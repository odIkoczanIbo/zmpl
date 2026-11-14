<?php
/**
 * zmogilna — wgrywanie i usuwanie własnego awatara (panel.php).
 *
 *   POST avatar-api.php?action=upload   multipart: avatar, csrf
 *   POST avatar-api.php?action=remove   csrf
 *
 * Wymagane: zalogowanie (dowolna rola) + token CSRF — tak samo jak przy
 * zgłaszaniu zdjęć (submit-photo-api.php).
 *
 * Plik nigdy nie trafia na dysk w oryginale. GD wczytuje go, przycina do
 * kwadratu i zapisuje 512×512 JPEG. Dzięki temu:
 *   - w katalogu nie ma pliku, którego treścią mógłby być skrypt,
 *   - znikają metadane (EXIF z telefonu często zawiera współrzędne GPS),
 *   - rozmiar jest stały, więc przeglądarka cache'uje awatar na rok.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/photos-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Bok zapisywanego awatara w pikselach — zapas na wyświetlanie w powiększeniu. */
const ZMAVATAR_SIZE = 512;
/**
 * Limit pliku przychodzącego.
 *
 * Świadomie duży: serwer i tak zapisuje tylko przeskalowany kwadrat 512×512
 * JPEG (~100 kB), więc na dysku nigdy nie leży oryginał z telefonu. Limit
 * istnieje tylko po to, żeby jeden request nie wyciągnąt serwera — ustawiamy
 * go na limit PHP (post_max_size), żeby komunikat był zgodny z tym, co
 * faktycznie przepuści serwer.
 */
const ZMAVATAR_MAX_BYTES = 40 * 1024 * 1024;

/**
 * Formaty obsługiwane bez konwersji. HEIC/HEIF oraz WebP/AVIF, jeśli przyjdą
 * jako MIME, którego GD nie zna, idą przez zm_convert_to_jpeg() (ffmpeg).
 */
const ZMAVATAR_ALLOWED = [
    'image/jpeg' => 'jpeg',
    'image/pjpeg' => 'jpeg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function zmavat_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** ini_get('upload_max_filesize') zwraca "40M" — zamieniamy na liczbę bajtów. */
function zmavat_ini_bytes(string $key, int $fallback): int
{
    $raw = trim((string) ini_get($key));
    if ($raw === '') {
        return $fallback;
    }
    $unit = strtolower(substr($raw, -1));
    $num = (float) $raw;
    $mult = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$unit] ?? 1;
    return (int) ($num * $mult);
}

/**
 * GD potrafi wczytać obcięty plik bez słowa protestu i oddać pół-gara obraz,
 * więc sprawdzamy strukturalnie, czy plik jest domknięty:
 *  - JPEG musi zawierać marker EOI (FFD9) — nie musi być na samym końcu,
 *    bo telefony i skanery dopisują po nim blok EXIF/TIFF (tzw. JPEGTIFF),
 *  - PNG musi zawierać chunk IEND,
 *  - WebP musi mieć zgodny z rozmiarem nagłówek RIFF.
 * Uwaga: FF00 w danych skompresowanych jest escapowane, więc FFD9 nie może
 * pojawić się przypadkiem w środku obrazu — znalezienie go to wiarygodny sygnał.
 */
function zmavat_is_complete(string $path, string $mime): bool
{
    $size = @filesize($path);
    if ($size === false || $size < 64) {
        return false;
    }

    if ($mime === 'image/webp') {
        $head = @file_get_contents($path, false, null, 0, 12);
        if (!is_string($head) || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
            return false;
        }
        $declared = unpack('V', substr($head, 4, 4))[1] ?? 0;
        // plik musi być co najmniej tak długi, jak deklaruje RIFF (dopuszczamy zapis po kontenerze)
        return $declared > 0 && $size >= $declared + 8;
    }

    $needle = $mime === 'image/png' ? 'IEND' : "\xFF\xD9";
    // wystarczy wąski pas z końca; szukamy do 4 MB w tył, żeby wyłapać
    // dopisane po EOI bloki EXIF, ale nie czytać całego wielkiego pliku
    $maxLookback = min($size, 4194304);
    $chunk = 65536;
    for ($off = 0; $off < $maxLookback; $off += $chunk) {
        $len = (int) min($chunk, $maxLookback - $off);
        $part = @file_get_contents($path, false, null, $size - $len - $off, $len);
        if (!is_string($part) || $part === '') {
            return false;
        }
        if (strpos($part, $needle) !== false) {
            return true;
        }
    }
    return false;
}

$user = zm_current_user();
if ($user === null) {
    zmavat_fail(401, 'Musisz być zalogowany.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zmavat_fail(405, 'Metoda niedozwolona.');
}

// token CSRF i akcja przychodzą w multipart/form-data albo w JSON — czytamy oba
$rawBody = (string) file_get_contents('php://input');
$jsonBody = $rawBody !== '' ? json_decode($rawBody, true) : null;
$jsonBody = is_array($jsonBody) ? $jsonBody : [];

$csrf = (string) ($_POST['csrf'] ?? ($jsonBody['csrf'] ?? ''));
if (!zm_csrf_check($csrf)) {
    zmavat_fail(403, 'Sesja wygasła — odśwież stronę i spróbuj ponownie.');
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? ($jsonBody['action'] ?? ''));

if (!is_dir(AVATARS_DIR) && !@mkdir(AVATARS_DIR, 0775, true)) {
    zmavat_fail(500, 'Nie udało się przygotować katalogu na awatary.');
}

/* ------------------------------------------------------------------ remove */
if ($action === 'remove') {
    $old = zmavat_current_name($pdo, (int) $user['id']);
    if ($old !== null) {
        @unlink(AVATARS_DIR . '/' . $old);
    }
    $pdo->prepare('UPDATE users SET avatar_path = NULL, avatar_updated_at = NULL WHERE id = :id')
        ->execute(['id' => $user['id']]);

    echo json_encode(['ok' => true, 'avatar' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ upload */
if ($action !== 'upload') {
    zmavat_fail(400, 'Nieznana akcja.');
}

if (!function_exists('imagecreatetruecolor')) {
    zmavat_fail(500, 'Serwer nie ma rozszerzenia GD — awatary są chwilowo wyłączone.');
}

if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    // PHP odrzuca multipart powyżej post_max_size zanim zobaczymy $_FILES,
    // więc sprawdzamy to po zawartości, inaczej użytkownik dostałby kłamliwe
    // „nie wybrano pliku" przy naprawdę za dużym zdjęciu
    $sentBytes = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = zmavat_ini_bytes('post_max_size', 0);
    if ($sentBytes > 0 && $postMax > 0 && $sentBytes > $postMax) {
        zmavat_fail(413, 'Plik jest za duży dla serwera — maksimum to '
            . (int) ($postMax / 1024 / 1024) . ' MB.');
    }
    zmavat_fail(400, 'Nie wybrano pliku.');
}

$file = $_FILES['avatar'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    $limit = zmavat_ini_bytes('upload_max_filesize', 40 * 1048576);
    zmavat_fail(413, 'Plik jest za duży dla serwera — maksimum to '
        . (int) ($limit / 1024 / 1024) . ' MB.');
}
if ($error !== UPLOAD_ERR_OK) {
    zmavat_fail(400, 'Nie udało się wczytać pliku.');
}

$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    zmavat_fail(400, 'Nie udało się wczytać pliku.');
}

$size = (int) ($file['size'] ?? 0);
if ($size <= 0) {
    // pusty plik to nie problem rozmiaru — np. wybrano skrót, który nie istnieje
    zmavat_fail(400, 'Wybrany plik jest pusty — wybierz zdjęcie jeszcze raz.');
}
if ($size > ZMAVATAR_MAX_BYTES) {
    zmavat_fail(413, 'Plik jest za duży — maksimum to '
        . (int) (ZMAVATAR_MAX_BYTES / 1024 / 1024) . ' MB.');
}

// prawdziwy typ pliku, nie rozszerzenie z nazwy
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmp);
$ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

// HEIC/HEIF z iPhone'a: GD ich nie czyta, więc najpierw zamieniamy na JPEG
if (!isset(ZMAVATAR_ALLOWED[$mime]) && ($mime === 'image/heic' || $mime === 'image/heif' || in_array($ext, ['heic', 'heif'], true))) {
    if (!zm_can_convert()) {
        zmavat_fail(415, 'Zdjęcie HEIC z iPhone’a wymaga konwertera na serwerze, którego tu nie ma. '
            . 'Zapisz zdjęcie jako JPG (iPhone: Ustawienia → Aparat → Formaty → Najbardziej zgodne).');
    }
    $converted = $tmp . '.avatar.jpg';
    if (!zm_convert_to_jpeg($tmp, $converted)) {
        @unlink($converted);
        zmavat_fail(415, 'Nie udało się przekonwertować zdjęcia HEIC.');
    }
    $tmp = $converted;
    $mime = 'image/jpeg';
    $size = (int) filesize($tmp);
    // plik pośredni kasujemy przy każdym wyjściu, także przy zmavat_fail()
    register_shutdown_function(static function () use ($converted): void {
        if (is_file($converted)) {
            @unlink($converted);
        }
    });
}

if (!isset(ZMAVATAR_ALLOWED[$mime])) {
    zmavat_fail(415, 'Obsługiwane są pliki JPG, PNG, WebP i HEIC.');
}

// i weryfikacja, że to faktycznie obraz o sensownych wymiarach
$info = @getimagesize($tmp);
if (!is_array($info) || empty($info[0]) || empty($info[1])) {
    zmavat_fail(415, 'To nie jest poprawny plik graficzny.');
}
if ((int) $info[0] < 32 || (int) $info[1] < 32) {
    zmavat_fail(422, 'Zdjęcie jest za małe — minimum to 32×32 piksele.');
}
if (!zmavat_is_complete($tmp, $mime)) {
    zmavat_fail(415, 'Plik zdjęcia jest uszkodzony lub nie w całości wczytany — '
        . 'prawdopodobnie przerwane pobieranie. Spróbuj wybrać zdjęcie jeszcze raz.');
}

$source = @imagecreatefromstring((string) file_get_contents($tmp));
if ($source === false) {
    zmavat_fail(415, 'Nie udało się odczytać obrazu.');
}

$srcW = imagesx($source);
$srcH = imagesy($source);
$side = min($srcW, $srcH);
$srcX = (int) (($srcW - $side) / 2);
$srcY = (int) (($srcH - $side) / 2);

$out = imagecreatetruecolor(ZMAVATAR_SIZE, ZMAVATAR_SIZE);
imagealphablending($out, false);
imagesavealpha($out, true);
imagefilledrectangle($out, 0, 0, ZMAVATAR_SIZE, ZMAVATAR_SIZE, imagecolorallocatealpha($out, 0, 0, 0, 127));
imagecopyresampled($out, $source, 0, 0, $srcX, $srcY, ZMAVATAR_SIZE, ZMAVATAR_SIZE, $side, $side);
imagedestroy($source);

// zapisujemy do pliku tymczasowego i podmieniamy, żeby nie zostawić urwanej halfy
$name = 'u' . (int) $user['id'] . '_' . bin2hex(random_bytes(8)) . '.jpg';
$final = AVATARS_DIR . '/' . $name;
$tmpOut = $final . '.' . getmypid() . '.part';

$ok = imagejpeg($out, $tmpOut, 86);
imagedestroy($out);
if (!$ok || !is_file($tmpOut)) {
    @unlink($tmpOut);
    zmavat_fail(500, 'Nie udało się zapisać awatara.');
}
@chmod($tmpOut, 0644);
if (!@rename($tmpOut, $final)) {
    @unlink($tmpOut);
    zmavat_fail(500, 'Nie udało się zapisać awatara.');
}

$previous = zmavat_current_name($pdo, (int) $user['id']);
$now = time();
$pdo->prepare('UPDATE users SET avatar_path = :p, avatar_updated_at = :t WHERE id = :id')->execute([
    'p' => $name,
    't' => $now,
    'id' => $user['id'],
]);

// stary plik kasujemy dopiero po udanym zapisie nowego
if ($previous !== null && $previous !== $name) {
    @unlink(AVATARS_DIR . '/' . $previous);
}

echo json_encode([
    'ok' => true,
    'avatar' => [
        // ten sam helper, którego używają szablony — jeden format URL w całym projekcie
        'url'     => zm_avatar_url(['id' => (int) $user['id'], 'avatar_path' => $name, 'avatar_updated_at' => $now], 256),
        'version' => $now,
    ],
], JSON_UNESCAPED_UNICODE);
exit;

/* --------------------------------------------------------------------- */

/** Nazwa pliku awatara z bazy (bez ścieżki) albo null — do kasowania starej wersji. */
function zmavat_current_name(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $name = $stmt->fetchColumn();
    if (!is_string($name) || $name === '') {
        return null;
    }
    $name = basename(str_replace('\\', '/', $name));
    return preg_match('~^u\d+_[0-9a-f]{16}\.jpg$~', $name) === 1 ? $name : null;
}

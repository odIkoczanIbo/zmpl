<?php
/**
 * zmogilna — serwowanie awatara użytkownika.
 *
 *   avatar.php?u=12&s=256
 *
 * Plik trzymamy jako kwadratowy JPEG 512×512 (avatar-api.php). Tutaj tylko
 * przeskalowujemy go do żądanego rozmiaru i odsyłamy — dzięki temu ten sam
 * plik obsługuje kółeczko 22 px w galerii i 76 px w panelu.
 *
 * Prywatność taka jak w profil.php: awatar widać, gdy profil jest publiczny
 * albo gdy ogląda właściciel / administrator. W pozostałych przypadkach 404,
 * żadnego „zapytania o istnienie pliku”.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$userId = (int) ($_GET['u'] ?? 0);
if ($userId <= 0) {
    avatar_fail(400);
}

$size = (int) ($_GET['s'] ?? 160);
$size = in_array($size, [48, 96, 160, 256, 512], true) ? $size : 160;

$stmt = $pdo->prepare(
    'SELECT id, avatar_path, avatar_updated_at, profile_public, role FROM users WHERE id = :id LIMIT 1'
);
$stmt->execute(['id' => $userId]);
$owner = $stmt->fetch();

if (!$owner || ($owner['avatar_path'] ?? null) === null || $owner['avatar_path'] === '') {
    avatar_fail(404);
}

// awatar = element publicznego profilu, więc podlega tej samej zasadzie co profil
$isPublic = (int) ($owner['profile_public'] ?? 0) === 1;
if (!$isPublic) {
    $viewer = zm_current_user();
    $isOwner = $viewer !== null && (int) $viewer['id'] === (int) $owner['id'];
    $isAdmin = $viewer !== null && $viewer['role'] === 'admin';
    if (!$isOwner && !$isAdmin) {
        avatar_fail(404);
    }
}

$path = avatar_abs_path((string) $owner['avatar_path']);
if ($path === null) {
    avatar_fail(404);
}

$version = (int) ($owner['avatar_updated_at'] ?? 0);
$etag = '"a' . $userId . '-' . $version . '-' . $size . '"';

// awatar jest niezmienny, dopóki nie wgra nowego — URL zawiera ?v=, więc cache na rok
header('Content-Type: image/jpeg');
header('Cache-Control: ' . ($isPublic ? 'public, max-age=31536000, immutable' : 'private, no-store'));
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$image = avatar_square($path, $size);
if ($image === null) {
    avatar_fail(404);
}

header('Content-Length: ' . (string) strlen($image));
echo $image;
exit;

/* --------------------------------------------------------------------- */

/** 404 bez informacji o powodzie — tak samo jak w reszcie API. */
function avatar_fail(int $code): void
{
    http_response_code($code);
    header('Content-Type: image/gif');
    // 1×1 przezroczysty GIF — nie psuje układu, gdy obrazka brak
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

/** Ścieżka bezwzględna do pliku awatara; null gdy wychodzi poza katalog. */
function avatar_abs_path(string $name): ?string
{
    $name = basename(str_replace('\\', '/', $name));
    if ($name === '' || !preg_match('~^u\d+_[0-9a-f]{16}\.jpg$~', $name)) {
        return null;
    }
    $abs = AVATARS_DIR . '/' . $name;
    return is_file($abs) ? $abs : null;
}

/** Kwadratowy JPEG o zadanym boku (przycięcie od środka), albo null. */
function avatar_square(string $abs, int $size): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $info = @getimagesize($abs);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return null;
    }

    $src = @imagecreatefromstring((string) file_get_contents($abs));
    if ($src === false) {
        return null;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $side = min($srcW, $srcH);
    $srcX = (int) (($srcW - $side) / 2);
    $srcY = (int) (($srcH - $side) / 2);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagecopyresampled($out, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
    imagedestroy($src);

    ob_start();
    imagejpeg($out, null, 86);
    imagedestroy($out);
    return (string) ob_get_clean();
}

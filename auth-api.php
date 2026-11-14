<?php
/**
 * zmogilna — wspólne API logowania dla WSZYSTKICH (nie tylko admina).
 *
 * Jeden popup na stronie głównej korzysta z tego pliku: rejestracja,
 * logowanie, wylogowanie, "kto jest zalogowany" i zmiana hasła.
 * Rola konta (user/admin) decyduje tylko o tym, dokąd front przekieruje
 * po zalogowaniu — sam formularz nigdy nie mówi, że to "logowanie admina".
 *
 * Sesja jest wspólna z auth.php, więc administrator zalogowany tutaj
 * jest też zalogowany w admin.php (i odwrotnie).
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
// social-lib.php musi być dołączony PRZED wywołaniem zm_register() — rejestracja
// wysyła alert dla administracji o nowym koncie (warunek function_exists
// w auth.php pozwala mu działać także tam, gdzie biblioteki nie ma)
require_once __DIR__ . '/social-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// sesja, ranga, punkty i lista kont — zawsze aktualne
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function zma_fail(int $code, string $error, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['error' => $error], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function zma_read_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return $_POST;
}

function zma_require_csrf(array $data): void
{
    if (!zm_csrf_check((string) ($data['csrf'] ?? ''))) {
        zma_fail(403, 'bad_csrf');
    }
}

// ---------------------------------------------------------------- profil: walidacja
const ZMA_SOCIAL_HOSTS = [
    'facebook'  => ['facebook.com', 'fb.com', 'fb.me'],
    'instagram' => ['instagram.com'],
    'youtube'   => ['youtube.com', 'youtu.be'],
    'tiktok'    => ['tiktok.com'],
];
const ZMA_SOCIAL_BASE = [
    'facebook'  => 'https://www.facebook.com/',
    'instagram' => 'https://www.instagram.com/',
    'youtube'   => 'https://www.youtube.com/',
    'tiktok'    => 'https://www.tiktok.com/',
];
const ZMA_SOCIAL_LABEL = [
    'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
    'tiktok' => 'TikTok', 'website' => 'Strona www',
];

/** Host bez „www.” / „m.”, małymi literami. */
function zma_clean_host(string $host): string
{
    $host = strtolower(trim($host, ". \t"));
    return (string) preg_replace('~^(www|m|mobile)\.~', '', $host);
}

/**
 * Normalizuje link społecznościowy. Przyjmuje sam nick (np. "@jan" albo "jan.kowalski")
 * albo pełny adres. Zwraca [wartość|null, komunikat błędu|null]. Pusty tekst = usunięcie linku.
 * Zapisujemy wyłącznie https i tylko domenę danej platformy — nic, co można wykorzystać
 * jako "javascript:" albo link do przypadkowej strony podszywającej się pod profil.
 */
function zma_norm_link(string $platform, string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [null, null];
    }
    $label = ZMA_SOCIAL_LABEL[$platform];
    if (mb_strlen($raw) > 255 || preg_match('~[\x00-\x20<>"\'`\\\\]~u', $raw) === 1) {
        return [null, "$label: niepoprawny link."];
    }

    if ($platform === 'website') {
        $url = preg_match('~^[a-z][a-z0-9+.\-]*://~i', $raw) ? $raw : 'https://' . $raw;
        $u = parse_url($url);
        if ($u === false || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['port'])) {
            return [null, "$label: niepoprawny adres."];
        }
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [null, "$label: dozwolone są tylko adresy http(s)."];
        }
        $host = strtolower(trim((string) $u['host'], '.'));
        if (function_exists('idn_to_ascii') && preg_match('~[^\x00-\x7f]~', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }
        if (!preg_match('~^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$~', $host)) {
            return [null, "$label: niepoprawna domena."];
        }
        $out = $scheme . '://' . $host . ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : '');
        return mb_strlen($out) > 255 ? [null, "$label: adres jest za długi."] : [$out, null];
    }

    $allowed = ZMA_SOCIAL_HOSTS[$platform];

    // sam nick
    if (preg_match('~^@?([A-Za-z0-9._\-]{1,60})$~', $raw, $m)) {
        $handle = $m[1];
        // ktoś wpisał samą domenę (np. "instagram.com") — to nie jest nick
        foreach ($allowed as $d) {
            if (strcasecmp($handle, $d) === 0) {
                return [null, "$label: podaj swój nick albo pełny link do profilu."];
            }
        }
        $prefix = ($platform === 'youtube' || $platform === 'tiktok') ? '@' : '';
        return [ZMA_SOCIAL_BASE[$platform] . $prefix . $handle, null];
    }

    // pełny adres
    $url = preg_match('~^[a-z][a-z0-9+.\-]*://~i', $raw) ? $raw : 'https://' . $raw;
    $u = parse_url($url);
    if ($u === false || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['port'])) {
        return [null, "$label: niepoprawny link."];
    }
    $scheme = strtolower((string) ($u['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return [null, "$label: dozwolone są tylko linki http(s)."];
    }
    $host = zma_clean_host((string) $u['host']);
    $hostOk = false;
    foreach ($allowed as $d) {
        if ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d) {
            $hostOk = true;
            break;
        }
    }
    if (!$hostOk) {
        return [null, "$label: link musi prowadzić do " . implode(' / ', $allowed) . '.'];
    }
    $path = (string) ($u['path'] ?? '');
    if ($path === '' || $path === '/') {
        return [null, "$label: podaj link do swojego profilu, nie do strony głównej."];
    }
    if (!preg_match('#^/[A-Za-z0-9._~%@/+\-]*$#', $path)) {
        return [null, "$label: niepoprawny link."];
    }
    $out = 'https://' . (in_array($host, ['youtu.be', 'fb.me', 'fb.com'], true) ? $host : 'www.' . $host) . $path;
    // stary format profilu Facebooka: profile.php?id=123
    if ($platform === 'facebook' && $path === '/profile.php' && !empty($u['query'])) {
        parse_str((string) $u['query'], $q);
        if (isset($q['id']) && ctype_digit((string) $q['id'])) {
            $out .= '?id=' . $q['id'];
        }
    }
    return mb_strlen($out) > 255 ? [null, "$label: link jest za długi."] : [$out, null];
}

/** Czyści krótki tekst z formularza: bez znaków sterujących, znaczników i podwójnych spacji. */
function zma_clean_text(string $s, int $max): string
{
    $s = strip_tags($s);
    $s = (string) preg_replace('~[\x00-\x1f\x7f]+~u', ' ', $s);
    $s = trim((string) preg_replace('~\s{2,}~u', ' ', $s));
    return mb_substr($s, 0, $max);
}

/**
 * Maskowany adres do potwierdzenia na ekranie: 'a***@gmail.com'.
 * Nie jest to zabezpieczenie (odpowiedź i tak jest generyczna), tylko
 * wygoda — użytkownik ma widzieć, gdzie wylądowało zgłoszenie.
 */
function zma_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    $local = substr($email, 0, $at);
    $head = mb_substr($local, 0, 1);
    return $head . '***' . substr($email, $at);
}

function zma_profile_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT username, display_name, email, role, created_at, bio, location,
                link_facebook, link_instagram, link_youtube, link_tiktok, link_website, profile_public,
                show_comments, show_online
         FROM users WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
}

function zma_profile_payload(array $r): array
{
    return [
        'username'     => (string) $r['username'],
        'display_name' => ($r['display_name'] ?? '') !== '' ? (string) $r['display_name'] : (string) $r['username'],
        // własny e-mail widzi tylko zalogowany właściciel (tu jesteśmy jego sesją)
        'email'        => (string) ($r['email'] ?? ''),
        'bio'          => (string) ($r['bio'] ?? ''),
        'location'     => (string) ($r['location'] ?? ''),
        'facebook'     => (string) ($r['link_facebook'] ?? ''),
        'instagram'    => (string) ($r['link_instagram'] ?? ''),
        'youtube'      => (string) ($r['link_youtube'] ?? ''),
        'tiktok'       => (string) ($r['link_tiktok'] ?? ''),
        'website'      => (string) ($r['link_website'] ?? ''),
        'public'       => (int) ($r['profile_public'] ?? 0) === 1,
        // brak kolumny (stare instalacje przed migracją) = domyślnie pokaż
        'show_comments'=> (int) ($r['show_comments'] ?? 1) === 1,
        // wyłącznik „Jestem aktywny" — pokazuje/pokrywa zieloną kropkę obecności
        'show_online'  => (int) ($r['show_online'] ?? 1) === 1,
    ];
}

$action = $_GET['action'] ?? '';
$data = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $data = zma_read_body();
    if ($action === '' && isset($data['action'])) {
        $action = (string) $data['action'];
    }
}

// ---------------------------------------------------------------- whoami
if ($action === 'whoami') {
    $user = zm_current_user();
    $payload = [
        'user' => $user['username'] ?? null,
        'role' => $user['role'] ?? null,
        'exp' => $user['exp'] ?? null,
        'csrf' => zm_csrf_token(),
    ];
    if ($user !== null) {
        $stmt = $pdo->prepare('SELECT display_name, email, created_at, avatar_path, avatar_updated_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        $row = $stmt->fetch();
        if ($row) {
            $payload['display_name'] = $row['display_name'] !== null && $row['display_name'] !== ''
                ? $row['display_name']
                : $user['username'];
            $payload['email'] = $row['email'];
            $payload['created_at'] = $row['created_at'];
            // własny awatar widzisz zawsze, także przy prywatnym profilu
            $payload['avatar'] = zm_avatar_url((array) $row + ['id' => (int) $user['id']], 160);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------- rank */
/*
 * Punkty, ranga, pasmo do następnej rangi i liczniki aktywności.
 *
 * Karta „Twoja ranga” w panelu była renderowana wyłącznie po stronie serwera,
 * więc po moderacji zdjęcia, polubieniu albo ręcznej korekcie punktów przez
 * admina strona pokazywała stare liczby aż do przeładowania. Panel odpytuje to
 * po powrocie na kartę i cyklicznie, a tabelę rang dostaje gotową, bo inaczej
 * JS musiałby powielać wyliczenia rangi i rozjechałby się z serwerem.
 * Prywatny widok: wymaga zalogowanego konta, bez CSRF (sam odczyt).
 */
if ($action === 'rank') {
    $user = zm_current_user();
    if ($user === null) {
        zma_fail(401, 'not_authenticated');
    }
    $view = zm_rank_view($pdo, (int) $user['id']);
    echo json_encode([
        'ok' => true,
        'rank' => $view,
        'tableHtml' => zm_rank_table_html(
            $pdo,
            isset($view['rank']['id']) ? (int) $view['rank']['id'] : null,
            isset($view['specialRank']['id']) ? (int) $view['specialRank']['id'] : null,
            (bool) $view['rankManual']
        ),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- profile_get
if ($action === 'profile_get') {
    $user = zm_current_user();
    if ($user === null) {
        zma_fail(401, 'not_authenticated');
    }
    $row = zma_profile_row($pdo, $user['id']);
    if (!$row) {
        zma_fail(404, 'not_found');
    }
    echo json_encode(['ok' => true, 'profile' => zma_profile_payload($row)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------- password_reset_request
// Nie wymaga sesji ani CSRF: token w akcji 'confirm' jest sam w sobie
// dowodem uprawnienia, a samo zgłoszenie niczego użytkownikowi nie zmienia.
// CSRF chroniłby tylko atakującego, który i tak nie ma nic do ochrony.
if ($action === 'password_reset_request') {
    $identifier = zma_clean_text((string) ($data['identifier'] ?? ''), 190);

    $result = zm_request_password_reset($pdo, $identifier);

    if (!$result['ok']) {
        if ($result['error'] === 'missing_identifier') {
            zma_fail(400, 'missing_identifier', ['message' => 'Podaj login albo adres e-mail.']);
        }
        if ($result['error'] === 'throttled') {
            $min = max(1, (int) ceil($result['retry_in'] / 60));
            zma_fail(429, 'throttled', [
                'retry_after' => (int) $result['retry_in'],
                'message'     => 'Wysłaliśmy już niedawno link na ten adres. Spróbuj ponownie za ' . $min . ' min.',
            ]);
        }
        if ($result['error'] === 'too_many') {
            zma_fail(429, 'too_many', ['message' => 'Zbyt wiele żądań. Spróbuj za kilka minut.']);
        }
        // Nie zdradzamy, czy konto o podanym loginie lub adresie istnieje
        zma_fail(404, 'unknown_account', [
            'message' => 'Nie znaleziono konta o podanym adresie e-mail.',
        ]);
    }

    echo json_encode([
        'ok'      => true,
        'masked'  => zma_mask_email((string) $result['email']),
        'message' => 'Wysłaliśmy link do ustawienia hasła na podany adres.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------- password_reset_confirm
if ($action === 'password_reset_confirm') {
    $result = zm_reset_password(
        $pdo,
        (string) ($data['token'] ?? ''),
        (string) ($data['password'] ?? '')
    );

    if (!$result['ok']) {
        $map = [
            'bad_token'     => ['bad_token', 'Link jest nieprawidłowy albo został już wykorzystany.'],
            'token_expired' => ['bad_token', 'Link wygasł. Poproś o nowy.'],
            'weak_password' => ['weak_password', 'Hasło musi mieć co najmniej 8 znaków.'],
        ];
        [$code, $message] = $map[$result['error']] ?? ['bad_token', 'Link jest nieprawidłowy.'];
        zma_fail(422, (string) $code, ['message' => $message]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zma_fail(405, 'method_not_allowed');
}

// ---------------------------------------------------------------- register
if ($action === 'register') {
    // pułapka na boty: pole ukryte w CSS, prawdziwy człowiek nigdy go nie wypełni
    if (trim((string) ($data['website'] ?? '')) !== '') {
        echo json_encode(['ok' => true]); // wygląda na sukces, nic nie zapisujemy
        exit;
    }

    $result = zm_register(
        $pdo,
        (string) ($data['username'] ?? ''),
        (string) ($data['email'] ?? ''),
        (string) ($data['password'] ?? ''),
        (string) ($data['display_name'] ?? '')
    );

    if (!$result['ok']) {
        $map = [
            'bad_username' => 'Login musi mieć od 3 do 40 znaków.',
            'bad_username_chars' => 'Login może zawierać tylko litery, cyfry, kropkę, myślnik i podkreślenie.',
            'reserved_username' => 'Tego loginu nie można użyć — jest zarezerwowany przez adres strony.',
            'blocked_username' => 'Tego loginu nie można użyć.',
            'email_required' => 'Podaj adres e-mail — jest potrzebny, żeby odzyskać hasło.',
            'bad_email' => 'Ten adres e-mail nie wygląda poprawnie.',
            'weak_password' => 'Hasło musi mieć co najmniej 8 znaków.',
            'taken_username' => 'Ten login jest już zajęty.',
            'taken_email' => 'Na ten adres e-mail istnieje już konto.',
        ];
        zma_fail(422, (string) $result['error'], [
            'field'  => ($result['error'] === 'bad_email' || $result['error'] === 'email_required') ? 'email'
                : (($result['error'] === 'bad_username' || $result['error'] === 'bad_username_chars'
                    || $result['error'] === 'reserved_username' || $result['error'] === 'blocked_username'
                    || $result['error'] === 'taken_username') ? 'username' : null),
            'message' => $map[$result['error']] ?? 'Nie udało się założyć konta.',
        ]);
    }

    echo json_encode([
        'ok' => true,
        'user' => $result['user']['username'],
        'role' => $result['user']['role'],
        'exp' => $result['user']['exp'],
        'csrf' => zm_csrf_token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- login
if ($action === 'login') {
    $result = zm_login($pdo, (string) ($data['user'] ?? ''), (string) ($data['pass'] ?? ''));

    if (!$result['ok']) {
        if ($result['error'] === 'locked') {
            zma_fail(429, 'locked', ['retry_after' => (int) ($result['retry_after'] ?? 300)]);
        }
        if ($result['error'] === 'missing_credentials') {
            zma_fail(400, 'missing_credentials', ['message' => 'Podaj login i hasło.']);
        }
        if ($result['error'] === 'banned') {
            zma_fail(403, 'banned', ['message' => 'To konto zostało zablokowane przez administratora.']);
        }
        zma_fail(403, 'bad_credentials', ['message' => 'Nieprawidłowy login lub hasło.']);
    }

    echo json_encode([
        'ok' => true,
        'user' => $result['user']['username'],
        'role' => $result['user']['role'],
        'exp' => $result['user']['exp'],
        'csrf' => zm_csrf_token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- logout
if ($action === 'logout') {
    zm_logout();
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- profile_save
if ($action === 'profile_save') {
    zma_require_csrf($data);
    $user = zm_current_user();
    if ($user === null) {
        zma_fail(401, 'not_authenticated');
    }

    $displayName = zma_clean_text((string) ($data['display_name'] ?? ''), 60);
    if ($displayName === '') {
        $displayName = $user['username'];
    }
    if (mb_strlen($displayName) < 2) {
        zma_fail(422, 'bad_display_name', ['field' => 'display_name', 'message' => 'Nazwa wyświetlana musi mieć co najmniej 2 znaki.']);
    }

    $bio = zma_clean_text((string) ($data['bio'] ?? ''), 300);
    // opis jest publiczny (gdy profil jest publiczny) — bez linków, tak jak w komentarzach
    if ($bio !== '' && preg_match('~(https?://|www\.|\b[a-z0-9\-]+\.(?:com|pl|net|org|eu|info|io|me|xyz|ru|tk)\b)~iu', $bio)) {
        zma_fail(422, 'bio_links', ['field' => 'bio', 'message' => 'W opisie nie można wstawiać linków — dodaj je w polach poniżej.']);
    }

    $location = zma_clean_text((string) ($data['location'] ?? ''), 80);

    $links = [];
    foreach (['facebook', 'instagram', 'youtube', 'tiktok', 'website'] as $platform) {
        [$val, $err] = zma_norm_link($platform, (string) ($data[$platform] ?? ''));
        if ($err !== null) {
            zma_fail(422, 'bad_link', ['field' => $platform, 'message' => $err]);
        }
        $links[$platform] = $val;
    }

    $public = !empty($data['public']) && $data['public'] !== 'false' && $data['public'] !== '0' ? 1 : 0;

    // E-mail: brak pola = nie ruszaj (inne wywołania API nie mają o nim wiedzieć).
    // Puste pole = brak adresu, zapisujemy NULL, a nie '' — pusty string w
    // unikalnym indeksie zablokowałby drugiemu użytkownikowi wyczyszczenie.
    // $emailSet rozdziela "nie ruszaj" od "wyczyść na NULL" — samo $email nie może,
    // bo wartość do zapisania bywa właśnie null.
    $email = null;
    $emailSet = array_key_exists('email', $data);
    if ($emailSet) {
        $clean = zm_normalize_email(zma_clean_text((string) $data['email'], 190));
        if ($clean !== '' && !zm_email_is_valid($clean)) {
            zma_fail(422, 'bad_email', ['field' => 'email', 'message' => 'Ten adres e-mail nie wygląda poprawnie.']);
        }
        // ten sam adres, który już jest zapisany, to nie konflikt
        if ($clean !== '') {
            $taken = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :me LIMIT 1');
            $taken->execute(['e' => $clean, 'me' => $user['id']]);
            if ($taken->fetch()) {
                zma_fail(422, 'taken_email', ['field' => 'email', 'message' => 'Na ten adres e-mail istnieje już inne konto.']);
            }
        }
        $email = $clean !== '' ? $clean : null;
    }

    // przełącznik komentarzy na profilu — brak pola w payloadzie = nie ruszaj ustawienia
    $showComments = array_key_exists('show_comments', $data)
        ? (!empty($data['show_comments']) && $data['show_comments'] !== 'false' && $data['show_comments'] !== '0' ? 1 : 0)
        : null;

    // „Jestem aktywny" — ręczna kontrola widoczności kropki obecności. Tak samo
    // jak show_comments: brak pola w payloadzie = nie ruszaj ustawienia.
    $showOnline = array_key_exists('show_online', $data)
        ? (!empty($data['show_online']) && $data['show_online'] !== 'false' && $data['show_online'] !== '0' ? 1 : 0)
        : null;

    $params = [
        'dn' => $displayName,
        'bio' => $bio !== '' ? $bio : null,
        'loc' => $location !== '' ? $location : null,
        'fb' => $links['facebook'],
        'ig' => $links['instagram'],
        'yt' => $links['youtube'],
        'tt' => $links['tiktok'],
        'www' => $links['website'],
        'pub' => $public,
        'id' => $user['id'],
    ];
    // Uwaga: przy ATTR_EMULATE_PREPARES = false sterownik odrzuca parametr,
    // którego nie ma w SQL ("Invalid parameter number"). ':mail', ':sc' i ':so'
    // dokładamy tylko wtedy, gdy faktycznie są w zapytaniu.
    if ($emailSet) {
        $params['mail'] = $email;
    }
    if ($showComments !== null) {
        $params['sc'] = $showComments;
    }
    if ($showOnline !== null) {
        $params['so'] = $showOnline;
    }

    $pdo->prepare(
        'UPDATE users SET display_name = :dn'
        . ($emailSet ? ', email = :mail' : '') . '
                , bio = :bio, location = :loc,
                link_facebook = :fb, link_instagram = :ig, link_youtube = :yt,
                link_tiktok = :tt, link_website = :www, profile_public = :pub'
        . ($showComments !== null ? ', show_comments = :sc' : '')
        . ($showOnline !== null ? ', show_online = :so' : '') . '
         WHERE id = :id'
    )->execute($params);

    echo json_encode(
        ['ok' => true, 'profile' => zma_profile_payload(zma_profile_row($pdo, $user['id']))],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---------------------------------------------------------------- change_password
if ($action === 'change_password') {
    zma_require_csrf($data);
    $user = zm_current_user();
    if ($user === null) {
        zma_fail(401, 'not_authenticated');
    }

    $current = (string) ($data['current_password'] ?? '');
    $new = (string) ($data['new_password'] ?? '');

    $minLen = zm_password_min_length();
    if (strlen($new) < $minLen) {
        zma_fail(422, 'weak_password', ['message' => 'Nowe hasło musi mieć co najmniej ' . $minLen . ' znaków.']);
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, (string) $row['password_hash'])) {
        zma_fail(403, 'bad_credentials', ['message' => 'Obecne hasło jest nieprawidłowe.']);
    }

    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([
        'h' => password_hash($new, PASSWORD_DEFAULT),
        'id' => $user['id'],
    ]);

    zm_security_event('password_change', 'warn', (string) ($user['username'] ?? ''), 'hasło zmienione z panelu konta');

    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- zmiana e-maila
// Osobna akcja zamiast profile_save, bo jest jedynym miejscem, gdzie podmiana
// adresu do odzyskiwania hasla musi udowodnić, że znasz hasło. Bez tego każda
// przejęta sesja wystarczała do trwałego przejęcia konta: podmiana adresu,
// potem "zapomniałem hasła" i reset leci już na adres atakującego.
if ($action === 'email_change') {
    zma_require_csrf($data);
    $user = zm_current_user();
    if ($user === null) {
        zma_fail(401, 'not_authenticated');
    }

    // Ktoś z podrobioną sesją mógłby tu zgadywać hasło w nieskończoność,
    // bo to jedyny punkt, który je sprawdza. Licznik trzymamy w sesji,
    // żeby nie zamykać konta (i nie psuć resetu hasła) na stałe.
    $now = time();
    $attempts = (int) ($_SESSION['email_change_attempts'] ?? 0);
    $until = (int) ($_SESSION['email_change_blocked_until'] ?? 0);
    if ($until > $now) {
        zma_fail(429, 'too_many_attempts', [
            'message' => 'Zbyt wiele prób. Spróbuj ponownie za ' . ($until - $now) . ' s.',
            'retry_after' => $until - $now,
        ]);
    }
    if ($attempts >= 5) {
        $until = $now + 900;
        $_SESSION['email_change_attempts'] = 0;
        $_SESSION['email_change_blocked_until'] = $until;
        zma_fail(429, 'too_many_attempts', [
            'message' => 'Zbyt wiele prób. Spróbuj ponownie za 15 min.',
            'retry_after' => 900,
        ]);
    }

    $clean = zm_normalize_email(zma_clean_text((string) ($data['email'] ?? ''), 190));
    if ($clean === '' || !zm_email_is_valid($clean)) {
        zma_fail(422, 'bad_email', [
            'field' => 'email',
            'message' => $clean === ''
                ? 'Podaj adres e-mail.'
                : 'Ten adres e-mail nie wygląda poprawnie.',
        ]);
    }

    $stmt = $pdo->prepare('SELECT password_hash, email FROM users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        zma_fail(404, 'not_found');
    }

    if (!password_verify((string) ($data['current_password'] ?? ''), (string) $row['password_hash'])) {
        $_SESSION['email_change_attempts'] = $attempts + 1;
        zma_fail(403, 'bad_credentials', ['message' => 'Obecne hasło jest nieprawidłowe.']);
    }
    $_SESSION['email_change_attempts'] = 0;
    $_SESSION['email_change_blocked_until'] = 0;

    // Bez zmiany adresu nie ma po co ruszać bazy.
    if ((string) ($row['email'] ?? '') === $clean) {
        echo json_encode(['ok' => true, 'email' => $clean, 'unchanged' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Unikalność w indeksie jest zabezpieczeniem ostatniej instancji; sprawdzamy
    // ja z jasnym komunikatem, żeby nie zwracać użytkownikowi surowego błędu SQL.
    $taken = $pdo->prepare('SELECT username FROM users WHERE email = :e AND id <> :me LIMIT 1');
    $taken->execute(['e' => $clean, 'me' => $user['id']]);
    if ($other = $taken->fetch()) {
        zma_fail(422, 'taken_email', [
            'field' => 'email',
            'message' => 'Na ten adres e-mail istnieje już inne konto.',
        ]);
    }

    $pdo->prepare('UPDATE users SET email = :e WHERE id = :id')->execute([
        'e' => $clean,
        'id' => $user['id'],
    ]);

    // Każdy token resetu, który zdążył powstać przed zmianą adresu, musi zniknąć.
    // Inaczej link "resetuj hasło" wysłany na poprzednią skrzynkę działałby
    // jeszcze przez godzinę i omijałby właśnie dodane zabezpieczenie.
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $user['id']]);

    echo json_encode(['ok' => true, 'email' => $clean], JSON_UNESCAPED_UNICODE);
    exit;
}

zma_fail(400, 'unknown_action');

<?php
declare(strict_types=1);

// jedna strefa czasowa dla całej aplikacji (planowanie publikacji porównuje czasy w PHP)
date_default_timezone_set('Europe/Warsaw');

/*
 * Opcjonalne nadpisania środowiska. Serwer produkcyjny trzyma w config.local.php
 * dane do bazy, adres domeny i transport poczty; lokalnie plik nie istnieje
 * i obowiązują wartości z tej pliki. config.local.php jest niedostępny przez
 * HTTP (blokada w .htaccess) — tak jak config.php.
 */
$zmLocalConfig = __DIR__ . '/config.local.php';
if (is_file($zmLocalConfig)) {
    require $zmLocalConfig;
}
unset($zmLocalConfig);

/*
 * Instalator: gdy jeszcze nie ma config.local.php (pierwsze wejście na czystą
 * instalację), zamiast łączyć się z niedziałającymi domyślnymi danymi do bazy,
 * przekierowujemy na install.php. Tam formularz zbiera dane do bazy, testuje
 * połączenie i zapisuje ten plik; schemat tworzy się sam po pierwszym
 * wejściu na stronę. Lokalnie (XAMPP) plik zwykle nie istnieje, ale działa
 * baza o danych domyślnych — dlatego przekierowanie nie działa w CLI i tylko
 * gdy plik instalacyjny faktycznie istnieje.
 */
if (PHP_SAPI !== 'cli'
    && !defined('DB_HOST')
    && is_file(__DIR__ . '/install.php')
    && !str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/install.php')
) {
    header('Location: /install.php', true, 302);
    exit;
}

/**
 * Wspólne nagłówki bezpieczeństwa dla całej aplikacji.
 *
 * Wywoływane na starcie `config.php`, więc obejmuje każdą stronę i każde API —
 * nie trzeba pamiętać o nich osobno w kilkunastu plikach wejściowych.
 *
 * CSP świadomie dopuszcza `'unsafe-inline'` w skryptach i stylach: aplikacja ma
 * dużo wkompilowanego JS/CSS, a budowanie nonce'ów dla każdego bloku byłoby
 * zmianą na skalę całego projektu. Reszta dyrektyw i tak realnie chroni:
 * odcina wstrzyknięte obce skrypty/ramki, `<object>`, clickjacking i podmianę
 * `base`, a `connect-src 'self'` blokuje eksfiltrację danych na obcy serwer.
 */
function zm_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()');

    // HSTS tylko po HTTPS (na HTTP przeglądarka go zignoruje). Parametry biorą
    // się z centrum bezpieczeństwa w panelu (security-lib.php → konfiguracja).
    if (function_exists('zm_security_is_https') && zm_security_is_https()) {
        $sec = zm_security_config();
        if (!empty($sec['hsts_enabled']) && (int) $sec['hsts_max_age'] > 0) {
            $hsts = 'max-age=' . (int) $sec['hsts_max_age'];
            if (!empty($sec['hsts_subdomains'])) {
                $hsts .= '; includeSubDomains';
            }
            if (!empty($sec['hsts_preload'])) {
                $hsts .= '; preload';
            }
            header('Strict-Transport-Security: ' . $hsts);
        }
    }

    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https:",
        "media-src 'self' blob:",
        "connect-src 'self'",
        // mapy w lightboksie to osadzone ramki OSM albo Google
        "frame-src 'self' https://www.openstreetmap.org https://www.google.com",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "manifest-src 'self'",
        "worker-src 'self'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

// ---------------------------------------------------------------- ustawienia
// Wartości poniżej obowiązują lokalnie; serwer nadpisuje je w config.local.php.
// 127.0.0.1 zamiast 'localhost': na Windows/XAMPP 'localhost' potrafi dodać ~1 s (próba IPv6)
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'zmogilna');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
const DB_CHARSET = 'utf8mb4';

// katalog ze zdjęciami (pliki) oraz z miniaturami
const PHOTOS_DIR = __DIR__ . '/uploads/photos';
const CACHE_DIR = __DIR__ . '/uploads/cache';

// Centrum bezpieczeństwa: konfiguracja (plik JSON w CACHE_DIR) jest potrzebna
// już na starcie — nagłówki bezpieczeństwa i ewentualne przekierowanie na HTTPS.
// Uruchamiamy je tutaj, po zdefiniowaniu CACHE_DIR, a przed jakimkolwiek wyjściem.
require_once __DIR__ . '/security-lib.php';
zm_security_harden_runtime();
zm_security_enforce_https();
zm_send_security_headers();

// Przerwa techniczna: stan (plik JSON w CACHE_DIR) i ekran 503 z oknem logowania
// dla administratora. Ładowane wcześnie; egzekwowane po połączeniu z bazą
// (zm_maintenance_enforce potrzebuje $pdo do sprawdzenia rangi sesji).
require_once __DIR__ . '/maintenance-lib.php';

// Konfiguracja systemu atrakcji turystycznych (etykiety, sekcja na stronie
// głównej, tryb wyboru zdjęć). Ładowana wcześnie — z niej korzysta formularz
// zgłoszenia (panel.php), API zgłoszeń (submit-photo-api.php), moderacja
// (admin-api.php) oraz budowany w kolejnym etapie blok na stronie głównej.
require_once __DIR__ . '/attraction-lib.php';

// Dziennik zdarzeń systemowych (404, błędy fatalne PHP, błędy zapisu zdjęć).
// Mały plik z definicjami — rejestruje też przechwytywanie błędów fatalnych,
// żeby „Stan techniczny" w panelu miał realne dane zamiast pustych miejsc.
require_once __DIR__ . '/system-log.php';
zm_system_register_fatal_handler();

/*
 * ---------------------------------------------------------------- awatary użytkowników
 */
const AVATARS_DIR = __DIR__ . '/uploads/avatars';

/**
 * Ścieżka do ffmpeg (potrzebne do konwersji HEIC z iPhone'a, gdy nie ma Imagicka).
 * Zostaw '' — wtedy szukany jest automatycznie w typowych miejscach i w PATH.
 * Możesz też wpisać ręcznie, np. 'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe'.
 */
const FFMPEG_BIN = __DIR__ . '/ffmpeg/bin/ffmpeg.exe';

/*
 * Limit rozmiaru zdjęć: celowo żaden po stronie aplikacji.
 * Realny limit wynika wyłącznie z php.ini (upload_max_filesize, post_max_size).
 * Jedno zgłoszenie przyjmuje jedno zdjęcie — patrz submit-photo-api.php.
 */
const ZM_MAX_PENDING_PER_USER = 200;                 // oczekujących na moderację naraz

/*
 * Klucz do api.adresy.app. OPCJONALNY — bez niego obowiązują limity anonimowe
 * (20 zapytań/min na IP), z nim wyższe. Serwis obsługuje dwie ścieżki:
 *
 *   - nazwa → gmina (/match, /lookup/teryt),
 *   - współrzędne → najbliższy punkt adresowy PRG (/reverse): ulica, numer,
 *     kod pocztowy oraz urzędowo przypisana gmina i miejscowość. To główne
 *     źródło „przybliżonego adresu”; Nominatim jest tylko zapasem dla miejsc
 *     bez adresów (las, jezioro) — patrz geo-lib.php.
 *
 * Klucz podnosi limity obu ścieżek.
 */
const ADRESY_APP_API_KEY = '';

// ---------------------------------------------------------------- e-mail
// Nadawca wszystkich wiadomości (reset hasła). Ustaw adres skrzynki,
// z której faktycznie wysyłasz — inaczej spam filtra poczty odrzuci list.
const MAIL_FROM = 'admin@zmogilna.pl';
const MAIL_FROM_NAME = 'zMogilna';

// Transport: 'file' zapisuje wiadomość do MAIL_DIR (domyślne, do testów na
// XAMPP-e, gdzie mail() bez sendmaila nic nie robi), 'mail' próbuje mail(),
// 'smtp' wysyła własnym klientem SMTP na SMTP_HOST.
if (!defined('MAIL_TRANSPORT')) {
    define('MAIL_TRANSPORT', 'file');
}
const MAIL_DIR = __DIR__ . '/uploads/mail';

// Dane SMTP (uzupełniasz, gdy zmienisz MAIL_TRANSPORT na 'smtp')
const SMTP_HOST = '';
const SMTP_PORT = 587;
const SMTP_SECURE = 'tls';      // 'tls' (STARTTLS), 'ssl' (implicit TLS, 465) albo ''
const SMTP_USER = '';
const SMTP_PASS = '';

/**
 * Adres bazowy w linkach wysyłanych mailem (np. link do resetu hasła).
 * Puste = wyliczamy z bieżącego żądania, co działa lokalnie, ale przy
 * wdrożeniu na domenę ustaw jawnie, np. 'https://zmogilna.pl'.
 */
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', '');
}

// ---------------------------------------------------------------- połączenie
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    if (PHP_SAPI !== 'cli' && strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'db_unavailable']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Brak połączenia z bazą danych `" . DB_NAME . "`.\n";
        echo "Sprawdź, czy MySQL w XAMPP jest uruchomiony i czy baza istnieje.";
    }
    exit;
}

// Przerwa techniczna: jeśli włączona, a żądanie nie pochodzi od zalogowanego
// administratora (ani nie jest logowaniem z panelu), kończymy je ekranem 503
// z krótkim komunikatem i oknem logowania. Administrator pracuje normalnie.
zm_maintenance_enforce($pdo);

/**
 * Tworzy brakujące tabele. Wywoływane raz — znacznik w uploads/cache/.schema-ok.
 * Dzięki temu nie ma DDL przy każdym żądaniu.
 */
function zm_ensure_schema(PDO $pdo): void
{
    // Znacznik istnieje => schemat już sprawdzony. Zero zapytań DDL/SHOW przy każdym żądaniu
    // (wcześniej każde zdjęcie w siatce robiło dodatkowe SHOW COLUMNS).
    //
    // UWAGA: poszerzenie `messages.body` NIE jest tutaj, tylko w
    // zm_ensure_message_body_schema() z własnym znacznikiem — patrz niżej.
    // Tu nic się nie zmienia od v7, więc znacznika nie podbijamy (podbicie
    // kazałoby całej funkcji przeliczać się na nowo przy każdym starym layoucie
    // i tak by nic nie dało, bo ALTER tu nie leży).
    $marker = CACHE_DIR . '/.schema-v7';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_categories_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            category_id INT UNSIGNED NULL,
            place VARCHAR(160) NULL,
            map_url VARCHAR(1000) NULL,
            taken_at DATE NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            published TINYINT(1) NOT NULL DEFAULT 1,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            filesize INT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_photos_filename (filename),
            KEY idx_photos_category (category_id),
            KEY idx_photos_published (published),
            CONSTRAINT fk_photos_category FOREIGN KEY (category_id)
                REFERENCES categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            photo_id VARCHAR(64) NOT NULL,
            photo_name VARCHAR(300) NULL,
            photo_thumb VARCHAR(1000) NULL,
            photo_created VARCHAR(60) NULL,
            author VARCHAR(40) NULL,
            text TEXT NOT NULL,
            delete_token CHAR(32) NOT NULL,
            created_at BIGINT NOT NULL,
            KEY idx_comments_photo_id (photo_id),
            KEY idx_comments_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(40) NOT NULL,
            comment_id INT UNSIGNED NULL,
            author VARCHAR(80) NULL,
            text TEXT NULL,
            photo_id VARCHAR(64) NULL,
            photo_name VARCHAR(300) NULL,
            admin_user VARCHAR(50) NULL,
            created_at BIGINT NOT NULL,
            KEY idx_admin_log_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // --- migracja: kolumny zdjęć ---
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM photos")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($cols['kind'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'image' AFTER filename");
    }
    if (!isset($cols['map_url'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN map_url VARCHAR(1000) NULL AFTER place");
    }

    if (!isset($cols['publish_at'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN publish_at DATETIME NULL AFTER published, ADD KEY idx_photos_publish_at (publish_at)");
    }

    if (!isset($cols['views'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!isset($cols['favorites'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN favorites INT UNSIGNED NOT NULL DEFAULT 0");
    }

    // --- migracja v7: informacja o modyfikacji zdjęcia (EXIF / ślady edycji) ---
    if (!isset($cols['edit_status'])) {
        // original | suspect | edited | unknown | na
        $pdo->exec("ALTER TABLE photos ADD COLUMN edit_status VARCHAR(12) NULL");
    }
    if (!isset($cols['edit_note'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN edit_note VARCHAR(255) NULL");
    }
    if (!isset($cols['edit_manual'])) {
        // 1 = status ustawiony ręcznie w panelu, analiza automatyczna go nie nadpisuje
        $pdo->exec("ALTER TABLE photos ADD COLUMN edit_manual TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($cols['exif_json'])) {
        $pdo->exec("ALTER TABLE photos ADD COLUMN exif_json TEXT NULL");
    }

    // --- migracja v7: podkategorie (kategoria może leżeć w innej kategorii) ---
    $catCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM categories")->fetchAll() as $c) {
        $catCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($catCols['parent_id'])) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT UNSIGNED NULL AFTER id, ADD KEY idx_categories_parent (parent_id)");
    }
    // nazwa nie musi być unikalna w całej bazie — ta sama podkategoria (np. „Rynek”) może leżeć w kilku gminach;
    // unikalność w obrębie jednego rodzica pilnuje admin-api.php
    if ($pdo->query("SHOW INDEX FROM categories WHERE Key_name = 'uq_categories_name'")->fetch()) {
        $pdo->exec("ALTER TABLE categories DROP INDEX uq_categories_name, ADD KEY idx_categories_name (name)");
    }

    // --- migracja v7: odpowiedzi na komentarze i polubienia komentarzy ---
    $comCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM comments")->fetchAll() as $c) {
        $comCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($comCols['parent_id'])) {
        $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INT UNSIGNED NULL, ADD KEY idx_comments_parent (parent_id)");
    }
    if (!isset($comCols['likes'])) {
        $pdo->exec("ALTER TABLE comments ADD COLUMN likes INT UNSIGNED NOT NULL DEFAULT 0");
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comment_likes (
            comment_id INT UNSIGNED NOT NULL,
            voter CHAR(32) NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (comment_id, voter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // --- migracja v7: notatnik administratora (lista zadań) ---
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            text TEXT NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            done_at BIGINT NULL,
            KEY idx_admin_tasks_done (done, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach ([PHOTOS_DIR, CACHE_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    @file_put_contents($marker, (string) time());
}

zm_ensure_schema($pdo);

/**
 * Tabela `users` + migracja kolumn potrzebnych do rejestracji zwykłych
 * użytkowników (obok istniejącego konta admina). Osobny znacznik, bo
 * instalacje sprzed tej wersji mają już znacznik `.schema-v7` i nie
 * przeszłyby przez zm_ensure_schema() ponownie.
 */
/**
 * Dodaje kolumnę do `users`, zachowując wskazaną pozycję TYLKO wtedy, gdy
 * kolumna odniesienia istnieje.
 *
 * Bez tego zabezpieczenia migracje w stylu `AFTER show_comments` wyważały
 * świeżą instalację: `show_comments` dodaje wcześniejsza migracja, więc przy
 * pustej bazie (gdy tworzymy users od zera) odwołanie do niej rzucalo wyjątek
 * i cała strona kończyła się 500 — mimo że sama kolumna nie była potrzebna do
 * stworzenia tej nowej. Kolejność kolumn to sprawa kosmetyczna; poprawność
 * schematu — nie.
 */
function zm_ensure_users_column(PDO $pdo, string $name, string $def, ?string $after = null): void
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (isset($cols[$name])) {
        return;
    }
    $suffix = '';
    if ($after !== null && isset($cols[strtolower($after)])) {
        $suffix = " AFTER `{$after}`";
    }
    $pdo->exec("ALTER TABLE users ADD COLUMN `{$name}` {$def}{$suffix}");
}

function zm_ensure_users_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-users-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            email VARCHAR(190) NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = $c;
    }

    if (!isset($cols['email'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username");
    }
    if (!isset($cols['display_name'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NULL AFTER password_hash");
    }
    if (!isset($cols['login_attempts'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN login_attempts INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!isset($cols['locked_until'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL");
    }
    if (!isset($cols['created_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    // Jeżeli `role` jest ENUM i nie zawiera 'user' (starsza instalacja z samym
    // adminem), poszerzamy do VARCHAR — rejestracja zawsze wpisuje 'user'.
    if (isset($cols['role']) && stripos((string) $cols['role']['Type'], 'enum') !== false
        && stripos((string) $cols['role']['Type'], "'user'") === false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
    }
    // Unikalny e-mail nie może kolidować z wieloma pustymi wartościami —
    // jeśli już istnieją puste stringi zamiast NULL, ujednolicamy.
    $pdo->exec("UPDATE users SET email = NULL WHERE email = ''");

    @file_put_contents($marker, (string) time());
}

zm_ensure_users_schema($pdo);

/**
 * Kolumny do moderacji zdjęć zgłaszanych przez zwykłych użytkowników
 * (panel.php → submit-photo-api.php). Zdjęcia dodane przez admina (istniejący
 * upload w admin-api.php) mają moderation_status = 'approved' domyślnie —
 * ich zachowanie się nie zmienia.
 */
function zm_ensure_moderation_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-moderation-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM photos")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = $c;
    }

    if (!isset($cols['moderation_status'])) {
        $pdo->exec(
            "ALTER TABLE photos
             ADD COLUMN moderation_status VARCHAR(12) NOT NULL DEFAULT 'approved' AFTER published,
             ADD KEY idx_photos_moderation (moderation_status)"
        );
    }
    if (!isset($cols['submitted_by_user_id'])) {
        $pdo->exec(
            "ALTER TABLE photos ADD COLUMN submitted_by_user_id INT UNSIGNED NULL AFTER moderation_status"
        );
        // FK osobno — jeśli już istnieją osierocone wartości albo silnik nie obsłuży inline, nie blokujemy reszty
        try {
            $pdo->exec(
                "ALTER TABLE photos
                 ADD CONSTRAINT fk_photos_submitted_by FOREIGN KEY (submitted_by_user_id)
                     REFERENCES users(id) ON DELETE SET NULL"
            );
        } catch (Throwable $e) {
            // brak FK nie jest krytyczny — moderacja i tak działa po submitted_by_user_id
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_moderation_schema($pdo);

/**
 * Wykrywanie gminy ze współrzędnych (api.adresy.app) — kolumny na zdjęciu.
 *
 *   gmina_detected — nazwa gminy odczytana z współrzędnych (do propozycji kategorii)
 *   geo_status     — matched | proposed | out_of_range | failed | none
 *   geo_json       — znormalizowany wynik geokodowania (miejscowość, kod pocztowy,
 *                    powiat, województwo) — tylko do wglądu w panelu admina
 *
 * Kolumny są NULL-owalne, więc istniejące zdjęcia zachowują się bez zmian.
 */
function zm_ensure_geo_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-geo-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM photos")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }

    // Dwa żądania potrafią przejść ten kod w tej samej sekundzie (strona + AJAX).
    // ALTER wykonany przez jedno z nich wygrywa, a drugie dostaje 1060 „już istnieje”
    // — traktujemy to jako sukces, inaczej zapytanie pada z 500.
    $addColumn = static function (string $sql) use ($pdo): void {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '42S21') {
                throw $e;
            }
        }
    };

    if (!isset($cols['gmina_detected'])) {
        $addColumn("ALTER TABLE photos ADD COLUMN gmina_detected VARCHAR(120) NULL AFTER lng");
    }
    if (!isset($cols['geo_status'])) {
        $addColumn("ALTER TABLE photos ADD COLUMN geo_status VARCHAR(16) NULL AFTER gmina_detected");
    }
    if (!isset($cols['geo_json'])) {
        $addColumn("ALTER TABLE photos ADD COLUMN geo_json TEXT NULL AFTER geo_status");
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_geo_schema($pdo);

/**
 * Wnioski użytkowników o nowe kategorie (podkategoria miejscowości albo
 * cała gmina). Użytkownik nigdy nie tworzy kategorii sam — wysyła wniosek,
 * administrator rozpatruje go w panelu admina („Wnioski o kategorie”).
 *
 * Osobny znacznik: `.schema-category-request-v1`. Niczego nie usuwa,
 * istniejące zdjęcia dostają tylko NULL-owalną kolumnę.
 */
function zm_ensure_category_request_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-category-request-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS category_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(16) NOT NULL DEFAULT 'place',
            gmina VARCHAR(120) NULL,
            parent_id INT UNSIGNED NULL,
            parent_name VARCHAR(150) NULL,
            child_name VARCHAR(150) NOT NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            requested_by_user_id INT UNSIGNED NULL,
            resolved_by_user_id INT UNSIGNED NULL,
            resolved_at DATETIME NULL,
            created_category_id INT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cr_status (status),
            KEY idx_cr_child (child_name),
            KEY idx_cr_requested (requested_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM photos")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }

    if (!isset($cols['category_request_id'])) {
        try {
            $pdo->exec(
                'ALTER TABLE photos ADD COLUMN category_request_id INT UNSIGNED NULL AFTER geo_json'
            );
            $pdo->exec('CREATE INDEX idx_photos_request ON photos (category_request_id)');
        } catch (PDOException $e) {
            // 42S21 = kolumna już jest, 42000 = indeks już jest — wyścig dwóch żądań
            $code = (string) $e->getCode();
            if ($code !== '42S21' && $code !== '42000') {
                throw $e;
            }
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_category_request_schema($pdo);

/**
 * Profil społecznościowy użytkownika (panel.php → auth-api.php, profil.php):
 * krótkie „o mnie”, miejscowość, linki do social mediów i przełącznik
 * publicznego profilu. Osobny znacznik — odpala się raz, automatycznie,
 * niczego nie usuwa ani nie zmienia w istniejących danych.
 *
 * Kolumna `profile_public` ma domyślną wartość 0 (prywatny) — bezpieczniej dla
 * ręcznych wstawień. Rejestracja (auth.php → zm_register) ustawia ją jawnie na 1,
 * więc nowe konto od razu ma publiczny profil; użytkownik może go ukryć w panelu.
 */
function zm_ensure_profile_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-profile-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }

    $add = [
        'bio'              => "VARCHAR(300) NULL",
        'location'         => "VARCHAR(80) NULL",
        'link_facebook'    => "VARCHAR(255) NULL",
        'link_instagram'   => "VARCHAR(255) NULL",
        'link_youtube'     => "VARCHAR(255) NULL",
        'link_tiktok'      => "VARCHAR(255) NULL",
        'link_website'     => "VARCHAR(255) NULL",
        'profile_public'   => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($add as $name => $def) {
        if (!isset($cols[$name])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $def");
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_profile_schema($pdo);

/**
 * Własny awatar użytkownika (kółeczko zamiast inicjałów).
 *
 * Plik trzymamy jako jeden kwadratowy JPEG 512×512 w uploads/avatars/ —
 * po przeskalowaniu przez GD, więc w środku nie ma ani EXIF (dane GPS
 * z telefonu nie wychodzą na serwer), ani możliwości przemycenia skryptu.
 * Kolumna trzyma tylko nazwę pliku względem AVATARS_DIR, nigdy ścieżkę bezwzględną.
 * Osobny znacznik — nie dotyka wcześniejszych migracji.
 */
function zm_ensure_avatar_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-avatar-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($cols['avatar_path'])) {
        zm_ensure_users_column($pdo, 'avatar_path', 'VARCHAR(255) NULL', 'profile_public');
    }
    if (!isset($cols['avatar_updated_at'])) {
        zm_ensure_users_column($pdo, 'avatar_updated_at', 'BIGINT UNSIGNED NULL');
    }

    if (!is_dir(AVATARS_DIR)) {
        @mkdir(AVATARS_DIR, 0775, true);
    }
    @file_put_contents($marker, (string) time());
}

/**
 * Komentarze i polubienia powiązane z kontem + przełącznik „pokazuj
 * komentarze na profilu”. Osobny znacznik, bo zm_ensure_schema() jest
 * bramkowane przez `.schema-v7` i na istniejących instalacjach nie wróci.
 *
 *  - comments.user_id      NULL = komentarz anonimowy (także starsze, napisane
 *                          przed zmianą) — nazwa zostaje zwykłym tekstem
 *  - comment_likes.user_id polubienie z konta; voter z localStorage zostaje,
 *                          żeby jeden odwiedzający nie polubić dwa razy
 *  - users.show_comments    1 = komentarze widać na profilu dla wszystkich
 */
function zm_ensure_comment_accounts_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-comment-accounts-v1';
    if (is_file($marker)) {
        return;
    }

    $comCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM comments")->fetchAll() as $c) {
        $comCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($comCols['user_id'])) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN user_id INT UNSIGNED NULL, ADD KEY idx_comments_user (user_id, created_at)');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comment_likes (
            comment_id INT UNSIGNED NOT NULL,
            voter CHAR(32) NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (comment_id, voter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $likeCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM comment_likes")->fetchAll() as $c) {
        $likeCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($likeCols['user_id'])) {
        $pdo->exec('ALTER TABLE comment_likes ADD COLUMN user_id INT UNSIGNED NULL, ADD KEY idx_likes_user (user_id, created_at)');
    }

    $userCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $c) {
        $userCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($userCols['show_comments'])) {
        zm_ensure_users_column($pdo, 'show_comments', 'TINYINT(1) NOT NULL DEFAULT 1', 'profile_public');
    }

    @file_put_contents($marker, (string) time());
}

/**
 * Naprawa kształtu tabeli `comment_likes` na instalacjach założonych z dawnego
 * `schema.sql`. Tam tabela ma `visitor_token CHAR(64)` i klucz główny `id`, a
 * obecny kod (i świeże instalacje z migracji) używa `voter CHAR(32)` z kluczem
 * `(comment_id, voter)`. `CREATE TABLE IF NOT EXISTS` nie poprawi istniejącej
 * tabeli, dlatego robimy to osobnym, oznaczonym znacznikiem krokiem.
 *
 * Bez tej poprawki `comments.php` pada na `Unknown column 'voter'` (HTTP 500),
 * więc nie ładują się komentarze i nie działają polubienia.
 */
function zm_ensure_comment_likes_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-comment-likes-v1';
    if (is_file($marker)) {
        return;
    }

    $exists = $pdo->query("SHOW TABLES LIKE 'comment_likes'")->fetchColumn() !== false;

    if ($exists) {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM comment_likes')->fetchAll() as $c) {
            $cols[strtolower((string) $c['Field'])] = true;
        }

        if (!isset($cols['voter'])) {
            // Stara tabela z `schema.sql`. Obecny kod jej tak nie używa — gdy jest
            // pusta, odtwarzamy ją w docelowym kształcie (bezstratnie); gdy ma
            // wiersze, zachowawczo dodajemy `voter` i przebudowujemy klucze.
            $count = (int) $pdo->query('SELECT COUNT(*) FROM comment_likes')->fetchColumn();
            if ($count === 0) {
                $pdo->exec('DROP TABLE comment_likes');
                $exists = false;
            } else {
                $pdo->exec('ALTER TABLE comment_likes ADD COLUMN voter CHAR(32) NULL');
                if (isset($cols['visitor_token'])) {
                    $pdo->exec('UPDATE comment_likes SET voter = LEFT(visitor_token, 32)');
                    try { $pdo->exec('ALTER TABLE comment_likes DROP INDEX uq_comment_like'); } catch (Throwable $e) {}
                    $pdo->exec('ALTER TABLE comment_likes DROP COLUMN visitor_token');
                }
                $pdo->exec('ALTER TABLE comment_likes MODIFY voter CHAR(32) NOT NULL');
                if (isset($cols['id'])) {
                    try { $pdo->exec('ALTER TABLE comment_likes MODIFY id BIGINT UNSIGNED NOT NULL'); } catch (Throwable $e) {}
                    try { $pdo->exec('ALTER TABLE comment_likes DROP PRIMARY KEY'); } catch (Throwable $e) {}
                    try { $pdo->exec('ALTER TABLE comment_likes DROP COLUMN id'); } catch (Throwable $e) {}
                }
                try { $pdo->exec('ALTER TABLE comment_likes ADD PRIMARY KEY (comment_id, voter)'); } catch (Throwable $e) {}
            }
        }
    }

    if (!$exists) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comment_likes (
                comment_id INT UNSIGNED NOT NULL,
                voter CHAR(32) NOT NULL,
                created_at BIGINT NOT NULL,
                user_id INT UNSIGNED NULL,
                PRIMARY KEY (comment_id, voter),
                KEY idx_likes_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    // `user_id` (polubienia z konta) — na starszych instalacjach mogło brakować.
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM comment_likes')->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($cols['user_id'])) {
        $pdo->exec('ALTER TABLE comment_likes ADD COLUMN user_id INT UNSIGNED NULL, ADD KEY idx_likes_user (user_id, created_at)');
    }

    @file_put_contents($marker, (string) time());
}

/**
 * Ulubione zdjęcia użytkownika.
 *
 *  - user_favorites.user_id  kto polubił (konto z sessions)
 *  - user_favorites.photo_id zdjęcie z tabeli photos
 *
 * Klucz (user_id, photo_id) = jedno zdjęcie może być ulubione przez tego samego
 * użytkownika tylko raz. Dzięki kluczowi obcemu ulubione są per konto, więc po
 * zalogowaniu na innym urządzeniu widać te same pozycje (wcześniej trzymane
 * tylko w localStorage przeglądarki).
 *
 * Osobny znacznik, bo zm_ensure_schema() jest bramkowane przez `.schema-v7`
 * i na istniejących instalacjach nie wróci.
 */
function zm_ensure_favorites_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-favorites-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_favorites (
            user_id INT UNSIGNED NOT NULL,
            photo_id INT UNSIGNED NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (user_id, photo_id),
            KEY idx_fav_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    @file_put_contents($marker, (string) time());
}

/**
 * System punktów, rang, polubień zdjęć i banów.
 *
 * OSOBNY mechanizm od user_favorites:
 *  - photo_likes     — „prawdziwe” polubienia zdjęć (serduszko „lubię to”)
 *  - points_history  — dziennik zmian punktów (delta + akcja + źródło)
 *  - rank_defs       — definicje rang; admin może zmieniać nazwy i progi
 *  - users.points / users.rank_override / users.is_banned / banned_at / banned_by
 *
 * Nagrody (nigdy nie schodzą poniżej 0 punktów):
 *  - +10  dodane zdjęcie — PO zatwierdzeniu moderacji (photo_add, ref=photo.id)
 *  - +2   komentarz z konta (comment, ref=comment.id)
 *  - +1   polubienie zdjęcia (photo_like, ref=photo.id); cofnięcie = -1
 * Każda nagroda za akcję jest przyznawana RAZ (strażnik w points_history),
 * dzięki czemu wielokrotne zatwierdzenie tej samej fotki nie dodaje punktów.
 *
 * Naliczenie wstecz (znacznik .schema-points-v1) — jednorazowo za istniejącą
 * aktywność: zatwierdzone zdjęcia +10 i komentarze z konta +2.
 */
function zm_ensure_points_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-points-v1';
    if (is_file($marker)) {
        return;
    }

    // --- kolumny users ---
    $userCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $userCols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($userCols['points'])) {
        zm_ensure_users_column($pdo, 'points', 'INT UNSIGNED NOT NULL DEFAULT 0', 'show_comments');
    }
    if (!isset($userCols['rank_override'])) {
        zm_ensure_users_column($pdo, 'rank_override', 'INT UNSIGNED NULL', 'points');
    }
    if (!isset($userCols['is_banned'])) {
        zm_ensure_users_column($pdo, 'is_banned', 'TINYINT(1) NOT NULL DEFAULT 0', 'rank_override');
    }
    if (!isset($userCols['banned_at'])) {
        zm_ensure_users_column($pdo, 'banned_at', 'DATETIME NULL', 'is_banned');
    }
    if (!isset($userCols['banned_by'])) {
        zm_ensure_users_column($pdo, 'banned_by', 'INT UNSIGNED NULL', 'banned_at');
    }

    // --- polubienia zdjęć (osobny, prawdziwy system, nie user_favorites) ---
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS photo_likes (
            user_id  INT UNSIGNED NOT NULL,
            photo_id INT UNSIGNED NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (user_id, photo_id),
            KEY idx_photo_likes_photo (photo_id, created_at),
            CONSTRAINT fk_pl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pl_photo FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // --- rangi (6 pozycji, progi edytowalne przez admina) ---
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rank_defs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            position INT UNSIGNED NOT NULL,
            name VARCHAR(60) NOT NULL,
            min_points INT UNSIGNED NOT NULL DEFAULT 0,
            special TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_rank_position (position),
            UNIQUE KEY uq_rank_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // --- historia punktów ---
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS points_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            delta INT NOT NULL,
            points_after INT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            ref_type VARCHAR(20) NULL,
            ref_id BIGINT UNSIGNED NULL,
            admin_id INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ph_user (user_id, created_at),
            KEY idx_ph_ref (action, ref_type, ref_id),
            CONSTRAINT fk_ph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ph_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // FK rank_override przy tworzeniu (osobno, bo tabela rank_defs musi istnieć)
    try {
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_rank_override
             FOREIGN KEY (rank_override) REFERENCES rank_defs(id) ON DELETE SET NULL'
        );
    } catch (Throwable $e) {
        // już istnieje albo silnik nie obsłuży — nie blokuje niczego
    }
    try {
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_banned_by
             FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE SET NULL'
        );
    } catch (Throwable $e) {
        // jw.
    }

    // --- seed rang (tylko gdy tabela pusta) ---
    $empty = (int) $pdo->query('SELECT COUNT(*) FROM rank_defs')->fetchColumn();
    if ($empty === 0) {
        $seed = [
            [1, 'Obserwator', 0],
            [2, 'Bywalec', 50],
            [3, 'Dokumentalista', 150],
            [4, 'Kronikarz', 350],
            [5, 'Strażnik regionu', 700],
            [6, 'Lokalny archiwista', 1200],
        ];
        $ins = $pdo->prepare('INSERT INTO rank_defs (position, name, min_points) VALUES (:p, :n, :m)');
        foreach ($seed as [$p, $n, $m]) {
            $ins->execute(['p' => $p, 'n' => $n, 'm' => $m]);
        }
    }

    // --- naliczenie wstecz za istniejącą aktywność ---
    $awardedPhotos = 0;
    $awardedComments = 0;
    try {
        $pdo->beginTransaction();

        $users = $pdo->query('SELECT id FROM users')->fetchAll();
        $photosStmt = $pdo->prepare(
            "SELECT id FROM photos
             WHERE submitted_by_user_id = :u AND moderation_status = 'approved'
               AND (kind IS NULL OR kind = 'image')"
        );
        $commentsStmt = $pdo->prepare('SELECT id FROM comments WHERE user_id = :u');
        $hasAward = $pdo->prepare(
            'SELECT 1 FROM points_history WHERE user_id = :u AND action = :a
             AND ref_type = :t AND ref_id = :rid AND admin_id IS NULL LIMIT 1'
        );

        foreach ($users as $u) {
            $uid = (int) $u['id'];

            $photosStmt->execute(['u' => $uid]);
            foreach ($photosStmt->fetchAll() as $ph) {
                $rid = (int) $ph['id'];
                $hasAward->execute(['u' => $uid, 'a' => 'photo_add', 't' => 'photo', 'rid' => $rid]);
                if ($hasAward->fetchColumn()) {
                    continue;
                }
                $curStmt = $pdo->prepare('SELECT points FROM users WHERE id = :id');
                $curStmt->execute(['id' => $uid]);
                $cur = (int) $curStmt->fetchColumn();
                $after = $cur + 10;
                $pdo->prepare('UPDATE users SET points = :p WHERE id = :id')->execute(['p' => $after, 'id' => $uid]);
                $pdo->prepare(
                    'INSERT INTO points_history (user_id, delta, points_after, action, ref_type, ref_id)
                     VALUES (:u, 10, :p, :a, :t, :rid)'
                )->execute(['u' => $uid, 'p' => $after, 'a' => 'photo_add', 't' => 'photo', 'rid' => $rid]);
                $awardedPhotos++;
            }

            $commentsStmt->execute(['u' => $uid]);
            foreach ($commentsStmt->fetchAll() as $cm) {
                $rid = (int) $cm['id'];
                $hasAward->execute(['u' => $uid, 'a' => 'comment', 't' => 'comment', 'rid' => $rid]);
                if ($hasAward->fetchColumn()) {
                    continue;
                }
                $curStmt = $pdo->prepare('SELECT points FROM users WHERE id = :id');
                $curStmt->execute(['id' => $uid]);
                $cur = (int) $curStmt->fetchColumn();
                $after = $cur + 2;
                $pdo->prepare('UPDATE users SET points = :p WHERE id = :id')->execute(['p' => $after, 'id' => $uid]);
                $pdo->prepare(
                    'INSERT INTO points_history (user_id, delta, points_after, action, ref_type, ref_id)
                     VALUES (:u, 2, :p, :a, :t, :rid)'
                )->execute(['u' => $uid, 'p' => $after, 'a' => 'comment', 't' => 'comment', 'rid' => $rid]);
                $awardedComments++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // naliczenie to dodatek — jego błąd nie może zablokować strony
    }

    @file_put_contents($marker, (string) time());
}

/**
 * Flaga „ranga specjalna" (gwiazdka) w rank_defs — dodawana na już działających
 * instalacjach. Rangi specjalne nadaje wyłącznie administracja (np. „Team");
 * nie da się ich zdobyć punktami, więc nie wchodzą do automatycznego przydziału.
 */
function zm_ensure_rank_special_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-rank-special-v1';
    if (is_file($marker)) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM rank_defs')->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($cols['special'])) {
        $pdo->exec('ALTER TABLE rank_defs ADD COLUMN special TINYINT(1) NOT NULL DEFAULT 0 AFTER min_points');
    }
    @file_put_contents($marker, (string) time());
}

/**
 * Druga, DODATKOWA ranga specjalna (users.special_rank) — niezależna od rangi
 * z punktów (rank_override). Specjalną nadaje wyłącznie administracja (np.
 * „Team"), a jej posiadacz DALEJ ma zwykłą rangę liczona z punktów.
 *
 * Stare dane: ktoś, kto dostał specjalną przez rank_override (przed tą zmianą),
 * jest migrowany na nową kolumnę, a RANKA Z PUNKTÓW wraca do automatycznej.
 */
function zm_ensure_special_rank_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-special-rank-v1';
    if (is_file($marker)) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    if (!isset($cols['special_rank'])) {
        zm_ensure_users_column($pdo, 'special_rank', 'INT UNSIGNED NULL', 'rank_override');
    }
    try {
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_special_rank
             FOREIGN KEY (special_rank) REFERENCES rank_defs(id) ON DELETE SET NULL'
        );
    } catch (Throwable $e) {
        // już istnieje albo silnik nie obsłużył — nie blokuje niczego
    }
    // back-compat: nadpisanie na rangę specjalną → przenieś do osobnej kolumny
    $pdo->exec(
        'UPDATE users u JOIN rank_defs d ON u.rank_override = d.id AND d.special = 1
         SET u.special_rank = d.id, u.rank_override = NULL'
    );
    @file_put_contents($marker, (string) time());
}

/**
 * Aktualne definicje rang (position rosnąco). Mało wierszy, więc prosta pamięć podręczna.
 *
 * @return list<array{id:int,position:int,name:string,min_points:int,special:bool}>
 */
function zm_rank_defs(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache === null) {
        $rows = $pdo->query('SELECT id, position, name, min_points, special FROM rank_defs ORDER BY position ASC')->fetchAll();
        $cache = array_map(
            static fn(array $r): array => [
                'id' => (int) $r['id'],
                'position' => (int) $r['position'],
                'name' => (string) $r['name'],
                'min_points' => (int) $r['min_points'],
                'special' => (int) ($r['special'] ?? 0) === 1,
            ],
            $rows
        );
    }
    return $cache;
}

/**
 * Zwykła ranga użytkownika (z punktów albo ręczne nadanie zwykłej rangi).
 *
 * To NIE jest ranga specjalna — specjalną sypie osobna kolumna special_rank
 * (patrz zm_user_rank_special). Zwykła ranga jest dla wszystkich; specjalna
 * jest dodatkowa i nadaje ją wyłącznie administracja.
 *
 * @param array{points?:mixed,rank_override?:mixed,special_rank?:mixed} $user
 * @return array{id:int,position:int,name:string,min_points:int,manual:bool,special:bool}
 */
function zm_user_rank(PDO $pdo, array $user): array
{
    $defs = zm_rank_defs($pdo);
    $override = (int) ($user['rank_override'] ?? 0);

    foreach ($defs as $d) {
        if ($override > 0 && (int) $d['id'] === $override) {
            $d['manual'] = true;
            $d['special'] = !empty($d['special']);
            return $d;
        }
    }

    $points = (int) ($user['points'] ?? 0);
    $best = null;
    foreach ($defs as $d) {
        if (!empty($d['special'])) {
            continue; // specjalnej nie da się zdobyć punktami
        }
        if ($points >= (int) $d['min_points'] && ($best === null || (int) $d['position'] >= (int) $best['position'])) {
            $best = $d;
        }
    }
    if ($best === null) {
        $best = ['id' => 0, 'position' => 0, 'name' => '—', 'min_points' => 0];
    }
    $best['manual'] = false;
    $best['special'] = false;
    return $best;
}

/**
 * Dodatkowa ranga specjalna (users.special_rank) albo null.
 *
 * Specjalna jest niezależna od rangi z punktów — użytkownik może mieć obie.
 * Zwracamy definicję tylko, gdy wskazana ranga naprawdę jest specjalną
 * (specjal_rank), bo kolumna może wskazywać też na zwykłą przy ręcznej edycji.
 *
 * @param array{special_rank?:mixed} $user
 * @return array{id:int,position:int,name:string,min_points:int,manual:bool,special:bool}|null
 */
function zm_user_rank_special(PDO $pdo, array $user): ?array
{
    $sp = (int) ($user['special_rank'] ?? 0);
    if ($sp <= 0) {
        return null;
    }
    foreach (zm_rank_defs($pdo) as $d) {
        if ((int) $d['id'] === $sp && !empty($d['special'])) {
            $d['manual'] = true;
            $d['special'] = true;
            return $d;
        }
    }
    return null;
}

/**
 * Czy definicja rangi to ukryta ranga VIP? VIP jest rangą specjalną, widoczną
 * wyłącznie w panelu administracyjnym — publicznie nie pokazujemy ani chipu,
 * ani filtra w katalogu osób.
 *
 * @param array<string,mixed>|null $rank
 */
function zm_rank_is_vip(?array $rank): bool
{
    if ($rank === null || empty($rank['special'])) {
        return false;
    }
    return strcasecmp(trim((string) ($rank['name'] ?? '')), 'VIP') === 0;
}

/**
 * Ranga specjalna do publicznego widoku: VIP zostaje ukryty, reszta bez zmian.
 *
 * @param array<string,mixed>|null $rank
 * @return array<string,mixed>|null
 */
function zm_public_special_rank(?array $rank): ?array
{
    return zm_rank_is_vip($rank) ? null : $rank;
}

/**
 * ID rangi specjalnej „VIP”. Tworzymy ją przy pierwszym użyciu, żeby dodanie
 * konta w panelu nie wymagało ręcznego zakładania rangi. Cache w pamięci,
 * `zm_rank_defs()` odświeżamy po insercie.
 */
function zm_vip_rank_id(PDO $pdo, bool $create = false): ?int
{
    static $vipId = null;
    static $loaded = false;

    if (!$loaded) {
        try {
            $st = $pdo->query(
                "SELECT id FROM rank_defs
                  WHERE special = 1 AND LOWER(TRIM(name)) = 'vip'
                  ORDER BY id ASC LIMIT 1"
            );
            $id = $st->fetchColumn();
            if ($id !== false) {
                $vipId = (int) $id;
            }
        } catch (Throwable $e) {
            $vipId = null;
        }
        $loaded = true;
    }

    if ($vipId !== null || !$create) {
        return $vipId;
    }

    try {
        $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM rank_defs')->fetchColumn();
        $ins = $pdo->prepare(
            'INSERT INTO rank_defs (position, name, min_points, special) VALUES (:p, :n, 0, 1)'
        );
        $ins->execute(['p' => $pos + 1, 'n' => 'VIP']);
        $vipId = (int) $pdo->lastInsertId();
        zm_rank_defs($pdo, true);
    } catch (Throwable $e) {
        // np. równoległy request zdążył już dodać rangę — spróbuj ją odczytać
        try {
            $st = $pdo->query(
                "SELECT id FROM rank_defs
                  WHERE special = 1 AND LOWER(TRIM(name)) = 'vip'
                  ORDER BY id ASC LIMIT 1"
            );
            $id = $st->fetchColumn();
            $vipId = $id === false ? null : (int) $id;
        } catch (Throwable $e2) {
            $vipId = null;
        }
    }

    return $vipId;
}

/** Czy konto ma rangę specjalną VIP (na podstawie bieżącego stanu bazy). */
function zm_user_is_vip(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $vipId = zm_vip_rank_id($pdo);
    if ($vipId === null) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND special_rank = :vip LIMIT 1');
        $st->execute(['id' => $userId, 'vip' => $vipId]);
        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Warunek SQL: konto liczy się w statystykach. Ukryte konto VIP wypada,
 * chyba że samo włączyło publiczny profil („Profil publiczny” w panelu konta).
 * Alias musi wskazywać tabelę `users`.
 */
function zm_sql_stats_visible_user(string $alias = 'u'): string
{
    if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $alias)) {
        $alias = 'u';
    }
    return "(NOT EXISTS (
        SELECT 1 FROM rank_defs zm_vip
        WHERE zm_vip.id = {$alias}.special_rank
          AND zm_vip.special = 1
          AND LOWER(TRIM(zm_vip.name)) = 'vip'
    ) OR {$alias}.profile_public = 1)";
}

/**
 * Kolor chipu rangi — wyliczany pozycji, żeby rangi miały stałe barwy
 * niezależnie od nazw i progu.
 */
function zm_rank_hue(int $position): int
{
    return ($position * 47 + 8) % 360;
}

/**
 * Komplet danych do karty „Twoja ranga” w panelu konta.
 *
 * Panel renderował to po stronie serwera, więc długo otwarta strona pokazywała
 * punkty i pasmo sprzed moderacji albo ręcznej korekty admina. Teraz ten sam
 * zestaw liczy API (auth-api.php?action=rank), a JS podmienia widok w miejscu.
 *
 * @return array<string,mixed>
 */
function zm_rank_view(PDO $pdo, int $userId): array
{
    $out = [
        'points' => 0,
        'rank' => null,
        'rankManual' => false,
        'rankHue' => 8,
        'specialRank' => null,
        'specialHue' => 8,
        'next' => null,
        'fill' => 0,
        'need' => 0,
        'counts' => ['photos' => 0, 'comments' => 0, 'likes' => 0, 'favorites' => 0],
    ];
    if ($userId <= 0) {
        return $out;
    }

    try {
        $q = $pdo->prepare('SELECT points, rank_override, special_rank FROM users WHERE id = :id');
        $q->execute(['id' => $userId]);
        $uRow = $q->fetch();
        if (!$uRow) {
            return $out;
        }
        $out['points'] = (int) $uRow['points'];

        $rank = zm_user_rank($pdo, $uRow);
        if ($rank['id'] !== null) {
            $out['rank'] = [
                'id' => (int) $rank['id'],
                'name' => (string) $rank['name'],
                'position' => (int) $rank['position'],
                'min_points' => (int) $rank['min_points'],
            ];
            $out['rankManual'] = !empty($rank['manual']);
            $out['rankHue'] = zm_rank_hue((int) $rank['position']);

            // następny próg = najbliższa ranga ZWYKŁA za nami (specjalne nie tworzą pasm)
            $cfg = zm_rank_defs($pdo);
            $pos = (int) $rank['position'];
            foreach ($cfg as $i => $d) {
                if ((int) $d['position'] === $pos) {
                    for ($j = $i + 1, $n = count($cfg); $j < $n; $j++) {
                        if (empty($cfg[$j]['special'])) {
                            $next = $cfg[$j];
                            $out['next'] = [
                                'name' => (string) $next['name'],
                                'min_points' => (int) $next['min_points'],
                            ];
                            $span = max(1, (int) $next['min_points'] - (int) $rank['min_points']);
                            $out['fill'] = max(0, min(100, (int) round(
                                100 * max(0, $out['points'] - (int) $rank['min_points']) / $span
                            )));
                            $out['need'] = max(0, (int) $next['min_points'] - $out['points']);
                            break;
                        }
                    }
                    break;
                }
            }
        }

        $sp = zm_public_special_rank(zm_user_rank_special($pdo, $uRow));
        if ($sp !== null) {
            $out['specialRank'] = [
                'id' => (int) $sp['id'],
                'name' => (string) $sp['name'],
                'position' => (int) $sp['position'],
            ];
            $out['specialHue'] = zm_rank_hue((int) $sp['position']);
        }
    } catch (Throwable $e) {
        // brak danych nie może wywrócić panelu
    }

    // liczniki aktywności w tej samej karcie
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE user_id = :id');
        $s->execute(['id' => $userId]);
        $out['counts']['comments'] = (int) $s->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM photo_likes WHERE user_id = :id');
        $s->execute(['id' => $userId]);
        $out['counts']['likes'] = (int) $s->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM photos WHERE submitted_by_user_id = :id
             AND moderation_status = :ap AND published = 1
             AND (kind IS NULL OR kind = :img)'
        );
        $s->execute(['id' => $userId, 'ap' => 'approved', 'img' => 'image']);
        $out['counts']['photos'] = (int) $s->fetchColumn();
    } catch (Throwable $e) {
    }
    // ulubione — z bazy. Panel wcześniej liczył je z localStorage, czyli
    // pokazywał stan sprzed logowania (a na innym urządzeniu wcale nie ten sam)
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM user_favorites WHERE user_id = :id');
        $s->execute(['id' => $userId]);
        $out['counts']['favorites'] = (int) $s->fetchColumn();
    } catch (Throwable $e) {
    }

    return $out;
}

/**
 * Wiersze tabeli rang (bez <tbody>) — wspólne dla panelu i API, żeby po
 * odświeżeniu w przeglądarce tabela była ta sama co po wyrenderowaniu przez
 * serwer, łącznie z zaznaczeniem własnego wiersza.
 */
function zm_rank_table_html(PDO $pdo, ?int $myRankId, ?int $mySpRankId, bool $rankManual): string
{
    try {
        $cfg = zm_rank_defs($pdo);
        usort($cfg, static function (array $a, array $b): int {
            $aSpecial = !empty($a['special']);
            $bSpecial = !empty($b['special']);
            if ($aSpecial !== $bSpecial) {
                return $aSpecial ? 1 : -1;
            }
            return (int) $a['position'] <=> (int) $b['position'];
        });
        $rows = [];
        $cfgCount = count($cfg);
        foreach ($cfg as $i => $d) {
            if (zm_rank_is_vip($d)) {
                continue;
            }
            $special = !empty($d['special']);
            $min = (int) $d['min_points'];
            // granica górna pasma = próg następnej rangi zwykłej
            $max = null;
            for ($j = $i + 1; $j < $cfgCount; $j++) {
                if (empty($cfg[$j]['special'])) {
                    $max = (int) $cfg[$j]['min_points'];
                    break;
                }
            }
            $n = 0;
            if ($special) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE special_rank = :rid');
                $st->execute(['rid' => (int) $d['id']]);
                $n = (int) $st->fetchColumn();
                $minLabel = '—';
                $maxLabel = '—';
            } else {
                $st = $max === null
                    ? $pdo->prepare('SELECT COUNT(*) FROM users WHERE points >= :m')
                    : $pdo->prepare('SELECT COUNT(*) FROM users WHERE points >= :m AND points < :x');
                $st->execute($max === null ? ['m' => $min] : ['m' => $min, 'x' => $max]);
                $n = (int) $st->fetchColumn();
                $minLabel = (string) $min;
                $maxLabel = $max === null ? '—' : (string) $max;
            }
            $hue = zm_rank_hue((int) $d['position']);
            $mine = $special
                ? (int) $d['id'] === (int) $mySpRankId
                : (int) $d['id'] === (int) $myRankId;
            $rows[] = sprintf(
                '<tr%s><td style="text-align:left;">%s<span class="rk-dot" style="background:hsl(%d 45%% 32%%);"></span>%s%s%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                $mine ? ' class="rk-mine"' : '',
                $special ? '<span class="rk-star" title="Ranga specjalna — nadaje ją administracja">★</span> ' : '',
                $hue,
                htmlspecialchars((string) $d['name'], ENT_QUOTES),
                $rankManual && (int) $d['id'] === (int) ($myRankId ?? 0) ? ' <span class="rk-you">ty</span>' : '',
                $mine ? ' <span class="rk-you">ty</span>' : '',
                $minLabel,
                $n,
                $maxLabel
            );
        }
        return implode('', $rows);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Stosuje zmianę punktów z zapisem w historii. Punkty nigdy nie spadają poniżej 0 —
 * nadmiarowa nadwyżka ujemna jest ścinana, a w historii ląduje realna delta.
 *
 * @return array{delta:int,points:int}
 */
function zm_apply_points(
    PDO $pdo,
    int $userId,
    int $delta,
    string $action,
    ?string $refType = null,
    ?int $refId = null,
    ?int $adminId = null,
    ?string $note = null
): array {
    $curStmt = $pdo->prepare('SELECT points FROM users WHERE id = :id FOR UPDATE');
    $curStmt->execute(['id' => $userId]);
    $cur = (int) $curStmt->fetchColumn();

    $applied = $delta;
    if ($cur + $applied < 0) {
        $applied = -$cur;
    }
    $after = $cur + $applied;

    $pdo->prepare('UPDATE users SET points = :p WHERE id = :id')->execute(['p' => $after, 'id' => $userId]);
    $pdo->prepare(
        'INSERT INTO points_history
            (user_id, delta, points_after, action, ref_type, ref_id, admin_id, note)
         VALUES (:u, :d, :p, :a, :t, :rid, :ad, :n)'
    )->execute([
        'u' => $userId,
        'd' => $applied,
        'p' => $after,
        'a' => $action,
        't' => $refType,
        'rid' => $refId,
        'ad' => $adminId,
        'n' => $note,
    ]);

    return ['delta' => $applied, 'points' => $after];
}

/**
 * Nagroda jednorazowa za daną akcję (+10 foto, +2 komentarz).
 * Strażnik: każda (user, akcja, ref) może dostać punkty tylko raz.
 *
 * @return array{awarded:bool,points:int,delta:int}
 */
function zm_award_once(PDO $pdo, int $userId, int $delta, string $action, string $refType, int $refId): array
{
    $has = $pdo->prepare(
        'SELECT 1 FROM points_history WHERE user_id = :u AND action = :a
         AND ref_type = :t AND ref_id = :rid AND admin_id IS NULL LIMIT 1'
    );
    $has->execute(['u' => $userId, 'a' => $action, 't' => $refType, 'rid' => $refId]);
    if ($has->fetchColumn()) {
        $curStmt = $pdo->prepare('SELECT points FROM users WHERE id = :id');
        $curStmt->execute(['id' => $userId]);
        return ['awarded' => false, 'points' => (int) $curStmt->fetchColumn(), 'delta' => 0];
    }
    $res = zm_apply_points($pdo, $userId, $delta, $action, $refType, $refId);
    return ['awarded' => true, 'points' => $res['points'], 'delta' => $res['delta']];
}

/**
 * Jednoratowa naprawa powiązań ze zdjęciami.
 *
 * `user_favorites` nie ma klucza obcego do `photos` ani do `users` (ulubione
 * dodawano też przed powstaniem tabeli zdjęć), więc po skasowaniu zdjęcia
 * albo konta zostawały wiersze wskazujące na nieistniejące rekordy. Bez tego
 * `photos.favorites` liczyło nieistniejące ulubione, a „ulubione” w panelu
 * admina pokazywałyoby śmieci.
 *
 * Uruchamiamy raz (znacznik w CACHE_DIR), potem dane utrzymują już operacje
 * zapisujące — m.in. zm_sync_photo_favorites() w photos-lib.php.
 */
function zm_reconcile_photo_refs(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.refs-reconcile-v1';
    if (is_file($marker)) {
        return;
    }
    // znacznik ustawiamy na końcu — przy bładowej bazie spróbujemy znowu
    try {
        // ulubione poza istniejącym zdjęciem lub istniejącym kontem
        $pdo->exec(
            'DELETE f FROM user_favorites f
               LEFT JOIN photos p ON p.id = f.photo_id
               LEFT JOIN users u ON u.id = f.user_id
              WHERE p.id IS NULL OR u.id IS NULL'
        );

        // polubienia komentarzy, których już nie ma (kaskada przy kasowaniu
        // komentarza to ogarnia, ale szeregowe operacje sprzed jej dodania —
        // oraz ręczne edycje bazy — mogły zostawić sieroty)
        $pdo->exec(
            'DELETE l FROM comment_likes l
               LEFT JOIN comments c ON c.id = l.comment_id
              WHERE c.id IS NULL'
        );

        // licznik ulubionych wyprowadzamy z tabeli
        $pdo->exec(
            'UPDATE photos p
                SET p.favorites = (
                    SELECT COUNT(*) FROM user_favorites f WHERE f.photo_id = p.id
                )'
        );

        @file_put_contents($marker, (string) time());
    } catch (Throwable $e) {
        // brak tabeli / baza chwilowo zajęta — nie blokujemy strony
    }
}

/** Czy konto jest zbanowane (bramka logowania i akcji zapisu). */
function zm_user_is_banned(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT is_banned FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $v = $stmt->fetchColumn();
    return is_numeric($v) && (int) $v === 1;
}

zm_ensure_points_schema($pdo);
zm_ensure_rank_special_schema($pdo);
zm_ensure_special_rank_schema($pdo);

/**
 * URL awatara dla szablonu — pusty string, gdy użytkownik nie ma zdjęcia.
 *
 * Sprawdzamy basename() i is_file(), żeby do znacznika img/src trafiła nazwa
 * z bazy, a nie ścieżka z bazy. Prywatności pilnuje avatar.php: przy profilu
 * prywatnym zwraca 404, więc URL może być w HTML bez obawy o wyciek.
 *
 * @param array{id?:mixed,avatar_path?:mixed,avatar_updated_at?:mixed} $user
 */
function zm_avatar_url(array $user, int $size = 256): string
{
    $name = basename(str_replace('\\', '/', (string) ($user['avatar_path'] ?? '')));
    if ($name === '' || !is_file(AVATARS_DIR . '/' . $name)) {
        return '';
    }
    $id = (int) ($user['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    return 'avatar.php?u=' . $id . '&s=' . $size . '&v=' . (int) ($user['avatar_updated_at'] ?? 0);
}

zm_ensure_avatar_schema($pdo);
zm_ensure_comment_accounts_schema($pdo);
zm_ensure_comment_likes_schema($pdo);
zm_ensure_favorites_schema($pdo);

/**
 * Ślad moderacji komentarza: kto i kiedy poprawił treść oraz dlaczego.
 *
 * Powód trzymamy przy komentarzu, a nie tylko w `admin_log`, bo to informacja
 * dla czytających („treść zawierała reklamę firmy”), a nie tylko wpis w
 * dzienniku administratora. Osobny znacznik — nie dotyka wcześniejszych
 * migracji i niczego nie usuwa; stare komentarze mają NULL, czyli są nietknięte.
 */
function zm_ensure_comment_moderation_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-comment-moderation-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM comments")->fetchAll() as $c) {
        $cols[strtolower((string) $c['Field'])] = true;
    }
    $add = [
        'edited_at'   => 'BIGINT NULL',
        'edited_by'   => 'VARCHAR(50) NULL',
        'edit_reason' => 'VARCHAR(255) NULL',
    ];
    foreach ($add as $name => $def) {
        if (isset($cols[$name])) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE comments ADD COLUMN `$name` $def");
        } catch (PDOException $e) {
            // Dwa żądania potrafią przejść ten kod w tej samej sekundzie — ALTER
            // wygrywa w jednym, drugie dostaje 1060 „już istnieje”, co jest sukcesem.
            if ((string) $e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_comment_moderation_schema($pdo);

/**
 * Powody zgłaszania komentarza. Lista zamknięta: panel bierze z niej etykiety,
 * a comments.php waliduje po niej zgłoszenie — dzięki temu do bazy trafia
 * tylko to, co panel umie pokazać, i nic więcej.
 */
function zm_comment_report_reasons(): array
{
    return [
        'spam'   => 'Reklama albo spam',
        'abuse'  => 'Obraźliwy albo agresywny',
        'vulgar' => 'Wulgarny język',
        'false'  => 'Fałszywe informacje',
        'other'  => 'Inne',
    ];
}

/**
 * Powody zgłaszania zdjęcia. Lista zamknięta — analogicznie do komentarzy:
 * panel bierze z niej etykiety, a photo-reports-api.php waliduje po niej
 * zgłoszenie, więc do bazy trafia tylko to, co panel umie pokazać.
 */
function zm_photo_report_reasons(): array
{
    return [
        'rights'   => 'Naruszenie praw autorskich',
        'privacy'  => 'Prywatność albo dane osobowe',
        'abuse'    => 'Treść obraźliwa albo wulgarna',
        'spam'     => 'Reklama albo spam',
        'quality'  => 'Nie pasuje do archiwum',
        'other'    => 'Inne',
    ];
}

/**
 * Zgłoszenia komentarzy (panel admina → „Zgłoszenia”).
 *
 * Zgłaszać mogą również osoby niezarejestrowane, dlatego obok `reporter_user_id`
 * trzymamy `reporter_key` — skrót z adresu IP z solą. Nie zapisujemy samego IP,
 * a skrót i tak służy tylko do odcinia powtórek i limitu zgłoszeń.
 * Osobny znacznik — nie dotyka wcześniejszych migracji.
 */
function zm_ensure_comment_reports_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-comment-reports-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comment_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            comment_id INT UNSIGNED NOT NULL,
            photo_id VARCHAR(64) NULL,
            reason VARCHAR(30) NOT NULL,
            note VARCHAR(300) NULL,
            reporter_user_id INT UNSIGNED NULL,
            reporter_key CHAR(32) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            created_at BIGINT NOT NULL,
            handled_at BIGINT NULL,
            handled_by VARCHAR(50) NULL,
            KEY idx_reports_status (status, created_at),
            KEY idx_reports_comment (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    @file_put_contents($marker, (string) time());
}

zm_ensure_comment_reports_schema($pdo);

/**
 * Zgłoszenia zdjęć (panel admina → „Zgłoszenia → Zdjęcia”).
 *
 * Ten sam model co przy komentarzach: `reporter_key` to skrót z adresu IP
 * z osobną solą, więc zgłaszać może także gość, a my odcinamy powtórki
 * i limitujemy liczbę zgłoszeń. `photo_id` trzyma numeryczne id wpisu
 * w tabeli `photos`. Osobny znacznik — nie dotyka wcześniejszych migracji.
 */
function zm_ensure_photo_reports_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-photo-reports-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS photo_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            photo_id INT UNSIGNED NOT NULL,
            reason VARCHAR(30) NOT NULL,
            note VARCHAR(300) NULL,
            reporter_user_id INT UNSIGNED NULL,
            reporter_key CHAR(32) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            created_at BIGINT NOT NULL,
            handled_at BIGINT NULL,
            handled_by VARCHAR(50) NULL,
            KEY idx_photo_reports_status (status, created_at),
            KEY idx_photo_reports_photo (photo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    @file_put_contents($marker, (string) time());
}

zm_ensure_photo_reports_schema($pdo);

/**
 * Reset hasła e-mailem. Osobny znacznik, bo zm_ensure_schema() jest
 * bramkowane przez `.schema-v7` i na istniejących instalacjach nie wróci.
 *
 * W bazie trzymamy wyłącznie skrót tokenu (SHA-256) — sam link jest
 * jednorazowy i wygasa po ZM_RESET_TTL_MIN, a poprzednie tokeny danego
 * konta są kasowane przy każdym nowym zgłoszeniu, żeby działający link
 * przestał działać po zgłoszeniu kolejnego.
 */
function zm_ensure_password_reset_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-password-reset-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            requested_ip VARCHAR(45) NULL,
            UNIQUE KEY uq_reset_token (token_hash),
            KEY idx_reset_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!is_dir(MAIL_DIR)) {
        @mkdir(MAIL_DIR, 0775, true);
    }

    // `users.email` musi być unikalny. Uwaga: `zm_ensure_users_schema()`
    // deklaruje UNIQUE tylko w CREATE TABLE IF NOT EXISTS, a na istniejącej
    // instalacji kolumna powstała przez ALTER TABLE — bez indeksu. Bez tego
    // kroku dwa konta mogłyby mieć ten sam e-mail i reset hasła poszedłby
    // do niewłaściwej osoby.
    $hasUniqueEmail = false;
    foreach ($pdo->query('SHOW INDEX FROM users')->fetchAll() as $idx) {
        if (($idx['Key_name'] ?? '') === 'uq_users_email' || (($idx['Non_unique'] ?? '1') === '0' && ($idx['Column_name'] ?? '') === 'email')) {
            $hasUniqueEmail = true;
            break;
        }
    }
    if (!$hasUniqueEmail) {
        $dupes = $pdo->query(
            "SELECT LOWER(TRIM(email)) AS e, COUNT(*) AS n
             FROM users WHERE email IS NOT NULL AND TRIM(email) <> ''
             GROUP BY LOWER(TRIM(email)) HAVING n > 1"
        )->fetchAll();
        if ($dupes) {
            // Nie kasujemy cudzych danych i nie blokujemy reszty — e-maily
            // trzeba poprawić ręcznie, a rejestracja pilnuje powtórzeń sama.
            error_log('zm: users.email nie jest unikalny — duplikaty: '
                . implode(', ', array_column($dupes, 'e')));
        } else {
            $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_password_reset_schema($pdo);

// ---------------------------------------------------------------- nazwy użytkowników

/**
 * Rozszerzenia plików aplikacji. Login typu „anna.php” wyglądałby jak adres
 * pliku, a przy krótkich adresach profili (/<login>) przestałby być linkiem do
 * profilu — takie loginy odrzucamy od razu.
 */
const ZM_RESERVED_USERNAME_EXTS = [
    'php', 'phtml', 'html', 'htm', 'css', 'js', 'mjs', 'json', 'xml', 'txt', 'log',
    'md', 'sql', 'ini', 'conf', 'env', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
    'ico', 'webmanifest', 'eml', 'heic', 'mp4', 'webm', 'zip', 'csv', 'map', 'htaccess',
];

/**
 * Adresy zajmowane przez sam serwer, których nie widać na dysku, więc skan
 * katalogu aplikacji nigdy by ich nie znalazł.
 */
const ZM_RESERVED_USERNAMES_BUILTIN = [
    'cgi-bin', 'icons', 'server-status', 'server-info', '.well-known',
    // stałe konto superadmina — patrz ZM_SUPERADMIN_LOGIN
    'admin',
];

/**
 * Login jedynego konta z pełnią władzą: tylko on nadaje i odbiera role, banuje
 * i usuwa administratorów. Panel rozpoznaje go po nazwie, a nie po roli, więc
 * nazwa jest zablokowana — inaczej konto przestałoby być superadminem, a sam
 * login dałoby się przejąć przy rejestracji.
 */
const ZM_SUPERADMIN_LOGIN = 'admin';

/**
 * Lista startowa wulgarnych nazw, których nie wolno zakładać jako login.
 * Trafia do tabeli `blocked_usernames` przy pierwszym uruchomieniu (tylko gdy
 * tabela jest pusta) i staje się w pełni edytowalna w panelu administratora —
 * administrator może każdą pozycję usunąć albo dopisać własną.
 */
const ZM_DEFAULT_BLOCKED_USERNAMES = [
    'chuj', 'chujwa', 'chuje', 'chujowy', 'jeba', 'jebany', 'jebane', 'jebiec', 'jebac',
    'kurwa', 'kurwy', 'kurwo', 'kurewa', 'kurewy', 'pierdol', 'pierdoli', 'pierdole',
    'pierdy', 'cipka', 'cip', 'dupa', 'dupci', 'dupsko', 'pizda', 'pizd', 'kutas', 'kuta',
    'skurwis', 'skurwys', 'skurwa', 'skurwia', 'gowno', 'gnoj', 'szmata', 'szmat', 'sukin',
    'ass', 'asshole', 'bastard', 'bitch', 'cunt', 'fuck', 'shit', 'dick', 'pussy',
];

/**
 * Lista startowa wyrazów, których nie wolno użyć w komentarzu (tabela
 * `blocked_words`). Wgrywana tylko raz, przy pierwszym uruchomieniu; potem jest
 * w pełni edytowalna w panelu administratora („Wulgaryzmy”).
 *
 * Wpisy to rdzenie, nie pełne słowa — dopasowanie łapie więc także odmianę
 * („kurwa”, „kurwy”, „wkurwić”). Krótkie rdzenie (3–4 znaki) pasują tylko na
 * początku wyrazu, dłuższe — w dowolnym miejscu; dzięki temu „dupa” łapie
 * „dupeczka”, a „podupadły” nie jest fałszywym trafieniem. Szczegóły:
 * zm_text_blocked_word().
 */
const ZM_DEFAULT_BLOCKED_WORDS = [
    // polskie
    'chuj', 'chuja', 'chuje', 'chujek', 'chujnia', 'chujowy', 'chujowo', 'huj', 'huja',
    'jeb', 'jebac', 'jebany', 'jebana', 'jebane', 'jebie', 'zajeb', 'przyjeb', 'wyjeb',
    'wjeb', 'dojeb', 'odjeb', 'objeb',
    'kurw', 'kurwa', 'kurwy', 'kurwo', 'kurwie', 'kurwi', 'kurew', 'kurestwo', 'kurwica',
    'kurwiarz', 'wkurw',
    'pierdol', 'pierdal', 'pierdola', 'pierdolony', 'popierdol', 'opierdol', 'spierdal',
    'wypierdal', 'odpierdol', 'zapierdal', 'rozpierdol', 'dopierdal', 'przypierdal',
    'pizd', 'pizda', 'pizdy', 'pizdo', 'pizde',
    'cip', 'cipa', 'cipy', 'cipka', 'cipki',
    'dup', 'dupa', 'dupy', 'dupka', 'dupsko', 'dupcia', 'dupeczk',
    'kutas', 'kutasy',
    'skurw', 'skurwysyn', 'skurwiel', 'skurwia',
    'gown', 'gowno', 'gowna', 'gowniarz', 'gnoj', 'gnojek',
    'szmata', 'szmat', 'szmaci',
    'suka', 'suko',
    'cwel', 'cwelu',
    'ruchac', 'rucha', 'ruchaj', 'wyrucha', 'zrucha', 'ruchanie',
    // angielskie
    'ass', 'asshole', 'bastard', 'bitch', 'bullshit', 'cunt', 'cocksuck',
    'dick', 'fuck', 'fucker', 'fucking', 'motherfuck', 'pussy', 'shit', 'shitty',
    'slut', 'whore',
];

/**
 * Wszystkie nazwy, których nie wolno użyć jako loginu, bo krótki adres
 * /<login> musi pozostać wolny dla istniejących plików i katalogów.
 *
 * Reguła jest celowo oparta na tym, co naprawdę leży w katalogu aplikacji,
 * a nie na sztywnej liście słów. Liczy się to, czy pod /<login> jest już coś:
 *
 *   - katalog (np. `uploads`, `ffmpeg`) — wejście w niego
 *     musi działać, inaczej krótki adres profilu przejąłby zawartość katalogu;
 *   - nazwa pliku w całości (np. `admin.php`) — pasuje dopiero po rozszerzeniu,
 *     a login takiego nie przyjmie, ale trzymamy je w rezerwie na wszelki wypadek.
 *
 * Czego świadomie NIE rezerwujemy: nazw plików bez rozszerzenia, czyli
 * `admin`, `panel`, `profil`, `config`. Plik nazywa się `admin.php`, więc adres
 * `/admin` jest wolny — i właśnie on ma być krótkim adresem profilu konta o
 * loginie „admin”. Katalog `admin/` blokowałby ten adres, dlatego moduł
 * panelu zdjęć z katalogu `panel-admina/` został w całości usunięty
 * (działał na obcym schemacie `photo_folders` i zwracał błąd 500).
 */
function zm_reserved_usernames(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $names = ZM_RESERVED_USERNAMES_BUILTIN;
    $entries = @scandir(__DIR__);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;   // katalogi ukryte (.htaccess, .git) — login i tak na nie nie wchodzi
            }
            $names[] = $entry;   // nazwa w całości: katalog albo plik z rozszerzeniem
        }
    }

    $out = [];
    foreach ($names as $n) {
        $n = mb_strtolower(trim((string) $n));
        if ($n !== '') {
            $out[$n] = true;
        }
    }
    $out = array_keys($out);
    sort($out);

    $cache = $out;
    return $cache;
}

/**
 * Czy login koliduje z adresem aplikacji (albo wygląda jak nazwa pliku).
 * Zawsze małymi literami — „Panel” i „panel” to ten sam adres.
 */
function zm_username_is_reserved(string $username): bool
{
    $username = trim($username);
    if ($username === '') {
        return true;
    }
    $lower = mb_strtolower($username);
    if (in_array($lower, zm_reserved_usernames(), true)) {
        return true;
    }
    $ext = strtolower((string) pathinfo($username, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, ZM_RESERVED_USERNAME_EXTS, true);
}

/**
 * Katalog aplikacji w adresie URL, np. "/zmogilna" (pusty w katalogu głównym).
 *
 * Mieszka tu, a nie w photos-lib.php, bo jest potrzebny w panel.php i profilu
 * zanim te pliki się dołączą — kolejność require bywa różna.
 */
function zm_base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

/**
 * json_encode do wklejania w <script> — bezpieczny.
 *
 * Zwykły json_encode z JSON_UNESCAPED_SLASHES nie chroni strony: JSON nie ma
 * pojęcia o HTML, więc `</script>` w treści wiadomości albo w nazwie konta
 * kończy element <script> i dalsza część strony jest już parsowana jako
 * HTML/JavaScript. Atakujący wysyła wtedy ofierze „wiadomość" i przejmuje jego
 * sesję.
 *
 * JSON_HEX_TAG zamienia < i > na \u003C / \u003E, więc żaden znak z payloadu nie
 * może już wyjść poza literał. Reszta flag chroni przed wstrzyknięciem przez
 * cudzysłowy i ampersandy (np. w atrybutach, gdy JSON trafia dalej do HTML).
 *
 * Używamy tego wszędzie, gdzie dane (mogące pochodzić od użytkownika) trafiają
 * do inline'owego <script>. Zwykłe json_encode do odpowiedzi HTTP (pliki *-api.php)
 * nie ma tego problemu — tam treść nie jest parsowana jako HTML.
 *
 * @param mixed $data
 */
function zm_js_json($data): string
{
    return (string) json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}

/**
 * Adres profilu użytkownika: krótki /<login>.
 *
 * Krótki adres działa tylko wtedy, gdy login nie koliduje z adresem aplikacji
 * (zm_reserved_usernames()). Dla starych kont, które taką nazwę już mają,
 * zostaje klasyczny profil.php?u=… — działa zawsze, także bez .htaccess.
 *
 * @return string ścieżka od katalogu aplikacji, bez adresu bazowego
 */
function zm_profile_path(string $username, ?int $id = null): string
{
    $username = trim($username);
    if (
        $username !== ''
        && preg_match('~^[A-Za-z0-9_.\-]{3,40}$~', $username) === 1
        && !zm_username_is_reserved($username)
    ) {
        return '/' . rawurlencode($username);
    }
    if ($id !== null && $id > 0) {
        return '/profil.php?id=' . (int) $id;
    }
    return '/profil.php?u=' . rawurlencode($username);
}

/**
 * Czy login jest na liście zablokowanych nazw (tabela `blocked_usernames`).
 *
 * Porównanie jest odporne na wielkość liter i obejmuje także zawieranie się
 * w loginie, więc „KURWA” i „xkurwowy” wypadają tak samo. Zawieranie sprawdzamy
 * tylko dla wyrazów długości co najmniej 4 znaki — krótkie („ass”) mogłyby
 * odrzucać zwykłe słowa.
 */
function zm_username_is_blocked(PDO $pdo, string $username): bool
{
    static $cache = [];
    static $loaded = false;

    $needle = mb_strtolower(trim($username));
    if ($needle === '') {
        return false;
    }

    if (!$loaded) {
        $cache = [];
        foreach ($pdo->query('SELECT name FROM blocked_usernames')->fetchAll() as $row) {
            $name = mb_strtolower(trim((string) $row['name']));
            if ($name !== '') {
                $cache[$name] = true;
            }
        }
        $loaded = true;
    }

    if (isset($cache[$needle])) {
        return true;
    }
    foreach (array_keys($cache) as $name) {
        if (mb_strlen($name) >= 4 && str_contains($needle, $name)) {
            return true;
        }
    }
    return false;
}

/**
 * Normalizacja tekstu do porównania z listą wulgaryzmów: małe litery, bez
 * polskich znaków, ze zdjętymi podstawieniami („ch@j”, „kurw4”, „pi3rdol”) oraz
 * ze skróconymi powtórzeniami liter („kurrrwa” → „kurwa”). Wszystko, co nie jest
 * literą a–z, staje się spacją — dzięki temu znaki przestankowe i cyfry
 * rozdzielają wyrazy, a nie sklejają je.
 */
function zm_text_norm(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o',
        'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'á' => 'a', 'ä' => 'a', 'é' => 'e',
        // podstawienia popularne w obfuskacji
        '@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
        '0' => 'o', '$' => 's', '5' => 's', '7' => 't', '8' => 'b',
    ]);
    $s = preg_replace('~[^a-z]+~', ' ', $s) ?? '';
    $s = preg_replace('~(.)\1{2,}~', '$1', $s) ?? $s;   // „kurrrwa” → „kurwa”
    return trim($s);
}

/**
 * Warianty tekstu do zm_text_norm() — dla znaków, które w obfuskacji są
 * wieloznaczne:
 *
 *   @  →  a (ass) albo u (ch@j)
 *   !  →  i (sh!t) albo u (ch!j)
 *   1  →  i (sh1t) albo u (ch1j)
 *
 * Jedna mapa znaków nie obsłużyłaby obu znaczeń naraz, więc dla każdego
 * takiego znaku powstają obie wersje i sprawdzamy je wszystkie. Trzy znaki to
 * najwyżej 8 wariantów; limit 16 zabezpiecza przed eksplozją przy dłuższym
 * tekście, w którym ktoś nawypiera samych „!” i „1”.
 */
function zm_text_norm_variants(string $s): array
{
    $variants = [$s];
    foreach (['@' => ['a', 'u'], '!' => ['i', 'u'], '1' => ['i', 'u']] as $ch => $replacements) {
        // PHP zamienia klucz '1' na liczbę 1, a strpos() wymaga stringa
        $ch = (string) $ch;
        if (strpos($s, $ch) === false) {
            continue;
        }
        $next = [];
        foreach ($variants as $v) {
            foreach ($replacements as $repl) {
                $next[] = str_replace($ch, $repl, $v);
            }
        }
        $next = array_slice(array_values(array_unique($next)), 0, 16);
        $variants = $next;
    }
    return $variants;
}

/**
 * Czy tekst zawiera wulgarny wyraz z listy `blocked_words`? Zwraca dopasowany
 * wyraz (do komunikatu) albo null, gdy tekst jest czysty lub lista jest pusta.
 *
 * Reguła dopasowania jest świadomie odmienna dla krótkich i długich wpisów:
 *
 *   - rdzeń 3–4 znaki → tylko na POCZĄTKU wyrazu. Dzięki temu „dup” łapie
 *     „dupa”, „dupeczka”, ale „podupadły” już nie; „cip” łapie „cipka”, ale nie
 *     „participant”.
 *   - rdzeń 5+ znaków → w dowolnym miejscu wyrazu, a także w tekście bez spacji
 *     („k u r w a” → „kurwa”). Długie rdzenie są na tyle charakterystyczne, że
 *     nie trafiają w zwykłe słowa („kurwa”, „pierdol”, „skurwysyn”).
 *
 * To kompromis: krótkie rdzenie trzeba ograniczyć do początku wyrazu, inaczej
 * filtr blokowałby poprawne słowa. Lista jest edytowalna w panelu, więc każdą
 * fałszywkę można usunąć jednym kliknięciem.
 */
function zm_text_blocked_word(PDO $pdo, string $text): ?string
{
    static $words = null;
    if ($words === null) {
        $words = [];
        foreach ($pdo->query('SELECT word FROM blocked_words')->fetchAll() as $row) {
            $w = zm_text_norm((string) ($row['word'] ?? ''));
            if ($w !== '') {
                $words[$w] = true;
            }
        }
    }
    if (!$words) {
        return null;
    }

    // Wieloznaczne znaki obfuskacji (@, !, 1) dają kilka wersji tekstu —
    // sprawdzamy je wszystkie, bo „ch1j” i „sh1t” to dwa różne wyrazy.
    $norms = [];
    foreach (zm_text_norm_variants($text) as $variant) {
        $n = zm_text_norm($variant);
        if ($n !== '') {
            $norms[$n] = true;          // klucz = wartość, więc duplikaty się zlewają
        }
    }
    if (!$norms) {
        return null;
    }

    foreach (array_keys($norms) as $norm) {
        $tokens = explode(' ', $norm);
        $flat = str_replace(' ', '', $norm);

        foreach (array_keys($words) as $w) {
            $len = strlen($w);
            if ($len >= 5) {
                foreach ($tokens as $t) {
                    if (str_contains($t, $w)) {
                        return $w;
                    }
                }
                if (str_contains($flat, $w)) {
                    return $w;
                }
            } elseif ($len >= 3) {
                foreach ($tokens as $t) {
                    if (str_starts_with($t, $w)) {
                        return $w;
                    }
                }
            } else {
                foreach ($tokens as $t) {
                    if ($t === $w) {
                        return $w;
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Własna lista zablokowanych nazw użytkownika. Administrator dodaje i usuwa
 * pozycje w panelu admina (admin.php → „Zablokowane nazwy”, API w admin-api.php).
 * Osobny znacznik — nie dotyka wcześniejszych migracji i nie kasuje niczego.
 */
function zm_ensure_blocked_usernames_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-blocked-usernames-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blocked_usernames (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            created_by VARCHAR(60) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_blocked_username (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Lista startowa wgrywana tylko wtedy, gdy tabela jest pusta — dzięki temu
    // administrator może usunąć wpis i nie wróci on przy następnym uruchomieniu.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM blocked_usernames')->fetchColumn();
    if ($count === 0) {
        $defaults = ZM_DEFAULT_BLOCKED_USERNAMES;
        $stmt = $pdo->prepare('INSERT IGNORE INTO blocked_usernames (name, created_by) VALUES (:n, NULL)');
        foreach ($defaults as $name) {
            $stmt->execute(['n' => $name]);
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_blocked_usernames_schema($pdo);

/**
 * Własna lista wyrazów blokujących komentarze (tabela `blocked_words`).
 * Administrator dodaje i usuwa pozycje w panelu admina („Wulgaryzmy”),
 * a API komentarzy odrzuca treść, która którąś z nich zawiera (comments.php →
 * zm_text_blocked_word). Osobny znacznik — nie dotyka wcześniejszych migracji.
 */
function zm_ensure_blocked_words_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-blocked-words-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blocked_words (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            word VARCHAR(60) NOT NULL,
            created_by VARCHAR(60) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_blocked_word (word)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Lista startowa wgrywana tylko wtedy, gdy tabela jest pusta — dzięki temu
    // administrator może usunąć wpis i nie wróci on przy następnym uruchomieniu.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM blocked_words')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO blocked_words (word, created_by) VALUES (:w, NULL)');
        foreach (ZM_DEFAULT_BLOCKED_WORDS as $word) {
            $stmt->execute(['w' => $word]);
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_blocked_words_schema($pdo);

/**
 * Rejestr typów powiadomień — JEDNO ŹRÓDŁO PRAWDY dla całego systemu.
 *
 * Mieszka tutaj, a nie w social-lib.php, bo migracja schematu potrzebuje go
 * już przy pierwszym uruchomieniu, zanim ktokolwiek dołączy bibliotekę. Ten sam
 * wzór co zm_comment_report_reasons() — lista zamknięta, z której biorą etykiety
 * panel, ustawienia użytkownika i walidacja po stronie serwera.
 *
 * Pola:
 *   label        etykieta dla użytkownika i w panelu admina (edytowalna)
 *   desc         opis w konfiguracji — administrator widzi, co włącza
 *   icon         nazwa ikony w interfejsie (SVG w social-lib.php)
 *   to_owner     typ dotyczy właściciela obiektu (ktoś polubił Twoje zdjęcie)
 *   to_followers typ leci do obserwujących (nowe zdjęcie od obserwowanego)
 *   to_admin     typ trafia do panelu administracyjnego (nowe zgłoszenie)
 *   user_manage  czy użytkownik może ten typ wyłączyć sam (false = tylko admin)
 *   default_on   stan domyślny po stronie użytkownika
 *
 * Zmiana wartości domyślnych po instalacji nie przepisuje istniejących wierszy
 * notification_config — administrator i tak poprawi je w panelu, a użytkownik
 * ma własny wyłącznik w notification_settings.
 */
function zm_notify_type_defs(): array
{
    return array_merge(
        zm_notify_type_defs_social(),
        zm_notify_type_defs_admin()
    );
}

/** Typy dotyczące relacji między użytkownikami i ich treści. */
function zm_notify_type_defs_social(): array
{
    return [
        'follow' => [
            'label' => 'Nowy obserwujący',
            'desc' => 'Ktoś zaczął obserwować Twój profil.',
            'icon' => 'user-plus',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => true, 'default_on' => true,
        ],
        'like' => [
            'label' => 'Polubienie zdjęcia',
            'desc' => 'Ktoś polubił zdjęcie, które dodałeś do galerii.',
            'icon' => 'heart',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => true, 'default_on' => true,
        ],
        'comment' => [
            'label' => 'Komentarz do Twojego zdjęcia',
            'desc' => 'Ktoś skomentował zdjęcie z Twojego profilu.',
            'icon' => 'comment',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => true, 'default_on' => true,
        ],
        'comment_reply' => [
            'label' => 'Odpowiedź na Twój komentarz',
            'desc' => 'Ktoś odpowiedział na Twój komentarz pod zdjęciem.',
            'icon' => 'reply',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => true, 'default_on' => true,
        ],
        /*
         * Typu `message` tu celowo NIE ma: nowa wiadomość prywatna żyje
         * wyłącznie w systemie wiadomości (skrzynka + plakietka `msgBadge`),
         * więc powiadomienie w dzwonku byłoby dublem. Stare wiersze sprząta
         * zm_drop_message_notifications().
         */
        'follow_post' => [
            'label' => 'Nowe zdjęcie od obserwowanego',
            'desc' => 'Powiadamia obserwujących o każdym nowym zatwierdzonym zdjęciu.',
            'icon' => 'camera',
            'to_owner' => false, 'to_followers' => true, 'to_admin' => false,
            'user_manage' => true, 'default_on' => true,
        ],
        'follow_comment' => [
            'label' => 'Komentarz obserwowanego',
            'desc' => 'Obserwowany użytkownik skomentował zdjęcie w galerii.',
            'icon' => 'comment',
            'to_owner' => false, 'to_followers' => true, 'to_admin' => false,
            'user_manage' => true, 'default_on' => false,
        ],
    ];
}

/**
 * Typy powiązane z decyzjami administracji oraz alerty dla panelu admina.
 *
 * `to_admin` oznacza, że zdarzenie zapisuje wiersz z user_id = NULL, czyli
 * „ktoś zrobił X, Ty musisz to rozpatrzyć”. Reszta typów z tej grupy informuje
 * użytkownika o decyzji, którą podjął administrator.
 */
function zm_notify_type_defs_admin(): array
{
    return [
        'moderation_approved' => [
            'label' => 'Zdjęcie zatwierdzone',
            'desc' => 'Administrator zatwierdził Twoje zgłoszenie — zdjęcie jest w galerii.',
            'icon' => 'check',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'moderation_rejected' => [
            'label' => 'Zdjęcie odrzucone',
            'desc' => 'Administrator odrzucił zgłoszenie — powód trafia do powiadomienia.',
            'icon' => 'cross',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'category_request_resolved' => [
            'label' => 'Wniosek o kategorię rozpatrzony',
            'desc' => 'Administrator rozpatrzył Twój wniosek o kategorię lub podkategorię.',
            'icon' => 'check',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'attraction_featured' => [
            'label' => 'Atrakcja turystyczna',
            'desc' => 'Twoje zdjęcie zostało oznaczone jako atrakcja turystyczna (z bonusem punktów).',
            'icon' => 'star',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'rank_changed' => [
            'label' => 'Zmiana rangi lub punktów',
            'desc' => 'Administrator nadał lub odjął punkty, zmienił rangę albo zablokował konto.',
            'icon' => 'star',
            'to_owner' => true, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_new_user' => [
            'label' => 'Nowe konto',
            'desc' => 'Ktoś zarejestrował się na portalu.',
            'icon' => 'user-plus',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => true,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_new_submission' => [
            'label' => 'Nowe zgłoszenie zdjęcia',
            'desc' => 'Użytkownik przysłał zdjęcie do galerii i czeka na moderację.',
            'icon' => 'camera',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_new_category_request' => [
            'label' => 'Nowy wniosek o kategorię',
            'desc' => 'Użytkownik zaproponował nową kategorię lub podkategorię.',
            'icon' => 'flag',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_comment_report' => [
            'label' => 'Zgłoszony komentarz',
            'desc' => 'Ktoś zgłosił komentarz do moderacji.',
            'icon' => 'flag',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => true,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_photo_report' => [
            'label' => 'Zgłoszone zdjęcie',
            'desc' => 'Ktoś zgłosił zdjęcie do moderacji.',
            'icon' => 'flag',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => true,
            'user_manage' => false, 'default_on' => true,
        ],
        'admin_broadcast' => [
            'label' => 'Ogłoszenie administracji',
            'desc' => 'Ogłoszenie wysłane ręcznie do wszystkich użytkowników.',
            'icon' => 'megaphone',
            'to_owner' => false, 'to_followers' => false, 'to_admin' => false,
            'user_manage' => false, 'default_on' => true,
        ],
    ];
}

/**
 * Wgrywa startowy zestaw konfiguracji typów — RAZ i tylko gdy typu nie ma w
 * tabeli (ta sama zasada co blocked_usernames / blocked_words w tym pliku).
 * Dzięki temu powtórzenie importu nie przywraca wyłączonych ani usuniętych
 * ustawień, a typy dodane w nowszej wersji dochodzą same.
 */
function zm_ensure_social_seed(PDO $pdo): void
{
    $have = [];
    try {
        foreach ($pdo->query('SELECT type FROM notification_config')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $have[(string) $t] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO notification_config (type, label, enabled, to_owner, to_followers, to_admin)
         VALUES (:t, :l, :e, :o, :f, :a)'
    );
    foreach (zm_notify_type_defs() as $type => $def) {
        if (isset($have[$type])) {
            continue;
        }
        $ins->execute([
            't' => $type,
            'l' => (string) $def['label'],
            'e' => !empty($def['default_on']) ? 1 : 0,
            'o' => !empty($def['to_owner']) ? 1 : 0,
            'f' => !empty($def['to_followers']) ? 1 : 0,
            'a' => !empty($def['to_admin']) ? 1 : 0,
        ]);
    }
}

/**
 * Funkcje społecznościowe: obserwowanie, blokowanie, wiadomości prywatne
 * i powiadomienia (dla użytkowników i dla administracji).
 *
 * Osobny znacznik, bo zm_ensure_schema() jest bramkowane przez `.schema-v7`
 * i na istniejących instalacjach nie wróci. Migracja wyłącznie DODAJĄCA —
 * nie rusza żadnej dotychczasowej tabeli ani kolumny.
 *
 *  - user_follows            kto obserwuje kogo (jednokierunkowo)
 *  - user_blocks             blokada: nie obserwujesz, nie piszesz, nie dostajesz
 *  - messages                wiadomości prywatne (skrzynka + wątek)
 *  - notifications           powiadomienia; user_id NULL = alert dla administracji
 *  - notification_settings   wyłączniki per użytkownik per typ
 *  - notification_config     konfiguracja globalna typu (edytowana w panelu)
 *
 * created_at jest tu BIGINT w milisekundach (jak comments.created_at), bo
 * powiadomienia powstają w trakcie żądania i nie chcemy zależeć od strefy
 * czasowej bazy.
 */
function zm_ensure_social_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-social-v1';
    if (is_file($marker)) {
        // Seed także na istniejących instalacjach: typy powiadomień dodane w
        // nowszej wersji muszą dojść do notification_config. INSERT IGNORE —
        // idempotentne, nie nadpisuje ustawień edytowanych w panelu.
        zm_ensure_social_seed($pdo);
        return;
    }

    // Obserwowanie. Klucz (follower_id, followee_id) = jedna osoba nie obserwuje
    // tej samej drugiej dwukrotnie. Indeks na followee_id obsługuje listę
    // „obserwujących” oraz rozfanie powiadomień do obserwujących.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_follows (
            follower_id INT UNSIGNED NOT NULL,
            followee_id INT UNSIGNED NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (follower_id, followee_id),
            KEY idx_follows_followee (followee_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Blokada. Trzymamy jeden kierunek — ten, który wskazał właściciel konta;
    // sprawdzamy go w obie strony przy każdej akcji.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_blocks (
            blocker_id INT UNSIGNED NOT NULL,
            blocked_id INT UNSIGNED NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (blocker_id, blocked_id),
            KEY idx_blocks_blocked (blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Wiadomości prywatne. Jeden wątek = para użytkowników, więc nie osobna
    // tabela rozmów — para wynika z (sender_id, recipient_id). Dwa indeksy:
    // jeden na skrzynkę (co nieprzeczytane), drugi na wątek w obie strony.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            body MEDIUMTEXT NOT NULL,
            created_at BIGINT NOT NULL,
            read_at BIGINT NULL,
            KEY idx_msg_inbox (recipient_id, read_at, id),
            KEY idx_msg_pair (sender_id, recipient_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    /*
     * Typ `messages.body` POPRAWIA funkcja zm_ensure_message_body_schema() —
     * nie ta. Ta kończy się na znaczniku `.schema-social-v1`, który na istniejących
     * instalacjach już leży, więc ALTER umieszczony tu nigdy by nie pobiegł.
     */

    /*
     * Powiadomienia — jedna tabela dla obu odbiorców.
     *
     * user_id = NULL oznacza „alert dla administracji”: taki wiersz nie ma
     * jednego właściciela, pokazuje się w panelu admina i znika po rozpatrzeniu
     * albo po wyłączeniu typu w konfiguracji. Dzięki temu panel nie potrzebuje
     * drugiej tabeli, a wyłącznik typu działa tak samo dla obu stron.
     */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            actor_id INT UNSIGNED NULL,
            type VARCHAR(40) NOT NULL,
            ref_type VARCHAR(20) NULL,
            ref_id BIGINT UNSIGNED NULL,
            body VARCHAR(300) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            KEY idx_notif_user (user_id, is_read, id),
            KEY idx_notif_admin (is_read, id),
            KEY idx_notif_type (type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Wyłączniki per użytkownik. Brak wiersza = korzystamy z konfiguracji
    // globalnej (notification_config), więc administrator nie musi pisać
    // miliona wierszy, żeby ustawić domyślne zachowanie.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_settings (
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Konfiguracja globalna typu — to edytuje administrator w panelu
    // („Powiadomienia → Konfiguracja”). Kolumny to_* mówią, komu typ ma trafić.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_config (
            type VARCHAR(40) NOT NULL PRIMARY KEY,
            label VARCHAR(80) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            to_owner TINYINT(1) NOT NULL DEFAULT 0,
            to_followers TINYINT(1) NOT NULL DEFAULT 0,
            to_admin TINYINT(1) NOT NULL DEFAULT 0,
            updated_at BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    zm_ensure_social_seed($pdo);

    // Oczyszczenie sierot: powiadomienia i wiadomości do nieistniejących kont.
    // Kaskad na kluczach obcych celowo nie zakładamy — przy kasowaniu konta
    // przez panel użytkownika wiersze mają zniknąć, a nie blokować DELETE.
    foreach ([
        'DELETE n FROM notifications n
           LEFT JOIN users u ON u.id = n.user_id
          WHERE n.user_id IS NOT NULL AND u.id IS NULL',
        'DELETE s FROM notification_settings s
           LEFT JOIN users u ON u.id = s.user_id
          WHERE u.id IS NULL',
        'DELETE m FROM messages m
           LEFT JOIN users a ON a.id = m.sender_id
           LEFT JOIN users b ON b.id = m.recipient_id
          WHERE a.id IS NULL OR b.id IS NULL',
        'DELETE f FROM user_follows f
           LEFT JOIN users a ON a.id = f.follower_id
           LEFT JOIN users b ON b.id = f.followee_id
          WHERE a.id IS NULL OR b.id IS NULL',
        'DELETE b FROM user_blocks b
           LEFT JOIN users a ON a.id = b.blocker_id
           LEFT JOIN users c ON c.id = b.blocked_id
          WHERE a.id IS NULL OR c.id IS NULL',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // brak tabeli / baza chwilowo zajęta — nie blokujemy strony
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_social_schema($pdo);

/**
 * Rozszerzenie wiadomości prywatnych: wskaźnik „pisze” i tabele pomocnicze.
 *
 * Osobny znacznik, bo `.schema-social-v1` jest już zapisany na istniejących
 * instalacjach i ta funkcja nigdy by się nie wykonała.
 *
 * `message_typing` trzyma JEDEN wiersz na parę (kto, do kogo) z czasem
 * ostatniej aktywności. Wiersz nie znaczy „pisze wiecznie” — jest ważny tylko
 * przez kilka sekund (ZM_TYPING_TTL_MS w social-lib.php). Dzięki temu nie
 * trzeba sprzątać na timerze: zapytanie po prostu porównuje czas.
 *
 * Dlaczego tabela, a nie sesja: druga osoba ma inną sesję, a pisanie ma być
 * widoczne dla niej w czasie rzeczywistym.
 */
function zm_ensure_messaging_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-messaging-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS message_typing (
            user_id INT UNSIGNED NOT NULL,
            partner_id INT UNSIGNED NOT NULL,
            updated_at BIGINT NOT NULL,
            PRIMARY KEY (user_id, partner_id),
            KEY idx_typing_partner (partner_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Pojedyncze starsze wiersze (gdyby ktoś kiedyś zmienił ważność) czyścimy
    // raz przy migracji; potem zapis nadpisuje ten sam klucz.
    try {
        $stale = (int) round(microtime(true) * 1000) - 60000;
        $pdo->prepare('DELETE FROM message_typing WHERE updated_at < :t')->execute(['t' => $stale]);
    } catch (Throwable $e) {
        // nieudane czyszczenie nie może zatrzymać migracji
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_messaging_schema($pdo);

/**
 * `messages.body`: TEXT -> MEDIUMTEXT.
 *
 * `CREATE TABLE IF NOT EXISTS` NIE zmienia typu już istniejącej kolumny,
 * więc na instalacjach, które mają stare `TEXT`, trzeba to zrobić wprost.
 *
 * Po co: znieśliśmy limit długości wiadomości, a `TEXT` mieści 65 535 bajtów.
 * Przy `PDO::ATTR_EMULATE_PREPARES => false` zbyt długi parametr wywala
 * zapytanie błędem PDO, a nie czytelnym komunikatem — czyli „wolność”
 * skończyłaby się błędem serwera. MEDIUMTEXT daje ~16 MB.
 *
 * Własny znacznik, a nie ALTER w środku zm_ensure_social_schema(): ta tamta
 * jest bramkowana przez `.schema-social-v1`, który na istniejących bazach już
 * leży, więc ALTER po prostu by nie pobiegł (dokładnie tak było, zanim to
 * przenieśliśmy tutaj). Świeża instalacja dostaje MEDIUMTEXT od razu z `CREATE`,
 * więc wtedy warunek `=== 'text'` jest fałszem i ALTER jest zbędny.
 */
function zm_ensure_message_body_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-msg-body-v1';
    if (is_file($marker)) {
        return;
    }

    $bodyType = $pdo->query(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'messages'
            AND COLUMN_NAME = 'body'"
    )->fetchColumn();
    if (strtolower((string) $bodyType) === 'text') {
        $pdo->exec('ALTER TABLE messages MODIFY body MEDIUMTEXT NOT NULL');
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_message_body_schema($pdo);

/**
 * Sprzątanie po porzuceniu typu powiadomienia `message`.
 *
 * Wiadomość prywatna nie trafia już do dzwonka (patrz zm_message_send w
 * social-lib.php) — zostaje wyłącznie w systemie wiadomości. Kasujemy więc
 * wszystko, co po tym typie zostało: gotowe powiadomienia, użytkownickie
 * wyłączniki i wiersz konfiguracji. Ostatni delete jest istotny dla testów
 * (tools/test-social.php): rejestr typów i tabela notification_config muszą
 * się zgadzać co do jednego wiersza.
 *
 * Własny znacznik, bo to migracja jednorazowa — ma pobiec raz, a nie przy
 * każdym żądaniu.
 */
function zm_drop_message_notifications(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.notif-drop-message-v1';
    if (is_file($marker)) {
        return;
    }

    foreach ([
        'DELETE FROM notifications WHERE type = :t',
        'DELETE FROM notification_settings WHERE type = :t',
        'DELETE FROM notification_config WHERE type = :t',
    ] as $sql) {
        try {
            $pdo->prepare($sql)->execute(['t' => 'message']);
        } catch (Throwable $e) {
            // brak tabeli na świeżej instalacji nie może zatrzymać strony
        }
    }

    @file_put_contents($marker, (string) time());
}

zm_drop_message_notifications($pdo);

/**
 * Obecność i własny status.
 *
 * `last_seen` = ms znacznika ostatniej aktywności (zapisywany rzadko przez
 * zm_presence_touch(), patrz social-lib.php). „Online" znaczy: aktywność nie
 * starsza niż ZM_PRESENCE_TTL_MS. `status` to krótka, edytowalna linijka
 * (np. „zajęty, po pracy") widoczna na profilu i w rozmowach.
 *
 * Własny znacznik, bo podbijanie tego pierwszego kazałoby od nowa przebiegać
 * ALTER-om, które już są — a ich warunki i tak by je pominęły.
 */
function zm_ensure_presence_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-presence-v1';
    if (is_file($marker)) {
        return;
    }

    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $have[$c['Field']] = true;
    }
    if (empty($have['last_seen'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen BIGINT NULL DEFAULT NULL');
    }
    if (empty($have['status'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN status VARCHAR(120) NULL DEFAULT NULL');
    }

    @file_put_contents($marker, (string) time());
}

/**
 * Ręczne sterowanie widocznością obecności.
 *
 * `users.show_online` = przełącznik „Jestem aktywny" w panelu konta. Gdy jest
 * wyłączony, zielona kropka nie pojawia się NIGDY — nawet jeśli użytkownik
 * właśnie korzysta ze strony. Tekstowy `users.status` został porzucony, więc
 * obecność to teraz wyłącznie automatyczny znacznik `last_seen` + ten wyłącznik.
 */
function zm_ensure_presence_vis_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-presence-vis-v1';
    if (is_file($marker)) {
        return;
    }

    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $have[$c['Field']] = true;
    }
    if (empty($have['show_online'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN show_online TINYINT(1) NOT NULL DEFAULT 1');
    }

    @file_put_contents($marker, (string) time());
}

/**
 * Podgląd linków w kompozytorze wiadomości.
 *
 * `link_previews` to cache pobranych tytułów/opisów/obrazków dla wklejonego
 * linka. `is_error` zaznacza, że strony nie dało się odczytać — żeby nie
 * odpytywać jej przy każdym naciśnięciu klawisza. `checked_at` (ms) pozwala
 * odświeżyć dane po dłuższym czasie (zwykle 60 min).
 */
function zm_ensure_link_preview_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-linkpreview-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS link_previews (
            url_hash CHAR(40) NOT NULL PRIMARY KEY,
            url VARCHAR(700) NOT NULL,
            title VARCHAR(300) NULL,
            description VARCHAR(500) NULL,
            image VARCHAR(700) NULL,
            is_error TINYINT(1) NOT NULL DEFAULT 0,
            checked_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    @file_put_contents($marker, (string) time());
}

zm_ensure_presence_schema($pdo);
zm_ensure_presence_vis_schema($pdo);
zm_ensure_link_preview_schema($pdo);

// na końcu: wszystkie tabele już istnieją, więc naprawa powiązań ze zdjęciami
// nie wykonuje się na pustej bazie (gdyby nie mogła, spróbowałaby przy
// kolejnym wywołaniu — znacznik zapisujemy dopiero po udanym przebiegu)
zm_reconcile_photo_refs($pdo);

// ---------------------------------------------------------------------------
// Lokalizacja zdjęcia: link do miejsca z Google Maps -> latitude/longitude
// ---------------------------------------------------------------------------
//
// ZMIANA KONTRaktu: pole w formularzu (panel.php, admin.php) to już nie
// "szerokość, długość", tylko link do Google Maps. Współrzędne wyciągamy po
// stronie serwera i zapisujemy dokładnie tam, gdzie wcześniej — w kolumnach
// photos.lat / photos.lng (DECIMAL(10,7)). Schemat i sposób przechowywania
// bez zmian.
//
// Google jest WYŁĄCZNIE źródłem linku wklejonego przez człowieka oraz zwykłym
// linkiem zewnętrznym pod mapą. Żadnego Google Maps API, Gemini, klucza API,
// osadzonej mapy ani Street View — to zwykłe przetworzenie tekstu (wyjątek:
// maps.app.goo.gl, gdzie musimy rozwinąć skrót zwykłym żądaniem HTTP).

/**
 * Czy host to domena Map Google?
 *
 * Świadomie wąska lista: maps.google.pl, www.google.com, google.pl oraz
 * skróty goo.gl. Dzięki temu link do innej mapy (albo dowolna strona
 * z "1,2" w zapytaniu) nigdy nie zostanie uznany za lokalizację zdjęcia.
 * Ścieżkę /maps sprawdza osobno zm_google_maps_path().
 */
function zm_google_maps_host(string $host): bool
{
    $host = strtolower(rtrim(trim($host), '.'));

    // maps.google.pl, www.google.com/maps, maps.app.goo.gl, goo.gl …
    return (bool) preg_match(
        '~^(?:[a-z0-9-]+\.)*(?:maps\.google\.[a-z.]+|google\.[a-z]{2,3}(?:\.[a-z]{2})?|goo\.gl)$~',
        $host
    );
}

/**
 * Czy w URL jest ścieżka Map Google (/maps, /maps/place/…, /maps/search/…).
 * Dla domen typu google.com samo "/maps" w ścieżce wystarcza — dzięki temu
 * link do newsów Google z "1,2" w zapytaniu nie zostanie uznany za mapę.
 * Wyjątek: maps.google.pl i podobne — tam cała domena jest Mapami,
 * więc ścieżka "/" to też mapa.
 */
function zm_google_maps_path(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_starts_with($host, 'maps.google.')) {
        return true;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    return (bool) preg_match('~/maps(?:/|$|\?)~', $path);
}

/**
 * Liczba z ułamkiem dziesiętnym, z kropką lub przecinkiem.
 */
function zm_coord_number(string $raw): ?float
{
    $raw = trim(str_replace(',', '.', $raw));
    if (!preg_match('~^-?\d{1,3}(?:\.\d+)?$~', $raw)) {
        return null;
    }
    $v = (float) $raw;
    return is_finite($v) ? $v : null;
}

/**
 * Para liczb zapisana jako "szerokość,długość" (kolejność ZAWSZE lat,lng).
 *
 * Tylko kropka dziesiętna — Google tak zapisuje współrzędne, a dopuszczenie
 * przecinka czyniłoby wyrażenie niejednoznacznym (cztery liczby rozdzielone
 * przecinkami dałyby się odczytać na dwa sposoby).
 *
 * Odrzuca (0,0) — to nigdy nie jest miejsce zdjęcia, tylko brak współrzędnych.
 */
function zm_coord_pair(string $raw): ?array
{
    $raw = trim($raw);
    if (!preg_match('~^-?\d{1,3}(?:\.\d+)?\s*,\s*-?\d{1,3}(?:\.\d+)?$~', $raw)) {
        return null;
    }
    [$latRaw, $lngRaw] = array_map('trim', explode(',', $raw, 2));
    $lat = zm_coord_number($latRaw);
    $lng = zm_coord_number($lngRaw);
    if ($lat === null || $lng === null) {
        return null;
    }
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return null;
    }
    if ($lat == 0.0 && $lng == 0.0) {
        return null;
    }
    return [round($lat, 7), round($lng, 7)];
}

/**
 * Odległość w km między dwoma parami [lat,lng] (wzór haversine).
 * Służy do sanity-checku pinu !3d/!4d względem środka widoku "@".
 */
function zm_coord_distance_km(array $a, array $b): float
{
    $r = 6371.0;
    $dLat = deg2rad($b[0] - $a[0]);
    $dLng = deg2rad($b[1] - $a[1]);
    $h = sin($dLat / 2) ** 2
        + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

/**
 * Wyciąga współrzędne z URL Google Maps.
 *
 * Kolejność źródeł:
 *   1. !3d…!4d             – dokładny pin obiektu w /maps/place/… (najdokładniejszy)
 *   2. @lat,lng            – środek widoku mapy (zawsze szerokość,długość)
 *   3. /maps/search/lat,lng– współrzędne w samej ścieżce
 *   4. ll=lat,lng          – parametr linku do lokalizacji
 *   5. query=/q=           – tylko gdy wartość jest parą liczb (nazwa odpada)
 *   6. mlat/mlng           – parametry pinu
 *
 * Punkt 3 jest bardzo częsty: Google po udostępnieniu miejsca daje
 * /maps/search/51.160011,+17.121891 — bez "@" i bez parametru query.
 *
 * Pin !3d/!4d (punkt 1): w /maps/place/… Google zapisuje go parami
 * !3d<szerokość>!4d<długość> (a nie odwrotnie). Bierzemy go przed "@" tylko
 * wtedy, gdy jest komplet (oba markery), obie liczby są w dozwolonych
 * zakresach i pin NIE odbiega od środka widoku @ o więcej niż 25 km —
 * inaczej (uszkodzony/pokręcony URL) wracamy do "@". Dzięki temu link udostępniony
 * z widoku danego obiektu pinuje obiekt (nie jego środek mapy), a żaden
 * zły URL nie zapisze współrzędnych z drugiej półkuli.
 */
function zm_google_maps_coords_from_url(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return null;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    if (!zm_google_maps_host($host)) {
        return null;
    }
    // maps.app.goo.gl i goo.gl prowadzą dalej – obsłużone w osobnej funkcji
    if (preg_match('~(?:^|\.)goo\.gl$~', $host)) {
        return null;
    }
    if (!zm_google_maps_path($url)) {
        return null;
    }

    $path = (string) ($parts['path'] ?? '');
    $decodedPath = rawurldecode($path);

    // środek widoku @lat,lng – czytamy od razu, bo służy do sanity-checku pinu
    $center = null;
    if (preg_match('~@(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)~', $decodedPath, $m)) {
        $center = zm_coord_pair($m[1] . ',' . $m[2]);
    }

    // 1. !3d…!4d – dokładny pin obiektu w /maps/place/… (szerokość, długość).
    //    Bierzemy go tylko przy komplecie obu markerów i tylko gdy nie odbiega
    //    od środka widoku o więcej niż 25 km (patrz komentarz nad funkcją) —
    //    inaczej cofamy się do "@". Zapobiega to cichej zamianie kolejności
    //    w zepsutych URL-ach (dawny powód pomijania !3d/!4d).
    if (preg_match('~!3d(-?\d{1,3}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)~', $decodedPath, $m)) {
        $pin = zm_coord_pair($m[1] . ',' . $m[2]);
        if ($pin !== null && ($center === null || zm_coord_distance_km($pin, $center) <= 25.0)) {
            return $pin;
        }
    }

    // 2. @szerokość,długość – środek widoku (najczęstszy w udostępnionych linkach)
    if ($center !== null) {
        return $center;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    // 3. Współrzędne w samej ścieżce, np. /maps/search/51.160011,+17.121891.
    //    "+" to w URL zakodowana spacja, więc zamieniamy ją przed sprawdzeniem.
    //    Nazwa miejsca w /maps/place/... nigdy nie jest parą liczb, więc
    //    taki segment zostanie pominięty i nic nie zostanie nadpisane.
    foreach(explode('/', $path) as $segment){
        $pair = zm_coord_pair(rawurldecode(str_replace('+', ' ', $segment)));
        if($pair !== null){
            return $pair;
        }
    }

    // 4. ll= – para liczb
    if (isset($query['ll']) && is_string($query['ll'])) {
        $pair = zm_coord_pair($query['ll']);
        if ($pair !== null) {
            return $pair;
        }
    }

    // 5. query= / q= – tylko para liczb, nazwa miejsca nie jest lokalizacją
    foreach (['query', 'q'] as $key) {
        if (isset($query[$key]) && is_string($query[$key])) {
            $pair = zm_coord_pair($query[$key]);
            if ($pair !== null) {
                return $pair;
            }
        }
    }

    // 6. mlat + mlng – parametry pinu
    if (isset($query['mlat'], $query['mlng'])
        && is_string($query['mlat']) && is_string($query['mlng'])
    ) {
        $pair = zm_coord_pair($query['mlat'] . ',' . $query['mlng']);
        if ($pair !== null) {
            return $pair;
        }
    }

    return null;
}

/**
 * Czy host to skrót Google Maps (maps.app.goo.gl, goo.gl)?
 */
function zm_google_maps_short_link(string $url): bool
{
    $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
    return $host !== '' && (bool) preg_match('~^(?:[a-z0-9-]+\.)*goo\.gl$~', $host);
}

/**
 * Rozwija skrót maps.app.goo.gl do docelowego adresu Google Maps.
 *
 * Zwykłe przekierowanie HTTP (HEAD, potem GET), bez żadnego API i bez klucza.
 * Każdy hop jest sprawdzany: przekierowanie poza domeny Google jest odrzucane,
 * więc skrót nie może nas zabrać na dowolny adres. Limit 5 przekierowań,
 * krótki timeout, zero przesyłania danych w obie strony.
 *
 * Zwraca URL po rozwikłaniu albo null, gdy nie udało się go bezpiecznie
 * otworzyć (wtedy zgłaszamy użytkownikowi czytelny błąd).
 */
function zm_resolve_google_maps_short_link(string $url, int $maxHops = 5): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    if (!zm_google_maps_short_link($url)) {
        return null;
    }

    $current = $url;

    for ($hop = 0; $hop < $maxHops; $hop++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $current,
            CURLOPT_NOBODY => true,                       // HEAD - bez pobierania treści
            CURLOPT_FOLLOWLOCATION => false,               // przekierowania obsługujemy ręcznie
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; zmogilna/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($status >= 300 && $status < 400 && $location !== '') {
            // przekierowanie poza Google = coś, czego nie obsługujemy
            $host = strtolower((string) parse_url($location, PHP_URL_HOST));
            if ($host === '' || !preg_match('~(?:^|\.)(?:google\.[a-z.]{2,}|goo\.gl)$~', $host)) {
                return null;
            }
            $current = $location;
            continue;
        }

        // bez przekierowania: dostaliśmy już właściwy adres
        if ($status >= 200 && $status < 400) {
            return $current;
        }

        return null;
    }

    return null;
}

/**
 * Współrzędne z dowolnego linku Google Maps wklejonego w formularzu.
 *
 * Zwraca ['lat' => float, 'lng' => float] albo null, gdy z linku nie da się
 * wiarygodnie wyciągnąć współrzędnych (wtedy nic nie zapisujemy).
 */
function zm_google_maps_coords(?string $value): ?array
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (zm_google_maps_short_link($value)) {
        $resolved = zm_resolve_google_maps_short_link($value);
        if ($resolved === null) {
            return null;
        }
        $value = $resolved;
    }

    $pair = zm_google_maps_coords_from_url($value);
    if ($pair === null) {
        return null;
    }

    return ['lat' => $pair[0], 'lng' => $pair[1]];
}

/**
 * Uwaga: funkcja zm_google_maps_link() została usunięta jako martwy kod —
 * nigdzie nie była wywoływana. Wszystkie linki do Google Maps budowane są
 * bezpośrednio z pary współrzędnych (config.php → galeria, panel.php,
 * admin.php) i nigdy z tekstu strony.
 */



/**
 * Statystyki mini-gier („Zgadnij gminę", „Znajdź na mapie") — jeden wiersz
 * na ukończone zagranie. `user_id` = konto zalogowanego gracza, a gdy go brak
 * (NULL), tożsamość niesie `anon_key` — losowy token z cookie `zm_games_player`.
 * Ranking liczy najlepszy wynik na gracza, a dzienny limit pilnowany jest
 * w zm_games_save(). Osobny znacznik, niczego nie usuwa ani nie zmienia.
 */
function zm_ensure_games_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-games-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS game_scores (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            game VARCHAR(32) NOT NULL,
            user_id INT UNSIGNED NULL,
            anon_key VARCHAR(64) NULL,
            score INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_game_scores_rank (game, score),
            KEY idx_game_scores_user (game, user_id, created_at),
            KEY idx_game_scores_anon (game, anon_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec(
            'ALTER TABLE game_scores
             ADD CONSTRAINT fk_game_scores_user FOREIGN KEY (user_id)
                 REFERENCES users(id) ON DELETE SET NULL'
        );
    } catch (Throwable $e) {
        // brak FK nie jest krytyczny — zapis i ranking działają po user_id
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_games_schema($pdo);

/**
 * Projekty robocze w panelu administratora — metasystem „Projekty”.
 *
 * Pozwala prowadzić listy zadań dla kolejnych przedsięwzięć (np. „Atrakcja
 * turystyczna”), opisanych i opatrzonych gotowymi promptami do skopiowania.
 * Przy pierwszej migracji zakładany jest projekt „Atrakcja turystyczna”.
 * Osobny znacznik — nic nie usuwa ani nie zmienia w istniejących danych.
 */
function zm_ensure_projects_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-projects-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_projects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            description TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            UNIQUE KEY uq_admin_projects_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_project_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'todo',
            priority TINYINT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            done_at BIGINT NULL,
            KEY idx_apt_project (project_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_project_prompts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id INT UNSIGNED NOT NULL,
            label VARCHAR(160) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            KEY idx_app_task (task_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec(
            'ALTER TABLE admin_project_tasks
             ADD CONSTRAINT fk_apt_project FOREIGN KEY (project_id)
                 REFERENCES admin_projects(id) ON DELETE CASCADE'
        );
    } catch (Throwable $e) {
        // brak FK nie jest krytyczny — kaskadę obsługujemy też w PHP
    }

    try {
        $pdo->exec(
            'ALTER TABLE admin_project_prompts
             ADD CONSTRAINT fk_app_task FOREIGN KEY (task_id)
                 REFERENCES admin_project_tasks(id) ON DELETE CASCADE'
        );
    } catch (Throwable $e) {
        // jw.
    }

    zm_seed_atrakcja_turystyczna_project($pdo);

    @file_put_contents($marker, (string) time());
}

/**
 * Zakłada projekt „Atrakcja turystyczna” wraz z listą zadań i promptami —
 * wyłącznie, gdy w bazie nie ma jeszcze żadnego projektu (świeża instalacja).
 */
function zm_seed_atrakcja_turystyczna_project(PDO $pdo): void
{
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_projects')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }

    if ($count > 0) {
        return;
    }

    $now = (int) round(microtime(true) * 1000);

    $pdo->prepare(
        'INSERT INTO admin_projects (name, slug, description, status, sort_order, created_at, updated_at)
         VALUES (:n, :s, :d, :st, 0, :created, :updated)'
    )->execute([
        'n' => 'Atrakcja turystyczna',
        's' => 'atrakcja-turystyczna',
        'd' => 'System oznaczania zdjęć jako atrakcje turystyczne („atrakcja turystyczna”, '
             . '„warto odwiedzić”). Oznaczenia nadaje zgłaszający podczas dodawania zdjęcia, '
             . 'a administrator zmienia je w moderacji i w edycji. Główny element strony '
             . 'głównej, w pełni konfigurowalny, zachęcający odwiedzających do powrotu.',
        'st' => 'active',
        'created' => $now,
        'updated' => $now,
    ]);

    $projectId = (int) $pdo->lastInsertId();
    if ($projectId <= 0) {
        return;
    }

    $taskStmt = $pdo->prepare(
        'INSERT INTO admin_project_tasks
            (project_id, title, description, status, priority, sort_order, created_at)
         VALUES (:p, :t, :d, :s, :pr, :so, :c)'
    );
    $promptStmt = $pdo->prepare(
        'INSERT INTO admin_project_prompts
            (task_id, label, body, sort_order, created_at, updated_at)
         VALUES (:t, :l, :b, :so, :created, :updated)'
    );

    $order = 0;
    foreach (zm_atrakcja_seed_tasks() as $task) {
        $order++;

        $taskStmt->execute([
            'p' => $projectId,
            't' => $task['title'],
            'd' => $task['description'],
            's' => 'todo',
            'pr' => (int) ($task['priority'] ?? 0),
            'so' => $order,
            'c' => $now,
        ]);

        $taskId = (int) $pdo->lastInsertId();
        if ($taskId <= 0) {
            continue;
        }

        $porder = 0;
        foreach ($task['prompts'] as $prompt) {
            $porder++;
            $promptStmt->execute([
                't' => $taskId,
                'l' => $prompt['label'],
                'b' => $prompt['body'],
                'so' => $porder,
                'created' => $now,
                'updated' => $now,
            ]);
        }
    }
}

/**
 * Treść startowa projektu „Atrakcja turystyczna”: 8 zadań w kolejności
 * realizacji. Każde zadanie ma opis funkcji oraz gotowy, edytowalny prompt
 * do skopiowania (np. do agenta AI). Prompty opisują konwencje tego projektu.
 *
 * @return array<int, array{title:string, description:string, priority:int, prompts:array<int, array{label:string, body:string}>}>
 */
function zm_atrakcja_seed_tasks(): array
{
    return [
        [
            'title' => '1. Oznaczenia atrakcji w bazie danych',
            'description' => 'Tabela photo_attractions (wiele etykiet na zdjęcie) + migracja '
                . 'w config.php ze znacznikiem .schema-attraction-v1. Etykiety pochodzą '
                . 'z pliku konfiguracji atrakcji.',
            'priority' => 5,
            'prompts' => [
                [
                    'label' => 'Implementacja (DB + migracja)',
                    'body' => <<<'TXT'
Pracujesz nad projektem PHP (bez frameworka) w katalogu C:\laragon\www. Baza: MySQL przez PDO
(zmienna globalna $pdo). Migracje uruchamiają się same w config.php — wzorzec: funkcja
zm_ensure_*_schema() ze znacznikiem w CACHE_DIR (uploads/cache/.schema-*). Wszystkie komentarze
i teksty interfejsu po polsku.

ZADANIE: umożliw oznaczanie zdjęć wieloma etykietami atrakcji turystycznych.

1. W config.php dodaj funkcję zm_ensure_photo_attraction_schema(PDO $pdo) wzorowaną na
   zm_ensure_games_schema(). Znacznik: CACHE_DIR . '/.schema-attraction-v1'. Wywołaj ją na końcu
   pliku obok pozostałych wywołań (tylko ta funkcja — nie zmieniaj istniejących migracji).
2. Utwórz tabelę:
   CREATE TABLE IF NOT EXISTS photo_attractions (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     photo_id INT UNSIGNED NOT NULL,
     label VARCHAR(60) NOT NULL,
     note VARCHAR(255) NULL,
     added_by_user_id INT UNSIGNED NULL,
     created_at BIGINT NOT NULL,
     UNIQUE KEY uq_photo_attraction (photo_id, label),
     KEY idx_photo_attractions_label (label)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
   W try/catch dodaj FK photo_id -> photos(id) ON DELETE CASCADE (wzorzec jak w zm_ensure_games_schema).
3. Zaktualizuj schema.sql (ręczna kopia schematu), dopisując nową tabelę.
4. Nie zmieniaj niczego w istniejących zapytaniach i widokach — to zadanie tylko dodaje warstwę danych.

Kryteria akceptacji: migracja idempotentna (drugie uruchomienie nic nie robi), `php -l config.php`
bez błędów, istniejące strony działają bez zmian. Zgłoś, które pliki zmieniłeś (ścieżka:linia).
TXT,
                ],
            ],
        ],
        [
            'title' => '2. Zgłaszanie oznaczenia przy dodawaniu zdjęcia',
            'description' => 'Osoba dodająca zdjęcie może zaznaczyć etykiety atrakcji '
                . '(konfigurowalne, np. „atrakcja turystyczna”, „warto odwiedzić”). Zapis do '
                . 'photo_attractions razem ze zdjęciem.',
            'priority' => 4,
            'prompts' => [
                [
                    'label' => 'Implementacja (formularz + API zgłoszenia)',
                    'body' => <<<'TXT'
Kontekst: projekt PHP w C:\laragon\www (bez frameworka, PDO). Zgłaszanie zdjęć przez
użytkowników obsługuje submit-photo-api.php; dodawanie zdjęcia przez admina — akcja
photo_upload w admin-api.php. Tabela photo_attractions istnieje (photo_id, label, note,
added_by_user_id, created_at). Lista dostępnych etykiet będzie w libce atrakcji (Etap 5);
na razie użyj sensownego zbioru domyślnego zdefiniowanego w jednym miejscu.

ZADANIE: pozwól osobie dodającej zdjęcie zaznaczyć etykiety atrakcji.

1. W submit-photo-api.php: przyjmij pole labels (tablica/CSV etykiet) i note; zwaliduj etykiety
   względem dozwolonego zbioru; po zapisie zdjęcia zapisz wiersze do photo_attractions
   (added_by_user_id = zalogowany użytkownik, a dla gościa NULL). Użyj transakcji.
2. W interfejsie dodawania zdjęcia (index.php — formularz zgłoszenia) dodaj sekcję
   „Ta fotografia to atrakcja turystyczna?” z checkboxami etykiet i opcjonalną notatką.
3. Zachowaj istniejące zachowanie i styl (klasy CSS z index.php, teksty po polsku).
4. Nie pokazuj jeszcze oznaczeń publicznie — to tylko zapis (publiczna prezentacja w Etapie 6).

Kryteria akceptacji: zdjęcie zapisuje się razem z wybranymi etykietami, pusta lista = brak
oznaczeń, walidacja odrzuca nieznane etykiety, `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '3. Moderacja oznaczeń w panelu',
            'description' => 'W widoku moderacji zdjęć administrator widzi zaproponowane '
                . 'etykiety atrakcji i może je zatwierdzić, usunąć lub zmienić przed publikacją.',
            'priority' => 4,
            'prompts' => [
                [
                    'label' => 'Implementacja (moderacja zdjęć)',
                    'body' => <<<'TXT'
Kontekst: panel admina to admin.php (HTML + inline JS, zakładki przez switchView()), API to
admin-api.php (liniowy dispatch: GET na ~linia 957, POST na ~linia 1740; helper zm_fail;
CSRF globalnie). Moderacja zdjęć: zm_admin_approve_photo() / zm_admin_reject_photo() oraz akcje
photo_moderate i photo_moderate_bulk. Tabela photo_attractions istnieje.

ZADANIE: pokaż i pozwól zmieniać etykiety atrakcji w trakcie moderacji.

1. W zm_admin_approve_photo() (admin-api.php) i w payloadzie listy zgłoszeń (photo_list) dołącz
   etykiety atrakcji dla zdjęcia (SELECT z photo_attractions).
2. W oknie moderacji w admin.php (moduł window.zmPhotos, okolice revPreview/gminaBackdrop) pokaż
   chipy z etykietami i pozwól je usunąć/dodać przed zatwierdzeniem.
3. Rozszerz akcję photo_moderate (i bulk) o opcjonalne pole labels — po decyzji zapis/zaktualizuj
   photo_attractions. Zapis w transakcji, walidacja względem dozwolonych etykiet.
4. Zachowaj dotychczasowy wygląd (klasy .chip/.badge z admin.php) i logowanie przez zm_log().

Kryteria akceptacji: moderator widzi zgłoszone etykiety, może je zmienić i zatwierdzić razem
z decyzją; `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '4. Edycja oznaczeń przy zdjęciu w panelu',
            'description' => 'W oknie „Edytuj zdjęcie” administrator może w każdej chwili '
                . 'dodać/zmienić/usunąć etykiety atrakcji (akcja photo_update).',
            'priority' => 3,
            'prompts' => [
                [
                    'label' => 'Implementacja (edycja zdjęcia)',
                    'body' => <<<'TXT'
Kontekst: admin.php + admin-api.php w C:\laragon\www. Edycja zdjęcia: okno phEditBackdrop
(id phEdTitle, phEdDesc, phEdCategory...) i akcja photo_update (admin-api.php ~linia 2202).

ZADANIE: dodaj zarządzanie etykietami atrakcji w edycji zdjęcia.

1. W oknie edycji (admin.php) dodaj sekcję „Atrakcje”: checkboxy dozwolonych etykiet + pole
   notatki. Wypełnij ją aktualnymi danymi zdjęcia (dane dołącz w photo_list).
2. Rozszerz akcję photo_update o pole labels (i note) — po UPDATE photos zaktualizuj
   photo_attractions dla tego photo_id (usuń nieodznaczone, dodaj nowe). Transakcja.
3. Zwracaj zaktualizowane etykiety w odpowiedzi, aby UI odświeżyło się bez przeładowania.
4. Zachowaj styl i logowanie (zm_log).

Kryteria akceptacji: zmiany etykiet zapisują się i natychmiast widać je po ponownym otwarciu
edycji; `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '5. Konfiguracja atrakcji (attraction-lib.php + zakładka)',
            'description' => 'W pełni konfigurowalny system: definicja etykiet, tytuł i podtytuł '
                . 'sekcji, liczba zdjęć, tryb sortowania, interwał karuzeli. Wzorzec jak '
                . 'security-lib.php (defaults + merge + clamp + atomowy zapis JSON).',
            'priority' => 4,
            'prompts' => [
                [
                    'label' => 'Implementacja (lib + API + zakładka admina)',
                    'body' => <<<'TXT'
Kontekst: w C:\laragon\www konfiguracja edytowalna jest wzorcem z security-lib.php:
zm_security_defaults() (defaults), zm_security_config() (merge), zm_security_clamp()
(bounds), zm_security_config_save() (whitelist + atomowy zapis JSON), endpoint security_save
w admin-api.php. Panel admina: admin.php — dodanie zakładki wymaga: przycisku w .admin-toolbar,
<div id="xView">, wpisu w switchView() oraz modułu <script> window.zmX {start,stop,refresh}.

ZADANIE: stwórz attraction-lib.php z konfiguracją atrakcji i zakładkę „Atrakcje” w adminie.

1. attraction-lib.php wg wzorca security-lib.php. Plik: CACHE_DIR . '/.attraction-config.json'.
   Pola domyślne: labels (lista etykiet: klucz + nazwa + domyślne zaznaczenie), section_title,
   section_subtitle, count (liczba zdjęć na stronie głównej), mode ('latest'|'random'|'top'),
   autoplay_seconds, show_on_home (bool), enable_submission (bool). Funkcje:
   zm_attraction_defaults(), zm_attraction_config(), zm_attraction_clamp(), zm_attraction_config_save().
2. W admin-api.php dodaj GET attraction_config oraz POST attraction_save (whitelist kluczy,
   clamp, log przez zm_log).
3. W admin.php dodaj zakładkę „Atrakcje” (przycisk + #attractionView + switchView + moduł
   window.zmAttraction) z formularzem: edytor listy etykiet (dodaj/usuń/zmień nazwę/domyślne),
   tytuł, podtytuł, liczba, tryb, autoplay, przełączniki. Zapisz przyciskiem.
4. Wszystkie etykiety używane w Etapach 1–4 mają pochodzić z tej konfiguracji.

Kryteria akceptacji: zmiana konfiguracji zapisuje się do JSON i jest odczytywana przez inne
moduły; walidacja/clamp chroni przed błędnymi wartościami; `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '6. Blok „Atrakcje turystyczne” na stronie głównej',
            'description' => 'Najważniejszy element strony głównej: dedykowana sekcja tuż pod hero '
                . 'z karuzelą/lista wyróżnionych atrakcji, w pełni sterowana konfiguracją.',
            'priority' => 5,
            'prompts' => [
                [
                    'label' => 'Implementacja (sekcja + live-refresh)',
                    'body' => <<<'TXT'
Kontekst: strona główna to index.php w C:\laragon\www (jeden duży plik, sekcje w <div class="wrap">,
styl .featured-block już istnieje — patrz renderFeatured()). Wzorzec „sekcji na żywo”:
funkcja danych w *-lib.php + partial PHP + *-api.php zwracający HTML + podmiana po data-hash
(patrz community-lib.php + community-section.php + community-api.php). Konfiguracja atrakcji:
zm_attraction_config() z attraction-lib.php (Etap 5). Zdjęcia: gallery.php?ids=... oraz
zm_find_photo(); miniatury: zm_thumb_url().

ZADANIE: dodaj główną sekcję „Atrakcje turystyczne” na stronie głównej.

1. W attraction-lib.php dodaj zm_home_attractions(PDO $pdo) (lista oznaczonych, opublikowanych
   zdjęć wg konfiguracji: count, mode) oraz zm_home_attractions_hash(array).
2. Utwórz attraction-section.php (partial PHP, analogicznie do community-section.php) —
   responsywna karuzela/lista: zdjęcie, tytuł, miejsce, etykiety, CTA „Zobacz” i „Dodaj swoje
   zdjęcie”. Tytuł i podtytuł z konfiguracji. Respektuj show_on_home.
3. Wstaw sekcję w index.php tuż pod hero (przed galerią), zaraz po sekcji hero (~linia 2163).
4. Utwórz attraction-api.php (jak community-api.php) i dodaj odświeżanie po hash, żeby nowe
   atrakcje pojawiały się bez przeładowania.
5. Styl dopasuj do istniejącego (zmienne CSS --gold/--card/--line, index.php ~linia 626).

Kryteria akceptacji: sekcja pokazuje się jako najważniejszy blok pod hero, działa przy 0/1/wielu
atrakcjach, jest w pełni sterowana konfiguracją; `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '7. Mechanizmy powrotu i zaangażowania',
            'description' => '„Atrakcja tygodnia/dnia”, punkty dla zgłaszających, powiadomienia '
                . 'i wyróżnienia — tak, by system zachęcał odwiedzających do powrotu.',
            'priority' => 2,
            'prompts' => [
                [
                    'label' => 'Implementacja (punkty + wyróżnienia + powiadomienia)',
                    'body' => <<<'TXT'
Kontekst: C:\laragon\www. Punkty i rangi: zm_ensure_points_schema, historia w points_history;
przy zatwierdzeniu zdjęcia zm_admin_approve_photo() przyznaje punkty. Powiadomienia:
social-lib.php + tabela notifications/notification_config. Konfiguracja atrakcji:
zm_attraction_config() (Etap 5).

ZADANIE: dodaj mechanizmy zachęcające do powrotu wokół atrakcji.

1. „Atrakcja tygodnia”: wybierz oznaczoną atrakcję (rotacja tygodniowa, deterministyczna po
   numerze tygodnia) i wyróżnij ją w bloku na stronie głównej oraz oznacz plakietką (badge).
2. Punkty: przyznawaj bonusowe punkty zgłaszającemu, gdy jego zdjęcie zostanie oznaczone jako
   atrakcja i zatwierdzone (użyj points_history, unikaj podwójnego naliczenia).
3. Powiadomienia: gdy zdjęcie użytkownika zostanie oznaczone jako atrakcja, wyślij powiadomienie
   (typ w notification_config, sterowany z panelu). Dodaj konfigurację w zakładce Atrakcje.
4. Dodaj licznik „X nowych atrakcji w tym tygodniu” widoczny na stronie głównej.

Kryteria akceptacji: wyróżnienie zmienia się raz w tygodniu, punkty naliczają się jednokrotnie,
powiadomienie dochodzi do autora; wszystko sterowane konfiguracją; `php -l` bez błędów.
TXT,
                ],
            ],
        ],
        [
            'title' => '8. Publiczny listing atrakcji',
            'description' => 'Osobny widok/lista wszystkich atrakcji z filtrowaniem po etykiecie '
                . 'i miejscowości, linkowany z bloku na stronie głównej.',
            'priority' => 2,
            'prompts' => [
                [
                    'label' => 'Implementacja (widok listy + filtr)',
                    'body' => <<<'TXT'
Kontekst: C:\laragon\www. Galeria: gallery.php (JSON: ?ids=, ?file=, ?q=) i zm_list() w
photos-lib.php. Etykiety atrakcji: photo_attractions. Konfiguracja etykiet:
zm_attraction_config(). Strona główna: index.php, blok atrakcji z Etapu 6.

ZADANIE: dodaj publiczny widok listy atrakcji.

1. Nowy endpoint (np. attraction-list-api.php) zwracający opublikowane zdjęcia-oznaczone-atrakcjami
   z filtrami: etykieta, miejscowość/gmina, stronicowanie. Wydajne zapytanie z JOIN do
   photo_attractions + indeksy.
2. Publiczna strona (np. atrakcje.php) z siatką kart atrakcji, filtrami (select etykiety, wyszukiwarka
   miejsca) i licznikiem. Link „Zobacz wszystkie atrakcje” w bloku na stronie głównej.
3. Spójny styl i responsywność z galerią (klasy i zmienne CSS z index.php). SEO: sensowne <title>,
   opis, dane OG.
4. Wyłącznie zdjęcia spełniające warunek published = 1 AND moderation_status = 'approved'.

Kryteria akceptacji: lista działa z filtrami i stronicowaniem, link zależy od konfiguracji,
`php -l` bez błędów.
TXT,
                ],
            ],
        ],
    ];
}

zm_ensure_projects_schema($pdo);

/**
 * Oznaczenia zdjęć jako atrakcje turystyczne — jedno zdjęcie może mieć
 * wiele etykiet (np. „atrakcja turystyczna”, „warto odwiedzić”). Etykiety
 * są walidowane na wyższej warstwie (lista dozwolonych pochodzi z konfiguracji
 * atrakcji); tutaj trzymamy sam zapis. Osobny znacznik — nic nie usuwa ani
 * nie zmienia w istniejących danych.
 */
function zm_ensure_photo_attraction_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-attraction-v1';
    if (is_file($marker)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS photo_attractions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            photo_id INT UNSIGNED NOT NULL,
            label VARCHAR(60) NOT NULL,
            note VARCHAR(255) NULL,
            added_by_user_id INT UNSIGNED NULL,
            created_at BIGINT NOT NULL,
            UNIQUE KEY uq_photo_attraction (photo_id, label),
            KEY idx_photo_attractions_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec(
            'ALTER TABLE photo_attractions
             ADD CONSTRAINT fk_photo_attractions_photo FOREIGN KEY (photo_id)
                 REFERENCES photos(id) ON DELETE CASCADE'
        );
    } catch (Throwable $e) {
        // brak FK nie jest krytyczny — oznaczanie i odczyt działają po photo_id
    }

    @file_put_contents($marker, (string) time());
}

zm_ensure_photo_attraction_schema($pdo);

/**
 * Notatnik administratora (lista zadań) — naprawa istniejących instalacji.
 *
 * Tabela `admin_tasks` powstaje w zm_ensure_schema(), ale ta jest bramkowana
 * znacznikiem `.schema-v7`. Na instalacjach, w których tabela została założona
 * ze starym schematem (created_at typu TIMESTAMP, bez kolumny done_at), nowy
 * kod nie mógł jej naprawić: CREATE TABLE IF NOT EXISTS nie zmienia istniejącej
 * tabeli. A Notatnik zapisuje czas w milisekundach (BIGINT), więc odczyt i zapis
 * kończyły się błędem serwera („Unknown column 'done_at'”).
 *
 * Ten znacznik doprowadza tabelę do właściwego kształtu: pustą odtwarza,
 * a niepustą uzupełnia (dokłada done_at i konwertuje created_at na milisekundy).
 */
function zm_ensure_admin_tasks_schema(PDO $pdo): void
{
    $marker = CACHE_DIR . '/.schema-admin-tasks-v1';
    if (is_file($marker)) {
        return;
    }

    $cols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM admin_tasks')->fetchAll() as $c) {
            $cols[strtolower((string) $c['Field'])] = strtolower((string) $c['Type']);
        }
    } catch (Throwable $e) {
        $cols = []; // brak tabeli — poniższy CREATE IF NOT EXISTS ją założy
    }

    $needsRebuild = $cols !== [] && (
        !isset($cols['done_at'])
        || !isset($cols['created_at'])
        || strpos($cols['created_at'], 'bigint') === false
    );

    if ($needsRebuild) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_tasks')->fetchColumn();

        if ($count === 0) {
            // Pusta tabela — najprościej odtworzyć we właściwym kształcie.
            $pdo->exec('DROP TABLE admin_tasks');
        } else {
            // Niepusta: dopełniamy brakującą kolumnę i przenosimy created_at
            // z TIMESTAMP na milisekundy (BIGINT), nie tracąc istniejących zadań.
            if (!isset($cols['done_at'])) {
                $pdo->exec('ALTER TABLE admin_tasks ADD COLUMN done_at BIGINT NULL');
            }
            if (strpos((string) ($cols['created_at'] ?? ''), 'bigint') === false) {
                $pdo->exec('ALTER TABLE admin_tasks ADD COLUMN created_at_ms BIGINT NULL');
                $pdo->exec('UPDATE admin_tasks SET created_at_ms = UNIX_TIMESTAMP(created_at) * 1000');
                $pdo->exec('ALTER TABLE admin_tasks DROP COLUMN created_at');
                $pdo->exec('ALTER TABLE admin_tasks CHANGE created_at_ms created_at BIGINT NOT NULL');
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            text TEXT NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            done_at BIGINT NULL,
            KEY idx_admin_tasks_done (done, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    @file_put_contents($marker, (string) time());
}

zm_ensure_admin_tasks_schema($pdo);




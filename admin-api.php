<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/texts-lib.php';
require_once __DIR__ . '/gsc-lib.php';

/**
 * Nazwa widoczna etykiety atrakcji na podstawie jej klucza technicznego.
 * Gdy klucza nie ma już w konfiguracji, pokazujemy sam klucz.
 */
function zm_attraction_label_name(string $key): string
{
    $all = zm_attraction_labels();
    return $all[$key] ?? $key;
}

/**
 * Normalizacja zbioru etykiet z żądania: przyjmuje tablicę kluczy albo listę
 * po przecinku, odrzuca nieznane klucze (walidacja wobec zm_attraction_labels())
 * i duplikaty. Kolejność zachowana. Limit z konfiguracji (domyślnie 2 na zdjęcie).
 */
function zm_attraction_normalize_labels($raw): array
{
    $allowed = zm_attraction_labels();
    $items = [];
    if (is_array($raw)) {
        $items = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $items = explode(',', $raw);
    }

    $out = [];
    foreach ($items as $item) {
        $key = strtolower(trim((string) $item));
        if ($key !== '' && isset($allowed[$key]) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return array_slice($out, 0, zm_attraction_max_labels());
}

/**
 * Etykiety atrakcji pojedynczego zdjęcia — lista [{label, name, note}].
 */
function zm_attraction_photo_labels(PDO $pdo, int $photoId): array
{
    if ($photoId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT label, note FROM photo_attractions WHERE photo_id = :id ORDER BY id ASC'
    );
    $stmt->execute(['id' => $photoId]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = (string) $r['label'];
        $out[] = [
            'label' => $key,
            'name' => zm_attraction_label_name($key),
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
        ];
    }
    return $out;
}

/**
 * Etykiety atrakcji dla wielu zdjęć naraz: [photo_id => [{label, name, note}]].
 * Jedno zapytanie zamiast N — używane przez listę zgłoszeń (photo_list).
 */
function zm_attraction_photos_map(PDO $pdo, array $photoIds): array
{
    $ids = [];
    foreach ($photoIds as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return [];
    }
    $ids = array_values($ids);

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT photo_id, label, note FROM photo_attractions
         WHERE photo_id IN ($marks) ORDER BY id ASC"
    );
    $stmt->execute($ids);

    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $pid = (int) $r['photo_id'];
        $key = (string) $r['label'];
        $map[$pid][] = [
            'label' => $key,
            'name' => zm_attraction_label_name($key),
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
        ];
    }
    return $map;
}

/** Usuwa zdjęcie z powiązanymi komentarzami, ulubionymi i plikami. */
function zm_admin_delete_photo(PDO $pdo, array $row, string $adminUsername): array
{
    $filename = (string) $row['filename'];
    $pid = (int) $row['id'];
    $key = 'p' . $pid;
    $removed = ['comments' => 0, 'favorites' => 0];

    try {
        $pdo->beginTransaction();
        $cidStmt = $pdo->prepare('SELECT id FROM comments WHERE photo_id = :k');
        $cidStmt->execute(['k' => $key]);
        $commentIds = array_map('intval', $cidStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($commentIds) {
            $marks = implode(',', array_fill(0, count($commentIds), '?'));
            $pdo->prepare("DELETE FROM comment_likes WHERE comment_id IN ($marks)")->execute($commentIds);
            $delCmt = $pdo->prepare("DELETE FROM comments WHERE id IN ($marks)");
            $delCmt->execute($commentIds);
            $removed['comments'] = $delCmt->rowCount();
        }
        $delFav = $pdo->prepare('DELETE FROM user_favorites WHERE photo_id = :id');
        $delFav->execute(['id' => $pid]);
        $removed['favorites'] = $delFav->rowCount();
        $pdo->prepare('DELETE FROM photos WHERE id = :id')->execute(['id' => $pid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $absolutePath = zm_photo_path($filename);
    if ($absolutePath !== null) {
        @unlink($absolutePath);
    }
    zm_clear_cache($filename);
    zm_log($pdo, 'photo_delete', $adminUsername, $key, $filename);
    zm_security_event('admin_action', 'warn', $adminUsername,
        'usunięto zdjęcie #' . $pid . ' (' . $filename . ', usunięto ' . $removed['comments'] . ' komentarzy)');
    return $removed;
}

/**
 * Ustawia etykiety atrakcji zdjęcia na podany zbiór. Usuwa etykiety spoza
 * listy, dodaje brakujące (istniejące wiersze zostają razem z notatką i autorem).
 * Zapis w transakcji. Gdy zdjęcie jest już w transakcji, tylko dokładamy zmiany.
 */
function zm_attraction_set_labels(PDO $pdo, int $photoId, array $labels, ?int $userId): void
{
    if ($photoId <= 0) {
        return;
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        if ($labels) {
            $marks = implode(',', array_fill(0, count($labels), '?'));
            $del = $pdo->prepare(
                "DELETE FROM photo_attractions WHERE photo_id = ? AND label NOT IN ($marks)"
            );
            $del->execute(array_merge([$photoId], $labels));
        } else {
            $pdo->prepare('DELETE FROM photo_attractions WHERE photo_id = ?')->execute([$photoId]);
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO photo_attractions (photo_id, label, note, added_by_user_id, created_at)
             VALUES (:photo_id, :label, :note, :user_id, :created_at)'
        );
        $now = (int) round(microtime(true) * 1000);
        foreach ($labels as $label) {
            $ins->execute([
                'photo_id' => $photoId,
                'label' => $label,
                'note' => null,
                'user_id' => $userId,
                'created_at' => $now,
            ]);
        }

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Jeżeli żądanie moderacji zawiera pole `labels`, ustawia etykiety atrakcji
 * zdjęcia (walidacja wobec zm_attraction_labels()). Brak klucza = brak zmian,
 * więc zatwierdzenie/odrzucenie bez tego pola nie rusza etykiet.
 * Zwraca docelową listę etykiet albo null, gdy nie ruszano.
 */
function zm_attraction_apply_labels(PDO $pdo, int $photoId, array $data, ?int $userId): ?array
{
    if (!array_key_exists('labels', $data)) {
        return null;
    }

    $labels = zm_attraction_normalize_labels($data['labels']);
    zm_attraction_set_labels($pdo, $photoId, $labels, $userId);
    return $labels;
}

/**
 * Nagroda dla zgłaszającego, gdy jego zdjęcie zostanie atrakcją turystyczną.
 *
 * Wołane przy zatwierdzaniu oraz przy edycji już zatwierdzonego zdjęcia.
 * Dwie rzeczy, obie jednorazowe i obie sterowane konfiguracją (zakładka Atrakcje):
 *   1) bonus punktowy  — przez zm_award_once(), więc ponowne zatwierdzenie czy
 *      kolejna edycja NIE naliczą drugi raz (strażnik w points_history);
 *   2) powiadomienie   — typu `attraction_featured`, wysyłane tylko raz na zdjęcie
 *      (istnienie sprawdzamy w notifications); samo zm_notify_user() respektuje
 *      jeszcze przełącznik typu w notification_config.
 *
 * Sprawdza realny stan: zdjęcie musi być zatwierdzone i mieć etykiety atrakcji.
 * Całość w try/catch — nagroda nie może wywrócić moderacji.
 */
function zm_attraction_reward_owner(PDO $pdo, int $photoId, int $userId, ?int $adminId): void
{
    if ($photoId <= 0 || $userId <= 0) {
        return;
    }

    try {
        $st = $pdo->prepare('SELECT moderation_status FROM photos WHERE id = :id LIMIT 1');
        $st->execute(['id' => $photoId]);
        $status = (string) $st->fetchColumn();
        if ($status !== 'approved') {
            return;
        }

        // Etykiety atrakcji = warunek „jest atrakcją”. Pusto = nie ma nagrody.
        if (zm_attraction_photo_labels($pdo, $photoId) === []) {
            return;
        }

        $cfg = zm_attraction_config();

        // 1) Bonus punktowy — jednorazowo na zdjęcie.
        $bonus = (int) ($cfg['bonus_points'] ?? 0);
        if ($bonus > 0) {
            zm_award_once($pdo, $userId, $bonus, 'attraction_bonus', 'photo', $photoId);
        }

        // 2) Powiadomienie — jednorazowo na zdjęcie, dodatkowo bramkowane
        //    przełącznikiem w konfiguracji atrakcji i typem w notification_config.
        if (!empty($cfg['notify_attraction'])) {
            $has = $pdo->prepare(
                "SELECT 1 FROM notifications
                  WHERE user_id = :u AND type = 'attraction_featured'
                    AND ref_type = 'photo' AND ref_id = :p LIMIT 1"
            );
            $has->execute(['u' => $userId, 'p' => $photoId]);
            if (!$has->fetchColumn()) {
                zm_notify_user($pdo, $userId, 'attraction_featured', $adminId, [
                    'ref_type' => 'photo',
                    'ref_id'   => $photoId,
                    'body'     => 'Twoje zdjęcie zostało wyróżnione jako atrakcja turystyczna.',
                ]);
            }
        }
    } catch (Throwable $e) {
        // Nagroda jest dodatkiem — jej błąd nie może przerwać moderacji.
    }
}

/**
 * Wspólne warunki filtrów listy zdjęć — używane przez photo_list
 * i photo_list_ids (zaznaczanie „wyniki”).
 */
function zm_photo_filters(array $g, PDO $pdo): array
{
    $search = trim((string) ($g['search'] ?? ''));
    $category = (string) ($g['category'] ?? '');
    $published = (string) ($g['published'] ?? '');
    $moderation = (string) ($g['moderation'] ?? '');
    $user = (string) ($g['user'] ?? '');

    $where = [];
    $params = [];

    if ($search !== '') {
        $searchValue = '%' . zm_lower($search) . '%';

        $where[] = '(
            LOWER(p.title) LIKE :search_title
            OR LOWER(p.filename) LIKE :search_filename
            OR LOWER(IFNULL(p.place, \'\')) LIKE :search_place
            OR LOWER(IFNULL(p.description, \'\')) LIKE :search_description
        )';

        $params['search_title'] = $searchValue;
        $params['search_filename'] = $searchValue;
        $params['search_place'] = $searchValue;
        $params['search_description'] = $searchValue;
    }

    if ($category === 'none') {
        $where[] = 'p.category_id IS NULL';
    } elseif ($category !== '' && ctype_digit($category)) {
        // wybrana kategoria + wszystkie jej podkategorie
        $subIds = zm_category_subtree_ids(zm_category_tree($pdo), (int) $category);
        $idList = implode(',', array_map('intval', $subIds));
        $where[] = 'p.category_id IN (' . $idList . ')';
    }

    if ($published === '1' || $published === '0') {
        $where[] = 'p.published = :published';
        $params['published'] = (int) $published;
    }

    if (in_array($moderation, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'p.moderation_status = :moderation';
        $params['moderation'] = $moderation;
    }

    // Filtr „Atrakcje” — tylko zdjęcia oznaczone jako atrakcja turystyczna
    // (istnieje co najmniej jeden wpis w photo_attractions).
    if (($g['attractions'] ?? '') === '1') {
        $where[] = 'EXISTS (SELECT 1 FROM photo_attractions pa WHERE pa.photo_id = p.id)';
    }

    if ($user !== '' && ctype_digit($user)) {
        $where[] = 'p.submitted_by_user_id = :phuser';
        $params['phuser'] = (int) $user;
    }

    return [
        'whereSql' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

/**
 * Zatwierdzenie jednego zgłoszenia zdjęcia — używane przez pojedynczą akcję
 * i przez masowe zatwierdzanie (photo_moderate_bulk). Logika ta sama co
 * w photo_moderate.
 */
function zm_admin_approve_photo(PDO $pdo, array $admin, int $id, array $opts = []): ?array
{
    $select = $pdo->prepare(
        'SELECT id, filename, moderation_status, submitted_by_user_id, category_id,
                gmina_detected, geo_json, category_request_id
         FROM photos WHERE id = :id LIMIT 1'
    );
    $select->execute(['id' => $id]);
    $row = $select->fetch();

    if (!$row) {
        return null;
    }

    $createGmina = !empty($opts['create_gmina']);
    $catChoice = (array_key_exists('category_id', $opts) && $opts['category_id'] !== '')
        ? (int) $opts['category_id']
        : null;

    $assignCategory = null;
    $createdCategoryId = null;
    $gmina = trim((string) ($row['gmina_detected'] ?? ''));

    if ($createGmina) {
        $need = zm_geo_need_from_geo_json((string) ($row['geo_json'] ?? ''));

        if ($need !== null && $need['kind'] === 'place' && $need['child_name'] !== '') {
            // brakowało podkategorii miejscowości
            $parentId = $need['parent_id'];
            if ($parentId === null && $gmina !== '') {
                $parentId = zm_geo_ensure_gmina_category($pdo, $gmina);
            }
            if ($parentId !== null) {
                $assignCategory = zm_geo_ensure_child_category($pdo, $parentId, $need['child_name']);
            }
        } elseif ($gmina !== '') {
            $assignCategory = zm_geo_ensure_gmina_category($pdo, $gmina);
        }

        $createdCategoryId = $assignCategory;
    } elseif ($catChoice !== null) {
        $chk = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
        $chk->execute(['id' => $catChoice]);
        if ($chk->fetchColumn() !== false) {
            $assignCategory = $catChoice;
        }
    } else {
        // Bez jawnego wyboru (szybkie „Zatwierdzono zdjęcie”, zatwierdzanie
        // masowe) oddajemy propozycję z geo/wniosku — inaczej zdjęcie zostaje
        // przy kategorii zastępczej gminy, a wniosek o podkategorię wisi jako
        // pending.
        $auto = zm_admin_auto_category($pdo, $row);
        if ($auto !== null) {
            $assignCategory = $auto['id'];
            if ($auto['created']) {
                $createdCategoryId = $auto['id'];
            }
        }
    }

    if ($assignCategory !== null) {
        $pdo->prepare(
            "UPDATE photos
             SET published = 1, moderation_status = 'approved', publish_at = NULL,
                 category_id = :cat, geo_status = 'matched', category_request_id = NULL
             WHERE id = :id"
        )->execute(['cat' => $assignCategory, 'id' => $id]);

        // Zgłoszenie miało własny wniosek o kategorię i admin właśnie ją
        // zatwierdził — zamykamy wniosek, żeby nie wisiał na zakładce.
        if (!empty($row['category_request_id'])) {
            $requestId = (int) $row['category_request_id'];
            $pdo->prepare(
                "UPDATE category_requests
                 SET status = 'approved', resolved_by_user_id = :admin,
                     resolved_at = NOW(), created_category_id = :cat
                 WHERE id = :id AND status = 'pending'"
            )->execute([
                'admin' => $admin['id'],
                'cat' => $assignCategory,
                'id' => $requestId,
            ]);

            // Pozostałe zdjęcia z tego samego wniosku dostają tę samą
            // kategorię — wniosek dotyczył miejsca, nie konkretnego pliku.
            $pdo->prepare(
                'UPDATE photos SET category_id = :cat
                 WHERE category_request_id = :rid AND category_id IS NULL'
            )->execute(['cat' => $assignCategory, 'rid' => $requestId]);
        }
    } else {
        $pdo->prepare(
            'UPDATE photos SET published = 1, moderation_status = \'approved\', publish_at = NULL WHERE id = :id'
        )->execute(['id' => $id]);
    }

    // Nagroda +10 za zatwierdzone zdjęcie zgłoszone przez użytkownika.
    // Jednorazowo (strażnik w points_history). Zdjęcia admina punktów nie dają.
    $submitter = (int) ($row['submitted_by_user_id'] ?? 0);
    if ($submitter > 0) {
        zm_award_once($pdo, $submitter, 10, 'photo_add', 'photo', $id);
    }

    // miniatury mogły nie powstać przy zgłoszeniu — dogeneruj teraz
    try {
        zm_pregen((string) $row['filename']);
    } catch (Throwable $e) {
        // nieudana miniatura nie blokuje zatwierdzenia
    }

    if ($submitter > 0) {
        zm_notify_user($pdo, $submitter, 'moderation_approved', (int) $admin['id'], [
            'ref_type' => 'photo',
            'ref_id'   => $id,
            'body'     => 'Twoje zdjęcie jest już w galerii.',
        ]);

        // Obserwujący autora dostają „nowe zdjęcie od obserwowanego”.
        zm_notify_followers($pdo, $submitter, 'follow_post', $submitter, [
            'ref_type' => 'photo',
            'ref_id'   => $id,
            'body'     => 'dodał nowe zdjęcie do galerii',
        ]);
    }

    // Etykiety atrakcji — jeśli żądanie zawiera pole `labels`, ustawiamy zbiór
    // etykiet zdjęcia. Brak klucza = brak zmian (etykiety zgłoszone przez
    // użytkownika zostają). Zapis w transakcji wewnątrz zm_attraction_set_labels().
    zm_attraction_apply_labels($pdo, $id, $opts, null);

    // Bonus + powiadomienie, gdy zatwierdzone zdjęcie jest atrakcją (po etykietach).
    if ($submitter > 0) {
        zm_attraction_reward_owner($pdo, $id, $submitter, (int) $admin['id']);
    }

    zm_log($pdo, 'photo_approve', $admin['username'], 'p' . $id, (string) $row['filename']);

    return [
        'ok' => true,
        'decision' => 'approve',
        'category_id' => $assignCategory,
        'created_category_id' => $createdCategoryId,
        'labels' => zm_attraction_photo_labels($pdo, $id),
    ];
}

/**
 * Odrzucenie zgłoszenia zdjęcia. Plik zostaje, zdjęcie zostaje ukryte.
 */
function zm_admin_reject_photo(PDO $pdo, array $admin, int $id, array $opts = []): ?array
{
    $select = $pdo->prepare(
        'SELECT id, filename, submitted_by_user_id
         FROM photos WHERE id = :id LIMIT 1'
    );
    $select->execute(['id' => $id]);
    $row = $select->fetch();

    if (!$row) {
        return null;
    }

    $pdo->prepare(
        "UPDATE photos SET published = 0, moderation_status = 'rejected' WHERE id = :id"
    )->execute(['id' => $id]);

    $rejectTo = (int) ($row['submitted_by_user_id'] ?? 0);
    if ($rejectTo > 0) {
        zm_notify_user($pdo, $rejectTo, 'moderation_rejected', (int) $admin['id'], [
            'ref_type' => 'photo',
            'ref_id'   => $id,
            'body'     => 'Twoje zgłoszenie zostało odrzucone. Plik nie został skasowany.',
        ]);
    }

    zm_attraction_apply_labels($pdo, $id, $opts, null);

    zm_log($pdo, 'photo_reject', $admin['username'], 'p' . $id, (string) $row['filename']);

    return [
        'ok' => true,
        'decision' => 'reject',
        'category_id' => null,
        'created_category_id' => null,
        'labels' => zm_attraction_photo_labels($pdo, $id),
    ];
}

require __DIR__ . '/photos-lib.php';
require __DIR__ . '/thumbs-lib.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/geo-lib.php';
// powiadomienia społecznościowe: decyzje moderacji, punkty i rangi muszą
// dotrzeć do użytkownika, a zgłoszenia i nowe konta — do panelu admina
require_once __DIR__ . '/social-lib.php';
// statystyki ruchu (bez cookies, szczegółowy IP log z retencją) — panel admina
require_once __DIR__ . '/traffic-lib.php';
// rozbudowany panel statystyk (6 sekcji): ruch, zdjęcia, miejscowości,
// społeczność, SEO i stan techniczny — w całości z realnych danych bazy
require_once __DIR__ . '/stats-lib.php';
// centrum bezpieczeństwa — dziennik zdarzeń, bany IP, konfiguracja HTTPS/limitów
require_once __DIR__ . '/security-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// odpowiedzi administracyjne czytają tabele i pliki — zawsze z sieci
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);

    // Błąd trafia do dziennika systemowego (Stan techniczny), ale klientowi
    // zwracamy tylko nazwę pliku i linię — bez pełnej ścieżki serwera.
    if (function_exists('zm_system_event') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        zm_system_event(
            $GLOBALS['pdo'],
            'php_fatal',
            basename($e->getFile()) . ':' . $e->getLine() . ' ' . $e->getMessage(),
            zm_system_path()
        );
    }

    echo json_encode([
        'error' => 'server_error',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);

    exit;
});

function zm_fail(int $code, string $error, array $extra = []): void
{
    http_response_code($code);

    echo json_encode(
        array_merge(['error' => $error], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Pole lokalizacji (upload i edycja) to link do miejsca z Google Maps.
 * Współrzędne wyciągamy z URL-a w config.php i zapisujemy w photos.lat /
 * photos.lng — dokładnie tak, jak wcześniej. Panel użytkownika używa tej samej
 * funkcji (zsp_parse_coords w submit-photo-api.php).
 *
 * Zwraca [lat, lng]; puste pole to [null, null] i lokalizacja zostaje usunięta.
 */
function zm_parse_coordinates(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [null, null];
    }

    $parsed = zm_google_maps_coords($value);

    if ($parsed === null) {
        zm_fail(400, 'bad_coordinates', [
            'message' => 'Nie udało się odczytać współrzędnych z tego linku. Wklej pełny link '
                . 'do miejsca z Map Google, np. https://www.google.com/maps/@52.6337,17.9810,15z',
        ]);
    }

    $lat = $parsed['lat'];
    $lng = $parsed['lng'];

    if ($lat < -90 || $lat > 90) {
        zm_fail(400, 'bad_lat');
    }

    if ($lng < -180 || $lng > 180) {
        zm_fail(400, 'bad_lng');
    }

    return [$lat, $lng];
}

/**
 * Generuje adres osadzonej mapy OpenStreetMap.
 */
function zm_osm_embed_url(?float $lat, ?float $lng): ?string
{
    if ($lat === null || $lng === null) {
        return null;
    }

    $delta = 0.01;

    $west = number_format($lng - $delta, 6, '.', '');
    $south = number_format($lat - $delta, 6, '.', '');
    $east = number_format($lng + $delta, 6, '.', '');
    $north = number_format($lat + $delta, 6, '.', '');
    $markerLat = number_format($lat, 7, '.', '');
    $markerLng = number_format($lng, 7, '.', '');

    return 'https://www.openstreetmap.org/export/embed.html'
        . '?bbox=' . rawurlencode("$west,$south,$east,$north")
        . '&layer=mapnik'
        . '&marker=' . rawurlencode("$markerLat,$markerLng");
}

/**
 * Zachowuje kompatybilność ze starymi rekordami zawierającymi
 * zwykły link lub iframe w map_url.
 */
function zm_legacy_map_value(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (stripos($value, '<iframe') !== false) {
        if (!preg_match(
            '~<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is',
            $value,
            $match
        )) {
            return null;
        }

        $value = html_entity_decode(
            trim((string) $match[2]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }

    return $value;
}

/**
 * Data/godzina publikacji z pola datetime-local ("2026-10-01T14:30").
 * Zwraca 'Y-m-d H:i:s' albo null (puste / niepoprawne).
 */
function zm_parse_publish_at(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$~', $value)) {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Łączy pole "published" z planowaniem.
 * Zwraca [published(0/1), publish_at|null]:
 *   opublikowane            → [1, null]
 *   ukryte + data w przyszłości → [0, data]  (zaplanowane)
 *   ukryte + data z przeszłości → [1, null]  (termin minął, publikujemy)
 *   ukryte bez daty         → [0, null]
 */
function zm_resolve_publish(bool $published, ?string $publishAt): array
{
    if ($published) {
        return [1, null];
    }
    if ($publishAt === null) {
        return [0, null];
    }
    return strtotime($publishAt) > time() ? [0, $publishAt] : [1, null];
}

function zm_log(
    PDO $pdo,
    string $action,
    string $adminUser,
    string $photoId,
    string $photoName
): void {
    $statement = $pdo->prepare(
        'INSERT INTO admin_log
            (
                action,
                comment_id,
                author,
                text,
                photo_id,
                photo_name,
                admin_user,
                created_at
            )
         VALUES
            (
                :action,
                NULL,
                NULL,
                NULL,
                :photo_id,
                :photo_name,
                :admin_user,
                :created_at
            )'
    );

    $statement->execute([
        'action' => $action,
        'photo_id' => $photoId,
        'photo_name' => $photoName,
        'admin_user' => $adminUser,
        'created_at' => (int) round(microtime(true) * 1000),
    ]);
}

/** "Apple iPhone 13" z zapisanego exif_json (do listy w panelu). */
function zm_camera_label(string $json): string
{
    if ($json === '') {
        return '';
    }
    $ex = json_decode($json, true);
    if (!is_array($ex)) {
        return '';
    }
    $make = trim((string) ($ex['make'] ?? ''));
    $model = trim((string) ($ex['model'] ?? ''));
    // model często zaczyna się od nazwy producenta — nie dublujemy
    if ($make !== '' && stripos($model, $make) === 0) {
        $make = '';
    }
    return trim($make . ' ' . $model);
}

/**
 * Pełny odczyt EXIF z photos.exif_json — do okna „Sprawdź i zatwierdź”.
 * Zwraca tylko wartości, które faktycznie zostały odczytane z pliku,
 * żeby administrator nie widział pustych pól.
 */
function zm_exif_payload(string $json): array
{
    if ($json === '') {
        return [];
    }
    $ex = json_decode($json, true);
    if (!is_array($ex)) {
        return [];
    }
    $labels = [
        'make'      => 'Aparat (producent)',
        'model'     => 'Model',
        'lens'      => 'Obiektyw',
        'software'  => 'Program',
        'tool'      => 'Program (XMP)',
        'focal'     => 'Ogniskowa',
        'aperture'  => 'Przysłona',
        'exposure'  => 'Czas naświetlania',
        'iso'       => 'ISO',
        'taken'     => 'Data wykonania',
    ];
    $out = [];
    foreach ($labels as $key => $label) {
        if (!isset($ex[$key]) || $ex[$key] === '' || $ex[$key] === null) {
            continue;
        }
        $out[$key] = ['label' => $label, 'value' => (string) $ex[$key]];
    }
    return $out;
}

/**
 * Zapewnia pole na pełną treść notatki. Starsza kolumna VARCHAR obcinała
 * tekst mimo wyższego limitu w formularzu i API.
 */
function zm_ensure_admin_tasks_text(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->query(
        "SELECT DATA_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'admin_tasks'
           AND COLUMN_NAME = 'text'
         LIMIT 1"
    );
    $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$column) {
        return;
    }

    $type = strtolower((string) ($column['DATA_TYPE'] ?? ''));
    if (in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) {
        return;
    }

    $nullable = strtoupper((string) ($column['IS_NULLABLE'] ?? 'NO')) === 'YES';
    $pdo->exec('ALTER TABLE admin_tasks MODIFY COLUMN text MEDIUMTEXT ' . ($nullable ? 'NULL' : 'NOT NULL'));
}

/** Lista notatek administratora: aktywne i wykonane, najnowsze na górze w każdej grupie. */
/**
 * Dodaje status realizacji do notatek administratora (todo / doing).
 * Istniejące wpisy pozostają domyślnie w stanie todo.
 */
function zm_ensure_admin_tasks_status(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'admin_tasks'
           AND COLUMN_NAME = 'status'
         LIMIT 1"
    );
    if (!$stmt || !$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE admin_tasks ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'todo'");
    }
}

function zm_task_payload(PDO $pdo): array
{
    zm_ensure_admin_tasks_text($pdo);
    zm_ensure_admin_tasks_status($pdo);
    $rows = $pdo->query(
        'SELECT id, text, done, status, created_at, done_at FROM admin_tasks
         ORDER BY done ASC, CASE WHEN status = \'doing\' THEN 0 ELSE 1 END, created_at DESC, id DESC
         LIMIT 500'
    )->fetchAll();
    $open = 0;
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['done'] = (int) $r['done'];
        $r['status'] = ($r['status'] ?? 'todo') === 'doing' && !$r['done'] ? 'doing' : 'todo';
        $r['created_at'] = (int) $r['created_at'];
        $r['done_at'] = $r['done_at'] !== null ? (int) $r['done_at'] : null;
        if (!$r['done']) {
            $open++;
        }
    }
    unset($r);
    return ['items' => $rows, 'open' => $open];
}

/**
 * Drzewo projektów roboczych: projekt -> zadania -> prompty.
 *
 * Wszystko w jednym payloadzie, bo projekty to mały zbiór, a panel i tak
 * renderuje je po stronie przeglądarki. Zwracamy też licznik otwartych zadań.
 */
function zm_projects_payload(PDO $pdo): array
{
    $projects = $pdo->query(
        'SELECT id, name, slug, description, status, sort_order, created_at, updated_at
         FROM admin_projects ORDER BY sort_order ASC, id ASC'
    )->fetchAll();

    $tasks = $pdo->query(
        'SELECT id, project_id, title, description, status, priority, sort_order, created_at, done_at
         FROM admin_project_tasks ORDER BY sort_order ASC, id ASC'
    )->fetchAll();

    $prompts = $pdo->query(
        'SELECT id, task_id, label, body, sort_order, created_at, updated_at
         FROM admin_project_prompts ORDER BY sort_order ASC, id ASC'
    )->fetchAll();

    $promptsByTask = [];
    foreach ($prompts as $p) {
        $p['id'] = (int) $p['id'];
        $p['task_id'] = (int) $p['task_id'];
        $p['sort_order'] = (int) $p['sort_order'];
        $promptsByTask[$p['task_id']][] = $p;
    }

    $tasksByProject = [];
    foreach ($tasks as $t) {
        $t['id'] = (int) $t['id'];
        $t['project_id'] = (int) $t['project_id'];
        $t['priority'] = (int) $t['priority'];
        $t['sort_order'] = (int) $t['sort_order'];
        $t['done_at'] = $t['done_at'] !== null ? (int) $t['done_at'] : null;
        $t['prompts'] = $promptsByTask[$t['id']] ?? [];
        $tasksByProject[$t['project_id']][] = $t;
    }

    $openTasks = 0;
    foreach ($projects as &$pr) {
        $pr['id'] = (int) $pr['id'];
        $pr['sort_order'] = (int) $pr['sort_order'];
        $pr['tasks'] = $tasksByProject[$pr['id']] ?? [];
        foreach ($pr['tasks'] as $tk) {
            if ($tk['status'] !== 'done') {
                $openTasks++;
            }
        }
    }
    unset($pr);

    return ['projects' => $projects, 'open_tasks' => $openTasks];
}

/** Unikatowy slug projektu wyprowadzony z nazwy (np. „Atrakcja turystyczna”). */
function zm_project_slug(PDO $pdo, string $name, int $ignoreId = 0): string
{
    $base = (string) preg_replace('~[^a-z0-9]+~u', '-', zm_lower(trim($name)));
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'projekt';
    }
    $base = zm_cut($base, 60);

    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id FROM admin_projects WHERE slug = :s AND id <> :id LIMIT 1'
        );
        $stmt->execute(['s' => $slug, 'id' => $ignoreId]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

/**
 * Lista nazw, których nie wolno użyć jako loginu (tabela `blocked_usernames`).
 *
 * Administrator dodaje i usuwa pozycje; rejestracja sprawdza tę listę przy
 * każdej próbie założenia konta (auth.php → zm_register()).
 */
function zm_blocked_payload(PDO $pdo): array
{
    // Jedno zapytanie zamiast osobnego liczenia per pozycja — lista jest mała,
    // ale i tak nie mnożymy odpytań na żądanie.
    $rows = $pdo->query(
        'SELECT b.id, b.name, b.created_by, b.created_at,
                (SELECT COUNT(*) FROM users u WHERE LOWER(u.username) = LOWER(b.name)) AS in_use
         FROM blocked_usernames b
         ORDER BY b.name ASC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['in_use'] = (int) $r['in_use'];
        $r['created_at'] = $r['created_at'] !== null
            ? (strtotime((string) $r['created_at']) ?: null)
            : null;
    }
    unset($r);
    return ['items' => $rows, 'count' => count($rows)];
}

/**
 * Uproszczenie nazwy przy wpisywaniu: obcina spacje, skleja wyrazy rozdzielone
 * myślnikiem („kur wa” → „kurwa”) i zostawia tylko znaki, które mogą w ogóle
 * wystąpić w loginie. Dzięki temu „K U R W A” i „kurwa” trafiają do tej samej
 * pozycji, a lista nie mierzy tego, kto jak pisze.
 */
function zm_blocked_name_normalize(string $raw): string
{
    $s = mb_strtolower(trim($raw));
    $s = preg_replace('~\s+~u', '', $s) ?? '';
    $s = str_replace(['-', '_', '.'], '', $s);
    return mb_substr($s, 0, 60);
}

/**
 * Lista wyrazów, których nie wolno użyć w komentarzu (tabela `blocked_words`).
 *
 * Administrator dodaje i usuwa pozycje; każde dodanie komentarza sprawdza tę
 * listę (comments.php → zm_text_blocked_word()).
 */
function zm_blocked_words_payload(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, word, created_by, created_at FROM blocked_words ORDER BY word ASC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['created_at'] = $r['created_at'] !== null
            ? (strtotime((string) $r['created_at']) ?: null)
            : null;
    }
    unset($r);
    return ['items' => $rows, 'count' => count($rows)];
}

/**
 * Uproszczenie wpisu tak samo, jak przy blokowanych nazwach: małe litery bez
 * spacji i znaków przestankowych, więc „K U R W A”, „kur-wa” i „kurwa” to jedna
 * pozycja. Normalizacja jest ta sama, którą stosuje dopasowanie, dzięki czemu
 * lista nie może rozjechać się z tym, co faktycznie jest blokowane.
 */
function zm_blocked_word_normalize(string $raw): string
{
    return str_replace(' ', '', zm_text_norm($raw));
}

/**
 * Otwarte zgłoszenia komentarzy (panel admina → „Zgłoszenia”).
 *
 * Dokładamy treść i autora zgłaszanego komentarza, żeby administrator
 * zdecydował od razu, bez przechodzenia w inne miejsce. Jeśli komentarza już
 * nie ma, wiersz zostaje z pustą treścią — to samo w sobie jest informacją
 * („ktoś zgłosił rzecz, której już nie ma”).
 */
function zm_comment_reports_payload(PDO $pdo): array
{
    $reasons = zm_comment_report_reasons();
    $rows = $pdo->query(
        "SELECT r.id, r.comment_id, r.photo_id, r.reason, r.note, r.created_at,
                c.author AS comment_author, c.text AS comment_text, c.photo_name,
                u.username AS reporter_username, u.display_name AS reporter_display_name
         FROM comment_reports r
         LEFT JOIN comments c ON c.id = r.comment_id
         LEFT JOIN users u ON u.id = r.reporter_user_id
         WHERE r.status = 'open'
         ORDER BY r.created_at DESC
         LIMIT 200"
    )->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $reporter = trim((string) ($row['reporter_display_name'] ?? ''));
        if ($reporter === '') {
            $reporter = (string) ($row['reporter_username'] ?? '');
        }
        $items[] = [
            'id' => (int) $row['id'],
            'comment_id' => (int) $row['comment_id'],
            'comment_missing' => $row['comment_text'] === null,
            'comment_author' => (string) ($row['comment_author'] ?? ''),
            'comment_text' => (string) ($row['comment_text'] ?? ''),
            'photo_id' => (string) ($row['photo_id'] ?? ''),
            'photo_name' => (string) ($row['photo_name'] ?? ''),
            'reason' => (string) $row['reason'],
            'reason_label' => $reasons[(string) $row['reason']] ?? 'Inne',
            'note' => (string) ($row['note'] ?? ''),
            // gość bez konta nie ma nazwy — wtedy zostaje „gość”
            'reporter' => $reporter !== '' ? $reporter : 'gość',
            'reporter_guest' => $reporter === '',
            'created_at' => (int) $row['created_at'],
        ];
    }
    return ['items' => $items, 'count' => count($items)];
}

/**
 * Otwarte zgłoszenia zdjęć (panel admina → „Zgłoszenia → Zdjęcia”).
 *
 * Dokładamy tytuł i autora zgłaszanego zdjęcia, żeby administrator zdecydował
 * od razu. Gdy zdjęcia już nie ma (ktoś je usunął), wiersz zostaje z pustą
 * miniaturą — to samo w sobie jest informacją. Numer zdjęcia trzymamy jako
 * `photo_key` = „p<id>”, tak jak w linkach galerii.
 */
function zm_photo_reports_payload(PDO $pdo): array
{
    $reasons = zm_photo_report_reasons();
    $rows = $pdo->query(
        "SELECT r.id, r.photo_id, r.reason, r.note, r.created_at,
                p.filename, p.title, p.place, p.published, p.moderation_status,
                u.username AS author_username, u.display_name AS author_display_name,
                ru.username AS reporter_username, ru.display_name AS reporter_display_name
         FROM photo_reports r
         LEFT JOIN photos p ON p.id = r.photo_id
         LEFT JOIN users u ON u.id = p.submitted_by_user_id
         LEFT JOIN users ru ON ru.id = r.reporter_user_id
         WHERE r.status = 'open'
         ORDER BY r.created_at DESC
         LIMIT 200"
    )->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $pid = (int) $row['photo_id'];
        $missing = $row['filename'] === null;

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = $missing ? ('p' . $pid) : (string) $row['filename'];
        }

        $author = trim((string) ($row['author_display_name'] ?? ''));
        if ($author === '') {
            $author = trim((string) ($row['author_username'] ?? ''));
        }

        $reporter = trim((string) ($row['reporter_display_name'] ?? ''));
        if ($reporter === '') {
            $reporter = trim((string) ($row['reporter_username'] ?? ''));
        }

        $items[] = [
            'id' => (int) $row['id'],
            'photo_id' => $pid,
            'photo_key' => 'p' . $pid,
            'photo_missing' => $missing,
            'title' => $title,
            'author' => $author !== '' ? $author : 'gość',
            'place' => (string) ($row['place'] ?? ''),
            'published' => (int) ($row['published'] ?? 0),
            'moderation_status' => (string) ($row['moderation_status'] ?? ''),
            'reason' => (string) $row['reason'],
            'reason_label' => $reasons[(string) $row['reason']] ?? 'Inne',
            'note' => (string) ($row['note'] ?? ''),
            'reporter' => $reporter !== '' ? $reporter : 'gość',
            'reporter_guest' => $reporter === '',
            'created_at' => (int) $row['created_at'],
            'thumb' => $missing ? '' : zm_thumb_url('p' . $pid, 320),
            'url' => 'index.php?photo=p' . $pid,
        ];
    }
    return ['items' => $items, 'count' => count($items)];
}

$admin = zm_current_admin();

if ($admin === null) {
    zm_fail(401, 'not_authenticated');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';


/** Tabela prywatnego katalogu linków administratora. */
function zm_ensure_admin_links_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(120) NOT NULL,
        url VARCHAR(2048) NOT NULL,
        description TEXT NULL,
        tags TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_admin_links_position (position, id), KEY idx_admin_links_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

zm_ensure_admin_links_table($pdo);

/* ------------------------------------------------------------ użytkownicy */

/**
 * Baza zapytania o listę użytkowników z licznikami aktywności.
 * Liczby: zatwierdzone OPUBLIKOWANE zdjęcia (kind='image'), komentarze z konta,
 * polubienia zdjęć (photo_likes). Zwraca [sql, params], gdzie sql ma `WHERE 1=1`.
 */
function zm_admin_users_base(PDO $pdo, array $where, array $params): array
{
    $w = array_merge(['1=1'], $where);
    $sql = 'SELECT u.id, u.username, u.display_name, u.email, u.role, u.points, u.rank_override, u.special_rank,
                   u.avatar_path, u.avatar_updated_at,
                   u.is_banned, u.banned_at, u.banned_by, u.created_at,
                   COALESCE(ph.cnt, 0) AS photos,
                   COALESCE(cm.cnt, 0) AS comments,
                   COALESCE(pl.cnt, 0) AS likes
            FROM users u
            LEFT JOIN (
                SELECT submitted_by_user_id AS uid, COUNT(*) AS cnt FROM photos
                WHERE submitted_by_user_id IS NOT NULL AND moderation_status = \'approved\'
                  AND published = 1 AND (kind IS NULL OR kind = \'image\')
                GROUP BY submitted_by_user_id
            ) ph ON ph.uid = u.id
            LEFT JOIN (
                SELECT user_id AS uid, COUNT(*) AS cnt FROM comments WHERE user_id IS NOT NULL GROUP BY user_id
            ) cm ON cm.uid = u.id
            LEFT JOIN (
                SELECT user_id AS uid, COUNT(*) AS cnt FROM photo_likes GROUP BY user_id
            ) pl ON pl.uid = u.id
            WHERE ' . implode(' AND ', $w);

    return [$sql, $params];
}

/** Porcja JSON z listą użytkowników (liczby całkowite). */
function zm_admin_users_list(PDO $pdo, array $filters, int $limit, int $offset): array
{
    $where = [];
    $params = [];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        // EMULATE_PREPARES = false: ten sam parametr może wystąpić tylko raz
        $where[] = '(u.username LIKE :q1 OR u.display_name LIKE :q2 OR u.email LIKE :q3)';
        $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
    }

    $status = (string) ($filters['status'] ?? '');
    if ($status === 'active') {
        $where[] = 'u.is_banned = 0';
    } elseif ($status === 'banned') {
        $where[] = 'u.is_banned = 1';
    }

    $rankFilter = (int) ($filters['rank'] ?? 0);
    if ($rankFilter > 0) {
        $defs = zm_rank_defs($pdo);
        foreach ($defs as $i => $d) {
            if ((int) $d['id'] !== $rankFilter) {
                continue;
            }
            if (!empty($d['special'])) {
                // specjalną mają użytkownicy z przypisaną drugą rangą (special_rank)
                $where[] = 'u.special_rank = :rk_id';
                $params['rk_id'] = $rankFilter;
                break;
            }
            $min = (int) $d['min_points'];
            // górna granica = próg następnej rangi zwykłej (specjalne nie tworzą pasm)
            $max = null;
            $defsCount = count($defs);
            for ($j = $i + 1; $j < $defsCount; $j++) {
                if (empty($defs[$j]['special'])) {
                    $max = (int) $defs[$j]['min_points'];
                    break;
                }
            }
            if ($max === null) {
                $where[] = 'u.points >= :rk_min';
            } else {
                $where[] = 'u.points >= :rk_min AND u.points < :rk_max';
                $params['rk_max'] = $max;
            }
            $params['rk_min'] = $min;
            break;
        }
    }

    $minPoints = (int) ($filters['min_points'] ?? 0);
    if ($minPoints > 0) {
        $where[] = 'u.points >= :mp';
        $params['mp'] = $minPoints;
    }

    [$sql, $params] = zm_admin_users_base($pdo, $where, $params);

    $cntParams = $params;
    $countSql = 'SELECT COUNT(*) FROM users u WHERE 1=1' . (count($where) ? ' AND ' . implode(' AND ', $where) : '');
    // w zapytaniu count liczymy bez złączeń; dopasujmy parametry rank
    $cStmt = $pdo->prepare($countSql);
    foreach ($cntParams as $k => $v) {
        $cStmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $cStmt->execute();
    $total = (int) $cStmt->fetchColumn();

    $sort = (string) ($filters['sort'] ?? 'points');
    $order = match ($sort) {
        'photos' => 'ph.cnt DESC, u.username ASC',
        'comments' => 'cm.cnt DESC, u.username ASC',
        'likes' => 'pl.cnt DESC, u.username ASC',
        'newest' => 'u.created_at DESC',
        'username' => 'u.username ASC',
        default => 'u.points DESC, u.username ASC',
    };

    $stmt = $pdo->prepare($sql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $rank = zm_user_rank($pdo, $r);
        $spRank = zm_user_rank_special($pdo, $r);
        $items[] = [
            'id' => (int) $r['id'],
            'username' => (string) $r['username'],
            'display_name' => (string) ($r['display_name'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'avatar' => zm_avatar_url($r, 96),
            'role' => (string) $r['role'],
            'points' => (int) $r['points'],
            'rank_id' => (int) $rank['id'],
            'rank_name' => (string) $rank['name'],
            'rank_manual' => (bool) $rank['manual'],
            'rank_special' => $spRank !== null,
            'rank_special_id' => $spRank === null ? null : (int) $spRank['id'],
            'rank_special_name' => $spRank === null ? null : (string) $spRank['name'],
            'photos' => (int) $r['photos'],
            'comments' => (int) $r['comments'],
            'likes' => (int) $r['likes'],
            'banned' => (int) $r['is_banned'] === 1,
            'banned_at' => $r['banned_at'] !== null ? (string) $r['banned_at'] : null,
            'created_at' => (string) $r['created_at'],
        ];
    }

    return ['items' => $items, 'total' => $total];
}

/** Definicje rang z rozkładem użytkowników (auto + ręczne nadpisania). */
function zm_admin_rank_payload(PDO $pdo): array
{
    $defs = zm_rank_defs($pdo);
    $rows = $pdo->query(
        'SELECT d.id, d.position, d.name, d.min_points, d.special,
                (SELECT COUNT(*) FROM users WHERE rank_override = d.id) AS manual_users,
                (SELECT COUNT(*) FROM users) AS total_users
         FROM rank_defs d ORDER BY d.position ASC'
    )->fetchAll();

    $rankMap = [];
    foreach ($rows as $r) {
        $rankMap[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'position' => (int) $r['position'],
            'name' => (string) $r['name'],
            'min_points' => (int) $r['min_points'],
            'special' => (int) ($r['special'] ?? 0) === 1,
            'manual_users' => (int) $r['manual_users'],
        ];
    }
    $list = array_values($rankMap);
    $autoTotal = 0;
    foreach ($list as $i => &$d) {
        if (!empty($d['special'])) {
            // ranga specjalna (np. „Team") — tylko jako DODATKOWA (special_rank), nigdy z punktów
            $d['auto_users'] = 0;
            $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE special_rank = :rid');
            $st->execute(['rid' => (int) $d['id']]);
            $d['manual_users'] = (int) $st->fetchColumn();
            $d['users'] = $d['manual_users'];
            continue;
        }
        // granica górna pasma = próg następnej rangi ZWYKŁEJ (specjalne nie tworzą pasm)
        $nextMin = null;
        for ($j = $i + 1, $c = count($list); $j < $c; $j++) {
            if (empty($list[$j]['special'])) {
                $nextMin = (int) $list[$j]['min_points'];
                break;
            }
        }
        if ($nextMin === null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE points >= :m AND rank_override IS NULL');
            $stmt->execute(['m' => $d['min_points']]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE points >= :min AND points < :max AND rank_override IS NULL');
            $stmt->execute(['min' => $d['min_points'], 'max' => $nextMin]);
        }
        $d['auto_users'] = (int) $stmt->fetchColumn();
        $d['users'] = $d['auto_users'] + $d['manual_users'];
        $autoTotal += $d['auto_users'];
    }
    unset($d);

    $totals = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    return [
        'ranks' => $list,
        'total_users' => (int) $totals,
        'banned_users' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn(),
        'manual_overrides' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE rank_override IS NOT NULL')->fetchColumn(),
        'auto_users' => $autoTotal,
    ];
}

/* ================================================================ GET === */

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    zm_publish_due($pdo, true);

    if ($action === 'categories') {
        $tree = zm_category_tree($pdo);

        $direct = [];
        foreach ($pdo->query(
            'SELECT category_id, COUNT(*) AS n FROM photos WHERE category_id IS NOT NULL GROUP BY category_id'
        )->fetchAll() as $cr) {
            $direct[(int) $cr['category_id']] = (int) $cr['n'];
        }

        // lista w kolejności drzewa: kategoria, jej podkategorie, następna kategoria…
        $rows = [];
        $walk = function (int $parent, int $depth, string $path) use (&$walk, &$rows, $tree, $direct): void {
            if ($depth > 8) {
                return;
            }
            foreach ($tree['children'][$parent] ?? [] as $id) {
                $r = $tree['byId'][$id];
                $fullPath = $path === '' ? (string) $r['name'] : $path . ' › ' . $r['name'];
                $total = 0;
                foreach (zm_category_subtree_ids($tree, $id) as $sid) {
                    $total += $direct[$sid] ?? 0;
                }
                $rows[] = [
                    'id' => (int) $id,
                    'parent_id' => !empty($r['parent_id']) && isset($tree['byId'][(int) $r['parent_id']])
                        ? (int) $r['parent_id'] : null,
                    'name' => (string) $r['name'],
                    'description' => $r['description'],
                    'sort_order' => (int) $r['sort_order'],
                    'depth' => $depth,
                    'path' => $fullPath,
                    'photo_count' => $direct[$id] ?? 0,
                    'total_count' => $total,
                    'has_children' => !empty($tree['children'][$id]),
                    'height' => zm_category_height($tree, (int) $id),
                ];
                $walk((int) $id, $depth + 1, $fullPath);
            }
        };
        $walk(0, 1, '');

        echo json_encode([
            'items' => $rows,
            'max_depth' => ZM_CATEGORY_MAX_DEPTH,
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($action === 'category_requests') {
        $status = (string) ($_GET['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $sql = "SELECT r.*, u.username, ru.username AS resolved_by_username,
                       c.name AS created_category_name,
                       (SELECT COUNT(*) FROM photos p WHERE p.category_request_id = r.id) AS photo_count,
                       (SELECT COUNT(*) FROM photos p
                          WHERE p.category_request_id = r.id AND p.published = 0) AS pending_photos
                FROM category_requests r
                LEFT JOIN users u ON u.id = r.requested_by_user_id
                LEFT JOIN users ru ON ru.id = r.resolved_by_user_id
                LEFT JOIN categories c ON c.id = r.created_category_id";
        $params = [];

        if ($status !== 'all') {
            $sql .= ' WHERE r.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY r.id DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = zm_geo_request_row($row);
            $item['lat'] = $row['lat'] !== null ? (float) $row['lat'] : null;
            $item['lng'] = $row['lng'] !== null ? (float) $row['lng'] : null;

            // miniatury zdjęć podpiętych do wniosku — admin widzi, o co walczymy
            $thumbs = [];
            $ts = $pdo->prepare(
                'SELECT id FROM photos WHERE category_request_id = :id ORDER BY id DESC LIMIT 6'
            );
            $ts->execute(['id' => (int) $row['id']]);
            foreach ($ts->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $thumbs[] = zm_thumb_url('p' . (int) $pid, 160);
            }
            $item['thumbs'] = $thumbs;

            $items[] = $item;
        }

        echo json_encode([
            'ok' => true,
            'items' => $items,
            'pending_total' => (int) $pdo
                ->query("SELECT COUNT(*) FROM category_requests WHERE status = 'pending'")
                ->fetchColumn(),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($action === 'env') {
        echo json_encode([
            'gd' => function_exists('imagecreatetruecolor'),
            'exif' => function_exists('exif_read_data'),
            'imagick_heic' => zm_has_imagick_heic(),
            'can_convert_heic' => zm_can_convert(),
            'accepted' => zm_accepted_list(),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
/*
     * Statystyki ruchu — raport zagregowany plus ostatnie szczegóły wejść.
     * Endpoint jest za autoryzacją administratora; pełne IP są retencjonowane 30 dni.
     */
    if ($action === 'traffic_stats') {
        $rangeKey = (string) ($_GET['range'] ?? '30d');
        $from = zm_traffic_valid_date((string) ($_GET['from'] ?? ''));
        $to = zm_traffic_valid_date((string) ($_GET['to'] ?? ''));

        if ($from === null || $to === null) {
            $range = zm_traffic_range($rangeKey);
            $from = $range['from'];
            $to = $range['to'];
            $label = $range['label'];
        } else {
            $label = 'Zakres niestandardowy';
        }

        $report = zm_traffic_report($pdo, $from, $to);
        $report['ok'] = true;
        $report['label'] = $label;

        echo json_encode($report, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Rozbudowany panel statystyk (6 sekcji) — jedno żądanie zwraca komplet:
     * Przegląd, Ruch i odwiedzających, Zdjęcia i miejscowości, Rozwój
     * społeczności, SEO i źródła oraz Stan techniczny. Dane są liczone
     * wprost z bazy; sekcje odporne na braki (każda zwraca pustą strukturę
     * zamiast wywracać całość).
     */
    if ($action === 'gsc_connect') {
        if (!zm_gsc_ready()) {
            zm_fail(503, 'gsc_not_configured', ['message' => 'Uzupełnij dane OAuth Google w config.local.php.']);
        }
        try {
            header('Location: ' . zm_gsc_auth_url());
            exit;
        } catch (Throwable $e) {
            zm_fail(500, 'gsc_oauth_error', ['message' => 'Nie udało się rozpocząć autoryzacji Google.']);
        }
    }

    if ($action === 'gsc_report') {
        $range = (string) ($_GET['range'] ?? '28d');
        $forceRefresh = (string) ($_GET['refresh'] ?? '') === '1';
        echo json_encode(zm_gsc_report($range, $forceRefresh), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'stats_report') {
        $rangeKey = (string) ($_GET['range'] ?? '30d');
        $from = zm_traffic_valid_date((string) ($_GET['from'] ?? ''));
        $to = zm_traffic_valid_date((string) ($_GET['to'] ?? ''));

        if ($from === null || $to === null) {
            $range = zm_traffic_range($rangeKey);
            $from = $range['from'];
            $to = $range['to'];
            $label = $range['label'];
        } else {
            $label = 'Zakres niestandardowy';
        }

        // Porządki w dzienniku systemowym przy okazji przeglądu (retencja 180 dni).
        try {
            zm_system_prune($pdo, 180);
        } catch (Throwable $e) {
            // opcjonalne
        }

        // Licznik ruchu nie był dotąd przycinany — retencja 400 dni przy okazji przeglądu.
        try {
            zm_traffic_prune($pdo, 400);
        } catch (Throwable $e) {
            // opcjonalne
        }

        $report = zm_stats_report($pdo, $from, $to, $label);
        echo json_encode($report, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Przerwa techniczna — stan dla zakładki „Przerwa techniczna" panelu admina.
     * Zwraca konfigurację (enabled/message/since/by) i czy przerwa jest aktywna.
     */
    if ($action === 'maintenance_status') {
        echo json_encode([
            'ok'      => true,
            'config'  => zm_maintenance_config(),
            'active'  => zm_maintenance_active(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Centrum bezpieczeństwa — stan systemów dla zakładki „Bezpieczeństwo".
     * Zwraca: konfigurację, wynik audytu środowiska, ostatni test na żywo
     * (certyfikat TLS + nagłówki), dziennik zdarzeń, bany IP i statystyki.
     */
    if ($action === 'security_status') {
        $cfg = zm_security_config();

        // Dziennik nie może rosnąć w nieskończoność — przy okazji przeglądu
        // przycinamy wpisy starsze niż skonfigurowany okres retencji.
        try {
            zm_security_prune($pdo, (int) $cfg['log_retention_days']);
        } catch (Throwable $e) {
            // porządki są opcjonalne
        }

        $checks = zm_security_env_audit();
        $rollup = zm_security_rollup($checks);
        $last = zm_security_lastcheck();

        $stats = [
            'events_total' => 0,
            'events_24h'   => 0,
            'fails_24h'    => 0,
            'locked_24h'   => 0,
            'bans_active'  => 0,
            'bans_total'   => 0,
            'last_event'   => 0,
        ];
        $events = [];
        $bans = [];

        try {
            zm_ensure_security_schema($pdo);
            $cut = (int) round(microtime(true) * 1000) - 86400 * 1000;

            $stats['events_total'] = (int) $pdo->query('SELECT COUNT(*) FROM security_events')->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM security_events WHERE created_at >= :c');
            $q->execute(['c' => $cut]);
            $stats['events_24h'] = (int) $q->fetchColumn();

            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM security_events
                  WHERE created_at >= :c AND event IN ('login_fail', 'ip_blocked')"
            );
            $q->execute(['c' => $cut]);
            $stats['fails_24h'] = (int) $q->fetchColumn();

            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM security_events
                  WHERE created_at >= :c AND event IN ('login_locked', 'ip_banned')"
            );
            $q->execute(['c' => $cut]);
            $stats['locked_24h'] = (int) $q->fetchColumn();

            $stats['bans_active'] = (int) $pdo->query(
                'SELECT COUNT(*) FROM security_ip_bans WHERE banned_until > NOW()'
            )->fetchColumn();
            $stats['bans_total'] = (int) $pdo->query('SELECT COUNT(*) FROM security_ip_bans')->fetchColumn();
            $stats['last_event'] = (int) $pdo->query('SELECT COALESCE(MAX(created_at), 0) FROM security_events')->fetchColumn();

            $events = $pdo->query(
                'SELECT event, severity, username, ip, detail, created_at
                   FROM security_events ORDER BY id DESC LIMIT 60'
            )->fetchAll();

            $bans = $pdo->query(
                'SELECT ip, reason, hits, banned_until, updated_at
                   FROM security_ip_bans ORDER BY updated_at DESC LIMIT 100'
            )->fetchAll();
        } catch (Throwable $e) {
            // brak tabeli nie psuje widoku — pokażemy pusty dziennik
        }

        echo json_encode([
            'ok'         => true,
            'config'     => $cfg,
            'host'       => zm_security_host(),
            'https'      => zm_security_is_https(),
            'checks'     => $checks,
            'rollup'     => $rollup,
            'lastcheck'  => $last,
            'events'     => $events,
            'bans'       => $bans,
            'stats'      => $stats,
            'expected_headers' => zm_security_expected_headers(),
            'php_version' => PHP_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }


    if ($action === 'photo_stats') {
        $total = (int) $pdo
            ->query('SELECT COUNT(*) FROM photos')
            ->fetchColumn();

        $published = (int) $pdo
            ->query('SELECT COUNT(*) FROM photos WHERE published = 1')
            ->fetchColumn();

        $categories = (int) $pdo
            ->query('SELECT COUNT(*) FROM categories')
            ->fetchColumn();

        $unanalyzed = (int) $pdo
            ->query('SELECT COUNT(*) FROM photos WHERE edit_status IS NULL')
            ->fetchColumn();

        $pending = (int) $pdo
            ->query("SELECT COUNT(*) FROM photos WHERE moderation_status = 'pending'")
            ->fetchColumn();

        // Wnioski o nowe kategorie — osobny licznik, bo dotyczą drzewa kategorii,
        // a nie moderacji zdjęć.
        $pendingRequests = (int) $pdo
            ->query("SELECT COUNT(*) FROM category_requests WHERE status = 'pending'")
            ->fetchColumn();

        // Zgłaszający zdjęcia — lista dla filtra użytkownika w panelu.
        $submitters = $pdo
            ->query(
                "SELECT u.id, u.username
                 FROM users u
                 INNER JOIN photos p ON p.submitted_by_user_id = u.id
                 GROUP BY u.id, u.username
                 ORDER BY u.username"
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total' => $total,
            'published' => $published,
            'hidden' => $total - $published,
            'categories' => $categories,
            'unanalyzed' => $unanalyzed,
            'pending' => $pending,
            'pending_requests' => $pendingRequests,
            'submitters' => $submitters,
        ]);

        exit;
    }

    if ($action === 'photo_list') {
        $limit = max(
            1,
            min(200, (int) ($_GET['limit'] ?? 30))
        );

        $offset = max(
            0,
            (int) ($_GET['offset'] ?? 0)
        );

        $filter = zm_photo_filters($_GET, $pdo);
        $whereSql = $filter['whereSql'];
        $params = $filter['params'];

        /*
         * COUNT.
         */
        $countStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM photos p
             $whereSql"
        );

        foreach ($params as $key => $value) {
            $countStatement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        /*
         * LISTA.
         */
        $statement = $pdo->prepare(
            "SELECT
                p.*,
                c.name AS category_name,
                u.username AS submitted_by_username,
                r.status AS request_status, r.kind AS request_kind,
                r.child_name AS request_child_name, r.parent_name AS request_parent_name,
                (SELECT COUNT(*) FROM photos pr WHERE pr.category_request_id = r.id) AS request_photo_count
             FROM photos p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.submitted_by_user_id
             LEFT JOIN category_requests r ON r.id = p.category_request_id
             $whereSql
             ORDER BY p.id DESC
             LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = [];

        $rowsList = $statement->fetchAll();

        // liczba komentarzy na zdjęcie — jedno zapytanie zamiast podzapytania per wiersz
        $commentCounts = [];
        $attractionMap = [];
        if ($rowsList) {
            $pids = array_map(static fn(array $r): string => 'p' . (int) $r['id'], $rowsList);
            $marks = implode(',', array_fill(0, count($pids), '?'));
            $cst = $pdo->prepare(
                "SELECT photo_id, COUNT(*) AS n FROM comments WHERE photo_id IN ($marks) GROUP BY photo_id"
            );
            $cst->execute($pids);
            foreach ($cst->fetchAll() as $cr) {
                $commentCounts[(string) $cr['photo_id']] = (int) $cr['n'];
            }

            // etykiety atrakcji turystycznych dla całej strony zgłoszeń naraz
            $attractionMap = zm_attraction_photos_map(
                $pdo,
                array_map(static fn(array $r): int => (int) $r['id'], $rowsList)
            );
        }

        foreach ($rowsList as $row) {
            $mapUrl = null;

            if (
                $row['lat'] !== null
                && $row['lng'] !== null
            ) {
                $mapUrl = zm_osm_embed_url(
                    (float) $row['lat'],
                    (float) $row['lng']
                );
            } elseif (!empty($row['map_url'])) {
                $mapUrl = zm_legacy_map_value(
                    (string) $row['map_url']
                );
            }

            // km od Mogilna — także dla zdjęć bez lat/lng: punkt odtwarzamy
            // z geo_json albo z nazwy (geokodowanie cache'owane).
            if ($row['lat'] !== null && $row['lng'] !== null) {
                $kmFromMogilno = zm_geo_distance_km(
                    (float) $row['lat'],
                    (float) $row['lng'],
                    ZM_GEO_MOGILNO_LAT,
                    ZM_GEO_MOGILNO_LNG
                );
            } else {
                $kmFromMogilno = zm_geo_photo_km_from_mogilno($row);
            }

            $items[] = [
                'id' => (int) $row['id'],
                'pid' => 'p' . (int) $row['id'],
                'filename' => (string) $row['filename'],
                'title' => (string) $row['title'],
                'description' => (string) ($row['description'] ?? ''),
                'category_id' => $row['category_id'] !== null
                    ? (int) $row['category_id']
                    : null,
                'category_name' => $row['category_name'],
                'place' => (string) ($row['place'] ?? ''),
                'map_url' => $mapUrl,
                'taken_at' => $row['taken_at'],
                'lat' => $row['lat'] !== null
                    ? (float) $row['lat']
                    : null,
                'lng' => $row['lng'] !== null
                    ? (float) $row['lng']
                    : null,
                'published' => (int) $row['published'],
                'moderation_status' => (string) ($row['moderation_status'] ?? 'approved'),
                'submitted_by_username' => $row['submitted_by_username'] ?? null,
                'publish_at' => $row['publish_at'] ?? null,
                'edit_status' => $row['edit_status'] ?? null,
                'edit_note' => (string) ($row['edit_note'] ?? ''),
                'edit_manual' => (int) ($row['edit_manual'] ?? 0),
                'gmina_detected' => (string) ($row['gmina_detected'] ?? ''),
                'geo_status' => (string) ($row['geo_status'] ?? ''),
                'kmFromMogilno' => $kmFromMogilno !== null ? (float) round($kmFromMogilno, 1) : null,
                'geo' => zm_geo_row_summary((string) ($row['geo_json'] ?? '')),
                'request' => $row['category_request_id'] !== null
                    ? [
                        'id' => (int) $row['category_request_id'],
                        'status' => (string) ($row['request_status'] ?? ''),
                        'kind' => (string) ($row['request_kind'] ?? ''),
                        'child_name' => (string) ($row['request_child_name'] ?? ''),
                        'parent_name' => (string) ($row['request_parent_name'] ?? ''),
                        'photo_count' => (int) ($row['request_photo_count'] ?? 0),
                    ]
                    : null,
                'camera' => zm_camera_label((string) ($row['exif_json'] ?? '')),
                // pełny odczyt EXIF — okno „Sprawdź i zatwierdź”
                'exif' => zm_exif_payload((string) ($row['exif_json'] ?? '')),
                'views' => (int) ($row['views'] ?? 0),
                'favorites' => (int) ($row['favorites'] ?? 0),
                'comments' => $commentCounts['p' . (int) $row['id']] ?? 0,
                'has_location' => ($row['lat'] !== null && $row['lng'] !== null) || $mapUrl !== null,
                // etykiety atrakcji turystycznej zgłoszone przez użytkownika
                'attractions' => $attractionMap[(int) $row['id']] ?? [],
                'width' => $row['width'] !== null
                    ? (int) $row['width']
                    : null,
                'height' => $row['height'] !== null
                    ? (int) $row['height']
                    : null,
                'thumb' => zm_thumb_url(
                    'p' . (int) $row['id'],
                    320
                ),
                'missing' => zm_photo_path(
                    (string) $row['filename']
                ) === null,
            ];
        }

        echo json_encode([
            'total' => $total,
            'items' => $items,
            // katalog etykiet atrakcji — panel buduje z niego listę do dodawania
            'attraction_labels' => zm_attraction_labels(),
            // limit kategorii na zdjęcie — panel blokuje nadmiarowe w moderacji/edycji
            'attraction_max_labels' => zm_attraction_max_labels(),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($action === 'photo_list_ids') {
        // „zaznacz wyniki”: wszystkie id pasujące do aktywnych filtrów,
        // bez paginacji — żeby zaznaczyć zdjęcia danego użytkownika jednym
        // kliknięciem, nawet gdy lista jest podzielona na strony.
        $filter = zm_photo_filters($_GET, $pdo);
        $statement = $pdo->prepare(
            "SELECT p.id FROM photos p {$filter['whereSql']} ORDER BY p.id DESC"
        );
        foreach ($filter['params'] as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();

        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        echo json_encode([
            'ok' => true,
            'ids' => $ids,
            'total' => count($ids),
        ]);
        exit;
    }

    if ($action === 'task_list') {
        echo json_encode(zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'project_list') {
        echo json_encode(zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'blocked_list') {
        echo json_encode(zm_blocked_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'blocked_words_list') {
        echo json_encode(zm_blocked_words_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'comment_reports_list') {
        echo json_encode(zm_comment_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'photo_reports_list') {
        echo json_encode(zm_photo_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rank_list') {
        echo json_encode(zm_admin_rank_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Alerty dla administracji (wiersze notifications z user_id = NULL) oraz
     * pełna konfiguracja typów powiadomień dla okna „Powiadomienia".
     *
     * Zwykłe powiadomienia użytkowników (user_id ≠ NULL) celowo NIE trafiają
     * tutaj — to ich prywatna skrzynka, administrator nie ma do niej wglądu.
     */
    if ($action === 'notify_admin_alerts') {
        $onlyUnread = !empty($_GET['unread']);
        $limit = max(1, min(300, (int) ($_GET['limit'] ?? 100)));

        $where = 'n.user_id IS NULL';
        if ($onlyUnread) {
            $where .= ' AND n.is_read = 0';
        }
        $sql = 'SELECT n.id, n.actor_id, n.type, n.ref_type, n.ref_id, n.body,
                       n.is_read, n.created_at,
                       a.username AS actor_username, a.display_name AS actor_display_name
                  FROM notifications n
                  LEFT JOIN users a ON a.id = n.actor_id
                 WHERE ' . $where . '
              ORDER BY n.id DESC
                 LIMIT ' . $limit;

        try {
            $st = $pdo->prepare($sql);
            $st->execute();
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }

        $cfg = zm_notify_config($pdo);
        $items = [];
        foreach ($rows as $r) {
            $type = (string) $r['type'];
            $c = $cfg[$type] ?? ['label' => $type, 'icon' => 'bell', 'desc' => ''];
            $actorId = $r['actor_id'] !== null ? (int) $r['actor_id'] : 0;
            $items[] = [
                'id'      => (int) $r['id'],
                'type'    => $type,
                'label'   => (string) $c['label'],
                'icon'    => (string) $c['icon'],
                'body'    => (string) ($r['body'] ?? ''),
                'isRead'  => (int) $r['is_read'] === 1,
                'ts'      => (int) $r['created_at'],
                'refType' => $r['ref_type'] !== null ? (string) $r['ref_type'] : null,
                'refId'   => $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
                'actor'   => $actorId > 0 ? [
                    'id'       => $actorId,
                    'name'     => trim((string) ($r['actor_display_name'] ?? '')) !== ''
                        ? (string) $r['actor_display_name']
                        : (string) ($r['actor_username'] ?? ''),
                    'username' => (string) ($r['actor_username'] ?? ''),
                    'url'      => zm_profile_path((string) ($r['actor_username'] ?? ''), $actorId),
                ] : null,
            ];
        }

        $unread = 0;
        try {
            $unread = (int) $pdo->query(
                'SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND is_read = 0'
            )->fetchColumn();
        } catch (Throwable $e) {
            $unread = 0;
        }

        echo json_encode([
            'ok' => true,
            'items' => $items,
            'unread' => $unread,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* Konfiguracja wszystkich typów + statystyki, żeby panel widział, co działa. */
    if ($action === 'notify_config') {
        $cfg = zm_notify_config($pdo);

        $stats = [];
        try {
            $rows = $pdo->query(
                'SELECT type, COUNT(*) AS n, SUM(is_read = 0) AS unread
                   FROM notifications GROUP BY type'
            )->fetchAll();
            foreach ($rows as $r) {
                $stats[(string) $r['type']] = [
                    'total' => (int) $r['n'],
                    'unread' => (int) $r['unread'],
                ];
            }
        } catch (Throwable $e) {
            // brak tabeli — pokażemy same typy
        }

        $types = [];
        foreach ($cfg as $type => $c) {
            $types[] = [
                'type'        => $type,
                'label'       => $c['label'],
                'desc'        => $c['desc'],
                'icon'        => $c['icon'],
                'enabled'     => $c['enabled'],
                'to_owner'    => $c['to_owner'],
                'to_followers'=> $c['to_followers'],
                'to_admin'    => $c['to_admin'],
                'user_manage' => $c['user_manage'],
                'total'       => $stats[$type]['total'] ?? 0,
                'unread'      => $stats[$type]['unread'] ?? 0,
            ];
        }

        echo json_encode([
            'ok' => true,
            'types' => $types,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'users_list') {
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = zm_admin_users_list($pdo, $_GET, $perPage, ($page - 1) * $perPage);
        echo json_encode([
            'items' => $res['items'],
            'total' => $res['total'],
            'page' => $page,
            'pages' => $res['total'] === 0 ? 0 : (int) ceil($res['total'] / $perPage),
            'ranks' => zm_admin_rank_payload($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'user_detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            zm_fail(400, 'invalid');
        }

        $base = zm_admin_users_base($pdo, ['u.id = :id'], ['id' => $id]);
        $stmt = $pdo->prepare($base[0]);
        $stmt->execute($base[1]);
        $row = $stmt->fetch();

        if (!$row) {
            zm_fail(404, 'not_found');
        }

        $counters = [];
        foreach (['photos', 'comments', 'likes'] as $k) {
            $counters[$k] = (int) $row[$k];
        }
        $row['rank_override'] = $row['rank_override'] === null ? null : (int) $row['rank_override'];
        $row['special_rank'] = $row['special_rank'] === null ? null : (int) $row['special_rank'];
        $rank = zm_user_rank($pdo, $row);
        $spRank = zm_user_rank_special($pdo, $row);

        $hist = $pdo->prepare(
            'SELECT h.id, h.delta, h.points_after, h.action, h.ref_type, h.ref_id, h.note, h.created_at,
                    ad.username AS admin_name
             FROM points_history h
             LEFT JOIN users ad ON ad.id = h.admin_id
             WHERE h.user_id = :id
             ORDER BY h.id DESC LIMIT 200'
        );
        $hist->execute(['id' => $id]);

        echo json_encode([
            'ok' => true,
            'user' => [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'role' => (string) $row['role'],
                'points' => (int) $row['points'],
                'rank_id' => (int) $rank['id'],
                'rank_name' => (string) $rank['name'],
                'rank_manual' => (bool) $rank['manual'],
                'rank_special' => $spRank !== null,
                'rank_special_id' => $spRank === null ? null : (int) $spRank['id'],
                'rank_special_name' => $spRank === null ? null : (string) $spRank['name'],
                'counters' => $counters,
                'banned' => (int) $row['is_banned'] === 1,
                'banned_at' => $row['banned_at'] !== null ? (string) $row['banned_at'] : null,
                'banned_by' => $row['banned_by'] === null ? null : (int) $row['banned_by'],
                'created_at' => (string) $row['created_at'],
            ],
            'history' => array_map(static function (array $h): array {
                return [
                    'id' => (int) $h['id'],
                    'delta' => (int) $h['delta'],
                    'points_after' => (int) $h['points_after'],
                    'action' => (string) $h['action'],
                    'ref_type' => $h['ref_type'],
                    'ref_id' => $h['ref_id'] === null ? null : (int) $h['ref_id'],
                    'note' => $h['note'],
                    'admin_name' => $h['admin_name'],
                    'created_at' => (string) $h['created_at'],
                ];
            }, $hist->fetchAll()),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Konfiguracja systemu atrakcji turystycznych dla zakładki „Atrakcje".
     * Zwraca pełny, przycięty zestaw (etykiety + sekcja + tryb + przełączniki)
     * oraz liczbę zdjęć aktualnie oznaczonych i opublikowanych — dla podglądu.
     */
    if ($action === 'attraction_config') {
        $cfg = zm_attraction_config();

        $used = 0;
        $usedLabels = [];
        try {
            $used = (int) $pdo->query(
                'SELECT COUNT(DISTINCT a.photo_id)
                   FROM photo_attractions a
                   JOIN photos p ON p.id = a.photo_id
                  WHERE p.published = 1 AND p.moderation_status = \'approved\''
            )->fetchColumn();

            $stmt = $pdo->query(
                'SELECT a.label, COUNT(*) AS n
                   FROM photo_attractions a
                   JOIN photos p ON p.id = a.photo_id
                  WHERE p.published = 1 AND p.moderation_status = \'approved\'
                  GROUP BY a.label'
            );
            foreach ($stmt->fetchAll() as $row) {
                $usedLabels[(string) $row['label']] = (int) $row['n'];
            }
        } catch (Throwable $e) {
            // podgląd jest opcjonalny
        }

        $labels = [];
        foreach ($cfg['labels'] as $label) {
            $labels[] = [
                'key'     => $label['key'],
                'name'    => $label['name'],
                'icon'    => $label['icon'] ?? '',
                'desc'    => $label['desc'] ?? '',
                'default' => (bool) $label['default'],
                'used'    => $usedLabels[$label['key']] ?? 0,
            ];
        }
        $cfg['labels'] = $labels;

        echo json_encode([
            'ok'       => true,
            'config'   => $cfg,
            'labeled'  => $used,
            'modes'    => ['latest', 'random', 'top'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * Edytowalne teksty strony głównej (Ustawienia → Edycja tekstów na stronie).
     * texts_list: wszystkie teksty z wartością bieżącą i domyślną.
     * texts_history: ostatnie wersje jednego tekstu (?key=hero.eyebrow).
     */
    if ($action === 'texts_list') {
        echo json_encode(['ok' => true] + zm_texts_payload($pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'texts_history') {
        $key = (string) ($_GET['key'] ?? '');
        if (!isset(zm_texts_defs()[$key])) {
            zm_fail(400, 'unknown_key');
        }
        echo json_encode(['ok' => true, 'key' => $key, 'history' => zm_texts_history($pdo, $key)], JSON_UNESCAPED_UNICODE);
        exit;
    }


    if ($action === 'link_list') {
        $rows = $pdo->query('SELECT id,title,url,description,tags,is_active,position,created_at,updated_at FROM admin_links ORDER BY position ASC,id ASC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id']=(int)$row['id']; $row['position']=(int)$row['position']; $row['is_active']=(bool)$row['is_active'];
            $decoded=json_decode((string)($row['tags']??'[]'),true); $row['tags']=is_array($decoded)?$decoded:[];
        }
        unset($row);
        $tagCounts = [];
        foreach ($rows as $row) foreach ($row['tags'] as $tag) $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
        ksort($tagCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $activeCount = 0;
        foreach ($rows as $row) if ($row['is_active']) $activeCount++;
        echo json_encode([
            'items' => $rows,
            'count' => count($rows),
            'stats' => ['total' => count($rows), 'active' => $activeCount, 'tags' => count($tagCounts), 'tag_counts' => $tagCounts]
        ], JSON_UNESCAPED_UNICODE); exit;
    }

    zm_fail(400, 'unknown_action');
}

/* =============================================================== POST === */

if ($method !== 'POST') {
    zm_fail(405, 'method_not_allowed');
}

$isMultipart = strpos(
    (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'multipart/form-data'
) === 0;

if ($isMultipart) {
    $data = $_POST;
} else {
    $raw = (string) file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    $data = is_array($decoded) ? $decoded : $_POST;
}

if (!zm_csrf_check((string) ($data['csrf'] ?? ''))) {
    zm_fail(403, 'bad_csrf');
}

$action = (string) ($data['action'] ?? '');


/* ============================== prywatny katalog przydatnych linków */
if ($action === 'link_preview') {
    $url = trim((string) ($data['url'] ?? ''));
    if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        zm_fail(422, 'bad_url', ['message' => 'Podaj prawidłowy adres strony.']);
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true)
        || isset($parts['user']) || isset($parts['pass'])) {
        zm_fail(422, 'bad_url', ['message' => 'Dozwolone są publiczne adresy HTTP i HTTPS.']);
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
    $publicIp = '';
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $publicIp = $ip; break;
        }
    }
    if ($publicIp === '') zm_fail(422, 'url_not_public', ['message' => 'Nie można bezpiecznie pobrać danych z tego adresu.']);
    if (!function_exists('curl_init')) zm_fail(503, 'curl_unavailable', ['message' => 'Serwer nie udostępnia obsługi pobierania stron (cURL).']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'zMogilna-LinkPreview/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
        CURLOPT_ENCODING => '',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $publicIp],
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$html) {
            if (strlen($html) + strlen($chunk) > 1048576) return 0;
            $html .= $chunk; return strlen($chunk);
        },
    ]);
    $html = '';
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($ok === false || $status < 200 || $status >= 400 || stripos($contentType, 'html') === false) {
        zm_fail(422, 'page_unavailable', ['message' => 'Nie udało się odczytać strony. Możesz wpisać nazwę i opis ręcznie.']);
    }
    $meta = [];
    if (preg_match_all('~<meta\b[^>]*>~i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (!preg_match('~\b(?:name|property)\s*=\s*([\'"])(.*?)\1~i', $tag, $key)) continue;
            if (!preg_match('~\bcontent\s*=\s*([\'"])(.*?)\1~is', $tag, $value)) continue;
            $meta[strtolower(trim($key[2]))] = html_entity_decode($value[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $pageTitle = '';
    if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $html, $match)) {
        $pageTitle = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $title = trim((string) ($meta['og:title'] ?? $meta['twitter:title'] ?? $pageTitle));
    $description = trim((string) ($meta['description'] ?? $meta['og:description'] ?? $meta['twitter:description'] ?? ''));
    $title = preg_replace('/\s+/u', ' ', strip_tags($title)) ?? '';
    $description = preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '';
    echo json_encode(['ok' => true, 'title' => mb_substr($title, 0, 120), 'description' => mb_substr($description, 0, 1000)], JSON_UNESCAPED_UNICODE);
    exit;
}


if ($action === 'link_save') {
    $id=(int)($data['id']??0); $title=trim((string)($data['title']??'')); $url=trim((string)($data['url']??''));
    $description=trim((string)($data['description']??'')); $active=!empty($data['is_active'])?1:0;
    if($title===''||mb_strlen($title)>120) zm_fail(422,'bad_title',['message'=>'Nazwa musi mieć od 1 do 120 znaków.']);
    if($url===''||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)||!parse_url($url,PHP_URL_HOST)) zm_fail(422,'bad_url',['message'=>'Podaj prawidłowy adres URL zaczynający się od https:// lub http://.']);
    if(mb_strlen($description)>1000) zm_fail(422,'bad_description',['message'=>'Opis może mieć maksymalnie 1000 znaków.']);
    $rawTags=$data['tags']??[]; if(is_string($rawTags)) $rawTags=preg_split('/[\s,]+/u',$rawTags,-1,PREG_SPLIT_NO_EMPTY); if(!is_array($rawTags)) $rawTags=[];
    $tags=[];
    foreach($rawTags as $rawTag){$tag=mb_strtolower(trim((string)$rawTag));$tag=ltrim($tag,'#');$tag=preg_replace('/[^\p{L}\p{N}_-]+/u','',$tag)??'';if($tag!==''&&mb_strlen($tag)<=32&&!in_array($tag,$tags,true))$tags[]=$tag;if(count($tags)>=20)break;}
    $tagsJson=json_encode($tags,JSON_UNESCAPED_UNICODE);
    if($id>0){$check=$pdo->prepare('SELECT id FROM admin_links WHERE id=:id');$check->execute(['id'=>$id]);if(!$check->fetchColumn())zm_fail(404,'link_not_found');
      $st=$pdo->prepare('UPDATE admin_links SET title=:title,url=:url,description=:description,tags=:tags,is_active=:active WHERE id=:id');
      $st->execute(['title'=>$title,'url'=>$url,'description'=>$description,'tags'=>$tagsJson,'active'=>$active,'id'=>$id]);
    }else{$position=(int)$pdo->query('SELECT COALESCE(MAX(position),0)+1 FROM admin_links')->fetchColumn();
      $st=$pdo->prepare('INSERT INTO admin_links(title,url,description,tags,is_active,position) VALUES(:title,:url,:description,:tags,:active,:position)');
      $st->execute(['title'=>$title,'url'=>$url,'description'=>$description,'tags'=>$tagsJson,'active'=>$active,'position'=>$position]);$id=(int)$pdo->lastInsertId();}
    echo json_encode(['ok'=>true,'id'=>$id],JSON_UNESCAPED_UNICODE);exit;
}
if ($action === 'link_delete') {
    $id=(int)($data['id']??0); if($id<=0)zm_fail(400,'invalid');
    $st=$pdo->prepare('DELETE FROM admin_links WHERE id=:id');$st->execute(['id'=>$id]);
    echo json_encode(['ok'=>true,'deleted'=>$st->rowCount()],JSON_UNESCAPED_UNICODE);exit;
}
if ($action === 'link_reorder') {
    $ids=$data['ids']??null;if(!is_array($ids)||count($ids)>1000)zm_fail(422,'bad_order');
    $clean=[];foreach($ids as $raw){$id=(int)$raw;if($id>0&&!in_array($id,$clean,true))$clean[]=$id;}
    $pdo->beginTransaction();
    try{$update=$pdo->prepare('UPDATE admin_links SET position=:position WHERE id=:id');foreach($clean as $index=>$id)$update->execute(['position'=>$index+1,'id'=>$id]);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}

/* ======================================================= przerwa techniczna */

// Włączenie / wyłączenie przerwy technicznej (blokada strony dla odwiedzających
// + ekran 503 z oknem logowania). CSRF sprawdzany wyżej jak dla całej sekcji POST.
if ($action === 'maintenance_save') {
    $enabled = !empty($data['enabled']);
    $message = (string) ($data['message'] ?? '');

    $before = zm_maintenance_active();
    $cfg = zm_maintenance_save($enabled, $message, (string) $admin['username']);
    $after = zm_maintenance_active();

    if ($before !== $after) {
        zm_security_event(
            'admin_action',
            'info',
            (string) $admin['username'],
            'przerwa techniczna: ' . ($after ? 'włączona' : 'wyłączona')
        );
        try {
            zm_log($pdo, $after ? 'maintenance_on' : 'maintenance_off', (string) $admin['username'], '', 'przerwa techniczna');
        } catch (Throwable $e) {
            // dziennik panelu jest pomocniczy — nie przerywamy odpowiedzi
        }
    }

    echo json_encode(['ok' => true, 'config' => $cfg, 'active' => $after], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==================================================== centrum bezpieczeństwa */

// Zapis konfiguracji centrum bezpieczeństwa (HTTPS, HSTS, limity logowania).
if ($action === 'security_save') {
    $patch = [];
    foreach (zm_security_defaults() as $key => $def) {
        if (array_key_exists($key, $data)) {
            $patch[$key] = $data[$key];
        }
    }
    $before = zm_security_config();
    $cfg = zm_security_config_save($patch);

    $changed = [];
    foreach ($cfg as $key => $value) {
        if (($before[$key] ?? null) !== $value) {
            $changed[] = $key;
        }
    }
    if ($changed) {
        zm_security_event('config_change', 'warn', (string) $admin['username'], 'zmiana: ' . implode(', ', $changed));
    }

    echo json_encode(['ok' => true, 'config' => $cfg, 'changed' => $changed], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==================================================== konfiguracja atrakcji */

// Zapis konfiguracji systemu atrakcji (etykiety, sekcja, tryb, przełączniki).
if ($action === 'attraction_save') {
    $patch = [];
    foreach (zm_attraction_defaults() as $key => $def) {
        if (array_key_exists($key, $data)) {
            $patch[$key] = $data[$key];
        }
    }
    $before = zm_attraction_config();
    $cfg = zm_attraction_config_save($patch);

    $changed = [];
    foreach ($cfg as $key => $value) {
        if ($key === 'labels') {
            $beforeKeys = array_column($before['labels'], 'key');
            $afterKeys = array_column($value, 'key');
            if ($beforeKeys !== $afterKeys || $before['labels'] !== $value) {
                $changed[] = 'labels';
            }
            continue;
        }
        if (($before[$key] ?? null) !== $value) {
            $changed[] = $key;
        }
    }

    if ($changed) {
        zm_log($pdo, 'attraction_config', (string) $admin['username'], '', 'zmiana: ' . implode(', ', $changed));
    }

    echo json_encode(['ok' => true, 'config' => $cfg, 'changed' => $changed], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================ teksty strony */

// Zapis zmian tekstów: {changes: {klucz: wartość}}. Pusta wartość lub równa
// domyślnej = powrót do tekstu domyślnego. Wszystko albo nic (walidacja z góry).
if ($action === 'texts_save') {
    $changes = $data['changes'] ?? null;
    if (!is_array($changes) || $changes === []) {
        zm_fail(400, 'no_changes');
    }

    $result = zm_texts_save($pdo, $changes, (string) $admin['username']);
    if ($result['errors']) {
        zm_fail(422, 'invalid_texts', ['fields' => $result['errors']]);
    }

    if ($result['changed']) {
        try {
            zm_log($pdo, 'texts_save', (string) $admin['username'], '', 'teksty: ' . implode(', ', $result['changed']));
        } catch (Throwable $e) {
            // dziennik panelu jest pomocniczy
        }
    }

    echo json_encode(
        ['ok' => true, 'changed' => $result['changed']] + zm_texts_payload($pdo),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// Test na żywo: certyfikat TLS + nagłówki odpowiedzi (loopback do serwera).
if ($action === 'security_test') {
    $last = zm_security_run_check();
    echo json_encode(['ok' => true, 'lastcheck' => $last], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ręczna blokada adresu IP.
if ($action === 'security_ban_ip') {
    $ip = trim((string) ($data['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        zm_fail(422, 'bad_ip', ['message' => 'Podaj poprawny adres IP.']);
    }
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'ręczna blokada z panelu';
    }
    $seconds = (int) ($data['seconds'] ?? 3600);
    zm_security_ban_ip($pdo, $ip, $reason, $seconds);
    zm_security_event('admin_action', 'warn', (string) $admin['username'], 'ręczny ban IP ' . $ip);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Zdjęcie blokady IP.
if ($action === 'security_unban_ip') {
    $ip = trim((string) ($data['ip'] ?? ''));
    if ($ip === '') {
        zm_fail(422, 'bad_ip', ['message' => 'Brak adresu IP.']);
    }
    zm_security_unban_ip($pdo, $ip);
    zm_security_event('admin_action', 'info', (string) $admin['username'], 'zdjęto blokadę IP ' . $ip);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Czyszczenie dziennika zdarzeń (całościowo albo starszych niż N dni).
if ($action === 'security_clear_events') {
    $days = (int) ($data['days'] ?? 0);
    $removed = 0;
    try {
        zm_ensure_security_schema($pdo);
        if ($days > 0) {
            $removed = zm_security_prune($pdo, $days);
        } else {
            $removed = (int) $pdo->exec('DELETE FROM security_events');
        }
    } catch (Throwable $e) {
        zm_fail(500, 'clear_failed', ['message' => 'Nie udało się wyczyścić dziennika.']);
    }
    zm_security_event('admin_action', 'info', (string) $admin['username'], 'wyczyszczono dziennik (usunięto ' . $removed . ')');
    echo json_encode(['ok' => true, 'removed' => $removed], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------- photo_upload */

if ($action === 'photo_upload') {
    if (!is_dir(PHOTOS_DIR)) {
        @mkdir(PHOTOS_DIR, 0775, true);
    }

    if (
        empty($_FILES['files'])
        || empty($_FILES['files']['name'])
        || !is_array($_FILES['files']['name'])
    ) {
        zm_fail(400, 'no_files');
    }

    $categoryId = ($data['category_id'] ?? '') !== ''
        ? (int) $data['category_id']
        : null;

    if ($categoryId !== null) {
        $check = $pdo->prepare(
            'SELECT id FROM categories WHERE id = :id'
        );

        $check->execute(['id' => $categoryId]);

        if ($check->fetchColumn() === false) {
            $categoryId = null;
        }
    }

    $place = trim((string) ($data['place'] ?? ''));

    /*
     * Frontend wysyła współrzędne w polu coordinates.
     * Dla kompatybilności obsługujemy też osobne lat/lng.
     */
    $coordinates = trim((string) ($data['coordinates'] ?? ''));

    if ($coordinates === '') {
        $rawLat = trim((string) ($data['lat'] ?? ''));
        $rawLng = trim((string) ($data['lng'] ?? ''));

        if ($rawLat !== '' || $rawLng !== '') {
            $coordinates = $rawLat . ',' . $rawLng;
        }
    }

    [$lat, $lng] = zm_parse_coordinates($coordinates);

    $published = isset($data['published'])
        ? (int) (bool) $data['published']
        : 1;

    // planowanie publikacji (gdy "opublikuj od razu" jest odznaczone)
    [$published, $publishAt] = zm_resolve_publish(
        (bool) $published,
        zm_parse_publish_at((string) ($data['publish_at'] ?? ''))
    );

    // wspólna data zdjęcia dla całej paczki (puste = EXIF z pliku)
    $userTakenAt = trim((string) ($data['taken_at'] ?? ''));
    if ($userTakenAt !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $userTakenAt)) {
        zm_fail(400, 'bad_date');
    }
    if ($userTakenAt === '') {
        $userTakenAt = null;
    }

    $added = [];
    $skipped = [];
    $count = count($_FILES['files']['name']);

    // Jedno zdjęcie na raz. Panel ma jedno pole, ale backend nie ufa przeglądarce:
    // gdyby ktoś wysłać kilka plików, bierzemy pierwszy i mówimy wprost, że reszta
    // została pominięta. Bez limitu rozmiaru w bajtach — limity ustawia php.ini
    // (post_max_size / upload_max_filesize), a aplikacja nie dokłada własnego.
    if ($count > 1) {
        $skipped[] = 'wybrano ' . $count . ' plików — dodajemy tylko pierwsze zdjęcie';
        $count = 1;
    }

    $insert = $pdo->prepare(
        'INSERT INTO photos
            (
                filename,
                kind,
                title,
                description,
                category_id,
                place,
                taken_at,
                published,
                publish_at,
                submitted_by_user_id,
                width,
                height,
                filesize,
                lat,
                lng,
                gmina_detected,
                geo_status,
                geo_json,
                edit_status,
                edit_note,
                exif_json
            )
         VALUES
             (
                :filename,
                :kind,
                :title,
                :description,
                :category_id,
                :place,
                :taken_at,
                :published,
                :publish_at,
                :uid,
                :width,
                :height,
                :filesize,
                :lat,
                :lng,
                :gmina_detected,
                :geo_status,
                :geo_json,
                :edit_status,
                :edit_note,
                :exif_json
            )'
    );

    for ($i = 0; $i < $count; $i++) {
        $originalName = (string) $_FILES['files']['name'][$i];
        $uploadError = (int) $_FILES['files']['error'][$i];

        if ($uploadError !== UPLOAD_ERR_OK) {
            $skipped[] = $originalName . ' (błąd wysyłki)';
            zm_system_event($pdo, 'upload_error', 'admin: błąd wysyłki (' . $uploadError . ') ' . basename($originalName));
            continue;
        }

        $temporaryFile = (string) $_FILES['files']['tmp_name'][$i];

        $needsConvert = zm_needs_convert($originalName);

        if (zm_kind($originalName) === null && !$needsConvert) {
            $skipped[] = $originalName . ' (nieobsługiwany format)';
            continue;
        }

        $width = null;
        $height = null;
        $takenAt = null;
        $wasConverted = false;
        $kind = 'image';

        if ($needsConvert) {
            if (!zm_can_convert()) {
                $skipped[] = $originalName . ' (brak konwertera HEIC)';
                continue;
            }

            $filename = zm_safe_filename(
                pathinfo($originalName, PATHINFO_FILENAME) . '.jpg'
            );

            $destination = PHOTOS_DIR . '/' . $filename;

            if (!zm_convert_to_jpeg($temporaryFile, $destination)) {
                @unlink($destination);
                $skipped[] = $originalName . ' (konwersja nieudana)';
                zm_system_event($pdo, 'upload_error', 'admin: konwersja HEIC nieudana ' . basename($originalName));
                continue;
            }

            @chmod($destination, 0644);

            // konwerter gubi EXIF — przenosimy go z oryginalnego HEIC do JPEG
            zm_carry_exif_from_heic($temporaryFile, $destination);

            $dimensions = @getimagesize($destination);

            if (is_array($dimensions)) {
                $width = (int) $dimensions[0];
                $height = (int) $dimensions[1];
            }

            $takenAt = zm_exif_date($destination);
            $kind = 'image';
            $wasConverted = true;
        } else {
            $dimensions = @getimagesize($temporaryFile);

            if ($dimensions === false) {
                $skipped[] = $originalName . ' (to nie jest zdjęcie)';
                continue;
            }

            $filename = zm_safe_filename($originalName);
            $destination = PHOTOS_DIR . '/' . $filename;

            if (!@move_uploaded_file($temporaryFile, $destination)) {
                $skipped[] = $originalName . ' (nie udało się zapisać)';
                zm_system_event($pdo, 'upload_error', 'admin: nie udało się zapisać pliku ' . basename($originalName));
                continue;
            }

            @chmod($destination, 0644);

            $width = (int) $dimensions[0];
            $height = (int) $dimensions[1];
            $takenAt = zm_exif_date($destination);
        }

        if ($userTakenAt !== null) {
            $takenAt = $userTakenAt;
        }

        // ocena, czy zdjęcie było modyfikowane (EXIF/XMP) + dane aparatu do okna „i”
        try {
            $analysis = zm_analyze_file($destination, $wasConverted);
        } catch (Throwable $e) {
            $analysis = ['edit_status' => null, 'edit_note' => null, 'exif_json' => null, 'coords' => null];
        }

        // Współrzędne: wklejony link ma pierwszeństwo, w razie braku — GPS z EXIF.
        $fileCoords = $analysis['coords'] ?? null;
        $effLat = $lat ?? ($fileCoords['lat'] ?? null);
        $effLng = $lng ?? ($fileCoords['lng'] ?? null);

        // Rozpoznanie gminy: dopasowanie kategorii albo opis brakującej.
        $geo = ($effLat !== null && $effLng !== null)
            ? zm_geo_classify($pdo, (float) $effLat, (float) $effLng)
            : zm_geo_classify($pdo, null, null);

        $finalCategoryId = $categoryId ?? $geo['category_id'];

        // Admin nie jest związany formularzem zgody — brakującą kategorię
        // (gmina i/lub miejscowość) zakładamy od razu, w tym samym uploadem.
        $created = [];
        if ($categoryId === null && $geo['need'] !== null && !empty($data['auto_categories'])) {
            $need = $geo['need'];
            $gmina = trim((string) $geo['gmina']);

            if ($need['kind'] === 'gmina') {
                // Brakuje samej gminy — zakładamy kategorię główną i na tym
                // kończymy. child_name jest wtedy tą samą nazwą, więc nie wolno
                // zakładać pod nią podkategorii o tej samej nazwie.
                if ($gmina !== '') {
                    $newGmina = zm_geo_ensure_gmina_category($pdo, $gmina);
                    if ($newGmina !== null) {
                        $finalCategoryId = $newGmina;
                        $created[] = [
                            'kind' => 'gmina',
                            'id' => $newGmina,
                            'name' => zm_category_name_by_id($pdo, $newGmina),
                        ];
                    }
                }
            } else {
                $childName = trim((string) $need['child_name']);
                $parentId = $need['parent_id'] !== null ? (int) $need['parent_id'] : null;

                if ($parentId === null && $gmina !== '') {
                    $newParent = zm_geo_ensure_gmina_category($pdo, $gmina);
                    if ($newParent !== null) {
                        $parentId = $newParent;
                        $created[] = [
                            'kind' => 'gmina',
                            'id' => $newParent,
                            'name' => zm_category_name_by_id($pdo, $newParent),
                        ];
                    }
                }

                if ($parentId !== null && $childName !== '') {
                    $newChild = zm_geo_ensure_child_category($pdo, $parentId, $childName);
                    if ($newChild !== null) {
                        $finalCategoryId = $newChild;
                        $created[] = [
                            'kind' => 'place',
                            'id' => $newChild,
                            'name' => zm_category_name_by_id($pdo, $newChild),
                        ];
                    }
                }
            }
        }

        $geoPlace = trim((string) ($geo['miejscowosc'] ?? ''));
        $geoGmina = trim((string) ($geo['gmina'] ?? ''));
        $photoTitle = $geoPlace !== ''
            ? $geoPlace
            : ($geoGmina !== ''
                ? $geoGmina
                : ($place !== '' ? $place : pathinfo($originalName, PATHINFO_FILENAME)));

        $insert->execute([
            'filename' => $filename,
            'kind' => $kind,
            'title' => $photoTitle,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'category_id' => $finalCategoryId,
            'place' => $place !== '' ? $place : null,
            'taken_at' => $takenAt,
            'published' => $published,
            'publish_at' => $publishAt,
            'uid' => $admin['id'],
            'width' => $width,
            'height' => $height,
            'filesize' => (int) @filesize($destination),
            'lat' => $effLat,
            'lng' => $effLng,
            'gmina_detected' => $geo['gmina'] !== '' ? $geo['gmina'] : null,
            'geo_status' => $created === [] ? $geo['status'] : 'matched',
            'geo_json' => $geo['geo_json'],
            'edit_status' => $analysis['edit_status'],
            'edit_note' => $analysis['edit_note'],
            'exif_json' => $analysis['exif_json'],
        ]);

        $newId = (int) $pdo->lastInsertId();

        // miniatury powstają od razu, a nie przy pierwszym wejściu odwiedzającego
        try {
            zm_pregen($filename);
        } catch (Throwable $e) {
            // nieudana miniatura nie może blokować dodania zdjęcia
        }

        $added[] = [
            'id' => $newId,
            'filename' => $filename,
            'category_id' => $finalCategoryId,
            'created_categories' => $created,
            'geo' => [
                'status' => $geo['status'],
                'gmina' => $geo['gmina'],
                'miejscowosc' => $geo['miejscowosc'],
                'category_id' => $finalCategoryId,
                'category_name' => $geo['category_name'],
                'need' => $geo['need'],
                'message' => $geo['message'],
            ],
        ];

        zm_log(
            $pdo,
            'photo_add',
            $admin['username'],
            'p' . $newId,
            $filename
        );
    }

    echo json_encode([
        'ok' => true,
        'added' => $added,
        'skipped' => $skipped,
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ---------------------------------------------------------- photo_update */

if ($action === 'photo_update') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $select = $pdo->prepare(
        'SELECT * FROM photos WHERE id = :id LIMIT 1'
    );

    $select->execute(['id' => $id]);

    $row = $select->fetch();

    if (!$row) {
        zm_fail(404, 'not_found');
    }

    $takenAt = trim((string) ($data['taken_at'] ?? ''));

    if (
        $takenAt !== ''
        && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $takenAt)
    ) {
        zm_fail(400, 'bad_date');
    }

    $coordinates = trim((string) ($data['coordinates'] ?? ''));

    if ($coordinates === '') {
        $rawLat = trim((string) ($data['lat'] ?? ''));
        $rawLng = trim((string) ($data['lng'] ?? ''));

        if ($rawLat !== '' || $rawLng !== '') {
            $coordinates = $rawLat . ',' . $rawLng;
        }
    }

    [$lat, $lng] = zm_parse_coordinates($coordinates);

    $categoryId = ($data['category_id'] ?? '') === ''
        ? null
        : (int) $data['category_id'];

    if ($categoryId !== null) {
        $check = $pdo->prepare(
            'SELECT id FROM categories WHERE id = :id'
        );

        $check->execute(['id' => $categoryId]);

        if ($check->fetchColumn() === false) {
            $categoryId = null;
        }
    }

    /*
     * Brak kategorii + rozpoznana lokalizacja + flaga auto_categories
     * (wysyłana przez panel) → zakładamy brakującą kategorię (gminę i/lub
     * miejscowość) tak jak przy uploadzie. Bez tego edycja zdjęcia z pustą
     * kategorią zostawiała je w ogóle bez kategorii, mimo że upload
     * umieściłby je poprawnie.
     */
    if ($categoryId === null && !empty($data['auto_categories'])) {
        $effLat = $lat ?? (is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null);
        $effLng = $lng ?? (is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : null);

        $geo = ($effLat !== null && $effLng !== null)
            ? zm_geo_classify($pdo, $effLat, $effLng)
            : zm_geo_classify($pdo, null, null);

        if ($geo['need'] !== null) {
            $need = $geo['need'];
            $gmina = trim((string) $geo['gmina']);
            if ($gmina === '') {
                $gmina = trim((string) ($row['gmina_detected'] ?? ''));
            }

            if ($need['kind'] === 'gmina') {
                if ($gmina !== '') {
                    $categoryId = zm_geo_ensure_gmina_category($pdo, $gmina);
                }
            } else {
                $childName = trim((string) $need['child_name']);
                $parentId = $need['parent_id'] !== null ? (int) $need['parent_id'] : null;

                if ($parentId === null && $gmina !== '') {
                    $parentId = zm_geo_ensure_gmina_category($pdo, $gmina);
                }
                if ($parentId !== null && $childName !== '') {
                    $categoryId = zm_geo_ensure_child_category($pdo, $parentId, $childName);
                }
            }
        }
    }

    $update = $pdo->prepare(
        'UPDATE photos
         SET
            title = :title,
            description = :description,
            category_id = :category_id,
            place = :place,
            taken_at = :taken_at,
            lat = :lat,
            lng = :lng,
            published = :published,
            publish_at = :publish_at
         WHERE id = :id'
    );

    // status „czy zdjęcie było modyfikowane”: '' = automatycznie, inna wartość = ręcznie
    $editChoice = array_key_exists('edit_status', $data) ? trim((string) $data['edit_status']) : null;

    if (isset($data['published'])) {
        [$newPublished, $newPublishAt] = zm_resolve_publish(
            (bool) $data['published'],
            zm_parse_publish_at((string) ($data['publish_at'] ?? ''))
        );
    } else {
        $newPublished = (int) $row['published'];
        $newPublishAt = $row['publish_at'] ?? null;
    }

    // Niezmiennik moderacji — patrz photo_publish. Zaplanowana publikacja
    // (publish_at) na zdjęciu oczekującym też by się „samoopublikowała",
    // bo zm_publish_due() odpala się przy każdym odczycie.
    $moderationStatus = (string) ($row['moderation_status'] ?? 'approved');
    if ($newPublished === 1 && $moderationStatus !== 'approved') {
        zm_fail(409, 'needs_moderation', [
            'message' => 'To zdjęcie nie zostało jeszcze zatwierdzone. Użyj „Zatwierdź”, '
                . 'aby je opublikować — okno edycji nie zastępuje moderacji.',
        ]);
    }

    // Etykiety atrakcji i notatka — opcjonalne pola. Zdjęcie i jego etykiety
    // zapisujemy atomowo (jedna transakcja): albo obie zmiany, albo żadna.
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $update->execute([
            'title' => zm_cut(
                trim((string) ($data['title'] ?? '')),
                255
            ),
            'description' => trim(
                (string) ($data['description'] ?? '')
            ) ?: null,
            'category_id' => $categoryId,
            'place' => zm_cut(
                trim((string) ($data['place'] ?? '')),
                160
            ) ?: null,
            'taken_at' => $takenAt !== '' ? $takenAt : null,
            'lat' => $lat,
            'lng' => $lng,
            'published' => $newPublished,
            'publish_at' => $newPublishAt,
            'id' => $id,
        ]);

        // Etykiety atrakcji: bierzemy tylko znane klucze (walidacja wobec
        // zm_attraction_labels()), usuwamy nieodznaczone, dodajemy nowe.
        // Notatka jest wspólna dla wszystkich etykiet zdjęcia; pusta czyści.
        if (array_key_exists('labels', $data)) {
            $labels = zm_attraction_normalize_labels($data['labels']);
            zm_attraction_set_labels($pdo, $id, $labels, null);

            if (array_key_exists('attraction_note', $data)) {
                $attrNote = zm_cut(trim((string) $data['attraction_note']), 255);
                $pdo->prepare('UPDATE photo_attractions SET note = :n WHERE photo_id = :id')
                    ->execute(['n' => $attrNote !== '' ? $attrNote : null, 'id' => $id]);
            }
        }

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        zm_fail(500, 'server_error', ['message' => 'Nie udało się zapisać zmian.']);
    }

    // Etykiety mogły zostać dodane do już zatwierdzonego zdjęcia — wtedy należy
    // się bonus punktowy i powiadomienie. Strażnik w zm_attraction_reward_owner()
    // gwarantuje jednokrotne naliczenie także przy kolejnych edycjach.
    if ((string) ($row['moderation_status'] ?? '') === 'approved') {
        $updateSubmitter = (int) ($row['submitted_by_user_id'] ?? 0);
        if ($updateSubmitter > 0) {
            zm_attraction_reward_owner($pdo, $id, $updateSubmitter, (int) $admin['id']);
        }
    }

    if ($editChoice !== null) {
        if ($editChoice === '') {
            // wracamy do oceny automatycznej
            $pdo->prepare('UPDATE photos SET edit_manual = 0 WHERE id = :id')->execute(['id' => $id]);
            $fresh = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
            $fresh->execute(['id' => $id]);
            $freshRow = $fresh->fetch();
            if ($freshRow) {
                zm_analyze_photo_row($pdo, $freshRow, true);
            }
        } elseif (in_array($editChoice, ['original', 'suspect', 'edited', 'unknown'], true)) {
            $note = zm_cut(trim((string) ($data['edit_note'] ?? '')), 250);
            if ($note === '') {
                $note = 'Status ustawiony ręcznie przez administratora.';
            }
            $pdo->prepare(
                'UPDATE photos SET edit_status = :s, edit_note = :n, edit_manual = 1 WHERE id = :id'
            )->execute(['s' => $editChoice, 'n' => $note, 'id' => $id]);
        }
    }

    zm_log(
        $pdo,
        'photo_edit',
        $admin['username'],
        'p' . $id,
        (string) $row['filename']
    );

    echo json_encode([
        'ok' => true,
        'labels' => zm_attraction_photo_labels($pdo, $id),
    ]);

    exit;
}

/* -------------------------------------------------------- photo_publish */

if ($action === 'photo_publish') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $published = (int) (bool) ($data['published'] ?? 0);

    // Niezmiennik moderacji: zdjęcie może być widoczne tylko wtedy, gdy jest
    // opublikowane ORAZ zatwierdzone. Ten endpoint obsługuje zwykłe
    // publikowanie/ukrywanie, więc nie może obejść zatwierdzenia — zgłoszenie
    // oczekujące albo odrzucone trzeba przepuścić przez photo_moderate.
    if ($published === 1) {
        $check = $pdo->prepare('SELECT moderation_status FROM photos WHERE id = :id LIMIT 1');
        $check->execute(['id' => $id]);
        $status = $check->fetchColumn();

        if ($status === false) {
            zm_fail(404, 'not_found');
        }

        if ($status !== 'approved') {
            zm_fail(409, 'needs_moderation', [
                'message' => 'To zdjęcie nie zostało jeszcze zatwierdzone. Użyj „Zatwierdź”, '
                    . 'aby je opublikować — „Publikuj” nie zastępuje moderacji.',
            ]);
        }
    }

    $update = $pdo->prepare(
        'UPDATE photos
         SET published = :published, publish_at = NULL
         WHERE id = :id'
    );

    $update->execute([
        'published' => $published,
        'id' => $id,
    ]);

    zm_log(
        $pdo,
        $published ? 'photo_publish' : 'photo_hide',
        $admin['username'],
        'p' . $id,
        ''
    );

    echo json_encode([
        'ok' => true,
        'published' => $published,
    ]);

    exit;
}

/* --------------------------------------------------------- photo_moderate */

if ($action === 'photo_moderate') {
    $id = (int) ($data['id'] ?? 0);
    $decision = (string) ($data['decision'] ?? '');

    if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        zm_fail(400, 'invalid');
    }

    $rejectReasonText = '';
    $rejectNotificationSent = false;
    if ($decision === 'reject') {
        $reasonKey = is_string($data['reject_reason'] ?? null) ? trim($data['reject_reason']) : '';
        $customReason = is_string($data['reject_message'] ?? null)
            ? trim((string) preg_replace('/\s+/u', ' ', $data['reject_message']))
            : '';
        $reasons = [
            'regulamin' => 'Niezgodne z regulaminem',
            'odleglosc' => 'Za daleko od Mogilna i okolicy',
            'obszar' => 'Nie przedstawia Mogilna ani okolicy',
            'jakosc' => 'Niska jakość lub nieczytelne zdjęcie',
        ];
        if (isset($reasons[$reasonKey])) {
            $rejectReasonText = $reasons[$reasonKey];
        } elseif ($reasonKey === 'inne' && $customReason !== '' && mb_strlen($customReason) <= 220) {
            $rejectReasonText = $customReason;
        } else {
            zm_fail(400, 'invalid_reject_reason', ['message' => 'Wybierz albo wpisz powód odrzucenia.']);
        }
    }

    $select = $pdo->prepare(
        'SELECT id, filename, moderation_status, submitted_by_user_id, category_id,
                gmina_detected, geo_json, category_request_id
         FROM photos WHERE id = :id LIMIT 1'
    );
    $select->execute(['id' => $id]);
    $row = $select->fetch();

    if (!$row) {
        zm_fail(404, 'not_found');
    }

    if ($decision === 'approve') {
        // Brakująca kategoria: admin decyduje ręcznie. create_gmina=1 tworzy
        // to, czego brakuje — kategorię gminy albo podkategorię miejscowości —
        // i przypisuje zdjęcie. W innym wypadku można wskazać istniejącą
        // kategorię (category_id), albo zatwierdzić bez kategorii.
        $createGmina = !empty($data['create_gmina']);
        $catChoice = (array_key_exists('category_id', $data) && $data['category_id'] !== '')
            ? (int) $data['category_id']
            : null;

        $assignCategory = null;
        $createdCategoryId = null;
        $gmina = trim((string) ($row['gmina_detected'] ?? ''));

        if ($createGmina) {
            $need = zm_geo_need_from_geo_json((string) ($row['geo_json'] ?? ''));

            if ($need !== null && $need['kind'] === 'place' && $need['child_name'] !== '') {
                // brakowało podkategorii miejscowości
                $parentId = $need['parent_id'];
                if ($parentId === null && $gmina !== '') {
                    $parentId = zm_geo_ensure_gmina_category($pdo, $gmina);
                }
                if ($parentId !== null) {
                    $assignCategory = zm_geo_ensure_child_category($pdo, $parentId, $need['child_name']);
                }
            } elseif ($gmina !== '') {
                $assignCategory = zm_geo_ensure_gmina_category($pdo, $gmina);
            }

            $createdCategoryId = $assignCategory;
        } elseif ($catChoice !== null) {
            $chk = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
            $chk->execute(['id' => $catChoice]);
            if ($chk->fetchColumn() !== false) {
                $assignCategory = $catChoice;
            }
        } else {
            // Bez jawnego wyboru (szybkie „Zatwierdzono zdjęcie”) oddajemy
            // propozycję z geo/wniosku — inaczej zdjęcie zostaje przy kategorii
            // zastępczej gminy, a wniosek o podkategorię wisi jako pending.
            $auto = zm_admin_auto_category($pdo, $row);
            if ($auto !== null) {
                $assignCategory = $auto['id'];
                if ($auto['created']) {
                    $createdCategoryId = $auto['id'];
                }
            }
        }

        if ($assignCategory !== null) {
            $pdo->prepare(
                "UPDATE photos
                 SET published = 1, moderation_status = 'approved', publish_at = NULL,
                     category_id = :cat, geo_status = 'matched', category_request_id = NULL
                 WHERE id = :id"
            )->execute(['cat' => $assignCategory, 'id' => $id]);

            // Zgłoszenie miało własny wniosek o kategorię i admin właśnie ją
            // zatwierdził — zamykamy wniosek, żeby nie wisiał na zakładce.
            if (!empty($row['category_request_id'])) {
                $requestId = (int) $row['category_request_id'];
                $pdo->prepare(
                    "UPDATE category_requests
                     SET status = 'approved', resolved_by_user_id = :admin,
                         resolved_at = NOW(), created_category_id = :cat
                     WHERE id = :id AND status = 'pending'"
                )->execute([
                    'admin' => $admin['id'],
                    'cat' => $assignCategory,
                    'id' => $requestId,
                ]);

                // Pozostałe zdjęcia z tego samego wniosku dostają tę samą
                // kategorię — wniosek dotyczył miejsca, nie konkretnego pliku.
                // Nie publikujemy ich: moderacja działa osobno dla każdego.
                $pdo->prepare(
                    'UPDATE photos SET category_id = :cat
                     WHERE category_request_id = :rid AND category_id IS NULL'
                )->execute(['cat' => $assignCategory, 'rid' => $requestId]);
            }
        } else {
            $pdo->prepare(
                'UPDATE photos SET published = 1, moderation_status = \'approved\', publish_at = NULL WHERE id = :id'
            )->execute(['id' => $id]);
        }

        // Nagroda +10 za zatwierdzone zdjęcie zgłoszone przez użytkownika.
        // Jednorazowo (strażnik w points_history) — ponowne zatwierdzenie
        // tej samej fotki nie dodaje punktów. Zdjęcia admina (submitted_by
        // NULL) punktów nie dają.
        $submitter = (int) ($row['submitted_by_user_id'] ?? 0);
        if ($submitter > 0) {
            zm_award_once($pdo, $submitter, 10, 'photo_add', 'photo', $id);
        }

        // miniatury mogły nie powstać przy zgłoszeniu (np. gdyby wtedy zawiodło) — dogeneruj teraz
        try {
            zm_pregen((string) $row['filename']);
        } catch (Throwable $e) {
            // nieudana miniatura nie blokuje zatwierdzenia
        }

        /*
         * Powiadomienie do zgłaszającego. Wysyłamy PO zmianie statusu, więc
         * kliknięcie w powiadomienie prowadzi do zdjęcia już widocznego w galerii.
         */
        if ($submitter > 0) {
            zm_notify_user($pdo, $submitter, 'moderation_approved', (int) $admin['id'], [
                'ref_type' => 'photo',
                'ref_id'   => $id,
                'body'     => 'Twoje zdjęcie jest już w galerii.',
            ]);

            // Obserwujący autora dostają „nowe zdjęcie od obserwowanego" —
            // dopiero teraz, bo zdjęcie faktycznie stało się widoczne.
            zm_notify_followers($pdo, $submitter, 'follow_post', $submitter, [
                'ref_type' => 'photo',
                'ref_id'   => $id,
                'body'     => 'dodał nowe zdjęcie do galerii',
            ]);
        }
    } else {
        $pdo->prepare(
            "UPDATE photos SET published = 0, moderation_status = 'rejected' WHERE id = :id"
        )->execute(['id' => $id]);

        // Powiadomienie zapisujemy przed usunięciem zdjęcia, aby użytkownik
        // zachował informację o decyzji i jej powodzie.
        $rejectTo = (int) ($row['submitted_by_user_id'] ?? 0);
        if ($rejectTo > 0) {
            $rejectNotificationSent = zm_notify_user($pdo, $rejectTo, 'moderation_rejected', (int) $admin['id'], [
                'ref_type' => 'photo',
                'ref_id'   => $id,
                'body'     => 'Twoje zgłoszenie zdjęcia zostało odrzucone. Powód: ' . $rejectReasonText . '.',
            ]);
        }
    }

    // Etykiety atrakcji — opcjonalne pole `labels` w żądaniu (jak przy
    // zatwierdzaniu przez zm_admin_approve_photo()). Brak klucza = brak zmian.
    if ($decision === 'approve') {
        zm_attraction_apply_labels($pdo, $id, $data, null);
    }

    // Bonus + powiadomienie, gdy zatwierdzone zdjęcie jest atrakcją (po etykietach).
    if ($decision === 'approve') {
        $attrSubmitter = (int) ($row['submitted_by_user_id'] ?? 0);
        if ($attrSubmitter > 0) {
            zm_attraction_reward_owner($pdo, $id, $attrSubmitter, (int) $admin['id']);
        }
    }

    $photoLogName = (string) $row['filename'];
    if ($decision === 'reject') {
        $photoLogName = 'Powód: ' . $rejectReasonText . ' | ' . $photoLogName;
    }
    $photoLogName = mb_substr($photoLogName, 0, 300);
    zm_log(
        $pdo,
        $decision === 'approve' ? 'photo_approve' : 'photo_reject',
        $admin['username'],
        'p' . $id,
        $photoLogName
    );

    if ($decision === 'reject') {
        try {
            zm_admin_delete_photo($pdo, $row, (string) $admin['username']);
        } catch (Throwable $e) {
            zm_fail(500, 'server_error');
        }
    }

    echo json_encode([
        'ok' => true,
        'decision' => $decision,
        'notification_sent' => $decision === 'reject' ? $rejectNotificationSent : null,
        'category_id' => $assignCategory ?? null,
        'created_category_id' => $createdCategoryId ?? null,
        'labels' => $decision === 'approve' ? zm_attraction_photo_labels($pdo, $id) : [],
    ]);

    exit;
}

/* -------------------------------------------------- photo_moderate_bulk */

if ($action === 'photo_moderate_bulk') {
    $decision = (string) ($data['decision'] ?? '');
    $ids = $data['ids'] ?? null;

    if (!in_array($decision, ['approve', 'reject'], true) || !is_array($ids)) {
        zm_fail(400, 'invalid');
    }

    $clean = [];
    foreach ($ids as $rawId) {
        $rawId = (int) $rawId;
        if ($rawId > 0) {
            $clean[$rawId] = $rawId;
        }
    }
    $ids = array_values($clean);

    if ($ids === [] || count($ids) > 1000) {
        zm_fail(400, 'invalid');
    }

    $done = 0;
    $missing = 0;
    $created = [];
    foreach ($ids as $id) {
        $result = $decision === 'approve'
            ? zm_admin_approve_photo($pdo, $admin, $id, $data)
            : zm_admin_reject_photo($pdo, $admin, $id, $data);

        if ($result === null) {
            $missing++;
            continue;
        }
        $done++;
        if (!empty($result['created_category_id'])) {
            $created[] = (int) $result['created_category_id'];
        }
    }

    echo json_encode([
        'ok' => true,
        'decision' => $decision,
        'done' => $done,
        'missing' => $missing,
        'created_categories' => $created,
    ]);

    exit;
}

/* -------------------------------------------------- category_request_resolve */

/**
 * Rozpatrzenie wniosku o nową kategorię / podkategorię.
 *
 *   decision = approve  → tworzymy kategorię (opcjonalnie razem z rodzicem),
 *                         przypisujemy do niej WSZYSTKIE zdjęcia podpięte do
 *                         wniosku i zamykamy wniosek
 *   decision = reject   → wniosek na 'rejected', zdjęcia zostają bez kategorii
 *                         (kategoria_request_id zerujemy, żeby nie wisiały
 *                          na czekającym wniosku)
 *
 * Zdjęcia zostają zmoderowane tak, jak były — tu zmienia się tylko drzewo
 * kategorii i ich przypisanie.
 */
if ($action === 'category_request_resolve') {
    $id = (int) ($data['id'] ?? 0);
    $decision = (string) ($data['decision'] ?? '');

    if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        zm_fail(400, 'invalid');
    }

    $select = $pdo->prepare('SELECT * FROM category_requests WHERE id = :id LIMIT 1');
    $select->execute(['id' => $id]);
    $request = $select->fetch();

    if (!$request) {
        zm_fail(404, 'not_found');
    }

    if ($decision === 'reject') {
        $pdo->prepare('UPDATE photos SET category_request_id = NULL WHERE category_request_id = :id')
            ->execute(['id' => $id]);

        $pdo->prepare(
            "UPDATE category_requests
             SET status = 'rejected', resolved_by_user_id = :admin, resolved_at = NOW()
             WHERE id = :id"
        )->execute(['admin' => $admin['id'], 'id' => $id]);

        zm_log($pdo, 'category_request_reject', $admin['username'], 'r' . $id, (string) $request['child_name']);

        // Wnioskodawca musi wiedzieć, że odrzucono — inaczej czeka bez końca.
        zm_notify_user(
            $pdo,
            (int) ($request['requested_by_user_id'] ?? 0),
            'category_request_resolved',
            (int) $admin['id'],
            [
                'ref_type' => 'category_request',
                'ref_id'   => $id,
                'body'     => 'Twój wniosek o „' . (string) ($request['child_name'] ?? '') . '” został odrzucony.',
            ]
        );

        echo json_encode([
            'ok' => true,
            'status' => 'rejected',
            'detached_photos' => true,
        ]);

        exit;
    }

    // --- approve -------------------------------------------------------
    $childName = zm_cut(trim((string) ($data['child_name'] ?? $request['child_name'])), 100);
    if ($childName === '') {
        zm_fail(400, 'invalid', ['message' => 'Nazwa kategorii nie może być pusta.']);
    }

    // Admin może zamknąć wniosek bez tworzenia i bez przypisywania kategorii
    // (np. prośba o kategorię, której nie chce w galerii). Zdjęcia zostają
    // w moderacji bez kategorii.
    if (!empty($data['no_category'])) {
        $pdo->prepare('UPDATE photos SET category_request_id = NULL WHERE category_request_id = :id')
            ->execute(['id' => $id]);

        $pdo->prepare(
            "UPDATE category_requests
             SET status = 'rejected', resolved_by_user_id = :admin, resolved_at = NOW(),
                 created_category_id = NULL, note = :note
             WHERE id = :id"
        )->execute([
            'admin' => $admin['id'],
            'note' => zm_cut(trim((string) ($request['note'] ?? '')), 500) . ' [odrzucono: bez kategorii]',
            'id' => $id,
        ]);

        zm_log($pdo, 'category_request_dismiss', $admin['username'], 'r' . $id, (string) $request['child_name']);

        echo json_encode(['ok' => true, 'status' => 'rejected', 'category_id' => null]);

        exit;
    }

    $parentId = ($data['parent_id'] ?? '') === '' ? null : (int) $data['parent_id'];
    if ($parentId !== null) {
        $chk = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
        $chk->execute(['id' => $parentId]);
        if ($chk->fetchColumn() === false) {
            $parentId = null;
        }
    }

    // Gdy wniosek dotyczy miejscowości, a rodzica w treści wniosku nie ma
    // (albo admin go nie potwierdził), zakładamy kategorię gminy.
    // Przy wniosku o samą gminę nie zakładamy rodzica — child_name jest wtedy
    // tą samą nazwą, więc powstałaby kategoria zagnieżdżona w samej sobie.
    $requestKind = (string) ($request['kind'] ?? 'place');
    $createdParent = null;
    if (
        $requestKind !== 'gmina'
        && $parentId === null
        && (string) ($data['parent_id'] ?? '') === ''
        && !empty($data['create_parent'])
    ) {
        $gmina = trim((string) ($request['gmina'] ?? ''));
        if ($gmina !== '') {
            $createdParent = zm_geo_ensure_gmina_category($pdo, $gmina);
            $parentId = $createdParent;
        }
    }

    // Nazwa może już istnieć (ktoś dodał ją ręcznie) — wtedy po prostu
    // przypisujemy do istniejącej zamiast tworzyć duplikat.
    $existing = $parentId !== null
        ? zm_find_child_category($pdo, $parentId, $childName)
        : zm_find_category_anywhere($pdo, $childName);

    if ($existing !== null) {
        $categoryId = (int) $existing['id'];
        $created = false;
    } else {
        $categoryId = $parentId !== null
            ? zm_geo_ensure_child_category($pdo, $parentId, $childName)
            : null;

        if ($categoryId === null) {
            // kategoria główna (albo zbyt głęboko — wtedy świadomie obniżamy
            // poziom zamiast odrzucać wniosek)
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO categories (name, description, parent_id, sort_order) VALUES (:n, NULL, NULL, 0)'
                );
                $ins->execute(['n' => $childName]);
                $categoryId = (int) $pdo->lastInsertId();
                $created = true;
            } catch (Throwable $e) {
                $again = zm_find_category_anywhere($pdo, $childName);
                if ($again === null) {
                    zm_fail(409, 'category_failed');
                }
                $categoryId = (int) $again['id'];
                $created = false;
            }
        } else {
            $created = true;
        }
    }

    $pdo->prepare(
        'UPDATE photos SET category_id = :cat, category_request_id = NULL WHERE category_request_id = :id'
    )->execute(['cat' => $categoryId, 'id' => $id]);

    $pdo->prepare(
        "UPDATE category_requests
         SET status = 'approved', child_name = :child, parent_id = :parent,
             resolved_by_user_id = :admin, resolved_at = NOW(), created_category_id = :cat
         WHERE id = :id"
    )->execute([
        'child' => $childName,
        'parent' => $parentId,
        'admin' => $admin['id'],
        'cat' => $categoryId,
        'id' => $id,
    ]);

    zm_log(
        $pdo,
        'category_request_approve',
        $admin['username'],
        'r' . $id,
        $childName . ($createdParent !== null ? ' (utworzono też ' . zm_category_name_by_id($pdo, $createdParent) . ')' : '')
    );

    // Wnioskodawca dostaje informację o zakceptowaniu (i nazwę nowej kategorii,
    // bo to ona jest dla niego wynikiem wniosku, nie samo „tak").
    zm_notify_user(
        $pdo,
        (int) ($request['requested_by_user_id'] ?? 0),
        'category_request_resolved',
        (int) $admin['id'],
        [
            'ref_type' => 'category_request',
            'ref_id'   => $id,
            'body'     => 'Twój wniosek został przyjęty — kategoria „'
                . zm_category_name_by_id($pdo, $categoryId) . '” jest już w galerii.',
        ]
    );

    echo json_encode([
        'ok' => true,
        'status' => 'approved',
        'category_id' => $categoryId,
        'category_name' => zm_category_name_by_id($pdo, $categoryId),
        'created_parent_id' => $createdParent,
    ]);

    exit;
}

/* ---------------------------------------------------------- photo_delete */

if ($action === 'photo_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $select = $pdo->prepare(
        'SELECT * FROM photos WHERE id = :id LIMIT 1'
    );

    $select->execute(['id' => $id]);

    $row = $select->fetch();

    if (!$row) {
        zm_fail(404, 'not_found');
    }

    try {
        $removed = zm_admin_delete_photo($pdo, $row, (string) $admin['username']);
    } catch (Throwable $e) {
        zm_fail(500, 'server_error');
    }

    echo json_encode(['ok' => true] + $removed);

    exit;
}

/* -------------------------------------------------------- category_save */

if ($action === 'category_save') {
    $name = trim((string) ($data['name'] ?? ''));

    if ($name === '') {
        zm_fail(400, 'invalid');
    }

    $name = zm_cut($name, 100);

    $description = zm_cut(
        trim((string) ($data['description'] ?? '')),
        255
    );

    $id = (int) ($data['id'] ?? 0);

    $tree = zm_category_tree($pdo);

    // kategoria nadrzędna (puste = kategoria główna)
    $parentId = ($data['parent_id'] ?? '') === '' ? null : (int) $data['parent_id'];

    if ($parentId !== null && !isset($tree['byId'][$parentId])) {
        $parentId = null;
    }

    if ($parentId !== null) {
        if ($id > 0 && in_array($parentId, zm_category_subtree_ids($tree, $id), true)) {
            // np. przeniesienie „Mogilno” do jej własnej podkategorii
            zm_fail(400, 'parent_cycle');
        }

        $needed = zm_category_depth($tree, $parentId)
            + ($id > 0 ? zm_category_height($tree, $id) : 1);

        if ($needed > ZM_CATEGORY_MAX_DEPTH) {
            zm_fail(400, 'too_deep', ['max_depth' => ZM_CATEGORY_MAX_DEPTH]);
        }
    }

    // nazwa unikalna tylko w obrębie jednej kategorii nadrzędnej
    $dup = $pdo->prepare(
        'SELECT id FROM categories
         WHERE LOWER(name) = LOWER(:name)
           AND ((:p1 IS NULL AND parent_id IS NULL) OR parent_id = :p2)
           AND id <> :id
         LIMIT 1'
    );
    $dup->execute(['name' => $name, 'p1' => $parentId, 'p2' => $parentId, 'id' => $id]);

    if ($dup->fetchColumn() !== false) {
        zm_fail(409, 'duplicate_name');
    }

    if ($id > 0) {
        $update = $pdo->prepare(
            'UPDATE categories
             SET name = :name, description = :description, parent_id = :parent_id
             WHERE id = :id'
        );

        $update->execute([
            'name' => $name,
            'description' => $description ?: null,
            'parent_id' => $parentId,
            'id' => $id,
        ]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO categories (name, description, parent_id)
             VALUES (:name, :description, :parent_id)'
        );

        $insert->execute([
            'name' => $name,
            'description' => $description ?: null,
            'parent_id' => $parentId,
        ]);

        $id = (int) $pdo->lastInsertId();
    }

    echo json_encode([
        'ok' => true,
        'id' => $id,
    ]);

    exit;
}

/* ------------------------------------------------------ category_delete */

if ($action === 'category_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $sel = $pdo->prepare('SELECT parent_id FROM categories WHERE id = :id');
    $sel->execute(['id' => $id]);
    $found = $sel->fetch();

    if (!$found) {
        zm_fail(404, 'not_found');
    }

    $parent = $found['parent_id'] !== null ? (int) $found['parent_id'] : null;

    // podkategorie i zdjęcia „wchodzą piętro wyżej” zamiast wylądować na stronie głównej
    $pdo->prepare('UPDATE categories SET parent_id = :p WHERE parent_id = :id')
        ->execute(['p' => $parent, 'id' => $id]);

    $moved = $pdo->prepare('UPDATE photos SET category_id = :p WHERE category_id = :id');
    $moved->execute(['p' => $parent, 'id' => $id]);

    $delete = $pdo->prepare(
        'DELETE FROM categories WHERE id = :id'
    );

    $delete->execute(['id' => $id]);

    echo json_encode([
        'ok' => true,
        'moved_photos' => $moved->rowCount(),
    ]);

    exit;
}

/* ----------------------------------------------------------- photo_scan */

if ($action === 'photo_scan') {
    if (!is_dir(PHOTOS_DIR)) {
        echo json_encode([
            'ok' => true,
            'added' => 0,
        ]);

        exit;
    }

    $known = $pdo
        ->query('SELECT filename FROM photos')
        ->fetchAll(PDO::FETCH_COLUMN);

    $known = array_flip(array_map('strval', $known));

    $insert = $pdo->prepare(
        'INSERT INTO photos
            (
                filename,
                kind,
                title,
                taken_at,
                published,
                submitted_by_user_id,
                width,
                height,
                filesize,
                edit_status,
                edit_note,
                exif_json
            )
         VALUES
             (
                :filename,
                :kind,
                :title,
                :taken_at,
                1,
                :uid,
                :width,
                :height,
                :filesize,
                :edit_status,
                :edit_note,
                :exif_json
            )'
    );

    $added = 0;

    foreach ((array) @scandir(PHOTOS_DIR) as $entry) {
        if (
            $entry === '.'
            || $entry === '..'
            || ($entry !== '' && $entry[0] === '.')
            || isset($known[$entry])
        ) {
            continue;
        }

        $absolutePath = PHOTOS_DIR . '/' . $entry;
        $kind = zm_kind($entry);

        if (!is_file($absolutePath) || $kind === null) {
            continue;
        }

        $width = null;
        $height = null;
        $takenAt = null;

        $dimensions = @getimagesize($absolutePath);

        if ($dimensions === false) {
            continue;
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $takenAt = zm_exif_date($absolutePath);

        try {
            $analysis = zm_analyze_file($absolutePath);
        } catch (Throwable $e) {
            $analysis = ['edit_status' => null, 'edit_note' => null, 'exif_json' => null];
        }

        $insert->execute([
            'filename' => $entry,
            'kind' => 'image',
            'title' => pathinfo($entry, PATHINFO_FILENAME),
            'taken_at' => $takenAt,
            'submitted_by_user_id' => $admin['id'],
            'width' => $width,
            'height' => $height,
            'filesize' => (int) @filesize($absolutePath),
            'edit_status' => $analysis['edit_status'],
            'edit_note' => $analysis['edit_note'],
            'exif_json' => $analysis['exif_json'],
        ]);

        $added++;
    }

    if ($added > 0) {
        zm_log(
            $pdo,
            'photo_scan',
            $admin['username'],
            '',
            (string) $added . ' plików'
        );
    }

    echo json_encode([
        'ok' => true,
        'added' => $added,
    ]);

    exit;
}

/* -------------------------------------------------------- photo_analyze */

/*
 * Sprawdza pliki pod kątem modyfikacji (EXIF/XMP). Domyślnie tylko zdjęcia,
 * które jeszcze nie mają oceny (starsze wgrania); all=1 przelicza wszystkie
 * — poza tymi, którym administrator ustawił status ręcznie.
 */
if ($action === 'photo_analyze') {
    $all = !empty($data['all']);

    $sql = 'SELECT * FROM photos WHERE edit_manual = 0'
        . ($all ? '' : ' AND edit_status IS NULL')
        . ' ORDER BY id ASC LIMIT 200';

    $rowsToCheck = $pdo->query($sql)->fetchAll();

    $done = 0;

    foreach ($rowsToCheck as $r) {
        try {
            if (zm_analyze_photo_row($pdo, $r) !== null) {
                $done++;
            } else {
                // brak pliku na dysku — oznaczamy, żeby nie wracać do niego w kółko
                $pdo->prepare(
                    "UPDATE photos SET edit_status = 'unknown', edit_note = 'Brak pliku na serwerze.' WHERE id = :id"
                )->execute(['id' => (int) $r['id']]);
            }
        } catch (Throwable $e) {
            $pdo->prepare(
                "UPDATE photos SET edit_status = 'unknown', edit_note = 'Nie udało się odczytać pliku.' WHERE id = :id"
            )->execute(['id' => (int) $r['id']]);
        }
    }

    $left = (int) $pdo
        ->query('SELECT COUNT(*) FROM photos WHERE edit_manual = 0 AND edit_status IS NULL')
        ->fetchColumn();

    echo json_encode([
        'ok' => true,
        'analyzed' => $done,
        'left' => $left,
    ]);

    exit;
}

/* ------------------------------------------------ notatnik: lista zadań */

if ($action === 'task_add') {
    zm_ensure_admin_tasks_text($pdo);
    $text = trim(strip_tags((string) ($data['text'] ?? '')));
    if (mb_strlen($text, 'UTF-8') > 1000000) {
        zm_fail(413, 'note_too_long');
    }

    if ($text === '') {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare(
        'INSERT INTO admin_tasks (text, done, created_at) VALUES (:t, 0, :c)'
    )->execute([
        't' => $text,
        'c' => (int) round(microtime(true) * 1000),
    ]);

    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'task_toggle') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $done = !empty($data['done']) ? 1 : 0;

    $pdo->prepare(
        'UPDATE admin_tasks SET done = :d, done_at = :t WHERE id = :id'
    )->execute([
        'd' => $done,
        't' => $done ? (int) round(microtime(true) * 1000) : null,
        'id' => $id,
    ]);

    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'task_status') {
    zm_ensure_admin_tasks_status($pdo);
    $id = (int) ($data['id'] ?? 0);
    $status = (string) ($data['status'] ?? 'todo');
    if ($id <= 0 || !in_array($status, ['todo', 'doing'], true)) {
        zm_fail(400, 'invalid');
    }
    $pdo->prepare('UPDATE admin_tasks SET status = :s WHERE id = :id AND done = 0')
        ->execute(['s' => $status, 'id' => $id]);
    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'task_edit') {
    zm_ensure_admin_tasks_text($pdo);
    $id = (int) ($data['id'] ?? 0);
    $text = zm_cut(trim(strip_tags((string) ($data['text'] ?? ''))), 12000);

    if ($id <= 0 || $text === '') {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare('UPDATE admin_tasks SET text = :t WHERE id = :id')
        ->execute(['t' => $text, 'id' => $id]);

    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'task_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare('DELETE FROM admin_tasks WHERE id = :id')->execute(['id' => $id]);

    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'task_clear_done') {
    $pdo->exec('DELETE FROM admin_tasks WHERE done = 1');

    echo json_encode(['ok' => true] + zm_task_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------- projekty robocze (metasystem) */

// Dozwolone statusy zadania — poza tym zbiorem wartość jest ignorowana.
$projectTaskStatuses = ['todo', 'doing', 'done'];

if ($action === 'project_add') {
    $name = zm_cut(trim(strip_tags((string) ($data['name'] ?? ''))), 160);

    if ($name === '') {
        zm_fail(422, 'invalid', ['message' => 'Podaj nazwę projektu.']);
    }

    $desc = zm_cut(trim((string) ($data['description'] ?? '')), 2000);
    $now = (int) round(microtime(true) * 1000);

    $pdo->prepare(
        'INSERT INTO admin_projects
            (name, slug, description, status, sort_order, created_at, updated_at)
         VALUES (:n, :s, :d, :st, :so, :created, :updated)'
    )->execute([
        'n' => $name,
        's' => zm_project_slug($pdo, $name),
        'd' => $desc !== '' ? $desc : null,
        'st' => 'active',
        'so' => (int) $pdo->query('SELECT IFNULL(MAX(sort_order), 0) + 1 FROM admin_projects')->fetchColumn(),
        'created' => $now,
        'updated' => $now,
    ]);

    zm_log($pdo, 'project_add', $admin['username'], 'p' . (int) $pdo->lastInsertId(), $name);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_edit') {
    $id = (int) ($data['id'] ?? 0);
    $name = zm_cut(trim(strip_tags((string) ($data['name'] ?? ''))), 160);

    if ($id <= 0 || $name === '') {
        zm_fail(422, 'invalid', ['message' => 'Podaj nazwę projektu.']);
    }

    $desc = zm_cut(trim((string) ($data['description'] ?? '')), 2000);
    $status = in_array(($data['status'] ?? ''), ['active', 'paused', 'done'], true)
        ? (string) $data['status']
        : 'active';

    $pdo->prepare(
        'UPDATE admin_projects
         SET name = :n, description = :d, status = :st, updated_at = :u
         WHERE id = :id'
    )->execute([
        'n' => $name,
        'd' => $desc !== '' ? $desc : null,
        'st' => $status,
        'u' => (int) round(microtime(true) * 1000),
        'id' => $id,
    ]);

    zm_log($pdo, 'project_edit', $admin['username'], 'p' . $id, $name);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE FROM admin_project_prompts
             WHERE task_id IN (SELECT id FROM admin_project_tasks WHERE project_id = :p)'
        )->execute(['p' => $id]);
        $pdo->prepare('DELETE FROM admin_project_tasks WHERE project_id = :p')->execute(['p' => $id]);
        $pdo->prepare('DELETE FROM admin_projects WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    zm_log($pdo, 'project_delete', $admin['username'], 'p' . $id, '');

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_task_add') {
    $projectId = (int) ($data['project_id'] ?? 0);
    $title = zm_cut(trim(strip_tags((string) ($data['title'] ?? ''))), 200);

    if ($projectId <= 0 || $title === '') {
        zm_fail(422, 'invalid', ['message' => 'Podaj tytuł zadania.']);
    }

    $exists = $pdo->prepare('SELECT id FROM admin_projects WHERE id = :id');
    $exists->execute(['id' => $projectId]);
    if (!$exists->fetchColumn()) {
        zm_fail(404, 'project_not_found');
    }

    $desc = zm_cut(trim((string) ($data['description'] ?? '')), 4000);
    $now = (int) round(microtime(true) * 1000);
    $sort = (int) $pdo->query(
        'SELECT IFNULL(MAX(sort_order), 0) + 1 FROM admin_project_tasks WHERE project_id = ' . $projectId
    )->fetchColumn();

    $pdo->prepare(
        'INSERT INTO admin_project_tasks
            (project_id, title, description, status, priority, sort_order, created_at)
         VALUES (:p, :t, :d, :s, :pr, :so, :c)'
    )->execute([
        'p' => $projectId,
        't' => $title,
        'd' => $desc !== '' ? $desc : null,
        's' => 'todo',
        'pr' => max(0, min(5, (int) ($data['priority'] ?? 0))),
        'so' => $sort,
        'c' => $now,
    ]);

    zm_log($pdo, 'project_task_add', $admin['username'], 't' . (int) $pdo->lastInsertId(), $title);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_task_edit') {
    $id = (int) ($data['id'] ?? 0);
    $title = zm_cut(trim(strip_tags((string) ($data['title'] ?? ''))), 200);

    if ($id <= 0 || $title === '') {
        zm_fail(422, 'invalid', ['message' => 'Podaj tytuł zadania.']);
    }

    $desc = zm_cut(trim((string) ($data['description'] ?? '')), 4000);
    $status = in_array(($data['status'] ?? ''), $projectTaskStatuses, true)
        ? (string) $data['status']
        : 'todo';
    $priority = max(0, min(5, (int) ($data['priority'] ?? 0)));
    $now = (int) round(microtime(true) * 1000);

    if ($status === 'done') {
        $pdo->prepare(
            'UPDATE admin_project_tasks
             SET title = :t, description = :d, status = :s, priority = :pr,
                 done_at = IFNULL(done_at, :now)
             WHERE id = :id'
        )->execute([
            't' => $title,
            'd' => $desc !== '' ? $desc : null,
            's' => $status,
            'pr' => $priority,
            'now' => $now,
            'id' => $id,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE admin_project_tasks
             SET title = :t, description = :d, status = :s, priority = :pr, done_at = NULL
             WHERE id = :id'
        )->execute([
            't' => $title,
            'd' => $desc !== '' ? $desc : null,
            's' => $status,
            'pr' => $priority,
            'id' => $id,
        ]);
    }

    zm_log($pdo, 'project_task_edit', $admin['username'], 't' . $id, $title);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_task_toggle') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    if (!empty($data['done'])) {
        $now = (int) round(microtime(true) * 1000);
        $pdo->prepare(
            'UPDATE admin_project_tasks SET status = \'done\', done_at = IFNULL(done_at, :now) WHERE id = :id'
        )->execute(['now' => $now, 'id' => $id]);
    } else {
        $pdo->prepare(
            'UPDATE admin_project_tasks SET status = \'todo\', done_at = NULL WHERE id = :id'
        )->execute(['id' => $id]);
    }

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_task_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM admin_project_prompts WHERE task_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM admin_project_tasks WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    zm_log($pdo, 'project_task_delete', $admin['username'], 't' . $id, '');

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_prompt_add') {
    $taskId = (int) ($data['task_id'] ?? 0);
    $body = zm_cut(trim((string) ($data['body'] ?? '')), 20000);

    if ($taskId <= 0 || $body === '') {
        zm_fail(422, 'invalid', ['message' => 'Prompt nie może być pusty.']);
    }

    $exists = $pdo->prepare('SELECT id FROM admin_project_tasks WHERE id = :id');
    $exists->execute(['id' => $taskId]);
    if (!$exists->fetchColumn()) {
        zm_fail(404, 'task_not_found');
    }

    $label = zm_cut(trim(strip_tags((string) ($data['label'] ?? ''))), 160);
    $now = (int) round(microtime(true) * 1000);
    $sort = (int) $pdo->query(
        'SELECT IFNULL(MAX(sort_order), 0) + 1 FROM admin_project_prompts WHERE task_id = ' . $taskId
    )->fetchColumn();

    $pdo->prepare(
        'INSERT INTO admin_project_prompts
            (task_id, label, body, sort_order, created_at, updated_at)
         VALUES (:t, :l, :b, :so, :created, :updated)'
    )->execute([
        't' => $taskId,
        'l' => $label,
        'b' => $body,
        'so' => $sort,
        'created' => $now,
        'updated' => $now,
    ]);

    zm_log($pdo, 'project_prompt_add', $admin['username'], 't' . $taskId, $label);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_prompt_edit') {
    $id = (int) ($data['id'] ?? 0);
    $body = zm_cut(trim((string) ($data['body'] ?? '')), 20000);

    if ($id <= 0 || $body === '') {
        zm_fail(422, 'invalid', ['message' => 'Prompt nie może być pusty.']);
    }

    $label = zm_cut(trim(strip_tags((string) ($data['label'] ?? ''))), 160);

    $pdo->prepare(
        'UPDATE admin_project_prompts SET label = :l, body = :b, updated_at = :u WHERE id = :id'
    )->execute([
        'l' => $label,
        'b' => $body,
        'u' => (int) round(microtime(true) * 1000),
        'id' => $id,
    ]);

    zm_log($pdo, 'project_prompt_edit', $admin['username'], 'p' . $id, $label);

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'project_prompt_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare('DELETE FROM admin_project_prompts WHERE id = :id')->execute(['id' => $id]);

    zm_log($pdo, 'project_prompt_delete', $admin['username'], 'p' . $id, '');

    echo json_encode(['ok' => true] + zm_projects_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

/* --------------------------------- zablokowane nazwy użytkownika (login) */

if ($action === 'blocked_add') {
    $name = zm_blocked_name_normalize((string) ($data['name'] ?? ''));

    if ($name === '' || !preg_match('~^[a-z0-9]{3,60}$~', $name)) {
        zm_fail(422, 'invalid_name', [
            'message' => 'Podaj nazwę z 3 do 60 liter lub cyfr.',
        ]);
    }

    $pdo->prepare(
        'INSERT IGNORE INTO blocked_usernames (name, created_by) VALUES (:n, :by)'
    )->execute([
        'n' => $name,
        'by' => $admin['username'],
    ]);

    echo json_encode(['ok' => true, 'name' => $name] + zm_blocked_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'blocked_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare('DELETE FROM blocked_usernames WHERE id = :id')->execute(['id' => $id]);

    echo json_encode(['ok' => true] + zm_blocked_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----------------------------------------------- wulgaryzmy w komentarzach */

if ($action === 'blocked_words_add') {
    $word = zm_blocked_word_normalize((string) ($data['word'] ?? ''));

    if ($word === '' || !preg_match('~^[a-z]{3,60}$~', $word)) {
        zm_fail(422, 'invalid_word', [
            'message' => 'Podaj wyraz z 3 do 60 liter, bez spacji i cyfr.',
        ]);
    }

    $pdo->prepare(
        'INSERT IGNORE INTO blocked_words (word, created_by) VALUES (:w, :by)'
    )->execute([
        'w' => $word,
        'by' => $admin['username'],
    ]);

    echo json_encode(['ok' => true, 'word' => $word] + zm_blocked_words_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'blocked_words_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $pdo->prepare('DELETE FROM blocked_words WHERE id = :id')->execute(['id' => $id]);

    echo json_encode(['ok' => true] + zm_blocked_words_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------- zgłoszenia komentarzy */

// Ta sama akcja co w gałęzi GET. Panel czyta po GET, ale obsługa jest tu
// też, żeby samo odczytanie zgłoszeń dało się wywołać z POST — wtedy
// z tokenem CSRF, tak jak każda zmiana w panelu.
if ($action === 'comment_reports_list') {
    echo json_encode(zm_comment_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'comment_reports_handle') {
    $ids = $data['ids'] ?? [];
    $ids = is_array($ids)
        ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn($n) => $n > 0)))
        : [];
    $status = (string) ($data['status'] ?? '');

    if ($ids === [] || !in_array($status, ['resolved', 'dismissed'], true)) {
        zm_fail(400, 'invalid', [
            'message' => 'Zaznacz zgłoszenie i sposób obsługi.',
        ]);
    }

    // `AND status = 'open'` chroni przed podwójnym kliknięciem — drugie
    // wywołanie nie nadpisze już obsłużonego zgłoszenia.
    //
    // Wszystkie placeholdery muszą być nazwane: PDO nie wolno mieszać nazwanych
    // z pozycyjnymi (`?`), a lista ID jest zmiennej długości, więc nazwy
    // generujemy po indeksie.
    $marks = [];
    $idParams = [];
    foreach ($ids as $i => $id) {
        $key = 'id' . $i;
        $marks[] = ':' . $key;
        $idParams[$key] = $id;
    }

    $pdo->prepare(
        "UPDATE comment_reports
            SET status = :st, handled_at = :ts, handled_by = :by
          WHERE id IN (" . implode(', ', $marks) . ") AND status = 'open'"
    )->execute(array_merge(
        [
            'st' => $status,
            'ts' => (int) round(microtime(true) * 1000),
            'by' => $admin['username'],
        ],
        $idParams
    ));

    /*
     * Domknięcie alertu w nowym systemie powiadomień.
     *
     * Zgłoszenie komentarza trafia w DWA miejsca: do tabeli `comment_reports`
     * (okno „Zgłoszenia”, istniejące od dawna) i do `notifications` jako alert
     * (nowe okno „Powiadomienia"). Bez tego kroku administrator obsługiwałby
     * zgłoszenie w jednym oknie, a drugie pokazywałoby je wciąż jako nowe —
     * zawieszony alert, który niczego nie wnosi i tylko miesza.
     *
     * Alerty rozpoznajemy po `ref_type = 'comment'` i numerze komentarza, nie
     * po numerze zgłoszenia — bo alert powstaje w chwili zgłoszenia i trzyma
     * numer komentarza (ten sam, co w linku „pokaż").
     */
    $commentIds = [];
    $selC = $pdo->prepare(
        'SELECT id, comment_id FROM comment_reports WHERE id IN (' . implode(', ', $marks) . ')'
    );
    $selC->execute($idParams);
    foreach ($selC->fetchAll() as $row) {
        $commentIds[] = (int) ($row['comment_id'] ?? 0);
    }

    $synced = 0;
    foreach (array_filter(array_unique($commentIds)) as $commentId) {
        if ($commentId <= 0) {
            continue;
        }
        // `user_id IS NULL` pilnuje, że ruszamy TYLKO alerty administracji —
        // powiadomienia użytkowników o komentarzach zostają nietknięte.
        $mark = $pdo->prepare(
            "UPDATE notifications
                SET is_read = 1
              WHERE user_id IS NULL
                AND type = 'admin_comment_report'
                AND ref_type = 'comment'
                AND ref_id = :cid
                AND is_read = 0"
        );
        $mark->execute(['cid' => $commentId]);
        $synced += $mark->rowCount();
    }

    echo json_encode([
        'ok' => true,
        // ile alertów zamknięto — panel odświeży licznik bez dodatkowego odczytu
        'alerts_closed' => $synced,
    ] + zm_comment_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

// Ta sama akcja co w gałęzi GET — odczyt z POST (z tokenem CSRF).
if ($action === 'photo_reports_list') {
    echo json_encode(zm_photo_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'photo_reports_handle') {
    $ids = $data['ids'] ?? [];
    $ids = is_array($ids)
        ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn($n) => $n > 0)))
        : [];
    $status = (string) ($data['status'] ?? '');

    if ($ids === [] || !in_array($status, ['resolved', 'dismissed'], true)) {
        zm_fail(400, 'invalid', [
            'message' => 'Zaznacz zgłoszenie i sposób obsługi.',
        ]);
    }

    // `AND status = 'open'` chroni przed podwójnym kliknięciem. Placeholdery
    // nazwane po indeksie — lista ID jest zmiennej długości, a PDO nie pozwala
    // mieszać nazwanych z pozycyjnymi.
    $marks = [];
    $idParams = [];
    foreach ($ids as $i => $id) {
        $key = 'id' . $i;
        $marks[] = ':' . $key;
        $idParams[$key] = $id;
    }

    $pdo->prepare(
        "UPDATE photo_reports
            SET status = :st, handled_at = :ts, handled_by = :by
          WHERE id IN (" . implode(', ', $marks) . ") AND status = 'open'"
    )->execute(array_merge(
        [
            'st' => $status,
            'ts' => (int) round(microtime(true) * 1000),
            'by' => $admin['username'],
        ],
        $idParams
    ));

    /*
     * Domknięcie alertu w systemie powiadomień — analogicznie do komentarzy.
     * Zgłoszenie zdjęcia trafia DWA razy: do `photo_reports` (okno „Zgłoszenia”)
     * i do `notifications` jako alert. Alerty rozpoznajemy po `ref_type='photo'`
     * i numerze zdjęcia, bo alert powstaje w chwili zgłoszenia i trzyma numer
     * zdjęcia (ten sam, co w linku „pokaż”).
     */
    $photoIds = [];
    $selP = $pdo->prepare(
        'SELECT id, photo_id FROM photo_reports WHERE id IN (' . implode(', ', $marks) . ')'
    );
    $selP->execute($idParams);
    foreach ($selP->fetchAll() as $row) {
        $photoIds[] = (int) ($row['photo_id'] ?? 0);
    }

    $synced = 0;
    foreach (array_filter(array_unique($photoIds)) as $photoId) {
        if ($photoId <= 0) {
            continue;
        }
        $mark = $pdo->prepare(
            "UPDATE notifications
                SET is_read = 1
              WHERE user_id IS NULL
                AND type = 'admin_photo_report'
                AND ref_type = 'photo'
                AND ref_id = :pid
                AND is_read = 0"
        );
        $mark->execute(['pid' => $photoId]);
        $synced += $mark->rowCount();
    }

    echo json_encode([
        'ok' => true,
        'alerts_closed' => $synced,
    ] + zm_photo_reports_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------- zarządzanie users */

if ($action === 'user_edit') {
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }

    $chk = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = :id');
    $chk->execute(['id' => $id]);
    $existing = $chk->fetch();
    if (!$existing) {
        zm_fail(404, 'not_found');
    }

    $username = trim((string) ($data['username'] ?? ''));
    if (mb_strlen($username) < 3 || mb_strlen($username) > 40) {
        zm_fail(422, 'bad_username', ['message' => 'Login musi mieć od 3 do 40 znaków.']);
    }
    if (!preg_match('~^[a-zA-Z0-9_.\-]+$~', $username)) {
        zm_fail(422, 'bad_username_chars', ['message' => 'Login może zawierać tylko litery, cyfry i _ . -']);
    }
    // Login „admin” jest kluczem superadmina (user_role/user_ban/user_delete pilnują
    // go po nazwie, nie po roli). Zmiana nazwy zrobiłaby z niego konto, do którego
    // nie ma już żadnych wyjątków, a sam login „admin” stałby się do przejęcia —
    // dlatego jest też na liście zarezerwowanych. Superadmin może go oczywiście
    // zapisać bez zmian, więc to jedyne pominięcie tej rezerwy.
    $isSuperLogin = strcasecmp((string) $existing['username'], ZM_SUPERADMIN_LOGIN) === 0;
    $keepsSuperLogin = $isSuperLogin && strcasecmp($username, ZM_SUPERADMIN_LOGIN) === 0;
    if ($isSuperLogin && !$keepsSuperLogin) {
        zm_fail(422, 'superadmin_login_fixed', [
            'message' => 'Login konta „' . ZM_SUPERADMIN_LOGIN . '” nie może zostać zmieniony — to stałe konto superadmina.',
        ]);
    }
    if (!$keepsSuperLogin && zm_username_is_reserved($username)) {
        zm_fail(422, 'reserved_username', ['message' => 'Ten login jest zarezerwowany.']);
    }
    if (zm_username_is_blocked($pdo, $username)) {
        zm_fail(422, 'blocked_username', ['message' => 'Ten login figuruje na liście zablokowanych nazw.']);
    }
    $taken = $pdo->prepare('SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1');
    $taken->execute(['u' => $username, 'id' => $id]);
    if ($taken->fetch()) {
        zm_fail(422, 'taken_username', ['message' => 'Ten login jest już zajęty.']);
    }

    $email = zm_normalize_email((string) ($data['email'] ?? ''));
    if ($email !== '' && !zm_email_is_valid($email)) {
        zm_fail(422, 'bad_email', ['message' => 'Podany adres e-mail jest nieprawidłowy.']);
    }
    if ($email !== '') {
        $takenMail = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
        $takenMail->execute(['e' => $email, 'id' => $id]);
        if ($takenMail->fetch()) {
            zm_fail(422, 'taken_email', ['message' => 'Ten adres e-mail jest już przypisany do innego konta.']);
        }
    } else {
        $email = null;   // admin może wyczyścić adres; reset hasła wtedy nie działa
    }

    $password = (string) ($data['password'] ?? '');
    if ($password !== '' && strlen($password) < 8) {
        zm_fail(422, 'weak_password', ['message' => 'Hasło musi mieć minimum 8 znaków.']);
    }

    $updates = ['username = :u', 'email = :e'];
    $params = ['u' => $username, 'e' => $email, 'id' => $id];
    if ($password !== '') {
        $updates[] = 'password_hash = :ph';
        $params['ph'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id')->execute($params);

    echo json_encode([
        'ok' => true,
        'user' => ['id' => $id, 'username' => $username, 'email' => $email ?? ''],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ręczne utworzenie konta w panelu. E-mail jest opcjonalny (puste = NULL),
 * login i hasło wymagane. Konto zawsze dostaje rolę `user`, prywatny profil
 * i rangę specjalną VIP — widoczną tylko w administracji, pomijaną w
 * statystykach do chwili włączenia „Profilu publicznego” przez użytkownika.
 */
if ($action === 'user_create') {
    $username = trim((string) ($data['username'] ?? ''));
    if (mb_strlen($username) < 3 || mb_strlen($username) > 40) {
        zm_fail(422, 'bad_username', ['message' => 'Login musi mieć od 3 do 40 znaków.']);
    }
    if (!preg_match('~^[a-zA-Z0-9_.\-]+$~', $username)) {
        zm_fail(422, 'bad_username_chars', ['message' => 'Login może zawierać tylko litery, cyfry i _ . -']);
    }
    if (zm_username_is_reserved($username)) {
        zm_fail(422, 'reserved_username', ['message' => 'Ten login jest zarezerwowany.']);
    }
    if (zm_username_is_blocked($pdo, $username)) {
        zm_fail(422, 'blocked_username', ['message' => 'Ten login figuruje na liście zablokowanych nazw.']);
    }
    $taken = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $taken->execute(['u' => $username]);
    if ($taken->fetch()) {
        zm_fail(422, 'taken_username', ['message' => 'Ten login jest już zajęty.']);
    }

    $email = zm_normalize_email((string) ($data['email'] ?? ''));
    if ($email !== '' && !zm_email_is_valid($email)) {
        zm_fail(422, 'bad_email', ['message' => 'Podany adres e-mail jest nieprawidłowy.']);
    }
    if ($email !== '') {
        $takenMail = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $takenMail->execute(['e' => $email]);
        if ($takenMail->fetch()) {
            zm_fail(422, 'taken_email', ['message' => 'Ten adres e-mail jest już przypisany do innego konta.']);
        }
    } else {
        $email = null;
    }

    $displayName = trim((string) ($data['display_name'] ?? ''));
    if (mb_strlen($displayName) > 120) {
        zm_fail(422, 'bad_display_name', ['message' => 'Nazwa wyświetlana może mieć maksymalnie 120 znaków.']);
    }
    if ($displayName === '') {
        $displayName = $username;
    }

    $password = (string) ($data['password'] ?? '');
    $minPassword = zm_password_min_length();
    if ($password === '' || strlen($password) < $minPassword) {
        zm_fail(422, 'weak_password', [
            'message' => 'Hasło musi mieć minimum ' . $minPassword . ' znaków.',
        ]);
    }

    $vipRankId = zm_vip_rank_id($pdo, true);
    if ($vipRankId === null) {
        zm_fail(500, 'vip_rank_missing', [
            'message' => 'Nie udało się przygotować rangi VIP. Spróbuj ponownie.',
        ]);
    }

    try {
        $pdo->prepare(
            "INSERT INTO users
                (username, email, password_hash, display_name, role, profile_public, special_rank, created_at)
             VALUES
                (:u, :e, :ph, :d, 'user', 0, :vip, NOW())"
        )->execute([
            'u' => $username,
            'e' => $email,
            'ph' => password_hash($password, PASSWORD_DEFAULT),
            'd' => $displayName,
            'vip' => $vipRankId,
        ]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            $msg = stripos($e->getMessage(), 'email') !== false
                ? 'Ten adres e-mail jest już przypisany do innego konta.'
                : 'Ten login jest już zajęty.';
            zm_fail(422, 'taken', ['message' => $msg]);
        }
        throw $e;
    }

    $id = (int) $pdo->lastInsertId();
    zm_security_event(
        'admin_action',
        'warn',
        (string) $admin['username'],
        'utworzono konto #' . $id . ' (' . $username . ', ranga VIP)'
    );

    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => $id,
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email ?? '',
            'role' => 'user',
            'profile_public' => false,
            'rank_special_name' => 'VIP',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'user_rank') {
    $id = (int) ($data['id'] ?? 0);
    $rankId = (int) ($data['rank_id'] ?? -1);          // -1 = nie zmieniaj zwykłej rangi
    $specialRankId = (int) ($data['special_rank_id'] ?? -1); // -1 = nie zmieniaj specjalnej

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $chk->execute(['id' => $id]);
    if (!$chk->fetch()) {
        zm_fail(404, 'not_found');
    }

    if ($rankId !== -1) {
        if ($rankId === 0) {
            // powrót do rangi automatycznej (z punktów)
            $pdo->prepare('UPDATE users SET rank_override = NULL WHERE id = :id')->execute(['id' => $id]);
        } else {
            $rd = $pdo->prepare('SELECT id, special FROM rank_defs WHERE id = :id');
            $rd->execute(['id' => $rankId]);
            $rdRow = $rd->fetch();
            if (!$rdRow) {
                zm_fail(422, 'bad_rank', ['message' => 'Nie ma takiej rangi.']);
            }
            if (!empty($rdRow['special'])) {
                zm_fail(422, 'special_only', ['message' => 'Ranga specjalna idzie do sekcji „Ranga specjalna”.']);
            }
            $pdo->prepare('UPDATE users SET rank_override = :r WHERE id = :id')->execute(['r' => $rankId, 'id' => $id]);
        }
    }

    if ($specialRankId !== -1) {
        if ($specialRankId === 0) {
            // zdejmij rangę specjalną (użytkownik zostaje tylko ze zwykłą z punktów)
            $pdo->prepare('UPDATE users SET special_rank = NULL WHERE id = :id')->execute(['id' => $id]);
        } else {
            $rd = $pdo->prepare('SELECT id, special FROM rank_defs WHERE id = :id');
            $rd->execute(['id' => $specialRankId]);
            $rdRow = $rd->fetch();
            if (!$rdRow || empty($rdRow['special'])) {
                zm_fail(422, 'bad_special_rank', ['message' => 'Wybierz rangę specjalną z listy.']);
            }
            $pdo->prepare('UPDATE users SET special_rank = :r WHERE id = :id')->execute(['r' => $specialRankId, 'id' => $id]);
        }
    }

    $row = $pdo->prepare('SELECT points, rank_override, special_rank FROM users WHERE id = :id');
    $row->execute(['id' => $id]);
    $u = $row->fetch();
    $rank = zm_user_rank($pdo, $u);
    $spRank = zm_user_rank_special($pdo, $u);

    echo json_encode([
        'ok' => true,
        'rank_id' => (int) $rank['id'],
        'rank_name' => (string) $rank['name'],
        'rank_manual' => (bool) $rank['manual'],
        'rank_special' => $spRank !== null,
        'rank_special_id' => $spRank === null ? null : (int) $spRank['id'],
        'rank_special_name' => $spRank === null ? null : (string) $spRank['name'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'user_role') {
    $id = (int) ($data['id'] ?? 0);
    $role = (string) ($data['role'] ?? '');

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    if ($id === (int) $admin['id']) {
        zm_fail(422, 'cannot_role_self', ['message' => 'Nie można zmienić roli własnego konta.']);
    }
    if ($admin['username'] !== ZM_SUPERADMIN_LOGIN) {
        zm_fail(403, 'super_admin_only', ['message' => 'Prawa administratora może nadać/odebrać tylko użytkownik „admin”.']);
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        zm_fail(422, 'bad_role', ['message' => 'Rola może być „admin” albo „user”.']);
    }

    $chk = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
    $chk->execute(['id' => $id]);
    $row = $chk->fetch();
    if (!$row) {
        zm_fail(404, 'not_found');
    }
    if ((string) $row['role'] === $role) {
        zm_fail(422, 'no_change', ['message' => 'Konto już ma tę rolę.']);
    }

    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute(['r' => $role, 'id' => $id]);

    zm_security_event('admin_action', 'warn', (string) $admin['username'], 'zmiana roli konta #' . $id . ' na ' . $role);

    echo json_encode(['ok' => true, 'id' => $id, 'role' => $role], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'user_points') {
    $id = (int) ($data['id'] ?? 0);
    $delta = (int) ($data['delta'] ?? 0);
    $note = preg_replace('/\s+/u', ' ', trim((string) ($data['note'] ?? ''))) ?? '';
    $note = mb_substr($note, 0, 255);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    if ($delta === 0 || $delta > 10000 || $delta < -10000) {
        zm_fail(422, 'bad_delta', ['message' => 'Podaj zmianę punktów od -10000 do 10000 (bez 0).']);
    }
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $chk->execute(['id' => $id]);
    if (!$chk->fetch()) {
        zm_fail(404, 'not_found');
    }

    $res = zm_apply_points($pdo, $id, $delta, 'admin_manual', null, null, (int) $admin['id'], $note !== '' ? $note : null);

    // Powiadomienie do użytkownika — zmiana punktów jest decyzją, którą musi
    // zobaczyć (i zwykle skomentować). Powód administratora jedzie w `body`,
    // bo to on tłumaczy, dlaczego punkty się zmieniły.
    zm_notify_user($pdo, $id, 'rank_changed', (int) $admin['id'], [
        'ref_type' => 'user',
        'ref_id'   => $id,
        'body'     => ($res['delta'] > 0 ? 'dostał +' : '')
            . $res['delta'] . ' punktów'
            . ($note !== '' ? ' — ' . $note : '')
            . '. Masz teraz ' . $res['points'] . ' pkt.',
    ]);

    echo json_encode(['ok' => true, 'points' => $res['points'], 'delta' => $res['delta']]);
    exit;
}

if ($action === 'user_ban') {
    $id = (int) ($data['id'] ?? 0);
    $banned = !empty($data['banned']);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    if ($id === (int) $admin['id']) {
        zm_fail(422, 'cannot_ban_self', ['message' => 'Nie można zbanować własnego konta.']);
    }
    $chk = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
    $chk->execute(['id' => $id]);
    $row = $chk->fetch();
    if (!$row) {
        zm_fail(404, 'not_found');
    }
    if ((string) $row['role'] === 'admin' && $admin['username'] !== ZM_SUPERADMIN_LOGIN) {
        zm_fail(403, 'admin_protected', ['message' => 'Konto administratora może zbanować/odblokować tylko użytkownik „admin”.']);
    }

    if ($banned) {
        $pdo->prepare(
            'UPDATE users SET is_banned = 1, banned_at = NOW(), banned_by = :by WHERE id = :id'
        )->execute(['by' => (int) $admin['id'], 'id' => $id]);
    } else {
        $pdo->prepare(
            'UPDATE users SET is_banned = 0, banned_at = NULL, banned_by = NULL WHERE id = :id'
        )->execute(['id' => $id]);
    }

    /*
     * Powiadomienie o blokadzie / odblokowaniu. Bez niego użytkownik nie wie,
     * dlaczego nagle nie może się zalogować — a przy zbanowanym koncie
     * powiadomienie i tak nie zostanie dostarczone (zm_notify_user pomija
     * konta zbanowane), więc odblokowanie tę informację wreszcie pokaże.
     */
    zm_notify_user($pdo, $id, 'rank_changed', (int) $admin['id'], [
        'ref_type' => 'user',
        'ref_id'   => $id,
        'body'     => $banned
            ? 'Twoje konto zostało zablokowane przez administrację.'
            : 'Twoje konto zostało odblokowane — możesz znowu się logować.',
    ]);

    zm_security_event('admin_action', 'warn', (string) $admin['username'],
        ($banned ? 'ban' : 'unban') . ' konta #' . $id . ' ' . (string) $row['id']);

    echo json_encode(['ok' => true, 'banned' => $banned]);
    exit;
}

if ($action === 'user_delete') {
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    if ($id === (int) $admin['id']) {
        zm_fail(422, 'cannot_delete_self', ['message' => 'Nie można usunąć własnego konta.']);
    }
    $sel = $pdo->prepare('SELECT id, username, role, avatar_path FROM users WHERE id = :id');
    $sel->execute(['id' => $id]);
    $row = $sel->fetch();
    if (!$row) {
        zm_fail(404, 'not_found');
    }
    if ((string) $row['role'] === 'admin' && $admin['username'] !== ZM_SUPERADMIN_LOGIN) {
        zm_fail(403, 'admin_protected', ['message' => 'Administratora może usunąć tylko użytkownik „admin”.']);
    }

    // FK robią dużą część roboty: photo_likes i points_history idą kaskadą,
    // a photos.submitted_by_user_id / users.banned_by / users.rank_override
    // dostają NULL. Reszta (kolumny bez FK) — ręcznie, w transakcji.
    try {
        $pdo->beginTransaction();

        $commentIdsStmt = $pdo->prepare('SELECT id FROM comments WHERE user_id = :id1 UNION SELECT comment_id AS id FROM comment_likes WHERE user_id = :id2');
        $commentIdsStmt->execute(['id1' => $id, 'id2' => $id]);
        $affectedCommentIds = array_map('intval', $commentIdsStmt->fetchAll(PDO::FETCH_COLUMN));
        $photoIdsStmt = $pdo->prepare('SELECT photo_id FROM user_favorites WHERE user_id = :id');
        $photoIdsStmt->execute(['id' => $id]);
        $affectedPhotoIds = array_map('intval', $photoIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        // Komentarze zostają, ale stają się anonimowe.
        $pdo->prepare('UPDATE comments SET user_id = NULL WHERE user_id = :id')->execute(['id' => $id]);
        // Polubienia komentarzy i ulubione zdjęć nie mają FK — czyścimy.
        $pdo->prepare('DELETE FROM comment_likes WHERE user_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM user_favorites WHERE user_id = :id')->execute(['id' => $id]);

        // Liczniki są cache'ami: po skasowaniu reakcji przeliczamy je ze źródeł prawdy.
        $syncComment = $pdo->prepare("UPDATE comments SET likes = (SELECT COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN CONCAT('u:', user_id) ELSE CONCAT('v:', voter) END) FROM comment_likes WHERE comment_id = :cid) WHERE id = :id");
        foreach ($affectedCommentIds as $commentId) $syncComment->execute(['cid' => $commentId, 'id' => $commentId]);
        $syncPhoto = $pdo->prepare('UPDATE photos SET favorites = (SELECT COUNT(*) FROM user_favorites WHERE photo_id = :pid) WHERE id = :id');
        foreach ($affectedPhotoIds as $photoId) $syncPhoto->execute(['pid' => $photoId, 'id' => $photoId]);

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        zm_fail(500, 'server_error');
    }

    // Awatar — plik w AVATARS_DIR, usuwamy po udanej transakcji (best effort).
    $avatar = trim((string) ($row['avatar_path'] ?? ''));
    if ($avatar !== '') {
        $file = AVATARS_DIR . '/' . basename(str_replace('\\', '/', $avatar));
        if (is_file($file)) {
            @unlink($file);
        }
    }

    zm_security_event('admin_action', 'danger', (string) $admin['username'],
        'TRWAŁE usunięcie konta #' . $id . ' ' . (string) $row['username']);

    echo json_encode(['ok' => true, 'deleted' => true, 'id' => $id, 'username' => (string) $row['username']]);
    exit;
}

/* ------------------------------------------------- powiadomienia (panel admina) */

/*
 * Zapis ustawień typu powiadomienia. To JEDNO miejsce, w którym administrator
 * włącza/wyłącza typ i decyduje, komu ma trafiać — panel tylko tu zapisuje,
 * więc serwis nie ma dwóch konfiguracji, które mogłyby się rozjechać.
 *
 * `user_manage` jest statyczne (z rejestru) i celowo NIE jest zapisywane
 * z formularza — inaczej administrator mógłby otworzyć użytkownikowi
 * przełącznik dla typu decyzji administracyjnej.
 */
if ($action === 'notify_type_save') {
    $type = (string) ($data['type'] ?? '');
    $cfg = zm_notify_type($pdo, $type);
    if ($cfg === null) {
        zm_fail(400, 'unknown_type', ['message' => 'Nie ma takiego typu powiadomienia.']);
    }

    $label = trim((string) ($data['label'] ?? ''));
    if ($label === '') {
        $label = $cfg['label'];
    }
    if (mb_strlen($label) > 80) {
        zm_fail(422, 'bad_label', ['message' => 'Nazwa typu może mieć maksymalnie 80 znaków.']);
    }

    $enabled = (int) (bool) ($data['enabled'] ?? false);
    $toOwner = (int) (bool) ($data['to_owner'] ?? false);
    $toFollowers = (int) (bool) ($data['to_followers'] ?? false);
    $toAdmin = (int) (bool) ($data['to_admin'] ?? false);

    /*
     * Sensowność: włączony typ bez odbiorcy to martwa konfiguracja —
     * odrzucamy z komunikatem, żeby administrator nie zastanawiał się,
     * czemu nic się nie dzieje.
     */
    if ($enabled && !$toOwner && !$toFollowers && !$toAdmin) {
        zm_fail(422, 'no_target', [
            'message' => 'Włączony typ musi trafić do kogoś — zaznacz właściciela, obserwujących albo administrację.',
        ]);
    }

    $upd = $pdo->prepare(
        'UPDATE notification_config
            SET label = :l, enabled = :e, to_owner = :o, to_followers = :f, to_admin = :a,
                updated_at = :u
          WHERE type = :t'
    );
    $upd->execute([
        'l' => $label,
        'e' => $enabled,
        'o' => $toOwner,
        'f' => $toFollowers,
        'a' => $toAdmin,
        'u' => (int) round(microtime(true) * 1000),
        't' => $type,
    ]);

    echo json_encode(['ok' => true, 'type' => $type], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Oznaczenie alertu jako przeczytanego / nieprzeczytanego. */
if ($action === 'notify_alert_read') {
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        zm_fail(400, 'invalid');
    }
    $read = (int) (bool) ($data['read'] ?? true);

    // warunek `user_id IS NULL` pilnuje, że administrator operuje wyłącznie
    // na alertach dla administracji — nie dotknie cudzej skrzynki
    $st = $pdo->prepare(
        'UPDATE notifications SET is_read = :r WHERE id = :id AND user_id IS NULL'
    );
    $st->execute(['r' => $read, 'id' => $id]);

    $unread = (int) $pdo->query(
        'SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND is_read = 0'
    )->fetchColumn();

    echo json_encode(['ok' => true, 'unread' => $unread], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Oznaczenie wszystkich alertów jako przeczytanych (albo kasowanie obsłużonych). */
if ($action === 'notify_alerts_handle_all') {
    $mode = (string) ($data['mode'] ?? 'read');
    if (!in_array($mode, ['read', 'clear'], true)) {
        zm_fail(400, 'invalid');
    }

    if ($mode === 'read') {
        $st = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0');
    } else {
        $st = $pdo->prepare('DELETE FROM notifications WHERE user_id IS NULL AND is_read = 1');
    }
    $st->execute();

    echo json_encode(['ok' => true, 'affected' => $st->rowCount(), 'unread' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * Ogłoszenie do wszystkich użytkowników. Jedyne miejsce w systemie, które
 * robi wiersz na każde konto — dlatego świadomie omijamy tutaj limit
 * ZM_NOTIFY_MAX_RECIPIENTS, który dotyczy tylko rozsyłania po obserwujących.
 * Pętla idzie po blokach po 500 kont, żeby nie trzymać w pamięci całej listy.
 */
if ($action === 'notify_broadcast') {
    $body = trim((string) ($data['body'] ?? ''));
    if ($body === '') {
        zm_fail(422, 'empty', ['message' => 'Treść ogłoszenia jest pusta.']);
    }
    if (mb_strlen($body) > 300) {
        zm_fail(422, 'too_long', ['message' => 'Ogłoszenie może mieć maksymalnie 300 znaków.']);
    }

    $cfg = zm_notify_type($pdo, 'admin_broadcast');
    if ($cfg === null || !$cfg['enabled']) {
        zm_fail(422, 'type_disabled', [
            'message' => 'Typ „Ogłoszenie administracji” jest wyłączony w konfiguracji.',
        ]);
    }

    $ts = (int) round(microtime(true) * 1000);
    $sent = 0;
    $skipped = 0;

    // kursor po id zamiast OFFSET — przy dużej bazie OFFSET jest kosztowny
    $lastId = 0;
    while (true) {
        $sel = $pdo->prepare(
            'SELECT id FROM users WHERE id > :last AND is_banned = 0 ORDER BY id LIMIT 500'
        );
        $sel->execute(['last' => $lastId]);
        $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            break;
        }
        $lastId = $ids[count($ids) - 1];

        $ins = $pdo->prepare(
            'INSERT INTO notifications
                (user_id, actor_id, type, ref_type, ref_id, body, is_read, created_at)
             VALUES (:u, :a, :t, NULL, NULL, :b, 0, :c)'
        );
        foreach ($ids as $uid) {
            // własny wyłącznik użytkownika ma pierwszeństwo nad wolą administratora
            if (!zm_notify_wants($pdo, $uid, 'admin_broadcast')) {
                $skipped++;
                continue;
            }
            $ins->execute([
                'u' => $uid,
                'a' => (int) $admin['id'],
                't' => 'admin_broadcast',
                'b' => $body,
                'c' => $ts,
            ]);
            $sent++;
        }
    }

    zm_log($pdo, 'notify_broadcast', $admin['username'], '', $body);

    echo json_encode([
        'ok' => true,
        'sent' => $sent,
        // liczba pominiętych — pokazujemy ją, żeby administrator wiedział,
        // że nie wszyscy dostali ogłoszenie (ktoś ma typ wyłączony)
        'skipped' => $skipped,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rank_save') {
    $ranks = $data['ranks'] ?? null;
    if (!is_array($ranks) || count($ranks) < 1 || count($ranks) > 20) {
        zm_fail(422, 'bad_ranks', ['message' => 'Lista rang jest nieprawidłowa.']);
    }

    $clean = [];
    $names = [];
    $prevMin = -1;
    foreach ($ranks as $i => $r) {
        $pos = $i + 1;
        $name = trim((string) ($r['name'] ?? ''));
        $min = (int) ($r['min_points'] ?? 0);
        $special = (int) (bool) ($r['special'] ?? false);
        if ($name === '' || mb_strlen($name) > 60) {
            zm_fail(422, 'bad_rank_name', ['message' => 'Nazwa rangi musi mieć od 1 do 60 znaków.']);
        }
        if ($min < 0 || $min > 99999999) {
            zm_fail(422, 'bad_rank_min', ['message' => 'Próg punktów rangi jest poza zakresem.']);
        }
        if ($pos === 1 && $min !== 0) {
            zm_fail(422, 'rank_start_not_zero', ['message' => 'Najniższa ranga musi zaczynać się od 0 punktów.']);
        }
        if (!$special && $min <= $prevMin) {
            zm_fail(422, 'rank_not_sorted', ['message' => 'Progi rang muszą rosnąć.']);
        }
        $bn = mb_strtolower($name);
        if (isset($names[$bn])) {
            zm_fail(422, 'duplicate_rank_name', ['message' => 'Nazwy rang nie mogą się powtarzać.']);
        }
        $names[$bn] = true;
        if (!$special) {
            // ranga specjalna jest nadawana ręcznie — nie liczy się w progresji punktowej,
            // więc jej „próg" nie może zepsuć rosnącego ciągu progów zwykłych rang
            $prevMin = $min;
        }
        $clean[] = [
            'id' => (int) ($r['id'] ?? 0),
            'position' => $pos,
            'name' => $name,
            'min_points' => $min,
            'special' => $special,
        ];
    }

    $existingIds = [];
    foreach ($pdo->query('SELECT id FROM rank_defs')->fetchAll() as $e) {
        $existingIds[(int) $e['id']] = true;
    }

    $upd = $pdo->prepare(
        'UPDATE rank_defs SET position = :p, name = :n, min_points = :m, special = :s WHERE id = :id'
    );
    $ins = $pdo->prepare(
        'INSERT INTO rank_defs (position, name, min_points, special) VALUES (:p, :n, :m, :s)'
    );
    $seen = [];

    foreach ($clean as $c) {
        if ($c['id'] > 0 && isset($existingIds[$c['id']])) {
            $upd->execute([
                'p' => $c['position'],
                'n' => $c['name'],
                'm' => $c['min_points'],
                's' => $c['special'],
                'id' => $c['id'],
            ]);
            $seen[$c['id']] = true;
        } else {
            $ins->execute(['p' => $c['position'], 'n' => $c['name'], 'm' => $c['min_points'], 's' => $c['special']]);
            $seen[(int) $pdo->lastInsertId()] = true;
        }
    }

    // usuwamy rangi, których admin nie wysłał (rank_override idzie w NULL przez FK);
    // liczba rang po operacji = count($clean), a to ≥ 1 jest już zwalidowane wyżej
    foreach ($existingIds as $eid => $v) {
        if (!isset($seen[$eid])) {
            $pdo->prepare('DELETE FROM rank_defs WHERE id = :id')->execute(['id' => $eid]);
        }
    }

    echo json_encode(['ok' => true] + zm_admin_rank_payload($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

zm_fail(400, 'unknown_action');

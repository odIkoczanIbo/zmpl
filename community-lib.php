<?php
/**
 * zmogilna — dane sekcji „WSPÓLNY ARCHIWUM” na stronie głównej.
 *
 * Wydzielone z index.php, bo panel ma się odświeżać bez przeładowania strony:
 * ten sam kod musi zadziałać w community-api.php, które zwraca gotowy HTML.
 * Dzięki temu jest jedno miejsce definiujące zapytania — strona i endpoint
 * nie mogą się rozjechać.
 */

declare(strict_types=1);

function zm_home_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Opisy gmin z tabeli categories (pusty obiekt, gdy tabeli jeszcze nie ma).
 */
function zm_home_gmina_descriptions(PDO $pdo): object
{
    try {
        $rows = $pdo->query(
            "SELECT name, description FROM categories WHERE description IS NOT NULL AND description <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r['name']] = (string) $r['description'];
            }
            return $out;
        }
    } catch (Throwable $e) {
        // brak opisów nie blokuje strony
    }
    return new stdClass();
}

/**
 * Sygnatura treści panelu. Strona porównuje ją z tym, co dostała z endpointu
 * i podmienia HTML tylko wtedy, gdy coś się faktycznie zmieniło — inaczej
 * podmiana co 20 s przestawiałaby obrazy i skakałaby przy przewijaniu.
 */
function zm_home_community_hash(array $data): string
{
    return substr(md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
}
/**
 * Komplet danych panelu. Pokazujemy wyłącznie osoby z publicznym profilem —
 * prywatne konta nie są ujawniane nigdzie w tym panelu.
 *
 * Panel ma charakter dodatkowy: błąd danych nie może wywrócić strony, więc
 * przy wyjątku zwracamy strukturę z zerami i pustymi listami.
 */
function zm_home_community(PDO $pdo): array
{
    $data = [
        'users' => 0,
        'photos' => 0,
        'categories' => 0,
        'members' => [],
        'photosLatest' => [],
        'contributors' => [],
        'topPlaces' => [],
        'topPhotos' => [],
        'photosWeek' => 0,
        'likesWeek' => 0,
        'membersWeek' => 0,
    ];

    try {
        zm_publish_due($pdo);
        $data['users'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM users u
              WHERE u.is_banned = 0 AND ' . zm_sql_stats_visible_user('u')
        )->fetchColumn();
        $data['photos'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM photos WHERE published = 1 AND moderation_status = 'approved' AND (kind IS NULL OR kind = 'image')"
        )->fetchColumn();
        $data['categories'] = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();

        $membersStmt = $pdo->query(
            "SELECT id, username, display_name, avatar_path, avatar_updated_at, created_at,
                    last_seen, show_online
             FROM users
             WHERE profile_public = 1 AND is_banned = 0
             ORDER BY created_at DESC, id DESC
             LIMIT 6"
        );
        $data['members'] = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

        $photosStmt = $pdo->query(
            "SELECT p.id, p.title, p.filename, p.place, p.gmina_detected, p.geo_json,
                    p.taken_at, p.created_at, c.name AS category_name
             FROM photos p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.published = 1 AND p.moderation_status = 'approved'
               AND (p.kind IS NULL OR p.kind = 'image')
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 6"
        );
        $data['photosLatest'] = $photosStmt->fetchAll(PDO::FETCH_ASSOC);
// --- ranking wkładowców (punkty + ranga ze zm_rank_defs) ---
        // Tylko osoby z publicznym profilem: ranking nie może ujawniać kont,
        // które zdecydowały się pozostać prywatne.
        $contributorsStmt = $pdo->query(
            "SELECT u.id, u.username, u.display_name, u.avatar_path, u.avatar_updated_at,
                    u.points, u.last_seen, u.show_online,
                    (SELECT COUNT(*) FROM photos pp
                      WHERE pp.submitted_by_user_id = u.id AND pp.published = 1
                        AND pp.moderation_status = 'approved'
                        AND (pp.kind IS NULL OR pp.kind = 'image')) AS photo_count,
                    (SELECT COUNT(*) FROM photo_likes pl
                       JOIN photos lp ON lp.id = pl.photo_id
                      WHERE lp.submitted_by_user_id = u.id AND lp.published = 1) AS likes_given,
                    (SELECT COUNT(*) FROM photo_likes pl2
                       JOIN photos lp2 ON lp2.id = pl2.photo_id
                      WHERE lp2.submitted_by_user_id = u.id AND lp2.published = 1
                        AND lp2.moderation_status = 'approved'
                        AND (lp2.kind IS NULL OR lp2.kind = 'image')) AS likes_got
             FROM users u
             WHERE u.profile_public = 1 AND u.is_banned = 0
             ORDER BY u.points DESC, photo_count DESC, u.id ASC
             LIMIT 6"
        );
        $data['contributors'] = $contributorsStmt->fetchAll(PDO::FETCH_ASSOC);

        // --- najaktywniejsze miejsca (liczba zdjęć w kategorii) ---
        $topPlacesStmt = $pdo->query(
            "SELECT c.id, c.name,
                    (SELECT COUNT(*) FROM photos p
                      WHERE p.category_id = c.id AND p.published = 1
                        AND p.moderation_status = 'approved'
                        AND (p.kind IS NULL OR p.kind = 'image')) AS photo_count
             FROM categories c
             ORDER BY photo_count DESC, c.name ASC
             LIMIT 13"
        );
        $data['topPlaces'] = array_values(array_filter(
            $topPlacesStmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $r): bool => (int) $r['photo_count'] > 0
        ));
// --- najpopularniejsze kadry (najwięcej polubień) ---
        $topPhotosStmt = $pdo->query(
            "SELECT p.id, p.title, p.filename, p.place, p.gmina_detected, p.geo_json,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM photo_likes pl WHERE pl.photo_id = p.id) AS like_count
             FROM photos p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.published = 1 AND p.moderation_status = 'approved'
               AND (p.kind IS NULL OR p.kind = 'image')
             ORDER BY like_count DESC, p.id DESC
             LIMIT 6"
        );
        $data['topPhotos'] = array_values(array_filter(
            $topPhotosStmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $r): bool => (int) $r['like_count'] > 0
        ));

        // --- aktywność w ostatnim tygodniu (podejście bez tabeli zdarzeń) ---
        // photos.created_at i users.created_at to TIMESTAMP; photo_likes.created_at
        // trzyma BIGINT w milisekundach od epoch.
        $weekAgoDate = date('Y-m-d H:i:s', (int) strtotime('-7 days'));
        $weekAgoTs = (int) strtotime('-7 days') * 1000;
        $data['photosWeek'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM photos
             WHERE published = 1 AND moderation_status = 'approved'
               AND (kind IS NULL OR kind = 'image')
               AND created_at >= '" . $weekAgoDate . "'"
        )->fetchColumn();
        $data['likesWeek'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM photo_likes WHERE created_at >= ' . $weekAgoTs
        )->fetchColumn();
        $data['membersWeek'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM users u
              WHERE u.is_banned = 0 AND u.created_at >= '"
            . $weekAgoDate . "' AND " . zm_sql_stats_visible_user('u')
        )->fetchColumn();
    } catch (Throwable $e) {
        // problem z danymi nie może blokować galerii
    }

    return $data;
}
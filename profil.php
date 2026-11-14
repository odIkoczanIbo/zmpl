<?php
/**
 * zmogilna — publiczny profil użytkownika: /<login> (np. /admin)
 *
 * Ten sam widok obsługuje też stare adresy profil.php?u=login i profil.php?id=123,
 * więc wszystkie wcześniej opublikowane linki działają bez zmian.
 *
 * Widoczny dla wszystkich TYLKO gdy właściciel włączył „Profil publiczny”
 * w panelu (panel.php). Właściciel i administrator zawsze widzą podgląd.
 * Pokazuje: nazwę, @login, miejscowość, opis, linki społecznościowe i
 * zatwierdzone (po moderacji) zdjęcia użytkownika.
 *
 * E-mail NIE jest pokazywany nigdzie na tym profilu — jest prywatny i widzi go
 * wyłącznie właściciel, w panelu konta (panel.php → „Adres e-mail”).
 */

declare(strict_types=1);

// profil i jego siatki czytane są z bazy — dokument zawsze z sieci
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/photos-lib.php';
require_once __DIR__ . '/thumbs-lib.php';
// Starsza kopia photos-lib.php na serwerze może nie mieć tego helpera.
// Zachowujemy zgodność profilu bez wymuszania równoczesnej podmiany biblioteki.
if (!function_exists('zm_photo_locality')) {
    function zm_photo_locality(array $row): string
    {
        $geoJson = trim((string) ($row['geo_json'] ?? ''));
        if ($geoJson !== '') {
            $geo = json_decode($geoJson, true);
            if (is_array($geo)) {
                $miejscowosc = trim((string) ($geo['miejscowosc'] ?? ''));
                if ($miejscowosc !== '') {
                    return $miejscowosc;
                }
            }
        }

        $categoryName = trim((string) ($row['category_name'] ?? ''));
        if ($categoryName !== '') {
            return $categoryName;
        }

        $gmina = trim((string) ($row['gmina_detected'] ?? ''));
        if ($gmina !== '') {
            return $gmina;
        }

        return trim((string) ($row['place'] ?? ''));
    }
}
// przyciski „Obserwuj" i „Napisz wiadomość" + liczniki obserwujących
require_once __DIR__ . '/social-lib.php';

// licznik ruchu (bez cookies; surowe IP wyłącznie w panelu admina przez 30 dni)
require_once __DIR__ . '/traffic-lib.php';
zm_traffic_track($pdo);

function zp_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zp_plural(int $n, string $one, string $few, string $many): string
{
    if ($n === 1) {
        return $one;
    }
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? $few : $many;
}

/** Znacznik czasu z comments.created_at (ms) w takim samym formacie, jak na stronie głównej. */
function format_comment_ts(int $ts): string
{
    if ($ts <= 0) {
        return '—';
    }
    $dt = (new DateTimeImmutable('@' . intdiv($ts, 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format('d.m.Y H:i');
}

/**
 * Przycisk „polubień” komentarza — wygląda jak zwykły licznik w .feed-meta,
 * ale jest klikalny (jak w galerii). Stan `is-on` aktualizuje JS na profilu.
 */
function zp_like_btn(int $cid, int $likes, bool $liked): string
{
    $word = zp_plural($likes, 'polubienie', 'polubienia', 'polubień');
    $icon = $liked
        ? '<svg class="zp-like-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
        : '<svg class="zp-like-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    return '<button type="button" class="zp-like' . ($liked ? ' is-on' : '') . '" data-cid="' . $cid . '"'
        . ' aria-pressed="' . ($liked ? 'true' : 'false') . '" title="' . ($liked ? 'Cofnij polubienie' : 'Polub komentarz') . '">'
        . $icon
        . '<span class="zp-like-n">' . $likes . '</span> <span class="zp-like-w">' . $word . '</span></button>';
}

function zp_initials(string $name): string
{
    $parts = preg_split('~\s+~', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) >= 2) {
        $r = mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1);
    } elseif (count($parts) === 1) {
        $r = mb_substr($parts[0], 0, 2);
    } else {
        $r = '?';
    }
    return mb_strtoupper($r);
}

$viewer = zm_current_user();
$cssVer = (string) (@filemtime(__DIR__ . '/konto.css') ?: 1);

// Profil otwieramy po loginie (?u=login) ALBO po numerze (?id=123).
// Odznaka „dodał/a” w galerii i popup profilu linkują po numerze, więc oba adresy muszą działać.
$username = (string) ($_GET['u'] ?? '');
$byId = (int) ($_GET['id'] ?? 0);
$row = null;
$profileCols = 'id, username, display_name, role, created_at, bio, location,
                link_facebook, link_instagram, link_youtube, link_tiktok, link_website, profile_public,
                avatar_path, avatar_updated_at, show_comments, last_seen, show_online';
if (preg_match('~^[A-Za-z0-9_.\-]{3,40}$~', $username)) {
    $stmt = $pdo->prepare("SELECT $profileCols FROM users WHERE username = :u LIMIT 1");
    $stmt->execute(['u' => $username]);
    $row = $stmt->fetch() ?: null;
} elseif ($byId > 0) {
    $stmt = $pdo->prepare("SELECT $profileCols FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $byId]);
    $row = $stmt->fetch() ?: null;
}

$isOwner = $row !== null && $viewer !== null && (int) $viewer['id'] === (int) $row['id'];
$isAdminViewer = $viewer !== null && $viewer['role'] === 'admin';
$isPublic = $row !== null && (int) ($row['profile_public'] ?? 0) === 1;
$visible = $row !== null && ($isPublic || $isOwner || $isAdminViewer);

// obecność — zielona kropka przy zdjęciu, tylko gdy profil się pokazuje.
// Wyłącznik `show_online` gasi ją ręcznie (panel konta, „Jestem aktywny"),
// niezależnie od tego, czy użytkownik właśnie na stronie jest.
$isOnline = $visible && (int) ($row['show_online'] ?? 1) === 1
    && zm_presence_online((int) ($row['last_seen'] ?? 0));

// relacje blokady widza z właścicielem profilu — to, co widać w akcjach
$iBlockedThem = $visible && $viewer !== null && !$isOwner
    && zm_social_blocked_by($pdo, (int) $viewer['id'], (int) $row['id']);
$theyBlockedMe = $visible && $viewer !== null && !$isOwner
    && zm_social_blocked_by($pdo, (int) $row['id'], (int) $viewer['id']);

// Adres e-mail właściciela NIE jest częścią tego widoku — widać go tylko
// w panelu konta. Nie pobieramy go nawet do zmiennej, żeby przypadkowe
// echo w szablonie nie mogło go wyciągnąć dla przypadkowego gościa.
$photos = [];
$photoTotal = 0;
if ($visible) {
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM photos
         WHERE submitted_by_user_id = :id AND moderation_status = 'approved' AND published = 1
           AND (kind IS NULL OR kind = 'image')"
    );
    $cnt->execute(['id' => $row['id']]);
    $photoTotal = (int) $cnt->fetchColumn();

    $ph = $pdo->prepare(
        "SELECT p.id, p.title, p.filename, p.place, p.gmina_detected, p.geo_json,
                p.lat, p.lng, p.taken_at, c.name AS category_name
         FROM photos p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.submitted_by_user_id = :id AND p.moderation_status = 'approved' AND p.published = 1
           AND (p.kind IS NULL OR p.kind = 'image')
         ORDER BY p.id DESC LIMIT 60"
    );
    $ph->execute(['id' => $row['id']]);
    $photos = $ph->fetchAll();

    // Menu folderów (gminy i podkatalogi) nad siatką zdjęć: tylko foldery, w których ten
    // użytkownik ma zatwierdzone zdjęcia. Błąd (np. brak kolumny parent_id) nie psuje profilu —
    // menu po prostu się nie pokaże.
    $folderData = ['cats' => [], 'photos' => []];
    try {
        $cq = false;
        $hasParent = false;
        try {
            $cq = $pdo->query('SELECT id, name, parent_id FROM categories');
            $hasParent = $cq !== false;
        } catch (Throwable $e) {
            $cq = false;
        }
        if (!$cq) {
            $cq = $pdo->query('SELECT id, name FROM categories');
        }
        foreach ($cq->fetchAll() as $c) {
            $folderData['cats'][] = [(int) $c['id'], (string) $c['name'], $hasParent ? (int) ($c['parent_id'] ?? 0) : 0];
        }
        $fp = $pdo->prepare(
            "SELECT p.id, p.category_id, p.title, p.place, p.gmina_detected, p.geo_json, c.name AS category_name
             FROM photos p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.submitted_by_user_id = :id AND p.moderation_status = 'approved' AND p.published = 1
               AND (p.kind IS NULL OR p.kind = 'image')
             ORDER BY p.id DESC"
        );
        $fp->execute(['id' => $row['id']]);
        foreach ($fp->fetchAll() as $p) {
            $fName = zm_photo_locality($p);
            if ($fName === '') {
                $fName = trim((string) ($p['title'] ?? '')) ?: trim((string) ($p['place'] ?? ''));
            }
            if ($fName === '') {
                $fName = 'zdjęcie';
            }
            $folderData['photos'][] = [(int) $p['id'], (int) ($p['category_id'] ?? 0), $fName, zm_thumb_url('p' . (int) $p['id'], 480)];
        }
    } catch (Throwable $e) {
        $folderData = ['cats' => [], 'photos' => []];
    }
}

/*
 * Komentarze na profilu.
 *
 * Właściciel zawsze widzi swoje komentarze — także przy wyłączonym
 * „pokazuj komentarze”. Inni widzą je tylko wtedy, gdy profil jest
 * publiczny I show_comments = 1. Administrator widzi profil także
 * przy wyłączonym pokazywaniu (podgląd moderacji).
 */
$written = [];
$liked = [];
$commentTotal = 0;
$likedTotal = 0;
$showCommentsToViewer = $visible && ((int) ($row['show_comments'] ?? 0) === 1 || $isOwner || $isAdminViewer);

if ($showCommentsToViewer) {
    $cw = $pdo->prepare(
        "SELECT id, photo_id, photo_name, photo_thumb, text, created_at AS ts,
                (SELECT COUNT(DISTINCT CASE WHEN cl.user_id IS NOT NULL THEN CONCAT('u:', cl.user_id) ELSE CONCAT('v:', cl.voter) END) FROM comment_likes cl WHERE cl.comment_id = comments.id) AS likes
         FROM comments WHERE user_id = :id ORDER BY created_at DESC LIMIT 40"
    );
    $cw->execute(['id' => $row['id']]);
    $written = $cw->fetchAll();

    $cc = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE user_id = :id');
    $cc->execute(['id' => $row['id']]);
    $commentTotal = (int) $cc->fetchColumn();

    // Polubione komentarze bez własnych (te są już w „Napisane”).
    // `<=>` zamiast `<>`: user_id bywa NULL przy starych, anonimowych
    // komentarzach, a `NULL <> 3` to w SQL NULL, nie TRUE — zwykłe
    // porównanie po cichu wyrzucałoby wszystkie polubienia bez konta.
    $cl = $pdo->prepare(
        "SELECT c.id, c.photo_id, c.photo_name, c.photo_thumb, c.author, c.text, c.created_at AS ts,
                (SELECT COUNT(DISTINCT CASE WHEN cl2.user_id IS NOT NULL THEN CONCAT('u:', cl2.user_id) ELSE CONCAT('v:', cl2.voter) END) FROM comment_likes cl2 WHERE cl2.comment_id = c.id) AS likes
         FROM comment_likes l
         JOIN comments c ON c.id = l.comment_id
         WHERE l.user_id = :id AND NOT (c.user_id <=> :id2)
         ORDER BY l.created_at DESC LIMIT 40"
    );
    $cl->execute(['id' => $row['id'], 'id2' => $row['id']]);
    $liked = $cl->fetchAll();

    $clc = $pdo->prepare(
        'SELECT COUNT(*) FROM comment_likes l JOIN comments c ON c.id = l.comment_id
         WHERE l.user_id = :id AND NOT (c.user_id <=> :id2)'
    );
    $clc->execute(['id' => $row['id'], 'id2' => $row['id']]);
    $likedTotal = (int) $clc->fetchColumn();

    // Stan przycisków „polubień” dla zalogowanego widza. Anonimowy widz nie
    // ma tu swojego `voter`, więc serwer nie zna jego stanu — JS domaluje go
    // z pierwszego fetcha feedu (profil.php podaje tylko user_id z konta).
    $viewerLiked = [];
    if ($viewer !== null) {
        $allIds = array_unique(array_merge(
            array_map(static fn(array $r): int => (int) $r['id'], $written),
            array_map(static fn(array $r): int => (int) $r['id'], $liked)
        ));
        $viewerLiked = zm_viewer_liked_comment_ids($pdo, $allIds, $viewer, '');
    }

    // Zdjęcia, które wciąż są w galerii. Skasowane albo wycofane zdjęcie
    // daje photo.php 404, więc bez tej listy feed zapala puste kafelki.
    $feedPhotoIds = [];
    foreach (array_merge($written, $liked) as $r) {
        $feedPhotoIds[] = (string) $r['photo_id'];
    }
    $feedLivePhotos = [];
    if ($feedPhotoIds) {
        $nums = [];
        foreach ($feedPhotoIds as $pid) {
            if (preg_match('~^p(\d+)$~', $pid, $pm)) {
                $nums[(int) $pm[1]] = true;
            }
        }
        if ($nums) {
            $marks = implode(',', array_fill(0, count($nums), '?'));
            $liveStmt = $pdo->prepare(
                "SELECT id FROM photos
                  WHERE moderation_status = 'approved' AND published = 1
                    AND (kind IS NULL OR kind = 'image') AND id IN ($marks)"
            );
            $liveStmt->execute(array_keys($nums));
            foreach ($liveStmt->fetchAll(PDO::FETCH_COLUMN) as $liveId) {
                $feedLivePhotos['p' . (int) $liveId] = true;
            }
        }
    }
}

// Ranga, punkty i liczniki aktywności — widoczne tylko na pokazanych
// profilach. Punkty i ranga wynikają z aktywności (zdjęcia, komentarze,
// polubienia) i nie są danymi prywatnymi jak e-mail.
$points = 0;
$likesGiven = 0;
$myRankId = null;
$myRankName = null;
$myRankManual = false;
$myRankHue = 8;
$mySpRank = null;
$mySpRankHue = 8;
$likedPhotos = [];
$likedPhotoTotal = 0;
if ($visible) {
    try {
        $qr = $pdo->prepare('SELECT points, rank_override, special_rank FROM users WHERE id = :id');
        $qr->execute(['id' => $row['id']]);
        $ur = $qr->fetch();
        if ($ur) {
            $points = (int) $ur['points'];
            $rk = zm_user_rank($pdo, $ur);
            if ($rk['id'] !== null) {
                $myRankId = $rk['id'];
                $myRankName = $rk['name'];
                $myRankManual = !empty($rk['manual']);
                foreach (zm_rank_defs($pdo) as $d) {
                    if ((int) $d['id'] === (int) $myRankId) {
                        $myRankHue = ((int) $d['position'] * 47 + 8) % 360;
                        break;
                    }
                }
            }
            $spRk = zm_public_special_rank(zm_user_rank_special($pdo, $ur));
            if ($spRk !== null) {
                $mySpRank = $spRk;
                $mySpRankHue = ((int) $spRk['position'] * 47 + 8) % 360;
            }
        }
        $ql = $pdo->prepare('SELECT COUNT(*) FROM photo_likes WHERE user_id = :id');
        $ql->execute(['id' => $row['id']]);
        $likesGiven = (int) $ql->fetchColumn();

        // Polubione ZDJĘCIA (te, które nadal są w galerii) — sekcja na dole profilu.
        $lph = $pdo->prepare(
            "SELECT COUNT(*) FROM photos p JOIN photo_likes pl ON pl.photo_id = p.id
             WHERE pl.user_id = :id AND p.moderation_status = 'approved' AND p.published = 1
               AND (p.kind IS NULL OR p.kind = 'image')"
        );
        $lph->execute(['id' => $row['id']]);
        $likedPhotoTotal = (int) $lph->fetchColumn();

        $lps = $pdo->prepare(
            "SELECT p.id, p.title, p.filename, p.place, p.gmina_detected, p.geo_json,
                    c.name AS category_name
             FROM photos p
             LEFT JOIN categories c ON c.id = p.category_id
             JOIN photo_likes pl ON pl.photo_id = p.id
             WHERE pl.user_id = :id AND p.moderation_status = 'approved' AND p.published = 1
               AND (p.kind IS NULL OR p.kind = 'image')
             ORDER BY pl.created_at DESC LIMIT 60"
        );
        $lps->execute(['id' => $row['id']]);
        $likedPhotos = $lps->fetchAll();
    } catch (Throwable $e) {
        // liczniki są ozdobą — ich brak nie łamie profilu
    }
}

if (!$visible) {
    http_response_code(404);
}

$displayName = $visible
    ? (($row['display_name'] ?? '') !== '' ? (string) $row['display_name'] : (string) $row['username'])
    : '';
$title = $visible ? $displayName . ' – profil użytkownika | zMogilna.pl' : 'Profil niedostępny — zMogilna.pl';

// awatar: serwowany przez avatar.php, który sam pilnuje prywatności.
// URL budujemy tylko dla widocznych profili, żeby nie odkrywać pliku na stronie prywatnej.
$avatarUrl = $visible ? zm_avatar_url((array) $row, 256) : '';

// Krótki adres tego profilu (/<login>) — ta sama funkcja co w linkach galerii,
// więc profil zawsze ogłasza dokładnie ten adres, który jest rozdawany w linkach.
$profilePath = $visible
    ? zm_profile_path((string) $row['username'], (int) $row['id'])
    : '';

/*
 * Funkcje społecznościowe na profilu: przyciski obserwowania i wiadomości
 * oraz liczniki. Liczymy je tylko dla widocznych profili — na stronie
 * prywatnej nie ma sensu pokazywać, kto kogo obserwuje.
 *
 * Przyciski pojawiają się wyłącznie gdy profil jest widoczny ORAZ widz jest
 * zalogowany i to nie jest ten sam profil. Gość widzi licznik obserwujących
 * (to jawna statystyka), ale nie przyciski akcji.
 */
$socialFollowers = $visible ? zm_followers_count($pdo, (int) $row['id']) : 0;
$socialFollowing = $visible ? zm_following_count($pdo, (int) $row['id']) : 0;
$isFollowing = ($visible && $viewer !== null && (int) $viewer['id'] === (int) $row['id'])
    ? false
    : ($visible && $viewer !== null ? zm_following($pdo, (int) $viewer['id'], (int) $row['id']) : false);
$canFollow = $visible && $viewer !== null && (int) $viewer['id'] !== (int) $row['id'];
// `&&` w PHP jest krótkie — gdy $canFollow jest fałszywe (np. gość), nigdy
// nie zajrzymy do $viewer['id'] i nie dostaniemy błędu na null.
$showSocialActions = $canFollow
    && $isPublic
    && !zm_user_is_banned($pdo, (int) $viewer['id'])
    // zablokowani nie widzą akcji społecznościowych — nie da się ich użyć,
    // więc lepiej ich nie pokazywać (serwer i tak by odmówił)
    && !zm_social_blocked_either($pdo, (int) $viewer['id'], (int) $row['id']);

$linkDefs = [
    'link_facebook'  => ['Facebook', '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>'],
    'link_instagram' => ['Instagram', '<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>'],
    'link_youtube'   => ['YouTube', '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/>'],
    'link_tiktok'    => ['TikTok', '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'],
    'link_website'   => ['Strona www', '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
];

?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$metaDesc = $visible ? trim(($row['bio'] ?? '') !== '' ? (string) $row['bio'] : ($displayName . ' — profil użytkownika na zmogilna.pl' . (($row['location'] ?? '') !== '' ? ', ' . $row['location'] : ''))) : '';
if ($metaDesc === '') { $metaDesc = 'Profil użytkownika na zmogilna.pl'; }
?>
<meta name="description" content="<?= zp_h(mb_substr($metaDesc, 0, 160)) ?>">
<?php if (!empty($isPublic)): ?>
<meta property="og:type" content="profile">
<meta property="og:site_name" content="zmogilna">
<meta property="og:title" content="<?= zp_h($displayName) ?> — zmogilna">
<meta property="og:description" content="<?= zp_h(mb_substr($metaDesc, 0, 160)) ?>">
<meta property="og:url" content="<?= zp_h(zm_base_url() . $profilePath) ?>">
<?php endif; ?>
<meta name="robots" content="<?= !empty($isPublic) ? 'index, follow' : 'noindex, nofollow' ?>">
<title><?= zp_h($title) ?></title>
<?php if ($profilePath !== ''): ?>
<link rel="canonical" href="<?= zp_h(zm_base_url() . $profilePath) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#101c16" id="metaThemeColor">
<link rel="stylesheet" href="zmogilna-ui.css?v=<?= $cssVer ?>">
<link rel="stylesheet" href="konto.css?v=<?= $cssVer ?>">
<style>
/* Funkcje społecznościowe na profilu. Style są tu, a nie w konto.css, bo dotyczą
   wyłącznie profil.php — panel.php ma własny układ kart. */
.social-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:20px }
.social-btn{
  display:inline-flex; align-items:center; gap:8px;
  border:1px solid var(--line-strong); background:var(--card); color:var(--ink);
  border-radius:10px; padding:9px 16px; cursor:pointer;
  font-family:'Inter',sans-serif; font-size:13.5px; text-decoration:none;
  transition:border-color .2s,color .2s,background .2s;
}
.social-btn svg{ width:15px; height:15px; flex-shrink:0 }
.social-btn:hover{ border-color:var(--gold); color:var(--gold) }
.social-btn.is-on{ border-color:var(--gold); color:var(--gold); background:rgba(201,162,39,0.10) }
.social-btn[disabled]{ opacity:.6; cursor:wait }
.social-btn.is-primary{ border-color:var(--gold); color:var(--gold) }
.social-btn.is-blocked{
  cursor:not-allowed; color:var(--ink-dim);
  border-color:var(--line-strong); background:transparent; opacity:.85;
}
/* zielona kropka obecności przy zdjęciu w hero — sama kropka, bez napisu */
.avatar-wrap{ position:relative; flex-shrink:0 }
.avatar-wrap .presence-dot{
  position:absolute; right:1px; bottom:3px; width:16px; height:16px;
  border-radius:50%; background:#2ecc71; border:3px solid var(--bg);
  box-shadow:0 2px 8px rgba(0,0,0,.35);
}
.social-count{
  font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim);
  display:inline-flex; align-items:center; gap:6px;
  border:1px solid var(--line); border-radius:20px; padding:6px 13px;
}
.social-count b{ color:var(--ink); font-weight:600 }
/* liczniki obserwujących/obserwowanych jako przyciski: otwierają listę osób.
   Reset domyślnych stylów przycisku — reszta wyglądu już jest w .social-count */
button.social-count{ background:none; cursor:pointer; transition:border-color .2s,color .2s }
button.social-count:hover{ border-color:var(--gold); color:var(--gold) }
button.social-count:hover b{ color:var(--gold) }

/* Lista obserwowanych i obserwujących — ten sam układ co „Ostatnio dołączyli”
   na stronie głównej: avatar, nazwa i login, z zachowaniem odznak rang. */
#pplList.sub-list{display:grid;gap:8px;margin-top:16px;max-height:min(58vh,560px);overflow-y:auto;padding:2px 4px 2px 0}
#pplList .people-row{
  display:flex;align-items:center;gap:11px;min-width:0;padding:9px;
  border:1px solid transparent;border-radius:10px;text-decoration:none;
  color:inherit;background:transparent;transition:background .2s,border-color .2s,transform .2s;
}
#pplList a.people-row:hover{background:var(--card);border-color:var(--line-strong);transform:translateX(2px)}
#pplList .member-avatar{
  position:relative;width:38px;height:38px;display:grid;place-items:center;
  flex:0 0 38px;overflow:hidden;border-radius:50%;
  background:linear-gradient(135deg,var(--teal),var(--gold));color:#fff;
  font:500 14px 'Fraunces',serif;
}
#pplList .member-avatar img{display:block;width:100%;height:100%;object-fit:cover;border-radius:50%}
#pplList .member-text{display:block;min-width:0;flex:1}
#pplList .member-name{display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-width:0;color:var(--ink);font-size:13px;font-weight:600;line-height:1.4}
#pplList .member-handle{display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink-dim);font:10.5px 'JetBrains Mono',monospace}
#pplList .people-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
#pplList .people-chip{display:inline-flex;align-items:center;max-width:100%;padding:2px 8px;border:1px solid var(--line-strong);border-radius:999px;background:var(--card);color:var(--ink-dim);font:9.5px 'JetBrains Mono',monospace;line-height:1.45}
#pplList .people-chip.admin{border-color:rgba(201,162,39,.5);color:var(--gold)}
#pplList .people-private{margin-left:auto;flex:0 0 auto}
#pplList .people-list-empty{margin:0;padding:18px 10px;color:var(--ink-dim);font-size:13px;line-height:1.5}
#pplList .people-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 11px}
#pplList .people-main{display:flex;align-items:center;gap:11px;min-width:0;flex:1 1 190px;color:inherit;text-decoration:none}
#pplList .people-main:hover .member-name{color:var(--gold)}
.people-search{
  display:block;width:100%;box-sizing:border-box;margin:12px 0 0;padding:9px 12px;
  border:1px solid var(--line-strong);border-radius:9px;background:var(--card);
  color:var(--ink);font:12px 'Inter',sans-serif;outline:none;
}
.people-search:focus{border-color:var(--gold);box-shadow:0 0 0 2px rgba(201,162,39,.12)}
.people-search::placeholder{color:var(--ink-dim)}
#pplList .people-actions{display:flex;flex-wrap:wrap;gap:5px;margin-left:auto}
#pplList .people-action{appearance:none;border:1px solid var(--line);border-radius:7px;padding:5px 8px;background:var(--card);color:var(--ink-dim);font:10px 'JetBrains Mono',monospace;cursor:pointer;white-space:nowrap}
#pplList .people-action:hover{border-color:var(--line-strong);color:var(--ink)}
#pplList .people-message-icon{display:inline-flex;align-items:center;justify-content:center;width:30px;height:28px;padding:0}
#pplList .people-message-icon svg{display:block;width:15px;height:15px}
#pplList .people-action.danger{color:#d98275}
#pplList .people-action:disabled{opacity:.55;cursor:wait}
#pplList .people-action-status{flex-basis:100%;margin:0 0 0 49px;color:var(--ink-dim);font-size:11px}
@media(max-width:480px){
  #pplList .people-row{gap:9px;padding:8px 6px}
  #pplList .member-avatar{width:36px;height:36px;flex-basis:36px}
  #pplList .people-private{display:none}
}
</style>
<script>
  (function(){
    try{
      var saved = localStorage.getItem('zmogilna-theme');
      var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
      var t = saved || (prefersLight ? 'light' : 'dark');
      if(t === 'light'){
        document.documentElement.setAttribute('data-theme', 'light');
        document.getElementById('metaThemeColor').setAttribute('content', '#f5f1e6');
      }
    }catch(_){}
  })();
</script>
</head>
<body class="profile-page">

<div class="wrap">
  <header class="top">
    <div>
      <a class="wordmark" href="index.php">z<span>mogilna</span></a>
      <div class="coords">profil użytkownika</div>
    </div>
    <div class="top-actions">
      <button class="round-btn" id="themeToggleP" type="button" aria-label="Przełącz tryb jasny/ciemny" title="Przełącz tryb jasny/ciemny">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      </button>
      <a class="round-btn" href="index.php" aria-label="Strona główna" title="Strona główna">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></svg>
      </a>
      <a class="round-btn" href="<?= $viewer !== null ? 'panel.php' : 'index.php?login=1' ?>" aria-label="Konto" title="<?= $viewer !== null ? 'Twoje konto' : 'Zaloguj się' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    </div>
  </header>

<?php if (!$visible): ?>
  <section class="hero">
    <div class="eyebrow">Profil</div>
    <h1>Nie ma tu <em>nic do pokazania</em></h1>
    <p class="lede">Ten profil nie istnieje albo jego właściciel nie udostępnia go publicznie.</p>
    <div class="btn-row" style="margin-top:22px;">
      <a class="btn" href="index.php">wróć do galerii</a>
    </div>
  </section>
<?php else: ?>
  <section class="hero">
    <div class="eyebrow">Profil</div>
    <div class="hero-row profile-identity">
      <div class="avatar-wrap profile-avatar-wrap">
        <div class="avatar profile-avatar">
          <span class="avatar-photo"<?= $avatarUrl !== '' ? ' style="background-image:url(' . zp_h($avatarUrl) . ')"' : '' ?>></span>
          <span class="avatar-initials"<?= $avatarUrl !== '' ? ' hidden' : '' ?>><?= zp_h(zp_initials($displayName)) ?></span>
        </div>
        <?php if ($isOnline): ?>
        <span class="presence-dot" aria-hidden="true" title="Jest teraz aktywny"></span>
        <?php endif; ?>
      </div>
      <div class="profile-identity-copy">
        <h1><?= zp_h($displayName) ?></h1>
        <div class="handle profile-handle">
          <span>@<?= zp_h((string) $row['username']) ?></span>
          <?php if (($row['location'] ?? '') !== ''): ?><span class="profile-location"><?= zp_h((string) $row['location']) ?></span><?php endif; ?>
        </div>
        <div class="profile-badges" aria-label="Rola i rangi">
          <?php if ($row['role'] === 'admin'): ?>
          <span class="badge is-admin profile-role">Administrator</span>
          <?php endif; ?>
          <?php if ($myRankId !== null): ?>
          <span class="rk-chip rk-chip-sm profile-rank" style="--rank-hue:<?= $myRankHue ?>;" title="<?= $myRankManual ? 'Ranga ustawiona ręcznie przez administrację' : 'Ranga z punktów za aktywność' ?>"><?= zp_h((string) $myRankName) ?><?= $myRankManual ? ' ✎' : '' ?></span>
          <?php endif; ?>
          <?php if ($mySpRank !== null): ?>
          <span class="rk-chip rk-chip-sm profile-rank profile-rank-special" style="--rank-hue:<?= $mySpRankHue ?>;" title="Ranga specjalna nadana przez administrację"><span aria-hidden="true">★</span> <?= zp_h((string) $mySpRank['name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <section class="profile-stats-panel" aria-label="Statystyki profilu">
      <div class="profile-stats-head"><div><span class="profile-stats-kicker">PODSUMOWANIE PROFILU</span><h2>Statystyki</h2></div><span class="profile-stats-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
      <div class="profile-stats">
        <?php if ($viewer !== null): ?>
          <button type="button" class="profile-stat profile-stat-action" data-profile-people="followers" title="Zobacz, kto obserwuje ten profil">
            <span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM20 8v6m3-3h-6"/></svg></span><span class="profile-stat-code">SOCIAL / 01</span></span>
            <b id="followersCount"><?= $socialFollowers ?></b><span class="profile-stat-label">obserwujących</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><span class="profile-stat-link">Zobacz osoby <span aria-hidden="true">↗</span></span>
          </button>
          <button type="button" class="profile-stat profile-stat-action" data-profile-people="following" title="Zobacz, kogo obserwuje ten profil">
            <span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M19 8v6m3-3h-6"/></svg></span><span class="profile-stat-code">SOCIAL / 02</span></span>
            <b id="followingCount"><?= $socialFollowing ?></b><span class="profile-stat-label">obserwowanych</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><span class="profile-stat-link">Zobacz osoby <span aria-hidden="true">↗</span></span>
          </button>
        <?php else: ?>
          <div class="profile-stat"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM20 8v6m3-3h-6"/></svg></span><span class="profile-stat-code">RELACJE / 01</span></span><b id="followersCount"><?= $socialFollowers ?></b><span class="profile-stat-label">obserwujących</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
          <div class="profile-stat"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M19 8v6m3-3h-6"/></svg></span><span class="profile-stat-code">RELACJE / 02</span></span><b id="followingCount"><?= $socialFollowing ?></b><span class="profile-stat-label">obserwowanych</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
        <?php endif; ?>
        <div class="profile-stat"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span><span class="profile-stat-code">GALERIA / 03</span></span><b id="heroPhotoCount"><?= $photoTotal ?></b><span class="profile-stat-label">zdjęć w galerii</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
        <?php if ($showCommentsToViewer): ?><div class="profile-stat"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg></span><span class="profile-stat-code">SPOŁECZNOŚĆ / 04</span></span><b id="heroCommentCount"><?= $commentTotal ?></b><span class="profile-stat-label">napisanych komentarzy</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div><?php endif; ?>
        <div class="profile-stat profile-stat-points"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3z"/></svg></span><span class="profile-stat-code">WYNIK / 05</span></span><b id="heroPoints"><?= $points ?></b><span class="profile-stat-label">punktów</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><span class="profile-points-orbit" aria-hidden="true"></span></div>
        <div class="profile-stat profile-stat-joined"><span class="profile-stat-top"><span class="profile-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></span><span class="profile-stat-code">DOŁĄCZENIE / 06</span></span><b><?= zp_h($row['created_at'] ? date('d.m.Y', strtotime((string) $row['created_at'])) : '—') ?></b><span class="profile-stat-label">na pokładzie od</span><span class="profile-stat-chart" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
      </div>
    </section>

    <?php if (($row['bio'] ?? '') !== ''): ?>
      <p class="lede profile-bio"><?= zp_h((string) $row['bio']) ?></p>
    <?php endif; ?>

    <?php
    $linksHtml = '';
    foreach ($linkDefs as $col => [$label, $icon]) {
        $url = (string) ($row[$col] ?? '');
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            continue;
        }
        $linksHtml .= '<a class="btn-ghost" href="' . zp_h($url) . '" target="_blank" rel="noopener noreferrer nofollow ugc" title="' . zp_h($label) . '" aria-label="' . zp_h($label) . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg>'
            . '<span class="sl-label">' . zp_h($label) . '</span></a>';
    }
    if ($linksHtml !== ''): ?>
      <div class="social-links"><?= $linksHtml ?></div>
    <?php endif; ?>

    <!-- Funkcje społecznościowe. Liczniki widoczne dla każdego (to jawna
         statystyka profilu), akcje tylko dla zalogowanego widza na cudzym
         publicznym profilu. -->
    <?php if ($visible && $isPublic): ?>
      <div class="social-actions">
        <?php if ($showSocialActions): ?>
          <button type="button" class="social-btn<?= $isFollowing ? ' is-on' : ' is-primary' ?>"
                  id="followBtn" data-user="<?= (int) $row['id'] ?>"
                  aria-pressed="<?= $isFollowing ? 'true' : 'false' ?>">
            <svg id="followIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <?php if ($isFollowing): ?>
                <polyline points="20 6 9 17 4 12"/>
              <?php else: ?>
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
              <?php endif; ?>
            </svg>
            <span id="followLabel"><?= $isFollowing ? 'Obserwujesz' : 'Obserwuj' ?></span>
          </button>
          <?php /* `to=` otwiera wątek z tą osobą od razu — bez tego parametru
                 wiadomosci.php pokazałby pustą listę rozmów i nie dałoby
                 się nikomu napisać jako pierwszemu. */ ?>
          <?php if ($theyBlockedMe): ?>
            <span class="social-btn is-blocked" title="To konto zablokowało Cię — nie możesz do niego pisać ani go odblokować">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
              <span>Zablokowano Cię</span>
            </span>
          <?php else: ?>
            <a class="social-btn" id="messageBtn"
               href="<?= zp_h((zm_base_path() === '' ? '' : zm_base_path()) . '/wiadomosci.php?to=' . (int) $row['id']) ?>"
               title="Napisz wiadomość prywatną">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>
              <span>Napisz wiadomość</span>
            </a>
            <button type="button" class="social-btn<?= $iBlockedThem ? ' is-on' : '' ?>"
                    id="blockBtn" data-user="<?= (int) $row['id'] ?>"
                    aria-pressed="<?= $iBlockedThem ? 'true' : 'false' ?>"
                    title="<?= $iBlockedThem ? 'Blokada jest aktywna — kliknij, aby odblokować' : 'Nie pozwala tej osobie pisać do Ciebie ani Cię obserwować' ?>">
              <svg id="blockIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <?php if ($iBlockedThem): ?>
                  <path d="M1 12s3.5-7 11-7 11 7 11 7-3.5 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>
                <?php else: ?>
                  <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                <?php endif; ?>
              </svg>
              <span id="blockLabel"><?= $iBlockedThem ? 'Odblokuj' : 'Zablokuj' ?></span>
            </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$isPublic): ?>
      <div class="preview-note">
        To jest podgląd — ten profil jest <b>prywatny</b>, widzisz go tylko Ty<?= $isAdminViewer && !$isOwner ? ' (jako administrator)' : '' ?>.
        <?php if ($isOwner): ?>Włącz „Profil publiczny” w <a href="panel.php">panelu konta</a>, żeby udostępnić go innym.<?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <main class="profile-sections">
    <div class="card">
      <div class="pg-view-row">
        <div class="profile-section-title"><span class="profile-stats-kicker">GALERIA / 01</span><h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Zdjęcia w galerii</h2></div>
        <div class="pg-view-group" data-pg-group="ownPhotosGrid" role="group" aria-label="Gęstość siatki">
          <button type="button" class="pg-view-btn" data-density="comfortable" title="Większe kafelki" aria-label="Większe kafelki">▣</button>
          <button type="button" class="pg-view-btn" data-density="default" title="Domyślny widok" aria-label="Domyślny widok">▦</button>
          <button type="button" class="pg-view-btn" data-density="compact" title="Gęstsza siatka" aria-label="Gęstsza siatka">▤</button>
        </div>
      </div>
      <p class="sub">Zdjęcia użytkownika opublikowane w galerii.</p>
      <?php /* Menu folderów jak na stronie głównej; wypełnia je JS (blok „Foldery zdjęć” niżej), tylko gdy są foldery. */ ?>
      <div class="pf-wrap" id="ownFolders" hidden>
        <div class="pf-bar">
          <nav class="breadcrumb" id="ownFoldersCrumbs" aria-label="Folder"></nav>
          <button type="button" class="place-folders-btn" id="ownFoldersToggle" aria-controls="ownFoldersGrid" aria-expanded="true"><span class="pf-label">Pokaż gminy</span></button>
        </div>
        <div class="folder-grid" id="ownFoldersGrid"></div>
      </div>
      <?php /* Pusty stan i siatka są w DOM zawsze — JS podmienia je w miejscu
             po moderacji albo usunięciu zdjęcia. display:none inline, bo .photo-grid
             ma własny display i wygrałby z atrybutem hidden. */ ?>
      <div class="empty" id="ownPhotosEmpty"<?= $photos ? ' style="display:none"' : '' ?>><b>Jeszcze pusto</b>Zatwierdzone zdjęcia tego użytkownika pojawią się tutaj.</div>
       <div class="photo-grid" id="ownPhotosGrid" data-total="<?= (int) $photoTotal ?>"<?= $photos ? '' : ' style="display:none"' ?>>
         <?php foreach ($photos as $p): ?>
           <?php
             $pName = zm_photo_locality($p);
             if ($pName === '') {
                 $pName = trim((string) ($p['title'] ?? '')) ?: trim((string) ($p['place'] ?? ''));
             }
             if ($pName === '') {
                 $pName = 'zdjęcie';
             }
           ?>
           <a href="index.php?photo=p<?= (int) $p['id'] ?>&profile_id=<?= (int) $row['id'] ?>" title="<?= zp_h($pName) ?>">
             <img src="<?= zp_h(zm_thumb_url('p' . (int) $p['id'], 480)) ?>" alt="<?= zp_h($pName) ?>" loading="lazy">
           </a>
         <?php endforeach; ?>
       </div>
       <div class="photo-more-row">
        <button type="button" class="photo-more" id="ownPhotosMore" aria-controls="ownPhotosGrid" aria-expanded="false" hidden><span class="photo-more-icon" aria-hidden="true">＋</span><span>Pokaż więcej</span><span class="photo-more-count" aria-hidden="true"></span><span class="photo-more-arrow" aria-hidden="true">↓</span></button>
        <button type="button" class="photo-more photo-less" id="ownPhotosLess" aria-controls="ownPhotosGrid" hidden><span class="photo-more-icon" aria-hidden="true">−</span><span>Pokaż mniej</span><span class="photo-more-arrow" aria-hidden="true">↑</span></button>
       </div>
       <p class="note" id="ownPhotosNote" data-tpl="Pokazano {n} z {t} zdjęć."<?= ($photos && $photoTotal > count($photos)) ? '' : ' hidden' ?>>Pokazano <?= count($photos) ?> z <?= $photoTotal ?> zdjęć.</p>
    </div>

    <div class="card" id="komentarze">
      <div class="profile-section-head"><div class="profile-section-title"><span class="profile-stats-kicker">SPOŁECZNOŚĆ / 02</span><h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Komentarze</h2></div><span class="profile-stats-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span></div>

      <?php if (!$showCommentsToViewer): ?>
        <p class="sub">Ten użytkownik nie pokazuje swoich komentarzy na profilu.</p>
        <?php if ($isOwner): ?>
          <p class="note">Włącz „Pokazuj komentarze na profilu” w <a href="panel.php">panelu konta</a>.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="tabs" role="tablist">
          <button class="tab is-on" type="button" role="tab" aria-selected="true" data-ctab="written">
            Napisane <span class="pill"><?= $commentTotal ?></span>
          </button>
          <button class="tab" type="button" role="tab" aria-selected="false" data-ctab="liked">
            Polubione <span class="pill"><?= $likedTotal ?></span>
          </button>
        </div>

        <div class="ctab-panel is-on" data-ctab-panel="written">
          <?php if (!$written): ?>
            <div class="empty"><b>Brak komentarzy</b>Ten użytkownik nie napisał jeszcze żadnego komentarza.</div>
          <?php else: ?>
            <ul class="feed" id="writtenFeed" data-profile-feed="written" data-visible="3" data-total="<?= (int) $commentTotal ?>">
              <?php foreach ($written as $c): ?>
                <?php
                $photoId = (string) $c['photo_id'];
                $photoLive = isset($feedLivePhotos[$photoId]);
                // `comment` otwiera zdjęcie RAZEM z panelem komentarzy
                // i przewija do tego wpisu (obsługa w index.php: tryOpenPhotoFromUrl)
                $href = 'index.php?photo=' . rawurlencode($photoId)
                    . '&comment=' . (int) $c['id'];
                ?>
                <li class="feed-item" data-cid="<?= (int) $c['id'] ?>">
                  <?php if ($photoLive): ?>
                    <a class="feed-photo" href="<?= zp_h($href) ?>" title="<?= zp_h((string) ($c['photo_name'] ?: 'zdjęcie')) ?>">
                      <img src="<?= zp_h(zm_thumb_url($photoId, 240)) ?>" alt="" loading="lazy">
                    </a>
                  <?php endif; ?>
                  <div class="feed-body">
                    <p class="feed-text"><?= zp_h((string) $c['text']) ?></p>
                    <div class="feed-meta">
                      <span><?= zp_h(format_comment_ts((int) $c['ts'])) ?></span>
                      <span>·</span>
                      <?= zp_like_btn((int) $c['id'], (int) $c['likes'], isset($viewerLiked[(int) $c['id']])) ?>
                      <?php if ($photoLive && ($c['photo_name'] ?? '') !== ''): ?>
                        <span>·</span>
                        <a href="<?= zp_h($href) ?>"><?= zp_h((string) $c['photo_name']) ?></a>
                      <?php endif; ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="photo-more-row profile-feed-more-row">
              <button type="button" class="photo-more profile-feed-more" data-feed-more="written" aria-controls="writtenFeed" aria-expanded="false" hidden><span class="photo-more-icon" aria-hidden="true">＋</span><span>Pokaż więcej</span><span class="photo-more-count" aria-hidden="true"></span><span class="photo-more-arrow" aria-hidden="true">↓</span></button>
              <button type="button" class="photo-more photo-less profile-feed-less" data-feed-less="written" aria-controls="writtenFeed" hidden><span class="photo-more-icon" aria-hidden="true">−</span><span>Pokaż mniej</span><span class="photo-more-arrow" aria-hidden="true">↑</span></button>
            </div>
            <p class="note profile-feed-note" data-feed-note="written" data-tpl="Pokazano {n} z {t} komentarzy." role="status" aria-live="polite" hidden></p>
          <?php endif; ?>
        </div>

        <div class="ctab-panel" data-ctab-panel="liked" hidden>
          <?php if (!$liked): ?>
            <div class="empty"><b>Brak polubień</b>Ten użytkownik nie polubił jeszcze żadnego komentarza.</div>
          <?php else: ?>
            <ul class="feed" id="likedFeed" data-profile-feed="liked" data-visible="3" data-total="<?= (int) $likedTotal ?>">
              <?php foreach ($liked as $c): ?>
                <?php
                $likedPhotoId = (string) $c['photo_id'];
                $likedPhotoLive = isset($feedLivePhotos[$likedPhotoId]);
                $likedHref = 'index.php?photo=' . rawurlencode($likedPhotoId)
                    . '&comment=' . (int) $c['id'];
                ?>
                <li class="feed-item" data-cid="<?= (int) $c['id'] ?>">
                  <?php if ($likedPhotoLive): ?>
                    <a class="feed-photo" href="<?= zp_h($likedHref) ?>" title="<?= zp_h((string) ($c['photo_name'] ?: 'zdjęcie')) ?>">
                      <img src="<?= zp_h(zm_thumb_url($likedPhotoId, 240)) ?>" alt="" loading="lazy">
                    </a>
                  <?php endif; ?>
                  <div class="feed-body">
                    <div class="feed-author"><?= zp_h((string) ($c['author'] ?? '')) ?></div>
                    <p class="feed-text"><?= zp_h((string) $c['text']) ?></p>
                    <div class="feed-meta">
                      <?= zp_like_btn((int) $c['id'], (int) $c['likes'], isset($viewerLiked[(int) $c['id']])) ?>
                      <?php if ($likedPhotoLive && ($c['photo_name'] ?? '') !== ''): ?>
                        <span>·</span>
                        <a href="<?= zp_h($likedHref) ?>"><?= zp_h((string) $c['photo_name']) ?></a>
                      <?php endif; ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="photo-more-row profile-feed-more-row">
              <button type="button" class="photo-more profile-feed-more" data-feed-more="liked" aria-controls="likedFeed" aria-expanded="false" hidden><span class="photo-more-icon" aria-hidden="true">＋</span><span>Pokaż więcej</span><span class="photo-more-count" aria-hidden="true"></span><span class="photo-more-arrow" aria-hidden="true">↓</span></button>
              <button type="button" class="photo-more photo-less profile-feed-less" data-feed-less="liked" aria-controls="likedFeed" hidden><span class="photo-more-icon" aria-hidden="true">−</span><span>Pokaż mniej</span><span class="photo-more-arrow" aria-hidden="true">↑</span></button>
            </div>
            <p class="note profile-feed-note" data-feed-note="liked" data-tpl="Pokazano {n} z {t} polubionych komentarzy." role="status" aria-live="polite" hidden></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" id="polubione-zdjecia">
      <div class="pg-view-row">
        <div class="profile-section-title"><span class="profile-stats-kicker">GALERIA / 03</span><h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>Polubione zdjęcia</h2></div>
        <div class="pg-view-group" data-pg-group="likedPhotosGrid" role="group" aria-label="Gęstość siatki">
          <button type="button" class="pg-view-btn" data-density="comfortable" title="Większe kafelki" aria-label="Większe kafelki">▣</button>
          <button type="button" class="pg-view-btn" data-density="default" title="Domyślny widok" aria-label="Domyślny widok">▦</button>
          <button type="button" class="pg-view-btn" data-density="compact" title="Gęstsza siatka" aria-label="Gęstsza siatka">▤</button>
        </div>
      </div>
      <p class="sub">Zdjęcia, które ten użytkownik polubił w galerii.</p>
      <div class="empty" id="likedPhotosEmpty"<?= $likedPhotos ? ' style="display:none"' : '' ?>><b>Jeszcze pusto</b>Polubione zdjęcia tego użytkownika pojawią się tutaj.</div>
       <div class="photo-grid" id="likedPhotosGrid" data-total="<?= (int) $likedPhotoTotal ?>"<?= $likedPhotos ? '' : ' style="display:none"' ?>>
         <?php foreach ($likedPhotos as $p): ?>
           <?php
             $pName = zm_photo_locality($p);
             if ($pName === '') {
                 $pName = trim((string) ($p['title'] ?? '')) ?: trim((string) ($p['place'] ?? ''));
             }
             if ($pName === '') {
                 $pName = 'zdjęcie';
             }
           ?>
           <a href="index.php?photo=p<?= (int) $p['id'] ?>" title="<?= zp_h($pName) ?>">
             <img src="<?= zp_h(zm_thumb_url('p' . (int) $p['id'], 480)) ?>" alt="<?= zp_h($pName) ?>" loading="lazy">
           </a>
         <?php endforeach; ?>
       </div>
       <div class="photo-more-row">
        <button type="button" class="photo-more" id="likedPhotosMore" aria-controls="likedPhotosGrid" aria-expanded="false" hidden><span class="photo-more-icon" aria-hidden="true">＋</span><span>Pokaż więcej</span><span class="photo-more-count" aria-hidden="true"></span><span class="photo-more-arrow" aria-hidden="true">↓</span></button>
        <button type="button" class="photo-more photo-less" id="likedPhotosLess" aria-controls="likedPhotosGrid" hidden><span class="photo-more-icon" aria-hidden="true">−</span><span>Pokaż mniej</span><span class="photo-more-arrow" aria-hidden="true">↑</span></button>
       </div>
       <p class="note" id="likedPhotosNote" data-tpl="Pokazano {n} z {t} polubionych zdjęć."<?= ($likedPhotos && $likedPhotoTotal > count($likedPhotos)) ? '' : ' hidden' ?>>Pokazano <?= count($likedPhotos) ?> z <?= $likedPhotoTotal ?> polubionych zdjęć.</p>
    </div>
  </main>
<?php endif; ?>

<?php if ($visible): ?>
<script>
/* Funkcje społecznościowe profilu. Blok jest warunkowy ($visible), bo przyciski
   i liczniki istnieją tylko na profilu, który w ogóle widać. */
(function(){
  const btn = document.getElementById('followBtn');
  if(!btn) return;                       // gość albo własny profil — brak akcji

  const SAPI   = <?= json_encode((zm_base_path() === '' ? '' : zm_base_path()) . '/social-api.php') ?>;
  const CSRF   = <?= json_encode(zm_csrf_token()) ?>;
  const LABEL  = document.getElementById('followLabel');
  const ICON   = document.getElementById('followIcon');
  const TOAST  = (function(){
    // toast tworzymy dopiero przy pierwszym błędzie — na profilu inaczej
    // nigdy go nie ma i nie zostawiałoby pustego elementu w DOM
    let t = null;
    return function(msg){
      if(!t){
        t = document.createElement('div');
        t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(16px);'
          + 'background:var(--card);border:1px solid var(--gold);color:var(--ink);'
          + 'border-radius:10px;padding:10px 18px;font-size:13.5px;opacity:0;'
          + 'pointer-events:none;transition:opacity .2s,transform .2s;z-index:60;max-width:90vw;text-align:center';
        document.body.appendChild(t);
      }
      t.textContent = msg;
      t.style.opacity = '1';
      t.style.transform = 'translateX(-50%) translateY(0)';
      clearTimeout(t._timer);
      t._timer = setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(16px)'; }, 3000);
    };
  })();

  // ikony w dwóch stanach: „dodaj obserwującego" i „już obserwujesz"
  const ICON_ADD  = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>';
  const ICON_ON   = '<polyline points="20 6 9 17 4 12"/>';

  function paint(following, followers, followingCount){
    btn.classList.toggle('is-on', following);
    btn.classList.toggle('is-primary', !following);
    btn.setAttribute('aria-pressed', following ? 'true' : 'false');
    LABEL.textContent = following ? 'Obserwujesz' : 'Obserwuj';
    ICON.innerHTML = following ? ICON_ON : ICON_ADD;
    const fc = document.getElementById('followersCount');
    const gc = document.getElementById('followingCount');
    if(fc && typeof followers === 'number') fc.textContent = followers;
    if(gc && typeof followingCount === 'number') gc.textContent = followingCount;
  }

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try{
      const res = await fetch(SAPI, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'follow_toggle', user:+btn.dataset.user, csrf: CSRF })
      });
      const d = await res.json().catch(() => null);
      if(res.status === 401){ TOAST('Musisz się zalogować.'); return; }
      if(!res.ok || !d || d.error){
        TOAST(d && d.message ? d.message : 'Nie udało się zmienić obserwacji.');
        return;
      }
      paint(!!d.following, d.followers, d.followingCount);
    }catch(_){
      TOAST('Brak połączenia z serwerem.');
    }finally{
      btn.disabled = false;
    }
  });

  // Blokada / odblokowanie. Przycisk istnieje tylko wtedy, gdy widz może to
  // zrobić (zalogowany nie-właściciel, a ten profil nas nie zablokował).
  const bBtn = document.getElementById('blockBtn');
  if(bBtn){
    const bLabel = document.getElementById('blockLabel');
    const bIcon = document.getElementById('blockIcon');
    const ICON_BLOCK = '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>';
    const ICON_UNLOCK = '<path d="M1 12s3.5-7 11-7 11 7 11 7-3.5 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
    bBtn.addEventListener('click', async () => {
      bBtn.disabled = true;
      try{
        const res = await fetch(SAPI, {
          method:'POST', credentials:'include',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'block_toggle', user:+bBtn.dataset.user, csrf: CSRF })
        });
        const d = await res.json().catch(() => null);
        if(res.status === 401){ TOAST('Musisz się zalogować.'); return; }
        if(!res.ok || !d || d.error){
          TOAST(d && d.message ? d.message : 'Nie udało się zmienić blokady.');
          return;
        }
        const blocked = !!d.blocked;
        bBtn.classList.toggle('is-on', blocked);
        bBtn.setAttribute('aria-pressed', blocked ? 'true' : 'false');
        bLabel.textContent = blocked ? 'Odblokuj' : 'Zablokuj';
        bIcon.innerHTML = blocked ? ICON_UNLOCK : ICON_BLOCK;
        bBtn.title = blocked
          ? 'Blokada jest aktywna — kliknij, aby odblokować'
          : 'Nie pozwala tej osobie pisać do Ciebie ani Cię obserwować';
      }catch(_){
        TOAST('Brak połączenia z serwerem.');
      }finally{
        bBtn.disabled = false;
      }
    });
  }
})();
</script>
<?php endif; ?>

<?php if ($visible): ?>
<script>
(function(){
  const photos = <?= zm_js_json(array_map(static function (array $p): array {
      $name = zm_photo_locality($p);
      if ($name === '') {
          $name = trim((string) ($p['title'] ?? '')) ?: trim((string) ($p['place'] ?? ''));
      }
      if ($name === '') {
          $name = 'zdjęcie';
      }
      return [
          'id' => 'p' . (int) $p['id'],
          'title' => $name,
          'place' => (string) ($p['place'] ?? ''),
          'lat' => $p['lat'] !== null ? (float) $p['lat'] : null,
          'lng' => $p['lng'] !== null ? (float) $p['lng'] : null,
          'takenAt' => (string) ($p['taken_at'] ?? ''),
      ];
  }, $photos)) ?>;
  const profileId = <?= (int) $row['id'] ?>;
  const profileCsrf = <?= json_encode(zm_csrf_token()) ?>;
  const box = document.getElementById('profileLightbox');
  const img = document.getElementById('profileLightboxImg');
  const cap = document.getElementById('profileLightboxCap');
  if(!box || !img || !photos.length) return;
  let index = 0;

  function photoUrl(id){ return 'profil.php?id=' + profileId + '&photo=' + encodeURIComponent(id); }
  function showAt(next, push){
    index = (next + photos.length) % photos.length;
    const photo = photos[index];
    img.src = 'photo.php?id=' + encodeURIComponent(photo.id) + '&s=1600';
    img.alt = photo.title;
    cap.textContent = photo.title + ' (' + (index + 1) + '/' + photos.length + ')';
    document.getElementById('profileLightboxInfo').classList.remove('open');
    document.getElementById('profileMap').style.display = photo.lat !== null && photo.lng !== null ? 'block' : 'none';
    document.getElementById('profileLightboxPrev').disabled = photos.length < 2;
    document.getElementById('profileLightboxNext').disabled = photos.length < 2;
    if(push) history.pushState({type:'profile-lightbox', photoId:photo.id}, '', photoUrl(photo.id));
  }
  function openAt(next, push){ box.classList.add('open'); document.documentElement.style.overflow='hidden'; showAt(next, push); }
  function close(){
    box.classList.remove('open');
    document.documentElement.style.overflow='';
    const url = new URL('profil.php', location.href);
    url.searchParams.set('id', profileId);
    history.replaceState({type:'profile'}, '', url.toString());
  }
  document.getElementById('ownPhotosGrid')?.addEventListener('click', e => {
    const link = e.target.closest('a');
    if(!link) return;
    const id = new URL(link.href, location.href).searchParams.get('photo');
    const found = photos.findIndex(photo => photo.id === id);
    if(found < 0) return;
    e.preventDefault();
    openAt(found, true);
  });
  document.getElementById('profileLightboxPrev').addEventListener('click', () => showAt(index - 1, true));
  document.getElementById('profileLightboxNext').addEventListener('click', () => showAt(index + 1, true));
  document.getElementById('profileLightboxClose').addEventListener('click', close);
  document.getElementById('profileInfo').addEventListener('click', () => {
    const photo = photos[index];
    const info = document.getElementById('profileLightboxInfo');
    info.textContent = [photo.place, photo.takenAt].filter(Boolean).join(' · ') || 'Brak dodatkowych danych';
    info.classList.toggle('open');
  });
  document.getElementById('profileMap').addEventListener('click', () => {
    const photo = photos[index];
    if(photo.lat === null || photo.lng === null) return;
    window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(photo.lat + ',' + photo.lng), '_blank', 'noopener');
  });
  document.getElementById('profileShare').addEventListener('click', async () => {
    const url = location.origin + photoUrl(photos[index].id);
    try{ await navigator.clipboard.writeText(url); }catch(_){ window.prompt('Skopiuj link do zdjęcia:', url); }
  });
  document.getElementById('profileComments').addEventListener('click', () => {
    location.href = 'index.php?photo=' + encodeURIComponent(photos[index].id) + '&profile_id=' + profileId;
  });
  async function toggleProfileAction(buttonId, endpoint, payload){
    try{
      const res = await fetch(endpoint, {method:'POST', credentials:'include', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
      if(res.ok) document.getElementById(buttonId).classList.toggle('active');
    }catch(_){ /* strona główna nadal obsługuje te akcje po otwarciu komentarzy */ }
  }
  document.getElementById('profileFav').addEventListener('click', () => toggleProfileAction('profileFav', 'favorites-api.php', {action:'toggle', photo:photos[index].id, csrf:profileCsrf}));
  document.getElementById('profileLike').addEventListener('click', () => toggleProfileAction('profileLike', 'photo-likes-api.php', {action:'toggle', photo:photos[index].id, csrf:profileCsrf}));
   // zamknięcie tylko przyciskiem Zamknij / Escape
  document.addEventListener('keydown', e => {
    if(!box.classList.contains('open')) return;
    if(e.key === 'ArrowLeft') showAt(index - 1, true);
    if(e.key === 'ArrowRight') showAt(index + 1, true);
    if(e.key === 'Escape') close();
  });
  const initial = new URL(location.href).searchParams.get('photo');
  const initialIndex = photos.findIndex(photo => photo.id === initial);
  if(initialIndex >= 0) openAt(initialIndex, false);
})();
</script>
<?php endif; ?>

  <?php require __DIR__ . '/footer-contact.php'; ?>
</div>

<script>
(function(){
  // zakładki „Napisane / Polubione” w sekcji komentarzy
  var tabs = document.querySelectorAll('[data-ctab]');
  var panels = document.querySelectorAll('[data-ctab-panel]');
  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      var want = tab.getAttribute('data-ctab');
      tabs.forEach(function(t){
        var on = t === tab;
        t.classList.toggle('is-on', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function(p){
        var on = p.getAttribute('data-ctab-panel') === want;
        p.classList.toggle('is-on', on);
        p.hidden = !on;
      });
    });
  });

  var btn = document.getElementById('themeToggleP');
  var meta = document.getElementById('metaThemeColor');
  btn.addEventListener('click', function(){
    var isLight = document.documentElement.getAttribute('data-theme') === 'light';
    var next = isLight ? 'dark' : 'light';
    try{ localStorage.setItem('zmogilna-theme', next); }catch(_){}
    if(next === 'light'){
      document.documentElement.setAttribute('data-theme', 'light');
      meta.setAttribute('content', '#f5f1e6');
    } else {
      document.documentElement.removeAttribute('data-theme');
      meta.setAttribute('content', '#101c16');
    }
  });
})();
</script>
<script>
(function(){
  var INITIAL = 3;
  var BATCH = 3;
  var root = document.getElementById('komentarze');
  if(!root) return;

  function applyFeed(kind, animateFrom){
    var list = document.getElementById(kind + 'Feed');
    if(!list) return;
    var items = Array.prototype.slice.call(list.querySelectorAll('.feed-item'));
    var visible = parseInt(list.getAttribute('data-visible') || INITIAL, 10);
    if(!isFinite(visible) || visible < INITIAL) visible = INITIAL;
    visible = Math.min(items.length, visible);
    list.setAttribute('data-visible', String(visible));

    items.forEach(function(item, index){
      item.classList.remove('feed-reveal');
      item.style.removeProperty('--pg-reveal-delay');
      item.classList.toggle('feed-extra', index >= visible);
      if(animateFrom !== undefined && index >= animateFrom && index < visible){
        item.style.setProperty('--pg-reveal-delay', Math.min(index - animateFrom, 7) * 36 + 'ms');
        item.classList.add('feed-reveal');
        item.addEventListener('animationend', function(){
          item.classList.remove('feed-reveal');
          item.style.removeProperty('--pg-reveal-delay');
        }, { once:true });
      }
    });

    var more = root.querySelector('[data-feed-more="' + kind + '"]');
    var less = root.querySelector('[data-feed-less="' + kind + '"]');
    var nextCount = Math.min(BATCH, Math.max(0, items.length - visible));
    var hasMore = nextCount > 0;
    if(more){
      more.hidden = !hasMore;
      more.setAttribute('aria-expanded', visible > INITIAL ? 'true' : 'false');
      more.setAttribute('aria-label', 'Pokaż więcej, kolejna porcja: ' + nextCount);
      var count = more.querySelector('.photo-more-count');
      if(count) count.textContent = hasMore ? '+' + nextCount : '';
    }
    if(less) less.hidden = visible <= INITIAL;

    var note = root.querySelector('[data-feed-note="' + kind + '"]');
    if(note){
      var total = parseInt(list.getAttribute('data-total') || '', 10);
      if(!isFinite(total) || total < items.length) total = items.length;
      note.hidden = visible >= total;
      if(!note.hidden){
        var tpl = note.getAttribute('data-tpl') || '';
        note.textContent = tpl.replace('{n}', visible).replace('{t}', total);
      }
    }
  }

  function applyAll(){
    applyFeed('written');
    applyFeed('liked');
  }

  root.addEventListener('click', function(e){
    var more = e.target.closest ? e.target.closest('[data-feed-more]') : null;
    var less = e.target.closest ? e.target.closest('[data-feed-less]') : null;
    var button = more || less;
    if(!button) return;
    var kind = button.getAttribute(more ? 'data-feed-more' : 'data-feed-less');
    var list = document.getElementById(kind + 'Feed');
    if(!list) return;
    var visible = parseInt(list.getAttribute('data-visible') || INITIAL, 10);
    if(!isFinite(visible) || visible < INITIAL) visible = INITIAL;
    if(more){
      list.setAttribute('data-visible', String(Math.min(list.querySelectorAll('.feed-item').length, visible + BATCH)));
      applyFeed(kind, visible);
    }else{
      list.setAttribute('data-visible', String(INITIAL));
      applyFeed(kind);
      var tabs = root.querySelector('.tabs');
      if(tabs && tabs.scrollIntoView) tabs.scrollIntoView({ behavior:'smooth', block:'start' });
    }
  });

  window.zmApplyProfileCommentFeeds = applyAll;
  applyAll();
})();
</script>
<script>
(function(){
  // Podgląd profilu działa całkowicie bez przeładowania strony: polubienia
  // komentarzy latają do comments.php, a feed („Napisane/Polubione” + liczniki)
  // odświeża się sam — po evidencie, po powrocie na kartę i cyklicznie.
  var PROFILE_ID = <?= isset($row['id']) ? (int) $row['id'] : 0 ?>;
  var feedVisible = <?= !empty($showCommentsToViewer) ? 'true' : 'false' ?>;
  // Siatki i liczniki w nagłówku odświeżamy niezależnie od tego, czy profil
  // pokazuje komentarze — przy wyłączonych komentarzach widać tylko zdjęcia,
  // a one też mogły się w międzyczasie zmienić.
  if(!PROFILE_ID || <?= $visible ? 'false' : 'true' ?>){
    // profil niewidoczny — serwer i tak wyrenderował widok zastępczy
    return;
  }
  var hasPanels = !!document.querySelector('[data-ctab-panel="written"]');

  var FEED_URL = 'profile-api.php?action=feed&id=' + PROFILE_ID;
  var COMMENTS_URL = 'comments.php';
  var HEART_OUT = '<svg class="zp-like-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
  var HEART_IN = '<svg class="zp-like-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
  var csrfToken = '';
  var busy = false;

  function el(id){ return document.getElementById(id); }
  function plural(n, one, few, many){
    if(n === 1) return one;
    var mod10 = n % 10, mod100 = n % 100;
    return (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) ? few : many;
  }
  // anonimowy, losowy identyfikator przeglądarki — ten sam co w galerii
  function voterKey(){
    try{
      var v = localStorage.getItem('zmogilna-voter');
      if(!v || !/^[A-Za-z0-9_-]{12,64}$/.test(v)){
        var bytes = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        v = Array.from(bytes, function(b){ return b.toString(16).padStart(2, '0'); }).join('');
        localStorage.setItem('zmogilna-voter', v);
      }
      return v;
    }catch(_){
      return 'anon' + Math.random().toString(36).slice(2, 14).padEnd(12, '0');
    }
  }

  function photoHref(c){
    return 'index.php?photo=' + encodeURIComponent(c.photoId) + '&comment=' + c.id;
  }
  function likeBtn(c){
    var on = !!c.liked;
    return '<button type="button" class="zp-like' + (on ? ' is-on' : '') + '" data-cid="' + c.id + '" aria-pressed="' + (on ? 'true' : 'false') + '" title="' + (on ? 'Cofnij polubienie' : 'Polub komentarz') + '">'
      + (on ? HEART_IN : HEART_OUT)
      + '<span class="zp-like-n">' + c.likes + '</span> <span class="zp-like-w">' + plural(c.likes, 'polubienie', 'polubienia', 'polubień') + '</span></button>';
  }
  function photoLink(c){
    var name = c.photoName || 'zdjęcie';
    return '<a href="' + photoHref(c) + '">' + name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>';
  }
  // Zdjęcie skasowane albo wycofane: photo.php odpowiada 404, więc nie
  // rysujemy miniatury ani linku — zostaje sam komentarz.
  function photoTile(c){
    if(c.photoLive === false) return '';
    return '<a class="feed-photo" href="' + photoHref(c) + '" title="' + esc(c.photoName || 'zdjęcie') + '">'
      + '<img src="' + esc(c.thumb) + '" alt="" loading="lazy"></a>';
  }
  function photoMetaLink(c){
    return (c.photoLive !== false && c.photoName) ? '<span>·</span>' + photoLink(c) : '';
  }
  function esc(s){
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function writtenItem(c){
    return '<li class="feed-item" data-cid="' + c.id + '">'
      + photoTile(c)
      + '<div class="feed-body">'
      + '<p class="feed-text">' + esc(c.text) + '</p>'
      + '<div class="feed-meta"><span>' + esc(c.tsText || '—') + '</span><span>·</span>'
      + likeBtn(c)
      + photoMetaLink(c)
      + '</div></div></li>';
  }
  function likedItem(c){
    return '<li class="feed-item" data-cid="' + c.id + '">'
      + photoTile(c)
      + '<div class="feed-body">'
      + '<div class="feed-author">' + esc(c.author || '') + '</div>'
      + '<p class="feed-text">' + esc(c.text) + '</p>'
      + '<div class="feed-meta">' + likeBtn(c)
      + photoMetaLink(c)
      + '</div></div></li>';
  }

  /*
   * Siatki zdjęć i liczniki w nagłówku. Strona renderuje je po stronie
   * serwera, więc przy długo otwartym profilu pokazywały stan sprzed
   * moderacji, polubienia albo usunięcia zdjęcia. Feed i tak się odświeża
   * cyklicznie i po powrocie na kartę - więc dokłądnie do niego te
   * dane, a JS podmienia je w miejscu - bez przeładowania strony i bez zmiany wyglądu.
   */
  var __profilPhotosServerRendered = !!(document.getElementById('ownPhotosGrid') && document.getElementById('ownPhotosGrid').children.length) || !!(document.getElementById('likedPhotosGrid') && document.getElementById('likedPhotosGrid').children.length);
  var __profilPhotosRefreshed = false;

  function photoTilesHtml(list){
    return list.map(function(p){
        return '<a href="index.php?photo=' + encodeURIComponent(p.id) + '&profile_id=' + PROFILE_ID + '" title="' + esc(p.title || p.place || 'zdjęcie') + '">'
          + '<img src="' + esc(p.thumb) + '" alt="' + esc(p.title || 'zdjęcie') + '" loading="lazy"></a>';
      }).join('');
  }
  /* Pusty stan i siatka leżą w DOM obok siebie, więc każda zmiana (moderacja,
     usunięcie zdjęcia, cofnięcie polubienia) to tylko przełączenie display —
     bez dokładania i usuwania węzłów, które potem gubiły cache elementu.
     display wprost, bo .photo-grid ma własny display i wygrałby z atrybutem hidden. */
  function paintGrid(gridId, emptyId, noteId, list, total, emptyText){
    if(gridId === 'ownPhotosGrid' && window.zmProfileFolders && window.zmProfileFolders.ready) return;  // siatką steruje menu folderów
    var grid = el(gridId), empty = el(emptyId), note = el(noteId);
    if(!grid || !empty) return;
    var n = list && typeof list.length === 'number' ? list.length : 0;
    if(typeof total === 'number') grid.setAttribute('data-total', total);
    if(n){
      grid.innerHTML = photoTilesHtml(list);
      grid.style.display = '';
      empty.style.display = 'none';
    }else{
      grid.innerHTML = '';
      grid.style.display = 'none';
      empty.style.display = '';
      if(emptyText && !empty.textContent.trim()) empty.textContent = emptyText;
    }
    if(note){
      if(n && total > n){
        note.hidden = false;
        note.textContent = note.dataset.tpl
          ? note.dataset.tpl.replace('{n}', n).replace('{t}', total)
          : note.textContent;
      }else{
        note.hidden = true;
      }
    }
  }
  function renderPhotoSection(data){
    if(!__profilPhotosRefreshed && __profilPhotosServerRendered){
      __profilPhotosRefreshed = true;
      if(typeof data.photoCount === 'number'){
        var c1 = el('heroPhotoCount');
        if(c1) c1.textContent = data.photoCount;
      }
      if(typeof data.likesGiven === 'number'){
        var c2 = el('heroLikesGiven');
        if(c2) c2.textContent = data.likesGiven;
      }
      if(typeof data.likedPhotoTotal === 'number'){
        // tylko aktualizacja licznika, bez przebudowy siatki
      }
      var cPts = el('heroPoints');
      if(cPts && typeof data.points === 'number') cPts.textContent = data.points;
      return;
    }
    __profilPhotosRefreshed = true;
    if(data.photos && typeof data.photos.length === 'number'){
      paintGrid('ownPhotosGrid', 'ownPhotosEmpty', 'ownPhotosNote', data.photos, data.photoCount);
      var c1b = el('heroPhotoCount');
      if(c1b) c1b.textContent = data.photoCount;
    }
    if(data.likedPhotos && typeof data.likedPhotos.length === 'number'){
      paintGrid('likedPhotosGrid', 'likedPhotosEmpty', 'likedPhotosNote', data.likedPhotos, data.likedPhotoTotal);
      var c2b = el('heroLikesGiven');
      if(c2b && typeof data.likesGiven === 'number') c2b.textContent = data.likesGiven;
    }
    var cPts2 = el('heroPoints');
    if(cPts2 && typeof data.points === 'number') cPts2.textContent = data.points;
  }

  function renderFeed(data){
    renderPhotoSection(data);
    if(!hasPanels) return;   // komentarze ukryte — odświeżamy tylko liczniki i siatki
    var writtenPanel = document.querySelector('[data-ctab-panel="written"]');
    var likedPanel = document.querySelector('[data-ctab-panel="liked"]');
    if(!writtenPanel || !likedPanel) return;

    // liczniki na zakładkach
    var pills = document.querySelectorAll('[data-ctab] .pill');
    if(pills.length === 2){
      pills[0].textContent = data.written.total;
      pills[1].textContent = data.liked.total;
    }
    var cmt = el('heroCommentCount');
    if(cmt) cmt.textContent = data.written.total;

    function moreControls(label){
      var listId = label + 'Feed';
      var noteTpl = label === 'written'
        ? 'Pokazano {n} z {t} komentarzy.'
        : 'Pokazano {n} z {t} polubionych komentarzy.';
      return '<div class="photo-more-row profile-feed-more-row">'
        + '<button type="button" class="photo-more profile-feed-more" data-feed-more="' + label + '" aria-controls="' + listId + '" aria-expanded="false" hidden>'
        + '<span class="photo-more-icon" aria-hidden="true">＋</span><span>Pokaż więcej</span><span class="photo-more-count" aria-hidden="true"></span><span class="photo-more-arrow" aria-hidden="true">↓</span></button>'
        + '<button type="button" class="photo-more photo-less profile-feed-less" data-feed-less="' + label + '" aria-controls="' + listId + '" hidden>'
        + '<span class="photo-more-icon" aria-hidden="true">−</span><span>Pokaż mniej</span><span class="photo-more-arrow" aria-hidden="true">↑</span></button>'
        + '</div><p class="note profile-feed-note" data-feed-note="' + label + '" data-tpl="' + noteTpl + '" role="status" aria-live="polite" hidden></p>';
    }
    function fill(panel, items, total, label){
      var html;
      var currentList = document.getElementById(label + 'Feed');
      var currentVisible = currentList ? parseInt(currentList.getAttribute('data-visible') || '3', 10) : 3;
      if(!isFinite(currentVisible) || currentVisible < 3) currentVisible = 3;
      if(!items.length){
        var e = label === 'written'
          ? '<b>Brak komentarzy</b>Ten użytkownik nie napisał jeszcze żadnego komentarza.'
          : '<b>Brak polubień</b>Ten użytkownik nie polubił jeszcze żadnego komentarza.';
        html = '<div class="empty">' + e + '</div>';
      } else {
        var lis = items.map(label === 'written' ? writtenItem : likedItem).join('');
        html = '<ul class="feed" id="' + label + 'Feed" data-profile-feed="' + label + '" data-visible="' + currentVisible + '" data-total="' + total + '">' + lis + '</ul>';
        html += moreControls(label);
      }
      panel.innerHTML = html;
    }
    fill(writtenPanel, data.written.items, data.written.total, 'written');
    fill(likedPanel, data.liked.items, data.liked.total, 'liked');
    if(window.zmApplyProfileCommentFeeds) window.zmApplyProfileCommentFeeds();
  }

  function refreshFeed(){
    if(busy) return;
    busy = true;
    fetch(FEED_URL + '&voter=' + encodeURIComponent(voterKey()), { headers:{ Accept:'application/json' }, credentials:'include' })
      .then(function(res){
        if(!res.ok) throw new Error('status ' + res.status);
        return res.json();
      })
      .then(function(data){
        if(data && data.ok) renderFeed(data);
      })
      .catch(function(){})
      .then(function(){ busy = false; });
  }

  // polubienie / cofnięcie — ten sam endpoint co w galerii (CSRF z konta, voter z przeglądarki)
  function likeOne(cid, btn){
    if(btn.disabled) return;
    btn.disabled = true;
    fetch(COMMENTS_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      credentials:'include',
      body: JSON.stringify({ action:'like', id: cid, voter: voterKey(), csrf: csrfToken })
    })
      .then(function(res){
        if(!res.ok) throw new Error('status ' + res.status);
        return res.json();
      })
      .then(function(data){
        if(data && data.error) throw new Error(data.error);
        if(data && typeof data.liked === 'boolean'){
          var on = data.liked;
          btn.classList.toggle('is-on', on);
          btn.setAttribute('aria-pressed', on ? 'true' : 'false');
          btn.setAttribute('title', on ? 'Cofnij polubienie' : 'Polub komentarz');
          btn.innerHTML = (on ? HEART_IN : HEART_OUT)
            + '<span class="zp-like-n">' + data.likes + '</span> <span class="zp-like-w">' + plural(data.likes, 'polubienie', 'polubienia', 'polubień') + '</span>';
          // zsynchronizuj liczniki/statusy ze stanem serwera (np. wpis znika z „Polubione”)
          refreshFeed();
        }
      })
      .catch(function(){})
      .then(function(){ btn.disabled = false; });
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('.zp-like') : null;
    if(btn) likeOne(+btn.getAttribute('data-cid'), btn);
  });

  fetch('auth-api.php?action=whoami', { credentials:'include' })
    .then(function(res){ return res.json(); })
    .then(function(d){ csrfToken = (d && d.csrf) || ''; })
    .catch(function(){});

  refreshFeed();

  // odśwież też po wróceniu na kartę / zauważeniu okna — komentarz dodany
  // w galerii w drugiej karcie pojawi się tu bez ręcznego przeładowania
  setInterval(refreshFeed, 20000);
  document.addEventListener('visibilitychange', function(){
    if(document.visibilityState === 'visible') refreshFeed();
  });
  window.addEventListener('focus', refreshFeed);
  window.addEventListener('pageshow', refreshFeed);
})();
</script>

<?php if ($visible && ($isPublic || $isOwner) && $viewer !== null): ?>
<!-- Lista osób: kto obserwuje ten profil i kogo on obserwuje. Otwiera ją klik
     w liczniki przy przyciskach obserwowania; dane z social-api.php?action=people -->
<div class="rk-backdrop" id="pplBackdrop" hidden>
  <div class="rk-sheet" role="dialog" aria-modal="true" aria-labelledby="pplTitle">
    <button type="button" class="rk-close" id="pplClose" aria-label="Zamknij">×</button>
    <h2 id="pplTitle">Obserwujący</h2>
    <p class="note" id="pplSub"></p>
    <?php if ($isOwner): ?>
      <input type="search" class="people-search" id="pplSearch" placeholder="Szukaj użytkownika…" aria-label="Szukaj użytkownika">
    <?php endif; ?>
    <div class="sub-list" id="pplList" style="margin-top:16px"><p class="note">Wczytywanie…</p></div>
  </div>
</div>
<script>
(function(){
  const backdrop = document.getElementById('pplBackdrop');
  if(!backdrop) return;
  const listBox  = document.getElementById('pplList');
  const titleEl  = document.getElementById('pplTitle');
  const subEl    = document.getElementById('pplSub');
  const searchEl = document.getElementById('pplSearch');
  const closeBtn = document.getElementById('pplClose');
  const myId     = <?= (int) $row['id'] ?>;
  const CAN_MANAGE = <?= !empty($isOwner) ? 'true' : 'false' ?>;
  const CSRF     = <?= json_encode(zm_csrf_token()) ?>;
  const BASE     = <?= json_encode(zm_base_path() === '' ? '' : zm_base_path()) ?>;
  const SAPI     = BASE + '/social-api.php';
  let currentMode = 'followers';

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const initialsOf = name => {
    const s = String(name == null ? '' : name).trim();
    return s ? s.slice(0,1).toUpperCase() : '?';
  };
  const messageUrl = id => BASE + '/wiadomosci.php?to=' + encodeURIComponent(id);
  const open = () => { backdrop.hidden = false; requestAnimationFrame(() => backdrop.classList.add('open')); closeBtn && closeBtn.focus(); };
  const close = () => { backdrop.classList.remove('open'); setTimeout(() => { backdrop.hidden = true; }, 220); };

  function personHtml(u){
    const id = Number(u.id) || 0;
    const avatar = u.avatar
      ? `<img src="${esc(u.avatar)}" alt="" loading="lazy">`
      : esc(initialsOf(u.name));
    const badges = [];
    if(u.isAdmin) badges.push('<span class="people-chip admin">Administrator</span>');
    if(u.specialRankName) badges.push(`<span class="people-chip" style="border-color:hsl(${Number(u.specialRankHue)||8} 55% 45%);color:hsl(${Number(u.specialRankHue)||8} 65% 72%)">${esc(u.specialRankName)}</span>`);
    if(u.rankName) badges.push(`<span class="people-chip">${esc(u.rankName)}</span>`);
    if(u.isPrivate) badges.push('<span class="people-chip">profil prywatny</span>');
    const badgesHtml = badges.length ? `<span class="people-badges">${badges.join('')}</span>` : '';
    const inner = `<span class="member-avatar">${avatar}</span>`
      + '<span class="member-text">'
      + `<span class="member-name">${esc(u.name)}</span>`
      + `<span class="member-handle">@${esc(u.username)}</span>`
      + badgesHtml
      + '</span>';
    const profile = u.url
      ? `<a class="people-main" href="${esc(u.url)}">${inner}</a>`
      : `<span class="people-main" data-private="1">${inner}</span>`;
    let actions = '';
    if(CAN_MANAGE && id > 0 && id !== myId){
      const message = u.url ? `<a class="people-action people-message-icon" href="${esc(messageUrl(id))}" title="Wyślij wiadomość" aria-label="Wyślij wiadomość"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>` : '';
      const unfollow = currentMode === 'following'
        ? `<button type="button" class="people-action danger" data-person-action="unfollow" data-user="${id}">Przestań obserwować</button>`
        : '';
      const block = `<button type="button" class="people-action danger" data-person-action="block" data-user="${id}">Zablokuj</button>`;
      actions = `<span class="people-actions">${message}${unfollow}${block}</span>`;
    }
    return `<div class="member-card people-row" data-person-id="${id}">${profile}${actions}</div>`;
  }

  async function postAction(action, userId){
    const res = await fetch(SAPI, {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, user:userId, csrf:CSRF})
    });
    const data = await res.json().catch(() => null);
    if(res.status === 401) throw new Error('Musisz się zalogować ponownie.');
    if(!res.ok || !data || data.error) throw new Error(data && data.message ? data.message : 'Nie udało się wykonać tej czynności.');
    return data;
  }

  let currentItems = [];
  function renderPeople(){
    const q = searchEl ? searchEl.value.trim().toLocaleLowerCase('pl') : '';
    const filtered = currentItems.filter(u => {
      if(!q) return true;
      return [u.name, u.username, u.rankName, u.specialRankName, u.isAdmin ? 'administrator' : '']
        .filter(Boolean).join(' ').toLocaleLowerCase('pl').includes(q);
    });
    if(!filtered.length){
      listBox.innerHTML = currentItems.length
        ? '<p class="people-list-empty">Nie znaleziono użytkownika pasującego do wyszukiwania.</p>'
        : '<p class="people-list-empty">' + (currentMode === 'following' ? 'Nie obserwujesz już żadnych użytkowników.' : 'Ten profil nie ma jeszcze obserwujących.') + '</p>';
      return;
    }
    listBox.innerHTML = filtered.map(personHtml).join('');
  }

  async function load(mode){
    currentMode = mode;
    if(searchEl) searchEl.value = '';
    currentItems = [];
    listBox.innerHTML = '<p class="note">Wczytywanie…</p>';
    try{
      const res = await fetch(SAPI + '?action=people&user=' + myId + '&mode=' + mode + '&limit=200', {credentials:'include'});
      const data = await res.json();
      if(!data || !data.ok){ listBox.innerHTML = '<p class="note">Nie udało się wczytać listy.</p>'; return; }
      currentItems = Array.isArray(data.items) ? data.items : [];
      renderPeople();
    }catch(_){
      listBox.innerHTML = '<p class="note">Błąd połączenia z serwerem.</p>';
    }
  }

  if(searchEl) searchEl.addEventListener('input', renderPeople);

  listBox.addEventListener('click', async e => {
    const button = e.target.closest('[data-person-action]');
    if(!button) return;
    const userId = Number(button.dataset.user);
    const action = button.dataset.personAction;
    if(!userId || button.disabled) return;
    if(action === 'unfollow' && !window.confirm('Przestać obserwować tego użytkownika?')) return;
    if(action === 'block' && !window.confirm('Zablokować tego użytkownika? Nie będzie mógł Cię obserwować ani wysyłać Ci wiadomości.')) return;
    button.disabled = true;
    try{
      if(action === 'unfollow'){
        const data = await postAction('follow_toggle', userId);
        if(data.following) throw new Error('Użytkownik nadal znajduje się na liście obserwowanych.');
        const row = button.closest('[data-person-id]');
        if(row) row.remove();
        const count = document.getElementById('followingCount');
        if(count && typeof data.followingCount === 'number') count.textContent = data.followingCount;
        if(!listBox.querySelector('[data-person-id]')) listBox.innerHTML = '<p class="people-list-empty">Nie obserwujesz już żadnych użytkowników.</p>';
      }else if(action === 'block'){
        await postAction('block_toggle', userId);
        button.textContent = 'Zablokowano';
        button.disabled = true;
        button.title = 'Użytkownik został zablokowany';
      }
    }catch(err){
      window.alert(err && err.message ? err.message : 'Nie udało się wykonać tej czynności.');
      button.disabled = false;
    }
  });

  document.querySelectorAll('[data-profile-people]').forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.profilePeople === 'following' ? 'following' : 'followers';
      titleEl.textContent = mode === 'following' ? 'Obserwowani' : 'Obserwujący';
      subEl.textContent = mode === 'following'
        ? 'Użytkownicy, których obserwuje ten profil — wraz z administratorami i rangami specjalnymi.'
        : 'Użytkownicy, którzy obserwują ten profil — wraz z administratorami i rangami specjalnymi.';
      open();
      load(mode);
    });
  });

  backdrop.addEventListener('click', e => { if(e.target === backdrop) close(); });
  closeBtn && closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', e => { if(e.key === 'Escape' && !backdrop.hidden) close(); });
})();
</script>
<?php endif; ?>
<?php if ($visible): ?>
<script>
/* Przełącznik gęstości siatki zdjęć na profilu — kopia zachowania galerii
   (index.php). Ten SAMY klucz localStorage (zmogilna-grid-density) i te same
   trzy poziomy (default | compact | comfortable), więc wybór z galerii przenosi
   się na profil i odwrotnie. Domyślny poziom = 'default', jak w galerii. */
(function(){
  var KEY = 'zmogilna-grid-density';
  var VALID = ['default','compact','comfortable'];
  var density = 'default';
  try{
    var saved = localStorage.getItem(KEY);
    if(VALID.indexOf(saved) !== -1) density = saved;
  }catch(_){}

  var grids = Array.prototype.slice.call(document.querySelectorAll('.photo-grid'));
  var btns  = Array.prototype.slice.call(document.querySelectorAll('.pg-view-btn[data-density]'));
  var PAIRS = {
    ownPhotosGrid:   { more:'ownPhotosMore',   less:'ownPhotosLess',   note:'ownPhotosNote' },
    likedPhotosGrid: { more:'likedPhotosMore', less:'likedPhotosLess', note:'likedPhotosNote' }
  };
  // Ile „ekranów” siatki pokazano. 1 = domyślny limit, każdy klik „Pokaż
  // więcej” dokłada dokładnie jedną porcję tej samej wielkości co siatka.
  var pages = {};

  function gridById(id){ return document.getElementById(id); }

  /* Ile kolumn ma teraz siatka? Tyle, ile wpisów w grid-template-columns.
     Dzięki temu limit kafelków sam dostosowuje się do gęstości i szerokości
     okna — po zmianie ustawienia siatki nie trzeba nic robić ręcznie. */
  function columnsOf(grid){
    var tpl = '';
    try{ tpl = window.getComputedStyle(grid).gridTemplateColumns || ''; }catch(_){}
    if(!tpl || tpl === 'none') return 1;
    return tpl.trim().split(/\s+/).filter(Boolean).length;
  }
  /* Domyślnie pokazujemy 2 wiersze (compact: 3, bo kafelki są mniejsze),
     resztę odsłania dopiero „Pokaż więcej”. */
  function rowsFor(){ return density === 'compact' ? 3 : 2; }
  function limitFor(grid){ return columnsOf(grid) * rowsFor(); }

  function anchorsOf(grid){
    var out = [];
    for(var i = 0; i < grid.children.length; i++){
      if(grid.children[i].tagName === 'A') out.push(grid.children[i]);
    }
    return out;
  }

  function updateNote(grid, id, count, visible){
    var note = document.getElementById(PAIRS[id].note);
    if(!note) return;
    if(!count){ note.hidden = true; return; }
    var total = parseInt(grid.getAttribute('data-total') || '', 10);
    if(isNaN(total) || total < count) total = count;
    if(visible < total){
      note.hidden = false;
      var tpl = note.getAttribute('data-tpl') || '';
      note.textContent = tpl
        ? tpl.replace('{n}', visible).replace('{t}', total)
        : 'Pokazano ' + visible + ' z ' + total + ' zdjęć.';
    }else{
      note.hidden = true;
    }
  }

  function applyGrid(id, animateFrom){
    var grid = gridById(id);
    if(!grid) return;
    var more = document.getElementById(PAIRS[id].more);
    var less = document.getElementById(PAIRS[id].less);
    var anchors = anchorsOf(grid);
    var base = limitFor(grid);
    var visible = Math.min(anchors.length, base * (pages[id] || 1));
    var expanded = visible > base;
    anchors.forEach(function(a, i){
      a.classList.remove('pg-reveal');
      a.style.removeProperty('--pg-reveal-delay');
      a.classList.toggle('pg-extra', i >= visible);
      if(animateFrom !== undefined && i >= animateFrom && i < visible){
        a.style.setProperty('--pg-reveal-delay', Math.min(i - animateFrom, 7) * 36 + 'ms');
        a.classList.add('pg-reveal');
        a.addEventListener('animationend', function(){
          a.classList.remove('pg-reveal');
          a.style.removeProperty('--pg-reveal-delay');
        }, { once:true });
      }
    });
    if(more){
      var hasMore = visible < anchors.length;
      more.hidden = !hasMore;
      more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      var count = more.querySelector('.photo-more-count');
      var nextCount = Math.min(base, Math.max(0, anchors.length - visible));
      if(count) count.textContent = hasMore ? '+' + nextCount : '';
      more.setAttribute('aria-label', hasMore ? 'Pokaż więcej zdjęć, ' + nextCount : 'Pokaż więcej zdjęć');
    }
    if(less){
      // „Pokaż mniej” ma sens tylko, gdy część zdjęć jest już ukryta.
      less.hidden = !expanded;
    }
    updateNote(grid, id, anchors.length, visible);
  }

  function applyAll(){ Object.keys(PAIRS).forEach(applyGrid); }

  function apply(d){
    if(VALID.indexOf(d) === -1) d = 'default';
    density = d;
    grids.forEach(function(g){
      g.classList.toggle('compact', d === 'compact');
      g.classList.toggle('comfortable', d === 'comfortable');
    });
    btns.forEach(function(b){ b.classList.toggle('active', b.dataset.density === d); });
    applyAll();
  }

  function scrollToCard(id){
    var grid = gridById(id);
    var card = grid && grid.closest ? grid.closest('.card') : null;
    if(card && card.scrollIntoView) card.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  Object.keys(PAIRS).forEach(function(id){
    var more = document.getElementById(PAIRS[id].more);
    if(more){
      more.addEventListener('click', function(){
        var grid = gridById(id);
        var oldVisible = grid ? anchorsOf(grid).filter(function(a){ return !a.classList.contains('pg-extra'); }).length : 0;
        pages[id] = (pages[id] || 1) + 1;
        applyGrid(id, oldVisible);
      });
    }
    var less = document.getElementById(PAIRS[id].less);
    if(less){
      less.addEventListener('click', function(){
        pages[id] = 1;
        applyGrid(id);
        // Po zwinięciu dużej siatki wracamy do nagłówka karty, żeby nie
        // zostawać na dole strony, gdy treść nagle się skróci.
        scrollToCard(id);
      });
    }
    // Nadmiar kafelków ukrywa klasa, więc po każdym przerenderowaniu siatki
    // (odświeżenie feedu, moderacja) limit trzeba nanieść od nowa.
    var grid = gridById(id);
    if(grid && window.MutationObserver){
      new MutationObserver(function(){ pages[id] = 1; applyGrid(id); })
        .observe(grid, { childList:true });
    }
  });

  var resizeTimer = null;
  window.addEventListener('resize', function(){
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(applyAll, 150);
  });

  // Klik w dowolny przycisk gęstości (obie karty) ustawia poziom i zapisuje —
  // tak jak w galerii, wszystkie siatki na stronie dostają ten sam poziom.
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('.pg-view-btn[data-density]');
    if(!b) return;
    apply(b.dataset.density);
    try{ localStorage.setItem(KEY, density); }catch(_){}
  });

  // Zmiana poziomu w innej karcie (np. w galerii) od razu aktualizuje profil,
  // bo klucz localStorage jest wspólny.
  window.addEventListener('storage', function(e){
    if(e.key === KEY) apply(e.newValue);
  });

  apply(density);
})();
</script>
<script>
/* Foldery zdjęć na profilu — to samo menu co na stronie głównej („gminy i podkatalogi”),
   ale tylko z folderami i zdjęciami tego użytkownika. Wybór folderu pokazuje zdjęcia z tego
   folderu i jego podkatalogów; „Pokaż/Ukryj gminy i podkatalogi” zwija kafelki folderów. */
(function(){
  var FD = <?= zm_js_json($folderData) ?>;
  var PID = <?= (int) $row['id'] ?>;
  var wrap = document.getElementById('ownFolders');
  var grid = document.getElementById('ownPhotosGrid');
  var empty = document.getElementById('ownPhotosEmpty');
  var crumbs = document.getElementById('ownFoldersCrumbs');
  var cards = document.getElementById('ownFoldersGrid');
  var toggle = document.getElementById('ownFoldersToggle');
  if(!wrap || !grid || !crumbs || !cards || !toggle || !FD || !FD.photos || !FD.photos.length || !FD.cats || !FD.cats.length) return;

  var KEY = 'zmogilna-profile-folders-hidden';
  var cat = {};
  FD.cats.forEach(function(c){ cat[c[0]] = { id:c[0], name:c[1], parent:c[2], kids:[], own:0, sum:null }; });
  var loose = 0;
  FD.photos.forEach(function(p){ if(cat[p[1]]) cat[p[1]].own++; else loose++; });
  if(loose) cat[-1] = { id:-1, name:'Bez folderu', parent:0, kids:[], own:loose, sum:null };

  function hasParent(c){ return c.parent && c.parent !== c.id && cat[c.parent]; }
  Object.keys(cat).forEach(function(k){ var c = cat[k]; if(hasParent(c)) cat[c.parent].kids.push(c); });
  function total(c, seen){
    if(c.sum !== null) return c.sum;
    if(seen[c.id]) return 0;
    seen[c.id] = 1;
    var t = c.own;
    c.kids.forEach(function(k){ t += total(k, seen); });
    c.sum = t;
    return t;
  }
  Object.keys(cat).forEach(function(k){ total(cat[k], {}); });
  // Najpierw foldery zawierające najwięcej zdjęć; przy remisie kolejność alfabetyczna.
  // Folder „Bez folderu” pozostaje na końcu listy głównej.
  function byPhotoCount(a, b){
    if(a.id === -1) return 1;
    if(b.id === -1) return -1;
    var diff = b.sum - a.sum;
    return diff || a.name.localeCompare(b.name, 'pl');
  }
  function kidsOf(c){ return c.kids.filter(function(k){ return k.sum > 0; }).sort(byPhotoCount); }
  var roots = Object.keys(cat).map(function(k){ return cat[k]; })
    .filter(function(c){ return c.sum > 0 && !hasParent(c); }).sort(byPhotoCount);
  if(!roots.length) return;

  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; }); }
  function subtree(c, out, seen){
    if(seen[c.id]) return out;
    seen[c.id] = 1; out[c.id] = true;
    c.kids.forEach(function(k){ subtree(k, out, seen); });
    return out;
  }
  var FOLDER_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';

  // domyślnie foldery są ukryte; po kliknięciu „Pokaż…” zapamiętujemy wybór w przeglądarce
  var hidden = true;
  try{ if(localStorage.getItem(KEY) === '0') hidden = false; }catch(_){}
  var current = 0;

  function paintToggle(){
    var noun = current ? 'miejscowości' : 'gminy';   // na liście gmin: „gminy”, w folderze gminy: „miejscowości”
    toggle.querySelector('.pf-label').textContent = (hidden ? 'Pokaż ' : 'Ukryj ') + noun;
    toggle.hidden = !cards.children.length;           // brak podfolderów = nie ma czego pokazywać
    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    toggle.classList.toggle('is-hidden-state', hidden);
    cards.hidden = hidden || !cards.children.length;
  }

  function show(id){
    current = id;
    var node = id ? cat[id] : null;
    // okruszki: Wszystkie zdjęcia / gmina / podkatalog
    var path = [], c = node, guard = 0;
    while(c && guard++ < 12){ path.unshift(c); c = hasParent(c) ? cat[c.parent] : null; }
    var html = '';
    if(!node){
      html = '<span class="crumb-current">Wszystkie zdjęcia</span><span class="crumb-count">' + FD.photos.length + '</span>';
    }else{
      html = '<button type="button" data-f="0">Wszystkie zdjęcia</button>';
      path.forEach(function(p, i){
        html += '<span class="crumb-sep">/</span>';
        html += (i === path.length - 1)
          ? '<span class="crumb-current">' + esc(p.name) + '</span><span class="crumb-count">' + node.sum + '</span>'
          : '<button type="button" data-f="' + p.id + '">' + esc(p.name) + '</button>';
      });
    }
    crumbs.innerHTML = html;

    // kafelki folderów: na starcie gminy, w folderze jego podkatalogi
    var list = node ? kidsOf(node) : roots;
    cards.innerHTML = list.map(function(k){
      return '<button type="button" class="folder-card" data-f="' + k.id + '">' + FOLDER_SVG
        + '<span class="folder-name">' + esc(k.name) + '</span><span class="folder-count">' + k.sum + '</span></button>';
    }).join('');

    // zdjęcia: wszystkie albo z wybranego folderu i podkatalogów
    var photos = FD.photos;
    if(node){
      var set = subtree(node, {}, {});
      photos = photos.filter(function(p){ return set[cat[p[1]] ? p[1] : -1]; });
    }
    grid.setAttribute('data-total', photos.length);   // przed zmianą DOM — liczy się w „Pokazano n z t”
    grid.innerHTML = photos.map(function(p){
      return '<a href="index.php?photo=p' + p[0] + '&profile_id=' + PID + '" title="' + esc(p[2]) + '">'
        + '<img src="' + esc(p[3]) + '" alt="' + esc(p[2]) + '" loading="lazy"></a>';
    }).join('');
    grid.style.display = photos.length ? '' : 'none';
    if(empty) empty.style.display = photos.length ? 'none' : '';
    paintToggle();
  }

  wrap.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('[data-f]') : null;
    if(!b || !wrap.contains(b)) return;
    show(parseInt(b.getAttribute('data-f'), 10) || 0);
    var bar = wrap.querySelector('.pf-bar');
    if(bar && bar.scrollIntoView && current) bar.scrollIntoView({ behavior:'smooth', block:'nearest' });
  });
  toggle.addEventListener('click', function(){
    hidden = !hidden;
    try{ localStorage.setItem(KEY, hidden ? '1' : '0'); }catch(_){}
    paintToggle();
  });

  window.zmProfileFolders = { ready:true };
  wrap.hidden = false;
  show(0);
})();
</script>

<?php endif; ?>
<!-- pływający przycisk powrotu na górę (jak na stronie głównej) -->
<style>
  .scroll-top-btn{
    position:fixed; right:18px; bottom:18px; z-index:50;
    width:46px; height:46px; border-radius:50%; padding:0;
    background:var(--card,#1b2a21); border:1px solid var(--line-strong,rgba(255,255,255,.25));
    color:var(--ink,#e8efe9); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.25);
    opacity:0; visibility:hidden; transform:translateY(10px);
    transition:opacity .25s ease, transform .25s ease, visibility .25s, border-color .2s, color .2s;
  }
  .scroll-top-btn.visible{ opacity:1; visibility:visible; transform:translateY(0); }
  .scroll-top-btn:hover{ border-color:var(--gold,#c9a227); color:var(--gold,#c9a227); }
  .scroll-top-btn svg{ display:block; width:20px; height:20px; flex:0 0 auto; }
  .scroll-back-btn{
    position:fixed; right:72px; bottom:18px; z-index:50;
    width:46px; height:46px; border-radius:50%; padding:0;
    background:var(--card,#1b2a21); border:1px solid var(--line-strong,rgba(255,255,255,.25));
    color:var(--ink,#e8efe9); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.25);
    opacity:0; visibility:hidden; transform:translateY(10px);
    transition:opacity .25s ease, transform .25s ease, visibility .25s, border-color .2s, color .2s;
  }
  .scroll-back-btn.visible{ opacity:1; visibility:visible; transform:translateY(0); }
  .scroll-back-btn:hover{ border-color:var(--gold,#c9a227); color:var(--gold,#c9a227); }
  .scroll-back-btn svg{ display:block; width:20px; height:20px; flex:0 0 auto; }
  @media (max-width:640px){
    .scroll-top-btn{ right:14px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
    .scroll-back-btn{ right:66px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
  }
</style>
<button type="button" class="scroll-back-btn" id="scrollBackBtn" aria-label="Wróć do poprzedniej strony" title="Wstecz">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
</button>
<button type="button" class="scroll-top-btn" id="scrollTopBtn" aria-label="Przewiń do góry" title="Do góry">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
</button>
<script>
(function(){
  var btn = document.getElementById('scrollTopBtn');
  var backBtn = document.getElementById('scrollBackBtn');
  if(!btn) return;
  function toggle(){
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    var visible = y > 400;
    btn.classList.toggle('visible', visible);
    if(backBtn) backBtn.classList.toggle('visible', visible);
  }
  if(backBtn){
    backBtn.addEventListener('click', function(){
      window.history.back();
    });
  }
  btn.addEventListener('click', function(){
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top:0, behavior: reduce ? 'auto' : 'smooth' });
  });
  window.addEventListener('scroll', toggle, { passive:true });
  toggle();
})();
</script>

</body>
</html>

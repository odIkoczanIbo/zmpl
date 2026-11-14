<?php

declare(strict_types=1);

// panel pokazuje punkty, rangę i liczniki z bazy — dokument zawsze z sieci
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
// dzwonek powiadomień w nagłówku panelu — liczniki czytamy raz na stronę,
// a potem odświeża je lekkie zapytanie social-api.php?action=count
require_once __DIR__ . '/social-lib.php';

$me = zm_current_user();
if ($me === null) {
    header('Location: index.php?login=1');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT username, email, display_name, role, created_at, bio, location,
            link_facebook, link_instagram, link_youtube, link_tiktok, link_website, profile_public,
            avatar_path, avatar_updated_at, show_comments
     FROM users WHERE id = :id'
);
$stmt->execute(['id' => $me['id']]);
$profile = $stmt->fetch();
if (!$profile) {
    zm_logout();
    header('Location: index.php?login=1');
    exit;
}

$displayName = $profile['display_name'] !== null && $profile['display_name'] !== ''
    ? $profile['display_name']
    : $profile['username'];
function zm_initials(string $name): string
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

$initials = zm_initials($displayName);
$isAdmin = $profile['role'] === 'admin';
$memberSince = $profile['created_at'] ? date('d.m.Y', strtotime((string) $profile['created_at'])) : '-';
$isPublic = (bool) ($profile['profile_public'] ?? 0);

// E-mail właściciela. Jest prywatny, więc pokazujemy go wyłącznie tutaj,
// w panelu właściciela — nigdy na publicznym profilu i nigdy w galerii.
// Panel jest prywatnym widokiem (ktoś musi się zalogować jako właściciel),
// więc tutaj adres nie wycieka do osób postronnych.
$ownerEmail = trim((string) ($profile['email'] ?? ''));

// awatar: URL tylko wtedy, gdy plik naprawdę jest na dysku
$avatarUrl = zm_avatar_url($profile + ['id' => $me['id']], 256);

/*
 * Liczniki na dzwoneczku. Czytane raz podczas renderowania strony, żeby plakietka
 * była poprawna już w pierwszym HTML-u — bez niej strona mrugałaby pustym
 * kółkiem, dopóki skrypt nie wróciłby z danymi.
 */
$initialUnread = zm_notify_unread($pdo, (int) $me['id']);
$initialMessages = zm_message_unread($pdo, (int) $me['id']);

// statystyki społecznościowe pokazywane w karcie na dole panelu
$followersCount = zm_followers_count($pdo, (int) $me['id']);
$followingCount = zm_following_count($pdo, (int) $me['id']);

// liczniki zgłoszeń użytkownika (zatwierdzone / oczekujące)
$approved = 0;
$pending = 0;
try {
    $c = $pdo->prepare(
        'SELECT moderation_status AS s, COUNT(*) AS n FROM photos
         WHERE submitted_by_user_id = :id GROUP BY moderation_status'
    );
    $c->execute(['id' => $me['id']]);
    foreach ($c->fetchAll() as $row) {
        if ($row['s'] === 'approved') {
            $approved = (int) $row['n'];
        } elseif ($row['s'] === 'pending') {
            $pending = (int) $row['n'];
        }
    }
} catch (Throwable $e) {
    // liczniki są tylko ozdobą — brak nie blokuje panelu
}

// Rang, punkty i liczniki aktywności właściciela konta.
// Tylko tutaj (prywatny widok) pokazujemy punkty i progi do następnej rangi.
$myRank = null;
$nextRank = null;
$mySpRank = null;
$points = 0;
$rankManual = false;
$cntComments = 0;
$cntLikes = 0;
$cntPhotosApproved = 0;
try {
    $rq = $pdo->prepare('SELECT points, rank_override, special_rank, is_banned FROM users WHERE id = :id');
    $rq->execute(['id' => $me['id']]);
    $uRow = $rq->fetch();
    if ($uRow) {
        $points = (int) $uRow['points'];
        $res = zm_user_rank($pdo, $uRow);
        if ($res['id'] !== null) {
            $myRank = $res;
            $rankManual = !empty($res['manual']);
            $rankCfg = zm_rank_defs($pdo);
            $myPos = (int) $myRank['position'];
            foreach ($rankCfg as $i => $cfg) {
                if ((int) $cfg['position'] === $myPos) {
                    // następny próg = najbliższa ranga ZWYKŁA za nami (specjalne nie tworzą pasm)
                    for ($j = $i + 1; $j < count($rankCfg); $j++) {
                        if (empty($rankCfg[$j]['special'])) {
                            $nextRank = $rankCfg[$j];
                            break;
                        }
                    }
                    break;
                }
            }
        }
        $mySpRank = zm_public_special_rank(zm_user_rank_special($pdo, $uRow));
    }
} catch (Throwable $e) {
    // brak danych nie blokuje panelu
}
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE user_id = :id');
    $st->execute(['id' => $me['id']]);
    $cntComments = (int) $st->fetchColumn();
} catch (Throwable $e) {
}
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM photo_likes WHERE user_id = :id');
    $st->execute(['id' => $me['id']]);
    $cntLikes = (int) $st->fetchColumn();
} catch (Throwable $e) {
}
try {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM photos WHERE submitted_by_user_id = :id
         AND moderation_status = :ap AND published = 1
         AND (kind IS NULL OR kind = :img)'
    );
    $st->execute(['id' => $me['id'], 'ap' => 'approved', 'img' => 'image']);
    $cntPhotosApproved = (int) $st->fetchColumn();
} catch (Throwable $e) {
}

// tabela rang + liczba użytkowników w każdym paśmie (do podstrony).
// Wiersze buduje wspólna funkcja z config.php — panel i auth-api.php?action=rank
// muszą pokazać identyczną tabelę także po odświeżeniu w przeglądarce.
$rankTableHtml = zm_rank_table_html(
    $pdo,
    isset($myRank['id']) ? (int) $myRank['id'] : null,
    isset($mySpRank['id']) ? (int) $mySpRank['id'] : null,
    $rankManual
);

// kolor rangi do chipu
$myRankHue = 8;
if ($myRank !== null) {
    $myRankHue = zm_rank_hue((int) $myRank['position']);
}
$mySpRankHue = 8;
if ($mySpRank !== null) {
    $mySpRankHue = zm_rank_hue((int) $mySpRank['position']);
}

$initial = [
    'username'     => (string) $profile['username'],
    'display_name' => (string) $displayName,
    'bio'          => (string) ($profile['bio'] ?? ''),
    'location'     => (string) ($profile['location'] ?? ''),
    'facebook'     => (string) ($profile['link_facebook'] ?? ''),
    'instagram'    => (string) ($profile['link_instagram'] ?? ''),
    'youtube'      => (string) ($profile['link_youtube'] ?? ''),
    'tiktok'       => (string) ($profile['link_tiktok'] ?? ''),
    'website'      => (string) ($profile['link_website'] ?? ''),
    'public'       => (int) ($profile['profile_public'] ?? 0) === 1,
    'show_comments'=> (int) ($profile['show_comments'] ?? 1) === 1,
    'avatar'       => $avatarUrl,
    'email'        => $ownerEmail,
    // krótki adres profilu policzony po stronie serwera (zm_profile_path),
    // żeby galeria i panel pokazywały dokładnie ten sam adres
    'profilePath'  => zm_base_path() . zm_profile_path((string) $profile['username'], (int) $me['id']),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$cssVer = (string) (@filemtime(__DIR__ . '/konto.css') ?: 1);

// Dane wstrzykiwane do panelu, żeby lista dozwolonych formatów zgadzała się
// z tym, co faktycznie przepuszcza backend w photos-lib.php.
require_once __DIR__ . '/photos-lib.php';
$zmIniBytes = static function (string $name, int $fallback): int {
    $v = trim((string) ini_get($name));
    $n = (int) $v;
    if ($v === '0' || $n <= 0) {
        return $fallback;
    }
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
};
$zmUploadMax = $zmIniBytes('upload_max_filesize', 2 * 1024 * 1024);
$uploadLimits = [
    // Jedno zgłoszenie = jedno zdjęcie, bez limitu rozmiaru narzuconego
    // przez aplikację. Progi dla uploadu ustawia php.ini.
    'maxPending' => ZM_MAX_PENDING_PER_USER,
    'iniUploadMax' => $zmUploadMax,
    'accepted' => zm_accepted_list(),
    'imageExt' => array_merge(array_keys(ZM_EXT), ZM_CONVERT_EXT),
];

?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Twoje konto — zmogilna</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#101c16" id="metaThemeColor">
<link rel="stylesheet" href="zmogilna-ui.css?v=<?= $cssVer ?>">
<link rel="stylesheet" href="konto.css?v=<?= $cssVer ?>">
<script>
  // ten sam wybór motywu co na stronie głównej (zapisany albo systemowy)
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
<style>
  /* ---------- menu konta (ikona konta w nagłówku) ---------- */
  /* Pasek ma tylko 3 przyciski, więc powiadomienia, wiadomości, profil,
     dodanie zdjęcia i wylogowanie siedzą w rozwijanej liście pod ikoną. */
  .top-actions{ position:relative; }
  .acct-menu{
    position:absolute; top:calc(100% + 8px); right:0; z-index:70;
    min-width:236px; padding:6px;
    background:var(--card); border:1px solid var(--line-strong); border-radius:14px;
    box-shadow:0 16px 44px rgba(0,0,0,.35);
    display:flex; flex-direction:column; gap:2px;
  }
  .acct-menu[hidden]{ display:none; }
  .acct-menu-item{
    display:flex; align-items:center; gap:10px;
    padding:10px 12px; border-radius:10px;
    border:1px solid transparent; background:none;
    color:var(--ink); font-family:'Inter',sans-serif; font-size:13.5px; font-weight:600;
    text-align:left; text-decoration:none; cursor:pointer;
    transition:border-color .2s, color .2s, background .2s;
  }
  .acct-menu-item:hover{ border-color:var(--gold); color:var(--gold); background:rgba(201,162,39,.08); }
  .acct-menu-item svg{ width:17px; height:17px; flex:0 0 auto; }
  /* plakietka w pozycji listy — statyczna, dociśnięta do prawej krawędzi
     (inaczej niż na .round-btn, gdzie siedzi w rogu kółka) */
  .acct-menu-item .bell-badge{
    position:static; margin-left:auto;
    min-width:18px; height:18px; padding:0 5px; border-radius:9px;
    background:var(--gold); color:#141c16;
    font-family:'JetBrains Mono',monospace; font-size:10.5px; font-weight:600;
    display:flex; align-items:center; justify-content:center; line-height:1;
    border:none; pointer-events:none;
  }
  .acct-menu-item .bell-badge[hidden]{ display:none; }
  .acct-menu-sep{ height:1px; background:var(--line); margin:4px 2px; }
  .acct-menu-item.is-out{ color:var(--ink-dim); }
  .acct-menu-item.is-out:hover{ color:var(--gold); }
  @media (max-width:640px){ .acct-menu{ min-width:210px; } }
</style>
</head>
<body>

<div class="wrap">
  <header class="top">
    <div>
      <a class="wordmark" href="index.php">z<span>mogilna</span></a>
      <div class="coords">konto · @<?= htmlspecialchars((string) $profile['username']) ?></div>
    </div>
    <div class="top-actions">
      <!-- Tylko 3 przyciski funkcyjne: tryb jasny/ciemny, strona główna
           i ikona konta. Ikona konta otwiera menu z funkcjami konta —
           powiadomienia, wiadomości, profil, dodawanie zdjęcia i wylogowanie.
           Plakietki w menu mają te same ID co dawniej dzwonek i dymek
           (#bellBadge, #msgBadge), więc poller i serwerowe liczniki
           startowe działają bez zmian. -->
      <button class="round-btn" id="themeToggleP" type="button" aria-label="Przełącz tryb jasny/ciemny" title="Przełącz tryb jasny/ciemny">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      </button>
      <a class="round-btn" href="index.php" aria-label="Strona główna" title="Strona główna">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></svg>
      </a>
      <button class="round-btn" id="acctToggleP" type="button" aria-label="Konto" title="Twoje konto"
              aria-haspopup="true" aria-expanded="false" aria-controls="acctMenu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="bell-badge" id="acctBadge"<?= ($initialUnread + $initialMessages) > 0 ? '' : ' hidden' ?>><?= $initialUnread + $initialMessages ?></span>
      </button>
      <div class="acct-menu" id="acctMenu" hidden>
        <a class="acct-menu-item" id="notifyBell" href="powiadomienia.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span>Powiadomienia</span>
          <span class="bell-badge" id="bellBadge"<?= $initialUnread > 0 ? '' : ' hidden' ?>><?= $initialUnread ?></span>
        </a>
        <a class="acct-menu-item" id="msgBtn" href="wiadomosci.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Wiadomości</span>
          <span class="bell-badge" id="msgBadge"<?= $initialMessages > 0 ? '' : ' hidden' ?>><?= $initialMessages ?></span>
        </a>
        <div class="acct-menu-sep"></div>
        <a class="acct-menu-item" href="<?= htmlspecialchars($initial['profilePath'], ENT_QUOTES) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Mój profil</span>
        </a>
        <button class="acct-menu-item" id="acctAddPhotoP" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          <span>Dodaj zdjęcie</span>
        </button>
        <?php if ($isAdmin): ?>
        <a class="acct-menu-item" href="admin.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>Panel administracyjny</span>
        </a>
        <?php endif; ?>
        <div class="acct-menu-sep"></div>
        <button class="acct-menu-item is-out" id="logoutBtnP" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Wyloguj</span>
        </button>
      </div>
    </div>
  </header>

  <section class="hero">
    <div class="eyebrow">Twoje konto</div>
    <div class="hero-row">
      <button type="button" class="avatar is-button" id="heroAvatar" title="Zmień zdjęcie awatara" aria-label="Zmień zdjęcie awatara">
        <span class="avatar-photo" id="heroAvatarPhoto"<?= $avatarUrl !== '' ? ' style="background-image:url(' . htmlspecialchars($avatarUrl, ENT_QUOTES) . ')"' : '' ?>></span>
        <span class="avatar-initials" id="heroAvatarInitials"<?= $avatarUrl !== '' ? ' hidden' : '' ?>><?= htmlspecialchars($initials) ?></span>
        <span class="avatar-edit-hint" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        </span>
      </button>
      <div>
        <h1>Cześć, <em id="heroName"><?= htmlspecialchars((string) $displayName) ?></em></h1>
        <div class="handle">
          <span>@<?= htmlspecialchars((string) $profile['username']) ?><?= $profile['email'] ? ' · ' . htmlspecialchars((string) $profile['email']) : '' ?></span>
          <span class="badge<?= $isAdmin ? ' is-admin' : '' ?>"><?= $isAdmin ? 'administrator' : 'użytkownik' ?></span>
        </div>
      </div>
    </div>
    <div class="hero-meta">
      <div><b id="favCountStat">—</b>ulubione zdjęcia</div>
      <div><b><?= $approved ?></b>zatwierdzone zdjęcia</div>
      <div><b id="heroPendingStat"><?= $pending ?></b>czeka na moderację</div>
      <div><b><?= htmlspecialchars($memberSince) ?></b>na pokładzie od</div>
    </div>
  </section>

  <main>
    <?php if ($isAdmin): ?>
    <div class="admin-cta">
      <div><b>Masz uprawnienia administratora</b><span>Dodawanie zdjęć, moderacja komentarzy i zgłoszeń oraz statystyki czekają w panelu admina.</span></div>
      <a class="btn" href="admin.php">panel admina →</a>
    </div>
    <?php endif; ?>

    <!-- Jedyny przycisk dodawania: otwiera okno, nie zajmuje strony -->
    <div class="upload-cta">
      <div>
        <b>Dodaj zdjęcie do galerii</b>
        <span>Kliknij, potem wybierz plik albo upuść go w oknie. Odczytamy lokalizację z pliku i sami zaproponujemy kategorię.</span>
      </div>
      <button type="button" class="btn" id="openUploadBtn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        dodaj zdjęcie
      </button>
    </div>

    <!-- ===================== PROFIL ===================== -->
    <div class="card" id="profileCard" style="margin-bottom:18px;">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Twój profil</h2>
      <p class="sub">Kilka słów o Tobie i linki do Twoich miejsc w sieci. Profil jest domyślnie prywatny — widoczny dla innych dopiero po włączeniu przełącznika poniżej.</p>

      <div class="avatar-row">
        <div class="avatar">
          <span class="avatar-photo" id="avatarPhoto"<?= $avatarUrl !== '' ? ' style="background-image:url(' . htmlspecialchars($avatarUrl, ENT_QUOTES) . ')"' : '' ?>></span>
          <span class="avatar-initials" id="avatarInitials"<?= $avatarUrl !== '' ? ' hidden' : '' ?>><?= htmlspecialchars($initials) ?></span>
        </div>
        <div class="avatar-row-body">
          <div class="t">Zdjęcie awatara</div>
          <div class="d">Zamiast inicjałów możesz wgrać własne zdjęcie — wtedy pojawia się ono tutaj, na Twoim profilu i przy każdym zdjęciu, które dodałeś do galerii. JPG, PNG, WebP albo HEIC prosto z iPhone’a — możesz wgrać nawet duże zdjęcie z aparatu, bo serwer i tak zapisze jego małą, kwadratową wersję 512×512. Metadane (np. współrzędne GPS z telefonu) kasujemy przy wgraniu.</div>
          <div class="btn-row">
            <button class="btn-ghost" id="avatarPickBtn" type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <span id="avatarPickLabel"><?= $avatarUrl !== '' ? 'zmień zdjęcie' : 'wgraj zdjęcie' ?></span>
            </button>
            <button class="btn-ghost danger" id="avatarRemoveBtn" type="button" style="display:none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              usuń awatar
            </button>
          </div>
          <div class="msg" id="avatarMsg" style="margin:12px 0 0;"></div>
        </div>
      </div>
      <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" hidden>

      <div class="msg" id="pfMsg"></div>
      <form id="pfForm" novalidate>
        <div class="two-col">
          <div>
            <div class="field">
              <label class="lbl" for="pfName">Nazwa wyświetlana</label>
              <input class="input" type="text" id="pfName" maxlength="60" autocomplete="nickname" placeholder="Jak mamy Cię podpisywać?">
              <div class="field-err" data-err="display_name"></div>
            </div>
            <div class="field">
              <label class="lbl" for="pfLocation">Miejscowość</label>
              <input class="input" type="text" id="pfLocation" maxlength="80" placeholder="np. Mogilno, Strzelno, Gębice…">
            </div>
            <div class="field">
              <label class="lbl" for="pfBio">O mnie</label>
              <textarea class="input" id="pfBio" maxlength="300" placeholder="Np. fotografuję okolice Mogilna od 2018 roku."></textarea>
              <div class="count"><span id="pfBioCount">0</span>/300</div>
              <div class="field-err" data-err="bio"></div>
            </div>
          </div>

          <div>
            <label class="lbl">Linki społecznościowe</label>
            <div class="social-list">
              <div>
                <div class="social-field" data-field="facebook">
                  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                  <input class="input" type="text" id="pfFacebook" maxlength="255" placeholder="Facebook — nick albo link" autocomplete="off" aria-label="Facebook">
                </div>
                <div class="field-err" data-err="facebook"></div>
              </div>
              <div>
                <div class="social-field" data-field="instagram">
                  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                  <input class="input" type="text" id="pfInstagram" maxlength="255" placeholder="Instagram — @nick albo link" autocomplete="off" aria-label="Instagram">
                </div>
                <div class="field-err" data-err="instagram"></div>
              </div>
              <div>
                <div class="social-field" data-field="youtube">
                  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>
                  <input class="input" type="text" id="pfYoutube" maxlength="255" placeholder="YouTube — @kanał albo link" autocomplete="off" aria-label="YouTube">
                </div>
                <div class="field-err" data-err="youtube"></div>
              </div>
              <div>
                <div class="social-field" data-field="tiktok">
                  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                  <input class="input" type="text" id="pfTiktok" maxlength="255" placeholder="TikTok — @nick albo link" autocomplete="off" aria-label="TikTok">
                </div>
                <div class="field-err" data-err="tiktok"></div>
              </div>
              <div>
                <div class="social-field" data-field="website">
                  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                  <input class="input" type="text" id="pfWebsite" maxlength="255" placeholder="Twoja strona www" autocomplete="off" aria-label="Strona www">
                </div>
                <div class="field-err" data-err="website"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="switch-row">
          <div>
            <div class="t">Profil publiczny</div>
            <div class="d">Gdy włączony, każdy z linkiem zobaczy Twoją nazwę, opis, miejscowość, linki oraz Twoje zatwierdzone zdjęcia. Adres e-mail nigdy nie jest pokazywany.</div>
          </div>
          <label class="switch" title="Profil publiczny">
            <input type="checkbox" id="pfPublic" aria-label="Profil publiczny">
            <i></i>
          </label>
        </div>

        <div class="switch-row">
          <div>
            <div class="t">Jestem aktywny</div>
            <div class="d">Gdy włączony, przy Twoim zdjęciu pojawia się zielona kropka, gdy korzystasz ze strony. Wyłączony = nikt jej nie zobaczy — nawet podczas wizyty.</div>
          </div>
          <label class="switch" title="Jestem aktywny">
            <input type="checkbox" id="pfShowOnline" aria-label="Jestem aktywny">
            <i></i>
          </label>
        </div>

        <div class="switch-row">
          <div>
            <div class="t">Pokazuj komentarze na profilu</div>
            <div class="d">Sekcja „Komentarze” na Twoim profilu: komentarze napisane z konta i polubione przez Ciebie. Wyłączone = nikt tego nie zobaczy poza Tobą i administratorem. Działa tylko przy publicznym profilu.</div>
          </div>
          <label class="switch" title="Pokazuj komentarze na profilu">
            <input type="checkbox" id="pfShowComments" aria-label="Pokazuj komentarze na profilu">
            <i></i>
          </label>
        </div>

        <div class="btn-row">
          <button class="btn" id="pfSubmit" type="submit">Zapisz profil</button>
        </div>
      </form>

      <h3>Twój adres profilu</h3>
      <div class="share-box is-off" id="shareBox">
        <code id="shareUrl">—</code>
        <button class="btn-ghost" id="copyBtn" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          kopiuj
        </button>
        <!-- href nadaje JS dopiero przy profilu publicznym (linia niżej).
             Pusty href byłby linkiem do fragmentu, a takie Chrome na Androidzie
             zamienia w adres z #:~:text=… . -->
        <a class="btn-ghost alt" id="viewBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          zobacz profil
        </a>
      </div>
      <p class="note" id="shareNote"></p>
    </div>

    <div class="grid-2" style="margin-bottom:18px;">
      <div class="card">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h20"/><path d="M4 20V10M10 20V4M16 20V8M22 20V6M4 4v6M10 4v16M16 8v12M22 6v14" transform="translate(0 0)"/></svg>Twoja ranga</h2>
        <p class="sub">Punkty zdobywasz aktywnością — status bije się w galerii.</p>

        <div class="rank-box" id="rankBox">
          <div class="rank-main">
            <span class="rk-chip" id="rankChip" style="background:hsl(<?= (int)$myRankHue ?> 45% 32%); color:hsl(<?= (int)$myRankHue ?> 70% 82%);"><span id="rankChipName"><?= htmlspecialchars((string) ($myRank['name'] ?? '—'), ENT_QUOTES) ?></span><span id="rankChipEd"<?= $rankManual ? '' : ' style="display:none"' ?>> ✎</span></span>
            <?php /* Zawsze w DOM, tylko ukryty: .rk-chip ma własny display, więc atrybut
                     hidden by przeglądarkę przegrałby z arkuszem stylów. */ ?>
            <span class="rk-chip" id="rankSpChip" style="<?= $mySpRank !== null ? '' : 'display:none;' ?>background:hsl(<?= (int)$mySpRankHue ?> 45% 32%); color:hsl(<?= (int)$mySpRankHue ?> 70% 82%);" title="Ranga specjalna — nadaje ją wyłącznie administracja">★ <span id="rankSpName"><?= htmlspecialchars((string) ($mySpRank['name'] ?? ''), ENT_QUOTES) ?></span></span>
            <span class="rk-points"><span id="rankPointsNum"><?= number_format($points, 0, ',', ' ') ?></span> <em>pkt</em></span>
          </div>

          <?php if ($nextRank !== null):
            $mineMin = (int) $myRank['min_points'];
            $nextMin = (int) $nextRank['min_points'];
            $span = max(1, $nextMin - $mineMin);
            $fill = (int) round(100 * max(0, $points - $mineMin) / $span);
            $fill = max(0, min(100, $fill));
            $need = max(0, $nextMin - $points);
          ?>
          <div class="rk-progress" id="rankProgress" title="Do rangi <?= htmlspecialchars((string) $nextRank['name'], ENT_QUOTES) ?> brakuje <?= $need ?> punktów">
            <div class="rk-bar"><i id="rankBarFill" style="width:<?= $fill ?>%;"></i></div>
            <div class="rk-bar-label" id="rankBarLabel"><?= $fill ?>% · do „<?= htmlspecialchars((string) $nextRank['name'], ENT_QUOTES) ?>” zostało <?= $need ?> pkt</div>
          </div>
          <?php else: ?>
          <div class="rk-progress" id="rankProgress" style="display:none;">
            <div class="rk-bar"><i id="rankBarFill" style="width:0%;"></i></div>
            <div class="rk-bar-label" id="rankBarLabel"></div>
          </div>
          <?php endif; ?>

          <div class="rk-stats">
            <span title="Zatwierdzone, opublikowane zdjęcia">📷 <b id="rankStatPhotos"><?= $cntPhotosApproved ?></b></span>
            <span title="Komentarze napisane z konta">💬 <b id="rankStatComments"><?= $cntComments ?></b></span>
            <span title="Polubienia zdjęć oddane z konta">♥ <b id="rankStatLikes"><?= $cntLikes ?></b></span>
          </div>
        </div>

        <div class="btn-row" style="margin-top:16px;">
          <button class="btn-ghost alt" type="button" id="rkOpenBtn">tabela rang i FAQ →</button>
        </div>
      </div>

      <div class="card">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Zmień hasło</h2>
        <div class="msg" id="pwMsg"></div>
        <form id="pwForm">
          <div class="field">
            <label class="lbl" for="pwCurrent">Obecne hasło</label>
            <input class="input" type="password" id="pwCurrent" autocomplete="current-password" required>
          </div>
          <div class="field">
            <label class="lbl" for="pwNew">Nowe hasło</label>
            <input class="input" type="password" id="pwNew" autocomplete="new-password" minlength="8" required>
          </div>
          <div class="field">
            <label class="lbl" for="pwNew2">Powtórz nowe hasło</label>
            <input class="input" type="password" id="pwNew2" autocomplete="new-password" minlength="8" required>
          </div>
          <button class="btn block" id="pwSubmit" type="submit">Zapisz nowe hasło</button>
          <p class="note">Minimum 8 znaków. Zmiana hasła nie wylogowuje.</p>
        </form>
      </div>
    </div>

    <!-- ===================== ADRES E-MAIL ===================== -->
    <!-- Osobna karta obok hasła, bo zmiana adresu to nie jest „profil”.
         Formularz wysyła do istniejącego auth-api.php?action=email_change,
         które wymaga hasła — nie ma tu żadnej równoległej ścieżki zapisu. -->
    <div class="card" id="emailCard" style="margin-bottom:18px;">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>Adres e-mail</h2>
      <p class="sub">Adres e-mail jest prywatny. Służy wyłącznie do odzyskiwania hasła i jest widoczny tylko dla Ciebie — nigdzie w galerii ani na profilu publicznym się nie pojawia.</p>

      <label class="lbl">Zapisany adres</label>
      <div class="email-current" id="emailCurrent"><?= $ownerEmail !== '' ? htmlspecialchars($ownerEmail, ENT_QUOTES) : 'nie ustawiony — hasła nie da się wtedy odzyskać' ?></div>
      <p class="note" id="emailNote" style="margin:8px 0 0;"></p>

      <div class="btn-row" style="margin-top:14px;">
        <button type="button" class="btn-ghost" id="emailToggle" aria-expanded="false" aria-controls="emailForm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
          Zmień adres
        </button>
      </div>

      <form id="emailForm" hidden novalidate style="margin-top:18px;">
        <div class="msg" id="emailMsg"></div>
        <div class="field">
          <label class="lbl" for="newEmail">Nowy adres e-mail</label>
          <input class="input" type="email" id="newEmail" maxlength="190" autocomplete="email" placeholder="np. imie@example.pl">
        </div>
        <div class="field">
          <label class="lbl" for="emailPassword">Obecne hasło</label>
          <input class="input" type="password" id="emailPassword" autocomplete="current-password">
        </div>
        <p class="note">Podajemy hasło, żeby ktoś, kto przejmie tę sesję, nie podmienił adresu i nie przejął trwale konta przez reset hasła.</p>
        <div class="btn-row" style="margin-top:16px;">
          <button type="submit" class="btn" id="emailSubmit">Zapisz nowy adres</button>
          <button type="button" class="btn-ghost" id="emailCancel">Anuluj</button>
        </div>
      </form>
    </div>

<!-- ===================== TWOJE ZGŁOSZENIA ===================== -->
    <div class="card">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Twoje zgłoszenia</h2>
      <p class="sub">Zdjęcie trafia najpierw do administratora i w galerii pojawia się dopiero po moderacji.</p>
      <div class="sub-list" id="subList"><p class="note">Wczytywanie…</p></div>
      <div class="sub-more-wrap" id="subMore"></div>
      <div class="msg" id="subActionMsg" role="status" aria-live="polite"></div>
    </div>

<!-- ===================== SPOŁECZNOŚĆ ===================== -->
    <div class="card" id="socialCard">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Obserwujący i obserwowani</h2>
      <p class="sub">Obserwujesz czyjeś profile z galerii, a Ci obserwujący dostają informację
        o nowych zdjęciach i komentarzach. Powiadomienia ustawisz w swoim panelu powiadomień.</p>

      <div class="hero-meta" style="margin-top:6px">
        <button type="button" class="hero-count" data-people="followers"><b id="meFollowers"><?= $followersCount ?></b>obserwujących Cię</button>
        <button type="button" class="hero-count" data-people="following"><b id="meFollowing"><?= $followingCount ?></b>obserwujesz</button>
      </div>

      <div class="btn-row" style="margin-top:16px">
        <a class="btn-ghost" href="powiadomienia.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          Powiadomienia i wiadomości
        </a>
        <?php if ($isPublic): ?>
        <a class="btn-ghost alt" href="<?= htmlspecialchars(zm_profile_path((string) $profile['username'], (int) $me['id'])) ?>">zobacz swój profil</a>
        <?php else: ?>
        <span class="note" style="align-self:center">Profil jest prywatny — obserwujący znajdą Cię dopiero po jego włączeniu.</span>
        <?php endif; ?>
      </div>
    </div>

<!-- ===================== ZABLOKOWANI ===================== -->
    <div class="card" id="blockedCard">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>Zablokowani</h2>
      <p class="sub">Zablokowana osoba nie widzi Twoich nowych zdjęć i nie może do Ciebie pisać. Rozmowy wrócą, gdy kogoś odblokujesz.</p>
      <div class="msg" id="blockedMsg" style="margin-bottom:14px;"></div>
      <div class="sub-list" id="blockedList"><p class="note">Wczytywanie…</p></div>
    </div>
  </main>

  <div class="modal-backdrop" id="subEditBackdrop" role="dialog" aria-modal="true" aria-labelledby="subEditTitle">
    <div class="modal" style="max-width:520px;">
      <h2 id="subEditTitle">Edytuj opis zdjęcia</h2>
      <p class="sub">Zmień krótki opis wyświetlany na zdjęciu. Zmiana jest widoczna od razu po zapisaniu.</p>
      <label class="lbl" for="subEditDescription">Opis zdjęcia</label>
      <input class="input" type="text" id="subEditDescription" maxlength="160" placeholder="np. most żelazny, wiatrak, staw">
      <div class="count"><span id="subEditCount">0</span>/160</div>
      <div class="msg" id="subEditMsg" role="status" aria-live="polite"></div>
      <div class="modal-actions" style="margin-top:14px;">
        <button type="button" class="modal-cancel" id="subEditCancel">Anuluj</button>
        <button type="button" class="btn" id="subEditSave">Zapisz opis</button>
      </div>
    </div>
  </div>

  <!-- ===================== OKNO DODAWANIA ZDJĘCIA ===================== -->
  <div class="modal-backdrop" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
    <div class="modal" id="uploadModalBox" tabindex="-1">
      <h2 id="uploadModalTitle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Dodaj zdjęcie
      </h2>
      <p class="sub" id="uploadModalSub">Trzy kroki: wybierz plik, poczekaj na dane, uzupełnij szczegóły.</p>

      <div class="msg" id="subMsg" style="margin-bottom:14px;"></div>

      <form id="subForm" novalidate>
        <!-- krok 1: plik -->
        <div class="modal-steps" id="stepPick">
          <div class="drop-zone" id="dropZone">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div class="dz-text">Kliknij albo upuść zdjęcie lub film tutaj (JPG, PNG, WebP, GIF, HEIC, MOV, MP4)</div>
            <div class="dz-files" id="dzFiles"></div>
            <!-- rozszerzenia .heic/.heif są tu celowo obok MIME: w Windowsie plikom
                 HEIC nie jest przypisany typ MIME, więc samo image/heic ich nie łapie.
                 Filmy i grafiki poza JPG i tak przechodzą przez konwersję na JPG
                 w przeglądarce — do backendu leci zawsze obraz. -->
            <input type="file" id="fileInput" accept="image/*,video/*,.heic,.heif,.avif,.tif,.tiff,.bmp,.mov,.mp4,.m4v,.webm" style="display:none;">
          </div>
          <div class="modal-actions">
            <button type="button" class="modal-cancel" id="upCancel1">anuluj</button>
          </div>
        </div>

        <!-- krok 2: czytanie danych z pliku -->
        <div class="modal-steps" id="stepScan">
          <div class="scan">
            <div class="scan-ring" aria-hidden="true"></div>
            <div class="scan-title" id="scanTitle" role="status" aria-live="polite">Przygotowujemy zdjęcie…</div>
            <div class="scan-file" id="scanFile">—</div>
            <ul class="scan-steps" id="scanSteps">
              <li data-step="prepare">przygotowanie zdjęcia i podglądu</li>
              <li data-step="exif">odczyt metadanych (EXIF)</li>
              <li data-step="gps">sprawdzanie współrzędnych GPS</li>
              <li data-step="geo">ustalanie lokalizacji i gminy</li>
              <li data-step="cat">dobieranie kategorii</li>
            </ul>
          </div>
          <div class="modal-actions">
            <button type="button" class="modal-cancel" id="upCancel2">anuluj</button>
          </div>
        </div>

        <!-- krok 3: dane zdjęcia -->
        <div class="modal-steps" id="stepForm">
          <div class="up-preview">
            <img id="upThumb" alt="Podgląd wybranego zdjęcia">
            <div>
              <div class="up-name" id="upName">—</div>
              <div class="up-meta" id="upMeta">—</div>
            </div>
            <button type="button" class="btn-ghost up-swap" id="upSwap">zmień plik</button>
          </div>

          <!-- wypełniane przez JS zależnie od tego, czy lokalizację udało się ustalić -->
          <div class="geo-box" id="upGeoBox">
            <div class="gb-title" id="upGeoTitle">Lokalizacja</div>
            <div id="upGeoBody">—</div>
          </div>

          <!-- propozycja kategorii (np. „Gmina Mogilno → Goryszewo”) — z GPS z pliku
               albo z nazwy miejscowości. Nie wybiera kategorii za użytkownika:
               propozycja idzie do moderacji. Przycisk „zmień” otwiera pole
               miejscowości, żeby poprawić rozpoznanie z pliku. -->
          <div class="geo-box prop-box" id="upProposalBox" hidden>
            <div class="gb-title">Proponowana kategoria</div>
            <div class="prop-row">
              <div id="upProposalBody">—</div>
              <button type="button" class="btn-ghost prop-edit" id="upProposalEdit">zmień</button>
            </div>
          </div>

          <!-- jedyne okno na miejsce: system sam ustala kategorię z lokalizacji.
               Gdy plik ma GPS, pole zostaje widoczne i służy do korekty
               rozpoznania („właściwie to Goryszewo”). Gdy GPS-a nie ma, nazwa
               jest jedynym źródłem kategorii — bez niej serwer odrzuci
               zgłoszenie z czytelnym powodem. -->
          <div class="field" id="upPlaceField" hidden>
            <label class="lbl" for="subPlaceName">Miejscowość</label>
            <input class="input" type="text" id="subPlaceName" maxlength="150" placeholder="np. Goryszewo" autocomplete="off" spellcheck="false">
            <p class="hint" id="subPlaceHint">Plik nie ma współrzędnych — wpisz nazwę miejscowości, a ustalimy, do której gminy należy.</p>
            <div id="upPlaceSuggest" class="place-suggest" hidden></div>
            <input type="hidden" id="subCoordinates" name="coordinates" value="">
            <input type="hidden" id="subPlaceCtxMiejsc" value="">
            <input type="hidden" id="subPlaceCtxGmina" value="">
            <input type="hidden" id="subPlaceCtxLat" value="">
            <input type="hidden" id="subPlaceCtxLng" value="">
            <p class="hint">Możesz też wpisać nazwę obiektu (np. most żelazny, klasztor). System sprawdzi okolice Mogilna i podpowie pasujące punkty.</p>
          </div>

          <div class="field">
            <label class="lbl" for="subTitle">Opis (plakietka na zdjęciu)</label>
            <input class="input" type="text" id="subTitle" maxlength="160" placeholder="np. most żelazny, wiatrak, staw">
            <p class="hint">Pokażemy go na powiększonym zdjęciu. Nazwa zdjęcia zawsze pochodzi z miejscowości.</p>
            <input type="hidden" id="subPlaceDescCoords" name="place_desc_coords" value="">
          </div>

          <!-- Opcjonalne oznaczenie zdjęcia jako atrakcja turystyczna. Maks.
               2 kategorie (limit z konfiguracji) — licznik i blokada w JS
               poniżej; nic nie publikuje się od razu, oznaczenie trafia do
               moderacji razem ze zdjęciem. Lista pochodzi ze wspólnej
               konfiguracji (zm_attraction_labels()), tego samego źródła,
               które waliduje zgłoszenie po stronie API. Sekcja znika, gdy
               administracja wyłączy zgłaszanie etykiet. -->
          <?php if (!empty(zm_attraction_config()['enable_submission'])): ?>
          <?php $attrMaxLabels = max(1, min(5, (int) (zm_attraction_config()['max_labels'] ?? 2))); ?>
          <?php $attrMeta = function_exists('zm_attraction_label_meta') ? zm_attraction_label_meta() : []; ?>
          <?php
            $attrWords = $attrMaxLabels === 1 ? 'kategorię' : ($attrMaxLabels < 5 ? 'kategorie' : 'kategorii');
          ?>
          <details class="field attr-disclosure" id="upAttrDisclosure">
            <summary class="attr-disclosure-toggle">
              <span class="attr-disclosure-q">Czy to zdjęcie pokazuje atrakcję turystyczną?</span>
              <span class="attr-disclosure-sub">opcjonalnie · rozwiń, żeby wybrać kategorię</span>
              <span class="attr-disclosure-chev" aria-hidden="true"></span>
            </summary>
            <div class="attr-disclosure-body">
              <p class="hint">Wybierz maks. <?= $attrMaxLabels ?> <?= $attrWords ?>, które najlepiej opisują to miejsce. Po zatwierdzeniu przez administratora zdjęcie może trafić do sekcji atrakcji.</p>
              <div class="attr-checks attr-cards" id="upAttrList" data-max="<?= $attrMaxLabels ?>">
                <?php $attrDefaults = array_slice(zm_attraction_default_label_keys(), 0, $attrMaxLabels); ?>
                <?php foreach (zm_attraction_labels() as $attrKey => $attrName): ?>
                  <?php $attrIcon = (string) ($attrMeta[$attrKey]['icon'] ?? ''); ?>
                  <?php $attrDesc = (string) ($attrMeta[$attrKey]['desc'] ?? ''); ?>
                  <label class="check-row attr-card"<?= $attrDesc !== '' ? ' title="' . htmlspecialchars($attrDesc, ENT_QUOTES) . '"' : '' ?>>
                    <input type="checkbox" name="labels[]" value="<?= htmlspecialchars((string) $attrKey, ENT_QUOTES) ?>"<?= in_array($attrKey, $attrDefaults, true) ? ' checked' : '' ?>>
                    <span class="t"><?php if ($attrIcon !== ''): ?><span class="attr-ico" aria-hidden="true"><?= htmlspecialchars($attrIcon, ENT_QUOTES) ?></span> <?php endif; ?><?= htmlspecialchars((string) $attrName, ENT_QUOTES) ?><?php if ($attrDesc !== ''): ?><small><?= htmlspecialchars($attrDesc, ENT_QUOTES) ?></small><?php endif; ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="hint attr-counter" id="upAttrCounter" aria-live="polite"></p>
              <input class="input" type="text" id="subAttractionNote" name="attraction_note" maxlength="255" placeholder="Notatka (opcjonalnie) — dlaczego warto tu przyjechać" style="margin-top:10px;">
            </div>
          </details>
          <?php endif; ?>

          <div class="modal-actions">
            <button type="button" class="modal-cancel" id="upCancel3">anuluj</button>
            <button class="btn" id="subSubmit" type="submit">wyślij do moderacji</button>
          </div>
        </div>
      </form>

      <!-- krok 4: potwierdzenie -->
      <div class="modal-steps" id="stepDone">
        <div class="scan">
          <div class="done-preview">
            <img id="doneThumb" alt="Podgląd dodanego zdjęcia">
            <div class="done-info">
              <div class="scan-title" style="color:var(--teal);">✓ Zdjęcie dodane</div>
              <div class="scan-file" id="doneFile">—</div>
            </div>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="modal-cancel" id="upClose">zamknij</button>
          <button type="button" class="btn" id="upAgain">dodaj kolejne</button>
        </div>
      </div>
    </div>
  </div>


  <?php require __DIR__ . '/footer-contact.php'; ?>
</div>

<script>
(function(){
  const el = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const PLACE_HINT_NONE = 'Plik nie ma współrzędnych — wpisz nazwę miejscowości, a ustalimy, do której gminy należy.';
  const INITIAL = <?= json_encode($initial, $jsonFlags) ?>;
  let csrfToken = '';
  let selectedFiles = [];

  // ---------- motyw (ten sam klucz co reszta serwisu) ----------
  function applyTheme(t){
    if(t === 'light'){
      document.documentElement.setAttribute('data-theme', 'light');
      el('metaThemeColor').setAttribute('content', '#f5f1e6');
    } else {
      document.documentElement.removeAttribute('data-theme');
      el('metaThemeColor').setAttribute('content', '#101c16');
    }
  }
  el('themeToggleP').addEventListener('click', () => {
    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
    const next = isLight ? 'dark' : 'light';
    try{ localStorage.setItem('zmogilna-theme', next); }catch(_){}
    applyTheme(next);
  });

  // ---------- ulubione ----------
  // licznik przychodzi z bazy w odpowiedzi auth-api.php?action=rank (pole
  // counts.favorites) i jest nanoszony w applyRank(). Wcześniej brano go
  // z localStorage tej samej przeglądarki, więc pokazywał stan sprzed
  // zalogowania i rozjeżdżał się z serwerem.

  // ---------- sesja ----------
  async function whoAmI(){
    try{
      const res = await fetch('auth-api.php?action=whoami', { credentials:'include' });
      const data = await res.json();
      csrfToken = data.csrf || '';
      if(!data.user){ window.location.href = 'index.php?login=1'; }
    }catch(_){}
  }
  whoAmI();

  el('logoutBtnP').addEventListener('click', async () => {
    try{ await fetch('auth-api.php?action=logout', { method:'POST', credentials:'include' }); }catch(_){}
    window.location.href = 'index.php?logout=1';
  });

  // ---------- menu konta (ikona konta → lista funkcji) ----------
  // Pasek ma tylko 3 przyciski, więc dzwonek, dymek i wylogowanie siedzą
  // w tej liście. Klik w ikonę przełącza, klik poza listą i Escape zamykają.
  const acctToggle = el('acctToggleP');
  const acctMenu = el('acctMenu');
  function setAcctMenu(open){
    if(!acctMenu || !acctToggle) return;
    acctMenu.hidden = !open;
    acctToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if(acctToggle && acctMenu){
    acctToggle.addEventListener('click', (e) => {
      e.stopPropagation();   // inaczej ten sam klik zaraz zamknąłby listę
      setAcctMenu(acctMenu.hidden);
    });
    document.addEventListener('click', (e) => {
      if(acctMenu.hidden) return;
      if(acctMenu.contains(e.target) || acctToggle.contains(e.target)) return;
      setAcctMenu(false);
    });
    document.addEventListener('keydown', (e) => {
      if(e.key === 'Escape' && !acctMenu.hidden){
        setAcctMenu(false);
        acctToggle.focus();
      }
    });
    // „Dodaj zdjęcie” z listy otwiera to samo okno co CTA w panelu;
    // lista sama się zamyka, żeby nie zasłaniać modala
    const addFromMenu = el('acctAddPhotoP');
    if(addFromMenu) addFromMenu.addEventListener('click', () => {
      setAcctMenu(false);
      openUpload();
    });
  }

  // ---------- plakietki menu konta ----------
  // Trzy liczniki: powiadomienia (#bellBadge w pozycji menu), wiadomości
  // (#msgBadge) i suma na samej ikonie konta (#acctBadge). Wszystkie rysuje
  // serwer przy renderowaniu, a tutaj tylko je odświeżamy. Odczyt
  // (social-api.php?action=count) to dwa COUNT-y, więc odpytywanie co minutę
  // nie obciąża bazy. Pomijamy je, gdy karta jest ukryta — w tle nie ma po co.
  (function bellPoller(){
    const badge = el('bellBadge');
    if(!badge) return;
    let last = parseInt(badge.textContent, 10) || 0;
    // pozycja „Wiadomości” — osobna liczba (tylko `messages`),
    // zmienia się niezależnie od powiadomień, więc pilnujemy jej osobno
    const msgBadge = el('msgBadge');
    let lastMsg = msgBadge ? (parseInt(msgBadge.textContent, 10) || 0) : 0;
    // suma obu skrzynek na ikonie konta — jedyny licznik widoczny bez
    // otwierania listy
    const acctBadge = el('acctBadge');
    let lastAcct = acctBadge ? (parseInt(acctBadge.textContent, 10) || 0) : -1;

    async function refresh(){
      if(document.hidden) return;
      try{
        const res = await fetch('social-api.php?action=count', { credentials:'include' });
        if(res.status === 401) return;          // sesja wygasła — nie martwimy się
        const d = await res.json();
        const u = Number(d.unread) || 0;        // same powiadomienia
        const m = Number(d.messages) || 0;      // same wiadomości
        const t = u + m;                        // suma na ikonie konta
        if(msgBadge && m !== lastMsg){
          lastMsg = m;
          msgBadge.textContent = m;
          msgBadge.hidden = m <= 0;
        }
        if(u !== last){
          last = u;
          badge.textContent = u;
          badge.hidden = u <= 0;
        }
        if(acctBadge && t !== lastAcct){
          lastAcct = t;
          acctBadge.textContent = t;
          acctBadge.hidden = t <= 0;
        }
      }catch(_){ /* brak sieci — zostawiamy poprzednią liczbę */ }
    }

    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', () => { if(!document.hidden) refresh(); });
  })();

  // ============================================================
  // PROFIL
  // ============================================================
  const FIELDS = ['facebook','instagram','youtube','tiktok','website'];
  const inputFor = { facebook:'pfFacebook', instagram:'pfInstagram', youtube:'pfYoutube', tiktok:'pfTiktok', website:'pfWebsite' };

  function initialsOf(name){
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if(parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
    if(parts.length === 1) return parts[0].slice(0,2).toUpperCase();
    return '?';
  }

  function fillForm(p){
    el('pfName').value = p.display_name || '';
    el('pfLocation').value = p.location || '';
    el('pfBio').value = p.bio || '';
    FIELDS.forEach(f => { el(inputFor[f]).value = p[f] || ''; });
    el('pfPublic').checked = !!p.public;
    el('pfShowOnline').checked = p.show_online !== false;
    el('pfShowComments').checked = p.show_comments !== false;
    el('pfBioCount').textContent = (p.bio || '').length;
    renderEmail(p.email || '');
    renderHero(p);
    renderShare(p);
  }

  // Karta „Adres e-mail”. Zmiany nie ma w formularzu profilu — e-mail
  // zmienia się osobnym żądaniem, które wymaga podania hasła.
  function renderEmail(email){
    const has = !!email;
    el('emailCurrent').textContent = has ? email : 'nie ustawiony — hasła nie da się wtedy odzyskać';
    el('emailNote').textContent = has
      ? 'Na ten adres wysyłamy link, gdy zapomnisz hasła. Adres jest prywatny — nikt go nie zobaczy.'
      : 'Bez adresu nie odzyskasz hasła, jeśli je zapomnisz. Uzupełnij, żeby móc się zalogować z powrotem.';
    el('newEmail').value = email;
  }

  function renderHero(p){
    el('heroName').textContent = p.display_name || p.username;
    el('heroAvatarInitials').textContent = initialsOf(p.display_name || p.username);
  }

  // Adres profilu policzony przez PHP (zm_profile_path). Nie składamy go
  // tutaj z loginu na nowo — inaczej link w panelu różniłby się od linku
  // w galerii dla loginów kolidujących z adresami aplikacji.
  function profileUrl(p){
    return new URL(p.profilePath || ('profil.php?u=' + encodeURIComponent(p.username)), window.location.href).href;
  }

  function renderShare(p){
    const url = profileUrl(p);
    el('shareUrl').textContent = url;
    el('viewBtn').href = url;
    // kopiowanie działa tylko przy profilu publicznym — przy prywatnym
    // adres i tak nie działa dla innych
    const isPublic = !!p.public;
    el('shareBox').classList.toggle('is-off', !isPublic);
    el('copyBtn').disabled = !isPublic;
    el('copyBtn').style.opacity = isPublic ? '' : '.45';
    el('shareNote').textContent = isPublic
      ? 'Profil jest publiczny — każdy z tym adresem zobaczy to, co wpisano powyżej.'
      : 'Profil jest prywatny. Adres zadziała dla innych dopiero po włączeniu „Profil publiczny” i zapisaniu. Ty zawsze widzisz podgląd.';
  }

  function clearErrors(){
    document.querySelectorAll('[data-err]').forEach(n => { n.textContent = ''; n.classList.remove('show'); });
    document.querySelectorAll('.social-field').forEach(n => n.classList.remove('has-error'));
    el('pfMsg').className = 'msg';
    el('pfMsg').textContent = '';
  }

  function showFieldError(field, message){
    const holder = document.querySelector('[data-err="' + field + '"]');
    if(holder){ holder.textContent = message; holder.classList.add('show'); }
    const sf = document.querySelector('.social-field[data-field="' + field + '"]');
    if(sf){ sf.classList.add('has-error'); }
  }

  el('pfBio').addEventListener('input', () => { el('pfBioCount').textContent = el('pfBio').value.length; });

  el('pfPublic').addEventListener('change', () => {
    renderShare({ username: INITIAL.username, public: el('pfPublic').checked, profilePath: INITIAL.profilePath });
  });

  el('pfForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors();
    const btn = el('pfSubmit');
    btn.disabled = true; btn.textContent = 'Zapisywanie…';
    const body = {
      csrf: csrfToken,
      display_name: el('pfName').value,
      // pola `email` tu celowo nie ma — profile_save rusza adres tylko wtedy,
      // gdy klient go jawnie przysyła. E-mail zmienia osobna, chroniona
      // hasłem akcja email_change poniżej.
      location: el('pfLocation').value,
      bio: el('pfBio').value,
      public: el('pfPublic').checked,
      show_comments: el('pfShowComments').checked,
      show_online: el('pfShowOnline').checked
    };
    FIELDS.forEach(f => { body[f] = el(inputFor[f]).value; });
    try{
      const res = await fetch('auth-api.php?action=profile_save', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify(body)
      });
      const data = await res.json();
      if(!res.ok || !data.ok){
        if(data.field){ showFieldError(data.field, data.message || 'Niepoprawna wartość.'); }
        el('pfMsg').className = 'msg err';
        el('pfMsg').textContent = data.message || 'Nie udało się zapisać profilu.';
        return;
      }
      fillForm(data.profile);
      el('pfMsg').className = 'msg ok';
      el('pfMsg').textContent = 'Profil zapisany.';
    }catch(_){
      el('pfMsg').className = 'msg err';
      el('pfMsg').textContent = 'Błąd połączenia z serwerem.';
    }finally{
      btn.disabled = false; btn.textContent = 'Zapisz profil';
    }
  });

  el('copyBtn').addEventListener('click', async () => {
    const url = el('shareUrl').textContent;
    try{
      await navigator.clipboard.writeText(url);
    }catch(_){
      const ta = document.createElement('textarea');
      ta.value = url; document.body.appendChild(ta); ta.select();
      try{ document.execCommand('copy'); }catch(__){}
      document.body.removeChild(ta);
    }
    const btn = el('copyBtn');
    const prev = btn.innerHTML;
    btn.textContent = 'skopiowano ✓';
    setTimeout(() => { btn.innerHTML = prev; }, 1600);
  });

  fillForm(INITIAL);

  // ============================================================
  // AWATAR
  // ============================================================
  const avatarInput = el('avatarInput');
  const HEIC_EXT = /\.(heic|heif)$/i;

  function paintAvatar(url){
    const has = !!url;
    [el('heroAvatarPhoto'), el('avatarPhoto')].forEach(node => {
      node.style.backgroundImage = has ? 'url("' + url + '")' : '';
    });
    [el('heroAvatarInitials'), el('avatarInitials')].forEach(node => { node.hidden = has; });
    el('avatarRemoveBtn').style.display = has ? '' : 'none';
    el('avatarPickLabel').textContent = has ? 'zmień zdjęcie' : 'wgraj zdjęcie';
  }

  function avatarMsg(text, ok){
    const m = el('avatarMsg');
    m.className = 'msg ' + (ok ? 'ok' : 'err');
    m.textContent = text;
  }

  function fileSize(bytes){
    return bytes >= 1048576
      ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'
      : Math.max(1, Math.round(bytes / 1024)) + ' kB';
  }

  // avatar-api.php czyta multipart, więc wysyłamy FormData także przy usuwaniu
  async function avatarCall(action, file){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', csrfToken);
    if (file) fd.append('avatar', file);
    const res = await fetch('avatar-api.php?action=' + action, {
      method:'POST', credentials:'include', body: fd
    });
    return res.json();
  }

  async function uploadAvatar(file){
    const btn = el('avatarPickBtn');
    const prevLabel = el('avatarPickLabel').textContent;
    const isHeic = HEIC_EXT.test(file.name || '');
    btn.disabled = true;
    el('avatarPickLabel').textContent = 'wgrywanie…';
    el('avatarMsg').className = 'msg';
    el('avatarMsg').textContent = '';

    // podgląd zaznaczonego pliku jeszcze przed wysłaniem — duże zdjęcie z aparatu
    // wgrywa się chwilę, a użytkownik od razu widzi, co wybrał
    let previewUrl = '';
    if (!isHeic) {
      previewUrl = URL.createObjectURL(file);
      paintAvatar(previewUrl);
    }

    try{
      const data = await avatarCall('upload', file);
      if (!data.ok) {
        avatarMsg(data.message || 'Nie udało się wgrać awatara.', false);
        if (previewUrl) { paintAvatar(INITIAL.avatar || ''); }
        return;
      }
      paintAvatar(data.avatar.url);
      const how = isHeic ? ' HEIC z iPhone’a zamieniliśmy na JPG.' : '';
      avatarMsg('Awatar zapisany' + how + ' Serwer trzyma małą wersję 512×512, więc duże zdjęcie z aparatu nie obciąża dysku.', true);
    }catch(_){
      avatarMsg('Błąd połączenia z serwerem.', false);
      if (previewUrl) { paintAvatar(INITIAL.avatar || ''); }
    }finally{
      btn.disabled = false;
      el('avatarPickLabel').textContent = prevLabel;
      if (previewUrl) { URL.revokeObjectURL(previewUrl); }
    }
  }

  async function removeAvatar(){
    const btn = el('avatarRemoveBtn');
    btn.disabled = true;
    el('avatarMsg').className = 'msg';
    el('avatarMsg').textContent = '';
    try{
      const data = await avatarCall('remove', null);
      if (!data.ok) {
        avatarMsg(data.message || 'Nie udało się usunąć awatara.', false);
        return;
      }
      paintAvatar('');
      avatarMsg('Awatar usunięty — wróciły inicjały.', true);
    }catch(_){
      avatarMsg('Błąd połączenia z serwerem.', false);
    }finally{
      btn.disabled = false;
    }
  }

  el('heroAvatar').addEventListener('click', () => avatarInput.click());
  el('avatarPickBtn').addEventListener('click', () => avatarInput.click());
  el('avatarRemoveBtn').addEventListener('click', removeAvatar);

  // przeciąganie pliku prosto na kółeczko — wygodniejsze niż szukanie przycisku
  ['dragenter', 'dragover'].forEach(ev => {
    el('heroAvatar').addEventListener(ev, e => { e.preventDefault(); el('heroAvatar').classList.add('is-drop'); });
  });
  ['dragleave', 'drop'].forEach(ev => {
    el('heroAvatar').addEventListener(ev, e => { e.preventDefault(); el('heroAvatar').classList.remove('is-drop'); });
  });
  el('heroAvatar').addEventListener('drop', e => {
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) uploadAvatar(f);
  });

  avatarInput.addEventListener('change', () => {
    const file = avatarInput.files && avatarInput.files[0];
    avatarInput.value = '';   // żeby dało się wybrać ten sam plik jeszcze raz
    if (!file) return;
    // HEIC nie da się podejrzeć w przeglądarce, więc mówimy wprost, co się stanie
    if (HEIC_EXT.test(file.name || '')) {
      avatarMsg('Wgrywam ' + file.name + ' (' + fileSize(file.size) + ') — serwer zamieni HEIC na JPG. Podglądu nie ma, bo przeglądarka nie umie otworzyć HEIC.', true);
    }
    uploadAvatar(file);
  });

  paintAvatar(INITIAL.avatar);

  // ============================================================
  // ZMIANA HASŁA
  // ============================================================
  el('pwForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = el('pwMsg');
    msg.className = 'msg';
    msg.textContent = '';
    const current = el('pwCurrent').value;
    const n1 = el('pwNew').value;
    const n2 = el('pwNew2').value;
    if(n1 !== n2){
      msg.className = 'msg err';
      msg.textContent = 'Nowe hasła nie są takie same.';
      return;
    }
    el('pwSubmit').disabled = true;
    el('pwSubmit').textContent = 'Zapisywanie…';
    try{
      const res = await fetch('auth-api.php?action=change_password', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ current_password:current, new_password:n1, csrf:csrfToken })
      });
      const data = await res.json();
      if(!res.ok || !data.ok){
        msg.className = 'msg err';
        msg.textContent = data.message || 'Nie udało się zmienić hasła.';
        return;
      }
      msg.className = 'msg ok';
      msg.textContent = 'Hasło zostało zmienione.';
      el('pwForm').reset();
    }catch(_){
      msg.className = 'msg err';
      msg.textContent = 'Błąd połączenia z serwerem.';
    }finally{
      el('pwSubmit').disabled = false;
      el('pwSubmit').textContent = 'Zapisz nowe hasło';
    }
  });

  // ============================================================
  // ZMIANA ADRESU E-MAIL
  // ============================================================
  // Idzie do istniejącego auth-api.php?action=email_change — tam jest
  // weryfikacja hasła (password_verify), limit prób i sprawdzenie,
  // czy adres nie jest już zajęty. Panel nie powtarza żadnej z tych reguł.
  const emailForm = el('emailForm');
  el('emailToggle').addEventListener('click', () => {
    const open = emailForm.hidden;
    emailForm.hidden = !open;
    el('emailToggle').setAttribute('aria-expanded', open ? 'true' : 'false');
    if(open) el('newEmail').focus();
  });
  el('emailCancel').addEventListener('click', () => {
    emailForm.hidden = true;
    el('emailToggle').setAttribute('aria-expanded', 'false');
    el('emailPassword').value = '';
  });

  emailForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = el('emailMsg');
    msg.className = 'msg';
    msg.textContent = '';
    const email = el('newEmail').value.trim();
    const pass = el('emailPassword').value;
    if(!email || !pass){
      msg.className = 'msg err';
      msg.textContent = 'Podaj nowy adres i obecne hasło.';
      return;
    }
    el('emailSubmit').disabled = true;
    el('emailSubmit').textContent = 'Zapisywanie…';
    try{
      const res = await fetch('auth-api.php?action=email_change', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ email, current_password: pass, csrf: csrfToken })
      });
      const data = await res.json();
      if(!res.ok || !data.ok){
        msg.className = 'msg err';
        msg.textContent = data.message || 'Nie udało się zmienić adresu.';
        return;
      }
      msg.className = 'msg ok';
      msg.textContent = data.unchanged
        ? 'To jest już Twój adres e-mail.'
        : 'Adres e-mail zapisany.';
      renderEmail(data.email || email);
      el('emailPassword').value = '';
      emailForm.hidden = true;
      el('emailToggle').setAttribute('aria-expanded', 'false');
    }catch(_){
      msg.className = 'msg err';
      msg.textContent = 'Błąd połączenia z serwerem.';
    }finally{
      el('emailSubmit').disabled = false;
      el('emailSubmit').textContent = 'Zapisz nowy adres';
    }
  });

  // ============================================================
  // ZGŁASZANIE ZDJĘCIA — okno modalne: plik → czytanie danych → dane do wysłania
  // ============================================================
  const LIMITS = <?= json_encode($uploadLimits, $jsonFlags) ?>;
  const IMAGE_EXT = LIMITS.imageExt.map(x => x.toLowerCase());
  const CONVERT_EXT = ['heic', 'heif', 'avif', 'tif', 'tiff', 'bmp'];
  // Formaty filmowe, które przeglądarka może zdekodować i zamienić na klatkę JPG.
  // Nieobsługiwane (np. HEVC w Chrome na Windowsie) łapie się w konwersji
  // i pokazuje komunikat, a nie cicho odrzuca.
  const VIDEO_EXT = ['mov', 'mp4', 'm4v', 'webm', '3gp', 'mts', 'm2ts', 'avi', 'mkv'];

  const modal     = el('uploadModal');
  const modalBox  = el('uploadModalBox');
  const dropZone  = el('dropZone');
  const fileInput = el('fileInput');
  const submitBtn = el('subSubmit');

  const STEP = { pick:'stepPick', scan:'stepScan', form:'stepForm', done:'stepDone' };
  const STEP_TEXT = {
    pick: 'Trzy kroki: wybierz plik, poczekaj na dane, uzupełnij szczegóły.',
    scan: 'Zaglądamy do pliku — za chwilę pokażemy, co udało się z niego wyczytać.',
    form: 'Uzupełnij szczegóły i wyślij zdjęcie do administratora.',
    done: 'Zdjęcie jest już w kolejce do moderacji.'
  };

  let scanRun = 0;   // żeby anulowanie nie dało się "odkopać" przez spóźniony fetch
  let convertRun = 0; // to samo dla konwersji pliku na JPG w przeglądarce
  let thumbUrl = null;
  let thumbRun = 0;
  let lastFocus = null;

  async function setThumb(file){
    const run = ++thumbRun;
    if(thumbUrl){ URL.revokeObjectURL(thumbUrl); thumbUrl = null; }
    const img = el('upThumb');
    if(!file){ img.removeAttribute('src'); return; }

    if(CONVERT_EXT.indexOf(extOf(file.name)) !== -1){
      const fd = new FormData();
      fd.append('file', file, file.name);
      fd.append('csrf', csrfToken);
      try{
        const res = await fetch('submit-photo-api.php?action=preview', {
          method: 'POST', credentials: 'include', body: fd
        });
        if(!res.ok) throw new Error('preview_failed');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        if(run !== thumbRun){ URL.revokeObjectURL(url); return; }
        thumbUrl = url;
        img.src = url;
        return blob;
      }catch(_){
        if(run !== thumbRun) return;
      }
    }

    thumbUrl = URL.createObjectURL(file);
    img.src = thumbUrl;
    return null;
  }

  function extOf(name){
    const m = /\.([a-z0-9]+)$/i.exec(String(name || ''));
    return m ? m[1].toLowerCase() : '';
  }

  function humanBytes(n){
    const b = Number(n) || 0;
    if(b < 1024) return b + ' B';
    if(b < 1048576) return (b / 1024).toFixed(b < 10240 ? 1 : 0) + ' kB';
    return (b / 1048576).toFixed(b < 10485760 ? 1 : 0) + ' MB';
  }

  /* ---------- stany przycisku: oczekiwanie → wysyłanie → sukces / błąd ---------- */
  const BTN = { idle:'idle', busy:'busy', done:'done', error:'error' };

  function setButton(state, text){
    submitBtn.classList.remove('is-busy', 'is-done', 'is-error');
    if(state === BTN.busy){
      const loader = document.createElement('span');
      loader.className = 'upload-loader';
      loader.setAttribute('aria-hidden', 'true');
      submitBtn.replaceChildren(loader, document.createTextNode(text));
      submitBtn.classList.add('is-busy');
      submitBtn.disabled = true;
      return;
    }
    if(state === BTN.done){ submitBtn.textContent = '✓ ' + text; submitBtn.classList.add('is-done'); }
    else if(state === BTN.error){ submitBtn.textContent = text; submitBtn.classList.add('is-error'); }
    else { submitBtn.textContent = text; }
    submitBtn.disabled = false;
  }

  function setProgress(percent, flag, label){
    // Postęp jest sygnalizowany bezpośrednio w przycisku wysyłania.
  }

  function clearMsg(){ const m = el('subMsg'); m.className = 'msg'; m.textContent = ''; }

  function subMsg(text, ok){
    const m = el('subMsg');
    m.className = 'msg ' + (ok ? 'ok' : 'err');
    m.textContent = text;
    // komunikat siedzi nad krokami — przy błędzie wysyłki trzeba go podnieść na widok
    if(!ok && m.scrollIntoView){ m.scrollIntoView({ block:'nearest' }); }
  }

  /* ---------- okno: kroki, otwieranie, zamykanie ---------- */
  function showStep(name){
    Object.keys(STEP).forEach(k => el(STEP[k]).classList.toggle('on', k === name));
    const sub = el('uploadModalSub');
    if(sub) sub.textContent = STEP_TEXT[name] || '';
    if(modalBox) modalBox.scrollTop = 0;
  }

  function openUpload(){
    lastFocus = document.activeElement;
    resetUpload();
    modal.classList.add('open');
    document.documentElement.style.overflow = 'hidden';
    if(modalBox){ modalBox.focus(); }
  }

  function closeUpload(){
    modal.classList.remove('open');
    document.documentElement.style.overflow = '';
    scanRun++;   // anulujemy ewentualny scan w toku
    convertRun++; // i ewentualną konwersję pliku na JPG
    if(lastFocus && lastFocus.focus) lastFocus.focus();
  }

  // keepText = true przy ponownym wyborze pliku, żeby nie przepadał tytuł i opis
  function resetUpload(keepText){
    selectedFiles = [];
    fileInput.value = '';
    exifCoords = null;
    placeOverrideOpen = false;
    el('upProposalBox').hidden = true;
    el('upProposalBody').textContent = '—';
    el('upPlaceField').hidden = true;
    el('subPlaceHint').textContent = PLACE_HINT_NONE;
    el('dzFiles').textContent = '';
    setThumb(null);
    el('upName').textContent = '—';
    el('upMeta').textContent = '—';
    if(!keepText) el('subForm').reset();
    updateGeoPreview._sig = '';
    setProgress(-1);
    clearMsg();
    setButton(BTN.idle, 'wyślij do moderacji');
    showStep('pick');
  }

  el('openUploadBtn').addEventListener('click', openUpload);
  ['upCancel1', 'upCancel2', 'upCancel3', 'upClose'].forEach(id => {
    const b = el(id);
    if(b) b.addEventListener('click', closeUpload);
  });
  el('upAgain').addEventListener('click', () => resetUpload(false));
  el('upSwap').addEventListener('click', () => resetUpload(true));
  modal.addEventListener('mousedown', (e) => { if(e.target === modal) closeUpload(); });

  // Otwarcie okna prosto z linku — np. „dodaj zdjęcie” na stronie głównej.
  // Sam hash w adresie, bez zapytania, więc link można bezpiecznie wkleić
  // gdziekolwiek. Panel i tak pilnuje zalogowania (inaczej odsyła na stronę
  // główną), więc nikt nie zobaczy pustego okna.
  if (location.hash === '#dodaj-zdjecie'){
    // jedna klatka oddechu: inaczej modal wskakuje, zanim zdąży się wyrysować
    requestAnimationFrame(() => openUpload());
  }
  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape' && modal.classList.contains('open')) closeUpload();
  });

  /* ---------- wybór plików ---------- */
  dropZone.addEventListener('click', (e) => {
    if(e.target === fileInput) return;   // bez tego klik w input zapętla click()
    fileInput.click();
  });
  ['dragover','dragenter'].forEach(ev => dropZone.addEventListener(ev, (e) => { e.preventDefault(); dropZone.classList.add('drag-over'); }));
  ['dragleave','dragend','drop'].forEach(ev => dropZone.addEventListener(ev, () => dropZone.classList.remove('drag-over')));
  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    if(e.dataTransfer.files && e.dataTransfer.files.length){ setFiles(e.dataTransfer.files); }
  });
  fileInput.addEventListener('change', () => setFiles(fileInput.files));

  function plural(n, one, few, many){
    if(n === 1) return one;
    const last = n % 10, lastTwo = n % 100;
    if(last >= 2 && last <= 4 && !(lastTwo >= 12 && lastTwo <= 14)) return few;
    return many;
  }

  // Czy plik wymaga zamiany na JPG w przeglądarce? JPG idzie bez zmian,
  // HEIC/AVIF/TIF/BMP przetwarza serwer (Imagick) — resztę robimy tutaj.
  function needsJpegConvert(file){
    const ext = extOf(file.name);
    return ext !== 'jpg' && ext !== 'jpeg' && CONVERT_EXT.indexOf(ext) === -1;
  }

  // Zamiana pliku na JPG w przeglądarce: filmy dają jedną klatkę, animacje
  // GIF/WebP — statyczny obraz, PNG/WebP — JPG na białym tle. Nic poza JPG
  // (i formatami obsługiwanymi przez serwer) nie wychodzi z przeglądarki,
  // więc backend dostaje zawsze poprawny obraz i nie trzeba zmieniać API.
  async function fileToJpeg(file){
    if(!needsJpegConvert(file)) return file;

    const ext = extOf(file.name);
    const isVideo = (file.type || '').toLowerCase().indexOf('video/') === 0
      || VIDEO_EXT.indexOf(ext) !== -1;
    const url = URL.createObjectURL(file);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    try{
      if(isVideo){
        const video = document.createElement('video');
        video.preload = 'auto';
        video.muted = true;
        video.playsInline = true;
        video.src = url;
        await new Promise((resolve, reject) => {
          const timer = setTimeout(() => reject(new Error('video_timeout')), 30000);
          video.onloadeddata = () => { clearTimeout(timer); resolve(); };
          video.onerror = () => { clearTimeout(timer); reject(new Error('video_decode')); };
        });
        // Klatka zerowa bywa czarna — przesuwamy minimalnie do przodu.
        // Seek może nie dojść (np. awaria dekodera) — wtedy rysujemy co mamy.
        const seek = Math.min(0.1,
          (Number.isFinite(video.duration) && video.duration > 0.2) ? video.duration / 2 : 0.1);
        if(seek > 0){
          await new Promise((resolve) => {
            const timer = setTimeout(resolve, 5000);
            video.onseeked = () => { clearTimeout(timer); resolve(); };
            video.onerror = () => { clearTimeout(timer); resolve(); };
            try{ video.currentTime = seek; }catch(_){ clearTimeout(timer); resolve(); }
          });
        }
        const w = video.videoWidth, h = video.videoHeight;
        if(!w || !h) throw new Error('video_dims');
        canvas.width = w;
        canvas.height = h;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(video, 0, 0, w, h);
        video.removeAttribute('src');
        video.load();
      }else{
        const img = new Image();
        await new Promise((resolve, reject) => {
          img.onload = () => resolve();
          img.onerror = () => reject(new Error('image_decode'));
          img.src = url;
        });
        const w = img.naturalWidth, h = img.naturalHeight;
        if(!w || !h) throw new Error('image_dims');
        canvas.width = w;
        canvas.height = h;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0);
      }
      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob((b) => b ? resolve(b) : reject(new Error('to_blob')),
          'image/jpeg', 0.92);
      });
      const base = file.name.replace(/\.[^./\\]+$/, '') || 'zdjecie';
      return new File([blob], base + '.jpg', { type: 'image/jpeg' });
    }finally{
      URL.revokeObjectURL(url);
    }
  }

  async function setFiles(fileList){
    const files = Array.from(fileList || []);
    const bad = [];
    const usable = [];
    files.forEach(f => {
      const type = (f.type || '').toLowerCase();
      if(IMAGE_EXT.indexOf(extOf(f.name)) === -1
        && VIDEO_EXT.indexOf(extOf(f.name)) === -1
        && type.indexOf('image/') !== 0
        && type.indexOf('video/') !== 0){ bad.push(f.name); return; }
      usable.push(f);
    });

    // Jedno zgłoszenie = jedno zdjęcie. Nadmiar zaznaczamy wprost w komunikacie,
    // zamiast po cichu gubić pliki. Limitu rozmiaru nie narzucamy — decyduje php.ini.
    const run = ++convertRun;
    scanRun++;
    selectedFiles = usable.slice(0, 1);

    const notes = [];
    if(bad.length) notes.push('Pominięto — nieobsługiwany format: ' + bad.join(', ') + '.');
    if(usable.length > 1){
      const left = usable.length - 1;
      notes.push('Pominięto ' + left + ' ' + plural(left, 'plik', 'pliki', 'plików')
        + ' — na zgłoszenie przyjmujemy jedno zdjęcie.');
    }

    el('dzFiles').textContent = selectedFiles.map(f => f.name + ' (' + humanBytes(f.size) + ')').join(', ');
    if(notes.length) subMsg(notes.join(' '), false); else clearMsg();
    if(selectedFiles.length === 0) return;

    // Filmy i grafiki spoza JPG/HEIC zamieniamy na JPG, zanim ktokolwiek
    // zobaczy podgląd — reszta ścieżki (skan, wysyłka) działa jak dawniej.
    const original = selectedFiles[0];
    el('scanTitle').textContent = 'Przygotowujemy zdjęcie…';
    el('scanFile').textContent = original.name + ' · ' + humanBytes(original.size);
    Array.prototype.forEach.call(el('scanSteps').querySelectorAll('li'), (step) => {
      step.className = step.dataset.step === 'prepare' ? 'on' : '';
    });
    showStep('scan');
    await new Promise(resolve => requestAnimationFrame(resolve));
    if(run !== convertRun) return;

    if(needsJpegConvert(original)){
      el('dzFiles').textContent = original.name + ' → przetwarzamy na JPG…';
      try{
        const converted = await fileToJpeg(original);
        if(run !== convertRun) return;
        selectedFiles = [converted];
        el('dzFiles').textContent = original.name + ' → ' + converted.name
          + ' (' + humanBytes(converted.size) + ')';
      }catch(_){
        if(run !== convertRun) return;
        selectedFiles = [];
        fileInput.value = '';
        el('dzFiles').textContent = '';
        subMsg('Nie udało się przetworzyć pliku „' + original.name + '" na JPG w tej przeglądarce. '
          + 'Filmy z iPhone’a (HEVC) spróbuj otworzyć w Safari albo zapisz plik wcześniej jako JPG.', false);
        showStep('pick');
        return;
      }
    }
    scanFile(selectedFiles[0]);
  }

  /* ---------- krok 2: czytanie danych ze zdjęcia ---------- */
  async function scanFile(file){
    const run = ++scanRun;
    const started = Date.now();

    // Krok przetwarzania jest widoczny od wyboru pliku, także podczas tworzenia podglądu.
    el('scanTitle').textContent = 'Przygotowujemy podgląd zdjęcia…';
    el('scanFile').textContent = file.name + ' · ' + humanBytes(file.size);
    showStep('scan');
    const previewBlob = await setThumb(file);
    if(run !== scanRun) return;
    el('upName').textContent = file.name;
    el('upMeta').textContent = humanBytes(file.size);

    const marks = {};
    Array.prototype.forEach.call(el('scanSteps').querySelectorAll('li'), (n) => { marks[n.dataset.step] = n; });
    const mark = (key, cls) => { if(marks[key]) marks[key].className = cls || ''; };

    mark('prepare', 'ok');
    el('scanTitle').textContent = 'Odczytujemy dane ze zdjęcia…';
    mark('exif', 'on');

    // GPS z EXIF tylko do podglądu gminy — serwer odczyta go ponownie przy wysyłce.
    exifCoords = await readExifGps(file);
    if(!exifCoords && previewBlob){
      const previewFile = new File([previewBlob], 'preview.jpg', { type:'image/jpeg' });
      exifCoords = await readExifGps(previewFile);
    }
    if(run !== scanRun) return;
    const hasGps = !!(exifCoords && Number.isFinite(exifCoords.lat) && Number.isFinite(exifCoords.lng));
    // GPS z pliku jednoznacznie wskazuje miejsce — nie pytamy wtedy o nazwę
    // miejscowości, bo i tak mamy ją w geolokalizacji. Chyba że użytkownik
    // wcześniej otworzył pole przyciskiem „zmień”.
    el('upPlaceField').hidden = hasGps && !placeOverrideOpen;
    mark('exif', 'ok');
    mark('gps', hasGps ? 'ok' : 'skip');

    mark('geo', 'on');
    // Kolejność jak na serwerze: GPS z EXIF, a gdy go nie ma — nazwa
    // miejscowości wpisana wcześniej (formularz nie bywa pusty przy zmianie pliku).
    const name = el('subPlaceName').value.trim();
    const payload = hasGps
      ? { lat: exifCoords.lat, lng: exifCoords.lng }
      : (name ? { place: name } : null);
    const geo = await locate(payload);
    if(run !== scanRun) return;
    const status = geo && geo.status ? geo.status : 'none';
    mark('geo', ['failed', 'none', 'unknown_place'].indexOf(status) === -1 ? 'ok' : 'skip');
    mark('cat', status === 'matched' && geo.category_id ? 'ok' : 'skip');

    // Animacja musi być widoczna — przy małym pliku kroki przeskoczyłyby bez słowa.
    const rest = 1100 - (Date.now() - started);
    if(rest > 0) await new Promise((r) => setTimeout(r, rest));
    if(run !== scanRun) return;

    applyGeoResult(geo, status);
    showStep('form');
  }

  async function locate(payload){
    if(!payload) return null;
    try{
      const res = await fetch('geo-locate-api.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ csrf: csrfToken }, payload))
      });
      const d = await res.json();
      if(!d || d.ok === false) return null;
      return d;
    }catch(_){
      return null;
    }
  }

  /* ---------- kategoria ---------- */
  // Wyboru kategorii nie ma: nazwę miejscowości rozpoznaje system, a brakującą
  // kategorię proponuje automatycznie (admin ją zatwierdza). Wysyłka nie
  // przesyła więc category_id ani category_auto — serwer sam ustala kategorię
  // z lokalizacji i nazwy miejscowości.

  /* ---------- pole miejscowości ---------- */
  // UWAGA: to pole jest PODPOWIEDZĄ, nie warunkiem wysyłki. Nazwa jest
  // potrzebna tylko wtedy, gdy plik nie ma GPS-a — ale panel nie potrafi
  // tego sprawdzić wiarygodnie: readExifGps czyta EXIF wyłącznie z JPEG
  // (świadomie pomija HEIC/PNG), więc plik z GPS-em w takim formacie
  // wygląda w przeglądarce jak bez lokalizacji. Serwer czyta GPS z każdego
  // formatu i sam odrzuci zgłoszenie bez lokalizacji, więc blokowanie
  // wysyłki w panelu tylko psuło zdjęcia z działającym EXIF-em.

  function placeNameValue(){
    const input = el('subPlaceName');
    return input ? input.value.trim() : '';
  }

  function hasGpsCoords(){
    return !!(exifCoords && Number.isFinite(exifCoords.lat) && Number.isFinite(exifCoords.lng));
  }

  /* ---------- rozpoznanie gminy (podgląd przed wysłaniem) ---------- */
  let exifCoords = null;

  // Minimalny czytnik GPS z EXIF pliku JPEG (segment APP1/Exif). HEIC/PNG
  // pomijamy — serwer i tak odczyta lokalizację z pliku przy wysyłce.
  function readExifGps(file){
    return new Promise(resolve => {
      if(!file){ resolve(null); return; }
      const fr = new FileReader();
      fr.onerror = () => resolve(null);
      fr.onload = () => {
        try{
          const dv = new DataView(fr.result);
          if(dv.byteLength < 4 || dv.getUint16(0, false) !== 0xFFD8){ resolve(null); return; }
          let off = 2;
          while(off + 4 <= dv.byteLength){
            if(dv.getUint8(off) !== 0xFF){ off++; continue; }
            const marker = dv.getUint8(off + 1);
            if(marker === 0xDA || marker === 0xD9) break;
            if(marker === 0x00 || (marker >= 0xD0 && marker <= 0xD7)){ off += 2; continue; }
            const size = dv.getUint16(off + 2, false);
            if(size < 2) break;
            const seg = off + 4;
            if(marker === 0xE1 && dv.getUint32(seg, false) === 0x45786966){
              resolve(parseExifTiff(dv, seg + 6));
              return;
            }
            off = seg + size - 2;
          }
          resolve(null);
        }catch(_){ resolve(null); }
      };
      fr.readAsArrayBuffer(file);
    });
  }

  function parseExifTiff(dv, tiffStart){
    const le = dv.getUint16(tiffStart, false) === 0x4949;
    const u16 = o => dv.getUint16(o, le);
    const u32 = o => dv.getUint32(o, le);
    if(u16(tiffStart + 2) !== 0x002A) return null;
    const typeSize = t => ({1:1, 2:1, 3:2, 4:4, 5:8, 7:1, 9:4, 10:8}[t] || 1);
    function entries(ifd){
      const n = u16(ifd), map = {}, base = ifd + 2;
      for(let i = 0; i < n; i++){
        const e = base + i * 12;
        map[u16(e)] = { type: u16(e + 2), count: u32(e + 4), valOff: e + 8 };
      }
      return map;
    }
    const dataOff = e => (e.count * typeSize(e.type) > 4) ? (tiffStart + u32(e.valOff)) : e.valOff;
    function ascii(e){
      let o = dataOff(e), s = '';
      for(let i = 0; i < e.count; i++){ const c = dv.getUint8(o + i); if(c === 0) break; s += String.fromCharCode(c); }
      return s;
    }
    function dms(e){
      if(e.count < 3) return null;
      const o = dataOff(e);
      const num = i => u32(o + i * 8), den = i => u32(o + i * 8 + 4);
      return (den(0) ? num(0) / den(0) : 0) + (den(1) ? num(1) / den(1) : 0) / 60 + (den(2) ? num(2) / den(2) : 0) / 3600;
    }
    const ifd0 = tiffStart + u32(tiffStart + 4);
    const ifd0Entries = entries(ifd0);
    const dateTag = ifd0Entries[0x9003] || ifd0Entries[0x0132];
    let takenAt = '';
    if(dateTag && dateTag.type === 2){
      const rawDate = ascii(dateTag);
      const match = /^(\d{4}):(\d{2}):(\d{2})/.exec(rawDate);
      if(match) takenAt = match[1] + '-' + match[2] + '-' + match[3];
    }
    const gpsTag = ifd0Entries[0x8825];
    if(!gpsTag) return takenAt ? { takenAt } : null;
    const g = entries(tiffStart + u32(gpsTag.valOff));
    if(!g[0x0002] || !g[0x0004]) return takenAt ? { takenAt } : null;
    let lat = dms(g[0x0002]), lng = dms(g[0x0004]);
    if(lat == null || lng == null) return takenAt ? { takenAt } : null;
    if(g[0x0001] && ascii(g[0x0001]) === 'S') lat = -lat;
    if(g[0x0003] && ascii(g[0x0003]) === 'W') lng = -lng;
    if(!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0)) return takenAt ? { takenAt } : null;
    if(lat < -90 || lat > 90 || lng < -180 || lng > 180) return takenAt ? { takenAt } : null;
    return { lat, lng, takenAt };
  }

  // „Gmina Mogilno → Łosośniki” z opisu brakującej kategorii (need).
  function proposalLabel(need){
    if(!need || !need.child_name) return '';
    return (need.parent_name ? need.parent_name + ' → ' : '') + need.child_name;
  }

  const geoStatusText = {
    matched:      d => `Rozpoznano gminę: ${d.gmina} — kategoria ustawiona automatycznie.`,
    proposed:     d => `Rozpoznano gminę: ${d.gmina}. Kategorii jeszcze nie ma — propozycja trafi do moderacji administratora.`,
    out_of_range: d => `Rozpoznano gminę: ${d.gmina} — poza okolicą Mogilna, więc kategorii nie zaproponujemy.`,
    failed:       () => 'Miejsce poza zasięgiem znanych adresów — gminę wskaże administrator.',
    unknown_place: d => `Nie znaleźliśmy miejscowości „${d.miejscowosc}” — popraw pisownię, a rozpoznamy kategorię.`,
    none:         () => 'Nie znamy miejsca — wpisz nazwę miejscowości.'
  };

  /* ---------- krok 3: pokazujemy wynik — zależnie, czy lokalizacja jest czy nie ---------- */
  function applyGeoResult(d, status){
    const box   = el('upGeoBox');
    const title = el('upGeoTitle');
    const body  = el('upGeoBody');

    const st = status || (d && d.status) || 'none';
    const gmina = (d && d.gmina) ? d.gmina : '';
    // Przybliżony adres: „ul. Wolności 2, Mogilno, 88-300” — ulica i numer
    // pochodzą z punktu adresowego PRG, gdy jest w zasięgu.
    const ulicaNr = d ? [d.ulica, d.nr].filter(Boolean).join(' ') : '';
    const adres = d ? [ulicaNr, d.miejscowosc, d.kod_pocztowy].filter(Boolean).join(', ').replace(', , ', ', ') : '';
    const okol = d ? [d.powiat, d.wojewodztwo].filter(Boolean).join(', ') : '';
    box.className = 'geo-box';

    // Propozycja kategorii (gmina → miejscowość) — z EXIF albo z nazwy
    // miejscowości wpisanej ręcznie. Nie ustawiamy pola „Kategoria”: to propozycja
    // do moderacji, a nie świadomy wybór użytkownika.
    const need = (d && d.need) || null;
    const propLabel = proposalLabel(need);
    const propBox = el('upProposalBox');
    const propBody = el('upProposalBody');
    if(propLabel){
      propBody.textContent = propLabel;
      propBox.className = 'geo-box prop-box warn';
      propBox.hidden = false;
    } else {
      propBox.hidden = true;
    }

    if(st === 'matched'){
      box.classList.add(need ? 'warn' : 'ok');
      title.textContent = d && d.source_hint === 'name' ? 'Rozpoznaliśmy miejscowość' : 'Lokalizacja znaleziona w pliku';
      body.innerHTML = 'Gmina: <b>' + esc(gmina) + '</b>'
        + (adres ? '<br>' + esc(adres) : '')
        + (okol ? '<br>' + esc(okol) : '')
        + (d && d.category_name ? '<br>Kategoria: <b>' + esc(d.category_name) + '</b>' : '');
    } else if(st === 'proposed'){
      box.classList.add('warn');
      title.textContent = 'Nowa gmina — czeka na zatwierdzenie admina';
      body.textContent = 'Gmina: ' + gmina + '. Tej kategorii jeszcze u nas nie ma — propozycja trafi do moderacji administratora.';
    } else if(st === 'out_of_range'){
      box.classList.add('warn');
      title.textContent = 'Lokalizacja poza okolicą Mogilna';
      body.textContent = 'Gmina: ' + gmina
        + (d.distance_km_mogilno !== null && d.distance_km_mogilno !== undefined
            ? ' — ' + Math.round(d.distance_km_mogilno) + ' km od Mogilna.' : '.')
        + ' Zdjęcie dodamy bez kategorii, a moderator zdecyduje, czy pasuje do galerii.';
    } else if(st === 'unknown_place'){
      box.classList.add('none');
      title.textContent = 'Nie znaleźliśmy takiej miejscowości';
      body.textContent = 'Nazwy „' + ((d && d.miejscowosc) || '')
        + '” nie ma w bazie adresowej. Popraw pisownię — rozpoznamy gminę i zaproponujemy kategorię.';
    } else if(st === 'failed'){
      box.classList.add('none');
      title.textContent = 'Lokalizacji nie udało się ustalić';
      body.textContent = 'Adresu nie znaleźliśmy w bazie. Wpisz nazwę miejscowości poniżej, a policzymy gminę sami.';
    } else {
      box.classList.add('none');
      title.textContent = 'Nie znamy miejsca — wpisz miejscowość';
      body.textContent = exifCoords
        ? 'Współrzędne z pliku są, ale nie wskazują miejsca. Wpisz nazwę miejscowości poniżej.'
        : 'Ten plik nie ma współrzędnych GPS. Wpisz nazwę miejscowości poniżej, a policzymy gminę sami.';
    }

    // Pole miejscowości pokazujemy tylko wtedy, gdy GPS z pliku nic nie dał
    // w geolokalizacji. Wyjątek: użytkownik otworzył je przyciskiem „zmień”
    // przy propozycji kategorii, więc zostawiamy pole otwarte — inaczej
    // zniknęłoby przy pierwszym odświeżeniu podglądu.
    const asksPlace = st === 'none' || st === 'failed' || st === 'unknown_place';
    el('upPlaceField').hidden = !(asksPlace || placeOverrideOpen);
    el('subPlaceHint').textContent = placeOverrideOpen
      ? 'Wpisz nazwę miejscowości — zastąpi rozpoznanie z pliku.'
      : (asksPlace
        ? ((geoStatusText[st] || geoStatusText.none)(d || {}) || PLACE_HINT_NONE)
        : '');
  }

  /* ---------- korekta miejscowości przy propozycji z GPS-u ---------- */
  // Przycisk „zmień” przy propozycji kategorii otwiera pole miejscowości i
  // pozwala poprawić rozpoznanie z pliku („właściwie to Dysiek”). Pole
  // zostaje wtedy otwarte mimo działającego GPS-a, bo użytkownik świadomie
  // chce je zmienić.
  let placeOverrideOpen = false;

  function openPlaceOverride(){
    placeOverrideOpen = true;
    el('upPlaceField').hidden = false;
    el('subPlaceHint').textContent = 'Wpisz nazwę miejscowości — zastąpi rozpoznanie z pliku.';
    const input = el('subPlaceName');
    if(input && input.focus) input.focus();
    // Podgląd musi odświeżyć się natychmiast, inaczej zostanie stary
    // wynik z GPS-a (podpisany w updateGeoPreview._sig).
    updateGeoPreview._sig = '';
    if(placeNameValue()) updateGeoPreview();
  }

  el('upProposalEdit').addEventListener('click', openPlaceOverride);

  /* ---------- nazwa miejscowości wpisana ręcznie: rozpoznajemy gminę na żywo ---------- */
  let geoPreviewToken = 0;
  updateGeoPreview._sig = '';
  function updateGeoPreview(){
    const name = el('subPlaceName').value.trim();
    const hasGps = !!(exifCoords && Number.isFinite(exifCoords.lat) && Number.isFinite(exifCoords.lng));

    // Wpisana nazwa ma pierwszeństwo — pozwala poprawić rozpoznanie z pliku.
    let payload = null, sig = '';
    if(name.length >= 3){ payload = { place: name }; sig = 'name:' + name; }
    else if(hasGps){ payload = { lat: exifCoords.lat, lng: exifCoords.lng }; sig = 'exif:' + exifCoords.lat + ',' + exifCoords.lng; }

    if(sig === updateGeoPreview._sig) return;
    updateGeoPreview._sig = sig;
    const token = ++geoPreviewToken;

    if(!payload){ applyGeoResult(null, 'none'); return; }
    locate(payload).then((d) => {
      if(token !== geoPreviewToken) return;
      applyGeoResult(d, d && d.status);
    });
  }

  let geoTimer = null;
  el('subPlaceName').addEventListener('input', () => {
    clearTimeout(geoTimer);
    geoTimer = setTimeout(updateGeoPreview, 650);
  });

  /* ---------- podpowiedzi lokalizacji (Nominatim, <25 km od Mogilna) ---------- */
  let suggestTimer = null;
  let suggestToken = 0;
  async function fetchSuggestions(q){
    if(!q || q.length < 2) return null;
    const token = ++suggestToken;
    const clat = el('subPlaceCtxLat') ? el('subPlaceCtxLat').value.trim() : '';
    const clng = el('subPlaceCtxLng') ? el('subPlaceCtxLng').value.trim() : '';
    try{
      const body = { q: q, csrf: csrfToken };
      if(clat && clng){ body.clat = clat; body.clng = clng; }
      const res = await fetch('geo-suggest-api.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      if(token !== suggestToken) return null;
      if(!d || d.ok === false) return [];
      return d.candidates || [];
    }catch(_){
      return [];
    }
  }
  function renderSuggestions(list, boxId = 'upPlaceSuggest', coordsId = 'subCoordinates', applyName = true){
    const box = el(boxId);
    if(!box) return;
    if(!list || !list.length){ box.hidden = true; box.innerHTML = ''; return; }
    const ctxM = el('subPlaceCtxMiejsc') ? el('subPlaceCtxMiejsc').value.trim().toLowerCase() : '';
    const ctxG = el('subPlaceCtxGmina') ? el('subPlaceCtxGmina').value.trim().toLowerCase() : '';
    let items = list.slice();
    if(!applyName){
      // don't show the village/town/city itself in object suggestions
      items = items.filter(it => {
        const t = (it.type || '').toLowerCase();
        const cat = (it.category || '').toLowerCase();
        if(t === 'village' || t === 'town' || t === 'city' || t === 'hamlet') return false;
        if(cat === 'place') {
          // if it's just the place entry matching context exactly, skip
          const im = (it.miejscowosc||'').toLowerCase();
          const nm = (it.name||'').toLowerCase();
          if(ctxM && (im === ctxM && nm === ctxM)) return false;
          if(ctxG && im === ctxM && !nm.includes('most') && !nm.includes('bridge') && !nm.includes('wiadukt') && !nm.includes('kładka')) {
            // be more strict - only show if looks like object
          }
        }
        return true;
      });
      if(ctxM || ctxG){
        items = items.filter(it => {
          const im = (it.miejscowosc||'').toLowerCase();
          const ig = (it.gmina||'').toLowerCase();
          if(ctxM && im === ctxM) return true;
          if(ctxG && ig === ctxG) return true;
          return false;
        });
      }
    }
    if(!items.length){ box.hidden = true; box.innerHTML = ''; return; }
    const html = items.map((it, i) => {
      const dist = (it.distance_km_mogilno !== undefined && it.distance_km_mogilno !== null)
        ? (Math.round(it.distance_km_mogilno * 100) / 100) : '';
      const label = it.label || it.name || '';
      const gmap = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(label + ', Polska');
      return '<button type="button" class="sg-item" data-idx="' + i + '">'
        + '<div class="sg-title">' + esc(label) + '</div>'
        + '<div class="sg-sub">' + (dist ? dist + ' km od Mogilna · ' : '') + '<a href="' + esc(gmap) + '" target="_blank" rel="noopener">zobacz w Google</a></div>'
        + '</button>';
    }).join('');
    box.innerHTML = html;
    box.hidden = false;
    Array.from(box.querySelectorAll('.sg-item')).forEach((btn, i) => {
      btn.addEventListener('click', () => chooseSuggestion(items[i], boxId, coordsId, applyName));
    });
  }
  function chooseSuggestion(it, boxId = 'upPlaceSuggest', coordsId = 'subCoordinates', applyName = true){
    const box = el(boxId);
    if(box){ box.hidden = true; box.innerHTML = ''; }
    const nameInput = el(applyName ? 'subPlaceName' : 'subTitle');
    const coordsInput = el(coordsId);
    if(applyName && nameInput) nameInput.value = it.label || it.name || it.display || '';
    if(!applyName && nameInput) nameInput.value = it.label || it.name || it.display || '';
    if(coordsInput && Number.isFinite(it.lat) && Number.isFinite(it.lng)){
      coordsInput.value = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(it.lat + ',' + it.lng);
    }
    if(applyName){
      const cM = el('subPlaceCtxMiejsc'); if(cM) cM.value = it.miejscowosc || it.name || '';
      const cG = el('subPlaceCtxGmina'); if(cG) cG.value = it.gmina || '';
      const cL = el('subPlaceCtxLat'); if(cL) cL.value = (Number.isFinite(it.lat)?it.lat:'');
      const cN = el('subPlaceCtxLng'); if(cN) cN.value = (Number.isFinite(it.lng)?it.lng:'');
      updateGeoPreview._sig = '';
      placeOverrideOpen = true;
      el('upPlaceField').hidden = false;
    }
    if(nameInput && nameInput.focus) nameInput.focus();
    if(applyName) updateGeoPreview();
  }
  el('subPlaceName').addEventListener('input', () => {
    const q = el('subPlaceName').value.trim();
    clearTimeout(suggestTimer);
    const coordsInput = el('subCoordinates');
    if(coordsInput) coordsInput.value = '';
    const cM = el('subPlaceCtxMiejsc'); if(cM) cM.value = '';
    const cG = el('subPlaceCtxGmina'); if(cG) cG.value = '';
    const cL = el('subPlaceCtxLat'); if(cL) cL.value = '';
    const cN = el('subPlaceCtxLng'); if(cN) cN.value = '';
    const box = el('upPlaceSuggest');
    if(q.length < 3){ if(box){ box.hidden = true; box.innerHTML = ''; } return; }
    suggestTimer = setTimeout(async () => {
      const list = await fetchSuggestions(q);
      renderSuggestions(list, 'upPlaceSuggest', 'subCoordinates', true);
    }, 450);
  });

  let descSuggestTimer = null;
  el('subTitle').addEventListener('input', () => {
    // podpowiedzi dla opisu miejsca wyłączone
  });

  /* ---------- lista własnych zgłoszeń ---------- */
  function formatWhen(iso){
    if(!iso) return '';
    const d = new Date(String(iso).replace(' ', 'T'));
    if(isNaN(d)) return '';
    return d.toLocaleString('pl-PL', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
  }
  function statusOf(it){
    if(it.published) return { label:'na stronie', cls:'approved' };
    if(it.publish_at) return { label:'zaplanowane ' + formatWhen(it.publish_at), cls:'pending' };
    if(it.status === 'rejected') return { label:'odrzucone', cls:'rejected' };
    if(it.status === 'approved') return { label:'zatwierdzone', cls:'approved' };
    return { label:'oczekuje', cls:'pending' };
  }
  function canManageSubmission(it){
    return !it.published ? (it.status === 'pending' || it.status === 'approved')
      : it.status === 'approved';
  }

  // Stan wniosku o kategorię jest osobną informacją — zdjęcie może czekać
  // w moderacji, a administrator w międzyczasie rozpatrzy zgłoszoną nazwę.
  function requestOf(it){
    const r = it.request;
    if(!r) return null;
    const label = r.parent_name ? r.parent_name + ' → ' + r.child_name : r.child_name;
    if(r.status === 'approved') return { label: 'kategoria dodana: ' + label, cls: 'approved' };
    if(r.status === 'rejected') return { label: 'propozycja odrzucona: ' + label, cls: 'rejected' };
    return { label: 'wniosek o kategorię: ' + label, cls: 'pending' };
  }

  const MINE_STEP = 6;
  let mineOffset = 0;
  let mineHasMore = false;
  let mineBusy = false;
  const mineItems = new Map();

  // Jeden wiersz listy. Zdjęcia już opublikowane są klikalne (link do galerii),
  // a obok pokazujemy statystyki (wyświetlenia / polubienia / komentarze / ulubione).
  function subItemHtml(it){
    mineItems.set(Number(it.id), it);
    const st = statusOf(it);
    const rq = requestOf(it);
    const catLine = it.category_name ? it.category_name : '';
    const published = !!it.published;
    const href = published ? `index.php?photo=p${it.id}` : null;
    const title = it.title || 'bez tytułu';
    const stats = it.stats || {};
    const canManage = canManageSubmission(it);
    const manageHtml = canManage ? `
      <div class="si-actions">
        <button type="button" class="si-action" data-sub-action="edit" data-id="${it.id}">Zmień opis</button>
        <button type="button" class="si-action danger" data-sub-action="delete" data-id="${it.id}">${it.published ? 'Usuń zdjęcie' : 'Usuń zgłoszenie'}</button>
      </div>` : '';
    const statsRow = published ? `
      <div class="si-stats">
        <span class="si-stat" title="Wyświetlenia"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>${stats.views || 0}</span>
        <span class="si-stat" title="Polubienia"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>${stats.likes || 0}</span>
        <span class="si-stat" title="Komentarze"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/></svg>${stats.comments || 0}</span>
        <span class="si-stat" title="Ulubione"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2z"/></svg>${stats.favorites || 0}</span>
      </div>` : '';
    const thumbInner = href
      ? `<a class="si-thumb" href="${href}" title="Zobacz w galerii"><img src="${esc(it.thumb)}" alt="" loading="lazy"></a>`
      : `<span class="si-thumb"><img src="${esc(it.thumb)}" alt="" loading="lazy"></span>`;
    const titleHtml = href
      ? `<a class="si-title" href="${href}">${esc(title)}</a>`
      : `<div class="si-title">${esc(title)}</div>`;
    return `
      <div class="sub-item" data-id="${it.id}">
        ${thumbInner}
        <div class="si-body">
          ${titleHtml}
          ${it.place ? `<div class="si-meta">${esc(it.place)}</div>` : ''}
          ${catLine ? `<div class="si-meta">Kategoria: ${esc(catLine)}</div>` : ''}
          ${rq ? `<div class="si-meta">${esc(rq.label)}</div>` : ''}
          ${statsRow}
          ${manageHtml}
        </div>
        <span class="pill ${st.cls}">${esc(st.label)}</span>
      </div>`;
  }

  function subMoreHtml(){
    const wrap = el('subMore');
    if(!wrap) return;
    if(!mineHasMore){
      wrap.innerHTML = '';
      return;
    }
    if(wrap.dataset.ready === '1') return;
    wrap.dataset.ready = '1';
    wrap.innerHTML = `<button type="button" class="btn-ghost" id="subMoreBtn">Pokaż więcej</button>`;
    el('subMoreBtn').addEventListener('click', loadMoreMine);
  }

  async function loadMoreMine(){
    if(mineBusy || !mineHasMore) return;
    mineBusy = true;
    const btn = el('subMoreBtn');
    if(btn){ btn.disabled = true; btn.textContent = 'Wczytywanie…'; }
    try{
      const res = await fetch(`submit-photo-api.php?action=list_mine&limit=${MINE_STEP}&offset=${mineOffset}`, { credentials:'include' });
      const data = await res.json();
      const items = data.items || [];
      mineOffset = data.offset ?? (mineOffset + items.length);
      mineHasMore = !!data.has_more;
      if(items.length){
        el('subList').insertAdjacentHTML('beforeend', items.map(subItemHtml).join(''));
      }
      subMoreHtml();
    }catch(_){
      if(btn){ btn.disabled = false; btn.textContent = 'Pokaż więcej'; }
    }finally{
      mineBusy = false;
    }
  }

  async function loadMine(){
    const box = el('subList');
    try{
      mineItems.clear();
      const res = await fetch(`submit-photo-api.php?action=list_mine&limit=${MINE_STEP}&offset=0`, { credentials:'include' });
      const data = await res.json();
      const items = data.items || [];
      if(items.length === 0){
        box.innerHTML = '<p class="note">Nie wysłano jeszcze żadnego zdjęcia.</p>';
        el('subMore') && (el('subMore').innerHTML = '');
        return;
      }
      mineOffset = data.offset ?? items.length;
      mineHasMore = !!data.has_more;
      box.innerHTML = items.map(subItemHtml).join('');
      subMoreHtml();
    }catch(_){
      box.innerHTML = '<p class="note">Nie udało się wczytać listy zgłoszeń.</p>';
    }
  }
  const subEditBackdrop = el('subEditBackdrop');
  let editingSubmissionId = 0;
  let submissionMessageTimer = 0;
  function submissionMessage(node, text, ok){
    clearTimeout(submissionMessageTimer);
    node.className = 'msg' + (text ? (ok ? ' ok' : ' err') : '');
    node.textContent = text;
    if(ok && text){
      submissionMessageTimer = setTimeout(() => {
        node.className = 'msg';
        node.textContent = '';
      }, 4500);
    }
  }
  function closeSubmissionEditor(){
    subEditBackdrop.classList.remove('open');
    editingSubmissionId = 0;
    submissionMessage(el('subEditMsg'), '', false);
  }
  el('subList').addEventListener('click', async e => {
    const btn = e.target.closest('[data-sub-action]');
    if(!btn) return;
    const id = Number(btn.dataset.id), item = mineItems.get(id);
    if(!item || !canManageSubmission(item)) return;
    if(btn.dataset.subAction === 'edit'){
      editingSubmissionId = id;
      el('subEditDescription').value = item.description || '';
      el('subEditCount').textContent = String(el('subEditDescription').value.length);
      submissionMessage(el('subEditMsg'), '', false);
      subEditBackdrop.classList.add('open');
      setTimeout(() => el('subEditDescription').focus(), 30);
      return;
    }
    if(btn.dataset.subAction === 'delete'){
      const isPending = item.status === 'pending' && !item.published;
      btn.disabled = true;
      try{
        const res = await fetch('submit-photo-api.php?action=delete_mine', {
          method:'POST', credentials:'include', headers:{'Content-Type':'application/json'},
          body:JSON.stringify({id,csrf:csrfToken})
        });
        const data = await res.json();
        if(!res.ok || !data.ok){
          const err=new Error(data.message || 'Nie udało się usunąć zgłoszenia.');
          err.retryAfter=Number(data.retry_after||0);
          throw err;
        }
        if(isPending){
          const pending = el('heroPendingStat');
          if(pending) pending.textContent = String(Math.max(0, (parseInt(pending.textContent,10)||0)-1));
        }
        let successText = isPending ? 'Usunięto zgłoszone zdjęcie.' : 'Usunięto zdjęcie.';
        if(data.cooldown_seconds) successText += ` Następne usuwanie za ${data.cooldown_seconds} s.`;
        submissionMessage(el('subActionMsg'), successText, true);
        await loadMine();
      }catch(err){
        const retry = Number(err.retryAfter || 0);
        submissionMessage(el('subActionMsg'), retry > 0
          ? `Kolejne usunięcie będzie możliwe za ${retry} s.`
          : (err.message || 'Błąd połączenia z serwerem.'), false);
        btn.disabled = false;
      }
    }
  });
  el('subEditDescription').addEventListener('input', () => {
    el('subEditCount').textContent = String(el('subEditDescription').value.length);
  });
  el('subEditCancel').addEventListener('click', closeSubmissionEditor);
  subEditBackdrop.addEventListener('click', e => { if(e.target === subEditBackdrop) closeSubmissionEditor(); });
  el('subEditSave').addEventListener('click', async () => {
    if(!editingSubmissionId) return;
    const btn=el('subEditSave');btn.disabled=true;btn.textContent='Zapisywanie…';
    try{
      const res=await fetch('submit-photo-api.php?action=edit_mine',{
        method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({id:editingSubmissionId,description:el('subEditDescription').value.trim(),csrf:csrfToken})
      });
      const data=await res.json();
      if(!res.ok||!data.ok)throw new Error(data.message||'Nie udało się zapisać opisu.');
      submissionMessage(el('subActionMsg'),'Zapisano zmiany w zgłoszeniu.',true);
      closeSubmissionEditor();
      await loadMine();
    }catch(err){
      submissionMessage(el('subEditMsg'),err.message||'Błąd połączenia z serwerem.',false);
    }finally{btn.disabled=false;btn.textContent='Zapisz opis';}
  });
  loadMine();

  /* ---------- zablokowani ---------- */
  let blockedMsgTimer = 0;
  function blockedMsg(text, ok){
    const m = el('blockedMsg');
    m.className = 'msg ' + (ok ? 'ok' : 'err');
    m.textContent = text;
    if(ok){
      clearTimeout(blockedMsgTimer);
      blockedMsgTimer = setTimeout(() => { m.className = 'msg'; m.textContent = ''; }, 4000);
    }
  }
  async function loadBlocked(){
    const box = el('blockedList');
    try{
      const res = await fetch('social-api.php?action=blocked', { credentials:'include' });
      const data = await res.json();
      const items = data.items || [];
      if(items.length === 0){
        box.innerHTML = '<p class="note">Nie blokujesz nikogo.</p>';
        return;
      }
      box.innerHTML = items.map(u => `
        <div class="sub-item" data-id="${esc(u.id)}">
          ${u.avatar
            ? `<img src="${esc(u.avatar)}" alt="" loading="lazy">`
            : '<span class="si-av">' + esc(initialsOf(u.name)) + '</span>'}
          <div>
            <div class="si-title">${esc(u.name)}</div>
            <div class="si-meta">@${esc(u.username)}</div>
          </div>
          <button class="btn-ghost" type="button" data-unblock="${esc(u.id)}" style="margin-left:auto">odblokuj</button>
        </div>`).join('');
    }catch(_){
      box.innerHTML = '<p class="note">Nie udało się wczytać listy zablokowanych.</p>';
    }
  }
  loadBlocked();
  el('blockedList').addEventListener('click', async e => {
    const btn = e.target.closest('[data-unblock]');
    if(!btn) return;
    btn.disabled = true;
    try{
      if(!csrfToken) await whoAmI();
      const res = await fetch('social-api.php', {
        method:'POST',
        credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'block_toggle', user: Number(btn.dataset.unblock), csrf: csrfToken })
      });
      const data = await res.json();
      if(data.ok){
        blockedMsg('Odblokowano.', true);
        loadBlocked();
      } else {
        blockedMsg(data.message || 'Nie udało się odblokować.', false);
        btn.disabled = false;
      }
    }catch(_){
      blockedMsg('Błąd połączenia z serwerem.', false);
      btn.disabled = false;
    }
  });

  /* ---------- wysyłka ---------- */
  el('subForm').addEventListener('submit', (e) => {
    e.preventDefault();
    if(selectedFiles.length === 0) return;

    clearMsg();
    const name = selectedFiles[0].name;

    // Miejscowości nie wymuszamy — patrz komentarz przy placeNameValue(). Serwer
    // sam odrzuci zgłoszenie bez GPS-a i bez nazwy, z czytelnym powodem.

    const fd = new FormData();
    selectedFiles.forEach(f => fd.append('files[]', f, f.name));
    fd.append('description', el('subTitle').value.trim());
    const descCoords = el('subPlaceDescCoords');
    if (descCoords && descCoords.value.trim()) {
      fd.append('place_desc_coords', descCoords.value.trim());
    }
    // Nazwa miejscowości (gdy plik nie ma GPS) — backend rozpozna po niej gminę
    // i sam przygotuje wniosek o brakującą kategorię.
    fd.append('place_name', placeNameValue());
    const coordsInput = el('subCoordinates');
    if (coordsInput && coordsInput.value.trim()) {
      fd.append('coordinates', coordsInput.value.trim());
    }
    // Etykiety atrakcji turystycznych (opcjonalne) — zaznaczone checkboxy
    // w sekcji „Ta fotografia to atrakcja turystyczna?” oraz wspólna notatka.
    // Twardy limit po stronie klienta też (serwer i tak przycina do max_labels).
    (function(){
      const maxAttr = parseInt((document.getElementById('upAttrList') || {}).dataset?.max || '2', 10) || 2;
      document.querySelectorAll('#upAttrList input[name="labels[]"]:checked')
        .forEach((cb, i) => { if(i < maxAttr) fd.append('labels[]', cb.value); });
    })();
    const attrNote = el('subAttractionNote');
    if(attrNote) fd.append('attraction_note', attrNote.value.trim());
    fd.append('csrf', csrfToken);
    setButton(BTN.busy, 'wysyłanie…');
    setProgress(0, null, 'Przygotowujemy zdjęcie…');

    // XHR, nie fetch — fetch nie daje zdarzeń postępu wysyłki,
    // więc użytkownik musi widzieć, że duże zdjęcie wciąż się wgrywa.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'submit-photo-api.php?action=submit', true);
    xhr.withCredentials = true;

    xhr.upload.addEventListener('progress', (ev) => {
      if(!ev.lengthComputable) return;
      const pct = Math.round((ev.loaded / ev.total) * 100);
      setProgress(pct, null, 'Wysyłanie zdjęcia…');
      setButton(BTN.busy, 'wysyłanie…');
    });

    // Po dosłaniu pliku serwer jeszcze konwertuje HEIC i generuje miniatury.
    // Postęp stoi wtedy w 100% i to jest normalne.
    xhr.upload.addEventListener('load', () => {
      setProgress(100, null, 'Przetwarzanie zdjęcia…');
      setButton(BTN.busy, 'wysyłanie…');
    });

    xhr.addEventListener('load', () => {
      let data = null;
      try{ data = JSON.parse(xhr.responseText); }catch(_){}
      if(!data || !data.ok){
        subMsg((data && data.message) || 'Nie udało się wysłać zgłoszenia.', false);
        setProgress(-1);
        setButton(BTN.error, 'nie wysłano — spróbuj ponownie');
        window.setTimeout(() => setButton(BTN.idle, 'wyślij do moderacji'), 3000);
        return;
      }

      const shots = (data.added || []).length;
      const skipped = data.skipped || [];
      const first = (data.added || [])[0] || null;
      const propTxt = first && first.proposal_label
        ? ' Proponowana kategoria: ' + first.proposal_label + ' — do rozpatrzenia przez administratora.'
        : '';
      // Serwer pomija zdjęcie bez lokalizacji (ani GPS, ani nazwy miejscowości).
      // To nie jest sukces — mówimy wprost, czego brakuje, i zostawiamy
      // formularz otwarty, żeby użytkownik dopisał nazwę i wysłał ponownie.
      if(shots === 0 && skipped.length){
        subMsg('Nie dodano zdjęcia. ' + skipped.join(' '), false);
        setProgress(-1);
        setButton(BTN.error, 'nie dodano — popraw i wyślij ponownie');
        el('upPlaceField').hidden = false;
        const input = el('subPlaceName');
        if(input && input.focus) input.focus();
        return;
      }

      subMsg('Dodano ' + (shots ? shots + ' ' + plural(shots, 'zdjęcie', 'zdjęcia', 'zdjęć') : 'nic')
        + '. Czeka na moderację.' + propTxt
        + (skipped.length ? ' Pominięto: ' + skipped.join(' ') : ''), true);

      // osobny krok „dodane” — użytkownik dostaje jednoznaczne potwierdzenie
      el('doneThumb').src = thumbUrl || '';
      el('doneFile').textContent = (first && first.title) || name;
      el('subForm').reset();
      selectedFiles = [];
      exifCoords = null;
      placeOverrideOpen = false;
      el('upProposalBox').hidden = true;
      el('upPlaceField').hidden = true;
      updateGeoPreview._sig = '';
      setProgress(-1);
      setButton(BTN.done, 'wysłano');
      showStep('done');
      loadMine();
      bumpPendingStat();
    });

    xhr.addEventListener('error', () => {
      subMsg('Błąd połączenia z serwerem.', false);
      setProgress(-1);
      setButton(BTN.error, 'nie wysłano — spróbuj ponownie');
      window.setTimeout(() => setButton(BTN.idle, 'wyślij do moderacji'), 3000);
    });

    xhr.addEventListener('abort', () => {
      setProgress(-1);
      setButton(BTN.idle, 'wyślij do moderacji');
    });

    xhr.send(fd);
  });

  // licznik „czeka na moderację” w nagłówku — aktualizowany bez przeładowania
  function bumpPendingStat(){
    const n = el('heroPendingStat');
    if(!n) return;
    const v = parseInt(n.textContent.replace(/\D+/g, ''), 10);
    n.textContent = String((isNaN(v) ? 0 : v) + 1);
  }
})();
</script>

<!-- ===================== RANGI: PODSTRONA (bez przeładowania) ===================== -->
<div class="rk-backdrop" id="rkBackdrop" hidden>
  <div class="rk-sheet" role="dialog" aria-modal="true" aria-label="Tabela rang">
    <button type="button" class="rk-close" id="rkClose" aria-label="Zamknij">×</button>

    <h2>Tabela rang</h2>
    <p class="note">Ranga zależy od liczby punktów. Gdy przekroczysz próg, awans jest automatyczny i natychmiastowy — także dla istniejącej aktywności ważonej wstecz. Rangi oznaczone <span class="rk-star">★</span> są specjalne i dodatkowe: nadaje je wyłącznie administracja i trzymają się obok zwykłej rangi z punktów.</p>

    <table class="rk-table">
      <thead>
        <tr><th style="text-align:left;">Ranga</th><th>Od pkt</th><th>Użytkownicy</th><th>Do</th></tr>
      </thead>
      <tbody id="rkTableBody">
        <?= $rankTableHtml !== '' ? $rankTableHtml : '<tr><td colspan="4" class="muted">Brak danych o rangach.</td></tr>' ?>
      </tbody>
    </table>

    <h3>Jak zdobywać punkty?</h3>
    <ul class="rk-faq">
      <li><b>+10 pkt</b> — za zdjęcie, które przejdzie moderację (punkty przyznamy po zatwierdzeniu).</li>
      <li><b>+2 pkt</b> — za każdy komentarz napisany z konta.</li>
      <li><b>+1 pkt</b> — za polubienie zdjęcia; cofnięcie polubienia odejmuje punkt.</li>
      <li><b>±</b> — administracja może ręcznie korygować punkty (np. za akcje poza galerią) i ma prawo nadać lub odebrać rangę niezależnie od punktów.</li>
      <li><b>Co oznacza ranga z gwiazdką (★)?</b> To ranga specjalna — nadaje ją wyłącznie administracja (np. dla drużyny). Jest dodatkową, trzymaną obok rangi z punktów: jej posiadacz dalej też ma swoją zwykłą rangę. Gwiazdki nie da się zdobyć punktami.</li>
    </ul>
    <h3>Częste pytania</h3>
    <ul class="rk-faq">
      <li><b>Czy punkty spadają poniżej zera?</b> Nie — minimum to zawsze 0.</li>
      <li><b>Dlaczego moje zatwierdzone zdjęcie dostało punkty dopiero teraz?</b> Stare, już zatwierdzone zdjęcia i komentarze zostały policzone wstecz przy włączaniu punktów.</li>
      <li><b>Co daje wyższa ranga?</b> Wyróżnienie na Twoim profilu i w panelu — w galerii nie podnosimy niczyich zdjęć kosztem innych.</li>
      <li><b>Usunięty komentarz/zdjęcie — co z punktami?</b> Punkty zostały już naliczone za faktyczną aktywność i nie przepadają; dotyczy także odrzuconych zgłoszeń.</li>
    </ul>

    <p class="note" style="margin-top:14px;">Rzeczywisty próg i liczba użytkowników w każdej randze odświeżają się po wejściu — jeśli brakuje aktualnych danych, odśwież stronę.</p>
  </div>
</div>

<!-- ===================== LISTA OSÓB (obserwujący / obserwowani) ===================== -->
<div class="rk-backdrop" id="peopleBackdrop" hidden>
  <div class="rk-sheet" role="dialog" aria-modal="true" aria-labelledby="peopleTitle">
    <button type="button" class="rk-close" id="peopleClose" aria-label="Zamknij">×</button>
    <h2 id="peopleTitle">Obserwujący</h2>
    <p class="note" id="peopleSub"></p>
    <div class="sub-list" id="peopleList" style="margin-top:16px"><p class="note">Wczytywanie…</p></div>
  </div>
</div>

<script>
/* Karta „Twoja ranga” — podstrona z tabelą i FAQ, bez przeładowania strony */
(() => {
  const backdrop = document.getElementById('rkBackdrop');
  const openBtn  = document.getElementById('rkOpenBtn');
  if(!backdrop || !openBtn) return;
  const closeBtn = document.getElementById('rkClose');
  const open = () => { backdrop.hidden = false; requestAnimationFrame(() => backdrop.classList.add('open')); closeBtn && closeBtn.focus(); };
  const close = () => { backdrop.classList.remove('open'); setTimeout(() => { backdrop.hidden = true; }, 220); };
  openBtn.addEventListener('click', open);
  backdrop.addEventListener('click', e => { if(e.target === backdrop) close(); });
  closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', e => { if(e.key === 'Escape' && !backdrop.hidden) close(); });
})();

/* Licznik i blokada kategorii atrakcji w formularzu zgłoszenia.
   Maks. z data-max (z konfiguracji, domyślnie 2): po osiągnięciu limitu
   pozostałe checkboxy się wyszarzają, a licznik pokazuje „2 / 2”.
   Bez tego użytkownik mógł zaznaczyć wszystko, a serwer cicho ucinał. */
(function(){
  const box = document.getElementById('upAttrList');
  if(!box) return;
  const counter = document.getElementById('upAttrCounter');
  const max = Math.max(1, Math.min(5, parseInt(box.dataset.max || '2', 10) || 2));
  function refresh(){
    const all = Array.from(box.querySelectorAll('input[name="labels[]"]'));
    const checked = all.filter(c => c.checked);
    if(counter){
      counter.textContent = 'Wybrano ' + checked.length + ' / ' + max;
      counter.style.color = checked.length >= max ? 'var(--gold)' : '';
    }
    all.forEach(c => { c.disabled = !c.checked && checked.length >= max; });
  }
  box.addEventListener('change', refresh);
  refresh();
})();

/* Lista osób: „kto mnie obserwuje / kogo obserwuję”. Klikalne liczniki
   w karcie społeczności otwierają popup z listą (social-api.php?action=people).
   Skrypt trzyma własne helpery (esc/initialsOf), bo główny skrypt panelu jest
   zamknięty we własnym IIFE i te funkcje nie są tu widoczne. */
(() => {
  const backdrop = document.getElementById('peopleBackdrop');
  if(!backdrop) return;
  const listBox  = document.getElementById('peopleList');
  const titleEl  = document.getElementById('peopleTitle');
  const subEl    = document.getElementById('peopleSub');
  const closeBtn = document.getElementById('peopleClose');
  const myId     = <?= (int) $me['id'] ?>;

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const initialsOf = name => {
    const s = String(name == null ? '' : name).trim();
    return s ? s.slice(0,1).toUpperCase() : '?';
  };

  const open = () => { backdrop.hidden = false; requestAnimationFrame(() => backdrop.classList.add('open')); closeBtn && closeBtn.focus(); };
  const close = () => { backdrop.classList.remove('open'); setTimeout(() => { backdrop.hidden = true; }, 220); };

  function personHtml(u){
    const av = u.avatar
      ? `<img src="${esc(u.avatar)}" alt="" loading="lazy">`
      : '<span class="si-av">' + esc(initialsOf(u.name)) + '</span>';
    const badges = [];
    if(u.isAdmin) badges.push('<span class="people-chip admin">Administrator</span>');
    if(u.specialRankName) badges.push(`<span class="people-chip" style="border-color:hsl(${u.specialRankHue} 55% 45%);color:hsl(${u.specialRankHue} 65% 72%)">${esc(u.specialRankName)}</span>`);
    if(u.rankName) badges.push(`<span class="people-chip">${esc(u.rankName)}</span>`);
    const badgesHtml = badges.length ? `<div class="people-badges">${badges.join('')}</div>` : '';
    const inner = av +
      '<div>' +
        `<div class="si-title">${esc(u.name)}${u.isPrivate ? ' <span class="people-chip">profil prywatny</span>' : ''}</div>` +
        `<div class="si-meta">@${esc(u.username)}</div>` +
        badgesHtml +
      '</div>';
    return u.url
      ? `<a class="sub-item people-row" href="${esc(u.url)}">${inner}</a>`
      : `<div class="sub-item people-row" data-private="1">${inner}</div>`;
  }

  async function load(mode){
    listBox.innerHTML = '<p class="note">Wczytywanie…</p>';
    try{
      const res = await fetch(`social-api.php?action=people&user=${myId}&mode=${mode}&limit=200`, { credentials:'include' });
      const data = await res.json();
      if(!data || !data.ok){ listBox.innerHTML = '<p class="note">Nie udało się wczytać listy.</p>'; return; }
      const items = data.items || [];
      if(!items.length){
        listBox.innerHTML = '<p class="note">' + (mode === 'following' ? 'Nikogo jeszcze nie obserwujesz.' : 'Nikt Cię jeszcze nie obserwuje.') + '</p>';
        return;
      }
      listBox.innerHTML = items.map(personHtml).join('');
    }catch(_){
      listBox.innerHTML = '<p class="note">Błąd połączenia z serwerem.</p>';
    }
  }

  document.querySelectorAll('[data-people]').forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.people === 'following' ? 'following' : 'followers';
      titleEl.textContent = mode === 'following' ? 'Obserwowani' : 'Obserwujący';
      subEl.textContent = mode === 'following'
        ? 'Profile, które obserwujesz — dostajesz od nich powiadomienia o nowych zdjęciach i komentarzach.'
        : 'Osoby, które obserwują Twój profil.';
      open();
      load(mode);
    });
  });

  backdrop.addEventListener('click', e => { if(e.target === backdrop) close(); });
  closeBtn && closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', e => { if(e.key === 'Escape' && !backdrop.hidden) close(); });
})();

/* Karta „Twoja ranga” była renderowana wyłącznie po stronie serwera, więc po
   moderacji zdjęcia, polubieniu albo korekcie punktów przez admina panel
   pokazywał stare liczby aż do przeładowania. Teraz odpytujemy ten sam zestaw,
   który wylicza panel (zm_rank_view w config.php), a tabelę rang dostajemy
   gotową — bez powielania wyliczeń rangi po stronie JS. */
(() => {
  const box = document.getElementById('rankBox');
  if(!box) return;

  const el = {
    chip:      document.getElementById('rankChip'),
    chipName:  document.getElementById('rankChipName'),
    chipEd:    document.getElementById('rankChipEd'),
    spChip:    document.getElementById('rankSpChip'),
    spName:    document.getElementById('rankSpName'),
    points:    document.getElementById('rankPointsNum'),
    progress:  document.getElementById('rankProgress'),
    barFill:   document.getElementById('rankBarFill'),
    barLabel:  document.getElementById('rankBarLabel'),
    photos:    document.getElementById('rankStatPhotos'),
    comments:  document.getElementById('rankStatComments'),
    likes:     document.getElementById('rankStatLikes'),
    favorites: document.getElementById('favCountStat'),
    tbody:     document.getElementById('rkTableBody'),
  };

  // number_format($n, 0, ',', ' ') → spacja jako separator tysięcy
  const num = n => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  // ten sam format, co w auth-api.php?action=rank
  const apply = (v, payload) => {
    if(!v) return;

    if(el.chipName && v.rank){
      el.chipName.textContent = v.rank.name;
      if(el.chip){
        el.chip.style.background = `hsl(${v.rankHue} 45% 32%)`;
        el.chip.style.color = `hsl(${v.rankHue} 70% 82%)`;
      }
    }
    if(el.chipEd) el.chipEd.style.display = v.rankManual ? '' : 'none';

    if(el.spChip){
      el.spChip.style.display = v.specialRank ? '' : 'none';
      if(v.specialRank && el.spName){
        el.spName.textContent = v.specialRank.name;
        el.spChip.style.background = `hsl(${v.specialHue} 45% 32%)`;
        el.spChip.style.color = `hsl(${v.specialHue} 70% 82%)`;
      }
    }

    if(el.points) el.points.textContent = num(v.points || 0);

    if(el.progress){
      el.progress.style.display = v.next ? '' : 'none';
      if(v.next){
        const need = Math.max(0, v.need || 0);
        el.progress.title = `Do rangi ${v.next.name} brakuje ${need} punktów`;
        if(el.barFill) el.barFill.style.width = Math.max(0, Math.min(100, v.fill || 0)) + '%';
        if(el.barLabel) el.barLabel.textContent = `${v.fill}% · do „${v.next.name}” zostało ${need} pkt`;
      }
    }

    if(v.counts){
      if(el.photos)   el.photos.textContent   = v.counts.photos;
      if(el.comments) el.comments.textContent = v.counts.comments;
      if(el.likes)    el.likes.textContent    = v.counts.likes;
      if(el.favorites && v.counts.favorites != null) el.favorites.textContent = v.counts.favorites;
    }

    // tabela rang: HTML z serwera, bo nazwy i pasma liczy tam zm_user_rank
    if(el.tbody && payload && typeof payload.tableHtml === 'string' && payload.tableHtml !== ''){
      el.tbody.innerHTML = payload.tableHtml;
    }
  };

  let busy = false;
  const refresh = () => {
    if(busy || document.hidden) return;
    busy = true;
    fetch('auth-api.php?action=rank', { credentials:'same-origin', cache:'no-store' })
      .then(r => r.ok ? r.json() : null)
      .then(d => { if(d && d.ok) apply(d.rank, d); })
      .catch(() => {})
      .then(() => { busy = false; });
  };

  // pokrywa moderację zdjęć, polubienia i ręczne korekty punktów bez przeładowania
  refresh();                                    // pierwsze pobranie — liczniki z bazy od razu
  window.addEventListener('focus', refresh);
  window.addEventListener('pageshow', refresh);
  document.addEventListener('visibilitychange', () => { if(!document.hidden) refresh(); });
  setInterval(refresh, 30000);
})();
</script>
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
  @media (max-width:640px){
    .scroll-top-btn{ right:14px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
  }
</style>
<button type="button" class="scroll-top-btn" id="scrollTopBtn" aria-label="Przewiń do góry" title="Do góry">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
</button>
<script>
(function(){
  var btn = document.getElementById('scrollTopBtn');
  if(!btn) return;
  function toggle(){
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    btn.classList.toggle('visible', y > 400);
  }
  btn.addEventListener('click', function(){
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top:0, behavior: reduce ? 'auto' : 'smooth' });
  });
  window.addEventListener('scroll', toggle, { passive:true });
  toggle();
})();
</script>

<script src="zmogilna-links.js" defer></script>
</body>
</html>

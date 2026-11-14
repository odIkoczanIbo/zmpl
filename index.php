<?php
declare(strict_types=1);

// Galeria to strona, której treść zmienia się z każdą moderacją zdjęć —
// bez tego nagłówka przeglądarka mogłaby pokazać starą powłokę strony
// z własnego cache. SW (sw.js) też nie cache'uje dokumentów.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require_once __DIR__ . '/photos-lib.php';
require_once __DIR__ . '/texts-lib.php';   // edytowalne teksty (panel → Ustawienia → Edycja tekstów)

require_once __DIR__ . '/community-lib.php';
require_once __DIR__ . '/games-lib.php';

// licznik ruchu (bez cookies; surowe IP wyłącznie w panelu admina przez 30 dni)
require_once __DIR__ . '/traffic-lib.php';
zm_traffic_track($pdo);

// Dane panelu społecznościowego wyciągnięte do community-lib.php, bo ta sama
// funkcja zasila community-api.php dla odświeżania bez przeładowania strony.
$gminaDescriptions = zm_home_gmina_descriptions($pdo);
$homeCommunity = zm_home_community($pdo);

// Sekcja „Atrakcje turystyczne” — dane z attraction-lib.php (konfiguracja z
// panelu admina). Ten sam zestaw zasila attraction-api.php (odświeżanie po hash).
// Wstawiana jest między galerią („Ostatnio dodane”) a „GŁOSEM SPOŁECZNOŚCI”.
$homeAttractions = zm_home_attractions($pdo);

// Licznik „zdjęć w zbiorze” i wykres aktywności liczone od razu na serwerze,
// z jednego zapytania do bazy. Wcześniej robił to JS, rekurencyjnie chodząc
// po folderach przez gallery.php - na telefonie licznik i wykres pojawiały się
// dopiero po dłuższej chwili. Teraz są w pierwszym HTML-u.
$galleryTotal = 0;
$galleryMonths = [];
try {
    $cntStmt = $pdo->query(
        "SELECT COUNT(*) FROM photos
         WHERE published = 1 AND moderation_status = 'approved'
           AND (kind IS NULL OR kind = 'image')"
    );
    $galleryTotal = (int) $cntStmt->fetchColumn();

    $monthRows = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM photos
         WHERE published = 1 AND moderation_status = 'approved'
           AND (kind IS NULL OR kind = 'image')
           AND created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 11 MONTH)
         GROUP BY ym"
    )->fetchAll();
    $monthCounts = [];
    foreach ($monthRows as $mr) {
        $monthCounts[(string) $mr['ym']] = (int) $mr['c'];
    }
    $plMonthShort = [1 => 'sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
    $monthCursor = new DateTimeImmutable(date('Y-m-01'));
    $monthCursor = $monthCursor->modify('-11 months');
    for ($i = 0; $i < 12; $i++) {
        $ym = $monthCursor->format('Y-m');
        $galleryMonths[] = [
            'key'   => $ym,
            'label' => $plMonthShort[(int) $monthCursor->format('n')],
            'count' => $monthCounts[$ym] ?? 0,
        ];
        $monthCursor = $monthCursor->modify('+1 month');
    }
} catch (Throwable $e) {
    // brak danych nie może wywalić strony - licznik zostaje na 0, wykres pusty
}

// Sprawdzenie, czy żądany jest konkretny widok zdjęcia (np. index.php?photo=p34)
$reqPhotoId = trim((string) ($_GET['photo'] ?? $_GET['id'] ?? ''));
$photoSeoRow = null;
if ($reqPhotoId !== '') {
    $photoSeoRow = zm_find_photo($pdo, $reqPhotoId, true);
}

if ($photoSeoRow !== null) {
    $photoLoc = zm_photo_locality($photoSeoRow);
    $photoRawTitle = $photoLoc !== '' ? $photoLoc : trim((string) ($photoSeoRow['title'] ?? ''));
    if ($photoRawTitle === '') {
        $photoRawTitle = pathinfo((string) ($photoSeoRow['filename'] ?? ''), PATHINFO_FILENAME);
    }
    if ($photoRawTitle === '') {
        $photoRawTitle = 'Zdjęcie ' . $reqPhotoId;
    }
    $pageTitle = $photoRawTitle . ' – zMogilna.pl';
    $photoRawCaption = zm_photo_caption($photoSeoRow, $photoLoc);
    if ($photoRawCaption === '') {
        $photoRawCaption = 'Zdjęcie archiwalne ' . $photoRawTitle . ' w zbiorze zMogilna.pl.';
    }
    $pageDescription = $photoRawCaption;
    $canonicalUrl = 'https://zmogilna.pl/index.php?photo=' . rawurlencode($reqPhotoId);
    $ogImageUrl = 'https://zmogilna.pl/photo.php?id=' . rawurlencode($reqPhotoId) . '&s=1200';
    $ogType = 'article';
    $isPhotoView = true;
} else {
    $pageTitle = zm_text('seo.title');
    $pageDescription = zm_text('seo.description');
    $canonicalUrl = 'https://zmogilna.pl/';
    $ogImageUrl = 'https://zmogilna.pl/icon-512.png';
    $ogType = 'website';
    $isPhotoView = false;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php /* Google Fonts ładowane bez blokowania pierwszego renderu: preload + przełączenie na stylesheet po
         pobraniu (display=swap w adresie → do tego czasu system pokazuje tekst fontem zapasowym).
         <noscript> zachowuje fonty, gdy JavaScript jest wyłączony. */ ?>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500&family=Inter:wght@400;600&family=JetBrains+Mono:wght@400&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500&family=Inter:wght@400;600&family=JetBrains+Mono:wght@400&display=swap"></noscript>
<?php /* Wspólna warstwa UI (kopia zmogilna-ui.css, ~1,7 KB) wstawiona inline, żeby nie blokować renderu
         osobnym żądaniem. Plik zmogilna-ui.css zostaje dla pozostałych podstron — zmiany rób w obu miejscach. */ ?>
<style>
:root{--ui-scroll-track:var(--bg-alt,oklch(20% .03 145));--ui-scroll-thumb:var(--line-strong,oklch(46% .03 145/.55));--ui-scroll-thumb-hover:var(--gold,oklch(76% .14 88));accent-color:var(--gold,#c9a227)}
*{scrollbar-width:thin;scrollbar-color:var(--ui-scroll-thumb) var(--ui-scroll-track)}
*::-webkit-scrollbar{width:9px;height:9px}
*::-webkit-scrollbar-track{background:var(--ui-scroll-track);border:1px solid var(--line,oklch(38% .03 145/.22));border-radius:999px}
*::-webkit-scrollbar-thumb{min-height:30px;background:var(--ui-scroll-thumb);border:2px solid var(--ui-scroll-track);border-radius:999px;transition:background-color .16s ease-out}
*::-webkit-scrollbar-thumb:hover,*::-webkit-scrollbar-thumb:active{background:var(--ui-scroll-thumb-hover)}
*::-webkit-scrollbar-corner{background:var(--ui-scroll-track)}
.recent-folder-strip,.favorites-strip,.comments-recent-strip{scrollbar-color:var(--ui-scroll-thumb) transparent}
.recent-folder-strip::-webkit-scrollbar-track,.favorites-strip::-webkit-scrollbar-track,.comments-recent-strip::-webkit-scrollbar-track{background:transparent;border-color:transparent}
.lightbox-comments-panel,.cmdk-list,dialog.guess-dialog{scrollbar-gutter:stable}
::selection{background:var(--gold,#c9a227);color:#101c16}
:where(a,button,input,select,textarea,summary,[tabindex]):focus-visible{outline:2px solid var(--gold,#c9a227);outline-offset:2px;border-radius:var(--focus-radius,6px)}
@media (prefers-reduced-motion:reduce){*::-webkit-scrollbar-thumb{transition:none}}
</style>

<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php if ($reqPhotoId !== '' && $photoSeoRow === null): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<meta name="theme-color" content="#101c16">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="icon-192.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">

<meta property="og:type" content="<?= $ogType ?>">
<meta property="og:site_name" content="zmogilna">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta property="og:locale" content="pl_PL">
<meta name="twitter:card" content="<?= $isPhotoView ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

<?php if ($isPhotoView): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ImageObject",
  "name": <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  "description": <?= json_encode($pageDescription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  "contentUrl": <?= json_encode($ogImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  "inLanguage": "pl-PL"
}
</script>
<?php else: ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "name": "zmogilna — Mogilno i okolice",
  "description": "Prywatne archiwum zdjęć Mogilna i okolic — bieżąco uzupełniana galeria terenu, miejscowości i krajobrazu.",
  "url": "https://zmogilna.pl/",
  "inLanguage": "pl-PL"
}
</script>
<?php endif; ?>

<style>
  /* ============================================================
     KONFIGURACJA — wpisz tu swoje dane raz, a strona zapamięta
     je na stałe (nie trzeba używać panelu ustawień).
     ============================================================ */
</style>
</head>
<body>

<script>
  (function(){
    // Domyślnie tryb ciemny, niezależnie od ustawień systemu. Jasny włącza się
    // tylko wtedy, gdy odwiedzający świadomie przełączył go przyciskiem
    // (wybór zapisany w localStorage).
    let saved = null;
    try{ saved = localStorage.getItem('zmogilna-theme'); }catch(_){}
    const initial = saved === 'light' ? 'light' : 'dark';
    if(initial === 'light') document.documentElement.setAttribute('data-theme', 'light');
  })();
</script>

<script>
  // ---- KONFIGURACJA GALERII (zdjęcia lokalne: uploads/photos/ + baza `zmogilna`) ----
  const CONFIG = {
    // "ROOT" = katalog uploads/photos/ na serwerze. Nic nie trzeba zmieniać.
    folderId: "ROOT",
    // zostawione dla zgodności z kodem strony — lokalne API nie używa klucza
    apiKey: "local",
    // adres lokalnego API galerii (plik gallery.php obok index.php)
    api: "gallery.php",
    // opcjonalnie: ID zdjęcia „wyróżnionego” na górze galerii
    // (ID znajdziesz w adresie po kliknięciu „udostępnij” przy zdjęciu)
    featuredPhotoId: "",
    // opisy gmin pochodzą z tabeli `categories` (pole description) — edytujesz je w panelu
    gminaDescriptions: <?= json_encode($gminaDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    // nazwy użytkowników, które nie mogą dostać krótkiego adresu /<login>, bo
    // kolidują z adresami aplikacji — ta sama lista, której używa PHP
    // (config.php → zm_reserved_usernames()), żeby link w galerii zgadzał się
    // z tym, co pokaże profil.php
    profileReserved: <?= json_encode(zm_reserved_usernames(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    profileExts: <?= json_encode(ZM_RESERVED_USERNAME_EXTS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
  // -----------------------------------------------------------

  // Adres profilu, dokładnie taki sam, jaki wygeneruje PHP (zm_profile_path):
  // krótki /<login>, a gdy login koliduje z adresem strony — klasyczny
  // profil.php?u=… Dzięki temu link z galerii i adres z serwera się zgadzają.
  function profileUrl(username, id){
    const clean = String(username == null ? '' : username).trim();
    const lower = clean.toLowerCase();
    const hasDot = clean.indexOf('.') !== -1;
    const ext = hasDot ? clean.split('.').pop().toLowerCase() : '';
    const reserved = CONFIG.profileReserved.indexOf(lower) !== -1
      || (ext !== '' && CONFIG.profileExts.indexOf(ext) !== -1);
    if(clean !== '' && /^[A-Za-z0-9_.\-]{3,40}$/.test(clean) && !reserved){
      return '/' + encodeURIComponent(clean);
    }
    if(id) return 'profil.php?id=' + encodeURIComponent(id);
    return 'profil.php?u=' + encodeURIComponent(clean);
  }
</script>

<script>
  // miniatura w przygotowaniu (503 z photo.php) — ponów kilka razy z rosnącym opóźnieniem
  document.addEventListener('error', function(e){
    const img = e.target;
    if(!img || img.tagName !== 'IMG' || !/photo\.php\?/.test(img.src)) return;
    const n = (parseInt(img.dataset.retry || '0', 10)) + 1;
    if(n > 4) return;
    img.dataset.retry = String(n);
    const base = img.src.replace(/([?&])_r=\d+&?/, '$1').replace(/[?&]$/, '');
    setTimeout(() => { img.src = base + (base.indexOf('?') > -1 ? '&' : '?') + '_r=' + n; }, 600 * n);
  }, true);
</script>

<script>
  // rejestracja service workera (instalacja na ekranie głównym + awaryjna
  // kopia samych assetów). Worker NIE cache'uje PHP/API/HTML/JS — patrz sw.js.
  // updateViaCache:'none' dodatkowo pilnuje, żeby przeglądarka nie sprawdzała
  // nowej wersji sw.js po HTTP cache i nie trzymała starego workera w nieskończoność.
  if('serviceWorker' in navigator){
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' }).catch(() => {});
    });
  }
</script>

<style>
  :root{
    --bg: #101c16;
    --bg-alt: #16241c;
    --card: #18271f;
    --ink: #ece6d6;
    --ink-dim: #96a397;
    --gold: #c9a227;
    --teal: #5a8a86;
    --clay: #a65a3c;
    --line: rgba(236,230,214,0.10);
    --line-strong: rgba(236,230,214,0.22);
  }

  html[data-theme="light"]{
    --bg: #f5f1e6;
    --bg-alt: #ece5d2;
    --card: #ffffff;
    --ink: #1c2620;
    --ink-dim: #5c6b60;
    --gold: #a9791f;
    --teal: #3f6d69;
    --clay: #a65a3c;
    --line: rgba(28,38,32,0.10);
    --line-strong: rgba(28,38,32,0.20);
  }

  /* ---------- płynne przejścia między stronami (View Transitions API) ----------
     Jedna deklaratywna reguła CSS. Tam, gdzie przeglądarka tego nie obsługuje
     (dziś: część Firefoksa), nawigacja po prostu działa jak wcześniej. */
  @view-transition{ navigation:auto; }
  ::view-transition-old(root), ::view-transition-new(root){ animation-duration:.4s; animation-timing-function:cubic-bezier(.22,.61,.36,1); }
  @media (prefers-reduced-motion:reduce){ ::view-transition-group(*){ animation:none !important; } }

  *{ box-sizing:border-box; }
  html{ scroll-behavior:smooth; }
  body{
    margin:0;
    background:var(--bg);
    color:var(--ink);
    font-family:'Inter', sans-serif;
    -webkit-font-smoothing:antialiased;
    transition: background-color .25s ease, color .25s ease;
  }
  @media (prefers-reduced-motion: reduce){
    *{ animation-duration:0.01ms !important; transition-duration:0.01ms !important; }
  }

  a{ color:inherit; }
  .mono{ font-family:'JetBrains Mono', monospace; }

  /* ---------- layout shell ---------- */
  .wrap{ max-width:1180px; margin:0 auto; padding:0 28px; }

  header.top{
    display:flex; align-items:center; justify-content:space-between;
    padding:26px 0;
    border-bottom:1px solid var(--line);
  }
  .site-nav{
    margin-top:8px; display:flex; flex-wrap:wrap; gap:14px;
  }
  .site-nav a{
    font-family:'JetBrains Mono', monospace; font-size:11px; letter-spacing:0.04em;
    color:var(--ink-dim); text-decoration:none;
    border-bottom:1px dotted var(--line-strong); padding-bottom:1px;
    transition: color .2s, border-color .2s;
  }
  .site-nav a:hover{ color:var(--gold); border-color:var(--gold); }
  .wordmark{
    font-family:'Fraunces', serif;
    font-size:22px; font-weight:600;
    letter-spacing:0.01em;
  }
  .wordmark span{ color:var(--gold); }
  .lede-br-m{ display:none; }
  @media (max-width:640px){ .lede-br{ display:none; } .lede-br-m{ display:inline; } }
  .wordmark .tld{ font-style:normal; font-weight:400; font-size:0.62em; color:var(--ink-dim); opacity:.55; letter-spacing:0.02em; margin-left:1px; }
  .coords{
    font-size:11px; letter-spacing:0.04em; color:var(--ink-dim);
  }
  button:focus-visible, input:focus-visible{
    outline:2px solid var(--gold); outline-offset:2px;
  }

  /* ---------- hero ---------- */
  .hero{
    display:grid; grid-template-columns: 1.1fr 0.9fr;
    gap:56px; align-items:center;
    padding:64px 0 56px;
    border-bottom:1px solid var(--line);
  }
  @media (max-width:860px){ .hero{ grid-template-columns:1fr; padding:44px 0 36px; gap:34px; } }

  .eyebrow{
    font-family:'JetBrains Mono', monospace;
    font-size:12px; letter-spacing:0.08em; text-transform:uppercase;
    color:var(--gold); margin-bottom:18px; display:flex; align-items:center; gap:10px;
  }
  .eyebrow::before{
    content:""; width:22px; height:1px; background:var(--gold); display:inline-block;
  }
  h1{
    font-family:'Fraunces', serif; font-weight:500;
    font-size:clamp(34px, 4.4vw, 52px);
    line-height:1.08; letter-spacing:-0.01em;
    margin:0 0 20px;
  }
  h1 em{ font-style:italic; color:var(--teal); }
  .lede{
    font-size:16px; line-height:1.65; color:var(--ink-dim);
    max-width:46ch; margin:0 0 26px;
  }
  .hero-meta{
    display:flex; gap:26px; flex-wrap:wrap;
  }
  .hero-meta div{ font-family:'JetBrains Mono', monospace; font-size:12px; color:var(--ink-dim); }
  .hero-meta b{ display:block; font-family:'Fraunces', serif; font-size:22px; color:var(--ink); font-weight:500; }

  .activity-block{ margin-top:28px; }
  .activity-label{
    font-family:'JetBrains Mono', monospace; font-size:11px; letter-spacing:0.04em;
    text-transform:uppercase; color:var(--ink-dim); margin-bottom:8px;
  }
  .chart-bars{ display:flex; align-items:flex-end; gap:5px; height:56px; }
  .chart-bar-col{ flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
  .chart-bar{ width:100%; background:var(--teal); border-radius:2px 2px 0 0; min-height:2px; transition:height .5s ease, background .2s; }
  .chart-bar-col:hover .chart-bar{ background:var(--gold); }
  .chart-bar-label{ font-family:'JetBrains Mono', monospace; font-size:9px; color:var(--ink-dim); margin-top:5px; }

  /* ---------- area map: powiaty/gminy outline (signature element) ---------- */
  .dial-holder{ display:flex; justify-content:center; }
  .map-card{
    position:relative;
    width:100%; max-width:380px; aspect-ratio:1/1;
    cursor:zoom-in;
  }
  .map-card svg{ display:block; width:100%; height:100%; }
  .c-muni{ fill:none; stroke:var(--line); stroke-width:0.6; }
  .c-muni-in{ fill:#6fa8dc; fill-opacity:0.16; stroke:#6fa8dc; stroke-opacity:0.55; stroke-width:0.8; }
  .c-county{ fill:none; stroke:var(--line-strong); stroke-width:1; }
  .c-home{ fill:none; stroke:var(--teal); stroke-width:1.3; }
  .c-town-dot{ fill:var(--ink-dim); }
  .c-town-label{
    font-family:'JetBrains Mono', monospace; font-size:7.5px; fill:var(--ink-dim);
    letter-spacing:0.01em;
  }
  .c-mogilno-label{ font-family:'Fraunces', serif; font-size:11px; fill:var(--ink); }
  /* Napisy w samym SVG wyłączamy na dotyk, aby Chromium nie traktowało nazw
     miejscowości jako tekstu do zaznaczenia ani rozpoznania jako adresu.
     Sama karta nasłuchuje kliknięć i nadal otwiera folder po nazwie gminy. */
  .map-card svg text{
    touch-action:manipulation;
    -webkit-tap-highlight-color:transparent;
    -webkit-touch-callout:none;
    user-select:none;
    -webkit-user-select:none;
  }
  .c-ring{ fill:none; stroke:var(--line-strong); stroke-width:0.7; stroke-dasharray:2,3; }
  .c-ring-main{ fill:var(--gold); fill-opacity:0.04; stroke:var(--gold); stroke-width:1.2; stroke-dasharray:none; }
  .c-center{ fill:var(--clay); }
  .map-card svg{ transition:transform .35s ease; transform-origin:center; }
  .map-card.zoomed svg{ transform:scale(1.6); }
  .map-card svg{ transition:transform .35s ease; }
  /* Blokada „linkowego” zachowania mapy na telefonie. Każdy silnik ma inny
     mechanizm, więc każda warstwa celuje gdzie indziej:
       - Safari / WebKit: -webkit-touch-callout: none wyłącza menu po
         przytrzymaniu (Kopiuj / Wygoogluj);
       - Chrome / Blink: ignoruje touch-callout, więc zatrzymuje go dopiero
         user-select: none — bez zaznaczenia nie ma od czego zacząć menu.
       Obie przeglądarki respektują -webkit-tap-highlight-color.
     Mapa jest martwa poza kliknięciem w nazwę gminy, więc wyłączamy to dla
     całego fragmentu, a nie dla jednej etykiety — tekstów jest tam kilka
     („Mogilno”, „· promień 20 km”, „kliknij, by powiększyć”), a Chrome potrafi
     podkreślić nowy, dopiero dodany. Google dobiera wtedy do samej nazwy
     adres z własnej bazy — u was wychodziło z tego „Wincentego Witosa 6”. */
  .map-card, .map-card *{
    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select:none; -webkit-user-select:none;
  }
  .map-label{
    position:absolute; left:12px; bottom:12px; z-index:2;
    display:flex; align-items:center; gap:7px;
    font-family:'Fraunces', serif; font-size:13px; color:var(--ink);
    background:rgba(16,28,22,0.72); backdrop-filter:blur(2px);
    border:1px solid var(--line-strong); border-radius:20px;
    padding:6px 12px 6px 10px; pointer-events:none;
  }
  html[data-theme="light"] .map-label{ background:rgba(245,241,230,0.82); }
  .map-label-dot{ width:7px; height:7px; border-radius:50%; background:var(--clay); flex-shrink:0; }
  .map-label-dim{ font-family:'JetBrains Mono', monospace; font-size:10px; color:var(--ink-dim); }
  /* Podpis mapy jest zwykłym tekstem HTML, dzięki czemu technologie
     wspomagające mogą go prawidłowo odczytać. Podpowiedź pozostaje dekoracyjna. */
  .map-hint::after{ content:attr(data-hint); }
  .map-hint{
    position:absolute; top:10px; right:12px; z-index:2;
    font-family:'JetBrains Mono', monospace; font-size:9px; color:var(--ink-dim);
    background:rgba(16,28,22,0.6); border:1px solid var(--line); border-radius:12px;
    padding:3px 8px; pointer-events:none; opacity:0; transition:opacity .2s;
  }
  html[data-theme="light"] .map-hint{ background:rgba(245,241,230,0.75); }
  .map-card:hover .map-hint{ opacity:1; }

  /* ---------- społeczność / aktualności strony głównej ---------- */
  .community-section{ padding:42px 0 18px; border-bottom:1px solid var(--line); }
  .community-top{ display:flex; align-items:end; justify-content:space-between; gap:22px; margin-bottom:24px; }
  .community-top h2{ margin:0; font-family:'Fraunces', serif; font-size:clamp(27px,3vw,36px); font-weight:500; letter-spacing:-.02em; }
  .community-copy{ max-width:43ch; margin:8px 0 0; color:var(--ink-dim); font-size:14px; line-height:1.6; }
  .community-link{ color:var(--gold); font:11px 'JetBrains Mono', monospace; text-decoration:none; white-space:nowrap; cursor:pointer; }
  .community-link:hover{ text-decoration:underline; }
  .community-stats{ display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:28px; }
  .community-stat{ padding:15px 17px; background:var(--card); border:1px solid var(--line); border-radius:12px; }
  .community-stat b{ display:block; font:500 clamp(23px,3vw,31px) 'Fraunces', serif; color:var(--ink); }
  .community-stat span{ display:block; margin-top:4px; color:var(--ink-dim); font:10px 'JetBrains Mono', monospace; letter-spacing:.03em; text-transform:uppercase; }
  .community-grid{ display:grid; grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr); gap:16px; }
  .community-panel{ min-width:0; padding:20px; background:var(--bg-alt); border:1px solid var(--line); border-radius:14px; }
  .community-panel-head{ display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:16px; }
  .community-panel h3{ margin:0; font:500 20px 'Fraunces', serif; }
  .community-kicker{ color:var(--ink-dim); font:10px 'JetBrains Mono', monospace; text-transform:uppercase; letter-spacing:.04em; }
  .community-more{
    background:none; border:none; cursor:pointer; white-space:nowrap;
    color:var(--gold); font:10px 'JetBrains Mono', monospace; letter-spacing:.04em; text-transform:uppercase;
    padding:2px 0;
  }
  .community-more:hover{ text-decoration:underline; }
  .member-list{ display:grid; gap:8px; }
  .member-card{ display:flex; align-items:center; gap:11px; min-width:0; padding:9px; border:1px solid transparent; border-radius:10px; text-decoration:none; transition:background .2s,border-color .2s,transform .2s; }
  .member-card:hover{ background:var(--card); border-color:var(--line-strong); transform:translateX(2px); }
  .member-avatar{
    position:relative; width:38px; height:38px; display:grid; place-items:center;
    overflow:visible; flex:0 0 auto; border-radius:50%;
    background:linear-gradient(135deg,var(--teal),var(--gold)); color:#fff;
    font:500 14px 'Fraunces', serif;
  }
  .member-avatar img{ width:100%; height:100%; object-fit:cover; border-radius:50%; display:block; }
  .member-avatar .online-dot{
    position:absolute; right:-1px; bottom:-1px; width:11px; height:11px; border-radius:50%;
    background:#3d9a6a; border:2px solid var(--bg); box-shadow:0 0 0 1px rgba(61,154,106,.35);
    pointer-events:none;
  }
  .member-text{ min-width:0; }
  .member-name{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink); font-size:13px; font-weight:600; }
  .member-handle{ margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink-dim); font:10.5px 'JetBrains Mono', monospace; }
  .member-date{ margin-left:auto; color:var(--ink-dim); font:10px 'JetBrains Mono', monospace; white-space:nowrap; }
  .latest-photos{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
  .latest-photo{ position:relative; display:block; min-width:0; aspect-ratio:1/1; overflow:hidden; border:1px solid var(--line); border-radius:9px; background:var(--card); }
  .latest-photo img{ display:block; width:100%; height:100%; object-fit:cover; transition:transform .35s ease; }
  .latest-photo:hover img{ transform:scale(1.06); }
  .latest-photo-label{ position:absolute; right:0; bottom:0; left:0; padding:20px 8px 7px; overflow:hidden; color:#fff; text-overflow:ellipsis; white-space:nowrap; background:linear-gradient(transparent,rgba(0,0,0,.78)); font-size:10.5px; }
  .community-empty{ grid-column:1/-1; margin:0; padding:20px 0; color:var(--ink-dim); font-size:13px; line-height:1.5; }
  /* trzeci wiersz paneli: trzy kolumny na szerokim ekranie */
  .community-grid-3{ grid-template-columns:repeat(3, minmax(0,1fr)); margin-top:16px; }
  /* ranking wkładowców — przebudowany pod telefon.
     Wcześniej karta była jednym wierszem flex: nazwa z rangą i punktami
     oraz prawa kolumna z licznikami (zdjęcia / polubienia). Przy wąskim
     ekranie obie kolumny zlewały się ze sobą i „X pkt” wychodziło
     niemal na „polubień”.
     Teraz karta to grid: awatar + kolumna treści. W treści wiersz nazwy
     zaczyna się numerem miejsca i kończy pillem z punktami, a ranga
     i liczniki lądują w drugim wierszu (flex-wrap), więc nachodzenie na
     siebie jest fizycznie niemożliwe, a przy długich danych po prostu
     się zawija. */
  .contrib-list{ list-style:none; margin:0; padding:0; display:grid; gap:8px; }
  .contrib-card{ min-width:0; }
  .contrib-card-link{
    display:grid; grid-template-columns:auto minmax(0,1fr);
    align-items:center; gap:11px; min-width:0; padding:10px;
    border:1px solid transparent; border-radius:10px;
    color:inherit; text-decoration:none;
    transition:background .2s,border-color .2s;
  }
  .contrib-card-link:hover{ background:var(--card); border-color:var(--line-strong); }
  .contrib-card-link:focus-visible{ outline:1px solid var(--gold); outline-offset:2px; }
  /* Blokada „linkowego” zachowania rankingu na telefonie — trzy warstwy,
     bo każda zamyka inny mechanizm systemu:
       1. -webkit-tap-highlight-color – podświetlenie aktywnego dotyku,
       2. user-select:none              – zaznaczanie tekstu,
       3. -webkit-touch-callout:none    – menu po przytrzymaniu (Kopiuj /
          Wygoogluj), które na telefonie wygląda dokładnie jak podkreślony
          link i potrafi odpalić wyszukiwarkę z tekstem karty.
     Panel jest w całości „martwy” klikalnie, więc wyłączamy to dla niego,
     a nie tylko dla pojedynczej karty. */
  .community-panel, .community-panel *{
    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
  }
  .contrib-card{ user-select:none; -webkit-user-select:none; }
  /* Punktów i numeru miejsca nie ma jako tekstu w DOM — wartości siedzą
     w data-pts / data-pos, a CSS je rysuje. System nie ma wtedy żadnego
     fragmentu do zaznaczenia ani rozpoznania jako adres. */
  .contrib-points::after{ content: attr(data-pts); }
  .contrib-pos::after{ content: attr(data-pos); }
  /* Numer miejsca to zwykły tekst przed nazwą, a nie osobna kolumna z
     kółkiem. Zyskujemy ~33px (kółko + dwa odstępy) dla treści, dzięki
     czemu „polubienia”/like nie wychodzą pod wiersz metadanych. */
  .contrib-pos{
    flex:0 0 auto; min-width:1ch; text-align:right;
    color:var(--ink-dim); font:500 11.5px 'JetBrains Mono', monospace;
  }
  .contrib-body{ min-width:0; display:grid; gap:5px; }
  .contrib-top{ display:flex; align-items:baseline; gap:7px; min-width:0; }
  .contrib-top .member-name{ flex:1 1 auto; min-width:0; }
  /* Nazwa zachowuje wygląd zwykłego tekstu; cały wiersz jest linkiem. */
  .contrib-name{ color:inherit; }
  /* Punkty jako osobny pill — nie mieszają się z licznikami aktywności
     ani nie skracają nazwy użytkownika bardziej niż trzeba. */
  .contrib-points{
    flex:0 0 auto; padding:2px 9px; border-radius:999px; white-space:nowrap;
    background:rgba(201,162,39,.12); border:1px solid rgba(201,162,39,.35);
    color:var(--gold); font:500 10.5px 'JetBrains Mono', monospace;
  }
  .contrib-meta{ display:flex; flex-wrap:wrap; align-items:center; gap:5px 7px; min-width:0; }
  .contrib-rank{
    min-width:0; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    color:var(--ink-dim); font:10.5px 'JetBrains Mono', monospace;
  }
  .contrib-chip{
    flex:0 0 auto; padding:2px 8px; border-radius:999px; white-space:nowrap;
    background:var(--card); border:1px solid var(--line);
    color:var(--ink-dim); font:10px 'JetBrains Mono', monospace;
  }
  .contrib-chip b{ color:var(--ink); font-weight:500; }
  .contrib-chip-muted{ opacity:.72; }
  /* ranking miejsc — pasek proporcji */
  .place-list{ list-style:none; margin:0; padding:0; display:grid; gap:7px; }
  .place-row{ display:grid; grid-template-columns:minmax(0,1fr) 74px 34px; align-items:center; gap:9px; }
  .place-name{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink); font-size:13px; }
  a.place-name, button.place-name{
    appearance:none; background:none; border:0; padding:0; margin:0; cursor:pointer;
    text-align:left; font:inherit; color:var(--ink); text-decoration:none;
    transition:color .18s ease;
  }
  a.place-name:hover, button.place-name:hover{ color:var(--gold); }
  .place-folder-tree{ display:grid; gap:5px; min-width:0; }
  .place-gmina-folder,.place-locality-folder{ display:flex; align-items:center; gap:7px; min-width:0; }
  .place-gmina-folder svg,.place-locality-folder svg{
    width:16px; height:16px; flex:0 0 16px; fill:none; stroke:var(--gold);
    stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round;
  }
  .place-gmina-folder span,.place-locality-folder span{
    min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .place-locality-folders{
    display:grid; gap:5px; margin-left:9px; padding-left:17px;
    border-left:1px solid var(--line-strong);
  }
  .place-locality-folder{ color:var(--ink-dim); font-size:11.5px; }
  .place-locality-folder svg{ width:14px; height:14px; flex-basis:14px; stroke:var(--ink-dim); }
  .place-bar{ height:6px; overflow:hidden; border-radius:999px; background:var(--card); border:1px solid var(--line); }
  .place-bar i{ display:block; height:100%; background:linear-gradient(90deg,var(--teal),var(--gold)); }
  .place-count{ color:var(--ink-dim); font:10.5px 'JetBrains Mono', monospace; text-align:right; }
  /* najbardziej lubiane kadry */
  .top-photos{ display:grid; gap:8px; }
  .top-photo{ position:relative; display:grid; grid-template-columns:68px minmax(0,1fr); align-items:center; gap:11px; text-decoration:none; }
  .top-photo img{ width:68px; height:52px; object-fit:cover; border-radius:7px; border:1px solid var(--line); }
  .top-photo-meta{ display:grid; gap:3px; min-width:0; }
  .top-photo-title{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink); font-size:13px; }
  .top-photo-likes{ color:var(--gold); font:10.5px 'JetBrains Mono', monospace; }
  /* tygodniowa aktywność */
  .community-activity{ margin-top:16px; padding:20px; background:var(--bg-alt); border:1px solid var(--line); border-radius:14px; }
  .activity-row{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
  .activity-cell{ padding:13px 15px; background:var(--card); border:1px solid var(--line); border-radius:10px; }
  .activity-cell b{ display:block; font:500 clamp(21px,2.6vw,28px) 'Fraunces', serif; color:var(--ink); }
  .activity-cell span{ display:block; margin-top:3px; color:var(--ink-dim); font:10px 'JetBrains Mono', monospace; text-transform:uppercase; letter-spacing:.03em; }
  .community-note{ margin:12px 0 0; color:var(--ink-dim); font-size:12px; line-height:1.5; }
  @media (max-width:900px){ .community-grid-3{ grid-template-columns:1fr; } }
  @media (max-width:760px){ .community-top{ align-items:start; flex-direction:column; gap:12px; } .community-grid{ grid-template-columns:1fr; } .place-row{ grid-template-columns:minmax(0,1fr) 54px 30px; } .community-activity{ padding:16px; } }
  @media (max-width:480px){ .activity-row{ grid-template-columns:1fr; gap:7px; } }
  @media (max-width:480px){ .community-section{ padding-top:34px; } .community-stats{ gap:7px; } .community-stat{ padding:12px 10px; } .community-stat span{ font-size:8.5px; } .community-panel{ padding:16px; } }

  /* ---------- gallery toolbar / search / sort ---------- */
  /* overflow-anchor:none — bez tego przeglądarka przy zmianie wysokości
     listy wyników (każde nowe naciśnięcie klawisza) przestawia widok i strona
     „skacze”, a kursor w polu wyszukiwania ucieka. */
  #galleryToolbar{ overflow-anchor:none; }
  .gallery-toolbar{
    display:flex; flex-wrap:wrap; gap:8px; align-items:center;
    margin-bottom:18px;
  }
  .gallery-search{
    flex:1 1 160px; min-width:0;
    background:var(--card); border:1px solid var(--line-strong); border-radius:20px;
    padding:9px 14px; color:var(--ink); font-family:'Inter', sans-serif; font-size:16px;
  }
  /* pojemnik na pole + przycisk „×” — dzieli flex tak samo jak samo pole */
  .gallery-search-wrap{ position:relative; flex:1 1 160px; min-width:0; display:flex; }
  /* width:0 + flex:1 — pole dostaje całą szerokość kontenera niezależnie od
     treści. Bez tego pojawienie się tekstu (i padding-right) potrafiło
     rozszerzyć input poza zaokrąglone krawędzie, a litery wychodziły
     poza widoczne pole. */
  .gallery-search-wrap .gallery-search{ flex:1 1 0%; width:0; min-width:0; }
  /* natywny „×” przeglądarek (type=search) — chowamy, bo mamy własny */
  .gallery-search::-webkit-search-cancel-button,
  .gallery-search::-webkit-search-decoration,
  .gallery-search::-webkit-search-results-button,
  .gallery-search::-webkit-search-results-decoration{ -webkit-appearance:none; appearance:none; display:none; }
  /* display:grid w klasie wygrywa z atrybutu [hidden] (display:none z UA ma
     niższy priorytet), więc bez tego przycisk nigdy by nie zniknął. */
  .gallery-search-clear[hidden]{ display:none; }
  .gallery-search-clear{
    position:absolute; top:50%; right:6px; transform:translateY(-50%);
    width:30px; height:30px; padding:0; line-height:1;
    display:grid; place-items:center;
    background:transparent; border:0; border-radius:50%;
    color:var(--ink-dim); font-size:20px; cursor:pointer;
  }
  .gallery-search-clear:hover{ color:var(--ink); background:var(--bg-alt); }
  .gallery-search-clear:focus-visible{ outline:1px solid var(--gold); }
  /* Miejsce na przycisk „×” zawsze zarezerwowane po prawej. Wcześniej pojawiało
     się przez :has(), a to dodawało padding w trakcie pisania i pole
     „przeskakiwało”. Stały padding + JS w toggleSearchClear() nie powoduje
     przesunięcia tekstu. */
  /* padding-right 38px zostawia miejsce na nasz przycisk „×” — także tutaj,
       bo reguła mobilna niżej resetuje padding i nadpisałaby nasz. */
  .gallery-search-wrap .gallery-search{ padding-right:38px; }
  .gallery-search::placeholder{
    font-size:12px; color:var(--ink-dim); opacity:1;
  }
  .gallery-search:focus{ outline:none; border-color:var(--gold); }
  .gallery-view-group{
    display:inline-flex; gap:6px; flex-shrink:0; align-items:center;
  }
    /* Wstawka z przyciskami gęstości renderowana między folderami a zdjęciami.
       Widoczna również na stronie głównej. */
    .gallery-view-group-inline{ display:none; }
    /* Przełącznik gęstości siatki dostępny na wszystkich widokach (w tym stronie głównej). */
  /* Podpowiedź o uszczypnięciu siedzi w tym samym szablonie co przyciski,
     ale pokazujemy ją tylko we wstawce mobilnej — w desktopowym pasku
     narzędzi tekst o gęstości tylko by przeszkadzał. */
  .gallery-view-group .view-hint{ display:none; }
  .gallery-view-btn{
    background:var(--card); border:1px solid var(--line-strong); border-radius:50%;
    width:36px; height:36px; padding:0;
    color:var(--ink); font-family:'JetBrains Mono', monospace; font-size:13px;
    cursor:pointer; display:inline-flex; align-items:center; justify-content:center;
    flex-shrink:0;
  }
  .gallery-view-btn:focus{ outline:none; border-color:var(--gold); }
  .gallery-view-btn.active{ border-color:var(--gold); color:var(--gold); }
  @media (max-width:640px){
    .gallery-toolbar{ gap:8px; }
    .gallery-search{ flex:1 1 100%; font-size:16px; padding:10px 38px 10px 14px; }
    .gallery-search::placeholder{ font-size:11.5px; letter-spacing:0.01em; }
    .gallery-view-group{ margin-left:auto; }
    /* Telefon: podpowiedź + trzy przyciski gęstości w JEDNEJ linii między
       folderami a zdjęciami. Podpowiedź dostaje flex:1 i min-width:0, więc
       przyciski zostają zwarte przy prawej krawędzi, a tekst skraca się
       zamiast zawijać na drugi wiersz. */
    #galleryToolbarViewGroup{ display:none; }
    /* Telefon: wiersz sterowania stoi NAD okruszkami (nazwa katalogu), więc
       zaczyna się od góry — stąd ujemny margines górny, który podniesie go
       do wysokości nagłówka sekcji. Przełącznik miejscowości idzie w lewo,
       ikony gęstości zostają po prawej (space-between), tak jak w pasku
       narzędzi na desktopie, gdzie wyszukiwarka jest z lewej a sterowniki
       z prawej. */
    .gallery-view-group-inline{
      display:flex; flex-wrap:nowrap; gap:8px; justify-content:space-between;
      align-items:center; margin:-6px 0 10px;
    }
    /* Podpowiedź „uszczypnij, aby przybliżyć” jest zbędna — gest i tak działa,
       a w miejscu, które ma zająć, jest liczbie folderów do pokazania. */
    .gallery-view-group-inline .view-hint{ display:none; }
    /* Przełącznik miejscowości w jednym wierszu z ikonami gęstości. Może się
       kurczyć i skracać wielokropkiem, ale nigdy nie wypycha wiersza poza ekran. */
    .gallery-view-group-inline .gallery-view-btn{ width:34px; height:34px; }
    .gallery-view-group-inline .place-folders-btn{
      font-size:10.5px; padding:4px 10px; flex:0 1 auto;
    }
    /* W okruszkach ten sam przycisk tylko by się powtórzył — na telefonie jest
       w wierszu sterowania powyżej, wraz z ikonami gęstości. */
    .breadcrumb .place-folders-btn,
    .breadcrumb .place-sep{ display:none; }
  }
  .folder-card .folder-count{
    margin-left:auto; font-family:'JetBrains Mono', monospace; font-size:10.5px;
    color:var(--gold); flex-shrink:0;
  }
  .gmina-desc{
    font-size:13.5px; line-height:1.55; color:var(--ink-dim);
    margin:-6px 0 18px; max-width:54ch;
  }
  .featured-block{
    margin-bottom:28px; border:1px solid var(--line); border-radius:10px;
    overflow:hidden; background:var(--card); cursor:pointer;
  }
  .featured-block img{ width:100%; max-height:280px; object-fit:cover; display:block; }
  .featured-cap{
    padding:12px 16px; font-family:'JetBrains Mono', monospace; font-size:12px; color:var(--ink-dim);
  }
  .featured-cap b{ color:var(--ink); font-family:'Fraunces', serif; font-size:15px; font-weight:500; display:block; margin-bottom:2px; }
  .grid.compact{ --grid-min: 150px; gap:10px; }
  .grid.comfortable{ --grid-min: 280px; }
  /* Przyciski akcji zdjęcia (mapa, udostępnij, zgłoś, ulubione) mieszkają w panelu
     „i" — układ i rozmiar ustawia .lightbox-exif-actions. */
  .lightbox-share-btn{
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s, opacity .15s linear;
  }
  .lightbox-share-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-share-btn svg{ width:15px; height:15px; }
  .lightbox-map-btn{
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:none; align-items:center; justify-content:center;
    transition:border-color .2s, color .2s, opacity .15s linear;
  }
  .lightbox-map-btn:hover, .lightbox-map-btn.active{ border-color:var(--gold); color:var(--gold); }
  .lightbox-map-btn svg{ width:15px; height:15px; }
  .lightbox-map-panel{
    display:none; position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
    width:min(86vw, 620px); height:min(76vh, 540px); z-index:8;
    background:rgba(16,28,22,0.97); border:1px solid var(--line-strong);
    border-radius:14px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.45);
  }
  html[data-theme="light"] .lightbox-map-panel{ background:rgba(245,241,230,0.98); }
  .lightbox-map-panel.open{ display:flex; flex-direction:column; }
  .lightbox-map-head{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:10px 12px; border-bottom:1px solid var(--line);
    font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim);
  }
  .lightbox-map-close{
    width:26px; height:26px; border-radius:50%; border:1px solid var(--line-strong);
    background:transparent; color:var(--ink-dim); cursor:pointer; font-size:17px; line-height:1;
  }
  .lightbox-map-close:hover{ border-color:var(--gold); color:var(--gold); }
.lightbox-map-frame{
  display:block;
  flex:1;
  width:100%;
  min-height:320px;
  border:0;
  background:#dfe8df;
}
  .lightbox-map-foot{ padding:8px 12px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:16px; }
  .lightbox-map-foot a{ color:var(--gold); font:11px 'JetBrains Mono', monospace; text-decoration:none; }
  .lightbox-map-foot a:hover{ text-decoration:underline; }
  @media (max-width:640px){
    .lightbox-map-panel{ width:min(92vw, 620px); height:min(70vh, 540px); }
  }
  .share-modal-backdrop{
    display:none; position:fixed; inset:0; z-index:80;
    background:rgba(8,14,10,0.72); align-items:center; justify-content:center;
    padding:20px;
  }
  .share-modal-backdrop.open{ display:flex; }
  .share-modal{
    position:relative;
    background:var(--bg); border:1px solid var(--line-strong); border-radius:14px;
    width:min(400px, 100%); padding:22px 18px 18px;
    box-shadow:0 20px 50px rgba(0,0,0,0.45);
  }
  .share-modal-close{
    position:absolute; top:10px; right:10px;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card);
    color:var(--ink-dim); cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; line-height:1; padding:0;
  }
  .share-modal-close:hover{ border-color:var(--gold); color:var(--gold); }
  .share-modal h3{
    font-family:'Fraunces', serif; font-weight:500; font-size:18px;
    margin:0 40px 8px 0;
  }
  .share-modal-title{
    font-size:13px; color:var(--ink-dim); margin:0 0 14px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .share-modal-url{
    width:100%; background:var(--card); border:1px solid var(--line-strong);
    border-radius:10px; padding:10px 12px; color:var(--ink);
    font-family:'JetBrains Mono', monospace; font-size:11.5px;
    margin-bottom:14px; word-break:break-all;
  }
  .share-copy-btn{
    width:100%; background:var(--gold); color:#141c16; border:none;
    font-family:'Inter', sans-serif; font-weight:600; font-size:14px;
    padding:12px 16px; border-radius:24px; cursor:pointer;
  }
  .share-copy-btn:hover{ filter:brightness(1.06); }
  .share-copy-btn.copied{ background:var(--teal); color:#fff; }
  .lightbox-report-btn{
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s, opacity .15s linear;
  }
  .lightbox-report-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-report-btn svg{ width:15px; height:15px; }
  .report-modal-backdrop{
    display:none; position:fixed; inset:0; z-index:82;
    background:rgba(8,14,10,0.72); align-items:center; justify-content:center;
    padding:20px;
  }
  .report-modal-backdrop.open{ display:flex; }
  .report-modal{
    position:relative;
    background:var(--bg); border:1px solid var(--line-strong); border-radius:14px;
    width:min(440px, 100%); padding:22px 18px 18px;
    box-shadow:0 20px 50px rgba(0,0,0,0.45);
  }
  .report-modal-close{
    position:absolute; top:10px; right:10px;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card);
    color:var(--ink-dim); cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; line-height:1; padding:0;
  }
  .report-modal-close:hover{ border-color:var(--gold); color:var(--gold); }
  .report-modal h3{
    font-family:'Fraunces', serif; font-weight:500; font-size:18px;
    margin:0 40px 6px 0;
  }
  .report-modal-title{
    font-size:13px; color:var(--ink-dim); margin:0 0 14px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .report-field{ display:block; margin-bottom:12px; }
  .report-field span{
    display:block; font-family:'JetBrains Mono', monospace; font-size:10px;
    letter-spacing:0.06em; text-transform:uppercase; color:var(--ink-dim); margin-bottom:6px;
  }
  .report-field select, .report-field textarea{
    width:100%; background:var(--card); border:1px solid var(--line-strong);
    border-radius:10px; padding:10px 12px; color:var(--ink);
    font-family:'Inter', sans-serif; font-size:13px; resize:vertical;
  }
  .report-field textarea{ min-height:76px; }
  .report-modal-actions{ display:flex; gap:10px; margin-top:6px; }
  .report-cancel-btn{
    flex:0 0 auto; background:transparent; color:var(--ink-dim); border:1px solid var(--line-strong);
    font-family:'Inter', sans-serif; font-weight:600; font-size:14px;
    padding:12px 18px; border-radius:24px; cursor:pointer;
  }
  .report-cancel-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .report-submit-btn{
    flex:1; background:var(--gold); color:#141c16; border:none;
    font-family:'Inter', sans-serif; font-weight:600; font-size:14px;
    padding:12px 16px; border-radius:24px; cursor:pointer;
  }
  .report-submit-btn:hover{ filter:brightness(1.06); }
  .report-submit-btn:disabled{ opacity:.6; cursor:default; }
  .report-modal-msg{
    font-size:12px; margin:10px 0 0; min-height:1.1em; color:var(--ink-dim);
  }
  .report-modal-msg.err{ color:#e08b7a; }
  .report-modal-msg.ok{ color:var(--teal); }

  /* ---------- popup profilu użytkownika (kto dodał zdjęcie) ---------- */
  .user-modal-backdrop{
    display:none; position:fixed; inset:0; z-index:85;
    background:rgba(8,14,10,0.72); align-items:center; justify-content:center; padding:20px;
  }
  .user-modal-backdrop.open{ display:flex; }
  .user-modal{
    position:relative; background:var(--bg); border:1px solid var(--line-strong); border-radius:16px;
    width:min(520px, 100%); max-height:min(80vh, 640px); overflow-y:auto;
    padding:26px 22px 22px; box-shadow:0 20px 50px rgba(0,0,0,0.45);
  }
  .user-modal-close{
    position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card); color:var(--ink-dim);
    cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:20px; line-height:1; padding:0;
  }
  .user-modal-close:hover{ border-color:var(--gold); color:var(--gold); }
  .um-head{ display:flex; align-items:center; gap:16px; margin:0 40px 18px 0; }
  .um-avatar{
    width:60px; height:60px; border-radius:50%; padding:2px; flex-shrink:0;
    background:linear-gradient(135deg, var(--gold), var(--teal));
    position:relative;
  }
  .um-avatar div{
    width:100%; height:100%; border-radius:50%; background:var(--bg-alt);
    display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces', serif; font-size:20px; font-weight:600; color:var(--ink);
  }
  .um-avatar .um-photo{
    position:absolute; inset:2px; width:auto; height:auto;
    background-size:cover; background-position:center; background-repeat:no-repeat;
  }
  .um-name{ font-family:'Fraunces', serif; font-weight:500; font-size:19px; margin:0 0 3px; }
  .um-handle{ font-family:'JetBrains Mono', monospace; font-size:11.5px; color:var(--ink-dim); }
  .um-stats{ display:flex; gap:20px; margin:0 0 16px; }
  .um-stats div{ font-family:'JetBrains Mono', monospace; font-size:10.5px; color:var(--ink-dim); }
  .um-stats b{ display:block; font-family:'Fraunces', serif; font-size:18px; color:var(--ink); font-weight:500; }
  .um-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(84px, 1fr)); gap:8px; margin-bottom:16px; }
  .um-tile{
    position:relative; aspect-ratio:1/1; border-radius:6px; overflow:hidden; cursor:pointer;
    background:var(--card); border:1px solid var(--line);
  }
  .um-tile img{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .3s ease; }
  .um-tile:hover img{ transform:scale(1.08); }
  .um-more{ display:block; text-align:center; color:var(--gold); font-family:'JetBrains Mono', monospace; font-size:11.5px; text-decoration:none; }
  .um-more:hover{ text-decoration:underline; }
  .um-empty{ font-size:13px; color:var(--ink-dim); text-align:center; padding:14px 0; }
  .um-loading{ font-family:'JetBrains Mono', monospace; font-size:11.5px; color:var(--ink-dim); text-align:center; padding:30px 0; }

  /* ---------- popup „wszyscy użytkownicy” (lista z rangami) ---------- */
  .dir-modal-backdrop{
    display:none; position:fixed; inset:0; z-index:86;
    background:rgba(8,14,10,0.72); align-items:center; justify-content:center; padding:20px;
  }
  .dir-modal-backdrop.open{ display:flex; }
  .dir-modal{
    position:relative; background:var(--bg); border:1px solid var(--line-strong); border-radius:16px;
    width:min(560px, 100%); max-height:min(84vh, 720px); display:flex; flex-direction:column;
    padding:24px 20px 18px; box-shadow:0 20px 50px rgba(0,0,0,0.45);
  }
  .dir-modal-close{
    position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card); color:var(--ink-dim);
    cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:20px; line-height:1; padding:0;
  }
  .dir-modal-close:hover{ border-color:var(--gold); color:var(--gold); }
  .dir-modal h3{ font-family:'Fraunces', serif; font-weight:500; font-size:19px; margin:0 40px 4px 0; }
  .dir-modal-sub{ font-size:12.5px; color:var(--ink-dim); margin:0 0 14px; }
  .dir-toolbar{
    display:flex; flex-wrap:nowrap; align-items:center; gap:8px;
    margin-bottom:14px; min-width:0;
  }
  .dir-sort{ display:flex; border:1px solid var(--line-strong); border-radius:10px; overflow:hidden; flex:0 0 auto; }
  .dir-sort-btn{
    background:var(--card); border:none; color:var(--ink-dim); cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:10.5px; padding:8px 11px;
    transition:color .2s, background .2s; white-space:nowrap;
  }
  .dir-sort-btn + .dir-sort-btn{ border-left:1px solid var(--line-strong); }
  .dir-sort-btn.is-on{ background:var(--gold); color:#141c16; }
  .dir-sort-btn:hover:not(.is-on){ color:var(--gold); }
  .dir-search{
    flex:1 1 120px; min-width:80px; max-width:200px; background:var(--card);
    border:1px solid var(--line-strong); border-radius:8px; padding:7px 10px; color:var(--ink);
    font-family:'Inter', sans-serif; font-size:12px;
  }
  .dir-search:focus{ outline:none; border-color:var(--gold); }
  .dir-rank{
    flex:0 1 150px; min-width:110px; max-width:170px;
    background:var(--card); border:1px solid var(--line-strong); border-radius:10px;
    padding:7px 10px; color:var(--ink); font-family:'Inter', sans-serif; font-size:12px; cursor:pointer;
  }
  .dir-rank:focus{ outline:none; border-color:var(--gold); }
  @media (max-width:520px){
    .dir-toolbar{ flex-wrap:wrap; }
    .dir-search{ max-width:none; flex:1 1 100%; order:3; }
    .dir-rank{ max-width:none; flex:1 1 auto; }
  }
  .dir-list{ display:flex; flex-direction:column; gap:4px; overflow-y:auto; min-height:80px; }
  .dir-empty{ font-size:13px; color:var(--ink-dim); text-align:center; padding:30px 0; }
  .dir-item{
    display:flex; align-items:center; gap:12px; padding:9px 8px; border-radius:10px;
  }
  .dir-item:hover{ background:var(--card); }
  .dir-av{
    position:relative; width:38px; height:38px; border-radius:50%; flex-shrink:0; overflow:visible;
    background:var(--card); border:1px solid var(--line);
    display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces', serif; font-size:15px; font-weight:600; color:var(--ink);
  }
  .dir-av img{ width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; }
  .dir-av-inner{ width:100%; height:100%; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; }
  .dir-online{
    position:absolute; right:-1px; bottom:-1px; width:11px; height:11px; border-radius:50%;
    background:#3d9a6a; border:2px solid var(--bg); box-shadow:0 0 0 1px rgba(61,154,106,.35);
  }
  .dir-chip.online{ border-color:#3d9a6a; color:#3d9a6a; }
  .dir-chip.active-ago{ border-color:var(--line-strong); color:var(--ink-dim); text-transform:none; letter-spacing:0; font-size:10px; }
  .dir-info{ min-width:0; flex:1 1 auto; }
  .dir-name{
    font-family:'Fraunces', serif; font-size:15px; color:var(--ink); text-decoration:none;
    display:inline-block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  a.dir-name:hover{ color:var(--gold); }
  .dir-name.no-link{ color:var(--ink-dim); }
  .dir-meta{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:3px; }
  .dir-handle{ font-family:'JetBrains Mono', monospace; font-size:10.5px; color:var(--ink-dim); }
  .dir-chip{
    font-family:'JetBrains Mono', monospace; font-size:9.5px; text-transform:uppercase; letter-spacing:.04em;
    border:1px solid var(--line-strong); border-radius:999px; padding:1px 8px; color:var(--ink-dim);
  }
  .dir-chip.admin{ border-color:var(--gold); color:var(--gold); }
  .dir-chip.private{ border-color:var(--clay); color:var(--clay); }
  .dir-points{
    font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim);
    flex-shrink:0; text-align:right;
  }
  .dir-points b{ display:block; font-family:'Fraunces', serif; font-size:15px; color:var(--ink); font-weight:500; }
  .dir-more{
    margin-top:12px; width:100%; background:var(--card); border:1px solid var(--line-strong);
    border-radius:10px; padding:10px; color:var(--ink); cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:11.5px;
  }
  .dir-more:hover{ border-color:var(--gold); color:var(--gold); }

  .comment-honeypot{ position:absolute; left:-9999px; opacity:0; height:0; width:0; overflow:hidden; }
  .map-card svg .c-town-label, .map-card svg .c-mogilno-label{ cursor:pointer; }
  .map-card svg .c-town-label:hover, .map-card svg .c-mogilno-label:hover{ fill:var(--gold); }

  /* ---------- gallery section ---------- */
  .gallery-section{ padding:54px 0 80px; }
  .gallery-head{
    display:flex; align-items:baseline; justify-content:space-between;
    flex-wrap:wrap; gap:14px; margin-bottom:28px;
  }
  .gallery-head h2{
    font-family:'Fraunces', serif; font-weight:500; font-size:26px; margin:0;
  }
  .sync-status{
    font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim);
    display:flex; align-items:center; gap:8px;
  }
  .sync-dot{ width:7px; height:7px; border-radius:50%; background:var(--teal); }
  .sync-dot.busy{ background:var(--gold); animation:pulse 1.2s ease-in-out infinite; }
  @keyframes pulse{ 0%,100%{ opacity:1; } 50%{ opacity:.35; } }
  .refresh-btn{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    font-family:'JetBrains Mono', monospace; font-size:11px;
    padding:6px 12px; border-radius:20px; cursor:pointer;
    transition: color .2s, border-color .2s;
  }
  .refresh-btn:hover{ color:var(--gold); border-color:var(--gold); }

  .breadcrumb{
    display:flex; align-items:center; flex-wrap:wrap; gap:6px;
    font-family:'JetBrains Mono', monospace; font-size:12px; color:var(--ink-dim);
    margin-bottom:22px;
  }
  .breadcrumb button{
    background:none; border:none; color:var(--ink-dim); cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:12px; padding:2px 4px;
  }
  .breadcrumb button:hover{ color:var(--gold); }
  /* Przełącznik folderów miejscowości. Styl przejęty ze sterownika galerii
     (.gallery-view-btn): obwódka, krój mono, zaokrąglona
     „pigułka”. Jest WSPÓLNY dla obu kopii — w okruszkach (desktop) i we wierszu
     sterowania nad nimi (telefon). Dawniej był przypisany tylko do .breadcrumb,
     przez co kopia mobilna wychodziła bez obwódki i wyglądała jak surowy tekst. */
  .place-folders-btn{
    display:inline-flex; align-items:center; min-width:0;
    background:var(--card); border:1px solid var(--line-strong);
    border-radius:20px; padding:3px 11px;
    color:var(--ink-dim); cursor:pointer; flex-shrink:0;
    font-family:'JetBrains Mono', monospace; font-size:11px; letter-spacing:0.03em;
  }
  .place-folders-btn:hover{ color:var(--gold); border-color:var(--gold); }
  .place-folders-btn:focus{ outline:none; border-color:var(--gold); }
  /* Etykieta w osobnym <span>, bo text-overflow działa na elemencie blokowym —
     bez tego przycisk ucinałby tekst bez wielokropka. */
  .place-folders-btn .pf-label{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .place-folders-btn.is-hidden-state{ border-color:var(--clay); color:var(--clay); }
  .breadcrumb .crumb-sep{ color:var(--line-strong); }
  .breadcrumb .crumb-current{ color:var(--ink); }

  .folder-grid{
    display:grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap:12px; margin-bottom:26px;
  }
  .folder-card{
    display:flex; align-items:center; gap:10px;
    padding:14px 16px; border:1px solid var(--line); border-radius:6px;
    background:var(--card); cursor:pointer;
    transition: border-color .2s, transform .15s;
  }
  .folder-card:hover{ border-color:var(--gold); transform:translateY(-1px); }
  .folder-card svg{ flex-shrink:0; color:var(--gold); }
  .folder-card span{ font-size:13.5px; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

  .recent-by-folder{
    display:flex; flex-direction:column; gap:24px;
    margin-bottom:34px; padding-bottom:26px;
    border-bottom:1px solid var(--line);
  }
  .recent-folder-row{ display:block; }
  .recent-folder-name{
    display:inline-flex; align-items:center; gap:8px;
    font-family:'JetBrains Mono', monospace; font-size:12px;
    letter-spacing:0.04em; text-transform:uppercase;
    color:var(--ink); font-weight:500; cursor:pointer;
    margin-bottom:10px; transition:color .2s;
  }
  .recent-folder-name:hover{ color:var(--gold); }
  .recent-folder-name::after{ content:"→"; opacity:0.45; font-size:11px; }
  .recent-folder-strip{
    display:flex; gap:8px; overflow-x:auto; overflow-y:hidden;
    padding-bottom:6px; scrollbar-width:thin; scrollbar-color:var(--line-strong) var(--bg-alt);
    touch-action:manipulation;
  }
  /* Gęstość siatki (ikony przy wyszukiwarce) na stronie głównej zmienia rozmiar
     miniatur w paskach gmin. Pasek nadal przewija się w bok (overflow-x:auto,
     miniatury flex:0 0 auto). Zmienną ustawiamy na kontenerze, a gest
     uszczypnięcia może ją nadpisać inline na pojedynczym pasku. */
  .recent-by-folder.compact{ --strip-size:64px; }
  .recent-by-folder.comfortable{ --strip-size:140px; }
  @media (max-width:640px){
    .recent-by-folder.comfortable{ --strip-size:120px; }
  }
  .recent-folder-strip::-webkit-scrollbar{ height:5px; }
  .recent-folder-strip::-webkit-scrollbar-track{ background:var(--bg-alt); border-radius:4px; }
  .recent-folder-strip::-webkit-scrollbar-thumb{ background:var(--line-strong); border-radius:4px; }
  .strip-thumb{
    flex:0 0 auto; width:var(--strip-size, 88px); height:var(--strip-size, 88px); border-radius:4px; overflow:hidden;
    border:1px solid var(--line); cursor:pointer; background:var(--card);
    transition:width .12s ease, height .12s ease;
  }
  .strip-thumb img{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .35s ease; }
  .strip-thumb:hover img{ transform:scale(1.08); }
  .strip-thumb.skeleton{ background:linear-gradient(100deg, var(--card) 30%, #1e3025 50%, var(--card) 70%); background-size:200% 100%; animation:shimmer 1.6s infinite; }
  .strip-loading, .strip-empty{
    font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim);
    padding:10px 0;
  }

  .grid{
    display:grid; grid-template-columns: repeat(auto-fill, minmax(min(var(--grid-min, 230px), 100%), 1fr));
    gap:18px; touch-action:manipulation;
  }
  .gallery-more-row{ display:flex; justify-content:center; margin:18px 0 4px; }
  .gallery-more{
    position:relative; isolation:isolate; overflow:hidden; display:inline-flex; align-items:center; justify-content:center; gap:8px;
    min-height:44px; max-width:100%; padding:7px 17px; border:1px solid var(--gold); border-radius:999px;
    background:var(--card); color:var(--ink); cursor:pointer; touch-action:manipulation;
    font-family:'Inter',sans-serif; font-size:12.5px; font-weight:650; letter-spacing:.01em;
    box-shadow:0 3px 12px rgba(0,0,0,.10); transition:transform .2s ease,box-shadow .2s ease,color .2s ease,border-color .2s ease;
  }
  .gallery-more::before{
    content:""; position:absolute; z-index:0; inset:-20% auto -20% -50%; width:35%; pointer-events:none;
    background:linear-gradient(100deg,transparent,rgba(255,255,255,.42),transparent);
    transform:skewX(-22deg); animation:galleryMoreShine 5s ease-in-out infinite;
  }
  .gallery-more:hover{ transform:translateY(-2px); color:var(--gold); box-shadow:0 8px 20px rgba(0,0,0,.16); }
  .gallery-more:active{ transform:translateY(0) scale(.98); }
  .gallery-more:focus-visible{ outline:3px solid var(--gold); outline-offset:3px; }
  .gallery-more-icon{ display:inline-grid; place-items:center; width:23px; height:23px; flex:0 0 23px; border-radius:50%; background:rgba(201,162,39,.16); color:var(--gold); font-size:18px; line-height:1; }
  .gallery-more-count{ padding:4px 7px; border-radius:999px; background:rgba(201,162,39,.14); color:var(--gold); font:600 10.5px/1 'JetBrains Mono',monospace; }
  .gallery-more-remaining{ color:var(--ink-dim); font:10px/1.3 'JetBrains Mono',monospace; }
  .gallery-more-arrow{ color:var(--gold); transition:transform .22s ease; }
  .gallery-more:hover .gallery-more-arrow{ transform:translateY(3px); }
  .gallery-more[hidden]{ display:none; }
  @keyframes galleryMoreShine{
    0%,68%,100%{ transform:translateX(0) skewX(-22deg); opacity:0; }
    74%{ opacity:.9; }
    94%{ transform:translateX(470%) skewX(-22deg); opacity:0; }
  }
  @media (max-width:380px){
    .gallery-more{ min-height:40px; gap:6px; padding-inline:12px; font-size:12px; }
    .gallery-more-remaining{ font-size:9px; }
  }
  @media (prefers-reduced-motion:reduce){
    .gallery-more,.gallery-more::before,.gallery-more-arrow{ animation:none!important; transition:none!important; }
  }
  /* min(... , 100%) w grid-template powyzej to zabezpieczenie: gdy --grid-min
     jest wiekszy od szerokosci kolumny (gest pinchu, bardzo waski telefon),
     kolumna dostaje 100% zamiast --grid-min i nic nie wychodzi poza ekran. */
  .card{
    position:relative; aspect-ratio:4/5; border-radius:4px; overflow:hidden;
    background:var(--card); border:1px solid var(--line);
    cursor:pointer;
  }
  .card img{
    width:100%; height:100%; object-fit:cover; display:block;
    transition: transform .5s ease;
  }
  .card:hover img{ transform:scale(1.05); }
  /* Podpis leży na gradientcie, który jest zawsze ciemny — niezależnie od motywu.
     Dlatego kolory napisów też są na stałe jasne (dokładnie te z trybu ciemnego),
     tak jak wcześniej wyglądało. Wcześniej szły z --ink/--ink-dim, a w trybie
     jasnym to niemal czerń — czarny napis na czarnym tle był niewidoczny. */
  .card .cap{
    position:absolute; left:0; right:0; bottom:0;
    padding:10px 12px 10px;
    background:linear-gradient(to top, rgba(16,28,22,0.92), transparent);
    font-family:'JetBrains Mono', monospace; font-size:10.5px; color:#96a397;
    opacity:0; transform:translateY(6px);
    transition: opacity .2s, transform .2s;
  }
  .card:hover .cap{ opacity:1; transform:translateY(0); }
  .card .cap b{ display:block; color:#ece6d6; font-size:11.5px; font-weight:500; margin-bottom:2px; }

  @media (max-width:640px){
    .card .cap{ opacity:1; transform:translateY(0); }
  }

  /* Telefon: mniejsze miniaturki, wiecej zdjec na ekranie.
     Pinch-to-zoom ustawia --grid-min inline na samym .grid (wczesniej na
     <html> — przez to zapis z pinch blokowal te trzy reguly i przyciski
     gestosci nie robily nic). Inline na .grid wygrywa, ale tylko gdy pinch
     faktycznie cos ustawil; przycisk gestosci kasuje zapis, wiec ponizsze
     klasy znowu obowiazuja.

     WARTOSCI KOLUMN na typowym telefonie (kontener ~335px, gap 8px):
       default     145px -> 2 kolumny (dwie najlepsze miniatury w linii),
       compact      92px -> 3 kolumny,
       comfortable 180px -> 1 kolumna.
     Przy 145px trzecia kolumna nie mieści sie (3*145+2*8=451 > 335),
     a druga tak (2*145+8=298 <= 335), wiec „domyślny” to zawsze 2. */
  @media (max-width:640px){
    .grid{ --grid-min: 145px; gap:8px; }
    .grid.compact{ --grid-min: 92px; gap:6px; }
    .grid.comfortable{ --grid-min: 180px; }
    .card{ aspect-ratio:1/1; }
    .card .cap{ padding:7px 8px 6px; font-size:9.5px; }
    .card .cap b{ font-size:10.5px; margin-bottom:1px; }
    .card-author, .strip-author{ font-size:9px; padding:3px 8px 3px 3px; max-width:calc(100% - 10px); }
  }

  /* ---------- odznaka „dodał/a: …” na kafelku zdjęcia ---------- */
  .card-author, .strip-author{
    position:absolute; z-index:2; display:inline-flex; align-items:center; gap:5px;
    background:rgba(16,28,22,0.82); backdrop-filter:blur(3px);
    border:1px solid var(--line-strong); border-radius:20px;
    padding:4px 10px 4px 4px; cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:10px; color:var(--ink-dim);
    text-decoration:none; max-width:calc(100% - 16px); transition:border-color .15s, color .15s;
  }
  html[data-theme="light"] .card-author, html[data-theme="light"] .strip-author{ background:rgba(245,241,230,0.88); }
  .card-author:hover, .strip-author:hover{ border-color:var(--gold); color:var(--gold); }
  .card-author .aa-dot, .strip-author .aa-dot{
    width:18px; height:18px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg, var(--gold), var(--teal));
    display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces', serif; font-size:8.5px; font-weight:600; color:#101c16;
    position:relative; overflow:hidden;
  }
  /* zdjęcie autora wypełnia kółeczek odznaki; inicjały chowamy */
  .aa-photo{
    position:absolute; inset:0; border-radius:50%;
    background-size:cover; background-position:center; background-repeat:no-repeat;
  }
  .aa-initials{ position:relative; z-index:1; }
  .card-author .aa-name, .strip-author .aa-name{
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .card-author{ top:8px; left:8px; }
  .strip-author{ top:6px; left:6px; padding:3px 9px 3px 3px; }
  .strip-author .aa-dot{ width:15px; height:15px; font-size:7.5px; }

  .skeleton{ background:linear-gradient(100deg, var(--card) 30%, #1e3025 50%, var(--card) 70%); background-size:200% 100%; animation:shimmer 1.6s infinite; }
  @keyframes shimmer{ to{ background-position:-200% 0; } }

  /* ---------- odsłanianie kafelków przy przewijaniu (czysty CSS) ----------
     Domyślnie widoczne od razu — bezpieczne dla każdej przeglądarki. Tam,
     gdzie animation-timeline:view() jest obsługiwane, kafelek odsłania się
     dokładnie w momencie wjechania w kadr, bez JS i bez nasłuchiwania scroll. */
  .card:not(.skeleton){ opacity:1; }
  @supports (animation-timeline: view()){
    .card:not(.skeleton){ opacity:0; animation:card-reveal linear both; animation-timeline:view(); animation-range:entry 0% cover 35%; }
    /* Podczas wyszukiwania kafelki wracają w krótkim czasie przy kazdym
       nacisnieciu klawisza, a animacja translateY przesuwala siatke pod
       otwarta klawiatura — strona „skacze” i pole wyszukiwania ucieka.
       W trakcie pisania wyniki sa zwykle poza kadrem, wiec bez animacji. */
    .searching .card:not(.skeleton){ opacity:1; animation:none; transform:none; }
  }
  @keyframes card-reveal{ from{ opacity:0; transform:translateY(18px); } to{ opacity:1; transform:translateY(0); } }
  @media (prefers-reduced-motion:reduce){ .card{ opacity:1 !important; animation:none !important; transform:none !important; } }

  /* ---------- płynne pojawianie się zdjęć po wczytaniu ---------- */
  .card img{ opacity:0; transition: opacity .35s ease, transform .5s ease; }
  .card img.loaded{ opacity:1; }
  .card img[loading]:not([src]){ opacity:0; }

  /* ---------- empty / disconnected state ---------- */
  .empty{
    border:1px dashed var(--line-strong); border-radius:8px;
    padding:52px 32px; text-align:center;
  }
  .empty h3{ font-family:'Fraunces', serif; font-weight:500; font-size:20px; margin:0 0 8px; }
  .empty p{ color:var(--ink-dim); font-size:14px; max-width:44ch; margin:0 auto 20px; line-height:1.6; }
  .empty button{
    background:var(--gold); color:#141c16; border:none; font-weight:600;
    font-size:13px; padding:11px 22px; border-radius:24px; cursor:pointer;
    font-family:'Inter', sans-serif;
  }

  .error-box{
    border:1px solid rgba(166,90,60,0.5); background:rgba(166,90,60,0.08);
    border-radius:6px; padding:16px 18px; font-size:13px; color:#e0b6a4;
    margin-bottom:20px; line-height:1.6;
  }
  .error-box b{ color:#f0c9ba; }

  footer{
    border-top:1px solid var(--line); padding:26px 0; text-align:center;
    font-size:11px; color:var(--ink-dim); font-family:'JetBrains Mono', monospace;
  }
  footer .footer-nav a{
    color:var(--ink-dim); text-decoration:none;
    border-bottom:1px dotted var(--line-strong); padding-bottom:1px;
    transition: color .2s, border-color .2s;
  }
  footer .footer-nav a:hover{ color:var(--gold); border-color:var(--gold); }
  footer .footer-nav .footer-link{
    background:none; border:none; padding:0 0 1px; cursor:pointer;
    color:var(--ink-dim); font:inherit;
    border-bottom:1px dotted var(--line-strong);
    transition: color .2s, border-color .2s;
  }
  footer .footer-nav .footer-link:hover{ color:var(--gold); border-color:var(--gold); }

  /* ---------- popup „kontakt” (e-mail + wiadomość do administratora) ---------- */
  .contact-modal-backdrop{
    display:none; position:fixed; inset:0; z-index:86;
    background:rgba(8,14,10,0.72); align-items:center; justify-content:center; padding:20px;
  }
  .contact-modal-backdrop.open{ display:flex; }
  .contact-modal{
    position:relative; background:var(--bg); border:1px solid var(--line-strong); border-radius:16px;
    width:min(440px, 100%); padding:24px 20px 18px; box-shadow:0 20px 50px rgba(0,0,0,0.45);
  }
  .contact-modal-close{
    position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card); color:var(--ink-dim);
    cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:20px; line-height:1; padding:0;
  }
  .contact-modal-close:hover{ border-color:var(--gold); color:var(--gold); }
  .contact-modal h3{ font-family:'Fraunces', serif; font-weight:500; font-size:19px; margin:0 40px 4px 0; }
  .contact-modal-sub{ font-size:12.5px; color:var(--ink-dim); margin:0 0 6px; }
  .contact-label{ display:block; font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim); margin:12px 0 6px; }
  .contact-input{
    width:100%; box-sizing:border-box; background:var(--card); border:1px solid var(--line-strong);
    border-radius:10px; padding:10px 12px; color:var(--ink); font-family:'Inter', sans-serif; font-size:14px;
  }
  .contact-input:focus{ outline:none; border-color:var(--gold); }
  .contact-text{ resize:vertical; min-height:110px; }
  .contact-msg{ font-size:12.5px; margin:10px 0 0; }
  .contact-msg.is-error{ color:#d0573f; }
  .contact-send{
    margin-top:14px; width:100%; background:var(--gold); color:#141c16; border:none;
    font-family:'Inter', sans-serif; font-weight:600; font-size:14px; padding:12px 18px; border-radius:24px; cursor:pointer;
  }
  .contact-send:disabled{ opacity:.6; cursor:wait; }

  /* ---------- pływające przyciski: motyw + strona główna ---------- */
  .floating-btns{
    position:fixed;
    top:calc(14px + env(safe-area-inset-top, 0px));
    left:calc(14px + env(safe-area-inset-left, 0px));
    z-index:50;
    display:flex; align-items:center; gap:8px;
    -webkit-backface-visibility:hidden;
    backface-visibility:hidden;
  }
  .theme-toggle, .home-toggle{
    position:relative;
    width:40px; height:40px;
    border-radius:50%;
    border:1px solid var(--line-strong);
    background:var(--card);
    color:var(--ink);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    flex-shrink:0;
    box-shadow:0 4px 16px rgba(0,0,0,0.25);
    transition: border-color .2s, color .2s, background .2s;
    text-decoration:none;
    padding:0;
  }
  .theme-toggle:hover, .home-toggle:hover{ border-color:var(--gold); color:var(--gold); }
  .theme-toggle svg, .home-toggle svg{ width:18px; height:18px; }
  .theme-toggle .icon-sun{ display:none; }
  .theme-toggle .icon-moon{ display:block; }
  html[data-theme="light"] .theme-toggle .icon-sun{ display:block; }
  html[data-theme="light"] .theme-toggle .icon-moon{ display:none; }
  .acct-toggle{ position:relative; }
  .acct-toggle.is-logged-in{ border-color:var(--gold); }
  .acct-toggle .acct-dot{
    position:absolute; top:1px; right:1px; width:9px; height:9px; border-radius:50%;
    background:var(--gold); border:2px solid var(--bg); display:none; pointer-events:none;
  }
  .acct-toggle.has-unread .acct-dot{ display:block; }
  @media (max-width:640px){
    .floating-btns{
      top:calc(10px + env(safe-area-inset-top, 0px));
      left:calc(10px + env(safe-area-inset-left, 0px));
      gap:7px;
    }
    .theme-toggle, .home-toggle{
      width:36px; height:36px;
    }
  }


  /* ---------- odznaka „dodał/a” — tylko w powiększonym zdjęciu ---------- */
  .lightbox-author{
    position:absolute; bottom:54px; left:0; right:0; z-index:2;
    display:none; justify-content:center; pointer-events:none;
  }
  .lightbox-author.has-author{ display:flex; }
  .lightbox-author .lb-author-badge{
    pointer-events:auto; display:inline-flex; align-items:center; gap:6px;
    background:rgba(16,28,22,0.78); backdrop-filter:blur(3px);
    border:1px solid var(--line-strong); border-radius:20px;
    padding:4px 12px 4px 4px; cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim);
    text-decoration:none; max-width:calc(100% - 40px);
    transition:border-color .15s, color .15s;
  }
  .lightbox-author .lb-author-badge:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-author .aa-dot{
    width:20px; height:20px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg, var(--gold), var(--teal));
    display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces', serif; font-size:9px; font-weight:600; color:#101c16;
    position:relative; overflow:hidden;
  }
  .lightbox-author .aa-name{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

  /* ---------- opis zdjęcia — półprzezroczysta plakietka w powiększeniu ---------- */
  .lightbox-desc{
    position:absolute; z-index:3; display:none;
    max-width:min(560px, calc(100% - 32px));
    padding:9px 15px 10px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,0.18);
    background:rgba(12,20,16,0.58);
    -webkit-backdrop-filter:blur(9px) saturate(1.15);
    backdrop-filter:blur(9px) saturate(1.15);
    color:#f4efe1;
    font-family:'Fraunces', Georgia, serif;
    font-size:13px; line-height:1.45; letter-spacing:.01em;
    text-align:center; text-wrap:balance;
    text-shadow:0 1px 2px rgba(0,0,0,.55);
    box-shadow:0 10px 30px rgba(0,0,0,.28);
    pointer-events:none; overflow-wrap:anywhere;
  }
  .lightbox-desc.open{ display:block; animation:lbDescIn .22s ease-out; }
  @keyframes lbDescIn{ from{ opacity:0; transform:translateY(4px); } to{ opacity:1; transform:translateY(0); } }
  html[data-theme="light"] .lightbox-desc{
    background:rgba(255,255,255,0.68);
    border-color:rgba(20,30,24,0.16);
    color:#253229; text-shadow:none;
    box-shadow:0 10px 30px rgba(40,50,40,.18);
  }
  @media (max-width:640px){
    .lightbox-desc{ font-size:12px; padding:8px 12px; border-radius:10px; }
  }
  @media (prefers-reduced-motion:reduce){
    .lightbox-desc.open{ animation:none; }
  }

  /* ---------- lightbox w trybie jasnym: jasne tło ---------- */
   html[data-theme="light"] .lightbox{ background:#f5f1e6; }
  html[data-theme="light"] .lightbox-nav,
  html[data-theme="light"] .lightbox-info-btn,
  html[data-theme="light"] .lightbox-fav-btn,
  html[data-theme="light"] .lightbox-like-btn,
  html[data-theme="light"] .lightbox-comments-btn,
  html[data-theme="light"] .lightbox-share-btn,
  html[data-theme="light"] .lightbox-map-btn,
  html[data-theme="light"] .lightbox-report-btn,
  html[data-theme="light"] .lightbox-author .lb-author-badge{ background:rgba(255,255,255,0.88); }
  html[data-theme="light"] .lightbox-close{ color:var(--ink); }
  html[data-theme="light"] .lightbox-like-btn{ color:var(--ink-dim); }
  html[data-theme="light"] .lightbox video{ background:#e9e3d0; }

  /* ---------- nagłówek: nazwa nie może wchodzić pod pływające przyciski (telefon) ---------- */
  @media (max-width:640px){
    header.top .brand-block{ margin-left:112px !important; min-width:0; }
    header.top .coords{ font-size:10px; line-height:1.35; }
  }

  /* ---------- lightbox ---------- */
  .lightbox{
     position:fixed; inset:0; background:#080e0a;
    display:none; align-items:center; justify-content:center; z-index:60; padding:30px;
    overflow:hidden; touch-action:auto;
  }
  .lightbox.open{ display:flex; animation:lbFade .18s ease-out; }
  .lightbox.open img{ animation:lbPop .24s cubic-bezier(.2,.8,.2,1); cursor:zoom-in; }
  @keyframes lbFade{ from{ opacity:0; } to{ opacity:1; } }
  @keyframes lbPop{ from{ opacity:0; transform:scale(.97); } to{ opacity:1; transform:translate(0,0) scale(1); } }
  @media (prefers-reduced-motion: reduce){ .lightbox.open, .lightbox.open img{ animation:none; } }
  .lightbox img{
    max-width:100%; max-height:82vh; border-radius:4px;
    touch-action:none; user-select:none; -webkit-user-drag:none;
    will-change:transform; transform:translate(0,0) scale(1);
  }

  .lightbox-cap{ position:absolute; bottom:24px; left:0; right:0; text-align:center; font-family:'JetBrains Mono', monospace; font-size:12px; color:var(--ink-dim); transition:opacity .15s linear; }
  .lightbox-info-btn{
    position:absolute; top:22px; left:28px; z-index:2;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); font-family:'Fraunces', serif; font-size:13px; font-style:italic;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s, opacity .15s linear;
  }
  .lightbox-info-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-info-btn.active{ border-color:var(--gold); color:var(--gold); }
  .lightbox-exif{
    display:none;
    position:absolute; top:64px; left:28px; z-index:2;
    max-width:min(82vw, 340px);
    flex-direction:column; gap:5px;
    font-family:'JetBrains Mono', monospace; font-size:10.5px;
    color:var(--ink-dim); letter-spacing:0.02em;
    background:rgba(16,28,22,0.78); border:1px solid var(--line-strong); border-radius:8px;
    padding:10px 12px;
  }
  html[data-theme="light"] .lightbox-exif{ background:rgba(245,241,230,0.9); }
  .lightbox-exif.open{ display:flex; }
  /* Wiersz akcji zdjęcia na górze panelu „i" (mapa, udostępnij, zgłoś, ulubione).
     To te same przyciski, co w górnym pasku lightboxu — tylko przeniesione. */
  .lightbox-exif-actions{
    display:flex; align-items:stretch; gap:6px;
    padding-bottom:8px; border-bottom:1px solid var(--line);
  }
  .lightbox-exif-actions .lightbox-map-btn,
  .lightbox-exif-actions .lightbox-share-btn,
  .lightbox-exif-actions .lightbox-report-btn,
  .lightbox-exif-actions .lightbox-fav-btn{
    position:static; top:auto; left:auto; right:auto; z-index:auto;
    flex:1 1 0; min-width:0; width:auto; height:auto; min-height:46px;
    border-radius:10px; padding:7px 4px;
    flex-direction:column; gap:4px;
    font-family:'JetBrains Mono', monospace; font-size:9px;
    letter-spacing:.05em; text-transform:uppercase; line-height:1.15;
  }
  .lightbox-exif-actions .lb-act-label{
    display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis;
  }
  .lightbox-exif-actions .lightbox-map-btn svg,
  .lightbox-exif-actions .lightbox-share-btn svg,
  .lightbox-exif-actions .lightbox-report-btn svg{ width:16px; height:16px; }
  @media (max-width:520px){
    .lightbox-exif-actions{ flex-wrap:wrap; }
    .lightbox-exif-actions .lightbox-map-btn,
    .lightbox-exif-actions .lightbox-share-btn,
    .lightbox-exif-actions .lightbox-report-btn,
    .lightbox-exif-actions .lightbox-fav-btn{ flex:1 1 calc(50% - 3px); }
  }
  .lightbox-exif span{ white-space:nowrap; }
  .lightbox-exif .exif-row{ display:flex; flex-wrap:wrap; gap:4px 10px; }
  .lightbox-exif .exif-h{
    font-size:9px; letter-spacing:0.09em; text-transform:uppercase; color:var(--ink-dim); opacity:.75;
    margin-top:4px;
  }
  .lightbox-exif .exif-h:first-child{ margin-top:0; }
  .lightbox-exif .exif-camera{ color:var(--ink); }
  .lightbox-exif .exif-edit{
    display:flex; align-items:center; gap:7px; color:var(--ink); font-size:11px; white-space:normal;
  }
  .lightbox-exif .exif-dot{ width:9px; height:9px; border-radius:50%; flex-shrink:0; background:var(--ink-dim); }
  .lightbox-exif .exif-edit.st-original .exif-dot{ background:#5fbf7f; }
  .lightbox-exif .exif-edit.st-suspect .exif-dot{ background:#e0a93a; }
  .lightbox-exif .exif-edit.st-edited .exif-dot{ background:#d9634c; }
  .lightbox-exif .exif-note{ font-size:10px; line-height:1.45; color:var(--ink-dim); white-space:normal; overflow-wrap:anywhere; }
  .lightbox-close{ position:absolute; top:22px; right:28px; color:var(--ink-dim); background:none; border:none; font-size:26px; cursor:pointer; z-index:2; transition:opacity .15s linear; }
  .lightbox-nav{
    position:absolute; top:50%; transform:translateY(-50%);
    width:48px; height:48px; border-radius:50%;
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink); font-size:26px; line-height:1; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s, background .2s;
  }
  .lightbox-nav:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-nav:disabled{ opacity:0.25; cursor:default; }
  .lightbox-nav:disabled:hover{ border-color:var(--line-strong); color:var(--ink); }
  .lightbox-prev{ left:20px; }
  .lightbox-next{ right:20px; }
  @media (max-width:640px){
    .lightbox-nav{ display:none; }
  }
  @media (hover:none) and (pointer:coarse){
    .lightbox-nav{ display:none; }
  }

  /* ---------- mini-gry: przyciski CTA ---------- */
  .game-cta-row{ margin-top:18px; display:flex; flex-wrap:wrap; gap:10px; }
  .game-cta{
    display:inline-flex; align-items:center; gap:8px;
    background:none; border:1px solid var(--gold); color:var(--gold);
    font-family:'JetBrains Mono', monospace; font-size:11.5px; letter-spacing:0.03em;
    padding:9px 16px; border-radius:24px; cursor:pointer;
    transition: background .2s, color .2s;
  }
  .game-cta:hover{ background:var(--gold); color:#141c16; }
  .game-cta svg{ width:14px; height:14px; flex-shrink:0; }
  .game-cta.alt{ border-color:var(--teal); color:var(--teal); }
  .game-cta.alt:hover{ background:var(--teal); color:#0d1512; }
  /* „dodaj zdjęcie” to link, a .game-cta ma inline-flex — bez tego atrybut
     hidden by nie ukrył przycisku, a link dostałby podkreślenie. */
  .game-cta[hidden]{ display:none; }
  a.game-cta{ text-decoration:none; }

  /* Telefon: oba przyciski mają stać w jednej linii. Sama etykieta „zgadnij
     gminę” daje razem ok. 306 px, a wiersz na ekranie 360 px ma 304 px — wychodzi
     o grubość włoska, więc na wąskich ekranach ściskamy odstępy tylko w tym
     rzędzie (marginesy całej strony zostają nietknięte). `nowrap` pilnuje, żeby
     etykieta nie rozpadła się w połowie zdania. */
  @media (max-width:640px){
    .game-cta-row{ gap:8px; }
    .game-cta{ padding:9px 11px; gap:7px; white-space:nowrap; }
  }

  /* ---------- magnetyczna poświata na głównym CTA (jeden, celowy moment) ---------- */
  .game-cta.magnetic{ position:relative; isolation:isolate; overflow:hidden; will-change:transform; }
  .game-cta.magnetic::before{
    content:''; position:absolute; inset:-60%; z-index:-1; opacity:0;
    background:radial-gradient(120px circle at var(--mx,50%) var(--my,50%), rgba(201,162,39,0.55), transparent 70%);
    transition:opacity .3s ease;
  }
  .game-cta.magnetic:hover::before{ opacity:1; }
  @media (hover:none){ .game-cta.magnetic::before{ display:none; } }


  dialog.guess-dialog{
    border:none; border-radius:12px; padding:0; background:var(--bg); color:var(--ink);
    width:min(460px, 92vw); max-height:88vh; overflow:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.45);
  }
  dialog.guess-dialog::backdrop{ background:rgba(8,14,10,0.72); backdrop-filter:blur(2px); }
  dialog.guess-dialog[open]{ animation: guess-pop .22s cubic-bezier(.22,.61,.36,1); }
  @keyframes guess-pop{ from{ opacity:0; transform:scale(.96) translateY(6px); } to{ opacity:1; transform:none; } }

  .guess-head{ position:relative; display:flex; align-items:baseline; justify-content:space-between; gap:10px; padding:18px 44px 0 20px; }
  .guess-title{ font-family:'Fraunces', serif; font-size:18px; font-weight:500; }
  .guess-score{ font-size:11px; color:var(--ink-dim); white-space:nowrap; }
  .guess-score b{ color:var(--gold); font-weight:600; }
  .guess-close{
    position:absolute; top:12px; right:14px; z-index:3;
    background:none; border:none;
    color:var(--ink-dim); font-size:22px; line-height:1; cursor:pointer; padding:6px;
  }
  .guess-close:hover{ color:var(--gold); }

  .guess-photo-wrap{
    position:relative; margin:16px 20px 0; border-radius:8px; overflow:hidden;
    background:var(--card); aspect-ratio:4/3;
  }
  .guess-photo-wrap img{ width:100%; height:100%; object-fit:cover; display:block; }
  .guess-feedback{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    text-align:center; padding:16px;
    font-family:'Fraunces', serif; font-size:20px; font-weight:500; color:#fff;
    opacity:0; transform:scale(.97); pointer-events:none;
    transition:opacity .18s ease, transform .18s ease;
  }
  .guess-feedback.show{ opacity:1; transform:none; }
  .guess-feedback.correct{ background:rgba(90,138,134,0.85); }
  .guess-feedback.incorrect{ background:rgba(166,90,60,0.85); }

  .guess-options{ display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:16px 20px; }
  @media (max-width:420px){ .guess-options{ grid-template-columns:1fr; } }
  .guess-opt{
    border:1px solid var(--line-strong); background:var(--card); color:var(--ink);
    font-family:'Inter', sans-serif; font-size:13.5px; font-weight:500;
    padding:12px 10px; border-radius:8px; cursor:pointer; text-align:center;
    transition: border-color .15s, background .15s, transform .1s;
  }
  .guess-opt:hover:not(:disabled){ border-color:var(--gold); transform:translateY(-1px); }
  .guess-opt:disabled{ cursor:default; }
  .guess-opt.correct-answer{ border-color:var(--teal); background:rgba(90,138,134,0.16); }
  .guess-opt.wrong-answer{ border-color:var(--clay); background:rgba(166,90,60,0.16); }

  .guess-next{
    display:block; width:calc(100% - 40px); margin:0 20px 20px;
    background:var(--gold); color:#141c16; border:none; font-weight:600;
    font-size:13px; padding:12px; border-radius:24px; cursor:pointer;
    font-family:'Inter', sans-serif;
  }
  .guess-loading{ grid-column:1/-1; padding:30px 10px; text-align:center; color:var(--ink-dim); font-family:'JetBrains Mono', monospace; font-size:12px; }

  /* ---------- mini-gra: znajdź na mapie (iframe w popupie) ---------- */
  dialog.findmap-dialog{
    border:none; border-radius:12px; padding:0; background:var(--bg); color:var(--ink);
    width:min(720px, 96vw); height:min(86vh, 640px); max-height:none; overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.45);
  }
  dialog.findmap-dialog::backdrop{ background:rgba(8,14,10,0.72); backdrop-filter:blur(2px); }
  dialog.findmap-dialog[open]{ animation: guess-pop .22s cubic-bezier(.22,.61,.36,1); }
  .findmap-shell{ display:flex; flex-direction:column; height:100%; padding:12px 14px 14px; gap:10px; box-sizing:border-box; position:relative; }
  .findmap-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 44px 0 2px; }
  .findmap-frame{ flex:1 1 auto; width:100%; min-height:0; border:1px solid var(--line-strong); border-radius:10px; background:var(--bg-alt); display:block; }

  /* ---------- ulubione (serduszko na miniaturach + sekcja na stronie głównej) ---------- */
  .fav-btn{
    position:absolute; top:8px; right:8px; z-index:3;
    width:30px; height:30px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    background:rgba(16,28,22,0.55); backdrop-filter:blur(2px);
    border:1px solid rgba(236,230,214,0.25); cursor:pointer;
    color:#ece6d6; transition: background .15s, border-color .15s, transform .1s;
  }
  .fav-btn:hover{ transform:scale(1.08); border-color:var(--clay); }
  .fav-btn svg{ width:15px; height:15px; }
  .fav-btn svg path{ transition: fill .15s, stroke .15s; }
  .fav-btn.is-fav{ border-color:var(--clay); }
  .fav-btn.is-fav svg path{ fill:var(--clay); stroke:var(--clay); }
  .strip-thumb{ position:relative; }
  .strip-thumb .fav-btn{ width:22px; height:22px; top:4px; right:4px; }
  .strip-thumb .fav-btn svg{ width:11px; height:11px; }
  .lightbox-fav-btn{
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s;
  }
  .lightbox-fav-btn:hover{ border-color:var(--clay); }
  .lightbox-fav-btn.is-fav{ border-color:var(--clay); }
  .lightbox-fav-btn.is-fav svg path{ fill:var(--clay); stroke:var(--clay); }
  .lightbox-fav-btn svg{ width:16px; height:16px; }
  .fav-btn svg, .lightbox-fav-btn svg{ stroke-linejoin:round; stroke-linecap:round; }

  /* ---------- polubienie zdjęcia (osobny system od ulubionych) ---------- */
  .lightbox-like-btn{
    position:absolute; top:22px; right:124px; z-index:2;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s;
  }
  .lightbox-like-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-like-btn.is-liked{ border-color:var(--gold); color:var(--gold); }
  .lightbox-like-btn.is-liked .like-ico svg path{ fill:var(--gold); stroke:var(--gold); }
  .lightbox-like-btn .like-ico svg{ width:16px; height:16px; }
  .lightbox-like-btn .like-count{
    position:absolute; top:-5px; right:-5px;
    min-width:16px; height:16px; padding:0 3px; border-radius:8px;
    background:var(--gold); color:#141c16;
    font-family:'JetBrains Mono', monospace; font-size:9.5px; font-weight:600;
    display:flex; align-items:center; justify-content:center;
  }
  @media (max-width:640px){ .lightbox-like-btn{ right:124px; } }

  .favorites-section{ padding:44px 0 0; }
  .favorites-head{
    display:flex; align-items:baseline; justify-content:space-between;
    flex-wrap:wrap; gap:14px; margin-bottom:20px;
  }
  .favorites-head h2{ font-family:'Fraunces', serif; font-weight:500; font-size:22px; margin:0; }
  .favorites-head .fav-count{ font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim); }
  .favorites-clear{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    font-family:'JetBrains Mono', monospace; font-size:11px;
    padding:6px 12px; border-radius:20px; cursor:pointer;
    transition: color .2s, border-color .2s;
  }
  .favorites-clear:hover{ color:var(--clay); border-color:var(--clay); }
  .favorites-strip{
    display:flex; gap:10px; overflow-x:auto; overflow-y:hidden;
    padding-bottom:10px; scrollbar-width:thin; scrollbar-color:var(--line-strong) var(--bg-alt);
  }
  .favorites-strip::-webkit-scrollbar{ height:5px; }
  .favorites-strip::-webkit-scrollbar-track{ background:var(--bg-alt); border-radius:4px; }
  .favorites-strip::-webkit-scrollbar-thumb{ background:var(--line-strong); border-radius:4px; }
  .fav-tile{
    position:relative; flex:0 0 auto; width:130px; aspect-ratio:4/5;
    border-radius:6px; overflow:hidden; border:1px solid var(--line); cursor:pointer;
    background:var(--card);
  }
  .fav-tile img{ width:100%; height:100%; object-fit:cover; display:block; }

  /* ---------- atrakcje turystyczne (między galerią a komentarzami) ---------- */
  .attraction-section{
    padding:44px 0 40px;
  }
  .attraction-top{ display:flex; align-items:end; justify-content:space-between; gap:22px; margin-bottom:18px; }
  .attraction-top h2{ margin:0; font-family:'Fraunces', serif; font-size:clamp(20px,2.2vw,26px); font-weight:500; letter-spacing:-.02em; }
  .attraction-copy{ max-width:52ch; margin:8px 0 0; color:var(--ink-dim); font-size:14px; line-height:1.6; }
  .attraction-top-actions{ display:flex; gap:10px; align-items:center; flex:0 0 auto; }
  .attraction-all{
    text-decoration:none; white-space:nowrap;
    font-family:'JetBrains Mono', monospace; font-size:11px; letter-spacing:.03em;
    color:var(--ink); border:1px solid var(--line-strong); border-radius:8px; padding:9px 14px;
    transition: border-color .2s, color .2s;
  }
  .attraction-all:hover{ border-color:var(--gold); color:var(--gold); }
  .attraction-track{
    display:flex; gap:16px; overflow-x:auto; overflow-y:hidden;
    overscroll-behavior-x:contain; padding-bottom:14px;
    scrollbar-width:thin; scrollbar-color:var(--line-strong) var(--bg-alt);
  }
  .attraction-track::-webkit-scrollbar{ height:5px; }
  .attraction-track::-webkit-scrollbar-track{ background:var(--bg-alt); border-radius:4px; }
  .attraction-track::-webkit-scrollbar-thumb{ background:var(--line-strong); border-radius:4px; }
  .attraction-card{
    position:relative; flex:0 0 auto; width:262px; background:var(--card); border:1px solid var(--line);
    border-radius:16px; overflow:hidden;
    transition: border-color .2s, transform .15s, box-shadow .2s;
    scroll-snap-align:start;
  }
  .attraction-card:hover{ border-color:var(--gold); transform:translateY(-3px); box-shadow:0 10px 28px rgba(0,0,0,.22); }
  .attraction-media{ position:relative; display:block; width:100%; min-height:240px; aspect-ratio:3/4; overflow:hidden; background:var(--bg-alt); touch-action:manipulation; }
  .attraction-media img{ width:100%; height:100%; min-height:240px; object-fit:cover; display:block; transition:transform .45s ease; }
  .attraction-media:focus-visible,.attraction-chip:focus-visible{ outline:3px solid var(--gold); outline-offset:3px; }
  .attraction-card:hover .attraction-media img{ transform:scale(1.05); }
  .attraction-scrim{
    position:absolute; inset:0;
    background:linear-gradient(to top,rgba(9,16,12,.94) 0%,rgba(9,16,12,.5) 34%,rgba(9,16,12,0) 62%);
  }
  .attraction-place-badge{
    position:absolute; left:12px; top:12px; max-width:calc(100% - 90px);
    background:rgba(13,21,18,.6); color:#fff; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:4px 10px;
    font:10px 'JetBrains Mono', monospace; letter-spacing:.03em;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .attraction-likes-badge{
    position:absolute; right:12px; top:12px;
    background:rgba(13,21,18,.6); color:#fff; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:4px 10px;
    font:11px 'JetBrains Mono', monospace;
  }
  .attraction-overlay{
    position:absolute; left:0; right:0; bottom:0; z-index:2;
    padding:15px 16px 16px; display:flex; flex-direction:column; gap:8px;
    pointer-events:none;
  }
  .attraction-overlay a{ pointer-events:auto; }
  .attraction-name{ font:500 17px 'Fraunces', serif; line-height:1.25; color:#fff; text-shadow:0 1px 12px rgba(0,0,0,.55); }
  .attraction-labels{ display:flex; flex-wrap:wrap; gap:8px; }
  .attraction-chip{
    display:inline-flex; align-items:center; justify-content:center; min-height:48px; min-width:48px;
    font:10px 'JetBrains Mono', monospace; letter-spacing:.03em; text-transform:uppercase;
    color:#fff; background:rgba(16,26,20,.72); border:1px solid rgba(255,255,255,.3); border-radius:24px; padding:10px 14px;
    text-decoration:none; backdrop-filter:blur(3px); transition:background .15s, color .15s, border-color .15s;
    touch-action:manipulation;
  }
  a.attraction-chip:hover{ background:var(--gold); color:#101c16; border-color:var(--gold); }
  .attraction-meta-line{ margin-top:14px; display:flex; flex-wrap:wrap; gap:6px 20px; align-items:center; }
  .attraction-more{
    color:var(--gold); font:11px 'JetBrains Mono', monospace; letter-spacing:.03em; text-decoration:none;
    border:1px solid var(--gold); border-radius:20px; padding:5px 14px; transition:background .2s, color .2s;
  }
  .attraction-more:hover{ background:var(--gold); color:#101c16; }
  .attraction-empty{ margin:0; padding:8px 0; color:var(--ink-dim); font-size:13.5px; line-height:1.6; }

  @media (max-width:760px){
    .attraction-top{ align-items:start; flex-direction:column; gap:12px; }
    .attraction-card{ width:74vw; }
  }

  /* ---------- ostatnie komentarze (na samym dole, nad stopką) ---------- */
  .comments-section{
    padding:40px 0 6px;
    border-top:1px solid var(--line);
  }
  .comments-section-head{ margin-bottom:22px; }
  .comments-section-head h2{
    font-family:'Fraunces', serif; font-weight:500; font-size:22px; margin:6px 0 0;
  }
  .comments-recent-strip{
    display:flex; gap:14px; overflow-x:auto; overflow-y:hidden;
    overscroll-behavior-x:contain;
    padding-top:6px; padding-bottom:14px; scrollbar-width:thin; scrollbar-color:var(--line-strong) var(--bg-alt);
  }
  .comments-recent-strip::-webkit-scrollbar{ height:5px; }
  .comments-recent-strip::-webkit-scrollbar-track{ background:var(--bg-alt); border-radius:4px; }
  .comments-recent-strip::-webkit-scrollbar-thumb{ background:var(--line-strong); border-radius:4px; }
  .comment-recent-tile{
    flex:0 0 auto; width:220px; border:1px solid var(--line); border-radius:6px;
    overflow:hidden; cursor:pointer; background:var(--card);
    transition: border-color .2s, transform .15s;
  }
  .comment-recent-tile:hover{ border-color:var(--gold); transform:translateY(-2px); }
  .comment-recent-tile img{ width:100%; aspect-ratio:16/10; object-fit:cover; display:block; }
  .comment-recent-body{ padding:12px 14px 15px; }
  .comment-recent-author{
    font-family:'JetBrains Mono', monospace; font-size:10px; letter-spacing:0.05em;
    text-transform:uppercase; color:var(--ink-dim);
    margin-bottom:7px; display:flex; align-items:center; gap:8px;
  }
  .comment-recent-author::before{ content:""; width:14px; height:1px; background:var(--gold); flex-shrink:0; }
  /* link do profilu autora komentarza */
  .comment-recent-author.is-account,
  .comment-author.is-account{
    color:var(--gold); text-decoration:none; border-bottom:1px solid rgba(201,162,39,.45);
    transition:color .2s, border-color .2s; align-self:flex-start;
  }
  .comment-recent-author.is-account:hover,
  .comment-author.is-account:hover{ color:var(--ink); border-bottom-color:var(--gold); }
  .comment-recent-author.is-account::before{ display:none; }
  /* informacja „komentujesz jako <nazwa>” zamiast pola imię */
  .comment-as-account{
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    padding:9px 13px; border:1px solid rgba(201,162,39,.35); border-radius:8px;
    background:rgba(201,162,39,.07);
  }
  .comment-as-label{
    font-family:'JetBrains Mono', monospace; font-size:10px; letter-spacing:0.05em;
    text-transform:uppercase; color:var(--ink-dim);
  }
  .comment-as-name{ font-family:'Fraunces', serif; font-size:14px; font-weight:500; color:var(--ink); }
  .comment-recent-text{
    font-family:'Fraunces', serif; font-style:italic; font-size:14px; color:var(--ink);
    line-height:1.45; display:-webkit-box; -webkit-line-clamp:3; line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }

  /* ---------- komentarze do zdjęć (lightbox) ---------- */
  .lightbox-comments-btn{
    position:absolute; top:22px; right:76px; z-index:2;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:rgba(16,28,22,0.7);
    color:var(--ink-dim); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition: border-color .2s, color .2s;
  }
  .lightbox-comments-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .lightbox-comments-btn.active{ border-color:var(--gold); color:var(--gold); }
  .lightbox-comments-btn svg{ width:16px; height:16px; }
  .lightbox-comments-btn .comment-count{
    position:absolute; top:-5px; right:-5px;
    min-width:16px; height:16px; padding:0 3px; border-radius:8px;
    background:var(--gold); color:#141c16;
    font-family:'JetBrains Mono', monospace; font-size:9.5px; font-weight:600;
    display:flex; align-items:center; justify-content:center;
  }
  @media (max-width:640px){ .lightbox-comments-btn{ right:76px; } }

  .lightbox-comments-panel{
    display:none;
    position:absolute; left:50%; top:var(--comments-photo-top, 50%);
    transform:translateX(-50%);
    width:min(92vw, 420px); max-width:100%;
    height:var(--comments-photo-height, 58vh);
    max-height:var(--comments-photo-height, 58vh);
    background:rgba(16,28,22,0.94); border:1px solid var(--line-strong);
    border-radius:14px; background-clip:padding-box;
    padding:14px 16px; z-index:4; overflow-y:auto; overflow-x:hidden;
    -webkit-overflow-scrolling:touch; overscroll-behavior:contain;
    touch-action:pan-y; scrollbar-gutter:stable;
  }
  html[data-theme="light"] .lightbox-comments-panel{ background:rgba(245,241,230,0.97); }
  .lightbox-comments-panel.open{ display:block; }
  .comments-panel-head{
    display:flex; align-items:center; justify-content:space-between;
    gap:8px; margin-bottom:6px; position:sticky; top:0;
    background:inherit; padding-bottom:6px; z-index:1;
  }
  .comments-panel-close{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    width:26px; height:26px; border-radius:50%; font-size:16px; line-height:1;
    cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center;
  }
  .comments-panel-close:hover{ border-color:var(--gold); color:var(--gold); }
  .comments-title{
    font-family:'JetBrains Mono', monospace; font-size:11px; letter-spacing:0.04em;
    text-transform:uppercase; color:var(--ink-dim); margin-bottom:10px;
  }
  .comments-loading{ font-size:12.5px; color:var(--ink-dim); font-style:italic; padding:4px 0 14px; }
  .comments-offline-note{
    font-size:11.5px; color:var(--clay); border:1px dashed var(--clay); border-radius:6px;
    padding:6px 9px; margin-bottom:12px; line-height:1.4;
  }
  .comments-list{ display:flex; flex-direction:column; gap:10px; margin-bottom:14px; min-width:0; }
  .comments-empty{ font-size:12.5px; color:var(--ink-dim); font-style:italic; margin-bottom:14px; }
  .comment-item{ border-bottom:1px solid var(--line); padding-bottom:8px; min-width:0; scroll-margin-top:14px; scroll-margin-bottom:14px; border-radius:8px; transition:background-color .4s ease, box-shadow .4s ease; }
  .comment-item:last-child{ border-bottom:none; padding-bottom:0; }
  .comment-item.comment-target{
    border-bottom-color:transparent;
    background:rgba(201,162,39,0.12);
    box-shadow:0 0 0 1px var(--gold), 0 8px 24px rgba(0,0,0,0.28);
    padding:10px 12px;
    margin:2px 0;
  }
  html[data-theme="light"] .comment-item.comment-target{ background:rgba(169,121,31,0.14); }
  @media (prefers-reduced-motion:no-preference){
    .comment-item.comment-target{ animation:commentTargetPulse 1.6s ease-out 1; }
  }
  @keyframes commentTargetPulse{
    0%{ box-shadow:0 0 0 1px var(--gold), 0 0 0 6px rgba(201,162,39,0.30); }
    100%{ box-shadow:0 0 0 1px var(--gold), 0 8px 24px rgba(0,0,0,0.28); }
  }
  .comment-item-head{ display:flex; align-items:baseline; justify-content:space-between; gap:8px; margin-bottom:3px; flex-wrap:wrap; min-width:0; }
  .comment-author{ font-family:'Fraunces', serif; font-size:13px; color:var(--ink); font-weight:500; overflow-wrap:anywhere; }
  .comment-date{ font-family:'JetBrains Mono', monospace; font-size:10px; color:var(--ink-dim); white-space:nowrap; }
  .comment-text{ font-size:13px; color:var(--ink-dim); line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; }
  .comment-del{ background:none; border:none; color:var(--ink-dim); font-size:11px; cursor:pointer; padding:0; margin-top:4px; font-family:'JetBrains Mono', monospace; }
  .comment-del:hover{ color:var(--clay); }
  .comment-actions{ display:flex; align-items:center; gap:14px; margin-top:5px; flex-wrap:wrap; }
  .comment-actions .comment-del{ margin-top:0; }

  /* --- moderacja komentarza: widoczna tylko dla administratora --- */
  .comment-admin-btn{
    background:none; border:none; color:var(--ink-dim); cursor:pointer; padding:2px 0;
    display:inline-flex; align-items:center;
    font-family:'JetBrains Mono', monospace; font-size:11px;
  }
  .comment-admin-btn:hover{ color:var(--gold); }
  .comment-admin-btn.danger:hover{ color:var(--clay); }

  /* --- zgłaszanie komentarza: dla zalogowanych i dla osób bez konta --- */
  .comment-report-btn{
    background:none; border:none; color:var(--ink-dim); cursor:pointer; padding:2px 0;
    display:inline-flex; align-items:center;
    font-family:'JetBrains Mono', monospace; font-size:11px;
  }
  .comment-report-btn:hover{ color:var(--clay); }
  .comment-report-btn.sent{ color:var(--gold); cursor:default; }
  .comment-report-pop{
    margin-top:8px; padding:10px; border:1px solid var(--line); border-radius:9px;
    display:flex; flex-direction:column; gap:8px;
  }
  .comment-report-pop[hidden]{ display:none; }
  .comment-report-reasons{ display:flex; flex-direction:column; gap:5px; }
  .comment-report-reasons label{
    display:flex; align-items:center; gap:7px; font-size:12.5px;
    color:var(--ink-dim); cursor:pointer;
  }
  .comment-report-note{
    width:100%; box-sizing:border-box; resize:vertical; min-height:54px;
    background:rgba(0,0,0,.12); border:1px solid var(--line); border-radius:7px;
    color:var(--ink); font:inherit; font-size:13px; padding:7px 9px;
  }
  .comment-report-row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .comment-report-send{
    background:none; border:1px solid var(--line-strong); border-radius:7px;
    color:var(--ink); padding:5px 13px; cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:11.5px;
  }
  .comment-report-send:hover{ color:var(--gold); border-color:var(--gold); }
  .comment-report-send:disabled{ opacity:.55; cursor:default; }
  .comment-report-cancel{
    background:none; border:none; color:var(--ink-dim); cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:11.5px;
  }
  .comment-report-msg{ font-size:12px; color:var(--ink-dim); }
  .comment-report-msg.is-error{ color:var(--clay); }
  .comment-report-msg.is-ok{ color:var(--gold); }

  /* --- telefon: iOS przybliża stronę przy dotknięciu pola mniejszego niż 16 px ---
     iOS Safari robi to automatycznie i nie wraca do poprzedniego powiększenia,
     gdy pole straci fokus — strona zostaje powiększona na stałe. Zamiast blokować
     zoom (psuje dostępność i nie da się go przywrócić), ustawiamy 16 px, czyli
     próg, poniżej którego iOS w ogóle nie sięga. Główny formularz komentarza ma
     tę wartość od początku; te pola zostały zapomniane.
     Powyżej 640 px zostaje dotychczasowy, mniejszy wygląd. */
  @media (max-width:640px){
    .comment-report-note,
    .comment-edit-pop textarea,
    .comment-edit-reason,
    .share-modal-url{ font-size:16px; }
  }

  /* --- powiadomienia (ten sam mechanizm co w panelu administratora) ---
     Zwykłe okno przeglądarki zatrzymuje przewijanie i wygląda obco w tej
     skórze, dlatego wszystkie potwierdzenia i ostrzeżenia idą w toast. */
  .toast{
    position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px);
    background:var(--card); border:1px solid var(--gold); color:var(--ink);
    font-family:'JetBrains Mono', monospace; font-size:12px; padding:10px 18px;
    border-radius:20px; z-index:120; opacity:0; pointer-events:none;
    transition:opacity .2s, transform .2s;
    box-shadow:0 8px 24px rgba(0,0,0,.35); max-width:90vw; text-align:center;
  }
  .toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
  .toast.is-error{ border-color:var(--clay); }
  .toast.is-error.show{ box-shadow:0 8px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(166,90,60,.35); }
  /* informacja dla czytających, co administrator poprawił i dlaczego */
  .comment-edited{
    margin-top:6px; font-size:11px; line-height:1.45; color:var(--ink-dim);
    border-left:2px solid var(--clay); padding-left:8px; overflow-wrap:anywhere;
  }
  /* wyskakujące okno edycji */
  .comment-edit-pop{
    margin-top:9px; padding:11px; border:1px solid var(--line-strong); border-radius:8px;
    background:var(--card); box-shadow:0 10px 28px rgba(0,0,0,.30);
    display:flex; flex-direction:column; gap:9px; min-width:0;
  }
  .comment-edit-pop[hidden]{ display:none; }
  .comment-edit-pop textarea, .comment-edit-reason{
    width:100%; max-width:100%; background:transparent; color:var(--ink);
    border:1px solid var(--line-strong); border-radius:6px; padding:8px 10px;
    font-family:inherit; font-size:13px; line-height:1.5; overflow-wrap:anywhere;
  }
  .comment-edit-pop textarea{ resize:vertical; min-height:80px; }
  .comment-edit-reason{ font-size:12.5px; padding:6px 9px; }
  .comment-edit-reason[hidden]{ display:none; }
  /* pole powodu jest celowo małe i zwinięte — odkrywa się kliknięciem */
  .comment-edit-reason-box{ display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
  .comment-edit-reason-toggle{
    background:none; border:none; color:var(--ink-dim); cursor:pointer; padding:0;
    font-family:'JetBrains Mono', monospace; font-size:11px;
  }
  .comment-edit-reason-toggle:hover{ color:var(--gold); }
  .comment-edit-actions{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .comment-edit-actions button{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    border-radius:6px; padding:5px 12px; cursor:pointer;
    font-family:'JetBrains Mono', monospace; font-size:11px;
  }
  .comment-edit-actions button:hover{ color:var(--gold); border-color:var(--gold); }
  .comment-edit-actions button.save{
    color:#141c16; background:var(--gold); border-color:var(--gold); font-weight:600;
  }
  .comment-edit-actions button.save:hover{ color:#141c16; opacity:.9; }
  .comment-edit-actions button:disabled{ opacity:.5; cursor:default; }
  .comment-like, .comment-reply-btn{
    background:none; border:none; color:var(--ink-dim); cursor:pointer; padding:2px 0;
    display:inline-flex; align-items:center; gap:5px;
    font-family:'JetBrains Mono', monospace; font-size:11px;
  }
  .comment-like svg{ width:13px; height:13px; transition:transform .15s; }
  .comment-like:hover, .comment-reply-btn:hover{ color:var(--gold); }
  .comment-like.liked{ color:var(--clay); }
  .comment-like.liked svg{ fill:currentColor; }
  .comment-like:active svg{ transform:scale(1.25); }
  .comment-replies{
    margin:8px 0 0 10px; padding-left:12px; border-left:2px solid var(--line);
    display:flex; flex-direction:column; gap:10px; min-width:0;
  }
  .comment-replies .comment-item{ border-bottom:none; padding-bottom:0; }
  .comment-reply-chip{
    display:flex; align-items:center; justify-content:space-between; gap:8px;
    font-size:12px; color:var(--ink-dim); border:1px solid var(--gold); border-radius:6px; padding:5px 9px;
  }
  .comment-reply-chip b{ color:var(--ink); font-weight:500; overflow-wrap:anywhere; }
  .comment-reply-chip button{
    background:none; border:none; color:var(--ink-dim); cursor:pointer; font-size:16px; line-height:1; padding:0 2px;
    align-self:auto;
  }
  .comment-reply-chip button:hover{ color:var(--clay); }

   /* panel komentarzy zawsze wysokości zdjęcia (także bez komentarzy) */
  .comment-form{ display:flex; flex-direction:column; gap:8px; min-width:0; }
  .comment-form input, .comment-form textarea{
    width:100%; max-width:100%; background:var(--card); color:var(--ink);
    border:1px solid var(--line-strong); border-radius:6px;
    padding:8px 10px; font-family:'Inter', sans-serif; font-size:16px;
    resize:vertical; box-sizing:border-box;
    /* 16px = Safari iOS nie robi auto-zoom przy fokusie pola */
  }
  .comment-form textarea{ min-height:56px; }
  .comment-form button{
    align-self:flex-end;
    background:var(--gold); color:#141c16; border:none; font-weight:600;
    font-size:12px; padding:8px 16px; border-radius:20px; cursor:pointer;
    font-family:'Inter', sans-serif; flex-shrink:0;
  }
  .comment-form button:disabled{ opacity:0.5; cursor:default; }

  /* Podpowiedź filtra wulgaryzmów — wyskakuje w trakcie pisania, żeby user
     dostał informację zanim kliknie „Dodaj komentarz” i zobaczy błąd serwera. */
  .comment-filter-hint{
    display:flex; align-items:flex-start; gap:6px;
    font-size:12.5px; line-height:1.45; color:var(--clay);
    background:rgba(166,90,60,.10);
    border:1px solid rgba(166,90,60,.32);
    border-radius:6px; padding:6px 9px;
  }
  /* atrybut [hidden] musi wygrać z display:flex wyżej */
  .comment-filter-hint[hidden]{ display:none; }

  /* Na telefonie panel dokładnie przykrywa obszar zdjęcia, ale nie cały ekran.
     Pozycja i rozmiar są wyliczane z aktualnego kadru lightboxa w JS. */
  @media (max-width:640px){
    .lightbox-comments-panel{
      position:absolute;
      left:var(--comments-photo-left, 30px);
      top:var(--comments-photo-top, 30px);
      width:var(--comments-photo-width, calc(100% - 60px));
      height:var(--comments-photo-height, calc(100% - 60px));
      max-width:none; max-height:none;
      transform:none;
      border-radius:14px;
      padding:12px 16px;
      background:rgba(16,28,22,0.94);
      box-shadow:none;
      transition:none;
    }
    .lightbox-comments-panel.kb-open{
      max-height:var(--comments-photo-height, calc(100% - 60px));
    }
    .comments-panel-head{ padding-bottom:6px; }
    .comments-panel-head .comments-title{ margin-bottom:0; }
  }

  /* ---------- pływający przycisk powrotu na górę ---------- */
  .scroll-top-btn{
    position:fixed; right:18px; bottom:18px; z-index:50;
    width:46px; height:46px; border-radius:50%;
    background:var(--card); border:1px solid var(--line-strong);
    color:var(--ink); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.25);
    opacity:0; visibility:hidden; transform:translateY(10px);
    transition: opacity .25s ease, transform .25s ease, visibility .25s, border-color .2s, color .2s;
    /* padding-bottom pod safe-area przesuwal strzalke do gory (border-box
       skraca obszar tresci, a flex centruje w nim SVG). Odstęp bezpiecznego
       obszaru dodajemy do `bottom` ponizej. */
    padding:0;
  }
  .scroll-top-btn.visible{ opacity:1; visibility:visible; transform:translateY(0); }
  .scroll-top-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .scroll-top-btn svg{ display:block; width:20px; height:20px; flex:0 0 auto; }
  @media (max-width:640px){
    .scroll-top-btn{ right:14px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
  }
</style>

<header class="top">
  <div class="wrap" style="display:flex; align-items:center; justify-content:space-between;">
    <div class="brand-block" style="margin-left:52px;">
      <div class="wordmark">z<span>mogilna</span><i class="tld">.pl</i></div>
      <div class="coords">52.654220° N, 17.953806° E</div>
    </div>
    <nav class="floating-btns" aria-label="Narzędzia strony">
      <button class="theme-toggle" id="themeToggle" aria-label="Przełącz tryb jasny/ciemny" title="Przełącz tryb jasny/ciemny">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
      </button>
      <a class="home-toggle" id="homeToggle" href="index.php" aria-label="Strona główna" title="Strona główna">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></svg>
      </a>
<?php /* Pasek narzędzi ma zostać przy 3 przyciskach: tryb jasny/ciemny,
         strona główna i ikona konta. Dzwonek powiadomień oraz dymek wiadomości
         przeniosły się do menu konta (acctDialog → acctLoggedInView) — tam też
         siedzą ich plakietki (#bellBadge, #msgBadge), więc poller liczników
         i widoczność zależna od zalogowania działają bez zmian. */ ?>
      <button class="home-toggle acct-toggle" id="acctToggle" aria-label="Konto, zaloguj się" title="Zaloguj się">
        <span class="acct-dot" id="acctDot" aria-hidden="true"></span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </button>
    </nav>
  </div>
</header>

<main id="main-content">
<div class="wrap">
  <section class="hero">
    <div>
      <div class="eyebrow"><?= zm_text_h('hero.eyebrow') ?></div>
      <h1><span style="color:var(--gold);">z</span><?= zm_text_h('hero.title') ?> <em><?= zm_text_h('hero.title_em') ?></em></h1>
      <p class="lede"><?= zm_text_h('hero.lede1') ?> <br class="lede-br"><?= zm_text_h('hero.lede2') ?> <br class="lede-br-m"><?= zm_text_h('hero.lede3') ?></p>
      <div class="activity-label" style="margin:-8px 0 26px;"><?= zm_text_h('hero.tagline') ?></div>
      <div class="hero-meta">
        <div><b id="statPhotos"><?= (int) $galleryTotal ?></b><?= zm_text_h('stats.photos_label') ?></div>
        <div><b><?= zm_text_h('stats.radius_value') ?></b><?= zm_text_h('stats.radius_label') ?></div>
        <div><b id="statSync">nie połączono</b><?= zm_text_h('stats.sync_label') ?></div>
      </div>
      <div class="activity-block">
        <div class="activity-label">aktywność w ostatnich 12 miesiącach</div>
        <div class="chart-bars" id="activityChart">
<?php foreach ($galleryMonths as $gm): ?>
  <div class="chart-bar-col" title="<?= htmlspecialchars($gm['label'], ENT_QUOTES, 'UTF-8') ?>: <?= (int) $gm['count'] ?>">
    <div class="chart-bar" style="height:<?= max(2, (int) round(($gm['count'] / max(1, max(array_column($galleryMonths, 'count')))) * 100)) ?>%"></div>
    <div class="chart-bar-label"><?= htmlspecialchars($gm['label'], ENT_QUOTES, 'UTF-8') ?></div>
  </div>
<?php endforeach; ?>
</div>
      </div>
      <div class="game-cta-row">
        <button class="game-cta magnetic" id="guessGameBtn" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
          <?= zm_text_h('game.guess') ?>
        </button>
      <!-- Obie gry są dla każdego — także dla gości. Otwierają się w popupie. -->
        <button class="game-cta magnetic" id="findMapBtn" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6v16l6-3 6 3 6-3 4 2V6l-4-2-6 3-6-3-6 3z"/><path d="M7 6v13"/><path d="M13 9v13"/></svg>
          <?= zm_text_h('game.findmap') ?>
        </button>
      </div>
    </div>
    <div class="dial-holder">
      <div class="map-card" id="mapCard">
        <svg viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg">
          <g class="munis-layer">
            <path class="c-muni" d="M -60.3 192.4 L -57.2 188.2 L -45.4 189.9 L -36.9 187.3 L -40.0 175.7 L -37.0 169.2 L -32.9 168.0 L -31.0 170.1 L -16.9 169.0 L -13.1 161.9 L -9.8 161.7 L -4.2 165.8 L -11.7 178.6 L -3.4 177.7 L -2.9 184.9 L 7.3 182.0 L 10.8 186.5 L 20.4 192.4 L 26.1 191.8 L 23.5 204.6 L 17.7 208.5 L 15.8 207.2 L 6.9 213.0 L -3.6 222.9 L -5.9 231.2 L -16.0 237.6 L -25.6 235.5 L -27.2 239.2 L -33.1 242.4 L -36.0 226.2 L -32.5 217.9 L -35.4 214.6 L -41.4 216.6 L -46.9 210.0 L -53.3 208.2 L -52.4 199.3 L -54.9 195.2 L -60.3 192.4 Z"></path>
<path class="c-muni" d="M 229.5 285.1 L 233.3 283.5 L 246.1 272.4 L 246.5 270.3 L 252.5 268.6 L 265.9 272.6 L 265.2 279.2 L 270.9 280.8 L 276.4 278.8 L 277.6 286.5 L 284.4 294.9 L 285.3 305.6 L 272.4 310.2 L 268.0 310.3 L 266.6 314.7 L 255.5 312.5 L 253.4 309.1 L 256.4 304.2 L 251.7 299.9 L 244.3 297.1 L 236.7 289.9 L 230.1 292.4 L 229.5 285.1 Z"></path>
<path class="c-muni" d="M 374.5 310.3 L 376.1 319.7 L 379.6 320.0 L 376.1 331.4 L 381.2 333.8 L 379.5 342.3 L 385.0 346.4 L 377.3 348.5 L 374.0 357.6 L 357.5 361.6 L 356.4 366.6 L 344.8 365.7 L 338.8 360.9 L 337.4 365.0 L 330.2 363.0 L 330.4 357.6 L 322.0 357.8 L 322.6 353.4 L 327.3 345.4 L 326.1 332.6 L 331.1 333.6 L 334.0 328.7 L 330.9 325.5 L 343.5 322.4 L 344.7 317.7 L 352.4 314.6 L 361.1 313.3 L 367.0 310.4 L 374.5 310.3 Z"></path>
<path class="c-muni" d="M 265.7 388.0 L 254.3 374.7 L 248.8 379.8 L 246.4 374.6 L 237.2 366.2 L 234.2 369.4 L 228.3 367.1 L 221.8 358.5 L 218.3 357.4 L 215.9 353.7 L 224.0 350.0 L 231.0 338.5 L 246.7 340.1 L 250.5 343.2 L 256.7 335.2 L 259.8 336.2 L 260.3 346.3 L 268.5 345.9 L 272.9 339.6 L 275.1 346.8 L 272.9 351.2 L 273.3 360.5 L 267.6 369.1 L 278.1 376.9 L 279.9 383.9 L 265.7 388.0 Z"></path>
<path class="c-muni c-muni-in" d="M 254.3 154.5 L 256.8 162.0 L 264.1 164.4 L 266.0 168.1 L 258.3 177.1 L 253.6 174.8 L 246.2 185.1 L 241.5 186.4 L 228.4 187.5 L 228.0 191.9 L 221.2 194.0 L 220.4 189.6 L 212.6 186.5 L 212.3 175.8 L 208.8 174.5 L 212.6 172.8 L 214.5 165.1 L 220.1 152.4 L 235.6 147.9 L 246.4 148.9 L 243.5 156.6 L 254.3 154.5 Z"></path>
<path class="c-muni c-muni-in" d="M 307.7 253.1 L 306.5 242.3 L 310.2 235.6 L 310.0 231.1 L 297.7 230.4 L 290.3 232.9 L 288.2 228.8 L 283.2 228.7 L 277.0 223.9 L 277.6 217.6 L 280.9 212.0 L 277.7 208.9 L 277.4 199.4 L 275.1 194.3 L 282.9 184.1 L 276.2 181.5 L 275.1 171.0 L 287.7 166.2 L 291.3 174.0 L 296.5 175.4 L 296.6 169.5 L 302.3 169.6 L 305.0 165.6 L 310.3 166.8 L 314.9 171.5 L 325.1 170.6 L 329.1 172.8 L 342.2 173.7 L 349.9 177.4 L 348.8 185.2 L 346.0 186.0 L 340.4 186.6 L 341.1 194.2 L 336.8 206.4 L 338.6 211.6 L 343.4 215.9 L 343.7 227.9 L 342.1 236.9 L 335.6 236.9 L 332.4 240.1 L 333.2 246.1 L 326.6 248.4 L 314.6 255.8 L 307.7 253.1 Z"></path>
<path class="c-muni c-muni-in" d="M 220.1 152.4 L 214.6 147.9 L 215.1 144.0 L 208.4 137.3 L 216.9 132.1 L 224.1 133.0 L 228.6 121.6 L 225.0 119.2 L 229.1 113.6 L 223.2 109.6 L 225.1 104.1 L 233.0 108.0 L 235.4 112.9 L 242.2 119.1 L 258.4 124.9 L 260.1 133.7 L 267.3 142.9 L 267.5 145.2 L 261.7 150.4 L 254.3 151.4 L 254.3 154.5 L 243.5 156.6 L 246.4 148.9 L 235.6 147.9 L 220.1 152.4 Z"></path>
<path class="c-muni" d="M 290.7 112.5 L 280.0 110.5 L 276.4 106.6 L 267.8 104.7 L 267.1 100.7 L 270.0 88.4 L 270.2 69.2 L 272.0 66.0 L 280.1 65.2 L 277.5 56.4 L 273.4 54.2 L 288.1 52.9 L 295.0 48.7 L 298.0 58.0 L 304.0 51.3 L 311.8 50.5 L 316.1 53.4 L 307.7 74.7 L 307.7 82.3 L 303.4 87.5 L 302.8 94.9 L 304.4 104.2 L 292.2 106.1 L 290.7 112.5 Z"></path>
<path class="c-muni c-muni-in" d="M 276.4 278.8 L 270.9 280.8 L 265.2 279.2 L 265.9 272.6 L 252.5 268.6 L 246.5 270.3 L 244.1 265.5 L 239.1 266.7 L 234.6 263.6 L 235.0 258.5 L 239.2 255.3 L 236.0 248.7 L 240.8 253.6 L 248.4 252.3 L 255.6 259.7 L 259.5 259.9 L 256.9 251.7 L 259.9 246.9 L 264.9 248.5 L 277.8 237.3 L 283.8 236.8 L 290.3 232.9 L 297.7 230.4 L 310.0 231.1 L 310.2 235.6 L 306.5 242.3 L 307.7 253.1 L 304.2 255.8 L 305.7 263.0 L 294.8 271.6 L 286.3 276.9 L 282.0 281.5 L 276.4 278.8 Z"></path>
<path class="c-muni" d="M 357.6 226.1 L 352.7 219.2 L 353.8 214.3 L 359.1 212.0 L 362.3 224.3 L 357.6 226.1 Z"></path>
<path class="c-muni" d="M 323.0 26.5 L 315.3 36.8 L 314.7 47.6 L 311.8 50.5 L 304.0 51.3 L 298.0 58.0 L 295.0 48.7 L 288.1 52.9 L 273.4 54.2 L 268.2 56.0 L 267.2 49.7 L 262.7 47.3 L 265.1 42.3 L 246.2 39.4 L 246.6 28.8 L 237.8 29.6 L 239.8 19.3 L 247.5 20.8 L 248.5 5.5 L 252.4 -0.5 L 261.3 2.4 L 267.0 2.4 L 277.8 6.5 L 282.1 4.9 L 294.7 8.8 L 307.8 18.1 L 323.0 26.5 Z"></path>
<path class="c-muni" d="M 26.1 191.8 L 20.4 192.4 L 10.8 186.5 L 7.3 182.0 L -2.9 184.9 L -3.4 177.7 L -11.7 178.6 L -4.2 165.8 L -9.8 161.7 L -2.3 159.2 L 7.7 150.4 L 11.8 150.6 L 13.4 156.3 L 25.9 157.1 L 29.8 155.7 L 27.9 149.9 L 27.7 135.2 L 36.0 133.2 L 44.7 138.7 L 45.1 142.7 L 44.3 153.4 L 47.3 158.2 L 53.1 158.7 L 58.4 163.8 L 57.2 168.7 L 52.2 170.2 L 50.0 178.1 L 52.6 186.3 L 46.6 185.9 L 40.7 188.1 L 41.0 193.4 L 36.2 194.9 L 33.2 189.4 L 26.1 191.8 Z"></path>
<path class="c-muni" d="M 101.3 436.0 L 102.3 431.6 L 99.5 422.9 L 94.0 423.9 L 91.5 419.0 L 91.1 410.0 L 92.9 403.9 L 97.1 399.3 L 87.5 396.6 L 86.1 392.9 L 88.7 387.8 L 96.0 389.0 L 104.3 382.8 L 106.1 375.7 L 114.4 377.4 L 118.1 374.4 L 119.4 368.2 L 124.6 368.9 L 126.4 374.5 L 130.3 377.7 L 144.2 383.5 L 148.6 388.4 L 142.0 396.5 L 137.5 399.5 L 130.2 402.0 L 122.4 407.6 L 116.8 413.5 L 110.4 425.0 L 112.6 434.8 L 101.3 436.0 Z"></path>
<path class="c-muni" d="M 330.9 325.5 L 334.0 328.7 L 331.1 333.6 L 326.1 332.6 L 327.3 345.4 L 322.6 353.4 L 314.6 354.3 L 312.5 365.4 L 308.3 363.6 L 297.9 366.0 L 293.9 360.7 L 295.1 356.5 L 280.4 353.9 L 272.9 351.2 L 275.1 346.8 L 272.9 339.6 L 272.6 335.0 L 277.3 327.3 L 275.6 316.0 L 272.4 310.2 L 285.3 305.6 L 290.7 309.1 L 296.0 302.4 L 300.2 304.1 L 299.0 309.7 L 307.1 312.8 L 325.0 321.2 L 330.9 325.5 Z"></path>
<path class="c-muni" d="M 79.0 218.1 L 74.2 236.9 L 73.4 245.4 L 65.8 245.5 L 52.3 243.3 L 52.4 249.1 L 43.8 249.2 L 38.1 252.3 L 37.5 244.4 L 42.9 237.8 L 43.5 230.7 L 36.1 235.2 L 33.2 228.5 L 34.3 212.7 L 29.2 213.1 L 25.2 218.5 L 17.7 208.5 L 23.5 204.6 L 26.1 191.8 L 33.2 189.4 L 36.2 194.9 L 41.0 193.4 L 40.7 188.1 L 46.6 185.9 L 52.6 186.3 L 57.6 194.8 L 62.9 195.2 L 63.1 200.2 L 60.1 207.0 L 59.4 213.5 L 65.4 215.0 L 78.5 215.7 L 79.0 218.1 Z"></path>
<path class="c-muni c-muni-in" d="M 125.9 329.8 L 118.2 327.4 L 117.8 323.3 L 122.8 312.5 L 129.6 310.7 L 128.3 303.6 L 134.7 288.1 L 138.5 289.6 L 141.1 277.2 L 134.0 269.4 L 142.4 263.4 L 154.6 261.9 L 158.9 264.5 L 165.4 264.9 L 174.4 262.5 L 181.0 268.9 L 184.1 269.0 L 183.7 278.0 L 185.3 280.6 L 187.8 286.2 L 184.0 298.3 L 176.1 304.8 L 171.4 306.1 L 168.0 316.1 L 168.4 324.1 L 165.2 326.5 L 154.1 329.3 L 146.8 333.1 L 136.8 329.3 L 125.9 329.8 Z"></path>
<path class="c-muni c-muni-in" d="M 275.1 171.0 L 272.8 166.7 L 266.0 168.1 L 264.1 164.4 L 256.8 162.0 L 254.3 154.5 L 254.3 151.4 L 261.7 150.4 L 267.5 145.2 L 274.6 149.3 L 271.3 158.7 L 283.7 161.5 L 288.1 158.9 L 291.0 153.4 L 286.6 150.7 L 286.6 145.1 L 290.1 136.9 L 288.0 126.9 L 275.5 129.4 L 275.2 136.1 L 271.4 136.2 L 267.3 142.9 L 260.1 133.7 L 258.4 124.9 L 248.0 121.4 L 249.6 114.5 L 252.9 114.9 L 256.3 109.5 L 254.4 99.2 L 267.1 100.7 L 267.8 104.7 L 276.4 106.6 L 280.0 110.5 L 290.7 112.5 L 289.6 118.3 L 296.9 118.8 L 302.7 123.2 L 314.2 122.2 L 316.0 133.0 L 321.0 142.3 L 315.5 143.5 L 317.6 154.8 L 323.5 163.7 L 325.1 170.6 L 314.9 171.5 L 310.3 166.8 L 305.0 165.6 L 302.3 169.6 L 296.6 169.5 L 296.5 175.4 L 291.3 174.0 L 287.7 166.2 L 275.1 171.0 Z"></path>
<path class="c-muni c-muni-in" d="M 107.2 203.1 L 108.7 198.1 L 106.6 191.1 L 111.0 186.5 L 105.6 177.9 L 101.9 180.2 L 92.6 179.5 L 90.2 185.2 L 82.8 185.8 L 82.8 181.6 L 87.9 175.6 L 91.3 165.0 L 88.1 161.0 L 88.2 150.2 L 100.3 150.8 L 103.4 142.1 L 111.2 139.1 L 112.8 135.1 L 119.9 132.3 L 122.9 144.9 L 120.8 150.6 L 124.0 153.7 L 129.1 153.5 L 133.3 157.8 L 131.6 165.7 L 135.8 179.3 L 143.2 177.4 L 155.5 179.4 L 154.3 185.4 L 158.0 192.5 L 149.9 203.7 L 147.3 210.7 L 143.0 211.4 L 142.6 204.7 L 136.4 204.0 L 130.3 209.2 L 139.5 212.4 L 132.9 217.4 L 123.2 225.9 L 119.6 226.2 L 120.8 216.4 L 119.5 205.1 L 110.8 206.8 L 107.2 203.1 Z"></path>
<path class="c-muni" d="M 216.9 3.2 L 217.2 1.2 L 201.4 -0.4 L 194.9 -2.3 L 195.9 -9.7 L 185.9 -14.8 L 178.0 -16.8 L 174.3 -20.3 L 183.7 -28.5 L 187.3 -22.8 L 197.8 -24.1 L 194.7 -33.8 L 195.2 -37.0 L 191.1 -47.8 L 192.5 -48.9 L 203.5 -50.7 L 202.4 -32.0 L 207.2 -31.3 L 208.1 -37.5 L 221.5 -36.2 L 234.5 -30.4 L 242.0 -31.8 L 246.9 -36.7 L 255.0 -36.4 L 259.2 -39.3 L 259.6 -45.5 L 263.0 -46.5 L 265.8 -35.7 L 264.3 -27.7 L 261.4 -23.8 L 249.6 -15.8 L 247.2 -9.6 L 249.8 -2.7 L 252.4 -0.5 L 248.5 5.5 L 247.5 20.8 L 239.8 19.3 L 240.4 14.9 L 220.5 21.7 L 222.4 9.3 L 216.9 3.2 Z"></path>
<path class="c-muni" d="M 398.4 177.9 L 390.2 175.8 L 390.9 169.8 L 375.7 170.7 L 365.3 174.0 L 350.9 176.6 L 350.4 164.4 L 348.9 159.8 L 360.4 161.6 L 364.0 155.9 L 377.9 157.1 L 383.8 141.8 L 387.7 140.7 L 396.4 143.8 L 399.6 148.0 L 399.2 153.4 L 410.2 158.9 L 418.3 170.5 L 415.7 175.1 L 409.3 176.7 L 399.4 171.8 L 398.4 177.9 Z"></path>
<path class="c-muni" d="M -58.6 39.6 L -58.9 33.3 L -62.6 32.3 L -67.8 17.8 L -70.3 17.3 L -64.6 10.8 L -61.8 14.0 L -50.6 14.2 L -46.5 11.8 L -39.8 9.9 L -34.6 11.3 L -30.1 8.3 L -21.6 7.4 L -11.6 3.5 L -6.8 6.2 L -3.5 8.7 L 1.1 6.6 L 11.1 6.2 L 12.0 17.7 L 13.9 21.2 L 10.6 28.3 L 1.2 36.0 L -11.4 35.0 L -16.6 36.4 L -23.2 35.2 L -24.7 37.8 L -37.2 38.1 L -43.1 40.7 L -49.9 46.8 L -58.6 39.6 Z"></path>
<path class="c-muni" d="M 8.5 355.9 L 7.7 350.3 L 17.7 350.6 L 23.2 341.8 L 33.4 343.6 L 35.7 348.1 L 42.8 346.3 L 47.4 353.3 L 42.1 357.9 L 44.1 371.1 L 50.0 376.9 L 56.4 378.1 L 62.8 374.6 L 66.7 378.8 L 61.6 388.0 L 56.9 388.2 L 55.6 394.7 L 44.4 392.5 L 45.3 384.6 L 34.5 385.2 L 32.5 377.7 L 14.3 373.5 L 17.8 362.5 L 13.8 356.3 L 8.5 355.9 Z"></path>
<path class="c-muni" d="M 45.1 142.7 L 44.7 138.7 L 36.0 133.2 L 36.8 121.8 L 33.4 115.4 L 34.8 96.5 L 40.8 96.4 L 50.1 88.4 L 54.8 93.2 L 55.8 99.9 L 60.6 97.2 L 63.5 101.0 L 71.9 99.7 L 76.9 100.8 L 77.3 108.3 L 73.7 110.9 L 73.4 121.4 L 75.8 126.1 L 71.2 134.1 L 72.7 137.8 L 69.3 142.6 L 54.1 141.5 L 45.1 142.7 Z"></path>
<path class="c-muni" d="M 50.1 88.4 L 43.6 80.0 L 44.1 77.6 L 54.4 65.7 L 54.6 63.7 L 59.9 67.9 L 65.6 67.4 L 69.5 75.4 L 75.2 77.4 L 73.5 86.2 L 76.9 100.8 L 71.9 99.7 L 63.5 101.0 L 60.6 97.2 L 55.8 99.9 L 54.8 93.2 L 50.1 88.4 Z"></path>
<path class="c-muni" d="M 88.7 387.8 L 88.0 384.0 L 81.2 379.7 L 77.8 384.9 L 69.7 386.3 L 66.7 378.8 L 62.8 374.6 L 67.5 366.7 L 65.5 356.5 L 70.7 347.7 L 70.1 338.2 L 63.7 337.5 L 62.0 327.8 L 66.5 326.5 L 63.1 321.1 L 63.7 313.7 L 69.4 312.6 L 79.3 314.0 L 82.8 319.1 L 86.5 316.9 L 96.5 320.0 L 104.3 324.3 L 117.8 323.3 L 118.2 327.4 L 125.9 329.8 L 124.2 335.7 L 124.8 342.1 L 131.9 347.8 L 130.5 351.5 L 136.7 354.6 L 133.6 361.3 L 127.8 361.6 L 124.6 368.9 L 119.4 368.2 L 118.1 374.4 L 114.4 377.4 L 106.1 375.7 L 104.3 382.8 L 96.0 389.0 L 88.7 387.8 Z"></path>
<path class="c-muni c-muni-in" d="M 74.0 276.1 L 78.1 262.3 L 72.5 261.4 L 69.5 254.8 L 73.3 250.4 L 73.4 245.4 L 74.2 236.9 L 79.0 218.1 L 89.7 222.5 L 93.9 219.8 L 95.1 209.7 L 101.9 208.4 L 107.2 203.1 L 110.8 206.8 L 119.5 205.1 L 120.8 216.4 L 119.6 226.2 L 123.2 225.9 L 132.9 217.4 L 137.4 225.0 L 139.6 232.0 L 132.7 237.5 L 140.8 245.6 L 133.6 251.9 L 142.4 263.4 L 134.0 269.4 L 128.6 271.7 L 118.3 269.2 L 109.5 271.9 L 104.1 267.1 L 113.2 258.7 L 112.1 250.4 L 102.1 242.6 L 98.2 242.5 L 89.4 247.6 L 86.5 250.9 L 86.3 263.4 L 90.8 264.2 L 87.8 274.0 L 82.3 279.9 L 79.6 276.3 L 74.0 276.1 Z"></path>
<path class="c-muni" d="M 96.5 320.0 L 93.7 312.7 L 93.9 302.4 L 99.6 298.8 L 95.7 290.1 L 96.0 280.3 L 99.1 280.7 L 106.5 274.7 L 104.1 267.1 L 109.5 271.9 L 118.3 269.2 L 128.6 271.7 L 134.0 269.4 L 141.1 277.2 L 138.5 289.6 L 134.7 288.1 L 128.3 303.6 L 129.6 310.7 L 122.8 312.5 L 117.8 323.3 L 104.3 324.3 L 96.5 320.0 Z"></path>
<path class="c-muni" d="M 315.2 128.0 L 314.2 122.2 L 302.7 123.2 L 296.9 118.8 L 289.6 118.3 L 292.2 106.1 L 304.4 104.2 L 302.8 94.9 L 303.4 87.5 L 307.7 82.3 L 307.7 74.7 L 310.1 69.2 L 332.1 64.8 L 333.7 70.9 L 347.7 68.1 L 352.5 86.6 L 366.9 99.8 L 363.8 111.5 L 357.6 116.8 L 351.0 119.7 L 340.6 118.1 L 341.4 125.2 L 336.3 128.2 L 329.0 128.8 L 323.3 121.8 L 315.2 128.0 Z"></path>
<path class="c-muni c-muni-in" d="M 205.8 248.6 L 205.5 241.8 L 198.6 240.7 L 194.5 237.2 L 186.6 236.2 L 181.9 233.8 L 184.0 223.6 L 174.1 212.5 L 166.7 214.4 L 160.8 211.5 L 156.9 206.6 L 149.9 203.7 L 158.0 192.5 L 154.3 185.4 L 155.5 179.4 L 157.4 180.7 L 163.6 172.9 L 167.0 172.3 L 171.4 181.3 L 181.0 181.0 L 195.6 174.4 L 201.0 177.1 L 203.7 172.1 L 212.3 175.8 L 212.6 186.5 L 220.4 189.6 L 221.2 194.0 L 228.0 191.9 L 228.4 187.5 L 241.5 186.4 L 240.4 196.4 L 241.5 202.1 L 234.2 215.7 L 233.3 225.4 L 239.0 238.0 L 232.0 235.0 L 225.9 251.1 L 219.0 250.3 L 215.8 247.8 L 205.8 248.6 Z"></path>
<path class="c-muni" d="M 99.9 85.4 L 99.3 74.0 L 91.4 72.1 L 91.6 60.4 L 99.9 54.6 L 94.0 52.5 L 89.4 44.2 L 96.0 42.9 L 98.1 35.5 L 95.9 28.6 L 96.4 10.9 L 97.6 5.9 L 111.3 5.3 L 113.7 -2.8 L 116.7 -7.3 L 140.3 0.8 L 141.7 -4.1 L 148.4 -4.6 L 151.7 1.2 L 156.8 -0.6 L 164.8 2.5 L 165.7 11.8 L 175.5 24.9 L 163.7 36.2 L 169.8 41.1 L 167.9 47.0 L 168.3 54.5 L 162.7 60.8 L 161.7 64.7 L 156.1 71.4 L 149.6 74.9 L 147.8 81.0 L 143.8 79.1 L 132.8 82.7 L 128.1 78.6 L 119.6 82.1 L 114.5 80.3 L 109.0 82.9 L 101.9 82.6 L 99.9 85.4 Z"></path>
<path class="c-muni" d="M 353.7 24.8 L 346.5 19.4 L 351.4 13.9 L 345.3 11.1 L 343.7 7.7 L 347.4 2.4 L 354.0 2.6 L 365.7 -14.1 L 374.3 -18.5 L 384.1 -16.8 L 383.6 -14.1 L 398.9 -10.5 L 405.7 -12.4 L 408.7 -26.7 L 412.3 -25.2 L 422.3 -22.8 L 429.0 -15.9 L 418.4 -6.7 L 410.6 7.2 L 398.3 17.2 L 391.1 14.7 L 388.7 18.2 L 381.7 18.4 L 375.8 15.8 L 369.5 17.6 L 364.5 16.4 L 357.9 19.6 L 353.7 24.8 Z"></path>
<path class="c-muni c-muni-in" d="M 208.4 137.3 L 203.3 134.8 L 205.0 128.2 L 197.6 130.1 L 199.5 124.4 L 191.9 116.9 L 187.1 118.2 L 178.0 113.4 L 177.5 105.9 L 170.7 107.8 L 162.8 105.7 L 160.7 97.4 L 171.8 94.5 L 185.3 89.2 L 189.7 89.4 L 193.0 82.4 L 202.6 82.1 L 208.5 87.5 L 218.6 86.7 L 224.5 97.4 L 225.1 104.1 L 223.2 109.6 L 229.1 113.6 L 225.0 119.2 L 228.6 121.6 L 224.1 133.0 L 216.9 132.1 L 208.4 137.3 Z"></path>
<path class="c-muni" d="M 264.3 400.2 L 263.1 394.5 L 265.7 388.0 L 279.9 383.9 L 278.1 376.9 L 267.6 369.1 L 273.3 360.5 L 272.9 351.2 L 280.4 353.9 L 295.1 356.5 L 293.9 360.7 L 297.9 366.0 L 296.6 373.4 L 291.0 380.0 L 291.4 386.8 L 299.0 390.5 L 302.0 397.3 L 297.6 400.9 L 291.3 397.9 L 288.4 409.0 L 288.9 418.0 L 283.7 415.5 L 277.3 416.7 L 270.5 414.7 L 272.0 403.0 L 264.3 400.2 Z"></path>
<path class="c-muni" d="M 30.1 314.0 L 24.6 310.6 L 20.0 298.1 L 14.6 307.3 L 6.9 307.0 L -3.9 298.5 L -11.3 301.8 L -14.4 300.6 L -21.3 303.9 L -28.6 298.5 L -29.6 288.1 L -34.2 286.3 L -32.5 281.3 L -35.7 269.6 L -36.5 264.0 L -34.6 259.8 L -29.6 257.2 L -25.2 249.2 L -17.5 248.8 L -8.4 253.9 L -3.9 253.5 L 1.5 249.6 L 8.5 255.7 L 15.3 251.8 L 23.4 253.4 L 28.3 257.2 L 29.9 261.3 L 21.3 268.7 L 31.0 275.9 L 24.9 282.7 L 27.3 287.2 L 33.8 288.2 L 34.7 295.9 L 41.5 300.2 L 37.0 310.0 L 30.1 314.0 Z"></path>
<path class="c-muni" d="M 79.0 218.1 L 78.5 215.7 L 65.4 215.0 L 59.4 213.5 L 60.1 207.0 L 63.1 200.2 L 62.9 195.2 L 57.6 194.8 L 52.6 186.3 L 50.0 178.1 L 52.2 170.2 L 57.2 168.7 L 58.4 163.8 L 66.6 168.1 L 60.8 190.6 L 70.2 188.6 L 74.7 193.4 L 82.8 185.8 L 90.2 185.2 L 92.6 179.5 L 101.9 180.2 L 105.6 177.9 L 111.0 186.5 L 106.6 191.1 L 108.7 198.1 L 107.2 203.1 L 101.9 208.4 L 95.1 209.7 L 93.9 219.8 L 89.7 222.5 L 79.0 218.1 Z"></path>
<path class="c-muni" d="M 330.9 325.5 L 325.0 321.2 L 307.1 312.8 L 314.6 305.2 L 316.5 296.8 L 320.6 288.3 L 318.7 286.5 L 326.5 285.2 L 327.4 282.3 L 338.7 283.6 L 338.3 277.4 L 348.2 271.9 L 356.0 273.2 L 360.8 279.5 L 365.6 280.3 L 368.9 288.9 L 374.7 286.9 L 379.0 293.1 L 390.7 295.6 L 392.8 307.8 L 385.7 310.5 L 367.0 310.4 L 361.1 313.3 L 352.4 314.6 L 344.7 317.7 L 343.5 322.4 L 330.9 325.5 Z"></path>
<path class="c-muni" d="M 352.8 394.6 L 356.5 392.2 L 359.1 385.8 L 352.2 380.0 L 346.2 379.8 L 344.8 365.7 L 356.4 366.6 L 357.5 361.6 L 374.0 357.6 L 377.3 348.5 L 385.0 346.4 L 385.9 350.5 L 382.4 354.3 L 387.6 357.7 L 391.5 366.8 L 385.8 374.9 L 391.9 383.4 L 386.7 389.3 L 384.8 394.9 L 378.3 397.1 L 377.8 396.5 L 377.4 391.2 L 370.4 395.8 L 370.2 399.3 L 361.5 396.5 L 357.0 397.8 L 352.8 394.6 Z"></path>
<path class="c-muni" d="M 419.5 378.4 L 417.7 371.1 L 407.1 372.0 L 399.2 369.4 L 397.5 365.1 L 391.5 366.8 L 387.6 357.7 L 382.4 354.3 L 385.9 350.5 L 385.0 346.4 L 379.5 342.3 L 381.2 333.8 L 376.1 331.4 L 379.6 320.0 L 376.1 319.7 L 374.5 310.3 L 385.7 310.5 L 392.8 307.8 L 400.4 305.8 L 398.3 310.6 L 399.4 318.5 L 408.0 318.3 L 413.6 327.6 L 413.7 330.7 L 419.5 335.3 L 420.1 349.3 L 426.6 348.6 L 429.0 345.1 L 436.5 340.4 L 441.5 344.2 L 437.6 348.8 L 430.7 348.7 L 425.1 356.8 L 429.1 374.2 L 424.9 374.1 L 419.5 378.4 Z"></path>
<path class="c-muni" d="M 217.2 413.9 L 213.9 397.2 L 211.8 393.4 L 216.9 390.1 L 216.0 385.5 L 220.7 376.7 L 218.8 371.8 L 228.3 367.1 L 234.2 369.4 L 237.2 366.2 L 246.4 374.6 L 248.8 379.8 L 254.3 374.7 L 265.7 388.0 L 263.1 394.5 L 264.3 400.2 L 254.8 406.6 L 239.8 408.5 L 229.6 406.8 L 221.9 413.1 L 217.2 413.9 Z"></path>
<path class="c-muni c-muni-in" d="M 236.0 248.7 L 225.9 251.1 L 232.0 235.0 L 239.0 238.0 L 233.3 225.4 L 234.2 215.7 L 241.5 202.1 L 240.4 196.4 L 241.5 186.4 L 246.2 185.1 L 253.6 174.8 L 258.3 177.1 L 266.0 168.1 L 272.8 166.7 L 275.1 171.0 L 276.2 181.5 L 282.9 184.1 L 275.1 194.3 L 277.4 199.4 L 277.7 208.9 L 280.9 212.0 L 277.6 217.6 L 277.0 223.9 L 283.2 228.7 L 288.2 228.8 L 290.3 232.9 L 283.8 236.8 L 277.8 237.3 L 264.9 248.5 L 259.9 246.9 L 256.9 251.7 L 259.5 259.9 L 255.6 259.7 L 248.4 252.3 L 240.8 253.6 L 236.0 248.7 Z"></path>
<path class="c-muni" d="M 58.4 163.8 L 53.1 158.7 L 47.3 158.2 L 44.3 153.4 L 45.1 142.7 L 54.1 141.5 L 69.3 142.6 L 72.7 137.8 L 71.2 134.1 L 75.8 126.1 L 73.4 121.4 L 73.7 110.9 L 77.3 108.3 L 83.6 114.3 L 94.3 121.8 L 93.1 129.1 L 99.1 131.8 L 98.0 139.9 L 103.4 142.1 L 100.3 150.8 L 88.2 150.2 L 88.1 161.0 L 91.3 165.0 L 87.9 175.6 L 82.8 181.6 L 82.8 185.8 L 74.7 193.4 L 70.2 188.6 L 60.8 190.6 L 66.6 168.1 L 58.4 163.8 Z"></path>
<path class="c-muni c-muni-in" d="M 77.3 108.3 L 76.9 100.8 L 73.5 86.2 L 81.0 90.5 L 85.8 89.1 L 89.4 91.9 L 99.9 85.4 L 101.9 82.6 L 109.0 82.9 L 114.5 80.3 L 119.6 82.1 L 128.1 78.6 L 132.8 82.7 L 143.8 79.1 L 147.8 81.0 L 148.3 91.5 L 154.6 97.1 L 160.7 97.4 L 162.8 105.7 L 170.7 107.8 L 177.5 105.9 L 178.0 113.4 L 187.1 118.2 L 182.7 124.5 L 177.7 126.2 L 175.3 120.9 L 170.6 125.4 L 167.0 129.8 L 145.0 134.4 L 139.1 142.2 L 135.1 140.8 L 128.2 130.8 L 119.9 132.3 L 112.8 135.1 L 111.2 139.1 L 103.4 142.1 L 98.0 139.9 L 99.1 131.8 L 93.1 129.1 L 94.3 121.8 L 83.6 114.3 L 77.3 108.3 Z"></path>
<path class="c-muni" d="M 337.9 37.5 L 342.7 37.0 L 345.7 31.9 L 355.7 32.9 L 353.7 24.8 L 357.9 19.6 L 364.5 16.4 L 369.5 17.6 L 375.8 15.8 L 381.7 18.4 L 388.7 18.2 L 391.1 14.7 L 398.3 17.2 L 406.7 18.7 L 412.7 24.8 L 410.2 29.2 L 413.4 36.1 L 407.3 43.9 L 401.5 45.5 L 404.4 55.1 L 397.7 57.1 L 393.4 49.4 L 382.2 52.2 L 379.0 56.4 L 367.7 55.9 L 366.4 43.5 L 358.7 40.3 L 350.2 40.9 L 337.9 37.5 Z"></path>
<path class="c-muni" d="M 380.7 136.9 L 373.4 136.9 L 368.8 129.4 L 363.5 131.3 L 360.6 126.8 L 360.9 121.0 L 357.6 116.8 L 363.8 111.5 L 366.9 99.8 L 374.4 109.4 L 379.2 102.0 L 392.3 91.7 L 400.5 92.4 L 399.8 101.5 L 407.3 110.8 L 409.5 105.9 L 405.7 101.9 L 411.2 96.5 L 401.8 91.4 L 403.3 80.0 L 410.9 75.0 L 418.7 82.9 L 423.9 85.6 L 431.3 86.7 L 432.7 92.4 L 423.1 99.6 L 426.0 105.5 L 421.4 109.5 L 420.1 119.3 L 411.0 124.5 L 411.6 131.4 L 397.0 126.4 L 393.9 130.7 L 389.1 131.8 L 380.7 136.9 Z"></path>
<path class="c-muni" d="M 148.6 388.4 L 144.2 383.5 L 130.3 377.7 L 126.4 374.5 L 124.6 368.9 L 127.8 361.6 L 133.6 361.3 L 136.7 354.6 L 130.5 351.5 L 131.9 347.8 L 124.8 342.1 L 124.2 335.7 L 125.9 329.8 L 136.8 329.3 L 146.8 333.1 L 154.1 329.3 L 165.2 326.5 L 168.4 324.1 L 178.5 334.3 L 177.7 337.7 L 181.2 351.3 L 176.2 361.3 L 169.2 364.9 L 163.0 363.9 L 166.2 370.3 L 159.4 383.5 L 149.1 387.8 L 148.6 388.4 Z"></path>
<path class="c-muni" d="M -19.0 74.5 L -20.9 71.7 L -20.1 64.7 L -13.2 59.8 L -12.7 53.7 L -16.9 49.5 L -17.0 37.7 L -11.4 35.0 L 1.2 36.0 L 10.6 28.3 L 13.9 21.2 L 12.0 17.7 L 11.1 6.2 L 20.9 8.8 L 24.4 25.1 L 23.2 35.9 L 18.0 36.9 L 14.6 47.0 L 18.9 49.0 L 18.9 56.4 L 33.3 56.8 L 40.6 52.6 L 45.2 57.8 L 54.6 63.7 L 54.4 65.7 L 44.1 77.6 L 43.6 80.0 L 50.1 88.4 L 40.8 96.4 L 34.8 96.5 L 31.8 93.5 L 25.7 93.9 L 22.3 89.2 L 16.6 90.0 L 14.8 84.7 L 6.8 96.3 L 2.4 87.2 L -4.9 86.0 L -8.0 81.4 L -12.7 80.7 L -19.0 74.5 Z"></path>
<path class="c-muni" d="M 62.0 327.8 L 63.7 337.5 L 70.1 338.2 L 70.7 347.7 L 65.5 356.5 L 67.5 366.7 L 62.8 374.6 L 56.4 378.1 L 50.0 376.9 L 44.1 371.1 L 42.1 357.9 L 47.4 353.3 L 42.8 346.3 L 35.7 348.1 L 33.4 343.6 L 32.7 334.7 L 35.2 328.2 L 27.3 317.1 L 30.1 314.0 L 37.0 310.0 L 39.9 312.9 L 53.8 318.4 L 57.0 326.6 L 62.0 327.8 Z"></path>
<path class="c-muni" d="M -32.9 168.0 L -33.4 160.2 L -36.5 157.0 L -41.8 143.8 L -50.1 147.4 L -67.7 144.0 L -68.6 133.5 L -67.5 123.0 L -55.3 120.7 L -55.2 116.5 L -50.0 105.3 L -53.2 100.7 L -47.5 94.6 L -42.1 91.7 L -30.7 89.6 L -23.4 86.7 L -24.3 75.6 L -19.0 74.5 L -12.7 80.7 L -8.0 81.4 L -4.9 86.0 L 2.4 87.2 L 6.8 96.3 L 14.8 84.7 L 16.6 90.0 L 22.3 89.2 L 25.7 93.9 L 31.8 93.5 L 34.8 96.5 L 33.4 115.4 L 36.8 121.8 L 36.0 133.2 L 27.7 135.2 L 27.9 149.9 L 29.8 155.7 L 25.9 157.1 L 13.4 156.3 L 11.8 150.6 L 7.7 150.4 L -2.3 159.2 L -9.8 161.7 L -13.1 161.9 L -16.9 169.0 L -31.0 170.1 L -32.9 168.0 Z M -15.1 141.9 L -5.3 138.7 L -3.4 134.3 L -3.5 123.8 L -12.1 119.6 L -15.5 127.3 L -22.6 130.4 L -24.9 137.7 L -15.1 141.9 Z"></path>
<path class="c-muni" d="M 28.3 257.2 L 23.4 253.4 L 15.3 251.8 L 8.5 255.7 L 1.5 249.6 L -3.9 253.5 L -8.4 253.9 L -17.5 248.8 L -18.4 242.6 L -23.6 243.9 L -27.2 239.2 L -25.6 235.5 L -16.0 237.6 L -5.9 231.2 L -3.6 222.9 L 6.9 213.0 L 15.8 207.2 L 17.7 208.5 L 25.2 218.5 L 29.2 213.1 L 34.3 212.7 L 33.2 228.5 L 36.1 235.2 L 43.5 230.7 L 42.9 237.8 L 37.5 244.4 L 38.1 252.3 L 36.5 255.3 L 30.3 254.6 L 28.3 257.2 Z"></path>
<path class="c-muni" d="M 349.9 177.4 L 342.2 173.7 L 329.1 172.8 L 325.1 170.6 L 323.5 163.7 L 317.6 154.8 L 315.5 143.5 L 321.0 142.3 L 316.0 133.0 L 315.2 128.0 L 323.3 121.8 L 329.0 128.8 L 336.3 128.2 L 341.4 125.2 L 340.6 118.1 L 351.0 119.7 L 357.6 116.8 L 360.9 121.0 L 360.6 126.8 L 363.5 131.3 L 368.8 129.4 L 373.4 136.9 L 380.7 136.9 L 383.8 141.8 L 377.9 157.1 L 364.0 155.9 L 360.4 161.6 L 348.9 159.8 L 350.4 164.4 L 350.9 176.6 L 349.9 177.4 Z"></path>
<path class="c-muni" d="M 363.1 238.5 L 365.0 230.0 L 371.7 230.3 L 374.2 233.9 L 381.8 229.5 L 382.3 225.7 L 393.6 225.8 L 396.7 223.8 L 402.9 228.7 L 399.9 236.3 L 398.4 245.2 L 404.3 250.3 L 398.3 254.9 L 393.2 256.2 L 385.8 266.4 L 381.0 269.4 L 360.9 251.2 L 360.7 243.9 L 363.1 238.5 Z"></path>
<path class="c-muni" d="M 160.7 97.4 L 154.6 97.1 L 148.3 91.5 L 147.8 81.0 L 149.6 74.9 L 156.1 71.4 L 161.7 64.7 L 162.7 60.8 L 168.3 54.5 L 167.9 47.0 L 169.8 41.1 L 163.7 36.2 L 175.5 24.9 L 179.9 29.5 L 192.4 27.0 L 198.5 28.2 L 199.5 33.6 L 203.7 40.6 L 200.9 44.2 L 205.3 47.6 L 210.4 55.1 L 213.8 69.8 L 206.5 73.3 L 200.4 79.3 L 202.6 82.1 L 193.0 82.4 L 189.7 89.4 L 185.3 89.2 L 171.8 94.5 L 160.7 97.4 Z"></path>
<path class="c-muni c-muni-in" d="M 119.9 132.3 L 128.2 130.8 L 135.1 140.8 L 139.1 142.2 L 145.0 134.4 L 167.0 129.8 L 170.6 125.4 L 177.7 130.1 L 179.3 137.4 L 183.7 138.8 L 181.9 146.0 L 186.0 150.7 L 186.2 159.8 L 173.2 163.9 L 167.0 172.3 L 163.6 172.9 L 157.4 180.7 L 155.5 179.4 L 143.2 177.4 L 135.8 179.3 L 131.6 165.7 L 133.3 157.8 L 129.1 153.5 L 124.0 153.7 L 120.8 150.6 L 122.9 144.9 L 119.9 132.3 Z"></path>
<path class="c-muni" d="M 198.5 28.2 L 192.4 27.0 L 179.9 29.5 L 175.5 24.9 L 165.7 11.8 L 164.8 2.5 L 156.8 -0.6 L 151.7 1.2 L 148.4 -4.6 L 151.0 -13.8 L 149.2 -19.1 L 174.3 -20.3 L 178.0 -16.8 L 185.9 -14.8 L 195.9 -9.7 L 194.9 -2.3 L 201.4 -0.4 L 217.2 1.2 L 212.7 27.8 L 207.5 26.2 L 198.5 28.2 Z"></path>
<path class="c-muni" d="M 7.8 421.1 L 8.5 418.8 L -3.3 415.6 L -9.4 408.1 L -17.5 407.3 L -16.4 403.2 L -30.1 389.1 L -35.8 393.8 L -38.4 390.3 L -28.9 384.8 L -26.2 386.5 L -21.8 380.5 L -18.4 383.8 L -14.3 378.3 L -17.0 375.3 L -15.8 371.6 L -9.3 373.0 L -5.9 368.2 L -2.4 358.8 L 8.5 355.9 L 13.8 356.3 L 17.8 362.5 L 14.3 373.5 L 32.5 377.7 L 34.5 385.2 L 45.3 384.6 L 44.4 392.5 L 55.6 394.7 L 59.5 403.2 L 60.3 410.8 L 50.6 412.5 L 49.2 420.7 L 34.4 422.0 L 31.9 426.2 L 26.6 423.5 L 16.0 422.7 L 10.4 424.1 L 7.8 421.1 Z"></path>
<path class="c-muni" d="M -2.4 358.8 L -10.0 355.5 L -9.4 348.0 L -19.8 342.6 L -23.7 346.4 L -29.8 344.6 L -27.2 336.8 L -28.3 331.8 L -35.6 329.0 L -33.4 320.3 L -21.6 322.6 L -20.6 315.5 L -11.3 301.8 L -3.9 298.5 L 6.9 307.0 L 14.6 307.3 L 20.0 298.1 L 24.6 310.6 L 30.1 314.0 L 27.3 317.1 L 35.2 328.2 L 32.7 334.7 L 33.4 343.6 L 23.2 341.8 L 17.7 350.6 L 7.7 350.3 L 8.5 355.9 L -2.4 358.8 Z"></path>
<path class="c-muni c-muni-in" d="M 185.3 280.6 L 183.7 278.0 L 184.1 269.0 L 184.5 265.3 L 193.2 262.2 L 200.1 256.2 L 205.8 248.6 L 215.8 247.8 L 219.0 250.3 L 225.9 251.1 L 236.0 248.7 L 239.2 255.3 L 235.0 258.5 L 234.6 263.6 L 239.1 266.7 L 244.1 265.5 L 246.5 270.3 L 246.1 272.4 L 233.3 283.5 L 229.5 285.1 L 223.9 289.4 L 212.2 282.8 L 210.1 285.3 L 198.7 279.8 L 187.4 278.7 L 185.3 280.6 Z"></path>
<path class="c-muni c-muni-in" d="M 178.5 334.3 L 168.4 324.1 L 168.0 316.1 L 171.4 306.1 L 176.1 304.8 L 184.0 298.3 L 187.8 286.2 L 185.3 280.6 L 187.4 278.7 L 198.7 279.8 L 210.1 285.3 L 212.2 282.8 L 223.9 289.4 L 216.8 297.2 L 208.0 299.8 L 204.6 303.2 L 193.3 309.6 L 191.6 314.3 L 193.8 320.6 L 193.8 329.5 L 191.5 330.0 L 188.6 331.4 L 181.0 329.5 L 178.5 334.3 Z"></path>
<path class="c-muni" d="M 60.3 450.1 L 57.9 445.9 L 61.7 437.6 L 58.0 437.3 L 50.9 427.4 L 49.2 420.7 L 50.6 412.5 L 60.3 410.8 L 59.5 403.2 L 55.6 394.7 L 56.9 388.2 L 61.6 388.0 L 66.7 378.8 L 69.7 386.3 L 77.8 384.9 L 81.2 379.7 L 88.0 384.0 L 88.7 387.8 L 86.1 392.9 L 87.5 396.6 L 97.1 399.3 L 92.9 403.9 L 91.1 410.0 L 91.5 419.0 L 94.0 423.9 L 99.5 422.9 L 102.3 431.6 L 101.3 436.0 L 89.9 437.1 L 83.3 435.3 L 74.0 444.6 L 60.3 450.1 Z"></path>
<path class="c-muni" d="M 62.0 327.8 L 57.0 326.6 L 53.8 318.4 L 39.9 312.9 L 37.0 310.0 L 41.5 300.2 L 46.2 294.8 L 55.4 294.3 L 54.1 288.1 L 61.1 286.3 L 68.6 278.3 L 74.0 276.1 L 79.6 276.3 L 82.3 279.9 L 87.8 274.0 L 96.0 280.3 L 95.7 290.1 L 99.6 298.8 L 93.9 302.4 L 93.7 312.7 L 96.5 320.0 L 86.5 316.9 L 82.8 319.1 L 79.3 314.0 L 69.4 312.6 L 63.7 313.7 L 63.1 321.1 L 66.5 326.5 L 62.0 327.8 Z"></path>
<path class="c-muni" d="M 41.5 300.2 L 34.7 295.9 L 33.8 288.2 L 27.3 287.2 L 24.9 282.7 L 31.0 275.9 L 21.3 268.7 L 29.9 261.3 L 28.3 257.2 L 30.3 254.6 L 36.5 255.3 L 38.1 252.3 L 43.8 249.2 L 52.4 249.1 L 52.3 243.3 L 65.8 245.5 L 73.4 245.4 L 73.3 250.4 L 69.5 254.8 L 72.5 261.4 L 78.1 262.3 L 74.0 276.1 L 68.6 278.3 L 61.1 286.3 L 54.1 288.1 L 55.4 294.3 L 46.2 294.8 L 41.5 300.2 Z"></path>
<path class="c-muni c-muni-in" d="M 142.4 263.4 L 133.6 251.9 L 140.8 245.6 L 132.7 237.5 L 139.6 232.0 L 137.4 225.0 L 132.9 217.4 L 139.5 212.4 L 130.3 209.2 L 136.4 204.0 L 142.6 204.7 L 143.0 211.4 L 147.3 210.7 L 149.9 203.7 L 156.9 206.6 L 160.8 211.5 L 166.7 214.4 L 174.1 212.5 L 184.0 223.6 L 181.9 233.8 L 186.6 236.2 L 194.5 237.2 L 198.6 240.7 L 205.5 241.8 L 205.8 248.6 L 200.1 256.2 L 193.2 262.2 L 184.5 265.3 L 184.1 269.0 L 181.0 268.9 L 174.4 262.5 L 165.4 264.9 L 158.9 264.5 L 154.6 261.9 L 142.4 263.4 Z"></path>
<path class="c-muni" d="M 344.5 403.6 L 352.8 394.6 L 357.0 397.8 L 361.5 396.5 L 370.2 399.3 L 370.4 395.8 L 377.4 391.2 L 377.8 396.5 L 374.5 401.5 L 377.0 407.5 L 371.8 412.7 L 366.8 407.6 L 356.2 404.3 L 351.4 406.4 L 344.5 403.6 Z M 403.6 431.5 L 397.7 428.2 L 388.9 415.3 L 392.8 413.1 L 398.8 405.6 L 387.1 406.1 L 378.3 397.1 L 384.8 394.9 L 386.7 389.3 L 391.9 383.4 L 385.8 374.9 L 391.5 366.8 L 397.5 365.1 L 399.2 369.4 L 407.1 372.0 L 417.7 371.1 L 421.1 384.5 L 418.9 387.6 L 419.9 395.4 L 413.3 391.4 L 406.1 391.7 L 406.0 402.2 L 408.3 415.2 L 415.9 420.6 L 414.0 430.9 L 408.8 425.4 L 403.6 431.5 Z"></path>
<path class="c-muni" d="M 248.0 121.4 L 242.2 119.1 L 235.4 112.9 L 233.0 108.0 L 225.1 104.1 L 224.5 97.4 L 218.6 86.7 L 208.5 87.5 L 202.6 82.1 L 200.4 79.3 L 206.5 73.3 L 213.8 69.8 L 211.9 61.6 L 219.8 59.8 L 228.7 62.0 L 232.4 67.5 L 240.7 71.9 L 247.9 67.5 L 257.1 68.1 L 262.2 70.0 L 270.2 69.2 L 270.0 88.4 L 267.1 100.7 L 254.4 99.2 L 256.3 109.5 L 252.9 114.9 L 249.6 114.5 L 248.0 121.4 Z"></path>
<path class="c-muni" d="M 399.9 236.3 L 402.9 228.7 L 396.7 223.8 L 396.0 216.7 L 389.4 213.8 L 389.7 206.5 L 386.4 201.3 L 387.9 191.6 L 391.6 186.4 L 397.0 184.1 L 398.4 177.9 L 399.4 171.8 L 409.3 176.7 L 420.0 187.0 L 425.7 190.0 L 438.4 204.0 L 440.5 205.2 L 434.7 217.6 L 435.5 224.7 L 427.3 221.7 L 423.7 227.7 L 419.2 229.3 L 417.2 237.8 L 409.7 236.3 L 401.8 239.4 L 399.9 236.3 Z"></path>
<path class="c-muni" d="M 346.0 186.0 L 348.8 185.2 L 349.9 177.4 L 350.9 176.6 L 365.3 174.0 L 375.7 170.7 L 390.9 169.8 L 390.2 175.8 L 398.4 177.9 L 397.0 184.1 L 391.6 186.4 L 387.9 191.6 L 386.4 201.3 L 375.0 207.4 L 367.4 206.9 L 369.5 200.0 L 357.6 194.3 L 351.6 195.7 L 347.3 192.2 L 346.0 186.0 Z"></path>
<path class="c-muni" d="M 318.7 286.5 L 314.3 271.2 L 317.1 258.3 L 314.6 255.8 L 326.6 248.4 L 333.2 246.1 L 332.4 240.1 L 335.6 236.9 L 342.1 236.9 L 343.7 227.9 L 346.1 230.5 L 355.6 232.8 L 363.1 238.5 L 360.7 243.9 L 360.9 251.2 L 381.0 269.4 L 385.8 266.4 L 388.6 273.2 L 386.5 278.5 L 374.7 286.9 L 368.9 288.9 L 365.6 280.3 L 360.8 279.5 L 356.0 273.2 L 348.2 271.9 L 338.3 277.4 L 338.7 283.6 L 327.4 282.3 L 326.5 285.2 L 318.7 286.5 Z"></path>
<path class="c-muni" d="M 392.8 307.8 L 390.7 295.6 L 379.0 293.1 L 374.7 286.9 L 386.5 278.5 L 388.6 273.2 L 385.8 266.4 L 393.2 256.2 L 398.3 254.9 L 404.3 250.3 L 398.4 245.2 L 399.9 236.3 L 401.8 239.4 L 409.7 236.3 L 417.2 237.8 L 413.6 244.9 L 420.9 253.8 L 417.0 260.8 L 418.0 265.5 L 414.1 275.6 L 415.3 282.4 L 412.2 295.4 L 404.6 300.0 L 410.4 306.0 L 405.8 308.7 L 400.4 305.8 L 392.8 307.8 Z"></path>
<path class="c-muni" d="M 337.9 37.5 L 323.0 26.5 L 307.8 18.1 L 294.7 8.8 L 282.1 4.9 L 277.8 6.5 L 267.0 2.4 L 261.3 2.4 L 252.4 -0.5 L 249.8 -2.7 L 253.3 -6.0 L 261.4 -3.9 L 276.6 -14.2 L 285.6 -16.4 L 284.6 -9.7 L 294.2 -13.9 L 295.8 -21.1 L 294.0 -25.8 L 297.5 -29.4 L 302.3 -28.8 L 306.9 -33.0 L 315.1 -36.5 L 325.5 -39.1 L 327.6 -36.1 L 328.0 -28.2 L 325.7 -22.5 L 331.6 -8.6 L 328.9 1.6 L 343.7 7.7 L 345.3 11.1 L 351.4 13.9 L 346.5 19.4 L 353.7 24.8 L 355.7 32.9 L 345.7 31.9 L 342.7 37.0 L 337.9 37.5 Z"></path>
<path class="c-muni" d="M 297.9 366.0 L 308.3 363.6 L 312.5 365.4 L 314.6 354.3 L 322.6 353.4 L 322.0 357.8 L 330.4 357.6 L 330.2 363.0 L 337.4 365.0 L 338.8 360.9 L 344.8 365.7 L 346.2 379.8 L 352.2 380.0 L 359.1 385.8 L 356.5 392.2 L 352.8 394.6 L 344.5 403.6 L 339.7 406.4 L 333.7 406.5 L 326.8 399.2 L 322.3 398.4 L 316.9 403.1 L 310.1 402.9 L 302.0 397.3 L 299.0 390.5 L 291.4 386.8 L 291.0 380.0 L 296.6 373.4 L 297.9 366.0 Z"></path>
<path class="c-muni" d="M 191.5 330.0 L 193.8 329.5 L 193.8 320.6 L 191.6 314.3 L 193.3 309.6 L 204.6 303.2 L 208.0 299.8 L 216.8 297.2 L 223.6 302.2 L 229.8 309.9 L 228.1 313.8 L 233.1 318.2 L 234.5 326.1 L 231.0 338.5 L 224.0 350.0 L 215.9 353.7 L 218.3 357.4 L 211.2 359.2 L 211.6 353.7 L 203.4 355.0 L 207.7 344.3 L 199.5 345.6 L 194.2 340.5 L 196.8 336.1 L 191.5 330.0 Z"></path>
<path class="c-muni" d="M 149.1 387.8 L 159.4 383.5 L 166.2 370.3 L 163.0 363.9 L 171.0 375.1 L 180.2 374.3 L 180.6 366.8 L 176.2 361.3 L 181.2 351.3 L 177.7 337.7 L 178.5 334.3 L 181.0 329.5 L 188.6 331.4 L 191.5 330.0 L 196.8 336.1 L 194.2 340.5 L 199.5 345.6 L 207.7 344.3 L 203.4 355.0 L 211.6 353.7 L 211.2 359.2 L 218.3 357.4 L 221.8 358.5 L 228.3 367.1 L 218.8 371.8 L 220.7 376.7 L 216.0 385.5 L 216.9 390.1 L 211.8 393.4 L 208.7 390.7 L 201.6 393.4 L 191.2 390.7 L 189.5 396.4 L 185.8 399.0 L 179.3 393.9 L 173.0 392.3 L 168.2 388.2 L 164.7 393.1 L 156.0 394.0 L 149.1 387.8 Z"></path>
<path class="c-muni" d="M 163.0 363.9 L 169.2 364.9 L 176.2 361.3 L 180.6 366.8 L 180.2 374.3 L 171.0 375.1 L 163.0 363.9 Z"></path>
<path class="c-muni" d="M 162.0 413.4 L 153.6 414.0 L 145.5 411.3 L 137.5 399.5 L 142.0 396.5 L 149.1 387.8 L 156.0 394.0 L 164.7 393.1 L 168.2 388.2 L 173.0 392.3 L 179.3 393.9 L 185.8 399.0 L 189.5 396.4 L 191.2 390.7 L 201.6 393.4 L 208.7 390.7 L 211.8 393.4 L 213.9 397.2 L 217.8 417.8 L 212.1 419.2 L 190.1 415.9 L 186.0 418.8 L 181.0 413.5 L 170.9 416.1 L 162.0 413.4 Z"></path>
<path class="c-muni" d="M 96.0 280.3 L 87.8 274.0 L 90.8 264.2 L 86.3 263.4 L 86.5 250.9 L 89.4 247.6 L 98.2 242.5 L 102.1 242.6 L 112.1 250.4 L 113.2 258.7 L 104.1 267.1 L 106.5 274.7 L 99.1 280.7 L 96.0 280.3 Z"></path>
<path class="c-muni" d="M 231.0 338.5 L 234.5 326.1 L 233.1 318.2 L 228.1 313.8 L 229.8 309.9 L 223.6 302.2 L 216.8 297.2 L 223.9 289.4 L 229.5 285.1 L 230.1 292.4 L 236.7 289.9 L 244.3 297.1 L 251.7 299.9 L 256.4 304.2 L 253.4 309.1 L 255.5 312.5 L 266.6 314.7 L 268.0 310.3 L 272.4 310.2 L 275.6 316.0 L 277.3 327.3 L 272.6 335.0 L 272.9 339.6 L 268.5 345.9 L 260.3 346.3 L 259.8 336.2 L 256.7 335.2 L 250.5 343.2 L 246.7 340.1 L 231.0 338.5 Z"></path>
<path class="c-muni" d="M 73.5 86.2 L 75.2 77.4 L 69.5 75.4 L 65.6 67.4 L 59.9 67.9 L 54.6 63.7 L 45.2 57.8 L 40.6 52.6 L 33.3 56.8 L 18.9 56.4 L 18.9 49.0 L 14.6 47.0 L 18.0 36.9 L 23.2 35.9 L 24.4 25.1 L 20.9 8.8 L 38.3 0.9 L 43.9 3.1 L 53.5 1.4 L 56.8 -1.3 L 72.4 -5.8 L 75.4 0.1 L 80.1 3.2 L 86.2 3.0 L 89.2 10.7 L 96.4 10.9 L 95.9 28.6 L 98.1 35.5 L 96.0 42.9 L 89.4 44.2 L 94.0 52.5 L 99.9 54.6 L 91.6 60.4 L 91.4 72.1 L 99.3 74.0 L 99.9 85.4 L 89.4 91.9 L 85.8 89.1 L 81.0 90.5 L 73.5 86.2 Z"></path>
<path class="c-muni" d="M 72.4 -5.8 L 72.7 -19.1 L 75.0 -26.6 L 72.3 -30.7 L 79.6 -34.6 L 77.4 -43.9 L 87.9 -43.8 L 91.0 -37.9 L 94.6 -44.3 L 100.7 -47.5 L 110.2 -49.3 L 116.2 -46.9 L 121.5 -52.2 L 128.5 -52.1 L 129.8 -41.1 L 138.8 -38.2 L 141.1 -31.0 L 138.2 -26.8 L 138.0 -20.4 L 149.2 -19.1 L 151.0 -13.8 L 148.4 -4.6 L 141.7 -4.1 L 140.3 0.8 L 116.7 -7.3 L 113.7 -2.8 L 111.3 5.3 L 97.6 5.9 L 96.4 10.9 L 89.2 10.7 L 86.2 3.0 L 80.1 3.2 L 75.4 0.1 L 72.4 -5.8 Z"></path>
<path class="c-muni" d="M 366.9 99.8 L 352.5 86.6 L 347.7 68.1 L 333.7 70.9 L 332.1 64.8 L 310.1 69.2 L 316.1 53.4 L 311.8 50.5 L 314.7 47.6 L 315.3 36.8 L 323.0 26.5 L 337.9 37.5 L 350.2 40.9 L 358.7 40.3 L 366.4 43.5 L 367.7 55.9 L 379.0 56.4 L 382.2 52.2 L 393.4 49.4 L 397.7 57.1 L 404.4 55.1 L 407.5 66.9 L 410.9 75.0 L 403.3 80.0 L 401.8 91.4 L 400.5 92.4 L 392.3 91.7 L 379.2 102.0 L 374.4 109.4 L 366.9 99.8 Z"></path>
<path class="c-muni" d="M 410.2 158.9 L 399.2 153.4 L 399.6 148.0 L 396.4 143.8 L 387.7 140.7 L 383.8 141.8 L 380.7 136.9 L 389.1 131.8 L 393.9 130.7 L 397.0 126.4 L 411.6 131.4 L 411.0 124.5 L 420.1 119.3 L 422.6 127.0 L 432.7 127.6 L 430.4 136.8 L 426.0 145.9 L 431.7 148.4 L 421.0 155.6 L 416.1 163.5 L 410.2 158.9 Z"></path>
<path class="c-muni" d="M 285.3 305.6 L 284.4 294.9 L 277.6 286.5 L 276.4 278.8 L 282.0 281.5 L 286.3 276.9 L 294.8 271.6 L 305.7 263.0 L 304.2 255.8 L 307.7 253.1 L 314.6 255.8 L 317.1 258.3 L 314.3 271.2 L 318.7 286.5 L 320.6 288.3 L 316.5 296.8 L 314.6 305.2 L 307.1 312.8 L 299.0 309.7 L 300.2 304.1 L 296.0 302.4 L 290.7 309.1 L 285.3 305.6 Z"></path>
<path class="c-muni c-muni-in" d="M 267.5 145.2 L 267.3 142.9 L 271.4 136.2 L 275.2 136.1 L 275.5 129.4 L 288.0 126.9 L 290.1 136.9 L 286.6 145.1 L 286.6 150.7 L 291.0 153.4 L 288.1 158.9 L 283.7 161.5 L 271.3 158.7 L 274.6 149.3 L 267.5 145.2 Z"></path>
<path class="c-muni c-muni-in" d="M 167.0 172.3 L 173.2 163.9 L 186.2 159.8 L 186.0 150.7 L 181.9 146.0 L 183.7 138.8 L 179.3 137.4 L 177.7 130.1 L 170.6 125.4 L 175.3 120.9 L 177.7 126.2 L 182.7 124.5 L 187.1 118.2 L 191.9 116.9 L 199.5 124.4 L 197.6 130.1 L 205.0 128.2 L 203.3 134.8 L 208.4 137.3 L 215.1 144.0 L 214.6 147.9 L 220.1 152.4 L 214.5 165.1 L 212.6 172.8 L 208.8 174.5 L 203.7 172.1 L 201.0 177.1 L 195.6 174.4 L 181.0 181.0 L 171.4 181.3 L 167.0 172.3 Z"></path>
<path class="c-muni" d="M 363.1 238.5 L 355.6 232.8 L 346.1 230.5 L 343.7 227.9 L 343.4 215.9 L 338.6 211.6 L 336.8 206.4 L 341.1 194.2 L 340.4 186.6 L 346.0 186.0 L 347.3 192.2 L 351.6 195.7 L 357.6 194.3 L 369.5 200.0 L 367.4 206.9 L 375.0 207.4 L 386.4 201.3 L 389.7 206.5 L 389.4 213.8 L 396.0 216.7 L 396.7 223.8 L 393.6 225.8 L 382.3 225.7 L 381.8 229.5 L 374.2 233.9 L 371.7 230.3 L 365.0 230.0 L 363.1 238.5 Z M 357.6 226.1 L 362.3 224.3 L 359.1 212.0 L 353.8 214.3 L 352.7 219.2 L 357.6 226.1 Z"></path>
<path class="c-muni" d="M 211.9 61.6 L 210.4 55.1 L 205.3 47.6 L 200.9 44.2 L 203.7 40.6 L 199.5 33.6 L 198.5 28.2 L 207.5 26.2 L 212.7 27.8 L 216.9 3.2 L 222.4 9.3 L 220.5 21.7 L 240.4 14.9 L 237.8 29.6 L 246.6 28.8 L 246.2 39.4 L 265.1 42.3 L 262.7 47.3 L 267.2 49.7 L 268.2 56.0 L 273.4 54.2 L 277.5 56.4 L 280.1 65.2 L 272.0 66.0 L 270.2 69.2 L 262.2 70.0 L 257.1 68.1 L 247.9 67.5 L 240.7 71.9 L 232.4 67.5 L 228.7 62.0 L 219.8 59.8 L 211.9 61.6 Z"></path>
          </g>
          <g class="counties-layer">
            <path class="c-county" d="M 456.5 108.4 L 459.7 110.8 L 462.5 119.6 L 463.0 125.6 L 468.6 136.5 L 471.2 143.9 L 461.3 145.7 L 460.0 148.6 L 442.5 150.2 L 440.2 158.3 L 444.5 163.2 L 446.3 178.1 L 449.5 179.8 L 448.4 195.3 L 445.2 199.4 L 448.8 203.5 L 451.0 211.0 L 438.4 204.0 L 425.7 190.0 L 420.0 187.0 L 409.3 176.7 L 399.4 171.8 L 398.4 177.9 L 390.2 175.8 L 390.9 169.8 L 375.7 170.7 L 365.3 174.0 L 350.9 176.6 L 350.4 164.4 L 348.9 159.8 L 360.4 161.6 L 364.0 155.9 L 377.9 157.1 L 383.8 141.8 L 380.7 136.9 L 373.4 136.9 L 368.8 129.4 L 363.5 131.3 L 360.6 126.8 L 360.9 121.0 L 357.6 116.8 L 363.8 111.5 L 366.9 99.8 L 374.4 109.4 L 379.2 102.0 L 392.3 91.7 L 400.5 92.4 L 401.8 91.4 L 403.3 80.0 L 410.9 75.0 L 418.7 82.9 L 423.9 85.6 L 436.2 87.4 L 441.2 93.2 L 442.4 97.9 L 447.8 104.8 L 456.5 108.4 Z"></path>
<path class="c-county" d="M 273.4 54.2 L 277.5 56.4 L 280.1 65.2 L 272.0 66.0 L 270.2 69.2 L 262.2 70.0 L 257.1 68.1 L 247.9 67.5 L 240.7 71.9 L 232.4 67.5 L 228.7 62.0 L 219.8 59.8 L 211.9 61.6 L 210.4 55.1 L 205.3 47.6 L 200.9 44.2 L 203.7 40.6 L 199.5 33.6 L 198.5 28.2 L 192.4 27.0 L 179.9 29.5 L 175.5 24.9 L 165.7 11.8 L 164.8 2.5 L 156.8 -0.6 L 151.7 1.2 L 148.4 -4.6 L 151.0 -13.8 L 149.2 -19.1 L 138.0 -20.4 L 138.2 -26.8 L 141.1 -31.0 L 138.8 -38.2 L 129.8 -41.1 L 128.5 -52.1 L 121.5 -52.2 L 117.1 -59.7 L 122.3 -63.2 L 124.5 -71.2 L 126.4 -86.0 L 128.7 -88.7 L 134.8 -102.2 L 134.4 -108.5 L 123.3 -107.7 L 122.2 -111.4 L 127.4 -125.1 L 136.8 -134.0 L 138.4 -138.5 L 145.5 -139.2 L 145.1 -153.3 L 149.6 -150.8 L 157.6 -153.0 L 166.6 -145.5 L 179.4 -152.0 L 181.6 -155.7 L 184.9 -157.9 L 190.5 -152.0 L 192.6 -142.9 L 197.7 -139.1 L 202.4 -130.3 L 205.4 -133.3 L 216.4 -133.6 L 219.0 -135.2 L 227.6 -128.5 L 226.7 -117.1 L 223.4 -113.6 L 223.2 -108.3 L 225.5 -111.1 L 232.6 -110.1 L 239.1 -112.9 L 245.7 -101.9 L 259.4 -91.5 L 261.2 -82.5 L 281.7 -78.6 L 282.1 -74.7 L 287.4 -71.0 L 283.9 -67.1 L 289.6 -67.6 L 296.8 -65.2 L 298.4 -57.5 L 306.7 -51.5 L 308.0 -42.3 L 306.9 -33.0 L 302.3 -28.8 L 297.5 -29.4 L 294.0 -25.8 L 295.8 -21.1 L 294.2 -13.9 L 284.6 -9.7 L 285.6 -16.4 L 276.6 -14.2 L 261.4 -3.9 L 253.3 -6.0 L 249.8 -2.7 L 247.2 -9.6 L 249.6 -15.8 L 261.4 -23.8 L 264.3 -27.7 L 265.8 -35.7 L 263.0 -46.5 L 259.6 -45.5 L 259.2 -39.3 L 255.0 -36.4 L 246.9 -36.7 L 242.0 -31.8 L 234.5 -30.4 L 221.5 -36.2 L 208.1 -37.5 L 207.2 -31.3 L 202.4 -32.0 L 203.5 -50.7 L 192.5 -48.9 L 191.1 -47.8 L 195.2 -37.0 L 194.7 -33.8 L 197.8 -24.1 L 187.3 -22.8 L 183.7 -28.5 L 174.3 -20.3 L 178.0 -16.8 L 185.9 -14.8 L 195.9 -9.7 L 194.9 -2.3 L 201.4 -0.4 L 217.2 1.2 L 216.9 3.2 L 222.4 9.3 L 220.5 21.7 L 240.4 14.9 L 239.8 19.3 L 247.5 20.8 L 248.5 5.5 L 252.4 -0.5 L 261.3 2.4 L 267.0 2.4 L 277.8 6.5 L 282.1 4.9 L 294.7 8.8 L 307.8 18.1 L 323.0 26.5 L 315.3 36.8 L 314.7 47.6 L 311.8 50.5 L 304.0 51.3 L 298.0 58.0 L 295.0 48.7 L 288.1 52.9 L 273.4 54.2 Z"></path>
<path class="c-county" d="M 266.0 168.1 L 258.3 177.1 L 253.6 174.8 L 246.2 185.1 L 241.5 186.4 L 228.4 187.5 L 228.0 191.9 L 221.2 194.0 L 220.4 189.6 L 212.6 186.5 L 212.3 175.8 L 208.8 174.5 L 212.6 172.8 L 214.5 165.1 L 220.1 152.4 L 214.6 147.9 L 215.1 144.0 L 208.4 137.3 L 216.9 132.1 L 224.1 133.0 L 228.6 121.6 L 225.0 119.2 L 229.1 113.6 L 223.2 109.6 L 225.1 104.1 L 224.5 97.4 L 218.6 86.7 L 208.5 87.5 L 202.6 82.1 L 200.4 79.3 L 206.5 73.3 L 213.8 69.8 L 211.9 61.6 L 219.8 59.8 L 228.7 62.0 L 232.4 67.5 L 240.7 71.9 L 247.9 67.5 L 257.1 68.1 L 262.2 70.0 L 270.2 69.2 L 272.0 66.0 L 280.1 65.2 L 277.5 56.4 L 273.4 54.2 L 288.1 52.9 L 295.0 48.7 L 298.0 58.0 L 304.0 51.3 L 311.8 50.5 L 316.1 53.4 L 310.1 69.2 L 332.1 64.8 L 333.7 70.9 L 347.7 68.1 L 352.5 86.6 L 366.9 99.8 L 363.8 111.5 L 357.6 116.8 L 360.9 121.0 L 360.6 126.8 L 363.5 131.3 L 368.8 129.4 L 373.4 136.9 L 380.7 136.9 L 383.8 141.8 L 377.9 157.1 L 364.0 155.9 L 360.4 161.6 L 348.9 159.8 L 350.4 164.4 L 350.9 176.6 L 349.9 177.4 L 348.8 185.2 L 340.4 186.6 L 341.1 194.2 L 336.8 206.4 L 338.6 211.6 L 343.4 215.9 L 343.7 227.9 L 342.1 236.9 L 335.6 236.9 L 332.4 240.1 L 333.2 246.1 L 326.6 248.4 L 314.6 255.8 L 307.7 253.1 L 306.5 242.3 L 310.2 235.6 L 310.0 231.1 L 297.7 230.4 L 290.3 232.9 L 288.2 228.8 L 283.2 228.7 L 277.0 223.9 L 277.6 217.6 L 280.9 212.0 L 277.7 208.9 L 277.4 199.4 L 275.1 194.3 L 282.9 184.1 L 276.2 181.5 L 275.1 171.0 L 272.8 166.7 L 266.0 168.1 Z"></path>
<path class="c-county" d="M 208.8 174.5 L 212.3 175.8 L 212.6 186.5 L 220.4 189.6 L 221.2 194.0 L 228.0 191.9 L 228.4 187.5 L 241.5 186.4 L 246.2 185.1 L 253.6 174.8 L 258.3 177.1 L 266.0 168.1 L 272.8 166.7 L 275.1 171.0 L 276.2 181.5 L 282.9 184.1 L 275.1 194.3 L 277.4 199.4 L 277.7 208.9 L 280.9 212.0 L 277.6 217.6 L 277.0 223.9 L 283.2 228.7 L 288.2 228.8 L 290.3 232.9 L 297.7 230.4 L 310.0 231.1 L 310.2 235.6 L 306.5 242.3 L 307.7 253.1 L 304.2 255.8 L 305.7 263.0 L 294.8 271.6 L 286.3 276.9 L 282.0 281.5 L 276.4 278.8 L 270.9 280.8 L 265.2 279.2 L 265.9 272.6 L 252.5 268.6 L 246.5 270.3 L 244.1 265.5 L 239.1 266.7 L 234.6 263.6 L 235.0 258.5 L 239.2 255.3 L 236.0 248.7 L 225.9 251.1 L 219.0 250.3 L 215.8 247.8 L 205.8 248.6 L 205.5 241.8 L 198.6 240.7 L 194.5 237.2 L 186.6 236.2 L 181.9 233.8 L 184.0 223.6 L 174.1 212.5 L 166.7 214.4 L 160.8 211.5 L 156.9 206.6 L 149.9 203.7 L 158.0 192.5 L 154.3 185.4 L 155.5 179.4 L 157.4 180.7 L 163.6 172.9 L 167.0 172.3 L 173.2 163.9 L 186.2 159.8 L 186.0 150.7 L 181.9 146.0 L 183.7 138.8 L 179.3 137.4 L 177.7 130.1 L 170.6 125.4 L 175.3 120.9 L 177.7 126.2 L 182.7 124.5 L 187.1 118.2 L 191.9 116.9 L 199.5 124.4 L 197.6 130.1 L 205.0 128.2 L 203.3 134.8 L 208.4 137.3 L 215.1 144.0 L 214.6 147.9 L 220.1 152.4 L 214.5 165.1 L 212.6 172.8 L 208.8 174.5 Z"></path>
<path class="c-county" d="M 46.9 -60.9 L 45.6 -65.7 L 52.9 -77.6 L 56.7 -84.8 L 71.5 -84.8 L 77.3 -82.2 L 85.4 -90.1 L 92.9 -85.3 L 101.8 -80.8 L 113.3 -84.2 L 119.1 -83.5 L 126.4 -86.0 L 124.5 -71.2 L 122.3 -63.2 L 117.1 -59.7 L 121.5 -52.2 L 128.5 -52.1 L 129.8 -41.1 L 138.8 -38.2 L 141.1 -31.0 L 138.2 -26.8 L 138.0 -20.4 L 149.2 -19.1 L 151.0 -13.8 L 148.4 -4.6 L 151.7 1.2 L 156.8 -0.6 L 164.8 2.5 L 165.7 11.8 L 175.5 24.9 L 163.7 36.2 L 169.8 41.1 L 167.9 47.0 L 168.3 54.5 L 162.7 60.8 L 161.7 64.7 L 156.1 71.4 L 149.6 74.9 L 147.8 81.0 L 143.8 79.1 L 132.8 82.7 L 128.1 78.6 L 119.6 82.1 L 114.5 80.3 L 109.0 82.9 L 101.9 82.6 L 99.9 85.4 L 89.4 91.9 L 85.8 89.1 L 81.0 90.5 L 73.5 86.2 L 75.2 77.4 L 69.5 75.4 L 65.6 67.4 L 59.9 67.9 L 54.6 63.7 L 45.2 57.8 L 40.6 52.6 L 33.3 56.8 L 18.9 56.4 L 18.9 49.0 L 14.6 47.0 L 18.0 36.9 L 23.2 35.9 L 24.4 25.1 L 20.9 8.8 L 30.1 4.8 L 31.2 -9.1 L 28.9 -15.3 L 37.6 -16.2 L 39.2 -21.3 L 25.2 -26.4 L 24.4 -39.5 L 27.3 -45.1 L 23.8 -50.6 L 20.1 -52.0 L 23.1 -54.9 L 33.4 -59.0 L 44.6 -59.3 L 46.9 -60.9 Z"></path>
<path class="c-county" d="M 343.7 227.9 L 343.4 215.9 L 338.6 211.6 L 336.8 206.4 L 341.1 194.2 L 340.4 186.6 L 348.8 185.2 L 349.9 177.4 L 350.9 176.6 L 365.3 174.0 L 375.7 170.7 L 390.9 169.8 L 390.2 175.8 L 398.4 177.9 L 399.4 171.8 L 409.3 176.7 L 420.0 187.0 L 425.7 190.0 L 438.4 204.0 L 440.5 205.2 L 434.7 217.6 L 435.5 224.7 L 427.3 221.7 L 423.7 227.7 L 419.2 229.3 L 417.2 237.8 L 413.6 244.9 L 420.9 253.8 L 417.0 260.8 L 418.0 265.5 L 414.1 275.6 L 415.3 282.4 L 412.2 295.4 L 404.6 300.0 L 410.4 306.0 L 405.8 308.7 L 400.4 305.8 L 392.8 307.8 L 390.7 295.6 L 379.0 293.1 L 374.7 286.9 L 368.9 288.9 L 365.6 280.3 L 360.8 279.5 L 356.0 273.2 L 348.2 271.9 L 338.3 277.4 L 338.7 283.6 L 327.4 282.3 L 326.5 285.2 L 318.7 286.5 L 314.3 271.2 L 317.1 258.3 L 314.6 255.8 L 326.6 248.4 L 333.2 246.1 L 332.4 240.1 L 335.6 236.9 L 342.1 236.9 L 343.7 227.9 Z"></path>
<path class="c-county" d="M 327.6 -36.1 L 340.0 -47.5 L 341.1 -43.7 L 346.3 -50.3 L 352.4 -53.2 L 352.9 -58.0 L 359.6 -54.5 L 367.9 -58.9 L 373.1 -58.6 L 378.1 -66.5 L 387.1 -63.0 L 391.5 -58.1 L 400.3 -72.7 L 404.5 -68.8 L 409.5 -69.7 L 411.6 -75.5 L 421.8 -68.6 L 431.8 -70.1 L 427.5 -65.9 L 424.9 -59.6 L 433.2 -56.0 L 422.0 -49.6 L 421.9 -47.3 L 417.0 -37.6 L 421.7 -31.3 L 419.2 -26.4 L 412.3 -25.2 L 422.3 -22.8 L 429.0 -15.9 L 433.3 -14.3 L 440.0 12.2 L 445.0 12.2 L 449.4 16.8 L 450.7 24.2 L 461.1 30.4 L 462.9 33.4 L 474.8 37.7 L 473.5 43.8 L 470.1 47.7 L 476.2 50.1 L 476.6 56.5 L 482.7 55.0 L 483.5 50.5 L 495.3 48.9 L 494.4 43.6 L 499.5 42.2 L 502.5 48.4 L 509.2 47.7 L 514.6 54.0 L 514.6 58.8 L 504.1 57.9 L 491.0 60.7 L 497.0 77.4 L 495.9 82.4 L 502.4 83.1 L 506.2 98.2 L 500.1 107.1 L 499.9 111.2 L 493.9 115.0 L 475.3 112.2 L 472.5 115.7 L 474.5 122.0 L 462.5 119.6 L 459.7 110.8 L 456.5 108.4 L 447.8 104.8 L 442.4 97.9 L 441.2 93.2 L 436.2 87.4 L 423.9 85.6 L 418.7 82.9 L 410.9 75.0 L 403.3 80.0 L 401.8 91.4 L 400.5 92.4 L 392.3 91.7 L 379.2 102.0 L 374.4 109.4 L 366.9 99.8 L 352.5 86.6 L 347.7 68.1 L 333.7 70.9 L 332.1 64.8 L 310.1 69.2 L 316.1 53.4 L 311.8 50.5 L 314.7 47.6 L 315.3 36.8 L 323.0 26.5 L 307.8 18.1 L 294.7 8.8 L 282.1 4.9 L 277.8 6.5 L 267.0 2.4 L 261.3 2.4 L 252.4 -0.5 L 249.8 -2.7 L 253.3 -6.0 L 261.4 -3.9 L 276.6 -14.2 L 285.6 -16.4 L 284.6 -9.7 L 294.2 -13.9 L 295.8 -21.1 L 294.0 -25.8 L 297.5 -29.4 L 302.3 -28.8 L 306.9 -33.0 L 315.1 -36.5 L 325.5 -39.1 L 327.6 -36.1 Z M 337.9 37.5 L 350.2 40.9 L 358.7 40.3 L 366.4 43.5 L 367.7 55.9 L 379.0 56.4 L 382.2 52.2 L 393.4 49.4 L 397.7 57.1 L 404.4 55.1 L 401.5 45.5 L 407.3 43.9 L 413.4 36.1 L 410.2 29.2 L 412.7 24.8 L 406.7 18.7 L 398.3 17.2 L 391.1 14.7 L 388.7 18.2 L 381.7 18.4 L 375.8 15.8 L 369.5 17.6 L 364.5 16.4 L 357.9 19.6 L 353.7 24.8 L 355.7 32.9 L 345.7 31.9 L 342.7 37.0 L 337.9 37.5 Z"></path>
<path class="c-county" d="M 77.3 108.3 L 76.9 100.8 L 73.5 86.2 L 81.0 90.5 L 85.8 89.1 L 89.4 91.9 L 99.9 85.4 L 101.9 82.6 L 109.0 82.9 L 114.5 80.3 L 119.6 82.1 L 128.1 78.6 L 132.8 82.7 L 143.8 79.1 L 147.8 81.0 L 149.6 74.9 L 156.1 71.4 L 161.7 64.7 L 162.7 60.8 L 168.3 54.5 L 167.9 47.0 L 169.8 41.1 L 163.7 36.2 L 175.5 24.9 L 179.9 29.5 L 192.4 27.0 L 198.5 28.2 L 199.5 33.6 L 203.7 40.6 L 200.9 44.2 L 205.3 47.6 L 210.4 55.1 L 213.8 69.8 L 206.5 73.3 L 200.4 79.3 L 202.6 82.1 L 208.5 87.5 L 218.6 86.7 L 224.5 97.4 L 225.1 104.1 L 223.2 109.6 L 229.1 113.6 L 225.0 119.2 L 228.6 121.6 L 224.1 133.0 L 216.9 132.1 L 208.4 137.3 L 203.3 134.8 L 205.0 128.2 L 197.6 130.1 L 199.5 124.4 L 191.9 116.9 L 187.1 118.2 L 182.7 124.5 L 177.7 126.2 L 175.3 120.9 L 170.6 125.4 L 177.7 130.1 L 179.3 137.4 L 183.7 138.8 L 181.9 146.0 L 186.0 150.7 L 186.2 159.8 L 173.2 163.9 L 167.0 172.3 L 163.6 172.9 L 157.4 180.7 L 155.5 179.4 L 154.3 185.4 L 158.0 192.5 L 149.9 203.7 L 147.3 210.7 L 143.0 211.4 L 142.6 204.7 L 136.4 204.0 L 130.3 209.2 L 139.5 212.4 L 123.2 225.9 L 119.6 226.2 L 120.8 216.4 L 119.5 205.1 L 110.8 206.8 L 107.2 203.1 L 108.7 198.1 L 106.6 191.1 L 111.0 186.5 L 105.6 177.9 L 101.9 180.2 L 92.6 179.5 L 90.2 185.2 L 82.8 185.8 L 74.7 193.4 L 70.2 188.6 L 60.8 190.6 L 66.6 168.1 L 58.4 163.8 L 53.1 158.7 L 47.3 158.2 L 44.3 153.4 L 45.1 142.7 L 54.1 141.5 L 69.3 142.6 L 72.7 137.8 L 71.2 134.1 L 75.8 126.1 L 73.4 121.4 L 73.7 110.9 L 77.3 108.3 Z"></path>
<path class="c-county" d="M 216.9 3.2 L 217.2 1.2 L 201.4 -0.4 L 194.9 -2.3 L 195.9 -9.7 L 185.9 -14.8 L 178.0 -16.8 L 174.3 -20.3 L 183.7 -28.5 L 187.3 -22.8 L 197.8 -24.1 L 194.7 -33.8 L 195.2 -37.0 L 191.1 -47.8 L 192.5 -48.9 L 203.5 -50.7 L 202.4 -32.0 L 207.2 -31.3 L 208.1 -37.5 L 221.5 -36.2 L 234.5 -30.4 L 242.0 -31.8 L 246.9 -36.7 L 255.0 -36.4 L 259.2 -39.3 L 259.6 -45.5 L 263.0 -46.5 L 265.8 -35.7 L 264.3 -27.7 L 261.4 -23.8 L 249.6 -15.8 L 247.2 -9.6 L 249.8 -2.7 L 252.4 -0.5 L 248.5 5.5 L 247.5 20.8 L 239.8 19.3 L 240.4 14.9 L 220.5 21.7 L 222.4 9.3 L 216.9 3.2 Z"></path>
<path class="c-county" d="M 337.9 37.5 L 342.7 37.0 L 345.7 31.9 L 355.7 32.9 L 353.7 24.8 L 357.9 19.6 L 364.5 16.4 L 369.5 17.6 L 375.8 15.8 L 381.7 18.4 L 388.7 18.2 L 391.1 14.7 L 398.3 17.2 L 406.7 18.7 L 412.7 24.8 L 410.2 29.2 L 413.4 36.1 L 407.3 43.9 L 401.5 45.5 L 404.4 55.1 L 397.7 57.1 L 393.4 49.4 L 382.2 52.2 L 379.0 56.4 L 367.7 55.9 L 366.4 43.5 L 358.7 40.3 L 350.2 40.9 L 337.9 37.5 Z"></path>
<path class="c-county" d="M -132.5 74.7 L -134.4 68.9 L -141.2 58.6 L -142.6 53.8 L -141.7 43.3 L -137.5 42.8 L -130.9 38.4 L -126.2 24.9 L -123.1 20.4 L -111.9 19.6 L -106.7 21.5 L -96.6 20.8 L -88.1 18.7 L -83.0 20.0 L -77.2 17.5 L -70.3 17.3 L -64.6 10.8 L -61.8 14.0 L -50.6 14.2 L -46.5 11.8 L -39.8 9.9 L -34.6 11.3 L -30.1 8.3 L -21.6 7.4 L -11.6 3.5 L -3.5 8.7 L 1.1 6.6 L 11.1 6.2 L 12.0 17.7 L 13.9 21.2 L 10.6 28.3 L 1.2 36.0 L -11.4 35.0 L -17.0 37.7 L -16.9 49.5 L -12.7 53.7 L -13.2 59.8 L -20.1 64.7 L -20.9 71.7 L -19.0 74.5 L -24.3 75.6 L -23.4 86.7 L -30.7 89.6 L -42.1 91.7 L -47.5 94.6 L -53.2 100.7 L -50.0 105.3 L -55.2 116.5 L -55.3 120.7 L -67.5 123.0 L -77.0 121.4 L -85.9 128.1 L -90.4 125.8 L -109.4 111.7 L -116.7 109.3 L -123.0 111.3 L -128.9 102.7 L -130.8 95.3 L -133.2 91.6 L -132.5 74.7 Z"></path>
<path class="c-county" d="M 123.2 225.9 L 139.5 212.4 L 130.3 209.2 L 136.4 204.0 L 142.6 204.7 L 143.0 211.4 L 147.3 210.7 L 149.9 203.7 L 156.9 206.6 L 160.8 211.5 L 166.7 214.4 L 174.1 212.5 L 184.0 223.6 L 181.9 233.8 L 186.6 236.2 L 194.5 237.2 L 198.6 240.7 L 205.5 241.8 L 205.8 248.6 L 200.1 256.2 L 193.2 262.2 L 184.5 265.3 L 183.7 278.0 L 185.3 280.6 L 187.8 286.2 L 184.0 298.3 L 176.1 304.8 L 171.4 306.1 L 168.0 316.1 L 168.4 324.1 L 165.2 326.5 L 154.1 329.3 L 146.8 333.1 L 136.8 329.3 L 125.9 329.8 L 118.2 327.4 L 117.8 323.3 L 104.3 324.3 L 96.5 320.0 L 86.5 316.9 L 82.8 319.1 L 79.3 314.0 L 69.4 312.6 L 63.7 313.7 L 63.1 321.1 L 66.5 326.5 L 62.0 327.8 L 57.0 326.6 L 53.8 318.4 L 39.9 312.9 L 37.0 310.0 L 41.5 300.2 L 34.7 295.9 L 33.8 288.2 L 27.3 287.2 L 24.9 282.7 L 31.0 275.9 L 21.3 268.7 L 29.9 261.3 L 28.3 257.2 L 23.4 253.4 L 15.3 251.8 L 8.5 255.7 L 1.5 249.6 L -3.9 253.5 L -8.4 253.9 L -17.5 248.8 L -18.4 242.6 L -23.6 243.9 L -27.2 239.2 L -25.6 235.5 L -16.0 237.6 L -5.9 231.2 L -3.6 222.9 L 6.9 213.0 L 15.8 207.2 L 17.7 208.5 L 23.5 204.6 L 26.1 191.8 L 33.2 189.4 L 36.2 194.9 L 41.0 193.4 L 40.7 188.1 L 46.6 185.9 L 52.6 186.3 L 50.0 178.1 L 52.2 170.2 L 57.2 168.7 L 58.4 163.8 L 66.6 168.1 L 60.8 190.6 L 70.2 188.6 L 74.7 193.4 L 82.8 185.8 L 90.2 185.2 L 92.6 179.5 L 101.9 180.2 L 105.6 177.9 L 111.0 186.5 L 106.6 191.1 L 108.7 198.1 L 107.2 203.1 L 110.8 206.8 L 119.5 205.1 L 120.8 216.4 L 119.6 226.2 L 123.2 225.9 Z"></path>
<path class="c-county" d="M 344.5 403.6 L 352.8 394.6 L 356.5 392.2 L 359.1 385.8 L 352.2 380.0 L 346.2 379.8 L 344.8 365.7 L 356.4 366.6 L 357.5 361.6 L 374.0 357.6 L 377.3 348.5 L 385.0 346.4 L 379.5 342.3 L 381.2 333.8 L 376.1 331.4 L 379.6 320.0 L 376.1 319.7 L 374.5 310.3 L 385.7 310.5 L 400.4 305.8 L 398.3 310.6 L 399.4 318.5 L 408.0 318.3 L 413.6 327.6 L 413.7 330.7 L 419.5 335.3 L 420.1 349.3 L 426.6 348.6 L 429.0 345.1 L 436.5 340.4 L 441.5 344.2 L 448.0 342.2 L 452.0 336.9 L 463.9 334.0 L 463.6 328.5 L 468.5 328.6 L 466.2 319.1 L 475.2 328.2 L 479.8 328.7 L 482.7 334.8 L 490.6 341.8 L 489.8 346.1 L 496.4 349.6 L 501.5 349.3 L 498.5 357.3 L 499.1 364.6 L 501.1 366.3 L 497.1 378.1 L 502.3 376.4 L 514.8 377.7 L 516.3 379.8 L 516.9 390.5 L 510.8 392.4 L 513.4 406.9 L 510.0 409.9 L 502.9 404.2 L 486.1 403.3 L 483.0 406.5 L 467.1 411.7 L 465.5 416.4 L 464.8 427.9 L 462.7 433.9 L 463.4 439.9 L 469.2 445.0 L 474.3 446.8 L 472.8 453.1 L 469.0 453.6 L 467.4 465.1 L 456.4 464.8 L 452.3 461.9 L 447.0 462.4 L 440.3 472.0 L 436.5 470.3 L 428.8 473.5 L 422.4 470.4 L 419.4 466.4 L 409.6 471.3 L 408.8 466.6 L 402.8 461.1 L 399.7 442.7 L 395.5 439.5 L 385.4 446.1 L 371.8 443.9 L 366.8 440.9 L 356.3 438.9 L 352.0 434.8 L 345.6 432.5 L 339.0 423.3 L 337.4 419.6 L 337.6 408.0 L 344.5 403.6 Z"></path>
<path class="c-county" d="M 318.7 286.5 L 326.5 285.2 L 327.4 282.3 L 338.7 283.6 L 338.3 277.4 L 348.2 271.9 L 356.0 273.2 L 360.8 279.5 L 365.6 280.3 L 368.9 288.9 L 374.7 286.9 L 379.0 293.1 L 390.7 295.6 L 392.8 307.8 L 385.7 310.5 L 374.5 310.3 L 376.1 319.7 L 379.6 320.0 L 376.1 331.4 L 381.2 333.8 L 379.5 342.3 L 385.0 346.4 L 377.3 348.5 L 374.0 357.6 L 357.5 361.6 L 356.4 366.6 L 344.8 365.7 L 346.2 379.8 L 352.2 380.0 L 359.1 385.8 L 356.5 392.2 L 352.8 394.6 L 344.5 403.6 L 337.6 408.0 L 337.4 419.6 L 339.0 423.3 L 330.7 426.3 L 326.8 430.4 L 317.3 432.1 L 314.2 437.7 L 307.5 434.4 L 306.5 443.1 L 301.3 445.2 L 295.7 444.2 L 289.4 444.0 L 288.2 448.9 L 271.9 458.7 L 273.4 462.7 L 270.3 466.3 L 273.2 483.5 L 272.3 489.8 L 268.9 494.6 L 263.5 494.1 L 262.5 500.2 L 256.2 497.5 L 256.4 507.7 L 240.2 509.4 L 234.0 511.8 L 226.3 510.6 L 206.8 502.4 L 193.1 500.7 L 198.2 488.6 L 203.8 479.4 L 200.3 466.7 L 194.1 467.0 L 191.2 459.8 L 197.9 459.7 L 200.7 456.9 L 201.4 452.7 L 211.4 449.1 L 217.4 448.4 L 217.3 443.4 L 210.9 436.4 L 205.0 434.4 L 210.7 429.2 L 214.1 420.7 L 217.8 417.8 L 213.9 397.2 L 211.8 393.4 L 216.9 390.1 L 216.0 385.5 L 220.7 376.7 L 218.8 371.8 L 228.3 367.1 L 221.8 358.5 L 218.3 357.4 L 215.9 353.7 L 224.0 350.0 L 231.0 338.5 L 234.5 326.1 L 233.1 318.2 L 228.1 313.8 L 229.8 309.9 L 223.6 302.2 L 216.8 297.2 L 223.9 289.4 L 229.5 285.1 L 233.3 283.5 L 246.1 272.4 L 246.5 270.3 L 252.5 268.6 L 265.9 272.6 L 265.2 279.2 L 270.9 280.8 L 276.4 278.8 L 282.0 281.5 L 286.3 276.9 L 294.8 271.6 L 305.7 263.0 L 304.2 255.8 L 307.7 253.1 L 314.6 255.8 L 317.1 258.3 L 314.3 271.2 L 318.7 286.5 Z M 272.9 351.2 L 273.3 360.5 L 267.6 369.1 L 278.1 376.9 L 279.9 383.9 L 265.7 388.0 L 263.1 394.5 L 264.3 400.2 L 272.0 403.0 L 270.5 414.7 L 277.3 416.7 L 283.7 415.5 L 288.9 418.0 L 288.4 409.0 L 291.3 397.9 L 297.6 400.9 L 302.0 397.3 L 299.0 390.5 L 291.4 386.8 L 291.0 380.0 L 296.6 373.4 L 297.9 366.0 L 293.9 360.7 L 295.1 356.5 L 280.4 353.9 L 272.9 351.2 Z"></path>
<path class="c-county" d="M -72.0 417.0 L -75.7 416.6 L -84.3 424.9 L -85.8 420.4 L -93.4 412.0 L -98.9 411.0 L -105.1 418.0 L -111.3 416.1 L -120.4 417.5 L -125.4 420.6 L -132.3 416.8 L -130.9 408.0 L -141.6 402.9 L -147.2 406.9 L -150.3 412.9 L -157.5 405.7 L -163.7 405.8 L -167.1 412.9 L -174.4 415.1 L -172.9 419.1 L -177.5 422.7 L -180.2 423.2 L -183.9 417.6 L -181.0 413.2 L -183.2 408.4 L -182.4 402.7 L -188.3 396.2 L -184.1 393.4 L -179.0 384.2 L -179.9 377.7 L -184.2 372.9 L -188.8 371.2 L -196.6 375.9 L -205.4 378.0 L -209.0 374.5 L -207.3 363.9 L -215.3 363.3 L -209.2 357.2 L -209.9 353.7 L -206.0 346.6 L -217.8 340.8 L -221.0 335.0 L -219.9 329.3 L -214.3 330.6 L -212.7 327.3 L -195.5 322.2 L -190.2 327.3 L -185.5 319.7 L -187.6 313.1 L -180.9 313.0 L -181.3 309.6 L -188.7 302.1 L -193.8 301.9 L -178.8 298.0 L -180.1 291.2 L -174.4 280.3 L -170.7 276.0 L -169.5 270.3 L -166.4 263.0 L -169.9 260.6 L -157.4 257.1 L -151.7 258.6 L -143.6 254.0 L -141.8 245.8 L -144.8 242.8 L -143.4 239.7 L -136.4 241.4 L -135.2 252.1 L -129.3 252.3 L -130.8 242.4 L -123.4 241.0 L -112.3 237.0 L -110.0 240.5 L -101.9 234.5 L -98.1 234.5 L -90.0 227.4 L -85.3 228.0 L -91.7 221.0 L -99.2 217.2 L -96.8 212.4 L -92.3 211.5 L -89.4 202.3 L -79.3 205.2 L -77.3 201.1 L -71.0 195.5 L -60.3 192.4 L -54.9 195.2 L -52.4 199.3 L -53.3 208.2 L -46.9 210.0 L -41.4 216.6 L -35.4 214.6 L -32.5 217.9 L -36.0 226.2 L -33.1 242.4 L -27.2 239.2 L -23.6 243.9 L -18.4 242.6 L -17.5 248.8 L -8.4 253.9 L -3.9 253.5 L 1.5 249.6 L 8.5 255.7 L 15.3 251.8 L 23.4 253.4 L 28.3 257.2 L 29.9 261.3 L 21.3 268.7 L 31.0 275.9 L 24.9 282.7 L 27.3 287.2 L 33.8 288.2 L 34.7 295.9 L 41.5 300.2 L 37.0 310.0 L 30.1 314.0 L 27.3 317.1 L 35.2 328.2 L 32.7 334.7 L 33.4 343.6 L 23.2 341.8 L 17.7 350.6 L 7.7 350.3 L 8.5 355.9 L -2.4 358.8 L -5.9 368.2 L -9.3 373.0 L -15.8 371.6 L -17.0 375.3 L -14.3 378.3 L -18.4 383.8 L -21.8 380.5 L -26.2 386.5 L -28.9 384.8 L -38.4 390.3 L -35.8 393.8 L -30.1 389.1 L -21.5 397.9 L -33.3 402.9 L -26.6 407.7 L -28.2 411.5 L -33.8 415.5 L -46.3 418.8 L -56.8 429.2 L -67.7 423.2 L -72.5 419.2 L -72.0 417.0 Z M -55.8 345.1 L -53.4 337.0 L -57.0 316.7 L -50.9 310.7 L -60.3 305.0 L -63.9 296.1 L -71.1 300.7 L -76.3 299.7 L -75.5 292.8 L -77.2 285.2 L -76.3 277.7 L -77.7 268.7 L -85.2 272.6 L -100.6 275.4 L -98.4 288.8 L -107.6 288.6 L -113.1 283.6 L -116.7 286.9 L -126.5 281.6 L -130.1 280.8 L -137.1 283.9 L -144.3 289.5 L -138.4 293.4 L -131.1 288.3 L -124.6 306.9 L -126.0 317.6 L -122.2 322.9 L -128.2 329.4 L -118.1 328.6 L -115.7 332.1 L -118.2 338.4 L -113.5 344.1 L -106.8 337.3 L -96.5 339.0 L -97.2 347.3 L -94.7 348.8 L -84.1 359.4 L -75.3 362.7 L -72.5 366.8 L -68.9 358.5 L -74.1 353.3 L -66.1 347.4 L -59.2 350.9 L -55.8 345.1 Z"></path>
<path class="c-county" d="M 223.9 289.4 L 216.8 297.2 L 223.6 302.2 L 229.8 309.9 L 228.1 313.8 L 233.1 318.2 L 234.5 326.1 L 231.0 338.5 L 224.0 350.0 L 215.9 353.7 L 218.3 357.4 L 221.8 358.5 L 228.3 367.1 L 218.8 371.8 L 220.7 376.7 L 216.0 385.5 L 216.9 390.1 L 211.8 393.4 L 213.9 397.2 L 217.8 417.8 L 214.1 420.7 L 210.7 429.2 L 205.0 434.4 L 210.9 436.4 L 217.3 443.4 L 217.4 448.4 L 211.4 449.1 L 201.4 452.7 L 200.7 456.9 L 197.9 459.7 L 191.2 459.8 L 177.5 463.7 L 172.7 463.3 L 168.3 467.8 L 161.8 465.1 L 160.2 461.1 L 151.2 456.2 L 145.5 451.4 L 144.3 447.0 L 147.6 442.1 L 147.9 436.2 L 153.0 430.1 L 157.4 430.3 L 160.0 426.6 L 163.0 416.7 L 162.0 413.4 L 153.6 414.0 L 145.5 411.3 L 137.5 399.5 L 142.0 396.5 L 148.6 388.4 L 144.2 383.5 L 130.3 377.7 L 126.4 374.5 L 124.6 368.9 L 127.8 361.6 L 133.6 361.3 L 136.7 354.6 L 130.5 351.5 L 131.9 347.8 L 124.8 342.1 L 124.2 335.7 L 125.9 329.8 L 136.8 329.3 L 146.8 333.1 L 154.1 329.3 L 165.2 326.5 L 168.4 324.1 L 168.0 316.1 L 171.4 306.1 L 176.1 304.8 L 184.0 298.3 L 187.8 286.2 L 185.3 280.6 L 183.7 278.0 L 184.5 265.3 L 193.2 262.2 L 200.1 256.2 L 205.8 248.6 L 215.8 247.8 L 219.0 250.3 L 225.9 251.1 L 236.0 248.7 L 239.2 255.3 L 235.0 258.5 L 234.6 263.6 L 239.1 266.7 L 244.1 265.5 L 246.5 270.3 L 246.1 272.4 L 233.3 283.5 L 229.5 285.1 L 223.9 289.4 Z"></path>
<path class="c-county" d="M -21.5 397.9 L -30.1 389.1 L -35.8 393.8 L -38.4 390.3 L -28.9 384.8 L -26.2 386.5 L -21.8 380.5 L -18.4 383.8 L -14.3 378.3 L -17.0 375.3 L -15.8 371.6 L -9.3 373.0 L -5.9 368.2 L -2.4 358.8 L 8.5 355.9 L 7.7 350.3 L 17.7 350.6 L 23.2 341.8 L 33.4 343.6 L 35.7 348.1 L 42.8 346.3 L 47.4 353.3 L 42.1 357.9 L 44.1 371.1 L 50.0 376.9 L 56.4 378.1 L 62.8 374.6 L 66.7 378.8 L 61.6 388.0 L 56.9 388.2 L 55.6 394.7 L 59.5 403.2 L 60.3 410.8 L 50.6 412.5 L 49.2 420.7 L 50.9 427.4 L 58.0 437.3 L 61.7 437.6 L 57.9 445.9 L 60.3 450.1 L 74.0 444.6 L 73.2 451.6 L 75.3 454.2 L 72.6 460.6 L 63.6 463.5 L 61.5 467.0 L 63.6 473.5 L 67.2 477.5 L 61.7 483.6 L 47.8 492.3 L 47.2 495.1 L 40.2 492.6 L 33.7 494.6 L 25.9 491.3 L 21.6 496.4 L 16.6 492.6 L 17.6 485.3 L 11.3 487.7 L 6.8 484.5 L 7.4 470.5 L 12.0 466.4 L 19.7 463.5 L 20.2 456.6 L 11.5 457.6 L -3.5 452.8 L -12.4 453.0 L -18.4 456.0 L -24.3 454.1 L -30.9 455.6 L -29.6 440.5 L -34.4 440.4 L -37.9 432.3 L -43.1 426.1 L -42.0 417.3 L -33.8 415.5 L -28.2 411.5 L -26.6 407.7 L -33.3 402.9 L -21.5 397.9 Z"></path>
<path class="c-county" d="M -19.0 74.5 L -20.9 71.7 L -20.1 64.7 L -13.2 59.8 L -12.7 53.7 L -16.9 49.5 L -17.0 37.7 L -11.4 35.0 L 1.2 36.0 L 10.6 28.3 L 13.9 21.2 L 12.0 17.7 L 11.1 6.2 L 20.9 8.8 L 24.4 25.1 L 23.2 35.9 L 18.0 36.9 L 14.6 47.0 L 18.9 49.0 L 18.9 56.4 L 33.3 56.8 L 40.6 52.6 L 45.2 57.8 L 54.6 63.7 L 59.9 67.9 L 65.6 67.4 L 69.5 75.4 L 75.2 77.4 L 73.5 86.2 L 76.9 100.8 L 77.3 108.3 L 73.7 110.9 L 73.4 121.4 L 75.8 126.1 L 71.2 134.1 L 72.7 137.8 L 69.3 142.6 L 54.1 141.5 L 45.1 142.7 L 44.3 153.4 L 47.3 158.2 L 53.1 158.7 L 58.4 163.8 L 57.2 168.7 L 52.2 170.2 L 50.0 178.1 L 52.6 186.3 L 46.6 185.9 L 40.7 188.1 L 41.0 193.4 L 36.2 194.9 L 33.2 189.4 L 26.1 191.8 L 23.5 204.6 L 17.7 208.5 L 15.8 207.2 L 6.9 213.0 L -3.6 222.9 L -5.9 231.2 L -16.0 237.6 L -25.6 235.5 L -27.2 239.2 L -33.1 242.4 L -36.0 226.2 L -32.5 217.9 L -35.4 214.6 L -41.4 216.6 L -46.9 210.0 L -53.3 208.2 L -52.4 199.3 L -54.9 195.2 L -60.3 192.4 L -57.2 188.2 L -45.4 189.9 L -36.9 187.3 L -40.0 175.7 L -37.0 169.2 L -32.9 168.0 L -33.4 160.2 L -36.5 157.0 L -41.8 143.8 L -50.1 147.4 L -67.7 144.0 L -68.6 133.5 L -67.5 123.0 L -55.3 120.7 L -55.2 116.5 L -50.0 105.3 L -53.2 100.7 L -47.5 94.6 L -42.1 91.7 L -30.7 89.6 L -23.4 86.7 L -24.3 75.6 L -19.0 74.5 Z"></path>
<path class="c-county" d="M 66.7 378.8 L 62.8 374.6 L 56.4 378.1 L 50.0 376.9 L 44.1 371.1 L 42.1 357.9 L 47.4 353.3 L 42.8 346.3 L 35.7 348.1 L 33.4 343.6 L 32.7 334.7 L 35.2 328.2 L 27.3 317.1 L 30.1 314.0 L 37.0 310.0 L 39.9 312.9 L 53.8 318.4 L 57.0 326.6 L 62.0 327.8 L 66.5 326.5 L 63.1 321.1 L 63.7 313.7 L 69.4 312.6 L 79.3 314.0 L 82.8 319.1 L 86.5 316.9 L 96.5 320.0 L 104.3 324.3 L 117.8 323.3 L 118.2 327.4 L 125.9 329.8 L 124.2 335.7 L 124.8 342.1 L 131.9 347.8 L 130.5 351.5 L 136.7 354.6 L 133.6 361.3 L 127.8 361.6 L 124.6 368.9 L 126.4 374.5 L 130.3 377.7 L 144.2 383.5 L 148.6 388.4 L 142.0 396.5 L 137.5 399.5 L 145.5 411.3 L 153.6 414.0 L 162.0 413.4 L 163.0 416.7 L 160.0 426.6 L 157.4 430.3 L 153.0 430.1 L 147.9 436.2 L 147.6 442.1 L 144.3 447.0 L 145.5 451.4 L 151.2 456.2 L 160.2 461.1 L 146.1 466.4 L 137.2 466.1 L 127.5 468.5 L 124.9 472.5 L 123.3 466.9 L 119.6 465.2 L 114.3 454.7 L 115.7 444.9 L 115.4 436.6 L 112.6 434.8 L 89.9 437.1 L 83.3 435.3 L 74.0 444.6 L 60.3 450.1 L 57.9 445.9 L 61.7 437.6 L 58.0 437.3 L 50.9 427.4 L 49.2 420.7 L 50.6 412.5 L 60.3 410.8 L 59.5 403.2 L 55.6 394.7 L 56.9 388.2 L 61.6 388.0 L 66.7 378.8 Z"></path>
<path class="c-county" d="M 264.3 400.2 L 263.1 394.5 L 265.7 388.0 L 279.9 383.9 L 278.1 376.9 L 267.6 369.1 L 273.3 360.5 L 272.9 351.2 L 280.4 353.9 L 295.1 356.5 L 293.9 360.7 L 297.9 366.0 L 296.6 373.4 L 291.0 380.0 L 291.4 386.8 L 299.0 390.5 L 302.0 397.3 L 297.6 400.9 L 291.3 397.9 L 288.4 409.0 L 288.9 418.0 L 283.7 415.5 L 277.3 416.7 L 270.5 414.7 L 272.0 403.0 L 264.3 400.2 Z"></path>
          </g>
          <g class="home-layer">
            <path class="c-home" d="M 208.8 174.5 L 212.3 175.8 L 212.6 186.5 L 220.4 189.6 L 221.2 194.0 L 228.0 191.9 L 228.4 187.5 L 241.5 186.4 L 246.2 185.1 L 253.6 174.8 L 258.3 177.1 L 266.0 168.1 L 272.8 166.7 L 275.1 171.0 L 276.2 181.5 L 282.9 184.1 L 275.1 194.3 L 277.4 199.4 L 277.7 208.9 L 280.9 212.0 L 277.6 217.6 L 277.0 223.9 L 283.2 228.7 L 288.2 228.8 L 290.3 232.9 L 297.7 230.4 L 310.0 231.1 L 310.2 235.6 L 306.5 242.3 L 307.7 253.1 L 304.2 255.8 L 305.7 263.0 L 294.8 271.6 L 286.3 276.9 L 282.0 281.5 L 276.4 278.8 L 270.9 280.8 L 265.2 279.2 L 265.9 272.6 L 252.5 268.6 L 246.5 270.3 L 244.1 265.5 L 239.1 266.7 L 234.6 263.6 L 235.0 258.5 L 239.2 255.3 L 236.0 248.7 L 225.9 251.1 L 219.0 250.3 L 215.8 247.8 L 205.8 248.6 L 205.5 241.8 L 198.6 240.7 L 194.5 237.2 L 186.6 236.2 L 181.9 233.8 L 184.0 223.6 L 174.1 212.5 L 166.7 214.4 L 160.8 211.5 L 156.9 206.6 L 149.9 203.7 L 158.0 192.5 L 154.3 185.4 L 155.5 179.4 L 157.4 180.7 L 163.6 172.9 L 167.0 172.3 L 173.2 163.9 L 186.2 159.8 L 186.0 150.7 L 181.9 146.0 L 183.7 138.8 L 179.3 137.4 L 177.7 130.1 L 170.6 125.4 L 175.3 120.9 L 177.7 126.2 L 182.7 124.5 L 187.1 118.2 L 191.9 116.9 L 199.5 124.4 L 197.6 130.1 L 205.0 128.2 L 203.3 134.8 L 208.4 137.3 L 215.1 144.0 L 214.6 147.9 L 220.1 152.4 L 214.5 165.1 L 212.6 172.8 L 208.8 174.5 Z"></path>
          </g>
          <g class="rings-layer">
        <circle class="c-ring" cx="200" cy="200" r="20.7"></circle>
        <circle class="c-ring" cx="200" cy="200" r="41.3"></circle>
        <circle class="c-ring" cx="200" cy="200" r="62.0"></circle>
        <circle class="c-ring-main" cx="200" cy="200" r="82.6"></circle>
          </g>
          <g class="labels-layer">
            <circle class="c-town-dot" cx="235.0" cy="169.6" r="2.6"></circle>
<text class="c-town-label" x="240.0" y="173.1">Janikowo</text>
<circle class="c-town-dot" cx="239.0" cy="135.2" r="2.6"></circle>
<text class="c-town-label" x="244.0" y="138.7">Pakość</text>
<circle class="c-town-dot" cx="120.3" cy="178.8" r="2.6"></circle>
<text class="c-town-label" x="125.3" y="182.3">Rogowo</text>
<circle class="c-town-dot" cx="200.2" cy="208.1" r="2.6"></circle>
<circle class="c-town-dot" cx="257.7" cy="215.2" r="2.6"></circle>
<text class="c-town-label" x="262.7" y="218.7">Strzelno</text>
<circle class="c-town-dot" cx="154.1" cy="152.5" r="2.6"></circle>
<text class="c-town-label" x="159.1" y="156.0">Gąsawa</text>
<circle class="c-town-dot" cx="215.5" cy="268.1" r="2.6"></circle>
<text class="c-town-label" x="220.5" y="271.6">Orchowo</text>
<circle class="c-town-dot" cx="164.4" cy="238.8" r="2.6"></circle>
<text class="c-town-label" x="169.4" y="242.3">Trzemeszno</text>
          </g>
          <circle class="c-center" cx="200" cy="200" r="3.4"></circle>
          <text class="c-mogilno-label" x="200" y="217" text-anchor="middle">Mogilno</text>
        </svg>
        <!-- Podpowiedź jest dekoracyjna; etykieta mapy to zwykły tekst HTML. -->
        <div class="map-hint" data-hint="kliknij, by powiększyć" aria-hidden="true"></div>
        <div class="map-label">
          <span class="map-label-dot" aria-hidden="true"></span>
          <span class="map-label-name">Mogilno</span>
          <span class="map-label-dim">· promień 20 km</span>
        </div>
      </div>
    </div>
  </section>


  <section class="favorites-section" id="favoritesSection" style="display:none;">
    <div class="favorites-head">
      <h2>Twoje ulubione <span class="fav-count" id="favCount"></span></h2>
      <button class="favorites-clear" id="favClearBtn" type="button">wyczyść</button>
    </div>
    <div class="favorites-strip" id="favoritesStrip"></div>
  </section>

  <section class="gallery-section" id="galeria">
    <div class="gallery-head">
      <h2>Ostatnio dodane</h2>
      <div style="display:flex; align-items:center; gap:14px;">
        <div class="sync-status"><span class="sync-dot" id="syncDot"></span><span id="syncText">niepołączono</span></div>
        <button class="refresh-btn" id="refreshBtn">odśwież teraz</button>
      </div>
    </div>

    <div class="gallery-toolbar" id="galleryToolbar">
      <div class="gallery-search-wrap" id="gallerySearchWrap">
        <input type="search" class="gallery-search" id="gallerySearch" placeholder="Szukaj zdjęcia, folderu…" enterkeyhint="search" autocomplete="off">
        <button type="button" class="gallery-search-clear" id="gallerySearchClear" aria-label="Wyczyść wyszukiwanie" title="Wyczyść" hidden>×</button>
      </div>
      <!-- Przyciski gęstości są w DWÓCH miejscach i CSS przełącza między nimi:
           • #galleryToolbarViewGroup — desktop, w pasku obok wyszukiwarki
             (tam wygląda czytelnie, więc zostaje bez zmian),
           • wstawka .gallery-view-group-inline — telefon, w JEDNEJ linii
             między folderami a siatką zdjęć, blisko gestu uszczypnięcia.
           Definicje przycisków trzymamy w jednym szablonie, żeby oba zestawy
           nigdy się nie rozjechały. Kliknięcia obsługuje delegacja po
           data-density (pasek narzędzi galerii). -->
      <div class="gallery-view-group" id="galleryToolbarViewGroup" role="group" aria-label="Gęstość siatki"></div>
    </div>
    <template id="viewGroupTemplate">
      <span class="view-hint">uszczypnij aby przybliżyć, oddalić</span>
      <button type="button" class="gallery-view-btn" data-density="comfortable" title="Większe kafelki" aria-label="Większe kafelki">▣</button>
      <button type="button" class="gallery-view-btn active" data-density="default" title="Domyślny widok" aria-label="Domyślny widok">▦</button>
      <button type="button" class="gallery-view-btn" data-density="compact" title="Gęstsza siatka" aria-label="Gęstsza siatka">▤</button>
    </template>
    <div id="featuredSlot"></div>
    <div id="errorBox"></div>
    <details id="debugBox" style="margin-bottom:20px; font-family:'JetBrains Mono', monospace; font-size:11px; color:var(--ink-dim); border:1px solid var(--line); border-radius:6px; padding:10px 14px;">
      <summary style="cursor:pointer; color:var(--ink-dim);">diagnostyka synchronizacji (kliknij, żeby rozwinąć)</summary>
      <div id="debugContent" style="margin-top:10px; line-height:1.7; white-space:pre-wrap;">jeszcze nie synchronizowano</div>
      <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--line);">
        <div style="margin-bottom:8px;">ostatnio dodane zdjęcia (globalnie, wszystkie podfoldery):</div>
        <div class="recent-folder-strip" id="debugRecentStrip">
          <div class="strip-thumb skeleton"></div>
          <div class="strip-thumb skeleton"></div>
          <div class="strip-thumb skeleton"></div>
          <div class="strip-thumb skeleton"></div>
        </div>
      </div>
    </details>
    <div id="galleryRoot"></div>
  </section>

  <!-- Atrakcje turystyczne: między „Ostatnio dodane” (foldery i zdjęcia) a sekcją
       „GŁOS SPOŁECZNOŚCI”. Partial współdzielony z attraction-api.php. -->
  <?php require __DIR__ . '/attraction-section.php'; ?>

  <section class="comments-section" id="recentCommentsSection" style="display:none;">
    <div class="comments-section-head">
      <div class="eyebrow">GŁOS SPOŁECZNOŚCI</div>
      <h2>Ostatnie komentarze</h2>
    </div>
    <div class="comments-recent-strip" id="recentCommentsStrip"></div>
  </section>

<?php require __DIR__ . '/community-section.php'; ?>
</div>
</main>

<footer><?= zm_text_h('footer.text') ?> <span class="footer-nav">· <a href="/funkcje.php"><?= zm_text_h('footer.link_features') ?></a> · <button type="button" class="footer-link" id="contactBtn"><?= zm_text_h('footer.link_contact') ?></button></span></footer>

<!-- pływający przycisk powrotu na górę -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Przewiń do góry" title="Do góry">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
</button>

<!-- lightbox -->
<div class="lightbox" id="lightbox">
  <button class="lightbox-close" id="closeLightbox" aria-label="Zamknij">×</button>
  <button class="lightbox-like-btn" id="lightboxLikeBtn" aria-label="Polub zdjęcie" title="Zaloguj się, żeby polubić">
    <span class="like-ico" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>
    </span>
    <span class="like-count" id="lightboxLikeCount" style="display:none;">0</span>
  </button>
  <button class="lightbox-comments-btn" id="lightboxCommentsBtn" aria-label="Komentarze" title="Komentarze">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
    <span class="comment-count" id="lightboxCommentCount" style="display:none;">0</span>
  </button>
  <div class="lightbox-comments-panel" id="lightboxCommentsPanel"></div>
  <button class="lightbox-info-btn" id="lightboxInfoBtn" aria-label="Pokaż dane zdjęcia" title="Dane zdjęcia">i</button>
  <div class="lightbox-exif" id="lightboxExif">
    <!-- Akcje zdjęcia: te same przyciski co w górnym pasku (mapy, udostępnij,
         zgłoś, ulubione) — przeniesione do panelu „i”. Każdy występuje tylko raz. -->
    <div class="lightbox-exif-actions" id="lightboxExifActions">
      <button class="lightbox-map-btn" id="lightboxMapBtn" aria-label="Pokaż mapę miejsca" title="Pokaż mapę miejsca">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3z"></path><path d="M9 3v15"></path><path d="M15 6v15"></path><circle cx="12" cy="10" r="2.2"></circle></svg>
        <span class="lb-act-label">Mapy</span>
      </button>
      <button class="lightbox-share-btn" id="lightboxShareBtn" aria-label="Udostępnij zdjęcie" title="Udostępnij / kopiuj link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        <span class="lb-act-label">Udostępnij</span>
      </button>
      <button class="lightbox-report-btn" id="lightboxReportBtn" aria-label="Zgłoś zdjęcie" title="Zgłoś zdjęcie">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>
        <span class="lb-act-label">Zgłoś</span>
      </button>
      <button class="lightbox-fav-btn" id="lightboxFavBtn" aria-label="Dodaj do ulubionych" title="Dodaj do ulubionych">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"></path></svg>
        <span class="lb-act-label">Ulubione</span>
      </button>
    </div>
    <div class="lightbox-exif-body" id="lightboxExifBody"></div>
  </div>
  <div class="lightbox-map-panel" id="lightboxMapPanel" role="dialog" aria-modal="true" aria-label="Mapa miejsca">
    <div class="lightbox-map-head"><span>Mapa miejsca</span><button type="button" class="lightbox-map-close" id="lightboxMapClose" aria-label="Zamknij mapę">×</button></div>
    <iframe class="lightbox-map-frame" id="lightboxMapFrame" title="Mapa lokalizacji zdjęcia (OpenStreetMap)" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    <!-- Bez href w HTML: mapa dostaje prawdziwy adres dopiero, gdy zdjęcie ma
         współrzędne. Pusty href to link do fragmentu, a taki Chrome na
         Androidzie zamienia w adres z #:~:text=…. -->
    <div class="lightbox-map-foot"><a id="lightboxMapGoogle" target="_blank" rel="noopener" style="display:none;">Otwórz w Google Maps ↗</a><a id="lightboxMapExternal" target="_blank" rel="noopener">Otwórz w OpenStreetMap ↗</a></div>
  </div>
  <button class="lightbox-nav lightbox-prev" id="lightboxPrev" aria-label="Poprzednie zdjęcie">‹</button>
  <img id="lightboxImg" src="" alt="">
    <button class="lightbox-nav lightbox-next" id="lightboxNext" aria-label="Następne zdjęcie">›</button>
  <div class="lightbox-desc" id="lightboxDesc" role="note"></div>
  <div class="lightbox-author" id="lightboxAuthor"></div>
  <div class="lightbox-cap" id="lightboxCap"></div>
</div>

<div class="share-modal-backdrop" id="shareModal" role="dialog" aria-modal="true" aria-labelledby="shareModalHeading">
  <div class="share-modal">
    <button type="button" class="share-modal-close" id="shareModalClose" aria-label="Zamknij">×</button>
    <h3 id="shareModalHeading">Udostępnij zdjęcie</h3>
    <p class="share-modal-title" id="shareModalTitle"></p>
    <input type="text" class="share-modal-url" id="shareModalUrl" readonly>
    <button type="button" class="share-copy-btn" id="shareCopyBtn">Skopiuj link</button>
  </div>
</div>

<div class="report-modal-backdrop" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportModalHeading">
  <div class="report-modal">
    <button type="button" class="report-modal-close" id="reportModalClose" aria-label="Zamknij">×</button>
    <h3 id="reportModalHeading">Zgłoś zdjęcie</h3>
    <p class="report-modal-title" id="reportModalTitle"></p>
    <label class="report-field">
      <span>Powód</span>
      <select id="reportReason"></select>
    </label>
    <label class="report-field">
      <span>Opis (opcjonalnie)</span>
      <textarea id="reportNote" maxlength="300" placeholder="Krótko opisz, co jest nie tak…"></textarea>
    </label>
    <div class="report-modal-actions">
      <button type="button" class="report-cancel-btn" id="reportCancelBtn">Anuluj</button>
      <button type="button" class="report-submit-btn" id="reportSubmitBtn">Wyślij zgłoszenie</button>
    </div>
    <p class="report-modal-msg" id="reportModalMsg"></p>
  </div>
</div>

<div class="user-modal-backdrop" id="userModal" role="dialog" aria-modal="true" aria-labelledby="userModalName">
  <div class="user-modal">
    <button type="button" class="user-modal-close" id="userModalClose" aria-label="Zamknij">×</button>
    <div id="userModalBody"><div class="um-loading">Wczytywanie profilu…</div></div>
  </div>
</div>

<div class="dir-modal-backdrop" id="dirModal" role="dialog" aria-modal="true" aria-labelledby="dirModalTitle">
  <div class="dir-modal">
    <button type="button" class="dir-modal-close" id="dirModalClose" aria-label="Zamknij">×</button>
    <h3 id="dirModalTitle">Użytkownicy</h3>
    <p class="dir-modal-sub">Wszyscy, którzy współtworzą archiwum — wraz z administratorami i rangami specjalnymi.</p>
    <div class="dir-toolbar">
      <div class="dir-sort" role="group" aria-label="Sortowanie">
        <button type="button" class="dir-sort-btn is-on" data-dir-sort="active">Ostatnio aktywni</button>
        <button type="button" class="dir-sort-btn" data-dir-sort="points">Punkty</button>
      </div>
      <input type="search" class="dir-search" id="dirSearch" placeholder="Szukaj…" aria-label="Szukaj użytkownika">
      <select class="dir-rank" id="dirRank" aria-label="Filtr rangi">
        <option value="0">Wszystkie rangi</option>
        <option value="-1">Administratorzy</option>
      </select>
    </div>
    <div class="dir-list" id="dirList"><p class="dir-empty">Wczytywanie…</p></div>
    <button type="button" class="dir-more" id="dirMore" hidden>Pokaż więcej</button>
  </div>
</div>

<!-- Kontakt — popup: e-mail + wiadomość do administratora (działa też bez konta) -->
<div class="contact-modal-backdrop" id="contactModal" role="dialog" aria-modal="true" aria-labelledby="contactModalTitle">
  <div class="contact-modal">
    <button type="button" class="contact-modal-close" id="contactModalClose" aria-label="Zamknij">×</button>
    <h3 id="contactModalTitle">Kontakt</h3>
    <p class="contact-modal-sub">Napisz do administratora — wiadomość trafi do jego komunikatora.</p>
    <label class="contact-label" for="contactEmail">E-mail</label>
    <input type="text" class="contact-input" id="contactEmail" placeholder="twój adres e-mail" autocomplete="email">
    <label class="contact-label" for="contactBody">Wiadomość</label>
    <textarea class="contact-input contact-text" id="contactBody" rows="5" placeholder="O co chodzi?"></textarea>
    <p class="contact-msg" id="contactMsg" hidden></p>
    <button type="button" class="contact-send" id="contactSend">Wyślij</button>
  </div>
</div>

<!-- Powiadomienia (potwierdzenia i ostrzeżenia) — jeden element dla całej strony -->
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<dialog class="guess-dialog" id="guessDialog">
  <button class="guess-close" id="guessCloseBtn" type="button" aria-label="Zamknij grę" onclick="document.getElementById('guessDialog').close()">×</button>
  <div class="guess-head">
    <div class="guess-title">Zgadnij gminę</div>
    <div class="guess-score mono">passa: <b id="guessStreakEl">0</b> · rekord: <b id="guessBestEl">0</b></div>
  </div>
  <div class="guess-photo-wrap">
    <img id="guessPhotoImg" src="" alt="">
    <div class="guess-feedback" id="guessFeedbackEl"></div>
  </div>
  <div class="guess-options" id="guessOptionsEl"></div>
  <button class="guess-next" id="guessNextBtn" style="display:none;">Następne zdjęcie →</button>
</dialog>

<!-- „Znajdź na mapie” — osobna gra osadzona w iframe (embed=1). Przeładowanie
     iframe przy każdym otwarciu daje świeżą rundę dla każdego gracza. -->
<dialog class="guess-dialog findmap-dialog" id="findMapDialog">
  <div class="findmap-shell">
    <button class="guess-close" id="findMapCloseBtn" type="button" aria-label="Zamknij grę">×</button>
    <div class="findmap-head">
      <div class="guess-title">Znajdź na mapie</div>
      <div class="guess-score mono">10 rund · kliknij na mapie</div>
    </div>
    <iframe class="findmap-frame" id="findMapFrame" src="" title="Znajdź na mapie" loading="lazy"></iframe>
  </div>
</dialog>

<style>
  /* ---------- popup logowania / rejestracji: ---------- */
  /* ---------- te same kolory, kroje i kształty co reszta strony ---------- */
  dialog.acct-dialog{
    border:1px solid var(--line-strong); border-radius:16px; padding:0;
    background:var(--bg); color:var(--ink);
    width:min(460px, 94vw); max-height:min(90vh, 760px); overflow:auto;
    box-shadow:0 24px 72px rgba(0,0,0,0.5),0 0 0 1px rgba(201,162,39,.05);
  }
  dialog.acct-dialog::backdrop{ background:rgba(8,14,10,0.78); backdrop-filter:blur(5px); }
  dialog.acct-dialog[open]{ animation: guess-pop .22s cubic-bezier(.22,.61,.36,1); }
  .acct-shell{
    position:relative; padding:26px 24px 24px; overflow:hidden;
    background:
      radial-gradient(420px 220px at 100% -10%, rgba(201,162,39,.13), transparent 60%),
      radial-gradient(360px 200px at -10% 110%, rgba(90,138,134,.18), transparent 60%),
      var(--bg);
  }
  .acct-close{
    position:absolute; top:12px; right:12px; z-index:3;
    width:32px; height:32px; border-radius:50%;
    border:1px solid var(--line-strong); background:var(--card);
    color:var(--ink-dim); cursor:pointer; padding:0;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; line-height:1;
  }
  .acct-close:hover{ border-color:var(--gold); color:var(--gold); }
  .acct-title{ font-family:'Fraunces', serif; font-weight:500; font-size:23px; margin:0 40px 4px 0; }
  .acct-sub{ font-size:12.5px; color:var(--ink-dim); margin:0 0 18px; }
  .acct-tabs{ display:flex; gap:6px; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:4px; margin-bottom:18px; }
  .acct-tab{
    flex:1; text-align:center; background:none; border:1px solid transparent; color:var(--ink-dim);
    font-family:'JetBrains Mono',monospace; font-size:11.5px; letter-spacing:.03em;
    text-transform:uppercase; padding:9px 8px; border-radius:10px; cursor:pointer;
    transition:background .2s, color .2s, border-color .2s;
  }
  .acct-tab:hover{ color:var(--ink); }
  .acct-tab.active{ background:var(--gold); border-color:var(--gold); color:#141c16; font-weight:700; }
  .acct-pane{ display:none; }
  .acct-pane.active{ display:block; }
  .acct-pane label{ display:block; font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); text-transform:uppercase; letter-spacing:.05em; margin:0 0 6px; }
  .acct-pane input{
    width:100%; background:var(--card); border:1px solid var(--line-strong); border-radius:10px;
    padding:11px 13px; color:var(--ink); font-size:16px; margin-bottom:13px;
  }
  .acct-pane input:focus{ outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(201,162,39,.16); }
  .acct-honeypot{ position:absolute !important; left:-9999px !important; width:1px; height:1px; overflow:hidden; }
  .acct-hint{ font-size:12px; color:var(--ink-dim); margin:8px 0 0; line-height:1.5; }
  .acct-hint a{ color:var(--gold); text-decoration:none; border-bottom:1px solid rgba(201,162,39,.4); }
  .acct-hint a:hover{ border-bottom-color:var(--gold); }
  .acct-submit{
    width:100%; background:var(--gold); color:#141c16; border:none; font-weight:600;
    font-family:'Inter',sans-serif; font-size:14px; padding:13px 18px; border-radius:24px; cursor:pointer; margin-top:2px;
    transition:filter .2s, transform .15s;
  }
  .acct-submit:hover{ filter:brightness(1.06); transform:translateY(-1px); }
  .acct-submit:disabled{ opacity:.55; cursor:default; transform:none; }
  .acct-msg{ font-size:12.5px; border-radius:10px; padding:9px 13px; margin-bottom:13px; display:none; }
  .acct-msg.err{ display:block; background:rgba(166,90,60,.16); border:1px solid rgba(166,90,60,.50); color:var(--ink); }
  .acct-msg.ok{ display:block; background:rgba(90,138,134,.18); border:1px solid rgba(90,138,134,.50); color:var(--ink); }
  @media(max-width:480px){
    dialog.acct-dialog{ width:calc(100vw - 22px); max-height:calc(100dvh - 22px); border-radius:18px; }
    .acct-shell{ padding:24px 19px 20px; }
    .acct-tabs{ gap:3px; margin-bottom:15px; }
    .acct-tab{ padding:9px 4px; font-size:9px; letter-spacing:0; }
    .acct-title{ font-size:21px; }
  }
  .acct-loggedin{ text-align:center; padding:8px 0 4px; }
  .acct-loggedin .av{
    width:64px; height:64px; border-radius:50%; margin:0 auto 14px; padding:2px;
    background:linear-gradient(135deg, var(--gold), var(--teal));
    position:relative;
  }
  .acct-loggedin .av span{
    width:100%; height:100%; border-radius:50%; background:var(--bg-alt); display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces',serif; font-weight:600; font-size:22px; color:var(--ink);
  }
  /* własne zdjęcie awatara wyleża się nad inicjały, w obrębie pierścienia */
  .acct-loggedin .av .av-photo{
    position:absolute; inset:2px; width:auto; height:auto;
    background-size:cover; background-position:center; background-repeat:no-repeat;
  }
  .acct-loggedin .name{ font-family:'Fraunces',serif; font-size:17px; font-weight:500; }
  .acct-loggedin .who{ font-family:'JetBrains Mono',monospace; font-size:11.5px; color:var(--ink-dim); margin:2px 0 18px; }
  /* ---------- menu konta: lista funkcji dla zalogowanego ---------- */
  /* Pasek ma tylko 3 przyciski, więc powiadomienia, wiadomości, panel,
     profil i wylogowanie siedzą w dymku konta — jako lista pozycji. */
  .acct-menu{ display:flex; flex-direction:column; gap:6px; margin-top:2px; text-align:left; }
  .acct-menu-item{
    display:flex; align-items:center; gap:10px;
    padding:11px 14px; border-radius:12px;
    border:1px solid var(--line-strong); background:var(--card); color:var(--ink);
    font-family:'Inter',sans-serif; font-size:13.5px; font-weight:600;
    text-align:left; text-decoration:none; cursor:pointer;
    transition:border-color .2s, color .2s, background .2s;
  }
  .acct-menu-item:hover{ border-color:var(--gold); color:var(--gold); }
  .acct-menu-item[hidden]{ display:none; }
  .acct-menu-item svg{ width:17px; height:17px; flex:0 0 auto; }
  .acct-menu-item .bell-badge{
    margin-left:auto; min-width:18px; height:18px; padding:0 5px; border-radius:9px;
    background:var(--gold); color:#141c16;
    font-family:'JetBrains Mono',monospace; font-size:10.5px; font-weight:600;
    display:flex; align-items:center; justify-content:center; line-height:1;
    border:none; pointer-events:none;
  }
  .acct-menu-item .bell-badge[hidden]{ display:none; }
  .acct-menu-sep{ height:1px; background:var(--line); margin:3px 0; }
  .acct-menu-item.is-out{ color:var(--ink-dim); }
  .acct-menu-item.is-out:hover{ color:var(--gold); }
</style>

<dialog class="acct-dialog" id="acctDialog">
  <div class="acct-shell">
    <button class="acct-close" id="acctCloseBtn" type="button" aria-label="Zamknij">×</button>

    <div id="acctLoggedOutView">
      <div class="acct-title">Twoje konto</div>
       <p class="acct-sub">Zaloguj się lub dołącz — jedno konto otwiera całe archiwum.</p>
      <div class="acct-tabs">
        <button type="button" class="acct-tab active" data-tab="login">Zaloguj się</button>
        <button type="button" class="acct-tab" data-tab="register">Załóż konto</button>
        <button type="button" class="acct-tab" data-tab="reset">Reset hasła</button>
      </div>

      <div class="acct-msg" id="acctMsg"></div>

      <div class="acct-pane active" id="acctPaneLogin">
        <form id="acctLoginForm" autocomplete="on">
          <label for="acctLoginUser">Login</label>
          <input type="text" id="acctLoginUser" name="user" autocomplete="username" autocapitalize="none" required>
          <label for="acctLoginPass">Hasło</label>
          <input type="password" id="acctLoginPass" name="pass" autocomplete="current-password" required>
          <button type="submit" class="acct-submit" id="acctLoginSubmit">Zaloguj</button>
        </form>
      </div>

      <div class="acct-pane" id="acctPaneRegister">
        <p class="acct-sub" style="margin:0 0 14px;">Rejestracja pozwala dodawać zdjęcia i komentować pod własną nazwą użytkownika.</p>
        <form id="acctRegisterForm" autocomplete="on">
          <label for="acctRegUser">Login</label>
          <input type="text" id="acctRegUser" name="user" autocomplete="username" autocapitalize="none" minlength="3" maxlength="40" required>
          <label for="acctRegEmail">E-mail</label>
          <input type="email" id="acctRegEmail" name="email" autocomplete="email" maxlength="190" required>
          <p class="acct-hint">Na ten adres wyślemy link, gdy zapomnisz hasła. Możesz go później zmienić w panelu.</p>
          <label for="acctRegPass">Hasło</label>
          <input type="password" id="acctRegPass" name="pass" autocomplete="new-password" minlength="8" required>
          <div class="acct-honeypot" aria-hidden="true">
            <label for="acctRegWebsite">Strona www</label>
            <input type="text" id="acctRegWebsite" name="website" tabindex="-1" autocomplete="off">
          </div>
          <button type="submit" class="acct-submit" id="acctRegSubmit">Załóż konto</button>
        </form>
      </div>

      <div class="acct-pane" id="acctPaneReset">
        <form id="acctResetForm" autocomplete="on">
          <p class="acct-sub" style="margin:0 0 16px;">Podaj login albo adres e-mail — wyślemy link do ustawienia nowego hasła.</p>
          <label for="acctResetId">Login albo e-mail</label>
          <!-- Bez placeholdera: przykładowy login lub adres zdradzałby,
               jakie konta istnieją. Odpowiedź jest zawsze taka sama. -->
          <input type="text" id="acctResetId" name="identifier" autocomplete="username"
                 autocapitalize="none" autocorrect="off" spellcheck="false" required>
          <button type="submit" class="acct-submit" id="acctResetSubmit">Wyślij link</button>
          <p class="acct-hint">Link działa 60 minut i tylko raz. Sprawdź też spam.</p>
        </form>
      </div>
    </div>

    <div id="acctLoggedInView" style="display:none;">
      <div class="acct-loggedin">
        <div class="av"><span class="av-photo" id="acctAvatarPhoto"></span><span id="acctAvatarInitials">–</span></div>
        <div class="name" id="acctLoggedName">–</div>
        <div class="who" id="acctLoggedRole">–</div>
      </div>
      <!-- Lista funkcji konta: pasek ma tylko 3 przyciski, więc powiadomienia,
           wiadomości i wylogowanie siedzą tutaj. #notifyBell / #msgBtn mają
           zachowane ID, bo to je przełącza syncAddPhotoCta i odświeża poller. -->
      <div class="acct-menu">
        <a class="acct-menu-item" id="notifyBell" href="powiadomienia.php" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span>Powiadomienia</span>
          <span class="bell-badge" id="bellBadge" hidden></span>
        </a>
        <a class="acct-menu-item" id="msgBtn" href="wiadomosci.php" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Wiadomości</span>
          <span class="bell-badge" id="msgBadge" hidden></span>
        </a>
        <div class="acct-menu-sep"></div>
        <a class="acct-menu-item" id="acctGoPanel" href="panel.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
          <span>Twój panel</span>
        </a>
        <a class="acct-menu-item" id="acctGoProfile" href="#">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Mój profil</span>
        </a>
        <a class="acct-menu-item" href="panel.php#dodaj-zdjecie">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          <span>Dodaj zdjęcie</span>
        </a>
        <a class="acct-menu-item" id="acctGoAdmin" href="admin.php" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>Panel administracyjny</span>
        </a>
        <div class="acct-menu-sep"></div>
        <button class="acct-menu-item is-out" id="acctLogoutBtn" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Wyloguj</span>
        </button>
      </div>
    </div>
  </div>
</dialog>

<!-- account-ui.js — konto, logowanie, powiadomienia, kontakt (kolejność ładowania: account-ui → gallery-ui → photo-likes-ui) -->
<script src="<?= htmlspecialchars("account-ui.js?v=" . (int) @filemtime(__DIR__ . "/account-ui.js"), ENT_QUOTES) ?>" charset="utf-8" defer></script>

<!-- gallery-ui.js — główna logika galerii: siatka, lightbox, komentarze, ulubione, gry, odświeżanie sekcji (kolejność ładowania: account-ui → gallery-ui → photo-likes-ui) -->
<script src="<?= htmlspecialchars("gallery-ui.js?v=" . (int) @filemtime(__DIR__ . "/gallery-ui.js"), ENT_QUOTES) ?>" charset="utf-8" defer></script>

<!-- photo-likes-ui.js — polubienia zdjęć (kolejność ładowania: account-ui → gallery-ui → photo-likes-ui) -->
<script src="<?= htmlspecialchars("photo-likes-ui.js?v=" . (int) @filemtime(__DIR__ . "/photo-likes-ui.js"), ENT_QUOTES) ?>" charset="utf-8" defer></script>
<script>
  // Linki bez href z atrybutem data-scroll-to (np. „przejdź do galerii” w sekcji społeczności).
  // Delegacja zdarzeń, bo sekcja jest podmieniana przez outerHTML przy odświeżaniu.
  (function(){
    function go(e){
      const t = e.target && e.target.closest ? e.target.closest('[data-scroll-to]') : null;
      if(!t) return;
      const dest = document.getElementById(t.getAttribute('data-scroll-to'));
      if(!dest) return;
      e.preventDefault();
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      dest.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    }
    document.addEventListener('click', go);
    document.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') go(e); });
  })();
</script>
<style>
  /* Stary #scrollTopBtn (sterowany przez gallery-ui.js) chowamy — zastępuje go niezależny przycisk poniżej. */
  #scrollTopBtn{ display:none !important; }
  .zm-top-btn{
    position:fixed; right:18px; bottom:18px; z-index:60;
    width:46px; height:46px; border-radius:50%; padding:0;
    background:var(--card); border:1px solid var(--line-strong);
    color:var(--ink); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.25);
    opacity:0; visibility:hidden; transform:translateY(10px);
    transition:opacity .25s ease, transform .25s ease, visibility .25s, border-color .2s, color .2s;
  }
  .zm-top-btn.on{ opacity:1; visibility:visible; transform:translateY(0); }
  .zm-top-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .zm-top-btn svg{ display:block; width:20px; height:20px; flex:0 0 auto; }
  .zm-back-btn{
    position:fixed; right:72px; bottom:18px; z-index:60;
    width:46px; height:46px; border-radius:50%; padding:0;
    background:var(--card); border:1px solid var(--line-strong);
    color:var(--ink); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.25);
    opacity:0; visibility:hidden; transform:translateY(10px);
    transition:opacity .25s ease, transform .25s ease, visibility .25s, border-color .2s, color .2s;
  }
  .zm-back-btn.on{ opacity:1; visibility:visible; transform:translateY(0); }
  .zm-back-btn:hover{ border-color:var(--gold); color:var(--gold); }
  .zm-back-btn svg{ display:block; width:20px; height:20px; flex:0 0 auto; }
  @media (max-width:640px){
    .zm-top-btn{ right:14px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
    .zm-back-btn{ right:66px; bottom:calc(14px + env(safe-area-inset-bottom, 0px)); width:44px; height:44px; }
  }
</style>
<button type="button" class="zm-back-btn" id="zmBackBtn" aria-label="Wróć do poprzedniej strony" title="Wstecz">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
</button>
<button type="button" class="zm-top-btn" id="zmTopBtn" aria-label="Przewiń do góry" title="Do góry">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
</button>
<script>
  (function(){
    const btn = document.getElementById('zmTopBtn');
    const backBtn = document.getElementById('zmBackBtn');
    if(!btn) return;
    function pos(){
      const se = document.scrollingElement || document.documentElement;
      return Math.max(window.pageYOffset || 0, se.scrollTop || 0, document.body.scrollTop || 0);
    }
    function toggle(){
      const visible = pos() > 300;
      btn.classList.toggle('on', visible);
      if(backBtn) backBtn.classList.toggle('on', visible);
    }
    if(backBtn){
      backBtn.addEventListener('click', function(){
        window.history.back();
      });
    }
    btn.addEventListener('click', function(){
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top:0, behavior: reduce ? 'auto' : 'smooth' });
    });
    window.addEventListener('scroll', toggle, { passive:true });
    document.addEventListener('scroll', toggle, { passive:true, capture:true });
    window.addEventListener('resize', toggle);
    window.addEventListener('load', toggle);
    toggle();
  })();
</script>
</body>
</html>

/* =====================================================================
   gallery-ui.js — główna logika galerii (jeden duży IIFE, wspólny `state`)
   Wycięte z index.php; ładowane z defer, po account-ui.js (używa csrfToken,
   lastWho) i przed photo-likes-ui.js. Wymaga CONFIG z inline <script> w index.php.

   Zawartość (kolejność jak w kodzie):
     - blokada przewijania tła przy oknach (poprawka iOS)
     - ulubione zdjęcia (lokalne + z konta, synchronizacja między urządzeniami)
     - statystyki: wyświetlenia i ulubione liczone na serwerze (track.php)
     - odznaka autora zdjęcia, popup profilu, lista wszystkich użytkowników
     - komentarze do zdjęć i panel komentarzy w lightboxie
     - historia przeglądarki („wstecz” cofa w obrębie galerii), deep link ?photo=ID
     - pobieranie folderów/zdjęć (gallery.php), siatka, wyszukiwanie, sortowanie,
       gęstość siatki, paski „ostatnio dodane”
     - pinch-to-zoom na siatkach
     - lightbox: nawigacja, gesty dotykowe, zoom i przeciąganie na komputerze
     - zgłoszenie zdjęcia (moderacja)
     - motyw jasny/ciemny, przycisk strony głównej, mapa gmin, przycisk „do góry”
     - mini-gry: „zgadnij gminę” i „znajdź na mapie”
     - pasek narzędzi galerii
     - sekcje „Wspólny archiwum” i „Atrakcje turystyczne” odświeżane bez
       przeładowania strony (community-api.php, attraction-api.php)
     - płynne pojawianie się zdjęć, efekt „magnetic”
   ===================================================================== */
(function(){
  let state = {
    rootId: CONFIG.folderId,
    apiKey: CONFIG.apiKey,
    path: [],          // [{id,name}, ...] trail below root
    items: [],          // current folder's children (folders + images)
    rootFolders: [],    // gminy (top-level foldery) — cache dla mini-gry
    polling: null
  };

  const el = id => document.getElementById(id);
  const escapeHtml = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  /* Krótki komunikat na dole ekranu — zamiast okna przeglądarki.
     Błędy zostają na ekranie dłużej i mają inną obwódkę, bo to one
     wymagają reakcji, a potwierdzenia można przeczytać i zapomnieć. */
  function toast(msg, isError){
    const t = el('toast');
    if(!t) return;
    t.textContent = msg;
    t.classList.toggle('is-error', !!isError);
    t.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => t.classList.remove('show'), isError ? 4200 : 2600);
  }

  /* Wylogowanie — krótkie potwierdzenie na dole ekranu. Pokazujemy je zarówno
     przy kliknięciu „Wyloguj" w menu konta (zdarzenie zm:logout), jak i po
     powrocie z panel.php, który wraca tu z ?logout=1. */
  window.addEventListener('zm:logout', () => toast('Wylogowano'));
  (function(){
    try{
      const qs = new URLSearchParams(window.location.search);
      if(qs.get('logout') !== '1') return;
      toast('Wylogowano');
      qs.delete('logout');
      const rest = qs.toString();
      history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
    }catch(_){}
  })();
  const galleryRoot = el('galleryRoot');
  const errorBox = el('errorBox');

  // ---------- blokada przewijania tła (naprawia bug iOS: dotknięcie pola
  // tekstowego w komentarzach wewnątrz elementu "position:fixed" potrafiło
  // przesunąć całą stronę w bok i schować przycisk zamknięcia lightboxa) ----------
  let scrollLockY = 0;
  function lockBodyScroll(){
    scrollLockY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollLockY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.style.overflow = 'hidden';
  }
  function unlockBodyScroll(){
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    document.body.style.overflow = '';
    window.scrollTo(0, scrollLockY);
  }

  // ---------- panel komentarzy: dopasowanie do aktualnego kadru zdjęcia ----------
  function syncCommentsPanelToPhoto(){
    const panel = el('lightboxCommentsPanel');
    const lb = el('lightbox');
    if(!panel || !lb) return;
    const target = el('lightboxImg');
    if(!target || target.style.display === 'none') return;
    const photoRect = target.getBoundingClientRect();
    const lightboxRect = lb.getBoundingClientRect();
    if(!photoRect.width || !photoRect.height) return;
    panel.style.setProperty('--comments-photo-left', Math.round(photoRect.left - lightboxRect.left) + 'px');
    panel.style.setProperty('--comments-photo-top', Math.round(photoRect.top - lightboxRect.top) + 'px');
    panel.style.setProperty('--comments-photo-width', Math.round(photoRect.width) + 'px');
    panel.style.setProperty('--comments-photo-height', Math.round(photoRect.height) + 'px');
  }

  // Plakietka z opisem leży NA zdjęciu przy jego dolnej krawędzi, więc jej
  // pozycję liczymy z aktualnego kadru (jak panel komentarzy). Dzięki temu nie
  // wisi na tle przy zdjęciach pionowych ani nie zasłania podpisu pod kadrem.
  // Trzymamy ją też nad plakietką autora, żeby obie się nie nakładały.
  function syncLightboxDesc(){
    const box = el('lightboxDesc');
    const lb = el('lightbox');
    const img = el('lightboxImg');
    if(!box || !lb || !img || !box.classList.contains('open')) return;
    const photoRect = img.getBoundingClientRect();
    const lbRect = lb.getBoundingClientRect();
    if(!photoRect.width || !photoRect.height){ box.style.visibility = 'hidden'; return; }
    box.style.visibility = '';
    box.style.maxWidth = Math.max(120, Math.min(photoRect.width - 24, 560)) + 'px';
    const pad = 14;
    const boxW = box.offsetWidth;
    const boxH = box.offsetHeight;
    let left = photoRect.left - lbRect.left + (photoRect.width - boxW) / 2;
    let top = photoRect.bottom - lbRect.top - boxH - pad;
    const author = el('lightboxAuthor');
    if(author && author.classList.contains('has-author')){
      const aRect = author.getBoundingClientRect();
      top = Math.min(top, aRect.top - lbRect.top - pad - boxH);
    }
    left = Math.max(photoRect.left - lbRect.left + pad, left);
    top = Math.max(photoRect.top - lbRect.top + pad, top);
    box.style.left = Math.round(left) + 'px';
    box.style.top = Math.round(top) + 'px';
    box.style.right = 'auto';
    box.style.bottom = 'auto';
  }

  // Połączenie syncCommentsPanelToPhoto() i syncLightboxDesc() dla zdarzeń load/resize.
  // Wcześniej każda z nich mieszała odczyt geometrii z zapisem stylu, więc przeglądarka przeliczała
  // layout kilka razy pod rząd. Teraz: wszystkie odczyty na początku, potem zapisy; jedyny
  // odczyt po zapisie (rozmiar plakietki po ustawieniu max-width) jest nieunikniony.
  function syncLightboxOverlays(){
    const panel = el('lightboxCommentsPanel');
    const lb = el('lightbox');
    const img = el('lightboxImg');
    const box = el('lightboxDesc');
    if(!lb || !img) return;
    const doPanel = !!panel && img.style.display !== 'none';
    const doDesc = !!box && box.classList.contains('open');
    if(!doPanel && !doDesc) return;
    // --- odczyty ---
    const photoRect = img.getBoundingClientRect();
    const lbRect = lb.getBoundingClientRect();
    const author = doDesc ? el('lightboxAuthor') : null;
    const aRect = (author && author.classList.contains('has-author')) ? author.getBoundingClientRect() : null;
    const hasSize = !!(photoRect.width && photoRect.height);
    // --- zapisy ---
    if(doPanel && hasSize){
      panel.style.setProperty('--comments-photo-left', Math.round(photoRect.left - lbRect.left) + 'px');
      panel.style.setProperty('--comments-photo-top', Math.round(photoRect.top - lbRect.top) + 'px');
      panel.style.setProperty('--comments-photo-width', Math.round(photoRect.width) + 'px');
      panel.style.setProperty('--comments-photo-height', Math.round(photoRect.height) + 'px');
    }
    if(!doDesc) return;
    if(!hasSize){ box.style.visibility = 'hidden'; return; }
    box.style.visibility = '';
    box.style.maxWidth = Math.max(120, Math.min(photoRect.width - 24, 560)) + 'px';
    const pad = 14;
    const boxW = box.offsetWidth;   // jedyny odczyt po zapisie — rozmiar zależy od max-width
    const boxH = box.offsetHeight;
    let left = photoRect.left - lbRect.left + (photoRect.width - boxW) / 2;
    let top = photoRect.bottom - lbRect.top - boxH - pad;
    if(aRect) top = Math.min(top, aRect.top - lbRect.top - pad - boxH);
    left = Math.max(photoRect.left - lbRect.left + pad, left);
    top = Math.max(photoRect.top - lbRect.top + pad, top);
    box.style.left = Math.round(left) + 'px';
    box.style.top = Math.round(top) + 'px';
    box.style.right = 'auto';
    box.style.bottom = 'auto';
  }

  function applyCommentsPanelKeyboardOffset(){
    const panel = el('lightboxCommentsPanel');
    if(!panel || !window.visualViewport) return;
    const vv = window.visualViewport;
    const inset = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    panel.style.setProperty('--kb-inset', inset + 'px');
    panel.classList.toggle('kb-open', inset > 80);
    requestAnimationFrame(syncCommentsPanelToPhoto);
  }
  el('lightboxImg').addEventListener('load', () => requestAnimationFrame(syncLightboxOverlays));
  window.addEventListener('resize', () => requestAnimationFrame(syncLightboxOverlays), { passive:true });
  if(window.visualViewport){
    window.visualViewport.addEventListener('resize', applyCommentsPanelKeyboardOffset);
    window.visualViewport.addEventListener('scroll', applyCommentsPanelKeyboardOffset);
  }

  // ---------- ulubione zdjęcia ----------
  //
  // Źródłem prawdy jest BAZA (favorites-api.php) i tylko ona. Wcześniej
  // loadFavorites() czytał listę z localStorage przy każdym renderze, więc po
  // zmianie w bazie (inny sprzęt, reset, moderacja) serca i pasek ulubionych
  // pokazywały poprzedni stan, aż do przeładowania.
  //
  // localStorage zostaje WYŁĄCZNIE dla niezalogowanych, bo favorites-api.php
  // wymaga sesji (401 dla gościa) i nie ma gdzie trzymać ich ulubionych.
  const FAV_KEY = 'zmogilna-favorites';
  const FAV_API = 'favorites-api.php';
  const BOOKMARK_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"></path></svg>`;
  const HEART_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"></path>`;
  let favAccount = false;   // true po zalogowaniu → źródłem prawdy jest baza
  // stan tylko w pamięci; przy zalogowanym startujemy pusto i czekamy na bazę,
  // żeby nie pokazać na pierwszą chwilę listy z localStorage
  let favMem = (lastWho && lastWho.user) ? [] : guestFavoritesFromStorage();
  function guestFavoritesFromStorage(){
    try{
      const v = JSON.parse(localStorage.getItem(FAV_KEY) || '[]');
      return Array.isArray(v) ? v : [];
    }catch(_){ return []; }
  }

  async function apiFavList(){
    const res = await fetch(FAV_API + '?action=list', { headers:{ Accept:'application/json' }, credentials:'include', cache:'no-store' });
    if(!res.ok) throw new Error('status ' + res.status);
    const data = await res.json();
    if(!data || !data.ok || !Array.isArray(data.items)) throw new Error('bad payload');
    return data.items;
  }
  async function apiFavClear(){
    const res = await fetch(FAV_API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      credentials:'include',
      body: JSON.stringify({ action:'clear', csrf:csrfToken })
    });
    if(!res.ok) throw new Error('status ' + res.status);
  }
  async function apiFavToggle(id){
    const res = await fetch(FAV_API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      credentials:'include',
      body: JSON.stringify({ action:'toggle', photo:id, csrf:csrfToken })
    });
    if(!res.ok) throw new Error('status ' + res.status);
    const data = await res.json();
    if(data && data.error) throw new Error(data.error);
    return data && !!data.favorited;
  }

  // render czyta WYŁĄCZNIE pamięć — nigdy nie odczytuje stanu z localStorage
  function loadFavorites(){
    return favMem;
  }
  function saveFavoritesList(list){
    favMem = Array.isArray(list) ? list : [];
    // lustro tylko dla niezalogowanych (i na wypadek wylogowania);
    // przy zalogowanym liczy się i tak tylko baza
    try{ localStorage.setItem(FAV_KEY, JSON.stringify(favMem)); }catch(_){}
  }
  function isFavorited(id){
    return loadFavorites().some(f => f.id === id);
  }
  // ---------- statystyki: wyświetlenia i ulubione liczone na serwerze (track.php) ----------
  function trackPhoto(id, type){
    if(!/^p\d+$/.test(String(id || ''))) return;
    try{
      const body = JSON.stringify({ photo:id, type });
      if(navigator.sendBeacon && navigator.sendBeacon('track.php', new Blob([body], { type:'application/json' }))) return;
      fetch('track.php', { method:'POST', headers:{ 'Content-Type':'application/json' }, body, keepalive:true }).catch(() => {});
    }catch(_){}
  }
  const VIEW_KEY = 'zmogilna-viewed';
  const VIEW_TTL = 12 * 3600 * 1000;   // to samo zdjęcie liczymy raz na 12 h na przeglądarkę
  function trackViewOnce(id){
    try{
      const map = JSON.parse(localStorage.getItem(VIEW_KEY) || '{}');
      const now = Date.now();
      Object.keys(map).forEach(k => { if(now - map[k] > VIEW_TTL) delete map[k]; });
      if(map[id]) return;
      map[id] = now;
      localStorage.setItem(VIEW_KEY, JSON.stringify(map));
    }catch(_){}
    trackPhoto(id, 'view');
  }
  // UWAGA: brak trackFavorite(). Wcześniej ulubione wysyłały beacon
  // 'fav'/'unfav' do track.php, który trzymał własny rejestr odwiedzonych ID
  // w localStorage (zmogilna-fav-tracked). Dziś track.php nic w tym trybie nie
  // liczy — prawdziwy zapis idzie przez favorites-api.php, a licznik
  // photos.favorites jest wyprowadzany z tabeli user_favorites. Ta dodatkowa
  // warstwa tylko dublowała stan w przeglądarce i potrafiła go rozjechać.

  function toggleFavorite(photo){
    const list = loadFavorites();
    const idx = list.findIndex(f => f.id === photo.id);
    let nowFav;
    if(idx >= 0){ list.splice(idx,1); nowFav = false; }
    else {
      list.unshift({
        id:photo.id,
        name:photo.name,
        thumbnailLink:photo.thumbnailLink,
        createdTime:photo.createdTime,
        imageMediaMetadata:photo.imageMediaMetadata,
        mapUrl:photo.mapUrl
      });
      nowFav = true;
    }
    saveFavoritesList(list);
    renderFavoritesSection();
    syncAllFavHearts();   // to samo zdjęcie bywa w kilku kafelkach naraz
    // zalogowany: baza jest źródłem — potwierdzamy toggle optymistycznie zrobiony
    // w UI i poprawiamy listę, gdyby serwer (inna karta/inne urządzenie) widział inaczej
    if(favAccount){
      apiFavToggle(photo.id).then(okFav => {
        const cur = loadFavorites();
        const i = cur.findIndex(f => f.id === photo.id);
        if(Boolean(okFav) !== (i >= 0)){
          if(okFav){
            cur.unshift({ id:photo.id, name:photo.name, thumbnailLink:photo.thumbnailLink, createdTime:photo.createdTime });
          } else {
            cur.splice(i,1);
          }
          saveFavoritesList(cur);
          renderFavoritesSection();
          syncAllFavHearts();
        }
      }).catch(() => refreshAccountFavorites());
    }
    return nowFav;
  }
  function favBtnHtml(id, extraClass){
    const fav = isFavorited(id);
    return `<button type="button" class="fav-btn${extraClass ? ' '+extraClass : ''}${fav ? ' is-fav' : ''}" data-fav-id="${id}" aria-label="${fav ? 'Usuń z ulubionych' : 'Dodaj do ulubionych'}" title="${fav ? 'Usuń z ulubionych' : 'Dodaj do ulubionych'}">${BOOKMARK_SVG}</button>`;
  }

  /*
   * Jeden stan — wiele miejsc na stronie. To samo zdjęcie pojawia się w
   * kilku kafelkach naraz (siatka, ostatnie zdjęcia, „ostatnie komentarze”,
   * pasek ulubionych, lightbox), a każde z nich ma własny przycisk .fav-btn.
   * Wcześniej zmiana ulubionego aktualizowała tylko ten przycisk, na który
   * kliknięto, więc reszta serc pokazywała stary stan aż do przeładowania.
   * Po każdej zmianie listy przechodzimy po wszystkich [data-fav-id] i
   * ustawiamy im stan z loadFavorites() — bez przebudowy widoków.
   */
  function syncFavHearts(photoId){
    const list = isFavorited(photoId);
    const label = list ? 'Usuń z ulubionych' : 'Dodaj do ulubionych';
    const sel = `.fav-btn[data-fav-id="${photoId}"], .lightbox-fav-btn[data-fav-id="${photoId}"]`;
    document.querySelectorAll(sel).forEach(btn => {
      btn.classList.toggle('is-fav', list);
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
    });
  }
  // po zmianie listy (serwer, inne urządzenie, czyszczenie) — wszystkie serca
  function syncAllFavHearts(){
    document.querySelectorAll('.fav-btn[data-fav-id], .lightbox-fav-btn[data-fav-id]')
      .forEach(btn => syncFavHearts(btn.dataset.favId));
  }

  function bindFavButtons(container, photosByIdGetter){
    container.querySelectorAll('.fav-btn[data-fav-id]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const id = btn.dataset.favId;
        const photo = photosByIdGetter(id);
        if(!photo) return;
        toggleFavorite(photo);
      });
    });
  }

  // ---------- odznaka „dodał/a: …” — kto wysłał zdjęcie do galerii ----------
  function authorInitials(name){
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if(parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
    if(parts.length === 1) return parts[0].slice(0,2).toUpperCase();
    return '?';
  }
  function authorBadgeHtml(f, cls){
    if(!f || !f.author || !f.author.id) return '';
    const a = f.author;
    const url = a.url || profileUrl(a.username, a.id);
    // awatar przychodzi tylko dla profili publicznych; inaczej zostają inicjały
    const photo = a.avatar
      ? `<span class="aa-photo" style="background-image:url('${escapeHtml(a.avatar)}')"></span>`
      : '';
    const initials = photo
      ? `<span class="aa-initials" hidden>${escapeHtml(authorInitials(a.name))}</span>`
      : `<span class="aa-initials">${escapeHtml(authorInitials(a.name))}</span>`;
    return `<a href="${url}" class="${cls || 'card-author'}" data-author-id="${a.id}" title="Zobacz profil: ${escapeHtml(a.name)}">` +
      `<span class="aa-dot">${photo}${initials}</span><span class="aa-name">${escapeHtml(a.name)}</span></a>`;
  }

  function bindAuthorBadges(container){
    container.querySelectorAll('[data-author-id]').forEach(badge => {
      badge.addEventListener('click', e => {
        e.preventDefault();
        e.stopPropagation();
        openUserProfile(badge.dataset.authorId);
      });
    });
  }

  // ---------- popup profilu użytkownika ----------
  async function openUserProfile(userId){
    const modal = el('userModal');
    if(!modal || !userId) return;
    modal.classList.add('open');
    const body = el('userModalBody');
    body.innerHTML = '<div class="um-loading">Wczytywanie profilu…</div>';
    try{
      const res = await fetch(`profile-api.php?action=profile&id=${encodeURIComponent(userId)}`, { headers:{ Accept:'application/json' } });
      const data = await res.json();
      if(!res.ok || !data.ok){
        body.innerHTML = '<div class="um-empty">Nie udało się wczytać profilu.</div>';
        return;
      }
      const u = data.user;
      const photos = data.photos || [];
      const shown = photos.slice(0, 12);
      const initials = authorInitials(u.name);
      if(u.private){
        body.innerHTML = `
          <div class="um-head">
            <div class="um-avatar"><div>${escapeHtml(initials)}</div></div>
            <div><h3 class="um-name" id="userModalName">${escapeHtml(u.name)}</h3></div>
          </div>
          <div class="um-empty">Ten użytkownik ma prywatny profil.</div>`;
        return;
      }
      const memberSince = u.memberSince ? new Date(u.memberSince).toLocaleDateString('pl-PL') : '—';
      // zdjęcie awatara z profilu publicznego; przy prywatnym zostają inicjały
      const umAvatar = u.avatar
        ? `<div class="um-avatar"><div class="um-photo" style="background-image:url('${escapeHtml(u.avatar)}')"></div><div hidden></div></div>`
        : `<div class="um-avatar"><div>${escapeHtml(initials)}</div></div>`;
      let html = `
        <div class="um-head">
          ${umAvatar}
          <div>
            <h3 class="um-name" id="userModalName">${escapeHtml(u.name)}</h3>
            <div class="um-handle">@${escapeHtml(u.username)}</div>
          </div>
        </div>
        <div class="um-stats">
          <div><b>${u.photoCount}</b>zdjęć w galerii</div>
          <div><b class="mono" style="font-size:14px;">${escapeHtml(memberSince)}</b>na pokładzie od</div>
        </div>`;
      if(shown.length){
        html += '<div class="um-grid">' + shown.map((p,i) => `
          <div class="um-tile" data-idx="${i}" title="${escapeHtml(p.name || '')}">
            <img ${thumbAttrs(p.thumbnailLink, 240, '100px')} alt="${escapeHtml(p.name || '')}" loading="lazy">
          </div>`).join('') + '</div>';
      } else {
        html += '<div class="um-empty">Ten użytkownik nie ma jeszcze zatwierdzonych zdjęć.</div>';
      }
      html += `<a class="um-more" href="${u.profileUrl || profileUrl(u.username, u.id)}">Zobacz pełny profil →</a>`;
      body.innerHTML = html;
      body.querySelectorAll('.um-tile').forEach(t => {
        t.addEventListener('click', () => {
          closeUserModal();
          openLightbox(shown, +t.dataset.idx);
        });
      });
    }catch(_){
      body.innerHTML = '<div class="um-empty">Błąd połączenia z serwerem.</div>';
    }
  }
  function closeUserModal(){
    const modal = el('userModal');
    if(modal) modal.classList.remove('open');
  }
  (function bindUserModal(){
    const modal = el('userModal');
    if(!modal) return;
    el('userModalClose').addEventListener('click', e => { e.stopPropagation(); closeUserModal(); });
    modal.addEventListener('click', e => { if(e.target === modal) closeUserModal(); });
    document.addEventListener('keydown', e => {
      if(e.key === 'Escape' && modal.classList.contains('open')) closeUserModal();
    });
  })();

  // ---------- popup „wszyscy użytkownicy” ----------
  // Lista ładuje się z publicznego endpointu directory-api.php (publiczne
  // profile + administratorzy). Przycisk otwierający siedzi w sekcji
  // społeczności, którą strona podmienia co 20 s — dlatego klik łapiemy
  // delegacją na dokumencie, a nie na samym przycisku.
  (function bindDirectoryModal(){
    const backdrop = el('dirModal');
    if(!backdrop) return;
    const listBox  = el('dirList');
    const moreBtn  = el('dirMore');
    const searchIn = el('dirSearch');
    const rankSel  = el('dirRank');
    const closeBtn = el('dirModalClose');
    const sortBtns = backdrop.querySelectorAll('[data-dir-sort]');

    const state = { sort:'joined', rank:0, q:'', offset:0, limit:50, total:0, loading:false, got:0 };
    let ranksLoaded = false;

    function initialsOf(name){
      const s = String(name == null ? '' : name).trim();
      return s ? s.slice(0,1).toUpperCase() : '?';
    }
    function fillRanks(ranks){
      if(ranksLoaded || !Array.isArray(ranks)) return;
      ranks.forEach(r => {
        const opt = document.createElement('option');
        opt.value = String(r.id);
        opt.textContent = 'Ranga: ' + r.name;
        rankSel.appendChild(opt);
      });
      ranksLoaded = true;
    }
    function itemHtml(u){
      const av = u.avatar
        ? `<span class="dir-av"><img src="${escapeHtml(u.avatar)}" alt="" loading="lazy"></span>`
        : `<span class="dir-av">${escapeHtml(initialsOf(u.name))}</span>`;
      const nameEl = u.isPrivate
        ? `<span class="dir-name no-link" title="Profil prywatny">${escapeHtml(u.name)}</span>`
        : `<a class="dir-name" href="${escapeHtml(u.url)}">${escapeHtml(u.name)}</a>`;
      const badges = [];
      if(u.isAdmin) badges.push('<span class="dir-chip admin">Administrator</span>');
      if(u.specialRankName){
        badges.push(`<span class="dir-chip" style="border-color:hsl(${u.specialRankHue} 55% 45%);color:hsl(${u.specialRankHue} 65% 72%)">${escapeHtml(u.specialRankName)}</span>`);
      }
      // Profil prywatny: nie pokazujemy punktów, rangi zdobywanej za punkty ani
      // daty dołączenia — zamiast tego odznaka „Profil prywatny”. Rangę specjalną
      // i oznaczenie administratora (powyżej) zostawiamy — to publiczna informacja
      // o roli, nie o aktywności.
      if(u.isPrivate){
        badges.push('<span class="dir-chip private">Profil prywatny</span>');
      } else if(u.rankName){
        badges.push(`<span class="dir-chip">${escapeHtml(u.rankName)}</span>`);
      }
      const joined = (u.joined && !u.isPrivate) ? `<span class="dir-handle">od ${escapeHtml(u.joined)}</span>` : '';
      const points = u.isPrivate
        ? ''
        : `<div class="dir-points"><b>${Number(u.points || 0).toLocaleString('pl-PL')}</b>pkt</div>`;
      return `<div class="dir-item">
        ${av}
        <div class="dir-info">
          ${nameEl}
          <div class="dir-meta"><span class="dir-handle">@${escapeHtml(u.username)}</span>${badges.join('')}${joined}</div>
        </div>
        ${points}
      </div>`;
    }

    async function load(reset){
      if(state.loading) return;
      state.loading = true;
      if(reset){
        state.offset = 0; state.got = 0;
        listBox.innerHTML = '<p class="dir-empty">Wczytywanie…</p>';
        moreBtn.hidden = true;
      } else {
        moreBtn.disabled = true;
      }
      const params = new URLSearchParams({
        sort: state.sort, rank: String(state.rank), q: state.q,
        limit: String(state.limit), offset: String(state.offset)
      });
      try{
        const res = await fetch('directory-api.php?' + params.toString(), { headers:{ Accept:'application/json' } });
        const data = await res.json();
        if(!data || !data.ok){
          if(reset) listBox.innerHTML = '<p class="dir-empty">Nie udało się wczytać listy.</p>';
          return;
        }
        fillRanks(data.specialRanks);
        const items = data.items || [];
        state.total = data.total || 0;
        const html = items.map(itemHtml).join('');
        if(reset){
          listBox.innerHTML = items.length ? html : '<p class="dir-empty">Brak użytkowników dla wybranych kryteriów.</p>';
        } else if(html){
          listBox.insertAdjacentHTML('beforeend', html);
        }
        state.got += items.length;
        state.offset = state.got;
        moreBtn.hidden = !data.hasMore;
        moreBtn.disabled = false;
      }catch(_){
        if(reset) listBox.innerHTML = '<p class="dir-empty">Błąd połączenia z serwerem.</p>';
      }finally{
        state.loading = false;
      }
    }

    function open(){ backdrop.classList.add('open'); closeBtn && closeBtn.focus(); load(true); }
    function close(){ backdrop.classList.remove('open'); }

    document.addEventListener('click', e => {
      const trigger = e.target.closest ? e.target.closest('[data-open-directory]') : null;
      if(trigger){ e.preventDefault(); open(); }
    });

    sortBtns.forEach(btn => btn.addEventListener('click', () => {
      const s = btn.dataset.dirSort;
      if(s === state.sort) return;
      state.sort = s;
      sortBtns.forEach(b => b.classList.toggle('is-on', b === btn));
      load(true);
    }));
    rankSel && rankSel.addEventListener('change', () => {
      state.rank = parseInt(rankSel.value, 10) || 0;
      load(true);
    });
    let searchTimer = null;
    searchIn && searchIn.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = searchIn.value.trim(); load(true); }, 250);
    });
    moreBtn && moreBtn.addEventListener('click', () => load(false));

     closeBtn && closeBtn.addEventListener('click', e => { e.stopPropagation(); close(); });
    document.addEventListener('keydown', e => {
      if(e.key === 'Escape' && backdrop.classList.contains('open')) close();
    });
  })();

  function renderFavoritesSection(){
    const section = el('favoritesSection');
    const strip = el('favoritesStrip');
    const countEl = el('favCount');
    if(!section || !strip) return;
    const list = loadFavorites();
    if(!list.length){
      section.style.display = 'none';
      strip.innerHTML = '';
      return;
    }
    section.style.display = '';
    countEl.textContent = `· ${list.length}`;
    strip.innerHTML = list.map((f,i) => `
      <div class="fav-tile" data-idx="${i}" title="${escapeHtml(f.name)}">
        <img ${thumbAttrs(f.thumbnailLink, 320, '130px')} alt="${escapeHtml(f.name)}" loading="lazy" decoding="async">
      </div>
    `).join('');
    strip.querySelectorAll('.fav-tile').forEach(tile => {
      tile.addEventListener('click', () => openLightbox(list, +tile.dataset.idx));
    });
    bindFavButtons(strip, id => list.find(f => f.id === id));
  }

  // Ulubione są zapisane w przeglądarce, więc zdjęcie ukryte / usunięte / zaplanowane
  // w panelu admina zostawałoby tu na zawsze. Pytamy serwer, które z nich są nadal
  // opublikowane, resztę usuwamy i odświeżamy nazwy oraz miniatury.
  let favSyncing = false, favSyncAt = 0;
  async function syncFavoritesWithServer(force){
    if(favSyncing) return;
    if(!force && Date.now() - favSyncAt < 30000) return;
    const list = loadFavorites();
    const ids = list.map(f => f.id).filter(id => /^p\d+$/.test(String(id)));
    if(!ids.length) return;
    favSyncing = true;
    try{
      const res = await fetch('gallery.php?ids=' + encodeURIComponent(ids.join(',')), { cache:'no-store' });
      if(!res.ok) return;                       // błąd serwera ≠ „zdjęcia zniknęły”
      const data = await res.json();
      if(!data || !Array.isArray(data.files)) return;
      favSyncAt = Date.now();
      const alive = new Map(data.files.map(f => [f.id, f]));
      const current = loadFavorites();          // mogło się zmienić w trakcie zapytania
      const next = [];
      current.forEach(f => {
        if(!/^p\d+$/.test(String(f.id))){ next.push(f); return; }
        const fresh = alive.get(f.id);
        if(fresh) next.push(Object.assign({}, f, fresh));
      });
      const changed = next.length !== current.length || JSON.stringify(next) !== JSON.stringify(current);
      if(changed){
        saveFavoritesList(next);
        renderFavoritesSection();
        syncAllFavHearts();
      }
    }catch(_){ /* brak sieci — zostawiamy listę bez zmian */ }
    finally{ favSyncing = false; }
  }
  document.addEventListener('visibilitychange', () => {
    if(document.visibilityState === 'visible'){
      syncFavoritesWithServer(false);
      if(favAccount) refreshAccountFavorites();
    }
  });
  window.addEventListener('pageshow', () => {
    syncFavoritesWithServer(false);
    if(favAccount) refreshAccountFavorites();
  });

  const favClearBtn = document.getElementById('favClearBtn');
  if(favClearBtn){
    favClearBtn.addEventListener('click', () => {
      saveFavoritesList([]);
      renderFavoritesSection();
      syncAllFavHearts();
      // zalogowany: czyszczymy też bazę (źródło prawdy)
      if(favAccount) apiFavClear().catch(() => refreshAccountFavorites());
    });
  }

  // ---------- ulubione z konta (baza, synchronizacja między urządzeniami) ----------
  async function refreshAccountFavorites(){
    if(!favAccount) return;
    let server;
    try{ server = await apiFavList(); }catch(_){ return; }
    const filtered = server.filter(f => /^p\d+$/.test(String(f.id || '')));
    saveFavoritesList(filtered);
    renderFavoritesSection();
    // lista z bazy może różnić się od tego, co widać na ekranie (np. ulubione
    // dodane na telefonie) — serca muszą pokazać stan bazy, nie snapshot
    syncAllFavHearts();
  }
  // Jednorazowa migracja po zalogowaniu: ulubione trzymane dotąd w przeglądarce
  // dopisujemy do konta, a potem stan w pamięci nadpisujemy tym, co jest w bazie.
  async function enableAccountFavorites(){
    if(favAccount) return;
    favAccount = true;
    // wprost z magazynu gościa, nie z pamięci — przy zalogowanym render startuje
    // od pustej listy i czeka na bazę, więc stąd nie ma nic do przenieść
    const local = guestFavoritesFromStorage();
    let server;
    try{ server = await apiFavList(); }catch(_){ return; }
    const serverIds = new Set(server.map(f => f.id));
    const missing = local.filter(f => /^p\d+$/.test(String(f.id || '')) && !serverIds.has(f.id));
    for(const f of missing){
      try{ await apiFavToggle(f.id); }catch(_){}
    }
    await refreshAccountFavorites();
  }
  async function disableAccountFavorites(){
    favAccount = false;
    // po wylogowaniu wracamy do listy gościa (ta sama, którą trzymamy w lustro)
    favMem = guestFavoritesFromStorage();
    renderFavoritesSection();
    syncAllFavHearts();
  }

  window.addEventListener('zm:auth', e => { if(e && e.detail && e.detail.user) enableAccountFavorites(); });
  window.addEventListener('zm:logout', () => disableAccountFavorites());
  if(lastWho && lastWho.user) enableAccountFavorites();

  // ---------- komentarze do zdjęć (backend PHP + SQLite, wspólny dla wszystkich
  // odwiedzających; localStorage służy tylko jako lokalna kopia zapasowa na
  // wypadek braku sieci oraz do zapamiętania, które komentarze może skasować
  // to urządzenie) ----------
  const COMMENTS_API = 'comments.php';
  const COMMENT_AUTHOR_KEY = 'zmogilna-comment-author';
  const COMMENT_TOKENS_KEY = 'zmogilna-comment-tokens';   // { [commentId]: deleteToken }
  let commentsApiOk = true;

  function loadDeleteTokens(){
    try{ return JSON.parse(localStorage.getItem(COMMENT_TOKENS_KEY) || '{}'); }catch(_){ return {}; }
  }
  function saveDeleteToken(id, token){
    try{
      const all = loadDeleteTokens();
      all[id] = token;
      localStorage.setItem(COMMENT_TOKENS_KEY, JSON.stringify(all));
    }catch(_){}
  }
  function removeDeleteToken(id){
    try{
      const all = loadDeleteTokens();
      delete all[id];
      localStorage.setItem(COMMENT_TOKENS_KEY, JSON.stringify(all));
    }catch(_){}
  }
  // Komentarze: źródłem jest WYŁĄCZNIE comments.php. Wcześniej trzymaliśmy
  // pełną listę w localStorage jako „rezerwę offline”, przez to po zmianie
  // w bazie (nowy komentarz, usunięty, lajk) ekran pokazywał stary stan,
  // dopóki ktoś nie przeładował stronę.
  async function getCommentsFor(photoId){
    try{
      const res = await fetch(`${COMMENTS_API}?action=for&photo_id=${encodeURIComponent(photoId)}&voter=${encodeURIComponent(voterKey())}`, { headers:{ Accept:'application/json' }, cache:'no-store' });
      if(!res.ok) throw new Error('status ' + res.status);
      const data = await res.json();
      if(!Array.isArray(data)) throw new Error('bad payload');
      commentsApiOk = true;
      return data;
    }catch(_){
      commentsApiOk = false;
      return [];
    }
  }

  // anonimowy, losowy identyfikator przeglądarki — pozwala polubić komentarz tylko raz
  function voterKey(){
    try{
      let v = localStorage.getItem('zmogilna-voter');
      if(!v || !/^[A-Za-z0-9_-]{12,64}$/.test(v)){
        const bytes = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        v = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        localStorage.setItem('zmogilna-voter', v);
      }
      return v;
    }catch(_){
      return 'anon' + Math.random().toString(36).slice(2, 14).padEnd(12, '0');
    }
  }
  async function likeCommentFor(id){
    const res = await fetch(COMMENTS_API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      // csrf idzie zawsze — backend wymaga go tylko przy zalogowaniu,
      // a przy polubieniu z konta zapisujemy user_id na profil
      body: JSON.stringify({ action:'like', id, voter: voterKey(), csrf: csrfToken })
    });
    if(!res.ok) throw new Error('status ' + res.status);
    const data = await res.json();
    if(data.error) throw new Error(data.error);
    return data;   // { liked, likes }
  }

  async function addCommentFor(photoId, author, text, photoSnapshot, parentId){
    const res = await fetch(COMMENTS_API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({
        action:'add', photo_id: photoId, author, text, csrf: csrfToken,
        parent_id: parentId || 0,
        photo_name: photoSnapshot ? (photoSnapshot.name || '') : '',
        photo_thumb: photoSnapshot ? (photoSnapshot.thumbnailLink || '') : '',
        photo_created: photoSnapshot ? (photoSnapshot.createdTime || '') : ''
      })
    });
    // Serwer może odrzucić komentarz z własnym powodem (wulgaryzm, ban) —
    // czytamy treść odpowiedzi także przy błędnym statusie, bo bez niej
    // użytkownik dostałby fałszywe „sprawdź połączenie z internetem”.
    const payload = await res.json().catch(() => null);
    if(!res.ok){
      const err = new Error((payload && (payload.message || payload.error)) || ('status ' + res.status));
      err.apiMessage = payload && payload.message ? payload.message : null;
      err.apiError = payload && payload.error ? payload.error : null;
      throw err;
    }
    const saved = payload || {};
    if(saved.error) throw new Error(saved.error);
    commentsApiOk = true;
    if(saved.id != null && saved.delete_token) saveDeleteToken(saved.id, saved.delete_token);
    return saved;
  }

  // Akcje moderatora przy komentarzu (edytuj / usuń). Osobna funkcja, bo nie
  // idą przez addCommentFor — tam błąd jest informacją dla zwykłego użytkownika,
  // a tutaj dla administratora, który musi wiedzieć, dlaczego zapis się nie udał.
  async function adminCommentCall(payload){
    const res = await fetch(COMMENTS_API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify(Object.assign({ csrf: csrfToken }, payload))
    });
    const data = await res.json().catch(() => null);
    if(!res.ok){
      const err = new Error((data && (data.message || data.error)) || ('status ' + res.status));
      err.apiMessage = data && data.message ? data.message : null;
      throw err;
    }
    return data || {};
  }

  async function deleteCommentFor(photoId, id){
    const tokens = loadDeleteTokens();
    const token = tokens[id];
    if(token){
      try{
        await fetch(COMMENTS_API, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'delete', id, delete_token: token })
        });
      }catch(_){}
      removeDeleteToken(id);
    }
  }

  async function getAllCommentsFlat(limit){
    try{
      const res = await fetch(`${COMMENTS_API}?action=recent&limit=${limit || 14}`, { headers:{ Accept:'application/json' } });
      if(!res.ok) throw new Error('status ' + res.status);
      const data = await res.json();
      if(!Array.isArray(data)) throw new Error('bad payload');
      commentsApiOk = true;
      return data
        .filter(c => c.photo_thumb)
        .map(c => ({
          id: c.id, photoId: c.photo_id, author: c.author, text: c.text, ts: c.ts,
          authorUsername: c.author_username || null,
          // Pełny obiekt z serwera — dzięki niemu lightbox ma dane aparatu („i”)
          // i mapę. Migawka z samego komentarza zostaje jako zapas dla starych
          // wpisów, których zdjęcia nie ma już w `photos`.
          photo: c.photo || { id: c.photo_id, name: c.photo_name, thumbnailLink: c.photo_thumb, createdTime: c.photo_created }
        }));
    }catch(_){
      commentsApiOk = false;
      return [];
    }
  }

  // Rolka myszy przesuwa poziomą listę „Ostatnie komentarze” (bez Shift). Na początku/końcu listy
  // kółko wraca do zwykłego przewijania strony, żeby lista nie „łapała” kursora.
  (function bindRecentStripWheel(){
    const strip = el('recentCommentsStrip');
    if(!strip) return;
    strip.addEventListener('wheel', e => {
      if(strip.scrollWidth <= strip.clientWidth + 1) return;
      if(e.ctrlKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;   // gest poziomy / zoom — zostaw przeglądarce
      const dy = e.deltaMode === 1 ? e.deltaY * 32 : e.deltaMode === 2 ? e.deltaY * strip.clientWidth : e.deltaY;
      const atStart = strip.scrollLeft <= 0 && dy < 0;
      const atEnd = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1 && dy > 0;
      if(atStart || atEnd) return;
      e.preventDefault();
      strip.scrollBy({ left: dy, behavior: 'auto' });
    }, { passive:false });
  })();

  // Rolka myszy przesuwa poziomą karuzelę atrakcji (bez Shift), identycznie jak „Ostatnie
  // komentarze”. Na początku/końcu karuzeli kółko wraca do zwykłego przewijania strony.
  (function bindAttractionTrackWheel(){
    const track = document.querySelector('.attraction-track');
    if(!track) return;
    track.addEventListener('wheel', e => {
      if(track.scrollWidth <= track.clientWidth + 1) return;
      if(e.ctrlKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;   // gest poziomy / zoom — zostaw przeglądarce
      const dy = e.deltaMode === 1 ? e.deltaY * 32 : e.deltaMode === 2 ? e.deltaY * track.clientWidth : e.deltaY;
      const atStart = track.scrollLeft <= 0 && dy < 0;
      const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1 && dy > 0;
      if(atStart || atEnd) return;
      e.preventDefault();
      track.scrollBy({ left: dy, behavior: 'auto' });
    }, { passive:false });
  })();

  async function renderRecentCommentsSection(){
    const section = el('recentCommentsSection');
    const strip = el('recentCommentsStrip');
    if(!section || !strip) return;
    const items = await getAllCommentsFlat(14);
    if(!items.length){
      section.style.display = 'none';
      strip.innerHTML = '';
      return;
    }
    section.style.display = '';
    strip.innerHTML = items.map((c,i) => `
      <div class="comment-recent-tile" data-idx="${i}" title="${escapeHtml(c.photo.name || '')}">
        <div class="comment-recent-photo">
          <img ${thumbAttrs(c.photo.thumbnailLink, 480, 'auto, (max-width:640px) 50vw, 240px')} alt="${escapeHtml(c.photo.name || '')}" loading="lazy" decoding="async">
        </div>
        <div class="comment-recent-body">
          ${c.authorUsername
            ? `<a class="comment-recent-author is-account" href="${profileUrl(c.authorUsername)}">${escapeHtml(c.author || 'Anonim')}</a>`
            : `<div class="comment-recent-author">${escapeHtml(c.author || 'Anonim')}</div>`}
          <div class="comment-recent-text">${escapeHtml(c.text)}</div>
        </div>
      </div>
    `).join('');
    strip.querySelectorAll('.comment-recent-tile').forEach(tile => {
      tile.addEventListener('click', () => {
        const idx = +tile.dataset.idx;
        openLightbox(items.map(x => x.photo), idx, { openComments: true, focusCommentId: items[idx].id });
      });
    });
    // Tylko nazwa autora prowadzi do profilu i nie może otwierać lightboxa.
    // Reszta kafelku (miniatura, treść) otwiera zdjęcie z otwartymi
    // komentarzami i przewijaniem do tego wpisu — jak zawsze.
    strip.querySelectorAll('.comment-recent-author.is-account').forEach(a => {
      a.addEventListener('click', e => e.stopPropagation());
    });
  }

  function formatCommentDate(ts){
    try{ return new Date(ts).toLocaleString('pl-PL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }); }
    catch(_){ return ''; }
  }
  function savedAuthorName(){
    try{ return localStorage.getItem(COMMENT_AUTHOR_KEY) || ''; }catch(_){ return ''; }
  }
  /* Zalogowany użytkownik pisze „z konta” — nazwa bierze się z profilu
     (backend i tak ją nadpisuje), a pole tylko pokazuje, czym się zapisze. */
  function commentAuthorField(){
    const name = lastWho && lastWho.user ? (lastWho.display_name || lastWho.user) : '';
    if(!name) return `<input type="text" id="commentAuthorInput" placeholder="Twoje imię" value="${escapeHtml(savedAuthorName())}" maxlength="40" autocomplete="nickname">`;
    return `<div class="comment-as-account">
              <span class="comment-as-label">komentujesz jako</span>
              <span class="comment-as-name">${escapeHtml(name)}</span>
            </div>
            <input type="hidden" id="commentAuthorInput" value="${escapeHtml(name)}">`;
  }

  function currentFolderId(){
    return state.path.length ? state.path[state.path.length-1].id : state.rootId;
  }

  // ---------- integracja z historią przeglądarki, żeby "wstecz" cofało w obrębie strony ----------
  function pathKey(p){ return (p||[]).map(x => x.id).join('>'); }

  function pushGalleryHistory(){
    try{
      history.pushState({ type:'gallery', path: state.path.map(p => ({id:p.id, name:p.name})) }, '', location.href);
    }catch(_){}
  }
  function replaceGalleryHistory(){
    try{
      history.replaceState({ type:'gallery', path: state.path.map(p => ({id:p.id, name:p.name})) }, '', location.href);
    }catch(_){}
  }

  window.addEventListener('popstate', e => {
    const lightboxWasOpen = el('lightbox').classList.contains('open');
    if(lightboxWasOpen){
      // "wstecz" zamyka najpierw podgląd zdjęcia, tak jak w aplikacjach mobilnych
      el('lightbox').classList.remove('open');
      document.body.style.overflow = '';
      return;
    }
    const st = e.state;
    const newPath = (st && st.path) ? st.path : [];
    if(pathKey(newPath) !== pathKey(state.path)){
      state.path = newPath;
      fetchFolder(false);
    }
  });

  function renderBreadcrumb(){
    const rootLabel = 'Zdjęcia';
      document.body.classList.toggle('gallery-at-root', state.path.length === 0);
    let html = `<div class="breadcrumb">`;
      if(state.path.length === 0){
        html += `<span class="crumb-current">${rootLabel}</span>`;
        // Na stronie głównej również dostępny przełącznik "pokaż/ukryj miejscowości"
        // dla spójności z widokiem gminy
        if(placeFoldersCount > 0){
          html += `<span class="crumb-sep place-sep">/</span>` + placeFoldersBtnHtml();
        }
      }else{
      html += `<button data-idx="-1">${rootLabel}</button>`;
      state.path.forEach((p,i) => {
        html += `<span class="crumb-sep">/</span>`;
        if(i === state.path.length - 1){
          html += `<span class="crumb-current">${p.name}</span>`;
        }else{
          html += `<button data-idx="${i}">${p.name}</button>`;
        }
      });
      // Przełącznik miejscowości stoi tuż przy nazwie gminy — tam, gdzie
      // czytelnik i tak patrzy, w jakim katalogu jest. Na telefonie wiersz
      // sterowania przenosi się nad okruszki (patrz renderContentsInner), więc
      // ta kopia jest wtedy ukryta w CSS — bez duplikowania logiki.
      html += `<span class="crumb-sep place-sep">·</span>` + placeFoldersBtnHtml();
    }
    html += `</div>`;
    return html;
  }

  function bindBreadcrumb(container){
    // Tylko przyciski ŚCIEŻKI (mają data-idx). Przełącznik miejscowości jest
    // też .breadcrumb button, ale bez data-idx — bez tego warunku `+undefined`
    // dawałoby NaN i wciskałoby nas do katalogu głównego.
    container.querySelectorAll('.breadcrumb button[data-idx]').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.idx;
        state.path = idx === -1 ? [] : state.path.slice(0, idx+1);
        fetchFolder(true);
        pushGalleryHistory();
      });
    });
    // Przycisk miejscowości występuje w dwóch miejscach (okruszki na desktopie,
    // wiersz sterowania nad nimi na telefonie) — podpinamy oba, a CSS w każdym
    // medium ukrywa ten niewłaściwy.
    container.querySelectorAll('[data-place-folders]').forEach(b => {
      b.addEventListener('click', togglePlaceFolders);
    });
  }

  el('refreshBtn').addEventListener('click', () => fetchFolder(true));

  function setSyncStatus(text, busy){
    el('syncText').textContent = text;
    el('syncDot').className = 'sync-dot' + (busy ? ' busy' : '');
  }

  function renderEmpty(msg){
    galleryRoot.innerHTML = `
      <div class="empty">
        <h3>${state.rootId ? 'Ten folder jest pusty' : 'Brak połączenia z galerią'}</h3>
        <p>${msg || (state.rootId ? 'Wrzuć tu zdjęcia albo podfoldery do katalogu uploads/photos/ — pojawią się przy następnej synchronizacji.' : 'Sprawdź, czy na serwerze istnieje katalog uploads/photos/ i czy leżą w nim zdjęcia.')}</p>
      </div>`;
  }

  function renderSkeleton(){
    galleryRoot.innerHTML = renderBreadcrumb() + '<div class="grid">' +
      Array.from({length:8}).map(()=>'<div class="card skeleton"></div>').join('') +
      '</div>';
    bindBreadcrumb(galleryRoot);
  }

  // UWAGA: wołaj z jawnym rozmiarem dopasowanym do miejsca (kafelki/paski 320, karty 480, lightbox 1200+).
  // Domyślne 1000 to ostateczność — wcześniej pobierały je nawet miniatury 86×115 px.
  function bigThumb(url, size){
    if(!url) return url;
    const s = size || 1000;
    // lokalne miniatury: photo.php?id=...&s=480
    if(/[?&]s=\d+/.test(url)) return url.replace(/([?&]s=)\d+/, '$1' + s);
    return url.replace(/=s\d+(-c)?$/, '=s' + s);
  }
  function gridThumb(url){ return bigThumb(url, 480); }

  // Responsywne miniatury: przeglądarka sama wybiera najmniejszy wariant, który
  // wystarcza dla faktycznej szerokości kafelka i gęstości ekranu (Lighthouse:
  // „Ulepsz dostarczanie obrazów”). sizes="auto" działa w Chrome dla lazy-load,
  // reszta wartości to zapas dla pozostałych przeglądarek i obrazów eager.
  const THUMB_WIDTHS = [240, 320, 480, 640];
  function thumbAttrs(url, size, sizes){
    if(!url || !/[?&]s=\d+/.test(url)) return 'src="' + (url || '') + '"';
    const set = THUMB_WIDTHS.map(w => bigThumb(url, w) + ' ' + w + 'w').join(', ');
    return 'src="' + bigThumb(url, size) + '" srcset="' + set + '" sizes="' + sizes + '"';
  }
  function gridSizes(){
    if(gridDensity === 'compact') return 'auto, (max-width:640px) 33vw, 150px';
    if(gridDensity === 'comfortable') return 'auto, (max-width:640px) 100vw, 300px';
    return 'auto, (max-width:640px) 50vw, 240px';
  }

  // ListING katalogu pochodzi WYŁĄCZNIE z sieci (fetch poniżej, `cache:'no-store'`).
  // Wcześniej trzymaliśmy tu sessionStorage z TTL 180000 ms, przez co nowe,
  // usunięte i przeniesione zdjęcia były niewidoczne do 3 minut.
  // Sortowania NIE zapamiętujemy między wejściami na stronę: domyślnie zawsze
  // „najnowsze”, a wybór czytelnika działa tylko do odświeżenia strony.
  // Klucz z poprzedniej wersji kasujemy na starcie, żeby nie wisiał bezczynnie
  // w localStorage i nie wrócił do życia przy wycofaniu tej zmiany.
  let gallerySort = 'newest';
  let galleryQuery = '';
  // W katalogu gminy foldery to miejscowości. Domyślnie są UKRYTE i zostaje
  // sama siatka zdjęć — przycisk przy okruszkach od razu zaprasza do ich
  // rozwinięcia, podając liczbę. Stan zapamiętujemy (jak gęstość siatki,
  //  inaczej niż sortowanie): świadome rozwinięcie miejscowości ma zostać,
  //  bo ktoś o nie prosił. Porównujemy z '1', więc brak klucza = stan domyślny.
  let showPlaceFolders = false;
  let placeFoldersCount = 0;   // ile folderów jest w bieżącym katalogu
  let gridDensity = 'default'; // default | compact | comfortable
  // Gęstość walidujemy przy wczytywaniu: zapis spoza tej listy (np. po zmianie
  // nazwy w przyszłości) dawałby klasę, której CSS nie zna, i siatka wracałaby
  // do wartości domyślnej bez zaznaczonego przycisku. 'default' to środkowa
  // opcja, czyli domyślnie 2 zdjęcia w linii na telefonie.
  try{
    const savedDensity = localStorage.getItem('zmogilna-grid-density');
    gridDensity = ['default','compact','comfortable'].includes(savedDensity) ? savedDensity : 'default';
  }catch(_){ gridDensity = 'default'; }
  try{ localStorage.removeItem('zmogilna-gallery-sort'); }catch(_){}
  try{ showPlaceFolders = localStorage.getItem('zmogilna-place-folders') === '1'; }catch(_){}

  /* Przełącznik folderów miejscowości. Etykieta powstaje razem z okruszkami
     w renderBreadcrumb(), więc nie ma tu osobnego DOM-u do synchronizowania.
     Wybór zapamiętujemy (jak gęstość siatki, inaczej niż sortowanie) — to
     świadoma decyzja o tym, jak oglądać galerię, a nie ustawienie, które ma
     się cofać przy każdym wejściu na stronę. */
  function togglePlaceFolders(){
    showPlaceFolders = !showPlaceFolders;
    try{ localStorage.setItem('zmogilna-place-folders', showPlaceFolders ? '1' : '0'); }catch(_){}
    if(state.items && state.items.length) renderContents(state.items);
  }

  /* Przycisk miejscowości w HTML-u. Jedna funkcja dla dwóch miejsc: okruszków
     (desktop) i wiersza sterowania nad okruszkami (telefon) — dzięki temu
     etykieta z liczbą folderów nie może się rozjechać między kopiami. */
  function placeFoldersBtnHtml(){
      if(placeFoldersCount <= 0) return '';
      const cnt = ' (' + placeFoldersCount + ')';
      const isHome = state.path.length === 0;
      const label = (showPlaceFolders ? (isHome ? 'Ukryj gminy' : 'Ukryj miejscowości') : (isHome ? 'Pokaż gminy' : 'Pokaż miejscowości')) + cnt;
      const title = showPlaceFolders
        ? (isHome ? 'Schowaj listę gmin' : 'Schowaj foldery miejscowości i zostań sam ze zdjęciami')
        : (isHome ? 'Pokaż listę gmin' : 'Pokaż foldery miejscowości');
      return `<button type="button" class="place-folders-btn${showPlaceFolders ? '' : ' is-hidden-state'}"`
        + ` data-place-folders aria-pressed="${showPlaceFolders ? 'false' : 'true'}"`
        + ` title="${title}"><span class="pf-label">${label}</span></button>`;
    }

  function sortPhotos(photos){
    const arr = photos.slice();
    if(gallerySort === 'newest'){
      arr.sort((a,b) => String(b.createdTime||'').localeCompare(String(a.createdTime||'')));
    } else if(gallerySort === 'oldest'){
      arr.sort((a,b) => String(a.createdTime||'').localeCompare(String(b.createdTime||'')));
    } else {
      arr.sort((a,b) => String(a.name||'').localeCompare(String(b.name||''), 'pl'));
    }
    return arr;
  }
  function normalizeSearch(s){
    return String(s ?? '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '') // bez ogonków: ą→a, ł zostaje osobno
      .replace(/ł/g, 'l')
      .replace(/\.[a-z0-9]{2,5}$/i, '') // bez rozszerzenia .jpg etc.
      .trim();
  }
  function nameMatchesQuery(name, qRaw){
    if(!qRaw) return true;
    const q = qRaw.trim().toLowerCase();
    const qn = normalizeSearch(qRaw);
    const n = String(name || '');
    const nl = n.toLowerCase();
    const nn = normalizeSearch(n);
    return nl.includes(q) || nn.includes(qn) || nl.includes(qn) || nn.includes(q);
  }
  function filterByQuery(items){
    const q = galleryQuery.trim();
    if(!q) return items;
    return items.filter(i => nameMatchesQuery(i.name, q));
  }

  let searchRunId = 0;
  let globalSearchPhotos = null; // wyniki globalne albo null = tryb lokalny

  async function ensureRootFolders(){
    if(state.rootFolders && state.rootFolders.length) return state.rootFolders;
    if(!state.rootId || !state.apiKey) return [];
    try{
      const q = encodeURIComponent(`'${state.rootId}' in parents and trashed = false and mimeType = '${FOLDER_MIME}'`);
      const fields = encodeURIComponent('files(id,name)');
      const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=200`;
      const res = await fetch(url, { cache:'no-store' });
      const data = await res.json();
      state.rootFolders = data.files || [];
      return state.rootFolders;
    }catch(_){ return []; }
  }

  async function runGlobalPhotoSearch(query){
    const run = ++searchRunId;
    const q = (query || '').trim();
    if(!q){
      globalSearchPhotos = null;
      if(state.items) renderContents(state.items);
      return;
    }
    setSyncStatus('szukam w galerii…', true);
    const folders = await ensureRootFolders();
    // zdjęcia w katalogu głównym (jeśli są) — z aktualnego stanu w pamięci,
    // pobranego w tym cyklu synchronizacji (bez cache z sessionStorage)
    let rootPhotos = [];
    try{
      const rootItems = state.items || [];
      rootPhotos = rootItems.filter(i => i.mimeType !== FOLDER_MIME && nameMatchesQuery(i.name, q));
    }catch(_){}

    const results = rootPhotos.map(p => ({ ...p, _gmina: '' }));
    const seen = new Set(results.map(p => p.id));

    await Promise.all(folders.map(async f => {
      try{
        const photos = await walkFolderPhotos(f.id);
        for(const p of photos){
          if(seen.has(p.id)) continue;
          if(nameMatchesQuery(p.name, q)){
            seen.add(p.id);
            results.push({ ...p, _gmina: f.name });
          }
        }
      }catch(_){}
    }));

    if(run !== searchRunId) return; // nowsze wyszukiwanie w toku
    globalSearchPhotos = sortPhotos(results);
    renderGlobalSearchResults(q, globalSearchPhotos, folders.filter(f => nameMatchesQuery(f.name, q)));
    setSyncStatus('znaleziono · ' + results.length, false);
  }

  // UWAGA: bez startViewTransition. Przejście widoku nagrywa CAŁĄ stronę i
    // odtwarza ją przy każdym naciśnięciu klawisza — na telefonie bywało
    // widać „skakanie”, a pole wyszukiwania (powyżej siatki) przesuwało się
    // przy każdej aktualizacji wyników. Wyniki podmienia się od razu.
    function renderGlobalSearchResults(q, photos, matchedFolders){
      renderGlobalSearchResultsInner(q, photos, matchedFolders);
    }

  function renderGlobalSearchResultsInner(q, photos, matchedFolders){
    let html = renderBreadcrumb();
    html += `<p class="gmina-desc">Wyniki dla „${escapeHtml(q)}”: <b style="color:var(--ink)">${photos.length}</b> zdjęć` +
      (matchedFolders.length ? `, <b style="color:var(--ink)">${matchedFolders.length}</b> folderów` : '') + `</p>`;

    if(matchedFolders.length){
      html += '<div class="folder-grid">' + matchedFolders.map((f,i) =>
        `<div class="folder-card" data-fid="${i}" role="button" tabindex="0">${folderIcon()}<span>${escapeHtml(f.name)}</span></div>`
      ).join('') + '</div>';
    }

    if(photos.length){
      html += `<div class="${gridClass()}">` + photos.map((f,i) => `
        <div class="card" data-i="${i}" role="button" tabindex="0" aria-label="${escapeHtml(f.name)}">
          <img ${thumbAttrs(f.thumbnailLink, 480, gridSizes())} alt="${escapeHtml(f.name)}" loading="${i < 8 ? 'eager' : 'lazy'}" decoding="async">
          <div class="cap"><b>${escapeHtml(f.name.replace(/\.[^/.]+$/, ''))}</b>${f._gmina ? escapeHtml(f._gmina) + ' · ' : ''}${f.createdTime ? new Date(f.createdTime).toLocaleDateString('pl-PL') : ''}</div>
        </div>`).join('') + '</div>';
    } else if(!matchedFolders.length){
      html += `<div class="empty"><h3>Nic nie znaleziono</h3><p>Brak zdjęć ani folderów dla „${escapeHtml(q)}”. Spróbuj innego fragmentu nazwy.</p></div>`;
    }

    galleryRoot.innerHTML = html;
    bindBreadcrumb(galleryRoot);

    galleryRoot.querySelectorAll('.folder-card').forEach(card => {
      const open = () => {
        const f = matchedFolders[+card.dataset.fid];
        el('gallerySearch').value = '';
        galleryQuery = '';
        globalSearchPhotos = null;
        state.path = [{ id: f.id, name: f.name }];
        fetchFolder(true);
        pushGalleryHistory();
      };
      card.addEventListener('click', open);
      card.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
    galleryRoot.querySelectorAll('.card').forEach(card => {
      const open = () => openLightbox(photos, +card.dataset.i);
      card.addEventListener('click', open);
      card.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
    bindFavButtons(galleryRoot, id => photos.find(p => p.id === id));
    bindAuthorBadges(galleryRoot);
  }
  function gridClass(){
    if(gridDensity === 'compact') return 'grid compact';
    if(gridDensity === 'comfortable') return 'grid comfortable';
    return 'grid';
  }

  // wewnętrzny znacznik „to jest folder” (taki sam zwraca gallery.php)
  const FOLDER_MIME = 'application/vnd.google-apps.folder';

  function folderIcon(){
    return `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
      <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>
    </svg>`;
  }

  const folderCountCache = {}; // id -> number

  // Płynne przejście przy każdej zmianie zawartości galerii (sortowanie, szukanie,
  // gęstość, zmiana folderu) — jedna funkcja opakowująca, każde dotychczasowe
  // wywołanie renderContents(...) korzysta z niej automatycznie.
  function renderContents(items){
    // Podczas wpisywania w wyszukiwarce bez animacji: startViewTransition
    // nagrywa i odtwarza CALA strone przy kazdym nacisnieciu klawisza, co na
    // telefonie dawalo „skakanie” i przesuwalo pole wyszukiwania.
    const searchInput = el('gallerySearch');
    const whileTyping = !!searchInput
      && document.activeElement === searchInput
      && searchInput.value.trim() !== '';
    // Podczas gestu uszczypniecia tez bez animacji — gest konczy sie
    // przeskoczeniem poziomu, a startViewTransition nagrywalby i odtwarzal
    // cala strone dokladnie wtedy, gdy palce wlasnie puszczaja ekran.
    const pinching = !!(window.zmPinchActive && window.zmPinchActive());
    if(!whileTyping && !pinching && document.startViewTransition){
      document.startViewTransition(() => {
        renderContentsInner(items);
        syncDensityAfterRender();
      });
    }else{
      renderContentsInner(items);
      syncDensityAfterRender();
    }
  }

  // Przyciski gęstości wstawiane między foldery a zdjęcia tworzą się od nowa
  // przy każdym renderze, więc zaznaczenie aktywnego poziomu (klasa „active”)
  // trzeba nałożyć dopiero potem. Dzięki temu podświetlenie jest widoczne
  // także wtedy, gdy ustawienie jest domyślne i użytkownik nic nie zmieniał.
  function syncDensityAfterRender(){
    if(window.zmSyncDensityButtons) window.zmSyncDensityButtons();
  }

  function renderContentsInner(items){
    let folders = items.filter(i => i.mimeType === FOLDER_MIME);
    let photos = items.filter(i => i.mimeType !== FOLDER_MIME);
    folders = filterByQuery(folders);
    // Serwer zwraca widok gminy już jako pełne poddrzewo w kolejności daty
    // dodania, więc „najnowsze” (domyślne) daje na niej dokładnie ten sam
    // porządek. Osobny porządek serwera trzymamy tylko wtedy, gdy czytelnik
    // w podfolderze świadomie wybierze „Nazwa A–Z”.
    photos = filterByQuery(photos);
    if(gallerySort === 'name' && state.path.length > 0){
      photos.sort((a,b) => String(b.createdTime || '').localeCompare(String(a.createdTime || '')));
    }else{
      photos = sortPhotos(photos);
    }

    // W katalogu gminy foldery to miejscowości — przy ukrytych zostaje sama
    // siatka zdjęć. Foldery nie przestają istnieć: okruszki wracają w górę,
    // a przełącznik w pasku przywraca je jednym kliknięciem.
    placeFoldersCount = folders.length;
    // Folder-karty (gminy na stronie głównej, miejscowości w gminie) znikają, gdy
    // przełącznik jest wyłączony. Pełną listę zostawiamy w stripFolders: na
    // stronie głównej z niej powstają paski „ostatnio dodane” per gmina, które
    // pełnią rolę siatki zdjęć. Podczas szukania na głównej nic nie ukrywamy,
    // żeby wyszukiwarka nadal znajdowała gminy.
    const stripFolders = folders;
    const hideFolders = !showPlaceFolders && (state.path.length > 0 || !galleryQuery);
    const hiddenPlaces = hideFolders ? folders.length : 0;
    if(hiddenPlaces) folders = [];

    const homeStrips = state.path.length === 0 && stripFolders.length > 0 && !galleryQuery;
    if(!folders.length && !photos.length && !homeStrips){
      galleryRoot.innerHTML = renderBreadcrumb();
      bindBreadcrumb(galleryRoot);
      // Katalog zawierający wyłącznie miejscowości przy ukrytych folderach nie
      // jest pusty — mówimy wprost, co się dzieje, zamiast „ten folder jest pusty”.
      const emptyTitle = hiddenPlaces
        ? 'Tu są tylko foldery miejscowości'
        : (galleryQuery ? 'Nic nie znaleziono' : 'Ten folder jest pusty');
      const emptyMsg = hiddenPlaces
        ? `Ukryto ${hiddenPlaces} ${hiddenPlaces === 1 ? 'folder' : 'folderów'} z miejscowościami. Kliknij „Pokaż miejscowości”, żeby do nich wejść.`
        : (galleryQuery
          ? `Brak wyników dla „${escapeHtml(galleryQuery)}”. Wyczyść wyszukiwanie albo wybierz inną gminę.`
          : (state.path.length
            ? 'Wrzuć tu zdjęcia albo podfoldery do katalogu uploads/photos/ — pojawią się przy następnej synchronizacji.'
            : 'Sprawdź synchronizację albo otwórz inną galerię.'));
      const wrap = document.createElement('div');
      wrap.innerHTML = `<div class="empty"><h3>${emptyTitle}</h3><p>${emptyMsg}</p></div>`;
      galleryRoot.appendChild(wrap.firstElementChild);
      return;
    }

    // Telefon: jeden wiersz sterowania NAD okruszkami — trzy ikony gęstości
    // i przełącznik miejscowości w jednej linii, nad nazwą katalogu. Desktop
    // ma ikony gęstości w pasku narzędzi, a CSS wybiera właściwe miejsce
    // (.gallery-view-group-inline jest display:none poza media query).
    // Na stronie głównej ikony zmieniają rozmiar miniatur w paskach gmin.
    let mobileRow = '';
    if(state.path.length > 0 || placeFoldersCount > 0){
      const tpl = el('viewGroupTemplate');
      if(tpl && tpl.content){
        const inline = document.createElement('div');
        inline.className = 'gallery-view-group-inline';
        inline.setAttribute('role', 'group');
        inline.setAttribute('aria-label', 'Gęstość siatki i miejscowości');
        inline.appendChild(tpl.content.cloneNode(true));
        // Przycisk miejscowości doklejamy PRZED ikonami, żeby znalazł się po
        // lewej. Gdy gminy nie ma miejscowości, funkcja zwraca pusty tekst
        // i nic nie dokleja.
        inline.insertAdjacentHTML('afterbegin', placeFoldersBtnHtml());
        mobileRow = inline.outerHTML;
      }
    }

    let html = mobileRow + renderBreadcrumb();

    // opis gminy (gdy jesteśmy w folderze top-level)
    if(state.path.length === 1){
      const gname = state.path[0].name;
      const desc = (CONFIG.gminaDescriptions && CONFIG.gminaDescriptions[gname]) || '';
      if(desc) html += `<p class="gmina-desc">${escapeHtml(desc)}</p>`;
    }

    if(folders.length){
      html += '<div class="folder-grid">' + folders.map((f,i) => {
        const cnt = folderCountCache[f.id];
        const badge = (cnt != null) ? `<span class="folder-count">${cnt}</span>` : '';
        return `<div class="folder-card" data-fid="${i}" role="button" tabindex="0" aria-label="Folder ${escapeHtml(f.name)}">${folderIcon()}<span>${escapeHtml(f.name)}</span>${badge}</div>`;
      }).join('') + '</div>';
    }

    const showRecentByFolder = homeStrips;
    if(showRecentByFolder){
      html += '<div id="recentByFolder" class="recent-by-folder' + (gridDensity === 'default' ? '' : ' ' + gridDensity) + '"></div>';
      state.rootFolders = items.filter(i => i.mimeType === FOLDER_MIME);
    }

    if(photos.length){
      html += `<div class="${gridClass()}">` + photos.map((f,i) => `
        <div class="card" data-i="${i}" role="button" tabindex="0" aria-label="${escapeHtml(f.name)}">
          <img ${thumbAttrs(f.thumbnailLink, 480, gridSizes())} alt="${escapeHtml(f.name)}" loading="${i < 8 ? 'eager' : 'lazy'}" decoding="async">
          <div class="cap"><b>${escapeHtml(f.name.replace(/\.[^/.]+$/, ''))}</b>${f.createdTime ? new Date(f.createdTime).toLocaleDateString('pl-PL') : ''}</div>
        </div>`).join('') + '</div>';
    }

    galleryRoot.innerHTML = html;
    bindBreadcrumb(galleryRoot);
    bindAuthorBadges(galleryRoot);

    galleryRoot.querySelectorAll('.folder-card').forEach(card => {
      const open = () => {
        const f = folders[+card.dataset.fid];
        state.path.push({ id: f.id, name: f.name });
        fetchFolder(true);
        pushGalleryHistory();
      };
      card.addEventListener('click', open);
      card.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });

    galleryRoot.querySelectorAll('.card').forEach(card => {
      const open = () => openLightbox(photos, +card.dataset.i);
      card.addEventListener('click', open);
      card.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
    bindFavButtons(galleryRoot, id => photos.find(p => p.id === id));

    if(showRecentByFolder){
      renderRecentByFolder(stripFolders);
    }

    // Liczniki folderów zachowujemy, ale pobieramy je dopiero, gdy karta
    // folderu zbliży się do ekranu. Jedno zapytanie do gallery.php wystarcza
    // dla całego poddrzewa gminy i może być współdzielone z paskiem zdjęć.
    const folderCards = galleryRoot.querySelectorAll('.folder-card');
    if(folderCards.length && 'IntersectionObserver' in window){
      const countObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if(!entry.isIntersecting) return;
          const card = entry.target;
          countObserver.unobserve(card);
          const folder = folders[Number(card.dataset.fid)];
          if(!folder) return;
          previewFolderPhotos(folder.id, 10).then(({ total }) => {
            folderCountCache[folder.id] = total;
            if(!card.isConnected) return;
            let badge = card.querySelector('.folder-count');
            if(!badge){
              badge = document.createElement('span');
              badge.className = 'folder-count';
              card.appendChild(badge);
            }
            badge.textContent = String(total);
          }).catch(() => {});
        });
      }, { root:null, rootMargin:'300px 0px', threshold:0.01 });
      folderCards.forEach(card => countObserver.observe(card));
    }

  }

  // ---------- pobieranie i renderowanie ostatnich zdjęć per folder ----------
  // cache w obrębie jednego cyklu synchronizacji — jeśli kilka miejsc
  // potrzebuje pełnej listy zdjęć tego samego folderu, pobieramy ją raz.
  let folderWalkCache = {};
  function walkFolderPhotos(folderId){
    if(folderWalkCache[folderId]) return folderWalkCache[folderId];
    // gallery.php zwraca dla katalogu gminy jego widok z poddrzewa.
    // Jedno żądanie wystarcza — rekurencyjne odpytywanie każdego podfolderu
    // powodowało łańcuch dziesiątek żądań i wielokrotne pobieranie tych samych zdjęć.
    const promise = (async () => {
      // Tylko zdjęcia: wynik i tak odfiltrowuje foldery, a bez nich serwer nie buduje
      // zbędnych obiektów podkategorii (okładki, zapytania). Zwraca całe poddrzewo (zm_list).
      const q = encodeURIComponent(`'${folderId}' in parents and trashed = false and mimeType contains 'image/'`);
      const fields = encodeURIComponent('files(id,name,mimeType,thumbnailLink,createdTime,imageMediaMetadata)');
      const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=1000`;
      const res = await fetch(url, { cache:'no-store' });
      const data = await res.json();
      if(!res.ok) throw new Error(data.error && data.error.message ? data.error.message : 'Nie udało się pobrać zdjęć folderu.');
      const seen = new Set();
      const all = (Array.isArray(data.files) ? data.files : []).filter(item => {
        if(!item || item.mimeType === FOLDER_MIME || !item.id || seen.has(item.id)) return false;
        seen.add(item.id);
        return true;
      });
      all.sort((a,b) => new Date(b.createdTime || 0) - new Date(a.createdTime || 0));
      return all;
    })();
    folderWalkCache[folderId] = promise;
    promise.catch(() => { if(folderWalkCache[folderId] === promise) delete folderWalkCache[folderId]; });
    return promise;
  }

  // Lekki podgląd folderu na pierwszy widok: tylko `limit` najnowszych zdjęć poddrzewa (serwer sortuje
  // malejąco po dacie dodania) oraz łączna liczba zdjęć (`total`) do licznika. Pełną listę
  // (walkFolderPhotos) pobieramy dopiero na żądanie: wyszukiwarka, gra „Zgadnij gminę”.
  // Gdy gallery.php nie zwraca `total` (stara wersja), wracamy do pełnej listy — licznik nigdy nie kłamie.
  let folderPreviewCache = {};
  function previewFolderPhotos(folderId, limit){
    const key = folderId + '|' + limit;
    if(folderPreviewCache[key]) return folderPreviewCache[key];
    const promise = (async () => {
      const q = encodeURIComponent(`'${folderId}' in parents and trashed = false and mimeType contains 'image/'`);
      const fields = encodeURIComponent('files(id,name,mimeType,thumbnailLink,createdTime,imageMediaMetadata)');
      const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=${limit}`;
      const res = await fetch(url, { cache:'no-store' });
      const data = await res.json();
      if(!res.ok) throw new Error(data.error && data.error.message ? data.error.message : 'Nie udało się pobrać zdjęć folderu.');
      if(typeof data.total !== 'number'){
        const all = await walkFolderPhotos(folderId);
        return { files: all.slice(0, limit), total: all.length };
      }
      const seen = new Set();
      const files = (Array.isArray(data.files) ? data.files : []).filter(item => {
        if(!item || item.mimeType === FOLDER_MIME || !item.id || seen.has(item.id)) return false;
        seen.add(item.id);
        return true;
      });
      files.sort((a,b) => new Date(b.createdTime || 0) - new Date(a.createdTime || 0));
      return { files, total: data.total };
    })();
    folderPreviewCache[key] = promise;
    promise.catch(() => { if(folderPreviewCache[key] === promise) delete folderPreviewCache[key]; });
    return promise;
  }

  async function fetchRecentPhotosInFolder(folderId, limit){
    const all = await walkFolderPhotos(folderId);
    return all.slice(0, limit);
  }

  // Nie skanujemy całego drzewa zdjęć tylko po to, aby sortować gminy.
  // Zachowujemy kolejność zwróconą przez serwer; liczniki nie blokują LCP.
  async function sortFoldersByPhotoCount(folders){
    return folders;
  }

  async function renderRecentByFolder(folders){
    const container = el('recentByFolder');
    if(!container) return;

    container.innerHTML = folders.map(f => `
      <div class="recent-folder-row" data-fid="${escapeHtml(f.id)}">
        <div class="recent-folder-name" data-fid="${escapeHtml(f.id)}">${escapeHtml(f.name)}</div>
        <div class="recent-folder-strip" id="strip-${escapeHtml(f.id)}">
          ${Array.from({length:4}).map(()=>'<div class="strip-thumb skeleton"></div>').join('')}
        </div>
      </div>
    `).join('');

    container.querySelectorAll('.recent-folder-name').forEach(nameEl => {
      nameEl.addEventListener('click', () => {
        const folder = folders.find(f => f.id === nameEl.dataset.fid);
        if(folder){
          state.path.push({ id: folder.id, name: folder.name });
          fetchFolder(true);
          pushGalleryHistory();
        }
      });
    });

    const loaded = new Set();
    async function loadStrip(f){
      if(loaded.has(f.id)) return;
      loaded.add(f.id);
      try{
        // Pełne poddrzewo pobieramy dopiero dla paska bliskiego widokowi.
        const { files: recent } = await previewFolderPhotos(f.id, 10);
        const stripEl = el(`strip-${f.id}`);
        if(!stripEl) return;
        if(!recent.length){
          const rowEl = Array.from(container.querySelectorAll('.recent-folder-row'))
            .find(row => row.dataset.fid === f.id);
          if(rowEl) rowEl.remove();
          return;
        }
        stripEl.innerHTML = recent.map((p,i) => `
          <div class="strip-thumb" data-idx="${i}" title="${escapeHtml(p.name || '')}">
            <img ${thumbAttrs(p.thumbnailLink, 320, 'auto, 180px')} alt="${escapeHtml(p.name || '')}" loading="lazy" decoding="async">
          </div>
        `).join('');
        stripEl.querySelectorAll('.strip-thumb').forEach(t => {
          t.addEventListener('click', () => openLightbox(recent, +t.dataset.idx));
        });
        bindFavButtons(stripEl, id => recent.find(p => p.id === id));
        bindAuthorBadges(stripEl);
      }catch(_){
        const stripEl = el(`strip-${f.id}`);
        if(stripEl) stripEl.innerHTML = `<div class="strip-empty">błąd ładowania zdjęć</div>`;
      }
    }

    if('IntersectionObserver' in window){
      const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if(!entry.isIntersecting) return;
          const row = entry.target;
          observer.unobserve(row);
          const folder = folders.find(f => f.id === row.dataset.fid);
          if(folder) loadStrip(folder);
        });
      }, { root:null, rootMargin:'500px 0px', threshold:0.01 });
      container.querySelectorAll('.recent-folder-row').forEach(row => observer.observe(row));
    }else{
      folders.slice(0,2).forEach(loadStrip);
    }
  }

  // ---------- pinch-to-zoom on photo grids (grid tile size / strip thumb size) ----------
  (function(){
    // Gest na .grid NIE ustawia dowolnego rozmiaru — przeskakuje miedzy
    // trzema poziomami gestosci (compact / default / comfortable), czyli
    // dokladnie tymi samymi, co trzy przyciski obok wyszukiwarki. Gest na
    // pasku miniaturek folderow zostaje plynny, bo nie ma dla niego
    // odpowiednika w przyciskach.
    const ZOOM_TARGETS = [
      // max to TYLKO sufit na desktopie — w gestach limit i tak domyka
      // szerokosc kontenera (pz.maxSize), wiec ta liczba nie musi byc duza.
      // min musi byc najmniejsze z trzech poziomow przyciskow (compact 92px na
      // telefonie), zeby gest mogl zawsze dojsc do najgęstszej siatki.
      { selector:'.grid', varName:'--grid-min', min:92, max:400, fallback:230, storageKey:'zmogilna-grid-min', snap:true },
      { selector:'.recent-folder-strip', varName:'--strip-size', min:56, max:180, fallback:88, storageKey:'zmogilna-strip-size' }
    ];

    // Ustawiamy zmienna na SAMYM elemencie (.grid / .recent-folder-strip),
    // a nie na <html>. Wczesniej szla na <html>, gdzie wartosc inline ma
    // pierwszenstwo przed klasami .grid.compact / .grid.comfortable — zapis
    // z pinch zabilal przyciski gestosci obok wyszukiwarki (klikniecie nic
    // nie robilo). Na samym .grid tez inline wygrywa, ale tylko gdy pinch
    // faktycznie cos ustawil (clearPinchOverride kasuje to przyciskiem).
    function clearPinchOverride(){
      ZOOM_TARGETS.forEach(t => {
        document.querySelectorAll(t.selector).forEach(n => n.style.removeProperty(t.varName));
      });
      // kasujemy rowniez zapis, by po odswiezeniu nie wracal
      ZOOM_TARGETS.forEach(t => {
        try{ localStorage.removeItem(t.storageKey); }catch(_){}
      });
    }
    window.zmClearPinchOverride = clearPinchOverride;
    // Czy trwa gest — renderContents pomija wtedy animacje przejscia.
    // Flaga zeruje sie dopiero po renderze wywolanym przez setDensity
    // (kolejnosc w endPinch: setDensity -> czyszczenie flagi).
    window.zmPinchActive = () => pz.active;

    // Przywracamy zapis tylko dla celow PŁYNNYCH (pasek miniaturek folderow).
    // Dla .grid zapis 'zmogilna-grid-min' jest nieaktualny: gest uszczypniecia
    // przeskakuje między trzema poziomami przyciskow, a nie ustawia dowolnej
    // szerokosci. Gdybyśmy go przywracali, stara wartość (np. 440px) wstawiona
    // inline nadpisałaby klasy .compact/.comfortable i znowu blokowała gest.
    try{
      ZOOM_TARGETS.forEach(t => {
        if(t.snap) return;
        const saved = localStorage.getItem(t.storageKey);
        if(saved){
          const first = document.querySelector(t.selector);
          if(first) first.style.setProperty(t.varName, saved);
        }
      });
      // Jednorazowo sprzątamy nieaktualny zapis gestu z siatki.
      try{ localStorage.removeItem('zmogilna-grid-min'); }catch(_){}
    }catch(_){}

    const pz = { active:false, target:null, el:null, startDist:0, startSize:0, maxSize:0,
               snapIndex:-1, snapMoved:false, snapDir:0 };

    function dist(a,b){ return Math.hypot(b.clientX-a.clientX, b.clientY-a.clientY); }

    function matchTarget(touch){
      const node = document.elementFromPoint(touch.clientX, touch.clientY);
      if(!node) return null;
      for(const t of ZOOM_TARGETS){
        const target = node.closest(t.selector);
        if(target) return { def:t, el: target };
      }
      return null;
    }

    function readSize(def, el){
      const raw = getComputedStyle(el).getPropertyValue(def.varName).trim();
      const n = parseFloat(raw);
      return isNaN(n) ? def.fallback : n;
    }

    document.addEventListener('touchstart', e => {
      if(e.touches.length !== 2) return;
      const m = matchTarget(e.touches[0]);
      if(!m) return;
      pz.active = true;
      pz.target = m.def;
      pz.el = m.el;
      pz.startDist = dist(e.touches[0], e.touches[1]);
      pz.startSize = readSize(m.def, m.el);
      // Gorny limit liczymy z SZEROKOSCI KONTENERA, nie ze stałej 440px.
      // Grid to repeat(auto-fill, minmax(--grid-min, 1fr)) — przy --grid-min
      // wiekszym niz szerokosc siatki kolumna wychodzi poza ekran i zdjecie
      // ucina sie z prawej. Limit = szerokosc kontenera = najwiekszy kafelek
      // jaki miesci sie w jednej kolumnie.
      pz.maxSize = Math.max(m.def.min, Math.min(m.def.max, m.el.clientWidth || m.def.max));
      // Gest z gestosci: zapamietujemy z ktorego poziomu startujemy i
      // w ktora strone palce sie rozjechaly, zeby po puszczeniu wybrac
      // sasiadujacy poziom (kompaktowy / domyslny / przestronny).
      if(m.def.snap){
        pz.snapIndex = densityIndexOf(pz.startSize);
        pz.snapMoved = false;
      }
    }, { passive:true });

    // Trzy poziomy gestosci w kolejnosci od najmniejszych kafelkow.
    // Trzymamy je w jednym miejscu z klasami .compact / .comfortable
    // w CSS, zeby gest i przyciski nie rozjezdzaly sie z czasem.
    function densityPresets(){
      return ['compact', 'default', 'comfortable'];
    }

    // Na ktorym poziomie jestmy teraz? Czesciowo przez rozmiar (gest
    // mogl zostac w trakcie), czesciowo przez zapisany stan przyciskow.
    function densityIndexOf(size){
      const cur = (window.zmCurrentDensity && window.zmCurrentDensity()) || 'default';
      const presets = densityPresets();
      const idx = presets.indexOf(cur);
      if(idx >= 0 && Math.abs(size - densitySizeOf(cur)) < 25) return idx;
      // rozmiar nie pasuje do zapisu (np. gest w trakcie) — najblizszy
      let best = 0, bestDiff = Infinity;
      presets.forEach((p, i) => {
        const diff = Math.abs(size - densitySizeOf(p));
        if(diff < bestDiff){ bestDiff = diff; best = i; }
      });
      return best;
    }

    // Rzeczywisty --grid-min dla klasy, liczony z CSS (mobile ma mniejsze).
    function densitySizeOf(name){
      const probe = document.createElement('div');
      probe.className = 'grid' + (name === 'compact' ? ' compact' : name === 'comfortable' ? ' comfortable' : '');
      probe.style.cssText = 'position:absolute;left:-9999px;top:0;width:10px;height:10px;pointer-events:none;';
      document.body.appendChild(probe);
      const raw = getComputedStyle(probe).getPropertyValue('--grid-min').trim();
      const n = parseFloat(raw);
      probe.remove();
      return isNaN(n) ? 230 : n;
    }

    document.addEventListener('touchmove', e => {
      if(!pz.active || e.touches.length !== 2) return;
      e.preventDefault();
      const d = dist(e.touches[0], e.touches[1]);
      const ratio = d / pz.startDist;
      const hi = pz.maxSize || pz.target.max;
      const newSize = Math.min(hi, Math.max(pz.target.min, Math.round(pz.startSize * ratio)));
      pz.el.style.setProperty(pz.target.varName, newSize + 'px');
      // Kierunek: palce rozjechaly sie = chcemy wieksze kafelki (wyzej
      // w liscie poziomow), palce sie zblizyly = mniejsze (nizej).
      if(pz.target.snap){
        if(Math.abs(d - pz.startDist) > 24) pz.snapMoved = true;
        const bigger = ratio > 1;
        pz.snapDir = (Math.abs(d - pz.startDist) > 24) ? (bigger ? 1 : -1) : pz.snapDir;
      }
    }, { passive:false });

    function endPinch(e){
      if(!pz.active) return;
      if(e.touches.length < 2){
        // Gest z gestosci: puszczenie palcow przeskakuje o jeden poziom,
        // tak samo jak klikniecie sasiadniego przycisku. Gest mniej niz
        // 24px traktujemy jako przypadek (akcentacja) i nic nie zmieniamy.
        if(pz.target.snap && pz.snapMoved && pz.snapDir){
          const presets = densityPresets();
          const next = Math.min(presets.length - 1, Math.max(0, pz.snapIndex + pz.snapDir));
          if(next !== pz.snapIndex && window.zmSetDensity){
            window.zmSetDensity(presets[next]);
          }else{
            // juz na skrajnym poziomie — wracamy do zapisu z przyciskow
            clearPinchOverride();
          }
        }else if(pz.target.snap){
          clearPinchOverride();
        }else{
          // pasek miniaturek folderow — gest plynnie ustawia rozmiar
          try{
            const val = getComputedStyle(pz.el).getPropertyValue(pz.target.varName).trim();
            localStorage.setItem(pz.target.storageKey, val);
          }catch(_){}
        }
        pz.active = false; pz.target = null; pz.el = null;
        pz.snapIndex = -1; pz.snapMoved = false; pz.snapDir = 0;
      }
    }
    document.addEventListener('touchend', endPinch, { passive:true });
    document.addEventListener('touchcancel', endPinch, { passive:true });
  })();

  // ---------- lightbox with prev/next navigation ----------
  let lightboxPhotos = [];
  let lightboxIndex = 0;
  let profileReturnId = null;
  // Adres powrotny z ?return= — np. z atrakcje.php po zamknięciu zdjęcia
  // wracamy na stronę atrakcji (z zachowanym filtrem), a nie na główną.
  // Walidowany: tylko własny host + atrakcje.php (ochrona przed open redirect).
  let lightboxReturnUrl = null;

  function formatExposureTime(sec){
    if(!sec) return null;
    if(sec >= 1) return sec.toFixed(sec >= 10 ? 0 : 1) + 's';
    const denom = Math.round(1 / sec);
    return `1/${denom}s`;
  }

  let exifOpen = false;

  const EDIT_LABELS = {
    original: 'Oryginał — bez śladów edycji',
    suspect:  'Możliwa edycja',
    edited:   'Zdjęcie edytowane',
    unknown:  'Nie da się ustalić, czy edytowane'
  };
  // Okno „i”: dane aparatu + informacja, czy zdjęcie było modyfikowane.
  function buildExifHtml(meta, edit){
    const sections = [];
    if(meta){
      const camera = [meta.cameraMake, meta.cameraModel].filter(Boolean).join(' ');
      const shot = [];
      if(meta.focalLength) shot.push(`${Math.round(meta.focalLength)}mm`);
      if(meta.aperture) shot.push(`f/${meta.aperture}`);
      const et = formatExposureTime(meta.exposureTime);
      if(et) shot.push(et);
      if(meta.isoSpeed) shot.push(`ISO ${meta.isoSpeed}`);
      const rows = [];
      if(camera) rows.push(`<div class="exif-row exif-camera"><span>${escapeHtml(camera)}</span></div>`);
      if(meta.lens) rows.push(`<div class="exif-row"><span>${escapeHtml(meta.lens)}</span></div>`);
      if(shot.length) rows.push(`<div class="exif-row">${shot.map(p => `<span>${escapeHtml(p)}</span>`).join('')}</div>`);
      const extra = [];
      if(meta.takenExact) extra.push(`<span>wykonano ${escapeHtml(meta.takenExact)}</span>`);
      if(meta.width && meta.height) extra.push(`<span>${meta.width}×${meta.height}</span>`);
      if(extra.length) rows.push(`<div class="exif-row">${extra.join('')}</div>`);
      if(rows.length) sections.push(`<div class="exif-h">Zdjęcie</div>${rows.join('')}`);
    }
    if(edit && EDIT_LABELS[edit.status]){
      sections.push(`
        <div class="exif-h">Modyfikacje</div>
        <div class="exif-edit st-${escapeHtml(edit.status)}"><i class="exif-dot"></i><b>${EDIT_LABELS[edit.status]}</b></div>
        ${edit.note ? `<div class="exif-note">${escapeHtml(edit.note)}</div>` : ''}
        <div class="exif-note">${edit.manual ? 'Status ustawiony ręcznie przez administratora.' : 'Ocena automatyczna na podstawie metadanych pliku — nie jest dowodem.'}</div>
      `);
    }
    return sections.join('');
  }

  function closeMapPanel(){
    const panel = el('lightboxMapPanel');
    const btn = el('lightboxMapBtn');
    if(panel) panel.classList.remove('open');
    if(btn) btn.classList.remove('active');
    const frame = el('lightboxMapFrame');
    if(frame) frame.removeAttribute('src');
    // gasimy link do Google, żeby przy następnym otwarciu nie wisiał stary URL
    const googleLink = el('lightboxMapGoogle');
    if(googleLink){
      googleLink.removeAttribute('href');
      googleLink.style.display = 'none';
    }
  }

  function openMapPanel(mapUrl, coords){
   if(!mapUrl) return;

   const panel = el('lightboxMapPanel');
   const frame = el('lightboxMapFrame');
   const external = el('lightboxMapExternal');
   const googleLink = el('lightboxMapGoogle');
   const btn = el('lightboxMapBtn');

   if(!panel || !frame || !external) return;

   // Link do Google budujemy WYŁĄCZNIE z zapisanych współrzędnych (config.php
   // wysyła je jako f.coordinates). Nie zgadujemy z URL-a mapy ani nie używamy
   // Google API — to zwykły odnośnik do miejsca.
   if(googleLink){
     const lat = coords ? Number(coords.lat) : NaN;
     const lng = coords ? Number(coords.lng) : NaN;
     if(isFinite(lat) && isFinite(lng) && !(lat === 0 && lng === 0)){
       googleLink.href = 'https://www.google.com/maps/search/?api=1&query='
         + encodeURIComponent(`${lat.toFixed(7)},${lng.toFixed(7)}`);
       googleLink.style.display = '';
     } else {
       googleLink.removeAttribute('href');
       googleLink.style.display = 'none';
     }
   }

   const isGoogleEmbed =
    /^https:\/\/(?:www\.)?google\.com\/maps\/embed(?:[/?#]|$)/i.test(mapUrl);

   const isOpenStreetMapEmbed =
    /^https:\/\/(?:www\.)?openstreetmap\.org\/export\/embed\.html(?:[/?#]|$)/i.test(mapUrl);

   const isAllowedMap =
    isGoogleEmbed || isOpenStreetMapEmbed;

   if(!isAllowedMap){
     frame.removeAttribute('src');
     frame.style.display = 'none';
     return;
   }

   frame.src = mapUrl;
   frame.style.display = 'block';

   // Link „Otwórz w OpenStreetMap” ma prowadzić do zwykłej strony mapy, a nie
   // do osadzonego widżetu — gdy znamy współrzędne, zbuduj normalny adres OSM.
   let externalHref = mapUrl;
   if(isOpenStreetMapEmbed){
     const lat = coords ? Number(coords.lat) : NaN;
     const lng = coords ? Number(coords.lng) : NaN;
     if(isFinite(lat) && isFinite(lng) && !(lat === 0 && lng === 0)){
       const la = lat.toFixed(7), lo = lng.toFixed(7);
       externalHref = `https://www.openstreetmap.org/?mlat=${la}&mlon=${lo}#map=15/${la}/${lo}`;
     }
   }
   external.href = externalHref;
   external.textContent = isOpenStreetMapEmbed
    ? 'Otwórz w OpenStreetMap ↗'
    : 'Otwórz w Google Maps ↗';

   panel.classList.add('open');

   if(btn){
     btn.style.display = 'flex';
     btn.classList.add('active');
   }
 }

  function showLightboxAt(i){
    if(!lightboxPhotos.length) return;
    lightboxIndex = (i + lightboxPhotos.length) % lightboxPhotos.length;
    const f = lightboxPhotos[lightboxIndex];
    const lbImg = el('lightboxImg');
    // od razu pokazujemy miniaturę z siatki (jest już w cache przeglądarki),
    // a duży plik podmieniamy, gdy się wczyta
    lbImg.style.display = '';
    {
      // miniaturę z siatki podmieniamy na dużą, gdy się wczyta
      const lbSize = window.innerWidth <= 800 ? 1200 : 1600;
      lbImg.src = gridThumb(f.thumbnailLink);
      const bigImg = new Image();
      bigImg.onload = () => {
        const swap = () => { if(lightboxPhotos[lightboxIndex] === f) lbImg.src = bigImg.src; };
        if(bigImg.decode){ bigImg.decode().then(swap, swap); } else { swap(); }
      };
      bigImg.src = bigThumb(f.thumbnailLink, lbSize);
      // podgrzej sąsiednie zdjęcia
      [1, -1].forEach(d => {
        const n = lightboxPhotos[(lightboxIndex + d + lightboxPhotos.length) % lightboxPhotos.length];
        if(n && n !== f){ new Image().src = bigThumb(n.thumbnailLink, lbSize); }
      });
    }
    const lbAuthor = el('lightboxAuthor');
    if(lbAuthor){
      const badgeHtml = authorBadgeHtml(f, 'lb-author-badge');
      lbAuthor.innerHTML = badgeHtml;
      lbAuthor.classList.toggle('has-author', !!badgeHtml);
      if(badgeHtml) bindAuthorBadges(lbAuthor);
    }
    const lbDesc = el('lightboxDesc');
    if(lbDesc){
      const desc = String(f.description || '').trim();
      lbDesc.textContent = desc;
      lbDesc.classList.toggle('open', desc !== '');
      lbDesc.setAttribute('aria-hidden', desc === '' ? 'true' : 'false');
      if(desc) requestAnimationFrame(syncLightboxDesc);
    }
    el('lightboxCap').textContent = f.name + (f.createdTime ? ' · ' + new Date(f.createdTime).toLocaleDateString('pl-PL') : '') + `  (${lightboxIndex+1}/${lightboxPhotos.length})`;
    // aktualizuj ?photo= przy przewijaniu w lightboxie
    if(el('lightbox').classList.contains('open') && f && f.id){
      try{
        history.replaceState(
  {
    type:'lightbox',
    path: state.path.map(p => ({id:p.id, name:p.name})),
    photoId: f.id
  },
  '',
  galleryPhotoUrl(f.id)
);
      }catch(_){}
    }
    const exifHtml = buildExifHtml(f.imageMediaMetadata, f.edit);
    el('lightboxExifBody').innerHTML = exifHtml;
    const infoBtn = el('lightboxInfoBtn');
    // Przycisk „i" działa zawsze: w panelu są akcje zdjęcia (mapa, udostępnij,
    // zgłoś, ulubione), więc panel otwiera się także wtedy, gdy brak metadanych.
    infoBtn.style.display = 'flex';
    el('lightboxExif').classList.toggle('open', exifOpen);
    infoBtn.classList.toggle('active', exifOpen);
    const mapBtn = el('lightboxMapBtn');
    if(mapBtn){
      mapBtn.style.display = f.mapUrl ? 'flex' : 'none';
      mapBtn.classList.remove('active');
    }
    closeMapPanel();
    el('lightboxPrev').disabled = lightboxPhotos.length < 2;
    el('lightboxNext').disabled = lightboxPhotos.length < 2;
    const favBtn = el('lightboxFavBtn');
    if(favBtn){
      favBtn.dataset.favId = f.id;
      syncFavHearts(f.id);
    }
    if(window.zmUpdatePhotoLike) window.zmUpdatePhotoLike(f.id);
    updateCommentsUI(f.id);
    requestAnimationFrame(syncCommentsPanelToPhoto);
  }

  el('lightboxFavBtn').addEventListener('click', () => {
    if(!lightboxPhotos.length) return;
    const f = lightboxPhotos[lightboxIndex];
    toggleFavorite(f);   // stan serc synchronizuje toggleFavorite
  });

  // ---------- panel komentarzy w lightboxie ----------
  let commentsOpen = false;

  function scrollCommentsToLatest(){
    const panel = el('lightboxCommentsPanel');
    if(!panel) return;
    // API zwraca komentarze rosnąco: najstarszy -> najnowszy.
    // Startujemy na dole, żeby od razu było widać ostatni wpis i formularz.
    requestAnimationFrame(() => {
      panel.scrollTop = panel.scrollHeight;
    });
  }

  // Podświetla i przewija do konkretnego komentarza (klik z kafelka
  // „Ostatnie komentarze” na stronie głównej).
  function focusCommentInPanel(commentId){
    const panel = el('lightboxCommentsPanel');
    if(!panel) return;
    requestAnimationFrame(() => {
      const target = panel.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
      if(!target){
        scrollCommentsToLatest();
        return;
      }
      panel.querySelectorAll('.comment-item.comment-target').forEach(n => n.classList.remove('comment-target'));
      try{ target.scrollIntoView({ block:'center', behavior:'smooth' }); }
      catch(_){ target.scrollIntoView(); }
      // restart animacji, gdyby ten sam komentarz był otwierany ponownie
      void target.offsetWidth;
      target.classList.add('comment-target');
    });
  }

  let scrollAfterRender = null;   // { id, until } — po dodaniu odpowiedzi przewiń do niej

  // Szkic komentarza osobno dla każdego zdjęcia. Bez tego przejście na inne
  // zdjęcie kasowałoby tekst, który ktoś zdążył już zacząć pisać; limit
  // chroni przed rozrostem mapy przy długiej sesji.
  /* ---- zgłaszanie komentarza administratorowi --------------------------
     Powody zgłoszenia biorą się z serwera, a nie z listy zaszytej w JS:
     jedno źródło prawdy z walidacją, więc podpisy w formularzu na pewno
     odpowiadają temu, co serwer przyjmie. Pobranie jest leniwe — dopiero
     przy pierwszym otwarciu okna. */
  let reportReasons = null;
  function loadReportReasons(){
    if(reportReasons) return Promise.resolve(reportReasons);
    return fetch('comments.php?action=report_reasons', { credentials:'same-origin' })
      .then(r => r.json())
      .then(d => (d && d.reasons && typeof d.reasons === 'object') ? (reportReasons = d.reasons) : {})
      .catch(() => ({}));
  }

  /* Świeży token CSRF na wypadek 403 przy pierwszym zgłoszeniu.
     Token jest pobierany raz przy wejściu na stronę, a serwer wymienia go przy
     logowaniu i przy wygasnięciu sesji. Długo otwarta karta albo zalogowanie
     w drugim oknie zostawiają więc stronę ze starszym tokenem — zgłoszenie
     wyrzuca wtedy 403, choć wszystko jest w porządku. Jeden świeży token
     i ponowienie wystarczą; bez pętli, żeby nie zapętlić przy realnym problemie
     z sesją. */
  let csrfRefresh = null;
  function refreshCsrfToken(){
    if(!csrfRefresh){
      csrfRefresh = fetch('auth-api.php?action=whoami', { credentials:'include' })
        .then(r => r.json())
        .then(d => {
          if(d && d.csrf){
            csrfToken = d.csrf;
            lastWho = d;
          }
          csrfRefresh = null;
          return csrfToken;
        })
        .catch(() => { csrfRefresh = null; return csrfToken; });
    }
    return csrfRefresh;
  }

  async function reportComment(commentId, reason, note){
    const send = token => fetch('comments.php', {
      method:'POST', credentials:'same-origin',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({
        action:'report', comment_id: commentId, reason, note, csrf: token
      })
    });

    let res = await send(csrfToken);
    // Dla tej akcji 403 znaczy tylko „zły token” (konto zbanowane dostaje 403
    // przy logowaniu, nie tutaj), więc świeżym tokenem próbujemy ponownie.
    if(res.status === 403){
      await refreshCsrfToken();
      res = await send(csrfToken);
    }

    const d = await res.json().catch(() => ({}));
    if(!res.ok || d.error){
      if(res.status === 403){
        throw new Error('Sesja wygasła — odśwież stronę i zgłoś jeszcze raz.');
      }
      throw new Error(d.message || ('Nie udało się zgłosić komentarza (status ' + res.status + ').'));
    }
    return d;
  }

  const commentDrafts = new Map();
  async function renderCommentsPanelContent(photoId, preFetched, focusCommentId){
    const panel = el('lightboxCommentsPanel');
    if(!panel) return;
    panel.dataset.photoId = photoId;

    // szkielet z nagłówkiem i widocznym przyciskiem zamknięcia — renderowany
    // od razu, żeby zawsze dało się zamknąć panel nawet gdy lista jeszcze się ładuje
    panel.innerHTML = `
      <div class="comments-panel-head">
        <div class="comments-title">Komentarze</div>
        <button type="button" class="comments-panel-close" id="commentsPanelClose" aria-label="Zamknij komentarze">×</button>
      </div>
      <div class="comments-body" id="commentsPanelBody"><div class="comments-loading">Wczytywanie…</div></div>
    `;
    const closeBtn = el('commentsPanelClose');
    if(closeBtn) closeBtn.addEventListener('click', () => toggleCommentsPanel(false));
    if(window.visualViewport) applyCommentsPanelKeyboardOffset();

    const comments = preFetched || await getCommentsFor(photoId);
    // zdjęcie mogło się zmienić zanim odpowiedź wróciła — nie nadpisuj wtedy panelu
    if(panel.dataset.photoId !== photoId) return;

    const tokens = loadDeleteTokens();
    panel.classList.toggle('is-empty', comments.length === 0);
    requestAnimationFrame(syncCommentsPanelToPhoto);

    // wątki: komentarz główny + odpowiedzi pod nim (jeden poziom zagnieżdżenia)
    const byId = new Map(comments.map(c => [c.id, c]));
    const roots = [], repliesOf = new Map();
    comments.forEach(c => {
      if(c.parent_id && byId.has(c.parent_id)){
        if(!repliesOf.has(c.parent_id)) repliesOf.set(c.parent_id, []);
        repliesOf.get(c.parent_id).push(c);
      } else roots.push(c);
    });
    // nazwa autora z konta jest linkiem do jego profilu; przy prywatnym
    // profilu backend nie zwraca author_username, więc zostaje zwykłym tekstem
    const commentAuthorHtml = c => c.author_username
      ? `<a class="comment-author is-account" href="${profileUrl(c.author_username, c.user_id)}">${escapeHtml(c.author || 'Anonim')}</a>`
      : `<span class="comment-author">${escapeHtml(c.author || 'Anonim')}</span>`;
    // Czy widz ma prawo moderacji? whoami zwraca rolę, więc decyzja jest
    // jednozdaniowa. To tylko wygoda UI — comments.php i tak sprawdza rolę
    // w requireAdmin(), niezależnie od tego, co tu wyjdzie.
    const viewerIsAdmin = !!(lastWho && lastWho.user && lastWho.role === 'admin');
    // Zgłosić może każdy poza właścicielem komentarza (ten ma „usuń”) i
    // administratorem (ten ma od razu edycję oraz usunięcie).
    const canReport = c => !tokens[c.id] && !viewerIsAdmin;
    const commentHtml = c => `
          <div class="comment-item" data-comment-id="${c.id}">
            <div class="comment-item-head">
              ${commentAuthorHtml(c)}
              <span class="comment-date">${formatCommentDate(c.ts)}</span>
            </div>
            <div class="comment-text">${escapeHtml(c.text)}</div>
            ${c.edited_at ? `<div class="comment-edited">Edytowane przez ${escapeHtml(c.edited_by || 'administratora')}${c.edit_reason ? ` — ${escapeHtml(c.edit_reason)}` : ''}</div>` : ''}
            <div class="comment-actions">
              <button type="button" class="comment-like${c.liked ? ' liked' : ''}" data-like-id="${c.id}" aria-pressed="${c.liked ? 'true' : 'false'}" aria-label="Lubię to">${HEART_SVG}<span class="comment-like-count">${c.likes > 0 ? c.likes : ''}</span></button>
              <button type="button" class="comment-reply-btn" data-reply-id="${c.id}">odpowiedz</button>
              ${(tokens[c.id] && !viewerIsAdmin) ? `<button type="button" class="comment-del" data-del-id="${c.id}">usuń</button>` : ''}
              ${canReport(c) ? `<button type="button" class="comment-report-btn" data-report-id="${c.id}">zgłoś</button>` : ''}
              ${viewerIsAdmin ? `<button type="button" class="comment-admin-btn" data-admin-edit="${c.id}">edytuj</button>
              <button type="button" class="comment-admin-btn danger" data-admin-del="${c.id}">usuń</button>` : ''}
            </div>
            ${canReport(c) ? `<div class="comment-report-pop" data-report-pop="${c.id}" hidden></div>` : ''}
            ${viewerIsAdmin ? `<div class="comment-edit-pop" data-edit-pop="${c.id}" hidden>
              <textarea class="comment-edit-text" maxlength="500" aria-label="Treść komentarza"></textarea>
              <div class="comment-edit-reason-box">
                <button type="button" class="comment-edit-reason-toggle">＋ powód edycji</button>
                <input type="text" class="comment-edit-reason" maxlength="255" placeholder="np. usunięto link do firmy" hidden>
              </div>
              <div class="comment-edit-actions">
                <button type="button" class="comment-edit-save save">zapisz</button>
                <button type="button" class="comment-edit-cancel">anuluj</button>
              </div>
            </div>` : ''}
            ${repliesOf.has(c.id) ? `<div class="comment-replies">${repliesOf.get(c.id).map(commentHtml).join('')}</div>` : ''}
          </div>`;
    const listHtml = comments.length
      ? `<div class="comments-list">${roots.map(commentHtml).join('')}</div>`
      : `<div class="comments-empty">Brak komentarzy — bądź pierwszą osobą.</div>`;

    const offlineNote = commentsApiOk ? '' : `<div class="comments-offline-note">Brak połączenia z serwerem — nie udało się pobrać aktualnych komentarzy. Poniżej pusto, aż do odświeżenia.</div>`;

    const body = el('commentsPanelBody');
    if(!body) return;
    body.innerHTML = `
      <div class="comments-title">Komentarze · ${comments.length}</div>
      ${offlineNote}
      ${listHtml}
      <form class="comment-form" id="commentForm">
        <div class="comment-reply-chip" id="commentReplyChip" style="display:none;"><span>Odpowiedź do: <b id="commentReplyTo"></b></span><button type="button" id="commentReplyCancel" aria-label="Anuluj odpowiedź">×</button></div>
        ${commentAuthorField()}
        <textarea id="commentTextInput" placeholder="Napisz komentarz…" maxlength="500"></textarea>
        <div class="comment-filter-hint" id="commentFilterHint" role="status" aria-live="polite" hidden></div>
        <input type="text" class="comment-honeypot" id="commentWebsite" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button type="submit">Dodaj komentarz</button>
      </form>
    `;
    // Szkic przywracamy po przerysowaniu formularza — samo ustawienie value
    // nie odpala „input”, więc zapisujemy go tu, przy renderze danego zdjęcia.
    const draftField = el('commentTextInput');
    if(draftField){
      const draft = commentDrafts.get(photoId);
      if(draft) draftField.value = draft;
      draftField.addEventListener('input', () => {
        if(commentDrafts.size > 10){
          commentDrafts.delete(commentDrafts.keys().next().value);
        }
        if(draftField.value) commentDrafts.set(photoId, draftField.value);
        else commentDrafts.delete(photoId);
      });
    }

    if(focusCommentId != null){
      focusCommentInPanel(focusCommentId);
    } else if(scrollAfterRender && Date.now() < scrollAfterRender.until){
      const wantedId = scrollAfterRender.id;
      requestAnimationFrame(() => {
        const t = body.querySelector(`.comment-item[data-comment-id="${wantedId}"]`);
        if(t) t.scrollIntoView({ block:'center' }); else scrollCommentsToLatest();
      });
    } else {
      scrollCommentsToLatest();
    }

    body.querySelectorAll('.comment-del').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        await deleteCommentFor(photoId, +btn.dataset.delId);
        renderCommentsPanelContent(photoId);
        updateCommentsUI(photoId);
        renderRecentCommentsSection();
      });
    });

    // ---- moderacja komentarza przez administratora --------------------------
    // W DOM trafia tylko u widocznego administratora (viewerIsAdmin), ale to nie
    // zabezpieczenie — comments.php sprawdza rolę w requireAdmin(). Panel to
    // wyłącznie wygoda, żeby nie pchać administratora do osobnego okna.
    const closeEditPops = () => {
      body.querySelectorAll('.comment-edit-pop').forEach(p => { p.hidden = true; });
    };

    body.querySelectorAll('.comment-admin-btn[data-admin-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = +btn.dataset.adminEdit;
        const pop = body.querySelector(`.comment-edit-pop[data-edit-pop="${id}"]`);
        const c = byId.get(id);
        if(!pop || !c) return;
        const wasOpen = !pop.hidden;
        closeEditPops();          // otwarcie drugiego zamyka pierwsze
        if(wasOpen) return;       // ponowne kliknięcie zamyka to samo
        const ta = pop.querySelector('.comment-edit-text');
        const reason = pop.querySelector('.comment-edit-reason');
        ta.value = c.text || '';
        // pole powodu jest zwinięte; odkrywamy je tylko wtedy, gdy coś już było
        reason.value = c.edit_reason || '';
        reason.hidden = !c.edit_reason;
        pop.hidden = false;
        ta.focus();
        try{ ta.setSelectionRange(ta.value.length, ta.value.length); }catch(_){}
      });
    });

    body.querySelectorAll('.comment-edit-reason-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const reason = btn.parentElement.querySelector('.comment-edit-reason');
        if(!reason) return;
        reason.hidden = !reason.hidden;
        if(!reason.hidden) reason.focus();
      });
    });

    body.querySelectorAll('.comment-edit-cancel').forEach(btn => {
      btn.addEventListener('click', closeEditPops);
    });

    body.querySelectorAll('.comment-edit-save').forEach(btn => {
      btn.addEventListener('click', async () => {
        const pop = btn.closest('.comment-edit-pop');
        if(!pop) return;
        const id = +pop.dataset.editPop;
        const c = byId.get(id);
        const text = pop.querySelector('.comment-edit-text').value.trim();
        const reason = pop.querySelector('.comment-edit-reason').value.trim();
        if(!text){
          toast('Treść komentarza nie może być pusta.', true);
          return;
        }
        btn.disabled = true;
        try{
          await adminCommentCall({
            action: 'admin_edit',
            id,
            text,
            author: (c && c.author) || 'Anonim',
            reason
          });
          closeEditPops();
        }catch(err){
          btn.disabled = false;
          toast((err && err.apiMessage) || 'Nie udało się zapisać zmiany.', true);
          return;
        }
        await renderCommentsPanelContent(photoId);
        updateCommentsUI(photoId);
        renderRecentCommentsSection();
      });
    });

    body.querySelectorAll('.comment-admin-btn[data-admin-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = +btn.dataset.adminDel;
        if(!window.confirm('Usunąć ten komentarz razem z jego odpowiedziami?')) return;
        btn.disabled = true;
        try{
          await adminCommentCall({ action: 'admin_delete', id });
        }catch(err){
          btn.disabled = false;
          toast((err && err.apiMessage) || 'Nie udało się usunąć komentarza.', true);
          return;
        }
        await renderCommentsPanelContent(photoId);
        updateCommentsUI(photoId);
        renderRecentCommentsSection();
      });
    });

    // ---- polubienia (aktualizacja w miejscu, bez przeładowania panelu i utraty przewinięcia) ----
    body.querySelectorAll('.comment-like').forEach(btn => {
      btn.addEventListener('click', async () => {
        if(btn.dataset.busy) return;
        btn.dataset.busy = '1';
        const id = +btn.dataset.likeId;
        const wasLiked = btn.classList.contains('liked');
        const countEl = btn.querySelector('.comment-like-count');
        const prev = parseInt(countEl.textContent || '0', 10) || 0;
        const setState = (liked, n) => {
          btn.classList.toggle('liked', liked);
          btn.setAttribute('aria-pressed', liked ? 'true' : 'false');
          countEl.textContent = n > 0 ? n : '';
        };
        setState(!wasLiked, Math.max(0, prev + (wasLiked ? -1 : 1)));     // od razu, potem korekta z serwera
        try{
          const r = await likeCommentFor(id);
          // stan z serwera wprost do DOM — nie ma już lokalnej kopii listy
          // komentarzy, którą trzeba by aktualizować obok
          setState(!!r.liked, r.likes);
        }catch(_){
          setState(wasLiked, prev);
        }finally{
          delete btn.dataset.busy;
        }
      });
    });

    // ---- zgłaszanie komentarza ---------------------------------------------
    // Okno rozwija się pod samym komentarzem; powody doczytujemy dopiero
    // przy pierwszym otwarciu, potem są już w pamięci.
    body.querySelectorAll('.comment-report-btn[data-report-id]').forEach(btn => {
      const commentId = +btn.dataset.reportId;
      const pop = body.querySelector(`.comment-report-pop[data-report-pop="${commentId}"]`);
      if(!pop) return;
      const close = () => { pop.hidden = true; pop.innerHTML = ''; };

      btn.addEventListener('click', async () => {
        if(btn.classList.contains('sent')) return;
        pop.hidden = !pop.hidden;
        if(pop.hidden){ pop.innerHTML = ''; return; }
        pop.innerHTML = '<div class="comment-report-msg">Wczytywanie…</div>';

        const reasons = await loadReportReasons();
        if(pop.hidden) return;                      // zdążyli zamknąć w międzyczasie
        const keys = Object.keys(reasons);
        if(!keys.length){
          pop.innerHTML = '<div class="comment-report-msg is-error">Nie udało się pobrać listy powodów. Spróbuj ponownie za chwilę.</div>';
          return;
        }

        const group = 'cmrep' + commentId;
        pop.innerHTML =
          '<div class="comment-report-reasons">' + keys.map((k, i) =>
            '<label><input type="radio" name="' + group + '" value="' + escapeHtml(k) + '"'
            + (i === 0 ? ' checked' : '') + '><span>' + escapeHtml(reasons[k]) + '</span></label>'
          ).join('') + '</div>'
          + '<textarea class="comment-report-note" maxlength="300" placeholder="Co warto zobaczyć przy tym komentarzu? (opcjonalnie)"></textarea>'
          + '<div class="comment-report-row">'
            + '<button type="button" class="comment-report-send">Wyślij zgłoszenie</button>'
            + '<button type="button" class="comment-report-cancel">Anuluj</button>'
          + '</div>'
          + '<div class="comment-report-msg" hidden></div>';

        const sendBtn = pop.querySelector('.comment-report-send');
        const msg = pop.querySelector('.comment-report-msg');
        pop.querySelector('.comment-report-cancel').addEventListener('click', close);

        sendBtn.addEventListener('click', async () => {
          const picked = pop.querySelector('input[name="' + group + '"]:checked');
          const noteEl = pop.querySelector('.comment-report-note');
          const reason = picked ? picked.value : '';
          const note = noteEl ? noteEl.value.trim() : '';

          sendBtn.disabled = true;
          if(msg){ msg.hidden = false; msg.className = 'comment-report-msg'; msg.textContent = 'Wysyłanie…'; }
          try{
            await reportComment(commentId, reason, note);
            btn.classList.add('sent');
            btn.textContent = 'zgłoszono';
            btn.title = 'Zgłoszenie trafiło do administratora.';
            close();
            // Potwierdzenie zostaje na stronie (toast), a nie w osobnym oknie
            // przeglądarki — przycisk „zgłoszono” zostaje jako trwały ślad.
            toast('Dziękujemy — zgłoszenie trafiło do administratora.');
          }catch(err){
            sendBtn.disabled = false;
            if(msg){
              msg.className = 'comment-report-msg is-error';
              msg.textContent = (err && err.message) || 'Nie udało się wysłać zgłoszenia.';
            }
          }
        });
      });
    });

    // ---- podpowiedź filtra wulgaryzmów -------------------------------------
    // W trakcie pisania pytamy serwer, czy w treści (i — tylko przy komentarzu
    // anonimowym — w nazwie autora) jest wyraz z listy. Samej listy nie ma
    // w kodzie strony: reguły dopasowania żyją wyłącznie w PHP, więc podpowiedź
    // nie może się rozjechać z tym, co naprawdę odrzuca serwer.
    const hint = el('commentFilterHint');
    const liveText = el('commentTextInput');
    const liveAuthor = el('commentAuthorInput');
    let hintTimer = null;
    let hintSeq = 0;
    let commentSending = false;

    function setFilterHint(blocked, message){
      if(hint){
        hint.textContent = blocked ? message : '';
        hint.hidden = !blocked;
      }
      // W trakcie wysyłki nie ruszamy przycisku — podpowiedź o wulgaryzmie
      // nie może odblokować go, gdy formularz jest właśnie wysyłany.
      if(commentSending) return;
      const formEl = el('commentForm');
      const submitBtn = formEl && formEl.querySelector('button[type="submit"]');
      if(submitBtn) submitBtn.disabled = !!blocked;
    }

    async function checkCommentLanguage(){
      const text = (liveText && liveText.value || '').trim();
      const author = (liveAuthor && liveAuthor.value || '').trim();
      if(!text && !author){ setFilterHint(false, ''); return; }
      const seq = ++hintSeq;
      try{
        const res = await fetch(COMMENTS_API, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'check', text, author, csrf: csrfToken })
        });
        if(!res.ok) return;
        const d = await res.json();
        // użytkownik mógł dalej pisać, więc starsza odpowiedź nie może nadpisać nowszej
        if(seq !== hintSeq) return;
        setFilterHint(!!d.blocked, d.field === 'author'
          ? 'Nazwa autora zawiera wulgarny wyraz — wybierz inną.'
          : 'Uwaga: w komentarzu jest wulgarny wyraz. Taki komentarz nie zostanie dodany.');
      }catch(_){
        // brak połączenia — milczymy; przy wysyłce serwer odrzuci komentarz i tak
      }
    }

    function scheduleFilterCheck(){
      clearTimeout(hintTimer);
      hintTimer = setTimeout(checkCommentLanguage, 450);
    }

    if(liveText) liveText.addEventListener('input', scheduleFilterCheck);
    if(liveAuthor) liveAuthor.addEventListener('input', scheduleFilterCheck);

    // ---- odpowiedzi ----
    let replyTarget = null;      // { id, author, isReply }
    const chip = el('commentReplyChip');
    const setReplyTarget = t => {
      replyTarget = t;
      if(chip){
        chip.style.display = t ? '' : 'none';
        el('commentReplyTo').textContent = t ? t.author : '';
      }
    };
    body.querySelectorAll('.comment-reply-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = +btn.dataset.replyId;
        const c = byId.get(id);
        if(!c) return;
        setReplyTarget({ id, author: c.author || 'Anonim', isReply: !!c.parent_id });
        const ti = el('commentTextInput');
        if(ti){
          if(c.parent_id && !ti.value) ti.value = '@' + (c.author || 'Anonim') + ' ';
          ti.focus();
          // programowe wstawienie nie odpala zdarzenia input, a filtr ma
          // zobaczyć tę treść tak samo jak po wpisaniu ręcznym
          try{ ti.dispatchEvent(new Event('input')); }catch(_){}
          try{ ti.setSelectionRange(ti.value.length, ti.value.length); }catch(_){}
          ti.scrollIntoView({ block:'nearest', behavior:'smooth' });
        }
      });
    });
    const replyCancel = el('commentReplyCancel');
    if(replyCancel) replyCancel.addEventListener('click', () => setReplyTarget(null));

    const form = el('commentForm');
    if(form){
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const authorInput = el('commentAuthorInput');
        const textInput = el('commentTextInput');
        const honey = el('commentWebsite');
        const submitBtn = form.querySelector('button[type="submit"]');
        // honeypot — boty wypełniają ukryte pole
        if(honey && honey.value) return;
        const text = (textInput.value || '').trim();
        if(!text) return;
        // rate limit: 1 komentarz / 30 s na urządzenie
        try{
          const last = parseInt(localStorage.getItem('zmogilna-comment-ts') || '0', 10);
          if(last && Date.now() - last < 30000){
            alert('Poczekaj chwilę przed dodaniem kolejnego komentarza.');
            return;
          }
        }catch(_){}
        const author = (authorInput.value || '').trim() || 'Anonim';
        // przy zalogowaniu nazwa pochodzi z konta — nie zapisuj jej w localStorage
        if(!(lastWho && lastWho.user)){
          try{ localStorage.setItem(COMMENT_AUTHOR_KEY, author); }catch(_){}
        }
        const currentPhoto = lightboxPhotos.find(p => p.id === photoId);
        const snapshot = currentPhoto ? {
          id: currentPhoto.id, name: currentPhoto.name,
          thumbnailLink: currentPhoto.thumbnailLink, createdTime: currentPhoto.createdTime
        } : null;
        let focusAfter = null;
        if(submitBtn) submitBtn.disabled = true;
        commentSending = true;
        // optymistyczne UI — od razu czyścimy pole
        textInput.value = '';
        try{
          const saved = await addCommentFor(photoId, author, text, snapshot, replyTarget ? replyTarget.id : 0);
          focusAfter = saved && saved.id != null ? saved.id : null;
          // odpowiedź ląduje w środku listy — po odświeżeniu panelu przewijamy do niej (bez podświetlenia)
          if(replyTarget && focusAfter != null) scrollAfterRender = { id: focusAfter, until: Date.now() + 2500 };
          commentDrafts.delete(photoId);   // komentarz doszedł — szkic już niepotrzebny
          try{ localStorage.setItem('zmogilna-comment-ts', String(Date.now())); }catch(_){}
        }catch(err){
          // Własny komunikat serwera ma pierwszeństwo — przy wulgaryzmie
          // użytkownik musi wiedzieć, co poprawić. Dopiero jego brak oznacza
          // problem techniczny.
          if(err && err.apiMessage){
            alert(err.apiMessage);
          } else {
            alert('Nie udało się wysłać komentarza na serwer. Sprawdź połączenie z internetem i spróbuj ponownie.');
          }
          textInput.value = text;
        }
        commentSending = false;
        await renderCommentsPanelContent(photoId);
        updateCommentsUI(photoId);
        renderRecentCommentsSection();
      });
    }
  }

  async function updateCommentsUI(photoId){
    const comments = await getCommentsFor(photoId);
    // Odpowiedź z sieci przychodzi asynchronicznie i do tego czasu użytkownik
    // może przejść na inne zdjęcie. Licznik i panel należą do tego, co widać
    // TERAZ, więc spóźniona odpowiedź idzie do kosza — inaczej licznik pokazywałby
    // liczbę komentarzy poprzedniego kadru.
    const current = lightboxPhotos[lightboxIndex];
    if(!current || current.id !== photoId) return;

    const countEl = el('lightboxCommentCount');
    if(countEl){
      countEl.textContent = comments.length;
      countEl.style.display = comments.length > 0 ? 'flex' : 'none';
    }
    // Przerysowujemy także wtedy, gdy panel pokazuje jeszcze INNE zdjęcie.
    // Wcześniejszy warunek `dataset.photoId === photoId` chronił przed
    // spóźnioną odpowiedzią, ale przez to samo cicho nic nie robił przy zmianie
    // kadru — i komentarze zostawały ze zdjęcia sprzed chwili.
    const panel = el('lightboxCommentsPanel');
    if(commentsOpen && panel.dataset.photoId !== photoId){
      renderCommentsPanelContent(photoId, comments);
    }
  }

  function toggleCommentsPanel(forceState){
    if(!lightboxPhotos.length) return;
    const f = lightboxPhotos[lightboxIndex];
    commentsOpen = typeof forceState === 'boolean' ? forceState : !commentsOpen;
    el('lightboxCommentsBtn').classList.toggle('active', commentsOpen);
    el('lightboxCommentsPanel').classList.toggle('open', commentsOpen);
    if(commentsOpen){
      renderCommentsPanelContent(f.id);
      requestAnimationFrame(syncCommentsPanelToPhoto);
    }
  }

  el('lightboxCommentsBtn').addEventListener('click', () => toggleCommentsPanel());

  el('lightboxInfoBtn').addEventListener('click', () => {
    exifOpen = !exifOpen;
    el('lightboxExif').classList.toggle('open', exifOpen);
    el('lightboxInfoBtn').classList.toggle('active', exifOpen);
  });

  el('lightboxMapBtn').addEventListener('click', e => {
    e.stopPropagation();
    const f = lightboxPhotos[lightboxIndex];
    if(f && f.mapUrl) openMapPanel(f.mapUrl, f.coordinates);
  });
  el('lightboxMapClose').addEventListener('click', e => { e.stopPropagation(); closeMapPanel(); });
  el('lightboxMapPanel').addEventListener('click', e => { if(e.target === el('lightboxMapPanel')) closeMapPanel(); });

  function galleryPhotoUrl(photoId){
  const u = new URL('index.php', location.href);
  u.searchParams.set('photo', photoId);
  if(profileReturnId) u.searchParams.set('profile_id', profileReturnId);
  if(lightboxReturnUrl) u.searchParams.set('return', lightboxReturnUrl);
  u.hash = '';
  return u.toString();
}

function sharePhotoUrl(photoId){
  const u = new URL('share.php', location.href);
  u.searchParams.set('photo', photoId);
  // Krótki cache-buster: social media nie zatrzymają starej, błędnej karty.
  u.searchParams.set('v', String(Math.floor(Date.now() / 3600000)));
  u.hash = '';
  return u.toString();
}

  function openShareModal(url, title, photoId){
    const modal = el('shareModal');
    if(!modal) return;
    el('shareModalTitle').textContent = title || 'zdjęcie';
    el('shareModalUrl').value = url;
    const copyBtn = el('shareCopyBtn');
    copyBtn.textContent = 'Skopiuj link';
    copyBtn.classList.remove('copied');
    modal.classList.add('open');
    // podgrzewa lokalną miniaturę (pod karty X/FB) — wszystko na własnym serwerze
    if(photoId){
      try{
        const warm = new URL('photo.php', location.href);
        warm.searchParams.set('id', photoId);
        warm.searchParams.set('s', '1200');
        fetch(warm.toString(), { cache:'no-store' }).catch(() => {});
      }catch(_){}
    }
    setTimeout(() => {
      try{ el('shareModalUrl').focus(); el('shareModalUrl').select(); }catch(_){}
    }, 40);
  }
  function closeShareModal(){
    const modal = el('shareModal');
    if(modal) modal.classList.remove('open');
  }
  function shareCurrentPhoto(){
    const f = lightboxPhotos[lightboxIndex];
    if(!f) return;
    const url = sharePhotoUrl(f.id);
    const title = (f.name || 'zdjęcie').replace(/\.[^/.]+$/, '');
    openShareModal(url, title, f.id);
  }
  (function bindShareModal(){
    const modal = el('shareModal');
    if(!modal) return;
    el('shareModalClose').addEventListener('click', e => { e.stopPropagation(); closeShareModal(); });
    modal.addEventListener('click', e => { if(e.target === modal) closeShareModal(); });
    document.addEventListener('keydown', e => {
      if(e.key === 'Escape' && modal.classList.contains('open')) closeShareModal();
    });
    el('shareCopyBtn').addEventListener('click', async () => {
      const url = el('shareModalUrl').value;
      const btn = el('shareCopyBtn');
      let ok = false;
      try{
        await navigator.clipboard.writeText(url);
        ok = true;
      }catch(_){
        try{
          el('shareModalUrl').select();
          ok = document.execCommand('copy');
        }catch(__){ ok = false; }
      }
      if(ok){
        btn.textContent = 'Skopiowano';
        btn.classList.add('copied');
        const cap = el('lightboxCap');
        if(cap){
          const prev = cap.textContent;
          cap.textContent = 'Link skopiowany do schowka';
          setTimeout(() => { if(el('lightbox') && el('lightbox').classList.contains('open')) cap.textContent = prev; }, 1600);
        }
        setTimeout(() => {
          btn.textContent = 'Skopiuj link';
          btn.classList.remove('copied');
        }, 1800);
      } else {
        btn.textContent = 'Zaznacz i skopiuj ręcznie';
      }
    });
  })();

  // ---------- zgłoszenie zdjęcia ----------
  let photoReportReasons = null;
  let reportBusy = false;

  function reportCurrentPhoto(){
    const f = lightboxPhotos[lightboxIndex];
    if(!f) return;
    openReportModal(f);
  }

  async function loadPhotoReportReasons(){
    if(photoReportReasons) return photoReportReasons;
    try{
      const res = await fetch('photo-reports-api.php?action=reasons', { credentials:'include', cache:'no-store' });
      const d = await res.json();
      photoReportReasons = (d && d.reasons) ? d.reasons : {};
    }catch(_){ photoReportReasons = {}; }
    return photoReportReasons;
  }

  async function openReportModal(f){
    const modal = el('reportModal');
    if(!modal) return;
    const title = (f.name || 'zdjęcie').replace(/\.[^/.]+$/, '');
    el('reportModalTitle').textContent = title;
    setReportMsg('', '');
    el('reportNote').value = '';
    el('reportSubmitBtn').disabled = false;
    el('reportSubmitBtn').textContent = 'Wyślij zgłoszenie';
    modal.classList.add('open');
    const reasons = await loadPhotoReportReasons();
    const sel = el('reportReason');
    sel.innerHTML = '';
    const keys = Object.keys(reasons);
    if(!keys.length){
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Nie udało się wczytać powodów — spróbuj ponownie.';
      sel.appendChild(opt);
    } else {
      keys.forEach(k => {
        const opt = document.createElement('option');
        opt.value = k;
        opt.textContent = reasons[k];
        sel.appendChild(opt);
      });
    }
    setTimeout(() => { try{ sel.focus(); }catch(_){} }, 40);
  }

  function closeReportModal(){
    const modal = el('reportModal');
    if(modal) modal.classList.remove('open');
  }

  function setReportMsg(text, kind){
    const msg = el('reportModalMsg');
    if(!msg) return;
    msg.textContent = text || '';
    msg.className = 'report-modal-msg' + (kind ? ' ' + kind : '');
  }

  function submitReport(){
    if(reportBusy) return;
    const f = lightboxPhotos[lightboxIndex];
    if(!f) return;
    const reason = el('reportReason').value;
    const note = el('reportNote').value.trim();
    if(!reason){
      setReportMsg('Wybierz powód zgłoszenia.', 'err');
      return;
    }
    reportBusy = true;
    const btn = el('reportSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Wysyłanie…';
    setReportMsg('', '');

    const send = (token) => fetch('photo-reports-api.php', {
      method:'POST', credentials:'include', cache:'no-store',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ action:'report', photo_id:String(f.id), reason, note, csrf: token })
    });

    send(csrfToken).then(async res => {
      // Świeży token na wypadek wygaśnięcia sesji — bez przeładowania strony.
      if(res.status === 403){ await whoAmI(); res = await send(csrfToken); }
      let d = {};
      try{ d = await res.json(); }catch(_){ d = {}; }
      if(res.ok && d && d.ok){
        setReportMsg('Dziękujemy — zgłoszenie trafiło do administracji.', 'ok');
        btn.textContent = 'Wysłano';
        setTimeout(() => { closeReportModal(); }, 1400);
      } else {
        const map = {
          already_reported:'To zgłoszenie już od ciebie wyszło — administrator je widzi.',
          own_photo:'Nie możesz zgłosić własnego zdjęcia.',
          not_found:'Tego zdjęcia już nie ma.',
          too_many_reports:'Zbyt wiele zgłoszeń w krótkim czasie. Spróbuj później.',
          bad_csrf:'Sesja wygasła. Odśwież stronę i spróbuj ponownie.'
        };
        setReportMsg((d && d.message) || map[d && d.error] || 'Nie udało się wysłać zgłoszenia.', 'err');
        btn.disabled = false;
        btn.textContent = 'Wyślij zgłoszenie';
      }
    }).catch(() => {
      setReportMsg('Błąd połączenia. Spróbuj ponownie.', 'err');
      btn.disabled = false;
      btn.textContent = 'Wyślij zgłoszenie';
    }).finally(() => { reportBusy = false; });
  }

  (function bindReportModal(){
    const btn = el('lightboxReportBtn');
    const modal = el('reportModal');
    if(btn) btn.addEventListener('click', e => { e.stopPropagation(); reportCurrentPhoto(); });
    if(!modal) return;
    el('reportModalClose').addEventListener('click', e => { e.stopPropagation(); closeReportModal(); });
    el('reportCancelBtn').addEventListener('click', e => { e.stopPropagation(); closeReportModal(); });
    modal.addEventListener('click', e => { if(e.target === modal) closeReportModal(); });
    el('reportSubmitBtn').addEventListener('click', e => { e.stopPropagation(); submitReport(); });
    // Escape zamyka najpierw zgłoszenie, nie cały lightbox pod spodem.
    document.addEventListener('keydown', e => {
      if(e.key === 'Escape' && modal.classList.contains('open')){
        e.stopImmediatePropagation();
        closeReportModal();
      }
    }, true);
  })();

  function openLightbox(photos, index, opts){
    opts = opts || {};
    lightboxPhotos = photos;
    exifOpen = false;
    commentsOpen = !!opts.openComments;
    const panel = el('lightboxCommentsPanel');
    if(panel) panel.classList.toggle('open', commentsOpen);
    el('lightboxCommentsBtn').classList.toggle('active', commentsOpen);
    showLightboxAt(index);
    el('lightbox').classList.add('open');
    lockBodyScroll();
    const f = lightboxPhotos[lightboxIndex];
    const url = f ? galleryPhotoUrl(f.id) : location.href;
    try{ history.pushState({ type:'lightbox', path: state.path.map(p => ({id:p.id, name:p.name})), photoId: f && f.id }, '', url); }catch(_){}
    if(commentsOpen && f){
      renderCommentsPanelContent(f.id, null, opts.focusCommentId);
      requestAnimationFrame(syncCommentsPanelToPhoto);
    }
  }

  function closeLightboxNow(){
    const wasOpen = el('lightbox').classList.contains('open');
    el('lightbox').classList.remove('open');
    commentsOpen = false;
    closeMapPanel();
    el('lightboxCommentsBtn').classList.remove('active');
    const panel = el('lightboxCommentsPanel');
    if(panel) panel.classList.remove('open');
    unlockBodyScroll();
    if(profileReturnId){
      const target = new URL('profil.php', location.href);
      target.searchParams.set('id', profileReturnId);
      location.href = target.toString();
      return;
    }
    // Powrót tam, skąd otwarto zdjęcie (atrakcje.php z filtrem) zamiast
    // na stronę główną. Nawigacja wprost, nie history.back() — otwarcie
    // lightboxa robi pushState, więc „wstecz” zamknęłoby tylko poprzedni kadr.
    if(lightboxReturnUrl){
      location.href = lightboxReturnUrl;
      return;
    }
    // usuń ?photo= z adresu
    try{
      const u = new URL(location.href);
      if(u.searchParams.has('photo')){
        u.searchParams.delete('photo');
        // `comment` towarzyszył `photo` przy deep linku z profilu — bez
        // zdjęcia jest bez znaczenia i zostawiałby brudny adres
        u.searchParams.delete('comment');
        history.replaceState({ type:'gallery', path: state.path.map(p => ({id:p.id, name:p.name})) }, '', u.pathname + u.search + u.hash);
      } else if(wasOpen && history.state && history.state.type === 'lightbox'){
        history.back();
      }
    }catch(_){
      if(wasOpen && history.state && history.state.type === 'lightbox') history.back();
    }
  }

  el('lightboxPrev').addEventListener('click', () => showLightboxAt(lightboxIndex - 1));
  el('lightboxNext').addEventListener('click', () => showLightboxAt(lightboxIndex + 1));

  document.addEventListener('keydown', e => {
    if(!el('lightbox').classList.contains('open')) return;
    if(e.key === 'ArrowLeft') showLightboxAt(lightboxIndex - 1);
    if(e.key === 'ArrowRight') showLightboxAt(lightboxIndex + 1);
    if(e.key === 'Escape') closeLightboxNow();
  });


  el('closeLightbox').addEventListener('click', () => closeLightboxNow());
  el('lightbox').addEventListener('click', e => { if(e.target.id==='lightbox') closeLightboxNow(); });
  const shareBtn = el('lightboxShareBtn');
  if(shareBtn) shareBtn.addEventListener('click', e => { e.stopPropagation(); shareCurrentPhoto(); });

  // ---------- native-feeling touch gestures (swipe / pinch / drag-to-dismiss) ----------
  (function(){
    const lb = el('lightbox');
    const img = el('lightboxImg');
    const cap = el('lightboxCap');
    const closeBtn = el('closeLightbox');

    const st = {
      dragging:false, direction:null,
      startX:0, startY:0, curX:0, curY:0, startTime:0,
      scale:1, panX:0, panY:0, basePanX:0, basePanY:0,
      pinching:false, pinchStartDist:0, pinchStartScale:1,
      lastTapTime:0, lastTapX:0, lastTapY:0
    };

    function dist(a,b){ return Math.hypot(b.clientX-a.clientX, b.clientY-a.clientY); }

    function setImgTransition(on){ img.style.transition = on ? 'transform .25s cubic-bezier(.22,.61,.36,1)' : 'none'; }

    function applyZoomTransform(){
      img.style.transform = `translate(${st.panX}px, ${st.panY}px) scale(${st.scale})`;
    }

    function resetZoom(animate){
      st.scale = 1; st.panX = 0; st.panY = 0; st.basePanX = 0; st.basePanY = 0;
      setImgTransition(animate);
      applyZoomTransform();
    }

    function resetSwipeVisual(animate){
      setImgTransition(animate);
      img.style.transform = 'translate(0,0) scale(1)';
      cap.style.opacity = '';
      lb.style.background = '';
      closeBtn.style.opacity = '';
    }

    // called every time a new photo is shown
    const _showLightboxAt = showLightboxAt;
    showLightboxAt = function(i){
      _showLightboxAt(i);
      st.scale = 1; st.panX = 0; st.panY = 0; st.basePanX = 0; st.basePanY = 0;
      setImgTransition(false);
      img.style.transform = 'translate(0,0) scale(1)';
      cap.style.opacity = '';
      lb.style.background = '';
      closeBtn.style.opacity = '';
    };

    lb.addEventListener('touchstart', e => {
      // Komentarze mają własny pionowy scroll. Nie uruchamiaj gestu zdjęcia.
      if(e.target.closest && e.target.closest('#lightboxCommentsPanel')){
        st.dragging = false;
        st.pinching = false;
        return;
      }
      if(e.touches.length === 2){
        st.pinching = true;
        st.dragging = false;
        st.pinchStartDist = dist(e.touches[0], e.touches[1]);
        st.pinchStartScale = st.scale;
        setImgTransition(false);
        return;
      }
      if(e.touches.length === 1){
        const t = e.touches[0];
        st.dragging = true;
        st.direction = null;
        st.startX = st.curX = t.clientX;
        st.startY = st.curY = t.clientY;
        st.startTime = Date.now();
        setImgTransition(false);
        lb.style.transition = 'none';
      }
    }, { passive:true });

    lb.addEventListener('touchmove', e => {
      // Zdarzenie może wypłynąć do lightboxa, ale panel nadal przewija się natywnie.
      if(e.target.closest && e.target.closest('#lightboxCommentsPanel')) return;
      if(st.pinching && e.touches.length === 2){
        e.preventDefault();
        const d = dist(e.touches[0], e.touches[1]);
        st.scale = Math.min(4, Math.max(1, st.pinchStartScale * (d / st.pinchStartDist)));
        applyZoomTransform();
        return;
      }
      if(!st.dragging || e.touches.length !== 1) return;
      const t = e.touches[0];
      const dx = t.clientX - st.startX;
      const dy = t.clientY - st.startY;
      st.curX = t.clientX; st.curY = t.clientY;

      if(st.scale > 1.03){
        e.preventDefault();
        img.style.transform = `translate(${st.basePanX + dx}px, ${st.basePanY + dy}px) scale(${st.scale})`;
        return;
      }

      if(!st.direction && (Math.abs(dx) > 8 || Math.abs(dy) > 8)){
        st.direction = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v';
      }
      if(st.direction === 'h'){
        e.preventDefault();
        img.style.transform = `translateX(${dx}px)`;
        cap.style.opacity = String(Math.max(0, 1 - Math.abs(dx)/300));
      } else if(st.direction === 'v'){
        e.preventDefault();
        const dampScale = Math.max(0.72, 1 - Math.abs(dy)/900);
        img.style.transform = `translateY(${dy}px) scale(${dampScale})`;
        const fade = Math.max(0, 1 - Math.abs(dy)/280);
        lb.style.background = `rgba(8,14,10,${(0.94*fade).toFixed(3)})`;
        cap.style.opacity = String(fade);
        closeBtn.style.opacity = String(fade);
      }
    }, { passive:false });

    lb.addEventListener('touchend', e => {
      if(st.pinching){
        st.pinching = e.touches.length === 2;
        if(!st.pinching){
          st.basePanX = st.panX; st.basePanY = st.panY;
          if(st.scale <= 1.03) resetZoom(true);
        }
        return;
      }
      if(!st.dragging) return;
      st.dragging = false;

      if(st.scale > 1.03){
        st.basePanX += (st.curX - st.startX);
        st.basePanY += (st.curY - st.startY);
        st.panX = st.basePanX; st.panY = st.basePanY;
        return;
      }

      const dx = st.curX - st.startX;
      const dy = st.curY - st.startY;
      const dt = Math.max(1, Date.now() - st.startTime);
      const velX = dx/dt, velY = dy/dt;

      if(st.direction === 'h'){
        const threshold = window.innerWidth * 0.22;
        if((dx < -threshold || velX < -0.55) && lightboxPhotos.length > 1){
          const w = window.innerWidth;
          setImgTransition(true);
          img.style.transform = `translateX(${-w}px)`;
          setTimeout(() => showLightboxAt(lightboxIndex + 1), 200);
        } else if((dx > threshold || velX > 0.55) && lightboxPhotos.length > 1){
          const w = window.innerWidth;
          setImgTransition(true);
          img.style.transform = `translateX(${w}px)`;
          setTimeout(() => showLightboxAt(lightboxIndex - 1), 200);
        } else {
          resetSwipeVisual(true);
        }
      } else if(st.direction === 'v'){
        if(dy > 110 || velY > 0.6){
          setImgTransition(true);
          lb.style.transition = 'background .18s ease';
          img.style.transform = `translateY(${Math.max(dy, 160)}px) scale(0.6)`;
          lb.style.background = 'rgba(8,14,10,0)';
          setTimeout(() => { closeLightboxNow(); resetSwipeVisual(false); }, 180);
        } else {
          resetSwipeVisual(true);
        }
      } else {
        // tap (no direction) — double-tap toggles zoom
        const now = Date.now();
        const closeTap = Math.abs(st.curX - st.lastTapX) < 30 && Math.abs(st.curY - st.lastTapY) < 30;
        if(now - st.lastTapTime < 300 && closeTap){
          if(st.scale > 1.03){
            resetZoom(true);
          } else {
            st.scale = 2.2; st.panX = 0; st.panY = 0; st.basePanX = 0; st.basePanY = 0;
            setImgTransition(true);
            applyZoomTransform();
          }
          st.lastTapTime = 0;
        } else {
          st.lastTapTime = now;
          st.lastTapX = st.curX; st.lastTapY = st.curY;
        }
      }
      st.direction = null;
    }, { passive:true });
  })();


  // ---------- lightbox na komputerze: zoom kółkiem/gładzikiem, przeciąganie, płynne przejścia ----------
  (function(){
    const lb = el('lightbox');
    const img = el('lightboxImg');
    const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    const z = { s:1, x:0, y:0 };
    let hiRes = false, drag = null, raf = 0, lastType = 'mouse', viewTimer = 0;

    function clampPan(){
      if(z.s <= 1){ z.x = 0; z.y = 0; return; }
      const mx = Math.max(0, (img.offsetWidth * z.s - lb.clientWidth) / 2);
      const my = Math.max(0, (img.offsetHeight * z.s - lb.clientHeight) / 2);
      z.x = Math.max(-mx, Math.min(mx, z.x));
      z.y = Math.max(-my, Math.min(my, z.y));
    }
    function apply(smooth){
      clampPan();
      img.style.transition = (smooth && !reduce) ? 'transform .2s ease-out' : 'none';
      img.style.transform = (z.s === 1 && !z.x && !z.y)
        ? 'translate(0,0) scale(1)'
        : `translate(${z.x}px, ${z.y}px) scale(${z.s})`;
      img.style.cursor = z.s > 1 ? (drag ? 'grabbing' : 'grab') : 'zoom-in';
      if(z.s > 1.4 && !hiRes) loadHiRes();
    }
    // przy zoomie podmieniamy na wersję 2000 px, żeby nie było rozmyte
    function loadHiRes(){
      const f = lightboxPhotos[lightboxIndex];
      if(!f) return;
      hiRes = true;
      const big = new Image();
      big.onload = () => {
        const swap = () => { if(lightboxPhotos[lightboxIndex] === f) img.src = big.src; };
        if(big.decode){ big.decode().then(swap, swap); } else { swap(); }
      };
      big.src = bigThumb(f.thumbnailLink, 2000);
    }
    function zoomAt(newS, cx, cy, smooth){
      newS = Math.min(5, Math.max(1, newS));
      const r = lb.getBoundingClientRect();
      const qx = cx - (r.left + r.width / 2);
      const qy = cy - (r.top + r.height / 2);
      const k = newS / z.s;
      z.x = qx - k * (qx - z.x);
      z.y = qy - k * (qy - z.y);
      z.s = newS;
      apply(smooth);
    }
    function resetZoom(smooth){ z.s = 1; z.x = 0; z.y = 0; apply(smooth); }

    // --- kółko / gładzik ---
    let lastWheelTs = 0, lastNav = 0, streamNav = false;
    lb.addEventListener('wheel', e => {
      if(!lb.classList.contains('open')) return;
      if(e.target.closest && e.target.closest('#lightboxCommentsPanel')) return;
      e.preventDefault();

      // Ctrl + kółko albo   pinch” na g  adziku → zoom pod kursorem
      if(e.ctrlKey){
        const d = Math.max(-25, Math.min(25, e.deltaY));
        zoomAt(z.s * Math.exp(-d * 0.015), e.clientX, e.clientY, false);
        return;
      }
      // przybliżone zdjęcie: kółko/dwa palce przesuwają obraz
      if(z.s > 1){
        z.x -= e.deltaX; z.y -= e.deltaY;
        apply(false);
        return;
      }
      // zdjęcie w całości: przewijanie do następnego/poprzedniego.
      // Bezwładność gładzika daje jedno przejście na gest, nie kilka.
      const now = performance.now();
      const idle = now - lastWheelTs > 140;
      lastWheelTs = now;
      if(idle) streamNav = false;
      const notch = Math.abs(e.deltaY) >= 50 || Math.abs(e.deltaX) >= 50;
      if(now - lastNav < 250) return;
      if(streamNav && !notch) return;
      if(Math.abs(e.deltaY) + Math.abs(e.deltaX) < 4) return;
      streamNav = true;
      lastNav = now;
      if(e.deltaY > 0 || e.deltaX > 0) showLightboxAt(lightboxIndex + 1);
      else showLightboxAt(lightboxIndex - 1);
    }, { passive:false });

    // --- podwójne kliknięcie: zoom pod kursorem ---
    img.addEventListener('pointerdown', e => { lastType = e.pointerType; }, true);
    img.addEventListener('dblclick', e => {
      if(lastType !== 'mouse') return;   // dotyk ma własną obsługę double-tap
      e.preventDefault();
      if(z.s > 1) resetZoom(true);
      else zoomAt(2.5, e.clientX, e.clientY, true);
    });

    // --- przeciąganie myszą po przybliżeniu ---
    img.addEventListener('pointerdown', e => {
      if(e.pointerType !== 'mouse' || e.button !== 0 || z.s <= 1) return;
      e.preventDefault();
      drag = { x:e.clientX, y:e.clientY, ox:z.x, oy:z.y };
      try{ img.setPointerCapture(e.pointerId); }catch(_){}
      apply(false);
    });
    img.addEventListener('pointermove', e => {
      if(!drag) return;
      z.x = drag.ox + (e.clientX - drag.x);
      z.y = drag.oy + (e.clientY - drag.y);
      if(!raf) raf = requestAnimationFrame(() => { raf = 0; apply(false); });
    });
    const endDrag = () => { if(!drag) return; drag = null; apply(false); };
    img.addEventListener('pointerup', endDrag);
    img.addEventListener('pointercancel', endDrag);

    // --- klawisze + / − / 0 ---
    document.addEventListener('keydown', e => {
      if(!lb.classList.contains('open')) return;
      const t = e.target;
      if(t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
      const r = lb.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
      if(e.key === '+' || e.key === '='){ zoomAt(z.s * 1.5, cx, cy, true); }
      else if(e.key === '-' || e.key === '_'){ zoomAt(z.s / 1.5, cx, cy, true); }
      else if(e.key === '0'){ resetZoom(true); }
    });

    // --- płynne przejście między zdjęciami ---
    const _prevShow = showLightboxAt;
    showLightboxAt = function(i){
      const wasOpen = lb.classList.contains('open');
      const prev = lightboxIndex;
      z.s = 1; z.x = 0; z.y = 0; hiRes = false; drag = null;
      _prevShow(i);
      img.style.cursor = 'zoom-in';
      // wyświetlenie liczymy, gdy zdjęcie zostaje na ekranie choć 1 s (przewijanie "w locie" nie liczy)
      clearTimeout(viewTimer);
      const cur = lightboxPhotos[lightboxIndex];
      if(cur && cur.id){
        viewTimer = setTimeout(() => {
          if(lb.classList.contains('open') && lightboxPhotos[lightboxIndex] === cur) trackViewOnce(cur.id);
        }, 1000);
      }
      if(wasOpen && !reduce && lightboxPhotos.length > 1 && img.style.display !== 'none' && img.animate){
        const dir = i > prev ? 1 : -1;
        img.animate(
          [{ opacity:0, transform:`translateX(${dir * 48}px)` }, { opacity:1, transform:'translateX(0)' }],
          { duration:240, easing:'cubic-bezier(.2,.8,.2,1)' }
        );
      }
    };
  })();

  function showError(msg){
    errorBox.innerHTML = `<div class="error-box"><b>Nie udało się pobrać zdjęć.</b><br>${msg}</div>`;
  }
  function clearError(){ errorBox.innerHTML = ''; }

  // Liczy zdjęcia W SPÓRÓWŚCI, nie sumę wpisów.
//
// Zdjęcie może leżeć w kilku folderach naraz (np. „Gmina Mogilno” i jej
// podfolder „Żabno”), więc zwykłe dodawanie liczby plików z każdego folderu
// liczyło jedno zdjęcie dwa razy. Dlatego zbieramy identyfikatory w zbiorze:
// każde zdjęcie wchodzi do wyniku dokładnie raz.
async function countAllPhotos(folderId, seenIds){
    const q = encodeURIComponent(`'${folderId}' in parents and trashed = false and (mimeType = '${FOLDER_MIME}' or mimeType contains 'image/')`);
    const fields = encodeURIComponent('files(id,mimeType,createdTime)');
    const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=1000&_=${Date.now()}`;
    const res = await fetch(url, { cache: 'no-store' });
    const data = await res.json();
    if(!res.ok) throw new Error(data.error && data.error.message ? data.error.message : 'Błąd zliczania zdjęć.');
    const items = data.files || [];
    const subfolders = items.filter(i => i.mimeType === FOLDER_MIME);
    const photos = items.filter(i => i.mimeType !== FOLDER_MIME);

    // seen przechodzimy w głąb — jedna instancja na całe wywołanie.
    const seen = seenIds instanceof Set ? seenIds : new Set();
    const createdTimes = [];
    photos.forEach(p => {
      if(seen.has(p.id)) return;
      seen.add(p.id);
      createdTimes.push(p.createdTime);
    });

    for(const f of subfolders){
      const sub = await countAllPhotos(f.id, seen);
      createdTimes.push(...sub.createdTimes);
    }

    return { count: seen.size, createdTimes };
  }

  function renderActivityChart(createdTimes){
    const container = el('activityChart');
    if(!container) return;
    const now = new Date();
    const months = [];
    for(let i=11; i>=0; i--){
      const d = new Date(now.getFullYear(), now.getMonth()-i, 1);
      months.push({ key:`${d.getFullYear()}-${d.getMonth()}`, label: d.toLocaleDateString('pl-PL', {month:'short'}), count:0 });
    }
    createdTimes.forEach(ct => {
      if(!ct) return;
      const d = new Date(ct);
      const key = `${d.getFullYear()}-${d.getMonth()}`;
      const m = months.find(m => m.key === key);
      if(m) m.count++;
    });
    const max = Math.max(1, ...months.map(m => m.count));
    container.innerHTML = months.map(m => `
      <div class="chart-bar-col" title="${m.label}: ${m.count}">
        <div class="chart-bar" style="height:${Math.max(2, Math.round((m.count/max)*100))}%"></div>
        <div class="chart-bar-label">${m.label}</div>
      </div>
    `).join('');
  }

  async function refreshTotalCount(){
    if(!state.rootId || !state.apiKey) return;
    try{
      const { count, createdTimes } = await countAllPhotos(state.rootId);
      el('statPhotos').textContent = count;
      renderActivityChart(createdTimes);
    }catch(err){
      // cichy błąd — licznik po prostu zostaje przy poprzedniej wartości
    }
  }

  let debugCountSeq = 0;
  function writeDebug(lines){
    const box = document.getElementById('debugContent');
    if(box) box.textContent = lines.join('\n');
  }

  // ---------- diagnostyka: ostatnio dodane zdjęcia (globalnie) ----------
  let debugRecentPhotos = [];
  async function renderDebugRecentPhotos(){
    const stripEl = el('debugRecentStrip');
    if(!stripEl || !state.rootId || !state.apiKey) return;
    try{
      const recent = await fetchRecentPhotosInFolder(state.rootId, 12);
      debugRecentPhotos = recent;
      if(!recent.length){
        stripEl.innerHTML = `<div class="strip-empty">brak zdjęć</div>`;
        return;
      }
      stripEl.innerHTML = recent.map((p,i) => `
        <div class="strip-thumb" data-idx="${i}" title="${p.name}${p.createdTime ? ' · ' + new Date(p.createdTime).toLocaleString('pl-PL') : ''}">
          <img ${thumbAttrs(p.thumbnailLink, 320, 'auto, 180px')} alt="${p.name}" loading="lazy">
        </div>
      `).join('');
      stripEl.querySelectorAll('.strip-thumb').forEach(t => {
        t.addEventListener('click', () => openLightbox(debugRecentPhotos, +t.dataset.idx));
      });
    }catch(err){
      stripEl.innerHTML = `<div class="strip-empty">błąd ładowania zdjęć</div>`;
    }
  }

  let folderSyncSeq = 0;
  let backgroundSyncInFlight = false;
  async function fetchFolder(manual){
    if(!state.rootId || !state.apiKey){ renderEmpty(); return; }
    if(!manual && backgroundSyncInFlight) return;
    const requestSeq = ++folderSyncSeq;
    backgroundSyncInFlight = true;
    setSyncStatus('synchronizuję…', true);
    if(manual) clearError();
    const parentId = currentFolderId();
    const now0 = new Date().toLocaleTimeString('pl-PL');
    try{
      // zawsze z sieci: bez cache listingu, więc stan katalogu na ekranie
      // jest zawsze tym, co zwrócił serwer w tej chwili
      const q = encodeURIComponent(`'${parentId}' in parents and trashed = false and (mimeType = '${FOLDER_MIME}' or mimeType contains 'image/')`);
      const fields = encodeURIComponent('files(id,name,mimeType,thumbnailLink,createdTime,imageMediaMetadata)');
      const url = `${CONFIG.api}?q=${q}&fields=${fields}&orderBy=folder,name&pageSize=300&_=${Date.now()}`;
      setSyncStatus('pobieram listę…', true);
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if(requestSeq !== folderSyncSeq) return;
      if(!res.ok){
        writeDebug([
          `[${now0}] próba synchronizacji`,
          `folder pytany (parentId): ${parentId}`,
          `status HTTP: ${res.status}`,
          `treść błędu: ${(data.error && data.error.message) || 'brak szczegółów'}`
        ]);
        let msg = data.error && data.error.message ? data.error.message : 'Błąd odczytu katalogu ze zdjęciami.';
        if(res.status === 403) msg = 'Brak dostępu (403). Sprawdź uprawnienia katalogu uploads/photos/.';
        if(res.status === 404) msg = 'Nie znaleziono folderu (404). Sprawdź katalog uploads/photos/.';
        throw new Error(msg);
      }
      state.items = data.files || [];
      folderWalkCache = {}; // nowy cykl synchronizacji — unieważnij cache list folderów
      folderPreviewCache = {};
      const folderCount = state.items.filter(i => i.mimeType === FOLDER_MIME).length;
      const photoCount = state.items.length - folderCount;
      // „zdjęcia: 0” w katalogu głównym to normalna sytuacja, gdy wszystkie zdjęcia leżą w kategoriach —
      // dlatego obok liczby zdjęć bezpośrednio w folderze pokazujemy też sumę z podfolderami
      const debugLines = [
        `[${now0}] synchronizacja OK`,
        `folder pytany (parentId): ${parentId}`,
        `status HTTP: ${res.status}`,
        `znalezione podfoldery: ${folderCount}`,
        `zdjęcia bezpośrednio w tym folderze: ${photoCount}`,
        `zdjęcia łącznie (z podfolderami): ${folderCount ? 'pomiar pominięty (optymalizacja)' : photoCount}`,
        `nazwy: ${state.items.map(i => i.name).join(', ') || '(pusto)'}`
      ];
      writeDebug(debugLines);
      // Bez rekurencyjnego countAllPhotos(): diagnostyka nie uruchamia
      // dziesiątek dodatkowych żądań podczas zwykłej wizyty.

      // Nie sortujemy gmin przez rekurencyjne liczenie wszystkich zdjęć.
      // Kolejność z API jest natychmiastowa i nie wymaga dodatkowych zapytań.

      renderContents(state.items);
      clearError();
      const now = new Date().toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit'});
      el('statSync').textContent = now;
      setSyncStatus('połączono · ' + now, false);
      refreshScrollBtn();
      // Diagnostyczny podgląd zdjęć nie jest pobierany automatycznie.
    }catch(err){
      if(requestSeq !== folderSyncSeq) return;
      setSyncStatus('błąd synchronizacji', false);
      writeDebug([
        `[${now0}] BŁĄD synchronizacji`,
        `folder pytany (parentId): ${parentId}`,
        `komunikat: ${err.message || 'nieznany błąd'}`
      ]);
      showError(
        (err.message || 'Nieznany błąd') +
        '<br><br>Sprawdź, czy: 1) na serwerze istnieje katalog uploads/photos/, 2) Apache i PHP mają prawo go odczytać, 3) pliki gallery.php i photo.php leżą obok index.php.'
      );
    }finally{
      if(requestSeq === folderSyncSeq) backgroundSyncInFlight = false;
    }
  }

  function startSync(manual){
    if(!state.rootId || !state.apiKey){ renderEmpty(); return; }
    renderSkeleton();
    fetchFolder(manual);
    if(state.polling) clearInterval(state.polling);
    state.polling = setInterval(() => { fetchFolder(false); }, 120000); // co 2 minuty
  }

  // ---------- theme toggle ----------
  const themeToggleBtn = document.getElementById('themeToggle');
  if(themeToggleBtn){
    themeToggleBtn.addEventListener('click', () => {
      const isLight = document.documentElement.getAttribute('data-theme') === 'light';
      if(isLight){
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('zmogilna-theme', 'dark');
      }else{
        document.documentElement.setAttribute('data-theme', 'light');
        localStorage.setItem('zmogilna-theme', 'light');
      }
    });
  }

  // ---------- przycisk strona główna (obok motywu) ----------
  const homeToggleBtn = document.getElementById('homeToggle');
  if(homeToggleBtn){
    homeToggleBtn.addEventListener('click', e => {
      // jeśli jesteśmy już na index — wróć do katalogu głównego bez przeładowania
      const path = (location.pathname || '').replace(/\/+$/, '');
      const onIndex = path === '' || path.endsWith('/index.php') || path.endsWith('/index.html') || path === '/' || /\/$/.test(location.pathname);
      if(onIndex){
        e.preventDefault();
        if(el('lightbox') && el('lightbox').classList.contains('open')){
          closeLightboxNow();
        }
        galleryQuery = '';
        globalSearchPhotos = null;
        const search = el('gallerySearch');
        if(search){
          search.value = '';
          // przycisk „×” ma zniknąć razem z tekstem
          const clearBtn = el('gallerySearchClear');
          if(clearBtn) clearBtn.hidden = true;
        }
        state.path = [];
        fetchFolder(true);
        replaceGalleryHistory();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
      // w przeciwnym razie zwykły href → index.php
    });
  }

  // ---------- area map: zoom + klik w etykietę gminy → folder ----------
  const mapCard = document.getElementById('mapCard');
  if(mapCard){
    mapCard.addEventListener('click', e => {
      const label = e.target.closest && e.target.closest('.c-town-label, .c-mogilno-label');
      if(label){
        e.stopPropagation();
        const name = (label.textContent || '').trim();
        if(name) openFolderByName(name);
        return;
      }
      mapCard.classList.toggle('zoomed');
    });
  }

  async function openFolderByName(name){
    const needle = name.trim().toLowerCase();
    if(!needle) return;
    // szukaj w rootFolders lub dociągnij listę
    let folders = state.rootFolders || [];
    if(!folders.length && state.rootId){
      try{
        const q = encodeURIComponent(`'${state.rootId}' in parents and trashed = false and mimeType = '${FOLDER_MIME}'`);
        const fields = encodeURIComponent('files(id,name)');
        const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=200`;
        const res = await fetch(url, { cache:'no-store' });
        const data = await res.json();
        folders = data.files || [];
        state.rootFolders = folders;
      }catch(_){ folders = []; }
    }
    const match = folders.find(f => {
      const n = String(f.name||'').toLowerCase();
      return n === needle || n.includes(needle) || needle.includes(n);
    });
    if(!match){
      // delikatny feedback — przewiń do galerii
      const g = document.getElementById('galeria') || el('galleryRoot');
      if(g) g.scrollIntoView({ behavior:'smooth', block:'start' });
      return;
    }
    state.path = [{ id: match.id, name: match.name }];
    fetchFolder(true);
    pushGalleryHistory();
    const g = document.getElementById('galeria') || el('galleryRoot');
    if(g) g.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  // ---------- pływający przycisk powrotu na górę ----------
  let refreshScrollBtn = () => {};
  const scrollTopBtn = document.getElementById('scrollTopBtn');
  if(scrollTopBtn){
    let ticking = false;
    function updateScrollBtn(){
      if(state.path.length === 0){
        // na stronie głównej (poza folderem) przycisk się nie pokazuje
        scrollTopBtn.classList.remove('visible');
        ticking = false;
        return;
      }
      const scrollable = document.documentElement.scrollHeight - window.innerHeight;
      const threshold = Math.max(1100, window.innerHeight * 1.6);
      // pokaż tylko, gdy jest naprawdę sporo do przewinięcia i faktycznie sporo przewinięto
      const show = scrollable > threshold * 1.4 && window.scrollY > threshold;
      scrollTopBtn.classList.toggle('visible', show);
      ticking = false;
    }
    refreshScrollBtn = updateScrollBtn;
    window.addEventListener('scroll', () => {
      if(!ticking){ window.requestAnimationFrame(updateScrollBtn); ticking = true; }
    }, { passive:true });
    window.addEventListener('resize', () => { if(!ticking){ window.requestAnimationFrame(updateScrollBtn); ticking = true; } });
    updateScrollBtn();
    scrollTopBtn.addEventListener('click', () => {
      window.scrollTo({ top:0, behavior:'smooth' });
    });
  }

  // ---------- mini-gra: zgadnij gminę ----------
  (function(){
    const dlg = el('guessDialog');
    const openBtn = el('guessGameBtn');
    const closeBtn = el('guessCloseBtn');
    const photoImg = el('guessPhotoImg');
    const feedbackEl = el('guessFeedbackEl');
    const optionsEl = el('guessOptionsEl');
    const nextBtn = el('guessNextBtn');
    const streakEl = el('guessStreakEl');
    const bestEl = el('guessBestEl');
    if(!dlg || !openBtn) return;

    const BEST_KEY = 'zmogilna-guess-best';
    let streak = 0;
    let best = 0;
    try{ best = parseInt(localStorage.getItem(BEST_KEY), 10) || 0; }catch(_){}
    if(bestEl) bestEl.textContent = best;

    let pool = null;        // [{photo, gmina}, ...] po zbudowaniu
    let gminy = [];         // unikalne nazwy gmin w puli
    let poolPromise = null;
    let current = null;     // {photo, correct, options}
    let answered = false;

    function shuffle(arr){
      const a = arr.slice();
      for(let i = a.length - 1; i > 0; i--){
        const r = crypto.getRandomValues(new Uint32Array(1))[0] / 4294967296;
        const j = Math.floor(r * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
      }
      return a;
    }

    async function buildPool(){
      if(poolPromise) return poolPromise;
      poolPromise = (async () => {
        let folders = state.rootFolders;
        if(!folders || !folders.length){
          try{
            const q = encodeURIComponent(`'${state.rootId}' in parents and trashed = false and mimeType = '${FOLDER_MIME}'`);
            const fields = encodeURIComponent('files(id,name)');
            const url = `${CONFIG.api}?q=${q}&fields=${fields}&pageSize=200`;
            const res = await fetch(url, { cache:'no-store' });
            const data = await res.json();
            folders = data.files || [];
          }catch(_){ folders = []; }
        }
        const results = await Promise.all(folders.map(async f => {
          try{
            const photos = await walkFolderPhotos(f.id);
            return photos.map(p => ({ photo:p, gmina:f.name }));
          }catch(_){ return []; }
        }));
        pool = results.flat();
        gminy = folders.map(f => f.name);
        return pool;
      })();
      return poolPromise;
    }

    function pickRound(){
      if(!pool || !pool.length || gminy.length < 2) return null;
      const item = pool[Math.floor(Math.random() * pool.length)];
      const wrongCandidates = gminy.filter(g => g !== item.gmina);
      const wrongs = shuffle(wrongCandidates).slice(0, Math.min(3, wrongCandidates.length));
      const options = shuffle([item.gmina, ...wrongs]);
      return { photo: item.photo, correct: item.gmina, options };
    }

    function renderLoading(){
      optionsEl.innerHTML = `<div class="guess-loading">wczytuję zdjęcia z okolicy…</div>`;
      photoImg.removeAttribute('src');
      feedbackEl.className = 'guess-feedback';
      feedbackEl.textContent = '';
      nextBtn.style.display = 'none';
    }

    function renderRound(){
      current = pickRound();
      answered = false;
      nextBtn.style.display = 'none';
      feedbackEl.className = 'guess-feedback';
      feedbackEl.textContent = '';
      if(!current){
        optionsEl.innerHTML = `<div class="guess-loading">za mało zdjęć rozłożonych po gminach, żeby zagrać</div>`;
        photoImg.removeAttribute('src');
        return;
      }
      photoImg.src = bigThumb(current.photo.thumbnailLink);
      photoImg.alt = '';
      optionsEl.innerHTML = current.options.map(name => `
        <button type="button" class="guess-opt" data-name="${escapeHtml(name)}">${escapeHtml(name)}</button>
      `).join('');
      optionsEl.querySelectorAll('.guess-opt').forEach(b => {
        b.addEventListener('click', () => onAnswer(b));
      });
    }

    function onAnswer(btnEl){
      if(answered || !current) return;
      answered = true;
      const isCorrect = btnEl.dataset.name === current.correct;

      optionsEl.querySelectorAll('.guess-opt').forEach(b => {
        b.disabled = true;
        if(b.dataset.name === current.correct) b.classList.add('correct-answer');
        else if(b === btnEl) b.classList.add('wrong-answer');
      });

      feedbackEl.textContent = isCorrect ? 'Trafiono!' : `To była gmina: ${current.correct}`;
      feedbackEl.className = 'guess-feedback show ' + (isCorrect ? 'correct' : 'incorrect');

      if(isCorrect){
        streak++;
        if(streak > best){
          best = streak;
          try{ localStorage.setItem(BEST_KEY, String(best)); }catch(_){}
          if(bestEl) bestEl.textContent = best;
        }
      }else{
        streak = 0;
      }
      if(streakEl) streakEl.textContent = streak;
      nextBtn.style.display = 'block';
    }

    async function openGame(){
      try{ dlg.showModal(); }catch(_){ dlg.setAttribute('open',''); }
      if(streakEl) streakEl.textContent = streak;
      if(!pool){
        renderLoading();
        await buildPool();
      }
      renderRound();
    }

    openBtn.addEventListener('click', openGame);
    closeBtn.addEventListener('click', () => dlg.close());
    nextBtn.addEventListener('click', renderRound);
    dlg.addEventListener('click', e => { if(e.target === dlg) dlg.close(); });
  })();

  // ---------- mini-gra: znajdź na mapie (iframe w popupie) ----------
  (function(){
    const dlg = el('findMapDialog');
    const openBtn = el('findMapBtn');
    const closeBtn = el('findMapCloseBtn');
    const frame = el('findMapFrame');
    if(!dlg || !openBtn) return;
    function openGame(){
      try{ dlg.showModal(); }catch(_){ dlg.setAttribute('open',''); }
      // Gra startuje samoczynnie przy każdym załadowaniu iframe, więc
      // przeładowujemy ją przy otwarciu — popup zawsze zaczyna świeżą rundę.
      if(frame){
        frame.src = 'zgadnij-miejscowosc/zgadnij-miejscowosc.php?embed=1&t=' + Date.now();
      }
    }
    openBtn.addEventListener('click', openGame);
    if(closeBtn) closeBtn.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', e => { if(e.target === dlg) dlg.close(); });
  })();

  // ---------- pasek narzędzi galerii ----------
  (function(){
    const search = el('gallerySearch');
    const sort = el('gallerySort');
    // Przyciski gęstości są w dwóch miejscach: w pasku narzędzi (desktop)
    // i w wstawce między folderami a zdjęciami (telefon). Dlatego NIE
    // wiążemy ich po id — szukamy po data-density i klikamy przez
    // delegację na document. Dzięki temu każdy render tworzy nowe
    // przyciski, które od razu działają, bez ponownego podpinania.
    function syncDensityButtons(){
      document.querySelectorAll('.gallery-view-btn[data-density]').forEach(b => {
        b.classList.toggle('active', b.dataset.density === gridDensity);
      });
    }
    // Oba zestawy (pasek + wstawka mobilna) kopiujemy z jednego szablonu.
    function fillDensityButtons(){
      const tpl = el('viewGroupTemplate');
      if(!tpl || !tpl.content) return;
      const toolbar = el('galleryToolbarViewGroup');
      if(toolbar && !toolbar.children.length){
        toolbar.appendChild(tpl.content.cloneNode(true));
      }
    }
    fillDensityButtons();
    // Handler siedzi na document, bo przyciski powstają od nowa przy każdym
    // renderze (nie ma ich w DOM na stałe). Ograniczamy go jednak do samej
    // galerii — bez tego dotknięcie palcem gdziekolwiek na stronie, np. przy
    // przewijaniu, mogło w niego przypadkowo trafić.
    document.addEventListener('click', e => {
      const host = e.target.closest && e.target.closest('#galleryRoot, #galleryToolbarViewGroup');
      if(!host) return;
      const btn = e.target.closest && e.target.closest('.gallery-view-btn[data-density]');
      if(!btn) return;
      setDensity(btn.dataset.density);
    });
    if(sort){
      sort.value = gallerySort;
      sort.addEventListener('change', () => {
        gallerySort = sort.value;
        // Bez zapisu do localStorage: wybór ma przetrwać tylko do odświeżenia
        // strony, a przy kolejnym wejściu wracamy do „najnowsze”.
        if(state.items && state.items.length) renderContents(state.items);
      });
    }
    window.addEventListener('pageshow', () => {
      gallerySort = 'newest';
      const s = el('gallerySort');
      if (s) s.value = 'newest';
      if (state && state.items && state.items.length) renderContents(state.items);
    });
    let searchTimer;
    if(search){
      search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        if(search.value.trim()) setSearching(true);
        searchTimer = setTimeout(() => {
          galleryQuery = search.value || '';
          if(!galleryQuery.trim()){
            globalSearchPhotos = null;
            if(state.items && state.items.length) renderContents(state.items);
            return;
          }
          // szukaj w całej galerii (wszystkie gminy + bieżący folder)
          runGlobalPhotoSearch(galleryQuery);
        }, 350);
      });
      search.addEventListener('keydown', e => {
        if(e.key === 'Escape'){ clearSearch(); }
      });

      // Przycisk „×” w polu wyszukiwania (widoczny tylko z wpisanym tekstem).
      // Robi dokładnie to samo, co Escape — jedna funkcja, jeden zestaw
      // reguł, więc nie rozjadą się przy przyszłych zmianach.
      const clearBtn = el('gallerySearchClear');
      // Klasa .searching wylacza animacje kafelkow na czas wyszukiwania
      // (patrz regula .searching .card w CSS).
      function setSearching(on){
        if(on) document.body.classList.add('searching');
        else document.body.classList.remove('searching');
      }
      function syncClearBtn(){
        if(!clearBtn) return;
        clearBtn.hidden = !search.value;
        if(!search.value.trim()) setSearching(false);
      }
      function clearSearch(){
        search.value = '';
        galleryQuery = '';
        globalSearchPhotos = null;
        clearTimeout(searchTimer);
        setSearching(false);
        syncClearBtn();
        if(state.items && state.items.length) renderContents(state.items);
        search.focus();
      }
      if(clearBtn){
        clearBtn.addEventListener('click', clearSearch);
        // Safari/iOS potrafi nie pokazać :placeholder-shown dla type=search,
        // dlatego stan przycisku synchronizujemy też zdarzeniami.
        search.addEventListener('input', syncClearBtn);
        search.addEventListener('search', syncClearBtn);
        syncClearBtn();
      }
    }
    function setDensity(d, skipRender){
      gridDensity = d;
      try{ localStorage.setItem('zmogilna-grid-density', d); }catch(_){}
      // Oba zestawy (pasek narzędzi + wstawka mobilna) mają pokazywać stan.
      syncDensityButtons();
      // Przycisk wygrywa z pinch: kasujemy inline --grid-min z .grid,
      // inaczej zapis z uszczypniecia blokowalby klasy .compact/.comfortable.
      if(window.zmClearPinchOverride) window.zmClearPinchOverride();
      const homeStripsEl = (state.path.length === 0) ? el('recentByFolder') : null;
      if(homeStripsEl){
        // Strona główna: zmieniamy tylko rozmiar miniatur w paskach gmin, bez
        // ponownego pobierania i bez migania szkieletów.
        homeStripsEl.classList.toggle('compact', d === 'compact');
        homeStripsEl.classList.toggle('comfortable', d === 'comfortable');
      }else if(!skipRender && state.items && state.items.length) renderContents(state.items);
      // render tworzy przyciski od nowa — trzeba zaznaczyć aktywny po nim.
      syncDensityButtons();
    }
    // Gest uszczypniecia na siatce korzysta z tych samych trzech poziomow,
    // wiec wystawiamy je globalnie — inaczej nie mialby jak ustawic gestosci.
    window.zmSetDensity = setDensity;
    window.zmCurrentDensity = () => gridDensity;
    // Przyciski gęstości w renderze mobilnym powstają od nowa przy każdym
    // renderze (szablon nie ma klas „active”), więc stan aktywny trzeba
    // nałożyć PO renderze. Bez tego podświetlenie znikało po zmianie
    // folderu, sortowaniu albo wyszukaniu — a przy domyślnym (niezmienionym)
    // ustawieniu nie pojawiało się wcale.
    window.zmSyncDensityButtons = syncDensityButtons;
    syncDensityButtons();
    setDensity(gridDensity);
  })();

  // Panel „WSPÓLNY ARCHIWUM” jest w całości nieklikalny (same dane), a na
  // telefonie system potrafi potraktować zwykły tekst jako adres: podświetla
  // go, rysuje kreski i po przytrzymaniu proponuje „Wygoogluj”, co otwiera
  // Google Maps z zapytaniem złożonym z treści karty. CSS (user-select,
  // tap-highlight, touch-callout) nie wystarcza w każdej przeglądarce,
  // więc blokujemy to też na poziomie zdarzeń. `select` na <input> zostaje
  // nietknięty, bo tam zaznaczanie jest potrzebne.
  (function(){
    const sel = '.community-panel';
    document.addEventListener('selectstart', e => {
      if(e.target.closest && e.target.closest(sel)) e.preventDefault();
    });
    document.addEventListener('contextmenu', e => {
      if(e.target.closest && e.target.closest(sel)) e.preventDefault();
    });
  })();

  // ---------- „WSPÓLNY ARCHIWUM” odświeżane bez przeładowania strony ----------
  // Ten sam wzorzec co na stronie profilu: odpytujemy endpoint co 20 s i
  // podmieniamy HTML tylko wtedy, gdy zmieniła się sygnatura treści (hash).
  // Dzięki temu nowe polubienie widać od razu, a zwykłe odpytywanie niczego
  // nie przestawia — bez podmiany obrazy by się ładowały od nowa co 20 s.
  (function(){
    const URL_API = 'community-api.php';
    const POLL_MS = 20000;
    let busy = false;
    let timer = null;

    function currentSection(){ return document.getElementById('communityLive'); }

    function applySection(html){
      const old = currentSection();
      if(!old) return;
      // Zapamiętujemy pozycję sekcji na ekranie: po podmianie jej wysokość może
      // się zmienić (np. ranking skoczył o jedną pozycję) i bez tego cała
      // strona przesunęłaby się pod palcem.
      const anchorTop = old.getBoundingClientRect().top;
      old.outerHTML = html;
      // sekcja pod dolną krawędzią ekranu nie przesuwa widocznej treści —
      // pomijamy drugi odczyt geometrii (wymuszone przeformatowanie przy starcie)
      if(anchorTop > window.innerHeight) return;
      const next = currentSection();
      if(next){
        const shift = next.getBoundingClientRect().top - anchorTop;
        if(shift) window.scrollBy(0, shift);
      }
    }

    function refreshCommunity(){
      if(busy) return;
      if(document.visibilityState === 'hidden') return;
      const section = currentSection();
      if(!section) return;
      busy = true;
      fetch(URL_API + '?t=' + Date.now(), { headers:{ Accept:'application/json' }, credentials:'same-origin' })
        .then(function(res){
          if(!res.ok) throw new Error('status ' + res.status);
          return res.json();
        })
        .then(function(data){
          if(!data || !data.ok || !data.html) return;
          if(data.hash === section.getAttribute('data-hash')) return;
          applySection(data.html);
        })
        .catch(function(){})
        .then(function(){ busy = false; });
    }

    // Pierwsze odpytywanie od razu po wejściu na stronę: jeśli panel zdążył
    // się zmienić między renderem a startem skryptu, poprawi się od razu.
    refreshCommunity();
    timer = setInterval(refreshCommunity, POLL_MS);

    // Powrót na kartę / powrót do okna — tak samo jak na profilu.
    document.addEventListener('visibilitychange', function(){
      if(document.visibilityState === 'visible') refreshCommunity();
    });
    window.addEventListener('focus', refreshCommunity);
    window.addEventListener('pageshow', refreshCommunity);
    window.addEventListener('pagehide', function(){ if(timer) clearInterval(timer); });
  })();

  // ---------- „ATRAKCJE TURYSTYCZNE” odświeżane bez przeładowania strony ----------
  // Ten sam wzorzec co sekcja „WSPÓLNY ARCHIWUM”: odpytujemy endpoint co 20 s
  // i podmieniamy HTML tylko wtedy, gdy zmieniła się sygnatura treści (hash),
  // więc nowe oznaczone zdjęcia pojawiają się same, a zwykłe odpytywanie nic
  // nie przestawia. Gdy sekcja jest wyłączona w konfiguracji, nie ma jej w DOM
  // i pętla nic nie robi.
  (function(){
    const URL_API = 'attraction-api.php';
    const POLL_MS = 20000;
    let busy = false;
    let timer = null;

    function currentSection(){ return document.getElementById('attractionLive'); }

    function applySection(html){
      const old = currentSection();
      if(!old) return;
      // Zachowujemy pozycję sekcji na ekranie — po podmianie wysokość może się
      // zmienić i bez tego cała strona przesunęłaby się pod palcem.
      const anchorTop = old.getBoundingClientRect().top;
      old.outerHTML = html;
      // sekcja pod dolną krawędzią ekranu nie przesuwa widocznej treści —
      // pomijamy drugi odczyt geometrii (wymuszone przeformatowanie przy starcie)
      if(anchorTop > window.innerHeight) return;
      const next = currentSection();
      if(next){
        const shift = next.getBoundingClientRect().top - anchorTop;
        if(shift) window.scrollBy(0, shift);
      }
    }

    function refreshAttraction(){
      if(busy) return;
      if(document.visibilityState === 'hidden') return;
      const section = currentSection();
      if(!section) return;
      busy = true;
      fetch(URL_API + '?t=' + Date.now(), { headers:{ Accept:'application/json' }, credentials:'same-origin' })
        .then(function(res){
          if(!res.ok) throw new Error('status ' + res.status);
          return res.json();
        })
        .then(function(data){
          if(!data || !data.ok || !data.html) return;
          if(data.hash === section.getAttribute('data-hash')) return;
          applySection(data.html);
        })
        .catch(function(){})
        .then(function(){ busy = false; });
    }

    refreshAttraction();
    timer = setInterval(refreshAttraction, POLL_MS);

    document.addEventListener('visibilitychange', function(){
      if(document.visibilityState === 'visible') refreshAttraction();
    });
    window.addEventListener('focus', refreshAttraction);
    window.addEventListener('pageshow', refreshAttraction);
    window.addEventListener('pagehide', function(){ if(timer) clearInterval(timer); });
  })();

  // ---------- deep link ?photo=ID oraz wyróżnione zdjęcie ----------
  async function tryOpenPhotoFromUrl(){
    let photoId = null, commentId = null;
    try{
      const sp = new URL(location.href).searchParams;
      photoId = sp.get('photo');
      profileReturnId = parseInt(sp.get('profile_id') || '', 10);
      if(!(profileReturnId > 0)) profileReturnId = null;
      // ?return= — adres powrotny (atrakcje.php z filtrem). Akceptujemy tylko
      // własny host i ścieżkę atrakcje.php, żeby nie dało się tu wstrzyknąć
      // zewnętrznego przekierowania. Zapisujemy samą ścieżkę+zapytanie.
      const retRaw = sp.get('return');
      if(retRaw){
        try{
          const ru = new URL(retRaw, location.href);
          if(ru.origin === location.origin && ru.pathname.replace(/\/+$/,'').endsWith('/atrakcje.php')){
            lightboxReturnUrl = ru.pathname + ru.search;
          }
        }catch(_){}
      }
      commentId = parseInt(sp.get('comment') || '', 10);   // link z panelu admina: zdjęcie + konkretny komentarz
      if(!(commentId > 0)) commentId = null;
    }catch(_){}
    if(!photoId && location.hash.startsWith('#photo=')){
      photoId = location.hash.slice(7);
    }
    if(!photoId || !state.rootId || !state.apiKey) return false;
    try{
      const fields = encodeURIComponent('id,name,mimeType,thumbnailLink,createdTime,imageMediaMetadata');
      const url = `${CONFIG.api}?file=${encodeURIComponent(photoId)}&fields=${fields}`;
      const res = await fetch(url, { cache:'no-store' });
      const file = await res.json();
      if(!res.ok || !file || !file.id) return false;
      if(profileReturnId){
        const profileRes = await fetch('profile-api.php?action=profile&id=' + profileReturnId, { cache:'no-store' });
        const profileData = await profileRes.json();
        const profilePhotos = profileData && profileData.ok && Array.isArray(profileData.photos) ? profileData.photos : [];
        const profileIndex = profilePhotos.findIndex(item => item.id === photoId);
        if(profileIndex >= 0){
          openLightbox(profilePhotos, profileIndex, commentId ? { openComments:true, focusCommentId: commentId } : undefined);
          return true;
        }
      }
      openLightbox([file], 0, commentId ? { openComments:true, focusCommentId: commentId } : undefined);
      return true;
    }catch(_){ return false; }
  }

  async function renderFeatured(){
    const slot = el('featuredSlot');
    if(!slot) return;
    const fid = (CONFIG.featuredPhotoId || '').trim();
    if(!fid || !state.apiKey){ slot.innerHTML = ''; return; }
    try{
      const fields = encodeURIComponent('id,name,mimeType,thumbnailLink,createdTime');
      const url = `${CONFIG.api}?file=${encodeURIComponent(fid)}&fields=${fields}`;
      const res = await fetch(url, { cache:'no-store' });
      const file = await res.json();
      if(!res.ok || !file.id){ slot.innerHTML = ''; return; }
      slot.innerHTML = `
        <div class="featured-block" id="featuredBlock" role="button" tabindex="0" aria-label="Zdjęcie wyróżnione">
          <img src="${bigThumb(file.thumbnailLink, 1200)}" alt="${escapeHtml(file.name || '')}" loading="lazy">
          <div class="featured-cap"><b>Wyróżnione</b>${escapeHtml((file.name || '').replace(/\.[^/.]+$/, ''))}</div>
        </div>`;
      const open = () => openLightbox([file], 0);
      const block = el('featuredBlock');
      block.addEventListener('click', open);
      block.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    }catch(_){ slot.innerHTML = ''; }
  }

  // ================================================================
  //  nowości: nawigator ⌘K, magnetyczny przycisk, płynne wczytywanie
  // ================================================================

  // ---------- płynne pojawianie się zdjęć po wczytaniu ----------
  galleryRoot.addEventListener('load', (e) => {
    if(e.target && e.target.tagName === 'IMG') e.target.classList.add('loaded');
  }, true);

  // ---------- magnetyczna poświata (dowolny element .magnetic) ----------
  if(window.matchMedia('(hover:hover) and (pointer:fine)').matches){
    document.addEventListener('pointermove', (e) => {
      const btn = e.target.closest && e.target.closest('.magnetic');
      if(!btn) return;
      const r = btn.getBoundingClientRect();
      btn.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      btn.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
  }

  // start
  renderFavoritesSection();
  syncFavoritesWithServer(true);
  renderRecentCommentsSection();
  try{ history.replaceState({ type:'gallery', path: [] }, '', location.href); }catch(_){}
if(state.rootId && state.apiKey){
    startSync(false);
    renderFeatured();
    // deep link  ?" po krƈtkiej chwili,   eby nie kolidowa   z pierwszym renderem
    setTimeout(() => { tryOpenPhotoFromUrl(); }, 400);
  }else{
    renderEmpty();
  }
})();

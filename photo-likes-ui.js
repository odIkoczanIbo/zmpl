/* =====================================================================
   photo-likes-ui.js — polubienia zdjęć
   Wycięte z index.php; ładowane z defer jako ostatnie (korzysta z
   lightboxa z gallery-ui.js). Przycisk w lightboxie: liczba polubień + stan,
   wymaga zalogowanego konta. API: photo-likes-api.php.
   ===================================================================== */
/* ------------------------------------------------------------------
   Polubienia zdjęć (osobny, prawdziwy system od ulubionych).
   Przycisk w lightboxie: liczba + stan, wymaga zalogowanego konta.
   API: photo-likes-api.php. +1 pkt za polubienie, -1 za cofnięcie.
   ------------------------------------------------------------------ */
(function(){
  const el = id => document.getElementById(id);
  const likeBtn = el('lightboxLikeBtn');
  const countEl = el('lightboxLikeCount');
  if(!likeBtn) return;

  let loggedIn = !!(lastWho && lastWho.user);
  let current = '';                 // aktualne zdjęcie w lightboxie ('p12')
  const liked = {};                 // photoId -> bool
  const counts = {};                // photoId -> liczba polubień

  window.addEventListener('zm:auth', e => { loggedIn = !!(e && e.detail && e.detail.user); if(loggedIn) refresh(); });
  window.addEventListener('zm:logout', () => { loggedIn = false; render(); });

  function csrf(){ return csrfToken || ''; }

  function render(){
    if(!current) return;
    likeBtn.classList.toggle('is-liked', !!liked[current]);
    if(countEl){
      const n = counts[current] || 0;
      countEl.textContent = n;
      countEl.style.display = n ? 'flex' : 'none';
    }
    likeBtn.title = loggedIn
      ? (liked[current] ? 'Cofnij polubienie' : 'Polub zdjęcie')
      : 'Zaloguj się, żeby polubić';
  }

  async function refresh(){
    if(!current) return;
    try{
      const res = await fetch('photo-likes-api.php?action=count&photo=' + encodeURIComponent(current), { credentials:'include' });
      const d = await res.json();
      if(d && typeof d.count === 'number'){
        counts[current] = d.count;
        liked[current] = !!d.liked;
        render();
      }
    }catch(_){}
  }

  window.zmUpdatePhotoLike = function(id){
    const next = String(id || '');
    if(next === current) return;
    current = next;
    refresh();
  };

  likeBtn.addEventListener('click', async () => {
    if(!loggedIn){
      const t = el('acctToggle');
      if(t) t.click();
      return;
    }
    if(!current) return;
    try{
      const res = await fetch('photo-likes-api.php', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'toggle', photo: current, csrf: csrf() })
      });
      if(res.status === 401){
        loggedIn = false;
        const t = el('acctToggle');
        if(t) t.click();
        return;
      }
      const d = await res.json();
      if(d && d.ok){
        liked[current] = !!d.liked;
        counts[current] = d.count;
        render();
      }
    }catch(_){}
  });

  refresh();
})();

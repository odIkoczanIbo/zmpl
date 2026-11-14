/* =====================================================================
   account-ui.js — konto użytkownika i kontakt
   Wycięte z index.php; ładowane z defer, PRZED gallery-ui.js i photo-likes-ui.js.
   Kolejność ma znaczenie: poniżej są globalne `csrfToken` i `lastWho`,
   z których korzysta gallery-ui.js (komentarze, ulubione, zgłoszenia zdjęć).

   Zawartość:
     - okno konta (acctDialog): logowanie, rejestracja, reset hasła,
       widok zalogowanego, przekierowanie wg roli, ?login=1 w adresie
     - whoAmI(): sprawdzenie sesji (auth-api.php?action=whoami)
     - dzwonek powiadomień i plakietka wiadomości (cykliczne odpytywanie)
     - przycisk „dodaj zdjęcie” zależny od stanu logowania
     - popup „kontakt” w stopce (zalogowany: wiadomość z konta,
       gość: przez guest-contact.php)
   ===================================================================== */
// csrfToken i lastWho muszą być widoczne dla obu IIFE niżej — panel komentarzy
// (osobny IIFE) używa tokenu przy zapisie i sprawdza sesję przy renderze formularza.
// Trzymane w zmiennych zadeklarowanych na poziomie skryptu, inaczej rzucają
// ReferenceError i panel zostaje pusty.
let csrfToken = '';
let lastWho = null;

(function(){
  const el = id => document.getElementById(id);
  const dlg = el('acctDialog');
  const toggleBtn = el('acctToggle');

  function openDialog(){
    if(typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
  }
  toggleBtn.addEventListener('click', () => {
    if(lastWho && lastWho.user){ renderLoggedIn(lastWho); }
    openDialog();
  });
  el('acctCloseBtn').addEventListener('click', () => dlg.close());
  // Kliknięcie w przyciemnione tło nie zamyka formularza: przypadkowe
  // dotknięcie obok okna nie może skasować wpisanych danych ani komunikatu.

  // Nazwa zakładki = id jej panelu po słowie "acctPane". Wcześniej wybór był
  // binarny ("login" czy "register"), więc trzecia zakładka i tak trafiłaby
  // do panelu rejestracji.
  function showTab(name){
    const pane = el('acctPane' + name.charAt(0).toUpperCase() + name.slice(1));
    if(!pane) return;
    document.querySelectorAll('.acct-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.acct-pane').forEach(p => p.classList.remove('active'));
    pane.classList.add('active');
    el('acctMsg').className = 'acct-msg';
    el('acctMsg').textContent = '';
  }

  document.querySelectorAll('.acct-tab').forEach(tab => {
    tab.addEventListener('click', () => showTab(tab.dataset.tab));
  });

  function showMsg(text, ok){
    const m = el('acctMsg');
    m.className = 'acct-msg ' + (ok ? 'ok' : 'err');
    m.textContent = text;
  }

  function initialsOf(name){
    const parts = String(name || '').trim().split(/\s+/);
    if(parts.length === 0 || !parts[0]) return '–';
    return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
  }

  function redirectByRole(role){
    window.location.href = role === 'admin' ? 'admin.php' : 'panel.php';
  }

  function renderLoggedIn(data){
    el('acctLoggedOutView').style.display = 'none';
    el('acctLoggedInView').style.display = 'block';
    const name = data.display_name || data.user;
    el('acctAvatarInitials').textContent = initialsOf(name);
    // własny awatar; avatar.php nigdy nie odmawia właścicielowi zdjęcia
    const avatarUrl = data.avatar || '';
    el('acctAvatarPhoto').style.backgroundImage = avatarUrl ? 'url("' + avatarUrl + '")' : '';
    el('acctAvatarInitials').hidden = !!avatarUrl;
    el('acctLoggedName').textContent = name;
    el('acctLoggedRole').textContent = '@' + data.user + (data.role === 'admin' ? ' · administrator' : ' · użytkownik');
    // administrator ma oba wejścia: swój zwykły panel i panel administracyjny
    el('acctGoPanel').href = 'panel.php';
    el('acctGoAdmin').hidden = data.role !== 'admin';
    // adres publicznego profilu liczy ten sam helper co galeria (profileUrl)
    const goProfile = el('acctGoProfile');
    if(goProfile) goProfile.href = profileUrl(data.user);
    toggleBtn.classList.add('is-logged-in');
    toggleBtn.setAttribute('aria-label', 'Konto, zalogowano');
    toggleBtn.title = 'Twoje konto';
  }

  // Pozycje menu konta zależą od logowania (dzwonek, wiadomości). Obie gry
  // w hero są dla wszystkich, więc nie ma tu już przycisku „dodaj zdjęcie”.
  // Wołane z whoami() przy wejściu i przy zalogowaniu; wylogowanie zgłasza
  // osobne zdarzenie, więc dopilnowujemy i tego.
  function syncAddPhotoCta(){
    // pozycje menu konta pokazujemy tylko przy zalogowanym koncie
    const bell = el('notifyBell');
    if(bell) bell.hidden = !(lastWho && lastWho.user);
    // pozycja „Wiadomości” — ten sam warunek co „Powiadomienia” wyżej
    const msgBtn = el('msgBtn');
    if(msgBtn) msgBtn.hidden = !(lastWho && lastWho.user);
  }
  window.addEventListener('zm:logout', syncAddPhotoCta);

  /*
   * Licznik na dzwoneczku. Odczyt (social-api.php?action=count) to dwa
   * COUNT-y, więc raz na minutę nie obciąża bazy. Gdy karta jest ukryta,
   * odpytywanie w tle nic nie daje — pomijamy je.
   */
  (function bellPoller(){
    const badge = el('bellBadge');
    if(!badge) return;
    let last = -1;   // -1 = jeszcze nie odczytano, więc pierwszy wynik zawsze wypisze
    // pozycja „Wiadomości” w menu konta — osobna plakietka z liczbą samych
    // nieprzeczytanych wiadomości; zmienia się niezależnie od powiadomień
    const msgBadge = el('msgBadge');
    let lastMsg = -1;
    const acctBtn = el('acctToggle');

    async function refresh(){
      if(document.hidden) return;
      if(!(lastWho && lastWho.user)) return;   // gość nie ma skrzynki
      try{
        const res = await fetch('social-api.php?action=count', { credentials:'include' });
        if(res.status === 401) return;
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
        if(acctBtn){
          acctBtn.classList.toggle('has-unread', t > 0);
          acctBtn.setAttribute(
            'aria-label',
            t > 0
              ? `Konto, ${t} nieprzeczytanych wiadomości lub powiadomień`
              : 'Konto, zalogowano'
          );
        }
      }catch(_){ /* brak sieci — zostawiamy poprzednią liczbę */ }
    }

    // pierwszy odczyt zaraz po whoami, potem co minutę
    window.addEventListener('zm:auth', refresh);
    window.addEventListener('zm:logout', () => {
      last = -1;
      badge.textContent = '';
      badge.hidden = true;
      if(acctBtn){
        acctBtn.classList.remove('has-unread');
        acctBtn.setAttribute('aria-label', 'Konto, zaloguj się');
        acctBtn.title = 'Zaloguj się';
      }
      if(msgBadge){
        lastMsg = -1;
        msgBadge.textContent = '';
        msgBadge.hidden = true;
      }
    });
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', () => { if(!document.hidden) refresh(); });
  })();

  async function whoAmI(){
    try{
      const res = await fetch('auth-api.php?action=whoami', { credentials:'include' });
      const data = await res.json();
      csrfToken = data.csrf || '';
      lastWho = data;
      // obie gry w hero są dostępne dla każdego; menu konta (dzwonek,
      // wiadomości) zależy od logowania. Aktualizujemy stan od razu po whoami,
      // żeby pozycje znikały i pojawiały się bez przeładowania strony.
      syncAddPhotoCta();
      if(data.user){
        renderLoggedIn(data);
        // ulubione (IIFE niżej) przełączają się na bazę po zalogowaniu
        window.dispatchEvent(new CustomEvent('zm:auth', { detail:data }));
      }
    }catch(_){}
  }
  whoAmI();

  el('acctLogoutBtn').addEventListener('click', async () => {
    try{ await fetch('auth-api.php?action=logout', { method:'POST', credentials:'include' }); }catch(_){}
    lastWho = null;
    toggleBtn.classList.remove('is-logged-in');
    toggleBtn.classList.remove('has-unread');
    toggleBtn.setAttribute('aria-label', 'Konto, zaloguj się');
    toggleBtn.title = 'Zaloguj się';
    el('acctLoggedOutView').style.display = 'block';
    el('acctLoggedInView').style.display = 'none';
    dlg.close();
    window.dispatchEvent(new CustomEvent('zm:logout'));
  });

  el('acctLoginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    showMsg('', true); el('acctMsg').className = 'acct-msg';
    const user = el('acctLoginUser').value.trim();
    const pass = el('acctLoginPass').value;
    const btn = el('acctLoginSubmit');
    btn.disabled = true; btn.textContent = 'Logowanie…';
    try{
      const res = await fetch('auth-api.php?action=login', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ user, pass })
      });
      const data = await res.json();
      if(res.status === 429 || data.error === 'locked'){
        const min = Math.ceil((data.retry_after || 300) / 60);
        showMsg(`Zbyt wiele nieudanych prób. Spróbuj za ${min} min.`, false);
        return;
      }
      if(!res.ok || !data.ok){
        showMsg(data.message || 'Nieprawidłowy login lub hasło.', false);
        return;
      }
      csrfToken = data.csrf || '';
      redirectByRole(data.role);
    }catch(_){
      showMsg('Błąd połączenia z serwerem.', false);
    }finally{
      btn.disabled = false; btn.textContent = 'Zaloguj';
    }
  });

  el('acctRegisterForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    el('acctMsg').className = 'acct-msg';
    const user = el('acctRegUser').value.trim();
    const email = el('acctRegEmail').value.trim();
    const pass = el('acctRegPass').value;
    const website = el('acctRegWebsite').value;
    const btn = el('acctRegSubmit');
    btn.disabled = true; btn.textContent = 'Zakładanie konta…';
    try{
      const res = await fetch('auth-api.php?action=register', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ username:user, email, password:pass, website })
      });
      const data = await res.json();
      if(!res.ok || !data.ok){
        showMsg(data.message || 'Nie udało się założyć konta.', false);
        return;
      }
      csrfToken = data.csrf || '';
      redirectByRole(data.role);
    }catch(_){
      showMsg('Błąd połączenia z serwerem.', false);
    }finally{
      btn.disabled = false; btn.textContent = 'Załóż konto';
    }
  });

  el('acctResetForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    el('acctMsg').className = 'acct-msg';
    const id = el('acctResetId').value.trim();
    if(!id){ showMsg('Podaj login albo adres e-mail.', false); el('acctResetId').focus(); return; }
    const btn = el('acctResetSubmit');
    btn.disabled = true; btn.textContent = 'Wysyłanie…';
    try{
      const res = await fetch('auth-api.php?action=password_reset_request', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ identifier: id })
      });
      const data = await res.json();
      // 404 to celowa odpowiedź dla konta, którego nie ma. Tekst jest taki sam
      // jak przy sukcesie, więc nie da się sprawdzić, czyje adresy mamy w bazie.
      if(res.status === 404){
        showMsg(data.message || 'Jeśli takie konto istnieje, wysłaliśmy na niego link do ustawienia hasła.', true);
        el('acctResetId').value = '';
        return;
      }
      if(res.status === 429){
        showMsg(data.message || 'Wysłaliśmy już niedawno link na ten adres. Spróbuj za chwilę.', false);
        return;
      }
      if(!res.ok || !data.ok){
        showMsg(data.message || 'Nie udało się wysłać linku.', false);
        return;
      }
      showMsg(data.message || 'Jeśli takie konto istnieje, wysłaliśmy na niego link do ustawienia hasła.', true);
      el('acctResetId').value = '';
    }catch(_){
      showMsg('Błąd połączenia z serwerem.', false);
    }finally{
      btn.disabled = false; btn.textContent = 'Wyślij link';
    }
  });

  // otwórz automatycznie, gdy panel.php przekierował z powodu braku sesji.
  // ?login=1&reset=1 otwiera od razu zakładkę resetu — reset-hasla.php kieruje
  // tam z powrotem, gdy ktoś nie pamięta hasła i trafił na link bez tokenu.
  try{
    const params = new URLSearchParams(window.location.search);
    if(params.get('login') === '1'){
      if(params.get('reset') === '1') showTab('reset');
      openDialog();
    }
  }catch(_){}
})();

/* Popup „kontakt” w stopce.
   ZALOGOWANY wysyła ZE SWOJEGO KONTA — dokładnie jak przycisk
   „Napisz wiadomość” na profilu (social-api.php → action=message_send
   do administratora; zwykły wątek w komunikatorze, dalsza rozmowa
   toczy się normalnie).
   GOŚĆ (bez konta) podaje zamiast nazwy użytkownika po prostu adres
   e-mail — bez walidacji (tak miało być); wysyłka idzie przez
   guest-contact.php ukrytą skrzynką „kontakt”. */
(function(){
  const modal = document.getElementById('contactModal');
  if(!modal) return;
  const emailEl = document.getElementById('contactEmail');
  const bodyEl  = document.getElementById('contactBody');
  const msgEl   = document.getElementById('contactMsg');
  const sendBtn = document.getElementById('contactSend');

  function say(txt, isError){
    msgEl.hidden = !txt;
    msgEl.textContent = txt || '';
    msgEl.classList.toggle('is-error', !!isError);
  }
  function open(){
    say('', false);
    modal.classList.add('open');
    setTimeout(() => { (lastWho && lastWho.user ? bodyEl : emailEl).focus(); }, 50);
  }
  function close(){ modal.classList.remove('open'); }

  const btn = document.getElementById('contactBtn');
  if(btn) btn.addEventListener('click', async () => {
    try{ if(!lastWho) await whoAmI(); }catch(_){}
    // zalogowany pisze ZE SWOJEGO KONTA (jak z „Napisz wiadomość” na
    // profilu) — pole e-mail jest zbędne, więc je chowamy i zostaje
    // sama wiadomość. Gość widzi oba pola.
    const logged = !!(lastWho && lastWho.user);
    const emailLabel = document.querySelector('label[for="contactEmail"]');
    emailEl.style.display = logged ? 'none' : '';
    if(emailLabel) emailLabel.style.display = logged ? 'none' : '';
    open();
  });
  document.getElementById('contactModalClose').addEventListener('click', close);
  modal.addEventListener('click', (e) => { if(e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if(e.key === 'Escape' && modal.classList.contains('open')) close(); });

  sendBtn.addEventListener('click', async () => {
    say('', false);
    sendBtn.disabled = true; sendBtn.textContent = 'Wysyłanie…';
    try{
      if(!csrfToken) await whoAmI();   // token CSRF dostaje też gość
      const logged = !!(lastWho && lastWho.user);
      if(logged){
        // ZALOGOWANY: wysyłka ZE SWOJEGO KONTA — ten sam tor co przycisk
        // „Napisz wiadomość” na profilu (social-api → message_send).
        // Odbiorca: główny administrator (konto `admin`).
        const aRes = await fetch('guest-contact.php?action=admin', { credentials:'include' });
        const aData = await aRes.json().catch(() => null);
        const adminId = aData && aData.admin_id ? Number(aData.admin_id) : 0;
        if(!adminId){
          say('Brak administratora do wysyłki. Spróbuj później.', true);
          return;
        }
        const res = await fetch('social-api.php', {
          method:'POST', credentials:'include',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'message_send', to: adminId, body: bodyEl.value, csrf: csrfToken })
        });
        const data = await res.json().catch(() => null);
        if(!res.ok || !data || !data.ok){
          say((data && data.message) || 'Nie udało się wysłać. Spróbuj ponownie.', true);
          return;
        }
        say('Wysłano — dalsza rozmowa toczy się w komunikatorze.', false);
        bodyEl.value = '';
        setTimeout(close, 1800);
        return;
      }
      // GOŚĆ: e-mail + wiadomość, bez walidacji — wysyłka ukrytą skrzynką.
      const res = await fetch('guest-contact.php', {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ email: emailEl.value, message: bodyEl.value, csrf: csrfToken })
      });
      const data = await res.json().catch(() => null);
      if(!res.ok || !data || !data.ok){
        say((data && data.message) || 'Nie udało się wysłać. Spróbuj ponownie.', true);
        return;
      }
      say('Wysłano — administrator odpowie na podany adres.', false);
      emailEl.value = ''; bodyEl.value = '';
      setTimeout(close, 1800);
    }catch(_){
      say('Błąd połączenia z serwerem.', true);
    }finally{
      sendBtn.disabled = false; sendBtn.textContent = 'Wyślij';
    }
  });
})();

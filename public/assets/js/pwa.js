// PWA + comportamento mobile: service worker, installazione, barra in basso,
// dettaglio commento a tutto schermo, indicatore offline.
(function () {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isSheetMode = () => window.matchMedia('(max-width: 900px)').matches;

  if (isStandalone()) document.documentElement.classList.add('pwa-standalone');

  // ── Service worker ────────────────────────────────────────────────
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
  }

  // ── Installazione ─────────────────────────────────────────────────
  let deferredPrompt = null;
  const installBtn = document.createElement('button');
  installBtn.className = 'install-btn';
  installBtn.type = 'button';
  installBtn.innerHTML =
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>Installa l\'app';
  const footer = $('.sidebar-footer');
  if (footer) footer.insertBefore(installBtn, footer.firstChild);

  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    if (!isStandalone()) installBtn.classList.add('show');
  });
  installBtn.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    try { await deferredPrompt.userChoice; } catch (_) {}
    deferredPrompt = null;
    installBtn.classList.remove('show');
  });
  window.addEventListener('appinstalled', () => installBtn.classList.remove('show'));

  // iPhone/iPad: Safari non ha il prompt nativo → suggerimento una tantum.
  function maybeShowIosHint() {
    const ua = navigator.userAgent || '';
    const isIos = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (!isIos || isStandalone()) return;
    try { if (localStorage.getItem('mh_ios_hint') === '1') return; } catch (_) {}
    const el = document.createElement('div');
    el.className = 'ios-hint show';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Come installare l\'app');
    el.innerHTML =
      '<div style="flex:1;color:var(--muted)"><strong>Installa Moderation Hub</strong><br>' +
      'Tocca <strong>Condividi</strong> (il quadrato con la freccia) e poi <strong>Aggiungi a Home</strong>.</div>' +
      '<button class="ios-hint-x" type="button" aria-label="Chiudi">✕</button>';
    $('.ios-hint-x', el).addEventListener('click', () => {
      try { localStorage.setItem('mh_ios_hint', '1'); } catch (_) {}
      el.remove();
    });
    document.body.appendChild(el);
  }

  // ── Online / offline ──────────────────────────────────────────────
  function setConnectionBadge() {
    const badge = $('.topbar-badge.live') || $('.topbar-badge');
    if (!badge) return;
    const online = navigator.onLine;
    badge.classList.toggle('offline', !online);
    badge.textContent = online ? '● Live' : '○ Offline';
  }
  window.addEventListener('offline', () => {
    setConnectionBadge();
    if (typeof toast === 'function') toast('Sei offline: le azioni non verranno inviate', 'err');
  });
  window.addEventListener('online', () => {
    setConnectionBadge();
    if (typeof toast === 'function') toast('Di nuovo online', 'ok');
    const q = $('#screen-queue');
    if (q && q.classList.contains('active') && typeof loadQueue === 'function') { loadQueue(); loadStats(); }
  });
  // Riaprendo l'app dallo sfondo aggiorna la coda (senza aspettare i 30 s del timer).
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible' || !navigator.onLine) return;
    const q = $('#screen-queue');
    if (typeof loadStats !== 'function' || getComputedStyle($('#login-screen')).display !== 'none') return;
    loadStats();
    if (q && q.classList.contains('active') && typeof loadQueue === 'function') loadQueue();
  });

  // ── Dettaglio commento a tutto schermo (mobile/tablet) ─────────────
  function openDetailSheet() {
    if (document.body.classList.contains('detail-open')) return;
    document.body.classList.add('detail-open');
    try { history.pushState({ sheet: 1 }, ''); } catch (_) {}
    const panel = $('#detail-panel');
    if (panel) panel.scrollTop = 0;
  }
  function closeDetailSheet(fromPop) {
    if (!document.body.classList.contains('detail-open')) return;
    document.body.classList.remove('detail-open');
    if (!fromPop && history.state && history.state.sheet) { try { history.back(); } catch (_) {} }
  }
  window.closeDetailSheet = () => closeDetailSheet(false);
  window.addEventListener('popstate', () => closeDetailSheet(true));

  // Avvolge selectComment (definita in moderation.js): su mobile apre il foglio.
  // Le azioni rapide dalla riga passano { quiet: true } e non aprono nulla.
  if (typeof window.selectComment === 'function') {
    const baseSelect = window.selectComment;
    window.selectComment = function (id, opts) {
      baseSelect(id);
      if (opts && opts.quiet) return;
      if (isSheetMode() && typeof currentComment !== 'undefined' && currentComment) openDetailSheet();
    };
  }
  // Quando il commento esce dalla coda (decisione presa) il pannello si nasconde: chiudi il foglio.
  const detailContent = $('#detail-content');
  if (detailContent) {
    new MutationObserver(() => {
      if (detailContent.style.display === 'none') closeDetailSheet(false);
    }).observe(detailContent, { attributes: true, attributeFilter: ['style'] });
  }
  window.addEventListener('resize', () => { if (!isSheetMode()) closeDetailSheet(false); });

  // ── Barra di navigazione in basso ─────────────────────────────────
  const TABS = [
    { screen: 'queue',           label: 'Coda',    badge: 'nav-queue-count',
      icon: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>' },
    { screen: 'banned-comments', label: 'Nascosti', badge: null,
      icon: '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>' },
    { screen: 'appeals',         label: 'Ricorsi', badge: 'nav-appeals-count',
      icon: '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>' },
    // Menu: il badge conta le segnalazioni pericolose in attesa (la voce "Segnalazioni" è nel menu).
    { screen: null,              label: 'Menu',    badge: 'nav-reportable-count', badgeLabel: 'segnalazioni in attesa',
      icon: '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>' },
  ];
  const tabbar = document.createElement('nav');
  tabbar.id = 'tabbar';
  tabbar.setAttribute('aria-label', 'Navigazione principale');
  tabbar.hidden = true;
  tabbar.innerHTML = TABS.map((t, i) => `
    <button type="button" class="tab" data-i="${i}" ${t.screen ? `data-screen="${t.screen}"` : 'data-menu="1"'}
            aria-label="${t.label}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${t.icon}</svg>
      <span>${t.label}</span>
      ${t.badge ? `<span class="tab-badge" hidden></span>` : ''}
    </button>`).join('');
  document.body.appendChild(tabbar);

  tabbar.addEventListener('click', e => {
    const tab = e.target.closest('.tab');
    if (!tab) return;
    closeDetailSheet(false);
    if (tab.dataset.menu) { window.toggleMobileSidebar && toggleMobileSidebar(); return; }
    const nav = $(`.nav-item[data-screen="${tab.dataset.screen}"]`);
    if (nav) nav.click();
  });

  function syncTabs() {
    const activeNav = $('.nav-item.active[data-screen]');
    const current = activeNav ? activeNav.dataset.screen : '';
    const known = TABS.some(t => t.screen === current);
    $$('.tab', tabbar).forEach(tab => {
      const on = tab.dataset.menu ? !known : tab.dataset.screen === current;
      tab.classList.toggle('active', on);
      if (on) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current');
    });
    TABS.forEach((t, i) => {
      if (!t.badge) return;
      const src = document.getElementById(t.badge);
      const dst = $(`.tab[data-i="${i}"] .tab-badge`, tabbar);
      if (!src || !dst) return;
      const n = (src.textContent || '').trim();
      const hidden = !n || n === '0' || getComputedStyle(src).display === 'none';
      dst.hidden = hidden;
      dst.textContent = n;
      const tabEl = dst.closest('.tab');
      if (tabEl) tabEl.setAttribute('aria-label', hidden ? t.label : `${t.label}, ${n} ${t.badgeLabel || 'in attesa'}`);
    });
    const login = $('#login-screen');
    const loggedOut = !!login && getComputedStyle(login).display !== 'none';
    tabbar.hidden = loggedOut;
    // Badge sull'icona: numero in coda; azzerato se sei disconnesso.
    if (typeof window.updateAppBadge === 'function') window.updateAppBadge(loggedOut ? 0 : pendingTotal());
  }
  // Totale per il badge dell'icona: commenti in coda + segnalazioni in attesa.
  function pendingTotal() {
    const num = id => { const n = parseInt(((document.getElementById(id) || {}).textContent || '').trim(), 10); return isNaN(n) ? 0 : n; };
    return num('nav-queue-count') + num('nav-reportable-count');
  }
  const syncObserver = new MutationObserver(syncTabs);
  $$('.nav-item').forEach(n => syncObserver.observe(n, { attributes: true, childList: true, subtree: true, characterData: true }));
  const loginEl = $('#login-screen');
  if (loginEl) syncObserver.observe(loginEl, { attributes: true, attributeFilter: ['style', 'class'] });
  syncTabs();

  // ── Badge sull'icona dell'app (numero di commenti in coda) ─────────
  // Si aggiorna con i contatori: finché l'app è aperta o in background recente.
  // Con l'app chiusa il numero resta all'ultimo valore (servirebbero le notifiche push).
  // Su iPhone/iPad il badge richiede il permesso notifiche → pulsante opt-in.
  const badgeBtn = document.createElement('button');
  badgeBtn.className = 'install-btn';
  badgeBtn.type = 'button';
  badgeBtn.textContent = 'Attiva il badge sull\'icona';
  if (footer) footer.insertBefore(badgeBtn, footer.firstChild);
  let lastBadge = null;

  async function updateAppBadge(n) {
    if (!('setAppBadge' in navigator)) return;
    n = Number(n) || 0;
    if (n === lastBadge) return;
    try {
      if (n > 0) await navigator.setAppBadge(n); else await navigator.clearAppBadge();
      lastBadge = n;
    } catch (_) {
      // iOS: senza permesso notifiche il badge non si può mostrare (pulsante in sidebar).
    } finally {
      refreshNotifBtn();
    }
  }
  window.updateAppBadge = updateAppBadge;
  // ── Notifiche push: iscrizione del dispositivo ────────────────────
  const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const loggedIn = () => typeof TOKEN !== 'undefined' && !!TOKEN;
  const urlB64ToBytes = b64 => {
    const raw = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, c => c.charCodeAt(0));
  };
  // Crea (o riallinea all'utente corrente) l'iscrizione e la registra sul server.
  async function ensurePushSubscription() {
    if (!pushSupported() || Notification.permission !== 'granted' || !loggedIn()) return false;
    try {
      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        const { publicKey } = await api('/push/key');
        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToBytes(publicKey) });
      }
      await api('/push/subscribe', 'POST', sub.toJSON());
      return true;
    } catch (_) { return false; }
  }
  async function removePushSubscription() {
    if (!pushSupported()) return;
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (!sub) return;
      await api('/push/unsubscribe', 'POST', { endpoint: sub.endpoint }).catch(() => {});
      await sub.unsubscribe();
    } catch (_) {}
  }
  // Al logout il dispositivo smette di ricevere gli avvisi di quell'utente.
  if (typeof window.logout === 'function') {
    const baseLogout = window.logout;
    let loggingOut = false; // api() chiama logout() sui 401: evita il rientro
    window.logout = function () {
      if (loggingOut) return baseLogout();
      loggingOut = true;
      Promise.race([removePushSubscription(), new Promise(r => setTimeout(r, 2500))]).finally(() => baseLogout());
    };
  }
  // Tocco su una notifica con l'app già aperta: vai alla schermata giusta.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', ev => {
      const d = ev.data || {};
      if (d.type !== 'goto' || !/^[a-z-]+$/.test(d.screen || '')) return;
      const nav = $(`.nav-item[data-screen="${d.screen}"]`);
      if (nav) nav.click();
    });
  }

  // Pulsante in sidebar (menu su telefono): riflette sempre lo stato reale del permesso.
  function refreshNotifBtn() {
    const ok = 'Notification' in window && (pushSupported() || 'setAppBadge' in navigator);
    badgeBtn.classList.toggle('show', ok && loggedIn());
    if (!ok) return;
    const perm = Notification.permission;
    badgeBtn.textContent = perm === 'granted' ? 'Invia notifica di prova'
      : perm === 'denied' ? 'Notifiche bloccate: come attivarle' : 'Attiva le notifiche';
  }
  async function onNotifBtn() {
    const perm = Notification.permission;
    if (perm === 'default') return enableNotifications();
    if (perm === 'denied') {
      toast('Notifiche bloccate: attivale da Impostazioni di iOS → Notifiche → Mod Hub (o dalle impostazioni del browser)', 'err');
      return;
    }
    if (!(await ensurePushSubscription())) { toast('Iscrizione alle notifiche non riuscita su questo dispositivo', 'err'); return; }
    try {
      const r = await api('/push/test', 'POST');
      toast(r.sent ? 'Notifica inviata: dovrebbe arrivare a breve' : 'Invio non riuscito: ' + JSON.stringify(r.results || r.error || []), r.sent ? 'ok' : 'err');
    } catch (err) { toast(err.message, 'err'); }
  }

  async function enableNotifications() {
    let perm = 'default';
    try { perm = await Notification.requestPermission(); } catch (_) {}
    if (perm !== 'granted') {
      refreshNotifBtn();
      if (perm === 'denied') toast('Permesso negato: puoi riattivarlo dalle impostazioni del dispositivo', 'err');
      return;
    }
    if (await ensurePushSubscription() && typeof toast === 'function') {
      toast('Notifiche attivate', 'ok');
      api('/push/test', 'POST').catch(() => {});
    }
    lastBadge = null;
    updateAppBadge(pendingTotal());
    refreshNotifBtn();
    const b = $('.notif-hint');
    if (b) b.remove();
  }
  badgeBtn.addEventListener('click', onNotifBtn);

  // Richiesta esplicita del permesso notifiche (necessario per il badge, soprattutto su iOS):
  // il browser accetta requestPermission solo da un gesto dell'utente → banner con pulsante.
  function maybeShowNotifHint() {
    if (!('Notification' in window) || Notification.permission !== 'default') return;
    if (!('setAppBadge' in navigator) && !pushSupported()) return;
    refreshNotifBtn();
    try { if (localStorage.getItem('mh_notif_hint') === '1') return; } catch (_) {}
    if ($('.notif-hint')) return;
    const el = document.createElement('div');
    el.className = 'ios-hint notif-hint show';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Attiva le notifiche');
    el.innerHTML =
      '<div style="flex:1;color:var(--muted)"><strong>Attiva le notifiche</strong><br>' +
      'Ricevi un avviso per le segnalazioni e i commenti in coda, con il totale sull\'icona.</div>' +
      '<button class="btn btn-primary notif-hint-go" type="button">Attiva</button>' +
      '<button class="ios-hint-x" type="button" aria-label="Chiudi">✕</button>';
    $('.notif-hint-go', el).addEventListener('click', enableNotifications);
    $('.ios-hint-x', el).addEventListener('click', () => {
      try { localStorage.setItem('mh_notif_hint', '1'); } catch (_) {}
      el.remove();
    });
    document.body.appendChild(el);
  }

  // ── Collegamenti rapidi (?screen=queue|appeals|…) e avvio ──────────
  const params = new URLSearchParams(location.search);
  const startScreen = params.get('screen');
  if (params.has('screen') || params.get('source')) {
    try {
      const clean = new URLSearchParams(location.search);
      clean.delete('screen'); clean.delete('source');
      const qs = clean.toString();
      history.replaceState({}, '', location.pathname + (qs ? '?' + qs : ''));
    } catch (_) {}
  }
  window.addEventListener('load', () => {
    setConnectionBadge();
    setTimeout(() => {
      if (startScreen && /^[a-z-]+$/.test(startScreen)) {
        const nav = $(`.nav-item[data-screen="${startScreen}"]`);
        if (nav && getComputedStyle($('#login-screen')).display === 'none') nav.click();
      }
      syncTabs();
    }, 400);
    // Il login si risolve in modo asincrono: aspetta che la dashboard sia davvero
    // visibile (fino a 30 s) prima di proporre installazione e notifiche.
    let tries = 0;
    const waitForLogin = setInterval(() => {
      const login = $('#login-screen');
      const shown = login && getComputedStyle(login).display !== 'none';
      if (!shown && loggedIn()) {
        clearInterval(waitForLogin);
        refreshNotifBtn();
        setTimeout(maybeShowIosHint, 2500);
        setTimeout(maybeShowNotifHint, 3500);
        ensurePushSubscription();
      } else if (++tries > 30) clearInterval(waitForLogin);
    }, 1000);
  });
})();

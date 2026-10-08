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
    if (q && q.classList.contains('active') && typeof loadQueue === 'function' && getComputedStyle($('#login-screen')).display === 'none') {
      loadQueue(); loadStats();
    }
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
    { screen: 'queue',      label: 'Coda',         badge: 'nav-queue-count',
      icon: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>' },
    { screen: 'reportable', label: 'Segnalazioni', badge: 'nav-reportable-count',
      icon: '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>' },
    { screen: 'appeals',    label: 'Ricorsi',      badge: 'nav-appeals-count',
      icon: '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>' },
    { screen: null,         label: 'Menu',         badge: null,
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
    });
    const login = $('#login-screen');
    tabbar.hidden = !!login && getComputedStyle(login).display !== 'none';
  }
  const syncObserver = new MutationObserver(syncTabs);
  $$('.nav-item').forEach(n => syncObserver.observe(n, { attributes: true, childList: true, subtree: true, characterData: true }));
  const loginEl = $('#login-screen');
  if (loginEl) syncObserver.observe(loginEl, { attributes: true, attributeFilter: ['style', 'class'] });
  syncTabs();

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
      if (getComputedStyle($('#login-screen')).display === 'none') setTimeout(maybeShowIosHint, 2500);
    }, 400);
  });
})();

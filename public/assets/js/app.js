// ── Mobile sidebar ────────────────────────────────────────────────
function toggleMobileSidebar() {
  const isOpen = document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebar-overlay').classList.toggle('open', isOpen);
  document.body.classList.toggle('sidebar-open', isOpen);
}
function closeMobileSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('open');
  document.body.classList.remove('sidebar-open');
}

// ── Navigation ────────────────────────────────────────────────────
document.querySelectorAll('.nav-item[data-screen]').forEach(item => {
  item.addEventListener('click', () => {
    if (window.innerWidth <= 640) closeMobileSidebar();
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    item.classList.add('active');
    const sc = item.dataset.screen;
    document.getElementById('screen-' + sc).classList.add('active');
    const titles = {
      queue:              'Coda revisione',
      reportable:         '⚠️ Segnalazioni pericolose',
      stats:              'Statistiche',
      pages:              'Pagine Facebook',
      policy:             'Policy AI',
      bans:               'Utenti bannati',
      'banned-comments':  'Commenti nascosti',
      'hidden-comments':  'Commenti nascosti',
      'removed-comments': 'Commenti rimossi',
      'approved-comments':'Commenti approvati',
      settings:           'Impostazioni',
      appeals:            'Ricorsi',
    };
    document.getElementById('topbar-title').textContent = titles[sc] || sc;
    if (sc === 'queue')             { loadQueue(); loadStats(); }
    if (sc === 'reportable')        { loadReportableQueue(); loadStats(); }
    if (sc === 'stats')             loadStatsScreen();
    if (sc === 'pages')             loadPages();
    if (sc === 'policy')            { loadPolicies(); loadActivePolicyPrompt(); }
    if (sc === 'bans')              { loadBans(); loadBanStats(); }
    if (sc === 'banned-comments')   loadHiddenComments();
    if (sc === 'hidden-comments')   loadHiddenComments();
    if (sc === 'removed-comments')  loadRemovedComments();
    if (sc === 'approved-comments') loadApprovedComments();
    if (sc === 'settings')          loadSettings();
    if (sc === 'appeals')           loadAppeals();
  });
});

// ── Init ──────────────────────────────────────────────────────────
async function initApp() {
  try {
    const me = await api('/me');
    currentUserRole = me.role || 'moderator';
    const initials = (me.name || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
    document.getElementById('user-avatar').textContent = initials;
    document.getElementById('user-name').textContent   = me.name || me.email;
    document.getElementById('user-role').textContent   = {
      admin:      'Admin',
      supervisor: 'Supervisore',
      moderator:  'Moderatore',
    }[currentUserRole] ?? 'Moderatore';

    if (currentUserRole === 'admin') {
      document.getElementById('nav-settings').style.display = 'flex';
      const menuUsers = document.getElementById('menu-manage-users');
      if (menuUsers) menuUsers.style.display = 'flex';
      const dp = document.getElementById('dev-mode-panel');
      if (dp) dp.style.display = 'block';
      const rp = document.getElementById('reply-settings-panel');
      if (rp) rp.style.display = 'block';
    }

    // Admin and supervisor can see removed comments
    if (['admin', 'supervisor'].includes(currentUserRole)) {
      const navRemoved = document.getElementById('nav-removed-comments');
      if (navRemoved) navRemoved.style.display = 'flex';
    }

    if (me.has_password) {
      const el = document.getElementById('pwd-has-password');
      if (el) el.style.display = 'block';
    }

    // Apply dev mode badge + Pro screen gates
    try {
      const s = await api('/settings');
      if (s.dev_mode) applyDevModeBadge(true);

      // Gate Pro screens (Statistiche + Segnalazioni) — solo per admin
      // Moderatori/supervisori vedono sempre il contenuto
      if (currentUserRole === 'admin') {
        const isPro        = s.license?.is_pro ?? false;
        const statsWall    = document.getElementById('stats-upgrade-wall');
        const statsContent = document.getElementById('stats-content');
        const repWall      = document.getElementById('reportable-upgrade-wall');
        const repContent   = document.getElementById('reportable-content');
        const statsBadge   = document.getElementById('nav-stats-pro-badge');
        const repBadge     = document.getElementById('nav-reportable-pro-badge');
        if (statsWall)    statsWall.style.display    = isPro ? 'none'         : 'block';
        if (statsContent) statsContent.style.display = isPro ? 'block'        : 'none';
        if (repWall)      repWall.style.display      = isPro ? 'none'         : 'block';
        if (repContent)   repContent.style.display   = isPro ? 'block'        : 'none';
        if (statsBadge)   statsBadge.style.display   = isPro ? 'none'         : 'inline-block';
        if (repBadge)     repBadge.style.display     = isPro ? 'none'         : 'inline-block';
      }
    } catch(e) {}

  } catch (e) {}

  loadQueue();
  loadStats();
  setInterval(() => {
    if (document.getElementById('screen-queue').classList.contains('active')) {
      loadQueue(); loadStats();
    }
  }, 30000);
}

// ── Manage users modal ────────────────────────────────────────────
function openManageUsers() {
  document.getElementById('user-dropdown').classList.remove('open');
  loadLocalUsers();
  openModal('modal-manage-users');
}

// ── Boot ──────────────────────────────────────────────────────────
checkToken();
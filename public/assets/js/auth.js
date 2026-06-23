// ── Auth: token check & login ─────────────────────────────────────
function checkToken() {
  const params = new URLSearchParams(window.location.search);
  const t = params.get('token');
  if (t) {
    TOKEN = t;
    localStorage.setItem('mh_token', t);
    history.replaceState({}, '', window.location.pathname);
  }
  if (TOKEN) {
    document.getElementById('login-screen').style.display = 'none';
    initApp();
  }
}

function logout() {
  localStorage.removeItem('mh_token');
  TOKEN = '';
  location.reload();
}

// Set OAuth button hrefs and hide unconfigured providers
(async () => {
  document.getElementById('btn-google').href = HUB_URL + '/auth/google';
  document.getElementById('btn-meta').href   = HUB_URL + '/auth/meta';
  document.getElementById('btn-ms').href     = HUB_URL + '/auth/microsoft';
  try {
    const res = await fetch(HUB_URL + '/auth/providers');
    const p   = await res.json();
    if (!p.google)    document.getElementById('btn-google').style.display = 'none';
    if (!p.meta)      document.getElementById('btn-meta').style.display   = 'none';
    if (!p.microsoft) document.getElementById('btn-ms').style.display     = 'none';
    if (!p.google && !p.meta && !p.microsoft) {
      document.getElementById('login-oauth-sep').style.display = 'none';
    }
  } catch (_) { /* se la chiamata fallisce i pulsanti restano visibili */ }
})();

// ── Local (email + password) login ───────────────────────────────
async function doLocalLogin() {
  const email    = document.getElementById('local-email').value.trim();
  const password = document.getElementById('local-password').value;
  const errEl    = document.getElementById('local-login-err');
  errEl.style.display = 'none';

  if (!email || !password) {
    errEl.textContent = 'Inserisci email e password.';
    errEl.style.display = 'block';
    return;
  }
  try {
    const res = await fetch(HUB_URL + '/auth/local', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Errore di accesso');
    TOKEN = data.token;
    localStorage.setItem('mh_token', TOKEN);
    document.getElementById('login-screen').style.display = 'none';
    if (data.must_change_password) {
      document.getElementById('forced-pwd-screen').style.display = 'flex';
    } else {
      initApp();
    }
  } catch (e) {
    errEl.textContent = e.message;
    errEl.style.display = 'block';
  }
}

async function saveForcedPassword() {
  const newPwd  = document.getElementById('forced-pwd-new').value;
  const confirm = document.getElementById('forced-pwd-confirm').value;
  const errEl   = document.getElementById('forced-pwd-err');
  errEl.style.display = 'none';
  if (!newPwd)            { errEl.textContent = 'Inserisci la nuova password.';  errEl.style.display = 'block'; return; }
  if (newPwd !== confirm) { errEl.textContent = 'Le password non coincidono.';   errEl.style.display = 'block'; return; }
  if (newPwd.length < 8)  { errEl.textContent = 'Minimo 8 caratteri.';          errEl.style.display = 'block'; return; }
  try {
    await api('/me/password', 'POST', { password: newPwd, password_confirm: confirm });
    document.getElementById('forced-pwd-screen').style.display = 'none';
    initApp();
  } catch (e) {
    errEl.textContent = e.message || 'Errore nel salvataggio.';
    errEl.style.display = 'block';
  }
}

// ── User dropdown menu ────────────────────────────────────────────
function toggleUserMenu() {
  document.getElementById('user-dropdown').classList.toggle('open');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.user-menu-wrap')) {
    document.getElementById('user-dropdown')?.classList.remove('open');
  }
});

// ── Change password (modal) ───────────────────────────────────────
function openChangePassword() {
  document.getElementById('user-dropdown').classList.remove('open');
  document.getElementById('pwd-new').value     = '';
  document.getElementById('pwd-confirm').value = '';
  document.getElementById('pwd-err').style.display = 'none';
  openModal('modal-password');
}

async function savePassword() {
  const newPwd  = document.getElementById('pwd-new').value;
  const confirm = document.getElementById('pwd-confirm').value;
  const errEl   = document.getElementById('pwd-err');
  errEl.style.display = 'none';
  if (!newPwd)           { errEl.textContent = 'Inserisci la nuova password.';    errEl.style.display = 'block'; return; }
  if (newPwd !== confirm) { errEl.textContent = 'Le password non coincidono.';    errEl.style.display = 'block'; return; }
  if (newPwd.length < 8)  { errEl.textContent = 'Minimo 8 caratteri.';           errEl.style.display = 'block'; return; }
  try {
    await api('/me/password', 'POST', { password: newPwd, password_confirm: confirm });
    toast('Password salvata', 'ok');
    closeModal('modal-password');
    const hasEl = document.getElementById('pwd-has-password');
    if (hasEl) hasEl.style.display = 'block';
  } catch (e) {
    errEl.textContent = e.message || 'Errore nel salvataggio.';
    errEl.style.display = 'block';
  }
}
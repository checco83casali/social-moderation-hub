// ── Pages ─────────────────────────────────────────────────────────
async function loadPages() {
  const wrap = document.getElementById('pages-list-wrap');
  try {
    const pages = await api('/pages');
    if (!pages.length) {
      wrap.innerHTML = '<div class="empty">Nessuna pagina connessa.<br><br>Usa il widget di connessione per aggiungere una pagina.</div>';
      return;
    }
    wrap.innerHTML = pages.map(p => `
      <div class="page-row">
        <div class="page-dot ${p.is_active ? 'on' : 'off'}"></div>
        <div class="page-name">${esc(p.page_name)}<br>
          <span style="font-size:11px;color:var(--muted)">ID: ${p.page_id} · Webhook: ${p.webhook_verified ? '✓' : '✗'}</span>
        </div>
        <button class="btn-sm" onclick="togglePage(${p.id})">${p.is_active ? 'Pausa' : 'Attiva'}</button>
      </div>`).join('');
  } catch (e) { wrap.innerHTML = '<div class="empty">Errore nel caricamento</div>'; }
}

async function togglePage(id) {
  try {
    const r = await api(`/pages/${id}/toggle`, 'PUT');
    toast(r.message || 'Aggiornato', 'ok');
    loadPages();
  } catch (e) { toast('Errore', 'err'); }
}

// ── Add Facebook pages (modal flow) ───────────────────────────────
let _fbSdkReady = null;          // Promise<void>, resolves once FB SDK + init done
let _fbAvailablePages = [];      // last fetched list (rendered as checkboxes)
let _fbUserToken = null;
let _fbOwnerId = null;

function _setAddPagesStep(step) {
  ['login', 'list', 'loading', 'error'].forEach(s => {
    const el = document.getElementById('add-pages-step-' + s) || document.getElementById('add-pages-' + s);
    if (el) el.style.display = (s === step) ? '' : 'none';
  });
}

function _showAddPagesError(msg) {
  const el = document.getElementById('add-pages-error');
  el.textContent = msg;
  el.style.display = '';
}

function loadFbSdk(appId, graphVersion) {
  if (_fbSdkReady) return _fbSdkReady;
  _fbSdkReady = new Promise((resolve, reject) => {
    window.fbAsyncInit = function () {
      FB.init({
        appId,
        cookie: false,
        xfbml: false,
        version: graphVersion || 'v19.0',
      });
      resolve();
    };
    const s = document.createElement('script');
    s.src = 'https://connect.facebook.net/en_US/sdk.js';
    s.async = true;
    s.defer = true;
    s.crossOrigin = 'anonymous';
    s.onerror = () => reject(new Error('SDK Facebook non caricabile'));
    document.body.appendChild(s);
  });
  return _fbSdkReady;
}

async function openAddPagesModal() {
  // Reset state
  _fbAvailablePages = [];
  _fbUserToken = null;
  _fbOwnerId = null;
  document.getElementById('add-pages-error').style.display = 'none';
  _setAddPagesStep('login');
  openModal('modal-add-pages');

  try {
    const cfg = await api('/pages/login-config');
    if (!cfg.app_id) {
      _showAddPagesError('META_APP_ID non configurato sul server.');
      return;
    }
    await loadFbSdk(cfg.app_id, cfg.graph_version);
    window._fbLoginConfigId = cfg.config_id || null;
  } catch (e) {
    _showAddPagesError(e.message || 'Errore inizializzazione Facebook.');
  }
}

function fbLoginAndListPages() {
  document.getElementById('add-pages-error').style.display = 'none';
  _setAddPagesStep('loading');

  const opts = window._fbLoginConfigId
    ? { config_id: window._fbLoginConfigId }
    : { scope: 'pages_show_list,pages_manage_metadata,pages_read_engagement,pages_manage_engagement' };

  FB.login(async (resp) => {
    if (!resp.authResponse || !resp.authResponse.accessToken) {
      _setAddPagesStep('login');
      _showAddPagesError('Login annullato o non autorizzato.');
      return;
    }
    _fbUserToken = resp.authResponse.accessToken;
    _fbOwnerId   = resp.authResponse.userID || null;

    try {
      const data = await api('/pages/available', 'POST', { user_token: _fbUserToken });
      _fbAvailablePages = data.pages || [];
      // The backend exchanged for a long-lived token but stripped per-page access_token.
      // We need page access_tokens to connect — request them directly from FB Graph using
      // the user token returned by FB.login (then call /pages/available only for the
      // "already_connected" annotation).
      const pagesResp = await new Promise(res => {
        FB.api('/me/accounts', 'GET',
          { fields: 'id,name,access_token,fan_count', access_token: _fbUserToken },
          res);
      });
      const fbPages = (pagesResp && pagesResp.data) || [];
      const connectedSet = new Set(
        _fbAvailablePages.filter(p => p.already_connected).map(p => p.id)
      );
      _fbAvailablePages = fbPages.map(p => ({
        ...p,
        already_connected: connectedSet.has(p.id),
      }));
      renderAvailablePages();
      _setAddPagesStep('list');
    } catch (e) {
      _setAddPagesStep('login');
      _showAddPagesError('Errore caricamento pagine: ' + (e.message || ''));
    }
  }, opts);
}

function renderAvailablePages() {
  const wrap = document.getElementById('add-pages-list');
  if (!_fbAvailablePages.length) {
    wrap.innerHTML = '<div class="empty" style="padding:20px;text-align:center">Nessuna pagina trovata per questo account Facebook.<br>Verifica di essere amministratore di almeno una pagina.</div>';
    document.getElementById('add-pages-confirm-btn').style.display = 'none';
    return;
  }
  document.getElementById('add-pages-confirm-btn').style.display = '';
  wrap.innerHTML = _fbAvailablePages.map((p, i) => `
    <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:8px;cursor:${p.already_connected ? 'not-allowed' : 'pointer'};opacity:${p.already_connected ? '.55' : '1'}">
      <input type="checkbox" data-idx="${i}" ${p.already_connected ? 'disabled' : ''} style="width:16px;height:16px">
      <div style="flex:1">
        <div style="font-weight:600;font-size:13px">${esc(p.name)}</div>
        <div style="font-size:11px;color:var(--muted)">ID: ${esc(p.id)}${p.already_connected ? ' · già connessa' : ''}</div>
      </div>
    </label>
  `).join('');
}

async function connectSelectedPages() {
  const checks = document.querySelectorAll('#add-pages-list input[type="checkbox"]:checked');
  if (!checks.length) { toast('Seleziona almeno una pagina', 'err'); return; }

  const selected = Array.from(checks).map(c => _fbAvailablePages[c.dataset.idx]);
  const payload = {
    pages: selected.map(p => ({
      page_id:           p.id,
      page_name:         p.name,
      page_access_token: p.access_token,
      owner_fb_id:       _fbOwnerId,
    })),
  };

  const btn = document.getElementById('add-pages-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Connessione…';

  try {
    const r = await api('/pages/connect-batch', 'POST', payload);
    const parts = [];
    if (r.connected) parts.push(`${r.connected} connesse`);
    if (r.skipped)   parts.push(`${r.skipped} saltate`);
    if (r.failed)    parts.push(`${r.failed} fallite`);
    toast(parts.join(' · ') || 'Nessuna pagina aggiunta', r.failed ? 'err' : 'ok');
    closeModal('modal-add-pages');
    loadPages();
  } catch (e) {
    _showAddPagesError(e.message || 'Errore connessione pagine.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Connetti selezionate';
  }
}

// ── Policies ──────────────────────────────────────────────────────
async function loadPolicies() {
  const wrap = document.getElementById('policy-list-wrap');
  try {
    const policies = await api('/policies');
    if (!policies.length) { wrap.innerHTML = '<div class="empty">Nessuna policy.</div>'; return; }
    wrap.innerHTML = policies.map(p => `
      <div class="policy-row">
        <div class="policy-active-dot ${p.is_active ? 'active' : ''}"></div>
        <div class="policy-info">
          <div class="policy-name">${esc(p.name)} <span style="font-size:11px;color:var(--muted)">v${p.version}</span></div>
          <div class="policy-meta">${p.is_active ? 'ATTIVA' : 'Inattiva'} · ${esc(p.created_by_name || '')}</div>
        </div>
        <button class="btn-sm" onclick="viewPolicy(${p.id})">Leggi</button>
        ${!p.is_active ? `<button class="btn-sm activate" onclick="activatePolicy(${p.id})">Attiva</button>` : ''}
      </div>`).join('');

    // Also load active policy prompt for the read-only viewer
  } catch (e) { wrap.innerHTML = '<div class="empty">Errore</div>'; }
}

async function loadActivePolicyPrompt() {
  const el = document.getElementById('active-prompt-viewer');
  if (!el) return;
  try {
    const p = await api('/policies/active');
    el.textContent = p.moderation_prompt || '(nessun prompt)';
    const header = document.getElementById('active-prompt-header');
    if (header) header.textContent = `${p.name} v${p.version} — System Prompt attivo (sola lettura)`;
  } catch(e) {
    el.textContent = 'Nessuna policy attiva.';
  }
}

async function viewPolicy(id) {
  const el = document.getElementById('active-prompt-viewer');
  const header = document.getElementById('active-prompt-header');
  if (!el) return;
  try {
    const p = await api(`/policies/${id}`);
    el.textContent = p.moderation_prompt || '(nessun prompt)';
    if (header) header.textContent = `${p.name} v${p.version}${p.is_active ? ' — ATTIVA' : ''} (sola lettura)`;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch(e) { toast('Errore caricamento policy', 'err'); }
}

async function activatePolicy(id) {
  try {
    const r = await api(`/policies/${id}/activate`, 'POST');
    toast(r.message || 'Policy attivata', 'ok');
    loadPolicies();
  } catch (e) { toast('Errore', 'err'); }
}

async function createPolicy() {
  const name   = document.getElementById('pname').value.trim();
  const desc   = document.getElementById('pdesc').value.trim();
  const prompt = document.getElementById('pprompt').value.trim();
  if (!name || !prompt) { toast('Nome e system prompt obbligatori', 'err'); return; }
  try {
    await api('/policies', 'POST', { name, description: desc, moderation_prompt: prompt });
    toast('Policy creata', 'ok');
    document.getElementById('pname').value   = '';
    document.getElementById('pdesc').value   = '';
    document.getElementById('pprompt').value = '';
    loadPolicies();
  } catch (e) { toast('Errore: ' + e.message, 'err'); }
}
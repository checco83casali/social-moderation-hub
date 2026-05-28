// ── Pages ─────────────────────────────────────────────────────────
// Free plan = 1 pagina; Pro (multi_page) = illimitate.
// Disabilita "+ Aggiungi pagine" quando il limite è raggiunto.
async function _updateAddPagesGate(count) {
  const btn = document.getElementById('btn-add-pages');
  if (!btn) return;
  let multiPage = false;
  try {
    const lic = await api('/license');
    multiPage = (lic.features || []).includes('multi_page');
  } catch (e) { /* in caso di errore licenza, non blocchiamo */ multiPage = true; }
  const blocked = count >= 1 && !multiPage;
  btn.disabled = blocked;
  btn.title = blocked ? 'Piano gratuito: 1 sola pagina. Passa a Pro per collegarne altre.' : '';
  btn.style.opacity = blocked ? '.5' : '';
  btn.style.cursor  = blocked ? 'not-allowed' : '';
}

async function loadPages() {
  const wrap = document.getElementById('pages-list-wrap');
  try {
    const pages = await api('/pages');
    _updateAddPagesGate(pages.length);
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
        <button class="btn-sm" onclick="openPageSettings(${p.id})">Soglie AI</button>
        <button class="btn-sm" onclick="togglePage(${p.id})">${p.is_active ? 'Pausa' : 'Attiva'}</button>
        <button class="btn-sm" style="color:var(--danger);border-color:var(--danger)" onclick="disconnectPage(${p.id})">Disconnetti</button>
      </div>`).join('');
  } catch (e) { wrap.innerHTML = '<div class="empty">Errore nel caricamento</div>'; }
}

async function disconnectPage(id) {
  if (!confirm('Disconnettere questa pagina?\n\nNon sarà più moderata e verrà tolta dall\'elenco, ma tutti i dati (coda di moderazione, log, ban) restano conservati per audit. Potrai ricollegarla in seguito.')) return;
  try {
    const r = await api(`/pages/${id}`, 'DELETE');
    toast(r.message || 'Pagina disconnessa', 'ok');
    loadPages();
  } catch (e) { toast(e.message || 'Errore', 'err'); }
}

async function togglePage(id) {
  try {
    const r = await api(`/pages/${id}/toggle`, 'PUT');
    toast(r.message || 'Aggiornato', 'ok');
    loadPages();
  } catch (e) { toast('Errore', 'err'); }
}

// ── Soglie AI per pagina (feature Pro: per_page_thresholds) ─────────
let _pageSettingsId = null;

async function openPageSettings(id) {
  _pageSettingsId = id;
  const body   = document.getElementById('page-settings-body');
  const errEl  = document.getElementById('page-settings-error');
  const saveBtn = document.getElementById('page-settings-save-btn');
  errEl.style.display = 'none';
  saveBtn.style.display = '';
  document.getElementById('page-settings-title').textContent = 'Soglie AI';
  body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);font-size:13px">Caricamento…</div>';
  openModal('modal-page-settings');

  try {
    const d = await api(`/pages/${id}/settings`);
    document.getElementById('page-settings-title').textContent = 'Soglie AI — ' + (d.page_name || '');
    body.innerHTML = `
      <div style="font-size:12px;color:var(--muted);margin-bottom:16px;line-height:1.6">
        Lascia un campo <strong>vuoto</strong> per usare il valore globale. I valori impostati qui sovrascrivono le soglie generali solo per questa pagina.
      </div>
      <label class="form-label">Soglia Haiku <span style="color:var(--muted);font-weight:400">(globale: ${d.global_haiku})</span></label>
      <input class="form-input" type="number" id="ps-haiku" min="0.01" max="1.00" step="0.01" value="${d.haiku_confidence_threshold ? d.haiku_confidence_threshold : ''}" placeholder="${d.global_haiku}">
      <div style="font-size:11px;color:var(--muted);margin:5px 0 14px">Se confidence ≥ soglia → Haiku decide da solo. Sotto → passa a Sonnet.</div>
      <label class="form-label">Soglia Sonnet <span style="color:var(--muted);font-weight:400">(globale: ${d.global_sonnet})</span></label>
      <input class="form-input" type="number" id="ps-sonnet" min="0.01" max="1.00" step="0.01" value="${d.sonnet_confidence_threshold ? d.sonnet_confidence_threshold : ''}" placeholder="${d.global_sonnet}">
      <div style="font-size:11px;color:var(--muted);margin:5px 0 16px">Se confidence ≥ soglia → Sonnet decide da solo. Sotto → revisione umana. Deve essere inferiore alla soglia Haiku.</div>
      ${d.fact_check_available ? `
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:8px">
        <input type="checkbox" id="ps-factcheck" ${d.fact_check_enabled ? 'checked' : ''} style="width:16px;height:16px">
        Fact-check AI attivo su questa pagina
      </label>` : ''}
      ${d.whataboutism_available ? `
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
        <input type="checkbox" id="ps-whataboutism" ${d.whataboutism_enabled ? 'checked' : ''} style="width:16px;height:16px">
        Whataboutism AI attivo su questa pagina
      </label>` : ''}`;
  } catch (e) {
    if ((e.message || '').toLowerCase().includes('pro license')) {
      saveBtn.style.display = 'none';
      body.innerHTML = `
        <div style="text-align:center;padding:18px">
          <div style="font-size:14px;font-weight:600;margin-bottom:8px">⭐ Funzionalità Pro</div>
          <div style="font-size:12.5px;color:var(--muted);line-height:1.6">
            Le soglie AI per singola pagina richiedono una licenza Pro.<br>
            Con il piano gratuito tutte le pagine usano le soglie globali impostate in Impostazioni.
          </div>
        </div>`;
    } else {
      body.innerHTML = '<div class="empty" style="padding:20px">Errore nel caricamento</div>';
    }
  }
}

async function savePageSettings() {
  if (!_pageSettingsId) return;
  const haikuEl  = document.getElementById('ps-haiku');
  const sonnetEl = document.getElementById('ps-sonnet');
  const fcEl     = document.getElementById('ps-factcheck');
  const wbEl     = document.getElementById('ps-whataboutism');
  if (!haikuEl || !sonnetEl) return;

  const haiku  = haikuEl.value.trim();
  const sonnet = sonnetEl.value.trim();

  // Validazione lato client: se entrambe impostate, Sonnet < Haiku
  if (haiku !== '' && sonnet !== '' && parseFloat(sonnet) >= parseFloat(haiku)) {
    const errEl = document.getElementById('page-settings-error');
    errEl.textContent = 'La soglia Sonnet deve essere inferiore alla soglia Haiku.';
    errEl.style.display = '';
    return;
  }

  const payload = {
    haiku_confidence_threshold:  haiku  === '' ? null : parseFloat(haiku),
    sonnet_confidence_threshold: sonnet === '' ? null : parseFloat(sonnet),
  };
  // Invia i toggle solo se la feature è attiva (checkbox presente),
  // altrimenti non tocchiamo il valore salvato.
  if (fcEl) payload.fact_check_enabled   = fcEl.checked;
  if (wbEl) payload.whataboutism_enabled = wbEl.checked;

  const btn = document.getElementById('page-settings-save-btn');
  btn.disabled = true;
  try {
    await api(`/pages/${_pageSettingsId}/settings`, 'PUT', payload);
    toast('Soglie salvate', 'ok');
    closeModal('modal-page-settings');
  } catch (e) {
    const errEl = document.getElementById('page-settings-error');
    errEl.textContent = e.message || 'Errore nel salvataggio';
    errEl.style.display = '';
  } finally {
    btn.disabled = false;
  }
}

// ── Add Facebook pages (modal flow) ───────────────────────────────
let _fbSdkReady = null;          // Promise<void>, resolves once FB SDK + init done
let _fbAvailablePages = [];      // last fetched list (rendered as checkboxes)
let _fbUserToken = null;
let _fbLongLivedToken = null;    // user token long-lived (dal backend) per page token che non scadono
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
  _fbLongLivedToken = null;
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

  FB.login((resp) => {
    if (!resp.authResponse || !resp.authResponse.accessToken) {
      _setAddPagesStep('login');
      _showAddPagesError('Login annullato o non autorizzato.');
      return;
    }
    _fbUserToken = resp.authResponse.accessToken;
    _fbOwnerId   = resp.authResponse.userID || null;

    // FB.login non accetta callback async (l'SDK lancia
    // "Expression is of type asyncfunction, not function"),
    // quindi la logica asincrona gira in una IIFE interna.
    (async () => {
    try {
      const data = await api('/pages/available', 'POST', { user_token: _fbUserToken });
      // Il backend ha già scambiato per uno user token long-lived: lo usiamo per
      // far risolvere lato server page token che NON scadono. Non chiediamo più i
      // token al client (FB.api col token short-lived faceva scadere tutto in pochi giorni).
      _fbLongLivedToken = data.long_lived_token || _fbUserToken;
      _fbAvailablePages = data.pages || [];
      renderAvailablePages();
      _setAddPagesStep('list');
    } catch (e) {
      _setAddPagesStep('login');
      _showAddPagesError('Errore caricamento pagine: ' + (e.message || ''));
    }
    })();
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
    // Lo user token long-lived: il backend risolve un page token che non scade.
    user_token: _fbLongLivedToken,
    pages: selected.map(p => ({
      page_id:           p.id,
      page_name:         p.name,
      page_access_token: p.access_token || null, // fallback; il backend preferisce risolverlo
      owner_fb_id:       _fbOwnerId,
    })),
  };

  const btn = document.getElementById('add-pages-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Connessione…';

  try {
    const r = await api('/pages/connect-batch', 'POST', payload);
    const proBlocked = (r.results || []).some(x => x.reason === 'pro_required');
    const parts = [];
    if (r.connected) parts.push(`${r.connected} connesse`);
    if (r.skipped)   parts.push(`${r.skipped} saltate`);
    if (r.failed)    parts.push(`${r.failed} fallite`);
    if (proBlocked) {
      toast('Piano gratuito: 1 sola pagina. Passa a Pro per collegarne altre.', 'err');
    } else {
      toast(parts.join(' · ') || 'Nessuna pagina aggiunta', r.failed ? 'err' : 'ok');
    }
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
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
  if (!(await confirmDialog({
    title: 'Disconnettere questa pagina?',
    message: 'Non sarà più moderata e verrà tolta dall\'elenco, ma tutti i dati (coda di moderazione, log, ban) restano conservati per audit.\n\nPotrai ricollegarla in seguito.',
    confirmLabel: 'Disconnetti',
    danger: true,
  }))) return;
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

// Soglie per pagina: l'API usa frazioni (0.85), l'interfaccia percentuali intere (85%).
const psPct = f => Math.round(parseFloat(f) * 100);

// Cursore e campo numerico (in %) restano allineati; campo vuoto = valore globale.
function psSync(key, val, fromInput = false, globalVal = null) {
  const num   = document.getElementById('ps-' + key);
  const range = document.getElementById('ps-' + key + '-range');
  if (!num || !range) return;
  if (globalVal !== null) { num.value = ''; range.value = globalVal; return; }
  if (!fromInput) { num.value = Math.round(parseFloat(val)); return; }
  if (val !== '' && !isNaN(parseFloat(val))) range.value = val;
}

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
      <label class="form-label">Soglia Haiku <span style="color:var(--muted);font-weight:400">(globale: ${psPct(d.global_haiku)}%)</span></label>
      <div style="display:flex;align-items:center;gap:10px">
        <input type="range" id="ps-haiku-range" min="1" max="100" step="1" value="${psPct(d.haiku_confidence_threshold || d.global_haiku)}" style="flex:1;min-width:0;accent-color:var(--accent)" oninput="psSync('haiku', this.value)">
        <input class="form-input" type="number" id="ps-haiku" min="1" max="100" step="1" value="${d.haiku_confidence_threshold ? psPct(d.haiku_confidence_threshold) : ''}" placeholder="${psPct(d.global_haiku)}" style="width:72px;flex:none;text-align:center" oninput="psSync('haiku', this.value, true)"><span style="font-size:13px;color:var(--muted);flex:none">%</span>
        <button type="button" class="btn-sm" style="flex-shrink:0" onclick="psSync('haiku', '', true, ${psPct(d.global_haiku)})">Globale</button>
      </div>
      <div style="font-size:11px;color:var(--muted);margin:5px 0 14px">Se confidence ≥ soglia → Haiku decide da solo. Sotto → passa a Sonnet.</div>
      <label class="form-label">Soglia Sonnet <span style="color:var(--muted);font-weight:400">(globale: ${psPct(d.global_sonnet)}%)</span></label>
      <div style="display:flex;align-items:center;gap:10px">
        <input type="range" id="ps-sonnet-range" min="1" max="100" step="1" value="${psPct(d.sonnet_confidence_threshold || d.global_sonnet)}" style="flex:1;min-width:0;accent-color:var(--accent)" oninput="psSync('sonnet', this.value)">
        <input class="form-input" type="number" id="ps-sonnet" min="1" max="100" step="1" value="${d.sonnet_confidence_threshold ? psPct(d.sonnet_confidence_threshold) : ''}" placeholder="${psPct(d.global_sonnet)}" style="width:72px;flex:none;text-align:center" oninput="psSync('sonnet', this.value, true)"><span style="font-size:13px;color:var(--muted);flex:none">%</span>
        <button type="button" class="btn-sm" style="flex-shrink:0" onclick="psSync('sonnet', '', true, ${psPct(d.global_sonnet)})">Globale</button>
      </div>
      <div style="font-size:11px;color:var(--muted);margin:5px 0 16px">Se confidence ≥ soglia → Sonnet decide da solo. Sotto → revisione umana. Deve essere inferiore alla soglia Haiku.</div>
      ${d.fact_check_available ? `
      <label class="whd-switch ok" style="margin-bottom:12px">
        <input type="checkbox" id="ps-factcheck" ${d.fact_check_enabled ? 'checked' : ''}>
        <span class="whd-track" aria-hidden="true"></span>
        <span>Fact-check AI attivo su questa pagina</span>
      </label>` : ''}
      ${d.whataboutism_available ? `
      <label class="whd-switch ok">
        <input type="checkbox" id="ps-whataboutism" ${d.whataboutism_enabled ? 'checked' : ''}>
        <span class="whd-track" aria-hidden="true"></span>
        <span>Whataboutism AI attivo su questa pagina</span>
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
    haiku_confidence_threshold:  haiku  === '' ? null : Math.round(parseFloat(haiku))  / 100,
    sonnet_confidence_threshold: sonnet === '' ? null : Math.round(parseFloat(sonnet)) / 100,
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

function switchAddPagesTab(tab) {
  const isOauth = tab === 'oauth';
  document.getElementById('add-pages-tab-oauth').style.display  = isOauth ? '' : 'none';
  document.getElementById('add-pages-tab-direct').style.display = isOauth ? 'none' : '';
  const accent = 'var(--accent)', transparent = 'transparent';
  document.getElementById('tab-btn-oauth').style.borderBottomColor  = isOauth ? accent : transparent;
  document.getElementById('tab-btn-direct').style.borderBottomColor = isOauth ? transparent : accent;
  document.getElementById('tab-btn-oauth').style.color  = isOauth ? 'var(--text)' : 'var(--muted)';
  document.getElementById('tab-btn-direct').style.color = isOauth ? 'var(--muted)' : 'var(--text)';
  document.getElementById('add-pages-error').style.display = 'none';
}

async function connectDirectToken() {
  const pageId    = document.getElementById('direct-page-id').value.trim();
  const pageToken = document.getElementById('direct-page-token').value.trim();
  const errEl     = document.getElementById('add-pages-error');
  const btn       = document.getElementById('direct-connect-btn');

  errEl.style.display = 'none';
  if (!pageId || !pageToken) {
    errEl.textContent = 'Page ID e Page Token sono obbligatori.';
    errEl.style.display = '';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Connessione…';
  try {
    const r = await api('/pages/connect-direct', 'POST', { page_id: pageId, page_token: pageToken });
    toast(`Pagina "${r.page_name}" connessa${r.webhook_active ? ' e webhook attivo' : ' (webhook da verificare)'}`, r.webhook_active ? 'ok' : 'warn');
    closeModal('modal-add-pages');
    loadPages();
  } catch (e) {
    errEl.textContent = e.message || 'Errore nella connessione.';
    errEl.style.display = '';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Connetti';
  }
}


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
    if (!policies.length) { wrap.innerHTML = '<div class="empty">Nessun prompt.</div>'; return; }
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

// ── Utilizzo ed efficacia del prompt ──────────────────────────────
let policyStatsScope = 'all';
let policyStatsData  = null;
let policyStatsSel   = null;   // versione di cui si mostrano le fasce di confidenza

function setPolicyStatsScope(scope, el) {
  policyStatsScope = scope;
  document.querySelectorAll('[data-filter-group="ps-scope"] .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadPolicyStats();
}

const psPctText = v => (v === null || v === undefined) ? '—' : Math.round(v * 100) + '%';
const psNum = n => Number(n || 0).toLocaleString('it-IT');
// Verde ≥ 90%, ambra ≥ 75%, rosso sotto
function psTone(eff) { return eff === null || eff === undefined ? '' : eff >= 0.9 ? 'ok' : eff >= 0.75 ? 'warn' : 'danger'; }

function psDecisionBar(d, total) {
  if (!total) return '<span style="color:var(--muted)">—</span>';
  const seg = (n, color, label) => n ? `<span title="${label}: ${n}" style="flex:${n};background:${color}"></span>` : '';
  return `<div style="display:flex;height:8px;border-radius:4px;overflow:hidden;min-width:90px;background:var(--bg-hover)">
    ${seg(d.allow, 'var(--success)', 'Approvati')}${seg(d.uncertain, 'var(--warn)', 'Incerti')}${seg(d.hide + d.reportable, 'var(--danger)', 'Nascosti/segnalati')}
  </div>
  <div style="font-size:10.5px;color:var(--muted);margin-top:3px">${psNum(d.allow)} appr. · ${psNum(d.uncertain)} incerti · ${psNum(d.hide + d.reportable)} nasc.</div>`;
}

function psCard(label, value, sub, tone) {
  return `<div class="stat-card"><div class="stat-label">${label}</div><div class="stat-value ${tone || ''}">${value}</div><div class="stat-sub">${sub}</div></div>`;
}

async function loadPolicyStats() {
  const body = document.getElementById('policy-stats-body');
  if (!body) return;
  if (!['admin', 'supervisor'].includes(currentUserRole)) {
    document.getElementById('policy-stats-panel').style.display = 'none';
    return;
  }
  try {
    policyStatsData = await api('/policies/stats?scope=' + policyStatsScope);
    if (!policyStatsSel || !policyStatsData.policies.some(p => p.id === policyStatsSel)) {
      policyStatsSel = (policyStatsData.policies.find(p => p.is_active) || policyStatsData.policies[0] || {}).id ?? null;
    }
    renderPolicyStats();
  } catch (e) {
    if (e.code === 'license_required') {
      document.getElementById('policy-stats-count').textContent = '';
      body.innerHTML = `<div class="empty" style="padding:28px 16px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:28px;height:28px;color:var(--muted);margin-bottom:8px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <div style="font-weight:600;color:var(--text);margin-bottom:4px">Funzionalità Enterprise</div>
        L'utilizzo e l'efficacia di ogni versione del prompt sono inclusi nella licenza Enterprise.</div>`;
      document.querySelector('[data-filter-group="ps-scope"]').style.display = 'none';
      return;
    }
    body.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function renderPolicyStats() {
  const body = document.getElementById('policy-stats-body');
  const d = policyStatsData;
  if (!d) return;
  const t = d.totals;
  document.getElementById('policy-stats-count').textContent = `${psNum(t.uses)} valutazioni`;
  if (!t.uses) {
    body.innerHTML = '<div class="empty">Ancora nessuna valutazione' + (policyStatsScope === 'dev' ? ' in modalità sviluppo' : policyStatsScope === 'prod' ? ' in produzione' : '') + '.</div>';
    return;
  }

  const cards = `<div class="stats-grid" style="margin-bottom:18px">
    ${psCard('Valutazioni', psNum(t.uses), `${psNum(t.comments)} commenti · ${psNum(t.prod_uses)} prod. · ${psNum(t.dev_uses)} sviluppo`, 'accent')}
    ${psCard('Efficacia stimata', psPctText(t.effectiveness), `su ${psNum(t.decisive)} decisioni autonome`, psTone(t.effectiveness))}
    ${psCard('Errori accertati', psNum(t.errors), `${psNum(t.too_permissive)} troppo permissivi · ${psNum(t.too_strict)} troppo severi`, t.errors ? 'warn' : 'success')}
    ${psCard('Escalation a umano', psNum(t.escalated), `${psNum(t.escalated_hidden)} nascosti · ${psNum(t.escalated_visible)} approvati · ${psNum(t.escalated_pending)} in attesa`, 'warn')}
    ${psCard('Confidenza media', psPctText(t.confidence_avg), `corrette ${psPctText(t.confidence_ok_avg)} · errori ${psPctText(t.confidence_err_avg)}`, '')}
  </div>`;

  const rows = d.policies.map(p => `
    <tr class="ps-row ${p.id === policyStatsSel ? 'selected' : ''}" onclick="selectPolicyStats(${p.id})" style="cursor:pointer">
      <td><div style="font-weight:600">${esc(p.name)} <span style="font-size:11px;color:var(--muted);font-weight:400">v${p.version}</span></div>
          <div style="font-size:11px;color:var(--muted)">${p.is_active ? '<span class="badge-human">ATTIVA</span>' : 'Inattiva'}${p.last_used ? ' · ultimo uso ' + relTime(p.last_used) : ''}</div></td>
      <td><b>${psNum(p.uses)}</b><div style="font-size:11px;color:var(--muted)">${psNum(p.prod_uses)} prod. · ${psNum(p.dev_uses)} sviluppo</div></td>
      <td class="ps-hide-m" style="min-width:150px">${psDecisionBar(p.by_decision, p.uses)}</td>
      <td>${p.effectiveness === null ? '—' : `<span class="chip chip-ai chip-ai--${psTone(p.effectiveness)}">${psPctText(p.effectiveness)}</span>`}</td>
      <td>${psNum(p.errors)}<div style="font-size:11px;color:var(--muted)">${psNum(p.too_permissive)} perm. · ${psNum(p.too_strict)} sev.</div></td>
      <td class="ps-hide-m">${psNum(p.escalated)}</td>
      <td class="ps-hide-m">${psPctText(p.confidence_avg)}</td>
    </tr>`).join('');

  const sel = d.policies.find(p => p.id === policyStatsSel);
  const bands = sel && sel.decisive ? `
    <div style="margin-top:20px">
      <div class="detail-section-title">Fasce di confidenza — ${esc(sel.name)} v${sel.version}</div>
      <div class="ban-table-wrap"><table class="ban-table ps-table">
        <thead><tr><th>Confidenza dell'AI</th><th>Decisioni autonome</th><th>Errori</th><th>Tasso di errore</th></tr></thead>
        <tbody>${sel.bands.map(b => `<tr><td>${b.label}</td><td>${psNum(b.n)}</td><td>${psNum(b.errors)}</td>
          <td class="ps-nowrap">${b.n === 0 ? '—' : `
            <span class="chip chip-ai chip-ai--danger" title="Troppo severo: l'AI aveva nascosto, un moderatore ha ripristinato — ${psNum(b.too_strict)} su ${psNum(b.n)}">severo ${psPctText(b.strict_rate)}</span>
            <span class="chip chip-ai chip-ai--ok" title="Troppo permissivo: l'AI aveva approvato, un moderatore ha nascosto — ${psNum(b.too_permissive)} su ${psNum(b.n)}">permissivo ${psPctText(b.permissive_rate)}</span>`}</td></tr>`).join('')}</tbody>
      </table></div>
    </div>` : '';

  body.innerHTML = cards + `
    <div class="ban-table-wrap"><table class="ban-table ps-table">
      <thead><tr><th>Versione</th><th>Valutazioni</th><th class="ps-hide-m">Esiti dell'AI</th><th>Efficacia</th><th>Errori accertati</th><th class="ps-hide-m">Escalation</th><th class="ps-hide-m">Conf. media</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>` + bands;
}

function selectPolicyStats(id) { policyStatsSel = id; renderPolicyStats(); }

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
  } catch(e) { toast('Errore caricamento prompt', 'err'); }
}

async function activatePolicy(id) {
  try {
    const r = await api(`/policies/${id}/activate`, 'POST');
    toast(r.message || 'Prompt attivato', 'ok');
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
    toast('Nuova versione del prompt creata', 'ok');
    document.getElementById('pname').value   = '';
    document.getElementById('pdesc').value   = '';
    document.getElementById('pprompt').value = '';
    loadPolicies();
  } catch (e) { toast('Errore: ' + e.message, 'err'); }
}
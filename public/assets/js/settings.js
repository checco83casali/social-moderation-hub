// ── Settings tab switcher ─────────────────────────────────────────
function switchSettingsTab(tab) {
  ['moderazione','messaggi','ai','privacy','sistema','licenza'].forEach(t => {
    const panel = document.getElementById('stab-' + t);
    const btn   = document.getElementById('stab-btn-' + t);
    if (panel) panel.style.display = t === tab ? '' : 'none';
    if (btn) {
      btn.style.color        = t === tab ? 'var(--text)' : 'var(--muted)';
      btn.style.borderBottom = t === tab ? '2px solid var(--accent)' : '2px solid transparent';
    }
  });
}

// ── Settings ──────────────────────────────────────────────────────
async function loadSettings() {
  try {
    const d = await api('/settings');
    const h = parseFloat(d.haiku_confidence_threshold  ?? 0.80);
    const s = parseFloat(d.sonnet_confidence_threshold ?? 0.70);
    const r = parseInt(d.recidivism_comment_ban_limit  ?? 3, 10);

    document.getElementById('set-haiku-range').value  = h;
    document.getElementById('set-haiku-val').value    = h;
    document.getElementById('set-sonnet-range').value = s;
    document.getElementById('set-sonnet-val').value   = s;
    document.getElementById('set-recid').value        = r;

    const rmw = parseInt(d.reason_max_words ?? 40, 10);
    const rmwEl = document.getElementById('set-reason-max-words');
    if (rmwEl) rmwEl.value = rmw;

    // Ban escalation
    const bl1 = document.getElementById('set-ban-l1-hours'); if (bl1) bl1.value = parseInt(d.ban_level_1_hours ?? 1, 10);
    const bl2 = document.getElementById('set-ban-l2-days');  if (bl2) bl2.value = parseInt(d.ban_level_2_days  ?? 7, 10);
    const bl3 = document.getElementById('set-ban-l3-days');  if (bl3) bl3.value = parseInt(d.ban_level_3_days  ?? 30, 10);

    // Timezone
    const tzEl = document.getElementById('set-timezone'); if (tzEl) tzEl.value = d.app_timezone ?? 'Europe/Rome';

    const replyEnabled = !!d.removal_reply_enabled;
    const replyToggle  = document.getElementById('set-reply-enabled');
    if (replyToggle) replyToggle.checked = replyEnabled;
    onReplyEnabledChange(replyEnabled);

    // Dev mode toggle
    const devToggle = document.getElementById('set-dev-mode');
    if (devToggle) {
      devToggle.checked = !!d.dev_mode;
      onDevModeChange(!!d.dev_mode);
    }

    // ── Privacy Policy fields (admin only) ──────────────────────────
    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
    setVal('set-app-url',              d.app_url                       ?? '');
    setVal('set-privacy-org-name',     d.privacy_org_name              ?? '');
    setVal('set-privacy-org-address',  d.privacy_org_address           ?? '');
    setVal('set-privacy-org-email',    d.privacy_org_email             ?? '');
    setVal('set-privacy-org-country',  d.privacy_org_country           ?? '');
    setVal('set-privacy-policy-date',  d.privacy_policy_date           ?? '');
    setVal('set-privacy-supervisory',  d.privacy_supervisory_authority ?? '');

    // Update preview links with actual app_url if available, else use relative path
    const appUrlForLinks = (d.app_url ?? '').replace(/\/$/, '');
    const privLink   = document.getElementById('privacy-preview-link');
    const policyLink = document.getElementById('public-policy-link');
    if (privLink)   privLink.href   = appUrlForLinks ? appUrlForLinks + '/privacy'        : '/privacy';
    if (policyLink) policyLink.href = appUrlForLinks ? appUrlForLinks + '/public/policy'  : '/public/policy';

    const me      = await api('/me');
    const isAdmin = me.role === 'admin';
    document.getElementById('settings-save-btn').disabled = !isAdmin;
    document.getElementById('settings-admin-note').style.display = isAdmin ? 'none' : 'block';

    // Privacy panel e Dev mode panel: solo admin
    const privPanel  = document.getElementById('privacy-settings-panel');
    const devPanel   = document.getElementById('dev-mode-panel');
    const replyPanel = document.getElementById('reply-settings-panel');
    if (privPanel)  privPanel.style.display  = isAdmin ? 'block' : 'none';
    if (devPanel)   devPanel.style.display   = isAdmin ? 'block' : 'none';
    if (replyPanel) replyPanel.style.display = isAdmin ? 'block' : 'none';

    if (!isAdmin) {
      document.getElementById('settings-save-btn').style.opacity = '.4';
      document.getElementById('settings-save-btn').style.cursor  = 'not-allowed';
    }

    // ── Claude AI key panel (admin only) ────────────────────────────
    const claudePanel = document.getElementById('claude-key-panel');
    if (claudePanel) claudePanel.style.display = isAdmin ? 'block' : 'none';
    if (isAdmin) {
      const keyInput = document.getElementById('set-anthropic-key');
      if (keyInput) {
        keyInput.placeholder = d.anthropic_api_key_set
          ? 'Attuale: ' + (d.anthropic_api_key_masked ?? '…') + ' — lascia vuoto per non modificare'
          : 'Inserisci la chiave sk-ant-…';
        keyInput.value = '';
      }
    }

    // ── License & Pro panels (admin only) ───────────────────────────
    if (isAdmin) {
      const lic = d.license ?? {};
      applyLicensePanel(lic);
      applyProFieldLocks(lic);

      // PRO: data retention
      const retEl = document.getElementById('set-data-retention-days');
      if (retEl) retEl.value = parseInt(d.data_retention_days ?? 0, 10);
      loadRetentionStatus();

      // PRO: fact-check auto-publish threshold
      const fcEl = document.getElementById('set-fact-check-threshold');
      if (fcEl) fcEl.value = parseFloat(d.fact_check_auto_publish_threshold ?? 0.90).toFixed(2);

      // PRO: whataboutism auto-publish threshold
      const wbEl = document.getElementById('set-whataboutism-threshold');
      if (wbEl) wbEl.value = parseFloat(d.whataboutism_auto_publish_threshold ?? 0.95).toFixed(2);

      // PRO: hide reply templates
      setVal('set-hide-reply-template',            d.hide_reply_template            ?? '');
      setVal('set-hide-reportable-reply-template', d.hide_reportable_reply_template ?? '');

      // PRO: ban notification templates
      setVal('set-ban-warning-template',       d.ban_warning_template       ?? '');
      setVal('set-ban-notification-template',  d.ban_notification_template  ?? '');
      setVal('set-banned-user-hide-template',  d.banned_user_hide_template  ?? '');
    }

  } catch (e) { toast('Errore caricamento impostazioni', 'err'); }
}

// ── License panel display ─────────────────────────────────────────
function applyLicensePanel(lic) {
  const panel = document.getElementById('license-panel');
  if (!panel) return;
  panel.style.display = 'block';

  const statusEl  = document.getElementById('lic-status');
  const planEl    = document.getElementById('lic-plan');
  const featEl    = document.getElementById('lic-features');
  const expiresEl = document.getElementById('lic-expires');
  const offlineEl = document.getElementById('lic-offline-warn');
  const keyInput  = document.getElementById('lic-key-input');

  const statusLabels = {
    valid:       '✅ Attiva',
    free:        '○ Community (gratuita)',
    invalid:     '✗ Non valida',
    expired:     '⚠ Scaduta',
    unreachable: '⚠ Server irraggiungibile',
  };

  if (statusEl)  statusEl.textContent  = statusLabels[lic.status] ?? lic.status ?? '—';
  if (planEl)    planEl.textContent    = lic.plan ? lic.plan.toUpperCase() : '—';
  if (expiresEl) expiresEl.textContent = lic.expires_at ? new Date(lic.expires_at).toLocaleDateString('it-IT') : '—';
  if (offlineEl) offlineEl.style.display = lic.offline_mode ? 'block' : 'none';

  if (featEl) {
    const featureNames = {
      data_retention:      'Retention dati',
      export_log:          'Export log',
      templates:           'Template reply personalizzabili',
      per_page_thresholds: 'Soglie AI per pagina',
      fact_check:          'Fact check AI',
      whataboutism:        'Whataboutism AI',
      multi_page:          'Pagine multiple',
    };
    const feats = (lic.features ?? []).map(f => featureNames[f] ?? f);
    featEl.innerHTML = feats.length
      ? feats.map(f => `<span class="badge badge-pro">${esc(f)}</span>`).join(' ')
      : '<span style="color:var(--muted);font-size:12px">Nessuna funzionalità Pro attiva</span>';
  }

  if (keyInput && lic.key_masked) keyInput.placeholder = lic.key_masked;

  // External licensing disabled (LICENSE_OFFLINE_MODE=true):
  // hide the whole activate/refresh/deactivate flow, show the explanatory banner.
  const externalDisabled = !!lic.external_disabled;
  const extMsg     = document.getElementById('lic-external-disabled-msg');
  const actForm    = document.getElementById('lic-activate-form');
  const actButtons = document.getElementById('lic-activate-buttons');
  const offlineEl2 = document.getElementById('lic-offline-warn');
  if (extMsg)     extMsg.style.display     = externalDisabled ? 'block' : 'none';
  if (actForm)    actForm.style.display    = externalDisabled ? 'none'  : 'grid';
  if (actButtons) actButtons.style.display = externalDisabled ? 'none'  : 'flex';
  // The "server unreachable" warning is irrelevant when we don't talk to a server.
  if (externalDisabled && offlineEl2) offlineEl2.style.display = 'none';

  // Show/hide deactivate button (only relevant when not in external_disabled mode)
  const deactBtn = document.getElementById('lic-deactivate-btn');
  if (deactBtn) deactBtn.style.display = (!externalDisabled && lic.status === 'valid') ? 'inline-flex' : 'none';
}

// ── Pro field lock overlay ────────────────────────────────────────
function applyProFieldLocks(lic) {
  const isPro = lic.is_pro ?? false;
  const proSections = document.querySelectorAll('[data-pro-feature]');
  proSections.forEach(section => {
    const feature  = section.dataset.proFeature;
    const unlocked = isPro && (lic.features ?? []).includes(feature);
    const lockEl   = section.querySelector('.pro-lock-overlay');
    const inputs   = section.querySelectorAll('input, textarea, select, button:not(.pro-lock-ignore)');

    if (lockEl) lockEl.style.display = unlocked ? 'none' : 'flex';
    inputs.forEach(el => { el.disabled = !unlocked; });
  });
}

// ── Activate license ──────────────────────────────────────────────
async function activateLicense() {
  const keyEl    = document.getElementById('lic-key-input');
  const domainEl = document.getElementById('lic-domain-input');
  const key      = keyEl?.value?.trim() ?? '';
  if (!key) { toast('Inserisci una chiave di licenza', 'err'); return; }

  try {
    const res = await api('/license', 'PUT', {
      key,
      domain: domainEl?.value?.trim() ?? '',
    });
    if (res.ok) {
      toast('Licenza attivata con successo', 'ok');
      loadSettings(); // reload to update all Pro panels
    } else {
      toast('Attivazione fallita: ' + (res.error ?? res.status), 'err');
    }
  } catch (e) { toast('Errore attivazione licenza', 'err'); }
}

// ── Deactivate license ────────────────────────────────────────────
async function deactivateLicense() {
  if (!confirm('Disattivare la licenza Pro? Le funzionalità Pro verranno disabilitate immediatamente.')) return;
  try {
    await api('/license', 'DELETE');
    toast('Licenza disattivata', 'ok');
    loadSettings();
  } catch (e) { toast('Errore disattivazione', 'err'); }
}

// ── Force refresh license ─────────────────────────────────────────
async function refreshLicense() {
  try {
    const res = await api('/license/refresh', 'POST');
    toast(res.ok ? 'Licenza aggiornata' : ('Aggiornamento: ' + res.status), res.ok ? 'ok' : 'warn');
    loadSettings();
  } catch (e) { toast('Errore aggiornamento licenza', 'err'); }
}

async function saveSettings() {
  const haiku   = parseFloat(document.getElementById('set-haiku-val').value);
  const sonnet  = parseFloat(document.getElementById('set-sonnet-val').value);
  const recid   = parseInt(document.getElementById('set-recid').value, 10);
  const devMode = document.getElementById('set-dev-mode')?.checked ?? false;

  if (isNaN(haiku) || haiku <= 0 || haiku > 1 ||
      isNaN(sonnet) || sonnet <= 0 || sonnet > 1 ||
      isNaN(recid) || recid < 1) {
    toast('Valori non validi', 'err');
    return;
  }
  if (sonnet >= haiku) {
    toast('La soglia Sonnet deve essere inferiore alla soglia Haiku', 'err');
    return;
  }
  const reasonMaxWords   = parseInt(document.getElementById('set-reason-max-words')?.value ?? 40, 10);
  const replyEnabled     = document.getElementById('set-reply-enabled')?.checked ?? true;

  // Ban escalation
  const banL1Hours = parseInt(document.getElementById('set-ban-l1-hours')?.value ?? 1, 10);
  const banL2Days  = parseInt(document.getElementById('set-ban-l2-days')?.value  ?? 7, 10);
  const banL3Days  = parseInt(document.getElementById('set-ban-l3-days')?.value  ?? 30, 10);
  if (isNaN(banL1Hours) || banL1Hours < 1 || isNaN(banL2Days) || banL2Days < 1 || isNaN(banL3Days) || banL3Days < 1) {
    toast('Valori escalation ban non validi', 'err'); return;
  }

  // Timezone
  const timezone = document.getElementById('set-timezone')?.value ?? 'Europe/Rome';

  // ── Privacy Policy fields ────────────────────────────────────────
  const privEmail = document.getElementById('set-privacy-org-email')?.value?.trim() ?? '';
  if (privEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(privEmail)) {
    toast('Email privacy non valida', 'err'); return;
  }
  const appUrl = (document.getElementById('set-app-url')?.value?.trim() ?? '').replace(/\/$/, '');
  if (appUrl && !/^https?:\/\/.+/.test(appUrl)) {
    toast('URL installazione non valido (deve iniziare con http:// o https://)', 'err'); return;
  }

  try {
    const payload = {
      haiku_confidence_threshold:    haiku,
      sonnet_confidence_threshold:   sonnet,
      recidivism_comment_ban_limit:  recid,
      dev_mode:                      devMode,
      reason_max_words:              reasonMaxWords,
      removal_reply_enabled:         replyEnabled,
      ban_level_1_hours:             banL1Hours,
      ban_level_2_days:              banL2Days,
      ban_level_3_days:              banL3Days,
      app_timezone:                  timezone,
      // Privacy
      app_url:                       appUrl,
      privacy_org_name:              document.getElementById('set-privacy-org-name')?.value?.trim()    ?? '',
      privacy_org_address:           document.getElementById('set-privacy-org-address')?.value?.trim() ?? '',
      privacy_org_email:             privEmail,
      privacy_org_country:           document.getElementById('set-privacy-org-country')?.value?.trim() ?? '',
      privacy_policy_date:           document.getElementById('set-privacy-policy-date')?.value?.trim() ?? '',
      privacy_supervisory_authority: document.getElementById('set-privacy-supervisory')?.value?.trim() ?? '',
    };

    // PRO fields — only send if the input exists and is enabled (not locked)
    const retEl = document.getElementById('set-data-retention-days');
    if (retEl && !retEl.disabled) {
      const retDays = parseInt(retEl.value, 10);
      if (!isNaN(retDays) && retDays >= 0) payload.data_retention_days = retDays;
    }
    const hideEl = document.getElementById('set-hide-reply-template');
    if (hideEl && !hideEl.disabled) payload.hide_reply_template = hideEl.value.trim();
    const hideRepEl = document.getElementById('set-hide-reportable-reply-template');
    if (hideRepEl && !hideRepEl.disabled) payload.hide_reportable_reply_template = hideRepEl.value.trim();
    const banWarnEl = document.getElementById('set-ban-warning-template');
    if (banWarnEl && !banWarnEl.disabled) payload.ban_warning_template = banWarnEl.value.trim();
    const banNotifEl = document.getElementById('set-ban-notification-template');
    if (banNotifEl && !banNotifEl.disabled) payload.ban_notification_template = banNotifEl.value.trim();
    const banHideEl = document.getElementById('set-banned-user-hide-template');
    if (banHideEl && !banHideEl.disabled) payload.banned_user_hide_template = banHideEl.value.trim();
    const fcThEl = document.getElementById('set-fact-check-threshold');
    if (fcThEl && !fcThEl.disabled) {
      const fcVal = parseFloat(fcThEl.value);
      if (!isNaN(fcVal) && fcVal >= 0.5 && fcVal <= 1.0) payload.fact_check_auto_publish_threshold = fcVal;
    }
    const wbThEl = document.getElementById('set-whataboutism-threshold');
    if (wbThEl && !wbThEl.disabled) {
      const wbVal = parseFloat(wbThEl.value);
      if (!isNaN(wbVal) && wbVal >= 0.5 && wbVal <= 1.0) payload.whataboutism_auto_publish_threshold = wbVal;
    }

    // Anthropic API key — solo se l'utente ha inserito qualcosa
    const apiKeyEl = document.getElementById('set-anthropic-key');
    if (apiKeyEl && apiKeyEl.value.trim()) {
      payload.anthropic_api_key = apiKeyEl.value.trim();
    }

    await api('/settings', 'PUT', payload);

    // Reset campo chiave dopo salvataggio riuscito
    if (apiKeyEl && apiKeyEl.value.trim()) {
      apiKeyEl.value = '';
      await loadSettings(); // ricarica per aggiornare il placeholder con la nuova chiave mascherata
      return;
    }

    // Aggiorna i link di anteprima con il nuovo app_url
    const privLinkAfter   = document.getElementById('privacy-preview-link');
    const policyLinkAfter = document.getElementById('public-policy-link');
    if (privLinkAfter)   privLinkAfter.href   = appUrl ? appUrl + '/privacy'       : '/privacy';
    if (policyLinkAfter) policyLinkAfter.href = appUrl ? appUrl + '/public/policy' : '/public/policy';

    applyDevModeBadge(devMode);
    toast('Impostazioni salvate', 'ok');
  } catch (e) { toast('Errore nel salvataggio', 'err'); }
}

// ── Export log moderazione (PRO) ─────────────────────────────────
async function exportLog(format) {
  const from  = document.getElementById('export-from')?.value ?? '';
  const to    = document.getElementById('export-to')?.value   ?? '';
  const token = localStorage.getItem('mh_token');

  const params = new URLSearchParams({ format });
  if (from) params.set('from', from);
  if (to)   params.set('to',   to);

  try {
    const res = await fetch('/api/export/moderation-log?' + params.toString(), {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.status === 403) { toast('Funzionalità Pro richiesta', 'err'); return; }
    if (!res.ok)            { toast('Errore export log', 'err'); return; }

    const blob     = await res.blob();
    const ext      = format === 'json' ? 'json' : 'csv';
    const filename = `moderation-log-${new Date().toISOString().slice(0,10)}.${ext}`;
    const url      = URL.createObjectURL(blob);
    const a        = document.createElement('a');
    a.href         = url;
    a.download     = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    toast('Download avviato', 'ok');
  } catch (e) { toast('Errore export log', 'err'); }
}
async function openRegistro() {
  try {
    const token = localStorage.getItem('mh_token');
    const res   = await fetch('/api/registro-trattamenti', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!res.ok) { toast('Errore apertura registro', 'err'); return; }
    const html = await res.text();
    const blob = new Blob([html], { type: 'text/html' });
    const url  = URL.createObjectURL(blob);
    window.open(url, '_blank');
    // Revoca l'URL dopo 60s per non tenere il blob in memoria
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) { toast('Errore apertura registro', 'err'); }
}
async function openDpia() {
  try {
    const token = localStorage.getItem('mh_token');
    const res   = await fetch('/api/dpia', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!res.ok) { toast('Errore apertura DPIA', 'err'); return; }
    const html = await res.text();
    const blob = new Blob([html], { type: 'text/html' });
    const url  = URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) { toast('Errore apertura DPIA', 'err'); }
}
function onDevModeChange(checked) {
  const label = document.getElementById('dev-mode-label');
  if (label) {
    label.textContent = checked ? '⚠ Modalità sviluppo ATTIVA' : 'Modalità sviluppo disattiva';
    label.style.color = checked ? 'var(--warn)' : '';
  }
}

function applyDevModeBadge(active) {
  let badge = document.getElementById('topbar-devmode-badge');
  if (active) {
    if (!badge) {
      badge = document.createElement('div');
      badge.id = 'topbar-devmode-badge';
      badge.className = 'topbar-badge';
      badge.style.cssText = 'background:rgba(247,178,68,.15);color:#f7b244;border:1px solid rgba(247,178,68,.3);font-weight:600;cursor:default';
      badge.title = 'Modalità sviluppo attiva — nessuna azione reale su Facebook o DB';
      badge.textContent = '⚠ DEV MODE';
      document.getElementById('topbar').appendChild(badge);
    }
    document.body.classList.add('dev-mode-active');
  } else {
    badge?.remove();
    document.body.classList.remove('dev-mode-active');
  }
}

// ── Reply enabled toggle ─────────────────────────────────────────
function onReplyEnabledChange(checked) {
  const gdprWarn = document.getElementById('reply-gdpr-warning');
  if (gdprWarn) {
    gdprWarn.style.display = checked ? 'none' : 'block';
    if (!checked) {
      gdprWarn.style.animation = 'none';
      setTimeout(() => { gdprWarn.style.animation = 'gdpr-pulse 0.4s ease 2'; }, 10);
    }
  }
}

// ── Local users (admin only) ──────────────────────────────────────
function openCreateUser() {
  ['cu-name', 'cu-email'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('cu-role').value = 'moderator';
  document.getElementById('cu-err').style.display = 'none';
  openModal('modal-create-user');
}

async function createLocalUser() {
  const name  = document.getElementById('cu-name').value.trim();
  const email = document.getElementById('cu-email').value.trim();
  const role  = document.getElementById('cu-role').value;
  const errEl = document.getElementById('cu-err');
  errEl.style.display = 'none';
  if (!name || !email) {
    errEl.textContent = 'Nome ed email sono obbligatori.';
    errEl.style.display = 'block';
    return;
  }
  try {
    const res = await api('/users/local', 'POST', { name, email, role });
    closeModal('modal-create-user');
    loadLocalUsers();
    document.getElementById('tp-email').textContent    = email;
    document.getElementById('tp-password').textContent = res.temp_password;
    document.getElementById('tp-copy-btn').textContent = 'Copia';
    openModal('modal-temp-password');
  } catch (e) {
    errEl.textContent = e.message || 'Errore nella creazione.';
    errEl.style.display = 'block';
  }
}

function copyTempPassword() {
  const pwd = document.getElementById('tp-password').textContent;
  navigator.clipboard.writeText(pwd).then(() => {
    const btn = document.getElementById('tp-copy-btn');
    btn.textContent = 'Copiata!';
    setTimeout(() => { btn.textContent = 'Copia'; }, 2000);
  });
}

async function loadLocalUsers() {
  const el = document.getElementById('local-users-list');
  if (!el) return;
  try {
    const users = await api('/users/local');
    if (!users.length) {
      el.innerHTML = '<div style="font-size:13px;color:var(--muted);padding:8px 0">Nessun utente locale creato.</div>';
      return;
    }
    el.innerHTML = `
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:1px solid var(--border)">
          <th style="text-align:left;padding:6px 8px;color:var(--muted);font-weight:500">Nome</th>
          <th style="text-align:left;padding:6px 8px;color:var(--muted);font-weight:500">Email</th>
          <th style="text-align:left;padding:6px 8px;color:var(--muted);font-weight:500">Ruolo</th>
          <th style="text-align:left;padding:6px 8px;color:var(--muted);font-weight:500">Ultimo accesso</th>
        </tr></thead>
        <tbody>${users.map(u => `
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px">${esc(u.name)}</td>
            <td style="padding:8px;color:var(--muted)">${esc(u.email)}</td>
            <td style="padding:8px">${u.role === 'admin' ? 'Admin' : 'Moderatore'}</td>
            <td style="padding:8px;color:var(--muted)">${u.last_login_at ? new Date(u.last_login_at).toLocaleDateString('it-IT') : '—'}</td>
          </tr>`).join('')}
        </tbody>
      </table>`;
  } catch (e) {
    el.innerHTML = '<div style="font-size:13px;color:var(--danger)">Errore caricamento utenti.</div>';
  }
}

// ── Retention status ──────────────────────────────────────────────
async function loadRetentionStatus() {
  const el = document.getElementById('retention-status-info');
  if (!el) return;

  try {
    const s = await api('/retention/status');

    if (!s.enabled) {
      el.innerHTML = '<div style="padding:10px 12px;background:var(--bg-hover);border-radius:var(--radius)">⚪ Retention disabilitata — nessuna anonimizzazione attiva.</div>';
      return;
    }

    const lr = s.last_run;
    if (!lr) {
      el.innerHTML = `<div style="padding:10px 12px;background:var(--warning-bg,#fff7ed);border:1px solid var(--warning,#fdba74);color:var(--warning-text,#9a3412);border-radius:var(--radius)">
        ⚠️ Retention impostata a <strong>${s.retention_days}</strong> giorni ma il cron non ha mai girato. Configura il cron sul server (vedi sotto).
      </div>`;
      return;
    }

    const finishedAt = lr.finished_at || lr.started_at;
    const finished = new Date(finishedAt.replace(' ', 'T'));
    const hoursAgo = (Date.now() - finished.getTime()) / 3.6e6;
    const stale = hoursAgo > 48; // > 2 days = cron probably not configured

    const stats = lr.anonymised || {};
    const totals = Object.values(stats).reduce((a, b) => a + (Number(b) || 0), 0);

    const palette = stale
      ? { bg: 'var(--warning-bg,#fff7ed)', bd: 'var(--warning,#fdba74)', fg: 'var(--warning-text,#9a3412)', icon: '⚠️' }
      : { bg: 'var(--success-bg,#f0fdf4)', bd: 'var(--success,#86efac)', fg: 'var(--success-text,#166534)', icon: '✅' };

    el.innerHTML = `<div style="padding:10px 12px;background:${palette.bg};border:1px solid ${palette.bd};color:${palette.fg};border-radius:var(--radius)">
      <div><strong>${palette.icon} Ultima esecuzione:</strong> ${relTime(finishedAt)} (${esc(finishedAt)})</div>
      ${lr.skipped
        ? `<div style="margin-top:4px">Esecuzione saltata: ${esc(lr.reason || '')}</div>`
        : `<div style="margin-top:4px">Cutoff: <code>${esc(lr.cutoff)}</code> · Anonimizzati ${totals} record (commenti ${stats.comments||0}, utenti ${stats.social_users||0}, log ${stats.moderation_log||0}, ricorsi ${stats.appeal_records||0}, webhook ${stats.webhook_events||0}) · ${lr.duration_ms||0}ms</div>`
      }
      ${stale ? '<div style="margin-top:6px;font-weight:500">Il cron sembra non girare regolarmente — verifica la configurazione.</div>' : ''}
    </div>`;
  } catch (e) {
    el.innerHTML = '<div style="font-size:12px;color:var(--danger)">Errore caricamento stato retention.</div>';
  }
}
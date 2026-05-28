// ── Stats (quick bar in queue screen) ────────────────────────────
async function loadStats() {
  try {
    const d = await api('/stats');
    document.getElementById('s-queue').textContent    = d.queue_pending  ?? '—';
    document.getElementById('s-removed').textContent  = d.hidden_30d    ?? '—';
    document.getElementById('s-approved').textContent = d.approved_30d  ?? '—';
    document.getElementById('s-bans').textContent     = d.active_bans   ?? '—';
    document.getElementById('nav-queue-count').textContent = d.queue_pending ?? '0';

    const appealsBadge = document.getElementById('nav-appeals-count');
    if (appealsBadge) appealsBadge.textContent = d.appeals_pending ?? '0';

    const reportEl = document.getElementById('s-reportable');
    if (reportEl) {
      reportEl.textContent = d.queue_reportable ?? '0';
      reportEl.closest('.stat-card')?.classList.toggle('danger', (d.queue_reportable ?? 0) > 0);
    }

    // Reportable badge in nav
    const repNav = document.getElementById('nav-reportable-count');
    if (repNav) {
      repNav.textContent = d.queue_reportable ?? '0';
      repNav.style.display = (d.queue_reportable ?? 0) > 0 ? '' : 'none';
    }
  } catch (e) {}
}

async function loadStatsScreen() {
  try {
    const d = await api('/stats');
    document.getElementById('ss-total').textContent    = d.total_comments_30d ?? '—';
    document.getElementById('ss-hidden').textContent   = d.hidden_30d         ?? '—';
    document.getElementById('ss-approved').textContent = d.approved_30d       ?? '—';
    document.getElementById('ss-bans').textContent     = d.active_bans        ?? '—';
    document.getElementById('ss-appeals').textContent  = d.appeals_pending    ?? '—';

    const stageColors = { haiku:'#4f8ef7', sonnet:'#f7b244', human:'#f75252', system:'#9ca3af' };
    // keepZero=true: mostra tutti gli stage anche se il conteggio è 0,
    // così Sonnet/Sistema non spariscono quando non sono stati invocati.
    renderBarChart('stage-chart', d.by_stage || {}, stageColors,
      { haiku:'Haiku', sonnet:'Sonnet', human:'Umano', system:'Sistema' }, true, false, true);

    // Sonnet sub-calls (fact-check + whataboutism): chiamate API che non
    // appaiono in by_stage perché il log porta lo stage della moderazione.
    const subCalls = d.sonnet_subcalls || { fact_check: 0, whataboutism: 0 };
    const subEl = document.getElementById('sonnet-subcalls');
    if (subEl) {
      const total = (subCalls.fact_check || 0) + (subCalls.whataboutism || 0);
      subEl.innerHTML = total === 0
        ? '<div style="padding:14px;text-align:center;font-size:12px;color:var(--muted)">Nessuna sub-call Sonnet negli ultimi 30 giorni</div>'
        : `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="padding:10px 14px;background:rgba(79,142,247,.08);border:1px solid rgba(79,142,247,.18);border-radius:var(--radius)">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">🔍 Fact-check</div>
            <div style="font-size:20px;font-weight:600;color:var(--accent)">${subCalls.fact_check ?? 0}</div>
            <div style="font-size:11px;color:var(--muted)">call Sonnet (assess + grounding)</div>
          </div>
          <div style="padding:10px 14px;background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.2);border-radius:var(--radius)">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">↩️ Whataboutism</div>
            <div style="font-size:20px;font-weight:600;color:#a855f7">${subCalls.whataboutism ?? 0}</div>
            <div style="font-size:11px;color:var(--muted)">call Sonnet (assess + verify)</div>
          </div>
        </div>`;
    }

    const decColors = { allow:'#3ecf8e', remove:'#f75252', uncertain:'#f7b244', hide:'#f7a244', reportable:'#e055a3' };
    renderBarChart('decision-chart', d.by_ai_decision || {}, decColors,
      { allow:'Approvato', remove:'Rimosso', uncertain:'Incerto', hide:'Nascosto', reportable:'Segnalato' }, true);
  } catch (e) {
    console.error('Stats error:', e);
  }

  try {
    const d = await api('/bans/stats');
    const catColors = {
      scam:'#f75252', spam:'#f7b244', grooming:'#d44', hate_speech:'#e055a3',
      harassment:'#e08c44', misinformation:'#7c6ef7', violence:'#e05050',
      sexual:'#d03', coordinated_behaviour:'#4fa8f7', illegal_content:'#991b1b',
    };
    // Categories: sorted by count desc, bars normalised to max value
    renderBarChart('ban-cat-chart', d.removed_by_category || {}, catColors, {}, true, true);
    const decData = { ai: d.removed_by_decider?.ai ?? 0, human: d.removed_by_decider?.human ?? 0 };
    renderBarChart('ban-decider-chart', decData, { ai:'#4f8ef7', human:'#3ecf8e' },
      { ai:'AI automatica', human:'Moderatore' }, true);
  } catch (e) {
    console.error('Ban stats error:', e);
  }
}

// ── Queue (uncertain AI decisions only) ──────────────────────────
async function loadQueue() {
  const list = document.getElementById('queue-list');
  try {
    const d = await api('/queue?limit=50');
    if (!d.items || d.items.length === 0) {
      list.innerHTML = '<div class="empty">Nessun commento in attesa ✓</div>';
      if (currentComment) {
        toast('Il commento che stavi revisionando è già stato gestito da un altro operatore.', 'warn');
      }
      return;
    }
    d.items.forEach(item => { queueMap[item.id] = item; });
    list.innerHTML = d.items.map(item => `
      <div class="q-item${currentComment && currentComment.id === item.id ? ' selected' : ''}" data-id="${item.id}" onclick="selectComment(${item.id})">
        <div class="q-avatar">${(item.display_name||'?')[0].toUpperCase()}</div>
        <div class="q-body">
          <div class="q-header">
            <span class="q-name">${esc(item.display_name || 'Anonimo')}</span>
            ${item.ai_severity==='high'   ? '<span class="chip chip-danger">alto rischio</span>' : ''}
            ${item.ai_severity==='medium' ? '<span class="chip chip-warn">medio</span>' : ''}
            ${item.violation_count > 0 ? `<span class="chip chip-info">${item.violation_count} violaz.</span>` : ''}
            <span class="q-time">${relTime(item.received_at)}</span>
          </div>
          <div class="q-text">${esc(item.content)}</div>
        </div>
      </div>`).join('');

    // Se stai revisionando un commento che nel frattempo è uscito dalla coda
    // (gestito da un altro operatore), avvisa invece di lasciarti decidere a vuoto.
    if (currentComment && !d.items.some(item => item.id === currentComment.id)) {
      toast('Il commento che stavi revisionando è già stato gestito da un altro operatore.', 'warn');
    }
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

// ── Reportable queue (dangerous content, auto-hidden by AI) ───────
async function loadReportableQueue() {
  const list    = document.getElementById('reportable-list');
  const countEl = document.getElementById('reportable-count');
  if (!list) return;
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const d = await api('/queue/reportable?limit=50');
    countEl && (countEl.textContent = `${d.total} segnalazioni`);
    if (!d.items || d.items.length === 0) {
      list.innerHTML = '<div class="empty">Nessuna segnalazione pericolosa in attesa ✓</div>';
      return;
    }
    d.items.forEach(item => { queueMap[item.id] = item; });
    list.innerHTML = d.items.map(item => {
      const cats = (item.ai_categories||[]).map(c => `<span class="chip chip-danger">${CAT_LABELS[c]||c}</span>`).join(' ');
      const fbLink = item.platform_comment_id && item.platform_post_id
        ? `https://www.facebook.com/permalink.php?story_fbid=${(item.platform_post_id.split('_')[1]||item.platform_post_id)}&id=${item.facebook_page_id}&comment_id=${item.platform_comment_id}`
        : '';
      return `
        <div class="bc-item" id="rep-${item.id}" style="border-left:3px solid var(--danger)">
          <div class="bc-header">
            <div class="ban-avatar" style="width:26px;height:26px;font-size:10px;background:var(--danger)">${(item.display_name||'?')[0].toUpperCase()}</div>
            <span class="bc-user">${esc(item.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(item.page_name)}</span>
            ${item.violation_count > 0 ? `<span class="chip chip-danger">${item.violation_count} violaz.</span>` : ''}
            <span class="bc-time">${relTime(item.received_at)}</span>
            ${fbLink ? `<a href="${fbLink}" target="_blank" rel="noopener" class="btn-sm" style="margin-left:auto;text-decoration:none" title="Vedi su Facebook">🔗</a>` : ''}
          </div>
          <div class="bc-content" style="margin-top:8px">${esc(item.content)}</div>
          ${item.ai_reason ? `<div style="font-size:11px;color:var(--muted);margin-top:4px">Motivazione AI: ${esc(item.ai_reason)}</div>` : ''}
          <div class="bc-footer" style="margin-top:10px">
            ${cats}
            <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-approve" style="padding:5px 12px;font-size:12px"
                onclick="resolveReportable(${item.id}, 'approve')">✓ Falso positivo — ripristina</button>
              <button class="btn" style="padding:5px 12px;font-size:12px;background:var(--warn-bg);color:var(--warn);border:1px solid rgba(247,178,68,.3)"
                onclick="resolveReportable(${item.id}, 'keep')">🙈 Mantieni nascosto</button>
              <button class="btn btn-remove" style="padding:5px 12px;font-size:12px"
                onclick="resolveReportable(${item.id}, 'report')">⚠️ Avvia iter segnalazione</button>
            </div>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

async function resolveReportable(commentId, action) {
  const labels = {
    approve: 'ripristinare il commento (falso positivo)',
    keep:    'mantenere il commento nascosto',
    report:  'avviare l\'iter di segnalazione alle autorità',
  };
  if (!confirm(`Confermi di voler ${labels[action]}?`)) return;

  try {
    if (action === 'approve') {
      await api(`/comments/${commentId}/decide`, 'POST', { decision: 'unhide', note: 'Falso positivo — ripristinato da moderatore' });
      toast('Commento ripristinato', 'ok');
    } else if (action === 'keep') {
      await api(`/comments/${commentId}/decide`, 'POST', { decision: 'keep_hidden', note: 'Confermato nascosto — iter segnalazione non avviato' });
      toast('Commento mantenuto nascosto', 'ok');
    } else if (action === 'report') {
      await api(`/comments/${commentId}/report-legal`, 'POST', { note: 'Iter segnalazione avviato — in attesa di ufficio legale' });
      toast('Iter avviato — scarico il dossier PDF', 'ok');
      await downloadLegalDossier(commentId);
      loadReportableArchive();
    }
    document.getElementById(`rep-${commentId}`)?.remove();
    loadStats();
  } catch (e) {
    toast('Errore: ' + e.message, 'err');
  }
}

// Scarica il dossier PDF (richiede header Authorization, quindi fetch + blob).
async function downloadLegalDossier(commentId) {
  try {
    const r = await fetch(HUB_URL + '/api/comments/' + commentId + '/legal-dossier', {
      headers: { 'Authorization': 'Bearer ' + TOKEN },
    });
    if (!r.ok) {
      // Il server risponde JSON in caso d'errore: mostra il motivo reale.
      let msg = 'HTTP ' + r.status;
      try { const j = await r.json(); if (j && j.error) msg = j.error; } catch (_) {}
      throw new Error(msg);
    }
    const blob = await r.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = `dossier-segnalazione-${commentId}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    toast('Errore nel download del dossier: ' + (e.message || ''), 'err');
  }
}

async function loadReportableArchive() {
  const list    = document.getElementById('reportable-archive-list');
  const countEl = document.getElementById('reportable-archive-count');
  if (!list) return;
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const d = await api('/queue/reportable/archive?limit=50');
    countEl && (countEl.textContent = `${d.total} segnalati`);
    if (!d.items?.length) {
      list.innerHTML = '<div class="empty">Nessuna segnalazione inoltrata alle autorità.</div>';
      return;
    }
    list.innerHTML = d.items.map(c => {
      const cats = (c.ai_categories||[]).map(cat => `<span class="chip chip-warn">${CAT_LABELS[cat]||cat}</span>`).join(' ');
      return `
        <div class="bc-item">
          <div class="bc-header">
            <span class="bc-user">${esc(c.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(c.page_name)}</span>
            <span class="chip chip-danger">⚠️ segnalato alle autorità</span>
            <span class="bc-time">${relTime(c.processed_at||c.received_at)}</span>
          </div>
          <div class="bc-content">${esc(c.content)}</div>
          ${c.human_note ? `<div style="font-size:11px;color:var(--muted);margin-top:4px">Nota: ${esc(c.human_note)}</div>` : ''}
          <div class="bc-footer" style="margin-top:10px">
            ${cats}
            <button class="btn btn-sm" style="margin-left:auto;padding:5px 12px;font-size:12px"
              onclick="downloadLegalDossier(${c.id})">📄 Scarica dossier PDF</button>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function selectComment(id) {
  currentComment = queueMap[id];
  if (!currentComment) return;
  document.querySelectorAll('.q-item').forEach(el => {
    el.classList.toggle('selected', parseInt(el.dataset.id) === id);
  });
  renderDetail(currentComment);
}

function renderDetail(c) {
  document.getElementById('detail-empty').style.display = 'none';
  const box = document.getElementById('detail-content');
  box.style.display = 'block';

  const cats = (c.ai_categories || []).map(cat => `<span class="chip chip-warn">${CAT_LABELS[cat] || cat}</span>`).join(' ');
  const confPct = c.ai_confidence ? Math.round(c.ai_confidence * 100) + '%' : '—';
  const stageLabel = { haiku:'Claude Haiku', sonnet:'Claude Sonnet', human:'Escalation umana' };

  box.innerHTML = `
    <div class="detail-section">
      <div class="detail-section-title">Commento</div>
      <div class="comment-bubble">${esc(c.content)}</div>
    </div>

    <div class="detail-section">
      <div class="detail-section-title">Utente</div>
      <div class="user-row">
        <div class="q-avatar">${(c.display_name||'?')[0].toUpperCase()}</div>
        <div class="user-info">
          <div class="user-display">${esc(c.display_name || 'Anonimo')}</div>
          <div class="user-meta">${c.violation_count || 0} violazioni · ${c.ban_status || 'clean'} · ${esc(c.page_name)}</div>
        </div>
      </div>
    </div>

    <div class="detail-section">
      <div class="detail-section-title">Valutazione AI</div>
      <div class="ai-verdict">
        <div>
          <div class="verdict-stage">${stageLabel[c.ai_stage] || c.ai_stage || '—'}</div>
          <div class="verdict-label">${esc(c.ai_reason || 'Nessuna motivazione')}</div>
          ${cats ? `<div style="margin-top:6px">${cats}</div>` : ''}
        </div>
        <div class="verdict-conf">${confPct}</div>
      </div>
    </div>

    <div class="detail-section">
      <div class="detail-section-title">Decisione</div>
      ${c.is_reportable ? `
      <div style="background:var(--danger-bg,#fff0f0);border:1px solid rgba(247,82,82,.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:12px;font-size:13px;line-height:1.6">
        <strong>⚠️ Contenuto potenzialmente illecito</strong><br>
        <span style="color:var(--muted);font-size:12px">Il commento è già stato nascosto automaticamente. Valuta se procedere con una segnalazione all'autorità competente prima di approvare o rimuovere definitivamente.</span>
      </div>` : ''}
      ${c.ai_fact_check_suggested && !c.ai_fact_check_draft ? `
      <div style="background:rgba(247,178,68,.08);border:1px solid rgba(247,178,68,.25);border-radius:var(--radius);padding:10px 14px;margin-bottom:12px;font-size:12.5px;line-height:1.6">
        <div style="font-weight:600;color:var(--warn);margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.06em">🔍 Fact-check suggerito dall'AI</div>
        <div style="color:var(--muted)">Il commento contiene una affermazione verificabile. Valuta se rispondere con una correzione editoriale.</div>
      </div>` : ''}
      ${c.ai_fact_check_draft ? `
      <div style="background:rgba(79,142,247,.08);border:1px solid rgba(79,142,247,.2);border-radius:var(--radius);padding:12px 14px;margin-bottom:12px;font-size:12.5px;line-height:1.6">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <div style="font-weight:600;color:var(--accent);font-size:11px;text-transform:uppercase;letter-spacing:.06em">💡 Risposta fact-check proposta dall'AI</div>
          ${c.ai_fact_check_confidence ? `<span style="font-size:11px;padding:2px 7px;border-radius:20px;background:rgba(79,142,247,.15);color:var(--accent)">confidenza ${Math.round(c.ai_fact_check_confidence * 100)}%</span>` : ''}
        </div>
        <div style="color:var(--text);margin-bottom:10px;white-space:pre-wrap">${esc(c.ai_fact_check_draft)}</div>
        ${Array.isArray(c.ai_fact_check_sources) && c.ai_fact_check_sources.length ? `
        <div style="margin-bottom:10px;padding-top:8px;border-top:1px solid rgba(79,142,247,.15)">
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">📎 Fonti (${c.ai_fact_check_sources.length})</div>
          ${c.ai_fact_check_sources.map((s, i) => `
          <div style="margin-bottom:6px;padding:7px 10px;background:rgba(255,255,255,.03);border-radius:6px;border-left:2px solid rgba(79,142,247,.3)">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
              <span style="font-size:10px;color:var(--muted);font-weight:600">${i + 1}</span>
              <a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer"
                 style="color:var(--accent);font-size:12px;font-weight:500;text-decoration:none;word-break:break-word">${esc(s.title)}</a>
            </div>
            <div style="color:var(--muted);font-size:11.5px;padding-left:16px">${esc(s.summary)}</div>
          </div>`).join('')}
        </div>` : `
        <div style="font-size:11px;color:var(--muted);padding-top:8px;border-top:1px solid rgba(79,142,247,.15)">Nessuna fonte allegata da Sonnet.</div>`}
        <button class="btn" style="width:100%;background:var(--accent);color:#fff;font-weight:600;padding:12px;font-size:14px" onclick="openFactcheckReply()">📣 Pubblica risposta fact-check su Facebook →</button>
      </div>` : ''}
      ${c.ai_whataboutism_suggested && !c.ai_whataboutism_draft ? `
      <div style="background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.25);border-radius:var(--radius);padding:10px 14px;margin-bottom:12px;font-size:12.5px;line-height:1.6">
        <div style="font-weight:600;color:#a855f7;margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.06em">↩️ Whataboutism rilevato dall'AI</div>
        <div style="color:var(--muted)">Il commento devia il tema. Valuta una breve risposta che riporti la discussione in-topic.</div>
      </div>` : ''}
      ${c.ai_whataboutism_draft ? `
      <div style="background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.2);border-radius:var(--radius);padding:12px 14px;margin-bottom:12px;font-size:12.5px;line-height:1.6">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <div style="font-weight:600;color:#a855f7;font-size:11px;text-transform:uppercase;letter-spacing:.06em">↩️ Risposta whataboutism proposta dall'AI</div>
          ${c.ai_whataboutism_confidence ? `<span style="font-size:11px;padding:2px 7px;border-radius:20px;background:rgba(168,85,247,.15);color:#a855f7">confidenza ${Math.round(c.ai_whataboutism_confidence * 100)}%</span>` : ''}
        </div>
        <div style="color:var(--text);margin-bottom:10px;white-space:pre-wrap">${esc(c.ai_whataboutism_draft)}</div>
        <button class="btn" style="width:100%;background:#a855f7;color:#fff;font-weight:600;padding:12px;font-size:14px" onclick="openWhataboutismReply()">📣 Pubblica risposta su Facebook →</button>
      </div>` : ''}
      <textarea class="note-input" id="mod-note" rows="2" placeholder="Nota interna opzionale…"></textarea>
      ${c.ai_fact_check_draft ? `
      <div style="font-size:11px;color:var(--muted);margin:4px 0 8px;text-transform:uppercase;letter-spacing:.05em">Azioni alternative</div>` : ''}
      <div class="action-grid">
        <button class="btn btn-approve" onclick="decide('allow')">✓ Approva commento</button>
        <button class="btn" style="background:#fff8e7;color:#92400e;border:1px solid rgba(247,178,68,.35)" onclick="decide('hide')">🙈 Nascondi + notifica utente</button>
        <button class="btn" style="background:var(--surface2,#f8fafc);color:var(--muted);border:1px solid var(--border)" onclick="decide('hide_silent')">🔇 Nascondi senza notifica</button>
      </div>
    </div>`;
}

async function decide(decision) {
  if (!currentComment) return;
  const note = document.getElementById('mod-note')?.value || '';

  // Disable all buttons and show spinner on the clicked one
  const btns = document.querySelectorAll('.action-grid .btn');
  const clickedBtn = event?.target?.closest('.btn');
  btns.forEach(b => { b.disabled = true; b.style.opacity = '.45'; });
  if (clickedBtn) {
    clickedBtn.style.opacity = '1';
    clickedBtn._origHTML = clickedBtn.innerHTML;
    clickedBtn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:7px">
      <svg style="animation:spin .7s linear infinite;flex-shrink:0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
      In corso…
    </span>`;
  }

  try {
    const payload = decision === 'hide_silent'
      ? { decision: 'hide', note, silent: true }
      : { decision, note };
    const res = await api(`/comments/${currentComment.id}/decide`, 'POST', payload);

    // Per i nascondimenti: verifica l'esito REALE su Facebook, non assumere successo.
    if ((decision === 'hide' || decision === 'hide_silent') && res && res.fb_hidden === false) {
      btns.forEach(b => { b.disabled = false; b.style.opacity = ''; });
      if (clickedBtn && clickedBtn._origHTML) clickedBtn.innerHTML = clickedBtn._origHTML;
      splash('Facebook ha rifiutato il nascondimento',
             res.fb_error || 'Il commento non è stato nascosto online. Verifica il token della pagina o se il commento esiste ancora.',
             { type: 'err', duration: 5000 });
      return;
    }

    const toastMsg = {
      allow:        'Commento approvato',
      hide:         'Commento nascosto · utente notificato',
      hide_silent:  'Commento nascosto',
    }[decision] ?? 'Fatto';
    toast(toastMsg, 'ok');
    currentComment = null;
    document.getElementById('detail-content').style.display = 'none';
    document.getElementById('detail-empty').style.display   = 'flex';
    loadQueue(); loadStats();
  } catch (e) {
    // Restore buttons on error
    btns.forEach(b => { b.disabled = false; b.style.opacity = ''; });
    if (clickedBtn && clickedBtn._origHTML) clickedBtn.innerHTML = clickedBtn._origHTML;
    toast('Errore: ' + e.message, 'err');
  }
}

// ── Fact-check reply (punto 6) ────────────────────────────────────
function openFactcheckReply() {
  if (!currentComment) return;

  const draft   = currentComment.ai_fact_check_draft || '';
  const sources = Array.isArray(currentComment.ai_fact_check_sources)
    ? currentComment.ai_fact_check_sources.filter(s => s && s.url)
    : [];

  // Il moderatore può editare la bozza prima di pubblicare. Gli URL sono ammessi
  // sia inline nel testo sia tramite il pannello fonti sotto (lasciato come
  // riferimento visivo, utile quando preferisce citarle per nome nel reply).
  document.getElementById('factcheck-reply-text').value = draft;
  document.getElementById('factcheck-reply-err').style.display = 'none';

  // Pannello fonti in sola lettura — riferimento visivo per il moderatore
  const sourcesRef  = document.getElementById('factcheck-sources-ref');
  const sourcesList = document.getElementById('factcheck-sources-list');
  if (sources.length && sourcesRef && sourcesList) {
    sourcesList.innerHTML = sources.map((s, i) => `
      <div style="margin-bottom:6px;padding:6px 10px;background:rgba(255,255,255,.03);border-radius:6px">
        <div style="display:flex;gap:6px;align-items:baseline">
          <span style="font-size:10px;color:var(--muted);font-weight:600;flex-shrink:0">${i + 1}</span>
          <div>
            <a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer"
               style="color:var(--accent);font-size:12px;font-weight:500;text-decoration:none">${esc(s.title)}</a>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">${esc(s.summary || '')}</div>
          </div>
        </div>
      </div>`).join('');
    sourcesRef.style.display = 'block';
  } else if (sourcesRef) {
    sourcesRef.style.display = 'none';
  }

  openModal('modal-factcheck-reply');
}

async function confirmFactcheckReply() {
  if (!currentComment) return;
  const text    = document.getElementById('factcheck-reply-text').value.trim();
  const errEl   = document.getElementById('factcheck-reply-err');
  const btn     = document.querySelector('#modal-factcheck-reply .btn-primary');
  errEl.style.display = 'none';

  if (!text) {
    errEl.textContent = 'Il testo non può essere vuoto.';
    errEl.style.display = 'block';
    return;
  }

  // Show spinner
  const originalLabel = btn.textContent;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px"></span>Pubblicazione…';

  try {
    // 1. Pubblica la risposta su Facebook (reply() ritorna 502 se FB rifiuta → throw).
    const r = await api(`/comments/${currentComment.id}/reply`, 'POST', { text });

    // Dev mode: nulla è stato inviato davvero → non approvare né dichiarare pubblicato.
    if (r && r.dev_mode) {
      closeModal('modal-factcheck-reply');
      toast('Dev mode attivo: la risposta NON è stata inviata su Facebook', 'err');
      btn.disabled = false;
      btn.textContent = originalLabel;
      return;
    }

    // 2. Approva il commento — esce dalla coda (solo se la risposta è stata pubblicata).
    await api(`/comments/${currentComment.id}/decide`, 'POST', {
      decision: 'allow',
      note:     'Approvato dopo risposta fact-check inviata dal moderatore',
    });

    closeModal('modal-factcheck-reply');
    splash('Risposta pubblicata su Facebook', 'Commento approvato e rimosso dalla coda.', { type: 'ok' });

    currentComment = null;
    document.getElementById('detail-content').style.display = 'none';
    document.getElementById('detail-empty').style.display   = 'flex';
    loadQueue();
    loadStats();

  } catch(e) {
    errEl.textContent = e.message || 'Errore durante l\'invio.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = originalLabel;
  }
}

// ── Whataboutism reply (parallelo al fact-check, senza fonti) ────────
function openWhataboutismReply() {
  if (!currentComment) return;
  const draft = currentComment.ai_whataboutism_draft || '';
  document.getElementById('whataboutism-reply-text').value = draft;
  document.getElementById('whataboutism-reply-err').style.display = 'none';
  openModal('modal-whataboutism-reply');
}

async function confirmWhataboutismReply() {
  if (!currentComment) return;
  const text  = document.getElementById('whataboutism-reply-text').value.trim();
  const errEl = document.getElementById('whataboutism-reply-err');
  const btn   = document.querySelector('#modal-whataboutism-reply .btn-primary');
  errEl.style.display = 'none';

  if (!text) {
    errEl.textContent = 'Il testo non può essere vuoto.';
    errEl.style.display = 'block';
    return;
  }

  const originalLabel = btn.textContent;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px"></span>Pubblicazione…';

  try {
    const r = await api(`/comments/${currentComment.id}/reply`, 'POST', { text });

    if (r && r.dev_mode) {
      closeModal('modal-whataboutism-reply');
      toast('Dev mode attivo: la risposta NON è stata inviata su Facebook', 'err');
      btn.disabled = false;
      btn.textContent = originalLabel;
      return;
    }

    await api(`/comments/${currentComment.id}/decide`, 'POST', {
      decision: 'allow',
      note:     'Approvato dopo risposta whataboutism inviata dal moderatore',
    });

    closeModal('modal-whataboutism-reply');
    splash('Risposta pubblicata su Facebook', 'Commento approvato e rimosso dalla coda.', { type: 'ok' });

    currentComment = null;
    document.getElementById('detail-content').style.display = 'none';
    document.getElementById('detail-empty').style.display   = 'flex';
    loadQueue();
    loadStats();

  } catch(e) {
    errEl.textContent = e.message || 'Errore durante l\'invio.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = originalLabel;
  }
}

// ── Bans ──────────────────────────────────────────────────────────
let currentBanViolations = 'all';
let currentBcFilter      = 'all';

async function loadBanStats() {
  try {
    const d = await api('/bans/stats');
    document.getElementById('bs-temp').textContent = d.active_bans_by_type?.temp_ban ?? 0;
  } catch (e) {}
}

async function loadBans() {
  const tbody   = document.getElementById('ban-table-body');
  const countEl = document.getElementById('ban-count');
  tbody.innerHTML = '<tr><td colspan="8" class="loading">Caricamento…</td></tr>';
  try {
    const url = currentBanViolations === 'all'
      ? '/bans?limit=50'
      : `/bans?limit=50&violations=${currentBanViolations}`;
    const d = await api(url);
    countEl.textContent = `${d.total} utenti`;
    if (!d.items?.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="empty">Nessun ban attivo</td></tr>';
      return;
    }
    tbody.innerHTML = d.items.map(b => `
      <tr>
        <td>
          <div class="ban-user-cell">
            <div class="ban-avatar">${(b.display_name||'?')[0].toUpperCase()}</div>
            <div>
              <div class="ban-name">${esc(b.display_name || 'Anonimo')}</div>
              <div class="ban-meta">${b.violation_count} violaz. · <a href="#" onclick="openDrawer(${b.social_user_id});return false" style="color:var(--accent);text-decoration:none">storico →</a></div>
            </div>
          </div>
        </td>
        <td style="font-size:12px;color:var(--muted)">${esc(b.page_name || 'Tutte')}</td>
        <td>${b.is_permanent ? '<span class="badge-perm">PERMANENTE</span>' : '<span class="badge-temp">TEMPORANEO</span>'}</td>
        <td>${b.decided_by === 'ai' ? '<span class="badge-ai">AI</span>' : '<span class="badge-human">Umano</span>'}</td>
        <td><div class="ban-reason" title="${esc(b.reason)}">${esc(b.reason || '—')}</div></td>
        <td style="font-size:12px;color:var(--muted);white-space:nowrap">${relTime(b.banned_at)}</td>
        <td style="font-size:12px;color:var(--muted);white-space:nowrap">${b.expires_at ? untilTime(b.expires_at) : '∞'}</td>
        <td><button class="btn-sm" onclick="doLiftBan(${b.social_user_id},'${esc(b.display_name||'questo utente')}')">Revoca</button></td>
      </tr>`).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Errore nel caricamento</td></tr>';
  }
}

function setBanViolationsFilter(val, el) {
  currentBanViolations = val;
  document.querySelectorAll('[data-ban-violations]').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadBans();
}

async function doLiftBan(userId, name) {
  if (!confirm(`Revocare il ban di ${name}?`)) return;
  try {
    await api(`/users/${userId}/ban`, 'DELETE', { reason: 'Revoca manuale da dashboard' });
    toast('Ban revocato', 'ok');
    loadBans(); loadBanStats(); loadStats();
  } catch (e) { toast('Errore nella revoca', 'err'); }
}

// ── Banned comments ───────────────────────────────────────────────
async function loadBannedComments() {
  const list    = document.getElementById('bc-list');
  const countEl = document.getElementById('bc-count');
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const url = currentBcFilter === 'all'
      ? '/bans/comments?limit=50'
      : `/bans/comments?limit=50&decided_by=${currentBcFilter}`;
    const d = await api(url);
    countEl.textContent = `${d.total} commenti`;
    if (!d.items?.length) { list.innerHTML = '<div class="empty">Nessun commento rimosso</div>'; return; }
    const stageLabel = { haiku:'Haiku', sonnet:'Sonnet', human:'Umano' };
    list.innerHTML = d.items.map(c => {
      const cats    = (c.ai_categories||[]).map(cat => `<span class="chip chip-warn">${CAT_LABELS[cat]||cat}</span>`).join(' ');
      const conf    = c.ai_confidence ? Math.round(c.ai_confidence*100)+'%' : '—';
      const decider = c.decided_by === 'human'
        ? `<span class="badge-human">Umano${c.decided_by_name ? ': '+esc(c.decided_by_name) : ''}</span>`
        : `<span class="badge-ai">AI · ${stageLabel[c.ai_stage]||c.ai_stage}</span>`;

      // Build Facebook permalink: comment link if we have both IDs, otherwise post link
      let fbLink = '';
      if (c.platform_comment_id) {
        // Comment permalink: https://www.facebook.com/{page_id}?comment_id={comment_id}
        // Fallback to post if platform_post_id is available
        const postId    = c.platform_post_id  || '';
        const commentId = c.platform_comment_id;
        const pageId    = c.facebook_page_id  || '';
        if (postId) {
          fbLink = `https://www.facebook.com/permalink.php?story_fbid=${postId.split('_')[1]||postId}&id=${pageId}&comment_id=${commentId}`;
        } else if (pageId) {
          fbLink = `https://www.facebook.com/${pageId}/posts/${commentId}`;
        }
      } else if (c.platform_post_id) {
        const parts  = c.platform_post_id.split('_');
        const pageId = c.facebook_page_id || parts[0] || '';
        const postId = parts[1] || c.platform_post_id;
        fbLink = `https://www.facebook.com/permalink.php?story_fbid=${postId}&id=${pageId}`;
      }

      return `
        <div class="bc-item">
          <div class="bc-header">
            <div class="ban-avatar" style="width:26px;height:26px;font-size:10px">${(c.display_name||'?')[0].toUpperCase()}</div>
            <span class="bc-user">${esc(c.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(c.page_name)}</span>
            <span class="bc-time">${relTime(c.processed_at||c.received_at)}</span>
            ${fbLink ? `<a href="${fbLink}" target="_blank" rel="noopener" class="btn-sm" style="margin-left:auto;text-decoration:none" title="Apri su Facebook">🔗 Vedi su Facebook</a>` : ''}
          </div>
          <div class="bc-content">${esc(c.content)}</div>
          <div class="bc-footer">
            ${decider} ${aiSignalChips(c)} ${cats}
            ${c.ai_reason ? `<span style="font-size:11px;color:var(--muted);flex-basis:100%;margin-top:4px">${esc(c.ai_reason)}</span>` : ''}
            <span class="bc-conf">${conf}</span>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function setBcFilter(val, el) {
  currentBcFilter = val;
  document.querySelectorAll('#screen-banned-comments .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadBannedComments();
}

// ── Approved comments ─────────────────────────────────────────────
let currentAcFilter       = 'all'; // decided_by: all|ai|human
let currentAcSignalFilter = 'all'; // signal: all|any|fact_check|whataboutism|none

async function loadApprovedComments() {
  const list    = document.getElementById('ac-list');
  const countEl = document.getElementById('ac-count');
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const params = new URLSearchParams({ limit: 50 });
    if (currentAcFilter       !== 'all') params.set('decided_by', currentAcFilter);
    if (currentAcSignalFilter !== 'all') params.set('signal',     currentAcSignalFilter);
    const url = `/comments/approved?${params.toString()}`;
    const d = await api(url);
    countEl.textContent = `${d.total} commenti`;
    if (!d.items?.length) { list.innerHTML = '<div class="empty">Nessun commento approvato</div>'; return; }
    const stageLabel = { haiku:'Haiku', sonnet:'Sonnet', human:'Umano' };
    list.innerHTML = d.items.map(c => {
      const cats    = (c.ai_categories||[]).map(cat => `<span class="chip chip-ok">${CAT_LABELS[cat]||cat}</span>`).join(' ');
      const conf    = c.ai_confidence ? Math.round(c.ai_confidence*100)+'%' : '—';
      const decider = c.decided_by === 'human'
        ? `<span class="badge-human">Umano${c.decided_by_name ? ': '+esc(c.decided_by_name) : ''}</span>`
        : c.decided_by === 'ai_fact_check'
          ? `<span class="badge-ai" style="background:rgba(79,142,247,.15);color:var(--accent)">AI · Fact-check${c.ai_fact_check_confidence ? ' '+Math.round(c.ai_fact_check_confidence*100)+'%' : ''}</span>`
          : c.decided_by === 'ai_whataboutism'
            ? `<span class="badge-ai" style="background:rgba(168,85,247,.15);color:#a855f7">AI · Whataboutism${c.ai_whataboutism_confidence ? ' '+Math.round(c.ai_whataboutism_confidence*100)+'%' : ''}</span>`
            : `<span class="badge-ai">AI · ${stageLabel[c.ai_stage]||c.ai_stage}</span>`;

      let fbLink = '';
      if (c.platform_comment_id) {
        const postId    = c.platform_post_id  || '';
        const commentId = c.platform_comment_id;
        const pageId    = c.facebook_page_id  || '';
        if (postId) {
          fbLink = `https://www.facebook.com/permalink.php?story_fbid=${postId.split('_')[1]||postId}&id=${pageId}&comment_id=${commentId}`;
        } else if (pageId) {
          fbLink = `https://www.facebook.com/${pageId}/posts/${commentId}`;
        }
      } else if (c.platform_post_id) {
        const parts  = c.platform_post_id.split('_');
        const pageId = c.facebook_page_id || parts[0] || '';
        const postId = parts[1] || c.platform_post_id;
        fbLink = `https://www.facebook.com/permalink.php?story_fbid=${postId}&id=${pageId}`;
      }

      return `
        <div class="bc-item">
          <div class="bc-header">
            <div class="ban-avatar" style="width:26px;height:26px;font-size:10px;background:var(--success)">${(c.display_name||'?')[0].toUpperCase()}</div>
            <span class="bc-user">${esc(c.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(c.page_name)}</span>
            <span class="bc-time">${relTime(c.processed_at||c.received_at)}</span>
            ${fbLink ? `<a href="${fbLink}" target="_blank" rel="noopener" class="btn-sm" style="margin-left:auto;text-decoration:none" title="Apri su Facebook">🔗 Vedi su Facebook</a>` : ''}
          </div>
          <div class="bc-content">${esc(c.content)}</div>
          <div class="bc-footer">
            ${decider} ${aiSignalChips(c)} ${cats}
            ${c.ai_reason ? `<span style="font-size:11px;color:var(--muted);flex-basis:100%;margin-top:4px">${esc(c.ai_reason)}</span>` : ''}
            ${c.human_note ? `<span style="font-size:11px;color:var(--muted);flex-basis:100%;margin-top:2px">📝 ${esc(c.human_note)}</span>` : ''}
            <span class="bc-conf">${conf}</span>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function setAcFilter(val, el) {
  currentAcFilter = val;
  // Active state isolato al gruppo decided_by — non azzera il filtro signal.
  document.querySelectorAll('[data-filter-group="ac-decided"] .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadApprovedComments();
}

function setAcSignalFilter(val, el) {
  currentAcSignalFilter = val;
  document.querySelectorAll('[data-filter-group="ac-signal"] .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadApprovedComments();
}

// ── User drawer (drill-down) ──────────────────────────────────────
async function openDrawer(userId) {
  document.getElementById('drawer-overlay').classList.add('open');
  document.getElementById('user-drawer').classList.add('open');
  document.getElementById('drawer-body').innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const [user, comments] = await Promise.all([
      api(`/users/${userId}`),
      api(`/bans/${userId}/comments`),
    ]);
    const u  = user.user || {};
    const bs = user.ban_status || {};
    document.getElementById('drawer-title').textContent = u.display_name || 'Dettaglio utente';

    const commentHtml = (comments.comments || []).map(c => {
      const cats = (c.ai_categories||[]).map(cat => `<span class="chip chip-warn">${CAT_LABELS[cat]||cat}</span>`).join(' ');
      const decider = c.decided_by_human
        ? ` · <span class="badge-human" title="Decisione di un moderatore">${esc(c.decided_by_name)}</span>`
        : '';
      return `
        <div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border)">
          <div style="font-size:12px;color:var(--muted);margin-bottom:6px">
            ${esc(c.page_name)} · ${relTime(c.received_at)}
            · <span class="${c.status==='removed'?'badge-perm':'badge-temp'}">${c.status==='removed'?'Rimosso':'In coda'}</span>${decider}
          </div>
          <div class="bc-content">${esc(c.content)}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
            ${cats}
            ${c.ai_reason ? `<span style="font-size:11px;color:var(--muted)">${esc(c.ai_reason)}</span>` : ''}
          </div>
        </div>`;
    }).join('') || '<div class="empty" style="padding:20px 0">Nessun commento rimosso</div>';

    document.getElementById('drawer-body').innerHTML = `
      <div class="drawer-section">
        <div class="drawer-section-title">Profilo</div>
        <div class="drawer-stat-row">
          <div class="drawer-stat">
            <div class="drawer-stat-val ${u.violation_count>0?'danger':''}">${u.violation_count||0}</div>
            <div class="drawer-stat-lbl">Violazioni totali</div>
          </div>
          <div class="drawer-stat">
            <div class="drawer-stat-val">${u.ban_status||'clean'}</div>
            <div class="drawer-stat-lbl">Stato ban</div>
          </div>
        </div>
        ${bs.banned ? `
          <div style="background:var(--danger-bg);border:1px solid rgba(247,82,82,.2);border-radius:var(--radius);padding:10px 14px;font-size:13px;margin-bottom:14px">
            🚫 Ban ${bs.type==='perm_ban'?'permanente':'temporaneo'}
            ${bs.expires_at ? '· scade '+untilTime(bs.expires_at) : ''}
            <br><span style="font-size:11px;color:var(--muted)">${esc(bs.reason||'')}</span>
          </div>
          <button class="btn btn-approve" style="width:100%;margin-bottom:14px" onclick="doLiftBan(${u.id},'${esc(u.display_name||'utente')}');closeDrawer()">
            ✓ Revoca ban
          </button>` : ''}
      </div>
      <div class="drawer-section">
        <div class="drawer-section-title">Commenti rimossi (${comments.total||0})</div>
        ${commentHtml}
      </div>`;
  } catch (e) {
    document.getElementById('drawer-body').innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function closeDrawer() {
  document.getElementById('drawer-overlay').classList.remove('open');
  document.getElementById('user-drawer').classList.remove('open');
}

// ── Appeals queue ─────────────────────────────────────────────────
async function loadAppeals() {
  const list    = document.getElementById('appeals-list');
  const countEl = document.getElementById('appeals-count');
  if (!list) return;
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const d = await api('/appeals?limit=50');
    countEl && (countEl.textContent = `${d.total} ricorsi`);
    if (!d.items?.length) {
      list.innerHTML = '<div class="empty">Nessun ricorso in attesa ✓</div>';
      return;
    }
    list.innerHTML = d.items.map(a => {
      const cats = (a.ai_categories||[]).map(c => `<span class="chip chip-warn">${CAT_LABELS[c]||c}</span>`).join(' ');
      return `
        <div class="bc-item" id="appeal-${a.appeal_id}">
          <div class="bc-header">
            <div class="ban-avatar" style="width:26px;height:26px;font-size:10px;background:#6366f1">${(a.display_name||'?')[0].toUpperCase()}</div>
            <span class="bc-user">${esc(a.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(a.page_name)}</span>
            <span class="bc-time">${relTime(a.submitted_at)}</span>
          </div>
          <div style="font-size:12px;color:var(--muted);margin:6px 0 4px">Commento nascosto:</div>
          <div class="bc-content" style="background:var(--surface2,#f8fafc)">${esc(a.content)}</div>
          ${a.ai_public_reason ? `<div style="font-size:11.5px;color:var(--muted);margin:6px 0 2px">Motivazione notificata: <em>${esc(a.ai_public_reason)}</em></div>` : ''}
          <div style="font-size:12px;color:var(--muted);margin:8px 0 4px">Motivazione del ricorso:</div>
          <div class="bc-content" style="background:rgba(99,102,241,.06);border-color:rgba(99,102,241,.2)">${esc(a.appeal_text||'—')}</div>
          <div class="bc-footer" style="margin-top:10px">
            ${cats}
            <div style="margin-left:auto;display:flex;gap:8px">
              <button class="btn btn-approve" style="padding:6px 14px;font-size:12px" onclick="decideAppeal(${a.appeal_id}, ${a.comment_id}, 'accept')">✓ Accetta ricorso</button>
              <button class="btn btn-remove"  style="padding:6px 14px;font-size:12px" onclick="decideAppeal(${a.appeal_id}, ${a.comment_id}, 'reject')">✕ Rigetta ricorso</button>
            </div>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

let _pendingAppeal = null;

function decideAppeal(appealId, commentId, decision) {
  _pendingAppeal = { appealId, commentId, decision };
  const isAccept = decision === 'accept';
  document.getElementById('appeal-modal-title').textContent    = isAccept ? '✓ Accetta ricorso' : '✕ Rigetta ricorso';
  document.getElementById('appeal-modal-subtitle').textContent = isAccept
    ? 'Il commento verrà ripristinato e tornerà visibile al pubblico.'
    : 'Il commento rimarrà nascosto. L\'utente riceverà una notifica privata.';
  document.getElementById('appeal-modal-note').value           = '';
  document.getElementById('appeal-modal-err').style.display    = 'none';
  const confirmBtn = document.getElementById('appeal-modal-confirm');
  confirmBtn.className = isAccept ? 'btn-primary' : 'btn btn-remove';
  confirmBtn.textContent = isAccept ? 'Conferma accettazione' : 'Conferma rigetto';
  openModal('modal-appeal-decision');
  setTimeout(() => document.getElementById('appeal-modal-note').focus(), 80);
}

async function confirmAppealDecision() {
  if (!_pendingAppeal) return;
  const { appealId, commentId, decision } = _pendingAppeal;
  const note    = document.getElementById('appeal-modal-note').value.trim();
  const errEl   = document.getElementById('appeal-modal-err');
  const confirmBtn = document.getElementById('appeal-modal-confirm');
  errEl.style.display = 'none';

  const origHTML = confirmBtn.innerHTML;
  confirmBtn.disabled = true;
  confirmBtn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:7px">
    <svg style="animation:spin .7s linear infinite;flex-shrink:0" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
    In corso…
  </span>`;

  try {
    await api(`/appeals/${appealId}/decide`, 'POST', { decision, note });
    closeModal('modal-appeal-decision');
    toast(decision === 'accept'
      ? 'Ricorso accettato · commento ripristinato'
      : 'Ricorso rigettato · utente notificato', 'ok');
    document.getElementById(`appeal-${appealId}`)?.remove();
    _pendingAppeal = null;
    loadStats();
  } catch (e) {
    errEl.textContent = e.message || 'Errore durante l\'operazione.';
    errEl.style.display = 'block';
    confirmBtn.disabled = false;
    confirmBtn.innerHTML = origHTML;
  }
}

// ── Hidden comments ───────────────────────────────────────────────
let currentHcSignalFilter = 'all'; // signal: all|any|fact_check|whataboutism|none

async function loadHiddenComments() {
  const list    = document.getElementById('hidden-list');
  const countEl = document.getElementById('hidden-count');
  if (!list) return;
  list.innerHTML = '<div class="loading">Caricamento…</div>';
  try {
    const params = new URLSearchParams({ limit: 50 });
    if (currentHcSignalFilter !== 'all') params.set('signal', currentHcSignalFilter);
    const d = await api(`/comments/hidden?${params.toString()}`);
    countEl && (countEl.textContent = `${d.total} commenti`);
    if (!d.items?.length) {
      list.innerHTML = '<div class="empty">Nessun commento nascosto</div>';
      return;
    }
    const isAdminOrSupervisor = ['admin','supervisor'].includes(currentUserRole);
    list.innerHTML = d.items.map(c => {
      const cats = (c.ai_categories||[]).map(cat => `<span class="chip chip-warn">${CAT_LABELS[cat]||cat}</span>`).join(' ');
      const appealBadge = c.has_appeal
        ? `<span class="chip" style="background:rgba(99,102,241,.12);color:#4f46e5">ricorso ${c.appeal_status === 'pending' ? 'in attesa' : c.appeal_status}</span>`
        : '';
      const reportBadge = c.is_reportable ? '<span class="chip chip-danger">⚠️ segnalabile</span>' : '';
      const deciderBadge = c.hidden_by_human
        ? `<span class="badge-human" title="Nascosto da un moderatore">Nascosto da: ${esc(c.decided_by_name)}</span>`
        : '';
      const fbLink = c.platform_comment_id && c.platform_post_id
        ? `https://www.facebook.com/permalink.php?story_fbid=${(c.platform_post_id.split('_')[1]||c.platform_post_id)}&id=${c.facebook_page_id}&comment_id=${c.platform_comment_id}`
        : '';
      const replyBlock = c.removal_reply_text
        ? `<div style="margin-top:8px;background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2);border-radius:6px;padding:10px 12px">
             <div style="font-size:10px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">💬 Risposta inviata automaticamente</div>
             <div style="font-size:12.5px;color:var(--text);line-height:1.5;white-space:pre-wrap">${esc(c.removal_reply_text)}</div>
           </div>`
        : '';
      return `
        <div class="bc-item" id="hc-${c.id}">
          <div class="bc-header">
            <div class="ban-avatar" style="width:26px;height:26px;font-size:10px;background:#f7a244">${(c.display_name||'?')[0].toUpperCase()}</div>
            <span class="bc-user">${esc(c.display_name||'Anonimo')}</span>
            <span class="bc-page">${esc(c.page_name)}</span>
            <span class="bc-time">${relTime(c.processed_at||c.received_at)}</span>
            ${reportBadge} ${appealBadge} ${deciderBadge}
            ${fbLink ? `<a href="${fbLink}" target="_blank" rel="noopener" class="btn-sm" style="margin-left:auto;text-decoration:none" title="Vedi su Facebook">🔗</a>` : ''}
          </div>
          <div class="bc-content">${esc(c.content)}</div>
          ${c.ai_reason ? `<div style="font-size:11px;color:var(--muted);margin-top:4px">Motivazione AI: ${esc(c.ai_reason)}</div>` : ''}
          ${replyBlock}
          <div class="bc-footer" style="margin-top:10px">
            ${aiSignalChips(c)} ${cats}
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="empty">Errore nel caricamento</div>';
  }
}

function setHcSignalFilter(val, el) {
  currentHcSignalFilter = val;
  document.querySelectorAll('[data-filter-group="hc-signal"] .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadHiddenComments();
}


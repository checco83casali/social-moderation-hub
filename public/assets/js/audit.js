// ── Registro di audit (solo admin) ────────────────────────────────────
// Chi ha fatto cosa su commenti, utenti e ricorsi. Sola lettura, append-only lato server.
const AUDIT_ACTIONS = {
  'comment.approve':     { label: 'Approvato',            tone: 'ok'   },
  'comment.hide':        { label: 'Nascosto',             tone: 'warn' },
  'comment.restore':     { label: 'Ripristinato',         tone: 'ok'   },
  'comment.keep_hidden': { label: 'Mantenuto nascosto',   tone: 'warn' },
  'comment.reply':       { label: 'Risposta pubblicata',  tone: ''     },
  'comment.report_legal':{ label: 'Segnalazione legale',  tone: 'warn' },
  'user.ban':            { label: 'Ban manuale',          tone: 'warn' },
  'user.unban':          { label: 'Ban revocato',         tone: 'ok'   },
  'user.password_reset': { label: 'Password reimpostata', tone: 'warn' },
  'appeal.accept':       { label: 'Ricorso accolto',      tone: 'ok'   },
  'appeal.reject':       { label: 'Ricorso respinto',     tone: 'warn' },
  'ai_training.start':   { label: 'Training AI avviato',  tone: ''     },
  'ai_training.stop':    { label: 'Training AI fermato',  tone: ''     },
  'ai_training.note':    { label: 'Nota di addestramento', tone: ''    },
  'ai_training.analysis':{ label: 'Proposta di prompt generata', tone: 'ok' },
};
const AUDIT_ROLES = { admin: 'Admin', supervisor: 'Supervisore', moderator: 'Moderatore' };
const AUDIT_STATUS = {
  approved: 'approvato', hidden: 'nascosto', hidden_reportable: 'nascosto (segnalabile)', escalated_human: 'in coda',
  appeal_pending: 'ricorso in attesa', reported_legal: 'segnalato', removed: 'rimosso', pending: 'in attesa',
};

let auditPage = 1;

function auditFilters() {
  const g = id => document.getElementById(id)?.value.trim() || '';
  const p = new URLSearchParams();
  if (g('audit-action'))  p.set('action', g('audit-action'));
  if (g('audit-from'))    p.set('from', g('audit-from'));
  if (g('audit-to'))      p.set('to', g('audit-to'));
  if (g('audit-comment')) p.set('comment_id', g('audit-comment').replace(/\D/g, ''));
  return p;
}

function auditDetails(d) {
  if (!d) return '';
  const bits = [];
  if (d.status_before || d.status_after) {
    bits.push(`${esc(AUDIT_STATUS[d.status_before] || d.status_before || '—')} → ${esc(AUDIT_STATUS[d.status_after] || d.status_after || '—')}`);
  }
  if (d.violations_before !== undefined && d.violations_after !== undefined && d.violations_before !== d.violations_after) {
    bits.push(`violazioni ${d.violations_before} → ${d.violations_after}`);
  }
  if (d.ban_action) bits.push('ban scattato');
  if (d.silent) bits.push('senza avviso');
  if (d.fb_hidden === false) bits.push('<span style="color:var(--danger)">Facebook ha rifiutato il nascondimento</span>');
  if (d.sent === false) bits.push('<span style="color:var(--danger)">Facebook ha rifiutato la risposta</span>');
  if (d.dev_mode) bits.push('dev mode (TEST)');
  const text = d.reply_text || d.text;
  const extra = text
    ? `<div style="margin-top:6px;padding:8px 10px;background:rgba(255,255,255,.03);border-left:2px solid var(--border-hi);border-radius:4px;font-size:12px;color:var(--muted);white-space:pre-wrap">${esc(text)}</div>`
    : '';
  return (bits.length ? `<div style="font-size:11.5px;color:var(--muted);margin-top:4px">${bits.join(' · ')}</div>` : '') + extra;
}

// Mostra il pannello "licenza Advanced" al posto dell'elenco (il server risponde 403 con feature=advanced_audit).
function showAuditWall(on) {
  const wall = document.getElementById('audit-upgrade-wall');
  const panel = document.querySelector('#screen-audit > .panel');
  if (wall)  wall.style.display  = on ? 'block' : 'none';
  if (panel) panel.style.display = on ? 'none'  : '';
}

async function loadAudit(reset = true) {
  const list = document.getElementById('audit-list');
  if (!list) return;
  if (reset) showAuditWall(false);
  if (reset) { auditPage = 1; list.innerHTML = '<div class="loading">Caricamento…</div>'; }
  try {
    const p = auditFilters(); p.set('limit', '50'); p.set('page', String(auditPage));
    const d = await api('/audit?' + p.toString());
    document.getElementById('audit-count').textContent = `${d.total} azioni`;
    const html = d.items.map(r => {
      const a = AUDIT_ACTIONS[r.action] || { label: r.action, tone: '' };
      const color = a.tone === 'ok' ? 'var(--success)' : a.tone === 'warn' ? 'var(--warn)' : 'var(--muted)';
      const who = esc(r.actor_name || (r.actor_id ? `utente #${r.actor_id}` : 'sistema'));
      const target = r.comment_id
        ? `commento #${r.comment_id}${r.comment_excerpt ? ' · “' + esc(r.comment_excerpt) + '”' : ''}`
        : (r.social_user_id ? `utente #${r.social_user_id}` : '');
      return `
        <div class="bc-item">
          <div class="bc-header" style="flex-wrap:wrap">
            <span class="chip" style="color:${color};border:1px solid ${color};background:transparent">${esc(a.label)}</span>
            <span style="font-weight:600;font-size:13px">${who}</span>
            <span style="font-size:11.5px;color:var(--muted)">${esc(AUDIT_ROLES[r.actor_role] || r.actor_role || '')}</span>
            <span class="bc-time" style="margin-left:auto">${esc(r.created_at)}</span>
          </div>
          ${target ? `<div class="bc-content">${target}</div>` : ''}
          ${r.note ? `<div style="font-size:12px;color:var(--muted);margin-top:6px">Nota: ${esc(r.note)}</div>` : ''}
          ${auditDetails(r.details)}
        </div>`;
    }).join('');
    const more = d.total > auditPage * d.per_page
      ? `<div style="padding:14px;text-align:center"><button class="btn-secondary" onclick="auditPage++;loadAudit(false)">Carica altre</button></div>` : '';
    if (reset) list.innerHTML = html || '<div class="empty">Nessuna azione registrata</div>';
    else { list.querySelector('[data-more]')?.remove(); list.insertAdjacentHTML('beforeend', html); }
    if (more) list.insertAdjacentHTML('beforeend', `<div data-more>${more}</div>`);
  } catch (e) {
    if (/Pro license required/i.test(e.message || '')) { showAuditWall(true); return; }
    if (reset) list.innerHTML = `<div class="empty">${esc(e.message || 'Errore nel caricamento')}</div>`;
    else toast('Errore: ' + e.message, 'err');
  }
}

// Esportazione CSV (richiede l'header Authorization, quindi fetch + blob).
async function exportAudit() {
  try {
    const r = await fetch(HUB_URL + '/api/audit/export?' + auditFilters().toString(), { headers: { 'Authorization': 'Bearer ' + TOKEN } });
    if (!r.ok) { let m = 'HTTP ' + r.status; try { const j = await r.json(); if (j.error) m = j.error; } catch (_) {} throw new Error(m); }
    const url = URL.createObjectURL(await r.blob());
    const a = document.createElement('a');
    a.href = url; a.download = `audit-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (e) { toast('Esportazione non riuscita: ' + e.message, 'err'); }
}

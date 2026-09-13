// ── Diritti dell'interessato (artt. 15/17/20 GDPR) — pannello admin ───

let gdprCurrentUserId = null;

async function gdprSearch() {
  const q = document.getElementById('gdpr-search-q').value.trim();
  if (!q) return;

  document.getElementById('gdpr-anonymise-confirm').style.display = 'none';

  try {
    const d = await api('/gdpr/search?q=' + encodeURIComponent(q));

    if (!d.found) {
      gdprCurrentUserId = null;
      document.getElementById('gdpr-result').style.display    = 'none';
      document.getElementById('gdpr-not-found').style.display = 'block';
      return;
    }

    document.getElementById('gdpr-not-found').style.display = 'none';
    gdprCurrentUserId = d.social_user.id;
    renderGdprResult(d);
    document.getElementById('gdpr-result').style.display = 'block';
  } catch (e) { toast(e.message || 'Errore ricerca', 'err'); }
}

function renderGdprResult(d) {
  const u = d.social_user;
  const banLabels = {
    clean:       'Nessun ban',
    warned:      'Avvisato',
    temp_banned: 'Bannato (temporaneo)',
    perm_banned: 'Bannato',
  };

  document.getElementById('gdpr-result-summary').innerHTML = `
    <strong>${esc(u.display_name || '(anonimizzato)')}</strong> — ID interno #${u.id}<br>
    ID piattaforma: <code>${esc(u.platform_user_id)}</code><br>
    Stato ban: ${esc(banLabels[u.ban_status] || u.ban_status)} · Violazioni: ${u.violation_count ?? 0}<br>
    Registrato dal: ${u.created_at ? new Date(u.created_at).toLocaleString('it-IT') : '—'}<br>
    Dati collegati: ${d.comments.length} commenti · ${d.ban_records.length} ban ·
    ${d.appeal_records.length} appelli · ${d.moderation_log.length} voci di log
  `;
}

async function gdprExport() {
  if (!gdprCurrentUserId) return;
  const token = localStorage.getItem('mh_token');

  try {
    const res = await fetch(`/api/gdpr/export/${gdprCurrentUserId}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!res.ok) { toast('Errore export dati', 'err'); return; }

    const blob     = await res.blob();
    const filename = `dsar-export-${gdprCurrentUserId}-${new Date().toISOString().slice(0,10)}.json`;
    const url      = URL.createObjectURL(blob);
    const a        = document.createElement('a');
    a.href         = url;
    a.download     = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10000);

    toast('Export scaricato', 'ok');
    loadGdprAuditLog();
  } catch (e) { toast('Errore export dati', 'err'); }
}

function gdprShowAnonymiseConfirm() {
  document.getElementById('gdpr-anonymise-confirm').style.display = 'block';
}

async function gdprConfirmAnonymise() {
  if (!gdprCurrentUserId) return;

  const reason = document.getElementById('gdpr-anonymise-reason').value.trim();
  if (!reason) { toast('Indica la motivazione della richiesta', 'err'); return; }

  try {
    await api(`/gdpr/anonymise/${gdprCurrentUserId}`, 'POST', { confirm: true, reason });

    toast('Utente anonimizzato', 'ok');
    document.getElementById('gdpr-anonymise-confirm').style.display = 'none';
    document.getElementById('gdpr-result').style.display            = 'none';
    document.getElementById('gdpr-search-q').value = '';
    gdprCurrentUserId = null;
    loadGdprAuditLog();
  } catch (e) { toast(e.message || 'Errore anonimizzazione', 'err'); }
}

async function loadGdprAuditLog() {
  const box = document.getElementById('gdpr-audit-log');
  if (!box) return;

  const actionLabels = { search: 'Ricerca', export: 'Export', anonymise: 'Anonimizzazione' };

  try {
    const d = await api('/gdpr/audit');
    if (!d.log.length) {
      box.innerHTML = '<span style="color:var(--muted)">Nessuna richiesta registrata.</span>';
      return;
    }
    box.innerHTML = d.log.map(r => `
      <div style="padding:6px 0;border-bottom:1px solid var(--border)">
        <strong>${esc(actionLabels[r.action] || r.action)}</strong> —
        utente interno #${r.social_user_id ?? '—'} — ${esc(r.admin_name || '—')} —
        ${new Date(r.created_at).toLocaleString('it-IT')}
        ${r.reason ? `<br><span style="color:var(--muted)">${esc(r.reason)}</span>` : ''}
      </div>
    `).join('');
  } catch (e) {
    box.innerHTML = '<span style="color:var(--danger)">Errore caricamento registro.</span>';
  }
}

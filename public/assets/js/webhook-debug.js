// Debug webhook Facebook — Impostazioni → Sistema (solo admin).
// Attiva la registrazione estesa e mostra gli ultimi eventi ricevuti.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  let timer = null;

  function setMsg(text, kind) {
    const el = $('whd-msg');
    if (!el) return;
    el.textContent = text || '';
    el.style.display = text ? 'block' : 'none';
    el.style.color = kind === 'err' ? 'var(--danger)' : kind === 'ok' ? 'var(--success)' : 'var(--muted)';
  }

  function fmtJson(raw) {
    try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (_) { return String(raw || ''); }
  }

  function renderEvents(events) {
    const list = $('whd-list');
    if (!list) return;
    if (!events.length) {
      list.innerHTML = '<div style="color:var(--muted)">Nessun evento registrato.</div>';
      return;
    }
    list.innerHTML = events.map(e => {
      const bad = e.event_type === 'invalid_signature' || e.event_type === 'invalid_json' || e.error;
      const state = bad ? '⚠' : (Number(e.processed) ? '✓' : '…');
      const dbg = e.debug ? `<div style="margin:8px 0 4px;font-weight:600">Diagnostica</div><pre style="${PRE}">${esc(JSON.stringify(e.debug, null, 2))}</pre>` : '';
      const err = e.error ? `<div style="color:var(--danger);margin:8px 0 4px">Errore: ${esc(e.error)}</div>` : '';
      return `<details style="border:1px solid var(--border);border-radius:var(--radius);padding:8px 10px;margin-bottom:6px">
        <summary style="cursor:pointer"><span style="color:${bad ? 'var(--warn)' : 'var(--success)'}">${state}</span>
          <strong>#${esc(e.id)}</strong> · ${esc(e.event_type)} · ${esc(e.received_at)}${e.page_id ? ' · pagina ' + esc(e.page_id) : ''}</summary>
        ${err}
        <div style="margin:8px 0 4px;font-weight:600">Payload ricevuto</div>
        <pre style="${PRE}">${esc(fmtJson(e.payload))}</pre>${dbg}
      </details>`;
    }).join('');
  }
  const PRE = 'margin:0;padding:8px;background:var(--bg-hover);border-radius:6px;overflow:auto;max-height:320px;font-family:var(--mono,monospace);font-size:11.5px;white-space:pre-wrap;word-break:break-all';

  function applyStatus(d) {
    const t = $('whd-toggle'), l = $('whd-label');
    if (t) t.checked = !!d.active;
    if (l) {
      l.textContent = d.active ? '⚠ Debug ATTIVO fino alle ' + new Date(d.until).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : 'Debug disattivo';
      l.style.color = d.active ? 'var(--warn)' : '';
    }
    // Finché è attivo l'elenco si aggiorna da solo.
    clearInterval(timer);
    timer = d.active ? setInterval(() => { if ($('webhook-debug-panel') && $('webhook-debug-panel').offsetParent) loadWebhookDebug(true); }, 10000) : null;
  }

  async function loadWebhookDebug(quiet) {
    try {
      const d = await api('/webhook-debug');
      applyStatus(d);
      renderEvents(d.events || []);
      if (!quiet) setMsg('');
    } catch (err) {
      if (!quiet) setMsg(err.message, 'err');
    }
  }
  window.loadWebhookDebug = loadWebhookDebug;

  document.addEventListener('DOMContentLoaded', () => {
    const t = $('whd-toggle');
    if (!t) return;
    t.addEventListener('change', async () => {
      try {
        const d = await api('/webhook-debug', 'POST', { enabled: t.checked });
        applyStatus(d);
        setMsg(d.active ? 'Debug attivato per 2 ore.' : 'Debug disattivato.', 'ok');
        loadWebhookDebug(true);
      } catch (err) {
        t.checked = !t.checked;
        setMsg(err.message, 'err');
      }
    });
    $('whd-refresh').addEventListener('click', () => loadWebhookDebug());
    $('whd-clear').addEventListener('click', async () => {
      if (!confirm('Eliminare tutti gli eventi webhook registrati?')) return;
      try {
        const d = await api('/webhook-debug', 'DELETE');
        setMsg(`Eliminati ${d.deleted} eventi.`, 'ok');
        loadWebhookDebug(true);
      } catch (err) { setMsg(err.message, 'err'); }
    });
  });
})();

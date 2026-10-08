// Debug webhook Facebook — Impostazioni → Sistema (solo admin).
// Attiva la registrazione estesa e mostra gli ultimi eventi, filtrabili per tipo.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const state = { events: [], filter: 'all' };
  let timer = null;

  const REACTIONS = { like: '👍', love: '❤️', haha: '😆', wow: '😮', sad: '😢', angry: '😡', care: '🥰' };

  function setMsg(text, kind) {
    const el = $('whd-msg');
    if (!el) return;
    el.textContent = text || '';
    el.style.display = text ? 'block' : 'none';
    el.style.color = kind === 'err' ? 'var(--danger)' : kind === 'ok' ? 'var(--success)' : 'var(--muted)';
  }

  function parse(raw) { try { return JSON.parse(raw); } catch (_) { return null; } }
  function pretty(raw) { const j = parse(raw); return j ? JSON.stringify(j, null, 2) : String(raw || ''); }

  // Una riga leggibile per ogni modifica contenuta nell'evento.
  function changesOf(e) {
    const p = parse(e.payload);
    const out = [];
    if (!p || !Array.isArray(p.entry)) return out;
    p.entry.forEach(en => (en.changes || []).forEach(c => {
      const v = c.value || {};
      const item = v.item || c.field || 'evento';
      const kind = item === 'comment' ? 'comment' : item === 'reaction' ? 'reaction' : 'other';
      const who = (v.from && v.from.name) || v.sender_name || (v.from && v.from.id) || v.sender_id || 'Qualcuno';
      out.push({ kind, item, verb: v.verb || '', who, v, page: en.id });
    }));
    return out;
  }

  function kindOf(e) {
    if (e.error || e.event_type === 'invalid_signature' || e.event_type === 'invalid_json') return 'error';
    const ch = changesOf(e);
    if (ch.some(c => c.kind === 'comment')) return 'comment';
    if (ch.some(c => c.kind === 'reaction')) return 'reaction';
    return 'other';
  }
  function hasKind(e, f) {
    if (f === 'all') return true;
    if (f === 'error') return kindOf(e) === 'error';
    return changesOf(e).some(c => c.kind === f) || (f === 'other' && kindOf(e) === 'other');
  }

  const VERBS = { add: 'ha commentato', edited: 'ha modificato un commento', remove: 'ha eliminato', hide: 'ha nascosto', unhide: 'ha mostrato' };
  function describe(c) {
    if (c.kind === 'comment') {
      const msg = c.v.message ? ` <span class="whd-msgtxt">“${esc(String(c.v.message).slice(0, 160))}”</span>` : '';
      return `<b>${esc(c.who)}</b> ${esc(VERBS[c.verb] || c.verb || 'commento')}${msg}`;
    }
    if (c.kind === 'reaction') {
      const t = c.v.reaction_type || '';
      const what = c.v.parent_id || c.v.comment_id ? 'un commento' : 'un post';
      return `<b>${esc(c.who)}</b> ${c.verb === 'remove' ? 'ha tolto la reaction' : 'ha messo'} ${REACTIONS[t] || ''} ${esc(t)} su ${what}`;
    }
    return `<b>${esc(c.who)}</b> · ${esc(c.item)}${c.verb ? ' · ' + esc(c.verb) : ''}`;
  }

  function tags(e, k) {
    const t = [];
    if (e.event_type === 'invalid_signature') t.push('<span class="whd-tag t-error">Firma non valida</span>');
    else if (e.event_type === 'invalid_json') t.push('<span class="whd-tag t-error">JSON non valido</span>');
    else if (e.error) t.push('<span class="whd-tag t-error">Errore</span>');
    else {
      const kinds = [...new Set(changesOf(e).map(c => c.kind))];
      if (kinds.includes('comment')) t.push('<span class="whd-tag t-comment">Commento</span>');
      if (kinds.includes('reaction')) t.push('<span class="whd-tag t-reaction">Reaction</span>');
      if (!kinds.length || kinds.includes('other')) t.push('<span class="whd-tag">Altro</span>');
      if (!Number(e.processed)) t.push('<span class="whd-tag t-warn">In elaborazione</span>');
    }
    return t.join('');
  }

  function card(e) {
    const k = kindOf(e);
    const ch = changesOf(e);
    const lines = ch.length
      ? ch.slice(0, 4).map(c => `<div class="whd-line">${describe(c)}</div>`).join('') + (ch.length > 4 ? `<div class="whd-line whd-msgtxt">… e altri ${ch.length - 4}</div>` : '')
      : `<div class="whd-line whd-msgtxt">${esc((e.debug && e.debug.result) || e.event_type)}</div>`;
    const dbg = e.debug ? `<h4>Diagnostica</h4><pre class="whd-pre">${esc(JSON.stringify(e.debug, null, 2))}</pre>` : '';
    const err = e.error ? `<h4>Errore</h4><pre class="whd-pre" style="color:var(--danger)">${esc(e.error)}</pre>` : '';
    return `<details class="whd-card k-${k}">
      <summary>
        <div class="whd-head">${tags(e, k)}<span>#${esc(e.id)}</span>${e.page_id ? `<span>pagina ${esc(e.page_id)}</span>` : ''}<span class="whd-time">${esc(e.received_at)}</span></div>
        ${lines}
      </summary>
      <div class="whd-body">${err}<h4>Payload ricevuto</h4><pre class="whd-pre">${esc(pretty(e.payload))}</pre>${dbg}</div>
    </details>`;
  }

  function render() {
    const list = $('whd-list');
    if (!list) return;
    document.querySelectorAll('#whd-filters .whd-f').forEach(b => {
      const f = b.dataset.f;
      b.classList.toggle('active', f === state.filter);
      b.querySelector('.whd-n').textContent = state.events.filter(e => hasKind(e, f)).length;
    });
    const rows = state.events.filter(e => hasKind(e, state.filter));
    list.innerHTML = rows.length
      ? rows.map(card).join('')
      : '<div class="whd-empty">Nessun evento in questa categoria.</div>';
  }

  function applyStatus(d) {
    const t = $('whd-toggle'), l = $('whd-label');
    if (t) t.checked = !!d.active;
    if (l) {
      l.textContent = d.active
        ? 'Debug attivo fino alle ' + new Date(d.until).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })
        : 'Debug disattivo';
      l.style.color = d.active ? 'var(--warn)' : '';
    }
    clearInterval(timer);
    timer = d.active ? setInterval(() => {
      const p = $('webhook-debug-panel');
      // Non ridisegnare mentre l'utente sta leggendo un evento aperto.
      if (p && p.offsetParent && !p.querySelector('details[open]')) loadWebhookDebug(true);
    }, 10000) : null;
  }

  async function loadWebhookDebug(quiet) {
    try {
      const d = await api('/webhook-debug?limit=60');
      applyStatus(d);
      state.events = d.events || [];
      render();
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
    $('whd-filters').addEventListener('click', ev => {
      const b = ev.target.closest('.whd-f');
      if (!b) return;
      state.filter = b.dataset.f;
      render();
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

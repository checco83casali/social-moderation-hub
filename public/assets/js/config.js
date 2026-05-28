// ── Config & shared state ─────────────────────────────────────────
const HUB_URL = window.MH_URL || '';
let TOKEN = localStorage.getItem('mh_token') || '';
let currentUserRole = '';   // set after login — drives admin-only UI
let currentComment  = null; // comment currently selected in queue
const queueMap = {};        // id → comment object

// Human-readable labels for AI moderation categories
const CAT_LABELS = {
  pig_butchering:        'Truffa sentimentale',
  wallet_theft:          'Furto wallet crypto',
  fake_giveaway:         'Falso giveaway',
  phishing:              'Phishing',
  scam:                  'Truffa generica',
  spam:                  'Spam',
  grooming:              'Adescamento',
  hate_speech:           "Incitamento all'odio",
  harassment:            'Molestia',
  violence:              'Violenza',
  sexual:                'Contenuto sessuale',
  misinformation:        'Disinformazione',
  coordinated_behaviour: 'Comportamento coordinato',
  impersonation:         'Impersonificazione',
  investment_fraud:      'Truffa investimenti',
  fake_testimonial:      'Testimonianza falsa',
  doxxing:               'Doxxing',
  journalist_criticism:  'Critica giornalista',
  outlet_criticism:      'Critica testata',
  illegal_content:       'Contenuto illegale',
};

// Per-category color palette: ogni categoria ha la sua identità visiva nei chip.
// Gruppi cromatici: truffe in rosso, spam in arancione, odio/molestia in magenta,
// disinformazione in viola, sexual/illegal in tonalità più cupe.
const CAT_COLORS = {
  // Truffe e frodi (rossi)
  pig_butchering:        '#d63a3a',
  wallet_theft:          '#b91c1c',
  fake_giveaway:         '#dc2626',
  phishing:              '#ef4444',
  scam:                  '#f75252',
  investment_fraud:      '#c1272d',
  impersonation:         '#e11d48',
  fake_testimonial:      '#9f1239',
  // Spam e coordinati (arancioni / blu chiaro)
  spam:                  '#f7b244',
  coordinated_behaviour: '#4fa8f7',
  // Predatori (rosso scuro / cremisi)
  grooming:              '#a31515',
  // Odio e molestie (magenta / ambra)
  hate_speech:           '#e055a3',
  harassment:            '#e08c44',
  // Violenza
  violence:              '#e05050',
  // Sesso / illegali (cupi)
  sexual:                '#7c3aed',
  illegal_content:       '#991b1b',
  doxxing:               '#831843',
  // Disinformazione (viola)
  misinformation:        '#7c6ef7',
  // Editoriali (azzurri)
  journalist_criticism:  '#4f8ef7',
  outlet_criticism:      '#0ea5e9',
};

// Restituisce un chip colorato per una categoria. Tono: pieno ma non sgargiante
// (background 14% opaco + border 28% + testo solido) così resta leggibile sia
// in tema scuro che chiaro. Fallback a grigio per categorie sconosciute.
function categoryChip(cat) {
  const label = CAT_LABELS[cat] || cat.replace(/_/g, ' ');
  const color = CAT_COLORS[cat] || '#9ca3af';
  // Costruisce rgba dal hex per background e border.
  const r = parseInt(color.slice(1, 3), 16);
  const g = parseInt(color.slice(3, 5), 16);
  const b = parseInt(color.slice(5, 7), 16);
  return `<span class="chip" style="background:rgba(${r},${g},${b},.14);color:${color};border:1px solid rgba(${r},${g},${b},.30)">${label}</span>`;
}

// ── API helper ────────────────────────────────────────────────────
async function api(path, method = 'GET', body = null) {
  const opts = {
    method,
    headers: { 'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json' },
  };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(HUB_URL + '/api' + path, opts);
  if (r.status === 401) { logout(); throw new Error('Non autorizzato'); }
  const data = await r.json();
  if (!r.ok) throw new Error(data?.error || `Errore HTTP ${r.status}`);
  return data;
}

// ── Utils ─────────────────────────────────────────────────────────
function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function relTime(ts) {
  if (!ts) return '';
  // MySQL datetimes have no timezone suffix. We treat them as local server time
  // by replacing the space with 'T' but NOT appending 'Z' (which would force UTC).
  // If the server is in Europe/Rome the browser would show wrong times with 'Z'.
  const d = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(ts) ? ts.replace(' ', 'T') : ts);
  if (isNaN(d)) return '';
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 0)     return 'adesso';
  if (diff < 60)    return diff + 's fa';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm fa';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h fa';
  return Math.floor(diff / 86400) + 'g fa';
}

// Like relTime but for FUTURE timestamps (e.g. ban expiry): "tra 5g", "tra 3h".
function untilTime(ts) {
  if (!ts) return '';
  const d = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(ts) ? ts.replace(' ', 'T') : ts);
  if (isNaN(d)) return '';
  const diff = Math.floor((d.getTime() - Date.now()) / 1000);
  if (diff <= 0)    return 'scaduto';
  if (diff < 60)    return 'tra <1m';
  if (diff < 3600)  return 'tra ' + Math.floor(diff / 60) + 'm';
  if (diff < 86400) return 'tra ' + Math.floor(diff / 3600) + 'h';
  return 'tra ' + Math.floor(diff / 86400) + 'g';
}

let toastTimer;
function toast(msg, type = 'ok') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.className = '', 3000);
}

// ── Splash di conferma (centrato, si chiude da solo) ───────────────
const SPLASH_ICONS = {
  ok:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
  err: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
};
let splashTimer;
/**
 * Mostra un riquadro di conferma centrato che si chiude in automatico.
 * @param {string} title  riga principale
 * @param {string} sub    sottotitolo (opzionale)
 * @param {{type?:'ok'|'err', duration?:number}} opts
 */
function splash(title, sub = '', opts = {}) {
  const type     = opts.type || 'ok';
  const duration = opts.duration || 2500;
  const el  = document.getElementById('splash');
  const bar = document.getElementById('splash-bar');
  document.getElementById('splash-icon').innerHTML  = SPLASH_ICONS[type] || SPLASH_ICONS.ok;
  document.getElementById('splash-title').textContent = title;
  document.getElementById('splash-sub').textContent   = sub;
  el.className = 'show ' + type;
  // (ri)avvia l'animazione della barra di avanzamento
  bar.style.animation = 'none';
  // eslint-disable-next-line no-unused-expressions
  bar.offsetHeight; // reflow
  bar.style.animation = `splashDeplete ${duration}ms linear forwards`;
  clearTimeout(splashTimer);
  splashTimer = setTimeout(closeSplash, duration);
}
function closeSplash() {
  clearTimeout(splashTimer);
  const el = document.getElementById('splash');
  if (el) el.className = '';
}

function renderBarChart(elId, data, colors, labels, sortDesc = false, normaliseToMax = false, keepZero = false) {
  const el = document.getElementById(elId);
  if (!el) return;
  const allEntries = Object.entries(data);
  const entries    = keepZero ? allEntries : allEntries.filter(([, v]) => Number(v) > 0);
  // Se keepZero=true ma TUTTI i valori sono 0, mostra comunque l'avviso vuoto.
  const totalSum = allEntries.reduce((a, [, v]) => a + Number(v), 0);
  if (!entries.length || (keepZero && totalSum === 0)) {
    el.innerHTML = '<div style="padding:20px;text-align:center;font-size:12px;color:var(--muted)">Nessun dato disponibile</div>';
    return;
  }
  if (sortDesc) entries.sort(([, a], [, b]) => Number(b) - Number(a));
  const max   = normaliseToMax ? Math.max(...entries.map(([, v]) => Number(v))) : null;
  const total = normaliseToMax ? null : (entries.reduce((a, [, v]) => a + Number(v), 0) || 1);
  const rows = entries.map(([k, v]) => {
    const label = labels[k] || CAT_LABELS[k] || k.replace(/_/g, ' ');
    const pct   = normaliseToMax
      ? Math.round(Number(v) / max * 100)
      : Math.round(Number(v) / total * 100);
    return `
    <div class="chart-row">
      <div class="chart-label" title="${label}">${label}</div>
      <div class="chart-bar-track"><div class="chart-bar-fill" style="width:${pct}%;background:${colors[k]||'#4f8ef7'}"></div></div>
      <div class="chart-count">${v}</div>
    </div>`;
  }).join('');
  el.innerHTML = `<div class="chart-bar-wrap">${rows}</div>`;
}

// ── AI signal chips ───────────────────────────────────────────────
// Rendering compatto dei flag AI (fact-check / whataboutism) come chip
// indipendenti dalla decisione finale: utile per vedere COSA aveva segnalato
// l'AI anche dopo una decisione umana che ha sovrascritto la pipeline.
function aiSignalChips(c) {
  const out = [];
  if (c.ai_fact_check_suggested) {
    const pct = c.ai_fact_check_confidence ? ' ' + Math.round(c.ai_fact_check_confidence * 100) + '%' : '';
    out.push(`<span class="chip" style="background:rgba(79,142,247,.14);color:var(--accent);border:1px solid rgba(79,142,247,.25)" title="L'AI ha segnalato un'affermazione fattuale da verificare">🔍 Fact-check${pct}</span>`);
  }
  if (c.ai_whataboutism_suggested) {
    const pct = c.ai_whataboutism_confidence ? ' ' + Math.round(c.ai_whataboutism_confidence * 100) + '%' : '';
    out.push(`<span class="chip" style="background:rgba(168,85,247,.14);color:#a855f7;border:1px solid rgba(168,85,247,.25)" title="L'AI ha rilevato una deflessione retorica">↩️ Whataboutism${pct}</span>`);
  }
  return out.join(' ');
}

// ── Modals ────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
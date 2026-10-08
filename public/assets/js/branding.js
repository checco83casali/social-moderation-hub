// Icona personalizzata dell'app (PWA) — Impostazioni → Sistema (solo admin).
// Il ritaglio avviene qui nel browser (canvas); al server arrivano 4 PNG già pronti.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const state = { img: null, objectUrl: null, confirmReset: false };

  function setMsg(text, kind) {
    const el = $('branding-msg');
    if (!el) return;
    el.textContent = text || '';
    el.style.display = text ? 'block' : 'none';
    el.style.color = kind === 'err' ? 'var(--danger)' : kind === 'ok' ? 'var(--success)' : 'var(--muted)';
    el.style.background = kind === 'err' ? 'var(--danger-bg)' : 'transparent';
  }

  // Disegna l'icona alla misura richiesta.
  //  kind: 'any' (Android/desktop), 'apple' (iPhone, sempre opaca), 'maskable' (il sistema la ritaglia a cerchio/squircle)
  function render(size, kind) {
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const g = c.getContext('2d');
    g.imageSmoothingEnabled = true;
    g.imageSmoothingQuality = 'high';
    g.fillStyle = $('branding-bg').value || '#3f6fe0';
    g.fillRect(0, 0, size, size);
    const img = state.img;
    if (!img) return c;
    const iw = img.naturalWidth || 512, ih = img.naturalHeight || 512;
    const cover = document.querySelector('input[name="branding-mode"]:checked').value === 'cover';
    let scale;
    if (cover) scale = Math.max(size / iw, size / ih);                 // riempie tutto il quadrato
    else scale = Math.min(size / iw, size / ih) * (kind === 'maskable' ? 0.66 : 0.84); // con margini (zona sicura per la maschera)
    const w = iw * scale, h = ih * scale;
    g.drawImage(img, (size - w) / 2, (size - h) / 2, w, h);
    return c;
  }

  function paintPreviews() {
    [['branding-prev-any', 192, 'any'], ['branding-prev-apple', 180, 'apple'], ['branding-prev-mask', 192, 'maskable']].forEach(([id, size, kind]) => {
      const el = $(id);
      if (!el) return;
      const c = render(size, kind);
      c.style.width = c.style.height = '100%';
      el.replaceChildren(c);
    });
  }

  function onFile(ev) {
    const file = ev.target.files && ev.target.files[0];
    state.img = null;
    $('branding-upload-btn').disabled = true;
    if (!file) { paintPreviews(); return; }
    if (!/^image\/(png|jpeg|webp|svg\+xml)$/.test(file.type)) {
      setMsg('Formato non supportato: usa PNG, JPG, WebP o SVG.', 'err');
      return;
    }
    if (file.size > 8 * 1024 * 1024) { setMsg('Il file supera 8 MB.', 'err'); return; }
    if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
    state.objectUrl = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      state.img = img;
      setMsg('', '');
      const small = Math.min(img.naturalWidth || 512, img.naturalHeight || 512) < 512 && file.type !== 'image/svg+xml';
      if (small) setMsg('Attenzione: l\'immagine è piccola (meno di 512 px) e potrebbe risultare sgranata.', 'info');
      $('branding-upload-btn').disabled = false;
      paintPreviews();
    };
    img.onerror = () => setMsg('Impossibile leggere l\'immagine.', 'err');
    img.src = state.objectUrl;
  }

  const toBlob = c => new Promise((res, rej) => c.toBlob(b => b ? res(b) : rej(new Error('Conversione non riuscita')), 'image/png'));

  async function upload() {
    if (!state.img) return;
    const btn = $('branding-upload-btn');
    btn.disabled = true;
    setMsg('Caricamento in corso…', 'info');
    try {
      const fd = new FormData();
      fd.append('icon_192',      await toBlob(render(192, 'any')),      'icon-192.png');
      fd.append('icon_512',      await toBlob(render(512, 'any')),      'icon-512.png');
      fd.append('icon_maskable', await toBlob(render(512, 'maskable')), 'icon-maskable.png');
      fd.append('icon_apple',    await toBlob(render(180, 'apple')),    'icon-apple.png');
      const r = await fetch(HUB_URL + '/api/branding/icon', {
        method: 'POST', headers: { 'Authorization': 'Bearer ' + TOKEN }, body: fd,
      });
      if (r.status === 401) { logout(); return; }
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || ('Errore HTTP ' + r.status));
      setMsg('Icona salvata. Per vederla sul telefono rimuovi e reinstalla l\'app.', 'ok');
      $('branding-file').value = '';
      state.img = null;
      loadBranding();
    } catch (e) {
      setMsg(e.message || 'Errore durante il caricamento.', 'err');
      btn.disabled = false;
    }
  }

  async function reset() {
    const btn = $('branding-reset-btn');
    if (!state.confirmReset) {
      state.confirmReset = true;
      btn.textContent = 'Conferma: torna all\'icona predefinita';
      setTimeout(() => { state.confirmReset = false; btn.textContent = 'Ripristina icona predefinita'; }, 5000);
      return;
    }
    state.confirmReset = false;
    btn.textContent = 'Ripristina icona predefinita';
    try {
      await api('/branding/icon', 'DELETE');
      setMsg('Icona predefinita ripristinata.', 'ok');
      loadBranding();
    } catch (e) {
      setMsg(e.message || 'Errore durante il ripristino.', 'err');
    }
  }

  window.loadBranding = async function () {
    try {
      const d = await api('/branding');
      const img = $('branding-current');
      if (img) img.src = '/pwa/icon/512.png?t=' + Date.now();
      const st = $('branding-status');
      if (st) st.textContent = d.custom
        ? 'Icona personalizzata' + (d.updated_at ? ' · caricata il ' + new Date(d.updated_at).toLocaleString('it-IT') : '')
        : 'Icona predefinita';
      const rb = $('branding-reset-btn');
      if (rb) rb.style.display = d.custom ? '' : 'none';
    } catch (e) { /* pannello non essenziale */ }
  };

  document.addEventListener('DOMContentLoaded', () => {
    if (!$('branding-panel')) return;
    $('branding-file').addEventListener('change', onFile);
    $('branding-bg').addEventListener('input', paintPreviews);
    document.querySelectorAll('input[name="branding-mode"]').forEach(r => r.addEventListener('change', paintPreviews));
    $('branding-upload-btn').addEventListener('click', upload);
    $('branding-reset-btn').addEventListener('click', reset);
    paintPreviews();
  });
})();

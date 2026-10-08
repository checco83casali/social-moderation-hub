// Service worker — Moderation Hub (PWA)
//
// Cosa mette in cache: SOLO l'involucro statico dell'app (HTML della dashboard,
// CSS, JS, icone, font). Mai /api, /auth, /webhook né pagine pubbliche: dati
// dei commenti, token e risposte del server non vengono salvati dal service worker.
//
// Strategia: network-first con ripiego sulla cache. Quando sei online ottieni
// sempre l'ultima versione appena deployata (nessuno sfasamento tra HTML e JS);
// offline l'app si apre comunque e mostra lo stato "Offline".
const VERSION = 'mh-v3';
const SHELL_CACHE = `${VERSION}-shell`;
const FONT_CACHE  = `${VERSION}-fonts`;

const SHELL = [
  '/dashboard.html',
  '/offline.html',
  '/manifest.webmanifest',
  '/favicon.svg',
  '/assets/css/app.css',
  '/assets/css/pwa.css',
  '/assets/js/config.js',
  '/assets/js/auth.js',
  '/assets/js/moderation.js',
  '/assets/js/pages-policy.js',
  '/assets/js/settings.js',
  '/assets/js/gdpr.js',
  '/assets/js/app.js',
  '/assets/js/pwa.js',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/apple-touch-icon.png',
];

// Percorsi che il service worker non tocca mai (sempre rete, nessuna cache).
const BYPASS = ['/api/', '/auth/', '/webhook/', '/appeal', '/public/', '/privacy', '/install.php'];

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    // Una risorsa mancante non deve far fallire l'installazione.
    await Promise.all(SHELL.map(async url => {
      try {
        const res = await fetch(url, { cache: 'reload', credentials: 'same-origin' });
        if (res.ok) await cache.put(url, res);
      } catch (_) { /* offline durante l'installazione: si riempie al primo uso */ }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keep = new Set([SHELL_CACHE, FONT_CACHE]);
    for (const key of await caches.keys()) {
      if (!keep.has(key)) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

function withTimeout(promise, ms) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('timeout')), ms);
    promise.then(v => { clearTimeout(t); resolve(v); }, e => { clearTimeout(t); reject(e); });
  });
}

async function networkFirst(request, cacheName, timeoutMs, cacheKey) {
  const cache = await caches.open(cacheName);
  try {
    // cache:'no-cache' = riconferma sempre col server (richiesta condizionale, 304 se
    // invariato): senza, la cache HTTP del browser può servire per ore un CSS/JS vecchio
    // dopo un deploy (euristica sul Last-Modified).
    const res = await withTimeout(fetch(request.url, { cache: 'no-cache', credentials: 'same-origin' }), timeoutMs);
    if (res && res.ok && res.type === 'basic') cache.put(cacheKey || request, res.clone());
    return res;
  } catch (_) {
    const hit = await cache.match(cacheKey || request, { ignoreSearch: true });
    if (hit) return hit;
    throw _;
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const hit = await cache.match(request);
  const refresh = fetch(request).then(res => {
    if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone());
    return res;
  }).catch(() => hit);
  return hit || refresh;
}

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Font di Google: cache a lungo termine (cambiano raramente).
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(staleWhileRevalidate(req, FONT_CACHE));
    return;
  }

  if (url.origin !== self.location.origin) return;
  if (BYPASS.some(p => url.pathname.startsWith(p))) return;

  // Navigazione (apertura/ricarica dell'app).
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      const key = (url.pathname === '/' || url.pathname === '/dashboard.html') ? '/dashboard.html' : null;
      try {
        // La pagina della dashboard è sempre la stessa: la salvo sotto una sola chiave.
        return await networkFirst(req, SHELL_CACHE, 4000, key || undefined);
      } catch (_) {
        const cache = await caches.open(SHELL_CACHE);
        return (key && await cache.match('/dashboard.html'))
            || await cache.match('/offline.html')
            || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
      }
    })());
    return;
  }

  // Risorse statiche dell'app.
  if (url.pathname.startsWith('/assets/') || url.pathname === '/favicon.svg' || url.pathname === '/manifest.webmanifest') {
    event.respondWith(networkFirst(req, SHELL_CACHE, 3500));
  }
});

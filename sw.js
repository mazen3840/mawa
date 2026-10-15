/* موارد سيستم — Service Worker (PWA) */
const CACHE = 'mawared-v18';
const IS_LOCAL = self.location.hostname === 'localhost' || self.location.hostname === '127.0.0.1';
const PRECACHE = [
  './',
  './index.html',
  './collector.html',
  './dataentry.html',
  './admin.html',
  './superadmin.html',
  './calendar.html',
  './operations.html',
  './collection_center.html',
  './my_calls.html',
  './agent.html',
  './assets/mawared.css?v=20261004-brand',
  './assets/app.js?v=20261004-brand',
  './assets/mawared-enterprise.css?v=20261004-brand',
  './assets/mawared-app.js?v=20261004-brand',
  './assets/collection-center.js?v=20261004-linked-actions',
  './assets/admin-common.js?v=20261004-assigned-clients',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/apple-touch-icon.png',
  './assets/brand-logo.png',
  './assets/brand-symbol.png',
  './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  if (IS_LOCAL) {
    event.waitUntil(self.skipWaiting());
    return;
  }
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim()).then(() => IS_LOCAL ? self.registration.unregister() : undefined)
  );
});

function isApi(url) {
  return url.pathname.endsWith('/api.php') || url.pathname.includes('/api.php');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (IS_LOCAL) {
    event.respondWith(fetch(req, { cache: 'no-store' }));
    return;
  }

  // API: network only (never serve stale auth/data)
  if (isApi(url)) {
    event.respondWith(
      fetch(req).catch(() =>
        new Response(JSON.stringify({ success: false, message: 'مفيش اتصال — البيانات دي محتاجة نت' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json; charset=UTF-8' }
        })
      )
    );
    return;
  }

  // HTML: network first, fallback cache
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match('./index.html')))
    );
    return;
  }

  // Static assets: cache first, then network
  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) {
        // revalidate in background
        fetch(req).then((res) => {
          if (res && res.ok) caches.open(CACHE).then((c) => c.put(req, res));
        }).catch(() => {});
        return cached;
      }
      return fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      });
    })
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

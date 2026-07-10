const SW_VERSION = 'ja-tuckshop-v4';

function appBasePath() {
  const path = new URL(self.location.href).pathname;
  return path.replace(/\/sw\.js$/, '');
}

function appUrl(relativePath) {
  const base = appBasePath();
  return `${base}${relativePath.startsWith('/') ? relativePath : '/' + relativePath}`;
}

const PRECACHE = [
  appUrl('/'),
  appUrl('/index.html'),
  appUrl('/manifest.json'),
  appUrl('/assets/css/app.css'),
  appUrl('/assets/js/api.js'),
  appUrl('/assets/js/pwa.js'),
  appUrl('/assets/js/student.js'),
  appUrl('/assets/js/seller.js'),
  appUrl('/assets/icons/icon.svg'),
  appUrl('/student/index.html'),
  appUrl('/seller/index.html'),
  appUrl('/parent/index.html'),
  appUrl('/pos/index.html'),
  appUrl('/admin/index.html')
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SW_VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== SW_VERSION).map((key) => caches.delete(key)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  const basePath = appBasePath();

  if (request.method !== 'GET') return;
  if (url.pathname.startsWith(`${basePath}/api/`)) {
    event.respondWith(
      fetch(request).catch(() =>
        new Response(JSON.stringify({ ok: false, error: 'Offline - API unavailable' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const fetchPromise = fetch(request)
        .then((response) => {
          if (response.ok && url.origin === self.location.origin) {
            const clone = response.clone();
            caches.open(SW_VERSION).then((cache) => cache.put(request, clone));
          }
          return response;
        })
        .catch(() => cached);
      return cached || fetchPromise;
    })
  );
});

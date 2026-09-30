const SHELL_CACHE = 'suhail-flight-shell-v2';
const MAP_CACHE = 'suhail-flight-map-v2';

const SHELL = [
  './',
  './index.php',
  './assets/css/style.css',
  './assets/js/app.js',
  './manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      .then(cache => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => ![SHELL_CACHE, MAP_CACHE].includes(key))
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

function isMapResource(url) {
  return url.hostname === 'tiles.openfreemap.org'
    || url.hostname === 'unpkg.com'
    || url.hostname === 'raw.githubusercontent.com';
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (isMapResource(url)) {
    event.respondWith(
      caches.open(MAP_CACHE).then(async cache => {
        const cached = await cache.match(request);
        if (cached) return cached;

        try {
          const response = await fetch(request);
          if (response && (response.ok || response.type === 'opaque')) {
            cache.put(request, response.clone()).catch(() => {});
          }
          return response;
        } catch {
          return cached || Response.error();
        }
      })
    );
    return;
  }

  if (url.origin === self.location.origin) {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (response.ok && !url.pathname.includes('/api/')) {
            const copy = response.clone();
            caches.open(SHELL_CACHE).then(cache => cache.put(request, copy)).catch(() => {});
          }
          return response;
        })
        .catch(() => caches.match(request).then(cached => cached || caches.match('./')))
    );
  }
});

/* DiveZone /m service worker — CHAT-T-073, ADR-086.
 *
 * Minimalny SW: powłoka aplikacji (HTML/CSS/JS/ikony) w cache, /m/api/*
 * ZAWSZE network (dane na zywo + cookie auth — nigdy cache).
 *
 * Strategia fetch:
 *  - /m/api/*               → bypass SW (zaden cache, zwykly fetch sieciowy).
 *  - /m/ navigation (HTML)  → network-first, fallback do cache index.html
 *                             (offline: pokaz powloke, API uderzy z bledem).
 *  - /m/<asset>             → stale-while-revalidate (szybko z cache,
 *                             potem update w tle).
 *
 * Wersjonowanie cache: zmiana CACHE_VERSION przy nastepnym deploy unicestwia
 * stary shell (activate sprzata stare cache). Bez tego SW byl "lepki" —
 * uzytkownicy widzieli stara wersje JS/CSS po deploy.
 */

const CACHE_VERSION = 'dz-m-v1-2026-06-05';
const CACHE_NAME = CACHE_VERSION;
const SHELL = [
  '/m/',
  '/m/index.html',
  '/m/app.js',
  '/m/styles.css',
  '/m/manifest.webmanifest',
  '/m/icons/icon-192.png',
  '/m/icons/icon-512.png',
  '/m/icons/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.map((k) => (k === CACHE_NAME ? null : caches.delete(k))))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // KRYTYCZNE: /m/api/* NIGDY przez SW cache — dane na żywo + cookie auth.
  // Pomijamy event.respondWith → przegladarka leci wprost siecia.
  const url = new URL(req.url);
  if (url.origin === self.location.origin && url.pathname.startsWith('/m/api/')) {
    return;
  }

  // Tylko GET cache'ujemy. POST/PUT/DELETE → bypass.
  if (req.method !== 'GET') {
    return;
  }

  // Nawigacja (request.mode === 'navigate', np. wejscie na /m/) — network-first
  // z fallbackiem do cache index.html (offline shell).
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        // Zaktualizuj cache shellu w tle.
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put('/m/index.html', copy));
        return res;
      }).catch(() =>
        caches.match('/m/index.html').then((r) => r || new Response('Offline', { status: 503 }))
      )
    );
    return;
  }

  // Statyki same-origin pod /m/ — stale-while-revalidate.
  if (url.origin === self.location.origin && url.pathname.startsWith('/m/')) {
    event.respondWith(
      caches.match(req).then((cached) => {
        const network = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => cached);
        return cached || network;
      })
    );
    return;
  }

  // Inne (cross-origin lub poza /m/) — bypass.
});

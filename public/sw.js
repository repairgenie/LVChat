/*
 * LVChat service worker — Progressive Web App layer.
 *
 * Always on: the app registers this worker on every page. It provides:
 *
 *   1. Installability  — the web app manifest is served at /manifest and the
 *      static shell (CSS/JS/sounds/icons) is precached, so the app opens
 *      instantly from the home screen.
 *   2. Offline reading — pages under /app (the chat shell, with the latest
 *      rendered messages inline) and /browse are cached network-first, and
 *      /api/history + /api/poll responses are cached stale-while-revalidate,
 *      so previously viewed channels/DMs and "Load earlier messages" work
 *      with no connection.
 *   3. Privacy         — only /app, /browse, /terms and /privacy pages are
 *      cached. Form/auth/admin/support pages and every other /api endpoint
 *      are always fetched from the network (never served stale, never leak a
 *      previous user's cached view). Login/logout tell this worker to wipe
 *      the page + data caches via a CLEAR_CACHES message.
 *
 * Bump CACHE_* versions whenever the precache list changes.
 */

'use strict';

const CACHE_STATIC = 'lvc-static-v1';
const CACHE_PAGES = 'lvc-pages-v1';
const CACHE_API = 'lvc-api-v1';

const PRECACHE = [
  '/manifest',
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/assets/vendor/tiptap/tiptap-bundle.js',
  '/assets/sounds/chime.wav',
  '/assets/sounds/ding.wav',
  '/assets/sounds/pop.wav',
  '/assets/pwa/icon-192.png',
  '/assets/pwa/icon-512.png',
  '/assets/pwa/apple-touch-icon.png',
  '/offline.html',
];

const API_CACHEABLE = new Set(['/api/poll', '/api/history']);
const PAGE_CACHEABLE = (path) =>
  path === '/browse' || path === '/terms' || path === '/privacy' || path.startsWith('/app');

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_STATIC)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => ![CACHE_STATIC, CACHE_PAGES, CACHE_API].includes(k)).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// Login/logout (and the chat's profile/session pages) wipe user-specific
// page + data caches so a shared browser never serves a previous user's view.
self.addEventListener('message', (event) => {
  if (!event.data || event.data.type !== 'CLEAR_CACHES') return;
  Promise.all([CACHE_PAGES, CACHE_API].map((name) => caches.delete(name))).then(() => {
    if (event.source && event.source.postMessage) event.source.postMessage({ type: 'CACHES_CLEARED' });
  });
});

// Cache-first for a versioned static asset; drop superseded entries for the
// same path so re-uploads (new ?v= query) never leave orphans behind.
function cacheStatic(request, response) {
  const cachePromise = caches.open(CACHE_STATIC);
  cachePromise.then((cache) => {
    const url = new URL(request.url);
    cache.put(request, response);
    return cache.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k.url.startsWith(url.origin + url.pathname + '?') && k.url !== request.url)
          .map((k) => cache.delete(k))
      )
    );
  });
  return cachePromise;
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;

  // Immutable static assets: cache-first with a network fill.
  if (url.pathname.startsWith('/assets/') || url.pathname === '/offline.html') {
    event.respondWith(
      caches.match(event.request).then((cached) => {
        if (cached) return cached;
        return fetch(event.request).then((response) => {
          if (response.ok) cacheStatic(event.request, response.clone());
          return response;
        });
      })
    );
    return;
  }

  // The manifest is generated from the site name, so prefer fresh over cached
  // when online; the precached copy keeps it available offline.
  if (url.pathname === '/manifest') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_STATIC).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Offline-reading data: /api/poll (live deltas + presence) and /api/history
  // ("Load earlier messages"). Network first, cached response on failure.
  if (API_CACHEABLE.has(url.pathname)) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_API).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Every other /api endpoint (send, command, search, gifs, upload, stream…) is
  // always live — never intercepted, never served from cache.
  if (url.pathname.startsWith('/api/')) return;

  // Read-only pages: network-first with a cached copy for offline reading.
  // Auth, admin, support, and /c join flows are deliberately excluded so a
  // stale form (with an old CSRF token) or a previous user's data is never
  // handed out.
  if (PAGE_CACHEABLE(url.pathname)) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_PAGES).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() =>
          caches.match(event.request).then((cached) =>
            cached || caches.match('/offline.html')
          )
        )
    );
    return;
  }

  // Anything else (login, register, admin, support, uploads, …): network only.
  // The browser's own HTTP cache still applies; we never serve stale shell.
});

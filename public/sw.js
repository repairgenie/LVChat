/*
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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

// ── Push notifications ───────────────────────────────────────────────────────
// The server POSTs encrypted Web Push messages for new channel messages, DMs,
// and channel invites; here they become OS notifications. The payload is JSON:
//   { type: 'channel'|'dm'|'invite', title, body, tag, data:{type, channel|username} }
const PUSH_ICON = '/assets/pwa/icon-192.png';

// Is any open client already looking at the target of this push? If so we skip
// the notification — the message is already on screen.
function clientIsViewing(payload) {
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
    if (!clients.length) return false;
    return clients.some((c) => {
      let u;
      try { u = new URL(c.url); } catch (e) { return false; }
      if (u.origin !== self.location.origin) return false;
      const params = u.searchParams;
      if (payload.type === 'dm' && payload.username) {
        return (params.get('dm') || '').toLowerCase() === String(payload.username).toLowerCase();
      }
      if ((payload.type === 'channel' || payload.type === 'invite') && payload.channel) {
        return (params.get('channel') || '') === payload.channel;
      }
      return false;
    });
  });
}

self.addEventListener('push', (event) => {
  let data = null;
  try { data = event.data ? JSON.parse(event.data.text()) : null; } catch (e) {}
  if (!data || !data.title || !data.data) return;
  const payload = data.data;
  const tagKey = payload.type === 'dm'
    ? 'dm:' + (payload.username || '')
    : 'channel:' + (payload.channel || '');
  event.waitUntil(
    clientIsViewing(payload).then((viewing) => {
      if (viewing) return;
      return self.registration.showNotification(data.title, {
        body: data.body || '',
        icon: PUSH_ICON,
        badge: PUSH_ICON,
        tag: tagKey,
        vibrate: [100, 50, 100],
        data: payload,
      });
    })
  );
});

// Clicking a notification opens (or focuses) the right place in the chat.
self.addEventListener('notificationclick', (event) => {
  const payload = event.notification.data || {};
  event.notification.close();
  let url = '/app';
  if (payload.type === 'channel' && payload.channel) url = '/app?channel=' + encodeURIComponent(payload.channel);
  else if (payload.type === 'dm' && payload.username) url = '/app?dm=' + encodeURIComponent(payload.username);
  else if (payload.type === 'invite' && payload.channel) url = '/app?channel=' + encodeURIComponent(payload.channel);
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const c of clients) {
        if (c.url && c.url.indexOf(self.location.origin) === 0) {
          return c.focus().then(() => c.navigate(url)).catch(() => c.focus());
        }
      }
      return self.clients.openWindow(url);
    })
  );
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

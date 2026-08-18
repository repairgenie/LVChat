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
 * LVChat Messenger Web — service worker (Progressive Web App layer).
 *
 *  1. Installability — the static app shell (HTML/CSS/JS/config/icons) is
 *     precached so the messenger launches instantly and stays readable while
 *     offline. Cache version is stamped at build time (__CACHE_VERSION__).
 *  2. Privacy — ONLY the app shell is cached. Every API call to the LVChat
 *     server (poll, send, history, uploads, avatars) goes straight to the
 *     network: never served stale, never leaks a previous user's session.
 *     Logging out posts a CLEAR_CACHES message that wipes the shell caches.
 *  3. Web Push — closed-tab notifications. The server encrypts a JSON payload
 *     ({type,title,body,tag,data}) that is decrypted by the browser and passed
 *     to this worker; clicking focuses the conversation in the messenger.
 */

'use strict';

const CACHE_SHELL = 'lvcweb-shell-__CACHE_VERSION__';

const PRECACHE = __PRECACHE__;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_SHELL)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_SHELL).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/* Navigation + shell assets: cache-first for speed, then update in the
 * background. Everything else (any cross-origin /api call) is network-only. */
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  const path = url.pathname;
  const isNavigation = event.request.mode === 'navigate';
  const isShell = PRECACHE.some((p) => p === './' + path.replace(/^\//, '')) ||
    /\.(css|js|png|webmanifest)$/.test(path);

  if (!isNavigation && !isShell) return;

  event.respondWith(
    caches.match(event.request, { ignoreSearch: true }).then((cached) => {
      const network = fetch(event.request)
        .then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE_SHELL).then((cache) => cache.put(event.request, copy));
          }
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});

/* Clear all shell caches (sign-out on a shared device). */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'CLEAR_CACHES') {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k))))
    );
  }
});

/* ── Web Push ─────────────────────────────────────────────────────────── */

function openMessenger (conv) {
  let target = './messenger.html';
  if (conv && conv.type && conv.id) {
    target += '?chat=' + encodeURIComponent(conv.type + ':' + conv.id);
    if (conv.msg_id) target += '&jump=' + encodeURIComponent(conv.msg_id);
  }
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    .then((clientList) => {
      for (const client of clientList) {
        if (client.url && client.url.includes('/messenger.html')) {
          return client.navigate(target).then(() => client.focus());
        }
      }
      return self.clients.openWindow(target);
    });
}

self.addEventListener('push', (event) => {
  if (!event.data) return;
  let payload = null;
  try {
    payload = event.data.json();
  } catch (err) {
    return; // not our format — ignore silently
  }
  const p = payload.data || {};
  const type = p.type || payload.type || 'channel';
  const id = p.channel || p.username || null;
  const msgId = p.msg_id || null;
  const title = String(payload.title || 'LVChat Messenger');
  const body = String(payload.body || '');
  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      tag: String(payload.tag || ''),
      icon: './icons/icon-192.png',
      badge: './icons/icon-192.png',
      data: { conv: type && id ? { type, id, msg_id: msgId } : null }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(openMessenger(event.notification.data && event.notification.data.conv));
});

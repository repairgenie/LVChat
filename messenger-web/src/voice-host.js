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

/* LVCVoiceHost — the messenger (Electron / PWA) adapter for the shared voice
 * client. Loaded BEFORE voice.js so the canonical client binds to the
 * messenger's DOM + API layer:
 *   - api():            window.LvApi (bearer token; returns {status, ok, body, …})
 *   - currentChannel(): derived from the #chat-title heading ("#slug" → slug)
 *   - currentDm():      derived from the #chat-title heading (nick when not #…)
 *   - headerEl():       .chat-head-actions (right side of the chat header)
 *   - bgBase:           selfie-segmentation assets shipped under vendor/
 *   - openChannel():    messenger's openRoom() when defined
 *
 * The canonical voice.js requires window.livekit-client + a real LVChat server
 * URL — it stays fully server-gated (nothing renders without /api/webrtc/voice/status).
 */

(function () {
  'use strict';
  if (window.LVCVoiceHost) return;

  window.LVCVoiceHost = {
    bgBase: 'vendor/selfie-segmentation/',

    api: function (path, data) {
      if (!window.LvApi) {
        return Promise.resolve({ ok: false, error: 'not signed in', _status: 0 });
      }
      var req = data ? window.LvApi.postForm(path, data) : window.LvApi.getJson(path);
      return Promise.resolve(req).then(function (r) {
        // LvApi returns {status, ok, body, res} — hand the parsed body to voice.js.
        var j = (r && r.body) || {};
        j._status = (r && r.status) || 0;
        return j;
      }).catch(function (e) {
        return { ok: false, error: String((e && e.message) || e), _status: 0 };
      });
    },

    currentChannel: function () {
      var title = document.getElementById('chat-title');
      var t = (title && title.textContent || '').trim();
      if (t.charAt(0) === '#') {
        return t.replace(/^#/, '');
      }
      return '';
    },

    currentDm: function () {
      var title = document.getElementById('chat-title');
      var t = (title && title.textContent || '').trim();
      if (t === '' || t === 'LVChat Messenger') return '';
      if (t.charAt(0) === '#') return '';
      return t;
    },

    headerEl: function () {
      return document.querySelector('.chat-head-actions');
    },

    openChannel: function (slug) {
      try {
        if (typeof window.openRoom === 'function') {
          window.openRoom('#' + slug);
          return;
        }
      } catch (e) { /* fall back to navigation */ }
      window.location.hash = '#/room/' + encodeURIComponent(slug);
    },

    bootGate: function () {
      return !!(document.getElementById('chat-title') && document.querySelector('.chat-head-actions'));
    },
  };
})();
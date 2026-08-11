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

/* WebRTC Voice — web app client (module asset, loaded via module.json assets.js).
 *
 * Self-contained and defensive: it never touches core app.js internals beyond
 * appending a couple of header buttons and reading body data attributes. All
 * state is driven by polling GET /api/webrtc/voice/status every poll interval,
 * which works identically over WS/SSE/poll transports. Nothing renders unless
 * the server says voice is enabled (module disabled/absent → 404 → hidden).
 *
 * Features:
 *   - one-on-one DM calls (ring / accept / decline / end)
 *   - per-channel voice (join / leave / mute, active-speaker talker cap)
 *   - video: camera + screen sharing (LiveKit setCameraEnabled /
 *     setScreenShareEnabled), remote/local tracks attached to a video grid
 *   - meetings: #mtg-XXXXXX private keyed rooms (create / invite online users)
 *
 * Requires the vendored livekit-client UMD (window.LivekitClient) — loaded
 * before this file by the module's assets.js ordering.
 */
(function () {
  'use strict';
  if (window.LVCVoice) return;

  var POLL_MS = 2000;

  var state = {
    enabled: false,
    active: 0,
    max: 0,
    full: false,
    talkerCap: 8,
    channels: {},        // slug -> { voice_enabled }
    session: null,       // { room, kind }
    calls: { incoming: [], outgoing: [], active: null },
    room: null,          // livekit Room
    inVoice: false,
    connecting: false,
    pendingJoin: null,
    pendingCall: null,
    mtg: null,           // { slug, name, key, url } for the current meeting
  };

  var els = {};          // { btn, mtgBtn, pane, ring, pill, videos, toast }
  var tiles = {};        // trackSid -> { el, kind, label }

  function $ (id) { return document.getElementById(id) }

  /* ── API ─────────────────────────────────────────────────────────────── */
  function api(path, data) {
    var csrf = document.body ? document.body.dataset.csrf || '' : '';
    var opts = { method: data ? 'POST' : 'GET', credentials: 'include' };
    if (data) {
      var body = new URLSearchParams();
      Object.keys(data).forEach(function (k) { body.set(k, String(data[k])); });
      body.set('csrf', csrf);
      opts.body = body.toString();
      opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    }
    return fetch(path, opts).then(function (res) {
      return res.json().catch(function () { return { ok: false, error: 'Bad response' }; }).then(function (j) {
        j._status = res.status;
        return j;
      });
    }).catch(function (e) {
      return { ok: false, error: String(e && e.message || e), _status: 0 };
    });
  }

  /* ── Status poll ─────────────────────────────────────────────────────── */
  function pollStatus() {
    return api('/api/webrtc/voice/status').then(function (j) {
      if (!j || j.ok !== true) {
        state.enabled = false;
        render();
        return;
      }
      state.enabled = !!j.enabled;
      state.active = j.active || 0;
      state.max = j.max || 0;
      state.full = !!j.full;
      state.talkerCap = j.talker_cap || 8;
      state.channels = {};
      (j.channels || []).forEach(function (c) { state.channels[c.slug] = c.voice_enabled; });
      state.session = j.session || null;
      state.calls = j.calls || { incoming: [], outgoing: [], active: null };
      state.inVoice = !!(state.session && state.room && state.room.state === 'connected');
      render();
      handleCallTransitions();
    });
  }

  /* ── LiveKit ────────────────────────────────────────────────────────── */
  function lk() {
    return (typeof window !== 'undefined') && (window.LivekitClient || window.LiveKitClient || window.LiveKit || null);
  }

  function connectLivekit(url, token, roomName) {
    if (!lk()) {
      toast('Voice client library is not loaded (livekit-client missing).');
      return;
    }
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    clearTiles();
    var Room = lk().Room;
    var Event = lk().RoomEvent || {};
    var room = new Room({ adaptiveStream: false });
    state.room = room;
    state.connecting = true;
    render();

    room.on(Event.ActiveSpeakersChanged, function (speakers) {
      var cap = state.talkerCap || 8;
      var order = (speakers || []).map(function (s) { return s.participant; });
      room.remoteParticipants.forEach(function (p) {
        // Keep screen-sharers subscribed regardless of speaking (their video is
        // the whole point); otherwise cap to the loudest talkers.
        var sharing = Array.from(p.trackPublications.values()).some(function (pub) {
          return pub.source === 'screen_share';
        });
        var idx = order.indexOf(p);
        p.setSubscribed(sharing || idx === -1 || idx < cap);
      });
    });
    room.on(Event.TrackSubscribed, function (track) { attachTrack(track); });
    room.on(Event.TrackUnsubscribed, function (track) { detachTrack(track); });
    room.on(Event.TrackMuted, function (track) {
      if (track.kind === 'video' && tiles[track.sid]) tiles[track.sid].el.classList.add('muted');
    });
    room.on(Event.TrackUnmuted, function (track) {
      if (track.kind === 'video' && tiles[track.sid]) tiles[track.sid].el.classList.remove('muted');
    });
    room.on(Event.Disconnected, function () {
      state.room = null;
      state.inVoice = false;
      state.connecting = false;
      clearTiles();
      render();
    });
    room.on(Event.Connected, function () {
      state.connecting = false;
      state.inVoice = true;
      room.localParticipant.setMicrophoneEnabled(true);
      render();
    });

    room.connect(url, token, {}).then(function () {
      log('connected', roomName);
    }).catch(function (err) {
      state.room = null;
      state.connecting = false;
      render();
      toast('Could not connect to voice: ' + (err && err.message || err));
    });
  }

  function attachTrack(track) {
    if (!track || tiles[track.sid]) return;
    var el = document.createElement('div');
    el.className = 'lvcvoice-tile';
    el.dataset.track = track.sid;
    if (track.kind === 'video') {
      var video = document.createElement('video');
      video.autoplay = true;
      video.playsInline = true;
      track.attach(video);
      el.appendChild(video);
    } else {
      var audio = document.createElement('audio');
      audio.autoplay = true;
      track.attach(audio);
      document.body.appendChild(audio);
      tiles[track.sid] = { el: audio, kind: 'audio' };
      return;
    }
    els.videos.appendChild(el);
    tiles[track.sid] = { el: el, kind: 'video' };
  }

  function detachTrack(track) {
    var t = tiles[track.sid];
    if (!t) return;
    try { track.detach(); } catch (e) {}
    if (t.el && t.el.parentNode) t.el.parentNode.removeChild(t.el);
    delete tiles[track.sid];
  }

  function clearTiles() {
    Object.keys(tiles).forEach(function (sid) {
      try { if (tiles[sid].kind === 'audio') tiles[sid].el.remove(); } catch (e) {}
      delete tiles[sid];
    });
    if (els.videos) els.videos.innerHTML = '';
  }

  /* Attach the local participant's own camera / screen tracks (LiveKit only
   * fires TrackSubscribed for remote tracks). */
  function attachLocalVideos() {
    var room = state.room;
    if (!room) return;
    room.localParticipant.trackPublications.forEach(function (pub) {
      var track = pub.track;
      if (track && track.kind === 'video' && !tiles[track.sid]) {
        attachTrack(track);
        var t = tiles[track.sid];
        if (t) {
          t.el.classList.add('self');
          t.el.setAttribute('data-label', pub.source === 'screen_share' ? 'You (screen)' : 'You');
        }
      }
    });
  }

  function toggleCamera() {
    var room = state.room;
    if (!room) return;
    var on = !room.localParticipant.isCameraEnabled();
    room.localParticipant.setCameraEnabled(on).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    setTimeout(attachLocalVideos, 400);
  }

  function toggleScreenShare() {
    var room = state.room;
    if (!room) return;
    var on = !room.localParticipant.isScreenShareEnabled();
    room.localParticipant.setScreenShareEnabled(on).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    setTimeout(attachLocalVideos, 400);
  }

  /* ── Actions ────────────────────────────────────────────────────────── */
  function currentChannel() {
    return document.body ? document.body.dataset.channel || '' : '';
  }
  function currentDm() {
    return document.body ? document.body.dataset.dm || '' : '';
  }

  function toggleVoice() {
    var slug = currentChannel();
    if (!slug || state.connecting) return;
    if (state.inVoice) { leaveVoice(); return; }
    if (state.full) return;
    state.pendingJoin = { channel: slug };
    render();
    api('/api/webrtc/voice/join', { channel: slug }).then(function (j) {
      state.pendingJoin = null;
      if (j.ok) {
        state.inVoice = true;
        connectLivekit(j.url, j.token, j.room);
      } else {
        render();
        toast(j.error || 'Could not join voice.');
      }
    });
  }

  function leaveVoice() {
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    state.inVoice = false;
    state.pendingJoin = null;
    clearTiles();
    api('/api/webrtc/voice/leave', {}).then(render);
    render();
  }

  function startCall() {
    var dm = currentDm();
    if (!dm) return;
    api('/api/webrtc/call/initiate', { user: dm }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not start the call.'); return; }
      state.pendingCall = j.call_id;
      render();
    });
  }

  function acceptCall(callId) {
    api('/api/webrtc/call/accept', { call_id: callId }).then(function (j) {
      if (j.ok) {
        state.pendingCall = null;
        state.inVoice = true;
        connectLivekit(j.url, j.token, j.room);
      } else {
        toast(j.error || 'Could not accept the call.');
      }
      render();
    });
  }

  function declineCall(callId) {
    api('/api/webrtc/call/decline', { call_id: callId }).then(render);
    render();
  }

  function endCall() {
    var call = state.calls.active;
    if (call) api('/api/webrtc/call/end', { call_id: call.call_id });
    state.pendingCall = null;
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    state.inVoice = false;
    clearTiles();
    render();
  }

  function handleCallTransitions() {
    var active = state.calls.active;
    if (active && state.room && state.room.state === 'connected') return;
    if (active && !state.room && !state.connecting) {
      api('/api/webrtc/call/join', { call_id: active.call_id }).then(function (j) {
        if (j.ok) { state.inVoice = true; connectLivekit(j.url, j.token, j.room); }
      });
    }
  }

  /* ── Meetings (#mtg-XXXXXX) ─────────────────────────────────────────── */
  function isMeetingChannel(slug) {
    return /^#?mtg-\d{6}$/i.test(slug || '');
  }

  function meetingModalOpen() {
    return els.mtgModal && !els.mtgModal.classList.contains('hidden');
  }

  function openMeetingModal() {
    ensureEls();
    if (!els.mtgModal) return;
    els.mtgModal.classList.remove('hidden');
    var slug = currentChannel();
    renderMeeting(slug && isMeetingChannel(slug) ? slug : '');
  }

  function closeMeetingModal() {
    if (els.mtgModal) els.mtgModal.classList.add('hidden');
  }

  function createMeeting() {
    api('/api/webrtc/mtg/create', {}).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not create a meeting.'); return; }
      state.mtg = { slug: j.slug, name: j.name, key: j.key, url: j.url };
      renderMeeting(state.mtg.slug);
    });
  }

  function inviteToMeeting() {
    var slug = state.mtg && state.mtg.slug;
    if (!slug) return;
    var input = els.mtgInvite;
    var names = (input && input.value || '').trim();
    if (!names) return;
    api('/api/webrtc/mtg/invite', { channel: slug, users: names }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not invite.'); return; }
      input.value = '';
      renderInviteResult(j);
    });
  }

  function renderInviteResult(j) {
    var el = els.mtgResult;
    if (!el) return;
    var parts = [];
    if (j.added && j.added.length) parts.push('Added: ' + j.added.join(', '));
    if (j.offline && j.offline.length) parts.push('Offline (not added): ' + j.offline.join(', '));
    if (j.unknown && j.unknown.length) parts.push('Unknown: ' + j.unknown.join(', '));
    el.textContent = parts.length ? parts.join(' · ') : (j.ok ? 'Invited.' : 'Could not invite.');
    el.classList.remove('hidden');
    setTimeout(function () { el.classList.add('hidden'); }, 6000);
  }

  function renderMeeting(slug) {
    if (!els.mtgForm || !els.mtgView) return;
    if (slug && isMeetingChannel(slug)) {
      // Viewing an existing meeting channel.
      els.mtgForm.classList.add('hidden');
      els.mtgView.classList.remove('hidden');
      var name = '#' + slug.replace(/^#?/, '');
      els.mtgViewName.textContent = name;
      if (state.mtg && state.mtg.slug === slug) {
        els.mtgUrl.value = state.mtg.url;
      } else {
        els.mtgUrl.value = '';
        els.mtgUrl.placeholder = 'Invite URL — created by the meeting host';
      }
    } else {
      els.mtgForm.classList.remove('hidden');
      els.mtgView.classList.add('hidden');
    }
  }

  function copyMeetingUrl() {
    var input = els.mtgUrl;
    if (!input || !input.value) return;
    var done = function () { toast('Invite link copied.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(done, function () { fallbackCopy(input, done); });
    } else {
      fallbackCopy(input, done);
    }
  }

  function fallbackCopy(input, done) {
    input.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
  }

  /* ── DOM ────────────────────────────────────────────────────────────── */
  function ensureEls() {
    if (els.btn) return;
    var header = document.querySelector('header .relative.ml-auto.flex.items-center.gap-2');
    if (!header) return;

    els.btn = makeButton('lvcvoice-btn', 'btn-ghost text-xs hidden md:flex', onMainClick);
    header.appendChild(els.btn);

    els.mtgBtn = makeButton('lvcvoice-mtg-btn', 'btn-ghost text-xs hidden md:flex', function () {
      openMeetingModal();
    });
    els.mtgBtn.textContent = 'Meeting';
    header.appendChild(els.mtgBtn);

    var pill = document.createElement('span');
    pill.id = 'lvcvoice-callpill';
    pill.className = 'hidden text-xs';
    pill.innerHTML = '<span class="dot"></span><span class="pill-text"></span><button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger" style="margin-left:6px">End</button>';
    pill.querySelector('button').addEventListener('click', endCall);
    header.insertBefore(pill, els.btn);
    els.pill = pill;

    document.body.appendChild(buildPane());
    document.body.appendChild(buildRing());
    document.body.appendChild(buildMtgModal());
  }

  function makeButton(id, cls, handler) {
    var b = document.createElement('button');
    b.id = id;
    b.type = 'button';
    b.className = cls;
    b.addEventListener('click', handler);
    return b;
  }

  function buildPane() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-pane';
    el.className = 'hidden';
    el.innerHTML =
      '<div class="pane-head"><span class="pane-title">Voice</span>' +
      '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger pane-leave">Leave</button></div>' +
      '<div class="pane-body">' +
      '<div id="lvcvoice-videos" class="lvcvoice-videos"></div>' +
      '<div class="pane-row"><span class="pane-meta pane-status">Connected to the voice room.</span></div>' +
      '<div class="pane-controls">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-mute" data-act="mute">Mute</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-cam" data-act="cam">Camera</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-share" data-act="share">Share</button>' +
      '</div></div>';
    el.querySelector('.pane-leave').addEventListener('click', leaveVoice);
    el.querySelector('.pane-mute').addEventListener('click', toggleMute);
    el.querySelector('.pane-cam').addEventListener('click', toggleCamera);
    el.querySelector('.pane-share').addEventListener('click', toggleScreenShare);
    els.videos = el.querySelector('#lvcvoice-videos');
    return el;
  }

  function buildRing() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-ring';
    el.className = 'hidden';
    el.innerHTML =
      '<div class="ring-title">Incoming voice call</div>' +
      '<div class="ring-sub ring-peer"></div>' +
      '<div class="ring-actions">' +
      '<button type="button" class="ring-accept">Accept</button>' +
      '<button type="button" class="ring-decline">Decline</button>' +
      '</div>';
    var currentRingId = null;
    el.querySelector('.ring-accept').addEventListener('click', function () {
      if (currentRingId != null) acceptCall(currentRingId);
      el.classList.add('hidden');
    });
    el.querySelector('.ring-decline').addEventListener('click', function () {
      if (currentRingId != null) declineCall(currentRingId);
      el.classList.add('hidden');
    });
    el._currentRingId = function (v) { if (arguments.length) currentRingId = v; return currentRingId; };
    return el;
  }

  function buildMtgModal() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-mtg-modal';
    el.className = 'hidden lvcvoice-mtg-overlay';
    el.innerHTML =
      '<div class="lvcvoice-mtg-card">' +
      '<div class="lvcvoice-mtg-head"><span>Meeting rooms</span>' +
      '<button type="button" class="lvcvoice-btn-ghost mtg-close">✕</button></div>' +
      '<div class="lvcvoice-mtg-body">' +
      '<div id="lvcvoice-mtg-form">' +
      '<p class="lvcvoice-mtg-hint">Create a private <code>#mtg-XXXXXX</code> room. Only users you invite (who are online) get in; the invite link carries the room key.</p>' +
      '<button type="button" class="btn-primary mtg-create" style="width:100%">Create meeting</button>' +
      '</div>' +
      '<div id="lvcvoice-mtg-view" class="hidden">' +
      '<div class="lvcvoice-mtg-name" id="lvcvoice-mtg-name"></div>' +
      '<div class="lvcvoice-mtg-urlrow"><input id="lvcvoice-mtg-url" class="input font-mono text-xs" readonly>' +
      '<button type="button" class="lvcvoice-btn-ghost mtg-copy">Copy</button></div>' +
      '<div class="lvcvoice-mtg-invite">' +
      '<label class="label" for="lvcvoice-mtg-invite-input">Invite by username (online users join immediately)</label>' +
      '<div class="lvcvoice-mtg-urlrow"><input id="lvcvoice-mtg-invite-input" class="input text-xs" placeholder="bob, alice">' +
      '<button type="button" class="btn-primary mtg-invite">Invite</button></div>' +
      '</div>' +
      '<div id="lvcvoice-mtg-result" class="lvcvoice-mtg-result hidden"></div>' +
      '</div>' +
      '</div></div>';
    el.querySelector('.mtg-close').addEventListener('click', closeMeetingModal);
    el.addEventListener('click', function (e) { if (e.target === el) closeMeetingModal(); });
    el.querySelector('.mtg-create').addEventListener('click', createMeeting);
    el.querySelector('.mtg-copy').addEventListener('click', copyMeetingUrl);
    el.querySelector('.mtg-invite').addEventListener('click', inviteToMeeting);
    el.querySelector('#lvcvoice-mtg-invite-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') inviteToMeeting();
    });
    els.mtgForm = el.querySelector('#lvcvoice-mtg-form');
    els.mtgView = el.querySelector('#lvcvoice-mtg-view');
    els.mtgViewName = el.querySelector('#lvcvoice-mtg-name');
    els.mtgUrl = el.querySelector('#lvcvoice-mtg-url');
    els.mtgInvite = el.querySelector('#lvcvoice-mtg-invite-input');
    els.mtgResult = el.querySelector('#lvcvoice-mtg-result');
    return el;
  }

  function toggleMute() {
    var room = state.room;
    if (!room) return;
    room.localParticipant.setMicrophoneEnabled(!room.localParticipant.isMicrophoneEnabled());
  }

  function onMainClick() {
    var dm = currentDm();
    if (dm) { startCall(); return; }
    toggleVoice();
  }

  function toast(msg) {
    if (!els.toast) {
      els.toast = document.createElement('div');
      els.toast.id = 'lvcvoice-toast';
      document.body.appendChild(els.toast);
    }
    els.toast.textContent = msg;
    els.toast.classList.add('show');
    clearTimeout(els.toast._t);
    els.toast._t = setTimeout(function () { els.toast.classList.remove('show'); }, 4000);
  }

  function log() { /* console.debug('[voice]', Array.prototype.slice.call(arguments)); */ }

  /* ── Rendering ──────────────────────────────────────────────────────── */
  function render() {
    ensureEls();
    if (!els.btn) return;
    var dm = currentDm();
    var slug = currentChannel();

    // Header: voice / call button.
    if (dm) {
      els.btn.classList.remove('hidden');
      els.btn.textContent = callLabel();
      els.btn.classList.remove('in-voice');
    } else if (slug && state.channels[slug] && state.enabled) {
      els.btn.classList.remove('hidden');
      els.btn.textContent = voiceLabel();
      els.btn.classList.toggle('in-voice', state.inVoice);
      els.btn.classList.toggle('disabled', state.full && !state.inVoice);
    } else {
      els.btn.classList.add('hidden');
    }

    // Header: Meeting button (module enabled; meeting channels get it too).
    if (els.mtgBtn) {
      if (state.enabled && !dm) {
        els.mtgBtn.classList.remove('hidden');
      } else {
        els.mtgBtn.classList.add('hidden');
      }
    }

    // Call pill.
    var call = state.calls.active;
    var outgoing = state.calls.outgoing[0] || null;
    if (els.pill) {
      if (call) {
        els.pill.classList.remove('hidden');
        els.pill.classList.add('active');
        els.pill.querySelector('.pill-text').textContent = 'In call with ' + call.peer;
      } else if (outgoing) {
        els.pill.classList.remove('hidden');
        els.pill.classList.remove('active');
        els.pill.classList.add('ringing');
        els.pill.querySelector('.pill-text').textContent = 'Ringing ' + outgoing.peer + '…';
      } else {
        els.pill.classList.add('hidden');
      }
    }

    // Voice pane.
    if (els.pane) {
      var inRoomVoice = state.inVoice && slug && !dm;
      els.pane.classList.toggle('hidden', !inRoomVoice);
      if (inRoomVoice) {
        els.pane.querySelector('.pane-title').textContent = 'Voice — #' + slug;
        var room = state.room;
        var muted = room && !room.localParticipant.isMicrophoneEnabled();
        var camOn = room && room.localParticipant.isCameraEnabled();
        var shareOn = room && room.localParticipant.isScreenShareEnabled();
        els.pane.querySelector('.pane-mute').textContent = muted ? 'Unmute' : 'Mute';
        els.pane.querySelector('.pane-cam').textContent = camOn ? 'Camera off' : 'Camera';
        els.pane.querySelector('.pane-share').textContent = shareOn ? 'Stop share' : 'Share';
        els.pane.querySelector('.pane-status').textContent = state.calls.active
          ? 'In a 1:1 call with ' + state.calls.active.peer
          : 'Connected to the channel voice room.';
      }
    }

    // Incoming ring overlay.
    var incoming = state.calls.incoming[0] || null;
    if (els.ring) {
      if (incoming && state.enabled) {
        els.ring.classList.remove('hidden');
        els.ring.querySelector('.ring-sub').textContent = incoming.peer + ' is calling…';
        els.ring._currentRingId(incoming.call_id);
      } else {
        els.ring.classList.add('hidden');
        els.ring._currentRingId(null);
      }
    }

    // Meeting modal state (refresh if viewing a meeting channel).
    if (meetingModalOpen()) {
      var cur = isMeetingChannel(slug) ? slug : (state.mtg ? state.mtg.slug : '');
      if (isMeetingChannel(slug) && !(state.mtg && state.mtg.slug === slug)) {
        state.mtg = { slug: slug, name: '#' + slug.replace(/^#?/, ''), key: '', url: '' };
      }
      renderMeeting(cur);
    }
  }

  function voiceLabel() {
    if (state.connecting) return 'Connecting…';
    if (state.inVoice) return 'In voice — Leave';
    if (state.full) return 'Voice full (' + state.active + '/' + state.max + ')';
    return 'Voice';
  }

  function callLabel() {
    if (state.calls.active) return 'In call';
    if (state.calls.outgoing[0]) return 'Ringing…';
    return 'Call';
  }

  /* ── Boot ───────────────────────────────────────────────────────────── */
  function boot() {
    if (!document.body || !document.body.classList.contains('chat-app')) return;
    ensureEls();
    pollStatus();
    setInterval(pollStatus, POLL_MS);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

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
 *   - one-on-one DM calls (ring / accept / decline / end, 20 s ring timeout)
 *   - per-channel voice (join / leave / mute, active-speaker talker cap)
 *   - video: camera + screen sharing (LiveKit setCameraEnabled /
 *     setScreenShareEnabled), remote/local tracks attached to a video grid
 *   - audio & video device settings + camera/mic test (getUserMedia, no server)
 *   - background blur / custom image during video (lazy MediaPipe segmentation)
 *   - events: public/private event rooms with WebRTC or link-based streams (create / email invites)
 *
 * Requires the vendored livekit-client UMD (window.LivekitClient) — loaded
 * before this file by the module's assets.js ordering.
 */
(function () {
  'use strict';
  if (window.LVCVoice) return;

  var POLL_MS = 2000;
  var PREFS_KEY = 'lvchat.voice.prefs';
  // Self-hosted MediaPipe selfie-segmentation, lazy-loaded (see vendor/).
  // Hosts whose assets live elsewhere (messenger renderers) override via HOST.
  var BG_BASE = '/modules/webrtc/assets/vendor/selfie-segmentation/';

  /* ── Host adapter ──────────────────────────────────────────────────────
   * The same voice client runs on every surface (web app, desktop, Electron
   * messenger, messenger-web PWA). Each shell may pre-define window.LVCVoiceHost
   * to override the default transport + DOM bindings:
   *   api(path, data)      → Promise<parsed JSON body like {ok, error, ...}>
   *                           (default: fetch + cookie/CSRF — the web app)
   *   currentChannel()     → active channel slug (default: body data attrs)
   *   currentDm()          → active DM nick (default: body data attrs)
   *   headerEl()           → element the dropdown + pill mount into
   *   openChannel(slug)    → navigate to a channel (default: openChannel()/URL)
   *   bootGate()           → true when this page should run voice at all
   * Without a host, the defaults assume the web app chat page. */
  var HOST = (typeof window !== 'undefined' && window.LVCVoiceHost) || {};
  if (HOST && typeof HOST.bgBase === 'string' && HOST.bgBase !== '') {
    BG_BASE = HOST.bgBase;
  }

  function hostApi(path, data) {
    if (typeof HOST.api === 'function') return HOST.api(path, data);
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

  function hostCurrentChannel() {
    if (typeof HOST.currentChannel === 'function') return HOST.currentChannel() || '';
    return document.body ? document.body.dataset.channel || '' : '';
  }

  function hostCurrentDm() {
    if (typeof HOST.currentDm === 'function') return HOST.currentDm() || '';
    return document.body ? document.body.dataset.dm || '' : '';
  }

  function hostHeaderEl() {
    if (typeof HOST.headerEl === 'function') {
      var el = HOST.headerEl();
      if (el) return el;
    }
    return document.querySelector('header .relative.ml-auto.flex.items-center.gap-2');
  }

  function hostOpenChannel(slug) {
    if (typeof HOST.openChannel === 'function') { HOST.openChannel(slug); return; }
    try {
      if (typeof openChannel === 'function') openChannel(slug);
      else window.location.href = '/app?channel=' + encodeURIComponent(slug);
    } catch (e) {}
  }

  function hostBootGate() {
    if (typeof HOST.bootGate === 'function') return !!HOST.bootGate();
    return !!(document.body && document.body.classList.contains('chat-app'));
  }

  var ICON_CALL = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>';
  var ICON_HANGUP = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transform:rotate(135deg)"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>';

  var VIDEO_QUALITY = {
    '360p':  { width: 640,  height: 360,  frameRate: 20 },
    '480p':  { width: 640,  height: 480,  frameRate: 20 },
    '720p':  { width: 1280, height: 720,  frameRate: 30 },
    '1080p': { width: 1920, height: 1080, frameRate: 30 }
  };
  var VIDEO_QUALITY_ORDER = ['360p', '480p', '720p', '1080p'];

  var state = {
    enabled: false,
    active: 0,
    max: 0,
    full: false,
    talkerCap: 8,
    channels: {},        // slug -> { voice_enabled }
    session: null,       // { room, kind, waiting, can_moderate, locked, roster, mint }
    calls: { incoming: [], outgoing: [], active: null, recent: [] },
    room: null,          // livekit Room
    inVoice: false,
    connecting: false,
    waitingRoom: false,  // in the lobby until the host admits us
    pendingJoin: null,
    pendingCall: null,
    pendingCallAt: 0,    // ms when the outgoing call was initiated (race guard)
    pendingCallVideo: false, // outgoing call should start with the camera on
    evt: null,            // { id, slug, title, invite_code, invite_url, status } for the current event
    ringSeconds: 20,     // server ring timeout (call_ring_seconds)
    ringStarted: {},     // call_id -> ms when its ring was first seen
    ringShown: null,     // { call_id, peer } of the current incoming ring
    ringDismissed: null, // call_id the user accepted/declined (not "missed")
    prefs: null,         // { mic, cam, speaker, bg, blur, image, ns, agc, echo, screenAudio }
    devices: { audioinput: [], videoinput: [], audiooutput: [] },
    camTest: null,       // active camera-test stream
    micTest: null,       // active mic-test analyser state
    echoTest: null,      // { room, analyser, stream, raf } — active echo-test state
    settingsOpen: false,
    bgProc: null,        // active LiveKit video processor for bg effects
    bgProcKey: '',       // JSON key of the effect the processor was built for
    deafened: false,     // mute everyone else's audio (Discord-style)
    layout: 'auto',      // 'auto' | 'speaker' | 'gallery'
    minimized: false,    // float the pane as a small pill
    quality: {},         // identity -> 'good' | 'fair' | 'poor'
    hands: {},           // identity -> raised hand?
    reactions: [],       // transient floating emoji: { emoji, name, at }
    ringAudio: null,     // synthesized ring tone element
    outgoingRing: false, // caller-side ring tone active for an outgoing call
    recording: { enabled: false, active: null },  // egress recording state
    videoQuality: '720p',     // current user video quality preference
    videoQualityDefault: '720p',  // server default
    videoQualityAvailable: ['360p', '480p', '720p', '1080p'],  // admin-allowed options
  };

  var els = {};          // { btn, settingsBtn, mtgBtn, pane, ring, pill, videos, toast, st* }
  var tiles = {};        // trackSid -> { el, kind, label }

  function $ (id) { return document.getElementById(id) }

  /* ── API ─────────────────────────────────────────────────────────────── */
  function api(path, data) {
    return hostApi(path, data);
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
      state.recording = j.recording || { enabled: false, active: null };
      state.calls = j.calls || { incoming: [], outgoing: [], active: null, recent: [] };
      state.recent = state.calls.recent || [];
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20;
      state.videoQualityDefault = j.video_quality_default || '720p';
      state.videoQualityAvailable = j.video_quality_available || ['360p', '480p', '720p', '1080p'];
      /* Apply server default if user has no saved preference. */
      if (!state.prefs.videoQuality) {
        state.videoQuality = state.videoQualityDefault;
      }
      state.inVoice = !!(state.session && state.room && state.room.state === 'connected');
      render();
      handleCallTransitions();
      handleSessionTransitions();
    });
  }

  /* ── Session transitions: waiting-room + admission + host changes ────── */
  var lastWaiting = false;
  function handleSessionTransitions() {
    var sess = state.session;
    var waiting = !!(sess && sess.waiting);
    if (waiting !== lastWaiting) {
      lastWaiting = waiting;
      if (waiting) {
        state.waitingRoom = true;
        stopRing();
        toast('You are in the waiting room — the host will let you in shortly.');
      }
    }
    // Admission: the host admitted us → connect with the freshly minted token.
    if (sess && sess.mint && !state.room && !state.connecting) {
      var mint = sess.mint;
      state.waitingRoom = false;
      lastWaiting = false;
      state.inVoice = true;
      connectLivekit(mint.url, mint.token, mint.room);
      return;
    }
    // Waiting-room session vanished → denied or the host closed the door.
    if (state.waitingRoom && !sess) {
      state.waitingRoom = false;
      lastWaiting = false;
      toast('The host declined your request to join.');
      render();
    }
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
      // Speaking rings on tiles.
      Object.keys(tiles).forEach(function (sid) {
        tiles[sid].el.classList.remove('speaking');
      });
      (speakers || []).forEach(function (sp) {
        if (!sp || !sp.participant) return;
        var pid = sp.participant.identity;
        sp.participant.trackPublications.forEach(function (pub) {
          if (pub.track && pub.track.kind === 'video' && tiles[pub.track.sid]) {
            tiles[pub.track.sid].el.classList.add('speaking');
          }
        });
      });
    });
    room.on(Event.TrackSubscribed, function (track) { attachTrack(track, track.participant); });
    room.on(Event.TrackUnsubscribed, function (track) { detachTrack(track); });
    // Participant joined → watch quality + attributes (raise hand).
    if (Event.ParticipantConnected) {
      room.on(Event.ParticipantConnected, function (p) { watchParticipant(p); });
    }
    room.remoteParticipants.forEach(function (p) { watchParticipant(p); });
    // Floating emoji reactions via the data channel (reliable).
    if (Event.DataReceived) {
      room.on(Event.DataReceived, function (payload, participant) {
        try {
          var msg = JSON.parse(new TextDecoder().decode(payload));
          if (msg && msg.type === 'reaction' && msg.emoji) {
            addReaction(msg.emoji, participant ? participant.name || participant.identity : 'Someone');
          }
        } catch (e) {}
      });
    }
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
      state.waitingRoom = false;
      lastWaiting = false;
      state.quality = {};
      state.hands = {};
      clearTiles();
      stopRing();
      render();
    });
    room.on(Event.Connected, function () {
      state.connecting = false;
      state.inVoice = true;
      applyDevicePrefs();
      // A video call (Issue #17) starts with the camera on: the caller asked
      // for it at initiate-time, so flip the camera on the moment the room is
      // live, then clear the intent (later joins stay audio-only).
      if (state.pendingCallVideo) {
        state.pendingCallVideo = false;
        setCamera(true);
      }
      render();
    });

    room.connect(url, token, {}).then(function () {
      log('connected', roomName);
      // Toggle deafen state back on after reconnect.
      if (state.deafened) setDeafen(true);
    }).catch(function (err) {
      state.room = null;
      state.connecting = false;
      render();
      toast('Could not connect to voice: ' + (err && err.message || err));
    });
  }

  /* Track a participant's connection quality + raise-hand attribute. */
  function watchParticipant(p) {
    if (!p) return;
    var PE = lk().ParticipantEvent || {};
    if (PE.ConnectionQualityChanged) {
      p.on(PE.ConnectionQualityChanged, function (q) {
        state.quality[p.identity] = String(q || '').toLowerCase();
        renderRoster();
      });
    }
    if (PE.AttributesChanged) {
      p.on(PE.AttributesChanged, function (attrs) {
        state.hands[p.identity] = attrs && attrs.hand === '1';
        renderRoster();
      });
    }
    if (PE.TrackPublished) {
      p.on(PE.TrackPublished, function () { setTimeout(attachLocalVideos, 150); });
    }
  }

  function addReaction(emoji, name) {
    state.reactions.push({ emoji: emoji, name: name, at: Date.now() });
    if (state.reactions.length > 12) state.reactions.shift();
    renderReactions();
    setTimeout(renderReactions, 4000);
  }

  function sendReaction(emoji) {
    var room = state.room;
    if (!room || !room.localParticipant || !room.localParticipant.publishData) return;
    try {
      var payload = new TextEncoder().encode(JSON.stringify({ type: 'reaction', emoji: emoji }));
      room.localParticipant.publishData(payload, { reliable: true });
      addReaction(emoji, 'You');
      toast('Reaction sent.');
    } catch (e) {}
  }

  function toggleHand() {
    var room = state.room;
    if (!room || !room.localParticipant) return;
    var on = !(state.hands[room.localParticipant.identity] === true);
    state.hands[room.localParticipant.identity] = on;
    try { room.localParticipant.setAttributes({ hand: on ? '1' : '0' }); } catch (e) {}
    renderRoster();
  }

  function attachTrack(track, participant) {
    if (!track || tiles[track.sid]) return;
    var el = document.createElement('div');
    el.className = 'lvcvoice-tile';
    el.dataset.track = track.sid;
    var name = participant ? (participant.name || participant.identity || '') : '';
    if (track.kind === 'video') {
      var video = document.createElement('video');
      video.autoplay = true;
      video.playsInline = true;
      track.attach(video);
      el.appendChild(video);
      if (name) el.setAttribute('data-label', track.source === 'screen_share' ? name + ' (screen)' : name);
    } else {
      var audio = document.createElement('audio');
      audio.autoplay = true;
      audio.volume = state.deafened ? 0 : 1;
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

  function setCamera(on) {
    var room = state.room;
    if (!room) return Promise.resolve();
    if (!on) {
      return room.localParticipant.setCameraEnabled(false, { stop: true }).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    }
    var opts = {};
    if (state.prefs && state.prefs.cam) opts.deviceId = state.prefs.cam;
    var res = VIDEO_QUALITY[state.videoQuality] || VIDEO_QUALITY['720p'];
    opts.resolution = res;
    var proc = currentBgProcessor();
    if (proc) opts.videoProcessor = proc;
    return room.localParticipant.setCameraEnabled(true, opts).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
  }

  function toggleCamera() {
    var room = state.room;
    if (!room) return;
    setCamera(!room.localParticipant.isCameraEnabled());
  }

  /* Restart the camera (stop → start) so a changed device or background effect
   * actually takes effect mid-call. Only restarts when the camera is on. */
  function restartCamera() {
    var room = state.room;
    if (!room) return Promise.resolve();
    var wasOn = room.localParticipant.isCameraEnabled();
    return room.localParticipant.setCameraEnabled(false, { stop: true }).then(function () {
      if (!wasOn) return;
      var opts = {};
      if (state.prefs && state.prefs.cam) opts.deviceId = state.prefs.cam;
      var res = VIDEO_QUALITY[state.videoQuality] || VIDEO_QUALITY['720p'];
      opts.resolution = res;
      var proc = currentBgProcessor();
      if (proc) opts.videoProcessor = proc;
      return room.localParticipant.setCameraEnabled(true, opts);
    }).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
  }

  function toggleScreenShare() {
    var room = state.room;
    if (!room) return;
    var on = !room.localParticipant.isScreenShareEnabled();
    var opts = {};
    if (on && state.prefs && state.prefs.screenAudio) {
      opts.audio = true; // capture the system audio tab (Google Meet-style)
    }
    room.localParticipant.setScreenShareEnabled(on, opts).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    setTimeout(attachLocalVideos, 400);
  }

  /* Deafen: mute all remote audio locally (Discord-style). */
  function setDeafen(on) {
    state.deafened = !!on;
    Object.keys(tiles).forEach(function (sid) {
      if (tiles[sid].kind === 'audio') tiles[sid].el.volume = state.deafened ? 0 : 1;
    });
    render();
  }
  function toggleDeafen() { setDeafen(!state.deafened); }

  /* Layout: 'auto' (active speaker spotlight) vs 'gallery' (grid). */
  function setLayout(mode) {
    state.layout = mode;
    if (els.pane) els.pane.classList.toggle('gallery', mode === 'gallery');
    if (els.pane) els.pane.classList.toggle('speaker', mode === 'speaker');
    render();
  }

  /* ── Device settings, camera/mic test & background effects ──────────── */

  function defaultPrefs() {
    return {
      mic: '', cam: '', speaker: '', bg: 'none', blur: 8, image: '',
      echoc: true, ns: true, agc: true, screenAudio: false,
      videoQuality: ''
    };
  }

  function loadPrefs() {
    try {
      var p = JSON.parse(localStorage.getItem(PREFS_KEY) || 'null');
      state.prefs = Object.assign(defaultPrefs(), p || {});
    } catch (e) {
      state.prefs = defaultPrefs();
    }
  }

  function savePrefs() {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(state.prefs)); } catch (e) {}
  }

  /* Apply saved devices to the connected LiveKit room: enable the mic with the
   * chosen input, route audio output to the chosen speaker, and enforce the
   * browser-side audio processing prefs (echo cancellation / noise suppression
   * / auto gain — Discord-style per-preference toggles). */
  function applyDevicePrefs() {
    var room = state.room;
    if (!room) return;
    var micOpts = {};
    if (state.prefs && state.prefs.mic) micOpts.deviceId = state.prefs.mic;
    if (state.prefs) {
      micOpts.echoCancellation = state.prefs.echoc !== false;
      micOpts.noiseSuppression = state.prefs.ns !== false;
      micOpts.autoGainControl = state.prefs.agc !== false;
    }
    room.localParticipant.setMicrophoneEnabled(true, micOpts).catch(function (e) { toast(String(e && e.message || e)); });
    if (state.prefs && state.prefs.speaker && typeof room.setSinkId === 'function') {
      room.setSinkId(state.prefs.speaker).catch(function () { /* unsupported device */ });
    }
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* Enumerate devices (asking for mic+camera permission first so the OS labels
   * are exposed), then repopulate the settings dropdowns. */
  function refreshDevices() {
    var mm = navigator.mediaDevices;
    if (!mm || !mm.enumerateDevices) return Promise.resolve();
    var perm = Promise.resolve();
    if (mm.getUserMedia) {
      perm = mm.getUserMedia({ audio: true, video: true })
        .then(function (s) { s.getTracks().forEach(function (t) { t.stop(); }); })
        .catch(function () { /* permission denied → labels will be generic */ });
    }
    return perm.then(function () {
      return mm.enumerateDevices().then(function (list) {
        var devs = { audioinput: [], videoinput: [], audiooutput: [] };
        (list || []).forEach(function (d) { if (devs[d.kind]) devs[d.kind].push(d); });
        state.devices = devs;
      }).catch(function () {});
    });
  }

  function deviceOptions(list, placeholder) {
    var out = '<option value="">' + escapeHtml(placeholder) + '</option>';
    (list || []).forEach(function (d) {
      var label = (d.label || d.kind || 'Device').trim();
      var id = d.deviceId || '';
      out += '<option value="' + escapeHtml(id) + '">' + escapeHtml(label + (id ? ' (' + id.slice(0, 8) + ')' : '')) + '</option>';
    });
    return out;
  }

  function populateSettings() {
    if (!els.stMic) return;
    els.stMic.innerHTML = deviceOptions(state.devices.audioinput, 'Default microphone');
    els.stCam.innerHTML = deviceOptions(state.devices.videoinput, 'Default camera');
    els.stSpeaker.innerHTML = deviceOptions(state.devices.audiooutput, 'Default speaker');
    if (state.prefs.mic) els.stMic.value = state.prefs.mic;
    if (state.prefs.cam) els.stCam.value = state.prefs.cam;
    if (state.prefs.speaker) els.stSpeaker.value = state.prefs.speaker;
  }

  function renderSettings() {
    if (!els.settings) return;
    var bg = state.prefs.bg || 'none';
    els.settings.querySelectorAll('.st-bg').forEach(function (b) {
      b.classList.toggle('on', b.dataset.bg === bg);
    });
    if (els.stBlurRow) els.stBlurRow.classList.toggle('hidden', bg !== 'blur');
    if (els.stImageRow) els.stImageRow.classList.toggle('hidden', bg !== 'image');
    if (els.stBlur) els.stBlur.value = state.prefs.blur || 8;
    if (els.stImgPrev) {
      if (state.prefs.image) {
        els.stImgPrev.src = state.prefs.image;
        els.stImgPrev.classList.remove('hidden');
      } else {
        els.stImgPrev.src = '';
        els.stImgPrev.classList.add('hidden');
      }
    }
    renderVqButtons();
  }

  function renderVqButtons() {
    if (!els.stVqRow) return;
    var cur = state.videoQuality || '720p';
    var avail = state.videoQualityAvailable || ['360p', '480p', '720p', '1080p'];
    els.stVqRow.innerHTML = '';
    avail.forEach(function (q) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lvcvoice-btn-ghost st-vq' + (q === cur ? ' on' : '');
      btn.dataset.vq = q;
      btn.textContent = q;
      btn.addEventListener('click', function () {
        state.videoQuality = q;
        state.prefs.videoQuality = q;
        savePrefs();
        renderVqButtons();
      });
      els.stVqRow.appendChild(btn);
    });
  }

  function openSettings() {
    ensureEls();
    if (!els.settings) return;
    els.settings.classList.remove('hidden');
    state.settingsOpen = true;
    refreshDevices().then(function () {
      populateSettings();
      renderSettings();
    });
  }

  function closeSettings() {
    if (els.settings) els.settings.classList.add('hidden');
    state.settingsOpen = false;
    stopCamTest();
    stopMicTest();
  }

  function saveSettings() {
    var mic = els.stMic.value;
    var cam = els.stCam.value;
    var speaker = els.stSpeaker.value;
    var prevQuality = state.videoQuality;
    state.prefs.mic = mic;
    state.prefs.cam = cam;
    state.prefs.speaker = speaker;
    savePrefs();
    var room = state.room;
    if (room) {
      if (mic) {
        try { room.localParticipant.setMicrophoneEnabled(true, { deviceId: mic }); } catch (e) {}
      }
      if (room.localParticipant.isCameraEnabled()) restartCamera();
    }
    closeSettings();
    toast('Voice settings saved.');
  }

  /* ── Echo test modal ───────────────────────────────────────────────── */
  function openEchoTest() {
    ensureEls();
    if (!els.echoTestModal) buildEchoTestModal();
    if (els.echoTestModal) els.echoTestModal.classList.remove('hidden');
  }

  function closeEchoTest() {
    stopEchoTest();
    if (els.echoTestModal) els.echoTestModal.classList.add('hidden');
  }

  function buildEchoTestModal() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-echotest-overlay';
    el.className = 'hidden lvcvoice-mtg-overlay';
    el.innerHTML =
      '<div class="lvcvoice-settings-card">' +
      '<div class="lvcvoice-mtg-head"><span>Echo test</span>' +
      '<button type="button" class="lvcvoice-btn-ghost et-close">✕</button></div>' +
      '<div class="lvcvoice-settings-body">' +
      '<p class="st-hint" style="margin-bottom:8px">Speak into your microphone and you should hear yourself. ' +
      'This verifies your mic, speakers, and the WebRTC server are working.</p>' +
      '<div class="st-section">' +
      '<div class="st-label">Input level ' +
      '<span class="st-meter-wrap"><meter id="lvcvoice-et-meter" min="0" max="100" low="10" high="70"></meter>' +
      '<span class="st-meter-val" id="lvcvoice-et-meterval"></span></span></div>' +
      '<div id="lvcvoice-et-status" class="st-hint" style="min-height:1.2em"></div>' +
      '<button type="button" class="lvcvoice-btn-ghost" id="lvcvoice-et-btn" style="width:100%">Start echo test</button>' +
      '</div>' +
      '</div></div>';

    el.querySelector('.et-close').addEventListener('click', closeEchoTest);
    el.addEventListener('click', function (e) { if (e.target === el) closeEchoTest(); });

    document.body.appendChild(el);
    els.echoTestModal = el;
    els.echoTestBtn = el.querySelector('#lvcvoice-et-btn');
    els.echoTestMeter = el.querySelector('#lvcvoice-et-meter');
    els.echoTestMeterVal = el.querySelector('#lvcvoice-et-meterval');
    els.echoTestStatus = el.querySelector('#lvcvoice-et-status');

    els.echoTestBtn.addEventListener('click', function () {
      if (state.echoTest) stopEchoTest(); else startEchoTest();
    });
  }

  /* Camera test — plain getUserMedia preview, no LiveKit involved. */
  function startCamTest() {
    if (state.camTest) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast('Camera access is not supported here.');
      return;
    }
    var res = VIDEO_QUALITY[state.videoQuality] || VIDEO_QUALITY['720p'];
    var constraints = { video: { width: { ideal: res.width }, height: { ideal: res.height }, frameRate: { ideal: res.frameRate } }, audio: false };
    if (state.prefs && state.prefs.cam) constraints.video.deviceId = { exact: state.prefs.cam };
    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      state.camTest = stream;
      if (els.stCamTest) els.stCamTest.classList.remove('hidden');
      if (els.stCamVideo) {
        els.stCamVideo.srcObject = stream;
        els.stCamVideo.play().catch(function () {});
      }
    }).catch(function (e) {
      toast('Could not start camera: ' + (e && e.message || e));
    });
  }

  function stopCamTest() {
    if (state.camTest) {
      try { state.camTest.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    }
    state.camTest = null;
    if (els.stCamVideo) els.stCamVideo.srcObject = null;
    if (els.stCamTest) els.stCamTest.classList.add('hidden');
  }

  /* Mic test — live input level meter via WebAudio. */
  function startMicTest() {
    if (state.micTest) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast('Microphone access is not supported here.');
      return;
    }
    var constraints = { audio: true, video: false };
    if (state.prefs && state.prefs.mic) constraints.audio = { deviceId: { exact: state.prefs.mic } };
    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) {
        stream.getTracks().forEach(function (t) { t.stop(); });
        toast('Audio analysis is not supported here.');
        return;
      }
      var actx = new AC();
      var analyser = actx.createAnalyser();
      analyser.fftSize = 512;
      actx.createMediaStreamSource(stream).connect(analyser);
      var buf = new Uint8Array(analyser.frequencyBinCount);
      state.micTest = { actx: actx, stream: stream };
      if (els.stMeter) els.stMeter.value = 0;
      var loop = function () {
        if (!state.micTest) return;
        analyser.getByteTimeDomainData(buf);
        var sum = 0;
        for (var i = 0; i < buf.length; i++) {
          var v = (buf[i] - 128) / 128;
          sum += v * v;
        }
        var pct = Math.min(100, Math.round(Math.sqrt(sum / buf.length) * 220));
        if (els.stMeter) els.stMeter.value = pct;
        if (els.stMeterVal) els.stMeterVal.textContent = pct + '%';
        state.micTest.raf = requestAnimationFrame(loop);
      };
      loop();
      toast('Mic test running — speak to see the level.');
    }).catch(function (e) {
      toast('Could not start mic test: ' + (e && e.message || e));
    });
  }

  function stopMicTest() {
    if (!state.micTest) return;
    cancelAnimationFrame(state.micTest.raf);
    try { state.micTest.actx.close(); } catch (e) {}
    try { state.micTest.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    state.micTest = null;
    if (els.stMeter) els.stMeter.value = 0;
    if (els.stMeterVal) els.stMeterVal.textContent = '';
  }

  /* ── Echo test — join a per-user LiveKit room and hear yourself back ──── */
  function startEchoTest() {
    if (state.echoTest) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast('Microphone access is not supported here.');
      return;
    }
    var constraints = { audio: true, video: false };
    if (state.prefs && state.prefs.mic) constraints.audio = { deviceId: { exact: state.prefs.mic } };

    toast('Starting echo test…');
    api('/api/webrtc/voice/echo-test').then(function (j) {
      if (!j || !j.ok) {
        toast(j && j.error ? j.error : 'Could not start echo test.');
        return;
      }
      navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
        if (!lk()) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          toast('Voice client library is not loaded.');
          return;
        }
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          toast('Audio analysis is not supported here.');
          return;
        }
        var actx = new AC();
        var analyser = actx.createAnalyser();
        analyser.fftSize = 512;
        var source = actx.createMediaStreamSource(stream);
        source.connect(analyser);

        /* Connect to LiveKit so the audio is routed through the server.
         * We create a minimal Room, publish the mic track, and subscribe
         * back — the remote audio is the "echo" the user hears. */
        var Room = lk().Room;
        var room = new Room({ adaptiveStream: false });
        var remoteAudio = null;
        var audioEl = document.createElement('audio');
        audioEl.autoplay = true;
        if (state.prefs && state.prefs.speaker) audioEl.setSinkId(state.prefs.speaker).catch(function () {});

        var Event = lk().RoomEvent || {};
        room.on(Event.TrackSubscribed, function (track, pub, participant) {
          if (track.kind === 'audio') {
            remoteAudio = track;
            track.attach(audioEl);
          }
        });
        room.on(Event.TrackUnsubscribed, function (track) {
          if (track.kind === 'audio' && remoteAudio === track) {
            track.detach(audioEl);
            remoteAudio = null;
          }
        });
        room.on(Event.Connected, function () {
          /* Publish the mic track so LiveKit routes it back to us. */
          var micPub = stream.getAudioTracks()[0];
          if (micPub) {
            room.localParticipant.publishTrack(micPub, { name: 'echo-mic' }).catch(function () {});
          }
        });
        room.on(Event.Disconnected, function () {
          stopEchoTest();
        });

        room.connect(j.url, j.token, {}).then(function () {
          state.echoTest = { room: room, stream: stream, actx: actx, analyser: analyser, audioEl: audioEl };
          if (els.echoTestBtn) els.echoTestBtn.textContent = 'Stop echo test';
          if (els.echoTestMeter) els.echoTestMeter.value = 0;
          if (els.echoTestStatus) els.echoTestStatus.textContent = 'Listening — speak into your microphone.';
          toast('Echo test connected — speak to hear yourself.');
          echoTestMeterLoop();
        }).catch(function (err) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          try { actx.close(); } catch (e) {}
          toast('Could not connect to echo test: ' + (err && err.message || err));
        });
      }).catch(function (e) {
        toast('Could not start mic: ' + (e && e.message || e));
      });
    });
  }

  function stopEchoTest() {
    if (!state.echoTest) return;
    cancelAnimationFrame(state.echoTest.raf);
    if (state.echoTest.room) { try { state.echoTest.room.disconnect(); } catch (e) {} }
    if (state.echoTest.audioEl) { try { state.echoTest.audioEl.srcObject = null; } catch (e) {} }
    try { state.echoTest.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    try { state.echoTest.actx.close(); } catch (e) {}
    state.echoTest = null;
    if (els.echoTestBtn) els.echoTestBtn.textContent = 'Start echo test';
    if (els.echoTestMeter) els.echoTestMeter.value = 0;
    if (els.echoTestStatus) els.echoTestStatus.textContent = '';
  }

  function echoTestMeterLoop() {
    if (!state.echoTest) return;
    var et = state.echoTest;
    var buf = new Uint8Array(et.analyser.frequencyBinCount);
    et.analyser.getByteTimeDomainData(buf);
    var sum = 0;
    for (var i = 0; i < buf.length; i++) {
      var v = (buf[i] - 128) / 128;
      sum += v * v;
    }
    var pct = Math.min(100, Math.round(Math.sqrt(sum / buf.length) * 220));
    if (els.echoTestMeter) els.echoTestMeter.value = pct;
    if (els.echoTestMeterVal) els.echoTestMeterVal.textContent = pct + '%';
    et.raf = requestAnimationFrame(echoTestMeterLoop);
  }

  /* Background effects: a LiveKit TrackProcessor that composites the person
   * (MediaPipe selfie segmentation) over a blurred or custom-image background.
   * MediaPipe is lazy-loaded and only runs while an effect is active. */
  function currentBgEffect() {
    if (!state.prefs || !state.prefs.bg || state.prefs.bg === 'none') return null;
    return { mode: state.prefs.bg, blur: Number(state.prefs.blur) || 8, image: state.prefs.image || '' };
  }

  function currentBgProcessor() {
    var eff = currentBgEffect();
    if (!eff) {
      if (state.bgProc) { try { state.bgProc.destroy(); } catch (e) {} }
      state.bgProc = null;
      state.bgProcKey = '';
      return null;
    }
    var key = JSON.stringify(eff);
    if (state.bgProc && state.bgProcKey === key) return state.bgProc;
    if (state.bgProc) { try { state.bgProc.destroy(); } catch (e) {} }
    state.bgProcKey = key;
    try { state.bgProc = makeBgProcessor(eff); } catch (e) { state.bgProc = null; }
    return state.bgProc;
  }

  function makeBgProcessor(effect) {
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');
    var video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;
    video.muted = true;
    var maskCanvas = document.createElement('canvas');
    var maskCtx = maskCanvas.getContext('2d');
    var bgCanvas = document.createElement('canvas');
    var bgCtx = bgCanvas.getContext('2d');
    var personCanvas = document.createElement('canvas');
    var personCtx = personCanvas.getContext('2d');
    var segFrame = document.createElement('canvas');
    var seg = null;
    var stream = null;
    var outTrack = null;
    var raf = 0;
    var running = false;
    var outW = 640, outH = 360, segW = 320, segH = 180;
    var bgImg = new Image();
    var bgImageOk = false;

    function locateFile(file) {
      // Ship only the SIMD wasm variant; map the non-SIMD name onto it.
      if (file.indexOf('simd') === -1 && file.indexOf('_solution_wasm_bin') !== -1) {
        file = file.replace('selfie_segmentation_solution_wasm_bin', 'selfie_segmentation_solution_simd_wasm_bin');
      }
      return BG_BASE + file;
    }

    function loadLib() {
      if (window.SelfieSegmentation) return Promise.resolve(window.SelfieSegmentation);
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = BG_BASE + 'selfie_segmentation.js';
        s.onload = function () {
          if (window.SelfieSegmentation) resolve(window.SelfieSegmentation);
          else reject(new Error('background library failed to load'));
        };
        s.onerror = function () { reject(new Error('could not load the background library')); };
        document.head.appendChild(s);
      });
    }

    function init() {
      if (effect.mode === 'image' && effect.image) {
        bgImg.onload = function () { bgImageOk = true; };
        bgImg.onerror = function () { bgImageOk = false; };
        bgImg.src = effect.image;
      }
      return loadLib().then(function (SS) {
        seg = new SS({ locateFile: locateFile });
        seg.setOptions({ modelSelection: 0, selfieMode: false });
        return seg.initialize();
      });
    }

    function drawBackground() {
      var w = bgCanvas.width, h = bgCanvas.height;
      if (effect.mode === 'image' && bgImageOk) {
        var iw = bgImg.naturalWidth || 1, ih = bgImg.naturalHeight || 1;
        var scale = Math.max(w / iw, h / ih);
        bgCtx.drawImage(bgImg, (w - iw * scale) / 2, (h - ih * scale) / 2, iw * scale, ih * scale);
      } else if (effect.mode === 'blur') {
        bgCtx.filter = 'blur(' + (Number(effect.blur) || 8) + 'px)';
        bgCtx.drawImage(video, 0, 0, w, h);
        bgCtx.filter = 'none';
      } else {
        bgCtx.drawImage(video, 0, 0, w, h);
      }
    }

    /* Turn the segmentation mask into an alpha cutout on maskCanvas. */
    function maskToAlpha(res) {
      var mask = res.segmentationMask || res.mask || null;
      if (!mask) return false;
      var mw = 0, mh = 0;
      if (mask.data) { // ImageData
        mw = mask.width; mh = mask.height;
        var d = mask.data;
        for (var i = 0; i < d.length; i += 4) {
          d[i + 3] = d[i]; d[i] = d[i + 1] = d[i + 2] = 0;
        }
        maskCanvas.width = mw; maskCanvas.height = mh;
        maskCtx.putImageData(mask, 0, 0);
      } else { // ImageBitmap
        mw = mask.width || mask.videoWidth || 0;
        mh = mask.height || mask.videoHeight || 0;
        if (!mw) return false;
        maskCanvas.width = mw; maskCanvas.height = mh;
        maskCtx.drawImage(mask, 0, 0);
        var id = maskCtx.getImageData(0, 0, mw, mh);
        for (var j = 0; j < id.data.length; j += 4) {
          id.data[j + 3] = id.data[j]; id.data[j] = id.data[j + 1] = id.data[j + 2] = 0;
        }
        maskCtx.putImageData(id, 0, 0);
      }
      return mw > 0;
    }

    function composite(res) {
      if (!maskToAlpha(res)) return;
      personCtx.clearRect(0, 0, outW, outH);
      personCtx.drawImage(video, 0, 0, outW, outH);
      personCtx.save();
      personCtx.globalCompositeOperation = 'destination-in';
      personCtx.imageSmoothingEnabled = true;
      personCtx.drawImage(maskCanvas, 0, 0, outW, outH);
      personCtx.restore();
      drawBackground();
      ctx.clearRect(0, 0, outW, outH);
      ctx.drawImage(bgCanvas, 0, 0);
      ctx.drawImage(personCanvas, 0, 0);
    }

    function drawLoop() {
      raf = requestAnimationFrame(drawLoop);
      if (!running || !seg) return;
      segFrame.width = segW;
      segFrame.height = segH;
      segFrame.getContext('2d').drawImage(video, 0, 0, segW, segH);
      seg.send({ image: segFrame }).then(function (res) {
        if (running) composite(res);
      }).catch(function () { /* skip a frame */ });
    }

    function sizeFor(track) {
      var s = {};
      try { s = track && track.getSettings ? track.getSettings() : {}; } catch (e) {}
      outW = Math.min(1280, Math.max(320, Number(s.width) || 640));
      outH = Math.min(720, Math.max(180, Math.round(outW * (Number(s.height) || 360) / (Number(s.width) || 640))));
      if (outH % 2) outH++;
      segW = Math.max(320, Math.round(outW / 2));
      if (segW % 2) segW++;
      segH = Math.max(180, Math.round(outH / 2));
      if (segH % 2) segH++;
      canvas.width = outW; canvas.height = outH;
      bgCanvas.width = outW; bgCanvas.height = outH;
      personCanvas.width = outW; personCanvas.height = outH;
    }

    return {
      name: 'bg-effect',
      processSource: function (track) {
        sizeFor(track);
        video.srcObject = new MediaStream([track]);
        stream = canvas.captureStream(15);
        outTrack = stream.getVideoTracks()[0];
        init().then(function () {
          running = true;
          drawLoop();
        }).catch(function (err) {
          toast('Background effect unavailable: ' + (err && err.message || err));
        });
        return Promise.resolve(outTrack);
      },
      updateSource: function (track) {
        if (track) {
          video.srcObject = new MediaStream([track]);
          sizeFor(track);
        }
      },
      destroy: function () {
        running = false;
        cancelAnimationFrame(raf);
        if (seg) { try { seg.close(); } catch (e) {} seg = null; }
        if (stream) { try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} }
        if (outTrack) { try { outTrack.stop(); } catch (e) {} }
        if (video.srcObject) {
          try { video.srcObject.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
        }
        video.srcObject = null;
      }
    };
  }

  /* Settings modal DOM. */
  function buildSettings() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-settings';
    el.className = 'hidden lvcvoice-mtg-overlay';
    el.innerHTML =
      '<div class="lvcvoice-settings-card">' +
      '<div class="lvcvoice-mtg-head"><span>Voice &amp; video settings</span>' +
      '<button type="button" class="lvcvoice-btn-ghost st-close">✕</button></div>' +
      '<div class="lvcvoice-settings-body">' +

      '<div class="st-section"><div class="st-label">Audio &amp; video devices</div>' +
      '<label class="st-field">Microphone<select id="lvcvoice-st-mic" class="st-select"></select></label>' +
      '<label class="st-field">Camera<select id="lvcvoice-st-cam" class="st-select"></select></label>' +
      '<label class="st-field">Speaker<select id="lvcvoice-st-speaker" class="st-select"></select></label>' +
      '<button type="button" class="lvcvoice-btn-ghost st-testcam" style="width:100%">Test camera</button>' +
      '</div>' +

      '<div class="st-section st-camtest hidden" id="lvcvoice-st-camtest">' +
      '<div class="st-label">Camera preview</div>' +
      '<video id="lvcvoice-st-camvideo" class="st-camvideo" autoplay playsinline muted></video>' +
      '<div class="st-row"><button type="button" class="lvcvoice-btn-ghost st-camstart">Start</button>' +
      '<button type="button" class="lvcvoice-btn-ghost st-camstop">Stop</button></div>' +
      '</div>' +

      '<div class="st-section"><div class="st-label">Microphone test ' +
      '<span class="st-meter-wrap"><meter id="lvcvoice-st-meter" min="0" max="100" low="10" high="70"></meter>' +
      '<span class="st-meter-val"></span></span></div>' +
      '<button type="button" class="lvcvoice-btn-ghost st-mictest" style="width:100%">Test microphone</button>' +
      '</div>' +

      '<div class="st-section"><div class="st-label">Video background</div>' +
      '<div class="st-row">' +
      '<button type="button" class="lvcvoice-btn-ghost st-bg" data-bg="none">None</button>' +
      '<button type="button" class="lvcvoice-btn-ghost st-bg" data-bg="blur">Blur</button>' +
      '<button type="button" class="lvcvoice-btn-ghost st-bg" data-bg="image">Image</button>' +
      '</div>' +
      '<div class="st-blur hidden"><label class="st-field">Blur strength ' +
      '<input id="lvcvoice-st-blur" type="range" min="2" max="20" step="1"></label></div>' +
      '<div class="st-image hidden">' +
      '<input id="lvcvoice-st-imgfile" type="file" accept="image/png,image/jpeg,image/webp">' +
      '<div class="st-row"><button type="button" class="lvcvoice-btn-ghost st-imgremove">Remove image</button></div>' +
      '<img id="lvcvoice-st-imgprev" class="st-imgprev hidden" alt="Background preview">' +
      '</div>' +
      '<p class="st-hint">Background effects only apply while your camera is on, and are processed locally in your browser.</p>' +
      '</div>' +

      '<div class="st-section"><div class="st-label">Video quality</div>' +
      '<div class="st-row st-vq-row" id="lvcvoice-st-vq"></div>' +
      '<p class="st-hint">Higher quality uses more bandwidth. This affects camera preview and published video.</p>' +
      '</div>' +

      '<div class="st-row st-actions">' +
      '<button type="button" class="btn-primary st-save">Save settings</button>' +
      '<button type="button" class="lvcvoice-btn-ghost st-cancel">Cancel</button>' +
      '</div>' +
      '</div></div>';

    el.querySelector('.st-close').addEventListener('click', closeSettings);
    el.querySelector('.st-cancel').addEventListener('click', closeSettings);
    el.addEventListener('click', function (e) { if (e.target === el) closeSettings(); });
    el.querySelector('.st-testcam').addEventListener('click', function () { if (state.camTest) stopCamTest(); else startCamTest(); });
    el.querySelector('.st-camstart').addEventListener('click', startCamTest);
    el.querySelector('.st-camstop').addEventListener('click', stopCamTest);
    el.querySelector('.st-mictest').addEventListener('click', function () { if (state.micTest) stopMicTest(); else startMicTest(); });
    el.querySelector('.st-save').addEventListener('click', saveSettings);
    el.querySelectorAll('.st-bg').forEach(function (b) {
      b.addEventListener('click', function () {
        state.prefs.bg = b.dataset.bg;
        renderSettings();
      });
    });
    el.querySelector('#lvcvoice-st-blur').addEventListener('input', function (e) {
      state.prefs.blur = Number(e.target.value);
    });
    el.querySelector('#lvcvoice-st-imgfile').addEventListener('change', onBgImageFile);
    el.querySelector('.st-imgremove').addEventListener('click', removeBgImage);

    document.body.appendChild(el);
    els.settings = el;
    els.stMic = el.querySelector('#lvcvoice-st-mic');
    els.stCam = el.querySelector('#lvcvoice-st-cam');
    els.stSpeaker = el.querySelector('#lvcvoice-st-speaker');
    els.stCamTest = el.querySelector('#lvcvoice-st-camtest');
    els.stCamVideo = el.querySelector('#lvcvoice-st-camvideo');
    els.stMeter = el.querySelector('#lvcvoice-st-meter');
    els.stMeterVal = el.querySelector('.st-meter-val');
    els.stBlur = el.querySelector('#lvcvoice-st-blur');
    els.stBlurRow = el.querySelector('.st-blur');
    els.stImageRow = el.querySelector('.st-image');
    els.stImgPrev = el.querySelector('#lvcvoice-st-imgprev');
    els.stImgFile = el.querySelector('#lvcvoice-st-imgfile');
    els.stVqRow = el.querySelector('#lvcvoice-st-vq');
  }

  function onBgImageFile(e) {
    var file = e.target.files && e.target.files[0];
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)/i.test(file.type || '')) {
      toast('Please choose a PNG, JPEG or WebP image.');
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      var img = new Image();
      img.onload = function () {
        var w = img.width, h = img.height;
        var scale = Math.min(1, Math.min(1280 / w, 720 / h));
        if (scale < 1) { w = Math.round(w * scale); h = Math.round(h * scale); }
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        var url = c.toDataURL('image/jpeg', 0.85);
        if (url.length > 400000) { toast('Image too large — please pick a smaller one.'); return; }
        state.prefs.image = url;
        state.prefs.bg = 'image';
        renderSettings();
      };
      img.onerror = function () { toast('Could not read that image.'); };
      img.src = String(reader.result);
    };
    reader.readAsDataURL(file);
  }

  function removeBgImage() {
    state.prefs.image = '';
    if (els.stImgPrev) { els.stImgPrev.src = ''; els.stImgPrev.classList.add('hidden'); }
    if (els.stImgFile) els.stImgFile.value = '';
    renderSettings();
  }

  /* ── Actions ────────────────────────────────────────────────────────── */
  function currentChannel() {
    return hostCurrentChannel();
  }
  function currentDm() {
    return hostCurrentDm();
  }

  function toggleVoice() {
    var slug = currentChannel();
    if (!slug || state.connecting) return;
    if (state.inVoice) { leaveVoice(); return; }
    if (state.waitingRoom) return;
    if (state.full) return;
    state.pendingJoin = { channel: slug };
    render();
    api('/api/webrtc/voice/join', { channel: slug }).then(function (j) {
      state.pendingJoin = null;
      if (j.ok && j.waiting) {
        state.waitingRoom = true;
        render();
        return;
      }
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
    state.pendingCallVideo = false;
    state.outgoingRing = false;
    stopRing();
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    state.inVoice = false;
    state.connecting = false;
    state.waitingRoom = false;
    lastWaiting = false;
    state.pendingJoin = null;
    clearTiles();
    api('/api/webrtc/voice/leave', {}).then(render);
    render();
  }

  function startCall(video) {
    var dm = currentDm();
    if (!dm) return;
    state.pendingCallVideo = !!video;
    api('/api/webrtc/call/initiate', { user: dm }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not start the call.'); return; }
      state.pendingCall = j.call_id;
      state.pendingCallAt = Date.now();
      state.ringStarted[j.call_id] = Date.now();
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20;
      // Audible ring on the caller side: initiated by a click, so the
      // autoplay policy permits immediate playback here.
      state.outgoingRing = true;
      playRing();
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
    // Covers both the active call and a still-ringing outgoing call — the pill's
    // End button must cancel the ring server-side, not just disconnect locally.
    var call = state.calls.active || state.calls.outgoing[0] || null;
    if (call) api('/api/webrtc/call/end', { call_id: call.call_id });
    state.pendingCall = null;
    state.pendingCallVideo = false;
    state.outgoingRing = false;
    stopRing();
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    state.inVoice = false;
    state.waitingRoom = false;
    lastWaiting = false;
    clearTiles();
    render();
  }

  function findRecentCall(callId) {
    for (var i = 0; i < state.recent.length; i++) {
      if (state.recent[i].call_id === callId) return state.recent[i];
    }
    return null;
  }

  function recentMessage(done) {
    if (done) {
      if (done.status === 'declined') return done.peer + ' declined the call.';
      if (done.status === 'missed') return done.peer + " didn't answer — call missed.";
      if (done.status === 'cancelled') return 'You cancelled the call.';
      if (done.status === 'ended') return 'Call ended.';
    }
    return 'Call ended — no answer.';
  }

  function handleCallTransitions() {
    var active = state.calls.active;
    if (active) state.pendingCall = null;
    if (active && state.room && state.room.state === 'connected') return;
    if (active && !state.room && !state.connecting) {
      api('/api/webrtc/call/join', { call_id: active.call_id }).then(function (j) {
        if (j.ok) { state.inVoice = true; connectLivekit(j.url, j.token, j.room); }
      });
    }
    // Prune ring timers that are no longer relevant (accepted / ended / expired).
    var liveIds = [];
    if (active) liveIds.push(active.call_id);
    state.calls.outgoing.forEach(function (c) { liveIds.push(c.call_id); });
    state.calls.incoming.forEach(function (c) { liveIds.push(c.call_id); });
    Object.keys(state.ringStarted).forEach(function (cid) {
      if (liveIds.indexOf(Number(cid)) === -1) delete state.ringStarted[cid];
    });
    // An outgoing ring vanished without connecting → declined / missed / cancelled.
    // The pendingCallAt grace window absorbs a stale poll snapshot that predates
    // the call row appearing, so we don't toast "no answer" by accident.
    if (state.pendingCall && !active && !state.calls.outgoing[0] && Date.now() - state.pendingCallAt > POLL_MS * 1.5) {
      var cid = state.pendingCall;
      state.pendingCall = null;
      state.pendingCallVideo = false;
      delete state.ringStarted[cid];
      toast(recentMessage(findRecentCall(cid)));
    }
  }

  /* ── Events ──────────────────────────────────────────────────────────── */
  function isEventChannel(slug) {
    return /^#?evt-[0-9a-f]{32}$/i.test(slug || '');
  }

  function eventModalOpen() {
    return els.evtModal && !els.evtModal.classList.contains('hidden');
  }

  function openEventModal() {
    ensureEls();
    if (!els.evtModal) return;
    els.evtModal.classList.remove('hidden');
    var slug = currentChannel();
    renderEvent(slug && isEventChannel(slug) ? slug : '');
    refreshEventsList();
  }

  function closeEventModal() {
    if (els.evtModal) els.evtModal.classList.add('hidden');
  }

  function createEvent() {
    var title = (els.evtTitle && els.evtTitle.value || '').trim();
    if (!title) { toast('Please enter a title.'); return; }
    var desc = (els.evtDesc && els.evtDesc.value || '').trim();
    var isPublic = els.evtPublic && els.evtPublic.checked;
    var waitingRoom = els.evtWaiting && els.evtWaiting.checked;
    var eventType = (els.evtType && els.evtType.value) || 'webrtc';
    var streamUrl = (els.evtStreamUrl && els.evtStreamUrl.value || '').trim();
    var scheduledAt = (els.evtSchedule && els.evtSchedule.value || '').trim();
    var duration = parseInt((els.evtDuration && els.evtDuration.value) || '0', 10) || 0;

    var params = {
      title: title,
      description: desc,
      is_public: isPublic ? 1 : 0,
      waiting_room: waitingRoom ? 1 : 0,
      event_type: eventType,
      stream_url: streamUrl,
      scheduled_at: scheduledAt,
      duration_minutes: duration
    };

    api('/api/events/create', params).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not create event.'); return; }
      state.evt = { id: j.id, slug: j.slug, title: title, invite_code: j.invite_code, invite_url: j.invite_url, status: j.status };
      renderEventView();
      refreshEventsList();
      if (j.slug) {
        hostOpenChannel(j.slug);
      }
    });
  }

  /* ── My events list (founder: list + cancel + copy link) ─────────────── */
  function refreshEventsList() {
    if (!els.evtList) return;
    els.evtList.innerHTML = '<div class="pane-meta">Loading…</div>';
    api('/api/events/list').then(function (j) {
      if (!j.ok || !j.events) { els.evtList.innerHTML = ''; return; }
      var active = (j.events || []).filter(function (e) { return e.status === 'active' || e.status === 'scheduled'; });
      if (!active.length) { els.evtList.innerHTML = '<div class="pane-meta">No events yet.</div>'; return; }
      var html = active.map(function (e) {
        var url = e.invite_code ? '/e/' + e.invite_code : (e.channel_slug ? '/event/' + e.channel_slug : '');
        var when = e.scheduled_at
          ? '<span class="pane-meta">' + escapeHtml(e.scheduled_at.replace('T', ' ').slice(0, 16)) + ' UTC</span>'
          : '<span class="pane-meta">' + (e.status === 'active' ? 'Live now' : '') + '</span>';
        return '<div class="evt-list-row">' +
          '<span class="evt-list-title">' + escapeHtml(e.title) +
          ' <span class="pane-meta">[' + escapeHtml(e.status) + ']</span></span>' +
          when +
          '<span class="evt-list-actions">' +
          (url ? '<button type="button" class="lvcvoice-btn-ghost evt-list-copy" data-url="' + escapeHtml(url) + '">Link</button>' : '') +
          '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger evt-list-cancel" data-id="' + (e.id || 0) + '">Cancel</button>' +
          '</span></div>';
      }).join('');
      els.evtList.innerHTML = '<div class="evt-list">' + html + '</div>';
      els.evtList.querySelectorAll('.evt-list-copy').forEach(function (b) {
        b.addEventListener('click', function () {
          var url = b.dataset.url || '';
          if (!url) return;
          var abs = window.location.origin + url;
          var done = function () { toast('Invite link copied.'); };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(abs).then(done, function () { toast('Copy: ' + abs); });
          } else {
            toast('Invite: ' + abs);
          }
        });
      });
      els.evtList.querySelectorAll('.evt-list-cancel').forEach(function (b) {
        b.addEventListener('click', function () {
          api('/api/events/cancel', { event_id: b.dataset.id }).then(function (j) {
            toast(j.ok ? 'Event cancelled.' : (j.error || 'Could not cancel event.'));
            refreshEventsList();
            pollStatus();
          });
        });
      });
    }).catch(function () { els.evtList.innerHTML = ''; });
  }

  function sendEventInvites() {
    var evt = state.evt;
    if (!evt || !evt.id) return;
    var input = els.evtInviteEmails;
    var emails = (input && input.value || '').trim();
    if (!emails) return;
    api('/api/events/invite', { event_id: evt.id, emails: emails }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not send invites.'); return; }
      input.value = '';
      var parts = [];
      if (j.sent && j.sent.length) parts.push('Sent: ' + j.sent.length);
      if (j.failed && j.failed.length) parts.push('Failed: ' + j.failed.length);
      els.evtInviteResult.textContent = parts.length ? parts.join(' · ') : 'Invites sent.';
      els.evtInviteResult.classList.remove('hidden');
      setTimeout(function () { els.evtInviteResult.classList.add('hidden'); }, 6000);
    });
  }

  function copyEventInviteUrl() {
    var input = els.evtInviteUrl;
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

  function renderEvent(slug) {
    if (!els.evtForm || !els.evtView) return;
    if (slug && isEventChannel(slug)) {
      els.evtForm.classList.add('hidden');
      els.evtView.classList.remove('hidden');
      var name = '#' + slug.replace(/^#?/, '');
      els.evtViewName.textContent = name;
      if (state.evt && state.evt.slug === slug) {
        els.evtInviteUrl.value = state.evt.invite_url || '';
      } else {
        els.evtInviteUrl.value = '';
        els.evtInviteUrl.placeholder = 'Invite URL — created by the event host';
      }
    } else {
      els.evtForm.classList.remove('hidden');
      els.evtView.classList.add('hidden');
    }
  }

  function renderEventView() {
    if (!els.evtForm || !els.evtView) return;
    var evt = state.evt;
    if (evt && evt.slug) {
      els.evtForm.classList.add('hidden');
      els.evtView.classList.remove('hidden');
      els.evtViewName.textContent = '#' + evt.slug.replace(/^#?/, '');
      els.evtInviteUrl.value = evt.invite_url || '';
    } else {
      els.evtForm.classList.remove('hidden');
      els.evtView.classList.add('hidden');
    }
  }

  /* ── DOM ────────────────────────────────────────────────────────────── */
  var ICON_VIDEO = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="13" height="12" rx="2"/><path d="M15 10l5-3v10l-5-3"/></svg>';

  function ensureEls() {
    if (els.dropdown) return;
    var header = hostHeaderEl();
    if (!header) return;

    var dd = document.createElement('div');
    dd.id = 'lvcvoice-dropdown';
    dd.className = 'lvcvoice-dropdown hidden md:block';

    var trigger = document.createElement('button');
    trigger.id = 'lvcvoice-dd-trigger';
    trigger.type = 'button';
    trigger.className = 'btn-ghost text-xs lvcvoice-dd-trigger';
    trigger.innerHTML = '<span class="lvcvoice-dd-dot"></span><span class="lvcvoice-dd-icon">' + ICON_CALL + '</span><span class="lvcvoice-dd-arrow">▾</span>';
    trigger.addEventListener('click', toggleDropdown);
    dd.appendChild(trigger);

    var menu = document.createElement('div');
    menu.id = 'lvcvoice-dd-menu';
    menu.className = 'lvcvoice-dd-menu hidden';

    els.ddCallItem = document.createElement('button');
    els.ddCallItem.type = 'button';
    els.ddCallItem.className = 'lvcvoice-dd-item';
    els.ddCallItem.innerHTML = '<span class="lvcvoice-dd-item-icon" style="color:#22c55e">' + ICON_CALL + '</span><span class="lvcvoice-dd-item-label">Call</span>';
    els.ddCallItem.addEventListener('click', function () {
      closeDropdown();
      if (state.calls.active || state.calls.outgoing[0]) { endCall(); return; }
      if (state.inVoice) { leaveVoice(); return; }
      onMainClick();
    });
    menu.appendChild(els.ddCallItem);

    els.ddVideoCallItem = document.createElement('button');
    els.ddVideoCallItem.type = 'button';
    els.ddVideoCallItem.className = 'lvcvoice-dd-item';
    els.ddVideoCallItem.innerHTML = '<span class="lvcvoice-dd-item-icon" style="color:#a78bfa">' + ICON_VIDEO + '</span><span class="lvcvoice-dd-item-label">Video call</span>';
    els.ddVideoCallItem.addEventListener('click', function () {
      closeDropdown();
      if (state.calls.active || state.calls.outgoing[0]) { endCall(); return; }
      if (state.inVoice) { leaveVoice(); return; }
      startCall(true);
    });
    menu.appendChild(els.ddVideoCallItem);

    els.ddEvtItem = document.createElement('button');
    els.ddEvtItem.type = 'button';
    els.ddEvtItem.className = 'lvcvoice-dd-item';
    els.ddEvtItem.innerHTML = '<span class="lvcvoice-dd-item-icon">📅</span><span class="lvcvoice-dd-item-label">Event</span>';
    els.ddEvtItem.addEventListener('click', function () { closeDropdown(); openEventModal(); });
    menu.appendChild(els.ddEvtItem);

    els.ddSettingsItem = document.createElement('button');
    els.ddSettingsItem.type = 'button';
    els.ddSettingsItem.className = 'lvcvoice-dd-item';
    els.ddSettingsItem.innerHTML = '<span class="lvcvoice-dd-item-icon">⚙</span><span class="lvcvoice-dd-item-label">Voice & video settings</span>';
    els.ddSettingsItem.addEventListener('click', function () { closeDropdown(); openSettings(); });
    menu.appendChild(els.ddSettingsItem);

    els.ddEchoTestItem = document.createElement('button');
    els.ddEchoTestItem.type = 'button';
    els.ddEchoTestItem.className = 'lvcvoice-dd-item';
    els.ddEchoTestItem.innerHTML = '<span class="lvcvoice-dd-item-icon">🎙</span><span class="lvcvoice-dd-item-label">Echo test</span>';
    els.ddEchoTestItem.addEventListener('click', function () { closeDropdown(); openEchoTest(); });
    menu.appendChild(els.ddEchoTestItem);

    dd.appendChild(menu);
    header.appendChild(dd);
    els.dropdown = dd;
    els.ddTrigger = trigger;
    els.ddMenu = menu;
    els.ddDot = trigger.querySelector('.lvcvoice-dd-dot');
    els.ddIcon = trigger.querySelector('.lvcvoice-dd-icon');

    var pill = document.createElement('span');
    pill.id = 'lvcvoice-callpill';
    pill.className = 'hidden text-xs';
    pill.innerHTML = '<span class="dot"></span><span class="pill-text"></span><button type="button" class="lvcvoice-btn-ghost lvcvoice-pill-hangup" style="margin-left:6px" title="End call">' + ICON_HANGUP + '</button>';
    pill.querySelector('button').addEventListener('click', endCall);
    header.insertBefore(pill, dd);
    els.pill = pill;

    document.body.appendChild(buildPane());
    document.body.appendChild(buildRing());
    els.evtModal = buildEvtModal();
    document.body.appendChild(els.evtModal);
    buildSettings();

    document.addEventListener('click', function (e) {
      if (els.dropdown && !els.dropdown.contains(e.target)) closeDropdown();
    });
  }

  function toggleDropdown(e) {
    e.stopPropagation();
    if (!els.ddMenu) return;
    els.ddMenu.classList.toggle('hidden');
  }

  function closeDropdown() {
    if (els.ddMenu) els.ddMenu.classList.add('hidden');
  }

  function buildPane() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-pane';
    el.className = 'hidden';
    el.innerHTML =
      '<div class="pane-head"><span class="pane-title">Voice</span>' +
      '<span class="pane-head-actions">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-vq" title="Video quality">720p</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-layout" title="Layout: auto / speaker / gallery">⬒</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-min" title="Minimize">–</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-settings" title="Voice &amp; video settings">⚙</button>' +
      '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger pane-leave">Leave</button>' +
      '</span></div>' +
      '<div class="pane-body">' +
      '<div id="lvcvoice-videos" class="lvcvoice-videos"></div>' +
      '<div class="pane-waiting hidden"><div class="pane-waiting-card">' +
      '<div class="pane-waiting-title">Waiting room</div>' +
      '<div class="pane-meta">The host has been notified. You can join as soon as they let you in.</div>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-waiting-leave">Leave waiting room</button>' +
      '</div></div>' +
      '<div class="pane-reactions hidden"></div>' +
      '<div class="pane-reaction-bursts"></div>' +
      '<div class="pane-roster"></div>' +
      '<div class="pane-invite hidden">' +
      '<div class="pane-invite-row"><input class="input text-xs pane-invite-input" placeholder="Nicks to invite (comma sep.)">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-invite-btn">Invite</button></div>' +
      '<div class="pane-meta pane-invite-result"></div>' +
      '</div>' +
      '<div class="pane-row"><span class="pane-meta pane-status">Connected to the voice room.</span></div>' +
      '<div class="pane-controls">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-mute" data-act="mute">Mute</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-deafen" data-act="deafen">Deafen</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-cam" data-act="cam">Camera</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-share" data-act="share">Share</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-hand" data-act="hand">✋ Hand</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-react" data-act="react">😀</button>' +
      '</div>' +
      '<div class="pane-mod hidden">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-muteall" data-act="muteall">Mute all</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-unmuteall" data-act="unmuteall">Unmute all</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-lock" data-act="lock">Lock room</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-record" data-act="record" title="Record this meeting (LiveKit egress)">⏺ Record</button>' +
      '</div>' +
      '<label class="pane-shareaudio-label hidden"><input type="checkbox" class="pane-shareaudio"> Share screen with system audio</label>' +
      '</div>';
    el.querySelector('.pane-leave').addEventListener('click', function () { leaveVoice(); });
    el.querySelector('.pane-waiting-leave').addEventListener('click', function () { leaveVoice(); });
    el.querySelector('.pane-settings').addEventListener('click', openSettings);
    el.querySelector('.pane-mute').addEventListener('click', toggleMute);
    el.querySelector('.pane-deafen').addEventListener('click', toggleDeafen);
    el.querySelector('.pane-cam').addEventListener('click', toggleCamera);
    el.querySelector('.pane-share').addEventListener('click', toggleScreenShare);
    el.querySelector('.pane-hand').addEventListener('click', toggleHand);
    el.querySelector('.pane-react').addEventListener('click', function () { toggleReactionStrip(); });
    el.querySelector('.pane-layout').addEventListener('click', cycleLayout);
    el.querySelector('.pane-vq').addEventListener('click', cycleVideoQuality);
    el.querySelector('.pane-min').addEventListener('click', toggleMinimize);
    el.querySelector('.pane-muteall').addEventListener('click', function () { moderate('mute_all'); });
    el.querySelector('.pane-unmuteall').addEventListener('click', function () { moderate('unmute_all'); });
    el.querySelector('.pane-lock').addEventListener('click', function () { moderate(state.session && state.session.locked ? 'unlock' : 'lock'); });
    el.querySelector('.pane-record').addEventListener('click', function () {
      var sess = state.session;
      if (!sess || !sess.room) return;
      var recording = state.recording || { enabled: false, active: null };
      if (recording.active && recording.active.room === sess.room) {
        record('stop');
      } else {
        record('start');
      }
    });
    el.querySelector('.pane-shareaudio').addEventListener('change', function (e) {
      state.prefs.screenAudio = !!e.target.checked;
      savePrefs();
    });
    el.querySelector('.pane-invite-btn').addEventListener('click', function () {
      var input = el.querySelector('.pane-invite-input');
      var result = el.querySelector('.pane-invite-result');
      var users = (input && input.value || '').trim();
      var call = state.calls.active;
      if (!call || !users) return;
      api('/api/webrtc/call/invite', { call_id: call.call_id, users: users }).then(function (j) {
        input.value = '';
        if (!j.ok) { result.textContent = j.error || 'Could not invite.'; return; }
        var parts = [];
        if (j.added && j.added.length) parts.push('Added: ' + j.added.join(', '));
        if (j.busy && j.busy.length) parts.push('Busy: ' + j.busy.join(', '));
        if (j.unknown && j.unknown.length) parts.push('Unknown: ' + j.unknown.join(', '));
        result.textContent = parts.join(' · ') || 'Invites sent.';
      });
    });
    // Reaction strip (emoji burst).
    ['👍', '❤️', '🎉', '👏', '😮', '😂'].forEach(function (em) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lvcvoice-btn-ghost pane-reac-btn';
      b.textContent = em;
      b.addEventListener('click', function () { sendReaction(em); });
      el.querySelector('.pane-reactions').appendChild(b);
    });
    els.videos = el.querySelector('#lvcvoice-videos');
    els.pane = el;
    els.paneRoster = el.querySelector('.pane-roster');
    els.paneWait = el.querySelector('.pane-waiting');
    els.paneReactions = el.querySelector('.pane-reactions');
    els.paneBursts = el.querySelector('.pane-reaction-bursts');
    els.paneMod = el.querySelector('.pane-mod');
    els.paneShareAudioLabel = el.querySelector('.pane-shareaudio-label');
    els.paneShareAudio = el.querySelector('.pane-shareaudio');
    return el;
  }

  function cycleLayout() {
    var next = state.layout === 'auto' ? 'speaker' : (state.layout === 'speaker' ? 'gallery' : 'auto');
    setLayout(next);
    toast('Layout: ' + next);
  }

  function cycleVideoQuality() {
    var avail = state.videoQualityAvailable || ['360p', '480p', '720p', '1080p'];
    var cur = state.videoQuality || '720p';
    var idx = avail.indexOf(cur);
    var next = avail[(idx + 1) % avail.length];
    state.videoQuality = next;
    state.prefs.videoQuality = next;
    savePrefs();
    toast('Video quality: ' + next);
    render();
    var room = state.room;
    if (room && room.localParticipant.isCameraEnabled()) restartCamera();
  }

  function toggleMinimize() {
    state.minimized = !state.minimized;
    if (els.pane) els.pane.classList.toggle('minimized', state.minimized);
    render();
  }

  function toggleReactionStrip() {
    if (!els.paneReactions) return;
    els.paneReactions.classList.toggle('hidden');
  }

  /* Server-side moderation (host controls) via the /api/webrtc/moderate gate. */
  function moderate(action, identity) {
    var sess = state.session;
    if (!sess || !sess.room) return Promise.resolve();
    var params = { room: sess.room, action: action };
    if (identity) params.identity = identity;
    return api('/api/webrtc/moderate', params).then(function (j) {
      if (j.ok) {
        toast(ACTION_LABELS[action] || 'Done.');
        pollStatus();
      } else {
        toast(j.error || 'Moderation action failed.');
      }
      return j;
    });
  }
  var ACTION_LABELS = {
    kick: 'Participant removed.', mute: 'Participant muted.', unmute: 'Participant unmuted.',
    mute_all: 'Everyone is muted.', unmute_all: 'Everyone is unmuted.', lock: 'Room locked.',
    unlock: 'Room unlocked.', admit: 'Let them in.', deny: 'Request declined.'
  };

  /* Recording (LiveKit egress): start/stop for hosts. */
  function record(action) {
    var sess = state.session;
    if (!sess || !sess.room) return Promise.resolve();
    return api('/api/webrtc/record', { room: sess.room, action: action }).then(function (j) {
      if (j.ok) {
        toast(action === 'start' ? 'Recording started.' : 'Recording stopped.');
        pollStatus();
      } else {
        toast(j.error || 'Could not ' + action + ' the recording.');
      }
      return j;
    });
  }

  /* Roster render: participant list with mute/kick for moderators + waiting queue. */
  function renderRoster() {
    if (!els.paneRoster) return;
    var sess = state.session;
    if (!sess || !sess.roster || !sess.roster.length) {
      els.paneRoster.innerHTML = '<div class="pane-meta">Nobody else is in the room.</div>';
      return;
    }
    var canMod = !!sess.can_moderate;
    var html = '';
    (sess.roster || []).forEach(function (r) {
      var qual = state.quality[r.identity];
      var qdot = qual ? '<span class="lvcvoice-q q-' + qual + '" title="Connection: ' + qual + '"></span>' : '';
      var hand = state.hands[r.identity] ? '<span class="lvcvoice-hand" title="Raised hand">✋</span>' : '';
      var actions = '';
      if (canMod && !r.me && (state.room && state.room.state === 'connected')) {
        actions += '<button type="button" class="lvcvoice-btn-ghost roster-mute" data-id="' + r.identity + '">Mute</button>';
        actions += '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger roster-kick" data-id="' + r.identity + '">Kick</button>';
      }
      var waiting = r.waiting ? ' <span class="pane-meta">(waiting)</span>' : '';
      var wButtons = '';
      if (canMod && r.waiting) {
        wButtons = '<button type="button" class="lvcvoice-btn-ghost roster-admit" data-id="' + r.identity + '">Admit</button>' +
          '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger roster-deny" data-id="' + r.identity + '">Deny</button>';
      }
      html += '<div class="pane-roster-row">' + qdot + hand +
        '<span class="pane-roster-name">' + escapeHtml(r.name) + waiting + (r.me ? ' (you)' : '') + '</span>' +
        '<span class="pane-roster-actions">' + actions + wButtons + '</span></div>';
    });
    els.paneRoster.innerHTML = html || '<div class="pane-meta">Nobody else is in the room.</div>';
    els.paneRoster.querySelectorAll('.roster-kick').forEach(function (b) {
      b.addEventListener('click', function () { moderate('kick', b.dataset.id); });
    });
    els.paneRoster.querySelectorAll('.roster-mute').forEach(function (b) {
      b.addEventListener('click', function () { moderate('mute', b.dataset.id); });
    });
    els.paneRoster.querySelectorAll('.roster-admit').forEach(function (b) {
      b.addEventListener('click', function () { moderate('admit', b.dataset.id); });
    });
    els.paneRoster.querySelectorAll('.roster-deny').forEach(function (b) {
      b.addEventListener('click', function () { moderate('deny', b.dataset.id); });
    });
  }

  function renderReactions() {
    if (!els.paneBursts || !state.reactions.length) return;
    var max = Date.now() - 6000;
    state.reactions = state.reactions.filter(function (r) { return r.at >= max; });
    if (!state.reactions.length) {
      els.paneBursts.innerHTML = '';
      return;
    }
    els.paneBursts.innerHTML = state.reactions.slice(-6).map(function (r) {
      return '<span class="pane-reaction" title="' + escapeHtml(r.name) + '">' + r.emoji + '</span>';
    }).join('');
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
      if (currentRingId != null) { state.ringDismissed = currentRingId; acceptCall(currentRingId); }
      el.classList.add('hidden');
      stopRing();
    });
    el.querySelector('.ring-decline').addEventListener('click', function () {
      if (currentRingId != null) { state.ringDismissed = currentRingId; declineCall(currentRingId); }
      el.classList.add('hidden');
      stopRing();
    });
    el._currentRingId = function (v) { if (arguments.length) currentRingId = v; return currentRingId; };
    el._ringVisible = function (vis) { if (arguments.length) { if (vis) playRing(); else if (!state.outgoingRing) stopRing(); } return vis; };
    return el;
  }

  function buildEvtModal() {
    var el = document.createElement('div');
    el.id = 'lvcvoice-evt-modal';
    el.className = 'hidden lvcvoice-mtg-overlay';
    el.innerHTML =
      '<div class="lvcvoice-mtg-card" style="max-width:560px">' +
      '<div class="lvcvoice-mtg-head"><span>Events</span>' +
      '<button type="button" class="lvcvoice-btn-ghost evt-close">✕</button></div>' +
      '<div class="lvcvoice-mtg-body">' +

      // ── Create form ──
      '<div id="lvcvoice-evt-form">' +
      '<div style="margin-bottom:12px">' +
      '<label class="label">Title</label>' +
      '<input id="lvcvoice-evt-title" class="input text-xs" placeholder="My Event" maxlength="120">' +
      '</div>' +
      '<div style="margin-bottom:12px">' +
      '<label class="label">Description (optional)</label>' +
      '<textarea id="lvcvoice-evt-desc" class="input text-xs" rows="2" placeholder="What\'s this event about?"></textarea>' +
      '</div>' +
      '<div style="margin-bottom:12px;display:flex;gap:16px">' +
      '<div><label class="label">Type</label>' +
      '<select id="lvcvoice-evt-type" class="input text-xs">' +
      '<option value="webrtc">WebRTC (interactive)</option>' +
      '<option value="link">Link (YouTube/Twitch)</option>' +
      '</select></div>' +
      '<div><label class="label">Visibility</label>' +
      '<label style="display:flex;align-items:center;gap:6px;margin-top:4px;cursor:pointer">' +
      '<input type="checkbox" id="lvcvoice-evt-public"> <span class="text-xs text-discord-300">Public</span>' +
      '</label></div>' +
      '<div><label class="label">Waiting room</label>' +
      '<label style="display:flex;align-items:center;gap:6px;margin-top:4px;cursor:pointer">' +
      '<input type="checkbox" id="lvcvoice-evt-waiting"> <span class="text-xs text-discord-300">Lobby</span>' +
      '</label></div>' +
      '</div>' +
      '<div id="lvcvoice-evt-stream-row" style="margin-bottom:12px;display:none">' +
      '<label class="label">Stream URL</label>' +
      '<input id="lvcvoice-evt-stream-url" class="input text-xs" placeholder="https://youtube.com/watch?v=...">' +
      '</div>' +
      '<div style="margin-bottom:12px;display:flex;gap:16px">' +
      '<div><label class="label">Schedule (optional)</label>' +
      '<input id="lvcvoice-evt-schedule" type="datetime-local" class="input text-xs"></div>' +
      '<div><label class="label">Duration (min)</label>' +
      '<input id="lvcvoice-evt-duration" type="number" class="input text-xs" min="0" max="1440" placeholder="0">' +
      '</div></div>' +
      '<button type="button" class="btn-primary evt-create" style="width:100%">Create event</button>' +
      '</div>' +

      // ── View / invite form ──
      '<div id="lvcvoice-evt-view" class="hidden">' +
      '<div class="lvcvoice-mtg-name" id="lvcvoice-evt-view-name"></div>' +
      '<div class="lvcvoice-mtg-urlrow"><input id="lvcvoice-evt-invite-url" class="input font-mono text-xs" readonly>' +
      '<button type="button" class="lvcvoice-btn-ghost evt-copy">Copy</button></div>' +
      '<div style="margin-top:12px">' +
      '<label class="label">Invite by email</label>' +
      '<div class="lvcvoice-mtg-urlrow"><input id="lvcvoice-evt-invite-emails" class="input text-xs" placeholder="alice@example.com, bob@example.com">' +
      '<button type="button" class="btn-primary evt-invite">Send</button></div>' +
      '<div id="lvcvoice-evt-invite-result" class="lvcvoice-mtg-result hidden"></div>' +
      '</div>' +
      '</div>' +

      // ── My events (founder list / cancel / links) ──
      '<div style="margin-top:16px;border-top:1px solid rgba(255,255,255,.06);padding-top:12px">' +
      '<div class="st-label">My events</div>' +
      '<div id="lvcvoice-evt-list"></div>' +
      '</div>' +

      '</div></div>';

    el.addEventListener('click', function (e) { if (e.target === el) closeEventModal(); });
    el.querySelector('.evt-close').addEventListener('click', closeEventModal);
    el.querySelector('.evt-create').addEventListener('click', createEvent);
    el.querySelector('.evt-copy').addEventListener('click', copyEventInviteUrl);
    el.querySelector('.evt-invite').addEventListener('click', sendEventInvites);
    el.querySelector('#lvcvoice-evt-invite-emails').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendEventInvites();
    });

    // Show/hide stream URL row based on event type.
    var typeSelect = el.querySelector('#lvcvoice-evt-type');
    var streamRow = el.querySelector('#lvcvoice-evt-stream-row');
    typeSelect.addEventListener('change', function () {
      streamRow.style.display = typeSelect.value === 'link' ? '' : 'none';
    });

    els.evtForm = el.querySelector('#lvcvoice-evt-form');
    els.evtView = el.querySelector('#lvcvoice-evt-view');
    els.evtViewName = el.querySelector('#lvcvoice-evt-view-name');
    els.evtTitle = el.querySelector('#lvcvoice-evt-title');
    els.evtDesc = el.querySelector('#lvcvoice-evt-desc');
    els.evtType = el.querySelector('#lvcvoice-evt-type');
    els.evtPublic = el.querySelector('#lvcvoice-evt-public');
    els.evtWaiting = el.querySelector('#lvcvoice-evt-waiting');
    els.evtStreamUrl = el.querySelector('#lvcvoice-evt-stream-url');
    els.evtSchedule = el.querySelector('#lvcvoice-evt-schedule');
    els.evtDuration = el.querySelector('#lvcvoice-evt-duration');
    els.evtInviteUrl = el.querySelector('#lvcvoice-evt-invite-url');
    els.evtInviteEmails = el.querySelector('#lvcvoice-evt-invite-emails');
    els.evtInviteResult = el.querySelector('#lvcvoice-evt-invite-result');
    els.evtList = el.querySelector('#lvcvoice-evt-list');
    return el;
  }

  function toggleMute() {
    var room = state.room;
    if (!room) return;
    room.localParticipant.setMicrophoneEnabled(!room.localParticipant.isMicrophoneEnabled());
  }

  /* ── Ring tone (synthesized, like the messenger web) ─────────────────── */
  function ringToneDataUrl() {
    var rate = 22050, dur = 2.0, n = Math.round(dur * rate);
    var buf = new ArrayBuffer(44 + n * 2);
    var dv = new DataView(buf);
    var str = function (o, s) { for (var i = 0; i < s.length; i++) dv.setUint8(o + i, s.charCodeAt(i)); };
    str(0, 'RIFF'); dv.setUint32(4, 36 + n * 2, true); str(8, 'WAVE');
    str(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 1, true); dv.setUint16(22, 1, true);
    dv.setUint32(24, rate, true); dv.setUint32(28, rate * 2, true); dv.setUint16(32, 2, true); dv.setUint16(34, 16, true);
    str(36, 'data'); dv.setUint32(40, n * 2, true);
    for (var i = 0; i < n; i++) {
      var t = i / rate;
      var freq = (Math.floor(t * 2) % 2 === 0) ? 440 : 480;
      var env = (t < 0.05) ? t / 0.05 : ((dur - t) < 0.15) ? (dur - t) / 0.15 : 1.0;
      var v = Math.sin(2 * Math.PI * freq * t) * env * 0.4;
      var val = Math.round(Math.max(-1, Math.min(1, v)) * 32767) & 0xFFFF;
      dv.setInt16(44 + i * 2, val, true);
    }
    var bytes = new Uint8Array(buf);
    var bin = '';
    for (var j = 0; j < bytes.length; j++) bin += String.fromCharCode(bytes[j]);
    return 'data:audio/wav;base64,' + btoa(bin);
  }

  function playRing() {
    try {
      if (!state.ringAudio) {
        state.ringAudio = new Audio(ringToneDataUrl());
        state.ringAudio.loop = true;
      }
      state.ringAudio.currentTime = 0;
      state.ringAudio.play().catch(function () {});
    } catch (e) { /* audio unavailable */ }
  }

  function stopRing() {
    try { if (state.ringAudio) { state.ringAudio.pause(); state.ringAudio.currentTime = 0; } } catch (e) {}
  }

  /* Autoplay policy: an incoming call can arrive at any time, so its first
   * ring playback is NOT gesture-initiated and browsers may block it. Retry
   * on the next click/keypress — by then the page has a user gesture and the
   * ring becomes audible. No-ops while nothing is ringing. */
  function unlockRingAudio() {
    try {
      if (!els.ring || els.ring.classList.contains('hidden')) return;
      playRing();
    } catch (e) { /* audio unavailable */ }
  }
  document.addEventListener('click', unlockRingAudio);
  document.addEventListener('keydown', unlockRingAudio);

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
    if (!els.dropdown) return;
    var dm = currentDm();
    var slug = currentChannel();

    els.dropdown.classList.toggle('hidden', !state.enabled);
    if (!state.enabled) {
      if (els.pill) els.pill.classList.add('hidden');
      if (els.pane) els.pane.classList.add('hidden');
      if (els.ring) els.ring.classList.add('hidden');
      return;
    }

    var dotColor = '#80848e';
    if (state.calls.active || state.inVoice) dotColor = '#22c55e';
    else if (state.calls.outgoing[0]) dotColor = '#f59e0b';
    if (els.ddDot) els.ddDot.style.background = dotColor;

    if (dm) {
      var cl = callLabel();
      els.ddCallItem.querySelector('.lvcvoice-dd-item-label').textContent = cl;
      var ci = els.ddCallItem.querySelector('.lvcvoice-dd-item-icon');
      if (state.calls.active || state.calls.outgoing[0]) {
        ci.style.color = '#ef4444'; ci.innerHTML = ICON_HANGUP;
      } else {
        ci.style.color = '#22c55e'; ci.innerHTML = ICON_CALL;
      }
      if (els.ddIcon) { els.ddIcon.style.color = dotColor; els.ddIcon.innerHTML = state.calls.active ? ICON_HANGUP : ICON_CALL; }
    } else if (slug && state.channels[slug]) {
      var vl = voiceLabel();
      els.ddCallItem.querySelector('.lvcvoice-dd-item-label').textContent = vl;
      var ci2 = els.ddCallItem.querySelector('.lvcvoice-dd-item-icon');
      if (state.inVoice) {
        ci2.style.color = '#ef4444'; ci2.innerHTML = ICON_HANGUP;
      } else {
        ci2.style.color = '#22c55e'; ci2.innerHTML = ICON_CALL;
      }
      if (els.ddIcon) { els.ddIcon.style.color = dotColor; els.ddIcon.innerHTML = state.inVoice ? ICON_HANGUP : ICON_CALL; }
    } else {
      els.ddCallItem.querySelector('.lvcvoice-dd-item-label').textContent = 'Call';
      els.ddCallItem.querySelector('.lvcvoice-dd-item-icon').style.color = '#80848e';
      if (els.ddIcon) { els.ddIcon.style.color = '#80848e'; els.ddIcon.innerHTML = ICON_CALL; }
    }

    if (els.ddEvtItem) els.ddEvtItem.classList.toggle('hidden', !!dm);
    // Video calls are a 1:1 initiation — offer them only next to a chat.
    if (els.ddVideoCallItem) els.ddVideoCallItem.classList.toggle('hidden', !dm || !!state.calls.active || !!state.calls.outgoing[0]);

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
        els.pill.querySelector('.pill-text').textContent = ringText(outgoing, 'Ringing');
        // Keep the caller-side ring tone playing while the call is ringing;
        // it stops here as soon as the outgoing call resolves (accepted,
        // declined, timed out, or ended).
        if (!state.outgoingRing) {
          state.outgoingRing = true;
          playRing();
        }
      } else {
        els.pill.classList.add('hidden');
        if (state.outgoingRing) {
          state.outgoingRing = false;
          stopRing();
        }
      }
    }

    // Voice pane.
    if (els.pane) {
      var inCallLive = !!(state.calls.active && state.inVoice && state.room && state.room.state === 'connected');
      var inRoomVoice = state.inVoice && slug && !dm && !inCallLive;
      var inWaiting = state.waitingRoom && !state.inVoice && slug && !dm;
      els.pane.classList.toggle('hidden', !inRoomVoice && !inWaiting && !inCallLive);
      if (inRoomVoice || inWaiting || inCallLive) {
        els.pane.querySelector('.pane-title').textContent = inCallLive
          ? 'In call with ' + state.calls.active.peer
          : (inWaiting ? 'Voice — waiting room' : 'Voice — #' + slug);
        if (els.paneWait) els.paneWait.classList.toggle('hidden', !inWaiting);
        var room = state.room;
        var muted = room && !room.localParticipant.isMicrophoneEnabled();
        var camOn = room && room.localParticipant.isCameraEnabled();
        var shareOn = room && room.localParticipant.isScreenShareEnabled();
        els.pane.querySelector('.pane-mute').textContent = muted ? 'Unmute' : 'Mute';
        els.pane.querySelector('.pane-mute').classList.toggle('active', !!muted);
        els.pane.querySelector('.pane-deafen').textContent = state.deafened ? 'Undeafen' : 'Deafen';
        els.pane.querySelector('.pane-deafen').classList.toggle('active', !!state.deafened);
        els.pane.querySelector('.pane-cam').textContent = camOn ? 'Camera off' : 'Camera';
        els.pane.querySelector('.pane-share').textContent = shareOn ? 'Stop share' : 'Share';
        els.pane.querySelector('.pane-hand').textContent = state.hands[myIdentity()] ? '✋ Lower' : '✋ Hand';
        var vqBtn = els.pane.querySelector('.pane-vq');
        if (vqBtn) vqBtn.textContent = state.videoQuality || '720p';
        var sess = state.session;
        if (els.paneMod) els.paneMod.classList.toggle('hidden', !(sess && sess.can_moderate));
        var inviteRow = els.pane && els.pane.querySelector('.pane-invite');
        if (inviteRow) inviteRow.classList.toggle('hidden', !(inCallLive && sess && sess.can_moderate && sess.room && sess.room.indexOf('call_') === 0));
        // Record button: hosts only + recording must be enabled; toggles state.
        if (sess && sess.can_moderate && (state.recording || {}).enabled) {
          var recBtn = els.paneMod && els.paneMod.querySelector('.pane-record');
          if (recBtn) {
            var recOn = (state.recording && state.recording.active && state.recording.active.room === sess.room);
            recBtn.classList.toggle('hidden', false);
            recBtn.textContent = recOn ? '⏹ Stop recording' : '⏺ Record';
            recBtn.classList.toggle('recording', !!recOn);
          }
        } else if (els.paneMod) {
          var recBtn2 = els.paneMod && els.paneMod.querySelector('.pane-record');
          if (recBtn2) recBtn2.classList.toggle('hidden', true);
        }
        if (els.paneShareAudioLabel) {
          els.paneShareAudioLabel.classList.toggle('hidden', !state.inVoice || !!shareOn);
        }
        if (els.paneShareAudio) els.paneShareAudio.checked = !!(state.prefs && state.prefs.screenAudio);
        var lockBtn = els.paneMod && els.paneMod.querySelector('.pane-lock');
        if (lockBtn) lockBtn.textContent = (sess && sess.locked) ? 'Unlock room' : 'Lock room';
        els.pane.querySelector('.pane-status').textContent = state.calls.active
          ? 'In a 1:1 call with ' + state.calls.active.peer
          : (inWaiting ? 'The host will let you in shortly.' : 'Connected to the channel voice room.');
        // Roster only for connected participants (not while waiting).
        if (state.room && state.room.state === 'connected') renderRoster();
        else if (els.paneRoster) els.paneRoster.innerHTML = '';
      }
    }

    // Incoming ring overlay (with ring-timeout countdown + missed-call toast).
    var incoming = state.calls.incoming[0] || null;
    if (els.ring) {
      if (incoming && state.enabled && state.ringDismissed !== incoming.call_id) {
        els.ring.classList.remove('hidden');
        els.ring._currentRingId(incoming.call_id);
        els.ring._ringVisible(true);
        els.ring.querySelector('.ring-sub').textContent = incoming.peer + ' is calling… (' + ringRemaining(incoming) + 's)';
        if (!state.ringShown || state.ringShown.call_id !== incoming.call_id) {
          state.ringShown = { call_id: incoming.call_id, peer: incoming.peer };
        }
      } else {
        els.ring.classList.add('hidden');
        els.ring._currentRingId(null);
        els.ring._ringVisible(false);
        // A ring that vanished without accept/decline was missed (server-side
        // timeout); the caller side gets its own "no answer" toast.
        if (state.ringShown && state.ringDismissed !== state.ringShown.call_id) {
          toast('Missed call from ' + state.ringShown.peer + '.');
        }
        state.ringShown = null;
      }
    }

    // Event modal state (refresh if viewing an event channel).
    if (eventModalOpen()) {
      var cur = isEventChannel(slug) ? slug : (state.evt ? state.evt.slug : '');
      if (isEventChannel(slug) && !(state.evt && state.evt.slug === slug)) {
        state.evt = { slug: slug, title: '#' + slug.replace(/^#?/, ''), invite_code: '', invite_url: '' };
      }
      renderEvent(cur);
    }
  }

  function voiceLabel() {
    if (state.connecting) return 'Connecting…';
    if (state.inVoice) return 'In voice — Leave';
    if (state.waitingRoom) return 'In waiting room';
    if (state.full) return 'Voice full (' + state.active + '/' + state.max + ')';
    return 'Voice';
  }

  function callLabel() {
    if (state.calls.active) return 'In call';
    if (state.calls.outgoing[0]) return 'Ringing…';
    return 'Call';
  }

  /* The local participant's LiveKit identity (for roster/hand lookups). */
  function myIdentity() {
    return state.room && state.room.localParticipant ? state.room.localParticipant.identity : '';
  }

  /* Seconds left before the server fails an unanswered call (cosmetic — the
   * server is authoritative via call_ring_seconds). */
  function ringRemaining(call) {
    if (!call) return 0;
    var base = state.ringStarted[call.call_id] || (call.started ? call.started : Date.now());
    if (!state.ringStarted[call.call_id]) state.ringStarted[call.call_id] = base;
    var left = (state.ringSeconds || 20) - Math.floor((Date.now() - base) / 1000);
    return Math.max(0, left);
  }

  function ringText(call, prefix) {
    var t = ringRemaining(call);
    return prefix + ' ' + call.peer + '…' + (t > 0 ? ' (' + t + 's)' : '');
  }

  /* ── Boot ───────────────────────────────────────────────────────────── */
  /* M toggles the mic while in voice (Discord-style shortcut; ignored while
   * typing in a text field). */
  function onKeydown(e) {
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target && e.target.isContentEditable) return;
    if (e.key === 'm' || e.key === 'M') {
      if (state.inVoice && !state.connecting) toggleMute();
    }
  }

  function boot() {
    if (!hostBootGate()) return;
    loadPrefs();
    if (state.prefs.videoQuality) state.videoQuality = state.prefs.videoQuality;
    ensureEls();
    document.addEventListener('keydown', onKeydown);
    pollStatus();
    setInterval(pollStatus, POLL_MS);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

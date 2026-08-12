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
 *   - meetings: #mtg-XXXXXX private keyed rooms (create / invite online users)
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
  var BG_BASE = '/modules/webrtc/assets/vendor/selfie-segmentation/';

  var ICON_CALL = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>';
  var ICON_HANGUP = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transform:rotate(135deg)"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>';

  var state = {
    enabled: false,
    active: 0,
    max: 0,
    full: false,
    talkerCap: 8,
    channels: {},        // slug -> { voice_enabled }
    session: null,       // { room, kind }
    calls: { incoming: [], outgoing: [], active: null, recent: [] },
    room: null,          // livekit Room
    inVoice: false,
    connecting: false,
    pendingJoin: null,
    pendingCall: null,
    pendingCallAt: 0,    // ms when the outgoing call was initiated (race guard)
    mtg: null,           // { slug, name, key, url } for the current meeting
    ringSeconds: 20,     // server ring timeout (call_ring_seconds)
    ringStarted: {},     // call_id -> ms when its ring was first seen
    ringShown: null,     // { call_id, peer } of the current incoming ring
    ringDismissed: null, // call_id the user accepted/declined (not "missed")
    prefs: null,         // { mic, cam, speaker, bg, blur, image }
    devices: { audioinput: [], videoinput: [], audiooutput: [] },
    camTest: null,       // active camera-test stream
    micTest: null,       // active mic-test analyser state
    settingsOpen: false,
    bgProc: null,        // active LiveKit video processor for bg effects
    bgProcKey: '',       // JSON key of the effect the processor was built for
  };

  var els = {};          // { btn, settingsBtn, mtgBtn, pane, ring, pill, videos, toast, st* }
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
      state.calls = j.calls || { incoming: [], outgoing: [], active: null, recent: [] };
      state.recent = state.calls.recent || [];
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20;
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
      applyDevicePrefs();
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

  function setCamera(on) {
    var room = state.room;
    if (!room) return Promise.resolve();
    if (!on) {
      return room.localParticipant.setCameraEnabled(false, { stop: true }).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    }
    var opts = {};
    if (state.prefs && state.prefs.cam) opts.deviceId = state.prefs.cam;
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
      var proc = currentBgProcessor();
      if (proc) opts.videoProcessor = proc;
      return room.localParticipant.setCameraEnabled(true, opts);
    }).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
  }

  function toggleScreenShare() {
    var room = state.room;
    if (!room) return;
    var on = !room.localParticipant.isScreenShareEnabled();
    room.localParticipant.setScreenShareEnabled(on).then(attachLocalVideos).catch(function (e) { toast(String(e && e.message || e)); });
    setTimeout(attachLocalVideos, 400);
  }

  /* ── Device settings, camera/mic test & background effects ──────────── */

  function defaultPrefs() {
    return { mic: '', cam: '', speaker: '', bg: 'none', blur: 8, image: '' };
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
   * chosen input, and route audio output to the chosen speaker. */
  function applyDevicePrefs() {
    var room = state.room;
    if (!room) return;
    var micOpts = {};
    if (state.prefs && state.prefs.mic) micOpts.deviceId = state.prefs.mic;
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

  /* Camera test — plain getUserMedia preview, no LiveKit involved. */
  function startCamTest() {
    if (state.camTest) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast('Camera access is not supported here.');
      return;
    }
    var constraints = { video: true, audio: false };
    if (state.prefs && state.prefs.cam) constraints.video = { deviceId: { exact: state.prefs.cam } };
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
      state.pendingCallAt = Date.now();
      state.ringStarted[j.call_id] = Date.now();
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20;
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
    if (state.room) { try { state.room.disconnect(); } catch (e) {} }
    state.room = null;
    state.inVoice = false;
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
      delete state.ringStarted[cid];
      toast(recentMessage(findRecentCall(cid)));
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
    if (els.dropdown) return;
    var header = document.querySelector('header .relative.ml-auto.flex.items-center.gap-2');
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

    els.ddMtgItem = document.createElement('button');
    els.ddMtgItem.type = 'button';
    els.ddMtgItem.className = 'lvcvoice-dd-item';
    els.ddMtgItem.innerHTML = '<span class="lvcvoice-dd-item-icon">📹</span><span class="lvcvoice-dd-item-label">Meeting</span>';
    els.ddMtgItem.addEventListener('click', function () { closeDropdown(); openMeetingModal(); });
    menu.appendChild(els.ddMtgItem);

    els.ddSettingsItem = document.createElement('button');
    els.ddSettingsItem.type = 'button';
    els.ddSettingsItem.className = 'lvcvoice-dd-item';
    els.ddSettingsItem.innerHTML = '<span class="lvcvoice-dd-item-icon">⚙</span><span class="lvcvoice-dd-item-label">Voice & video settings</span>';
    els.ddSettingsItem.addEventListener('click', function () { closeDropdown(); openSettings(); });
    menu.appendChild(els.ddSettingsItem);

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
    els.mtgModal = buildMtgModal();
    document.body.appendChild(els.mtgModal);
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
      '<button type="button" class="lvcvoice-btn-ghost pane-settings" title="Voice &amp; video settings">⚙</button>' +
      '<button type="button" class="lvcvoice-btn-ghost lvcvoice-btn-danger pane-leave">Leave</button>' +
      '</span></div>' +
      '<div class="pane-body">' +
      '<div id="lvcvoice-videos" class="lvcvoice-videos"></div>' +
      '<div class="pane-row"><span class="pane-meta pane-status">Connected to the voice room.</span></div>' +
      '<div class="pane-controls">' +
      '<button type="button" class="lvcvoice-btn-ghost pane-mute" data-act="mute">Mute</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-cam" data-act="cam">Camera</button>' +
      '<button type="button" class="lvcvoice-btn-ghost pane-share" data-act="share">Share</button>' +
      '</div></div>';
    el.querySelector('.pane-leave').addEventListener('click', leaveVoice);
    el.querySelector('.pane-settings').addEventListener('click', openSettings);
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
      if (currentRingId != null) { state.ringDismissed = currentRingId; acceptCall(currentRingId); }
      el.classList.add('hidden');
    });
    el.querySelector('.ring-decline').addEventListener('click', function () {
      if (currentRingId != null) { state.ringDismissed = currentRingId; declineCall(currentRingId); }
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

    if (els.ddMtgItem) els.ddMtgItem.classList.toggle('hidden', !!dm);

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

    // Incoming ring overlay (with ring-timeout countdown + missed-call toast).
    var incoming = state.calls.incoming[0] || null;
    if (els.ring) {
      if (incoming && state.enabled && state.ringDismissed !== incoming.call_id) {
        els.ring.classList.remove('hidden');
        els.ring.querySelector('.ring-sub').textContent = incoming.peer + ' is calling… (' + ringRemaining(incoming) + 's)';
        els.ring._currentRingId(incoming.call_id);
        if (!state.ringShown || state.ringShown.call_id !== incoming.call_id) {
          state.ringShown = { call_id: incoming.call_id, peer: incoming.peer };
        }
      } else {
        els.ring.classList.add('hidden');
        els.ring._currentRingId(null);
        // A ring that vanished without accept/decline was missed (server-side
        // timeout); the caller side gets its own "no answer" toast.
        if (state.ringShown && state.ringDismissed !== state.ringShown.call_id) {
          toast('Missed call from ' + state.ringShown.peer + '.');
        }
        state.ringShown = null;
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
  function boot() {
    if (!document.body || !document.body.classList.contains('chat-app')) return;
    loadPrefs();
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

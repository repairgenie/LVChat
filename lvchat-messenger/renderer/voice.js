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

/* LVChat Messenger — voice client (shared with messenger-web).
 *
 * Self-contained and gated: nothing is rendered unless the LVChat server says
 * voice is enabled. Every poll asks GET /api/webrtc/voice/status; if the module
 * is disabled on the server (or not installed — 404), the voice/call UI never
 * appears. Uses the vendored livekit-client UMD (window.LivekitClient).
 *
 * This file is loaded AFTER api.js (window.LvApi) and messenger.js, so the
 * current conversation is detected from the DOM (#chat-title / #members-toggle)
 * rather than reaching into messenger.js internals.
 *
 * Features: one-on-one DM calls (20 s ring timeout), channel voice, video
 * (camera + screen share), audio/video device settings + camera/mic test, and
 * background blur / custom image during video (lazy MediaPipe segmentation).
 */
(function () {
  'use strict'
  if (window.LVCMsgVoice) return

  var POLL_MS = 2000
  var PREFS_KEY = 'lvchat.voice.prefs'
  // Self-hosted MediaPipe selfie-segmentation, lazy-loaded (see vendor/).
  var BG_BASE = '/vendor/selfie-segmentation/'

  var state = {
    enabled: false,
    active: 0,
    max: 0,
    full: false,
    talkerCap: 8,
    channels: {},   // slug -> { voice_enabled }
    session: null,  // { room, kind }
    calls: { incoming: [], outgoing: [], active: null, recent: [] },
    room: null,     // livekit Room
    inVoice: false,
    connecting: false,
    pendingJoin: null,
    pendingCall: null,
    pendingCallAt: 0,
    ringSeconds: 20,
    ringStarted: {},
    ringShown: null,
    ringDismissed: null,
    prefs: null,
    devices: { audioinput: [], videoinput: [], audiooutput: [] },
    camTest: null,
    micTest: null,
    settingsOpen: false,
    bgProc: null,
    bgProcKey: ''
  }

  var els = {}
  var tiles = {}   // trackSid -> { el, kind }

  function $ (id) { return document.getElementById(id) }

  /* ── API (LvApi: getJson / postForm, bearer + CSRF) ─────────────────── */
  function api (path, data) {
    if (!window.LvApi) return Promise.resolve({ status: 0, ok: false, body: null })
    return data ? window.LvApi.postForm(path, data) : window.LvApi.getJson(path)
  }

  /* ── Current conversation from the DOM ──────────────────────────────── */
  function conv () {
    if (!els.title) return null
    var title = (els.title.textContent || '').trim()
    if (!title || title === 'LVChat Messenger') return null
    // Room titles render as "#slug"; usernames can never start with '#'.
    if (title.charAt(0) === '#') return { type: 'room', id: title.slice(1) }
    return { type: 'dm', id: title }
  }

  function canManageRoom () {
    // The channel-settings button is only rendered for users who can manage it.
    return !!(els.chanSettings && !els.chanSettings.hidden)
  }

  /* ── Status poll ────────────────────────────────────────────────────── */
  function pollStatus () {
    // Not signed in yet (LvApi has no server origin).
    if (!window.LvApi || !window.LvApi.origin || !window.LvApi.origin()) return
    api('/api/webrtc/voice/status').then(function (r) {
      var j = r && r.body
      if (!j || j.ok !== true) {
        state.enabled = false
        render()
        return
      }
      state.enabled = !!j.enabled
      state.active = j.active || 0
      state.max = j.max || 0
      state.full = !!j.full
      state.talkerCap = j.talker_cap || 8
      state.channels = {}
      ;(j.channels || []).forEach(function (c) { state.channels[c.slug] = c.voice_enabled })
      state.session = j.session || null
      state.calls = j.calls || { incoming: [], outgoing: [], active: null, recent: [] }
      state.recent = state.calls.recent || []
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20
      state.inVoice = !!(state.session && state.room && state.room.state === 'connected')
      render()
      handleCallTransitions()
    }).catch(function () {
      state.enabled = false
      render()
    })
  }

  /* ── LiveKit ────────────────────────────────────────────────────────── */
  function lk () {
    return (typeof window !== 'undefined') && (window.LivekitClient || window.LiveKitClient || window.LiveKit || null)
  }

  function connectLivekit (url, token) {
    if (!lk()) {
      flash('Voice client library is not loaded.')
      return
    }
    if (state.room) { try { state.room.disconnect() } catch (e) {} }
    state.room = null
    clearTiles()
    var Room = lk().Room
    var Event = lk().RoomEvent || {}
    var room = new Room({ adaptiveStream: false })
    state.room = room
    state.connecting = true
    render()

    room.on(Event.ActiveSpeakersChanged, function (speakers) {
      var cap = state.talkerCap || 8
      var order = (speakers || []).map(function (s) { return s.participant })
      room.remoteParticipants.forEach(function (p) {
        // Keep screen-sharers subscribed regardless of speaking; otherwise cap
        // to the loudest talkers.
        var sharing = Array.from(p.trackPublications.values()).some(function (pub) {
          return pub.source === 'screen_share'
        })
        var idx = order.indexOf(p)
        p.setSubscribed(sharing || idx === -1 || idx < cap)
      })
    })
    room.on(Event.TrackSubscribed, function (track) { attachTrack(track) })
    room.on(Event.TrackUnsubscribed, function (track) { detachTrack(track) })
    room.on(Event.TrackMuted, function (track) {
      if (track.kind === 'video' && tiles[track.sid]) tiles[track.sid].el.classList.add('muted')
    })
    room.on(Event.TrackUnmuted, function (track) {
      if (track.kind === 'video' && tiles[track.sid]) tiles[track.sid].el.classList.remove('muted')
    })
    room.on(Event.Disconnected, function () {
      state.room = null
      state.inVoice = false
      state.connecting = false
      clearTiles()
      render()
    })
    room.on(Event.Connected, function () {
      state.connecting = false
      state.inVoice = true
      applyDevicePrefs()
      render()
    })

    room.connect(url, token, {}).then(function () {
      // connected — Connected event handles the rest
    }).catch(function (err) {
      state.room = null
      state.connecting = false
      render()
      flash('Could not connect to voice: ' + (err && err.message || err))
    })
  }

  /* ── Video (camera + screen share) ───────────────────────────────────── */
  function attachTrack (track) {
    if (!track || tiles[track.sid]) return
    if (track.kind === 'audio') {
      var audio = document.createElement('audio')
      audio.autoplay = true
      track.attach(audio)
      document.body.appendChild(audio)
      tiles[track.sid] = { el: audio, kind: 'audio' }
      return
    }
    if (!els.videos) return
    var el = document.createElement('div')
    el.className = 'lvcvoice-tile'
    var video = document.createElement('video')
    video.autoplay = true
    video.playsInline = true
    track.attach(video)
    el.appendChild(video)
    els.videos.appendChild(el)
    tiles[track.sid] = { el: el, kind: 'video' }
  }

  function detachTrack (track) {
    var t = tiles[track.sid]
    if (!t) return
    try { track.detach() } catch (e) {}
    if (t.el && t.el.parentNode) t.el.parentNode.removeChild(t.el)
    delete tiles[track.sid]
  }

  function clearTiles () {
    Object.keys(tiles).forEach(function (sid) {
      try { if (tiles[sid].el && tiles[sid].el.parentNode) tiles[sid].el.parentNode.removeChild(tiles[sid].el) } catch (e) {}
      delete tiles[sid]
    })
    if (els.videos) els.videos.innerHTML = ''
  }

  /* Attach the local participant's own camera / screen tracks (LiveKit only
   * fires TrackSubscribed for remote tracks). */
  function attachLocalVideos () {
    var room = state.room
    if (!room) return
    room.localParticipant.trackPublications.forEach(function (pub) {
      var track = pub.track
      if (track && track.kind === 'video' && !tiles[track.sid]) {
        attachTrack(track)
        var t = tiles[track.sid]
        if (t) {
          t.el.classList.add('self')
          t.el.setAttribute('data-label', pub.source === 'screen_share' ? 'You (screen)' : 'You')
        }
      }
    })
  }

  function setCamera (on) {
    var room = state.room
    if (!room) return Promise.resolve()
    if (!on) {
      return room.localParticipant.setCameraEnabled(false, { stop: true }).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
    }
    var opts = {}
    if (state.prefs && state.prefs.cam) opts.deviceId = state.prefs.cam
    var proc = currentBgProcessor()
    if (proc) opts.videoProcessor = proc
    return room.localParticipant.setCameraEnabled(true, opts).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
  }

  function toggleCamera () {
    var room = state.room
    if (!room) return
    setCamera(!room.localParticipant.isCameraEnabled())
  }

  /* Restart the camera (stop → start) so a changed device or background effect
   * actually takes effect mid-call. Only restarts when the camera is on. */
  function restartCamera () {
    var room = state.room
    if (!room) return Promise.resolve()
    var wasOn = room.localParticipant.isCameraEnabled()
    return room.localParticipant.setCameraEnabled(false, { stop: true }).then(function () {
      if (!wasOn) return
      var opts = {}
      if (state.prefs && state.prefs.cam) opts.deviceId = state.prefs.cam
      var proc = currentBgProcessor()
      if (proc) opts.videoProcessor = proc
      return room.localParticipant.setCameraEnabled(true, opts)
    }).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
  }

  function toggleScreenShare () {
    var room = state.room
    if (!room) return
    var on = !room.localParticipant.isScreenShareEnabled()
    room.localParticipant.setScreenShareEnabled(on).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
    setTimeout(attachLocalVideos, 400)
  }

  /* ── Device settings, camera/mic test & background effects ──────────── */

  function defaultPrefs () {
    return { mic: '', cam: '', speaker: '', bg: 'none', blur: 8, image: '' }
  }

  function loadPrefs () {
    try {
      var p = JSON.parse(localStorage.getItem(PREFS_KEY) || 'null')
      state.prefs = Object.assign(defaultPrefs(), p || {})
    } catch (e) {
      state.prefs = defaultPrefs()
    }
  }

  function savePrefs () {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(state.prefs)) } catch (e) {}
  }

  function applyDevicePrefs () {
    var room = state.room
    if (!room) return
    var micOpts = {}
    if (state.prefs && state.prefs.mic) micOpts.deviceId = state.prefs.mic
    room.localParticipant.setMicrophoneEnabled(true, micOpts).catch(function (e) { flash(String(e && e.message || e)) })
    if (state.prefs && state.prefs.speaker && typeof room.setSinkId === 'function') {
      room.setSinkId(state.prefs.speaker).catch(function () {})
    }
  }

  function escapeHtml (s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
  }

  function refreshDevices () {
    var mm = navigator.mediaDevices
    if (!mm || !mm.enumerateDevices) return Promise.resolve()
    var perm = Promise.resolve()
    if (mm.getUserMedia) {
      perm = mm.getUserMedia({ audio: true, video: true })
        .then(function (s) { s.getTracks().forEach(function (t) { t.stop() }) })
        .catch(function () {})
    }
    return perm.then(function () {
      return mm.enumerateDevices().then(function (list) {
        var devs = { audioinput: [], videoinput: [], audiooutput: [] }
        ;(list || []).forEach(function (d) { if (devs[d.kind]) devs[d.kind].push(d) })
        state.devices = devs
      }).catch(function () {})
    })
  }

  function deviceOptions (list, placeholder) {
    var out = '<option value="">' + escapeHtml(placeholder) + '</option>'
    ;(list || []).forEach(function (d) {
      var label = (d.label || d.kind || 'Device').trim()
      var id = d.deviceId || ''
      out += '<option value="' + escapeHtml(id) + '">' + escapeHtml(label + (id ? ' (' + id.slice(0, 8) + ')' : '')) + '</option>'
    })
    return out
  }

  function populateSettings () {
    if (!els.stMic) return
    els.stMic.innerHTML = deviceOptions(state.devices.audioinput, 'Default microphone')
    els.stCam.innerHTML = deviceOptions(state.devices.videoinput, 'Default camera')
    els.stSpeaker.innerHTML = deviceOptions(state.devices.audiooutput, 'Default speaker')
    if (state.prefs.mic) els.stMic.value = state.prefs.mic
    if (state.prefs.cam) els.stCam.value = state.prefs.cam
    if (state.prefs.speaker) els.stSpeaker.value = state.prefs.speaker
  }

  function renderSettings () {
    if (!els.settings) return
    var bg = state.prefs.bg || 'none'
    els.settings.querySelectorAll('.st-bg').forEach(function (b) {
      b.classList.toggle('on', b.dataset.bg === bg)
    })
    if (els.stBlurRow) els.stBlurRow.classList.toggle('hidden', bg !== 'blur')
    if (els.stImageRow) els.stImageRow.classList.toggle('hidden', bg !== 'image')
    if (els.stBlur) els.stBlur.value = state.prefs.blur || 8
    if (els.stImgPrev) {
      if (state.prefs.image) {
        els.stImgPrev.src = state.prefs.image
        els.stImgPrev.classList.remove('hidden')
      } else {
        els.stImgPrev.src = ''
        els.stImgPrev.classList.add('hidden')
      }
    }
  }

  function openSettings () {
    ensureEls()
    if (!els.settings) return
    els.settings.classList.remove('hidden')
    state.settingsOpen = true
    refreshDevices().then(function () {
      populateSettings()
      renderSettings()
    })
  }

  function closeSettings () {
    if (els.settings) els.settings.classList.add('hidden')
    state.settingsOpen = false
    stopCamTest()
    stopMicTest()
  }

  function saveSettings () {
    var mic = els.stMic.value
    var cam = els.stCam.value
    var speaker = els.stSpeaker.value
    state.prefs.mic = mic
    state.prefs.cam = cam
    state.prefs.speaker = speaker
    savePrefs()
    var room = state.room
    if (room) {
      if (mic) {
        try { room.localParticipant.setMicrophoneEnabled(true, { deviceId: mic }) } catch (e) {}
      }
      if (room.localParticipant.isCameraEnabled()) restartCamera()
    }
    closeSettings()
    flash('Voice settings saved.')
  }

  function startCamTest () {
    if (state.camTest) return
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      flash('Camera access is not supported here.')
      return
    }
    var constraints = { video: true, audio: false }
    if (state.prefs && state.prefs.cam) constraints.video = { deviceId: { exact: state.prefs.cam } }
    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      state.camTest = stream
      if (els.stCamTest) els.stCamTest.classList.remove('hidden')
      if (els.stCamVideo) {
        els.stCamVideo.srcObject = stream
        els.stCamVideo.play().catch(function () {})
      }
    }).catch(function (e) {
      flash('Could not start camera: ' + (e && e.message || e))
    })
  }

  function stopCamTest () {
    if (state.camTest) {
      try { state.camTest.getTracks().forEach(function (t) { t.stop() }) } catch (e) {}
    }
    state.camTest = null
    if (els.stCamVideo) els.stCamVideo.srcObject = null
    if (els.stCamTest) els.stCamTest.classList.add('hidden')
  }

  function startMicTest () {
    if (state.micTest) return
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      flash('Microphone access is not supported here.')
      return
    }
    var constraints = { audio: true, video: false }
    if (state.prefs && state.prefs.mic) constraints.audio = { deviceId: { exact: state.prefs.mic } }
    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      var AC = window.AudioContext || window.webkitAudioContext
      if (!AC) {
        stream.getTracks().forEach(function (t) { t.stop() })
        flash('Audio analysis is not supported here.')
        return
      }
      var actx = new AC()
      var analyser = actx.createAnalyser()
      analyser.fftSize = 512
      actx.createMediaStreamSource(stream).connect(analyser)
      var buf = new Uint8Array(analyser.frequencyBinCount)
      state.micTest = { actx: actx, stream: stream }
      if (els.stMeter) els.stMeter.value = 0
      var loop = function () {
        if (!state.micTest) return
        analyser.getByteTimeDomainData(buf)
        var sum = 0
        for (var i = 0; i < buf.length; i++) {
          var v = (buf[i] - 128) / 128
          sum += v * v
        }
        var pct = Math.min(100, Math.round(Math.sqrt(sum / buf.length) * 220))
        if (els.stMeter) els.stMeter.value = pct
        if (els.stMeterVal) els.stMeterVal.textContent = pct + '%'
        state.micTest.raf = requestAnimationFrame(loop)
      }
      loop()
      flash('Mic test running — speak to see the level.')
    }).catch(function (e) {
      flash('Could not start mic test: ' + (e && e.message || e))
    })
  }

  function stopMicTest () {
    if (!state.micTest) return
    cancelAnimationFrame(state.micTest.raf)
    try { state.micTest.actx.close() } catch (e) {}
    try { state.micTest.stream.getTracks().forEach(function (t) { t.stop() }) } catch (e) {}
    state.micTest = null
    if (els.stMeter) els.stMeter.value = 0
    if (els.stMeterVal) els.stMeterVal.textContent = ''
  }

  /* Background effects: a LiveKit TrackProcessor that composites the person
   * (MediaPipe selfie segmentation) over a blurred or custom-image background.
   * MediaPipe is lazy-loaded and only runs while an effect is active. */
  function currentBgEffect () {
    if (!state.prefs || !state.prefs.bg || state.prefs.bg === 'none') return null
    return { mode: state.prefs.bg, blur: Number(state.prefs.blur) || 8, image: state.prefs.image || '' }
  }

  function currentBgProcessor () {
    var eff = currentBgEffect()
    if (!eff) {
      if (state.bgProc) { try { state.bgProc.destroy() } catch (e) {} }
      state.bgProc = null
      state.bgProcKey = ''
      return null
    }
    var key = JSON.stringify(eff)
    if (state.bgProc && state.bgProcKey === key) return state.bgProc
    if (state.bgProc) { try { state.bgProc.destroy() } catch (e) {} }
    state.bgProcKey = key
    try { state.bgProc = makeBgProcessor(eff) } catch (e) { state.bgProc = null }
    return state.bgProc
  }

  function makeBgProcessor (effect) {
    var canvas = document.createElement('canvas')
    var ctx = canvas.getContext('2d')
    var video = document.createElement('video')
    video.autoplay = true
    video.playsInline = true
    video.muted = true
    var maskCanvas = document.createElement('canvas')
    var maskCtx = maskCanvas.getContext('2d')
    var bgCanvas = document.createElement('canvas')
    var bgCtx = bgCanvas.getContext('2d')
    var personCanvas = document.createElement('canvas')
    var personCtx = personCanvas.getContext('2d')
    var segFrame = document.createElement('canvas')
    var seg = null
    var stream = null
    var outTrack = null
    var raf = 0
    var running = false
    var outW = 640, outH = 360, segW = 320, segH = 180
    var bgImg = new Image()
    var bgImageOk = false

    function locateFile (file) {
      // Ship only the SIMD wasm variant; map the non-SIMD name onto it.
      if (file.indexOf('simd') === -1 && file.indexOf('_solution_wasm_bin') !== -1) {
        file = file.replace('selfie_segmentation_solution_wasm_bin', 'selfie_segmentation_solution_simd_wasm_bin')
      }
      return BG_BASE + file
    }

    function loadLib () {
      if (window.SelfieSegmentation) return Promise.resolve(window.SelfieSegmentation)
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script')
        s.src = BG_BASE + 'selfie_segmentation.js'
        s.onload = function () {
          if (window.SelfieSegmentation) resolve(window.SelfieSegmentation)
          else reject(new Error('background library failed to load'))
        }
        s.onerror = function () { reject(new Error('could not load the background library')) }
        document.head.appendChild(s)
      })
    }

    function init () {
      if (effect.mode === 'image' && effect.image) {
        bgImg.onload = function () { bgImageOk = true }
        bgImg.onerror = function () { bgImageOk = false }
        bgImg.src = effect.image
      }
      return loadLib().then(function (SS) {
        seg = new SS({ locateFile: locateFile })
        seg.setOptions({ modelSelection: 0, selfieMode: false })
        return seg.initialize()
      })
    }

    function drawBackground () {
      var w = bgCanvas.width, h = bgCanvas.height
      if (effect.mode === 'image' && bgImageOk) {
        var iw = bgImg.naturalWidth || 1, ih = bgImg.naturalHeight || 1
        var scale = Math.max(w / iw, h / ih)
        bgCtx.drawImage(bgImg, (w - iw * scale) / 2, (h - ih * scale) / 2, iw * scale, ih * scale)
      } else if (effect.mode === 'blur') {
        bgCtx.filter = 'blur(' + (Number(effect.blur) || 8) + 'px)'
        bgCtx.drawImage(video, 0, 0, w, h)
        bgCtx.filter = 'none'
      } else {
        bgCtx.drawImage(video, 0, 0, w, h)
      }
    }

    /* Turn the segmentation mask into an alpha cutout on maskCanvas. */
    function maskToAlpha (res) {
      var mask = res.segmentationMask || res.mask || null
      if (!mask) return false
      var mw = 0, mh = 0
      if (mask.data) { // ImageData
        mw = mask.width; mh = mask.height
        var d = mask.data
        for (var i = 0; i < d.length; i += 4) {
          d[i + 3] = d[i]; d[i] = d[i + 1] = d[i + 2] = 0
        }
        maskCanvas.width = mw; maskCanvas.height = mh
        maskCtx.putImageData(mask, 0, 0)
      } else { // ImageBitmap
        mw = mask.width || mask.videoWidth || 0
        mh = mask.height || mask.videoHeight || 0
        if (!mw) return false
        maskCanvas.width = mw; maskCanvas.height = mh
        maskCtx.drawImage(mask, 0, 0)
        var id = maskCtx.getImageData(0, 0, mw, mh)
        for (var j = 0; j < id.data.length; j += 4) {
          id.data[j + 3] = id.data[j]; id.data[j] = id.data[j + 1] = id.data[j + 2] = 0
        }
        maskCtx.putImageData(id, 0, 0)
      }
      return mw > 0
    }

    function composite (res) {
      if (!maskToAlpha(res)) return
      personCtx.clearRect(0, 0, outW, outH)
      personCtx.drawImage(video, 0, 0, outW, outH)
      personCtx.save()
      personCtx.globalCompositeOperation = 'destination-in'
      personCtx.imageSmoothingEnabled = true
      personCtx.drawImage(maskCanvas, 0, 0, outW, outH)
      personCtx.restore()
      drawBackground()
      ctx.clearRect(0, 0, outW, outH)
      ctx.drawImage(bgCanvas, 0, 0)
      ctx.drawImage(personCanvas, 0, 0)
    }

    function drawLoop () {
      raf = requestAnimationFrame(drawLoop)
      if (!running || !seg) return
      segFrame.width = segW
      segFrame.height = segH
      segFrame.getContext('2d').drawImage(video, 0, 0, segW, segH)
      seg.send({ image: segFrame }).then(function (res) {
        if (running) composite(res)
      }).catch(function () { /* skip a frame */ })
    }

    function sizeFor (track) {
      var s = {}
      try { s = track && track.getSettings ? track.getSettings() : {} } catch (e) {}
      outW = Math.min(1280, Math.max(320, Number(s.width) || 640))
      outH = Math.min(720, Math.max(180, Math.round(outW * (Number(s.height) || 360) / (Number(s.width) || 640))))
      if (outH % 2) outH++
      segW = Math.max(320, Math.round(outW / 2))
      if (segW % 2) segW++
      segH = Math.max(180, Math.round(outH / 2))
      if (segH % 2) segH++
      canvas.width = outW; canvas.height = outH
      bgCanvas.width = outW; bgCanvas.height = outH
      personCanvas.width = outW; personCanvas.height = outH
    }

    return {
      name: 'bg-effect',
      processSource: function (track) {
        sizeFor(track)
        video.srcObject = new MediaStream([track])
        stream = canvas.captureStream(15)
        outTrack = stream.getVideoTracks()[0]
        init().then(function () {
          running = true
          drawLoop()
        }).catch(function (err) {
          flash('Background effect unavailable: ' + (err && err.message || err))
        })
        return Promise.resolve(outTrack)
      },
      updateSource: function (track) {
        if (track) {
          video.srcObject = new MediaStream([track])
          sizeFor(track)
        }
      },
      destroy: function () {
        running = false
        cancelAnimationFrame(raf)
        if (seg) { try { seg.close() } catch (e) {} seg = null }
        if (stream) { try { stream.getTracks().forEach(function (t) { t.stop() }) } catch (e) {} }
        if (outTrack) { try { outTrack.stop() } catch (e) {} }
        if (video.srcObject) {
          try { video.srcObject.getTracks().forEach(function (t) { t.stop() }) } catch (e) {}
        }
        video.srcObject = null
      }
    }
  }

  /* Settings modal DOM. */
  function buildSettings () {
    var el = document.createElement('div')
    el.id = 'lvcvoice-settings'
    el.className = 'lvcvoice-settings-overlay'
    el.hidden = true
    el.innerHTML =
      '<div class="lvcvoice-settings-card">' +
      '<div class="lvcvoice-mtg-head"><span>Voice &amp; video settings</span>' +
      '<button type="button" class="ghost small st-close">✕</button></div>' +
      '<div class="lvcvoice-settings-body">' +

      '<div class="st-section"><div class="st-label">Audio &amp; video devices</div>' +
      '<label class="st-field">Microphone<select id="lvcvoice-st-mic" class="st-select"></select></label>' +
      '<label class="st-field">Camera<select id="lvcvoice-st-cam" class="st-select"></select></label>' +
      '<label class="st-field">Speaker<select id="lvcvoice-st-speaker" class="st-select"></select></label>' +
      '<button type="button" class="ghost small st-testcam" style="width:100%">Test camera</button>' +
      '</div>' +

      '<div class="st-section st-camtest" id="lvcvoice-st-camtest" hidden>' +
      '<div class="st-label">Camera preview</div>' +
      '<video id="lvcvoice-st-camvideo" class="st-camvideo" autoplay playsinline muted></video>' +
      '<div class="st-row"><button type="button" class="ghost small st-camstart">Start</button>' +
      '<button type="button" class="ghost small st-camstop">Stop</button></div>' +
      '</div>' +

      '<div class="st-section"><div class="st-label">Microphone test ' +
      '<span class="st-meter-wrap"><meter id="lvcvoice-st-meter" min="0" max="100" low="10" high="70"></meter>' +
      '<span class="st-meter-val"></span></span></div>' +
      '<button type="button" class="ghost small st-mictest" style="width:100%">Test microphone</button>' +
      '</div>' +

      '<div class="st-section"><div class="st-label">Video background</div>' +
      '<div class="st-row">' +
      '<button type="button" class="ghost small st-bg" data-bg="none">None</button>' +
      '<button type="button" class="ghost small st-bg" data-bg="blur">Blur</button>' +
      '<button type="button" class="ghost small st-bg" data-bg="image">Image</button>' +
      '</div>' +
      '<div class="st-blur" hidden><label class="st-field">Blur strength ' +
      '<input id="lvcvoice-st-blur" type="range" min="2" max="20" step="1"></label></div>' +
      '<div class="st-image" hidden>' +
      '<input id="lvcvoice-st-imgfile" type="file" accept="image/png,image/jpeg,image/webp">' +
      '<div class="st-row"><button type="button" class="ghost small st-imgremove">Remove image</button></div>' +
      '<img id="lvcvoice-st-imgprev" class="st-imgprev" alt="Background preview" hidden>' +
      '</div>' +
      '<p class="st-hint">Background effects only apply while your camera is on, and are processed locally in your browser.</p>' +
      '</div>' +

      '<div class="st-row st-actions">' +
      '<button type="button" class="primary small st-save">Save settings</button>' +
      '<button type="button" class="ghost small st-cancel">Cancel</button>' +
      '</div>' +
      '</div></div>'

    el.querySelector('.st-close').addEventListener('click', closeSettings)
    el.querySelector('.st-cancel').addEventListener('click', closeSettings)
    el.addEventListener('click', function (e) { if (e.target === el) closeSettings() })
    el.querySelector('.st-testcam').addEventListener('click', function () { if (state.camTest) stopCamTest(); else startCamTest() })
    el.querySelector('.st-camstart').addEventListener('click', startCamTest)
    el.querySelector('.st-camstop').addEventListener('click', stopCamTest)
    el.querySelector('.st-mictest').addEventListener('click', function () { if (state.micTest) stopMicTest(); else startMicTest() })
    el.querySelector('.st-save').addEventListener('click', saveSettings)
    el.querySelectorAll('.st-bg').forEach(function (b) {
      b.addEventListener('click', function () {
        state.prefs.bg = b.dataset.bg
        renderSettings()
      })
    })
    el.querySelector('#lvcvoice-st-blur').addEventListener('input', function (e) {
      state.prefs.blur = Number(e.target.value)
    })
    el.querySelector('#lvcvoice-st-imgfile').addEventListener('change', onBgImageFile)
    el.querySelector('.st-imgremove').addEventListener('click', removeBgImage)

    document.body.appendChild(el)
    els.settings = el
    els.stMic = el.querySelector('#lvcvoice-st-mic')
    els.stCam = el.querySelector('#lvcvoice-st-cam')
    els.stSpeaker = el.querySelector('#lvcvoice-st-speaker')
    els.stCamTest = el.querySelector('#lvcvoice-st-camtest')
    els.stCamVideo = el.querySelector('#lvcvoice-st-camvideo')
    els.stMeter = el.querySelector('#lvcvoice-st-meter')
    els.stMeterVal = el.querySelector('.st-meter-val')
    els.stBlur = el.querySelector('#lvcvoice-st-blur')
    els.stBlurRow = el.querySelector('.st-blur')
    els.stImageRow = el.querySelector('.st-image')
    els.stImgPrev = el.querySelector('#lvcvoice-st-imgprev')
    els.stImgFile = el.querySelector('#lvcvoice-st-imgfile')
  }

  function onBgImageFile (e) {
    var file = e.target.files && e.target.files[0]
    if (!file) return
    if (!/^image\/(png|jpe?g|webp)/i.test(file.type || '')) {
      flash('Please choose a PNG, JPEG or WebP image.')
      return
    }
    var reader = new FileReader()
    reader.onload = function () {
      var img = new Image()
      img.onload = function () {
        var w = img.width, h = img.height
        var scale = Math.min(1, Math.min(1280 / w, 720 / h))
        if (scale < 1) { w = Math.round(w * scale); h = Math.round(h * scale) }
        var c = document.createElement('canvas')
        c.width = w; c.height = h
        c.getContext('2d').drawImage(img, 0, 0, w, h)
        var url = c.toDataURL('image/jpeg', 0.85)
        if (url.length > 400000) { flash('Image too large — please pick a smaller one.'); return }
        state.prefs.image = url
        state.prefs.bg = 'image'
        renderSettings()
      }
      img.onerror = function () { flash('Could not read that image.') }
      img.src = String(reader.result)
    }
    reader.readAsDataURL(file)
  }

  function removeBgImage () {
    state.prefs.image = ''
    if (els.stImgPrev) { els.stImgPrev.src = ''; els.stImgPrev.hidden = true }
    if (els.stImgFile) els.stImgFile.value = ''
    renderSettings()
  }

  /* ── Actions ────────────────────────────────────────────────────────── */
  function toggleVoice () {
    var c = conv()
    if (!c || c.type !== 'room' || state.connecting) return
    if (state.inVoice) { leaveVoice(); return }
    if (state.full) { flash(state.calls ? 'Voice is full (' + state.active + '/' + state.max + ').' : 'Voice is full.'); return }
    state.pendingJoin = { channel: c.id }
    render()
    api('/api/webrtc/voice/join', { channel: c.id }).then(function (r) {
      state.pendingJoin = null
      var j = r && r.body
      if (r.ok && j && j.ok) {
        connectLivekit(j.url, j.token)
      } else {
        render()
        flash((j && j.error) || 'Could not join voice.')
      }
    })
  }

  function leaveVoice () {
    if (state.room) { try { state.room.disconnect() } catch (e) {} }
    state.room = null
    state.inVoice = false
    state.pendingJoin = null
    clearTiles()
    api('/api/webrtc/voice/leave', {}).then(render)
    render()
  }

  function toggleChannelVoice () {
    var c = conv()
    if (!c || c.type !== 'room') return
    var want = !(state.channels[c.id] === true)
    api('/api/webrtc/voice/channel-voice', { channel: c.id, enabled: want ? '1' : '0' }).then(function (r) {
      var j = r && r.body
      if (r.ok && j && j.ok) { state.channels[c.id] = want }
      else flash((j && j.error) || 'Could not toggle voice.')
      render()
    })
  }

  function startCall () {
    var c = conv()
    if (!c || c.type !== 'dm') return
    api('/api/webrtc/call/initiate', { user: c.id }).then(function (r) {
      var j = r && r.body
      if (!r.ok || !j || !j.ok) { flash((j && j.error) || 'Could not start the call.'); return }
      state.pendingCall = j.call_id
      state.pendingCallAt = Date.now()
      state.ringStarted[j.call_id] = Date.now()
      state.ringSeconds = j.ring_seconds || state.ringSeconds || 20
      render()
    })
  }

  function acceptCall (callId) {
    api('/api/webrtc/call/accept', { call_id: String(callId) }).then(function (r) {
      var j = r && r.body
      if (r.ok && j && j.ok) {
        state.pendingCall = null
        connectLivekit(j.url, j.token)
      } else {
        flash((j && j.error) || 'Could not accept the call.')
      }
      render()
    })
  }

  function declineCall (callId) {
    api('/api/webrtc/call/decline', { call_id: String(callId) }).then(render)
    render()
  }

  function endCall () {
    // Also cancel a still-ringing outgoing call — the pill's End button must
    // end the ring server-side, not just disconnect locally.
    var call = state.calls.active || state.calls.outgoing[0] || null
    if (call) api('/api/webrtc/call/end', { call_id: String(call.call_id) })
    state.pendingCall = null
    if (state.room) { try { state.room.disconnect() } catch (e) {} }
    state.room = null
    state.inVoice = false
    clearTiles()
    render()
  }

  function findRecentCall (callId) {
    for (var i = 0; i < state.recent.length; i++) {
      if (state.recent[i].call_id === callId) return state.recent[i]
    }
    return null
  }

  function recentMessage (done) {
    if (done) {
      if (done.status === 'declined') return done.peer + ' declined the call.'
      if (done.status === 'missed') return done.peer + " didn't answer — call missed."
      if (done.status === 'cancelled') return 'You cancelled the call.'
      if (done.status === 'ended') return 'Call ended.'
    }
    return 'Call ended — no answer.'
  }

  /* When an outgoing call flips to active, the caller connects via call/join. */
  function handleCallTransitions () {
    var active = state.calls.active
    if (active) state.pendingCall = null
    if (active && state.room && state.room.state === 'connected') return
    if (active && !state.room && !state.connecting) {
      api('/api/webrtc/call/join', { call_id: String(active.call_id) }).then(function (r) {
        var j = r && r.body
        if (r.ok && j && j.ok) connectLivekit(j.url, j.token)
      })
    }
    // Prune ring timers that are no longer relevant (accepted / ended / expired).
    var liveIds = []
    if (active) liveIds.push(active.call_id)
    state.calls.outgoing.forEach(function (c) { liveIds.push(c.call_id) })
    state.calls.incoming.forEach(function (c) { liveIds.push(c.call_id) })
    Object.keys(state.ringStarted).forEach(function (cid) {
      if (liveIds.indexOf(Number(cid)) === -1) delete state.ringStarted[cid]
    })
    // An outgoing ring vanished without connecting → declined / missed / cancelled.
    // The pendingCallAt grace window absorbs a stale poll snapshot that predates
    // the call row appearing, so we don't flash "no answer" by accident.
    if (state.pendingCall && !active && !state.calls.outgoing[0] && Date.now() - state.pendingCallAt > POLL_MS * 1.5) {
      var cid = state.pendingCall
      state.pendingCall = null
      delete state.ringStarted[cid]
      flash(recentMessage(findRecentCall(cid)))
    }
  }

  /* ── DOM ────────────────────────────────────────────────────────────── */
  function ensureEls () {
    if (els.btn) return
    els.title = $('chat-title')
    els.chanSettings = $('chan-settings-btn')
    var actions = document.querySelector('.chat-head-actions')
    if (!actions) return

    var btn = document.createElement('button')
    btn.id = 'lvcvoice-btn'
    btn.type = 'button'
    btn.className = 'ghost small'
    btn.addEventListener('click', onMainClick)
    actions.insertBefore(btn, actions.firstChild)
    els.btn = btn

    var settingsBtn = document.createElement('button')
    settingsBtn.id = 'lvcvoice-settings-btn'
    settingsBtn.type = 'button'
    settingsBtn.className = 'ghost small'
    settingsBtn.textContent = '⚙ Settings'
    settingsBtn.title = 'Voice & video settings'
    settingsBtn.addEventListener('click', openSettings)
    actions.appendChild(settingsBtn)
    els.settingsBtn = settingsBtn

    var pill = document.createElement('span')
    pill.id = 'lvcvoice-pill'
    pill.className = 'lvcvoice-pill'
    pill.style.display = 'none'
    pill.innerHTML = '<span class="lvcvoice-pill-dot"></span><span class="lvcvoice-pill-text"></span>' +
      '<button type="button" class="ghost small lvcvoice-pill-end">End</button>'
    pill.querySelector('.lvcvoice-pill-end').addEventListener('click', endCall)
    actions.insertBefore(pill, btn)
    els.pill = pill

    document.body.appendChild(buildPane())
    document.body.appendChild(buildRing())
    buildSettings()
  }

  function buildPane () {
    var el = document.createElement('div')
    el.id = 'lvcvoice-pane'
    el.className = 'lvcvoice-pane'
    el.hidden = true
    el.innerHTML =
      '<div class="lvcvoice-pane-head"><span class="lvcvoice-pane-title">Voice</span>' +
      '<span class="lvcvoice-pane-head-actions">' +
      '<button type="button" class="ghost small lvcvoice-pane-settings" title="Voice &amp; video settings">⚙</button>' +
      '<button type="button" class="ghost small lvcvoice-pane-leave">Leave</button>' +
      '</span></div>' +
      '<div class="lvcvoice-pane-body">' +
      '<div id="lvcvoice-videos" class="lvcvoice-videos"></div>' +
      '<div class="lvcvoice-pane-row lvcvoice-pane-meta">Connected to the voice room.</div>' +
      '<div class="lvcvoice-controls">' +
      '<button type="button" class="ghost small lvcvoice-pane-mute">Mute</button>' +
      '<button type="button" class="ghost small lvcvoice-pane-cam">Camera</button>' +
      '<button type="button" class="ghost small lvcvoice-pane-share">Share</button>' +
      '</div>' +
      '</div>'
    el.querySelector('.lvcvoice-pane-leave').addEventListener('click', leaveVoice)
    el.querySelector('.lvcvoice-pane-settings').addEventListener('click', openSettings)
    el.querySelector('.lvcvoice-pane-mute').addEventListener('click', toggleMute)
    el.querySelector('.lvcvoice-pane-cam').addEventListener('click', toggleCamera)
    el.querySelector('.lvcvoice-pane-share').addEventListener('click', toggleScreenShare)
    els.videos = el.querySelector('#lvcvoice-videos')
    return el
  }

  function buildRing () {
    var el = document.createElement('div')
    el.id = 'lvcvoice-ring'
    el.className = 'lvcvoice-ring'
    el.hidden = true
    el.innerHTML =
      '<div class="lvcvoice-ring-title">Incoming voice call</div>' +
      '<div class="lvcvoice-ring-peer"></div>' +
      '<div class="lvcvoice-ring-actions">' +
      '<button type="button" class="primary small lvcvoice-ring-accept">Accept</button>' +
      '<button type="button" class="ghost small lvcvoice-ring-decline">Decline</button>' +
      '</div>'
    var currentRingId = null
    el.querySelector('.lvcvoice-ring-accept').addEventListener('click', function () {
      if (currentRingId != null) { state.ringDismissed = currentRingId; acceptCall(currentRingId) }
      el.hidden = true
    })
    el.querySelector('.lvcvoice-ring-decline').addEventListener('click', function () {
      if (currentRingId != null) { state.ringDismissed = currentRingId; declineCall(currentRingId) }
      el.hidden = true
    })
    el._ringId = function (v) { if (arguments.length) currentRingId = v; return currentRingId }
    return el
  }

  function toggleMute () {
    var room = state.room
    if (!room) return
    room.localParticipant.setMicrophoneEnabled(!room.localParticipant.isMicrophoneEnabled())
  }

  function onMainClick () {
    var c = conv()
    if (!c) return
    if (c.type === 'dm') { startCall(); return }
    toggleVoice()
  }

  function flash (msg) {
    var el = $('lvcvoice-toast')
    if (!el) {
      el = document.createElement('div')
      el.id = 'lvcvoice-toast'
      el.className = 'lvcvoice-toast'
      document.body.appendChild(el)
    }
    el.textContent = msg
    el.hidden = false
    clearTimeout(el._t)
    el._t = setTimeout(function () { el.hidden = true }, 4000)
  }

  function render () {
    ensureEls()
    if (!els.btn) return
    var c = conv()

    var inRoom = c && c.type === 'room'
    var inDm = c && c.type === 'dm'

    if (!state.enabled) {
      els.btn.hidden = true
      if (els.settingsBtn) els.settingsBtn.hidden = true
      hidePill()
      hidePane()
      hideRing()
      return
    }

    if (els.settingsBtn) els.settingsBtn.hidden = false

    if (inDm) {
      els.btn.hidden = false
      els.btn.textContent = callLabel()
      els.btn.className = 'ghost small' + (state.calls.active ? ' lvcvoice-in-call' : '')
    } else if (inRoom) {
      var voiceOn = state.channels[c.id] === true
      if (voiceOn) {
        els.btn.hidden = false
        els.btn.textContent = voiceLabel()
        els.btn.className = 'ghost small' + (state.inVoice ? ' lvcvoice-in-voice' : '')
      } else if (canManageRoom()) {
        els.btn.hidden = false
        els.btn.textContent = 'Enable voice'
        els.btn.onclick = toggleChannelVoice
      } else {
        els.btn.hidden = true
      }
    } else {
      els.btn.hidden = true
    }

    // Call pill (with ring-timeout countdown on outgoing).
    var call = state.calls.active
    var outgoing = state.calls.outgoing[0] || null
    if (els.pill) {
      if (call) {
        showPill('active', 'In call with ' + call.peer)
      } else if (outgoing) {
        showPill('ringing', ringText(outgoing, 'Ringing'))
      } else {
        hidePill()
      }
    }

    // Voice pane.
    if (els.pane) {
      if (state.inVoice && inRoom) {
        els.pane.hidden = false
        els.pane.querySelector('.lvcvoice-pane-title').textContent = 'Voice — #' + c.id
        var room = state.room
        var muted = room && !room.localParticipant.isMicrophoneEnabled()
        var camOn = room && room.localParticipant.isCameraEnabled()
        var shareOn = room && room.localParticipant.isScreenShareEnabled()
        els.pane.querySelector('.lvcvoice-pane-mute').textContent = muted ? 'Unmute' : 'Mute'
        els.pane.querySelector('.lvcvoice-pane-cam').textContent = camOn ? 'Camera off' : 'Camera'
        els.pane.querySelector('.lvcvoice-pane-share').textContent = shareOn ? 'Stop share' : 'Share'
      } else {
        els.pane.hidden = true
      }
    }

    // Incoming ring (countdown + missed-call toast).
    var incoming = state.calls.incoming[0] || null
    if (els.ring) {
      if (incoming && state.ringDismissed !== incoming.call_id) {
        els.ring.hidden = false
        els.ring.querySelector('.lvcvoice-ring-peer').textContent = incoming.peer + ' is calling… (' + ringRemaining(incoming) + 's)'
        els.ring._ringId(incoming.call_id)
        if (!state.ringShown || state.ringShown.call_id !== incoming.call_id) {
          state.ringShown = { call_id: incoming.call_id, peer: incoming.peer }
        }
      } else {
        els.ring.hidden = true
        els.ring._ringId(null)
        if (state.ringShown && state.ringDismissed !== state.ringShown.call_id) {
          flash('Missed call from ' + state.ringShown.peer + '.')
        }
        state.ringShown = null
      }
    }
  }

  function showPill (cls, text) {
    els.pill.classList.remove('ringing', 'active')
    if (cls) els.pill.classList.add(cls)
    els.pill.style.display = 'inline-flex'
    els.pill.querySelector('.lvcvoice-pill-text').textContent = text
  }
  function hidePill () { if (els.pill) els.pill.style.display = 'none' }
  function hidePane () { if (els.pane) els.pane.hidden = true }
  function hideRing () { if (els.ring) { els.ring.hidden = true; els.ring._ringId(null) } }

  function voiceLabel () {
    if (state.connecting) return 'Connecting…'
    if (state.inVoice) return 'Leave voice'
    if (state.full) return 'Voice full (' + state.active + '/' + state.max + ')'
    return 'Voice'
  }

  function callLabel () {
    if (state.calls.active) return 'End call'
    if (state.calls.outgoing[0]) return 'Ringing…'
    return 'Call'
  }

  /* Seconds left before the server fails an unanswered call (cosmetic — the
   * server is authoritative via call_ring_seconds). */
  function ringRemaining (call) {
    if (!call) return 0
    var base = state.ringStarted[call.call_id] || (call.started ? call.started : Date.now())
    if (!state.ringStarted[call.call_id]) state.ringStarted[call.call_id] = base
    var left = (state.ringSeconds || 20) - Math.floor((Date.now() - base) / 1000)
    return Math.max(0, left)
  }

  function ringText (call, prefix) {
    var t = ringRemaining(call)
    return prefix + ' ' + call.peer + '…' + (t > 0 ? ' (' + t + 's)' : '')
  }

  /* ── Boot ───────────────────────────────────────────────────────────── */
  function boot () {
    loadPrefs()
    ensureEls()
    pollStatus()
    setInterval(pollStatus, POLL_MS)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot)
  } else {
    boot()
  }
})()

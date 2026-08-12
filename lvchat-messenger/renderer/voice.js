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
 */
(function () {
  'use strict'
  if (window.LVCMsgVoice) return

  var POLL_MS = 2000

  var state = {
    enabled: false,
    active: 0,
    max: 0,
    full: false,
    talkerCap: 8,
    channels: {},   // slug -> { voice_enabled }
    session: null,  // { room, kind }
    calls: { incoming: [], outgoing: [], active: null },
    room: null,     // livekit Room
    inVoice: false,
    connecting: false,
    pendingCall: null
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
      state.calls = j.calls || { incoming: [], outgoing: [], active: null }
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
      room.localParticipant.setMicrophoneEnabled(true)
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

  function toggleCamera () {
    var room = state.room
    if (!room) return
    var on = !room.localParticipant.isCameraEnabled()
    room.localParticipant.setCameraEnabled(on).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
    setTimeout(attachLocalVideos, 400)
  }

  function toggleScreenShare () {
    var room = state.room
    if (!room) return
    var on = !room.localParticipant.isScreenShareEnabled()
    room.localParticipant.setScreenShareEnabled(on).then(attachLocalVideos).catch(function (e) { flash(String(e && e.message || e)) })
    setTimeout(attachLocalVideos, 400)
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
    // An outgoing ring vanished without connecting → declined / missed / cancelled.
    if (state.pendingCall && !active && !state.calls.outgoing[0]) {
      state.pendingCall = null
      flash('Call ended — no answer.')
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
  }

  function buildPane () {
    var el = document.createElement('div')
    el.id = 'lvcvoice-pane'
    el.className = 'lvcvoice-pane'
    el.hidden = true
    el.innerHTML =
      '<div class="lvcvoice-pane-head"><span class="lvcvoice-pane-title">Voice</span>' +
      '<button type="button" class="ghost small lvcvoice-pane-leave">Leave</button></div>' +
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
      if (currentRingId != null) acceptCall(currentRingId)
      el.hidden = true
    })
    el.querySelector('.lvcvoice-ring-decline').addEventListener('click', function () {
      if (currentRingId != null) declineCall(currentRingId)
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
      hidePill()
      hidePane()
      hideRing()
      return
    }

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

    // Call pill.
    var call = state.calls.active
    var outgoing = state.calls.outgoing[0] || null
    if (els.pill) {
      if (call) {
        showPill('active', 'In call with ' + call.peer)
      } else if (outgoing) {
        showPill('ringing', 'Ringing ' + outgoing.peer + '…')
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

    // Incoming ring.
    var incoming = state.calls.incoming[0] || null
    if (els.ring) {
      if (incoming) {
        els.ring.hidden = false
        els.ring.querySelector('.lvcvoice-ring-peer').textContent = incoming.peer + ' is calling…'
        els.ring._ringId(incoming.call_id)
      } else {
        els.ring.hidden = true
        els.ring._ringId(null)
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

  /* ── Boot ───────────────────────────────────────────────────────────── */
  function boot () {
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

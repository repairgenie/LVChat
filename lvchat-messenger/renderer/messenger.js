/* LVChat Messenger — IM-first client UI. */

'use strict'

const $ = (sel) => document.querySelector(sel)
const SYSTEM_KINDS = ['join', 'part', 'quit', 'kick', 'ban', 'topic', 'mode', 'nick', 'system', 'notice']

const state = {
  profile: null,
  me: null,
  friends: [],
  incoming: [],
  outgoing: [],
  blocked: [],
  channelInvites: [],
  groups: [],
  dmList: [],
  channelUnread: {},
  channelPresence: {},
  joinedChannels: [],
  open: null, // { type:'dm'|'room', id, title, members, since, topic }
  messages: [],
  tab: 'buddy',
  collapsed: new Set(),
  pollTimer: null,
  pollBusy: false,
  dirTimer: null,
  chatWindow: false, // this window is a dedicated conversation window (Compact)
  viewMode: 'compact', // 'compact' | 'advanced' (persisted in prefs)
  _chatTarget: null,
  offline: false, // the messenger has no connection to the server
  sendQueue: [], // offline-sent messages, delivered in order on reconnect
  _localId: 0, // counter for locally-pending (unsent) message ids
  flushing: false,
  sounds: null, // { list:{id:{name,url}}, dm:id|null, channel:id|null, overrides:{uid:id|null} }
  audioEl: null
}

/* OS-notification engine state. Only the buddy-list window alerts; dedicated
 * conversation windows stay silent (their host window already notifies). */
const notif = {
  prefs: { channels: 1, dms: 1, invites: 1 },
  seeded: false, // poll-derived alerts seeded (pre-existing state must not alert)
  feedSeeded: false, // /api/notifications feed seeded
  bgMax: 0, // highest background message id seen
  prevDm: {}, // username(lower) -> unread
  feedSeen: new Set(), // notification ids already surfaced
  feedTimer: null
}

/* The window may have been opened as a dedicated conversation window:
 * messenger.html?profile=<id>&chat=room:slug | chat=dm:username */
function parseChatTarget () {
  const params = new URLSearchParams(location.search)
  const chat = params.get('chat') || ''
  const sep = chat.indexOf(':')
  if (sep === -1) return
  const type = chat.slice(0, sep)
  const id = chat.slice(sep + 1)
  if ((type === 'dm' || type === 'room') && id) {
    state.chatWindow = true
    state._chatTarget = { type, id }
  }
}

parseChatTarget()

function esc (s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

function debounce (fn, ms) {
  let t
  return (...args) => {
    clearTimeout(t)
    t = setTimeout(() => fn(...args), ms)
  }
}

/* ── Avatars / status ─────────────────────────────────────── */

function avatarInitial (name) {
  return (name || '?').trim().charAt(0).toUpperCase()
}

function avatarEl (name, avatar, size) {
  const el = document.createElement('div')
  el.className = 'avatar'
  if (size) el.style.width = el.style.height = size
  if (avatar) {
    const img = document.createElement('img')
    img.src = LvApi.abs(avatar)
    img.alt = ''
    el.appendChild(img)
  } else {
    el.textContent = avatarInitial(name)
  }
  return el
}

function statusClass (user) {
  if (!user) return 'offline'
  if (user.invisible || !user.is_online) return 'offline'
  if (user.status_mode === 'dnd') return 'dnd'
  if (user.status_mode === 'away' || user.away) return 'away'
  if (user.status_mode === 'custom') return 'away'
  return 'online'
}

/* Short status text (custom status or away message) to show under a nick. */
function statusText (user) {
  const t = String((user && (user.custom_status != null ? user.custom_status : user.away)) || '').trim()
  return t.length > 60 ? t.slice(0, 59) + '…' : t
}

function statusLabel (mode) {
  return ({ online: 'Online', away: 'Away', dnd: 'Do Not Disturb', invisible: 'Appear Offline', custom: 'Custom status' })[mode || 'online'] || 'Online'
}

/* The avatar status-dot color for a mode: green online, yellow away/custom,
 * red DND, grey appear-offline. */
function statusDotClass (mode) {
  if (mode === 'dnd') return 'dnd'
  if (mode === 'away' || mode === 'custom') return 'away'
  if (mode === 'invisible') return 'offline'
  return 'online'
}

/* Native-title tooltip for a contact: nick + status + status text. */
function contactTitle (user) {
  const name = user && user.username ? user.username : '?'
  if (user && user.invisible) return name + ' — Appear Offline'
  if (user && !user.is_online && !user.away) return name + ' — Offline'
  const mode = (user && user.status_mode) || (user && user.away ? 'away' : 'online')
  let t = name + ' — ' + statusLabel(mode)
  const st = statusText(user)
  if (st) t += ' — ' + st
  return t
}

/* ── Modal dialogs (prompt / confirm / alert) ─────────────── */

let modalResolve = null

function showModal ({ title, message, input, initial, okLabel, cancel }) {
  return new Promise((resolve) => {
    modalResolve = resolve
    $('#modal-title').textContent = title
    $('#modal-message').textContent = message || ''
    $('#modal-message').hidden = !message
    $('#modal-input').hidden = !input
    if (input) {
      $('#modal-input').value = initial || ''
      $('#modal-input').placeholder = input
    }
    $('#modal-ok').textContent = okLabel || 'OK'
    $('#modal-cancel').hidden = !cancel
    $('#modal').hidden = false
    if (input) $('#modal-input').focus()
  })
}

function settleModal (value) {
  $('#modal').hidden = true
  if (modalResolve) { modalResolve(value); modalResolve = null }
}

function appPrompt (title, initial, input) {
  return showModal({ title, input: input || 'Enter a value', initial, cancel: true })
    .then((v) => (v === null ? null : String(v).trim()))
}

function appConfirm (message) {
  return showModal({ title: 'Confirm', message, okLabel: 'Yes', cancel: true })
}

function appAlert (message) {
  return showModal({ title: 'LVChat Messenger', message, okLabel: 'OK', cancel: false })
}

/* ── Boot / auth flow ─────────────────────────────────────── */

async function boot () {
  state.profile = await window.msg.profile()
  if (!state.profile) {
    document.body.textContent = 'No profile. Open the Profile Manager and connect to a server.'
    return
  }
  LvApi.init(new URL(state.profile.url).origin)
  LvApi.resetCsrf()

  $('#login-server').value = state.profile.url
  $('#login-server-url').textContent = new URL(state.profile.url).origin
  $('#login-username').value = state.profile.username || ''
  $('#login-target').hidden = false

  await Promise.all([applyTheme(), applyViewMode()])
  initSidebarResize()

  // Resume an existing session if the messenger API is reachable. Never let a
  // fetch/CORS failure abort boot: always land on the login view.
  let me = null
  try {
    me = await LvApi.getJson('/api/me')
  } catch (err) {
    me = { ok: false, status: 0 }
  }

  if (me.ok && me.body && me.body.user) {
    const expected = state.profile.username
    const actual = me.body.user.username
    if (expected && actual && String(expected).toLowerCase() !== String(actual).toLowerCase()) {
      // The persisted partition still holds a session for a different account
      // (the profile's server/account was edited, or an old session predates a
      // re-add). Wipe it so the user signs in as the account this profile
      // expects; msg:logout clears storage and reloads onto the login view.
      await window.msg.logout()
      return
    }
    await startMain(me.body.user)
    return
  }

  showView('login')

  // The messenger endpoint is missing or CORS-blocked → the server is running
  // an older LVChat build (no CORS middleware, no /api/me). Give the user an
  // actionable message instead of a silent failure.
  if (me.status === 404 || me.status === 0) {
    showLoginError(
      'Could not reach this LVChat server, or it is running an older build. ' +
      'Deploy the latest LVChat build (it adds CORS + the Messenger API) and try again.'
    )
    return
  }

  // Not signed in yet — try the keychain-saved credentials first.
  const saved = await window.msg.savedCredentials()
  if (saved && saved.username && saved.password) {
    await attemptLogin(saved.username, saved.password, false)
  } else if (saved && saved.username) {
    $('#login-username').value = saved.username
  }
}

function showView (name) {
  for (const v of ['login', 'mfa', 'main']) {
    $('#view-' + v).hidden = v !== name
  }
}

function showLoginError (msg) {
  $('#login-error').textContent = msg
  $('#login-error').hidden = !msg
  $('#login-submit').disabled = false
  $('#login-submit').textContent = 'Sign in'
}

async function attemptLogin (username, password, save) {
  $('#login-submit').disabled = true
  $('#login-submit').textContent = 'Signing in…'
  showLoginError('')
  const r = await LvApi.login(username, password)
  if (r.mfa) {
    $('#login-submit').disabled = false
    $('#login-submit').textContent = 'Sign in'
    showMfa(username, password, save)
    return
  }
  if (r.mfaSetup) {
    $('#login-submit').disabled = false
    $('#login-submit').textContent = 'Sign in'
    showLoginError('Two-factor authentication must be set up for this account. Open the web app to enroll.')
    window.msg.openExternal(new URL('/login/mfa/setup', LvApi.origin()).toString())
    return
  }
  if (r.error) {
    showLoginError(r.error)
    return
  }
  if (save) {
    await window.msg.saveCredentials({ id: state.profile.id, username, password })
  }
  const me = await LvApi.getJson('/api/me')
  if (me.ok && me.body && me.body.user) {
    await startMain(me.body.user)
  } else {
    showLoginError('Sign-in did not complete. Please try again.')
  }
}

function showMfa (username, password, save) {
  state._pendingLogin = { username, password, save }
  showView('mfa')
  $('#mfa-code').value = ''
  $('#mfa-error').hidden = true
  $('#mfa-submit').disabled = false
  $('#mfa-submit').textContent = 'Verify'
  $('#mfa-code').focus()
}

async function verifyMfa () {
  const code = $('#mfa-code').value.trim()
  if (!/^\d{6}$/.test(code)) {
    $('#mfa-error').textContent = 'Enter the 6-digit code.'
    $('#mfa-error').hidden = false
    return
  }
  $('#mfa-submit').disabled = true
  $('#mfa-submit').textContent = 'Verifying…'
  const r = await LvApi.mfaVerify(code)
  if (r.ok) {
    const pending = state._pendingLogin || {}
    if (pending.save && pending.password) {
      await window.msg.saveCredentials({ id: state.profile.id, username: pending.username, password: pending.password })
    }
    const me = await LvApi.getJson('/api/me')
    if (me.ok && me.body && me.body.user) {
      await startMain(me.body.user)
      return
    }
    $('#mfa-error').textContent = 'Could not complete sign-in.'
    $('#mfa-error').hidden = false
    $('#mfa-submit').disabled = false
    $('#mfa-submit').textContent = 'Verify'
    return
  }
  $('#mfa-error').textContent = r.error || 'Invalid authentication code.'
  $('#mfa-error').hidden = false
  $('#mfa-submit').disabled = false
  $('#mfa-submit').textContent = 'Verify'
}

async function doLogout () {
  stopPoll()
  stopNotifications()
  stopWs()
  LvApi.resetCsrf()
  await window.msg.logout()
}

/* ── Main app data ────────────────────────────────────────── */

async function startMain (me) {
  state.me = me
  $('#me-name').textContent = me.username
  $('#me-avatar').replaceChildren(avatarEl(me.username, me.avatar))
  const stDot = document.createElement('span')
  stDot.className = 'avatar-status'
  $('#me-avatar').appendChild(stDot)
  updateMeStatus()

  // Session is live — close the profile manager window.
  window.msg.loginComplete()

  showView('main')

  // Restore any messages queued while the messenger was offline, and watch for
  // connectivity changes so queued sends flush the moment we're back online.
  loadSendQueue()
  window.addEventListener('online', () => setOffline(false))
  window.addEventListener('offline', () => setOffline(true))
  if (navigator.onLine) flushSendQueue()
  else setOffline(true)

  if (state.chatWindow && state._chatTarget) {
    try {
      await openConversation(state._chatTarget.type, state._chatTarget.id)
    } catch (err) { /* leave whatever rendered */ }
    return
  }

  try {
    await refreshBuddyData()
  } catch (err) { /* keep going; the poll loop re-renders */ }
  await startPoll()
  await initNotifications()
  await initSounds()
  initWs()
  try {
    renderAll()
  } catch (err) { /* leave whatever rendered */ }
}

async function refreshBuddyData () {
  const [f, g] = await Promise.all([
    LvApi.getJson('/api/friends'),
    LvApi.getJson('/api/groups')
  ])
  if (f.ok && f.body) {
    state.friends = f.body.friends || []
    state.incoming = f.body.incoming || []
    state.outgoing = f.body.outgoing || []
    state.blocked = f.body.blocked || []
  }
  if (g.ok && g.body) {
    state.groups = g.body.groups || []
  }
}

async function startPoll () {
  stopPoll()
  await pollTick()
  state.pollTimer = setInterval(pollTick, 2000)
}

function stopPoll () {
  if (state.pollTimer) {
    clearInterval(state.pollTimer)
    state.pollTimer = null
  }
}

async function pollTick () {
  if (state.pollBusy) return
  state.pollBusy = true
  try {
    let path = '/api/poll?since=' + (state.open ? state.open.since : 0)
    if (state.open) {
      const key = state.open.type === 'dm' ? 'dm' : 'channel'
      path += '&' + key + '=' + encodeURIComponent(state.open.id)
    }
    path += '&bg_since=' + notif.bgMax
    const j = await LvApi.getJson(path)
    if (j.status === 401) {
      LvApi.resetCsrf()
      stopPoll()
      showView('login')
      showLoginError('Your session has expired. Please sign in again.')
      return
    }
    // status 0 = the fetch failed (offline / server unreachable).
    if (j.status === 0) {
      setOffline(true)
      return
    }
    if (!j.ok || !j.body) {
      setOffline(false)
      return
    }
    setOffline(false)
    handlePoll(j.body)
    // A dedicated room window keeps the server-side unread watermark advanced
    // so the buddy-list window's badge stays clear while this window is open.
    if (state.chatWindow && state.open && state.open.type === 'room') {
      LvApi.postForm('/api/channel/read', { channel: state.open.id })
    }
  } catch (err) {
    /* transient network error — keep polling */
    setOffline(true)
  } finally {
    state.pollBusy = false
  }
}

function handlePoll (body) {
  if (body.reconnect) {
    location.reload()
    return
  }
  if (Array.isArray(body.dm_list)) state.dmList = body.dm_list
  if (Array.isArray(body.friends)) state.friends = body.friends
  if (Array.isArray(body.friend_requests)) state.incoming = body.friend_requests
  if (Array.isArray(body.blocked)) state.blocked = body.blocked
  if (Array.isArray(body.channel_invites)) state.channelInvites = body.channel_invites
  if (body.channel_unread && Array.isArray(body.channel_unread)) {
    const map = {}
    for (const c of body.channel_unread) map[c.slug] = c.unread
    state.channelUnread = map
    state.joinedChannels = body.channel_unread.map((c) => ({ slug: c.slug, unread: c.unread }))
  }
  if (body.channel_presence && Array.isArray(body.channel_presence)) {
    const map = {}
    for (const c of body.channel_presence) map[c.slug] = c.online
    state.channelPresence = map
  }

  // Messages belong to the currently open conversation.
  if (Array.isArray(body.messages) && state.open) {
    const echo = body.dm || body.channel || ''
    if (echo.toLowerCase() === state.open.id.toLowerCase()) {
      applyMessages(body.messages)
    }
  }

  if (state.open && state.open.type === 'room' && body.channel === state.open.id) {
    if (Array.isArray(body.presence)) state.open.members = body.presence
    if (typeof body.topic === 'string') state.open.topic = body.topic
  }
  if (state.open && state.open.type === 'dm' && body.dm === state.open.id && Array.isArray(body.presence) && body.presence.length) {
    state.open.presence = body.presence[0]
  }

  checkAlerts(body)
  renderAll()
}

/* ── OS notifications (rendered by the main process) ───────── */

function notify (payload) {
  try {
    window.msg.notify(payload)
  } catch (err) { /* preload bridge missing */ }
}

function truncateNotify (s, n) {
  const t = String(s == null ? '' : s).replace(/\s+/g, ' ').trim()
  const max = n || 120
  return t.length > max ? t.slice(0, max - 1) + '…' : t
}

function totalUnread () {
  let n = 0
  for (const d of state.dmList) n += Number(d.unread) || 0
  for (const slug of Object.keys(state.channelUnread)) n += Number(state.channelUnread[slug]) || 0
  return n
}

/* Detect new DMs + background channel messages from a poll payload, and keep
 * the tray tooltip's unread total fresh. Only the buddy-list window alerts;
 * dedicated conversation windows never do. */
function mutedSender (id) {
  if (id == null || !state.sounds || !state.sounds.overrides) return false
  return Object.prototype.hasOwnProperty.call(state.sounds.overrides, id) && state.sounds.overrides[id] === null
}

function checkAlerts (body) {
  if (state.chatWindow) return
  try { window.msg.setUnread(totalUnread()) } catch (err) { /* ignore */ }
  const focused = typeof document.hasFocus === 'function' ? document.hasFocus() : true
  const meDnd = !!(state.me && state.me.status_mode === 'dnd')

  // First poll: seed the watermark/prev state silently so pre-existing unread
  // messages, invites and background chatter never fire an alert.
  if (!notif.seeded) {
    if (Array.isArray(body.dm_list)) {
      for (const d of body.dm_list) notif.prevDm[String(d.username || '').toLowerCase()] = Number(d.unread) || 0
    }
    if (Array.isArray(body.bg_messages)) {
      for (const m of body.bg_messages) notif.bgMax = Math.max(notif.bgMax, Number(m.id) || 0)
    }
    notif.seeded = true
    return
  }

  if (Array.isArray(body.dm_list)) {
    for (const d of body.dm_list) {
      const key = String(d.username || '').toLowerCase()
      const prev = notif.prevDm[key] || 0
      const now = Number(d.unread) || 0
      notif.prevDm[key] = now
      if (now <= prev || now === 0) continue
      if (focused && state.open && state.open.type === 'dm' && String(state.open.id).toLowerCase() === key) continue
      // Do Not Disturb silences the audio + OS alert for this user.
      if (meDnd) continue
      // A per-user mute silences this person's sound + OS alert.
      if (d.muted) continue
      // Audio alert fires whenever a new DM lands; the OS notification is a
      // separate toggle so users can mute one without the other.
      playSound(effectiveSound(d.user_id, state.sounds && state.sounds.dm))
      if (notif.prefs.dms !== 1) continue
      const content = truncateNotify(d.last_content)
      notify({
        title: 'DM from ' + d.username,
        body: content ? d.username + ': ' + content : 'New direct message',
        conv: { type: 'dm', id: d.username }
      })
    }
  }

  if (Array.isArray(body.bg_messages)) {
    for (const m of body.bg_messages) {
      const id = Number(m.id) || 0
      if (id <= notif.bgMax) continue
      notif.bgMax = id
      if (focused && state.open && state.open.type === 'room' && String(state.open.id).toLowerCase() === String(m.channel_slug || '').toLowerCase()) continue
      if (meDnd) continue
      // A per-user mute silences this person's sound + OS alert.
      if (mutedSender(m.sender_id)) continue
      playSound(effectiveSound(m.sender_id, state.sounds && state.sounds.channel))
      if (notif.prefs.channels !== 1) continue
      const slug = m.channel_slug || 'channel'
      notify({
        title: '#' + slug,
        body: (m.username ? m.username + ': ' : '') + truncateNotify(m.content),
        conv: { type: 'room', id: slug }
      })
    }
  }
}

/* Poll the notifications feed for friend requests/accepts, invites and
 * mentions — the poll payload never lists those with their content. First
 * successful read seeds the dedupe set silently. */
async function pollNotificationsFeed () {
  if (state.chatWindow) return
  const j = await LvApi.getJson('/api/notifications')
  if (!j.ok || !j.body || !Array.isArray(j.body.notifications)) { notif.feedSeeded = false; return }
  const seed = !notif.feedSeeded
  for (const n of j.body.notifications) {
    if (!n || !n.id) continue
    const kind = String(n.kind || '')
    if (kind !== 'friend_request' && kind !== 'friend_accepted' && kind !== 'invite' && kind !== 'mention') continue
    if (notif.feedSeen.has(n.id)) continue
    notif.feedSeen.add(n.id)
    if (seed) continue
    // Do Not Disturb silences these alerts too.
    if (state.me && state.me.status_mode === 'dnd') continue
    if (kind === 'friend_request') {
      notify({ title: 'Friend request', body: (n.sender || 'Someone') + ' sent you a friend request', conv: { type: 'dm', id: n.sender } })
    } else if (kind === 'friend_accepted') {
      notify({ title: 'Friend request accepted', body: (n.sender || 'Someone') + ' is now your friend', conv: { type: 'dm', id: n.sender } })
    } else if (kind === 'invite') {
      if (notif.prefs.invites !== 1) continue
      notify({ title: 'Channel invite', body: 'You were invited to ' + (n.channel_name || 'a channel') + (n.sender ? ' by ' + n.sender : '') })
    } else if (kind === 'mention') {
      if (notif.prefs.channels !== 1) continue
      playSound(effectiveSound(n.sender_id, state.sounds && state.sounds.channel))
      notify({ title: 'Mentioned you', body: (n.sender ? '@' + n.sender : 'Someone') + (n.channel_name ? ' in ' + n.channel_name : '') })
    }
  }
  notif.feedSeeded = true
}

async function initNotifications () {
  let prefs = loadLocalNotifyPrefs()
  try {
    const j = await LvApi.getJson('/api/push/prefs')
    if (j.ok && j.body && j.body.prefs) {
      const p = j.body.prefs
      prefs = { channels: p.channels === 0 ? 0 : 1, dms: p.dms === 0 ? 0 : 1, invites: p.invites === 0 ? 0 : 1 }
      persistLocalNotifyPrefs(prefs)
    }
  } catch (err) { /* server endpoint unavailable — local prefs stand */ }
  notif.prefs = prefs
  notif.feedTimer = setInterval(pollNotificationsFeed, 4000)
  pollNotificationsFeed()
}

function stopNotifications () {
  if (notif.feedTimer) {
    clearInterval(notif.feedTimer)
    notif.feedTimer = null
  }
}

/* ── Audio alerts (sound notifications) ──────────────────────
 * Loads the server's sound list + the user's DM/channel choices and per-sender
 * overrides once at startup, and ALWAYS ships three built-in tones so audio
 * alerts work even when the server hasn't deployed the sounds endpoint yet.
 * A per-sender override wins over the context default; a null override means
 * that sender is muted. */

/* Synthesize a tiny PCM WAV (16-bit mono) as a data URL — no binary assets. */
function wavDataUrl (freq, dur) {
  const rate = 22050
  const n = Math.round(dur * rate)
  const samples = new Uint8Array(n * 2)
  for (let i = 0; i < n; i++) {
    const t = i / rate
    const env = Math.exp(-3.2 * (t / dur))
    const v = Math.sin(2 * Math.PI * freq * t) * env * 0.45
    const val = Math.round(Math.max(-1, Math.min(1, v)) * 32767) & 0xFFFF
    samples[i * 2] = val & 0xFF
    samples[i * 2 + 1] = (val >> 8) & 0xFF
  }
  const dataSize = samples.length
  const buf = new ArrayBuffer(44 + dataSize)
  const dv = new DataView(buf)
  const str = (o, s) => { for (let i = 0; i < s.length; i++) dv.setUint8(o + i, s.charCodeAt(i)) }
  str(0, 'RIFF'); dv.setUint32(4, 36 + dataSize, true); str(8, 'WAVE')
  str(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 1, true); dv.setUint16(22, 1, true)
  dv.setUint32(24, rate, true); dv.setUint32(28, rate * 2, true); dv.setUint16(32, 2, true); dv.setUint16(34, 16, true)
  str(36, 'data'); dv.setUint32(40, dataSize, true)
  new Uint8Array(buf, 44).set(samples)
  const bytes = new Uint8Array(buf)
  let bin = ''
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i])
  return 'data:audio/wav;base64,' + btoa(bin)
}

function builtinSounds () {
  return {
    'builtin-ding': { name: 'Ding', url: wavDataUrl(880, 0.4) },
    'builtin-pop': { name: 'Pop', url: wavDataUrl(1175, 0.18) },
    'builtin-chime': { name: 'Chime', url: wavDataUrl(660, 0.55) }
  }
}

function soundPrefsKey () {
  return 'lvcmsg.sound.' + (state.profile ? state.profile.id : '')
}

function loadLocalSoundPrefs () {
  try {
    const j = JSON.parse(localStorage.getItem(soundPrefsKey()) || '{}')
    return { dm: j.dm, channel: j.channel }
  } catch (err) {
    return { dm: null, channel: null }
  }
}

function persistLocalSoundPrefs (dm, channel) {
  try {
    localStorage.setItem(soundPrefsKey(), JSON.stringify({ dm, channel }))
  } catch (err) { /* ignore */ }
}

async function initSounds () {
  const builtin = builtinSounds()
  const local = loadLocalSoundPrefs()
  let server = null
  try {
    const j = await LvApi.getJson('/api/sounds')
    if (j.ok && j.body && j.body.sounds && Object.keys(j.body.sounds).length) server = j.body
  } catch (err) { server = null }

  const list = {}
  const serverNames = new Set()
  if (server) {
    for (const id of Object.keys(server.sounds)) {
      list[id] = server.sounds[id]
      if (server.sounds[id] && server.sounds[id].name) serverNames.add(String(server.sounds[id].name).trim().toLowerCase())
    }
  }
  // The server seeds the same tones (Ding/Pop/Chime), so only fall back to the
  // local built-ins when the server has no sound with that name — keeps the
  // settings picker free of duplicate entries while older servers (no sounds
  // endpoint) still get audio alerts out of the box.
  for (const id of Object.keys(builtin)) {
    if (list[id]) continue
    if (serverNames.has(String(builtin[id].name).trim().toLowerCase())) continue
    list[id] = builtin[id]
  }

  // Server prefs win when the server has sounds; otherwise use local choices,
  // defaulting to a built-in tone so audio alerts work out of the box.
  let dm = local.dm || 'builtin-ding'
  let channel = local.channel || 'builtin-pop'
  if (server) {
    if (server.dm_sound_id != null) dm = Number(server.dm_sound_id) > 0 ? String(server.dm_sound_id) : null
    if (server.channel_sound_id != null) channel = Number(server.channel_sound_id) > 0 ? String(server.channel_sound_id) : null
  }
  if (dm && !list[dm]) dm = 'builtin-ding'
  if (channel && !list[channel]) channel = 'builtin-pop'

  state.sounds = { list, dm, channel, overrides: (server && server.overrides) || {} }
}

function soundUrl (soundId) {
  const s = state.sounds && state.sounds.list[soundId]
  return s ? LvApi.abs(s.url) : ''
}

function playSound (soundId) {
  if (soundId == null) return
  const url = soundUrl(soundId)
  if (!url) return
  try {
    if (!state.audioEl) state.audioEl = new Audio()
    state.audioEl.src = url
    state.audioEl.currentTime = 0
    state.audioEl.play().catch(() => {})
  } catch (err) { /* audio unavailable — never crash on an alert */ }
}

/* The sound to play for a message from $senderUid: a per-sender override wins
 * (null = that sender is muted); otherwise the context default. */
function effectiveSound (senderUid, fallback) {
  if (!state.sounds) return null
  const ov = senderUid != null ? state.sounds.overrides[senderUid] : undefined
  if (ov !== undefined) return ov == null ? null : Number(ov)
  return fallback
}

/* ── WebSocket real-time ─────────────────────────────────────
 * Additive transport: the 2s poll keeps running as the always-on fallback and
 * sidebar/friends/reconcile source. WS only accelerates delivery of messages
 * in the open conversation and drives the notifications feed on bell changes.
 * Handshake mirrors the web app (GET /api/ws/ticket, one-time ticket in the
 * URL, {action:'subscribe'} after open, 30s pings, backoff reconnects). */
const wsrt = {
  ws: null,
  ticket: '',
  base: '',
  fails: 0,
  retryTimer: null,
  pingTimer: null,
  gone: false
}

function wsSend (obj) {
  if (wsrt.ws && wsrt.ws.readyState === WebSocket.OPEN) wsrt.ws.send(JSON.stringify(obj))
}

function wsSubscribe () {
  if (!wsrt.ws || wsrt.ws.readyState !== WebSocket.OPEN || !state.open) return
  const payload = state.open.type === 'dm'
    ? { action: 'subscribe', dm: state.open.id }
    : { action: 'subscribe', channel: state.open.id }
  wsrt.ws.send(JSON.stringify(payload))
}

function wsRefreshTicket (done) {
  LvApi.getJson('/api/ws/ticket')
    .then((j) => {
      if (j.ok && j.body && j.body.ticket) {
        wsrt.ticket = j.body.ticket
        wsrt.base = j.body.url || wsrt.base
      }
      done()
    })
    .catch(() => done())
}

function wsHandle (j) {
  if (j.pong) return
  if (j.reconnect) { location.reload(); return }
  if (Array.isArray(j.messages) && j.messages.length) {
    // Frames arrive only for the conversation this socket is subscribed to.
    if (state.open) applyMessages(j.messages)
    return
  }
  if (j.msg_update && state.open && state.open.type === 'room') {
    const u = j.msg_update
    const idx = state.messages.findIndex((m) => Number(m.id) === Number(u.message_id))
    if (idx !== -1) {
      if (u.action === 'delete') { state.messages.splice(idx, 1); renderStream() }
      else if (u.action === 'edit' && typeof u.content === 'string') { state.messages[idx].content = u.content; renderStream() }
    }
    return
  }
  // Unread bell moved server-side — refresh the notifications feed so friend
  // requests/accepts/invites/mentions surface without waiting for the timer.
  if (typeof j.notify_count === 'number' && !state.chatWindow) pollNotificationsFeed()
}

function wsOpen () {
  if (wsrt.gone) return
  const base = wsrt.base
  if (!base) { wsrt.gone = true; return }
  const sep = base.indexOf('?') >= 0 ? '&' : '?'
  let socket = null
  try {
    socket = new WebSocket(base + sep + 'ticket=' + encodeURIComponent(wsrt.ticket))
  } catch (err) { /* fall through */ }
  if (!socket) { wsrt.gone = true; return }
  wsrt.ws = socket
  socket.onopen = () => { wsrt.fails = 0; wsSubscribe() }
  socket.onmessage = (ev) => {
    let j = null
    try { j = JSON.parse(ev.data) } catch (err) { return }
    wsHandle(j)
  }
  socket.onerror = () => { try { socket.close() } catch (err) {} }
  socket.onclose = () => {
    wsrt.ws = null
    if (wsrt.gone) return
    wsrt.fails++
    if (wsrt.fails >= 3) {
      // Daemon unreachable: back way off but keep re-probing so a later daemon
      // start re-enables realtime. The 2s poll keeps everything working.
      wsrt.fails = 0
      wsrt.retryTimer = setTimeout(() => wsRefreshTicket(wsOpen), 5 * 60 * 1000)
      return
    }
    wsRefreshTicket(() => { wsrt.retryTimer = setTimeout(wsOpen, 2000 * wsrt.fails) })
  }
}

async function initWs () {
  if (typeof WebSocket === 'undefined' || wsrt.gone) return
  wsrt.pingTimer = setInterval(() => wsSend({ action: 'ping' }), 30000)
  wsRefreshTicket(wsOpen)
}

function stopWs () {
  wsrt.gone = true
  if (wsrt.retryTimer) { clearTimeout(wsrt.retryTimer); wsrt.retryTimer = null }
  if (wsrt.pingTimer) { clearInterval(wsrt.pingTimer); wsrt.pingTimer = null }
  if (wsrt.ws) { try { wsrt.ws.close() } catch (err) {} wsrt.ws = null }
}

function applyMessages (messages) {
  let changed = false
  for (const m of messages || []) {
    const id = Number(m.id)
    if (id > state.open.since) state.open.since = id
    if (state.messages.some((x) => Number(x.id) === id)) continue
    state.messages.push(m)
    changed = true
  }
  if (changed) {
    sortMessages()
    renderStream()
  }
}

/* ── Conversation switching ───────────────────────────────── */

async function openConversation (type, id) {
  hidePickers()
  state.open = {
    type,
    id,
    title: type === 'room' ? '#' + id : id,
    since: 0,
    messages: [],
    members: [],
    topic: '',
    presence: null
  }
  state.messages = state.open.messages
  state.tab = type === 'room' ? 'rooms' : 'buddy'
  setTab(state.tab)
  renderAll()
  wsSubscribe()
  await pollTick()
  if (type === 'room') {
    await LvApi.postForm('/api/channel/read', { channel: id })
  }
}

function openDm (username) {
  openConversation('dm', username)
}

function openRoom (slug) {
  openConversation('room', slug)
}

/* In Compact view, opening a conversation always means a dedicated window. In
 * Advanced view it opens in the in-window pane. */
async function openConversationOrWindow (type, id) {
  if (state.viewMode === 'compact') return openChatWindow(type, id)
  return type === 'dm' ? openDm(id) : openRoom(id)
}

/* Ask the main process for a dedicated conversation window (Pidgin-style). */
async function openChatWindow (type, id) {
  await window.msg.openChat({ type, id })
}

/* ── Rendering: sidebar ───────────────────────────────────── */

function renderAll () {
  renderSidebar()
  renderChat()
}

function unreadFor (username) {
  const d = state.dmList.find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
  return d ? Number(d.unread) : 0
}

function friendAvatar (username) {
  for (const list of [state.friends, state.incoming, state.outgoing]) {
    const u = list.find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
    if (u && u.avatar) return u.avatar
  }
  for (const g of state.groups) {
    const u = (g.members || []).find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
    if (u && u.avatar) return u.avatar
  }
  return ''
}

function makeContact (user, opts) {
  const wrap = document.createElement('div')
  wrap.className = 'contact' + (user.is_online || user.away ? '' : ' off') + (opts.selected ? ' selected' : '')
  wrap.title = contactTitle(user)

  const dot = document.createElement('div')
  dot.className = 'dot ' + statusClass(user)
  const avatar = avatarEl(user.username, user.avatar, '28px')
  const info = document.createElement('div')
  info.className = 'contact-name-col'
  const name = document.createElement('div')
  name.className = 'contact-name'
  name.textContent = user.username
  info.appendChild(name)
  const st = statusText(user)
  if (st) {
    const sub = document.createElement('div')
    sub.className = 'contact-status'
    sub.textContent = st
    info.appendChild(sub)
  }

  wrap.append(dot, avatar, info)

  const unread = unreadFor(user.username)
  if (unread > 0) {
    const b = document.createElement('span')
    b.className = 'unread'
    b.textContent = unread > 99 ? '99+' : unread
    wrap.appendChild(b)
  }

  wrap.addEventListener('click', (e) => {
    if (e.target.closest('.ctx-menu')) return
    if (state.viewMode === 'compact') return
    openDm(user.username)
  })
  wrap.addEventListener('dblclick', (e) => {
    if (e.target.closest('.ctx-menu')) return
    e.preventDefault()
    openChatWindow('dm', user.username)
  })
  wrap.addEventListener('contextmenu', (e) => {
    e.preventDefault()
    openContextMenu(e.clientX, e.clientY, user)
  })
  return wrap
}

function renderSidebar () {
  if (state.chatWindow) return
  // Directory search panel takes precedence while typing.
  const searching = $('#directory-search').value.trim() !== ''
  $('#panel-directory').hidden = !searching

  $('#tab-buddy').classList.toggle('active', state.tab === 'buddy')
  $('#tab-rooms').classList.toggle('active', state.tab === 'rooms')
  $('#tab-requests').classList.toggle('active', state.tab === 'requests')

  const reqCount = state.incoming.length + state.channelInvites.length
  const badge = $('#req-badge')
  badge.hidden = reqCount === 0
  badge.textContent = reqCount > 99 ? '99+' : reqCount

  $('#panel-buddy').hidden = state.tab !== 'buddy' || searching
  $('#panel-rooms').hidden = state.tab !== 'rooms' || searching
  $('#panel-requests').hidden = state.tab !== 'requests' || searching

  if (state.tab === 'buddy') renderBuddyList()
  if (state.tab === 'rooms') renderRoomsList()
  if (state.tab === 'requests') renderRequestsList()
}

function renderBuddyList () {
  const list = $('#buddy-list')
  list.replaceChildren()

  const assigned = new Set()
  for (const g of state.groups) {
    for (const m of (g.members || [])) assigned.add(String(m.id))
  }

  for (const g of state.groups) {
    list.appendChild(renderGroup(g))
  }

  // Ungrouped friends node.
  const ungrouped = state.friends.filter((f) => !assigned.has(String(f.id)))
  if (ungrouped.length) {
    const node = renderGroup({ id: 'ungrouped', name: 'Ungrouped', members: ungrouped, isUngrouped: true })
    list.appendChild(node)
  }

  const blockedNode = renderBlockedGroup()
  if (blockedNode) list.appendChild(blockedNode)

  if (state.friends.length === 0 && state.groups.length === 0) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No friends yet. Use the search box to find people and add them.'
    list.appendChild(empty)
  }
}

function renderGroup (g) {
  const node = document.createElement('div')
  node.className = 'group' + (state.collapsed.has('g:' + g.id) ? ' collapsed' : '')

  const head = document.createElement('div')
  head.className = 'group-head'
  const caret = document.createElement('span')
  caret.className = 'caret'
  caret.textContent = '▼'
  const title = document.createElement('span')
  title.textContent = g.isUngrouped ? g.name : g.name
  const count = document.createElement('span')
  count.className = 'count'
  count.textContent = (g.members || []).length

  const actions = document.createElement('span')
  actions.className = 'g-actions'
  if (!g.isUngrouped) {
    const ren = document.createElement('button')
    ren.className = 'icon-btn'
    ren.title = 'Rename group'
    ren.textContent = '✎'
    ren.addEventListener('click', (e) => { e.stopPropagation(); renameGroup(g) })
    const del = document.createElement('button')
    del.className = 'icon-btn'
    del.title = 'Delete group'
    del.textContent = '✕'
    del.addEventListener('click', (e) => { e.stopPropagation(); deleteGroup(g) })
    actions.append(ren, del)
  }
  head.append(caret, title, count, actions)
  head.addEventListener('click', () => {
    if (state.collapsed.has('g:' + g.id)) state.collapsed.delete('g:' + g.id)
    else state.collapsed.add('g:' + g.id)
    node.classList.toggle('collapsed')
  })
  head.addEventListener('contextmenu', (e) => {
    e.preventDefault()
    openGroupContextMenu(e.clientX, e.clientY, g)
  })

  const members = document.createElement('div')
  members.className = 'group-members'
  for (const m of (g.members || [])) {
    const c = makeContact(m, { selected: state.open && state.open.type === 'dm' && String(state.open.id).toLowerCase() === String(m.username).toLowerCase() })
    if (!g.isUngrouped) {
      c.addEventListener('contextmenu', (e) => {
        e.preventDefault()
        e.stopPropagation()
        openMemberContextMenu(e.clientX, e.clientY, m, g)
      })
    }
    members.appendChild(c)
  }
  if (!(g.members || []).length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No members'
    members.appendChild(empty)
  }

  node.append(head, members)
  return node
}

/* Collapsible "Blocked users" group at the bottom of the buddy list. Each row
 * carries an Unblock button so a block can be lifted without leaving the app. */
function renderBlockedGroup () {
  if (!state.blocked.length) return null
  const node = document.createElement('div')
  node.className = 'group' + (state.collapsed.has('g:blocked') ? ' collapsed' : '')

  const head = document.createElement('div')
  head.className = 'group-head'
  const caret = document.createElement('span')
  caret.className = 'caret'
  caret.textContent = '▼'
  const title = document.createElement('span')
  title.textContent = 'Blocked users'
  const count = document.createElement('span')
  count.className = 'count'
  count.textContent = state.blocked.length
  head.append(caret, title, count)
  head.addEventListener('click', () => {
    if (state.collapsed.has('g:blocked')) state.collapsed.delete('g:blocked')
    else state.collapsed.add('g:blocked')
    node.classList.toggle('collapsed')
  })

  const members = document.createElement('div')
  members.className = 'group-members'
  for (const b of state.blocked) {
    const row = document.createElement('div')
    row.className = 'contact'
    row.title = contactTitle(Object.assign({ is_online: 0, away: null, status_mode: 'invisible' }, b))
    const dot = document.createElement('div')
    dot.className = 'dot offline'
    const avatar = avatarEl(b.username, b.avatar, '28px')
    const info = document.createElement('div')
    info.className = 'contact-name-col'
    const name = document.createElement('div')
    name.className = 'contact-name'
    name.textContent = b.username
    info.appendChild(name)
    const sub = document.createElement('div')
    sub.className = 'contact-status'
    sub.textContent = 'Blocked'
    info.appendChild(sub)
    const un = document.createElement('button')
    un.type = 'button'
    un.className = 'ghost small'
    un.textContent = 'Unblock'
    un.title = 'Unblock ' + b.username
    un.addEventListener('click', (e) => {
      e.stopPropagation()
      unblockUser(b)
    })
    row.append(dot, avatar, info, un)
    row.addEventListener('dblclick', () => openChatWindow('dm', b.username))
    members.appendChild(row)
  }

  node.append(head, members)
  return node
}

async function unblockUser (user) {
  if (!user || !user.username) return
  const r = await LvApi.postForm('/api/friend/unblock', { username: user.username })
  if (!r.ok) {
    await appAlert((r.body && r.body.error) || 'Could not unblock ' + user.username + '.')
    return
  }
  await refreshBuddyData()
  await pollTick()
}

function renderRoomsList () {
  const list = $('#rooms-list')
  list.replaceChildren()
  if (!state.joinedChannels.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No rooms yet. Join rooms in the web app (or be invited) and they will appear here.'
    list.appendChild(empty)
    return
  }
  for (const c of state.joinedChannels) {
    const row = document.createElement('div')
    row.className = 'contact' + (state.open && state.open.type === 'room' && state.open.id === c.slug ? ' selected' : '')
    const icon = document.createElement('div')
    icon.className = 'avatar'
    icon.textContent = '#'
    const name = document.createElement('div')
    name.className = 'contact-name'
    name.textContent = c.slug
    row.append(icon, name)
    const online = state.channelPresence[c.slug]
    if (typeof online === 'number') {
      const oc = document.createElement('span')
      oc.className = 'contact-name'
      oc.style.flex = 'none'
      oc.style.color = 'var(--muted)'
      oc.style.fontSize = '11px'
      oc.textContent = online + ' online'
      row.appendChild(oc)
    }
    if (c.unread > 0) {
      const b = document.createElement('span')
      b.className = 'unread'
      b.textContent = c.unread > 99 ? '99+' : c.unread
      row.appendChild(b)
    }
    row.addEventListener('click', () => {
      if (state.viewMode === 'compact') return
      openRoom(c.slug)
    })
    row.addEventListener('dblclick', () => openChatWindow('room', c.slug))
    row.addEventListener('contextmenu', (e) => {
      e.preventDefault()
      openRoomContextMenu(e.clientX, e.clientY, c)
    })
    list.appendChild(row)
  }
}

function renderRequestsList () {
  const list = $('#requests-list')
  list.replaceChildren()

  if (state.channelInvites.length) {
    const t = document.createElement('div')
    t.className = 'panel-title'
    t.textContent = 'Channel invites'
    list.appendChild(t)
    for (const inv of state.channelInvites) {
      list.appendChild(makeInviteRow(inv))
    }
  }
  if (state.incoming.length) {
    const t = document.createElement('div')
    t.className = 'panel-title'
    t.textContent = 'Incoming requests'
    list.appendChild(t)
    for (const r of state.incoming) {
      list.appendChild(makeRequestRow(r, 'incoming'))
    }
  }
  if (state.outgoing.length) {
    const t = document.createElement('div')
    t.className = 'panel-title'
    t.textContent = 'Sent requests'
    list.appendChild(t)
    for (const r of state.outgoing) {
      list.appendChild(makeRequestRow(r, 'outgoing'))
    }
  }
  if (!state.incoming.length && !state.outgoing.length && !state.channelInvites.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No pending requests or invites.'
    list.appendChild(empty)
  }
}

/* A channel invite row: accept joins the room, decline removes it. */
function makeInviteRow (inv) {
  const wrap = document.createElement('div')
  wrap.className = 'req'
  const meta = document.createElement('div')
  meta.className = 'req-meta'
  const icon = document.createElement('div')
  icon.className = 'avatar'
  icon.textContent = '#'
  const name = document.createElement('div')
  name.className = 'req-name'
  name.textContent = inv.channel_name || inv.slug
  meta.append(icon, name)
  wrap.appendChild(meta)
  if (inv.inviter) {
    const by = document.createElement('div')
    by.className = 'req-sub'
    by.textContent = 'Invited by ' + inv.inviter
    wrap.appendChild(by)
  }

  const actions = document.createElement('div')
  actions.className = 'req-actions'
  const accept = document.createElement('button')
  accept.className = 'primary'
  accept.textContent = 'Accept'
  const decline = document.createElement('button')
  decline.className = 'ghost small'
  decline.textContent = 'Decline'
  accept.addEventListener('click', async () => {
    accept.disabled = true
    await LvApi.postForm('/api/channel/invite/accept', { channel: inv.slug })
    state.channelInvites = state.channelInvites.filter((x) => x.slug !== inv.slug)
    renderRequestsList()
    await pollTick()
  })
  decline.addEventListener('click', async () => {
    decline.disabled = true
    await LvApi.postForm('/api/channel/invite/decline', { channel: inv.slug })
    state.channelInvites = state.channelInvites.filter((x) => x.slug !== inv.slug)
    renderRequestsList()
  })
  actions.append(accept, decline)
  wrap.appendChild(actions)
  return wrap
}

function makeRequestRow (r, dir) {
  const wrap = document.createElement('div')
  wrap.className = 'req'
  const meta = document.createElement('div')
  meta.className = 'req-meta'
  const avatar = avatarEl(r.username, r.avatar, '28px')
  const name = document.createElement('div')
  name.className = 'req-name'
  name.textContent = r.username
  meta.append(avatar, name)
  wrap.appendChild(meta)

  const actions = document.createElement('div')
  actions.className = 'req-actions'
  const accept = document.createElement('button')
  accept.className = 'primary'
  accept.textContent = 'Accept'
  const decline = document.createElement('button')
  decline.className = 'ghost small'
  decline.textContent = 'Decline'
  const cancel = document.createElement('button')
  cancel.className = 'ghost small'
  cancel.textContent = 'Cancel request'
  const dm = document.createElement('button')
  dm.className = 'ghost small'
  dm.textContent = 'Message'

  dm.addEventListener('click', () => openConversationOrWindow('dm', r.username))

  if (dir === 'incoming') {
    accept.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/accept', { username: r.username })
      await refreshBuddyData()
    })
    decline.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/decline', { username: r.username })
      await refreshBuddyData()
    })
    actions.append(accept, decline, dm)
  } else {
    cancel.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/cancel', { username: r.username })
      await refreshBuddyData()
    })
    actions.append(cancel, dm)
  }
  wrap.appendChild(actions)
  return wrap
}

/* ── Directory search ─────────────────────────────────────── */

async function runDirectorySearch () {
  const q = $('#directory-search').value.trim()
  const list = $('#directory-list')
  list.replaceChildren()
  if (!q) return
  const j = await LvApi.getJson('/api/directory?q=' + encodeURIComponent(q) + '&limit=25')
  if (!j.ok || !j.body) return
  const results = j.body.results || []
  if (!results.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No matching users found.'
    list.appendChild(empty)
    return
  }
  for (const r of results) {
    list.appendChild(makeDirectoryRow(r))
  }
}

function makeDirectoryRow (r) {
  const wrap = document.createElement('div')
  wrap.className = 'contact'
  const dot = document.createElement('div')
  dot.className = 'dot ' + (r.is_online ? 'online' : 'offline')
  const avatar = avatarEl(r.username, r.avatar, '28px')
  const name = document.createElement('div')
  name.className = 'contact-name'
  name.textContent = r.username
  wrap.append(dot, avatar, name)

  const btn = document.createElement('button')
  btn.className = 'ghost small'

  if (r.status === 'none') {
    btn.textContent = 'Add friend'
    btn.addEventListener('click', async () => {
      btn.disabled = true
      btn.textContent = '…'
      await LvApi.postForm('/api/friend/request', { username: r.username })
      btn.textContent = 'Requested ✓'
      await refreshBuddyData()
    })
  } else if (r.status === 'outgoing') {
    btn.textContent = 'Requested'
    btn.disabled = true
  } else if (r.status === 'incoming') {
    btn.textContent = 'Accept'
    btn.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/accept', { username: r.username })
      btn.textContent = 'Friends ✓'
      btn.disabled = true
      await refreshBuddyData()
    })
  } else if (r.status === 'friend') {
    btn.textContent = 'Friends ✓'
    btn.disabled = true
  } else {
    btn.textContent = r.status
    btn.disabled = true
  }
  wrap.appendChild(btn)
  wrap.addEventListener('click', (e) => {
    if (e.target.closest('button')) return
    openConversationOrWindow('dm', r.username)
  })
  return wrap
}

/* ── Context menus ────────────────────────────────────────── */

let ctxMenu = null

function closeContextMenu () {
  if (ctxMenu) { ctxMenu.remove(); ctxMenu = null }
}

/* ── Mute / block ──────────────────────────────────────────
 * Mute = per-user notification mute (user_mutes): silences that person's
 * sound + OS alerts across every surface. Block = the friendship becomes
 * 'blocked' and the user leaves the friends list (kept under "Blocked users"
 * so the block can be lifted from the messenger). */

function isMuted (user) {
  if (!user) return false
  if (Number(user.muted) === 1) return true
  // Friends carry a `muted` flag; everyone else falls back to the sounds
  // overrides, where a push-muted sender is forced to a null sound.
  if (user.id != null && state.sounds && state.sounds.overrides) {
    const ov = state.sounds.overrides[user.id]
    return Object.prototype.hasOwnProperty.call(state.sounds.overrides, user.id) && ov === null
  }
  return false
}

function isBlocked (user) {
  if (!user || !user.username) return false
  const key = String(user.username).toLowerCase()
  return state.blocked.some((b) => String(b.username || '').toLowerCase() === key)
}

async function setUserMuted (user, currentlyMuted) {
  if (!user || user.id == null) return
  const path = currentlyMuted ? '/api/push/unmute' : '/api/push/mute'
  const r = await LvApi.postForm(path, { user_id: user.id })
  if (!r.ok) {
    await appAlert((r.body && r.body.error) || 'Could not update the mute.')
    return
  }
  // Refetch sounds so the sender's overrides (and therefore alerts) update.
  await initSounds()
  await refreshBuddyData()
}

async function setUserBlocked (user, currentlyBlocked) {
  if (!user || !user.username) return
  const path = currentlyBlocked ? '/api/friend/unblock' : '/api/friend/block'
  const r = await LvApi.postForm(path, { username: user.username })
  if (!r.ok) {
    await appAlert((r.body && r.body.error) || 'Could not update the block.')
    return
  }
  await refreshBuddyData()
  await pollTick()
}

/* Add a Mute/Unmute + Block/Unblock pair to a context menu. */
function addMuteBlockItems (menu, user) {
  const muted = isMuted(user)
  const blockItem = menuItem(muted ? 'Unmute notifications' : 'Mute notifications', async () => {
    await setUserMuted(user, muted)
    closeContextMenu()
  })
  menu.appendChild(blockItem)

  const blocked = isBlocked(user)
  const item = menuItem(blocked ? 'Unblock' : 'Block', async () => {
    if (!blocked) {
      closeContextMenu()
      const ok = await appConfirm('Block ' + (user.username || 'this user') + '? They will be removed from your friends list.')
      if (!ok) return
    }
    await setUserBlocked(user, blocked)
    closeContextMenu()
  })
  item.classList.add('danger')
  menu.appendChild(item)
}

function openContextMenu (x, y, user) {
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'

  const open = menuItem('Open in new window', () => { openChatWindow('dm', user.username); closeContextMenu() })
  menu.appendChild(open)
  const dm = menuItem('Message', () => { openDm(user.username); closeContextMenu() })
  menu.appendChild(dm)
  const profile = menuItem('View profile', () => {
    window.msg.openExternal(new URL('/u/' + encodeURIComponent(user.username), LvApi.origin()).toString())
    closeContextMenu()
  })
  menu.appendChild(profile)
  menu.appendChild(menuSeparator())

  // Add-to-group section.
  const groups = state.groups
  if (groups.length) {
    const label = document.createElement('div')
    label.className = 'ctx-label'
    label.textContent = 'Add to group'
    menu.appendChild(label)
    for (const g of groups) {
      const already = (g.members || []).some((m) => String(m.id) === String(user.id))
      const item = menuItem((already ? '✓ ' : '') + g.name, async () => {
        if (!already) {
          await LvApi.postForm('/api/groups/member/add', { group_id: g.id, friend_id: user.id })
          await refreshBuddyData()
        }
        closeContextMenu()
      })
      item.classList.toggle('checked', already)
      menu.appendChild(item)
    }
  }
  const newGroup = menuItem('New group…', () => { closeContextMenu(); promptNewGroupFor(user) })
  menu.appendChild(newGroup)
  menu.appendChild(menuSeparator())

  addMuteBlockItems(menu, user)

  menu.appendChild(menuSeparator())

  const remove = menuItem('Remove friend', async () => {
    await LvApi.postForm('/api/friend/remove', { username: user.username })
    await refreshBuddyData()
    closeContextMenu()
  })
  remove.classList.add('danger')
  menu.appendChild(remove)

  showContextMenu(menu, x, y)
}

/* Right-click on a room row: open in a new window, share, browser, leave. */
function openRoomContextMenu (x, y, c) {
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'

  const open = menuItem('Open in new window', () => { openChatWindow('room', c.slug); closeContextMenu() })
  menu.appendChild(open)
  if (state.viewMode !== 'compact') {
    menu.appendChild(menuItem('Open here', () => { openRoom(c.slug); closeContextMenu() }))
  }
  menu.appendChild(menuSeparator())
  menu.appendChild(menuItem('Copy share link', async () => {
    await window.msg.copyText(new URL('/c/' + encodeURIComponent(c.slug), LvApi.origin()).toString())
    closeContextMenu()
  }))
  menu.appendChild(menuItem('Open in browser', () => {
    window.msg.openExternal(new URL('/app?channel=' + encodeURIComponent(c.slug), LvApi.origin()).toString())
    closeContextMenu()
  }))
  menu.appendChild(menuSeparator())
  const leave = menuItem('Leave room', async () => {
    closeContextMenu()
    const ok = await appConfirm('Leave #' + c.slug + '?')
    if (!ok) return
    await LvApi.postForm('/api/part', { channel: c.slug })
    await refreshBuddyData()
    await pollTick()
  })
  leave.classList.add('danger')
  menu.appendChild(leave)

  showContextMenu(menu, x, y)
}

/* Right-click on a group header: rename / delete / collapse. */
function openGroupContextMenu (x, y, g) {
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'

  if (!g.isUngrouped) {
    menu.appendChild(menuItem('Rename group', () => { closeContextMenu(); renameGroup(g) }))
    const del = menuItem('Delete group', () => { closeContextMenu(); deleteGroup(g) })
    del.classList.add('danger')
    menu.appendChild(del)
    menu.appendChild(menuSeparator())
  }

  const collapsed = state.collapsed.has('g:' + g.id)
  menu.appendChild(menuItem(collapsed ? 'Expand' : 'Collapse', () => {
    closeContextMenu()
    if (state.collapsed.has('g:' + g.id)) state.collapsed.delete('g:' + g.id)
    else state.collapsed.add('g:' + g.id)
    renderBuddyList()
  }))

  showContextMenu(menu, x, y)
}

/* Right-click on a message: copy the text, jump to the sender's profile. */
function openMessageContextMenu (x, y, m) {
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'

  menu.appendChild(menuItem('Copy message', async () => {
    await window.msg.copyText(String(m.content || ''))
    closeContextMenu()
  }))
  if (m.username) {
    menu.appendChild(menuItem('View ' + m.username + '\u2019s profile', () => {
      window.msg.openExternal(new URL('/u/' + encodeURIComponent(m.username), LvApi.origin()).toString())
      closeContextMenu()
    }))
  }

  showContextMenu(menu, x, y)
}

function openMemberContextMenu (x, y, user, group) {
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'
  menu.appendChild(menuItem('Open in new window', () => { openChatWindow('dm', user.username); closeContextMenu() }))
  const dm = menuItem('Message', () => { openDm(user.username); closeContextMenu() })
  menu.appendChild(dm)
  menu.appendChild(menuItem('View profile', () => {
    window.msg.openExternal(new URL('/u/' + encodeURIComponent(user.username), LvApi.origin()).toString())
    closeContextMenu()
  }))
  menu.appendChild(menuSeparator())
  addMuteBlockItems(menu, user)
  menu.appendChild(menuSeparator())
  const remove = menuItem('Remove from ' + group.name, async () => {
    await LvApi.postForm('/api/groups/member/remove', { group_id: group.id, friend_id: user.id })
    await refreshBuddyData()
    closeContextMenu()
  })
  remove.classList.add('danger')
  menu.appendChild(remove)
  showContextMenu(menu, x, y)
}

function menuItem (text, onClick) {
  const item = document.createElement('button')
  item.type = 'button'
  item.className = 'ctx-item'
  item.textContent = text
  item.addEventListener('click', onClick)
  return item
}

function menuSeparator () {
  const sep = document.createElement('div')
  sep.className = 'ctx-sep'
  return sep
}

function positionMenu (menu, x, y) {
  const rect = menu.getBoundingClientRect()
  const left = Math.min(x, window.innerWidth - rect.width - 8)
  const top = Math.min(y, window.innerHeight - rect.height - 8)
  menu.style.left = left + 'px'
  menu.style.top = top + 'px'
}

/* Close any open menu, then show + track this one. */
function showContextMenu (menu, x, y) {
  closeContextMenu()
  document.body.appendChild(menu)
  positionMenu(menu, x, y)
  ctxMenu = menu
}

/* ── Groups ───────────────────────────────────────────────── */

async function promptNewGroupFor (user) {
  const name = await appPrompt('New group name:', '', 'e.g. Friends')
  if (!name) return
  const r = await LvApi.postForm('/api/groups', { name })
  if (r.ok && r.body && r.body.group) {
    if (user) {
      await LvApi.postForm('/api/groups/member/add', { group_id: r.body.group.id, friend_id: user.id })
    }
    await refreshBuddyData()
  } else if (r.body && r.body.error) {
    await appAlert(r.body.error)
  }
}

async function renameGroup (g) {
  const name = await appPrompt('Rename group:', g.name, 'Group name')
  if (!name || name === g.name) return
  const r = await LvApi.postForm('/api/groups/rename', { id: g.id, name })
  if (r.ok) await refreshBuddyData()
  else if (r.body && r.body.error) await appAlert(r.body.error)
}

async function deleteGroup (g) {
  const ok = await appConfirm('Delete the "' + g.name + '" group? Contacts are not removed from your friends list.')
  if (!ok) return
  await LvApi.postForm('/api/groups/delete', { id: g.id })
  await refreshBuddyData()
}

/* ── Chat pane ────────────────────────────────────────────── */

function renderChat () {
  const open = state.open
  const title = $('#chat-title')
  const sub = $('#chat-sub')
  const composer = $('#composer')
  const membersBtn = $('#members-toggle')

  if (!open) {
    title.textContent = 'LVChat Messenger'
    sub.textContent = 'Pick a friend or room to start chatting'
    composer.hidden = true
    membersBtn.hidden = true
    $('#members').hidden = true
    $('#stream').replaceChildren()
    return
  }

  if (open.type === 'dm') {
    title.textContent = open.id
    const p = open.presence || {}
    const st = statusText(p)
    const label = p.status_mode ? statusLabel(p.status_mode) : (p.is_online ? 'Online' : (p.away ? 'Away' : 'Offline'))
    sub.textContent = label + (st ? ' — ' + st : '')
    membersBtn.hidden = true
    $('#members').hidden = true
  } else {
    title.textContent = '#' + open.id
    const online = open.members ? open.members.filter((m) => m.is_online).length : 0
    sub.textContent = (open.topic || '') + (open.topic ? ' · ' : '') + (open.members ? open.members.length : 0) + ' members · ' + online + ' online'
    membersBtn.hidden = false
    renderMembers()
  }
  composer.hidden = false
  renderStream()
}

function renderMembers () {
  const panel = $('#members')
  const open = state.open
  if (!open || open.type !== 'room') { panel.hidden = true; return }
  panel.replaceChildren()
  // Only members currently in the channel (online) belong in the Active list;
  // offline members are not present and are omitted, matching the web app.
  const members = (open.members || []).filter((m) => m.is_online)
  const t = document.createElement('div')
  t.className = 'members-title'
  t.textContent = 'Active members — ' + members.length
  panel.appendChild(t)
  for (const m of members) {
    const row = document.createElement('div')
    row.className = 'contact'
    row.title = contactTitle(m)
    const dot = document.createElement('div')
    dot.className = 'dot ' + statusClass(m)
    const avatar = avatarEl(m.username, m.avatar, '28px')
    const info = document.createElement('div')
    info.className = 'contact-name-col'
    const name = document.createElement('div')
    name.className = 'contact-name'
    name.textContent = m.username
    info.appendChild(name)
    const st = statusText(m)
    if (st) {
      const sub = document.createElement('div')
      sub.className = 'contact-status'
      sub.textContent = st
      info.appendChild(sub)
    }
    row.append(dot, avatar, info)
    row.addEventListener('click', () => openConversationOrWindow('dm', m.username))
    panel.appendChild(row)
  }
  if (!members.length) {
    const e = document.createElement('div')
    e.className = 'empty'
    e.textContent = 'No members online right now.'
    panel.appendChild(e)
  }
}

function isMine (m) {
  return state.me && String(m.sender_id) === String(state.me.id)
}

function renderStream () {
  const stream = $('#stream')
  stream.replaceChildren()
  let lastDate = ''
  for (const m of state.messages) {
    const date = String(m.created_at || '').slice(0, 10)
    if (date && date !== lastDate) {
      lastDate = date
      const sep = document.createElement('div')
      sep.className = 'date-sep'
      sep.textContent = formatDate(m.created_at)
      stream.appendChild(sep)
    }
    stream.appendChild(buildMessageEl(m))
  }
  scrollStream()
}

function buildMessageEl (m) {
  const kind = m.kind || 'message'
  const wrap = document.createElement('div')
  wrap.dataset.id = String(m.id)

  if (SYSTEM_KINDS.includes(kind)) {
    wrap.className = 'msg system'
    const body = document.createElement('div')
    body.className = 'msg-body'
    const c = document.createElement('div')
    c.className = 'msg-content'
    c.textContent = m.content || ''
    body.appendChild(c)
    wrap.appendChild(body)
    wrap.addEventListener('contextmenu', (e) => {
      e.preventDefault()
      openMessageContextMenu(e.clientX, e.clientY, m)
    })
    return wrap
  }

  const mine = isMine(m)
  wrap.className = 'msg' + (mine ? ' mine' : '') + (kind === 'action' ? ' action' : '') + (m.pending ? ' pending' : '')

  const avatar = avatarEl(m.username, friendAvatar(m.username), '30px')
  const body = document.createElement('div')
  body.className = 'msg-body'

  const meta = document.createElement('div')
  meta.className = 'msg-meta'
  const author = document.createElement('span')
  author.className = 'msg-author'
  author.textContent = m.username || 'unknown'
  const time = document.createElement('span')
  time.className = 'msg-time'
  time.textContent = formatTime(m.created_at)
  meta.append(author, time)
  if (m.pending) {
    const pend = document.createElement('span')
    pend.className = 'msg-pending'
    pend.textContent = 'Pending'
    meta.appendChild(pend)
  }
  body.appendChild(meta)

  const bubble = document.createElement('div')
  if (mine) bubble.className = 'bubble'
  bubble.appendChild(contentEl(kind, m.content))
  body.appendChild(bubble)

  wrap.append(mine ? body : avatar, mine ? avatar : body)
  wrap.addEventListener('contextmenu', (e) => {
    e.preventDefault()
    openMessageContextMenu(e.clientX, e.clientY, m)
  })
  return wrap
}

function contentEl (kind, content) {
  const div = document.createElement('div')
  div.className = 'msg-content'
  const lines = String(content || '').split('\n')

  if (kind === 'gif' || kind === 'image') {
    const url = (lines[0] || '').trim()
    const caption = lines.slice(1).join(' ').trim()
    if (url) {
      const img = document.createElement('img')
      img.className = 'media'
      img.src = LvApi.abs(url)
      img.alt = caption || 'image'
      img.loading = 'lazy'
      div.appendChild(img)
    }
    if (caption) {
      const cap = document.createElement('div')
      cap.className = 'caption'
      cap.textContent = caption
      div.appendChild(cap)
    }
    return div
  }

  // Plain text with @mention highlighting + autolinked URLs.
  const text = document.createElement('span')
  text.textContent = lines.join('\n')
  text.innerHTML = renderMarkup(text.textContent)
  div.appendChild(text)
  return div
}

/* Escape → highlight @mentions → autolink URLs (order matches the web app). */
function renderMarkup (text) {
  let html = esc(text)
  html = html.replace(/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/g, '<span class="mention">@$1</span>')
  html = html.replace(/(https?:\/\/[^\s<]+)/g, (url) => {
    return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>'
  })
  return html
}

function scrollStream () {
  const stream = $('#stream')
  requestAnimationFrame(() => { stream.scrollTop = stream.scrollHeight })
}

function formatTime (ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T') + 'Z')
  if (isNaN(d.getTime())) return ''
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatDate (ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T') + 'Z')
  if (isNaN(d.getTime())) return ''
  const today = new Date()
  if (d.toDateString() === today.toDateString()) return 'Today'
  return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
}

/* ── @mention autocomplete ────────────────────────────────── */

const MENTION_RE = /(?:^|\s)@([^\s]*)$/

let mentionIndex = 0

/* Pool of taggable users: friends + grouped contacts + open room members +
 * recent DM partners + me, deduped by username (online first). */
function mentionPool () {
  const seen = new Set()
  const out = []
  const add = (user) => {
    const name = user && user.username
    const key = name ? String(name).toLowerCase() : ''
    if (!key || seen.has(key)) return
    seen.add(key)
    out.push({ username: String(name), is_online: user.is_online ? 1 : 0, avatar: user.avatar || null })
  }
  for (const f of state.friends) add(f)
  for (const g of state.groups) for (const m of (g.members || [])) add(m)
  if (state.open && state.open.members) for (const m of state.open.members) add(m)
  for (const d of state.dmList) add(d)
  if (state.me) add(Object.assign({ username: state.me.username, is_online: 1 }, state.me))
  return out.sort((a, b) => b.is_online - a.is_online || String(a.username).localeCompare(String(b.username)))
}

function showMentionAc (query) {
  const ac = $('#mention-ac')
  const q = String(query || '').toLowerCase()
  const matches = mentionPool().filter((u) => !q || String(u.username).toLowerCase().startsWith(q)).slice(0, 25)
  ac.replaceChildren()
  if (!matches.length) {
    const empty = document.createElement('div')
    empty.className = 'ma-empty'
    empty.textContent = 'No matching users'
    ac.appendChild(empty)
    ac.hidden = false
    mentionIndex = 0
    return
  }
  mentionIndex = 0
  matches.forEach((u, i) => {
    const item = document.createElement('button')
    item.type = 'button'
    item.className = 'ma-item' + (i === 0 ? ' selected' : '')
    const dot = document.createElement('span')
    dot.className = 'dot ' + (u.is_online ? 'online' : 'offline')
    const name = document.createElement('span')
    name.className = 'ma-name'
    name.textContent = '@' + u.username
    item.append(dot, name)
    item.addEventListener('click', () => insertMention(u.username))
    ac.appendChild(item)
  })
  ac.hidden = false
  markMentionSelected()
}

function markMentionSelected () {
  const ac = $('#mention-ac')
  const items = ac.querySelectorAll('.ma-item')
  items.forEach((el, i) => el.classList.toggle('selected', i === mentionIndex))
}

function moveMentionAc (d) {
  const items = $('#mention-ac').querySelectorAll('.ma-item')
  if (!items.length) return
  mentionIndex = (mentionIndex + d + items.length) % items.length
  markMentionSelected()
  const sel = items[mentionIndex]
  if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' })
}

function hideMentionAc () {
  $('#mention-ac').hidden = true
  $('#mention-ac').replaceChildren()
}

function insertMention (name) {
  const input = $('#composer-input')
  const v = input.value
  const sel = input.selectionStart
  const before = v.slice(0, sel)
  const m = before.match(MENTION_RE)
  if (m) {
    const atPos = sel - m[1].length - 1
    input.value = v.slice(0, atPos) + '@' + name + ' ' + v.slice(sel)
    const pos = atPos + name.length + 2
    input.setSelectionRange(pos, pos)
  }
  hideMentionAc()
  input.focus()
}

function mentionAcOpen () {
  return !$('#mention-ac').hidden
}

/* ── Offline message queue ──────────────────────────────────
 * While the messenger has no connection, messages the user sends (text, GIF or
 * image) are shown immediately as "Pending" and queued in localStorage (per
 * profile). On reconnect the queue is flushed in order — each message is only
 * dropped once the server accepts it — so nothing sent while offline is lost,
 * and messages delivered to the server while the user was away are pulled in by
 * the normal poll. */

function queueStorageKey () {
  return 'lvcmsg.offline.queue.' + (state.profile ? state.profile.id : '')
}

function loadSendQueue () {
  try {
    state.sendQueue = JSON.parse(localStorage.getItem(queueStorageKey()) || '[]') || []
  } catch (err) {
    state.sendQueue = []
  }
}

function persistSendQueue () {
  try {
    localStorage.setItem(queueStorageKey(), JSON.stringify(state.sendQueue))
  } catch (err) { /* quota exceeded / storage unavailable — keep going */ }
}

function setOffline (off) {
  off = !!off
  if (state.offline === off) return
  state.offline = off
  const banner = $('#offline-banner')
  if (banner) banner.hidden = !off
  updateMeStatus()
  if (!off) flushSendQueue()
}

/* Reflect the caller's chosen status (or connectivity) in the sidebar header. */
function updateMeStatus () {
  const el = $('#me-status')
  if (!el) return
  el.classList.remove('online', 'away', 'dnd', 'offline')
  let mode = (state.me && state.me.status_mode) || 'online'
  if (state.offline) mode = 'invisible'
  el.textContent = state.offline ? 'Offline' : statusLabel(mode) + (statusText(state.me) ? ' — ' + statusText(state.me) : '')
  el.classList.add(mode === 'invisible' ? 'offline' : (mode === 'dnd' ? 'dnd' : (mode === 'away' || mode === 'custom' ? 'away' : 'online')))
  const dot = $('#me-avatar .avatar-status')
  if (dot) {
    dot.classList.remove('online', 'away', 'dnd', 'offline')
    dot.classList.add(state.offline ? 'offline' : statusDotClass(mode))
  }
}

function openStatusMenu () {
  const menu = $('#status-menu')
  if (!menu) return
  menu.replaceChildren()
  const modes = [
    ['online', 'Online'],
    ['away', 'Away'],
    ['dnd', 'Do Not Disturb'],
    ['invisible', 'Appear Offline'],
    ['custom', 'Custom status…']
  ]
  const current = (state.me && state.me.status_mode) || 'online'
  for (const [mode, label] of modes) {
    const b = document.createElement('button')
    b.type = 'button'
    b.className = 'ctx-item' + (mode === current ? ' checked' : '')
    b.textContent = label
    b.addEventListener('click', () => {
      menu.hidden = true
      if (mode === 'custom') setCustomStatus()
      else setMyStatus(mode)
    })
    menu.appendChild(b)
  }
  menu.hidden = false
}

async function setMyStatus (mode, custom) {
  const body = { status_mode: mode }
  if (custom != null) body.custom_status = custom
  const r = await LvApi.postForm('/api/status', body)
  if (r.ok && r.body && r.body.status) {
    const s = r.body.status
    if (state.me) {
      state.me.status_mode = s.status_mode
      state.me.custom_status = s.custom_status
      state.me.away = s.away
      state.me.dnd = s.dnd
      state.me.invisible = s.invisible
      state.me.is_online = s.is_online
    }
    updateMeStatus()
  } else {
    await appAlert((r.body && r.body.error) || 'Could not update your status.')
  }
}

async function setCustomStatus () {
  const text = await appPrompt('Custom status:', state.me && state.me.custom_status ? state.me.custom_status : '', 'e.g. busy, streaming, sleeping…')
  if (text === null) return
  setMyStatus('custom', text)
}

function fileToDataUrl (file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader()
    r.onload = () => resolve(String(r.result))
    r.onerror = () => reject(r.error)
    r.readAsDataURL(file)
  })
}

function dataUrlToFile (dataUrl, name) {
  return new Promise((resolve, reject) => {
    try {
      const parts = String(dataUrl).split(',')
      const mime = /^data:([^;]+);/.exec(parts[0])
      const bin = atob(parts[1] || '')
      const buf = new Uint8Array(bin.length)
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i)
      resolve(new File([buf], name || 'image.png', { type: mime ? mime[1] : 'image/png' }))
    } catch (err) {
      reject(err)
    }
  })
}

/* Keep pending (unsent) messages at the end of the stream; everything else is
 * ordered by server id. */
function sortMessages () {
  state.messages.sort((a, b) => {
    const ap = a.pending ? 1 : 0
    const bp = b.pending ? 1 : 0
    if (ap !== bp) return ap - bp
    return Number(a.id) - Number(b.id)
  })
}

function pendingMsg (desc, localId) {
  return {
    id: -localId,
    pending: true,
    kind: desc.kind,
    content: desc.content || '',
    created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    username: state.me ? state.me.username : 'me',
    sender_id: state.me ? state.me.id : 0,
    role: 'user',
    guest: 0,
    is_pm: state.open ? state.open.type === 'dm' : false,
    channel: state.open && state.open.type === 'room' ? state.open.id : undefined
  }
}

function removePending (localId) {
  const idx = state.messages.findIndex((m) => m.id === -localId)
  if (idx !== -1) {
    state.messages.splice(idx, 1)
    renderStream()
  }
}

/* Post a message (or upload an image). Returns the LvApi result object; a
 * network failure surfaces as { status: 0 }. */
async function postFromDesc (desc) {
  if (desc.kind === 'image') {
    if (!desc.image) return { status: 0 }
    const file = await dataUrlToFile(desc.image, desc.fileName)
    const fd = new FormData()
    fd.set('file', file, file.name)
    if (desc.payload.recipient) fd.set('dm', desc.payload.recipient)
    else fd.set('channel', desc.payload.channel)
    return LvApi.upload('/api/upload', fd)
  }
  return LvApi.postForm('/api/send', desc.payload)
}

/* Show the message as pending, then deliver it now if we're online (removing
 * the pending bubble once the server confirms) or queue it if we're offline. */
async function deliverOrQueue (desc) {
  if (!state.open) return false
  const localId = ++state._localId
  if (desc.kind === 'gif') {
    desc.content = desc.payload.gif_url + (desc.payload.gif_title ? '\n' + desc.payload.gif_title : '')
  } else if (desc.kind === 'image') {
    desc.content = desc.image || ''
  } else {
    desc.content = desc.payload.content || ''
  }
  state.messages.push(pendingMsg(desc, localId))
  sortMessages()
  renderStream()
  scrollStream()

  if (state.offline || navigator.onLine === false) {
    enqueueSend(desc, localId)
    return false
  }
  const r = await postFromDesc(desc)
  if (r.status === 0) {
    enqueueSend(desc, localId)
    setOffline(true)
    return false
  }
  if (r.ok && r.body && r.body.message) {
    removePending(localId)
    applyMessages([r.body.message])
    return true
  }
  removePending(localId)
  await appAlert((r.body && r.body.error) || 'Message failed to send.')
  return false
}

function enqueueSend (desc, localId) {
  const item = { id: localId, at: Date.now(), payload: desc.payload }
  if (desc.kind === 'image' && desc.image) {
    item.payload._image = desc.image
    item.payload._fileName = desc.fileName
  }
  state.sendQueue.push(item)
  persistSendQueue()
  setOffline(true)
}

/* Flush the queue in order on reconnect. A network blip keeps the item queued;
 * a server rejection drops it after surfacing the error. */
async function flushSendQueue () {
  if (state.flushing || !state.sendQueue.length || navigator.onLine === false) return
  state.flushing = true
  try {
    while (state.sendQueue.length) {
      const item = state.sendQueue[0]
      let r
      if (item.payload._image) {
        try {
          r = await postFromDesc({ kind: 'image', image: item.payload._image, fileName: item.payload._fileName, payload: item.payload })
        } catch (err) {
          r = { status: 0 }
        }
      } else {
        r = await postFromDesc({ kind: 'message', payload: item.payload })
      }
      if (r.status === 0) {
        setOffline(true)
        return
      }
      state.sendQueue.shift()
      persistSendQueue()
      if (r.ok && r.body && r.body.message) {
        removePending(item.id)
        const sentTo = item.payload.recipient || item.payload.channel
        if (state.open && sentTo && String(state.open.id).toLowerCase() === String(sentTo).toLowerCase()) {
          applyMessages([r.body.message])
        }
      } else if (r.body && r.body.error) {
        removePending(item.id)
        await appAlert('A queued message could not be delivered: ' + r.body.error)
      }
    }
    setOffline(false)
  } finally {
    state.flushing = false
  }
}

/* ── Composer ─────────────────────────────────────────────── */

async function sendMessage (content) {
  if (!state.open) return
  const trimmed = content.trim()
  if (!trimmed) return
  const data = { content: trimmed }
  if (state.open.type === 'dm') data.recipient = state.open.id
  else data.channel = state.open.id
  await deliverOrQueue({ kind: 'message', payload: data })
}

async function sendGif (gif) {
  if (!state.open) return
  const data = { gif_url: gif.url, gif_title: gif.title || '' }
  if (state.open.type === 'dm') data.recipient = state.open.id
  else data.channel = state.open.id
  const ok = await deliverOrQueue({ kind: 'gif', payload: data })
  if (ok) hidePickers()
}

async function sendImage (file) {
  if (!state.open || !file) return
  let dataUrl = null
  try {
    dataUrl = await fileToDataUrl(file)
  } catch (err) { /* fall through with null — pending bubble shows a placeholder */ }
  const payload = {}
  if (state.open.type === 'dm') payload.recipient = state.open.id
  else payload.channel = state.open.id
  await deliverOrQueue({ kind: 'image', image: dataUrl, fileName: file.name || 'image.png', payload })
}

function hidePickers () {
  $('#emoji-panel').hidden = true
  $('#gif-panel').hidden = true
}

async function loadGifs (q) {
  const grid = $('#gif-grid')
  grid.replaceChildren()
  if (!q) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = 'Type to search GIPHY…'
    grid.appendChild(note)
    return
  }
  const j = await LvApi.getJson('/api/gifs?q=' + encodeURIComponent(q) + '&limit=20')
  if (!j.ok) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = (j.body && j.body.error) || 'GIF search is unavailable.'
    grid.appendChild(note)
    return
  }
  const gifs = j.body.gifs || []
  if (!gifs.length) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = 'No GIFs found.'
    grid.appendChild(note)
    return
  }
  for (const g of gifs) {
    const item = document.createElement('div')
    item.className = 'gif-item'
    const img = document.createElement('img')
    img.src = g.preview || g.url
    img.loading = 'lazy'
    img.title = g.title || ''
    item.appendChild(img)
    item.addEventListener('click', () => sendGif(g))
    grid.appendChild(item)
  }
}

/* ── View mode (Compact / Advanced) ───────────────────────── */

async function applyViewMode () {
  let mode = await window.msg.prefsGet('viewMode')
  if (mode !== 'compact' && mode !== 'advanced') mode = 'compact'
  state.viewMode = mode
  document.body.classList.remove('compact', 'advanced')
  if (state.chatWindow) {
    document.body.classList.add('chat-window')
  } else {
    document.body.classList.add(mode)
    window.msg.setCompact(mode === 'compact')
  }
  updateViewModeButton()
}

function updateViewModeButton () {
  const btn = $('#view-mode-btn')
  if (!btn) return
  if (state.viewMode === 'compact') {
    btn.textContent = '▦'
    btn.title = 'Switch to Advanced view'
  } else {
    btn.textContent = '◧'
    btn.title = 'Switch to Compact view'
  }
}

async function toggleViewMode () {
  const next = state.viewMode === 'compact' ? 'advanced' : 'compact'
  state.viewMode = next
  document.body.classList.remove('compact', 'advanced')
  document.body.classList.add(next)
  await window.msg.prefsSet('viewMode', next)
  if (!state.chatWindow) window.msg.setCompact(next === 'compact')
  updateViewModeButton()
  renderAll()
}

/* ── Hamburger menu (narrow sidebar) ──────────────────────── */

function headMenuLabel (p) {
  return p.username ? `@${p.username} — ${p.name}` : p.name
}

async function switchProfile (profile) {
  const ok = await appConfirm(`Switch to ${headMenuLabel(profile)}?`)
  if (!ok) return
  const r = await window.msg.switchProfile({ id: profile.id })
  if (!r || !r.ok) await appAlert((r && r.error) || 'Could not switch accounts.')
}

async function toggleHeadMenu (force) {
  const menu = $('#head-menu')
  const willShow = force !== undefined ? !!force : menu.hidden
  if (!willShow) { menu.hidden = true; return }
  menu.replaceChildren()
  const add = (text, onClick, danger) => {
    const b = document.createElement('button')
    b.type = 'button'
    b.className = 'ctx-item' + (danger ? ' danger' : '')
    b.textContent = text
    b.addEventListener('click', () => { menu.hidden = true; onClick() })
    menu.appendChild(b)
  }
  add(state.viewMode === 'compact' ? 'Switch to Advanced view' : 'Switch to Compact view', toggleViewMode)
  add('Toggle light / dark theme', toggleTheme)

  // Account switching: every other saved profile. Same-server accounts make
  // this the way to hop between sessions without signing out first.
  let profiles = []
  try {
    const data = await window.msg.listProfiles()
    profiles = (data && data.profiles) || []
  } catch (err) { /* ignore */ }
  const others = profiles.filter((p) => p.id !== state.profile.id)
  if (others.length) {
    const label = document.createElement('div')
    label.className = 'ctx-label'
    label.textContent = 'Switch account'
    menu.appendChild(label)
    for (const p of others) {
      const b = document.createElement('button')
      b.type = 'button'
      b.className = 'ctx-item'
      b.textContent = headMenuLabel(p)
      b.addEventListener('click', () => { menu.hidden = true; switchProfile(p) })
      menu.appendChild(b)
    }
  }

  add('Profile manager', () => window.msg.showLauncher())
  add('Settings', openSettings)
  add('Sign out', doLogout, true)
  add('Quit LVChat Messenger', () => window.msg.quit(), true)
  menu.hidden = false
}

/* ── Settings (notification + sound preferences) ───────────── */

/* Notifications preference store. The server is authoritative when it answers;
 * these local copies keep the toggles working on servers that haven't deployed
 * the push-prefs endpoint yet. */
function notifyPrefsKey () {
  return 'lvcmsg.notify.' + (state.profile ? state.profile.id : '')
}

function loadLocalNotifyPrefs () {
  try {
    const j = JSON.parse(localStorage.getItem(notifyPrefsKey()) || '{}')
    return {
      channels: j.channels === 0 ? 0 : 1,
      dms: j.dms === 0 ? 0 : 1,
      invites: j.invites === 0 ? 0 : 1
    }
  } catch (err) {
    return { channels: 1, dms: 1, invites: 1 }
  }
}

function persistLocalNotifyPrefs (p) {
  try {
    localStorage.setItem(notifyPrefsKey(), JSON.stringify({ channels: p.channels, dms: p.dms, invites: p.invites }))
  } catch (err) { /* ignore */ }
}

function fillSoundSelect (select, chosenId) {
  const cur = String(select.value || '0')
  select.replaceChildren()
  const off = document.createElement('option')
  off.value = '0'
  off.textContent = 'Off'
  select.appendChild(off)
  const list = (state.sounds && state.sounds.list) || {}
  for (const id of Object.keys(list)) {
    const opt = document.createElement('option')
    opt.value = id
    opt.textContent = list[id].name || 'Sound ' + id
    select.appendChild(opt)
  }
  const wanted = chosenId != null ? String(chosenId) : cur
  select.value = list[wanted] ? wanted : (list[cur] ? cur : '0')
  if (!list[select.value]) select.value = '0'
}

function closeSettings () {
  $('#settings-modal').hidden = true
}

/* Whether the messenger is running on Windows (for OS notification guidance). */
function isWindows () {
  try { return /win/i.test(String(window.msg.platform)) } catch (err) { return /win/i.test(navigator.platform) }
}

function openSettings () {
  $('#set-notify-dms').checked = notif.prefs.dms === 1
  $('#set-notify-channels').checked = notif.prefs.channels === 1
  $('#set-notify-invites').checked = notif.prefs.invites === 1
  $('#settings-status').hidden = true
  // Windows toasts depend on an OS-level toggle we can't read — always point
  // the user at the right place.
  const winHint = $('#win-notify-hint')
  if (winHint) winHint.hidden = !isWindows()

  const sounds = state.sounds
  const dmOn = !!sounds && sounds.dm != null
  const chOn = !!sounds && sounds.channel != null
  $('#set-sound-dm-on').checked = dmOn
  $('#set-sound-channel-on').checked = chOn
  fillSoundSelect($('#set-sound-dm'), sounds ? sounds.dm : null)
  fillSoundSelect($('#set-sound-channel'), sounds ? sounds.channel : null)
  $('#set-sound-dm').disabled = !dmOn
  $('#set-sound-channel').disabled = !chOn
  $('#settings-modal').hidden = false
}

async function saveNotifyPrefs () {
  const prefs = {
    channels: $('#set-notify-channels').checked ? 1 : 0,
    dms: $('#set-notify-dms').checked ? 1 : 0,
    invites: $('#set-notify-invites').checked ? 1 : 0
  }
  notif.prefs = prefs
  persistLocalNotifyPrefs(prefs)
  try {
    await LvApi.postForm('/api/push/prefs', { channels: String(prefs.channels), dms: String(prefs.dms), invites: String(prefs.invites) })
  } catch (err) { /* server endpoint unavailable — local prefs stand */ }
}

async function saveSoundPrefs () {
  const dmOn = $('#set-sound-dm-on').checked
  const chOn = $('#set-sound-channel-on').checked
  const dmVal = dmOn ? $('#set-sound-dm').value : '0'
  const chVal = chOn ? $('#set-sound-channel').value : '0'
  $('#set-sound-dm').disabled = !dmOn
  $('#set-sound-channel').disabled = !chOn
  const dm = dmOn && dmVal !== '0' ? dmVal : null
  const channel = chOn && chVal !== '0' ? chVal : null
  if (state.sounds) {
    state.sounds.dm = dm
    state.sounds.channel = channel
  }
  persistLocalSoundPrefs(dm, channel)
  try {
    await LvApi.postForm('/api/sound/prefs', { dm_sound: dmVal, channel_sound: chVal })
  } catch (err) { /* server endpoint unavailable — local prefs stand */ }
}

/* ── Resizable sidebar ────────────────────────────────────── */

/* Clamp + apply the list width via a CSS variable. */
function applySidebarWidth (w) {
  const windowW = window.innerWidth
  const clamped = Math.max(180, Math.min(560, Math.round(w), windowW - 40))
  document.body.style.setProperty('--sidebar-width', clamped + 'px')
  return clamped
}

async function initSidebarResize () {
  const saved = await window.msg.prefsGet('sidebarWidth')
  if (typeof saved === 'number' && saved > 0) applySidebarWidth(saved)

  const handle = $('#sidebar-resizer')
  if (!handle) return
  let dragging = false
  handle.addEventListener('mousedown', (e) => {
    e.preventDefault()
    dragging = true
    document.body.classList.add('resizing')
  })
  document.addEventListener('mousemove', (e) => {
    if (!dragging) return
    const rect = $('#sidebar').getBoundingClientRect()
    applySidebarWidth(e.clientX - rect.left)
  })
  document.addEventListener('mouseup', async () => {
    if (!dragging) return
    dragging = false
    document.body.classList.remove('resizing')
    const width = parseFloat(getComputedStyle($('#sidebar')).width)
    if (Number.isFinite(width)) await window.msg.prefsSet('sidebarWidth', Math.round(width))
  })
}

/* ── Theme ────────────────────────────────────────────────── */

async function applyTheme () {
  let theme = await window.msg.prefsGet('theme')
  if (theme !== 'light' && theme !== 'dark') theme = 'dark'
  document.body.classList.remove('theme-light', 'theme-dark')
  document.body.classList.add('theme-' + theme)
}

async function toggleTheme () {
  const isLight = document.body.classList.contains('theme-light')
  const next = isLight ? 'dark' : 'light'
  document.body.classList.remove('theme-light', 'theme-dark')
  document.body.classList.add('theme-' + next)
  await window.msg.prefsSet('theme', next)
}

/* ── Wiring ───────────────────────────────────────────────── */

function setTab (tab) {
  state.tab = tab
  renderSidebar()
}

function wireEvents () {
  $('#login-form').addEventListener('submit', (e) => {
    e.preventDefault()
    const username = $('#login-username').value.trim()
    const password = $('#login-password').value
    const save = $('#login-save').checked
    if (!username || !password) {
      showLoginError('Enter your username and password.')
      return
    }
    attemptLogin(username, password, save)
  })

  $('#login-server').addEventListener('change', () => {
    const url = $('#login-server').value.trim()
    if (url) {
      try {
        const origin = new URL(url).origin
        state.profile = Object.assign({}, state.profile, { url })
        LvApi.init(origin)
        $('#login-server-url').textContent = origin
      } catch (err) { /* keep existing */ }
    }
  })

  $('#login-forgot').addEventListener('click', (e) => {
    e.preventDefault()
    window.msg.openExternal(new URL('/forgot-password', LvApi.origin()).toString())
  })
  $('#login-register').addEventListener('click', (e) => {
    e.preventDefault()
    window.msg.openExternal(new URL('/register', LvApi.origin()).toString())
  })

  $('#mfa-form').addEventListener('submit', (e) => {
    e.preventDefault()
    verifyMfa()
  })
  $('#mfa-back').addEventListener('click', (e) => {
    e.preventDefault()
    showView('login')
  })

  $('#modal-ok').addEventListener('click', () => {
    const input = $('#modal-input')
    const value = !input.hidden ? input.value : true
    settleModal(value)
  })
  $('#modal-cancel').addEventListener('click', () => settleModal(null))
  $('#modal-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') $('#modal-ok').click()
    if (e.key === 'Escape') $('#modal-cancel').click()
  })

  $('#theme-toggle').addEventListener('click', toggleTheme)
  $('#me-status').addEventListener('click', (e) => {
    e.stopPropagation()
    $('#head-menu').hidden = true
    const menu = $('#status-menu')
    if (menu.hidden) openStatusMenu()
    else menu.hidden = true
  })
  $('#me-avatar').addEventListener('click', (e) => {
    e.stopPropagation()
    $('#head-menu').hidden = true
    const menu = $('#status-menu')
    if (menu.hidden) openStatusMenu()
    else menu.hidden = true
  })
  $('#view-mode-btn').addEventListener('click', toggleViewMode)
  $('#profile-manager').addEventListener('click', () => window.msg.showLauncher())
  $('#logout-btn').addEventListener('click', doLogout)
  $('#menu-btn').addEventListener('click', (e) => {
    e.stopPropagation()
    toggleHeadMenu()
  })

  // Settings modal.
  $('#settings-close').addEventListener('click', closeSettings)
  $('#settings-modal .modal-backdrop').addEventListener('click', closeSettings)
  $('#win-notify-settings').addEventListener('click', () => {
    try { window.msg.openExternal('ms-settings:notifications') } catch (err) { /* bridge missing */ }
  })
  $('#set-notify-dms').addEventListener('change', saveNotifyPrefs)
  $('#set-notify-channels').addEventListener('change', saveNotifyPrefs)
  $('#set-notify-invites').addEventListener('change', saveNotifyPrefs)
  $('#set-sound-dm-on').addEventListener('change', () => {
    $('#set-sound-dm').disabled = !$('#set-sound-dm-on').checked
    saveSoundPrefs()
  })
  $('#set-sound-channel-on').addEventListener('change', () => {
    $('#set-sound-channel').disabled = !$('#set-sound-channel-on').checked
    saveSoundPrefs()
  })
  $('#set-sound-dm').addEventListener('change', saveSoundPrefs)
  $('#set-sound-channel').addEventListener('change', saveSoundPrefs)
  $('#set-notify-test').addEventListener('click', async () => {
    const status = $('#settings-status')
    status.hidden = false
    status.textContent = 'Sending…'
    try {
      const r = await window.msg.testNotification()
      const stats = await window.msg.notifyStats()
      const state = (r && r.state) || ''
      if (r && r.ok === false && (state === 'unsupported' || (stats && stats.supported === false))) {
        status.textContent = isWindows()
          ? 'Notifications are not supported on this system. Install via the Setup installer so they can be registered, then enable them in Windows notification settings.'
          : 'Notifications are not supported on this system.'
      } else if (r && r.ok === false && state.startsWith('failed')) {
        status.textContent = 'Notification failed: ' + state.slice('failed: '.length)
      } else if (r && r.ok === false && state.startsWith('error')) {
        status.textContent = 'Notification error: ' + state.slice('error: '.length)
      } else {
        status.textContent = 'Notification sent — check your operating system.'
        if (isWindows()) {
          status.textContent += ' On Windows, if you didn’t see it, open the notification settings (below) and make sure notifications are enabled for LVChat Messenger.'
        }
      }
    } catch (err) {
      status.textContent = 'Could not send a test notification.'
    }
  })
  $('#set-sound-test').addEventListener('click', () => {
    const id = $('#set-sound-dm-on').checked ? $('#set-sound-dm').value : '0'
    if (id && id !== '0') playSound(id)
  })

  $('#tab-buddy').addEventListener('click', () => setTab('buddy'))
  $('#tab-rooms').addEventListener('click', () => setTab('rooms'))
  $('#tab-requests').addEventListener('click', () => setTab('requests'))

  $('#btn-new-group').addEventListener('click', () => promptNewGroupFor(null))

  $('#directory-search').addEventListener('input', debounce(() => {
    renderSidebar()
    runDirectorySearch()
  }, 300))

  $('#members-toggle').addEventListener('click', () => {
    const panel = $('#members')
    panel.hidden = !panel.hidden
    if (!panel.hidden) renderMembers()
  })

  // Composer
  const input = $('#composer-input')
  input.addEventListener('input', () => {
    const sel = input.selectionStart
    const m = input.value.slice(0, sel).match(MENTION_RE)
    if (m) showMentionAc(m[1])
    else hideMentionAc()
  })
  input.addEventListener('keydown', (e) => {
    if (mentionAcOpen()) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionAc(1); return }
      if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionAc(-1); return }
      if (e.key === 'Tab' || e.key === 'Enter') {
        e.preventDefault()
        const items = $('#mention-ac').querySelectorAll('.ma-item')
        if (items[mentionIndex]) items[mentionIndex].click()
        return
      }
      if (e.key === 'Escape') { hideMentionAc(); return }
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      const content = input.value
      input.value = ''
      hideMentionAc()
      sendMessage(content)
    }
  })
  $('#composer-send').addEventListener('click', () => {
    const content = input.value
    input.value = ''
    hideMentionAc()
    sendMessage(content)
  })

  $('#btn-emoji').addEventListener('click', () => {
    const panel = $('#emoji-panel')
    const willShow = panel.hidden
    hidePickers()
    if (willShow) {
      panel.hidden = false
      if (!panel.dataset.built) {
        panel.dataset.built = '1'
        for (const e of window.EMOJIS) {
          const b = document.createElement('button')
          b.type = 'button'
          b.textContent = e
          b.addEventListener('click', () => {
            input.value += e
            input.focus()
          })
          panel.appendChild(b)
        }
      }
    }
  })

  $('#btn-gif').addEventListener('click', () => {
    const panel = $('#gif-panel')
    const willShow = panel.hidden
    hidePickers()
    if (willShow) {
      panel.hidden = false
      loadGifs($('#gif-search-input').value.trim())
      $('#gif-search-input').focus()
    }
  })
  $('#gif-search-input').addEventListener('input', debounce(() => {
    loadGifs($('#gif-search-input').value.trim())
  }, 350))

  $('#btn-image').addEventListener('click', () => $('#image-file').click())
  $('#image-file').addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0]
    if (file) sendImage(file)
    e.target.value = ''
  })

  document.addEventListener('click', (e) => {
    if (ctxMenu && !e.target.closest('.ctx-menu')) closeContextMenu()
    if (!e.target.closest('#head-menu') && !e.target.closest('#menu-btn')) $('#head-menu').hidden = true
    if (!e.target.closest('#status-menu') && !e.target.closest('#me-status')) $('#status-menu').hidden = true
    if (!e.target.closest('#emoji-panel') && !e.target.closest('#btn-emoji')) $('#emoji-panel').hidden = true
    if (!e.target.closest('#gif-panel') && !e.target.closest('#btn-gif') && !e.target.closest('#gif-search-input')) $('#gif-panel').hidden = true
    if (!e.target.closest('#mention-ac') && !e.target.closest('#composer-input')) hideMentionAc()
  })
}

wireEvents()

function wireOpenConversation () {
  try {
    if (window.msg.onOpenConversation) {
      window.msg.onOpenConversation((conv) => {
        if (conv && conv.type && conv.id) openConversationOrWindow(conv.type, conv.id)
      })
    }
  } catch (err) { /* bridge missing */ }
}

wireOpenConversation()
boot()

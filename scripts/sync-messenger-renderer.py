#!/usr/bin/env python3
"""Port the messenger Phase-D notification overhaul to the Electron renderer copy.
Applies the same string replacements used on messenger-web/src/messenger.js, and
reports any that don't match so they can be handled manually."""

P = 'lvchat-messenger/renderer/messenger.js'
s = open(P).read()

edits = []

# 1. state: pendingJumpId
edits.append((
  "  sounds: null, // { list:{id:{name,url}}, dm:id|null, channel:id|null, overrides:{uid:id|null} }\n  audioEl: null\n}",
  "  sounds: null, // { list:{id:{name,url}}, dm:id|null, channel:id|null, overrides:{uid:id|null} }\n  audioEl: null,\n  pendingJumpId: 0 // message id to highlight after a notification click\n}"
))

# 2. notif object
edits.append((
  "const notif = {\n  prefs: { channels: 1, dms: 1, invites: 1 },\n  seeded: false, // poll-derived alerts seeded (pre-existing state must not alert)\n  feedSeeded: false, // /api/notifications feed seeded\n  bgMax: 0, // highest background message id seen\n  prevDm: {}, // username(lower) -> unread\n  feedSeen: new Set(), // notification ids already surfaced\n  feedTimer: null\n}",
  "const notif = {\n  prefs: { channels: 1, dms: 1, invites: 1 },\n  notifyPrefs: null, // unified prefs (masters / quiet hours / keywords / previews)\n  seeded: false, // poll-derived alerts seeded (pre-existing state must not alert)\n  deltaSeeded: false, // unified `alerts` delta seeded\n  hasDelta: false, // the server sends the unified alerts delta\n  feedSeeded: false, // /api/notifications feed seeded\n  bgMax: 0, // highest background message id seen\n  prevDm: {}, // username(lower) -> unread\n  feedSeen: new Set(), // notification ids already surfaced\n  feedItems: [], // notifications feed (activity panel)\n  feedTimer: null\n}"
))

# 3. checkAlerts unified routing
edits.append((
  "function checkAlerts (body) {\n  if (state.chatWindow) return\n  try { window.msg.setUnread(totalUnread()) } catch (err) { /* ignore */ }\n  const focused = typeof document.hasFocus === 'function' ? document.hasFocus() : true\n  const meDnd = !!(state.me && state.me.status_mode === 'dnd')",
  "function checkAlerts (body) {\n  if (state.chatWindow) return\n  try { window.msg.setUnread(totalUnread()) } catch (err) { /* ignore */ }\n\n  // Unified path: the poll carries the `alerts` delta (DM / mention / invite /\n  // friend events with content + channel slug + message id). One decision\n  // engine serves every surface; the older per-list detection below is kept\n  // only for servers that don't send the delta yet.\n  if (Array.isArray(body.alerts)) {\n    notif.hasDelta = true\n    handleAlertsDelta(body.alerts)\n    return\n  }\n\n  const focused = typeof document.hasFocus === 'function' ? document.hasFocus() : true\n  const meDnd = !!(state.me && state.me.status_mode === 'dnd')"
))

# 4. pollNotificationsFeed + initNotifications block replacement
old_feed = '''/* Poll the notifications feed for friend requests/accepts, invites and
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
}'''
new_feed = open('messenger-web/src/messenger.js').read()
import re
m = re.search(r"/\* The notifications feed powers the in-app Activity panel.*?pollNotificationsFeed\(\)\n\}", new_feed, re.S)
assert m, 'web block not found'
new_block = m.group(0)
edits.append((old_feed, new_block))

# 5. renderSidebar alerts tab
edits.append((
  "  $('#tab-buddy').classList.toggle('active', state.tab === 'buddy')\n  $('#tab-rooms').classList.toggle('active', state.tab === 'rooms')\n  $('#tab-requests').classList.toggle('active', state.tab === 'requests')",
  "  $('#tab-buddy').classList.toggle('active', state.tab === 'buddy')\n  $('#tab-rooms').classList.toggle('active', state.tab === 'rooms')\n  $('#tab-requests').classList.toggle('active', state.tab === 'requests')\n  $('#tab-alerts').classList.toggle('active', state.tab === 'alerts')"
))
edits.append((
  "  $('#panel-buddy').hidden = state.tab !== 'buddy' || searching\n  $('#panel-rooms').hidden = state.tab !== 'rooms' || searching\n  $('#panel-requests').hidden = state.tab !== 'requests' || searching\n\n  if (state.tab === 'buddy') renderBuddyList()\n  if (state.tab === 'rooms') renderRoomsList()\n  if (state.tab === 'requests') renderRequestsList()",
  "  $('#panel-buddy').hidden = state.tab !== 'buddy' || searching\n  $('#panel-rooms').hidden = state.tab !== 'rooms' || searching\n  $('#panel-requests').hidden = state.tab !== 'requests' || searching\n  $('#panel-alerts').hidden = state.tab !== 'alerts'\n\n  if (state.tab === 'buddy') renderBuddyList()\n  if (state.tab === 'rooms') renderRoomsList()\n  if (state.tab === 'requests') renderRequestsList()\n  if (state.tab === 'alerts') renderActivityPanel()"
))

# 6. wireEvents alerts tab click
edits.append((
  "  $('#tab-requests').addEventListener('click', () => setTab('requests'))",
  "  $('#tab-requests').addEventListener('click', () => setTab('requests'))\n  $('#tab-alerts').addEventListener('click', () => setTab('alerts'))"
))

# 7. openConversation jump
edits.append((
  "async function openConversation (type, id) {\n  hidePickers()\n  state.open = {\n    type,\n    id,\n    title: type === 'room' ? '#' + id : id,\n    since: 0,\n    messages: [],\n    members: [],\n    topic: '',\n    channelUrl: '',\n    presence: null\n  }\n  state.messages = state.open.messages\n  state.tab = type === 'room' ? 'rooms' : 'buddy'\n  setTab(state.tab)\n  renderAll()\n  wsSubscribe()\n  await pollTick()\n  if (type === 'room') {\n    await LvApi.postForm('/api/channel/read', { channel: id })\n  }\n}\n\nfunction openDm (username) {\n  openConversation('dm', username)\n}\n\nfunction openRoom (slug) {\n  openConversation('room', slug)\n}\n\n/* In Compact view, opening a conversation always means a dedicated window. In\n * Advanced view it opens in the in-window pane. */\nasync function openConversationOrWindow (type, id) {\n  if (state.viewMode === 'compact') return openChatWindow(type, id)\n  return type === 'dm' ? openDm(id) : openRoom(id)\n}",
  "async function openConversation (type, id, jump) {\n  hidePickers()\n  state.open = {\n    type,\n    id,\n    title: type === 'room' ? '#' + id : id,\n    since: 0,\n    messages: [],\n    members: [],\n    topic: '',\n    channelUrl: '',\n    presence: null\n  }\n  state.messages = state.open.messages\n  state.tab = type === 'room' ? 'rooms' : 'buddy'\n  setTab(state.tab)\n  renderAll()\n  wsSubscribe()\n  await pollTick()\n  if (type === 'room') {\n    await LvApi.postForm('/api/channel/read', { channel: id })\n  }\n  if (jump) jumpToMessage(jump)\n}\n\n/* Jump to (and briefly highlight) a specific message after a notification\n * click. The highlight re-applies on subsequent renders so re-rendered polls\n * don't wipe the flash mid-way. */\nfunction jumpToMessage (id) {\n  state.pendingJumpId = parseInt(id, 10) || 0\n  setTimeout(() => { if (Number(state.pendingJumpId) === Number(id)) state.pendingJumpId = 0 }, 4000)\n  applyJumpHighlight()\n}\n\nfunction applyJumpHighlight () {\n  if (!state.pendingJumpId) return\n  const el = document.querySelector('#stream .msg[data-id=\"' + state.pendingJumpId + '\"]')\n  if (!el) return\n  el.scrollIntoView({ block: 'center' })\n  el.classList.add('msg-highlight')\n}\n\nfunction openDm (username, jump) {\n  openConversation('dm', username, jump)\n}\n\nfunction openRoom (slug, jump) {\n  openConversation('room', slug, jump)\n}\n\n/* In Compact view, opening a conversation always means a dedicated window. In\n * Advanced view it opens in the in-window pane. */\nasync function openConversationOrWindow (type, id, jump) {\n  if (state.viewMode === 'compact') return openChatWindow(type, id)\n  return type === 'dm' ? openDm(id, jump) : openRoom(id, jump)\n}"
))

# 8. renderChat jump highlight
edits.append((
  "  composer.hidden = false\n  renderStream()\n}",
  "  composer.hidden = false\n  renderStream()\n  applyJumpHighlight()\n}"
))

# 9. wireOpenConversation msg_id
edits.append((
  "        if (conv && conv.type && conv.id) openConversationOrWindow(conv.type, conv.id)",
  "        if (conv && conv.type && conv.id) openConversationOrWindow(conv.type, conv.id, conv.msg_id)"
))

# 10. openSettings sync
edits.append((
  "  $('#set-notify-invites').checked = notif.prefs.invites === 1\n  $('#settings-status').hidden = true",
  "  $('#set-notify-invites').checked = notif.prefs.invites === 1\n  $('#settings-status').hidden = true\n  syncUnifiedPrefsUi()"
))

# 11. saveNotifyPrefs unified prefs helpers (insert after saveNotifyPrefs fn)
old_save = "async function saveNotifyPrefs () {\n  const prefs = {\n    channels: $('#set-notify-channels').checked ? 1 : 0,\n    dms: $('#set-notify-dms').checked ? 1 : 0,\n    invites: $('#set-notify-invites').checked ? 1 : 0\n  }\n  notif.prefs = prefs\n  persistLocalNotifyPrefs(prefs)\n  try {\n    await LvApi.postForm('/api/push/prefs', { channels: String(prefs.channels), dms: String(prefs.dms), invites: String(prefs.invites) })\n  } catch (err) { /* server endpoint unavailable — local prefs stand */ }\n}"
m2 = re.search(r"async function saveNotifyPrefs \(\) \{\n  const prefs = \{[\s\S]*?\n  \} catch \(err\) \{ /\* server endpoint unavailable — local prefs stand \*/ \}\n\}", s)
assert m2, 'saveNotifyPrefs not found'
new_save = '''async function saveNotifyPrefs () {
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

/* Unified pref fields in the Settings modal (masters / quiet hours / previews /
 * keywords) — saved through /api/notify/prefs so every surface shares them. */
function syncUnifiedPrefsUi () {
  const p = notif.notifyPrefs || {}
  const qh = $('#set-qh-enabled')
  if (qh) qh.checked = !!p.quiet_hours_enabled
  const qs = $('#set-qh-start')
  if (qs) qs.value = p.quiet_hours_start || '22:00'
  const qe = $('#set-qh-end')
  if (qe) qe.value = p.quiet_hours_end || '08:00'
  const days = Array.isArray(p.quiet_hours_days) ? p.quiet_hours_days.map(Number) : []
  document.querySelectorAll('.m-qh-day').forEach((b) => {
    const on = days.includes(parseInt(b.dataset.day, 10))
    b.classList.toggle('on', on)
    if (on) { b.style.background = 'var(--accent)'; b.style.color = 'var(--accent-fg)'; b.style.borderColor = 'var(--accent)'; }
    else { b.style.background = ''; b.style.color = ''; b.style.borderColor = ''; }
  })
  const sm = $('#set-master-sound')
  if (sm) sm.checked = p.sound_master !== 0
  const om = $('#set-master-os')
  if (om) om.checked = p.os_master !== 0
  const pv = $('#set-preview')
  if (pv) pv.checked = p.previews !== 0
  const kw = $('#set-keywords')
  if (kw) kw.value = (Array.isArray(p.highlight_keywords) ? p.highlight_keywords : []).join('\\n')
}

async function saveUnifiedPrefs () {
  const days = [...document.querySelectorAll('.m-qh-day.on')].map((b) => parseInt(b.dataset.day, 10))
  const payload = {
    sound_master: $('#set-master-sound').checked ? '1' : '0',
    os_master: $('#set-master-os').checked ? '1' : '0',
    previews: $('#set-preview').checked ? '1' : '0',
    quiet_hours_enabled: $('#set-qh-enabled').checked ? '1' : '0',
    quiet_hours_start: $('#set-qh-start').value || '22:00',
    quiet_hours_end: $('#set-qh-end').value || '08:00',
    quiet_hours_days: JSON.stringify(days),
    highlight_keywords: JSON.stringify((($('#set-keywords').value || '').split(/\\n/).map((s) => s.trim()).filter(Boolean)).slice(0, 25)),
    tz_offset_minutes: String(-new Date().getTimezoneOffset())
  }
  const merged = Object.assign({}, notif.notifyPrefs, {
    sound_master: +payload.sound_master, os_master: +payload.os_master, previews: +payload.previews,
    quiet_hours_enabled: +payload.quiet_hours_enabled, quiet_hours_start: payload.quiet_hours_start,
    quiet_hours_end: payload.quiet_hours_end, quiet_hours_days: days,
    highlight_keywords: JSON.parse(payload.highlight_keywords), tz_offset_minutes: -new Date().getTimezoneOffset()
  })
  notif.notifyPrefs = merged
  try {
    await LvApi.postForm('/api/notify/prefs', payload)
  } catch (err) { /* older server — masters stay local-only for this session */ }
}

function wireUnifiedPrefs () {
  const ids = ['set-master-sound', 'set-master-os', 'set-preview', 'set-qh-enabled', 'set-qh-start', 'set-qh-end', 'set-keywords']
  for (const id of ids) {
    const el = document.getElementById(id)
    if (el) el.addEventListener('change', saveUnifiedPrefs)
  }
  document.querySelectorAll('.m-qh-day').forEach((b) => {
    b.classList.toggle('on', b.classList.contains('on'))
    b.addEventListener('click', () => {
      const on = !b.classList.contains('on')
      b.classList.toggle('on', on)
      if (on) { b.style.background = 'var(--accent)'; b.style.color = 'var(--accent-fg)'; b.style.borderColor = 'var(--accent)'; }
      else { b.style.background = ''; b.style.color = ''; b.style.borderColor = ''; }
      saveUnifiedPrefs()
    })
  })
}'''
edits.append((m2.group(0), new_save))

# 12. wireEvents unified prefs (add after notify-invites binding)
edits.append((
  "  $('#set-notify-invites').addEventListener('change', saveNotifyPrefs)",
  "  $('#set-notify-invites').addEventListener('change', saveNotifyPrefs)\n  wireUnifiedPrefs()"
))

applied = 0
failed = []
for old, new in edits:
    if old in s:
        s = s.replace(old, new, 1)
        applied += 1
    else:
        failed.append(old[:80])

open(P, 'w').write(s)
print('applied', applied, 'of', len(edits))
for f in failed:
    print('MISS:', f.replace('\n', '\\n'))
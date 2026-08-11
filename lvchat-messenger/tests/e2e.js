/* LVChat Messenger end-to-end test.
 *
 * Boots the real Electron app against an in-memory mock LVChat server and
 * drives the messenger window: login → MFA → friends/groups render →
 * directory add-friend → DM send/receive → GIF + image → room members → theme.
 */
const { app, BrowserWindow, shell } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-messenger-test-'))
app.setPath('userData', tmp)

const messengerMain = require('../src/main')
const updater = require('../src/updater')
const profiles = require('../src/profiles')

// Intercept external-browser opens so registration URLs can be asserted without
// launching a real browser. main.js references the same `shell` singleton.
let openedExternal = null
shell.openExternal = async (url) => { openedExternal = String(url) }

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

function launcher () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('launcher.html'))
}

function messengerWindow () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('messenger.html'))
}

/* A dedicated conversation window: URL contains chat=<type>:<id> encoded. */
function chatWindow (type, id) {
  const token = encodeURIComponent(type + ':' + id)
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('chat=' + token))
}

function chatWindowCount (type, id) {
  const token = encodeURIComponent(type + ':' + id)
  return BrowserWindow.getAllWindows().filter((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('chat=' + token)).length
}

function waitLauncher () {
  return new Promise((resolve) => {
    const tryGet = () => {
      const w = launcher()
      if (w && !w.webContents.isLoading()) resolve(w)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
}

function waitForNoLauncher () {
  return new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      if (!launcher()) resolve(true)
      else if (Date.now() - start > 15000) resolve(false)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
}

function waitMessenger () {
  return new Promise((resolve) => {
    const tryGet = () => {
      const w = messengerWindow()
      if (w && !w.webContents.isLoading()) resolve(w)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
}

async function waitChatWindow (type, id, ms) {
  const timeout = ms || 25000
  const start = Date.now()
  for (;;) {
    const w = chatWindow(type, id)
    if (w && !w.webContents.isLoading()) return w
    if (Date.now() - start > timeout) return null
    await new Promise((r) => setTimeout(r, 80))
  }
}

async function waitExternal (expected, ms) {
  const timeout = ms || 15000
  const start = Date.now()
  while (openedExternal !== expected && Date.now() - start < timeout) {
    await new Promise((r) => setTimeout(r, 80))
  }
}

function js (win, code) {
  return Promise.race([
    win.webContents.executeJavaScript(code, true),
    new Promise((resolve) => setTimeout(() => resolve({ __timeout: true }), 30000))
  ])
}

/* Poll an expression in the renderer until it is truthy. */
async function waitJs (win, expr, ms) {
  const timeout = ms || 25000
  const start = Date.now()
  for (;;) {
    let v
    try { v = await js(win, expr) } catch (err) { v = false }
    if (v && !(typeof v === 'object' && v.__timeout)) return v
    if (Date.now() - start > timeout) return false
    await new Promise((r) => setTimeout(r, 80))
  }
}

/* ── Mock LVChat server ───────────────────────────────────── */

function mockLvchatServer () {
  const CSRF = 'test-csrf-token'
  let nextId = 100

  const dmMessages = [
    { id: 1, kind: 'message', content: 'hey alice', username: 'bob', sender_id: 2, created_at: '2026-08-06 10:00:00', is_pm: true },
    { id: 2, kind: 'message', content: 'yo bob', username: 'alice', sender_id: 1, created_at: '2026-08-06 10:01:00', is_pm: true }
  ]
  const roomMessages = [
    { id: 10, kind: 'message', content: 'welcome to gaming', username: 'carol', sender_id: 3, created_at: '2026-08-06 09:00:00', channel: 'gaming' },
    { id: 11, kind: 'join', content: 'carol joined the channel', username: null, sender_id: null, created_at: '2026-08-06 09:01:00', channel: 'gaming' }
  ]

  const state = {
    directoryDanStatus: 'none',
    dmUnread: 2,
    kickChannel: null,
    lastCommand: null,
    lastJoin: null,
    groups: [{ id: 1, name: 'Friends', position: 0, members: [2] }],
    joinedChannels: [{ slug: 'gaming', unread: 3, online: 4 }, { slug: 'general', unread: 0, online: 1 }],
    soundPrefs: null,
    pushPrefs: null,
    meStatus: null,
    mutedUsers: new Set(), // user ids muted by the viewer (user_mutes)
    blockedUsers: new Set(), // usernames blocked by the viewer
    onlineFriends: new Set(['bob']), // usernames currently online
    friendModes: {}, // username -> status_mode override (issue #6)
    friendStatus: {}, // username -> custom_status override
    invites: [
      { id: 5, channel_id: 9, channel_name: 'Dev Lounge', slug: 'dev', inviter: 'bob', created_at: '2026-08-06 07:00:00' },
      { id: 6, channel_id: 10, channel_name: 'Gamers Den', slug: 'gamers', inviter: 'carol', created_at: '2026-08-06 07:05:00' }
    ]
  }

  const friends = () => ([
    { id: 2, username: 'bob', avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00', friends_since: '2026-07-01 00:00:00', is_online: 1 },
    { id: 3, username: 'carol', avatar: null, role: 'user', away: null, last_seen: '2026-08-01 00:00:00', friends_since: '2026-07-01 00:00:00', is_online: 0 }
  ])
    // Blocking deletes the friendship server-side, so blocked users leave the list.
    .filter((u) => !state.blockedUsers.has(u.username))
    .map((u) => Object.assign({}, u, {
      is_online: state.onlineFriends.has(u.username) ? 1 : 0,
      status_mode: state.friendModes[u.username] || 'online',
      custom_status: state.friendStatus[u.username] || '',
      muted: state.mutedUsers.has(u.id) ? 1 : 0
    }))
  const incoming = () => ([{ id: 4, username: 'eve', avatar: null, created_at: '2026-08-06 08:00:00' }])
  const outgoing = () => (state.directoryDanStatus === 'outgoing'
    ? [{ id: 9, username: 'dan', avatar: null, created_at: '2026-08-06 08:30:00' }]
    : [])

  function groupsPayload () {
    return state.groups.map((g) => {
      const members = g.members.map((uid) => {
        const u = uid === 2
          ? { id: 2, username: 'bob', avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00' }
          : { id: 3, username: 'carol', avatar: null, role: 'user', away: null, last_seen: '2026-08-01 00:00:00' }
        u.is_online = u.id === 2 ? 1 : 0
        u.status_mode = state.friendModes[u.username] || 'online'
        u.custom_status = state.friendStatus[u.username] || ''
        return u
      })
      return { id: g.id, name: g.name, position: g.position, members }
    })
  }

  function pollPayload (url) {
    const since = Number(url.searchParams.get('since') || 0)
    const dm = url.searchParams.get('dm')
    const channel = url.searchParams.get('channel')
    // Simulated kick: the server removes the user's membership and bounces
    // them out of the channel with a reason.
    if (channel && channel === state.kickChannel) {
      state.joinedChannels = state.joinedChannels.filter((c) => c.slug !== channel)
      return { ok: true, redirect: '/app', reason: 'You were removed from #' + channel + '.' }
    }
    const payload = {
      ok: true,
      messages: [],
      presence: [],
      notify_count: 1,
      dm_list: [{ user_id: 3, username: 'carol', role: 'user', guest: 0, away: null, last_seen: '2026-08-01 00:00:00', unread: state.dmUnread, last_content: 'hey', last_id: 3, muted: state.mutedUsers.has(3) ? 1 : 0 }],
      friends: friends(),
      friend_requests: incoming(),
      channel_invites: state.invites,
      channel_unread: state.joinedChannels.map((c) => ({ slug: c.slug, unread: c.unread })),
      channel_presence: state.joinedChannels.map((c) => ({ slug: c.slug, online: c.online })),
      blocked: [...state.blockedUsers].map((username) => ({ id: username === 'carol' ? 3 : 2, username, avatar: null }))
    }
    if (dm) {
      payload.dm = dm
      payload.messages = dmMessages.filter((m) => m.id > since)
      payload.presence = [{ username: 'bob', is_online: 1, away: null, level: 'normal', role: 'user', guest: 0 }]
    }
    if (channel) {
      payload.channel = channel
      payload.topic = 'fps hangout'
      payload.messages = roomMessages.filter((m) => m.id > since)
      payload.presence = [
        { username: 'bob', is_online: 1, away: null, level: 'op', role: 'user', guest: 0, avatar: null },
        { username: 'carol', is_online: 0, away: null, level: 'normal', role: 'user', guest: 0, avatar: null }
      ]
    }
    return payload
  }

  function html (title, error) {
    return '<!doctype html><html><body>' +
      '<form action="/login" method="post">' +
      '<input type="hidden" name="csrf" value="' + CSRF + '">' +
      '<input name="username"><input name="password"></form>' +
      (error ? '<div class="text-red-400">' + error + '</div>' : '') +
      '<title>' + title + '</title></body></html>'
  }

  const serverBase = () => `http://127.0.0.1:${server.address().port}`

  const server = http.createServer((req, res) => {
    if (process.env.MOCK_LOG) console.log('[mock]', req.method, req.url)
    const url = new URL(req.url, 'http://127.0.0.1')
    const origin = req.headers.origin
    const setCors = () => {
      if (!origin) return
      res.setHeader('access-control-allow-origin', origin)
      res.setHeader('access-control-allow-credentials', 'true')
      res.setHeader('vary', 'Origin')
      res.setHeader('access-control-allow-methods', 'GET, POST, OPTIONS')
      res.setHeader('access-control-allow-headers', 'Content-Type, X-CSRF, X-Messenger, X-LVC-Session')
    }
    const cookie = req.headers.cookie || ''
    const sessionHeader = String(req.headers['x-lvc-session'] || '')
    const hasSession = cookie.includes('session=abc123') || sessionHeader === 'session-abc123'
    const hasPending = cookie.includes('pending=abc')

    if (req.method === 'OPTIONS') {
      setCors()
      res.writeHead(204)
      res.end()
      return
    }
    setCors()
    const json = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' })
      res.end(JSON.stringify(obj))
    }

    if (url.pathname === '/api/version') {
      json(200, { version: '1.0.0-test', site: 'Test Chat', updater_url: serverBase() })
      return
    }

    if (url.pathname === '/api/updater') {
      json(200, {
        updater_url: serverBase(),
        site: 'Test Chat',
        apps: {
          web: { installed: '1.0.0', latest: '1.0.0', url: serverBase() + '/web.zip', sha256: '', update_available: false },
          messenger: { installed: '9.9.9', latest: '9.9.9', update_available: false, platforms: { win: { url: serverBase() + '/messenger.exe', version: '9.9.9' } } },
          desktop: { installed: '1.0.0', latest: '1.0.0', update_available: false, platforms: {} }
        }
      })
      return
    }

    if (url.pathname === '/api/commands') {
      json(200, { commands: ['help', 'kick', 'sanick', 'kickban', 'kline', 'msg'] })
      return
    }

    if (url.pathname === '/login' && req.method === 'GET') {
      if (hasSession) { res.writeHead(302, { location: '/app?channel=general' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end(html('login'))
      return
    }

    if (url.pathname === '/api/messenger/login' && req.method === 'POST') {
      if (req.headers['x-messenger'] !== '1') { json(403, { error: 'Not a messenger request.' }); return }
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const params = new URLSearchParams(body)
        const username = params.get('username')
        const password = params.get('password')
        if (username === 'alice' && password === 'password123') {
          json(200, { mfa: true, ticket: 'ticket-abc' })
          return
        }
        if (username === 'bob' && password === 'secret') {
          json(200, { ok: true, token: 'session-abc123' })
          return
        }
        json(401, { error: 'Invalid username or password.' })
      })
      return
    }

    if (url.pathname === '/api/messenger/mfa' && req.method === 'POST') {
      if (req.headers['x-messenger'] !== '1') { json(403, { error: 'Not a messenger request.' }); return }
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        if (p.get('ticket') === 'ticket-abc' && p.get('code') === '123456') {
          json(200, { ok: true, token: 'session-abc123' })
          return
        }
        json(401, { error: 'Invalid authentication code. Try again.' })
      })
      return
    }

    if (url.pathname === '/api/messenger/logout' && req.method === 'POST') {
      json(200, { ok: true })
      return
    }

    if (url.pathname === '/api/csrf') {
      json(200, { ok: true, csrf: CSRF })
      return
    }

    if (url.pathname === '/app') {
      if (!hasSession) { res.writeHead(302, { location: '/login?next=/app' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end('<html><body>app</body></html>')
      return
    }

    if (!hasSession) {
      json(401, { error: 'Not authenticated.' })
      return
    }

    if (url.pathname === '/api/me') {
      json(200, { ok: true, user: Object.assign({ id: 1, username: 'alice', avatar: null, role: 'user', guest: 0, away: null, status: 'active' }, state.meStatus || {}) })
      return
    }
    if (url.pathname === '/api/status') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        const mode = p.get('status_mode') || 'online'
        const custom = p.get('custom_status') || ''
        state.meStatus = { status_mode: mode, custom_status: custom, dnd: mode === 'dnd' ? 1 : 0, invisible: mode === 'invisible' ? 1 : 0, is_online: mode === 'invisible' ? 0 : 1 }
        json(200, { ok: true, status: state.meStatus, away: mode === 'away' ? custom || null : null })
      })
      return
    }
    if (url.pathname === '/api/friends') {
      json(200, { ok: true, friends: friends(), incoming: incoming(), outgoing: outgoing(), blocked: [...state.blockedUsers].map((username) => ({ id: username === 'carol' ? 3 : 2, username, avatar: null })) })
      return
    }
    if (url.pathname === '/api/push/mute' || url.pathname === '/api/push/unmute') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const uid = Number(new URLSearchParams(body).get('user_id'))
        if (url.pathname === '/api/push/mute') state.mutedUsers.add(uid)
        else state.mutedUsers.delete(uid)
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/friend/block' || url.pathname === '/api/friend/unblock') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const username = new URLSearchParams(body).get('username')
        if (url.pathname === '/api/friend/block') state.blockedUsers.add(username)
        else state.blockedUsers.delete(username)
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/groups') {
      if (req.method === 'GET') {
        json(200, { ok: true, groups: groupsPayload() })
        return
      }
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        if (p.get('name')) {
          const group = { id: ++nextId, name: p.get('name'), position: 0, members: [] }
          state.groups.push(group)
          json(200, { ok: true, group: { id: group.id, name: group.name, position: 0, members: [] } })
        } else if (p.get('id') && p.get('name') !== null) {
          json(200, { ok: true, group: { id: p.get('id'), name: p.get('name'), position: 0, members: [] } })
        } else {
          json(400, { error: 'Bad request.' })
        }
      })
      return
    }
    if (url.pathname === '/api/groups/member/add') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        const g = state.groups.find((x) => String(x.id) === String(p.get('group_id')))
        if (!g) { json(400, { error: 'Group not found.' }); return }
        const fid = Number(p.get('friend_id'))
        if (!g.members.includes(fid)) g.members.push(fid)
        json(200, { ok: true, group: groupsPayload().find((x) => x.id === g.id) })
      })
      return
    }
    if (url.pathname === '/api/groups/member/remove') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        const g = state.groups.find((x) => String(x.id) === String(p.get('group_id')))
        if (g) g.members = g.members.filter((m) => m !== Number(p.get('friend_id')))
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/groups/delete') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        state.groups = state.groups.filter((x) => String(x.id) !== String(p.get('id')))
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/directory') {
      const q = url.searchParams.get('q') || ''
      if (q === 'dan') {
        json(200, { ok: true, results: [{ id: 9, username: 'dan', avatar: null, role: 'user', is_online: 0, status: state.directoryDanStatus }] })
      } else {
        json(200, { ok: true, results: [] })
      }
      return
    }
    if (url.pathname === '/api/friend/request') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        if (new URLSearchParams(body).get('username') === 'dan') state.directoryDanStatus = 'outgoing'
        json(200, { ok: true, status: 'outgoing' })
      })
      return
    }
    if (url.pathname === '/api/friend/accept' || url.pathname === '/api/friend/decline') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => { json(200, { ok: true }) })
      return
    }
    if (url.pathname === '/api/channel/invite/accept' || url.pathname === '/api/channel/invite/decline') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        const slug = p.get('channel')
        const invite = state.invites.find((i) => i.slug === slug)
        state.invites = state.invites.filter((i) => i.slug !== slug)
        if (url.pathname === '/api/channel/invite/accept' && invite) {
          state.joinedChannels.push({ slug, unread: 0, online: 0 })
        }
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/poll') {
      json(200, pollPayload(url))
      return
    }
    if (url.pathname === '/api/send') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        const gifUrl = p.get('gif_url')
        const kind = gifUrl ? 'gif' : 'message'
        const content = gifUrl ? gifUrl + (p.get('gif_title') ? '\n' + p.get('gif_title') : '') : p.get('content')
        const msg = { id: ++nextId, kind, content, username: 'alice', sender_id: 1, created_at: '2026-08-06 11:00:00', is_pm: !!p.get('recipient'), channel: p.get('channel') || undefined }
        if (p.get('recipient')) dmMessages.push(msg)
        else roomMessages.push(msg)
        json(200, { ok: true, message: msg })
      })
      return
    }
    if (url.pathname === '/api/gifs') {
      const q = url.searchParams.get('q') || ''
      json(200, {
        ok: true,
        gifs: q
          ? [{ id: 'g1', title: 'cat gif', url: 'https://media.giphy.com/media/abc/giphy.gif', preview: 'https://media.giphy.com/media/abc/preview.gif', page: 'https://giphy.com/abc', provider: 'giphy' }]
          : [],
        next: ''
      })
      return
    }
    if (url.pathname === '/api/upload') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const dm = /\nname="dm"\r\n\r\n([^\r\n]+)/.exec(body)
        const channel = /\nname="channel"\r\n\r\n([^\r\n]+)/.exec(body)
        const msg = { id: ++nextId, kind: 'image', content: '/uploads/upload/xyz.png', username: 'alice', sender_id: 1, created_at: '2026-08-06 11:01:00', is_pm: !!dm }
        if (dm || channel) {
          if (dm) dmMessages.push(msg)
          else roomMessages.push(msg)
        }
        json(200, { ok: true, message: msg })
      })
      return
    }
    if (url.pathname === '/api/channel/read') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => { json(200, { ok: true }) })
      return
    }
    if (url.pathname === '/api/command') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        state.lastCommand = { text: p.get('text') || '', channel: p.get('channel') || '' }
        const text = state.lastCommand.text
        if (text.startsWith('/clear')) {
          json(200, { ok: true, action: 'clear' })
          return
        }
        json(200, { ok: true, replies: ['Command reply: ' + text] })
      })
      return
    }
    if (url.pathname === '/api/browse') {
      json(200, {
        ok: true,
        channels: [{ id: 20, name: '#arcade', slug: 'arcade', topic: '', description: '', members: 3, online: 2, visibility: 'public', joined: false }],
        myChannels: [{ id: 21, name: '#gaming', slug: 'gaming', topic: 'fps hangout', description: '', members: 5, online: 4, visibility: 'public', joined: true }],
        online: 9,
        peak: 12
      })
      return
    }
    if (url.pathname === '/api/join') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        state.lastJoin = { name: p.get('name') || '', key: p.get('key') || '' }
        const slug = (p.get('name') || '').replace(/^[#&]/, '')
        if (slug && !state.joinedChannels.some((c) => c.slug === slug)) {
          state.joinedChannels.push({ slug, unread: 0, online: 1 })
        }
        json(200, { ok: true, redirect: '/app?channel=' + slug })
      })
      return
    }
    if (url.pathname === '/api/push/prefs') {
      if (req.method === 'POST') {
        let body = ''
        req.on('data', (c) => { body += c })
        req.on('end', () => {
          const p = new URLSearchParams(body)
          state.pushPrefs = { channels: p.get('channels') === '1' ? 1 : 0, dms: p.get('dms') === '1' ? 1 : 0, invites: p.get('invites') === '1' ? 1 : 0 }
          json(200, { ok: true, prefs: state.pushPrefs })
        })
        return
      }
      json(200, { ok: true, prefs: { channels: 1, dms: 1, invites: 1 } })
      return
    }
    if (url.pathname === '/api/sounds') {
      // Server-seeded tones (Ding/Pop/Chime) — the messenger must NOT add its
      // own local built-ins of the same names, or the picker lists them twice.
      const overrides = {}
      for (const uid of state.mutedUsers) overrides[uid] = null
      json(200, {
        ok: true,
        sounds: {
          1: { name: 'Ding', url: '/assets/sounds/ding.wav' },
          2: { name: 'Pop', url: '/assets/sounds/pop.wav' },
          3: { name: 'Chime', url: '/assets/sounds/chime.wav' }
        },
        dm_sound_id: 1,
        channel_sound_id: 1,
        overrides
      })
      return
    }
    if (url.pathname === '/api/sound/prefs') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        state.soundPrefs = Object.fromEntries(new URLSearchParams(body))
        json(200, { ok: true })
      })
      return
    }
    if (url.pathname === '/api/notifications') {
      json(200, { ok: true, notifications: [] })
      return
    }
    if (url.pathname === '/api/ws/ticket') {
      // A dead port on purpose: exercises the WS connect + poll-fallback path.
      json(200, { ok: true, ticket: 'test-ticket', url: 'ws://127.0.0.1:1/' })
      return
    }
    json(404, { error: 'Not found' })
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port
      // Expose the message arrays so tests can inject a message as if it came
      // from another device (deterministic — no shared-id-counter ordering).
      state.dmMessages = dmMessages
      state.roomMessages = roomMessages
      resolve({ base: 'http://127.0.0.1:' + port, server, state })
    })
  })
}

/* ── Test flow ────────────────────────────────────────────── */

async function main () {
  const mock = await mockLvchatServer()
  console.log('mock LVChat server on ' + mock.base)

  const launcherWin = await waitLauncher()
  check('launcher window opens', !!launcherWin)

  // Registration — from the add-server form: enable when a valid URL is typed,
  // then open /register in the browser.
  await js(launcherWin, `document.getElementById('add-server-toggle').click()`)
  check('add-server form shown', await waitJs(launcherWin, `!document.getElementById('server-form').hidden && 'ok'`))
  check('register button disabled with empty URL', await js(launcherWin, `document.getElementById('server-register').disabled === true`))
  await js(launcherWin, `document.getElementById('server-url').value = ${JSON.stringify(mock.base)}; document.getElementById('server-url').dispatchEvent(new Event('input'))`)
  check('register button enabled with valid URL', await waitJs(launcherWin, `document.getElementById('server-register').disabled === false && 'ok'`))
  await js(launcherWin, `document.getElementById('server-register').click()`)
  await waitExternal(mock.base + '/register')
  check('register opens the browser to /register', openedExternal === mock.base + '/register', String(openedExternal))
  await js(launcherWin, `document.getElementById('server-cancel').click()`)

  const addRes = await js(launcherWin, `window.lvchat.addProfile({ name: 'Test', url: ${JSON.stringify(mock.base)}, username: 'alice', autoConnect: false })`)
  check('add test profile', addRes.ok === true, JSON.stringify(addRes))
  const profileId = addRes.profile.id

  // Multiple accounts per server: same URL with a different username is a
  // distinct profile; same URL + same username is still rejected.
  const dupRes = await js(launcherWin, `window.lvchat.addProfile({ url: ${JSON.stringify(mock.base)}, username: 'bob', autoConnect: false })`)
  check('second account on same server is allowed', dupRes.ok === true, JSON.stringify(dupRes))
  const dupId = dupRes.profile && dupRes.profile.id
  check('same-server profile defaults to user@host', dupRes.profile && dupRes.profile.name === 'bob@' + new URL(mock.base).hostname, String(dupRes.profile && dupRes.profile.name))
  const dupFail = await js(launcherWin, `window.lvchat.addProfile({ name: 'Dup', url: ${JSON.stringify(mock.base)}, username: 'alice', autoConnect: false })`)
  check('same server + same username is rejected', dupFail.ok === false, JSON.stringify(dupFail))
  if (dupId) await js(launcherWin, `window.lvchat.removeProfile({ id: ${JSON.stringify(dupId)} })`)

  // Registration is also reachable from each saved server row.
  await js(launcherWin, `(() => { const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('Test')); const b = li && [...li.querySelectorAll('button')].find((x) => x.textContent === 'Register'); if (b) b.click(); return !!b })()`)
  await waitExternal(mock.base + '/register')
  check('row Register opens the browser to /register', openedExternal === mock.base + '/register', String(openedExternal))

  const conn = await js(launcherWin, `window.lvchat.connectProfile({ id: ${JSON.stringify(profileId)} })`)
  check('connect opens a messenger window', conn.ok === true && conn.reused === false, JSON.stringify(conn))

  const switchRes = await js(launcherWin, `window.lvchat.switchProfile({ id: ${JSON.stringify(profileId)} })`)
  check('switch to the active profile focuses it', switchRes.ok === true && switchRes.reused === true, JSON.stringify(switchRes))

  const win = await waitMessenger()
  check('messenger window loads', !!win, '')
  win.webContents.on('console-message', (event, level, message, line, sourceId) => {
    console.log('[renderer]', level, message, line || '')
  })

  // Login screen should appear (no saved credentials yet).
  check('login view shown', await waitJs(win, `!document.getElementById('view-login').hidden`))

  // Wrong password → the server's flash error surfaces in the login view.
  await js(win, `document.getElementById('login-username').value = 'alice'; document.getElementById('login-password').value = 'wrong'; document.getElementById('login-form').requestSubmit()`)
  check('bad password shows the login error', await waitJs(win, `document.getElementById('login-error').textContent.includes('Invalid username or password') && 'ok'`))

  // Fill + submit login form → MFA challenge.
  await js(win, `document.getElementById('login-username').value = 'alice'; document.getElementById('login-password').value = 'password123'; document.getElementById('login-form').requestSubmit()`)
  check('MFA view shown after password', await waitJs(win, `!document.getElementById('view-mfa').hidden`))

  // Wrong code first, then correct code.
  await js(win, `document.getElementById('mfa-code').value = '000000'; document.getElementById('mfa-form').requestSubmit()`)
  check('wrong MFA code shows an error', await waitJs(win, `!document.getElementById('mfa-error').hidden`))

  await js(win, `document.getElementById('mfa-code').value = '123456'; document.getElementById('mfa-form').requestSubmit()`)
  check('main view shown after MFA', await waitJs(win, `!document.getElementById('view-main').hidden`))

  // The profile manager window closes once a session is live.
  await waitForNoLauncher()
  check('profile window closes after login', launcher() === undefined)

  check('me name is alice', await waitJs(win, `document.getElementById('me-name').textContent === 'alice' && 'ok'`))

  // Compact view is the default.
  check('compact view is default', await waitJs(win, `document.body.classList.contains('compact') && 'ok'`))
  check('chat pane hidden in compact', await waitJs(win, `document.getElementById('chat').offsetParent === null && 'ok'`))

  // Friends list + groups render.
  const buddyText = await waitJs(win, `document.getElementById('buddy-list').textContent`)
  check('buddy list renders bob + carol', (buddyText || '').includes('bob') && (buddyText || '').includes('carol'), String(buddyText).slice(0, 200))
  check('group node "Friends" + ungrouped render', (buddyText || '').includes('Friends') && (buddyText || '').includes('Ungrouped'), String(buddyText).slice(0, 200))
  check('carol unread badge shows 2', await waitJs(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('carol')); return c && c.querySelector('.unread') && c.querySelector('.unread').textContent === '2' })()`))

  // A DM arriving while on the buddy list fires a native OS notification via
  // the main process (seeding on the first poll must not alert).
  const n0 = await js(win, `window.msg.notifyStats()`)
  mock.state.dmUnread = 3
  await new Promise((r) => setTimeout(r, 3500))
  const n1 = await js(win, `window.msg.notifyStats()`)
  check('new DM unread triggers a native notification', (n1.count || 0) > (n0.count || 0), `${JSON.stringify(n0)} -> ${JSON.stringify(n1)}`)
  mock.state.dmUnread = 2

  // Directory search → add friend.
  await js(win, `document.getElementById('directory-search').value = 'dan'; document.getElementById('directory-search').dispatchEvent(new Event('input'))`)
  check('directory panel shown while searching', await waitJs(win, `!document.getElementById('panel-directory').hidden && 'ok'`))
  check('directory finds dan', await waitJs(win, `[...document.querySelectorAll('#directory-list .contact')].some((r) => r.textContent.includes('dan')) && 'ok'`))
  const addClicked = await js(win, `(() => { const r = [...document.querySelectorAll('#directory-list .contact')].find((r) => r.textContent.includes('dan')); if (!r) return 'no-row'; const b = r.querySelector('button'); b.click(); return b.textContent })()`)
  check('add-friend button clicked', addClicked === 'Add friend' || addClicked === '…', String(addClicked))
  check('dan request sent (status -> Requested)', await waitJs(win, `(() => { const r = [...document.querySelectorAll('#directory-list .contact')].find((r) => r.textContent.includes('dan')); return !!r && r.querySelector('button').textContent.includes('Requested') })()`))

  // Requests tab shows incoming friend request + channel invites.
  await js(win, `document.getElementById('tab-requests').click()`)
  check('requests tab lists eve', await waitJs(win, `document.getElementById('requests-list').textContent.includes('eve') && 'ok'`))
  check('requests tab lists channel invites', await waitJs(win, `document.getElementById('requests-list').textContent.includes('Dev Lounge') && document.getElementById('requests-list').textContent.includes('Gamers Den') && document.getElementById('requests-list').textContent.includes('Invited by bob') && 'ok'`))
  check('requests badge counts invites + friend requests', await waitJs(win, `document.getElementById('req-badge').textContent === '3' && 'ok'`))

  // Decline a channel invite.
  await js(win, `(() => { const rows = [...document.querySelectorAll('#requests-list .req')]; const r = rows.find((x) => x.textContent.includes('Dev Lounge')); if (!r) return 'no-row'; [...r.querySelectorAll('button')].find((b) => b.textContent === 'Decline').click(); return 'ok' })()`)
  check('declined invite leaves the list', await waitJs(win, `!document.getElementById('requests-list').textContent.includes('Dev Lounge') && document.getElementById('requests-list').textContent.includes('Gamers Den') && 'ok'`))

  // Accept a channel invite → the room joins the Rooms tab.
  await js(win, `(() => { const rows = [...document.querySelectorAll('#requests-list .req')]; const r = rows.find((x) => x.textContent.includes('Gamers Den')); if (!r) return 'no-row'; [...r.querySelectorAll('button')].find((b) => b.textContent === 'Accept').click(); return 'ok' })()`)
  check('accepted invite leaves the list', await waitJs(win, `!document.getElementById('requests-list').textContent.includes('Gamers Den') && 'ok'`))
  await js(win, `document.getElementById('tab-rooms').click()`)
  check('accepted invite joins the rooms list', await waitJs(win, `document.getElementById('rooms-list').textContent.includes('gamers') && 'ok'`))
  await js(win, `document.getElementById('tab-buddy').click()`)

  // In compact view a single click must not open a conversation window.
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.click() })()`)
  await new Promise((r) => setTimeout(r, 500))
  check('single click in compact opens no chat window', chatWindowCount('dm', 'bob') === 0, '')

  // Double-click opens a dedicated chat window; repeat double-clicks dedupe.
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.dispatchEvent(new MouseEvent('dblclick', { bubbles: true })) })()`)
  const dmWin = await waitChatWindow('dm', 'bob')
  check('double-click opens a DM chat window', !!dmWin, '')
  check('DM chat window renders history', await waitJs(dmWin, `document.querySelectorAll('#stream .msg').length >= 2 && 'ok'`))
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.dispatchEvent(new MouseEvent('dblclick', { bubbles: true })) })()`)
  await new Promise((r) => setTimeout(r, 700))
  check('double-click dedupes to the same window', chatWindowCount('dm', 'bob') === 1, '')
  await js(dmWin, `document.getElementById('composer-input').value = 'from compact window'; document.getElementById('composer-send').click()`)
  check('DM chat window sends messages', await waitJs(dmWin, `document.getElementById('stream').textContent.includes('from compact window') && 'ok'`))

  // A message sent from ANOTHER device must appear live in this chat window —
  // dedicated windows keep polling, they aren't a one-shot snapshot.
  mock.state.dmMessages.push({ id: 999, kind: 'message', content: 'from another device', username: 'bob', sender_id: 2, created_at: '2026-08-06 11:02:00', is_pm: true })
  check('chat window updates live from another device', await waitJs(dmWin, `document.getElementById('stream').textContent.includes('from another device') && 'ok'`, 6000))
  dmWin.close()
  await new Promise((r) => setTimeout(r, 400))

  // Rooms tab + double-click a room to open its own window.
  await js(win, `document.getElementById('tab-rooms').click()`)
  check('rooms tab lists gaming', await waitJs(win, `document.getElementById('rooms-list').textContent.includes('gaming') && 'ok'`))
  await js(win, `(() => { const c = [...document.querySelectorAll('#rooms-list .contact')].find((c) => c.textContent.includes('gaming')); if (c) c.dispatchEvent(new MouseEvent('dblclick', { bubbles: true })) })()`)
  const roomWin = await waitChatWindow('room', 'gaming')
  check('double-click opens a room chat window', !!roomWin, '')
  check('room chat window shows #gaming title', await waitJs(roomWin, `document.getElementById('chat-title').textContent === '#gaming' && 'ok'`))
  check('room chat window renders messages', await waitJs(roomWin, `document.querySelectorAll('#stream .msg').length >= 2 && 'ok'`))
  // Room windows also poll live: a message from another device shows up.
  mock.state.roomMessages.push({ id: 998, kind: 'message', content: 'live room ping', username: 'carol', sender_id: 3, created_at: '2026-08-06 11:03:00', channel: 'gaming' })
  check('room chat window updates live from another device', await waitJs(roomWin, `document.getElementById('stream').textContent.includes('live room ping') && 'ok'`, 6000))
  roomWin.close()
  await new Promise((r) => setTimeout(r, 400))

  // Switch to the Advanced view (persisted in prefs).
  await js(win, `document.getElementById('view-mode-btn').click()`)
  check('advanced view enabled after toggle', await waitJs(win, `document.body.classList.contains('advanced') && 'ok'`))
  check('chat pane visible in advanced', await waitJs(win, `document.getElementById('chat').offsetParent !== null && 'ok'`))
  const savedMode = await waitJs(win, `window.msg.prefsGet('viewMode')`)
  check('viewMode pref persisted', savedMode === 'advanced', String(savedMode))

  // Sidebar is resizable via the drag handle and the width persists.
  await js(win, `(() => { const h = document.getElementById('sidebar-resizer'); h.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 260 })); document.dispatchEvent(new MouseEvent('mousemove', { clientX: 380 })); document.dispatchEvent(new MouseEvent('mouseup', { clientX: 380 })); return 'ok' })()`)
  check('sidebar resizes via drag handle', await waitJs(win, `getComputedStyle(document.getElementById('sidebar')).width === '380px' && 'ok'`))
  check('sidebar width persisted', await waitJs(win, `window.msg.prefsGet('sidebarWidth')`).then((v) => v === 380, String))

  // Compact keeps the list width; the window narrows to fit it (no gray area).
  await js(win, `document.getElementById('view-mode-btn').click()`)
  check('compact list fills the window horizontally', await waitJs(win, `(() => { const s = document.getElementById('sidebar').getBoundingClientRect(); return Math.round(s.width) === window.innerWidth && 'ok' })()`))
  check('compact narrows the window to the list width', await waitJs(win, `Math.abs(window.innerWidth - 380) <= 8 && 'ok'`))
  await js(win, `document.getElementById('view-mode-btn').click()`)
  check('advanced opens the chat pane wide', await waitJs(win, `window.innerWidth >= 900 && getComputedStyle(document.getElementById('sidebar')).width === '380px' && 'ok'`))

  // Narrow sidebar → the header icons collapse into the hamburger menu.
  await js(win, `(() => { const h = document.getElementById('sidebar-resizer'); h.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 200 })); document.dispatchEvent(new MouseEvent('mousemove', { clientX: 200 })); document.dispatchEvent(new MouseEvent('mouseup', { clientX: 200 })); return 'ok' })()`)
  check('hamburger appears when the list is narrow', await waitJs(win, `getComputedStyle(document.getElementById('menu-btn')).display !== 'none' && getComputedStyle(document.getElementById('logout-btn')).display === 'none' && 'ok'`))
  await js(win, `document.getElementById('menu-btn').click()`)
  check('hamburger menu lists the header actions', await waitJs(win, `!document.getElementById('head-menu').hidden && document.getElementById('head-menu').textContent.includes('Profile manager') && document.getElementById('head-menu').textContent.includes('Sign out') && 'ok'`))
  await js(win, `(() => { const m = document.getElementById('head-menu'); const b = [...m.querySelectorAll('button')].find((x) => x.textContent.includes('light')); if (b) b.click(); return 'ok' })()`)
  check('hamburger theme item works', await waitJs(win, `document.body.className.includes('theme-') && 'ok'`))

  // Open DM with bob (in-pane, advanced).
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.click() })()`)
  check('DM with bob opens', await waitJs(win, `document.getElementById('chat-title').textContent === 'bob' && 'ok'`))
  check('DM history rendered (2 seed messages)', await waitJs(win, `document.querySelectorAll('#stream .msg').length >= 2 && 'ok'`))
  check('DM stream shows bob + alice', await waitJs(win, `document.getElementById('stream').textContent.includes('hey alice') && 'ok'`))

  // Send a text message.
  await js(win, `document.getElementById('composer-input').value = 'hello from messenger'; document.getElementById('composer-send').click()`)
  check('text message sends + appears', await waitJs(win, `document.getElementById('stream').textContent.includes('hello from messenger') && 'ok'`))

  // @mention autocomplete: typing @bo suggests bob; Enter inserts @bob and the
  // sent message renders the mention highlighted.
  await js(win, `(() => { const i = document.getElementById('composer-input'); i.value = '@bo'; i.setSelectionRange(3, 3); i.dispatchEvent(new Event('input')) })()`)
  check('mention autocomplete shows for @bo', await waitJs(win, `!document.getElementById('mention-ac').hidden && document.getElementById('mention-ac').textContent.includes('bob') && 'ok'`))
  await js(win, `document.getElementById('composer-input').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))`)
  check('Enter picks the mention', await waitJs(win, `document.getElementById('composer-input').value.startsWith('@bob ') && 'ok'`))
  await js(win, `(() => { const i = document.getElementById('composer-input'); i.value += 'check this'; const e = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }); i.dispatchEvent(e) })()`)
  check('mention message sends', await waitJs(win, `document.getElementById('stream').textContent.includes('check this') && 'ok'`))
  check('mention renders highlighted', await waitJs(win, `document.querySelector('#stream .mention') !== null && document.querySelector('#stream .mention').textContent.includes('@bob') && 'ok'`))

  // GIF search + send.
  await js(win, `document.getElementById('btn-gif').click()`)
  await js(win, `document.getElementById('gif-search-input').value = 'cat'; document.getElementById('gif-search-input').dispatchEvent(new Event('input'))`)
  check('giphy grid returns results', await waitJs(win, `document.querySelectorAll('#gif-grid .gif-item').length > 0 && 'ok'`))
  await js(win, `document.querySelector('#gif-grid .gif-item').click()`)
  check('gif message posts to stream', await waitJs(win, `[...document.querySelectorAll('#stream .msg img.media')].some((img) => img.src.includes('giphy')) && 'ok'`))

  // Emoji picker opens on click and closes on a second click (and via the GIF
  // button / outside click), like the other pickers.
  await js(win, `document.body.click()`)
  await new Promise((r) => setTimeout(r, 200))
  await js(win, `document.getElementById('btn-emoji').click()`)
  await new Promise((r) => setTimeout(r, 250))
  check('emoji picker opens', await js(win, `(() => { const p = document.getElementById('emoji-panel'); return !p.hidden && p.querySelectorAll('button').length > 0 })()`))
  await js(win, `document.getElementById('btn-emoji').click()`)
  await new Promise((r) => setTimeout(r, 250))
  check('emoji picker closes on second click', await js(win, `document.getElementById('emoji-panel').hidden`))
  await js(win, `document.getElementById('btn-emoji').click()`)
  await new Promise((r) => setTimeout(r, 200))
  await js(win, `document.getElementById('btn-gif').click()`)
  await new Promise((r) => setTimeout(r, 200))
  check('emoji picker closes when the GIF picker opens', await js(win, `document.getElementById('emoji-panel').hidden && !document.getElementById('gif-panel').hidden`))
  await js(win, `document.body.click()`)
  await new Promise((r) => setTimeout(r, 200))
  check('gif picker closes on outside click', await js(win, `document.getElementById('gif-panel').hidden`))

  // Image upload (mock accepts the file without parsing its bytes).
  await js(win, `(() => { const f = new File([new Uint8Array([1,2,3])], 'pic.png', { type: 'image/png' }); const dt = new DataTransfer(); dt.items.add(f); const inp = document.getElementById('image-file'); inp.files = dt.files; inp.dispatchEvent(new Event('change')) })()`)
  check('image upload posts to stream', await waitJs(win, `[...document.querySelectorAll('#stream .msg img.media')].some((img) => img.src.includes('uploads')) && 'ok'`))

  // Offline queue: go offline, send, message is queued + pending; reconnect
  // flushes it and the server-confirmed message replaces the pending bubble.
  await js(win, `window.dispatchEvent(new Event('offline'))`)
  await js(win, `(() => { const i = document.getElementById('composer-input'); i.value = 'queued while offline'; document.getElementById('composer-send').click(); return 'ok' })()`)
  check('offline banner appears', await waitJs(win, `!document.getElementById('offline-banner').hidden && 'ok'`))
  check('offline message renders as pending', await waitJs(win, `(() => { const m = [...document.querySelectorAll('#stream .msg')].find((x) => x.textContent.includes('queued while offline')); return !!m && m.textContent.includes('Pending') && 'ok' })()`))
  await js(win, `window.dispatchEvent(new Event('online'))`)
  check('queued message delivered after reconnect', await waitJs(win, `(() => { const m = [...document.querySelectorAll('#stream .msg')].find((x) => x.textContent.includes('queued while offline')); return !!m && !m.textContent.includes('Pending') && 'ok' })()`))
  check('offline banner hidden after reconnect', await waitJs(win, `document.getElementById('offline-banner').hidden && 'ok'`))

  // Settings modal: notification + sound preferences.
  await js(win, `document.getElementById('menu-btn').click()`)
  await js(win, `(() => { const m = document.getElementById('head-menu'); const b = [...m.querySelectorAll('button')].find((x) => x.textContent.includes('Settings')); if (b) b.click(); return !!b })()`)
  check('settings modal opens from the menu', await waitJs(win, `!document.getElementById('settings-modal').hidden && 'ok'`))
  check('settings load current prefs', await waitJs(win, `document.getElementById('set-notify-dms').checked && document.getElementById('set-notify-channels').checked && document.getElementById('set-sound-dm-on').checked && document.getElementById('set-sound-dm').value === '1' && 'ok'`))
  check('sound picker lists each tone once (no dupes)', await waitJs(win, `(() => { const s = document.getElementById('set-sound-dm'); const names = [...s.options].map((o) => o.textContent.trim()); return names.length === 4 && new Set(names).size === names.length && 'ok' })()`))
  // Toggle DM notifications off -> saved to the server + reflected in prefs.
  await js(win, `(() => { const c = document.getElementById('set-notify-dms'); c.checked = false; c.dispatchEvent(new Event('change')); return 'ok' })()`)
  await new Promise((r) => setTimeout(r, 400))
  check('DM notification toggle posts push prefs', !!mock.state.pushPrefs && mock.state.pushPrefs.dms === 0 && mock.state.pushPrefs.channels === 1, JSON.stringify(mock.state.pushPrefs))
  // Toggle DM sound off -> POST /api/sound/prefs with dm_sound=0.
  await js(win, `(() => { const c = document.getElementById('set-sound-dm-on'); c.checked = false; c.dispatchEvent(new Event('change')); return 'ok' })()`)
  await new Promise((r) => setTimeout(r, 400))
  check('DM sound toggle posts sound prefs', !!mock.state.soundPrefs && mock.state.soundPrefs.dm_sound === '0' && mock.state.soundPrefs.channel_sound === '1', JSON.stringify(mock.state.soundPrefs))
  await js(win, `document.getElementById('settings-close').click()`)
  check('settings modal closes', await waitJs(win, `document.getElementById('settings-modal').hidden && 'ok'`))

  // Status selector: pick Do Not Disturb, then a custom status.
  await js(win, `document.getElementById('me-status').click()`)
  check('status menu opens', await waitJs(win, `!document.getElementById('status-menu').hidden && document.getElementById('status-menu').textContent.includes('Do Not Disturb') && 'ok'`))
  await js(win, `(() => { const b = [...document.querySelectorAll('#status-menu .ctx-item')].find((x) => x.textContent.includes('Do Not Disturb')); if (b) b.click(); return !!b })()`)
  await new Promise((r) => setTimeout(r, 400))
  check('DND status saved to the server', !!mock.state.meStatus && mock.state.meStatus.status_mode === 'dnd' && mock.state.meStatus.dnd === 1, JSON.stringify(mock.state.meStatus))
  check('header shows Do Not Disturb', await waitJs(win, `document.getElementById('me-status').textContent.includes('Do Not Disturb') && 'ok'`))
  check('avatar status dot turns red for DND', await waitJs(win, `(() => { const d = document.getElementById('me-avatar').querySelector('.avatar-status'); return !!d && d.classList.contains('dnd') && 'ok' })()`))
  check('Windows notification settings link present', await waitJs(win, `document.getElementById('win-notify-settings') !== null && 'ok'`))

  // The avatar is a second way to open the status menu.
  await js(win, `document.getElementById('me-avatar').click()`)
  check('avatar click opens the status menu', await waitJs(win, `!document.getElementById('status-menu').hidden && document.getElementById('status-menu').textContent.includes('Do Not Disturb') && 'ok'`))
  await js(win, `document.getElementById('status-menu').hidden = true`)

  await js(win, `document.getElementById('me-status').click()`)
  await js(win, `(() => { const b = [...document.querySelectorAll('#status-menu .ctx-item')].find((x) => x.textContent.includes('Custom status')); if (b) b.click(); return !!b })()`)
  check('custom status prompt opens', await waitJs(win, `!document.getElementById('modal').hidden && 'ok'`))
  await js(win, `(() => { const i = document.getElementById('modal-input'); i.value = 'streaming'; document.getElementById('modal-ok').click(); return 'ok' })()`)
  await new Promise((r) => setTimeout(r, 400))
  check('custom status saved to the server', !!mock.state.meStatus && mock.state.meStatus.status_mode === 'custom' && mock.state.meStatus.custom_status === 'streaming', JSON.stringify(mock.state.meStatus))
  check('custom status shown in header', await waitJs(win, `document.getElementById('me-status').textContent.includes('Custom status') && document.getElementById('me-status').textContent.includes('streaming') && 'ok'`))

  // Issue #6: a friend using a custom status must show the away-colored dot to
  // other users (not a plain green "online"). Carol is an ungrouped friend, so
  // her row re-renders from the poll's friend data.
  mock.state.onlineFriends.add('carol')
  mock.state.friendModes['carol'] = 'custom'
  mock.state.friendStatus['carol'] = 'streaming'
  check('custom-status friend shows the away dot', await waitJs(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('carol')); return !!c && c.querySelector('.dot').classList.contains('away') && 'ok' })()`))
  mock.state.onlineFriends.delete('carol')
  mock.state.friendModes['carol'] = 'online'
  mock.state.friendStatus['carol'] = ''

  // Issue #8: mute + block from the buddy context menu.
  // Grouped member bob: mute, verify the label flips to Unmute, then unmute.
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('context menu offers Mute + Block', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Mute notifications') && m.textContent.includes('Block') && 'ok' })()`))
  await js(win, `(() => { const b = [...document.querySelectorAll('.ctx-menu .ctx-item')].find((x) => x.textContent.includes('Mute notifications')); if (b) b.click(); return !!b })()`)
  await new Promise((r) => setTimeout(r, 800))
  check('mute posted /api/push/mute', mock.state.mutedUsers.has(2), [...mock.state.mutedUsers])
  // Reopen the menu: the item should now read Unmute notifications.
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('muted friend shows Unmute', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Unmute notifications') && !m.textContent.includes('Mute notifications') && 'ok' })()`))
  await js(win, `(() => { const b = [...document.querySelectorAll('.ctx-menu .ctx-item')].find((x) => x.textContent.includes('Unmute notifications')); if (b) b.click(); return !!b })()`)
  await new Promise((r) => setTimeout(r, 800))
  check('unmute posted /api/push/unmute', !mock.state.mutedUsers.has(2), [...mock.state.mutedUsers])

  // A muted sender must not fire an OS notification even when their unread
  // count grows (previously only the sound was silenced).
  const n2 = await js(win, `window.msg.notifyStats()`)
  mock.state.mutedUsers.add(3) // carol
  mock.state.dmUnread = 4
  await new Promise((r) => setTimeout(r, 2600))
  const n3 = await js(win, `window.msg.notifyStats()`)
  check('muted sender fires no OS notification', (n3.count || 0) === (n2.count || 0), `${JSON.stringify(n2)} -> ${JSON.stringify(n3)}`)
  mock.state.mutedUsers.delete(3)
  mock.state.dmUnread = 2

  // Buddy context menu (grouped member: window/profile/remove-from-group).
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('grouped member context menu has window + profile + remove-from-group', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Open in new window') && m.textContent.includes('View profile') && m.textContent.includes('Remove from Friends') && 'ok' })()`))

  // Buddy context menu (ungrouped friend: window/profile/remove-friend).
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('carol')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('buddy context menu shows window + profile + remove friend', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Open in new window') && m.textContent.includes('View profile') && m.textContent.includes('Remove friend') && 'ok' })()`))

  // Issue #8: block carol from the context menu → she leaves the friends list
  // and lands under "Blocked users"; unblocking brings her back.
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('carol')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  await js(win, `(() => { const b = [...document.querySelectorAll('.ctx-menu .ctx-item')].find((x) => x.textContent === 'Block'); if (b) b.click(); return !!b })()`)
  check('block confirm modal appears', await waitJs(win, `!document.getElementById('modal').hidden && 'ok'`))
  await js(win, `document.getElementById('modal-ok').click()`)
  await new Promise((r) => setTimeout(r, 800))
  check('block posted /api/friend/block', mock.state.blockedUsers.has('carol'), [...mock.state.blockedUsers])
  check('blocked friend moves to the Blocked users group', await waitJs(win, `(() => { const t = document.getElementById('buddy-list'); const g = [...t.querySelectorAll('.group')].find((x) => x.querySelector('.group-head') && x.querySelector('.group-head').textContent.includes('Blocked users')); return !!g && g.textContent.includes('carol') && 'ok' })()`))
  await js(win, `(() => { const b = [...document.querySelectorAll('#buddy-list button')].find((x) => x.textContent === 'Unblock'); if (b) b.click(); return !!b })()`)
  await new Promise((r) => setTimeout(r, 800))
  check('unblock restores carol to the buddy list', await waitJs(win, `(() => { const t = document.getElementById('buddy-list'); const g = [...t.querySelectorAll('.group')].find((x) => x.querySelector('.group-head') && x.querySelector('.group-head').textContent.includes('Blocked users')); return !g && t.textContent.includes('carol') && 'ok' })()`))
  check('unblock posted /api/friend/unblock', !mock.state.blockedUsers.has('carol'), [...mock.state.blockedUsers])

  // Room view: gaming with members.
  await js(win, `document.getElementById('tab-rooms').click()`)
  check('rooms tab lists gaming', await waitJs(win, `document.getElementById('rooms-list').textContent.includes('gaming') && 'ok'`))
  await js(win, `(() => { const c = [...document.querySelectorAll('#rooms-list .contact')].find((c) => c.textContent.includes('gaming')); if (c) c.click() })()`)
  check('room opens with #gaming title', await waitJs(win, `document.getElementById('chat-title').textContent === '#gaming' && 'ok'`))
  await js(win, `document.getElementById('members-toggle').click()`)
  check('active members list shows only online members', await waitJs(win, `!document.getElementById('members').hidden && document.getElementById('members').textContent.includes('bob') && !document.getElementById('members').textContent.includes('carol') && 'ok'`))

  // Slash commands route to /api/command and render their reply in the stream.
  await js(win, `document.getElementById('composer-input').value = '/kick bob too spammy'; document.getElementById('composer-send').click()`)
  check('slash command reply renders', await waitJs(win, `document.getElementById('stream').textContent.includes('Command reply: /kick bob too spammy') && 'ok'`))
  check('slash command hit /api/command', mock.state.lastCommand && mock.state.lastCommand.text === '/kick bob too spammy', JSON.stringify(mock.state.lastCommand))
  check('slash command carried the channel', mock.state.lastCommand && mock.state.lastCommand.channel === 'gaming', JSON.stringify(mock.state.lastCommand))

  // /clear wipes the local stream (a real server also echoes "Chat cleared.").
  await js(win, `document.getElementById('composer-input').value = '/clear'; document.getElementById('composer-send').click()`)
  check('clear command empties the stream', await waitJs(win, `!document.getElementById('stream').textContent.includes('Command reply: /kick bob too spammy') && 'ok'`))

  // Slash-command autocomplete mirrors the web app (fed by GET /api/commands).
  await js(win, `(() => { const i = document.getElementById('composer-input'); i.value = '/sa'; i.dispatchEvent(new Event('input', { bubbles: true })); return 'ok' })()`)
  check('slash autocomplete lists matching commands', await waitJs(win, `(() => { const ac = document.getElementById('slash-ac'); return !ac.hidden && ac.textContent.includes('/sanick') && 'ok' })()`))
  check('slash autocomplete omits /help', await waitJs(win, `!document.getElementById('slash-ac').textContent.includes('/help') && 'ok'`))
  await js(win, `document.getElementById('composer-input').dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))`)
  check('tab completes the command into the composer', await waitJs(win, `document.getElementById('composer-input').value === '/sanick ' && 'ok'`))
  check('slash autocomplete closes after completion', await waitJs(win, `document.getElementById('slash-ac').hidden && 'ok'`))

  // Send a real message so there is something to right-click below.
  await js(win, `document.getElementById('composer-input').value = 'a message to right click'; document.getElementById('composer-send').click()`)
  check('sent a message for the menu test', await waitJs(win, `document.getElementById('stream').textContent.includes('a message to right click') && 'ok'`))

  // Room context menu: leave + share link.
  await js(win, `(() => { const c = [...document.querySelectorAll('#rooms-list .contact')].find((c) => c.textContent.includes('gaming')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('room context menu shows leave + share link', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Leave room') && m.textContent.includes('Copy share link') && 'ok' })()`))

  // Message context menu: copy + mute + block.
  await js(win, `(() => { const m = document.querySelector('#stream .msg:not(.system)'); if (!m) return 'no-msg'; m.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('message context menu shows copy', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Copy message') && 'ok' })()`))
  check('message context menu has mute + block', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Mute notifications') && m.textContent.includes('Block') && 'ok' })()`))

  // Room browsing: list public rooms, join one by name.
  await js(win, `document.getElementById('tab-rooms').click()`)
  await js(win, `document.getElementById('btn-browse-rooms').click()`)
  check('browse list shows arcade with member counts', await waitJs(win, `!document.getElementById('browse-list').hidden && document.getElementById('browse-list').textContent.includes('arcade') && document.getElementById('browse-list').textContent.includes('3 members') && 'ok'`))
  check('browse shows Open for my joined room', await waitJs(win, `(() => { const rows = [...document.querySelectorAll('#browse-list .contact')]; const g = rows.find((r) => r.textContent.includes('gaming')); return !!g && [...g.querySelectorAll('button')].some((b) => b.textContent === 'Open') && 'ok' })()`))
  await js(win, `(() => { const rows = [...document.querySelectorAll('#browse-list .contact')]; const a = rows.find((r) => r.textContent.includes('arcade')); if (!a) return 'no-row'; [...a.querySelectorAll('button')].find((b) => b.textContent === 'Join').click(); return 'ok' })()`)
  check('join opens the room by name', await waitJs(win, `document.getElementById('chat-title').textContent === '#arcade' && 'ok'`))
  check('join posted the display name to /api/join', mock.state.lastJoin && mock.state.lastJoin.name === '#arcade', JSON.stringify(mock.state.lastJoin))
  await js(win, `document.getElementById('btn-browse-back').click()`)
  check('back returns to my rooms', await waitJs(win, `document.getElementById('browse-list').hidden && document.getElementById('rooms-list').hidden === false && 'ok'`))
  check('joined room appears in the rooms list', await waitJs(win, `document.getElementById('rooms-list').textContent.includes('arcade') && 'ok'`))

  // Kick redirect: the server bounces the user out of the open room with a reason.
  mock.state.kickChannel = 'arcade'
  await new Promise((r) => setTimeout(r, 3500))
  check('kicked room leaves the rooms list', await waitJs(win, `!document.getElementById('rooms-list').textContent.includes('arcade') && 'ok'`))
  check('kicked user sees the removal reason', await waitJs(win, `!document.getElementById('modal').hidden && document.getElementById('modal-message').textContent.includes('removed') && 'ok'`))
  await js(win, `document.getElementById('modal-ok').click()`)

  // Theme toggle.
  const themeBefore = await js(win, `document.body.className`)
  await js(win, `document.getElementById('theme-toggle').click()`)
  const themeAfter = await js(win, `document.body.className`)
  check('theme toggle switches light/dark', themeBefore !== themeAfter, themeBefore + ' -> ' + themeAfter)

  // Tray icon must never be blank, and the icon asset must ship in the package.
  check('tray icon image is non-empty', messengerMain.trayImage && !messengerMain.trayImage().isEmpty(), '')
  const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8'))
  check('packaged files ship build/icon.png', Array.isArray(pkg.build.files) && pkg.build.files.includes('build/icon.png'), JSON.stringify(pkg.build.files))

  // Logout wipes the session cookie; because the login form saved the password
  // (keychain), the reload auto-logins with saved credentials → MFA gate.
  await js(win, `document.getElementById('logout-btn').click()`)
  check('logout hides the main view', await waitJs(win, `document.getElementById('view-main').hidden && 'ok'`))
  check('auto-login with saved creds reaches MFA', await waitJs(win, `!document.getElementById('view-mfa').hidden`))
  await js(win, `document.getElementById('mfa-code').value = '123456'; document.getElementById('mfa-form').requestSubmit()`)
  check('auto-login completes back to main', await waitJs(win, `!document.getElementById('view-main').hidden`))

  // ── updater (pure logic + server feed discovery) ──────────────────────────
  console.log('== updater ==')
  check('compareVersions newer', updater.compareVersions('1.10.0', '1.9.0') > 0)
  check('compareVersions older', updater.compareVersions('0.9.9', '1.0.0') < 0)
  check('parseFeedVersion yml', updater.parseFeedVersion('version: 0.2.0\nfiles:\n  - url: x\n') === '0.2.0')
  check('feedFileName win', updater.feedFileName('win32') === 'latest.yml')
  const avail = await updater.isUpdateAvailable('https://updates.example.com/messenger', '0.1.1', 'linux', async () => 'version: 0.2.0\n')
  check('isUpdateAvailable true on newer feed', avail === true)
  check('resolveFeedUrl defaults to upstream', updater.resolveFeedUrl([]) === updater.PACKAGE_FEED_URL)
  const optIn = updater.resolveFeedUrl([{ serverUpdaterUrl: 'https://feeds.example.com/x', useServerUpdates: true, lastConnectedAt: '2026-01-02' }])
  check('resolveFeedUrl honours a profile opt-in', optIn === 'https://feeds.example.com/x/messenger', optIn)

  // The launcher closes after login — reopen it to exercise the feed probe and
  // the UI update row.
  messengerMain.createLauncherWindow()
  const updLauncher = await waitLauncher()
  const probeOk = await js(updLauncher, `window.lvchat.probeServer({ url: ${JSON.stringify(mock.base)} })`)
  check('probe surfaces the server updater feed', probeOk.ok && probeOk.updaterUrl === mock.base, JSON.stringify(probeOk))
  const srv = await profiles.getServerUpdater(profiles.find(profileId))
  check('getServerUpdater resolves server feed links', srv.ok && (srv.apps.messenger.platforms.win.url || '').includes('/messenger.exe'), JSON.stringify(srv))
  const footer = await js(updLauncher, `(() => { const t = document.getElementById('update-status-text'); const b = document.getElementById('update-check'); return { hasStatus: !!t, hasBtn: !!b } })()`)
  check('launcher has update status row', footer.hasStatus && footer.hasBtn)
  const updState = await js(updLauncher, 'window.lvchat.updatesStatus()')
  check('updates:status IPC responds', updState && typeof updState.state === 'string', JSON.stringify(updState))
  const updFeed = await js(updLauncher, 'window.lvchat.updatesFeed()')
  check('updates:feed IPC responds', updFeed && typeof updFeed.url === 'string' && typeof updFeed.currentVersion === 'string', JSON.stringify(updFeed))

  mock.server.close()

  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}

app.whenReady().then(main)

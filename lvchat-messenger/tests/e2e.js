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

require('../src/main')

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
    groups: [{ id: 1, name: 'Friends', position: 0, members: [2] }],
    joinedChannels: [{ slug: 'gaming', unread: 3, online: 4 }, { slug: 'general', unread: 0, online: 1 }],
    invites: [
      { id: 5, channel_id: 9, channel_name: 'Dev Lounge', slug: 'dev', inviter: 'bob', created_at: '2026-08-06 07:00:00' },
      { id: 6, channel_id: 10, channel_name: 'Gamers Den', slug: 'gamers', inviter: 'carol', created_at: '2026-08-06 07:05:00' }
    ]
  }

  const friends = () => ([
    { id: 2, username: 'bob', avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00', friends_since: '2026-07-01 00:00:00', is_online: 1 },
    { id: 3, username: 'carol', avatar: null, role: 'user', away: null, last_seen: '2026-08-01 00:00:00', friends_since: '2026-07-01 00:00:00', is_online: 0 }
  ])
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
        return u
      })
      return { id: g.id, name: g.name, position: g.position, members }
    })
  }

  function pollPayload (url) {
    const since = Number(url.searchParams.get('since') || 0)
    const dm = url.searchParams.get('dm')
    const channel = url.searchParams.get('channel')
    const payload = {
      ok: true,
      messages: [],
      presence: [],
      notify_count: 1,
      dm_list: [{ user_id: 3, username: 'carol', role: 'user', guest: 0, away: null, last_seen: '2026-08-01 00:00:00', unread: 2, last_content: 'hey', last_id: 3, muted: 0 }],
      friends: friends(),
      friend_requests: incoming(),
      channel_invites: state.invites,
      channel_unread: state.joinedChannels.map((c) => ({ slug: c.slug, unread: c.unread })),
      channel_presence: state.joinedChannels.map((c) => ({ slug: c.slug, online: c.online }))
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
      res.setHeader('access-control-allow-headers', 'Content-Type, X-CSRF')
    }
    const cookie = req.headers.cookie || ''
    const hasSession = cookie.includes('session=abc123')
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
      json(200, { version: '1.0.0-test', site: 'Test Chat' })
      return
    }

    if (url.pathname === '/login' && req.method === 'GET') {
      if (hasSession) { res.writeHead(302, { location: '/app?channel=general' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end(html('login'))
      return
    }

    if (url.pathname === '/login' && req.method === 'POST') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const params = new URLSearchParams(body)
        const username = params.get('username')
        const password = params.get('password')
        if (username === 'alice' && password === 'password123') {
          res.writeHead(302, { location: '/login/mfa', 'set-cookie': 'pending=abc; Path=/' })
          res.end()
          return
        }
        if (username === 'bob' && password === 'secret') {
          res.writeHead(302, { location: '/app?channel=general', 'set-cookie': 'session=abc123; Path=/' })
          res.end()
          return
        }
        res.writeHead(200, { 'content-type': 'text/html' })
        res.end(html('login', 'Invalid username or password.'))
      })
      return
    }

    if (url.pathname === '/login/mfa' && req.method === 'GET') {
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end(html('mfa'))
      return
    }

    if (url.pathname === '/login/mfa' && req.method === 'POST') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const code = new URLSearchParams(body).get('code')
        if (hasPending && code === '123456') {
          res.writeHead(302, { location: '/app?channel=general', 'set-cookie': 'session=abc123; Path=/' })
          res.end()
          return
        }
        res.writeHead(200, { 'content-type': 'text/html' })
        res.end(html('mfa', 'Invalid authentication code. Try again.'))
      })
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
      json(200, { ok: true, user: { id: 1, username: 'alice', avatar: null, role: 'user', guest: 0, away: null, status: 'active' } })
      return
    }
    if (url.pathname === '/api/friends') {
      json(200, { ok: true, friends: friends(), incoming: incoming(), outgoing: outgoing(), blocked: [] })
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
    json(404, { error: 'Not found' })
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port
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

  // Registration is also reachable from each saved server row.
  await js(launcherWin, `(() => { const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('Test')); const b = li && [...li.querySelectorAll('button')].find((x) => x.textContent === 'Register'); if (b) b.click(); return !!b })()`)
  await waitExternal(mock.base + '/register')
  check('row Register opens the browser to /register', openedExternal === mock.base + '/register', String(openedExternal))

  const conn = await js(launcherWin, `window.lvchat.connectProfile({ id: ${JSON.stringify(profileId)} })`)
  check('connect opens a messenger window', conn.ok === true && conn.reused === false, JSON.stringify(conn))

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
  roomWin.close()
  await new Promise((r) => setTimeout(r, 400))

  // Switch to the Advanced view (persisted in prefs).
  await js(win, `document.getElementById('view-mode-btn').click()`)
  check('advanced view enabled after toggle', await waitJs(win, `document.body.classList.contains('advanced') && 'ok'`))
  check('chat pane visible in advanced', await waitJs(win, `document.getElementById('chat').offsetParent !== null && 'ok'`))
  const savedMode = await waitJs(win, `window.msg.prefsGet('viewMode')`)
  check('viewMode pref persisted', savedMode === 'advanced', String(savedMode))

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

  // Image upload (mock accepts the file without parsing its bytes).
  await js(win, `(() => { const f = new File([new Uint8Array([1,2,3])], 'pic.png', { type: 'image/png' }); const dt = new DataTransfer(); dt.items.add(f); const inp = document.getElementById('image-file'); inp.files = dt.files; inp.dispatchEvent(new Event('change')) })()`)
  check('image upload posts to stream', await waitJs(win, `[...document.querySelectorAll('#stream .msg img.media')].some((img) => img.src.includes('uploads')) && 'ok'`))

  // Buddy context menu (grouped member: window/profile/remove-from-group).
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('grouped member context menu has window + profile + remove-from-group', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Open in new window') && m.textContent.includes('View profile') && m.textContent.includes('Remove from Friends') && 'ok' })()`))

  // Buddy context menu (ungrouped friend: window/profile/remove-friend).
  await js(win, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('carol')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('buddy context menu shows window + profile + remove friend', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Open in new window') && m.textContent.includes('View profile') && m.textContent.includes('Remove friend') && 'ok' })()`))

  // Room view: gaming with members.
  await js(win, `document.getElementById('tab-rooms').click()`)
  check('rooms tab lists gaming', await waitJs(win, `document.getElementById('rooms-list').textContent.includes('gaming') && 'ok'`))
  await js(win, `(() => { const c = [...document.querySelectorAll('#rooms-list .contact')].find((c) => c.textContent.includes('gaming')); if (c) c.click() })()`)
  check('room opens with #gaming title', await waitJs(win, `document.getElementById('chat-title').textContent === '#gaming' && 'ok'`))
  await js(win, `document.getElementById('members-toggle').click()`)
  check('active members list shows bob', await waitJs(win, `!document.getElementById('members').hidden && document.getElementById('members').textContent.includes('bob') && 'ok'`))

  // Room context menu: leave + share link.
  await js(win, `(() => { const c = [...document.querySelectorAll('#rooms-list .contact')].find((c) => c.textContent.includes('gaming')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('room context menu shows leave + share link', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Leave room') && m.textContent.includes('Copy share link') && 'ok' })()`))

  // Message context menu: copy.
  await js(win, `(() => { const m = document.querySelector('#stream .msg:not(.system)'); if (!m) return 'no-msg'; m.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 100, clientY: 100 })); return 'ok' })()`)
  check('message context menu shows copy', await waitJs(win, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Copy message') && 'ok' })()`))

  // Theme toggle.
  const themeBefore = await js(win, `document.body.className`)
  await js(win, `document.getElementById('theme-toggle').click()`)
  const themeAfter = await js(win, `document.body.className`)
  check('theme toggle switches light/dark', themeBefore !== themeAfter, themeBefore + ' -> ' + themeAfter)

  // Logout wipes the session cookie; because the login form saved the password
  // (keychain), the reload auto-logins with saved credentials → MFA gate.
  await js(win, `document.getElementById('logout-btn').click()`)
  check('logout hides the main view', await waitJs(win, `document.getElementById('view-main').hidden && 'ok'`))
  check('auto-login with saved creds reaches MFA', await waitJs(win, `!document.getElementById('view-mfa').hidden`))
  await js(win, `document.getElementById('mfa-code').value = '123456'; document.getElementById('mfa-form').requestSubmit()`)
  check('auto-login completes back to main', await waitJs(win, `!document.getElementById('view-main').hidden`))

  mock.server.close()

  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}

app.whenReady().then(main)

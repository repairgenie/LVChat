/* LVChat Messenger startup-bypass + tray end-to-end test.
 *
 * Boots the real Electron app with a profile that has auto-connect set BEFORE
 * the main process loads, then verifies:
 *   - the Profile Manager is completely bypassed (never created at startup);
 *   - the messenger window opens straight to the login view and, after signing
 *     in, straight to the account's friends list;
 *   - the tray exists and uses the app icon;
 *   - closing a window with the X hides it to the tray (not destroyed);
 *   - logout still destroys hidden chat windows (the close-to-tray handler
 *     must not turn those into lingering hidden windows).
 */
const { app, BrowserWindow } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-messenger-startup-'))
app.setPath('userData', tmp)

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

function messengerWindow () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('messenger.html'))
}

function launcherWindow () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('launcher.html'))
}

function chatWindow (type, id) {
  const token = encodeURIComponent(type + ':' + id)
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('chat=' + token))
}

function js (win, code) {
  return Promise.race([
    win.webContents.executeJavaScript(code, true),
    new Promise((resolve) => setTimeout(() => resolve({ __timeout: true }), 30000))
  ])
}

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

const delay = (ms) => new Promise((r) => setTimeout(r, ms))

/* ── Mock LVChat server (minimal: enough for boot + login + friends render) ── */

function mockServer () {
  const CSRF = 'startup-csrf-token'
  const html = '<!doctype html><html><body>' +
    '<form action="/login" method="post">' +
    '<input type="hidden" name="csrf" value="' + CSRF + '">' +
    '<input name="username"><input name="password"></form>' +
    '<title>login</title></body></html>'

  const server = http.createServer((req, res) => {
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
    const hasSession = cookie.includes('session=startup123') || sessionHeader === 'startup-session-token'
    const json = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' })
      res.end(JSON.stringify(obj))
    }

    if (req.method === 'OPTIONS') { setCors(); res.writeHead(204); res.end(); return }
    setCors()

    if (url.pathname === '/api/version') { json(200, { version: '1.0.0-test', site: 'Startup Test Chat' }); return }

    if (url.pathname === '/login' && req.method === 'GET') {
      if (hasSession) { res.writeHead(302, { location: '/app?channel=general' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end(html)
      return
    }

    if (url.pathname === '/api/messenger/login' && req.method === 'POST') {
      if (req.headers['x-messenger'] !== '1') { json(403, { error: 'Not a messenger request.' }); return }
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const p = new URLSearchParams(body)
        if (p.get('username') === 'alice' && p.get('password') === 'password123') {
          json(200, { ok: true, token: 'startup-session-token' })
          return
        }
        json(401, { error: 'Invalid username or password.' })
      })
      return
    }
    if (url.pathname === '/api/messenger/logout' && req.method === 'POST') {
      json(200, { ok: true })
      return
    }

    if (url.pathname === '/app') {
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end('<html><body>app</body></html>')
      return
    }

    if (url.pathname === '/api/csrf') { json(200, { ok: true, csrf: CSRF }); return }

    if (!hasSession) { json(401, { error: 'Not authenticated.' }); return }

    if (url.pathname === '/api/me') {
      json(200, { ok: true, user: { id: 1, username: 'alice', avatar: null, role: 'user', guest: 0, away: null, status: 'active' } })
      return
    }
    if (url.pathname === '/api/friends') {
      json(200, { ok: true, friends: [{ id: 2, username: 'bob', avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00', friends_since: '2026-07-01 00:00:00', is_online: 1 }], incoming: [], outgoing: [], blocked: [] })
      return
    }
    if (url.pathname === '/api/groups') { json(200, { ok: true, groups: [] }); return }
    if (url.pathname === '/api/poll') {
      json(200, {
        ok: true, messages: [], presence: [], notify_count: 0, dm_list: [],
        friends: [{ id: 2, username: 'bob', avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00', friends_since: '2026-07-01 00:00:00', is_online: 1 }],
        friend_requests: [], channel_invites: [], channel_unread: [], channel_presence: []
      })
      return
    }
    json(404, { error: 'Not found' })
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port
      resolve({ base: 'http://127.0.0.1:' + port, server })
    })
  })
}

/* ── Test flow ────────────────────────────────────────────── */

async function main () {
  // The main process was required (below) with an auto-connect profile already
  // seeded, so whenReady must NOT have created the Profile Manager.
  const win = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = messengerWindow()
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 25000) resolve(null)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })

  check('messenger window opens directly at startup', !!win, '')
  win.webContents.on('console-message', (event, level, message, line) => {
    console.log('[renderer]', level, message, line || '')
  })

  // Give any accidental launcher creation time to appear, then assert none.
  await delay(1500)
  check('profile manager never created (auto-connect bypasses it)', launcherWindow() === undefined, '')

  check('tray exists', appExports.trayPresent() === true, '')

  // No saved session/credentials yet → the messenger shows its own login view.
  check('messenger login view shown (no session)', await waitJs(win, `!document.getElementById('view-login').hidden && 'ok'`))

  // Sign in inside the messenger window — lands on the friends list.
  await js(win, `document.getElementById('login-username').value = 'alice'; document.getElementById('login-password').value = 'password123'; document.getElementById('login-form').requestSubmit()`)
  check('main view shown after sign-in', await waitJs(win, `!document.getElementById('view-main').hidden && 'ok'`))
  check('friends tab is the active tab', await waitJs(win, `document.getElementById('tab-buddy').classList.contains('active') && 'ok'`))
  check('friends list renders (bob)', await waitJs(win, `document.getElementById('buddy-list').textContent.includes('bob') && 'ok'`))
  check('still no profile manager window', launcherWindow() === undefined, '')

  // Close-to-tray: clicking X hides the messenger window, not destroy it.
  win.close()
  await delay(600)
  check('X hides the messenger window to the tray', !win.isDestroyed() && !win.isVisible(), '')

  // Restore from the tray.
  win.show()
  await delay(300)
  check('tray restore brings the messenger window back', win.isVisible(), '')

  // Regression: a hidden chat window is destroyed (not just hidden) on logout.
  await js(win, `window.msg.openChat({ type: 'dm', id: 'bob' })`)
  const chatWin = await (async () => {
    const start = Date.now()
    for (;;) {
      const w = chatWindow('dm', 'bob')
      if (w && !w.webContents.isLoading()) return w
      if (Date.now() - start > 25000) return null
      await delay(80)
    }
  })()
  check('chat window opens', !!chatWin, '')
  chatWin.close()
  let chatHidden = false
  for (let i = 0; i < 80 && !chatHidden; i++) {
    await delay(100)
    chatHidden = chatWin.isDestroyed() || !chatWin.isVisible()
  }
  check('chat window hides to tray on X', !chatWin.isDestroyed() && chatHidden, 'destroyed=' + chatWin.isDestroyed() + ' visible=' + chatWin.isVisible() + ' min=' + chatWin.isMinimized())

  await js(win, `document.getElementById('logout-btn').click()`)
  await delay(1200)
  check('logout destroys hidden chat windows', chatWindow('dm', 'bob') === undefined, '')

  global.__mockServer.server.close()

  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}

/* Seed the auto-connect (or last-used) profile, then load the app. The mock
 * server must be listening first so its URL is baked into the profile before
 * whenReady runs. STARTUP_SEED=lastused seeds a profile WITHOUT auto-connect to
 * verify the launcher is bypassed there too (the messenger opens instead). */
let appExports = null
mockServer().then((mock) => {
  const seedMode = process.env.STARTUP_SEED === 'lastused' ? 'lastused' : 'auto'
  fs.writeFileSync(path.join(tmp, 'profiles.json'), JSON.stringify({
    version: 2,
    defaultUrl: mock.base,
    profiles: [{
      id: 'startup-test-profile',
      name: 'Startup',
      url: mock.base,
      username: 'alice',
      autoConnect: seedMode === 'auto',
      lastConnectedAt: seedMode === 'lastused' ? new Date().toISOString() : null
    }]
  }), 'utf8')
  appExports = require('../src/main')
  global.__mockServer = mock
  app.whenReady().then(main)
})

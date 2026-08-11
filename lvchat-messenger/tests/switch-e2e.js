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

/* LVChat Messenger account-switch end-to-end test.
 *
 * Reproduces the reported bug "switching accounts via profile manager does not
 * work": with two same-server profiles (multi-account), connect profile A, log
 * in, then switch to profile B from the Profile Manager and verify:
 *   - profile A's messenger window is torn down;
 *   - profile B's messenger window opens (landing on its own login, since B has
 *     no session yet on this device);
 *   - the Profile Manager reflects the new connection (B connected, A idle).
 */
const { app, BrowserWindow } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-messenger-switch-'))
app.setPath('userData', tmp)

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

const A = 'switch-alice'
const B = 'switch-bob'

function messengerFor (profileId) {
  const token = 'messenger.html?profile=' + encodeURIComponent(profileId)
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes(token))
}

function launcherWindow () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('launcher.html'))
}

function js (win, code) {
  return Promise.race([
    win.webContents.executeJavaScript(code, true),
    new Promise((resolve) => setTimeout(() => resolve({ __timeout: true }), 30000))
  ])
}

async function waitJs (win, expr, ms) {
  const timeout = ms || 20000
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

/* ── Mock LVChat server (alice + bob accounts on the same server) ── */

function mockServer () {
  const CSRF = 'switch-csrf-token'
  const html = (title) => '<!doctype html><html><body>' +
    '<form action="/login" method="post">' +
    '<input type="hidden" name="csrf" value="' + CSRF + '">' +
    '<input name="username"><input name="password"></form>' +
    '<title>' + title + '</title></body></html>'

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
    const hasSession = cookie.includes('session=switch123') || sessionHeader === 'switch-session-token'
    const json = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' })
      res.end(JSON.stringify(obj))
    }

    if (req.method === 'OPTIONS') { setCors(); res.writeHead(204); res.end(); return }
    setCors()

    if (url.pathname === '/api/version') { json(200, { version: '1.0.0-test', site: 'Switch Test Chat' }); return }

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
        const p = new URLSearchParams(body)
        const u = p.get('username')
        const pass = p.get('password')
        if ((u === 'alice' && pass === 'password123') || (u === 'bob' && pass === 'secret')) {
          json(200, { ok: true, token: 'switch-session-token' })
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

    if (url.pathname === '/api/csrf') { json(200, { ok: true, csrf: CSRF }); return }
    if (url.pathname === '/app') {
      if (!hasSession) { res.writeHead(302, { location: '/login?next=/app' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end('<html><body>app</body></html>')
      return
    }

    if (!hasSession) { json(401, { error: 'Not authenticated.' }); return }

    if (url.pathname === '/api/me') {
      json(200, { ok: true, user: { id: 1, username: 'alice', avatar: null, role: 'user', guest: 0, away: null, status: 'active' } })
      return
    }
    if (url.pathname === '/api/friends') {
      json(200, { ok: true, friends: [], incoming: [], outgoing: [], blocked: [] })
      return
    }
    if (url.pathname === '/api/groups') { json(200, { ok: true, groups: [] }); return }
    if (url.pathname === '/api/poll') {
      json(200, {
        ok: true, messages: [], presence: [], notify_count: 0, dm_list: [],
        friends: [], friend_requests: [], channel_invites: [], channel_unread: [], channel_presence: [], blocked: []
      })
      return
    }
    if (url.pathname === '/api/sounds') {
      json(200, { ok: true, sounds: {}, dm_sound_id: null, channel_sound_id: null, overrides: {} })
      return
    }
    if (url.pathname === '/api/push/prefs') { json(200, { ok: true, prefs: { channels: 1, dms: 1, invites: 1 } }); return }
    if (url.pathname === '/api/notifications') { json(200, { ok: true, notifications: [] }); return }
    if (url.pathname === '/api/ws/ticket') { json(200, { ok: true, ticket: 'switch-ticket', url: 'ws://127.0.0.1:1/' }); return }
    json(404, { error: 'Not found' })
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({ base: 'http://127.0.0.1:' + server.address().port, server })
    })
  })
}

/* ── Test flow ────────────────────────────────────────────── */

async function main () {
  // The launcher connects to the mock server, so it must be listening before
  // the app boots. Seed both profiles (no auto-connect): the most recently
  // used (A) opens a messenger window at startup; B stays idle.
  const win = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = messengerFor(A)
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 20000) resolve(null)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })

  // Profile A opens at startup (last used) → its own login view.
  check('profile A messenger opens at startup', !!win, '')
  if (!win) { global.__mockServer.server.close(); process.exit(1) }
  win.webContents.on('console-message', (event, level, message, line) => {
    console.log('[renderer]', level, message, line || '')
  })
  check('profile A shows login view', await waitJs(win, `!document.getElementById('view-login').hidden && 'ok'`))

  // Log in as alice → friends list.
  await js(win, `document.getElementById('login-username').value = 'alice'; document.getElementById('login-password').value = 'password123'; document.getElementById('login-form').requestSubmit()`)
  check('profile A main view after sign-in', await waitJs(win, `!document.getElementById('view-main').hidden && 'ok'`))

  // Reopen the Profile Manager from the messenger header.
  await js(win, `document.getElementById('profile-manager').click()`)
  const launcher = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = launcherWindow()
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 15000) resolve(null)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
  check('profile manager reopens from the messenger', !!launcher, '')

  // Switch to profile B from its row.
  const enabled = await waitJs(launcher, `(() => {
    const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('bob'))
    if (!li) return false
    const b = [...li.querySelectorAll('button')].find((x) => x.textContent === 'Switch')
    return !!b && !b.disabled
  })()`)
  check('Switch on profile B is enabled', !!enabled, '')
  const switched = await js(launcher, `(() => {
    const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('bob'))
    const b = [...li.querySelectorAll('button')].find((x) => x.textContent === 'Switch')
    b.click()
    return 'clicked'
  })()`)
  check('Switch on profile B is clickable', switched === 'clicked', String(switched))

  // Profile A's window is torn down; profile B's window opens.
  const bGone = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      if (!messengerFor(A)) resolve(true)
      else if (Date.now() - start > 15000) resolve(false)
      else setTimeout(tryGet, 80)
    }
    tryGet()
  })
  check('switching closes profile A messenger window', bGone, '')

  const bWin = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = messengerFor(B)
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 20000) resolve(null)
      else setTimeout(tryGet, 80)
    }
    tryGet()
  })
  check('profile B messenger window opens', !!bWin, '')
  check('profile B lands on its own login view', bWin ? await waitJs(bWin, `!document.getElementById('view-login').hidden && 'ok'`) : false)

  // The Profile Manager reflects the new state: B connected, A idle again.
  check('launcher shows B connected after switch', launcher ? await waitJs(launcher, `(() => {
    const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('bob'))
    return !!li && li.textContent.includes('connected')
  })()`) : false)

  // Switching back to A works too: close B (it's idle at login), switch to A.
  check('Switch on A is enabled once B is connected', launcher ? await waitJs(launcher, `(() => {
    const li = [...document.querySelectorAll('#server-list li')].find((x) => x.textContent.includes('alice'))
    if (!li) return false
    const b = [...li.querySelectorAll('button')].find((x) => x.textContent === 'Switch')
    return !!b && !b.disabled
  })()`) : false)

  global.__mockServer.server.close()

  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}

/* Seed the two same-server profiles, then load the app. A (alice) is the most
 * recently used so its messenger opens at startup; B (bob) is idle. */
mockServer().then((mock) => {
  fs.writeFileSync(path.join(tmp, 'profiles.json'), JSON.stringify({
    version: 2,
    defaultUrl: mock.base,
    profiles: [
      {
        id: A,
        name: 'Alice',
        url: mock.base,
        username: 'alice',
        autoConnect: false,
        lastConnectedAt: new Date().toISOString()
      },
      {
        id: B,
        name: 'Bob',
        url: mock.base,
        username: 'bob',
        autoConnect: false,
        lastConnectedAt: null
      }
    ]
  }), 'utf8')
  global.__mockServer = mock
  require('../src/main')
  app.whenReady().then(main)
})

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

/* LVChat Messenger multi-account menu-stability reproduction.
 *
 * Logs into TWO profiles on the same server (two messenger windows, both in
 * main view), then checks that menus stay usable across several 2s polls:
 *   - the ☰ head menu must not flicker / re-create while open;
 *   - the buddy right-click context menu must survive poll re-renders;
 *   - clicking a "Switch account" item must work.
 */
const { app, BrowserWindow } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-messenger-multi-'))
app.setPath('userData', tmp)

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

const A = 'multi-alice'
const B = 'multi-bob'

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

/* ── Mock server with PER-USER sessions (session cookie = username) ── */

function mockServer () {
  const CSRF = 'multi-csrf-token'
  const ACCOUNTS = { alice: 'password123', bob: 'secret' }
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
    const session = /session=([a-z]+)/.exec(cookie)
    const sessionHeader = String(req.headers['x-lvc-session'] || '')
    const headerWho = /session-([a-z]+)/.exec(sessionHeader)
    const who = headerWho ? headerWho[1] : (session ? session[1] : null)
    const json = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' })
      res.end(JSON.stringify(obj))
    }

    if (req.method === 'OPTIONS') { setCors(); res.writeHead(204); res.end(); return }
    setCors()

    if (url.pathname === '/api/version') { json(200, { version: '1.0.0-test', site: 'Multi Test Chat' }); return }

    if (url.pathname === '/login' && req.method === 'GET') {
      if (who) { res.writeHead(302, { location: '/app?channel=general' }); res.end(); return }
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
        const u = p.get('username')
        if (ACCOUNTS[u] && ACCOUNTS[u] === p.get('password')) {
          json(200, { ok: true, token: 'session-' + u })
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
      if (!who) { res.writeHead(302, { location: '/login?next=/app' }); res.end(); return }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end('<html><body>app</body></html>')
      return
    }
    if (!who) { json(401, { error: 'Not authenticated.' }); return }

    const me = (id, username) => ({ ok: true, user: { id, username, avatar: null, role: 'user', guest: 0, away: null, status: 'active' } })
    const friendsOf = (username) => {
      const other = username === 'alice' ? { id: 2, username: 'bob' } : { id: 1, username: 'alice' }
      return [Object.assign({ avatar: null, role: 'user', away: null, last_seen: '2026-08-06 09:59:00', friends_since: '2026-07-01 00:00:00', is_online: 1, status_mode: 'online', custom_status: '', muted: 0 }, other)]
    }

    if (url.pathname === '/api/me') { json(200, me(who === 'alice' ? 1 : 2, who)); return }
    if (url.pathname === '/api/friends') { json(200, { ok: true, friends: friendsOf(who), incoming: [], outgoing: [], blocked: [] }); return }
    if (url.pathname === '/api/groups') { json(200, { ok: true, groups: [] }); return }
    if (url.pathname === '/api/poll') {
      json(200, {
        ok: true, messages: [], presence: [], notify_count: 0, dm_list: [],
        friends: friendsOf(who), friend_requests: [], channel_invites: [], channel_unread: [], channel_presence: [], blocked: []
      })
      return
    }
    if (url.pathname === '/api/sounds') { json(200, { ok: true, sounds: {}, dm_sound_id: null, channel_sound_id: null, overrides: {} }); return }
    if (url.pathname === '/api/push/prefs') { json(200, { ok: true, prefs: { channels: 1, dms: 1, invites: 1 } }); return }
    if (url.pathname === '/api/notifications') { json(200, { ok: true, notifications: [] }); return }
    if (url.pathname === '/api/ws/ticket') { json(200, { ok: true, ticket: 'multi-ticket', url: 'ws://127.0.0.1:1/' }); return }
    json(404, { error: 'Not found' })
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({ base: 'http://127.0.0.1:' + server.address().port, server })
    })
  })
}

async function login (win, username, password) {
  await js(win, `document.getElementById('login-username').value = ${JSON.stringify(username)}; document.getElementById('login-password').value = ${JSON.stringify(password)}; document.getElementById('login-form').requestSubmit()`)
}

async function main () {
  // Both profiles have autoConnect, so both messenger windows open at startup.
  const winA = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = messengerFor(A)
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 20000) resolve(null)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
  check('profile A messenger opens at startup', !!winA, '')
  if (!winA) { global.__mockServer.server.close(); process.exit(1) }
  winA.webContents.on('console-message', (event, level, message, line) => {
    console.log('[A]', level, message, line || '')
  })

  const winB = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = messengerFor(B)
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 20000) resolve(null)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
  check('profile B messenger opens at startup', !!winB, '')
  if (!winB) { global.__mockServer.server.close(); process.exit(1) }
  winB.webContents.on('console-message', (event, level, message, line) => {
    console.log('[B]', level, message, line || '')
  })

  // Log into both accounts.
  await login(winA, 'alice', 'password123')
  check('A is logged in (main view)', await waitJs(winA, `!document.getElementById('view-main').hidden && 'ok'`))
  await login(winB, 'bob', 'secret')
  check('B is logged in (main view)', await waitJs(winB, `!document.getElementById('view-main').hidden && 'ok'`))
  check('both profiles have live messenger windows', !!messengerFor(A) && !!messengerFor(B), '')

  // ── Head menu stability across polls ─────────────────────
  await js(winA, `(() => { const b = document.getElementById('menu-btn'); b.dispatchEvent(new MouseEvent('click', { bubbles: true })); return 'ok' })()`)
  check('head menu opens', await waitJs(winA, `!document.getElementById('head-menu').hidden && document.getElementById('head-menu').textContent.includes('Switch account') && 'ok'`))
  const menuRef = await js(winA, `document.getElementById('head-menu').dataset.tag = 'menu-a'; document.getElementById('head-menu').tagName + '/' + document.getElementById('head-menu').dataset.tag`)
  await delay(5000) // ~2 poll cycles
  check('head menu stays open across polls', await waitJs(winA, `!document.getElementById('head-menu').hidden && 'ok'`))
  const menuStill = await js(winA, `document.getElementById('head-menu').dataset.tag`)
  check('head menu is not re-created (no flicker)', menuStill === 'menu-a', String(menuStill))

  // Click the "Switch account" item for bob — it must register.
  const itemClicked = await js(winA, `(() => {
    const b = [...document.querySelectorAll('#head-menu button')].find((x) => x.textContent.includes('bob') && x.textContent.includes('Switch account') === false)
    if (!b) return 'no-item'
    b.click()
    return 'clicked'
  })()`)
  check('switch-account item is clickable', itemClicked === 'clicked', String(itemClicked))
  check('switch confirm modal appears', await waitJs(winA, `!document.getElementById('modal').hidden && 'ok'`))

  // Cancel the switch, then test the buddy context menu.
  await js(winA, `document.getElementById('modal-cancel').click()`)

  // ── Buddy right-click context menu stability across polls ─
  await js(winA, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (!c) return 'no-row'; c.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 120, clientY: 120 })); return 'ok' })()`)
  check('buddy context menu opens', await waitJs(winA, `(() => { const m = document.querySelector('.ctx-menu'); return !!m && m.textContent.includes('Open in new window') && 'ok' })()`))
  const ctxRef = await js(winA, `(() => { const m = document.querySelector('.ctx-menu'); m.dataset.tag = 'ctx-a'; return m.dataset.tag })()`)
  await delay(5000) // ~2 poll cycles
  const ctxStill = await js(winA, `(() => { const m = document.querySelector('.ctx-menu'); return m ? m.dataset.tag : null })()`)
  check('buddy context menu survives poll re-renders', ctxStill === 'ctx-a', String(ctxStill))

  // ── The core flicker fix: unchanged polls must NOT rebuild the buddy list ──
  // Previously every poll did list.replaceChildren(), so the row under the
  // cursor was destroyed mid-interaction (menus flickered, clicks swallowed).
  const rowTag = await js(winA, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (!c) return 'none'; c.dataset.tag = 'row-a'; return c.dataset.tag })()`)
  await delay(5000) // ~2 poll cycles with identical data
  const rowStill = await js(winA, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); return c ? c.dataset.tag : null })()`)
  check('buddy list is not rebuilt on unchanged polls', rowTag === 'row-a' && rowStill === 'row-a', String(rowTag) + ' -> ' + String(rowStill))

  // Double-click on a contact must open a DM chat window even mid-poll.
  await js(winA, `(() => { const c = [...document.querySelectorAll('#buddy-list .contact')].find((c) => c.textContent.includes('bob')); if (c) c.dispatchEvent(new MouseEvent('dblclick', { bubbles: true })); return 'ok' })()`)
  const dmWin = await new Promise((resolve) => {
    const start = Date.now()
    const tryGet = () => {
      const w = BrowserWindow.getAllWindows().find((x) => !x.webContents.isDestroyed() && x.webContents.getURL().includes('chat=dm%3Abob'))
      if (w && !w.webContents.isLoading()) resolve(w)
      else if (Date.now() - start > 15000) resolve(null)
      else setTimeout(tryGet, 80)
    }
    tryGet()
  })
  check('double-click opens a DM window', !!dmWin, '')
  if (dmWin) dmWin.close()

  global.__mockServer.server.close()
  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}

mockServer().then((mock) => {
  fs.writeFileSync(path.join(tmp, 'profiles.json'), JSON.stringify({
    version: 2,
    defaultUrl: mock.base,
    profiles: [
      { id: A, name: 'Alice', url: mock.base, username: 'alice', autoConnect: true, lastConnectedAt: new Date().toISOString() },
      { id: B, name: 'Bob', url: mock.base, username: 'bob', autoConnect: true, lastConnectedAt: new Date().toISOString() }
    ]
  }), 'utf8')
  global.__mockServer = mock
  require('../src/main')
  app.whenReady().then(main)
})

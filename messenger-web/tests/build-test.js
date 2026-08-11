#!/usr/bin/env node
'use strict'

/* LVChat Messenger Web — zero-dependency test suite.
 *
 *   Part 1  build.js: .env parsing, config validation, dist/ artifacts
 *           (config.js, manifest, sw.js, icons) for a scratch .env.
 *   Part 2  web-bridge.js: the window.msg web bridge against stubbed browser
 *           globals (profile, credentials, prefs, notifications, clipboard).
 *   Part 3  api.js + a mock LVChat server: csrf → login (bad creds → MFA) →
 *           mfaVerify → /api/me (with vapidPublicKey) → push subscribe.
 *   Part 4  preview server: dist/ is served with correct types + content.
 */

const { build, parseEnv, loadConfig } = require('../build.js')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')
const vm = require('vm')
const { spawn } = require('child_process')

const ROOT = path.join(__dirname, '..')
const DIST = path.join(ROOT, 'dist')

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

/* ── Part 1: build ─────────────────────────────────────────────────────── */

function scratchEnv (content) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lvcweb-env-'))
  const file = path.join(dir, '.env')
  fs.writeFileSync(file, content)
  return file
}

function readText (p) {
  return fs.readFileSync(p, 'utf8')
}

{
  const envFile = scratchEnv([
    '# comment line',
    '',
    'LVCHAT_SERVER_URL=https://chat.example.com',
    'APP_NAME="Messenger X"',
    'APP_THEME_COLOR = #123456',
    "APP_SHORT_NAME='Msg'",
    'APP_VERSION=2.3.4'
  ].join('\n'))
  build({ env: envFile, clean: true })

  const configJs = readText(path.join(DIST, 'config.js'))
  check('dist/config.js written', fs.existsSync(path.join(DIST, 'config.js')))
  check('config.js embeds the server URL', configJs.includes('https://chat.example.com'))
  check('config.js embeds the app name', configJs.includes('Messenger X'))
  check('config.js sets shortName', configJs.includes('"shortName": "Msg"'))

  const manifest = JSON.parse(readText(path.join(DIST, 'manifest.webmanifest')))
  check('manifest name from .env', manifest.name === 'Messenger X', manifest.name)
  check('manifest short_name from .env', manifest.short_name === 'Msg', manifest.short_name)
  check('manifest theme color from .env', manifest.theme_color === '#123456', manifest.theme_color)
  check('manifest has icons', Array.isArray(manifest.icons) && manifest.icons.length === 3)
  check('manifest is standalone + scoped', manifest.display === 'standalone' && manifest.scope === './')

  const sw = readText(path.join(DIST, 'sw.js'))
  check('sw.js has no leftover placeholders', !/__CACHE_VERSION__|__PRECACHE__/.test(sw))
  check('sw.js precaches the shell', sw.includes('messenger.html') && sw.includes('web-bridge.js'))
  check('sw.js cache version is stamped', /lvcweb-shell-v[0-9a-z]+'/.test(sw))

  for (const f of ['index.html', 'messenger.html', 'messenger.css', 'messenger.js', 'api.js', 'emoji.js', 'web-bridge.js', 'offline.html']) {
    check('dist copies ' + f, fs.existsSync(path.join(DIST, f)))
  }
  for (const f of ['icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'apple-touch-icon.png', 'favicon.png']) {
    const p = path.join(DIST, 'icons', f)
    check('icon ' + f + ' exists', fs.existsSync(p) && fs.statSync(p).size > 100)
  }
  const buildInfo = JSON.parse(readText(path.join(DIST, 'build-info.json')))
  check('build-info records the config', buildInfo.config.serverUrl === 'https://chat.example.com' && !!buildInfo.cacheVersion)
}

{
  // .env parsing edge cases (quotes, spacing, comments, inline values).
  const env = parseEnv('A=1\nB = "two"\nC=\'3\'\n#D=no\n\nE=https://x/?q=1#frag\n')
  check('parseEnv trims + strips quotes', env.B === 'two' && env.C === '3', JSON.stringify(env))
  check('parseEnv keeps # inside values', env.E === 'https://x/?q=1#frag', env.E)
  check('parseEnv ignores comments/blanks', env.A === '1' && env.D === undefined)

  // Validation: missing URL must throw (loadConfig is imported, so fail() throws).
  let threw = false
  try { loadConfig(scratchEnv('APP_NAME=X\n')) } catch (err) { threw = true }
  check('build rejects a missing LVCHAT_SERVER_URL', threw)

  let threwBad = false
  try { loadConfig(scratchEnv('LVCHAT_SERVER_URL=ftp://nope\n')) } catch (err) { threwBad = true }
  check('build rejects a non-http(s) URL', threwBad)

  const cfg = loadConfig(scratchEnv('LVCHAT_SERVER_URL=https://chat.example.com/path?q=1\n'))
  check('server URL is normalized to origin', cfg.serverUrl === 'https://chat.example.com', cfg.serverUrl)
  check('default app name applied', cfg.appName === 'LVChat Messenger', cfg.appName)
}

/* ── Parts 2–4: mock LVChat server + VM tests + preview server ─────────── */

const MOCK_PORT = 18531
const PREVIEW_PORT = 18532
let mockSession = false

const mockServer = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1:' + MOCK_PORT)
  const cookie = req.headers.cookie || ''
  mockSession = cookie.includes('session=abc123')
  const json = (code, obj) => {
    res.writeHead(code, { 'content-type': 'application/json; charset=utf-8' })
    res.end(JSON.stringify(obj))
  }
  const html = (code, body) => {
    res.writeHead(code, { 'content-type': 'text/html; charset=utf-8' })
    res.end(body)
  }
  const formBody = () => new Promise((resolve) => {
    let data = ''
    req.on('data', (c) => { data += c })
    req.on('end', () => resolve(new URLSearchParams(data)))
  })

  if (url.pathname === '/api/csrf') return json(200, { csrf: 'csrf-token-123' })
  if (url.pathname === '/login' && req.method === 'POST') {
    return formBody().then((body) => {
      const username = body.get('username') || ''
      const password = body.get('password') || ''
      if (username !== 'alice' || password !== 'password123') {
        return html(200, '<form action="/login" method="post"><div class="text-red-400">Invalid username or password.</div></form>')
      }
      res.writeHead(302, { location: '/login/mfa', 'set-cookie': 'pending=abc; Path=/' })
      return res.end()
    })
  }
  if (url.pathname === '/login/mfa' && req.method === 'GET') return html(200, '<form action="/login/mfa" method="post">mfa</form>')
  if (url.pathname === '/login/mfa' && req.method === 'POST') {
    return formBody().then((body) => {
      if (body.get('code') !== '123456') {
        return html(200, '<form action="/login/mfa" method="post"><div class="text-red-400">Invalid authentication code.</div></form>')
      }
      res.writeHead(302, { location: '/app?channel=general', 'set-cookie': 'session=abc123; Path=/' })
      return res.end()
    })
  }
  if (url.pathname === '/api/me') {
    if (!mockSession) return json(401, { error: 'Not authenticated.' })
    return json(200, { ok: true, user: { id: 1, username: 'alice', avatar: null, role: 'user', guest: 0 }, vapidPublicKey: 'VAPIDPUBLICKEY' })
  }
  if (url.pathname === '/api/commands') return json(200, { commands: ['help', 'sanick', 'kick'] })
  if (url.pathname === '/api/push/subscribe' && req.method === 'POST') {
    return formBody().then(() => (mockSession ? json(200, { ok: true }) : json(401, { error: 'Not authenticated.' })))
  }
  if (url.pathname === '/api/friends') return json(200, { ok: true, friends: [], incoming: [], outgoing: [], blocked: [] })
  if (url.pathname === '/api/groups') return json(200, { ok: true, groups: [] })
  if (url.pathname === '/api/poll') return json(200, { ok: true, reconnect: false, dm_list: [], friend_requests: [], blocked: [], channel_invites: [], channel_unread: [], channel_presence: [], messages: [], bg_messages: [] })
  if (url.pathname === '/api/push/prefs') return json(200, { ok: true, prefs: { channels: 1, dms: 1, invites: 1 } })
  if (url.pathname === '/api/sounds') return json(200, { ok: true, sounds: {} })
  if (url.pathname === '/api/ws/ticket') return json(200, { ok: true, ticket: 't', url: 'ws://127.0.0.1:' + MOCK_PORT + '/ws' })
  return html(404, 'not found: ' + url.pathname)
})

/* A fetch stub for the api.js VM: follows redirects, keeps a cookie jar. */
function sandboxFetch (base, fetchUrl, opts) {
  const url = new URL(String(fetchUrl), base)
  return new Promise((resolve) => {
    const doRequest = (target, redirects) => {
      const u = new URL(target)
      const reqOpts = {
        hostname: u.hostname,
        port: u.port,
        path: u.pathname + u.search,
        method: opts.method || 'GET',
        headers: { cookie: cookieHeader() }
      }
      if (opts.method === 'POST') reqOpts.headers['content-type'] = 'application/x-www-form-urlencoded'
      const rq = http.request(reqOpts, (rs) => {
        const chunks = []
        rs.on('data', (c) => chunks.push(c))
        rs.on('end', () => {
          const setCookies = rs.headers['set-cookie'] || []
          for (const sc of setCookies) {
            const name = String(sc).split(';')[0].split('=')[0].trim()
            const value = String(sc).split(';')[0].split('=').slice(1).join('=').trim()
            if (value) cookies[name] = value
            else delete cookies[name]
          }
          const body = Buffer.concat(chunks)
          const status = rs.statusCode || 0
          if (status >= 300 && status < 400 && rs.headers.location) {
            if (redirects >= 10) return resolve(plainResponse(500, body))
            return doRequest(new URL(rs.headers.location, target).toString(), redirects + 1)
          }
          resolve(buildResponse(new URL(target), body, status, target, redirects > 0))
        })
      })
      rq.on('error', () => resolve({ status: 0, ok: false }))
      if (opts.method === 'POST') rq.write(opts.body ? opts.body.toString() : '')
      rq.end()
    }
    doRequest(url.toString(), 0)
  })
}

let cookies = {}
function cookieHeader () {
  return Object.keys(cookies).map((k) => k + '=' + cookies[k]).join('; ')
}
function buildResponse (u, body, status, finalUrl, redirected) {
  return {
    status,
    ok: status >= 200 && status < 300,
    url: finalUrl,
    redirected: !!redirected,
    text: () => Promise.resolve(body.toString()),
    json: () => Promise.resolve(JSON.parse(body.toString()))
  }
}
function plainResponse (status, body) {
  return {
    status,
    ok: status >= 200 && status < 300,
    url: '',
    redirected: false,
    text: () => Promise.resolve(body.toString()),
    json: () => Promise.resolve({})
  }
}

function loadApiClient () {
  const sandbox = {
    window: {},
    console,
    URLSearchParams,
    URL,
    fetch: (url, opts) => sandboxFetch('http://127.0.0.1:' + MOCK_PORT, url, opts)
  }
  sandbox.globalThis = sandbox
  vm.createContext(sandbox)
  vm.runInContext(readText(path.join(ROOT, 'src', 'api.js')), sandbox)
  return sandbox.window.LvApi
}

/* Part 3: api.js against the mock server. */
;(async () => {
  await new Promise((resolve) => mockServer.listen(MOCK_PORT, resolve))

  const LvApi = loadApiClient()
  LvApi.init('http://127.0.0.1:' + MOCK_PORT)

  const tok = await LvApi.csrf()
  check('api: csrf fetched', tok === 'csrf-token-123', String(tok))

  const bad = await LvApi.login('alice', 'wrong')
  check('api: bad password surfaces the flash error', bad.error === 'Invalid username or password.', JSON.stringify(bad))

  const mfa = await LvApi.login('alice', 'password123')
  check('api: correct password lands on the MFA gate', mfa.mfa === true, JSON.stringify(mfa))

  const badCode = await LvApi.mfaVerify('000000')
  check('api: wrong MFA code rejected', !!badCode.error, JSON.stringify(badCode))

  const ok = await LvApi.mfaVerify('123456')
  check('api: MFA verify completes the login', ok.ok === true, JSON.stringify(ok))

  const me = await LvApi.getJson('/api/me')
  check('api: /api/me returns the session user', me.ok && me.body.user.username === 'alice')
  check('api: /api/me exposes the VAPID public key', me.ok && me.body.vapidPublicKey === 'VAPIDPUBLICKEY')

  const sub = await LvApi.postForm('/api/push/subscribe', { endpoint: 'https://push.example/x', p256dh: 'abc', auth: 'def' })
  check('api: push subscribe accepted', sub.ok && sub.body && sub.body.ok === true, JSON.stringify(sub))

  /* Part 2: web-bridge against stubbed browser globals. */
  let notifShown = false
  const fakeNotification = function (title, opts) {
    notifShown = title + '|' + (opts && opts.body)
  }
  fakeNotification.permission = 'granted'
  fakeNotification.requestPermission = () => Promise.resolve('granted')
  const clipboardCalls = []
  const store = new Map()
  const bridgeSandbox = {
    window: { isSecureContext: true },
    console,
    URLSearchParams,
    URL,
    atob,
    btoa,
    localStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: (k) => store.delete(k)
    },
    navigator: {
      platform: 'Linux',
      clipboard: { writeText: async (t) => { clipboardCalls.push(t); return true } },
      serviceWorker: undefined
    },
    document: { title: 'initial' },
    Notification: fakeNotification
  }
  bridgeSandbox.window.LVCHAT_CONFIG = {
    serverUrl: 'https://chat.example.com',
    appName: 'Test Messenger',
    shortName: 'Test',
    version: '1.0.0'
  }
  bridgeSandbox.window.open = () => null
  bridgeSandbox.window.openConversationOrWindow = undefined
  bridgeSandbox.window.location = { href: '' }
  bridgeSandbox.globalThis = bridgeSandbox
  vm.createContext(bridgeSandbox)
  vm.runInContext(readText(path.join(ROOT, 'src', 'web-bridge.js')), bridgeSandbox)
  const msg = bridgeSandbox.window.msg

  const prof = msg.profile()
  check('bridge: single profile from config', prof.id === 'default' && prof.url === 'https://chat.example.com' && prof.name === 'Test Messenger', JSON.stringify(prof))

  const creds = await msg.savedCredentials()
  check('bridge: no saved password', creds.hasPassword === false && creds.username === null, JSON.stringify(creds))
  await msg.saveCredentials({ username: 'alice', password: 'secret' })
  const creds2 = await msg.savedCredentials()
  check('bridge: save remembers only the username', creds2.username === 'alice' && !creds2.password, JSON.stringify(creds2))

  await msg.prefsSet('theme', 'light')
  check('bridge: prefs round-trip', await msg.prefsGet('theme') === 'light')
  check('bridge: missing pref is null', await msg.prefsGet('nope') === null)

  msg.setUnread(3)
  check('bridge: unread badge in the title', bridgeSandbox.document.title === 'Test Messenger (3)', bridgeSandbox.document.title)
  msg.setUnread(0)
  check('bridge: title clears', bridgeSandbox.document.title === 'Test Messenger')

  await msg.copyText('hello world')
  check('bridge: clipboard write', clipboardCalls[0] === 'hello world')

  const tn = await msg.testNotification()
  check('bridge: test notification shows + reports', tn.ok === true && tn.state === 'shown' && typeof notifShown === 'string' && notifShown.includes('Test Messenger'), JSON.stringify(tn))

  const lp = await msg.listProfiles()
  check('bridge: only the one profile exists', lp.profiles.length === 1 && lp.profiles[0].id === 'default')
  const sw = await msg.switchProfile({ id: 'default' })
  check('bridge: switching profiles is refused', sw.ok === false)

  /* Part 4: preview server serves the built dist/. */
  const preview = spawn(process.execPath, [path.join(ROOT, 'preview.js'), String(PREVIEW_PORT)], { stdio: 'ignore' })
  await new Promise((resolve) => setTimeout(resolve, 600))
  try {
    const get = (p) => new Promise((resolve) => {
      http.get({ hostname: '127.0.0.1', port: PREVIEW_PORT, path: p }, (rs) => {
        const chunks = []
        rs.on('data', (c) => chunks.push(c))
        rs.on('end', () => resolve({ status: rs.statusCode, type: rs.headers['content-type'] || '', body: Buffer.concat(chunks).toString() }))
      }).on('error', () => resolve({ status: 0, type: '', body: '' }))
    })
    const root = await get('/')
    check('preview: / serves the messenger', root.status === 200 && root.type.includes('text/html') && root.body.includes('LVChat Messenger'))
    const cfg = await get('/config.js')
    check('preview: config.js served as JS', cfg.status === 200 && cfg.type.includes('javascript') && cfg.body.includes('chat.example.com'))
    const sw2 = await get('/sw.js')
    check('preview: sw.js served as JS', sw2.status === 200 && sw2.type.includes('javascript'))
    const man = await get('/manifest.webmanifest')
    check('preview: manifest served as manifest+json', man.status === 200 && man.type.includes('manifest'))
    const ic = await get('/icons/icon-192.png')
    check('preview: icon served as png', ic.status === 200 && ic.type.includes('image/png'))
  } finally {
    preview.kill()
  }

  mockServer.close()
  console.log(failures === 0 ? '\nALL TESTS PASSED' : '\nTEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
})().catch((err) => {
  console.error('TEST RUNNER ERROR', err)
  process.exit(1)
})

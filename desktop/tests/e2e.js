const { app, BrowserWindow } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-desktop-test-'))
app.setPath('userData', tmp)

const { chatWindows, sameSite, createLauncherWindow, getNotifyCount, getAppUpdater } = require('../src/main')
const updater = require('../src/updater')
const profiles = require('../src/profiles')

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

function launcher () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('launcher.html'))
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

// The profile manager closes after a successful connect, so the tests reopen it
// before the next round of renderer calls.
async function relaunchLauncher () {
  createLauncherWindow()
  return waitLauncher()
}

function js (win, code) {
  return Promise.race([
    win.webContents.executeJavaScript(code, true),
    new Promise((resolve) => setTimeout(() => resolve({ __timeout: true }), 30000))
  ])
}

function waitFor (cond, ms) {
  const timeout = ms || 20000
  const start = Date.now()
  return new Promise((resolve) => {
    const tick = () => {
      let result
      try { result = cond() } catch (err) { result = false }
      if (result) resolve(true)
      else if (Date.now() - start > timeout) resolve(false)
      else setTimeout(tick, 50)
    }
    tick()
  })
}

function mockLvchatServer () {
  const csrf = 'test-csrf-token'
  const sessions = new Set()
  const notifications = []
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1')
    const baseUrl = `http://127.0.0.1:${server.address().port}`
    const cookie = req.headers.cookie || ''
    const hasSession = /session=abc123/.test(cookie)

    if (url.pathname === '/api/version') {
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({ version: '1.0.0-test', site: 'Test Chat', updater_url: baseUrl }))
      return
    }

    if (url.pathname === '/api/updater') {
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({
        updater_url: baseUrl,
        site: 'Test Chat',
        apps: {
          web: { installed: '1.0.0', latest: '1.0.0', url: baseUrl + '/web.zip', sha256: '', update_available: false },
          desktop: { installed: '9.9.9', latest: '9.9.9', update_available: false, platforms: { win: { url: baseUrl + '/desktop.exe', version: '9.9.9' } } },
          messenger: { installed: '1.0.0', latest: '1.0.0', update_available: false, platforms: {} }
        }
      }))
      return
    }

    if (url.pathname === '/api/notifications') {
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({ ok: true, notifications }))
      return
    }

    if (url.pathname === '/login' && req.method === 'GET') {
      if (hasSession) {
        res.writeHead(302, { location: '/app?channel=general' })
        res.end()
        return
      }
      res.writeHead(200, { 'content-type': 'text/html' })
      res.end(`<html><body><form action="/login" method="post"><input type="hidden" name="csrf" value="${csrf}"><input type="hidden" name="next" value=""><input name="username"><input name="password"></form></body></html>`)
      return
    }

    if (url.pathname === '/login' && req.method === 'POST') {
      let body = ''
      req.on('data', (c) => { body += c })
      req.on('end', () => {
        const params = new URLSearchParams(body)
        if (params.get('csrf') !== csrf) {
          res.writeHead(302, { location: '/login' })
          res.end()
          return
        }
        if (params.get('username') === 'alice' && params.get('password') === 'secret') {
          sessions.add('abc123')
          res.writeHead(302, { location: params.get('next') || '/app?channel=general', 'set-cookie': 'session=abc123; Path=/' })
          res.end()
          return
        }
        res.writeHead(302, { location: '/login' })
        res.end()
      })
      return
    }

    if (url.pathname === '/app') {
      if (hasSession) {
        res.writeHead(200, { 'content-type': 'text/html' })
        res.end('<title>app</title><h1>logged in</h1><div id="notif-list"></div>')
        return
      }
      res.writeHead(302, { location: '/login?next=' + encodeURIComponent('/app?channel=general') })
      res.end()
      return
    }

    res.writeHead(200, { 'content-type': 'text/html' })
    res.end('<title>whatever</title><p>plain page</p>')
  })
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({
      server,
      addNotification: (n) => notifications.push(n)
    }))
  })
}

function plainServer () {
  const server = http.createServer((req, res) => {
    res.writeHead(200, { 'content-type': 'text/html' })
    res.end('<html><body><h1>a normal website</h1></body></html>')
  })
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve(server))
  })
}

async function main () {
  const lvchat = await mockLvchatServer()
  const plain = await plainServer()
  const base = `http://127.0.0.1:${lvchat.server.address().port}`
  const plainBase = `http://127.0.0.1:${plain.address().port}`

  let win = await waitLauncher()
  check('launcher window created', !!win)

  const info = await js(win, 'window.lvchat.listProfiles()')
  check('profiles:list exposes metadata', info && Array.isArray(info.profiles) && typeof info.storageAvailable === 'boolean', JSON.stringify(info))
  check('defaultUrl exposed', info.defaultUrl.replace(/\/$/, '') === 'https://chat.lasvegasbestinternet.com')
  const storageAvailable = info.storageAvailable
  console.log('  (keychain storage available: ' + storageAvailable + ')')

  const probeOk = await js(win, `window.lvchat.probeServer({ url: '${base}' })`)
  check('probe accepts an LVChat server', probeOk.ok && probeOk.site === 'Test Chat' && probeOk.version === '1.0.0-test', JSON.stringify(probeOk))
  check('probe surfaces the server updater feed', probeOk.updaterUrl === base, JSON.stringify(probeOk))

  const probeBad = await js(win, `window.lvchat.probeServer({ url: '${plainBase}' })`)
  check('probe rejects a plain website', probeBad.ok === false, JSON.stringify(probeBad))

  const probeFtp = await js(win, `window.lvchat.probeServer({ url: 'ftp://x.com' })`)
  check('probe rejects non-http scheme', probeFtp.ok === false, JSON.stringify(probeFtp))

  const add = await js(win, `window.lvchat.addProfile({ name: 'Test Chat', url: '${base}', username: 'alice' })`)
  check('add profile', add.ok && add.profile.url === base + '/', JSON.stringify(add))

  // Multi-account: same URL with a different username is a distinct profile;
  // exact URL + username duplicates and duplicate anonymous URLs are rejected.
  const dupe2 = await js(win, `window.lvchat.addProfile({ name: 'Dupe', url: '${base}', username: 'carol' })`)
  check('same URL + different account is allowed', dupe2.ok === true, JSON.stringify(dupe2))
  const dupe3 = await js(win, `window.lvchat.addProfile({ name: 'Dupe2', url: '${base}', username: 'carol' })`)
  check('same URL + same account is rejected', dupe3.ok === false, JSON.stringify(dupe3))
  await js(win, `window.lvchat.removeProfile({ id: '${dupe2.profile.id}' })`)
  const anon1 = await js(win, `window.lvchat.addProfile({ name: 'Anon', url: '${base}/anon' })`)
  check('anonymous profile added', anon1.ok === true, JSON.stringify(anon1))
  const anon2 = await js(win, `window.lvchat.addProfile({ name: 'Anon2', url: '${base}/anon' })`)
  check('duplicate anonymous server URL rejected', anon2.ok === false, JSON.stringify(anon2))
  await js(win, `window.lvchat.removeProfile({ id: '${anon1.profile.id}' })`)

  const upd = await js(win, `window.lvchat.updateProfile({ id: '${add.profile.id}', name: 'Test Chat 2', username: 'bob', autoConnect: true })`)
  check('update profile', upd.ok && upd.profile.name === 'Test Chat 2' && upd.profile.username === 'bob' && upd.profile.autoConnect === true, JSON.stringify(upd))

  const cred = await js(win, `window.lvchat.saveCredentials({ id: '${add.profile.id}', username: 'alice', password: 'secret' })`)
  check('save credentials accepted', cred.ok === true, JSON.stringify(cred))

  if (storageAvailable) {
    const has = await js(win, `window.lvchat.hasCredentials({ id: '${add.profile.id}' })`)
    check('saved password is retrievable', has === true, JSON.stringify(has))
  }

  const conn = await js(win, `window.lvchat.connectProfile({ id: '${add.profile.id}' })`)
  check('connect profile opens a window', conn.ok === true, JSON.stringify(conn))

  const record = [...chatWindows.values()].find((r) => r.profileId === add.profile.id)
  check('connection uses a stable per-profile partition',
    record && record.partition === 'persist:lvchat-profile-' + add.profile.id,
    record ? record.partition : 'no record')

  const connAgain = await js(win, `window.lvchat.connectProfile({ id: '${add.profile.id}' })`)
  check('reconnecting reuses the existing window', connAgain.ok && connAgain.reused === true && chatWindows.size === 1, JSON.stringify(connAgain))

  const add2 = await js(win, `window.lvchat.addProfile({ name: 'Second', url: '${base}/second' })`)
  const conn2 = await js(win, `window.lvchat.connectProfile({ id: '${add2.profile.id}' })`)
  check('second profile connects', conn2.ok === true, JSON.stringify(conn2))
  check('profiles use distinct partitions',
    [...chatWindows.values()].map((r) => r.partition).length === 2 &&
    new Set([...chatWindows.values()].map((r) => r.partition)).size === 2,
    JSON.stringify([...chatWindows.values()].map((r) => r.partition)))

  const wlist = await js(win, 'window.lvchat.listWindows()')
  check('windows:list returns running windows', Array.isArray(wlist) && wlist.length === 2, JSON.stringify(wlist))

  const close = await js(win, 'window.lvchat.closeWindow({ id: 99999 })')
  check('windows:close responds for unknown id', close.ok && !close.__timeout)
  const closeA = await js(win, `window.lvchat.closeWindow({ id: ${conn.id} })`)
  check('windows:close accepts a real window id', closeA.ok && !closeA.__timeout)
  await waitFor(() => chatWindows.size === 1)

  const rem = await js(win, `window.lvchat.removeProfile({ id: '${add.profile.id}' })`)
  check('remove profile', rem.ok && rem.removed)

  // The profile manager closes itself after a successful connect (UI flow):
  // click the Connect button for a fresh server and expect the window to close.
  const fresh = await js(win, `window.lvchat.addProfile({ name: 'Fresh', url: '${base}/fresh' })`)
  await js(win, 'window.location.reload()')
  win = await waitLauncher()
  let clicked = false
  for (let i = 0; i < 120 && !clicked; i++) {
    const r = await js(win, `(() => {
      const lis = document.querySelectorAll('#server-list li');
      for (const li of lis) {
        const nameEl = li.querySelector('.site-name');
        if (nameEl && nameEl.textContent.trim() === 'Fresh') {
          const btn = li.querySelector('button.primary');
          if (!btn) return false;
          btn.click();
          return true;
        }
      }
      return false;
    })()`)
    if (r === true) clicked = true
    else await new Promise((res) => setTimeout(res, 50))
  }
  check('connect button is clickable in the UI', clicked === true, String(clicked))
  const uiClosed = await waitFor(() => !launcher())
  check('profile manager closes after connect', uiClosed)
  const freshRec = [...chatWindows.values()].find((r) => r.profileId === fresh.profile.id)
  check('UI connect opened a chat window', !!freshRec)
  win = await relaunchLauncher()

  if (storageAvailable) {
    const autoAdd = await js(win, `window.lvchat.addProfile({ name: 'Auto', url: '${base}', username: 'alice' })`)
    const creds = await js(win, `window.lvchat.saveCredentials({ id: '${autoAdd.profile.id}', username: 'alice', password: 'secret' })`)
    check('save credentials for auto-login profile', creds.ok === true, JSON.stringify(creds))

    // A pre-existing unread notification must NOT re-alert when the window
    // starts — the bridge seeds its dedup set on the first feed poll.
    const preCount = getNotifyCount()
    lvchat.addNotification({ id: 1001, kind: 'dm', sender: 'alice', content: 'old dm' })

    const connAuto = await js(win, `window.lvchat.connectProfile({ id: '${autoAdd.profile.id}' })`)
    check('auto-login profile connects', connAuto.ok === true, JSON.stringify(connAuto))

    const autoLoggedIn = await waitFor(() => {
      const r = chatWindows.get(connAuto.id)
      if (!r || r.win.isDestroyed() || r.win.webContents.isLoading()) return false
      const url = r.win.webContents.getURL()
      return url.includes('/app') && !url.includes('/login')
    })
    check('auto-login lands on /app (not /login)', autoLoggedIn)
    const appUrl = chatWindows.get(connAuto.id).win.webContents.getURL()
    check('auto-login URL is the app page', appUrl.includes('/app'), appUrl)

    // Allow the bridge's first feed poll (immediate) to run and seed.
    await new Promise((r) => setTimeout(r, 3000))
    check('pre-existing feed notifications do not re-alert on start', getNotifyCount() === preCount, String(getNotifyCount()) + ' vs ' + preCount)

    // The native-notification bridge: the chat page's lvchat:notify event must
    // reach the main process through the preload bridge.
    const bridgePresent = await js(chatWindows.get(connAuto.id).win,
      `typeof window.lvchatNative !== 'undefined' && typeof window.lvchatNative.notify === 'function'`)
    check('chat window exposes the native notify bridge', bridgePresent === true, String(bridgePresent))
    const beforeNotify = getNotifyCount()
    await js(chatWindows.get(connAuto.id).win,
      `window.dispatchEvent(new CustomEvent('lvchat:notify', { detail: { title: 'T', body: 'B' } }))`)
    const notified = await waitFor(() => getNotifyCount() > beforeNotify)
    check('lvchat:notify event reaches the main process', notified)

    // Feed-notification polling: a NEW item in the server's /api/notifications
    // feed must be picked up by the bridge and shown as an OS notification
    // (the bridge polls it directly — the web app only loads the feed on click).
    const beforeFeed = getNotifyCount()
    lvchat.addNotification({ id: 1002, kind: 'dm', sender: 'bob', content: 'new dm' })
    const feedObserved = await waitFor(() => getNotifyCount() > beforeFeed)
    check('bridge polls the notifications API and shows alerts', feedObserved)

    // Admin dashboard links pop out into a separate window (same session).
    const chatWin = chatWindows.get(connAuto.id).win
    await js(chatWin, `window.location.href = '${base}/admin'`)
    const adminOpened = await waitFor(() => {
      const a = [...chatWindows.values()].find((r) => r.kind === 'admin' && !r.win.isDestroyed())
      return a && a.win.webContents.getURL().includes('/admin')
    })
    check('admin link pops out into its own window', adminOpened)
    const adminRec = [...chatWindows.values()].find((r) => r.kind === 'admin' && !r.win.isDestroyed())
    const chatRec = chatWindows.get(connAuto.id)
    check('admin window shares the profile session', adminRec && chatRec && adminRec.partition === chatRec.partition)
    const adminName = adminRec ? adminRec.name : ''
    check('admin window is labelled as admin', adminName.startsWith('Admin —'), adminName)
    if (adminRec) {
      await js(win, `window.lvchat.closeWindow({ id: ${adminRec.id} })`)
      await waitFor(() => ![...chatWindows.values()].some((r) => r.id === adminRec.id))
    }
  } else {
    console.log('  (skipping auto-login + admin pop-out checks: keychain unavailable)')
  }

  check('sameSite helper (subdomains)', sameSite('https://chat.lasvegasbestinternet.com', 'https://lasvegasbestinternet.com'))
  check('sameSite helper (foreign rejected)', !sameSite('https://chat.lasvegasbestinternet.com', 'https://evil.com'))

  // ── updater (pure logic, no electron-updater dependency) ──────────────────
  console.log('== updater ==')
  check('compareVersions same', updater.compareVersions('1.2.3', '1.2.3') === 0)
  check('compareVersions newer', updater.compareVersions('1.10.0', '1.9.0') > 0)
  check('compareVersions older', updater.compareVersions('0.9.9', '1.0.0') < 0)
  check('compareVersions dotted padding', updater.compareVersions('1.2.3.4', '1.2.3') > 0)
  check('compareVersions fallback strings', updater.compareVersions('beta2', 'beta1') > 0)
  check('parseFeedVersion yml', updater.parseFeedVersion('version: 2.0.1\nfiles:\n  - url: x\n') === '2.0.1')
  check('parseFeedVersion missing', updater.parseFeedVersion('nope') === '')
  check('feedFileName win', updater.feedFileName('win32') === 'latest.yml')
  check('feedFileName mac', updater.feedFileName('darwin') === 'latest-mac.yml')
  check('feedFileName linux', updater.feedFileName('linux') === 'latest-linux.yml')

  const feedYml = 'version: 0.2.0\nfiles:\n  - url: https://x/LVChat.AppImage\n    sha512: null\n    size: 1\n'
  const avail = await updater.isUpdateAvailable('https://updates.example.com/desktop', '0.1.1', 'linux', async (u) => feedYml)
  check('isUpdateAvailable true on newer feed', avail === true)
  const noAvail = await updater.isUpdateAvailable('https://updates.example.com/desktop', '0.3.0', 'linux', async (u) => feedYml)
  check('isUpdateAvailable false on older/equal feed', noAvail === false)

  check('resolveFeedUrl defaults to upstream', updater.resolveFeedUrl([]) === updater.PACKAGE_FEED_URL)
  const optIn = updater.resolveFeedUrl([
    { id: 'a', serverUpdaterUrl: 'https://feeds.example.com/x', useServerUpdates: false, lastConnectedAt: '2026-01-01' },
    { id: 'b', serverUpdaterUrl: 'https://feeds.example.com/y', useServerUpdates: true, lastConnectedAt: '2026-01-02' }
  ])
  check('resolveFeedUrl honours a profile opt-in', optIn === 'https://feeds.example.com/y/desktop', optIn)

  // The server-recommended links via /api/updater (main-process fetch).
  const srv = await profiles.getServerUpdater(profiles.find(fresh.profile.id))
  check('getServerUpdater resolves server feed links', srv.ok && (srv.apps.desktop.platforms.win.url || '').includes('/desktop.exe'), JSON.stringify(srv))

  // The launcher footer exposes the update controls.
  const footer = await js(win, `(() => {
    const t = document.getElementById('update-status-text');
    const btn = document.getElementById('update-check');
    return { hasStatus: !!t, hasBtn: !!btn, statusText: t ? t.textContent : '' };
  })()`)
  check('launcher has update status row', footer.hasStatus && footer.hasBtn)

  // The updater singleton exists and answers status without hanging.
  const updState = await js(win, 'window.lvchat.updatesStatus()')
  check('updates:status IPC responds', updState && typeof updState.state === 'string', JSON.stringify(updState))
  const updFeed = await js(win, 'window.lvchat.updatesFeed()')
  check('updates:feed IPC responds', updFeed && typeof updFeed.url === 'string' && typeof updFeed.currentVersion === 'string', JSON.stringify(updFeed))

  lvchat.server.close()
  plain.close()

  console.log(failures === 0 ? '\nALL TESTS PASSED' : `\n${failures} TEST(S) FAILED`)
  app.exit(failures === 0 ? 0 : 1)
}

app.whenReady().then(main)

setTimeout(() => {
  console.log(`\nTIMEOUT with ${failures} failure(s) — aborting`)
  app.exit(2)
}, 60000)

const { app, BrowserWindow, ipcMain, Menu, Tray, shell, session, nativeImage, Notification } = require('electron')
const path = require('path')
const profiles = require('./profiles')

app.setName('LVChat Desktop')

let launcherWindow = null
let tray = null
let isQuitting = false
let desktopNotifications = 0
const chatWindows = new Map()

const APP_ICON = path.join(__dirname, '..', 'build', 'icon.png')
const SPLASH = path.join(__dirname, 'renderer', 'splash.html')

// Web-app UI that makes no sense inside the desktop client: the PWA install
// buttons and the Web-Push enable row (Electron can't subscribe to Web Push —
// the desktop app shows native notifications through the bridge instead).
const HIDE_WEB_UI_CSS = `
  #install-btn, #install-btn-m { display: none !important; }
  #push-row { display: none !important; }
`

// Injected into the chat page's main world. Delivers OS notifications by
// watching the server directly, so it works on any server version and in any
// realtime mode:
//  1. The updated web app dispatches `lvchat:notify` for background-channel
//     messages (the one thing the notifications feed never lists).
//  2. The bridge polls GET /api/notifications itself — the web app only renders
//     that feed when the bell is clicked, so we watch the source for real-time
//     DM / mention / invite / friend alerts. New items are deduped by id and
//     gated by the user's per-context push prefs (Profile → Push notifications).
//  3. On older servers (no `data-push-prefs`), the bridge also watches
//     /api/poll responses for background channel messages (polling realtime).
// Notifications are always shown by the main process — the page's own
// Notification API can't be relied on (its permission often reads as denied).
const NOTIFY_BRIDGE = `
  (function () {
    if (window.__lvchatNotifyBridge) return;
    window.__lvchatNotifyBridge = true;

    var prefs = (function () {
      try {
        var p = JSON.parse(document.body.dataset.pushPrefs || '{}');
        return { channels: p.channels === 0 ? 0 : 1, dms: p.dms === 0 ? 0 : 1, invites: p.invites === 0 ? 0 : 1 };
      } catch (e) { return { channels: 1, dms: 1, invites: 1 }; }
    })();
    var serverHasBridge = !!(document.body.dataset.pushPrefs);

    function strip (s) {
      return String(s || '').replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/\\s+/g, ' ').trim();
    }
    function push (title, body) {
      if (window.lvchatNative && window.lvchatNative.notify) {
        window.lvchatNative.notify({ title: title, body: body });
        return;
      }
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          var n = new Notification(String(title || 'LVChat'), { body: String(body || ''), silent: true });
          n.onclick = function () { try { window.focus(); } catch (err) {} n.close(); };
          setTimeout(function () { try { n.close(); } catch (err) {} }, 10000);
        } catch (err) {}
      }
    }

    // 1. Events from the updated web app (background channel messages).
    window.addEventListener('lvchat:notify', function (e) {
      var d = e.detail || {};
      push(String(d.title || 'LVChat'), String(d.body || ''));
    });

    // 2. Feed notifications — poll the source directly so alerts are real time.
    //    The first poll seeds the dedup set silently (pre-existing unread items
    //    must not re-alert on every app start / page load); only items that
    //    appear afterwards produce OS alerts.
    var seen = {};
    var seeded = false;
    function handleFeed (list) {
      if (!Array.isArray(list)) return;
      for (var i = 0; i < list.length; i++) {
        var n = list[i];
        if (!n || !n.id || seen[n.id]) continue;
        seen[n.id] = 1;
        if (!seeded) continue;
        var kind = String(n.kind || '');
        var title = 'LVChat';
        var body = '';
        if (kind === 'dm') {
          if (prefs.dms !== 1) continue;
          title = 'DM from ' + (n.sender || 'someone');
          body = n.content ? strip(n.content) : 'New direct message';
        } else if (kind === 'mention') {
          if (prefs.channels !== 1) continue;
          title = 'Mentioned you';
          body = (n.sender ? '@' + n.sender : 'Someone') + (n.channel_name ? ' in ' + n.channel_name : '');
        } else if (kind === 'invite') {
          if (prefs.invites !== 1) continue;
          title = 'Channel invite';
          body = 'You were invited to ' + (n.channel_name || 'a channel') + (n.sender ? ' by ' + n.sender : '');
        } else if (kind === 'friend_request') {
          title = 'Friend request';
          body = (n.sender || 'Someone') + ' sent you a friend request';
        } else if (kind === 'friend_accepted') {
          title = 'Friend request accepted';
          body = (n.sender || 'Someone') + ' is now your friend';
        } else {
          title = kind.charAt(0).toUpperCase() + kind.slice(1);
          body = (n.sender ? n.sender + ': ' : '') + (n.channel_name ? n.channel_name : '');
        }
        if (!body) body = 'You have a new notification';
        push(title, body);
      }
    }
    function loadFeed () {
      fetch('/api/notifications', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && Array.isArray(j.notifications)) handleFeed(j.notifications);
          seeded = true;
        })
        .catch(function () {});
    }
    loadFeed();
    setInterval(loadFeed, 5000);

    // 3. Older servers + polling realtime: watch /api/poll for background
    //    channel messages (the feed never lists plain channel messages). Same
    //    first-poll seeding so existing unread background messages stay silent.
    if (!serverHasBridge && 'fetch' in window) {
      var bgSeen = {};
      var bgSeeded = false;
      var origFetch = window.fetch.bind(window);
      window.fetch = function (input, init) {
        return origFetch(input, init).then(function (res) {
          try {
            var url = (typeof input === 'string' ? input : (input && input.url) || '') || '';
            if (url.indexOf('/api/poll') !== -1) {
              res.clone().json().then(function (j) {
                if (j && Array.isArray(j.bg_messages)) {
                  for (var i = 0; i < j.bg_messages.length; i++) {
                    var m = j.bg_messages[i];
                    if (!m || !m.id || bgSeen[m.id]) continue;
                    bgSeen[m.id] = 1;
                    if (!bgSeeded) continue;
                    if (prefs.channels !== 1) continue;
                    push(m.channel_slug ? '#' + m.channel_slug : 'New message', (m.username ? m.username + ': ' : '') + strip(m.content || ''));
                  }
                  bgSeeded = true;
                }
              }).catch(function () {});
            }
          } catch (e) {}
          return res;
        });
      };
    }
  })();
`

function sameSite (a, b) {
  try {
    const hostA = new URL(a).hostname.toLowerCase()
    const hostB = new URL(b).hostname.toLowerCase()
    if (hostA === hostB) return true
    return hostB.endsWith('.' + hostA) || hostA.endsWith('.' + hostB)
  } catch (err) {
    return false
  }
}

function pathnameOf (url) {
  try {
    return url ? new URL(url).pathname : ''
  } catch (err) {
    return ''
  }
}

function createLauncherWindow () {
  if (launcherWindow && !launcherWindow.isDestroyed()) {
    if (launcherWindow.isMinimized()) launcherWindow.restore()
    launcherWindow.focus()
    return launcherWindow
  }
  launcherWindow = new BrowserWindow({
    width: 720,
    height: 800,
    minWidth: 540,
    minHeight: 580,
    title: 'Profile Manager',
    icon: APP_ICON,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  })
  launcherWindow.loadFile(path.join(__dirname, 'renderer', 'launcher.html'))
  launcherWindow.once('ready-to-show', () => launcherWindow.show())
  launcherWindow.on('closed', () => {
    launcherWindow = null
    if (!isQuitting && chatWindows.size === 0) app.quit()
  })
  return launcherWindow
}

function closeLauncherWindow () {
  if (launcherWindow && !launcherWindow.isDestroyed()) launcherWindow.close()
}

function allowPermissions (ses) {
  ses.setPermissionRequestHandler((wc, permission, callback) => {
    const allowed = ['notifications', 'fullscreen', 'media', 'clipboard-sanitized-write']
    callback(allowed.includes(permission))
  })
}

// Log the user in from the main process using the profile session's cookie
// store, so the window never has to render the web login page. The window is
// parked on a "Logging in…" splash while this runs.
// Renders the login form post in the shared profile session so the session
// cookie is stored exactly as a real browser would do it.
function autoLoginScript (username, password, next) {
  return `
    (async () => {
      try {
        const page = await fetch(window.location.origin + '/login', { credentials: 'include' })
        const html = await page.text()
        const doc = new DOMParser().parseFromString(html, 'text/html')
        const csrfInput = doc.querySelector('input[name="csrf"]')
        if (!csrfInput) return { ok: false, reason: 'no-csrf' }
        const form = doc.querySelector('form[action="/login"]')
        const nextField = form ? form.querySelector('input[name="next"]') : null
        const nextValue = nextField && nextField.value ? nextField.value : ${JSON.stringify(next)}
        const res = await fetch('/login', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf: csrfInput.value,
            username: ${JSON.stringify(username)},
            password: ${JSON.stringify(password)},
            next: nextValue
          })
        })
        return { ok: true, status: res.status, url: res.url, redirected: res.redirected }
      } catch (err) {
        return { ok: false, reason: 'error', message: String(err && err.message) }
      }
    })()
  `
}

// Log the user in without ever showing the web login page: the visible window
// stays parked on the "Logging in…" splash while a hidden window sharing the
// profile's partition performs the real session login (cookies flow into the
// same store the chat window reads).
async function runAutoLogin (win, record, profile, creds) {
  const base = profile.url
  const appUrl = new URL('/app', base).toString()
  let settled = false
  const done = (url) => {
    if (settled) return
    settled = true
    if (record.helper && !record.helper.isDestroyed()) record.helper.destroy()
    if (!win.isDestroyed()) win.loadURL(url || appUrl)
  }

  const helper = new BrowserWindow({
    show: false,
    webPreferences: {
      partition: record.partition,
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  })
  record.helper = helper

  helper.webContents.once('dom-ready', async () => {
    try {
      const pageUrl = helper.webContents.getURL()
      if (!sameSite(pageUrl, base) || pathnameOf(pageUrl) !== '/login') {
        done(appUrl)
        return
      }
      const nextParam = new URL(pageUrl).searchParams.get('next') || '/app?channel=general'
      const result = await helper.webContents.executeJavaScript(autoLoginScript(creds.username, creds.password, nextParam), true)
      // TOTP gate: hand the visible window to the real MFA page so the user can
      // enter their code (the session is parked in a pre-auth MFA state).
      if (result.ok && result.url && result.url.includes('/login/mfa')) {
        done(new URL('/login/mfa', base).toString())
        return
      }
      if (result.ok && result.url && !result.url.includes('/login')) {
        done(result.url)
        return
      }
      done(appUrl)
    } catch (err) {
      console.warn('auto-login failed:', err.message)
      done(appUrl)
    }
  })
  helper.webContents.once('did-fail-load', (_e, code, desc) => {
    console.warn('auto-login helper failed to load:', code, desc)
    done(appUrl)
  })

  helper.loadURL(appUrl)

  // Safety net: never leave the window stranded on the splash.
  setTimeout(() => {
    if (!settled && !win.isDestroyed() && pathnameOf(win.webContents.getURL()).includes('splash')) {
      done(appUrl)
    }
  }, 20000)
}

function wireChatWindow (win, record, profile) {
  const onReady = () => {
    if (pathnameOf(win.webContents.getURL()).startsWith('/app')) {
      win.webContents.insertCSS(HIDE_WEB_UI_CSS).catch(() => {})
      win.webContents.executeJavaScript(NOTIFY_BRIDGE, true).catch(() => {})
    }
  }
  win.webContents.on('dom-ready', onReady)
  win.webContents.on('did-navigate', onReady)
}

function connectProfile (profile) {
  if (!profile) return { ok: false, error: 'Profile not found' }

  const normalized = profiles.normalizeUrl(profile.url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }

  const partition = profiles.partitionFor(profile.id)
  allowPermissions(session.fromPartition(partition))

  const existing = [...chatWindows.values()].find((r) => r.kind === 'chat' && r.profileId === profile.id)
  if (existing && !existing.win.isDestroyed()) {
    if (existing.win.isMinimized()) existing.win.restore()
    existing.win.focus()
    return { ok: true, id: existing.id, reused: true }
  }

  const win = new BrowserWindow({
    width: 1280,
    height: 820,
    title: profile.name || normalized,
    icon: APP_ICON,
    webPreferences: {
      partition,
      preload: path.join(__dirname, 'preload-notify.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      backgroundThrottling: false
    }
  })

  const webContentsId = win.webContents.id
  const drop = () => { chatWindows.delete(webContentsId) }
  win.on('closed', drop)
  win.on('destroyed', drop)

  const record = { id: webContentsId, win, kind: 'chat', profileId: profile.id, url: normalized, name: profile.name || normalized, partition }
  chatWindows.set(webContentsId, record)
  profiles.touch(profile.id)
  wireChatWindow(win, record, profile)

  const creds = profiles.getCredentials(profile.id)
  if (creds && creds.password) {
    win.loadFile(SPLASH)
    runAutoLogin(win, record, profile, creds)
  } else {
    win.loadURL(new URL('/app', normalized).toString())
  }
  return { ok: true, id: webContentsId, reused: false }
}

// The admin dashboard is a different tool than the chat — open it in its own
// window (sharing the profile's session) instead of navigating the chat away.
function openAdminWindow (opener, url) {
  const profile = profiles.find(opener.profileId)
  const partition = opener.partition
  allowPermissions(session.fromPartition(partition))

  const name = 'Admin — ' + (profile ? profile.name : opener.name)
  const win = new BrowserWindow({
    width: 1400,
    height: 900,
    title: name,
    icon: APP_ICON,
    webPreferences: {
      partition,
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  })

  const webContentsId = win.webContents.id
  const drop = () => { chatWindows.delete(webContentsId) }
  win.on('closed', drop)
  win.on('destroyed', drop)

  const record = { id: webContentsId, win, kind: 'admin', profileId: opener.profileId, url: new URL(url).origin, name, partition }
  chatWindows.set(webContentsId, record)
  win.loadURL(url)
  return win
}

function disconnectProfile (profileId) {
  const records = [...chatWindows.values()].filter((r) => r.profileId === profileId)
  for (const r of records) {
    if (!r.win.isDestroyed()) r.win.destroy()
  }
  return { ok: true, closed: records.length }
}

function buildMenu () {
  const template = [
    ...(process.platform === 'darwin' ? [{ role: 'appMenu' }] : []),
    {
      label: 'File',
      submenu: [
        { label: 'Profile Manager', accelerator: 'CmdOrCtrl+M', click: () => createLauncherWindow() },
        { type: 'separator' },
        process.platform === 'darwin' ? { role: 'close' } : { role: 'quit' }
      ]
    },
    {
      label: 'Servers',
      submenu: profileSubmenu()
    },
    { role: 'editMenu' },
    { role: 'viewMenu' },
    { role: 'windowMenu' }
  ]
  Menu.setApplicationMenu(Menu.buildFromTemplate(template))
}

function profileSubmenu () {
  const items = profiles.list().map((p) => ({
    label: p.name,
    submenu: [
      {
        label: 'Open / Connect',
        click: () => connectProfile(p)
      },
      {
        label: 'Edit',
        click: () => createLauncherWindow()
      }
    ]
  }))
  if (items.length === 0) items.push({ label: 'No servers added yet', enabled: false })
  items.push({ type: 'separator' })
  items.push({ label: 'Add server…', click: () => createLauncherWindow() })
  return items
}

function buildTray () {
  let image
  try {
    image = nativeImage.createFromPath(APP_ICON).resize({ width: 24, height: 24 })
  } catch (err) {
    image = nativeImage.createEmpty()
  }
  tray = new Tray(image)
  tray.setToolTip('LVChat Desktop')
  tray.on('click', () => createLauncherWindow())
  rebuildTrayMenu()
}

function rebuildTrayMenu () {
  if (!tray) return
  const items = []
  for (const p of profiles.list()) {
    const record = [...chatWindows.values()].find((r) => r.profileId === p.id && !r.win.isDestroyed())
    items.push({
      label: p.name,
      submenu: [
        ...(record
          ? [{ label: 'Focus window', click: () => {
            if (record.win.isMinimized()) record.win.restore()
            record.win.focus()
          } }]
          : []),
        { label: record ? 'Open another window' : 'Connect', click: () => { connectProfile(p); closeLauncherWindow() } },
        ...(record ? [{ label: 'Disconnect', click: () => disconnectProfile(p.id) }] : [])
      ]
    })
  }
  items.push({ type: 'separator' })
  items.push({ label: 'Profile Manager', click: () => createLauncherWindow() })
  items.push({ type: 'separator' })
  items.push({ label: 'Quit LVChat Desktop', click: () => { isQuitting = true; app.quit() } })
  tray.setContextMenu(Menu.buildFromTemplate(items))
}

function registerIpc () {
  ipcMain.handle('profiles:list', () => ({
    profiles: profiles.list(),
    defaultUrl: profiles.getDefaultUrl(),
    version: app.getVersion(),
    storageAvailable: profiles.storageAvailable()
  }))

  ipcMain.handle('profiles:probe', (_e, { url }) => profiles.probeServer(url))

  ipcMain.handle('profiles:add', (_e, payload) => profiles.add(payload || {}))
  ipcMain.handle('profiles:update', (_e, payload) => profiles.update(payload?.id, payload || {}))
  ipcMain.handle('profiles:remove', (_e, { id }) => profiles.remove(id))
  ipcMain.handle('profiles:connect', (_e, { id }) => connectProfile(profiles.find(id)))
  ipcMain.handle('profiles:disconnect', (_e, { id }) => disconnectProfile(id))

  ipcMain.handle('credentials:save', (_e, payload) => profiles.setCredentials(payload?.id, payload?.username, payload?.password))
  ipcMain.handle('credentials:has', (_e, { id }) => !!profiles.getCredentials(id)?.hasPassword)

  ipcMain.handle('windows:list', () =>
    [...chatWindows.values()]
      .filter((r) => !r.win.isDestroyed())
      .map((r) => ({ id: r.id, kind: r.kind, profileId: r.profileId, url: r.url, name: r.name }))
  )
  ipcMain.handle('windows:focus', (_e, { id }) => {
    const r = chatWindows.get(Number(id))
    if (r && !r.win.isDestroyed()) {
      if (r.win.isMinimized()) r.win.restore()
      r.win.focus()
      return { ok: true }
    }
    return { ok: false }
  })
  ipcMain.handle('windows:close', (_e, { id }) => {
    const r = chatWindows.get(Number(id))
    if (r && !r.win.isDestroyed()) setTimeout(() => {
      if (!r.win.isDestroyed()) r.win.destroy()
    }, 50)
    return { ok: true }
  })
  ipcMain.handle('launcher:show', () => { createLauncherWindow(); return { ok: true } })

  // The renderer closes the profile manager itself after a successful connect;
  // a short delay lets the invoke reply flush to the (about-to-close) window.
  ipcMain.handle('launcher:close', () => {
    setTimeout(closeLauncherWindow, 30)
    return { ok: true }
  })

  // Lets the user verify the OS notification pipeline independent of any server.
  ipcMain.handle('notify:test', () => {
    const notification = new Notification({
      title: 'LVChat Desktop',
      body: 'Test notification — desktop alerts are working.',
      icon: APP_ICON
    })
    notification.on('click', () => { createLauncherWindow() })
    notification.show()
    return { ok: true }
  })

  // Diagnostic: how many OS notifications the main process has shown.
  ipcMain.handle('notify:stats', () => ({ count: desktopNotifications }))

  // Native OS notifications requested by the chat page's notify bridge. Showing
  // them from the main process works on every platform regardless of the
  // page's Notification permission. Clicking focuses the originating window.
  ipcMain.on('desktop:notify', (event, payload) => {
    const record = chatWindows.get(event.sender.id)
    desktopNotifications++
    const notification = new Notification({
      title: String((payload && payload.title) || 'LVChat'),
      body: String((payload && payload.body) || ''),
      icon: APP_ICON
    })
    notification.on('click', () => {
      if (record && record.win && !record.win.isDestroyed()) {
        if (record.win.isMinimized()) record.win.restore()
        record.win.show()
        record.win.focus()
      }
    })
    notification.show()
  })

  ipcMain.on('app:refresh-menus', () => {
    buildMenu()
    rebuildTrayMenu()
  })
}

app.on('web-contents-created', (_e, contents) => {
  contents.setWindowOpenHandler(({ url: target }) => {
    const opener = chatWindows.get(contents.id)
    if (opener && opener.kind === 'chat' && /^https?:/i.test(target) &&
        sameSite(target, opener.url) && pathnameOf(target).startsWith('/admin')) {
      openAdminWindow(opener, target)
      return { action: 'deny' }
    }
    if (/^https?:/i.test(target)) shell.openExternal(target)
    return { action: 'deny' }
  })

  contents.on('will-navigate', (event, target) => {
    if (target === 'about:blank') return
    if (launcherWindow && contents.id === launcherWindow.webContents.id) {
      if (!target.startsWith('file:')) event.preventDefault()
      return
    }
    if (!/^https?:/i.test(target)) {
      event.preventDefault()
      shell.openExternal(target)
      return
    }
    const opener = chatWindows.get(contents.id)
    const allowedOrigin = opener ? new URL(opener.url).origin : null
    // Admin links in the chat pop out into their own window instead of
    // navigating the chat away. Already in an admin window? Navigate in place.
    if (opener && opener.kind === 'chat' && allowedOrigin &&
        sameSite(target, allowedOrigin) && pathnameOf(target).startsWith('/admin')) {
      event.preventDefault()
      openAdminWindow(opener, target)
      return
    }
    if (allowedOrigin && !sameSite(target, allowedOrigin)) {
      event.preventDefault()
      shell.openExternal(target)
    }
  })
})

app.whenReady().then(() => {
  registerIpc()
  buildMenu()
  createLauncherWindow()
  buildTray()

  for (const p of profiles.list()) {
    if (p.autoConnect) {
      connectProfile(p)
      closeLauncherWindow()
    }
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createLauncherWindow()
  })
})

app.on('window-all-closed', () => {
  // Keep running in the tray; the app only quits via the tray/menu Quit,
  // or when the launcher is closed with no chat windows open.
})

app.on('before-quit', () => { isQuitting = true })

module.exports = { createLauncherWindow, connectProfile, disconnectProfile, chatWindows, registerIpc, sameSite, getNotifyCount: () => desktopNotifications }

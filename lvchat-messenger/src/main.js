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

const { app, BrowserWindow, ipcMain, Menu, Tray, nativeImage, Notification, shell, session, clipboard } = require('electron')
const path = require('path')
const fs = require('fs')
const profiles = require('./profiles')
const updater = require('./updater')
const { createStaticServer } = require('./server')

app.setName('LVChat Messenger')
// Required for Windows toast notifications to appear.
app.setAppUserModelId('com.lasvegasbestinternet.lvchatmessenger')

let launcherWindow = null
let tray = null
let isQuitting = false
let messengerNotifications = 0
let staticServer = null
let appOrigin = 'http://127.0.0.1'
const messengerWindows = new Map()

let appUpdater = null

const APP_ICON = path.join(__dirname, '..', 'build', 'icon.png')

// Small brand mark baked in as a data URL so the tray icon is never blank even
// when build/icon.png isn't available (e.g. a packaged build where the asset
// lives in buildResources and is not shipped in the app asar).
const TRAY_FALLBACK = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAIAAABvFaqvAAAATElEQVR4nGNQ07ChCmIYNWjUICwoIvVTROonOBtTkCiDIBogenCx6WsQsi8wDRrqsTaIDXrtOg+CKDIIbgpBs4acQVQLI2rGGr0NAgABE3N6IZJN6AAAAABJRU5ErkJggg=='

function trayImage () {
  try {
    const img = nativeImage.createFromPath(APP_ICON).resize({ width: 24, height: 24 })
    if (!img.isEmpty()) return img
  } catch (err) { /* fall through */ }
  try {
    const fallback = nativeImage.createFromDataURL(TRAY_FALLBACK)
    if (!fallback.isEmpty()) return fallback
  } catch (err) { /* fall through */ }
  return nativeImage.createEmpty()
}

/* Show an OS notification via the main process. Windows toasts need the app's
 * AppUserModelID to be registered (set at startup) and use the AUMID-registered
 * app icon — a custom image can make the toast fail there, so it's skipped on
 * Windows. failure to create/show is surfaced through the 'failed' event and
 * logged, never thrown. $done (optional) reports the outcome for the test path. */
function showOsNotification (record, title, body, conv, done) {
  const report = (state) => {
    if (typeof done === 'function') {
      try { done(state) } catch (err) { /* ignore */ }
    }
  }
  try {
    if (!Notification.isSupported()) {
      console.warn('[notify] OS notifications unsupported on this system')
      report('unsupported')
      return false
    }
    let icon
    if (process.platform !== 'win32') {
      try {
        const img = nativeImage.createFromPath(APP_ICON)
        icon = img.isEmpty() ? undefined : img.resize({ width: 64, height: 64 })
      } catch (err) { icon = undefined }
    }
    const options = { title: String(title || 'LVChat Messenger'), body: String(body || '') }
    if (icon) options.icon = icon
    const notification = new Notification(options)
    notification.on('click', () => {
      if (record && record.win && !record.win.isDestroyed()) {
        if (record.win.isMinimized()) record.win.restore()
        record.win.show()
        record.win.focus()
      }
      if (record && conv && !record.win.isDestroyed() && !record.win.webContents.isDestroyed()) {
        record.win.webContents.send('msg:open-conv', conv)
      }
    })
    notification.on('show', () => {
      console.log('[notify] shown:', title)
      report('shown')
    })
    notification.on('failed', (_e, err) => {
      console.warn('[notify] failed:', err || 'unknown error')
      report('failed: ' + ((err && err.message) || err || 'unknown error'))
    })
    notification.show()
    return true
  } catch (err) {
    // A broken notification daemon must never take the app down.
    console.warn('[notify] error:', err.message)
    report('error: ' + err.message)
    return false
  }
}

/* On Windows, toast notifications are only registered once the app has a Start
 * Menu shortcut carrying its AppUserModelID. Installing the Setup exe creates
 * one; when running an unpacked/portable build we create it at startup so the
 * app still shows up in Windows notification settings and toasts can fire. */
function ensureWindowsNotificationShortcut () {
  if (process.platform !== 'win32') return
  try {
    const exe = process.execPath
    const startMenu = path.join(app.getPath('appData'), 'Microsoft', 'Windows', 'Start Menu', 'Programs')
    const lnk = path.join(startMenu, 'LVChat Messenger.lnk')
    if (fs.existsSync(lnk)) return
    fs.mkdirSync(startMenu, { recursive: true })
    const esc1 = (s) => String(s).replace(/'/g, "''")
    const ps = [
      '$ws = New-Object -ComObject WScript.Shell',
      "$s = $ws.CreateShortcut('" + esc1(lnk) + "')",
      "$s.TargetPath = '" + esc1(exe) + "'",
      "$s.WorkingDirectory = '" + esc1(path.dirname(exe)) + "'",
      "$s.IconLocation = '" + esc1(exe) + ",0'",
      '$s.Save()'
    ].join('; ')
    require('child_process').execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', ps], { timeout: 20000, stdio: 'ignore' })
  } catch (err) {
    console.warn('[notify] could not create the Windows start-menu shortcut:', err.message)
  }
}

function prefsPath () {
  return path.join(app.getPath('userData'), 'prefs.json')
}

function loadPrefs () {
  try {
    return JSON.parse(fs.readFileSync(prefsPath(), 'utf8'))
  } catch (err) {
    return {}
  }
}

function savePrefs (prefs) {
  try {
    fs.mkdirSync(path.dirname(prefsPath()), { recursive: true })
    fs.writeFileSync(prefsPath(), JSON.stringify(prefs, null, 2), 'utf8')
  } catch (err) {
    console.warn('prefs write failed:', err.message)
  }
}

function debounce (fn, ms) {
  let t
  return (...args) => {
    clearTimeout(t)
    t = setTimeout(() => fn(...args), ms)
  }
}

/* Last window size/location, so the user's layout survives restarts. */
function savedBounds () {
  const b = loadPrefs().windowBounds
  if (b && Number.isFinite(b.width) && Number.isFinite(b.height) && b.width > 0 && b.height > 0) return b
  return null
}

/* Remember the window's bounds (debounced) when the user moves/resizes it. */
function rememberBounds (win) {
  const save = () => {
    if (win.isDestroyed() || win.isMaximized() || win.isFullScreen()) return
    const prefs = loadPrefs()
    prefs.windowBounds = win.getBounds()
    savePrefs(prefs)
  }
  win.on('resize', debounce(save, 400))
  win.on('move', debounce(save, 400))
}

function allowPermissions (ses) {
  ses.setPermissionRequestHandler((wc, permission, callback) => {
    const allowed = ['notifications', 'fullscreen', 'media', 'clipboard-sanitized-write']
    callback(allowed.includes(permission))
  })
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
  launcherWindow.loadURL(appOrigin + '/launcher.html')
  launcherWindow.once('ready-to-show', () => launcherWindow.show())
  launcherWindow.on('closed', () => {
    launcherWindow = null
    if (!isQuitting && messengerWindows.size === 0) app.quit()
  })
  return launcherWindow
}

function connectProfile (profile) {
  if (!profile) return { ok: false, error: 'Profile not found' }

  const normalized = profiles.normalizeUrl(profile.url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }

  const partition = profiles.partitionFor(profile.id)
  allowPermissions(session.fromPartition(partition))

  const existing = [...messengerWindows.values()].find((r) => r.profileId === profile.id && r.kind === 'main')
  if (existing && !existing.win.isDestroyed()) {
    if (existing.win.isMinimized()) existing.win.restore()
    existing.win.show()
    existing.win.focus()
    return { ok: true, id: existing.id, reused: true }
  }

  const win = new BrowserWindow({
    width: (savedBounds() || {}).width || 1120,
    height: (savedBounds() || {}).height || 760,
    minWidth: 260,
    minHeight: 420,
    title: profile.name || normalized,
    icon: APP_ICON,
    show: false,
    backgroundColor: '#1a1a24',
    webPreferences: {
      partition,
      preload: path.join(__dirname, 'preload-messenger.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      backgroundThrottling: false
    }
  })

  const webContentsId = win.webContents.id
  const drop = () => { messengerWindows.delete(webContentsId) }
  win.on('closed', drop)
  win.on('destroyed', drop)
  // Closing with the X hides to the tray instead of quitting; a real quit (or
  // disconnect/logout) bypasses this via destroy() / isQuitting.
  win.on('close', (e) => {
    if (!isQuitting) {
      e.preventDefault()
      win.hide()
    }
  })
  rememberBounds(win)

  const record = { id: webContentsId, win, profileId: profile.id, url: normalized, name: profile.name || normalized, partition, kind: 'main' }
  messengerWindows.set(webContentsId, record)
  profiles.touch(profile.id)

  win.loadURL(appOrigin + '/messenger.html?profile=' + encodeURIComponent(profile.id))
  win.once('ready-to-show', () => win.show())
  rebuildTrayMenu()
  return { ok: true, id: webContentsId, reused: false }
}

/* Open (or focus) a dedicated conversation window for a profile. Each chat gets
 * its own window, deduped per profile + conversation. */
function openChatWindow (profile, type, id) {
  const convKey = (type === 'room' ? 'room:' : 'dm:') + String(id).toLowerCase()

  const existing = [...messengerWindows.values()].find((r) => r.profileId === profile.id && r.kind === 'chat' && r.convKey === convKey)
  if (existing && !existing.win.isDestroyed()) {
    if (existing.win.isMinimized()) existing.win.restore()
    existing.win.show()
    existing.win.focus()
    return { ok: true, id: existing.id, reused: true }
  }

  const partition = profiles.partitionFor(profile.id)
  allowPermissions(session.fromPartition(partition))
  const title = type === 'room' ? '#' + id : id

  const win = new BrowserWindow({
    width: 780,
    height: 640,
    minWidth: 520,
    minHeight: 400,
    title,
    icon: APP_ICON,
    show: false,
    backgroundColor: '#1a1a24',
    webPreferences: {
      partition,
      preload: path.join(__dirname, 'preload-messenger.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      backgroundThrottling: false
    }
  })

  const webContentsId = win.webContents.id
  const drop = () => { messengerWindows.delete(webContentsId) }
  win.on('closed', drop)
  win.on('destroyed', drop)
  // Same close-to-tray behavior as the main messenger window.
  win.on('close', (e) => {
    if (!isQuitting) {
      e.preventDefault()
      win.hide()
    }
  })

  const record = { id: webContentsId, win, profileId: profile.id, url: profile.url, name: title, partition, kind: 'chat', convType: type, convId: id, convKey }
  messengerWindows.set(webContentsId, record)

  win.loadURL(appOrigin + '/messenger.html?profile=' + encodeURIComponent(profile.id) + '&chat=' + encodeURIComponent(type + ':' + id))
  win.once('ready-to-show', () => win.show())
  return { ok: true, id: webContentsId, reused: false }
}

function disconnectProfile (profileId) {
  const records = [...messengerWindows.values()].filter((r) => r.profileId === profileId)
  for (const r of records) {
    if (!r.win.isDestroyed()) r.win.destroy()
  }
  rebuildTrayMenu()
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
    { role: 'windowMenu' },
    {
      label: 'Help',
      submenu: [
        {
          label: 'Check for Updates…',
          click: () => { checkForUpdates({ manual: true }) }
        }
      ]
    }
  ]
  Menu.setApplicationMenu(Menu.buildFromTemplate(template))
}

function profileSubmenu () {
  const items = profiles.list().map((p) => ({
    label: p.name,
    submenu: [
      { label: 'Open Messenger', click: () => connectProfile(p) },
      { label: 'Edit', click: () => createLauncherWindow() }
    ]
  }))
  if (items.length === 0) items.push({ label: 'No servers added yet', enabled: false })
  items.push({ type: 'separator' })
  items.push({ label: 'Add server…', click: () => createLauncherWindow() })
  return items
}

/* Show the first messenger window (restoring it if hidden in the tray), or the
 * Profile Manager when nothing is open. Used by the tray click + dock activate. */
function showMessengerOrLauncher () {
  const record = [...messengerWindows.values()].find((r) => r.kind === 'main' && !r.win.isDestroyed())
  if (record) {
    if (record.win.isMinimized()) record.win.restore()
    record.win.show()
    record.win.focus()
    return
  }
  createLauncherWindow()
}

/* System-tray presence. Closing a messenger window with the X hides it here;
 * the tray carries the same context menu as LVChat Desktop (per-profile
 * actions, Profile Manager, Quit), attached exactly the way Desktop does it —
 * setContextMenu() with no extra event listeners, which is what keeps the
 * tray icon rendering reliably on Linux. */
function buildTray () {
  try {
    tray = new Tray(trayImage())
    tray.setToolTip('LVChat Messenger')
    tray.on('click', showMessengerOrLauncher)
    rebuildTrayMenu()
  } catch (err) {
    console.warn('tray setup failed:', err.message)
  }
}

function rebuildTrayMenu () {
  if (!tray) return
  const items = []
  // "Open Messenger…" first: bring the app back up (or the Profile Manager if
  // nothing is open) without hunting through the per-profile submenus.
  items.push({ label: 'Open Messenger…', click: () => showMessengerOrLauncher() })
  items.push({ type: 'separator' })
  for (const p of profiles.list()) {
    const record = [...messengerWindows.values()].find((r) => r.profileId === p.id && !r.win.isDestroyed())
    items.push({
      label: p.name,
      submenu: [
        ...(record
          ? [{
            label: 'Focus window',
            click: () => {
              if (record.win.isMinimized()) record.win.restore()
              record.win.show()
              record.win.focus()
            }
          }]
          : []),
        { label: record ? 'Open another window' : 'Connect', click: () => connectProfile(p) },
        ...(record ? [{ label: 'Disconnect', click: () => disconnectProfile(p.id) }] : [])
      ]
    })
  }
  items.push({ type: 'separator' })
  items.push({ label: 'Profile Manager', click: () => createLauncherWindow() })
  items.push({ type: 'separator' })
  items.push({ label: 'Quit LVChat Messenger', click: () => { isQuitting = true; app.quit() } })
  tray.setContextMenu(Menu.buildFromTemplate(items))
}

function trayPresent () {
  return !!tray
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

  // Switch accounts cleanly: close every other connected profile's windows
  // (their partitions are wiped by their own logout flows; here we just tear
  // down the windows) and open the target profile's messenger.
  ipcMain.handle('profiles:switch', (_e, { id }) => {
    const target = profiles.find(id)
    if (!target) return { ok: false, error: 'Profile not found' }
    for (const pid of new Set([...messengerWindows.values()].map((r) => r.profileId))) {
      if (pid !== id) disconnectProfile(pid)
    }
    return connectProfile(target)
  })

  ipcMain.handle('credentials:save', (_e, payload) => profiles.setCredentials(payload?.id, payload?.username, payload?.password))
  ipcMain.handle('credentials:has', (_e, { id }) => !!profiles.getCredentials(id)?.hasPassword)

  // Messenger window bridge.
  ipcMain.handle('msg:profile', (event) => {
    const record = messengerWindows.get(event.sender.id)
    if (!record) return null
    const p = profiles.find(record.profileId)
    return p ? { id: p.id, name: p.name, url: p.url, username: p.username || null } : null
  })
  ipcMain.handle('msg:savedCredentials', (event) => {
    const record = messengerWindows.get(event.sender.id)
    if (!record) return null
    const creds = profiles.getCredentials(record.profileId)
    return creds && creds.username ? { username: creds.username, password: creds.password || null, hasPassword: !!creds.password } : null
  })
  ipcMain.handle('credentials:clear', (_e, { id }) => profiles.deleteCredentials(id))

  // Wipe the profile partition's cookies + storage so the next load shows the
  // login screen, then reload the requesting window.
  ipcMain.handle('msg:logout', (event) => {
    const record = messengerWindows.get(event.sender.id)
    if (!record) return { ok: false }
    const ses = session.fromPartition(record.partition)
    return ses.clearStorageData({}).then(() => {
      // Dedicated chat windows share the wiped partition session — destroy them
      // (close() would be intercepted by the close-to-tray handler and leave
      // stale hidden windows holding the old session).
      for (const r of [...messengerWindows.values()]) {
        if (r.profileId === record.profileId && r.id !== record.id && !r.win.isDestroyed()) r.win.destroy()
      }
      if (!record.win.isDestroyed()) record.win.loadURL(appOrigin + '/messenger.html?profile=' + encodeURIComponent(record.profileId))
      return { ok: true }
    })
  })

  // Open a dedicated conversation window (Compact view double-click).
  ipcMain.handle('chat:open', (event, { type, id }) => {
    const record = messengerWindows.get(event.sender.id)
    if (!record) return { ok: false, error: 'Unknown window' }
    if (type !== 'dm' && type !== 'room') return { ok: false, error: 'Bad conversation type' }
    if (!id) return { ok: false, error: 'Bad conversation id' }
    const profile = profiles.find(record.profileId)
    if (!profile) return { ok: false, error: 'Profile not found' }
    return openChatWindow(profile, type, id)
  })

  ipcMain.handle('clipboard:write', (_e, text) => {
    clipboard.writeText(String(text == null ? '' : text))
    return { ok: true }
  })

  // Size the main messenger window for the active layout. Compact matches the
  // (user-resizable) sidebar width; Advanced opens the chat pane wide. The
  // sidebar's own width is never touched, so the list never shrinks.
  ipcMain.handle('window:setCompact', (event, compact) => {
    const r = messengerWindows.get(event.sender.id)
    if (!r || r.kind !== 'main' || r.win.isDestroyed()) return { ok: false }
    const win = r.win
    if (win.isMaximized()) win.unmaximize()
    if (compact) {
      const sidebarWidth = Number(loadPrefs().sidebarWidth) || 320
      win.setMinimumSize(260, 420)
      // Size the window to exactly the list width so Compact is just the left
      // pane (no dead space to its right).
      win.setSize(Math.max(260, Math.round(sidebarWidth)), 700)
    } else {
      win.setMinimumSize(800, 560)
      win.setSize(1120, 760)
    }
    return { ok: true }
  })

  // A session is live (the user logged in) — tuck the profile manager away.
  // It stays reopenable from File → Profile Manager (Cmd/Ctrl+M).
  ipcMain.on('msg:loginComplete', (event) => {
    const record = messengerWindows.get(event.sender.id)
    if (!record || !launcherWindow || launcherWindow.isDestroyed()) return
    launcherWindow.close()
  })

  ipcMain.handle('msg:openExternal', (_e, url) => {
    if (typeof url === 'string' && /^https?:/i.test(url)) shell.openExternal(url)
    // Windows deep links (e.g. ms-settings:notifications) so we can send the
    // user straight to the OS notification settings when toasts don't appear.
    else if (process.platform === 'win32' && typeof url === 'string' && /^ms-settings:/i.test(url)) shell.openExternal(url)
  })

  // Native OS notifications requested by the messenger renderer. Shown by the
  // main process (the renderer's Notification permission is unreliable);
  // clicking restores the window and opens the conversation that was alerted.
  ipcMain.on('msg:notify', (event, payload) => {
    const record = messengerWindows.get(event.sender.id)
    messengerNotifications++
    showOsNotification(record, payload && payload.title, payload && payload.body, payload && payload.conv)
  })

  ipcMain.handle('notify:test', () => {
    // Resolve with the real outcome so the settings button can show feedback.
    return new Promise((resolve) => {
      let done = false
      const finish = (state) => {
        if (done) return
        done = true
        resolve({ ok: state === 'shown', state })
      }
      const shown = showOsNotification(null, 'LVChat Messenger', 'Test notification — desktop alerts are working.', null, finish)
      if (shown === false) finish('unsupported')
      // Some platforms suppress toasts silently — surface that as "sent" after a
      // grace period instead of hanging forever.
      setTimeout(() => finish('sent'), 4000)
    })
  })

  ipcMain.handle('notify:stats', () => {
    let supported = false
    try { supported = Notification.isSupported() } catch (err) { /* ignore */ }
    return { count: messengerNotifications, supported }
  })

  // Reflect unread totals in the tray tooltip (main messenger windows only).
  ipcMain.on('tray:setUnread', (_event, count) => {
    if (!tray) return
    const n = Number(count) || 0
    tray.setToolTip(n > 0 ? 'LVChat Messenger — ' + n + ' unread' : 'LVChat Messenger')
  })

  ipcMain.handle('prefs:get', (_e, key) => loadPrefs()[key] ?? null)
  ipcMain.handle('prefs:set', (_e, { key, value }) => {
    const prefs = loadPrefs()
    prefs[key] = value
    savePrefs(prefs)
    return { ok: true }
  })

  ipcMain.handle('windows:list', () =>
    [...messengerWindows.values()]
      .filter((r) => !r.win.isDestroyed())
      .map((r) => ({ id: r.id, profileId: r.profileId, url: r.url, name: r.name }))
  )
  ipcMain.handle('windows:focus', (_e, { id }) => {
    const r = messengerWindows.get(Number(id))
    if (r && !r.win.isDestroyed()) {
      if (r.win.isMinimized()) r.win.restore()
      r.win.show()
      r.win.focus()
      return { ok: true }
    }
    return { ok: false }
  })
  ipcMain.handle('windows:close', (_e, { id }) => {
    const r = messengerWindows.get(Number(id))
    if (r && !r.win.isDestroyed()) setTimeout(() => {
      if (!r.win.isDestroyed()) r.win.destroy()
    }, 50)
    return { ok: true }
  })
  ipcMain.handle('launcher:show', () => { createLauncherWindow(); return { ok: true } })
  // Quit the app from the messenger UI (guaranteed path even if the tray menu
  // is unreachable on the host desktop).
  ipcMain.handle('app:quit', () => {
    isQuitting = true
    app.quit()
    return { ok: true }
  })
  ipcMain.on('app:refresh-menus', () => {
    buildMenu()
    rebuildTrayMenu()
  })
}

// ── Updates ─────────────────────────────────────────────────────────────────

function feedUrlFor () {
  return updater.resolveFeedUrl(profiles.list())
}

function broadcastUpdateStatus () {
  if (launcherWindow && !launcherWindow.isDestroyed()) {
    launcherWindow.webContents.send('updates:status', appUpdater ? appUpdater.getState() : { state: 'idle' })
  }
}

function checkForUpdates ({ manual, quiet } = {}) {
  if (!appUpdater) return Promise.resolve({ state: 'idle' })
  broadcastUpdateStatus()
  const p = appUpdater.checkNow({ quiet: manual ? false : quiet })
  if (p && typeof p.then === 'function') {
    p.catch((err) => console.warn('update check failed:', err && err.message))
  }
  return p
}

function setupUpdater () {
  if (appUpdater) return appUpdater
  appUpdater = updater.createUpdater({
    currentVersion: app.getVersion(),
    feedUrl: feedUrlFor,
    onStatus: broadcastUpdateStatus
  })
  ipcMain.handle('updates:check', (_e, opts) => checkForUpdates({ manual: true, quiet: !!(opts && opts.quiet) }))
  ipcMain.handle('updates:status', () => appUpdater.getState())
  ipcMain.handle('updates:feed', () => ({
    url: appUpdater.feedUrl(),
    currentVersion: appUpdater.currentVersion
  }))
  ipcMain.handle('updates:server', (_e, { id }) => {
    const profile = profiles.find(id)
    if (!profile) return { ok: false, error: 'Profile not found' }
    return profiles.getServerUpdater(profile)
  })
  ipcMain.handle('updates:quit-and-install', () => { appUpdater.quitAndInstall(); return { ok: true } })
  // Quiet background auto-check on startup (delayed so windows can come up),
  // then once every 12h while the app runs.
  setTimeout(() => checkForUpdates({ quiet: true }), 15000)
  setInterval(() => checkForUpdates({ quiet: true }), 12 * 3600 * 1000)
  return appUpdater
}

app.on('web-contents-created', (_e, contents) => {
  contents.setWindowOpenHandler(({ url: target }) => {
    if (/^https?:/i.test(target)) shell.openExternal(target)
    return { action: 'deny' }
  })
  contents.on('will-navigate', (event, target) => {
    if (target === 'about:blank') return
    if (target.startsWith(appOrigin)) return
    event.preventDefault()
    if (/^https?:/i.test(target)) shell.openExternal(target)
  })
})

app.whenReady().then(async () => {
  const serving = await createStaticServer()
  staticServer = serving.server
  appOrigin = serving.origin

  registerIpc()
  setupUpdater()
  buildMenu()
  buildTray()
  ensureWindowsNotificationShortcut()

  // The Profile Manager is only ever auto-opened when there is nothing to
  // connect to (first run). Once a profile exists it is never shown on its own
  // at startup — auto-connect profiles open directly, and otherwise the most
  // recently used profile's messenger window opens (landing on the login modal
  // if there's no live session). The manager stays reachable manually.
  const allProfiles = profiles.list()
  if (allProfiles.length === 0) {
    createLauncherWindow()
  } else {
    let opened = 0
    const auto = allProfiles.filter((p) => p.autoConnect)
    if (auto.length > 0) {
      for (const p of auto) {
        const r = connectProfile(p)
        if (r && r.ok) opened++
      }
    } else {
      const last = [...allProfiles]
        .sort((a, b) => String(b.lastConnectedAt || '').localeCompare(String(a.lastConnectedAt || '')))[0]
        || allProfiles[0]
      const r = connectProfile(last)
      if (r && r.ok) opened++
    }
    // Safety net: if every connect failed (e.g. an invalid URL), fall back to
    // the Profile Manager so the user isn't left with nothing.
    if (opened === 0) createLauncherWindow()
  }

  app.on('activate', () => {
    // Dock click: restore a hidden messenger window (tray state) rather than
    // popping the Profile Manager; only open the launcher when nothing exists.
    showMessengerOrLauncher()
  })
})

app.on('window-all-closed', () => {
  // Keep running (launcher closed while messenger windows exist); quit is
  // handled by the launcher-close / Quit paths.
})

app.on('before-quit', () => { isQuitting = true })
app.on('will-quit', () => {
  if (staticServer) staticServer.close()
})

module.exports = { createLauncherWindow, connectProfile, disconnectProfile, messengerWindows, registerIpc, trayPresent, trayImage, appOrigin: () => appOrigin, setupUpdater, checkForUpdates, getAppUpdater: () => appUpdater }

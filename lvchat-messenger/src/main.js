const { app, BrowserWindow, ipcMain, Menu, shell, session } = require('electron')
const path = require('path')
const fs = require('fs')
const profiles = require('./profiles')
const { createStaticServer } = require('./server')

app.setName('LVChat Messenger')

let launcherWindow = null
let isQuitting = false
let staticServer = null
let appOrigin = 'http://127.0.0.1'
const messengerWindows = new Map()

const APP_ICON = path.join(__dirname, '..', 'build', 'icon.png')

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

  const existing = [...messengerWindows.values()].find((r) => r.profileId === profile.id)
  if (existing && !existing.win.isDestroyed()) {
    if (existing.win.isMinimized()) existing.win.restore()
    existing.win.focus()
    return { ok: true, id: existing.id, reused: true }
  }

  const win = new BrowserWindow({
    width: 1120,
    height: 760,
    minWidth: 800,
    minHeight: 560,
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

  const record = { id: webContentsId, win, profileId: profile.id, url: normalized, name: profile.name || normalized, partition }
  messengerWindows.set(webContentsId, record)
  profiles.touch(profile.id)

  win.loadURL(appOrigin + '/messenger.html?profile=' + encodeURIComponent(profile.id))
  win.once('ready-to-show', () => win.show())
  return { ok: true, id: webContentsId, reused: false }
}

function disconnectProfile (profileId) {
  const records = [...messengerWindows.values()].filter((r) => r.profileId === profileId)
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
      { label: 'Open Messenger', click: () => connectProfile(p) },
      { label: 'Edit', click: () => createLauncherWindow() }
    ]
  }))
  if (items.length === 0) items.push({ label: 'No servers added yet', enabled: false })
  items.push({ type: 'separator' })
  items.push({ label: 'Add server…', click: () => createLauncherWindow() })
  return items
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
      if (!record.win.isDestroyed()) record.win.loadURL(appOrigin + '/messenger.html?profile=' + encodeURIComponent(record.profileId))
      return { ok: true }
    })
  })

  ipcMain.handle('msg:openExternal', (_e, url) => {
    if (typeof url === 'string' && /^https?:/i.test(url)) shell.openExternal(url)
    return { ok: true }
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
  ipcMain.on('app:refresh-menus', () => buildMenu())
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
  buildMenu()
  createLauncherWindow()

  for (const p of profiles.list()) {
    if (p.autoConnect) connectProfile(p)
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createLauncherWindow()
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

module.exports = { createLauncherWindow, connectProfile, disconnectProfile, messengerWindows, registerIpc, appOrigin: () => appOrigin }

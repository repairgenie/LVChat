const { app, BrowserWindow, ipcMain, Menu, Tray, shell, session, nativeImage } = require('electron')
const path = require('path')
const profiles = require('./profiles')

app.setName('LVChat Desktop')

let launcherWindow = null
let tray = null
let isQuitting = false
const chatWindows = new Map()

const APP_ICON = path.join(__dirname, '..', 'build', 'icon.png')

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

function recordForContents (id) {
  return chatWindows.get(Number(id))
}

async function attemptAutoLogin (contents, profile, creds) {
  if (!creds || !creds.username || !creds.password) return
  const base = profile.url
  try {
    const pageUrl = contents.getURL()
    if (!sameSite(pageUrl, base)) return

    const next = new URL(pageUrl)
    const nextParam = next.searchParams.get('next') || '/app?channel=general'

    const result = await contents.executeJavaScript(`
      (async () => {
        try {
          const page = await fetch(window.location.origin + '/login', { credentials: 'include' })
          const html = await page.text()
          const doc = new DOMParser().parseFromString(html, 'text/html')
          const csrfInput = doc.querySelector('input[name="csrf"]')
          if (!csrfInput) return { ok: false, reason: 'no-csrf' }
          const form = doc.querySelector('form[action="/login"]')
          const nextField = form ? form.querySelector('input[name="next"]') : null
          const nextValue = nextField && nextField.value ? nextField.value : ${JSON.stringify(nextParam)}
          const res = await fetch('/login', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              csrf: csrfInput.value,
              username: ${JSON.stringify(creds.username)},
              password: ${JSON.stringify(creds.password)},
              next: nextValue
            })
          })
          return { ok: true, status: res.status, url: res.url, redirected: res.redirected }
        } catch (err) {
          return { ok: false, reason: 'error', message: String(err && err.message) }
        }
      })()
    `, true)

    if (result.ok && result.url && result.url.includes('/login/mfa')) {
      contents.loadURL(new URL('/login/mfa', base).toString())
      return
    }
    if (result.ok && result.url && !result.url.includes('/login')) {
      contents.loadURL(result.url)
      return
    }
  } catch (err) {
    console.warn('auto-login failed:', err.message)
  }
}

function pathnameOf (url) {
  try {
    return url ? new URL(url).pathname : ''
  } catch (err) {
    return ''
  }
}

function connectProfile (profile) {
  if (!profile) return { ok: false, error: 'Profile not found' }

  const normalized = profiles.normalizeUrl(profile.url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }

  const partition = profiles.partitionFor(profile.id)
  const ses = session.fromPartition(partition)
  ses.setPermissionRequestHandler((wc, permission, callback) => {
    const allowed = ['notifications', 'fullscreen', 'media', 'clipboard-sanitized-write']
    callback(allowed.includes(permission))
  })

  const existing = [...chatWindows.values()].find((r) => r.profileId === profile.id)
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
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  })

  const webContentsId = win.webContents.id
  const drop = () => { chatWindows.delete(webContentsId) }
  win.on('closed', drop)
  win.on('destroyed', drop)

  const record = { id: webContentsId, win, profileId: profile.id, url: normalized, name: profile.name || normalized, partition }
  chatWindows.set(webContentsId, record)
  profiles.touch(profile.id)

  win.webContents.on('did-navigate', () => {
    if (pathnameOf(win.webContents.getURL()) === '/login' && !record.autoLoginDone) {
      record.autoLoginDone = true
      attemptAutoLogin(win.webContents, profile, profiles.getCredentials(profile.id))
    }
  })
  win.webContents.on('did-finish-load', () => {
    if (pathnameOf(win.webContents.getURL()) === '/login' && !record.autoLoginDone) {
      record.autoLoginDone = true
      attemptAutoLogin(win.webContents, profile, profiles.getCredentials(profile.id))
    }
  })

  win.loadURL(new URL('/app', normalized).toString())
  return { ok: true, id: webContentsId, reused: false }
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
        { label: record ? 'Open another window' : 'Connect', click: () => connectProfile(p) },
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
      .map((r) => ({ id: r.id, profileId: r.profileId, url: r.url, name: r.name }))
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

  ipcMain.on('app:refresh-menus', () => {
    buildMenu()
    rebuildTrayMenu()
  })
}

app.on('web-contents-created', (_e, contents) => {
  contents.setWindowOpenHandler(({ url: target }) => {
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
    if (p.autoConnect) connectProfile(p)
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

module.exports = { createLauncherWindow, connectProfile, disconnectProfile, chatWindows, registerIpc, sameSite }

const { app, BrowserWindow, ipcMain, Menu, Tray, shell, session, nativeImage } = require('electron')
const path = require('path')
const crypto = require('crypto')
const sites = require('./sites')

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
    width: 680,
    height: 760,
    minWidth: 520,
    minHeight: 560,
    title: 'Site Manager',
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

function openChatWindow ({ url, name }) {
  const normalized = sites.normalizeUrl(url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }

  const partition = `persist:win-${crypto.randomUUID()}`
  const ses = session.fromPartition(partition)
  ses.setPermissionRequestHandler((wc, permission, callback) => {
    const allowed = ['notifications', 'fullscreen', 'media', 'clipboard-sanitized-write']
    callback(allowed.includes(permission))
  })

  const win = new BrowserWindow({
    width: 1280,
    height: 820,
    title: name ? `${name}` : normalized,
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

  const record = { id: webContentsId, win, url: normalized, name: name || normalized, partition }
  chatWindows.set(webContentsId, record)
  win.loadURL(normalized)
  return { ok: true, id: webContentsId }
}

function buildMenu () {
  const template = [
    ...(process.platform === 'darwin' ? [{ role: 'appMenu' }] : []),
    {
      label: 'File',
      submenu: [
        { label: 'Open Site Manager', accelerator: 'CmdOrCtrl+M', click: () => createLauncherWindow() },
        { type: 'separator' },
        process.platform === 'darwin' ? { role: 'close' } : { role: 'quit' }
      ]
    },
    { role: 'editMenu' },
    { role: 'viewMenu' },
    { role: 'windowMenu' }
  ]
  Menu.setApplicationMenu(Menu.buildFromTemplate(template))
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
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'Open Site Manager', click: () => createLauncherWindow() },
    { type: 'separator' },
    { label: 'Quit LVChat Desktop', click: () => { isQuitting = true; app.quit() } }
  ]))
  tray.on('click', () => createLauncherWindow())
}

function registerIpc () {
  ipcMain.handle('sites:list', () => ({
    sites: sites.list(),
    defaultUrl: sites.getDefaultUrl(),
    version: app.getVersion()
  }))
  ipcMain.handle('sites:add', (_e, payload) => sites.add(payload || {}))
  ipcMain.handle('sites:update', (_e, payload) => sites.update(payload?.id, payload || {}))
  ipcMain.handle('sites:remove', (_e, { id }) => sites.remove(id))
  ipcMain.handle('sites:open', (_e, payload) => openChatWindow(payload || {}))
  ipcMain.handle('windows:list', () =>
    [...chatWindows.values()].map((r) => ({ id: r.id, url: r.url, name: r.name }))
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

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createLauncherWindow()
  })
})

app.on('window-all-closed', () => {
  // Keep running in the tray; the app only quits via the tray/menu Quit,
  // or when the launcher is closed with no chat windows open.
})

app.on('before-quit', () => { isQuitting = true })

module.exports = { createLauncherWindow, openChatWindow, chatWindows, registerIpc, sameSite }

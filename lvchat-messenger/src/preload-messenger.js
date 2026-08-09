const { contextBridge, ipcRenderer } = require('electron')

// Bridge for messenger windows: profile + credential access, local prefs,
// logout (cookie wipe), and external-link opening. All network calls are made
// by the page directly to the LVChat server (CORS + session cookies).
contextBridge.exposeInMainWorld('msg', {
  platform: process.platform,
  profile: () => ipcRenderer.invoke('msg:profile'),
  listProfiles: () => ipcRenderer.invoke('profiles:list'),
  switchProfile: (payload) => ipcRenderer.invoke('profiles:switch', payload),
  savedCredentials: () => ipcRenderer.invoke('msg:savedCredentials'),
  saveCredentials: (payload) => ipcRenderer.invoke('credentials:save', payload),
  clearCredentials: (payload) => ipcRenderer.invoke('credentials:clear', payload),
  logout: () => ipcRenderer.invoke('msg:logout'),
  openExternal: (url) => ipcRenderer.invoke('msg:openExternal', url),
  prefsGet: (key) => ipcRenderer.invoke('prefs:get', key),
  prefsSet: (key, value) => ipcRenderer.invoke('prefs:set', { key, value }),
  showLauncher: () => ipcRenderer.invoke('launcher:show'),
  loginComplete: () => ipcRenderer.send('msg:loginComplete'),
  notify: (payload) => ipcRenderer.send('msg:notify', payload),
  notifyStats: () => ipcRenderer.invoke('notify:stats'),
  testNotification: () => ipcRenderer.invoke('notify:test'),
  setUnread: (count) => ipcRenderer.send('tray:setUnread', count),
  onOpenConversation: (cb) => ipcRenderer.on('msg:open-conv', (_e, conv) => { if (cb) cb(conv) }),
  openChat: (payload) => ipcRenderer.invoke('chat:open', payload),
  setCompact: (compact) => ipcRenderer.invoke('window:setCompact', compact),
  copyText: (text) => ipcRenderer.invoke('clipboard:write', text),
  quit: () => ipcRenderer.invoke('app:quit')
})

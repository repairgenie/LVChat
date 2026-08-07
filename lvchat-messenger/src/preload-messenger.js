const { contextBridge, ipcRenderer } = require('electron')

// Bridge for messenger windows: profile + credential access, local prefs,
// logout (cookie wipe), and external-link opening. All network calls are made
// by the page directly to the LVChat server (CORS + session cookies).
contextBridge.exposeInMainWorld('msg', {
  profile: () => ipcRenderer.invoke('msg:profile'),
  savedCredentials: () => ipcRenderer.invoke('msg:savedCredentials'),
  saveCredentials: (payload) => ipcRenderer.invoke('credentials:save', payload),
  clearCredentials: (payload) => ipcRenderer.invoke('credentials:clear', payload),
  logout: () => ipcRenderer.invoke('msg:logout'),
  openExternal: (url) => ipcRenderer.invoke('msg:openExternal', url),
  prefsGet: (key) => ipcRenderer.invoke('prefs:get', key),
  prefsSet: (key, value) => ipcRenderer.invoke('prefs:set', { key, value }),
  showLauncher: () => ipcRenderer.invoke('launcher:show'),
  loginComplete: () => ipcRenderer.send('msg:loginComplete'),
  notify: (payload) => ipcRenderer.send('msg:notify', payload)
})

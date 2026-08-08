const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('lvchat', {
  listProfiles: () => ipcRenderer.invoke('profiles:list'),
  probeServer: (payload) => ipcRenderer.invoke('profiles:probe', payload),
  addProfile: (payload) => ipcRenderer.invoke('profiles:add', payload),
  updateProfile: (payload) => ipcRenderer.invoke('profiles:update', payload),
  removeProfile: (payload) => ipcRenderer.invoke('profiles:remove', payload),
  connectProfile: (payload) => ipcRenderer.invoke('profiles:connect', payload),
  disconnectProfile: (payload) => ipcRenderer.invoke('profiles:disconnect', payload),
  switchProfile: (payload) => ipcRenderer.invoke('profiles:switch', payload),
  saveCredentials: (payload) => ipcRenderer.invoke('credentials:save', payload),
  hasCredentials: (payload) => ipcRenderer.invoke('credentials:has', payload),
  listWindows: () => ipcRenderer.invoke('windows:list'),
  focusWindow: (payload) => ipcRenderer.invoke('windows:focus', payload),
  closeWindow: (payload) => ipcRenderer.invoke('windows:close', payload),
  showLauncher: () => ipcRenderer.invoke('launcher:show'),
  refreshMenus: () => ipcRenderer.send('app:refresh-menus'),
  testNotification: () => ipcRenderer.invoke('notify:test'),
  notifyStats: () => ipcRenderer.invoke('notify:stats'),
  openExternal: (url) => ipcRenderer.invoke('msg:openExternal', url)
})

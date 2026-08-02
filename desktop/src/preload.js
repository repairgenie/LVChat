const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('siteAPI', {
  listSites: () => ipcRenderer.invoke('sites:list'),
  addSite: (payload) => ipcRenderer.invoke('sites:add', payload),
  updateSite: (payload) => ipcRenderer.invoke('sites:update', payload),
  removeSite: (payload) => ipcRenderer.invoke('sites:remove', payload),
  openSite: (payload) => ipcRenderer.invoke('sites:open', payload),
  listWindows: () => ipcRenderer.invoke('windows:list'),
  focusWindow: (payload) => ipcRenderer.invoke('windows:focus', payload),
  closeWindow: (payload) => ipcRenderer.invoke('windows:close', payload),
  showLauncher: () => ipcRenderer.invoke('launcher:show')
})

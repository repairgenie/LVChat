const { contextBridge, ipcRenderer } = require('electron')

// Minimal bridge for chat windows: lets the page request a native OS
// notification through the main process (the page's own Notification API is
// unreliable in Electron — permission often reads as denied). The web app
// dispatches `lvchat:notify` events which the injected bridge script forwards
// here; the main process shows the real notification.
contextBridge.exposeInMainWorld('lvchatNative', {
  notify: (payload) => ipcRenderer.send('desktop:notify', payload)
})

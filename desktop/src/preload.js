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
  closeLauncher: () => ipcRenderer.invoke('launcher:close'),
  testNotification: () => ipcRenderer.invoke('notify:test'),
  notifyStats: () => ipcRenderer.invoke('notify:stats'),
  refreshMenus: () => ipcRenderer.send('app:refresh-menus'),
  // Updates
  updatesCheck: (opts) => ipcRenderer.invoke('updates:check', opts),
  updatesStatus: () => ipcRenderer.invoke('updates:status'),
  updatesFeed: () => ipcRenderer.invoke('updates:feed'),
  updatesServer: (payload) => ipcRenderer.invoke('updates:server', payload),
  updatesQuitAndInstall: () => ipcRenderer.invoke('updates:quit-and-install'),
  onUpdateStatus: (cb) => {
    const listener = (_e, status) => cb(status)
    ipcRenderer.on('updates:status', listener)
    return () => ipcRenderer.removeListener('updates:status', listener)
  }
})

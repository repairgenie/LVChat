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

// Minimal bridge for chat windows: lets the page request a native OS
// notification through the main process (the page's own Notification API is
// unreliable in Electron — permission often reads as denied). The web app
// dispatches `lvchat:notify` events which the injected bridge script forwards
// here; the main process shows the real notification.
contextBridge.exposeInMainWorld('lvchatNative', {
  notify: (payload) => ipcRenderer.send('desktop:notify', payload)
})

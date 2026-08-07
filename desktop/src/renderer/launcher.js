const api = window.lvchat

const addToggle = document.getElementById('add-server-toggle')
const serverForm = document.getElementById('server-form')
const serverName = document.getElementById('server-name')
const serverUrl = document.getElementById('server-url')
const serverCheck = document.getElementById('server-check')
const serverCheckResult = document.getElementById('server-check-result')
const serverSave = document.getElementById('server-save')
const serverCancel = document.getElementById('server-cancel')
const serverUsername = document.getElementById('server-username')
const serverPassword = document.getElementById('server-password')
const passwordField = document.getElementById('password-field')
const savePasswordWrap = document.getElementById('save-password-wrap')
const savePassword = document.getElementById('save-password')
const keychainWarning = document.getElementById('keychain-warning')
const serverAutoConnect = document.getElementById('server-auto-connect')
const serverError = document.getElementById('server-error')

const serverList = document.getElementById('server-list')
const serverEmpty = document.getElementById('server-empty')

const connectionList = document.getElementById('connection-list')
const connectionEmpty = document.getElementById('connection-empty')
const connectionsRefresh = document.getElementById('connections-refresh')
const testNotificationBtn = document.getElementById('test-notification')
const notifyCountEl = document.getElementById('notify-count')

testNotificationBtn.addEventListener('click', async () => {
  await api.testNotification()
})

async function refreshNotifyCount () {
  try {
    const stats = await api.notifyStats()
    if (stats && typeof stats.count === 'number') {
      notifyCountEl.textContent = 'OS notifications shown: ' + stats.count
      notifyCountEl.hidden = false
    }
  } catch (err) {}
}
setInterval(refreshNotifyCount, 2000)
refreshNotifyCount()

let editingId = null
let originalUrl = null
let profilesData = []
let probed = null
let storageAvailable = true

function setError (el, msg) {
  el.textContent = msg
  el.hidden = !msg
}

function show (el, visible) {
  el.hidden = !visible
}

function el (tag, className, text) {
  const node = document.createElement(tag)
  if (className) node.className = className
  if (text !== undefined) node.textContent = text
  return node
}

function renderServers () {
  serverList.replaceChildren()
  show(serverEmpty, profilesData.length === 0)

  const connectedIds = new Set(connectedProfileIds())

  for (const profile of profilesData) {
    const li = el('li')

    const grow = el('div', 'grow')
    const top = el('div', 'row')
    const name = el('div', 'site-name', profile.name)
    if (connectedIds.has(profile.id)) {
      const badge = el('span', 'badge on', 'connected')
      top.append(name, badge)
    } else {
      top.append(name)
    }
    grow.appendChild(top)

    const urlLine = el('div', 'site-url')
    urlLine.textContent = profile.siteName
      ? `${profile.siteName} · ${profile.url}`
      : profile.url
    grow.appendChild(urlLine)

    if (profile.username) {
      const user = el('div', 'site-url')
      user.textContent = `@${profile.username}` + (profile.autoConnect ? ' · auto-connect' : '')
      grow.appendChild(user)
    } else if (profile.autoConnect) {
      const user = el('div', 'site-url')
      user.textContent = 'auto-connect'
      grow.appendChild(user)
    }

    const actions = el('div', 'actions')

    const connect = el('button', connectedIds.has(profile.id) ? 'ghost' : 'primary',
      connectedIds.has(profile.id) ? 'Focus' : 'Connect')
    connect.addEventListener('click', () => connectedIds.has(profile.id)
      ? focusProfile(profile.id)
      : connectProfile(profile))

    const disconnect = el('button', 'ghost', 'Disconnect')
    disconnect.disabled = !connectedIds.has(profile.id)
    disconnect.addEventListener('click', () => api.disconnectProfile({ id: profile.id }).then(loadAll))

    const edit = el('button', 'ghost', 'Edit')
    edit.addEventListener('click', () => startEdit(profile))

    const del = el('button', 'danger', 'Delete')
    del.addEventListener('click', () => removeProfile(profile))

    actions.append(connect, disconnect, edit, del)
    li.append(grow, actions)
    serverList.appendChild(li)
  }
}

function renderConnections () {
  api.listWindows().then((windows) => {
    connectionList.replaceChildren()
    show(connectionEmpty, windows.length === 0)

    for (const w of windows) {
      const li = el('li')

      const grow = el('div', 'grow')
      grow.appendChild(el('div', 'site-name', w.name))
      grow.appendChild(el('div', 'site-url', w.url))

      const actions = el('div', 'actions')
      const focusBtn = el('button', 'ghost', 'Focus')
      focusBtn.addEventListener('click', () => api.focusWindow({ id: w.id }))
      const closeBtn = el('button', 'danger', 'Close')
      closeBtn.addEventListener('click', () => api.closeWindow({ id: w.id }).then(renderConnections))
      actions.append(focusBtn, closeBtn)
      li.append(grow, actions)
      connectionList.appendChild(li)
    }
  })
}

function connectedProfileIds () {
  return (window.__connectedProfiles || []).length ? window.__connectedProfiles : []
}

async function loadConnections () {
  const windows = await api.listWindows()
  window.__connectedProfiles = [...new Set(windows.map((w) => w.profileId))]
  renderServers()
  renderConnections()
}

async function connectProfile (profile) {
  setError(serverError, '')
  const res = await api.connectProfile({ id: profile.id })
  if (!res.ok) {
    setError(serverError, res.error || 'Could not connect to that server.')
    return
  }
  api.closeLauncher()
}

async function focusProfile (profileId) {
  const windows = await api.listWindows()
  const w = windows.find((x) => x.profileId === profileId)
  if (w) api.focusWindow({ id: w.id })
}

function startEdit (profile) {
  editingId = profile.id
  originalUrl = normalizeForCompare(profile.url)
  serverName.value = profile.name
  serverUrl.value = profile.url
  serverUsername.value = profile.username || ''
  serverPassword.value = ''
  serverAutoConnect.checked = !!profile.autoConnect
  probed = null
  show(serverCheckResult, false)
  setError(serverError, '')
  openForm('Save')
  api.hasCredentials({ id: profile.id }).then((has) => {
    if (!has) {
      savePassword.checked = true
      serverPassword.placeholder = 'Saved in your system keychain'
    }
  })
}

function openForm (buttonLabel) {
  show(serverForm, true)
  show(addToggle, false)
  serverSave.textContent = buttonLabel
  serverName.focus()
}

function resetForm () {
  editingId = null
  originalUrl = null
  probed = null
  serverName.value = ''
  serverUrl.value = ''
  serverUsername.value = ''
  serverPassword.value = ''
  serverAutoConnect.checked = false
  savePassword.checked = true
  show(serverCheckResult, false)
  show(serverForm, false)
  show(addToggle, true)
  serverSave.textContent = 'Save'
  setError(serverError, '')
}

async function removeProfile (profile) {
  if (!window.confirm(`Delete "${profile.name}" from your servers?`)) return
  await api.removeProfile({ id: profile.id })
  await loadAll()
}

function setupFormVisibility () {
  show(passwordField, storageAvailable)
  show(savePasswordWrap, storageAvailable)
  show(keychainWarning, !storageAvailable)
  if (!storageAvailable) savePassword.checked = false
}

serverCheck.addEventListener('click', async () => {
  const url = serverUrl.value.trim()
  if (!url) return
  setError(serverCheckResult, '')
  serverCheck.disabled = true
  serverCheck.textContent = 'Checking…'
  const res = await api.probeServer({ url })
  serverCheck.disabled = false
  serverCheck.textContent = 'Check server'
  if (res.ok) {
    probed = { url: res.url, site: res.site, version: res.version }
    serverCheckResult.className = 'hint ok'
    serverCheckResult.textContent = `LVChat server found: ${res.site} (v${res.version})`
  } else {
    probed = null
    serverCheckResult.className = 'hint bad'
    serverCheckResult.textContent = res.error || 'Not a valid LVChat server.'
  }
  show(serverCheckResult, true)
})

addToggle.addEventListener('click', () => {
  editingId = null
  serverUrl.value = ''
  resetForm()
  openForm('Save')
  serverName.value = ''
})

serverCancel.addEventListener('click', resetForm)

serverForm.addEventListener('submit', async (e) => {
  e.preventDefault()
  const name = serverName.value.trim()
  const url = serverUrl.value.trim()
  const username = serverUsername.value.trim()

  if (!editingId && !probed) {
    setError(serverError, 'Click "Check server" first to confirm this is an LVChat server.')
    return
  }
  if (editingId && normalizeForCompare(serverUrl.value) !== originalUrl && !probed) {
    setError(serverError, 'The server URL changed — click "Check server" again before saving.')
    return
  }

  const payload = {
    name,
    url,
    username,
    autoConnect: serverAutoConnect.checked,
    siteName: probed ? probed.site : null
  }
  const res = editingId
    ? await api.updateProfile({ id: editingId, ...payload })
    : await api.addProfile(payload)
  if (!res.ok) {
    setError(serverError, res.error || 'Could not save that server.')
    return
  }

  const password = serverPassword.value
  if (savePassword.checked && password) {
    const cred = await api.saveCredentials({ id: res.profile.id, username, password })
    if (!cred.ok) setError(serverError, cred.error || 'Could not save credentials.')
  }

  resetForm()
  api.refreshMenus()
  await loadAll()
})

function normalizeForCompare (input) {
  return String(input || '').trim().replace(/\/+$/, '').toLowerCase()
}

async function loadAll () {
  const data = await api.listProfiles()
  profilesData = data.profiles
  storageAvailable = data.storageAvailable
  setupFormVisibility()
  document.getElementById('version').textContent = 'v' + data.version
  renderServers()
  renderConnections()
}

connectionsRefresh.addEventListener('click', loadConnections)

loadAll()
setInterval(loadConnections, 4000)

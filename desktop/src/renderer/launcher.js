const api = window.siteAPI

const quickForm = document.getElementById('quick-form')
const quickUrl = document.getElementById('quick-url')
const quickError = document.getElementById('quick-error')

const siteList = document.getElementById('site-list')
const siteEmpty = document.getElementById('site-empty')
const addToggle = document.getElementById('add-site-toggle')
const siteForm = document.getElementById('site-form')
const siteName = document.getElementById('site-name')
const siteUrl = document.getElementById('site-url')
const siteSave = document.getElementById('site-save')
const siteCancel = document.getElementById('site-cancel')
const siteError = document.getElementById('site-error')

const windowList = document.getElementById('window-list')
const windowEmpty = document.getElementById('window-empty')
const windowsRefresh = document.getElementById('windows-refresh')

let editingId = null
let sitesData = []

function setError (el, msg) {
  el.textContent = msg
  el.hidden = !msg
}

function renderSites () {
  siteList.replaceChildren()
  siteEmpty.hidden = sitesData.length !== 0

  for (const site of sitesData) {
    const li = document.createElement('li')

    const grow = document.createElement('div')
    grow.className = 'grow'

    const name = document.createElement('div')
    name.className = 'site-name'
    name.textContent = site.name
    grow.appendChild(name)

    const url = document.createElement('div')
    url.className = 'site-url'
    url.textContent = site.url
    grow.appendChild(url)

    const actions = document.createElement('div')
    actions.className = 'actions'

    const open = document.createElement('button')
    open.className = 'primary'
    open.textContent = 'Open'
    open.addEventListener('click', () => launch(site.url, site.name))

    const again = document.createElement('button')
    again.className = 'ghost'
    again.title = 'Open a second isolated window for this site'
    again.textContent = 'New Window'
    again.addEventListener('click', () => launch(site.url, site.name))

    const edit = document.createElement('button')
    edit.className = 'ghost'
    edit.textContent = 'Edit'
    edit.addEventListener('click', () => startEdit(site))

    const del = document.createElement('button')
    del.className = 'danger'
    del.textContent = 'Delete'
    del.addEventListener('click', () => removeSite(site))

    actions.append(open, again, edit, del)
    li.append(grow, actions)
    siteList.appendChild(li)
  }
}

function renderWindows () {
  api.listWindows().then((windows) => {
    windowList.replaceChildren()
    windowEmpty.hidden = windows.length !== 0

    for (const w of windows) {
      const li = document.createElement('li')

      const grow = document.createElement('div')
      grow.className = 'grow'

      const name = document.createElement('div')
      name.className = 'site-name'
      name.textContent = w.name
      grow.appendChild(name)

      const url = document.createElement('div')
      url.className = 'site-url'
      url.textContent = w.url
      grow.appendChild(url)

      const actions = document.createElement('div')
      actions.className = 'actions'

      const focusBtn = document.createElement('button')
      focusBtn.className = 'ghost'
      focusBtn.textContent = 'Focus'
      focusBtn.addEventListener('click', () => api.focusWindow({ id: w.id }))

      const closeBtn = document.createElement('button')
      closeBtn.className = 'danger'
      closeBtn.textContent = 'Close'
      closeBtn.addEventListener('click', () => api.closeWindow({ id: w.id }).then(refreshWindows))

      actions.append(focusBtn, closeBtn)
      li.append(grow, actions)
      windowList.appendChild(li)
    }
  })
}

async function launch (url, name) {
  setError(quickError, '')
  const res = await api.openSite({ url, name })
  if (!res.ok) {
    setError(quickError, res.error || 'Could not open that site.')
    return false
  }
  refreshWindows()
  return true
}

function startEdit (site) {
  editingId = site.id
  siteName.value = site.name
  siteUrl.value = site.url
  siteForm.hidden = false
  addToggle.hidden = true
  siteSave.textContent = 'Save'
  setError(siteError, '')
  siteName.focus()
}

function resetForm () {
  editingId = null
  siteName.value = ''
  siteUrl.value = ''
  siteForm.hidden = true
  addToggle.hidden = false
  siteSave.textContent = 'Save'
  setError(siteError, '')
}

function refreshWindows () {
  renderWindows()
  setTimeout(renderWindows, 4000)
}

quickForm.addEventListener('submit', async (e) => {
  e.preventDefault()
  const url = quickUrl.value.trim()
  if (!url) return
  await launch(url)
})

addToggle.addEventListener('click', () => {
  editingId = null
  siteName.value = ''
  siteUrl.value = ''
  siteForm.hidden = false
  addToggle.hidden = true
  siteSave.textContent = 'Save'
  setError(siteError, '')
  siteName.focus()
})

siteCancel.addEventListener('click', resetForm)

siteForm.addEventListener('submit', async (e) => {
  e.preventDefault()
  const payload = { name: siteName.value.trim(), url: siteUrl.value.trim() }
  const res = editingId ? await api.updateSite({ id: editingId, ...payload }) : await api.addSite(payload)
  if (!res.ok) {
    setError(siteError, res.error || 'Could not save that site.')
    return
  }
  resetForm()
  await loadSites()
})

async function removeSite (site) {
  if (!window.confirm(`Delete "${site.name}" from your sites?`)) return
  await api.removeSite({ id: site.id })
  await loadSites()
}

async function loadSites () {
  const data = await api.listSites()
  sitesData = data.sites
  quickUrl.placeholder = data.defaultUrl
  if (!quickUrl.value) quickUrl.value = data.defaultUrl
  document.getElementById('version').textContent = 'v' + data.version
  renderSites()
}

windowsRefresh.addEventListener('click', renderWindows)

loadSites()
renderWindows()

/* LVChat Messenger — IM-first client UI. */

'use strict'

const $ = (sel) => document.querySelector(sel)
const SYSTEM_KINDS = ['join', 'part', 'quit', 'kick', 'ban', 'topic', 'mode', 'nick', 'system', 'notice']

const state = {
  profile: null,
  me: null,
  friends: [],
  incoming: [],
  outgoing: [],
  groups: [],
  dmList: [],
  channelUnread: {},
  channelPresence: {},
  joinedChannels: [],
  open: null, // { type:'dm'|'room', id, title, members, since, topic }
  messages: [],
  tab: 'buddy',
  collapsed: new Set(),
  pollTimer: null,
  pollBusy: false,
  dirTimer: null
}

function esc (s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

function debounce (fn, ms) {
  let t
  return (...args) => {
    clearTimeout(t)
    t = setTimeout(() => fn(...args), ms)
  }
}

/* ── Avatars / status ─────────────────────────────────────── */

function avatarInitial (name) {
  return (name || '?').trim().charAt(0).toUpperCase()
}

function avatarEl (name, avatar, size) {
  const el = document.createElement('div')
  el.className = 'avatar'
  if (size) el.style.width = el.style.height = size
  if (avatar) {
    const img = document.createElement('img')
    img.src = LvApi.abs(avatar)
    img.alt = ''
    el.appendChild(img)
  } else {
    el.textContent = avatarInitial(name)
  }
  return el
}

function statusClass (user) {
  if (user && user.away) return 'away'
  if (user && user.is_online) return 'online'
  return 'offline'
}

/* ── Modal dialogs (prompt / confirm / alert) ─────────────── */

let modalResolve = null

function showModal ({ title, message, input, initial, okLabel, cancel }) {
  return new Promise((resolve) => {
    modalResolve = resolve
    $('#modal-title').textContent = title
    $('#modal-message').textContent = message || ''
    $('#modal-message').hidden = !message
    $('#modal-input').hidden = !input
    if (input) {
      $('#modal-input').value = initial || ''
      $('#modal-input').placeholder = input
    }
    $('#modal-ok').textContent = okLabel || 'OK'
    $('#modal-cancel').hidden = !cancel
    $('#modal').hidden = false
    if (input) $('#modal-input').focus()
  })
}

function settleModal (value) {
  $('#modal').hidden = true
  if (modalResolve) { modalResolve(value); modalResolve = null }
}

function appPrompt (title, initial, input) {
  return showModal({ title, input: input || 'Enter a value', initial, cancel: true })
    .then((v) => (v === null ? null : String(v).trim()))
}

function appConfirm (message) {
  return showModal({ title: 'Confirm', message, okLabel: 'Yes', cancel: true })
}

function appAlert (message) {
  return showModal({ title: 'LVChat Messenger', message, okLabel: 'OK', cancel: false })
}

/* ── Boot / auth flow ─────────────────────────────────────── */

async function boot () {
  state.profile = await window.msg.profile()
  if (!state.profile) {
    document.body.textContent = 'No profile. Open the Profile Manager and connect to a server.'
    return
  }
  LvApi.init(new URL(state.profile.url).origin)
  LvApi.resetCsrf()

  $('#login-server').value = state.profile.url
  $('#login-server-url').textContent = new URL(state.profile.url).origin
  $('#login-username').value = state.profile.username || ''
  $('#login-target').hidden = false

  applyTheme()

  // Resume an existing session if the messenger API is reachable. Never let a
  // fetch/CORS failure abort boot: always land on the login view.
  let me = null
  try {
    me = await LvApi.getJson('/api/me')
  } catch (err) {
    me = { ok: false, status: 0 }
  }

  if (me.ok && me.body && me.body.user) {
    await startMain(me.body.user)
    return
  }

  showView('login')

  // The messenger endpoint is missing or CORS-blocked → the server is running
  // an older LVChat build (no CORS middleware, no /api/me). Give the user an
  // actionable message instead of a silent failure.
  if (me.status === 404 || me.status === 0) {
    showLoginError(
      'Could not reach this LVChat server, or it is running an older build. ' +
      'Deploy the latest LVChat build (it adds CORS + the Messenger API) and try again.'
    )
    return
  }

  // Not signed in yet — try the keychain-saved credentials first.
  const saved = await window.msg.savedCredentials()
  if (saved && saved.username && saved.password) {
    await attemptLogin(saved.username, saved.password, false)
  } else if (saved && saved.username) {
    $('#login-username').value = saved.username
  }
}

function showView (name) {
  for (const v of ['login', 'mfa', 'main']) {
    $('#view-' + v).hidden = v !== name
  }
}

function showLoginError (msg) {
  $('#login-error').textContent = msg
  $('#login-error').hidden = !msg
  $('#login-submit').disabled = false
  $('#login-submit').textContent = 'Sign in'
}

async function attemptLogin (username, password, save) {
  $('#login-submit').disabled = true
  $('#login-submit').textContent = 'Signing in…'
  showLoginError('')
  const r = await LvApi.login(username, password)
  if (r.mfa) {
    $('#login-submit').disabled = false
    $('#login-submit').textContent = 'Sign in'
    showMfa(username, password, save)
    return
  }
  if (r.mfaSetup) {
    $('#login-submit').disabled = false
    $('#login-submit').textContent = 'Sign in'
    showLoginError('Two-factor authentication must be set up for this account. Open the web app to enroll.')
    window.msg.openExternal(new URL('/login/mfa/setup', LvApi.origin()).toString())
    return
  }
  if (r.error) {
    showLoginError(r.error)
    return
  }
  if (save) {
    await window.msg.saveCredentials({ id: state.profile.id, username, password })
  }
  const me = await LvApi.getJson('/api/me')
  if (me.ok && me.body && me.body.user) {
    await startMain(me.body.user)
  } else {
    showLoginError('Sign-in did not complete. Please try again.')
  }
}

function showMfa (username, password, save) {
  state._pendingLogin = { username, password, save }
  showView('mfa')
  $('#mfa-code').value = ''
  $('#mfa-error').hidden = true
  $('#mfa-submit').disabled = false
  $('#mfa-submit').textContent = 'Verify'
  $('#mfa-code').focus()
}

async function verifyMfa () {
  const code = $('#mfa-code').value.trim()
  if (!/^\d{6}$/.test(code)) {
    $('#mfa-error').textContent = 'Enter the 6-digit code.'
    $('#mfa-error').hidden = false
    return
  }
  $('#mfa-submit').disabled = true
  $('#mfa-submit').textContent = 'Verifying…'
  const r = await LvApi.mfaVerify(code)
  if (r.ok) {
    const pending = state._pendingLogin || {}
    if (pending.save && pending.password) {
      await window.msg.saveCredentials({ id: state.profile.id, username: pending.username, password: pending.password })
    }
    const me = await LvApi.getJson('/api/me')
    if (me.ok && me.body && me.body.user) {
      await startMain(me.body.user)
      return
    }
    $('#mfa-error').textContent = 'Could not complete sign-in.'
    $('#mfa-error').hidden = false
    $('#mfa-submit').disabled = false
    $('#mfa-submit').textContent = 'Verify'
    return
  }
  $('#mfa-error').textContent = r.error || 'Invalid authentication code.'
  $('#mfa-error').hidden = false
  $('#mfa-submit').disabled = false
  $('#mfa-submit').textContent = 'Verify'
}

async function doLogout () {
  stopPoll()
  LvApi.resetCsrf()
  await window.msg.logout()
}

/* ── Main app data ────────────────────────────────────────── */

async function startMain (me) {
  state.me = me
  $('#me-name').textContent = me.username
  $('#me-avatar').replaceChildren(avatarEl(me.username, me.avatar))
  $('#me-status').textContent = 'online'

  showView('main')
  try {
    await refreshBuddyData()
  } catch (err) { /* keep going; the poll loop re-renders */ }
  await startPoll()
  try {
    renderAll()
  } catch (err) { /* leave whatever rendered */ }
}

async function refreshBuddyData () {
  const [f, g] = await Promise.all([
    LvApi.getJson('/api/friends'),
    LvApi.getJson('/api/groups')
  ])
  if (f.ok && f.body) {
    state.friends = f.body.friends || []
    state.incoming = f.body.incoming || []
    state.outgoing = f.body.outgoing || []
  }
  if (g.ok && g.body) {
    state.groups = g.body.groups || []
  }
}

async function startPoll () {
  stopPoll()
  await pollTick()
  state.pollTimer = setInterval(pollTick, 2000)
}

function stopPoll () {
  if (state.pollTimer) {
    clearInterval(state.pollTimer)
    state.pollTimer = null
  }
}

async function pollTick () {
  if (state.pollBusy) return
  state.pollBusy = true
  try {
    let path = '/api/poll?since=' + (state.open ? state.open.since : 0)
    if (state.open) {
      const key = state.open.type === 'dm' ? 'dm' : 'channel'
      path += '&' + key + '=' + encodeURIComponent(state.open.id)
    }
    const j = await LvApi.getJson(path)
    if (j.status === 401) {
      LvApi.resetCsrf()
      stopPoll()
      showView('login')
      showLoginError('Your session has expired. Please sign in again.')
      return
    }
    if (!j.ok || !j.body) return
    handlePoll(j.body)
  } catch (err) {
    /* transient network error — keep polling */
  } finally {
    state.pollBusy = false
  }
}

function handlePoll (body) {
  if (body.reconnect) {
    location.reload()
    return
  }
  if (Array.isArray(body.dm_list)) state.dmList = body.dm_list
  if (Array.isArray(body.friends)) state.friends = body.friends
  if (Array.isArray(body.friend_requests)) state.incoming = body.friend_requests
  if (body.channel_unread && Array.isArray(body.channel_unread)) {
    const map = {}
    for (const c of body.channel_unread) map[c.slug] = c.unread
    state.channelUnread = map
    state.joinedChannels = body.channel_unread.map((c) => ({ slug: c.slug, unread: c.unread }))
  }
  if (body.channel_presence && Array.isArray(body.channel_presence)) {
    const map = {}
    for (const c of body.channel_presence) map[c.slug] = c.online
    state.channelPresence = map
  }

  // Messages belong to the currently open conversation.
  if (Array.isArray(body.messages) && state.open) {
    const echo = body.dm || body.channel || ''
    if (echo.toLowerCase() === state.open.id.toLowerCase()) {
      applyMessages(body.messages)
    }
  }

  if (state.open && state.open.type === 'room' && body.channel === state.open.id) {
    if (Array.isArray(body.presence)) state.open.members = body.presence
    if (typeof body.topic === 'string') state.open.topic = body.topic
  }
  if (state.open && state.open.type === 'dm' && body.dm === state.open.id && Array.isArray(body.presence) && body.presence.length) {
    state.open.presence = body.presence[0]
  }

  renderAll()
}

function applyMessages (messages) {
  let changed = false
  for (const m of messages || []) {
    const id = Number(m.id)
    if (id > state.open.since) state.open.since = id
    if (state.messages.some((x) => Number(x.id) === id)) continue
    state.messages.push(m)
    changed = true
  }
  if (changed) {
    state.messages.sort((a, b) => Number(a.id) - Number(b.id))
    renderStream()
  }
}

/* ── Conversation switching ───────────────────────────────── */

async function openConversation (type, id) {
  state.open = {
    type,
    id,
    title: type === 'room' ? '#' + id : id,
    since: 0,
    messages: [],
    members: [],
    topic: '',
    presence: null
  }
  state.messages = state.open.messages
  state.tab = type === 'room' ? 'rooms' : 'buddy'
  setTab(state.tab)
  renderAll()
  await pollTick()
  if (type === 'room') {
    await LvApi.postForm('/api/channel/read', { channel: id })
  }
}

function openDm (username) {
  openConversation('dm', username)
}

function openRoom (slug) {
  openConversation('room', slug)
}

/* ── Rendering: sidebar ───────────────────────────────────── */

function renderAll () {
  renderSidebar()
  renderChat()
}

function unreadFor (username) {
  const d = state.dmList.find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
  return d ? Number(d.unread) : 0
}

function friendAvatar (username) {
  for (const list of [state.friends, state.incoming, state.outgoing]) {
    const u = list.find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
    if (u && u.avatar) return u.avatar
  }
  for (const g of state.groups) {
    const u = (g.members || []).find((x) => String(x.username).toLowerCase() === String(username).toLowerCase())
    if (u && u.avatar) return u.avatar
  }
  return ''
}

function makeContact (user, opts) {
  const wrap = document.createElement('div')
  wrap.className = 'contact' + (user.is_online || user.away ? '' : ' off') + (opts.selected ? ' selected' : '')
  wrap.title = user.username

  const dot = document.createElement('div')
  dot.className = 'dot ' + statusClass(user)
  const avatar = avatarEl(user.username, user.avatar, '28px')
  const name = document.createElement('div')
  name.className = 'contact-name'
  name.textContent = user.username

  wrap.append(dot, avatar, name)

  const unread = unreadFor(user.username)
  if (unread > 0) {
    const b = document.createElement('span')
    b.className = 'unread'
    b.textContent = unread > 99 ? '99+' : unread
    wrap.appendChild(b)
  }

  wrap.addEventListener('click', (e) => {
    if (e.target.closest('.ctx-menu')) return
    openDm(user.username)
  })
  wrap.addEventListener('contextmenu', (e) => {
    e.preventDefault()
    openContextMenu(e.clientX, e.clientY, user)
  })
  return wrap
}

function renderSidebar () {
  // Directory search panel takes precedence while typing.
  const searching = $('#directory-search').value.trim() !== ''
  $('#panel-directory').hidden = !searching

  $('#tab-buddy').classList.toggle('active', state.tab === 'buddy')
  $('#tab-rooms').classList.toggle('active', state.tab === 'rooms')
  $('#tab-requests').classList.toggle('active', state.tab === 'requests')

  const reqCount = state.incoming.length
  const badge = $('#req-badge')
  badge.hidden = reqCount === 0
  badge.textContent = reqCount > 99 ? '99+' : reqCount

  $('#panel-buddy').hidden = state.tab !== 'buddy' || searching
  $('#panel-rooms').hidden = state.tab !== 'rooms' || searching
  $('#panel-requests').hidden = state.tab !== 'requests' || searching

  if (state.tab === 'buddy') renderBuddyList()
  if (state.tab === 'rooms') renderRoomsList()
  if (state.tab === 'requests') renderRequestsList()
}

function renderBuddyList () {
  const list = $('#buddy-list')
  list.replaceChildren()

  const assigned = new Set()
  for (const g of state.groups) {
    for (const m of (g.members || [])) assigned.add(String(m.id))
  }

  for (const g of state.groups) {
    list.appendChild(renderGroup(g))
  }

  // Ungrouped friends node.
  const ungrouped = state.friends.filter((f) => !assigned.has(String(f.id)))
  if (ungrouped.length) {
    const node = renderGroup({ id: 'ungrouped', name: 'Ungrouped', members: ungrouped, isUngrouped: true })
    list.appendChild(node)
  }

  if (state.friends.length === 0 && state.groups.length === 0) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No friends yet. Use the search box to find people and add them.'
    list.appendChild(empty)
  }
}

function renderGroup (g) {
  const node = document.createElement('div')
  node.className = 'group' + (state.collapsed.has('g:' + g.id) ? ' collapsed' : '')

  const head = document.createElement('div')
  head.className = 'group-head'
  const caret = document.createElement('span')
  caret.className = 'caret'
  caret.textContent = '▼'
  const title = document.createElement('span')
  title.textContent = g.isUngrouped ? g.name : g.name
  const count = document.createElement('span')
  count.className = 'count'
  count.textContent = (g.members || []).length

  const actions = document.createElement('span')
  actions.className = 'g-actions'
  if (!g.isUngrouped) {
    const ren = document.createElement('button')
    ren.className = 'icon-btn'
    ren.title = 'Rename group'
    ren.textContent = '✎'
    ren.addEventListener('click', (e) => { e.stopPropagation(); renameGroup(g) })
    const del = document.createElement('button')
    del.className = 'icon-btn'
    del.title = 'Delete group'
    del.textContent = '✕'
    del.addEventListener('click', (e) => { e.stopPropagation(); deleteGroup(g) })
    actions.append(ren, del)
  }
  head.append(caret, title, count, actions)
  head.addEventListener('click', () => {
    if (state.collapsed.has('g:' + g.id)) state.collapsed.delete('g:' + g.id)
    else state.collapsed.add('g:' + g.id)
    node.classList.toggle('collapsed')
  })

  const members = document.createElement('div')
  members.className = 'group-members'
  for (const m of (g.members || [])) {
    const c = makeContact(m, { selected: state.open && state.open.type === 'dm' && String(state.open.id).toLowerCase() === String(m.username).toLowerCase() })
    if (!g.isUngrouped) {
      c.addEventListener('contextmenu', (e) => {
        e.preventDefault()
        e.stopPropagation()
        openMemberContextMenu(e.clientX, e.clientY, m, g)
      })
    }
    members.appendChild(c)
  }
  if (!(g.members || []).length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No members'
    members.appendChild(empty)
  }

  node.append(head, members)
  return node
}

function renderRoomsList () {
  const list = $('#rooms-list')
  list.replaceChildren()
  if (!state.joinedChannels.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No rooms yet. Join rooms in the web app (or be invited) and they will appear here.'
    list.appendChild(empty)
    return
  }
  for (const c of state.joinedChannels) {
    const row = document.createElement('div')
    row.className = 'contact' + (state.open && state.open.type === 'room' && state.open.id === c.slug ? ' selected' : '')
    const icon = document.createElement('div')
    icon.className = 'avatar'
    icon.textContent = '#'
    const name = document.createElement('div')
    name.className = 'contact-name'
    name.textContent = c.slug
    row.append(icon, name)
    const online = state.channelPresence[c.slug]
    if (typeof online === 'number') {
      const oc = document.createElement('span')
      oc.className = 'contact-name'
      oc.style.flex = 'none'
      oc.style.color = 'var(--muted)'
      oc.style.fontSize = '11px'
      oc.textContent = online + ' online'
      row.appendChild(oc)
    }
    if (c.unread > 0) {
      const b = document.createElement('span')
      b.className = 'unread'
      b.textContent = c.unread > 99 ? '99+' : c.unread
      row.appendChild(b)
    }
    row.addEventListener('click', () => openRoom(c.slug))
    list.appendChild(row)
  }
}

function renderRequestsList () {
  const list = $('#requests-list')
  list.replaceChildren()

  if (state.incoming.length) {
    const t = document.createElement('div')
    t.className = 'panel-title'
    t.textContent = 'Incoming requests'
    list.appendChild(t)
    for (const r of state.incoming) {
      list.appendChild(makeRequestRow(r, 'incoming'))
    }
  }
  if (state.outgoing.length) {
    const t = document.createElement('div')
    t.className = 'panel-title'
    t.textContent = 'Sent requests'
    list.appendChild(t)
    for (const r of state.outgoing) {
      list.appendChild(makeRequestRow(r, 'outgoing'))
    }
  }
  if (!state.incoming.length && !state.outgoing.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No pending friend requests.'
    list.appendChild(empty)
  }
}

function makeRequestRow (r, dir) {
  const wrap = document.createElement('div')
  wrap.className = 'req'
  const meta = document.createElement('div')
  meta.className = 'req-meta'
  const avatar = avatarEl(r.username, r.avatar, '28px')
  const name = document.createElement('div')
  name.className = 'req-name'
  name.textContent = r.username
  meta.append(avatar, name)
  wrap.appendChild(meta)

  const actions = document.createElement('div')
  actions.className = 'req-actions'
  const accept = document.createElement('button')
  accept.className = 'primary'
  accept.textContent = 'Accept'
  const decline = document.createElement('button')
  decline.className = 'ghost small'
  decline.textContent = 'Decline'
  const cancel = document.createElement('button')
  cancel.className = 'ghost small'
  cancel.textContent = 'Cancel request'
  const dm = document.createElement('button')
  dm.className = 'ghost small'
  dm.textContent = 'Message'

  dm.addEventListener('click', () => openDm(r.username))

  if (dir === 'incoming') {
    accept.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/accept', { username: r.username })
      await refreshBuddyData()
    })
    decline.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/decline', { username: r.username })
      await refreshBuddyData()
    })
    actions.append(accept, decline, dm)
  } else {
    cancel.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/cancel', { username: r.username })
      await refreshBuddyData()
    })
    actions.append(cancel, dm)
  }
  wrap.appendChild(actions)
  return wrap
}

/* ── Directory search ─────────────────────────────────────── */

async function runDirectorySearch () {
  const q = $('#directory-search').value.trim()
  const list = $('#directory-list')
  list.replaceChildren()
  if (!q) return
  const j = await LvApi.getJson('/api/directory?q=' + encodeURIComponent(q) + '&limit=25')
  if (!j.ok || !j.body) return
  const results = j.body.results || []
  if (!results.length) {
    const empty = document.createElement('div')
    empty.className = 'empty'
    empty.textContent = 'No matching users found.'
    list.appendChild(empty)
    return
  }
  for (const r of results) {
    list.appendChild(makeDirectoryRow(r))
  }
}

function makeDirectoryRow (r) {
  const wrap = document.createElement('div')
  wrap.className = 'contact'
  const dot = document.createElement('div')
  dot.className = 'dot ' + (r.is_online ? 'online' : 'offline')
  const avatar = avatarEl(r.username, r.avatar, '28px')
  const name = document.createElement('div')
  name.className = 'contact-name'
  name.textContent = r.username
  wrap.append(dot, avatar, name)

  const btn = document.createElement('button')
  btn.className = 'ghost small'

  if (r.status === 'none') {
    btn.textContent = 'Add friend'
    btn.addEventListener('click', async () => {
      btn.disabled = true
      btn.textContent = '…'
      await LvApi.postForm('/api/friend/request', { username: r.username })
      btn.textContent = 'Requested ✓'
      await refreshBuddyData()
    })
  } else if (r.status === 'outgoing') {
    btn.textContent = 'Requested'
    btn.disabled = true
  } else if (r.status === 'incoming') {
    btn.textContent = 'Accept'
    btn.addEventListener('click', async () => {
      await LvApi.postForm('/api/friend/accept', { username: r.username })
      btn.textContent = 'Friends ✓'
      btn.disabled = true
      await refreshBuddyData()
    })
  } else if (r.status === 'friend') {
    btn.textContent = 'Friends ✓'
    btn.disabled = true
  } else {
    btn.textContent = r.status
    btn.disabled = true
  }
  wrap.appendChild(btn)
  wrap.addEventListener('click', (e) => {
    if (e.target.closest('button')) return
    openDm(r.username)
  })
  return wrap
}

/* ── Context menus ────────────────────────────────────────── */

let ctxMenu = null

function closeContextMenu () {
  if (ctxMenu) { ctxMenu.remove(); ctxMenu = null }
}

function openContextMenu (x, y, user) {
  closeContextMenu()
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'

  const dm = menuItem('Message', () => { openDm(user.username); closeContextMenu() })
  menu.appendChild(dm)

  // Add-to-group section.
  const groups = state.groups
  if (groups.length) {
    const label = document.createElement('div')
    label.className = 'ctx-label'
    label.textContent = 'Add to group'
    menu.appendChild(label)
    for (const g of groups) {
      const already = (g.members || []).some((m) => String(m.id) === String(user.id))
      const item = menuItem((already ? '✓ ' : '') + g.name, async () => {
        if (!already) {
          await LvApi.postForm('/api/groups/member/add', { group_id: g.id, friend_id: user.id })
          await refreshBuddyData()
        }
        closeContextMenu()
      })
      item.classList.toggle('checked', already)
      menu.appendChild(item)
    }
  }
  const newGroup = menuItem('New group…', () => { closeContextMenu(); promptNewGroupFor(user) })
  menu.appendChild(newGroup)

  const remove = menuItem('Remove friend', async () => {
    await LvApi.postForm('/api/friend/remove', { username: user.username })
    await refreshBuddyData()
    closeContextMenu()
  })
  remove.classList.add('danger')
  menu.appendChild(remove)

  document.body.appendChild(menu)
  positionMenu(menu, x, y)
}

function openMemberContextMenu (x, y, user, group) {
  closeContextMenu()
  const menu = document.createElement('div')
  menu.className = 'ctx-menu'
  menu.style.left = x + 'px'
  menu.style.top = y + 'px'
  const dm = menuItem('Message', () => { openDm(user.username); closeContextMenu() })
  menu.appendChild(dm)
  const remove = menuItem('Remove from ' + group.name, async () => {
    await LvApi.postForm('/api/groups/member/remove', { group_id: group.id, friend_id: user.id })
    await refreshBuddyData()
    closeContextMenu()
  })
  remove.classList.add('danger')
  menu.appendChild(remove)
  document.body.appendChild(menu)
  positionMenu(menu, x, y)
}

function menuItem (text, onClick) {
  const item = document.createElement('button')
  item.type = 'button'
  item.className = 'ctx-item'
  item.textContent = text
  item.addEventListener('click', onClick)
  return item
}

function positionMenu (menu, x, y) {
  const rect = menu.getBoundingClientRect()
  const left = Math.min(x, window.innerWidth - rect.width - 8)
  const top = Math.min(y, window.innerHeight - rect.height - 8)
  menu.style.left = left + 'px'
  menu.style.top = top + 'px'
}

/* ── Groups ───────────────────────────────────────────────── */

async function promptNewGroupFor (user) {
  const name = await appPrompt('New group name:', '', 'e.g. Friends')
  if (!name) return
  const r = await LvApi.postForm('/api/groups', { name })
  if (r.ok && r.body && r.body.group) {
    if (user) {
      await LvApi.postForm('/api/groups/member/add', { group_id: r.body.group.id, friend_id: user.id })
    }
    await refreshBuddyData()
  } else if (r.body && r.body.error) {
    await appAlert(r.body.error)
  }
}

async function renameGroup (g) {
  const name = await appPrompt('Rename group:', g.name, 'Group name')
  if (!name || name === g.name) return
  const r = await LvApi.postForm('/api/groups/rename', { id: g.id, name })
  if (r.ok) await refreshBuddyData()
  else if (r.body && r.body.error) await appAlert(r.body.error)
}

async function deleteGroup (g) {
  const ok = await appConfirm('Delete the "' + g.name + '" group? Contacts are not removed from your friends list.')
  if (!ok) return
  await LvApi.postForm('/api/groups/delete', { id: g.id })
  await refreshBuddyData()
}

/* ── Chat pane ────────────────────────────────────────────── */

function renderChat () {
  const open = state.open
  const title = $('#chat-title')
  const sub = $('#chat-sub')
  const composer = $('#composer')
  const membersBtn = $('#members-toggle')

  if (!open) {
    title.textContent = 'LVChat Messenger'
    sub.textContent = 'Pick a friend or room to start chatting'
    composer.hidden = true
    membersBtn.hidden = true
    $('#members').hidden = true
    $('#stream').replaceChildren()
    return
  }

  if (open.type === 'dm') {
    title.textContent = open.id
    const p = open.presence || {}
    const online = p.is_online ? 'online' : (p.away ? 'away' : 'offline')
    sub.textContent = online
    membersBtn.hidden = true
    $('#members').hidden = true
  } else {
    title.textContent = '#' + open.id
    const online = open.members ? open.members.filter((m) => m.is_online).length : 0
    sub.textContent = (open.topic || '') + (open.topic ? ' · ' : '') + (open.members ? open.members.length : 0) + ' members · ' + online + ' online'
    membersBtn.hidden = false
    renderMembers()
  }
  composer.hidden = false
  renderStream()
}

function renderMembers () {
  const panel = $('#members')
  const open = state.open
  if (!open || open.type !== 'room') { panel.hidden = true; return }
  panel.replaceChildren()
  const t = document.createElement('div')
  t.className = 'members-title'
  t.textContent = 'Active members — ' + (open.members ? open.members.length : 0)
  panel.appendChild(t)
  for (const m of open.members || []) {
    const row = document.createElement('div')
    row.className = 'contact'
    const dot = document.createElement('div')
    dot.className = 'dot ' + (m.is_online ? 'online' : 'offline')
    const avatar = avatarEl(m.username, m.avatar, '28px')
    const name = document.createElement('div')
    name.className = 'contact-name'
    name.textContent = m.username + (m.away ? ' (away)' : '')
    row.append(dot, avatar, name)
    row.addEventListener('click', () => openDm(m.username))
    panel.appendChild(row)
  }
  if (!(open.members || []).length) {
    const e = document.createElement('div')
    e.className = 'empty'
    e.textContent = 'No members online right now.'
    panel.appendChild(e)
  }
}

function isMine (m) {
  return state.me && String(m.sender_id) === String(state.me.id)
}

function renderStream () {
  const stream = $('#stream')
  stream.replaceChildren()
  let lastDate = ''
  for (const m of state.messages) {
    const date = String(m.created_at || '').slice(0, 10)
    if (date && date !== lastDate) {
      lastDate = date
      const sep = document.createElement('div')
      sep.className = 'date-sep'
      sep.textContent = formatDate(m.created_at)
      stream.appendChild(sep)
    }
    stream.appendChild(buildMessageEl(m))
  }
  scrollStream()
}

function buildMessageEl (m) {
  const kind = m.kind || 'message'
  const wrap = document.createElement('div')
  wrap.dataset.id = String(m.id)

  if (SYSTEM_KINDS.includes(kind)) {
    wrap.className = 'msg system'
    const body = document.createElement('div')
    body.className = 'msg-body'
    const c = document.createElement('div')
    c.className = 'msg-content'
    c.textContent = m.content || ''
    body.appendChild(c)
    wrap.appendChild(body)
    return wrap
  }

  const mine = isMine(m)
  wrap.className = 'msg' + (mine ? ' mine' : '') + (kind === 'action' ? ' action' : '')

  const avatar = avatarEl(m.username, friendAvatar(m.username), '30px')
  const body = document.createElement('div')
  body.className = 'msg-body'

  const meta = document.createElement('div')
  meta.className = 'msg-meta'
  const author = document.createElement('span')
  author.className = 'msg-author'
  author.textContent = m.username || 'unknown'
  const time = document.createElement('span')
  time.className = 'msg-time'
  time.textContent = formatTime(m.created_at)
  meta.append(author, time)
  body.appendChild(meta)

  const bubble = document.createElement('div')
  if (mine) bubble.className = 'bubble'
  bubble.appendChild(contentEl(kind, m.content))
  body.appendChild(bubble)

  wrap.append(mine ? body : avatar, mine ? avatar : body)
  return wrap
}

function contentEl (kind, content) {
  const div = document.createElement('div')
  div.className = 'msg-content'
  const lines = String(content || '').split('\n')

  if (kind === 'gif' || kind === 'image') {
    const url = (lines[0] || '').trim()
    const caption = lines.slice(1).join(' ').trim()
    if (url) {
      const img = document.createElement('img')
      img.className = 'media'
      img.src = LvApi.abs(url)
      img.alt = caption || 'image'
      img.loading = 'lazy'
      div.appendChild(img)
    }
    if (caption) {
      const cap = document.createElement('div')
      cap.className = 'caption'
      cap.textContent = caption
      div.appendChild(cap)
    }
    return div
  }

  // Plain text with autolinked URLs.
  const text = document.createElement('span')
  text.textContent = lines.join('\n')
  text.innerHTML = linkify(text.textContent)
  div.appendChild(text)
  return div
}

function linkify (text) {
  const escaped = esc(text)
  return escaped.replace(/(https?:\/\/[^\s<]+)/g, (url) => {
    return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>'
  })
}

function scrollStream () {
  const stream = $('#stream')
  requestAnimationFrame(() => { stream.scrollTop = stream.scrollHeight })
}

function formatTime (ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T') + 'Z')
  if (isNaN(d.getTime())) return ''
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatDate (ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T') + 'Z')
  if (isNaN(d.getTime())) return ''
  const today = new Date()
  if (d.toDateString() === today.toDateString()) return 'Today'
  return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
}

/* ── Composer ─────────────────────────────────────────────── */

async function sendMessage (content) {
  if (!state.open) return
  const trimmed = content.trim()
  if (!trimmed) return
  const data = { content: trimmed }
  if (state.open.type === 'dm') data.recipient = state.open.id
  else data.channel = state.open.id
  const r = await LvApi.postForm('/api/send', data)
  if (r.ok && r.body && r.body.message) {
    applyMessages([r.body.message])
  } else {
    const err = (r.body && r.body.error) || 'Message failed to send.'
    await appAlert(err)
  }
}

async function sendGif (gif) {
  if (!state.open) return
  const data = { gif_url: gif.url, gif_title: gif.title || '' }
  if (state.open.type === 'dm') data.recipient = state.open.id
  else data.channel = state.open.id
  const r = await LvApi.postForm('/api/send', data)
  if (r.ok && r.body && r.body.message) {
    applyMessages([r.body.message])
    hidePickers()
  } else {
    await appAlert((r.body && r.body.error) || 'Could not send the GIF.')
  }
}

async function sendImage (file) {
  if (!state.open || !file) return
  const fd = new FormData()
  fd.set('file', file, file.name || 'image')
  if (state.open.type === 'dm') fd.set('dm', state.open.id)
  else fd.set('channel', state.open.id)
  const r = await LvApi.upload('/api/upload', fd)
  if (r.ok && r.body && r.body.message) {
    applyMessages([r.body.message])
  } else {
    await appAlert((r.body && r.body.error) || 'Image upload failed.')
  }
}

function hidePickers () {
  $('#emoji-panel').hidden = true
  $('#gif-panel').hidden = true
}

async function loadGifs (q) {
  const grid = $('#gif-grid')
  grid.replaceChildren()
  if (!q) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = 'Type to search GIPHY…'
    grid.appendChild(note)
    return
  }
  const j = await LvApi.getJson('/api/gifs?q=' + encodeURIComponent(q) + '&limit=20')
  if (!j.ok) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = (j.body && j.body.error) || 'GIF search is unavailable.'
    grid.appendChild(note)
    return
  }
  const gifs = j.body.gifs || []
  if (!gifs.length) {
    const note = document.createElement('div')
    note.className = 'gif-note'
    note.textContent = 'No GIFs found.'
    grid.appendChild(note)
    return
  }
  for (const g of gifs) {
    const item = document.createElement('div')
    item.className = 'gif-item'
    const img = document.createElement('img')
    img.src = g.preview || g.url
    img.loading = 'lazy'
    img.title = g.title || ''
    item.appendChild(img)
    item.addEventListener('click', () => sendGif(g))
    grid.appendChild(item)
  }
}

/* ── Theme ────────────────────────────────────────────────── */

async function applyTheme () {
  let theme = await window.msg.prefsGet('theme')
  if (theme !== 'light' && theme !== 'dark') theme = 'dark'
  document.body.classList.remove('theme-light', 'theme-dark')
  document.body.classList.add('theme-' + theme)
}

async function toggleTheme () {
  const isLight = document.body.classList.contains('theme-light')
  const next = isLight ? 'dark' : 'light'
  document.body.classList.remove('theme-light', 'theme-dark')
  document.body.classList.add('theme-' + next)
  await window.msg.prefsSet('theme', next)
}

/* ── Wiring ───────────────────────────────────────────────── */

function setTab (tab) {
  state.tab = tab
  renderSidebar()
}

function wireEvents () {
  $('#login-form').addEventListener('submit', (e) => {
    e.preventDefault()
    const username = $('#login-username').value.trim()
    const password = $('#login-password').value
    const save = $('#login-save').checked
    if (!username || !password) {
      showLoginError('Enter your username and password.')
      return
    }
    attemptLogin(username, password, save)
  })

  $('#login-server').addEventListener('change', () => {
    const url = $('#login-server').value.trim()
    if (url) {
      try {
        const origin = new URL(url).origin
        state.profile = Object.assign({}, state.profile, { url })
        LvApi.init(origin)
        $('#login-server-url').textContent = origin
      } catch (err) { /* keep existing */ }
    }
  })

  $('#login-forgot').addEventListener('click', (e) => {
    e.preventDefault()
    window.msg.openExternal(new URL('/forgot-password', LvApi.origin()).toString())
  })
  $('#login-register').addEventListener('click', (e) => {
    e.preventDefault()
    window.msg.openExternal(new URL('/register', LvApi.origin()).toString())
  })

  $('#mfa-form').addEventListener('submit', (e) => {
    e.preventDefault()
    verifyMfa()
  })
  $('#mfa-back').addEventListener('click', (e) => {
    e.preventDefault()
    showView('login')
  })

  $('#modal-ok').addEventListener('click', () => {
    const input = $('#modal-input')
    const value = !input.hidden ? input.value : true
    settleModal(value)
  })
  $('#modal-cancel').addEventListener('click', () => settleModal(null))
  $('#modal-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') $('#modal-ok').click()
    if (e.key === 'Escape') $('#modal-cancel').click()
  })

  $('#theme-toggle').addEventListener('click', toggleTheme)
  $('#profile-manager').addEventListener('click', () => window.msg.showLauncher())
  $('#logout-btn').addEventListener('click', doLogout)

  $('#tab-buddy').addEventListener('click', () => setTab('buddy'))
  $('#tab-rooms').addEventListener('click', () => setTab('rooms'))
  $('#tab-requests').addEventListener('click', () => setTab('requests'))

  $('#btn-new-group').addEventListener('click', () => promptNewGroupFor(null))

  $('#directory-search').addEventListener('input', debounce(() => {
    renderSidebar()
    runDirectorySearch()
  }, 300))

  $('#members-toggle').addEventListener('click', () => {
    const panel = $('#members')
    panel.hidden = !panel.hidden
    if (!panel.hidden) renderMembers()
  })

  // Composer
  const input = $('#composer-input')
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      const content = input.value
      input.value = ''
      sendMessage(content)
    }
  })
  $('#composer-send').addEventListener('click', () => {
    const content = input.value
    input.value = ''
    sendMessage(content)
  })

  $('#btn-emoji').addEventListener('click', () => {
    const panel = $('#emoji-panel')
    const willShow = panel.hidden
    hidePickers()
    if (willShow) {
      panel.hidden = false
      if (!panel.dataset.built) {
        panel.dataset.built = '1'
        for (const e of window.EMOJIS) {
          const b = document.createElement('button')
          b.type = 'button'
          b.textContent = e
          b.addEventListener('click', () => {
            input.value += e
            input.focus()
          })
          panel.appendChild(b)
        }
      }
    }
  })

  $('#btn-gif').addEventListener('click', () => {
    const panel = $('#gif-panel')
    const willShow = panel.hidden
    hidePickers()
    if (willShow) {
      panel.hidden = false
      loadGifs($('#gif-search-input').value.trim())
      $('#gif-search-input').focus()
    }
  })
  $('#gif-search-input').addEventListener('input', debounce(() => {
    loadGifs($('#gif-search-input').value.trim())
  }, 350))

  $('#btn-image').addEventListener('click', () => $('#image-file').click())
  $('#image-file').addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0]
    if (file) sendImage(file)
    e.target.value = ''
  })

  document.addEventListener('click', (e) => {
    if (ctxMenu && !e.target.closest('.ctx-menu')) closeContextMenu()
    if (!e.target.closest('#emoji-panel') && !e.target.closest('#btn-emoji')) $('#emoji-panel').hidden = true
    if (!e.target.closest('#gif-panel') && !e.target.closest('#btn-gif') && !e.target.closest('#gif-search-input')) $('#gif-panel').hidden = true
  })
}

wireEvents()
boot()

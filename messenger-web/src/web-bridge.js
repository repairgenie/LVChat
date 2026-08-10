/* LVChat Messenger Web — web implementation of the Electron `window.msg`
 * bridge. Loaded after config.js + api.js and before messenger.js.
 *
 * The messenger UI is the same single-window client the Electron app ships;
 * this bridge replaces the OS services (profiles, keychain, tray, native
 * notifications, dedicated windows, prefs) with browser equivalents:
 *
 *   - single profile configured at build time (window.LVCHAT_CONFIG)
 *   - username remembered in localStorage (password never stored)
 *   - OS notifications via the Notification API; closed-tab notifications via
 *     Web Push (subscription handled here, rendered by sw.js)
 *   - dedicated conversation windows become browser tabs (messenger.html?chat=…)
 *   - prefs + send-queue live in localStorage
 */
window.msg = (() => {
  const config = window.LVCHAT_CONFIG || {}
  const prefsPrefix = 'lvcmsg.pref.'
  const userKey = 'lvcmsg.username'
  let notifCount = 0

  function serverUrl () {
    return config.serverUrl || ''
  }

  function appName () {
    return config.appName || 'LVChat Messenger'
  }

  /* The single configured profile. `username` stays null so the messenger
   * never tries to wipe an active session it doesn't recognize — it just uses
   * whatever session the browser holds for this server. */
  function profile () {
    return { id: 'default', name: appName(), url: serverUrl(), username: null }
  }

  function listProfiles () {
    return Promise.resolve({ profiles: [profile()], defaultUrl: serverUrl(), version: config.version || '' })
  }

  function switchProfile () {
    return Promise.resolve({ ok: false, error: 'Account switching is not available in the web messenger.' })
  }

  function savedCredentials () {
    let username = null
    try { username = localStorage.getItem(userKey) || null } catch (err) { /* ignore */ }
    return Promise.resolve({ username, password: null, hasPassword: false })
  }

  function saveCredentials (payload) {
    if (payload && payload.username) {
      try { localStorage.setItem(userKey, String(payload.username)) } catch (err) { /* ignore */ }
    }
    return Promise.resolve({ ok: true })
  }

  function clearCredentials () {
    try { localStorage.removeItem(userKey) } catch (err) { /* ignore */ }
    return Promise.resolve({ ok: true })
  }

  function prefsGet (key) {
    try { return JSON.parse(localStorage.getItem(prefsPrefix + key)) } catch (err) { return null }
  }

  function prefsSet (key, value) {
    try { localStorage.setItem(prefsPrefix + key, JSON.stringify(value)) } catch (err) { /* ignore */ }
    return Promise.resolve({ ok: true })
  }

  function openExternal (url) {
    if (typeof url === 'string' && /^https?:/i.test(url)) {
      try {
        const w = window.open(url, '_blank', 'noopener')
        if (w) w.opener = null
      } catch (err) { /* ignore */ }
    }
    return Promise.resolve({ ok: true })
  }

  function showLauncher () {
    // No profile manager on the web — nothing to do.
    return Promise.resolve({ ok: true })
  }

  function loginComplete () {
    // No launcher window to tuck away.
  }

  /* End the server session (its Set-Cookie clears the cross-site session
   * cookie), wipe local prefs + shell caches, and return to the sign-in view. */
  async function logout () {
    try {
      const tok = await window.LvApi.csrf()
      const body = new URLSearchParams({ csrf: tok, ajax: '1' })
      await fetch(serverUrl() + '/logout', {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body
      })
    } catch (err) { /* offline — the local wipe still applies */ }
    try { localStorage.removeItem(userKey) } catch (err) { /* ignore */ }
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHES' })
      }
    } catch (err) { /* ignore */ }
    window.location.href = './messenger.html'
    return { ok: true }
  }

  /* ── Notifications (tab open) ─────────────────────────────────────────── */

  function notify (payload) {
    try {
      if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return
      notifCount++
      const conv = payload && payload.conv
      const n = new Notification(String((payload && payload.title) || appName()), {
        body: String((payload && payload.body) || ''),
        icon: './icons/icon-192.png',
        badge: './icons/icon-192.png'
      })
      n.onclick = () => {
        try { window.focus() } catch (err) { /* ignore */ }
        if (conv && conv.type && conv.id) openConversation(conv.type, conv.id)
      }
    } catch (err) { /* notifications unavailable — never crash an alert */ }
  }

  function openConversation (type, id) {
    try {
      if (window.openConversationOrWindow) window.openConversationOrWindow(type, id)
      else window.open('./messenger.html?chat=' + encodeURIComponent(type + ':' + id), '_blank')
    } catch (err) { /* ignore */ }
  }

  function notifyStats () {
    return Promise.resolve({
      count: notifCount,
      supported: typeof Notification !== 'undefined'
    })
  }

  function testNotification () {
    return new Promise((resolve) => {
      try {
        if (typeof Notification === 'undefined') return resolve({ ok: false, state: 'unsupported' })
        const finish = (state) => resolve({ ok: state === 'shown', state })
        const showTest = () => {
          try {
            new Notification(appName(), { body: 'Test notification — browser notifications are working.', icon: './icons/icon-192.png' })
          } catch (err) { /* ignore */ }
        }
        if (Notification.permission === 'granted') { showTest(); return finish('shown') }
        if (Notification.permission === 'denied') return finish('denied')
        Notification.requestPermission().then((perm) => {
          if (perm !== 'granted') return finish('denied')
          showTest()
          finish('shown')
        }).catch(() => finish('denied'))
      } catch (err) {
        resolve({ ok: false, state: 'error: ' + ((err && err.message) || err) })
      }
    })
  }

  /* Unread totals go to the document title instead of the tray tooltip. */
  function setUnread (count) {
    const n = Number(count) || 0
    try { document.title = n > 0 ? appName() + ' (' + n + ')' : appName() } catch (err) { /* ignore */ }
  }

  function onOpenConversation () {
    // Notification clicks are handled directly in notify().
  }

  /* A dedicated conversation window becomes a browser tab. */
  function openChat (payload) {
    const type = payload && payload.type
    const id = payload && payload.id
    if ((type === 'dm' || type === 'room') && id) {
      try {
        window.open('./messenger.html?chat=' + encodeURIComponent(type + ':' + id), '_blank')
      } catch (err) { /* ignore */ }
    }
    return Promise.resolve({ ok: true })
  }

  function setCompact () {
    // Browsers can't resize windows for a layout mode — the CSS handles it.
    return Promise.resolve({ ok: true })
  }

  function copyText (text) {
    const t = String(text == null ? '' : text)
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(t)
        .then(() => ({ ok: true }))
        .catch(() => { legacyCopy(t); return { ok: true } })
    }
    legacyCopy(t)
    return Promise.resolve({ ok: true })
  }

  function legacyCopy (text) {
    try {
      const ta = document.createElement('textarea')
      ta.value = text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      ta.remove()
    } catch (err) { /* ignore */ }
  }

  function quit () {
    try { window.close() } catch (err) { /* ignore */ }
    return Promise.resolve({ ok: true })
  }

  /* ── Web Push (tab closed) ────────────────────────────────────────────── */

  function pushSupported () {
    return window.isSecureContext && 'serviceWorker' in navigator &&
      'PushManager' in window && typeof Notification !== 'undefined'
  }

  function b64uToBytes (b64) {
    const bin = atob(b64.replace(/-/g, '+').replace(/_/g, '/'))
    const bytes = new Uint8Array(bin.length)
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
    return bytes
  }

  function b64uFromBytes (bytes) {
    let bin = ''
    bytes.forEach((b) => { bin += String.fromCharCode(b) })
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  }

  function pushPayload (sub) {
    const p256 = sub.getKey ? sub.getKey('p256dh') : null
    const auth = sub.getKey ? sub.getKey('auth') : null
    if (!p256 || !auth) return null
    return {
      endpoint: sub.endpoint,
      p256dh: b64uFromBytes(new Uint8Array(p256)),
      auth: b64uFromBytes(new Uint8Array(auth))
    }
  }

  /* Register the service worker and subscribe to the server's push feed.
   * Returns { enabled, reason } so the UI can explain any failure. */
  async function setupWebPush () {
    if (!pushSupported()) return { enabled: false, reason: 'unsupported' }
    try {
      const me = await window.LvApi.getJson('/api/me')
      const vapid = me.body && me.body.vapidPublicKey
      if (!vapid) return { enabled: false, reason: 'no-vapid' }
      const reg = await navigator.serviceWorker.register('./sw.js')
      await navigator.serviceWorker.ready
      if (Notification.permission === 'denied') return { enabled: false, reason: 'denied' }
      const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission()
      if (perm !== 'granted') return { enabled: false, reason: 'denied' }
      let sub = await reg.pushManager.getSubscription()
      if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(vapid) })
      const payload = pushPayload(sub)
      if (!payload) return { enabled: false, reason: 'payload' }
      const r = await window.LvApi.postForm('/api/push/subscribe', {
        endpoint: payload.endpoint,
        p256dh: payload.p256dh,
        auth: payload.auth
      })
      if (!r.ok) return { enabled: false, reason: 'server' }
      return { enabled: true }
    } catch (err) {
      return { enabled: false, reason: 'error' }
    }
  }

  async function pushStatus () {
    if (!pushSupported()) return { supported: false, enabled: false, permission: '' }
    try {
      const reg = await navigator.serviceWorker.ready
      const sub = await reg.pushManager.getSubscription()
      return { supported: true, enabled: !!sub, permission: Notification.permission }
    } catch (err) {
      return { supported: true, enabled: false, permission: Notification.permission }
    }
  }

  async function teardownWebPush () {
    try {
      if ('serviceWorker' in navigator) {
        const reg = await navigator.serviceWorker.ready
        const sub = await reg.pushManager.getSubscription()
        if (sub) await sub.unsubscribe()
      }
      await window.LvApi.postForm('/api/push/unsubscribe', {})
    } catch (err) { /* the session wipe covers it */ }
  }

  return {
    platform: (typeof navigator !== 'undefined' ? navigator.platform : ''),
    profile, listProfiles, switchProfile,
    savedCredentials, saveCredentials, clearCredentials,
    logout, openExternal,
    prefsGet, prefsSet,
    showLauncher, loginComplete,
    notify, notifyStats, testNotification, setUnread, onOpenConversation,
    openChat, setCompact, copyText, quit,
    setupWebPush, pushStatus, teardownWebPush
  }
})()

/* Register the service worker on load so the app shell is precached (offline
 * launch) and Web Push is ready once the user signs in. Best-effort — the app
 * still works without it. */
try {
  if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('./sw.js').catch(() => { /* ignore */ })
  }
} catch (err) { /* ignore */ }

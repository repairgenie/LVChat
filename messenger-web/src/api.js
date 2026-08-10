/* LVChat Messenger API client.
 *
 * Talks to an LVChat server over HTTPS (or local HTTP) using the browser
 * session's cookies (credentials: 'include'). The PHP backend's config-gated
 * CORS middleware allows the messenger's loopback origin. POSTs are
 * form-encoded with the CSRF token in the body (no custom headers, so no
 * preflight); image uploads are multipart (triggers the backend's OPTIONS
 * preflight handler). Login uses redirect:'manual' so the messenger can detect
 * the MFA gate without downloading the full /app page.
 */
window.LvApi = (() => {
  let base = ''
  let csrfToken = ''

  function origin () { return base }

  function init (serverOrigin) {
    base = String(serverOrigin || '').replace(/\/+$/, '')
    csrfToken = ''
  }

  /* Drop the cached CSRF token (e.g. after a session wipe / expiry). */
  function resetCsrf () {
    csrfToken = ''
  }

  function headers (extra) {
    return Object.assign({}, extra)
  }

  /* Fetch without throwing: network/CORS failures return a status-0 result so
   * callers can render a helpful message instead of a blank window. */
  async function get (path) {
    try {
      const res = await fetch(base + path, { credentials: 'include', headers: headers() })
      return res
    } catch (err) {
      return { status: 0, ok: false, res: null, error: String((err && err.message) || err) }
    }
  }

  async function getJson (path) {
    const res = await get(path)
    if (!res || res.status === 0) return { status: 0, ok: false, body: null, res: null, error: res ? res.error : '' }
    let body
    try {
      body = await res.json()
    } catch (err) {
      body = null
    }
    return { status: res.status, ok: res.ok, body, res }
  }

  /* Fetch the session CSRF token: from /api/csrf, else by parsing /login. */
  async function csrf () {
    if (csrfToken) return csrfToken
    try {
      const j = await getJson('/api/csrf')
      if (j.body && typeof j.body.csrf === 'string' && j.body.csrf) {
        csrfToken = j.body.csrf
        return csrfToken
      }
    } catch (err) { /* fall through */ }
    try {
      const res = await get('/login')
      if (!res || typeof res.text !== 'function') return csrfToken
      const html = await res.text()
      const m = html.match(/name="csrf" value="([^"]+)"/)
      if (m) csrfToken = m[1]
    } catch (err) { /* ignore */ }
    return csrfToken
  }

  async function postForm (path, data) {
    const tok = await csrf()
    const body = new URLSearchParams(data)
    body.set('csrf', tok)
    body.set('ajax', '1') // JSON responses; without this /api/send treats "/…" as a command and redirects
    let res
    try {
      res = await fetch(base + path, {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body
      })
    } catch (err) {
      return { status: 0, ok: false, body: null, res: null, error: String((err && err.message) || err) }
    }
    let j = null
    try {
      j = await res.json()
    } catch (err) { /* non-JSON (redirect/419 text) */ }
    return { status: res.status, ok: res.ok, body: j, res }
  }

  async function upload (path, formData) {
    const tok = await csrf()
    formData.set('csrf', tok)
    let res
    try {
      res = await fetch(base + path, {
        method: 'POST',
        credentials: 'include',
        body: formData
      })
    } catch (err) {
      return { status: 0, ok: false, body: null, res: null, error: String((err && err.message) || err) }
    }
    let j = null
    try {
      j = await res.json()
    } catch (err) { /* ignore */ }
    return { status: res.status, ok: res.ok, body: j, res }
  }

  /* Attempt a password login. Returns { ok:true } | { mfa:true } | { error }.
   * Redirects are followed (like the desktop client): the login POST answers
   * 302 → /login/mfa for MFA users or → next (/app) otherwise, so the final
   * URL tells us which branch we're on, and the session cookie from the 302
   * is stored along the way. */
  async function login (username, password) {
    const tok = await csrf()
    const body = new URLSearchParams({ csrf: tok, username, password, next: '/app' })
    let res
    try {
      res = await fetch(base + '/login', {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body
      })
    } catch (err) {
      return { error: 'Could not reach the server. ' + String(err && err.message || '') }
    }
    const finalUrl = res.url || ''
    if (finalUrl.indexOf('/login/mfa/setup') !== -1) {
      // Account class requires MFA but it isn't enrolled yet — only the web app
      // can walk the user through setup (QR code + secret).
      return { mfaSetup: true }
    }
    if (finalUrl.indexOf('/login/mfa') !== -1) return { mfa: true }
    if (finalUrl.indexOf('/login') !== -1) {
      // Bounced back to a login-family page (bad creds, banned, suspended…).
      let html = ''
      try { html = await res.text() } catch (err) { /* ignore */ }
      return { error: extractFlash(html) || 'Login failed. Check your credentials.' }
    }
    if (!res.redirected && res.status === 200) {
      const html = await res.text()
      return { error: extractFlash(html) || 'Login failed. Check your credentials.' }
    }
    return { ok: true }
  }

  /* Submit the 6-digit TOTP code. Returns { ok:true } | { error }. */
  async function mfaVerify (code) {
    const tok = await csrf()
    const body = new URLSearchParams({ csrf: tok, code })
    let res
    try {
      res = await fetch(base + '/login/mfa', {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body
      })
    } catch (err) {
      return { error: 'Could not reach the server.' }
    }
    if (!res.redirected && res.status === 200) {
      const html = await res.text()
      return { error: extractFlash(html) || 'Invalid authentication code. Try again.' }
    }
    if (res.redirected && (res.url || '').indexOf('/login/mfa') === -1) return { ok: true }
    return { error: 'Invalid authentication code. Try again.' }
  }

  function extractFlash (html) {
    const m = html.match(/text-red-400[^>]*>([^<]+)</)
    return m ? m[1].trim() : ''
  }

  /* Absolute URL helper: prefix server-relative paths (avatars, uploads, gifs). */
  function abs (url) {
    if (!url) return ''
    if (/^(https?:)?\/\//.test(url) || /^data:/.test(url)) return url
    return base + url
  }

  return { origin, init, get, getJson, postForm, upload, login, mfaVerify, csrf, resetCsrf, abs }
})()

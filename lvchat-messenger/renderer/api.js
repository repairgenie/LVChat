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

/* LVChat Messenger API client.
 *
 * Talks to an LVChat server over HTTPS (or local HTTP). Auth uses the chat
 * session's bearer token: after logging in the token is stored in localStorage
 * and sent as `X-LVC-Session` on every request, so the messenger works even
 * when third-party cookies are blocked (mobile Safari). The browser's session
 * cookies are still sent (credentials: 'include') for backward compatibility
 * and for resources the token doesn't cover. POSTs are form-encoded; image
 * uploads are multipart (triggers the backend's OPTIONS preflight handler).
 */
window.LvApi = (() => {
  let base = ''
  let csrfToken = ''
  let sessionToken = ''
  let mfaTicket = null

  function origin () { return base }

  function tokenKey () { return 'lvcmsg.token.' + base }

  function loadToken () {
    try { sessionToken = localStorage.getItem(tokenKey()) || '' } catch (err) { sessionToken = '' }
  }

  function persistToken () {
    try {
      if (sessionToken) localStorage.setItem(tokenKey(), sessionToken)
      else localStorage.removeItem(tokenKey())
    } catch (err) { /* ignore */ }
  }

  function init (serverOrigin) {
    base = String(serverOrigin || '').replace(/\/+$/, '')
    csrfToken = ''
    mfaTicket = null
    loadToken()
  }

  /* Drop the cached CSRF token (e.g. after a session wipe / expiry). */
  function resetCsrf () {
    csrfToken = ''
  }

  /* Clear the stored bearer token (logout / session revoked). */
  function clearToken () {
    sessionToken = ''
    mfaTicket = null
    persistToken()
  }

  function headers (extra) {
    const h = Object.assign({}, extra)
    if (sessionToken) h['X-LVC-Session'] = sessionToken
    return h
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
        headers: headers({ 'content-type': 'application/x-www-form-urlencoded' }),
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
        headers: headers(),
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

  /* Attempt a password login. Returns { ok:true } | { mfa:true } | { mfaSetup:true } | { error }.
   * Uses the token endpoint, so it works even when third-party cookies (and
   * therefore a cookie-bound CSRF token) are unavailable on mobile. */
  async function login (username, password) {
    mfaTicket = null
    let res
    try {
      res = await fetch(base + '/api/messenger/login', {
        method: 'POST',
        credentials: 'include',
        headers: headers({ 'content-type': 'application/x-www-form-urlencoded', 'X-Messenger': '1' }),
        body: new URLSearchParams({ username, password })
      })
    } catch (err) {
      return { error: 'Could not reach the server. ' + String(err && err.message || '') }
    }
    let j = null
    try { j = await res.json() } catch (err) { /* non-JSON */ }
    if (j && j.mfa) {
      mfaTicket = String(j.ticket || '')
      return { mfa: true }
    }
    if (j && j.mfa_setup) {
      return { mfaSetup: true, error: j.error || 'Two-factor authentication must be set up for this account.' }
    }
    if (res.status === 200 && j && j.ok && j.token) {
      sessionToken = String(j.token)
      persistToken()
      return { ok: true }
    }
    return { error: (j && j.error) || 'Login failed. Check your credentials.' }
  }

  /* Submit the 6-digit TOTP code to complete a token login. */
  async function mfaVerify (code) {
    const ticket = mfaTicket
    if (!ticket) return { error: 'That login has expired. Try signing in again.' }
    let res
    try {
      res = await fetch(base + '/api/messenger/mfa', {
        method: 'POST',
        credentials: 'include',
        headers: headers({ 'content-type': 'application/x-www-form-urlencoded', 'X-Messenger': '1' }),
        body: new URLSearchParams({ ticket, code })
      })
    } catch (err) {
      return { error: 'Could not reach the server.' }
    }
    let j = null
    try { j = await res.json() } catch (err) { /* non-JSON */ }
    if (res.status === 200 && j && j.ok && j.token) {
      mfaTicket = null
      sessionToken = String(j.token)
      persistToken()
      return { ok: true }
    }
    return { error: (j && j.error) || 'Invalid authentication code. Try again.' }
  }

  /* Revoke the messenger's bearer session on the server and forget it locally. */
  async function logout () {
    const had = !!sessionToken
    if (had) {
      try {
        await fetch(base + '/api/messenger/logout', {
          method: 'POST',
          credentials: 'include',
          headers: headers({ 'content-type': 'application/x-www-form-urlencoded' })
        })
      } catch (err) { /* best-effort */ }
    }
    clearToken()
    return { ok: true }
  }

  /* Absolute URL helper: prefix server-relative paths (avatars, uploads, gifs). */
  function abs (url) {
    if (!url) return ''
    if (/^(https?:)?\/\//.test(url) || /^data:/.test(url)) return url
    return base + url
  }

  return { origin, init, get, getJson, postForm, upload, login, mfaVerify, csrf, resetCsrf, clearToken, logout, abs }
})()

const { app, safeStorage } = require('electron')
const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

const DEFAULT_URL = 'https://chat.lasvegasbestinternet.com'
const PROBE_TIMEOUT = 8000

function storePath () {
  return path.join(app.getPath('userData'), 'profiles.json')
}

function credsPath () {
  return path.join(app.getPath('userData'), 'credentials.bin')
}

let cache = null
let credsCache = null

function load () {
  if (cache) return cache
  let data = { version: 2, defaultUrl: DEFAULT_URL, profiles: [] }
  try {
    const raw = fs.readFileSync(storePath(), 'utf8')
    data = JSON.parse(raw)
  } catch (err) {
    if (err.code !== 'ENOENT') console.warn('profiles store read failed:', err.message)
  }
  if (!Array.isArray(data.profiles)) data.profiles = []
  if (!data.defaultUrl) data.defaultUrl = DEFAULT_URL
  data.profiles = data.profiles.filter((p) => p && typeof p === 'object')
  cache = data
  return data
}

function persist (data) {
  try {
    fs.mkdirSync(path.dirname(storePath()), { recursive: true })
    fs.writeFileSync(storePath(), JSON.stringify(data, null, 2), 'utf8')
  } catch (err) {
    console.warn('profiles store write failed:', err.message)
  }
}

function loadCreds () {
  if (credsCache) return credsCache
  let data = {}
  try {
    const raw = fs.readFileSync(credsPath())
    data = JSON.parse(raw.toString('utf8'))
  } catch (err) {
    if (err.code !== 'ENOENT') console.warn('credentials store read failed:', err.message)
  }
  credsCache = data
  return data
}

function persistCreds (data) {
  try {
    fs.mkdirSync(path.dirname(credsPath()), { recursive: true })
    fs.writeFileSync(credsPath(), Buffer.from(JSON.stringify(data), 'utf8'), 'utf8')
  } catch (err) {
    console.warn('credentials store write failed:', err.message)
  }
}

function normalizeUrl (input) {
  let url = String(input || '').trim()
  if (!url) return null
  const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url)
  if (scheme && !/^https?$/i.test(scheme[1])) return null
  if (!/^https?:\/\//i.test(url)) url = 'https://' + url
  let parsed
  try {
    parsed = new URL(url)
  } catch (err) {
    return null
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null
  if (!parsed.hostname) return null
  parsed.hash = ''
  parsed.pathname = parsed.pathname.replace(/\/+$/, '')
  return parsed.toString()
}

function stripTrailingSlash (url) {
  return String(url).replace(/\/+$/, '')
}

function canonicalKey (url) {
  return stripTrailingSlash(normalizeUrl(url) || url).toLowerCase()
}

function probeServer (url) {
  const base = normalizeUrl(url)
  if (!base) return Promise.resolve({ ok: false, error: 'Invalid URL' })
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT)
  return fetch(new URL('/api/version', base).toString(), { signal: controller.signal })
    .then(async (res) => {
      if (!res.ok) throw new Error('Server responded ' + res.status)
      const ct = (res.headers.get('content-type') || '')
      if (!ct.includes('application/json')) throw new Error('Not an LVChat server')
      const body = await res.json()
      if (!body || typeof body.version !== 'string' || typeof body.site !== 'string') {
        throw new Error('Not an LVChat server')
      }
      return { ok: true, url: base, version: body.version, site: body.site }
    })
    .catch((err) => {
      if (err && err.name === 'AbortError') return { ok: false, error: 'Server did not respond in time' }
      return { ok: false, error: err.message || 'Could not reach server' }
    })
    .finally(() => clearTimeout(timer))
}

function storageAvailable () {
  try {
    return safeStorage.isEncryptionAvailable()
  } catch (err) {
    return false
  }
}

function list () {
  return load().profiles
}

function find (id) {
  return load().profiles.find((p) => p.id === id) || null
}

function add ({ name, url, username, autoConnect, siteName }) {
  const normalized = normalizeUrl(url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }
  const data = load()
  const key = canonicalKey(normalized)
  if (data.profiles.some((p) => canonicalKey(p.url) === key)) {
    return { ok: false, error: 'That server is already in your list.' }
  }
  const profile = {
    id: crypto.randomUUID(),
    name: (name || '').trim() || stripTrailingSlash(normalized),
    url: normalized,
    siteName: (siteName || '').trim() || null,
    username: (username || '').trim() || null,
    autoConnect: !!autoConnect,
    createdAt: new Date().toISOString(),
    lastConnectedAt: null
  }
  data.profiles.push(profile)
  persist(data)
  return { ok: true, profile }
}

function update (id, { name, url, username, autoConnect, siteName }) {
  const data = load()
  const profile = data.profiles.find((p) => p.id === id)
  if (!profile) return { ok: false, error: 'Not found' }
  const normalized = url ? normalizeUrl(url) : profile.url
  if (!normalized) return { ok: false, error: 'Invalid URL' }
  if (url) {
    const key = canonicalKey(normalized)
    if (data.profiles.some((p) => p.id !== id && canonicalKey(p.url) === key)) {
      return { ok: false, error: 'That server is already in your list.' }
    }
    profile.url = normalized
  }
  if (typeof name === 'string') profile.name = name.trim() || stripTrailingSlash(profile.url)
  if (typeof siteName === 'string') profile.siteName = siteName.trim() || null
  if (typeof username === 'string') profile.username = username.trim() || null
  if (typeof autoConnect === 'boolean') profile.autoConnect = autoConnect
  persist(data)
  return { ok: true, profile }
}

function remove (id) {
  const data = load()
  const before = data.profiles.length
  data.profiles = data.profiles.filter((p) => p.id !== id)
  persist(data)
  deleteCredentials(id)
  return { ok: true, removed: data.profiles.length !== before }
}

function touch (id) {
  const data = load()
  const profile = data.profiles.find((p) => p.id === id)
  if (!profile) return
  profile.lastConnectedAt = new Date().toISOString()
  persist(data)
}

function setCredentials (id, username, password) {
  const profile = find(id)
  if (!profile) return { ok: false, error: 'Not found' }
  const data = load()
  if (typeof username === 'string') {
    profile.username = username.trim() || null
    persist(data)
  }
  if (password !== undefined && password !== null) {
    if (!storageAvailable()) {
      return { ok: false, error: 'System keychain unavailable; password not stored.', storedPassword: false }
    }
    const creds = loadCreds()
    const enc = safeStorage.encryptString(String(password))
    creds[id] = enc.toString('base64')
    persistCreds(creds)
    credsCache = creds
  }
  return { ok: true, storedPassword: !!password && storageAvailable() }
}

function getCredentials (id) {
  const profile = find(id)
  if (!profile) return null
  const password = (() => {
    if (!storageAvailable()) return null
    try {
      const creds = loadCreds()
      const enc = creds[id]
      if (!enc) return null
      return safeStorage.decryptString(Buffer.from(enc, 'base64'))
    } catch (err) {
      console.warn('credential decrypt failed:', err.message)
      return null
    }
  })()
  return { username: profile.username || null, password, hasPassword: !!password }
}

function deleteCredentials (id) {
  const creds = loadCreds()
  if (!(id in creds)) return
  delete creds[id]
  persistCreds(creds)
  credsCache = creds
}

function getDefaultUrl () {
  return load().defaultUrl
}

function partitionFor (id) {
  return 'persist:lvchat-profile-' + id
}

module.exports = {
  DEFAULT_URL,
  getDefaultUrl,
  storageAvailable,
  list,
  find,
  add,
  update,
  remove,
  touch,
  setCredentials,
  getCredentials,
  deleteCredentials,
  probeServer,
  normalizeUrl,
  canonicalKey,
  partitionFor
}

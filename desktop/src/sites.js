const { app } = require('electron')
const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

const DEFAULT_URL = 'https://chat.lasvegasbestinternet.com'

function storePath () {
  return path.join(app.getPath('userData'), 'sites.json')
}

let cache = null

function load () {
  if (cache) return cache
  let data = { version: 1, defaultUrl: DEFAULT_URL, sites: [] }
  try {
    const raw = fs.readFileSync(storePath(), 'utf8')
    data = JSON.parse(raw)
  } catch (err) {
    if (err.code !== 'ENOENT') console.warn('sites store read failed:', err.message)
  }
  if (!Array.isArray(data.sites)) data.sites = []
  if (!data.defaultUrl) data.defaultUrl = DEFAULT_URL
  if (data.sites.length === 0) {
    data.sites = [{ id: crypto.randomUUID(), name: 'LVChat', url: DEFAULT_URL, createdAt: new Date().toISOString() }]
    persist(data)
  }
  cache = data
  return data
}

function persist (data) {
  try {
    fs.mkdirSync(path.dirname(storePath()), { recursive: true })
    fs.writeFileSync(storePath(), JSON.stringify(data, null, 2), 'utf8')
  } catch (err) {
    console.warn('sites store write failed:', err.message)
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
  return parsed.toString()
}

function list () {
  return load().sites
}

function add ({ name, url }) {
  const normalized = normalizeUrl(url)
  if (!normalized) return { ok: false, error: 'Invalid URL' }
  const data = load()
  const site = {
    id: crypto.randomUUID(),
    name: (name || normalized).trim() || normalized,
    url: normalized,
    createdAt: new Date().toISOString()
  }
  data.sites.push(site)
  persist(data)
  return { ok: true, site }
}

function update (id, { name, url }) {
  const data = load()
  const site = data.sites.find((s) => s.id === id)
  if (!site) return { ok: false, error: 'Not found' }
  const normalized = url ? normalizeUrl(url) : site.url
  if (!normalized) return { ok: false, error: 'Invalid URL' }
  site.name = (name || '').trim() || normalized
  site.url = normalized
  persist(data)
  return { ok: true, site }
}

function remove (id) {
  const data = load()
  const before = data.sites.length
  data.sites = data.sites.filter((s) => s.id !== id)
  persist(data)
  return { ok: true, removed: data.sites.length !== before }
}

function getDefaultUrl () {
  return load().defaultUrl
}

module.exports = { DEFAULT_URL, getDefaultUrl, list, add, update, remove, normalizeUrl }

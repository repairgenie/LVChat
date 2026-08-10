#!/usr/bin/env node
'use strict'

/* LVChat Messenger Web — static build.
 *
 * Reads .env (single-site config), injects the values into config.js, the PWA
 * manifest and the service worker, and emits a fully static dist/ that can be
 * dropped on any static host (nginx, Caddy, S3, GitHub Pages…).
 *
 *   node build.js            # build dist/ from ./.env
 *   node build.js --env=…    # build from a specific env file (used by tests)
 *   node build.js --clean    # wipe dist/ first
 */

const fs = require('fs')
const path = require('path')

const ROOT = __dirname
const SRC = path.join(ROOT, 'src')
const OUT = path.join(ROOT, 'dist')

const DEFAULTS = {
  APP_NAME: 'LVChat Messenger',
  APP_DESCRIPTION: 'IM-first client for LVChat — friends, direct messages and rooms.',
  APP_THEME_COLOR: '#26283c',
  APP_BACKGROUND_COLOR: '#1a1a24'
}

function fail (msg) {
  if (require.main === module) {
    console.error('[messenger-web] ' + msg)
    process.exit(1)
  }
  throw new Error(msg)
}

/* Parse a .env file: KEY=VALUE lines, blank lines and # comments ignored,
 * values may be single/double quoted (quotes stripped). No interpolation. */
function parseEnv (text) {
  const out = {}
  for (const rawLine of String(text || '').split(/\r?\n/)) {
    const line = rawLine.trim()
    if (!line || line.startsWith('#')) continue
    const eq = line.indexOf('=')
    if (eq === -1) continue
    const key = line.slice(0, eq).trim()
    let value = line.slice(eq + 1).trim()
    if (value.length >= 2 && ((value[0] === '"' && value[value.length - 1] === '"') || (value[0] === "'" && value[value.length - 1] === "'"))) {
      value = value.slice(1, -1)
    }
    if (key) out[key] = value
  }
  return out
}

/* Resolve + validate the effective config from an env file. */
function loadConfig (envFile) {
  let envText = ''
  if (fs.existsSync(envFile)) envText = fs.readFileSync(envFile, 'utf8')
  else if (envFile !== path.join(ROOT, '.env')) {
    fail('env file not found: ' + envFile)
  }
  const env = parseEnv(envText)

  const serverUrl = (env.LVCHAT_SERVER_URL || '').trim()
  if (!serverUrl) {
    fail('LVCHAT_SERVER_URL is not set. Copy .env.example to .env and set the LVChat server this client connects to.')
  }
  let parsed
  try {
    parsed = new URL(serverUrl)
  } catch (err) {
    fail('LVCHAT_SERVER_URL is not a valid URL: ' + serverUrl)
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    fail('LVCHAT_SERVER_URL must be http:// or https:// (got ' + parsed.protocol + ').')
  }

  const name = (env.APP_NAME || DEFAULTS.APP_NAME).trim()
  return {
    serverUrl: parsed.origin, // normalize (strip paths)
    appName: name,
    shortName: (env.APP_SHORT_NAME || 'LVChat').trim().slice(0, 12),
    description: (env.APP_DESCRIPTION || DEFAULTS.APP_DESCRIPTION).trim(),
    themeColor: (env.APP_THEME_COLOR || DEFAULTS.APP_THEME_COLOR).trim(),
    backgroundColor: (env.APP_BACKGROUND_COLOR || DEFAULTS.APP_BACKGROUND_COLOR).trim(),
    version: (env.APP_VERSION || '1.0.0').trim()
  }
}

/* Simple content hash so the service-worker cache version only changes when
 * the source files actually change (idempotent rebuilds). */
function contentHash (files) {
  let h = 5381
  for (const f of files) {
    const data = fs.readFileSync(f)
    for (let i = 0; i < data.length; i++) h = ((h * 33) ^ data[i]) >>> 0
    const m = String(f).length
    h = ((h * 33) ^ m) >>> 0
  }
  return 'v' + h.toString(36)
}

function copy (src, dest) {
  fs.copyFileSync(src, dest)
}

function write (file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true })
  fs.writeFileSync(file, data)
}

function build (opts) {
  const envFile = opts.env || path.join(ROOT, '.env')
  const config = loadConfig(envFile)

  if (opts.clean && fs.existsSync(OUT)) fs.rmSync(OUT, { recursive: true })
  fs.mkdirSync(OUT, { recursive: true })

  // Generated, versioned client config.
  const configJs = 'window.LVCHAT_CONFIG = ' + JSON.stringify({
    serverUrl: config.serverUrl,
    appName: config.appName,
    shortName: config.shortName,
    description: config.description,
    themeColor: config.themeColor,
    backgroundColor: config.backgroundColor,
    version: config.version
  }, null, 2) + ';\n'
  write(path.join(OUT, 'config.js'), configJs)

  // PWA manifest.
  const manifest = JSON.parse(fs.readFileSync(path.join(SRC, 'manifest.template.json'), 'utf8'))
  manifest.name = config.appName
  manifest.short_name = config.shortName
  manifest.description = config.description
  manifest.theme_color = config.themeColor
  manifest.background_color = config.backgroundColor
  write(path.join(OUT, 'manifest.webmanifest'), JSON.stringify(manifest, null, 2))

  // Service worker (cache version tied to the source content).
  const precache = [
    './',
    './index.html',
    './messenger.html',
    './messenger.css',
    './messenger.js',
    './api.js',
    './emoji.js',
    './web-bridge.js',
    './config.js',
    './manifest.webmanifest',
    './offline.html',
    './icons/icon-192.png',
    './icons/icon-512.png'
  ]
  const cacheVersion = contentHash([
    path.join(SRC, 'messenger.html'),
    path.join(SRC, 'messenger.css'),
    path.join(SRC, 'messenger.js'),
    path.join(SRC, 'api.js'),
    path.join(SRC, 'emoji.js'),
    path.join(SRC, 'web-bridge.js'),
    path.join(SRC, 'sw.template.js')
  ])
  const sw = fs.readFileSync(path.join(SRC, 'sw.template.js'), 'utf8')
    .replace(/__CACHE_VERSION__/g, cacheVersion)
    .replace(/__PRECACHE__/g, JSON.stringify(precache))
  write(path.join(OUT, 'sw.js'), sw)

  // Static assets.
  for (const f of ['index.html', 'messenger.html', 'messenger.css', 'messenger.js', 'api.js', 'emoji.js', 'web-bridge.js', 'offline.html']) {
    copy(path.join(SRC, f), path.join(OUT, f))
  }
  fs.mkdirSync(path.join(OUT, 'icons'), { recursive: true })
  for (const f of fs.readdirSync(path.join(SRC, 'icons'))) {
    copy(path.join(SRC, 'icons', f), path.join(OUT, 'icons', f))
  }

  // Build-info for humans + tests.
  write(path.join(OUT, 'build-info.json'), JSON.stringify({
    builtAt: new Date().toISOString(),
    cacheVersion,
    config
  }, null, 2))

  console.log('[messenger-web] built dist/')
  console.log('[messenger-web]   client -> ' + config.appName + ' (' + config.serverUrl + ')')
  console.log('[messenger-web]   sw cache ' + cacheVersion + ' — ' + precache.length + ' precached files')
}

if (require.main === module) {
  const args = process.argv.slice(2)
  const opts = { clean: false, env: path.join(ROOT, '.env') }
  for (const a of args) {
    if (a === '--clean') opts.clean = true
    else if (a.startsWith('--env=')) opts.env = path.join(ROOT, a.slice('--env='.length))
    else if (a.startsWith('--env ')) opts.env = path.join(ROOT, a.slice('--env '.length).trim())
    else if (a === '--help') {
      console.log('usage: node build.js [--clean] [--env=<file>]')
      process.exit(0)
    }
  }
  build(opts)
}

module.exports = { build, parseEnv, loadConfig }

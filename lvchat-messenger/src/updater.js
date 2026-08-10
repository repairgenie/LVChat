// Update checking for LVChat Messenger.
//
// The app self-updates through electron-updater (generic provider) against the
// default upstream feed, which is embedded at build time via the `publish.url`
// in package.json. White-labelled community builds change that URL at build
// time — that's the supported way to ship a tailored build.
//
// A connected server MAY advertise an update feed (`updater_url` from
// GET /api/version). The app never auto-updates from an arbitrary server unless
// the user explicitly opts in per profile ("Use this server's updates"), which
// stores `useServerUpdates` on the profile. This keeps a malicious/compromised
// server from pushing code onto stock installs.
//
// The version math and feed parsing below are pure and unit-testable without
// Electron (tests/e2e.js requires this module directly).

const { app, dialog } = require('electron')
const { autoUpdater } = require('electron-updater')

const DEFAULT_FEED_URL = 'https://updates.lasvegasbestinternet.com/messenger'

// The path segment electron-updater's generic feed lives under (e.g. /desktop,
// /messenger). Server feed opt-ins get this same suffix appended.
const FEED_APP_PATH = (() => {
  try {
    return '/' + new URL(DEFAULT_FEED_URL).pathname.split('/').filter(Boolean).pop()
  } catch (err) {
    return '/messenger'
  }
})()

// Mirror the package.json `build.publish[0].url` (electron-builder writes this
// into app-update.yml at package time; this is the dev/default value).
const PACKAGE_FEED_URL = (() => {
  try {
    const pkg = require('../package.json')
    const pub = (pkg.build && pkg.build.publish) || []
    const generic = (Array.isArray(pub) ? pub : [pub]).find((p) => p && p.provider === 'generic')
    return generic && generic.url ? generic.url : DEFAULT_FEED_URL
  } catch (err) {
    return DEFAULT_FEED_URL
  }
})()

/** Numeric-dotted version compare; returns -1/0/1. Falls back to string order. */
function compareVersions (a, b) {
  const va = String(a || '').trim()
  const vb = String(b || '').trim()
  if (va === vb) return 0
  const na = /^\d+(\.\d+){0,3}$/.test(va)
  const nb = /^\d+(\.\d+){0,3}$/.test(vb)
  if (na && nb) {
    const pa = va.split('.').map(Number)
    const pb = vb.split('.').map(Number)
    const len = Math.max(pa.length, pb.length)
    for (let i = 0; i < len; i++) {
      const d = (pa[i] || 0) - (pb[i] || 0)
      if (d !== 0) return d > 0 ? 1 : -1
    }
    return 0
  }
  return va < vb ? -1 : va > vb ? 1 : 0
}

/** Extract the `version:` field from an electron-updater latest*.yml body. */
function parseFeedVersion (ymlText) {
  const m = /(?:^|\n)\s*version:\s*["']?([^"'\s]+)/.exec(String(ymlText || ''))
  return m ? m[1] : ''
}

/** Which feed file electron-updater reads on this platform. */
function feedFileName (platform) {
  switch (platform) {
    case 'darwin': return 'latest-mac.yml'
    case 'linux': return 'latest-linux.yml'
    default: return 'latest.yml' // win32 + everything else
  }
}

/**
 * Fetch + parse a feed purely (used by tests and as a fallback for status
 * reporting when electron-updater's event flow isn't available).
 * @param {string} feedBase e.g. https://updates.example.com/desktop
 * @param {string} platform process.platform
 * @param {Function} fetchFn (url) => Promise<string> default global fetch
 */
async function readFeed (feedBase, platform, fetchFn) {
  const fetcher = fetchFn || (async (url) => {
    const res = await fetch(url, { redirect: 'follow', signal: AbortSignal.timeout(8000) })
    if (!res.ok) throw new Error('Feed responded ' + res.status)
    return res.text()
  })
  const url = String(feedBase).replace(/\/+$/, '') + '/' + feedFileName(platform)
  const body = await fetcher(url)
  return { url, version: parseFeedVersion(body), raw: body }
}

/**
 * True when the feed advertises a newer version than the running app.
 * Pure: readFeed + compareVersions in one step.
 */
async function isUpdateAvailable (feedBase, currentVersion, platform, fetchFn) {
  const feed = await readFeed(feedBase, platform, fetchFn)
  return compareVersions(feed.version, currentVersion) > 0
}

/**
 * Resolve the feed URL the app should check, honouring per-profile opt-in.
 * @param {Array} profiles profile list (with serverUpdaterUrl / useServerUpdates)
 * @returns {string}
 */
function resolveFeedUrl (profiles) {
  const overrides = (profiles || [])
    .filter((p) => p && p.useServerUpdates && p.serverUpdaterUrl)
    .sort((a, b) => String(b.lastConnectedAt || '').localeCompare(String(a.lastConnectedAt || '')))
  if (overrides.length > 0) {
    return String(overrides[0].serverUpdaterUrl).replace(/\/+$/, '') + FEED_APP_PATH
  }
  return PACKAGE_FEED_URL || DEFAULT_FEED_URL
}

/**
 * Wire electron-updater's autoUpdater into the app.
 *
 * @param {Object} opts
 * @param {string} opts.currentVersion app.getVersion()
 * @param {Function} opts.feedUrl () => string  — resolves the current feed
 * @param {Function} opts.onStatus (status) => void — status changes:
 *        { state: 'checking' } | { state: 'uptodate' } | { state: 'available', version }
 *        | { state: 'downloading', percent } | { state: 'downloaded', version }
 *        | { state: 'error', message }
 * @returns {Object} { checkNow, quitAndInstall, getState }
 */
function createUpdater (opts) {
  const current = String(opts.currentVersion || '')
  let state = { state: 'idle' }
  let quiet = false

  const emit = (s) => {
    state = s
    if (opts.onStatus) opts.onStatus(s)
  }

  const readyForUpdate = async (feed) => {
    if (feed) autoUpdater.setFeedURL({ provider: 'generic', url: feed })
    return autoUpdater.checkForUpdates()
  }

  autoUpdater.autoDownload = false // we prompt before downloading
  autoUpdater.on('checking-for-update', () => emit({ state: 'checking' }))
  autoUpdater.on('update-available', (info) => {
    emit({ state: 'available', version: info && info.version })
    if (quiet) return
    const v = (info && info.version) || 'newer version'
    dialog.showMessageBox({
      type: 'info',
      title: 'Update available',
      message: `LVChat Desktop v${v} is available`,
      detail: 'Download it now and restart to install?',
      buttons: ['Download', 'Later'],
      defaultId: 0,
      cancelId: 1
    }).then(({ response }) => {
      if (response === 0) {
        emit({ state: 'downloading', percent: 0 })
        autoUpdater.downloadUpdate()
      }
    }).catch(() => {})
  })
  autoUpdater.on('update-not-available', () => emit({ state: 'uptodate' }))
  autoUpdater.on('download-progress', (p) => emit({ state: 'downloading', percent: Math.round(p.percent || 0) }))
  autoUpdater.on('update-downloaded', (info) => {
    emit({ state: 'downloaded', version: info && info.version })
    if (quiet) return
    dialog.showMessageBox({
      type: 'info',
      title: 'Update ready',
      message: `LVChat Desktop v${info && info.version} is downloaded`,
      detail: 'Quit and install now, or later?',
      buttons: ['Restart now', 'Later'],
      defaultId: 0,
      cancelId: 1
    }).then(({ response }) => {
      if (response === 0) autoUpdater.quitAndInstall()
    }).catch(() => {})
  })
  autoUpdater.on('error', (err) => emit({ state: 'error', message: (err && err.message) || 'Update error' }))

  return {
    getState: () => state,
    currentVersion: current,
    feedUrl: () => opts.feedUrl(),
    /** @param {{quiet?: boolean}} [settings] quiet suppresses the prompts. */
    checkNow: (settings) => {
      quiet = !!(settings && settings.quiet)
      emit({ state: 'checking' })
      try {
        return readyForUpdate(opts.feedUrl())
      } catch (err) {
        emit({ state: 'error', message: (err && err.message) || 'Update check failed' })
        return Promise.resolve(null)
      }
    },
    quitAndInstall: () => { try { autoUpdater.quitAndInstall() } catch (err) {} }
  }
}

module.exports = {
  DEFAULT_FEED_URL,
  PACKAGE_FEED_URL,
  compareVersions,
  parseFeedVersion,
  feedFileName,
  readFeed,
  isUpdateAvailable,
  resolveFeedUrl,
  createUpdater
}

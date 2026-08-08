/* Persistent tray diagnostic: boots the real messenger main.js with a seeded
 * profile so the tray menu includes the profile submenu, and stays alive so
 * we can inspect it over D-Bus and with real clicks. Also asserts the tray
 * icon resolves to a non-empty image and ships in packaged builds. */
const { app } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-msg-tray-'))
app.setPath('userData', tmp)
fs.writeFileSync(path.join(tmp, 'profiles.json'), JSON.stringify({
  version: 2,
  defaultUrl: 'http://127.0.0.1:1',
  profiles: [{
    id: 'tray-test-profile',
    name: 'Test Server',
    url: 'http://127.0.0.1:1',
    username: 'alice',
    autoConnect: false,
    lastConnectedAt: new Date().toISOString()
  }]
}), 'utf8')

const main = require('../src/main')

let failures = 0
const check = (name, cond, extra) => {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

const img = main.trayImage()
check('trayImage() returns a non-empty icon', img && !img.isEmpty(), img && img.isEmpty() ? 'image is empty' : '')

const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8'))
check('package.json build.files ships build/icon.png', Array.isArray(pkg.build.files) && pkg.build.files.includes('build/icon.png'), JSON.stringify(pkg.build.files))

setTimeout(() => {
  if (failures === 0) console.log('ALL TESTS PASSED')
  else console.log('TEST(S) FAILED: ' + failures)
  process.exit(failures === 0 ? 0 : 1)
}, 3000)

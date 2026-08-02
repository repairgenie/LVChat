const { app, BrowserWindow } = require('electron')
const fs = require('fs')
const os = require('os')
const path = require('path')
const http = require('http')

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lvchat-desktop-test-'))
app.setPath('userData', tmp)

const { chatWindows, sameSite } = require('../src/main')

let failures = 0
function check (name, cond, extra) {
  if (cond) console.log('PASS  ' + name)
  else { failures++; console.log('FAIL  ' + name + (extra ? '  -> ' + extra : '')) }
}

function launcher () {
  return BrowserWindow.getAllWindows().find((w) => !w.webContents.isDestroyed() &&
    w.webContents.getURL().includes('launcher.html'))
}

function waitLauncher () {
  return new Promise((resolve) => {
    const tryGet = () => {
      const w = launcher()
      if (w && !w.webContents.isLoading()) resolve(w)
      else setTimeout(tryGet, 50)
    }
    tryGet()
  })
}

function js (win, code) {
  return Promise.race([
    win.webContents.executeJavaScript(code, true),
    new Promise((resolve) => setTimeout(() => resolve({ __timeout: true }), 30000))
  ])
}

async function main () {
  const server = http.createServer((req, res) => {
    res.writeHead(200, { 'content-type': 'text/html' })
    res.end('<title>test page</title><h1>ok</h1>')
  })
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const port = server.address().port
  const urlA = `http://127.0.0.1:${port}/a`
  const urlB = `http://127.0.0.1:${port}/b`

  const win = await waitLauncher()
  check('launcher window created', !!win)

  const info = await js(win, 'window.siteAPI.listSites()')
  check('default site seeded', info.sites.length === 1 && info.sites[0].url.includes('lasvegasbestinternet.com'), JSON.stringify(info))
  check('defaultUrl exposed', info.defaultUrl.replace(/\/$/, '') === 'https://chat.lasvegasbestinternet.com')

  const add = await js(win, `window.siteAPI.addSite({ name: 'Example', url: 'example.com' })`)
  check('add site (bare domain normalised)', add.ok && add.site.url === 'https://example.com/', JSON.stringify(add))

  const bad = await js(win, `window.siteAPI.addSite({ name: 'Bad', url: 'ftp://x.com' })`)
  check('reject non-http scheme', bad.ok === false, JSON.stringify(bad))

  const upd = await js(win, `window.siteAPI.updateSite({ id: '${add.site.id}', name: 'Example 2', url: 'https://example.org' })`)
  check('update site', upd.ok && upd.site.name === 'Example 2' && upd.site.url === 'https://example.org/')

  const open1 = await js(win, `window.siteAPI.openSite({ url: '${urlA}', name: 'Window A' })`)
  const open2 = await js(win, `window.siteAPI.openSite({ url: '${urlB}', name: 'Window B' })`)
  check('open first chat window', open1.ok)
  check('open second chat window (concurrent profile)', open2.ok)
  check('two windows tracked', chatWindows.size === 2, String(chatWindows.size))

  const parts = [...chatWindows.values()].map((r) => r.partition)
  check('windows use distinct isolated sessions', parts.length === 2 && new Set(parts).size === 2, JSON.stringify(parts))

  const dup = await js(win, `window.siteAPI.openSite({ url: '${urlA}', name: 'Window A copy' })`)
  check('same site can open a second isolated window', dup.ok && chatWindows.size === 3, String(chatWindows.size))

  const loaded = await new Promise((resolve) => {
    let tries = 0
    const tick = () => {
      tries++
      const allLoaded = [...chatWindows.values()].every((r) => !r.win.isDestroyed() && !r.win.webContents.isLoading())
      if (allLoaded || tries > 200) resolve(allLoaded)
      else setTimeout(tick, 50)
    }
    tick()
  })
  check('chat windows finish loading', loaded)

  const wlist = await js(win, 'window.siteAPI.listWindows()')
  check('windows:list returns running windows', Array.isArray(wlist) && wlist.length === 3, JSON.stringify(wlist))

  const close = await js(win, 'window.siteAPI.closeWindow({ id: 99999 })')
  check('windows:close responds for unknown id', close.ok && !close.__timeout)
  const closeA = await js(win, `window.siteAPI.closeWindow({ id: ${open1.id} })`)
  check('windows:close accepts a real window id', closeA.ok && !closeA.__timeout)

  const rem = await js(win, `window.siteAPI.removeSite({ id: '${add.site.id}' })`)
  check('remove site', rem.ok && rem.removed)

  check('sameSite helper (subdomains)', sameSite('https://chat.lasvegasbestinternet.com', 'https://lasvegasbestinternet.com'))
  check('sameSite helper (foreign rejected)', !sameSite('https://chat.lasvegasbestinternet.com', 'https://evil.com'))

  server.close()

  console.log(failures === 0 ? '\nALL TESTS PASSED' : `\n${failures} TEST(S) FAILED`)
  app.exit(failures === 0 ? 0 : 1)
}

app.whenReady().then(main)

setTimeout(() => {
  console.log(`\nTIMEOUT with ${failures} failure(s) — aborting`)
  app.exit(2)
}, 45000)

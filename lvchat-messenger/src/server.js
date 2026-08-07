const http = require('http')
const fs = require('fs')
const path = require('path')

// Serves the renderer files from a loopback origin (127.0.0.1:<random port>).
// A real http(s) origin — rather than file:// — keeps the messenger same-site
// with a local dev server (SameSite=Lax session cookies still flow), while the
// PHP backend's CORS middleware lets the origin talk to remote HTTPS servers.
const ROOT = path.join(__dirname, '..', 'renderer')

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.json': 'application/json'
}

function createStaticServer () {
  const server = http.createServer((req, res) => {
    let urlPath = decodeURIComponent((req.url || '/').split('?')[0])
    if (urlPath === '/') urlPath = '/index.html'
    const filePath = path.normalize(path.join(ROOT, urlPath))
    if (!filePath.startsWith(ROOT)) {
      res.writeHead(403)
      res.end('forbidden')
      return
    }
    fs.readFile(filePath, (err, data) => {
      if (err) {
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' })
        res.end('not found: ' + urlPath)
        return
      }
      res.writeHead(200, {
        'content-type': MIME[path.extname(filePath)] || 'application/octet-stream',
        'cache-control': 'no-store'
      })
      res.end(data)
    })
  })
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address()
      resolve({ server, port, origin: 'http://127.0.0.1:' + port })
    })
  })
}

module.exports = { createStaticServer }

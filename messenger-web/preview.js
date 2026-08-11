#!/usr/bin/env node
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

'use strict'

/* Minimal zero-dependency static server for the built dist/ folder — handy for
 * local testing. For production, serve dist/ with any static host (nginx,
 * Caddy, S3, GitHub Pages…) over HTTPS. The PWA service worker requires a
 * secure context, and cross-site session cookies need HTTPS on the LVChat
 * server too. */

const fs = require('fs')
const path = require('path')
const http = require('http')

const ROOT = path.join(__dirname, 'dist')
const PORT = Number(process.argv.find((a) => /^\d+$/.test(a))) || Number(process.env.PORT) || 8080

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.webmanifest': 'application/manifest+json',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon'
}

const server = http.createServer((req, res) => {
  let urlPath = decodeURIComponent((req.url || '/').split('?')[0])
  if (urlPath === '/' || urlPath === '/index.html') urlPath = '/messenger.html'
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
    const ext = path.extname(filePath)
    res.writeHead(200, {
      'content-type': MIME[ext] || 'application/octet-stream',
      'cache-control': urlPath === '/sw.js' || urlPath === '/manifest.webmanifest' ? 'no-cache' : 'max-age=3600'
    })
    res.end(data)
  })
})

server.listen(PORT, () => {
  console.log('LVChat Messenger Web preview: http://127.0.0.1:' + PORT + '  (Ctrl+C to stop)')
})

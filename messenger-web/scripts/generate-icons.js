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

/* Generate the messenger-web PWA icon set (src/icons/*.png) from scratch —
 * no fonts, no external assets. A rounded dark square with a white chat
 * bubble (three blurple "..." dots + a tail) and a pink notification dot,
 * matching the LVChat Messenger branding.
 *
 *   node scripts/generate-icons.js
 */

'use strict'

const zlib = require('zlib')
const fs = require('fs')
const path = require('path')

const SS = 3 // supersampling factor for smooth edges

const BG = [38, 40, 60]
const ACCENT = [88, 101, 242]
const WHITE = [255, 255, 255]
const PINK = [235, 69, 158]

function inRoundRect (x, y, x0, y0, w, h, r) {
  const cx = Math.max(x0 + r, Math.min(x, x0 + w - r))
  const cy = Math.max(y0 + r, Math.min(y, y0 + h - r))
  const dx = x - cx
  const dy = y - cy
  return dx * dx + dy * dy <= r * r
}

function inCircle (x, y, cx, cy, r) {
  const dx = x - cx
  const dy = y - cy
  return dx * dx + dy * dy <= r * r
}

function pixel (x, y, fullBleed) {
  const u = x / N
  const v = y / N

  // Maskable icons have no rounded backdrop (the launcher masks them); the
  // regular icons are a rounded square.
  if (!fullBleed && !inRoundRect(u, v, 0.02, 0.02, 0.96, 0.96, 0.22)) return BG

  const bubble = { x0: 0.2, y0: 0.2, w: 0.6, h: 0.42, r: 0.1 }
  const inBubble = inRoundRect(u, v, bubble.x0, bubble.y0, bubble.w, bubble.h, bubble.r)
  const tail = u >= 0.24 && u <= 0.44 && v >= bubble.y0 + bubble.h - 0.02 && v <= bubble.y0 + bubble.h + 0.13 &&
    (v - (bubble.y0 + bubble.h - 0.02)) <= (u - 0.24)

  if (inBubble || tail) {
    if (inCircle(u, v, 0.36, 0.41, 0.05) || inCircle(u, v, 0.50, 0.41, 0.05) || inCircle(u, v, 0.64, 0.41, 0.05)) return ACCENT
    return WHITE
  }

  if (inCircle(u, v, 0.5, 0.78, 0.09)) return PINK
  return BG
}

function crc32 (buf) {
  let c = ~0
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i]
    for (let k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1
  }
  return ~c >>> 0
}

function chunk (type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const t = Buffer.from(type, 'ascii')
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(Buffer.concat([t, data])))
  return Buffer.concat([len, t, data, crc])
}

let N

function render (size, fullBleed) {
  N = size * SS
  const raw = Buffer.alloc(N * N * 3)
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) {
      const [r, g, b] = pixel(x, y, fullBleed)
      const i = (y * N + x) * 3
      raw[i] = r
      raw[i + 1] = g
      raw[i + 2] = b
    }
  }

  const out = Buffer.alloc(size * size * 3)
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      let r = 0; let g = 0; let b = 0
      const count = SS * SS
      for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
          const i = ((y * SS + sy) * N + (x * SS + sx)) * 3
          r += raw[i]
          g += raw[i + 1]
          b += raw[i + 2]
        }
      }
      const i = (y * size + x) * 3
      out[i] = Math.round(r / count)
      out[i + 1] = Math.round(g / count)
      out[i + 2] = Math.round(b / count)
    }
  }

  const scanlines = Buffer.alloc(size * (1 + size * 3))
  for (let y = 0; y < size; y++) {
    scanlines[y * (1 + size * 3)] = 0
    out.copy(scanlines, y * (1 + size * 3) + 1, y * size * 3, (y + 1) * size * 3)
  }

  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(size, 0)
  ihdr.writeUInt32BE(size, 4)
  ihdr[8] = 8
  ihdr[9] = 2

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(scanlines)),
    chunk('IEND', Buffer.alloc(0))
  ])
}

const outDir = path.join(__dirname, '..', 'src', 'icons')
fs.mkdirSync(outDir, { recursive: true })

const targets = [
  { file: 'icon-192.png', size: 192, fullBleed: false },
  { file: 'icon-512.png', size: 512, fullBleed: false },
  { file: 'icon-maskable-512.png', size: 512, fullBleed: true },
  { file: 'apple-touch-icon.png', size: 180, fullBleed: false },
  { file: 'favicon.png', size: 32, fullBleed: false }
]

for (const t of targets) {
  const png = render(t.size, t.fullBleed)
  const p = path.join(outDir, t.file)
  fs.writeFileSync(p, png)
  console.log('wrote', path.relative(path.join(__dirname, '..'), p), png.length, 'bytes')
}

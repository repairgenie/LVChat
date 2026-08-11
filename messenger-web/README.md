# LVChat Messenger Web

A web (PWA) version of the **LVChat Messenger** desktop client. Like the
Electron apps it is a **pure client** — it contains no server-side code and
just signs you into one LVChat server. Instead of a multi-profile launcher,
the site it connects to is configured by the admin in a `.env` file.

It reuses the same IM-first UI as `lvchat-messenger/`: buddy list with custom
contact groups, DMs, joined rooms, the offline send queue, GIF/image posting,
mention autocomplete, per-user mutes, and sound alerts — plus Web Push
notifications when the tab is closed. Rooms also carry the full **Channel
settings** control panel (bans, registered ops & half-ops, topic, Channel URL)
for ops and above, and render a **Channel URL** in a pane above the chat when
one is set.

## Requirements

- Node.js (any recent version) **only for building** — the output is fully
  static and can be hosted anywhere.
- The LVChat server must be **HTTPS**.

## Setup

```bash
cd messenger-web
cp .env.example .env        # then set LVCHAT_SERVER_URL (the site to connect to)
npm install                 # no-op (zero dependencies) — optional
npm run build               # emits dist/ with the config baked in
npm run preview             # serve dist/ locally for testing (http://127.0.0.1:8080)
```

`.env`:

| Key | Default | Purpose |
|---|---|---|
| `LVCHAT_SERVER_URL` | *(required)* | The LVChat server this client signs into |
| `APP_NAME` | `LVChat Messenger` | Branding / PWA name / window title |
| `APP_SHORT_NAME` | `LVChat` | PWA short name (≤12 chars) |
| `APP_THEME_COLOR` | `#26283c` | PWA theme colour (install header) |
| `APP_BACKGROUND_COLOR` | `#1a1a24` | PWA splash background |
| `APP_DESCRIPTION` | *(default)* | PWA description |
| `APP_VERSION` | `1.0.0` | Reported in `build-info.json` / `config.js` |

`npm run build` generates `dist/config.js`, `dist/manifest.webmanifest`,
`dist/sw.js` (cache-version stamped) and copies the client. Re-run it whenever
`.env` changes.

## Allowing the client on the server

The messenger talks to the LVChat server cross-origin using a **bearer session
token** (kept in localStorage, sent as `X-LVC-Session`) instead of the session
cookie, so it signs in and stays signed in even on phones where browsers block
third-party cookies (mobile Safari). Add the origin the messenger will be
served from to the server's allowlist so the API's CORS headers are emitted —
easiest from the admin dashboard:

```bash
# on the LVChat server: Admin → Settings → Web messenger clients → Allowed origins
# e.g. https://msg.example.com
# ...or via env var:
export CHAT_CORS_ORIGINS=https://msg.example.com
```

The built-in loopback origins (`http://127.0.0.1:*`) and `null` (file://) are
already allowed, so local testing works without touching the server.

Both sites must be **HTTPS** (or localhost).

## Deploying

`dist/` is plain static files — upload it to any static host and point it at
HTTPS. Examples:

- **nginx** — serve `dist/` with a document root; make sure `sw.js` and
  `manifest.webmanifest` are served with their real MIME types (nginx defaults
  are fine) and not cached aggressively (`no-cache` for `sw.js`).
- **Any CDN / object store / GitHub Pages** — upload `dist/`.

The service worker precaches the app shell, so an installed messenger launches
instantly and opens even when offline (signing in still needs a connection, and
messages you send while offline are queued and delivered on reconnect).

### Installing

Open the served URL in a browser and use the browser's **Install app**
(Chrome/Edge/Android: address bar or menu; Safari: Share → Add to Home Screen).
The app opens in its own window, and Web Push notifications arrive even when it
is closed.

## Web Push

Closed-tab notifications use the server's existing push endpoints. After sign-in
the client registers `sw.js`, reads the server's VAPID public key from
`GET /api/me`, and subscribes via `POST /api/push/subscribe`. The per-context
toggles (channel / DM / invites) and per-user mutes are shared with the web app
(`/api/push/prefs`, `/api/push/mute`), so preferences carry across surfaces.

Requires the server build that exposes `vapidPublicKey` in `/api/me`
(LVChat 1.7.1+). Older servers simply show the push toggle as unavailable.

## Privacy

Only the app shell is cached by the service worker. Every API call — polling,
sending, history, avatars, uploads — goes straight to the server and is never
served stale or cached per-user. Signing out revokes the bearer session server-side
(`/api/messenger/logout`) and wipes the local shell caches.

The client remembers only your **username** and the opaque **session token** in
localStorage. Passwords are never stored by this app — your browser's own
password manager and the bearer token handle sign-in. The token is a random
64-char session identifier that is revoked on logout and expires after 30 days.

## Layout / files

```
build.js             reads .env → emits dist/
preview.js           zero-dep static server for dist/ (local testing)
src/                 client sources (adapted from lvchat-messenger/renderer)
  messenger.html     PWA head + single-server login view
  messenger.js       the IM client (reused, ~3k lines untouched)
  api.js emoji.js messenger.css   reused as-is
  web-bridge.js      window.msg web implementation (profiles, prefs, notify, push, logout)
  sw.template.js     service worker (shell precache + push handler)
  manifest.template.json  PWA manifest (filled at build time)
  icons/             PWA icon set (regenerate: npm run icons)
scripts/generate-icons.js  draws the icon set from scratch (no assets)
tests/               zero-dependency Node suite (npm test)
```

## Testing

```bash
npm test
```

Runs the build against a scratch `.env`, validates the generated config /
manifest / service worker, exercises `web-bridge.js` against stubbed browser
globals, drives `api.js`'s login → MFA → `/api/me` → push-subscribe flow
against a mock LVChat server, and smoke-tests the preview server.

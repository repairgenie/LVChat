# LVChat — Mumble (Murmur) Voice Integration

> **SUPERSEDED.** This plan was **rejected** — voice ships via the WebRTC/LiveKit
> module instead (`modules/webrtc/`, see [`docs/webrtc-implementation.md`](webrtc-implementation.md)
> and [`docs/protocol/voice.md`](protocol/voice.md)). The Mumble browser path
> needed mumble-web-proxy (a Rust bridge, AGPL-3.0, ~75★/41 commits, WebRTC
> extension "not yet stabilized"); LiveKit lets browsers speak native WebRTC to
> a single Apache-2.0 daemon with no proxy. This document is kept only for the
> historical **MCU-vs-SFU** bandwidth comparison (§Appendix A and
> webrtc-implementation.md §3.1 "the one tradeoff"). None of its §7A per-client
> plans or §9 change tables were applied.

**Status:** ~~Planning / not implemented~~ — **rejected in favor of the WebRTC (LiveKit) module**
**Target app:** LVChat (PHP 8.1+ / SQLite / Workerman) at `chat.lasvegasbestinternet.com`
**Scope:** Voice channels inside LVChat backed by a Mumble **Murmur** server, with max-connection and per-user bandwidth caps managed from **Admin → Settings**, delivered to **all four clients**: web app, desktop, messenger, and messenger-web.

---

## 1. TL;DR

- LVChat gets voice by running a **Murmur** (Mumble server) daemon alongside the existing PHP app + Workerman realtime gateway.
- **Murmur does the audio mixing** (low latency, Opus). LVChat does **auth, authorization, and capacity enforcement**:
  - Admin sets `mumble_max_users` (max concurrent voice connections) in Admin → Settings.
  - The app **gates joins** against that cap (rejects with a friendly error when full) **and** writes the same cap into `murmur.ini` (`users=`), restarting Murmur when it changes — mirroring the existing `ws_port`/gateway-restart pattern.
  - Admin sets a per-client bandwidth cap (`mumble_bandwidth`) that maps to Murmur's `bandwidth=` ini key — this is how we enforce "moderate to minimum quality" and keep the server from being overwhelmed.
- Browser voice works via **mumble-web** (WebAssembly client talking to Murmur over WebSocket). Desktop clients (Electron) can embed a native Mumble client later. **Browsers cannot speak the Mumble protocol natively** — no WebRTC bridge exists in Mumble; the WASM client is the web path.
- **One voice engine, four surfaces:** the server + API stay identical for every client. Web/messenger-web embed mumble-web in an iframe; desktop/messenger open the same mumble-web page in an Electron window (MVP), with native Mumble as a later phase (§7.2). The admin cap (`mumble_max_users`) is enforced server-side, so **every client obeys it automatically** — no per-client enforcement code needed.
- Target: up to **200 concurrent users** at moderate/minimum quality — see §3 for the math. Recommended host: 100 Mbit/s unmetered, 2–4 vCPU, 1–2 GB RAM.

---

## 2. Architecture

```
┌─────────────┐   HTTPS/WSS   ┌──────────────────────────────────┐
│  Browser    │ ◀───────────▶ │  LVChat PHP app                  │
│  (web app)  │               │  - auth (sessions/CSRF)          │
└──────┬──────┘               │  - /api/voice/join (gates cap)   │
       │                      │  - /api/voice/leave|status        │
┌──────┴───────────┐           └───────────────┬──────────────────┘
│  Messenger Web  │ ◀───────── WSS/session ────┘
│  (PWA, iframe)  │            (cross-site, SameSite=None)
└──────┬──────────┘
       │  WebSocket (mumble-web WASM client, served from app origin)
       ▼                       ┌──────────────────────────────────┐
┌─────────────┐   config +    │  Electron Desktop / Messenger     │
│   Murmur    │ ◀─ restart ──▶│  (mumble-web in BrowserWindow or  │
│  (mumble-   │               │   iframe in messenger.html)       │
│   server)   │               └──────────────────────────────────┘
└──────┬──────┘
       │ UDP 64738 (native clients) / WebSocket (browser + WASM)
       ▼
   Audio mixing per channel → each listener gets ONE mixed downlink
```

**One engine, four surfaces:** the web app, messenger-web, desktop, and messenger all call the same `/api/voice/*` endpoints and all render the same mumble-web surface (iframe or Electron window). The cap is enforced in exactly one place: the PHP `VoiceController` join gate + the `users=` value written into `murmur.ini`. Full per-client plan in §7A.

---

## 3. Capacity & bandwidth requirements (up to 200 concurrent)

### 3.1 Per-user bandwidth (Murmur's own formula, from `AudioInput.cpp`)

```
network_bw = opus_bitrate + overhead
overhead ≈ 20+8+4+1+2 bytes + (800/frames) + per-frame bits
```

| Quality | Opus bitrate | Network bw per talking user |
|---|---|---|
| High (default 72 kbit/s) | 72 kbit/s | ~83 kbit/s |
| Moderate (40 kbit/s) | 40 kbit/s | ~51 kbit/s |
| Minimum (16 kbit/s) | 16 kbit/s | ~27 kbit/s |
| Floor (8 kbit/s) | 8 kbit/s | ~19 kbit/s |

### 3.2 Worst case vs realistic

- **Worst case** (all 200 in one channel, everyone talking): moderate ≈ **10.2 Mbit/s**, minimum ≈ **5.4 Mbit/s** (up + down).
- **Realistic**: voice is bursty (VAD — not everyone talks at once). Typical sustained for 200 users across channels at moderate quality: **2–4 Mbit/s**, bursts to 6–8 Mbit/s.
- Murmur mixes per channel, so **downlink is one stream per listener, not one per speaker** — this is the whole reason 200 users is feasible on modest bandwidth.

### 3.3 Host recommendation (for George / deployment)

- **Bandwidth:** 100 Mbit/s unmetered (or 50 Mbit/s with ≥10 TB/mo). Hard floor: 25 Mbit/s symmetric.
- **CPU:** 2 vCPU minimum, 4 vCPU comfortable (Opus mixing is cheap).
- **RAM:** 1–2 GB (Murmur + SQLite backend is light).
- **Disk:** 10 GB is overkill (logs + SQLite only).
- Same box as LVChat is fine (PHP app is also light). **Do not** put it on shared cPanel-style hosting — Murmur needs a real daemon + open UDP port.

---

## 4. Murmur server deployment

### 4.1 Install (Debian/Ubuntu)

```bash
apt install mumble-server        # package name varies: mumble-server | murmur
systemctl enable --now mumble-server
murmurd -ini /etc/mumble-server.ini   # first run generates SQLite + cert
```

### 4.2 `murmur.ini` settings we care about

| Key | Value | Why |
|---|---|---|
| `port=64738` | default | UDP voice + TCP control; keep |
| `users=100` | **managed by app** | hard max concurrent connections — see §5.4 |
| `bandwidth=558000` | **managed by app** | per-client cap (bits/s) — see §5.4 |
| `opusthreshold=0` | 0 | force Opus (all modern clients have it) |
| `serverpassword=` | app-managed | shared join password (see §7) |
| `welcometext=` | "LVChat Voice" | branding |
| `allowping=true` | default | lets us read user count via UDP ping for the admin status panel |

> Note: package may ship `mumble-server.ini` (Debian) — confirm the path; the app's ini-writer must handle both.

### 4.3 systemd unit (mirror the ws-server.php unit style in `bin/ws-server.php` header)

```ini
[Unit]
Description=LVChat Murmur voice server
After=network.target
[Service]
User=mumble-server
ExecStart=/usr/bin/murmurd -ini /etc/mumble-server.ini -fg
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
```

### 4.4 Firewall

- Open **UDP 64738** (and TCP 64738 for native clients / control) on the host.
- If serving mumble-web from the same origin as LVChat, no extra web port needed. If Murmur's WebSocket listener is used directly, open that port too (default `64738` ws on same port in newer murmur, or a dedicated `websocket` port — verify at implementation time).
- `bin/deploy.sh` currently only manages the LVChat gateway; add a Murmur section (start/restart/status) — see §8.

---

## 5. Admin settings integration (the max-connections control)

### 5.1 New `server_config` keys (all read via existing `config_get()` / `config_set()` helpers in `src/Helpers.php`)

| Key | Default | Notes |
|---|---|---|
| `mumble_enabled` | `0` | master switch — hides voice UI when off |
| `mumble_host` | `127.0.0.1` | Murmur host the app talks to (admin writes ini there) |
| `mumble_port` | `64738` | Murmur UDP/TCP port |
| `mumble_ws_url` | `''` | optional; where the browser mumble-web client connects (same-origin default if blank) |
| `mumble_server_password` | `''` | shared join password (write-only, like `smtp_password`) |
| **`mumble_max_users`** | `50` | **the cap we care about** — max concurrent voice connections; range 1–200 |
| **`mumble_bandwidth`** | `51000` | per-client cap bits/s → murmur `bandwidth=`. Presets: `83000`=high, `51000`=moderate (40 kbit/s), `27000`=minimum (16 kbit/s), `19000`=floor |
| `mumble_quality_preset` | `moderate` | `high` \| `moderate` \| `minimum` — UI convenience that sets `mumble_bandwidth` |
| `mumble_register_name` | `LVChat Voice` | public server-list name (optional) |

**Why `mumble_max_users` defaults to 50, not 200:** the point of the admin control is to protect host resources. 50 is a sane shared-hosting-era default; George can raise it to 200 on the right box. The app must **never** exceed the murmur `users=` hard cap, and the murmur cap must **never** exceed what the admin set — see §5.4 for the invariant.

### 5.2 `AdminController::settings()` — add keys to the whitelist

File: `src/controllers/AdminController.php`, `settings()` (~line 411). Append the keys from §5.1 to the `$keys` array so they load into `$settings` for the view. Also compute `mumble_has_password` (mirror `smtp_has_password`).

### 5.3 `settings_save` — persist + validate + restart Murmur

File: `src/controllers/AdminController.php`, `action()` switch, `case 'settings_save'` (~line 1059). Add:

- `mumble_enabled` → `'0'/'1'`
- `mumble_host` → validate IP/hostname (mirror `ws_ip` validation)
- `mumble_port` → `max(1, min(65535, int))`
- `mumble_server_password` → only overwrite when non-empty (mirror SMTP password handling)
- `mumble_max_users` → `max(1, min(200, int))`
- `mumble_bandwidth` → `max(8000, min(558000, int))` (Murmur floor is 8 kbit/s)
- `mumble_quality_preset` → validate in `{high,moderate,minimum}`, derive bandwidth if blank

**Restart-on-change** (copy the exact pattern already used for `ws_port`):
1. Snapshot old `mumble_host`/`mumble_port`/`mumble_max_users`/`mumble_bandwidth` before saving (like `$oldWsPort` etc.).
2. After saving, if any changed AND `mumble_enabled=1`: regenerate the murmur ini (see §5.4) and restart the murmur service via `CommandRunner::run()` (same helper used for `ws-server.php restart`). Audit-log it (`log_audit('mumble_restart', ...)`).
3. If Murmur isn't running, save anyway and surface "Murmur is not running — start it to apply" (mirror the ws gateway message).

### 5.4 The cap invariant (critical)

Two layers must **always agree**:

1. **App layer (soft gate):** LVChat tracks live voice sessions (see §6.2) and refuses new joins when `active_voice_sessions >= mumble_max_users`. This is what the user actually sees ("Voice is full — try later").
2. **Murmur layer (hard ceiling):** `users=` in `murmur.ini` is set to `mumble_max_users` (never higher). Murmur will reject the (max_users + 1)th native client even if the app gate is bypassed.

The admin UI should make the effective cap obvious, e.g. *"Max concurrent voice users: 50 — enforced by LVChat (join gate) and Murmur (users=50)".*

**Dynamic ini writing:** Murmur only reads `users=`/`bandwidth=` at boot, so a settings change = write ini + restart service. Write a small PHP helper (e.g. `src/services/MumbleService.php`, see §9) that:
- Reads the current ini, **preserves every other key** (never clobber a hand-edited ini — the "inspect existing state first, merge by default" rule),
- Updates only `users`, `bandwidth`, `serverpassword`, `port`,
- Writes atomically (tmp + rename), then triggers `systemctl restart mumble-server` (or the daemonized `murmurd -ini ...` restart) via `CommandRunner`.

### 5.5 Admin UI — `views/admin/settings.php`

Add a **"Voice (Mumble)"** card, placed near the Realtime mode card. Fields:

- Master switch: `mumble_enabled`
- `mumble_host`, `mumble_port` (numeric), `mumble_ws_url` (optional, font-mono)
- **Max concurrent voice users** `mumble_max_users` — labeled clearly: *"Hard ceiling for voice connections. Protects server bandwidth/CPU. Murmur's users= is set to this and LVChat refuses joins past it."*
- **Quality preset** select (`high`/`moderate`/`minimum`) + read-only display of the resulting per-user bandwidth (51 kbit/s etc.) — or a raw `mumble_bandwidth` field with a helper text listing the presets.
- `mumble_server_password` (write-only; show "•••" if set, like SMTP)
- **Status panel** (mirror the WS gateway status panel, `#ws-settings`): live "connected voice users / max" pulled from the app's `voice_sessions` count + Murmur's UDP ping user count (see §6.3), plus a "Murmur running: yes/no" dot from a health probe.

Keep all fields in the same `<form method="post" action="/admin/action">` — no new form needed. Add the keys to the `$keys` list in `settings()` so `$settings['mumble_*']` renders.

---

## 6. Voice session management (app side)

### 6.1 New table `voice_sessions`

```sql
CREATE TABLE IF NOT EXISTS voice_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
  murmur_session INTEGER,          -- murmur's session id if we can read it
  joined_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(user_id, channel_id)      -- one voice connection per user per channel
);
```

One row per live voice connection. **This table is the source of truth for the app-level cap** and for the admin status panel.

### 6.2 API endpoints (mirror the existing `/api/*` controller style)

- `POST /api/voice/join` — body: `{channel: slug}`. Server checks: `mumble_enabled` on; user has channel access (reuse `ChannelService`/`AccessService` gates); `active_voice_sessions < mumble_max_users` (else HTTP 409 + friendly error); inserts `voice_sessions`; returns `{host, port, ws_url, password, channel, username}` so the client can connect.
- `POST /api/voice/leave` — removes the session row (idempotent).
- `GET /api/voice/status` — `{enabled, active, max, full}` for UI badges and the admin panel.
- **Stale-session cleanup:** a cron/heartbeat or a check inside `leave`/`status` that deletes `voice_sessions` where `last_seen` is older than ~2 min (mirror the `rt_transports` 2-minute window pattern in `Realtime::daemonStatus()`). Clients heartbeat `last_seen` on an interval. This prevents crashed tabs from pinning the cap.

### 6.3 Murmur-side live user count (for the admin panel)

Options, in order of preference:
1. Murmur **UDP ping protocol** (port 64738, the `allowping` protocol) — gives current user count + max. Pure PHP, no deps. **Best fit** (matches the fsockopen style already used in `Realtime::daemonStatus()`).
2. Parse `murmur.log` (fragile).
3. ICE (ZeroC) — heavyweight, avoid unless we need per-user control (kick, mute) later. **Defer.**

Per-channel mapping: create one Murmur channel per LVChat channel (by slug) on demand via the app, or flatten to one big Murmur root channel initially and map LVChat channels onto Murmur subchannels at join time. MVP: **one Murmur channel per LVChat channel, created lazily on first join** (murmur ini `channelnestinglimit` is fine at default). This is a decision point for the coding agent — see §10.

---

## 7. Client integration

### 7.1 Web (browser) — mumble-web

Mumble has **no native browser client and no WebRTC bridge**. The standard web path is **mumble-web** (Johni0702's WebAssembly client):
- Serve it from the same origin as LVChat (or a subdomain) so no mixed-content issues.
- It connects to Murmur over **WebSocket** — Murmur 1.5+ ships a WebSocket listener; mumble-web is compatible with the 1.5 protocol. **Verify current mumble-web + murmur version compatibility at implementation time** (this changes; pin versions).
- It's a static SPA — LVChat embeds it in a modal/panel (the app already has an iframe-pane pattern for Channel URLs; reuse that UX or a dedicated voice modal).
- Auth: mumble-web supports a preconfigured server + password. LVChat hands the client the join payload from `POST /api/voice/join` (host/port/password) and stores them in-memory only (never in localStorage).

### 7.2 Desktop clients (Electron) — later phase

- `desktop/` and `lvchat-messenger/` are Electron. Native Mumble via a bundled client lib is the high-quality path but is a **large** chunk of work (native modules, build pipeline per OS).
- MVP for desktop: open the same mumble-web voice UI in an Electron window (cheap, consistent). Native client integration is a follow-up.

### 7.3 Channel → voice mapping & UI

- Voice is a **per-channel feature**: add a "voice active" indicator to channels (the channel model already has `member_limit`, `visibility`, access lists — voice should respect the same access gates).
- Join/leave buttons in the channel header; presence dots in the member list come from `voice_sessions` joined to presence (`last_seen`).
- `data-voice` attributes on the chat `<body>` (mirroring `data-rt`/`data-ws-url` in `views/chat/app.php` lines ~319–322) so `public/assets/js/app.js` knows whether voice is enabled, the ws url, and the current cap.

---

## 7A. Cross-app implementation plan (web + desktop + messenger + messenger-web)

> **The golden rule: the voice engine and the join gate live 100% on the server.**
> Every client just calls `POST /api/voice/join` and embeds/displays the mumble-web surface the server tells it to. The `mumble_max_users` cap is enforced server-side in one place — no client ever re-implements capacity logic, and no client can bypass it.

### 7A.1 The four surfaces

| Client | Codebase | Tech | Voice surface (MVP) | Voice surface (later phase) |
|---|---|---|---|---|
| **Web app** | `views/chat/app.php` + `public/assets/js/app.js` | Browser (PWA) | mumble-web **iframe** in a voice pane above the chat (reuse the Channel-URL pane pattern at `app.js:2624`) | mumble-web in a dedicated modal |
| **Desktop** | `desktop/` (Electron) | Chromium + `session` partition | **mumble-web in an Electron `BrowserWindow`** (child window), same partition so the session cookie flows | native Mumble (C++ lib) — defer (§7.2) |
| **Messenger** | `lvchat-messenger/` (Electron) | Chromium + loopback static server (`src/server.js`) | **mumble-web iframe inside `renderer/messenger.html`** (it already has the `#channel-url-pane` iframe pattern at `messenger.html:156`) | native Mumble — defer |
| **Messenger Web** | `messenger-web/` (PWA, static build) | Browser | **mumble-web iframe** in `src/messenger.html` (same `#channel-url-pane` pattern) | — |

### 7A.2 Shared server contract (one implementation, four consumers)

The `/api/voice/*` endpoints from §6.2 are the **only** voice contract. Every client uses `window.LvApi` (or the Electron `window.lvchat` / `window.msg` bridge) exactly the way it already calls `LvApi.getJson('/api/poll')` / `fetch('/api/channel/read')`:

```js
// POST /api/voice/join  { channel: slug }  →  200 { host, port, ws_url, password, channel, username }
//                                          →  409 { error: "Voice is full (50/50). Try again later." }
// POST /api/voice/leave                    →  200 { ok: true }   (idempotent)
// GET  /api/voice/status                   →  200 { enabled, active, max, full }
```

- **Auth:** the browser session cookie + CSRF token the client already carries (the messengers POST form-encoded with `csrf` in the body — no preflight; keep that pattern for voice joins).
- **The join payload is single-use and in-memory only.** The client holds `{host, port, ws_url, password}` in a JS variable while the voice pane is open, and drops it on leave/unmount. Never persist to `localStorage`/disk.
- **Full (409):** all four clients render the same friendly message from `j.error` and show a disabled join button with a counter (`active/max`).

### 7A.3 Web app — concrete steps

1. Add `data-voice-enabled` / `data-voice-ws-url` / `data-voice-max` to the chat `<body>` (`views/chat/app.php` ~line 319, next to the existing `data-rt` attrs).
2. In `public/assets/js/app.js`, build a **voice pane** by copying the `urlPaneEl()` pattern (`app.js:2605–2640`): a collapsible pane above the message list whose `<iframe>` points at the mumble-web URL with `?server=<host>&port=<port>&username=<user>&password=<pass>` (or however mumble-web accepts prefill — verify, §10 Q2).
3. Join flow: channel header button → `fetch('/api/voice/join', {method:'POST', body: FormData({csrf, channel})})` → on 200, set `iframe.src` and show the pane; on 409, `uiAlert(j.error)`.
4. Leave flow: close the pane + `POST /api/voice/leave`; heartbeat `last_seen` on an interval (see §6.2 stale cleanup).
5. Sandbox the iframe like the URL pane, but **add `allow="microphone"`** to the `allow=` attribute — mumble-web needs mic access. On the PWA, also confirm the manifest has no mic permission blocker.
6. Reflect voice presence: when `/api/voice/status` says a channel is active, show a small speaker/headset icon next to the channel name and in the member list (reuse the existing presence dot system).

### 7A.4 Desktop (Electron) — concrete steps

1. `desktop/src/main.js` already calls `allowPermissions(session.fromPartition(partition))` (line ~236) and the allowlist already includes **`media`** — so the mic permission prompt will work in the chat window automatically. Verify `media` stays in the list; do **not** add `media` to windows that don't need it.
2. Add a **voice window** path to `desktop/src/main.js` (mirror `openChatWindow` at line ~372): a child `BrowserWindow` (e.g. 420×640, frameless-ish, always-on-top toggle) that loads the mumble-web URL with the join payload in the query string, using the **same partition** as the chat window so the session cookie is present. Hook it to a "Voice" button in the chat UI via the existing `window.lvchat` bridge (`desktop/src/preload.js`): add `voiceOpen(payload)` / `voiceClose()` IPC.
3. Because it's a separate window (not an iframe), Electron's `setPermissionRequestHandler` already covers the mic; also handle `setPermissionCheckHandler` for `media` if Chromium blocks it on the mumble-web origin.
4. Keep the join payload in the main process only: pass it to the window via `loadURL` query (never write it to disk; don't log it).

### 7A.5 Messenger (Electron) — concrete steps

1. `lvchat-messenger/src/main.js` has the same `allowPermissions` allowlist incl. `media` (line ~171) — verify it stays.
2. The messenger UI lives in `renderer/messenger.html`; it **already has the `#channel-url-pane` iframe** (line 156) with `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"` and `allow="fullscreen"`. Reuse this exact pane for voice:
   - Add a **voice button** to the chat header (`#chat-title` row, line ~140) — visible only when `/api/voice/status` returns `enabled:true` and the channel supports voice.
   - On join, set the pane's `iframe.src` to the mumble-web URL with the payload query string; on leave, clear it + `POST /api/voice/leave`.
   - **Add `microphone` to the iframe `allow=` attribute** (the URL pane only has `fullscreen`; mumble-web needs mic).
3. Because the messenger serves its UI from a **loopback origin** (`http://127.0.0.1:<random>` via `src/server.js`), the mumble-web iframe will be a **cross-origin** embed — confirm the mumble-web server sends permissive CORS for its WebSocket, or serve mumble-web from the same loopback origin by proxying it in `src/server.js` (add a `/voice/*` route to the static server that serves mumble-web's files — cleanest). This is the one place where the loopback architecture bites; flag it in §10.

### 7A.6 Messenger Web (PWA) — concrete steps

1. `messenger-web/` is a static build (`build.js` reads `.env`, emits `dist/`). It has **no server-side code** — it can only reach `/api/voice/*` on the configured LVChat server (cross-site, so it relies on the PHP CORS middleware that already lets the messenger origin talk to the server).
2. `src/messenger.html` has the same `#channel-url-pane` iframe (line 156) as the Electron messenger — reuse it identically, **plus `allow="microphone"`**.
3. `src/api.js` (`window.LvApi`) already has `getJson`/`postForm` with `credentials:'include'` + CSRF — add voice join/leave as plain `LvApi.postForm('/api/voice/join', {csrf, channel})` calls (no new auth plumbing).
4. mumble-web URL: because this client is hosted on a **different origin** than the LVChat server, the mumble-web iframe must point at the **server's** mumble-web (same origin as the server → same-origin WebSocket, no CORS issue). The join payload already returns `ws_url` for exactly this reason. If mumble-web is served from a CDN/other origin instead, it must send permissive CORS and the WebSocket must be reachable cross-site — verify, §10 Q2.
5. PWA note: `src/sw.template.js` (service worker) must **not** cache the mumble-web iframe or the join payload; keep voice URLs in the network-only bucket (add to the SW's exclusion list).

### 7A.7 Shared UX rules (all four clients)

- **Join button states:** `Voice` (idle) → `Connecting…` → `In voice` (green, with leave). Disabled + `Voice full (n/max)` when `/api/voice/status` says full.
- **Error surfaces:** 409 full → inline toast; network fail → "Voice is offline — try again" (the existing offline-banner pattern in `messenger.html:75` is a good template); Murmur down → server returns `enabled:false` and clients hide the button entirely.
- **Presence:** voice-active users get a headset/🔊 dot in the member list (reuse the presence system in `messenger.js` `channelPresence` and the web app's presence dots).
- **One code path for the join payload:** a tiny shared helper in each client (`voiceJoin(channelSlug)`) so all four stay consistent — copy-paste, not a shared module (each repo builds standalone).

---

## 8. Deploy & ops

- **`bin/deploy.sh`:** add a Murmur section (mirror the ws gateway handling): detect `mumble-server`/`murmurd` unit, restart on deploy if `mumble_enabled=1`, verify UDP port open. Keep it non-fatal when Murmur is absent (the app degrades to text-only).
- **Monitoring:** reuse the health-check style of `bin/ws-server.php` — add `GET /health`-equivalent for Murmur via the UDP ping; surface "Murmur down" in the admin overview and the voice status panel.
- **Backups:** Murmur's SQLite (`/var/lib/mumble-server/mumble-server.sqlite`) holds registered users/certs if we ever use them — include it in the existing backup routine **only if** we enable registration; with the shared-password MVP it holds nothing important.
- **Security:** never expose `mumble_server_password` to the browser except in the in-memory join payload; rate-limit `/api/voice/join` (reuse the `login_attempts`-style pattern or the existing spamfilter machinery); keep Murmur bound to the app host, not 0.0.0.0, unless native clients need direct access (then firewall it to the app's public IP and consider the `obfuscate=true` ini option for logs).

---

## 9. File-by-file change list (for the coding agent)

### Server (one implementation, all clients)

| File | Change |
|---|---|
| `schema.sql` | add `voice_sessions` table (§6.1) |
| `src/Database.php` | seed default `mumble_*` keys in the migration/seed block (~lines 372–389 pattern) |
| `src/Helpers.php` | nothing needed — `config_get`/`config_set` already exist |
| `src/services/MumbleService.php` | **new** — ini read/merge/write (atomic), murmur restart via `CommandRunner`, UDP-ping status probe, active-session count |
| `src/controllers/AdminController.php` | `settings()` key list; `settings_save` case (§5.2–5.3) |
| `src/controllers/VoiceController.php` | **new** — `/api/voice/join`, `/leave`, `/status` (§6.2) |
| `src/Router.php` | register the voice routes (find where `/api/*` routes are registered) |
| `views/admin/settings.php` | Voice (Mumble) card + status panel (§5.5) |
| `bin/deploy.sh` | Murmur start/restart/status section (§8) |
| `docs/admin-guide.md` | §6 settings table: add `mumble_*` rows; new §2.x "Voice" admin section |
| `docs/protocol/realtime.md` (or new `voice.md`) | document `/api/voice/*` payloads + the join gate contract |
| `README.md` | feature bullet + "Voice" architecture note |

### Web app

| File | Change |
|---|---|
| `views/chat/app.php` | `data-voice-enabled` / `data-voice-ws-url` / `data-voice-max` on `<body>` (next to `data-rt`, ~line 319); voice button in channel header |
| `public/assets/js/app.js` | voice pane (copy `urlPaneEl()` ~line 2605), join/leave + heartbeat, 409 handling, presence icons; `allow="microphone"` on the voice iframe |

### Desktop (Electron)

| File | Change |
|---|---|
| `desktop/src/main.js` | `voiceOpen(payload)` / `voiceClose()` IPC handlers; child `BrowserWindow` loading mumble-web with the join payload in the query, **same partition** as the chat window; confirm `media` stays in `allowPermissions` (line ~236) |
| `desktop/src/preload.js` | expose `voiceOpen`/`voiceClose` on `window.lvchat` |
| `desktop/src/renderer/*` (if voice UI in launcher) | optional voice-status badge |

### Messenger (Electron)

| File | Change |
|---|---|
| `lvchat-messenger/src/main.js` | confirm `media` in `allowPermissions` (line ~171); if serving mumble-web locally, add a `/voice/*` route to the loopback static server |
| `lvchat-messenger/src/server.js` | optional: proxy mumble-web files on the loopback origin (avoids cross-origin iframe issues, §7A.5.3) |
| `lvchat-messenger/renderer/messenger.html` | voice button in the header (`#chat-title` row); reuse `#channel-url-pane` for voice; add `microphone` to the iframe `allow=` |
| `lvchat-messenger/renderer/messenger.js` | `voiceJoin()` helper via `LvApi.postForm`, pane open/close, heartbeat, 409 + offline handling |

### Messenger Web (PWA)

| File | Change |
|---|---|
| `messenger-web/src/messenger.html` | voice button + voice pane (reuse `#channel-url-pane`, add `microphone` to `allow=`) |
| `messenger-web/src/messenger.js` | `voiceJoin()` via `LvApi` (same as Electron messenger) |
| `messenger-web/src/api.js` | no change needed — `postForm`/`getJson` already exist; voice is just two more calls |
| `messenger-web/src/sw.template.js` | exclude mumble-web URLs + `/api/voice/*` from the SW cache (network-only) |
| `messenger-web/build.js` | no change (no new env vars unless we want a voice-server URL override) |

---

## 10. Open questions for the coding agent (resolve before/while implementing)

1. **mumble-web compatibility** — which murmur version is current in the distro, and which mumble-web release matches it? (This is the highest-risk external dependency; pin both.)
2. **Murmur WebSocket listener** — is it built into the distro `mumble-server` package or a separate plugin? What port does it bind? Does it accept the same origin (CORS) we need? **Also: how does mumble-web accept connection prefill (query params vs. config)?** This drives the iframe URLs in §7A.3–7A.6.
3. **Channel mapping** — one Murmur channel per LVChat channel (lazy-create) vs. flat root channel for MVP? Decide based on how deep channel ACLs need to be enforced in voice.
4. **Per-user Mumble accounts?** MVP uses a shared `serverpassword`. Do we want per-user registration (murmur DB + certs) later so kicks/mutes map to LVChat bans? (Defer; note in §8.)
5. **`mumble_max_users` ceiling** — hard-cap the admin input at 200 (per George's target) or make it open-ended? Recommend: **cap the input at 200** and warn in the UI about bandwidth above that.
6. **Who restarts Murmur on settings change** — the PHP app runs as the site user; does it have `systemctl restart mumble-server` rights (sudoers entry), or should we fall back to a setuid helper / `CommandRunner` with sudo like `deploy.sh` already does? Check what `deploy.sh` does for the ws gateway and mirror it.
7. **Stale-session cleanup trigger** — cron vs. opportunistic cleanup inside `status`/`join` (recommend the latter, matches app conventions, no new scheduler dependency).
8. **Messenger loopback origin** — the Electron messenger serves UI from `http://127.0.0.1:<random>` (`src/server.js`). A mumble-web iframe from there is cross-origin to the LVChat server. Decide: proxy mumble-web through the loopback server (cleanest, §7A.5.3) vs. rely on mumble-web's CORS. **Test mic permission in Electron with `sandbox:true` + `contextIsolation:true` on the messenger's BrowserWindow** (the web app's iframe path is simpler; the messenger's is the riskiest).
9. **Messenger-web cross-site cookies** — the PWA relies on `SameSite=None` over HTTPS for the server session (README requirement). The voice join uses that same session — confirm it works from the messenger-web origin, and that mumble-web's WebSocket to the server isn't blocked by third-party-cookie/partitioning rules in Safari/Firefox (may need `partition` hints or a fallback message).
10. **Electron `media` permission scope** — `allowPermissions` grants `media` to every window in the partition. Confirm that doesn't auto-grant mic to the chat window itself (mumble-web in its own window is fine; a rogue iframe could also get mic). If concerned, scope the voice window to its own partition with `media` only (and its own profile session cookie handling).

---

## Appendix A — Bandwidth cheat sheet (copy into the admin UI helper text)

For `mumble_max_users` N at a given preset (worst case, all talking):

| Preset | `mumble_bandwidth` | Per user | 50 users | 100 users | 200 users |
|---|---|---|---|---|---|
| High | 83000 | ~83 kbit/s | 4.2 Mbit/s | 8.3 Mbit/s | 16.6 Mbit/s |
| Moderate | 51000 | ~51 kbit/s | 2.6 Mbit/s | 5.1 Mbit/s | 10.2 Mbit/s |
| Minimum | 27000 | ~27 kbit/s | 1.4 Mbit/s | 2.7 Mbit/s | 5.4 Mbit/s |

Realistic sustained load is ~30–40% of the worst case (voice activity detection — people aren't all talking at once).

**Recommendation for 200 users:** `mumble_max_users=200` + `mumble_quality_preset=moderate` on a 100 Mbit/s unmetered host (or minimum on 25–50 Mbit/s). See §3.3.

---

*Derived from Murmur's own bandwidth formula (mumble `AudioInput.cpp` `getNetworkBandwidth()`) and the `mumble-server.ini` defaults (`bandwidth=558000`, `users=100`, `opusthreshold=0`).*

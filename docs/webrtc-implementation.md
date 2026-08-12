# LVChat — WebRTC Voice Integration (LiveKit)

**Status:** Implemented as the **`webrtc` module** (`modules/webrtc/`, see its README). §7A/§9 below describe the original cross-app plan; the server side now ships as a self-contained module (module-owned schema, routes, admin page, assets). Shipped client work: web app voice + video (camera/screen share) + meetings in `assets/js/voice.js`; desktop via the served `/app` page (e2e covers the contract); messenger (Electron) and messenger-web vendor `livekit-client` + a server-gated `voice.js`. Client-repo integration points are documented in `modules/webrtc/README.md` and remain as listed here.
**Target app:** LVChat (PHP 8.1+ / SQLite / Workerman) at `chat.lasvegasbestinternet.com`
**Scope:** Audio calling for **one-on-one DMs** (ring/accept/decline), **per-channel voice**, **video** (camera + screen sharing), and **meeting rooms** (`#mtg-XXXXXX` — private, keyed, invite-only, online-only invites), delivered to all four clients: web app, desktop, messenger, and messenger-web — backed by a self-hosted **LiveKit** SFU with the LVChat server as the control plane (auth, room mapping, capacity).

> **Supersedes `docs/mumble-implementation.md`.** The Mumble plan's browser path was itself WebRTC: mumble-web connects to **mumble-web-proxy** (a Rust bridge that must be compiled from source, is AGPL-3.0, ~75★/41 commits, and whose WebRTC extension "has not yet been stabilized") so it can reach Murmur. LiveKit removes that proxy entirely — the browser speaks native WebRTC to a single, actively-maintained, Apache-2.0 daemon.

---

## 1. TL;DR

- LVChat gets voice by running **LiveKit** (a single Go SFU binary) alongside the existing PHP app + Workerman realtime gateway, plus **coturn** for NAT traversal (TURN).
- **LiveKit forwards audio; LVChat does auth, authorization, room mapping, and capacity enforcement:**
  - Admin sets `voice_max_users` (global concurrent voice cap) and `voice_talker_cap` (max simultaneous talkers each listener hears). The app **gates joins** against the cap (rejects with a friendly error when full) and sets the room `max_participants` on LiveKit — no daemon restart needed (unlike Murmur's `users=` ini).
  - Admin sets a per-client audio bitrate (`voice_bitrate`) — the Opus equivalent of the old Mumble bandwidth control.
- **Auth is per-user, not a shared password:** the PHP server mints short-TTL **LiveKit access tokens (JWT)** at join time, mirroring the existing `ws_tickets` one-time handshake pattern. No credential ever reaches the browser except the in-memory, single-use join payload.
- **One voice engine, four surfaces:** every client calls the same `/api/voice/*` and `/api/call/*` endpoints and drives the official **`livekit-client`** JS SDK. The web app and messenger-web run it in a bundled build; desktop and messenger (Chromium/Electron) run it natively. The cap is enforced in exactly one place — the PHP join gate — so every client obeys it and none can bypass it.
- **Features:** one-on-one DM **calls** (ring / accept / decline / end, 20 s ring timeout → "no answer"), **voice-enabled channels** (a `voice_enabled` flag per channel, settable by ops+ in the Channel Settings modal; a voice button appears in the channel header when enabled and the user has access), **video** (camera + screen sharing with per-user device selection, camera/mic test tools, and background blur / custom-image replacement via lazily-loaded MediaPipe selfie-segmentation).
- Target: up to **200 concurrent voice users**, worst case with the active-speaker cap — see §3. Recommended host: 100 Mbit/s unmetered, 2–4 vCPU, 2 GB RAM.

---

## 2. Architecture

```
┌─────────────┐   HTTPS/WSS   ┌──────────────────────────────────┐
│  Browser    │ ◀───────────▶ │  LVChat PHP app                  │
│  (web app)  │               │  - auth (sessions/CSRF/bearer)   │
└──────┬──────┘               │  - /api/voice/* (join gate)      │
       │                      │  - /api/call/* (ring/accept/end) │
┌──────┴───────────┐           │  - mints LiveKit JWTs (§6.4)    │
│  Messenger Web  │ ◀─────────└───────────────┬──────────────────┘
│  (PWA, bundler) │         WSS/session       │ REST/WS (admin)
└──────┬──────────┘                           ▼
       │  WebRTC (native, livekit-client)  ┌──────────────────────────────┐
       ▼                                  │        LiveKit SFU            │
┌─────────────┐   WebRTC (native)         │  - rooms per channel/call     │
│  Desktop /  │ ◀────────────────────────▶│  - forwards audio (SFU)       │
│  Messenger  │                           │  - speaker detection          │
│  (Electron) │                           └──────────────┬───────────────┘
└─────────────┘                                          │ UDP media 7880–7883
                                                         ▼
                                    coturn (TURN relay for NAT traversal)
```

**One engine, four surfaces:** the web app, messenger-web, desktop, and messenger all call the same `/api/voice/*` / `/api/call/*` endpoints and all render the same custom LVChat voice UI (join/leave, mute, participant list, ringing state) driven by `livekit-client`. The cap is enforced in exactly one place: the PHP `VoiceController` join gate. Full per-client plan in §7A.

**Signaling reuse:** ring/accept/decline events ride the existing realtime layer — the Workerman gateway (`Realtime::dm`/`bell` fan-out) when WebSocket mode is on, else SSE/poll + the existing notification bell/push pipeline. LiveKit only carries the audio + room state.

---

## 3. Capacity & bandwidth (up to 200 concurrent, all-talking worst case)

### 3.1 The one tradeoff vs Mumble

Murmur is an **MCU**: it mixes audio per channel, so each listener downloads **one** mixed stream. LiveKit is an **SFU**: it forwards each speaker's track, so each listener downloads **one stream per active speaker**. This is the only place Mumble genuinely wins — and it only matters in the pathological "everyone talks at once" case.

### 3.2 Per-user bandwidth

| Quality | Opus bitrate (`voice_bitrate`) | Network bw per talking user (≈) |
|---|---|---|
| High | 64 kbit/s | ~72 kbit/s |
| Moderate (default) | 40 kbit/s | ~51 kbit/s |
| Minimum | 16 kbit/s | ~27 kbit/s |

Opus is negotiated natively by WebRTC (no WASM decode step, unlike mumble-web).

### 3.3 Worst case vs realistic

- **Raw all-to-all SFU** (200 talkers, everyone hears everyone): each of 200 listeners pulls 200 downlinks × 51 kbit/s ≈ **10 Mbit/s per listener** — fails on mobile/consumer links. No SFU serves this naively; this is why large voice apps cap audible talkers.
- **With the active-speaker cap** (`voice_talker_cap`, default **8**): each listener subscribes to the top-N loudest speakers only (LiveKit speaker-detection + selective subscription). Worst case becomes:
  - Uplink: 200 talkers × 51 kbit/s ≈ **10.2 Mbit/s** (same as Murmur's worst case — senders always upload).
  - Downlink per listener: ≤ 8 × 51 kbit/s ≈ **0.4 Mbit/s** (Murmur: 0.05 Mbit/s — LiveKit costs 8× down, but bounded and broadband-fine).
- **Realistic:** voice is bursty (VAD — 1–4 simultaneous talkers). Sustained for 200 users across channels: **2–4 Mbit/s**, bursts to 8 Mbit/s.

**Product note:** "every listener hears all 200 voices at once" is not something anyone actually wants. The talker cap is the same design Discord/TeamSpeak-style products ship; it protects the server *and* the listeners. If the hard requirement ever becomes "must hear all 200 simultaneously," that is the one case that argues for a mixing MCU instead — LiveKit would then need a custom server-side mixer participant (§10 Q4).

### 3.4 Host recommendation

- **Bandwidth:** 100 Mbit/s unmetered (or 50 Mbit/s with ≥10 TB/mo). Hard floor: 25 Mbit/s symmetric.
- **CPU:** 2 vCPU minimum, 4 vCPU comfortable (SFU forwarding is cheap; Opus encode/decode is in the browsers and LiveKit's Go/Pion stack).
- **RAM:** 2 GB (LiveKit + coturn are light; Pion's memory per connection is modest).
- **Disk:** 10 GB is overkill (logs only — no recordings unless enabled).
- Same box as LVChat is fine. **Do not** put it on shared cPanel-style hosting — LiveKit needs real UDP ports + a public IP for ICE.

---

## 4. LiveKit deployment

### 4.1 Install (Debian/Ubuntu)

```bash
# LiveKit server — single static binary (also Docker: livekit/livekit-server)
curl -sSL https://get.livekit.io | bash
# or grab a release binary from github.com/livekit/livekit/releases

# coturn for NAT traversal (TURN)
apt install coturn
```

### 4.2 `livekit.yaml` settings we care about

| Key | Value | Why |
|---|---|---|
| `port` | `7880` | WebRTC UDP mux + WebSocket signaling (wss:// in production) |
| `rtc.tcp_port` | `7881` | TCP fallback for strict networks |
| `rtc.port_range_start` / `end` | `50000` / `50200` | UDP range for ICE (firewall must open it) |
| `keys` | `api_key: api_secret` | shared HMAC secret used by the PHP JWT mint |
| `turn.enabled` | `true` | serve TURN from LiveKit itself *or* point at coturn (`turn.udp_port`, `turn.tls_port`, `turn.domain`) |
| `logging.level` | `info` | keep logs small |

The app never rewrites this file (unlike Murmur's ini). LiveKit reads its config at boot only, but **no setting the app changes requires a restart** — the join gate + room APIs are runtime, so the admin flow is simpler than the Mumble plan (§5.4).

### 4.3 systemd units

```ini
# /etc/systemd/system/livekit.service
[Unit]
Description=LVChat LiveKit media server
After=network.target
[Service]
User=livekit
ExecStart=/usr/local/bin/livekit-server --config /etc/livekit.yaml
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
```

```ini
# coturn: /etc/turnserver.conf — relay on the public IP; auth via the LiveKit
# integration (LiveKit issues TURN creds) OR a shared static credential.
```

### 4.4 Firewall

- Open **UDP 7880** + **TCP 7881** (LiveKit RTC), the **UDP 50000–50200** ICE range, **TCP 7880** (signaling) and, if using coturn, **UDP/TCP 3478** + **UDP 5349** (TURN).
- If LiveKit serves wss itself, terminate TLS on it or reverse-proxy `/` + `/rtc` through the site's nginx with the existing Let's Encrypt cert (mirror the `deploy.sh` TLS staging for the WS gateway).
- `bin/deploy.sh` gets a LiveKit section (start/restart/status + UDP health) — see §8.

---

## 5. Admin settings integration

### 5.1 New `server_config` keys (read via existing `config_get()` / `config_set()` in `src/Helpers.php`)

| Key | Default | Notes |
|---|---|---|
| `voice_enabled` | `0` | master switch — hides all voice/call UI when off |
| `livekit_url` | `ws://127.0.0.1:7880` | where clients connect (wss:// in production); also used for server-side room admin |
| `livekit_api_key` | `''` | API key (paired with the secret below) |
| `livekit_api_secret` | `''` | HMAC secret for JWT signing — **write-only**, like `smtp_password` |
| **`voice_max_users`** | `50` | **the cap we care about** — max concurrent voice connections; range 1–200. Join gate rejects past it and each room's `max_participants` is set to it |
| `voice_talker_cap` | `8` | max simultaneous talkers each listener hears (active-speaker subscription) |
| `voice_bitrate` | `40000` | per-user Opus cap bits/s. Presets: `64000`=high, `40000`=moderate, `16000`=minimum |
| `voice_quality_preset` | `moderate` | UI convenience that sets `voice_bitrate` |

**Why `voice_max_users` defaults to 50, not 200:** the point of the control is to protect host resources. 50 is a sane default; raise it to 200 on the right box. The app must never let `active_voice_sessions` exceed it, and the room `max_participants` must never exceed what the admin set.

### 5.2 `AdminController::settings()` — add keys to the whitelist

File: `src/controllers/AdminController.php`, `settings()` (~line 411). Append the keys from §5.1 to the `$keys` array so they load into `$settings` for the view. Also compute `livekit_has_secret` (mirror `smtp_has_password` at line 424).

### 5.3 `settings_save` — persist + validate (no daemon restart)

File: `src/controllers/AdminController.php`, `action()` switch, `case 'settings_save'` (~line 1059). Add:

- `voice_enabled` → `'0'/'1'`
- `livekit_url` → validate `ws(s)?://host:port` (or `http(s)://` for the admin API); fall back to default on bad input
- `livekit_api_secret` → only overwrite when non-empty (mirror SMTP password handling, line 1109)
- `voice_max_users` → `max(1, min(200, int))`
- `voice_talker_cap` → `max(1, min(50, int))`
- `voice_bitrate` → `max(16000, min(64000, int))`
- `voice_quality_preset` → validate in `{high,moderate,minimum}`, derive bitrate if blank

**No restart-on-change** (unlike the `ws_port` / Murmur patterns). The join gate reads the caps from `server_config` live, and `max_participants` is applied at room-create. Audit-log changes with `log_audit('voice_settings', ...)`.

### 5.4 The cap invariant (critical)

Two layers must **always agree**:

1. **App layer (soft gate):** LVChat tracks live voice sessions (§6.2) and refuses new joins when `active_voice_sessions >= voice_max_users`. This is what the user actually sees ("Voice is full — try later").
2. **LiveKit layer (hard ceiling):** each room is created/joined with `max_participants` set to `voice_max_users`. LiveKit rejects the (max_users + 1)th participant even if the app gate is bypassed. `max_participants` is a runtime API call — no restart, unlike Murmur's `users=` ini.

The admin UI should make the effective cap obvious, e.g. *"Max concurrent voice users: 50 — enforced by LVChat (join gate) and LiveKit (room max_participants)".*

### 5.5 Admin UI — `views/admin/settings.php`

Add a **"Voice (LiveKit)"** card, placed near the Realtime mode card. Fields:

- Master switch: `voice_enabled`
- `livekit_url` (font-mono) — "where clients connect, e.g. wss://voice.example.com:7880"
- `livekit_api_key` + `livekit_api_secret` (write-only; show "•••" if set, like SMTP)
- **Max concurrent voice users** `voice_max_users` — labeled: *"Hard ceiling for voice connections. Protects server bandwidth/CPU. LiveKit rooms are created with this as max_participants and LVChat refuses joins past it."*
- **Simultaneous talkers per listener** `voice_talker_cap` — labeled: *"Each listener hears at most this many active speakers (Discord-style). Protects downlink bandwidth."*
- **Quality preset** select (`high`/`moderate`/`minimum`) + read-only display of the resulting per-user bitrate
- **Status panel** (mirror the WS gateway status panel): live "connected voice users / max" from `voice_sessions` + LiveKit's room list API, plus a "LiveKit running: yes/no" dot from `GET /health` on the admin port.

Keep all fields in the same `<form method="post" action="/admin/action">` — no new form needed.

---

## 6. Voice session & call management (app side)

### 6.1 New tables

```sql
-- Voice-enabled channels: the "channels can be enabled as voice" requirement.
ALTER TABLE channels ADD COLUMN voice_enabled INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS voice_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  room TEXT NOT NULL,                -- LiveKit room name (channel slug or call id)
  kind TEXT NOT NULL DEFAULT 'channel',  -- 'channel' | 'call'
  token TEXT NOT NULL,               -- the minted LiveKit JWT (single-use, short-TTL)
  joined_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(user_id, guest_id)          -- one live voice connection per actor
);

-- One-on-one call intent/state. This is the ring/accept/decline state machine.
CREATE TABLE IF NOT EXISTS call_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room TEXT NOT NULL UNIQUE,         -- 'call:<id>' — maps 1:1 to a LiveKit room
  caller_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  caller_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  callee_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  callee_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'ringing',  -- 'ringing' | 'active' | 'declined' | 'ended' | 'missed'
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  answered_at TEXT
);
```

`voice_sessions` is the source of truth for the app-level cap and the admin panel; `call_sessions` drives the ring UX.

### 6.2 API endpoints (mirror the existing `/api/*` controller style)

Channel voice:

- `POST /api/voice/join` — body: `{channel: slug}`. Checks: `voice_enabled` on; channel has `voice_enabled=1`; user has channel access (reuse `ChannelService`/`AccessService` gates); `active_voice_sessions < voice_max_users` (else **HTTP 409** + friendly error); inserts `voice_sessions`; **mints a LiveKit JWT** (§6.4); returns `{url, token, room, talker_cap}` so the client can connect.
- `POST /api/voice/leave` — removes the session row + calls LiveKit to remove the participant (idempotent).
- `GET /api/voice/status` — `{enabled, active, max, full}` for UI badges and the admin panel.
- **Stale-session cleanup:** opportunistic delete of `voice_sessions` where `last_seen` is older than ~2 min (mirror the `rt_transports` 2-minute window in `Realtime::daemonStatus()`). Clients heartbeat `last_seen` on an interval.

One-on-one calls:

- `POST /api/call/initiate` — body: `{user: nick}`. Checks: target exists, not blocked either direction, caller is not already in a call. Creates `call_sessions` (`ringing`), returns `{call_id, room}` to the caller. Rings the callee via the realtime layer + notification bell (reuse `Realtime::bell` + push pipeline). If the callee is offline, still allow it — the callee gets a missed-call bell entry / push, and the caller sees "ringing".
- `POST /api/call/accept` — body: `{call_id}`. Caller sets `active`, mints JWTs for **both** participants (§6.4 — one token per side), returns each side's `{url, token, room}`. The callee's client then joins.
- `POST /api/call/decline` — sets `declined`, notifies the caller ("declined the call"), frees the room.
- `POST /api/call/end` — sets `ended`, removes `voice_sessions` for both, tells LiveKit to end the room (idempotent).
- `POST /api/call/timeout` / cron-opportunistic — a `ringing` call older than `call_ring_seconds` (default 20 s) becomes `missed` and the caller sees "no answer".

### 6.3 LiveKit-side room lifecycle (admin/control)

Options, in order of preference:

1. **Client-side room create on join:** `livekit-client` `Room` with `{roomName, token, maxParticipants}`? — actually `max_participants` is enforced server-side via the token's grant or room settings. The join JWT carries `roomCreate: true` + `room: <name>` + `maxParticipants` via the LiveKit JWT `video` grant, so the first participant creates the room implicitly. **Best fit** — no server-to-server calls for the common path.
2. **LiveKit REST/WebSocket admin API** (`/rtc` on the server or the server-sdk) to create rooms with explicit `max_participants`, list rooms for the admin panel, and kick/remove stale participants. Use this for the status panel and moderation (server-admin kick/mute).
3. ICE (ZeroC) — n/a; LiveKit has no such dependency.

**Room name scheme:** channel voice → `chan:<slug>`; 1:1 call → `call:<call_id>`. One LiveKit room per LVChat channel (created lazily on first join) and per call.

### 6.4 JWT mint (no external SDK required)

LiveKit access tokens are JWTs signed **HS256** with the API secret, using claims `iss` = api_key, `sub` = identity, `name`, `video` grant (`roomJoin`, `roomCreate`, `room`, `canPublish`, `canSubscribe`, `maxParticipants`, `roomAdmin`). PHP can build and sign this with the built-in `hash_hmac` — no composer dep. A small `src/services/LiveKitService.php` provides:

- `token(string $room, array $actor, array $grant): string` — short TTL (60 s, like `ws_tickets`), single-use in practice.
- `health(): bool` — GET the admin `/health` endpoint (curl/fsockopen, mirroring `Realtime::daemonStatus()`).
- `rooms(): array` — list rooms + participant counts for the admin panel.
- `removeParticipant(string $room, string $identity): void` — for leave/kick.

If preferred, the community PHP SDK (`agence104/livekit-server-sdk-php`) can be used instead — but the JWT is ~20 lines, so prefer the zero-dep path to match the codebase ethos.

---

## 7. Client integration

### 7.1 The engine: `livekit-client` (official JS SDK)

All four clients are Chromium/browser, so **WebRTC is native** — no WASM, no proxy, no iframe of a third-party UI. Each client:

1. Calls `/api/voice/join` (channel) or `/api/call/accept` (call) with the existing session/CSRF (or bearer token) it already carries.
2. Gets back `{url, token, room, talker_cap}`.
3. Connects with `new Room()` → `room.connect(url, token)` → `room.localParticipant.setMicrophoneEnabled(true)`.
4. Subscribes to **active speakers only** (talker cap): listen to `RoomEvent.ActiveSpeakersChanged`, `setSubscribedTrack`/track subscription to keep ≤ `talker_cap` loudest audio tracks — LiveKit's speaker-detection + selective-subscription make this native.

Shared UX rules in §7A.7. The join payload stays in-memory only (never `localStorage`), matching the Mumble plan's §7A.2 rule.

### 7.2 Channel → voice mapping & UI

- Voice is a **per-channel feature**: `channels.voice_enabled` (added in §6.1). It respects the same access gates as text (`ChannelService`/`AccessService`).
- The Channel Settings modal (ops+) gains a "Enable voice for this channel" toggle.
- Join/leave button in the channel header; presence = headset dot in the member list from `voice_sessions` joined to presence (`last_seen`).
- `data-voice-enabled` / `data-voice-url` / `data-voice-max` / `data-voice-talker-cap` attributes on the chat `<body>` (mirroring `data-rt`/`data-ws-url` in `views/chat/app.php` ~line 319).

### 7.3 One-on-one call UX

- A **call button** in the DM header. Click → `POST /api/call/initiate` → caller sees a ringing overlay (mute speaker / cancel).
- Callee gets an **incoming-call overlay** (via the realtime layer or push) with Accept / Decline; the notification bell + sound pipeline already exists for the alert.
- Accept → both clients `POST /api/call/accept`-derived join → in-call UI (mute, speaker, end, call timer). End → `POST /api/call/end`.
- Missed/declined → toast to the caller; missed calls appear in the bell.

---

## 7A. Cross-app implementation plan (web + desktop + messenger + messenger-web)

> **The golden rule: the voice engine and the join gate live 100% on the server.**
> Every client just calls `/api/voice/join` (or the call endpoints) and connects with `livekit-client` to the URL/token the server returns. The `voice_max_users` cap is enforced server-side in one place — no client ever re-implements capacity logic, and no client can bypass it.

### 7A.1 The four surfaces

| Client | Codebase | Tech | Voice/call surface |
|---|---|---|---|
| **Web app** | `views/chat/app.php` + `public/assets/js/app.js` | Browser (PWA) | voice pane above the chat (reuse the Channel-URL pane pattern at `app.js:2624`) + call overlays |
| **Desktop** | `desktop/` (Electron) | Chromium + `session` partition | the same web voice UI in the chat window (Electron already grants `media` in `allowPermissions`, line ~236) + native call overlay |
| **Messenger** | `lvchat-messenger/` (Electron) | Chromium, **no bundler** | voice pane in `renderer/messenger.html` (reuse `#channel-url-pane`, line 156) + call overlay; import a **prebuilt `livekit-client` UMD/ESM file** from `node_modules` |
| **Messenger Web** | `messenger-web/` (PWA, `build.js`) | Browser | voice pane in `src/messenger.html` (same `#channel-url-pane` pattern) + call overlay; npm-import `livekit-client` in the bundle |

### 7A.2 Shared server contract (one implementation, four consumers)

```
POST /api/voice/join  {channel}          → 200 {url, token, room, talker_cap}
                                         → 409 {error: "Voice is full (50/50). Try again later."}
POST /api/voice/leave                    → 200 {ok:true}   (idempotent)
GET  /api/voice/status                   → 200 {enabled, active, max, full}
POST /api/call/initiate {user}           → 200 {call_id, room}   /  404 unknown / 409 busy
POST /api/call/accept   {call_id}        → 200 {url, token, room}   (per-caller)
POST /api/call/decline  {call_id}        → 200 {ok:true}
POST /api/call/end      {call_id}        → 200 {ok:true}
```

- **Auth:** the browser session cookie + CSRF token (form-encoded, no preflight) or the messenger bearer token (`X-LVC-Session`) — exactly the patterns the clients already use for `/api/send`.
- **The token is single-use and in-memory only:** 60 s TTL, held in a JS variable while connected, dropped on leave. Never persist to `localStorage`/disk.
- **Full (409):** all four clients render the same friendly message from `j.error` and show a disabled join button with a counter (`active/max`).

### 7A.3 Web app — concrete steps

1. Add `data-voice-enabled` / `data-voice-url` / `data-voice-max` / `data-voice-talker-cap` to the chat `<body>` (`views/chat/app.php` ~line 319, next to the existing `data-rt` attrs).
2. In `public/assets/js/app.js`, build a **voice pane** by copying the `urlPaneEl()` pattern (`app.js:2605–2640`): a collapsible pane above the message list showing the channel's participants, mute button, leave button, and active-speaker badges.
3. Join flow: channel header button → `fetch('/api/voice/join', {method:'POST', body: FormData({csrf, channel})})` → on 200, `new Room()`, `connect(url, token)`, publish mic; on 409, `uiAlert(j.error)`.
4. Leave flow: `room.disconnect()` + `POST /api/voice/leave`; heartbeat `last_seen` on an interval (§6.2 stale cleanup).
5. **Talker cap:** on `RoomEvent.ActiveSpeakersChanged`, keep subscriptions to the `voice_talker_cap` loudest audio tracks (selective subscription) — this is the §3.3 bandwidth bound.
6. Reflect voice presence: when `/api/voice/status` says a channel is active, show a small speaker/headset icon next to the channel name and in the member list (reuse the existing presence-dot system).
7. **Mic permission** is a normal `getUserMedia` prompt — no iframe `allow=` wrangling (that was a mumble-web-only headache). PWA manifest needs no change.

### 7A.4 Desktop (Electron) — concrete steps

1. `desktop/src/main.js` already grants `media` in `allowPermissions` (line ~236) — confirm it stays; voice runs in the existing chat window (no new window needed, unlike mumble-web).
2. Voice is the same web UI, so the only work is exposing call/voice **IPC hints** if native overlays are wanted: `voiceRinging(callId)`, `voiceOpen(payload)`, `voiceClose()` on `window.lvchat` via `desktop/src/preload.js`.
3. Keep the token in the renderer's JS memory only; never write it to disk; don't log it.

### 7A.5 Messenger (Electron) — concrete steps

1. `lvchat-messenger/src/main.js` has the same `media` allowlist (line ~171) — confirm it stays. Voice runs in the chat window (no cross-origin iframe — the loopback-origin problem from the Mumble plan **disappears**).
2. `renderer/messenger.html` reuses the `#channel-url-pane` iframe **area** for the voice pane and adds call overlays; `renderer/messenger.js` adds `voiceJoin()`, `callInitiate()`, `callAccept()`, `callDecline()`, `callEnd()` via `LvApi.postForm` (no new auth plumbing).
3. Because the messenger has **no bundler**, add a `node_modules/livekit-client/dist/livekit-client.umd.min.js` (or the ESM build) script include and a pinned version in `package.json`. This is the one client-specific integration detail — flag it in §10 Q5.

### 7A.6 Messenger Web (PWA) — concrete steps

1. `messenger-web/` is a static build (`build.js` reads `.env`, emits `dist/`). `livekit-client` is a normal npm import in `src/messenger.js`; the bundle includes it.
2. `src/messenger.html` reuses the `#channel-url-pane` area for the voice pane + call overlays, exactly like the Electron messenger.
3. `src/api.js` (`window.LvApi`) already has `getJson`/`postForm` with credentials + CSRF — voice/call endpoints are plain `LvApi.postForm` calls.
4. **LiveKit URL:** the join payload returns `url` from the server — the PWA points its `Room.connect()` at the server's configured LiveKit URL (same as every other client). No iframe, so no CORS/service-worker-cache special-casing beyond keeping `/api/voice/*` + `/api/call/*` out of the SW cache (add to the SW's exclusion list, `src/sw.template.js`).

### 7A.7 Shared UX rules (all four clients)

- **Join button states:** `Voice` (idle) → `Connecting…` → `In voice` (green, with leave + mute). Disabled + `Voice full (n/max)` when `/api/voice/status` says full.
- **Error surfaces:** 409 full → inline toast; network fail → "Voice is offline — try again" (the offline-banner pattern in `messenger.html:75` is a good template); LiveKit down → server returns `enabled:false` and clients hide the button entirely.
- **Presence:** voice-active users get a headset dot in the member list (reuse the presence system in `messenger.js` `channelPresence` and the web app's presence dots).
- **One code path:** a tiny shared helper in each client (`voiceJoin(channelSlug)`, `callTo(nick)`) so all four stay consistent — copy-paste, not a shared module (each repo builds standalone).

---

## 8. Deploy & ops

- **`bin/deploy.sh`:** add a LiveKit section (mirror the ws gateway handling): detect the `livekit` unit, restart on deploy if `voice_enabled=1`, verify UDP ports are reachable, surface "LiveKit down" non-fatally (the app degrades to text-only). TURN/coturn is a co-install; document it in `docs/installation.md`.
- **Monitoring:** LiveKit exposes an HTTP `/health` on the admin port — reuse the health-check style of `bin/ws-server.php` and surface it in the admin overview + the voice status panel. Also monitor the ICE UDP range.
- **Backups:** nothing new — LiveKit keeps no per-user state we care about (rooms are ephemeral; identity/auth stays in LVChat's SQLite).
- **Security:** never expose `livekit_api_secret` to the browser (only the minted token); rate-limit `/api/voice/join` and `/api/call/*` (reuse the `login_attempts`-style pattern or the spamfilter machinery); bind LiveKit's admin API to the app host or firewall it; TLS everywhere (`wss://`), reusing the existing Let's Encrypt staging in `deploy.sh`.

---

## 9. File-by-file change list (for the coding agent)

### Server (one implementation, all clients)

| File | Change |
|---|---|
| `schema.sql` | `channels.voice_enabled` column; `voice_sessions` + `call_sessions` tables (§6.1) |
| `src/Database.php` | migrate `voice_enabled`; seed default `voice_*` / `livekit_*` keys in the seed block (~lines 567–609 pattern) + the `INSERT OR IGNORE` upgrade block (~line 372) |
| `src/services/LiveKitService.php` | **new** — JWT mint (HS256), `/health` probe, room list, remove-participant (§6.4) |
| `src/controllers/VoiceController.php` | **new** — `/api/voice/join|leave|status` (§6.2) |
| `src/controllers/CallController.php` | **new** — `/api/call/initiate|accept|decline|end` + timeout (§6.2) |
| `src/controllers/AdminController.php` | `settings()` key list (§5.2); `settings_save` case (§5.3) |
| `public/index.php` | register the voice/call routes (next to the other `/api/*` routes, ~line 49) |
| `views/admin/settings.php` | Voice (LiveKit) card + status panel (§5.5) |
| `views/chat/app.php` | voice attrs on `<body>`; voice button in channel header; DM call button (§7.2–7.3) |
| `views/chat/settings.php` (channel modal) | "Enable voice" toggle for ops+ |
| `bin/deploy.sh` | LiveKit + coturn section (§8) |
| `docs/admin-guide.md` | settings table rows + "Voice" admin section |
| `docs/protocol/realtime.md` or new `docs/protocol/voice.md` | document `/api/voice/*` + `/api/call/*` payloads + the join-gate contract |
| `README.md` | feature bullets + "Voice" architecture note |

### Web app

| File | Change |
|---|---|
| `views/chat/app.php` | `data-voice-*` attrs; channel-header voice button; DM call button |
| `public/assets/js/app.js` | voice pane (copy `urlPaneEl()` ~line 2605), `livekit-client` connect/publish/leave, talker-cap subscription, 409 handling, presence icons, call overlays |

### Desktop (Electron)

| File | Change |
|---|---|
| `desktop/src/main.js` | confirm `media` stays in `allowPermissions` (line ~236); optional `voiceRinging`/`voiceOpen`/`voiceClose` IPC for native overlays |
| `desktop/src/preload.js` | expose the voice IPC on `window.lvchat` |
| `desktop/package.json` | pin `livekit-client` if the overlay needs it directly |

### Messenger (Electron)

| File | Change |
|---|---|
| `lvchat-messenger/src/main.js` | confirm `media` in `allowPermissions` (line ~171) |
| `lvchat-messenger/package.json` | pin `livekit-client` |
| `lvchat-messenger/renderer/messenger.html` | voice pane (reuse `#channel-url-pane` area) + call overlays; UMD script include |
| `lvchat-messenger/renderer/messenger.js` | `voiceJoin()` / `call*()` helpers via `LvApi.postForm`, pane open/close, heartbeat, talker-cap, 409 + offline handling |

### Messenger Web (PWA)

| File | Change |
|---|---|
| `messenger-web/src/messenger.html` | voice pane + call overlays (reuse `#channel-url-pane` area) |
| `messenger-web/src/messenger.js` | `voiceJoin()` / `call*()` via `LvApi` (same as Electron messenger); `livekit-client` import |
| `messenger-web/package.json` | add `livekit-client` |
| `messenger-web/src/sw.template.js` | exclude LiveKit WS + `/api/voice/*` + `/api/call/*` from the SW cache (network-only) |

---

## 10. Open questions for the coding agent (resolve before/while implementing)

1. **LiveKit version pin** — which `livekit-server` release + matching `livekit-client` JS SDK to ship. Unlike mumble-web/proxy there's no protocol-stability concern (WebRTC is standardized), but pin versions anyway for reproducibility. Confirm the `maxParticipants` JWT grant name in the pinned release.
2. **`livekit_url` scheme in production** — serve `wss://` via the site's nginx (reuse the `deploy.sh` TLS staging) vs. LiveKit's own TLS vs. reverse proxy. Drives the `livekit_url` default and §4.4 firewall list.
3. **Talker-cap enforcement depth** — enforce at the client (selective subscription, simplest) vs. server-side (LiveKit room config / a subscription policy). Client-side is the MVP; server-side is the "no one can bypass it" hard bound. Recommend client-side first, document the bypass caveat.
4. **`voice_max_users` ceiling + the all-talking worst case** — confirm 200 is the concurrent-connection target (it is, per George) *and* that the active-speaker cap is acceptable product behavior. If "every listener must hear all 200 voices at once" is ever required, that's the one case that needs a mixing MCU or a custom LiveKit mixer participant (§3.3) — recommend keeping the cap.
5. **Messenger no-bundler SDK include** — `lvchat-messenger` is vanilla JS with no bundler; confirm the pinned `livekit-client` ships a usable prebuilt UMD/ESM file for a plain `<script>` include (the one client-specific integration wrinkle). If not, copy a built `dist/` file into the repo like the committed `public/assets/css/app.css` pattern.
6. **Stale-session cleanup trigger** — cron vs. opportunistic cleanup inside `status`/`join` (recommend the latter, matches app conventions).
7. **LiveKit room cleanup** — when the last participant leaves a channel room, should the room be destroyed (recommend yes, via the last-leaver or the `/leave` handler) so `max_participants` changes apply on the next join? LiveKit auto-reclaims empty rooms; verify at implementation time.
8. **Call authorization** — can *any* user call *any* user, or only friends / not-blocked / same-role? Recommend: no block either direction (mirror DM gate), allow non-friends for parity with `/msg`.
9. **Call logging & recording** — the app's append-only `chat_logs` records messages; should call metadata (who called whom, duration, outcome) be logged to `chat_logs`/`audit_log`? Recommend yes for moderation parity; LiveKit recording is a separate feature and **out of scope** for MVP.
10. **Rate limits** — mirror the `login_attempts` throttle for `/api/call/initiate` (spam-ring protection) and reuse the 12-per-5s send counter or a dedicated cap for `/api/voice/join`.

---

## Appendix A — Bandwidth cheat sheet (copy into the admin UI helper text)

For `voice_max_users` N at a given preset (worst case, all talking, with `voice_talker_cap`=8):

| Preset | `voice_bitrate` | Per user | Uplink (N talkers) | Downlink per listener (≤8 talkers) |
|---|---|---|---|---|
| High | 64000 | ~72 kbit/s | 14.4 Mbit/s | 0.58 Mbit/s |
| Moderate | 40000 | ~51 kbit/s | 10.2 Mbit/s | 0.41 Mbit/s |
| Minimum | 16000 | ~27 kbit/s | 5.4 Mbit/s | 0.22 Mbit/s |

Realistic sustained load is ~30–40% of the worst case (voice activity detection — people aren't all talking at once).

**Recommendation for 200 users:** `voice_max_users=200` + `voice_quality_preset=moderate` + `voice_talker_cap=8` on a 100 Mbit/s unmetered host (or minimum on 25–50 Mbit/s). See §3.4.

---

*Derived from LiveKit's SFU model (Pion WebRTC), the Opus bitrates the browser negotiates, and the active-speaker subscription design — see `docs/mumble-implementation.md` for the rejected MCU alternative.*

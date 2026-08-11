# WebRTC Voice (LiveKit) — module

One-on-one audio calls and per-channel voice for LVChat, backed by a
self-hosted [LiveKit](https://github.com/livekit/livekit) SFU. This module is
the server half: auth, room mapping, and the capacity gate live here; every
client (web app, desktop, messenger, messenger-web) consumes the same
`/api/webrtc/*` contract. See `docs/webrtc-implementation.md` for the full
design and file-by-file plan.

## Files

```
module.json                     manifest (settings, admin entry, assets)
init.php                        boot hook — loads service + controllers
routes.php                      /api/webrtc/voice/*, /api/webrtc/call/*, /admin/voice
schema.php                      idempotent: voice_sessions, call_sessions, channels.voice_enabled
LiveKitService.php              JWT mint (HS256), /health probe, status/prune helpers
VoiceController.php             voice join/leave/status + per-channel toggle
CallController.php              call initiate/accept/decline/join/end
MtgController.php               #mtg-XXXXXX meetings: create / invite / keyed landing
AdminVoiceController.php        Admin → Voice settings + save
views/admin/voice.php           settings form + status panel
assets/                         voice.css, voice.js, vendor/livekit-client.umd.js
README.md                       this file
```

## Deploy (server)

1. **Install LiveKit** (one static binary) on the app host:
   ```bash
   curl -sSL https://get.livekit.io | bash
   ```
   or a release from `github.com/livekit/livekit/releases`.

2. **`/etc/livekit.yaml`**:
   ```yaml
   port: 7880                # WebRTC + signaling
   rtc:
     tcp_port: 7881
     port_range_start: 50000
     port_range_end: 50200
   keys:
     <api_key>: <api_secret>
   turn:
     enabled: true           # or point at coturn
   ```
   Generate a strong key pair once: `openssl rand -hex 24`.

3. **systemd** (`/etc/systemd/system/livekit.service`):
   ```ini
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
   `systemctl enable --now livekit`.

4. **NAT / TURN**: WebRTC needs ICE reachable. On a VPS with a public IP,
   LiveKit's built-in TURN covers most cases; for strict networks add
   **coturn** (`apt install coturn`) and point `turn.udp_port`/`turn.tls_port`
   at it. Firewall: **UDP 7880**, **TCP 7881**, **UDP 50000–50200**, plus
   coturn **UDP/TCP 3478** if used.

5. **TLS**: serve `wss://` — either terminate at LiveKit (`https_port`) with
   the site's Let's Encrypt cert or reverse-proxy `/` and `/rtc` through the
   existing nginx. Set **LiveKit URL** in Admin → Voice to the `wss://` URL.

6. **Admin → Voice**: enable the master switch, paste the API key + secret,
   set the caps, save. No daemon restart is ever needed — LiveKit applies room
   `max_participants` at runtime.

## Admin

**Admin → Voice (LiveKit)** (added by this module's manifest `admin` block):
- Master switch, LiveKit URL, API key/secret (write-only)
- `voice_max_users` — global concurrent-voice cap (join gate + room max)
- `voice_talker_cap` — active speakers each listener hears (downlink bound)
- Quality preset / bitrate
- Live status panel: `/health` probe + connected users / max

## API contract

```
GET  /api/webrtc/voice/status           → status + caller's sessions/calls/channels
POST /api/webrtc/voice/join {channel}   → {ok, url, token, room, talker_cap, bitrate}
POST /api/webrtc/voice/leave            → {ok}
POST /api/webrtc/voice/channel-voice    → ops+ toggles channels.voice_enabled
POST /api/webrtc/call/initiate {user}   → {ok, call_id, room, peer}
POST /api/webrtc/call/accept {call_id}  → join payload for the callee
POST /api/webrtc/call/decline {call_id} → {ok}
POST /api/webrtc/call/join {call_id}    → join payload once active (caller side)
POST /api/webrtc/call/end {call_id}     → hang up
POST /api/webrtc/mtg/create             → {ok, slug, name, key, url} (#mtg-XXXXXX)
POST /api/webrtc/mtg/invite             → {ok, added[], offline[], unknown[], url}
GET  /mtg/{slug}?key=…                  → keyed auto-join landing (login bounce)
```

Auth: browser session + CSRF, or the messenger bearer token (`X-LVC-Session`,
CSRF-safe). Tokens are LiveKit JWTs (HS256, 60 s TTL) minted by this server —
the API secret never reaches a browser. Unanswered calls expire to `missed`
after 30 s; voice sessions without a heartbeat expire after 2 min.

## Clients

- **Web app**: `assets/js/voice.js` is auto-injected by the module (see the
  chat-head asset loop in `views/chat/app.php`). It adds the voice/call/meeting
  buttons, voice pane with **video (camera + screen share)**, call overlays,
  and the meeting modal, and drives everything from the status poll. Nothing
  renders unless the server reports the module enabled.
- **Desktop**: loads the server's `/app` page — gets the web voice UI
  automatically, plus its e2e mock exercises the voice contract
  (`desktop/tests/e2e.js`).
- **Messenger (Electron)**: the vendored `livekit-client.umd.js` +
  `renderer/voice.js` / `voice.css` are loaded same-origin from the loopback
  server (CSP `script-src 'self'` stays intact). Full voice + **video**,
  server-gated on `/api/webrtc/voice/status` — it never shows UI when the
  module is disabled.
- **Messenger Web (PWA)**: same assets under `src/vendor/` + `src/voice.*`,
  added to `build.js`'s copy + `PRECACHE` lists. Voice + **video**, server-gated
  like the Electron messenger.

All four clients vendor the same pinned `livekit-client` UMD, share the
`/api/webrtc/*` contract, and keep the join/leave/call/meeting endpoints as the
only voice interface (see `docs/webrtc-implementation.md`).

## Meetings (#mtg-XXXXXX)

`POST /api/webrtc/mtg/create` makes a temporary private `#mtg-<6 digits>` room
(visibility `private` + invite-only, voice enabled, random key). `POST
/api/webrtc/mtg/invite {channel, users}` adds **online** invitees immediately
(offline users are reported and not added). The invite link `/mtg/<slug>?key=…`
auto-joins members and key holders and bounces logged-out visitors through
login. Meetings are unregistered channels, so they vanish when empty.

## Known limits / follow-ups

- Server-side room listing / kick via LiveKit's admin API is not wired yet —
  the status panel counts from `voice_sessions`, and participants leave by
  disconnecting (LiveKit reclaims empty rooms). `LiveKitService::removeParticipant()`
  is a no-op placeholder.
- Ring/accept is poll-driven via `/api/webrtc/voice/status` (works on all
  transports); OS push for missed calls is a follow-up.
- Talker-cap enforcement is client-side (selective subscription) and applies to
  participants as a whole; screen-sharers are kept subscribed regardless of
  whether they are currently speaking.
- Meeting invite URLs rely on the module's stored plaintext key
  (`mtg_channels`) so the host can re-share the link; `channels.key_hash`
  remains the verification authority.

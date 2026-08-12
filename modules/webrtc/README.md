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

The module runs LiveKit **as the site user** — the same way the WebSocket
gateway runs. Config, pidfile, and log live under the app's own `data/livekit/`
(owned by the web user, never needing root), and the process is spawned with
`nohup`. This works on shared hosting (HestiaCP etc.); no `/etc`, no systemd,
no sudo.

1. **Install the LiveKit binary** somewhere on the site user's PATH (it only
   needs to be runnable by that user):
   ```bash
   curl -sSL https://get.livekit.io | bash          # installs to /usr/local/bin
   ```
   or drop a static release under `~/.local/bin/livekit-server` (checked
   automatically). The autoconfigure button reports the binary it found.

2. **Admin → Voice → Generate & autoconfigure keys**: the button generates a
   strong API key + secret, writes `data/livekit/livekit.yaml` (starter config:
   `port: 7880`, `rtc.tcp_port: 7881`, UDP range 50000–50200, plus the keys
   block — existing keys are preserved), starts `livekit-server --config …` as
   the site user with nohup, records the pid, enables voice, and shows a status
   flash. Set **LiveKit URL** first if your setup is not `ws://127.0.0.1:7880`.

3. **NAT / TURN**: WebRTC needs ICE reachable. On a VPS with a public IP,
   LiveKit's built-in TURN covers most cases; for strict networks add
   **coturn** (`apt install coturn`) and point `turn.udp_port`/`turn.tls_port`
   at it. Firewall: **UDP 7880**, **TCP 7881**, **UDP 50000–50200**, plus
   coturn **UDP/TCP 3478** if used.

4. **TLS**: serve `wss://` — either terminate at LiveKit (`https_port`) with
   the site's Let's Encrypt cert or reverse-proxy `/` and `/rtc` through the
   existing nginx. Set **LiveKit URL** in Admin → Voice to the `wss://` URL.

5. **Restart on reboot**: a `nohup` process doesn't survive reboots. Re-run the
   autoconfigure button after a server restart, or add a cron `@reboot` entry
   for the site user: `livekit-server --config <ROOT>/data/livekit/livekit.yaml`.

Room settings apply at runtime — no daemon restart is needed for
`max_participants`, talker caps, or bitrate changes.

## Admin

**Admin → Voice (LiveKit)** (added by this module's manifest `admin` block):
- Master switch, LiveKit URL, API key/secret (write-only)
- **Generate & autoconfigure keys** — generates a strong key pair, writes it to
  the user-space config (`data/livekit/livekit.yaml`, preserving existing keys),
  starts/restarts `livekit-server` as the site user with nohup (pid in
  `data/livekit/livekit.pid`), and enables voice. Degrades with a clear message
  when the binary isn't installed or the port is taken by an instance the user
  can't manage.
- `voice_max_users` — global concurrent-voice cap (join gate + room max)
- `voice_talker_cap` — active speakers each listener hears (downlink bound)
- Quality preset / bitrate
- Live status panel: `/health` probe, managed-process pid, config path, binary

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
after `call_ring_seconds` (default 20 s) and the caller is told "no answer";
voice sessions without a heartbeat expire after 2 min.

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

## Device settings & video background

Every client exposes a **Voice & video settings** modal (gear in the header /
voice pane): microphone, camera and speaker device selection (persisted per
machine in `localStorage`), a **camera test** (live `getUserMedia` preview,
independent of LiveKit) and a **microphone test** (live input-level meter).
Device prefs are applied at connect/toggle time via `setMicrophoneEnabled` /
`setCameraEnabled` / `setSinkId`.

**Background effects** (blur or a custom image behind you) use a lazily-loaded,
self-hosted MediaPipe selfie-segmentation build (`assets/vendor/selfie-segmentation/`,
also mirrored under `messenger-web/src/vendor/` and `renderer/vendor/`). A custom
LiveKit `TrackProcessor` composites the segmented person over the blurred or
image background; MediaPipe is only fetched while an effect is active and runs
entirely in the browser. Effects apply whenever your camera is on (1:1 calls,
channel voice, meetings).

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

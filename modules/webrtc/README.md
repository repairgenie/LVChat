# WebRTC Voice & Meetings (LiveKit) — module

Audio calls, channel voice, video, and meetings for LVChat, backed by a
self-hosted [LiveKit](https://github.com/livekit/livekit) SFU. This module is
the server half: auth, room mapping, the capacity gate, host moderation, the
waiting room, and recording live here; every client (web app, desktop,
messenger, messenger-web) consumes the same `/api/webrtc/*` contract and runs
the same canonical voice client. See `docs/webrtc-implementation.md` for the
full design.

## Files

```
module.json                     manifest (settings, admin entry, assets)
init.php                        boot hook — loads service + controllers
routes.php                      /api/webrtc/voice|call|moderate|record/*, /api/events/*
schema.php                      idempotent: voice/call/participant/recording tables + flags
LiveKitService.php              JWT mint (HS256), Twirp admin API, health, caps, rate limits
VoiceController.php             voice join/leave/status + per-channel toggle
CallController.php              1:1 + group calls (initiate/invite/accept/decline/join/end)
ModerationController.php        kick/mute/mute-all/lock + waiting-room admit/deny
RecordingController.php         LiveKit egress (start/stop + authenticated download)
EventController.php             #evt-* meetings: create / invite / scheduled / keyed landing
AdminVoiceController.php        Admin → Voice settings + save
views/admin/voice.php           settings form + status panel + live rooms + recordings
assets/                         voice.css, voice.js, voice-host shim, vendor/ (livekit, selfie-seg)
README.md                       this file
```

## Deploy (server)

The module runs LiveKit **as the site user** — the same way the WebSocket
gateway runs. Config, pidfile, and log live under the app's own `data/livekit/`
(owned by the web user, never needing root), spawned with `nohup`. This works
on shared hosting (HestiaCP etc.); no `/etc`, no systemd, no sudo.

1. **Install the LiveKit binary** somewhere on the site user's PATH:
   ```bash
   curl -sSL https://get.livekit.io | bash          # installs to /usr/local/bin
   ```
   or drop a static release under `~/.local/bin/livekit-server` (checked
   automatically). The autoconfigure button reports the binary it found.

2. **Admin → Voice → Generate & autoconfigure keys**: the button generates a
   strong API key + secret, writes `data/livekit/livekit.yaml`, starts
   `livekit-server --config …` as the site user with nohup, and enables voice.

3. **NAT / TURN**: on a VPS with a public IP, LiveKit's built-in TURN covers
   most cases; for strict networks add **coturn** and point it at the
   `turn.udp_port`/`turn.tls_port` settings. Firewall: **UDP 7880**, **TCP
   7881**, **UDP 50000–50200**, plus coturn **UDP/TCP 3478** if used.

4. **TLS**: serve `wss://` — terminate at LiveKit (`https_port`) with the
   site's Let's Encrypt cert, or reverse-proxy `/` and `/rtc` through nginx.
   Set **LiveKit URL** in Admin → Voice to the `wss://` URL.

5. **Recording (optional)**: install the `livekit-egress` binary + a Redis,
   add an `egress:` block to `data/livekit/livekit.yaml`, and enable
   "Allow meeting recording" in Admin → Voice. Without egress, the Record
   button reports "not available" gracefully — nothing else changes.

6. **Restart on reboot**: re-run autoconfigure, or add a cron `@reboot` entry.

Room settings apply at runtime — no daemon restart needed for `max_participants`,
talker caps, or bitrate changes.

## Admin

**Admin → Voice (LiveKit)**:
- Master switch, LiveKit URL, API key/secret (write-only)
- Generate & autoconfigure keys (user-space config + nohup daemon)
- `voice_max_users` — global concurrent-voice cap (join gate + room max)
- `voice_talker_cap` — active speakers each listener hears (downlink bound)
- Quality preset / bitrate, ring timeout
- Recording toggle + storage path
- Status panel: `/health` probe, connected users, live rooms (LiveKit admin
  API, cached a few seconds), recordings list

## API contract

```
GET  /api/webrtc/voice/status                 → status + session (waiting/roster/mint) + calls + recording
POST /api/webrtc/voice/join {channel}         → {ok, url, token, room, talker_cap, bitrate} | {ok, waiting:true}
POST /api/webrtc/voice/leave                  → {ok}
POST /api/webrtc/voice/channel-voice          → ops+ toggles channels.voice_enabled

POST /api/webrtc/call/initiate {user}         → {ok, call_id, room, peer}
POST /api/webrtc/call/invite {call_id, users} → host grows the call into a group call
POST /api/webrtc/call/accept {call_id}        → join payload (callee or any invited participant)
POST /api/webrtc/call/decline {call_id}       → {ok}
POST /api/webrtc/call/join {call_id}          → re-enter an active call (token)
POST /api/webrtc/call/end {call_id}           → hang up (host ends for everyone)

POST /api/webrtc/moderate {room, action, identity?, value?}
      action: kick|mute|unmute|mute_all|unmute_all|lock|unlock|admit|deny|waiting_room
POST /api/webrtc/record {room, action: start|stop}   → host-only, gated by recording_enabled
GET  /api/webrtc/recordings/{id}              → authenticated MP4 download (admin/starter)

POST /api/events/create                       → meetings (#evt-*): public/private, webrtc/link, scheduled, waiting room
POST /api/events/invite                       → email invites (tokens)
POST /api/events/cancel                       → founder cancels
GET  /api/events/list                         → founder's scheduled/active events
GET  /e/{token}   GET  /event/{slug}          → invite/landing pages
```

Auth: browser session + CSRF, or the messenger bearer token (`X-LVC-Session`,
CSRF-safe). Tokens are LiveKit JWTs (HS256, 60 s TTL) minted by this server —
the API secret never reaches a browser. Unanswered calls expire to `missed`
after `call_ring_seconds`; voice sessions without a heartbeat expire after
2 minutes; a scheduled `VoiceCleanupJob` purges old rows and pushes missed-call
notifications.

## Features (Slack/Mattermost/Discord/Zoom-style)

- **1:1 calls** — ring / accept / decline / end with a 20 s ring timeout,
  missed-call OS push (Web Push, once per call).
- **Group calls** — the host grows any active call via `/call/invite`
  (Discord-style); invited participants see it as an incoming call and accept
  into the room. A member hanging up leaves the call; the host ends it for all.
- **Channel voice** — per-channel voice rooms (ops+ enables), active-speaker
  talker cap (selective subscription), voice presence in the member list.
- **Video** — camera + screen sharing (with optional system audio), per-user
  device selection, camera/mic test tools, background blur/custom image
  (lazy MediaPipe selfie-segmentation).
- **Waiting room** — hosts can enable a Zoom-style lobby per channel or per
  event; joiners wait (publish-gated) until admitted; the status poll hands the
  admitted user a fresh token (mint handoff).
- **Moderation** — hosts kick/mute individual participants, mute everyone,
  or lock a room (blocks new joins at the gate). Authority: ops+ on channels,
  event founder on events, call host on calls. Server-side kick/mute use
  LiveKit's admin API (Twirp).
- **Recording** — LiveKit egress (room composite MP4), host-toggled, with
  authenticated download and an admin list.
- **Call stats & quality** — per-participant connection-quality dots (good /
  fair / poor) plus live roster with speaker rings.
- **Reactions & raise hand** — emoji bursts over the data channel and a ✋
  attribute badge (Zoom/Discord-style).
- **Deafen, minimize, layouts** — Discord-style deafen toggle, minimize the
  pane to a floating video pill, and auto/speaker/gallery layouts; `M` toggles
  the mic while in voice.
- **Noise suppression controls** — echo cancellation / noise suppression /
  auto gain toggles in settings (browser-side constraints).
- **Events / scheduled meetings** — public or private `#evt-*` rooms with
  email invites, invite codes, scheduled start, and an end-of-meeting chat log.
  A founder "My events" list (copy link / cancel) lives in the events modal.

## Clients

All four clients run the **same** `voice.js`/`voice.css` (the module assets are
the single source; the messenger and messenger-web copies are synced copies).
Each shell supplies a tiny `LVCVoiceHost` adapter for its transport + DOM:

- **Web app**: default host — cookie+CSRF `fetch`, `body[data-channel|data-dm]`,
  Tailwind header hook. Auto-injected by the module manifest.
- **Desktop**: loads the server's `/app` page — gets the web voice UI
  automatically (its e2e exercises the contract).
- **Messenger (Electron)**: `renderer/voice-host.js` adapts `LvApi` + the
  `#chat-title`/`.chat-head-actions` DOM. Vendored `livekit-client.umd.js`,
  voice.js, voice.css, selfie-segmentation ship in `renderer/`.
- **Messenger Web (PWA)**: `src/voice-host.js` + the same synced assets; the
  build (`build.js`) copies and precaches them (voice endpoints stay
  network-only in the SW).

## Device settings & video background

Every client exposes a **Voice & video settings** modal: microphone, camera and
speaker selection (persisted per machine), camera test (live `getUserMedia`
preview), mic test (input-level meter), audio-processing toggles, and the
background effect picker (blur or custom image) powered by lazy-loaded,
self-hosted MediaPipe selfie-segmentation.

## Meetings / events (#evt-*)

`POST /api/events/create` makes a `#evt-<32 hex>` channel (private by default,
invite-only, voice enabled, optional waiting room). Scheduled events create the
channel at start time (cron); the founder can email invites with per-person
tokens; `/e/<token>` and `/event/<slug>` landings auto-join members and bounce
logged-out visitors through login. Meetings are unregistered channels, so they
vanish when empty. Cancel kicks everyone and soft-deletes the channel.

## Known limits / follow-ups

- Talker-cap enforcement is client-side (selective subscription); the server
  returns the cap but a custom client could bypass the downlink bound.
- Server-side "mute" is track-level (Zoom-style); Discord-style "server mute"
  that prevents re-publish would need `can_publish` permission flips per room.
- Ring/accept is poll-driven via `/api/webrtc/voice/status` (works on all
  transports); OS push for incoming calls (not just missed) is a follow-up.
- Recording needs `livekit-egress` + Redis deployed; the app degrades to a
  friendly 503 when they're absent.
- No per-channel capacity — `voice_max_users` is global across all rooms.

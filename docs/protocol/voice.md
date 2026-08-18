# Voice, Calls & Meetings — protocol (`/api/webrtc/*`)

LVChat's realtime voice is delivered by a self-hosted **LiveKit** SFU. This
page documents the *control plane* — the HTTP API every client uses to join,
ring, moderate, and record. The LVChat server mints short-lived LiveKit JWTs;
clients connect with the official `livekit-client` JS SDK (vendored UMD in
`modules/webrtc/assets/vendor/`).

All endpoints are served by the `webrtc` module (`modules/webrtc/routes.php`),
auth'd by the browser session + CSRF (POSTs) or the messenger bearer token
(`X-LVC-Session`, CSRF-safe). JSON bodies: `{ok:true, …}` on success; failures
return `4xx/5xx` with `{error: "…"}`. Guests may use voice + calls; events and
admin pages require registered users.

## Identity

Every actor maps 1:1 to a LiveKit identity: `u<id>` (registered user) or
`g<id>` (guest) — `LiveKitService::identity()`. Joins are single-use: one
`voice_sessions` row per actor, one `max_participants` capped room, tokens
55–60 s TTL.

## Voice (channel) — `/api/webrtc/voice/*`

```
GET  /api/webrtc/voice/status
POST /api/webrtc/voice/join         {channel}
POST /api/webrtc/voice/leave
POST /api/webrtc/voice/channel-voice {channel, enabled}   (ops+)
```

`status` drives **everything**: the module gate (`enabled`), the capacity
counter (`active/max/full`), the caller's own `session`, and the call ring
state. Poll it every ~2 s. Response shape:

```jsonc
{
  "ok": true, "enabled": true, "active": 3, "max": 50, "full": false,
  "talker_cap": 8, "bitrate": 40000, "ring_seconds": 20,
  "recording": { "enabled": true, "active": null },
  "channels": [{ "slug": "general", "name": "#general", "voice_enabled": true }],
  "session": {
    "room": "chan:general", "kind": "channel", "waiting": false, "locked": false,
    "can_moderate": false,
    "roster": [{ "identity": "u5", "name": "alice", "guest": false, "waiting": false, "me": true }],
    "mint": null   // {url, token, room} — single delivery after waiting-room admit
  },
  "calls": { "incoming": [], "outgoing": [], "active": null, "recent": [] }
}
```

**Join** returns the LiveKit payload the client connects with:
`{ok, url, token, room, talker_cap, bitrate, can_moderate}`. If the room's
waiting-room flag is on (and the caller can't moderate), it returns
`{ok:true, waiting:true, room}` instead — **no token yet**. The client shows a
lobby; the host's status `session.roster` lists the waiting occupant; host
actions `admit`/`deny` (`/api/webrtc/moderate`) decide. An admitted occupant's
next status poll delivers `session.mint = {url, token, room}` exactly once —
the client then connects normally. A denied occupant simply loses their
session row.

Capacity: `voice_max_users` is enforced at the join gate (409 "Voice is
full") *and* stamped into every room token as LiveKit's `maxParticipants`
(hard ceiling even if the gate is bypassed). Waiting occupants hold session
rows but don't consume the cap.

## Calls (1:1 + groups) — `/api/webrtc/call/*`

```
POST /api/webrtc/call/initiate {user}           → {ok, call_id, room, peer, ring_seconds}
POST /api/webrtc/call/invite    {call_id, users} → {ok, added[], unknown[], busy[]}
POST /api/webrtc/call/accept    {call_id}        → join payload (callee / invited participant)
POST /api/webrtc/call/decline   {call_id}        → {ok}
POST /api/webrtc/call/join      {call_id}        → join payload (re-enter active call)
POST /api/webrtc/call/end       {call_id}        → {ok}   (host ends for all)
```

State machine: `ringing → active | declined | missed | cancelled | ended`.
Ring/accept is **poll-driven** through `status` (`calls.incoming/outgoing/
active/recent`); unanswered calls expire to `missed` after `ring_seconds`.
Blocked/muted callers are silently flipped so they never disturb the callee.

**Group calls**: the caller is the **host**. Once active, `invite` adds more
participants (any actor — registered or guest, not the caller, not already on
the call, not busy in another live call). Invitees see the call under
`calls.incoming` (tagged `group:true`) and `accept` into the room. Hanging up:
host ends for everyone; a member in a group call just leaves, and the call
survives while ≥1 participant remains. The roster (via `status.session.roster`)
shows everyone connected.

## Moderation & waiting room — `/api/webrtc/moderate`

```
POST /api/webrtc/moderate {room, action, identity?, value?}
```

| action | effect |
|---|---|
| `kick {identity}` | LiveKit RemoveParticipant + drop the session row; empty rooms are force-deleted |
| `mute` / `unmute` | host-mute a participant's audio track (LiveKit MutePublishedTrack) |
| `mute_all` / `unmute_all` | mute every publisher in the room |
| `lock` / `unlock` | join gate blocks non-moderators (room flag, `voice_room_flags`) |
| `admit {identity}` | lets a waiting-room occupant in (re-mints token → `session.mint`) |
| `deny {identity}` | rejects the waiting occupant (session dropped) |
| `waiting_room {value}` | ops+/founder toggles the lobby on this channel/event room |

Authority is room-shaped: **ops+** on channel rooms (`ChannelService::
canManageChannel`), the **event founder** on event channels, the **caller
(host)** on call rooms. All actions are audit-logged (`log_audit('voice_*')`)
and rate-limited (120/min per actor).

## Recording — `/api/webrtc/record` + downloads

```
POST /api/webrtc/record {room, action: "start"|"stop"}
GET  /api/webrtc/recordings/{id}
```

Gated by the admin `recording_enabled` flag and host authority (same shape as
moderation). Start asks LiveKit's **egress** service (via the server's Twirp
proxy) to composite the room to an MP4 under `data/recordings/` (or the
configured `recording_path`); the app tracks the egress id in `recordings`.
If egress/Redis isn't deployed, StartEgress errors → friendly `503 "recording
not available"`. Stop is idempotent. Download streams the file with auth: the
starter or an admin; everyone else gets 404. `VoiceCleanupJob` reconciles
finished egresses and sizes files for the admin panel.

## Events / meetings — `/api/events/*`

```
POST /api/events/create {title, description?, is_public?, event_type?, stream_url?, scheduled_at?, duration_minutes?, waiting_room?}
POST /api/events/invite  {event_id, emails}
POST /api/events/cancel  {event_id}
GET  /api/events/list
GET  /e/{token}   ·   GET /event/{slug}
```

Events create `#evt-<32 hex>` channels (private + invite-only by default,
voice enabled). `link` events embed a stream URL instead. Scheduled events are
created by the cron `EventSchedulerJob` at start time; the founder gets a
reminder email 15 min early; a chat-log ZIP is emailed at end. Private events
get a URL-safe invite code and per-email invite tokens. `waiting_room: 1`
mounts the lobby on the meeting (host = founder admits via `/moderate`).
Rate-limited per actor.

## Rate limits

| bucket | budget |
|---|---|
| `voice-join:<identity>` | 12 / min |
| `call-initiate:<identity>` | 6 / min |
| `call-invite:<identity>` | 20 / min |
| `voice-moderate:<identity>` | 120 / min |
| `event-create:<user_id>` | 10 / hour |
| `event-invite:<user_id>` | 30 / hour |

Over budget → `429`. Buckets live in `rate_limits` and are purged by
`VoiceCleanupJob`.

## Cleanup

`VoiceCleanupJob` (cron, every minute, via `bin/scheduledjobs.php`):
- pushes "missed call" OS notifications exactly once (`missed_pushed` flag),
- purges finished calls older than 24 h (chat_logs keeps the history),
- purges orphaned voice sessions, rate-limit buckets, stale landing limits,
  and room flags for empty rooms,
- reconciles finished egresses and sizes recording files.

## Example flows

### Join channel voice
```
POST /api/webrtc/voice/join {channel: "general"}
→ {ok, url: "wss://…", token: "<jwt>", room: "chan:general", talker_cap: 8}
Room.connect(url, token, {}) → localParticipant.setMicrophoneEnabled(true)
```
Leave: `room.disconnect()` then `POST /api/webrtc/voice/leave` (heartbeat drops
the session within 2 min either way).

### 1:1 call
```
A: POST /api/webrtc/call/initiate {user: "bob"}   → {call_id, room}
A: status … "outgoing: [{peer: bob, …}]"
B: status … "incoming: [{call_id, peer: alice}]" (rings; ~20 s → missed)
B: POST /api/webrtc/call/accept {call_id}         → {url, token, room}
A: status … "active" → POST /api/webrtc/call/join → token
either: POST /api/webrtc/call/end → both disconnect; chat_logs gets the outcome + duration
```

### Group call (Discord-style)
```
A: POST /api/webrtc/call/initiate {user: "bob"} → B accepts → active
A (host): POST /api/webrtc/call/invite {call_id, users: "carol"} → {added: ["carol"]}
C: status → calls.incoming has the call (group:true) → POST /api/webrtc/call/accept → joins room
C: POST /api/webrtc/call/end → leaves; call stays active while A or B remain
A: POST /api/webrtc/call/end → everyone's room emptied, call ended
```

### Waiting room
```
admin: POST /api/webrtc/moderate {room:"chan:general", action:"waiting_room", value:1}
B: POST /api/webrtc/voice/join → {ok, waiting:true}   (no token; lobby UI)
admin (in room): status → session.roster has B with waiting:true
admin: POST /api/webrtc/moderate {room, action:"admit", identity:"u<id>"}
B: status → session.mint {url, token, room}       (once — client connects)
```
Denied: `action:"deny"` → B loses the session → "host declined your request".
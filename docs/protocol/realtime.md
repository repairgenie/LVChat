# LVChat — Realtime Transports

Clients receive new messages through one of three realtime transports. The
server advertises which one to use (`data-rt` on the chat `<body>`), and the
client degrades **ws → SSE → poll** on failure. Every transport funnels into
**one shared handler** (`handleRealtime` in `public/assets/js/app.js`), so the
payload schema is identical everywhere — that is the contract documented in
[http-api.md](http-api.md) §2.

```
                        ┌───────────────────────────┐
   data-rt = "ws"  ───▶ │  WebSocket gateway daemon  │  instant fan-out
                        │  (bin/ws-server.php)       │
                        └───────────────────────────┘
                                      ▼
   data-rt = "sse" ───▶  GET /api/stream (EventSource)  ──┐
                                      ▼                    │ handleRealtime
   data-rt = "poll" ──▶ GET /api/poll (2s jittered loop) ──┘
```

---

## 1. Polling (default)

### Query

```
GET /api/poll?since=<id>&channel=<slug>&dm=<nick>&bg_since=<id>
```

| Param | Meaning |
|---|---|
| `since` | the client's delta watermark for the conversation it's viewing |
| `channel` | slug of the channel being viewed (omit for DM view) |
| `dm` | nick of the DM partner (omit for channel view) |
| `bg_since` | global watermark for background-channel messages (audio alerts) |

### Scheduling

- Base interval comes from `poll_interval` (default 2 s) via `data-poll-ms`.
- **Jitter**: every poll is delayed `base + rand(0 … base*0.25)` so client
  requests spread out instead of bursting together (protects the shared-hosting
  worker pool).
- **Backoff**: after 3 consecutive failures the interval doubles; after 8 it
  becomes 5×; it recovers automatically when polls start succeeding.

### Handler

The response is the poll payload from [http-api.md](http-api.md) §2. The
client advances `lastId` from the `messages` it actually appends; it dedupes
background messages with a `bgSeen` set (SSE reuses a fixed query string, so
the same message can arrive via both paths).

---

## 2. SSE (`GET /api/stream`)

A single long-lived stream that pushes the **same payload** as polling on a
loop, with these specifics:

- Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
  `X-Accel-Buffering: no` (disables nginx proxy buffering), `Connection:
  keep-alive`; `zlib.output_compression` is switched off.
- **Frame format**: every event is a `data:` line followed by a blank line
  (`data: {json}\n\n`). There is no named event; clients use `onmessage`.
- **Dedupe**: a frame is only sent when the JSON payload changed from the last
  one; otherwise a **15-second keepalive** is sent as `data: : keepalive`
  (client ignores it).
- **Presence refresh**: the server writes `last_seen` inside the loop on the
  `presence_throttle` cadence, so long-lived streams never go stale.
- **Watermark advance**: after each tick the server advances its internal
  `since` to the highest message id in the batch — *unless* the batch hit the
  100-row cap, in which case it holds `since` so a busy channel never drops a
  message (next tick keeps catching up).
- **Lifetime**: capped at **1 hour**, and the script exits promptly when the
  client disconnects (`connection_aborted()`), so idle streams never pin a PHP
  worker forever.

Each SSE connection holds one PHP worker for its entire lifetime — this is why
SSE targets php-fpm/VPS and not shared hosting.

### Reconnect

On `es.onerror` the client closes the EventSource and reopens it after
`pollMs * 3`. Because the URL is fixed (including `since`), the dedupe sets in
`handleRealtime` prevent double-rendering.

---

## 3. WebSocket

### 3.1 Handshake

1. The chat page embeds `data-rt-ticket` (one-time ticket, 60 s TTL) and
   `data-ws-url` (e.g. `wss://chat.example.com:8080/`). The host used to build
   that URL comes from `APP_URL` / `TRUSTED_HOSTS` when set — the client `Host`
   header is never trusted on its own (see `docs/installation.md` §3.4.1).
2. The client opens `ws(s)://host:port/?ticket=<token>`.

   ```js
   new WebSocket(wsBase + (wsBase.includes('?') ? '&' : '?') + 'ticket=' + encodeURIComponent(wsTicket));
   ```

3. The gateway validates:
   - `Origin` against `ws_allowed_origin` config (when set) — mismatch closes.
   - The ticket via `Realtime::consumeTicket()` — invalid/expired closes.
4. The ticket is **single-use**: redeemed and deleted on connect. A reconnect
   mints a fresh ticket via `GET /api/ws/ticket`.

### 3.2 Client → server actions

All client frames are JSON objects with an `action` field:

```json
{ "action": "hello" }
{ "action": "ping" }
{ "action": "subscribe", "channel": "general" }
{ "action": "subscribe", "dm": "bob" }
```

| Action | Server response | Purpose |
|---|---|---|
| `hello` | `{"ok":true,"id":<actor id>,"nick":"<username>","guest":0\|1}` | Identity confirmation. |
| `ping` | `{"pong":true}` | Presence heartbeat. The client sends one every **30 s**; the gateway writes `last_seen` on a throttled cadence (`presence_throttle`), keeping the existing `isOnline()` checks accurate. |
| `subscribe` | `{"ok":true,"sub":{"type":"channel"\|"dm","target":"<slug or nick>"}}` | Set the connection's single subscription. The gateway routes pushed messages by this. |

A client sends `subscribe` immediately on `onopen`. Any other action receives
`{"ok":false,"error":"unknown action"}`.

### 3.3 Server → client frames

Server frames are the **poll payload shape** plus two transport-specific keys:

```json
{ "messages": [ { /* channel or PM message */ } ] }
{ "notify_count": 3 }
{ "msg_update": { "action": "edit"|"delete"|"reaction", "message_id": 1042,
                  "content": "…", "reactions": [ {"emoji":"👍","count":2} ] } }
{ "reconnect": true }
```

| Key | Meaning |
|---|---|
| `messages` | Exactly one new message (channel or DM), routed to subscribers of that channel/DM. Same bytes the writer's `/api/send` returned. |
| `notify_count` | A user's bell count changed (all their tabs refresh it). |
| `msg_update` | A channel message was edited / deleted / reacted. The client patches the message element in view. |
| `reconnect` | Admin-forced reconnect: every tab reloads to pick up a new gateway config. |
| `pong` | Reply to a client `ping` (client ignores it). |

Because `messages` and `msg_update` reuse the poll shape, `onmessage` just
calls the same `handleRealtime(j)` — `{pong:true}` is the only frame filtered
beforehand.

### 3.4 Connection lifecycle

- **Heartbeats**: `ping` every 30 s keeps presence fresh and proxies happy.
- **Idle sweep**: the gateway closes any connection that hasn't sent a frame
  in 90 s (no pong).
- **Reconnect**: on close, the client backs off (`pollMs * (fails + 1)`) and
  mints a fresh ticket before reopening, because tickets are single-use and
  60 s short-lived. After 3 consecutive failures it gives up and falls back.
- **Plain-ws fallback**: if the secure (`wss://`) handshake fails 3 times on a
  **non-secure page**, the client tries the plain `ws://` variant once per
  retry cycle. On an HTTPS page the browser hard-blocks `ws://` as mixed
  content, so the offline state is the honest outcome.
- **Re-probe**: after giving up, the client quietly re-tries every 5 minutes
  so a later proxy fix / daemon restart re-enables realtime.
- **HTTP reconcile**: while the socket is open, the client also runs a
  **30-second poll** (only if the socket is `OPEN`) to refresh the sidebar
  summaries (DM list, unread badges, presence counts, friends, bell) without a
  per-event push for each.
- **Force mode**: when `data-rt-force=1` (server `realtime_force`), a broken
  gateway never silently downgrades. The client shows the loud "websocket
  offline" badge and keeps retrying; it reports `transport=none`.

### 3.5 Transport reporting

The client calls `POST /api/rt/report` every time its live transport changes
(`ws` / `sse` / `poll` / `none`). This powers the admin panel's per-transport
counters so a "WebSockets configured but everyone on polling" silent fallback
is visible rather than a phantom 0 in the gateway's connection count.

---

## 4. Fallback ladder summary

```
rtMode = data-rt
if ws available:
    setupWs( fallback = startPolling() )     # or RT_FORCE: stay offline
else if rtMode == sse and EventSource:
    openStream()                             # onerror -> reopen after pollMs*3
else:
    startPolling()                           # jittered, backs off on failure
```

- ws → SSE → poll is *not* a full ladder at runtime: WebSocket mode falls
  straight to polling (not SSE); SSE mode falls to polling. The gateway itself
  is the only "instant" transport.
- Every fallback is silent (badge shows the actual transport) except in force
  mode.

# LVChat — Chat Protocol

LVChat is a **Discord-style IRC web chat** written in PHP + SQLite. This
directory documents the wire-level chat protocol: how clients authenticate,
send messages, receive them in realtime, run IRC-style slash commands, and how
the optional WebSocket gateway fans messages out to connected browsers.

The chat is **not an IRC server**. There is no IRC protocol, no IRC daemon, and
nothing for an IRC client to connect to. The IRC-style commands, channel modes,
access levels, and services are emulated natively over plain HTTP(S) + JSON
(with an optional WebSocket transport for low-latency delivery). This
documentation describes that native protocol.

---

## Architecture in one paragraph

Every client is a web page (or the Electron desktop clients, which are the web
app in a browser window). The client authenticates with a **cookie session**,
**writes** messages via small JSON POST endpoints (`/api/send`, `/api/upload`,
`/api/command`), and **receives** messages through one of three realtime
transports:

1. **Polling** (`GET /api/poll`) — the shared-hosting default, a jittered
   2s AJAX loop.
2. **Server-Sent Events** (`GET /api/stream`) — one long-lived stream that
   pushes the *same* payload shape as polling.
3. **WebSocket** (`/api/ws/ticket` + the Workerman gateway daemon) — the
   gateway holds one connection per client and fans messages out the moment
   they are persisted, off PHP-FPM entirely.

The client **falls back ws → SSE → poll** automatically on failure, and every
transport funnels into the **same handler** (`handleRealtime`), so the payload
schema is shared. The three transports are documented in
[realtime.md](realtime.md).

Writes go through the request layer (php-fpm). After a message is persisted,
the request layer *pushes* a small event over HTTP to the gateway daemon's
internal endpoint, which routes it to the right subscribers. This internal
push protocol (php-fpm → daemon) is documented in [gateway.md](gateway.md).

---

## The three protocol "channels"

| Channel | Direction | Transport | Documented in |
|---|---|---|---|
| **Authentication** | client → server | HTTP cookie session + CSRF; WebSocket uses a one-time handshake ticket | [authentication.md](authentication.md) |
| **Writes** | client → server | HTTP `POST` JSON (multipart for uploads) | [http-api.md](http-api.md) |
| **Realtime reads** | server → client | poll / SSE / WebSocket, one shared payload schema | [realtime.md](realtime.md) |

---

## Actors

Every connected participant is an **actor**. There are two kinds:

- **Registered user** — lives in the `users` table, has a username, password
  hash (argon2id), role, avatar, etc.
- **Guest** — an anonymous, age-gated participant who "joins as guest" with
  just a nickname. Guests live in the **`guests`** table, never in `users`.
  In JSON payloads a guest is marked `"guest": 1` and uses a guest id.

The actor abstraction matters everywhere: a message sender, DM participant,
channel member, or presence entry is identified by *either* a `user_id`
(*user*) *or* a `guest_id` (*guest*), and the JSON shapes always carry the
`guest` flag so clients can tell them apart. See
[messages.md](messages.md) for the shapes and [authentication.md](authentication.md)
for the guest login flow.

---

## Data model (message shapes, watermarks, kinds)

The message schema is shared by every read path — initial page render,
polling, SSE, WebSocket fan-out, history pagination, and search:

- Channel messages (22 fields, including sender/level/role decoration, reply
  metadata, and reactions)
- Private messages (a trimmed variant with `is_pm: true`)
- Message kinds: `message`, `action`, `image`, `gif`, `ai_response`, plus 11
  system kinds (`join`, `part`, `quit`, `kick`, `ban`, `topic`, `mode`,
  `nick`, `system`, `notice`)

**Watermarks** are the entire pagination story: `since` (delta fetch), `before`
(older history), `bg_since` (background-channel audio watermark). All are
documented in [messages.md](messages.md).

---

## Realtime transport selection (server config)

| Config key | Default | Meaning |
|---|---|---|
| `realtime` | `poll` | `poll` \| `sse` \| `ws` — which transport the server advertises |
| `realtime_force` | `0` | When `realtime = ws`, force clients to stay on WebSocket (never silently fall back) |
| `poll_interval` | `2` | Seconds between polls / SSE ticks |
| `presence_throttle` | `30` | Seconds between `last_seen` database writes per user |
| `ws_port` | `8080` | Port the gateway listens on for chat clients |
| `ws_push_url` | `http://127.0.0.1:9001/push` | Internal endpoint php-fpm posts events to |
| `ws_url` | derived | Public WS URL the browser connects to (else derived from `HTTP_HOST`) |
| `ws_ip` / `WS_IP` | `0.0.0.0` | Gateway bind address |
| `ws_ssl_cert` / `ws_ssl_key` | — | Enable WSS (TLS) on the gateway socket |

The boot page embeds the transport decision in `data-*` attributes on the
chat `<body>`:

| Attribute | Contents |
|---|---|
| `data-rt` | `poll` \| `sse` \| `ws` |
| `data-rt-force` | `1` when WebSocket fallback is disabled |
| `data-rt-ticket` | the one-time WS handshake ticket (WS mode only) |
| `data-ws-url` | the WS/WSS URL to connect to (WS mode only) |
| `data-poll-ms` | `poll_interval` in milliseconds (jitter base) |
| `data-bg-last` | highest channel message id rendered on this page (audio watermark seed) |

---

## Reading order

1. [authentication.md](authentication.md) — how a client proves who it is
   (sessions, CSRF, MFA, guests, WS tickets)
2. [messages.md](messages.md) — the message data model every endpoint returns
3. [http-api.md](http-api.md) — the REST endpoints clients call to write,
   read, and manage state
4. [realtime.md](realtime.md) — how messages arrive without refreshing:
   polling, SSE, WebSocket framing and actions
5. [gateway.md](gateway.md) — the WebSocket daemon and the internal
   php-fpm → daemon push protocol
6. [commands.md](commands.md) — the slash-command protocol (parser,
   result/event shapes, command catalog)
7. [presence.md](presence.md) — online/away/presence bookkeeping
8. [errors.md](errors.md) — status codes, JSON error shapes, rate limits,
   moderation gates

---

## Quick reference

| You want to… | You call… |
|---|---|
| Post a channel message | `POST /api/send` (`channel`, `content`, optional `reply_to`) |
| Post a private message | `POST /api/send` (`recipient`, `content`) |
| Upload an image message | `POST /api/upload` (multipart `file`, `channel` or `dm`) |
| Run a slash command | `POST /api/command` (`text`, `channel`) |
| Fetch new messages | `GET /api/poll?since=&channel=&dm=&bg_since=` |
| Stream live (SSE) | `GET /api/stream?...` (same params as poll) |
| Open a WebSocket | `GET /api/ws/ticket`, then `wss://host:port/?ticket=...` |
| Load older history | `GET /api/history?channel=<slug>&before=<id>` or `?dm=<nick>&before=<id>` |
| Search messages | `GET /api/search?q=` |
| Edit / delete a message | `POST /api/message/edit` / `POST /api/message/delete` |
| React to a message | `POST /api/message/reaction` (`id`, `emoji`) |
| Report a message | `POST /api/report` (`id`, `pm`, `reason`, `other`) |
| Join / part / create a channel | `POST /api/join` / `POST /api/part` / `POST /api/channels` |
| Check the session | `GET /api/me`, `GET /api/csrf` |

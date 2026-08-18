# LVChat — HTTP API (REST Catalog)

The chat's state-changing and read surfaces are plain HTTP endpoints under
`/api/*`. This page documents the ones a chat client uses: sending, the poll
payload, message lifecycle, channels, notifications, presence, friends,
messenger endpoints, and the settings toggles. (Webhooks, OpenClaw bots, push
subscriptions, and admin endpoints are covered in their own guides; the chat
client only touches the push/theme/sound endpoints listed at the end.)

## Conventions

- **Body encoding.** `POST` bodies are `application/x-www-form-urlencoded` or
  `multipart/form-data` (the JS client uses `FormData`; `Content-Type` is set
  by the browser). Webhooks/OpenClaw are the exception (raw JSON).
- **CSRF.** Every `POST` carries the session token in a `csrf` field or the
  `X-CSRF` header (see [authentication.md](authentication.md)); failure is
  HTTP **419**.
- **Host handling.** Absolute URLs in responses (redirects, link/magic-link
  construction) use `APP_URL` / `TRUSTED_HOSTS` when configured; the client
  `Host` header alone is never trusted (see
  [installation.md](../installation.md) §3.4.1).
- **Auth.** Every `/api/*` endpoint except `/api/version` requires a session;
  failure is **401** `{"error":"Not authenticated."}`.
- **Dual-mode responses.** `ChatController::finish()` answers **JSON** when the
  request includes `ajax=1`, otherwise it flashes the error and redirects back.
  This is the no-JS fallback: a message still delivers even with JavaScript
  disabled.
- **Moderation gates.** Sends first check `ModerationService::restriction()`
  (pending/suspended/suspended-with-reason) → **403**, then rate limiting
  (12 messages+DMs per 5 s) → **429**.
- JSON is encoded `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.

---

## 1. Sending

### `POST /api/send` — channel or DM message

Form fields:

| Field | Channel branch | DM branch |
|---|---|---|
| `channel` | slug, required | — |
| `recipient` | — | nick, required |
| `content` | required (≤ 2000 chars after trim) | required (≤ 2000) |
| `reply_to` | optional message id (must exist in the same channel) | — |
| `gif_url` | optional validated Giphy media URL | optional |
| `gif_title` | optional (≤ 300), stored under the GIF for search | optional |
| `ajax` | `1` to force a JSON response | same |

Validation order (channel branch):

1. Authenticated + CSRF + restriction gate + rate limit.
2. Channel exists → **404**; you are a member → **403**.
3. `BanService::canPost` (channel ban/mute/`+m` moderated) → **403**.
4. `BanService::sendBlocked` (spamfilter, shuns, global *lines) → **403**.
5. Channel `+C` word filter (when enabled): `censor` action rewrites content to
   `****`; `block` action posts then immediately deletes the row, posts a
   `system` "ChanServ removed message…" notice, and returns
   `{ok, message, blocked:true, notice}`.

DM branch additionally: recipient must exist → **404**; block either direction
→ **400**; global word filter applies always (no channel mode) with the same
`censor`/`block` semantics (`block` returns `{ok, blocked:true, notice}`).

Success (both branches):

```json
{
  "ok": true,
  "message": { "id": 1042, "kind": "message", "content": "hi", /* full channel or PM shape */ }
}
```

The returned `message` is the *exact* object later re-broadcast over the
WebSocket, so the sender's client renders it and other clients receive it from
the gateway with identical bytes. On the block path the extra fields
`"blocked": true, "notice": "…"` tell the client to flash the message and then
remove it.

### `POST /api/upload` — image message

Multipart `file` (5 MB cap, real MIME sniffing), plus `channel` or `dm`,
optional `caption` (≤ 300), optional `ajax`.

- Server downscales to max **1600 px** (GD, best-effort) and stores a
  random-named file under `public/uploads/`.
- Renders as `kind: "image"`; content = URL line + optional caption line.
- Runs the same restriction/rate-limit/ban/spamfilter/word-filter pipeline as
  `send`.
- Returns `{ok: true, message}` (channel) or `{ok: true, message}` (DM).
- `uploads_enabled` config off → **403**.

### `POST /api/command` — slash command

Form fields: `text` (must start with `/`), `channel` (slug, optional — commands
that need a channel resolve it from the current view or a leading `#name` arg),
`ajax`.

Returns the command result merged with `ok: true`:

```json
{
  "ok": true,
  "replies": ["You have left #general."],
  "redirect": "/app",
  "events": [ { "channel_id": 1, "kind": "system", "content": "alice has left" } ]
}
```

The full command protocol is documented in [commands.md](commands.md).

### `GET /api/commands` — slash-command autocomplete list

Requires an authenticated session. Returns the command names the web chat
embeds as `data-commands`; messenger clients use it for identical `/`
autocomplete. The server remains the authority — this list is presentational.

```json
{ "commands": ["help", "join", "nick", "sanick", "sqline", "cqline", "…"] }
```

---

## 2. Receiving

### `GET /api/poll` — realtime payload (polling transport)

Query params: `since`, `channel` (slug), `dm` (nick), `bg_since`.

```json
{
  "ok": true,
  "messages": [ /* delta for the viewed channel or DM */ ],
  "presence": [ /* member list with live flags, when viewing a channel */ ],
  "notify_count": 3,
  "alerts": [ /* new unread notifications since this session's watermark */ ],
  "dm_list": [ /* live DM sidebar summaries */ ],
  "channel_unread": [ {"slug":"general","unread":2} ],
  "channel_mentions": [ {"slug":"general","mentions":1} ],
  "channel_presence": [ {"slug":"general","online":12} ],
  "bg_messages": [ /* new real messages in other member channels */ ],
  "typing": [ "alice" ],
  "mentions": [ /* unread notifications for the viewed channel */ ],
  "reconnect": 1
}
```

Field reference:

| Field | When present | Meaning |
|---|---|---|
| `messages` | always | Delta (`id > since`) for the viewed channel or DM. Empty when no conversation is open. |
| `presence` | channel view | Full member roster with `username`, `is_online`, `away`, `level`, `role`, `bot`, `guest`, `role_helper`, `role_color`, `avatar`. |
| `notify_count` | always | Unread count for the notification bell. |
| `alerts` | always | New unread notifications since this session's `lvc_alert_seen` watermark — the unified alert delta consumed by every surface (web toasts, desktop bridge, Messenger). Each item: `id`, `kind`, `sender`, `channel_id`, `channel_slug`, `channel_name`, `message_id`, `content`, `excerpt`, `created_at`. The first poll of a fresh session seeds the watermark silently (returns `[]`). |
| `dm_list` | always | Live DM sidebar (partner, unread, last preview) — see `dmSummaries`. |
| `channel_unread` | always | Unread badges per joined channel. |
| `channel_mentions` | always | Unread `@mention` notification counts per channel (`{slug, mentions}`) — drives the highlight vs. activity badge split. |
| `channel_presence` | always | Active-chatter count per joined channel. |
| `friends` / `friend_requests` / `channel_invites` | registered users | Sidebar friends + pending requests + channel invites. |
| `bg_messages` | always | Background-channel messages (`id > bg_since`, excludes the viewed channel and system kinds) — fuel for channel audio alerts. Each message also carries `notify_mode` (`"all"`/`"mentions"`/`"muted"`) and a `mentioned` boolean so clients gate sounds exactly like the server's push tier; channels the viewer muted (and muted senders) are excluded server-side. |
| `typing` | always | Usernames currently typing in the viewed conversation (grace window ≈8s; swept by `TypingService`). |
| `mentions` | channel view | Unread notifications scoped to the viewed channel (open-channel ping sound). |
| `dm` | DM view | The partner nick echoed back. |
| `channel` / `topic` | channel view | Slug + current topic echoed back. |
| `channel_url` / `url_banned` | channel view | The channel's embedded URL (null when unset or its domain is banned); `url_banned` is true when a stored URL is hidden by the global blocked-domains list. |
| `reconnect` | rare | Admin-forced "reconnect all clients": the client reloads to pick up a new gateway config. |
| `redirect` | edge | `{ok:true, redirect:"/app", reason:"You are no longer in this channel."}` when the user's membership vanished — client navigates. |

### `GET /api/stream` — SSE

Same payload as poll, pushed over one connection. Framing, keepalives, and the
watermark-advance rule are documented in [realtime.md](realtime.md).

### `GET /api/ws/ticket`

Returns `{ok:true, ticket, url}` — a fresh one-time WebSocket handshake ticket
plus the WS URL. Used by reconnecting clients.

### `POST /api/rt/report`

Fire-and-forget: `{transport: "ws"|"sse"|"poll"|"none"}`. The browser records
which transport actually won so the admin panel can surface silent fallbacks.
Stored in `rt_transports`.

---

## 3. Message lifecycle

| Endpoint | Fields | Behaviour |
|---|---|---|
| `POST /api/message/edit` | `id`, `content` (≤ 2000) | Owner (or admin) only; non-admins may edit within **5 minutes** of posting. Success: `{ok:true, content}`. Also fans an `msg_update` (edit) frame to viewers. |
| `POST /api/message/delete` | `id` | Owner or admin only (soft delete: `deleted=1`, content blanked). Success `{ok:true}`. Fans a delete frame. |
| `POST /api/message/reaction` | `id`, `emoji` (≤ 16 chars) | Toggles the actor's reaction. Returns `{ok:true, added, reactions}` and fans a `reaction` frame. **403** when reactions are disabled server-wide. |
| `POST /api/report` | `id`, `pm` (`0`/`1`), `reason`, `other` | Report a channel or DM message to staff. Reporter must be a channel member (channel) or a conversation participant (DM). One report per message per reporter → **409**. Deleted messages → **410**. |

`msg_update` frames are described in [gateway.md](gateway.md).

---

## 4. Channels

| Endpoint | Fields | Behaviour |
|---|---|---|
| `POST /api/channels` | `name`, `visibility` (`public`/`private`/`secret`), `topic`, `register`, `invite_only` | Create a channel. Success `{ok:true, redirect:"/app?channel=<slug>"}`. |
| `POST /api/join` | `name` (e.g. `#gaming`), optional `key` | Join an existing channel (auto-create if it doesn't exist). Keyed channels need `key`. Returns `{ok:true, redirect}` or `{error:"need_key", …}`-style **403**. |
| `POST /api/part` | `channel` (slug), optional `reason` | Leave a channel. `{ok:true, redirect:"/app"}`. |
| `POST /api/channel/notify` | `channel`, `mode` (`all`/`mentions`/`muted`) | Per-user notification mode for a channel. |
| `POST /api/channel/read` | `channel` | Mark a channel read (clears its unread badge). |
| `POST /api/channel/delete` | `channel` | Founder (or admin) deletes the channel (history preserved in `chat_logs`). |
| `POST /api/channel/invite/accept` / `decline` | `channel` (slug) | Accept/decline a channel invite. |
| `POST /api/channel/bg` / `bg/remove` | `channel`, optional file/`bg_color`/`bg_fit`/`bg_overlay` | Channel owner sets/clears the channel chat background. |
| `GET /api/channel/settings` | `channel` (slug, query) | Control-panel payload for a channel — see below. Requires membership. |
| `POST /api/channel/settings` | `channel`, `action`, action fields | Channel control-panel actions (bans / access list / topic / URL) — see below. |
| `GET /api/browse` | — | Public channel browser data: `channels` (joinable), `myChannels`, `online`, `peak`. |

### `GET /api/channel/settings` — control-panel payload

Auth + membership required (else **401** / **403**). Returns everything the
Channel Settings modal renders:

```json
{
  "ok": true,
  "channel": {
    "id": 7, "name": "#gaming", "slug": "gaming", "topic": "…",
    "description": "…", "visibility": "public", "topic_locked": true,
    "registered": true,
    "url": "https://example.com/page", "url_banned": false, "url_set": true
  },
  "can": { "manage": true, "bans": true, "access": true, "topic": true, "url": true },
  "bans":  [ /* channel bans, newest first */ ],
  "access": [ /* channel_access rows with username + level */ ]
}
```

The `can` map tells the client which tabs/fields the caller may use:

| Key | When true |
|---|---|
| `manage` | Channel ops+ (or server admin/oper) — the caller may open the panel at all |
| `bans` | Half-op+ — add/remove bans |
| `access` | Op+ — add/remove registered ops & half-ops |
| `topic` | Op+, **or** the topic is unlocked (`topic_locked` false) |
| `url` | Op+ — set/clear the Channel URL |

`url` is `null` when no URL is set **or** when the stored URL's domain is on
the global banned list (`url_banned: true`, `url_set: true` keeps the stored
value's existence visible).

### `POST /api/channel/settings` — control-panel actions

CSRF + auth + membership required. The `action` field dispatches to one of:

| `action` | Extra fields | Permission | Effect |
|---|---|---|---|
| `ban_add` | `mask` (nick auto-resolves to `nick!*@*`), `duration`, `reason` | halfop+ | Inserts a `channel_ban`, records moderation + staff note when the target resolves. → `{ok, message}` |
| `ban_del` | `id` | halfop+ | Removes a channel ban. |
| `access_add` | `nick`, `level` (`admin`/`op`/`halfop`/`voice`) | op+ | Adds to `channel_access`, reusing `AccessService::canSetLevel` grant limits. |
| `access_del` | `nick` | op+ | Removes from `channel_access`. |
| `topic_set` | `topic` (≤ 500) | op+ unless `+t` off | Updates the topic, posts a `topic` system event. → `{ok, topic_set, topic_channel}` (the client refreshes its header). |
| `url_set` | `url` | op+ | Sets the Channel URL (http/https only, ≤ 500 chars, banned-domain check). → `{ok, url}` |
| `url_clear` | — | op+ | Clears the Channel URL. → `{ok, url: null}` |

Errors: **400** (bad URL, banned domain, unknown action), **403** (permission),
**404** (missing ban/access user). A successful `url_set` / `url_clear` also
posts a `system` line to the channel so every viewer sees the change.

---

## 5. History & search

| Endpoint | Fields | Response |
|---|---|---|
| `GET /api/history` | `channel` **or** `dm`, `before` | Older messages (`id < before`, ≤ 50). `{ok, messages, channel\|dm}`. |
| `GET /api/search` | `q` | `{ok, results:{channels:[…], dms:[…]}}` — each result carries a `snippet` around the first match. Uses FTS5 when available, else `LIKE`. |
| `GET /api/gifs` | `q` (empty = trending), `limit` (≤ 50), `offset` | Giphy proxy: `{ok, gifs, next}`. Server-side so the API key never reaches browsers. Rate-capped (30 calls/10 s) → **429**. |

---

## 6. Notifications

| Endpoint | Behaviour |
|---|---|
| `GET /api/notifications` | Unread notifications (≤ 50), each with `created_at` relativized plus `channel_slug` and message `content` (DMs resolve from `private_messages`; other kinds from `messages`). |
| `POST /api/notifications/read` | Marks all the session's notifications read. |
| `POST /api/notifications/dismiss` | Deletes one notification (`id`), returns the new `notify_count`. |
| `GET /api/notify/prefs` | The full per-user preference set: `{prefs:{push:{channels,dms,invites}, notify:{sound_master, os_master, previews, quiet_hours_enabled, quiet_hours_start, quiet_hours_end, quiet_hours_days, highlight_keywords, tz_offset_minutes}}}`. Guests get defaults. |
| `POST /api/notify/prefs` | Save any subset (same shape). `quiet_hours_days` / `highlight_keywords` accept arrays or JSON strings. Also stores `tz_offset_minutes` so server-side quiet-hours gating (Web Push) matches the client's local time. |
| `POST /api/push/test` | Sends a test Web Push to the caller's subscriptions; **400** when none. |
| `POST /api/push/subscribe` / `POST /api/push/unsubscribe` | Manage browser push subscriptions. |
| `POST /api/push/mute` / `POST /api/push/unmute` | Mute a user across every notification surface. |
| `POST /api/typing` | Record that the caller is typing (`channel` or `dm`); surfaced via the poll's `typing` field. |
| `POST /api/message/pin` | Pin a channel message (`id`) — operators/admins only, chat messages only. Returns the channel's updated `pins`. |
| `POST /api/message/unpin` | Unpin a message (`id`), same permission gate. |
| `GET /api/channel/pins` | `channel` (slug) → `{ok, pins:[{message_id, username, sender_id, sender_guest_id, content, message_at, created_at}]}`. |

Notification kinds include `dm`, `mention`, `invite`, `notice`, `knock`, `friend_request`, `friend_accepted`.

**Web Push payloads** (`channelMessage`, `dm`, `invite`) carry `title`, `body`, `tag`, and `data:{type, channel|username, msg_id}`. The service worker uses `data.msg_id` to deep-link notification clicks to `/app?channel=…&jump=<id>` (or `?dm=…&jump=<id>`). Per-recipient preferences (`os_master`, quiet hours, `previews`) partition the fan-out; `previews=0` replaces bodies with a generic line. Channels in `muted` notify-mode and per-user muted senders are excluded.

---

## 7. Presence

| Endpoint | Response |
|---|---|
| `GET /api/online` | `{ok, online:[{id, username, away}]}` — registered users active in the last 30 s, excluding self. |

Presence semantics and throttling are documented in [presence.md](presence.md).

---

## 8. Friends

| Endpoint | Behaviour |
|---|---|
| `GET /api/friends` | List with per-friend online/status grouping. |
| `GET /api/friend/status` | Status between two users. |
| `POST /api/friend/request` | Send a friend request. |
| `POST /api/friend/accept` / `decline` / `cancel` | Accept / decline / cancel a request. |
| `POST /api/friend/remove` | Remove a friendship. |
| `POST /api/friend/block` / `unblock` | Block/unblock a user (blocks messages; `/ignore` delegates here). |

---

## 9. Messenger & directory (Electron clients)

| Endpoint | Purpose |
|---|---|
| `GET /api/me` | Current session's account (`id`, `username`, `avatar`, `role`, `guest`). |
| `GET /api/csrf` | The session's CSRF token for API clients. |
| `GET /api/directory?q=` | User-directory search with relationship status. |
| `GET /api/groups` · `POST /api/groups` · `/rename` · `/delete` | Custom contact groups ("nodes") CRUD. |
| `POST /api/groups/member/add` · `/remove` | Group membership (enforced to accepted friends). |

---

## 10. Profile, sounds, push, theme (user settings)

| Endpoint | Fields | Notes |
|---|---|---|
| `POST /api/password` | `current`, `new` (≥ 8) | Change password. |
| `POST /api/profile` | `vhost`, `away`, `theme`, … | Update profile fields. |
| `POST /api/avatar` / `avatar/remove` | multipart `file` (1 MB cap) | Upload/remove avatar. |
| `POST /api/mfa/begin` / `enable` / `disable` | — | TOTP enrollment lifecycle. |
| `POST /api/sound/prefs` | `dm_sound_id`, `channel_sound_id` | Per-context default sounds (null = muted). |
| `POST /api/sound/override` / `override/remove` | `user_id`, `sound_id` | Per-sender override (null = mute that sender). |
| `POST /api/push/subscribe` / `unsubscribe` | subscription keys | Web Push subscription (VAPID). |
| `POST /api/push/prefs` | `channels`, `dms`, `invites` | Per-context push toggles. |
| `POST /api/push/mute` / `unmute` | `user_id` | Mute a user across push/bell/sounds/DM-toasts. |
| `POST /api/theme` / `theme/bg` / `theme/bg/remove` | — | Per-user theme + chat background. |
| `GET /api/theme/css` | — | Live per-user theme CSS. |

---

## 11. Status code cheat-sheet

| Code | Meaning |
|---|---|
| 200 | Success (`{ok:true}` or `{ok:true, …}`). |
| 400 | Bad request / bad field (e.g. empty message, bad GIF URL, invalid mode). |
| 401 | Not authenticated / missing session. |
| 403 | Authorized but forbidden: restriction gate, channel membership, bans, word-filter block, owner-only action. |
| 404 | Missing resource (channel, user, message, history endpoint params). |
| 409 | Duplicate report (one per message per reporter). |
| 410 | Message already deleted/removed. |
| 419 | CSRF token mismatch. |
| 429 | Rate limited (12 sends/5 s; GIF proxy 30/10 s; etc.). |

## 12. Voice, calls & meetings (WebRTC)

The WebRTC voice module adds its own endpoint family — `/api/webrtc/voice/*`,
`/api/webrtc/call/*`, `/api/webrtc/moderate`, `/api/webrtc/record`, and
`/api/webrtc/recordings/{id}`, plus the `/api/events/*` meeting-event endpoints.
They follow the same auth/CSRF/JSON conventions as the rest of this catalog but
are documented separately in [voice.md](voice.md), because they return
LiveKit JWT payloads rather than plain chat data.

# LVChat — Message Data Model

Every read path — initial page render, polling, SSE, WebSocket fan-out,
history pagination, and search — returns messages in the same JSON shape.
This page documents the shape field-by-field, the message kinds, how image/GIF
content is encoded, and the watermark/pagination model.

---

## 1. Channel message shape

A channel message is produced by `MessageService::present()` and is what
`/api/poll`, `/api/stream`, WebSocket frames, `/api/history`, `/api/search`,
and the initial `/app` render all return.

```json
{
  "id": 1042,
  "kind": "message",
  "content": "Hello world",
  "created_at": "2026-08-05 14:30:00",
  "username": "alice",
  "sender_id": 3,
  "avatar": null,
  "bot": 0,
  "role": "user",
  "level": "normal",
  "role_color": null,
  "guest": 0,
  "channel_id": 1,
  "channel_slug": "general",
  "reply_to_id": null,
  "reply_to_username": null,
  "reply_to_excerpt": null,
  "edited_at": null,
  "deleted": 0,
  "is_pm": false,
  "reactions": [],
  "my_reactions": []
}
```

### Field reference

| Field | Type | Meaning |
|---|---|---|
| `id` | int | Monotonic message id. Serves as the *watermark* for delta fetches. |
| `kind` | string | Message kind — see [§3 Kinds](#3-message-kinds). |
| `content` | string | Raw message body. Not HTML — clients render it (server pre-escapes server-rendered pages; the JSON API returns raw text with markup tokens like `**bold**`, `` `code` ``, `@mention`, `#channel`, URLs). |
| `created_at` | string | UTC timestamp, `Y-m-d H:i:s`. |
| `username` | string\|null | Sender nickname (guest nick or registered username). `null` for system messages. |
| `sender_id` | int\|null | Registered sender id, or `null` for guests/system. |
| `avatar` | string\|null | Sender avatar URL (registered users). |
| `bot` | int | `1` when the sender is a bot/webhook account. |
| `role` | string | Sender's account role (`user`, `admin`, …). |
| `level` | string | Sender's channel level: `normal` \| `voice` \| `halfop` \| `op` \| `admin` \| `founder`. |
| `role_color` | string\|null | Custom-role colour. Helper roles always resolve to green (`#22c55e`). |
| `guest` | int | `1` when the sender is a guest actor. |
| `channel_id` | int\|null | Owning channel id. |
| `channel_slug` | string\|null | Owning channel slug (for client links). |
| `reply_to_id` | int\|null | Parent message id when this message is a reply. |
| `reply_to_username` | string\|null | Sender of the parent message (if still visible). |
| `reply_to_excerpt` | string\|null | First 80 chars of the parent's content. |
| `edited_at` | string\|null | Set when the message was edited. |
| `deleted` | int | `1` once soft-deleted. Deleted rows are excluded from reads, so this is mostly informational. |
| `is_pm` | bool | Always `false` on channel messages. |
| `reactions` | array | `[{emoji, count}]`, aggregated, count-desc. Only present when reactions are enabled (`hydrateReactions`). |
| `my_reactions` | array | Emojis the viewing actor has reacted with. |

---

## 2. Private-message shape

A PM is produced by `MessageService::forDm()` and friends. It is a trimmed
channel-message shape:

```json
{
  "id": 88,
  "kind": "message",
  "content": "Hey!",
  "created_at": "2026-08-05 14:32:00",
  "username": "bob",
  "sender_id": 5,
  "bot": 0,
  "role": "user",
  "role_color": null,
  "guest": 0,
  "level": "normal",
  "reply_to_id": null,
  "edited_at": null,
  "deleted": 0,
  "is_pm": true
}
```

Differences from channel messages:

- `is_pm` is `true`.
- No `channel_id` / `channel_slug`, no `reply_to_*`, no `reactions`.
- Sender may be a user or a guest; the `guest` flag disambiguates.
- The *conversation* is identified by the participant nicks, not an id. DM
  polling passes `dm=<nick>`.

---

## 3. Message kinds

### User message kinds (stored in `messages.kind` / `private_messages.kind`)

| Kind | Meaning |
|---|---|
| `message` | A normal chat line. |
| `action` | `/me <action>` — rendered as a third-person action line. |
| `image` | An uploaded image; content = image URL, then optional caption. |
| `gif` | A posted GIF; content = media URL, then optional title. |
| `ai_response` | Bot response rendered with full GFM markdown (+ `:::thinking` / `:::tool` collapsible blocks). |

### System message kinds (`MessageService::SYSTEM_KINDS`)

Produced by commands and channel events. These have `sender_id: null` /
`username: null`, are excluded from background-sound fetches, mentions, and
search:

```
join, part, quit, kick, ban, topic, mode, nick, system, notice
```

| Kind | Emitted when |
|---|---|
| `join` | someone joins a channel |
| `part` | someone leaves |
| `quit` | `/quit` — user disconnects |
| `kick` | someone is kicked |
| `ban` | someone is banned/unbanned |
| `topic` | the topic changes |
| `mode` | channel modes change |
| `nick` | a user changes nick |
| `system` | generic server/ChanServ notice text |
| `notice` | targeted notice |

---

## 4. Content encoding (image / GIF)

Image and GIF messages pack the media URL plus an optional caption into
`content` as **line-separated fields**:

```
<url-or-path>
<optional caption / title>
```

- `image` — first line is the **upload path** (relative, e.g.
  `/uploads/ab12…/img.webp`); following lines are the caption.
- `gif` — first line is the **absolute media URL** (validated against known
  Giphy CDN hosts at send time); following lines are the search title, which
  keeps the GIF findable in chat search.

`ai_response` content is markdown with optional collapsible blocks:

```markdown
:::thinking
internal reasoning shown collapsed
:::

:::tool
web_search
result lines
:::
```

---

## 5. Watermarks & pagination

Everything is driven by the monotonic `messages.id` (and `private_messages.id`).

| Query param | Used by | Semantics |
|---|---|---|
| `since` | `/api/poll`, `/api/stream` | Return rows with `id > since`. This is the client's *delta watermark* for the conversation it is viewing. |
| `bg_since` | `/api/poll`, `/api/stream` | Global watermark for **background** channel messages (every member channel except the one being viewed) — drives channel audio alerts. |
| `before` | `/api/history` | Return rows with `id < before`, newest-first batch (then reversed) — "load earlier messages". |

### Delta-fetch details

- Poll/SSE channel fetches cap at **100 rows**; when a batch hits the cap the
  SSE server **does not advance `since`** so nothing is dropped on a busy
  channel — the next tick keeps catching up. (The polling client likewise only
  advances its `lastId` from what it appended.)
- The initial page render shows the last **60** channel messages and seeds the
  client watermark from the last rendered id.
- History pagination returns **50** older messages per call (`historyBefore`),
  ascending oldest→newest.
- DM deltas fetch up to **100** rows.
- Background fetches cap at **50** rows and exclude system kinds and the
  currently-viewed channel.

### Who-owns-what

- `messages.id` — global channel-message sequence (all channels share one
  sequence).
- `private_messages.id` — global PM sequence (all conversations share one).
- `messages.id` in `bg_since` watermarks is the *channel* sequence; the PM
  sequence never feeds it.

---

## 6. Reactions

Reactions ride on top of channel messages:

- Stored in `reactions` keyed by `(message_id, actor_type, actor_id, emoji)`,
  where `actor_type` is `user` or `guest`.
- Toggled with `POST /api/message/reaction` (`id`, `emoji`); one reaction per
  actor per emoji.
- `reactions` on a presented message lists `{emoji, count}` (count-desc);
  `my_reactions` lists the emojis the *viewing* actor already used.
- Server-wide toggle: `reactions_enabled` config (default on). System kinds
  and deleted messages cannot be reacted to.
- When disabled, `reactions`/`my_reactions` are omitted entirely.

---

## 7. What clients receive on each read path

| Path | Message source | Shape |
|---|---|---|
| `/app` initial render | `MessageService::history()` (last 60) | `present()` + `hydrateReactions` |
| `GET /api/poll?channel=` | `forChannel(id, since)` (delta ≤ 100) | `present()` + `hydrateReactions` |
| `GET /api/poll?dm=` | `forDm()` (delta ≤ 100) | PM shape |
| `GET /api/poll?bg_since=` | `backgroundSince()` (≤ 50) | `present()`, system kinds excluded |
| `GET /api/stream` | same as poll, looped | same |
| WebSocket frame | gateway re-broadcasts the write payload | same as what `/api/send` returned |
| `GET /api/history?channel=&before=` | `historyBefore()` (≤ 50) | `present()` + `hydrateReactions` |
| `GET /api/history?dm=&before=` | `dmHistoryBefore()` (≤ 50) | PM shape |
| `GET /api/search?q=` | `searchChannels` / `searchDm` (≤ 50) | `present()` + PM shape, plus `snippet` |

The WebSocket gateway does **not** reshape messages: it re-broadcasts the
exact array the writer endpoint returned, so the browser's single
`handleRealtime` handler (see [realtime.md](realtime.md)) treats a WS frame and
a poll response identically.

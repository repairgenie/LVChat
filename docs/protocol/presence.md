# LVChat — Presence Model

Presence is the "who's online right now" layer: how `last_seen` gets written,
how online-ness is computed, how away state works, and how the transport
reporting lets admins verify which realtime layer is actually in use.

---

## 1. The primitive: `last_seen`

Every registered user (`users.last_seen`) and guest (`guests.last_seen`) has a
UTC `last_seen` timestamp. A participant is considered **online** when:

```php
// Auth::isOnline()
away === null
&& last_seen is not empty
&& (now - last_seen) <= presence_window()
```

- Away users are **never** counted online, even with a fresh `last_seen`.
- The window is `presence_window()` = `max(30, 3 × presence_throttle)` — default
  30 s throttle, so a **90 s** window. It is the same one used by `/api/online`,
  the `/app` member roster, `online_count()`, and every presence dot in the UI.

### What writes `last_seen`

| Writer | When | Frequency |
|---|---|---|
| `Auth::user()` (session check) | every authenticated request, throttled | once per `presence_throttle` seconds (default 30) |
| SSE loop (`/api/stream`) | inside the stream loop, throttled | same cadence, so long-lived streams never go stale |
| WebSocket `ping` | at `ping_ms` (window / 3, 5–30 s) from the client, throttled | same `presence_throttle` cadence |
| WebSocket connect / close | immediately | `now` on connect, `-(presence_window() + 1) seconds` on close (so the user drops offline promptly) |
| `Auth::login()` / guest login | login | `now` |

### Throttling (the point)

`presence_throttle` (default **30 s**, min 5) governs how often a client's
activity actually writes to the database. Between writes, `Auth::user()`
**patches the in-memory row** so the rest of the request still sees fresh data
— the database only needs one write per user per 30 s. That turns ~28 of every
30 polls into pure reads (SQLite WAL reads scale; writes serialize).

The **offline window is separate from the throttle** and deliberately larger:
`presence_window()` = `max(30, 3 × presence_throttle)`. When the two were the
same value every refresh raced the offline test, so a late or skipped write
flipped active users offline (and a low `presence_throttle` left WebSocket
clients offline for most of every 30 s ping cycle). Three throttles tolerate
two missed writes. Clean disconnects do not wait for the window: WS `onClose`
and the `/api/heartbeat` `offline` beacon write `now - (window + 1)`.
Clients derive their heartbeat cadence from the window: `GET /api/ws/ticket`
returns `ping_ms` = window / 3, floored at 5 s and capped at 30 s.

### Going offline: last client wins

Presence is per **account**, but closing is per **client**. Every browser tab /
messenger window generates a `client` id in `sessionStorage` and sends it on
every poll, on the SSE stream, in the WebSocket connect URL and in the unload
beacon. The server keeps one row per `(client, transport)` in `rt_clients`,
refreshed on the `presence_throttle` cadence.

When a socket closes or a beacon reports `offline`, only that client's rows are
dropped, and `last_seen` is pushed past the presence window **only when no
fresh row remains for the actor**. Closing one tab, or logging out one device,
therefore leaves the account online while any other tab/device keeps polling,
streaming or holding a socket. Rows older than the presence window are ignored
and pruned daily; a beacon with no (or an unknown) client id deletes nothing and
lets the window expire, so an older cached client can never force a false
offline.

Opportunistic housekeeping rides on the same throttled write: `purgeExpired()`
(once per hour) prunes expired `sessions`/`guest_sessions` and stale `guests`
(> 1 day inactive), and `record_peak()` updates the all-time `peak_online`.

---

## 2. Away state

- `users.away` (message text) + `users.away_at` (timestamp).
- `/away [message]` sets it; `/back` clears it; profile edits can set/clear it.
- Away users are excluded from online counts and their presence dot renders
  amber.

---

## 3. What clients receive

### Poll / SSE / WS payloads

Every realtime payload carries presence where relevant:

- **Channel view** — `presence[]`: the full member roster, each with
  `username`, `is_online`, `away`, `level`, `role`, `bot`, `guest`,
  `role_helper`, `role_color`, `avatar`. The client re-renders member-list
  presence from this on every poll.
- **DM view** — `presence[]` for the partner: `{username, is_online, away,
  level, role, guest}`.
- **Sidebar** — `channel_presence[]`: `{slug, online}` per joined channel (the
  "active chatters" count). The DM sidebar derives online dots from
  `dm_list[].last_seen` against the same effective presence window
  (`presence_window()`, both in the initial render and from the `is_online`
  that `Auth::statusInfo()` puts on each `dm_list` entry), so dots agree with
  the server at any `presence_throttle` setting.

### `GET /api/online`

```json
{ "ok": true, "online": [ { "id": 3, "username": "alice", "away": null } ] }
```

Registered users active within the presence window, excluding self. The initial `/app`
render embeds a similar roster (ordered online-first) for mention autocomplete.

### `online_count()` / browse

`online` (users + guests active within the presence window) and `peak` (all-time concurrent peak)
feed `/api/browse` and the channel browser.

---

## 4. Guests

- Guests have `last_seen` but no away column (always `NULL`).
- A guest is online when `last_seen` is within the presence window — same rule,
  `guest: 1` flagged in payloads.
- On logout the guest's `last_seen` is set `NULL`, releasing the nick; on WS
  close it's written `window + 1` seconds back.
- `Auth::guestInUse()` (2-min grace) decides whether a stale guest row's nick
  is reclaimable.

---

## 5. Transport reporting (`rt_transports`)

Browsers call `POST /api/rt/report` whenever their live transport changes
(`ws` / `sse` / `poll` / `none`) — see [realtime.md](realtime.md) §3.5. The
table holds `(actor_id, guest, transport, updated_at)`, one row per actor.
`Realtime::daemonStatus()` aggregates rows updated in the last 2 minutes so the
admin panel shows *actual* transport usage rather than the configured one — the
key signal for "WebSockets are configured but everyone silently fell back to
polling."

---

## 6. Config knobs

| Config | Default | Effect |
|---|---|---|
| `presence_throttle` | 30 | Seconds between `last_seen` database writes per user (min 5). Raise on busy shared hosts. The offline window is 3× this (min 30 s). |
| `poll_interval` | 2 | Seconds between client polls / SSE ticks. |
| `peak_online` | 0 | All-time concurrent peak (auto-recorded, admin-visible). |

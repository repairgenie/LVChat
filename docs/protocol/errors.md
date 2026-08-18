# LVChat — Errors, Status Codes, and Gates

This page collects the cross-cutting error conventions shared by every endpoint.

---

## 1. JSON shape

- **Success** (when it carries data): `{"ok": true, ...fields}`.
- **Failure**: `{"error": "<human-readable message>"}` with an appropriate
  status code. Some endpoints add structured fields alongside `error`
  (e.g. `{error:"need_key", ...}` from `/api/join`, or
  `{ok:true, blocked:true, notice:"…"}` from the word-filter block path).
- Errors are always returned as JSON for AJAX; form/no-JS posts flash the
  message and redirect instead (see [http-api.md](http-api.md) §Conventions).
- JSON is encoded `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.

### Status code cheat-sheet

| Code | Meaning | Typical cases |
|---|---|---|
| 200 | Success | `{ok:true}` or a data payload |
| 400 | Bad request | Empty message, bad GIF URL, invalid mode/visibility, `/api/history` without `channel`/`dm`, report missing reason |
| 401 | Not authenticated | Missing/expired session on a protected endpoint |
| 403 | Forbidden | Restriction gate, not a member, channel ban/mute/moderated, spamfilter/shun hit, word-filter block, owner-only action, report on a non-member channel, edit/delete of someone else's message |
| 404 | Not found | Unknown channel, user, message, or missing `channel`/`dm` params |
| 409 | Conflict | Duplicate report (one per message per reporter); voice: "Voice is full", "You are already in a call" / busy-gate |
| 410 | Gone | Reporting an already-deleted message |
| 419 | CSRF mismatch | Missing/incorrect `csrf` field or `X-CSRF` header |
| 429 | Rate limited | 12 sends+DMs per 5 s; GIF proxy 30 calls per 10 s; voice/call/event buckets (see [voice.md](voice.md) §Rate limits) |
| 503 | Service unavailable | Voice: recording requested while LiveKit egress isn't configured |

---

## 2. Authentication & CSRF gates

- `ChatController::requireUser()` → **401** `{"error":"Not authenticated."}`.
- `ChatController::requireCsrf()` / `Csrf::verify()` → **419**
  `{"error":"CSRF token mismatch."}`. Accepted from the `csrf` POST field or
  the `X-CSRF` header.
- Page routes redirect to `/login?next=<url>` instead of erroring.
- WebSocket handshakes fail by **closing the socket** (bad origin or
  invalid/expired ticket), not by JSON — see [gateway.md](gateway.md).

---

## 3. Account & moderation gates

Every send path (channel, DM, upload, GIF search, command) first runs:

1. **`ModerationService::restriction($user)`** — returns a reason string when
   the account is `pending` or `suspended` (with the stored reason), blocking
   chat entirely → **403**.
2. **`BanService::canPost($channel, $user, $member)`** — channel-scoped: active
   channel bans (+`-b`), `+q` quiet (mute), `+m` moderated (only voiced+ can
   speak).
3. **`BanService::sendBlocked($user, $content, 'c'|'p')`** — server-wide
   spamfilters, shuns, and global *line bans matched against the message
   content and the sender's nick/email/IP.
4. **`CensorService::check()`** — the bad-word filter:
   - **Channel**: only applies when the channel has `+C` set.
   - **DM**: always applies (no channel mode exists for PMs).
   - `action = censor` → content is rewritten to `****` and stored.
   - `action = block` → the message is rejected; in channels the server posts a
     `system` notice ("Chanserv removed message from X due to prohibited
     words") and returns `{ok, message, blocked:true, notice}` so the client
     can flash-then-remove; in DMs it returns the notice directly.
   - Every hit is recorded on the moderation queue
     (`ModerationService::record`, kind `badword`).

---

## 4. Rate limits

| Limit | Window | Endpoint scope |
|---|---|---|
| **12 messages + DMs** | 5 s, per actor | `/api/send`, `/api/upload` (shared counter across both) |
| **30 GIF proxy calls** | 10 s | `/api/gifs` |
| **10 failed logins** | 10 min, per IP | `/login`, `/login/mfa` |
| **20 registrations** | 10 min, per IP | `/register` |
| **Voice/call/event buckets** | see [voice.md](voice.md) §Rate limits | `/api/webrtc/voice/join` (12/min), `/api/webrtc/call/initiate` (6/min), `/api/webrtc/call/invite` (20/min), `/api/webrtc/moderate` (120/min), `/api/events/create` (10/hr), `/api/events/invite` (30/hr) |

Exceeding a chat limit returns **429** with a "slow down" style message. The
composer also queues sends while offline (PWA) and replays them in order on
reconnect.

---

## 5. Response conventions by family

| Family | Success | Failure style |
|---|---|---|
| Reads (poll/history/search/browse/notifications) | `{ok:true, …data}` | 401/403/404 JSON |
| Writes (send/upload/command) | `{ok:true, message\|replies\|redirect}` | 400/403/404/429 JSON; no-JS → flash+redirect |
| Message lifecycle (edit/delete/reaction/report) | `{ok:true, …}` | 403 permission, 404 missing, 409 dup report, 410 deleted |
| Channel management | `{ok:true, redirect}` | 400 invalid fields, 403 permission/join reasons |
| Settings (profile/sound/push/theme) | `{ok:true}` | 400 invalid input, 403 ownership |

---

## 6. The `redirect` signal

Several payloads carry a `redirect` even on `ok` (the client navigates
instead of rendering):

- `/api/command` → follow the command's own redirect (e.g. after `/join`).
- `/api/poll` → `{ok:true, redirect:"/app", reason:"You are no longer in this
  channel."}` when the viewing membership vanished.
- `/api/join` → `{ok:true, redirect:"/app?channel=<slug>"}`.
- Word-filter block / send → keeps the sender in place while removing the
  message.

The client's shared handler checks `redirect` before anything else, so a
mid-session membership change always lands the user somewhere valid.

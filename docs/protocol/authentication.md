# LVChat — Authentication & Session Protocol

This page documents how a client proves its identity to LVChat: the cookie
session, CSRF protection, MFA/TOTP gating, the guest login flow, the WebSocket
handshake ticket, and the small API-client endpoints.

---

## 1. Transport & session cookie

All authentication rides on a standard PHP session cookie. The session is
started in `src/bootstrap.php` before any routing:

- Cookie name: the default **`PHPSESSID`** (no custom `session_name()` is set).
- `HttpOnly` — the token is never readable by JavaScript.
- `Secure` when the request arrives over HTTPS.
- `SameSite=None` over HTTPS, `SameSite=Lax` on plain HTTP. (`None` is
  required so the public embed widget works in cross-site iframes.)

### The session token

Inside the session, `$_SESSION['token']` holds a server-side session token:

```
token = bin2hex(random_bytes(32))   // 64 hex chars, opaque
```

**Registered users.** `Auth::login()` inserts the token into the `sessions`
table keyed to the user id with a 30-day expiry and stores it in the session:

```sql
INSERT INTO sessions (user_id, token, expires_at)
VALUES (?, ?, datetime('now', '+30 days'));
```

Every authenticated request looks the token up:

```sql
SELECT u.*, s.expires_at FROM sessions s JOIN users u ON u.id = s.user_id
WHERE s.token = ? AND s.expires_at > datetime('now');
```

**Guests.** Exactly parallel, but against `guests` / `guest_sessions`, and the
session resolves to a guest row (`Auth::guestActor()` shapes it like a user row
with `guest: 1`).

### Unauthenticated responses

| Endpoint kind | Behaviour |
|---|---|
| Page routes | Redirect to `/login?next=<original-url>` |
| JSON API | `401 {"error": "Not authenticated."}` |

`Auth::require()` is the page guard; `ChatController::requireUser()` is the
API guard.

---

## 2. CSRF

Every state-changing endpoint (all `POST`s, both forms and AJAX) requires a
CSRF token.

- The token is generated per session: `bin2hex(random_bytes(32))`, stored in
  `$_SESSION['csrf']` (`Csrf::token()`).
- Clients send it one of two ways (either is accepted, `Csrf::verify()` and
  `ChatController::requireCsrf()` check both):
  1. A `csrf` field in the POST body (all forms and the JS `post()` helper).
  2. An `X-CSRF` HTTP header (raw `fetch` calls set both for safety).
- Validation is constant-time (`hash_equals`).
- **Failure → HTTP 419** (`{"error":"CSRF token mismatch."}` for AJAX, a plain
  "CSRF token mismatch" body for forms).

---

## 3. The login flow

`POST /login` (with CSRF), form fields `username`, `password`, optional `next`:

1. **Rate gate** — per-IP failed-attempt log (`login_attempts`). After 10
   failures within 10 minutes the login is refused.
2. **Verify** — `Auth::attempt()` looks the user up (case-insensitive) and
   `password_verify`s argon2id; rehashes automatically if the stored hash is
   outdated.
3. **Global ban / suspension checks** — `*line` bans (`Auth::globalBanFor`),
   the `banned` flag, and `status = 'suspended'` all block login with the
   stored reason.
4. **MFA gate** — see below.
5. **Open the session** — `Auth::login()` writes the token, clears the IP
   failure log, and redirects to `next`.

### MFA (TOTP)

When a user is MFA-enabled (or an account class *requires* MFA), the password
check **parks** the login in a pending state instead of opening a session:

```php
$_SESSION['mfa_pending_uid']  = <user id>
$_SESSION['mfa_pending_next'] = <return URL>
```

- Enrolled users are sent to `/login/mfa` to enter a 6-digit TOTP code.
  `POST /login/mfa` verifies the code, then completes the login.
- Users who must enroll are sent to `/login/mfa/setup` first (QR/manual key),
  then to `/login/mfa`.
- A parked state only exists in the session; **no session token is minted
  until MFA passes.**

### Magic link & password reset

One-time tokens live in `auth_tokens` (single-use `used_at`, short `expires_at`):

| Route | Purpose |
|---|---|
| `POST /forgot-password` | Emails a reset link (`/reset-password/<token>`) |
| `POST /reset-password/<token>` | Sets a new password, invalidating the token |
| `POST /magic-link` | Emails a magic login link |
| `GET /magic/<token>` | Logs the user in directly |

---

## 4. Registration & invites

`POST /register`:

- Username must match `^[A-Za-z0-9_\-\[\]\\\`^{}|]{2,32}$` (IRC-safe symbols).
- Age gate: `ageVerified` must be true (the 18+ certification checkbox).
- Honeypot field to discourage spam registrations.
- Rate limited per IP via `registration_attempts` (default 20 per 10 min).
- **First account on a fresh database becomes the admin** (`role = 'admin'`).
- When `registration_requires_approval` is on, new accounts start as
  `status = 'pending'` (can browse but not chat).
- Stale guest rows holding the same nick are converted into the real account
  (DMs/memberships/notifications transfer to the new user id).

`/register?invite=<token>` (from `registration_invites`) lets an invitee sign
up even when open registration is closed.

---

## 5. Guest login

`POST /guest` (`Auth::loginGuest`):

- Fields: `nick`, age certification.
- Nick must match the same IRC-safe regex (2–32 chars).
- A registered user owns the nick → the guest is refused.
- Global bans are checked for the prospective guest.
- **Nick reuse**: a stale `guests` row (previous holder logged out or inactive
  > 2 min grace) is reclaimed — the same guest id, nick, and DM history are
  reused; stale rows are purged after 1 day of inactivity.
- Guests cannot create channels, cannot log into the admin, and carry
  `guest: 1` in every JSON payload.

Logout for a guest releases the nick (sets `last_seen = NULL`) so it becomes
reclaimable immediately.

---

## 6. WebSocket handshake tickets

The WebSocket gateway is a separate daemon, so the *session cookie* is not sent
to it. Instead the chat page mints a **one-time handshake ticket**:

```php
token = bin2hex(random_bytes(24))   // 48 hex chars
```

- Minted server-side by `Realtime::mintTicket($user)` and stored in
  `ws_tickets` with a **60-second TTL** (`Realtime::TICKET_TTL`).
- The chat page embeds it as `data-rt-ticket`; a reconnecting client mints a
  fresh one via `GET /api/ws/ticket`.
- The gateway redeems it on `onWebSocketConnect`, **deletes it immediately**
  (single use), and resolves the actor (user or guest) from the ticket row.
- An invalid/expired ticket → the gateway closes the connection.

This keeps the long-lived session token out of JavaScript and gives the
gateway a short-lived, replayable-once credential. Full details in
[gateway.md](gateway.md) and [realtime.md](realtime.md).

---

## 7. API-client endpoints

Small JSON endpoints used by the Electron messengers and any API client
(all send the session cookie; CORS applies to allowlisted origins):

| Endpoint | Returns |
|---|---|
| `GET /api/me` | `{ok, user:{id, username, avatar, role, guest, away, status}}` |
| `GET /api/csrf` | `{ok, csrf}` — the session's token for API clients |
| `GET /api/version` | `{version, site}` — build fingerprint (no auth) |

### CORS

Cross-origin app clients (LVChat Messenger, future native/mobile) get CORS
headers **only** when an allowlisted `Origin` header is present:

- Built in: the `null` origin (`file://`) and any `http://127.0.0.1:*`.
- Configurable: `CHAT_CORS_ORIGINS` env var or the `app_origins` config key
  (comma-separated list).
- Headers: `Access-Control-Allow-Origin`, `-Credentials: true`, `-Methods:
  GET, POST, OPTIONS`, `-Headers: Content-Type, X-CSRF`.
- `OPTIONS` preflight is answered with a bare `204`. Normal same-origin web
  traffic is untouched (no `Origin` header → no CORS headers).

---

## 8. Session lifecycle & security summary

| Concern | Mechanism |
|---|---|
| Long-lived credential | `sessions.token`, 30-day expiry, `HttpOnly` cookie |
| Replay protection | CSRF token on every POST (body field or `X-CSRF` header), 419 on mismatch |
| Guest identity | `guest_sessions.token`, guest id never aliased to a user id |
| WebSocket | 60s one-time `ws_tickets` token, single-use, never the session token |
| MFA | TOTP (RFC 6238), pending-state parking before session open |
| Login throttle | 10 failed attempts per IP / 10 min |
| Registration throttle | 20 new accounts per IP / 10 min |
| Password storage | argon2id via `password_hash`; auto-rehash on login |
| Session revocation | `Auth::killSessions()` deletes all but the current token |

Logout (`POST /logout`) deletes the token from `sessions`/`guest_sessions`,
regenerates the PHP session id, and (for guests) releases the nick.

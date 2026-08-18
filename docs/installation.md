# LVChat — Installation & Administration Guide

LVChat is a Discord-style IRC web chat written in **PHP + SQLite** (v1.6). It
needs no Node.js on the server and no internet access to a CDN: the compiled
`public/assets/css/app.css`, `public/assets/js/app.js`, and the vendored
`public/assets/vendor/tiptap/tiptap-bundle.js` (the rich-text editor) ship with
the app folder. A front-controller rewrite gives clean URLs (`/login`, `/app`,
`/c/gaming`, `/admin/...`).

This guide covers:

- Requirements
- Local development
- Production deployment (Apache, Nginx, and shared hosts)
- The SQLite database and configuration
- Upgrading and backing up
- Verification and troubleshooting
- Scaling knobs for shared hosting
- The optional desktop launcher

---

## 1. Requirements

| Component | Requirement |
|---|---|
| PHP | 8.1 or newer |
| PHP extensions | `pdo_sqlite` (required). Optional, each is used best-effort and skipped when absent: **GD** (re-encodes/downscales image uploads and avatars to WebP), **cURL** (the Giphy GIF-search proxy falls back to a stream read), **`finfo`** (audio MIME validation on sound uploads), **FTS5** (full-text search; search falls back to `LIKE` without it) |
| Node.js + npm | **Only on your own machine** if you edit views or the editor — `npm run build` compiles the Tailwind CSS *and* the Tiptap editor bundle; the server never needs Node |
| Writable dirs | `data/` (the database) and the runtime upload folders under `public/` (created automatically on first request: `public/uploads/`, `public/assets/avatars/`) |
| Disk space | A few MB for the app; the SQLite database and uploaded images grow with usage |

Check your server before going further:

```bash
php -v                      # must report PHP 8.1+
php -m | grep pdo_sqlite    # must list pdo_sqlite
php -m | grep -E 'gd|curl'  # optional: GD + cURL enable downscaling + Giphy proxy
```

> **Note:** `bin/deploy.sh` runs the PHP/`pdo_sqlite`/asset checks automatically
> and aborts if the essentials are missing.

---

## 2. Local development

If you changed any view **or the editor**, rebuild the committed assets first
(this only needs Node on *your* machine). `npm run build` compiles both the
Tailwind stylesheet and the Tiptap editor bundle:

```bash
npm install && npm run build
```

Then run the built-in PHP server:

```bash
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000> in a browser.

**What happens on first request:**

- `src/Database.php` applies `schema.sql` (all tables + indexes) and creates the
  database at `data/chat.db` (or wherever `CHAT_DB` points).
- On a *fresh* database it seeds `server_config` defaults (site name, MOTD,
  registration/approval flags, spam filter, uploads, reactions, GIF search,
  webhooks, channel cap, presence throttle, poll interval, realtime mode) and
  three channels: `#general` (public), `#help` (public), `#staff` (staff only).
- The four default **operator classes** (`netadmin`, `serveradmin`, `globalop`,
  `localop`) and three **sound alerts** (`Ding`, `Pop`, `Chime` — generated with
  a dependency-free PHP WAV writer, no ffmpeg) are seeded automatically.
- **First-run admin bootstrap:** `mfa_require_admin` defaults to `1`, so the
  first admin is prompted to enroll **MFA (TOTP)** at first login — see
  §4 *First-run bootstrap* below.
- Registration (and joining as a guest) requires certifying you are 18+.

---

## 3. Production deployment

The deploy story is intentionally simple: **upload the app folder, then run one
script on the server.**

### 3.1 Upload

Upload the entire app folder to your server, **except anything under `data/`**
(that is runtime state and lives on the server). Keep the layout intact — the
folder must contain `public/`, `src/`, `views/`, `bin/`, `schema.sql`, and the
compiled assets under `public/assets/`.

Examples:

```bash
# rsync (preserves every dotfile, mirrors deletions)
rsync -av --exclude 'data/' --exclude 'node_modules/' ./ user@host:/srv/lvchat/

# scp whole directory (uploaders that strip dotfiles are handled by deploy.sh)
scp -r ./ user@host:/srv/lvchat/
```

> **Tip:** Even if your upload tool drops dotfiles (which would remove
> `public/.htaccess`), `bin/deploy.sh` re-writes `.htaccess` for you.

### 3.2 Point your web server at `public/`

The document root must be the **`public/` folder**. Only `public/` is web-accessible;
`data/`, `src/`, `views/`, and `bin/` stay outside the document root and can
never be fetched over HTTP.

Two folders inside `public/` are **runtime writable** and created automatically
on first use: `public/uploads/` (image uploads) and `public/assets/avatars/`.
Make sure the PHP process can write to them (and to `data/`). The committed
`public/assets/sounds/` holds the three default alert WAVs.

#### Apache

`.htaccess` ships with the app, and `bin/deploy.sh` writes (or repairs) it on
every run. It enables `mod_rewrite`, serves existing files (assets) directly,
and forwards everything else to `index.php`:

```apache
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

If you prefer, put the equivalent in the `<VirtualHost>` or a `<Directory>`
block and delete `public/.htaccess` entirely.

#### Nginx

Nginx has no `.htaccess`, so you add the `try_files` rule yourself. Use
`index.php` as the single entry point and fall back to it for anything that is
not a real file or directory:

```nginx
server {
    listen 80;
    server_name chat.example.com;
    root /srv/lvchat/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # adjust to your PHP-FPM socket
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 7d;
        try_files $uri =404;
    }
}
```

The app also works without any rewrite at all by using `index.php/<path>`
(e.g. `index.php/login`), because the router strips an `/index.php` prefix. This
is what happens on servers where `.htaccess` is missing or `mod_rewrite` is
off — clean URLs come back as soon as you run `bin/deploy.sh`.

### 3.3 Run the deploy script

On the server, from the project root:

```bash
bash bin/deploy.sh
```

`bin/deploy.sh` does four things:

1. **Rewrites `public/.htaccess`** — restores clean URLs even if your uploader
   stripped dotfiles.
2. **Handles the database** — uses `$CHAT_DB` if set (default
   `<project>/data/chat.db`), migrates an older `../data/chat.db` into place,
   and creates a timestamped safety backup of an existing database before
   anything else runs.
3. **Runtime checks** — verifies the PHP version, that `pdo_sqlite` is loaded,
   and that `public/assets/js/app.js` and `public/assets/css/app.css` are
   present.
4. **HTTP sanity check** — boots a throwaway server on `127.0.0.1:8095`
   (`DEPLOY_PORT` overrides) and asserts that `/`, `/login`, `/register`,
   `/api/version`, and a 404 page behave, and that the JS and CSS assets are
   actually served as their real content-types (not as rewritten HTML). It prints
   the app version from `/api/version`.

A successful run ends with:

```
Deploy check passed. The app is ready.
```

## 3.4 Database location and configuration

- SQLite database: `<project>/data/chat.db` — **beside** `public/`, never
  web-served, and inside `open_basedir` by default so it works on shared hosts
  that enforce it.
- Override the location with the `CHAT_DB` environment variable:

  ```bash
  CHAT_DB=/var/lib/lvchat/chat.db bash bin/deploy.sh
  # or in PHP-FPM/Apache env
  ```

- The database uses WAL mode (`PRAGMA journal_mode = WAL`) for better
  concurrent reads. This creates `chat.db-wal` and `chat.db-shm` alongside it;
  treat all three as a unit when copying the database.
- `bin/deploy.sh` writes a backup named `chat.db.bak.<timestamp>` on every run.

### 3.4.1 Environment variables (.env)

Configuration is read from a `.env` file in the project root when present
(`.env.example` ships with every option documented). The ones that matter for
installation and security:

| Variable | Default | Purpose |
|---|---|---|---|
| `CHAT_DB` | `data/chat.db` | SQLite database path |
| `SETUP_TOKEN` | *(empty)* | **First-run admin bootstrap.** When set, the very first registered account is granted `admin` **only if** the registration POST carries `setup_token=<same value>` (see §4). Leave empty to disable admin auto-grant entirely. |
| `TRUSTED_PROXY` | `0` | Set to `1` when the app sits behind a TLS-terminating reverse proxy (nginx/Caddy/Cloudflare). Enables honoring `X-Forwarded-For`, `X-Real-IP` and `CF-Connecting-IP` for `client_ip()`. **Leave `0` on direct connections** — with it off, spoofed proxy headers are ignored and `REMOTE_ADDR` wins. |
| `APP_URL` | *(empty)* | Canonical public URL (`https://chat.example.com`). When set, the client-supplied `Host` header is ignored entirely (host-header poisoning defence). Used to build password-reset / magic-link / invite links and WebSocket URLs. |
| `TRUSTED_HOSTS` | *(empty)* | Comma-separated hosts the `Host` header is allowed to claim (e.g. `chat.example.com,chat2.example.com`). Only these exact hosts are used for link/WebSocket construction; any other Host value is ignored. If both `APP_URL` and `TRUSTED_HOSTS` are unset, `SERVER_NAME` is used. |
| `LVC_LICENSE_PUBLIC_KEY` | *(empty)* | Ed25519 public key for license verification; only needed for proxied/licensed deployments (see `docs/licensing.md`). |

> **Note:** `bin/deploy.sh` ships the same variables to the container when you
> use the Docker Compose setup (`docker-compose.yml`); `TRUSTED_PROXY=1` is set
> by default there because the compose stack terminates TLS at the proxy.

### 3.5 Permissions (shared-hosts and everyone)

- `public/` must be readable by the web user.
- `data/` must be **writable** by the PHP process (SQLite needs to create and
  update the database file plus its `-wal`/`-shm` companions).
- `public/uploads/` and `public/assets/avatars/` must be **writable** so image
  uploads and avatars can be stored (they are created automatically on first use).
- Never make `data/`, `src/`, `views/`, or `bin/` reachable over HTTP.

---

## 4. First-run bootstrap

On a fresh database the following channels are created automatically:

| Channel | Visibility | Who can join |
|---|---|---|
| `#general` | public | everyone |
| `#help` | public | everyone |
| `#staff` | staff | admins and `staff`-role users only |

### 4.1 Claiming the admin account (SETUP_TOKEN)

**The first registered account is *not* automatically the admin anymore.** To
prevent anyone who reaches a fresh install before the real operator from gaining
full control, the first account only becomes `admin` when a `SETUP_TOKEN` is
configured **and** presented at registration:

1. Set `SETUP_TOKEN=<a long random value>` in `.env` **before** the first
   registration (record it somewhere safe — the admin grant applies only
   while the database has *no* admin yet: once any account holds
   `role='admin'`, presenting the token again does nothing).
2. Register your first account and include the token in the POST:

   ```html
   <form method="post" action="/register">
     <input type="hidden" name="setup_token" value="YOUR-TOKEN" />
     ...
   ```

   From the command line / API the same field works:

   ```bash
   curl -s -c cookies.txt -b cookies.txt \
     -d 'username=admin&email=admin@example.com&password=secret&age18=1&setup_token=YOUR-TOKEN' \
     http://127.0.0.1:8000/register
   ```

   (The web UI has no setup-token field; you can inject it via the browser
   devtools or use the API/curl path once.)

3. Every later registration is a regular user — *unless* you enable **"require
   admin approval"** in Settings (new accounts then start as `pending`, able to
   browse but not chat, until approved). After that, admins can promote users
   from **Admin → Users**, or create an **o:line** in **Admin → O-lines**
   (username + password + operator class) so a user can run
   `/oper <their nick> <password>` to operate with that class's permissions.
   There is **no shared operator password.**

> If you **don't** set `SETUP_TOKEN`, the first account becomes a regular user
> and *no one* is admin. Reach `/admin` afterwards by promoting yourself in
> SQLite: `UPDATE users SET role='admin', status='active' WHERE username='you';`
> (admins can also do this from **Admin → Users**).

### 4.2 First admin login is MFA-gated

`mfa_require_admin` defaults to `1`, so the freshly promoted admin is prompted
to **enroll a TOTP authenticator** (QR code) at first login before the app
opens. Keep the recovery secret somewhere safe. You can relax this later under
**Admin → Settings → Security** (`mfa_require_admin` / `mfa_require_staff` /
`mfa_require_user`).

Anyone — including people who don't want an account — can **Join as guest**
from the login page (or an embedded channel link) with just a nickname and an
18+ certification. Guests are labeled `(guest)`, can chat in existing channels
but cannot create channels; a guest nick frees up on logout and is reclaimed on
re-login so its DM thread survives.

**Optional features start unconfigured** until you add a key on **Admin →
Settings**: email (SMTP) for invites/welcome/support replies, the **Giphy API
key** for GIF search, and a **logo URL**. Uploads, reactions, GIF search, and
webhooks are enabled by default and each can be switched off in Settings.

There are **no hardcoded default credentials.**

---

## 5. Upgrading / reinstalling

Upgrades are the same procedure as a fresh install:

1. Back up `data/` (`cp data/chat.db backup.db` — see also the automatic backup
   below).
2. Upload the app folder over the old one, **leaving `data/`** (and ideally
   `rewriting` nothing in it) untouched.
3. Run `bash bin/deploy.sh`.

`bin/deploy.sh` takes care of the rest:

- re-creates `.htaccess`.
- backs the database up automatically on every run.
- migrations are applied on app boot (`Database::init()` adds missing columns
  and creates missing tables automatically — `theme`, `avatar`, `status`,
  `age_verified_at`, `reactions`, `channel_notify`, `sound_alerts`, `webhooks`,
  `login_attempts`, `registration_invites`, `moderation_events`, `reports`,
  `user_notes`, `support_tickets`, `push_subscriptions`, `user_push_prefs`,
  `user_mutes`, the `guests`/`guest_sessions` tables, the FTS
  search index, and more). Older installs whose guests lived in `users` are
  migrated into the dedicated `guests` table on boot, so DMs/memberships/history
  survive. The four default operator classes and three default sounds are seeded
  idempotently.
- older installs that stored the DB one level above the project are migrated
  back into `data/` automatically.

**Cache-busting is automatic** — CSS and JS (including the editor bundle) are
linked with `?v=<file-mtime>`, so browsers never keep stale copies after an
upload.
after an upload.

---

## 6. Verification

- **`/api/version`** returns the running build, e.g.

  ```json
  {"version":"1.6.0","site":"LVChat"}
  ```

- **`bin/deploy.sh`** runs the full HTTP sanity check described in section 3.3.
- The chat page shows a **warning bar** if its scripts fail to load (stale or
  partial upload). It names the fix: "Re-upload the app folder and run
  `bash bin/deploy.sh`."

---

## 7. Scaling on shared hosting

With PHP + SQLite + polling, the ceiling on a shared host is the **PHP worker
pool** and **SQLite write serialization**. Realistically ~25–75 concurrent users
out of the box, and ~100–250 with the tuning knobs below (both set under
**Admin → Settings**):

- **`poll_interval`** (seconds, default **2**) — how often a client fetches new
  messages. Raising to 3–5s cuts requests proportionally.
- **`presence_throttle`** (seconds, default **30**) — how often the server writes
  "last seen" per user. With it, ~28 of every 30 polls become pure reads instead
  of one write each.

Benchmark your own server:

```bash
php tests/load_check.php 10 10   # concurrent requests × rounds → req/s
```

Beyond a few hundred concurrent users move to a VPS (more php-fpm workers) and/or
switch **Realtime mode → SSE** under Settings. SSE streams live updates over one
connection per client instead of repeated polls, but each connection holds a PHP
worker the whole time, so it suits php-fpm/VPS far better than shared hosting.
The client automatically falls back to polling if the stream drops.

---

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Chat page shows "Chat scripts did not RUN" | `/assets/js/app.js` is being served as HTML — broken rewrite or missing file | Re-upload the app folder; run `bash bin/deploy.sh`; verify `curl -sI https://host/assets/js/app.js` returns `Content-Type: application/javascript` |
| "Chat scripts crashed" warning | A JavaScript error at runtime | Open the browser console; the message lists the first 3 errors. Usually a stale `app.js` — re-run deploy |
| Legal-page rich editor missing/blank | `public/assets/vendor/tiptap/tiptap-bundle.js` not uploaded or stale | Re-upload it (or rebuild locally with `npm run build:editor`) and re-run deploy |
| Clean URLs return 404 (`/login`) | `.htaccess` missing or `mod_rewrite` off (Apache) | Run `bash bin/deploy.sh` (rewrites `.htaccess`); or use nginx config from section 3.2 |
| Still works but URL has `index.php/` | Rewrite not applied yet | Same as above |
| 500 on first load / "pdo_sqlite missing" | PHP lacks the SQLite driver | Install the `pdo_sqlite` extension and restart PHP-FPM |
| Database permission error | `data/` not writable by the web user | `chown -R <php-user> data/` or `chmod` to make it writable |
| Image uploads fail ("could not store") | `public/uploads/` (or `public/assets/avatars/`) not writable | Create it and make it writable by the PHP user |
| GIF picker says not configured | No Giphy API key yet | Add **Giphy API key** under Admin → Settings and tick **GIF search (Giphy)** |
| GIF picker says disabled | `gifs_enabled` off, or webhook/upload flag turned off | Turn the corresponding feature back on in Settings |
| SMTP test email fails | Wrong host/port/encryption/credentials, or port blocked | Re-check **Admin → Settings → SMTP**; use the **Send test email** button |
| `/api/version` wrong build | Old upload | Re-upload everything except `data/`, rerun `deploy.sh` |
| Slow with many users | Polling + shared PHP worker pool | Raise `poll_interval`/`presence_throttle`, or switch Realtime → SSE |

---

## 9. Optional: desktop client

The `desktop/` folder contains the **LVChat Desktop client** for
Windows/macOS/Linux. It is strictly a client: it connects to LVChat servers over
HTTP(S) and contains no server-side code. A **profile manager** window stores
multiple server profiles (name + URL, verified via `GET /api/version`), and each
server opens in its own isolated window with a persistent session — so you stay
logged in across restarts. Optionally save a username/password per server
(encrypted in the OS keychain via Electron `safeStorage`) for one-click
auto-login; accounts that require TOTP still enter the code manually.
Auto-login shows a "Logging in…" splash instead of flashing the web login page.

Desktop OS notifications work without Web Push (which Electron can't receive):
the client listens to the same events the web app already gates, so the
per-context toggles and per-channel/per-user mutes in **your profile → Push
notifications** also control desktop notifications — the opt-out is identical
on every platform. The chat's Admin links open in their own window.

```bash
cd desktop
npm install
npm run start          # run from source
npm test               # e2e tests against a local mock LVChat server
npm run dist:linux     # or dist:win / dist:mac — packages an installer
```

The desktop client's chat window is the same web app, so every unified
notification preference (masters, quiet hours, keywords, previews, per-channel
modes) applies to it too; alert decisions are made once, in the page, and the
desktop bridge simply forwards them to OS notifications with per-conversation
coalescing, an app badge, and click-to-message deep links.

The web app itself works fully in a normal browser and nothing on the server
depends on the desktop client.

---

## 10. Security notes

- Passwords: `argon2id` via `password_hash`/`password_verify`; rehashed
  automatically on login if the algorithm parameters change.
- **Admin bootstrap:** the first account only becomes admin when a
  `SETUP_TOKEN` is set in the environment *and* submitted at registration
  (`setup_token` POST field, constant-time `hash_equals` comparison). Leave it
  unset on a fresh install and nobody is admin until you promote manually.
  See §4.1.
- **MFA:** `mfa_require_admin` defaults to `1` — first login as admin forces
  TOTP enrollment; `mfa_require_staff`/`mfa_require_user` default to `0`.
- **Proxy header trust:** `client_ip()` honors `X-Forwarded-For`,
  `X-Real-IP`, `CF-Connecting-IP` only when `TRUSTED_PROXY=1`; otherwise
  spoofed headers are ignored and `REMOTE_ADDR` is used (spoof-proof on
  direct connections).
- **Host-header poisoning defence:** outbound links and WebSocket URLs use
  `APP_URL` (when set) or only hosts listed in `TRUSTED_HOSTS`; the client
  `Host` header is otherwise ignored. See §3.4.1.
- Channel keys and webhook tokens (SHA-256 of the token; the raw token is shown
  once) are stored hashed.
- CSRF tokens are required on every POST (forms and the AJAX API).
- **Login throttling** per IP: after 10 failed attempts within 10 minutes the
  next attempts are refused (`login_attempts`).
- All output is HTML-escaped; chat markup (bold/italic/code/blocks/mentions) is
  rendered from escaped input, and the legal-page rich text is sanitized against
  a tag/attribute allow-list (event handlers and `javascript:`/`data:` URIs
  stripped — including unquoted-attribute variants).
- Sending is rate-limited (per user, 12 messages/DMs per 5 seconds); global spam
  filters, shuns, and `*line` bans are enforced server-side, not just in the UI.
- Uploads are validated by real MIME sniffing (`getimagesize`), size-capped
  (5 MB chat images, 1 MB avatars), and stored with random names under `public/`;
  audio alerts are capped at 2 MB with MIME checking.
- Operators authenticate with per-user **o:lines** (**Admin → O-lines** — username
  + password + operator class). There is no shared operator password: `/oper`
  only works for the account matching the o:line's nickname. Guests live in a
  dedicated `guests` table (never in `users`), are age-gated, and are purged
  after a day of inactivity.
- The **Giphy API key** never reaches browsers — all search/trending calls go
  through the server-side `/api/gifs` proxy, and posted GIF URLs are limited to
  known media hosts. The SMTP password is write-only (never echoed back).
- There is an append-only `chat_logs` archive of every channel message and PM;
  nothing is ever removed from it. Admins have full visibility into this log.

Backups: `deploy.sh` snapshots `data/chat.db` automatically on each run; for a
full backup, `sqlite3 data/chat.db ".backup '/backup/chat.db'"` while the server
is running (safe in WAL mode) or copy the files at quiet times.

## 11. Voice, video & meetings (WebRTC / LiveKit) — optional

Voice is a **module** (`modules/webrtc`), delivered by a self-hosted **LiveKit**
SFU. The PHP app is only the control plane — auth, room mapping, the capacity
gate, host moderation, waiting rooms, and recording. Media flows browser →
LiveKit directly. The full design and API are in `docs/webrtc-implementation.md`
and `docs/protocol/voice.md`.

### 11.1 One-time setup (shared-hosting friendly)

1. **Install the LiveKit binary** somewhere on the site user's PATH:
   ```bash
   curl -sSL https://get.livekit.io | bash          # → /usr/local/bin/livekit-server
   ```
   (or drop a static release under `~/.local/bin/livekit-server`).
2. **Admin → Voice → Generate & autoconfigure keys**: writes
   `data/livekit/livekit.yaml`, starts `livekit-server` as the site user via
   `nohup`, records the pid, and enables voice. No root, no systemd, no `/etc`.
3. **Firewall** (media + signaling):
   - UDP **7880** (RTC mux), TCP **7881** (RTC TCP fallback)
   - UDP **50000–50200** (ICE range — must be open for P2P + TURN)
   - TCP **7880** (signaling/WebSocket + the Twirp admin API + `/health`)
   - coturn, if used: UDP/TCP **3478**, UDP **5349**
4. **TLS**: serve `wss://` — LiveKit's own `https_port` with the site's
   Let's Encrypt cert, or reverse-proxy `/` + `/rtc` through the existing nginx.
   Set **LiveKit URL** to the `wss://` URL.
5. **Restart on reboot**: re-run the autoconfigure button or add a cron
   `@reboot` entry for the site user.

`bin/deploy.sh` now reports/restarts a managed LiveKit daemon when voice is
enabled.

### 11.2 Recording (optional)

Install the `livekit-egress` binary + a Redis, add an `egress:` block to
`data/livekit/livekit.yaml`, then tick "Allow meeting recording" in Admin →
Voice. Without egress, the in-call Record button degrades to a friendly
"not available" — nothing else changes. Files land under `data/recordings/`
(default; configurable), download-protected by the app.

### 11.3 Capacity guidance

`voice_max_users` (default 50, up to 200) is the hard global ceiling — enforced
by the app join gate *and* stamped into every LiveKit room as `max_participants`.
`voice_talker_cap` (default 8) bounds each listener's downlink to the top-N
active speakers. Moderate quality ≈ 51 kbit/s per talking user; the worst case
for 200 users on a 100 Mbit/s host is comfortable with the talker cap in place
(see `docs/webrtc-implementation.md` §3 for the numbers).
# LVChat — Discord-style web chat (PHP + SQLite)

A modern, Discord-look web chat that pays homage to one of the greatest chat
systems of all time- IRC. Channels use `#name` names, users run `/slash` commands
(kline, ban, kick, /me, topic, and more), channel ops work with `~ & @ % +`
access levels, and a full admin dashboard mirrors IRC's operator tooling plus
the NickServ / ChanServ / MemoServ / HostServ / OperServ services.

**This is a web app, not an IRC server.** There's no IRC protocol, no IRC
daemon, and nothing for an IRC client to connect to. The IRC-style commands,
channel modes, access levels, and services are emulated natively — a deliberate
nod to IRC rather than an implementation of it.

## Features

- **Accounts** — register/login (argon2id), session auth, CSRF everywhere
- **Two-factor authentication (TOTP)** — dependency-free RFC 6238 MFA: users enroll an authenticator app from their profile (QR code or manual key) and must enter a 6-digit code at every login; admins can **require** MFA per account class (admins/staff/registered) under **Admin → Settings**, walk not-yet-enrolled users through forced setup at login, and reset a user's MFA from **Admin → Users** if they lose their device
- **Admin account tools** — admins create accounts manually (auto-generated password shown once, optional welcome email), or **invite** people by email with a sign-up link that works even when open registration is closed; pending invites can be re-sent or revoked, and any account can be permanently **deleted** (owned channels pass on; the log archive keeps history). **Admin → Invites**, **Admin → Users**.
- **Email (SMTP)** — a dependency-free SMTP client configured under **Admin → Settings** (host, port, STARTTLS/SSL, auth, from address) with a one-click **Send test email**; used for invite and welcome emails
- **Channels** — create, register/deregister (temp channels vanish when empty, founder passes on), public/private/secret, invite-only, keyed, moderated, member limits, topics, ban lists, AKICK, access lists
- **Channel settings (control panel)** — channel ops, admins, founders, and server admins/opers open a tabbed **⚙ Settings** modal from the channel header (or right-click → **Channel settings**): manage channel bans (add by nick or mask with duration + reason, remove), the registered **ops & half-ops** access list, the channel **topic** (respecting `+t`), and the **Channel URL**. Available in the web app, the desktop app, and both Messenger clients; every action reuses the same permission gates as the `/ban`, `/unban`, `/access`, and `/topic` slash commands
- **Channel URL** — an operator can give a channel a web page that opens in a **pane above the chat** (the left/right sidebars stay put; the message list takes the bottom half). The pane has a header bar (host, **Open** in a new tab, **Refresh**, collapse ▾ — remembered per channel) and a sandboxed `<iframe>`; only `http://`/`https://` pages are allowed, and the URL updates live for everyone. Set or clear it from the settings modal. Pages load through a **server-side embed proxy** (`GET /api/embed`), so sites that refuse to be framed (`X-Frame-Options` / CSP `frame-ancestors`) or are plain `http://` still render — the proxy fetches the page server-side, strips the frame blocks, injects a `<base>` so relative resources resolve to the target, and reroutes in-pane link clicks back through itself. The proxied document runs in an opaque-origin sandbox (no `allow-same-origin`), so embedded scripts can never touch the chat app. To keep that sandbox from crashing heavy JS sites (Next.js-style SPAs, consent managers), the proxy injects **resilience shims** that tolerate the opaque-origin `history`/`localStorage`/`cookie` restrictions, and re-serves the page's stylesheets and fonts through a **resource proxy** (`GET /api/embed/res`, served with `Access-Control-Allow-Origin: *`), so `@font-face` and CSS `url()` loads that would be CORS-blocked from origin `null` still render. Access is limited to signed-in sessions and guarded against SSRF (no loopback/private/link-local targets) and the **Blocked URLs** list. On **mobile the pane defaults to collapsed** (less screen space) and the page isn't fetched until you expand it; desktop defaults to expanded.
- **Admin → Blocked URLs** — a global list of domains (exact host or any subdomain) that may never be used as a Channel URL. Enforced at set time (rejected with the reason) **and** at render time (a URL whose domain gets banned later simply stops showing until the ban is lifted)
- **Friends** — registered users send/accept/decline friend requests, remove friends, block/unblock users; the right sidebar shows a Friends panel with online/offline grouping, pending requests with accept/decline buttons, and a request count badge; friend requests and acceptances appear in the notification bell; `/ignore` and `/unignore` now delegate to the friend block system
- **Admin tools** — see every user's IP, ban by nick or IP/CIDR with duration and reason (kline/gline/zline/shun), manage the bad-word filter (censor to `****` or block the whole message with a ChanServ notice) via **Admin → Bad words**, per-channel `+C` flag, and a clickable mode bar with tooltips above each channel
- **Admin presence** — operators' messages and nicks render in red throughout the chat and member lists
- **Private messages** — `/msg`, notices, ignore list, unread badges, read receipts,
  and messaging yourself (an IRC tradition kept alive)
- **Context menus** — right-click any message, user, or channel for actions (copy, edit,
  delete, report, message, profile, whois, ignore, kick, ban, share link, leave, channel info)
- **Message reports** — right-click → **Report message** on any channel or DM message with
  preset reasons plus a free-text "Other"; reports snapshot the sender, message kind, and content
  (inline images/GIFs included and rendered in the review queue) and land in **Admin → Reports**
  (staff+admin) with a review/resolve/dismiss flow
- **Moderation queue** — every time a user trips a bad-word/spam filter, or is kicked, banned,
  muted, or hit with a kline/gline/zline/shun, it's recorded in **Admin → Moderation** with the
  actor, match, and channel
- **Staff account timeline** — **Admin → Users → moderation history** keeps a staff-only record
  of every action taken against an account plus free-form staff notes; visible only to admins/staff
- **Support tickets** — registered users open tickets from the account menu (**Support**);
  staff open tickets for a registered user **or an external email address**, assign them to
  any admin/staff member, filter by status/assignee (**Admin → Support**), and the contact
  is emailed via the system SMTP settings on each staff reply
- **Age gate** — registering (or joining as a guest) requires certifying you are 18+, recorded
  on the account; registrations can be set to require **admin approval** (pending users can
  browse but not chat), and any account can be set **pending** or **suspended** with a reason
- **Legal pages** — **Terms of Service** and **Privacy Policy** (US + Nevada boilerplate) are
  editable in **Admin → Terms & Privacy** with a full kitchen-sink rich-text editor (headings,
  colours, tables, task lists, images, alignment and more), sanitized on save, and linked from
  the account menu in the chat sidebar and the login/register pages
- **Realtime** — 2s AJAX polling with presence + mention/direct-message notification bell
- **Sound alerts** — audio pings for DMs and for messages in channels you're not
  viewing, with per-context sounds, @mention pings, and per-user overrides
  (custom sound or mute) all configurable from your profile. Admins upload the
  alert sounds (**Admin → Sounds**); three defaults ship built-in and every
  sound is available to all users
- **Push notifications** — real OS/browser notifications (Web Push via the
  service worker, no external library: hand-rolled VAPID + RFC 8291 encryption
  on the built-in openssl/curl) for channel messages, DMs, and channel invites,
  delivered even when the chat is in the background or closed. Per-channel
  muting (the 🔔 button), per-user muting (mutes someone across push, the bell,
  sounds, and DM toasts — distinct from blocking), and per-context off-switches
  (channel messages / DMs / invites) all live in your profile. **On by default**:
  the first click/keystroke in the chat asks for the browser's permission and
  subscribes automatically (respecting a previous "deny" or an all-off choice),
  with a fallback **Enable** button in the chat's bell panel. Requires HTTPS.
  Notifications collapse per channel/sender and are suppressed when you're
  already looking at that conversation
- **Themes & appearance** — a server-wide theme with a library of **75 preset
  colour schemes** (curated anchors + generated sidebar/accent combinations),
  custom accent/sidebar colours, a font choice, and a chat background colour or
  image, all managed live under **Admin → Appearance**. Every registered user
  (admins included) can pick their own personal theme — preset, colours, font,
  and chat background — from their profile to override the server theme, and
  channel **owners can set a per-channel background** image/colour via the
  channel menu. Admins can switch personal customization off entirely with one
  toggle (**Admin → Appearance**).
- **Resilient sending** — the composer posts via AJAX, with a native no-JS fallback that
  still delivers the message and returns you to the channel
- **GIF search** — a Giphy-backed GIF picker in the composer (channels and DMs) with
  live search and trending; the API key is set under **Admin → Settings** and all
  search/trending calls are proxied through this server so the key never reaches browsers.
  Posted GIFs render inline and stay searchable by their title in chat history
- **Slash commands** — full parser + Discord-style autocomplete for the entire IRC-style command set (see `/help`)
- **Shareable channel links** — `/c/gaming` links that land a logged-out friend on login/register
  and bounce them back into the channel; logged-in users with the link are auto-joined
  (private channels are hidden from `/list` but joinable via their link)
- **Chat logging** — every channel (public, private, and even deleted/unregistered channels)
  and private message is written to an append-only archive visible in full to admins, with a
  per-channel participant list; nothing is ever removed
- **Custom roles & permissions** — admins create roles (name, colour, permissions) and assign
  them to users; the `oper` permission turns a regular user into an operator (the IRC-style
  elevated role). Marking a
  role as **Helper** gives its members a green nick and automatic half-op (`%`) in every channel
- **Helper users** — a distinct tier between regular users and staff; helpers are grouped
  separately in the member list, show green nicks, and receive automatic half-op in all channels
- **Analytics dashboard** — comprehensive admin-only dashboard at **Admin → Analytics** with
  time-range filtering (7d/30d/90d/all time), server-side SVG charts (no JS dependencies),
  KPI cards (users, online now, peak online, messages, PMs, censor hits, open reports),
  activity charts (messages/day, daily active users, PMs/day, registrations/day, most active
  users/channels/DM senders, activity by hour/weekday), moderation charts (censor/spam-filter
  leaders, matched words, filter hits/day, moderation action mix, ban types, report status/reasons),
  health charts (audit events/day, support tickets/day, invite stats, webhook activity)
- **Auto-join** — new users are automatically joined to `#general` on first login
- **Incoming webhooks** — Discord-compatible `POST /api/webhooks/<token>` endpoints post into
  a channel as a bot (JSON or form-encoded, `content` + `username` + `avatar_url` + `embeds`).
  Point FriendsOfFlarum/webhooks (or GitHub/GitLab/Zapier) at one per channel. Manage from
  **Admin → Webhooks**.
- **Channel operator rules** — ops can promote other members to op; half-ops get standard IRC-style
  privileges (voice/kick/ban/`+imtk`) but not op-level modes (`+l`, `+C`, `+p`, `+s`, `+o`)
- **Admin dashboard** — users, channels, global bans (kline/gline/zline/shun/qline),
  spam filters, MOTD, server settings, audit log, `/oper` privilege elevation, analytics
- **Footer links** — the browse and admin pages include a footer with project links
  (GitHub, Buy me a coffee)
- **Progressive Web App** — installable to the home screen / Start menu / dock on
  desktop and mobile (manifest + icons + service worker, always on). Installed
  apps open in their own window, load instantly, and keep previously viewed
  channels/DMs readable **offline**; "Load earlier messages" works from cache
  too. Messages sent while offline are queued in the browser and delivered
  automatically on reconnect, with an offline banner while disconnected. The
  chat header's **⬇ How to install** button walks through each platform, and
  offers a one-click **Install now** where the browser supports it. Only
  read-only pages (`/app`, `/browse`, `/terms`, `/privacy`) are cached —
  auth/admin/support pages and all other API calls are always fetched live, and
  logging out wipes a shared device's cached views.

## Requirements

- PHP 8.1+ with `pdo_sqlite`
- Node.js + npm **only if you edit views** — the compiled `public/assets/css/app.css` ships with the app, so the server never needs Node or internet access to Tailwind
- Composer **only for the WebSocket realtime gateway** — `composer install --no-dev` pulls in Workerman, used by `bin/ws-server.php`. The core app (polling/SSE) runs without composer, and `bin/deploy.sh` installs composer + runs `composer install` for you when WebSocket mode is enabled.
- The `pcntl` and `posix` PHP extensions **only for the WebSocket realtime gateway** — Workerman forks a master + worker process and needs them on Linux. The core app (polling/SSE) runs without them. On Debian/Ubuntu: `sudo apt install php-cli`, then verify with `php -m | grep -iE 'pcntl|posix'`.

## Quick start

```bash
# if you changed a view, rebuild the committed stylesheet first (only this machine needs Node):
npm install && npm run build

# dev server
php -S 127.0.0.1:8000 -t public

# production: point your web server's document root at public/, then run:
bash bin/deploy.sh
```

## Realtime gateway (WebSocket)

The optional Level 3 realtime mode moves chat delivery from polling/SSE to a
WebSocket gateway daemon (Workerman). Messages are fanned out the moment they
are persisted — no 2s polling latency — and idle clients cost a fraction of
the DB work of polling. See the [Scaling](#scaling) section for the capacity
gain per tier.

Enable it under **Admin → Settings → Realtime mode → WebSocket** (or
`config_set('realtime', 'ws')`), then run the daemon:

```bash
# one-time server prerequisites:
sudo apt install php-cli                      # pcntl + posix (required to fork workers)
php -m | grep -iE 'pcntl|posix'               # both must be listed

# start the gateway (stop|restart|status)
php bin/ws-server.php start -d
```

`composer install` is handled for you: `bin/deploy.sh` auto-installs composer
(a system install when running as root, otherwise a local `data/composer.phar`
that needs no root) and runs `composer install --no-dev` whenever `vendor/` is
missing. It also warns if the `pcntl`/`posix` extensions are absent and
auto-selects a free gateway port in the 8080–8089 range.

If `pcntl`/`posix` are missing, the gateway refuses to start with a clear
message — the chat keeps working with polling/SSE, only WebSocket mode needs
the daemon.

The daemon listens for chat clients on `ws_port` (default 8080) and exposes an
internal push endpoint on `ws_push_url` (default `http://127.0.0.1:9001/push`)
that php-fpm POSTs to after each write. Both are configurable under
**Admin → Settings** or via the `WS_IP` / `WS_PORT` / `WS_PUSH_URL` environment
variables. `bin/deploy.sh` automatically picks the **first free port in the
8080–8089 range** when the configured one is already in use, and **Admin →
Settings → Realtime mode → WebSocket** offers a manual fallback: a **Bind IP**
field and a **typeable port dropdown** listing which ports in 8080–8089 are
currently free. Restart the gateway after changing either.

**Admin → Settings** also manages the daemon without SSH: a live **gateway
status** (running/stopped, connection count, pid) with **Start / Stop /
Restart** buttons, and a **Run deploy.sh** button that opens a modal streaming
`bin/deploy.sh` output exactly like a terminal.

**WSS / TLS renewal:** when WebSocket mode serves `wss://`, `bin/deploy.sh`
stages the site's Let's Encrypt cert into `data/tls/`. On panels that keep the
cert source root-only (e.g. HestiaCP), a non-root deploy can't re-stage it —
deploy.sh now says so loudly instead of "already current". For automatic
rotation, install the certbot renewal hook and re-run as root when prompted:

```bash
sudo cp bin/le-renewal-hook.sh /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
```

See `docs/protocol/gateway.md → WSS / TLS renewal` for details.

> **Note on `proc_open`:** control panels (HestiaCP, Docker panels) sometimes
> disable `proc_open` in PHP for security. The web UI degrades automatically —
> it falls back to `popen`, then `exec`, and shows a clear message only if all
> three are disabled. For best results (full streaming with separate stderr),
> enable `proc_open` in your PHP-FPM config (`php.ini` / the domain's
> `php-fpm.conf`) and restart PHP.

For production, run
it under systemd — the unit is shown in the header of `bin/ws-server.php`:

```ini
[Unit]
Description=LVChat realtime gateway
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/chat
ExecStart=/usr/bin/php /var/www/chat/bin/ws-server.php start
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

The client falls back **ws → SSE → poll** automatically if the gateway is down,
so the chat never goes quiet. `bash bin/deploy.sh` health-checks the daemon
when realtime mode is set to WebSocket.

## Upgrading / reinstalling (important)

The entire install is **PHP + a committed stylesheet** — the server needs no Node, no npm,
and no internet access to a CDN. `public/assets/css/app.css` and `public/assets/js/app.js`
ship with the app folder (both are cache-busted by file-modified-time so browsers never
serve stale copies after an upload).

To deploy or upgrade, do exactly this:

1. Upload the app folder (everything **except** anything under `data/`, which is runtime state).
2. Run `bash bin/deploy.sh` on the server.

`bin/deploy.sh`:
- (re)writes `public/.htaccess` — so even uploaders that strip dotfiles get clean URLs back
- migrates/backs up the SQLite database
- checks PHP + `pdo_sqlite`, verifies `app.js` exists and is served as JavaScript (not a rewritten HTML page), prints the app version from `/api/version`, and smoke-tests `/`, `/login`, `/register` over a local server

The chat page shows a **warning bar if its scripts fail to load** (e.g. an old or partial
upload), naming the fix: "Re-upload the app folder and run `bash bin/deploy.sh`." Print
`/api/version` to confirm which build a server is running.

The database lives at `data/chat.db` **inside the project**, one folder beside the `public/`
docroot. Because the web server only serves `public/`, the `data/` folder is never
web-accessible, and the path stays inside `open_basedir` on shared hosts. Override the
location with the `CHAT_DB` environment variable. `bin/deploy.sh` backs the database up on
every run and migrates an older `../data/chat.db` location into place automatically.

If `.htaccess` is ever missing, the app still responds at `index.php/<path>` — but clean
URLs come back as soon as you run `bin/deploy.sh`.

## Update system

LVChat ships with an update feed so admins and users always know the latest
version of everything, and so desktop clients can self-update.

- **`update-server/`** is a small, zero-dependency PHP web app that publishes the
  current versions + download links for all three apps (web, desktop, messenger).
  `data/releases.json` is the single source of truth; it serves a JSON manifest
  (`/manifest.json`), stable `/downloads/<app>/<platform>` redirects, and the
  electron-updater `latest*.yml` feeds. See `update-server/README.md`.
- **Upstream vs custom.** A server admin points their install at an update server
  under **Admin → Settings → Updates** (`updater_url`, on by default when set).
  The chat's download modal and the **Admin → Updates** page then resolve latest
  versions and links from that feed. The existing per-platform **custom** download
  fields still win when filled in — that's the white-label mechanism (community
  builds with their own URLs, or a mirror). Empty fields fall back to upstream.
- **Web app updates.** **Admin → Updates** compares the installed version against
  the feed, shows release notes, and can download the verified archive
  (sha256-checked) into `data/updates/` for a manual upload + `bash bin/deploy.sh`.
  One-click auto-swap is deliberately not automatic. A cron-friendly check:
  `php bin/check-updates.php` (exits `1` when something is outdated).
- **Desktop & Messenger updates.** Both Electron apps use `electron-updater`
  against the upstream feed (set at build time via `build.publish`). A quiet
  background check runs on launch and every 12h; the Profile Manager footer shows
  the status with a **Check for updates** button and install prompts. A server
  that advertises its own feed (`updater_url` in `/api/version`) can be opted into
  per profile ("Use this server's updates") for white-labelled builds. **Note:**
  silent auto-install on macOS/Windows needs code-signed artifacts; unsigned
  builds fall back to download prompts.
- **API.** `GET /api/version` now includes `updater_url`; `GET /api/updater`
  returns the effective per-app versions + links this server serves its users
  (custom overrides win).

## Default channels

On a fresh database the following channels are created automatically:

| Channel | Visibility | Who can join |
|---|---|---|
| `#general` | public | everyone |
| `#help` | public | everyone |
| `#staff` | staff | admins and `staff`-role users only |
| `#oper-log` | private | server admins only |

Promote a user to **staff** (or back) from **Admin → Users**. `#staff` is never shown in
the channel browser and its share link is rejected for non-staff.

`#oper-log` is the admin-only operator action log. Every event performed by a
server admin or an o:line holder (OperServ commands and admin-panel actions
alike) is mirrored there as a `notice` line, with the actor's nick and — when
operating — their oper class. Actions taken by plain channel operators (who are
neither admins nor o:line holders) are excluded. Admins are auto-joined when
they first act (or when promoted); demoted admins lose access.

## Command map (see `/help` in-app)

| Group | Commands |
|---|---|
| Core | help, join, part, quit, me, msg/pm/query, notice, nick, away, back, whois, list, channels, topic, ping, invite, knock, ignore, clear, info, share |
| Channel Ops | kick, kickban, ban, unban, quiet, op, deop, halfop, dehalfop, voice, devoice, mode, topiclock, clear |
| ChanServ | register, unregister, drop, identify, set, access, akick, transfer, getkey, senak, chaninfo, forbid, cs |
| NickServ | register, identify, logout, set, ghost, release, recover, status, info, group, rename, ns |
| MemoServ | memo, ms (send/read/list/del/summary/set) |
| HostServ | vhost, hs |
| OperServ | oper, kline, gline, zline, shun (+ un* variants), kill, global, wallops, motd, sajoin, sapart, samode, sanick, sasethost, sqline, unsqline, sqlines, cqline, uncqline, cqlines, spamfilter, badword, clients, serverstats, rehash, notice |

**Forbidden nicks & channels.** `/sqline` (and the Admin → Bans page) forbids a
nickname; `/unsqline` and `/sqlines` remove/list them. Q-lines are enforced on
registration, `/nick`, guest logins, and `/sanick` targets. Channel-name
c-lines (`/cqline`, `/uncqline`, `/cqlines`) forbid a channel name or mask
(e.g. `#ads*`) so neither creating nor joining a matching channel is allowed.

**`/sanick`** force-renames a registered user (`/sanick <oldnick> <newnick>`) and is
restricted to server admins and opers operating with the `netadmin` o:line class. If
the requested nick is already registered to someone or held by a live guest, it replies
`Requested nick is unavailable, please select another`. On success the registration is
updated (including the user's o:line, if any), a `nick` event is broadcast to every
channel they're in, and the renamed user gets a direct message about the change.

**Channel modes** via `/mode` (also managed by the toggle bar above every channel, with
mouseover tooltips): `+i` invite-only, `+m` moderated, `+C` word filter, `+k` key, `+l` limit,
`+t` topic-lock, `+p`/`+s` private/secret visibility, `+b`/`-b` bans, plus `+vhoaq` levels.
Run `/mode` with no flags to see the full explanation of every mode inline.

**Levels:** `~` founder, `&` admin, `@` op, `%` halfop, `+` voice.

## Admin setup

There are **no hardcoded default credentials**. The first account registered on a fresh
database automatically becomes the server admin (the second and later accounts are regular
users). Log in as that account to reach `/admin`.

After that, admins can promote other users from the **Users** page, or create an **o:line**
(an IRC-style operator line) in **Admin → O-lines** (username + password + operator class)
so a user can `/oper` up
against their own nick and gain that class's permissions. Default classes: `netadmin`,
`serveradmin`, `globalop`, `localop` (custom classes via **Admin → Operclasses**). There is
no shared operator password. (You can also set the `users.role` column directly in SQLite
if you ever need to recover admin access.)

**GIF search** needs a free [Giphy API key](https://developers.giphy.com) — add it under
**Admin → Settings → Giphy API key** and tick **GIF search (Giphy)**. Until a key is added,
the picker explains that GIF search isn't configured. The picker shows trending GIFs on
open and searches Giphy as you type; clicking one posts it to the current channel or DM.

## Scaling

Capacity by tier and realtime mode (concurrent users, rough order of magnitude):

| Tier | Polling (2s) | Polling (3–5s) | SSE | WebSocket* |
|---|---|---|---|---|
| Shared hosting | 25–75 | 100–250 | worker-bound | — |
| InterServer base VPS (1c/2GB) | 50–100 | 100–200 | 30–60 | 500–1,000 |
| InterServer VPS (2c/6GB) | 100–200 | 200–400 | 80–150 | 1,500–3,000 |
| VPS (4c/16GB) | 300–500 | 500–800 | 150–250 | 5,000–10,000 |
| Dedicated 3700X (32GB) | 800–1,500 | 1,500–3,000+ | 300–500 | 20,000–50,000 |
| Dedicated 3700X (64–128GB) | 1,500–3,000 | 3,000–5,000 | 500–900 | 20,000–50,000+ |

\* WebSocket = the Level 3 realtime gateway (`bin/ws-server.php`, Workerman). It holds a
persistent connection per client like SSE, but off PHP-FPM entirely, and fanned-out
messages are delivered the moment they're persisted. WebSocket figures assume the
gateway runs on the same box as php-fpm. Shared hosting cannot run a long-lived daemon,
so no WebSocket row there.

### Shared hosting

With PHP + SQLite + polling, the ceiling on shared hosting is the **PHP worker pool** and
**SQLite write serialization**, not the database size. Realistically that's ~25–75 concurrent
users out of the box, and ~100–250 with the two tuning knobs below:

- **`poll_interval`** (seconds, default 2) — how often each client fetches new messages.
  Raising it to 3–5s cuts requests proportionally.
- **`presence_throttle`** (seconds, default 30) — how often the server writes "last seen"
  per user. With it, ~28 of every 30 polls become pure reads instead of a write each time.

Both are settable under **Admin → Settings**. The deciding factor is the host's PHP worker
count (Litespeed/higher tiers give more). Measure your own server with:

```bash
php tests/load_check.php 10 10   # concurrent requests × rounds → req/s
```

### InterServer base VPS (1 core, 2GB RAM)

**50–200 concurrent users** — the entry tier above shared hosting:

- **Polling (2s)**: 50–100 users
- **Polling (3–5s)**: 100–200 users
- **SSE mode**: 30–60 users (each holds a PHP worker)
- **WebSocket**: 500–1,000 users
- `pm.max_children` = 30–50

The single core is the hard ceiling — you're CPU-bound long before RAM. 2GB leaves
~1GB for OS + SQLite cache after ~40 PHP-FPM workers (~30–40MB each). Fine for a small
community or beta; the 2-core/6GB tier is the better price jump at roughly double the
capacity.

### VPS (4 vCPU Xeon Silver, 16GB RAM)

**300–800 concurrent users** depending on configuration:

- **Polling (2s)**: 300–500 users
- **Polling (3–5s)**: 500–800 users
- **SSE mode**: 150–250 users (each holds a PHP worker)

**Recommended tuning**:
1. PHP-FPM `pm.max_children` = 150–200
2. Enable OPcache
3. Set `poll_interval` = 3–5s (Admin → Settings)
4. Keep `presence_throttle` = 30s+
5. Use Nginx over Apache

SQLite with WAL mode handles this workload well. The bottleneck shifts from database to PHP worker pool at this scale. Beyond ~800 concurrent, consider PostgreSQL/MySQL or horizontal scaling.

### Dedicated server (AMD Ryzen 3700X, 8c/16t)

The 3700X's 8 physical cores and ~1.5–2x better single-thread performance over Xeon Silver
provide a large jump. Likely NVMe storage — SQLite WAL loves fast I/O.

| RAM | Polling (2s) | Polling (3–5s) | SSE | `pm.max_children` |
|---|---|---|---|---|
| **32GB (sweet spot)** | 800–1,500 | 1,500–3,000+ | 300–500 | 400–500 |
| 64GB | 1,500–2,500 | 2,500–5,000 | 500–800 | 800–1,000 |
| 128GB | 1,500–3,000 | 3,000–5,000 | 600–900 | 1,000–1,500 |

**32GB is the sweet spot** for this CPU. Each PHP-FPM worker runs ~20–40MB for this app,
so 400–500 workers = ~12–16GB working set with plenty left for OS + SQLite page cache.
The 3700X hits its CPU ceiling around 3,000–5,000 concurrent regardless, so extra RAM
mostly sits idle.

**64GB** is worth it only if the price delta is small, you plan to run MySQL/PostgreSQL
alongside, or you want room to upgrade the CPU later. At 64GB, **CPU becomes the
bottleneck** — 16 threads can only process so many requests per second regardless of
worker count.

**128GB** offers diminishing returns. You're fully CPU-bound and the extra RAM sits idle.
SQLite also becomes the bottleneck here with single-writer serialization under heavy
write load. Past 64GB on this CPU, you're spending money for no gain.

To go beyond ~5,000 concurrent, you need more cores (e.g., Ryzen 5950X 16c/32t, EPYC,
or multiple servers) — not more RAM. At that scale, migrating off SQLite to
PostgreSQL/MySQL is also warranted.

**Recommended tuning (all RAM tiers)**:
1. OPcache enabled, `memory_consumption` = 256MB
2. `pm = dynamic` with `max_spare_servers` = 100–150
3. Nginx + php-fpm (Unix socket)
4. `poll_interval` = 3–5s for headroom

At this tier, SQLite remains viable well past 2,000 concurrent. The write serialization
bottleneck only shows up under heavy message-send storms, not reads. You'd need to be
PostgreSQL/MySQL territory around ~5,000+ concurrent before SQLite becomes the ceiling.

### General recommendations

Beyond a few hundred concurrent users, move to a VPS (php-fpm with more workers) and/or
switch realtime from polling to **SSE**: set **Realtime mode → SSE** under Admin → Settings.
SSE streams live updates over one connection per client instead of repeated polls, but each
connection holds a PHP worker, so it suits php-fpm/VPS far better than shared hosting. The
client automatically falls back to polling if the stream drops.

A **Level 3 realtime gateway** (`bin/ws-server.php`, Workerman, `realtime = 'ws'`)
moves realtime connections off PHP-FPM entirely, multiplying per-tier capacity
5–20x (see the summary table above). Enable it in **Admin → Settings** and keep
the daemon running under systemd — see "Realtime gateway (WebSocket)" above.

## Testing

```bash
bash bin/test.sh
```

Runs **1090 automated assertions** in three layers:

- **`tests/smoke.php`** (598) — every slash command and service against a scratch DB:
  registration/login, channels, messaging, all Core/Channel-Op/ChanServ/NickServ/
  MemoServ/HostServ/OperServ commands, private/keyed/staff channels, bans, mentions,
  share links, guests, webhooks, account invites + SMTP, age verification, the
  moderation queue (filter hits, kicks, *lines), pending/suspended account status,
  staff notes, support tickets, legal-page sanitisation, forbidden nicks/channels
  (sqline/cqline), `/sanick` gating + availability, the `#oper-log` mirror, the
  channel-URL validator and banned-domain list (exact + subdomain), and the
  ChannelService URL/`canManageChannel` helpers.
- **`tests/http_test.php`** (487) — full HTTP end-to-end: spins up the built-in server
  and drives registration, CSRF enforcement, channel CRUD, send/poll/command APIs,
  private messages (including image attachments), admin pages & actions (including invites,
  manual user creation, user deletion and SMTP settings), private/keyed/staff channel flows,
  message reports, moderation/reports/support/legal admin pages, pending-approval and
  suspended login flows, share-link redirects, webhooks, logout, the channel-settings
  API (bans / access list / topic / URL with the full permission matrix), the admin
  **Blocked URLs** page and `banned_url` actions, the `channel_url` / `url_banned`
  poll fields, the web-messenger **allowed origins** (CORS) setting, the messenger
  room-browser API (`/api/browse`), the **kick** flow (target's poll returns a
  one-shot redirect carrying the kick reason + actor), the **channel-URL
  embed proxy** (`/api/embed`: framing-header stripping, injected `<base>`,
  redirects, non-HTML passthrough, opaque-origin resilience shims, stylesheet
  rewrite, plus auth/banned-domain/SSRF guards) and its **resource proxy**
  (`/api/embed/res`: CSS `url()`/`@import` rewriting, `Access-Control-Allow-Origin: *`
  re-serving of fonts/styles, same guards), and the
  web-messenger **bearer-token auth** (login/mfa/logout, header-authenticated
  `/api/me` + POSTs without cookies, token revocation, single-use MFA tickets,
  and the CORS preflight allowing the custom headers).
- **`tests/ws_test.php`** (12) — WebSocket gateway integration: spawns the realtime
  daemon against a scratch DB and verifies ticket auth, channel/DM subscriptions,
  and message/msg-update fan-out (ports via `WS_PORT` / `WS_PUSH_PORT`).

The two desktop clients also ship their own Electron end-to-end suites
(`npm test` in each folder — see [Desktop clients](#desktop-clients)).

## Desktop clients

Two native Electron apps live in this repository. Both are **pure clients** —
they connect to LVChat servers over HTTP(S) and contain no server-side code —
and the web app itself runs fully in any browser with nothing depending on them.

### LVChat Desktop (`desktop/`) — the web app as a native client

The web-wrapper client. A **Profile Manager** window stores multiple server
profiles (name + URL, verified against `GET /api/version`), and each server
opens in its own isolated window with a persistent session — you stay logged in
across restarts. Optionally save a username/password per server, encrypted in
the OS keychain (Electron `safeStorage`), for **one-click auto-login**: a hidden
helper window performs the real session login so the web login form never
flashes (a "Logging in…" splash plays instead), and accounts protected by
**TOTP/MFA** are handed to the MFA page so you can enter the code. The **Admin**
links in the chat open the dashboard in its own window sharing the profile's
session.

Desktop **OS notifications** work without Web Push (which Electron can't
receive): the client bridges the same events the web app already gates, so your
**Profile → Push notifications** preferences — per-context toggles and
per-channel/per-user mutes — control desktop alerts identically on every
platform. A tray icon and app menus manage servers and windows, per-server
auto-connect on startup is supported, and a test-notification button verifies
the OS pipeline.

```bash
cd desktop
npm install && npm start      # run
npm test                      # e2e tests against a local mock LVChat server
npm run dist:linux            # or dist:win / dist:mac — packages an installer
```

### LVChat Messenger (`lvchat-messenger/`) — IM-first client

A separate Electron app (vanilla HTML/CSS/JS, no bundler) that is IM-first: a
buddy list with custom contact groups, DMs, and joined rooms — the basis for
future native mobile apps. It lives side by side with `desktop/` and does not
modify the web app. Build/run from inside the folder:

```bash
cd lvchat-messenger
npm install && npm start      # run
npm test                      # mock-server end-to-end suite
npm run dist                  # package installers (electron-builder)
```

The messenger talks to this server's JSON API cross-origin with a **bearer session
token** (`X-LVC-Session`, kept in localStorage) — no reliance on third-party cookies, so
it works on phones where browsers block them — while the browser's cookies remain
supported for compatibility. The server still needs CORS enabled for the app's origin.
This is on by default for `null` (file://) and any `http://127.0.0.1:*` origin. To allow
other origins (web/mobile builds), add them under **Admin → Settings → Web messenger
clients** (writes the `app_origins` config key), or set the `CHAT_CORS_ORIGINS` env var,
to a comma-separated list, e.g. `https://app.example.com`. CORS headers are only emitted
when an allowlisted `Origin` header is present — normal web-app traffic is untouched.

The messenger has two layouts, toggled from the sidebar header and persisted in the app's
local settings (`viewMode`, default **Compact**):

- **Compact** — a Pidgin-style buddy/room list window with tabs. Double-click a friend or
  room to open that conversation in its **own dedicated window** (each chat is a separate
  window, deduped). Right-click a friend, room, group header, or message for actions
  (open in new window, message, view profile, add-to-group, copy share link, leave room,
  rename/delete group, copy message).
- **Advanced** — the single-window layout with the chat pane beside the buddy list;
  single-click a contact or room to switch the pane.

The **Profile Manager** (add/edit server) offers a **Register account** action that opens
that LVChat server's `/register` page in your browser.

Messenger-specific API (additive; authenticated by the session cookie **or** the
messenger's `X-LVC-Session` bearer token):

| Endpoint | Purpose |
|---|---|
| `GET /api/me` | current session's account (id, username, avatar) |
| `GET /api/csrf` | the session's CSRF token for app clients |
| `POST /api/messenger/login` | token login (`X-Messenger: 1` header; returns `{ok, token}` / `{mfa, ticket}` / `{error}`) |
| `POST /api/messenger/mfa` | complete a token login with a TOTP code (`{ticket, code}`) |
| `POST /api/messenger/logout` | revoke the bearer session |
| `GET /api/directory?q=` | user-directory search with relationship status (find people to add) |
| `GET /api/groups`, `POST /api/groups`, `/rename`, `/delete`, `/member/add`, `/member/remove` | custom contact groups ("nodes") |
| `POST /api/channel/read` | mark a room read (clears its unread badge) |

Contact groups live in `contact_groups` + `contact_group_members` (see `schema.sql`);
membership is enforced to accepted friends. Directory search + groups + the read endpoint
are exercised in `tests/http_test.php`.

### LVChat Messenger Web (`messenger-web/`) — the messenger as a PWA

A web build of the messenger that runs in any browser and is installable as a
Progressive Web App. Like the desktop apps it is a **pure client**; unlike the
multi-profile launcher it connects to a **single** LVChat server that the admin
sets in `messenger-web/.env` (`LVCHAT_SERVER_URL`). It reuses the messenger's
IM-first UI (buddy list, groups, DMs, rooms, offline send queue) and adds
Web Push for closed-tab notifications.

```bash
cd messenger-web
cp .env.example .env       # set LVCHAT_SERVER_URL
npm run build              # static dist/ (config baked in) — host anywhere
npm run preview            # local preview on http://127.0.0.1:8080
npm test                   # build + bridge + API-login smoke suite
```

The client talks to the server cross-origin using a **bearer session token** (kept in
localStorage, sent as `X-LVC-Session`) instead of relying on the session cookie — so it
signs in and stays signed in even on phones where browsers block third-party cookies
(mobile Safari). The messenger's origin still needs allowlisting for the API's CORS
headers: add it under **Admin → Settings → Web messenger clients** (or set
`CHAT_CORS_ORIGINS`) to e.g. `https://msg.example.com`. HTTPS is required on both ends.
Web Push uses the server's VAPID key exposed via `/api/me` (`vapidPublicKey`). See
`messenger-web/README.md`.

## Layout

```
public/          front controller + .htaccess + JS + sounds + PWA (sw.js, manifest, icons, offline.html)
src/             bootstrap, router, DB, auth, services (commands), controllers
views/           layout, auth, chat app, browse, admin pages
bin/deploy.sh    post-upload restore + sanity check
bin/ws-server.php   Workerman realtime gateway (WebSocket + internal push endpoint)
bin/make-icons.php  regenerate the PWA icon set (public/assets/pwa/*.png)
bin/check-updates.php  CLI update check against the configured feed (cron-friendly)
bin/test.sh      run the full automated test suite
tests/smoke.php  598 command/service assertions
tests/http_test.php  480 HTTP assertions
tests/ws_test.php   WebSocket gateway integration test (spawns the daemon)
composer.json    Workerman dependency for the realtime gateway (vendor/ is server-side)
schema.sql       SQLite schema (applied on boot)
data/            SQLite database (beside public/, never web-served)
desktop/         LVChat Desktop — the web app as a native Electron client (see above)
lvchat-messenger/   LVChat Messenger — IM-first Electron client (see above)
update-server/   the update feed web app (versions + download links + electron feeds)
```

The **PWA layer** ships as committed files — `public/sw.js` (service worker),
`public/assets/pwa/*.png` (icons, regenerable via `php bin/make-icons.php`),
`public/offline.html` (offline fallback page), a `/manifest` route generated
from the site name, and the `views/partials/pwa.php` head block. `bin/deploy.sh`
verifies the service worker, manifest MIME type, and icons after every upload.

## Security notes

- Passwords: argon2id (`password_hash`), channel keys hashed, all queries prepared
- CSRF tokens on every POST; HTML-escaped output with a small safe-markup renderer
- Rate limiting on sends; spam filters, shuns, and global bans enforced server-side
- Operators authenticate with per-user **o:lines** (IRC-style operator lines, **Admin → O-lines**) against an
  operator class (netadmin, serveradmin, globalop, localop, or custom) — there is no
  shared operator password; `/oper` only works for the nick the o:line is tied to

## License

LVChat is free software released under the **GNU Affero General Public License,
version 3 only** (SPDX `AGPL-3.0-only`) — see `LICENSE`. Modules shipped in this
repository (e.g. `modules/webrtc`) are AGPL-3.0-only like the core; modules
distributed separately may carry their own license. Bundled third-party code
keeps its own license (see `THIRD_PARTY_NOTICES.md`). Contributions are accepted
under the Developer Certificate of Origin — see `CONTRIBUTING.md` and
`docs/licensing.md`.

# LVChat — Discord-style IRC web chat (PHP + SQLite)

A modern, Discord-look chat that speaks fluent IRC. Channels use `#name` names, users
use `/slash` commands in the standard IRC format (kline, ban, kick, /me, topic, etc.),
channel ops work with `~ & @ % +` access levels, and a full admin dashboard mirrors
UnrealIRCd's IRCop tooling plus the NickServ / ChanServ / MemoServ / HostServ / OperServ
services from Anope.

## Features

- **Accounts** — register/login (argon2id), session auth, CSRF everywhere
- **Two-factor authentication (TOTP)** — dependency-free RFC 6238 MFA: users enroll an authenticator app from their profile (QR code or manual key) and must enter a 6-digit code at every login; admins can **require** MFA per account class (admins/staff/registered) under **Admin → Settings**, walk not-yet-enrolled users through forced setup at login, and reset a user's MFA from **Admin → Users** if they lose their device
- **Admin account tools** — admins create accounts manually (auto-generated password shown once, optional welcome email), or **invite** people by email with a sign-up link that works even when open registration is closed; pending invites can be re-sent or revoked, and any account can be permanently **deleted** (owned channels pass on; the log archive keeps history). **Admin → Invites**, **Admin → Users**.
- **Email (SMTP)** — a dependency-free SMTP client configured under **Admin → Settings** (host, port, STARTTLS/SSL, auth, from address) with a one-click **Send test email**; used for invite and welcome emails
- **Channels** — create, register/deregister (temp channels vanish when empty, founder passes on), public/private/secret, invite-only, keyed, moderated, member limits, topics, ban lists, AKICK, access lists
- **Friends** — registered users send/accept/decline friend requests, remove friends, block/unblock users; the right sidebar shows a Friends panel with online/offline grouping, pending requests with accept/decline buttons, and a request count badge; friend requests and acceptances appear in the notification bell; `/ignore` and `/unignore` now delegate to the friend block system
- **Admin tools** — see every user's IP, ban by nick or IP/CIDR with duration and reason (kline/gline/zline/shun), manage the bad-word filter (censor to `****` or block the whole message with a ChanServ notice) via **Admin → Bad words**, per-channel `+C` flag, and a clickable mode bar with tooltips above each channel
- **Admin presence** — operators' messages and nicks render in red throughout the chat and member lists
- **Private messages** — `/msg`, notices, ignore list, unread badges, read receipts,
  and messaging yourself (an IRC hallmark)
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
- **Resilient sending** — the composer posts via AJAX, with a native no-JS fallback that
  still delivers the message and returns you to the channel
- **GIF search** — a Giphy-backed GIF picker in the composer (channels and DMs) with
  live search and trending; the API key is set under **Admin → Settings** and all
  search/trending calls are proxied through this server so the key never reaches browsers.
  Posted GIFs render inline and stay searchable by their title in chat history
- **Slash commands** — full parser + Discord-style autocomplete for the entire IRC/Anope command set (see `/help`)
- **Shareable channel links** — `/c/gaming` links that land a logged-out friend on login/register
  and bounce them back into the channel; logged-in users with the link are auto-joined
  (private channels are hidden from `/list` but joinable via their link)
- **Chat logging** — every channel (public, private, and even deleted/unregistered channels)
  and private message is written to an append-only archive visible in full to admins, with a
  per-channel participant list; nothing is ever removed
- **Custom roles & permissions** — admins create roles (name, colour, permissions) and assign
  them to users; the `oper` permission turns a regular user into an IRC Operator. Marking a
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
- **Channel operator rules** — ops can promote other members to op; half-ops get standard IRC
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

## Quick start

```bash
# if you changed a view, rebuild the committed stylesheet first (only this machine needs Node):
npm install && npm run build

# dev server
php -S 127.0.0.1:8000 -t public

# production: point your web server's document root at public/, then run:
bash bin/deploy.sh
```

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

## Default channels

On a fresh database the following channels are created automatically:

| Channel | Visibility | Who can join |
|---|---|---|
| `#general` | public | everyone |
| `#help` | public | everyone |
| `#staff` | staff | admins and `staff`-role users only |

Promote a user to **staff** (or back) from **Admin → Users**. `#staff` is never shown in
the channel browser and its share link is rejected for non-staff.

## Command map (see `/help` in-app)

| Group | Commands |
|---|---|
| Core | help, join, part, quit, me, msg/pm/query, notice, nick, away, back, whois, list, channels, topic, ping, invite, knock, ignore, clear, info, share |
| Channel Ops | kick, kickban, ban, unban, quiet, op, deop, halfop, dehalfop, voice, devoice, mode, topiclock, clear |
| ChanServ | register, unregister, drop, identify, set, access, akick, transfer, getkey, senak, chaninfo, forbid, cs |
| NickServ | register, identify, logout, set, ghost, release, recover, status, info, group, rename, ns |
| MemoServ | memo, ms (send/read/list/del/summary/set) |
| HostServ | vhost, hs |
| OperServ | oper, kline, gline, zline, shun (+ un* variants), kill, global, wallops, motd, sajoin, sapart, samode, sanick, sasethost, sqline, spamfilter, badword, clients, serverstats, rehash, notice |

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
in **Admin → O-lines** (username + password + operator class) so a user can `/oper` up
against their own nick and gain that class's permissions. Default classes: `netadmin`,
`serveradmin`, `globalop`, `localop` (custom classes via **Admin → Operclasses**). There is
no shared operator password. (You can also set the `users.role` column directly in SQLite
if you ever need to recover admin access.)

**GIF search** needs a free [Giphy API key](https://developers.giphy.com) — add it under
**Admin → Settings → Giphy API key** and tick **GIF search (Giphy)**. Until a key is added,
the picker explains that GIF search isn't configured. The picker shows trending GIFs on
open and searches Giphy as you type; clicking one posts it to the current channel or DM.

## Scaling

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

## Testing

```bash
bash bin/test.sh
```

Runs **558 automated assertions** in two layers:

- **`tests/smoke.php`** (379) — every slash command and service against a scratch DB:
  registration/login, channels, messaging, all Core/Channel-Op/ChanServ/NickServ/
  MemoServ/HostServ/OperServ commands, private/keyed/staff channels, bans, mentions,
  share links, guests, webhooks, account invites + SMTP, age verification, the
  moderation queue (filter hits, kicks, *lines), pending/suspended account status,
  staff notes, support tickets, and legal-page sanitisation.
- **`tests/http_test.php`** (179) — full HTTP end-to-end: spins up the built-in server
  and drives registration, CSRF enforcement, channel CRUD, send/poll/command APIs,
  private messages (including image attachments), admin pages & actions (including invites,
  manual user creation, user deletion and SMTP settings), private/keyed/staff channel flows,
  message reports, moderation/reports/support/legal admin pages, pending-approval and
  suspended login flows, share-link redirects, webhooks, and logout.

A headless-browser check (Chrome DevTools Protocol) is also used during development to
confirm the chat page loads without JS errors, fills the viewport, polls for messages,
and sends messages from the UI.

## Layout

```
public/          front controller + .htaccess + JS + sounds + PWA (sw.js, manifest, icons, offline.html)
src/             bootstrap, router, DB, auth, services (commands), controllers
views/           layout, auth, chat app, browse, admin pages
bin/deploy.sh    post-upload restore + sanity check
bin/make-icons.php  regenerate the PWA icon set (public/assets/pwa/*.png)
bin/test.sh      run the full automated test suite
tests/smoke.php  319 command/service assertions
tests/http_test.php  122 HTTP assertions
schema.sql       SQLite schema (applied on boot)
data/            SQLite database (beside public/, never web-served)
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
- Operators authenticate with per-user **o:lines** (Admin → O-lines) against an
  operator class (netadmin, serveradmin, globalop, localop, or custom) — there is no
  shared operator password; `/oper` only works for the nick the o:line is tied to

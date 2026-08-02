# LVChat — Discord-style IRC web chat (PHP + SQLite)

A modern, Discord-look chat that speaks fluent IRC. Channels use `#name` names, users
use `/slash` commands in the standard IRC format (kline, ban, kick, /me, topic, etc.),
channel ops work with `~ & @ % +` access levels, and a full admin dashboard mirrors
UnrealIRCd's IRCop tooling plus the NickServ / ChanServ / MemoServ / HostServ / OperServ
services from Anope.

## Features

- **Accounts** — register/login (argon2id), session auth, CSRF everywhere
- **Channels** — create, register/deregister (temp channels vanish when empty, founder passes on), public/private/secret, invite-only, keyed, moderated, member limits, topics, ban lists, AKICK, access lists
- **Admin tools** — see every user's IP, ban by nick or IP/CIDR with duration and reason (kline/gline/zline/shun), manage the bad-word filter (censor to `****` or block the whole message with a ChanServ notice) via **Admin → Bad words**, per-channel `+C` flag, and a clickable mode bar with tooltips above each channel
- **Admin presence** — operators' messages and nicks render in red throughout the chat and member lists
- **Private messages** — `/msg`, notices, ignore list, unread badges, read receipts,
  and messaging yourself (an IRC hallmark)
- **Context menus** — right-click any message, user, or channel for actions (copy, edit,
  delete, message, profile, whois, ignore, kick, ban, share link, leave, channel info)
- **Realtime** — 2s AJAX polling with presence + mention/direct-message notification bell
- **Resilient sending** — the composer posts via AJAX, with a native no-JS fallback that
  still delivers the message and returns you to the channel
- **Slash commands** — full parser + Discord-style autocomplete for the entire IRC/Anope command set (see `/help`)
- **Shareable channel links** — `/c/gaming` links that land a logged-out friend on login/register
  and bounce them back into the channel; logged-in users with the link are auto-joined
  (private channels are hidden from `/list` but joinable via their link)
- **Chat logging** — every channel (public, private, and even deleted/unregistered channels)
  and private message is written to an append-only archive visible in full to admins, with a
  per-channel participant list; nothing is ever removed
- **Custom roles & permissions** — admins create roles (name, colour, permissions) and assign
  them to users; the `oper` permission turns a regular user into an IRC Operator
- **Channel operator rules** — ops can promote other members to op; half-ops get standard IRC
  privileges (voice/kick/ban/`+imtk`) but not op-level modes (`+l`, `+C`, `+p`, `+s`, `+o`)
- **Admin dashboard** — users, channels, global bans (kline/gline/zline/shun/qline),
  spam filters, MOTD, server settings, audit log, `/oper` privilege elevation

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

## Testing

```bash
bash bin/test.sh
```

Runs **212 automated assertions** in two layers:

- **`tests/smoke.php`** (213) — every slash command and service against a scratch DB:
  registration/login, channels, messaging, all Core/Channel-Op/ChanServ/NickServ/
  MemoServ/HostServ/OperServ commands, private/keyed/staff channels, bans, mentions,
  share links, and the admin dashboard data.
- **`tests/http_test.php`** (52) — full HTTP end-to-end: spins up the built-in server
  and drives registration, CSRF enforcement, channel CRUD, send/poll/command APIs,
  private messages, admin pages & actions, private/keyed/staff channel flows, share-link
  redirects, and logout.

A headless-browser check (Chrome DevTools Protocol) is also used during development to
confirm the chat page loads without JS errors, fills the viewport, polls for messages,
and sends messages from the UI.

## Layout

```
public/          front controller + .htaccess + JS
src/             bootstrap, router, DB, auth, services (commands), controllers
views/           layout, auth, chat app, browse, admin pages
bin/deploy.sh    post-upload restore + sanity check
bin/test.sh      run the full automated test suite
tests/smoke.php  213 command/service assertions
tests/http_test.php  52 HTTP assertions
schema.sql       SQLite schema (applied on boot)
data/            SQLite database (beside public/, never web-served)
```

## Security notes

- Passwords: argon2id (`password_hash`), channel keys hashed, all queries prepared
- CSRF tokens on every POST; HTML-escaped output with a small safe-markup renderer
- Rate limiting on sends; spam filters, shuns, and global bans enforced server-side
- Operators authenticate with per-user **o:lines** (Admin → O-lines) against an
  operator class (netadmin, serveradmin, globalop, localop, or custom) — there is no
  shared operator password; `/oper` only works for the nick the o:line is tied to

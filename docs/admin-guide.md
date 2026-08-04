# LVChat — Administrator Guide

LVChat's admin story mirrors UnrealIRCd's IRCop tooling plus the Anope
services. This guide is for **server administrators** and moderators with
operator permissions. It walks through every page of the admin dashboard, every
setting, the moderation toolchain (bans, spam filters, bad words, roles), the
append-only chat logs, and the full OperServ command reference.

Related guides: see `installation.md` for deploying/upgrading the server; see
`user-guide.md` for what regular users can do.

---

## 1. Becoming an administrator

- **First account on a fresh server** is automatically the admin.
- **Promoting others:** on **Admin → Users**, use *Make admin* / *Make staff*.
- **O:lines (`/oper`):** instead of a shared password, create a per-user
  **o:line** on **Admin → O-lines** (username + password + operator class). The
  user logs in, then runs `/oper <their nick> <password>` to operate with that
  class's permissions until they log out or `/deoper`. **There is no shared
  operator password.**
- **Escalation safety net:** if you ever lock yourself out, you can set the
  `users.role` column directly in SQLite (`UPDATE users SET role='admin' WHERE
  username='you';`).
- Admins render in **red** across the chat (messages, member lists, online
  list) so their presence is visible.

---

## 2. The admin dashboard (`/admin`)

The dashboard has **two tiers**: **staff** (admins *and* users with the `staff`
role) see **Overview, Moderation, Reports, Support**; **admins** additionally
see **Users, Channels, Bans, Spam filters, Bad words, Roles, O-lines,
Operclasses, MOTD, Sounds, Chat logs, Webhooks, Invites, Terms & Privacy,
Settings**. A **🛡 Admin dashboard** link also appears at the top of the
sidebar in the chat app for admins.

> Staff can also open any user's **moderation history** page
> (`/admin/users/<id>`) from the Users listing — admins can too.

### 2.1 Overview

- **Stat tiles:** total users, users online now (seen in last 30s and not away),
  channels, messages logged, private messages, active global bans, spam
  filters, audit events.
- **Recent audit events** — the most recent 15 entries from the audit log
  (who did what and when). Everything from ban adds to role edits to password
  resets is recorded here.
- **Banned / restricted users** — accounts currently marked banned or covered
  by an active global ban.
- **Full analytics** links at the top navigate to the dedicated Analytics page.

### 2.2 Analytics (`/admin/analytics`)

A comprehensive admin-only dashboard with server-side SVG charts (no JavaScript
dependencies). Use the time-range selector (7d / 30d / 90d / All time) to filter
all charts at once.

**KPI cards** across the top: total users, online now, peak online (all-time
record), messages (range), private messages (range), censor hits (range), open
reports.

**Active accounts** summary: distinct users active today, in 7 days, in 30
days, plus guests active in 30 days.

**Activity charts:**
- Messages per day (line)
- Daily active users — distinct people who spoke (line)
- Private messages per day (line)
- New registrations per day (line)
- Most active users — channel messages + DMs (horizontal bars)
- Activity by hour of day, UTC (vertical bars)
- Activity by weekday, UTC (vertical bars)
- Most active channels (horizontal bars)
- Top DM senders (horizontal bars)
- Least active accounts table (user, messages, PMs, registered, last seen, status)

**Moderation charts:**
- Censor leaders — users who tripped the bad-word filter (horizontal bars)
- Spam-filter leaders (horizontal bars)
- Most common matched words/patterns (horizontal bars)
- Filter hits per day — bad words + spam filters (line)
- Moderation action mix — kicks/bans/klines/etc. (donut)
- Ban types — global + channel, all-time (donut)
- Reports by status (donut)
- Most reported users (horizontal bars)
- Report reasons (horizontal bars)

**Health & operations charts:**
- Audit events per day (line)
- Support tickets per day (line)
- Account invites — used / pending / expired (donut)
- Webhook activity — last-used timestamps per webhook

### 2.3 Users (`/admin/users`)

Search by username or email, then act. Columns show the user's role, an
**account status** (active / **pending approval** / **suspended**), channel
count, registration date, last seen, **last IP**, and a BANNED badge. Click the
username to open the user's **moderation history** page (`/admin/users/<id>`,
staff+admin) — a staff-only timeline of every action against that account plus
free-form staff notes and filter/ban events (see §2.5).

**Guests** (anonymous `Join as guest` users) appear too — they have no email
(an `@guest.invalid` placeholder), no usable password, and their nickname
carries the `(guest)` tag in chat. Punishable like any user; they are also
auto-purged after a day of inactivity (so they can be short-lived).

Per-user actions (all confirm via CSRF-protected POSTs, all logged to audit and
to the account's staff timeline):

| Action | Effect |
|---|---|
| **Ban / Unban** | Sets `users.banned`; banned accounts can't log in. Enter a reason. |
| **Zline IP** | Adds a permanent `zline` against the user's recorded `last_ip`. |
| **Make admin / Demote** | Promotes/removes `admin` role. You cannot demote yourself. |
| **Make staff / Remove staff** | Staff opens `#staff` and shares its badge. |
| **Approve** | Moves a `pending` account to `active` (they can chat again). |
| **Set pending** | Marks an account `pending` — it can browse but not chat (used to hold a new account or pause one for review). |
| **Suspend** | Sets `suspended` **with a required reason**; kills all sessions and blocks chat until `Activate`. |
| **Activate** | Clears `pending`/`suspended` back to `active`. |
| **Add note** | Appends a free-form staff comment to the account's moderation history. |
| **Reset pw** | Sets a new random 12-char password (shown once) and kills all sessions. |
| **Delete** | **Permanently deletes** the account, its sessions, memberships and reactions. Channel/PM messages stay (they just lose the name); the append-only log archive keeps everything. Owned channels pass to the longest-standing remaining member, or vanish if empty and unregistered. A freed nick's **o:line is removed** so nobody can claim the nick and `/oper`. You cannot delete yourself or the last admin. |
| **Role selector** | Assigns or clears the user's custom role (dropdown mid-table). |

Above the table is a **Create a user manually** form: username, email, role
(user/staff/admin), and an optional **Email credentials** checkbox. A random
16-character password is generated and shown once in the flash banner — use it
to hand the account over directly. When SMTP is configured and "Email
credentials" is ticked, the credentials are also emailed (`user_create`).

> The Users page lists up to 200 rows, ordered by registration date.

### 2.4 Moderation queue (`/admin/moderation`, staff+admin)

A running record of moderation activity across the server, in two views:

- **By user** — per-account totals of *bad-word hits*, *spam-filter hits*, and
  *moderation actions* (kicks, channel bans, kline/gline/zline/shun), newest
  first, so repeat offenders stand out.
- **Event log** — the individual events (who, what match, which channel, when).
  Each time a user (or guest) trips the bad-word filter or a spam filter, or is
  kicked/banned/muted/*line'd, a `moderation_events` row is recorded
  automatically by the send/ban code — no manual entry needed.

Use this with the user timeline (§2.5) to build a case file for a problem
account before acting.

### 2.5 Reports (`/admin/reports`, staff+admin)

User-submitted message reports (right-click → **Report message** in the chat).
Each report **snapshots** the reported sender, message kind, and content —
including inline **images/GIFs** rendered in the review — so it stays accurate
even if the message is later edited or deleted.

- Filter the queue by status: **Open → Investigated → Resolved / Dismissed**.
- **Review / resolve / dismiss** a report (with an optional resolution note).
  Resolving a report appends a line to the sender's staff timeline
  (`Report #id … — resolved: …`).
- One report per message per reporter is enforced server-side.

### 2.6 User detail — moderation history (`/admin/users/<id>`, staff+admin)

The staff-only timeline for one account: every action taken *against* it
(bans, *lines, kicks, status changes, role changes, password resets, deletion,
report outcomes) **and** free-form **staff notes**, newest first, each with the
acting staff member. The matching moderation events (filter hits, etc.) are
shown alongside. Notes are append-only and invisible to the user.

### 2.7 Channels (`/admin/channels`)

Search channels by name; ordered by membership. Each row links to the channel
and to **logs** filtered to that channel (`/admin/logs?channel=#name`).
The **Actions** menu offers:

- **Force topic** — set a channel topic over the founder's head.
- **Visibility** — public / private / secret / staff. Note the interplay:
  private = hidden from `/list` but share-link-joinable; secret = hidden +
  invite-only; staff = only admins/staff join.
- **Forbid / Un-forbid** — forbid makes nobody able to join at all
  (renders the channel off-limits server-side).
- **Drop** — permanently delete the channel and its data stream.

### 2.9 Bans

Two tables: **Global bans** (kline / gline / zline / shun / qline, channel-less)
and **Channel bans**.

**Add a global ban** (top-right):

- **Type:** `kline` (IP/account-wide kill ban), `gline` (global ban), `zline`
  (severe IP ban), `shun` (mute — cannot speak), `qline` (reserved/forbidden
  nicknames).
- **Duration:** free-form like `30m`, `2h`, `1d`, `1w`, `1mo`, `1y`; a bare
  number is minutes; blank or `permanent` = never expires.
- **Mask:** a nick, a raw IP, an `IP/CIDR` (e.g. `10.0.0.0/24`), or a wildcard
  mask (e.g. `baduser!*@*`, `192.168.*`).
- **Reason** — shown to the user at login/join and stored in the ban row.

Bans are enforced server-side: login is refused for accounts hit by any active
global ban, sends are blocked, matching users can't join channels, and (for
IP/zline bans) online matching users are force-kicked.

Removing: **Remove** on any row (`ban_del`). Also possible in-chat with the
`un*` commands (below).

### 2.10 Spam filters

Rules that block messages by content. Fields:

- **Match text** — simple wildcard matching using `*` and `?`
  (e.g. `*cheap watches*`).
- **Reason** — shown to the user ("Your message was blocked by a spam filter: …").
- **Enabled / Disabled**, **Delete** per row.

Filters match against **channel messages (`c`) and private messages (`p`)** —
new filters store the targets field as `cpntu`. The whole server toggle is
**Settings → Spam filters**. If a filter hits, the message is rejected before
it's stored.

### 2.11 Bad words

The bad-word (censor) filter with two actions per word:

Controlled in **Admin → Bad words**; applied to **private messages always**, and
to a **channel only when mode `+C` is set** (toggleable by ops in the mode
bar). Actions:

- **`censor`** — matching words are replaced with `****` (word-boundary aware).
- **`block`** — the whole message is removed and a ChanServ notice is posted to
  the channel announcing the removal.

Words are matched against `[a-z0-9_]` boundaries. From the table you can add
words (with an action), enable/disable them, and delete them.

### 2.12 Roles & permissions

Role-based access lets you hand IRCop powers to trusted users without making
them full admins. Each role has a **name**, a **colour** (applied to the user's
name in the member list), a set of **permissions**, and an optional **Helper**
flag:

| Permission | Grants |
|---|---|
| `oper` | **IRC Operator**: OperServ commands (kline/gline/zline/shun, kill, global, spamfilter, badword…) **and viewing user IPs** (e.g. `/whois`) |
| `manage_users` | Promote / demote / ban users |
| `manage_channels` | Force topics, drop channels, change visibility |
| `manage_bans` | Add / remove global bans |
| `manage_badwords` | Manage the bad-word filter |
| `manage_roles` | Create / edit roles |

**Helper flag:** ticking **Helper** on a role gives its members a **green nick**
and automatic **half-op (`%`) in every channel**. Helpers are grouped separately
in the member list (between Staff and Registered). This is a distinct tier for
trusted community members who should have basic moderation privileges everywhere
without being full staff.

**Admins always have every permission.** Assign roles to users on the **Users**
page (the role dropdown); deleting a role unassigns it from all members.

> Any user with the `oper` permission is an IRC Operator even if their role is
> otherwise "normal" — the OperServ commands are enabled by the backend when
> `Auth::isOper` is true.

### 2.13 MOTD

The **Message of the Day** shows at the top of every chat window (a multi-line
textarea). Newlines render fine. Empty = no banner.

### 2.14 Chat logs

The **append-only** archive. Every channel message, action, and system event
(join/part/kick/ban/topic/mode/nick/system/notice), plus every PM, is written
to `chat_logs` at send-time with a denormalized channel name — **so logs survive
even when a channel is deleted or unregistered**. Nothing is ever removed from
this table.

**Chat Logs now lists one row per channel + day** (channel, date, entry count).
Pick a channel from the select to narrow it, then **click a day** to open a
full-width modal with that day's entire log in IRC format
(`HH:MM:SS AM - nick - message`, quoted topic changes, `-nick banned by op`
lines, PMs rendered as `sendername -> recipient`). Guests are marked `(guest)`.

- **Refresh** reloads the day from the archive.
- **Export** downloads the day as a `.log` file named `<channel>-<date>.log`
  (`/admin/logs/export?channel=…&date=…`).
- This is your forensics tool for moderation disputes — every day of every
  channel (public, private, staff, even long-deleted) is recoverable here.

### 2.15 O-lines (`/admin/opers`)

Per-user operator lines. Each row ties a **nickname** to an **operator class**
and an enable flag. **Add o:line** takes:

- **Nickname** — the account that may oper (must match the user's own nick).
- **Password** — 8+ chars, argon2id-hashed, shown to the user, cannot be read back.
- **Operator class** — one of your classes (default or custom).

Once issued, the user runs `/oper <their nick> <password>` from the chat to
operate as that class (lasts until logout or `/deoper`). Use **Enable/Disable**
to temporarily suspend an o:line, **Delete** to revoke it permanently. All
changes are audited (`oper_add`, `oper_del`, `oper_toggle`).

> There is **no shared operator password** on this server — `/oper` only works
> for the account matching an enabled o:line.

### 2.16 Operclasses

Permission bundles that define *what* an o:line can actually do. Each class has
a **name**, a **colour**, and a set of **permissions**. Four are created and
seeded automatically (defaults, delete-protected):

| Default class | Permissions |
|---|---|
| **netadmin** | oper, manage_users, manage_channels, manage_bans, manage_badwords, manage_roles, **manage_opers**, **rehash** |
| **serveradmin** | oper, manage_channels, manage_bans, manage_badwords, **manage_opers**, **rehash** |
| **globalop** | oper, manage_bans |
| **localop** | oper |

The permission set matches the role system plus two extras:

| Permission | Grants |
|---|---|
| `oper` | IRCop commands and viewing user IPs |
| `manage_users` | Promote / demote / ban users |
| `manage_channels` | Force topics, drop channels, change visibility |
| `manage_bans` | Add / remove global bans |
| `manage_badwords` | Manage the bad-word filter |
| `manage_roles` | Manage custom roles |
| `manage_opers` | **Create/edit o:lines and operator classes** (guard this) |
| `rehash` | Run `/rehash` |

Custom classes: **＋ New class**, edit name/colour/permissions, or delete
(non-default classes only — deleting a class also deletes its o:lines). A user's
active permissions while operating are their **role permissions + their
operclass permissions**.

> Permission checks combine the user's custom **role** permissions *and* the
> **operclass** of any active `/oper` session (`Auth::can`), so a user can
> operate with a class *and* keep role-granted powers.

### 2.17 Settings

| Setting | Meaning |
|---|---|
| **Site name** | Shown in the header, chat titles, and `/api/version` |
| **Logo URL** | Optional image shown as the site mark on login/register/embed pages (falls back to the default `#` tile) |
| **Registration** | Whether new accounts can be created (`/register` closed when off; the **Join as guest** option on the login page is unaffected and stays available). **Invite links keep working when this is off.** |
| **Require admin approval** | New registrations start as `pending` (browse, no chat) until an admin approves them on the Users page |
| **Spam filters** | Master switch for all active spam filters |
| **Image uploads** | Whether the 📎 image upload button works anywhere (channels and DMs) |
| **Reactions** | Whether emoji reactions on messages are enabled |
| **GIF search (Giphy)** | Enable toggle + **Giphy API key**. The key only ever lives server-side; `/api/gifs` proxies search/trending. With no key, the picker tells users GIF search isn't configured. |
| **Webhooks** | Master switch for the incoming-webhook API |
| **Max channels per user** | Per-user owned-channel cap (affects + create/`/register`) |
| **Presence throttle (s)** | How often the server writes "last seen" per user (default 30; raises scale by making most polls read-only) |
| **Poll interval (s)** | Client realtime poll frequency (default 2; raise to 3–5 to cut request volume) |
| **Realtime mode** | `Polling` (shared-hosting friendly) or `SSE` (streams updates per client; holds a PHP worker each — use on php-fpm/VPS) |
| **Email (SMTP)** | Host, port, encryption (STARTTLS / SSL / none), username, password, from address/name. Used for invite, welcome, and support-reply emails. |

**Sending email** is a dependency-free SMTP client — no `mail()`, no Composer.
The **Send test email** box under the settings form (or the **SMTP test** flash
from the header) verifies the stored settings against a live server and reports
the exact failure. The password field is write-only: leave it blank to keep the
stored one.

> The old shared **operator password** field was removed — operator access is now
> managed exclusively via **O-lines**. Settings writes appear in the audit log
> (`settings_save`).

### 2.18 Invites (`/admin/invites`)

Invite people by email instead of (or in addition to) open registration. Enter
an email (plus an optional personal message) and a sign-up link is generated,
emailed, and listed on this page:

- **Email** + inviter + expiry (links are valid for **7 days**).
- **Status** — pending, *used by <nick>*, or expired.
- **Copy link** — copy the `/register?invite=<token>` URL to share manually
  (handy when SMTP isn't configured yet — the created link is also shown in a
  banner whenever the email fails to send).
- **Resend** — rolls a *new* token for an unused invite and emails it again.
- **Revoke** — deletes the invite; its link stops working immediately.

The recipient opens the link and registers with their **email locked** to the
invited address. Clicking the emailed link is the proof of access, so an invite
**bypasses the `registration_enabled` toggle** — you can close open registration
and still admit people you've invited. Invites are audited
(`invite_create`, `invite_resend`, `invite_revoke`).

### 2.19 Sound alerts (`/admin/sounds`)

Server-wide audio alerts for channel messages and DMs. Anything you upload here
is offered to **every user** in their Profile → **Notification sounds** settings
(users cannot upload their own — only admins can).

- **Upload** — a name plus an audio file (**MP3, WAV, OGG, WebM, M4A**, max
  2 MB). Each upload becomes a selectable alert with a built-in preview player.
- **Status** — disabling a sound hides it from every user's picker (existing
  choices simply stop playing); re-enable it to restore it.
- **Delete** — removes the sound and its file. Users who had selected it for a
  channel/DM fall back to muted; per-user overrides pointing at it revert to
  that user's defaults (nobody is silently muted).
- Three built-in sounds (**Ding**, **Pop**, **Chime**) ship with the server and
  are regenerated automatically on boot if missing, so there are always alerts
  to pick from. Upload actions are audited (`sound_add`, `sound_del`).

### 2.20 Support tickets (`/admin/support`)

Staff and admins manage the support inbox. The **＋ Open a ticket** button
starts a ticket for either:

- a **registered user** (pick them from the linked-user dropdown), or
- an **email address** (for people without an account — e.g. forum visitors).
  If the email belongs to a registered account it links automatically.

Tickets can be **assigned** to any admin or staff member from the list (inline
dropdown) or the ticket detail page. Filters group the inbox by **status**
(All / Open / Answered / Closed) and by **Assignee** (All / Mine / Unassigned),
so staff can work their own queue.

When a staff member replies, the contact is **emailed** through the configured
SMTP (see §6) — to the ticket's email for email-only tickets, or to the linked
account's address. Users see their own tickets at `/support` in the app; an
email-only ticket is never visible to another user.

### 2.21 Terms & Privacy (`/admin/legal`)

Edit the public **Terms of Service** and **Privacy Policy** with the full
kitchen-sink rich-text editor — headings (H1–H6), bold/italic/underline/
strikethrough, subscript/superscript, text and highlight colour, bulleted/
numbered/task lists, blockquotes, inline & fenced code, text alignment,
horizontal rules, links, images, and tables. Both documents render at
`/terms` and `/privacy`.

- **Save legal pages** stores a sanitized copy. The sanitizer whitelists only
  safe tags/attributes, strips event handlers and `javascript:`/`data:` URLs,
  and rebuilds `style` from a safe allow-list (`color`, `background-color`,
  `text-align`) — so formatting survives but scripts never do.
- **Reset to boilerplate** replaces the selected document with the built-in
  US-federal / Nevada boilerplate (COPPA, NRS 597.790/597.795).

### 2.22 Webhooks (`/admin/webhooks`)

Discord-compatible incoming webhooks post into a channel as a bot — the full
API is documented in **§6.5** below. This page manages them:

- **＋ New webhook** — pick a channel and a bot name (2–32 chars), optionally an
  `https://` avatar URL. A random 48-char **token is generated and shown once**
  (`/api/webhooks/<token>`); only its SHA-256 hash is stored, so it can't be
  read back later.
- **Delete** — revokes the URL immediately (the channel founder may also delete
  a webhook from the API-side logic; admins always can).
- Each row shows the channel, name, creator, and last use. All webhooks can be
  switched off globally with **Settings → Webhooks** (`webhooks_enabled`).
  Creating/deleting is audited (`webhook_create`, `webhook_delete`).

---

## 3. OperServ / IRCop command reference

These commands are typed in the chat like any `/command`. **Only users who are
IRC Operators (admins, or anyone with the `oper` permission) can run them** —
the parser gates them server-side.

| Command | Description |
|---|---|
| `/oper <nick> <password>` | Oper up against **your o:line** and gain its operator class's permissions (session-scoped) |
| `/deoper` | Drop operator status (clears the active operclass) |
| `/kline <mask-nick-ip-cidr> <duration> <reason>` | Add an IP/account-wide kill ban |
| `/gline <mask-nick-ip-cidr> <duration> <reason>` | Add a global ban |
| `/zline <mask-nick-ip-cidr> <duration> <reason>` | Add a severe (IP) ban |
| `/shun <mask-nick-ip-cidr> <duration> <reason>` | Mute — cannot speak on the server |
| `/unkline / ungline / unzline / unshun <mask-nick>` | Remove the corresponding ban |
| `/kill <nick> <reason>` | Ban + disconnect a user (kills sessions) |
| `/global <message>` | Announce to every channel (aliases `/wallops`) |
| `/motd [set <text>]` | Read the MOTD; admins can `set` it |
| `/sajoin <nick> <#channel>` | Force a user to join a channel |
| `/sapart <nick> <#channel>` | Force a user out of a channel |
| `/samode <#channel> <+/-modes> [args]` | Force channel mode changes |
| `/sanick <nick> <newnick>` | Force rename a user |
| `/sasethost <nick> <host>` | Force-set a user's vhost |
| `/sqline <nick> [reason]` | Reserve / forbid a nickname |
| `/spamfilter add <match> [reason] \| del <id> \| list` | Manage spam filters (simple match, `*`/`?`) |
| `/badword add <word> [censor\|block] \| del <id> \| list \| toggle <id>` | Manage the bad-word list |
| `/clients` | List users currently online |
| `/serverstats` | Server counters (front page equivalents) |
| `/rehash` | Record/log a config reload |
| `/ignore` (from Core) | Also usable by ops to mute a specific nick in DMs |

Duration syntax: `30m`, `2h`, `1d`, `1w`, `1mo`, `1y`, bare number = minutes,
or empty/permanent. Targets: nick (resolved to `nick!*@*`), IP, `IP/CIDR`
(zline resolves to the user's recorded IP), or full masks like `*!*@10.0.0.1`.

---

## 4. Moderation workflow and tips

- **`/whois <nick>`** — IPs are visible to ops; combined with the Users page IP
  column this is your user-tracking anchor.
- **Pick the right tool:**
  - *Channel-level problems* → `/kick`, `/ban`, `/quiet`, `/mode +m`, or
    `/clear <bans|users>` in that channel; persistent ones → ChanServ `/akick`
    and `/access`.
  - *One user misbehaving everywhere* → `/kline <nick>` (or `/zline` by IP),
    `/shun` if it's purely spammy chatter.
  - *A nickname that must never be used* → `/sqline <nick>`.
  - *A reserve to hold an account for review* → **Set pending** (they can browse
    but not chat) or **Suspend** (kills sessions, requires a reason); **Approve**
    / **Activate** to restore. Every status change lands on the user timeline.
- **Responding to reports:** the **Reports** queue (§2.4) triages user reports;
  resolve a report and the outcome is appended to the sender's timeline.
  Document your own reasoning with an **Add note** on the user's moderation
  history page (§2.5) so other staff see the full picture.
- **`#staff`** is a staff/admins-only default channel (`staff` visibility) for
  coordination — it's invisible in the channel browser and its share link is
  rejected for non-staff. Promote users with the "Make staff" action.
- **Moderation is audited.** Every action (`kick`, `ban`, `kline`, `role`,
  `message_delete`, status changes, …) lands in the audit log on the Overview
  page *and* the account timeline; filter/bad-word hits and *line events also
  feed the **Moderation** queue (§2.3). Nothing is deleted from `chat_logs`.
- **Announce maintenance** with `/global` before a restart, and give a reason
  on every ban — it's shown to the user and remembered later.
- **Ignore is personal.** If a user ignores you, `/msg` from you is dropped; for
  server-wide reach use `/notice` (which does not respect ignores) or `/global`.

---

## 5. Audit log reference

The `audit_log` table stores `(actor_id, action, target, detail, created_at)`.
Every dashboard POST and the important chat actions append a row. Common
`action` values: `user_ban`, `user_unban`, `user_admin`, `user_staff`,
`user_reset`, `user_create`, `user_delete`, `user_status`, `user_approve`,
`user_note`, `zline_ip`, `ban_add`, `ban_remove`, `channel_create`,
`channel_delete`, `channel_drop`, `channel_register`, `channel_auto_delete`,
`channel_forbid`, `channel_topic_admin`, `channel_visibility`, `topic`,
`kick`, `kill`, `global`, `spamfilter_add/del`, `badword_add/del`,
`role_add/update/del`, `user_set_role`, `oper_add`, `oper_del`, `oper_toggle`,
`operclass_add/update/del`, `guest_join`, `oper`, `motd_save`,
`settings_save`, `invite_create`, `invite_resend`, `invite_revoke`,
`sound_add`, `sound_del`, `webhook_create`, `webhook_delete`, `legal_save`,
`legal_reset`, `legal_page`, `report_add`, `report_status`, `user_note`,
`support_ticket_open`, `support_ticket_staff_open`, `support_ticket_assign`,
`support_ticket_reply`, `support_ticket_close`, `support_ticket_reopen`,
`avatar_upload`, `message_edit`, `message_edit_denied`, `message_delete`,
`password_change`, `rehash`, `kline_add`, `gline_add`, `zline_add`,
`shun_add`, `unkline`, `ungline`, `unzline`, `unshun`, etc.

The overview page shows the 15 most recent; the full table is behind any admin's
SQLite access (`sqlite3 data/chat.db "SELECT * FROM audit_log ORDER BY id
DESC LIMIT 100;"`).

---

## 6. Settings / config reference (server_config)

| Key | Default | Notes |
|---|---|---|
| `site_name` | `LVChat` | UI brand name |
| `logo_url` | — | Optional site logo image URL |
| `registration_enabled` | `1` | `0` closes registration (invites still work) |
| `registration_requires_approval` | `0` | `1` = new accounts start `pending` |
| `motd` | *welcome text* | Shown in chat |
| `spamfilter_enabled` | `1` | Master switch for spam filters |
| `uploads_enabled` | `1` | Allow image uploads in channels/DMs |
| `reactions_enabled` | `1` | Allow message reactions |
| `gifs_enabled` | `1` | Master switch for GIF search |
| `giphy_api_key` | — | Giphy key; the server proxies all search/trending |
| `webhooks_enabled` | `1` | Master switch for incoming webhooks |
| `peak_online` | `0` | All-time concurrent peak (tracked automatically) |
| `presence_throttle` | `30` | Seconds between per-user "last seen" writes |
| `poll_interval` | `2` | Client realtime poll interval in seconds |
| `realtime` | `poll` | `poll` or `sse` (SSE holds a worker per client) |
| `max_channels_per_user` | `100` | Owned-channel cap |
| `smtp_enabled` | `0` | Master switch for email sending |
| `smtp_host` | — | SMTP server hostname/IP |
| `smtp_port` | `587` | SMTP port |
| `smtp_encryption` | `tls` | `tls` (STARTTLS), `ssl`, or `none` |
| `smtp_username` | — | Auth username (empty = no auth) |
| `smtp_password` | — | Auth password (write-only from the dashboard) |
| `smtp_from_email` | — | Envelope + From address |
| `smtp_from_name` | — | Display name for the From header |
| `legal_terms` / `legal_privacy` | — | Sanitized HTML for `/terms` and `/privacy` |
| `realtime` | `poll` | `poll` or `sse` (SSE holds a worker per client) |
| `max_channels_per_user` | `100` | Owned-channel cap |
| `smtp_enabled` | `0` | Master switch for email sending |
| `smtp_host` | — | SMTP server hostname/IP |
| `smtp_port` | `587` | SMTP port |
| `smtp_encryption` | `tls` | `tls` (STARTTLS), `ssl`, or `none` |
| `smtp_username` | — | Auth username (empty = no auth) |
| `smtp_password` | — | Auth password (write-only from the dashboard) |
| `smtp_from_email` | — | Envelope + From address |
| `smtp_from_name` | — | Display name for the From header |

There is **no `oper_password` key anymore** — operator access lives in the
`opers` / `operclasses` tables (see §2.13–2.14). The four default operator
classes are maintained in code and re-seeded on boot if removed.

Override file-level values by setting `CHAT_DB` to relocate the database (see
installation guide); all else is managed from the dashboard.

---

## 6.5 Incoming webhook API

Webhooks let external systems (forums, CI, monitoring) post into a channel as a
bot without an account. Manage them under **Admin → Webhooks**: pick a channel
and a bot name, and you get a one-time URL.

```
POST /api/webhooks/<token>
```

The token is a random 48-char secret; only its SHA-256 hash is stored. The URL
is shown once after creation — if you lose it, delete and recreate the webhook.

**Payloads (Discord-compatible JSON or form-encoded):**

| Field | Notes |
|---|---|
| `content` | The message text (≤ 2000 chars). |
| `username` | Override the bot name shown in chat (≤ 32 chars). |
| `avatar_url` | Override the bot avatar (must be `https://`). |
| `embeds[]` | Optional; `title`, `url`, `description`, `author.name`, `color` — flattened into formatted text. |

Example:

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"content":"New discussion: **My thread** — https://forum.example.com/t/123","username":"Forum Bot"}' \
  https://your.chat/api/webhooks/9f2c...8a1
```

Webhook posts run through the same spam filters, word filter, and rate limits as
normal sends, and land in the append-only `chat_logs` archive like any message.

**FriendsOfFlarum/webhooks** (`composer require fof/webhooks`): install the
extension, add a webhook with the **Discord** service type, paste the LVChat
webhook URL, and pick the forum events (Discussion Started, Post Created, User
Registered) to forward. Each LVChat webhook is scoped to one channel, so create
one per tag/channel pairing you want mirrored.

---

## 7. Reference: role/permission <-> admin UX mapping

Drawn from the code (src/controllers/AdminController.php, views/admin/*):

- **Moderation queue** (staff): per-user hit/action totals + event log.
- **Reports** (staff): report list by status, review/resolve/dismiss + notes.
- **Support** (staff): ticket inbox (filter by status/assignee), open for a user
  or an email, assign, reply (emails the contact), close/reopen.
- **User detail** (staff): moderation-history timeline + staff notes + events.
- Users page: ban/unban, zline-IP, admin/staff promote+demote, password reset,
  **pending/approve/suspend/activate**, notes, **delete**, custom-role
  assignment (guests appear too), **create a user manually** (+ email credentials).
- Channels page: force topic, visibility, forbid, drop.
- Bans page: add global ban (kind/duration/mask/reason), remove any ban.
- Spam filters page: add (simple match + reason), enable/disable, delete.
- Bad words: add (word + censor/block), enable/disable, delete.
- Roles: create/rename/colour/permissions, delete (unassigns members).
- **O-lines: add (nick/password/class), enable/disable, delete.**
- **Operclasses: create/rename/colour/permissions, delete (non-default only).**
- MOTD: save free-text.
- Logs: per-channel-per-day table, click a day for the IRC-format view, Refresh
  + Export.
- **Sounds: upload audio, enable/disable, delete.**
- **Invites: create (email + message), copy link, resend, revoke.**
- **Webhooks: create (channel/name/avatar → token shown once), delete.**
- **Terms & Privacy: rich-text edit both documents, save sanitized, reset to
  boilerplate.**
- Settings: site name, logo, registration + approval gate, spam filter master
  switch, uploads, reactions, GIF/Giphy, webhooks, channel cap, presence
  throttle, poll interval, realtime mode, and SMTP (+ send test email).
  (No operator-password field — that moved to O-lines.)

Every POST action goes through `/admin/action` with a CSRF token and writes an
audit entry before redirecting back.
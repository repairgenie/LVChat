# LVChat — Slash Command Protocol

Clients invoke the entire IRC-style command set by posting a `/`-prefixed line.
This page documents the wire protocol for commands: the parser grammar, the
registry metadata, the **command result** shape returned to the client, and how
side effects (system messages, redirects, client actions) are carried.

---

## 1. Transport

Two ways to run a command:

1. **AJAX** — `POST /api/command` with `text` and optional `channel`. The
   response is the command result merged with `ok: true`. This is what the chat
   composer uses.
2. **No-JS fallback** — `POST /api/send` with `ajax != 1` and a `content`
   starting with `/`. The server detects the slash, runs the command, applies
   its events, and redirects to `redirect` (or back to the channel). The
   message is never stored — it's treated as a command.

Both paths share `CommandParser::run()`.

---

## 2. Grammar

```
command-line  := '/' command [args]
command       := [A-Za-z0-9]+          (matched case-insensitively)
args          := whitespace-separated tokens
```

- `CommandParser::parse()` splits on whitespace, strips the leading `/`,
  lowercases the command word.
- `CommandParser::splitChannel()`: if `args[0]` starts with `#` or `&` it is
  popped and resolved as the target channel (via `ChannelService::find`).
  Commands that need a channel (`needs_channel`) do this so
  `/kick #channel nick` works from anywhere.

Unknown input that doesn't start with `/` → `['replies' => ['Unrecognized
input.']]`.

---

## 3. Registry metadata

Every command is registered in the `CommandRegistry` with metadata:

| Key | Meaning |
|---|---|
| `group` | Display group: `Core`, `Channel Ops`, `ChanServ`, `NickServ`, `MemoServ`, `HostServ`, `OperServ`. |
| `desc` | One-line help text (`/help <cmd>`). |
| `usage` | Usage string. |
| `needs_channel` | Requires a channel context; the parser resolves one or rejects with "You must be in a channel or provide one". |
| `min_level` | Minimum channel level needed (see §5); enforced before `run`. |
| `server_admin` | Restricts to operators (`Auth::isOper`) — used by OperServ commands. |

Dispatch checks, in order:

1. Command exists → else "Unknown command: /X. Type /help for a list."
2. `server_admin` and not an operator → "restricted to server administrators."
3. `needs_channel` → resolve channel; missing → usage error.
4. `min_level` > effective level (unless account role is `admin`) →
   "You do not have permission to use /X in #chan."

---

## 4. The command result shape

A handler returns one of:

- `null` — no reply (side effect only; e.g. `/me`).
- `string` — a single reply line.
- `array` — the full result:

```json
{
  "replies": ["You have left #general."],
  "events": [
    { "channel_id": 1, "kind": "system", "content": "alice has left the channel" }
  ],
  "redirect": "/app",
  "action": "clear",
  "copy": "https://chat.example.com/c/gaming"
}
```

| Key | Meaning |
|---|---|
| `replies` | String lines rendered inline in the chat as system-style text (each shown to the actor). |
| `events` | Channel events to persist as **system messages** via `MessageService::system()` (`kind` is one of the system kinds). `applyEvents` inserts each and fans it out over the WebSocket as `{messages:[…]}`. |
| `redirect` | URL to navigate to (e.g. `/app?channel=…`). The AJAX client follows it; the no-JS path redirects server-side. |
| `action` | Client-side instruction: `clear` (clear the message window), `browse` (open the channel browser), `copy` (copy `copy` to clipboard). |
| `copy` | Value for `action: copy`. |

### Where the message byte is decided

- `events` rows are built by `MessageService::system()` (sender `null`),
  matched to a channel by `channel_id`, then published with `Realtime::message`
  — so a kick/ban/topic/mode change is **seen live by every viewer** as a
  system message, exactly like `/api/send` fans out a chat line.

---

## 5. Channel levels

`level_weight()` is the authority for permission math:

| Level | Symbol | Weight |
|---|---|---|
| `normal` | — | 0 |
| `voice` | `+` | 1 |
| `halfop` | `%` | 2 |
| `op` | `@` | 3 |
| `admin` | `&` | 4 |
| `founder` | `~` | 5 |

- `AccessService::effectiveLevel()`: access list (`channel_access`) first, else
  membership level, floored at `halfop` for Helper-role users.
- Op commands carry a `min_level`: **halfop (2)** for kick/kickban/ban/unban/
  quiet/voice/devoice/mode/topiclock, **op (3)** for op/deop/halfop/dehalfop.
  (These mirror IRC: half-ops get voice/kick/ban/`+imtk` but not `+l+C+p+s+o`.)
- `AccessService::canSetLevel()` enforces the grant ladder: you may only modify
  users *below* your level and grant *up to* your own (op grants voice/halfop/
  op; admin grants up to admin; founder grants up to admin; server admins do
  anything).

---

## 6. Command catalog

### Core

| Command | Notes |
|---|---|
| `help [cmd]` | Grouped help output. |
| `join <#channel> [key]` | Join or create; `need_key` redirects to the key modal. |
| `part [#channel] [reason]` | Leave a channel. |
| `quit [reason]` | Leave all channels, log out (`redirect: /logout`). |
| `me <action>` | Action message, `kind: action`. |
| `msg` / `pm` / `query <nick> <message>` | Send a PM. |
| `notice <nick> <message>` | PM with no notification created. |
| `nick <newnick>` | Rename (system `nick` events in every joined channel). |
| `away [message]` / `back` | Toggle away state. |
| `whois <nick>` | User info. |
| `list` / `channels` | `action: browse` — open the channel browser. |
| `topic <text>` | Set topic (channel). |
| `ping` | Latency check. |
| `invite <nick> [channel]` | Channel invite (+ system `system` event). |
| `knock <channel>` | Ask to join an invite-only channel. |
| `ignore` / `unignore <nick>` | Delegates to the friend block system. |
| `share` | `action: copy` — copies the channel share link. |
| `search <term>` | Chat search. |
| `clear` | `action: clear` (client-side; also a Channel-Ops `/clear <type>`). |

### Channel Ops

`kick`, `kickban`, `ban`, `unban`, `quiet`, `op`, `deop`, `halfop`,
`dehalfop`, `voice`, `devoice`, `mode`, `topiclock`, `clear <users|bans|ops|
voices|topic|modes>`.

- `/mode` with no flags prints the full mode explanation inline.
- Modes: `+i` invite-only, `+m` moderated, `+C` word filter, `+k` key,
  `+l` limit, `+t` topic-lock, `+p`/`+s` visibility, `+b`/`-b` bans,
  `+vhoaq` levels. Every mode change emits a `mode` system event and a
  `log_audit` row.

### ChanServ

`register`, `unregister`, `drop`, `identify`, `set`, `access`, `akick`,
`transfer`, `getkey`, `senak`, `chaninfo`, `forbid`, `cs`.

### NickServ

`register`, `identify`, `logout`, `set`, `ghost`, `release`, `recover`,
`status`, `info`, `group`, `rename`, `ns`.

### MemoServ

`memo`, `ms` — send / read / list / del / summary / set.

### HostServ

`vhost`, `hs`.

### OperServ (server-admin restricted)

`oper`, `deoper`, `kline`, `gline`, `zline`, `shun` (+ `un*` variants), `kill`,
`global`, `wallops`, `motd`, `sajoin`, `sapart`, `samode`, `sanick`,
`sasethost`, `sqline`, `unsqline`, `sqlines`, `cqline`, `uncqline`, `cqlines`,
`spamfilter`, `badword`, `clients`, `serverstats`, `rehash`, `notice`.

- `/oper <nick> <password>` elevates against a per-user **o:line** (no shared
  operator password); the result is an oper-class permission set active for the
  session.
- `kline`/`gline`/`zline`/`shun` write `bans` rows (server-wide), record a
  moderation event and staff note, and emit a `system` event to the channel.
- `sqline`/`unsqline`/`sqlines` manage **q-lines** — forbidden nicknames,
  enforced at registration, `/nick`, guest logins, and `/sanick` targets.
- `cqline`/`uncqline`/`cqlines` manage **c-lines** — forbidden channel-name
  masks (with or without the leading `#`), enforced at channel creation and on
  `/join` for both new and existing channels.
- `/sanick <oldnick> <newnick>` is gated to **server admins and `netadmin`
  o:line holders** (`Auth::isNetadmin`). A requested nick that is registered to
  another user or held by a live guest (or q-lined) replies
  `Requested nick is unavailable, please select another`. On success it updates
  the target's `users.username` (and any matching `opers` row), broadcasts a
  `nick` system event to every channel they're in, and sends the renamed user a
  direct message with the change.

Every action an admin or o:line holder takes is mirrored as a `notice` line into
the admin-only `#oper-log` channel by `oper_log()` (wired into `log_audit()`).
Plain channel operators — no o:line, not a server admin — are excluded.

---

## 7. Client-side autocomplete

The chat page embeds the full command-name list (`data-commands`) and offers
Discord-style `/` autocomplete; `@`-mention autocomplete pools the embedded
`data-users` list plus current channel members. Messenger clients fetch the
same list from `GET /api/commands` for identical autocomplete. This is purely
presentational — the server remains the authority (it rejects unknown commands,
enforces levels, and applies filters).

---

## 8. Side-effect summary

| Command family | Persists | Broadcasts | Audits |
|---|---|---|---|
| Message-y (`/me`, `/msg`) | `messages` / `private_messages` | WS `message`/`dm` | `chat_logs` |
| Channel ops (kick/ban/mode/…) | channel state + `messages` system rows | WS `message` (system) | `audit_log` + moderation queue + staff note |
| Nick/away | `users` row | system `nick` events | — |
| Services (ns/cs/…) | their own tables | system events where relevant | `audit_log` where relevant |
| `/quit` | memberships removed | `quit` system rows | — |

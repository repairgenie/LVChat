# LVChat — User Guide

LVChat is a Discord-style chat that speaks fluent IRC. Channels use `#name`
names, users run `/slash` commands, and the familiar IRC concepts (channel
operator levels, keyed/invite-only/moderation modes, ChanServ/NickServ/MemoServ
/HostServ services) are all here — wrapped in a modern web interface.

This guide covers everything you need as a regular user: your account, channels,
messaging, private messages, your profile, and the complete command reference.

---

## 1. Your account

### 1.1 Registration and login

- Visit the site and click **Register**.
- **Username:** 2–32 characters using letters, numbers, and IRC-safe symbols
  `- _ [ ] { } ^ \` |` — this is your nick on the server.
- **Email:** a valid address (used only for account recovery / admin tools).
- **Password:** at least 8 characters.
- **18+ gate:** you must certify you are at least 18 (recorded on the account)
  and agree to the linked **Terms of Service** and **Privacy Policy**.

If open registration is turned off, the **Register** page instead says that only
invite links can create accounts. When a server admin invites you, you'll get an
email with a **create-account link** — opening it lands you on the registration
form with your email already filled in (and locked). Just pick a username and
password and you're in.

If new registrations **require admin approval**, your new account starts as
**pending**: you can log in and browse channels, but you cannot chat until an
admin approves you. If an account is ever **suspended** instead, login shows the
reason and chat access is blocked until it's lifted.

Log in with your username and password. Sessions last 30 days. Too many failed
login attempts from the same IP within 10 minutes locks that IP out briefly.

### 1.2 Join as a guest

Don't want an account? The login page has a **Join as guest** box: pick a free
nickname and you're in immediately — no email, no password.

- Guests can chat in any existing channel and send private messages, and their
  nick is shown with a `(guest)` tag.
- **Guests cannot create channels** — when you try, you're told to make an
  account instead.
- A guest nick **frees up on logout** (or after a day of inactivity) and is
  **reclaimed on re-login**, so the same nick keeps its DM history. Registering
  an account with that nick later converts the guest into a real account and
  carries the DMs/memberships over.

### 1.3 First visit

On the top of your first chat window you may see the server **MOTD** (Message
of the Day) set by the admins. Typing **`/help`** lists every available command;
`/info` shows server statistics. The site automatically creates a few default
channels (`#general`, `#help`, `#staff`) on a new server.

New users are **automatically joined to `#general`** on their first login, so
you land in an active channel rather than an empty chat screen.

### 1.4 Who is the admin?

Server administrators ("IRC Operators") are shown in **red** in message lists,
the member list, and the online list, and get extra buttons. Custom role names
may appear in their assigned colour. Users operating through an **o:line**
(`/oper`) gain that operator class's permissions for the session — see the
[Admin Guide](admin-guide.md).

---

## 2. The chat window layout

| Region | What it shows |
|---|---|
| Left sidebar | Server name + notification bell; your joined channels; direct-message conversations; a list of who is online; your user card (⚙ menu) |
| Centre | The channel header (name, topic, **Share** / **Leave**), the mode bar, the message timeline, the **pinned-bar** (jumpable list of pinned messages), the typing indicator, and the message composer |
| Right sidebar | Friends, channel invites, and the member list for the current channel — Online and Offline groups, with access-level symbols (`~ & @ % +`) |

- **Hide the chat list** with the ☰ button in the top-left of the message area:
  on desktop it collapses the sidebar (your choice is remembered), on mobile it
  opens a drawer over the chat.
- Switch **light/dark** with the 🌙 button in the channel header (remembered per
  browser).
- The composer has four buttons next to the input: **📎** (image upload), **GIF**
  (Giphy GIF picker), **😀** (emoji picker), and **➤** (send). It also supports
  **slash commands** with autocomplete (type `/` to see suggestions, arrow keys
  to move, `Tab` to accept).
- Type **`@nick`** to mention a user — they get a notification bell.
- `Enter` sends; **Shift+Enter** is a newline. Messages are limited to 2000 characters.

### 2.1 Text formatting

Messages support multi-line input (**Enter** sends, **Shift+Enter** is a newline)
and light formatting:

| You type | You see |
|---|---|
| `**bold**` | **bold** |
| `*italic*` | *italic* |
| `~~strike~~` | ~~strike~~ |
| `\`code\`` | `code` |
| ` ```php` … ` ``` ` (own line) | fenced code block |
| `> quote` (line prefix) | blockquote |
| `- item` or `1. item` (line prefix) | bulleted / numbered list |
| `@nick` | highlighted mention |
| `https://example.com` | clickable link |

### 2.2 Real-time updates

The chat polls the server every 2 seconds, so new messages, the member list, and
your notification bell stay fresh without reloading.

---

## 3. Channels

### 3.1 Browsing and joining

- **Browse** the list of public channels from the **Browse channels** link (or
  type `/list` or `/channels`). The browser is now a **table** you can:
  - **filter** by name or topic with the search box;
  - **narrow** to `Not joined` / `Joined` via the select,
  - **sort** by Channel, Topic, or Users by clicking the column headers —
    everything updates instantly with a live channel count.
- Click **Join** on a channel row, or type `/join #channel`.
- Channels may require any of the following — you will see a clear reason if you
  are denied:
  - a **key** (password) — you will be asked for it;
  - an **invite** from a member (`+i` invite-only);
  - **secret** channels (`+s`) — hidden entirely and joinable by invitation only;
  - **staff-only** channels — restricted to admins and Staff.
- Channels with **unread messages** show a red count badge next to their name in
  the sidebar; it clears as soon as you open the channel and updates live.
- Click the **🔔 button** in a channel's header to cycle its notification mode:
  **all** (default) → **mentions only** → **muted** (no alerts from that channel
  at all, including `@mention`s).
- If a channel requires a key, LVChat shows a dedicated "enter key" page.
- If you're denied access to an invite-only channel, the page offers a
  **Request access (knock)** button.

### 3.2 Shareable links

Every channel has a shareable link of the form `/c/<slug>` (e.g. `/c/gaming`):

- Send it to a friend who isn't logged in — they land on login/register and are
  returned straight into the channel.
- Logged-in users who click the link are auto-joined.
- Private (`+p`) channels are hidden from `/list` but still joinable via their
  share link.
- Get the link for the current channel with **Share** (top-right) or `/share`.

You can also embed a channel on another page with its **embed URL**
(`/embed/<slug>`): logged-out visitors see a small card with a **Chat as
guest** box (plus Log in / Register), and signing in drops them straight into
the channel. It's the same flow as a share link, formatted for an iframe.

### 3.3 Creating and registering channels

- Create a channel with the **＋** button next to "Text channels", or `/join
  #name` of a channel that doesn't exist. **Guests can't create channels** — join
  an existing one, or register an account to found your own.
- New channels are **temporary**: they disappear when the last person leaves.
  The channel's own warning banner tells you this and — if you are the founder —
  asks you to run `/register #channel`.

**`/register #channel`** makes the channel (and you, its **founder**) permanent:

- A registered channel persists even when empty.
- If the founder of an *unregistered* channel leaves while others remain, the
  founder title passes to the member who has been there the longest.
- Dropping / unregistering removes the founder's protections (see ChanServ).

### 3.4 Leaving channels

Click **✕ Leave** in the channel header, or run `/part [#channel] [reason]`.
If you `/part` an empty **unregistered** channel, the channel is deleted.

### 3.5 Channel modes (the "mode bar")

Above every channel's messages, a bar shows the active channel modes as
clickable chips, each with a mouseover tooltip. Operators can toggle them;
everyone can read them. The same settings are managed with `/mode`.

| Mode | Meaning |
|---|---|
| `+i` (invite) | Only users you invite may join |
| `+m` (moderated) | Only voiced users (`+v`) may speak |
| `+C` (filter) | Applies the server's bad-word list (censor or full-message block) |
| `+k` (key) | A password is required to join; `/mode +k <key>` / `-k` to remove |
| `+l` (limit) | Caps the number of members; `/mode +l <n>` / `-l` |
| `+t` (topic lock) | Only operators may change the topic (usually on) |
| `+p` (private) | Hidden from `/list`, joinable via share link |
| `+s` (secret) | Hidden entirely, join by invitation only |
| `+b` `/ -b` (ban) | Ban / unban a mask or nick (a bare nick resolves to `nick!*@*`) |
| `+q` `/ -q` (mute) | Mute / unmute a specific user (cannot speak) |
| `+o` `/ +h` `/ +v` | Grant op / half-op / voice to a nick (prefix `-` to remove, e.g. `/mode #chan -o nick`) |

Run `/mode` in a channel with no arguments for a full inline explanation of
every mode, plus the channel's current mode string.

### 3.6 Access levels

Members have one of these levels, shown next to their nick:

| Level | Symbol | Typical powers |
|---|---|---|
| Founder | `~` | Everything; owner of a registered channel |
| Admin | `&` | Everything except founder actions |
| Op | `@` | Kick/ban, mode changes (`+l`, `+C`, `+p`, `+s`, `+o`), voice/halfop grants |
| Halfop | `%` | Voice/kick/ban/`+imtk`, but not op-level modes |
| Voice | `+` | Can speak in moderated (`+m`) channels |
| Normal | *(none)* | Standard member |

Channel ops can **promote** you (e.g. `/op nick`) and set bans — see
**Channel ops commands** below.

### 3.7 Channel settings (the control panel)

Channel **operators and above** — plus server admins and opers — get a
**⚙ Settings** button for the channel they're viewing (top-right of the header,
or right-click the channel in the sidebar → **Channel settings**). It's a
point-and-click control panel with four tabs:

- **Overview** — the channel's name, visibility, registration status, and the
  **Channel URL** field.
- **Bans** — the channel's ban list with add/remove. Add by **nick** (resolved
  automatically) or a **mask** (`nick!*@*`, IP, …) with an optional duration
  (`1d`, `30m`, …) and reason. Half-ops and above can manage bans.
- **Ops & half-ops** — the registered **access list**: members who
  automatically hold a level every time they join, even while offline. Add a
  user with a level (`@ op`, `% half-op`, `& admin`, `+ voice`) or remove one.
  Ops and above can manage this.
- **Topic** — set or clear the channel topic. When the topic is locked (`+t`),
  only operators can change it.

Everything in the panel is also available as a slash command (`/ban`, `/unban`,
`/access`, `/topic`) with exactly the same permissions — the panel just makes
them clickable.

### 3.8 Channel URL

An operator can give a channel a **Channel URL** — a web page embedded directly
in the chat. When one is set, a pane opens **above** the message list. The
sidebars stay where they are; the chat takes the bottom half of the view.

- The pane's header bar shows the page's host, with **Open** (opens the page in
  a new tab), **Refresh**, and a **▾** collapse button (your choice is
  remembered for that channel).
- Only `http://` and `https://` pages are allowed. Some sites refuse to be
  embedded (`X-Frame-Options`) — if the pane looks empty, click **Open**.
- Pages load through a server-side proxy, so sites that block embedding still
  render. The page runs in a sealed sandbox (it can never touch your chat
  session); the proxy shims the few browser APIs the sandbox forbids and
  re-serves the page's stylesheets and fonts, so modern JS-heavy sites and
  their fonts work normally in the pane.
- Set or clear it in **Channel settings → Overview**. Ops and above can change
  it; a system line in the channel announces set/clear so everyone sees it live.
- The server keeps a global **blocked-domains** list. A URL whose host equals a
  blocked domain (or is a subdomain of one) is **rejected** when you try to set
  it, and an already-set URL is **hidden** if its domain gets blocked later.

---

## 4. Messaging

- Send a message by typing in the composer and pressing **Enter**. A message
  posts instantly (AJAX) with a native no-JS fallback that still delivers.
- **Edit:** hover your message and click ✏️, or right-click it and choose *Edit*.
  You can edit your own messages within **5 minutes** of posting; server
  administrators can edit any message at any time. Edited messages show
  "(edited)".
- **Delete:** hover and click 🗑, or right-click → **Delete** (normal users can
  delete their own; admins can delete any).
- **Actions:** `/me does something` renders as an italic *action* line.
- **Reactions:** hover a message and use the **+** under it (or click an emoji
  chip to toggle it). Every message shows its reaction chips live.
- **Images:** use the **📎** button (or drag & drop an image) in a channel **or
  a private message** to post an image; it renders as a thumbnail and opens in a
  lightbox when clicked. The chat auto-scrolls to keep new messages and images
  fully in view — but if you scroll up to read earlier chat, it holds your place
  instead of yanking you back down. If an administrator has disabled uploads
  you'll be told so.
- **GIFs:** click **GIF** in the composer to open the picker — it loads trending
  GIFs and searches as you type, and posts the one you click straight into the
  channel or DM. GIF messages render inline, stay searchable by their title, and
  are subject to the server's filters like any message. GIFs require the admin to
  configure a **Giphy API key** (Admin → Settings) — until then the picker says
  GIF search isn't configured.
- **Reply (quote):** right-click somebody's message → **Reply** sets a "Replying to @nick — excerpt" chip above the composer; your message then carries the quoted context, and the quoted line is shown under the author's name in the timeline. Click the `↪` line to jump to (and briefly highlight) the original message.
- **Mentions:** mention a member of the current channel with `@nick`; they get a
  notification bell. Mute a channel from its 🔔 header button to stop its
  mention alerts (an operator can also mute someone with `/quiet`).

### 4.1 Searching your messages

Use the **search box** in the chat header (or `/search <term>`) to find
messages across **every channel you are a member of** and your **private
messages**. Results show the channel, author, and a snippet; clicking a result
opens the channel and **jumps straight to** (and highlights) that message.
Admins can additionally search the full append-only archive on the **Admin →
Chat logs** page. Search only ever covers messages you are allowed to see.

### 4.2 Reporting a message

Right-click any channel or DM message and choose **Report message**. Pick a
preset reason (Harassment / Bullying, Spam or advertising, Hate speech,
Inappropriate / NSFW content, Threatening violence, Personal information /
doxxing) or **Other** with a short description. The report snapshots the sender,
message type, and content (inline images/GIFs included), so it survives later
edits or deletes. You can report a message once; staff review reports on the
**Admin → Reports** queue. Reporting is not a substitute for an emergency — if
someone is in immediate danger, contact the authorities.

---

## 5. Friends

Registered users can manage friends from the **Friends panel** in the right
sidebar (above the member list) or from any user's profile page.

- **Send a friend request** — click **Add Friend** on a user's profile, or use
  the context menu. Guests cannot receive friend requests.
- **Accept / Decline** — incoming requests appear at the top of the Friends
  panel with Accept/Decline buttons, and also in the notification bell.
- **Remove a friend** — click **Remove Friend** on their profile or cancel an
  outgoing request with **Cancel Request**.
- **Block / Unblock** — blocking a user removes any existing friendship and
  prevents all private messages between you (in both directions). Use
  **Block User** / **Unblock** on their profile. `/ignore <nick>` and
  `/unignore <nick>` now delegate to the block system.
- The Friends panel groups friends into **Online** and **Offline** sections,
  shows a pending-request count badge, and clicking a friend opens a DM.

---

## 6. Private messages (DMs)

- Start a DM by clicking a user's name in the member/online list, or with
  `/msg <nick> <message>` (aliases: `/pm`, `/query`).
- DM conversations stay open in the **Direct messages** sidebar with unread
  counts and green "online" dots; read **receipts** quietly mark a conversation
  as all-read when you open it.
- **Messaging yourself** is allowed (an IRC hallmark) — handy for notes.
- **Notices** (`/notice <nick> <message>`) are PMs that create no notification.
- **Ignore** someone with `/ignore <nick>` — their DMs are dropped; `/unignore`
  to reverse it. If you ignore someone, `/msg` to them tells you so. This now
  uses the friend block system (see §5).
- The **notification bell** (top-left) aggregates mentions, DMs, invites,
  knocks, friend requests, and friend acceptances; **Mark all read** clears them.
  New activity also arrives as **in-app toasts** (top-right) and OS/browser
  notifications. Press **Ctrl+K** (⌘K) anywhere to jump between channels and
  DMs with the **quick switcher**.

### 5.1 Typing indicators, pinned messages & jumps

- **Typing indicators** — while someone types in the conversation you're
  reading, an animated "X is typing…" line appears above the composer (channels
  and DMs alike).
- **Pinned messages** — operators and admins pin any channel message
  (right-click → **Pin message**). The pinned bar above the composer shows the
  count; click it for the jumpable list, or use the header menu → **Pinned
  messages**. Unpin from the same right-click menu.
- **Deep links** — a notification click (toast, OS, or push) jumps straight to
  the message that triggered it, highlighting it in context. Share-style links
  with `?jump=<id>` work the same way.

---

## 6.1 Voice, calls & meetings (WebRTC)

LVChat ships real-time audio and video powered by **LiveKit** (a self-hosted
SFU). Voice is a server feature — the 📞 controls in the header appear only when
an administrator has enabled it. Everything is **server-gated**: if the feature
is off, no buttons render at all.

### Channel voice (the "huddle")

- An operator must first enable voice on a channel (**Channel settings →
  Enable voice**). Once on, a voice button appears in the channel header.
- Click it to **join the channel's voice room**: the chat window shows a small
  voice panel with your mic mute, camera, screen share, deafen, layout, and
  **Leave** controls, plus the current roster of participants.
- **Talking** is automatic (voice-activity detection); each listener hears up
  to `voice_talker_cap` loudest speakers at once — the same "top talkers"
  model Discord uses, so big rooms don't drown you in audio.
- While in voice, press **M** to toggle your microphone.
- **Video** — toggle your camera, share your screen (optionally with system
  audio), and switch between **auto / speaker / gallery** layouts. Choose your
  devices and a **video background** (blur or a custom image) under
  **⚙ Voice & video settings**.
- **Deafen** silences everyone else's audio without leaving.

### One-on-one and group calls

- In a DM conversation, the voice button places a **call**: the other person
  sees an incoming-call overlay with **Accept** / **Decline**. Unanswered calls
  ring ~20 seconds and become "no answer"; missed calls appear in your
  notification bell (and as an OS push when you're offline, if notifications
  are enabled).
- **Group calls (Discord-style)** — while in an active call, the caller/host
  can use the in-call **Invite** box to add more people (comma-separated
  nicks). They see the call as an incoming call and join the same room.
  Members can hang up and leave the call running; the host ends it for everyone.

### Host controls (moderating a room)

In any voice room you have host privileges if you're the **channel operator**
(channel voice), the **event founder** (a meeting), or the **call host**:
- **Mute / unmute** an individual participant, **Mute all**, or **Kick**
  someone from the roster list in the voice panel.
- **Lock / unlock** the room — locked rooms refuse new joins until unlocked.

### Waiting rooms (lobby)

An operator can enable a **waiting room** on a channel (or an event creator on
a meeting). Joining then lands you in a "Waiting for the host…" lobby instead
of the room; the host admits you from the roster (**Admit**) or rejects you
(**Deny**). Admitted guests are silently connected the moment the host lets
them in.

### Recording

When recording is enabled, a host's voice panel shows a **Record** button —
tap it to record the meeting (LiveKit egress composites the room to an MP4 on
the server). Hosts (and admins) can download their recordings from
**Admin → Voice**. Recording needs the server's egress service deployed; if
it isn't, the button reports "not available" and nothing else changes.

### Meetings (`#evt-*`)

- The **📅 Event** button in the header opens the events panel: create a
  **WebRTC (interactive)** or **Link** (YouTube/Twitch) event, choose
  **Public** or **Private**, optionally **schedule** it and set a **duration**,
  and turn on a **waiting room (Lobby)**.
- **Private** events get a shareable invite link (`/e/<code>`); the founder can
  also **invite by email** (each person gets a unique link). **Public** events
  have a plain `/event/<slug>` link anyone can open.
- Meeting channels are temporary — they appear in your sidebar while active and
  are cleaned up when they end. From the events panel's **My events** list you
  can copy links or **cancel** your own events.
- Open an invite or event link while logged in to auto-join; logged-out
  visitors are bounced through login first.

### Connection quality

Each participant in the roster carries a small quality dot (green / amber /
red) driven by LiveKit's connection-quality reports — a quick way to tell if
someone's link is the reason a room feels laggy.

### Reacting and raising your hand

In-call buttons let you **raise your hand** (a ✋ badge appears on your tile
and in the roster) and send **emoji reactions** (👍 ❤️ 🎉 👏 😮 😂) that float
into the room for a few seconds.

---

## 7. Your profile & settings

From the `⚙` menu next to your name in the sidebar, open **Profile & settings**
(guests see their profile but no account forms — they have no password/vhost to
edit).

- **Change password** — must verify your current password; new password 8+ chars.
- **Avatar** — upload a profile picture (JPEG/PNG/WebP/GIF, up to 1 MB; downscaled
  to 256px). It appears next to your name in messages, member lists, and profile.
  Use **Remove** to go back to the initial-letter avatar.
- **Virtual host (vhost)** — a hostname that identifies your connection/status.
  Also manageable with HostServ commands (below).
- **Away** — use `/away [message]`, or the **Set away** sidebar menu item; you
  rejoin with `/back`. While away, you appear offline to "online" lists and get the
  💤 symbol in member lists and profile.

### 7.1 Your theme

Your **Profile & settings** page has a **My theme** card where you can make the
chat look the way you like — your choice overrides the server-wide theme that
the admin configured:

- **Mode** — Dark or Light (each theme ships both palettes; toggling applies
  instantly).
- **Preset** — pick from the library of **75 colour schemes** (Midnight, Nord,
  Dracula, Solarized, One Dark, Gruvbox, Catppuccin, Tokyo Night, Cyberpunk,
  Forest, Ocean, Sunset, Rose, Monochrome, …). Each previews instantly.
- **Accent / Sidebar colours** — fine-tune the preset with a colour picker, or
  "use preset" to fall back to the preset's values.
- **Font** — Default, Sans, Serif, Mono, or Rounded (system fonts only).
- **Chat background** — a colour and/or an image behind the message list
  (upload your own **PNG/JPG/WebP/GIF** up to 5 MB, or pick Contain (default) /
  Cover / Repeat). An **overlay opacity** slider adds a translucent layer
  between the text and the image — raise it when a busy image makes chat hard
  to read.
- **Save theme** applies your choices; **Reset to server default** drops them
  and puts you back on the admin's theme. The quick 🌙/☀️ toggle in the chat
  header flips just the dark/light mode and is remembered per account.

If the admin has disabled personal themes, the card shows a notice and the theme
controls are hidden — everyone uses the server theme.

**Channel backgrounds** — if you own a channel, right-click it in the sidebar
and choose **Channel background** to give it its own image, colour, image fit
(defaults to **Contain**), and overlay opacity (default 55%). Everyone viewing
the channel sees it. Admins can set (or remove) any channel's background too.

### 7.2 Notification sounds & alerts

Audio alerts play in your browser when a message lands while you're looking
elsewhere. Open your **Profile & settings** page (from the `⚙` menu) and scroll
to **Notification sounds**:

- **Direct messages** — sound that plays when a new DM arrives while you're not
  viewing that conversation.
- **Channel messages** — sound that plays when a new message arrives in a channel
  you're a member of but **aren't currently viewing**. You're never pinged for the
  channel you're actively reading; @mentioning you in the open channel does ping.
- Each dropdown offers **Off (muted)** or any sound the admin uploaded; the
  **Play** button previews your current pick. Changes save instantly.
- **Per-user overrides** — pick a specific sound for a particular person (or
  **mute** them entirely) with **Add override**. An override applies to that
  person's DMs *and* their channel messages everywhere. Use **Remove** to let
  them fall back to your default choices again.

Guests get the default sounds automatically but can't change them. Any sound the
admin uploads is available to everyone — you can't upload your own.

#### Unified alert preferences

One preference set drives every surface — the web chat, the PWA, the Desktop
client and the Messenger. From **Profile & settings → Push notifications**:

- **Master toggles** — *Play sounds* and *OS & in-app alerts* switch an entire
  channel off in one click; *Show message previews* replaces message bodies in
  notifications with a generic line.
- **Quiet hours** — suppress sounds and alerts between a start and end time, on
  selected days (none selected = every day). Evaluated in your **local time**;
  Web Push uses the UTC offset you report automatically at save/time.
- **Highlight keywords** — messages containing these words alert you exactly
  like an `@mention`, even in channels set to *mentions only* (one per line).
- **Per-channel notification modes** — the joined-channel list lets you switch
  any channel between **All messages / Mentions only / Nothing**, the same
  control as the 🔔 button in each channel's header.
- **Send test notification** — fires a test push to this browser.

#### How alerts behave

- **In-app toasts** — new DMs, @mentions in other channels, invites and friend
  events pop a card in the top-right (avatar, accent per kind, click-to-jump).
  Toasts are suppressed when you're already reading that conversation.
- **Badges** — red = someone needs you (unread mentions/DMs); the plain badge
  on a channel means activity-only unread.
- **Clicking any notification jumps to the exact message**, not just the
  channel (the jump highlights the message in place).
- **Muted channels never sound**: a channel set to *Nothing* is filtered
  server-side from the background stream, so no client ever alerts for it.
- **Do Not Disturb** silences sounds, toasts and OS notifications everywhere;
  muting a person silences all of their surfaces at once.

### 7.3 Hide your online status

`/set hide on` hides you from the online list; `/set hide off` unhides.

### 7.4 Your public profile

Every user has a profile at `/u/<username>` (click any nick, or right-click →
**View profile**). It shows your avatar initial, status (Guest / Helper /
IRC Operator / Staff / Registered + online/away/bot), registration date, and
the channels you're in. For other registered users, the profile also shows
friend actions (Add Friend / Accept / Decline / Remove / Block / Unblock).
A ✕ button in the corner returns you to the chat.

### 7.5 Support & legal

- **Support tickets:** from the `⚙` account menu choose **Support** to open
  tickets. Registered users can create a ticket (subject + description), reply
  to the thread, and close it. Staff may reply, assign, reopen, or open tickets
  on your behalf — and each staff reply is emailed to your registered address
  via the server's SMTP settings. Guests can't open tickets (they have no
  account/email); log in or register first.
- **Terms of Service & Privacy Policy:** every server publishes editable pages
  at **/terms** and **/privacy**, linked from the account menu, the login and
  registration forms, and the guest box. They're maintained by the admin under
  **Admin → Terms & Privacy**.

---

## 8. Right-click context menus

Right-click anywhere on:

- a **message** — Reply, Copy text, Edit (your own), Delete, **Report message**,
  Message author, Copy username;
- a **user / member / nick** — Message, View profile, Whois, Copy username, plus
  (if you're a channel op) Voice / Half-op / Op / Mute / Kick / Ban, and
  Ignore;
- a **channel name** — Open channel, Copy share link, Channel info, Set topic
  (op), Invite (op), Leave.

---

## 9. Command reference

Every command can be typed into the chat input, prefixed with `/`. Commands are
grouped below exactly as `/help` groups them. For one command's details run
`/help <command>`.

> **Notation:** `<>` = required, `[]` = optional. Durations read like `30m`,
> `2h`, `1d`, `1w`, `1mo`, `1y` (a bare number is minutes).

### 9.1 Core commands

| Command | Description |
|---|---|
| `/help [command]` | List all commands, or help for one command |
| `/join <#channel> [key]` | Join a channel (creates it if it doesn't exist) |
| `/part [#channel] [reason]` | Leave a channel |
| `/quit [reason]` | Disconnect (log out) from the chat |
| `/me <action>` | Send an action message (`* nick acts`) |
| `/msg <nick> <message>` | Private message a user (aliases `/pm`, `/query`) |
| `/notice <nick> <message>` | Send a notice — no notification created |
| `/nick <newnick>` | Change your username (2–32 IRC-safe chars) |
| `/away [message]` | Mark yourself away |
| `/back` | Return from being away |
| `/whois <nick>` | Show user info (registration, last seen, idle, channels; IP for ops) |
| `/whowas <nick>` | Show the last-seen record for a nickname |
| `/who [#channel\|mask]` | List online users matching a mask, or a channel's members |
| `/names [#channel]` | List a channel's members with their mode prefixes |
| `/userhost <nick>` | Show a user's `ident@host` |
| `/list` / `/channels` | Open the public channel browser |
| `/topic [#channel] [new topic]` | View or set the channel topic |
| `/ping` | Ping the server — replies "Pong!" |
| `/invite <nick> [#channel]` | Invite a user to a channel (op) |
| `/knock <#channel>` | Ask operators to let you into an invite-only channel |
| `/ignore <nick>` | Stop receiving private messages from a user |
| `/unignore <nick>` | Reverse `/ignore` |
| `/share [#channel]` | Show the shareable link for a channel (and copy it) |
| `/search <term>` | Search your channels and private messages (or use the search box in the chat header) |

### 9.2 Channel operator commands

*Apply to the current channel (or the one you name). Levels gate each command.*
Use them from the member right-click menu or by typing:

| Command | Who | Description |
|---|---|---|
| `/kick <nick> [reason]` | halfop+ | Remove a user from the channel |
| `/kickban <nick> [reason]` | halfop+ | Kick and ban them at once |
| `/ban <mask-nick> [duration] [reason]` | halfop+ | Ban a nick or mask (e.g. `*!*@1.2.3.4`) |
| `/unban <mask-nick>` | halfop+ | Remove a ban |
| `/quiet <nick> [duration]` | halfop+ | Mute a user (cannot speak; shows `+q`) |
| `/op <nick>` / `/deop <nick>` | op+ | Grant / remove channel operator |
| `/halfop <nick>` / `/dehalfop <nick>` | op | Grant / remove half-operator |
| `/voice <nick>` / `/devoice <nick>` | halfop+ | Grant / remove voice (speaks in `+m`) |
| `/mode [+/-modes] [args]` | halfop+ | Change channel modes and bans (see §3.5) |
| `/topiclock <on-off>` | halfop+ | Lock / unlock topic to ops |
| `/clear [#channel] <users-bans-ops-voices-topic-modes>` | founder/op | Clear channel state (bare `/clear` clears your window) |

**Operator rules:** you may only change users *below* your own level, and may
only grant up to your own level (half-op grants voice; op grants voice/half-op/
op; admin grants to admin; founder grants to admin). Server admins may do
anything.

### 9.3 NickServ / ChanServ commands (serv)

| Command | Description |
|---|---|
| `/register <#channel>` | Register a channel as founder; keeps it after empty. If no channel, confirms your account |
| `/identify <password>` | Verify your password; or `/identify #channel <key>` to join a keyed channel |
| `/logout` | Log out |
| `/set <option> <value>` | Account: `/set email|password|hide`. Channel: `/set #chan founder|password|key desc|topic|private|secret|visibility|successor|mlock ...` |
| `/ns <command>` | Prefix a NickServ command |
| `/cs <command>` | Prefix a ChanServ command |
| `/ghost`, `/release`, `/recover` | Terminate your other sessions (reclaim your nick) |
| `/status [nick]` | Show if a nick is registered and offline/online |
| `/info [nick]` | Server info, or your account info (you + admins) |
| `/group` | Nicks and accounts are unified here — nothing to do |
| `/rename <nick>` | Same as `/nick` |
| `/passwd <newpassword>` | Change your password (same as `/set password`) |

**ChanServ details:**

- `/drop <#channel>` — founder only, deletes the channel permanently.
- `/unregister <#channel>` — founder only; channel becomes temporary and
  vanishes when empty.
- `/access <#channel> list|add <nick> <admin|op|halfop|voice>|del <nick>|clear`
  — the persistent access list (works even for users not currently in the
  channel).
- `/akick <#channel> add|del|list|clear` — auto-kick-ban list; anyone on it is
  refused entry / immediately removed.
- `/transfer <#channel> <newfounder>` / `/set <#channel> founder <nick>` —
  hand founder to another member.
- `/getkey <#channel>` — check whether a key is set.
- `/senak <#channel> <message>` — message all channel ops.
- `/chaninfo <#channel>` — channel facts (founder, members, visibility, reg).
- `/forbid <#channel> [off]` — forbid the channel entirely (nobody may join).

### 9.4 MemoServ

| Command | Description |
|---|---|
| `/memo send <nick> <message>` | Send an offline memo |
| `/memo read [id]` | Read unread memos, or one by id |
| `/memo list` | List your memos (with read/unread flags) |
| `/memo del <id>` | Delete a memo |
| `/memo summary` | Unread vs. total counts (also shows your notify mode) |
| `/memo set <notify\|silent>` | Preferred notification mode (persisted) |
| `/ms ...` | Alias of `/memo` |

### 9.5 HostServ

| Command | Description |
|---|---|
| `vhost set <host>` | Set a virtual hostname |
| `/vhost on` | Activate your vhost |
| `/vhost off` | Deactivate it |
| `/vhost status` | Show your current vhost |
| `/hs <command>` | Alias of `/vhost` |

### 9.6 OperServ & o:lines

Operating (`/oper`) is **per-user** on this server. An administrator issues an
**o:line** in **Admin → O-lines** (your nick + a private password + an operator
class). If you have one:

```
/oper <your nickname> <your o:line password>
```

You then operate with that class's permissions until you log out or run
**`/deoper`**. Your nick must exactly match the o:line — there is no shared
operator password. All OperServ commands can also be prefixed with **`/os`**
(e.g. `/os kline *!*@1.2.3.4 1h spam`). If you think you were banned in error,
contact the server owner. See the **Admin Guide**.

---

## 10. Moderation & etiquette tips

- Follow the channel's MOTD, topic and listed rules.
- Keep private-channel share links to people you trust — anyone with one can join
  your private channel.
- Use `@mention` sparingly; mentions generate notifications for the target.
- If a channel is misbehaving, `/knock` won't help — talk to an operator or the
  admin, who can be seen in `/whois` / `/info` and by their red names.

---

## 11. Performance notes

- Messages are capped at 2000 characters.
- Sends are rate-limited: more than ~12 messages/DMs in 5 seconds will get a
  "sending too quickly" message — pause and retry.
- The channel history shown when you open a channel is the most recent 60
  messages; use **↑ Load earlier messages** to page back, and older text is in
  the (admin-only) chat logs.
# Introducing LVChat

**A modern, self-hosted, open-source chat platform that combines the best of Discord-style usability with the power and flexibility of IRC.**

LVChat is a full-featured team and community chat application you run on your own server. No subscriptions to a third-party cloud. No data leaving your infrastructure. No feature gates imposed by a vendor. Just a powerful chat platform you own, control, and customize.

---

## Features at a Glance

### Communication

- **Channels** — public, private, secret, invite-only, keyed, moderated, with member limits and persistent topics
- **Direct Messages** — private conversations with read receipts, unread badges, and offline memos (MemoServ)
- **Voice & Video** — one-on-one and group calls (ring/accept/decline), per-channel voice chat, camera and screen sharing with background effects, waiting rooms, host moderation (mute/kick/lock), recording, and scheduled meeting events (`#evt-*`), powered by LiveKit WebRTC
- **GIF Search** — Giphy-backed picker with trending and live search, inline rendering
- **Image Uploads** — drag-and-drop or attach, with lightbox preview
- **Reactions** — emoji reactions on any message
- **Reply/Quote** — reply to any message with inline quoted context
- **Text Formatting** — bold, italic, strikethrough, code blocks, blockquotes, lists, mentions
- **Slash Commands** — full IRC-style command set with Discord-style autocomplete (`/help`, `/join`, `/msg`, `/kick`, `/ban`, `/topic`, `/mode`, and 60+ more)

### Community & Moderation

- **Friends System** — send/accept/decline friend requests, block/unblock, online/offline grouping
- **Custom Roles & Permissions** — create roles with custom colors and permissions, assign to users
- **Helper Users** — a distinct tier between regular users and staff with green nicks and automatic half-op
- **Channel Operator Levels** — full IRC-style hierarchy: `~` founder, `&` admin, `@` op, `%` halfop, `+` voice
- **Channel Services** — ChanServ, NickServ, MemoServ, HostServ, OperServ (register channels, manage access lists, auto-kick, vhosts, and more)
- **Message Reports** — right-click any message to report with preset reasons; staff review queue
- **Moderation Queue** — every filter hit, kick, ban, mute, and global ban logged with actor and context
- **Bad-Word Filter** — per-channel or server-wide, censor or block, with admin-managed word list
- **Global Bans** — kline, gline, zline, shun by nick, IP, or CIDR with duration and reason
- **Forbidden Nicks & Channels** — sqline/cqline to reserve or block names
- **Age Gate** — 18+ certification on registration, optional admin approval for new accounts
- **Support Tickets** — users open tickets, staff assign and reply, email notifications via SMTP

### Administration

- **Full Admin Dashboard** — users, channels, bans, spam filters, MOTD, server settings, audit log, O-lines, oper classes
- **Analytics Dashboard** — time-range filtering, KPI cards, activity charts (messages/day, DAU, registrations), moderation charts, health charts — all server-side SVG, zero JS dependencies
- **Chat Logging** — append-only archive of every channel and DM, nothing ever deleted, full admin search
- **User Management** — create accounts manually, invite by email, delete accounts, promote/demote roles, reset MFA
- **SMTP Email** — built-in dependency-free SMTP client for invites, welcome emails, and support ticket replies
- **Two-Factor Authentication (TOTP)** — RFC 6238 MFA with QR enrollment, per-class enforcement, forced setup at login
- **Legal Pages** — editable Terms of Service and Privacy Policy with a rich-text editor
- **Incoming Webhooks** — Discord-compatible webhook endpoints for integrations (GitHub, GitLab, Zapier, etc.)
- **AI Bot Integration** — OpenClaw AI agents connect as authenticated bot users with channel and DM access, rich markdown rendering
- **Module System** — extend LVChat with plug-and-play modules (slash commands, routes, admin pages, database tables, assets, views)
- **SaaS Billing Module** — optional plan-based metering with Stripe, PayPal, and BTCPay Server integration for monetized deployments
- **Update System** — built-in update feed, version checking, and one-click download for web and desktop clients

### Personalization

- **75 Preset Color Themes** — Midnight, Nord, Dracula, Solarized, Catppuccin, Tokyo Night, Cyberpunk, and many more
- **Custom Themes** — personal accent/sidebar colors, font choice, chat background image with overlay opacity
- **Per-Channel Backgrounds** — channel owners set custom backgrounds
- **Unified Notifications** — one alert engine shared by the web app, PWA, desktop client and Messenger: in-app toasts, mention-aware badges, quiet hours, highlight keywords, content-preview toggles, master sound/OS switches, and click-to-message deep links
- **Notification Sounds** — per-context sounds (DMs, channels), per-user overrides, admin-uploadable sound library
- **Push Notifications** — real OS/browser notifications via Web Push (VAPID + RFC 8291), per-channel and per-user muting, delivered even when the app is closed
- **Dark/Light Mode** — instant toggle, remembered per account

### Realtime & Performance

- **Three Realtime Tiers** — AJAX polling (2s default), Server-Sent Events (SSE), or WebSocket gateway (Workerman) for up to 50,000+ concurrent users
- **Offline Support** — messages sent while offline are queued and delivered on reconnect
- **Resilient Sending** — AJAX with native no-JS fallback
- **Shareable Channel Links** — `/c/gaming` links that auto-join logged-in users and redirect guests through login
- **Channel URL Embeds** — embed any web page in a pane above the chat with a server-side proxy that bypasses X-Frame-Options restrictions

---

## Clients

LVChat is available on every platform your users need:

| Client | Type | Description |
|--------|------|-------------|
| **LVChat Web** | Progressive Web App (PWA) | The full-featured chat in any modern browser. Installable to the home screen, Start menu, or dock. Opens in its own window, loads instantly, works offline, and delivers push notifications even when closed. |
| **LVChat Messenger Web** | Progressive Web App (PWA) | An IM-first web client with a buddy list, custom contact groups, DMs, and rooms. Lightweight, installable, and perfect for users who want a focused messaging experience. |
| **LVChat Desktop** | Electron App (Windows, macOS, Linux) | The full web chat as a native desktop application. Multi-profile support (connect to multiple LVChat servers), OS-native notifications, tray icon, auto-login with encrypted credential storage, and auto-updates. |
| **LVChat Messenger** | Electron App (Windows, macOS, Linux) | An IM-first desktop client with a Pidgin-style buddy list, custom contact groups, and dedicated chat windows. The foundation for future native mobile apps. |

### Native Mobile Apps — Coming Soon

Native iOS and Android applications are in development, built on the same IM-first architecture as the Messenger clients. The Messenger Web PWA provides a fully functional mobile experience in the meantime — installable from any mobile browser with push notifications and offline support.

---

## Who Is LVChat For?

- **Small Businesses & Startups** — Replace expensive per-seat SaaS subscriptions with a one-time deployment on a $5/month VPS. Own your team's communication data.
- **Gaming Organizations & Clans** — Channels, voice chat, roles, and the familiar IRC-style command set your members already know. Run tournaments, coordinate raids, and build community — all on your own server.
- **Online Communities & Forums** — Give your community a real-time chat layer alongside your existing site. Embed channels, invite via share links, and moderate with the same tools your IRC veterans love.
- **Open-Source Projects** — A chat platform that matches your values. Self-hosted, transparent, auditable, and free forever. No corporate overlord changing the terms of service.
- **Educational Institutions** — Private, moderated channels for classes and study groups. Age gating, admin approval, and full logging for compliance.
- **Content Creators & Streamers** — Engage your audience with a branded, self-hosted chat. Custom themes, channel URL embeds, webhooks for stream alerts, and AI bot integration for automated moderation.
- **Managed Service Providers** — Use the SaaS billing module to offer hosted chat as a paid service to your clients, with plan-based metering and Stripe/PayPal/BTCPay checkout.
- **Anyone Who Values Privacy** — Your data stays on your server. No third-party analytics, no ad targeting, no data mining. Ever.

---

## Why LVChat Over Cloud-Hosted Commercial Solutions?

| | LVChat | Discord / Slack / Teams |
|---|---|---|
| **Cost** | Free. Runs on a $5/month VPS or even shared hosting. No per-seat fees, no premium tiers imposed on you. | $8–$20+/user/month for business plans. Costs scale linearly with team size. |
| **Data Ownership** | Your data lives on your server, in a SQLite database you control. Full chat logs, full export, full deletion — your call. | Your data lives on the vendor's servers. Subject to their retention policies, their outages, and their terms of service. |
| **Privacy** | No third-party tracking, no telemetry, no ad targeting. AGPL-3.0 licensed. | Your data is their product. Privacy policies change at their discretion. |
| **Customization** | Full source code access. Modify anything. Build custom modules. White-label completely. | Limited to vendor-approved integrations and branding options. No source access. |
| **Self-Hosting** | Deploy anywhere — shared hosting, VPS, dedicated server, air-gapped network. | Not available. You must use their cloud infrastructure. |
| **Vendor Lock-In** | None. Export your SQLite database anytime. Migrate or fork freely. | Locked into their ecosystem. Migration tools are limited or nonexistent. |
| **Uptime Control** | You control maintenance windows, updates, and scaling. No surprise outages. | Subject to vendor outages, scheduled maintenance, and service degradation outside your control. |
| **Compliance** | Meet HIPAA, GDPR, SOC2, or any regulatory requirement on your own infrastructure. | Dependent on vendor certifications. Data residency options may be limited or expensive. |
| **Longevity** | Open-source forever. Even if the original maintainer stops, the community can continue. | Platforms shut down, pivot, or sunset features without warning (RIP Slack free tier, Google Allo, etc.). |
| **AI Integration** | Connect any AI agent via the OpenClaw API. No vendor approval process. | Limited to vendor marketplace bots with revenue sharing and review delays. |
| **Monetization** | Built-in SaaS billing module lets you resell chat as a service. Keep 100% of revenue minus payment processing. | Revenue sharing required. Terms prohibit most resale models. |

---

## Open Source & Licensing

LVChat is released under the **GNU Affero General Public License v3 (AGPL-3.0-only)**. This means:

- **Free to use** for any purpose, commercial or non-commercial
- **Full source code** always available
- **Modify and redistribute** under the same license
- **Network use clause** ensures hosted modifications remain open
- **No CLA traps** — contributions accepted under the Developer Certificate of Origin

The core application and all bundled modules (including WebRTC voice) are AGPL-3.0. Third-party modules may carry their own licenses, enabling a healthy ecosystem of both open-source and commercial extensions.

---

## Get Started

- **Documentation:** Comprehensive guides for users, admins, and developers
- **Installation:** PHP 8.1+ with SQLite. Deploy in minutes on shared hosting or scale to 50,000+ concurrent users on dedicated hardware.
- **Community:** Join `#general` on any LVChat instance or visit the project repository
- **Contributing:** Issues, pull requests, and module development welcome under the DCO

**LVChat: Your chat. Your server. Your rules.**

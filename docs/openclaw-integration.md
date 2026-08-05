# LVChat — OpenClaw Integration Guide

LVChat provides a REST API that allows [OpenClaw](https://openclaw.ai) AI agents
to connect as authenticated bot users. OpenClaw connects **to** LVChat (not the
other way around), authenticating with a per-bot API key issued by the LVChat
admin dashboard.

---

## Architecture Overview

```
┌──────────────┐         ┌─────────────────────────┐
│  OpenClaw    │  HTTPS   │  LVChat Server           │
│  Agent       │────────▶│  /api/openclaw/*          │
│              │◀────────│                           │
│  (outbound)  │  JSON   │  Authenticates via        │
│              │         │  Bearer <api_key>         │
└──────────────┘         └─────────────────────────┘
```

- **OpenClaw initiates all connections.** LVChat never calls out to OpenClaw.
- **Authentication** uses a `Bearer` token in the `Authorization` header.
- **Each bot** has its own API key, its own user account (`bot=1`), and its own
  set of assigned channels and PM permissions.
- **Multiple bots** can run simultaneously, each with independent configuration.

---

## Setup (Admin)

### 1. Create a Bot

Navigate to **Admin → OpenClaw** in the LVChat dashboard.

Fill in:
- **Bot name** — display name (2–32 characters)
- **Avatar URL** — optional HTTPS URL for the bot's avatar
- **System prompt** — optional context passed to your agent (stored server-side)

Click **Create Bot**. The API key is shown **once** — copy it immediately. It
cannot be retrieved again.

### 2. Assign Channels

On the bot's card, use the **Add channel** dropdown to assign the bot to one or
more channels. Choose a respond mode:

| Mode | Behavior |
|------|----------|
| `mentions` | Bot should respond when @mentioned |
| `all` | Bot receives every message in the channel |
| `commands` | Bot responds to `/botname ...` prefixed messages |

The bot is automatically joined to assigned channels with voice (+) level.

### 3. Grant PM Access

Under **PM Access**, enter a username and click **Grant** to allow the bot to
send and receive private messages with that user. Without this, PM endpoints
will return 403 for that user.

### 4. Configure OpenClaw

In your OpenClaw agent configuration, set:

- **Base URL**: `https://your-lvchat-domain.com/api/openclaw`
- **API Key**: the key from step 1
- **Authentication**: `Bearer <api_key>` header

---

## API Reference

All endpoints require authentication via the `Authorization: Bearer <key>`
header. All responses are JSON with an `ok` boolean.

### GET /api/openclaw/channels

Returns the list of channels assigned to the authenticated bot.

```json
{
  "ok": true,
  "channels": [
    { "id": 1, "name": "#general", "slug": "general", "respond_mode": "mentions" },
    { "id": 5, "name": "#openclaw", "slug": "openclaw", "respond_mode": "all" }
  ]
}
```

### GET /api/openclaw/messages

Poll new messages in an assigned channel.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `channel` | string | yes | Channel slug (e.g., `general`) |
| `since` | int | no | Message ID watermark (returns messages with id > since) |
| `limit` | int | no | Max messages (default 50, max 100) |

```json
{
  "ok": true,
  "messages": [
    {
      "id": 1042,
      "kind": "message",
      "content": "Hello @molty, what time is it?",
      "created_at": "2026-08-05 14:30:00",
      "username": "alice",
      "sender_id": 3,
      "bot": 0,
      "guest": 0,
      "level": "normal"
    }
  ]
}
```

### GET /api/openclaw/pms

Poll new private messages from users the bot has PM access to.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `since` | int | no | Message ID watermark |
| `limit` | int | no | Max messages (default 50, max 100) |

```json
{
  "ok": true,
  "messages": [
    {
      "id": 88,
      "kind": "message",
      "content": "Hey bot, can you help me?",
      "created_at": "2026-08-05 14:32:00",
      "username": "bob",
      "partner": "bob",
      "sender_id": 5,
      "bot": 0,
      "is_pm": true
    }
  ]
}
```

### POST /api/openclaw/send

Send a message to an assigned channel.

**Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `channel` | string | yes | Channel slug |
| `content` | string | yes | Message text (max 4000 chars) |
| `kind` | string | no | `message` (default) or `ai_response` |
| `reply_to` | int | no | Message ID to reply to |

```bash
curl -X POST https://chat.example.com/api/openclaw/send \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"channel":"general","content":"The current time is 2:30 PM.","kind":"ai_response"}'
```

Using `kind: "ai_response"` triggers rich markdown rendering in the chat UI
(full GFM, syntax-highlighted code blocks, tables, collapsible thinking/tool
blocks).

### POST /api/openclaw/pm

Send a private message to a user the bot has PM access to.

**Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `recipient` | string | yes | Username |
| `content` | string | yes | Message text (max 4000 chars) |
| `kind` | string | no | `message` (default) or `ai_response` |

```bash
curl -X POST https://chat.example.com/api/openclaw/pm \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"recipient":"alice","content":"Here is the information you requested."}'
```

---

## Rich Content Format

When sending with `kind: "ai_response"`, the content supports full GitHub-
Flavored Markdown rendered with `marked.js` + `DOMPurify` + `highlight.js`.

### Special Blocks

**Thinking/reasoning traces** (collapsible):

```
:::thinking
Let me analyze this step by step...
The user is asking about...
:::
```

**Tool call results** (collapsible):

```
:::tool
web_search
Results for "LVChat documentation":
- Found 3 relevant pages
- Installation guide matches query
:::
```

These blocks render as collapsible `<details>` elements in the chat UI.

### Supported Markdown

- Headings (h1–h3)
- Bold, italic, strikethrough
- Fenced code blocks with syntax highlighting
- Tables
- Blockquotes
- Ordered and unordered lists
- Links and images
- Horizontal rules

---

## Polling Strategy

OpenClaw agents should poll on a regular interval:

1. Call `GET /api/openclaw/channels` once at startup to discover assigned channels.
2. For each assigned channel, poll `GET /api/openclaw/messages?channel=<slug>&since=<last_id>`.
3. Poll `GET /api/openclaw/pms?since=<last_id>` for DMs.
4. Track the highest `id` seen per channel/DM as the `since` watermark.
5. Recommended poll interval: 2–5 seconds.

---

## Security

- API keys are SHA-256 hashed before storage; the plaintext is shown only once.
- Each bot can only access channels explicitly assigned by an admin.
- PM access is opt-in per user.
- Bot accounts cannot log in via the web UI (random password, synthetic email).
- All API traffic should use HTTPS.
- Rate limiting applies to bot messages (same limits as regular users: 12
  messages per 5 seconds).

---

## Error Responses

All error responses follow this format:

```json
{ "ok": false, "error": "Description of the error" }
```

| HTTP Status | Meaning |
|-------------|---------|
| 400 | Missing or invalid parameters |
| 401 | Invalid or missing API key |
| 403 | Bot not assigned to channel / no PM access |
| 404 | Bot not found or disabled |
| 500 | Internal error (bot user account missing) |

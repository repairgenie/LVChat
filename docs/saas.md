# SaaS module — design & implementation

Plan-based metering, feature gating, and billing for LVChat, shipped as the
`modules/saas/` module. This document covers the design decisions, the data
model, the entitlement engine, and the core integration points.

## Goals

- A default **Free** tier (editable, undeletable) with per-plan **limits**
  (numbers or *unlimited*) and **features** (on/off).
- **Admin-managed paid plans** with self-serve checkout via **Stripe**,
  **PayPal**, and **BTCPay Server**.
- **Per-user overrides**: an admin can assign any feature/limit to an
  individual user regardless of their plan.
- **Connection metering**: per-account concurrent-WebSocket pool + a hard
  global ceiling.
- A **platform fee** (default $0.75) on every paid transaction, configured in
  the deployment `.env`, **not** disableable by admins (except in developer
  mode).

## Key decisions (from product review)

| Resource | Metering |
|---|---|
| Concurrent connections | Per-account pool; reject-new; global ceiling 200 (configurable) |
| Meetings (#mtg) | Feature on/off + per-plan concurrent cap |
| Voice (calls + channel voice) | Feature on/off; concurrent slots reuse global `voice_max_users` |
| Voice QoS | Per-plan talker cap + bitrate tiers |
| Owned channels | Per-plan cap (replaces global `max_channels_per_user`) |
| Channel memberships | Per-plan cap |
| DMs / messages | Not metered (existing flood gate stays) |
| Chat uploads | Per-plan max bytes/file |
| Avatar / theme / status | Not metered |
| OpenClaw bots | Feature on/off + count cap |
| Webhooks / friends / groups / memos | Not metered |
| Support tickets | Per-plan open-ticket cap (0 = disabled) |
| Registration invites | Per-plan cap |
| Message history / search | Per-plan message-count sliding window |

## Data model (`schema.php`)

- **`saas_plans`** — `name, slug, description, is_free, active, sort_order,
  price_amount, price_currency, billing_cycle (monthly|yearly), trial_days,
  features (JSON), limits (JSON), qos (JSON), provider_ids (JSON)`.
- **`saas_user_plans`** — one row per user: `plan_id, status
  (active|grace|expired), source (admin|self), provider, provider_sub_id,
  period_start, period_end, grace_until, auto_renew`.
- **`saas_overrides`** — per-user exceptions: `(user_id, key, value, note)`;
  `value` is `1`/`0` for features, a number for limits, or `''` for unlimited.
- **`saas_checkouts`** — self-serve session state.
- **`saas_payments`** — transaction ledger with `kind` (`payment` |
  `platform_fee`) so the developer fee is visible and auditable.
- **`saas_events`** — idempotency ledger (`UNIQUE (provider, event_id)`).

The free plan is seeded once in `schema.php`.

## Entitlement engine (`SaaSService`)

Resolution order for an actor:

1. Free-plan defaults.
2. Overlay the active paid plan (`status IN ('active','grace')`).
3. Overlay per-user overrides.
4. Clamp to hard server ceilings (global connection ceiling, `voice_max_users`).

Key methods used by core services (all guarded by `class_exists('SaaSService')`
so the module is fully optional):

- `feature($actor, $key)` / `limit($actor, $key)` — the gates.
- `connectionLimit($actor)` / `globalConnectionCeiling()` — the daemon.
- `voiceQos($actor)` — webrtc QoS tiers.
- `historyFloor($channelId, $actor)` / `historyFloorGlobal($actor)` — the
  sliding message window.
- Live counters: `ownedChannelCount`, `membershipCount`, `botCount`,
  `openTicketCount`, `regInviteCount`, `meetingCount`.

**Enforcement state machine:** `saas_enabled` (master switch, default off) →
`saas_enabled` off means every feature is allowed and every limit is unlimited,
so existing installs are never degraded by merely shipping the module.
Admins are exempt. Guests always get the free tier.

## Lifecycle (grace → downgrade)

`resolveLifecycle($userId)` runs lazily on every entitlement read (and can be
run for all users via `resolveAllLifecycles()`):

1. `status = active` and `period_end` in the past → `grace`, `grace_until =
   now + saas_grace_days`.
2. `status = grace` and `grace_until` in the past → `expired` (plan limits
   revert to Free).

Provider webhooks drive the same transitions: `paid` → assign/renew,
`grace` → enter grace, `cancelled` → downgrade.

## Core integration points

| File | Change |
|---|---|
| `src/bootstrap.php` | loads `src/Dotenv.php` (`ROOT/.env`) after boot |
| `bin/ws-server.php` | connection pool + global ceiling in `onWebSocketConnect` (peek → gate → consume) |
| `src/services/Realtime.php` | `peekTicket()` (resolve without consuming) |
| `src/services/ChannelService.php` | owned-channel + membership caps |
| `src/services/UploadService.php` | per-plan `upload_max_bytes` (chat uploads) |
| `src/services/OpenClawBotService.php` | `openclaw_bots` feature + count cap |
| `src/services/SupportService.php` | `open_tickets` cap |
| `src/services/InviteService.php` | `reg_invites` cap |
| `src/services/MessageService.php` | `history()`/`historyBefore()` take a floor id; `searchChannels()` clamps |
| `src/controllers/ChatController.php` | passes the history floor; passes actor to uploads |
| `modules/webrtc/MtgController.php` | meetings feature + concurrent cap |
| `modules/webrtc/VoiceController.php` | voice feature gate; per-user QoS in join response |
| `modules/webrtc/CallController.php` | voice feature gate on initiate |
| `modules/webrtc/LiveKitService.php` | `talkerCap($actor)` / `bitrate($actor)` |

All gates are `class_exists('SaaSService')` guarded; without the module (or
with the master switch off) they are no-ops.

## Platform fee

- `.env`: `SAAS_PLATFORM_FEE` (default `0.75`), `SAAS_PLATFORM_FEE_CURRENCY`,
  `SAAS_PLATFORM_FEE_DESTINATION` (Stripe Connect account id), and
  `SAAS_DEVELOPER_MODE` (default `0`).
- `SaaSService::platformFeeEnabled()` — when developer mode is on, the
  `saas_platform_fee_enabled` setting (admin toggle) is honored; otherwise the
  env flag is authoritative and the admin form never renders a toggle.
- `BillingService::capturePlatformFee()` runs on every settled payment:
  idempotent (one fee per provider payment id), always recorded in
  `saas_payments` (`kind = platform_fee`), and — for Stripe with a destination
  configured — executed as a `Transfer` (anchored to the settled charge via
  `source_transaction`).

## Billing

`BillingService` + `Providers/*Driver` implement `SaasProviderDriver`
(`createCheckout`, `handleWebhook`, `configured`). Webhooks flow:

```
POST /api/saas/billing/webhook/{provider}
  → driver.handleWebhook (signature verification)
  → SaaSService::recordEvent (idempotency)
  → BillingService::applyEvent (assign/renew/grace/downgrade + ledger + fee)
```

Provider-specific notes:

- **Stripe**: Checkout Session (`mode=subscription`); webhook signature
  `t=…,v1=…` HMAC-SHA256. `invoice.paid` events carry only the subscription id
  and are attributed via `saas_user_plans.provider_sub_id`.
- **PayPal**: product/plan creation is memoized in `provider_ids.paypal`;
  subscriptions carry `custom_id = user_id`; webhooks verified through the
  `verify-webhook-signature` API.
- **BTCPay**: invoice-per-cycle (no auto-renew). Every `InvoiceSettled`
  activates or renews. `BTCPay-Sig: sha256=<hmac>` with the store webhook
  secret.

## Tests

`tests/saas_test.php` (54 assertions) covers: module boot, free-plan
seeding/protection, master-switch semantics, plan CRUD, resolution order,
overrides, lifecycle, platform fee, core-service gates, and hermetic
Stripe/BTCPay webhook flows (signature verification, activation, renewal,
idempotency). Run with:

```
CHAT_DB=/tmp/opencode/saas.db php tests/saas_test.php
```

# SaaS (Plans & Billing) — module

Plan-based metering, feature gating, and billing for LVChat. Free tier by
default; admin-managed paid plans with **Stripe**, **PayPal**, and **BTCPay
Server** checkouts; per-user overrides that assign features/limits regardless
of plan; and a developer **platform fee** on every paid transaction.

## What it meters

| Key | Kind | Meaning |
|-----|------|---------|
| `connections` | limit | Concurrent WebSocket connections per account (free = 3). Reject-new at the cap. |
| `events` | feature | Scheduled events with chat rooms (WebRTC module). |
| `events_concurrent` | limit | Max live events a user may hold. |
| `voice` | feature | One-on-one calls + channel voice (WebRTC module). |
| `voice_talker_cap`, `voice_bitrate` | qos | Per-plan voice quality tiers (blank = global admin cap). |
| `owned_channels` | limit | Channels a user may own (replaces the global `max_channels_per_user`). |
| `memberships` | limit | Channels a user may join. |
| `upload_max_bytes` | limit | Max bytes per chat image upload. |
| `openclaw_bots` | feature | User-created OpenClaw AI bots. |
| `openclaw_bot_count` | limit | Max bots per user (0 = disabled). |
| `open_tickets` | limit | Max open support tickets (0 = disabled). |
| `reg_invites` | limit | Max unused registration-invite tokens. |
| `history_messages` | limit | Sliding message-history window per channel (blank = full). |

**Semantics:** a feature is on/off; a limit is a number or *unlimited*
(blank). `unlimited` never exceeds hard server ceilings — the global
connection ceiling (default 200) and `voice_max_users` still apply.
Admins are always exempt. Guests always get the free tier.

## Files

```
module.json                     manifest (admin nav, settings, provider keys)
schema.php                      saas_plans, saas_user_plans, saas_overrides,
                                saas_checkouts, saas_payments, saas_events
init.php                        loads services/controllers (side-effect-free)
routes.php                      /admin/saas*, /billing*, /api/saas/*
SaaSService.php                 entitlement engine, plan CRUD, lifecycle, fee
BillingService.php              provider dispatch, webhooks, platform fee
Providers/SaasProviderDriver.php   driver contract
Providers/StripeDriver.php      Checkout Sessions + transfers + webhooks
Providers/PayPalDriver.php      Subscriptions + verify-webhook-signature
Providers/BTCPayDriver.php      invoice-per-cycle + BTCPay-Sig webhooks
AdminSaaSController.php         Admin → SaaS (Plans / Users / Billing)
BillingController.php           /billing portal, checkout, webhook endpoint
views/                          admin plans/users/billing + user portal
```

## Enable

1. Enable the module on **Admin → Modules**.
2. **Admin → SaaS → Billing**: flip "Enable SaaS metering" on, configure one or
   more providers, paste the provider webhook URLs into the provider dashboards.
3. **Admin → SaaS → Plans**: edit the Free tier and create paid plans.
4. Optional per-user exceptions: **Admin → SaaS → Users**.

Nothing changes on existing installs until the SaaS master switch is flipped on
— while off, every feature is allowed and every limit is unlimited.

## Self-serve checkout

Users open `/billing`, pick a plan + provider, and are taken through the
provider's checkout. Webhooks drive activation/renewal:

- **Stripe** — Checkout Session (`checkout.session.completed`), renewals
  (`invoice.paid`), grace (`invoice.payment_failed`), cancel
  (`customer.subscription.deleted/updated`).
- **PayPal** — Subscriptions (`BILLING.SUBSCRIPTION.ACTIVATED`,
  `PAYMENT.SALE.COMPLETED`, `CANCELLED`, `SUSPENDED`); verified through
  PayPal's verify-webhook-signature API.
- **BTCPay Server** — BTCPay has **no native auto-renew**: each billing cycle
  is a fresh invoice, and every `InvoiceSettled` webhook extends the period.

A failed payment moves the user into a **grace period** (default 3 days,
configurable), then the plan auto-downgrades to Free. Expiry is enforced lazily
on every request, so no cron is required.

## Platform fee (developer support fee)

Every paid transaction routes a small fee to the software developer
(default **$0.75**) so support scales with the userbase. It is configured in
the deployment `.env` file — **admins cannot disable it**:

```
SAAS_PLATFORM_FEE=0.75
SAAS_PLATFORM_FEE_CURRENCY=usd
SAAS_PLATFORM_FEE_DESTINATION=acct_...   # Stripe Connect account; blank = ledger only
SAAS_DEVELOPER_MODE=0                    # 1 exposes an admin toggle for local testing
```

For Stripe, when `SAAS_PLATFORM_FEE_DESTINATION` is set, a **Transfer** of the
fee is created per settled payment to the developer's Connect account. Fees are
always recorded in `saas_payments` (`kind = platform_fee`), idempotently (one
fee per provider payment id).

See `.env.example` at the repository root for the full key list.

## Connection metering

The realtime gateway (`bin/ws-server.php`) enforces the per-account connection
pool and the global ceiling during the WebSocket handshake. It peeks the
one-time ticket, counts the actor's live connections, and rejects the new
connection (Workerman close code 4501 = global ceiling, 4502 = per-account
limit) before the ticket is consumed. Without the module the gate is a no-op.

## Tests

`tests/saas_test.php` exercises the engine against a scratch DB: plan CRUD,
entitlement resolution order (free → plan → override → ceilings), lifecycle
(grace → downgrade), the platform fee, core-service gates, and hermetic
Stripe/BTCPay webhook flows (signature verification, activation, renewal,
idempotency).

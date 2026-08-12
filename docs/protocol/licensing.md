# LVChat license key algorithm & validation protocol

LVChat paid modules validate their license key in **two layers**:

1. **Internal (offline, always)** — an Ed25519 signature + claims check that
   runs entirely on the chat install with zero network traffic. A malformed,
   forged, or expired key never leaves the server.
2. **External (best effort)** — the key is sent to the operator's **License
   Server** (a standalone app, e.g. `~/Documents/lvchat-license-server`, outside
   this repository) which confirms the key still exists, is active, not revoked,
   and within its seat budget — and registers the install's `server_id` against it.

> This file is the **protocol** spec. For the licensing model of LVChat itself
> (AGPL, modules, commercial plugins) see `../licensing.md`.

## Key format (v1)

```
LVC-1-<moduleId>-<payloadB32>-<signatureB32>
```

| Part | Meaning |
|---|---|
| `LVC` | literal prefix |
| `1` | scheme version |
| `<moduleId>` | lowercase `[a-z0-9-_]`, the module the key is bound to (must match `modules/<id>/module.json`) |
| `<payloadB32>` | RFC 4648 base32 (uppercase, **no padding**) of the canonical claims JSON bytes |
| `<signatureB32>` | base32 of `sodium_crypto_sign_detached(payloadBytes, secretKey)` — a 64-byte Ed25519 signature |

**Canonical claims JSON** (field order fixed; the signature covers the exact
payload bytes):

```json
{"v":1,"mod":"premium-badges","type":"pro","holder":"Acme Corp",
 "exp":"2027-12-31","act":3,"iss":"2026-08-11","n":"1839bb183311"}
```

| Field | Meaning |
|---|---|
| `v` | scheme version (1) |
| `mod` | module id (the `-<moduleId>-` segment equals this) |
| `type` | edition — free-form (`standard`, `pro`, `lifetime`, …) |
| `holder` | customer / order reference |
| `exp` | `YYYY-MM-DD`, or `""` = **forever/lifetime key** |
| `act` | max simultaneous activations; `0` = unlimited (forever keys) |
| `iss` | issuance date `YYYY-MM-DD` |
| `n` | per-key nonce (12 hex chars) — Ed25519 signing is deterministic, so without it a bulk batch of identical claims would mint identical keys |

**Internal verification** (`src/services/LicenseKeys.php`), no network:
format regex → base32-decode payload + signature → Ed25519 verify against the
embedded vendor public key → JSON decode → `v` == 1, `mod` matches the requested
module, `exp` is `""` or not past.

## Keypair management

- The License Server signs keys with an Ed25519 **seed** in its `config.php`
  (`license_ed25519_seed`, base64 32 bytes). Generate one with
  `php bin/gen-seed.php`, which also prints the public key.
- The chat app embeds only the **public key** as the `PUBLIC_KEY` constant in
  `src/services/LicenseKeys.php`. Anyone with the app source can verify keys but
  **cannot forge them**.
- An `LVC_LICENSE_PUBLIC_KEY` environment variable overrides the constant
  (used by the test suites and by the License Server, which derives its own
  public key from the seed).

## Key storage & issuance rules

- License Server rows store **only `sha256(key)`** (`key_hash`). The raw key is
  returned exactly once (web UI page or CLI stdout), then unrecoverable.
- `issued_at` / `issued_to` record when a key was handed to a customer. Keys
  with `issued_at IS NULL` sit in the **unissued pool**; the dashboard only
  ever offers unissued rows, so the same key is never handed out twice.
- A single key issued **with a holder** is marked issued immediately. Any other
  combination (bulk quantity, or single without a holder) lands in the pool.
- **Forever keys**: `exp = ""` + `act_max = NULL` (unlimited). One row that any
  number of customers can activate on their own servers; seat limits don't apply.
- Seat accounting: `activations` rows are unique per `(license_id, server_id)`;
  validation refuses a *new* server when the key's `act_max` is reached.

## External protocol

The chat app calls (timeout 8s, per-module result cached for
`license_recheck_hours`, default 24):

### `POST {license_url}/api/licenses/validate`

Body: `application/json` — `{"module":"<id>","key":"LVC-…","server_id":"srv_…"}`

`server_id` is the install's stable fingerprint (`LicensingService::serverId()`,
generated once into `server_config`, survives redeploys of `data/`). Omit it to
validate without consuming a seat.

Success (`200`):

```json
{"ok":true,"module":"premium-badges","license_type":"pro","holder":"Acme Corp",
 "expires_at":"2027-12-31","activations_used":2,"activations_max":3}
```

Failure (`200` with `ok:false`): `{"ok":false,"reason":"<one of>"}`

Reasons: `bad_request`, `malformed`, `unsupported_version`, `bad_signature`,
`unknown_key`, `revoked`, `inactive`, `wrong_module`, `expired`,
`activation_limit`, `rate_limited` (HTTP 429).

Server-side order: offline signature check → `key_hash` lookup → not
revoked/inactive → module match → not expired → seat check → upsert activation →
log to `validation_log`.

### `POST {license_url}/api/licenses/deactivate`

Body: `{"key":"LVC-…","server_id":"srv_…"}` → frees this server's seat
(returns `{"ok":true}`).

## Offline policy (chat app, `LicensingService`)

When the License Server is unreachable the behavior is set by
`license_policy` (Admin → Settings → Licensing):

| Policy | Behavior |
|---|---|
| `grace` (default) | Last known-good `valid` keeps working. A key that **never** confirmed gets a `license_grace_days` window (default 7) counted from its first attempted check, then refuses (`unreachable_grace`). |
| `strict` | Refuses immediately when unreachable (`unreachable_strict`). |
| `offline` | Never dials out; the internal Ed25519 check is the whole gate. |

The internal check always runs first and short-circuits failures with zero
network traffic. A fresh cached `valid` result (within `license_recheck_hours`)
also avoids the network call. With no `license_url` configured, the internal
check is the gate (equivalent to `offline`).

Statuses stored on the module row (`license_status`): `no_key`, `malformed`,
`unsupported_version`, `bad_signature`, `wrong_module`, `expired`, `valid`,
`unvalidated` (in grace, awaiting first confirmation), `server_refused`,
`unreachable_strict`, `unreachable_grace`. Only the failure set blocks
`ModuleLoader::isLicensed()`.

## Edge cases & behaviors

**The two layers catch different things.** The offline Ed25519 check proves the
key is *genuine* (issued by you) and *not expired* — it cannot know a key was
**revoked** or that its **seats are full**, because those are server-side facts.
Consequences:

- With no `license_url` configured (or `license_policy = offline`), a stolen
  genuine key keeps working forever and revocation is impossible. That is a
  deliberate trade-off: no network, no revocation.
- Revocation, seat limits, and activity toggles only take effect when the server
  is reachable. `grace` mode keeps a previously-confirmed key running for as
  long as it stays unreachable — revocation during an outage is deferred until
  connectivity returns.

**Expiry.** `exp` is a calendar date compared against **UTC today**. A key
expiring today is valid today and stops tomorrow. The server is authoritative
when reachable; the offline check uses the install's clock, so a wildly wrong
install clock can misjudge expiry until the server is reachable. Keep `exp`
empty for keys that must never expire (forever keys).

**Keys are immutable.** The claims are inside the signature, so you cannot edit
a key's type, expiry, holder, or seats after issuance — issue a new key instead.
Changing the product catalog name or pricing has no effect on existing keys
(`mod` is what matters, and it cannot change).

**Uniqueness & the nonce.** `n` (12 random hex chars) makes every key unique even
when every other claim is identical — this is what lets a bulk batch mint 500
distinct keys. Ed25519 signatures are deterministic, so without `n` a bulk batch
of identical claims would produce one byte-identical key.

**Lost keys are unrecoverable by design.** Only `sha256(key)` is stored. If a
customer loses their key you cannot look it up — issue a replacement. The
"shown once" page and CLI stdout are the only times the raw key exists.

**Seat accounting races.** The "count active activations, then insert" seat
check is not atomic: two installs validating simultaneously against the *last*
free seat of a key can both pass the count, then both insert, temporarily
over-subscribing. SQLite's `UNIQUE(license_id, server_id)` still guarantees one
activation per install. Seat limits are best-effort enforcement, not a hard
mutex — the admin can release seats from the license detail page.

**Cloned installs share a seat.** `server_id` is derived from `data/chat.db` +
site name + a random value and stored in `server_config`. Cloning the whole
`data/` folder to a second server yields the **same** `server_id`, so both count
as one activation. Deleting `data/` regenerates the id → a fresh activation
(which can exhaust seats). Keep `data/` backed up.

**Grace exhaustion is sticky.** Once a never-confirmed key trips
`unreachable_grace`, the module stays refused on subsequent requests — the only
ways out are a successful server round-trip (connectivity returns) or a new key.
`license_grace_days = 0` refuses on the very first unreachable check.

**Cache vs. re-check.** A `valid` status is trusted for `license_recheck_hours`
(default 24) without dialing out. Saving a **different** key via Admin → Modules
clears the cached status so the next check re-validates. `re-check` forces a
validation immediately (used by the admin UI).

**Leaked keys / seat squatting.** Anyone holding a raw key can activate it on
their own servers up to `act_max`. Mitigations: treat keys as secrets, keep
`act_max` meaningful, watch the activation list, and on a leak **revoke** the key
(installs in `grace` keep running until they next reach the server, then stop).

**Rate limiting.** `validate` is limited to `rate_limit` (default 60) calls per
IP per minute; excess returns HTTP 429 `rate_limited`. Bulk-issuing 500 keys in
one batch is a single admin action and is not subject to the validate limit.

**Environment override.** `LVC_LICENSE_PUBLIC_KEY` overrides the embedded
`PUBLIC_KEY` constant. Only the License Server bootstrap and the test suites
use it — do not set it on production installs (it would let an attacker point
offline verification at a keypair of their choosing).

## Operations runbook (License Server operator)

### One-time setup

1. `cp config.sample.php config.php`.
2. `php bin/gen-seed.php` → paste the **LICENSE SEED** into `license_ed25519_seed`.
3. Set an admin password (`admin_pass` or a `password_hash()` in `admin_pass_hash`).
4. Add every product you sell under `"modules"` — the **id must match** the
   module directory name on chat installs (e.g. `premium-badges`).
5. Embed the **PUBLIC KEY** from `bin/gen-seed.php` into the chat app's
   `src/services/LicenseKeys.php` `PUBLIC_KEY` constant and ship that build.
6. Deploy: point a web root at `public/` (Apache `.htaccess` included), or
   `php -S 0.0.0.0:8090 public/index.php`. The SQLite DB is auto-created at
   `data/licenses.db`.

### Issuing keys

Web UI (`/admin` → **Issue keys**) or CLI:

```bash
# one key, marked issued immediately (holder set, qty 1)
php bin/issue.php --module premium-badges --type pro --holder "Acme Corp" --exp 2027-12-31 --seats 3

# 50 unissued pool keys (hand out later; shown/downloaded once, hash-only stored)
php bin/issue.php --module premium-badges --bulk 50 --csv batch1.txt

# a forever key (no expiry, unlimited seats, many customers can activate it)
php bin/issue.php --module premium-badges --forever --holder "Lifetime customer"
```

CLI options: `--module` (required), `--type`, `--holder`, `--exp YYYY-MM-DD`,
`--seats N` (0/absent = unlimited), `--forever`, `--bulk N` (1–500), `--csv
file` (appends the raw keys), `--notes`.

### Activating a customer install

1. The customer sets **Admin → Settings → Licensing → License server URL** to
   your server (`https://licenses.example.com`) and saves.
2. You send them a key (from the unissued pool or a freshly issued one).
3. They paste it under **Admin → Modules → their module → License key → Save**,
   then **re-check**. The badge shows `license valid` once your server confirms
   it and registers their `server_id` against the key (a seat is consumed when
   `act_max` is set).

### Managing activations & seats

`/admin/license/<id>` lists every activation (server id, host, IP, last seen).
**Release seat** frees one, so another server can take its place. Keys with
`act_max = NULL` (forever keys) never refuse on seat count.

### Revocation & deactivation

- **Revoke** (license detail page) — the key stops validating at the next server
  round-trip. Install in `grace` keeps running until reachable again.
- **Deactivate** flips the key `active = 0` — same effect as revoke.
- **Release seat** / `POST /api/licenses/deactivate` — frees one server's slot.

### Backups

`data/licenses.db` holds your key hashes, issuance record, and activations.
Back it up — it cannot be rebuilt from anywhere (raw keys are gone after
issuance). Install-side state (which key each install has) lives in that
install's `data/chat.db`.

### Troubleshooting (chat install Admin → Modules badge)

| Badge | Meaning | Fix |
|---|---|---|
| `no license key` | Paid module, nothing saved yet | Paste a key and re-check |
| `license valid` | Offline sig + (recently) server-confirmed | — |
| `invalid: bad_signature` / `malformed` | Key not genuine / mangled | Re-type the key; the public key in `LicenseKeys.php` may not match your seed |
| `invalid: wrong_module` | Key is for a different module | Issue a key for *this* module id |
| `invalid: expired` | Past its `exp` | Issue a new key |
| `server refused` | Server reachable but said no (revoked/inactive/unknown/seat-full) | Check the license detail page |
| `license not checked` (`unvalidated`) | Never confirmed, inside the grace window | Wait for connectivity; check `license_url` |
| `offline refused` / `grace expired` (`unreachable_strict` / `unreachable_grace`) | Server unreachable and policy refuses | Restore connectivity or relax the policy |

**Why is my key `server refused` when it's brand new?** The dashboard only shows
`unissued` pool keys; a key marked issued is fine. Check the license detail page:
`revoked`, `inactive`, `expired`, or `activation_limit` (all seats in use) all
return `server refused` to the install.

## Wiring in the chat app

- `modules/<id>/module.json` with `"license": true` marks a paid module.
- At boot, `ModuleLoader` runs `LicensingService::validate()` for paid modules
  and records the status.
- Modules gate their features with `ModuleLoader::isLicensed($id)` and read the
  raw key with `ModuleLoader::license($id)`.
- Admins set the key and see the status badge under **Admin → Modules**
  (re-check forces a fresh external validation). The License Server URL and
  policy live under **Admin → Settings → Licensing**.

## Testing

Both layers are covered by the automated suites (`bash bin/test.sh`); nothing
reaches a real License Server.

| Behavior | Where |
|---|---|
| Key format, base32 round-trip, batch uniqueness (nonce) | `tests/smoke.php` (`== license keys ==`) |
| Offline Ed25519 verification: valid / tampered payload / tampered signature / forged key (wrong signer) / wrong module / expired / forever / malformed / unsupported version | `tests/smoke.php` |
| Paid-module boot integration: `no_key` status recorded, `isLicensed()` gating, feature command blocked then active once a key is stored | `tests/smoke.php` (`== licensing client ==`, fixture `paid-mod`) |
| Client policy: offline (internal-only), grace (first-check window, no repeat dial, never-confirmed expiry → `unreachable_grace`, last-known-good keeps running), strict (refuse when unreachable) — all against a closed port | `tests/smoke.php` |
| Server round-trip: settings `license_url`, save key + `re-check` → `license valid` badge; a server-refused key (fixture returns `revoked`) → `server refused`; malformed key fails offline with no network | `tests/http_test.php` (`== licensing client ==`, fixture `tests/fixtures/license_server.php` on `:8096`) |
| `module_save` clears the cached status when the key changes | `tests/http_test.php` |

The suites use a scratch Ed25519 keypair: `tests/smoke.php` and
`tests/http_test.php` generate one, pass the public half to the app under test
via `LVC_LICENSE_PUBLIC_KEY`, and the fixture license server in `http_test`
mimics `/api/licenses/validate` (accepts validly-signed keys, refuses a
`holder` claim of `REFUSE`). The full License Server app itself ships its own
`README.md` with a manual verification flow.

> Setting `LVC_LICENSE_PUBLIC_KEY` for a fixture is how tests verify without the
> real vendor key. Production installs must use the embedded `PUBLIC_KEY`.

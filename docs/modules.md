# LVChat Module System

Modules are optional, self-contained feature packs that plug into LVChat without
touching the core. They can register slash commands, web routes, admin pages,
config settings, their own database tables, static assets, and views. This is
how LVChat ships paid plugins: a module declares that it requires a license, and
the licensing layer (see `docs/protocol/licensing.md`) gates it.

**Licensing rule of thumb:** modules shipped in this repository are
AGPL-3.0-only like the core (e.g. `webrtc`). Modules distributed separately may
be licensed under their own terms — including proprietary/commercial — provided
they are independent works (no copied core source, public API only). See
`docs/protocol/licensing.md` for the full rules.

## Directory layout

Every module lives in its own directory under `modules/` at the project root:

```
modules/
  <module-id>/
    module.json      required — manifest (validated at boot)
    init.php         optional — boot hook (runs on every request)
    routes.php       optional — registers web routes
    schema.php       optional — idempotent database migrations
    assets/          optional — static files served at /modules/<id>/assets/…
    views/           optional — module templates (rendered via ModuleLoader::view)
    …                any other PHP files the module requires
  <module-id>.disabled/   hard-disabled — ignored at boot (state preserved in DB)
```

The `modules/` directory ships empty (`.gitkeep`). **If it is empty, missing, or
contains nothing but dotfiles, the loader is a silent no-op.**

## Enable / disable

| Mechanism | Effect |
|---|---|
| `modules/<id>.disabled/` (rename) | **Hard off.** The module is never loaded. Its `modules` DB row is kept so license key + enabled state survive the rename. |
| `modules` DB row `enabled = 0` (Admin → Modules) | **Soft off.** Directory is still scanned and validated, but `init.php`/`routes.php`/`schema.php` never run. |
| Remove the directory | The DB row is pruned on the next boot. |
| Invalid `module.json` | Skipped with a warning shown on Admin → Modules (never fatal). |
| Unsatisfied `requires` | Skipped with a warning (never fatal). |

## Manifest (`module.json`)

```json
{
  "id": "premium-badges",
  "name": "Premium Badges",
  "version": "1.0.0",
  "description": "Custom animated badges on profiles.",
  "author": "Acme Plugins",

  "requires": {
    "lvchat": ">=1.7.1",
    "php": ">=8.1",
    "modules": ["shared-core"]
  },

  "order": 0,
  "admin": [
    { "id": "badges", "label": "Badges", "url": "/admin/badges", "roles": ["admin"] }
  ],
  "settings": {
    "badges_enabled": "1",
    "badges_max": "3"
  },
  "assets": {
    "css": ["css/badges.css"],
    "js": ["js/badges.js"]
  },
  "license": false
}
```

| Field | Type | Required | Meaning |
|---|---|---|---|
| `id` | string | yes | Lowercase `[a-z0-9-_]`, **must equal the directory name**. Used for DB rows, asset URLs, and license keys. |
| `name` | string | yes | Human-readable name. |
| `version` | string | yes | Module version (e.g. `1.0.0`). |
| `description` | string | no | One-line summary. |
| `author` | string | no | Who wrote the module (provenance for the module's own distribution license, if any). |
| `requires.lvchat` | string | no | Minimum LVChat version with an operator: `>=1.7.1`, `>=8.1`, etc. |
| `requires.php` | string | no | Minimum PHP version with an operator. |
| `requires.modules` | array | no | Module ids that must be present **and enabled** first. |
| `order` | int | no | Load order (lower first, then id). Default `0`. |
| `admin` | array | no | Extra **Admin →** nav entries: `{id, label, url, roles}`. `roles` = `["admin"]` (default) and/or `"staff"`. |
| `settings` | object | no | Config keys seeded into `server_config` on boot. Existing admin-set values are **never** overwritten. |
| `assets.css` / `assets.js` | array | no | Module stylesheets/scripts to load on the chat app page, resolved as `/modules/<id>/assets/…`. |
| `license` | bool | no | When `true`, the module is a paid plugin: its features are gated by a license key (see `docs/protocol/licensing.md`). **`license` is reserved for this flag — do not use it for provenance.** |

> **Secrets:** `settings` is plaintext in `module.json` and lives in a source
> tree — never put API keys, passwords, or secrets there. Seed the key as `''`
> (or omit it) and have the module's admin page write the real value with
> `config_set()` on save. The write-only pattern (`config_set` on non-empty
> POST, show "•••" when set) mirrors the core SMTP password handling.

`name`, `version`, and a valid `id` are mandatory; anything else missing simply
has no effect.

**Version constraints** (`requires.lvchat`, `requires.php`) accept a leading
operator — `>=`, `>`, `<=`, `<`, `==`/`=`, `~` (next minor), `^` (next major) —
or a bare version, which means `>=`. Examples: `>=1.7.1`, `^8.1` (PHP),
`~1.2`. An unparseable constraint is treated as unmet (module skipped with a
warning).

## Boot lifecycle

`ModuleLoader::boot()` runs from `bootstrap.php` **after** the core requires and
`Database::init()`, so `Database`, `config_get`/`config_set`, and every core
service are available to module code. It runs on **every** request — including
CLI scripts and the Workerman realtime daemon, which both `require` bootstrap.

Per enabled module, in `order` then `id` sequence:

1. Validate the manifest (`id` matches the directory name, `name`/`version` present).
2. Check `requires` (LVChat version, PHP version, dependency modules enabled).
3. Run `schema.php` if present (see below).
4. Seed `settings` into `server_config` (`INSERT OR IGNORE`).
5. `require` `init.php` if present.
6. Record the module version + `updated_at` in the `modules` table.

**`init.php` must be side-effect-free at the HTTP/CLI level**: it may register
commands, require its own PHP files, and build static state — it must **not**
emit output, set headers, or call `exit`. It runs in the daemon too.

### `schema.php`

Optional. Must `return` a callable that takes the PDO instance and applies
**idempotent** migrations:

```php
<?php
return static function (PDO $pdo): void {
    $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('badges', $tables, true)) {
        $pdo->exec('CREATE TABLE badges (…);');
    }
};
```

Modules own their tables; the core never drops or migrates them. Prefer
`CREATE TABLE IF NOT EXISTS` / column-presence checks, mirroring the style in
`src/Database.php`.

### `init.php`

Runs after the module's schema + settings. Typical usage:

```php
<?php
require __DIR__ . '/BadgesService.php';
require __DIR__ . '/BadgesController.php';

CommandRegistry::register('badge', [
    'group' => 'Plugins',
    'desc' => 'Assign a badge.',
    'usage' => '/badge <nick> <badge>',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => [BadgesService::assign($args[0] ?? '', $args[1] ?? '')]];
    },
]);
```

### `routes.php`

Optional. Must `return` a callable that receives the `Router` and registers
routes. Called from `public/index.php` after the core routes, **before**
`$router->dispatch()` — core routes always win on a path collision.

```php
<?php
return static function (Router $router): void {
    $router->get('/admin/badges', [BadgesController::class, 'admin']);
    $router->post('/api/badges/assign', [BadgesController::class, 'assign']);
};
```

## Extension surfaces

| Surface | How |
|---|---|
| Slash commands | `CommandRegistry::register()` from `init.php` (auto-appears in `/help`). |
| Web routes | `routes.php` returning a `function (Router $router)`. |
| Admin nav | `admin` entries in the manifest. |
| Config | `settings` in the manifest → `server_config`; read with `config_get()`, write with `config_set()`. |
| Database | `schema.php` (idempotent, module-owned tables). |
| Static assets | `assets/` dir → served at `/modules/<id>/assets/<path>` (see below). |
| Views | `ModuleLoader::view($id, 'name', $vars)` renders `modules/<id>/views/name.php` inside the standard layout. |
| Licensing | `license: true` + the licensing layer (`docs/protocol/licensing.md`). |

### Module API (`src/ModuleLoader.php`)

```php
ModuleLoader::all()             // array of enabled manifests, id => manifest
ModuleLoader::get($id)          // one enabled manifest, or null
ModuleLoader::enabled()         // array of enabled module ids
ModuleLoader::warnings()        // boot warnings (invalid manifests, unmet requires)
ModuleLoader::adminNav()        // manifest `admin` entries for the admin sidebar
ModuleLoader::view($id, $view, $vars, $layout = 'layout')
ModuleLoader::license($id)      // stored license key string, or ''
ModuleLoader::isLicensed($id)   // paid modules: whether a valid license is present
```

### Static assets

`GET /modules/<id>/assets/<path>` serves files from `modules/<id>/assets/`. It
is traversal-safe (`realpath` must stay inside the module's `assets/` dir), only
serves **enabled** modules, whitelists content types, and sets a short
`Cache-Control`. Module `assets` manifest entries are injected into the chat
app's `<head>` automatically.

### Security model

- Module PHP runs with the same privileges as the core app — **only install
  modules you trust** (same policy as any CMS plugin).
- Module code is sandboxed from nothing: never point it at untrusted inputs
  without validating them exactly as core code does (prepared statements, `h()`
  on output, `Csrf::verify()` on POSTs).
- Asset serving is confined to the module's `assets/` directory.
- `schema.php` runs only when the module's `requires` checks pass.

## Edge cases & common pitfalls

**Manifest / discovery**

- `id` must equal the directory name and match `[a-z0-9][a-z0-9-_]*`. A mismatch
  (e.g. `modules/MyModule/` with `"id": "mymodule"`), a missing `name` or
  `version`, or invalid JSON all skip the module with a warning — never a fatal.
- Directory names starting with `.` (`.gitkeep`, `.DS_Store`) are ignored, not
  treated as modules.
- A directory that is not a directory (a stray file like `modules/readme.txt`)
  is ignored.
- `name`/`version`/`id` are the only mandatory manifest fields; everything else
  missing is simply inert.

**Enable / disable**

- **Hard beats soft:** a `<id>.disabled` rename wins over the DB `enabled = 1`
  flag — the module never loads until the directory is renamed back.
- Renaming to `.disabled` keeps the DB row (license key + enabled flag intact);
  re-enabling is just renaming back. Deleting the directory entirely prunes the
  row on the next boot.
- Soft-disabling in Admin takes effect on the **next request/boot**, not
  mid-request. In the long-lived Workerman daemon, a module loaded at start stays
  loaded until the daemon restarts — toggling only affects new HTTP processes.
- The Admin → Modules "not on disk" state means the directory is gone entirely
  (distinct from "disabled (.disabled)"/"disabled").

**Boot**

- `init.php` runs on **every** request and in the CLI/daemon. It must be
  idempotent and side-effect-free at the HTTP level: register commands/classes
  only, never `echo`, `header`, or `exit`.
- Prefer `require_once` (or guard with `class_exists`) when `init.php` pulls in
  module classes — tests re-boot the loader in one process and a plain `require`
  would redeclare the class. The fixture module shows the guard pattern.
- If a module's `schema.php`, `init.php`, or license validation **throws**, the
  loader catches it, records a **boot warning**, and continues — a broken module
  never takes down a request or the realtime daemon. Check Admin → Modules for
  the warning.
- A module whose `routes.php` throws is likewise skipped with a warning; core
  routes and other modules are unaffected.

**Settings & schema**

- Manifest `settings` are seeded with `INSERT OR IGNORE` — an admin-set value is
  **never overwritten** at boot. Changing a default in a new module version has
  no effect on installs that already saved a value.
- `settings` is plaintext and version-controlled — never store secrets there.
- `schema.php` must be idempotent (`CREATE TABLE IF NOT EXISTS` /
  column-presence checks). It re-runs on every boot, so non-idempotent DDL
  (plain `CREATE TABLE`, unconditional `ALTER TABLE ADD COLUMN`) throws on the
  second request and the module is boot-failed with a warning.

**Routes & assets**

- Core routes are registered **before** module routes and always win on a path
  collision. A module can't shadow `/`, `/login`, `/api/send`, etc.
- Route parameters: `{name}` is one non-slash segment; `{path...}` is a
  catch-all. `/modules/{name}/assets/{path...}` is reserved for the asset
  pipeline — don't register your own routes under `/modules/`.
- Assets are only served for **enabled** modules; `.disabled` or unknown modules
  return 404. `..` traversal (including URL-encoded `%2e%2e`) is blocked by the
  `realpath` guard. Only known extensions get a MIME type; everything else is
  served as `application/octet-stream` with `X-Content-Type-Options: nosniff`.
- Module views must exist or `ModuleLoader::view()` 404s with a plain message —
  it never falls back to a core view.

**Dependencies & ordering**

- `requires.modules` must list modules that are present **and enabled**. If a
  dependency is soft-disabled, the dependent is skipped with a warning.
- Load order is `order` ascending, then `id` (case-insensitive) — deterministic
  across every install. Dependencies with a lower `order` load first.
- A dependency cycle cannot be detected and simply won't resolve (`A` requires
  `B`, `B` requires `A` → both skipped). Keep the graph acyclic.

**Licensing**

- `license` in the manifest is the **paid flag** (bool). Don't reuse it for the
  module's own open-source provenance (put that in `author` or a `LICENSE` file).
- A paid module with `"license": true` but no stored key shows `no license key`
  on Admin → Modules and `ModuleLoader::isLicensed()` returns `false` — the
  module still boots (its status is recorded), it just must not enable gated
  features.
- `isLicensed()` reads the **stored** key + status, not whatever you passed to a
  transient `LicensingService::validate()` call — persist the key first.
- Saving a new key resets the cached validation status so the next check
  re-validates against the license server.

## Building a module from scratch

A complete example — a paid "premium-badges" module (no affiliation with any
real product). Directory:

```
modules/premium-badges/
  module.json
  schema.php
  init.php
  routes.php
  BadgesService.php
  BadgesController.php
  assets/css/badges.css
  views/admin.php
```

**`module.json`** — a paid plugin with one admin page, one setting, and an asset:

```json
{
  "id": "premium-badges",
  "name": "Premium Badges",
  "version": "1.0.0",
  "description": "Custom badges on profiles.",
  "author": "Acme Plugins",
  "requires": { "lvchat": ">=1.7.1", "php": ">=8.1" },
  "order": 0,
  "admin": [
    { "id": "badges", "label": "Badges", "url": "/admin/badges", "roles": ["admin"] }
  ],
  "settings": { "badges_enabled": "1", "badges_max": "3" },
  "assets": { "css": ["css/badges.css"] },
  "license": true
}
```

**`schema.php`** — one module-owned table, idempotent:

```php
<?php
return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS badges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        label TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
};
```

**`BadgesService.php`** — plain static class, same style as core services:

```php
<?php
final class BadgesService
{
    public static function assign(int $userId, string $label): string
    {
        Database::query('INSERT INTO badges (user_id, label) VALUES (?, ?)', [$userId, mb_substr($label, 0, 32)]);
        return "Badge '$label' assigned.";
    }
}
```

**`init.php`** — registers a slash command and guards against re-boot:

```php
<?php
if (!class_exists('BadgesService')) {
    require __DIR__ . '/BadgesService.php';
}
require_once __DIR__ . '/BadgesController.php';

CommandRegistry::register('badge', [
    'group' => 'Plugins',
    'desc' => 'Assign a badge (paid feature).',
    'usage' => '/badge <nick> <label>',
    'run' => function (array $args, array $user, ?array $channel) {
        if (!ModuleLoader::isLicensed('premium-badges')) {
            return ['replies' => ['This feature requires a premium-badges license key (Admin → Modules).']];
        }
        $target = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$args[0] ?? '']);
        if (!$target) {
            return ['replies' => ['No such user.']];
        }
        return ['replies' => [BadgesService::assign((int) $target['id'], $args[1] ?? '')]];
    },
]);
```

**`routes.php`** — the admin page + an API endpoint:

```php
<?php
return static function (Router $router): void {
    $router->get('/admin/badges', [BadgesController::class, 'admin']);
    $router->post('/api/badges/assign', [BadgesController::class, 'assign']);
};
```

**`BadgesController.php`** — every POST verifies CSRF; the admin page renders
through `ModuleLoader::view()`:

```php
<?php
final class BadgesController
{
    public static function admin(array $params): void
    {
        $admin = Auth::requireAdmin();
        ModuleLoader::view('premium-badges', 'admin', ['admin' => $admin, 'licensed' => ModuleLoader::isLicensed('premium-badges')]);
    }

    public static function assign(array $params): void
    {
        Csrf::verify();
        if (!ModuleLoader::isLicensed('premium-badges')) {
            json_out(['ok' => false, 'error' => 'License required.'], 403);
        }
        $u = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [(string) ($_POST['nick'] ?? '')]);
        if (!$u) {
            json_out(['ok' => false, 'error' => 'No such user.']);
        }
        json_out(['ok' => true, 'message' => BadgesService::assign((int) $u['id'], (string) ($_POST['label'] ?? ''))]);
    }
}
```

**`views/admin.php`** — rendered inside the standard layout by `ModuleLoader::view`:

```php
<?php $title = 'Badges'; $active = 'badges'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Badges</h1>
  <span class="<?= $licensed ? 'text-green-400' : 'text-red-400' ?> text-sm">
    <?= $licensed ? 'licensed' : 'no license key' ?>
  </span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<!-- module UI … -->
```

**`assets/css/badges.css`** — any CSS; loaded on the chat app page automatically.

Install the module by dropping `premium-badges/` into `modules/`; it appears on
Admin → Modules, where you paste the license key issued by your license server.
All the fixture modules in `tests/fixtures/modules/` follow exactly this shape.

## Admin → Modules

Lists every discovered module: id, name, version, `order`, requires status
(`requires`/OK/warning), load state (running/disabled-by-rename/disabled-in-DB),
license key field (with its validation badge), and boot warnings. Actions:

- **Enable / Disable** — toggles the `modules.enabled` flag (soft off; applies next request).
- **Save license key** — stores the key for paid modules (a changed key clears any cached validation result).
- **Re-check** — re-runs the license validation for a paid module immediately (`LicensingService::validate`, see `docs/protocol/licensing.md`).

The page also reports the modules directory in use (`ModuleLoader::dir()`,
overridable via the `CHAT_MODULES` environment variable — used by tests).

## Testing the module system

The loader and its extension surfaces are covered by the automated suites in
`tests/` (run with `bash bin/test.sh`). Tests never touch a live installation:
they boot the loader against **fixture modules** (`tests/fixtures/modules/`)
via the `CHAT_MODULES` env var, on a scratch database.

| Behavior | Where it is asserted |
|---|---|
| Discovery, manifest validation, `name`/`version`/`id` required, `id` must equal dir name | `tests/smoke.php` (modules + lifecycle sections) |
| Invalid/missing `module.json` is skipped with a warning (never fatal) | `tests/smoke.php` |
| `requires` gating — LVChat version, PHP version, module-to-module dependencies (`requires.modules`) | `tests/smoke.php` (incl. a temp `dep-parent`/`dep-child` pair) |
| Settings seeded (`INSERT OR IGNORE`), never overwriting admin-set values | `tests/smoke.php` |
| Idempotent `schema.php` migrations + `init.php` boot hook (commands, classes) | `tests/smoke.php` |
| Soft disable (DB `enabled = 0`) unloads a module at the next boot | `tests/smoke.php` + `tests/http_test.php` (route/asset/view go 404, return 200 on re-enable) |
| **Hard disable via `<id>.disabled` rename** — never loaded, DB row + license + enabled flag preserved, reload on rename back, hard-over-soft precedence | `tests/smoke.php` (rename cycle on a temp copy) + `tests/http_test.php` (`cycle-mod` rename cycle over HTTP) |
| `ModuleLoader::dirExists()` (active and `.disabled` forms) | `tests/smoke.php` |
| Pruning: removing a module directory deletes its DB row | `tests/smoke.php` |
| Empty / missing / dotfiles-only `modules/` dir is a silent no-op | `tests/smoke.php` |
| Module web routes + static assets (MIME types, traversal / unknown-module / `.disabled` 404s) | `tests/http_test.php` |
| `ModuleLoader::view()` renders a module view inside the standard layout | `tests/http_test.php` (`/admin/good-module` fixture route) |
| Admin → Modules page (state badges incl. `disabled (.disabled)`, boot warnings, enable/disable, license save/clear) | `tests/http_test.php` |
| Licensing gate + key validation — Ed25519 offline signature/claims, license-server round-trip, `no_key`/`expired`/`wrong_module`/server-refused states, feature gating via `isLicensed()`, re-check action | `tests/smoke.php` (`== license keys ==`, fixture `paid-mod`) + `tests/http_test.php` (`== licensing client ==`, fixture license server on `:8096`) |
| The shipped `webrtc` module (admin config, LiveKit join/leave, calls, meetings) | `tests/http_test.php` (fixture is a symlink to `modules/webrtc`) |
| A module whose `init.php`/`schema.php` throws is caught with a boot warning — never fatal, the rest of the app keeps working | `tests/smoke.php` (`broken-mod` fixture) |

**Adding tests for a new module or a loader change:** drop the fixture under
`tests/fixtures/modules/` (or build a throwaway copy in `sys_get_temp_dir()`
when you need to mutate it, as the lifecycle tests do), point the suite at it
with `CHAT_MODULES`, and assert the behavior in `tests/smoke.php` (loader level)
and/or `tests/http_test.php` (over HTTP). Run `bash bin/test.sh` after changes.

## Packaging & deployment

- `modules/` ships with the app folder like `src/`; `bin/deploy.sh` needs no
  changes. Module state (enabled flag, license keys) lives in `data/chat.db`,
  so re-uploading the app folder preserves activation state.
- To sell a module: set `"license": true`, point `license_url` at your license
  server (Admin → Settings), and gate features with `ModuleLoader::isLicensed()`.
  See `docs/protocol/licensing.md` for the full key/validation protocol.

# LVChat Module System

Modules are optional, self-contained feature packs that plug into LVChat without
touching the core. They can register slash commands, web routes, admin pages,
config settings, their own database tables, static assets, and views. This is
how LVChat ships paid plugins: a module declares that it requires a license, and
the licensing layer (see `docs/licensing.md`) gates it.

**Licensing rule of thumb:** modules shipped in this repository are
AGPL-3.0-only like the core (e.g. `webrtc`). Modules distributed separately may
be licensed under their own terms — including proprietary/commercial — provided
they are independent works (no copied core source, public API only). See
`docs/licensing.md` for the full rules.

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
  "license": "proprietary",

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
| `author` / `license` | string | no | Provenance + license of the module itself (SPDX id, e.g. `AGPL-3.0-only`). In-repo modules are `AGPL-3.0-only`; separately-distributed modules may carry any license. |
| `requires.lvchat` | string | no | Minimum LVChat version with an operator: `>=1.7.1`, `>=8.1`, etc. |
| `requires.php` | string | no | Minimum PHP version with an operator. |
| `requires.modules` | array | no | Module ids that must be present **and enabled** first. |
| `order` | int | no | Load order (lower first, then id). Default `0`. |
| `admin` | array | no | Extra **Admin →** nav entries: `{id, label, url, roles}`. `roles` = `["admin"]` (default) and/or `"staff"`. |
| `settings` | object | no | Config keys seeded into `server_config` on boot. Existing admin-set values are **never** overwritten. |

> **Secrets:** `settings` is plaintext in `module.json` and lives in a source
> tree — never put API keys, passwords, or secrets there. Seed the key as `''`
> (or omit it) and have the module's admin page write the real value with
> `config_set()` on save. The write-only pattern (`config_set` on non-empty
> POST, show "•••" when set) mirrors the core SMTP password handling.
| `assets.css` / `assets.js` | array | no | Module stylesheets/scripts to load on the chat app page, resolved as `/modules/<id>/assets/…`. |
| `license` | bool | no | When `true`, the module is a paid plugin: its features are gated by a license key (see `docs/licensing.md`). |

`name`, `version`, and a valid `id` are mandatory; anything else missing simply
has no effect.

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
| Licensing | `license: true` + the licensing layer (`docs/licensing.md`). |

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

## Admin → Modules

Lists every discovered module: id, name, version, `order`, requires status
(`requires`/OK/warning), load state (running/disabled-by-rename/disabled-in-DB),
license key field, and boot warnings. Actions:

- **Enable / Disable** — toggles the `modules.enabled` flag (soft off; applies next request).
- **Save license key** — stores the key for paid modules.
- **Re-check** — re-runs the boot-time validation for a module.

The page also reports the modules directory in use (`ModuleLoader::dir()`,
overridable via the `CHAT_MODULES` environment variable — used by tests).

## Packaging & deployment

- `modules/` ships with the app folder like `src/`; `bin/deploy.sh` needs no
  changes. Module state (enabled flag, license keys) lives in `data/chat.db`,
  so re-uploading the app folder preserves activation state.
- To sell a module: set `"license": true`, point `license_url` at your license
  server (Admin → Settings), and gate features with `ModuleLoader::isLicensed()`.
  See `docs/licensing.md` for the full key/validation protocol.

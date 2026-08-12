<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

/**
 * ModuleLoader — discovers, validates, and boots modules from the modules/
 * directory (see docs/modules.md for the full spec).
 *
 * Discovery rules:
 *   - A missing, empty, or dotfiles-only modules/ directory is a silent no-op.
 *   - Subdirectories are modules; dotfiles and non-directories are ignored.
 *   - A directory whose name ends with ".disabled" is never loaded, but its
 *     modules DB row is preserved so license/enable state survives the rename.
 *   - A directory without a valid module.json (missing/invalid id/name/version)
 *     is skipped with a warning — never fatal.
 *   - A module whose `requires` are not satisfied is skipped with a warning.
 *
 * boot() runs after Database::init() from bootstrap.php, so it also runs inside
 * the Workerman realtime daemon and CLI scripts. Module init.php files must be
 * side-effect-free (no output, headers, or exit).
 */
final class ModuleLoader
{
    private const DEFAULT_DIR = ROOT . '/modules';

    /** id => manifest (enabled + loadable modules only). */
    private static ?array $modules = null;

    /** @var string[] */
    private static array $warnings = [];

    private static bool $booted = false;

    public static function dir(): string
    {
        $env = getenv('CHAT_MODULES');
        return is_string($env) && $env !== '' ? $env : self::DEFAULT_DIR;
    }

    /** Reset loader state so a test can boot against another directory. */
    public static function reset(): void
    {
        self::$modules = null;
        self::$warnings = [];
        self::$booted = false;
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        // 1. Scan + validate every subdirectory (enabled and .disabled alike).
        $scanned = []; // id => ['dirName', 'disabled', 'manifest', 'error']
        $ids = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..' || $e === '' || $e[0] === '.') {
                continue;
            }
            $full = $dir . '/' . $e;
            if (!is_dir($full)) {
                continue;
            }
            $disabled = str_ends_with($e, '.disabled');
            $id = $disabled ? substr($e, 0, -strlen('.disabled')) : $e;
            $found = self::loadManifest($full, $id);
            $scanned[$id] = [
                'dirName' => $e,
                'disabled' => $disabled,
                'manifest' => $found['manifest'],
                'error' => $found['error'],
            ];
            $ids[] = $id;
            if ($found['error'] !== null) {
                self::$warnings[] = "Module directory '$e': " . $found['error'];
            }
        }

        // 2. Keep the modules table in sync (upsert scanned, prune removed).
        self::syncRows($scanned, $ids);

        // 3. Select loadable modules: not disabled, enabled in DB, valid manifest,
        //    and with `requires` satisfied (dependency checks need the full set,
        //    so build candidates first, then filter).
        $candidates = [];
        foreach ($scanned as $id => $info) {
            if ($info['disabled'] || $info['manifest'] === null) {
                continue;
            }
            $row = Database::row('SELECT enabled FROM modules WHERE id = ?', [$id]);
            if (!$row || (int) $row['enabled'] !== 1) {
                continue;
            }
            $candidates[$id] = $info['manifest'];
        }
        uasort($candidates, static function (array $a, array $b): int {
            return [($a['order'] ?? 0), (string) ($a['id'] ?? '')]
                <=> [($b['order'] ?? 0), (string) ($b['id'] ?? '')];
        });
        $loaded = [];
        foreach ($candidates as $id => $manifest) {
            [$ok, $err] = self::checkRequires($id, $manifest, array_keys($candidates));
            if (!$ok) {
                self::$warnings[] = "Module '$id' skipped: $err";
                continue;
            }
            $loaded[$id] = $manifest;
        }
        self::$modules = $loaded;

        // 4. Boot each module in order.
        foreach ($loaded as $id => $manifest) {
            self::runModule($id, $manifest, $dir . '/' . $id);
        }
    }

    /** @return array<string, array> id => manifest of enabled + loadable modules. */
    public static function all(): array
    {
        self::boot();
        return self::$modules ?? [];
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** Whether the module's directory exists in any form (`<id>` or `<id>.disabled`). */
    public static function dirExists(string $id): bool
    {
        $dir = self::dir();
        return is_dir($dir . '/' . $id) || is_dir($dir . '/' . $id . '.disabled');
    }

    /** @return string[] ids of every enabled + loadable module. */
    public static function enabled(): array
    {
        return array_keys(self::all());
    }

    /** @return string[] boot warnings (invalid manifests, unmet requires). */
    public static function warnings(): array
    {
        self::boot();
        return self::$warnings;
    }

    /** Manifest `admin` entries for the admin sidebar, keyed by entry id. */
    public static function adminNav(): array
    {
        $items = [];
        foreach (self::all() as $id => $manifest) {
            foreach ((array) ($manifest['admin'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $eid = (string) ($entry['id'] ?? '');
                if ($eid === '') {
                    continue;
                }
                $items[$eid] = [
                    'label' => (string) ($entry['label'] ?? $manifest['name'] ?? $id),
                    'url' => (string) ($entry['url'] ?? '/admin'),
                    'roles' => array_map('strval', (array) ($entry['roles'] ?? ['admin'])),
                ];
            }
        }
        return $items;
    }

    /** Stored license key for a module, or ''. */
    public static function license(string $id): string
    {
        $row = Database::row('SELECT license FROM modules WHERE id = ?', [$id]);
        return $row ? (string) $row['license'] : '';
    }

    /** License validation state for a module (see docs/protocol/licensing.md). */
    public static function licenseStatus(string $id): array
    {
        $row = Database::row(
            'SELECT license, license_status, license_checked_at, license_expires_at FROM modules WHERE id = ?',
            [$id]
        );
        return $row ?: [];
    }

    /** Whether a module may run. Free modules are always licensed; paid modules
     *  need a stored key that has not failed validation. */
    public static function isLicensed(string $id): bool
    {
        $row = Database::row('SELECT license, license_status FROM modules WHERE id = ?', [$id]);
        if (!$row) {
            return false;
        }
        $manifest = self::get($id);
        if ($manifest && !empty($manifest['license'])) {
            return (string) $row['license'] !== ''
                && !in_array((string) $row['license_status'], LicensingService::INVALID_STATUSES, true);
        }
        return true;
    }

    /** Wire each enabled module's routes.php into the router. Called from
     *  public/index.php before dispatch; core routes always win on collisions.
     *  A module whose routes.php throws is skipped (recorded as a warning). */
    public static function wireRoutes(Router $router): void
    {
        foreach (self::all() as $id => $manifest) {
            $routes = self::dir() . '/' . $id . '/routes.php';
            if (!is_file($routes)) {
                continue;
            }
            try {
                $fn = require $routes;
                if (is_callable($fn)) {
                    $fn($router);
                }
            } catch (Throwable $e) {
                self::$warnings[] = "Module '$id' routes failed: " . $e->getMessage();
            }
        }
    }

    /** Render a module view inside the standard layout (mirrors render_view). */
    public static function view(string $id, string $name, array $vars = [], ?string $layout = 'layout'): never
    {
        $file = self::dir() . '/' . $id . '/views/' . $name . '.php';
        if (!is_file($file)) {
            http_response_code(404);
            echo 'Module view not found';
            exit;
        }
        extract($vars, EXTR_SKIP);
        if ($layout) {
            ob_start();
            require $file;
            $content = ob_get_clean();
            require ROOT . '/views/' . $layout . '.php';
        } else {
            require $file;
        }
        exit;
    }

    /** @return array{id:string, manifest:?array, error:?string} */
    private static function loadManifest(string $dir, string $id): array
    {
        $file = $dir . '/module.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            return ['id' => $id, 'manifest' => null, 'error' => 'missing module.json'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['id' => $id, 'manifest' => null, 'error' => 'module.json is not valid JSON'];
        }
        $mid = (string) ($data['id'] ?? '');
        if ($mid !== $id || !preg_match('/^[a-z0-9][a-z0-9\-_]*$/', $mid)) {
            return ['id' => $id, 'manifest' => null, 'error' => "id '$mid' does not match directory '$id' (lowercase [a-z0-9-_])"];
        }
        if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
            return ['id' => $id, 'manifest' => null, 'error' => "module.json for '$id' is missing 'name'"];
        }
        if (!is_string($data['version'] ?? null) || trim($data['version']) === '') {
            return ['id' => $id, 'manifest' => null, 'error' => "module.json for '$id' is missing 'version'"];
        }
        return ['id' => $id, 'manifest' => $data, 'error' => null];
    }

    /** Upsert scanned module rows (preserving enabled/license state) and prune
     *  rows whose directory no longer exists in any form. */
    private static function syncRows(array $scanned, array $ids): void
    {
        $ins = Database::query(
            'INSERT OR IGNORE INTO modules (id, name, version, installed_at) VALUES (?, ?, ?, datetime("now"))'
        );
        foreach ($scanned as $id => $info) {
            $manifest = $info['manifest'];
            $name = $manifest['name'] ?? $manifest['id'] ?? $id;
            $version = $manifest['version'] ?? '';
            $ins->execute([$id, $name, $version]);
            if ($manifest !== null) {
                Database::query('UPDATE modules SET name = ?, version = ? WHERE id = ?', [$name, $version, $id]);
            }
        }
        foreach (Database::all('SELECT id FROM modules') as $r) {
            if (!in_array($r['id'], $ids, true)) {
                Database::query('DELETE FROM modules WHERE id = ?', [$r['id']]);
            }
        }
    }

    /** @return array{0:bool,1:?string} [ok, error] */
    private static function checkRequires(string $id, array $manifest, array $enabledIds): array
    {
        $req = (array) ($manifest['requires'] ?? []);
        $lv = (string) ($req['lvchat'] ?? '');
        if ($lv !== '' && !self::versionOk(LVC_VERSION, $lv)) {
            return [false, "requires LVChat $lv (running " . LVC_VERSION . ')'];
        }
        $php = (string) ($req['php'] ?? '');
        if ($php !== '' && !self::versionOk(PHP_VERSION, $php)) {
            return [false, "requires PHP $php (running " . PHP_VERSION . ')'];
        }
        foreach ((array) ($req['modules'] ?? []) as $dep) {
            if (!in_array($dep, $enabledIds, true)) {
                return [false, "requires module '$dep' enabled"];
            }
        }
        return [true, null];
    }

    private static function versionOk(string $installed, string $constraint): bool
    {
        $constraint = trim($constraint);
        if (preg_match('#^(>=|<=|>|<|==|=|~|\^)?\s*v?([0-9][0-9a-zA-Z.\-_]*)$#', $constraint, $m)) {
            $op = $m[1] !== '' ? $m[1] : '>=';
            $ver = $m[2];
            if ($op === '~' || $op === '^') {
                return version_compare($installed, $ver, '>=')
                    && version_compare($installed, self::rangeUpper($ver, $op === '~'), '<');
            }
            return version_compare($installed, $ver, $op);
        }
        return false;
    }

    /** Upper bound for ~ (next minor) and ^ (next major) constraints. */
    private static function rangeUpper(string $ver, bool $minor): string
    {
        if (preg_match('/^v?(\d+)(?:\.(\d+))?/', $ver, $m)) {
            $major = (int) $m[1];
            $minorNum = isset($m[2]) ? (int) $m[2] : 0;
            if ($minor) {
                return $major . '.' . ($minorNum + 1) . '.0';
            }
            return ($major + 1) . '.0.0';
        }
        return '99.0.0';
    }

    private static function runModule(string $id, array $manifest, string $moduleDir): void
    {
        // Seed declared settings — existing admin-set values are never touched.
        foreach ((array) ($manifest['settings'] ?? []) as $k => $v) {
            if (is_scalar($v)) {
                Database::query('INSERT OR IGNORE INTO server_config (key, value) VALUES (?, ?)', [(string) $k, (string) $v]);
            }
        }

        // A module whose schema/init code throws must never take down the whole
        // request (or the Workerman daemon) — record it as a boot warning and
        // continue. The module still shows on Admin → Modules so the failure is
        // visible and fixable.
        try {
            // Idempotent schema migrations.
            $schema = $moduleDir . '/schema.php';
            if (is_file($schema)) {
                $fn = require $schema;
                if (is_callable($fn)) {
                    $fn(Database::instance());
                }
            }

            // Boot hook.
            $init = $moduleDir . '/init.php';
            if (is_file($init)) {
                require $init;
            }

            // Paid plugins: run the two-layer license validation and record the
            // status (drives isLicensed() + the Admin → Modules badge). Free
            // modules skip this entirely. See docs/protocol/licensing.md.
            if (!empty($manifest['license'])) {
                $key = (string) Database::scalar('SELECT license FROM modules WHERE id = ?', [$id]);
                LicensingService::validate($id, $key, $manifest);
            }
        } catch (Throwable $e) {
            self::$warnings[] = "Module '$id' failed to boot: " . $e->getMessage();
        }

        Database::query('UPDATE modules SET version = ?, updated_at = datetime("now") WHERE id = ?', [$manifest['version'], $id]);
    }
}

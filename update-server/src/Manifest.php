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
 * The release manifest — data/releases.json is the single source of truth for
 * every app the update server publishes. See the README for the format.
 */
final class Manifest
{
    public const APPS = ['web', 'desktop', 'messenger'];

    public const PLATFORMS = ['win', 'mac', 'linux_deb', 'linux_rpm', 'linux_appimage'];

    public static function dataFile(): string
    {
        return UPDATE_ROOT . '/data/releases.json';
    }

    public static function defaults(): array
    {
        $apps = [];
        foreach (self::APPS as $app) {
            $entry = [
                'version' => '',
                'notes' => '',
                'released_at' => '',
            ];
            if ($app !== 'web') {
                $entry['platforms'] = [];
                foreach (self::PLATFORMS as $plat) {
                    $entry['platforms'][$plat] = ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => null];
                }
            } else {
                $entry['url'] = '';
                $entry['sha256'] = '';
            }
            $apps[$app] = $entry;
        }
        return ['updated_at' => '', 'apps' => $apps];
    }

    /** @return array{updated_at:string, apps:array} */
    public static function load(): array
    {
        $data = self::defaults();
        $file = self::dataFile();
        if (is_file($file)) {
            $raw = file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = array_replace($data, $decoded);
                $data['apps'] = array_replace($data['apps'], is_array($data['apps'] ?? null) ? $data['apps'] : []);
                foreach (self::APPS as $app) {
                    $data['apps'][$app] = array_replace($data['apps'][$app] ?? [], $data['apps'][$app] ?? []);
                }
            }
        }
        return $data;
    }

    public static function save(array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $dir = dirname(self::dataFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            self::dataFile(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public static function app(?string $name): ?array
    {
        if (!is_string($name) || !in_array($name, self::APPS, true)) {
            return null;
        }
        $data = self::load();
        return $data['apps'][$name] ?? null;
    }

    public static function platform(?string $app, ?string $plat): ?array
    {
        $entry = self::app($app);
        if ($entry === null || $app === 'web' || !is_array($entry['platforms'] ?? null)) {
            return null;
        }
        if (!is_string($plat) || !in_array($plat, self::PLATFORMS, true)) {
            return null;
        }
        $p = $entry['platforms'][$plat] ?? null;
        return is_array($p) ? $p : null;
    }

    /** Basic sanity checks; returns a list of human-readable problems. */
    public static function validate(): array
    {
        $errors = [];
        $data = self::load();
        foreach (self::APPS as $app) {
            $entry = $data['apps'][$app] ?? [];
            if (trim((string) ($entry['version'] ?? '')) === '') {
                $errors[] = "$app: missing version";
                continue;
            }
            if ($app === 'web') {
                if (trim((string) ($entry['url'] ?? '')) === '') {
                    $errors[] = "$app: missing download url";
                }
                if (trim((string) ($entry['sha256'] ?? '')) !== '' && !preg_match('/^[a-f0-9]{64}$/i', (string) $entry['sha256'])) {
                    $errors[] = "$app: sha256 is not a valid 64-hex digest";
                }
                continue;
            }
            foreach (self::PLATFORMS as $plat) {
                $p = $entry['platforms'][$plat] ?? [];
                if (trim((string) ($p['url'] ?? '')) === '') {
                    continue;
                }
                if (trim((string) ($p['sha256'] ?? '')) !== '' && !preg_match('/^[a-f0-9]{64}$/i', (string) $p['sha256'])) {
                    $errors[] = "$app/$plat: sha256 is not a valid 64-hex digest";
                }
                if (trim((string) ($p['sha512'] ?? '')) !== '' && !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', (string) $p['sha512'])) {
                    $errors[] = "$app/$plat: sha512 is not valid base64";
                }
            }
        }
        return $errors;
    }

    public static function platformLabel(string $plat): string
    {
        return [
            'win' => 'Windows',
            'mac' => 'macOS',
            'linux_deb' => 'Linux (DEB)',
            'linux_rpm' => 'Linux (RPM)',
            'linux_appimage' => 'Linux (AppImage)',
        ][$plat] ?? $plat;
    }
}

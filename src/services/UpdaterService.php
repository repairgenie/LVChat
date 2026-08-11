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
 * Update feed client for LVChat server installs.
 *
 * The server can be pointed at an LVChat Update Server (or any mirror that
 * speaks the same /manifest.json format) via Admin → Settings → Updates. The
 * manifest lists the current version + download links + sha256 for the web
 * app, LVChat Desktop and LVChat Messenger.
 *
 * Server admins keep full control of what their community downloads: the
 * existing per-platform `download_*` config keys are CUSTOM OVERRIDES — a
 * configured URL/version wins; empty fields fall back to the upstream
 * manifest. Empty fields with no upstream configured hide the button, exactly
 * as before the update system existed.
 *
 * The manifest is cached to data/cache/updater-manifest.json (TTL) so pages
 * never block on the network. All failures degrade gracefully to "no update
 * info" — never an error page.
 */
final class UpdaterService
{
    public const APPS = ['web', 'desktop', 'messenger'];

    public const PLATFORMS = ['win', 'mac', 'linux_rpm', 'linux_deb', 'linux_appimage'];

    private const CACHE_TTL = 3600; // seconds before re-fetching the manifest
    private const CONNECT_TIMEOUT = 6;
    private const READ_TIMEOUT = 15;

    public static function enabled(): bool
    {
        return config_get('updater_enabled', '0') === '1';
    }

    /** Base URL of the update server (or mirror). Trailing slash stripped. */
    public static function baseUrl(): string
    {
        return rtrim(trim((string) (config_get('updater_url', '') ?? '')), '/');
    }

    public static function manifestPath(): string
    {
        return ROOT . '/data/cache/updater-manifest.json';
    }

    /** mtime of the cached manifest (0 when absent). */
    public static function cachedAt(): int
    {
        $p = self::manifestPath();
        return is_file($p) ? (int) filemtime($p) : 0;
    }

    /**
     * The upstream manifest as an array (empty when unreachable / disabled).
     * Pass $force to bypass the TTL cache.
     */
    public static function fetchManifest(bool $force = false): array
    {
        if (!self::enabled()) {
            return [];
        }
        $path = self::manifestPath();
        if (!$force && is_file($path) && time() - (int) filemtime($path) < self::CACHE_TTL) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $url = self::baseUrl();
        if ($url === '') {
            return [];
        }
        $body = self::httpGet($url . '/manifest.json');
        if ($body === '' || !str_contains($body, '{')) {
            // Keep the previous snapshot: a stale manifest beats an outage.
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                return is_array($decoded) ? $decoded : [];
            }
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, json_encode($decoded));
        return $decoded;
    }

    /** A single app entry from the manifest (or null). */
    public static function latest(?string $app): ?array
    {
        if (!is_string($app) || !in_array($app, self::APPS, true)) {
            return null;
        }
        $m = self::fetchManifest();
        $entry = $m['apps'][$app] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public static function latestVersion(string $app): string
    {
        $e = self::latest($app);
        return trim((string) (is_array($e) ? ($e['version'] ?? '') : ''));
    }

    public static function latestUrl(string $app, ?string $platform = null): string
    {
        $e = self::latest($app);
        if (!is_array($e)) {
            return '';
        }
        if ($platform !== null) {
            $p = $e['platforms'][$platform] ?? null;
            return is_array($p) ? trim((string) ($p['url'] ?? '')) : '';
        }
        return trim((string) ($e['url'] ?? ''));
    }

    public static function latestSha256(string $app, ?string $platform = null): string
    {
        $e = self::latest($app);
        if (!is_array($e)) {
            return '';
        }
        if ($platform !== null) {
            $p = $e['platforms'][$platform] ?? null;
            return is_array($p) ? trim((string) ($p['sha256'] ?? '')) : '';
        }
        return trim((string) ($e['sha256'] ?? ''));
    }

    public static function latestNotes(string $app): string
    {
        $e = self::latest($app);
        return is_array($e) ? trim((string) ($e['notes'] ?? '')) : '';
    }

    /** A server-configured custom URL for a download ('' when unset). */
    private static function customUrl(string $app, ?string $platform): string
    {
        if ($app === 'web') {
            return '';
        }
        return trim((string) (config_get("download_{$app}_{$platform}_url", '') ?? ''));
    }

    private static function customVersion(string $app, ?string $platform): string
    {
        if ($app === 'web') {
            return '';
        }
        return trim((string) (config_get("download_{$app}_{$platform}_version", '') ?? ''));
    }

    /**
     * The URL the chat's download modal should actually use for an app/platform:
     * custom override wins, else the upstream manifest, else '' (hide the button).
     */
    public static function effectiveUrl(string $app, ?string $platform = null): string
    {
        $custom = self::customUrl($app, $platform);
        if ($custom !== '') {
            return $custom;
        }
        return self::latestUrl($app, $platform);
    }

    /**
     * The version label displayed next to a download button. Custom wins, then
     * upstream, then '' (fall back to a plain "Download" label).
     */
    public static function effectiveVersion(string $app, ?string $platform = null): string
    {
        $custom = self::customVersion($app, $platform);
        if ($custom !== '') {
            return $custom;
        }
        return self::latestVersion($app);
    }

    /**
     * Compact per-app status for the admin page / CLI / /api/updater:
     *   {app, installed, latest, update_available, notes, sha256, source}
     * `source` is 'custom' or 'upstream' (or null when nothing configured).
     */
    public static function status(string $app): array
    {
        $installed = self::installedVersion($app);
        $latest = self::latestVersion($app);
        $row = [
            'app' => $app,
            'installed' => $installed,
            'latest' => $latest,
            'update_available' => false,
            'notes' => self::latestNotes($app),
            'sha256' => self::latestSha256($app),
            'source' => null,
        ];
        // Which source is the download modal actually showing?
        $custom = $app === 'web' ? '' : self::customUrl($app, self::PLATFORMS[0] ?? 'win');
        if ($custom !== '') {
            $row['source'] = 'custom';
        } elseif (self::enabled()) {
            $row['source'] = 'upstream';
        }
        if ($installed !== '' && $latest !== '' && self::versionNewer($latest, $installed)) {
            $row['update_available'] = true;
        }
        return $row;
    }

    public static function statusAll(): array
    {
        $out = [];
        foreach (self::APPS as $app) {
            $out[$app] = self::status($app);
        }
        return $out;
    }

    /** The version string this install reports for an app. */
    public static function installedVersion(string $app): string
    {
        if ($app === 'web') {
            return LVC_VERSION;
        }
        $custom = self::customVersion($app, 'win');
        if ($custom !== '') {
            return $custom;
        }
        return self::latestVersion($app);
    }

    /**
     * True when $a is a newer version than $b. Numeric-dotted versions use
     * version_compare; anything unusual falls back to a strict string compare.
     */
    public static function versionNewer(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === $b) {
            return false;
        }
        if (preg_match('/^\d+(\.\d+){0,3}$/', $a) && preg_match('/^\d+(\.\d+){0,3}$/', $b)) {
            return version_compare($a, $b, '>');
        }
        return strcmp($a, $b) > 0;
    }

    /**
     * Download the web app update into data/updates/ and verify its sha256
     * against the manifest. Returns ['ok'=>true,'path','size'] or an error map.
     * Never touches the live install — the admin applies it manually (upload +
     * `bash bin/deploy.sh`) or via the documented one-click path.
     */
    public static function downloadWebUpdate(): array
    {
        $url = self::latestUrl('web');
        if ($url === '' || !self::enabled()) {
            return ['ok' => false, 'error' => 'No web update is configured on this server\'s update feed.'];
        }
        $version = self::latestVersion('web') ?: 'latest';
        $dir = ROOT . '/data/updates';
        @mkdir($dir, 0775, true);
        $ext = strtolower((string) parse_url($url, PHP_URL_PATH));
        $suffix = str_ends_with($ext, '.zip') ? 'zip' : (str_ends_with($ext, '.tar.gz') ? 'tar.gz' : (str_ends_with($ext, '.tgz') ? 'tgz' : 'bin'));
        $dest = $dir . '/lvchat-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $version) . '.' . $suffix;

        $expected = self::latestSha256('web');
        $hash = hash_init('sha256');
        $size = 0;
        $ok = self::streamToFile($url, $dest, function (string $chunk) use (&$hash, &$size) {
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        });
        if (!$ok) {
            @unlink($dest);
            return ['ok' => false, 'error' => 'Download failed — the update server may be unreachable.'];
        }
        $actual = hash_final($hash);
        if ($expected !== '' && strtolower($actual) !== strtolower($expected)) {
            @unlink($dest);
            return ['ok' => false, 'error' => "sha256 mismatch — download rejected (got $actual, expected $expected)."];
        }
        return [
            'ok' => true,
            'path' => $dest,
            'filename' => basename($dest),
            'version' => $version,
            'size' => $size,
            'sha256' => $actual,
            'verified' => $expected !== '',
            'instructions' => 'Upload the file to your server, extract it over the app folder, then run `bash bin/deploy.sh`.',
        ];
    }

    /** Best-effort GET body ('' on failure), cURL first then bounded streams. */
    private static function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT => self::READ_TIMEOUT,
                    CURLOPT_USERAGENT => 'LVChat/' . LVC_VERSION . ' (update check)',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body = @curl_exec($ch);
                $code = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return is_string($body) && $code >= 200 && $code < 300 ? $body : '';
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::CONNECT_TIMEOUT,
            'user_agent' => 'LVChat/' . LVC_VERSION . ' (update check)',
            'header' => "Accept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }

    /** Stream a remote file to $dest with a per-chunk callback; true on success. */
    private static function streamToFile(string $url, string $dest, callable $onChunk): bool
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'wb');
            if ($fp === false) {
                return false;
            }
            $ch = @curl_init($url);
            if ($ch === false) {
                fclose($fp);
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_USERAGENT => 'LVChat/' . LVC_VERSION . ' (update download)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, $fp) {
                    $onChunk($data);
                    return (int) fwrite($fp, $data);
                },
            ]);
            curl_exec($ch);
            $code = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($ch) !== 0;
            curl_close($ch);
            fclose($fp);
            return !$err && $code >= 200 && $code < 300;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 600,
            'user_agent' => 'LVChat/' . LVC_VERSION . ' (update download)',
        ]]);
        $src = @fopen($url, 'rb', false, $ctx);
        if ($src === false) {
            return false;
        }
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            fclose($src);
            return false;
        }
        while (!feof($src)) {
            $chunk = fread($src, 65536);
            if ($chunk === false) {
                fclose($src);
                fclose($fp);
                @unlink($dest);
                return false;
            }
            $onChunk($chunk);
            fwrite($fp, $chunk);
        }
        fclose($src);
        fclose($fp);
        return true;
    }
}

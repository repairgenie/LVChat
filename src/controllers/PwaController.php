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
 * Progressive Web App endpoints.
 *
 * The manifest is generated (rather than a static file) so the installed app
 * picks up the configured site name. Served at /manifest (no extension so the
 * PHP built-in dev server routes it through the front controller too).
 */
final class PwaController
{
    public static function manifest(): void
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $short = mb_substr($site, 0, 12);
        $colors = ThemeService::manifestColors();

        header('Content-Type: application/manifest+json; charset=utf-8');
        // Short TTL: the manifest rarely changes, but this keeps the configured
        // name fresh across renames without forcing clients to bypass the cache.
        header('Cache-Control: public, max-age=300');
        echo json_encode([
            'name' => $site,
            'short_name' => $short,
            'description' => ((string) (config_get('site_tagline', 'Discord-style web chat') ?: 'Discord-style web chat')) . ' — ' . $site,
            'id' => '/app',
            'start_url' => '/app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $colors['background_color'],
            'theme_color' => $colors['theme_color'],
            'lang' => 'en',
            'categories' => ['social', 'communication'],
            'icons' => [
                ['src' => '/assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Chat', 'url' => '/app'],
                ['name' => 'Browse channels', 'url' => '/browse'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

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
 * ModuleController — serves static assets from enabled modules.
 *
 * GET /modules/{name}/assets/{path...} reads files from modules/<name>/assets/
 * with a realpath traversal guard, a content-type whitelist, and a short cache
 * header. Disabled or unknown modules never serve assets.
 */
final class ModuleController
{
    private const MIME = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'map' => 'application/json',
    ];

    public static function asset(array $params): void
    {
        $name = (string) ($params['name'] ?? '');
        $path = (string) ($params['path'] ?? '');
        // Only enabled modules can serve assets (`.disabled` / unknown -> 404).
        if (ModuleLoader::get($name) === null || $path === '' || str_contains($path, "\0")) {
            self::notFound();
        }

        $base = realpath(ModuleLoader::dir() . '/' . $name . '/assets');
        $file = realpath($base . '/' . $path);
        if ($base === false || $file === false
            || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)
            || !is_file($file)) {
            self::notFound();
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

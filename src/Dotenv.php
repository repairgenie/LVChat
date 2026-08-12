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
 * Dotenv — minimal, dependency-free .env loader.
 *
 * Reads ROOT/.env (when present) into the process environment so deployment
 * secrets and developer-only config never live in the repo or in server_config.
 * Rules, matching the house style (everything is getenv()/putenv()):
 *   - KEY=VALUE lines; blank lines and '#' comments are skipped.
 *   - An optional "export " prefix is tolerated.
 *   - Single/double quotes around values are stripped.
 *   - Existing environment variables are NEVER overridden (so CHAT_DB set in
 *     the shell or by the test harness always wins over .env).
 */
final class Dotenv
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if (getenv($key) !== false) {
                continue;
            }
            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

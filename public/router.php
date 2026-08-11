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


// Router for the PHP built-in dev server (php -S 127.0.0.1:8000 -t public).
// Apache/nginx use the .htaccess front-controller rewrite instead. Return
// false for real files so the built-in server serves them statically (the
// module asset route needs this); route everything else through index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '' && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';

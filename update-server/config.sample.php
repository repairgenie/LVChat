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

// LVChat Update Server configuration.
//
// Copy this file to config.php (next to it) and edit the values.
// config.php is git-ignored — it never ships with the app.

// Public display name used in the header/footer.
return [
    'site_name' => 'LVChat Updates',

    // Base public URL of this server (used for links in the UI and feeds).
    // Example: 'https://updates.lasvegasbestinternet.com'
    'base_url' => '',

    // Operator password. Either:
    //   - 'admin_pass'            the plaintext password (simplest), or
    //   - 'admin_pass_hash'       a password_hash()/bcrypt hash of it (recommended).
    //
    // Generate a hash with:
    //   php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), PHP_EOL;'
    // Then put the output in admin_pass_hash below.
    'admin_pass' => '',
    'admin_pass_hash' => '',
];

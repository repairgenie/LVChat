#!/usr/bin/env php
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

// check-manifest.php — validate the release manifest from the CLI.
//   php bin/check-manifest.php
// Exits 0 when healthy, 1 when issues are found.

require dirname(__DIR__) . '/src/bootstrap.php';

$errors = Manifest::validate();
$data = Manifest::load();

echo "LVChat Update Server manifest\n";
echo str_repeat('-', 46) . "\n";
foreach (Manifest::APPS as $app) {
    $entry = $data['apps'][$app] ?? [];
    $ver = trim((string) ($entry['version'] ?? ''));
    printf("%-10s %s\n", $app, $ver !== '' ? 'v' . $ver : '(not published)');
    if ($app !== 'web') {
        foreach (Manifest::PLATFORMS as $plat) {
            $p = $entry['platforms'][$plat] ?? [];
            $url = trim((string) ($p['url'] ?? ''));
            printf("  %-15s %s\n", Manifest::platformLabel($plat), $url !== '' ? $url : '-');
        }
    } else {
        printf("  %-15s %s\n", 'download', trim((string) ($entry['url'] ?? '')) !== '' ? trim((string) ($entry['url'] ?? '')) : '-');
    }
}
echo str_repeat('-', 46) . "\n";

if ($errors !== []) {
    echo "Issues:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
echo "Manifest OK.\n";
exit(0);

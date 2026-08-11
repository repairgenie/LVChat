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

// LVChat Update Server bootstrap.

define('UPDATE_ROOT', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '1');

$__cfg = [];
$__cfgFile = UPDATE_ROOT . '/config.php';
if (is_file($__cfgFile)) {
    $__cfg = (array) require $__cfgFile;
}
$__cfg['site_name'] = (string) ($__cfg['site_name'] ?? 'LVChat Updates');
$__cfg['base_url'] = (string) ($__cfg['base_url'] ?? '');
$__cfg['admin_pass'] = (string) ($__cfg['admin_pass'] ?? '');
$__cfg['admin_pass_hash'] = (string) ($__cfg['admin_pass_hash'] ?? '');
if (!defined('UPDATE_CONFIG')) {
    define('UPDATE_CONFIG', $__cfg);
}
unset($__cfg, $__cfgFile);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => $secure ? 'None' : 'Lax',
]);
session_start();

require UPDATE_ROOT . '/src/helpers.php';
require UPDATE_ROOT . '/src/Csrf.php';
require UPDATE_ROOT . '/src/Manifest.php';
require UPDATE_ROOT . '/src/ElectronFeed.php';

function config_value(string $key, string $default = ''): string
{
    $c = defined('UPDATE_CONFIG') ? UPDATE_CONFIG : [];
    return (string) ($c[$key] ?? $default);
}

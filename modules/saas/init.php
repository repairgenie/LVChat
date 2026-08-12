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



/**
 * SaaS — boot hook. Must stay side-effect-free (no output/headers/exit): it
 * only loads the module's classes. Runs on every request including the
 * Workerman daemon, so the connection-metering gate in bin/ws-server.php can
 * call SaaSService directly (guarded by class_exists). See docs/modules.md.
 */

if (!class_exists('SaaSService')) {
    require __DIR__ . '/SaaSService.php';
}
if (!class_exists('BillingService')) {
    require __DIR__ . '/BillingService.php';
}
if (!interface_exists('SaasProviderDriver')) {
    require __DIR__ . '/Providers/SaasProviderDriver.php';
    foreach (glob(__DIR__ . '/Providers/*Driver.php') ?: [] as $file) {
        require_once $file;
    }
}
if (!class_exists('AdminSaaSController')) {
    require __DIR__ . '/AdminSaaSController.php';
}
if (!class_exists('BillingController')) {
    require __DIR__ . '/BillingController.php';
}

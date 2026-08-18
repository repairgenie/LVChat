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
 * Scheduled jobs runner — call from system cron every minute:
 *
 *   * * * * * php /path/to/bin/scheduledjobs.php >> data/cron.log 2>&1
 *
 * To add a new job:
 *   1. Create src/jobs/YourJob.php with a static run() method.
 *   2. Add YourJob::class to the $jobs array below.
 *   3. That's it — the runner will call it every minute.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require ROOT . '/src/jobs/EventSchedulerJob.php';
require ROOT . '/src/jobs/RealtimeGatewayCheckJob.php';
require ROOT . '/src/jobs/VoiceCleanupJob.php';

// ── Job registry ────────────────────────────────────────────────────────────
// Each entry must be a class with a public static run(): void method.
// The runner calls every job on every tick; keep jobs idempotent.
$jobs = [
    EventSchedulerJob::class,
    RealtimeGatewayCheckJob::class,
    VoiceCleanupJob::class,
    // Future jobs:
    // SubscriptionBillingJob::class,
];

// ── Runner ──────────────────────────────────────────────────────────────────
foreach ($jobs as $jobClass) {
    try {
        $jobClass::run();
    } catch (\Throwable $e) {
        $msg = '[' . gmdate('Y-m-d H:i:s') . "] Job {$jobClass} failed: "
            . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
        fwrite(STDERR, $msg);
        error_log($msg);
    }
}

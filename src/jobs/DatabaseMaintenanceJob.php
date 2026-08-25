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
 * Database maintenance: prune stale data, ANALYZE, and auto-backup.
 * Runs once daily (the cron runner calls every minute; we gate on day change).
 */
class DatabaseMaintenanceJob
{
    public static function run(): void
    {
        $lastRun = config_get('db_maintenance_last_run');
        $today = gmdate('Y-m-d');
        if ($lastRun === $today) {
            return;
        }
        Database::maintenance();
        self::pruneExpiredFiles();
        config_set('db_maintenance_last_run', $today);
    }

    /**
     * Delete uploaded files that have passed their expiry date.
     * Files with expires_at = NULL never expire (admin set expiry to 0).
     */
    private static function pruneExpiredFiles(): void
    {
        $expiryDays = (int) config_get('upload_expiry_days', 60);
        if ($expiryDays <= 0) {
            return;
        }
        $pdo = Database::instance();
        $rows = $pdo->query('SELECT id, url FROM uploaded_files WHERE expires_at IS NOT NULL AND expires_at < datetime("now")')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            UploadService::remove($row['url']);
        }
        $pdo->exec('DELETE FROM uploaded_files WHERE expires_at IS NOT NULL AND expires_at < datetime("now")');
    }
}

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
 * VoiceCleanupJob — the WebRTC module's housekeeping pass, run every minute
 * by bin/scheduledjobs.php (same registry as EventSchedulerJob).
 *
 * Complements the opportunistic cleanups that already run on the request
 * path (pruneStale/expireCalls in the status poll):
 *   - Push "missed call" nudges exactly once per unanswered call.
 *   - Purge call_sessions older than 24h (the status filter alone keeps them
 *     forever; the IRC log keeps the history).
 *   - Purge orphaned voice_sessions, expired rate-limit buckets, empty room
 *     flags, and old event landing limits.
 *
 * Everything is idempotent; a second run is a no-op.
 */
final class VoiceCleanupJob
{
    public static function run(): void
    {
        // 1. Missed-call push, once per call. The callee may have all pages
        //    closed; the OS nudge (if push subscriptions exist) is the ring
        //    that time forgot. Guests never get push (PushService guards).
        $missed = Database::all(
            "SELECT *, u.username AS callee_name FROM call_sessions cs
             LEFT JOIN users u ON u.id = cs.callee_user_id
             WHERE cs.status = 'missed' AND cs.missed_pushed = 0
             ORDER BY cs.id"
        );
        foreach ($missed as $call) {
            $caller = '';
            if ((int) ($call['caller_user_id'] ?? 0)) {
                $caller = (string) (Database::scalar('SELECT username FROM users WHERE id = ?', [(int) $call['caller_user_id']]) ?? '');
            } elseif ((int) ($call['caller_guest_id'] ?? 0)) {
                $caller = (string) (Database::scalar('SELECT nick FROM guests WHERE id = ?', [(int) $call['caller_guest_id']]) ?? '');
            }
            if ((int) ($call['callee_user_id'] ?? 0)) {
                try {
                    PushService::missedCall((int) $call['callee_user_id'], $caller, (int) $call['id']);
                } catch (\Throwable $e) {
                    // Push must never break the cron loop.
                }
            }
            Database::query('UPDATE call_sessions SET missed_pushed = 1 WHERE id = ?', [(int) $call['id']]);
        }

        // 2. Purge finished calls older than a day. Histories live in
        //    chat_logs (kind='call') from LogCallOutcome, so deleting the
        //    state machine rows is safe.
        Database::query(
            "DELETE FROM call_sessions
             WHERE status IN ('declined', 'missed', 'cancelled', 'ended')
               AND created_at < datetime('now', '-24 hours')"
        );

        // 3. Any voice session rows that outlived their client (belt and
        //    braces beyond the 2-minute poll-triggered prune).
        Database::query('DELETE FROM voice_sessions WHERE last_seen < datetime("now", "-10 minutes")');

        // 4. Rate-limit buckets older than 2× their natural window and landing
        //    limits older than 10 minutes (they're per-IP, never purged today).
        Database::query('DELETE FROM rate_limits WHERE window_start < strftime("%s", "now") - 7200');
        Database::query('DELETE FROM event_land_limits WHERE updated_at < datetime("now", "-10 minutes")');

        // 5. Room flags for rooms that no longer exist (clean slate so a new
        //    room with the same name starts unlocked).
        Database::query(
            'DELETE FROM voice_room_flags WHERE room NOT IN (SELECT room FROM voice_sessions)'
        );

        // 6. Reconcile recordings: egress finished but the app row still says
        //    active → mark stopped; prune rows for rooms that are long gone.
        if (class_exists('LiveKitService') && LiveKitService::enabled()) {
            $items = LiveKitService::listEgress();
            $done = [];
            foreach ($items as $item) {
                if (in_array($item['status'], ['EGRESS_COMPLETE', 'EGRESS_FAILED', 'EGRESS_ABORTED'], true)) {
                    $done[] = $item['egress_id'];
                }
            }
            if ($done) {
                $ph = implode(',', array_fill(0, count($done), '?'));
                Database::query(
                    "UPDATE recordings SET status = 'stopped', stopped_at = datetime('now')
                     WHERE egress_id IN ($ph) AND status IN ('starting', 'active')",
                    $done
                );
            }
            // Size the files so the admin panel shows something useful.
            $dir = LiveKitService::recordingsDir();
            foreach (Database::all("SELECT id, filename FROM recordings WHERE size_bytes = 0 AND filename IS NOT NULL") as $rec) {
                $p = $dir . '/' . $rec['filename'];
                if (is_file($p)) {
                    Database::query('UPDATE recordings SET size_bytes = ? WHERE id = ?', [(int) filesize($p), (int) $rec['id']]);
                }
            }
        }
    }
}
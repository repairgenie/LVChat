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
 * EventSchedulerJob — runs every minute via scheduledjobs.php.
 *
 * Responsibilities:
 *   1. Unlock event channels 15 minutes before start (set invite_only = 0).
 *   2. Create channels and start events when their scheduled_at arrives.
 *   3. End events that have exceeded their duration (kick, log, email, delete).
 *   4. Send founder reminder emails 15 minutes before start.
 */
final class EventSchedulerJob
{
    /** Run all event lifecycle checks. Called every minute by the cron runner. */
    public static function run(): void
    {
        self::unlockUpcoming();
        self::startEvents();
        self::endEvents();
        self::sendReminders();
    }

    /**
     * Unlock channels for events starting within 15 minutes.
     * Sets invite_only = 0 so anyone with the link/code can join freely.
     */
    private static function unlockUpcoming(): void
    {
        $events = Database::all(
            "SELECT e.*, c.id AS cid, c.invite_only
             FROM events e
             JOIN channels c ON c.id = e.channel_id
             WHERE e.status = 'active'
               AND e.started_at IS NOT NULL
               AND c.invite_only = 1
               AND e.scheduled_at IS NOT NULL
               AND datetime(e.scheduled_at, '-15 minutes') <= datetime('now')
               AND datetime(e.scheduled_at) > datetime('now', '-30 minutes')"
        );
        foreach ($events as $e) {
            Database::query('UPDATE channels SET invite_only = 0 WHERE id = ?', [(int) $e['cid']]);
            log_audit('event_unlock', $e['title'], 'channel unlocked 15 min before start');
        }
    }

    /**
     * Start scheduled events whose scheduled_at has arrived.
     * Creates the channel, links it, sets status = active.
     */
    private static function startEvents(): void
    {
        $events = Database::all(
            "SELECT * FROM events WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= datetime('now')"
        );
        foreach ($events as $e) {
            $founder = Database::row('SELECT * FROM users WHERE id = ?', [(int) $e['founder_id']]);
            if (!$founder) {
                continue;
            }

            $channelId = EventController::createEventChannel(
                $founder,
                $e['title'],
                $e['event_type'],
                (string) ($e['stream_url'] ?? ''),
                (bool) $e['is_public']
            );
            if (is_string($channelId)) {
                // Channel creation failed — log and skip; will retry next tick.
                log_audit('event_start_fail', $e['title'], $channelId);
                continue;
            }

            $slug = (string) Database::scalar('SELECT slug FROM channels WHERE id = ?', [$channelId]);
            Database::query(
                "UPDATE events SET channel_id = ?, started_at = datetime('now'), status = 'active' WHERE id = ?",
                [$channelId, (int) $e['id']]
            );
            Database::query('UPDATE channels SET event_id = ? WHERE id = ?', [(int) $e['id'], $channelId]);

            log_audit('event_start', $e['title'], 'channel ' . $slug);

            // Auto-join the founder.
            ChannelService::join(
                Database::row('SELECT * FROM channels WHERE id = ?', [$channelId]),
                $founder
            );
        }
    }

    /**
     * End events that have exceeded their duration or been running > 24h.
     * Generates the chat log, emails it to the founder, cleans up the channel.
     */
    private static function endEvents(): void
    {
        $events = Database::all(
            "SELECT * FROM events WHERE status = 'active' AND started_at IS NOT NULL AND (
                (duration_minutes IS NOT NULL AND datetime(started_at, '+' || duration_minutes || ' minutes') <= datetime('now'))
                OR (duration_minutes IS NULL AND datetime(started_at, '+24 hours') <= datetime('now'))
            )"
        );
        foreach ($events as $e) {
            $event = $e; // alias for readability
            $channelId = $event['channel_id'] ? (int) $event['channel_id'] : null;
            $channelSlug = $channelId
                ? (string) Database::scalar('SELECT slug FROM channels WHERE id = ?', [$channelId])
                : null;
            $channelName = $channelId
                ? (string) Database::scalar('SELECT name FROM channels WHERE id = ?', [$channelId])
                : $event['title'];

            // Generate and email the chat log before cleanup.
            if ($channelId && $channelSlug) {
                $founder = Database::row('SELECT * FROM users WHERE id = ?', [(int) $event['founder_id']]);
                if ($founder && Mailer::configured()) {
                    $logPath = EventLogService::buildLogZip(
                        $event,
                        $channelName,
                        $channelSlug
                    );
                    if ($logPath) {
                        EventLogService::emailLog($event, $founder, $logPath);
                        @unlink($logPath);
                    }
                }
            }

            // Kick everyone and delete the channel.
            if ($channelId) {
                EventController::cleanupEventChannel($channelId);
            }

            Database::query(
                "UPDATE events SET status = 'ended', ended_at = datetime('now') WHERE id = ?",
                [(int) $event['id']]
            );
            log_audit('event_end', $event['title'], 'duration ended');
        }
    }

    /**
     * Send reminder emails to founders 15 minutes before event start.
     * Each event gets only one reminder (reminder_sent flag).
     */
    private static function sendReminders(): void
    {
        $events = Database::all(
            "SELECT e.*, u.username, u.email
             FROM events e
             JOIN users u ON u.id = e.founder_id
             WHERE e.status = 'scheduled'
               AND e.scheduled_at IS NOT NULL
               AND e.reminder_sent = 0
               AND datetime(e.scheduled_at, '-15 minutes') <= datetime('now')
               AND datetime(e.scheduled_at) > datetime('now')"
        );
        foreach ($events as $e) {
            if (empty($e['email']) || !Mailer::configured()) {
                Database::query('UPDATE events SET reminder_sent = 1 WHERE id = ?', [(int) $e['id']]);
                continue;
            }

            $siteName = config_get('site_name', 'LVChat');
            $text = "Your event \"{$e['title']}\" starts in 15 minutes.\n\n"
                . "Scheduled: {$e['scheduled_at']} UTC\n"
                . "Type: {$e['event_type']}\n";

            $html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">'
                . '<h2 style="color:#5865F2">Event Starting Soon</h2>'
                . '<p>Your event <strong>' . h($e['title']) . '</strong> starts in 15 minutes.</p>'
                . '<p>Scheduled: ' . h($e['scheduled_at']) . ' UTC</p>'
                . '<p>Type: ' . h($e['event_type']) . '</p>'
                . '</div>';

            Mailer::send($e['email'], "Event Starting Soon: {$e['title']}", $text, $html);
            Database::query('UPDATE events SET reminder_sent = 1 WHERE id = ?', [(int) $e['id']]);
            log_audit('event_reminder', $e['title'], 'reminder sent to ' . $e['email']);
        }
    }
}

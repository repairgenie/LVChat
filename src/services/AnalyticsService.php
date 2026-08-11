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
 * Aggregations for the admin analytics dashboard.
 *
 * Every timestamp in the DB is UTC (`datetime('now')`), so all range filters
 * and day-series here are built in UTC to match the append-only chat_logs
 * archive, the moderation queue, and the audit log.
 *
 * Methods return chart-ready rows, either a day-series of
 * ['label' => 'YYYY-MM-DD', 'value' => int] pairs or a grouped
 * ['label' => string, 'value' => int] ranking, sorted descending.
 */
final class AnalyticsService
{
    private const SYSTEM_KINDS = '("join","part","quit","kick","ban","topic","mode","nick","system","notice")';
    private const SPOKEN_KINDS = '("message","action","pm")';

    /** Valid ?range= values. */
    public static function ranges(): array
    {
        return ['7', '30', '90', 'all'];
    }

    /** UTC datetime used as the lower bound for a range, or null for all time. */
    public static function rangeSince(string $range): ?string
    {
        return match ($range) {
            '7' => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
            '30' => gmdate('Y-m-d H:i:s', time() - 30 * 86400),
            '90' => gmdate('Y-m-d H:i:s', time() - 90 * 86400),
            default => null,
        };
    }

    /** ['col >= ?', [since]] or ['', []]. */
    private static function since(string $dateCol, ?string $since): array
    {
        return $since === null ? ['', []] : ["$dateCol >= ?", [$since]];
    }

    /**
     * Full day-by-day series (zero-filled) from $since — or from the earliest
     * recorded row when $since is null — up to today, in UTC.
     */
    private static function dailySeries(array $byDay, ?string $since): array
    {
        $today = gmdate('Y-m-d');
        $start = $since !== null
            ? substr($since, 0, 10)
            : (count($byDay) > 0 ? min(array_keys($byDay)) : $today);
        $out = [];
        $cursor = $start;
        $guard = 0;
        while ($cursor <= $today && $guard < 3700) {
            $out[] = ['label' => $cursor, 'value' => (int) ($byDay[$cursor] ?? 0)];
            $cursor = gmdate('Y-m-d', strtotime($cursor . ' +1 day'));
            $guard++;
        }
        return $out;
    }

    /** Group a table's rows per day, zero-filled across the range. */
    private static function toSeries(string $dateCol, ?string $since, string $select, string $from, string $where = '', array $params = []): array
    {
        [$w, $p] = self::since($dateCol, $since);
        $conds = array_values(array_filter([$where, $w]));
        $sql = $select . ' FROM ' . $from . (count($conds) > 0 ? ' WHERE ' . implode(' AND ', $conds) : '') . ' GROUP BY day';
        $rows = Database::all($sql, array_merge($params, $p));
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = (int) $r['value'];
        }
        return self::dailySeries($byDay, $since);
    }

    /** Headline numbers for the KPI row. */
    public static function kpis(?string $since): array
    {
        $censor = (int) Database::scalar(
            'SELECT COUNT(*) FROM moderation_events WHERE kind = "badword"' . ($since !== null ? ' AND created_at >= ?' : ''),
            $since !== null ? [$since] : []
        );
        return [
            'total_users' => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE guest = 0'),
            'online_now' => online_count(),
            'peak_online' => (int) (config_get('peak_online', '0') ?? 0),
            'messages' => (int) array_sum(array_column(self::messagesDaily($since), 'value')),
            'pms' => (int) array_sum(array_column(self::pmsDaily($since), 'value')),
            'censor_hits' => $censor,
            'open_reports' => (int) Database::scalar('SELECT COUNT(*) FROM reports WHERE status = "open"'),
        ];
    }

    /** Accounts (and guests) seen in the last 24h / 7d / 30d. */
    public static function activeCounts(): array
    {
        $count = static fn (int $seconds): int => (int) Database::scalar(
            'SELECT COUNT(*) FROM users WHERE guest = 0 AND last_seen IS NOT NULL AND last_seen >= datetime("now", ?)',
            ['-' . $seconds . ' seconds']
        );
        return [
            'users_24h' => $count(86400),
            'users_7d' => $count(7 * 86400),
            'users_30d' => $count(30 * 86400),
            'guests_30d' => (int) Database::scalar('SELECT COUNT(*) FROM guests WHERE last_seen >= datetime("now", "-30 days")'),
        ];
    }

    public static function messagesDaily(?string $since): array
    {
        return self::toSeries('created_at', $since, 'SELECT substr(created_at,1,10) AS day, COUNT(*) AS value', 'chat_logs', 'kind IN ' . self::SPOKEN_KINDS);
    }

    public static function pmsDaily(?string $since): array
    {
        return self::toSeries('created_at', $since, 'SELECT substr(created_at,1,10) AS day, COUNT(*) AS value', 'private_messages');
    }

    public static function registrationsDaily(?string $since): array
    {
        return self::toSeries('registered_at', $since, 'SELECT substr(registered_at,1,10) AS day, COUNT(*) AS value', 'users', 'guest = 0');
    }

    /** Distinct people who spoke per day (archive keeps usernames after deletes). */
    public static function dauDaily(?string $since): array
    {
        return self::toSeries(
            'created_at',
            $since,
            'SELECT substr(created_at,1,10) AS day, COUNT(DISTINCT username) AS value',
            'chat_logs',
            'username IS NOT NULL AND kind NOT IN ' . self::SYSTEM_KINDS
        );
    }

    /** Most active speakers (channel messages + actions + PMs) in the range. */
    public static function topUsers(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('cl.created_at', $since);
        $sql = 'SELECT COALESCE(cl.username, "(deleted)") AS label, COUNT(*) AS value
                FROM chat_logs cl
                WHERE cl.username IS NOT NULL AND cl.kind IN ' . self::SPOKEN_KINDS;
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY cl.username ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    /** Accounts with the least total activity ever (candidates for pruning/nudging). */
    public static function leastActive(int $limit = 12): array
    {
        return Database::all(
            'SELECT u.id, u.username, u.registered_at, u.last_seen, u.status, u.banned,
                    (SELECT COUNT(*) FROM messages m WHERE m.sender_id = u.id) AS messages,
                    (SELECT COUNT(*) FROM private_messages pm WHERE pm.sender_id = u.id) AS pms
             FROM users u
             WHERE u.guest = 0
             ORDER BY (messages + pms) ASC, u.last_seen IS NULL DESC, u.last_seen ASC, u.registered_at ASC
             LIMIT ?',
            [$limit]
        );
    }

    public static function activityByHour(?string $since): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT CAST(strftime('%H', created_at) AS INTEGER) AS hour, COUNT(*) AS value
                FROM chat_logs WHERE kind IN " . self::SPOKEN_KINDS;
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY hour';
        $byHour = [];
        foreach (Database::all($sql, $p) as $r) {
            $byHour[(int) $r['hour']] = (int) $r['value'];
        }
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ['label' => (string) $h, 'value' => $byHour[$h] ?? 0];
        }
        return $out;
    }

    public static function activityByWeekday(?string $since): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT CAST(strftime('%w', created_at) AS INTEGER) AS dow, COUNT(*) AS value
                FROM chat_logs WHERE kind IN " . self::SPOKEN_KINDS;
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY dow';
        $byDow = [];
        foreach (Database::all($sql, $p) as $r) {
            $byDow[(int) $r['dow']] = (int) $r['value'];
        }
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $out[] = ['label' => $names[$d], 'value' => $byDow[$d] ?? 0];
        }
        return $out;
    }

    public static function topChannels(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('cl.created_at', $since);
        $sql = 'SELECT COALESCE(cl.channel_name, "(unknown)") AS label, COUNT(*) AS value
                FROM chat_logs cl WHERE cl.channel_name IS NOT NULL';
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY cl.channel_name ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    public static function topDmSenders(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = 'SELECT COALESCE((SELECT u.username FROM users u WHERE u.id = pm.sender_id),
                                (SELECT g.nick FROM guests g WHERE g.id = pm.sender_guest_id),
                                "(deleted)") AS label,
                       COUNT(*) AS value
                FROM private_messages pm';
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY pm.sender_id, pm.sender_guest_id ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    /** Users who tripped a moderation filter (badword censor or spamfilter). */
    private static function filterLeaders(?string $since, string $kind, int $limit): array
    {
        [$w, $p] = self::since('me.created_at', $since);
        $sql = "SELECT COALESCE((SELECT u.username FROM users u WHERE u.id = me.user_id),
                                (SELECT g.nick FROM guests g WHERE g.id = me.guest_id),
                                '(deleted)') AS label,
                       COUNT(*) AS value
                FROM moderation_events me WHERE me.kind = ?";
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY me.user_id, me.guest_id ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge([$kind], $p, [$limit]));
    }

    /** Users who trigger the bad-word censor the most. */
    public static function censorLeaders(?string $since, int $limit = 10): array
    {
        return self::filterLeaders($since, 'badword', $limit);
    }

    /** Users who trigger spam filters the most. */
    public static function spamLeaders(?string $since, int $limit = 10): array
    {
        return self::filterLeaders($since, 'spamfilter', $limit);
    }

    /** The exact words/patterns that tripped filters most often. */
    public static function topMatchedWords(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT match AS label, COUNT(*) AS value
                FROM moderation_events
                WHERE kind IN ('badword','spamfilter') AND match != ''";
        if ($w !== '') {
            $sql .= ' AND ' . $w;
        }
        $sql .= ' GROUP BY match ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    public static function moderationDaily(?string $since): array
    {
        return self::toSeries('created_at', $since, 'SELECT substr(created_at,1,10) AS day, COUNT(*) AS value', 'moderation_events');
    }

    /** Moderation events broken down by kind (badword vs spamfilter vs actions). */
    public static function moderationMix(?string $since): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = 'SELECT kind AS label, COUNT(*) AS value FROM moderation_events';
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY kind ORDER BY value DESC';
        return Database::all($sql, $p);
    }

    /** Global + channel bans by kind (kline / gline / zline / shun / quiet). */
    public static function banMix(): array
    {
        return Database::all('SELECT kind AS label, COUNT(*) AS value FROM bans GROUP BY kind ORDER BY value DESC');
    }

    /** Message reports by status. */
    public static function reportMix(?string $since): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = 'SELECT status AS label, COUNT(*) AS value FROM reports';
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY status ORDER BY value DESC';
        return Database::all($sql, $p);
    }

    /** Most reported senders (snapshot survives edits/deletes). */
    public static function topReported(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT COALESCE(NULLIF(sender_name, ''), '(unknown)') AS label, COUNT(*) AS value FROM reports";
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY sender_name ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    /** Most common report reasons. */
    public static function reportReasons(?string $since, int $limit = 10): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT COALESCE(NULLIF(reason, ''), '(no reason)') AS label, COUNT(*) AS value FROM reports";
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY reason ORDER BY value DESC LIMIT ?';
        return Database::all($sql, array_merge($p, [$limit]));
    }

    public static function auditDaily(?string $since): array
    {
        return self::toSeries('created_at', $since, 'SELECT substr(created_at,1,10) AS day, COUNT(*) AS value', 'audit_log');
    }

    public static function ticketsDaily(?string $since): array
    {
        return self::toSeries('created_at', $since, 'SELECT substr(created_at,1,10) AS day, COUNT(*) AS value', 'support_tickets');
    }

    /** Account invites: used / pending / expired. */
    public static function inviteStats(?string $since): array
    {
        [$w, $p] = self::since('created_at', $since);
        $sql = "SELECT CASE WHEN used_at IS NOT NULL THEN 'Used'
                            WHEN expires_at < datetime('now') THEN 'Expired'
                            ELSE 'Pending' END AS label,
                       COUNT(*) AS value
                FROM registration_invites";
        if ($w !== '') {
            $sql .= ' WHERE ' . $w;
        }
        $sql .= ' GROUP BY label ORDER BY value DESC';
        return Database::all($sql, $p);
    }

    /** Webhooks with their channel and last use (for the health table). */
    public static function webhooks(): array
    {
        return Database::all(
            'SELECT w.name AS webhook, COALESCE(c.name, "(deleted channel)") AS channel, w.last_used
             FROM webhooks w LEFT JOIN channels c ON c.id = w.channel_id
             ORDER BY w.last_used DESC LIMIT 10'
        );
    }
}

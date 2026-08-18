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
 * Per-user notification preferences shared by every surface (web app, PWA,
 * desktop client, messenger). Layered on top of the legacy per-context push
 * prefs and per-channel/per-user mutes:
 *
 *   sound_master          — play any alert sound
 *   os_master             — show any OS/browser notification
 *   previews              — include message content in notifications
 *   quiet_hours_enabled   — suppress sounds + OS alerts during a window
 *   quiet_hours_start/end — "HH:MM" (24h), compared in the user's local time
 *   quiet_hours_days      — JSON array of weekday numbers 0 (Sun)..6 (Sat); empty = every day
 *   highlight_keywords    — JSON array of words/phrases treated like @mentions
 *   tz_offset_minutes     — minutes east of UTC, refreshed on save/login; used
 *                           to evaluate quiet hours server-side (push), since
 *                           sounds/toasts are gated client-side in local time.
 */
class NotifyPrefs
{
    public static function defaults(): array
    {
        return [
            'sound_master' => 1,
            'os_master' => 1,
            'previews' => 1,
            'quiet_hours_enabled' => 0,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
            'quiet_hours_days' => [],
            'highlight_keywords' => [],
            'tz_offset_minutes' => 0,
        ];
    }

    /** Full preference object for a viewer (guests get defaults). */
    public static function get(array $user): array
    {
        $d = self::defaults();
        if (Auth::isGuest($user)) {
            return $d;
        }
        $row = Database::row('SELECT * FROM user_notify_prefs WHERE user_id = ?', [(int) $user['id']]);
        if (!$row) {
            return $d;
        }
        $decode = fn ($s) => array_values(array_filter(array_map('strval', (array) json_decode((string) $s, true) ?: []), fn ($x) => $x !== ''));
        return [
            'sound_master' => (int) $row['sound_master'],
            'os_master' => (int) $row['os_master'],
            'previews' => (int) $row['previews'],
            'quiet_hours_enabled' => (int) $row['quiet_hours_enabled'],
            'quiet_hours_start' => (string) $row['quiet_hours_start'],
            'quiet_hours_end' => (string) $row['quiet_hours_end'],
            'quiet_hours_days' => $decode($row['quiet_hours_days']),
            'highlight_keywords' => $decode($row['highlight_keywords']),
            'tz_offset_minutes' => (int) $row['tz_offset_minutes'],
        ];
    }

    /** Persist a partial set of the fields above. Returns an error string or null. */
    public static function save(array $user, array $in): ?string
    {
        if (Auth::isGuest($user)) {
            return 'Guests cannot customize notifications.';
        }
        $cur = self::get($user);
        $time = static fn (?string $v, string $def) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $def;

        $next = [
            'sound_master' => isset($in['sound_master']) ? ((int) $in['sound_master'] ? 1 : 0) : (int) $cur['sound_master'],
            'os_master' => isset($in['os_master']) ? ((int) $in['os_master'] ? 1 : 0) : (int) $cur['os_master'],
            'previews' => isset($in['previews']) ? ((int) $in['previews'] ? 1 : 0) : (int) $cur['previews'],
            'quiet_hours_enabled' => isset($in['quiet_hours_enabled']) ? ((int) $in['quiet_hours_enabled'] ? 1 : 0) : (int) $cur['quiet_hours_enabled'],
            'quiet_hours_start' => $time($in['quiet_hours_start'] ?? null, (string) $cur['quiet_hours_start']),
            'quiet_hours_end' => $time($in['quiet_hours_end'] ?? null, (string) $cur['quiet_hours_end']),
            'tz_offset_minutes' => isset($in['tz_offset_minutes'])
                ? max(-720, min(840, (int) $in['tz_offset_minutes']))
                : (int) $cur['tz_offset_minutes'],
        ];
        $next['quiet_hours_days'] = json_encode(self::cleanIntList(self::asList($in['quiet_hours_days'] ?? $cur['quiet_hours_days']), 0, 6));
        $next['highlight_keywords'] = json_encode(self::cleanKeywordList(self::asList($in['highlight_keywords'] ?? $cur['highlight_keywords'])));

        Database::query(
            'INSERT INTO user_notify_prefs (user_id, sound_master, os_master, previews, quiet_hours_enabled, quiet_hours_start, quiet_hours_end, quiet_hours_days, highlight_keywords, tz_offset_minutes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))
             ON CONFLICT(user_id) DO UPDATE SET
               sound_master = excluded.sound_master,
               os_master = excluded.os_master,
               previews = excluded.previews,
               quiet_hours_enabled = excluded.quiet_hours_enabled,
               quiet_hours_start = excluded.quiet_hours_start,
               quiet_hours_end = excluded.quiet_hours_end,
               quiet_hours_days = excluded.quiet_hours_days,
               highlight_keywords = excluded.highlight_keywords,
               tz_offset_minutes = excluded.tz_offset_minutes,
               updated_at = datetime(\'now\')',
            [
                (int) $user['id'],
                $next['sound_master'], $next['os_master'], $next['previews'],
                $next['quiet_hours_enabled'],
                $next['quiet_hours_start'], $next['quiet_hours_end'],
                $next['quiet_hours_days'], $next['highlight_keywords'],
                $next['tz_offset_minutes'],
            ]
        );
        return null;
    }

    /** Is the current UTC time inside the user's quiet-hours window? */
    public static function quietHoursActive(array $prefs, ?int $nowUtc = null): bool
    {
        if (empty($prefs['quiet_hours_enabled'])) {
            return false;
        }
        $nowUtc ??= time();
        $offset = (int) ($prefs['tz_offset_minutes'] ?? 0);
        $local = $nowUtc + $offset * 60;
        // Weekday check (0=Sun..6=Sat) in the user's local day.
        $days = array_map('intval', (array) ($prefs['quiet_hours_days'] ?? []));
        if (!empty($days) && !in_array((int) gmdate('w', $local), $days, true)) {
            return false;
        }
        $hm = static fn (string $t) => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $start = $hm((string) ($prefs['quiet_hours_start'] ?? '22:00'));
        $end = $hm((string) ($prefs['quiet_hours_end'] ?? '08:00'));
        $mins = ((int) gmdate('G', $local)) * 60 + (int) gmdate('i', $local);
        if ($start === $end) {
            return false; // a zero-length window never blocks
        }
        return $start < $end ? ($mins >= $start && $mins < $end) : ($mins >= $start || $mins < $end);
    }

    /** Highlight keywords, lowercased, trimmed, deduped (max 25). */
    public static function keywords(array $prefs): array
    {
        $out = [];
        foreach ((array) ($prefs['highlight_keywords'] ?? []) as $k) {
            $k = mb_strtolower(trim((string) $k));
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
                if (count($out) >= 25) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Does the message match a highlight keyword? (case-insensitive, word-ish). */
    public static function matchesKeywords(array $prefs, string $content): bool
    {
        $kws = self::keywords($prefs);
        if (!$kws) {
            return false;
        }
        $hay = mb_strtolower((string) $content);
        foreach ($kws as $k) {
            if (mb_strpos($hay, $k) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function cleanIntList(mixed $v, int $min, int $max): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            $x = (int) $x;
            if ($x >= $min && $x <= $max && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
        sort($out);
        return $out;
    }

    /** Normalise a list-ish input: an array, or a JSON-encoded string (the
     *  web app + messenger send JSON strings, e.g. "[0,6]"). */
    private static function asList(mixed $v): array
    {
        if (is_string($v)) {
            $dec = json_decode($v, true);
            if (is_array($dec)) {
                return $dec;
            }
            return [$v];
        }
        return is_array($v) ? $v : [];
    }

    private static function cleanKeywordList(mixed $v): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            $x = trim((string) $x);
            if ($x !== '' && mb_strlen($x) <= 40 && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
        return array_slice($out, 0, 25);
    }
}
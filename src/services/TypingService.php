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
 * Cross-client "X is typing…" indicators.
 *
 * Clients POST a throttled /api/typing whenever the user types; rows are
 * stamped in SQLite and swept as part of the normal poll. A row is only ever
 * visible while younger than the grace window (8s) — no timers on the client,
 * no WebSocket event, works over poll/SSE/WS alike.
 *
 * Channels key by channel_id; DMs key by channel_id = NULL with actor_name
 * carrying the typer's nick (the DM counterpart polls dm=X and matches the
 * username, so guests and registered users both work).
 */
class TypingService
{
    /** Rows older than this (seconds) are never shown and are swept from the
     *  table — keeps the indicator honest between polls. */
    public const GRACE = 8;

    /** Record that an actor is typing in a conversation. */
    public static function touch(array $user, ?int $channelId, ?string $dmUsername): void
    {
        if ($channelId === null && ($dmUsername === null || $dmUsername === '')) {
            return;
        }
        $name = (string) ($user['username'] ?? $user['nick'] ?? '');
        if ($name === '') {
            return;
        }
        Database::query(
            'INSERT INTO typing_indicators (channel_id, actor_type, actor_id, actor_name, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))
             ON CONFLICT (channel_id, actor_type, actor_id)
             DO UPDATE SET actor_name = excluded.actor_name, updated_at = datetime(\'now\')',
            [$channelId, (int) ($user['guest'] ?? 0) === 1 ? 'guest' : 'user', (int) $user['id'], $name]
        );
    }

    /** Usernames currently typing in a channel (excludes the viewer). */
    public static function forChannel(int $channelId, int $viewerId): array
    {
        $rows = Database::all(
            "SELECT actor_name FROM typing_indicators
             WHERE channel_id = ? AND actor_id != ? AND updated_at >= datetime('now', '-' || ? || ' seconds')",
            [$channelId, $viewerId, self::GRACE]
        );
        return array_values(array_unique(array_map(fn ($r) => (string) $r['actor_name'], $rows)));
    }

    /** Username currently typing in a DM with `partner` (any typer in the
     *  channel_id = NULL space whose name matches the partner). */
    public static function forDm(string $partner): array
    {
        $rows = Database::all(
            "SELECT actor_name FROM typing_indicators
             WHERE channel_id IS NULL AND actor_name = ? AND updated_at >= datetime('now', '-' || ? || ' seconds')",
            [trim($partner), self::GRACE]
        );
        return array_values(array_unique(array_map(fn ($r) => (string) $r['actor_name'], $rows)));
    }

    /** Opportunistic sweep — cheap enough to run on every poll. */
    public static function sweep(): void
    {
        Database::query(
            "DELETE FROM typing_indicators WHERE updated_at < datetime('now', '-' || ? || ' seconds')",
            [self::GRACE * 3]
        );
    }
}
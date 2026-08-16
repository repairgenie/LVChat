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

final class BanService
{
    /** Does a user match a channel ban or quiet ban (active)? */
    public static function channelBanFor(array $channel, array $user, string $kind = 'channel_ban'): ?array
    {
        foreach (Database::all(
            "SELECT * FROM bans WHERE active = 1 AND channel_id = ? AND kind = ?
             AND (expires_at IS NULL OR expires_at > datetime('now'))",
            [$channel['id'], $kind]
        ) as $ban) {
            if ($ban['target_user_id'] && (int) $ban['target_user_id'] === (int) $user['id']) {
                return $ban;
            }
            if (self::userMatchesMask($ban['mask'], $user)) {
                return $ban;
            }
        }
        return null;
    }

    public static function akickFor(array $channel, array $user): ?array
    {
        foreach (Database::all('SELECT * FROM akick WHERE channel_id = ?', [$channel['id']]) as $ak) {
            if ($ak['target_user_id'] && (int) $ak['target_user_id'] === (int) $user['id']) {
                return $ak;
            }
            if ($ak['target'] && self::userMatchesMask($ak['target'], $user)) {
                return $ak;
            }
        }
        return null;
    }

    public static function userMatchesMask(string $mask, array $user): bool
    {
        $mask = trim($mask);
        if ($mask === '') {
            return false;
        }
        $host = $user['vhost'] ?? $user['username'];
        $needles = [
            $user['username'],                                   // nick
            strtolower($user['username']) . '!*@*',              // nick!user@host
            strtolower($user['username']) . '!*@' . $host,       // full-ish
            '*' . '!*@' . $host,
        ];
        foreach ($needles as $n) {
            if (Auth::maskMatch($mask, $n)) {
                return true;
            }
        }
        if (!empty($user['last_ip']) && Auth::ipMatch($mask, (string) $user['last_ip'])) {
            return true;
        }
        return false;
    }

    /** Add a ban. $kind: channel_ban|quiet|kline|gline|zline|shun. Returns error string or null.
     *  $setBy is a registered user id; pass null for guest actors (their guest
     *  id must never land in the users-keyed set_by column). */
    public static function addBan(
        string $kind,
        int|string|null $channelId,
        string $mask,
        ?string $reason,
        ?int $durationSeconds,
        ?int $setBy,
        ?int $targetUserId = null
    ): ?string {
        $mask = trim($mask);
        if ($mask === '') {
            return 'A target mask is required.';
        }
        $expires = $durationSeconds !== null ? gmdate('Y-m-d H:i:s', time() + $durationSeconds) : null;
        Database::query(
            'INSERT INTO bans (kind, channel_id, mask, target_user_id, reason, set_by, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$kind, $channelId, $mask, $targetUserId, $reason ?: '', $setBy, $expires]
        );
        log_audit('ban_add', $mask, "$kind / " . ($reason ?: 'no reason'));
        return null;
    }

    public static function remove(int $banId): void
    {
        Database::query('DELETE FROM bans WHERE id = ?', [$banId]);
        log_audit('ban_remove', 'ban#' . $banId);
    }

    public static function removeByMask(string $kind, int|string|null $channelId, string $mask): bool
    {
        $stmt = Database::query(
            'DELETE FROM bans WHERE kind = ? AND channel_id IS ? AND mask = ? COLLATE NOCASE',
            [$kind, $channelId, $mask]
        );
        return $stmt->rowCount() > 0;
    }

    public static function activeBans(string $kind): array
    {
        return Database::all(
            'SELECT b.*, s.username AS set_by_name FROM bans b
             LEFT JOIN users s ON s.id = b.set_by
             WHERE b.active = 1 AND b.channel_id IS NULL AND b.kind = ?
             ORDER BY b.set_at DESC',
            [$kind]
        );
    }

    public static function channelBans(int|string $channelId): array
    {
        return Database::all(
            'SELECT b.*, s.username AS set_by_name FROM bans b
             LEFT JOIN users s ON s.id = b.set_by
             WHERE b.channel_id = ? ORDER BY b.set_at DESC',
            [$channelId]
        );
    }

    /**
     * Active q-line forbidding a nickname (masks match via Auth::maskMatch).
     * Used to block registration, /nick, and /sanick targets.
     */
    public static function nickForbidden(string $nick): ?array
    {
        $nick = trim($nick);
        if ($nick === '') {
            return null;
        }
        foreach (Database::all(
            "SELECT * FROM bans WHERE active = 1 AND kind = 'qline' AND channel_id IS NULL
             AND (expires_at IS NULL OR expires_at > datetime('now'))"
        ) as $ban) {
            if (Auth::maskMatch($ban['mask'], $nick)) {
                return $ban;
            }
        }
        return null;
    }

    /**
     * Active c-line (channel q-line) forbidding a channel name. Masks may be
     * given with or without the leading # (e.g. "#ads*" or "ads*") and match
     * against both forms of the proposed channel name.
     */
    public static function channelNameForbidden(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $bare = ltrim($name, '#&');
        foreach (Database::all(
            "SELECT * FROM bans WHERE active = 1 AND kind = 'cqline' AND channel_id IS NULL
             AND (expires_at IS NULL OR expires_at > datetime('now'))"
        ) as $ban) {
            if (Auth::maskMatch($ban['mask'], $name) || Auth::maskMatch($ban['mask'], $bare)) {
                return $ban;
            }
        }
        return null;
    }

    /** Check flood/spamfilter/shun style restrictions on a send. Returns error string or null. */
    public static function sendBlocked(array $user, string $content, string $target): ?string
    {
        $shun = Database::row(
            "SELECT * FROM bans WHERE active = 1 AND kind = 'shun' AND channel_id IS NULL
             AND (expires_at IS NULL OR expires_at > datetime('now'))"
        );
        if ($shun && self::userMatchesMask($shun['mask'], $user)) {
            return 'You are shunned from speaking.';
        }
        if (config_get('spamfilter_enabled', '1') === '1') {
            foreach (Database::all('SELECT * FROM spamfilters WHERE enabled = 1') as $f) {
                if (!str_contains($f['targets'], $target)) {
                    continue;
                }
                $hit = $f['match_type'] === 'regex'
                    ? @preg_match($f['match'], $content) === 1
                    : Auth::maskMatch(str_replace('_', ' ', $f['match']), $content);
                if ($hit) {
                    ModerationService::record($user, 'spamfilter', 'block', (string) $f['match'], $content, $target);
                    return 'Your message was blocked by a spam filter' . ($f['reason'] ? ': ' . $f['reason'] : '.');
                }
            }
        }
        return null;
    }

    public static function canPost(array $channel, array $user, array $member): ?string
    {
        if ((int) $channel['moderated'] === 1 && level_weight($member['level'] ?? 'normal') < level_weight('voice')) {
            return 'This channel is moderated (+m); you need voice or higher to speak.';
        }
        $quiet = self::channelBanFor($channel, $user, 'quiet');
        if ($quiet) {
            return 'You are muted (+q) in this channel.';
        }
        return null;
    }
}

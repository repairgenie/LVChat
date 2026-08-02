<?php

declare(strict_types=1);

final class AccessService
{
    public static function member(int|string $channelId, int|array $actor): ?array
    {
        if (is_array($actor)) {
            if (Auth::isGuest($actor)) {
                return Database::row(
                    'SELECT * FROM channel_members WHERE channel_id = ? AND guest_id = ?',
                    [$channelId, $actor['id']]
                );
            }
            return Database::row(
                'SELECT * FROM channel_members WHERE channel_id = ? AND user_id = ?',
                [$channelId, $actor['id']]
            );
        }
        return Database::row(
            'SELECT * FROM channel_members WHERE channel_id = ? AND user_id = ?',
            [$channelId, $actor]
        );
    }

    /** Effective level of an actor in a channel: access-list first, then membership, else 'normal'. */
    public static function effectiveLevel(int|string $channelId, int|array $actor): string
    {
        $id = is_array($actor) ? (int) $actor['id'] : (int) $actor;
        $access = Database::row(
            'SELECT level FROM channel_access WHERE channel_id = ? AND user_id = ?',
            [$channelId, $id]
        );
        if ($access) {
            return $access['level'];
        }
        $m = self::member($channelId, $actor);
        if (!$m) {
            return 'normal';
        }
        if (level_weight($m['level']) > level_weight($access['level'] ?? 'normal')) {
            return $m['level'];
        }
        return $access['level'] ?? $m['level'];
    }

    public static function setLevel(int|string $channelId, int $userId, string $level): void
    {
        Database::query(
            'INSERT INTO channel_members (channel_id, user_id, level)
             VALUES (?, ?, ?)
             ON CONFLICT DO UPDATE SET level = excluded.level',
            [$channelId, $userId, $level]
        );
        Database::query('DELETE FROM channel_access WHERE channel_id = ? AND user_id = ?', [$channelId, $userId]);
    }

    /**
     * Check whether $actor may set $target to $newLevel inside $channel.
     * Standard IRC rules: you may only modify users below your own level, and you may
     * only grant up to your own level (a half-op grants voice; an op grants voice/half-op/op;
     * admin grants up to admin; founder grants up to admin; server admins may do anything).
     */
    public static function canSetLevel(array $channel, array $actor, array $target, string $newLevel): bool|string
    {
        if (Auth::isAdmin($actor)) {
            return true;
        }
        $actorWeight = level_weight(AccessService::effectiveLevel($channel['id'], (int) $actor['id']));
        $targetWeight = level_weight(AccessService::effectiveLevel($channel['id'], (int) $target['id']));
        if ($targetWeight >= $actorWeight) {
            return 'You cannot change the level of a user at or above your own level.';
        }
        $newWeight = level_weight($newLevel);
        // Demotion to 'normal' is fine (already guarded by the target-weight check).
        if ($newWeight === 0) {
            return true;
        }
        $maxGrantable = match ($actorWeight) {
            0, 1 => 0,  // normal / voice: cannot grant any level
            2 => 1,     // half-op: can grant voice only
            3 => 3,     // op: can grant voice, half-op, op
            4 => 4,     // admin: can grant up to admin
            5 => 4,     // founder: can grant up to admin
            default => 0,
        };
        if ($newWeight > $maxGrantable) {
            return 'You cannot grant that level.';
        }
        return true;
    }

    /** Add to the persistent ChanServ-style access list (works even if the user is not currently in the channel). */
    public static function addAccess(int|string $channelId, int $userId, string $level, int $addedBy): void
    {
        Database::query(
            'INSERT OR REPLACE INTO channel_access (channel_id, user_id, level, added_by)
             VALUES (?, ?, ?, ?)',
            [$channelId, $userId, $level, $addedBy]
        );
    }

    public static function removeAccess(int|string $channelId, int $userId): void
    {
        Database::query('DELETE FROM channel_access WHERE channel_id = ? AND user_id = ?', [$channelId, $userId]);
    }

    public static function accessList(int|string $channelId): array
    {
        return Database::all(
            'SELECT ca.*, u.username FROM channel_access ca
             JOIN users u ON u.id = ca.user_id
             WHERE ca.channel_id = ?
             ORDER BY ca.added_at',
            [$channelId]
        );
    }
}

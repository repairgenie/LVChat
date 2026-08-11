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

final class FriendService
{
    public static function relationship(int $userId, int $targetId): ?array
    {
        return Database::row(
            'SELECT * FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)',
            [$userId, $targetId, $targetId, $userId]
        );
    }

    public static function isBlocked(int $userId, int $targetId): bool
    {
        $r = self::relationship($userId, $targetId);
        if (!$r || $r['status'] !== 'blocked') {
            return false;
        }
        return (int) $r['user_id'] === $userId;
    }

    public static function isBlockedEither(int $a, int $b): bool
    {
        return (bool) Database::row(
            "SELECT 1 FROM friendships WHERE status = 'blocked' AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))",
            [$a, $b, $b, $a]
        );
    }

    public static function isFriend(int $userId, int $targetId): bool
    {
        return (bool) Database::row(
            "SELECT 1 FROM friendships WHERE status = 'accepted' AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))",
            [$userId, $targetId, $targetId, $userId]
        );
    }

    public static function status(int $userId, int $targetId): string
    {
        $r = self::relationship($userId, $targetId);
        if (!$r) {
            return 'none';
        }
        if ($r['status'] === 'accepted') {
            return 'friend';
        }
        if ($r['status'] === 'blocked') {
            return (int) $r['user_id'] === $userId ? 'blocked_by_me' : 'blocked_me';
        }
        return (int) $r['user_id'] === $userId ? 'outgoing' : 'incoming';
    }

    public static function sendRequest(int $userId, int $targetId): array
    {
        if ($userId === $targetId) {
            return ['error' => 'You cannot friend yourself.'];
        }
        if ((int) Database::scalar('SELECT guest FROM users WHERE id = ?', [$targetId]) === 1) {
            return ['error' => 'Guests cannot receive friend requests.'];
        }
        $existing = self::relationship($userId, $targetId);
        if ($existing) {
            if ($existing['status'] === 'accepted') {
                return ['error' => 'Already friends.'];
            }
            if ($existing['status'] === 'blocked') {
                return ['error' => 'A block exists between you.'];
            }
            if ((int) $existing['user_id'] === $userId) {
                return ['error' => 'Request already pending.'];
            }
            Database::query(
                "UPDATE friendships SET status = 'accepted', updated_at = datetime('now') WHERE id = ?",
                [$existing['id']]
            );
            self::notify('friend_accepted', $userId, $targetId);
            return ['ok' => true, 'status' => 'friend'];
        }
        Database::query(
            "INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')",
            [$userId, $targetId]
        );
        self::notify('friend_request', $targetId, $userId);
        return ['ok' => true, 'status' => 'outgoing'];
    }

    public static function acceptRequest(int $userId, int $requesterId): array
    {
        $r = Database::row(
            "SELECT * FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'pending'",
            [$requesterId, $userId]
        );
        if (!$r) {
            return ['error' => 'No pending request.'];
        }
        Database::query(
            "UPDATE friendships SET status = 'accepted', updated_at = datetime('now') WHERE id = ?",
            [$r['id']]
        );
        self::notify('friend_accepted', $requesterId, $userId);
        return ['ok' => true];
    }

    public static function declineRequest(int $userId, int $requesterId): array
    {
        Database::query(
            "DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'pending'",
            [$requesterId, $userId]
        );
        return ['ok' => true];
    }

    public static function removeFriend(int $userId, int $friendId): array
    {
        Database::query(
            "DELETE FROM friendships WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = 'accepted'",
            [$userId, $friendId, $friendId, $userId]
        );
        return ['ok' => true];
    }

    public static function cancelRequest(int $userId, int $targetId): array
    {
        Database::query(
            "DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'pending'",
            [$userId, $targetId]
        );
        return ['ok' => true];
    }

    public static function blockUser(int $userId, int $targetId): array
    {
        if ($userId === $targetId) {
            return ['error' => 'You cannot block yourself.'];
        }
        Database::query(
            'DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)',
            [$userId, $targetId, $targetId, $userId]
        );
        Database::query(
            "INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'blocked')",
            [$userId, $targetId]
        );
        return ['ok' => true];
    }

    public static function unblockUser(int $userId, int $targetId): array
    {
        Database::query(
            "DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = 'blocked'",
            [$userId, $targetId]
        );
        return ['ok' => true];
    }

    public static function getFriends(int $userId): array
    {
        return Database::all(
            "SELECT u.id, u.username, u.avatar, u.role, u.away, u.status_mode, u.custom_status, u.last_seen, f.updated_at AS friends_since,
                    (SELECT 1 FROM user_mutes um WHERE um.user_id = ? AND um.muted_user_id = u.id) AS muted
             FROM friendships f
             JOIN users u ON u.id = CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END
             WHERE ((f.user_id = ? AND f.friend_id != ?) OR (f.friend_id = ? AND f.user_id != ?))
               AND f.status = 'accepted'
             ORDER BY u.username COLLATE NOCASE",
            [$userId, $userId, $userId, $userId, $userId, $userId]
        );
    }

    public static function getFriendsWithStatus(int $userId): array
    {
        $friends = self::getFriends($userId);
        foreach ($friends as &$f) {
            $f = array_merge($f, Auth::statusInfo($f));
            $f['muted'] = (int) ($f['muted'] ?? 0);
        }
        return $friends;
    }

    public static function getPendingIncoming(int $userId): array
    {
        return Database::all(
            "SELECT u.id, u.username, u.avatar, f.created_at
             FROM friendships f
             JOIN users u ON u.id = f.user_id
             WHERE f.friend_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC",
            [$userId]
        );
    }

    public static function getPendingOutgoing(int $userId): array
    {
        return Database::all(
            "SELECT u.id, u.username, u.avatar, f.created_at
             FROM friendships f
             JOIN users u ON u.id = f.friend_id
             WHERE f.user_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC",
            [$userId]
        );
    }

    public static function getBlocked(int $userId): array
    {
        return Database::all(
            "SELECT u.id, u.username, u.avatar, f.created_at AS blocked_at
             FROM friendships f
             JOIN users u ON u.id = f.friend_id
             WHERE f.user_id = ? AND f.status = 'blocked'
             ORDER BY u.username COLLATE NOCASE",
            [$userId]
        );
    }

    public static function allForUser(int $userId): array
    {
        return [
            'friends' => self::getFriendsWithStatus($userId),
            'incoming' => self::getPendingIncoming($userId),
            'outgoing' => self::getPendingOutgoing($userId),
            'blocked' => self::getBlocked($userId),
        ];
    }

    private static function notify(string $kind, int $recipientId, int $senderId): void
    {
        Database::query(
            'INSERT INTO notifications (user_id, kind, sender_id) VALUES (?, ?, ?)',
            [$recipientId, $kind, $senderId]
        );
    }
}

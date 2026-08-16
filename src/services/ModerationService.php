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
 * Moderation queue + staff-only account timeline.
 *
 * Two streams are kept:
 *  - `moderation_events`: every time a user trips a filter/bad-word, or is the
 *    target of a moderation action (kick, channel ban, kline/gline/zline/shun).
 *  - `user_notes`: actions taken against an account by staff/admins plus free-form
 *    staff comments, viewable only by admins and staff.
 */
final class ModerationService
{
    public const ACTION_LABELS = [
        'note' => 'Note',
        'approve' => 'Approved',
        'pending' => 'Set pending',
        'suspend' => 'Suspended',
        'activate' => 'Activated',
        'ban' => 'Banned',
        'unban' => 'Unbanned',
        'kick' => 'Kicked',
        'kline' => 'K-line',
        'gline' => 'G-line',
        'zline' => 'Z-line',
        'shun' => 'Shun',
        'warn' => 'Warning',
        'role' => 'Role changed',
        'reset_password' => 'Password reset',
        'delete' => 'Account deleted',
        'report' => 'Report action',
        'zline_ip' => 'Z-line (IP)',
    ];

    /** Staff = server admins plus users with the `staff` role. */
    public static function isStaff(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'staff';
    }

    /** Guard used by moderation-facing pages: staff or admin, else 403. */
    public static function requireStaff(): array
    {
        $u = Auth::require();
        if (!self::isStaff($u)) {
            http_response_code(403);
            exit('Forbidden');
        }
        return $u;
    }

    /**
     * Whether the account may speak/act in chat. Returns an error message when
     * the account is pending approval (log in allowed, chat restricted) or
     * suspended. Guests are never restricted here.
     */
    public static function restriction(array $user): ?string
    {
        if (Auth::isGuest($user)) {
            return null;
        }
        $status = (string) ($user['status'] ?? 'active');
        if ($status === 'pending') {
            return 'Your account is pending admin approval. You can browse, but you cannot chat until it is approved.';
        }
        if ($status === 'suspended') {
            $reason = trim((string) ($user['status_reason'] ?? ''));
            return 'This account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : '');
        }
        return null;
    }

    /** Record a moderation event. `$user` may be a registered user or a guest actor. */
    public static function record(array $user, string $kind, string $action, string $match, string $content = '', string $target = '', ?int $channelId = null): void
    {
        if (MessageService::isGuest($user)) {
            Database::query(
                'INSERT INTO moderation_events (guest_id, kind, action, match, content, target, channel_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [(int) $user['id'], $kind, $action, $match, $content, $target, $channelId]
            );
            return;
        }
        Database::query(
            'INSERT INTO moderation_events (user_id, kind, action, match, content, target, channel_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $user['id'], $kind, $action, $match, $content, $target, $channelId]
        );
    }

    /** Append a staff-only action/comment to an account's timeline. */
    public static function note(int $targetUserId, array $actor, string $action, string $reason): void
    {
        if ($targetUserId < 1 || !Database::row('SELECT id FROM users WHERE id = ?', [$targetUserId])) {
            return; // never write a timeline entry for a non-existent account
        }
        // actor_id references users — a guest actor's id must never be stored
        // there (guest/user id-collision would attribute the note to someone
        // else). Null it out for guests.
        $actorId = (int) ($actor['guest'] ?? 0) === 1 ? null : (int) $actor['id'];
        Database::query(
            'INSERT INTO user_notes (user_id, actor_id, action, reason) VALUES (?, ?, ?, ?)',
            [$targetUserId, $actorId, $action, mb_substr($reason, 0, 1000)]
        );
    }

    /** Full timeline for an account (newest first), joined with actor names. */
    public static function history(int $userId): array
    {
        return Database::all(
            'SELECT n.*, a.username AS actor_name, a.role AS actor_role FROM user_notes n
             LEFT JOIN users a ON a.id = n.actor_id
             WHERE n.user_id = ?
             ORDER BY n.id DESC LIMIT 200',
            [$userId]
        );
    }

    /** Moderation events that reference this account (filter hits + actions). */
    public static function eventsForUser(int $userId, ?int $guestId = null, int $limit = 100): array
    {
        $rows = [];
        if ($userId > 0) {
            $rows = Database::all(
                'SELECT * FROM moderation_events WHERE user_id = ? ORDER BY id DESC LIMIT ?',
                [$userId, $limit]
            );
        } elseif ($guestId > 0) {
            $rows = Database::all(
                'SELECT * FROM moderation_events WHERE guest_id = ? ORDER BY id DESC LIMIT ?',
                [$guestId, $limit]
            );
        }
        return $rows;
    }

    /**
     * Set an account's status with a reason and log it on the timeline.
     * `suspend` also kills the account's live sessions.
     */
    public static function setStatus(int $userId, string $status, ?string $reason, array $actor, bool $writeNote = true, string $action = ''): void
    {
        if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
            $status = 'active';
        }
        Database::query(
            'UPDATE users SET status = ?, status_reason = ? WHERE id = ?',
            [$status, $reason !== null && $reason !== '' ? mb_substr($reason, 0, 300) : null, $userId]
        );
        if ($status === 'suspended') {
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        }
        if ($writeNote) {
            if ($action === '') {
                $action = ['active' => 'activate', 'pending' => 'pending', 'suspended' => 'suspend'][$status] ?? 'note';
            }
            self::note($userId, $actor, $action, (string) $reason);
        }
        log_audit('user_status', 'user#' . $userId, "$status / " . ($reason ?: 'no reason'));
    }

    /** Human-readable label for an action kind (also covers moderation_events kinds). */
    public static function label(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
}

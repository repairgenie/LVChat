<?php

declare(strict_types=1);

final class ContactGroupService
{
    /** Validate + normalize a group name. Returns an error string or null. */
    public static function validName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Group name is required.';
        }
        if (mb_strlen($name) > 60) {
            return 'Group name is too long (max 60 characters).';
        }
        return null;
    }

    public static function create(int $userId, string $name): array
    {
        $name = trim($name);
        $err = self::validName($name);
        if ($err) {
            return ['error' => $err];
        }
        $dup = Database::scalar('SELECT id FROM contact_groups WHERE user_id = ? AND name = ? COLLATE NOCASE', [$userId, $name]);
        if ($dup) {
            return ['error' => 'You already have a group with that name.'];
        }
        $pos = (int) Database::scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM contact_groups WHERE user_id = ?', [$userId]);
        Database::query(
            'INSERT INTO contact_groups (user_id, name, position) VALUES (?, ?, ?)',
            [$userId, $name, $pos]
        );
        $id = (int) Database::lastId();
        return ['ok' => true, 'group' => self::row((int) $id, $userId)];
    }

    public static function rename(int $userId, int $id, string $name): array
    {
        $name = trim($name);
        $err = self::validName($name);
        if ($err) {
            return ['error' => $err];
        }
        $group = Database::row('SELECT * FROM contact_groups WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$group) {
            return ['error' => 'Group not found.'];
        }
        $dup = Database::scalar('SELECT id FROM contact_groups WHERE user_id = ? AND name = ? COLLATE NOCASE AND id != ?', [$userId, $name, $id]);
        if ($dup) {
            return ['error' => 'You already have a group with that name.'];
        }
        Database::query('UPDATE contact_groups SET name = ? WHERE id = ?', [$name, $id]);
        return ['ok' => true, 'group' => self::row($id, $userId)];
    }

    public static function delete(int $userId, int $id): array
    {
        Database::query('DELETE FROM contact_groups WHERE id = ? AND user_id = ?', [$id, $userId]);
        return ['ok' => true];
    }

    /** Add an accepted friend to a group. Returns an error string or null. */
    public static function addMember(int $userId, int $groupId, int $friendId): ?string
    {
        $group = Database::row('SELECT id FROM contact_groups WHERE id = ? AND user_id = ?', [$groupId, $userId]);
        if (!$group) {
            return 'Group not found.';
        }
        if ($friendId === $userId) {
            return 'You cannot add yourself to a group.';
        }
        $friend = Database::row('SELECT id, guest FROM users WHERE id = ?', [$friendId]);
        if (!$friend || (int) ($friend['guest'] ?? 0) === 1) {
            return 'User not found.';
        }
        if (!FriendService::isFriend($userId, $friendId)) {
            return 'You can only group people you are friends with.';
        }
        $exists = Database::row(
            'SELECT id FROM contact_group_members WHERE group_id = ? AND friend_id = ?',
            [$groupId, $friendId]
        );
        if ($exists) {
            return 'That person is already in this group.';
        }
        Database::query(
            'INSERT INTO contact_group_members (group_id, friend_id) VALUES (?, ?)',
            [$groupId, $friendId]
        );
        return null;
    }

    public static function removeMember(int $userId, int $groupId, int $friendId): array
    {
        Database::query(
            'DELETE FROM contact_group_members WHERE group_id = ? AND friend_id = ?
             AND group_id IN (SELECT id FROM contact_groups WHERE user_id = ?)',
            [$groupId, $friendId, $userId]
        );
        return ['ok' => true];
    }

    /** Fetch one group (owned by $userId) as a client-shaped array. */
    public static function row(int $id, int $userId): ?array
    {
        $g = Database::row('SELECT * FROM contact_groups WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$g) {
            return null;
        }
        return [
            'id' => (int) $g['id'],
            'name' => $g['name'],
            'position' => (int) $g['position'],
            'members' => self::members((int) $g['id']),
        ];
    }

    /** All groups for a user with member friend objects, ordered by position then name. */
    public static function allForUser(int $userId): array
    {
        $rows = Database::all(
            'SELECT id FROM contact_groups WHERE user_id = ? ORDER BY position ASC, name COLLATE NOCASE ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::row((int) $r['id'], $userId);
        }
        return $out;
    }

    /** Member friend objects for a group: id, username, avatar, role, is_online, away. */
    public static function members(int $groupId): array
    {
        $rows = Database::all(
            'SELECT u.id, u.username, u.avatar, u.role, u.away, u.status_mode, u.custom_status, u.last_seen
             FROM contact_group_members m
             JOIN users u ON u.id = m.friend_id
             WHERE m.group_id = ?
             ORDER BY u.username COLLATE NOCASE',
            [$groupId]
        );
        foreach ($rows as &$m) {
            $m = array_merge($m, Auth::statusInfo($m));
        }
        return $rows;
    }

    /** Friend ids that are already members of any of the user's groups. */
    public static function assignedFriendIds(int $userId): array
    {
        $rows = Database::all(
            'SELECT DISTINCT m.friend_id FROM contact_group_members m
             JOIN contact_groups g ON g.id = m.group_id
             WHERE g.user_id = ?',
            [$userId]
        );
        return array_map(fn ($r) => (int) $r['friend_id'], $rows);
    }
}

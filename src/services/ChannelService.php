<?php

declare(strict_types=1);

final class ChannelService
{
    public static function nameToSlug(string $name): string
    {
        return strtolower(preg_replace('/^[#&!]+/', '', trim($name)) ?? '');
    }

    public static function find(string $name): ?array
    {
        return Database::row('SELECT * FROM channels WHERE name = ? COLLATE NOCASE', [$name]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', [$slug]);
    }

    public static function validateName(string $name): string|bool
    {
        $name = trim($name);
        if (!preg_match('/^[#&][A-Za-z0-9_\-\[\]{}^`|\\\\]+$/', $name)) {
            return 'Channel names must start with # or & and use letters, numbers or - _ [ ] { } ^ ` |.';
        }
        if (strlen($name) > 64) {
            return 'Channel names must be 64 characters or fewer.';
        }
        return true;
    }

    public static function create(array $user, string $name): array|string
    {
        if (Auth::isGuest($user)) {
            return 'Guests can only join existing channels. Create an account to make new channels.';
        }
        $err = self::validateName($name);
        if ($err !== true) {
            return $err;
        }
        if (self::find($name)) {
            return 'That channel already exists.';
        }
        $max = (int) (config_get('max_channels_per_user', '100') ?? 100);
        $owned = (int) Database::scalar('SELECT COUNT(*) FROM channels WHERE owner_id = ?', [$user['id']]);
        if ($owned >= $max) {
            return "You have reached the channel limit ($max).";
        }
        $slug = self::nameToSlug($name);
        // Channels start as temporary: registered_at is NULL until /register is used.
        // An empty, unregistered channel is deleted automatically (see afterMemberRemoval).
        Database::query(
            'INSERT INTO channels (name, slug, owner_id) VALUES (?, ?, ?)',
            [$name, $slug, $user['id']]
        );
        $id = (string) Database::lastId();
        Database::query(
            'INSERT OR REPLACE INTO channel_members (channel_id, user_id, level) VALUES (?, ?, "founder")',
            [$id, $user['id']]
        );
        log_audit('channel_create', $name);
        return Database::row('SELECT * FROM channels WHERE id = ?', [$id]);
    }

    /** Returns [ok, reason|'need_key'|'need_invite', needsKey]. */
    public static function joinStatus(array $channel, array $user, ?string $key = null): array
    {
        $channel = Database::row('SELECT * FROM channels WHERE id = ?', [$channel['id']]) ?: $channel;
        $member = AccessService::member($channel['id'], $user);
        if ($member) {
            return ['ok' => true, 'reason' => 'already_member', 'needsKey' => false];
        }
        if ((int) $channel['forbidden'] === 1) {
            return ['ok' => false, 'reason' => 'Channel is forbidden.', 'needsKey' => false];
        }
        if ($channel['visibility'] === 'staff' && !in_array($user['role'], ['admin', 'staff'], true)) {
            return ['ok' => false, 'reason' => 'This channel is restricted to staff.', 'needsKey' => false];
        }
        $ban = BanService::channelBanFor($channel, $user);
        if ($ban) {
            return ['ok' => false, 'reason' => 'You are banned from this channel: ' . ($ban['reason'] ?: 'no reason'), 'needsKey' => false];
        }
        if (BanService::akickFor($channel, $user)) {
            return ['ok' => false, 'reason' => 'You are on the auto-kick (AKICK) list.', 'needsKey' => false];
        }
        if ($channel['member_limit'] && (int) $channel['member_limit'] <= (int) Database::scalar(
            'SELECT COUNT(*) FROM channel_members WHERE channel_id = ?',
            [$channel['id']]
        )) {
            return ['ok' => false, 'reason' => 'Channel is full (limit reached).', 'needsKey' => false];
        }

        $needsKey = !empty($channel['key_hash']) && !$member;
        if ($needsKey) {
            if ($key === null) {
                return ['ok' => false, 'reason' => 'need_key', 'needsKey' => true];
            }
            if (!password_verify($key, $channel['key_hash'])) {
                return ['ok' => false, 'reason' => 'Incorrect channel key.', 'needsKey' => true];
            }
        }

        $needInvite = (int) $channel['invite_only'] === 1 || $channel['visibility'] === 'secret';
        if ($needInvite) {
            $invited = Database::row(
                'SELECT * FROM invites WHERE channel_id = ? AND user_id = ?',
                [$channel['id'], $user['id']]
            );
            if (!$invited) {
                return ['ok' => false, 'reason' => 'This channel is invite-only.', 'needsKey' => false];
            }
        }
        return ['ok' => true, 'reason' => '', 'needsKey' => $needsKey];
    }

    public static function join(array $channel, array $user): void
    {
        $level = 'normal';
        $access = Database::row(
            'SELECT level FROM channel_access WHERE channel_id = ? AND user_id = ?',
            [$channel['id'], $user['id']]
        );
        if ($access) {
            $level = $access['level'];
        }
        if (Auth::isGuest($user)) {
            Database::query(
                'INSERT OR IGNORE INTO channel_members (channel_id, guest_id, level) VALUES (?, ?, ?)',
                [$channel['id'], $user['id'], $level]
            );
        } else {
            Database::query(
                'INSERT OR IGNORE INTO channel_members (channel_id, user_id, level) VALUES (?, ?, ?)',
                [$channel['id'], $user['id'], $level]
            );
            Database::query('DELETE FROM invites WHERE channel_id = ? AND user_id = ?', [$channel['id'], $user['id']]);
        }
        MessageService::system($channel['id'], 'join', $user['username'] . ' has joined ' . $channel['name']);
    }

    public static function part(array $channel, array $user, ?string $reason): void
    {
        if (Auth::isGuest($user)) {
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND guest_id = ?', [$channel['id'], $user['id']]);
        } else {
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$channel['id'], $user['id']]);
        }
        $msg = $user['username'] . ' has left ' . $channel['name'];
        if ($reason) {
            $msg .= ' (' . $reason . ')';
        }
        MessageService::system($channel['id'], 'part', $msg);
        self::afterMemberRemoval($channel['id']);
    }

    /**
     * IRC-style cleanup after anyone stops being a member:
     *  - an empty, UNREGISTERED channel is deleted;
     *  - if an unregistered channel's owner leaves but others remain, founder
     *    passes to the member who has been there the longest.
     */
    public static function afterMemberRemoval(int|string $channelId): void
    {
        $ch = Database::row('SELECT * FROM channels WHERE id = ?', [$channelId]);
        if (!$ch) {
            return;
        }
        $count = (int) Database::scalar('SELECT COUNT(*) FROM channel_members WHERE channel_id = ?', [$channelId]);
        if ($count === 0) {
            if (empty($ch['registered_at'])) {
                Database::query('DELETE FROM channels WHERE id = ?', [$channelId]);
                log_audit('channel_auto_delete', $ch['name']);
            }
            return;
        }
        if (empty($ch['registered_at']) && $ch['owner_id'] !== null && !AccessService::member($channelId, ['id' => (int) $ch['owner_id'], 'guest' => 0])) {
            // Founder only ever passes to a registered user, never to a guest.
            $heir = Database::row(
                'SELECT user_id FROM channel_members WHERE channel_id = ? AND user_id IS NOT NULL ORDER BY joined_at ASC, user_id ASC LIMIT 1',
                [$channelId]
            );
            if ($heir) {
                Database::query('UPDATE channels SET owner_id = ? WHERE id = ?', [$heir['user_id'], $channelId]);
                Database::query(
                    'UPDATE channel_members SET level = "founder" WHERE channel_id = ? AND user_id = ?',
                    [$channelId, $heir['user_id']]
                );
            }
        }
    }

    public static function isRegistered(array $channel): bool
    {
        return !empty($channel['registered_at']);
    }

    public static function members(int|string $channelId): array
    {
        return Database::all(
            "SELECT COALESCE(u.id, g.id) AS id, COALESCE(u.username, g.nick) AS username,
                    u.away, u.away_at, COALESCE(u.last_seen, g.last_seen) AS last_seen,
                    COALESCE(u.role, 'user') AS role, COALESCE(u.bot, 0) AS bot,
                    CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END AS guest,
                    cm.level,
                    (SELECT r.color FROM roles r WHERE r.id = u.role_id) AS role_color
             FROM channel_members cm
             LEFT JOIN users u ON u.id = cm.user_id
             LEFT JOIN guests g ON g.id = cm.guest_id
             WHERE cm.channel_id = ?
             ORDER BY cm.level DESC, COALESCE(u.username, g.nick) ASC",
            [$channelId]
        );
    }

    public static function memberCount(int|string $channelId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM channel_members WHERE channel_id = ?', [$channelId]);
    }

    public static function joinedChannelNames(array $actor): array
    {
        if (Auth::isGuest($actor)) {
            return Database::all(
                'SELECT c.id, c.name, c.slug, c.topic, c.visibility, c.moderated
                 FROM channel_members cm JOIN channels c ON c.id = cm.channel_id
                 WHERE cm.guest_id = ?
                 ORDER BY c.name COLLATE NOCASE',
                [$actor['id']]
            );
        }
        return Database::all(
            'SELECT c.id, c.name, c.slug, c.topic, c.visibility, c.moderated
             FROM channel_members cm JOIN channels c ON c.id = cm.channel_id
             WHERE cm.user_id = ?
             ORDER BY c.name COLLATE NOCASE',
            [$actor['id']]
        );
    }

    public static function update(int|string $channelId, array $fields): void
    {
        $allowed = [
            'topic', 'description', 'visibility', 'invite_only', 'moderated', 'member_limit',
            'mlock', 'topic_locked', 'forbidden', 'owner_id', 'successor_id', 'censor',
        ];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            Database::query("UPDATE channels SET $k = ? WHERE id = ?", [$v, $channelId]);
        }
    }

    public static function setKey(int|string $channelId, ?string $key): void
    {
        $hash = null;
        if ($key !== null && $key !== '') {
            $hash = password_hash($key, PASSWORD_ARGON2ID);
        }
        Database::query('UPDATE channels SET key_hash = ? WHERE id = ?', [$hash, $channelId]);
    }

    public static function drop(int|string $channelId): void
    {
        Database::query('DELETE FROM channels WHERE id = ?', [$channelId]);
    }

    public static function publicChannels(string $term = ''): array
    {
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM channel_members cm WHERE cm.channel_id = c.id) AS members
                FROM channels c
                WHERE c.visibility = 'public'
                AND c.forbidden = 0";
        $params = [];
        if ($term !== '') {
            $sql .= ' AND c.name LIKE ? COLLATE NOCASE';
            $params[] = '%' . $term . '%';
        }
        $sql .= ' ORDER BY members DESC, c.name COLLATE NOCASE LIMIT 500';
        return Database::all($sql, $params);
    }
}

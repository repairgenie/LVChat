<?php

declare(strict_types=1);

final class MessageService
{
    public const SYSTEM_KINDS = ['join', 'part', 'quit', 'kick', 'ban', 'topic', 'mode', 'nick', 'system', 'notice'];

    public static function send(int $channelId, int $senderId, string $content, string $kind = 'message', ?int $replyTo = null): array
    {
        Database::query(
            'INSERT INTO messages (channel_id, sender_id, kind, content, reply_to_id) VALUES (?, ?, ?, ?, ?)',
            [$channelId, $senderId, $kind, $content, $replyTo]
        );
        $id = (int) Database::lastId();
        $msg = Database::row(
            'SELECT m.*, u.username, u.bot, u.role,
                    (SELECT cm.level FROM channel_members cm WHERE cm.channel_id = m.channel_id AND cm.user_id = m.sender_id) AS level,
                    (SELECT r.color FROM roles r WHERE r.id = u.role_id) AS role_color
             FROM messages m LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.id = ?',
            [$id]
        );
        self::logRow(
            (string) (Database::scalar('SELECT name FROM channels WHERE id = ?', [$channelId]) ?? $channelId),
            $senderId,
            $msg['username'] ?? null,
            $kind,
            $content
        );
        self::notifyMentions($msg, $channelId, $senderId);
        return $msg;
    }

    public static function system(int $channelId, string $kind, string $content): int
    {
        Database::query(
            'INSERT INTO messages (channel_id, sender_id, kind, content) VALUES (?, NULL, ?, ?)',
            [$channelId, $kind, $content]
        );
        $id = (int) Database::lastId();
        self::logRow(
            (string) (Database::scalar('SELECT name FROM channels WHERE id = ?', [$channelId]) ?? $channelId),
            null,
            null,
            $kind,
            $content
        );
        return $id;
    }

    /** Append-only archive write (never deleted, survives channel lifecycle). */
    public static function logRow(?string $channelName, ?int $userId, ?string $username, string $kind, string $content): void
    {
        Database::query(
            'INSERT INTO chat_logs (channel_name, user_id, username, kind, content) VALUES (?, ?, ?, ?, ?)',
            [$channelName, $userId, $username, $kind, $content]
        );
    }

    /** Log a private message exchange into the archive (channel_name = "PM: nick"). */
    public static function logPm(int $senderId, string $senderName, string $recipientName, string $content): void
    {
        Database::query(
            'INSERT INTO chat_logs (channel_name, user_id, username, kind, content) VALUES (?, ?, ?, "pm", ?)',
            ['PM: ' . $recipientName, $senderId, $senderName, $content]
        );
    }

    /** Distinct channels present in the archive (including long-deleted ones). */
    public static function loggedChannels(): array
    {
        return Database::all("SELECT channel_name, COUNT(*) AS entries, COUNT(DISTINCT username) AS participants
            FROM chat_logs WHERE channel_name IS NOT NULL GROUP BY channel_name ORDER BY channel_name COLLATE NOCASE");
    }

    /** Participants + message counts for a channel, derived from the archive. */
    public static function channelParticipants(string $channelName): array
    {
        return Database::all(
            'SELECT username, user_id, COUNT(*) AS messages, MAX(created_at) AS last_at
             FROM chat_logs WHERE channel_name = ? AND kind NOT IN ("join","part","quit","kick","ban","topic","mode","system","nick","notice") AND username IS NOT NULL
             GROUP BY username, user_id ORDER BY messages DESC',
            [$channelName]
        );
    }

    public static function forChannel(int $channelId, int $since = 0, int $limit = 100): array
    {
        $rows = Database::all(
            'SELECT m.*, u.username, u.bot, u.role,
                    (SELECT cm.level FROM channel_members cm WHERE cm.channel_id = m.channel_id AND cm.user_id = m.sender_id) AS level,
                    (SELECT r.color FROM roles r WHERE r.id = u.role_id) AS role_color
             FROM messages m LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.channel_id = ? AND m.id > ? AND m.deleted = 0
             ORDER BY m.id ASC
             LIMIT ?',
            [$channelId, $since, $limit]
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function history(int $channelId, int $limit = 60): array
    {
        $rows = Database::all(
            'SELECT m.*, u.username, u.bot, u.role,
                    (SELECT cm.level FROM channel_members cm WHERE cm.channel_id = m.channel_id AND cm.user_id = m.sender_id) AS level,
                    (SELECT r.color FROM roles r WHERE r.id = u.role_id) AS role_color
             FROM messages m LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.channel_id = ? AND m.deleted = 0
             ORDER BY m.id DESC
             LIMIT ?',
            [$channelId, $limit]
        );
        return array_map([self::class, 'present'], array_reverse($rows));
    }

    public static function forDm(int $me, int $other, int $since = 0, int $limit = 100): array
    {
        $rows = Database::all(
            'SELECT pm.*, u.username, u.bot, u.role, NULL AS level
             FROM private_messages pm JOIN users u ON u.id = pm.sender_id
             WHERE pm.id > ? AND ((pm.sender_id = ? AND pm.recipient_id = ?) OR (pm.sender_id = ? AND pm.recipient_id = ?))
             ORDER BY pm.id ASC
             LIMIT ?',
            [$since, $me, $other, $other, $me, $limit]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => 'message',
                'content' => $r['content'],
                'created_at' => $r['created_at'],
                'username' => $r['username'],
                'sender_id' => (int) $r['sender_id'],
                'bot' => (int) $r['bot'],
                'role' => $r['role'],
                'role_color' => null,
                'level' => 'normal',
                'reply_to_id' => null,
                'edited_at' => null,
                'deleted' => 0,
                'is_pm' => true,
            ];
        }
        return $out;
    }

    public static function markDmRead(int $me, int $other): void
    {
        Database::query(
            'UPDATE private_messages SET read_at = datetime("now")
             WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL',
            [$me, $other]
        );
    }

    public static function unreadDmCounts(int $userId): array
    {
        $rows = Database::all(
            'SELECT pm.sender_id, u.username, COUNT(*) AS cnt, MAX(pm.id) AS last_id
             FROM private_messages pm JOIN users u ON u.id = pm.sender_id
             WHERE pm.recipient_id = ? AND pm.read_at IS NULL
             GROUP BY pm.sender_id',
            [$userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['user_id' => (int) $r['sender_id'], 'username' => $r['username'], 'count' => (int) $r['cnt']];
        }
        return $out;
    }

    public static function recentDmPartners(int $userId, int $limit = 30): array
    {
        return Database::all(
            'SELECT u.id, u.username, u.role, u.last_seen, u.away,
                    (SELECT pm.read_at FROM private_messages pm
                     WHERE ((pm.sender_id = u.id AND pm.recipient_id = ?) OR (pm.sender_id = ? AND pm.recipient_id = u.id))
                     ORDER BY pm.id DESC LIMIT 1) IS NOT NULL AS all_read
             FROM users u
             WHERE u.id IN (
                SELECT sender_id FROM private_messages WHERE recipient_id = ?
                UNION
                SELECT recipient_id FROM private_messages WHERE sender_id = ?
             )
             ORDER BY u.username COLLATE NOCASE
             LIMIT ?',
            [$userId, $userId, $userId, $userId, $limit]
        );
    }

    public static function notifyMentions(array $msg, int $channelId, int $senderId): void
    {
        if (in_array($msg['kind'], self::SYSTEM_KINDS, true)) {
            return;
        }
        if (preg_match_all('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', $msg['content'] ?? '', $m)) {
            foreach (array_unique($m[1]) as $nick) {
                $target = Database::row(
                    'SELECT cm.user_id, cm.channel_id FROM channel_members cm JOIN users u ON u.id = cm.user_id
                     WHERE cm.channel_id = ? AND u.username = ? COLLATE NOCASE AND cm.user_id != ?',
                    [$channelId, $nick, $senderId]
                );
                if ($target) {
                    Database::query(
                        'INSERT INTO notifications (user_id, kind, channel_id, sender_id, message_id)
                         VALUES (?, "mention", ?, ?, ?)',
                        [$target['user_id'], $channelId, $senderId, $msg['id']]
                    );
                }
            }
        }
    }

    public static function notify(int $userId, string $kind, ?int $channelId, ?int $senderId): void
    {
        Database::query(
            'INSERT INTO notifications (user_id, kind, channel_id, sender_id) VALUES (?, ?, ?, ?)',
            [$userId, $kind, $channelId, $senderId]
        );
    }

    public static function delete(int $messageId, array $actor): bool|string
    {
        $msg = Database::row('SELECT * FROM messages WHERE id = ?', [$messageId]);
        if (!$msg) {
            return 'Message not found.';
        }
        if ($actor['role'] !== 'admin' && (int) $msg['sender_id'] !== (int) $actor['id']) {
            return 'You can only delete your own messages.';
        }
        Database::query('UPDATE messages SET deleted = 1, content = "" WHERE id = ?', [$messageId]);
        return true;
    }

    public static function edit(int $messageId, string $content, array $actor): bool|string
    {
        $msg = Database::row('SELECT * FROM messages WHERE id = ?', [$messageId]);
        if (!$msg) {
            return 'Message not found.';
        }
        if ((int) $msg['sender_id'] !== (int) $actor['id']) {
            return 'You can only edit your own messages.';
        }
        Database::query(
            'UPDATE messages SET content = ?, edited_at = datetime("now") WHERE id = ?',
            [mb_substr($content, 0, 2000), $messageId]
        );
        return true;
    }

    private static function present(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'kind' => $m['kind'],
            'content' => $m['content'],
            'created_at' => $m['created_at'],
            'username' => $m['username'],
            'sender_id' => $m['sender_id'] === null ? null : (int) $m['sender_id'],
            'bot' => (int) ($m['bot'] ?? 0),
            'role' => $m['role'] ?? 'user',
            'level' => $m['level'] ?? 'normal',
            'role_color' => $m['role_color'] ?? null,
            'reply_to_id' => $m['reply_to_id'] === null ? null : (int) $m['reply_to_id'],
            'edited_at' => $m['edited_at'],
            'deleted' => (int) $m['deleted'],
            'is_pm' => false,
        ];
    }
}

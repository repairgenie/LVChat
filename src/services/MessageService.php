<?php

declare(strict_types=1);

final class MessageService
{
    public const SYSTEM_KINDS = ['join', 'part', 'quit', 'kick', 'ban', 'topic', 'mode', 'nick', 'system', 'notice'];

    /** True if the actor is an anonymous guest (guests live in `guests`, not `users`). */
    public static function isGuest(array $a): bool
    {
        return (int) ($a['guest'] ?? 0) === 1;
    }

    /** Are two actors the same person (same identity kind + id)? */
    public static function sameActor(array $a, array $b): bool
    {
        return self::isGuest($a) === self::isGuest($b) && (int) $a['id'] === (int) $b['id'];
    }

    /** Insert a private message between two actors (users and/or guests). Returns the new id. */
    public static function insertPm(array $sender, array $recipient, string $content, string $kind = 'message'): int
    {
        $sc = self::isGuest($sender) ? 'sender_guest_id' : 'sender_id';
        $rc = self::isGuest($recipient) ? 'recipient_guest_id' : 'recipient_id';
        Database::query(
            "INSERT INTO private_messages ($sc, $rc, content, kind) VALUES (?, ?, ?, ?)",
            [$sender['id'], $recipient['id'], $content, $kind]
        );
        return (int) Database::lastId();
    }

    /** Create a DM notification for a recipient actor (skips self-DMs). */
    public static function notifyDm(array $recipient, array $sender, int $pmId): void
    {
        if (self::sameActor($recipient, $sender)) {
            return;
        }
        $rc = self::isGuest($recipient) ? 'guest_user_id' : 'user_id';
        $sc = self::isGuest($sender) ? 'sender_guest_id' : 'sender_id';
        Database::query(
            "INSERT INTO notifications ($rc, kind, $sc, message_id) VALUES (?, 'dm', ?, ?)",
            [$recipient['id'], $sender['id'], $pmId]
        );
    }

    /** [userColValue, guestColValue] — the side not in play is 0 so it never matches a real id. */
    private static function actorPair(array $a): array
    {
        if (self::isGuest($a)) {
            return [0, (int) $a['id']];
        }
        return [(int) $a['id'], 0];
    }

    /** Shared sender-resolution fragment for channel messages (senders may be users or guests). */
    private static function msgSelect(): string
    {
        return 'SELECT m.*,
                    COALESCE((SELECT u.username FROM users u WHERE u.id = m.sender_id),
                             (SELECT g.nick FROM guests g WHERE g.id = m.sender_guest_id)) AS username,
                    (SELECT u.role FROM users u WHERE u.id = m.sender_id) AS role,
                    (SELECT u.avatar FROM users u WHERE u.id = m.sender_id) AS avatar,
                    CASE WHEN m.sender_guest_id IS NOT NULL THEN 1 ELSE 0 END AS guest,
                    CASE WHEN m.sender_guest_id IS NOT NULL THEN 0 ELSE COALESCE((SELECT u.bot FROM users u WHERE u.id = m.sender_id), 0) END AS bot,
                    CASE WHEN m.sender_guest_id IS NOT NULL THEN \'normal\'
                         ELSE (SELECT cm.level FROM channel_members cm WHERE cm.channel_id = m.channel_id AND cm.user_id = m.sender_id)
                    END AS level,
                    (SELECT r.color FROM roles r WHERE r.id = (SELECT u.role_id FROM users u WHERE u.id = m.sender_id)) AS role_color,
                    (SELECT slug FROM channels c WHERE c.id = m.channel_id) AS channel_slug,
                    (SELECT COALESCE(u2.username, g2.nick) FROM messages pm
                        LEFT JOIN users u2 ON u2.id = pm.sender_id
                        LEFT JOIN guests g2 ON g2.id = pm.sender_guest_id
                        WHERE pm.id = m.reply_to_id AND pm.deleted = 0) AS reply_to_username,
                    (SELECT substr(pm2.content, 1, 80) FROM messages pm2 WHERE pm2.id = m.reply_to_id AND pm2.deleted = 0) AS reply_to_excerpt
             FROM messages m';
    }

    public static function send(int $channelId, array $sender, string $content, string $kind = 'message', ?int $replyTo = null): array
    {
        $isGuest = self::isGuest($sender);
        $senderCol = $isGuest ? 'sender_guest_id' : 'sender_id';
        Database::query(
            "INSERT INTO messages (channel_id, $senderCol, kind, content, reply_to_id) VALUES (?, ?, ?, ?, ?)",
            [$channelId, $sender['id'], $kind, $content, $replyTo]
        );
        $id = (int) Database::lastId();
        $msg = Database::row(self::msgSelect() . ' WHERE m.id = ?', [$id]);
        self::logRow(
            (string) (Database::scalar('SELECT name FROM channels WHERE id = ?', [$channelId]) ?? $channelId),
            $isGuest ? null : (int) $sender['id'],
            $msg['username'] ?? null,
            $kind,
            $content,
            $isGuest ? 1 : 0
        );
        self::notifyMentions($msg, $channelId, $sender);
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
    public static function logRow(?string $channelName, ?int $userId, ?string $username, string $kind, string $content, int $guest = 0): void
    {
        Database::query(
            'INSERT INTO chat_logs (channel_name, user_id, username, kind, content, guest) VALUES (?, ?, ?, ?, ?, ?)',
            [$channelName, $userId, $username, $kind, $content, $guest]
        );
    }

    /** Log a private message exchange into the archive (channel_name = "PM: nick"). */
    public static function logPm(int $senderId, string $senderName, string $recipientName, string $content, int $guest = 0): void
    {
        Database::query(
            'INSERT INTO chat_logs (channel_name, user_id, username, kind, content, guest) VALUES (?, ?, ?, "pm", ?, ?)',
            ['PM: ' . $recipientName, $senderId, $senderName, $content, $guest]
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
            'SELECT username, user_id, COUNT(*) AS messages, MAX(created_at) AS last_at, MAX(guest) AS guest
             FROM chat_logs WHERE channel_name = ? AND kind NOT IN ("join","part","quit","kick","ban","topic","mode","system","nick","notice") AND username IS NOT NULL
             GROUP BY username, user_id ORDER BY messages DESC',
            [$channelName]
        );
    }

    public static function forChannel(int $channelId, int $since = 0, int $limit = 100): array
    {
        $rows = Database::all(
            self::msgSelect() . '
             WHERE m.channel_id = ? AND m.id > ? AND m.deleted = 0
             ORDER BY m.id ASC
             LIMIT ?',
            [$channelId, $since, $limit]
        );
        return array_map([self::class, 'present'], $rows);
    }

    /**
     * New real (non-system) messages in every channel the actor is a member of
     * except the one they're currently viewing. This is what drives background
     * channel audio alerts: the client polls with a global messages.id
     * watermark (`bg_since`) and plays a sound for each returned row.
     */
    public static function backgroundSince(array $actor, int $since, int $excludeChannelId = 0, int $limit = 50): array
    {
        $memberJoin = self::isGuest($actor)
            ? 'JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.guest_id = ?'
            : 'JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?';
        $kinds = implode('","', self::SYSTEM_KINDS);
        $params = [$actor['id'], $since];
        $exclude = '';
        if ($excludeChannelId > 0) {
            $exclude = ' AND m.channel_id != ?';
            $params[] = $excludeChannelId;
        }
        $params[] = $limit;
        $rows = Database::all(
            self::msgSelect() . "
             $memberJoin
             WHERE m.id > ? AND m.deleted = 0
               AND m.kind NOT IN (\"$kinds\")$exclude
             ORDER BY m.id ASC
             LIMIT ?",
            $params
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function history(int $channelId, int $limit = 60): array
    {
        $rows = Database::all(
            self::msgSelect() . '
             WHERE m.channel_id = ? AND m.deleted = 0
             ORDER BY m.id DESC
             LIMIT ?',
            [$channelId, $limit]
        );
        return array_map([self::class, 'present'], array_reverse($rows));
    }

    /** Older messages than $beforeId (pagination). Returns ascending oldest→newest. */
    public static function historyBefore(int $channelId, int $beforeId, int $limit = 50): array
    {
        $rows = Database::all(
            self::msgSelect() . '
             WHERE m.channel_id = ? AND m.deleted = 0 AND m.id < ?
             ORDER BY m.id DESC
             LIMIT ?',
            [$channelId, $beforeId, $limit]
        );
        return array_map([self::class, 'present'], array_reverse($rows));
    }

    public static function forDm(array $me, array $other, int $since = 0, int $limit = 100): array
    {
        [$meU, $meG] = self::actorPair($me);
        [$oU, $oG] = self::actorPair($other);
        $order = $since > 0 ? 'ASC' : 'DESC';
        $rows = Database::all(
            "SELECT pm.*,
                    COALESCE((SELECT u.username FROM users u WHERE u.id = pm.sender_id),
                             (SELECT g.nick FROM guests g WHERE g.id = pm.sender_guest_id)) AS username,
                    (SELECT u.role FROM users u WHERE u.id = pm.sender_id) AS role,
                    CASE WHEN pm.sender_guest_id IS NOT NULL THEN 1 ELSE 0 END AS guest,
                    NULL AS bot, NULL AS level
             FROM private_messages pm
             WHERE pm.id > ? AND (
                ((pm.sender_id = ? OR pm.sender_guest_id = ?) AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?))
                OR
                ((pm.sender_id = ? OR pm.sender_guest_id = ?) AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?))
             )
             ORDER BY pm.id $order
             LIMIT ?",
            [$since, $meU, $meG, $oU, $oG, $oU, $oG, $meU, $meG, $limit]
        );
        $rows = $since > 0 ? $rows : array_reverse($rows);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => $r['kind'] ?? 'message',
                'content' => $r['content'],
                'created_at' => $r['created_at'],
                'username' => $r['username'],
                'sender_id' => $r['sender_id'] === null ? null : (int) $r['sender_id'],
                'bot' => (int) ($r['bot'] ?? 0),
                'role' => $r['role'] ?? 'user',
                'role_color' => null,
                'guest' => (int) ($r['guest'] ?? 0),
                'level' => 'normal',
                'reply_to_id' => null,
                'edited_at' => null,
                'deleted' => 0,
                'is_pm' => true,
            ];
        }
        return $out;
    }

    /** Older DM messages than $beforeId (pagination). Returns oldest→newest. */
    public static function dmHistoryBefore(array $me, array $other, int $beforeId, int $limit = 50): array
    {
        [$meU, $meG] = self::actorPair($me);
        [$oU, $oG] = self::actorPair($other);
        $rows = Database::all(
            "SELECT pm.*,
                    COALESCE((SELECT u.username FROM users u WHERE u.id = pm.sender_id),
                             (SELECT g.nick FROM guests g WHERE g.id = pm.sender_guest_id)) AS username,
                    (SELECT u.role FROM users u WHERE u.id = pm.sender_id) AS role,
                    CASE WHEN pm.sender_guest_id IS NOT NULL THEN 1 ELSE 0 END AS guest,
                    NULL AS bot, NULL AS level
             FROM private_messages pm
             WHERE pm.id < ? AND (
                ((pm.sender_id = ? OR pm.sender_guest_id = ?) AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?))
                OR
                ((pm.sender_id = ? OR pm.sender_guest_id = ?) AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?))
             )
             ORDER BY pm.id DESC
             LIMIT ?",
            [$beforeId, $meU, $meG, $oU, $oG, $oU, $oG, $meU, $meG, $limit]
        );
        $rows = array_reverse($rows);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => $r['kind'] ?? 'message',
                'content' => $r['content'],
                'created_at' => $r['created_at'],
                'username' => $r['username'],
                'sender_id' => $r['sender_id'] === null ? null : (int) $r['sender_id'],
                'bot' => (int) ($r['bot'] ?? 0),
                'role' => $r['role'] ?? 'user',
                'role_color' => null,
                'guest' => (int) ($r['guest'] ?? 0),
                'level' => 'normal',
                'reply_to_id' => null,
                'edited_at' => null,
                'deleted' => 0,
                'is_pm' => true,
            ];
        }
        return $out;
    }

    public static function markDmRead(array $me, array $other): void
    {
        [$meU, $meG] = self::actorPair($me);
        [$oU, $oG] = self::actorPair($other);
        Database::query(
            'UPDATE private_messages SET read_at = datetime("now")
             WHERE read_at IS NULL
               AND (recipient_id = ? OR recipient_guest_id = ?)
               AND (sender_id = ? OR sender_guest_id = ?)',
            [$meU, $meG, $oU, $oG]
        );
    }

    public static function hasUnreadDm(array $me, array $other): bool
    {
        [$meU, $meG] = self::actorPair($me);
        [$oU, $oG] = self::actorPair($other);
        return (bool) Database::scalar(
            'SELECT 1 FROM private_messages
             WHERE read_at IS NULL
               AND (recipient_id = ? OR recipient_guest_id = ?)
               AND (sender_id = ? OR sender_guest_id = ?) LIMIT 1',
            [$meU, $meG, $oU, $oG]
        );
    }

    public static function unreadDmCounts(array $me): array
    {
        [$meU, $meG] = self::actorPair($me);
        $rows = Database::all(
            'SELECT pm.sender_id, pm.sender_guest_id, COUNT(*) AS cnt, MAX(pm.id) AS last_id,
                    COALESCE((SELECT u.username FROM users u WHERE u.id = pm.sender_id),
                             (SELECT g.nick FROM guests g WHERE g.id = pm.sender_guest_id)) AS username
             FROM private_messages pm
             WHERE (pm.recipient_id = ? OR pm.recipient_guest_id = ?) AND pm.read_at IS NULL
             GROUP BY pm.sender_id, pm.sender_guest_id',
            [$meU, $meG]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => (int) ($r['sender_id'] ?? $r['sender_guest_id']),
                'username' => $r['username'],
                'count' => (int) $r['cnt'],
            ];
        }
        return $out;
    }

    public static function recentDmPartners(array $me, int $limit = 30): array
    {
        [$meU, $meG] = self::actorPair($me);
        return Database::all(
            "SELECT p.id, p.username, p.role, p.guest, p.last_seen, p.away, 1 AS all_read
             FROM (
               SELECT 'user' AS ptype, u.id AS id, u.username, u.role, u.guest, u.last_seen, u.away
               FROM users u WHERE u.id IN (
                 SELECT recipient_id FROM private_messages WHERE sender_id = ? OR sender_guest_id = ?
                 UNION SELECT sender_id FROM private_messages WHERE recipient_id = ? OR recipient_guest_id = ?
               )
               UNION ALL
               SELECT 'guest', g.id, g.nick, 'user', 1, g.last_seen, NULL
               FROM guests g WHERE g.id IN (
                 SELECT recipient_guest_id FROM private_messages WHERE sender_id = ? OR sender_guest_id = ?
                 UNION SELECT sender_guest_id FROM private_messages WHERE recipient_id = ? OR recipient_guest_id = ?
               )
             ) p
             ORDER BY p.username COLLATE NOCASE
             LIMIT ?",
            [$meU, $meG, $meU, $meG, $meU, $meG, $meU, $meG, $limit]
        );
    }

    /** Live DM sidebar + toast data: partners, unread counts, latest message previews. */
    public static function dmSummaries(array $me, int $limit = 30): array
    {
        [$meU, $meG] = self::actorPair($me);
        $rows = Database::all(
            "SELECT p.user_id, p.username, p.role, p.guest, p.last_seen, p.away,
                    (SELECT COUNT(*) FROM private_messages pm
                     WHERE pm.read_at IS NULL
                       AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?)
                       AND (pm.sender_id = CASE WHEN p.ptype = 'user' THEN p.id ELSE NULL END
                            OR pm.sender_guest_id = CASE WHEN p.ptype = 'guest' THEN p.id ELSE NULL END)) AS unread,
                    (SELECT pm.content FROM private_messages pm
                     WHERE (
                       (pm.sender_id = ? OR pm.sender_guest_id = ?)
                       AND (pm.recipient_id = CASE WHEN p.ptype = 'user' THEN p.id ELSE NULL END
                            OR pm.recipient_guest_id = CASE WHEN p.ptype = 'guest' THEN p.id ELSE NULL END)
                     ) OR (
                       (pm.sender_id = CASE WHEN p.ptype = 'user' THEN p.id ELSE NULL END
                        OR pm.sender_guest_id = CASE WHEN p.ptype = 'guest' THEN p.id ELSE NULL END)
                       AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?)
                     )
                     ORDER BY pm.id DESC LIMIT 1) AS last_content,
                    (SELECT pm.id FROM private_messages pm
                     WHERE (
                       (pm.sender_id = ? OR pm.sender_guest_id = ?)
                       AND (pm.recipient_id = CASE WHEN p.ptype = 'user' THEN p.id ELSE NULL END
                            OR pm.recipient_guest_id = CASE WHEN p.ptype = 'guest' THEN p.id ELSE NULL END)
                     ) OR (
                       (pm.sender_id = CASE WHEN p.ptype = 'user' THEN p.id ELSE NULL END
                        OR pm.sender_guest_id = CASE WHEN p.ptype = 'guest' THEN p.id ELSE NULL END)
                       AND (pm.recipient_id = ? OR pm.recipient_guest_id = ?)
                     )
                     ORDER BY pm.id DESC LIMIT 1) AS last_id
             FROM (
               SELECT 'user' AS ptype, u.id AS id, u.id AS user_id, u.username, u.role, u.guest, u.last_seen, u.away
               FROM users u WHERE u.id IN (
                 SELECT recipient_id FROM private_messages WHERE sender_id = ? OR sender_guest_id = ?
                 UNION SELECT sender_id FROM private_messages WHERE recipient_id = ? OR recipient_guest_id = ?
               )
               UNION ALL
               SELECT 'guest', g.id, g.id, g.nick, 'user', 1, g.last_seen, NULL
               FROM guests g WHERE g.id IN (
                 SELECT recipient_guest_id FROM private_messages WHERE sender_id = ? OR sender_guest_id = ?
                 UNION SELECT sender_guest_id FROM private_messages WHERE recipient_id = ? OR recipient_guest_id = ?
               )
             ) p
             ORDER BY p.username COLLATE NOCASE
             LIMIT ?",
            [
                $meU, $meG, $meU, $meG, $meU, $meG, $meU, $meG,
                $meU, $meG, $meU, $meG, $meU, $meG, $meU, $meG,
                $meU, $meG,
                $limit,
            ]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => (int) $r['user_id'],
                'username' => $r['username'],
                'role' => $r['role'],
                'guest' => (int) ($r['guest'] ?? 0),
                'away' => $r['away'],
                'last_seen' => $r['last_seen'],
                'unread' => (int) $r['unread'],
                'last_content' => (string) ($r['last_content'] ?? ''),
                'last_id' => (int) ($r['last_id'] ?? 0),
            ];
        }
        return $out;
    }

    public static function notifyMentions(array $msg, int $channelId, array $sender): void
    {
        if (in_array($msg['kind'], self::SYSTEM_KINDS, true)) {
            return;
        }
        if (preg_match_all('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', $msg['content'] ?? '', $m)) {
            foreach (array_unique($m[1]) as $nick) {
                $target = Database::row(
                    "SELECT cm.user_id, cm.guest_id, cm.channel_id
                     FROM channel_members cm
                     LEFT JOIN users u ON u.id = cm.user_id
                     LEFT JOIN guests g ON g.id = cm.guest_id
                     WHERE cm.channel_id = ? AND (u.username = ? COLLATE NOCASE OR g.nick = ? COLLATE NOCASE)",
                    [$channelId, $nick, $nick]
                );
                if (!$target) {
                    continue;
                }
                $senderIsGuest = self::isGuest($sender);
                if ($senderIsGuest && (int) $target['guest_id'] === (int) $sender['id']) {
                    continue;
                }
                if (!$senderIsGuest && (int) $target['user_id'] === (int) $sender['id']) {
                    continue;
                }
                // Respect the target's channel notification mode (muted = no
                // alerts at all; 'all' and 'mentions' both still deliver a
                // mention). Guests never mute.
                if ($target['user_id'] !== null) {
                    $mode = (string) Database::scalar(
                        'SELECT mode FROM channel_notify WHERE channel_id = ? AND user_id = ?',
                        [$channelId, (int) $target['user_id']]
                    );
                    if ($mode === 'muted') {
                        continue;
                    }
                }
                $targetCol = $target['guest_id'] !== null ? 'guest_user_id' : 'user_id';
                $senderCol = $senderIsGuest ? 'sender_guest_id' : 'sender_id';
                Database::query(
                    "INSERT INTO notifications ($targetCol, kind, channel_id, $senderCol, message_id)
                     VALUES (?, 'mention', ?, ?, ?)",
                    [(int) ($target['guest_id'] ?? $target['user_id']), $channelId, (int) $sender['id'], $msg['id']]
                );
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
        $isOwner = self::isGuest($actor)
            ? ($msg['sender_guest_id'] !== null && (int) $msg['sender_guest_id'] === (int) $actor['id'])
            : ($msg['sender_id'] !== null && (int) $msg['sender_id'] === (int) $actor['id']);
        if ($actor['role'] !== 'admin' && !$isOwner) {
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
        $isAdmin = $actor['role'] === 'admin';
        $isOwner = self::isGuest($actor)
            ? ($msg['sender_guest_id'] !== null && (int) $msg['sender_guest_id'] === (int) $actor['id'])
            : ($msg['sender_id'] !== null && (int) $msg['sender_id'] === (int) $actor['id']);
        if (!$isAdmin && !$isOwner) {
            return 'You can only edit your own messages.';
        }
        if (!$isAdmin && $isOwner) {
            $created = strtotime($msg['created_at'] . ' UTC');
            $now = time();
            if ($now - $created > 300) {
                return 'You can only edit messages within 5 minutes of posting.';
            }
        }
        Database::query(
            'UPDATE messages SET content = ?, edited_at = datetime("now") WHERE id = ?',
            [mb_substr($content, 0, 2000), $messageId]
        );
        return true;
    }

    /**
     * Search recent channel messages the actor can see (member of the channel).
     * Uses FTS5 when the SQLite build has it, otherwise falls back to LIKE.
     */
    public static function searchChannels(array $actor, string $term, int $limit = 50): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $join = Auth::isGuest($actor)
            ? 'JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.guest_id = ?'
            : 'JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?';
        if (Database::fts5()) {
            // Quote each word so FTS5 treats operators (-, "", NEAR, etc.) as
            // literal text rather than syntax (e.g. "zzzz-no-such-term").
            $words = preg_split('/\s+/', $term) ?: [];
            $words = array_filter($words, fn ($w) => $w !== '');
            if (!$words) {
                return [];
            }
            $match = implode(' ', array_map(fn ($w) => '"' . str_replace('"', '""', $w) . '"', $words));
            $ids = Database::all(
                'SELECT rowid FROM messages_fts WHERE messages_fts MATCH ? ORDER BY rank LIMIT ?',
                [$match, $limit]
            );
            if (!$ids) {
                return [];
            }
            $ids = array_map(fn ($r) => (int) $r['rowid'], $ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all(
                self::msgSelect() . "
                 $join
                 WHERE m.deleted = 0 AND m.kind NOT IN ('join','part','quit','kick','ban','topic','mode','nick','system','notice')
                   AND m.id IN ($ph)
                 ORDER BY m.id DESC
                 LIMIT ?",
                array_merge([$actor['id']], $ids, [$limit])
            );
        } else {
            $rows = Database::all(
                self::msgSelect() . "
                 $join
                 WHERE m.deleted = 0 AND m.kind NOT IN ('join','part','quit','kick','ban','topic','mode','nick','system','notice')
                   AND m.content LIKE ? ESCAPE '\\'
                 ORDER BY m.id DESC
                 LIMIT ?",
                [$actor['id'], '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $term) . '%', $limit]
            );
        }
        return array_map([self::class, 'present'], $rows);
    }

    /** Search this user's private message conversations for a term (LIKE fallback always). */
    public static function searchDm(array $actor, string $term, int $limit = 50): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        [$meU, $meG] = self::actorPair($actor);
        $rows = Database::all(
            "SELECT pm.*,
                    COALESCE((SELECT u.username FROM users u WHERE u.id = pm.sender_id),
                             (SELECT g.nick FROM guests g WHERE g.id = pm.sender_guest_id)) AS username,
                    (SELECT u.role FROM users u WHERE u.id = pm.sender_id) AS role,
                    CASE WHEN pm.sender_guest_id IS NOT NULL THEN 1 ELSE 0 END AS guest,
                    NULL AS bot, NULL AS level
             FROM private_messages pm
             WHERE (pm.sender_id = ? OR pm.sender_guest_id = ? OR pm.recipient_id = ? OR pm.recipient_guest_id = ?)
               AND pm.content LIKE ? ESCAPE '\\'
             ORDER BY pm.id DESC
             LIMIT ?",
            [$meU, $meG, $meU, $meG, '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $term) . '%', $limit]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => 'message',
                'content' => $r['content'],
                'created_at' => $r['created_at'],
                'username' => $r['username'],
                'sender_id' => $r['sender_id'] === null ? null : (int) $r['sender_id'],
                'bot' => (int) ($r['bot'] ?? 0),
                'role' => $r['role'] ?? 'user',
                'role_color' => null,
                'guest' => (int) ($r['guest'] ?? 0),
                'level' => 'normal',
                'reply_to_id' => null,
                'edited_at' => null,
                'deleted' => 0,
                'is_pm' => true,
            ];
        }
        return $out;
    }

    /** Build a snippet around the first match of $term in $content (FTS-style). */
    public static function snippet(string $content, string $term, int $radius = 60): string
    {
        $content = preg_replace('/\s+/', ' ', trim($content));
        $pos = mb_stripos($content, $term);
        if ($pos === false) {
            return mb_strimwidth($content, 0, $radius * 2, '…');
        }
        $start = max(0, $pos - $radius);
        $len = min(mb_strlen($content), $radius * 2);
        $out = mb_substr($content, $start, $len);
        if ($start > 0) {
            $out = '…' . $out;
        }
        if ($start + $len < mb_strlen($content)) {
            $out .= '…';
        }
        return $out;
    }

    /**
     * Toggle a reaction on a message. Returns the new state:
     * ['added' => true, 'reactions' => [...]] or ['added' => false, ...].
     */
    public static function toggleReaction(int $messageId, array $actor, string $emoji): array|string
    {
        $msg = Database::row('SELECT id, kind, deleted FROM messages WHERE id = ?', [$messageId]);
        if (!$msg || (int) $msg['deleted'] === 1 || in_array($msg['kind'], self::SYSTEM_KINDS, true)) {
            return 'Message not found.';
        }
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            return 'Invalid emoji.';
        }
        $isGuest = self::isGuest($actor);
        $actorType = $isGuest ? 'guest' : 'user';
        $existing = Database::row(
            "SELECT id FROM reactions WHERE message_id = ? AND actor_type = ? AND actor_id = ? AND emoji = ?",
            [$messageId, $actorType, (int) $actor['id'], $emoji]
        );
        if ($existing) {
            Database::query('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        } else {
            Database::query(
                'INSERT INTO reactions (message_id, actor_type, actor_id, emoji) VALUES (?, ?, ?, ?)',
                [$messageId, $actorType, (int) $actor['id'], $emoji]
            );
        }
        return ['added' => $existing === null, 'reactions' => self::reactionsFor($messageId, $actor)];
    }

    /** Aggregate reactions for a message, flagging which emojis the actor used. */
    public static function reactionsFor(int $messageId, ?array $actor = null): array
    {
        $rows = Database::all(
            'SELECT emoji, COUNT(*) AS count FROM reactions WHERE message_id = ? GROUP BY emoji ORDER BY count DESC, emoji',
            [$messageId]
        );
        $mine = [];
        if ($actor && (int) ($actor['id'] ?? 0) > 0) {
            $actorType = self::isGuest($actor) ? 'guest' : 'user';
            $mine = Database::all(
                'SELECT DISTINCT emoji FROM reactions WHERE message_id = ? AND actor_type = ? AND actor_id = ?',
                [$messageId, $actorType, (int) $actor['id']]
            );
            $mine = array_column($mine, 'emoji');
        }
        return ['rows' => $rows, 'mine' => $mine];
    }

    /** True if reactions are enabled server-wide (config-driven). */
    public static function reactionsEnabled(): bool
    {
        return config_get('reactions_enabled', '1') === '1';
    }

    /** Attach aggregate reactions to each presented message (batch, one query). */
    public static function hydrateReactions(array $messages, ?array $actor = null): array
    {
        if (!$messages || !self::reactionsEnabled()) {
            return $messages;
        }
        $ids = array_filter(array_map(fn ($m) => (int) ($m['id'] ?? 0), $messages), fn ($id) => $id > 0);
        if (!$ids) {
            return $messages;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT message_id, emoji, COUNT(*) AS count FROM reactions WHERE message_id IN ($ph) GROUP BY message_id, emoji",
            $ids
        );
        $byMsg = [];
        foreach ($rows as $r) {
            $byMsg[(int) $r['message_id']][] = ['emoji' => $r['emoji'], 'count' => (int) $r['count']];
        }
        $mine = [];
        if ($actor && (int) ($actor['id'] ?? 0) > 0) {
            $actorType = self::isGuest($actor) ? 'guest' : 'user';
            $mineRows = Database::all(
                "SELECT DISTINCT message_id, emoji FROM reactions WHERE message_id IN ($ph) AND actor_type = ? AND actor_id = ?",
                array_merge($ids, [$actorType, (int) $actor['id']])
            );
            foreach ($mineRows as $r) {
                $mine[(int) $r['message_id']][] = $r['emoji'];
            }
        }
        foreach ($messages as &$m) {
            $id = (int) $m['id'];
            $m['reactions'] = $byMsg[$id] ?? [];
            $m['my_reactions'] = $mine[$id] ?? [];
        }
        unset($m);
        return $messages;
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
            'avatar' => $m['avatar'] ?? null,
            'bot' => (int) ($m['bot'] ?? 0),
            'role' => $m['role'] ?? 'user',
            'level' => $m['level'] ?? 'normal',
            'role_color' => $m['role_color'] ?? null,
            'guest' => (int) ($m['guest'] ?? 0),
            'channel_id' => $m['channel_id'] === null ? null : (int) $m['channel_id'],
            'channel_slug' => $m['channel_slug'] ?? null,
            'reply_to_id' => $m['reply_to_id'] === null ? null : (int) $m['reply_to_id'],
            'reply_to_username' => $m['reply_to_username'] ?? null,
            'reply_to_excerpt' => $m['reply_to_excerpt'] ?? null,
            'edited_at' => $m['edited_at'],
            'deleted' => (int) $m['deleted'],
            'is_pm' => false,
        ];
    }
}

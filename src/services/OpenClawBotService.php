<?php

declare(strict_types=1);

final class OpenClawBotService
{
    public static function create(string $name, string $systemPrompt, string $avatar, array $actor): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 32 || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}| ]{2,32}$/', $name)) {
            return ['ok' => false, 'error' => 'Bot name must be 2-32 chars (letters, numbers, spaces, IRC-safe symbols).'];
        }
        if ($avatar !== '' && !preg_match('#^https://#', $avatar)) {
            return ['ok' => false, 'error' => 'Avatar must be an https:// URL.'];
        }

        $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $username = $slug !== '' ? mb_substr($slug, 0, 24) : 'openclaw';
        $candidate = $username;
        $i = 1;
        while (Database::scalar('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE', [$candidate])) {
            $candidate = $username . $i++;
        }

        Database::query(
            'INSERT INTO users (username, email, password_hash, bot, avatar, role) VALUES (?, ?, ?, 1, ?, "user")',
            [$candidate, $candidate . '@openclaw.local', password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID), mb_substr($avatar, 0, 500)]
        );
        $userId = (int) Database::lastId();

        $apiKey = bin2hex(random_bytes(32));
        $apiKeyHash = hash('sha256', $apiKey);

        Database::query(
            'INSERT INTO openclaw_bots (name, username, user_id, api_key_hash, avatar, system_prompt, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [mb_substr($name, 0, 32), $candidate, $userId, $apiKeyHash, mb_substr($avatar, 0, 500), trim($systemPrompt), (int) $actor['id']]
        );
        log_audit('openclaw_create', $candidate, $name);
        return ['ok' => true, 'id' => (int) Database::lastId(), 'api_key' => $apiKey];
    }

    public static function delete(int $id, array $actor): bool|string
    {
        $bot = Database::row('SELECT * FROM openclaw_bots WHERE id = ?', [$id]);
        if (!$bot) {
            return 'Bot not found.';
        }
        if ($actor['role'] !== 'admin') {
            return 'Only administrators can manage OpenClaw bots.';
        }
        Database::query('DELETE FROM users WHERE id = ?', [(int) $bot['user_id']]);
        Database::query('DELETE FROM openclaw_bots WHERE id = ?', [$id]);
        log_audit('openclaw_delete', 'bot#' . $id, $bot['name']);
        return true;
    }

    public static function toggle(int $id): bool
    {
        Database::query('UPDATE openclaw_bots SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END WHERE id = ?', [$id]);
        return true;
    }

    public static function assignChannel(int $botId, int $channelId, string $respondMode): array
    {
        $mode = in_array($respondMode, ['mentions', 'all', 'commands'], true) ? $respondMode : 'mentions';
        Database::query(
            'INSERT OR REPLACE INTO openclaw_bot_channels (bot_id, channel_id, respond_mode) VALUES (?, ?, ?)',
            [$botId, $channelId, $mode]
        );
        $bot = Database::row('SELECT user_id FROM openclaw_bots WHERE id = ?', [$botId]);
        if ($bot) {
            $exists = Database::scalar('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, (int) $bot['user_id']]);
            if (!$exists) {
                Database::query(
                    'INSERT INTO channel_members (channel_id, user_id, level) VALUES (?, ?, "voice")',
                    [$channelId, (int) $bot['user_id']]
                );
            }
        }
        return ['ok' => true];
    }

    public static function removeChannel(int $botId, int $channelId): array
    {
        Database::query('DELETE FROM openclaw_bot_channels WHERE bot_id = ? AND channel_id = ?', [$botId, $channelId]);
        return ['ok' => true];
    }

    public static function grantPmAccess(int $botId, int $userId): array
    {
        Database::query('INSERT OR IGNORE INTO openclaw_bot_pm_access (bot_id, user_id) VALUES (?, ?)', [$botId, $userId]);
        return ['ok' => true];
    }

    public static function revokePmAccess(int $botId, int $userId): array
    {
        Database::query('DELETE FROM openclaw_bot_pm_access WHERE bot_id = ? AND user_id = ?', [$botId, $userId]);
        return ['ok' => true];
    }

    public static function all(): array
    {
        return Database::all('SELECT b.*, u.avatar AS user_avatar FROM openclaw_bots b LEFT JOIN users u ON u.id = b.user_id ORDER BY b.name');
    }

    public static function channelsForBot(int $botId): array
    {
        return Database::all(
            'SELECT c.id, c.name, c.slug, bc.respond_mode FROM openclaw_bot_channels bc JOIN channels c ON c.id = bc.channel_id WHERE bc.bot_id = ? ORDER BY c.name',
            [$botId]
        );
    }

    public static function pmUsersForBot(int $botId): array
    {
        return Database::all(
            'SELECT u.id, u.username FROM openclaw_bot_pm_access pa JOIN users u ON u.id = pa.user_id WHERE pa.bot_id = ? ORDER BY u.username',
            [$botId]
        );
    }

    public static function authenticate(string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }
        $hash = hash('sha256', $apiKey);
        $bot = Database::row('SELECT b.*, u.username AS bot_username FROM openclaw_bots b JOIN users u ON u.id = b.user_id WHERE b.api_key_hash = ? AND b.enabled = 1', [$hash]);
        if (!$bot) {
            return null;
        }
        Database::query('UPDATE openclaw_bots SET last_seen = datetime("now") WHERE id = ?', [(int) $bot['id']]);
        return $bot;
    }

    public static function getAssignedChannels(int $botId): array
    {
        return Database::all(
            'SELECT c.id, c.name, c.slug, bc.respond_mode FROM openclaw_bot_channels bc JOIN channels c ON c.id = bc.channel_id WHERE bc.bot_id = ? ORDER BY c.name',
            [$botId]
        );
    }

    public static function getMessages(int $botId, string $channelSlug, int $since, int $limit): array
    {
        $channel = Database::row('SELECT c.id FROM channels c JOIN openclaw_bot_channels bc ON bc.channel_id = c.id WHERE c.slug = ? AND bc.bot_id = ?', [$channelSlug, $botId]);
        if (!$channel) {
            return [];
        }
        return MessageService::forChannel((int) $channel['id'], $since, min($limit, 100));
    }

    public static function getPms(int $botId, int $since, int $limit): array
    {
        $bot = Database::row('SELECT user_id FROM openclaw_bots WHERE id = ?', [$botId]);
        if (!$bot) {
            return [];
        }
        $botUser = Database::row('SELECT * FROM users WHERE id = ?', [(int) $bot['user_id']]);
        if (!$botUser) {
            return [];
        }
        $allowedUserIds = array_column(Database::all('SELECT user_id FROM openclaw_bot_pm_access WHERE bot_id = ?', [$botId]), 'user_id');
        if (!$allowedUserIds) {
            return [];
        }
        $results = [];
        foreach ($allowedUserIds as $uid) {
            $partner = Database::row('SELECT * FROM users WHERE id = ?', [(int) $uid]);
            if (!$partner) {
                continue;
            }
            $msgs = MessageService::forDm($botUser, $partner, $since, min($limit, 100));
            foreach ($msgs as $m) {
                $m['partner'] = $partner['username'];
                $results[] = $m;
            }
        }
        usort($results, fn($a, $b) => (int) $a['id'] - (int) $b['id']);
        return array_slice($results, 0, min($limit, 100));
    }

    public static function sendMessage(int $botId, string $channelSlug, string $content, string $kind, ?int $replyTo): array
    {
        $bot = Database::row('SELECT * FROM openclaw_bots WHERE id = ? AND enabled = 1', [$botId]);
        if (!$bot) {
            return ['ok' => false, 'error' => 'Bot not found or disabled.', 'status' => 404];
        }
        $channel = Database::row('SELECT c.* FROM channels c JOIN openclaw_bot_channels bc ON bc.channel_id = c.id WHERE c.slug = ? AND bc.bot_id = ?', [$channelSlug, $botId]);
        if (!$channel) {
            return ['ok' => false, 'error' => 'Bot is not assigned to this channel.', 'status' => 403];
        }
        $botUser = Database::row('SELECT * FROM users WHERE id = ?', [(int) $bot['user_id']]);
        if (!$botUser) {
            return ['ok' => false, 'error' => 'Bot user account missing.', 'status' => 500];
        }
        $content = mb_substr(trim($content), 0, 4000);
        if ($content === '') {
            return ['ok' => false, 'error' => 'Content is empty.', 'status' => 400];
        }
        $validKinds = ['message', 'ai_response'];
        if (!in_array($kind, $validKinds, true)) {
            $kind = 'message';
        }
        $msg = MessageService::send((int) $channel['id'], $botUser, $content, $kind, $replyTo);
        return ['ok' => true, 'message' => $msg];
    }

    public static function sendPm(int $botId, string $recipientUsername, string $content, string $kind): array
    {
        $bot = Database::row('SELECT * FROM openclaw_bots WHERE id = ? AND enabled = 1', [$botId]);
        if (!$bot) {
            return ['ok' => false, 'error' => 'Bot not found or disabled.', 'status' => 404];
        }
        $hasAccess = Database::scalar(
            'SELECT 1 FROM openclaw_bot_pm_access pa JOIN users u ON u.id = pa.user_id WHERE pa.bot_id = ? AND u.username = ? COLLATE NOCASE',
            [$botId, $recipientUsername]
        );
        if (!$hasAccess) {
            return ['ok' => false, 'error' => 'Bot does not have PM access to this user.', 'status' => 403];
        }
        $botUser = Database::row('SELECT * FROM users WHERE id = ?', [(int) $bot['user_id']]);
        if (!$botUser) {
            return ['ok' => false, 'error' => 'Bot user account missing.', 'status' => 500];
        }
        $recipient = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$recipientUsername]);
        if (!$recipient) {
            return ['ok' => false, 'error' => 'Recipient not found.', 'status' => 404];
        }
        $content = mb_substr(trim($content), 0, 4000);
        if ($content === '') {
            return ['ok' => false, 'error' => 'Content is empty.', 'status' => 400];
        }
        $validKinds = ['message', 'ai_response'];
        if (!in_array($kind, $validKinds, true)) {
            $kind = 'message';
        }
        $pmId = MessageService::insertPm($botUser, $recipient, $content, $kind);
        MessageService::notifyDm($recipient, $botUser, $pmId);
        MessageService::logPm((int) $botUser['id'], $botUser['username'], $recipient['username'], $content, 0);
        return ['ok' => true, 'pm_id' => $pmId];
    }
}

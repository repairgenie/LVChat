<?php

declare(strict_types=1);

final class OpenClawBotService
{
    public static function create(string $name, string $gatewayUrl, string $apiKey, string $systemPrompt, string $avatar, array $actor): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 32 || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}| ]{2,32}$/', $name)) {
            return ['ok' => false, 'error' => 'Bot name must be 2-32 chars (letters, numbers, spaces, IRC-safe symbols).'];
        }
        $gatewayUrl = trim($gatewayUrl);
        if ($gatewayUrl === '' || !preg_match('#^https?://#', $gatewayUrl)) {
            return ['ok' => false, 'error' => 'Gateway URL must be a valid http(s) URL.'];
        }
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'API key is required.'];
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

        Database::query(
            'INSERT INTO openclaw_bots (name, username, user_id, gateway_url, api_key, avatar, system_prompt, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr($name, 0, 32), $candidate, $userId, $gatewayUrl, $apiKey, mb_substr($avatar, 0, 500), trim($systemPrompt), (int) $actor['id']]
        );
        log_audit('openclaw_create', $candidate, $name);
        return ['ok' => true, 'id' => (int) Database::lastId()];
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

    public static function botsForChannel(int $channelId): array
    {
        return Database::all(
            'SELECT b.*, bc.respond_mode FROM openclaw_bots b JOIN openclaw_bot_channels bc ON bc.bot_id = b.id WHERE bc.channel_id = ? AND b.enabled = 1',
            [$channelId]
        );
    }

    public static function botsForPm(int $userId): array
    {
        return Database::all(
            'SELECT b.* FROM openclaw_bots b JOIN openclaw_bot_pm_access pa ON pa.bot_id = b.id WHERE pa.user_id = ? AND b.enabled = 1',
            [$userId]
        );
    }

    public static function onChannelMessage(int $channelId, array $sender, string $content, int $messageId): void
    {
        if ((int) ($sender['bot'] ?? 0) === 1) {
            return;
        }
        $bots = self::botsForChannel($channelId);
        foreach ($bots as $bot) {
            $shouldRespond = false;
            $mode = $bot['respond_mode'] ?? 'mentions';
            if ($mode === 'all') {
                $shouldRespond = true;
            } elseif ($mode === 'mentions') {
                $pattern = '/@' . preg_quote($bot['username'], '/') . '\b/i';
                $shouldRespond = preg_match($pattern, $content) === 1;
            } elseif ($mode === 'commands') {
                $pattern = '/^\/' . preg_quote($bot['username'], '/') . '\b/i';
                $shouldRespond = preg_match($pattern, $content) === 1;
            }
            if (!$shouldRespond) {
                continue;
            }
            $secret = hash('sha256', $bot['api_key'] . $bot['id']);
            $callbackUrl = self::callbackUrl((int) $bot['id'], $secret);
            $context = json_encode([
                'type' => 'channel',
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'sender' => $sender['username'] ?? 'unknown',
            ]);
            self::forwardToGateway($bot, $content, $context, $callbackUrl);
        }
    }

    public static function onPrivateMessage(array $sender, array $recipient, string $content, int $pmId): void
    {
        if ((int) ($sender['bot'] ?? 0) === 1) {
            return;
        }
        $bots = Database::all(
            'SELECT b.* FROM openclaw_bots b
             JOIN openclaw_bot_pm_access pa ON pa.bot_id = b.id
             JOIN users u ON u.id = b.user_id
             WHERE u.username = ? COLLATE NOCASE AND b.enabled = 1',
            [$recipient['username'] ?? '']
        );
        foreach ($bots as $bot) {
            $secret = hash('sha256', $bot['api_key'] . $bot['id']);
            $callbackUrl = self::callbackUrl((int) $bot['id'], $secret);
            $context = json_encode([
                'type' => 'pm',
                'pm_id' => $pmId,
                'sender' => $sender['username'] ?? 'unknown',
                'sender_id' => (int) ($sender['id'] ?? 0),
            ]);
            self::forwardToGateway($bot, $content, $context, $callbackUrl);
        }
    }

    public static function handleCallback(int $botId, string $secret): array
    {
        $bot = Database::row('SELECT * FROM openclaw_bots WHERE id = ? AND enabled = 1', [$botId]);
        if (!$bot) {
            return ['ok' => false, 'error' => 'Bot not found or disabled.', 'status' => 404];
        }
        $expected = hash('sha256', $bot['api_key'] . $bot['id']);
        if (!hash_equals($expected, $secret)) {
            return ['ok' => false, 'error' => 'Invalid secret.', 'status' => 403];
        }
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['content'])) {
            return ['ok' => false, 'error' => 'Missing content.', 'status' => 400];
        }
        $content = mb_substr(trim((string) $payload['content']), 0, 4000);
        $kind = ($payload['kind'] ?? 'message') === 'ai_response' ? 'ai_response' : 'message';
        $context = json_decode((string) ($payload['context'] ?? '{}'), true);
        if (!is_array($context)) {
            $context = [];
        }
        $botUser = Database::row('SELECT * FROM users WHERE id = ?', [(int) $bot['user_id']]);
        if (!$botUser) {
            return ['ok' => false, 'error' => 'Bot user account missing.', 'status' => 500];
        }
        if (($context['type'] ?? '') === 'pm') {
            $senderId = (int) ($context['sender_id'] ?? 0);
            if ($senderId <= 0) {
                return ['ok' => false, 'error' => 'No PM recipient specified.', 'status' => 400];
            }
            $recipient = Database::row('SELECT * FROM users WHERE id = ?', [$senderId]);
            if (!$recipient) {
                return ['ok' => false, 'error' => 'PM recipient not found.', 'status' => 404];
            }
            $pmId = MessageService::insertPm($botUser, $recipient, $content, $kind);
            MessageService::notifyDm($recipient, $botUser, $pmId);
            MessageService::logPm((int) $botUser['id'], $botUser['username'], $recipient['username'], $content, 0);
            return ['ok' => true, 'pm_id' => $pmId];
        }
        $channelId = (int) ($context['channel_id'] ?? 0);
        if ($channelId <= 0) {
            return ['ok' => false, 'error' => 'No channel specified.', 'status' => 400];
        }
        $replyTo = isset($context['message_id']) ? (int) $context['message_id'] : null;
        $msg = MessageService::send($channelId, $botUser, $content, $kind, $replyTo > 0 ? $replyTo : null);
        return ['ok' => true, 'message_id' => (int) $msg['id']];
    }

    private static function callbackUrl(int $botId, string $secret): string
    {
        $base = rtrim((string) config_get('site_url', ''), '/');
        if ($base === '') {
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/api/openclaw/callback/' . $botId . '/' . $secret;
    }

    public static function forwardToGateway(array $bot, string $message, string $context, string $callbackUrl): void
    {
        $payload = json_encode([
            'message' => $message,
            'context' => $context,
            'system_prompt' => $bot['system_prompt'] ?? '',
            'callback_url' => $callbackUrl,
        ]);
        $ch = curl_init(rtrim($bot['gateway_url'], '/') . '/v1/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $bot['api_key'],
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

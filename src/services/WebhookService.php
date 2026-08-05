<?php

declare(strict_types=1);

final class WebhookService
{
    /** Generate a fresh random token and return its plaintext (shown once). */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Create a webhook for a channel. Returns the plaintext token (shown once). */
    public static function create(int $channelId, array $actor, string $name, string $avatar = ''): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 32 || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}| ]{2,32}$/', $name)) {
            return ['ok' => false, 'error' => 'Webhook name must be 2-32 chars (letters, numbers, spaces, IRC-safe symbols).'];
        }
        if ($avatar !== '' && !preg_match('#^https://#', $avatar)) {
            return ['ok' => false, 'error' => 'avatar_url must be an https:// URL.'];
        }
        $token = self::generateToken();
        Database::query(
            'INSERT INTO webhooks (token_hash, channel_id, name, avatar, created_by) VALUES (?, ?, ?, ?, ?)',
            [self::hashToken($token), $channelId, mb_substr($name, 0, 32), mb_substr($avatar, 0, 500), (int) $actor['id']]
        );
        log_audit('webhook_create', '#' . (string) $channelId, $name);
        return ['ok' => true, 'token' => $token, 'url' => '/api/webhooks/' . $token];
    }

    /** Look up a webhook by its plaintext token (constant-time compare). */
    public static function findByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) < 40) {
            return null;
        }
        $hash = self::hashToken($token);
        $hook = Database::row('SELECT * FROM webhooks WHERE token_hash = ?', [$hash]);
        return $hook ?: null;
    }

    /**
     * Handle an incoming POST to a webhook. Accepts Discord-compatible JSON or
     * form-encoded data. Returns [ok, error|message].
     */
    public static function post(string $token): array
    {
        $hook = self::findByToken($token);
        if (!$hook || (int) $hook['enabled'] !== 1) {
            return ['ok' => false, 'error' => 'Unknown or disabled webhook.', 'status' => 404];
        }
        $channel = Database::row('SELECT * FROM channels WHERE id = ?', [$hook['channel_id']]);
        if (!$channel) {
            return ['ok' => false, 'error' => 'Webhook channel no longer exists.', 'status' => 410];
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = [];
        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : [];
        } else {
            $payload = $_POST;
            // Form posts may send the JSON body as `payload_json`.
            if (isset($payload['payload_json'])) {
                $decoded = json_decode((string) $payload['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        $content = mb_substr(trim((string) ($payload['content'] ?? '')), 0, 2000);
        $name = trim((string) ($payload['username'] ?? $hook['name'] ?? 'Webhook'));
        $name = mb_substr($name, 0, 32);
        $avatar = trim((string) ($payload['avatar_url'] ?? $hook['avatar'] ?? ''));
        $embeds = $payload['embeds'] ?? [];

        // Build the text body: content + flattened embeds.
        $body = $content;
        if (is_array($embeds)) {
            foreach (array_slice($embeds, 0, 10) as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $parts = [];
                $title = trim((string) ($e['title'] ?? ''));
                $url = trim((string) ($e['url'] ?? ''));
                $desc = trim((string) ($e['description'] ?? ''));
                if ($title !== '') {
                    $parts[] = '**' . $title . '**' . ($url !== '' ? ' — ' . $url : '');
                } elseif ($url !== '') {
                    $parts[] = $url;
                }
                if ($desc !== '') {
                    $parts[] = mb_substr($desc, 0, 1024);
                }
                if (isset($e['author']['name']) && trim((string) $e['author']['name']) !== '') {
                    $parts[] = '— ' . mb_substr(trim((string) $e['author']['name']), 0, 200);
                }
                if ($parts) {
                    $body .= ($body !== '' ? "\n" : '') . implode("\n", $parts);
                }
            }
        }
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Webhook payload has no content or embeds.', 'status' => 400];
        }

        // Route through the same moderation checks as a normal send.
        $bot = self::botAccount($hook, $name, $avatar);
        $blocked = BanService::sendBlocked($bot, $body, 'c');
        if ($blocked) {
            return ['ok' => false, 'error' => $blocked, 'status' => 403];
        }
        $censor = CensorService::check($body, CensorService::isChannelFiltered($channel));
        if ($censor) {
            if ($censor['action'] === 'block') {
                return ['ok' => false, 'error' => 'Message blocked by the word filter.', 'status' => 403];
            }
            $body = $censor['censored'];
        }

        $msg = MessageService::send((int) $channel['id'], $bot, $body, 'message');
        Database::query('UPDATE webhooks SET last_used = datetime("now") WHERE id = ?', [(int) $hook['id']]);
        $msg['channel'] = $channel['slug'];
        Realtime::message($channel['slug'], $msg);
        return ['ok' => true, 'message' => ['id' => (int) $msg['id']]];
    }

    /** Get (or lazily create) the bot account that posts for a webhook. */
    private static function botAccount(array $hook, string $name, string $avatar): array
    {
        // Reuse a stable bot identity per webhook (username derived from name).
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $username = $slug !== '' ? mb_substr($slug, 0, 24) : 'webhook';
        $u = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE AND bot = 1', [$username]);
        if (!$u) {
            // Ensure uniqueness for bots sharing a name.
            $i = 1;
            $candidate = $username;
            while (Database::scalar('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE', [$candidate])) {
                $candidate = $username . $i++;
            }
            Database::query(
                'INSERT INTO users (username, email, password_hash, bot, avatar, role) VALUES (?, ?, ?, 1, ?, "user")',
                [$candidate, $candidate . '@webhook.local', password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID), $avatar]
            );
            $u = Database::row('SELECT * FROM users WHERE id = ?', [(int) Database::lastId()]);
        }
        if ($avatar !== '' && ($u['avatar'] ?? '') === '') {
            Database::query('UPDATE users SET avatar = ? WHERE id = ?', [$avatar, (int) $u['id']]);
            $u['avatar'] = $avatar;
        }
        return $u;
    }

    /** Delete a webhook (founder of the channel or admin). */
    public static function delete(int $id, array $actor): bool|string
    {
        $hook = Database::row('SELECT * FROM webhooks WHERE id = ?', [$id]);
        if (!$hook) {
            return 'Webhook not found.';
        }
        $ch = Database::row('SELECT * FROM channels WHERE id = ?', [$hook['channel_id']]);
        $owns = $ch && (int) ($ch['owner_id'] ?? 0) === (int) $actor['id'];
        if ($actor['role'] !== 'admin' && !$owns) {
            return 'Only the channel founder or an administrator can delete this webhook.';
        }
        Database::query('DELETE FROM webhooks WHERE id = ?', [$id]);
        log_audit('webhook_delete', 'webhook#' . $id);
        return true;
    }
}

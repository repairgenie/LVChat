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

final class OpenClawController
{
    private static function requireBot(): array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiKey = '';
        if (str_starts_with($auth, 'Bearer ')) {
            $apiKey = substr($auth, 7);
        }
        // The ?api_key= fallback leaks keys into logs/history, so it is only
        // honored for loopback clients. Use REMOTE_ADDR (the real TCP peer,
        // unforgeable) — never header-derived IPs, which can be spoofed even
        // when TRUSTED_PROXY=1.
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $isLoopback = $peer === '127.0.0.1' || $peer === '::1'
            || str_starts_with($peer, '127.')
            || $peer === 'localhost';
        if ($apiKey === '' && isset($_GET['api_key']) && $isLoopback) {
            $apiKey = (string) $_GET['api_key'];
        }
        $bot = OpenClawBotService::authenticate($apiKey);
        if (!$bot) {
            json_out(['ok' => false, 'error' => 'Invalid or missing API key.'], 401);
        }
        return $bot;
    }

    public static function channels(): void
    {
        $bot = self::requireBot();
        $channels = OpenClawBotService::getAssignedChannels((int) $bot['id']);
        json_out(['ok' => true, 'channels' => $channels]);
    }

    public static function messages(): void
    {
        $bot = self::requireBot();
        $channelSlug = trim((string) ($_GET['channel'] ?? ''));
        $since = max(0, (int) ($_GET['since'] ?? 0));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        if ($channelSlug === '') {
            json_out(['ok' => false, 'error' => 'channel parameter required.'], 400);
        }
        $messages = OpenClawBotService::getMessages((int) $bot['id'], $channelSlug, $since, $limit);
        json_out(['ok' => true, 'messages' => $messages]);
    }

    public static function pms(): void
    {
        $bot = self::requireBot();
        $since = max(0, (int) ($_GET['since'] ?? 0));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        $messages = OpenClawBotService::getPms((int) $bot['id'], $since, $limit);
        json_out(['ok' => true, 'messages' => $messages]);
    }

    public static function send(): void
    {
        $bot = self::requireBot();
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $channelSlug = trim((string) ($payload['channel'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        $kind = (string) ($payload['kind'] ?? 'message');
        $replyTo = isset($payload['reply_to']) ? (int) $payload['reply_to'] : null;
        if ($channelSlug === '') {
            json_out(['ok' => false, 'error' => 'channel is required.'], 400);
        }
        $result = OpenClawBotService::sendMessage((int) $bot['id'], $channelSlug, $content, $kind, $replyTo);
        if (!$result['ok']) {
            json_out(['ok' => false, 'error' => $result['error']], $result['status'] ?? 500);
        }
        json_out($result);
    }

    public static function pm(): void
    {
        $bot = self::requireBot();
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $recipient = trim((string) ($payload['recipient'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        $kind = (string) ($payload['kind'] ?? 'message');
        if ($recipient === '') {
            json_out(['ok' => false, 'error' => 'recipient is required.'], 400);
        }
        $result = OpenClawBotService::sendPm((int) $bot['id'], $recipient, $content, $kind);
        if (!$result['ok']) {
            json_out(['ok' => false, 'error' => $result['error']], $result['status'] ?? 500);
        }
        json_out($result);
    }
}

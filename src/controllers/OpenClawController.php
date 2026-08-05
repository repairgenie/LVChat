<?php

declare(strict_types=1);

final class OpenClawController
{
    public static function callback(array $params): void
    {
        $botId = (int) ($params['botId'] ?? 0);
        $secret = (string) ($params['secret'] ?? '');
        if ($botId <= 0 || $secret === '') {
            json_out(['ok' => false, 'error' => 'Invalid parameters.'], 400);
        }
        $result = OpenClawBotService::handleCallback($botId, $secret);
        if (!$result['ok']) {
            json_out(['ok' => false, 'error' => $result['error']], $result['status'] ?? 500);
        }
        json_out($result);
    }
}

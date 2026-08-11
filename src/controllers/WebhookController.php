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

final class WebhookController
{
    /** POST /api/webhooks/<token> — public, token-authenticated, no CSRF. */
    public static function post(array $params): void
    {
        if (config_get('webhooks_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['error' => 'Webhooks are disabled on this server.']);
            exit;
        }
        $token = (string) ($params['token'] ?? '');
        $r = WebhookService::post($token);
        http_response_code($r['status'] ?? ($r['ok'] ? 200 : 400));
        header('Content-Type: application/json; charset=utf-8');
        if ($r['ok']) {
            echo json_encode(['ok' => true, 'message' => $r['message']]);
        } else {
            echo json_encode(['error' => $r['error']]);
        }
        exit;
    }

    /** Admin UI data + management actions. */
    public static function admin(): void
    {
        $admin = Auth::requireAdmin();
        $hooks = Database::all(
            'SELECT w.*, c.name AS channel_name FROM webhooks w LEFT JOIN channels c ON c.id = w.channel_id ORDER BY w.id DESC'
        );
        $channels = Database::all('SELECT id, name, slug FROM channels WHERE forbidden = 0 ORDER BY name COLLATE NOCASE');
        render_view('admin/webhooks', ['admin' => $admin, 'hooks' => $hooks, 'channels' => $channels]);
    }

    public static function action(): void
    {
        $admin = Auth::requireAdmin();
        Csrf::verify();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'webhook_create') {
            $channelId = (int) ($_POST['channel_id'] ?? 0);
            $name = (string) ($_POST['name'] ?? '');
            $avatar = (string) ($_POST['avatar'] ?? '');
            $ch = Database::row('SELECT * FROM channels WHERE id = ?', [$channelId]);
            if (!$ch) {
                flash('Select a channel.');
            } else {
                $r = WebhookService::create($channelId, $admin, $name, $avatar);
                if (!$r['ok']) {
                    flash($r['error']);
                } else {
                    $_SESSION['webhook_token'] = $r['token'];
                    flash('Webhook created. The token is shown once on the page.');
                }
            }
        } elseif ($action === 'webhook_delete') {
            $r = WebhookService::delete((int) ($_POST['id'] ?? 0), $admin);
            flash(is_string($r) ? $r : 'Webhook deleted.');
        }
        redirect('/admin/webhooks');
    }
}

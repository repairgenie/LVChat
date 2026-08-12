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

/**
 * Realtime gateway bridge.
 *
 * PHP is request/response: after a message (or other user-facing change) is
 * persisted, the request layer calls {@see Realtime::publish()} to push a small
 * event to the Workerman gateway daemon, which fans it out to the connected
 * clients. The daemon is optional — every publish is fire-and-forget and
 * silently swallowed when the gateway is down, so the app degrades to polling.
 */
final class Realtime
{
    public const TICKET_TTL = 60; // seconds — how long a WS handshake ticket is valid

    /** True when the admin enabled the WebSocket realtime mode. */
    public static function enabled(): bool
    {
        return config_get('realtime', 'poll') === 'ws';
    }

    /** Mint a one-time WS handshake ticket for a user/guest actor. */
    public static function mintTicket(array $user): string
    {
        $token = bin2hex(random_bytes(24));
        $col = ((int) ($user['guest'] ?? 0) === 1) ? 'guest_id' : 'user_id';
        Database::query(
            "INSERT INTO ws_tickets ($col, token, created_at, expires_at)
             VALUES (?, ?, datetime('now'), datetime('now', ?))",
            [(int) $user['id'], $token, '+' . self::TICKET_TTL . ' seconds']
        );
        return $token;
    }

    /**
     * Resolve a ticket to its actor WITHOUT invalidating it. Used by the
     * gateway's connection-metering gate, which must check limits before
     * burning the single-use ticket (see bin/ws-server.php onWebSocketConnect).
     */
    public static function peekTicket(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $t = Database::row(
            'SELECT * FROM ws_tickets WHERE token = ? AND expires_at > datetime("now")',
            [$token]
        );
        if (!$t) {
            return null;
        }
        if (!empty($t['user_id'])) {
            $u = Database::row('SELECT * FROM users WHERE id = ?', [(int) $t['user_id']]);
            return $u ?: null;
        }
        if (!empty($t['guest_id'])) {
            $g = Database::row('SELECT * FROM guests WHERE id = ?', [(int) $t['guest_id']]);
            return $g ? Auth::guestActor($g) : null;
        }
        return null;
    }

    /**
     * Redeem a handshake ticket. Returns the actor array (user or guest shape)
     * and invalidates the ticket. Used by the realtime gateway.
     */
    public static function consumeTicket(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $t = Database::row(
            'SELECT * FROM ws_tickets WHERE token = ? AND expires_at > datetime("now")',
            [$token]
        );
        if (!$t) {
            return null;
        }
        Database::query('DELETE FROM ws_tickets WHERE token = ?', [$token]);
        if (!empty($t['user_id'])) {
            $u = Database::row('SELECT * FROM users WHERE id = ?', [(int) $t['user_id']]);
            return $u ?: null;
        }
        if (!empty($t['guest_id'])) {
            $g = Database::row('SELECT * FROM guests WHERE id = ?', [(int) $t['guest_id']]);
            return $g ? Auth::guestActor($g) : null;
        }
        return null;
    }

    /** The WebSocket URL the browser should connect to (env override, then config, then derived). */
    public static function clientUrl(): string
    {
        $env = getenv('WS_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $cfg = (string) (config_get('ws_url', '') ?? '');
        if ($cfg !== '') {
            return $cfg;
        }
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        // Mirror the daemon's port resolution (bin/ws-server.php): WS_PORT env
        // wins, then the ws_port config. If these ever disagreed, the browser
        // would connect to the config port while the daemon listens on the env
        // port — refused, silent fallback to polling, phantom 0 connections.
        $port = (int) (getenv('WS_PORT') ?: (config_get('ws_port', '8080') ?? 8080));
        $secure = self::requestSecure() || self::sslConfigured();
        return ($secure ? 'wss' : 'ws') . '://' . $host . ':' . $port . '/';
    }

    /** True when the gateway daemon is configured to serve TLS (WSS). */
    public static function sslConfigured(): bool
    {
        $cert = (string) (getenv('WS_SSL_CERT') ?: (config_get('ws_ssl_cert', '') ?? ''));
        $key = (string) (getenv('WS_SSL_KEY') ?: (config_get('ws_ssl_key', '') ?? ''));
        return $cert !== '' && $key !== '';
    }

    /**
     * Was this request delivered over TLS? Honors reverse-proxy headers
     * (X-Forwarded-Proto, X-Forwarded-SSL, Cloudflare CF-Visitor) as well as
     * the direct HTTPS/443 signals, so the gateway URL uses wss:// when TLS is
     * terminated upstream. An https page forces a plain ws:// into mixed content
     * and the browser blocks the handshake — which silently drops clients to
     * the polling fallback.
     */
    private static function requestSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (stripos((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') !== false) {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        if (stripos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false) {
            return true;
        }
        return false;
    }

    /** Internal push endpoint the request layer POSTs events to. */
    public static function pushUrl(): string
    {
        $env = getenv('WS_PUSH_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return (string) (config_get('ws_push_url', 'http://127.0.0.1:9001/push') ?? 'http://127.0.0.1:9001/push');
    }

    /**
     * Is the gateway daemon currently up? Queries its local /health endpoint
     * (localhost-only) and reads the Workerman pid file. Used by the admin UI.
     */
    public static function daemonStatus(): array
    {
        $running = false;
        $connections = 0;
        $wsPort = 0;
        $url = self::pushUrl();
        if ($url !== '') {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = (int) ($parts['port'] ?? 9001);
            $fp = @fsockopen((string) $host, $port, $errno, $errstr, 0.5);
            if ($fp) {
                // Bound the read so a keep-alive connection can't block us for
                // default_socket_timeout (60s); the daemon closes /health anyway.
                stream_set_timeout($fp, 1);
                fwrite($fp, "GET /health HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
                $resp = stream_get_contents($fp);
                fclose($fp);
                if (preg_match('/\{.*\}/s', (string) $resp, $m)) {
                    $j = json_decode($m[0], true);
                    if (is_array($j)) {
                        $running = !empty($j['ok']);
                        $connections = (int) ($j['connections'] ?? 0);
                        $wsPort = (int) ($j['ws_port'] ?? 0);
                    }
                }
            }
        }
        $pid = 0;
        $pidFile = ROOT . '/data/ws-server.pid';
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
        }
        // Browsers report which realtime transport they ended up on, so an admin
        // can tell a silent poll/SSE fallback from real WebSocket connections.
        $transports = [];
        foreach (Database::all(
            'SELECT transport, COUNT(*) AS n FROM rt_transports WHERE updated_at >= datetime("now", "-2 minutes") GROUP BY transport',
            []
        ) as $row) {
            $transports[$row['transport']] = (int) $row['n'];
        }
        return [
            'running' => $running,
            'connections' => $connections,
            'pid' => $pid,
            'ws_port' => $wsPort, // the port the daemon actually bound (0 = down)
            'transports' => $transports,
        ];
    }

    /** Shared secret for the internal push endpoint (auto-provisioned on first use). */
    public static function pushSecret(): string
    {
        $secret = (string) (config_get('ws_push_secret', '') ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            config_set('ws_push_secret', $secret);
        }
        return $secret;
    }

    /**
     * Fire-and-forget push to the gateway daemon. Never blocks meaningfully and
     * never throws — when the daemon is down the app simply keeps polling.
     *
     * Event shapes (see bin/ws-server.php for the routing table):
     *   ['type' => 'message', 'channel' => '<slug>', 'message' => <hydrated msg>]
     *   ['type' => 'dm', 'from' => '<nick>', 'to' => '<nick>', 'message' => <hydrated pm>]
     *   ['type' => 'bell', 'user_id' => <id>, 'notify_count' => <n>]
     */
    public static function publish(array $event): void
    {
        $url = self::pushUrl();
        if ($url === '') {
            return;
        }
        $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return;
        }
        $port = (int) ($parts['port'] ?? 9001);
        $path = $parts['path'] ?? '/push';
        $secret = self::pushSecret();
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen((string) $parts['host'], $port, $errno, $errstr, 0.2);
        if (!$fp) {
            return;
        }
        $request = "POST $path HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . ':' . $port . "\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($payload) . "\r\n"
            . "X-Push-Secret: $secret\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        @fwrite($fp, $request);
        @fclose($fp); // fire and forget — don't wait for the response
    }

    /** Broadcast a channel message to everyone subscribed to that channel. */
    public static function message(string $channelSlug, array $message): void
    {
        if (!self::enabled()) {
            return;
        }
        self::publish(['type' => 'message', 'channel' => $channelSlug, 'message' => $message]);
    }

    /** Push a private message to both conversation participants' clients.
     *  The event carries the participants' actor ids + guest flags so the
     *  gateway can authorize delivery by identity — a subscriber is only ever
     *  a participant, never a third party listening on a name. */
    public static function dm(array $from, array $to, array $message): void
    {
        if (!self::enabled()) {
            return;
        }
        self::publish([
            'type' => 'dm',
            'from' => (string) ($from['username'] ?? ''),
            'to' => (string) ($to['username'] ?? ''),
            'from_id' => (int) ($from['id'] ?? 0),
            'from_guest' => (int) ($from['guest'] ?? 0),
            'to_id' => (int) ($to['id'] ?? 0),
            'to_guest' => (int) ($to['guest'] ?? 0),
            'message' => $message,
        ]);
    }

    /** Push a channel message edit/delete/reaction to everyone viewing that channel. */
    public static function msgUpdate(string $channelSlug, string $action, int $messageId, array $extra = []): void
    {
        if (!self::enabled()) {
            return;
        }
        self::publish(array_merge([
            'type' => 'msg_update',
            'channel' => $channelSlug,
            'action' => $action,
            'message_id' => $messageId,
        ], $extra));
    }

    /** Refresh one user's notification bell on all their open tabs. */
    public static function bell(array $user): void
    {
        if (!self::enabled()) {
            return;
        }
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR guest_user_id = ?) AND read = 0',
            [(int) $user['id'], (int) $user['id']]
        );
        self::publish(['type' => 'bell', 'user_id' => (int) $user['id'], 'notify_count' => $count]);
    }

    /**
     * Tell a target actor's clients (all their open tabs viewing that channel)
     * that they were removed — a kick or a ban that evicts them. The gateway
     * bounces those connections out with the reason shown, instead of waiting
     * for the next poll to notice the membership is gone (up to 30s in WS mode).
     */
    public static function memberRemoved(array $target, string $channelSlug, string $reason): void
    {
        if (!self::enabled()) {
            return;
        }
        self::publish([
            'type' => 'member_removed',
            'user_id' => (int) $target['id'],
            'guest' => (int) ($target['guest'] ?? 0),
            'channel' => $channelSlug,
            'reason' => $reason,
        ]);
    }

    /**
     * Admin "reconnect all clients": every open tab reloads on its next poll,
     * SSE frame or WS message so it re-renders with the current gateway config
     * (fresh ticket + URL). Works for all three transports — WS clients get the
     * daemon frame; poll/SSE clients see the flag in the next payload.
     */
    public static function reconnectClients(): void
    {
        config_set('rt_reconnect_at', (string) time());
        self::publish(['type' => 'reconnect']);
    }

    /** True while a reconnect-all request is still "fresh" (short window). */
    public static function reconnectRequested(): bool
    {
        $at = (int) (config_get('rt_reconnect_at', '0') ?? '0');
        return $at > 0 && (time() - $at) <= 15;
    }
}

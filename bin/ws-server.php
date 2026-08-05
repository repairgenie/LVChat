#!/usr/bin/env php
<?php

/**
 * LVChat realtime gateway — Workerman daemon.
 *
 * Runs two listeners in one process/event loop:
 *   - WebSocket server on 0.0.0.0:8080  (ws_port) for chat clients
 *   - HTTP push endpoint on 127.0.0.1:9001 (ws_push_url) that php-fpm POSTs
 *     to after a message is persisted, so the daemon can fan it out to the
 *     subscribers who are viewing that channel/DM right now.
 *
 * Clients authenticate with a one-time ticket minted when the chat page loads
 * (see Realtime::mintTicket). Messages are fanned out as the same payload
 * shapes the poll/SSE endpoints return, so the browser reuses one handler.
 *
 * Run (as the app user):
 *   php bin/ws-server.php start       # foreground
 *   php bin/ws-server.php start -d    # daemonize
 *   php bin/ws-server.php stop|restart|reload|status
 *
 * systemd unit (recommended over -d):
 *   [Unit]
 *   Description=LVChat realtime gateway
 *   After=network.target
 *   [Service]
 *   User=www-data
 *   WorkingDirectory=/var/www/chat
 *   ExecStart=/usr/bin/php /var/www/chat/bin/ws-server.php start
 *   Restart=always
 *   RestartSec=3
 *   [Install]
 *   WantedBy=multi-user.target
 *
 * Health check for deploy scripts / uptime monitors:
 *   curl http://127.0.0.1:9001/health   -> {"ok":true,"connections":N}
 */

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

Worker::$logFile = ROOT . '/data/ws-server.log';
Worker::$pidFile = ROOT . '/data/ws-server.pid';

$wsPort = (int) (getenv('WS_PORT') ?: (config_get('ws_port', '8080') ?? 8080));
$pushUrl = (string) (getenv('WS_PUSH_URL') ?: (config_get('ws_push_url', 'http://127.0.0.1:9001/push') ?? 'http://127.0.0.1:9001/push'));
$pushHost = '127.0.0.1';
$pushPort = 9001;
$pushPath = '/push';
if (preg_match('#^http://([^:/]+)(?::(\d+))?(/.*)?$#', $pushUrl, $m)) {
    $pushHost = $m[1];
    $pushPort = (int) ($m[2] ?? 9001);
    $pushPath = $m[3] !== '' ? $m[3] : '/push';
}
$presenceThrottle = max(5, (int) (config_get('presence_throttle', '30') ?? 30));

// Authenticated connections: id => ['user' => actor, 'sub' => [type,target], 'presence_ts', 'touched'].
$state = [];

function rt_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

/** Write last_seen for an actor. $offset is 'now' or a SQLite relative modifier. */
$writePresence = function (array $actor, string $offset = 'now') use ($presenceThrottle): void {
    $isGuest = (int) ($actor['guest'] ?? 0) === 1;
    $table = $isGuest ? 'guests' : 'users';
    if ($offset === 'now') {
        Database::query("UPDATE $table SET last_seen = datetime('now') WHERE id = ?", [(int) $actor['id']]);
    } else {
        Database::query("UPDATE $table SET last_seen = datetime('now', ?) WHERE id = ?", [$offset, (int) $actor['id']]);
    }
};

$ws = new Worker('websocket://0.0.0.0:' . $wsPort);
$ws->name = 'lvchat-ws';
$ws->count = 1;

$ws->onWorkerStart = function (Worker $worker) use (&$state, $presenceThrottle, $writePresence, $pushHost, $pushPort, $pushPath): void {
    // We forked from the master process which already opened the SQLite
    // connection; re-open it inside this worker so each process owns its PDO.
    Database::close();

    $pushSecret = Realtime::pushSecret();

    // ── Internal HTTP listener (php-fpm -> daemon), same event loop ──────────
    $push = new Worker('http://' . $pushHost . ':' . $pushPort);
    $push->name = 'lvchat-push';
    $push->reusePort = false;

    $broadcast = function (array $payload, ?callable $match = null) use ($worker, &$state): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        foreach ($worker->connections as $conn) {
            $st = $state[$conn->id] ?? null;
            if ($st === null) {
                continue;
            }
            if ($match !== null && !$match($st)) {
                continue;
            }
            $conn->send($json);
        }
    };

    $push->onMessage = function (TcpConnection $conn, Request $req) use ($pushSecret, $pushPath, $broadcast, &$state): void {
        if ($req->path() !== $pushPath || $req->method() !== 'POST') {
            if ($req->path() === '/health') {
                $conn->send(json_encode(['ok' => true, 'connections' => count($state)]));
                return;
            }
            $conn->send('{"ok":false,"error":"not found"}');
            return;
        }
        $got = (string) $req->header('x-push-secret');
        if ($pushSecret !== '' && !hash_equals($pushSecret, $got)) {
            $conn->send('{"ok":false,"error":"unauthorized"}');
            return;
        }
        $event = json_decode($req->rawBody(), true);
        if (!is_array($event) || empty($event['type'])) {
            $conn->send('{"ok":false,"error":"bad event"}');
            return;
        }
        switch ($event['type']) {
            case 'message':
                // A channel message: deliver to every connection viewing that channel.
                $broadcast(['messages' => [$event['message'] ?? []]], static function (array $st) use ($event): bool {
                    return ($st['sub']['type'] ?? null) === 'channel'
                        && ($st['sub']['target'] ?? '') === ($event['channel'] ?? '');
                });
                break;
            case 'dm':
                // A private message: deliver to every connection viewing either side
                // of the conversation (so both participants' open tabs update).
                $targets = [
                    strtolower((string) ($event['from'] ?? '')),
                    strtolower((string) ($event['to'] ?? '')),
                ];
                $broadcast(['messages' => [$event['message'] ?? []]], static function (array $st) use ($targets): bool {
                    return ($st['sub']['type'] ?? null) === 'dm'
                        && in_array(strtolower((string) ($st['sub']['target'] ?? '')), $targets, true);
                });
                break;
            case 'bell':
                // Refresh one user's unread bell count across their open tabs.
                $broadcast(['notify_count' => (int) ($event['notify_count'] ?? 0)], static function (array $st) use ($event): bool {
                    return (int) ($st['user']['id'] ?? 0) === (int) ($event['user_id'] ?? -1);
                });
                break;
            case 'msg_update':
                // A channel message was edited/deleted/reacted: sync every viewer.
                $broadcast(['msg_update' => [
                    'action' => (string) ($event['action'] ?? ''),
                    'message_id' => (int) ($event['message_id'] ?? 0),
                    'content' => $event['content'] ?? null,
                    'reactions' => $event['reactions'] ?? null,
                ]], static function (array $st) use ($event): bool {
                    return ($st['sub']['type'] ?? null) === 'channel'
                        && ($st['sub']['target'] ?? '') === ($event['channel'] ?? '');
                });
                break;
            default:
                break;
        }
        $conn->send('{"ok":true}');
    };

    // Bind the push socket into this worker's event loop.
    $push->listen();
    Worker::$globalEvent->add($push->getMainSocket(), EventInterface::EV_READ, [$push, 'acceptConnection']);

    // Idle sweep: close connections that stopped sending frames (no pong).
    Timer::add(30, static function () use ($worker, &$state): void {
        $now = time();
        foreach ($worker->connections as $conn) {
            $st = $state[$conn->id] ?? null;
            if ($st !== null && $now - ($st['touched'] ?? $now) > 90) {
                $conn->close();
            }
        }
    });
};

$ws->onWebSocketConnect = function (TcpConnection $conn) use (&$state, $writePresence): void {
    $ticket = (string) ($_GET['ticket'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = (string) (config_get('ws_allowed_origin', '') ?? '');
    if ($allowed !== '' && $origin !== '' && stripos($origin, $allowed) === false) {
        rt_log('reject ws connect: bad origin ' . $origin);
        $conn->close();
        return;
    }
    $user = Realtime::consumeTicket($ticket);
    if (!$user) {
        rt_log('reject ws connect: invalid/expired ticket');
        $conn->close();
        return;
    }
    $state[$conn->id] = [
        'user' => $user,
        'sub' => ['type' => null, 'target' => null],
        'presence_ts' => 0,
        'touched' => time(),
    ];
    $writePresence($user, 'now');
    rt_log('ws connect: ' . ($user['username'] ?? '?') . ' (guest=' . ($user['guest'] ?? 0) . ')');
};

$ws->onMessage = function (TcpConnection $conn, string $data) use (&$state, $presenceThrottle, $writePresence): void {
    $st = &$state[$conn->id];
    if (!is_array($st)) {
        return;
    }
    $st['touched'] = time();
    $msg = json_decode($data, true);
    if (!is_array($msg)) {
        return;
    }
    switch ($msg['action'] ?? '') {
        case 'ping':
            // Presence heartbeat: refresh last_seen on a throttled cadence so
            // every existing isOnline() check keeps working unchanged.
            if (time() - (int) $st['presence_ts'] >= $presenceThrottle) {
                $writePresence($st['user'], 'now');
                $st['presence_ts'] = time();
            }
            $conn->send('{"pong":true}');
            break;
        case 'hello':
            $conn->send(json_encode([
                'ok' => true,
                'id' => (int) $st['user']['id'],
                'nick' => $st['user']['username'] ?? '',
                'guest' => (int) ($st['user']['guest'] ?? 0),
            ], JSON_UNESCAPED_UNICODE));
            break;
        case 'subscribe':
            if (!empty($msg['channel'])) {
                $st['sub'] = ['type' => 'channel', 'target' => (string) $msg['channel']];
                $conn->send(json_encode(['ok' => true, 'sub' => $st['sub']], JSON_UNESCAPED_UNICODE));
            } elseif (!empty($msg['dm'])) {
                $st['sub'] = ['type' => 'dm', 'target' => strtolower((string) $msg['dm'])];
                $conn->send(json_encode(['ok' => true, 'sub' => $st['sub']], JSON_UNESCAPED_UNICODE));
            } else {
                $st['sub'] = ['type' => null, 'target' => null];
            }
            break;
        default:
            $conn->send('{"ok":false,"error":"unknown action"}');
    }
};

$ws->onClose = function (TcpConnection $conn) use (&$state, $writePresence): void {
    if (isset($state[$conn->id])) {
        // Mark offline promptly instead of waiting for the 30s presence window.
        $writePresence($state[$conn->id]['user'], '-60 seconds');
        rt_log('ws close: ' . ($state[$conn->id]['user']['username'] ?? '?'));
        unset($state[$conn->id]);
    }
};

Worker::runAll();

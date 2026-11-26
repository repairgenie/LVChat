#!/usr/bin/env php
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

// Overridable so an integration test can run its own daemon without touching a
// live gateway's pid/log file (see tests/ws_test.php).
Worker::$logFile = (string) (getenv('WS_LOG_FILE') ?: ROOT . '/data/ws-server.log');
Worker::$pidFile = (string) (getenv('WS_PID_FILE') ?: ROOT . '/data/ws-server.pid');

$wsIp = (string) (getenv('WS_IP') ?: (config_get('ws_ip', '0.0.0.0') ?? '0.0.0.0'));
$wsPort = (int) (getenv('WS_PORT') ?: (config_get('ws_port', '8080') ?? 8080));
$wsSslCert = (string) (getenv('WS_SSL_CERT') ?: (config_get('ws_ssl_cert', '') ?? ''));
$wsSslKey = (string) (getenv('WS_SSL_KEY') ?: (config_get('ws_ssl_key', '') ?? ''));
$pushUrl = (string) (getenv('WS_PUSH_URL') ?: (config_get('ws_push_url', 'http://127.0.0.1:9001/push') ?? 'http://127.0.0.1:9001/push'));
$pushHost = '127.0.0.1';
$pushPort = 9001;
$pushPath = '/push';
if (preg_match('#^http://([^:/]+)(?::(\d+))?(/.*)?$#', $pushUrl, $m)) {
    $pushHost = $m[1];
    $pushPort = (int) ($m[2] ?? 9001);
    $pushPath = $m[3] !== '' ? $m[3] : '/push';
}
// presence_throttle is admin-tunable and read fresh on every application ping
// (see onMessage): one indexed config-row lookup, measured at ~4 us here, while
// pings arrive at most every 10-30 s per socket. Caching it would only add a
// staleness window, so a settings change applies to live sockets immediately.
// End-to-end liveness. The gateway sends a WebSocket PING frame to every live
// connection and closes it when nothing at all (pong or app message) arrives
// within the timeout. Browsers answer PING frames in the network stack, so this
// still proves reachability for a throttled/frozen tab whose JS timers stopped.
$wsPingInterval = max(1, (int) (getenv('WS_PING_INTERVAL') ?: 30));
$wsPongTimeout = max($wsPingInterval + 1, (int) (getenv('WS_PONG_TIMEOUT') ?: 75));

// Authenticated connections: id => ['user' => actor, 'sub' => [type,target], 'presence_ts', 'touched'].
$state = [];

function rt_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

/** Write last_seen for an actor. $offset is 'now' or a SQLite relative modifier. */
$writePresence = function (array $actor, string $offset = 'now'): void {
    $isGuest = (int) ($actor['guest'] ?? 0) === 1;
    $table = $isGuest ? 'guests' : 'users';
    if ($offset === 'now') {
        Database::query("UPDATE $table SET last_seen = datetime('now') WHERE id = ?", [(int) $actor['id']]);
    } else {
        Database::query("UPDATE $table SET last_seen = datetime('now', ?) WHERE id = ?", [$offset, (int) $actor['id']]);
    }
};

/**
 * Prove a connection is still present: refresh last_seen and its ws client row,
 * at most once per presence_throttle. Shared by application pings and protocol
 * PONGs, so a tab whose JS timers are frozen (but which still answers PINGs in
 * the network stack) does not go offline while its socket is alive.
 */
$refreshPresence = function (array &$st) use ($writePresence): void {
    if (time() - (int) ($st['presence_ts'] ?? 0) >= presence_throttle()) {
        $writePresence($st['user'], 'now');
        Realtime::touchClient($st['user'], (string) ($st['client'] ?? ''), 'ws');
        $st['presence_ts'] = time();
    }
};

// Optional WSS: when a TLS cert/key pair is configured the gateway serves
// encrypted websocket on the same port, so the browser can use wss://.
$wsSsl = $wsSslCert !== '' && $wsSslKey !== '';
$wsContext = $wsSsl ? [
    'ssl' => [
        'local_cert' => $wsSslCert,
        'local_pk' => $wsSslKey,
        'verify_peer' => false,
        'allow_self_signed' => true,
    ],
] : [];
$ws = new Worker('websocket://' . $wsIp . ':' . $wsPort, $wsContext);
if ($wsSsl) {
    $ws->transport = 'ssl';
}
$ws->name = 'lvchat-ws';
$ws->count = 1;

$ws->onWorkerStart = function (Worker $worker) use (&$state, $writePresence, $pushHost, $pushPort, $pushPath, $wsPort, $wsPingInterval, $wsPongTimeout): void {
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

    $push->onMessage = function (TcpConnection $conn, Request $req) use ($pushSecret, $pushPath, $broadcast, &$state, $wsPort): void {
        if ($req->path() !== $pushPath || $req->method() !== 'POST') {
            if ($req->path() === '/health') {
                // Report the bound WS port too, so the admin UI can flag when the
                // daemon's actual port disagrees with the ws_port config (a stale
                // daemon after a config change, or a WS_PORT env override).
                $conn->send(json_encode(['ok' => true, 'connections' => count($state), 'ws_port' => (int) $wsPort]));
                $conn->close(); // status checks read until EOF — close promptly
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
                // A private message: deliver only to connections whose
                // authenticated actor is one of the two participants (matched
                // by id + guest flag). A subscriber of a name is never treated
                // as a participant, so a third party can't eavesdrop on a DM.
                $fromId = (int) ($event['from_id'] ?? 0);
                $fromG = (int) ($event['from_guest'] ?? 0);
                $toId = (int) ($event['to_id'] ?? 0);
                $toG = (int) ($event['to_guest'] ?? 0);
                $broadcast(['messages' => [$event['message'] ?? []]], static function (array $st) use ($fromId, $fromG, $toId, $toG): bool {
                    if (($st['sub']['type'] ?? null) !== 'dm') {
                        return false;
                    }
                    $uid = (int) ($st['user']['id'] ?? 0);
                    $ug = (int) ($st['user']['guest'] ?? 0);
                    return ($uid === $fromId && $ug === $fromG) || ($uid === $toId && $ug === $toG);
                });
                break;
            case 'bell':
                // Refresh one user's unread bell count across their open tabs.
                // Match id AND guest flag (guest/user ids share a numeric space).
                $bellUid = (int) ($event['user_id'] ?? 0);
                $bellGuest = (int) ($event['guest'] ?? 0);
                $broadcast(['notify_count' => (int) ($event['notify_count'] ?? 0)], static function (array $st) use ($bellUid, $bellGuest): bool {
                    return (int) ($st['user']['id'] ?? 0) === $bellUid
                        && (int) ($st['user']['guest'] ?? 0) === $bellGuest;
                });
                break;
            case 'member_removed':
                // A kick/ban evicted the actor from a channel: bounce every one of
                // their connections that is viewing that channel out with the
                // reason, so they leave (and see why) immediately in WS mode.
                $removedUid = (int) ($event['user_id'] ?? 0);
                $removedGuest = (int) ($event['guest'] ?? 0);
                $removedChan = strtolower((string) ($event['channel'] ?? ''));
                $broadcast(['redirect' => '/app', 'reason' => (string) ($event['reason'] ?? '')], static function (array $st) use ($removedUid, $removedGuest, $removedChan): bool {
                    if ((int) ($st['user']['id'] ?? 0) !== $removedUid || (int) ($st['user']['guest'] ?? 0) !== $removedGuest) {
                        return false;
                    }
                    return ($st['sub']['type'] ?? null) === 'channel'
                        && strtolower((string) ($st['sub']['target'] ?? '')) === $removedChan;
                });
                break;
            case 'call':
                // A call rang / was accepted / was hung up: deliver to the named
                // participants (id + guest flag), whatever channel or DM each of
                // their tabs is viewing — calls are not tied to a subscription.
                // The frame is a hint: the client re-reads
                // /api/webrtc/voice/status, so a dropped frame only costs the
                // poll interval it was meant to skip.
                $callTargets = [];
                foreach ((array) ($event['targets'] ?? []) as $t) {
                    $tId = (int) ($t['id'] ?? 0);
                    if ($tId > 0) {
                        $callTargets[$tId . ':' . (int) ($t['guest'] ?? 0)] = true;
                    }
                }
                $callFrame = $event;
                unset($callFrame['type'], $callFrame['targets']);
                $broadcast(['call' => $callFrame], static function (array $st) use ($callTargets): bool {
                    $key = (int) ($st['user']['id'] ?? 0) . ':' . (int) ($st['user']['guest'] ?? 0);
                    return isset($callTargets[$key]);
                });
                break;
            case 'reconnect':
                // Admin-forced: every connected WS client reloads on this frame so
                // it re-renders with the current gateway config (poll/SSE clients
                // get the same signal from their own payloads).
                $broadcast(['reconnect' => true]);
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
            case 'dm_update':
                // A DM message was edited/deleted: deliver to both participants.
                $dmFromId = (int) ($event['from_id'] ?? 0);
                $dmFromG = (int) ($event['from_guest'] ?? 0);
                $dmToId = (int) ($event['to_id'] ?? 0);
                $dmToG = (int) ($event['to_guest'] ?? 0);
                $broadcast(['dm_update' => [
                    'action' => (string) ($event['action'] ?? ''),
                    'message_id' => (int) ($event['message_id'] ?? 0),
                    'content' => $event['content'] ?? null,
                ]], static function (array $st) use ($dmFromId, $dmFromG, $dmToId, $dmToG): bool {
                    if (($st['sub']['type'] ?? null) !== 'dm') {
                        return false;
                    }
                    $uid = (int) ($st['user']['id'] ?? 0);
                    $ug = (int) ($st['user']['guest'] ?? 0);
                    return ($uid === $dmFromId && $ug === $dmFromG) || ($uid === $dmToId && $ug === $dmToG);
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

    // Liveness sweep: PING every live connection on a fixed cadence and close
    // it when no inbound frame (pong or app message) has arrived for longer
    // than the timeout. A blackholed path leaves TCP "open" with no error, so
    // pongs are the only proof the peer is still there. Closing runs onClose →
    // presence offline, and the client's onclose → backoff reconnect with a
    // fresh ticket. The tick is short enough to enforce a short test interval.
    Timer::add(max(1, min(15, $wsPingInterval)), static function () use ($worker, &$state, $wsPingInterval, $wsPongTimeout): void {
        $now = time();
        foreach ($worker->connections as $conn) {
            $st = $state[$conn->id] ?? null;
            if ($st === null) {
                continue;
            }
            if ($now - ($st['touched'] ?? $now) > $wsPongTimeout) {
                rt_log('ws liveness: no frame for ' . ($now - (int) ($st['touched'] ?? $now)) . 's, closing ' . ($st['user']['username'] ?? '?'));
                $conn->close();
                continue;
            }
            if ($now - (int) ($st['ping_at'] ?? 0) >= $wsPingInterval) {
                // Opcode 0x9 (PING), empty payload, unmasked: FIN+PING, len 0.
                $conn->send("\x89\x00", true);
                $state[$conn->id]['ping_at'] = $now;
            }
        }
    });
};

$ws->onWebSocketConnect = function (TcpConnection $conn) use (&$state, $writePresence): void {
    $ticket = (string) ($_GET['ticket'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = (string) (config_get('ws_allowed_origin', '') ?? '');
    if ($allowed !== '' && $origin !== '') {
        // Parse the origin's host for an exact match (not substring).
        $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
        $allowedHost = parse_url($allowed, PHP_URL_HOST) ?: $allowed;
        if (strtolower($originHost) !== strtolower($allowedHost)) {
            rt_log('reject ws connect: bad origin ' . $origin);
            $conn->close();
            return;
        }
    }

    // Connection metering (SaaS module): peek the ticket so we can enforce the
    // per-account pool and the global ceiling BEFORE burning the single-use
    // ticket. Both the limit and the counts live in the shared DB + daemon
    // state; the SaaS module is optional — without it nothing is gated.
    $peeked = Realtime::peekTicket($ticket);
    if (!$peeked) {
        rt_log('reject ws connect: invalid/expired ticket');
        $conn->close();
        return;
    }
    if (class_exists('SaaSService')) {
        $ceiling = SaaSService::globalConnectionCeiling();
        if (count($state) >= $ceiling) {
            rt_log('reject ws connect: global ceiling reached (' . count($state) . '/' . $ceiling . ')');
            $conn->close(4501);
            return;
        }
        $cap = SaaSService::connectionLimit($peeked);
        if ($cap !== null) {
            $uid = (int) $peeked['id'];
            $ug = (int) ($peeked['guest'] ?? 0);
            $mine = 0;
            foreach ($state as $st) {
                if ((int) $st['user']['id'] === $uid && (int) $st['user']['guest'] === $ug) {
                    $mine++;
                }
            }
            if ($mine >= $cap) {
                rt_log('reject ws connect: ' . ($peeked['username'] ?? '?') . " at connection limit ($mine/$cap)");
                $conn->close(4502);
                return;
            }
        }
    }

    $user = Realtime::consumeTicket($ticket);
    if (!$user) {
        rt_log('reject ws connect: ticket consumed concurrently');
        $conn->close();
        return;
    }
    // Per-tab/device key from the browser so this socket can be dropped
    // without hiding the same account on another tab or device. Older clients
    // send none: fall back to a connection-scoped id.
    $wsClient = Realtime::clientKey((string) ($_GET['client'] ?? ''));
    if ($wsClient === '') {
        $wsClient = 'ws:' . getmypid() . ':' . $conn->id;
    }
    $state[$conn->id] = [
        'user' => $user,
        'sub' => ['type' => null, 'target' => null],
        'client' => $wsClient,
        'presence_ts' => time(), // connect already wrote last_seen
        'touched' => time(),
        'ping_at' => 0,
    ];
    $writePresence($user, 'now');
    Realtime::touchClient($user, $wsClient, 'ws');
    rt_log('ws connect: ' . ($user['username'] ?? '?') . ' (guest=' . ($user['guest'] ?? 0) . ')');
};

// Protocol-level PONG (the reply to our PING frame). Browsers send it from the
// network stack, so it keeps a connection alive even when its JS is throttled —
// and its absence is what tells us the path is dead.
$ws->onWebSocketPong = function (TcpConnection $conn, string $data) use (&$state, $refreshPresence): void {
    if (!isset($state[$conn->id])) {
        return;
    }
    $state[$conn->id]['touched'] = time();
    // Network-stack PONGs keep arriving for a backgrounded/frozen tab whose JS
    // timers stopped; count them as presence proof at the app-ping cadence.
    $refreshPresence($state[$conn->id]);
};

$ws->onMessage = function (TcpConnection $conn, string $data) use (&$state, $refreshPresence): void {
    $st = &$state[$conn->id];
    if (!is_array($st)) {
        return;
    }
    $st['touched'] = time();
    // Per-connection rate limit: max 30 messages/second with a burst tolerance.
    $now = time();
    if (($st['msg_window'] ?? 0) !== $now) {
        $st['msg_window'] = $now;
        $st['msg_count'] = 0;
    }
    $st['msg_count'] = ($st['msg_count'] ?? 0) + 1;
    if ($st['msg_count'] > 30) {
        return; // silently drop — too fast
    }
    $msg = json_decode($data, true);
    if (!is_array($msg)) {
        return;
    }
    switch ($msg['action'] ?? '') {
        case 'ping':
            // Presence heartbeat: refresh last_seen on a throttled cadence so
            // every existing isOnline() check keeps working unchanged.
            $refreshPresence($st);
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
            // Authorization: a connection may only subscribe to a channel it is
            // actually a member of, and only to a DM target that resolves to a
            // real actor (delivery is additionally gated by participant identity
            // in the 'dm' fan-out above). This closes the WS-mode information
            // leak where any authenticated user could subscribe to a private
            // channel slug or a username and receive traffic they weren't part
            // of — the poll/SSE paths already enforce these same checks.
            if (!empty($msg['channel'])) {
                $slug = (string) $msg['channel'];
                $ch = ChannelService::findBySlug($slug);
                if ($ch && AccessService::member((int) $ch['id'], $st['user'])) {
                    $st['sub'] = ['type' => 'channel', 'target' => $slug];
                    $conn->send(json_encode(['ok' => true, 'sub' => $st['sub']], JSON_UNESCAPED_UNICODE));
                } else {
                    $st['sub'] = ['type' => null, 'target' => null];
                    $conn->send(json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE));
                }
            } elseif (!empty($msg['dm'])) {
                $target = strtolower((string) $msg['dm']);
                if (!Auth::findActor($target)) {
                    $st['sub'] = ['type' => null, 'target' => null];
                    $conn->send(json_encode(['ok' => false, 'error' => 'no such user'], JSON_UNESCAPED_UNICODE));
                } else {
                    $st['sub'] = ['type' => 'dm', 'target' => $target];
                    $conn->send(json_encode(['ok' => true, 'sub' => $st['sub']], JSON_UNESCAPED_UNICODE));
                }
            } else {
                $st['sub'] = ['type' => null, 'target' => null];
            }
            break;
        default:
            $conn->send('{"ok":false,"error":"unknown action"}');
    }
};

$ws->onClose = function (TcpConnection $conn) use (&$state): void {
    if (!isset($state[$conn->id])) {
        return;
    }
    $actor = $state[$conn->id]['user'];
    $client = (string) ($state[$conn->id]['client'] ?? '');
    unset($state[$conn->id]);
    // Only go offline when this was the actor's last live client — closing one
    // socket (or one of several tabs/devices) must leave the account online.
    Realtime::markOfflineIfLast($actor, $client, 'ws');
    rt_log('ws close: ' . ($actor['username'] ?? '?'));
};

// Workerman forks a master + worker processes on Linux, so the pcntl and posix
// extensions are required. Give a clear message instead of a fatal error. The
// core app is unaffected — clients simply fall back to polling/SSE.
$cmd = strtolower((string) ($argv[1] ?? 'start'));
if (in_array($cmd, ['start', 'restart', 'reload'], true)
    && (!function_exists('pcntl_fork') || !function_exists('posix_kill'))) {
    fwrite(STDERR, "LVChat realtime gateway cannot start: the PHP pcntl and posix extensions are missing.\n");
    fwrite(STDERR, "On Debian/Ubuntu:  sudo apt install php-cli php-common\n");
    fwrite(STDERR, "Then verify:        php -m | grep -iE 'pcntl|posix'\n");
    fwrite(STDERR, "The chat app keeps working with polling/SSE — only WebSocket mode needs this daemon.\n");
    exit(1);
}

Worker::runAll();

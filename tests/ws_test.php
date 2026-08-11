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
 * WebSocket gateway integration test.
 *
 * Spawns the realtime daemon against a scratch DB, connects raw WebSocket
 * clients (deterministic, no Workerman process model), and verifies that
 * channel messages, private messages, and message updates are fanned out to
 * subscribers. The gateway routes each connection by its single subscription
 * (channel or DM), mirroring the app.
 *
 * Usage:
 *   WS_PORT=9092 WS_PUSH_PORT=9093 php tests/ws_test.php
 *
 * Skipped when the test ports are already in use or the daemon can't start.
 */

putenv('CHAT_DB=' . (getenv('CHAT_DB') ?: '/tmp/opencode/chat-ws-test.db'));
$dbPath = getenv('CHAT_DB');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
    if (file_exists($f)) {
        @unlink($f);
    }
}

$wsPort = (int) (getenv('WS_PORT') ?: 9092);
$pushPort = (int) (getenv('WS_PUSH_PORT') ?: 9093);
$wsUrl = 'ws://127.0.0.1:' . $wsPort;
$pushUrl = 'http://127.0.0.1:' . $pushPort . '/push';
$logFile = dirname($dbPath) . '/ws-test-daemon.log';

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function wcheck(string $label, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['passed']++;
        echo "  ok  $label\n";
    } else {
        $GLOBALS['failed']++;
        echo "FAIL  $label  $detail\n";
    }
}

// ── Setup: two users + ws mode ─────────────────────────────────────────────
config_set('realtime', 'ws');
config_set('ws_port', (string) $wsPort);
config_set('ws_push_url', $pushUrl);

Auth::register('rtalice', 'rtalice@example.com', 'password123', true);
Auth::register('rtbob', 'rtbob@example.com', 'password123', true);
$alice = Auth::attempt('rtalice', 'password123');
$bob = Auth::attempt('rtbob', 'password123');
if (!$alice || !$bob) {
    echo "SKIP  could not create test users\n";
    exit(0);
}

// ── Spawn the daemon ────────────────────────────────────────────────────────
$cmd = 'WS_PORT=' . $wsPort
    . ' WS_PUSH_URL=' . escapeshellarg($pushUrl)
    . ' CHAT_DB=' . escapeshellarg($dbPath)
    . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/ws-server.php') . ' start';
$proc = proc_open($cmd, [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
if (!is_resource($proc)) {
    echo "SKIP  cannot spawn daemon\n";
    exit(0);
}
register_shutdown_function(function () use ($dbPath): void {
    $stop = 'CHAT_DB=' . escapeshellarg($dbPath)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/ws-server.php') . ' stop';
    @exec($stop . ' > /dev/null 2>&1');
});

$ready = false;
for ($i = 0; $i < 50; $i++) {
    $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);
    $body = @file_get_contents('http://127.0.0.1:' . $pushPort . '/health', false, $ctx);
    if ($body !== false) {
        $ready = true;
        break;
    }
    usleep(200000);
}
if (!$ready) {
    echo "SKIP  daemon did not become healthy\n";
    exit(0);
}
wcheck('daemon healthy', true);

// ── Raw WebSocket client helpers ────────────────────────────────────────────
function wsOpen(string $wsUrl, string $ticket)
{
    $addr = preg_replace('#^ws://#', 'tcp://', $wsUrl);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($addr, $errno, $errstr, 3);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 2);
    $key = base64_encode(random_bytes(16));
    $path = '/?ticket=' . urlencode($ticket);
    $req = "GET $path HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: $key\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
    fwrite($fp, $req);
    $resp = '';
    while (strpos($resp, "\r\n\r\n") === false) {
        $chunk = fread($fp, 1024);
        if ($chunk === false || $chunk === '') {
            fclose($fp);
            return false;
        }
        $resp .= $chunk;
    }
    if (strpos($resp, '101') === false) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function wsSend($fp, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    $header = "\x81";
    if ($len < 126) {
        $header .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $header .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $header .= chr(0x80 | 127) . pack('NN', 0, $len);
    }
    $masked = $payload ^ str_repeat($mask, intdiv($len + 3, 4));
    fwrite($fp, $header . $mask . substr($masked, 0, $len));
}

function wsReceive($fp, float $seconds): array
{
    $messages = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $r = [$fp];
        $w = null;
        $e = null;
        $sel = @stream_select($r, $w, $e, 0, 200000);
        if (!$sel) {
            continue;
        }
        // Read one frame. First byte: FIN + opcode. Second byte: mask + len.
        $b0 = fread($fp, 1);
        if ($b0 === false || $b0 === '') {
            break;
        }
        $b1 = ord(fread($fp, 1));
        $len = $b1 & 0x7f;
        if ($len === 126) {
            $len = unpack('n', fread($fp, 2))[1];
        } elseif ($len === 127) {
            $ext = unpack('N2', fread($fp, 8));
            $len = ($ext[1] << 32) | $ext[2];
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($fp, $len - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break 2;
            }
            $payload .= $chunk;
        }
        $opcode = ord($b0) & 0x0f;
        if ($opcode === 0x1) { // text frame
            $messages[] = $payload;
        }
    }
    return $messages;
}

// ── Open two subscriptions for alice: a channel and a DM ───────────────────
$connA = wsOpen($wsUrl, Realtime::mintTicket($alice));
wcheck('channel connection opens', is_resource($connA));
$connB = wsOpen($wsUrl, Realtime::mintTicket($alice));
wcheck('dm connection opens', is_resource($connB));
if (!is_resource($connA) || !is_resource($connB)) {
    echo "FAIL  could not open websocket connections\n";
    exit(1);
}

wsSend($connA, json_encode(['action' => 'subscribe', 'channel' => 'general']));
wsSend($connB, json_encode(['action' => 'subscribe', 'dm' => 'rtbob']));

// Drain both subscription acks.
$ackA = wsReceive($connA, 2);
$ackB = wsReceive($connB, 2);
wcheck('channel sub acked', isset($ackA[0]) && strpos($ackA[0], '"ok":true') !== false, implode('|', $ackA));
wcheck('dm sub acked', isset($ackB[0]) && strpos($ackB[0], '"ok":true') !== false, implode('|', $ackB));

// ── Health endpoint reflects live connections ─────────────────────────────
$health = function () use ($pushUrl): array {
    $base = str_replace('/push', '/health', $pushUrl);
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $body = @file_get_contents($base, false, $ctx);
    return $body !== false ? json_decode($body, true) : [];
};
$h = $health();
wcheck('health reports live connections', (int) ($h['connections'] ?? 0) >= 2, json_encode($h));
wcheck('health reports the bound ws port', (int) ($h['ws_port'] ?? 0) === $wsPort, json_encode($h));

// ── Fire the pushes (simulates other users' php-fpm requests) ──────────────
Realtime::message('general', ['id' => 600001, 'kind' => 'message', 'content' => 'ws msg', 'username' => 'rtalice', 'channel' => 'general']);
Realtime::dm($alice, $bob, ['id' => 600002, 'kind' => 'message', 'content' => 'ws dm', 'username' => 'rtalice', 'is_pm' => true]);
Realtime::msgUpdate('general', 'delete', 600003);

// Collect frames.
$framesA = wsReceive($connA, 2);
$framesB = wsReceive($connB, 2);

$allA = implode(' ', $framesA);
$allB = implode(' ', $framesB);

wcheck('channel message broadcast', strpos($allA, '600001') !== false, $allA);
wcheck('message update broadcast', strpos($allA, 'msg_update') !== false && strpos($allA, '600003') !== false, $allA);
wcheck('dm broadcast', strpos($allB, '600002') !== false, $allB);

// ── Admin reconnect broadcast ─────────────────────────────────────────────
Realtime::reconnectClients();
$framesR = wsReceive($connA, 2);
wcheck('reconnect frame broadcast', strpos(implode(' ', $framesR), '"reconnect"') !== false, implode(' ', $framesR));

fclose($connA);
fclose($connB);

// ── Invalid ticket rejected ─────────────────────────────────────────────────
$bad = wsOpen($wsUrl, 'not-a-real-ticket');
wcheck('invalid ticket rejected', $bad === false);

echo "ws_test: {$GLOBALS['passed']} passed, {$GLOBALS['failed']} failed\n";
exit($GLOBALS['failed'] === 0 ? 0 : 1);

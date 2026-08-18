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
 * RealtimeGatewayCheckJob — watchdog for the WebSocket gateway daemon.
 *
 * Runs every minute via scheduledjobs.php. When WebSocket realtime mode is
 * selected it probes the gateway's local /health endpoint and restarts the
 * daemon if it died (or is serving a stale ws_port after a config change).
 * When websocket mode is off, or the daemon is healthy, this job is a no-op.
 *
 * Note: runs as whatever user the system cron invokes scheduledjobs.php as —
 * that user must own data/ (pid file + log) for the restart to take.
 */
final class RealtimeGatewayCheckJob
{
    /** Run the gateway health check. Called every minute by the cron runner. */
    public static function run(): void
    {
        if (!Realtime::enabled()) {
            return;
        }
        if (!CommandRunner::available()) {
            error_log('RealtimeGatewayCheckJob: shell execution is disabled — cannot restart the gateway');
            return;
        }

        $wantPort = (int) (getenv('WS_PORT') ?: (config_get('ws_port', '8080') ?? 8080));
        $status = Realtime::daemonStatus();

        // Healthy: /health answers AND the daemon is bound to the configured
        // port. A daemon on a different port means a stale instance survived a
        // config change — every client would silently fall back to polling, so
        // that counts as down for the purposes of this watchdog.
        if (!empty($status['running']) && (int) $status['ws_port'] === $wantPort) {
            return;
        }

        $notes = self::restart();
        if ($notes === null) {
            return;
        }

        if ($notes['ok']) {
            log_audit('ws_daemon_self_heal', 'gateway', $notes['detail']);
            echo '[' . gmdate('Y-m-d H:i:s') . '] RealtimeGatewayCheckJob: gateway was down — restarted. ' . $notes['detail'] . PHP_EOL;
        } else {
            log_audit('ws_daemon_self_heal_failed', 'gateway', $notes['detail']);
            echo '[' . gmdate('Y-m-d H:i:s') . '] RealtimeGatewayCheckJob: gateway restart FAILED. ' . $notes['detail'] . PHP_EOL;
        }
    }

    /** Stop any stale daemon, start a fresh one, verify. Null when nothing was needed. */
    private static function restart(): ?array
    {
        $cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/bin/ws-server.php');
        $wsPort = (int) (getenv('WS_PORT') ?: (config_get('ws_port', '8080') ?? 8080));
        $pushParts = parse_url((string) Realtime::pushUrl());
        $pushPort = (int) ($pushParts['port'] ?? 9001);
        $detail = [];

        // Workerman's stop/restart signals the master PID from the pid file. A
        // stale pid file makes that a no-op while the old daemon keeps the
        // ports — sweep the daemon's ports directly too.
        CommandRunner::run($cli . ' stop', 20);
        foreach ([$wsPort, $pushPort] as $p) {
            $pids = self::pidsOnPort($p);
            if (!$pids) {
                continue;
            }
            foreach ($pids as $pid) {
                CommandRunner::run('kill -TERM ' . (int) $pid . ' 2>/dev/null', 5);
            }
            usleep(500000);
            foreach ($pids as $pid) {
                CommandRunner::run('kill -9 ' . (int) $pid . ' 2>/dev/null', 5);
            }
            $detail[] = 'cleared stale pid(s) ' . implode(',', $pids) . ' on port ' . $p;
        }

        [$code, $output] = CommandRunner::run($cli . ' start -d', 20);
        if ($code !== 0 && self::pidsOnPort($wsPort)) {
            // Something reclaimed the port mid-start — clear it and retry once.
            foreach (self::pidsOnPort($wsPort) as $pid) {
                CommandRunner::run('kill -9 ' . (int) $pid . ' 2>/dev/null', 5);
            }
            usleep(500000);
            [$code, $output] = CommandRunner::run($cli . ' start -d', 20);
        }
        if ($output !== '') {
            $detail[] = $output;
        }

        // Give the daemon a moment to bind, then confirm health.
        $ok = false;
        for ($i = 0; $i < 5; $i++) {
            $s = Realtime::daemonStatus();
            if (!empty($s['running']) && (int) $s['ws_port'] === $wsPort) {
                $ok = true;
                $detail[] = "healthy on port {$s['ws_port']} (" . (int) $s['connections'] . ' connections)';
                break;
            }
            sleep(1);
        }
        return ['ok' => $ok, 'detail' => implode(' | ', $detail)];
    }

    /**
     * PIDs of LVChat gateway processes (cmdline contains ws-server.php or
     * WorkerMan, the renamed worker title) listening on a TCP port. Reads
     * /proc on Linux; empty elsewhere.
     */
    private static function pidsOnPort(int $port): array
    {
        $hex = strtoupper(dechex($port));
        $inodes = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
            $lines = @file($f);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 10 || ($parts[3] ?? '') !== '0A') {
                    continue; // not a LISTEN socket
                }
                $local = (string) ($parts[1] ?? '');
                $colon = strrpos($local, ':');
                if ($colon !== false && strtoupper(substr($local, $colon + 1)) === $hex) {
                    $inodes[(int) ($parts[9] ?? 0)] = true;
                }
            }
        }
        if (!$inodes) {
            return [];
        }
        $pids = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $pid = (int) basename($dir);
            $cmd = @file_get_contents($dir . '/cmdline');
            if ($cmd === false
                || (stripos($cmd, 'ws-server.php') === false && stripos($cmd, 'WorkerMan') === false)) {
                continue;
            }
            foreach (@glob($dir . '/fd/*') ?: [] as $fd) {
                $target = @readlink($fd);
                if ($target !== false && preg_match('/socket:\[(\d+)\]/', $target, $m) && isset($inodes[(int) $m[1]])) {
                    $pids[$pid] = true;
                    break;
                }
            }
        }
        return array_keys($pids);
    }
}
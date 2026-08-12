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
 * LiveKitService — the only place the WebRTC module talks to LiveKit.
 *
 * Responsibilities:
 *   - Mint LiveKit access tokens (JWT, HS256, zero external deps — hash_hmac).
 *   - Probe the media server /health endpoint for the admin status panel.
 *   - Derive the current voice status (active sessions, cap, "full").
 *   - Manage the user-space LiveKit deployment: write the config under
 *     data/livekit/ and start/restart livekit-server with nohup as the site
 *     user (HestiaCP/shared-hosting friendly — no root, no systemd).
 *
 * Design notes:
 *   - Tokens are short-lived (60 s, like ws_tickets) and never stored beyond
 *     the voice_sessions row. The browser holds them in memory only.
 *   - Room state (participants) is read from the app's voice_sessions table,
 *     not from LiveKit's server API — the client disconnects the Room on leave
 *     and LiveKit reclaims empty rooms automatically. Server-side kick/room
 *     listing via LiveKit's admin API is a documented follow-up (module README).
 */
final class LiveKitService
{
    public const TOKEN_TTL = 60; // seconds

    /** Whether voice is switched on and a key pair is configured. */
    public static function enabled(): bool
    {
        if ((string) (config_get('voice_enabled', '0') ?? '0') !== '1') {
            return false;
        }
        return self::apiKey() !== ''
            && self::apiSecret() !== ''
            && self::url() !== '';
    }

    public static function url(): string
    {
        return trim((string) (config_get('livekit_url', '') ?? ''));
    }

    public static function apiKey(): string
    {
        return trim((string) (config_get('livekit_api_key', '') ?? ''));
    }

    public static function apiSecret(): string
    {
        return (string) (config_get('livekit_api_secret', '') ?? '');
    }

    public static function maxUsers(): int
    {
        return max(1, min(200, (int) (config_get('voice_max_users', '50') ?? 50)));
    }

    /** How long an unanswered call rings before it fails as 'missed' (~5 rings). */
    public static function ringSeconds(): int
    {
        return max(10, min(120, (int) (config_get('call_ring_seconds', '25') ?? 25)));
    }

    public static function talkerCap(): int
    {
        return max(1, min(50, (int) (config_get('voice_talker_cap', '8') ?? 8)));
    }

    public static function bitrate(): int
    {
        return max(16000, min(64000, (int) (config_get('voice_bitrate', '40000') ?? 40000)));
    }

    /** A stable LiveKit identity for an actor (used as JWT `sub`). */
    public static function identity(array $actor): string
    {
        return ((int) ($actor['guest'] ?? 0) === 1 ? 'g' : 'u') . (int) $actor['id'];
    }

    /**
     * Mint a LiveKit access token (JWT HS256).
     *
     * Grants: join + implicit-create for the room, publish/subscribe audio, and
     * maxParticipants pinned to the admin cap (the hard ceiling §5.4 of
     * docs/webrtc-implementation.md — LiveKit enforces it even if the app gate
     * is bypassed).
     */
    public static function token(string $room, array $actor, int $maxParticipants, array $extra = []): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge([
            'iss' => self::apiKey(),
            'sub' => self::identity($actor),
            'name' => (string) ($actor['username'] ?? ''),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL,
            'video' => array_merge([
                'roomJoin' => true,
                'roomCreate' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
                'maxParticipants' => $maxParticipants,
                'roomAdmin' => false,
            ], $extra),
        ], $extra['claim'] ?? []);

        $h = self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(hash_hmac('sha256', "$h.$p", self::apiSecret(), true));
        return "$h.$p.$sig";
    }

    /** Current app-level voice status (join gate + admin panel source). */
    public static function status(): array
    {
        $active = (int) Database::scalar(
            'SELECT COUNT(*) FROM voice_sessions WHERE last_seen >= datetime("now", "-2 minutes")'
        );
        $max = self::maxUsers();
        return [
            'enabled' => self::enabled(),
            'active' => $active,
            'max' => $max,
            'full' => self::enabled() && $active >= $max,
        ];
    }

    /**
     * Best-effort /health probe of the media server. Never throws. The
     * configured URL may be ws(s)://; health is checked over http(s) on the
     * same host/port (LiveKit serves /health on its HTTP/RTCP port).
     */
    public static function health(): array
    {
        $url = self::url();
        if ($url === '') {
            return ['running' => false, 'error' => 'not configured'];
        }
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 7880);
        if ($host === '') {
            return ['running' => false, 'error' => 'invalid url'];
        }
        $scheme = isset($parts['scheme']) && $parts['scheme'] === 'wss' ? 'https' : 'http';
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (!$fp) {
            return ['running' => false, 'error' => $errstr !== '' ? $errstr : 'connection failed'];
        }
        stream_set_timeout($fp, 1);
        $path = (string) ($parts['path'] ?? '');
        $path = $path !== '' && $path !== '/' ? rtrim($path, '/') . '/health' : '/health';
        fwrite(
            $fp,
            "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n"
        );
        $resp = (string) stream_get_contents($fp);
        fclose($fp);
        $ok = str_contains($resp, '200')
            && preg_match('/\{.*\}/s', $resp, $m)
            && !empty(json_decode($m[0], true)['ok']);
        return ['running' => $ok, 'error' => $ok ? '' : 'not ok'];
    }

    /**
     * User-space LiveKit deployment. On shared hosting (HestiaCP etc.) the app
     * runs as the site user, so the media server lives entirely under the app's
     * own data/ dir: config + pidfile + log are all owned by that user and the
     * process is spawned with nohup (same pattern as the WS gateway pidfile).
     * $LIVEKIT_YAML env can override the config path for unusual installs.
     */
    public static function configPath(): string
    {
        $env = getenv('LIVEKIT_YAML');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return ROOT . '/data/livekit/livekit.yaml';
    }

    public static function pidPath(): string
    {
        return ROOT . '/data/livekit/livekit.pid';
    }

    public static function logPath(): string
    {
        return ROOT . '/data/livekit/livekit.log';
    }

    /** The livekit-server binary, or null when not installed. */
    public static function binaryPath(): ?string
    {
        $home = (string) (getenv('HOME') ?: '');
        $cands = [];
        foreach (['/usr/local/bin/livekit-server', '/usr/local/sbin/livekit-server', '/usr/bin/livekit-server'] as $p) {
            $cands[] = $p;
        }
        if ($home !== '') {
            $cands[] = $home . '/.local/bin/livekit-server';
            $cands[] = $home . '/livekit/livekit-server';
        }
        foreach ($cands as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        if (CommandRunner::available()) {
            [$rc, $out] = CommandRunner::run('command -v livekit-server 2>/dev/null', 5);
            if ($rc === 0 && trim($out) !== '' && is_executable(trim($out))) {
                return trim($out);
            }
        }
        return null;
    }

    /** PID of the app-managed livekit process (from the pidfile), or null. */
    public static function processPid(): ?int
    {
        $pidFile = self::pidPath();
        if (!is_file($pidFile)) {
            return null;
        }
        $pid = (int) trim((string) @file_get_contents($pidFile));
        if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
            return null;
        }
        $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
        return stripos($cmd, 'livekit-server') !== false ? $pid : null;
    }

    /** PIDs of every livekit-server process visible on this host. */
    public static function livekitPids(): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $pid = (int) basename($dir);
            $cmd = (string) @file_get_contents($dir . '/cmdline');
            if (stripos($cmd, 'livekit-server') !== false) {
                $pids[] = $pid;
            }
        }
        return $pids;
    }

    /**
     * Write (or update) the key pair in the user-space config file and return
     * [written, config]. Existing keys are preserved; a missing file gets a
     * fresh starter config matching the module defaults.
     */
    public static function writeKeysConfig(string $key, string $secret): array
    {
        $config = self::configPath();
        @mkdir(dirname($config), 0775, true);
        $existing = (string) @file_get_contents($config);
        if (trim($existing) === '') {
            $port = (int) (parse_url(self::url(), PHP_URL_PORT) ?: 7880);
            $new = "# LiveKit config generated by LVChat autoconfigure\n"
                . "port: $port\n"
                . "rtc:\n"
                . "  tcp_port: " . ($port + 1) . "\n"
                . "  port_range_start: 50000\n"
                . "  port_range_end: 50200\n"
                . "keys:\n"
                . "  $key: $secret\n";
        } else {
            $new = self::upsertKeysYaml($existing, $key, $secret);
        }
        if (@file_put_contents($config, $new) !== false) {
            return ['written' => true, 'config' => $config, 'message' => ''];
        }
        return ['written' => false, 'config' => $config, 'message' => 'permission denied'];
    }

    /**
     * Ensure a livekit-server is running with the given config, as the current
     * (site) user. Stops any livekit-server it can, spawns a fresh one with
     * nohup, records its pid, and reports what happened. Never throws.
     */
    public static function ensureRunning(string $config): array
    {
        $bin = self::binaryPath();
        if ($bin === null) {
            return ['running' => false, 'pid' => null, 'message' => 'livekit-server binary not found (install with: curl -sSL https://get.livekit.io | bash)'];
        }

        foreach (self::livekitPids() as $pid) {
            CommandRunner::run('kill -TERM ' . (int) $pid . ' 2>/dev/null', 5);
        }
        usleep(400000);
        foreach (self::livekitPids() as $pid) {
            CommandRunner::run('kill -9 ' . (int) $pid . ' 2>/dev/null', 5);
        }

        @mkdir(dirname(self::logPath()), 0775, true);
        [$rc, $out] = CommandRunner::run(
            'nohup ' . escapeshellarg($bin) . ' --config ' . escapeshellarg($config)
            . ' >> ' . escapeshellarg(self::logPath()) . ' 2>&1 & echo $!',
            5
        );
        $pid = (int) trim((string) $out);
        usleep(1200000);

        $alive = $pid > 0 && is_dir('/proc/' . $pid);
        if ($alive) {
            @file_put_contents(self::pidPath(), (string) $pid);
        }
        $log = (string) @file_get_contents(self::logPath());
        $logTail = trim($log) !== '' ? ' ' . trim(substr($log, -400)) : '';
        $health = self::health();

        return [
            'running' => $alive && $health['running'],
            'pid' => $alive ? $pid : null,
            'message' => $alive
                ? ($health['running']
                    ? 'livekit running as user (pid ' . $pid . ')'
                    : 'process started (pid ' . $pid . ') but /health not ok' . $logTail)
                : 'livekit failed to start' . ($logTail !== '' ? ': ' . $logTail : ''),
        ];
    }

    /** For the admin status panel: where the managed server lives. */
    public static function daemonInfo(): array
    {
        $bin = self::binaryPath();
        $pid = self::processPid();
        return [
            'binary' => $bin ?? '',
            'config' => self::configPath(),
            'pid' => $pid ?? 0,
            'managed' => $pid !== null,
            'log' => self::logPath(),
        ];
    }

    /**
     * Merge `keys:\n  <key>: <secret>` into a YAML config without a YAML lib.
     * Adds a single entry under an existing top-level `keys:` block, or appends
     * a fresh block when the file has none (LiveKit defaults cover the rest).
     */
    private static function upsertKeysYaml(string $yaml, string $key, string $secret): string
    {
        $entry = '  ' . $key . ': ' . $secret;
        $lines = preg_split('/\R/', $yaml) ?: [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^keys:[ \t]*(?:#.*)?$/', $line) !== 1) {
                continue;
            }
            for ($j = $i + 1; $j < count($lines); $j++) {
                $l = $lines[$j];
                if ($l !== '' && preg_match('/^\S/', $l) === 1) {
                    break; // past the keys block
                }
                if (preg_match('/^\s+' . preg_quote($key, '/') . '\s*:/', $l) === 1) {
                    return rtrim($yaml, "\n") . "\n"; // already present
                }
            }
            array_splice($lines, $i + 1, 0, [$entry]);
            return implode("\n", $lines) . "\n";
        }

        return rtrim($yaml, "\n") . ($yaml !== '' ? "\n" : '') . "keys:\n" . $entry . "\n";
    }

    /** Prune voice_sessions whose client stopped heartbeating (~2 min). */
    public static function pruneStale(): void
    {
        Database::query('DELETE FROM voice_sessions WHERE last_seen < datetime("now", "-2 minutes")');
    }

    /** Fail ringing calls nobody answered (~5 rings, see ringSeconds()). */
    public static function expireCalls(): void
    {
        Database::query(
            "UPDATE call_sessions SET status = 'missed'
             WHERE status = 'ringing' AND created_at < datetime('now', '-' || ? || ' seconds')",
            [self::ringSeconds()]
        );
    }

    /** Remove a participant from a LiveKit room server-side (best effort). */
    public static function removeParticipant(string $room, string $identity): void
    {
        // The client disconnect already removes the participant; this hook exists
        // for future server-side kicks via LiveKit's admin API. Deliberately a
        // no-op until that API surface is pinned (see module README / Q4).
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

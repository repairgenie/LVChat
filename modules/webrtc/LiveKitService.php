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

    /** How long an unanswered call rings before it fails as 'missed' (~4 rings). */
    public static function ringSeconds(): int
    {
        return max(10, min(120, (int) (config_get('call_ring_seconds', '20') ?? 20)));
    }

    public static function talkerCap(?array $actor = null): int
    {
        $cap = (int) (config_get('voice_talker_cap', '8') ?? 8);
        if ($actor !== null && class_exists('SaaSService')) {
            $qos = SaaSService::voiceQos($actor);
            if ($qos['talker_cap'] !== null && $qos['talker_cap'] > 0) {
                $cap = $qos['talker_cap'];
            }
        }
        return max(1, min(50, $cap));
    }

    public static function bitrate(?array $actor = null): int
    {
        $bitrate = (int) (config_get('voice_bitrate', '40000') ?? 40000);
        if ($actor !== null && class_exists('SaaSService')) {
            $qos = SaaSService::voiceQos($actor);
            if ($qos['bitrate'] !== null && $qos['bitrate'] > 0) {
                $bitrate = $qos['bitrate'];
            }
        }
        return max(16000, min(64000, $bitrate));
    }

    /** Allowed video quality labels (admin whitelist). */
    private const VIDEO_QUALITIES = ['360p', '480p', '720p', '1080p'];

    /** Default video quality for users who haven't chosen one. */
    public static function videoQualityDefault(): string
    {
        $q = (string) (config_get('video_quality_default', '720p') ?? '720p');
        return in_array($q, self::VIDEO_QUALITIES, true) ? $q : '720p';
    }

    /** Which video qualities users are allowed to pick (comma-separated setting). */
    public static function videoQualityAvailable(): array
    {
        $raw = trim((string) (config_get('video_quality_available', '360p,480p,720p,1080p') ?? ''));
        if ($raw === '') {
            return ['360p', '480p', '720p', '1080p'];
        }
        $parts = array_map('trim', explode(',', $raw));
        $valid = array_values(array_intersect($parts, self::VIDEO_QUALITIES));
        return $valid !== [] ? $valid : ['720p'];
    }

    /** A stable LiveKit identity for an actor (used as JWT `sub`). */
    public static function identity(array $actor): string
    {
        return ((int) ($actor['guest'] ?? 0) === 1 ? 'g' : 'u') . (int) $actor['id'];
    }

    /** Inverse of identity(): 'u12' → ['user_id' => 12], 'g3' → ['guest_id' => 3]. */
    public static function parseIdentity(string $identity): ?array
    {
        if (preg_match('/^u(\d+)$/', $identity, $m)) {
            return ['user_id' => (int) $m[1]];
        }
        if (preg_match('/^g(\d+)$/', $identity, $m)) {
            return ['guest_id' => (int) $m[1]];
        }
        return null;
    }

    /** The HTTP/HTTPS base URL for server-side admin calls (ws→http, wss→https). */
    public static function httpUrl(): string
    {
        $url = self::url();
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'ws://')) {
            return 'http://' . substr($url, 5);
        }
        if (str_starts_with($url, 'wss://')) {
            return 'https://' . substr($url, 6);
        }
        return $url;
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
        // Waiting-room occupants hold session rows (so hosts can see them) but
        // are not connected to LiveKit — they must not consume the global cap.
        $active = (int) Database::scalar(
            'SELECT COUNT(*) FROM voice_sessions WHERE last_seen >= datetime("now", "-2 minutes") AND waiting = 0'
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

    // ── Admin API (Twirp over HTTP, zero external deps) ─────────────────────
    //
    // LiveKit exposes its admin surface as Twirp RPCs on the server's HTTP
    // port (the same host/port as the /health probe): services livekit.RoomService
    // and livekit.Egress, called as JSON POSTs with `Authorization: Bearer
    // <admin JWT>`. The admin JWT is a normal access token minted with our API
    // key + secret — same signing path as user tokens, no extra deps.

    /** Mint a short-lived admin token (room + egress admin grants). */
    public static function adminToken(): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => self::apiKey(),
            'sub' => 'lvc-admin',
            'name' => 'LVChat Admin',
            'iat' => $now,
            'exp' => $now + 30, // short: minted per admin call
            'video' => [
                'roomCreate' => true,
                'roomList' => true,
                'roomAdmin' => true,
                'egressAdmin' => true,
            ],
        ];
        $h = self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(hash_hmac('sha256', "$h.$p", self::apiSecret(), true));
        return "$h.$p.$sig";
    }

    /**
     * Call a LiveKit Twirp endpoint: "RoomService/ListRooms", "Egress/ListEgress", …
     * Returns ['ok' => true, 'data' => decoded body] on 2xx, or
     * ['ok' => false, 'error' => msg] on any failure. Never throws.
     */
    public static function adminCall(string $endpoint, array $payload = []): array
    {
        $base = self::httpUrl();
        if ($base === '' || self::apiKey() === '' || self::apiSecret() === '') {
            return ['ok' => false, 'error' => 'not configured'];
        }
        $url = rtrim($base, '/') . '/twirp/livekit.' . $endpoint;
        $body = json_encode(
            array_filter($payload, static fn ($v) => $v !== null),
            JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            return ['ok' => false, 'error' => 'bad payload'];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . self::adminToken(),
        ];

        // Prefer curl when the extension is loaded (PushService already depends
        // on it); fall back to a minimal stream-context POST otherwise.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_TIMEOUT_MS => 4000,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                return ['ok' => false, 'error' => ($err !== '' ? $err : 'request failed')];
            }
            return self::adminResult($code, $resp);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 4,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'request failed'];
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $code = (int) $m[1];
            }
        }
        return self::adminResult($code, $resp);
    }

    /** Normalize a Twirp response (HTTP status + JSON body) into ok/data. */
    private static function adminResult(int $code, string $resp): array
    {
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['ok' => $code >= 200 && $code < 300, 'error' => 'unparseable response', 'data' => []];
        }
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'data' => $data, 'error' => ''];
        }
        return [
            'ok' => false,
            'error' => (string) ($data['msg'] ?? $data['error'] ?? ($code === 401 || $code === 403 ? 'admin token rejected' : 'HTTP ' . $code)),
            'code' => $code,
            'data' => $data,
        ];
    }

    /** List rooms + participant counts as seen by the LiveKit server itself. */
    public static function rooms(): array
    {
        $r = self::adminCall('RoomService/ListRooms');
        if (!$r['ok']) {
            return [];
        }
        $rooms = [];
        foreach ((array) ($r['data']['rooms'] ?? []) as $room) {
            $rooms[] = [
                'name' => (string) ($room['name'] ?? ''),
                'num_participants' => (int) ($room['num_participants'] ?? 0),
                'created_at' => (string) ($room['creation_time'] ?? ''),
                'empty_timeout' => (int) ($room['empty_timeout'] ?? 0),
            ];
        }
        return $rooms;
    }

    /**
     * Throttled room listing for the admin panel (the admin page is heavy on
     * these; room list is cached for a few seconds so the panel can poll live
     * state without hammering LiveKit from a fleet of admin tabs).
     */
    public static function roomsCached(int $ttlSeconds = 5): array
    {
        $file = ROOT . '/data/livekit/rooms-cache.json';
        if (is_file($file) && filemtime($file) > time() - $ttlSeconds) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $rooms = self::rooms();
        @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, (string) json_encode($rooms));
        return $rooms;
    }

    /** Participants of a room, as LiveKit sees them (identity + track state). */
    public static function participants(string $room): array
    {
        $r = self::adminCall('RoomService/ListParticipants', ['room' => $room]);
        if (!$r['ok']) {
            return [];
        }
        $out = [];
        foreach ((array) ($r['data']['participants'] ?? []) as $p) {
            $tracks = [];
            foreach ((array) ($p['tracks'] ?? []) as $t) {
                $tracks[] = [
                    'sid' => (string) ($t['sid'] ?? ''),
                    'type' => (int) ($t['type'] ?? 0), // 0=audio, 1=video, 3=screen_share_audio, 4=screen_share_video
                    'muted' => (bool) ($t['muted'] ?? false),
                ];
            }
            $out[] = [
                'identity' => (string) ($p['identity'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'is_publisher' => (bool) ($p['isPublisher'] ?? false),
                'tracks' => $tracks,
            ];
        }
        return $out;
    }

    /**
     * Kick a participant from a room server-side (repo's room admin API now
     * wired — replaces the former no-op placeholder). Returns true on success.
     */
    public static function removeParticipant(string $room, string $identity): bool
    {
        $r = self::adminCall('RoomService/RemoveParticipant', ['room' => $room, 'identity' => $identity]);
        return $r['ok'];
    }

    /** Mute/unmute a participant's published audio track (host-mute, Zoom-style). */
    public static function muteParticipant(string $room, string $identity, bool $muted): bool
    {
        // Find the audio track sid first (MutePublishedTrack targets a track).
        $r = self::adminCall('RoomService/GetParticipant', ['room' => $room, 'identity' => $identity]);
        if (!$r['ok']) {
            return false;
        }
        $p = (array) ($r['data']['participant'] ?? []);
        $audioSid = null;
        foreach ((array) ($p['tracks'] ?? []) as $t) {
            $type = (int) ($t['type'] ?? 0);
            if ($type === 0 || $type === 3) { // AUDIO or SCREEN_SHARE_AUDIO
                $audioSid = (string) ($t['sid'] ?? '');
                break;
            }
        }
        if ($audioSid === '') {
            return false;
        }
        $r2 = self::adminCall('RoomService/MutePublishedTrack', [
            'room' => $room,
            'identity' => $identity,
            'track_sid' => $audioSid,
            'muted' => $muted,
        ]);
        return $r2['ok'];
    }

    /** Mute/unmute every participant currently publishing audio in a room. */
    public static function muteAll(string $room, bool $muted): array
    {
        $names = [];
        foreach (self::participants($room) as $p) {
            $hasAudio = false;
            foreach ($p['tracks'] as $t) {
                if ($t['type'] === 0 || $t['type'] === 3) {
                    $hasAudio = true;
                    break;
                }
            }
            if (!$hasAudio) {
                continue;
            }
            $ok = self::muteParticipant($room, $p['identity'], $muted);
            if ($ok) {
                $names[] = $p['identity'];
            }
        }
        return $names;
    }

    /** Flip a participant's publish/subscribe permissions (lock/unlock server-side). */
    public static function updateParticipant(string $room, string $identity, bool $canPublish, bool $canSubscribe = true): bool
    {
        $r = self::adminCall('RoomService/UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'permission' => [
                'can_publish' => $canPublish,
                'can_subscribe' => $canSubscribe,
                'can_publish_data' => true,
                'can_update_metadata' => false,
            ],
        ]);
        return $r['ok'];
    }

    /** Delete an empty room (or force-close one) server-side. */
    public static function deleteRoom(string $room): bool
    {
        $r = self::adminCall('RoomService/DeleteRoom', ['room' => $room]);
        return $r['ok'];
    }

    // ── Active-speaker / subscription hints ──────────────────────────────────
    // Talker-cap enforcement is client-side (selective subscription), but the
    // server can still ask LiveKit which participants it considers speakers.
    /** LiveKit's current speaker list for a room (identities, loudest first). */
    public static function activeSpeakers(string $room): array
    {
        // RoomService has no direct "speakers" RPC; participants + track engine
        // state is the practical approximation. Used by admin diagnostics only.
        return array_column(self::participants($room), 'identity');
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

    /** Fail ringing calls nobody answered (~5 rings, see ringSeconds()). */
    public static function expireCalls(): void
    {
        $rows = Database::all(
            'SELECT * FROM call_sessions WHERE status = "ringing"
             AND created_at < datetime("now", "-" || ? || " seconds")',
            [self::ringSeconds()]
        );
        if ($rows) {
            foreach ($rows as $call) {
                Database::query("UPDATE call_sessions SET status = 'missed' WHERE id = ?", [(int) $call['id']]);
                self::logCallOutcome($call, 'missed');
            }
        }
    }

    /**
     * Write a call outcome to chat_logs (kind='call') so moderation sees who
     * called whom and what happened — the audit trail the IRC-style logger
     * already keeps for messages. Best-effort; never breaks the call flow.
     */
    public static function logCallOutcome(array $call, string $outcome, ?int $durationSec = null): void
    {
        try {
            $caller = self::callerName($call['caller_user_id'] ?? null, $call['caller_guest_id'] ?? null);
            $callee = self::callerName($call['callee_user_id'] ?? null, $call['callee_guest_id'] ?? null);
            $content = 'call ' . ($caller !== '' ? $caller : 'guest') . ' -> '
                . ($callee !== '' ? $callee : 'guest') . ' [' . $outcome . ']';
            if ($durationSec !== null && $durationSec > 0) {
                $content .= ' ' . gmdate('H:i:s', $durationSec);
            }
            Database::query(
                'INSERT INTO chat_logs (channel_name, user_id, username, kind, content, guest)
                 VALUES (?, ?, ?, "call", ?, 0)',
                ['calls', (int) ($call['caller_user_id'] ?? 0), $caller !== '' ? $caller : 'guest', $content]
            );
        } catch (\Throwable $e) {
            // Logging must never break the call flow.
        }
    }

    /** Display name for one side of a call. */
    private static function callerName($userId, $guestId): string
    {
        if ($userId) {
            return (string) (Database::scalar('SELECT username FROM users WHERE id = ?', [(int) $userId]) ?? '');
        }
        if ($guestId) {
            return (string) (Database::scalar('SELECT nick FROM guests WHERE id = ?', [(int) $guestId]) ?? '');
        }
        return '';
    }

    // ── Server-side roster (app view, source of truth for the join gate) ──

    /**
     * Participants of a room as the app sees them (voice_sessions), joined with
     * actor names. Includes waiting-room occupants so hosts can admit/deny.
     * Mirrors what LiveKit sees, minus the sub-second window between a client
     * disconnect and its session row aging out.
     */
    public static function roster(string $room): array
    {
        $rows = Database::all('SELECT * FROM voice_sessions WHERE room = ?', [$room]);
        $out = [];
        foreach ($rows as $row) {
            $identity = ($row['user_id'] ? 'u' . (int) $row['user_id'] : 'g' . (int) $row['guest_id']);
            if ($row['user_id']) {
                $name = (string) (Database::scalar('SELECT username FROM users WHERE id = ?', [$row['user_id']]) ?? 'user');
                $guest = false;
            } else {
                $name = (string) (Database::scalar('SELECT nick FROM guests WHERE id = ?', [$row['guest_id']]) ?? 'guest');
                $guest = true;
            }
            $out[] = [
                'identity' => $identity,
                'name' => $name,
                'guest' => $guest,
                'waiting' => (int) ($row['waiting'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    /**
     * Simple in-DB rate limiter (per-actor/per-action buckets) reused by the
     * voice/call/event endpoints — the login_attempts-style spam guard.
     * Returns true when the action is allowed, false when the caller is out
     * of budget for the window.
     */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds = 60): bool
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        $row = Database::row('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (!$row || (int) $row['window_start'] < $windowStart) {
            Database::query(
                'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
                 ON CONFLICT(bucket) DO UPDATE SET hits = 1, window_start = excluded.window_start',
                [$bucket, $now]
            );
            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        Database::query('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        return true;
    }

    /** Whether a voice room is locked (join gate blocks non-moderators). */
    public static function roomLocked(string $room): bool
    {
        return (bool) Database::scalar('SELECT locked FROM voice_room_flags WHERE room = ?', [$room]);
    }

    public static function setRoomLocked(string $room, bool $locked): void
    {
        Database::query(
            'INSERT INTO voice_room_flags (room, locked, updated_at) VALUES (?, ?, datetime("now"))
             ON CONFLICT(room) DO UPDATE SET locked = excluded.locked, updated_at = excluded.updated_at',
            [$room, $locked ? 1 : 0]
        );
    }

    // ── Egress (recording) ─────────────────────────────────────────────────

    /**
     * Kick off a room-composite recording via LiveKit's egress service
     * (livekit-egress + Redis must be deployed; StartEgress fails otherwise).
     */
    public static function startEgress(string $room, array $outputs): array
    {
        $payload = ['roomName' => $room];
        foreach ($outputs as $o) {
            if (($o['kind'] ?? '') === 'file') {
                $payload['fileOutputs'] = [['fileType' => 'MP4', 'filepath' => $o['path']]];
            }
        }
        return self::adminCall('Egress/StartRoomCompositeEgress', $payload);
    }

    public static function stopEgress(string $egressId): array
    {
        return self::adminCall('Egress/StopEgress', ['egressId' => $egressId]);
    }

    public static function listEgress(): array
    {
        $r = self::adminCall('Egress/ListEgress');
        if (!$r['ok']) {
            return [];
        }
        $out = [];
        foreach ((array) ($r['data']['items'] ?? []) as $item) {
            $out[] = [
                'egress_id' => (string) ($item['egressId'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'room_name' => (string) ($item['roomName'] ?? ''),
                'file_results' => (array) ($item['fileResults'] ?? []),
                'error' => (string) ($item['error'] ?? ''),
            ];
        }
        return $out;
    }

    /** Root dir for recording files (under data/, like LiveKit's own dirs). */
    public static function recordingsDir(): string
    {
        return (string) (config_get('recording_path', '') ?? '') !== ''
            ? (string) config_get('recording_path', '')
            : ROOT . '/data/recordings';
    }

    /**
     * Prune voice_sessions whose client stopped heartbeating (~2 min).
     */
    public static function pruneStale(): void
    {
        Database::query('DELETE FROM voice_sessions WHERE last_seen < datetime("now", "-2 minutes") AND waiting = 0');
        // Waiting occupants never connect, but their client still heartbeats
        // via the status poll — give them the same 2-minute window, and while
        // we're here prune anything left behind by crashed clients.
        Database::query('DELETE FROM voice_sessions WHERE last_seen < datetime("now", "-2 minutes")');
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

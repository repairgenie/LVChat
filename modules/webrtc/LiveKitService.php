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

    /** Prune voice_sessions whose client stopped heartbeating (~2 min). */
    public static function pruneStale(): void
    {
        Database::query('DELETE FROM voice_sessions WHERE last_seen < datetime("now", "-2 minutes")');
    }

    /** Prune ringing calls that were never answered (~30 s). */
    public static function expireCalls(): void
    {
        Database::query(
            "UPDATE call_sessions SET status = 'missed'
             WHERE status = 'ringing' AND created_at < datetime('now', '-30 seconds')"
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

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
 * AdminVoiceController — Admin → Voice (LiveKit) settings page for the WebRTC module.
 *
 * Routes (registered by routes.php):
 *   GET  /admin/voice       — settings form + status panel (module view)
 *   POST /admin/voice/save  — persist + validate (write-only secret, like SMTP)
 *
 * Autoconfigure (POST /admin/voice/save with autoconfigure=1): generates a
 * strong API key + secret, writes them into the user-space config
 * (data/livekit/livekit.yaml), starts livekit-server as the site user via
 * nohup, and enables voice. Everything stays inside the web user's account —
 * no /etc, no systemd, no sudo (see modules/webrtc/README.md).
 */
final class AdminVoiceController
{
    private static function requireAdmin(): array
    {
        return Auth::requireAdmin();
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch.');
        }
    }

    private static function keys(): array
    {
        return [
            'voice_enabled', 'livekit_url', 'livekit_api_key', 'livekit_api_secret',
            'voice_max_users', 'voice_talker_cap', 'voice_bitrate', 'voice_quality_preset',
            'call_ring_seconds', 'recording_enabled', 'recording_path',
        ];
    }

    /** GET /admin/voice */
    public static function admin(): void
    {
        $admin = self::requireAdmin();
        $settings = [];
        foreach (self::keys() as $k) {
            $settings[$k] = (string) (config_get($k, '') ?? '');
        }
        // Write-only secret: never echo it back — just say one is stored.
        $settings['livekit_has_secret'] = trim((string) (config_get('livekit_api_secret', '') ?? '')) !== '';
        ModuleLoader::view('webrtc', 'admin/voice', [
            'admin' => $admin,
            'settings' => $settings,
            'status' => LiveKitService::status(),
            'health' => LiveKitService::health(),
            'daemon' => LiveKitService::daemonInfo(),
            'rooms' => LiveKitService::roomsCached(),
            'recordings' => self::recordings(),
            'module' => ModuleLoader::get('webrtc'),
        ], 'layout');
    }

    /** POST /admin/voice/save — plain save, or autoconfigure when flagged. */
    public static function save(): void
    {
        self::requireAdmin();
        self::requireCsrf();

        if (!empty($_POST['autoconfigure'])) {
            self::autoconfigure($_POST);
            return;
        }

        self::applyFields($_POST);

        config_set('livekit_api_key', trim((string) ($_POST['livekit_api_key'] ?? '')));
        $secret = (string) ($_POST['livekit_api_secret'] ?? '');
        if ($secret !== '') {
            config_set('livekit_api_secret', $secret);
        }

        log_audit('voice_settings', null, 'saved by ' . (Auth::user()['username'] ?? 'admin'));
        flash('Voice settings saved.');
        redirect('/admin/voice');
    }

    /** Persist the non-secret voice fields (shared by save + autoconfigure). */
    private static function applyFields(array $post): void
    {
        config_set('voice_enabled', ($post['voice_enabled'] ?? '0') === '1' ? '1' : '0');

        $url = trim((string) ($post['livekit_url'] ?? ''));
        if ($url !== '' && preg_match('#^(ws|wss|http|https)://[^\s]+$#', $url)) {
            config_set('livekit_url', $url);
        }

        config_set('voice_max_users', (string) max(1, min(200, (int) ($post['voice_max_users'] ?? 50))));
        config_set('voice_talker_cap', (string) max(1, min(50, (int) ($post['voice_talker_cap'] ?? 8))));
        config_set('call_ring_seconds', (string) max(10, min(120, (int) ($post['call_ring_seconds'] ?? 20))));

        $preset = (string) ($post['voice_quality_preset'] ?? 'moderate');
        if (!in_array($preset, ['high', 'moderate', 'minimum'], true)) {
            $preset = 'moderate';
        }
        config_set('voice_quality_preset', $preset);
        $bitrate = ['high' => '64000', 'moderate' => '40000', 'minimum' => '16000'][$preset];
        $custom = (int) ($post['voice_bitrate'] ?? 0);
        if ($custom > 0) {
            $bitrate = (string) max(16000, min(64000, $custom));
        }
        config_set('voice_bitrate', $bitrate);

        // Recording (egress).
        config_set('recording_enabled', ($post['recording_enabled'] ?? '0') === '1' ? '1' : '0');
        $recPath = trim((string) ($post['recording_path'] ?? ''));
        if ($recPath !== '' && is_dir($recPath) && is_writable($recPath)) {
            config_set('recording_path', $recPath);
        }
    }

    /** Recordings list for the admin panel (from the app table + disk scan). */
    private static function recordings(): array
    {
        $rows = Database::all(
            'SELECT * FROM recordings ORDER BY id DESC LIMIT 50'
        );
        $out = [];
        foreach ($rows as $r) {
            $size = (int) $r['size_bytes'];
            if ($size <= 0 && $r['filename']) {
                $p = LiveKitService::recordingsDir() . '/' . $r['filename'];
                $size = is_file($p) ? (int) filesize($p) : 0;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'room' => (string) $r['room'],
                'kind' => (string) $r['kind'],
                'status' => (string) $r['status'],
                'filename' => (string) ($r['filename'] ?? ''),
                'size' => $size,
                'started_at' => (string) ($r['started_at'] ?? ''),
                'stopped_at' => (string) ($r['stopped_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** Autoconfigure: generate keys, push them into LiveKit, restart, enable voice. */
    private static function autoconfigure(array $post): void
    {
        $key = self::generateApiKey();
        $secret = self::generateSecret();

        self::applyFields($post);
        if (trim((string) (config_get('livekit_url', '') ?? '')) === '') {
            config_set('livekit_url', 'ws://127.0.0.1:7880'); // module default
        }
        config_set('livekit_api_key', $key);
        config_set('livekit_api_secret', $secret);
        config_set('voice_enabled', '1');

        $written = LiveKitService::writeKeysConfig($key, $secret);
        $run = LiveKitService::ensureRunning($written['config']);

        $msg = 'LiveKit autoconfigured. API key: ' . $key;
        if ($written['written']) {
            $msg .= ' — keys written to ' . $written['config'];
        } else {
            $msg .= ' — could not write ' . $written['config'] . ', add manually and restart: ' . $key . ': ' . $secret;
        }
        $msg .= $run['running']
            ? ' — ' . $run['message'] . '.'
            : ' — ' . $run['message'] . ' (voice stays disabled until LiveKit accepts these keys).';

        log_audit('voice_settings', null, 'autoconfigured livekit keys by ' . (Auth::user()['username'] ?? 'admin'));
        flash($msg);
        redirect('/admin/voice');
    }

    private static function generateApiKey(): string
    {
        return 'LVCHAT' . strtoupper(bin2hex(random_bytes(4)));
    }

    private static function generateSecret(): string
    {
        return bin2hex(random_bytes(24)); // matches the README's `openssl rand -hex 24`
    }
}

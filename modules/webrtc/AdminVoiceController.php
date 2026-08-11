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
            'module' => ModuleLoader::get('webrtc'),
        ], 'layout');
    }

    /** POST /admin/voice/save */
    public static function save(): void
    {
        self::requireAdmin();
        self::requireCsrf();

        config_set('voice_enabled', ($_POST['voice_enabled'] ?? '0') === '1' ? '1' : '0');

        $url = trim((string) ($_POST['livekit_url'] ?? ''));
        if ($url !== '' && preg_match('#^(ws|wss|http|https)://[^\s]+$#', $url)) {
            config_set('livekit_url', $url);
        }

        config_set('livekit_api_key', trim((string) ($_POST['livekit_api_key'] ?? '')));
        $secret = (string) ($_POST['livekit_api_secret'] ?? '');
        if ($secret !== '') {
            config_set('livekit_api_secret', $secret);
        }

        config_set('voice_max_users', (string) max(1, min(200, (int) ($_POST['voice_max_users'] ?? 50))));
        config_set('voice_talker_cap', (string) max(1, min(50, (int) ($_POST['voice_talker_cap'] ?? 8))));

        $preset = (string) ($_POST['voice_quality_preset'] ?? 'moderate');
        if (!in_array($preset, ['high', 'moderate', 'minimum'], true)) {
            $preset = 'moderate';
        }
        config_set('voice_quality_preset', $preset);
        $bitrate = ['high' => '64000', 'moderate' => '40000', 'minimum' => '16000'][$preset];
        $custom = (int) ($_POST['voice_bitrate'] ?? 0);
        if ($custom > 0) {
            $bitrate = (string) max(16000, min(64000, $custom));
        }
        config_set('voice_bitrate', $bitrate);

        log_audit('voice_settings', null, 'saved by ' . (Auth::user()['username'] ?? 'admin'));
        flash('Voice settings saved.');
        redirect('/admin/voice');
    }
}

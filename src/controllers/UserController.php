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

final class UserController
{
    /** GET /api/me — current session's account (messenger/API clients). */
    public static function me(): void
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        json_out([
            'ok' => true,
            'user' => array_merge([
                'id' => (int) $u['id'],
                'username' => $u['username'],
                'avatar' => $u['avatar'] ?? null,
                'role' => $u['role'],
                'guest' => (int) ($u['guest'] ?? 0),
                'away' => $u['away'] ?? null,
                'status' => $u['status'] ?? 'active',
            ], Auth::statusInfo($u)),
            // Web Push clients (the messenger-web PWA) subscribe with the
            // server's VAPID public key; it's embedded in HTML pages but only
            // exposed through the API here.
            'vapidPublicKey' => PushService::publicKey(),
        ]);
    }

    /** POST /api/status — set the caller's presence status (online/away/dnd/invisible/custom). */
    public static function status(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        $mode = (string) ($_POST['status_mode'] ?? '');
        if (!in_array($mode, Auth::STATUS_MODES, true)) {
            json_out(['error' => 'Invalid status mode.'], 400);
        }
        $custom = mb_substr(trim((string) ($_POST['custom_status'] ?? '')), 0, 80);
        $away = $mode === 'away' ? ($custom !== '' ? $custom : null) : null;
        Database::query(
            'UPDATE users SET status_mode = ?, custom_status = ?, away = ?, away_at = ? WHERE id = ?',
            [$mode, $custom, $away, $away !== null ? now() : null, $user['id']]
        );
        log_audit('status_set', $user['username'], $mode . ($custom !== '' ? ' — ' . $custom : ''));
        $row = Database::row('SELECT * FROM users WHERE id = ?', [$user['id']]);
        json_out(['ok' => true, 'status' => Auth::statusInfo($row), 'away' => $row['away']]);
    }

    /** GET /api/csrf — the session's CSRF token for app clients that post JSON/form bodies.
     *  Requires an authenticated session so an unauthenticated cross-origin page
     *  cannot read a victim's token (defense-in-depth behind the credentialed
     *  CORS restriction in bootstrap.php). */
    public static function csrf(): void
    {
        Auth::require();
        json_out(['ok' => true, 'csrf' => Csrf::token()]);
    }

    public static function profile(array $params): void
    {
        $viewer = Auth::require();
        $user = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$params['username']]);
        if (!$user) {
            render_view('errors/notfound', [], null);
        }
        $isSelf = (int) $viewer['id'] === (int) $user['id']
        && (int) ($viewer['guest'] ?? 0) === (int) ($user['guest'] ?? 0);
        // Channel list: only show channels the VIEWER is entitled to know.
        // Private/secret channels — and private event channels, whose slug IS
        // the access credential — must never be disclosed to third parties.
        // Self-viewers see their own channels; anyone else sees only public
        // channels the target belongs to, plus any channel they share with the
        // viewer.
        $channels = [];
        if ($isSelf) {
            $channels = ChannelService::joinedChannelNames($user);
        } else {
            $viewerIsGuest = (int) ($viewer['guest'] ?? 0) === 1;
            $targetIsRegistered = (int) ($user['guest'] ?? 0) !== 1;
            $seen = [];
            if ($targetIsRegistered) {
                // Channels the target shares with the viewer.
                if (!$viewerIsGuest) {
                    $shared = Database::all(
                        'SELECT c.name, c.slug FROM channel_members v
                         JOIN channel_members t ON t.channel_id = v.channel_id
                         JOIN channels c ON c.id = v.channel_id
                         WHERE v.user_id = ? AND t.user_id = ? AND c.event_id IS NULL',
                        [(int) $viewer['id'], (int) $user['id']]
                    );
                    foreach ($shared as $c) {
                        $channels[] = $c;
                        $seen[(string) $c['name']] = true;
                    }
                }
                // Public channels the target belongs to.
                $publicCh = Database::all(
                    "SELECT c.name, c.slug FROM channels c
                     JOIN channel_members t ON t.channel_id = c.id
                     WHERE t.user_id = ? AND c.visibility = 'public' AND c.forbidden = 0 AND c.event_id IS NULL",
                    [(int) $user['id']]
                );
                foreach ($publicCh as $c) {
                    if (!isset($seen[(string) $c['name']])) {
                        $channels[] = $c;
                    }
                }
            } else {
                // Target is a guest: only public channels they belong to.
                $guestPublic = Database::all(
                    "SELECT c.name, c.slug FROM channels c
                     JOIN channel_members t ON t.channel_id = c.id
                     WHERE t.guest_id = ? AND c.visibility = 'public' AND c.forbidden = 0 AND c.event_id IS NULL",
                    [(int) $user['id']]
                );
                $channels = $guestPublic;
            }
        }
        $isOnline = Auth::isOnline($user);
        $sounds = SoundService::listEnabled();
        $soundPrefs = SoundService::prefsFor($user);
        $soundOverrides = SoundService::overrides($user);
        $allUsers = $isSelf && !(int) ($user['guest'] ?? 0)
            ? Database::all('SELECT id, username FROM users WHERE id != ? ORDER BY username COLLATE NOCASE LIMIT 1000', [$user['id']])
            : [];
        $friendStatus = (!$isSelf && !(int) ($viewer['guest'] ?? 0) && !(int) ($user['guest'] ?? 0))
            ? FriendService::status((int) $viewer['id'], (int) $user['id'])
            : 'none';
        render_view('user/profile', [
            'viewer' => $viewer,
            'user' => $user,
            'isSelf' => $isSelf,
            'channels' => $channels,
            'isOnline' => $isOnline,
            'sounds' => $sounds,
            'soundPrefs' => $soundPrefs,
            'soundOverrides' => $soundOverrides,
            'allUsers' => $allUsers,
            'friendStatus' => $friendStatus,
            'themePresets' => ThemeService::presets(),
            'themeCustomizationEnabled' => ThemeService::customizationEnabled(),
            'userThemeJson' => ThemeService::userTheme($user),
            'effectiveTheme' => ThemeService::effectiveForView($user),
            'pushPrefs' => $isSelf && !(int) ($user['guest'] ?? 0) ? PushService::prefs($user) : ['channels' => 1, 'dms' => 1, 'invites' => 1],
            'pushMutedUsers' => $isSelf && !(int) ($user['guest'] ?? 0) ? PushService::mutedList($user) : [],
            'vapidPublicKey' => PushService::publicKey(),
        ]);
    }

    public static function online(): void
    {
        $user = Auth::require();
        $rows = Database::all(
            'SELECT id, username, away, status_mode, custom_status FROM users
             WHERE last_seen >= datetime("now", "-30 seconds") AND status_mode != \'invisible\' AND id != ?
             ORDER BY username COLLATE NOCASE',
            [$user['id']]
        );
        json_out(['ok' => true, 'online' => $rows]);
    }

    public static function changePassword(): void
    {
        $user = Auth::require();
        Csrf::verify();
        // Throttle password verification like logins (10 per 10 min per IP).
        if (login_attempt_count() >= login_attempt_max()) {
            json_out(['error' => 'Too many attempts. Please wait a few minutes.'], 429);
        }
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            login_attempt_record();
            json_out(['error' => 'Current password is incorrect.'], 403);
        }
        login_attempt_clear();
        if (strlen($new) < 8) {
            json_out(['error' => 'New password must be at least 8 characters.'], 400);
        }
        Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $user['id']]);
        // Kill all other sessions so a stolen token cannot persist after
        // the password is changed (mirrors the admin-initiated reset flow).
        Auth::killSessions((int) $user['id']);
        log_audit('password_change', $user['username']);
        json_out(['ok' => true]);
    }

    public static function updateProfile(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        $fields = [];
        if (isset($_POST['vhost'])) {
            $vhost = trim((string) $_POST['vhost']);
            if ($vhost !== '' && !preg_match('/^[A-Za-z0-9.\-]{3,60}$/', $vhost)) {
                json_out(['error' => 'Invalid vhost.'], 400);
            }
            $fields['vhost'] = $vhost !== '' ? $vhost : null;
        }
        if (isset($_POST['away'])) {
            $fields['away'] = $_POST['away'] !== '' ? mb_substr((string) $_POST['away'], 0, 200) : null;
            $fields['away_at'] = $_POST['away'] !== '' ? now() : null;
        }
        if (isset($_POST['searchable'])) {
            $fields['searchable'] = (int) ($_POST['searchable'] ? 1 : 0);
        }
        if (isset($_POST['theme'])) {
            $mode = $_POST['theme'] === 'light' ? 'light' : 'dark';
            $fields['theme'] = $mode;
            // Mirror the quick-toggle mode into the personal theme JSON so the
            // header toggle and the profile theme editor stay in sync.
            $tj = Database::scalar('SELECT theme_json FROM users WHERE id = ?', [$user['id']]);
            if ($tj) {
                $t = json_decode((string) $tj, true);
                if (is_array($t)) {
                    $t['mode'] = $mode;
                    Database::query(
                        'UPDATE users SET theme_json = ? WHERE id = ?',
                        [json_encode(ThemeService::normalize($t)), (int) $user['id']]
                    );
                }
            }
        }
        if (!$fields) {
            json_out(['ok' => true]);
        }
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $set[] = "$k = ?";
            $vals[] = $v;
        }
        $vals[] = $user['id'];
        Database::query('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
        json_out(['ok' => true]);
    }

    public static function uploadAvatar(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        if (!isset($_FILES['avatar']) || !UploadService::isImageUpload($_FILES['avatar'])) {
            json_out(['error' => 'Choose an image file first.'], 400);
        }
        $v = UploadService::validate($_FILES['avatar'], 'avatar');
        if (!$v['ok']) {
            json_out(['error' => $v['error']], 400);
        }
        $stored = UploadService::store($_FILES['avatar'], 'avatar');
        if (!$stored['ok']) {
            json_out(['error' => $stored['error']], 500);
        }
        // Downscale to a small square-ish avatar (WebP when possible).
        $abs = UploadService::dir('avatar') . '/' . basename($stored['url']);
        $scaled = UploadService::downscale($abs, (string) $stored['ext'], 256);
        $url = $scaled === false
            ? $stored['url']
            : str_replace('\\', '/', substr($scaled, strlen(ROOT . '/public')));
        // Remove any previous avatar file (keep the row clean).
        $old = Database::scalar('SELECT avatar FROM users WHERE id = ?', [$user['id']]);
        Database::query('UPDATE users SET avatar = ? WHERE id = ?', [$url, $user['id']]);
        if ($old) {
            UploadService::remove((string) $old);
        }
        log_audit('avatar_upload', $user['username']);
        json_out(['ok' => true, 'avatar' => $url]);
    }

    public static function removeAvatar(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        $old = Database::scalar('SELECT avatar FROM users WHERE id = ?', [$user['id']]);
        Database::query('UPDATE users SET avatar = NULL WHERE id = ?', [$user['id']]);
        if ($old) {
            UploadService::remove((string) $old);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/mfa/begin — start MFA enrollment; the secret lives in the session until verified. */
    public static function mfaBegin(): void
    {
        $user = Auth::require();
        Csrf::verify();
        if ((int) ($user['guest'] ?? 0) === 1) {
            json_out(['error' => 'Registered users only.'], 403);
        }
        if (TotpService::enabled($user)) {
            json_out(['error' => 'Two-factor authentication is already enabled.'], 400);
        }
        $secret = TotpService::generateSecret();
        $_SESSION['mfa_selfsetup_secret'] = $secret;
        json_out([
            'ok' => true,
            'secret' => TotpService::formatSecret($secret),
            'uri' => TotpService::otpauthUri($secret, (string) $user['username'], (string) config_get('site_name', 'LVChat')),
        ]);
    }

    /** POST /api/mfa/enable — confirm enrollment with a valid code from the authenticator. */
    public static function mfaEnable(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        $secret = (string) ($_SESSION['mfa_selfsetup_secret'] ?? '');
        if ($secret === '') {
            json_out(['error' => 'Start the MFA setup first.'], 400);
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!TotpService::verify($secret, $code, 1, (int) $user['id'])) {
            json_out(['error' => 'Invalid code. Check your authenticator app and try again.'], 403);
        }
        TotpService::enable((int) $user['id'], $secret);
        unset($_SESSION['mfa_selfsetup_secret']);
        log_audit('mfa_enable', $user['username']);
        json_out(['ok' => true]);
    }

    /** POST /api/mfa/disable — requires the account password; blocked when the class requires MFA. */
    public static function mfaDisable(): void
    {
        $user = Auth::requireAccount();
        Csrf::verify();
        // Throttle password verification like logins (10 per 10 min per IP).
        if (login_attempt_count() >= login_attempt_max()) {
            json_out(['error' => 'Too many attempts. Please wait a few minutes.'], 429);
        }
        if (!TotpService::enabled($user)) {
            json_out(['error' => 'Two-factor authentication is not enabled.'], 400);
        }
        if (TotpService::requiredFor($user)) {
            json_out(['error' => 'Two-factor authentication is required for your account class and cannot be disabled.'], 403);
        }
        $password = (string) ($_POST['password'] ?? '');
        if (!password_verify($password, $user['password_hash'])) {
            login_attempt_record();
            json_out(['error' => 'Password is incorrect.'], 403);
        }
        login_attempt_clear();
        TotpService::disable((int) $user['id']);
        log_audit('mfa_disable', $user['username']);
        json_out(['ok' => true]);
    }
}

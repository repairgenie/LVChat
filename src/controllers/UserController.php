<?php

declare(strict_types=1);

final class UserController
{
    public static function profile(array $params): void
    {
        $viewer = Auth::require();
        $user = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$params['username']]);
        if (!$user) {
            render_view('errors/notfound', [], null);
        }
        $isSelf = (int) $viewer['id'] === (int) $user['id'];
        $channels = ChannelService::joinedChannelNames($user);
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
        ]);
    }

    public static function online(): void
    {
        $user = Auth::require();
        $rows = Database::all(
            'SELECT id, username, away FROM users
             WHERE last_seen >= datetime("now", "-30 seconds") AND away IS NULL AND id != ?
             ORDER BY username COLLATE NOCASE',
            [$user['id']]
        );
        json_out(['ok' => true, 'online' => $rows]);
    }

    public static function changePassword(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            json_out(['error' => 'Current password is incorrect.'], 403);
        }
        if (strlen($new) < 8) {
            json_out(['error' => 'New password must be at least 8 characters.'], 400);
        }
        Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $user['id']]);
        log_audit('password_change', $user['username']);
        json_out(['ok' => true]);
    }

    public static function updateProfile(): void
    {
        $user = Auth::require();
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
        $user = Auth::require();
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
        $user = Auth::require();
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
        $user = Auth::require();
        Csrf::verify();
        $secret = (string) ($_SESSION['mfa_selfsetup_secret'] ?? '');
        if ($secret === '') {
            json_out(['error' => 'Start the MFA setup first.'], 400);
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!TotpService::verify($secret, $code)) {
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
        $user = Auth::require();
        Csrf::verify();
        if (!TotpService::enabled($user)) {
            json_out(['error' => 'Two-factor authentication is not enabled.'], 400);
        }
        if (TotpService::requiredFor($user)) {
            json_out(['error' => 'Two-factor authentication is required for your account class and cannot be disabled.'], 403);
        }
        $password = (string) ($_POST['password'] ?? '');
        if (!password_verify($password, $user['password_hash'])) {
            json_out(['error' => 'Password is incorrect.'], 403);
        }
        TotpService::disable((int) $user['id']);
        log_audit('mfa_disable', $user['username']);
        json_out(['ok' => true]);
    }
}

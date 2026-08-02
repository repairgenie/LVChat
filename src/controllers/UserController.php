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
        $channels = ChannelService::joinedChannelNames((int) $user['id']);
        $isOnline = Auth::isOnline($user);
        render_view('user/profile', [
            'viewer' => $viewer,
            'user' => $user,
            'isSelf' => $isSelf,
            'channels' => $channels,
            'isOnline' => $isOnline,
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
            $fields['theme'] = $_POST['theme'] === 'light' ? 'light' : 'dark';
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
}

<?php

declare(strict_types=1);

final class FriendController
{
    private static function requireUser(): array
    {
        $u = Auth::user();
        if (!$u || (int) ($u['guest'] ?? 0) === 1) {
            json_out(['error' => 'Registered users only.'], 401);
        }
        return $u;
    }

    private static function requireCsrf(): void
    {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    private static function resolveTarget(): ?array
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username === '') {
            json_out(['error' => 'Missing username.'], 400);
        }
        $t = Database::row('SELECT id, username, guest FROM users WHERE username = ? COLLATE NOCASE', [$username]);
        if (!$t || (int) ($t['guest'] ?? 0) === 1) {
            json_out(['error' => 'User not found.'], 404);
        }
        return $t;
    }

    public static function list(): void
    {
        $user = self::requireUser();
        json_out(['ok' => true] + FriendService::allForUser((int) $user['id']));
    }

    public static function send(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::sendRequest((int) $user['id'], (int) $t['id']);
        if (isset($r['error'])) {
            json_out($r, 400);
        }
        json_out($r);
    }

    public static function accept(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::acceptRequest((int) $user['id'], (int) $t['id']);
        if (isset($r['error'])) {
            json_out($r, 400);
        }
        json_out($r);
    }

    public static function decline(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::declineRequest((int) $user['id'], (int) $t['id']);
        json_out($r);
    }

    public static function remove(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::removeFriend((int) $user['id'], (int) $t['id']);
        json_out($r);
    }

    public static function cancel(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::cancelRequest((int) $user['id'], (int) $t['id']);
        json_out($r);
    }

    public static function block(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::blockUser((int) $user['id'], (int) $t['id']);
        if (isset($r['error'])) {
            json_out($r, 400);
        }
        json_out($r);
    }

    public static function unblock(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $t = self::resolveTarget();
        $r = FriendService::unblockUser((int) $user['id'], (int) $t['id']);
        json_out($r);
    }

    public static function status(): void
    {
        $user = self::requireUser();
        $username = trim((string) ($_GET['username'] ?? ''));
        if ($username === '') {
            json_out(['error' => 'Missing username.'], 400);
        }
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$username]);
        if (!$t) {
            json_out(['status' => 'none']);
        }
        json_out(['status' => FriendService::status((int) $user['id'], (int) $t['id'])]);
    }

    /**
     * GET /api/directory — search the user directory to find people to send
     * friend requests to. Registered, active, non-bot users only (guests are
     * excluded); each result carries the requester's relationship status.
     */
    public static function search(): void
    {
        $user = self::requireUser();
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 25)));
        if ($q === '') {
            json_out(['ok' => true, 'results' => []]);
        }
        $rows = Database::all(
            "SELECT id, username, avatar, role, away, last_seen
             FROM users
             WHERE guest = 0 AND status = 'active' AND bot = 0 AND id != ?
               AND username LIKE ? COLLATE NOCASE
               AND NOT EXISTS (SELECT 1 FROM friendships fb WHERE fb.status = 'blocked'
                               AND ((fb.user_id = ? AND fb.friend_id = users.id)
                                 OR (fb.user_id = users.id AND fb.friend_id = ?)))
             ORDER BY (last_seen >= datetime('now', '-30 seconds')) DESC, username COLLATE NOCASE
             LIMIT ?",
            [(int) $user['id'], '%' . $q . '%', (int) $user['id'], (int) $user['id'], $limit]
        );
        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'id' => (int) $r['id'],
                'username' => $r['username'],
                'avatar' => $r['avatar'] ?? null,
                'role' => $r['role'],
                'is_online' => Auth::isOnline($r) ? 1 : 0,
                'status' => FriendService::status((int) $user['id'], (int) $r['id']),
            ];
        }
        json_out(['ok' => true, 'results' => $results]);
    }
}

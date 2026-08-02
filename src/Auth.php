<?php

declare(strict_types=1);

final class Auth
{
    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function user(): ?array
    {
        $token = $_SESSION['token'] ?? null;
        if (!$token) {
            return null;
        }
        $u = Database::row(
            'SELECT u.*, s.expires_at FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token = ? AND s.expires_at > datetime("now")',
            [$token]
        );
        if ($u) {
            Database::query('UPDATE users SET last_seen = datetime("now"), last_ip = ? WHERE id = ?', [client_ip(), $u['id']]);
        }
        return $u;
    }

    public static function login(array $user): void
    {
        $token = bin2hex(random_bytes(32));
        Database::query(
            'INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, datetime("now", "+30 days"))',
            [$user['id'], $token]
        );
        Database::query('UPDATE users SET last_ip = ? WHERE id = ?', [client_ip(), $user['id']]);
        @session_regenerate_id(true);
        $_SESSION['token'] = $token;
    }

    public static function logout(): void
    {
        $token = $_SESSION['token'] ?? null;
        if ($token) {
            Database::query('DELETE FROM sessions WHERE token = ?', [$token]);
        }
        unset($_SESSION['token']);
        session_regenerate_id(true);
    }

    /** Kill sessions belonging to a user. Keeps the current session unless $includeCurrent. */
    public static function killSessions(int $userId, bool $includeCurrent = false): int
    {
        $token = $_SESSION['token'] ?? null;
        if ($includeCurrent || !$token) {
            $stmt = Database::query('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        } else {
            $stmt = Database::query('DELETE FROM sessions WHERE user_id = ? AND token != ?', [$userId, $token]);
        }
        return $stmt->rowCount();
    }

    public static function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = trim($email);
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $username)) {
            $errors[] = 'Username must be 2-32 chars using letters, numbers, and IRC-safe symbols.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $exists = Database::scalar('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email]);
        if ($exists) {
            return ['ok' => false, 'errors' => ['That username or email is already registered.']];
        }
        Database::query(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            [$username, $email, password_hash($password, PASSWORD_ARGON2ID)]
        );
        $id = (int) Database::lastId();
        $hasAdmin = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE role = "admin"') > 0;
        if (!$hasAdmin) {
            Database::query('UPDATE users SET role = "admin" WHERE id = ?', [$id]);
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Create and log in an anonymous guest. No email/password is collected; the
     * guest row is ephemeral and purged after a day of inactivity.
     * Returns the user row, or null if the nick is invalid/taken/banned.
     */
    public static function loginGuest(string $nick): ?array
    {
        $nick = trim($nick);
        if (!preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $nick)) {
            return null;
        }
        self::purgeGuests();
        // Nick must be free — covers registered users AND active guests.
        if (Database::scalar('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick])) {
            return null;
        }
        $probe = ['username' => $nick, 'email' => '', 'last_ip' => client_ip(), 'role' => 'user'];
        if (self::globalBanFor($probe)) {
            return null;
        }
        Database::query(
            'INSERT INTO users (username, email, password_hash, guest) VALUES (?, ?, ?, 1)',
            [$nick, 'guest-' . bin2hex(random_bytes(8)) . '@guest.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID)]
        );
        $id = (int) Database::lastId();
        $user = Database::row('SELECT * FROM users WHERE id = ?', [$id]);
        self::login($user);
        log_audit('guest_join', $nick);
        return $user;
    }

    /** Remove guests that have been inactive for over a day. */
    public static function purgeGuests(): void
    {
        Database::query('DELETE FROM users WHERE guest = 1 AND last_seen < datetime("now", "-1 day")');
    }

    public static function isGuest(array $user): bool
    {
        return (int) ($user['guest'] ?? 0) === 1;
    }

    public static function attempt(string $username, string $password): ?array
    {
        $u = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$username]);
        if (!$u) {
            return null;
        }
        if (!password_verify($password, $u['password_hash'])) {
            return null;
        }        if (password_needs_rehash($u['password_hash'], PASSWORD_ARGON2ID)) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $u['id']]);
        }
        return $u;
    }

    /** Check whether a user is hit by a global ban (*line / shun / qline / kline-style). */    public static function globalBanFor(array $user): ?array
    {
        $masks = [
            'nick!user@host' => strtolower($user['username'] . '!*@*'),
            'nick' => strtolower($user['username']),
            'email' => strtolower($user['email'] ?? ''),
            'ip' => $user['last_ip'] ?? '',
        ];
        foreach (Database::all(
            "SELECT * FROM bans WHERE active = 1 AND channel_id IS NULL
             AND (expires_at IS NULL OR expires_at > datetime('now'))"
        ) as $ban) {
            $mask = strtolower($ban['mask']);
            if ($mask === '*') {
                return $ban;
            }
            foreach ($masks as $v) {
                if ($v !== '' && self::maskMatch($mask, $v)) {
                    return $ban;
                }
            }
            if (!empty($user['last_ip']) && self::ipMatch($mask, (string) $user['last_ip'])) {
                return $ban;
            }
        }
        return null;
    }

    /** Match an IP/CIDR/wildcard-IP pattern against an IP. */
    public static function ipMatch(string $pattern, string $ip): bool
    {
        $pattern = trim($pattern);
        $ip = trim($ip);
        if ($pattern === '' || $ip === '') {
            return false;
        }
        // CIDR (e.g. 192.168.1.0/24)
        if (strpos($pattern, '/') !== false) {
            $parts = explode('/', $pattern, 2);
            $net = $parts[0];
            $bits = $parts[1];
            if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($bits)) {
                return false;
            }
            $bits = (int) $bits;
            if ($bits < 0 || $bits > 32 || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }
            $netLong = ip2long($net);
            $ipLong = ip2long($ip);
            if ($netLong === false || $ipLong === false) {
                return false;
            }
            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
            return ($netLong & $mask) === ($ipLong & $mask);
        }
        // Wildcard / partial IP (e.g. 192.168.* or 10.0.0.*)
        if (strpbrk($pattern, '*?') !== false) {
            return self::maskMatch($pattern, $ip);
        }
        // Exact IP
        return $ip === $pattern;
    }

    public static function maskMatch(string $pattern, string $value): bool
    {
        $pattern = strtolower($pattern);
        $value = strtolower($value);
        $regex = preg_quote($pattern, '#');
        $regex = str_replace(['\*', '\?'], ['[^!@:\s]*', '.'], $regex);
        return (bool) preg_match('#^' . $regex . '$#', $value);
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            $next = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/login?next=' . rawurlencode($next));
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') {
            http_response_code(403);
            exit('Forbidden');
        }
        return $u;
    }

    public static function isOnline(array $user, int $within = 30): bool
    {
        return $user['away'] === null && !empty($user['last_seen']) && (time() - strtotime($user['last_seen'] . ' UTC')) <= $within;
    }

    public static function isAdmin(array $user): bool
    {
        return $user['role'] === 'admin';
    }

    /** Permissions granted by the user's custom role (empty if none / not admin). */
    public static function rolePerms(array $user): array
    {
        if (empty($user['role_id'])) {
            return [];
        }
        $r = Database::row('SELECT perms FROM roles WHERE id = ?', [$user['role_id']]);
        $perms = json_decode((string) ($r['perms'] ?? '[]'), true);
        return is_array($perms) ? $perms : [];
    }

    /** Permissions from the current /oper session (o:line operclass), if any. */
    public static function operSessionPerms(): array
    {
        $id = $_SESSION['operclass_id'] ?? null;
        if (!$id) {
            return [];
        }
        $r = Database::row('SELECT perms FROM operclasses WHERE id = ?', [$id]);
        $perms = json_decode((string) ($r['perms'] ?? '[]'), true);
        return is_array($perms) ? $perms : [];
    }

    /** Name of the active oper class, if operating. */
    public static function operSessionClass(): ?string
    {
        $id = $_SESSION['operclass_id'] ?? null;
        if (!$id) {
            return null;
        }
        return Database::scalar('SELECT name FROM operclasses WHERE id = ?', [$id]) ?: null;
    }

    /** Check a permission key (admins have everything; roles + oper classes grant the rest). */
    public static function can(array $user, string $permission): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        if (in_array($permission, self::rolePerms($user), true)) {
            return true;
        }
        return in_array($permission, self::operSessionPerms(), true);
    }

    /** An IRC Operator: server admin, a user with the 'oper' permission, or an active o:line. */
    public static function isOper(array $user): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        return in_array('oper', self::rolePerms($user), true)
            || in_array('oper', self::operSessionPerms(), true);
    }

    /** Drop the current /oper session. */
    public static function deoper(): void
    {
        unset($_SESSION['operclass_id']);
    }
}

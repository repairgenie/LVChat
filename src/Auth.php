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

final class Auth
{
    public static function id(): ?int
    {
        $u = self::user();
        // Guests live in `guests`, never in `users` — no user id exists for them.
        if (!$u || (int) ($u['guest'] ?? 0) === 1) {
            return null;
        }
        return (int) $u['id'];
    }

    /** The messenger's session token from the X-LVC-Session header (if any).
     *  Cross-site clients that can't rely on cookies (mobile Safari blocks
     *  third-party cookies) authenticate with this bearer header instead. */
    public static function headerToken(): ?string
    {
        $h = $_SERVER['HTTP_X_LVC_SESSION'] ?? '';
        return (is_string($h) && $h !== '') ? $h : null;
    }

    /** Whether a token corresponds to a live user or guest session. */
    public static function validSessionToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (Database::scalar('SELECT 1 FROM sessions WHERE token = ? AND expires_at > datetime("now")', [$token])) {
            return true;
        }
        return (bool) Database::scalar('SELECT 1 FROM guest_sessions WHERE token = ? AND expires_at > datetime("now")', [$token]);
    }

    public static function user(): ?array
    {
        $token = $_SESSION['token'] ?? null;
        if (!$token) {
            $token = self::headerToken();
        }
        if (!$token) {
            return null;
        }
        $u = Database::row(
            'SELECT u.*, s.expires_at FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token = ? AND s.expires_at > datetime("now")',
            [$token]
        );
        if ($u) {
            // Throttle the presence write so most polls are pure reads (SQLite WAL
            // handles many readers + one writer). The in-memory row is patched so
            // the rest of the request still sees fresh data.
            $throttle = max(5, (int) (config_get('presence_throttle', '30') ?? 30));
            $lastWrite = (int) ($_SESSION['presence_ts'] ?? 0);
            if (time() - $lastWrite >= $throttle) {
                Database::query('UPDATE users SET last_seen = datetime("now"), last_ip = ? WHERE id = ?', [client_ip(), $u['id']]);
                $_SESSION['presence_ts'] = time();
                self::purgeExpired();
                record_peak();
            }
            if (empty($u['last_seen']) || time() - strtotime($u['last_seen'] . ' UTC') > $throttle) {
                $u['last_seen'] = now();
            }
            if (empty($u['last_ip'])) {
                $u['last_ip'] = client_ip();
            }
            return $u;
        }
        // Guest session — guests live in `guests`, never in `users`.
        $g = Database::row(
            'SELECT g.*, s.expires_at FROM guest_sessions s JOIN guests g ON g.id = s.guest_id
             WHERE s.token = ? AND s.expires_at > datetime("now")',
            [$token]
        );
        if ($g) {
            $throttle = max(5, (int) (config_get('presence_throttle', '30') ?? 30));
            $lastWrite = (int) ($_SESSION['presence_ts'] ?? 0);
            if (time() - $lastWrite >= $throttle) {
                Database::query('UPDATE guests SET last_seen = datetime("now"), ip = ? WHERE id = ?', [client_ip(), $g['id']]);
                $_SESSION['presence_ts'] = time();
                self::purgeExpired();
                record_peak();
            }
            if (empty($g['last_seen']) || time() - strtotime($g['last_seen'] . ' UTC') > $throttle) {
                $g['last_seen'] = now();
            }
            return self::guestActor($g);
        }
        return null;
    }

    /** Shape a `guests` row like a user row so the rest of the app treats it uniformly. */
    public static function guestActor(array $g): array
    {
        return [
            'id' => (int) $g['id'],
            'username' => $g['nick'],
            'email' => '',
            'password_hash' => '',
            'role' => 'user',
            'role_id' => null,
            'guest' => 1,
            'vhost' => null,
            'away' => null,
            'away_at' => null,
            'status_mode' => 'online',
            'custom_status' => '',
            'bot' => 0,
            'banned' => 0,
            'ban_reason' => null,
            'theme' => '',
            'registered_at' => $g['created_at'] ?? null,
            'last_seen' => $g['last_seen'] ?? null,
            'last_ip' => $g['ip'] ?? null,
            'status' => 'active',
            'status_reason' => null,
            'age_verified_at' => $g['age_verified_at'] ?? null,
        ];
    }

    /** Find a registered user or guest by nickname, returned as an actor array. */
    public static function findActor(string $nick): ?array
    {
        $u = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if ($u) {
            return $u;
        }
        $g = Database::row('SELECT * FROM guests WHERE nick = ? COLLATE NOCASE', [$nick]);
        if ($g) {
            return self::guestActor($g);
        }
        return null;
    }

    public static function login(array $user): void
    {
        self::loginToken($user);
    }

    /** Create a session for a user and return the raw session token. The web
     *  messenger stores this token and sends it as X-LVC-Session, so it can log
     *  in (and stay logged in) even when third-party cookies are blocked. */
    public static function loginToken(array $user): string
    {
        $token = bin2hex(random_bytes(32));
        Database::query(
            'INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, datetime("now", "+30 days"))',
            [$user['id'], $token]
        );
        Database::query('UPDATE users SET last_ip = ?, last_seen = datetime("now") WHERE id = ?', [client_ip(), $user['id']]);
        @session_regenerate_id(true);
        $_SESSION['token'] = $token;
        return $token;
    }

    public static function logout(): void
    {
        $user = self::user();
        $token = $_SESSION['token'] ?? null;
        if (!$token) {
            $token = self::headerToken();
        }
        if ($token) {
            Database::query('DELETE FROM sessions WHERE token = ?', [$token]);
            Database::query('DELETE FROM guest_sessions WHERE token = ?', [$token]);
        }
        if ($user && (int) ($user['guest'] ?? 0) === 1) {
            // Guests are not real accounts: release the nick immediately on logout
            // (the row + its DM history are kept, so the thread survives re-login).
            Database::query('UPDATE guests SET last_seen = NULL WHERE id = ?', [$user['id']]);
        }
        unset($_SESSION['token']);
        @session_regenerate_id(true);
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

    /**
     * Register a real account. When $ageVerified is true the user has certified
     * they are at least 18; when registration_requires_approval is enabled new
     * accounts are created as 'pending' (unless $autoApprove, used when an admin
     * creates the account manually or the very first account becomes the admin).
     */
    public static function register(string $username, string $email, string $password, bool $ageVerified = false, bool $autoApprove = false): array
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
        if (!$ageVerified) {
            $errors[] = 'You must certify that you are at least 18 years old to register.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (BanService::nickForbidden($username)) {
            $errors[] = 'That username is not allowed.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $exists = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$username]);
        $emailTaken = Database::row('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [$email]);
        if ($emailTaken) {
            return ['ok' => false, 'errors' => ['That email address is already registered.']];
        }
        if ($exists) {
            return ['ok' => false, 'errors' => ['That username is already registered.']];
        }
        $hasAdmin = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE role = "admin"') > 0;
        if (!$hasAdmin || $autoApprove || config_get('registration_requires_approval', '0') !== '1') {
            $status = 'active';
        } else {
            $status = 'pending';
        }
        $ageAt = $ageVerified ? now() : null;
        // A stale guest row may hold the nick — convert it into the real account,
        // transferring its DMs / memberships / notifications to the new user id.
        $guest = Database::row('SELECT * FROM guests WHERE nick = ? COLLATE NOCASE', [$username]);
        if ($guest) {
            if (self::guestInUse($guest)) {
                return ['ok' => false, 'errors' => ['That username is already registered.']];
            }
            Database::query(
                'INSERT INTO users (username, email, password_hash, status, age_verified_at) VALUES (?, ?, ?, ?, ?)',
                [$username, $email, password_hash($password, PASSWORD_ARGON2ID), $status, $ageAt]
            );
            $id = (int) Database::lastId();
            Database::query('UPDATE private_messages SET sender_id = ?, sender_guest_id = NULL WHERE sender_guest_id = ?', [$id, $guest['id']]);
            Database::query('UPDATE private_messages SET recipient_id = ?, recipient_guest_id = NULL WHERE recipient_guest_id = ?', [$id, $guest['id']]);
            Database::query('UPDATE channel_members SET user_id = ?, guest_id = NULL WHERE guest_id = ?', [$id, $guest['id']]);
            Database::query('UPDATE messages SET sender_id = ?, sender_guest_id = NULL WHERE sender_guest_id = ?', [$id, $guest['id']]);
            Database::query('UPDATE notifications SET user_id = ?, guest_user_id = NULL WHERE guest_user_id = ?', [$id, $guest['id']]);
            Database::query('UPDATE notifications SET sender_id = ?, sender_guest_id = NULL WHERE sender_guest_id = ?', [$id, $guest['id']]);
            Database::query('DELETE FROM guest_sessions WHERE guest_id = ?', [$guest['id']]);
            Database::query('DELETE FROM guests WHERE id = ?', [$guest['id']]);
        } else {
            Database::query(
                'INSERT INTO users (username, email, password_hash, status, age_verified_at) VALUES (?, ?, ?, ?, ?)',
                [$username, $email, password_hash($password, PASSWORD_ARGON2ID), $status, $ageAt]
            );
            $id = (int) Database::lastId();
        }
        if (!$hasAdmin) {
            // The first account auto-gains admin only when a SETUP_TOKEN is
            // configured in the environment.  This prevents anyone who reaches
            // a fresh install before the real admin from gaining full control.
            // To bootstrap: set SETUP_TOKEN in .env, then register with
            // setup_token=<value> in the POST body.
            $setupToken = getenv('SETUP_TOKEN');
            $provided   = $autoApprove
                || ($setupToken !== false && $setupToken !== '' && isset($_POST['setup_token']) && hash_equals($setupToken, (string) $_POST['setup_token']));
            if ($provided) {
                Database::query('UPDATE users SET role = "admin", status = "active", status_reason = NULL WHERE id = ?', [$id]);
            }
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Create and log in an anonymous guest. Guests live in the `guests` table —
     * never in `users` — so they are not registered accounts at all.
     *
     * A guest nick is reused in place when the previous holder is gone (logout or
     * a short inactivity), so the nick frees quickly and its DM history survives.
     * Returns the guest actor array, or null if the nick is invalid/in use/banned.
     */
    public static function loginGuest(string $nick, bool $ageVerified = false): ?array
    {
        $nick = trim($nick);
        if (!preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $nick)) {
            return null;
        }
        if (!$ageVerified) {
            return null;
        }
        self::purgeGuests();
        // A registered user owns this nick — guests can never take it.
        if (Database::scalar('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE', [$nick])) {
            return null;
        }
        $existing = Database::row('SELECT * FROM guests WHERE nick = ? COLLATE NOCASE', [$nick]);
        if ($existing && self::guestInUse($existing)) {
            return null;
        }
        $probe = ['username' => $nick, 'email' => '', 'last_ip' => client_ip(), 'role' => 'user', 'guest' => 1];
        if (self::globalBanFor($probe)) {
            return null;
        }
        if ($existing) {
            // Stale guest row: reclaim it, keeping the same guest id and DMs.
            Database::query('DELETE FROM guest_sessions WHERE guest_id = ?', [$existing['id']]);
            Database::query('UPDATE guests SET last_seen = datetime("now"), ip = ?, age_verified_at = COALESCE(age_verified_at, datetime("now")) WHERE id = ?', [client_ip(), $existing['id']]);
            $g = Database::row('SELECT * FROM guests WHERE id = ?', [$existing['id']]);
            self::loginGuestSession($g);
            log_audit('guest_join', $nick);
            return self::guestActor($g);
        }
        Database::query('INSERT INTO guests (nick, ip, age_verified_at) VALUES (?, ?, datetime("now"))', [$nick, client_ip()]);
        $g = Database::row('SELECT * FROM guests WHERE id = ?', [(int) Database::lastId()]);
        self::loginGuestSession($g);
        log_audit('guest_join', $nick);
        return self::guestActor($g);
    }

    /** Whether a nickname is currently claimed. Registered users always own their
     *  name; a guest's nick is only claimed while they are actively present. */
    public static function nickInUse(array $user, int $grace = 120): bool
    {
        if ((int) ($user['guest'] ?? 0) !== 1) {
            return true;
        }
        return !empty($user['last_seen'])
            && (time() - strtotime((string) $user['last_seen'] . ' UTC')) < $grace;
    }

    /** Whether a `guests` row is actively present right now. */
    public static function guestInUse(array $g, int $grace = 120): bool
    {
        return !empty($g['last_seen'])
            && (time() - strtotime((string) $g['last_seen'] . ' UTC')) < $grace;
    }

    private static function loginGuestSession(array $g): void
    {
        $token = bin2hex(random_bytes(32));
        Database::query(
            'INSERT INTO guest_sessions (guest_id, token, expires_at) VALUES (?, ?, datetime("now", "+30 days"))',
            [$g['id'], $token]
        );
        Database::query('UPDATE guests SET last_seen = datetime("now"), ip = ? WHERE id = ?', [client_ip(), $g['id']]);
        @session_regenerate_id(true);
        $_SESSION['token'] = $token;
    }

    /** Remove guests that have been inactive for over a day. */
    public static function purgeGuests(): void
    {
        Database::query('DELETE FROM guests WHERE last_seen < datetime("now", "-1 day")');
    }

    /**
     * Opportunistic housekeeping for tables that otherwise grow forever:
     * expired sessions (both kinds) and stale guest rows. Throttled off the
     * presence write (see user()), so most requests stay pure reads.
     */
    public static function purgeExpired(): void
    {
        $last = (int) ($_SESSION['purge_ts'] ?? 0);
        if (time() - $last < 3600) {
            return;
        }
        $_SESSION['purge_ts'] = time();
        Database::query('DELETE FROM sessions WHERE expires_at < datetime("now")');
        Database::query('DELETE FROM guest_sessions WHERE expires_at < datetime("now")');
        Database::query('DELETE FROM guests WHERE last_seen < datetime("now", "-1 day")');
        Database::query('DELETE FROM totp_used_counters WHERE expires_at < datetime("now")');
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

    /** Require a logged-in REGISTERED account (guests rejected). Guests share
     *  an id space with users only by coincidence of separate AUTOINCREMENT
     *  sequences — user-keyed writes must never run against a guest id. */
    public static function requireAccount(): array
    {
        $u = self::user();
        if (!$u) {
            $next = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/login?next=' . rawurlencode($next));
        }
        if (self::isGuest($u)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'Registered users only.']));
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

    public const STATUS_MODES = ['online', 'away', 'dnd', 'invisible', 'custom'];

    /** Effective status mode. A legacy away flag (with no mode set) maps to 'away'. */
    public static function statusMode(array $user): string
    {
        if (self::isGuest($user)) {
            return 'online';
        }
        $m = (string) ($user['status_mode'] ?? '');
        if (!in_array($m, self::STATUS_MODES, true)) {
            $m = !empty($user['away']) ? 'away' : 'online';
        }
        return $m;
    }

    /** The status text shown next to the nick: custom text wins, else the away message. */
    public static function statusText(array $user): string
    {
        $custom = mb_substr((string) ($user['custom_status'] ?? ''), 0, 80);
        if ($custom !== '') {
            return $custom;
        }
        if (self::statusMode($user) === 'away') {
            return (string) ($user['away'] ?? '');
        }
        return '';
    }

    /** Actually connected right now, regardless of the chosen status mode. */
    public static function actuallyOnline(array $user, int $within = 30): bool
    {
        return !empty($user['last_seen']) && (time() - strtotime($user['last_seen'] . ' UTC')) <= $within;
    }

    /** Whether this user appears online to others. 'invisible' hides them. */
    public static function appearsOnline(array $user, int $within = 30): bool
    {
        return self::actuallyOnline($user, $within) && self::statusMode($user) !== 'invisible';
    }

    /** Whether the user set Do Not Disturb (silences their notifications). */
    public static function isDnd(array $user): bool
    {
        return self::statusMode($user) === 'dnd';
    }

    /** The presence object clients render: mode, text, and the online flags. */
    public static function statusInfo(array $user, int $within = 30): array
    {
        $mode = self::statusMode($user);
        $text = self::statusText($user);
        $appears = self::appearsOnline($user, $within);
        return [
            'status_mode' => $mode,
            'custom_status' => $text,
            'away' => $mode === 'away' ? ($text !== '' ? $text : null) : null,
            'is_online' => $appears ? 1 : 0,
            'dnd' => $mode === 'dnd' ? 1 : 0,
            'invisible' => $mode === 'invisible' ? 1 : 0,
        ];
    }

    /** Nick colour forced on Helper-role users (they always show green). */
    public const HELPER_COLOR = '#22c55e';

    /** Whether a user holds a Helper role (green nick + auto half-op everywhere). */
    public static function isHelper(array $user): bool
    {
        if (!empty($user['role_helper'])) {
            return (int) $user['role_helper'] === 1;
        }
        $roleId = $user['role_id'] ?? null;
        if (!$roleId) {
            return false;
        }
        return (bool) Database::scalar('SELECT helper FROM roles WHERE id = ?', [(int) $roleId]);
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

    /** A netadmin: server admin or a user operating with the 'netadmin' o:line class. */
    public static function isNetadmin(array $user): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        return strtolower((string) self::operSessionClass()) === 'netadmin';
    }

    /** Drop the current /oper session. */
    public static function deoper(): void
    {
        unset($_SESSION['operclass_id']);
    }
}

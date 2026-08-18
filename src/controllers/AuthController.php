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

final class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        $next = safe_next((string) ($_GET['next'] ?? '/app'));
        render_view('auth/login', [
            'next' => $next,
            'error' => flash(),
        ]);
    }

    public static function login(): void
    {
        Csrf::verify();
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $next = safe_next((string) ($_POST['next'] ?? '/app'));

        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many failed login attempts. Please wait a few minutes and try again.');
            redirect('/login?next=' . rawurlencode($next));
        }

        $user = Auth::attempt($username, $password);
        if (!$user) {
            login_attempt_record();
            flash('Invalid username or password.');
            redirect('/login?next=' . rawurlencode($next));
        }
        $ban = Auth::globalBanFor($user);
        if ($ban || (int) $user['banned'] === 1) {
            login_attempt_record();
            $reason = $ban['reason'] ?? $user['ban_reason'] ?? '';
            flash('This account is banned.' . ($reason ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        if (($user['status'] ?? 'active') === 'suspended') {
            login_attempt_record();
            $reason = trim((string) ($user['status_reason'] ?? ''));
            flash('This account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        // MFA gate: enrolled users must pass the TOTP challenge; users whose
        // account class requires MFA must enroll before the session opens.
        if (TotpService::enabled($user)) {
            self::beginMfa($user, $next);
            redirect('/login/mfa');
        }
        if (TotpService::requiredFor($user)) {
            self::beginMfa($user, $next);
            $_SESSION['mfa_setup_secret'] = TotpService::generateSecret();
            redirect('/login/mfa/setup');
        }
        login_attempt_clear();
        Auth::login($user);
        redirect($next);
    }

    /** Park a password-verified user in a pre-auth MFA state (no session token yet). */
    private static function beginMfa(array $user, string $next): void
    {
        @session_regenerate_id(true);
        $_SESSION['mfa_pending_uid'] = (int) $user['id'];
        $_SESSION['mfa_pending_next'] = $next;
    }

    public static function mfaForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        if (empty($_SESSION['mfa_pending_uid'])) {
            redirect('/login');
        }
        render_view('auth/mfa', ['error' => flash()]);
    }

    public static function mfaVerify(): void
    {
        Csrf::verify();
        $uid = (int) ($_SESSION['mfa_pending_uid'] ?? 0);
        $next = safe_next((string) ($_SESSION['mfa_pending_next'] ?? '/app'));
        if (!$uid) {
            redirect('/login');
        }
        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many failed attempts. Please wait a few minutes and try again.');
            redirect('/login');
        }
        $user = Database::row('SELECT * FROM users WHERE id = ?', [$uid]);
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!$user || !TotpService::enabled($user) || !TotpService::verify((string) $user['totp_secret'], $code, 1, (int) $uid)) {
            login_attempt_record();
            flash('Invalid authentication code. Try again.');
            redirect('/login/mfa');
        }
        unset($_SESSION['mfa_pending_uid'], $_SESSION['mfa_pending_next']);
        login_attempt_clear();
        Auth::login($user);
        redirect($next);
    }

    public static function mfaSetupForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        $uid = (int) ($_SESSION['mfa_pending_uid'] ?? 0);
        $secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
        if (!$uid || $secret === '') {
            redirect('/login');
        }
        $user = Database::row('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$user) {
            redirect('/login');
        }
        render_view('auth/mfa-setup', [
            'error' => flash(),
            'secret' => TotpService::formatSecret($secret),
            'uri' => TotpService::otpauthUri($secret, (string) $user['username'], (string) config_get('site_name', 'LVChat')),
        ]);
    }

    public static function mfaSetupVerify(): void
    {
        Csrf::verify();
        $uid = (int) ($_SESSION['mfa_pending_uid'] ?? 0);
        $next = safe_next((string) ($_SESSION['mfa_pending_next'] ?? '/app'));
        $secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
        if (!$uid || $secret === '') {
            redirect('/login');
        }
        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many failed attempts. Please wait a few minutes and try again.');
            redirect('/login');
        }
        $user = Database::row('SELECT * FROM users WHERE id = ?', [$uid]);
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!$user || !TotpService::verify($secret, $code, 1, (int) $uid)) {
            login_attempt_record();
            flash('Invalid code. Check your authenticator app and try again.');
            redirect('/login/mfa/setup');
        }
        TotpService::enable($uid, $secret);
        unset($_SESSION['mfa_pending_uid'], $_SESSION['mfa_pending_next'], $_SESSION['mfa_setup_secret']);
        login_attempt_clear();
        log_audit('mfa_enroll', $user['username']);
        Auth::login($user);
        redirect($next);
    }

    public static function registerForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        $next = safe_next((string) ($_GET['next'] ?? '/app'));
        $inviteToken = trim((string) ($_GET['invite'] ?? ''));
        $invite = null;
        if ($inviteToken !== '') {
            $invite = InviteService::valid($inviteToken);
            if (!$invite) {
                flash('This invitation is invalid, expired, or has already been used.');
                redirect('/register');
            }
        }
        render_view('auth/register', [
            'next' => $next,
            'error' => flash(),
            'old' => $_SESSION['old'] ?? [],
            'invite' => $invite,
            'registration_open' => config_get('registration_enabled', '1') === '1',
            'requires_approval' => config_get('registration_requires_approval', '0') === '1',
        ]);
    }

    public static function register(): void
    {
        Csrf::verify();
        // Honeypot: bots fill every visible-looking field. Humans never see this
        // one, so a non-empty value means an automated submission. Fail silently —
        // no flash, no account — so bots can't tell a block from a success.
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            log_audit('bot_signup_blocked', trim((string) ($_POST['username'] ?? '')));
            redirect('/register');
        }
        $next = safe_next((string) ($_POST['next'] ?? '/app'));
        $inviteToken = trim((string) ($_POST['invite'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $invite = null;
        if ($inviteToken !== '') {
            // An invite token bypasses the registration_enabled toggle: it is
            // the proof of access when open registration is closed.
            $invite = InviteService::valid($inviteToken);
            if (!$invite) {
                $_SESSION['old'] = ['username' => $username];
                flash('This invitation is invalid, expired, or has already been used.');
                redirect('/register');
            }
            if (strcasecmp($email, $invite['email']) !== 0) {
                $_SESSION['old'] = ['username' => $username];
                flash('This invitation is only valid for ' . $invite['email'] . '.');
                redirect('/register?invite=' . rawurlencode($inviteToken));
            }
        } elseif (config_get('registration_enabled', '1') !== '1') {
            flash('Registration is currently disabled on this server.');
            redirect('/register');
        }

        // Per-IP registration throttle. Invite flows are exempt: the token is
        // already the proof of access, and admins control who gets invites.
        $regLimit = registration_rate_limit();
        if ($invite === null && $regLimit > 0 && registration_attempt_count() >= $regLimit) {
            flash('Too many accounts have been created from this address recently. Please try again later.');
            redirect('/register');
        }

        $age18 = ($_POST['age18'] ?? '0') === '1';
        $result = Auth::register($username, $email, $password, $age18);
        if (!$result['ok']) {
            $_SESSION['old'] = ['username' => $username, 'email' => $email];
            flash(implode(' ', $result['errors']));
            redirect('/register?next=' . rawurlencode($next) . ($invite ? '&invite=' . rawurlencode($inviteToken) : ''));
        }
        if ($invite) {
            InviteService::claim((int) $invite['id'], (int) $result['id']);
        } else {
            registration_attempt_record();
        }
        $user = Auth::attempt($username, $password);
        if (TotpService::requiredFor($user)) {
            self::beginMfa($user, $next);
            $_SESSION['mfa_setup_secret'] = TotpService::generateSecret();
            redirect('/login/mfa/setup');
        }
        Auth::login($user);
        if (($user['status'] ?? 'active') === 'pending') {
            flash('Your account is pending admin approval. You can browse channels, but you cannot chat until an admin approves it.');
            redirect('/app');
        }
        redirect($next);
    }

    public static function logout(): void
    {
        Csrf::verify();
        Auth::logout();
        redirect('/login');
    }

    public static function forgotPasswordForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        render_view('auth/forgot-password', [
            'error' => flash(),
        ]);
    }

    public static function forgotPassword(): void
    {
        Csrf::verify();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many attempts. Please wait a few minutes and try again.');
            redirect('/forgot-password');
        }
        login_attempt_record();

        // Only registered (non-guest) accounts have a usable email. The same
        // message is shown whether or not the address exists, so the form
        // cannot be used to enumerate accounts.
        $user = Database::row(
            'SELECT * FROM users WHERE email = ? COLLATE NOCASE AND guest = 0',
            [$email]
        );
        if ($user) {
            AuthTokenService::invalidateAllForUser((int) $user['id'], AuthTokenService::TYPE_RESET);
            $token = AuthTokenService::create((int) $user['id'], AuthTokenService::TYPE_RESET, AuthTokenService::RESET_TTL_MINUTES);
            Mailer::sendPasswordReset($user['email'], $user['username'], AuthTokenService::resetLink($token));
        }
        flash('If an account exists with that email, we\'ve sent a password reset link.');
        redirect('/forgot-password');
    }

    public static function resetPasswordForm(array $params): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        $token = (string) ($params['token'] ?? '');
        $row = AuthTokenService::validate($token, AuthTokenService::TYPE_RESET);
        if (!$row) {
            flash('This password reset link is invalid or has expired. Please request a new one.');
            redirect('/forgot-password');
        }
        render_view('auth/reset-password', [
            'token' => $token,
            'error' => flash(),
        ]);
    }

    public static function resetPassword(array $params): void
    {
        Csrf::verify();
        $token = (string) ($params['token'] ?? '');
        $row = AuthTokenService::validate($token, AuthTokenService::TYPE_RESET);
        if (!$row) {
            flash('This password reset link is invalid or has expired. Please request a new one.');
            redirect('/forgot-password');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            flash('Password must be at least 8 characters.');
            redirect('/reset-password/' . rawurlencode($token));
        }
        if ($password !== $confirm) {
            flash('Passwords do not match.');
            redirect('/reset-password/' . rawurlencode($token));
        }

        $userId = (int) $row['id'];
        Database::query(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_ARGON2ID), $userId]
        );
        AuthTokenService::claim((int) $row['token_id']);
        AuthTokenService::invalidateAllForUser($userId, AuthTokenService::TYPE_RESET);
        AuthTokenService::invalidateAllForUser($userId, AuthTokenService::TYPE_MAGIC);
        // A password reset is a credential change: every existing session must
        // re-authenticate with the new password.
        Auth::killSessions($userId, false);
        log_audit('password_reset', 'user#' . $userId);
        flash('Your password has been reset. Log in with your new password.');
        redirect('/login');
    }

    public static function magicLoginForm(): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        render_view('auth/magic-link', [
            'error' => flash(),
        ]);
    }

    public static function magicLoginRequest(): void
    {
        Csrf::verify();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many attempts. Please wait a few minutes and try again.');
            redirect('/magic-link');
        }
        login_attempt_record();

        // Same anti-enumeration rule as the password reset form.
        $user = Database::row(
            'SELECT * FROM users WHERE email = ? COLLATE NOCASE AND guest = 0',
            [$email]
        );
        if ($user) {
            AuthTokenService::invalidateAllForUser((int) $user['id'], AuthTokenService::TYPE_MAGIC);
            $token = AuthTokenService::create((int) $user['id'], AuthTokenService::TYPE_MAGIC, AuthTokenService::MAGIC_TTL_MINUTES);
            Mailer::sendMagicLink($user['email'], $user['username'], AuthTokenService::magicLink($token));
        }
        flash('If an account exists with that email, we\'ve sent a login link.');
        redirect('/magic-link');
    }

    public static function magicLogin(array $params): void
    {
        if (Auth::user()) {
            redirect('/app');
        }
        $token = (string) ($params['token'] ?? '');
        $row = AuthTokenService::validate($token, AuthTokenService::TYPE_MAGIC);
        if (!$row) {
            flash('This login link is invalid or has expired. Please request a new one.');
            redirect('/magic-link');
        }
        // Magic links authenticate as the user, so apply the same
        // ban/suspension gates as a password login.
        $ban = Auth::globalBanFor($row);
        if ($ban || (int) $row['banned'] === 1) {
            $reason = $ban['reason'] ?? $row['ban_reason'] ?? '';
            flash('This account is banned.' . ($reason ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        if (($row['status'] ?? 'active') === 'suspended') {
            $reason = trim((string) ($row['status_reason'] ?? ''));
            flash('This account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        AuthTokenService::claim((int) $row['token_id']);
        AuthTokenService::invalidateAllForUser((int) $row['id'], AuthTokenService::TYPE_MAGIC);
        login_attempt_clear();
        // MFA gate — magic links must still pass TOTP like a password login.
        if (TotpService::enabled($row)) {
            self::beginMfa($row, '/app');
            redirect('/login/mfa');
        }
        if (TotpService::requiredFor($row)) {
            self::beginMfa($row, '/app');
            $_SESSION['mfa_setup_secret'] = TotpService::generateSecret();
            redirect('/login/mfa/setup');
        }
        Auth::login($row);
        log_audit('magic_login', 'user#' . (int) $row['id']);
        redirect('/app');
    }

    public static function guestLogin(): void
    {
        Csrf::verify();
        $next = safe_next((string) ($_POST['next'] ?? '/app'));
        $nick = trim((string) ($_POST['nick'] ?? ''));
        $age18 = ($_POST['age18'] ?? '0') === '1';
        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many attempts. Please wait a few minutes and try again.');
            redirect('/login?next=' . rawurlencode($next));
        }
        if (!$age18) {
            login_attempt_record();
            flash('You must certify that you are at least 18 years old to join as a guest.');
            redirect('/login?next=' . rawurlencode($next));
        }
        $user = Auth::loginGuest($nick, true);
        if (!$user) {
            login_attempt_record();
            flash('That nickname is invalid or already in use. Try another one.');
            redirect('/login?next=' . rawurlencode($next));
        }
        login_attempt_clear();
        redirect($next);
    }
}

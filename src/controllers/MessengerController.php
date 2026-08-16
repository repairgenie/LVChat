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

/**
 * Token-based auth for the cross-site web messenger. The messenger cannot rely
 * on the chat's session cookie on mobile (Safari blocks third-party cookies),
 * so it logs in here and gets back a bearer session token that it stores in
 * localStorage and sends as `X-LVC-Session` on every request. `Auth::user()`
 * accepts that header, and `Csrf::verify()` skips the cookie-CSRF check for
 * header-authenticated requests (the bearer token + CORS preflight already
 * protect them).
 *
 * The login/MFA endpoints require the `X-Messenger: 1` custom header: the
 * browser only sends a custom header after a CORS preflight, which non-
 * allowlisted origins cannot complete — that preflight is the anti-CSRF here
 * (there is no usable session to bind a CSRF token to before login).
 */
final class MessengerController
{
    private static function requireMessenger(): void
    {
        if (($_SERVER['HTTP_X_MESSENGER'] ?? '') !== '1') {
            json_out(['error' => 'Not a messenger request.'], 403);
        }
    }

    /** POST /api/messenger/login — password login returning a bearer token. */
    public static function login(): void
    {
        self::requireMessenger();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            json_out(['error' => 'Enter your username and password.'], 400);
        }
        if (login_attempt_count() >= login_attempt_max()) {
            json_out(['error' => 'Too many failed login attempts. Please wait a few minutes and try again.'], 429);
        }

        $user = Auth::attempt($username, $password);
        if (!$user) {
            login_attempt_record();
            json_out(['error' => 'Invalid username or password.'], 401);
        }
        $ban = Auth::globalBanFor($user);
        if ($ban || (int) $user['banned'] === 1) {
            login_attempt_record();
            $reason = $ban['reason'] ?? $user['ban_reason'] ?? '';
            json_out(['error' => 'This account is banned.' . ($reason !== '' ? ' Reason: ' . $reason : '')], 403);
        }
        if (($user['status'] ?? 'active') === 'suspended') {
            login_attempt_record();
            $reason = trim((string) ($user['status_reason'] ?? ''));
            json_out(['error' => 'This account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : '')], 403);
        }
        if (TotpService::enabled($user)) {
            $ticket = AuthTokenService::create((int) $user['id'], 'messenger_mfa', 5);
            json_out(['mfa' => true, 'ticket' => $ticket]);
        }
        if (TotpService::requiredFor($user)) {
            json_out(['mfa_setup' => true, 'error' => 'Two-factor authentication must be set up for this account. Open the web app to enroll.']);
        }
        login_attempt_clear();
        $token = Auth::loginToken($user);
        json_out(['ok' => true, 'token' => $token, 'user' => Auth::statusInfo($user)]);
    }

    /** POST /api/messenger/mfa — complete a token login with a TOTP code. */
    public static function mfa(): void
    {
        self::requireMessenger();
        $ticket = trim((string) ($_POST['ticket'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($ticket === '' || $code === '') {
            json_out(['error' => 'Enter your authentication code.'], 400);
        }
        $row = AuthTokenService::validate($ticket, 'messenger_mfa');
        if (!$row) {
            json_out(['error' => 'That login has expired. Try signing in again.'], 410);
        }
        if (login_attempt_count() >= login_attempt_max()) {
            json_out(['error' => 'Too many failed attempts. Please wait a few minutes and try again.'], 429);
        }
        if (!TotpService::verify((string) $row['totp_secret'], $code, 1, (int) $row['id'])) {
            login_attempt_record();
            json_out(['error' => 'Invalid authentication code. Try again.'], 401);
        }
        login_attempt_clear();
        AuthTokenService::claim((int) $row['token_id']);
        $token = Auth::loginToken($row);
        json_out(['ok' => true, 'token' => $token, 'user' => Auth::statusInfo($row)]);
    }

    /** POST /api/messenger/logout — destroy the messenger's bearer session. */
    public static function logout(): void
    {
        $token = Auth::headerToken();
        if ($token !== null) {
            Database::query('DELETE FROM sessions WHERE token = ?', [$token]);
            Database::query('DELETE FROM guest_sessions WHERE token = ?', [$token]);
        }
        json_out(['ok' => true]);
    }
}

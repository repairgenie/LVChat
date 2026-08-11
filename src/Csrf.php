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

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Whether this request is authenticated by the messenger's bearer session
     *  header (X-LVC-Session). Such requests are CSRF-safe by construction: the
     *  token is a bearer secret sent in a custom header that cross-site pages
     *  cannot set (CORS preflight + the origin allowlist block them), so the
     *  cookie-CSRF check can be skipped. */
    public static function bearerAuthorized(): bool
    {
        $h = $_SERVER['HTTP_X_LVC_SESSION'] ?? '';
        return is_string($h) && $h !== '' && Auth::validSessionToken($h);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . h(self::token()) . '">';
    }

    public static function verify(): void
    {
        // Requests authenticated by the messenger's session-token header are
        // safe by construction: the token is a bearer secret sent in a custom
        // header that cross-site pages cannot set (CORS preflight + the origin
        // allowlist block them), so the cookie-CSRF check is unnecessary here.
        if (self::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent)) {
            $sent = '';
        }
        // Accept the token from the POST field or the X-CSRF header (the JS
        // client sends both; some raw fetches only set the header).
        if ($sent === '') {
            $h = $_SERVER['HTTP_X_CSRF'] ?? '';
            $sent = is_string($h) ? $h : '';
        }
        if ($sent === '' || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch');
        }
    }
}

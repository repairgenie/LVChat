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
 * GET /api/embed?url=… — the Channel URL pane's iframe target. Fetches the page
 * server-side (see EmbedService) so sites that refuse to be framed still load.
 * GET /api/embed/res?url=… — re-serves a stylesheet/font/image referenced by an
 * embedded page with Access-Control-Allow-Origin: * so the opaque-origin
 * sandbox can use it. Signed-in sessions only; validation + SSRF guards live in
 * EmbedService.
 */
final class EmbedController
{
    public static function proxy(): void
    {
        $user = Auth::user();
        if (!$user) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Sign in to view this page.');
        }

        $url = (string) ($_GET['url'] ?? '');
        $result = EmbedService::proxy($url);
        if (isset($result['error'])) {
            http_response_code((int) ($result['status'] ?? 400));
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache');
            exit($result['error']);
        }

        header('Content-Type: ' . $result['content_type']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: frame-ancestors ' . ($_SERVER['HTTP_HOST'] ?? '') . '; sandbox allow-scripts allow-forms allow-popups');
        echo $result['body'];
        exit;
    }

    public static function resource(): void
    {
        $user = Auth::user();
        if (!$user) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Sign in to view this page.');
        }

        $url = (string) ($_GET['url'] ?? '');
        $result = EmbedService::resource($url);
        if (isset($result['error'])) {
            http_response_code((int) ($result['status'] ?? 400));
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache');
            exit($result['error']);
        }

        header('Content-Type: ' . $result['content_type']);
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        header('Referrer-Policy: no-referrer');
        echo $result['body'];
        exit;
    }
}

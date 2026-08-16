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
    /** Session-based rate limit: max 30 embed requests per 60 seconds (allows
     *  normal bursts like a single page's subresources but throttles floods). */
    private static function rateLimit(string $key): void
    {
        $k = 'embed_bucket_' . $key;
        $window = [0, time()]; // [count, windowStart]
        $saved = (array) ($_SESSION[$k] ?? []);
        if (is_array($saved) && ($saved[1] ?? 0) > time() - 60) {
            $window = [$saved[0] ?? 0, (int) ($saved[1] ?? time())];
        }
        if ($window[0] >= 30) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Too many requests. Please slow down.');
        }
        $window[0]++;
        $_SESSION[$k] = $window;
    }

    public static function proxy(): void
    {
        $user = Auth::user();
        if (!$user) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Sign in to view this page.');
        }
        // Rate limit: each fetch is a multi-hop server-side request (up to 4 MB,
        // 8 s connect / 15 s read timeouts) — an unthrottled flood would pin
        // workers and burn bandwidth.
        self::rateLimit('embed');

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
        // Sanitize the Host header — it's client-controlled and must not be
        // interpolated into CSP without validation. Allow IPv6 [...], ports,
        // and standard hostname characters.
        $host = preg_replace('/[^\w.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? '');
        header('Content-Security-Policy: frame-ancestors ' . $host . '; sandbox allow-scripts allow-forms allow-popups');
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
        // Same budget as the page proxy (each is a server-side fetch).
        self::rateLimit('embed');

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
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        header('Referrer-Policy: no-referrer');
        // Validate Content-Type from remote server to prevent MIME confusion.
        // SVG/XML/HTML are explicitly excluded: served as application/octet-stream
        // so a top-level navigation can never execute attacker-controlled scripts
        // in the chat origin (this endpoint has no per-request sandbox by design
        // of the opaque-origin embed flow).
        $ct = strtolower($result['content_type']);
        $ctSafe = false;
        // Fonts include legacy MIME types still served by common nginx/apache
        // configs; fonts are inert binary data with no script execution.
        $safeTypes = ['text/css', 'text/javascript', 'application/javascript', 'font/', 'application/font-', 'application/x-font-', 'application/vnd.ms-fontobject', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/vnd.microsoft.icon', 'image/x-icon', 'audio/', 'video/'];
        foreach ($safeTypes as $prefix) {
            if (str_starts_with($ct, $prefix)) { $ctSafe = true; break; }
        }
        if (!$ctSafe || str_contains($ct, 'svg') || str_contains($ct, 'xml') || str_contains($ct, 'html')) {
            header('Content-Type: application/octet-stream');
        }
        // Defense-in-depth: sandbox any document-level navigation of proxied
        // output (opaque origin, no same-origin) so even a served HTML/SVG body
        // cannot touch the chat app's origin if loaded at the top level.
        header('Content-Security-Policy: sandbox allow-forms allow-popups');
        echo $result['body'];
        exit;
    }
}

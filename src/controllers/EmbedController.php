<?php

declare(strict_types=1);

/**
 * GET /api/embed?url=… — the Channel URL pane's iframe target. Fetches the page
 * server-side (see EmbedService) so sites that refuse to be framed still load.
 * Signed-in sessions only; validation + SSRF guards live in EmbedService.
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
}

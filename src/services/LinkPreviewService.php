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
 * Fetches and caches OpenGraph metadata for URLs posted in chat.
 * Images are proxied through the server to avoid hotlinking issues
 * and to keep previews working when source sites block external refs.
 */
final class LinkPreviewService
{
    private const CACHE_TTL = 86400;       // 24 hours — re-fetch stale previews after this
    private const PURGE_AGE = 2592000;     // 30 days — delete previews older than this
    private const FETCH_TIMEOUT = 10;      // total cURL timeout (seconds)
    private const CONNECT_TIMEOUT = 5;     // cURL connect timeout (seconds)
    private const MAX_HTML = 524288;       // 512 KB — max HTML to download
    private const MAX_IMAGE = 5242880;     // 5 MB — max image to proxy
    private const PREVIEW_DIR = '/uploads/previews';

    /** Whether the feature is enabled (admin toggle). */
    public static function enabled(): bool
    {
        return config_get('link_preview_enabled', '1') === '1';
    }

    /** Extract the first URL from a text string, or null. */
    public static function extractFirstUrl(string $content): ?string
    {
        if (preg_match('#(https?://[^\s<>"\')]+)#i', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Should this message get a link preview? Only single-URL messages. */
    public static function shouldPreview(string $content): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $trimmed = trim($content);
        if ($trimmed === '') {
            return false;
        }
        // Must contain exactly one URL and nothing else meaningful.
        if (preg_match('#(https?://[^\s<>"\')]+)#i', $trimmed, $m)) {
            $url = $m[1];
            // Strip the URL and check if anything meaningful remains.
            $rest = trim(str_replace($url, '', $trimmed));
            return $rest === '';
        }
        return false;
    }

    /**
     * Get a preview for a URL. Returns cached data when available and fresh,
     * otherwise fetches, stores, and returns the result.
     *
     * @return array{title:?string, description:?string, image_url:?string, site_name:?string, nsfw:bool}|null
     */
    public static function getPreview(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        // Check cache first.
        $row = Database::row('SELECT * FROM link_previews WHERE url = ?', [$url]);
        if ($row && (int) $row['status'] === 'success' && (time() - (int) $row['fetched_at']) < self::CACHE_TTL) {
            return self::formatResult($row);
        }
        // If previously failed, don't re-fetch within an hour.
        if ($row && (int) $row['status'] === 'failed' && (time() - (int) $row['fetched_at']) < 3600) {
            return null;
        }

        return self::fetchAndStore($url);
    }

    /** Force-fetch a URL and store the result. Returns formatted preview or null. */
    public static function fetchAndStore(string $url): ?array
    {
        // SSRF protection: block private/non-routable IPs.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || self::isPrivateHost($host)) {
            self::storeResult($url, null, null, null, null, 'failed', null, 'Private or invalid host');
            return null;
        }

        // Check banned domains (NSFW list).
        if (UrlBanService::isBanned($host) !== null) {
            self::storeResult($url, null, null, null, null, 'no_preview', null, 'Domain is banned');
            return null;
        }

        $html = self::fetchHtml($url);
        if ($html === null) {
            self::storeResult($url, null, null, null, null, 'failed', null, 'Fetch failed');
            return null;
        }

        $og = self::parseOpenGraph($html, $url);

        // Proxy the image if we have one.
        $proxyPath = null;
        if ($og['image'] !== null) {
            $proxyPath = self::proxyImage($og['image']);
        }

        self::storeResult(
            $url,
            $og['title'],
            $og['description'],
            $proxyPath,
            $og['image'],
            'success',
            200,
            null
        );

        return self::formatResult(Database::row('SELECT * FROM link_previews WHERE url = ?', [$url]));
    }

    /**
     * Fetch HTML content from a URL.
     * @return string|null HTML content or null on failure.
     */
    private static function fetchHtml(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_MAXFILESIZE => self::MAX_HTML,
            CURLOPT_USERAGENT => 'LVChat/1.0 LinkPreview',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '', // accept any encoding
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ]);
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $code < 200 || $code >= 400) {
            return null;
        }
        return substr($html, 0, self::MAX_HTML);
    }

    /**
     * Parse OpenGraph and fallback meta tags from HTML.
     * @return array{title:?string, description:?string, image:?string, site_name:?string}
     */
    private static function parseOpenGraph(string $html, string $baseUrl): array
    {
        $result = ['title' => null, 'description' => null, 'image' => null, 'site_name' => null];

        // Extract og:title
        if (preg_match('#<meta\s+(?:[^>]*?\s+)?property=["\']og:title["\'][^>]*?\s+content=["\']([^"\']*)["\']#i', $html, $m)) {
            $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('#<meta\s+(?:[^>]*?\s+)?content=["\']([^"\']*)["\'][^>]*?\s+property=["\']og:title["\']#i', $html, $m)) {
            $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract og:description
        if (preg_match('#<meta\s+(?:[^>]*?\s+)?property=["\']og:description["\'][^>]*?\s+content=["\']([^"\']*)["\']#i', $html, $m)) {
            $result['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('#<meta\s+(?:[^>]*?\s+)?content=["\']([^"\']*)["\'][^>]*?\s+property=["\']og:description["\']#i', $html, $m)) {
            $result['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract og:image
        if (preg_match('#<meta\s+(?:[^>]*?\s+)?property=["\']og:image["\'][^>]*?\s+content=["\']([^"\']*)["\']#i', $html, $m)) {
            $result['image'] = self::resolveUrl($m[1], $baseUrl);
        } elseif (preg_match('#<meta\s+(?:[^>]*?\s+)?content=["\']([^"\']*)["\'][^>]*?\s+property=["\']og:image["\']#i', $html, $m)) {
            $result['image'] = self::resolveUrl($m[1], $baseUrl);
        }

        // Extract og:site_name
        if (preg_match('#<meta\s+(?:[^>]*?\s+)?property=["\']og:site_name["\'][^>]*?\s+content=["\']([^"\']*)["\']#i', $html, $m)) {
            $result['site_name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('#<meta\s+(?:[^>]*?\s+)?content=["\']([^"\']*)["\'][^>]*?\s+property=["\']og:site_name["\']#i', $html, $m)) {
            $result['site_name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Fallbacks: <title>, <meta name="description">, first <img>, hostname.
        if ($result['title'] === null && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $result['title'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        if ($result['description'] === null && preg_match('#<meta\s+(?:[^>]*?\s+)?name=["\']description["\'][^>]*?\s+content=["\']([^"\']*)["\']#i', $html, $m)) {
            $result['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        } elseif ($result['description'] === null && preg_match('#<meta\s+(?:[^>]*?\s+)?content=["\']([^"\']*)["\'][^>]*?\s+name=["\']description["\']#i', $html, $m)) {
            $result['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        if ($result['image'] === null && preg_match('#<img\s+[^>]*?src=["\']([^"\']+)["\']#i', $html, $m)) {
            $candidate = self::resolveUrl($m[1], $baseUrl);
            if ($candidate !== null && preg_match('#\.(jpe?g|png|gif|webp|avif)(?:\?|$)#i', $candidate)) {
                $result['image'] = $candidate;
            }
        }
        if ($result['site_name'] === null) {
            $result['site_name'] = (string) parse_url($baseUrl, PHP_URL_HOST);
        }

        // Trim long fields.
        if ($result['title'] !== null) {
            $result['title'] = mb_substr($result['title'], 0, 200);
        }
        if ($result['description'] !== null) {
            $result['description'] = mb_substr($result['description'], 0, 500);
        }

        return $result;
    }

    /** Resolve a potentially relative URL against a base. */
    private static function resolveUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        // Relative URL — resolve against base.
        $parts = parse_url($baseUrl);
        if (!$parts) {
            return null;
        }
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $base . $dir . $url;
    }

    /** Check if a hostname resolves to a private/non-routable IP. */
    private static function isPrivateHost(string $host): bool
    {
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname failed to resolve.
            return true;
        }
        // Block private ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        // Block localhost variants.
        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.local') || str_ends_with($lower, '.localhost')) {
            return true;
        }
        return false;
    }

    /**
     * Download an image and save it to the proxy directory.
     * Returns the local URL path, or null on failure.
     */
    private static function proxyImage(?string $imageUrl): ?string
    {
        if ($imageUrl === null || $imageUrl === '') {
            return null;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_MAXFILESIZE => self::MAX_IMAGE,
            CURLOPT_USERAGENT => 'LVChat/1.0 LinkPreview',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($data === false || $code < 200 || $code >= 400 || $data === '') {
            return null;
        }

        // Split headers from body.
        $headerLen = strpos($data, "\r\n\r\n");
        if ($headerLen === false) {
            return null;
        }
        $body = substr($data, $headerLen + 4);

        // Validate image MIME type.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($body);
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        // Map MIME to extension.
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
        ];
        $ext = $extMap[$mime] ?? 'jpg';

        // Generate a deterministic filename from the URL hash.
        $hash = hash('sha256', $imageUrl);
        $dir = ROOT . self::PREVIEW_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $hash . '.' . $ext;
        file_put_contents($path, $body);

        return self::PREVIEW_DIR . '/' . $hash . '.' . $ext;
    }

    /** Store a preview result in the database. */
    private static function storeResult(
        string $url,
        ?string $title,
        ?string $description,
        ?string $imageUrl,
        ?string $originalImageUrl,
        string $status,
        ?int $httpStatus,
        ?string $error
    ): void {
        Database::query(
            'INSERT INTO link_previews (url, title, description, image_url, original_image_url, fetched_at, status, http_status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(url) DO UPDATE SET
                title = excluded.title,
                description = excluded.description,
                image_url = excluded.image_url,
                original_image_url = excluded.original_image_url,
                fetched_at = excluded.fetched_at,
                status = excluded.status,
                http_status = excluded.http_status,
                error_message = excluded.error_message',
            [
                $url,
                $title,
                $description,
                $imageUrl,
                $originalImageUrl,
                time(),
                $status,
                $httpStatus,
                $error,
            ]
        );
    }

    /** Format a database row into the preview array structure. */
    private static function formatResult(?array $row): ?array
    {
        if (!$row || (int) $row['status'] !== 'success') {
            return null;
        }
        return [
            'title' => $row['title'] ?: null,
            'description' => $row['description'] ?: null,
            'image_url' => $row['image_url'] ? url((string) $row['image_url']) : null,
            'site_name' => $row['site_name'] ?: null,
            'nsfw' => self::isNsfwUrl((string) $row['url']),
        ];
    }

    /** Check if a URL's domain is in the banned_urls list (NSFW filter). */
    public static function isNsfwUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        return UrlBanService::isBanned($host) !== null;
    }

    /**
     * Get the preview for a message (used by MessageService::present).
     * @return array{title:?string, description:?string, image_url:?string, site_name:?string, nsfw:bool}|null
     */
    public static function getForMessage(int $messageId): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $row = Database::row(
            'SELECT lp.* FROM link_previews lp
             JOIN messages m ON m.id = ?
             WHERE lp.url = m.content AND lp.status = "success"',
            [$messageId]
        );
        if (!$row) {
            return null;
        }
        return self::formatResult($row);
    }

    /**
     * Get preview by URL (for client-side fetching of pending previews).
     * @return array{title:?string, description:?string, image_url:?string, site_name:?string, nsfw:bool, status:string}|null
     */
    public static function getByUrl(string $url): ?array
    {
        $row = Database::row('SELECT * FROM link_previews WHERE url = ?', [$url]);
        if (!$row) {
            return null;
        }
        $result = self::formatResult($row);
        if ($result === null) {
            return ['title' => null, 'description' => null, 'image_url' => null, 'site_name' => null, 'nsfw' => false, 'status' => (string) $row['status']];
        }
        $result['status'] = (string) $row['status'];
        return $result;
    }

    /** Delete previews older than PURGE_AGE and their orphaned images. */
    public static function purgeOld(): int
    {
        $cutoff = time() - self::PURGE_AGE;
        $rows = Database::all('SELECT image_url FROM link_previews WHERE fetched_at < ?', [$cutoff]);
        $deleted = 0;
        foreach ($rows as $row) {
            if (!empty($row['image_url'])) {
                $absPath = ROOT . (string) $row['image_url'];
                if (is_file($absPath)) {
                    @unlink($absPath);
                }
            }
            $deleted++;
        }
        Database::query('DELETE FROM link_previews WHERE fetched_at < ?', [$cutoff]);
        return $deleted;
    }
}

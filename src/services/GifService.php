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
 * Giphy-backed GIF search for the chat composer. The browser never talks to
 * Giphy directly: the API key lives in server_config (Admin → Settings) and
 * every search/trending call is proxied through GET /api/gifs. Posting a GIF
 * stores the media URL as a message of kind 'gif' (content = "url\ntitle"),
 * so the existing FTS index keeps it searchable by caption/title.
 */
final class GifService
{
    private const API_BASE = 'https://api.giphy.com/v1/gifs';
    private const RATING = 'pg-13';
    private const LANG = 'en';
    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT = 8;

    /** Base domains we're willing to embed as a GIF message (any subdomain of
     *  these — Giphy serves media from media.giphy.com, media1-4.giphy.com,
     *  i.giphy.com — is owned by the provider). Add providers here later. */
    private const MEDIA_HOSTS = ['giphy.com', 'tenor.com'];

    /** Is the GIF feature enabled at all? (master switch, default on). */
    public static function enabled(): bool
    {
        return config_get('gifs_enabled', '1') === '1';
    }

    /** True when an admin has configured a Giphy API key. */
    public static function configured(): bool
    {
        return trim((string) (config_get('giphy_api_key', '') ?? '')) !== '';
    }

    /** Search Giphy for a term. Returns normalized items (empty on failure). */
    public static function search(string $q, int $limit = 24, int $offset = 0): array
    {
        $params = array_filter([
            'q' => mb_substr($q, 0, 50),
            'limit' => max(1, min(50, $limit)),
            'offset' => max(0, $offset),
            'rating' => self::RATING,
            'lang' => self::LANG,
        ], fn ($v) => $v !== null && $v !== '');
        return self::request('/search', $params);
    }

    /** Current trending GIFs. Returns normalized items (empty on failure). */
    public static function trending(int $limit = 24, int $offset = 0): array
    {
        $params = [
            'limit' => max(1, min(50, $limit)),
            'offset' => max(0, $offset),
            'rating' => self::RATING,
        ];
        return self::request('/trending', $params);
    }

    /** Hit the Giphy API and normalize `data`. Never throws; returns [] on error. */
    private static function request(string $path, array $params): array
    {
        $key = (string) config_get('giphy_api_key', '');
        if (!$key || !self::enabled()) {
            return [];
        }
        $url = self::API_BASE . $path . '?api_key=' . rawurlencode($key);
        foreach ($params as $k => $v) {
            $url .= '&' . $k . '=' . rawurlencode((string) $v);
        }
        $raw = self::fetch($url);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? self::itemsFrom($json) : [];
    }

    /** Normalize a Giphy search/trending payload into picker items. Pure and
     *  testable: `{id, title, preview, url, page}`. */
    public static function itemsFrom(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['data'] ?? []) as $g) {
            if (!is_array($g)) {
                continue;
            }
            $images = (array) ($g['images'] ?? []);
            $preview = self::imageUrl($images, ['preview_gif', 'fixed_height_small']);
            $url = self::imageUrl($images, ['downsized', 'fixed_width', 'original']);
            $id = (string) ($g['id'] ?? '');
            if ($id === '' || $preview === '' || $url === '') {
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => mb_substr(trim((string) ($g['title'] ?? '')), 0, 300),
                'preview' => $preview,
                'url' => $url,
                'page' => (string) ($g['url'] ?? ''),
                'provider' => 'giphy',
            ];
        }
        return $items;
    }

    /** First rendition in $prefs that resolves to a URL ('' if none). */
    private static function imageUrl(array $images, array $prefs): string
    {
        foreach ($prefs as $p) {
            if (isset($images[$p]['url']) && is_string($images[$p]['url']) && $images[$p]['url'] !== '') {
                return (string) $images[$p]['url'];
            }
        }
        return '';
    }

    /** Is a URL safe to embed as a GIF message? (https + known media host). */
    public static function validMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        foreach (self::MEDIA_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** Best-effort GET that returns the response body or ''. Prefers cURL;
     *  falls back to a bounded stream-context read (shared hosts without cURL). */
    private static function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT => self::READ_TIMEOUT,
                    CURLOPT_USERAGENT => 'LVChat/1.6 (GIF search)',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body = @curl_exec($ch);
                $code = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return is_string($body) && $body !== '' && $code >= 200 && $code < 300 ? $body : '';
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::CONNECT_TIMEOUT,
            'user_agent' => 'LVChat/1.6 (GIF search)',
            'header' => "Accept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
}

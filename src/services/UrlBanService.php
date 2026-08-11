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
 * Global list of channel URLs / domains that may never be used as a Channel
 * URL. Managed from Admin → Blocked URLs; enforced when a channel URL is set
 * and re-checked whenever a channel URL is served to clients (a URL whose
 * domain becomes banned later is simply not shown).
 */
final class UrlBanService
{
    /** Normalize a submitted value to a bare host, or null when it isn't a
     *  usable host. Accepts "example.com", "*.example.com", "https://example.com/path". */
    public static function normalizeHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            $host = (string) parse_url($value, PHP_URL_HOST);
            if ($host !== '') {
                return self::cleanHost($host);
            }
            return null;
        }
        // A bare domain (optionally with a leading *. wildcard), possibly pasted
        // with a path — strip anything after the first '/'.
        $value = strtok($value, '/') ?: $value;
        return self::cleanHost($value);
    }

    private static function cleanHost(string $host): ?string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));
        $host = preg_replace('/^\\*\\./', '', $host) ?? $host;
        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $host)) {
            return null;
        }
        if (strlen($host) > 253) {
            return null;
        }
        return $host;
    }

    /** Does a host match a banned domain exactly, or as a subdomain of one? */
    public static function isBanned(string $host): ?array
    {
        $host = self::cleanHost($host);
        if ($host === null) {
            return null;
        }
        foreach (Database::all('SELECT * FROM banned_urls') as $ban) {
            $domain = self::cleanHost((string) $ban['domain']);
            if ($domain === null) {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $ban;
            }
        }
        return null;
    }

    /** Is the given URL allowed as a channel URL (scheme + host + ban check)? */
    public static function urlAllowed(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return ['error' => 'The channel URL must start with http:// or https://.'];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/i', $host)) {
            return ['error' => 'That channel URL has an invalid host.'];
        }
        $ban = self::isBanned($host);
        if ($ban) {
            return ['error' => 'That domain is banned (' . ($ban['reason'] ?: 'no reason given') . ').'];
        }
        return ['ok' => true];
    }

    public static function all(): array
    {
        return Database::all(
            'SELECT b.*, s.username AS set_by_name FROM banned_urls b
             LEFT JOIN users s ON s.id = b.created_by
             ORDER BY b.created_at DESC'
        );
    }

    /** Add a domain to the banned list. Returns an error string or null. */
    public static function add(string $domain, string $reason, int $createdBy): ?string
    {
        $host = self::normalizeHost($domain);
        if ($host === null) {
            return 'Enter a valid domain, e.g. example.com';
        }
        $existing = Database::row('SELECT 1 FROM banned_urls WHERE domain = ? COLLATE NOCASE', [$host]);
        if ($existing) {
            return 'That domain is already banned.';
        }
        Database::query(
            'INSERT INTO banned_urls (domain, reason, created_by) VALUES (?, ?, ?)',
            [$host, mb_substr($reason, 0, 300), $createdBy]
        );
        log_audit('banned_url_add', $host, $reason ?: 'no reason');
        return null;
    }

    public static function remove(int $id): void
    {
        Database::query('DELETE FROM banned_urls WHERE id = ?', [$id]);
        log_audit('banned_url_remove', 'banned_url#' . $id);
    }
}

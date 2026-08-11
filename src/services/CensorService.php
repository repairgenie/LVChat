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
 * Admin-managed bad-word filter.
 *
 * Each bad word has an action:
 *   - 'censor': matching words are replaced with ****
 *   - 'block':  the whole message is removed and replaced by a ChanServ notice
 *
 * The filter runs on channel messages only when the channel has mode +C set,
 * and always on private messages.
 */
final class CensorService
{
    public static function activeBadWords(): array
    {
        return Database::all('SELECT * FROM badwords WHERE enabled = 1');
    }

    public static function isChannelFiltered(array $channel): bool
    {
        return (int) ($channel['censor'] ?? 0) === 1;
    }

    /**
     * Check content against the filter.
     * Returns ['word' => string, 'action' => 'censor'|'block', 'censored' => string] or null.
     */
    public static function check(string $content, bool $apply): ?array
    {
        if (!$apply || $content === '') {
            return null;
        }
        foreach (self::activeBadWords() as $bw) {
            $word = trim((string) $bw['word']);
            $pattern = self::wordPattern($word);
            if ($pattern === null) {
                continue;
            }
            if (preg_match($pattern, $content)) {
                if (($bw['action'] ?? 'censor') === 'block') {
                    return ['word' => $word, 'action' => 'block', 'censored' => $content];
                }
                return [
                    'word' => $word,
                    'action' => 'censor',
                    'censored' => preg_replace($pattern, '****', $content),
                ];
            }
        }
        return null;
    }

    /**
     * Build the match pattern for a stored bad word.
     *
     * A leading '*' drops the left word boundary, a trailing '*' drops the right
     * one, so e.g. "nigger*" matches "niggers" and "*nigger*" matches both
     * "niggers" and "fatnigger". Plain words keep full word boundaries.
     */
    private static function wordPattern(string $word): ?string
    {
        if ($word === '') {
            return null;
        }
        $leadingStar = str_starts_with($word, '*');
        $trailingStar = str_ends_with($word, '*');
        $core = trim($word, '*');
        if ($core === '') {
            return null;
        }
        $left = $leadingStar ? '' : '(?<![a-z0-9_])';
        $right = $trailingStar ? '' : '(?![a-z0-9_])';
        return '/' . $left . preg_quote($core, '/') . $right . '/i';
    }
}

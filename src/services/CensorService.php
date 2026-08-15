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
        // Normalize the content to defeat common filter-evasion tricks:
        // strip zero-width characters, collapse repeated chars, and
        // transliterate Cyrillic homoglyphs to their Latin equivalents.
        $normalized = self::normalize($content);
        foreach (self::activeBadWords() as $bw) {
            $word = trim((string) $bw['word']);
            $pattern = self::wordPattern($word);
            if ($pattern === null) {
                continue;
            }
            if (preg_match($pattern, $normalized)) {
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
     * Normalize content for filter matching.
     * - Strip zero-width joiners, non-joiners, soft hyphens, etc.
     * - Transliterate Cyrillic homoglyphs to Latin.
     * - Collapse repeated characters (e.g. "fuuuuck" -> "fuuck").
     */
    private static function normalize(string $text): string
    {
        // Strip zero-width and invisible Unicode characters.
        $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}\x{180E}]/u', '', $text);
        // Fullwidth Latin (U+FF01-U+FF5E) → ASCII (U+0021-U+007E).
        // e.g. ｆｕｃｋ → fuck
        $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', function ($m) {
            return mb_chr(mb_ord($m[0]) - 0xFEE0, 'UTF-8');
        }, $text);
        // Cyrillic → Latin homoglyphs (common evasion characters).
        $homoglyphs = [
            'а' => 'a', 'А' => 'a', // Cyrillic а
            'е' => 'e', 'Е' => 'e', // Cyrillic е
            'о' => 'o', 'О' => 'o', // Cyrillic о
            'р' => 'p', 'Р' => 'p', // Cyrillic р
            'с' => 'c', 'С' => 'c', // Cyrillic с
            'у' => 'y', 'У' => 'y', // Cyrillic у
            'х' => 'x', 'Х' => 'x', // Cyrillic х
            'ᴀ' => 'a', // Small capital A
            'ʙ' => 'b', // Small capital B
            'ᴄ' => 'c', // Small capital C
            'ᴅ' => 'd', // Small capital D
            'ᴇ' => 'e', // Small capital E
            'ꜰ' => 'f', // Small capital F
            'ɢ' => 'g', // Small capital G
            'ʜ' => 'h', // Small capital H
            'ɪ' => 'i', // Small capital I
            'ᴊ' => 'j', // Small capital J
            'ᴋ' => 'k', // Small capital K
            'ʟ' => 'l', // Small capital L
            'ᴍ' => 'm', // Small capital M
            'ɴ' => 'n', // Small capital N
            'ᴏ' => 'o', // Small capital O
            'ᴘ' => 'p', // Small capital P
            'ǫ' => 'q', // Small capital Q
            'ʀ' => 'r', // Small capital R
            'ꜱ' => 's', // Small capital S
            'ᴛ' => 't', // Small capital T
            'ᴜ' => 'u', // Small capital U
            'ᴠ' => 'v', // Small capital V
            'ᴡ' => 'w', // Small capital W
            'ʏ' => 'y', // Small capital Y
            'ᴢ' => 'z', // Small capital Z
        ];
        $text = strtr($text, $homoglyphs);
        // Collapse 3+ repeated characters to 2 (e.g. "fuuuuck" → "fuuck").
        $text = preg_replace('/(.)\1{2,}/u', '$1$1', $text);
        return $text;
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

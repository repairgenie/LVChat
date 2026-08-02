<?php

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
            if ($word === '') {
                continue;
            }
            $pattern = '/(?<![a-z0-9_])' . preg_quote($word, '/') . '(?![a-z0-9_])/i';
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
}

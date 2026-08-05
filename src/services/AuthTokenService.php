<?php

declare(strict_types=1);

/**
 * One-time auth tokens: password resets and magic-link logins. A token is a
 * 64-char hex string stored in `auth_tokens` with a short TTL; it is claimed
 * (used_at set) on first successful use and can never be reused.
 *
 * Registered accounts only — guests have no email address, so no token can
 * ever be issued for them.
 */
final class AuthTokenService
{
    /** Password-reset links are valid for this many minutes. */
    public const RESET_TTL_MINUTES = 15;

    /** Magic-link logins are valid for this many minutes. */
    public const MAGIC_TTL_MINUTES = 10;

    public const TYPE_RESET = 'reset';
    public const TYPE_MAGIC = 'magic_link';

    /** Create a token for a user and return the raw (unhashed) token string. */
    public static function create(int $userId, string $type, int $ttlMinutes): string
    {
        $token = bin2hex(random_bytes(32));
        Database::query(
            'INSERT INTO auth_tokens (user_id, token, type, expires_at)
             VALUES (?, ?, ?, datetime("now", "+' . (int) $ttlMinutes . ' minutes"))',
            [$userId, $token, $type]
        );
        return $token;
    }

    /** Return the token row joined with its user when the token is unused,
     *  unexpired, and of the expected type — else null. */
    public static function validate(string $token, string $type): ?array
    {
        $token = trim($token);
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }
        $row = Database::row(
            'SELECT t.id AS token_id, t.type, t.expires_at, u.*
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND t.type = ? AND t.used_at IS NULL AND t.expires_at > datetime("now")',
            [$token, $type]
        );
        return $row ?: null;
    }

    /** Mark a token used so it can never be replayed. */
    public static function claim(int $tokenId): void
    {
        Database::query(
            'UPDATE auth_tokens SET used_at = datetime("now") WHERE id = ? AND used_at IS NULL',
            [$tokenId]
        );
    }

    /** Delete every unused token of a type for a user (e.g. after a reset or
     *  magic login, any other outstanding links for that user die too). */
    public static function invalidateAllForUser(int $userId, string $type): void
    {
        Database::query(
            'DELETE FROM auth_tokens WHERE user_id = ? AND type = ? AND used_at IS NULL',
            [$userId, $type]
        );
    }

    public static function resetLink(string $token): string
    {
        return base_url() . '/reset-password/' . rawurlencode($token);
    }

    public static function magicLink(string $token): string
    {
        return base_url() . '/magic/' . rawurlencode($token);
    }
}

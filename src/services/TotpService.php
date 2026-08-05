<?php

declare(strict_types=1);

/**
 * TOTP (RFC 6238) multi-factor authentication. Dependency-free: HMAC-SHA1 via
 * hash_hmac(), 160-bit base32 secrets, 30-second window, 6-digit codes.
 *
 * A secret only becomes active once the user proves they can generate a valid
 * code (`totp_enabled_at` set) — this prevents locking accounts to a secret
 * the authenticator never received.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a new 160-bit secret, base32-encoded (32 chars, no padding). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $out = '';
        $acc = 0;
        $bits = 0;
        foreach (str_split($bin) as $ch) {
            $acc = ($acc << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::BASE32[($acc >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::BASE32[($acc << (5 - $bits)) & 31];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $b32));
        static $map = null;
        if ($map === null) {
            $map = array_flip(str_split(self::BASE32));
        }
        $out = '';
        $acc = 0;
        $bits = 0;
        foreach (str_split($b32) as $ch) {
            if (!isset($map[$ch])) {
                continue;
            }
            $acc = ($acc << 5) | $map[$ch];
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($acc >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }

    /** The 6-digit code for a secret at a given time (defaults to now). */
    public static function code(string $secretB32, ?int $timestamp = null): string
    {
        $secret = self::base32Decode($secretB32);
        if ($secret === '') {
            return '';
        }
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        // 64-bit big-endian counter per RFC 4226.
        $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $bin, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Verify a user-supplied code, tolerating ±$window 30-second steps of clock drift. */
    public static function verify(string $secretB32, string $code, int $window = 1): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secretB32, $now + $i * self::PERIOD), $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// URI understood by every mainstream authenticator app. */
    public static function otpauthUri(string $secretB32, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secretB32
            . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    /** Secret grouped in 4-char chunks for manual entry. */
    public static function formatSecret(string $secretB32): string
    {
        return trim(chunk_split($secretB32, 4, ' '));
    }

    /** True when the user has completed MFA enrollment. */
    public static function enabled(array $user): bool
    {
        return !empty($user['totp_secret']) && !empty($user['totp_enabled_at']);
    }

    /** True when the admin requires MFA for this user's account class (role). */
    public static function requiredFor(array $user): bool
    {
        if ((int) ($user['guest'] ?? 0) === 1) {
            return false;
        }
        $role = (string) ($user['role'] ?? 'user');
        if (!in_array($role, ['user', 'staff', 'admin'], true)) {
            $role = 'user';
        }
        return config_get('mfa_require_' . $role, '0') === '1';
    }

    public static function enable(int $userId, string $secretB32): void
    {
        Database::query(
            'UPDATE users SET totp_secret = ?, totp_enabled_at = datetime("now") WHERE id = ?',
            [$secretB32, $userId]
        );
    }

    public static function disable(int $userId): void
    {
        Database::query('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL WHERE id = ?', [$userId]);
    }
}

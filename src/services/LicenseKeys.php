<?php

declare(strict_types=1);

/**
 * LicenseKeys — the offline LVChat license key algorithm (v1).
 *
 * Key format:  LVC-1-<moduleId>-<payloadB32>-<signatureB32>
 *
 *   - `1`            scheme version
 *   - `<moduleId>`   lowercase [a-z0-9-_] module id the key is bound to
 *   - `<payloadB32>` RFC 4648 base32 (uppercase, no padding) of the canonical
 *                    claims JSON bytes
 *   - `<signatureB32>` base32 of sodium_crypto_sign_detached() over the raw
 *                    payload bytes (a 64-byte Ed25519 signature)
 *
 * Canonical claims JSON (field order fixed; sign the exact bytes):
 *   {"v":1,"mod":"<id>","type":"<edition>","holder":"<customer>",
 *    "exp":"YYYY-MM-DD"|"","act":<max activations|0=unlimited>,"iss":"YYYY-MM-DD"}
 *
 * Verification is fully offline and requires no network: format parse -> base32
 * decode -> Ed25519 verify against the vendor public key -> claim checks (module
 * match, expiry). Keys are unforgeable without the vendor's private key, so the
 * internal check is the first line of defence; the licensing server then
 * confirms the key exists and is still active (docs/protocol/licensing.md).
 *
 * The vendor public key is embedded below (32 bytes, base64). Generate a
 * keypair and fetch the public half with the license server's bin/gen-seed.php,
 * then paste the output here. An LVC_LICENSE_PUBLIC_KEY env var overrides it
 * (used by the automated test suites and the license server).
 */
final class LicenseKeys
{
    public const VERSION = 1;
    public const PREFIX = 'LVC';

    /** Vendor Ed25519 public key (base64, 32 bytes). */
    public const PUBLIC_KEY = '';

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function publicKey(): string
    {
        $env = getenv('LVC_LICENSE_PUBLIC_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return self::PUBLIC_KEY;
    }

    /** Mint a key. $claims: mod, type, holder, exp ("YYYY-MM-DD"|""), act, iss.
     *  The Ed25519 secret key is a 64-byte sodium secret key (see
     *  sodium_crypto_sign_keypair()). Used by tests and the license server. */
    public static function generate(array $claims, string $secretKey): string
    {
        $claims = array_merge(['v' => self::VERSION], $claims);
        // A per-key nonce keeps every key unique even when the other claims are
        // identical (Ed25519 signing is deterministic, so without it a bulk
        // batch of identical claims would produce byte-for-byte the same key).
        $claims['n'] = bin2hex(random_bytes(6));
        $payload = json_encode(self::canonicalize($claims), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new InvalidArgumentException('Could not encode license claims.');
        }
        $sig = sodium_crypto_sign_detached($payload, $secretKey);
        return implode('-', [
            self::PREFIX,
            self::VERSION,
            (string) ($claims['mod'] ?? ''),
            self::b32encode($payload),
            self::b32encode($sig),
        ]);
    }

    /**
     * Offline validation. Returns ['ok'=>bool, 'reason'=>string, 'claims'=>array].
     * Reasons: malformed | unsupported_version | bad_signature | wrong_module |
     * expired | ok.
     */
    public static function verify(string $key, string $module): array
    {
        $fail = static fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'claims' => []];

        if (!preg_match('#^LVC-(\d+)-([a-z0-9][a-z0-9\-_]*)-([A-Z2-7]+)-([A-Z2-7]+)$#', trim($key), $m)) {
            return $fail('malformed');
        }
        if ((int) $m[1] !== self::VERSION) {
            return $fail('unsupported_version');
        }
        $payload = self::b32decode($m[3]);
        $sig = self::b32decode($m[4]);
        if ($payload === null || $sig === null) {
            return $fail('malformed');
        }
        $pk = base64_decode(self::publicKey(), true);
        if (!is_string($pk) || strlen($pk) !== 32 || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return $fail('bad_signature');
        }
        if (!sodium_crypto_sign_verify_detached($sig, $payload, $pk)) {
            return $fail('bad_signature');
        }
        $claims = json_decode($payload, true);
        if (!is_array($claims) || (int) ($claims['v'] ?? 0) !== self::VERSION) {
            return $fail('malformed');
        }
        if ((string) ($claims['mod'] ?? '') !== $module) {
            return $fail('wrong_module');
        }
        $exp = (string) ($claims['exp'] ?? '');
        if ($exp !== '' && !preg_match('#^\d{4}-\d{2}-\d{2}$#', $exp)) {
            return $fail('malformed');
        }
        if ($exp !== '' && $exp < gmdate('Y-m-d')) {
            return $fail('expired');
        }
        return ['ok' => true, 'reason' => 'ok', 'claims' => $claims];
    }

    /** RFC 4648 base32, uppercase, no padding. */
    public static function b32encode(string $data): string
    {
        $out = '';
        $bits = 0;
        $val = 0;
        foreach (str_split($data) as $ch) {
            $val = ($val << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32_ALPHABET[($val >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($val << (5 - $bits)) & 31];
        }
        return $out;
    }

    /** Decode unpadded base32; returns null on any invalid character. */
    public static function b32decode(string $s): ?string
    {
        $out = '';
        $bits = 0;
        $val = 0;
        foreach (str_split($s) as $ch) {
            $idx = strpos(self::B32_ALPHABET, $ch);
            if ($idx === false) {
                return null;
            }
            $val = ($val << 5) | $idx;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($val >> $bits) & 0xff);
            }
        }
        return $out;
    }

    /** Reorder claims into the canonical field sequence for signing. */
    private static function canonicalize(array $claims): array
    {
        $out = [];
        foreach (['v', 'mod', 'type', 'holder', 'exp', 'act', 'iss', 'n'] as $k) {
            if (array_key_exists($k, $claims)) {
                $out[$k] = $claims[$k];
            }
        }
        return $out;
    }
}

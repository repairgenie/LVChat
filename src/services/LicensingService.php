<?php

declare(strict_types=1);

/**
 * LicensingService — the chat app's client half of the LVChat licensing system
 * (docs/protocol/licensing.md).
 *
 * Validation is a two-layer stack:
 *   1. INTERNAL (always, offline): LicenseKeys::verify() checks the Ed25519
 *      signature and the claims (module match, expiry). A malformed, unsigned,
 *      or expired key is rejected with zero network traffic.
 *   2. EXTERNAL (best effort): the configured license server is asked to confirm
 *      the key still exists and is active, registering this install's server_id
 *      against it (seat accounting). Results are cached per module.
 *
 * When the license server is unreachable the behavior follows the policy:
 *   - grace   (default)  last-known-good 'valid' keeps working; a key that has
 *                        never been confirmed gets a license_grace_days window.
 *   - strict             the module refuses to run.
 *   - offline            internal check only, never dial out.
 *
 * Config keys (Admin → Settings → Licensing): license_url, license_policy,
 * license_grace_days, license_recheck_hours.
 */
final class LicensingService
{
    /** Statuses that must stop a paid module from running. */
    public const INVALID_STATUSES = [
        'no_key',
        'malformed',
        'unsupported_version',
        'bad_signature',
        'wrong_module',
        'expired',
        'server_refused',
        'unreachable_strict',
        'unreachable_grace',
    ];

    /** Validate a module's license key. Returns ['status'=>string,'ok'=>bool,...].
     *  StoreStatus writes license_status/license_expires_at/license_checked_at
     *  on the module row, which drives ModuleLoader::isLicensed() and the
     *  Admin → Modules status badge. */
    public static function validate(string $module, string $key, array $manifest = []): array
    {
        $key = trim($key);
        if ($key === '') {
            self::storeStatus($module, 'no_key', null);
            return ['status' => 'no_key', 'ok' => false];
        }

        // 1. Internal algorithm — offline, no network. The module field in the
        //    signed claims must match the module being loaded.
        $offline = LicenseKeys::verify($key, $module);
        if (!$offline['ok']) {
            self::storeStatus($module, $offline['reason'], null);
            return ['status' => $offline['reason'], 'ok' => false];
        }
        $exp = (string) ($offline['claims']['exp'] ?? '');
        $expiresAt = $exp !== '' ? $exp : null;

        $policy = self::policy();

        // 2. Offline-only policy: the internal check is the whole gate.
        if ($policy === 'offline') {
            self::storeStatus($module, 'valid', $expiresAt);
            return ['status' => 'valid', 'ok' => true, 'offline' => true];
        }

        $row = Database::row('SELECT license_status, license_checked_at, license_expires_at FROM modules WHERE id = ?', [$module]);

        // 3. A fresh confirmed result short-circuits the network call.
        $recheckH = max(1, (int) (config_get('license_recheck_hours', '24') ?? 24));
        if ($row && $row['license_status'] === 'valid' && $row['license_checked_at']
            && (time() - (int) strtotime($row['license_checked_at'])) < $recheckH * 3600) {
            return ['status' => 'valid', 'ok' => true, 'cached' => true];
        }

        $url = rtrim((string) (config_get('license_url') ?? ''), '/');

        // 4. No license server configured: the internal check is the gate.
        if ($url === '') {
            self::storeStatus($module, 'valid', $expiresAt);
            return ['status' => 'valid', 'ok' => true, 'offline' => true];
        }

        // 5. A never-confirmed key already inside its grace window doesn't dial
        //    out again on every request — it keeps running until grace runs out.
        $graceDays = max(0, (int) (config_get('license_grace_days', '7') ?? 7));
        if ($row && $row['license_status'] === 'unvalidated' && $row['license_checked_at']) {
            if ((time() - (int) strtotime($row['license_checked_at'])) > $graceDays * 86400) {
                self::storeStatus($module, 'unreachable_grace', null);
                return ['status' => 'unreachable_grace', 'ok' => false];
            }
            return ['status' => 'valid', 'ok' => true, 'grace' => true];
        }

        // 6. External validation against the license server.
        $result = self::serverValidate($url, $module, $key, self::serverId());
        if ($result['reachable'] && $result['ok']) {
            self::storeStatus($module, 'valid', $result['expires_at'] ?: $expiresAt);
            return ['status' => 'valid', 'ok' => true, 'server' => true];
        }
        if ($result['reachable']) {
            self::storeStatus($module, 'server_refused', null);
            return ['status' => 'server_refused', 'ok' => false, 'reason' => $result['reason']];
        }

        // 7. License server unreachable -> policy.
        if ($policy === 'strict') {
            self::storeStatus($module, 'unreachable_strict', null);
            return ['status' => 'unreachable_strict', 'ok' => false];
        }
        if ($row && $row['license_status'] === 'valid') {
            return ['status' => 'valid', 'ok' => true, 'grace' => true]; // last known good
        }
        if ($row === null || $row['license_status'] === '') {
            if ($graceDays <= 0) {
                self::storeStatus($module, 'unreachable_grace', null);
                return ['status' => 'unreachable_grace', 'ok' => false];
            }
            self::storeStatus($module, 'unvalidated', null); // start the grace clock
            return ['status' => 'valid', 'ok' => true, 'grace' => true];
        }
        self::storeStatus($module, 'unreachable_grace', null);
        return ['status' => 'unreachable_grace', 'ok' => false];
    }

    public static function policy(): string
    {
        $p = (string) (config_get('license_policy', 'grace') ?? 'grace');
        return in_array($p, ['grace', 'strict', 'offline'], true) ? $p : 'grace';
    }

    /** Stable per-install fingerprint used to bind a license seat. Generated
     *  once into server_config so it survives redeploys of data/. */
    public static function serverId(): string
    {
        $id = (string) (config_get('license_server_id', '') ?? '');
        if ($id === '') {
            $seed = (string) (config_get('site_name', 'LVChat') ?? 'LVChat')
                . '|' . (getenv('CHAT_DB') ?: ROOT . '/data/chat.db')
                . '|' . bin2hex(random_bytes(8));
            $id = 'srv_' . substr(hash('sha256', $seed), 0, 24);
            config_set('license_server_id', $id);
        }
        return $id;
    }

    /** POST the key to the license server for existence/activity confirmation.
     *  @return array{reachable:bool, ok:bool, expires_at:?string, reason:?string} */
    private static function serverValidate(string $url, string $module, string $key, string $serverId): array
    {
        $body = json_encode(['module' => $module, 'key' => $key, 'server_id' => $serverId], JSON_UNESCAPED_SLASHES);
        $endpoint = $url . '/api/licenses/validate';
        $timeout = 8;
        $ua = 'LVChat/' . LVC_VERSION;

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => $ua,
            ]);
            $raw = curl_exec($ch);
            $ok = is_string($raw) && curl_errno($ch) === 0;
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $body,
                'timeout' => $timeout,
                'user_agent' => $ua,
            ]]);
            $raw = @file_get_contents($endpoint, false, $ctx);
            $ok = is_string($raw);
        }

        if (!$ok) {
            return ['reachable' => false, 'ok' => false, 'expires_at' => null, 'reason' => 'unreachable'];
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['reachable' => false, 'ok' => false, 'expires_at' => null, 'reason' => 'bad_response'];
        }
        if (($data['ok'] ?? false) === true) {
            $exp = $data['expires_at'] ?? null;
            return [
                'reachable' => true,
                'ok' => true,
                'expires_at' => is_string($exp) && $exp !== '' ? $exp : null,
                'reason' => null,
            ];
        }
        return ['reachable' => true, 'ok' => false, 'expires_at' => null, 'reason' => (string) ($data['reason'] ?? 'refused')];
    }

    private static function storeStatus(string $module, string $status, ?string $expiresAt): void
    {
        Database::query(
            'UPDATE modules SET license_status = ?, license_expires_at = ?, license_checked_at = datetime("now") WHERE id = ?',
            [$status, $expiresAt, $module]
        );
    }
}

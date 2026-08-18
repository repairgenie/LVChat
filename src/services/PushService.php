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
 * Browser push notifications (Web Push), dependency-free.
 *
 * Delivers real OS/browser notifications through the service worker for:
 *   - channel messages   (respects the per-channel notification mode and the
 *                         user's per-user mutes + global channel toggle)
 *   - DMs                (respects per-user mutes + global DM toggle)
 *   - channel invites    (respects per-user mutes + global invite toggle)
 *
 * Everything the protocol needs is hand-rolled on the built-in openssl/curl
 * extensions (matching the project's dependency-free philosophy):
 *   - VAPID P-256 keypair (auto-generated once into server_config)
 *   - ES256 JWT signing with DER -> raw R||S conversion
 *   - RFC 8291 aes128gcm payload encryption (ECDH + HKDF + AES-128-GCM)
 *   - parallel fan-out via curl_multi, pruning 404/410 (dead) endpoints
 *
 * Sending is best-effort and never throws: if a push service is unreachable
 * the chat keeps working exactly as before.
 */
final class PushService
{
    private const MAX_SUBS_PER_SEND = 400;
    private const TTL = 86400; // seconds — how long a push service may hold a message

    // ── VAPID keys ────────────────────────────────────────────────────────────

    /** Auto-provision the site's VAPID keypair (public = base64url uncompressed point). */
    public static function ensureVapidKeys(): void
    {
        if (config_get('push_vapid_public', '') !== '' && config_get('push_vapid_private', '') !== '') {
            return;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key) {
            return;
        }
        $details = openssl_pkey_get_details($key);
        $pubRaw = self::rawPoint($details['key'] ?? '');
        if ($pubRaw === '') {
            return;
        }
        $pem = '';
        openssl_pkey_export($key, $pem);
        if ($pem === '') {
            return;
        }
        config_set('push_vapid_public', self::b64uEncode($pubRaw));
        config_set('push_vapid_private', $pem);
    }

    /** The VAPID public key served to clients for pushManager.subscribe(). */
    public static function publicKey(): string
    {
        self::ensureVapidKeys();
        return (string) (config_get('push_vapid_public', '') ?? '');
    }

    // ── Preferences (global per-context on/off switches — push only) ─────────

    /** @return array{channels:int,dms:int,invites:int} default all on. */
    public static function prefs(array $user): array
    {
        if (Auth::isGuest($user)) {
            return ['channels' => 1, 'dms' => 1, 'invites' => 1];
        }
        $row = Database::row('SELECT channels, dms, invites FROM user_push_prefs WHERE user_id = ?', [$user['id']]);
        if (!$row) {
            return ['channels' => 1, 'dms' => 1, 'invites' => 1];
        }
        return [
            'channels' => (int) $row['channels'],
            'dms' => (int) $row['dms'],
            'invites' => (int) $row['invites'],
        ];
    }

    public static function savePrefs(array $user, int $channels, int $dms, int $invites): void
    {
        if (Auth::isGuest($user)) {
            return;
        }
        Database::query(
            'INSERT INTO user_push_prefs (user_id, channels, dms, invites) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET
               channels = excluded.channels,
               dms = excluded.dms,
               invites = excluded.invites',
            [(int) $user['id'], $channels ? 1 : 0, $dms ? 1 : 0, $invites ? 1 : 0]
        );
    }

    // ── Per-user mutes (all notification surfaces) ────────────────────────────

    /** Is $senderId muted by $userId? (null sender = a guest; mutes never apply.) */
    public static function isMuted(int $userId, ?int $senderId): bool
    {
        if ($senderId === null || $senderId === $userId) {
            return false;
        }
        return (bool) Database::scalar(
            'SELECT 1 FROM user_mutes WHERE user_id = ? AND muted_user_id = ?',
            [$userId, $senderId]
        );
    }

    public static function addMute(array $user, int $targetUserId): bool|string
    {
        if (Auth::isGuest($user)) {
            return 'Guests cannot manage mute settings.';
        }
        if ($targetUserId === (int) $user['id']) {
            return 'You cannot mute yourself.';
        }
        if (!Database::scalar('SELECT 1 FROM users WHERE id = ?', [$targetUserId])) {
            return 'No such user.';
        }
        Database::query(
            'INSERT OR IGNORE INTO user_mutes (user_id, muted_user_id) VALUES (?, ?)',
            [(int) $user['id'], $targetUserId]
        );
        return true;
    }

    public static function removeMute(array $user, int $targetUserId): void
    {
        if (Auth::isGuest($user)) {
            return;
        }
        Database::query(
            'DELETE FROM user_mutes WHERE user_id = ? AND muted_user_id = ?',
            [(int) $user['id'], $targetUserId]
        );
    }

    /** @return array<int, array{muted_user_id:int, username:string}> */
    public static function mutedList(array $user): array
    {
        if (Auth::isGuest($user)) {
            return [];
        }
        return Database::all(
            'SELECT m.muted_user_id, u.username FROM user_mutes m
             JOIN users u ON u.id = m.muted_user_id
             WHERE m.user_id = ? ORDER BY u.username COLLATE NOCASE',
            [(int) $user['id']]
        );
    }

    // ── Subscriptions ─────────────────────────────────────────────────────────

    /** Store (or refresh) one browser's push subscription for a user. */
    public static function subscribe(array $user, string $endpoint, string $p256dh, string $auth): bool|string
    {
        if (Auth::isGuest($user)) {
            return 'Guests cannot subscribe to push notifications.';
        }
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || strlen($endpoint) > 500) {
            return 'Invalid push endpoint.';
        }
        // Per-user subscription cap: unlimited endpoints would let one account
        // register a battery of slow/hanging HTTPS hosts that every send to them
        // synchronously awaits (fan-out latency DoS) and grow unbounded rows.
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?',
            [(int) $user['id']]
        );
        if ($count >= 10) {
            return 'Too many push subscriptions for this account (max 10).';
        }
        $p256 = self::b64uDecode($p256dh);
        $a = self::b64uDecode($auth);
        if (strlen($p256) !== 65) {
            return 'Invalid p256dh key.';
        }
        if (strlen($a) !== 16) {
            return 'Invalid auth secret.';
        }
        Database::query(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, last_seen)
             VALUES (?, ?, ?, ?, datetime("now"))
             ON CONFLICT(endpoint) DO UPDATE SET
               user_id = excluded.user_id,
               p256dh = excluded.p256dh,
               auth = excluded.auth,
               last_seen = excluded.last_seen',
            [(int) $user['id'], $endpoint, self::b64uEncode($p256), self::b64uEncode($a)]
        );
        return true;
    }

    /** Remove every subscription row for a user (browser-side unsubscribe too). */
    public static function unsubscribe(array $user): void
    {
        if (Auth::isGuest($user)) {
            return;
        }
        Database::query('DELETE FROM push_subscriptions WHERE user_id = ?', [(int) $user['id']]);
    }

    // ── Pure decision helpers (unit-testable, no DB/network) ─────────────────

    /** Should a channel message reach this user? */
    public static function channelDecision(int $pushOn, bool $muted, string $notifyMode, string $content, string $username): bool
    {
        if ($pushOn !== 1) {
            return false;
        }
        if ($muted) {
            return false;
        }
        if ($notifyMode === 'muted') {
            return false;
        }
        if ($notifyMode === 'mentions') {
            return self::mentioned($content, $username);
        }
        return true;
    }

    /** Should a DM reach this user? */
    public static function dmDecision(int $dmsPref, bool $muted): bool
    {
        return $dmsPref === 1 && !$muted;
    }

    /** Should a channel invite reach this user? */
    public static function inviteDecision(int $invitesPref, bool $muted): bool
    {
        return $invitesPref === 1 && !$muted;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /** Push a newly persisted channel message to every eligible member. */
    public static function channelMessage(array $msg): void
    {
        $kind = (string) ($msg['kind'] ?? 'message');
        if (in_array($kind, MessageService::SYSTEM_KINDS, true)) {
            return;
        }
        $channelId = (int) ($msg['channel_id'] ?? 0);
        if ($channelId < 1) {
            return;
        }
        $senderId = isset($msg['sender_id']) ? (int) $msg['sender_id'] : null;
        $msgId = (int) ($msg['id'] ?? 0);
        $slug = (string) ($msg['channel_slug'] ?? '');
        $content = (string) ($msg['content'] ?? '');
        $senderName = (string) ($msg['username'] ?? '');

        $rows = Database::all(
            'SELECT cm.user_id, u.username, u.status_mode,
                    COALESCE(cn.mode, "all") AS notify_mode,
                    COALESCE(pp.channels, 1) AS push_on,
                    (SELECT 1 FROM user_mutes um
                     WHERE um.user_id = cm.user_id AND um.muted_user_id = ?) AS muted
             FROM channel_members cm
             JOIN users u ON u.id = cm.user_id
             LEFT JOIN channel_notify cn ON cn.channel_id = cm.channel_id AND cn.user_id = cm.user_id
             LEFT JOIN user_push_prefs pp ON pp.user_id = cm.user_id
             WHERE cm.channel_id = ? AND cm.user_id IS NOT NULL'
                . ($senderId !== null ? ' AND cm.user_id != ?' : ''),
            $senderId !== null ? [$senderId, $channelId, $senderId] : [null, $channelId]
        );
        if (!$rows) {
            return;
        }
        // Partition subscriptions by payload variant so per-user preferences
        // (quiet hours, content previews, highlight keywords) each get the
        // right title/body and urgency — one payload never fits all.
        $groups = [];
        foreach ($rows as $r) {
            // Do Not Disturb silences push for channel messages too.
            if ((string) ($r['status_mode'] ?? '') === 'dnd') {
                continue;
            }
            $uid = (int) $r['user_id'];
            if (!self::channelDecision(
                (int) $r['push_on'],
                !empty($r['muted']),
                (string) $r['notify_mode'],
                $content,
                (string) $r['username']
            )) {
                continue;
            }
            $prefs = NotifyPrefs::get(['id' => $uid]);
            if (!(int) ($prefs['os_master'] ?? 1)) {
                continue;
            }
            if (NotifyPrefs::quietHoursActive($prefs)) {
                continue;
            }
            $highlight = self::mentioned($content, (string) $r['username'])
                || NotifyPrefs::matchesKeywords($prefs, $content);
            $urgent = $highlight ? 'high' : 'normal';
            $prev = (int) ($prefs['previews'] ?? 1) === 1;
            $key = $urgent . '|' . ($prev ? '1' : '0');
            $groups[$key] ??= ['urgency' => $urgent, 'previews' => $prev, 'uids' => []];
            $groups[$key]['uids'][] = $uid;
        }
        if (!$groups) {
            return;
        }
        foreach ($groups as $g) {
            $subs = self::subscriptionsFor($g['uids']);
            if (!$subs) {
                continue;
            }
            $body = $g['previews']
                ? self::excerpt($content, $kind)
                : 'New message in #' . ($slug !== '' ? $slug : 'a channel');
            $payload = [
                'type' => 'channel',
                'title' => ($slug !== '' ? '#' . $slug : 'Channel') . ' · ' . $senderName,
                'body' => $body,
                'tag' => 'channel:' . $slug,
                'data' => ['type' => 'channel', 'channel' => $slug, 'msg_id' => $msgId],
            ];
            self::sendAll($subs, $payload, $g['urgency']);
        }
    }

    /** Push a DM to the recipient (called from MessageService::notifyDm). */
    public static function dm(array $recipient, array $sender, string $content, string $kind = 'message', ?int $pmId = null): void
    {
        if (MessageService::isGuest($recipient)) {
            return;
        }
        $recipientId = (int) $recipient['id'];
        $senderId = MessageService::isGuest($sender) ? null : (int) $sender['id'];
        if ($senderId !== null && $recipientId === $senderId) {
            return;
        }
        $pref = (int) (Database::scalar('SELECT dms FROM user_push_prefs WHERE user_id = ?', [$recipientId]) ?? 1);
        if (!self::dmDecision($pref, self::isMuted($recipientId, $senderId))) {
            return;
        }
        $notifyPrefs = NotifyPrefs::get(['id' => $recipientId]);
        if (!(int) ($notifyPrefs['os_master'] ?? 1)) {
            return;
        }
        if (NotifyPrefs::quietHoursActive($notifyPrefs)) {
            return;
        }
        $subs = Database::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$recipientId]);
        if (!$subs) {
            return;
        }
        $senderName = (string) ($sender['username'] ?? '');
        $previews = (int) ($notifyPrefs['previews'] ?? 1) === 1;
        $payload = [
            'type' => 'dm',
            'title' => $senderName !== '' ? $senderName : 'New message',
            'body' => $previews ? self::excerpt($content, $kind) : 'You received a direct message',
            'tag' => 'dm:' . $senderName,
            'data' => ['type' => 'dm', 'username' => $senderName, 'msg_id' => $pmId],
        ];
        self::sendAll($subs, $payload, 'high');
    }

    /** Send a test push to every subscription the user has registered. */
    public static function sendTest(array $user): bool
    {
        if (Auth::isGuest($user)) {
            return false;
        }
        $subs = Database::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [(int) $user['id']]);
        if (!$subs) {
            return false;
        }
        $payload = [
            'type' => 'test',
            'title' => 'LVChat',
            'body' => 'Test notification — alerts are working.',
            'tag' => 'test:' . time(),
            'data' => ['type' => 'test'],
        ];
        self::sendAll($subs, $payload, 'normal');
        return true;
    }

    /** Push a channel invitation to the invited user. */
    public static function invite(int $targetUserId, int $channelId, int $senderId): void
    {
        $pref = (int) (Database::scalar('SELECT invites FROM user_push_prefs WHERE user_id = ?', [$targetUserId]) ?? 1);
        if (!self::inviteDecision($pref, self::isMuted($targetUserId, $senderId))) {
            return;
        }
        $notifyPrefs = NotifyPrefs::get(['id' => $targetUserId]);
        if (!(int) ($notifyPrefs['os_master'] ?? 1)) {
            return;
        }
        if (NotifyPrefs::quietHoursActive($notifyPrefs)) {
            return;
        }
        $subs = Database::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$targetUserId]);
        if (!$subs) {
            return;
        }
        $channel = Database::row('SELECT name, slug FROM channels WHERE id = ?', [$channelId]);
        if (!$channel) {
            return;
        }
        $senderName = (string) (Database::scalar('SELECT username FROM users WHERE id = ?', [$senderId]) ?? '');
        $payload = [
            'type' => 'invite',
            'title' => $channel['name'],
            'body' => $senderName . ' invited you to ' . $channel['name'],
            'tag' => 'invite:' . $channel['slug'],
            'data' => ['type' => 'invite', 'channel' => $channel['slug']],
        ];
        self::sendAll($subs, $payload, 'high');
    }

    /** Push a missed call to the callee (per-user DM prefs gate the nudge). */
    public static function missedCall(int $targetUserId, string $callerName, int $callId): void
    {
        $pref = (int) (Database::scalar('SELECT dms FROM user_push_prefs WHERE user_id = ?', [$targetUserId]) ?? 1);
        if (!self::dmDecision($pref, false)) {
            return;
        }
        $notifyPrefs = NotifyPrefs::get(['id' => $targetUserId]);
        if (!(int) ($notifyPrefs['os_master'] ?? 1)) {
            return;
        }
        if (NotifyPrefs::quietHoursActive($notifyPrefs)) {
            return;
        }
        $subs = Database::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$targetUserId]);
        if (!$subs) {
            return;
        }
        $payload = [
            'type' => 'call',
            'title' => ($callerName !== '' ? $callerName : 'A user') . ' called you',
            'body' => 'Missed call — tap to return it',
            'tag' => 'call:' . $callId,
            'data' => ['type' => 'call', 'call_id' => $callId, 'username' => $callerName],
        ];
        self::sendAll($subs, $payload, 'high');
    }

    // ── Low-level sending ─────────────────────────────────────────────────────

    /** All subscription rows for a set of user ids (bounded, newest first). */
    private static function subscriptionsFor(array $userIds): array
    {
        $ids = array_slice(array_map('intval', array_values($userIds)), 0, self::MAX_SUBS_PER_SEND);
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::all("SELECT * FROM push_subscriptions WHERE user_id IN ($ph)", $ids);
    }

    /**
     * Encrypt the JSON payload once per subscription and POST them all in
     * parallel. Fire-and-forget: dead endpoints (404/410) are pruned, live
     * ones get their last_seen bumped. Never throws.
     */
    private static function sendAll(array $subs, array $payload, string $urgency): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return;
        }
        self::ensureVapidKeys();
        if (self::publicKey() === '') {
            return;
        }
        $mh = curl_multi_init();
        if (!$mh) {
            return;
        }
        $handles = [];
        foreach (array_slice($subs, 0, self::MAX_SUBS_PER_SEND) as $row) {
            $ch = self::buildHandle($row, $body, $urgency);
            if (!$ch) {
                continue;
            }
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['ch' => $ch, 'id' => (int) $row['id']];
        }
        if (!$handles) {
            curl_multi_close($mh);
            return;
        }
        $active = null;
        // Total wall-clock bound: even if many handles hang at the per-handle
        // 2.5s timeout, the whole batch must not stall the message-sender request
        // much longer than a single handle would.
        $deadline = microtime(true) + 3.0;
        do {
            $rc = curl_multi_exec($mh, $active);
            if ($active) {
                if (microtime(true) > $deadline) {
                    break;
                }
                curl_multi_select($mh, 0.1);
            }
        } while ($active && $rc === CURLM_OK);
        $live = [];
        $prune = [];
        foreach ($handles as $info) {
            $code = (int) curl_getinfo($info['ch'], CURLINFO_RESPONSE_CODE);
            if ($code === 404 || $code === 410) {
                $prune[] = $info['id'];
            } else {
                $live[] = $info['id'];
            }
            curl_multi_remove_handle($mh, $info['ch']);
            curl_close($info['ch']);
        }
        curl_multi_close($mh);
        if ($live) {
            Database::query('UPDATE push_subscriptions SET last_seen = datetime("now") WHERE id IN (' . implode(',', $live) . ')');
        }
        if ($prune) {
            Database::query('DELETE FROM push_subscriptions WHERE id IN (' . implode(',', $prune) . ')');
        }
    }

    /** Build a curl handle for one subscription (per-subscription encryption). */
    private static function buildHandle(array $sub, string $payloadJson, string $urgency): mixed
    {
        $endpoint = (string) $sub['endpoint'];
        $uaPublic = self::b64uDecode((string) $sub['p256dh']);
        $auth = self::b64uDecode((string) $sub['auth']);
        if (strlen($uaPublic) !== 65 || strlen($auth) !== 16) {
            return null;
        }
        $encrypted = self::encryptPayload($payloadJson, $uaPublic, $auth);
        if ($encrypted === null) {
            return null;
        }
        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $aud = $parts['scheme'] . '://' . $parts['host'];
        $jwt = self::vapidJwt($aud);
        if ($jwt === '') {
            return null;
        }
        $ch = curl_init($endpoint);
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . self::TTL,
            'Urgency: ' . $urgency,
            'Authorization: vapid t=' . $jwt . ', k=' . self::publicKey(),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 800,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        return $ch;
    }

    // ── RFC 8291 aes128gcm encryption ────────────────────────────────────────

    /**
     * Encrypt a payload for one subscription (RFC 8291). Returns the full
     * aes128gcm body (salt || rs || idlen || keyid || ciphertext || tag), or
     * null when the crypto primitives are unavailable.
     */
    public static function encryptPayload(string $plaintext, string $uaPublicRaw, string $authSecret): ?string
    {
        if (strlen($uaPublicRaw) !== 65 || strlen($authSecret) !== 16) {
            return null;
        }
        $server = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$server) {
            return null;
        }
        $details = openssl_pkey_get_details($server);
        $serverPublicRaw = self::rawPoint($details['key'] ?? '');
        if ($serverPublicRaw === '') {
            return null;
        }
        $shared = openssl_pkey_derive(self::ecPublicPem($uaPublicRaw), $server);
        if ($shared === false || $shared === '') {
            return null;
        }

        $salt = random_bytes(16);
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublicRaw . $serverPublicRaw;
        // RFC 8291: PRK = HKDF-Extract(salt = auth_secret, IKM = ECDH shared secret).
        $prk = hash_hmac('sha256', $shared, $authSecret, true);
        $cek = self::hkdfExpand($prk, $keyInfo, 16);
        $nonce = self::hkdfExpand($prk, 'Content-Encoding: nonce' . "\x00", 12);

        $record = $plaintext . "\x02"; // single record, rs = 4096
        $header = $salt . pack('N', 4096) . chr(65) . $serverPublicRaw;
        $tag = '';
        $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, $header);
        if ($ciphertext === false) {
            return null;
        }
        return $header . $ciphertext . $tag;
    }

    /** HKDF-Expand (RFC 5869) with HMAC-SHA-256. */
    public static function hkdfExpand(string $prk, string $info, int $length): string
    {
        $out = '';
        $t = '';
        for ($i = 1; strlen($out) < $length; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $out .= $t;
        }
        return substr($out, 0, $length);
    }

    /** Wrap a raw P-256 uncompressed point in SubjectPublicKeyInfo (for openssl). */
    private static function ecPublicPem(string $rawPoint): string
    {
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $rawPoint;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
    }

    /**
     * The raw 65-byte uncompressed EC point from an openssl key's details.
     * Some PHP builds return the raw point, others a PEM (SPKI) string; the
     * uncompressed point is always the final 65 bytes of the SPKI DER.
     */
    private static function rawPoint(string $key): string
    {
        if (strlen($key) === 65 && ord($key[0]) === 0x04) {
            return $key;
        }
        $b64 = (string) preg_replace('/-----[A-Z ]+-----/', '', $key);
        $b64 = (string) preg_replace('/\s+/', '', $b64);
        $der = base64_decode($b64, true);
        if ($der === false || strlen($der) < 65) {
            return '';
        }
        $point = substr($der, -65);
        return (strlen($point) === 65 && ord($point[0]) === 0x04) ? $point : '';
    }

    // ── VAPID JWT (ES256) ─────────────────────────────────────────────────────

    /** The `sub` claim for the VAPID token (mailto: from the SMTP from-address). */
    private static function vapidSub(): string
    {
        $email = trim((string) (config_get('smtp_from_email', '') ?? ''));
        if ($email !== '') {
            return 'mailto:' . $email;
        }
        $host = trusted_host();
        $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
        return 'mailto:admin@' . $host;
    }

    /** Build a signed ES256 JWT for the VAPID Authorization header. */
    private static function vapidJwt(string $aud): string
    {
        self::ensureVapidKeys();
        $privatePem = (string) (config_get('push_vapid_private', '') ?? '');
        if ($privatePem === '') {
            return '';
        }
        $header = self::b64uEncode('{"typ":"JWT","alg":"ES256"}');
        $payload = self::b64uEncode((string) json_encode([
            'aud' => $aud,
            'exp' => time() + 43200, // 12h
            'sub' => self::vapidSub(),
        ]));
        $signingInput = $header . '.' . $payload;
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $privatePem, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        return $signingInput . '.' . self::b64uEncode(self::derToRaw($sig));
    }

    /** Convert a DER-encoded ECDSA signature to the raw 64-byte R||S form. */
    public static function derToRaw(string $der): string
    {
        $len = strlen($der);
        if ($len < 8 || ord($der[0]) !== 0x30) {
            return '';
        }
        // Skip the 0x30 and its length (single or long form).
        $pos = 2;
        $l = ord($der[1]);
        if ($l & 0x80) {
            $pos += $l & 0x7f;
        }
        $int = static function () use ($der, &$pos): string {
            if ($pos + 2 > strlen($der) || ord($der[$pos]) !== 0x02) {
                return '';
            }
            $n = ord($der[$pos + 1]);
            $out = substr($der, $pos + 2, $n);
            $pos += 2 + $n;
            return $out;
        };
        $r = $int();
        $s = $int();
        if ($r === '' || $s === '' || strlen($r) > 33 || strlen($s) > 33) {
            return '';
        }
        return self::pad32($r) . self::pad32($s);
    }

    private static function pad32(string $v): string
    {
        $v = ltrim($v, "\x00");
        if (strlen($v) > 32) {
            return substr($v, strlen($v) - 32);
        }
        return str_pad($v, 32, "\x00", STR_PAD_LEFT);
    }

    // ── Small helpers ─────────────────────────────────────────────────────────

    public static function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($data, true);
        return $out === false ? '' : $out;
    }

    /** Is $username @mentioned in $content (same nick matching as @mentions)? */
    public static function mentioned(string $content, string $username): bool
    {
        if ($content === '' || $username === '') {
            return false;
        }
        if (!preg_match_all('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', $content, $m)) {
            return false;
        }
        $username = strtolower($username);
        foreach ($m[1] as $nick) {
            if (strtolower($nick) === $username) {
                return true;
            }
        }
        return false;
    }

    /** A short plain-text excerpt for the notification body. */
    private static function excerpt(string $content, string $kind): string
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        if (in_array($kind, ['image', 'gif'], true)) {
            $caption = '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim((string) $lines[$i]);
                if ($line !== '' && !preg_match('#^https?://#i', $line)) {
                    $caption = $line;
                    break;
                }
            }
            return $caption !== '' ? mb_substr($caption, 0, 200) : '[image]';
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $content));
        return mb_substr($text, 0, 200);
    }
}

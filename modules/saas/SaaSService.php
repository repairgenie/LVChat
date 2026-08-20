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
 * SaaSService — the plan/entitlement engine for the SaaS module.
 *
 * Entitlement resolution order for an actor:
 *   1. Free plan defaults.
 *   2. Overlay the active paid plan (status active/grace).
 *   3. Overlay per-user overrides (saas_overrides).
 *   4. Clamp to hard server ceilings (global connection ceiling, voice_max_users).
 *
 * Every feature/limit getter is a no-op returning "allowed / unlimited" while
 * the module's master switch (saas_enabled) is off, and admins are always
 * exempt — so enabling the module changes nothing until the admin flips the
 * switch, and existing installs are never degraded accidentally.
 *
 * Core services (ChannelService, UploadService, ...) call these methods guarded
 * by class_exists('SaaSService'), so the module is fully optional.
 */
final class SaaSService
{
    /** Feature toggles (per-plan on/off switches). */
    public const FEATURE_KEYS = ['events', 'voice', 'voice_stt', 'voice_tts', 'openclaw_bots'];

    /** Numeric limits ('' / null value = unlimited). */
    public const LIMIT_KEYS = [
        'connections', 'owned_channels', 'memberships', 'upload_max_bytes',
        'events_concurrent', 'openclaw_bot_count', 'open_tickets',
        'reg_invites', 'history_messages',
    ];

    /** Voice QoS tiers (null = fall back to the global admin caps). */
    public const QOS_KEYS = ['voice_talker_cap', 'voice_bitrate'];

    private const LABELS = [
        'events' => 'Events',
        'voice' => 'Voice (calls + channel voice)',
        'voice_stt' => 'Speech-to-text dictation',
        'voice_tts' => 'Text-to-speech read-aloud',
        'openclaw_bots' => 'OpenClaw bots',
        'connections' => 'Concurrent connections',
        'owned_channels' => 'Owned channels',
        'memberships' => 'Channel memberships',
        'upload_max_bytes' => 'Max file size (bytes)',
        'events_concurrent' => 'Concurrent events',
        'openclaw_bot_count' => 'OpenClaw bot count',
        'open_tickets' => 'Open support tickets',
        'reg_invites' => 'Registration invites',
        'history_messages' => 'Message history window',
        'voice_talker_cap' => 'Voice talker cap',
        'voice_bitrate' => 'Voice bitrate (bps)',
    ];

    private const DEFAULT_FEATURES = [
        'events' => false,
        'voice' => false,
        'voice_stt' => false,
        'voice_tts' => false,
        'openclaw_bots' => false,
    ];

    private const DEFAULT_LIMITS = [
        'connections' => 3,
        'owned_channels' => 10,
        'memberships' => 100,
        'upload_max_bytes' => 5242880,
        'events_concurrent' => 1,
        'openclaw_bot_count' => 0,
        'open_tickets' => 3,
        'reg_invites' => 2,
        'history_messages' => null,
    ];

    private const DEFAULT_QOS = [
        'voice_talker_cap' => null,
        'voice_bitrate' => null,
    ];

    private static bool $tablesChecked = false;
    private static bool $eventsTableExists = true;

    public static function enabled(): bool
    {
        return (string) (config_get('saas_enabled', '0') ?? '0') === '1';
    }

    public static function graceDays(): int
    {
        return max(0, (int) (config_get('saas_grace_days', '3') ?? 3));
    }

    public static function currency(): string
    {
        $c = strtolower((string) (config_get('saas_checkout_currency', 'usd') ?? 'usd'));
        return preg_match('/^[a-z]{3}$/', $c) ? $c : 'usd';
    }

    /** Hard server ceiling on total concurrent WS connections (never exceeded). */
    public static function globalConnectionCeiling(): int
    {
        return max(1, (int) (config_get('saas_max_connections_global', '200') ?? 200));
    }

    public static function featureKeys(): array
    {
        return self::FEATURE_KEYS;
    }

    public static function limitKeys(): array
    {
        return self::LIMIT_KEYS;
    }

    public static function qosKeys(): array
    {
        return self::QOS_KEYS;
    }

    public static function keyLabel(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /** True while the module's master switch is off, or the actor is exempt. */
    private static function unenforced(array $actor): bool
    {
        if (!self::enabled()) {
            return true;
        }
        return self::isExempt($actor);
    }

    private static function isExempt(array $actor): bool
    {
        return empty($actor['guest']) && ($actor['role'] ?? '') === 'admin';
    }

    /** All plans ordered by sort_order, then name. */
    public static function plans(): array
    {
        return Database::all('SELECT * FROM saas_plans ORDER BY is_free DESC, sort_order ASC, name ASC');
    }

    public static function plan(int $id): ?array
    {
        return Database::row('SELECT * FROM saas_plans WHERE id = ?', [$id]) ?: null;
    }

    public static function freePlan(): array
    {
        $p = Database::row('SELECT * FROM saas_plans WHERE is_free = 1 LIMIT 1');
        return $p ?: [
            'id' => 0,
            'name' => 'Free',
            'features' => json_encode(self::DEFAULT_FEATURES),
            'limits' => json_encode(self::DEFAULT_LIMITS),
            'qos' => json_encode(self::DEFAULT_QOS),
        ];
    }

    /** Active (or grace) plan assignment for a user, else null. */
    public static function activeAssignment(int $userId): ?array
    {
        return Database::row(
            "SELECT * FROM saas_user_plans WHERE user_id = ? AND status IN ('active', 'grace')",
            [$userId]
        ) ?: null;
    }

    /** Current plan + assignment for display (never the merged entitlements). */
    public static function planForUser(int $userId): array
    {
        self::resolveLifecycle($userId);
        $row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = ?', [$userId]);
        if ($row) {
            $plan = self::plan((int) $row['plan_id']) ?: self::freePlan();
            return ['plan' => $plan, 'assignment' => $row];
        }
        return ['plan' => self::freePlan(), 'assignment' => null];
    }

    /**
     * Full merged entitlements for an actor:
     * ['features' => [key => bool], 'limits' => [key => ?int], 'qos' => [key => ?int]].
     * While unenforced, returns everything allowed/unlimited.
     */
    public static function limitsFor(array $actor): array
    {
        $all = [
            'features' => array_fill_keys(self::FEATURE_KEYS, true),
            'limits' => array_fill_keys(self::LIMIT_KEYS, null),
            'qos' => array_fill_keys(self::QOS_KEYS, null),
        ];
        if (self::unenforced($actor)) {
            return $all;
        }
        if (Auth::isGuest($actor)) {
            $plan = self::freePlan();
            // Guests share a numeric id space with registered users — never
            // merge saas_overrides keyed on user ids for a guest actor (0 does
            // not match any user).
            return self::mergeOverrides(
                self::decode($plan['features'], self::DEFAULT_FEATURES),
                self::decode($plan['limits'], self::DEFAULT_LIMITS),
                self::decode($plan['qos'], self::DEFAULT_QOS),
                0
            );
        }
        $userId = (int) $actor['id'];
        self::resolveLifecycle($userId);
        $plan = self::freePlan();
        $active = self::activeAssignment($userId);
        if ($active) {
            $p = self::plan((int) $active['plan_id']);
            if ($p && (int) $p['active'] === 1) {
                $plan = $p;
            }
        }
        return self::mergeOverrides(
            self::decode($plan['features'], self::DEFAULT_FEATURES),
            self::decode($plan['limits'], self::DEFAULT_LIMITS),
            self::decode($plan['qos'], self::DEFAULT_QOS),
            $userId
        );
    }

    private static function mergeOverrides(array $features, array $limits, array $qos, int $userId): array
    {
        foreach (self::overridesFor($userId) as $key => $value) {
            if (in_array($key, self::FEATURE_KEYS, true)) {
                $features[$key] = $value === '1';
            } elseif (in_array($key, self::LIMIT_KEYS, true)) {
                $limits[$key] = self::parseLimit($value);
            } elseif (in_array($key, self::QOS_KEYS, true)) {
                $qos[$key] = self::parseLimit($value);
            }
        }
        return ['features' => $features, 'limits' => $limits, 'qos' => $qos];
    }

    /** Whether the actor has a feature (true = allowed). */
    public static function feature(array $actor, string $key): bool
    {
        if (self::unenforced($actor)) {
            return true;
        }
        return (bool) (self::limitsFor($actor)['features'][$key] ?? false);
    }

    /** Same as feature(), but takes a user id (services without an actor row). */
    public static function featureForUser(int $userId, string $key): bool
    {
        $u = Database::row('SELECT role, guest FROM users WHERE id = ?', [$userId]);
        $actor = [
            'id' => $userId,
            'role' => (string) ($u['role'] ?? 'user'),
            'guest' => (int) ($u['guest'] ?? 0),
        ];
        return self::feature($actor, $key);
    }

    /** A numeric limit for an actor (null = unlimited / not enforced). */
    public static function limit(array $actor, string $key): ?int
    {
        if (self::unenforced($actor)) {
            return null;
        }
        return self::limitsFor($actor)['limits'][$key] ?? null;
    }

    /** Same as limit(), but takes a user id. */
    public static function limitForUser(int $userId, string $key): ?int
    {
        $u = Database::row('SELECT role, guest FROM users WHERE id = ?', [$userId]);
        $actor = [
            'id' => $userId,
            'role' => (string) ($u['role'] ?? 'user'),
            'guest' => (int) ($u['guest'] ?? 0),
        ];
        return self::limit($actor, $key);
    }

    /** Voice QoS tier for an actor: ['talker_cap' => ?int, 'bitrate' => ?int]. */
    public static function voiceQos(array $actor): array
    {
        if (self::unenforced($actor)) {
            return ['talker_cap' => null, 'bitrate' => null];
        }
        $q = self::limitsFor($actor)['qos'];
        return [
            'talker_cap' => isset($q['voice_talker_cap']) ? (int) $q['voice_talker_cap'] : null,
            'bitrate' => isset($q['voice_bitrate']) ? (int) $q['voice_bitrate'] : null,
        ];
    }

    /** Concurrent-connection allowance for the gateway (null = unlimited). */
    public static function connectionLimit(array $actor): ?int
    {
        return self::limit($actor, 'connections');
    }

    /** Message-id floor (inclusive) for a channel, or null when unlimited. */
    public static function historyFloor(int $channelId, array $actor): ?int
    {
        $n = self::limit($actor, 'history_messages');
        if ($n === null) {
            return null;
        }
        $maxId = (int) Database::scalar('SELECT MAX(id) FROM messages WHERE channel_id = ?', [$channelId]);
        if ($maxId <= 0) {
            return null;
        }
        return max(1, $maxId - $n + 1);
    }

    /** Message-id floor across all channels (used by search). */
    public static function historyFloorGlobal(array $actor): ?int
    {
        $n = self::limit($actor, 'history_messages');
        if ($n === null) {
            return null;
        }
        $maxId = (int) Database::scalar('SELECT MAX(id) FROM messages');
        if ($maxId <= 0) {
            return null;
        }
        return max(1, $maxId - $n + 1);
    }

    // ── Live counters (the "metered" reads) ──────────────────────────────────

    public static function eventCount(int $userId): int
    {
        self::checkTables();
        if (!self::$eventsTableExists) {
            return 0;
        }
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM events e WHERE e.founder_id = ? AND e.status IN (\'scheduled\', \'active\')',
            [$userId]
        );
    }

    public static function ownedChannelCount(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM channels WHERE owner_id = ?', [$userId]);
    }

    public static function membershipCount(array $actor): int
    {
        if (Auth::isGuest($actor)) {
            return (int) Database::scalar('SELECT COUNT(*) FROM channel_members WHERE guest_id = ?', [(int) $actor['id']]);
        }
        return (int) Database::scalar('SELECT COUNT(*) FROM channel_members WHERE user_id = ?', [(int) $actor['id']]);
    }

    public static function botCount(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM openclaw_bots WHERE created_by = ?', [$userId]);
    }

    public static function openTicketCount(int $userId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status = 'open'",
            [$userId]
        );
    }

    public static function regInviteCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM registration_invites WHERE invited_by = ? AND used_at IS NULL AND expires_at > datetime("now")',
            [$userId]
        );
    }

    // ── Lifecycle (grace then downgrade) ─────────────────────────────────────

    public static function resolveLifecycle(int $userId): void
    {
        $row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = ?', [$userId]);
        if (!$row) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($row['status'] === 'active'
            && $row['period_end'] !== null && $row['period_end'] !== ''
            && $row['period_end'] < $now) {
            $graceDays = self::graceDays();
            Database::query(
                'UPDATE saas_user_plans SET status = "grace", grace_until = datetime("now", ?), updated_at = datetime("now") WHERE user_id = ?',
                ["+$graceDays days", $userId]
            );
            log_audit('saas_grace', 'user#' . $userId, 'subscription period ended');
        }
        if ($row['status'] === 'grace'
            && $row['grace_until'] !== null && $row['grace_until'] < $now) {
            Database::query(
                'UPDATE saas_user_plans SET status = "expired", updated_at = datetime("now") WHERE user_id = ?',
                [$userId]
            );
            log_audit('saas_downgrade', 'user#' . $userId, 'grace period lapsed — downgraded to free');
        }
    }

    /** Mark every lapsed subscription for every user (billing rollover cron). */
    public static function resolveAllLifecycles(): int
    {
        $count = 0;
        foreach (Database::all('SELECT user_id FROM saas_user_plans') as $row) {
            self::resolveLifecycle((int) $row['user_id']);
            $count++;
        }
        return $count;
    }

    /** Activate (or replace) a user's plan assignment. Returns ['ok', 'error']. */
    public static function assignPlan(int $userId, int $planId, string $source = 'admin', array $opts = []): array
    {
        $plan = self::plan($planId);
        if (!$plan) {
            return ['ok' => false, 'error' => 'Unknown plan.'];
        }
        $end = $opts['period_end'] ?? self::periodEndFor($plan, $source);
        Database::query(
            'INSERT OR REPLACE INTO saas_user_plans
               (user_id, plan_id, status, source, provider, provider_sub_id, period_start, period_end, grace_until, auto_renew, updated_at)
             VALUES (?, ?, "active", ?, ?, ?, datetime("now"), ?, NULL, ?, datetime("now"))',
            [
                $userId,
                $planId,
                $source,
                $opts['provider'] ?? null,
                $opts['provider_sub_id'] ?? null,
                $end,
                !empty($opts['auto_renew']) ? 1 : 0,
            ]
        );
        log_audit('saas_assign', 'user#' . $userId, $plan['name'] . " ($source)");
        return ['ok' => true];
    }

    /** End a plan assignment (admin force-downgrade or provider cancellation). */
    public static function downgrade(int $userId, string $reason = ''): void
    {
        Database::query(
            'UPDATE saas_user_plans SET status = "expired", auto_renew = 0, updated_at = datetime("now") WHERE user_id = ?',
            [$userId]
        );
        log_audit('saas_downgrade', 'user#' . $userId, $reason);
    }

    /** Extend an active subscription's period (provider invoice paid). */
    public static function renew(int $userId, ?string $periodEnd = null): void
    {
        $row = Database::row('SELECT plan_id FROM saas_user_plans WHERE user_id = ?', [$userId]);
        if (!$row) {
            return;
        }
        $plan = self::plan((int) $row['plan_id']);
        $end = $periodEnd ?: ($plan ? self::periodEndFor($plan, 'renew') : null);
        Database::query(
            'UPDATE saas_user_plans SET status = "active", grace_until = NULL, period_end = COALESCE(?, period_end), updated_at = datetime("now") WHERE user_id = ?',
            [$end, $userId]
        );
    }

    /** Enter the grace period for a user (provider payment failed). */
    public static function enterGrace(int $userId, string $reason = ''): void
    {
        $graceDays = self::graceDays();
        Database::query(
            'UPDATE saas_user_plans SET status = "grace", grace_until = datetime("now", ?), updated_at = datetime("now") WHERE user_id = ?',
            ["+$graceDays days", $userId]
        );
        log_audit('saas_grace', 'user#' . $userId, $reason);
    }

    public static function periodEndFor(array $plan, string $source): string
    {
        $cycle = (string) ($plan['billing_cycle'] ?? 'monthly');
        if ($source === 'admin' && (int) ($plan['trial_days'] ?? 0) > 0) {
            return gmdate('Y-m-d H:i:s', time() + (int) $plan['trial_days'] * 86400);
        }
        return $cycle === 'yearly'
            ? gmdate('Y-m-d H:i:s', time() + 365 * 86400)
            : gmdate('Y-m-d H:i:s', time() + 30 * 86400);
    }

    // ── Per-user overrides ───────────────────────────────────────────────────

    public static function overridesFor(int $userId): array
    {
        $out = [];
        foreach (Database::all('SELECT key, value, note FROM saas_overrides WHERE user_id = ?', [$userId]) as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }
        return $out;
    }

    public static function setOverride(int $userId, string $key, ?string $value, string $note = ''): void
    {
        if (!in_array($key, self::FEATURE_KEYS, true)
            && !in_array($key, self::LIMIT_KEYS, true)
            && !in_array($key, self::QOS_KEYS, true)) {
            return;
        }
        $note = mb_substr(trim($note), 0, 200);
        if ($value === null || $value === '') {
            Database::query(
                'INSERT INTO saas_overrides (user_id, key, value, note) VALUES (?, ?, ?, ?)
                 ON CONFLICT (user_id, key) DO UPDATE SET value = "", note = excluded.note',
                [$userId, $key, '', $note]
            );
            return;
        }
        Database::query(
            'INSERT INTO saas_overrides (user_id, key, value, note) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, key) DO UPDATE SET value = excluded.value, note = excluded.note',
            [$userId, $key, $value, $note]
        );
        log_audit('saas_override', 'user#' . $userId, "$key = " . ($value === '' ? 'unlimited' : $value));
    }

    public static function clearOverride(int $userId, string $key): void
    {
        Database::query('DELETE FROM saas_overrides WHERE user_id = ? AND key = ?', [$userId, $key]);
    }

    // ── Plan CRUD (used by the admin controller and tests) ───────────────────

    public static function createPlan(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Plan name is required.'];
        }
        $slug = strtolower(trim((string) ($d['slug'] ?? '')));
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)) ?: 'plan';
        }
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        if (Database::scalar('SELECT 1 FROM saas_plans WHERE slug = ? COLLATE NOCASE', [$slug])) {
            return ['ok' => false, 'error' => 'A plan with that slug already exists.'];
        }
        $data = self::normalizePlanData($d);
        Database::query(
            'INSERT INTO saas_plans
               (name, slug, description, is_free, active, sort_order, price_amount, price_currency, billing_cycle, trial_days, features, limits, qos, provider_ids)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $slug,
                mb_substr(trim((string) ($d['description'] ?? '')), 0, 500),
                !empty($d['is_free']) ? 1 : 0,
                !empty($d['active']) ? 1 : 1,
                (int) ($d['sort_order'] ?? 0),
                max(0, (int) ($d['price_amount'] ?? 0)),
                self::currency(),
                in_array((string) ($d['billing_cycle'] ?? ''), ['monthly', 'yearly'], true) ? (string) $d['billing_cycle'] : 'monthly',
                max(0, (int) ($d['trial_days'] ?? 0)),
                json_encode($data['features']),
                json_encode($data['limits']),
                json_encode($data['qos']),
                '{}',
            ]
        );
        $id = (int) Database::lastId();
        log_audit('saas_plan_create', $name);
        return ['ok' => true, 'id' => $id];
    }

    public static function updatePlan(int $id, array $d): array
    {
        $plan = self::plan($id);
        if (!$plan) {
            return ['ok' => false, 'error' => 'Unknown plan.'];
        }
        $name = trim((string) ($d['name'] ?? $plan['name']));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Plan name is required.'];
        }
        $data = self::normalizePlanData($d);
        Database::query(
            'UPDATE saas_plans SET name = ?, description = ?, active = ?, sort_order = ?,
               price_amount = ?, billing_cycle = ?, trial_days = ?, features = ?, limits = ?, qos = ?, updated_at = datetime("now")
             WHERE id = ?',
            [
                $name,
                mb_substr(trim((string) ($d['description'] ?? $plan['description'])), 0, 500),
                !empty($d['active']) ? 1 : 0,
                (int) ($d['sort_order'] ?? $plan['sort_order']),
                max(0, (int) ($d['price_amount'] ?? $plan['price_amount'])),
                in_array((string) ($d['billing_cycle'] ?? ''), ['monthly', 'yearly'], true) ? (string) $d['billing_cycle'] : (string) $plan['billing_cycle'],
                max(0, (int) ($d['trial_days'] ?? $plan['trial_days'])),
                json_encode($data['features']),
                json_encode($data['limits']),
                json_encode($data['qos']),
                $id,
            ]
        );
        log_audit('saas_plan_update', (string) $plan['name']);
        return ['ok' => true];
    }

    public static function deletePlan(int $id): array
    {
        $plan = self::plan($id);
        if (!$plan) {
            return ['ok' => false, 'error' => 'Unknown plan.'];
        }
        if ((int) $plan['is_free'] === 1) {
            return ['ok' => false, 'error' => 'The free plan cannot be deleted.'];
        }
        $assigned = (int) Database::scalar('SELECT COUNT(*) FROM saas_user_plans WHERE plan_id = ?', [$id]);
        if ($assigned > 0) {
            return ['ok' => false, 'error' => "This plan is assigned to $assigned user(s). Downgrade them first."];
        }
        Database::query('DELETE FROM saas_plans WHERE id = ?', [$id]);
        log_audit('saas_plan_delete', (string) $plan['name']);
        return ['ok' => true];
    }

    public static function togglePlan(int $id): array
    {
        $plan = self::plan($id);
        if (!$plan) {
            return ['ok' => false, 'error' => 'Unknown plan.'];
        }
        if ((int) $plan['is_free'] === 1) {
            return ['ok' => false, 'error' => 'The free plan cannot be disabled.'];
        }
        Database::query('UPDATE saas_plans SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?', [$id]);
        return ['ok' => true];
    }

    /**
     * Normalize raw form input into registry-shaped arrays. Feature keys come
     * from checkboxes ('1' present), limits/QoS from text inputs ('' = unlimited).
     */
    public static function normalizePlanData(array $d): array
    {
        $features = [];
        foreach (self::FEATURE_KEYS as $k) {
            $features[$k] = !empty($d['feature_' . $k]);
        }
        $limits = [];
        foreach (self::LIMIT_KEYS as $k) {
            $limits[$k] = self::parseLimit(isset($d['limit_' . $k]) ? trim((string) $d['limit_' . $k]) : null);
        }
        $qos = [];
        foreach (self::QOS_KEYS as $k) {
            $qos[$k] = self::parseLimit(isset($d['qos_' . $k]) ? trim((string) $d['qos_' . $k]) : null);
        }
        return ['features' => $features, 'limits' => $limits, 'qos' => $qos];
    }

    // ── Payments ledger ──────────────────────────────────────────────────────

    public static function recordPayment(int $userId, int $planId, string $provider, ?string $providerPaymentId, int $amount, string $currency, string $status, string $raw = ''): void
    {
        if ($providerPaymentId !== null && $providerPaymentId !== '') {
            $exists = Database::scalar(
                'SELECT 1 FROM saas_payments WHERE provider = ? AND provider_payment_id = ? AND kind = "payment"',
                [$provider, $providerPaymentId]
            );
            if ($exists) {
                return;
            }
        }
        Database::query(
            'INSERT INTO saas_payments (user_id, plan_id, provider, provider_payment_id, kind, amount, currency, status, raw) VALUES (?, ?, ?, ?, "payment", ?, ?, ?, ?)',
            [$userId, $planId, $provider, $providerPaymentId, $amount, $currency, $status, $raw]
        );
    }

    public static function paymentsFor(int $userId): array
    {
        return Database::all(
            'SELECT p.*, pl.name AS plan_name FROM saas_payments p LEFT JOIN saas_plans pl ON pl.id = p.plan_id WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 50',
            [$userId]
        );
    }

    // ── Platform fee (developer support fee on every paid transaction) ───────

    /** Whether the platform fee is live. Admins cannot disable it; developer
     *  mode (SAAS_DEVELOPER_MODE=1) exposes the saas_platform_fee_enabled
     *  setting so a developer can switch it off for local testing. */
    public static function platformFeeEnabled(): bool
    {
        if ((string) (getenv('SAAS_DEVELOPER_MODE') ?: '0') === '1') {
            return (string) (config_get('saas_platform_fee_enabled', '1') ?? '1') === '1';
        }
        return (string) (getenv('SAAS_PLATFORM_FEE_ENABLED') ?: '1') === '1';
    }

    /** Developer mode: allow disabling the platform fee via server_config. */
    public static function developerMode(): bool
    {
        return (string) (getenv('SAAS_DEVELOPER_MODE') ?: '0') === '1';
    }

    /** Platform fee in the provider's minor currency unit (cents), default $0.75. */
    public static function platformFeeAmountCents(): int
    {
        $raw = trim((string) (getenv('SAAS_PLATFORM_FEE') ?: '0.75'));
        $v = is_numeric($raw) ? (float) $raw : 0.75;
        return max(0, (int) round($v * 100));
    }

    public static function platformFeeCurrency(): string
    {
        $c = strtolower(trim((string) (getenv('SAAS_PLATFORM_FEE_CURRENCY') ?: '')));
        return preg_match('/^[a-z]{3}$/', $c) ? $c : self::currency();
    }

    /** Developer payout destination (e.g. a Stripe Connect account id). */
    public static function platformFeeDestination(): string
    {
        return trim((string) (getenv('SAAS_PLATFORM_FEE_DESTINATION') ?: ''));
    }

    /** Whether a platform fee has already been captured for a provider payment. */
    public static function platformFeeCaptured(string $provider, string $providerPaymentId): bool
    {
        return (bool) Database::scalar(
            'SELECT 1 FROM saas_payments WHERE provider = ? AND provider_payment_id = ? AND kind = "platform_fee"',
            [$provider, $providerPaymentId]
        );
    }

    public static function recordPlatformFee(int $userId, int $planId, string $provider, string $providerPaymentId, int $amount, string $currency, string $status, string $detail = ''): void
    {
        Database::query(
            'INSERT INTO saas_payments (user_id, plan_id, provider, provider_payment_id, kind, amount, currency, status, raw) VALUES (?, ?, ?, ?, "platform_fee", ?, ?, ?, ?)',
            [$userId, $planId, $provider, $providerPaymentId, $amount, $currency, $status, $detail]
        );
    }

    public static function recordEvent(string $provider, string $eventId, string $action): bool
    {
        $exists = Database::scalar('SELECT 1 FROM saas_events WHERE provider = ? AND event_id = ?', [$provider, $eventId]);
        if ($exists) {
            return false;
        }
        Database::query(
            'INSERT INTO saas_events (provider, event_id, action) VALUES (?, ?, ?)',
            [$provider, $eventId, $action]
        );
        return true;
    }

    public static function checkoutForSession(string $provider, string $sessionId): ?array
    {
        return Database::row(
            'SELECT * FROM saas_checkouts WHERE provider = ? AND provider_session_id = ?',
            [$provider, $sessionId]
        ) ?: null;
    }

    public static function markCheckoutComplete(int $id): void
    {
        Database::query(
            'UPDATE saas_checkouts SET status = "completed", completed_at = datetime("now") WHERE id = ?',
            [$id]
        );
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    private static function decode(string $json, array $defaults): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $defaults;
        }
        $out = $defaults;
        foreach ($data as $k => $v) {
            if (array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function parseLimit(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return max(0, (int) $value);
    }

    private static function checkTables(): void
    {
        if (self::$tablesChecked) {
            return;
        }
        self::$tablesChecked = true;
        self::$eventsTableExists = (bool) Database::scalar(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'events'"
        );
    }
}

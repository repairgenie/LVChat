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
 * AdminSaaSController — Admin → SaaS admin pages (module views).
 *
 * Routes (registered by routes.php):
 *   GET  /admin/saas            — plan list + editor
 *   POST /admin/saas/plan/save|delete|toggle
 *   GET  /admin/saas/users      — user search, plan assignment, overrides
 *   POST /admin/saas/users/save|cancel
 *   GET  /admin/saas/billing    — provider config + platform fee
 *   POST /admin/saas/billing/save
 */
final class AdminSaaSController
{
    private static function requireAdmin(): array
    {
        return Auth::requireAdmin();
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch.');
        }
    }

    /** GET /admin/saas */
    public static function plans(): void
    {
        $admin = self::requireAdmin();
        $plans = SaaSService::plans();
        $counts = [];
        foreach ($plans as $p) {
            $counts[(int) $p['id']] = (int) Database::scalar('SELECT COUNT(*) FROM saas_user_plans WHERE plan_id = ?', [(int) $p['id']]);
        }
        ModuleLoader::view('saas', 'admin/plans', [
            'admin' => $admin,
            'plans' => $plans,
            'counts' => $counts,
            'feature_keys' => SaaSService::featureKeys(),
            'limit_keys' => SaaSService::limitKeys(),
            'qos_keys' => SaaSService::qosKeys(),
            'module' => ModuleLoader::get('saas'),
        ], 'layout');
    }

    /** POST /admin/saas/plan/save — create or update a plan. */
    public static function planSave(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $id = (int) ($_POST['plan_id'] ?? 0);
        $res = $id > 0
            ? SaaSService::updatePlan($id, $_POST)
            : SaaSService::createPlan($_POST);
        if (empty($res['ok'])) {
            flash($res['error'] ?? 'Could not save the plan.');
        } else {
            flash('Plan saved.');
        }
        redirect('/admin/saas');
    }

    /** POST /admin/saas/plan/delete */
    public static function planDelete(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $res = SaaSService::deletePlan((int) ($_POST['plan_id'] ?? 0));
        flash($res['ok'] ? 'Plan deleted.' : ($res['error'] ?? 'Could not delete the plan.'));
        redirect('/admin/saas');
    }

    /** POST /admin/saas/plan/toggle */
    public static function planToggle(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $res = SaaSService::togglePlan((int) ($_POST['plan_id'] ?? 0));
        flash($res['ok'] ? 'Plan updated.' : ($res['error'] ?? 'Could not toggle the plan.'));
        redirect('/admin/saas');
    }

    /** GET /admin/saas/users */
    public static function users(): void
    {
        $admin = self::requireAdmin();
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = [];
        if ($q !== '') {
            $rows = Database::all(
                'SELECT id, username, email, role, guest, registered_at FROM users
                 WHERE username LIKE ? ESCAPE "\\" OR email LIKE ? ESCAPE "\\"
                 ORDER BY id DESC LIMIT 20',
                ['%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%', '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%']
            );
        } elseif (!empty($_GET['user'])) {
            $rows = Database::all(
                'SELECT id, username, email, role, guest, registered_at FROM users WHERE id = ?',
                [(int) $_GET['user']]
            );
        }
        $viewUser = null;
        $planFor = null;
        $overrides = [];
        if (!empty($_GET['user']) && !empty($rows)) {
            $viewUser = $rows[0];
            $planFor = SaaSService::planForUser((int) $viewUser['id']);
            $overrides = Database::all(
                'SELECT key, value, note FROM saas_overrides WHERE user_id = ? ORDER BY key',
                [(int) $viewUser['id']]
            );
        }
        ModuleLoader::view('saas', 'admin/users', [
            'admin' => $admin,
            'q' => $q,
            'rows' => $rows,
            'view_user' => $viewUser,
            'plan_for' => $planFor,
            'overrides' => $overrides,
            'plans' => SaaSService::plans(),
            'feature_keys' => SaaSService::featureKeys(),
            'limit_keys' => SaaSService::limitKeys(),
            'qos_keys' => SaaSService::qosKeys(),
            'module' => ModuleLoader::get('saas'),
        ], 'layout');
    }

    /** POST /admin/saas/users/save — assign plan + set per-user overrides. */
    public static function userSave(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('Missing user.');
            redirect('/admin/saas/users');
        }
        $planId = (int) ($_POST['plan_id'] ?? 0);
        if ($planId > 0) {
            SaaSService::assignPlan($userId, $planId, 'admin');
        } else {
            SaaSService::downgrade($userId, 'admin removed plan assignment');
        }

        foreach (array_merge(SaaSService::featureKeys(), SaaSService::limitKeys(), SaaSService::qosKeys()) as $key) {
            $clear = !empty($_POST['ovc_' . $key]);
            $val = trim((string) ($_POST['ov_' . $key] ?? ''));
            $note = trim((string) ($_POST['ovn_' . $key] ?? ''));
            if ($clear) {
                SaaSService::clearOverride($userId, $key);
                continue;
            }
            if (in_array($key, SaaSService::featureKeys(), true)) {
                if (in_array($val, ['allow', 'deny'], true)) {
                    SaaSService::setOverride($userId, $key, $val === 'allow' ? '1' : '0', $note);
                }
                continue;
            }
            if ($val === '' || $val === '-') {
                continue;
            }
            if (strcasecmp($val, 'unlimited') === 0) {
                SaaSService::setOverride($userId, $key, '', $note);
                continue;
            }
            if (is_numeric($val)) {
                SaaSService::setOverride($userId, $key, (string) max(0, (int) $val), $note);
            }
        }

        flash('User plan and overrides saved.');
        redirect('/admin/saas/users?user=' . $userId);
    }

    /** POST /admin/saas/users/cancel — force-downgrade a user to free. */
    public static function userCancel(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            SaaSService::downgrade($userId, 'admin force-downgrade');
            flash('User downgraded to the free plan.');
        }
        redirect('/admin/saas/users?user=' . $userId);
    }

    /** GET /admin/saas/billing */
    public static function billing(): void
    {
        $admin = self::requireAdmin();
        $keys = [
            'saas_enabled', 'saas_grace_days', 'saas_max_connections_global', 'saas_checkout_currency',
            'saas_platform_fee_enabled',
            'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id', 'paypal_mode',
            'btcpay_url', 'btcpay_api_key', 'btcpay_store_id', 'btcpay_webhook_secret',
        ];
        $settings = [];
        foreach ($keys as $k) {
            $settings[$k] = (string) (config_get($k, '') ?? '');
        }
        $has = ['stripe_secret_key', 'paypal_client_secret', 'btcpay_api_key', 'btcpay_webhook_secret', 'stripe_webhook_secret', 'paypal_webhook_id'];
        foreach ($has as $k) {
            $settings[$k . '_set'] = trim((string) (config_get($k, '') ?? '')) !== '';
        }
        ModuleLoader::view('saas', 'admin/billing', [
            'admin' => $admin,
            'settings' => $settings,
            'providers' => BillingService::providers(),
            'configured' => [
                'stripe' => BillingService::configured('stripe'),
                'paypal' => BillingService::configured('paypal'),
                'btcpay' => BillingService::configured('btcpay'),
            ],
            'webhook_urls' => [
                'stripe' => BillingService::webhookUrl('stripe'),
                'paypal' => BillingService::webhookUrl('paypal'),
                'btcpay' => BillingService::webhookUrl('btcpay'),
            ],
            'dev_mode' => SaaSService::developerMode(),
            'fee' => [
                'enabled' => SaaSService::platformFeeEnabled(),
                'amount' => SaaSService::platformFeeAmountCents(),
                'currency' => SaaSService::platformFeeCurrency(),
                'destination' => SaaSService::platformFeeDestination(),
                'dev_mode' => SaaSService::developerMode(),
            ],
            'module' => ModuleLoader::get('saas'),
        ], 'layout');
    }

    /** POST /admin/saas/billing/save */
    public static function billingSave(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $p = $_POST;

        config_set('saas_enabled', ($p['saas_enabled'] ?? '0') === '1' ? '1' : '0');
        config_set('saas_grace_days', (string) max(0, min(90, (int) ($p['saas_grace_days'] ?? 3))));
        config_set('saas_max_connections_global', (string) max(1, min(100000, (int) ($p['saas_max_connections_global'] ?? 200))));
        $cur = strtolower(trim((string) ($p['saas_checkout_currency'] ?? 'usd')));
        if (preg_match('/^[a-z]{3}$/', $cur)) {
            config_set('saas_checkout_currency', $cur);
        }

        // The platform fee is developer-controlled. Outside developer mode the
        // admin form does not even render a toggle; when present we still only
        // honour it in developer mode so an admin can never flip it off.
        if (SaaSService::developerMode()) {
            config_set('saas_platform_fee_enabled', ($p['saas_platform_fee_enabled'] ?? '0') === '1' ? '1' : '0');
        }

        config_set('stripe_publishable_key', trim((string) ($p['stripe_publishable_key'] ?? '')));
        self::writeSecret('stripe_secret_key', (string) ($p['stripe_secret_key'] ?? ''));
        self::writeSecret('stripe_webhook_secret', (string) ($p['stripe_webhook_secret'] ?? ''));

        config_set('paypal_client_id', trim((string) ($p['paypal_client_id'] ?? '')));
        self::writeSecret('paypal_client_secret', (string) ($p['paypal_client_secret'] ?? ''));
        config_set('paypal_webhook_id', trim((string) ($p['paypal_webhook_id'] ?? '')));
        config_set('paypal_mode', ($p['paypal_mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox');

        $btcpay = trim((string) ($p['btcpay_url'] ?? ''));
        if ($btcpay !== '' && preg_match('#^https?://[^\s]+$#', $btcpay)) {
            config_set('btcpay_url', rtrim($btcpay, '/'));
        }
        self::writeSecret('btcpay_api_key', (string) ($p['btcpay_api_key'] ?? ''));
        config_set('btcpay_store_id', trim((string) ($p['btcpay_store_id'] ?? '')));
        self::writeSecret('btcpay_webhook_secret', (string) ($p['btcpay_webhook_secret'] ?? ''));

        log_audit('saas_billing', null, 'billing settings saved by ' . (Auth::user()['username'] ?? 'admin'));
        flash('Billing settings saved.');
        redirect('/admin/saas/billing');
    }

    /** Write a secret only when a new non-empty value was posted (write-only). */
    private static function writeSecret(string $key, string $value): void
    {
        if (trim($value) !== '') {
            config_set($key, trim($value));
        }
    }
}

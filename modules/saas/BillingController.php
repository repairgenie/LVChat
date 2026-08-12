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
 * BillingController — user-facing billing portal + provider webhooks.
 *
 * Routes (registered by routes.php):
 *   GET  /billing                       — current plan + upgrade options
 *   POST /billing/checkout              — start a provider checkout session
 *   GET  /billing/return                — post-checkout confirmation page
 *   POST /billing/cancel                — cancel a self-serve subscription
 *   GET  /api/saas/entitlements         — JSON entitlement snapshot for the UI
 *   POST /api/saas/billing/webhook/{provider} — provider webhooks
 */
final class BillingController
{
    private static function requireUser(): array
    {
        return Auth::require();
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

    /** GET /billing */
    public static function portal(): void
    {
        $user = self::requireUser();
        $info = SaaSService::planForUser((int) $user['id']);
        $entitlements = SaaSService::limitsFor($user);
        $plans = [];
        foreach (SaaSService::plans() as $p) {
            if ((int) $p['active'] !== 1 || (int) $p['is_free'] === 1) {
                continue;
            }
            $plans[] = $p;
        }
        ModuleLoader::view('saas', 'billing/portal', [
            'user' => $user,
            'plan' => $info['plan'],
            'assignment' => $info['assignment'],
            'entitlements' => $entitlements,
            'plans' => $plans,
            'providers' => BillingService::providers(),
            'configured' => [
                'stripe' => BillingService::configured('stripe'),
                'paypal' => BillingService::configured('paypal'),
                'btcpay' => BillingService::configured('btcpay'),
            ],
            'payments' => SaaSService::paymentsFor((int) $user['id']),
            'module' => ModuleLoader::get('saas'),
        ], 'layout');
    }

    /** POST /billing/checkout */
    public static function checkout(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        if (Auth::isGuest($user)) {
            json_out(['error' => 'Registered users only.'], 403);
        }
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $provider = (string) ($_POST['provider'] ?? '');
        $plan = SaaSService::plan($planId);
        if (!$plan || (int) $plan['active'] !== 1 || (int) $plan['is_free'] === 1) {
            flash('That plan is not available.');
            redirect('/billing');
        }
        if (!in_array($provider, array_keys(BillingService::providers()), true)) {
            flash('Unknown payment provider.');
            redirect('/billing');
        }
        $res = BillingService::createCheckout($plan, $user, $provider);
        if (empty($res['ok'])) {
            flash($res['error'] ?? 'Could not start the checkout.');
            redirect('/billing');
        }
        Database::query(
            'INSERT INTO saas_checkouts (user_id, plan_id, provider, provider_session_id, status, expires_at)
             VALUES (?, ?, ?, ?, "pending", datetime("now", "+2 hours"))',
            [(int) $user['id'], $planId, $provider, $res['session_id']]
        );
        log_audit('saas_checkout', (string) $plan['name'], $provider);
        redirect($res['url']);
    }

    /** GET /billing/return — the provider sends the customer back here. */
    public static function checkoutReturn(): void
    {
        $user = self::requireUser();
        $provider = (string) ($_GET['provider'] ?? '');
        $status = 'Payment is being processed. Your plan activates as soon as the payment provider confirms it (usually within a minute).';
        if (!empty($_GET['session'])) {
            $checkout = Database::row(
                'SELECT * FROM saas_checkouts WHERE provider = ? AND provider_session_id = ? AND user_id = ?',
                [$provider, (string) $_GET['session'], (int) $user['id']]
            );
            if ($checkout) {
                Database::query('UPDATE saas_checkouts SET status = "returned" WHERE id = ?', [(int) $checkout['id']]);
            }
        }
        flash($status);
        redirect('/billing');
    }

    /** POST /billing/cancel — cancel a self-serve subscription (downgrade). */
    public static function cancel(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $row = SaaSService::activeAssignment((int) $user['id']);
        if ($row) {
            SaaSService::downgrade((int) $user['id'], 'user cancelled subscription');
            log_audit('saas_cancel', (string) ($user['username'] ?? ''), 'user cancelled');
            flash('Your subscription was cancelled. You are back on the free plan.');
        } else {
            flash('You are not on a paid plan.');
        }
        redirect('/billing');
    }

    /** GET /api/saas/entitlements */
    public static function entitlements(): void
    {
        $user = Auth::user();
        $actor = $user ?: ['id' => 0, 'role' => 'user', 'guest' => 1, 'username' => ''];
        $limits = SaaSService::limitsFor($actor);
        $info = $user ? SaaSService::planForUser((int) $user['id']) : null;
        json_out([
            'ok' => true,
            'enabled' => SaaSService::enabled(),
            'plan' => $info ? $info['plan']['name'] : 'Free',
            'features' => $limits['features'],
            'limits' => $limits['limits'],
            'qos' => $limits['qos'],
            'connection_limit' => SaaSService::connectionLimit($actor),
            'global_connection_ceiling' => SaaSService::globalConnectionCeiling(),
        ]);
    }

    /** POST /api/saas/billing/webhook/{provider} */
    public static function webhook(array $params): void
    {
        $provider = (string) ($params['provider'] ?? '');
        if (!in_array($provider, array_keys(BillingService::providers()), true)) {
            json_out(['ok' => false, 'error' => 'unknown provider'], 404);
        }
        $payload = file_get_contents('php://input');
        if ($payload === false || $payload === '') {
            json_out(['ok' => false, 'error' => 'empty body'], 400);
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $res = BillingService::handleWebhook($provider, $payload, $headers);
        if (empty($res['ok'])) {
            json_out(['ok' => false, 'error' => $res['error'] ?? 'webhook failed'], 400);
        }
        json_out(['ok' => true, 'event_id' => $res['event_id'] ?? '']);
    }
}

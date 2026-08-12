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
 * PayPalDriver — Subscriptions (Billing Plans → Subscribe) + webhooks.
 *
 * Config (via Admin → SaaS → Billing): paypal_client_id, paypal_client_secret,
 * paypal_webhook_id, paypal_mode (sandbox|live). Webhook events are verified
 * through PayPal's verify-webhook-signature API (not a shared secret).
 *
 * The subscription's custom_id carries the LVChat user id so ACTIVATED and
 * PAYMENT.SALE.COMPLETED events can be attributed without extra lookups.
 */
final class PayPalDriver implements SaasProviderDriver
{
    public function name(): string
    {
        return 'paypal';
    }

    public function configured(): bool
    {
        return trim((string) (config_get('paypal_client_id', '') ?? '')) !== ''
            && trim((string) (config_get('paypal_client_secret', '') ?? '')) !== '';
    }

    private static function base(): string
    {
        return ((string) (config_get('paypal_mode', 'sandbox') ?? 'sandbox')) === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private static function authHeader(): string
    {
        $id = (string) (config_get('paypal_client_id', '') ?? '');
        $secret = (string) (config_get('paypal_client_secret', '') ?? '');
        return 'Basic ' . base64_encode($id . ':' . $secret);
    }

    private static function accessToken(): ?string
    {
        $res = BillingService::httpJson('POST', self::base() . '/v1/oauth2/token', 'grant_type=client_credentials', [
            'Authorization: ' . self::authHeader(),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $body = json_decode($res['body'], true);
        return is_string($body['access_token'] ?? null) && $body['access_token'] !== '' ? (string) $body['access_token'] : null;
    }

    public function createCheckout(array $plan, array $user, string $returnUrl): array
    {
        $token = self::accessToken();
        if (!$token) {
            return ['ok' => false, 'error' => 'PayPal auth failed (check client id/secret).'];
        }
        $auth = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

        $planId = (int) ($plan['id'] ?? 0);
        $name = mb_substr((string) ($plan['name'] ?? 'Plan'), 0, 120);
        $amount = max(0.5, ((int) ($plan['price_amount'] ?? 0)) / 100);
        $currency = strtoupper(SaaSService::currency());
        $cycle = ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'YEAR' : 'MONTH';

        // Reuse stored provider ids when present; otherwise create product+plan.
        $pids = json_decode((string) ($plan['provider_ids'] ?? '{}'), true) ?: [];
        $pp = is_array($pids['paypal'] ?? null) ? $pids['paypal'] : [];
        $productId = is_string($pp['product_id'] ?? null) && $pp['product_id'] !== '' ? $pp['product_id'] : null;
        $billingPlanId = is_string($pp['plan_id'] ?? null) && $pp['plan_id'] !== '' ? $pp['plan_id'] : null;

        if (!$productId) {
            $res = BillingService::httpJson('POST', self::base() . '/v1/catalog/products', json_encode([
                'name' => $name,
                'type' => 'SERVICE',
                'description' => 'LVChat ' . $name . ' plan',
            ]), $auth);
            $body = json_decode($res['body'], true);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return ['ok' => false, 'error' => 'PayPal product error: ' . (string) ($body['message'] ?? $res['body'])];
            }
            $productId = (string) ($body['id'] ?? '');
            $pp['product_id'] = $productId;
        }

        if (!$billingPlanId) {
            $res = BillingService::httpJson('POST', self::base() . '/v1/billing/plans', json_encode([
                'product_id' => $productId,
                'name' => $name . ' (' . $cycle . ')',
                'billing_cycles' => [[
                    'frequency' => ['interval_unit' => $cycle, 'interval_count' => '1'],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => number_format($amount, 2, '.', ''),
                            'currency_code' => $currency,
                        ],
                    ],
                ]],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => '1',
                ],
            ]), $auth);
            $body = json_decode($res['body'], true);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return ['ok' => false, 'error' => 'PayPal plan error: ' . (string) ($body['message'] ?? $res['body'])];
            }
            $billingPlanId = (string) ($body['id'] ?? '');
            $pp['plan_id'] = $billingPlanId;
        }

        // Remember created provider ids on the plan so we don't recreate them.
        if ($pids['paypal'] !== $pp) {
            $pids['paypal'] = $pp;
            Database::query('UPDATE saas_plans SET provider_ids = ?, updated_at = datetime("now") WHERE id = ?', [json_encode($pids), $planId]);
        }

        $siteName = (string) (config_get('site_name', 'LVChat') ?? 'LVChat');
        $res = BillingService::httpJson('POST', self::base() . '/v1/billing/subscriptions', json_encode([
            'plan_id' => $billingPlanId,
            'custom_id' => (string) (int) ($user['id'] ?? 0),
            'application_context' => [
                'brand_name' => mb_substr($siteName, 0, 127),
                'return_url' => $returnUrl . '&provider=paypal',
                'cancel_url' => $returnUrl,
                'user_action' => 'SUBSCRIBE_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ]), $auth);
        $body = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => 'PayPal subscribe error: ' . (string) ($body['message'] ?? $res['body'])];
        }
        $approveUrl = '';
        foreach ((array) ($body['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'approve') {
                $approveUrl = (string) ($link['href'] ?? '');
            }
        }
        return [
            'ok' => true,
            'url' => $approveUrl,
            'session_id' => (string) ($body['id'] ?? ''),
        ];
    }

    public function handleWebhook(string $payload, array $headers): array
    {
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return ['ok' => false, 'error' => 'malformed event'];
        }
        $eventId = (string) $event['id'];
        $type = (string) $event['event_type'];
        if (!self::verifyWebhook($event, $headers)) {
            return ['ok' => false, 'error' => 'bad signature'];
        }
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $subId = (string) ($res['id'] ?? $res['billing_agreement_id'] ?? '');

        switch ($type) {
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $data = [
                    'user_id' => (int) ($res['custom_id'] ?? 0),
                    'plan_id' => (int) (self::planIdFromSubscription($subId, $res['plan_id'] ?? null)),
                    'provider_sub_id' => $subId,
                    'payment_id' => $subId . '-activated',
                    'amount' => (int) round(((float) ($res['plan']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] ?? 0)) * 100),
                    'currency' => (string) ($res['plan']['billing_cycles'][0]['pricing_scheme']['fixed_price']['currency_code'] ?? SaaSService::currency()),
                    'period_end' => null,
                ];
                return ['ok' => true, 'event_id' => $eventId, 'event_type' => 'paid', 'data' => $data];
            case 'PAYMENT.SALE.COMPLETED':
                return [
                    'ok' => true,
                    'event_id' => $eventId,
                    'event_type' => 'paid',
                    'data' => [
                        'user_id' => 0,
                        'plan_id' => 0,
                        'provider_sub_id' => (string) ($res['billing_agreement_id'] ?? ''),
                        'payment_id' => (string) ($res['id'] ?? $eventId),
                        'amount' => (int) round(((float) ($res['amount']['total'] ?? 0)) * 100),
                        'currency' => (string) ($res['amount']['currency_code'] ?? SaaSService::currency()),
                        'period_end' => null,
                    ],
                ];
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                return [
                    'ok' => true,
                    'event_id' => $eventId,
                    'event_type' => 'cancelled',
                    'data' => ['provider_sub_id' => $subId],
                ];
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                return [
                    'ok' => true,
                    'event_id' => $eventId,
                    'event_type' => 'grace',
                    'data' => ['provider_sub_id' => $subId],
                ];
        }
        return ['ok' => true, 'event_id' => $eventId, 'event_type' => 'ignored'];
    }

    /** Resolve the LVChat plan id for a subscription (custom_id → user's plan). */
    private static function planIdFromSubscription(string $subId, mixed $fallback): int
    {
        $userId = (int) (Database::scalar(
            'SELECT user_id FROM saas_user_plans WHERE provider = "paypal" AND provider_sub_id = ?',
            [$subId]
        ) ?: 0);
        if ($userId > 0) {
            return (int) (Database::scalar('SELECT plan_id FROM saas_user_plans WHERE user_id = ?', [$userId]) ?: 0);
        }
        return (int) ($fallback ?? 0);
    }

    /** PayPal webhook signature verification via verify-webhook-signature API. */
    private static function verifyWebhook(array $event, array $headers): bool
    {
        $webhookId = (string) (config_get('paypal_webhook_id', '') ?? '');
        if ($webhookId === '') {
            return false;
        }
        $token = self::accessToken();
        if (!$token) {
            return false;
        }
        $res = BillingService::httpJson('POST', self::base() . '/v1/notifications/verify-webhook-signature', json_encode([
            'auth_algo' => (string) ($headers['paypal-auth-algo'] ?? ''),
            'cert_url' => (string) ($headers['paypal-cert-url'] ?? ''),
            'transmission_id' => (string) ($headers['paypal-transmission-id'] ?? ''),
            'transmission_sig' => (string) ($headers['paypal-transmission-sig'] ?? ''),
            'transmission_time' => (string) ($headers['paypal-transmission-time'] ?? ''),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]), ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        $body = json_decode($res['body'], true);
        return (string) ($body['verification_status'] ?? '') === 'SUCCESS';
    }
}

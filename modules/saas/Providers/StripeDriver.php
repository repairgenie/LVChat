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
 * StripeDriver — Checkout Sessions (subscriptions) + webhooks + transfers.
 *
 * Secrets come from server_config (written via Admin → SaaS → Billing, the
 * write-only pattern used for the LiveKit key): stripe_secret_key,
 * stripe_webhook_secret, stripe_publishable_key.
 *
 * Platform fee routing: when SAAS_PLATFORM_FEE_DESTINATION (a Stripe Connect
 * account id) is set in the deployment .env, a Transfer for the fee is created
 * against the settled charge so the developer is paid separately from the
 * network's own balance.
 */
final class StripeDriver implements SaasProviderDriver
{
    public function name(): string
    {
        return 'stripe';
    }

    public function configured(): bool
    {
        return trim((string) (config_get('stripe_secret_key', '') ?? '')) !== '';
    }

    private static function secretKey(): string
    {
        return trim((string) (config_get('stripe_secret_key', '') ?? ''));
    }

    private static function apiBase(): string
    {
        return 'https://api.stripe.com/v1';
    }

    private static function authHeaders(): array
    {
        return ['Authorization: Bearer ' . self::secretKey(), 'Content-Type: application/x-www-form-urlencoded'];
    }

    public function createCheckout(array $plan, array $user, string $returnUrl): array
    {
        $planId = (int) ($plan['id'] ?? 0);
        $amount = max(50, (int) ($plan['price_amount'] ?? 0));
        $cycle = ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'year' : 'month';
        $currency = SaaSService::currency();

        // Prefer a pre-created Price (provider_ids.stripe.<monthly|yearly>) when
        // the admin configured one; otherwise use inline price_data.
        $pids = json_decode((string) ($plan['provider_ids'] ?? '{}'), true) ?: [];
        $stripe = is_array($pids['stripe'] ?? null) ? $pids['stripe'] : [];
        $price = is_string($stripe[$cycle] ?? null) && $stripe[$cycle] !== '' ? $stripe[$cycle] : null;
        if ($price) {
            $lineItem = ['price' => $price, 'quantity' => '1'];
        } else {
            $lineItem = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (string) $amount,
                    'product_data' => ['name' => mb_substr((string) ($plan['name'] ?? 'Plan'), 0, 120)],
                    'recurring' => ['interval' => $cycle],
                ],
                'quantity' => '1',
            ];
        }

        $data = [
            'mode' => 'subscription',
            'line_items[0]' => $lineItem, // array expanded below
            'success_url' => $returnUrl . '&session={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl,
            'metadata[user_id]' => (string) (int) ($user['id'] ?? 0),
            'metadata[plan_id]' => (string) $planId,
            'metadata[provider]' => 'stripe',
            'allow_promotion_codes' => 'true',
        ];
        unset($data['line_items[0]']);
        $res = BillingService::httpJson('POST', self::apiBase() . '/checkout/sessions', self::flattenLineItem($lineItem, $data), self::authHeaders());
        $body = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => 'Stripe error: ' . (string) ($body['error']['message'] ?? $res['body'])];
        }
        return [
            'ok' => true,
            'url' => (string) ($body['url'] ?? ''),
            'session_id' => (string) ($body['id'] ?? ''),
        ];
    }

    public function handleWebhook(string $payload, array $headers): array
    {
        if (!self::verifySignature($payload, $headers)) {
            return ['ok' => false, 'error' => 'bad signature'];
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return ['ok' => false, 'error' => 'malformed event'];
        }
        $eventId = (string) $event['id'];
        $type = (string) $event['type'];
        $obj = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        if ($type === 'checkout.session.completed') {
            if (($obj['payment_status'] ?? '') !== 'paid') {
                return ['ok' => true, 'event_id' => $eventId, 'event_type' => 'ignored'];
            }
            $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
            return [
                'ok' => true,
                'event_id' => $eventId,
                'event_type' => 'paid',
                'data' => [
                    'user_id' => (int) ($meta['user_id'] ?? 0),
                    'plan_id' => (int) ($meta['plan_id'] ?? 0),
                    'provider_sub_id' => (string) ($obj['subscription'] ?? ''),
                    'payment_id' => (string) ($obj['payment_intent'] ?? ''),
                    'payment_intent' => (string) ($obj['payment_intent'] ?? ''),
                    'amount' => (int) ($obj['amount_total'] ?? 0),
                    'currency' => (string) ($obj['currency'] ?? SaaSService::currency()),
                    'period_end' => null,
                ],
            ];
        }
        if ($type === 'invoice.paid') {
            $subId = (string) ($obj['subscription'] ?? '');
            $intent = (string) ($obj['payment_intent'] ?? '');
            $charge = (string) ($obj['charge'] ?? '');
            $amount = (int) ($obj['amount_paid'] ?? 0);
            $currency = (string) ($obj['currency'] ?? SaaSService::currency());
            $periodEnd = isset($obj['lines']) && is_array($obj['lines']['data'] ?? null)
                ? self::periodEndFromLines($obj['lines']['data'])
                : null;
            return [
                'ok' => true,
                'event_id' => $eventId,
                'event_type' => 'paid',
                'data' => [
                    'user_id' => 0, // resolved below via subscription
                    'plan_id' => 0,
                    'provider_sub_id' => $subId,
                    'payment_id' => $intent !== '' ? $intent : $charge,
                    'payment_intent' => $intent,
                    'charge_id' => $charge,
                    'amount' => $amount,
                    'currency' => $currency,
                    'period_end' => $periodEnd,
                ],
            ];
        }
        if ($type === 'invoice.payment_failed') {
            return [
                'ok' => true,
                'event_id' => $eventId,
                'event_type' => 'grace',
                'data' => ['provider_sub_id' => (string) ($obj['subscription'] ?? '')],
            ];
        }
        if ($type === 'customer.subscription.deleted'
            || ($type === 'customer.subscription.updated' && ($obj['status'] ?? '') === 'canceled')) {
            return [
                'ok' => true,
                'event_id' => $eventId,
                'event_type' => 'cancelled',
                'data' => ['provider_sub_id' => (string) ($obj['id'] ?? '')],
            ];
        }
        return ['ok' => true, 'event_id' => $eventId, 'event_type' => 'ignored'];
    }

    /** stripe.webhooks signature verification (v1, HMAC-SHA256). */
    private static function verifySignature(string $payload, array $headers): bool
    {
        $secret = (string) (config_get('stripe_webhook_secret', '') ?? '');
        $sig = (string) ($headers['stripe-signature'] ?? '');
        if ($secret === '' || $sig === '') {
            return false;
        }
        $ts = '';
        $expectedSig = '';
        foreach (explode(',', $sig) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $ts = $v;
            } elseif ($k === 'v1') {
                $expectedSig = $v;
            }
        }
        if ($ts === '' || $expectedSig === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        return hash_equals($expected, $expectedSig);
    }

    /** Period end from the first recurring subscription item's period.end. */
    private static function periodEndFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (!is_array($line) || empty($line['period']['end'])) {
                continue;
            }
            return gmdate('Y-m-d H:i:s', (int) $line['period']['end']);
        }
        return null;
    }

    /**
     * Transfer the platform fee to the developer's Connect account. Tries to
     * anchor the transfer to the settled charge (source_transaction) so it
     * comes from this payment, not the general balance.
     */
    public static function transfer(int $amountCents, string $currency, string $destination, ?string $chargeId = null, ?string $paymentIntentId = null): array
    {
        $chargeId = $chargeId ?: self::latestCharge($paymentIntentId);
        $data = [
            'amount' => (string) $amountCents,
            'currency' => $currency,
            'destination' => $destination,
        ];
        if ($chargeId !== '') {
            $data['source_transaction'] = $chargeId;
        }
        $res = BillingService::httpJson('POST', self::apiBase() . '/transfers', $data, self::authHeaders());
        $body = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => (string) ($body['error']['message'] ?? $res['body'])];
        }
        return ['ok' => true, 'transfer_id' => (string) ($body['id'] ?? '')];
    }

    private static function latestCharge(?string $paymentIntentId): string
    {
        if ($paymentIntentId === null || $paymentIntentId === '') {
            return '';
        }
        $res = BillingService::httpJson(
            'GET',
            self::apiBase() . '/payment_intents/' . rawurlencode($paymentIntentId),
            null,
            ['Authorization: Bearer ' . self::secretKey()]
        );
        if ($res['status'] !== 200) {
            return '';
        }
        $body = json_decode($res['body'], true);
        return (string) ($body['latest_charge'] ?? '');
    }

    /** Expand a single line-item array into Stripe form-encoded flat keys. */
    private static function flattenLineItem(array $item, array $data): array
    {
        $walk = function (string $prefix, mixed $value) use (&$walk, &$out): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($prefix . '[' . $k . ']', $v);
                }
                return;
            }
            $out[$prefix] = (string) $value;
        };
        $out = $data;
        foreach ($item as $k => $v) {
            $walk('line_items[0][' . $k . ']', $v);
        }
        return $out;
    }
}

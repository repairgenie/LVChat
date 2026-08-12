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
 * BTCPayDriver — invoice-per-cycle payments against a BTCPay Server store.
 *
 * Config (via Admin → SaaS → Billing): btcpay_url (e.g. https://btcpay.example),
 * btcpay_api_key, btcpay_store_id, btcpay_webhook_secret.
 *
 * BTCPay has no native auto-renewing subscriptions, so each billing cycle is a
 * fresh invoice. Every settled invoice extends the current period; a user who
 * isn't on the plan yet gets it activated. Webhooks are authenticated with the
 * store webhook secret (BTCPay-Sig: sha256=<hmac>).
 */
final class BTCPayDriver implements SaasProviderDriver
{
    public function name(): string
    {
        return 'btcpay';
    }

    public function configured(): bool
    {
        return trim((string) (config_get('btcpay_url', '') ?? '')) !== ''
            && trim((string) (config_get('btcpay_api_key', '') ?? '')) !== ''
            && trim((string) (config_get('btcpay_store_id', '') ?? '')) !== '';
    }

    private static function base(): string
    {
        return rtrim((string) (config_get('btcpay_url', '') ?? ''), '/');
    }

    private static function storeId(): string
    {
        return trim((string) (config_get('btcpay_store_id', '') ?? ''));
    }

    public function createCheckout(array $plan, array $user, string $returnUrl): array
    {
        $store = self::storeId();
        $planId = (int) ($plan['id'] ?? 0);
        $amount = max(0.5, ((int) ($plan['price_amount'] ?? 0)) / 100);
        $currency = strtoupper(SaaSService::currency());
        $res = BillingService::httpJson('POST', self::base() . '/api/v1/stores/' . rawurlencode($store) . '/invoices', json_encode([
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'metadata' => [
                'user_id' => (string) (int) ($user['id'] ?? 0),
                'plan_id' => (string) $planId,
            ],
            'checkout' => [
                'redirectURL' => $returnUrl,
                'defaultLanguage' => 'en',
            ],
        ]), [
            'Authorization: token ' . (string) (config_get('btcpay_api_key', '') ?? ''),
            'Content-Type: application/json',
        ]);
        $body = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => 'BTCPay error: ' . (string) ($body['message'] ?? $body['title'] ?? $res['body'])];
        }
        return [
            'ok' => true,
            'url' => (string) ($body['checkoutLink'] ?? ''),
            'session_id' => (string) ($body['id'] ?? ''),
        ];
    }

    public function handleWebhook(string $payload, array $headers): array
    {
        $secret = (string) (config_get('btcpay_webhook_secret', '') ?? '');
        if ($secret !== '' && !self::verifySignature($payload, (string) ($headers['btcpay-sig'] ?? ''), $secret)) {
            return ['ok' => false, 'error' => 'bad signature'];
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['deliveryId'])) {
            return ['ok' => false, 'error' => 'malformed event'];
        }
        $eventId = (string) $event['deliveryId'];
        $type = (string) ($event['type'] ?? '');
        $invoice = is_array($event['invoice'] ?? null) ? $event['invoice'] : [];

        if ($type === 'InvoiceSettled') {
            $meta = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
            $amount = 0;
            $currency = SaaSService::currency();
            foreach ((array) ($invoice['cryptoPaid'] ?? []) as $info) {
                $amount = (int) round((float) ($info['due'] ?? $info['paid'] ?? 0));
            }
            if ($amount <= 0) {
                $amount = (int) round((float) ($invoice['amount'] ?? 0) * 100);
            }
            if (!empty($invoice['currency'])) {
                $currency = strtoupper((string) $invoice['currency']);
            }
            return [
                'ok' => true,
                'event_id' => $eventId,
                'event_type' => 'paid',
                'data' => [
                    'user_id' => (int) ($meta['user_id'] ?? 0),
                    'plan_id' => (int) ($meta['plan_id'] ?? 0),
                    'provider_sub_id' => (string) ($invoice['id'] ?? $eventId),
                    'payment_id' => (string) ($invoice['id'] ?? $eventId),
                    'amount' => $amount,
                    'currency' => $currency,
                    'period_end' => null,
                ],
            ];
        }
        return ['ok' => true, 'event_id' => $eventId, 'event_type' => 'ignored'];
    }

    private static function verifySignature(string $payload, string $header, string $secret): bool
    {
        if (!preg_match('#^sha256=([0-9a-f]{64})$#i', trim($header), $m)) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals(strtolower($expected), strtolower($m[1]));
    }
}

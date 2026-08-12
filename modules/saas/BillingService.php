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
 * BillingService — payment-provider plumbing for the SaaS module.
 *
 * Provider drivers (Stripe / PayPal / BTCPay) implement SaasProviderDriver.
 * Webhooks flow: BillingController POST /api/saas/billing/webhook/{provider}
 * → BillingService::handleWebhook (idempotency + dispatch) → SaaSService
 * lifecycle (assign/renew/grace/downgrade) + payment ledger + platform fee.
 *
 * The platform fee (default $0.75) is a developer support fee on every paid
 * transaction. It is configured entirely from the deployment .env file and
 * cannot be disabled by admins (see SaaSService::platformFeeEnabled).
 */
final class BillingService
{
    /** @return array<string,string> provider id => label */
    public static function providers(): array
    {
        return ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'btcpay' => 'BTCPay Server'];
    }

    public static function configured(string $provider): bool
    {
        $d = self::driver($provider);
        return $d !== null && $d->configured();
    }

    public static function driver(string $provider): ?SaasProviderDriver
    {
        return self::drivers()[$provider] ?? null;
    }

    /** @return array<string, SaasProviderDriver> */
    public static function drivers(): array
    {
        static $drivers = null;
        if ($drivers === null) {
            $drivers = [
                'stripe' => new StripeDriver(),
                'paypal' => new PayPalDriver(),
                'btcpay' => new BTCPayDriver(),
            ];
        }
        return $drivers;
    }

    /** Provider webhook URL admins paste into their provider dashboard. */
    public static function webhookUrl(string $provider): string
    {
        return base_url() . '/api/saas/billing/webhook/' . rawurlencode($provider);
    }

    /** Create a self-serve checkout. Returns ['ok','url','session_id'] | error. */
    public static function createCheckout(array $plan, array $user, string $provider): array
    {
        $d = self::driver($provider);
        if (!$d) {
            return ['ok' => false, 'error' => 'Unknown payment provider.'];
        }
        if (!$d->configured()) {
            return ['ok' => false, 'error' => 'That payment provider is not configured on this server.'];
        }
        $returnUrl = base_url() . '/billing/return?provider=' . rawurlencode($provider);
        return $d->createCheckout($plan, $user, $returnUrl);
    }

    /**
     * Handle a provider webhook. Signature-verifies, dedupes by event id, then
     * applies the lifecycle action. Returns an array for the controller.
     */
    public static function handleWebhook(string $provider, string $payload, array $headers): array
    {
        $d = self::driver($provider);
        if (!$d) {
            return ['ok' => false, 'error' => 'Unknown provider.'];
        }
        $res = $d->handleWebhook($payload, $headers);
        if (empty($res['ok'])) {
            return $res;
        }
        $eventId = (string) ($res['event_id'] ?? '');
        $eventType = (string) ($res['event_type'] ?? '');
        if (!SaaSService::recordEvent($provider, $eventId, $eventType)) {
            return ['ok' => true, 'idempotent' => true, 'event_id' => $eventId];
        }
        self::applyEvent($provider, $eventType, (array) ($res['data'] ?? []));
        return ['ok' => true, 'event_id' => $eventId, 'event_type' => $eventType];
    }

    /**
     * Turn a semantic webhook event into lifecycle + ledger actions.
     * Event types (normalized by the drivers):
     *   paid      — a payment settled (first checkout OR a renewal)
     *   grace     — a payment failed; start the grace period
     *   cancelled — the subscription was cancelled; downgrade
     */
    private static function applyEvent(string $provider, string $eventType, array $data): void
    {
        if ($eventType === 'paid') {
            $userId = (int) ($data['user_id'] ?? 0);
            $planId = (int) ($data['plan_id'] ?? 0);
            $subId = $data['provider_sub_id'] ?? null;
            $periodEnd = $data['period_end'] ?? null;
            $amount = (int) ($data['amount'] ?? 0);
            $currency = (string) ($data['currency'] ?? SaaSService::currency());
            $paymentId = (string) ($data['payment_id'] ?? '');

            // Provider renewals (Stripe invoice.paid, PayPal sale.completed)
            // carry only the subscription id — attribute via the assignment.
            if ($userId <= 0 && is_string($subId) && $subId !== '') {
                $sub = Database::row(
                    'SELECT user_id, plan_id FROM saas_user_plans WHERE provider = ? AND provider_sub_id = ?',
                    [$provider, $subId]
                );
                if ($sub) {
                    $userId = (int) $sub['user_id'];
                    $planId = (int) $sub['plan_id'];
                }
            }
            if ($userId <= 0 || $planId <= 0) {
                return;
            }

            if ($paymentId !== '') {
                SaaSService::recordPayment($userId, $planId, $provider, $paymentId, $amount, $currency, 'paid');
                self::capturePlatformFee($provider, $userId, $planId, $amount, $paymentId, $data);
            }

            $existing = SaaSService::activeAssignment($userId);
            if ($existing && ($subId === null || (string) $existing['provider_sub_id'] === (string) $subId)) {
                SaaSService::renew($userId, $periodEnd);
            } else {
                SaaSService::assignPlan($userId, $planId, 'self', [
                    'provider' => $provider,
                    'provider_sub_id' => $subId,
                    'period_end' => $periodEnd,
                    'auto_renew' => true,
                ]);
            }
            return;
        }

        $userId = self::userIdForSubscription($provider, $data);
        if ($userId <= 0) {
            return;
        }
        if ($eventType === 'grace') {
            SaaSService::enterGrace($userId, $provider . ' payment failed');
        } elseif ($eventType === 'cancelled') {
            SaaSService::downgrade($userId, $provider . ' subscription cancelled');
        }
    }

    /** Resolve a user from a subscription id on the user's current assignment. */
    private static function userIdForSubscription(string $provider, array $data): int
    {
        $subId = (string) ($data['provider_sub_id'] ?? '');
        if ($subId === '') {
            return 0;
        }
        return (int) (Database::scalar(
            'SELECT user_id FROM saas_user_plans WHERE provider = ? AND provider_sub_id = ?',
            [$provider, $subId]
        ) ?: 0);
    }

    /**
     * Capture the platform fee (default $0.75) for a paid transaction.
     * Ledger row + provider payout routing when a destination is configured.
     */
    public static function capturePlatformFee(string $provider, int $userId, int $planId, int $paymentAmountCents, string $paymentId, array $opts = []): array
    {
        if (!SaaSService::platformFeeEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $fee = SaaSService::platformFeeAmountCents();
        if ($fee <= 0) {
            return ['ok' => false, 'reason' => 'zero'];
        }
        $fee = min($fee, max(0, $paymentAmountCents));
        if ($fee <= 0) {
            return ['ok' => false, 'reason' => 'amount'];
        }
        if (SaaSService::platformFeeCaptured($provider, $paymentId)) {
            return ['ok' => true, 'reason' => 'already'];
        }
        $currency = SaaSService::platformFeeCurrency();
        $detail = 'platform fee';
        $status = 'recorded';
        $dest = SaaSService::platformFeeDestination();

        if ($provider === 'stripe') {
            if ($dest !== '') {
                $transfer = StripeDriver::transfer($fee, $currency, $dest, $opts['charge_id'] ?? null, $opts['payment_intent'] ?? null);
                if ($transfer['ok']) {
                    $status = 'paid';
                    $detail .= ' transferred to ' . $dest . ' (transfer ' . $transfer['transfer_id'] . ')';
                } else {
                    $detail .= ' transfer to ' . $dest . ' failed: ' . $transfer['error'];
                }
            } else {
                $detail .= ' (no payout destination configured — recorded only)';
            }
        } elseif ($provider === 'paypal') {
            $detail .= ' (PayPal partner-fee routing not configured — recorded only)';
        } else {
            $detail .= ' (BTCPay payout routing not configured — recorded only)';
        }

        SaaSService::recordPlatformFee($userId, $planId, $provider, $paymentId, $fee, $currency, $status, $detail);
        return ['ok' => true, 'fee' => $fee, 'status' => $status];
    }

    /**
     * Minimal JSON HTTP client (curl with stream fallback). Returns
     * ['status' => int, 'body' => string].
     */
    public static function httpJson(string $method, string $url, array|string|null $body = null, array $headers = [], int $timeout = 20): array
    {
        $payload = is_array($body) ? http_build_query($body) : (string) $body;
        $method = strtoupper($method);
        $curl = function_exists('curl_init');
        if ($curl) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if ($method !== 'GET' && $payload !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $body2 = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => is_string($body2) ? $body2 : ''];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $method !== 'GET' ? $payload : null,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body2 = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ((array) ($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => is_string($body2) ? $body2 : ''];
    }
}

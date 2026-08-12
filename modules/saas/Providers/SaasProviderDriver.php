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



/**
 * Contract every payment-provider driver implements. Drivers are pure static
 * services in the house style; the instance is a thin holder so BillingService
 * can swap them without globals.
 */
interface SaasProviderDriver
{
    /** Canonical provider id ('stripe' | 'paypal' | 'btcpay'). */
    public function name(): string;

    /** Whether the provider keys are configured for this install. */
    public function configured(): bool;

    /**
     * Create a checkout/session for a plan. Returns
     * ['ok' => true, 'url' => string, 'session_id' => string] or
     * ['ok' => false, 'error' => string].
     *
     * @param array{id:int,name:string,price_amount:int,billing_cycle:string} $plan
     * @param array{id:int,email?:string,username:string} $user
     */
    public function createCheckout(array $plan, array $user, string $returnUrl): array;

    /**
     * Handle an incoming provider webhook. Returns
     * ['ok' => true, 'event_id' => string, 'action' => string, 'message' => string]
     * or ['ok' => false, 'error' => string]. Signature verification happens
     * here; the BillingController records idempotency + dispatches.
     *
     * @param string      $payload raw request body
     * @param array<string,string> $headers header name => value (lowercased keys)
     */
    public function handleWebhook(string $payload, array $headers): array;
}

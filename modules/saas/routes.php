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
 * SaaS — module routes. Wired by ModuleLoader::wireRoutes() after the core
 * routes (core always wins on a path collision). See docs/modules.md.
 */

return static function (Router $router): void {
    // Admin: plans, per-user assignment + overrides, provider config.
    $router->get('/admin/saas', [AdminSaaSController::class, 'plans']);
    $router->post('/admin/saas/plan/save', [AdminSaaSController::class, 'planSave']);
    $router->post('/admin/saas/plan/delete', [AdminSaaSController::class, 'planDelete']);
    $router->post('/admin/saas/plan/toggle', [AdminSaaSController::class, 'planToggle']);
    $router->get('/admin/saas/users', [AdminSaaSController::class, 'users']);
    $router->post('/admin/saas/users/save', [AdminSaaSController::class, 'userSave']);
    $router->post('/admin/saas/users/cancel', [AdminSaaSController::class, 'userCancel']);
    $router->get('/admin/saas/billing', [AdminSaaSController::class, 'billing']);
    $router->post('/admin/saas/billing/save', [AdminSaaSController::class, 'billingSave']);

    // User-facing billing portal + self-serve checkout.
    $router->get('/billing', [BillingController::class, 'portal']);
    $router->post('/billing/checkout', [BillingController::class, 'checkout']);
    $router->get('/billing/return', [BillingController::class, 'checkoutReturn']);
    $router->post('/billing/cancel', [BillingController::class, 'cancel']);

    // Client entitlement snapshot (the UI hides/limits features from it).
    $router->get('/api/saas/entitlements', [BillingController::class, 'entitlements']);

    // Provider webhooks (Stripe / PayPal / BTCPay). Public: they authenticate
    // via provider signatures, not the app session.
    $router->post('/api/saas/billing/webhook/{provider}', [BillingController::class, 'webhook']);
};

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

$title = 'SaaS · Billing';
$active = 'saas-billing';
$s = $settings;
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">SaaS — Billing</h1>
  <span class="text-xs text-discord-400 font-mono">module <?= h($module['version'] ?? '') ?> · saas</span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 max-w-6xl">
  <form method="post" action="/admin/saas/billing/save" class="card p-6 space-y-5 xl:col-span-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/saas/billing">

    <div class="flex items-center justify-between card p-4">
      <div>
        <div class="text-sm font-medium text-white">Enable SaaS metering</div>
        <div class="text-xs text-discord-400 mt-0.5">Off = plans/limits are not enforced anywhere (install behaves as before)</div>
      </div>
      <input type="checkbox" name="saas_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($s['saas_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="label">Grace days before downgrade</label>
        <input class="input font-mono text-xs" type="number" min="0" max="90" name="saas_grace_days" value="<?= h($s['saas_grace_days'] ?? '3') ?>">
      </div>
      <div>
        <label class="label">Global connection ceiling</label>
        <input class="input font-mono text-xs" type="number" min="1" name="saas_max_connections_global" value="<?= h($s['saas_max_connections_global'] ?? '200') ?>">
        <p class="text-xs text-discord-400 mt-1">Hard total concurrent WS cap — never exceeded, even by "unlimited" plans.</p>
      </div>
      <div>
        <label class="label">Checkout currency</label>
        <input class="input font-mono text-xs" name="saas_checkout_currency" value="<?= h($s['saas_checkout_currency'] ?? 'usd') ?>" placeholder="usd">
      </div>
    </div>

    <div class="pt-2 border-t border-discord-800">
      <div class="text-sm font-medium text-white mb-3">Stripe</div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="label">Publishable key</label>
          <input class="input font-mono text-xs" name="stripe_publishable_key" value="<?= h($s['stripe_publishable_key'] ?? '') ?>" spellcheck="false" autocomplete="off">
        </div>
        <div>
          <label class="label">Secret key</label>
          <input class="input font-mono text-xs" name="stripe_secret_key" type="password" placeholder="<?= !empty($s['stripe_secret_key_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div>
          <label class="label">Webhook signing secret</label>
          <input class="input font-mono text-xs" name="stripe_webhook_secret" type="password" placeholder="<?= !empty($s['stripe_webhook_secret_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
      </div>
      <div class="text-xs text-discord-400 mt-2">
        Webhook endpoint: <code class="text-sky-300"><?= h($webhook_urls['stripe']) ?></code> — subscribe to
        <code>checkout.session.completed</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code>,
        <code>customer.subscription.deleted</code>, <code>customer.subscription.updated</code>.
      </div>
    </div>

    <div class="pt-2 border-t border-discord-800">
      <div class="text-sm font-medium text-white mb-3">PayPal</div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="label">Client ID</label>
          <input class="input font-mono text-xs" name="paypal_client_id" value="<?= h($s['paypal_client_id'] ?? '') ?>" spellcheck="false" autocomplete="off">
        </div>
        <div>
          <label class="label">Client secret</label>
          <input class="input font-mono text-xs" name="paypal_client_secret" type="password" placeholder="<?= !empty($s['paypal_client_secret_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div>
          <label class="label">Webhook ID</label>
          <input class="input font-mono text-xs" name="paypal_webhook_id" type="password" placeholder="<?= !empty($s['paypal_webhook_id_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div>
          <label class="label">Mode</label>
          <select class="input" name="paypal_mode">
            <option value="sandbox" <?= ($s['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>sandbox</option>
            <option value="live" <?= ($s['paypal_mode'] ?? '') === 'live' ? 'selected' : '' ?>>live</option>
          </select>
        </div>
      </div>
      <div class="text-xs text-discord-400 mt-2">
        Webhook endpoint: <code class="text-sky-300"><?= h($webhook_urls['paypal']) ?></code> — subscribe to
        <code>BILLING.SUBSCRIPTION.ACTIVATED</code>, <code>PAYMENT.SALE.COMPLETED</code>,
        <code>BILLING.SUBSCRIPTION.CANCELLED</code>, <code>BILLING.SUBSCRIPTION.SUSPENDED</code>.
      </div>
    </div>

    <div class="pt-2 border-t border-discord-800">
      <div class="text-sm font-medium text-white mb-3">BTCPay Server</div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="label">Server URL</label>
          <input class="input font-mono text-xs" name="btcpay_url" value="<?= h($s['btcpay_url'] ?? '') ?>" placeholder="https://btcpay.example" spellcheck="false" autocomplete="off">
        </div>
        <div>
          <label class="label">Store ID</label>
          <input class="input font-mono text-xs" name="btcpay_store_id" value="<?= h($s['btcpay_store_id'] ?? '') ?>" spellcheck="false" autocomplete="off">
        </div>
        <div>
          <label class="label">API key</label>
          <input class="input font-mono text-xs" name="btcpay_api_key" type="password" placeholder="<?= !empty($s['btcpay_api_key_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div class="md:col-span-3">
          <label class="label">Webhook secret (BTCPay-Sig)</label>
          <input class="input font-mono text-xs" name="btcpay_webhook_secret" type="password" placeholder="<?= !empty($s['btcpay_webhook_secret_set']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
          <div class="text-xs text-discord-400 mt-2">
            Webhook endpoint: <code class="text-sky-300"><?= h($webhook_urls['btcpay']) ?></code> — subscribe to
            <code>InvoiceSettled</code>. BTCPay has no auto-renew: each billing cycle is a fresh invoice that extends the period.
          </div>
        </div>
      </div>
    </div>

    <div class="pt-2">
      <button class="btn-primary">Save billing settings</button>
    </div>
  </form>

  <div class="space-y-5">
    <div class="card p-5">
      <div class="text-sm font-medium text-white mb-3">Platform fee (developer support)</div>
      <dl class="space-y-2 text-sm">
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Status</dt>
          <dd>
            <?php if ($fee['enabled']): ?>
              <span class="inline-flex items-center gap-1.5 text-green-400"><span class="w-2 h-2 rounded-full bg-green-400"></span> enabled</span>
            <?php else: ?>
              <span class="inline-flex items-center gap-1.5 text-red-400"><span class="w-2 h-2 rounded-full bg-red-400"></span> disabled</span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Fee per transaction</dt>
          <dd class="font-mono"><?= h(strtoupper($fee['currency'])) ?> <?= number_format($fee['amount'] / 100, 2) ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Payout destination</dt>
          <dd class="font-mono text-xs max-w-[14rem] truncate" title="<?= h($fee['destination']) ?>"><?= h($fee['destination'] ?: 'none (ledger only)') ?></dd>
        </div>
      </dl>
      <p class="text-xs text-discord-400 mt-3 leading-relaxed">
        Every paid transaction routes a small support fee to the software developer. This is configured in the
        deployment's <code class="text-sky-300">.env</code> file
        (<code>SAAS_PLATFORM_FEE</code>, <code>SAAS_PLATFORM_FEE_DESTINATION</code>) and cannot be disabled here.
      </p>
      <?php if ($fee['dev_mode']): ?>
        <form method="post" action="/admin/saas/billing/save" class="mt-4 pt-3 border-t border-discord-800">
          <?= Csrf::field() ?>
          <input type="hidden" name="back" value="/admin/saas/billing">
          <label class="flex items-center justify-between card p-3">
            <span class="text-xs text-discord-300">Developer mode: allow fee to be disabled</span>
            <input type="checkbox" name="saas_platform_fee_enabled" value="1" class="w-4 h-4 accent-blurple" <?= ($s['saas_platform_fee_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
          </label>
          <button class="btn-secondary px-3 py-1.5 text-xs mt-2">Save toggle</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card p-5">
      <div class="text-sm font-medium text-white mb-3">Provider status</div>
      <dl class="space-y-2 text-sm">
        <?php foreach ($providers as $id => $label): ?>
          <div class="flex items-center justify-between gap-3">
            <dt class="text-discord-400"><?= h($label) ?></dt>
            <dd>
              <?php if ($configured[$id]): ?>
                <span class="inline-flex items-center gap-1.5 text-green-400"><span class="w-2 h-2 rounded-full bg-green-400"></span> configured</span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 text-discord-500"><span class="w-2 h-2 rounded-full bg-discord-500"></span> not configured</span>
              <?php endif; ?>
            </dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </div>

    <div class="card p-5 text-sm">
      <div class="text-xs text-discord-400 leading-relaxed">
        <strong class="text-discord-200">Lifecycle:</strong> a failed payment moves the user into a grace period
        (configurable days), then the plan auto-downgrades to Free. Expiry is enforced lazily on every request.
      </div>
    </div>
  </div>
</div>

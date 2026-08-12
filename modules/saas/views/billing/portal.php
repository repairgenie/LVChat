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

?>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold text-white">Billing</h1>
    <a href="/app" class="btn-ghost text-xs">← Back to chat</a>
  </div>

  <?php if ($assignment): ?>
    <div class="card p-6 mb-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="text-sm text-discord-400">Current plan</div>
          <div class="text-xl font-bold text-white"><?= h($plan['name']) ?></div>
        </div>
        <div class="text-right text-xs text-discord-400">
          <div>status: <span class="<?= ($assignment['status'] ?? '') === 'grace' ? 'text-amber-400' : 'text-green-400' ?>"><?= h($assignment['status']) ?></span></div>
          <?php if (!empty($assignment['period_end'])): ?>
            <div>ends <?= h($assignment['period_end']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-4">
        <?php foreach ($entitlements['features'] as $key => $on): ?>
          <div class="card p-3 flex items-center justify-between">
            <span class="text-xs text-discord-300"><?= h(SaaSService::keyLabel($key)) ?></span>
            <span class="text-xs <?= $on ? 'text-green-400' : 'text-discord-500' ?>"><?= $on ? 'on' : 'off' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-xs text-discord-400 mt-3 space-y-1">
        <?php foreach ($entitlements['limits'] as $key => $val): ?>
          <div class="flex justify-between">
            <span><?= h(SaaSService::keyLabel($key)) ?></span>
            <span class="font-mono"><?= $val === null ? 'unlimited' : number_format((int) $val) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (($assignment['source'] ?? '') === 'self'): ?>
        <form method="post" action="/billing/cancel" class="mt-4">
          <?= Csrf::field() ?>
          <button class="btn-secondary text-xs" onclick="return confirm('Cancel your subscription and downgrade to the free plan?');">Cancel subscription</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php foreach ($plans as $pl): ?>
      <div class="card p-6 flex flex-col">
        <div class="flex items-center justify-between">
          <div class="font-semibold text-white"><?= h($pl['name']) ?></div>
          <div class="text-lg font-bold text-white">
            <?= h(strtoupper(SaaSService::currency())) ?> <?= number_format((int) $pl['price_amount'] / 100, 2) ?>
            <span class="text-xs text-discord-400 font-normal">/ <?= h($pl['billing_cycle'] === 'yearly' ? 'year' : 'month') ?></span>
          </div>
        </div>
        <?php if (!empty($pl['description'])): ?>
          <p class="text-xs text-discord-400 mt-2"><?= h($pl['description']) ?></p>
        <?php endif; ?>
        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-discord-800">
          <div class="text-[10px] text-discord-500">includes a <?= h(strtoupper(SaaSService::platformFeeCurrency())) ?> <?= number_format(SaaSService::platformFeeAmountCents() / 100, 2) ?> developer platform fee per transaction</div>
          <div class="flex flex-wrap gap-2 justify-end">
            <?php foreach ($providers as $id => $label): ?>
              <?php if ($configured[$id]): ?>
                <form method="post" action="/billing/checkout">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>">
                  <input type="hidden" name="provider" value="<?= h($id) ?>">
                  <button class="btn-primary px-3 py-1.5 text-xs"><?= h($label) ?></button>
                </form>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$configured['stripe'] && !$configured['paypal'] && !$configured['btcpay']): ?>
              <span class="text-xs text-discord-500">checkout not available yet</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($payments)): ?>
    <div class="card p-6 mt-5">
      <div class="text-sm font-medium text-white mb-3">Payment history</div>
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-discord-400 border-b border-discord-800">
            <th class="py-2 font-medium">Date</th>
            <th class="py-2 font-medium">Plan</th>
            <th class="py-2 font-medium">Provider</th>
            <th class="py-2 font-medium">Amount</th>
            <th class="py-2 font-medium">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
            <tr class="border-b border-discord-800/60 last:border-0">
              <td class="py-2 text-discord-300"><?= h($pay['created_at']) ?></td>
              <td class="py-2 text-white"><?= h($pay['plan_name'] ?: '—') ?></td>
              <td class="py-2 text-discord-300"><?= h($pay['provider'] ?: '—') ?></td>
              <td class="py-2 font-mono text-discord-300">
                <?= h(strtoupper($pay['currency'] ?? '')) ?> <?= number_format((int) $pay['amount'] / 100, 2) ?>
              </td>
              <td class="py-2 <?= ($pay['status'] ?? '') === 'paid' ? 'text-green-400' : 'text-discord-400' ?>">
                <?= h($pay['kind'] ?? 'payment') ?> · <?= h($pay['status']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

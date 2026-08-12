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

$title = 'SaaS · Plans';
$active = 'saas-plans';
$p = $plans;
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">SaaS — Plans</h1>
  <span class="text-xs text-discord-400 font-mono">module <?= h($module['version'] ?? '') ?> · saas</span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="space-y-5 max-w-6xl">
  <?php foreach ($p as $plan): ?>
    <form method="post" action="/admin/saas/plan/save" class="card p-6 space-y-5">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/saas">
      <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
      <input type="hidden" name="is_free" value="<?= (int) $plan['is_free'] ?>">

      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <input class="input font-semibold text-white" name="name" value="<?= h($plan['name']) ?>" <?= (int) $plan['is_free'] === 1 ? 'readonly' : '' ?>>
          <?php if ((int) $plan['is_free'] === 1): ?>
            <span class="px-2 py-0.5 rounded-full text-xs bg-green-500/15 text-green-400 border border-green-500/30">default · undeletable</span>
          <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-discord-400"><?= (int) ($counts[(int) $plan['id']] ?? 0) ?> assigned</span>
          <button type="submit" class="btn-primary px-4 py-1.5 text-sm">Save</button>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div>
          <label class="label">Price (minor units)</label>
          <input class="input font-mono text-xs" type="number" min="0" name="price_amount" value="<?= h((string) $plan['price_amount']) ?>">
          <p class="text-xs text-discord-400 mt-1">e.g. 499 = <?= h(strtoupper(SaaSService::currency())) ?>4.99</p>
        </div>
        <div>
          <label class="label">Billing cycle</label>
          <select class="input" name="billing_cycle">
            <?php foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($plan['billing_cycle'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Trial days (admin)</label>
          <input class="input font-mono text-xs" type="number" min="0" name="trial_days" value="<?= h((string) $plan['trial_days']) ?>">
        </div>
        <div>
          <label class="label">Sort order</label>
          <input class="input font-mono text-xs" type="number" name="sort_order" value="<?= h((string) $plan['sort_order']) ?>">
        </div>
        <div class="flex flex-col justify-end gap-2">
          <?php if ((int) $plan['is_free'] !== 1): ?>
            <button type="submit" formaction="/admin/saas/plan/toggle" class="btn-secondary px-3 py-1.5 text-xs">
              <?= (int) $plan['active'] === 1 ? 'Deactivate' : 'Activate' ?>
            </button>
            <button type="submit" formaction="/admin/saas/plan/delete" class="text-xs text-red-400 hover:text-red-300 text-left"
              onclick="return confirm('Delete this plan? Users must be downgraded first.');">Delete plan</button>
          <?php else: ?>
            <span class="text-xs <?= (int) $plan['active'] === 1 ? 'text-green-400' : 'text-red-400' ?>">
              <?= (int) $plan['active'] === 1 ? 'active' : 'inactive' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <label class="label">Description</label>
        <input class="input text-sm" name="description" value="<?= h($plan['description']) ?>" placeholder="Shown to users on the /billing page">
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div>
          <div class="text-sm font-medium text-white mb-2">Features</div>
          <div class="space-y-2">
            <?php
            $features = json_decode((string) $plan['features'], true) ?: [];
            foreach ($feature_keys as $key):
                $on = (bool) ($features[$key] ?? false);
            ?>
              <label class="flex items-center justify-between card p-3">
                <span class="text-xs text-discord-300"><?= h(SaaSService::keyLabel($key)) ?></span>
                <input type="checkbox" name="feature_<?= $key ?>" value="1" class="w-4 h-4 accent-blurple" <?= $on ? 'checked' : '' ?>>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <div class="text-sm font-medium text-white mb-2">Limits <span class="text-discord-400 font-normal">(blank = unlimited)</span></div>
          <div class="space-y-2">
            <?php
            $limits = json_decode((string) $plan['limits'], true) ?: [];
            foreach ($limit_keys as $key):
                $val = array_key_exists($key, $limits) ? $limits[$key] : null;
            ?>
              <label class="block">
                <span class="text-xs text-discord-300 block mb-1"><?= h(SaaSService::keyLabel($key)) ?></span>
                <input class="input font-mono text-xs" name="limit_<?= $key ?>" value="<?= $val === null ? '' : h((string) $val) ?>" placeholder="unlimited">
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <div class="text-sm font-medium text-white mb-2">Voice QoS <span class="text-discord-400 font-normal">(blank = global cap)</span></div>
          <div class="space-y-2">
            <?php
            $qos = json_decode((string) $plan['qos'], true) ?: [];
            foreach ($qos_keys as $key):
                $val = array_key_exists($key, $qos) ? $qos[$key] : null;
            ?>
              <label class="block">
                <span class="text-xs text-discord-300 block mb-1"><?= h(SaaSService::keyLabel($key)) ?></span>
                <input class="input font-mono text-xs" name="qos_<?= $key ?>" value="<?= $val === null ? '' : h((string) $val) ?>" placeholder="use global">
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </form>
  <?php endforeach; ?>

  <form method="post" action="/admin/saas/plan/save" class="card p-6 space-y-5">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/saas">
    <div class="flex items-center justify-between">
      <div class="text-sm font-medium text-white">New plan</div>
      <button type="submit" class="btn-secondary px-4 py-1.5 text-sm">Create plan</button>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <div class="col-span-2">
        <label class="label">Name</label>
        <input class="input" name="name" placeholder="Pro" required>
      </div>
      <div>
        <label class="label">Price (minor units)</label>
        <input class="input font-mono text-xs" type="number" min="0" name="price_amount" value="499">
      </div>
      <div>
        <label class="label">Billing cycle</label>
        <select class="input" name="billing_cycle">
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      <div>
        <label class="label">Trial days (admin)</label>
        <input class="input font-mono text-xs" type="number" min="0" name="trial_days" value="0">
      </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div>
        <div class="text-sm font-medium text-white mb-2">Features</div>
        <div class="space-y-2">
          <?php foreach ($feature_keys as $key): ?>
            <label class="flex items-center justify-between card p-3">
              <span class="text-xs text-discord-300"><?= h(SaaSService::keyLabel($key)) ?></span>
              <input type="checkbox" name="feature_<?= $key ?>" value="1" class="w-4 h-4 accent-blurple" <?= $key === 'voice' ? 'checked' : '' ?>>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div class="text-sm font-medium text-white mb-2">Limits <span class="text-discord-400 font-normal">(blank = unlimited)</span></div>
        <div class="space-y-2">
          <?php foreach ($limit_keys as $key): ?>
            <label class="block">
              <span class="text-xs text-discord-300 block mb-1"><?= h(SaaSService::keyLabel($key)) ?></span>
              <input class="input font-mono text-xs" name="limit_<?= $key ?>" placeholder="unlimited">
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div class="text-sm font-medium text-white mb-2">Voice QoS <span class="text-discord-400 font-normal">(blank = global cap)</span></div>
        <div class="space-y-2">
          <?php foreach ($qos_keys as $key): ?>
            <label class="block">
              <span class="text-xs text-discord-300 block mb-1"><?= h(SaaSService::keyLabel($key)) ?></span>
              <input class="input font-mono text-xs" name="qos_<?= $key ?>" placeholder="use global">
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </form>
</div>

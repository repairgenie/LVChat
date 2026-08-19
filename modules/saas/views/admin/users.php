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

$title = 'SaaS · Users';
$active = 'saas-users';
$allKeys = array_merge($feature_keys, $limit_keys, $qos_keys);
$overrideMap = [];
foreach ($overrides as $ov) {
    $overrideMap[$ov['key']] = $ov;
}
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">SaaS — Users</h1>
  <span class="text-xs text-discord-400 font-mono">module <?= h($module['version'] ?? '') ?> · saas</span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="space-y-5 max-w-6xl">
  <form method="get" action="/admin/saas/users" class="card p-4 flex items-center gap-3">
    <input class="input" name="q" value="<?= h($q) ?>" placeholder="Search by username or email…">
    <button class="btn-secondary px-4 py-2 text-sm">Search</button>
  </form>

  <?php if ($view_user): ?>
    <div class="card p-6 space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-lg font-semibold text-white"><?= h($view_user['username']) ?></div>
          <div class="text-xs text-discord-400">#<?= (int) $view_user['id'] ?> · <?= h($view_user['email'] ?: 'no email') ?> · <?= h($view_user['role']) ?></div>
        </div>
        <a class="text-xs text-discord-400 hover:text-white" href="/admin/saas/users?q=<?= rawurlencode($view_user['username']) ?>">re-search</a>
      </div>

      <form method="post" action="/admin/saas/users/save" class="space-y-5">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/saas/users?user=<?= (int) $view_user['id'] ?>">
        <input type="hidden" name="user_id" value="<?= (int) $view_user['id'] ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="col-span-2">
            <label class="label">Plan</label>
            <select class="input" name="plan_id">
              <option value="0">— Free (no paid plan) —</option>
              <?php foreach ($plans as $pl):
                  $isFree = (int) $pl['is_free'] === 1;
                  $current = $plan_for['assignment']['plan_id'] ?? 0;
              ?>
                <option value="<?= (int) $pl['id'] ?>" <?= (int) $current === (int) $pl['id'] ? 'selected' : '' ?> <?= $isFree ? 'disabled' : '' ?>>
                  <?= h($pl['name']) ?><?= (int) $pl['active'] !== 1 ? ' (inactive)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-discord-400 mt-1">
              Current: <strong class="text-white"><?= h($plan_for['plan']['name']) ?></strong>
              <?php if ($plan_for['assignment']): ?>
                — <?= h($plan_for['assignment']['status']) ?> (ends <?= h((string) ($plan_for['assignment']['period_end'] ?? 'never')) ?>) · source <?= h($plan_for['assignment']['source'] ?? '') ?>
              <?php endif; ?>
            </p>
          </div>
          <div class="flex items-end justify-between gap-3">
            <button type="submit" class="btn-primary px-4 py-2 text-sm">Save</button>
            <button type="submit" formaction="/admin/saas/users/cancel" class="btn-secondary px-4 py-2 text-sm"
               data-confirm="Force-downgrade this user to the free plan?">Downgrade</button>
          </div>
        </div>

        <div>
          <div class="text-sm font-medium text-white mb-2">Per-user overrides <span class="text-discord-400 font-normal">(apply regardless of plan; admins are exempt)</span></div>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            <?php foreach ($allKeys as $key):
                $cur = $overrideMap[$key] ?? null;
                $isFeature = in_array($key, $feature_keys, true);
            ?>
              <div class="card p-3 space-y-1.5">
                <div class="text-xs font-medium text-white"><?= h(SaaSService::keyLabel($key)) ?>
                  <?php if ($cur): ?>
                    <span class="ml-1 px-1.5 py-0.5 rounded bg-blurple/20 text-blurple-300 text-[10px]">override</span>
                  <?php endif; ?>
                </div>
                <?php if ($isFeature): ?>
                  <select class="input text-xs" name="ov_<?= $key ?>">
                    <option value="">inherit</option>
                    <option value="allow" <?= $cur && $cur['value'] === '1' ? 'selected' : '' ?>>allow</option>
                    <option value="deny" <?= $cur && $cur['value'] === '0' ? 'selected' : '' ?>>deny</option>
                  </select>
                <?php else: ?>
                  <input class="input font-mono text-xs" name="ov_<?= $key ?>" value="<?= $cur ? ($cur['value'] === '' ? 'unlimited' : h($cur['value'])) : '' ?>" placeholder="inherit (blank)">
                <?php endif; ?>
                <div class="flex items-center gap-2">
                  <input class="input text-xs" name="ovn_<?= $key ?>" value="<?= $cur ? h($cur['note'] ?? '') : '' ?>" placeholder="note">
                  <label class="flex items-center gap-1 text-[10px] text-discord-400 whitespace-nowrap">
                    <input type="checkbox" name="ovc_<?= $key ?>" value="1" class="w-3 h-3 accent-red-400"> clear
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </form>
    </div>
  <?php else: ?>
    <?php if ($q !== '' && empty($rows)): ?>
      <div class="card p-6 text-sm text-discord-400">No users match "<?= h($q) ?>".</div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($rows) && !$view_user): ?>
    <div class="card overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-discord-400 border-b border-discord-800">
            <th class="p-3 font-medium">User</th>
            <th class="p-3 font-medium">Email</th>
            <th class="p-3 font-medium">Role</th>
            <th class="p-3 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="border-b border-discord-800/60 last:border-0">
              <td class="p-3 text-white"><?= h($r['username']) ?> <span class="text-discord-500 text-xs">#<?= (int) $r['id'] ?></span></td>
              <td class="p-3 text-discord-300"><?= h($r['email'] ?: '—') ?></td>
              <td class="p-3 text-discord-300"><?= h($r['role']) ?></td>
              <td class="p-3 text-right"><a class="text-sky-400 hover:text-sky-300" href="/admin/saas/users?user=<?= (int) $r['id'] ?>">manage</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

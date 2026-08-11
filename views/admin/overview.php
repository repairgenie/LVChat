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

 $title = 'Admin dashboard'; $active = 'overview'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Admin dashboard</h1>
  <span class="text-sm text-discord-400">Signed in as <?= h($admin['username']) ?></span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <?php foreach ($stats as $label => $val): ?>
  <div class="card p-4">
    <div class="text-2xl font-bold text-white"><?= (int) $val ?></div>
    <div class="text-xs text-discord-400 mt-1"><?= h($label) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php require ROOT . '/views/admin/_charts.php'; ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-3">
      <h2 class="font-semibold text-white text-sm">Messages, last 30 days</h2>
      <a href="/admin/analytics?range=30" class="text-[11px] text-blurple hover:underline">Full analytics →</a>
    </div>
    <?= chart_line($overviewMessages, ['color' => '#5865f2']) ?>
  </div>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-3">
      <h2 class="font-semibold text-white text-sm">Most active users, last 30 days</h2>
      <a href="/admin/analytics?range=30" class="text-[11px] text-blurple hover:underline">Full analytics →</a>
    </div>
    <?= chart_hbars($overviewTopUsers, ['color' => '#10b981', 'rowH' => 30]) ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="card p-4">
    <h2 class="font-semibold text-white mb-3">Recent audit events</h2>
    <div class="space-y-1.5">
      <?php if (!$recentAudit): ?><div class="text-sm text-discord-500">No audit events yet.</div><?php endif; ?>
      <?php foreach ($recentAudit as $a): ?>
      <div class="text-sm text-discord-300">
        <span class="text-discord-500 text-xs"><?= h(date('M j H:i', strtotime($a['created_at'] . ' UTC'))) ?></span>
        <span class="text-blurple"><?= h($a['action']) ?></span>
        <?php if ($a['target']): ?>→ <span class="text-discord-200"><?= h($a['target']) ?></span><?php endif; ?>
        <span class="text-discord-500">by <?= h($a['username'] ?? 'system') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card p-4">
    <h2 class="font-semibold text-white mb-3">Banned / restricted users</h2>
    <div class="space-y-1.5">
      <?php if (!$banned): ?><div class="text-sm text-discord-500">No banned users.</div><?php endif; ?>
      <?php foreach ($banned as $u): ?>
      <div class="flex items-center justify-between text-sm">
        <span class="text-discord-200"><?= h($u['username']) ?></span>
        <span class="text-discord-400"><?= h($u['ban_reason'] ?: ($u['kind'] ?? '')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4 text-xs text-discord-500">Tip: use /global to announce to all channels, /kline & /shun via chat, or the Bans page.</div>
  </div>
</div>

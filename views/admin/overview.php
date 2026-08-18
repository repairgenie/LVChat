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
<?php require ROOT . '/views/admin/_nav.php'; ?>
<?php require ROOT . '/views/admin/_page_header.php'; ?>

<?php
$statCards = [
    ['Total users', $stats['Total users'], 'users', 'blurple'],
    ['Online now', $stats['Online now'], 'zap', 'green'],
    ['Channels', $stats['Channels'], 'hash', 'cyan'],
    ['Messages logged', $stats['Messages logged'], 'message-square', 'purple'],
    ['Private messages', $stats['Private messages'], 'mail', 'pink'],
    ['Active global bans', $stats['Active global bans'], 'slash', 'red'],
    ['Spam filters', $stats['Spam filters'], 'filter', 'amber'],
    ['Audit events', $stats['Audit events'], 'shield', 'blurple'],
];
$statTint = ['blurple' => '', 'green' => 'green', 'cyan' => 'cyan', 'purple' => 'purple', 'pink' => 'pink', 'red' => 'red', 'amber' => 'amber'];
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <?php foreach ($statCards as [$label, $val, $iconName, $tint]): ?>
  <div class="stat-card">
    <div class="stat-icon <?= $statTint[$tint] ?>"><?= icon($iconName, 'w-5 h-5') ?></div>
    <div class="min-w-0">
      <div class="stat-value"><?= (int) $val ?></div>
      <div class="stat-label"><?= h($label) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card p-3 mb-6">
  <div class="flex flex-wrap items-center gap-2">
    <span class="text-xs font-semibold uppercase tracking-wide text-discord-400 px-2">Quick actions</span>
    <a href="/admin/users" class="btn-ghost text-xs !py-1.5"><?= icon('user-plus', 'w-3.5 h-3.5') ?> Create user</a>
    <a href="/admin/channels" class="btn-ghost text-xs !py-1.5"><?= icon('hash', 'w-3.5 h-3.5') ?> Manage channels</a>
    <a href="/admin/reports" class="btn-ghost text-xs !py-1.5"><?= icon('flag', 'w-3.5 h-3.5') ?> Review reports</a>
    <a href="/admin/bans" class="btn-ghost text-xs !py-1.5"><?= icon('slash', 'w-3.5 h-3.5') ?> Bans &amp; lines</a>
    <a href="/admin/motd" class="btn-ghost text-xs !py-1.5"><?= icon('quote', 'w-3.5 h-3.5') ?> Update MOTD</a>
    <a href="/admin/settings" class="btn-ghost text-xs !py-1.5"><?= icon('gear', 'w-3.5 h-3.5') ?> Server settings</a>
  </div>
</div>

<?php require ROOT . '/views/admin/_charts.php'; ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="card p-5">
    <div class="flex items-baseline justify-between mb-3">
      <h2 class="font-semibold text-white text-sm">Messages, last 30 days</h2>
      <a href="/admin/analytics?range=30" class="text-[11px] text-blurple hover:underline">Full analytics →</a>
    </div>
    <?= chart_line($overviewMessages, ['color' => '#5865f2']) ?>
  </div>
  <div class="card p-5">
    <div class="flex items-baseline justify-between mb-3">
      <h2 class="font-semibold text-white text-sm">Most active users, last 30 days</h2>
      <a href="/admin/analytics?range=30" class="text-[11px] text-blurple hover:underline">Full analytics →</a>
    </div>
    <?= chart_hbars($overviewTopUsers, ['color' => '#10b981', 'rowH' => 30]) ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="card p-5">
    <h2 class="font-semibold text-white mb-3"><?= icon('clock', 'w-4 h-4 text-blurple inline-block mr-1') ?>Recent audit events</h2>
    <div class="space-y-2">
      <?php if (!$recentAudit): ?><div class="empty-state">No audit events yet.</div><?php endif; ?>
      <?php foreach ($recentAudit as $a): ?>
      <div class="flex items-center gap-2 text-sm border-b border-discord-800 pb-2 last:border-0 last:pb-0">
        <span class="text-discord-500 text-xs shrink-0 w-16"><?= h(date('M j', strtotime($a['created_at'] . ' UTC'))) ?></span>
        <span class="px-1.5 py-0.5 rounded bg-blurple/15 text-blurple text-[10px] font-semibold uppercase tracking-wide shrink-0"><?= h($a['action']) ?></span>
        <?php if ($a['target']): ?><span class="text-discord-200 truncate"><?= h($a['target']) ?></span><?php endif; ?>
        <span class="text-discord-500 ml-auto text-xs shrink-0">by <?= h($a['username'] ?? 'system') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card p-5">
    <h2 class="font-semibold text-white mb-3"><?= icon('slash', 'w-4 h-4 text-red-400 inline-block mr-1') ?>Banned / restricted users</h2>
    <div class="space-y-2">
      <?php if (!$banned): ?><div class="empty-state">No banned users.</div><?php endif; ?>
      <?php foreach ($banned as $u): ?>
      <div class="flex items-center justify-between gap-2 text-sm border-b border-discord-800 pb-2 last:border-0 last:pb-0">
        <span class="text-discord-200 truncate"><?= h($u['username']) ?></span>
        <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-400 shrink-0"><?= h($u['ban_reason'] ?: ($u['kind'] ?? 'restricted')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4 text-xs text-discord-500 leading-relaxed">Tip: use /global to announce to all channels, /kline & /shun via chat, or the Bans page.</div>
  </div>
</div>
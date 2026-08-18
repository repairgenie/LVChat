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

 $title = 'Bans'; $active = 'bans';
$pageTitle = 'Bans';
$pageActions = '<details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add global ban</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      ' . Csrf::field() . '
      <input type="hidden" name="back" value="/admin/bans">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Type</label>
          <select name="kind" class="input">
            ' . implode('', array_map(function ($k) {
                return '<option value="' . $k . '">' . strtoupper($k) . '</option>';
            }, ['kline', 'gline', 'zline', 'shun', 'qline', 'cqline'])) . '
          </select>
        </div>
        <div>
          <label class="label">Duration</label>
          <input class="input" name="duration" placeholder="e.g. 1d, 30m">
        </div>
      </div>
      <div>
        <label class="label">Mask</label>
        <input class="input" name="mask" placeholder="nick · IP · IP/CIDR · #channel for CQLINE" required>
      </div>
      <div>
        <label class="label">Reason</label>
        <input class="input" name="reason" placeholder="Reason…">
      </div>
      <button name="action" value="ban_add" class="btn-primary w-full justify-center">Add ban</button>
    </form>
  </details>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="admin-section-title"><?= icon('shield', 'w-4 h-4') ?>Global bans (kline / gline / zline / shun / qline)</div>
<div class="card overflow-x-auto mb-8">
  <table class="data-table">
    <thead>
      <tr><th>Kind</th><th>Mask</th><th>Reason</th><th>Set by</th><th>Expires</th><th class="text-right"></th></tr>
    </thead>
    <tbody>
      <?php if (!$global): ?><tr><td class="text-discord-500" colspan="6">No global bans.</td></tr><?php endif; ?>
      <?php foreach ($global as $b): ?>
      <tr>
        <td><span class="text-red-400 font-mono"><?= h(strtoupper($b['kind'])) ?></span></td>
        <td class="font-mono text-discord-200"><?= h($b['mask']) ?></td>
        <td class="text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="text-discord-400"><?= h($b['set_by_name'] ?? 'system') ?></td>
        <td class="text-discord-400"><?= $b['expires_at'] ? h(date('M j H:i', strtotime($b['expires_at'] . ' UTC'))) : 'permanent' ?></td>
        <td class="text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/bans"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="ban_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="admin-section-title"><?= icon('hash', 'w-4 h-4') ?>Channel bans</div>
<div class="card overflow-x-auto">
  <table class="data-table">
    <thead>
      <tr><th>Channel</th><th>Kind</th><th>Mask</th><th>Reason</th><th class="text-right"></th></tr>
    </thead>
    <tbody>
      <?php if (!$channelBans): ?><tr><td class="text-discord-500" colspan="5">No channel bans.</td></tr><?php endif; ?>
      <?php foreach ($channelBans as $b): ?>
      <tr>
        <td class="text-discord-200"><?= h($b['channel_name'] ?? '#?') ?></td>
        <td class="text-red-400 font-mono"><?= h(strtoupper($b['kind'])) ?></td>
        <td class="font-mono text-discord-200"><?= h($b['mask']) ?></td>
        <td class="text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/bans"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="ban_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

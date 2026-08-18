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

 $title = 'Moderation'; $active = 'moderation';
$pageTitle = 'Moderation queue';
$pageSubtitle = 'Bad-word / spam-filter triggers and moderation actions against accounts';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<?php if ($summary): ?>
<div class="card overflow-x-auto mb-6">
  <div class="px-5 py-3.5 border-b border-discord-700 text-sm font-semibold text-white">Most filtered accounts</div>
  <table class="data-table">
    <thead>
      <tr><th>User</th><th>Hits</th><th>Bad words</th><th>Spam filters</th><th>Actions</th><th>Last hit</th><th class="text-right">View</th></tr>
    </thead>
    <tbody>
      <?php foreach ($summary as $s): ?>
      <tr>
        <td class="font-medium text-white"><?= h($s['username'] ?: '(deleted user)') ?><?= (int) $s['guest_id'] ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></td>
        <td><?= (int) $s['hits'] ?></td>
        <td><?= (int) $s['badwords'] ?></td>
        <td><?= (int) $s['spamfilters'] ?></td>
        <td><?= (int) $s['actions'] ?></td>
        <td class="text-discord-400"><?= h(relative_time($s['last_hit'])) ?></td>
        <td class="text-right">
          <?php if ((int) $s['user_id'] > 0): ?>
          <a href="/admin/users/<?= (int) $s['user_id'] ?>" class="btn-ghost text-xs !py-1">History</a>
          <?php else: ?>
          <span class="text-xs text-discord-500">guest</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card p-6 mb-6 text-sm text-discord-400">No moderation events recorded yet.</div>
<?php endif; ?>

<div class="card overflow-x-auto">
  <div class="px-5 py-3.5 border-b border-discord-700 text-sm font-semibold text-white">Recent events</div>
  <table class="data-table">
    <thead>
      <tr><th>Time</th><th>User</th><th>Type</th><th>Action</th><th>Match / Target</th><th>Details</th></tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
      <tr class="align-top">
        <td class="text-discord-400 whitespace-nowrap"><?= h(gmdate('M j H:i', strtotime($e['created_at'] . ' UTC'))) ?></td>
        <td>
          <?php $nm = $e['username'] ?: $e['guest_name']; ?>
          <?php if ((int) $e['user_id'] > 0): ?>
          <a href="/admin/users/<?= (int) $e['user_id'] ?>" class="font-medium text-white hover:underline"><?= h($nm) ?></a>
          <?php else: ?>
          <span class="text-discord-300"><?= h($nm) ?: '(deleted)' ?></span><?= (int) $e['guest_id'] ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?>
          <?php endif; ?>
        </td>
        <td><span class="px-1.5 py-0.5 rounded text-[11px] <?= in_array($e['kind'], ['badword', 'spamfilter'], true) ? 'bg-amber-500/20 text-amber-300' : 'bg-blurple/20 text-blurple' ?>"><?= h($e['kind']) ?></span></td>
        <td><?= h($e['action']) ?></td>
        <td class="font-mono text-[11px] text-discord-300"><?= h($e['match'] ?: '—') ?></td>
        <td class="text-discord-300"><?= h($e['content'] !== '' ? mb_strimwidth($e['content'], 0, 80, '…') : $e['target']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$events): ?>
      <tr><td colspan="6" class="py-4 text-discord-500">No events yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

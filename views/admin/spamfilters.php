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

 $title = 'Spam filters'; $active = 'spamfilters'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Spam filters</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add filter</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/spamfilters">
      <div>
        <label class="label">Match text (simple, * and ? wildcards)</label>
        <input class="input" name="match" placeholder="e.g. *cheap watches*" required>
      </div>
      <div>
        <label class="label">Reason shown to user</label>
        <input class="input" name="reason" placeholder="No advertising">
      </div>
      <button name="action" value="spamfilter_add" class="btn-primary w-full justify-center">Add</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">#</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Match</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">Targets</th><th class="px-4 py-2">Enabled</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$filters): ?><tr><td class="px-4 py-3 text-discord-500" colspan="7">No filters yet.</td></tr><?php endif; ?>
      <?php foreach ($filters as $f): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 text-discord-400"><?= (int) $f['id'] ?></td>
        <td class="px-4 py-2 font-mono"><?= h($f['match_type']) ?></td>
        <td class="px-4 py-2 font-mono text-discord-200"><?= h($f['match']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($f['reason'] ?: '—') ?></td>
        <td class="px-4 py-2 font-mono text-discord-400"><?= h($f['targets']) ?></td>
        <td class="px-4 py-2"><?= $f['enabled'] ? '<span class="text-green-400">on</span>' : '<span class="text-discord-500">off</span>' ?></td>
        <td class="px-4 py-2 flex gap-1 justify-end">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/spamfilters"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button name="action" value="spamfilter_toggle" class="btn-ghost text-xs !py-1"><?= $f['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/spamfilters"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button name="action" value="spamfilter_del" class="btn-ghost text-xs !py-1 text-red-400" onclick="event.preventDefault(); return LVCDialog.confirmSubmit(this, 'Delete this spam filter?')">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

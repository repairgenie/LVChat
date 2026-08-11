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

 $title = 'Bad words'; $active = 'badwords'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Bad word filter</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add word</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/badwords">
      <div>
        <label class="label">Word</label>
        <input class="input" name="word" placeholder="e.g. spamword" required>
      </div>
      <div>
        <label class="label">Action</label>
        <select name="badword_action" class="input">
          <option value="censor">Censor (replace with ****)</option>
          <option value="block">Remove the whole message</option>
        </select>
      </div>
      <p class="text-xs text-discord-400">Applied to private messages and to channels with mode <code>+C</code> set.</p>
      <button name="action" value="badword_add" class="btn-primary w-full justify-center">Add</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">#</th><th class="px-4 py-2">Word</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">Enabled</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$words): ?><tr><td class="px-4 py-3 text-discord-500" colspan="5">No bad words configured.</td></tr><?php endif; ?>
      <?php foreach ($words as $w): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 text-discord-400"><?= (int) $w['id'] ?></td>
        <td class="px-4 py-2 font-mono text-discord-200"><?= h($w['word']) ?></td>
        <td class="px-4 py-2"><?= $w['action'] === 'block' ? '<span class="text-red-400">block</span>' : '<span class="text-sky-400">censor</span>' ?></td>
        <td class="px-4 py-2"><?= $w['enabled'] ? '<span class="text-green-400">on</span>' : '<span class="text-discord-500">off</span>' ?></td>
        <td class="px-4 py-2 flex gap-1 justify-end">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/badwords"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button name="action" value="badword_toggle" class="btn-ghost text-xs !py-1"><?= $w['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/badwords"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button name="action" value="badword_del" class="btn-ghost text-xs !py-1 text-red-400" onclick="return confirm('Delete this bad word?')">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

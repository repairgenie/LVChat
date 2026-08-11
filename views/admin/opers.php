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

 $title = 'O-lines'; $active = 'opers'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Operator lines (o:lines)</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add o:line</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/opers">
      <div>
        <label class="label">Nickname</label>
        <input class="input" name="username" placeholder="e.g. operator1" required autocomplete="off">
      </div>
      <div>
        <label class="label">Password</label>
        <input class="input" type="password" name="password" placeholder="8+ characters" required minlength="8" autocomplete="new-password">
      </div>
      <div>
        <label class="label">Operator class</label>
        <select name="operclass_id" class="input">
          <?php foreach ($classes as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="text-xs text-discord-400">The user logs in, then runs <code>/oper &lt;their nick&gt; &lt;password&gt;</code> to gain the class's permissions.</p>
      <button name="action" value="oper_add" class="btn-primary w-full justify-center">Add o:line</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<div class="text-xs text-discord-500 mb-3">There is no shared operator password — each operator has their own o:line. A user only oper's up the account matching their own nickname.</div>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Nickname</th><th class="px-4 py-2">Class</th><th class="px-4 py-2">Enabled</th><th class="px-4 py-2">Created</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$opers): ?><tr><td class="px-4 py-3 text-discord-500" colspan="5">No o:lines yet.</td></tr><?php endif; ?>
      <?php foreach ($opers as $o): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 font-mono text-discord-200"><?= h($o['username']) ?></td>
        <td class="px-4 py-2"><?= h($o['operclass'] ?? '—') ?></td>
        <td class="px-4 py-2"><?= $o['enabled'] ? '<span class="text-green-400">on</span>' : '<span class="text-discord-500">off</span>' ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(date('M j Y', strtotime($o['created_at'] . ' UTC'))) ?></td>
        <td class="px-4 py-2 flex gap-1 justify-end">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/opers"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button name="action" value="oper_toggle" class="btn-ghost text-xs !py-1"><?= $o['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/opers"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button name="action" value="oper_del" class="btn-ghost text-xs !py-1 text-red-400" onclick="return confirm('Remove this o:line?')">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

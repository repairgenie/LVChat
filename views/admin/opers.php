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

 $title = 'O-lines'; $active = 'opers';
$pageTitle = 'Operator lines (o:lines)';
$pageSubtitle = 'There is no shared operator password — each operator has their own o:line. A user only opers up the account matching their own nickname.';
$pageActions = '<details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add o:line</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      ' . Csrf::field() . '
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
          ' . implode('', array_map(function ($c) {
              return '<option value="' . (int) $c['id'] . '">' . h($c['name']) . '</option>';
          }, $classes ?? [])) . '
        </select>
      </div>
      <p class="text-xs text-discord-400">The user logs in, then runs <code>/oper &lt;their nick&gt; &lt;password&gt;</code> to gain the class&rsquo;s permissions.</p>
      <button name="action" value="oper_add" class="btn-primary w-full justify-center">Add o:line</button>
    </form>
  </details>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="card overflow-x-auto">
  <table class="data-table">
    <thead>
      <tr><th>Nickname</th><th>Class</th><th>Enabled</th><th>Created</th><th class="text-right"></th></tr>
    </thead>
    <tbody>
      <?php if (!$opers): ?><tr><td class="text-discord-500" colspan="5">No o:lines yet.</td></tr><?php endif; ?>
      <?php foreach ($opers as $o): ?>
      <tr>
        <td class="font-mono text-discord-200"><?= h($o['username']) ?></td>
        <td><?= h($o['operclass'] ?? '—') ?></td>
        <td><?= $o['enabled'] ? '<span class="text-green-400">on</span>' : '<span class="text-discord-500">off</span>' ?></td>
        <td class="text-discord-400"><?= h(date('M j Y', strtotime($o['created_at'] . ' UTC'))) ?></td>
        <td class="flex gap-1 justify-end">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/opers"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button name="action" value="oper_toggle" class="btn-ghost text-xs !py-1"><?= $o['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/opers"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button name="action" value="oper_del" class="btn-ghost text-xs !py-1 text-red-400" onclick="event.preventDefault(); return LVCDialog.confirmSubmit(this, 'Remove this o:line?')">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

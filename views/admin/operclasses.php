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


$title = 'Operclasses'; $active = 'operclasses';
$pageTitle = 'Operator classes';
$permLabels = [
    'oper' => 'Operator commands (kline/gline/zline/shun, kill, global, spamfilter, badword…) and viewing user IPs',
    'manage_users' => 'Promote / demote / ban users',
    'manage_channels' => 'Force topics, drop channels, change visibility',
    'manage_bans' => 'Add / remove global bans',
    'manage_badwords' => 'Manage the bad-word filter',
    'manage_roles' => 'Manage custom roles',
    'manage_opers' => 'Manage o:lines and operator classes',
    'rehash' => 'Reload server configuration (/rehash)',
];
$permBoxes = function (array $checked) use ($permLabels): string {
    $html = '';
    foreach ($permLabels as $key => $label) {
        $on = in_array($key, $checked, true) ? ' checked' : '';
        $html .= '<label class="flex items-start gap-2 text-xs text-discord-300">'
            . '<input type="checkbox" name="perms[]" value="' . h($key) . '"' . $on . ' class="mt-0.5 accent-blurple"> '
            . h($label) . '</label>';
    }
    return $html;
};
$pageSubtitle = 'Permission bundles granted to operators when they /oper up against their o:line. Default classes (netadmin, serveradmin, globalop, localop) ship with the server.';
$pageActions = '<details class="relative">
    <summary class="btn-primary cursor-pointer">＋ New class</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      ' . Csrf::field() . '
      <input type="hidden" name="back" value="/admin/operclasses">
      <input type="hidden" name="id" value="0">
      <div>
        <label class="label">Name</label>
        <input class="input" name="name" placeholder="e.g. netadmin" required>
      </div>
      <div>
        <label class="label">Colour</label>
        <input class="input" type="color" name="color" value="#ffd700">
      </div>
      <div class="space-y-1">' . $permBoxes([]) . '</div>
      <button name="action" value="operclass_save" class="btn-primary w-full justify-center">Create class</button>
    </form>
  </details>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($classes as $c): $perms = json_decode((string) $c['perms'], true) ?: []; ?>
  <details class="card p-5">
    <summary class="cursor-pointer flex items-center gap-2 text-sm font-semibold text-white">
      <span class="w-3 h-3 rounded-full inline-block" style="background:<?= h($c['color']) ?>"></span>
      <?= h($c['name']) ?>
      <?php if ($c['is_default']): ?><span class="text-[10px] text-discord-500">default</span><?php endif; ?>
    </summary>
    <form method="post" action="/admin/action" class="mt-3 space-y-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/operclasses">
      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Name</label>
          <input class="input" name="name" value="<?= h($c['name']) ?>" required>
        </div>
        <div>
          <label class="label">Colour</label>
          <input class="input" type="color" name="color" value="<?= h($c['color']) ?>">
        </div>
      </div>
      <div class="space-y-1"><?= $permBoxes($perms) ?></div>
      <div class="flex gap-2">
        <button name="action" value="operclass_save" class="btn-primary text-xs !py-1.5">Save</button>
        <?php if (!$c['is_default']): ?>
        <button name="action" value="operclass_del" class="btn-ghost text-xs !py-1.5 text-red-400" onclick="event.preventDefault(); return LVCDialog.confirmSubmit(this, 'Delete this class? Its o:lines are removed too.')">Delete</button>
        <?php endif; ?>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

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


$title = 'Roles'; $active = 'roles';
$permLabels = [
    'oper' => 'Operator commands (kline/gline/zline/shun, kill, global, spamfilter, badword…) and viewing user IPs',
    'manage_users' => 'Promote / demote / ban users',
    'manage_channels' => 'Force topics, drop channels, change visibility',
    'manage_bans' => 'Add / remove global bans',
    'manage_badwords' => 'Manage the bad-word filter',
    'manage_roles' => 'Create / edit roles',
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
$pageTitle = 'Roles &amp; permissions';
$pageSubtitle = 'Admins always have every permission. Custom roles grant permissions to otherwise regular users (an IRC Operator is anyone with the oper permission). Marking a role as a Helper gives its members a green nick and automatic half-op (%) in every channel.';
$pageActions = '<details class="relative">
    <summary class="btn-primary cursor-pointer">＋ New role</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      ' . Csrf::field() . '
      <input type="hidden" name="back" value="/admin/roles">
      <input type="hidden" name="id" value="0">
      <div>
        <label class="label">Name</label>
        <input class="input" name="name" placeholder="e.g. Moderator" required>
      </div>
      <div>
        <label class="label">Colour</label>
        <input class="input" type="color" name="color" value="#5865f2">
      </div>
      <div class="space-y-1">' . $permBoxes([]) . '</div>
      <label class="flex items-center gap-2 text-xs text-discord-300 cursor-pointer">
        <input type="checkbox" name="helper" value="1" class="accent-blurple"> <span>Helper — <span class="text-green-400">green nick</span> + auto half-op in every channel</span>
      </label>
      <button name="action" value="role_save" class="btn-primary w-full justify-center">Create role</button>
    </form>
  </details>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php if (!$roles): ?><div class="text-sm text-discord-500">No custom roles yet.</div><?php endif; ?>
  <?php foreach ($roles as $r): $rperms = json_decode((string) $r['perms'], true) ?: []; ?>
  <details class="card p-5" <?= isset($_GET['edit']) && (int) $_GET['edit'] === (int) $r['id'] ? 'open' : '' ?>>
    <summary class="cursor-pointer flex items-center gap-2 text-sm font-semibold text-white">
      <span class="w-3 h-3 rounded-full inline-block" style="background:<?= h($r['color']) ?>"></span>
      <?= h($r['name']) ?>
      <span class="text-xs font-normal text-discord-400">(<?= (int) $r['members'] ?> member<?= $r['members'] == 1 ? '' : 's' ?>)</span>
    </summary>
    <form method="post" action="/admin/action" class="mt-3 space-y-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/roles">
      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Name</label>
          <input class="input" name="name" value="<?= h($r['name']) ?>" required>
        </div>
        <div>
          <label class="label">Colour</label>
          <input class="input" type="color" name="color" value="<?= h($r['color']) ?>">
        </div>
      </div>
      <div class="space-y-1"><?= $permBoxes($rperms) ?></div>
      <label class="flex items-center gap-2 text-xs text-discord-300 cursor-pointer">
        <input type="checkbox" name="helper" value="1" <?= (int) $r['helper'] === 1 ? 'checked' : '' ?> class="accent-blurple"> <span>Helper — <span class="text-green-400">green nick</span> + auto half-op in every channel</span>
      </label>
      <div class="flex gap-2">
        <button name="action" value="role_save" class="btn-primary text-xs !py-1.5">Save</button>
        <button name="action" value="role_del" class="btn-ghost text-xs !py-1.5 text-red-400" onclick="event.preventDefault(); return LVCDialog.confirmSubmit(this, 'Delete this role? Members lose its permissions.')">Delete</button>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

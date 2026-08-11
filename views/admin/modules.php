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

 $title = 'Modules'; $active = 'modules'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Modules</h1>
  <p class="text-sm text-discord-400 font-mono"><?= h($dir) ?></p>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<p class="text-sm text-discord-400 mb-4">
  Modules are feature packs in the <code class="text-discord-300">modules/</code> directory
  (see <code class="text-discord-300">docs/modules.md</code>). Renaming a module folder to
  <code class="text-discord-300">&lt;id&gt;.disabled</code> hard-disables it and keeps its
  state; toggling below soft-disables it. Empty or missing <code class="text-discord-300">modules/</code>
  is ignored entirely.
</p>

<?php if ($warnings): ?>
<div class="card p-4 mb-5 border border-amber-500/40">
  <div class="text-sm font-semibold text-amber-300 mb-2">Boot warnings</div>
  <ul class="text-sm text-discord-300 space-y-1">
    <?php foreach ($warnings as $w): ?>
    <li class="font-mono text-xs"><?= h($w) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Module</th>
      <th class="px-4 py-2">Version</th>
      <th class="px-4 py-2">Order</th>
      <th class="px-4 py-2">State</th>
      <th class="px-4 py-2">License key</th>
      <th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$rows): ?>
      <tr><td class="px-4 py-3 text-discord-500" colspan="6">No modules installed — the <code class="text-discord-400">modules/</code> directory is empty or missing.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $id => $m): $row = $m['row']; $manifest = $m['manifest']; ?>
      <tr class="border-b border-discord-800 align-top">
        <td class="px-4 py-2">
          <div class="font-medium text-discord-100"><?= h($manifest['name'] ?? $row['name'] ?: $id) ?></div>
          <div class="text-xs text-discord-500 font-mono"><?= h($id) ?></div>
          <?php if ($manifest && !empty($manifest['license'])): ?>
          <div class="text-xs text-amber-400/80 mt-0.5">paid plugin</div>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-discord-300 font-mono text-xs"><?= h($manifest['version'] ?? $row['version'] ?: '—') ?></td>
        <td class="px-4 py-2 text-discord-400 font-mono text-xs"><?= isset($manifest['order']) ? (int) $manifest['order'] : '—' ?></td>
        <td class="px-4 py-2">
          <?php if (!$m['onDisk']): ?>
            <span class="text-discord-500">not on disk</span>
          <?php elseif ($m['disabledRename']): ?>
            <span class="text-amber-400">disabled (.disabled)</span>
          <?php elseif ($m['manifest'] !== null && (int) $row['enabled'] === 1): ?>
            <span class="text-green-400">running</span>
          <?php elseif ((int) $row['enabled'] === 1): ?>
            <span class="text-amber-400">skipped at boot</span>
          <?php else: ?>
            <span class="text-red-400">disabled</span>
          <?php endif; ?>
          <?php if ($m['onDisk'] && !empty($row['updated_at'])): ?>
          <div class="text-xs text-discord-500 mt-0.5">booted <?= relative_time($row['updated_at']) ?></div>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2">
          <form method="post" action="/admin/action" class="flex items-center gap-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="module_save">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <input type="hidden" name="back" value="/admin/modules">
            <input class="input font-mono text-xs w-56" name="license" value="<?= h($row['license'] ?? '') ?>"
                   placeholder="LVC-…" <?= $m['onDisk'] ? '' : 'disabled' ?>>
            <button class="btn-ghost text-xs !py-1" <?= $m['onDisk'] ? '' : 'disabled' ?>>Save</button>
          </form>
        </td>
        <td class="px-4 py-2 text-right">
          <?php if ($m['onDisk']): ?>
          <form method="post" action="/admin/action" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <input type="hidden" name="back" value="/admin/modules">
            <button name="action" value="module_toggle" class="btn-ghost text-xs !py-1">
              <?= (int) $row['enabled'] === 1 ? 'Disable' : 'Enable' ?>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

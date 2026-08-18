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

 $title = 'Sounds'; $active = 'sounds';
$pageTitle = 'Sound alerts';
$pageSubtitle = 'Audio alerts users can pick for channel messages and DMs. Everything uploaded here is available to every user; users cannot upload their own.';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="card p-5 mb-5">
  <h2 class="font-semibold text-white mb-3">Upload a sound</h2>
  <form method="post" action="/admin/action" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/sounds">
    <div>
      <label class="label">Name</label>
      <input class="input" name="name" placeholder="e.g. Telegram" required maxlength="60">
    </div>
    <div>
      <label class="label">Audio file (MP3, WAV, OGG, WebM, M4A · max 2 MB)</label>
      <input class="input !p-1.5" type="file" name="file" accept="audio/*" required>
    </div>
    <button name="action" value="sound_add" class="btn-primary justify-center">Upload</button>
  </form>
</div>

<div class="card overflow-x-auto">
  <table class="data-table">
    <thead>
      <tr><th>Name</th><th>Preview</th><th>Status</th><th>Added by</th><th class="text-right"></th></tr>
    </thead>
    <tbody>
      <?php if (!$sounds): ?><tr><td class="text-discord-500" colspan="5">No sounds yet. Upload one above.</td></tr><?php endif; ?>
      <?php foreach ($sounds as $s): ?>
      <tr>
        <td class="text-discord-200 font-medium"><?= h($s['name']) ?></td>
        <td>
          <audio controls preload="none" src="<?= h(url((string) $s['file'])) ?>" class="h-8 w-56 max-w-full"></audio>
        </td>
        <td><?= (int) $s['enabled'] === 1 ? '<span class="text-green-400">enabled</span>' : '<span class="text-red-400">disabled</span>' ?></td>
        <td class="text-discord-400"><?= h($s['created_by_name'] ?? 'system') ?></td>
        <td class="text-right whitespace-nowrap">
          <form method="post" action="/admin/action" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/sounds">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button name="action" value="sound_toggle" class="btn-ghost text-xs !py-1"><?= (int) $s['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/action" class="inline" data-confirm="Delete this sound? Users who selected it fall back to their defaults.">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/sounds">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button name="action" value="sound_del" class="btn-ghost text-xs text-red-400 !py-1">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

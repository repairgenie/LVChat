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

 $title = 'Channels'; $active = 'channels';
$pageActions = '<form method="get" action="/admin/channels" class="flex gap-2">
    <input class="input w-56" name="q" placeholder="Search channels…" value="' . h($term) . '">
    <button class="btn-primary">Search</button>
  </form>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="card overflow-x-auto">
  <table class="data-table">
    <thead>
      <tr><th>Channel</th><th>Topic</th><th>Founder</th><th>Members</th><th>Visibility</th><th class="text-right">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($channels as $c): ?>
      <tr class="align-top">
        <td>
          <a href="/c/<?= h(rawurlencode($c['slug'])) ?>" class="font-medium text-white hover:underline"><?= h($c['name']) ?></a>
          <a href="/admin/logs?channel=<?= h(rawurlencode($c['name'])) ?>" class="ml-1 text-[10px] text-blurple hover:underline">logs</a>
          <?php if ($c['forbidden']): ?><span class="ml-1 text-[10px] bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded">FORBIDDEN</span><?php endif; ?>
        </td>
        <td class="text-discord-300 max-w-60 truncate"><?= h($c['topic'] ?: '(none)') ?></td>
        <td class="text-discord-300"><?= h($c['owner'] ?? 'none') ?></td>
        <td><?= (int) $c['members'] ?></td>
        <td class="text-discord-300"><?= h($c['visibility']) ?><?= $c['invite_only'] ? ' +i' : '' ?><?= $c['moderated'] ? ' +m' : '' ?></td>
        <td>
          <details class="relative text-right">
            <summary class="btn-ghost text-xs !py-1 inline-flex cursor-pointer">Actions</summary>
            <div class="absolute right-0 mt-1 w-72 card p-3 space-y-2 z-20">
              <form method="post" action="/admin/action" class="flex gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="back" value="/admin/channels">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input class="input !py-1 text-xs flex-1" name="topic" placeholder="Force topic…" value="<?= h($c['topic']) ?>">
                <button name="action" value="channel_topic" class="btn-primary text-xs !py-1">Set</button>
              </form>
              <form method="post" action="/admin/action" class="flex gap-2 items-center">
                <?= Csrf::field() ?>
                <input type="hidden" name="back" value="/admin/channels">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <select name="visibility" class="input !py-1 text-xs flex-1">
                  <?php foreach (['public', 'private', 'secret', 'staff'] as $v): ?>
                  <option value="<?= $v ?>" <?= $c['visibility'] === $v ? 'selected' : '' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
                <button name="action" value="channel_visibility" class="btn-primary text-xs !py-1">Set</button>
              </form>
              <form method="post" action="/admin/action" class="flex gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="back" value="/admin/channels">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="forbid" value="<?= $c['forbidden'] ? 0 : 1 ?>">
                <button name="action" value="channel_forbid" class="btn-ghost text-xs !py-1"><?= $c['forbidden'] ? 'Un-forbid' : 'Forbid' ?></button>
                <button name="action" value="channel_drop" class="btn-ghost text-xs !py-1 text-red-400" onclick="event.preventDefault(); return LVCDialog.confirmSubmit(this, 'Drop this channel permanently?')">Drop</button>
              </form>
            </div>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

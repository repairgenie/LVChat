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

 $title = 'MOTD'; $active = 'motd'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Message of the day</h1>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<form method="post" action="/admin/action" class="card p-6 space-y-4 flex flex-col" style="height:calc(100vh - 20rem);min-height:24rem">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/motd">
  <div class="flex flex-col flex-1 min-h-0">
    <label class="label">MOTD text</label>
    <textarea name="motd" class="input font-mono flex-1 min-h-[200px]" placeholder="Welcome…"><?= h($motd) ?></textarea>
    <p class="text-xs text-discord-400 mt-1">Shown at the top of every chat window. Blank lines are fine.</p>
  </div>
  <button name="action" value="motd_save" class="btn-primary self-start">Save MOTD</button>
</form>

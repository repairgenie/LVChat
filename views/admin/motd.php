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

 $title = 'MOTD'; $active = 'motd'; require ROOT . '/views/admin/_nav.php'; ?>
<?php $pageTitle = 'Message of the day'; $pageSubtitle = 'Shown at the top of every chat window. Blank lines are fine.'; require ROOT . '/views/admin/_page_header.php'; ?>
<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-4">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/motd">
  <div>
    <label class="label">MOTD text</label>
    <textarea name="motd" class="input font-mono min-h-[220px]" placeholder="Welcome…"><?= h($motd) ?></textarea>
  </div>
  <button name="action" value="motd_save" class="btn-primary self-start">Save MOTD</button>
</form>

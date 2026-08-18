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

 $title = 'Blocked URLs'; $active = 'urls';
$pageTitle = 'Blocked URLs';
$pageSubtitle = 'A channel URL whose host equals a listed domain (or a subdomain of it) is rejected when set and hidden when rendered.';
$pageActions = '<details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add banned domain</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      ' . Csrf::field() . '
      <input type="hidden" name="back" value="/admin/urls">
      <div>
        <label class="label">Domain</label>
        <input class="input" name="domain" placeholder="e.g. example.com or *.example.com" required>
      </div>
      <div>
        <label class="label">Reason</label>
        <input class="input" name="reason" placeholder="Reason…">
      </div>
      <button name="action" value="banned_url_add" class="btn-primary w-full justify-center">Add domain</button>
    </form>
  </details>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="card overflow-x-auto">
  <table class="data-table">
    <thead>
      <tr><th>Domain</th><th>Reason</th><th>Added by</th><th>Added</th><th class="text-right"></th></tr>
    </thead>
    <tbody>
      <?php if (!$banned): ?><tr><td class="text-discord-500" colspan="5">No banned domains. Channel owners can embed any http(s) page.</td></tr><?php endif; ?>
      <?php foreach ($banned as $b): ?>
      <tr>
        <td class="font-mono text-red-400"><?= h($b['domain']) ?></td>
        <td class="text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="text-discord-400"><?= h($b['set_by_name'] ?? 'system') ?></td>
        <td class="text-discord-400"><?= h(date('M j Y', strtotime($b['created_at'] . ' UTC'))) ?></td>
        <td class="text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/urls"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="banned_url_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

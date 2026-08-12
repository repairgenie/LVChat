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

 $title = 'Webhooks'; $active = 'webhooks'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Webhooks</h1>
  <p class="text-sm text-discord-400">POST JSON (Discord-compatible) to a webhook URL to post into a channel as a bot.</p>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<?php if (!empty($_SESSION['webhook_token'])): ?>
<div class="card p-5 mb-5 border border-green-500/40">
  <div class="text-sm font-semibold text-green-400 mb-2">Webhook created — token shown once</div>
  <input class="input font-mono text-xs" readonly value="<?= h(url('/api/webhooks/' . $_SESSION['webhook_token'])) ?>"
         onfocus="this.select()">
  <p class="text-xs text-discord-400 mt-2">Copy this URL now. The raw token is not stored — if you lose it, delete and recreate the webhook.</p>
  <div class="mt-2"><code class="text-xs text-discord-300"><?= h('curl -X POST -H "Content-Type: application/json" -d \'{"content":"Hello from the forum!"}\' "' . url('/api/webhooks/' . $_SESSION['webhook_token']) . '"') ?></code></div>
</div>
<?php unset($_SESSION['webhook_token']); endif; ?>

<div class="card p-5 mb-5">
  <h2 class="font-semibold text-white mb-3">Create a webhook</h2>
  <form method="post" action="/admin/webhooks/action" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <?= Csrf::field() ?>
    <div>
      <label class="label">Channel</label>
      <select name="channel_id" class="input !py-1.5" required>
        <?php foreach ($channels as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Webhook name</label>
      <input class="input" name="name" placeholder="Forum bot" required minlength="2" maxlength="32">
    </div>
    <div>
      <label class="label">Avatar URL (https)</label>
      <input class="input" name="avatar" placeholder="https://example.com/bot.png">
    </div>
    <button name="action" value="webhook_create" class="btn-primary justify-center">Create</button>
  </form>
</div>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Name</th><th class="px-4 py-2">Channel</th><th class="px-4 py-2">Avatar</th>
      <th class="px-4 py-2">Status</th><th class="px-4 py-2">Last used</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$hooks): ?><tr><td class="px-4 py-3 text-discord-500" colspan="6">No webhooks yet.</td></tr><?php endif; ?>
      <?php foreach ($hooks as $w): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 text-discord-200 font-medium"><?= h($w['name']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($w['channel_name'] ?? '?') ?></td>
        <td class="px-4 py-2"><?= $w['avatar'] ? '<img src="' . h($w['avatar']) . '" class="w-6 h-6 rounded-full object-cover" alt="">' : '<span class="text-discord-500">—</span>' ?></td>
        <td class="px-4 py-2"><?= (int) $w['enabled'] === 1 ? '<span class="text-green-400">enabled</span>' : '<span class="text-red-400">disabled</span>' ?></td>
        <td class="px-4 py-2 text-discord-400"><?= $w['last_used'] ? relative_time($w['last_used']) : 'never' ?></td>
        <td class="px-4 py-2 text-right">
          <form method="post" action="/admin/webhooks/action" class="inline" data-confirm="Delete this webhook? Its token stops working immediately.">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button name="action" value="webhook_delete" class="btn-ghost text-xs text-red-400 !py-1">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

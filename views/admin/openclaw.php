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

 $title = 'OpenClaw Bots'; $active = 'openclaw';
$pageSubtitle = 'Manage AI bots that connect to LVChat via the OpenClaw API. Assign bots to channels and grant PM access.';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<?php if (!empty($_SESSION['openclaw_api_key'])): ?>
<div class="card p-5 mb-5 border border-green-500/40">
  <div class="text-sm font-semibold text-green-400 mb-2">Bot created — API key shown once</div>
  <input class="input font-mono text-xs" readonly value="<?= h($_SESSION['openclaw_api_key']) ?>" onfocus="this.select()">
  <p class="text-xs text-discord-400 mt-2">Copy this key now. It is used as a Bearer token for all <code>/api/openclaw/*</code> endpoints. The raw key is not stored — if you lose it, delete and recreate the bot.</p>
</div>
<?php unset($_SESSION['openclaw_api_key']); endif; ?>

<div class="card p-5 mb-5">
  <h2 class="font-semibold text-white mb-3">Create a bot</h2>
  <form method="post" action="/admin/action" class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/openclaw">
    <div>
      <label class="label">Bot name</label>
      <input class="input" name="name" placeholder="Molty" required minlength="2" maxlength="32">
    </div>
    <div>
      <label class="label">Avatar URL (https)</label>
      <input class="input" name="avatar" placeholder="https://example.com/bot.png">
    </div>
    <div class="md:col-span-2">
      <label class="label">System prompt</label>
      <textarea class="input" name="system_prompt" rows="3" placeholder="You are a helpful assistant..."></textarea>
    </div>
    <button name="action" value="openclaw_create" class="btn-primary justify-center md:col-span-2">Create Bot</button>
  </form>
</div>

<?php if (!$bots): ?>
<div class="empty-state">No OpenClaw bots configured yet.</div>
<?php else: ?>
<?php foreach ($bots as $bot): ?>
<div class="card p-5 mb-4">
  <div class="flex items-center justify-between mb-3">
    <div class="flex items-center gap-3">
      <?php if ($bot['avatar']): ?>
        <img src="<?= h($bot['avatar']) ?>" class="w-8 h-8 rounded-full object-cover" alt="">
      <?php endif; ?>
      <div>
        <span class="text-white font-semibold"><?= h($bot['name']) ?></span>
        <span class="text-discord-400 text-sm ml-2">@<?= h($bot['username']) ?></span>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <span class="px-1.5 py-0.5 rounded text-[11px] <?= (int) $bot['enabled'] === 1 ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
        <?= (int) $bot['enabled'] === 1 ? 'ENABLED' : 'DISABLED' ?>
      </span>
      <form method="post" action="/admin/action" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/openclaw">
        <input type="hidden" name="id" value="<?= (int) $bot['id'] ?>">
        <button name="action" value="openclaw_toggle" class="btn-ghost text-xs !py-1">Toggle</button>
      </form>
      <form method="post" action="/admin/action" class="inline" data-confirm="Delete this bot? Its user account will also be deleted.">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/openclaw">
        <input type="hidden" name="id" value="<?= (int) $bot['id'] ?>">
        <button name="action" value="openclaw_delete" class="btn-ghost text-xs text-red-400 !py-1">Delete</button>
      </form>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
      <h3 class="text-sm font-semibold text-discord-300 mb-2">Assigned Channels</h3>
      <?php $assigned = $botChannels[(int) $bot['id']] ?? []; ?>
      <?php if ($assigned): ?>
        <div class="space-y-1 mb-2">
          <?php foreach ($assigned as $ac): ?>
            <div class="flex items-center justify-between text-sm bg-discord-900/50 rounded px-2 py-1">
              <span class="text-discord-200"><?= h($ac['name']) ?></span>
              <div class="flex items-center gap-2">
                <span class="text-[10px] text-discord-400 uppercase"><?= h($ac['respond_mode']) ?></span>
                <form method="post" action="/admin/action" class="inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="back" value="/admin/openclaw">
                  <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
                  <input type="hidden" name="channel_id" value="<?= (int) $ac['id'] ?>">
                  <button name="action" value="openclaw_remove_channel" class="text-red-400 hover:text-red-300 text-xs">&times;</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-xs text-discord-500 mb-2">Not assigned to any channels.</p>
      <?php endif; ?>
      <form method="post" action="/admin/action" class="flex gap-2 items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/openclaw">
        <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
        <select name="channel_id" class="input !py-1 text-sm flex-1" required>
          <option value="">Add channel...</option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="respond_mode" class="input !py-1 text-sm">
          <option value="mentions">@mentions</option>
          <option value="all">All messages</option>
          <option value="commands">/commands</option>
        </select>
        <button name="action" value="openclaw_assign_channel" class="btn-primary text-xs !py-1.5">Add</button>
      </form>
    </div>

    <div>
      <h3 class="text-sm font-semibold text-discord-300 mb-2">PM Access</h3>
      <?php $pmUsers = $botPmUsers[(int) $bot['id']] ?? []; ?>
      <?php if ($pmUsers): ?>
        <div class="space-y-1 mb-2">
          <?php foreach ($pmUsers as $pu): ?>
            <div class="flex items-center justify-between text-sm bg-discord-900/50 rounded px-2 py-1">
              <span class="text-discord-200"><?= h($pu['username']) ?></span>
              <form method="post" action="/admin/action" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="back" value="/admin/openclaw">
                <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
                <input type="hidden" name="user_id" value="<?= (int) $pu['id'] ?>">
                <button name="action" value="openclaw_pm_revoke" class="text-red-400 hover:text-red-300 text-xs">&times;</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-xs text-discord-500 mb-2">No users granted PM access.</p>
      <?php endif; ?>
      <form method="post" action="/admin/action" class="flex gap-2 items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/openclaw">
        <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
        <input class="input !py-1 text-sm flex-1" name="username" placeholder="Username..." required>
        <button name="action" value="openclaw_pm_grant" class="btn-primary text-xs !py-1.5">Grant</button>
      </form>
    </div>
  </div>

  <div class="mt-3 pt-3 border-t border-discord-700">
    <div class="text-xs text-discord-500">
      Last seen: <?= $bot['last_seen'] ? relative_time($bot['last_seen']) : 'never' ?>
      &middot; Created <?= relative_time($bot['created_at']) ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

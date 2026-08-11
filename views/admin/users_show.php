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

 $title = $user['username'] . ' — Moderation'; $active = 'users'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Moderation history — <?= h($user['username']) ?></h1>
  <a href="/admin/users" class="btn-ghost text-xs">← Back to users</a>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-1 space-y-5">
    <div class="card p-5">
      <div class="flex items-center gap-3">
        <?= avatar_img($user, 'w-12 h-12 rounded-full') ?>
        <div>
          <div class="font-semibold text-white"><?= h($user['username']) ?></div>
          <div class="text-xs text-discord-400"><?= h($user['email']) ?></div>
        </div>
      </div>
      <div class="mt-3 flex flex-wrap gap-2">
        <?php
        $status = $user['status'] ?? 'active';
        $statusColor = $status === 'active' ? 'bg-green-500/20 text-green-400' : ($status === 'pending' ? 'bg-amber-500/20 text-amber-300' : 'bg-red-500/20 text-red-400');
        ?>
        <span class="px-2 py-0.5 rounded text-[11px] font-semibold <?= $statusColor ?>"><?= h($status) ?></span>
        <?php if ($user['banned']): ?><span class="px-2 py-0.5 rounded text-[11px] bg-red-500/20 text-red-400">banned</span><?php endif; ?>
        <span class="px-2 py-0.5 rounded text-[11px] bg-discord-700 text-discord-300"><?= h($user['role']) ?></span>
        <?php if ($user['age_verified_at']): ?><span class="px-2 py-0.5 rounded text-[11px] bg-blurple/20 text-blurple">18+ certified</span><?php endif; ?>
        <?php if (!empty($user['totp_enabled_at'])): ?><span class="px-2 py-0.5 rounded text-[11px] bg-green-500/20 text-green-400">MFA enabled</span><?php endif; ?>
      </div>
      <?php if ($user['status_reason']): ?>
      <div class="mt-3 text-xs text-discord-300 bg-discord-850 border border-discord-700 rounded px-3 py-2"><?= h($user['status_reason']) ?></div>
      <?php endif; ?>
      <div class="mt-3 text-xs text-discord-400">Registered <?= h(date('M j, Y', strtotime($user['registered_at'] . ' UTC'))) ?> · Last seen <?= h(relative_time($user['last_seen'])) ?> · IP <span class="font-mono"><?= h($user['last_ip'] ?? '—') ?></span></div>
    </div>

    <div class="card p-5">
      <h2 class="font-semibold text-white mb-3">Set status</h2>
      <?php if ($status !== 'active'): ?>
      <form method="post" action="/admin/action" class="mb-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_approve">
        <button class="btn-primary w-full justify-center text-xs">Approve account</button>
      </form>
      <?php endif; ?>
      <form method="post" action="/admin/action" class="mb-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_pending">
        <input class="input mb-1" name="reason" placeholder="Reason (optional)…">
        <button class="btn-ghost w-full justify-center text-xs">Set pending</button>
      </form>
      <form method="post" action="/admin/action" class="mb-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_suspend">
        <input class="input mb-1" name="reason" placeholder="Reason (required)…" required>
        <button class="btn-ghost w-full justify-center text-xs text-red-400">Suspend account</button>
      </form>
      <form method="post" action="/admin/action" class="mb-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_activate">
        <button class="btn-ghost w-full justify-center text-xs text-green-400">Activate (clear suspension)</button>
      </form>
      <?php if ($user['banned']): ?>
      <form method="post" action="/admin/action">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_unban">
        <button class="btn-ghost w-full justify-center text-xs">Unban (IRC)</button>
      </form>
      <?php endif; ?>
      <?php if (!empty($user['totp_enabled_at'])): ?>
      <form method="post" action="/admin/action" onsubmit="return confirm('Reset MFA for this user? Their active sessions will end and they will need to set MFA up again.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_mfa_reset">
        <button class="btn-ghost w-full justify-center text-xs text-amber-300">Reset MFA</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="lg:col-span-2 space-y-5">
    <div class="card p-5">
      <h2 class="font-semibold text-white mb-3">Add a note</h2>
      <form method="post" action="/admin/action" class="flex gap-2 items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/admin/users/<?= (int) $user['id'] ?>">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="user_note">
        <input class="input flex-1" name="reason" placeholder="Staff comment — only admins and staff can see this…" required>
        <button class="btn-primary !py-2 text-xs">Add note</button>
      </form>
    </div>

    <div class="card overflow-x-auto">
      <div class="px-4 py-3 border-b border-discord-700 text-sm font-semibold text-white">Timeline</div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
            <th class="px-4 py-2">When</th>
            <th class="px-4 py-2">Action</th>
            <th class="px-4 py-2">By</th>
            <th class="px-4 py-2">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $n): ?>
          <tr class="border-b border-discord-800 align-top">
            <td class="px-4 py-2 text-discord-400 whitespace-nowrap"><?= h(gmdate('M j Y H:i', strtotime($n['created_at'] . ' UTC'))) ?></td>
            <td class="px-4 py-2">
              <span class="px-1.5 py-0.5 rounded text-[11px] <?= $n['action'] === 'note' ? 'bg-discord-700 text-discord-300' : ($n['action'] === 'suspend' ? 'bg-red-500/20 text-red-400' : 'bg-blurple/20 text-blurple') ?>"><?= h(ModerationService::label($n['action'])) ?></span>
            </td>
            <td class="px-4 py-2 text-discord-300"><?= h($n['actor_name'] ?? 'system') ?></td>
            <td class="px-4 py-2 text-discord-200 whitespace-pre-wrap"><?= h($n['reason']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$history): ?>
          <tr><td colspan="4" class="px-4 py-4 text-discord-500">No moderation history yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($events): ?>
    <div class="card overflow-x-auto">
      <div class="px-4 py-3 border-b border-discord-700 text-sm font-semibold text-white">Queue events for this account</div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
            <th class="px-4 py-2">When</th>
            <th class="px-4 py-2">Type</th>
            <th class="px-4 py-2">Action</th>
            <th class="px-4 py-2">Match</th>
            <th class="px-4 py-2">Content</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $e): ?>
          <tr class="border-b border-discord-800 align-top">
            <td class="px-4 py-2 text-discord-400 whitespace-nowrap"><?= h(gmdate('M j H:i', strtotime($e['created_at'] . ' UTC'))) ?></td>
            <td class="px-4 py-2"><?= h($e['kind']) ?></td>
            <td class="px-4 py-2"><?= h($e['action']) ?></td>
            <td class="px-4 py-2 font-mono text-[11px] text-discord-300"><?= h($e['match']) ?></td>
            <td class="px-4 py-2 text-discord-300"><?= h(mb_strimwidth($e['content'], 0, 80, '…')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

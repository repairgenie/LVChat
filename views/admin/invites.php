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

 $title = 'Invites'; $active = 'invites'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Invites</h1>
  <p class="text-sm text-discord-400">Email people a link that lets them create an account</p>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<?php if ($lastLink): ?>
<div class="card p-5 mb-5 border border-green-500/40">
  <div class="text-sm font-semibold text-green-400 mb-2">New invite link — copy and share it</div>
  <input class="input font-mono text-xs" readonly value="<?= h($lastLink) ?>" onfocus="this.select()">
  <p class="text-xs text-discord-400 mt-2">The email could not be sent (or SMTP is not configured), so share this link directly. It works for 7 days.</p>
</div>
<?php endif; ?>

<div class="card p-5 mb-5">
  <h2 class="font-semibold text-white mb-3">Invite someone</h2>
  <form method="post" action="/admin/action" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/invites">
    <div>
      <label class="label">Email address</label>
      <input class="input" type="email" name="email" required placeholder="friend@example.com">
    </div>
    <div>
      <label class="label">Personal message (optional)</label>
      <input class="input" name="message" placeholder="Join our chat!">
    </div>
    <button name="action" value="invite_create" class="btn-primary justify-center">Send invite</button>
  </form>
  <?php if (!$smtp): ?>
  <div class="mt-3 rounded-md bg-amber-500/10 border border-amber-500/30 text-amber-300 px-3 py-2 text-sm">
    SMTP is not configured yet, so the email won't be delivered — the invite link is still created and shown above.
    Set it up under <a class="underline" href="/admin/settings">Settings → Email (SMTP)</a>.
  </div>
  <?php endif; ?>
</div>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
        <th class="px-4 py-2">Email</th>
        <th class="px-4 py-2">Status</th>
        <th class="px-4 py-2">Invited by</th>
        <th class="px-4 py-2">Expires</th>
        <th class="px-4 py-2 text-right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$invites): ?><tr><td class="px-4 py-3 text-discord-500" colspan="5">No invites yet.</td></tr><?php endif; ?>
      <?php foreach ($invites as $i): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 text-discord-200"><?= h($i['email']) ?>
          <?php if (!empty($i['message'])): ?><span class="block text-xs text-discord-500">"<?= h($i['message']) ?>"</span><?php endif; ?>
        </td>
        <td class="px-4 py-2">
          <?php if (!empty($i['used_at'])): ?>
          <span class="text-green-400">used by <?= h($i['used_by_name'] ?? '?') ?></span>
          <?php elseif (strtotime((string) $i['expires_at'] . ' UTC') < time()): ?>
          <span class="text-red-400">expired</span>
          <?php else: ?>
          <span class="text-blurple">pending</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-discord-400"><?= h($i['invited_by_name'] ?? '—') ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(date('M j Y', strtotime($i['expires_at'] . ' UTC'))) ?></td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <?php if (empty($i['used_at']) && strtotime((string) $i['expires_at'] . ' UTC') >= time()): ?>
          <button class="btn-ghost text-xs !py-1" onclick="promptCopy('Invite link', '<?= h(InviteService::link((string) $i['token'])) ?>')">Copy link</button>
          <form method="post" action="/admin/action" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/invites">
            <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <button name="action" value="invite_resend" class="btn-ghost text-xs !py-1">Resend</button>
          </form>
          <?php endif; ?>
          <form method="post" action="/admin/action" class="inline" onsubmit="return confirm('Revoke this invite? Its link stops working immediately.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/invites">
            <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <button name="action" value="invite_revoke" class="btn-ghost text-xs !py-1 text-red-400">Revoke</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function promptCopy(title, value) {
  navigator.clipboard.writeText(value).then(function() {
    alert(title + ' copied to clipboard.');
  }).catch(function() {
    const ta = document.createElement('textarea');
    ta.value = value;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    alert(title + ' copied to clipboard: ' + value);
  });
}
</script>

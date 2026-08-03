<?php
$isStaff = ModerationService::isStaff($user);
$isOwner = $ticket['user_id'] !== null && (int) $ticket['user_id'] === (int) $user['id'];
$back = $isStaff ? '/admin/support' : '/support';
?>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold text-white">Ticket #<?= (int) $ticket['id'] ?> — <?= h($ticket['subject']) ?></h1>
    <a href="<?= h($back) ?>" class="btn-ghost text-xs">← Back</a>
  </div>

  <?php if ($error): ?>
  <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-2.5 text-sm"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card p-4 mb-4 text-sm flex flex-wrap gap-x-4 gap-y-2 items-center">
    <span>Status:
      <span class="px-1.5 py-0.5 rounded text-[11px] <?= $ticket['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($ticket['status'] === 'answered' ? 'bg-amber-500/20 text-amber-300' : 'bg-discord-700 text-discord-400') ?>"><?= h($ticket['status']) ?></span>
    </span>
    <?php if ($isStaff): ?>
    <span>
      Contact:
      <?php if ($ticket['user_id'] !== null): ?>
      <a class="text-blurple hover:underline" href="/admin/users/<?= (int) $ticket['user_id'] ?>"><?= h($ticket['username']) ?></a>
      <?php else: ?>
      <span class="text-blurple"><?= h($ticket['email']) ?></span>
      <?php endif; ?>
      <?php if ($contactEmail && ($ticket['user_id'] === null || ($ticket['email'] ?? '') !== '')): ?>
      <span class="text-discord-500">· emails to <?= h($contactEmail) ?></span>
      <?php endif; ?>
    </span>
    <span class="text-discord-400"><?= h(gmdate('M j Y H:i', strtotime($ticket['created_at'] . ' UTC'))) ?></span>
    <?php if ($isStaff && $staff): ?>
    <form method="post" action="/admin/support/<?= (int) $ticket['id'] ?>/assign" class="flex items-center gap-1 ml-auto">
      <?= Csrf::field() ?>
      <label class="text-discord-400 text-xs">Assignee</label>
      <select name="assigned_to" class="text-xs bg-discord-750 border border-discord-600 rounded px-1.5 py-0.5" onchange="this.form.submit()">
        <option value="">Unassigned</option>
        <?php foreach ($staff as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= (int) $ticket['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($ticket['closed_at']): ?><span class="text-discord-400">Closed <?= h(gmdate('M j Y H:i', strtotime($ticket['closed_at'] . ' UTC'))) ?></span><?php endif; ?>
  </div>

  <div class="space-y-4 mb-6">
    <?php foreach ($replies as $r): $staff = (int) $r['is_staff'] === 1; ?>
    <div class="card p-4 <?= $staff ? 'border-blurple/40' : '' ?>">
      <div class="flex items-baseline gap-2 mb-2 text-xs">
        <span class="font-semibold <?= $staff ? 'text-blurple' : 'text-white' ?>"><?= h($r['username'] ?? 'deleted user') ?><?= $staff ? ' (staff)' : '' ?></span>
        <span class="text-discord-500"><?= h(gmdate('M j Y H:i', strtotime($r['created_at'] . ' UTC'))) ?></span>
      </div>
      <div class="text-[15px] leading-relaxed text-discord-200 whitespace-pre-wrap"><?= h($r['content']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($ticket['status'] !== 'closed'): ?>
  <form method="post" action="/support/<?= (int) $ticket['id'] ?>/reply" class="card p-5 space-y-3">
    <?= Csrf::field() ?>
    <label class="label"><?= $isStaff ? 'Reply (the user is emailed on your reply)' : 'Add a reply' ?></label>
    <textarea class="input" name="content" rows="4" required placeholder="Type your message…"></textarea>
    <div class="flex gap-2">
      <button class="btn-primary justify-center flex-1">Send reply</button>
      <?php if ($isOwner || $isStaff): ?>
      <button class="btn-ghost" formaction="/support/<?= (int) $ticket['id'] ?>/close">Close ticket</button>
      <?php endif; ?>
    </div>
  </form>
  <?php else: ?>
  <?php if ($isStaff): ?>
  <form method="post" action="/support/<?= (int) $ticket['id'] ?>/reopen" class="card p-5">
    <?= Csrf::field() ?>
    <button class="btn-ghost justify-center">Reopen ticket</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

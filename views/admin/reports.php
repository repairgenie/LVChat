<?php $title = 'Reports'; $active = 'reports'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Message reports</h1>
  <div class="flex gap-1">
    <?php foreach (['open', 'investigated', 'resolved', 'dismissed', 'all'] as $s): ?>
    <a href="/admin/reports<?= $s === 'all' ? '' : '?status=' . $s ?>" class="px-2.5 py-1 rounded-md text-sm <?= ($status === $s || ($s === 'all' && !in_array($status, ['open', 'investigated', 'resolved', 'dismissed'], true))) ? 'bg-blurple text-white' : 'bg-discord-750 text-discord-300 hover:text-white' ?>"><?= h(ucfirst($s)) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<?php if (!$reports): ?>
<div class="card p-6 text-sm text-discord-400">No reports here.</div>
<?php endif; ?>

<?php foreach ($reports as $r):
  $sender = $r['sender_name'];
  $reporter = $r['reporter_name'] ?: $r['reporter_guest_name'];
?>
<div class="card p-5 mb-4">
  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
    <span class="text-discord-400">Reported message</span>
    <span class="text-discord-300">from <span class="text-white font-medium"><?= h($sender) ?></span><?= $r['sender_guest_id'] ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
    <span class="text-discord-500">·</span>
    <span class="text-discord-400">reported by <span class="text-white"><?= h($reporter) ?></span><?= $r['reporter_guest_id'] ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
    <span class="text-discord-500">·</span>
    <span class="text-discord-400"><?= h(gmdate('M j Y H:i', strtotime($r['created_at'] . ' UTC'))) ?></span>
    <span class="text-discord-500">·</span>
    <span class="px-1.5 py-0.5 rounded text-[11px] <?= $r['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($r['status'] === 'dismissed' ? 'bg-discord-700 text-discord-400' : 'bg-green-500/20 text-green-400') ?>"><?= h($r['status']) ?></span>
    <?php if ($r['pm']): ?><span class="text-[10px] text-discord-500">private message</span><?php endif; ?>
    <a href="/u/<?= h(rawurlencode($sender)) ?>" class="ml-auto btn-ghost text-xs !py-1">View sender</a>
  </div>

  <div class="mt-3 rounded-lg bg-discord-850 border border-discord-700 px-4 py-3">
    <div class="text-[11px] uppercase tracking-wide text-discord-400 font-semibold mb-1">Reason: <?= h($r['reason']) ?></div>
    <?php if ($r['reason_other'] !== ''): ?>
    <div class="text-xs text-discord-300 italic mb-2">"<?= h($r['reason_other']) ?>"</div>
    <?php endif; ?>
    <div class="msg-content text-[15px] leading-[1.4] text-discord-200 break-words"><?= chat_content_html(['kind' => $r['pm'] ? 'message' : (str_starts_with($r['content'], '/uploads/') || str_starts_with($r['content'], '/assets/') ? 'image' : 'message'), 'content' => $r['content']]) ?></div>
  </div>

  <?php if ($r['handled_name']): ?>
  <div class="mt-2 text-xs text-discord-400">Handled by <?= h($r['handled_name']) ?> <?= h(gmdate('M j Y H:i', strtotime($r['handled_at'] . ' UTC'))) ?><?= $r['resolution'] !== '' ? ' — ' . h($r['resolution']) : '' ?></div>
  <?php endif; ?>

  <form method="post" action="/admin/action" class="mt-3 flex flex-wrap gap-2 items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/reports<?= in_array($status, ['open', 'investigated', 'resolved', 'dismissed'], true) ? '?status=' . $status : '' ?>">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <input class="input flex-1 min-w-52" name="resolution" placeholder="Resolution note (shown to the sender's timeline)…">
    <button name="action" value="report_status" class="btn-ghost text-xs !py-2">Mark investigated</button>
    <input type="hidden" name="status" value="investigated">
  </form>
  <form method="post" action="/admin/action" class="mt-2 flex flex-wrap gap-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/reports<?= in_array($status, ['open', 'investigated', 'resolved', 'dismissed'], true) ? '?status=' . $status : '' ?>">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <input type="hidden" name="status" value="resolved">
    <button name="action" value="report_status" class="btn-ghost text-xs !py-1 text-green-400">Resolve</button>
  </form>
  <form method="post" action="/admin/action" class="mt-2 flex flex-wrap gap-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/reports<?= in_array($status, ['open', 'investigated', 'resolved', 'dismissed'], true) ? '?status=' . $status : '' ?>">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <input type="hidden" name="status" value="dismissed">
    <button name="action" value="report_status" class="btn-ghost text-xs !py-1 text-discord-400">Dismiss</button>
  </form>
</div>
<?php endforeach; ?>

<?php $title = 'Admin dashboard'; $active = 'overview'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Admin dashboard</h1>
  <span class="text-sm text-discord-400">Signed in as <?= h($admin['username']) ?></span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <?php foreach ($stats as $label => $val): ?>
  <div class="card p-4">
    <div class="text-2xl font-bold text-white"><?= (int) $val ?></div>
    <div class="text-xs text-discord-400 mt-1"><?= h($label) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="card p-4">
    <h2 class="font-semibold text-white mb-3">Recent audit events</h2>
    <div class="space-y-1.5">
      <?php if (!$recentAudit): ?><div class="text-sm text-discord-500">No audit events yet.</div><?php endif; ?>
      <?php foreach ($recentAudit as $a): ?>
      <div class="text-sm text-discord-300">
        <span class="text-discord-500 text-xs"><?= h(date('M j H:i', strtotime($a['created_at'] . ' UTC'))) ?></span>
        <span class="text-blurple"><?= h($a['action']) ?></span>
        <?php if ($a['target']): ?>→ <span class="text-discord-200"><?= h($a['target']) ?></span><?php endif; ?>
        <span class="text-discord-500">by <?= h($a['username'] ?? 'system') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card p-4">
    <h2 class="font-semibold text-white mb-3">Banned / restricted users</h2>
    <div class="space-y-1.5">
      <?php if (!$banned): ?><div class="text-sm text-discord-500">No banned users.</div><?php endif; ?>
      <?php foreach ($banned as $u): ?>
      <div class="flex items-center justify-between text-sm">
        <span class="text-discord-200"><?= h($u['username']) ?></span>
        <span class="text-discord-400"><?= h($u['ban_reason'] ?: ($u['kind'] ?? '')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4 text-xs text-discord-500">Tip: use /global to announce to all channels, /kline & /shun via chat, or the Bans page.</div>
  </div>
</div>

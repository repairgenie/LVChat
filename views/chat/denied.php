<?php $title = 'Access denied'; ?>
<div class="max-w-md mx-auto text-center">
  <div class="card p-8">
    <div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-red-500/10 flex items-center justify-center text-2xl">🚫</div>
    <h1 class="text-xl font-bold text-white"><?= h($channel['name']) ?></h1>
    <p class="text-sm text-discord-400 mt-2 mb-6"><?= h($reason) ?></p>
    <?php if ((int) $channel['invite_only'] === 1): ?>
    <form method="post" action="/api/command" class="space-y-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="channel" value="<?= h($channel['slug']) ?>">
      <input type="hidden" name="text" value="/knock <?= h($channel['name']) ?>">
      <button class="btn-ghost w-full justify-center py-2.5">Request access (knock)</button>
    </form>
    <?php endif; ?>
    <a href="/browse" class="btn-primary w-full justify-center py-2.5 mt-3">Browse other channels</a>
  </div>
</div>

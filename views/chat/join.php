<?php $title = 'Join ' . $channel['name']; ?>
<div class="max-w-md mx-auto">
  <div class="card p-8 text-center">
    <div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-blurple flex items-center justify-center text-2xl font-bold text-white">#</div>
    <h1 class="text-xl font-bold text-white"><?= h($channel['name']) ?></h1>
    <p class="text-sm text-discord-400 mt-1 mb-6">This channel requires a key to join.</p>
    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/c/<?= h(rawurlencode($channel['slug'])) ?>/join" class="space-y-4">
      <?= Csrf::field() ?>
      <input class="input text-center" type="password" name="key" placeholder="Channel key" required autofocus>
      <button class="btn-primary w-full justify-center py-2.5">Join <?= h($channel['name']) ?></button>
    </form>
  </div>
</div>

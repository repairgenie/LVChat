<?php $site = config_get('site_name', 'LVChat'); ?>
<div class="max-w-md mx-auto">
  <div class="card p-8 text-center">
    <?= logo_mark('mx-auto mb-4 w-14 h-14 rounded-2xl text-2xl') ?>
    <h1 class="text-xl font-bold text-white"><?= h($channel['name']) ?></h1>
    <?php if (!empty($channel['topic'])): ?>
    <p class="text-sm text-discord-400 mt-1 mb-6"><?= h($channel['topic']) ?></p>
    <?php else: ?>
    <p class="text-sm text-discord-400 mt-1 mb-6">Join the conversation on <?= h($site) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/guest" class="space-y-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <input class="input text-center" name="nick" placeholder="Guest nickname" maxlength="32" required autocomplete="off" autofocus>
      <button class="btn-primary w-full justify-center py-2.5">Chat as guest</button>
    </form>

    <div class="mt-3 grid grid-cols-2 gap-2">
      <a href="/login?next=<?= h(rawurlencode($next)) ?>" class="btn-ghost justify-center">Log in</a>
      <?php if (config_get('registration_enabled', '1') === '1'): ?>
      <a href="/register?next=<?= h(rawurlencode($next)) ?>" class="btn-ghost justify-center">Register</a>
      <?php endif; ?>
    </div>
  </div>
</div>

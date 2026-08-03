<div class="max-w-md mx-auto">
  <div class="card p-8">
    <div class="flex items-center gap-3 mb-8">
      <?= logo_mark() ?>
      <div>
        <h1 class="text-xl font-bold text-white">Welcome back</h1>
        <p class="text-sm text-discord-400"><?= h(config_get('site_name', 'LVChat')) ?></p>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="space-y-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <div>
        <label class="label" for="username">Username</label>
        <input class="input" id="username" name="username" required autofocus autocomplete="username">
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Log in</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      Need an account?
      <?php if (config_get('registration_enabled', '1') === '1'): ?>
      <a class="text-blurple hover:underline" href="/register?next=<?= h(rawurlencode($next)) ?>">Register</a>
      <?php else: ?>
      Registration is currently closed — but invite links still work.
      <?php endif; ?>
    </p>

    <div class="mt-8 pt-6 border-t border-discord-700">
      <h2 class="text-sm font-semibold text-white">Prefer to stay anonymous?</h2>
      <p class="text-xs text-discord-400 mt-1 mb-3">Join instantly with a nickname — no email, no password, no account. You can chat in existing channels right away.</p>
      <form method="post" action="/guest" class="flex gap-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <input class="input flex-1" name="nick" placeholder="Your nickname" maxlength="32" required autocomplete="off">
        <button class="btn-ghost">Join as guest</button>
      </form>
    </div>
  </div>
</div>

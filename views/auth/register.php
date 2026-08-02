<div class="max-w-md mx-auto">
  <div class="card p-8">
    <div class="flex items-center gap-3 mb-8">
      <div class="w-12 h-12 rounded-2xl bg-blurple flex items-center justify-center text-2xl font-bold text-white">#</div>
      <div>
        <h1 class="text-xl font-bold text-white">Create an account</h1>
        <p class="text-sm text-discord-400">One account for nicks, channels and private messages</p>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/register" class="space-y-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <div>
        <label class="label" for="username">Username</label>
        <input class="input" id="username" name="username" required autofocus value="<?= h($old['username'] ?? '') ?>">
        <p class="text-xs text-discord-400 mt-1">2-32 characters, letters, numbers, and - _ [ ] { } ^ ` |</p>
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <input class="input" id="email" type="email" name="email" required value="<?= h($old['email'] ?? '') ?>">
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input class="input" id="password" type="password" name="password" required minlength="8">
        <p class="text-xs text-discord-400 mt-1">At least 8 characters</p>
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Create account</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      Already registered?
      <a class="text-blurple hover:underline" href="/login?next=<?= h(rawurlencode($next)) ?>">Log in</a>
    </p>
  </div>
</div>

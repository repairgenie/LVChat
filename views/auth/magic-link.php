<div class="max-w-md mx-auto">
  <div class="card p-8">
    <?php if (site_logo()): ?>
    <img src="<?= h(site_logo()) ?>" alt="" class="w-full h-auto object-contain mb-8">
    <?php endif; ?>
    <div class="flex items-center gap-3 mb-8">
      <div>
        <h1 class="text-xl font-bold text-white">Log in with magic link</h1>
        <p class="text-sm text-discord-400"><?= h(config_get('site_name', 'LVChat')) ?></p>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <p class="text-sm text-discord-300 mb-4">Enter the email address on your account and we'll send you a link that logs you in instantly — no password needed.</p>

    <form method="post" action="/magic-link" class="space-y-4">
      <?= Csrf::field() ?>
      <div>
        <label class="label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" required autofocus autocomplete="email">
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Send login link</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      Prefer a password?
      <a class="text-blurple hover:underline" href="/login">Back to login</a>
    </p>
  </div>
</div>

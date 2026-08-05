<?php $title = 'Two-factor authentication'; ?>
<div class="max-w-md mx-auto">
  <div class="card p-8">
    <?php if (site_logo()): ?>
    <img src="<?= h(site_logo()) ?>" alt="" class="w-full h-auto object-contain mb-8">
    <?php endif; ?>
    <div class="mb-8">
      <h1 class="text-xl font-bold text-white">Two-factor authentication</h1>
      <p class="text-sm text-discord-400 mt-1">Enter the 6-digit code from your authenticator app to finish signing in.</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login/mfa" class="space-y-4">
      <?= Csrf::field() ?>
      <div>
        <label class="label" for="code">Authentication code</label>
        <input class="input text-center text-lg tracking-[0.4em] font-mono" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus autocomplete="one-time-code" placeholder="000000">
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Verify</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      Lost access to your authenticator? Ask an administrator to reset MFA on your account.
    </p>
  </div>
</div>

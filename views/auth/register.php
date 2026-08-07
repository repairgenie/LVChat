<div class="max-w-md mx-auto">
  <div class="card p-8">
    <div class="flex items-center gap-3 mb-8">
      <?= logo_mark() ?>
      <div>
        <h1 class="text-xl font-bold text-white">Create an account</h1>
        <p class="text-sm text-discord-400">One account for nicks, channels and private messages</p>
      </div>
    </div>

    <?php if ($invite): ?>
    <div class="mb-4 rounded-md bg-blurple/20 border border-blurple/40 text-blurple px-3 py-2 text-sm">
      You've been invited to join <strong><?= h(config_get('site_name', 'LVChat')) ?></strong>.
      <?php if (!empty($invite['message'])): ?><br><em>"<?= h($invite['message']) ?>"</em><?php endif; ?>
    </div>
    <?php elseif (!$registration_open): ?>
    <div class="mb-4 rounded-md bg-amber-500/10 border border-amber-500/30 text-amber-300 px-3 py-2 text-sm">
      Registration is currently closed — only invite links can create accounts.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/register" class="space-y-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <div class="absolute left-[-9999px] top-auto" aria-hidden="true">
        <label for="website">Leave this field empty</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
      </div>
      <?php if ($invite): ?>
      <input type="hidden" name="invite" value="<?= h($invite['token']) ?>">
      <?php endif; ?>
      <div>
        <label class="label" for="username">Username</label>
        <input class="input" id="username" name="username" required autofocus value="<?= h($old['username'] ?? '') ?>">
        <p class="text-xs text-discord-400 mt-1">2-32 characters, letters, numbers, and - _ [ ] { } ^ ` |</p>
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <?php if ($invite): ?>
        <input class="input" id="email" type="email" name="email" required value="<?= h($invite['email']) ?>" readonly>
        <p class="text-xs text-discord-400 mt-1">Locked to the address this invitation was sent to.</p>
        <?php else: ?>
        <input class="input" id="email" type="email" name="email" required value="<?= h($old['email'] ?? '') ?>">
        <?php endif; ?>
      </div>
      <div>
        <label class="label" for="password">Password</label>
        <input class="input" id="password" type="password" name="password" required minlength="8">
        <p class="text-xs text-discord-400 mt-1">At least 8 characters</p>
      </div>
      <label class="flex items-start gap-2 text-sm text-discord-300 cursor-pointer">
        <input type="checkbox" name="age18" value="1" required class="w-4 h-4 mt-0.5 accent-blurple">
        <span>I certify that I am at least 18 years of age. This service is not intended for minors.</span>
      </label>
      <label class="flex items-start gap-2 text-sm text-discord-300 cursor-pointer">
        <input type="checkbox" required class="w-4 h-4 mt-0.5 accent-blurple">
        <span>I have read and agree to the <a class="text-blurple hover:underline" href="/terms" target="_blank">Terms of Service</a> and <a class="text-blurple hover:underline" href="/privacy" target="_blank">Privacy Policy</a>.</span>
      </label>
      <?php if ($requires_approval): ?>
      <div class="rounded-md bg-amber-500/10 border border-amber-500/30 text-amber-300 px-3 py-2 text-xs">
        New registrations require admin approval before you can chat. You can still log in and browse channels.
      </div>
      <?php endif; ?>
      <button class="btn-primary w-full justify-center py-2.5">Create account</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      Already registered?
      <a class="text-blurple hover:underline" href="/login?next=<?= h(rawurlencode($next)) ?>">Log in</a>
    </p>
    <div class="mt-4 pt-4 border-t border-discord-700 text-xs text-discord-500 text-center">
      <a class="hover:underline" href="/terms">Terms of Service</a> · <a class="hover:underline" href="/privacy">Privacy Policy</a>
    </div>
  </div>
</div>

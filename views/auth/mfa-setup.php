<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */

 $title = 'Set up two-factor authentication'; ?>
<div class="max-w-md mx-auto">
  <div class="card auth-card p-8">
    <?php if (site_logo()): ?>
    <img src="<?= h(site_logo()) ?>" alt="" class="w-full h-auto object-contain mb-8">
    <?php endif; ?>
    <div class="mb-6">
      <h1 class="text-xl font-bold text-white">Set up two-factor authentication</h1>
      <p class="text-sm text-discord-400 mt-1">Your account class requires MFA. Scan the code with your authenticator app (Aegis, Google Authenticator, 1Password, …), then enter the 6-digit code it shows.</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="flex justify-center mb-4">
      <div id="mfa-qr" class="bg-white rounded-lg p-3 inline-block"></div>
    </div>
    <details class="mb-6">
      <summary class="text-xs text-discord-400 cursor-pointer hover:text-white">Can't scan? Enter the key manually</summary>
      <div class="mt-2 font-mono text-sm text-discord-200 bg-discord-850 border border-discord-700 rounded px-3 py-2 select-all break-all"><?= h($secret) ?></div>
    </details>

    <form method="post" action="/login/mfa/setup" class="space-y-4">
      <?= Csrf::field() ?>
      <div>
        <label class="label" for="code">Authentication code</label>
        <input class="input text-center text-lg tracking-[0.4em] font-mono" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code" placeholder="000000">
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Enable MFA and sign in</button>
    </form>
  </div>
</div>
<script src="/assets/js/qrcode.min.js"></script>
<script>
(function () {
  if (typeof qrcode === 'undefined') return;
  var qr = qrcode(0, 'M');
  qr.addData(<?= json_encode($uri, JSON_UNESCAPED_SLASHES) ?>);
  qr.make();
  document.getElementById('mfa-qr').innerHTML = qr.createImgTag(4, 0);
})();
</script>

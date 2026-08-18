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
?>

<div class="max-w-md mx-auto">
  <div class="card auth-card p-8">
    <?php if (site_logo()): ?>
    <img src="<?= h(site_logo()) ?>" alt="" class="w-full h-auto object-contain mb-8">
    <?php endif; ?>
    <div class="flex items-center gap-3 mb-8">
      <div>
        <h1 class="text-xl font-bold text-white">Choose a new password</h1>
        <p class="text-sm text-discord-400"><?= h(config_get('site_name', 'LVChat')) ?></p>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/reset-password/<?= h(rawurlencode($token)) ?>" class="space-y-4">
      <?= Csrf::field() ?>
      <div>
        <label class="label" for="password">New password</label>
        <input class="input" id="password" type="password" name="password" required autofocus autocomplete="new-password" minlength="8">
        <p class="text-xs text-discord-400 mt-1">At least 8 characters.</p>
      </div>
      <div>
        <label class="label" for="password_confirm">Confirm new password</label>
        <input class="input" id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password" minlength="8">
      </div>
      <button class="btn-primary w-full justify-center py-2.5">Reset password</button>
    </form>

    <p class="mt-6 text-sm text-discord-400 text-center">
      <a class="text-blurple hover:underline" href="/login">Back to login</a>
    </p>
  </div>
</div>

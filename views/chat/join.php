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

 $title = 'Join ' . $channel['name']; ?>
<div class="max-w-md mx-auto">
  <div class="card p-8 text-center">
    <?= logo_mark('mx-auto mb-4 w-14 h-14 rounded-2xl text-2xl') ?>
    <h1 class="text-xl font-bold text-white"><?= h($channel['name']) ?></h1>
    <p class="text-sm text-discord-400 mt-1 mb-6">This channel requires a key to join.</p>
    <?php if ($error): ?>
    <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 text-sm"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/c/<?= h(rawurlencode($channel['slug'])) ?>/join" class="space-y-4">
      <?= Csrf::field() ?>
      <label class="label" for="join-key">Channel key</label>
      <input class="input text-center" type="password" name="key" id="join-key" placeholder="Channel key" required autofocus>
      <button class="btn-primary w-full justify-center py-2.5">Join <?= h($channel['name']) ?></button>
    </form>
  </div>
</div>

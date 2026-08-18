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

 $title = 'Updates'; $active = 'updates';
$updaterOn = $enabled && $updaterUrl !== '';
if ($updaterOn) {
    $pageActions = '<form method="post" action="/admin/updates/check">' . Csrf::field() . '<button type="submit" class="btn-primary">Check for updates</button></form>';
}
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<?php if (!$updaterOn): ?>
<div class="card p-6 max-w-2xl">
  <div class="text-sm font-medium text-white mb-1">Update feed is not enabled</div>
  <p class="text-xs text-discord-400 mb-4">
    Point this server at an LVChat Update Server (or your own mirror) to resolve the latest
    versions of LVChat Web, Desktop and Messenger, verify updates by sha256, and let your
    community's download modal fall back to upstream links automatically.
  </p>
  <a href="/admin/settings" class="btn-primary text-sm">Enable in Settings → Updates</a>
</div>
<?php else: ?>
<div class="card p-5 mb-4 max-w-2xl">
  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-discord-400">
    <span>Feed: <a class="text-blurple-300 hover:underline" href="<?= h($updaterUrl) ?>/manifest.json" target="_blank" rel="noopener"><?= h($updaterUrl) ?></a></span>
    <span>Cache: <?= $cachedAt ? relative_time(gmdate('Y-m-d H:i:s', $cachedAt)) : 'never fetched' ?></span>
  </div>
</div>

<div class="grid gap-4">
  <?php foreach ($status as $app => $s):
    $name = match ($app) { 'web' => 'LVChat Web', 'desktop' => 'LVChat Desktop', 'messenger' => 'LVChat Messenger' };
    $desc = match ($app) {
        'web' => 'The PHP web app this server runs. Update = download the verified archive, replace the app folder, run <code>bash bin/deploy.sh</code>.',
        'desktop' => 'Electron client — the web app as a native desktop app.',
        'messenger' => 'Electron client — the IM-first messenger.',
    };
    $custom = $s['source'] === 'custom';
  ?>
  <div class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
      <div class="flex items-center gap-2">
        <h2 class="text-base font-bold text-white"><?= h($name) ?></h2>
        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $custom ? 'bg-amber-500/15 text-amber-300' : 'bg-discord-700 text-discord-300' ?>">
          <?= $custom ? 'custom links' : 'upstream' ?>
        </span>
      </div>
      <?php if ($s['update_available']): ?>
        <span class="text-xs px-2.5 py-1 rounded-full bg-green-500/15 text-green-300 font-semibold">Update available</span>
      <?php else: ?>
        <span class="text-xs px-2.5 py-1 rounded-full bg-discord-700 text-discord-300 font-semibold">Current</span>
      <?php endif; ?>
    </div>
    <p class="text-xs text-discord-400 mb-3 max-w-3xl"><?= $desc ?></p>
    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
      <div>
        <div class="text-xs text-discord-400">Installed</div>
        <div class="font-mono"><?= $s['installed'] !== '' ? 'v' . h($s['installed']) : '—' ?></div>
      </div>
      <div>
        <div class="text-xs text-discord-400">Latest (upstream)</div>
        <div class="font-mono"><?= $s['latest'] !== '' ? 'v' . h($s['latest']) : '—' ?></div>
      </div>
      <?php if ($s['sha256'] !== ''): ?>
      <div>
        <div class="text-xs text-discord-400">sha256</div>
        <div class="font-mono text-xs text-discord-300"><?= h($s['sha256']) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($s['notes'] !== ''): ?>
    <div class="mt-2 text-xs">
      <a href="<?= h($s['notes']) ?>" target="_blank" rel="noopener" class="text-blurple-300 hover:underline">Release notes →</a>
    </div>
    <?php endif; ?>
    <?php if ($s['update_available']): ?>
    <div class="mt-4 flex flex-wrap gap-2">
      <?php if ($app === 'web'): ?>
        <form method="post" action="/admin/updates/download-web">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-primary text-sm">Download verified update</button>
        </form>
      <?php else: ?>
        <form method="post" action="/admin/updates/pin">
          <?= Csrf::field() ?>
          <input type="hidden" name="app" value="<?= h($app) ?>">
          <button type="submit" class="btn-ghost text-sm">Pin upstream as my custom links</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($lastDownload): ?>
<div class="card p-5 mt-4">
  <h2 class="text-base font-bold text-white mb-1">Last verified web update</h2>
  <p class="text-xs text-discord-400 mb-3"><?= h($lastDownload['instructions'] ?? '') ?></p>
  <div class="flex flex-wrap items-center gap-3 text-sm">
    <code class="bg-discord-900 px-2 py-1 rounded font-mono text-xs"><?= h($lastDownload['path'] ?? '') ?></code>
    <span class="text-xs text-discord-400">
      <?= number_format((int) ($lastDownload['size'] ?? 0)) ?> bytes
      <?= !empty($lastDownload['verified']) ? '· sha256 verified' : '· checksum not published upstream' ?>
    </span>
    <?php if (!empty($lastDownload['path'])): ?>
    <a class="btn-ghost text-xs" href="/admin/updates/download?file=<?= h(rawurlencode(basename((string) $lastDownload['path']))) ?>">Save as…</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

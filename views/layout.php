<?php
// The effective mode (account/global) is emitted as data-theme so the inline
// head script applies it before paint; visitors without a saved preference fall
// back to their browser/OS preference.
$layoutTheme = ThemeService::effectiveForView($user ?? null);
$layoutThemeMode = $layoutTheme['mode'] === 'light' ? 'light' : '';
?>
<!DOCTYPE html>
<html lang="en" class="dark" data-theme="<?= $layoutThemeMode ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h($title ?? (config_get('site_name', 'LVChat') ?: 'LVChat')) ?></title>
  <script>
  (function () {
    try {
      var t = localStorage.getItem('lvc.theme') || document.documentElement.getAttribute('data-theme') || '';
      if (!t) t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
      if (t === 'light') document.documentElement.classList.add('light');
    } catch (e) {}
  })();
  </script>
  <?php require ROOT . '/views/partials/tailwind.php'; ?>
  <?php require ROOT . '/views/partials/theme.php'; ?>
  <?php require ROOT . '/views/partials/pwa.php'; ?>
</head>
<body class="bg-discord-900 text-discord-200 antialiased min-h-screen flex flex-col" data-csrf="<?= h(Csrf::token()) ?>">
  <header class="bg-discord-950/60 border-b border-discord-800">
    <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <?php if (site_logo()): ?>
        <img src="<?= h(site_logo()) ?>" alt="" class="w-8 h-8 rounded-xl object-contain">
        <?php else: ?>
        <div class="w-8 h-8 rounded-xl bg-blurple flex items-center justify-center font-bold text-white">#</div>
        <?php endif; ?>
        <div>
          <div class="font-semibold leading-tight"><?= h(config_get('site_name', 'LVChat')) ?></div>
          <div class="text-xs text-discord-400 leading-tight"><?= h(config_get('site_tagline', 'IRC-style web chat') ?: 'IRC-style web chat') ?></div>
        </div>
      </div>
      <?php if (isset($user) && $user): ?>
      <nav class="flex items-center gap-2">
        <a href="/app" class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm hidden md:inline-flex">Chat</a>
        <?php if ($user['role'] === 'admin'): ?>
        <a href="/admin" class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm text-amber-400 hidden md:inline-flex">Admin</a>
        <?php endif; ?>
        <a href="/u/<?= h(rawurlencode($user['username'])) ?>" class="px-3 py-1.5 rounded-md bg-discord-750 text-sm font-medium truncate max-w-[120px] sm:max-w-none"><?= h($user['username']) ?></a>
        <form method="post" action="/logout">
          <?= Csrf::field() ?>
          <button class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm text-red-400">Log out</button>
        </form>
      </nav>
      <?php else: ?>
      <button id="theme-toggle" class="px-2 py-1.5 rounded-md hover:bg-discord-750 text-sm" title="Switch theme">🌙</button>
      <?php endif; ?>
    </div>
  </header>
  <?php if ($f = flash()): ?>
  <div class="max-w-5xl mx-auto w-full px-4 pt-4">
    <div class="rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 px-4 py-2.5 text-sm"><?= h($f) ?></div>
  </div>
  <?php endif; ?>
  <main class="flex-1 w-full <?= !empty($fullWidth) ? 'max-w-none px-4 md:px-8 py-6' : 'max-w-5xl mx-auto px-4 py-8' ?>">
    <?= $content ?>
  </main>
  <?php if (!empty($donateFooter)): ?>
  <footer class="border-t border-discord-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
      <span class="text-discord-400">Enjoying <?= h(config_get('site_name', 'LVChat')) ?>?</span>
      <a href="https://buymeacoffee.com/georgethegeek" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-amber-300 hover:text-amber-200 hover:underline">
        ☕ <span>Buy me a coffee</span>
      </a>
      <a href="https://github.com/repairgenie/lvchat" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-discord-300 hover:text-white hover:underline">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"></path></svg>
        <span>GitHub</span>
      </a>
    </div>
  </footer>
  <?php endif; ?>
  <script>
  (function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    function icon() { btn.textContent = document.documentElement.classList.contains('light') ? '☀️' : '🌙'; }
    btn.addEventListener('click', function () {
      var light = document.documentElement.classList.toggle('light');
      var theme = light ? 'light' : 'dark';
      try { localStorage.setItem('lvc.theme', theme); } catch (e) {}
      icon();
      var csrf = document.body.dataset.csrf || '';
      if (!csrf) return;
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('ajax', '1');
      fd.append('theme', theme);
      fetch('/api/profile', { method: 'POST', body: fd }).catch(function () {});
    });
    icon();
  })();
  </script>
</body>
</html>

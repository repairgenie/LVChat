<!DOCTYPE html>
<html lang="en" class="dark" data-theme="<?= isset($user) && $user ? h((string) ($user['theme'] ?? '')) : '' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
          <div class="text-xs text-discord-400 leading-tight">IRC-style web chat</div>
        </div>
      </div>
      <?php if (isset($user) && $user): ?>
      <nav class="flex items-center gap-2">
        <button id="theme-toggle" class="px-2 py-1.5 rounded-md hover:bg-discord-750 text-sm" title="Switch theme">🌙</button>
        <a href="/browse" class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm">Browse channels</a>
        <a href="/app" class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm">Chat</a>
        <?php if ($user['role'] === 'admin'): ?>
        <a href="/admin" class="px-3 py-1.5 rounded-md hover:bg-discord-750 text-sm text-amber-400">Admin</a>
        <?php endif; ?>
        <a href="/u/<?= h(rawurlencode($user['username'])) ?>" class="px-3 py-1.5 rounded-md bg-discord-750 text-sm font-medium"><?= h($user['username']) ?></a>
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

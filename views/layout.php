<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? (config_get('site_name', 'LVChat') ?: 'LVChat')) ?></title>
  <?php require ROOT . '/views/partials/tailwind.php'; ?>
</head>
<body class="bg-discord-900 text-discord-200 antialiased min-h-screen flex flex-col">
  <header class="bg-discord-950/60 border-b border-discord-800">
    <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-xl bg-blurple flex items-center justify-center font-bold text-white">#</div>
        <div>
          <div class="font-semibold leading-tight"><?= h(config_get('site_name', 'LVChat')) ?></div>
          <div class="text-xs text-discord-400 leading-tight">IRC-style web chat</div>
        </div>
      </div>
      <?php if (isset($user) && $user): ?>
      <nav class="flex items-center gap-2">
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
</body>
</html>

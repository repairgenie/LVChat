<?php $title = 'Browse channels'; ?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h1 class="text-2xl font-bold text-white">Channel browser</h1>
    <p class="text-sm text-discord-400 mt-1">Public channels on <?= h(config_get('site_name', 'LVChat')) ?>. Private channels are hidden and joinable only via their share link.</p>
  </div>
  <form method="get" action="/browse" class="flex gap-2">
    <input class="input w-56" type="text" name="q" placeholder="Search channels…" value="<?= h($term) ?>">
    <button class="btn-primary">Search</button>
  </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($channels as $c): ?>
  <div class="card p-4 flex flex-col">
    <div class="flex items-center justify-between">
      <h3 class="font-bold text-white"><?= h($c['name']) ?></h3>
      <span class="text-xs text-discord-400 flex items-center gap-1">👥 <?= (int) $c['members'] ?></span>
    </div>
    <p class="text-sm text-discord-400 mt-1 flex-1"><?= h($c['description'] ?: $c['topic'] ?: '(no description)') ?></p>
    <div class="mt-4">
      <?php if (isset($joinedMap[$c['id']])): ?>
      <a href="/app?channel=<?= h(rawurlencode($c['slug'])) ?>" class="btn-ghost w-full justify-center">In channel — open</a>
      <?php else: ?>
      <a href="/c/<?= h(rawurlencode($c['slug'])) ?>" class="btn-primary w-full justify-center">Join</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php if (!$channels): ?>
<div class="text-center text-discord-500 py-16">No public channels found.</div>
<?php endif; ?>

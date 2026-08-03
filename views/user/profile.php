<?php $title = $user['username']; ?>
<!-- X button: return to the chat -->
<button type="button" onclick="if (history.length > 1) history.back(); else location='/app';" class="btn-ghost fixed top-4 right-4 z-40 !p-2" title="Back to chat" aria-label="Close profile">✕</button>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
  <div class="card p-6 text-center md:col-span-1">
    <div class="mx-auto w-20 h-20">
      <?php if (!empty($user['avatar'])): ?>
      <img src="<?= h(url((string) $user['avatar'])) ?>" alt="<?= h($user['username']) ?>" class="w-20 h-20 rounded-full object-cover">
      <?php else: ?>
      <div class="w-20 h-20 rounded-full bg-blurple flex items-center justify-center text-3xl font-bold text-white"><?= h(strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
      <?php endif; ?>
    </div>
    <?php if ($isSelf && !(int) ($user['guest'] ?? 0)): ?>
    <div class="mt-2 flex items-center justify-center gap-2 text-xs">
      <label class="btn-ghost !py-1 cursor-pointer">Change<input type="file" id="avatar-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"></label>
      <?php if (!empty($user['avatar'])): ?><button id="avatar-remove" class="btn-ghost !py-1 text-red-400">Remove</button><?php endif; ?>
    </div>
    <?php endif; ?>
    <h1 class="mt-3 text-xl font-bold text-white"><?= h($user['username']) ?></h1>
    <div class="mt-1 text-sm">
      <?php if ((int) ($user['guest'] ?? 0)): ?>
      <span class="text-green-400 font-medium">Guest</span>
      <?php elseif ($user['role'] === 'admin'): ?>
      <span class="text-amber-400 font-medium">IRC Operator</span>
      <?php elseif ($user['role'] === 'staff'): ?>
      <span class="text-blurple font-medium">Staff</span>
      <?php else: ?>
      <span class="text-discord-400 font-medium">Registered</span>
      <?php endif; ?>
      <span class="text-discord-400"> · <?= $isOnline ? 'online' : 'offline' ?></span>
    </div>
    <?php if ($user['away']): ?><div class="mt-2 text-sm text-amber-400">💤 <?= h($user['away']) ?></div><?php endif; ?>
    <?php if ($user['bot']): ?><div class="mt-2 text-sm text-blurple">Bot</div><?php endif; ?>
    <div class="mt-4 text-xs text-discord-400">Registered <?= date('M j, Y', strtotime($user['registered_at'] . ' UTC')) ?></div>

    <?php if (!$isSelf): ?>
    <a href="/app?dm=<?= h(rawurlencode($user['username'])) ?>" class="btn-primary w-full justify-center mt-5">Send message</a>
    <?php endif; ?>
  </div>

  <div class="md:col-span-2 space-y-6">
    <div class="card p-6">
      <h2 class="font-semibold text-white mb-4">Channels — <?= count($channels) ?></h2>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($channels as $c): ?>
        <a href="/app?channel=<?= h(rawurlencode($c['slug'])) ?>" class="px-3 py-1.5 rounded-md bg-discord-750 hover:bg-discord-700 text-sm"><?= h($c['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$channels): ?><span class="text-sm text-discord-500">Not in any channels.</span><?php endif; ?>
      </div>
    </div>

    <?php if ($isSelf && !(int) ($user['guest'] ?? 0)): ?>
    <div class="card p-6">
      <h2 class="font-semibold text-white mb-4">Account settings</h2>
      <form id="pw-form" class="space-y-4">
        <?= Csrf::field() ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <input class="input" type="password" name="current" placeholder="Current password" required>
          <input class="input" type="password" name="new" placeholder="New password (8+ chars)" required minlength="8">
          <button class="btn-primary justify-center">Change password</button>
        </div>
      </form>
      <form id="vhost-form" class="mt-5 pt-5 border-t border-discord-700 space-y-4">
        <?= Csrf::field() ?>
        <div class="flex flex-wrap gap-3 items-end">
          <div class="flex-1 min-w-40">
            <label class="label">Virtual host (vhost)</label>
            <input class="input" name="vhost" placeholder="user.chat.example" value="<?= h(str_replace('|hide', '', (string) $user['vhost'])) ?>">
          </div>
          <button class="btn-primary">Save vhost</button>
        </div>
      </form>
      <div id="profile-msg" class="mt-3 text-sm text-green-400 hidden">Saved.</div>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(() => {
  const csrf = document.querySelector('input[name=csrf]')?.value || '';
  const msg = document.getElementById('profile-msg');
  function post(url, fd, ok) {
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(r => r.json()).then(j => { if (j.error) { alert(j.error); return; } if (ok) ok(); });
  }
  const pw = document.getElementById('pw-form');
  if (pw) pw.addEventListener('submit', e => {
    e.preventDefault();
    const fd = new FormData(pw);
    post('/api/password', fd, () => { pw.reset(); alert('Password changed.'); });
  });
  const vh = document.getElementById('vhost-form');
  if (vh) vh.addEventListener('submit', e => {
    e.preventDefault();
    post('/api/profile', new FormData(vh), () => {
      msg.classList.remove('hidden');
      setTimeout(() => msg.classList.add('hidden'), 2000);
    });
  });
  const avFile = document.getElementById('avatar-file');
  if (avFile) avFile.addEventListener('change', () => {
    const f = avFile.files && avFile.files[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('avatar', f);
    post('/api/avatar', fd, () => location.reload());
  });
  const avRemove = document.getElementById('avatar-remove');
  if (avRemove) avRemove.addEventListener('click', () => post('/api/avatar/remove', new FormData(), () => location.reload()));
})();
</script>

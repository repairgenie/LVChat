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
    <?php
    $soundSelect = function (?int $selected, string $name) use ($sounds): string {
        $html = '<select class="input !py-1.5" name="' . h($name) . '">';
        $html .= '<option value="0"' . ($selected === null ? ' selected' : '') . '>Off (muted)</option>';
        foreach ($sounds as $s) {
            $html .= '<option value="' . (int) $s['id'] . '"' . ($selected !== null && (int) $s['id'] === $selected ? ' selected' : '') . '>' . h($s['name']) . '</option>';
        }
        return $html . '</select>';
    };
    ?>
    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">Notification sounds</h2>
      <p class="text-xs text-discord-400 mb-4">Sounds play when a DM arrives or a message lands in a channel you're not viewing. Pick an alert per context, or mute each entirely.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="label">Direct messages</label>
          <div class="flex gap-2">
            <?= $soundSelect($soundPrefs['dm_sound_id'], 'dm') ?>
            <button type="button" class="btn-ghost shrink-0" data-test-sound="dm">Play</button>
          </div>
        </div>
        <div>
          <label class="label">Channel messages</label>
          <div class="flex gap-2">
            <?= $soundSelect($soundPrefs['channel_sound_id'], 'channel') ?>
            <button type="button" class="btn-ghost shrink-0" data-test-sound="channel">Play</button>
          </div>
        </div>
      </div>
      <div id="sounds-msg" class="mt-3 text-sm text-green-400 hidden">Saved.</div>

      <div class="mt-6 pt-5 border-t border-discord-700">
        <div class="text-sm font-medium text-white mb-1">Per-user overrides</div>
        <p class="text-xs text-discord-400 mb-3">Give a specific person their own sound (or mute them) everywhere — DMs and channel messages.</p>
        <?php if ($soundOverrides): ?>
        <div class="space-y-2 mb-4">
          <?php foreach ($soundOverrides as $uid => $o): ?>
          <div class="flex items-center gap-2" data-override="<?= (int) $uid ?>">
            <span class="text-sm text-discord-200 w-40 truncate"><?= h($o['username']) ?></span>
            <?= $soundSelect($o['sound_id'], 'sound') ?>
            <button type="button" class="btn-ghost text-xs text-red-400 !py-1 shrink-0" data-remove-override="<?= (int) $uid ?>">Remove</button>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form id="override-form" class="flex flex-wrap items-end gap-2">
          <?= Csrf::field() ?>
          <div class="min-w-44 flex-1">
            <label class="label">User</label>
            <select name="target_user_id" class="input !py-1.5" required>
              <?php foreach ($allUsers as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= h($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="min-w-40">
            <label class="label">Sound</label>
            <?= $soundSelect(null, 'sound') ?>
          </div>
          <button class="btn-ghost">Add override</button>
        </form>
      </div>
    </div>

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

  const SOUND_URLS = <?= json_encode(array_combine(array_map('intval', array_column($sounds, 'id')), array_map(fn ($s) => url($s['file']), $sounds))) ?>;
  const sndMsg = document.getElementById('sounds-msg');
  function sndPost(url, fd, ok) {
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(r => r.json()).then(j => { if (j.error) { alert(j.error); return; } if (ok) ok(); });
  }
  function playSound(id) {
    const u = SOUND_URLS[id] || '';
    if (!u) return;
    try { new Audio(u).play().catch(() => {}); } catch (e) {}
  }
  document.querySelectorAll('[data-test-sound]').forEach(btn => {
    btn.addEventListener('click', () => {
      const sel = document.querySelector('select[name="' + btn.dataset.testSound + '"]');
      playSound(sel ? parseInt(sel.value, 10) || 0 : 0);
    });
  });
  ['dm', 'channel'].forEach(name => {
    const sel = document.querySelector('select[name="' + name + '"]');
    if (!sel) return;
    sel.addEventListener('change', () => {
      const fd = new FormData();
      fd.append('dm_sound', document.querySelector('select[name="dm"]').value);
      fd.append('channel_sound', document.querySelector('select[name="channel"]').value);
      sndPost('/api/sound/prefs', fd, () => {
        sndMsg.classList.remove('hidden');
        setTimeout(() => sndMsg.classList.add('hidden'), 2000);
      });
    });
  });
  document.querySelectorAll('[data-override] select[name="sound"]').forEach(sel => {
    sel.addEventListener('change', () => {
      const row = sel.closest('[data-override]');
      const fd = new FormData();
      fd.append('target_user_id', row.dataset.override);
      fd.append('sound', sel.value);
      sndPost('/api/sound/override', fd);
    });
  });
  document.querySelectorAll('[data-remove-override]').forEach(btn => {
    btn.addEventListener('click', () => {
      const fd = new FormData();
      fd.append('target_user_id', btn.dataset.removeOverride);
      sndPost('/api/sound/override/remove', fd, () => {
        const row = btn.closest('[data-override]');
        if (row) row.remove();
      });
    });
  });
  const ovForm = document.getElementById('override-form');
  if (ovForm) ovForm.addEventListener('submit', e => {
    e.preventDefault();
    sndPost('/api/sound/override', new FormData(ovForm), () => location.reload());
  });
})();
</script>

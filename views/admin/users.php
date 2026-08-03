<?php $title = 'Users'; $active = 'users'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Users</h1>
  <form method="get" action="/admin/users" class="flex gap-2">
    <input class="input w-56" name="q" placeholder="Search username/email…" value="<?= h($term) ?>">
    <button class="btn-primary">Search</button>
  </form>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="card p-5 mb-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold text-white">Create a user manually</h2>
    <span class="text-xs text-discord-400">No email needed — the password is shown once</span>
  </div>
  <form method="post" action="/admin/action" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/users">
    <div>
      <label class="label">Username</label>
      <input class="input" name="username" required pattern="[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}" title="2-32 chars, letters, numbers, and - _ [ ] { } ^ ` |">
    </div>
    <div>
      <label class="label">Email</label>
      <input class="input" type="email" name="email" required>
    </div>
    <div>
      <label class="label">Role</label>
      <select name="role" class="input !py-1.5">
        <option value="user" selected>user</option>
        <option value="staff">staff</option>
        <option value="admin">admin</option>
      </select>
    </div>
    <div class="flex flex-col gap-1">
      <label class="flex items-center gap-2 text-sm text-discord-300 cursor-pointer">
        <input type="checkbox" name="email_welcome" value="1" class="w-4 h-4 accent-blurple">
        Email credentials
      </label>
      <button name="action" value="user_create" class="btn-primary justify-center">Create user</button>
    </div>
  </form>
  <p class="text-xs text-discord-400 mt-2">A random 16-character password is generated and shown once on the next page. Check "Email credentials" to also send it to the address above (requires SMTP under Settings).</p>
</div>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
        <th class="px-4 py-2">User</th>
        <th class="px-4 py-2">Email</th>
        <th class="px-4 py-2">Role</th>
        <th class="px-4 py-2">Channels</th>
        <th class="px-4 py-2">Registered</th>
        <th class="px-4 py-2">Last seen</th>
        <th class="px-4 py-2">IP</th>
        <th class="px-4 py-2 text-right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2">
          <a href="/u/<?= h(rawurlencode($u['username'])) ?>" class="font-medium text-white hover:underline"><?= h($u['username']) ?></a>
          <?php if ($u['banned']): ?><span class="ml-1 text-[10px] bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded">BANNED</span><?php endif; ?>
        </td>
        <td class="px-4 py-2 text-discord-300"><?= h($u['email']) ?></td>
        <td class="px-4 py-2"><?= $u['role'] === 'admin' ? '<span class="text-amber-400">admin</span>' : ($u['role'] === 'staff' ? '<span class="text-blurple">staff</span>' : 'user') ?></td>
        <td class="px-4 py-2">
          <form method="post" action="/admin/action" class="flex items-center gap-1">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/users">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <select name="role_id" class="input !py-0.5 text-xs" onchange="this.form.submit()">
              <option value="0">— none —</option>
              <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $u['role_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="user_set_role">
          </form>
        </td>
        <td class="px-4 py-2"><?= (int) $u['channel_count'] ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(date('M j Y', strtotime($u['registered_at'] . ' UTC'))) ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(relative_time($u['last_seen'])) ?></td>
        <td class="px-4 py-2 font-mono text-[11px] text-discord-300"><?= h($u['last_ip'] ?? '—') ?></td>
        <td class="px-4 py-2">
          <form method="post" action="/admin/action" class="flex gap-1 justify-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/admin/users">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
              <?php if ($u['banned']): ?>
              <button name="action" value="user_unban" class="btn-ghost text-xs !py-1">Unban</button>
              <?php else: ?>
              <button name="action" value="user_ban" class="btn-ghost text-xs !py-1 text-red-400" onclick="return confirm('Ban this user?')">Ban</button>
              <?php endif; ?>
              <?php if (!empty($u['last_ip'])): ?>
              <button name="action" value="user_zline_ip" class="btn-ghost text-xs !py-1 text-orange-400" onclick="return confirm('Zline (ban) this IP?')">Zline IP</button>
              <?php endif; ?>
              <?php if ($u['role'] === 'admin'): ?>
              <button name="action" value="user_deadmin" class="btn-ghost text-xs !py-1">Demote</button>
              <?php elseif ($u['role'] === 'staff'): ?>
              <button name="action" value="user_destaff" class="btn-ghost text-xs !py-1">Remove staff</button>
              <button name="action" value="user_admin" class="btn-ghost text-xs !py-1 text-amber-400">Make admin</button>
              <?php else: ?>
              <button name="action" value="user_staff" class="btn-ghost text-xs !py-1 text-blurple">Make staff</button>
              <button name="action" value="user_admin" class="btn-ghost text-xs !py-1 text-amber-400">Make admin</button>
              <?php endif; ?>
              <button name="action" value="user_reset" class="btn-ghost text-xs !py-1" onclick="return confirm('Reset password? New password shown on next page.')">Reset pw</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
$title = 'Roles'; $active = 'roles';
$permLabels = [
    'oper' => 'Operator commands (kline/gline/zline/shun, kill, global, spamfilter, badword…) and viewing user IPs',
    'manage_users' => 'Promote / demote / ban users',
    'manage_channels' => 'Force topics, drop channels, change visibility',
    'manage_bans' => 'Add / remove global bans',
    'manage_badwords' => 'Manage the bad-word filter',
    'manage_roles' => 'Create / edit roles',
];
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Roles &amp; permissions</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ New role</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/roles">
      <input type="hidden" name="id" value="0">
      <div>
        <label class="label">Name</label>
        <input class="input" name="name" placeholder="e.g. Moderator" required>
      </div>
      <div>
        <label class="label">Colour</label>
        <input class="input" type="color" name="color" value="#5865f2">
      </div>
      <div class="space-y-1">
        <?php foreach ($permLabels as $key => $label): ?>
        <label class="flex items-start gap-2 text-xs text-discord-300">
          <input type="checkbox" name="perms[]" value="<?= h($key) ?>" class="mt-0.5 accent-blurple"> <?= h($label) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <button name="action" value="role_save" class="btn-primary w-full justify-center">Create role</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<div class="text-xs text-discord-500 mb-3">Admins always have every permission. Custom roles grant permissions to otherwise regular users (an IRC Operator is anyone with the <b>oper</b> permission).</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php if (!$roles): ?><div class="text-sm text-discord-500">No custom roles yet.</div><?php endif; ?>
  <?php foreach ($roles as $r): $rperms = json_decode((string) $r['perms'], true) ?: []; ?>
  <details class="card p-4" <?= isset($_GET['edit']) && (int) $_GET['edit'] === (int) $r['id'] ? 'open' : '' ?>>
    <summary class="cursor-pointer flex items-center gap-2 text-sm font-semibold text-white">
      <span class="w-3 h-3 rounded-full inline-block" style="background:<?= h($r['color']) ?>"></span>
      <?= h($r['name']) ?>
      <span class="text-xs font-normal text-discord-400">(<?= (int) $r['members'] ?> member<?= $r['members'] == 1 ? '' : 's' ?>)</span>
    </summary>
    <form method="post" action="/admin/action" class="mt-3 space-y-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/roles">
      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Name</label>
          <input class="input" name="name" value="<?= h($r['name']) ?>" required>
        </div>
        <div>
          <label class="label">Colour</label>
          <input class="input" type="color" name="color" value="<?= h($r['color']) ?>">
        </div>
      </div>
      <div class="space-y-1">
        <?php foreach ($permLabels as $key => $label): ?>
        <label class="flex items-start gap-2 text-xs text-discord-300">
          <input type="checkbox" name="perms[]" value="<?= h($key) ?>" <?= in_array($key, $rperms, true) ? 'checked' : '' ?> class="mt-0.5 accent-blurple"> <?= h($label) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="flex gap-2">
        <button name="action" value="role_save" class="btn-primary text-xs !py-1.5">Save</button>
        <button name="action" value="role_del" class="btn-ghost text-xs !py-1.5 text-red-400" onclick="return confirm('Delete this role? Members lose its permissions.')">Delete</button>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

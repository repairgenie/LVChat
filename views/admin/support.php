<?php $title = 'Support'; $active = 'support'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Support tickets</h1>
  <div class="flex gap-1">
    <?php foreach (['', 'open', 'answered', 'closed'] as $s): ?>
    <a href="/admin/support<?= $s !== '' ? '?status=' . $s : '' ?>" class="px-2.5 py-1 rounded-md text-sm <?= ($status === $s) ? 'bg-blurple text-white' : 'bg-discord-750 text-discord-300 hover:text-white' ?>"><?= $s === '' ? 'All' : h(ucfirst($s)) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<?php if (!$tickets): ?>
<div class="card p-6 text-sm text-discord-400">No tickets<?= $status !== '' ? ' with status ' . h($status) : '' ?>.</div>
<?php endif; ?>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
        <th class="px-4 py-2">Ticket</th>
        <th class="px-4 py-2">Subject</th>
        <th class="px-4 py-2">User</th>
        <th class="px-4 py-2">Status</th>
        <th class="px-4 py-2">Replies</th>
        <th class="px-4 py-2">Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2"><a href="/admin/support/<?= (int) $t['id'] ?>" class="text-blurple hover:underline">#<?= (int) $t['id'] ?></a></td>
        <td class="px-4 py-2 text-white"><?= h($t['subject']) ?></td>
        <td class="px-4 py-2"><a href="/admin/users/<?= (int) $t['user_id'] ?>" class="hover:underline"><?= h($t['username']) ?></a></td>
        <td class="px-4 py-2">
          <span class="px-1.5 py-0.5 rounded text-[11px] <?= $t['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($t['status'] === 'answered' ? 'bg-amber-500/20 text-amber-300' : 'bg-discord-700 text-discord-400') ?>"><?= h($t['status']) ?></span>
        </td>
        <td class="px-4 py-2 text-discord-300"><?= (int) $t['replies'] ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(relative_time($t['updated_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

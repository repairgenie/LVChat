<?php $title = 'Blocked URLs'; $active = 'urls'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Blocked URLs</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add banned domain</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/urls">
      <div>
        <label class="label">Domain</label>
        <input class="input" name="domain" placeholder="e.g. example.com or *.example.com" required>
        <p class="text-[11px] text-discord-500 mt-1">A channel URL whose host equals this domain (or is a subdomain of it) is rejected when set and hidden when rendered.</p>
      </div>
      <div>
        <label class="label">Reason</label>
        <input class="input" name="reason" placeholder="Reason…">
      </div>
      <button name="action" value="banned_url_add" class="btn-primary w-full justify-center">Add domain</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<h2 class="font-semibold text-white mb-3">Banned channel URLs / domains</h2>
<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Domain</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">Added by</th><th class="px-4 py-2">Added</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$banned): ?><tr><td class="px-4 py-3 text-discord-500" colspan="5">No banned domains. Channel owners can embed any http(s) page.</td></tr><?php endif; ?>
      <?php foreach ($banned as $b): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 font-mono text-red-400"><?= h($b['domain']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h($b['set_by_name'] ?? 'system') ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h(date('M j Y', strtotime($b['created_at'] . ' UTC'))) ?></td>
        <td class="px-4 py-2 text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/urls"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="banned_url_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

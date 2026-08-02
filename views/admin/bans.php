<?php $title = 'Bans'; $active = 'bans'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Bans</h1>
  <details class="relative">
    <summary class="btn-primary cursor-pointer">＋ Add global ban</summary>
    <form method="post" action="/admin/action" class="absolute right-0 mt-2 w-80 card p-4 space-y-3 z-20">
      <?= Csrf::field() ?>
      <input type="hidden" name="back" value="/admin/bans">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Type</label>
          <select name="kind" class="input">
            <?php foreach (['kline', 'gline', 'zline', 'shun', 'qline'] as $k): ?>
            <option value="<?= $k ?>"><?= strtoupper($k) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Duration</label>
          <input class="input" name="duration" placeholder="e.g. 1d, 30m">
        </div>
      </div>
      <div>
        <label class="label">Mask (nick, IP, or IP/CIDR)</label>
        <input class="input" name="mask" placeholder="nick · 1.2.3.4 · 10.0.0.0/24" required>
      </div>
      <div>
        <label class="label">Reason</label>
        <input class="input" name="reason" placeholder="Reason…">
      </div>
      <button name="action" value="ban_add" class="btn-primary w-full justify-center">Add ban</button>
    </form>
  </details>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<h2 class="font-semibold text-white mb-3">Global bans (kline / gline / zline / shun / qline)</h2>
<div class="card overflow-x-auto mb-8">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Kind</th><th class="px-4 py-2">Mask</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">Set by</th><th class="px-4 py-2">Expires</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$global): ?><tr><td class="px-4 py-3 text-discord-500" colspan="6">No global bans.</td></tr><?php endif; ?>
      <?php foreach ($global as $b): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2"><span class="text-red-400 font-mono"><?= h(strtoupper($b['kind'])) ?></span></td>
        <td class="px-4 py-2 font-mono text-discord-200"><?= h($b['mask']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="px-4 py-2 text-discord-400"><?= h($b['set_by_name'] ?? 'system') ?></td>
        <td class="px-4 py-2 text-discord-400"><?= $b['expires_at'] ? h(date('M j H:i', strtotime($b['expires_at'] . ' UTC'))) : 'permanent' ?></td>
        <td class="px-4 py-2 text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/bans"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="ban_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 class="font-semibold text-white mb-3">Channel bans</h2>
<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Channel</th><th class="px-4 py-2">Kind</th><th class="px-4 py-2">Mask</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$channelBans): ?><tr><td class="px-4 py-3 text-discord-500" colspan="5">No channel bans.</td></tr><?php endif; ?>
      <?php foreach ($channelBans as $b): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2 text-discord-200"><?= h($b['channel_name'] ?? '#?') ?></td>
        <td class="px-4 py-2 text-red-400 font-mono"><?= h(strtoupper($b['kind'])) ?></td>
        <td class="px-4 py-2 font-mono text-discord-200"><?= h($b['mask']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($b['reason'] ?: '—') ?></td>
        <td class="px-4 py-2 text-right">
          <form method="post" action="/admin/action">
            <?= Csrf::field() ?><input type="hidden" name="back" value="/admin/bans"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button name="action" value="ban_del" class="btn-ghost text-xs !py-1 text-red-400">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

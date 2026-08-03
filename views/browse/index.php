<?php $title = 'Browse channels'; ?>
<?php
function channel_row(array $c, array $joinedMap): string {
    $joined = isset($joinedMap[$c['id']]);
    $vis = $c['visibility'] !== 'public' ? ' <span class="text-[10px]" title="Restricted">🔒</span>' : '';
    $action = $joined
        ? '<a href="/app?channel=' . h(rawurlencode($c['slug'])) . '" class="btn-ghost text-xs !py-1">Open</a>'
        : '<a href="/c/' . h(rawurlencode($c['slug'])) . '" class="btn-primary text-xs !py-1">Join</a>';
    return '<tr class="border-b border-discord-800 hover:bg-discord-750/40"
        data-name="' . h(strtolower($c['name'])) . '"
        data-topic="' . h(strtolower(($c['topic'] ?: $c['description']) ?: '')) . '"
        data-members="' . (int) $c['members'] . '"
        data-joined="' . ($joined ? '1' : '0') . '">
        <td class="px-4 py-2 font-medium text-white whitespace-nowrap">' . h($c['name']) . $vis . '</td>
        <td class="px-4 py-2 text-discord-300 max-w-md truncate" title="' . h($c['topic'] ?: $c['description'] ?: '') . '">' . h(mb_strimwidth($c['topic'] ?: $c['description'] ?: '(no topic)', 0, 128, '…')) . '</td>
        <td class="px-4 py-2 text-right text-discord-300">' . (int) $c['members'] . '</td>
        <td class="px-4 py-2 text-right">' . $action . '</td>
      </tr>';
}
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h1 class="text-2xl font-bold text-white">Channel browser</h1>
    <p class="text-sm text-discord-400 mt-1">Public channels on <?= h(config_get('site_name', 'LVChat')) ?>. Private channels are hidden and joinable only via their share link.</p>
  </div>
</div>

<div class="flex flex-wrap gap-3 mb-4">
  <div class="card px-4 py-3 flex items-center gap-3">
    <span class="w-2 h-2 rounded-full bg-green-500"></span>
    <div>
      <div class="text-lg font-bold text-white leading-tight"><?= (int) $online ?></div>
      <div class="text-xs text-discord-400">Online now</div>
    </div>
  </div>
  <div class="card px-4 py-3 flex items-center gap-3">
    <span class="w-2 h-2 rounded-full bg-amber-400"></span>
    <div>
      <div class="text-lg font-bold text-white leading-tight"><?= (int) $peak ?></div>
      <div class="text-xs text-discord-400">Peak users ever</div>
    </div>
  </div>
</div>

<?php if ($myChannels): ?>
<div class="card overflow-hidden mb-6">
  <div class="p-3 border-b border-discord-700 flex items-center justify-between">
    <span class="font-bold text-white">My Channels</span>
    <span class="text-xs text-discord-400"><?= count($myChannels) ?></span>
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700 select-none">
        <th class="px-4 py-2.5 whitespace-nowrap">Channel</th>
        <th class="px-4 py-2.5">Topic</th>
        <th class="px-4 py-2.5 text-right whitespace-nowrap">Users</th>
        <th class="px-4 py-2.5 text-right">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($myChannels as $c): ?><?= channel_row($c, $joinedMap) ?><?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-3 border-b border-discord-700 flex flex-wrap gap-2 items-center">
    <input id="ch-search" class="input w-64 !py-1.5" placeholder="Search by name or topic…" autocomplete="off">
    <select id="ch-filter" class="input w-44 !py-1.5">
      <option value="all">All channels</option>
      <option value="open">Not joined</option>
      <option value="joined">Joined</option>
    </select>
    <span id="ch-count" class="text-xs text-discord-500 ml-auto"></span>
  </div>

  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700 select-none">
        <th class="px-4 py-2.5 cursor-pointer hover:text-white whitespace-nowrap" data-sort="name">Channel <span class="sort-arrow">⬍</span></th>
        <th class="px-4 py-2.5 cursor-pointer hover:text-white" data-sort="topic">Topic <span class="sort-arrow">⬍</span></th>
        <th class="px-4 py-2.5 cursor-pointer hover:text-white text-right whitespace-nowrap" data-sort="members">Users <span class="sort-arrow">⬍</span></th>
        <th class="px-4 py-2.5 text-right">Join</th>
      </tr>
    </thead>
    <tbody id="ch-tbody">
      <?php foreach ($channels as $c): ?><?= channel_row($c, $joinedMap) ?><?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$channels): ?>
  <div class="p-8 text-center text-discord-500 text-sm">No public channels found.</div>
  <?php endif; ?>
</div>

<script>
(() => {
  const tbody = document.getElementById('ch-tbody');
  if (!tbody) return;
  const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
  const search = document.getElementById('ch-search');
  const filter = document.getElementById('ch-filter');
  const count = document.getElementById('ch-count');
  let sortKey = 'name';
  let sortDir = 1;

  function apply() {
    const q = (search.value || '').trim().toLowerCase();
    const f = filter.value;
    let visible = rows.filter((r) => {
      if (f === 'joined' && r.dataset.joined !== '1') return false;
      if (f === 'open' && r.dataset.joined === '1') return false;
      if (q && r.dataset.name.indexOf(q) === -1 && r.dataset.topic.indexOf(q) === -1) return false;
      return true;
    });
    visible.sort((a, b) => {
      const va = a.dataset[sortKey];
      const vb = b.dataset[sortKey];
      let cmp;
      if (sortKey === 'members') cmp = (parseInt(va, 10) || 0) - (parseInt(vb, 10) || 0);
      else cmp = String(va).localeCompare(String(vb));
      return cmp * sortDir;
    });
    const shown = new Set(visible);
    rows.forEach((r) => {
      if (!shown.has(r)) r.classList.add('hidden');
      else { r.classList.remove('hidden'); tbody.appendChild(r); }
    });
    count.textContent = visible.length + ' channel' + (visible.length === 1 ? '' : 's');
    tbody.closest('table').querySelectorAll('th[data-sort]').forEach((th) => {
      const arrow = th.querySelector('.sort-arrow');
      if (th.dataset.sort === sortKey) arrow.textContent = sortDir === 1 ? '▲' : '▼';
      else arrow.textContent = '⬍';
    });
  }

  search.addEventListener('input', apply);
  filter.addEventListener('change', apply);
  tbody.closest('table').querySelectorAll('th[data-sort]').forEach((th) => {
    th.addEventListener('click', () => {
      if (sortKey === th.dataset.sort) sortDir *= -1;
      else { sortKey = th.dataset.sort; sortDir = 1; }
      apply();
    });
  });
  apply();
})();
</script>

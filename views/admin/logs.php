<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */


$title = 'Chat logs';
$active = 'logs';
$fullWidth = true;
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Chat logs</h1>
  <form method="get" action="/admin/logs" class="flex gap-2">
    <select name="channel" class="input w-56 !py-1.5 text-xs" onchange="this.form.submit()">
      <option value="">All channels</option>
      <?php foreach ($channels as $c): ?>
      <option value="<?= h($c['channel_name']) ?>" <?= $channel === $c['channel_name'] ? 'selected' : '' ?>><?= h($c['channel_name']) ?> (<?= (int) $c['entries'] ?> entries)</option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= h($q ?? '') ?>" placeholder="Search log content…" class="input w-64 !py-1.5 text-xs" autocomplete="off">
    <button type="submit" class="btn-primary text-xs !py-1">Search</button>
  </form>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<div class="text-xs text-discord-500 mb-3">One entry per channel per day. Click a day to view its full log (also available for deleted / unregistered channels and private messages).</div>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs text-discord-400 border-b border-discord-700">
      <th class="px-4 py-2">Channel</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Entries</th><th class="px-4 py-2 text-right"></th>
    </tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td class="px-4 py-3 text-discord-500" colspan="4">No log entries yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $isToday = $r['day'] === gmdate('Y-m-d'); ?>
      <tr class="border-b border-discord-800 cursor-pointer hover:bg-discord-750/40" data-channel="<?= h($r['channel_name']) ?>" data-date="<?= h($r['day']) ?>">
        <td class="px-4 py-2 text-discord-200"><?= h($r['channel_name']) ?></td>
        <td class="px-4 py-2 text-discord-300"><?= h($r['day']) ?><?= $isToday ? ' <span class="text-[10px] text-green-400">today</span>' : '' ?></td>
        <td class="px-4 py-2 text-discord-400"><?= (int) $r['entries'] ?></td>
        <td class="px-4 py-2 text-right text-blurple text-xs">View log →</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Day log modal -->
<div id="log-modal" class="hidden fixed inset-0 z-[200] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/70" data-close></div>
  <div class="relative card w-[min(96vw,1400px)] h-[85vh] flex flex-col overflow-hidden">
    <div class="px-4 py-2.5 border-b border-discord-700 flex items-center justify-between gap-2 shrink-0">
      <span id="log-modal-title" class="font-semibold text-white text-sm truncate"></span>
      <div class="flex items-center gap-2 shrink-0">
        <button id="log-refresh" class="btn-ghost text-xs !py-1">⟳ Refresh</button>
        <a id="log-export" class="btn-primary text-xs !py-1" href="#" download>Export</a>
        <button data-close class="btn-ghost text-xs !py-1"><?= icon('x', 'w-3.5 h-3.5') ?></button>
      </div>
    </div>
    <textarea id="log-content" readonly spellcheck="false"
      class="flex-1 min-h-0 w-full bg-discord-900 text-[12px] leading-relaxed font-mono text-discord-200 p-3 outline-none resize-none scrollbar-thin"></textarea>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('log-modal');
  const title = document.getElementById('log-modal-title');
  const content = document.getElementById('log-content');
  const exportLink = document.getElementById('log-export');
  let current = null;

  function openDay(channel, date) {
    current = { channel, date };
    title.textContent = '#' + channel + ' · ' + date;
    exportLink.href = '/admin/logs/export?channel=' + encodeURIComponent(channel) + '&date=' + date;
    modal.classList.remove('hidden');
    fetchLog();
  }
  function fetchLog() {
    if (!current) return;
    content.value = 'Loading…';
    fetch('/admin/logs/day?channel=' + encodeURIComponent(current.channel) + '&date=' + current.date)
      .then((r) => (r.ok ? r.text() : Promise.reject('HTTP ' + r.status)))
      .then((t) => { content.value = t; content.scrollTop = 0; })
      .catch((e) => { content.value = 'Failed to load log: ' + e; });
  }
  function closeDay() {
    modal.classList.add('hidden');
    current = null;
  }

  document.querySelectorAll('tr[data-channel]').forEach((tr) => {
    tr.addEventListener('click', () => openDay(tr.dataset.channel, tr.dataset.date));
  });
  document.getElementById('log-refresh').addEventListener('click', fetchLog);
  modal.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', closeDay));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDay(); });
})();
</script>

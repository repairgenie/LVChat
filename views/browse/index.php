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

 $title = 'Browse channels'; ?>
<?php $donateFooter = true; ?>
<?php
function channel_card(array $c, array $joinedMap): string {
    $joined = isset($joinedMap[$c['id']]);
    $vis = $c['visibility'] !== 'public' ? '<span class="ml-1.5 text-[10px] px-1.5 py-0.5 rounded bg-discord-700/80 text-discord-400 border border-discord-600/50" title="Restricted">🔒</span>' : '';
    $topicText = mb_strimwidth($c['topic'] ?: $c['description'] ?: '', 0, 120, '…');
    $topic = $topicText ? chat_markup_plain($topicText) : '<span class="text-discord-500 italic">No topic set</span>';
    $action = $joined
        ? '<a href="/app?channel=' . h(rawurlencode($c['slug'])) . '" class="px-5 py-2 rounded-lg text-sm font-semibold bg-discord-700 hover:bg-discord-600 text-white transition-all border border-discord-600 hover:border-discord-500">Open</a>'
        : '<a href="/c/' . h(rawurlencode($c['slug'])) . '" class="px-5 py-2 rounded-lg text-sm font-semibold bg-blurple hover:bg-blurple-dark text-white transition-all shadow-md shadow-blurple/20 hover:shadow-blurple/30">Join</a>';
    $hue = abs(crc32($c['name'])) % 360;
    $letter = strtoupper(mb_substr($c['name'], 0, 1));
    $avatar = '<div class="w-10 h-10 rounded-lg shrink-0 flex items-center justify-center text-base font-bold text-white shadow-inner" style="background:linear-gradient(135deg,hsl(' . $hue . ',55%,45%),hsl(' . (($hue + 40) % 360) . ',50%,35%))">' . $letter . '</div>';
    return '<div class="browse-row-premium flex items-center gap-4 p-4 rounded-xl bg-discord-800/80 border border-discord-700/80"
        data-name="' . h(strtolower($c['name'])) . '"
        data-topic="' . h(strtolower(($c['topic'] ?: $c['description']) ?: '')) . '"
        data-members="' . (int) $c['members'] . '"
        data-joined="' . ($joined ? '1' : '0') . '">
        ' . $avatar . '
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5 mb-0.5">
            <span class="font-bold text-[15px] text-white">' . h($c['name']) . '</span>' . $vis . '
          </div>
          <div class="text-[13px] text-discord-300 line-clamp-2 leading-relaxed">' . $topic . '</div>
        </div>
        <div class="flex items-center gap-4 shrink-0">
          <div class="text-right">
            <div class="flex items-center justify-end gap-1.5 text-xs">
              <span class="w-1.5 h-1.5 rounded-full bg-green-500 pulse-dot"></span>
              <span class="text-discord-200 font-semibold">' . (int) $c['members'] . '</span>
            </div>
            <div class="text-[10px] text-discord-500 mt-0.5">' . (int) $c['members'] . ' member' . ($c['members'] == 1 ? '' : 's') . '</div>
          </div>
          ' . $action . '
        </div>
      </div>';
}
?>

<div class="max-w-5xl mx-auto">
  <div class="mb-8">
    <div class="browse-gradient-accent rounded-full mb-6"></div>
    <h1 class="text-3xl font-extrabold text-white tracking-tight">Channel Browser</h1>
    <p class="text-sm text-discord-400 mt-2">Discover and join public channels on <?= h(config_get('site_name', 'LVChat')) ?>. Private channels are hidden and joinable only via their share link.</p>
  </div>

  <div class="flex flex-wrap gap-3 mb-6">
    <div class="browse-stat-card browse-stat-green rounded-lg px-4 py-3 flex items-center gap-3 min-w-[130px]">
      <span class="w-2.5 h-2.5 rounded-full bg-green-500 pulse-dot shadow-lg shadow-green-500/50 shrink-0"></span>
      <div>
        <div class="text-xl font-bold text-white leading-tight"><?= (int) $online ?></div>
        <div class="text-[11px] text-discord-400 font-medium uppercase tracking-wide">Online</div>
      </div>
    </div>
    <div class="browse-stat-card browse-stat-amber rounded-lg px-4 py-3 flex items-center gap-3 min-w-[130px]">
      <span class="w-2.5 h-2.5 rounded-full bg-amber-400 shadow-lg shadow-amber-400/50 shrink-0"></span>
      <div>
        <div class="text-xl font-bold text-white leading-tight"><?= (int) $peak ?></div>
        <div class="text-[11px] text-discord-400 font-medium uppercase tracking-wide">Peak</div>
      </div>
    </div>
  </div>

  <?php if ($myChannels): ?>
  <div class="mb-8">
    <div class="flex items-center gap-3 mb-4">
      <div class="h-px flex-1 bg-blurple/30"></div>
      <span class="text-xs font-bold uppercase tracking-widest text-blurple">My Channels — <?= count($myChannels) ?></span>
      <div class="h-px flex-1 bg-blurple/30"></div>
    </div>
    <div class="space-y-2">
      <?php foreach ($myChannels as $c): ?><?= channel_card($c, $joinedMap) ?><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div>
    <div class="flex flex-wrap gap-3 items-center mb-4">
      <input id="ch-search" class="input w-64 !py-2 !rounded-lg" placeholder="Search by name or topic…" autocomplete="off">
      <select id="ch-filter" class="input w-44 !py-2 !rounded-lg">
        <option value="all">All channels</option>
        <option value="open">Not joined</option>
        <option value="joined">Joined</option>
      </select>
      <span id="ch-count" class="text-xs text-discord-500 font-medium ml-auto"></span>
    </div>
    <div id="ch-list" class="space-y-2">
      <?php foreach ($channels as $c): ?><?= channel_card($c, $joinedMap) ?><?php endforeach; ?>
    </div>
    <?php if (!$channels): ?>
    <div class="py-16 text-center">
      <div class="text-4xl mb-3 opacity-40">🔍</div>
      <div class="text-discord-400 text-sm font-medium">No public channels found</div>
      <div class="text-discord-500 text-xs mt-1">Be the first to create one!</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(() => {
  const list = document.getElementById('ch-list');
  if (!list) return;
  const cards = Array.prototype.slice.call(list.querySelectorAll('.browse-row-premium'));
  const search = document.getElementById('ch-search');
  const filter = document.getElementById('ch-filter');
  const count = document.getElementById('ch-count');

  function apply() {
    const q = (search.value || '').trim().toLowerCase();
    const f = filter.value;
    let visible = cards.filter((c) => {
      if (f === 'joined' && c.dataset.joined !== '1') return false;
      if (f === 'open' && c.dataset.joined === '1') return false;
      if (q && c.dataset.name.indexOf(q) === -1 && c.dataset.topic.indexOf(q) === -1) return false;
      return true;
    });
    visible.sort((a, b) => {
      return (parseInt(b.dataset.members, 10) || 0) - (parseInt(a.dataset.members, 10) || 0);
    });
    const shown = new Set(visible);
    cards.forEach((c) => {
      if (!shown.has(c)) c.classList.add('hidden');
      else { c.classList.remove('hidden'); list.appendChild(c); }
    });
    count.textContent = visible.length + ' channel' + (visible.length === 1 ? '' : 's');
  }

  search.addEventListener('input', apply);
  filter.addEventListener('change', apply);
  apply();
})();
</script>

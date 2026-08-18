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

 $title = 'Analytics'; $active = 'analytics';
$pageActions = '<div class="flex items-center gap-1 bg-discord-850 border border-discord-700 rounded-lg p-1 text-sm">
    ' . implode('', array_map(function ($r) use ($range) {
        return '<a href="/admin/analytics?range=' . $r . '" class="px-3 py-1 rounded-md '
            . ($range === $r ? 'bg-discord-700 text-white' : 'text-discord-300 hover:bg-discord-750 hover:text-white') . '">'
            . ($r === 'all' ? 'All time' : $r . 'd') . '</a>';
    }, AnalyticsService::ranges())) . '
  </div>';
require ROOT . '/views/admin/_charts.php';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>

<div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 mb-6">
  <?php
  $kpiCards = [
    ['Total users', $kpis['total_users'], 'users', 'blurple'],
    ['Online now', $kpis['online_now'], 'zap', 'green', 'last 30s'],
    ['Peak online', $kpis['peak_online'], 'arrow-up', 'amber', 'all-time record'],
    ['Messages', $kpis['messages'], 'message-square', 'purple', 'channels + DMs, range'],
    ['Private messages', $kpis['pms'], 'mail', 'pink', 'range'],
    ['Censor hits', $kpis['censor_hits'], 'alert', 'red', 'bad-word triggers, range'],
    ['Open reports', $kpis['open_reports'], 'flag', 'amber', 'unresolved'],
  ];
  $statTint = ['blurple' => '', 'green' => 'green', 'amber' => 'amber', 'purple' => 'purple', 'pink' => 'pink', 'red' => 'red'];
  foreach ($kpiCards as $kpiCard):
    [$label, $val, $iconName, $tint] = $kpiCard;
    $sub = $kpiCard[4] ?? '';
  ?>
  <div class="stat-card">
    <div class="stat-icon <?= $statTint[$tint] ?>"><?= icon($iconName, 'w-5 h-5') ?></div>
    <div class="min-w-0">
      <div class="stat-value"><?= (int) $val ?></div>
      <div class="stat-label"><?= h($label) ?></div>
      <?php if ($sub): ?><div class="text-[10px] text-discord-500"><?= h($sub) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="flex flex-wrap gap-3 mb-8 text-sm">
  <span class="card px-3 py-1.5 text-discord-300">Active accounts — <b class="text-white"><?= (int) $activeCounts['users_24h'] ?></b> <span class="text-discord-500">today</span> · <b class="text-white"><?= (int) $activeCounts['users_7d'] ?></b> <span class="text-discord-500">7d</span> · <b class="text-white"><?= (int) $activeCounts['users_30d'] ?></b> <span class="text-discord-500">30d</span></span>
  <span class="card px-3 py-1.5 text-discord-300">Guests active (30d) — <b class="text-white"><?= (int) $activeCounts['guests_30d'] ?></b></span>
</div>

<?php
$rangeLabel = $range === 'all' ? 'all time' : 'last ' . $range . ' days';
$modLabels = ['badword' => 'Bad word', 'spamfilter' => 'Spam filter', 'kick' => 'Kick', 'ban' => 'Ban', 'kill' => 'Kill', 'kline' => 'K-line', 'gline' => 'G-line', 'zline' => 'Z-line', 'shun' => 'Shun', 'quiet' => 'Quiet'];
$reportLabels = ['open' => 'Open', 'investigated' => 'Investigated', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];

function analytics_card(string $title, string $subtitle, string $body): void
{
    echo '<div class="card p-5">';
    echo '<div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">';
    echo '<h2 class="font-semibold text-white text-sm">' . h($title) . '</h2>';
    if ($subtitle !== '') {
        echo '<span class="text-[11px] text-discord-500">' . h($subtitle) . '</span>';
    }
    echo '</div>';
    echo $body;
    echo '</div>';
}
?>

<div class="admin-section-title"><?= icon('zap', 'w-4 h-4') ?>Activity</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
  <?php
  analytics_card('Messages per day', $rangeLabel, chart_line($messagesDaily, ['color' => '#5865f2']));
  analytics_card('Daily active users', $rangeLabel . ' — distinct people who spoke', chart_line($dauDaily, ['color' => '#10b981']));
  analytics_card('Private messages per day', $rangeLabel, chart_line($pmsDaily, ['color' => '#a855f7']));
  analytics_card('New registrations per day', $rangeLabel, chart_line($registrationsDaily, ['color' => '#06b6d4']));
  analytics_card('Most active users', $rangeLabel . ' — channel messages + DMs', chart_hbars($topUsers, ['color' => '#5865f2']));
  analytics_card('Activity by hour of day', $rangeLabel . ' (UTC)', chart_vbars($hourly, ['color' => '#5865f2']));
  analytics_card('Activity by weekday', $rangeLabel . ' (UTC)', chart_vbars($weekday, ['color' => '#10b981']));
  analytics_card('Most active channels', $rangeLabel, chart_hbars($topChannels, ['color' => '#06b6d4']));
  analytics_card('Top DM senders', $rangeLabel, chart_hbars($topDmSenders, ['color' => '#a855f7']));
  ?>
</div>

<div class="card overflow-x-auto mb-8">
  <div class="px-5 py-3.5 border-b border-discord-700 text-sm font-semibold text-white">Least active accounts</div>
  <table class="data-table">
    <thead>
      <tr><th>User</th><th>Messages</th><th>PMs</th><th>Registered</th><th>Last seen</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($leastActive as $u): ?>
      <tr>
        <td>
          <a href="/admin/users/<?= (int) $u['id'] ?>" class="font-medium text-white hover:underline"><?= h($u['username']) ?></a>
        </td>
        <td><?= (int) $u['messages'] ?></td>
        <td><?= (int) $u['pms'] ?></td>
        <td class="text-discord-400"><?= h(gmdate('Y-m-d', strtotime($u['registered_at'] . ' UTC'))) ?></td>
        <td class="text-discord-400"><?= $u['last_seen'] ? h(relative_time($u['last_seen'])) : '<span class="text-discord-500">never</span>' ?></td>
        <td>
          <?php if ((int) $u['banned']): ?>
          <span class="px-1.5 py-0.5 rounded text-[11px] bg-red-500/20 text-red-300">banned</span>
          <?php elseif ($u['status'] === 'suspended'): ?>
          <span class="px-1.5 py-0.5 rounded text-[11px] bg-red-500/20 text-red-300">suspended</span>
          <?php elseif ($u['status'] === 'pending'): ?>
          <span class="px-1.5 py-0.5 rounded text-[11px] bg-amber-500/20 text-amber-300">pending</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$leastActive): ?>
      <tr><td colspan="6" class="py-4 text-discord-500">No accounts yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="admin-section-title"><?= icon('shield', 'w-4 h-4') ?>Moderation</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
  <?php
  $mix = [];
  foreach ($moderationMix as $m) {
      $mix[] = ['label' => $modLabels[$m['label']] ?? ucfirst(str_replace('_', ' ', $m['label'])), 'value' => $m['value']];
  }
  $bans = [];
  foreach ($banMix as $b) {
      $bans[] = ['label' => $modLabels[$b['label']] ?? ucfirst(str_replace('_', ' ', $b['label'])), 'value' => $b['value']];
  }
  $reports = [];
  foreach ($reportMix as $rm) {
      $reports[] = ['label' => $reportLabels[$rm['label']] ?? ucfirst($rm['label']), 'value' => $rm['value']];
  }
  analytics_card('Censor leaders', $rangeLabel . ' — users who tripped the bad-word filter', chart_hbars($censorLeaders, ['color' => '#ef4444']));
  analytics_card('Spam-filter leaders', $rangeLabel, chart_hbars($spamLeaders, ['color' => '#f59e0b']));
  analytics_card('Most common matched words', $rangeLabel . ' — words/patterns that tripped filters', chart_hbars($topMatchedWords, ['color' => '#ec4899']));
  analytics_card('Filter hits per day', $rangeLabel . ' — bad words + spam filters', chart_line($moderationDaily, ['color' => '#f59e0b']));
  analytics_card('Moderation action mix', $rangeLabel, chart_donut($mix));
  analytics_card('Ban types', 'all-time, global + channel', chart_donut($bans));
  analytics_card('Reports by status', $rangeLabel, chart_donut($reports));
  analytics_card('Most reported users', $rangeLabel, chart_hbars($topReported, ['color' => '#f97316']));
  analytics_card('Report reasons', $rangeLabel, chart_hbars($reportReasons, ['color' => '#84cc16']));
  ?>
</div>

<div class="admin-section-title"><?= icon('heart', 'w-4 h-4') ?>Health &amp; operations</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
  <?php
  analytics_card('Audit events per day', $rangeLabel, chart_line($auditDaily, ['color' => '#14b8a6']));
  analytics_card('Support tickets per day', $rangeLabel, chart_line($ticketsDaily, ['color' => '#a855f7']));
  analytics_card('Account invites', $rangeLabel . ' — used / pending / expired', chart_donut($inviteStats));
  ?>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
      <h2 class="font-semibold text-white text-sm">Webhook activity</h2>
      <span class="text-[11px] text-discord-500">last used</span>
    </div>
    <?php if ($webhooks): ?>
    <ul class="space-y-2 text-sm">
      <?php foreach ($webhooks as $wh): ?>
      <li class="flex items-center justify-between gap-2">
        <span class="text-discord-200"><?= h($wh['webhook']) ?> <span class="text-discord-500">→ <?= h($wh['channel']) ?></span></span>
        <span class="text-discord-400 text-xs whitespace-nowrap"><?= $wh['last_used'] ? h(relative_time($wh['last_used'])) : '<span class="text-discord-500">never</span>' ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="text-sm text-discord-500">No webhooks configured yet.</div>
    <?php endif; ?>
  </div>
</div>

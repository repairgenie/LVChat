<?php
$title = 'Admin dashboard';
$active = $active ?? '';
$user = $admin ?? null;
function admin_nav(string $active): void {
  $items = [
    'overview' => ['/admin', 'Overview'],
    'users' => ['/admin/users', 'Users'],
    'channels' => ['/admin/channels', 'Channels'],
    'bans' => ['/admin/bans', 'Bans'],
    'spamfilters' => ['/admin/spamfilters', 'Spam filters'],
    'badwords' => ['/admin/badwords', 'Bad words'],
    'roles' => ['/admin/roles', 'Roles'],
    'opers' => ['/admin/opers', 'O-lines'],
    'operclasses' => ['/admin/operclasses', 'Operclasses'],
    'motd' => ['/admin/motd', 'MOTD'],
    'logs' => ['/admin/logs', 'Chat logs'],
    'webhooks' => ['/admin/webhooks', 'Webhooks'],
    'settings' => ['/admin/settings', 'Settings'],
  ];
  echo '<div class="flex flex-wrap gap-1 mb-6 bg-discord-850 border border-discord-700 rounded-lg p-1">';
  foreach ($items as $k => [$href, $label]) {
    $on = $active === $k ? 'bg-discord-700 text-white' : 'text-discord-300 hover:bg-discord-750';
    echo '<a href="' . h($href) . '" class="px-3 py-1.5 rounded-md text-sm font-medium ' . $on . '">' . h($label) . '</a>';
  }
  echo '</div>';
}
admin_nav($active);

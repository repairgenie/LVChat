<?php
// The whole admin dashboard uses the full-width layout (like the chat logs page).
$fullWidth = true;
$title = 'Admin dashboard';
$active = $active ?? '';
$user = $admin ?? null;
function admin_nav(string $active, array $user): void {
  // Moderation-facing pages are available to staff too; the rest are admin-only.
  $isStaff = ModerationService::isStaff($user);
  $isAdmin = ($user['role'] ?? '') === 'admin';
  $items = [
    'overview' => ['/admin', 'Overview', $isAdmin],
    'moderation' => ['/admin/moderation', 'Moderation', $isStaff],
    'reports' => ['/admin/reports', 'Reports', $isStaff],
    'support' => ['/admin/support', 'Support', $isStaff],
    'users' => ['/admin/users', 'Users', $isAdmin],
    'channels' => ['/admin/channels', 'Channels', $isAdmin],
    'bans' => ['/admin/bans', 'Bans', $isAdmin],
    'spamfilters' => ['/admin/spamfilters', 'Spam filters', $isAdmin],
    'badwords' => ['/admin/badwords', 'Bad words', $isAdmin],
    'roles' => ['/admin/roles', 'Roles', $isAdmin],
    'opers' => ['/admin/opers', 'O-lines', $isAdmin],
    'operclasses' => ['/admin/operclasses', 'Operclasses', $isAdmin],
    'motd' => ['/admin/motd', 'MOTD', $isAdmin],
    'sounds' => ['/admin/sounds', 'Sounds', $isAdmin],
    'logs' => ['/admin/logs', 'Chat logs', $isAdmin],
    'webhooks' => ['/admin/webhooks', 'Webhooks', $isAdmin],
    'invites' => ['/admin/invites', 'Invites', $isAdmin],
    'legal' => ['/admin/legal', 'Terms & Privacy', $isAdmin],
    'settings' => ['/admin/settings', 'Settings', $isAdmin],
  ];
  echo '<div class="flex flex-wrap gap-1 mb-6 bg-discord-850 border border-discord-700 rounded-lg p-1">';
  foreach ($items as $k => [$href, $label, $visible]) {
    if (!$visible) {
      continue;
    }
    $on = $active === $k ? 'bg-discord-700 text-white' : 'text-discord-300 hover:bg-discord-750';
    echo '<a href="' . h($href) . '" class="px-3 py-1.5 rounded-md text-sm font-medium ' . $on . '">' . h($label) . '</a>';
  }
  echo '</div>';
}
admin_nav($active, is_array($user) ? $user : []);

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


// The whole admin dashboard uses the full-width layout (like the chat logs page).
$fullWidth = true;
$donateFooter = true;
$title = 'Admin dashboard';
$active = $active ?? '';
$user = $admin ?? null;
function admin_nav(string $active, array $user): void {
  // Moderation-facing pages are available to staff too; the rest are admin-only.
  $isStaff = ModerationService::isStaff($user);
  $isAdmin = ($user['role'] ?? '') === 'admin';
  $items = [
    'overview' => ['/admin', 'Overview', $isAdmin],
    'analytics' => ['/admin/analytics', 'Analytics', $isAdmin],
    'moderation' => ['/admin/moderation', 'Moderation', $isStaff],
    'reports' => ['/admin/reports', 'Reports', $isStaff],
    'support' => ['/admin/support', 'Support', $isStaff],
    'users' => ['/admin/users', 'Users', $isAdmin],
    'channels' => ['/admin/channels', 'Channels', $isAdmin],
    'bans' => ['/admin/bans', 'Bans', $isAdmin],
    'urls' => ['/admin/urls', 'Blocked URLs', $isAdmin],
    'spamfilters' => ['/admin/spamfilters', 'Spam filters', $isAdmin],
    'badwords' => ['/admin/badwords', 'Bad words', $isAdmin],
    'roles' => ['/admin/roles', 'Roles', $isAdmin],
    'opers' => ['/admin/opers', 'O-lines', $isAdmin],
    'operclasses' => ['/admin/operclasses', 'Operclasses', $isAdmin],
    'motd' => ['/admin/motd', 'MOTD', $isAdmin],
    'sounds' => ['/admin/sounds', 'Sounds', $isAdmin],
    'theme' => ['/admin/theme', 'Appearance', $isAdmin],
    'logs' => ['/admin/logs', 'Chat logs', $isAdmin],
    'webhooks' => ['/admin/webhooks', 'Webhooks', $isAdmin],
    'openclaw' => ['/admin/openclaw', 'OpenClaw', $isAdmin],
    'invites' => ['/admin/invites', 'Invites', $isAdmin],
    'legal' => ['/admin/legal', 'Terms & Privacy', $isAdmin],
    'updates' => ['/admin/updates', 'Updates', $isAdmin],
    'modules' => ['/admin/modules', 'Modules', $isAdmin],
    'settings' => ['/admin/settings', 'Settings', $isAdmin],
  ];
  // Modules can add their own admin pages via their manifest `admin` block.
  foreach (ModuleLoader::adminNav() as $k => $entry) {
    $visible = $isAdmin || ($isStaff && in_array('staff', $entry['roles'], true));
    $items[$k] = [$entry['url'], $entry['label'], $visible];
  }
  echo '<div class="sticky top-0 z-30 -mx-2 px-2 py-2 mb-6 bg-discord-900/90 backdrop-blur-sm border border-discord-700 rounded-xl shadow-lg">';
  echo '<div class="flex flex-wrap gap-1">';
  foreach ($items as $k => [$href, $label, $visible]) {
    if (!$visible) {
      continue;
    }
    $on = $active === $k ? 'bg-blurple/20 text-white ring-1 ring-blurple/40 border-blurple/40' : 'text-discord-300 hover:bg-discord-750 hover:text-white';
    echo '<a href="' . h($href) . '" class="px-3 py-1.5 rounded-md text-sm font-medium border border-transparent transition-colors ' . $on . '">' . h($label) . '</a>';
  }
  echo '</div></div>';
}
admin_nav($active, is_array($user) ? $user : []);

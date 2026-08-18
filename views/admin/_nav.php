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


// Admin shell: groups the whole dashboard behind a sticky, grouped sidebar.
// The page sets $active (and optionally $title/$subtitle) before including this
// partial; the real markup is emitted by views/layout.php into $adminSidebarHtml.
$fullWidth = true;
$donateFooter = true;
$title = $title ?? 'Admin dashboard';
$active = $active ?? '';
$user = $admin ?? null;

if (!function_exists('admin_sidebar_html')) {
function admin_sidebar_html(string $active, array $user): string
{
    // Moderation-facing pages are available to staff too; the rest are admin-only.
    $isStaff = ModerationService::isStaff($user);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $badges = [];
    if ($isStaff) {
        $badges['reports'] = (int) Database::scalar("SELECT COUNT(*) FROM reports WHERE status = 'open'");
    }
    if ($isAdmin) {
        $badges['users'] = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    }

    $groups = [
        'Dashboard' => [
            'overview' => ['/admin', 'Overview', 'layout', $isAdmin],
            'analytics' => ['/admin/analytics', 'Analytics', 'grid', $isAdmin],
        ],
        'Moderation' => [
            'moderation' => ['/admin/moderation', 'Moderation', 'shield', $isStaff],
            'reports' => ['/admin/reports', 'Reports', 'flag', $isStaff],
            'users' => ['/admin/users', 'Users', 'users', $isAdmin],
            'bans' => ['/admin/bans', 'Bans', 'slash', $isAdmin],
            'support' => ['/admin/support', 'Support', 'help', $isStaff],
        ],
        'Community' => [
            'channels' => ['/admin/channels', 'Channels', 'hash', $isAdmin],
            'invites' => ['/admin/invites', 'Invites', 'mail', $isAdmin],
            'roles' => ['/admin/roles', 'Roles', 'star', $isAdmin],
            'opers' => ['/admin/opers', 'O-lines', 'terminal', $isAdmin],
            'operclasses' => ['/admin/operclasses', 'Operclasses', 'book', $isAdmin],
            'webhooks' => ['/admin/webhooks', 'Webhooks', 'zap', $isAdmin],
        ],
        'Content' => [
            'motd' => ['/admin/motd', 'MOTD', 'quote', $isAdmin],
            'badwords' => ['/admin/badwords', 'Bad words', 'alert', $isAdmin],
            'spamfilters' => ['/admin/spamfilters', 'Spam filters', 'filter', $isAdmin],
            'urls' => ['/admin/urls', 'Blocked URLs', 'link', $isAdmin],
            'sounds' => ['/admin/sounds', 'Sounds', 'music', $isAdmin],
            'theme' => ['/admin/theme', 'Appearance', 'sparkles', $isAdmin],
            'legal' => ['/admin/legal', 'Terms & Privacy', 'file-text', $isAdmin],
        ],
        'System' => [
            'logs' => ['/admin/logs', 'Chat logs', 'message-square', $isAdmin],
            'settings' => ['/admin/settings', 'Settings', 'gear', $isAdmin],
            'updates' => ['/admin/updates', 'Updates', 'download', $isAdmin],
            'modules' => ['/admin/modules', 'Modules', 'grid', $isAdmin],
            'openclaw' => ['/admin/openclaw', 'OpenClaw', 'compass', $isAdmin],
        ],
    ];

    // Modules can add their own admin pages via their manifest `admin` block.
    $moduleItems = [];
    foreach (ModuleLoader::adminNav() as $k => $entry) {
        $visible = $isAdmin || ($isStaff && in_array('staff', $entry['roles'], true));
        $moduleItems[$k] = [$entry['url'], $entry['label'], 'code', $visible];
    }

    $out = '';
    $itemHtml = static function (string $label, string $href, string $iconName, string $k) use ($active, $badges): string {
        $badge = ($badges[$k] ?? 0) > 0 ? '<span class="admin-nav-badge">' . (int) $badges[$k] . '</span>' : '';
        $on = $active === $k ? ' active' : '';
        return '<a href="' . h($href) . '" class="admin-nav-link' . $on . '">'
            . icon($iconName, 'w-4 h-4')
            . '<span class="min-w-0 truncate">' . h($label) . '</span>'
            . $badge . '</a>';
    };

    foreach ($groups as $groupName => $items) {
        $html = '';
        foreach ($items as $k => [$href, $label, $iconName, $visible]) {
            if (!$visible) {
                continue;
            }
            $html .= $itemHtml($label, $href, $iconName, $k);
        }
        if ($html === '') {
            continue;
        }
        $out .= '<div class="admin-nav-heading">' . h($groupName) . '</div>' . $html;
    }
    if ($moduleItems) {
        $html = '';
        foreach ($moduleItems as $k => [$href, $label, $iconName, $visible]) {
            if (!$visible) {
                continue;
            }
            $html .= $itemHtml($label, $href, $iconName, $k);
        }
        if ($html !== '') {
            $out .= '<div class="admin-nav-heading">Modules</div>' . $html;
        }
    }
    return $out;
}
}

$adminSidebarHtml = admin_sidebar_html($active, is_array($user) ? $user : []);
$adminShell = true;

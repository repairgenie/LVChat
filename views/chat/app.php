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


$site = config_get('site_name', 'LVChat');
$csrf = Csrf::token();
$channelSlug = $channel['slug'] ?? '';
$dmName = $dm['username'] ?? '';
$theme = ThemeService::effectiveForView($user)['mode'] === 'light' ? 'light' : '';
$currentLevel = $channel ? AccessService::effectiveLevel($channel['id'], (int) $user['id']) : 'normal';
$myLevelWeight = level_weight($currentLevel);
$lastMsg = null;
foreach ($messages as $m) {
    if (($m['kind'] ?? '') === 'message') {
        $lastMsg = $m;
    }
}

// Desktop app download config (Admin → Settings → Desktop apps & downloads).
// Each platform button is rendered only when a URL is configured.
$downloadPlatforms = [
    'win' => 'Windows',
    'mac' => 'macOS',
    'linux_rpm' => 'Linux (RPM)',
    'linux_deb' => 'Linux (DEB)',
    'linux_appimage' => 'Linux (AppImage)',
];
$downloadUpdateUrl = trim((string) (config_get('download_update_url', '') ?? ''));
if ($downloadUpdateUrl === '' && UpdaterService::enabled()) {
    $downloadUpdateUrl = UpdaterService::baseUrl();
}
function download_buttons_html(string $app, array $platforms): string
{
    $html = '';
    foreach ($platforms as $plat => $label) {
        // Custom override wins; otherwise the upstream feed; else hide.
        $url = UpdaterService::effectiveUrl($app, $plat);
        if ($url === '') {
            continue;
        }
        $ver = UpdaterService::effectiveVersion($app, $plat);
        $html .= '<a href="' . h($url) . '" target="_blank" rel="noopener noreferrer" class="btn-ghost w-full justify-between !text-sm">'
            . '<span>' . h($label) . '</span>'
            . '<span class="font-mono text-xs ' . ($ver !== '' ? 'text-discord-400' : 'text-discord-500') . '">' . ($ver !== '' ? 'v' . h($ver) : 'Download') . '</span>'
            . '</a>';
    }
    return $html;
}

// Channel mode flags shown in the GUI bar (with tooltips).
$modeDefs = [
    'i' => ['short' => 'invite', 'title' => 'Invite-only (+i): only users you invite may join.'],
    'm' => ['short' => 'moderated', 'title' => 'Moderated (+m): only voiced users (+v) may speak.'],
    'C' => ['short' => 'filter', 'title' => 'Word filter (+C): applies the server bad-word list to this channel (censor or block).'],
    'k' => ['short' => 'key', 'title' => 'Channel key (+k): a password is required to join.'],
    'l' => ['short' => 'limit', 'title' => 'Member limit (+l): maximum number of members allowed in the channel.'],
    't' => ['short' => 'topic lock', 'title' => 'Topic lock (+t): only operators may change the topic.'],
    'p' => ['short' => 'private', 'title' => 'Private (+p): hidden from /list, joinable via its share link.'],
    's' => ['short' => 'secret', 'title' => 'Secret (+s): hidden entirely; join by invitation only.'],
    'L' => ['short' => 'no-log', 'title' => 'No logging (+L): messages in this channel are not recorded in the server archive (opers only).'],
];
$modeState = $channel ? [
    'i' => (int) $channel['invite_only'] === 1,
    'm' => (int) $channel['moderated'] === 1,
    'C' => (int) ($channel['censor'] ?? 0) === 1,
    'k' => !empty($channel['key_hash']),
    'l' => !empty($channel['member_limit']),
    't' => (int) $channel['topic_locked'] === 1,
    'p' => ($channel['visibility'] ?? '') === 'private',
    's' => ($channel['visibility'] ?? '') === 'secret',
    'L' => (int) ($channel['no_logging'] ?? 0) === 1,
] : [];
$canManageModes = $myLevelWeight >= 3 || $user['role'] === 'admin';
$canManageSettings = $channel ? ChannelService::canManageChannel($channel, $user) : false;
$channelUrl = $channel ? ChannelService::channelUrl($channel) : null;

function chat_divider_label(string $date): string {
    $today = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', time() - 86400);
    if ($date === $today) {
        return 'Today';
    }
    if ($date === $yesterday) {
        return 'Yesterday';
    }
    return date('F j, Y', strtotime($date . ' 00:00:00 UTC'));
}

function reactions_html(array $m, array $viewer): string {
    if (!MessageService::reactionsEnabled()) {
        return '';
    }
    $rows = $m['reactions'] ?? [];
    $mine = array_map('strval', (array) ($m['my_reactions'] ?? []));
    if (!$rows) {
        return '';
    }
    $html = '<div class="msg-reactions flex flex-wrap gap-1.5 mt-1.5">';
    foreach ($rows as $r) {
        $emoji = h((string) $r['emoji']);
        $count = (int) $r['count'];
        $mineClass = in_array((string) $r['emoji'], $mine, true) ? ' bg-blurple/30 border-blurple/60' : ' bg-discord-800 border-discord-700';
        $html .= '<button type="button" class="reaction-btn flex items-center gap-1 px-2 py-0.5 rounded-md text-xs border transition-colors hover:border-blurple/60 hover:bg-blurple/20"' . $mineClass . ' data-emoji="' . $emoji . '" title="Toggle reaction">'
            . '<span class="text-sm leading-none">' . $emoji . '</span>'
            . '<span class="text-discord-300 font-medium">' . $count . '</span>'
            . '</button>';
    }
    $html .= '<button type="button" class="reaction-add px-2 py-0.5 rounded-md text-xs bg-discord-800 border border-discord-700 text-discord-400 hover:text-white hover:border-blurple/60" title="Add a reaction">+</button>';
    $html .= '</div>';
    return $html;
}

function msg_action_buttons(array $viewer, array $m): string {
    $mine = ((int) $m['sender_id'] === (int) $viewer['id'])
        || (!empty($m['username']) && strcasecmp((string) $m['username'], (string) $viewer['username']) === 0);
    $canEdit = $viewer['role'] === 'admin' || $mine;
    $html = '<button type="button" class="msg-react-btn p-1.5 rounded-md text-discord-400 hover:text-white hover:bg-discord-700" title="Add a reaction">' . icon('smile') . '</button>'
        . '<button type="button" class="msg-reply-btn p-1.5 rounded-md text-discord-400 hover:text-white hover:bg-discord-700" title="Reply">' . icon('reply') . '</button>';
    if ($canEdit) {
        $html .= '<button type="button" class="msg-edit p-1.5 rounded-md text-discord-400 hover:text-white hover:bg-discord-700" title="Edit">' . icon('edit') . '</button>'
            . '<button type="button" class="msg-del p-1.5 rounded-md text-discord-400 hover:text-red-400 hover:bg-discord-700" title="Delete">' . icon('trash') . '</button>';
    }
    return $html;
}

function msg_html(array $m, ?array $prev, array $viewer): string {
    $system = in_array($m['kind'], MessageService::SYSTEM_KINDS, true);
    if ($system) {
        return '<div class="msg-system px-4 py-1.5 text-xs text-discord-400 italic text-center select-none" data-id="' . (int) $m['id'] . '" data-kind="' . h($m['kind']) . '">' . chat_markup($m['content']) . '</div>';
    }
    if ($m['kind'] === 'action') {
        $isAdmin = ($m['role'] ?? '') === 'admin';
        $rc = (!$isAdmin && !empty($m['role_color'])) ? ' style="color:' . h($m['role_color']) . '"' : '';
        $guestTag = !empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
        $nameColor = $isAdmin ? 'text-red-400' : 'text-discord-100';
        return '<div class="msg group px-4 py-0.5 flex gap-4 hover:bg-white/[0.03]" data-id="' . (int) $m['id'] . '" data-kind="action" data-is-pm="' . (!empty($m['is_pm']) ? '1' : '0') . '" data-author="' . h($m['username']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">'
            . '<div class="w-10 shrink-0"></div>'
            . '<div class="text-sm ' . ($isAdmin ? 'text-red-400' : 'text-discord-200') . '"' . $rc . '><span class="italic">* <span class="font-medium ' . $nameColor . '"' . $rc . '>' . h($m['username']) . '</span>' . $guestTag . ' ' . chat_markup($m['content']) . '</span></div>'
            . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white self-start mt-0.5 ml-auto p-0.5" title="More">' . icon('more-h', 'w-3.5 h-3.5') . '</button>'
            . '</div>';
    }

    $group = false;
    if ($prev && $prev['kind'] === 'message' && (int) $prev['sender_id'] === (int) $m['sender_id']) {
        $dt = abs(strtotime($m['created_at'] . ' UTC') - strtotime($prev['created_at'] . ' UTC'));
        if ($dt < 300) {
            $group = true;
        }
    }

    $initial = strtoupper(mb_substr($m['username'] ?? '?', 0, 1));
    $isAdmin = ($m['role'] ?? '') === 'admin';
    $roleColor = (!$isAdmin && !empty($m['role_color'])) ? (string) $m['role_color'] : null;
    $nameStyle = $roleColor !== null ? ' style="color:' . h($roleColor) . '"' : '';
    $contentStyle = $roleColor !== null ? ' style="color:' . h($roleColor) . '"' : '';
    $color = $isAdmin ? 'text-red-400' : level_color($m['level'] ?? 'normal');
    $contentColor = $isAdmin ? 'text-red-400' : 'text-discord-200';
    $levelSym = level_symbol($m['level'] ?? 'normal');

    // Reply line: context for messages that quote another message.
    $replyLine = '';
    if (!empty($m['reply_to_id'])) {
        $rnick = $m['reply_to_username'] ?? '';
        $excerpt = trim((string) ($m['reply_to_excerpt'] ?? ''));
        $replyLine = '<a class="reply-line block text-xs text-discord-400 italic hover:text-discord-300 mt-0.5 break-all" href="#msg-' . (int) $m['reply_to_id'] . '" data-reply-scroll="' . (int) $m['reply_to_id'] . '">↪ <span class="font-semibold">' . h($rnick) . '</span>: ' . h($excerpt) . '</a>';
    }

    if ($group) {
        return '<div class="msg group px-4 py-0.5 hover:bg-white/[0.03] flex gap-4" data-id="' . (int) $m['id'] . '" data-kind="message" data-is-pm="' . (!empty($m['is_pm']) ? '1' : '0') . '" data-author="' . h($m['username']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">'
            . '<div class="w-10 shrink-0"></div>'
            . '<div class="min-w-0 flex-1">'
            . $replyLine
            . '<div class="msg-content text-[15px] leading-[1.4] ' . $contentColor . ' break-words"' . $contentStyle . '>' . chat_content_html($m) . '</div>'
            . reactions_html($m, $viewer)
            . '</div>'
            . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white self-start mt-0.5 p-0.5" title="More">' . icon('more-h', 'w-3.5 h-3.5') . '</button>'
            . '</div>';
    }

    $actions = msg_action_buttons($viewer, $m);

    return '<div class="msg group relative px-4 pt-[17px] pb-0.5 hover:bg-white/[0.03] flex gap-4" data-id="' . (int) $m['id'] . '" data-kind="' . h($m['kind']) . '" data-is-pm="' . (!empty($m['is_pm']) ? '1' : '0') . '" data-author="' . h($m['username']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '" data-bot="' . (!empty($m['bot']) ? '1' : '0') . '">'
        . '<div class="w-10 h-10 shrink-0">' . avatar_img($m, 'w-10 h-10 rounded-full') . '</div>'
        . '<div class="min-w-0 flex-1">'
        . '<div class="flex items-baseline gap-2 h-[22px]">'
        . '<span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ' . $color . '"' . $nameStyle . ' data-nick="' . h($m['username']) . '">' . $levelSym . h($m['username']) . (!empty($m['bot']) ? '<span class="bot-badge">BOT</span>' : '') . (!empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '') . '</span>'
        . '<span class="time text-[11px] text-discord-400 hidden group-hover:inline" data-ts="' . h($m['created_at']) . '">' . h(date('H:i', strtotime($m['created_at'] . ' UTC'))) . '</span>'
        . (!empty($m['edited_at']) ? '<span class="text-[10px] text-discord-400">(edited)</span>' : '')
        . '</div>'
        . $replyLine
        . '<div class="msg-content text-[15px] leading-[1.4] ' . $contentColor . ' break-words"' . $contentStyle . '>' . chat_content_html($m) . '</div>'
        . reactions_html($m, $viewer)
        . '</div>'
        . '<div class="msg-actions absolute right-4 -top-3 opacity-0 group-hover:opacity-100 flex items-center gap-0.5 rounded-lg border border-discord-700 bg-discord-850 shadow-lg px-1 py-0.5 transition-opacity z-10">' . $actions . '</div>'
        . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-1" title="More">' . icon('more-h') . '</button>'
        . '</div>';
}

function channel_link(array $c, string $channelSlug, array $user): string {
    $owned = (int) ($c['owner_id'] ?? 0) === (int) $user['id'] ? '1' : '0';
    $unread = (int) ($c['unread'] ?? 0);
    $online = (int) ($c['online'] ?? 0);
    $vis = $c['visibility'] !== 'public'
        ? '<span class="chan-vis text-discord-400' . ($unread > 0 ? '' : ' ml-auto') . '" title="' . h(ucfirst((string) $c['visibility'])) . ' channel">' . icon($c['visibility'] === 'secret' ? 'lock' : ($c['visibility'] === 'staff' ? 'shield' : 'eye'), 'w-3.5 h-3.5') . '</span>'
        : '';
    $badge = $unread > 0
        ? '<span class="unread-badge ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center">' . ($unread > 99 ? '99+' : $unread) . '</span>'
        : '';
    $nameCls = $unread > 0 ? 'font-semibold text-white' : '';
    $onlineHtml = $online > 0 ? '<span class="chan-online text-[10px] text-discord-400 shrink-0">(' . $online . ')</span>' : '';
    $cls = $channelSlug === $c['slug'] ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white';
    return '<a href="/app?channel=' . h(rawurlencode($c['slug'])) . '"'
        . ' data-ctx-channel="' . h($c['slug']) . '"'
        . ' data-ctx-channel-name="' . h($c['name']) . '"'
        . ' data-owned="' . $owned . '"'
        . ' data-bg-color="' . h((string) ($c['bg_color'] ?? '')) . '"'
        . ' data-bg-image="' . h((string) ($c['bg_image'] ?? '')) . '"'
        . ' data-bg-fit="' . h((string) ($c['bg_fit'] ?? 'contain')) . '"'
        . ' data-bg-overlay="' . (int) ($c['bg_overlay'] ?? ThemeService::CHAT_BG_OVERLAY_DEFAULT) . '"'
        . ' class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm ' . $cls . '">'
        . '<span class="truncate ' . $nameCls . '">' . h($c['name']) . '</span>' . $onlineHtml . $badge . $vis
        . '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white ml-auto shrink-0 p-0.5" title="More">' . icon('more-h', 'w-3.5 h-3.5') . '</button>'
        . '</a>';
}

function member_html(array $m, bool $online): string {
    $badge = $m['role'] === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>'
        : ($m['role'] === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '');
    $color = $m['role'] === 'admin' ? 'text-red-400' : ($online ? level_color($m['level']) : 'text-discord-400');
    $roleStyle = ($m['role'] !== 'admin' && !empty($m['role_color'])) ? ' style="color:' . h($m['role_color']) . '"' : '';
    $guestTag = !empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
    $stDot = presence_dot_class($m);
    $stText = presence_status_text($m);
    return '<a href="/app?dm=' . h(rawurlencode($m['username'])) . '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ' . $color . '"' . $roleStyle . ' data-username="' . h($m['username']) . '" data-user-id="' . (int) $m['id'] . '" data-level="' . h($m['level']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">
        <span class="text-[10px] font-bold w-3">' . h(level_symbol($m['level'])) . '</span>
        ' . avatar_img($m, 'w-6 h-6 rounded-full') . '
        <span class="w-2 h-2 rounded-full shrink-0 ' . $stDot . '"></span>
        <span class="min-w-0"><span class="block truncate">' . h($m['username']) . $guestTag . '</span>' . ($stText !== '' ? '<span class="block truncate text-[11px] text-discord-500">' . h($stText) . '</span>' : '') . '</span>' . $badge
        . '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">' . icon('more-h', 'w-3.5 h-3.5') . '</button></a>';
}

/* Tailwind dot class for a user's presence (mirrors presenceDot in app.js). */
function presence_dot_class(array $u): string {
    $m = (string) ($u['status_mode'] ?? '');
    if (!in_array($m, ['online', 'away', 'dnd', 'invisible', 'custom'], true)) {
        $m = !empty($u['away']) ? 'away' : 'online';
    }
    return match ($m) {
        'dnd' => 'bg-red-500',
        'away', 'custom' => 'bg-amber-400',
        'invisible' => 'bg-discord-500',
        default => 'bg-green-500',
    };
}

/* Status text shown under a nick (custom status, else away message). */
function presence_status_text(array $u): string {
    $m = (string) ($u['status_mode'] ?? '');
    $t = trim((string) ($u['custom_status'] ?? ''));
    if ($t === '' && $m === 'away') {
        $t = trim((string) ($u['away'] ?? ''));
    }
    return mb_substr($t, 0, 60);
}

/* Human label for a user's presence mode. */
function presence_mode(array $u): string {
    $m = (string) ($u['status_mode'] ?? '');
    if (!in_array($m, ['online', 'away', 'dnd', 'invisible', 'custom'], true)) {
        $m = !empty($u['away']) ? 'away' : 'online';
    }
    return $m;
}

function presence_label(array $u): string {
    return match (presence_mode($u)) {
        'away' => 'Away',
        'dnd' => 'Do Not Disturb',
        'invisible' => 'Appear Offline',
        'custom' => 'Custom status',
        default => 'Online',
    };
}
?>
<!DOCTYPE html>
<html lang="en" class="dark h-full" data-theme="<?= h($theme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h($channel ? $channel['name'] : ($dm ? 'DM: ' . $dm['username'] : $site)) ?> · <?= h($site) ?></title>
  <script>
  (function () {
    // Apply the theme before CSS paints (no flash). Priority: this browser's
    // choice, then the account's saved choice, then the system preference.
    try {
      var t = localStorage.getItem('lvc.theme') || document.documentElement.getAttribute('data-theme') || '';
      if (!t) t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
      if (t === 'light') document.documentElement.classList.add('light');
    } catch (e) {}
  })();
  </script>
  <?php require ROOT . '/views/partials/tailwind.php'; ?>
  <?php require ROOT . '/views/partials/theme.php'; ?>
  <style>.bot-badge{display:inline-block;font-size:10px;font-weight:600;line-height:1;padding:1px 5px;margin-left:5px;border-radius:3px;background:#5865f2;color:#fff;vertical-align:middle;letter-spacing:.02em}</style>
  <?php require ROOT . '/views/partials/pwa.php'; ?>
  <?php
  // Module assets (docs/modules.md): each enabled module's manifest `assets`
  // lists css/js files served at /modules/<id>/assets/<path>. Loaded here so
  // modules can extend the chat app without touching core views. Manifest
  // order is preserved (a module may list its vendor lib before its own js).
  foreach (ModuleLoader::all() as $__mid => $__man) {
      foreach ((array) ($__man['assets']['css'] ?? []) as $__p) {
          $__p = ltrim((string) $__p, '/');
          $__f = ModuleLoader::dir() . '/' . $__mid . '/assets/' . $__p;
          $__v = is_file($__f) ? (int) @filemtime($__f) : '';
          echo '<link rel="stylesheet" href="/modules/' . h($__mid) . '/assets/' . h($__p) . ($__v ? '?v=' . $__v : '') . '">' . "\n";
      }
      foreach ((array) ($__man['assets']['js'] ?? []) as $__p) {
          $__p = ltrim((string) $__p, '/');
          $__f = ModuleLoader::dir() . '/' . $__mid . '/assets/' . $__p;
          $__v = is_file($__f) ? (int) @filemtime($__f) : '';
          echo '<script src="/modules/' . h($__mid) . '/assets/' . h($__p) . ($__v ? '?v=' . $__v : '') . '"></script>' . "\n";
      }
  }
  unset($__mid, $__man, $__p, $__f, $__v);
  ?>
</head>
<body class="chat-app bg-discord-800 text-discord-200 antialiased flex"
      data-csrf="<?= h($csrf) ?>"
      data-channel="<?= h($channelSlug) ?>"
      data-dm="<?= h($dmName) ?>"
      data-my-id="<?= (int) $user['id'] ?>"
      data-my-guest="<?= (int) ($user['guest'] ?? 0) ?>"
      data-vapid-key="<?= h(PushService::publicKey()) ?>"
      data-push-all-off="<?= (int) (!(int) ($user['guest'] ?? 0) && (int) $pushPrefs['channels'] === 0 && (int) $pushPrefs['dms'] === 0 && (int) $pushPrefs['invites'] === 0) ?>"
      data-push-prefs="<?= h(json_encode(['channels' => (int) $pushPrefs['channels'], 'dms' => (int) $pushPrefs['dms'], 'invites' => (int) $pushPrefs['invites']])) ?>"
      data-my-level="<?= h($currentLevel) ?>"
      data-me-status="<?= h($user['status_mode'] ?? 'online') ?>"
      data-can-op="<?= $myLevelWeight >= 3 || $user['role'] === 'admin' ? '1' : '0' ?>"
      data-can-admin="<?= $user['role'] === 'admin' ? '1' : '0' ?>"
      data-my-nick="<?= h($user['username']) ?>"
      data-channel-url="<?= h((string) ($channelUrl ?? '')) ?>"
      data-site-name="<?= h($site) ?>"
      data-theme-custom="<?= ThemeService::customizationEnabled() ? '1' : '0' ?>"
      data-chan-bg-color="<?= h($channel['bg_color'] ?? '') ?>"
      data-chan-bg-image="<?= h($channel['bg_image'] ?? '') ?>"
      data-chan-bg-fit="<?= h($channel['bg_fit'] ?? 'contain') ?>"
      data-chan-bg-overlay="<?= (int) ($channel['bg_overlay'] ?? ThemeService::CHAT_BG_OVERLAY_DEFAULT) ?>"
      data-version="<?= LVC_VERSION ?>"
      data-poll-ms="<?= (int) ((config_get('poll_interval', '2') ?? 2) * 1000) ?>"
      data-rt="<?= config_get('realtime', 'poll') === 'sse' ? 'sse' : (config_get('realtime', 'poll') === 'ws' ? 'ws' : 'poll') ?>"
      data-rt-force="<?= (config_get('realtime_force', '0') ?? '0') === '1' ? '1' : '0' ?>"
      data-rt-ticket="<?= h($wsTicket ?? '') ?>"
      data-ws-url="<?= h($wsUrl ?? '') ?>"
      data-commands="<?= h(json_encode($commands)) ?>"
      data-channels="<?= h(json_encode($channelLinks)) ?>"
      data-users="<?= h(json_encode(array_map(fn ($u) => ['u' => $u['username'], 'on' => (int) ($u['online'] ?? 0)], $mentionUsers))) ?>"
      data-sounds="<?= h(json_encode($sounds['sounds'])) ?>"
      data-sound-prefs="<?= h(json_encode(['dm_sound_id' => $sounds['dm_sound_id'], 'channel_sound_id' => $sounds['channel_sound_id']])) ?>"
      data-sound-overrides="<?= h(json_encode($sounds['overrides'])) ?>"
      data-notify-prefs="<?= h(json_encode($notifyPrefs)) ?>"
      data-bg-last="<?= (int) $bgLast ?>">

  <!-- ── Left: channel sidebar ── -->
  <aside id="sidebar" class="sidebar w-60 md:w-64 bg-sidebar flex flex-col shrink-0">
    <div class="h-12 px-4 flex items-center justify-between border-b border-discord-700 shadow-sm shrink-0">
      <?php if ($logo = site_logo()): ?>
      <img src="<?= h($logo) ?>" alt="<?= h($site) ?>" class="max-h-8 max-w-[160px] w-auto object-contain">
      <?php else: ?>
      <span class="font-bold text-white text-sm truncate"><?= h($site) ?></span>
      <?php endif; ?>
      <div class="flex items-center gap-1.5">
        <button id="theme-toggle" class="text-discord-300 hover:text-white p-1.5 rounded-md hover:bg-discord-600/40 md:block hidden" title="Switch theme" aria-label="Switch theme"><?= icon('moon', 'w-4 h-4') ?></button>
        <button id="bell" class="relative text-discord-300 hover:text-white p-1.5 rounded-md hover:bg-discord-600/40" title="Notifications" aria-label="Notifications">
          <?= icon('bell', 'w-4 h-4') ?><span id="bell-dot" class="hidden absolute -top-0.5 -right-0.5 w-4 h-4 rounded-full bg-red-500 text-[9px] text-white flex items-center justify-center"></span>
        </button>
      </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <!-- Realtime transport status: websocket / sse / polling / offline. -->
      <div id="rt-status" class="hidden px-2 pt-3" hidden>
        <div class="flex items-center gap-2 px-2 py-1.5 rounded-md text-[10px] font-mono font-semibold uppercase tracking-wide select-none" title="Realtime connection status">
          <span id="rt-dot" class="w-2 h-2 rounded-full bg-discord-500 shrink-0"></span>
          <span id="rt-label" class="truncate">connecting…</span>
        </div>
      </div>
      <div class="px-2 pt-0.5 select-none">
        <div class="px-2 py-1 rounded-md text-[10px] font-mono text-discord-500" title="Core platform version">LVChat <?= h(LVC_VERSION) ?></div>
      </div>

      <?php if ($user['role'] === 'admin'): ?>
      <nav class="px-2 pt-3">
        <a href="/admin" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-red-400 font-bold hover:bg-discord-600/40 hover:text-red-300 text-sm">
          <?= icon('shield', 'w-4 h-4') ?> Admin dashboard
        </a>
      </nav>
      <?php elseif ($user['role'] === 'staff'): ?>
      <nav class="px-2 pt-3">
        <a href="/admin/moderation" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-amber-400 hover:bg-discord-600/40 hover:text-amber-300 text-sm font-medium">
          <?= icon('shield', 'w-4 h-4 text-discord-400') ?> Moderation
        </a>
      </nav>
      <?php endif; ?>

      <?php if ($myChannels): ?>
      <nav class="px-2 pt-2 pb-1">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">My Channels</div>
        <div class="mt-1 space-y-0.5">
          <?php foreach ($myChannels as $c): ?><?= channel_link($c, $channelSlug, $user) ?><?php endforeach; ?>
        </div>
      </nav>
      <?php endif; ?>

      <nav class="px-2 pt-2 pb-2">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400 flex items-center justify-between">
          <span>Text channels</span>
          <button id="create-channel" class="text-discord-400 hover:text-white p-1 rounded" title="Create a channel" aria-label="Create a channel"><?= icon('plus', 'w-3.5 h-3.5') ?></button>
        </div>
        <div class="mt-1 space-y-0.5">
          <button id="browse-btn-sidebar" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-discord-300 hover:bg-discord-600/40 hover:text-white text-sm w-full text-left cursor-pointer">
            <?= icon('globe', 'w-4 h-4 text-discord-400') ?> Browse channels
          </button>
          <?php foreach ($otherChannels as $c): ?><?= channel_link($c, $channelSlug, $user) ?><?php endforeach; ?>
        </div>
      </nav>

      <nav class="px-2 pt-3">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">Direct messages</div>
        <div id="dm-section" class="mt-1 space-y-0.5">
          <?php if (!$dmPartners): ?>
          <div class="px-2 py-1 text-xs text-discord-500">No conversations yet</div>
          <?php endif; ?>
          <?php foreach ($dmPartners as $d): $uc = array_filter($unreadDms, fn ($x) => $x['user_id'] == $d['id']); $ucnt = $uc ? $uc[0]['count'] : 0; $dOnline = $d['away'] === null && !empty($d['last_seen']) && (time() - strtotime($d['last_seen'] . ' UTC')) <= 90; $unreadCls = $ucnt ? 'font-semibold' . (($d['role'] ?? '') === 'admin' ? '' : ' text-white') : ''; ?>
          <a href="/app?dm=<?= h(rawurlencode($d['username'])) ?>"
             data-ctx-user="<?= h($d['username']) ?>"
             data-user-id="<?= (int) ($d['id'] ?? 0) ?>"
             data-guest="<?= !empty($d['guest']) ? '1' : '0' ?>"
             class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm <?= $dmName === $d['username'] ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white' ?> <?= $dOnline ? '' : 'italic opacity-70' ?>">
            <span class="w-2 h-2 rounded-full <?= !empty($d['away']) ? 'bg-amber-400' : ($dOnline ? 'bg-green-500' : 'bg-discord-500') ?>"></span>
            <span class="truncate <?= ($d['role'] ?? '') === 'admin' ? 'text-red-400' : '' ?> <?= $unreadCls ?>"><?= h($d['username']) ?><?= !empty($d['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
            <?php if ($ucnt): ?><span class="ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center"><?= $ucnt > 99 ? '99+' : $ucnt ?></span><?php endif; ?>
            <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white ml-auto shrink-0 p-0.5" title="More"><?= icon('more-h', 'w-3.5 h-3.5') ?></button>
          </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <nav class="px-2 pt-3 pb-2">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">Online</div>
        <div id="online-section" class="mt-1 space-y-0.5">
          <?php foreach ($onlineUsers as $ou): ?>
          <a href="/app?dm=<?= h(rawurlencode($ou['username'])) ?>" data-ctx-user="<?= h($ou['username']) ?>" data-user-id="<?= (int) ($ou['id'] ?? 0) ?>" data-guest="<?= !empty($ou['guest']) ? '1' : '0' ?>" class="flex items-center gap-2 px-2 py-1 rounded-md text-xs text-discord-300 hover:bg-discord-600/40">
            <span class="w-2 h-2 rounded-full <?= presence_dot_class($ou) ?>"></span><span class="<?= ($ou['role'] ?? '') === 'admin' ? 'text-red-400' : '' ?>"><?= h($ou['username']) ?><?= !empty($ou['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
            <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white ml-auto shrink-0 p-0.5" title="More"><?= icon('more-h', 'w-3.5 h-3.5') ?></button>
          </a>
          <?php endforeach; ?>
          <?php if (!$onlineUsers): ?><div class="px-2 py-1 text-xs text-discord-500">Nobody online</div><?php endif; ?>
        </div>
      </nav>
    </div>

    <div class="border-t border-discord-700 bg-sidebar p-2 shrink-0">
      <a href="https://georgethegeek.com" target="_blank" rel="noopener" class="block text-center text-[10px] text-discord-500 hover:text-discord-300 py-1 transition-colors">Made with ❤️ in Las Vegas</a>
      <div class="flex items-center gap-2 rounded-md hover:bg-discord-600/40 px-2 py-1.5">
        <?php
        $stMode = (string) ($user['status_mode'] ?? '');
        if (!in_array($stMode, ['online', 'away', 'dnd', 'invisible', 'custom'], true)) {
            $stMode = !empty($user['away']) ? 'away' : 'online';
        }
        $stLabels = ['online' => 'Online', 'away' => 'Away', 'dnd' => 'Do Not Disturb', 'invisible' => 'Appear Offline', 'custom' => 'Custom status'];
        $stText = trim((string) ($user['custom_status'] ?? ''));
        if ($stText === '' && $stMode === 'away') {
            $stText = trim((string) ($user['away'] ?? ''));
        }
        $stDot = match ($stMode) {
            'dnd' => 'bg-red-500',
            'away', 'custom' => 'bg-amber-400',
            'invisible' => 'bg-discord-500',
            default => 'bg-green-500',
        };
        ?>
        <div id="me-header-avatar" class="relative w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white cursor-pointer" style="<?= avatar_gradient((string) $user['username']) ?>" title="Set your status">
          <?= h(strtoupper(mb_substr($user['username'], 0, 1))) ?>
          <span class="avatar-status absolute -right-0.5 -bottom-0.5 w-3 h-3 rounded-full border-2 border-sidebar <?= $stDot ?>"></span>
        </div>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-white truncate"><?= h($user['username']) ?></div>
          <button type="button" id="me-status-line" class="text-[10px] text-discord-400 truncate underline decoration-dotted underline-offset-2 cursor-pointer hover:decoration-solid block max-w-full text-left">
            <?= h($stLabels[$stMode] ?? 'Online') ?><?= $stText !== '' ? ' — ' . h(mb_substr($stText, 0, 60)) : '' ?>
          </button>
        </div>
        <div class="relative">
          <button id="user-menu-btn" class="text-discord-400 hover:text-white p-1.5 rounded-md hover:bg-discord-700/50"><?= icon('gear', 'w-4 h-4') ?></button>
          <div id="user-menu" class="hidden absolute bottom-9 right-0 w-56 card p-1.5 shadow-xl z-50">
            <a href="/u/<?= h(rawurlencode($user['username'])) ?>" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Profile & settings</a>
            <a href="/support" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Support</a>
            <div class="h-px bg-discord-700 my-1"></div>
            <div class="px-2 py-1 text-[10px] uppercase tracking-wide text-discord-500">Status</div>
            <button type="button" data-status="online" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Online</button>
            <button type="button" data-status="away" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Away</button>
            <button type="button" data-status="dnd" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Do Not Disturb</button>
            <button type="button" data-status="invisible" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Appear Offline</button>
            <button type="button" data-status="custom" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Custom status…</button>
            <?php if ($channel): ?>
            <button id="set-away-btn" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Set away</button>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="/admin" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm text-amber-400">Admin dashboard</a>
            <?php elseif ($user['role'] === 'staff'): ?>
            <a href="/admin/moderation" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm text-amber-400">Moderation</a>
            <?php endif; ?>
            <button type="button" data-embed class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Get embed code</button>
            <div class="h-px bg-discord-700 my-1"></div>
            <a href="/terms" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Terms of Service</a>
            <a href="/privacy" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Privacy Policy</a>
            <div class="h-px bg-discord-700 my-1"></div>
            <form method="post" action="/logout"><?= Csrf::field() ?><button class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm text-red-400">Log out</button></form>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Center: chat ── -->
  <section class="flex-1 flex flex-col min-w-0 min-h-0 bg-discord-750">
    <header class="min-h-12 pl-2 pr-4 border-b border-discord-800 bg-discord-750 flex items-center gap-3 shadow-sm shrink-0">
      <button id="sidebar-toggle" class="btn-ghost !p-1.5" title="Toggle channel list" aria-label="Toggle channel list"><?= icon('menu', 'w-[18px] h-[18px]') ?></button>
      <?php if ($channel): ?>
      <span class="font-bold text-white text-sm flex items-center gap-1.5"><?= icon('hash', 'w-4 h-4 text-discord-400') ?><?= h($channel['name']) ?></span>
      <span id="header-topic" class="text-xs text-discord-400 truncate max-w-md hidden sm:block"><?= $channel['topic'] !== '' ? chat_markup_plain($channel['topic']) : '' ?></span>
      <div class="relative ml-auto flex items-center gap-1">
        <input id="search-input" type="search" placeholder="Search chat… (Ctrl+K)" autocomplete="off"
               class="input w-40 md:w-56 !py-1 !text-xs hidden md:block" title="Search messages in your channels and DMs">
        <div id="search-results" class="hidden absolute top-10 right-0 w-96 max-w-[calc(100vw-2rem)] card shadow-2xl z-50 max-h-[60vh] overflow-y-auto scrollbar-thin"></div>
        <button id="share-btn" class="btn-ghost text-xs hidden md:flex"><?= icon('link', 'w-3.5 h-3.5') ?> <span class="hidden xl:inline">Share</span></button>
        <?php if ($canManageSettings): ?>
        <button id="chan-settings-btn" class="btn-ghost text-xs hidden md:flex"><?= icon('gear', 'w-3.5 h-3.5') ?> <span class="hidden xl:inline">Settings</span></button>
        <?php endif; ?>
        <button id="mute-btn" class="btn-ghost text-xs hidden md:flex" data-mode="<?= h($notifyMode) ?>" title="<?= h($notifyMode === 'muted' ? 'Unmute channel' : ($notifyMode === 'mentions' ? 'Notification mode: mentions only' : 'Mute channel')) ?>"><?= $notifyMode === 'muted' ? icon('bell-off', 'w-3.5 h-3.5') : ($notifyMode === 'mentions' ? icon('bell-ring', 'w-3.5 h-3.5') : icon('bell', 'w-3.5 h-3.5')) ?></button>
        <button type="button" data-embed class="btn-ghost text-xs hidden md:flex" title="Get HTML embed code for this channel"><?= icon('code', 'w-3.5 h-3.5') ?></button>
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex" title="Install the app on your computer or phone"><?= icon('download', 'w-3.5 h-3.5') ?></button>
        <button id="part-btn" class="btn-ghost text-xs text-red-400 hidden md:flex" title="Leave channel"><?= icon('log-out', 'w-3.5 h-3.5') ?></button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel" aria-label="Toggle friends and members panel"><?= icon('users', 'w-3.5 h-3.5') ?></button>
        <?php require ROOT . '/views/partials/header-menu.php'; ?>
      </div>
      <?php elseif ($dm): ?>
      <span class="font-bold text-white text-sm"><?= h($dm['username']) ?></span>
      <span id="dm-header-status" class="flex items-center gap-1.5 text-xs text-discord-400">
        <span class="w-2 h-2 rounded-full <?= presence_dot_class($dm) ?>"></span>
        <?= h(presence_label($dm)) ?><?php $dmSt = presence_status_text($dm); if ($dmSt !== ''): ?> — <span class="truncate max-w-[24ch]"><?= h($dmSt) ?></span><?php endif; ?>
      </span>
      <div class="relative ml-auto flex items-center gap-1">
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex" title="Install the app on your computer or phone"><?= icon('download', 'w-3.5 h-3.5') ?></button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel" aria-label="Toggle friends and members panel"><?= icon('users', 'w-3.5 h-3.5') ?></button>
        <?php require ROOT . '/views/partials/header-menu.php'; ?>
      </div>
      <?php else: ?>
      <span class="font-bold text-white text-sm"><?= h($site) ?></span>
      <span class="text-xs text-discord-400 truncate">You are not in any channel. Create one or browse the list.</span>
      <div class="relative ml-auto flex items-center gap-1">
        <button id="browse-btn-header" class="btn-primary text-xs hidden md:flex cursor-pointer"><?= icon('globe', 'w-3.5 h-3.5') ?> Browse channels</button>
        <button id="create-channel-2" class="btn-ghost text-xs hidden md:flex"><?= icon('plus', 'w-3.5 h-3.5') ?> New channel</button>
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex"><?= icon('download', 'w-3.5 h-3.5') ?></button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel" aria-label="Toggle friends and members panel"><?= icon('users', 'w-3.5 h-3.5') ?></button>
        <?php require ROOT . '/views/partials/header-menu.php'; ?>
      </div>
      <?php endif; ?>
    </header>

    <?php if (($user['status'] ?? 'active') === 'pending'): ?>
    <div class="px-4 py-2 text-xs text-amber-300 bg-amber-500/10 border-b border-amber-500/30">
      ⏳ Your account is <strong>pending admin approval</strong> — you can browse channels, but you cannot chat until an admin approves it.
    </div>
    <?php endif; ?>

    <?php if ($channel && !ChannelService::isRegistered($channel)): ?>
    <div id="unregistered-banner" class="flex items-center gap-2 px-4 py-2 text-xs font-medium <?= (int) $channel['owner_id'] === (int) $user['id'] ? 'text-amber-950 bg-amber-300 border-b border-amber-400' : 'text-discord-950 bg-discord-300 border-b border-discord-400' ?>">
      <span class="min-w-0 flex-1">
        <?php if ((int) $channel['owner_id'] === (int) $user['id']): ?>
        ⚠ This channel is <strong>not registered</strong> — it will disappear when the last person leaves. You are the founder: type <code class="bg-amber-950/15 px-1 rounded">/register <?= h($channel['name']) ?></code> to keep it.
        <?php else: ?>
        This is a temporary channel (not registered) — it will disappear when the last person leaves.
        <?php endif; ?>
      </span>
      <button type="button" id="unregistered-dismiss" class="shrink-0 text-sm leading-none px-1.5 py-0.5 rounded opacity-70 hover:opacity-100 hover:bg-black/10" title="Dismiss"><?= icon('x', 'w-4 h-4') ?></button>
    </div>
    <script>
    (function () {
      var b = document.getElementById('unregistered-banner');
      var x = document.getElementById('unregistered-dismiss');
      if (b && x) x.addEventListener('click', function () { b.style.display = 'none'; });
    })();
    </script>
    <?php endif; ?>

    <?php if ($channel): ?>
    <div class="hidden md:flex px-3 py-1.5 border-b border-discord-700 bg-discord-850 items-center gap-1.5 flex-wrap shrink-0">
      <span class="text-[10px] font-bold uppercase tracking-wide text-discord-400 mr-1">Modes</span>
      <?php foreach ($modeDefs as $flag => $def): ?>
      <button type="button"
              class="mode-toggle px-2 py-0.5 rounded text-xs border transition-colors <?= $modeState[$flag] ? 'bg-blurple/20 border-blurple/40 text-white' : 'bg-discord-800 border-discord-700 text-discord-400 hover:text-discord-200' ?> <?= $canManageModes ? 'cursor-pointer' : 'cursor-not-allowed opacity-70' ?>"
              data-mode="<?= h($flag) ?>"
              data-short="<?= h($def['short']) ?>"
              data-channel="<?= h($channel['slug']) ?>"
              title="<?= h($def['title']) . ($canManageModes ? '' : ' (requires operator)') ?>"
              <?= $canManageModes ? '' : 'disabled' ?>>
        <?= $modeState[$flag] ? '+' : '-' ?><?= h($flag) ?> <?= h($def['short']) ?>
      </button>
      <?php endforeach; ?>
      <?php if (!$canManageModes): ?><span class="text-[10px] text-discord-500 ml-1">operators can change these</span><?php endif; ?>
      <span class="ml-auto hidden md:inline text-[10px] text-discord-500">hover a flag for details · <a class="text-blurple hover:underline" href="/help-mode" data-help-mode="<?= h($channel['name']) ?>" onclick="return false;">/mode help</a></span>
    </div>
    <?php endif; ?>

    <div id="messages" class="theme-bg-image flex-1 min-h-0 overflow-y-auto scrollbar-thin"
         data-last-msg="<?= h(json_encode($lastMsg ?? null)) ?>">
      <button id="load-earlier" class="hidden w-full py-2 text-xs text-blurple hover:text-white hover:bg-blurple/10 transition-colors">↑ Load earlier messages</button>
      <?php if ($motd): ?>
      <div class="msg-system px-4 py-3 text-xs text-discord-400 whitespace-pre-wrap break-words"><?= h($motd) ?></div>
      <?php endif; ?>
      <?php
      $prev = null;
      $prevDate = null;
      foreach ($messages as $m):
          $date = substr((string) $m['created_at'], 0, 10);
          if ($date !== $prevDate):
      ?>
      <div class="divider flex items-center gap-4 px-4 py-3 select-none">
        <div class="h-px flex-1 bg-discord-800"></div>
        <span class="text-xs font-semibold text-discord-400 uppercase tracking-wide"><?= h(chat_divider_label($date)) ?></span>
        <div class="h-px flex-1 bg-discord-800"></div>
      </div>
      <?php
          $prevDate = $date;
          $prev = null;
          endif;
          echo msg_html($m, $prev, $user);
          $prev = $m;
      endforeach;
      ?>
      <?php if (!$messages && !$motd): ?>
      <div class="h-full flex flex-col items-center justify-center text-center px-6">
        <div class="w-14 h-14 rounded-2xl bg-discord-800 border border-discord-700 flex items-center justify-center mb-4"><?= icon('message-circle', 'w-6 h-6 text-blurple') ?></div>
        <div class="text-discord-200 font-semibold"><?= $channel ? 'Welcome to ' . h($channel['name']) : ($dm ? 'Say hi to ' . h($dm['username']) : 'Welcome to ' . h($site)) ?></div>
        <p class="text-sm text-discord-500 mt-1 max-w-sm"><?= $channel && $channel['topic'] !== '' ? h(mb_substr($channel['topic'], 0, 140)) : 'No messages yet — say hello and start the conversation.' ?></p>
        <button id="empty-compose-tip" class="hidden mt-5 btn-ghost text-xs"><?= icon('keyboard', 'w-3.5 h-3.5') ?> Start typing</button>
      </div>
      <?php endif; ?>
    </div>

    <div id="pinned-bar" class="hidden items-center gap-2 px-4 py-1.5 border-t border-discord-700 bg-discord-850/80 text-xs shrink-0 relative">
      <button id="pinned-bar-btn" class="flex items-center gap-2 text-discord-300 hover:text-white font-medium"><?= icon('pin', 'w-3.5 h-3.5 text-blurple') ?> <span id="pinned-count">0</span> <span id="pinned-label">pinned</span> <span class="text-discord-500 font-normal">·</span> <span id="pinned-latest" class="text-discord-400 truncate max-w-[30ch] text-[11px]"></span></button>
      <div id="pins-pop" class="hidden absolute bottom-full left-3 mb-1 w-96 max-w-[calc(100vw-2rem)] card shadow-2xl z-40 max-h-80 overflow-y-auto scrollbar-thin p-1.5"></div>
    </div>

    <div id="typing-ind" class="hidden min-h-[22px] flex items-center gap-1.5 px-4 py-0.5 text-xs text-discord-400 shrink-0 select-none">
      <span class="inline-flex gap-0.5 items-end h-3.5">
        <span class="typing-dot w-1 h-1 rounded-full bg-discord-400"></span>
        <span class="typing-dot w-1 h-1 rounded-full bg-discord-400"></span>
        <span class="typing-dot w-1 h-1 rounded-full bg-discord-400"></span>
      </span>
      <span id="typing-label"></span>
    </div>

    <div id="composer" class="px-4 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))] shrink-0 bg-discord-750">
      <div id="autocomplete" class="hidden mb-2 card max-h-56 overflow-y-auto scrollbar-thin"></div>
      <div id="emoji-panel" class="hidden mb-2 card p-2 grid grid-cols-8 gap-1 max-h-56 overflow-y-auto scrollbar-thin"></div>
      <div id="gif-panel" class="hidden mb-2 card max-h-96 flex flex-col">
        <div class="flex items-center gap-2 px-3 pt-2.5 pb-2 border-b border-discord-700 shrink-0">
          <span class="text-xs font-bold uppercase tracking-wide text-discord-400">GIF</span>
          <input id="gif-search" type="search" placeholder="Search Giphy…" autocomplete="off" class="input flex-1 !py-1.5 !text-xs">
          <button type="button" id="gif-close" class="text-discord-400 hover:text-white p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
        </div>
        <div id="gif-grid" class="grid grid-cols-4 gap-1.5 overflow-y-auto scrollbar-thin p-2"></div>
        <div id="gif-status" class="hidden px-3 py-2 text-xs text-discord-400 border-t border-discord-700 shrink-0"></div>
        <button type="button" id="gif-more" class="hidden py-2 text-xs text-blurple hover:text-white hover:bg-blurple/10 transition-colors shrink-0">Load more GIFs</button>
      </div>
      <div id="reply-chip" class="hidden mb-2 flex items-center gap-2 card px-3 py-1.5 text-sm text-discord-300">
        <span class="text-xs text-blurple font-semibold"><?= icon('reply', 'w-3.5 h-3.5') ?> Replying to</span>
        <span id="reply-chip-name" class="font-semibold text-white truncate"></span>
        <span id="reply-chip-excerpt" class="truncate text-discord-400"></span>
        <button type="button" id="reply-cancel" class="ml-auto text-discord-400 hover:text-white p-1" title="Cancel reply"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <form id="send-form" method="post" action="/api/send">
        <?= Csrf::field() ?>
        <?php if ($dm): ?>
        <input type="hidden" name="recipient" value="<?= h($dm['username']) ?>">
        <?php elseif ($channel): ?>
        <input type="hidden" name="channel" value="<?= h($channel['slug']) ?>">
        <?php endif; ?>
        <input type="hidden" id="reply-to-input" name="reply_to" value="">
        <div id="attach-preview" class="hidden mb-1.5 flex items-center gap-2"></div>
        <div class="flex items-end gap-1 rounded-xl border border-discord-600 bg-discord-800 px-1.5 py-1 transition-colors focus-within:border-blurple/60 focus-within:ring-1 focus-within:ring-blurple/30 shadow-sm">
          <textarea id="chat-input" name="content" rows="1" autocomplete="off" spellcheck="false"
                 aria-label="Message input"
                 class="flex-1 min-w-0 bg-transparent border-0 focus:ring-0 focus:outline-none resize-none max-h-40 py-1.5 px-1.5 text-sm text-discord-100 placeholder:text-discord-400"
                 placeholder="<?= h($channel ? "Message " . $channel['name'] : ($dm ? 'Message ' . $dm['username'] : 'Join a channel to chat')) ?>"
                 <?= ($channel || $dm) ? '' : 'disabled' ?>></textarea>
          <div class="flex items-center gap-0.5 shrink-0">
            <button type="button" id="upload-btn" class="btn-ghost !p-1.5 !rounded-lg <?= ($channel || $dm) ? 'inline-flex' : 'hidden' ?>" title="Upload an image"><?= icon('paperclip', 'w-4 h-4') ?></button>
            <input type="file" id="upload-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
            <button type="button" id="emoji-btn" class="btn-ghost !p-1.5 !rounded-lg <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Emoji"><?= icon('smile', 'w-4 h-4') ?></button>
            <button type="button" id="gif-btn" class="btn-ghost !p-1.5 !rounded-lg text-xs font-bold <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Send a GIF">GIF</button>
            <button type="submit" id="send-btn" class="btn-primary !p-2 !rounded-lg" title="Send"><?= icon('send', 'w-4 h-4') ?></button>
          </div>
        </div>
        <div class="text-[11px] text-discord-400 mt-1.5 px-1">Type <span class="text-discord-200">/</span> for commands · <span class="text-discord-200">@nick</span> to mention · <span class="text-discord-200">Enter</span> to send · <span class="text-discord-200">Shift+Enter</span> newline · <span class="text-discord-200">**bold**</span> <span class="text-discord-200">*italic*</span> <span class="text-discord-200">```code```</span></div>
      </form>
    </div>
  </section>

  <!-- ── Mobile right panel backdrop ── -->
  <div id="right-panel-backdrop" class="hidden fixed inset-0 z-[55] bg-black/60 md:hidden"></div>

  <!-- ── Right: friends + member list ── -->
  <aside id="right-panel" class="right-panel hidden md:flex w-60 bg-sidebar flex-col shrink-0 min-h-0">
    <?php if ((int) ($user['guest'] ?? 0) !== 1): ?>
    <?php
    $onlineFriends = array_values(array_filter($friends, fn($f) => !empty($f['is_online'])));
    $offlineFriends = array_values(array_filter($friends, fn($f) => empty($f['is_online'])));
    ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">
      <button type="button" id="friends-toggle" class="flex items-center gap-1 hover:text-white cursor-pointer">
        <span id="friends-arrow" class="text-[10px]">▾</span>
        <span>Friends — <span id="friend-count"><?= (int) count($friends) ?></span></span>
      </button>
      <?php if (!empty($friendRequests)): ?>
      <span id="friend-badge" class="min-w-5 h-5 px-1 rounded-full bg-blurple text-white text-[10px] flex items-center justify-center"><?= count($friendRequests) ?></span>
      <?php endif; ?>
    </div>
    <div id="friends-section" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php if (!empty($friendRequests)): ?>
      <div class="px-2 pt-2 pb-1">
        <div class="px-2 text-xs font-semibold text-blurple uppercase tracking-wide mb-1">Requests — <?= count($friendRequests) ?></div>
        <?php foreach ($friendRequests as $fr): ?>
        <div class="friend-request flex items-center gap-2 px-2 py-1.5 rounded hover:bg-discord-600/40 text-sm" data-username="<?= h($fr['username']) ?>">
          <?= avatar_img(['username' => $fr['username'], 'avatar' => $fr['avatar'] ?? null, 'guest' => 0], 'w-6 h-6 rounded-full') ?>
          <span class="truncate text-discord-200"><?= h($fr['username']) ?></span>
          <div class="ml-auto flex gap-1">
            <button type="button" class="friend-accept text-[10px] px-1.5 py-0.5 rounded bg-green-600 hover:bg-green-500 text-white">Accept</button>
            <button type="button" class="friend-decline text-[10px] px-1.5 py-0.5 rounded bg-discord-700 hover:bg-discord-600 text-discord-300">Decline</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($onlineFriends)): ?>
      <div class="px-2 pt-2 pb-1">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Online — <?= count($onlineFriends) ?></div>
        <?php foreach ($onlineFriends as $f): ?>
        <a href="/app?dm=<?= h(rawurlencode($f['username'])) ?>" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-200" data-ctx-user="<?= h($f['username']) ?>" data-user-id="<?= (int) $f['id'] ?>" data-friend="1">
          <span class="w-2 h-2 rounded-full <?= $f['away'] ? 'bg-amber-400' : 'bg-green-500' ?>"></span>
          <?= avatar_img(['username' => $f['username'], 'avatar' => $f['avatar'] ?? null, 'guest' => 0], 'w-6 h-6 rounded-full') ?>
          <span class="truncate"><?= h($f['username']) ?></span>
          <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white ml-auto shrink-0 p-0.5" title="More"><?= icon('more-h', 'w-3.5 h-3.5') ?></button>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($offlineFriends)): ?>
      <div class="px-2 pt-2 pb-1">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Offline — <?= count($offlineFriends) ?></div>
        <?php foreach ($offlineFriends as $f): ?>
        <a href="/app?dm=<?= h(rawurlencode($f['username'])) ?>" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-400 italic opacity-70" data-ctx-user="<?= h($f['username']) ?>" data-user-id="<?= (int) $f['id'] ?>" data-friend="1">
          <span class="w-2 h-2 rounded-full bg-discord-500"></span>
          <?= avatar_img(['username' => $f['username'], 'avatar' => $f['avatar'] ?? null, 'guest' => 0], 'w-6 h-6 rounded-full') ?>
          <span class="truncate"><?= h($f['username']) ?></span>
          <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white ml-auto shrink-0 p-0.5" title="More"><?= icon('more-h', 'w-3.5 h-3.5') ?></button>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!$friends && !$friendRequests): ?>
      <div class="p-4 text-xs text-discord-500">No friends yet.</div>
      <?php endif; ?>
    </div>
    <div class="border-t border-discord-700 shrink-0"></div>
    <?php endif; ?>
    <?php if ((int) ($user['guest'] ?? 0) !== 1): ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">
      <button type="button" id="channel-invites-toggle" class="flex items-center gap-1 hover:text-white cursor-pointer">
        <span id="channel-invites-arrow" class="text-[10px]">▸</span>
        <span>Channel Invites — <span id="channel-invites-count"><?= count($channelInvites) ?></span></span>
      </button>
    </div>
    <div id="channel-invites-section" class="hidden flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php if (!empty($channelInvites)): ?>
      <div class="px-2 pt-2 pb-1">
        <?php foreach ($channelInvites as $ci): ?>
        <div class="channel-invite flex items-center gap-2 px-2 py-1.5 rounded hover:bg-discord-600/40 text-sm" data-channel="<?= h($ci['slug']) ?>">
          <span class="truncate text-discord-200">#<?= h($ci['channel_name']) ?></span>
          <span class="text-xs text-discord-500 truncate">by <?= h($ci['inviter'] ?? 'unknown') ?></span>
          <div class="ml-auto flex gap-1">
            <button type="button" class="channel-invite-accept text-[10px] px-1.5 py-0.5 rounded bg-green-600 hover:bg-green-500 text-white">Accept</button>
            <button type="button" class="channel-invite-decline text-[10px] px-1.5 py-0.5 rounded bg-discord-700 hover:bg-discord-600 text-discord-300">Reject</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="p-4 text-xs text-discord-500">No pending invites.</div>
      <?php endif; ?>
    </div>
    <div class="border-t border-discord-700 shrink-0"></div>
    <?php endif; ?>
    <?php if ($channel): ?>
    <?php
    // Group by user type. Offline guests are skipped entirely (anonymous).
    $typeGroups = ['Admins' => [], 'Staff' => [], 'Helpers' => [], 'Registered' => [], 'Guests' => []];
    foreach ($members as $m) {
        if ($m['role'] === 'admin') {
            $typeGroups['Admins'][] = $m;
        } elseif ($m['role'] === 'staff') {
            $typeGroups['Staff'][] = $m;
        } elseif (!empty($m['role_helper'])) {
            $typeGroups['Helpers'][] = $m;
        } elseif (!empty($m['guest'])) {
            if (!empty($m['is_online'])) {
                $typeGroups['Guests'][] = $m;
            }
        } else {
            $typeGroups['Registered'][] = $m;
        }
    }
    $memberCount = array_sum(array_map('count', $typeGroups));
    ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">
      <button type="button" id="members-toggle" class="flex items-center gap-1 hover:text-white cursor-pointer">
        <span id="members-arrow" class="text-[10px]">▾</span>
        <span>Members — <span id="member-count"><?= (int) $memberCount ?></span></span>
      </button>
    </div>
    <div id="member-list" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php foreach ($typeGroups as $label => $list): if (!$list) continue; ?>
      <div class="px-2 <?= $label === 'Admins' ? 'pt-2' : 'pt-3' ?> pb-2">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1"><?= h($label) ?> — <?= count($list) ?></div>
        <?php foreach ($list as $m): ?><?= member_html($m, !empty($m['is_online'])) ?><?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">
      <button type="button" id="members-toggle" class="flex items-center gap-1 hover:text-white cursor-pointer">
        <span id="members-arrow" class="text-[10px]">▾</span>
        <span>Members</span>
      </button>
    </div>
    <div id="member-list" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <div class="p-4 text-xs text-discord-500">Select a channel to see its members.</div>
    </div>
    <?php endif; ?>
  </aside>

  <!-- Password-protected join modal -->
  <?php if (!empty($joinModal)): ?>
  <div id="join-modal" class="fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70"></div>
    <div class="relative card p-6 w-[min(92vw,420px)] shadow-2xl">
      <h2 class="text-lg font-bold text-white">Join <?= h($joinModal['name']) ?></h2>
      <p class="text-xs text-discord-400 mt-1 mb-4">This channel is protected by a password.</p>
      <form id="join-form" method="post" action="/c/<?= h(rawurlencode($joinModal['slug'])) ?>/join" class="space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="channel" value="<?= h($joinModal['slug']) ?>">
        <input id="join-key" type="password" name="key" class="input" placeholder="Channel password" required autofocus>
        <div id="join-error" class="hidden text-xs text-red-400"></div>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary flex-1 justify-center">Enter channel</button>
          <a href="/app" class="btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    var f = document.getElementById('join-form');
    if (!f) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData();
      fd.append('csrf', <?= json_encode($csrf) ?>);
      fd.append('ajax', '1');
      fd.append('name', f.querySelector('input[name=channel]').value);
      fd.append('key', document.getElementById('join-key').value);
      fetch('/api/join', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.redirect) { window.location = j.redirect; return; }
          var el = document.getElementById('join-error');
          el.textContent = j.error || 'Unable to join.';
          el.classList.remove('hidden');
        })
        .catch(function () {
          // Fall back to a native form submit (no-JS / network error).
          f.submit();
        });
    });
  })();
  </script>
  <?php endif; ?>

  <!-- Channel browser modal -->
  <div id="browse-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" data-browse-close></div>
    <div class="relative bg-discord-800 rounded-xl browse-glow browse-modal-enter w-[min(96vw,920px)] flex flex-col overflow-hidden" style="max-height:88vh">
      <div class="browse-gradient-accent shrink-0"></div>
      <div class="px-6 pt-5 pb-4 flex items-start justify-between shrink-0">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Channel Browser</h2>
          <p class="text-sm text-discord-400 mt-1">Discover and join public channels on <?= h($site) ?></p>
        </div>
        <button type="button" data-browse-close class="text-discord-400 hover:text-white text-lg leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-discord-700/80 transition-colors mt-0.5" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <div class="px-6 pb-4 shrink-0">
        <div class="flex flex-wrap gap-3 items-stretch">
          <div class="browse-stat-card browse-stat-green rounded-lg px-4 py-3 flex items-center gap-3 min-w-[130px]">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500 pulse-dot shadow-lg shadow-green-500/50 shrink-0"></span>
            <div>
              <div class="text-xl font-bold text-white leading-tight" id="browse-online">0</div>
              <div class="text-[11px] text-discord-400 font-medium uppercase tracking-wide">Online</div>
            </div>
          </div>
          <div class="browse-stat-card browse-stat-amber rounded-lg px-4 py-3 flex items-center gap-3 min-w-[130px]">
            <span class="w-2.5 h-2.5 rounded-full bg-amber-400 shadow-lg shadow-amber-400/50 shrink-0"></span>
            <div>
              <div class="text-xl font-bold text-white leading-tight" id="browse-peak">0</div>
              <div class="text-[11px] text-discord-400 font-medium uppercase tracking-wide">Peak</div>
            </div>
          </div>
          <div class="ml-auto flex gap-2 items-center">
            <input id="browse-search" class="input w-56 !py-2 !rounded-lg text-sm" placeholder="Search channels…" autocomplete="off">
            <select id="browse-filter" class="input w-36 !py-2 !rounded-lg text-sm">
              <option value="all">All channels</option>
              <option value="open">Not joined</option>
              <option value="joined">Joined</option>
            </select>
          </div>
        </div>
      </div>
      <div class="h-px bg-discord-700/60 shrink-0"></div>
      <div class="flex-1 min-h-0 overflow-y-auto scrollbar-thin px-6 py-5 space-y-6">
        <div id="browse-my-section" class="hidden">
          <div class="flex items-center gap-3 mb-3">
            <div class="h-px flex-1 bg-blurple/30"></div>
            <span class="text-xs font-bold uppercase tracking-widest text-blurple">My Channels</span>
            <div class="h-px flex-1 bg-blurple/30"></div>
          </div>
          <div id="browse-my-list" class="space-y-2"></div>
        </div>
        <div>
          <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-widest text-discord-400">All Public Channels</span>
            <span id="browse-count" class="text-xs text-discord-500 font-medium"></span>
          </div>
          <div id="browse-list" class="space-y-2"></div>
          <div id="browse-empty" class="hidden py-16 text-center">
            <div class="mx-auto mb-3 w-12 h-12 rounded-2xl bg-discord-800 border border-discord-700 flex items-center justify-center text-discord-500"><?= icon('search', 'w-5 h-5') ?></div>
            <div class="text-discord-400 text-sm font-medium">No channels found</div>
            <div class="text-discord-500 text-xs mt-1">Try a different search or filter</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick switcher (Ctrl+K / ⌘K) -->
  <div id="switcher-modal" class="hidden fixed inset-0 z-[350] flex items-start justify-center pt-[14vh] p-4">
    <div class="absolute inset-0 bg-black/70" data-switcher-close></div>
    <div class="relative card shadow-2xl w-[min(94vw,560px)] overflow-hidden modal-card">
      <div class="flex items-center gap-2.5 px-4 py-3 border-b border-discord-700 bg-discord-850">
        <span class="text-discord-400"><?= icon('search', 'w-4 h-4') ?></span>
        <input id="switcher-input" type="text" placeholder="Jump to a channel, DM or message…" autocomplete="off" spellcheck="false" class="flex-1 bg-transparent border-0 focus:ring-0 focus:outline-none text-sm text-white placeholder:text-discord-400">
        <kbd class="text-[10px] font-semibold text-discord-400 border border-discord-600 rounded px-1.5 py-0.5">ESC</kbd>
      </div>
      <div id="switcher-list" class="max-h-[50vh] overflow-y-auto scrollbar-thin p-1.5 space-y-0.5"></div>
    </div>
  </div>

  <!-- Create channel modal -->
  <div id="create-channel-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-create-close></div>
    <div class="relative card p-6 w-[min(92vw,480px)] shadow-2xl max-h-[90vh] overflow-y-auto scrollbar-thin">
      <div class="flex items-center justify-between mb-1">
        <h2 class="text-lg font-bold text-white">Create a channel</h2>
        <button type="button" data-create-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <p class="text-xs text-discord-400 mb-4">Give your channel a name, set the topic, and choose who can find it.</p>
      <form id="create-form" class="space-y-4">
        <div>
          <label for="create-name" class="block text-xs font-bold uppercase tracking-wide text-discord-400 mb-1">Channel name</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-blurple font-bold select-none" aria-hidden="true">#</span>
            <input id="create-name" type="text" maxlength="64" autocomplete="off" spellcheck="false" required
                   class="input !pl-8" placeholder="e.g. gaming">
          </div>
          <p class="text-[11px] text-discord-500 mt-1">No <span class="text-discord-300">#</span> needed — it's added for you.</p>
        </div>
        <div>
          <label for="create-topic" class="block text-xs font-bold uppercase tracking-wide text-discord-400 mb-1">Topic <span class="font-normal normal-case">(optional)</span></label>
          <input id="create-topic" type="text" maxlength="500" autocomplete="off" spellcheck="false"
                 class="input" placeholder="What is this channel about?">
        </div>
        <div class="space-y-2.5">
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400">Privacy</div>
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="radio" name="visibility" value="public" checked class="mt-0.5 accent-blurple">
            <span class="text-sm text-discord-200">
              <span class="font-medium text-white">Public</span>
              <span class="block text-[11px] text-discord-500">Visible in the channel browser — anyone can join.</span>
            </span>
          </label>
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="radio" name="visibility" value="private" class="mt-0.5 accent-blurple">
            <span class="text-sm text-discord-200">
              <span class="font-medium text-white">Private</span>
              <span class="block text-[11px] text-discord-500">Hidden from the browser; join via the share link.</span>
            </span>
          </label>
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="radio" name="visibility" value="secret" class="mt-0.5 accent-blurple">
            <span class="text-sm text-discord-200">
              <span class="font-medium text-white">Secret</span>
              <span class="block text-[11px] text-discord-500">Hidden entirely; members must be invited.</span>
            </span>
          </label>
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" id="create-invite" class="mt-0.5 accent-blurple">
            <span class="text-sm text-discord-200">
              <span class="font-medium text-white">Invite only</span>
              <span class="block text-[11px] text-discord-500">Only people you invite can join.</span>
            </span>
          </label>
        </div>
        <label class="flex items-start gap-2.5 cursor-pointer">
          <input type="checkbox" id="create-register" checked class="mt-0.5 accent-blurple">
          <span class="text-sm text-discord-200">
            <span class="font-medium text-white">Register this channel to me</span>
            <span class="block text-[11px] text-discord-500">Keeps the channel even when it's empty; you become the founder.</span>
          </span>
        </label>
        <div id="create-error" class="hidden text-xs text-red-400"></div>
        <div class="flex gap-2 pt-1">
          <button type="submit" id="create-submit" class="btn-primary flex-1 justify-center">Create channel</button>
          <button type="button" data-create-close class="btn-ghost">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Notifications panel -->
  <div id="notif-panel" class="hidden fixed w-96 max-w-[calc(100vw-2rem)] card shadow-2xl z-50 flex flex-col" style="max-height:70vh" data-pos="auto">
    <div class="px-3 py-2 border-b border-discord-700 text-sm font-semibold flex items-center justify-between shrink-0">
      <span>Notifications</span>
      <button id="notif-clear" class="text-xs text-discord-400 hover:text-white cursor-pointer">Mark all read</button>
    </div>
    <div id="push-row" class="hidden px-3 py-2 border-b border-discord-700 flex items-center justify-between gap-2 text-xs shrink-0">
      <span class="text-discord-300 inline-flex items-center gap-1.5"><?= icon('bell', 'w-3.5 h-3.5') ?> Browser push</span>
      <button id="push-enable" class="btn-ghost !py-1 !text-xs">Enable</button>
    </div>
    <div id="notif-list" class="flex-1 overflow-y-auto scrollbar-thin p-1.5 text-sm min-h-0"></div>
  </div>

  <!-- Mobile sidebar backdrop -->
  <div id="sidebar-backdrop" class="hidden fixed inset-0 z-[55] bg-black/60 md:hidden"></div>

  <!-- Right-click context menu -->
  <div id="ctx-menu" class="hidden fixed z-[100] min-w-52 max-w-72 card p-1.5 shadow-2xl text-sm"></div>

  <!-- Report message modal -->
  <div id="report-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-report-close></div>
    <div class="relative card p-6 w-[min(92vw,480px)] shadow-2xl">
      <div class="flex items-center justify-between mb-1">
        <h2 class="text-lg font-bold text-white">Report message</h2>
        <button type="button" data-report-close class="text-discord-400 hover:text-white text-lg leading-none p-1"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <p class="text-xs text-discord-400 mb-4">This report goes to the staff. The message content is included.</p>
      <div class="mb-3 rounded-lg bg-discord-850 border border-discord-700 px-3 py-2 text-sm text-discord-300 max-h-72 overflow-y-auto scrollbar-thin">
        <span class="text-[10px] uppercase tracking-wide text-discord-400 font-semibold block mb-0.5"><?= h($user['username']) ?> · quoted message</span>
        <div id="report-quote" class="break-words"></div>
      </div>
      <div class="space-y-2 text-sm" id="report-reasons">
        <?php foreach (['Harassment / Bullying', 'Spam or advertising', 'Hate speech', 'Inappropriate / NSFW content', 'Threatening violence', 'Personal information (doxxing)', 'Other'] as $i => $opt): ?>
        <label class="flex items-center gap-2 text-discord-200 cursor-pointer">
          <input type="radio" name="report_reason" value="<?= h($opt) ?>" class="w-4 h-4 accent-blurple" <?= $i === 0 ? 'checked' : '' ?>>
          <?= h($opt) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <textarea id="report-other" class="input mt-3 hidden" rows="3" maxlength="500" placeholder="Tell us what happened…"></textarea>
      <div id="report-error" class="hidden mt-2 text-xs text-red-400"></div>
      <div class="flex gap-2 mt-4">
        <button id="report-submit" class="btn-primary flex-1 justify-center">Submit report</button>
        <button data-report-close class="btn-ghost">Cancel</button>
      </div>
    </div>
  </div>

  <!-- Guest profile modal -->
  <div id="guest-profile-modal" class="hidden fixed inset-0 z-[400] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-guest-modal-close></div>
    <div class="relative card p-6 w-[min(92vw,400px)] shadow-2xl text-center">
      <button type="button" data-guest-modal-close class="absolute top-2 right-3 text-discord-400 hover:text-white text-lg leading-none p-1"><?= icon('x', 'w-4 h-4') ?></button>
      <div class="mx-auto w-16 h-16 rounded-full bg-discord-600 flex items-center justify-center text-2xl font-bold text-white mb-3" id="guest-profile-avatar"></div>
      <h2 class="text-lg font-bold text-white" id="guest-profile-name"></h2>
      <p class="text-sm text-discord-400 mt-2">Profile does not exist</p>
      <p class="text-xs text-discord-500 mt-1">Guest users do not have profiles.</p>
      <button data-guest-modal-close class="btn-ghost mt-4 justify-center w-full">Close</button>
    </div>
  </div>

  <!-- Channel background modal (channel owner sets the chat background) -->
  <div id="chan-bg-modal" class="hidden fixed inset-0 z-[400] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-chan-bg-close></div>
    <div class="relative card p-6 w-[min(92vw,440px)] shadow-2xl max-h-[85vh] overflow-y-auto scrollbar-thin">
      <button type="button" data-chan-bg-close class="absolute top-2 right-3 text-discord-400 hover:text-white text-lg leading-none p-1"><?= icon('x', 'w-4 h-4') ?></button>
      <h2 class="text-lg font-bold text-white">Channel background</h2>
      <p class="text-xs text-discord-400 mt-1 mb-4">Everyone viewing this channel sees this behind the message list.</p>
      <div class="space-y-4">
        <div>
          <label class="label">Background colour <button type="button" id="chan-bg-color-clear" class="text-[10px] text-blurple hover:underline ml-1">none</button></label>
          <input type="color" id="chan-bg-color" value="#2b2d31" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
        </div>
        <div>
          <label class="label">Background image</label>
          <label class="btn-ghost !py-1.5 text-xs cursor-pointer">Upload image<input type="file" id="chan-bg-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"></label>
          <div id="chan-bg-current" class="hidden mt-2"></div>
          <p class="text-xs text-discord-400 mt-1">PNG/JPG/WebP/GIF up to 5&nbsp;MB. Best as a wide, muted image.</p>
        </div>
        <div>
          <label class="label">Image fit</label>
          <select id="chan-bg-fit" class="input !py-1.5">
            <?php foreach (ThemeService::CHAT_BG_FITS as $f): ?>
            <option value="<?= h($f) ?>" <?= ($channel['bg_fit'] ?? 'contain') === $f ? 'selected' : '' ?>><?= h(ucfirst($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Overlay opacity <span id="chan-bg-overlay-label" class="text-discord-400 normal-case"><?= (int) ($channel['bg_overlay'] ?? ThemeService::CHAT_BG_OVERLAY_DEFAULT) ?>%</span></label>
          <input type="range" id="chan-bg-overlay" min="0" max="100" step="5" value="<?= (int) ($channel['bg_overlay'] ?? ThemeService::CHAT_BG_OVERLAY_DEFAULT) ?>" class="w-full accent-blurple cursor-pointer">
          <p class="text-xs text-discord-400 mt-1">A translucent layer between the text and the image — raise it when a busy image makes chat hard to read.</p>
        </div>
        <div class="flex gap-2">
          <button id="chan-bg-save" class="btn-primary flex-1 justify-center">Save background</button>
          <button id="chan-bg-remove" class="btn-ghost text-red-400">Remove</button>
          <button data-chan-bg-close class="btn-ghost">Close</button>
        </div>
        <div id="chan-bg-msg" class="text-sm text-green-400 hidden">Saved.</div>
      </div>
    </div>
  </div>

  <!-- Channel settings modal (channel control panel: bans, ops, topic, URL) -->
  <div id="chan-settings-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-chan-settings-close></div>
    <div class="relative card shadow-2xl w-[min(94vw,760px)] max-h-[88vh] flex flex-col overflow-hidden">
      <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-discord-700 shrink-0">
        <h2 class="text-lg font-bold text-white">Channel settings <span id="cs-name" class="text-blurple"></span></h2>
        <button type="button" data-chan-settings-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <div id="cs-tabs" class="flex flex-wrap gap-1 px-3 py-2 border-b border-discord-700 bg-discord-850 shrink-0"></div>
      <div id="cs-body" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin p-4 space-y-4"></div>
      <div id="cs-msg" class="hidden px-4 py-2 border-t border-discord-700 text-sm text-green-400 shrink-0"></div>
    </div>
  </div>

  <!-- Image lightbox -->
  <div id="lightbox" class="hidden fixed inset-0 z-[400] flex items-center justify-center p-4 bg-black/85">
    <button data-lightbox-close class="absolute top-3 right-3 text-white text-2xl leading-none p-2"><?= icon('x', 'w-4 h-4') ?></button>
    <img id="lightbox-img" src="" alt="" class="max-h-[90vh] max-w-full object-contain rounded-lg">
  </div>

  <!-- Generic dialog modal (replaces native prompt()/confirm()/alert(); the
       Electron desktop client doesn't support window.prompt and returns null,
       silently killing edit/ban/kick/topic flows there). -->
  <div id="dlg-modal" class="hidden fixed inset-0 z-[500] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-dlg-close></div>
    <div class="relative card p-6 w-[min(92vw,420px)] shadow-2xl">
      <h3 id="dlg-title" class="text-lg font-bold text-white mb-2"></h3>
      <p id="dlg-message" class="hidden text-sm text-discord-400 mb-3 whitespace-pre-wrap"></p>
      <input id="dlg-input" class="input hidden" type="text" autocomplete="off" spellcheck="false">
      <div id="dlg-error" class="hidden text-xs text-red-400 mt-2"></div>
      <div class="flex gap-2 mt-4">
        <button id="dlg-ok" class="btn-primary flex-1 justify-center">OK</button>
        <button id="dlg-cancel" class="btn-ghost hidden">Cancel</button>
      </div>
    </div>
  </div>

  <!-- JS-health watchdog: shows if the chat scripts failed to load (stale/broken deploy) -->
  <div id="js-warning" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-[200] card px-4 py-2.5 text-xs text-amber-300 border border-amber-500/40 shadow-2xl max-w-md"></div>

  <!-- DM arrival toast (shown when a DM lands while you are elsewhere) -->
  <div id="toast-stack" class="fixed top-3 right-3 z-[150] flex flex-col gap-2 w-80 max-w-[calc(100vw-1.5rem)] pointer-events-none"></div>

  <!-- Offline banner (shown while the connection is down; PWA offline reading) -->
  <div id="offline-banner" class="hidden fixed top-0 inset-x-0 z-[250] bg-amber-500/90 text-amber-950 text-center text-xs font-semibold py-1.5 px-4" role="status">
    You're offline — showing saved messages. Anything you send is queued and delivered when you reconnect.
  </div>

  <!-- Embed code modal -->
  <div id="embed-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-embed-close></div>
    <div class="relative card p-6 w-[min(92vw,560px)] shadow-2xl">
      <h2 class="text-lg font-bold text-white">Embed this chat</h2>
      <p class="text-xs text-discord-400 mt-1 mb-4">Copy this snippet into your site. Visitors are prompted to sign in, register, or join as a guest.</p>
      <textarea id="embed-code" readonly class="input font-mono text-xs" rows="5"></textarea>
      <div class="flex gap-2 mt-4">
        <button id="embed-copy" class="btn-primary flex-1 justify-center">Copy code</button>
        <button data-embed-close class="btn-ghost">Close</button>
      </div>
    </div>
  </div>

  <!-- How to install modal (PWA install instructions) -->
  <div id="install-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-install-close></div>
    <div class="relative card shadow-2xl w-[min(92vw,480px)] flex flex-col max-h-[80vh]">
      <div class="flex items-center justify-between px-6 pt-5 pb-2 border-b border-discord-700 shrink-0">
        <h2 class="text-lg font-bold text-white">How to install <?= h($site) ?></h2>
        <button type="button" data-install-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <div id="install-body" class="px-6 py-4 overflow-y-auto scrollbar-thin space-y-5 text-sm">
        <button id="install-now" class="hidden btn-primary w-full justify-center"><?= icon('download', 'w-4 h-4') ?> Install now</button>

        <div class="pt-1 border-t border-discord-700">
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">Desktop apps</div>
          <p class="text-discord-200 mb-2.5">Prefer a native app? LVChat also ships desktop clients for Windows, macOS and Linux.</p>
          <button id="download-open-btn" type="button" class="btn-ghost w-full justify-center"><?= icon('download', 'w-4 h-4') ?> Download the desktop app</button>
        </div>

        <div>
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">Windows · Mac · Linux</div>
          <ul class="text-discord-200 space-y-1.5 list-disc pl-4">
            <li><strong class="text-white">Chrome, Edge or Brave</strong> — click the <span class="text-discord-400">⭣ install</span> icon in the address bar, or open the <span class="text-discord-400">⋮</span> menu → <em>Install <?= h($site) ?></em>.</li>
            <li>The app opens in its own window and shows up in your Start menu / dock / app launcher, just like a native app.</li>
            <li><strong class="text-white">Firefox</strong> — desktop install support is limited; use Chrome, Edge, or Brave for the one-click install.</li>
            <li><strong class="text-white">Safari (Mac)</strong> — use <em>File → Add to Dock</em> to pin it like an app.</li>
          </ul>
        </div>

        <div>
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">Android</div>
          <ul class="text-discord-200 space-y-1.5 list-disc pl-4">
            <li>Open <?= h($site) ?> in <strong class="text-white">Chrome</strong>.</li>
            <li>Tap the <span class="text-discord-400">⋮</span> menu → <em>Install app</em> (or <em>Add to Home screen</em>).</li>
            <li>An icon appears on your home screen and opens the chat full-screen, like a native app.</li>
          </ul>
        </div>

        <div>
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">iPhone · iPad</div>
          <ul class="text-discord-200 space-y-1.5 list-disc pl-4">
            <li>Open <?= h($site) ?> in <strong class="text-white">Safari</strong>.</li>
            <li>Tap the <span class="text-discord-400">Share</span> button at the bottom of the screen.</li>
            <li>Tap <em>Add to Home Screen</em>, then <em>Add</em>.</li>
          </ul>
        </div>

        <div class="rounded-lg bg-discord-850 border border-discord-700 px-3 py-2.5 text-xs text-discord-400">
          <strong class="text-discord-200">Works offline:</strong> the app opens instantly and keeps the messages you've already viewed available without a connection. Anything you send while offline is queued and delivered automatically when you reconnect.
        </div>
      </div>
    </div>
  </div>

  <!-- Download the desktop app modal (native desktop clients) -->
  <div id="download-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-download-close></div>
    <div class="relative card shadow-2xl w-[min(92vw,600px)] flex flex-col max-h-[80vh]">
      <div class="flex items-center justify-between px-6 pt-5 pb-2 border-b border-discord-700 shrink-0">
        <h2 class="text-lg font-bold text-white">Download <?= h($site) ?></h2>
        <button type="button" data-download-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <div class="px-6 py-4 overflow-y-auto scrollbar-thin">
        <p class="text-sm text-discord-200">Two desktop apps are available — pick the one that fits how you work:</p>

        <div class="flex gap-1 mt-3 mb-4 rounded-lg bg-discord-850 p-1" role="tablist">
          <button type="button" data-download-tab="desktop" class="download-tab flex-1 inline-flex items-center justify-center gap-2 text-sm font-semibold py-1.5 px-3 rounded-md text-white bg-blurple" role="tab" aria-selected="true">
            <img src="/assets/apps/lvchat-desktop.png" alt="" class="w-4 h-4 rounded-[4px] object-cover" aria-hidden="true">
            LVChat Desktop
          </button>
          <button type="button" data-download-tab="messenger" class="download-tab flex-1 inline-flex items-center justify-center gap-2 text-sm font-semibold py-1.5 px-3 rounded-md text-discord-300" role="tab" aria-selected="false">
            <img src="/assets/apps/lvchat-messenger.png" alt="" class="w-4 h-4 rounded-[4px] object-cover" aria-hidden="true">
            LVChat Messenger
          </button>
        </div>

        <div data-download-panel="desktop" class="download-panel space-y-4">
          <div class="flex items-start gap-3">
            <img src="/assets/apps/lvchat-desktop.png" alt="LVChat Desktop icon" class="w-16 h-16 rounded-xl object-cover shrink-0 ring-1 ring-black/40" width="64" height="64" loading="lazy">
            <div class="min-w-0">
              <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">LVChat Desktop</div>
              <p class="text-sm text-discord-200">A desktop-based version of the normal <?= h($site) ?> experience — the full web chat in its own window, with native notifications and offline support. Choose this for the complete feature set.</p>
            </div>
          </div>
          <div class="grid gap-2">
            <?= download_buttons_html('desktop', $downloadPlatforms) ?>
          </div>
        </div>

        <div data-download-panel="messenger" class="download-panel hidden space-y-4">
          <div class="flex items-start gap-3">
            <img src="/assets/apps/lvchat-messenger.png" alt="LVChat Messenger icon" class="w-16 h-16 rounded-xl object-cover shrink-0 ring-1 ring-black/40" width="64" height="64" loading="lazy">
            <div class="min-w-0">
              <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">LVChat Messenger</div>
              <p class="text-sm text-discord-200">A more streamlined, instant-messaging-first experience. The layout is simplified around conversations, making it quicker to use day-to-day — which may appeal more to business settings. It's a separate, more focused desktop client rather than a web app.</p>
            </div>
          </div>
          <div class="grid gap-2">
            <?= download_buttons_html('messenger', $downloadPlatforms) ?>
          </div>
        </div>

        <?php if ($downloadUpdateUrl !== ''): ?>
        <div class="mt-5 rounded-lg bg-discord-850 border border-discord-700 px-3 py-2.5 text-xs text-discord-400 flex flex-wrap items-center gap-x-2 gap-y-1">
          <strong class="text-discord-200">Already installed?</strong>
          <span>Fetch the latest version from the</span>
          <a href="<?= h($downloadUpdateUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-blurple hover:text-blurple-dark font-medium underline decoration-dotted underline-offset-2">update link</a>.
        </div>
        <?php endif; ?>

        <div class="mt-5 rounded-lg bg-discord-850 border border-discord-700 px-3 py-2.5 text-xs text-discord-400 flex flex-wrap items-center gap-x-2 gap-y-1">
          <span><strong class="text-discord-200">Open source:</strong> LVChat is built in the open — bug testers and contributors are welcome. Report issues, suggest ideas, or help improve the code on</span>
          <a href="https://github.com/repairgenie/lvchat" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-blurple hover:text-blurple-dark font-medium underline decoration-dotted underline-offset-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"></path></svg>
            <span>GitHub</span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- App install unsupported modal (shown when the browser can't install PWAs) -->
  <div id="install-unsupported-modal" class="hidden fixed inset-0 z-[300] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70" data-install-unsupported-close></div>
    <div class="relative card shadow-2xl w-[min(92vw,480px)] flex flex-col max-h-[80vh]">
      <div class="flex items-center justify-between px-6 pt-5 pb-2 border-b border-discord-700 shrink-0">
        <h2 class="text-lg font-bold text-white">App install isn't supported here</h2>
        <button type="button" data-install-unsupported-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close"><?= icon('x', 'w-4 h-4') ?></button>
      </div>
      <div class="px-6 py-4 overflow-y-auto scrollbar-thin space-y-4 text-sm">
        <p class="text-discord-200">Your current browser doesn't support installing this chat as an app. To get the standalone app experience, use one of these instead:</p>
        <ul class="text-discord-200 space-y-1.5 list-disc pl-4">
          <li><strong class="text-white">Chrome, Edge or Brave</strong> (and other Chromium browsers · Windows · Mac · Linux) — click the <span class="text-discord-400">⭣ install</span> icon in the address bar, or the <span class="text-discord-400">⋮</span> menu → <em>Install <?= h($site) ?></em>.</li>
          <li><strong class="text-white">Chrome</strong> (Android) — <span class="text-discord-400">⋮</span> menu → <em>Install app</em>.</li>
          <li><strong class="text-white">Safari</strong> (iPhone · iPad) — <em>Share</em> → <em>Add to Home Screen</em>.</li>
          <li><strong class="text-white">Firefox</strong> (desktop) — install support is limited, so Chrome, Edge, or Brave is recommended.</li>
        </ul>
        <div class="rounded-lg bg-discord-850 border border-discord-700 px-3 py-2.5 text-xs text-discord-400">
          The chat works fine right here in your browser either way — installing just gives you its own window and offline support.
        </div>
      </div>
    </div>
  </div>

<script>window.CHAT = { csrf: <?= json_encode($csrf) ?> };</script>
  <script src="/assets/vendor/ai/marked.min.js"></script>
  <script src="/assets/vendor/ai/purify.min.js"></script>
  <script src="/assets/vendor/ai/highlight.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/styles/github-dark-dimmed.min.css">
  <script src="/assets/js/icons.js?v=<?= (int) @filemtime(ROOT . '/public/assets/js/icons.js') ?>"></script>
  <script src="/assets/js/app.js?v=<?= (int) @filemtime(ROOT . '/public/assets/js/app.js') ?>"></script>
  <script>
  // JS-health watchdog: explains why the chat scripts aren't running, if they aren't.
  window.__lvcErrors = [];
  window.addEventListener('error', function (e) {
    window.__lvcErrors.push((e.message || (e.error && e.error.message) || 'script error') + ' @' + String(e.filename || '').split('/').pop() + ':' + (e.lineno || ''));
  }, true);
  window.addEventListener('unhandledrejection', function (e) {
    window.__lvcErrors.push('unhandled promise: ' + String((e.reason && (e.reason.message || e.reason)) || e.reason));
  });
  setTimeout(function () {
    var b = document.body;
    var w = document.getElementById('js-warning');
    if (!w || b.dataset.jsok) return;
    var errs = (window.__lvcErrors || []).slice(0, 3);
    var build = b.dataset.version || '?';
    var msg;
    if (!b.dataset.jsstarted) {
      msg = '⚠ Chat scripts did not RUN (build ' + build + '). app.js did not execute at all — the server is likely serving /assets/js/app.js as HTML instead of JavaScript. Re-upload the app folder and run: bash bin/deploy.sh';
    } else if (!b.dataset.jsok) {
      msg = '⚠ Chat scripts crashed (build ' + build + '): ' + (errs.join(' · ') || 'unknown error');
    }
    if (msg) {
      w.textContent = msg;
      w.classList.remove('hidden');
    }
  }, 2500);
  </script>
</body>
</html>

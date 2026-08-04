<?php
$site = config_get('site_name', 'LVChat');
$csrf = Csrf::token();
$channelSlug = $channel['slug'] ?? '';
$dmName = $dm['username'] ?? '';
$theme = (string) ($user['theme'] ?? '') === 'light' ? 'light' : '';
$currentLevel = $channel ? AccessService::effectiveLevel($channel['id'], (int) $user['id']) : 'normal';
$myLevelWeight = level_weight($currentLevel);
$lastMsg = null;
foreach ($messages as $m) {
    if (($m['kind'] ?? '') === 'message') {
        $lastMsg = $m;
    }
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

function msg_html(array $m, ?array $prev, array $viewer): string {
    $system = in_array($m['kind'], MessageService::SYSTEM_KINDS, true);
    if ($system) {
        return '<div class="msg-system px-4 py-1.5 text-xs text-discord-400 italic text-center select-none" data-kind="' . h($m['kind']) . '">' . chat_markup($m['content']) . '</div>';
    }
    if ($m['kind'] === 'action') {
        $isAdmin = ($m['role'] ?? '') === 'admin';
        $rc = (!$isAdmin && !empty($m['role_color'])) ? ' style="color:' . h($m['role_color']) . '"' : '';
        $guestTag = !empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
        $nameColor = $isAdmin ? 'text-red-400' : 'text-discord-100';
        return '<div class="msg group px-4 py-0.5 flex gap-4 hover:bg-white/[0.03]" data-id="' . (int) $m['id'] . '" data-kind="action" data-is-pm="' . (!empty($m['is_pm']) ? '1' : '0') . '" data-author="' . h($m['username']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">'
            . '<div class="w-10 shrink-0"></div>'
            . '<div class="text-sm ' . ($isAdmin ? 'text-red-400' : 'text-discord-200') . '"' . $rc . '><span class="italic">* <span class="font-medium ' . $nameColor . '"' . $rc . '>' . h($m['username']) . '</span>' . $guestTag . ' ' . chat_markup($m['content']) . '</span></div>'
            . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-0.5 ml-auto" title="More">⋮</button>'
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
            . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-0.5" title="More">⋮</button>'
            . '</div>';
    }

    $actions = '';
    $mine = ((int) $m['sender_id'] === (int) $viewer['id'])
        || (!empty($m['username']) && strcasecmp((string) $m['username'], (string) $viewer['username']) === 0);
    if ($viewer['role'] === 'admin' || $mine) {
        $actions = '<button class="msg-edit text-[12px] opacity-60 hover:opacity-100" title="Edit">✏️</button>'
            . '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    }

    return '<div class="msg group px-4 pt-[17px] pb-0.5 hover:bg-white/[0.03] flex gap-4" data-id="' . (int) $m['id'] . '" data-kind="' . h($m['kind']) . '" data-is-pm="' . (!empty($m['is_pm']) ? '1' : '0') . '" data-author="' . h($m['username']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">'
        . '<div class="w-10 h-10 shrink-0">' . avatar_img($m, 'w-10 h-10 rounded-full') . '</div>'
        . '<div class="min-w-0 flex-1">'
        . '<div class="flex items-baseline gap-2 h-[22px]">'
        . '<span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ' . $color . '"' . $nameStyle . ' data-nick="' . h($m['username']) . '">' . $levelSym . h($m['username']) . (!empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '') . '</span>'
        . '<span class="time text-[11px] text-discord-400 hidden group-hover:inline" data-ts="' . h($m['created_at']) . '">' . h(date('H:i', strtotime($m['created_at'] . ' UTC'))) . '</span>'
        . (!empty($m['edited_at']) ? '<span class="text-[10px] text-discord-400">(edited)</span>' : '')
        . '</div>'
        . $replyLine
        . '<div class="msg-content text-[15px] leading-[1.4] ' . $contentColor . ' break-words"' . $contentStyle . '>' . chat_content_html($m) . '</div>'
        . reactions_html($m, $viewer)
        . '</div>'
        . '<div class="actions ml-auto opacity-0 group-hover:opacity-100 flex gap-1 pt-0.5">' . $actions . '</div>'
        . '<button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-1" title="More">⋮</button>'
        . '</div>';
}

function channel_link(array $c, string $channelSlug, array $user): string {
    $owned = (int) ($c['owner_id'] ?? 0) === (int) $user['id'] ? '1' : '0';
    $unread = (int) ($c['unread'] ?? 0);
    $online = (int) ($c['online'] ?? 0);
    $vis = $c['visibility'] !== 'public'
        ? '<span class="chan-vis text-[10px] text-discord-400' . ($unread > 0 ? '' : ' ml-auto') . '">' . ($c['visibility'] === 'secret' ? '🔒' : ($c['visibility'] === 'staff' ? '🛡' : '👁')) . '</span>'
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
        . ' class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm ' . $cls . '">'
        . '<span class="truncate ' . $nameCls . '">' . h($c['name']) . '</span>' . $onlineHtml . $badge . $vis
        . '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>'
        . '</a>';
}

function member_html(array $m, bool $online): string {
    $badge = $m['role'] === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>'
        : ($m['role'] === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '');
    $color = $m['role'] === 'admin' ? 'text-red-400' : ($online ? level_color($m['level']) : 'text-discord-400');
    $roleStyle = ($m['role'] !== 'admin' && !empty($m['role_color'])) ? ' style="color:' . h($m['role_color']) . '"' : '';
    $guestTag = !empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
    return '<a href="/app?dm=' . h(rawurlencode($m['username'])) . '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ' . $color . '"' . $roleStyle . ' data-username="' . h($m['username']) . '" data-user-id="' . (int) $m['id'] . '" data-level="' . h($m['level']) . '" data-guest="' . (!empty($m['guest']) ? '1' : '0') . '">
        <span class="text-[10px] font-bold w-3">' . h(level_symbol($m['level'])) . '</span>
        ' . avatar_img($m, 'w-6 h-6 rounded-full') . '
        <span class="truncate">' . h($m['username']) . $guestTag . '</span>' . ($m['away'] ? '<span class="text-xs" title="' . h($m['away']) . '">💤</span>' : '') . $badge
        . '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button></a>';
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
  <?php require ROOT . '/views/partials/pwa.php'; ?>
</head>
<body class="chat-app bg-discord-800 text-discord-200 antialiased flex"
      data-csrf="<?= h($csrf) ?>"
      data-channel="<?= h($channelSlug) ?>"
      data-dm="<?= h($dmName) ?>"
      data-my-id="<?= (int) $user['id'] ?>"
      data-my-level="<?= h($currentLevel) ?>"
      data-can-op="<?= $myLevelWeight >= 3 || $user['role'] === 'admin' ? '1' : '0' ?>"
      data-can-admin="<?= $user['role'] === 'admin' ? '1' : '0' ?>"
      data-my-nick="<?= h($user['username']) ?>"
      data-site-name="<?= h($site) ?>"
      data-version="<?= LVC_VERSION ?>"
      data-poll-ms="<?= (int) ((config_get('poll_interval', '2') ?? 2) * 1000) ?>"
      data-rt="<?= config_get('realtime', 'poll') === 'sse' ? 'sse' : 'poll' ?>"
      data-commands="<?= h(json_encode($commands)) ?>"
      data-sounds="<?= h(json_encode($sounds['sounds'])) ?>"
      data-sound-prefs="<?= h(json_encode(['dm_sound_id' => $sounds['dm_sound_id'], 'channel_sound_id' => $sounds['channel_sound_id']])) ?>"
      data-sound-overrides="<?= h(json_encode($sounds['overrides'])) ?>"
      data-bg-last="<?= (int) $bgLast ?>">

  <!-- ── Left: channel sidebar ── -->
  <aside id="sidebar" class="sidebar w-60 md:w-64 bg-discord-800 flex flex-col shrink-0">
    <div class="h-12 px-4 flex items-center justify-between border-b border-discord-700 shadow-sm shrink-0">
      <?php if ($logo = site_logo()): ?>
      <img src="<?= h($logo) ?>" alt="<?= h($site) ?>" class="max-h-8 max-w-[160px] w-auto object-contain">
      <?php else: ?>
      <span class="font-bold text-white text-sm truncate"><?= h($site) ?></span>
      <?php endif; ?>
      <div class="flex items-center gap-1.5">
        <button id="theme-toggle" class="text-discord-300 hover:text-white text-base leading-none p-1 md:block hidden" title="Switch theme">🌙</button>
        <button id="bell" class="relative text-discord-300 hover:text-white text-lg leading-none" title="Notifications">
          🔔<span id="bell-dot" class="hidden absolute -top-1 -right-1 w-4 h-4 rounded-full bg-red-500 text-[9px] text-white flex items-center justify-center"></span>
        </button>
      </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php if ($user['role'] === 'admin'): ?>
      <nav class="px-2 pt-3">
        <a href="/admin" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-amber-400 hover:bg-discord-600/40 hover:text-amber-300 text-sm font-medium">
          <span class="text-discord-400">🛡</span> Admin dashboard
        </a>
      </nav>
      <?php elseif ($user['role'] === 'staff'): ?>
      <nav class="px-2 pt-3">
        <a href="/admin/moderation" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-amber-400 hover:bg-discord-600/40 hover:text-amber-300 text-sm font-medium">
          <span class="text-discord-400">🛡</span> Moderation
        </a>
      </nav>
      <?php endif; ?>

      <nav class="px-2 pt-3">
        <a href="https://buymeacoffee.com/georgethegeek" target="_blank" rel="noopener" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm text-amber-300 hover:bg-discord-600/40 hover:text-amber-200" title="Support the project">
          <span class="text-discord-400">☕</span> Buy me a coffee
        </a>
      </nav>

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
          <button id="create-channel" class="text-discord-400 hover:text-white text-sm" title="Create a channel">＋</button>
        </div>
        <div class="mt-1 space-y-0.5">
          <a href="/browse" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-discord-300 hover:bg-discord-600/40 hover:text-white text-sm">
            <span class="text-discord-400">🌐</span> Browse channels
          </a>
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
            <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>
          </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <nav class="px-2 pt-3 pb-2">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">Online</div>
        <div class="mt-1 space-y-0.5">
          <?php foreach ($onlineUsers as $ou): ?>
          <a href="/app?dm=<?= h(rawurlencode($ou['username'])) ?>" data-ctx-user="<?= h($ou['username']) ?>" data-user-id="<?= (int) ($ou['id'] ?? 0) ?>" data-guest="<?= !empty($ou['guest']) ? '1' : '0' ?>" class="flex items-center gap-2 px-2 py-1 rounded-md text-xs text-discord-300 hover:bg-discord-600/40">
            <span class="w-2 h-2 rounded-full bg-green-500"></span><span class="<?= ($ou['role'] ?? '') === 'admin' ? 'text-red-400' : '' ?>"><?= h($ou['username']) ?><?= !empty($ou['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
            <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>
          </a>
          <?php endforeach; ?>
          <?php if (!$onlineUsers): ?><div class="px-2 py-1 text-xs text-discord-500">Nobody online</div><?php endif; ?>
        </div>
      </nav>
    </div>

    <div class="border-t border-discord-700 bg-discord-800 p-2 shrink-0">
      <a href="https://georgethegeek.com" target="_blank" rel="noopener" class="block text-center text-[10px] text-discord-500 hover:text-discord-300 py-1 transition-colors">Made with ❤️ in Las Vegas</a>
      <div class="flex items-center gap-2 rounded-md hover:bg-discord-600/40 px-2 py-1.5">
        <div class="w-8 h-8 rounded-full bg-blurple flex items-center justify-center text-sm font-bold text-white"><?= h(strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-white truncate"><?= h($user['username']) ?></div>
          <div class="text-[10px] text-discord-400 truncate"><?= (int) ($user['guest'] ?? 0) ? 'Guest' : ($user['role'] === 'admin' ? 'IRC Operator' : ($user['role'] === 'staff' ? 'Staff' : 'Registered')) ?></div>
        </div>
        <div class="relative">
          <button id="user-menu-btn" class="text-discord-400 hover:text-white text-xs px-1">⚙</button>
          <div id="user-menu" class="hidden absolute bottom-9 right-0 w-56 card p-1.5 shadow-xl z-50">
            <a href="/u/<?= h(rawurlencode($user['username'])) ?>" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Profile & settings</a>
            <a href="/support" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Support</a>
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
      <button id="sidebar-toggle" class="btn-ghost !p-1.5 text-lg leading-none" title="Toggle channel list" aria-label="Toggle channel list">☰</button>
      <?php if ($channel): ?>
      <span class="font-bold text-white text-sm"><?= h($channel['name']) ?></span>
      <span class="text-xs text-discord-400 truncate max-w-md hidden sm:block"><?= h($channel['topic']) ?></span>
      <div class="relative ml-auto flex items-center gap-2">
        <input id="search-input" type="search" placeholder="Search chat…" autocomplete="off"
               class="input w-40 md:w-56 !py-1 !text-xs hidden md:block" title="Search messages in your channels and DMs">
        <div id="search-results" class="hidden absolute top-9 right-0 w-96 max-w-[calc(100vw-2rem)] card shadow-2xl z-50 max-h-[60vh] overflow-y-auto scrollbar-thin"></div>
        <button id="share-btn" class="btn-ghost text-xs hidden md:flex" title="Copy shareable link">🔗 Share</button>
        <button id="mute-btn" class="btn-ghost text-xs hidden md:flex" data-mode="<?= h($notifyMode) ?>" title="<?= h($notifyMode === 'muted' ? 'Unmute channel' : ($notifyMode === 'mentions' ? 'Notification mode: mentions only' : 'Mute channel')) ?>"><?= $notifyMode === 'muted' ? '🔕 Muted' : ($notifyMode === 'mentions' ? '🔔 Mentions' : '🔔') ?></button>
        <button type="button" data-embed class="btn-ghost text-xs hidden md:flex" title="Get HTML embed code for this channel">&lt;/&gt; Embed</button>
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex" title="Install the app on your computer or phone">⬇ How to install</button>
        <button id="part-btn" class="btn-ghost text-xs text-red-400 hidden md:flex" title="Leave channel">✕ Leave</button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel">👥</button>
        <?php require ROOT . '/views/partials/header-menu.php'; ?>
      </div>
      <?php elseif ($dm): ?>
      <span class="font-bold text-white text-sm"><?= h($dm['username']) ?></span>
      <span class="text-xs text-discord-400">Private message</span>
      <div class="relative ml-auto flex items-center gap-2">
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex" title="Install the app on your computer or phone">⬇ How to install</button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel">👥</button>
        <?php require ROOT . '/views/partials/header-menu.php'; ?>
      </div>
      <?php else: ?>
      <span class="font-bold text-white text-sm"><?= h($site) ?></span>
      <span class="text-xs text-discord-400 truncate">You are not in any channel. Create one or browse the list.</span>
      <div class="relative ml-auto flex items-center gap-2">
        <a href="/browse" class="btn-primary text-xs hidden md:flex">Browse channels</a>
        <button id="create-channel-2" class="btn-ghost text-xs hidden md:flex">＋ New channel</button>
        <button id="install-btn" class="btn-ghost text-xs hidden md:flex">⬇ How to install</button>
        <button id="right-panel-toggle" class="btn-ghost text-xs hidden md:flex" title="Toggle friends & members panel">👥</button>
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
    <div class="px-4 py-2 text-xs <?= (int) $channel['owner_id'] === (int) $user['id'] ? 'text-amber-300 bg-amber-500/10 border-b border-amber-500/30' : 'text-discord-400 bg-discord-850 border-b border-discord-700' ?>">
      <?php if ((int) $channel['owner_id'] === (int) $user['id']): ?>
      ⚠ This channel is <strong>not registered</strong> — it will disappear when the last person leaves. You are the founder: type <code class="bg-discord-800 px-1 rounded">/register <?= h($channel['name']) ?></code> to keep it.
      <?php else: ?>
      This is a temporary channel (not registered) — it will disappear when the last person leaves.
      <?php endif; ?>
    </div>
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

    <div id="messages" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin"
         data-last-msg="<?= h(json_encode($lastMsg ?? null)) ?>">
      <button id="load-earlier" class="hidden w-full py-2 text-xs text-blurple hover:text-white hover:bg-blurple/10 transition-colors">↑ Load earlier messages</button>
      <?php if ($motd): ?>
      <div class="msg-system px-4 py-3 text-xs text-discord-400 italic text-center whitespace-pre-wrap"><?= h($motd) ?></div>
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
      <div class="h-full flex items-center justify-center text-discord-500 text-sm">No messages yet. Say hello!</div>
      <?php endif; ?>
    </div>

    <div id="composer" class="px-4 pt-2 pb-4 shrink-0 bg-discord-750">
      <div id="autocomplete" class="hidden mb-2 card max-h-56 overflow-y-auto scrollbar-thin"></div>
      <div id="emoji-panel" class="hidden mb-2 card p-2 grid grid-cols-8 gap-1 max-h-56 overflow-y-auto scrollbar-thin"></div>
      <div id="gif-panel" class="hidden mb-2 card max-h-96 flex flex-col">
        <div class="flex items-center gap-2 px-3 pt-2.5 pb-2 border-b border-discord-700 shrink-0">
          <span class="text-xs font-bold uppercase tracking-wide text-discord-400">GIF</span>
          <input id="gif-search" type="search" placeholder="Search Giphy…" autocomplete="off" class="input flex-1 !py-1.5 !text-xs">
          <button type="button" id="gif-close" class="text-discord-400 hover:text-white text-xs px-1" title="Close">✕</button>
        </div>
        <div id="gif-grid" class="grid grid-cols-4 gap-1.5 overflow-y-auto scrollbar-thin p-2"></div>
        <div id="gif-status" class="hidden px-3 py-2 text-xs text-discord-400 border-t border-discord-700 shrink-0"></div>
        <button type="button" id="gif-more" class="hidden py-2 text-xs text-blurple hover:text-white hover:bg-blurple/10 transition-colors shrink-0">Load more GIFs</button>
      </div>
      <div id="reply-chip" class="hidden mb-2 flex items-center gap-2 card px-3 py-1.5 text-sm text-discord-300">
        <span class="text-xs text-blurple font-semibold">↪ Replying to</span>
        <span id="reply-chip-name" class="font-semibold text-white truncate"></span>
        <span id="reply-chip-excerpt" class="truncate text-discord-400"></span>
        <button type="button" id="reply-cancel" class="ml-auto text-discord-400 hover:text-white text-xs px-1" title="Cancel reply">✕</button>
      </div>
      <form id="send-form" method="post" action="/api/send" class="relative">
        <?= Csrf::field() ?>
        <?php if ($dm): ?>
        <input type="hidden" name="recipient" value="<?= h($dm['username']) ?>">
        <?php elseif ($channel): ?>
        <input type="hidden" name="channel" value="<?= h($channel['slug']) ?>">
        <?php endif; ?>
        <input type="hidden" id="reply-to-input" name="reply_to" value="">
        <textarea id="chat-input" name="content" rows="1" autocomplete="off" spellcheck="false"
               class="input pr-48 py-2.5 resize-none bg-discord-800 !rounded-lg !border-transparent focus:!border-transparent shadow align-middle max-h-40 overflow-y-auto"
               placeholder="<?= h($channel ? "Message #" . $channel['name'] : ($dm ? 'Message ' . $dm['username'] : 'Join a channel to chat')) ?>"
               <?= ($channel || $dm) ? '' : 'disabled' ?>></textarea>
        <button type="button" id="upload-btn" class="absolute right-36 top-1/2 -translate-y-1/2 btn-ghost !p-1.5 !rounded-md text-base <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Upload an image">📎</button>
        <input type="file" id="upload-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
        <button type="button" id="gif-btn" class="absolute right-24 top-1/2 -translate-y-1/2 btn-ghost !p-1.5 !rounded-md text-xs font-bold <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Send a GIF">GIF</button>
        <button type="button" id="emoji-btn" class="absolute right-12 top-1/2 -translate-y-1/2 btn-ghost !p-1.5 !rounded-md text-base <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Emoji">😀</button>
        <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 btn-primary !p-1.5 !rounded-md" title="Send">➤</button>
      </form>
      <div class="text-[11px] text-discord-400 mt-1.5 px-1">Type <span class="text-discord-200">/</span> for commands · <span class="text-discord-200">@nick</span> to mention · <span class="text-discord-200">Enter</span> to send · <span class="text-discord-200">Shift+Enter</span> newline · <span class="text-discord-200">**bold**</span> <span class="text-discord-200">*italic*</span> <span class="text-discord-200">```code```</span></div>
    </div>
  </section>

  <!-- ── Mobile right panel backdrop ── -->
  <div id="right-panel-backdrop" class="hidden fixed inset-0 z-[55] bg-black/60 md:hidden"></div>

  <!-- ── Right: friends + member list ── -->
  <aside id="right-panel" class="right-panel hidden md:flex w-60 bg-discord-800 flex-col shrink-0 min-h-0">
    <?php if ((int) ($user['guest'] ?? 0) !== 1): ?>
    <?php
    $onlineFriends = array_values(array_filter($friends, fn($f) => !empty($f['is_online'])));
    $offlineFriends = array_values(array_filter($friends, fn($f) => empty($f['is_online'])));
    ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">
      <span>Friends — <span id="friend-count"><?= (int) count($friends) ?></span></span>
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
          <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>
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
          <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>
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
    <div class="h-12 px-4 border-b border-discord-700 flex items-center text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">Members — <span id="member-count"><?= (int) $memberCount ?></span></div>
    <div id="member-list" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php foreach ($typeGroups as $label => $list): if (!$list) continue; ?>
      <div class="px-2 <?= $label === 'Admins' ? 'pt-2' : 'pt-3' ?> pb-2">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1"><?= h($label) ?> — <?= count($list) ?></div>
        <?php foreach ($list as $m): ?><?= member_html($m, !empty($m['is_online'])) ?><?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">Members</div>
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

  <!-- Notifications panel -->
  <div id="notif-panel" class="hidden fixed top-14 right-4 w-80 max-w-[calc(100vw-2rem)] card shadow-2xl z-50 max-h-[60vh] overflow-y-auto scrollbar-thin">
    <div class="px-3 py-2 border-b border-discord-700 text-sm font-semibold flex items-center justify-between">
      <span>Notifications</span>
      <button id="notif-clear" class="text-xs text-discord-400 hover:text-white">Mark all read</button>
    </div>
    <div id="notif-list" class="p-1.5 text-sm">
      <?php foreach ($notifications as $n): ?>
      <div class="px-2 py-1.5 rounded hover:bg-discord-750 text-discord-300">
        <?php if ($n['kind'] === 'dm' && !empty($n['sender'])): ?>
        <span class="text-discord-400">dm</span> from <a class="text-blurple hover:underline" href="/app?dm=<?= h(rawurlencode($n['sender'])) ?>"><?= h($n['sender']) ?></a>
        <?php elseif ($n['kind'] === 'friend_request' && !empty($n['sender'])): ?>
        <span class="text-green-400">friend request</span> from <a class="text-blurple hover:underline" href="/u/<?= h(rawurlencode($n['sender'])) ?>"><?= h($n['sender']) ?></a>
        <?php elseif ($n['kind'] === 'friend_accepted' && !empty($n['sender'])): ?>
        <span class="text-green-400">friend accepted</span> — <a class="text-blurple hover:underline" href="/u/<?= h(rawurlencode($n['sender'])) ?>"><?= h($n['sender']) ?></a> is now your friend
        <?php else: ?>
        <span class="text-discord-400"><?= h($n['kind']) ?></span>
        <?php if ($n['channel_name']): ?>→ <a class="text-blurple hover:underline" href="/app?channel=<?= h(rawurlencode(ChannelService::nameToSlug($n['channel_name']))) ?>"><?= h($n['channel_name']) ?></a><?php endif; ?>
        <span class="text-discord-400">from</span> <?= h($n['sender'] ?? 'system') ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$notifications): ?><div class="px-2 py-3 text-discord-500 text-center">Nothing new</div><?php endif; ?>
    </div>
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
        <button type="button" data-report-close class="text-discord-400 hover:text-white text-lg leading-none p-1">✕</button>
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
      <button type="button" data-guest-modal-close class="absolute top-2 right-3 text-discord-400 hover:text-white text-lg leading-none p-1">✕</button>
      <div class="mx-auto w-16 h-16 rounded-full bg-discord-600 flex items-center justify-center text-2xl font-bold text-white mb-3" id="guest-profile-avatar"></div>
      <h2 class="text-lg font-bold text-white" id="guest-profile-name"></h2>
      <p class="text-sm text-discord-400 mt-2">Profile does not exist</p>
      <p class="text-xs text-discord-500 mt-1">Guest users do not have profiles.</p>
      <button data-guest-modal-close class="btn-ghost mt-4 justify-center w-full">Close</button>
    </div>
  </div>

  <!-- Image lightbox -->
  <div id="lightbox" class="hidden fixed inset-0 z-[400] flex items-center justify-center p-4 bg-black/85">
    <button data-lightbox-close class="absolute top-3 right-3 text-white text-2xl leading-none p-2">✕</button>
    <img id="lightbox-img" src="" alt="" class="max-h-[90vh] max-w-full object-contain rounded-lg">
  </div>

  <!-- JS-health watchdog: shows if the chat scripts failed to load (stale/broken deploy) -->
  <div id="js-warning" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-[200] card px-4 py-2.5 text-xs text-amber-300 border border-amber-500/40 shadow-2xl max-w-md"></div>

  <!-- DM arrival toast (shown when a DM lands while you are elsewhere) -->
  <div id="dm-toast" class="hidden fixed bottom-4 right-4 z-[150] w-80 max-w-[calc(100vw-2rem)] card border border-blurple/40 shadow-2xl"></div>

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
        <button type="button" data-install-close class="text-discord-400 hover:text-white text-lg leading-none p-1" title="Close">✕</button>
      </div>
      <div id="install-body" class="px-6 py-4 overflow-y-auto scrollbar-thin space-y-5 text-sm">
        <button id="install-now" class="hidden btn-primary w-full justify-center">⬇ Install now</button>

        <div>
          <div class="text-xs font-bold uppercase tracking-wide text-discord-400 mb-1.5">Windows · Mac · Linux</div>
          <ul class="text-discord-200 space-y-1.5 list-disc pl-4">
            <li><strong class="text-white">Chrome or Edge</strong> — click the <span class="text-discord-400">⭣ install</span> icon in the address bar, or open the <span class="text-discord-400">⋮</span> menu → <em>Install <?= h($site) ?></em>.</li>
            <li>The app opens in its own window and shows up in your Start menu / dock / app launcher, just like a native app.</li>
            <li><strong class="text-white">Firefox</strong> — desktop install support is limited; use Chrome or Edge for the one-click install.</li>
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

<script>window.CHAT = { csrf: <?= json_encode($csrf) ?> };</script>
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

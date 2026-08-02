<?php
$site = config_get('site_name', 'LVChat');
$csrf = Csrf::token();
$channelSlug = $channel['slug'] ?? '';
$dmName = $dm['username'] ?? '';
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
        return '<div class="msg group px-4 py-0.5 flex gap-4 hover:bg-white/[0.03]" data-id="' . (int) $m['id'] . '" data-kind="action" data-author="' . h($m['username']) . '">
            <div class="w-10 shrink-0"></div>
            <div class="text-sm ' . ($isAdmin ? 'text-red-400' : 'text-discord-200') . '"' . $rc . '><span class="italic">* <span class="font-medium ' . $nameColor . '"' . $rc . '>' . h($m['username']) . '</span>' . $guestTag . ' ' . chat_markup($m['content']) . '</span></div>
        </div>';
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

    if ($group) {
        return '<div class="msg group px-4 py-0.5 hover:bg-white/[0.03] flex gap-4" data-id="' . (int) $m['id'] . '" data-kind="message" data-author="' . h($m['username']) . '">
            <div class="w-10 shrink-0"></div>
            <div class="min-w-0 flex-1">
                <div class="msg-content text-[15px] leading-[1.4] ' . $contentColor . ' break-words"' . $contentStyle . '>' . chat_markup_plain($m['content']) . '</div>
            </div>
        </div>';
    }

    $actions = '';
    if ((int) $m['sender_id'] === (int) $viewer['id']) {
        $actions = '<button class="msg-edit text-[12px] opacity-60 hover:opacity-100" title="Edit">✏️</button>'
            . '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    } elseif ($viewer['role'] === 'admin') {
        $actions = '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    }

    return '<div class="msg group px-4 pt-[17px] pb-0.5 hover:bg-white/[0.03] flex gap-4" data-id="' . (int) $m['id'] . '" data-kind="message" data-author="' . h($m['username']) . '">
        <div class="w-10 h-10 shrink-0 rounded-full bg-discord-500 flex items-center justify-center text-sm font-bold text-white border border-discord-600">' . h($initial) . '</div>
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2 h-[22px]">
                <span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ' . $color . '"' . $nameStyle . ' data-nick="' . h($m['username']) . '">' . $levelSym . h($m['username']) . (!empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '') . '</span>
                <span class="time text-[11px] text-discord-400 hidden group-hover:inline" data-ts="' . h($m['created_at']) . '">' . h(date('H:i', strtotime($m['created_at'] . ' UTC'))) . '</span>
                ' . (!empty($m['edited_at']) ? '<span class="text-[10px] text-discord-400">(edited)</span>' : '') . '
            </div>
            <div class="msg-content text-[15px] leading-[1.4] ' . $contentColor . ' break-words"' . $contentStyle . '>' . chat_markup_plain($m['content']) . '</div>
        </div>
        <div class="actions ml-auto opacity-0 group-hover:opacity-100 flex gap-1 pt-0.5">' . $actions . '</div>
    </div>';
}

function member_html(array $m, bool $online): string {
    $badge = $m['role'] === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>'
        : ($m['role'] === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '');
    $color = $m['role'] === 'admin' ? 'text-red-400' : ($online ? level_color($m['level']) : 'text-discord-400');
    $roleStyle = ($m['role'] !== 'admin' && !empty($m['role_color'])) ? ' style="color:' . h($m['role_color']) . '"' : '';
    $guestTag = !empty($m['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
    return '<a href="/app?dm=' . h(rawurlencode($m['username'])) . '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ' . $color . '"' . $roleStyle . ' data-username="' . h($m['username']) . '" data-level="' . h($m['level']) . '">
        <span class="text-[10px] font-bold w-3">' . h(level_symbol($m['level'])) . '</span>
        <span class="truncate">' . h($m['username']) . $guestTag . '</span>' . ($m['away'] ? '<span class="text-xs" title="' . h($m['away']) . '">💤</span>' : '') . $badge . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en" class="dark h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($channel ? $channel['name'] : ($dm ? 'DM: ' . $dm['username'] : $site)) ?> · <?= h($site) ?></title>
  <?php require ROOT . '/views/partials/tailwind.php'; ?>
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
      data-version="<?= LVC_VERSION ?>"
      data-commands="<?= h(json_encode($commands)) ?>">

  <!-- ── Left: channel sidebar ── -->
  <aside id="sidebar" class="sidebar w-60 md:w-64 bg-discord-800 flex flex-col shrink-0">
    <div class="h-12 px-4 flex items-center justify-between border-b border-discord-700 shadow-sm shrink-0">
      <span class="font-bold text-white text-sm truncate"><?= h($site) ?></span>
      <button id="bell" class="relative text-discord-300 hover:text-white text-lg leading-none" title="Notifications">
        🔔<span id="bell-dot" class="hidden absolute -top-1 -right-1 w-4 h-4 rounded-full bg-red-500 text-[9px] text-white flex items-center justify-center"></span>
      </button>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <nav class="px-2 pt-4 pb-2">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400 flex items-center justify-between">
          <span>Text channels</span>
          <button id="create-channel" class="text-discord-400 hover:text-white text-sm" title="Create a channel">＋</button>
        </div>
        <div class="mt-1 space-y-0.5">
          <?php if ($user['role'] === 'admin'): ?>
          <a href="/admin" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-amber-400 hover:bg-discord-600/40 hover:text-amber-300 text-sm font-medium">
            <span class="text-discord-400">🛡</span> Admin dashboard
          </a>
          <?php endif; ?>
          <a href="/browse" class="flex items-center gap-2 px-2 py-1.5 rounded-md text-discord-300 hover:bg-discord-600/40 hover:text-white text-sm">
            <span class="text-discord-400">🌐</span> Browse channels
          </a>
          <?php foreach ($channels as $c): ?>
          <a href="/app?channel=<?= h(rawurlencode($c['slug'])) ?>"
             data-ctx-channel="<?= h($c['slug']) ?>"
             data-ctx-channel-name="<?= h($c['name']) ?>"
             class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm <?= $channelSlug === $c['slug'] ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white' ?>">
             <span class="truncate"><?= h($c['name']) ?></span>
             <?php if ($c['visibility'] !== 'public'): ?><span class="text-[10px] text-discord-400 ml-auto"><?= $c['visibility'] === 'secret' ? '🔒' : ($c['visibility'] === 'staff' ? '🛡' : '👁') ?></span><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <nav class="px-2 pt-3">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">Direct messages</div>
        <div class="mt-1 space-y-0.5">
          <?php if (!$dmPartners): ?>
          <div class="px-2 py-1 text-xs text-discord-500">No conversations yet</div>
          <?php endif; ?>
          <?php foreach ($dmPartners as $d): $uc = array_filter($unreadDms, fn ($x) => $x['user_id'] == $d['id']); $ucnt = $uc ? $uc[0]['count'] : 0; ?>
          <a href="/app?dm=<?= h(rawurlencode($d['username'])) ?>"
             data-ctx-user="<?= h($d['username']) ?>"
             class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm <?= $dmName === $d['username'] ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white' ?>">
            <span class="w-2 h-2 rounded-full <?= !empty($d['away']) ? 'bg-amber-400' : 'bg-green-500' ?>"></span>
            <span class="truncate <?= ($d['role'] ?? '') === 'admin' ? 'text-red-400' : '' ?>"><?= h($d['username']) ?><?= !empty($d['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
            <?php if ($ucnt): ?><span class="ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center"><?= $ucnt ?></span><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <nav class="px-2 pt-3 pb-2">
        <div class="px-2 text-xs font-bold uppercase tracking-wide text-discord-400">Online</div>
        <div class="mt-1 space-y-0.5">
          <?php foreach ($onlineUsers as $ou): ?>
          <a href="/app?dm=<?= h(rawurlencode($ou['username'])) ?>" data-ctx-user="<?= h($ou['username']) ?>" class="flex items-center gap-2 px-2 py-1 rounded-md text-xs text-discord-300 hover:bg-discord-600/40">
            <span class="w-2 h-2 rounded-full bg-green-500"></span><span class="<?= ($ou['role'] ?? '') === 'admin' ? 'text-red-400' : '' ?>"><?= h($ou['username']) ?><?= !empty($ou['guest']) ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '' ?></span>
          </a>
          <?php endforeach; ?>
          <?php if (!$onlineUsers): ?><div class="px-2 py-1 text-xs text-discord-500">Nobody online</div><?php endif; ?>
        </div>
      </nav>
    </div>

    <div class="border-t border-discord-700 bg-discord-800 p-2 shrink-0">
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
            <?php if ($channel): ?>
            <button id="set-away-btn" class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm">Set away</button>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="/admin" class="block px-2 py-1.5 rounded hover:bg-discord-750 text-sm text-amber-400">Admin dashboard</a>
            <?php endif; ?>
            <form method="post" action="/logout"><?= Csrf::field() ?><button class="w-full text-left px-2 py-1.5 rounded hover:bg-discord-750 text-sm text-red-400">Log out</button></form>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Center: chat ── -->
  <section class="flex-1 flex flex-col min-w-0 min-h-0 bg-discord-750">
    <header class="h-12 pl-2 pr-4 border-b border-discord-800 bg-discord-750 flex items-center gap-3 shadow-sm shrink-0">
      <button id="sidebar-toggle" class="btn-ghost !p-1.5 text-lg leading-none" title="Toggle channel list" aria-label="Toggle channel list">☰</button>
      <?php if ($channel): ?>
      <span class="font-bold text-white text-sm"><?= h($channel['name']) ?></span>
      <span class="text-xs text-discord-400 truncate max-w-md hidden sm:block"><?= h($channel['topic']) ?></span>
      <div class="ml-auto flex items-center gap-2">
        <button id="share-btn" class="btn-ghost text-xs" title="Copy shareable link">🔗 Share</button>
        <button id="part-btn" class="btn-ghost text-xs text-red-400" title="Leave channel">✕ Leave</button>
      </div>
      <?php elseif ($dm): ?>
      <span class="font-bold text-white text-sm"><?= h($dm['username']) ?></span>
      <span class="text-xs text-discord-400">Private message</span>
      <?php else: ?>
      <span class="font-bold text-white text-sm"><?= h($site) ?></span>
      <span class="text-xs text-discord-400 truncate">You are not in any channel. Create one or browse the list.</span>
      <div class="ml-auto flex items-center gap-2">
        <a href="/browse" class="btn-primary text-xs">Browse channels</a>
        <button id="create-channel-2" class="btn-ghost text-xs">＋ New channel</button>
      </div>
      <?php endif; ?>
    </header>

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
    <div class="px-3 py-1.5 border-b border-discord-700 bg-discord-850 flex items-center gap-1.5 flex-wrap shrink-0">
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

    <div class="px-4 pt-2 pb-4 shrink-0 bg-discord-750">
      <div id="autocomplete" class="hidden mb-2 card max-h-56 overflow-y-auto scrollbar-thin"></div>
      <div id="emoji-panel" class="hidden mb-2 card p-2 grid grid-cols-8 gap-1 max-h-56 overflow-y-auto scrollbar-thin"></div>
      <form id="send-form" method="post" action="/api/send" class="relative">
        <?= Csrf::field() ?>
        <?php if ($dm): ?>
        <input type="hidden" name="recipient" value="<?= h($dm['username']) ?>">
        <?php elseif ($channel): ?>
        <input type="hidden" name="channel" value="<?= h($channel['slug']) ?>">
        <?php endif; ?>
        <input id="chat-input" name="content" type="text" autocomplete="off" spellcheck="false"
               class="input pr-28 bg-discord-800 !rounded-lg !border-transparent focus:!border-transparent shadow"
               placeholder="<?= h($channel ? "Message #" . $channel['name'] : ($dm ? 'Message ' . $dm['username'] : 'Join a channel to chat')) ?>"
               <?= ($channel || $dm) ? '' : 'disabled' ?>>
        <button type="button" id="emoji-btn" class="absolute right-12 top-1/2 -translate-y-1/2 btn-ghost !p-1.5 !rounded-md text-base <?= ($channel || $dm) ? '' : 'hidden' ?>" title="Emoji">😀</button>
        <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 btn-primary !p-1.5 !rounded-md" title="Send">➤</button>
      </form>
      <div class="text-[11px] text-discord-400 mt-1.5 px-1">Type <span class="text-discord-200">/</span> for commands · <span class="text-discord-200">@nick</span> to mention · <span class="text-discord-200">Enter</span> to send</div>
    </div>
  </section>

  <!-- ── Right: member list ── -->
  <aside class="hidden md:flex w-60 bg-discord-800 flex-col shrink-0 min-h-0">
    <?php if ($channel): ?>
    <div class="h-12 px-4 border-b border-discord-700 flex items-center text-xs font-bold uppercase tracking-wide text-discord-400 shrink-0">Members — <span id="member-count"><?= count($members) ?></span></div>
    <div id="member-list" class="flex-1 min-h-0 overflow-y-auto scrollbar-thin">
      <?php
      $online = array_filter($members, fn ($m) => !empty($m['is_online']));
      $offline = array_filter($members, fn ($m) => empty($m['is_online']));
      ?>
      <div class="px-2 py-2">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Online — <?= count($online) ?></div>
        <?php foreach ($online as $m): ?><?= member_html($m, true) ?><?php endforeach; ?>
      </div>
      <div class="px-2 pb-2">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Offline — <?= count($offline) ?></div>
        <?php foreach ($offline as $m): ?><?= member_html($m, false) ?><?php endforeach; ?>
      </div>
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
        <span class="text-discord-400"><?= h($n['kind']) ?></span>
        <?php if ($n['channel_name']): ?>→ <a class="text-blurple hover:underline" href="/app?channel=<?= h(rawurlencode(ChannelService::nameToSlug($n['channel_name']))) ?>"><?= h($n['channel_name']) ?></a><?php endif; ?>
        <span class="text-discord-400">from</span> <?= h($n['sender'] ?? 'system') ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$notifications): ?><div class="px-2 py-3 text-discord-500 text-center">Nothing new</div><?php endif; ?>
    </div>
  </div>

  <!-- Mobile sidebar backdrop -->
  <div id="sidebar-backdrop" class="hidden fixed inset-0 z-[55] bg-black/60 md:hidden"></div>

  <!-- Right-click context menu -->
  <div id="ctx-menu" class="hidden fixed z-[100] min-w-52 max-w-72 card p-1.5 shadow-2xl text-sm"></div>

  <!-- JS-health watchdog: shows if the chat scripts failed to load (stale/broken deploy) -->
  <div id="js-warning" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-[200] card px-4 py-2.5 text-xs text-amber-300 border border-amber-500/40 shadow-2xl max-w-md"></div>

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

<?php

declare(strict_types=1);

// ─── Core client commands ──────────────────────────────────────────────────

CommandRegistry::register('help', [
    'desc' => 'Show help for all commands (or one command).',
    'usage' => '/help [command]',
    'run' => function (array $args, array $user, ?array $channel) {
        $all = CommandRegistry::all();
        $topic = $args[0] ?? null;
        if ($topic) {
            $c = CommandRegistry::get($topic);
            if (!$c) {
                return ['replies' => ["No help available for /$topic."]];
            }
            return ['replies' => [h('/' . $topic . ' — ' . $c['desc']), 'Usage: ' . h($c['usage'] ?? '/' . $topic)]];
        }
        $grouped = [];
        foreach ($all as $name => $c) {
            $grp = $c['group'] ?? 'Core';
            $grouped[$grp][] = '/' . $name . '  —  ' . $c['desc'];
        }
        ksort($grouped);
        $lines = ['Help for this server. Run /help <command> for details.'];
        foreach ($grouped as $g => $list) {
            $lines[] = '── ' . $g . ' ──';
            $lines = array_merge($lines, $list);
        }
        return ['replies' => $lines];
    },
], );

CommandRegistry::register('join', [
    'group' => 'Core',
    'desc' => 'Join (or create) a channel.',
    'usage' => '/join <#channel> [key]',
    'run' => function (array $args, array $user, ?array $channel) {
        $name = $args[0] ?? null;
        if (!$name || !preg_match('/^[#&]/', $name)) {
            return ['replies' => ['Usage: /join <#channel> [key]']];
        }
        $ch = ChannelService::find($name);
        if (!$ch) {
            $created = ChannelService::create($user, $name);
            if (is_string($created)) {
                return ['replies' => [$created]];
            }
            ChannelService::join($created, $user);
            return ['replies' => ["Created and joined $name."], 'redirect' => '/c/' . rawurlencode($created['slug'])];
        }
        $status = ChannelService::joinStatus($ch, $user, $args[1] ?? null);
        if ($status['reason'] === 'already_member') {
            return ['replies' => ["You are already in $name."], 'redirect' => '/c/' . rawurlencode($ch['slug'])];
        }
        if (!$status['ok']) {
            if ($status['reason'] === 'need_key') {
                return ['replies' => ["Channel $name requires a key."], 'redirect' => '/app?join=' . rawurlencode($ch['slug'])];
            }
            return ['replies' => [$status['reason']]];
        }
        ChannelService::join($ch, $user);
        return ['replies' => ["Joined $name."], 'redirect' => '/c/' . rawurlencode($ch['slug'])];
    },
]);

CommandRegistry::register('part', [
    'group' => 'Core',
    'desc' => 'Leave a channel.',
    'usage' => '/part [#channel] [reason]',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        $reason = implode(' ', $args);
        ChannelService::part($channel, $user, $reason ?: null);
        return ['replies' => ["You have left " . $channel['name'] . '.'], 'redirect' => '/app'];
    },
]);

CommandRegistry::register('quit', [
    'group' => 'Core',
    'desc' => 'Disconnect (log out) from the chat.',
    'usage' => '/quit [reason]',
    'run' => function (array $args, array $user, ?array $channel) {
        $reason = implode(' ', $args);
        foreach (ChannelService::joinedChannelNames($user) as $c) {
            $msg = $user['username'] . ' has quit';
            if ($reason) {
                $msg .= ' (' . $reason . ')';
            }
            MessageService::system($c['id'], 'quit', $msg);
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$c['id'], $user['id']]);
            ChannelService::afterMemberRemoval($c['id']);
        }
        return ['replies' => ['Goodbye!'], 'redirect' => '/logout'];
    },
]);

CommandRegistry::register('me', [
    'group' => 'Core',
    'desc' => 'Send an action message (/me does something).',
    'usage' => '/me <action>',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        $text = implode(' ', $args);
        if ($text === '') {
            return ['replies' => ['Usage: /me <action>']];
        }
        $blocked = BanService::canPost($channel, $user, AccessService::member($channel['id'], $user));
        if ($blocked) {
            return ['replies' => [$blocked]];
        }
        MessageService::send($channel['id'], $user, $text, 'action');
        return null;
    },
]);

foreach (['msg', 'pm', 'query'] as $alias) {
    CommandRegistry::register($alias, [
        'group' => 'Core',
        'desc' => 'Send a private message to a user.',
        'usage' => '/msg <nick> <message>',
        'run' => function (array $args, array $user, ?array $channel) use ($alias) {
            $nick = $args[0] ?? null;
            $text = implode(' ', array_slice($args, 1));
            if (!$nick || $text === '') {
                return ['replies' => ['Usage: /msg <nick> <message>']];
            }
            return UserCommands::privateMessage($user, $nick, $text);
        },
    ]);
}

CommandRegistry::register('notice', [
    'group' => 'Core',
    'desc' => 'Send a notice to a user (no notification created).',
    'usage' => '/notice <nick> <message>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $text = implode(' ', array_slice($args, 1));
        if (!$nick || $text === '') {
            return ['replies' => ['Usage: /notice <nick> <message>']];
        }
        $target = Auth::findActor($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        if (MessageService::sameActor($target, $user)) {
            return ['replies' => ['You cannot send a notice to yourself.']];
        }
        $prefix = $user['role'] === 'admin' ? '[Server] ' : '';
        MessageService::insertPm($user, $target, mb_substr($prefix . $text, 0, 2000));
        MessageService::logPm((int) $user['id'], $user['username'], $target['username'], mb_substr($prefix . $text, 0, 2000), MessageService::isGuest($user) ? 1 : 0);
        return ['replies' => ["Notice sent to $nick."]];
    },
]);

CommandRegistry::register('nick', [
    'group' => 'Core',
    'desc' => 'Change your username.',
    'usage' => '/nick <newnick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $newnick = $args[0] ?? null;
        if (!$newnick || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $newnick)) {
            return ['replies' => ['Usage: /nick <newnick> (2-32 chars, IRC-safe symbols)']];
        }
        if (strtolower($newnick) === strtolower($user['username'])) {
            return ['replies' => ['You already have that nick.']];
        }
        $exists = Database::scalar('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$newnick]);
        if ($exists) {
            return ['replies' => ["Nickname $newnick is already in use."]];
        }
        $ban = Database::row(
            "SELECT * FROM bans WHERE active = 1 AND kind = 'qline' AND channel_id IS NULL
             AND (expires_at IS NULL OR expires_at > datetime('now'))"
        );
        if ($ban && Auth::maskMatch($ban['mask'], $newnick)) {
            return ['replies' => ['That nickname is reserved (' . ($ban['reason'] ?: 'q-lined') . ').']];
        }
        Database::query('UPDATE users SET username = ? WHERE id = ?', [$newnick, $user['id']]);
        foreach (ChannelService::joinedChannelNames($user) as $c) {
            MessageService::system($c['id'], 'nick', $user['username'] . ' is now known as ' . $newnick);
        }
        return ['replies' => ["You are now known as $newnick."]];
    },
]);

CommandRegistry::register('away', [
    'group' => 'Core',
    'desc' => 'Set yourself away. Use /back to return.',
    'usage' => '/away [message]',
    'run' => function (array $args, array $user, ?array $channel) {
        $msg = implode(' ', $args);
        Database::query('UPDATE users SET away = ?, away_at = datetime("now") WHERE id = ?', [$msg, $user['id']]);
        return ['replies' => [$msg === '' ? 'You are now away.' : "You are now away: $msg"]];
    },
]);

CommandRegistry::register('back', [
    'group' => 'Core',
    'desc' => 'Return from being away.',
    'usage' => '/back',
    'run' => function (array $args, array $user, ?array $channel) {
        Database::query('UPDATE users SET away = NULL, away_at = NULL WHERE id = ?', [$user['id']]);
        return ['replies' => ['You are back.']];
    },
]);

CommandRegistry::register('whois', [
    'group' => 'Core',
    'desc' => 'Show information about a user.',
    'usage' => '/whois <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /whois <nick>']];
        }
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t) {
            return ['replies' => ["No such user: $nick"]];
        }
        $online = Auth::isOnline($t) ? 'online' : 'offline';
        $roleTag = match ($t['role']) {
            'admin' => ' (IRC Operator)',
            'staff' => ' (Staff)',
            default => '',
        };
        $lines = [
            h($t['username']) . " — $online" . $roleTag,
            'Registered: ' . date('Y-m-d H:i', strtotime($t['registered_at'] . ' UTC')),
            'Last seen: ' . relative_time($t['last_seen']),
        ];
        if (Auth::isOper($user)) {
            $lines[] = 'IP: ' . ($t['last_ip'] ?: '(none recorded)');
        }
        if ($t['away']) {
            $lines[] = 'Away: ' . h($t['away']);
        }
        $chans = ChannelService::joinedChannelNames($t);
        if ($chans) {
            $lines[] = 'Channels: ' . implode(' ', array_column($chans, 'name'));
        }
        return ['replies' => $lines];
    },
]);

CommandRegistry::register('list', [
    'group' => 'Core',
    'desc' => 'Open the public channel browser.',
    'usage' => '/list',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Opening channel browser...'], 'redirect' => '/browse'];
    },
]);

CommandRegistry::register('channels', [
    'group' => 'Core',
    'desc' => 'Alias of /list (public channel browser).',
    'usage' => '/channels',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Opening channel browser...'], 'redirect' => '/browse'];
    },
]);

CommandRegistry::register('topic', [
    'group' => 'Core',
    'desc' => 'View or set the channel topic.',
    'usage' => '/topic [#channel] [new topic]',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        if (!$args) {
            return ['replies' => ["Topic for " . $channel['name'] . ': ' . ($channel['topic'] ?: '(none)')]];
        }
        $level = AccessService::effectiveLevel($channel['id'], (int) $user['id']);
        if ((int) $channel['topic_locked'] === 1 && level_weight($level) < level_weight('op') && $user['role'] !== 'admin') {
            return ['replies' => ['You must be a channel operator (+o) to change the topic.']];
        }
        $topic = implode(' ', $args);
        $old = $channel['topic'];
        ChannelService::update($channel['id'], ['topic' => mb_substr($topic, 0, 500)]);
        MessageService::system($channel['id'], 'topic', $user['username'] . ' set the topic to: ' . $topic);
        log_audit('topic', $channel['name'], $topic);
        return ['replies' => ["Topic set to: $topic"]];
    },
]);

CommandRegistry::register('ping', [
    'group' => 'Core',
    'desc' => 'Ping the server.',
    'usage' => '/ping',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Pong!']];
    },
]);

CommandRegistry::register('invite', [
    'group' => 'Core',
    'desc' => 'Invite a user to a channel.',
    'usage' => '/invite <nick> [#channel]',
    'needs_channel' => true,
    'min_level' => 3,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /invite <nick> [#channel]']];
        }
        $target = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        Database::query(
            'INSERT OR IGNORE INTO invites (channel_id, user_id, invited_by) VALUES (?, ?, ?)',
            [$channel['id'], $target['id'], $user['id']]
        );
        MessageService::notify((int) $target['id'], 'invite', (int) $channel['id'], (int) $user['id']);
        return ['replies' => ["$nick has been invited to " . $channel['name'] . '.'], 'events' => [
            ['channel_id' => $channel['id'], 'kind' => 'system', 'content' => $user['username'] . ' invited ' . $nick . ' to ' . $channel['name']],
        ]];
    },
]);

CommandRegistry::register('knock', [
    'group' => 'Core',
    'desc' => 'Request access to an invite-only channel.',
    'usage' => '/knock <#channel>',
    'run' => function (array $args, array $user, ?array $channel) {
        $name = $args[0] ?? null;
        if (!$name) {
            return ['replies' => ['Usage: /knock <#channel>']];
        }
        $ch = ChannelService::find($name);
        if (!$ch) {
            return ['replies' => ["No such channel: $name"]];
        }
        $ops = Database::all(
            'SELECT u.id FROM channel_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.channel_id = ? AND cm.level IN ("founder","admin","op")',
            [$ch['id']]
        );
        foreach ($ops as $op) {
            MessageService::notify((int) $op['id'], 'knock', (int) $ch['id'], (int) $user['id']);
        }
        return ['replies' => ["Your knock has been sent to the operators of $name."]];
    },
]);

CommandRegistry::register('ignore', [
    'group' => 'Core',
    'desc' => 'Ignore private messages from a user.',
    'usage' => '/ignore <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /ignore <nick>']];
        }
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t || (int) $t['id'] === (int) $user['id']) {
            return ['replies' => ['Invalid user.']];
        }
        Database::query(
            'INSERT OR IGNORE INTO ignores (user_id, ignored_user_id) VALUES (?, ?)',
            [$user['id'], $t['id']]
        );
        return ['replies' => ["You are now ignoring $nick."]];
    },
]);

CommandRegistry::register('unignore', [
    'group' => 'Core',
    'desc' => 'Stop ignoring a user.',
    'usage' => '/unignore <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /unignore <nick>']];
        }
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if ($t) {
            Database::query('DELETE FROM ignores WHERE user_id = ? AND ignored_user_id = ?', [$user['id'], $t['id']]);
        }
        return ['replies' => ["You are no longer ignoring $nick."]];
    },
]);

CommandRegistry::register('share', [
    'group' => 'Core',
    'desc' => 'Get the shareable link for a channel.',
    'usage' => '/share [#channel]',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        $slug = $channel['slug'];
        return ['replies' => [
            "Shareable link for " . $channel['name'] . ':',
            canonical_channel_url($slug),
        ], 'action' => 'copy', 'copy' => canonical_channel_url($slug)];
    },
]);

CommandRegistry::register('search', [
    'group' => 'Core',
    'desc' => 'Search channels you are in and your private messages.',
    'usage' => '/search <term>',
    'run' => function (array $args, array $user, ?array $channel) {
        $term = trim(implode(' ', $args));
        if ($term === '') {
            return ['replies' => ['Usage: /search <term>']];
        }
        $channels = MessageService::searchChannels($user, $term, 20);
        $dms = MessageService::searchDm($user, $term, 20);
        if (!$channels && !$dms) {
            return ['replies' => ['No results for "' . $term . '".']];
        }
        $replies = ['Results for "' . $term . '":'];
        foreach ($channels as $r) {
            $replies[] = '#' . ($r['channel_slug'] ?? '?') . ' · ' . $r['username'] . ' · ' . MessageService::snippet($r['content'], $term, 45);
        }
        foreach ($dms as $r) {
            $replies[] = 'DM · ' . $r['username'] . ' · ' . MessageService::snippet($r['content'], $term, 45);
        }
        if (count($replies) > 11) {
            $replies = array_merge(array_slice($replies, 0, 10), ['…' . (count($channels) + count($dms) - 9) . ' more matches in the search box.']);
        }
        return ['replies' => $replies];
    },
]);

// Used by /msg handlers above.
final class UserCommands
{
    public static function privateMessage(array $user, string $nick, string $text): ?array
    {
        $target = Auth::findActor($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        $ignoring = Database::row(
            'SELECT 1 FROM ignores WHERE user_id = ? AND ignored_user_id = ?',
            [$user['id'], $target['id']]
        );
        if ($ignoring) {
            return ['replies' => ['You are ignoring that user.']];
        }
        $blockedByTarget = Database::row(
            'SELECT 1 FROM ignores WHERE user_id = ? AND ignored_user_id = ?',
            [$target['id'], $user['id']]
        );
        if ($blockedByTarget) {
            return ['replies' => ["$nick is not accepting private messages from you."]];
        }
        $blocked = BanService::sendBlocked($user, $text, 'p');
        if ($blocked) {
            return ['replies' => [$blocked]];
        }
        $pmId = MessageService::insertPm($user, $target, mb_substr($text, 0, 2000));
        MessageService::logPm((int) $user['id'], $user['username'], $target['username'], mb_substr($text, 0, 2000), MessageService::isGuest($user) ? 1 : 0);
        MessageService::notifyDm($target, $user, $pmId);
        return null;
    }
}

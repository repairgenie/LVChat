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
        // Rate-limit joins to prevent channel flooding.
        $lastJoin = (int) ($_SESSION['last_join_ts'] ?? 0);
        if (time() - $lastJoin < 2) {
            return ['replies' => ['You are joining channels too quickly. Slow down.']];
        }
        $_SESSION['last_join_ts'] = time();
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
            // Guest memberships live in guest_id columns — never touch the
            // user_id rows of a registered user that shares this guest's id.
            if (Auth::isGuest($user)) {
                Database::query('DELETE FROM channel_members WHERE channel_id = ? AND guest_id = ?', [$c['id'], $user['id']]);
            } else {
                Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$c['id'], $user['id']]);
            }
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
        // Respect block lists — notices must be blockable like regular DMs.
        if (!Auth::isGuest($user) && !Auth::isGuest($target)
            && FriendService::isBlockedEither((int) $user['id'], (int) $target['id'])) {
            return ['replies' => ['You cannot message this user.']];
        }
        // Guests cannot send notices to registered users.
        if (Auth::isGuest($user) && !Auth::isGuest($target)) {
            return ['replies' => ['Guests cannot send notices to registered users.']];
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
        // Nick changes write the users table — guests live in `guests` and
        // must not mutate a registered user who shares their numeric id.
        if (Auth::isGuest($user)) {
            return ['replies' => ['Create an account to change your nickname.']];
        }
        $newnick = $args[0] ?? null;
        if (!$newnick || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $newnick)) {
            return ['replies' => ['Usage: /nick <newnick> (2-32 chars, IRC-safe symbols)']];
        }
        if (strtolower($newnick) === strtolower($user['username'])) {
            return ['replies' => ['You already have that nick.']];
        }
        // Rate-limit nick changes to prevent channel flooding.
        $lastNick = (int) ($_SESSION['last_nick_ts'] ?? 0);
        if (time() - $lastNick < 30) {
            return ['replies' => ['Please wait before changing your nickname again.']];
        }
        $_SESSION['last_nick_ts'] = time();
        $exists = Database::scalar('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$newnick]);
        if ($exists) {
            return ['replies' => ["Nickname $newnick is already in use."]];
        }
        $ban = BanService::nickForbidden($newnick);
        if ($ban) {
            return ['replies' => ['That nickname is reserved (' . ($ban['reason'] ?: 'q-lined') . ').']];
        }
        Database::query('UPDATE users SET username = ? WHERE id = ?', [$newnick, $user['id']]);
        // Keep the user's o:line (if any) attached to the new nick, so /oper
        // keeps working after a rename (mirrors /sanick).
        Database::query('UPDATE opers SET username = ? WHERE username = ? COLLATE NOCASE', [$newnick, $user['username']]);
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
        // away writes the users table — reject guests (guest presences are
        // implicit and short-lived; see guests table).
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $msg = implode(' ', $args);
        Database::query(
            'UPDATE users SET away = ?, away_at = datetime("now"), status_mode = \'away\' WHERE id = ?',
            [$msg, $user['id']]
        );
        return ['replies' => [$msg === '' ? 'You are now away.' : "You are now away: $msg"]];
    },
]);

CommandRegistry::register('back', [
    'group' => 'Core',
    'desc' => 'Return from being away.',
    'usage' => '/back',
    'run' => function (array $args, array $user, ?array $channel) {
        // back writes the users table — reject guests (see /away guard).
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        Database::query('UPDATE users SET away = NULL, away_at = NULL, status_mode = \'online\' WHERE id = ?', [$user['id']]);
        return ['replies' => ['You are back.']];
    },
]);

CommandRegistry::register('whois', [
    'group' => 'Core',
    'desc' => 'Show information about a user (registered users and guests).',
    'usage' => '/whois <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /whois <nick>']];
        }
        $t = Auth::findActor($nick);
        if (!$t) {
            return ['replies' => ["No such user: $nick"]];
        }
        $isGuestTarget = (int) ($t['guest'] ?? 0) === 1;
        $si = Auth::statusInfo($t);
        $statusWord = match ($si['status_mode']) {
            'away' => 'away',
            'dnd' => 'Do Not Disturb',
            'invisible' => 'appearing offline',
            'custom' => 'custom status',
            default => 'online',
        };
        $roleTag = $isGuestTarget ? ' (guest)' : match ($t['role']) {
            'admin' => ' (IRC Operator)',
            'staff' => ' (Staff)',
            default => '',
        };
        $lines = [
            h($t['username']) . " — $statusWord" . $roleTag,
        ];
        if ($isGuestTarget) {
            if (!empty($t['last_seen'])) {
                $lines[] = 'Last seen: ' . relative_time($t['last_seen']);
            } else {
                $lines[] = 'Never seen online.';
            }
        } else {
            $lines[] = 'Registered: ' . date('Y-m-d H:i', strtotime($t['registered_at'] . ' UTC'));
            if (!empty($t['last_seen'])) {
                $lines[] = 'Last seen: ' . relative_time($t['last_seen']);
                $idle = max(0, time() - strtotime($t['last_seen'] . ' UTC'));
                if ($idle >= 60) {
                    $lines[] = 'Idle: ' . UserCommands::idleFmt($idle);
                }
            }
            $signon = Database::scalar(
                'SELECT MIN(created_at) FROM sessions WHERE user_id = ? AND expires_at > datetime("now")',
                [(int) $t['id']]
            );
            if ($signon) {
                $lines[] = 'Signon: ' . date('Y-m-d H:i', strtotime($signon . ' UTC'));
            }
        }
        if (Auth::isOper($user)) {
            $lines[] = 'IP: ' . ($t['last_ip'] ?: '(none recorded)');
        }
        if ($si['custom_status'] !== '') {
            $lines[] = 'Status: ' . h($si['custom_status']);
        }
        $chans = ChannelService::visibleChannelNames($t, $user);
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
        return ['replies' => ['Opening channel browser...'], 'action' => 'browse'];
    },
]);

CommandRegistry::register('channels', [
    'group' => 'Core',
    'desc' => 'Alias of /list (public channel browser).',
    'usage' => '/channels',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Opening channel browser...'], 'action' => 'browse'];
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
        $level = AccessService::effectiveLevel($channel['id'], $user);
        if ((int) $channel['topic_locked'] === 1 && level_weight($level) < level_weight('op') && $user['role'] !== 'admin') {
            return ['replies' => ['You must be a channel operator (+o) to change the topic.']];
        }
        // Rate-limit topic changes to prevent flooding.
        $lastTopic = (int) ($_SESSION['last_topic_ts_' . $channel['id']] ?? 0);
        if (time() - $lastTopic < 10) {
            return ['replies' => ['Topic changes are rate-limited. Please wait.']];
        }
        $_SESSION['last_topic_ts_' . $channel['id']] = time();
        $topic = implode(' ', $args);
        $old = $channel['topic'];
        ChannelService::update($channel['id'], ['topic' => mb_substr($topic, 0, 500)]);
        MessageService::system($channel['id'], 'topic', $user['username'] . ' set the topic to: ' . $topic);
        log_audit('topic', $channel['name'], $topic);
        // topic_set/topic_channel let the chat client refresh the header topic
        // instantly for the channel it is currently viewing (no page reload).
        return [
            'replies' => ["Topic set to: $topic"],
            'topic_set' => $topic,
            'topic_channel' => $channel['slug'],
        ];
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

CommandRegistry::register('pong', [
    'group' => 'Core',
    'desc' => 'Reply to a server ping (also a latency check).',
    'usage' => '/pong [token]',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Ping!']];
    },
]);

CommandRegistry::register('version', [
    'group' => 'Core',
    'desc' => 'Show the server software version.',
    'usage' => '/version',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => [h(config_get('site_name', 'LVChat')) . ' ' . LVC_VERSION . ' — a Discord-style IRC web chat with an UnrealIRCd/Anope-style command set.']];
    },
]);

CommandRegistry::register('time', [
    'group' => 'Core',
    'desc' => 'Show the server time (UTC).',
    'usage' => '/time',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => [gmdate('Y-m-d H:i:s') . ' UTC']];
    },
]);

CommandRegistry::register('userhost', [
    'group' => 'Core',
    'desc' => 'Show a user\'s ident@host.',
    'usage' => '/userhost <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /userhost <nick>']];
        }
        $t = Auth::findActor($nick);
        if (!$t) {
            return ['replies' => ["No such user: $nick"]];
        }
        $host = $t['vhost'] ?? $t['last_ip'] ?? 'unknown';
        $ident = Auth::isGuest($t) ? '~' . strtolower($t['username']) : strtolower($t['username']);
        return ['replies' => ["$nick=$ident@$host"]];
    },
]);

CommandRegistry::register('names', [
    'group' => 'Core',
    'desc' => 'List the members of a channel with their mode prefixes.',
    'usage' => '/names [#channel]',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        $members = ChannelService::members($channel['id']);
        if (!$members) {
            return ['replies' => [$channel['name'] . ': (empty)']];
        }
        $list = array_map(
            fn ($m) => ($m['level'] !== 'normal' ? level_symbol($m['level']) : '') . $m['username'],
            $members
        );
        return ['replies' => [$channel['name'] . ' (' . count($members) . '): ' . implode(' ', $list)]];
    },
]);

CommandRegistry::register('who', [
    'group' => 'Core',
    'desc' => 'List online users matching a nick mask (or all users of a #channel).',
    'usage' => '/who [#channel|<mask>]',
    'run' => function (array $args, array $user, ?array $channel) {
        $arg = $args[0] ?? null;
        if ($arg && preg_match('/^[#&]/', $arg)) {
            $ch = ChannelService::find($arg);
            if (!$ch) {
                return ['replies' => ["No such channel: $arg"]];
            }
            if (!AccessService::member($ch['id'], $user)) {
                return ['replies' => ['You must be a member of ' . $arg . ' to see its users.']];
            }
            $members = ChannelService::members($ch['id']);
            $lines = [$ch['name'] . ' (' . count($members) . ' members):'];
            foreach ($members as $m) {
                $lines[] = ($m['level'] !== 'normal' ? level_symbol($m['level']) : '') . $m['username'] . ($m['guest'] ? ' (guest)' : '');
            }
            return ['replies' => $lines];
        }
        $like = '%' . str_replace(['*', '?'], ['%', '_'], strtolower($arg ?? '*')) . '%';
        $rows = Database::all(
            'SELECT username, role, status_mode, away FROM users
             WHERE username LIKE ? COLLATE NOCASE AND last_seen >= datetime("now", "-30 seconds")
             ORDER BY username',
            [$like]
        );
        $guests = Database::all(
            'SELECT nick FROM guests WHERE nick LIKE ? COLLATE NOCASE AND last_seen >= datetime("now", "-30 seconds")
             ORDER BY nick',
            [$like]
        );
        if (!$rows && !$guests) {
            return ['replies' => ['No users match ' . ($arg ?: '*') . '.']];
        }
        $lines = ['Who match (' . (count($rows) + count($guests)) . ' online):'];
        foreach ($rows as $u) {
            $pref = $u['role'] === 'admin' ? '*' : '';
            $st = $u['status_mode'] === 'away' ? ' [away]' : ($u['status_mode'] === 'dnd' ? ' [dnd]' : '');
            $lines[] = $pref . $u['username'] . $st;
        }
        foreach ($guests as $g) {
            $lines[] = $g['nick'] . ' (guest)';
        }
        return ['replies' => $lines];
    },
]);

CommandRegistry::register('whowas', [
    'group' => 'Core',
    'desc' => 'Show the last-seen record for a nickname.',
    'usage' => '/whowas <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /whowas <nick>']];
        }
        $t = Auth::findActor($nick);
        if (!$t) {
            // Not currently known — fall back to the append-only chat archive.
            $r = Database::row(
                'SELECT created_at FROM chat_logs WHERE username = ? COLLATE NOCASE ORDER BY id DESC LIMIT 1',
                [$nick]
            );
            if ($r) {
                return ['replies' => ["$nick was last active " . date('Y-m-d H:i', strtotime($r['created_at'] . ' UTC')) . ' UTC (from the chat archive).']];
            }
            return ['replies' => ["No such user: $nick"]];
        }
        $when = !empty($t['last_seen']) ? relative_time($t['last_seen']) : 'never';
        $kind = Auth::isGuest($t) ? ' (guest)' : '';
        return ['replies' => ["$nick$kind was last seen " . $when . '.']];
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
        // Invites reference registered user ids only (invited_by, sender_id) —
        // reject guests to avoid id-collision writes.
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $target = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        if ((int) $target['id'] === (int) $user['id']) {
            return ['replies' => ['You cannot invite yourself.']];
        }
        // Respect block lists — a blocked user should not receive invite notifications.
        if (FriendService::isBlockedEither((int) $user['id'], (int) $target['id'])) {
            return ['replies' => ['You cannot invite this user.']];
        }
        // A per-user mute silences that person's invites (bell + push) too.
        if (PushService::isMuted((int) $target['id'], (int) $user['id'])) {
            return ['replies' => ["$nick cannot receive your invites."]];
        }
        Database::query(
            'INSERT OR IGNORE INTO invites (channel_id, user_id, invited_by) VALUES (?, ?, ?)',
            [$channel['id'], $target['id'], $user['id']]
        );
        MessageService::notify((int) $target['id'], 'invite', (int) $channel['id'], (int) $user['id']);
        PushService::invite((int) $target['id'], (int) $channel['id'], (int) $user['id']);
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
        // Knock notifications store the sender in a users-keyed column.
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $ch = ChannelService::find($name);
        if (!$ch) {
            return ['replies' => ["No such channel: $name"]];
        }
        // Rate-limit knocks to 1 per 60 seconds per channel.
        $knockKey = 'knock_' . $ch['id'];
        $lastKnock = (int) ($_SESSION[$knockKey] ?? 0);
        if (time() - $lastKnock < 60) {
            return ['replies' => ['You recently knocked on this channel. Please wait before trying again.']];
        }
        $_SESSION[$knockKey] = time();
        $ops = Database::all(
            'SELECT u.id FROM channel_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.channel_id = ? AND cm.level IN ("founder","admin","op")',
            [$ch['id']]
        );
        foreach ($ops as $op) {
            if (PushService::isMuted((int) $op['id'], (int) $user['id'])) {
                continue;
            }
            MessageService::notify((int) $op['id'], 'knock', (int) $ch['id'], (int) $user['id']);
        }
        return ['replies' => ["Your knock has been sent to the operators of $name."]];
    },
]);

CommandRegistry::register('ignore', [
    'group' => 'Core',
    'desc' => 'Block a user (prevents DMs and hides their messages).',
    'usage' => '/ignore <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /ignore <nick>']];
        }
        if ((int) ($user['guest'] ?? 0) === 1) {
            return ['replies' => ['Registered users only.']];
        }
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t || (int) $t['id'] === (int) $user['id']) {
            return ['replies' => ['Invalid user.']];
        }
        FriendService::blockUser((int) $user['id'], (int) $t['id']);
        return ['replies' => ["You have blocked $nick."]];
    },
]);

CommandRegistry::register('unignore', [
    'group' => 'Core',
    'desc' => 'Unblock a user.',
    'usage' => '/unignore <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /unignore <nick>']];
        }
        if ((int) ($user['guest'] ?? 0) === 1) {
            return ['replies' => ['Registered users only.']];
        }
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if ($t) {
            FriendService::unblockUser((int) $user['id'], (int) $t['id']);
        }
        return ['replies' => ["You have unblocked $nick."]];
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
        // Rate-limit search to prevent database exhaustion.
        $lastSearch = (int) ($_SESSION['last_search_ts'] ?? 0);
        if (time() - $lastSearch < 3) {
            return ['replies' => ['Search is rate-limited. Please wait.']];
        }
        $_SESSION['last_search_ts'] = time();
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
    public static function idleFmt(int $seconds): string
    {
        if ($seconds >= 86400) {
            return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
        }
        if ($seconds >= 3600) {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }
        return floor($seconds / 60) . 'm';
    }

    public static function privateMessage(array $user, string $nick, string $text): ?array
    {
        $target = Auth::findActor($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        if ((int) ($user['guest'] ?? 0) !== 1 && (int) ($target['guest'] ?? 0) !== 1 && FriendService::isBlockedEither((int) $user['id'], (int) $target['id'])) {
            return ['replies' => ['A block prevents messaging between you.']];
        }
        // Guests cannot DM registered users.
        if ((int) ($user['guest'] ?? 0) === 1 && (int) ($target['guest'] ?? 0) !== 1) {
            return ['replies' => ['Guests cannot send private messages to registered users.']];
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

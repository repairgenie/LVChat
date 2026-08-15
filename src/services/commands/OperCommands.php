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

// ─── OperServ / IRCop commands (o:lines grant operator classes) ─────────────

CommandRegistry::register('oper', [
    'group' => 'OperServ',
    'desc' => 'Oper up against your o:line and gain your operator class permissions.',
    'usage' => '/oper <username> <password>',
    'run' => function (array $args, array $user, ?array $channel) {
        if ($user['role'] === 'admin') {
            return ['replies' => ['You are already an IRC Operator (server admin).']];
        }
        $name = $args[0] ?? '';
        $pw = $args[1] ?? '';
        if (strtolower($name) !== strtolower($user['username'])) {
            return ['replies' => ['You may only oper the account that matches your nickname.']];
        }
        $op = Database::row('SELECT * FROM opers WHERE username = ? COLLATE NOCASE', [$name]);
        if (!$op || (int) $op['enabled'] !== 1 || !password_verify($pw, $op['password_hash'])) {
            return ['replies' => ['Incorrect oper credentials.']];
        }
        $class = Database::row('SELECT * FROM operclasses WHERE id = ?', [$op['operclass_id']]);
        if (!$class) {
            return ['replies' => ['Your operator class no longer exists.']];
        }
        $_SESSION['operclass_id'] = (int) $class['id'];
        log_audit('oper', $user['username'], 'operclass: ' . $class['name']);
        return ['replies' => ["You are now operating as " . $class['name'] . ". /deoper to drop operator status."]];
    },
]);

CommandRegistry::register('deoper', [
    'group' => 'OperServ',
    'desc' => 'Drop your operator status.',
    'usage' => '/deoper',
    'run' => function (array $args, array $user, ?array $channel) {
        Auth::deoper();
        return ['replies' => ['You are no longer operating.']];
    },
]);

foreach (['kline' => 'IP/account-wide kill ban', 'gline' => 'global ban', 'zline' => 'z-line (severe) ban', 'shun' => 'mute (cannot speak)'] as $kind => $desc) {
    CommandRegistry::register($kind, [
        'group' => 'OperServ',
        'desc' => "Add a $desc: /$kind <mask|nick|ip|ip/cidr> <duration> <reason>",
        'usage' => "/$kind <mask|nick|ip|ip/cidr> <duration> <reason>",
        'server_admin' => true,
        'run' => function (array $args, array $user, ?array $channel) use ($kind) {
            $target = $args[0] ?? null;
            if (!$target) {
                return ['replies' => ["Usage: /$kind <mask|nick|ip|ip/cidr> <duration> <reason>"]];
            }
            $duration = null;
            $reason = '';
            $rest = array_slice($args, 1);
            if ($rest) {
                $duration = parse_duration($rest[0]);
                if ($duration !== null) {
                    array_shift($rest);
                }
                $reason = implode(' ', $rest);
            }
            $isIp = (bool) preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $target);
            $userId = null;
            if ($isIp) {
                // Keep the IP/CIDR as the ban mask.
                $target = strtolower($target);
            } elseif (!preg_match('/[*!@?]/', $target)) {
                $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$target]);
                if ($t) {
                    $userId = (int) $t['id'];
                    if ($kind === 'zline' && !empty($t['last_ip'])) {
                        $target = $t['last_ip']; // zline by resolved IP
                    } else {
                        $target = strtolower($t['username']) . '!*@*';
                    }
                }
            }
            // Block overly broad masks that would affect everyone.
            if ($target === '*@*' || $target === '*!*@*' || $target === '*') {
                return ['replies' => ['This mask is too broad and would affect all users.']];
            }
            // Enforce a maximum ban duration of 30 days to prevent accidental
            // permanent locks.  Operators may re-apply if needed.
            if ($duration !== null && $duration > 30 * 86400) {
                $duration = 30 * 86400;
            }
            $err = BanService::addBan($kind, null, $target, $reason, $duration, (int) $user['id'], $userId);
            if ($err) {
                return ['replies' => [$err]];
            }
            $dur = $duration !== null ? ($duration >= 3600 ? floor($duration / 3600) . 'h' : floor($duration / 60) . 'm') : 'permanent';
            if ($userId) {
                $tu = Database::row('SELECT * FROM users WHERE id = ?', [$userId]);
                if ($tu) {
                    ModerationService::record($tu, $kind, 'applied', $target, $reason, '', null);
                    ModerationService::note($userId, $user, $kind, $reason !== '' ? $reason : 'no reason');
                }
            }
            if ($kind === 'shun' && $userId) {
                // shun blocks messaging, so don't kick
            } elseif ($isIp || ($userId === null && in_array($kind, ['kline', 'gline', 'zline'], true))) {
                // Kick any online users whose recorded IP matches the ban.
                foreach (Database::all('SELECT * FROM users WHERE last_ip IS NOT NULL') as $u) {
                    if (Auth::ipMatch($target, (string) $u['last_ip'])) {
                        op_force_kick((int) $u['id'], "Banned (" . ($reason ?: $kind) . ')', $user);
                    }
                }
            } elseif ($userId) {
                op_force_kick((int) $userId, "Banned (" . ($reason ?: $kind) . ')', $user);
            }
            log_audit($kind . '_add', $target, "$dur / " . ($reason ?: 'no reason'));
            return ['replies' => ["$target has been " . strtoupper($kind) . "d for $dur" . ($reason ? ": $reason" : '') . '.']];
        },
    ]);
}

foreach (['unkline' => 'kline', 'ungline' => 'gline', 'unzline' => 'zline', 'unshun' => 'shun'] as $name => $kind) {
    CommandRegistry::register($name, [
        'group' => 'OperServ',
        'desc' => "Remove a $kind.",
        'usage' => "/$name <mask|nick>",
        'server_admin' => true,
        'run' => function (array $args, array $user, ?array $channel) use ($kind, $name) {
            $target = $args[0] ?? null;
            if (!$target) {
                return ['replies' => ["Usage: /$name <mask|nick>"]];
            }
            $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$target]);
            $removed = 0;
            if ($t) {
                $removed += Database::query('DELETE FROM bans WHERE kind = ? AND target_user_id = ?', [$kind, $t['id']])->rowCount();
            }
            $removed += Database::query('DELETE FROM bans WHERE kind = ? AND mask = ? COLLATE NOCASE', [$kind, $target])->rowCount();
            if ($t) {
                $removed += Database::query('DELETE FROM bans WHERE kind = ? AND mask LIKE ? COLLATE NOCASE', [$kind, strtolower($target) . '!*@*'])->rowCount();
            }
            log_audit($name, $target);
            return ['replies' => [$removed > 0 ? "$target removed from $kind." : "No matching $kind for $target."]];
        },
    ]);
}

CommandRegistry::register('kill', [
    'group' => 'OperServ',
    'desc' => 'Ban and disconnect a user.',
    'usage' => '/kill <nick> <reason>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $reason = implode(' ', array_slice($args, 1)) ?: 'Banned by an operator';
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t) {
            return ['replies' => ["No such user: $nick"]];
        }
        if ((int) $t['id'] === (int) $user['id']) {
            return ['replies' => ['You cannot kill yourself.']];
        }
        // Rate-limit kills to prevent kill-flooding.
        $lastKill = (int) ($_SESSION['last_kill_ts'] ?? 0);
        if (time() - $lastKill < 10) {
            return ['replies' => ['Please wait before using /kill again.']];
        }
        $_SESSION['last_kill_ts'] = time();
        op_force_kick((int) $t['id'], 'Killed: ' . $reason, $user);
        ModerationService::note((int) $t['id'], $user, 'kline', $reason);
        Database::query('DELETE FROM sessions WHERE user_id = ?', [$t['id']]);
        log_audit('kill', $nick, $reason);
        return ['replies' => ["$nick has been killed ($reason)."]];
    },
]);

CommandRegistry::register('global', [
    'group' => 'OperServ',
    'desc' => 'Announce a message to every channel.',
    'usage' => '/global <message>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $msg = implode(' ', $args);
        if ($msg === '') {
            return ['replies' => ['Usage: /global <message>']];
        }
        // Rate-limit global announcements to prevent flooding.
        $lastGlobal = (int) ($_SESSION['last_global_ts'] ?? 0);
        if (time() - $lastGlobal < 60) {
            return ['replies' => ['Please wait before sending another global announcement.']];
        }
        $_SESSION['last_global_ts'] = time();
        // Run the message through the badword filter.
        $censor = CensorService::check($msg, true);
        if ($censor && $censor['action'] === 'block') {
            return ['replies' => ['Message blocked by the word filter.']];
        }
        if ($censor) {
            $msg = $censor['censored'];
        }
        foreach (Database::all('SELECT id, name FROM channels') as $c) {
            MessageService::system($c['id'], 'system', '[GLOBAL] ' . $user['username'] . ': ' . $msg);
        }
        log_audit('global', null, $msg);
        return ['replies' => ['Announcement sent to all channels.']];
    },
]);

CommandRegistry::register('wallops', [
    'group' => 'OperServ',
    'desc' => 'Alias of /global.',
    'usage' => '/wallops <message>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $reg = CommandRegistry::get('global');
        return call_user_func($reg['run'], $args, $user, $channel);
    },
]);

CommandRegistry::register('motd', [
    'group' => 'OperServ',
    'desc' => 'View the server MOTD (admins may set it).',
    'usage' => '/motd [set <text>]',
    'run' => function (array $args, array $user, ?array $channel) {
        if (($args[0] ?? '') === 'set' && $user['role'] === 'admin') {
            // Join with spaces so the command reads naturally, but honour
            // explicit "\n" sequences so a multi-line MOTD can be set in-chat.
            $text = str_replace('\\n', "\n", implode(' ', array_slice($args, 1)));
            config_set('motd', $text);
            log_audit('motd', null, 'updated');
            return ['replies' => ['MOTD updated.']];
        }
        // One line per reply. The lines are NOT pre-escaped: the client renders
        // replies through linkify(), which HTML-escapes (escaping here too would
        // double-escape & < > and mangle URLs/HTML in the MOTD text).
        return ['replies' => explode("\n", (string) config_get('motd', ''))];
    },
]);

CommandRegistry::register('sajoin', [
    'group' => 'OperServ',
    'desc' => 'Force a user to join a channel.',
    'usage' => '/sajoin <nick> <#channel>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $name = $args[1] ?? null;
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        $ch = $name ? ChannelService::find($name) : null;
        if (!$t || !$ch) {
            return ['replies' => ['Usage: /sajoin <nick> <#channel>']];
        }
        ChannelService::join($ch, $t);
        log_audit('sajoin', $nick, $ch['name']);
        return ['replies' => ["Forced $nick to join " . $ch['name'] . '.']];
    },
]);

CommandRegistry::register('sapart', [
    'group' => 'OperServ',
    'desc' => 'Force a user to leave a channel.',
    'usage' => '/sapart <nick> <#channel>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $name = $args[1] ?? null;
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        $ch = $name ? ChannelService::find($name) : null;
        if (!$t || !$ch) {
            return ['replies' => ['Usage: /sapart <nick> <#channel>']];
        }
        Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$ch['id'], $t['id']]);
        ChannelService::afterMemberRemoval($ch['id']);
        MessageService::system($ch['id'], 'kick', $nick . ' was removed by ' . $user['username'] . ' (SAKICK)');
        log_audit('sapart', $nick, $ch['name']);
        return ['replies' => ["Forced $nick out of " . $ch['name'] . '.']];
    },
]);

CommandRegistry::register('samode', [
    'group' => 'OperServ',
    'desc' => 'Force a channel mode change.',
    'usage' => '/samode <#channel> <+/-modes> [args]',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $name = array_shift($args);
        $ch = $name ? ChannelService::find($name) : null;
        if (!$ch) {
            return ['replies' => ['Usage: /samode <#channel> <+/-modes> [args]']];
        }
        log_audit('samode', $ch['name'], implode(' ', $args));
        $reg = CommandRegistry::get('mode');
        return call_user_func($reg['run'], array_merge([$args[0] ?? ''], array_slice($args, 1)), $user, $ch);
    },
]);

CommandRegistry::register('sanick', [
    'group' => 'OperServ',
    'desc' => 'Force-rename a registered user.',
    'usage' => '/sanick <oldnick> <newnick>',
    'server_admin' => true,
    'netadmin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $oldnick = $args[0] ?? null;
        $newnick = $args[1] ?? null;
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$oldnick]);
        if (!$t || !$newnick || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $newnick)) {
            return ['replies' => ['Usage: /sanick <oldnick> <newnick>']];
        }
        // Availability: not registered to another user, not held by a live guest,
        // and not a forbidden nickname.
        if (Database::scalar('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$newnick])) {
            return ['replies' => ['Requested nick is unavailable, please select another']];
        }
        $guest = Database::row('SELECT * FROM guests WHERE nick = ? COLLATE NOCASE', [$newnick]);
        if ($guest && Auth::guestInUse($guest)) {
            return ['replies' => ['Requested nick is unavailable, please select another']];
        }
        if (BanService::nickForbidden($newnick)) {
            return ['replies' => ['Requested nick is unavailable, please select another']];
        }
        Database::query('UPDATE users SET username = ? WHERE id = ?', [$newnick, $t['id']]);
        // Keep the target's o:line (if any) attached to the new nick.
        Database::query('UPDATE opers SET username = ? WHERE username = ? COLLATE NOCASE', [$newnick, $oldnick]);
        $renamed = Database::row('SELECT * FROM users WHERE id = ?', [$t['id']]);
        foreach (ChannelService::joinedChannelNames($t) as $c) {
            $id = MessageService::system($c['id'], 'nick', $oldnick . ' is now known as ' . $newnick . ' (SANICK)');
            Realtime::message($c['slug'], [
                'id' => $id,
                'kind' => 'nick',
                'content' => $oldnick . ' is now known as ' . $newnick . ' (SANICK)',
                'channel' => $c['slug'],
                'sender_id' => null,
                'username' => null,
                'guest' => 0,
            ]);
        }
        // Notify the renamed user directly (persists in their DM history).
        $notice = "Your nickname has been changed to $newnick by {$user['username']} (SANICK).";
        $pmId = MessageService::insertPm($user, $renamed, $notice);
        MessageService::logPm((int) $user['id'], $user['username'], $renamed['username'], $notice, 0);
        MessageService::notifyDm($renamed, $user, $pmId);
        $pmMessage = [
            'id' => $pmId,
            'kind' => 'message',
            'content' => $notice,
            'created_at' => now(),
            'username' => $user['username'],
            'sender_id' => (int) $user['id'],
            'role' => $user['role'],
            'guest' => 0,
            'level' => 'normal',
            'is_pm' => true,
        ];
        Realtime::dm($user, $renamed, $pmMessage);
        Realtime::bell($renamed);
        log_audit('sanick', $oldnick, '-> ' . $newnick);
        return ['replies' => ["$oldnick is now known as $newnick."]];
    },
]);

CommandRegistry::register('sasethost', [
    'group' => 'OperServ',
    'desc' => 'Force-set a user vhost.',
    'usage' => '/sasethost <nick> <host>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $host = $args[1] ?? null;
        $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t || !$host) {
            return ['replies' => ['Usage: /sasethost <nick> <host>']];
        }
        // Validate the host string — same rules as the user-facing /vhost set.
        if (!preg_match('/^[A-Za-z0-9.\-]{3,60}$/', $host)) {
            return ['replies' => ['Host must be 3-60 chars using letters, numbers, dots, and hyphens.']];
        }
        Database::query('UPDATE users SET vhost = ? WHERE id = ?', [$host, $t['id']]);
        log_audit('sasethost', $nick, $host);
        return ['replies' => ["$nick vhost set to $host."]];
    },
]);

CommandRegistry::register('sqline', [
    'group' => 'OperServ',
    'desc' => 'Reserve/forbid a nickname.',
    'usage' => '/sqline <nick> [reason]',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /sqline <nick> [reason]']];
        }
        $reason = implode(' ', array_slice($args, 1));
        BanService::addBan('qline', null, $nick, $reason, null, (int) $user['id']);
        // Disconnect anyone currently using the forbidden nick.
        $u = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if ($u) {
            op_force_kick((int) $u['id'], 'Nickname forbidden (' . ($reason ?: 'q-lined') . ')', $user);
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
        }
        $g = Database::row('SELECT * FROM guests WHERE nick = ? COLLATE NOCASE', [$nick]);
        if ($g && Auth::guestInUse($g)) {
            Database::query('DELETE FROM guest_sessions WHERE guest_id = ?', [$g['id']]);
        }
        log_audit('sqline', $nick, $reason ?: 'no reason');
        return ['replies' => ["Nickname $nick is now forbidden."]];
    },
]);

CommandRegistry::register('unsqline', [
    'group' => 'OperServ',
    'desc' => 'Un-forbid a nickname.',
    'usage' => '/unsqline <nick>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        if (!$nick) {
            return ['replies' => ['Usage: /unsqline <nick>']];
        }
        $removed = Database::query(
            "DELETE FROM bans WHERE kind = 'qline' AND channel_id IS NULL AND mask = ? COLLATE NOCASE",
            [$nick]
        )->rowCount();
        log_audit('unsqline', $nick);
        return ['replies' => [$removed > 0 ? "$nick removed from the forbidden nick list." : "No q-line for $nick."]];
    },
]);

CommandRegistry::register('sqlines', [
    'group' => 'OperServ',
    'desc' => 'List forbidden nicknames.',
    'usage' => '/sqlines',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $rows = BanService::activeBans('qline');
        if (!$rows) {
            return ['replies' => ['No forbidden nicknames.']];
        }
        return ['replies' => array_map(
            fn ($b) => h($b['mask']) . ' — ' . ($b['reason'] ?: 'no reason') . ' (by ' . h($b['set_by_name'] ?? 'system') . ')',
            $rows
        )];
    },
]);

foreach (['cqline' => 'forbid a channel name', 'uncqline' => 'un-forbid a channel name', 'cqlines' => 'list forbidden channel names'] as $name => $desc) {
    CommandRegistry::register($name, [
        'group' => 'OperServ',
        'desc' => ucfirst($desc) . ($name === 'cqlines' ? '' : ': /' . $name . ' <#channel> [reason]'),
        'usage' => $name === 'cqlines' ? '/cqlines' : "/$name <#channel> [reason]",
        'server_admin' => true,
        'run' => function (array $args, array $user, ?array $channel) use ($name) {
            if ($name === 'cqlines') {
                $rows = BanService::activeBans('cqline');
                if (!$rows) {
                    return ['replies' => ['No forbidden channel names.']];
                }
                return ['replies' => array_map(
                    fn ($b) => h($b['mask']) . ' — ' . ($b['reason'] ?: 'no reason') . ' (by ' . h($b['set_by_name'] ?? 'system') . ')',
                    $rows
                )];
            }
            $chan = $args[0] ?? null;
            if (!$chan || !preg_match('/^[#&]/', $chan)) {
                return ['replies' => ["Usage: /$name <#channel> [reason]"]];
            }
            if ($name === 'cqline') {
                $reason = implode(' ', array_slice($args, 1));
                BanService::addBan('cqline', null, $chan, $reason, null, (int) $user['id']);
                log_audit('cqline', $chan, $reason ?: 'no reason');
                return ['replies' => ["Channel name $chan is now forbidden."]];
            }
            $removed = Database::query(
                "DELETE FROM bans WHERE kind = 'cqline' AND channel_id IS NULL AND (mask = ? COLLATE NOCASE OR mask = ? COLLATE NOCASE)",
                [$chan, ltrim($chan, '#&')]
            )->rowCount();
            log_audit('uncqline', $chan);
            return ['replies' => [$removed > 0 ? "$chan removed from the forbidden channel list." : "No c-line for $chan."]];
        },
    ]);
}

CommandRegistry::register('spamfilter', [
    'group' => 'OperServ',
    'desc' => 'Manage spam filters.',
    'usage' => '/spamfilter <add <match> [reason]|del <id>|list>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'list');
        switch ($sub) {
            case 'add':
                $match = $args[1] ?? null;
                if (!$match) {
                    return ['replies' => ['Usage: /spamfilter add <match> [reason]']];
                }
                $reason = implode(' ', array_slice($args, 2));
                Database::query(
                    'INSERT INTO spamfilters (match_type, targets, action, reason, match) VALUES ("simple", "cpntu", "block", ?, ?)',
                    [$reason, $match]
                );
                log_audit('spamfilter_add', $match, $reason ?: 'no reason');
                return ['replies' => ['Spam filter added.']];
            case 'del':
                $id = (int) ($args[1] ?? 0);
                Database::query('DELETE FROM spamfilters WHERE id = ?', [$id]);
                log_audit('spamfilter_del', '#' . $id);
                return ['replies' => ['Spam filter removed.']];
            case 'list':
                $rows = Database::all('SELECT * FROM spamfilters WHERE enabled = 1');
                if (!$rows) {
                    return ['replies' => ['No active spam filters.']];
                }
                return ['replies' => array_map(fn ($f) => "#{$f['id']} [{$f['match_type']}] " . h($f['match']) . ($f['reason'] ? ' — ' . h($f['reason']) : ''), $rows)];
            default:
                return ['replies' => ['Usage: /spamfilter <add <match> [reason]|del <id>|list>']];
        }
    },
]);

CommandRegistry::register('clients', [
    'group' => 'OperServ',
    'desc' => 'List users currently online.',
    'usage' => '/clients',
    'run' => function (array $args, array $user, ?array $channel) {
        $online = Database::all(
            'SELECT username, role FROM users WHERE last_seen >= datetime("now", "-30 seconds") AND away IS NULL ORDER BY username'
        );
        if (!$online) {
            return ['replies' => ['Nobody is online right now.']];
        }
        $lines = ['Online users (' . count($online) . '):'];
        foreach ($online as $u) {
            $lines[] = $u['username'] . ($u['role'] === 'admin' ? ' (*)' : '');
        }
        return ['replies' => $lines];
    },
]);

CommandRegistry::register('serverstats', [
    'group' => 'OperServ',
    'desc' => 'Show server statistics.',
    'usage' => '/serverstats',
    'run' => function (array $args, array $user, ?array $channel) {
        $stats = [
            'Users' => Database::scalar('SELECT COUNT(*) FROM users'),
            'Channels' => Database::scalar('SELECT COUNT(*) FROM channels'),
            'Messages logged' => Database::scalar('SELECT COUNT(*) FROM messages'),
            'Private messages' => Database::scalar('SELECT COUNT(*) FROM private_messages'),
            'Active global bans' => Database::scalar('SELECT COUNT(*) FROM bans WHERE channel_id IS NULL AND active = 1'),
            'Spam filters' => Database::scalar('SELECT COUNT(*) FROM spamfilters WHERE enabled = 1'),
            'Audit events' => Database::scalar('SELECT COUNT(*) FROM audit_log'),
        ];
        return ['replies' => array_map(fn ($k, $v) => "$k: $v", array_keys($stats), array_values($stats))];
    },
]);

CommandRegistry::register('rehash', [
    'group' => 'OperServ',
    'desc' => 'Reload server configuration.',
    'usage' => '/rehash',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        log_audit('rehash');
        return ['replies' => ['Configuration reloaded.']];
    },
]);

CommandRegistry::register('badword', [
    'group' => 'OperServ',
    'desc' => 'Manage the global bad-word filter.',
    'usage' => '/badword <add <word> [censor|block]|del <id>|list|toggle <id>>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'list');
        switch ($sub) {
            case 'add':
                $word = $args[1] ?? null;
                $action = strtolower($args[2] ?? 'censor');
                if (!$word || !in_array($action, ['censor', 'block'], true)) {
                    return ['replies' => ['Usage: /badword add <word> [censor|block]']];
                }
                Database::query('INSERT INTO badwords (word, action) VALUES (?, ?)', [strtolower($word), $action]);
                log_audit('badword_add', $word, $action);
                return ['replies' => ["Bad word '$word' added (action: $action)."]];
            case 'del':
                $id = (int) ($args[1] ?? 0);
                Database::query('DELETE FROM badwords WHERE id = ?', [$id]);
                log_audit('badword_del', '#' . $id);
                return ['replies' => ['Bad word removed.']];
            case 'toggle':
                $id = (int) ($args[1] ?? 0);
                Database::query('UPDATE badwords SET enabled = 1 - enabled WHERE id = ?', [$id]);
                log_audit('badword_toggle', '#' . $id);
                return ['replies' => ['Bad word toggled.']];
            case 'list':
                $rows = Database::all('SELECT * FROM badwords ORDER BY id DESC');
                if (!$rows) {
                    return ['replies' => ['No bad words configured.']];
                }
                return ['replies' => array_map(
                    fn ($w) => "#{$w['id']} [{$w['action']}] " . ($w['enabled'] ? '' : '[off] ') . h($w['word']),
                    $rows
                )];
            default:
                return ['replies' => ['Usage: /badword <add <word> [censor|block]|del <id>|list|toggle <id>>']];
        }
    },
]);

// Shared helper for this file. When $actor is given, the removal is recorded on
// the target's staff-only moderation timeline.
function op_force_kick(int $userId, string $reason, ?array $actor = null): void
{
    foreach (Database::all('SELECT cm.channel_id, c.name FROM channel_members cm JOIN channels c ON c.id = cm.channel_id WHERE cm.user_id = ?', [$userId]) as $r) {
        MessageService::system($r['channel_id'], 'kick', 'user#' . $userId . ' was removed (' . $reason . ')');
        Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$r['channel_id'], $userId]);
        ChannelService::afterMemberRemoval($r['channel_id']);
    }
    if ($actor) {
        ModerationService::note($userId, $actor, 'kick', $reason);
    }
}

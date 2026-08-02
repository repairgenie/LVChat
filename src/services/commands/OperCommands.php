<?php

declare(strict_types=1);

// ─── OperServ / IRCop commands (server administrators) ───────────────────────

CommandRegistry::register('oper', [
    'group' => 'OperServ',
    'desc' => 'Oper up (grant yourself IRCop / admin rights).',
    'usage' => '/oper <username> <password>',
    'run' => function (array $args, array $user, ?array $channel) {
        if ($user['role'] === 'admin') {
            return ['replies' => ['You are already an IRC Operator.']];
        }
        $operPw = config_get('oper_password', '');
        if ($operPw === '') {
            return ['replies' => ['No operator password is configured. Contact the server owner.']];
        }
        $pw = $args[1] ?? '';
        $name = $args[0] ?? '';
        if (strtolower($name) === strtolower($user['username']) && $pw !== '' && password_verify($pw, $user['password_hash']) === false && hash_equals($operPw, $pw) === false) {
            return ['replies' => ['Incorrect operator password.']];
        }
        if (strtolower($name) !== strtolower($user['username'])) {
            return ['replies' => ['You may only oper your own account.']];
        }
        if (!hash_equals($operPw, $pw)) {
            return ['replies' => ['Incorrect operator password.']];
        }
        Database::query('UPDATE users SET role = "admin" WHERE id = ?', [$user['id']]);
        log_audit('oper', $user['username'], 'promoted to IRCop via /oper');
        return ['replies' => ['You are now an IRC Operator. Full admin dashboard access granted.']];
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
            $err = BanService::addBan($kind, null, $target, $reason, $duration, (int) $user['id'], $userId);
            if ($err) {
                return ['replies' => [$err]];
            }
            $dur = $duration !== null ? ($duration >= 3600 ? floor($duration / 3600) . 'h' : floor($duration / 60) . 'm') : 'permanent';
            if ($kind === 'shun' && $userId) {
                // shun blocks messaging, so don't kick
            } elseif ($isIp || ($kind === 'zline' && !$userId)) {
                // Kick any online users whose recorded IP matches the ban.
                foreach (Database::all('SELECT * FROM users WHERE last_ip IS NOT NULL') as $u) {
                    if (Auth::ipMatch($target, (string) $u['last_ip'])) {
                        op_force_kick((int) $u['id'], "Banned (" . ($reason ?: $kind) . ')');
                    }
                }
            } elseif ($userId) {
                op_force_kick((int) $userId, "Banned (" . ($reason ?: $kind) . ')');
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
        op_force_kick((int) $t['id'], 'Killed: ' . $reason);
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
            $text = implode(' ', array_slice($args, 1));
            config_set('motd', $text);
            log_audit('motd', null, 'updated');
            return ['replies' => ['MOTD updated.']];
        }
        return ['replies' => array_map('h', explode("\n", (string) config_get('motd', '')))];
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
        $reg = CommandRegistry::get('mode');
        return call_user_func($reg['run'], array_merge([$args[0] ?? ''], array_slice($args, 1)), $user, $ch);
    },
]);

CommandRegistry::register('sanick', [
    'group' => 'OperServ',
    'desc' => 'Force-rename a user.',
    'usage' => '/sanick <nick> <newnick>',
    'server_admin' => true,
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? null;
        $newnick = $args[1] ?? null;
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t || !$newnick || !preg_match('/^[A-Za-z0-9_\-\[\]\\`^{}|]{2,32}$/', $newnick)) {
            return ['replies' => ['Usage: /sanick <nick> <newnick>']];
        }
        if (Database::scalar('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$newnick])) {
            return ['replies' => ["$newnick is already in use."]];
        }
        Database::query('UPDATE users SET username = ? WHERE id = ?', [$newnick, $t['id']]);
        foreach (ChannelService::joinedChannelNames((int) $t['id']) as $c) {
            MessageService::system($c['id'], 'nick', $nick . ' is now known as ' . $newnick . ' (SANICK)');
        }
        return ['replies' => ["$nick is now known as $newnick."]];
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
        Database::query('UPDATE users SET vhost = ? WHERE id = ?', [$host, $t['id']]);
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
        return ['replies' => ["Nickname $nick is now forbidden."]];
    },
]);

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
                return ['replies' => ['Spam filter added.']];
            case 'del':
                $id = (int) ($args[1] ?? 0);
                Database::query('DELETE FROM spamfilters WHERE id = ?', [$id]);
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
                return ['replies' => ["Bad word '$word' added (action: $action)."]];
            case 'del':
                $id = (int) ($args[1] ?? 0);
                Database::query('DELETE FROM badwords WHERE id = ?', [$id]);
                return ['replies' => ['Bad word removed.']];
            case 'toggle':
                $id = (int) ($args[1] ?? 0);
                Database::query('UPDATE badwords SET enabled = 1 - enabled WHERE id = ?', [$id]);
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

// Shared helper for this file.
function op_force_kick(int $userId, string $reason): void
{
    foreach (Database::all('SELECT cm.channel_id, c.name FROM channel_members cm JOIN channels c ON c.id = cm.channel_id WHERE cm.user_id = ?', [$userId]) as $r) {
        MessageService::system($r['channel_id'], 'kick', 'user#' . $userId . ' was removed (' . $reason . ')');
        Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$r['channel_id'], $userId]);
        ChannelService::afterMemberRemoval($r['channel_id']);
    }
}

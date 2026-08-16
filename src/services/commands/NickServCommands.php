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

// ─── NickServ commands ──────────────────────────────────────────────────────

CommandRegistry::register('ns', [
    'group' => 'NickServ',
    'desc' => 'Alias: prefix a NickServ command, e.g. /ns set password hunter2',
    'usage' => '/ns <command> [args]',
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = array_shift($args);
        if (!$sub) {
            return ['replies' => ['Usage: /ns <command> [args]']];
        }
        if (!CommandRegistry::get($sub)) {
            return ['replies' => ["Unknown NickServ command: $sub"]];
        }
        // Re-enter the parser so the TARGET command's gates are enforced —
        // never call its handler directly.
        return CommandParser::run('/' . $sub . ($args ? ' ' . implode(' ', $args) : ''), $user, $channel);
    },
]);

CommandRegistry::register('register', [
    'group' => 'NickServ / ChanServ',
    'desc' => 'Register a channel, or confirm your account is registered.',
    'usage' => '/register <#channel>   |   /register <email> <password>',
    'run' => function (array $args, array $user, ?array $channel) {
        $name = $args[0] ?? null;
        if ($name && preg_match('/^[#&]/', $name)) {
            // Channel registration writes users-keyed rows (owner_id, user_id,
            // founder membership) — guests must never claim a channel via a
            // colliding guest id.
            if (Auth::isGuest($user)) {
                return ['replies' => ['Create an account to register a channel.']];
            }
            $ch = ChannelService::find($name);
            if (!$ch) {
                $created = ChannelService::create($user, $name);
                if (is_string($created)) {
                    return ['replies' => [$created]];
                }
                Database::query('UPDATE channels SET registered_at = datetime("now") WHERE id = ?', [$created['id']]);
                log_audit('channel_register', $name);
                return ['replies' => ["Channel $name registered. You are the founder. It will now persist even when empty."], 'redirect' => '/c/' . rawurlencode($created['slug'])];
            }
            if ((int) $ch['owner_id'] === (int) $user['id'] || (int) $ch['owner_id'] === 0 || $user['role'] === 'admin') {
                if (ChannelService::isRegistered($ch)) {
                    // Registered but unowned (e.g. a seeded default channel): an
                    // admin may claim it so it shows under their "My Channels".
                    if ((int) $ch['owner_id'] === 0 && $user['role'] === 'admin') {
                        Database::query('UPDATE channels SET owner_id = ? WHERE id = ?', [$user['id'], $ch['id']]);
                        Database::query(
                            'INSERT INTO channel_members (channel_id, user_id, level) VALUES (?, ?, "founder")
                             ON CONFLICT DO UPDATE SET level = "founder"',
                            [$ch['id'], $user['id']]
                        );
                        log_audit('channel_register', $name);
                        return ['replies' => ["$name is now registered to you. You are the founder."]];
                    }
                    return ['replies' => ["$name is already registered."]];
                }
                // Registering claims the channel: an unowned channel becomes the
                // registerer's (owner_id + founder level). Registering someone
                // else's owned channel just marks it registered without stealing.
                if ((int) $ch['owner_id'] === 0 || (int) $ch['owner_id'] === (int) $user['id']) {
                    Database::query('UPDATE channels SET owner_id = ?, registered_at = datetime("now") WHERE id = ?', [$user['id'], $ch['id']]);
                    Database::query(
                        'INSERT INTO channel_members (channel_id, user_id, level) VALUES (?, ?, "founder")
                         ON CONFLICT DO UPDATE SET level = "founder"',
                        [$ch['id'], $user['id']]
                    );
                    log_audit('channel_register', $name);
                    return ['replies' => ["Channel $name is now registered. You are the founder. It will persist even when empty."]];
                }
                Database::query('UPDATE channels SET registered_at = datetime("now") WHERE id = ?', [$ch['id']]);
                log_audit('channel_register', $name);
                return ['replies' => ["Channel $name is now registered."]];
            }
            return ['replies' => ["$name belongs to someone else and cannot be registered."]];
        }
        return ['replies' => [
            'You already have an account (' . $user['username'] . ').',
            'To change your password use: /set password <newpassword>',
        ]];
    },
]);

CommandRegistry::register('logout', [
    'group' => 'NickServ',
    'desc' => 'Log out of your session.',
    'usage' => '/logout',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Logging out...'], 'redirect' => '/logout'];
    },
]);

CommandRegistry::register('identify', [
    'group' => 'NickServ / ChanServ',
    'desc' => 'Verify your password, or join a keyed channel.',
    'usage' => '/identify <password>   |   /identify <#channel> <key>',
    'run' => function (array $args, array $user, ?array $channel) {
        if (!empty($args[0]) && preg_match('/^[#&]/', $args[0])) {
            $name = array_shift($args);
            $key = $args[0] ?? null;
            $ch = ChannelService::find($name);
            if (!$ch) {
                return ['replies' => ["No such channel: $name"]];
            }
            $status = ChannelService::joinStatus($ch, $user, $key);
            if ($status['ok']) {
                if ($status['reason'] !== 'already_member') {
                    ChannelService::join($ch, $user);
                }
                return ['replies' => ["Now in $name."], 'redirect' => '/c/' . rawurlencode($ch['slug'])];
            }
            return ['replies' => [$status['reason']]];
        }
        $pw = $args[0] ?? '';
        if (password_verify($pw, $user['password_hash'])) {
            return ['replies' => ['Password verified. You are identified as ' . $user['username'] . '.']];
        }
        return ['replies' => ['Incorrect password.']];
    },
]);

CommandRegistry::register('ghost', [
    'group' => 'NickServ',
    'desc' => 'Terminate your other sessions.',
    'usage' => '/ghost <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        // Ghosting kills sessions keyed on the users table — guests must not
        // terminate a registered user's sessions via a colliding guest id.
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $nick = $args[0] ?? $user['username'];
        if (strtolower($nick) !== strtolower($user['username'])) {
            return ['replies' => ['You can only ghost your own nick.']];
        }
        Auth::killSessions((int) $user['id']);
        return ['replies' => ['Other sessions for ' . $user['username'] . ' have been terminated.']];
    },
]);

CommandRegistry::register('release', [
    'group' => 'NickServ',
    'desc' => 'Release your nick (terminate other sessions and reclaim it).',
    'usage' => '/release <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $nick = $args[0] ?? $user['username'];
        if (strtolower($nick) !== strtolower($user['username'])) {
            return ['replies' => ['You can only release your own nick.']];
        }
        Auth::killSessions((int) $user['id']);
        return ['replies' => ["Nick $nick released."]];
    },
]);

CommandRegistry::register('recover', [
    'group' => 'NickServ',
    'desc' => 'Recover your nick (same as /release).',
    'usage' => '/recover <nick>',
    'run' => function (array $args, array $user, ?array $channel) {
        if (Auth::isGuest($user)) {
            return ['replies' => ['Registered users only.']];
        }
        $nick = $args[0] ?? $user['username'];
        Auth::killSessions((int) $user['id']);
        return ['replies' => ["$nick recovered."]];
    },
]);

CommandRegistry::register('status', [
    'group' => 'NickServ',
    'desc' => 'Show account status for a nick.',
    'usage' => '/status [nick]',
    'run' => function (array $args, array $user, ?array $channel) {
        $nick = $args[0] ?? $user['username'];
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
        if (!$t) {
            return ['replies' => ["No such user: $nick"]];
        }
        $state = Auth::isOnline($t) ? 'online' : 'offline';
        $isAdmin = $t['role'] === 'admin' ? ' (IRC Operator)' : '';
        return ['replies' => ["$nick is registered, $state$isAdmin."]];
    },
]);

CommandRegistry::register('info', [
    'group' => 'NickServ / Core',
    'desc' => 'Show server info, or account info for a nick.',
    'usage' => '/info [nick]',
    'run' => function (array $args, array $user, ?array $channel) {
        if (!empty($args[0])) {
            $nick = $args[0];
            $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
            if (!$t) {
                return ['replies' => ["No such user: $nick"]];
            }
            if (strtolower($nick) !== strtolower($user['username']) && $user['role'] !== 'admin') {
                return ['replies' => ['Account info is only visible to yourself (and admins).']];
            }
            $channels = count(ChannelService::joinedChannelNames($t));
            $memos = (int) Database::scalar('SELECT COUNT(*) FROM memos WHERE recipient_id = ? AND read_at IS NULL', [$t['id']]);
            return ['replies' => [
                'Nick: ' . h($t['username']) . '  |  Email: ' . h($t['email']),
                'Registered: ' . date('Y-m-d H:i', strtotime($t['registered_at'] . ' UTC')),
                "In $channels channel(s) | Unread memos: $memos | Vhost: " . ($t['vhost'] ?: 'none'),
            ]];
        }
        $users = (int) Database::scalar('SELECT COUNT(*) FROM users');
        $online = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE last_seen >= datetime("now", "-30 seconds") AND away IS NULL');
        $channels = (int) Database::scalar('SELECT COUNT(*) FROM channels');
        $msgs = (int) Database::scalar('SELECT COUNT(*) FROM messages');
        return ['replies' => [
            h(config_get('site_name', 'LVChat')) . ' — a Discord-style IRC web chat',
            "Users: $users  |  Online: $online  |  Channels: $channels  |  Messages logged: $msgs",
            'Type /help for a full command list.',
        ]];
    },
]);

CommandRegistry::register('group', [
    'group' => 'NickServ',
    'desc' => 'Grouping is automatic — your nick and account are the same.',
    'usage' => '/group',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => ['Accounts and nicks are unified on this server — no grouping needed.']];
    },
]);

CommandRegistry::register('rename', [
    'group' => 'NickServ',
    'desc' => 'Rename your account (same as /nick).',
    'usage' => '/rename <newnick>',
    'run' => function (array $args, array $user, ?array $channel) {
        $reg = CommandRegistry::get('nick');
        return call_user_func($reg['run'], $args, $user, $channel);
    },
]);

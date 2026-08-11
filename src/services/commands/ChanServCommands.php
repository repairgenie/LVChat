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

// ─── ChanServ commands ──────────────────────────────────────────────────────

CommandRegistry::register('drop', [
    'group' => 'ChanServ',
    'desc' => 'Delete a channel (founder only).',
    'usage' => '/drop <#channel>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        $name = $channel['name'];
        $res = ChannelService::delete($channel['id'], $user);
        if ($res !== true) {
            return ['replies' => [$res]];
        }
        return ['replies' => ["Channel $name has been dropped."], 'redirect' => '/app'];
    },
]);

CommandRegistry::register('unregister', [
    'group' => 'ChanServ',
    'desc' => 'Deregister a channel — it becomes temporary and disappears when empty.',
    'usage' => '/unregister <#channel>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            return ['replies' => ['Only the channel founder can unregister this channel.']];
        }
        if (!ChannelService::isRegistered($channel)) {
            return ['replies' => [$channel['name'] . ' is not registered.']];
        }
        Database::query('UPDATE channels SET registered_at = NULL WHERE id = ?', [$channel['id']]);
        ChannelService::afterMemberRemoval($channel['id']);
        log_audit('channel_unregister', $channel['name']);
        return ['replies' => ["Channel {$channel['name']} is no longer registered. It will disappear when the last person leaves."]];
    },
]);

CommandRegistry::register('set', [
    'group' => 'NickServ / ChanServ',
    'desc' => 'Set an account option, or configure a channel you own.',
    'usage' => '/set <email|password|hide> <value>   |   /set <#channel> <founder|password|desc|topic|private|secret|visibility|successor|mlock> <value>',
    'run' => function (array $args, array $user, ?array $channel) {
        // NickServ mode: account options when the first argument is not a channel.
        if (empty($args[0]) || !preg_match('/^[#&]/', $args[0])) {
            $opt = strtolower($args[0] ?? '');
            $val = implode(' ', array_slice($args, 1));
            switch ($opt) {
                case 'email':
                    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        return ['replies' => ['A valid email is required.']];
                    }
                    Database::query('UPDATE users SET email = ? WHERE id = ?', [$val, $user['id']]);
                    return ['replies' => ['Email updated.']];
                case 'password':
                    if (strlen($val) < 8) {
                        return ['replies' => ['Password must be at least 8 characters.']];
                    }
                    Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($val, PASSWORD_ARGON2ID), $user['id']]);
                    return ['replies' => ['Password updated.']];
                case 'hide':
                    $on = in_array(strtolower($val), ['on', '1', 'true', 'yes'], true);
                    $cur = (string) $user['vhost'];
                    if ($on && str_contains($cur, '|hide')) {
                        return ['replies' => ['You already hide your online status.']];
                    }
                    Database::query('UPDATE users SET vhost = ? WHERE id = ?', [$on ? ($cur . '|hide') : str_replace('|hide', '', $cur), $user['id']]);
                    return ['replies' => ['Status hidden: ' . ($on ? 'on' : 'off') . '.']];
                default:
                    return ['replies' => ['Usage: /set <email|password|hide> <value>  or  /set <#channel> <option> <value>']];
            }
        }

        // ChanServ mode.
        $name = array_shift($args);
        $channel = ChannelService::find($name);
        if (!$channel) {
            return ['replies' => ["No such channel: $name"]];
        }
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            return ['replies' => ['Only the channel founder can use /set on this channel.']];
        }
        $option = strtolower($args[0] ?? '');
        $rest = array_slice($args, 1);
        $value = implode(' ', $rest);
        switch ($option) {
            case 'founder':
                $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$rest[0] ?? '']);
                if (!$t) {
                    return ['replies' => ['No such user.']];
                }
                if (!AccessService::member($channel['id'], (int) $t['id'])) {
                    return ['replies' => ['The new founder must be a member of the channel.']];
                }
                Database::query('UPDATE channels SET owner_id = ? WHERE id = ?', [$t['id'], $channel['id']]);
                AccessService::setLevel($channel['id'], (int) $t['id'], 'founder');
                AccessService::setLevel($channel['id'], (int) $user['id'], 'admin');
                MessageService::system($channel['id'], 'system', $user['username'] . ' transferred founder status to ' . $t['username']);
                return ['replies' => ["Founder status transferred to " . $t['username'] . '.']];
            case 'password':
            case 'key':
                ChannelService::setKey($channel['id'], $value === '' ? null : $value);
                return ['replies' => [$value === '' ? 'Channel key removed.' : 'Channel key set.']];
            case 'desc':
            case 'description':
                ChannelService::update($channel['id'], ['description' => mb_substr($value, 0, 300)]);
                return ['replies' => ['Description updated.']];
            case 'topic':
                ChannelService::update($channel['id'], ['topic' => mb_substr($value, 0, 500)]);
                MessageService::system($channel['id'], 'topic', $user['username'] . ' set the topic to: ' . $value);
                return ['replies' => ['Topic updated.']];
            case 'private':
            case 'secret':
                $on = in_array(strtolower($value), ['on', '1', 'true', 'yes'], true);
                $vis = $option === 'private' ? 'private' : 'secret';
                ChannelService::update($channel['id'], ['visibility' => $on ? $vis : 'public']);
                return ['replies' => ["Channel is now " . ($on ? $vis : 'public') . ". Private channels stay hidden from /list but are joinable via their share link."]];
                case 'visibility':
                    $v = strtolower($value);
                    if (!in_array($v, ['public', 'private', 'secret', 'staff'], true)) {
                        return ['replies' => ['Visibility must be public, private, secret or staff.']];
                    }
                    if ($v === 'staff' && $user['role'] !== 'admin') {
                        return ['replies' => ['Only server admins can set a staff-only channel.']];
                    }
                    ChannelService::update($channel['id'], ['visibility' => $v]);
                    return ['replies' => ["Channel visibility set to $v."]];
            case 'successor':
                $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$rest[0] ?? '']);
                ChannelService::update($channel['id'], ['successor_id' => $t ? $t['id'] : null]);
                return ['replies' => ['Successor set.']];
            case 'secureops':
            case 'keepmodes':
            case 'restrictmsg':
                $on = in_array(strtolower($value), ['on', '1', 'true', 'yes'], true) ? '1' : '0';
                $cur = $channel['mlock'];
                $cur = preg_replace('/(^|,)' . $option . '=\d/', '', $cur);
                $cur = trim($cur . ',' . $option . '=' . $on, ',');
                ChannelService::update($channel['id'], ['mlock' => $cur]);
                return ['replies' => ["$option is now " . ($on === '1' ? 'on' : 'off') . '.']];
            case 'mlock':
                ChannelService::update($channel['id'], ['mlock' => mb_substr($value, 0, 64)]);
                return ['replies' => ["Mode lock set to: $value"]];
            default:
                return ['replies' => ['Unknown option. Usage: /set <#channel> <founder|password|desc|topic|private|secret|visibility|successor|mlock> <value>']];
        }
    },
]);

CommandRegistry::register('access', [
    'group' => 'ChanServ',
    'desc' => 'Manage the persistent channel access list.',
    'usage' => '/access <#channel> <list|add <nick> <level>|del <nick>|clear>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'list');
        switch ($sub) {
            case 'list':
                $rows = AccessService::accessList($channel['id']);
                if (!$rows) {
                    return ['replies' => ['Access list is empty.']];
                }
                return ['replies' => array_map(
                    fn ($r) => $r['username'] . ' — ' . $r['level'] . ' (by ' . (($r['added_by'] ? 'user#' . $r['added_by'] : 'system') . ')'),
                    $rows
                )];
            case 'add':
                $nick = $args[1] ?? null;
                $level = strtolower($args[2] ?? '');
                if (!$nick || !in_array($level, ['admin', 'op', 'halfop', 'voice'], true)) {
                    return ['replies' => ['Usage: /access <#channel> add <nick> <admin|op|halfop|voice>']];
                }
                $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
                if (!$t) {
                    return ['replies' => ["No such user: $nick"]];
                }
                $check = AccessService::canSetLevel($channel, $user, $t, $level);
                if ($check !== true) {
                    return ['replies' => [$check]];
                }
                AccessService::addAccess($channel['id'], (int) $t['id'], $level, (int) $user['id']);
                return ['replies' => ["Added $nick to the access list as $level."]];
            case 'del':
                $nick = $args[1] ?? null;
                $t = $nick ? Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]) : null;
                if (!$t) {
                    return ['replies' => ["No such user: $nick"]];
                }
                AccessService::removeAccess($channel['id'], (int) $t['id']);
                return ['replies' => ["Removed $nick from the access list."]];
            case 'clear':
                Database::query('DELETE FROM channel_access WHERE channel_id = ?', [$channel['id']]);
                return ['replies' => ['Access list cleared.']];
            default:
                return ['replies' => ['Usage: /access <#channel> <list|add|del|clear>']];
        }
    },
]);

CommandRegistry::register('akick', [
    'group' => 'ChanServ',
    'desc' => 'Manage automatic kick-bans on a channel.',
    'usage' => '/akick <#channel> <add <nick|mask> [reason]|del <nick|mask>|list|clear>',
    'needs_channel' => true,
    'min_level' => 4,
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'list');
        switch ($sub) {
            case 'list':
                $rows = Database::all('SELECT * FROM akick WHERE channel_id = ? ORDER BY added_at DESC', [$channel['id']]);
                if (!$rows) {
                    return ['replies' => ['AKICK list is empty.']];
                }
                return ['replies' => array_map(fn ($r) => h($r['target']) . ($r['reason'] ? ' — ' . h($r['reason']) : ''), $rows)];
            case 'add':
                $target = $args[1] ?? null;
                if (!$target) {
                    return ['replies' => ['Usage: /akick <#channel> add <nick|mask> [reason]']];
                }
                $reason = implode(' ', array_slice($args, 2));
                $userId = null;
                $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$target]);
                if ($t) {
                    $userId = (int) $t['id'];
                }
                Database::query(
                    'INSERT INTO akick (channel_id, target, target_user_id, reason, added_by) VALUES (?, ?, ?, ?, ?)',
                    [$channel['id'], $target, $userId, $reason, $user['id']]
                );
                if ($userId) {
                    $mem = AccessService::member($channel['id'], $userId);
                    if ($mem && level_weight($mem['level']) < level_weight($user['id'] === (int) $user['id'] ? 'op' : 'normal')) {
                        Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$channel['id'], $userId]);
                        ChannelService::afterMemberRemoval($channel['id']);
                    }
                }
                return ['replies' => ["$target added to the AKICK list."]];
            case 'del':
                $target = $args[1] ?? null;
                if (!$target) {
                    return ['replies' => ['Usage: /akick del <nick|mask>']];
                }
                $t = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$target]);
                if ($t) {
                    Database::query('DELETE FROM akick WHERE channel_id = ? AND (target_user_id = ? OR target = ?)', [$channel['id'], $t['id'], $target]);
                } else {
                    Database::query('DELETE FROM akick WHERE channel_id = ? AND target = ? COLLATE NOCASE', [$channel['id'], $target]);
                }
                return ['replies' => ["Removed $target from the AKICK list."]];
            case 'clear':
                Database::query('DELETE FROM akick WHERE channel_id = ?', [$channel['id']]);
                return ['replies' => ['AKICK list cleared.']];
            default:
                return ['replies' => ['Usage: /akick <#channel> <add|del|list|clear>']];
        }
    },
]);

CommandRegistry::register('transfer', [
    'group' => 'ChanServ',
    'desc' => 'Transfer channel founder status.',
    'usage' => '/transfer <#channel> <newfounder>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        if (empty($args[0])) {
            return ['replies' => ['Usage: /transfer <#channel> <newfounder>']];
        }
        $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$args[0]]);
        if (!$t) {
            return ['replies' => ['No such user.']];
        }
        if (!AccessService::member($channel['id'], (int) $t['id'])) {
            return ['replies' => ['The new founder must be a member.']];
        }
        Database::query('UPDATE channels SET owner_id = ? WHERE id = ?', [$t['id'], $channel['id']]);
        AccessService::setLevel($channel['id'], (int) $t['id'], 'founder');
        AccessService::setLevel($channel['id'], (int) $user['id'], 'admin');
        MessageService::system($channel['id'], 'system', $user['username'] . ' transferred founder status to ' . $t['username']);
        return ['replies' => ["Founder status transferred to " . $t['username'] . '.']];
    },
]);

CommandRegistry::register('getkey', [
    'group' => 'ChanServ',
    'desc' => 'Check whether a channel key is set.',
    'usage' => '/getkey <#channel>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        if (empty($channel['key_hash'])) {
            return ['replies' => ['This channel has no key set.']];
        }
        return ['replies' => ['A channel key is set. Use /mode <#channel> -k to remove it, or /mode <#channel> +k <newkey> to change it.']];
    },
]);

CommandRegistry::register('senak', [
    'group' => 'ChanServ',
    'desc' => 'Send a notice to all channel operators.',
    'usage' => '/senak <#channel> <message>',
    'needs_channel' => true,
    'min_level' => 3,
    'run' => function (array $args, array $user, ?array $channel) {
        $msg = implode(' ', $args);
        if ($msg === '') {
            return ['replies' => ['Usage: /senak <#channel> <message>']];
        }
        $ops = Database::all(
            'SELECT u.id FROM channel_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.channel_id = ? AND cm.level IN ("founder","admin","op") AND cm.user_id != ?',
            [$channel['id'], $user['id']]
        );
        foreach ($ops as $op) {
            Database::query(
                'INSERT INTO notifications (user_id, kind, channel_id, sender_id, message_id) VALUES (?, "notice", ?, ?, NULL)',
                [$op['id'], $channel['id'], $user['id']]
            );
        }
        MessageService::system($channel['id'], 'notice', '[SENAK] ' . $user['username'] . ' -> channel ops: ' . $msg);
        return ['replies' => ["Notice sent to " . count($ops) . " channel operator(s)."]];
    },
]);

CommandRegistry::register('chaninfo', [
    'group' => 'ChanServ',
    'desc' => 'Show detailed channel information.',
    'usage' => '/chaninfo <#channel>',
    'needs_channel' => true,
    'min_level' => 0,
    'run' => function (array $args, array $user, ?array $channel) {
        $owner = Database::row('SELECT username FROM users WHERE id = ?', [$channel['owner_id']]);
        $count = ChannelService::memberCount($channel['id']);
        $lines = [
            h($channel['name']) . ' — ' . h($channel['description'] ?: '(no description)'),
            'Topic: ' . h($channel['topic'] ?: '(none)'),
            "Founder: " . ($owner['username'] ?? 'none') . "  |  Members: $count",
            'Visibility: ' . $channel['visibility'] . ($channel['invite_only'] ? '  |  invite-only' : '') . ($channel['moderated'] ? '  |  moderated' : ''),
            'Registered: ' . ($channel['registered_at'] ? date('Y-m-d H:i', strtotime($channel['registered_at'] . ' UTC')) : '(not registered)'),
        ];
        return ['replies' => $lines];
    },
]);

CommandRegistry::register('forbid', [
    'group' => 'ChanServ',
    'desc' => 'Forbid the channel (nobody may join).',
    'usage' => '/forbid <#channel>',
    'needs_channel' => true,
    'min_level' => 5,
    'run' => function (array $args, array $user, ?array $channel) {
        $on = in_array(strtolower($args[0] ?? 'on'), ['off', '0', 'no'], true) ? 0 : 1;
        ChannelService::update($channel['id'], ['forbidden' => $on]);
        return ['replies' => [$on ? 'Channel forbidden — nobody can join until it is lifted.' : 'Channel un-forbidden.']];
    },
]);

CommandRegistry::register('cs', [
    'group' => 'ChanServ',
    'desc' => 'Alias: prefix a ChanServ command, e.g. /cs set #chan topic hi',
    'usage' => '/cs <command> [args]',
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = array_shift($args);
        if (!$sub) {
            return ['replies' => ['Usage: /cs <command> [args]']];
        }
        $reg = CommandRegistry::get($sub);
        if (!$reg) {
            return ['replies' => ["Unknown ChanServ command: $sub"]];
        }
        return call_user_func($reg['run'], $args, $user, $channel);
    },
]);

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

// ─── MemoServ commands ──────────────────────────────────────────────────────

CommandRegistry::register('memo', [
    'group' => 'MemoServ',
    'desc' => 'Send and manage offline memos.',
    'usage' => '/memo <send <nick> <message>|read [id]|list|del <id>|summary|set <notify|silent>>',
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'list');
        $me = (int) $user['id'];
        switch ($sub) {
            case 'send':
                $nick = $args[1] ?? null;
                $msg = implode(' ', array_slice($args, 2));
                if (!$nick || $msg === '') {
                    return ['replies' => ['Usage: /memo send <nick> <message>']];
                }
                $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
                if (!$t) {
                    return ['replies' => ["No such user: $nick"]];
                }
                Database::query(
                    'INSERT INTO memos (recipient_id, sender_id, content) VALUES (?, ?, ?)',
                    [$t['id'], $me, mb_substr($msg, 0, 1000)]
                );
                return ['replies' => ["Memo sent to $nick."]];
            case 'read':
                $id = (int) ($args[1] ?? 0);
                if ($id) {
                    $memo = Database::row('SELECT * FROM memos WHERE id = ? AND recipient_id = ?', [$id, $me]);
                    if (!$memo) {
                        return ['replies' => ['No such memo.']];
                    }
                    Database::query('UPDATE memos SET read_at = datetime("now") WHERE id = ?', [$id]);
                    return ['replies' => ["Memo #{$memo['id']} from " . ($memo['sender_id'] ? 'user#' . $memo['sender_id'] : 'system') . ' on ' . date('Y-m-d H:i', strtotime($memo['created_at'] . ' UTC')) . ':', $memo['content']]];
                }
                $unread = Database::all('SELECT * FROM memos WHERE recipient_id = ? AND read_at IS NULL ORDER BY id DESC', [$me]);
                if (!$unread) {
                    return ['replies' => ['You have no unread memos.']];
                }
                $lines = ['Unread memos:'];
                foreach ($unread as $m) {
                    $s = $m['sender_id'] ? Database::scalar('SELECT username FROM users WHERE id = ?', [$m['sender_id']]) : 'system';
                    $lines[] = "#{$m['id']} from $s on " . date('Y-m-d H:i', strtotime($m['created_at'] . ' UTC')) . ': ' . mb_substr($m['content'], 0, 60);
                }
                return ['replies' => $lines];
            case 'list':
                $rows = Database::all('SELECT * FROM memos WHERE recipient_id = ? ORDER BY id DESC LIMIT 50', [$me]);
                if (!$rows) {
                    return ['replies' => ['You have no memos.']];
                }
                return ['replies' => array_map(
                    fn ($m) => '#'.$m['id'].' ' . ($m['read_at'] ? '[read]' : '[unread]') . ' from ' . ($m['sender_id'] ? (Database::scalar('SELECT username FROM users WHERE id = ?', [$m['sender_id']]) ?: '?') : 'system'),
                    $rows
                )];
            case 'del':
                $id = (int) ($args[1] ?? 0);
                Database::query('DELETE FROM memos WHERE id = ? AND recipient_id = ?', [$id, $me]);
                return ['replies' => ['Memo deleted.']];
            case 'summary':
                $unread = (int) Database::scalar('SELECT COUNT(*) FROM memos WHERE recipient_id = ? AND read_at IS NULL', [$me]);
                $total = (int) Database::scalar('SELECT COUNT(*) FROM memos WHERE recipient_id = ?', [$me]);
                return ['replies' => ["$unread unread of $total total memos."]];
            case 'set':
                $mode = strtolower($args[1] ?? '');
                if (!in_array($mode, ['notify', 'silent'], true)) {
                    return ['replies' => ['Usage: /memo set <notify|silent>']];
                }
                return ['replies' => ["Memo notifications are $mode."]];
            default:
                return ['replies' => ['Usage: /memo <send|read|list|del|summary|set>']];
        }
    },
]);

CommandRegistry::register('ms', [
    'group' => 'MemoServ',
    'desc' => 'Alias of /memo.',
    'usage' => '/ms <command> [args]',
    'run' => function (array $args, array $user, ?array $channel) {
        $reg = CommandRegistry::get('memo');
        return call_user_func($reg['run'], $args, $user, $channel);
    },
]);

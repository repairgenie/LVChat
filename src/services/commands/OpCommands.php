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

// ─── Channel operator commands (halfop +) ───────────────────────────────────

$opHelp = [
    'kick' => ['desc' => 'Kick a user from the channel.', 'usage' => '/kick <nick> [reason]'],
    'kickban' => ['desc' => 'Kick a user and ban them from the channel.', 'usage' => '/kickban <nick> [reason]'],
    'ban' => ['desc' => 'Ban a mask or nick from the channel.', 'usage' => '/ban <mask|nick> [duration] [reason]'],
    'unban' => ['desc' => 'Remove a ban from the channel.', 'usage' => '/unban <mask|nick>'],
    'quiet' => ['desc' => 'Mute a user in the channel (cannot speak).', 'usage' => '/quiet <nick> [duration]'],
    'op' => ['desc' => 'Give channel operator status.', 'usage' => '/op <nick>'],
    'deop' => ['desc' => 'Remove channel operator status.', 'usage' => '/deop <nick>'],
    'halfop' => ['desc' => 'Give half-operator status.', 'usage' => '/halfop <nick>'],
    'dehalfop' => ['desc' => 'Remove half-operator status.', 'usage' => '/dehalfop <nick>'],
    'voice' => ['desc' => 'Give voice (can speak in +m).', 'usage' => '/voice <nick>'],
    'devoice' => ['desc' => 'Remove voice.', 'usage' => '/devoice <nick>'],
    'mode' => ['desc' => 'View or change channel modes (i invite, m moderated, C word filter, k key, l limit, t topic lock, p private, s secret, b ban).', 'usage' => '/mode [#channel] [+/-modes] [args]'],
    'topiclock' => ['desc' => 'Lock or unlock the topic to ops.', 'usage' => '/topiclock [#channel] on|off'],
    'clear' => ['desc' => 'Clear channel state.', 'usage' => '/clear <users|bans|ops|voices|topic|modes>'],
];

// Minimum channel level required per operator command (half-op = 2, op = 3).
$opLevels = [
    'kick' => 2, 'kickban' => 2, 'ban' => 2, 'unban' => 2, 'quiet' => 2,
    'op' => 3, 'deop' => 3, 'halfop' => 3, 'dehalfop' => 3,
    'voice' => 2, 'devoice' => 2, 'mode' => 2, 'topiclock' => 2, 'clear' => 0,
];
foreach ($opHelp as $name => $meta) {
    $isClear = $name === 'clear';
    CommandRegistry::register($name, array_merge($meta, [
        'group' => 'Channel Ops',
        'needs_channel' => !$isClear,
        'min_level' => $isClear ? 0 : ($opLevels[$name] ?? 2),
        'run' => function (array $args, array $user, ?array $channel) use ($name, $isClear) {
            // Bare /clear is the client-side "clear my window" command.
            if ($isClear && $args === []) {
                return ['replies' => ['Chat cleared.'], 'action' => 'clear'];
            }
            return OpCommands::dispatch($name, $args, $user, $channel);
        },
    ]));
}

final class OpCommands
{
    public static function dispatch(string $name, array $args, array $user, array $channel): array
    {
        return match ($name) {
            'kick' => self::kick($args, $user, $channel),
            'kickban' => self::kickban($args, $user, $channel),
            'ban' => self::ban($args, $user, $channel),
            'unban' => self::unban($args, $user, $channel),
            'quiet' => self::quiet($args, $user, $channel),
            'op', 'deop', 'halfop', 'dehalfop', 'voice', 'devoice' => self::level($name, $args, $user, $channel),
            'mode' => self::mode($args, $user, $channel),
            'topiclock' => self::topiclock($args, $user, $channel),
            'clear' => self::clear($args, $user, $channel),
            default => ['replies' => ['Unknown op command.']],
        };
    }

    /** Server admins and IRC operators bypass channel-level guards. */
    private static function isStaff(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || Auth::isOper($user);
    }

    /** Resolve a target by nick — registered user OR channel guest. */
    private static function targetUser(?string $nick): ?array
    {
        if (!$nick) {
            return null;
        }
        return Auth::findActor($nick) ?: null;
    }

    /** Delete the target's membership row (user or guest). */
    private static function removeMember(int|string $channelId, array $target): void
    {
        if (Auth::isGuest($target)) {
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND guest_id = ?', [$channelId, (int) $target['id']]);
        } else {
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, (int) $target['id']]);
        }
    }

    private static function kick(array $args, array $user, array $channel): array
    {
        $nick = $args[0] ?? null;
        $reason = implode(' ', array_slice($args, 1));
        $target = self::targetUser($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        $member = AccessService::member($channel['id'], $target);
        if (!$member) {
            return ['replies' => ["$nick is not in " . $channel['name'] . '.']];
        }
        if (!self::isStaff($user)) {
            $actorLvl = level_weight(AccessService::effectiveLevel($channel['id'], $user));
            $targetLvl = level_weight($member['level']);
            if ($targetLvl >= $actorLvl) {
                return ['replies' => ["You cannot kick $nick (at or above your level)."]];
            }
        }
        self::removeMember($channel['id'], $target);
        ChannelService::afterMemberRemoval($channel['id']);
        $msg = $user['username'] . ' kicked ' . $nick . ' from ' . $channel['name'];
        if ($reason) {
            $msg .= ' (' . $reason . ')';
        }
        // One-shot removal notice: the target's next poll shows the reason (with
        // who did it) before the redirect drops them out. WS clients get the same
        // text bounced inline by Realtime::memberRemoved below.
        ChannelService::recordRemoval($channel, $target, $user['username'] . ' kicked you from ' . $channel['name'] . ($reason ? ' (' . $reason . ')' : ''));
        log_audit('kick', $channel['name'], $nick . ($reason ? " / $reason" : ''));
        if (!Auth::isGuest($target)) {
            ModerationService::record($target, 'kick', 'applied', $channel['name'], $reason, 'c', (int) $channel['id']);
            ModerationService::note((int) $target['id'], $user, 'kick', $channel['name'] . ($reason ? ' — ' . $reason : ''));
        }
        // Bounce the target's clients out of the channel right away, with the
        // reason, so the removal is immediate and never silent (WS mode).
        Realtime::memberRemoved($target, $channel['slug'], $user['username'] . ' kicked you from ' . $channel['name'] . ($reason ? ' (' . $reason . ')' : ''));
        return [
            'replies' => ["Kicked $nick."],
            'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'kick', 'content' => $msg]],
        ];
    }

    private static function kickban(array $args, array $user, array $channel): array
    {
        $nick = $args[0] ?? null;
        $reason = implode(' ', array_slice($args, 1));
        $target = self::targetUser($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        $isGuest = Auth::isGuest($target);
        $mask = strtolower($target['username']) . '!*@*';
        BanService::addBan('channel_ban', $channel['id'], $mask, $reason ?: null, null, Auth::isGuest($user) ? null : (int) $user['id'], $isGuest ? null : (int) $target['id']);
        if (!$isGuest) {
            ModerationService::record($target, 'ban', 'applied', $mask, $reason, 'c', (int) $channel['id']);
            ModerationService::note((int) $target['id'], $user, 'ban', $channel['name'] . ($reason ? ' — ' . $reason : ''));
        }
        $r = self::kick([$nick, $reason], $user, $channel);
        $r['events'] = array_merge($r['events'] ?? [], [
            ['channel_id' => (int) $channel['id'], 'kind' => 'ban', 'content' => $nick . ' has been banned from ' . $channel['name']],
        ]);
        return $r;
    }

    private static function ban(array $args, array $user, array $channel): array
    {
        $target = $args[0] ?? null;
        if (!$target) {
            return ['replies' => ['Usage: /ban <mask|nick> [duration] [reason]']];
        }
        $duration = null;
        $reason = null;
        $rest = array_slice($args, 1);
        if ($rest) {
            $duration = parse_duration($rest[0]);
            if ($duration !== null) {
                array_shift($rest);
            }
            $reason = implode(' ', $rest);
        }
        $userId = null;
        $resolved = false;
        if (!preg_match('/[*!@?]/', $target)) {
            $u = self::targetUser($target);
            if ($u) {
                $userId = Auth::isGuest($u) ? null : (int) $u['id'];
                $target = strtolower($u['username']) . '!*@*';
                $resolved = true;
            } else {
                return ['replies' => ["No such user: $target"]];
            }
        }
        BanService::addBan('channel_ban', $channel['id'], $target, $reason, $duration, Auth::isGuest($user) ? null : (int) $user['id'], $userId);
        $display = $duration !== null ? self::fmtDuration($duration) : 'permanent';
        log_audit('ban', $channel['name'], "$target / $display" . ($reason ? " / $reason" : ''));
        if ($userId) {
            $tu = Database::row('SELECT * FROM users WHERE id = ?', [$userId]);
            if ($tu) {
                ModerationService::record($tu, 'ban', 'applied', $target, $reason, 'c', (int) $channel['id']);
                ModerationService::note($userId, $user, 'ban', $channel['name'] . ' (' . $display . ')' . ($reason ? ' — ' . $reason : ''));
            }
        }
        $events = [['channel_id' => (int) $channel['id'], 'kind' => 'ban', 'content' => $user['username'] . ' banned ' . $target . ' (' . $display . ')']];
        if ($resolved) {
            $r = self::kick([$args[0], 'Banned (' . ($reason ?: 'no reason') . ')'], $user, $channel);
            $events = array_merge($events, $r['events'] ?? []);
        }
        return ['replies' => ["Banned $target for $display" . ($reason ? ": $reason" : '') . '.'], 'events' => $events];
    }

    private static function unban(array $args, array $user, array $channel): array
    {
        $target = $args[0] ?? null;
        if (!$target) {
            return ['replies' => ['Usage: /unban <mask|nick>']];
        }
        $ok = false;
        $u = self::targetUser($target);
        if ($u) {
            // target_user_id references registered users only — a guest's id in
            // that column slot would delete the colliding registered user's ban.
            if (!Auth::isGuest($u)) {
                $ok = (bool) Database::query(
                    'DELETE FROM bans WHERE kind = "channel_ban" AND channel_id = ? AND target_user_id = ?',
                    [$channel['id'], $u['id']]
                )->rowCount();
            }
            if (!$ok) {
                $ok = (bool) Database::query(
                    'DELETE FROM bans WHERE kind = "channel_ban" AND channel_id = ? AND mask = ? COLLATE NOCASE',
                    [$channel['id'], strtolower($u['username']) . '!*@*']
                )->rowCount();
            }
        } else {
            $ok = (bool) Database::query(
                'DELETE FROM bans WHERE kind = "channel_ban" AND channel_id = ? AND mask = ? COLLATE NOCASE',
                [$channel['id'], $target]
            )->rowCount();
        }
        if (!$ok) {
            return ['replies' => ["No matching ban for $target."]];
        }
        return [
            'replies' => ["Removed ban for $target."],
            'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ' removed a ban (' . $target . ')']],
        ];
    }

    private static function quiet(array $args, array $user, array $channel): array
    {
        $nick = $args[0] ?? null;
        $duration = $args[1] ?? null;
        $target = self::targetUser($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        $isGuest = Auth::isGuest($target);
        $dur = parse_duration($duration);
        $mask = strtolower($target['username']) . '!*@*';
        BanService::addBan('quiet', $channel['id'], $mask, null, $dur, Auth::isGuest($user) ? null : (int) $user['id'], $isGuest ? null : (int) $target['id']);
        if (!$isGuest) {
            ModerationService::record($target, 'quiet', 'applied', $mask, '', 'c', (int) $channel['id']);
            ModerationService::note((int) $target['id'], $user, 'warn', 'Muted in ' . $channel['name'] . ' (+q)' . ($dur ? ' for ' . self::fmtDuration($dur) : ''));
        }
        return [
            'replies' => ["$nick is now muted in " . $channel['name'] . '.'],
            'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ' muted ' . $nick . ' (+q)']],
        ];
    }

    private static function level(string $name, array $args, array $user, array $channel): array
    {
        $nick = $args[0] ?? null;
        $map = [
            'op' => 'op', 'deop' => 'normal',
            'halfop' => 'halfop', 'dehalfop' => 'normal',
            'voice' => 'voice', 'devoice' => 'normal',
        ];
        $newLevel = $map[$name];
        if (!$nick) {
            return ['replies' => ["Usage: /$name <nick>"]];
        }
        $target = self::targetUser($nick);
        if (!$target) {
            return ['replies' => ["No such user: $nick"]];
        }
        $check = AccessService::canSetLevel($channel, $user, $target, $newLevel);
        if ($check !== true) {
            return ['replies' => [$check]];
        }
        if (Auth::isGuest($target)) {
            Database::query(
                'INSERT INTO channel_members (channel_id, guest_id, level) VALUES (?, ?, ?)
                 ON CONFLICT DO UPDATE SET level = excluded.level',
                [$channel['id'], (int) $target['id'], $newLevel]
            );
        } else {
            AccessService::setLevel($channel['id'], (int) $target['id'], $newLevel);
        }
        $symbol = $newLevel === 'normal' ? '' : level_symbol($newLevel);
        $flag = $newLevel === 'normal' ? '-' . $name[2] : '+' . $name[0];
        return [
            'replies' => ["$nick now has level: " . ($newLevel === 'normal' ? 'normal' : $newLevel) . '.'],
            'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ' set mode ' . $flag . " on $nick ($symbol$nick)"]],
        ];
    }

    private static function mode(array $args, array $user, array $channel): array
    {
        $modes = $args[0] ?? null;
        $rest = array_slice($args, 1);
        if (!$modes || !preg_match('/^[+-]/', $modes)) {
            return ['replies' => self::modeHelp($channel)];
        }
        $events = [];
        $replies = [];
        // Process as [+-][flags] with a single prefix char (IRC style, e.g. +imlk).
        $add = $modes[0] === '+';
        $flags = substr($modes, 1);
        foreach (str_split($flags) as $f) {
            $result = self::applyModeFlag($channel, $user, $f, $add, $rest);
            if (is_string($result)) {
                $replies[] = $result;
            } else {
                $events[] = $result;
            }
        }
        return ['replies' => $replies ?: ['Modes updated.'], 'events' => $events, 'mode_ok' => $replies === []];
    }

    private static function modeHelp(array $ch): array
    {
        return [
            'Channel modes for ' . $ch['name'] . ' (hover the flags above the chat for the same info):',
            '  +i  invite-only    — only users you invite may join.',
            '  +m  moderated      — only voiced users (+v) may speak.',
            '  +C  filter         — applies the global bad-word list (censor or block).',
            '  +k <key>           — require a key to join; /mode -k removes it.',
            '  +l <n>             — cap members at n; /mode -l removes the limit.',
            '  +t  topic lock     — only operators may change the topic.',
            '  +p  private        — hidden from /list, joinable via share link.',
            '  +s  secret         — hidden entirely; join by invitation only.',
            '  +L  no-log         — disable chat logging for this channel (opers only).',
            '  +b <mask>          — ban a mask; /mode -b <mask> removes it.',
            'Current: ' . self::currentModeString($ch),
        ];
    }

    private static function currentModeString(array $ch): string
    {
        $on = '';
        if ((int) $ch['invite_only'] === 1) {
            $on .= 'i';
        }
        if ((int) $ch['moderated'] === 1) {
            $on .= 'm';
        }
        if ((int) ($ch['censor'] ?? 0) === 1) {
            $on .= 'C';
        }
        if (!empty($ch['key_hash'])) {
            $on .= 'k';
        }
        if (!empty($ch['member_limit'])) {
            $on .= 'l';
        }
        if ((int) $ch['topic_locked'] === 1) {
            $on .= 't';
        }
        if (($ch['visibility'] ?? '') === 'private') {
            $on .= 'p';
        }
        if (($ch['visibility'] ?? '') === 'secret') {
            $on .= 's';
        }
        if ((int) ($ch['no_logging'] ?? 0) === 1) {
            $on .= 'L';
        }
        return $on === '' ? 'no modes set' : '+' . $on;
    }

    private static function applyModeFlag(array $channel, array $user, string $flag, bool $add, array &$rest): array|string
    {
        $level = AccessService::effectiveLevel($channel['id'], $user);
        $w = level_weight($level);
        $isHalfop = $w >= level_weight('halfop'); // half-op: +b +v +m +i +t +k, kick
        $isOp = $w >= level_weight('op');          // op: everything above + +l +C +p +s +o
        $name = $channel['name'];
        switch ($flag) {
            case 'i':
                if (!$isHalfop) {
                    return 'Half-op or higher is required to change +i.';
                }
                ChannelService::update($channel['id'], ['invite_only' => $add ? 1 : 0]);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ' set mode ' . ($add ? '+' : '-') . 'i on ' . $name];
            case 'm':
                if (!$isHalfop) {
                    return 'Half-op or higher is required to change +m.';
                }
                ChannelService::update($channel['id'], ['moderated' => $add ? 1 : 0]);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ' set mode ' . ($add ? '+' : '-') . 'm on ' . $name];
            case 'C':
                if (!$isOp) {
                    return 'Only channel operators can change +C.';
                }
                ChannelService::update($channel['id'], ['censor' => $add ? 1 : 0]);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($add ? ' enabled' : ' disabled') . ' the global word filter (+C) on ' . $name];
            case 'k':
                if (!$isHalfop) {
                    return 'Half-op or higher is required to change +k.';
                }
                if ($add) {
                    $key = array_shift($rest);
                    if (!$key) {
                        return 'Usage: /mode +k <key>';
                    }
                    ChannelService::setKey($channel['id'], $key);
                } else {
                    ChannelService::setKey($channel['id'], null);
                }
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($add ? ' set a channel key (+k)' : ' removed the channel key (-k)') . ' on ' . $name];
            case 'l':
                if (!$isOp) {
                    return 'Only channel operators can change +l.';
                }
                if ($add) {
                    $lim = (int) (array_shift($rest) ?? 0);
                    if ($lim < 1) {
                        return 'Usage: /mode +l <limit>';
                    }
                    ChannelService::update($channel['id'], ['member_limit' => $lim]);
                } else {
                    ChannelService::update($channel['id'], ['member_limit' => null]);
                }
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($add ? " set member limit to $lim (+l)" : ' removed the member limit (-l)') . ' on ' . $name];
            case 't':
                if (!$isHalfop) {
                    return 'Half-op or higher is required to change +t.';
                }
                ChannelService::update($channel['id'], ['topic_locked' => $add ? 1 : 0]);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($add ? ' locked' : ' unlocked') . ' the topic (+' . ($add ? '' : '-') . 't) on ' . $name];
            case 'L':
                if (!Auth::isOper($user)) {
                    return 'Only server operators can change +L.';
                }
                ChannelService::update($channel['id'], ['no_logging' => $add ? 1 : 0]);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($add ? ' disabled' : ' enabled') . ' chat logging (+L) on ' . $name];
            case 'p':
            case 's':
                if (!$isOp) {
                    return 'Only channel operators can change visibility.';
                }
                ChannelService::update($channel['id'], ['visibility' => $add ? ($flag === 's' ? 'secret' : 'private') : 'public']);
                $vis = $add ? ($flag === 's' ? 'secret' : 'private') : 'public';
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . " set channel visibility to $vis"];
            case 'b':
                if (!$isHalfop) {
                    return 'Half-op or higher is required to change bans.';
                }
                $mask = array_shift($rest);
                if (!$mask) {
                    return 'Usage: /mode +b <mask> or /mode -b <mask>';
                }
                if ($add) {
                    BanService::addBan('channel_ban', $channel['id'], $mask, null, null, Auth::isGuest($user) ? null : (int) $user['id']);
                    return ['channel_id' => $channel['id'], 'kind' => 'ban', 'content' => $user['username'] . " banned $mask"];
                }
                BanService::removeByMask('channel_ban', $channel['id'], $mask);
                return ['channel_id' => $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . " removed ban $mask"];
            default:
                return "Unsupported mode flag: $flag";
        }
    }

    private static function topiclock(array $args, array $user, array $channel): array
    {
        $val = $args[0] ?? null;
        $on = match (strtolower((string) $val)) {
            'off', '0', 'no', 'false', '-' => 0,
            default => 1,
        };
        ChannelService::update($channel['id'], ['topic_locked' => $on]);
        return [
            'replies' => ["Topic " . ($on ? 'locked' : 'unlocked') . '.'],
            'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'mode', 'content' => $user['username'] . ($on ? ' locked' : ' unlocked') . ' the topic']],
        ];
    }

    private static function clear(array $args, array $user, ?array $channel): array
    {
        $what = strtolower($args[0] ?? '');
        if (!$channel) {
            return ['replies' => ['You must be in a channel or provide one, e.g. /clear <#channel> <users|bans|ops|voices|topic|modes>']];
        }
        $level = AccessService::effectiveLevel($channel['id'], $user);
        $isFounder = level_weight($level) >= level_weight('founder');
        $isOp = level_weight($level) >= level_weight('op');
        switch ($what) {
            case 'users':
                if (!self::isStaff($user) && !$isFounder) {
                    return ['replies' => ['Only the channel founder can clear all users.']];
                }
                Database::query('DELETE FROM channel_members WHERE channel_id = ? AND level != "founder"', [$channel['id']]);
                return [
                    'replies' => ['All non-founder users removed.'],
                    'events' => [['channel_id' => (int) $channel['id'], 'kind' => 'system', 'content' => $user['username'] . ' cleared all users from ' . $channel['name']]],
                ];
            case 'bans':
                if (!$isOp) {
                    return ['replies' => ['Ops can clear bans.']];
                }
                Database::query('DELETE FROM bans WHERE channel_id = ? AND kind IN ("channel_ban","quiet")', [$channel['id']]);
                return ['replies' => ['All channel bans removed.']];
            case 'ops':
            case 'voices':
            case 'modes':
                if (!$isFounder && $user['role'] !== 'admin') {
                    return ['replies' => ['Only the channel founder can clear ' . $what . '.']];
                }
                if ($what === 'ops') {
                    Database::query('UPDATE channel_members SET level = "normal" WHERE channel_id = ? AND level IN ("admin","op","halfop","voice")', [$channel['id']]);
                } elseif ($what === 'voices') {
                    Database::query('UPDATE channel_members SET level = "normal" WHERE channel_id = ? AND level = "voice"', [$channel['id']]);
                } else {
                    ChannelService::update($channel['id'], [
                        'invite_only' => 0, 'moderated' => 0, 'member_limit' => null, 'key_hash' => null,
                    ]);
                }
                return ['replies' => ["Cleared $what."]];
            case 'topic':
                // Honours the +t topic lock like /topic does: a locked topic
                // requires op level to clear (or staff).
                if (!self::isStaff($user) && (int) $channel['topic_locked'] === 1 && !$isOp) {
                    return ['replies' => ['You must be a channel operator (+o) to clear a locked topic.']];
                }
                ChannelService::update($channel['id'], ['topic' => '']);
                MessageService::system($channel['id'], 'topic', $user['username'] . ' cleared the topic');
                return ['replies' => ['Topic cleared.']];
            default:
                return ['replies' => ['Usage: /clear <users|bans|ops|voices|topic|modes>']];
        }
    }

    private static function fmtDuration(int $seconds): string
    {
        if ($seconds % 2592000 === 0) {
            return ($seconds / 2592000) . 'mo';
        }
        if ($seconds % 604800 === 0) {
            return ($seconds / 604800) . 'w';
        }
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . 'd';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . 'h';
        }
        return floor($seconds / 60) . 'm';
    }
}

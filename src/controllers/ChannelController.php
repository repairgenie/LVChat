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

final class ChannelController
{
    /** GET /c/{slug} — shareable channel link with the login/auto-join flow. */
    public static function channelLink(array $params): void
    {
        $user = Auth::user();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        if (!$user) {
            redirect('/login?next=' . rawurlencode('/c/' . rawurlencode($channel['slug'])));
        }
        $member = AccessService::member($channel['id'], (int) $user['id']);
        if ($member) {
            redirect('/app?channel=' . rawurlencode($channel['slug']));
        }
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $restriction,
                'user' => $user,
            ]);
        }
        $status = ChannelService::joinStatus($channel, $user, $_POST['key'] ?? null);
        if ($status['reason'] === 'need_key') {
            redirect('/app?join=' . rawurlencode($channel['slug']));
        }
        if (!$status['ok']) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $status['reason'],
                'user' => $user,
            ]);
        }
        ChannelService::join($channel, $user);
        redirect('/app?channel=' . rawurlencode($channel['slug']));
    }

    /** GET /embed/{slug} — public embeddable page: gate on sign-in/register/guest, then open the channel. */
    public static function embed(array $params): void
    {
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        $user = Auth::user();
        if ($user) {
            // Signed in: reuse the share-link auto-join flow (the iframe follows the redirect).
            redirect('/c/' . rawurlencode($channel['slug']));
        }
        render_view('embed/landing', [
            'title' => 'Join ' . $channel['name'],
            'channel' => $channel,
            'next' => '/c/' . rawurlencode($channel['slug']),
            'error' => flash(),
        ]);
    }

    public static function joinForm(array $params): void
    {
        $user = Auth::require();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        if (AccessService::member($channel['id'], (int) $user['id'])) {
            redirect('/app?channel=' . rawurlencode($channel['slug']));
        }
        render_view('chat/join', [
            'channel' => $channel,
            'user' => $user,
            'error' => flash(),
        ]);
    }

    public static function joinWithKey(array $params): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            flash($restriction);
            redirect('/app');
        }
        $status = ChannelService::joinStatus($channel, $user, (string) ($_POST['key'] ?? ''));
        if (!$status['ok']) {
            flash($status['reason']);
            redirect('/c/' . rawurlencode($channel['slug']) . '/join');
        }
        ChannelService::join($channel, $user);
        redirect('/app?channel=' . rawurlencode($channel['slug']));
    }

    public static function create(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        if (!in_array($visibility, ['public', 'private', 'secret'], true)) {
            json_out(['error' => 'Visibility must be public, private or secret.'], 400);
        }
        $result = ChannelService::create($user, $name, [
            'topic' => (string) ($_POST['topic'] ?? ''),
            'register' => !empty($_POST['register']) && in_array((string) $_POST['register'], ['1', 'on', 'true', 'yes'], true),
            'visibility' => $visibility,
            'invite_only' => !empty($_POST['invite_only']) && in_array((string) $_POST['invite_only'], ['1', 'on', 'true', 'yes'], true),
        ]);
        if (is_string($result)) {
            json_out(['error' => $result], 400);
        }
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($result['slug'])]);
    }

    public static function join(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = $_POST['key'] ?? null;
        $channel = ChannelService::find($name);
        if (!$channel) {
            $created = ChannelService::create($user, $name);
            if (is_string($created)) {
                json_out(['error' => $created], 400);
            }
            ChannelService::join($created, $user);
            json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($created['slug'])]);
        }
        $status = ChannelService::joinStatus($channel, $user, $key ? (string) $key : null);
        if (!$status['ok']) {
            json_out(['error' => $status['reason']], 403);
        }
        ChannelService::join($channel, $user);
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($channel['slug'])]);
    }

    public static function part(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        ChannelService::part($channel, $user, $reason ?: null);
        json_out(['ok' => true, 'redirect' => '/app']);
    }

    /** POST /api/channel/notify — set the user's notification mode for a channel. */
    public static function notify(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if (!AccessService::member($channel['id'], $user)) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }
        $mode = (string) ($_POST['mode'] ?? 'all');
        if (!in_array($mode, ['all', 'mentions', 'muted'], true)) {
            json_out(['error' => 'Invalid notification mode.'], 400);
        }
        ChannelService::setNotifyMode($channel['id'], $user, $mode);
        json_out(['ok' => true, 'mode' => $mode]);
    }

    /** POST /api/channel/read — mark a channel read (messenger/API clients). */
    public static function markRead(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if (!AccessService::member($channel['id'], $user)) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }
        ChannelService::markRead($channel['id'], $user);
        json_out(['ok' => true]);
    }

    /** POST /api/channel/delete — founder deletes their channel (history preserved). */
    public static function deleteChannel(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $r = ChannelService::delete($channel['id'], $user);
        if ($r !== true) {
            json_out(['error' => $r], 403);
        }
        json_out(['ok' => true, 'redirect' => '/app']);
    }

    public static function acceptInvite(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $channel = ChannelService::findBySlug($slug);
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $invite = Database::row('SELECT * FROM invites WHERE channel_id = ? AND user_id = ?', [(int) $channel['id'], (int) $user['id']]);
        if (!$invite) {
            json_out(['error' => 'Invite not found.'], 404);
        }
        ChannelService::join($channel, $user);
        Database::query('DELETE FROM notifications WHERE kind = "invite" AND user_id = ? AND channel_id = ?', [(int) $user['id'], (int) $channel['id']]);
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($channel['slug'])]);
    }

    public static function declineInvite(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $channel = ChannelService::findBySlug($slug);
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        Database::query('DELETE FROM invites WHERE channel_id = ? AND user_id = ?', [(int) $channel['id'], (int) $user['id']]);
        Database::query('DELETE FROM notifications WHERE kind = "invite" AND user_id = ? AND channel_id = ?', [(int) $user['id'], (int) $channel['id']]);
        json_out(['ok' => true]);
    }

    /** POST /api/channel/bg — channel owner sets the channel's chat background
     *  (upload an image and/or pick a colour; empty bg_color clears the colour). */
    public static function setBackground(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            json_out(['error' => 'Only the channel owner can set the background.'], 403);
        }

        $color = ThemeService::hex((string) ($_POST['bg_color'] ?? ''));
        $fit = in_array($_POST['bg_fit'] ?? '', ThemeService::CHAT_BG_FITS, true)
            ? (string) $_POST['bg_fit']
            : 'contain';
        $overlay = (int) ($_POST['bg_overlay'] ?? -1);
        $overlay = $overlay >= 0 && $overlay <= 100 ? $overlay : ThemeService::CHAT_BG_OVERLAY_DEFAULT;
        $fields = ['bg_color' => $color !== '' ? $color : null, 'bg_fit' => $fit, 'bg_overlay' => $overlay];

        $hasFile = isset($_FILES['file'])
            && !empty($_FILES['file']['tmp_name'])
            && is_uploaded_file((string) $_FILES['file']['tmp_name']);
        if ($hasFile) {
            $stored = UploadService::store($_FILES['file'], 'theme');
            if (!$stored['ok']) {
                json_out(['error' => $stored['error']], 400);
            }
            if (!empty($channel['bg_image'])) {
                UploadService::remove((string) $channel['bg_image']);
            }
            $fields['bg_image'] = $stored['url'];
        }

        ChannelService::update((string) $channel['id'], $fields);
        log_audit('channel_bg', $channel['name'], implode(',', array_filter($fields)));
        json_out([
            'ok' => true,
            'bg_color' => $fields['bg_color'] ?? null,
            'bg_fit' => $fit,
            'bg_overlay' => $overlay,
            'bg_image' => $fields['bg_image'] ?? (isset($channel['bg_image']) ? (string) $channel['bg_image'] : null),
        ]);
    }

    /** POST /api/channel/bg/remove — channel owner clears the channel background. */
    public static function removeBackground(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            json_out(['error' => 'Only the channel owner can remove the background.'], 403);
        }
        if (!empty($channel['bg_image'])) {
            UploadService::remove((string) $channel['bg_image']);
        }
        ChannelService::update((string) $channel['id'], ['bg_image' => null, 'bg_color' => null, 'bg_fit' => 'contain', 'bg_overlay' => ThemeService::CHAT_BG_OVERLAY_DEFAULT]);
        log_audit('channel_bg_remove', $channel['name']);
        json_out(['ok' => true]);
    }

    // ── Channel Settings (control panel) ─────────────────────────────────────

    /** The open channel for a settings request (auth + membership checked). */
    private static function settingsChannel(array $params): array
    {
        $user = Auth::user();
        if (!$user) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        $channel = ChannelService::findBySlug((string) ($params['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if (!AccessService::member($channel['id'], $user)) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }
        return $channel;
    }

    /** GET /api/channel/settings — the control-panel payload for a channel:
     *  bans, registered ops/halfops (access list), topic and channel URL. */
    public static function settings(): void
    {
        $channel = self::settingsChannel($_GET);
        $user = Auth::user();
        $level = level_weight(AccessService::effectiveLevel($channel['id'], $user));
        $isStaff = $user['role'] === 'admin' || Auth::isOper($user);
        json_out([
            'ok' => true,
            'channel' => [
                'id' => (int) $channel['id'],
                'name' => $channel['name'],
                'slug' => $channel['slug'],
                'topic' => $channel['topic'],
                'description' => $channel['description'],
                'visibility' => $channel['visibility'],
                'topic_locked' => (int) $channel['topic_locked'] === 1,
                'registered' => ChannelService::isRegistered($channel),
                'url' => ChannelService::channelUrl($channel),
                'url_banned' => ChannelService::channelUrlBanned($channel),
                'url_set' => !empty($channel['channel_url']),
            ],
            'can' => [
                'manage' => ChannelService::canManageChannel($channel, $user),
                'bans' => $isStaff || $level >= level_weight('halfop'),
                'access' => $isStaff || $level >= level_weight('op'),
                'topic' => $isStaff || $level >= level_weight('op') || (int) $channel['topic_locked'] !== 1,
                'url' => $isStaff || $level >= level_weight('op'),
            ],
            'bans' => BanService::channelBans((int) $channel['id']),
            'access' => AccessService::accessList((int) $channel['id']),
        ]);
    }

    /** POST /api/channel/settings — actions for the channel control panel.
     *  Each action enforces its own channel-level permission. */
    public static function settingsAction(): void
    {
        $user = Auth::user();
        if (!$user) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        Csrf::verify();
        $channel = self::settingsChannel($_POST);
        $cid = (int) $channel['id'];
        $level = level_weight(AccessService::effectiveLevel($cid, $user));
        $isStaff = $user['role'] === 'admin' || Auth::isOper($user);

        $action = (string) ($_POST['action'] ?? '');
        switch ($action) {
            case 'ban_add':
                if (!$isStaff && $level < level_weight('halfop')) {
                    json_out(['error' => 'Half-op or higher is required to manage bans.'], 403);
                }
                $target = trim((string) ($_POST['mask'] ?? ''));
                if ($target === '') {
                    json_out(['error' => 'A target mask or nick is required.'], 400);
                }
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $duration = parse_duration((string) ($_POST['duration'] ?? ''));
                $userId = null;
                if (!preg_match('/[*!@?]/', $target)) {
                    $u = Auth::findActor($target);
                    if (!$u) {
                        json_out(['error' => "No such user: $target"], 404);
                    }
                    $userId = Auth::isGuest($u) ? null : (int) $u['id'];
                    $target = strtolower($u['username']) . '!*@*';
                }
                $err = BanService::addBan('channel_ban', $cid, $target, $reason, $duration, (int) $user['id'], $userId);
                if ($err) {
                    json_out(['error' => $err], 400);
                }
                if ($userId) {
                    $tu = Database::row('SELECT * FROM users WHERE id = ?', [$userId]);
                    if ($tu) {
                        ModerationService::record($tu, 'ban', 'applied', $target, $reason, 'c', $cid);
                        ModerationService::note($userId, $user, 'ban', $channel['name'] . ' (settings)');
                    }
                }
                log_audit('channel_settings_ban', $channel['name'], "$target / " . ($reason ?: 'no reason'));
                json_out(['ok' => true, 'message' => "Banned $target."]);
                // no break
            case 'ban_del':
                if (!$isStaff && $level < level_weight('halfop')) {
                    json_out(['error' => 'Half-op or higher is required to manage bans.'], 403);
                }
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) {
                    json_out(['error' => 'Missing ban.'], 400);
                }
                $ban = Database::row('SELECT * FROM bans WHERE id = ? AND channel_id = ?', [$id, $cid]);
                if (!$ban) {
                    json_out(['error' => 'Ban not found.'], 404);
                }
                BanService::remove($id);
                log_audit('channel_settings_unban', $channel['name'], 'ban#' . $id);
                json_out(['ok' => true, 'message' => 'Ban removed.']);
                // no break
            case 'access_add':
                if (!$isStaff && $level < level_weight('op')) {
                    json_out(['error' => 'Channel ops or higher can manage registered ops and half-ops.'], 403);
                }
                $nick = trim((string) ($_POST['nick'] ?? ''));
                $newLevel = strtolower((string) ($_POST['level'] ?? ''));
                if ($nick === '' || !in_array($newLevel, ['admin', 'op', 'halfop', 'voice'], true)) {
                    json_out(['error' => 'Pick a user and a level (admin, op, halfop or voice).'], 400);
                }
                $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$nick]);
                if (!$t) {
                    json_out(['error' => "No such user: $nick"], 404);
                }
                $check = AccessService::canSetLevel($channel, $user, $t, $newLevel);
                if ($check !== true) {
                    json_out(['error' => $check], 403);
                }
                AccessService::addAccess($cid, (int) $t['id'], $newLevel, (int) $user['id']);
                log_audit('channel_settings_access', $channel['name'], "$nick -> $newLevel");
                json_out(['ok' => true, 'message' => "$nick added to the access list as $newLevel."]);
                // no break
            case 'access_del':
                if (!$isStaff && $level < level_weight('op')) {
                    json_out(['error' => 'Channel ops or higher can manage registered ops and half-ops.'], 403);
                }
                $nick = trim((string) ($_POST['nick'] ?? ''));
                $t = $nick !== '' ? Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$nick]) : null;
                if (!$t) {
                    json_out(['error' => "No such user: $nick"], 404);
                }
                AccessService::removeAccess($cid, (int) $t['id']);
                log_audit('channel_settings_access_del', $channel['name'], $nick);
                json_out(['ok' => true, 'message' => "Removed $nick from the access list."]);
                // no break
            case 'topic_set':
                if ($user['role'] !== 'admin' && (int) $channel['topic_locked'] === 1 && $level < level_weight('op')) {
                    json_out(['error' => 'You must be a channel operator (+o) to change the topic.'], 403);
                }
                $topic = trim((string) ($_POST['topic'] ?? ''));
                $topic = mb_substr($topic, 0, 500);
                ChannelService::update((string) $cid, ['topic' => $topic]);
                MessageService::system($cid, 'topic', $user['username'] . ($topic !== '' ? ' set the topic to: ' . $topic : ' cleared the topic'));
                log_audit('topic', $channel['name'], $topic);
                json_out([
                    'ok' => true,
                    'message' => $topic !== '' ? 'Topic set.' : 'Topic cleared.',
                    'topic_set' => $topic,
                    'topic_channel' => $channel['slug'],
                ]);
                // no break
            case 'url_set':
            case 'url_clear':
                if (!$isStaff && $level < level_weight('op')) {
                    json_out(['error' => 'Channel ops or higher can set the channel URL.'], 403);
                }
                $url = $action === 'url_clear' ? '' : trim((string) ($_POST['url'] ?? ''));
                $err = ChannelService::setChannelUrl($cid, $url);
                if ($err) {
                    json_out(['error' => $err], 400);
                }
                if ($url !== '') {
                    self::channelSystemMessage($channel, 'system', $user['username'] . ' set the channel URL to: ' . $url);
                } else {
                    self::channelSystemMessage($channel, 'system', $user['username'] . ' cleared the channel URL.');
                }
                log_audit('channel_url', $channel['name'], $url !== '' ? $url : '(cleared)');
                json_out(['ok' => true, 'message' => $url !== '' ? 'Channel URL set.' : 'Channel URL cleared.', 'url' => $url !== '' ? $url : null]);
                // no break
            default:
                json_out(['error' => 'Unknown settings action.'], 400);
        }
    }

    /** Post a system message to a channel and fan it out to live viewers. */
    private static function channelSystemMessage(array $channel, string $kind, string $content): void
    {
        $id = MessageService::system((int) $channel['id'], $kind, $content);
        Realtime::message($channel['slug'], [
            'id' => $id,
            'kind' => $kind,
            'content' => $content,
            'channel' => $channel['slug'],
            'sender_id' => null,
            'username' => null,
            'guest' => 0,
        ]);
    }
}

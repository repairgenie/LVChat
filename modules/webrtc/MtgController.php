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



/**
 * MtgController — ephemeral meeting rooms (#mtg-XXXXXX) for the WebRTC module.
 *
 * Meetings are temporary, private, voice-enabled channels:
 *   - name #mtg-<6 random digits>, unregistered (vanishes when empty)
 *   - visibility 'private' + invite_only → never in the public channel list
 *   - a random key is set (+k) and baked into the invite URL (/mtg/<slug>?key=)
 *   - invited online users are added immediately (no accept step); offline
 *     users are NOT added — they need to be online to be invited
 *
 * Routes (registered by routes.php):
 *   POST /api/webrtc/mtg/create  → {ok, slug, name, key, url}
 *   POST /api/webrtc/mtg/invite  → {ok, added[], offline[], unknown[], url}
 *   GET  /mtg/{slug}             → keyed auto-join landing (login bounce)
 */
final class MtgController
{
    private static function requireActor(): array
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not signed in.'], 401);
        }
        return $u;
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    private static function meetingKey(int $channelId): ?string
    {
        $row = Database::row('SELECT key FROM mtg_channels WHERE channel_id = ?', [$channelId]);
        return $row ? (string) $row['key'] : null;
    }

    private static function inviteUrl(string $slug, ?string $key): string
    {
        $url = '/mtg/' . rawurlencode($slug);
        if ($key !== null && $key !== '') {
            $url .= '?key=' . rawurlencode($key);
        }
        return $url;
    }

    /** POST /api/webrtc/mtg/create */
    public static function create(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        if (Auth::isGuest($actor)) {
            json_out(['error' => 'Registered users only.'], 403);
        }

        // #mtg-<6 random digits>; regenerate on the (rare) collision.
        $name = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = '#mtg-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (ChannelService::find($candidate) === null) {
                $name = $candidate;
                break;
            }
        }
        if ($name === '') {
            json_out(['error' => 'Could not allocate a meeting room. Try again.'], 500);
        }

        $channel = ChannelService::create($actor, $name, [
            'visibility' => 'private',
            'invite_only' => true,
            'topic' => 'Voice meeting',
        ]);
        if (is_string($channel)) {
            json_out(['error' => $channel], 400);
        }
        $channelId = (int) $channel['id'];

        $key = bin2hex(random_bytes(4)); // 8 hex chars, baked into the invite URL
        ChannelService::setKey($channelId, $key);
        Database::query('UPDATE channels SET voice_enabled = 1, invite_only = 1 WHERE id = ?', [$channelId]);
        Database::query(
            'INSERT OR REPLACE INTO mtg_channels (channel_id, key, created_by) VALUES (?, ?, ?)',
            [$channelId, $key, (int) $actor['id']]
        );

        log_audit('mtg_create', $name);
        json_out([
            'ok' => true,
            'slug' => (string) $channel['slug'],
            'name' => $name,
            'key' => $key,
            'url' => self::inviteUrl((string) $channel['slug'], $key),
        ]);
    }

    /** POST /api/webrtc/mtg/invite — {channel, users} adds online users immediately. */
    public static function invite(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $channel = Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', [$slug]);
        if (!$channel) {
            json_out(['error' => 'Unknown channel.'], 404);
        }
        if (!ChannelService::canManageChannel($channel, $actor)) {
            json_out(['error' => 'Channel operators only.'], 403);
        }

        $raw = (string) ($_POST['users'] ?? '');
        $names = preg_split('/[\s,]+/', $raw) ?: [];
        $added = [];
        $offline = [];
        $unknown = [];
        foreach (array_filter($names) as $nick) {
            $target = Auth::findActor(trim($nick));
            if (!$target) {
                $unknown[] = trim($nick);
                continue;
            }
            if (!Auth::actuallyOnline($target)) {
                $offline[] = (string) $target['username'];
                continue;
            }
            ChannelService::join($channel, $target);
            $added[] = (string) $target['username'];
        }

        if ($added || $offline || $unknown) {
            $detail = implode(', ', $added) . ($offline ? ' — offline (not added): ' . implode(', ', $offline) : '');
            log_audit('mtg_invite', (string) $channel['name'], $detail);
        }

        json_out([
            'ok' => true,
            'added' => $added,
            'offline' => $offline,
            'unknown' => $unknown,
            'url' => self::inviteUrl((string) $channel['slug'], self::meetingKey((int) $channel['id'])),
        ]);
    }

    /** GET /mtg/{slug} — invite link: auto-joins members/key holders, login bounce otherwise. */
    public static function landing(array $params): void
    {
        $channel = ChannelService::findBySlug((string) ($params['slug'] ?? ''));
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }

        $user = Auth::user();
        if (!$user) {
            $key = isset($_GET['key']) ? '?key=' . rawurlencode((string) $_GET['key']) : '';
            redirect('/login?next=' . rawurlencode('/mtg/' . rawurlencode((string) $channel['slug']) . $key));
        }

        if (AccessService::member((int) $channel['id'], $user)) {
            redirect('/app?channel=' . rawurlencode((string) $channel['slug']));
        }

        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            render_view('chat/denied', ['channel' => $channel, 'reason' => $restriction, 'user' => $user]);
        }

        $key = isset($_GET['key']) ? (string) $_GET['key'] : null;
        $status = ChannelService::joinStatus($channel, $user, $key);
        if ($status['reason'] === 'need_key') {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => 'This meeting requires the key from the invite link.',
                'user' => $user,
            ]);
        }
        if (!$status['ok']) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $status['reason'],
                'user' => $user,
            ]);
        }
        ChannelService::join($channel, $user);
        redirect('/app?channel=' . rawurlencode((string) $channel['slug']));
    }
}

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
 * VoiceController — channel voice + shared status for the WebRTC module.
 *
 * Routes (registered by routes.php):
 *   GET  /api/webrtc/voice/status         — module status + caller's sessions/calls
 *   POST /api/webrtc/voice/join           — {channel} → LiveKit join payload
 *   POST /api/webrtc/voice/leave          — drop the caller's voice session
 *   POST /api/webrtc/voice/channel-voice  — {channel, enabled} ops+ toggle
 *
 * Auth: browser session cookie + CSRF (POSTs) or the messenger bearer token
 * (X-LVC-Session, CSRF-safe by construction) — mirrors FriendController.
 */
final class VoiceController
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

    /** Insert/update the actor's live voice session (one per actor). */
    private static function upsertSession(array $actor, string $room, string $kind, string $token): void
    {
        $isGuest = Auth::isGuest($actor);
        $col = $isGuest ? 'guest_id' : 'user_id';
        Database::query(
            "DELETE FROM voice_sessions WHERE $col = ?",
            [(int) $actor['id']]
        );
        Database::query(
            "INSERT INTO voice_sessions ($col, room, kind, token) VALUES (?, ?, ?, ?)",
            [(int) $actor['id'], $room, $kind, $token]
        );
    }

    /** The actor's current voice session row, if any. */
    private static function currentSession(array $actor): ?array
    {
        $isGuest = Auth::isGuest($actor);
        $col = $isGuest ? 'guest_id' : 'user_id';
        $row = Database::row(
            "SELECT * FROM voice_sessions WHERE $col = ?",
            [(int) $actor['id']]
        );
        return $row ?: null;
    }

    private static function deleteSession(array $actor): ?array
    {
        $isGuest = Auth::isGuest($actor);
        $col = $isGuest ? 'guest_id' : 'user_id';
        $row = self::currentSession($actor);
        Database::query("DELETE FROM voice_sessions WHERE $col = ?", [(int) $actor['id']]);
        return $row;
    }

    /** GET /api/webrtc/voice/status */
    public static function status(): void
    {
        $actor = self::requireActor();
        LiveKitService::pruneStale();
        LiveKitService::expireCalls();

        $status = LiveKitService::status();

        // Touch this actor's session so the stale-pruner keeps them counted.
        if ($sess = self::currentSession($actor)) {
            Database::query(
                'UPDATE voice_sessions SET last_seen = datetime("now") WHERE id = ?',
                [(int) $sess['id']]
            );
        }

        // Channels the actor is a member of, marked with their voice state.
        $channels = [];
        foreach (Database::all(
            'SELECT c.slug, c.voice_enabled, c.name FROM channels c
             JOIN channel_members m ON m.channel_id = c.id
             WHERE (m.user_id = ? OR m.guest_id = ?)
             ORDER BY c.name COLLATE NOCASE',
            [(int) $actor['id'], (int) $actor['id']]
        ) as $row) {
            $channels[] = [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'voice_enabled' => (int) ($row['voice_enabled'] ?? 0) === 1,
            ];
        }

        $isGuest = Auth::isGuest($actor);
        $meId = (int) $actor['id'];

        $incoming = [];
        $outgoing = [];
        $active = null;
        $recent = [];
        foreach (Database::all(
            'SELECT * FROM call_sessions
             WHERE status IN ("ringing", "active")
               AND (callee_user_id = ? OR callee_guest_id = ? OR caller_user_id = ? OR caller_guest_id = ?)
             ORDER BY id',
            [$meId, $meId, $meId, $meId]
        ) as $call) {
            $isCaller = ($call['caller_user_id'] && (int) $call['caller_user_id'] === $meId)
                || ($call['caller_guest_id'] && (int) $call['caller_guest_id'] === $meId);
            $peer = $isCaller
                ? self::callPeer($call['callee_user_id'], $call['callee_guest_id'])
                : self::callPeer($call['caller_user_id'], $call['caller_guest_id']);
            $row = [
                'call_id' => (int) $call['id'],
                'room' => $call['room'],
                'status' => $call['status'],
                'peer' => $peer,
                'incoming' => !$isCaller && $call['status'] === 'ringing',
                'started' => (int) (strtotime((string) $call['created_at'] . ' UTC') * 1000),
            ];
            if ($call['status'] === 'active') {
                $active = $row;
            } elseif ($isCaller) {
                $outgoing[] = $row;
            } else {
                if (self::callerSilenced($call, $actor)) {
                    // Blocked or muted caller: never disturb the callee — silently
                    // fail the ring so the caller's side clears too.
                    Database::query("UPDATE call_sessions SET status = 'missed' WHERE id = ?", [(int) $call['id']]);
                    continue;
                }
                $incoming[] = $row;
            }
        }

        // Recent (non-live) call outcomes for this actor, so the caller's client
        // can say "declined" vs "no answer" vs "you cancelled". Ring timeout is
        // enforced by LiveKitService::expireCalls() using call_ring_seconds.
        foreach (Database::all(
            'SELECT * FROM call_sessions
             WHERE status IN ("declined", "missed", "cancelled", "ended")
               AND (callee_user_id = ? OR callee_guest_id = ? OR caller_user_id = ? OR caller_guest_id = ?)
               AND created_at >= datetime("now", "-90 seconds")
             ORDER BY id DESC LIMIT 5',
            [$meId, $meId, $meId, $meId]
        ) as $call) {
            $isCaller = ($call['caller_user_id'] && (int) $call['caller_user_id'] === $meId)
                || ($call['caller_guest_id'] && (int) $call['caller_guest_id'] === $meId);
            $recent[] = [
                'call_id' => (int) $call['id'],
                'status' => $call['status'],
                'peer' => $isCaller
                    ? self::callPeer($call['callee_user_id'], $call['callee_guest_id'])
                    : self::callPeer($call['caller_user_id'], $call['caller_guest_id']),
            ];
        }

        json_out([
            'ok' => true,
            'enabled' => $status['enabled'],
            'active' => $status['active'],
            'max' => $status['max'],
            'full' => $status['full'],
            'talker_cap' => LiveKitService::talkerCap(),
            'bitrate' => LiveKitService::bitrate(),
            'ring_seconds' => LiveKitService::ringSeconds(),
            'channels' => $channels,
            'session' => $sess ? ['room' => $sess['room'], 'kind' => $sess['kind']] : null,
            'calls' => ['incoming' => $incoming, 'outgoing' => $outgoing, 'active' => $active, 'recent' => $recent],
        ]);
    }

    /** POST /api/webrtc/voice/join */
    public static function join(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $slug = trim((string) ($_POST['channel'] ?? ''));

        if (class_exists('SaaSService') && !SaaSService::feature($actor, 'voice')) {
            json_out(['error' => 'Voice is not available on your plan.'], 403);
        }

        $status = LiveKitService::status();
        if (!$status['enabled']) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        if ($slug === '') {
            json_out(['error' => 'Missing channel.'], 400);
        }
        $channel = Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', [$slug]);
        if (!$channel) {
            json_out(['error' => 'Unknown channel.'], 404);
        }
        if ((int) ($channel['voice_enabled'] ?? 0) !== 1) {
            json_out(['error' => 'Voice is not enabled for this channel.'], 403);
        }
        if (!AccessService::member((int) $channel['id'], $actor)) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }

        LiveKitService::pruneStale();
        $status = LiveKitService::status();
        if ($status['full']) {
            json_out(['error' => "Voice is full ({$status['active']}/{$status['max']}). Try again later."], 409);
        }

        $room = 'chan:' . $slug;
        $token = LiveKitService::token($room, $actor, LiveKitService::maxUsers());
        self::upsertSession($actor, $room, 'channel', $token);

        json_out([
            'ok' => true,
            'url' => LiveKitService::url(),
            'token' => $token,
            'room' => $room,
            'talker_cap' => LiveKitService::talkerCap($actor),
            'bitrate' => LiveKitService::bitrate($actor),
        ]);
    }

    /** POST /api/webrtc/voice/leave */
    public static function leave(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $sess = self::deleteSession($actor);
        if ($sess && $sess['kind'] === 'call') {
            Database::query(
                "UPDATE call_sessions SET status = 'ended' WHERE room = ? AND status != 'ended'",
                [$sess['room']]
            );
        }
        json_out(['ok' => true]);
    }

    /** POST /api/webrtc/voice/channel-voice — ops+ toggles a channel's voice flag. */
    public static function channelVoice(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $enable = ($_POST['enabled'] ?? '0') === '1';
        $channel = Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', [$slug]);
        if (!$channel) {
            json_out(['error' => 'Unknown channel.'], 404);
        }
        if (!ChannelService::canManageChannel($channel, $actor)) {
            json_out(['error' => 'Channel operators only.'], 403);
        }
        Database::query('UPDATE channels SET voice_enabled = ? WHERE id = ?', [$enable ? 1 : 0, (int) $channel['id']]);
        log_audit('channel_voice_' . ($enable ? 'on' : 'off'), $slug);
        json_out(['ok' => true, 'voice_enabled' => $enable]);
    }

    /** Resolve a call peer's display name from user/guest ids. */
    private static function callPeer(?int $userId, ?int $guestId): string
    {
        if ($userId) {
            $u = Database::row('SELECT username FROM users WHERE id = ?', [$userId]);
            return $u ? (string) $u['username'] : 'unknown';
        }
        if ($guestId) {
            $g = Database::row('SELECT nick FROM guests WHERE id = ?', [$guestId]);
            return $g ? (string) $g['nick'] : 'guest';
        }
        return 'unknown';
    }

    /**
     * Whether a ringing call to $actor should never be surfaced: the caller is
     * a real user $actor has blocked or muted. Mutes/blocks only apply between
     * registered users (guests can't be blocked/muted, and guests don't set them).
     */
    private static function callerSilenced(array $call, array $actor): bool
    {
        $callerUid = (int) ($call['caller_user_id'] ?? 0);
        if ($callerUid === 0 || (int) ($call['caller_guest_id'] ?? 0) !== 0 || Auth::isGuest($actor)) {
            return false;
        }
        $me = (int) $actor['id'];
        $blocked = Database::scalar(
            'SELECT 1 FROM friendships WHERE status = "blocked"
               AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))',
            [$me, $callerUid, $callerUid, $me]
        );
        if ($blocked) {
            return true;
        }
        return (bool) Database::scalar(
            'SELECT 1 FROM user_mutes WHERE user_id = ? AND muted_user_id = ?',
            [$me, $callerUid]
        );
    }
}

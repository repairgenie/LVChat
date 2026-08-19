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
    private static function upsertSession(array $actor, string $room, string $kind, ?string $token, bool $waiting = false): void
    {
        $isGuest = Auth::isGuest($actor);
        $col = $isGuest ? 'guest_id' : 'user_id';
        Database::query(
            "DELETE FROM voice_sessions WHERE $col = ?",
            [(int) $actor['id']]
        );
        // Waiting occupants have no LiveKit token yet (schema keeps token NOT
        // NULL, so store an empty string for them — they're admitted via mint).
        Database::query(
            "INSERT INTO voice_sessions ($col, room, kind, token, waiting) VALUES (?, ?, ?, ?, ?)",
            [(int) $actor['id'], $room, $kind, $token ?? '', $waiting ? 1 : 0]
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

        // Touch this actor's session so the stale-pruner keeps them counted
        // (waiting occupants heartbeat too — they hold a session row while
        // the host decides).
        if ($sess = self::currentSession($actor)) {
            Database::query(
                'UPDATE voice_sessions SET last_seen = datetime("now") WHERE id = ?',
                [(int) $sess['id']]
            );
        }

        // Session payload: room state + (for moderators) the roster so the host
        // can kick/mute/admit straight from the UI. The roster is app-side
        // (voice_sessions), so the 2s poll never touches LiveKit's admin API.
        $session = null;
        if ($sess) {
            $room = (string) $sess['room'];
            $session = [
                'room' => $room,
                'kind' => (string) $sess['kind'],
                'waiting' => (int) ($sess['waiting'] ?? 0) === 1,
                'locked' => LiveKitService::roomLocked($room),
                'can_moderate' => ModerationController::canModerate($actor, $room),
            ];
            if ((int) ($sess['waiting'] ?? 0) === 1) {
                // Waiting occupants skip the roster (they're not inside yet).
                $session['roster'] = [];
            } else {
                $meIdentity = LiveKitService::identity($actor);
                $roster = [];
                foreach (LiveKitService::roster($room) as $r) {
                    $roster[] = [
                        'identity' => $r['identity'],
                        'name' => $r['name'],
                        'guest' => $r['guest'],
                        'waiting' => $r['waiting'],
                        'me' => $r['identity'] === $meIdentity,
                    ];
                }
                $session['roster'] = $roster;
            }
            // Admission handoff: the host admitted us — hand over a freshly
            // minted token exactly once. The token is regenerated on every
            // poll while the mint is pending so a slow client never gets an
            // expired one, then cleared after delivery.
            if (!empty($sess['mint'])) {
                $token = LiveKitService::token($room, $actor, LiveKitService::maxUsers());
                Database::query(
                    'UPDATE voice_sessions SET mint = NULL, token = ? WHERE id = ?',
                    [$token, (int) $sess['id']]
                );
                $session['mint'] = [
                    'url' => LiveKitService::url(),
                    'token' => $token,
                    'room' => $room,
                ];
            }
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
                'title' => (string) ($call['title'] ?? ''),
                'group' => self::callIsGroup($call),
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

        // Group-call invites: active calls this actor was added to (invited,
        // not yet joined/declined). The callee columns above already cover the
        // original 1:1 pair; this sweep picks up every later invitee.
        foreach (Database::all(
            'SELECT cs.*, cp.status AS invited_status, cp.role AS invited_role
             FROM call_participants cp
             JOIN call_sessions cs ON cs.id = cp.call_id
             WHERE cp.status IN ("invited", "joined") AND cs.status = "active"
               AND (cp.user_id = ? OR cp.guest_id = ?)
             ORDER BY cs.id',
            [$meId, $meId]
        ) as $call) {
            $seen = false;
            if ($active !== null && (int) $active['call_id'] === (int) $call['id']) {
                $seen = true;
            }
            foreach ($incoming as $c) {
                if ((int) $c['call_id'] === (int) $call['id']) {
                    $seen = true;
                }
            }
            $base = [
                'call_id' => (int) $call['id'],
                'room' => $call['room'],
                'status' => 'active',
                'peer' => self::callPeer($call['caller_user_id'], $call['caller_guest_id']),
                'title' => (string) ($call['title'] ?? ''),
                'group' => true,
                'started' => (int) (strtotime((string) $call['created_at'] . ' UTC') * 1000),
            ];
            if ($seen) {
                continue;
            }
            if (($call['invited_status'] ?? '') === 'joined') {
                // Already in the room: surface as the active call (page reload).
                $active = $active === null ? $base : $active;
            } else {
                // Still ringing for us (invited, not yet accepted).
                $incoming[] = array_merge($base, ['incoming' => true]);
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

        // Recording state for the UI (admin gate + whether this room is being recorded).
        $recEnabled = (string) (config_get('recording_enabled', '0') ?? '0') === '1';
        $recActive = null;
        if ($sess && $recEnabled) {
            $recRow = Database::row(
                "SELECT * FROM recordings WHERE room = ? AND status IN ('starting', 'active') ORDER BY id DESC LIMIT 1",
                [(string) $sess['room']]
            );
            if ($recRow) {
                $recActive = [
                    'room' => (string) $recRow['room'],
                    'file' => (string) ($recRow['filename'] ?? ''),
                    'started_at' => (string) ($recRow['started_at'] ?? ''),
                ];
            }
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
            'video_quality_default' => LiveKitService::videoQualityDefault(),
            'video_quality_available' => LiveKitService::videoQualityAvailable(),
            'recording' => ['enabled' => $recEnabled, 'active' => $recActive],
            'channels' => $channels,
            'session' => $session,
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
        if (!LiveKitService::rateLimit('voice-join:' . LiveKitService::identity($actor), 12, 60)) {
            json_out(['error' => 'Too many join attempts. Please slow down.'], 429);
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

        $room = 'chan:' . $slug;

        // Waiting room (Zoom-style lobby): the flag lives on the channel or on
        // the linked event; occupants who can't moderate the room wait before
        // entering. Locked rooms refuse new joins entirely (except moderators).
        $moderator = ModerationController::canModerate($actor, $room);
        $event = null;
        if ((int) ($channel['event_id'] ?? 0)) {
            $event = Database::row('SELECT * FROM events WHERE id = ?', [(int) $channel['event_id']]);
        }
        $waitingRoom = (int) ($channel['voice_waiting_room'] ?? 0) === 1
            || ($event && (int) ($event['waiting_room'] ?? 0) === 1);

        if (!$moderator && LiveKitService::roomLocked($room)) {
            json_out(['error' => 'This voice room is locked by a moderator.'], 403);
        }
        if (!$moderator && $waitingRoom) {
            // Wait: session row only (heartbeated by the status poll); the host
            // admits later, and the fresh token is handed over via session.mint.
            self::upsertSession($actor, $room, 'channel', null, true);
            json_out(['ok' => true, 'waiting' => true, 'room' => $room]);
        }

        LiveKitService::pruneStale();
        $status = LiveKitService::status();
        if ($status['full']) {
            json_out(['error' => "Voice is full ({$status['active']}/{$status['max']}). Try again later."], 409);
        }

        $token = LiveKitService::token($room, $actor, LiveKitService::maxUsers());
        self::upsertSession($actor, $room, 'channel', $token);

        json_out([
            'ok' => true,
            'url' => LiveKitService::url(),
            'token' => $token,
            'room' => $room,
            'talker_cap' => LiveKitService::talkerCap($actor),
            'bitrate' => LiveKitService::bitrate($actor),
            'can_moderate' => $moderator,
        ]);
    }

    /** POST /api/webrtc/voice/leave */
    public static function leave(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $sess = self::deleteSession($actor);
        if ($sess && $sess['kind'] === 'call' && str_starts_with((string) $sess['room'], 'call_')) {
            // Mark the leaver's participant row; only 1:1/host leaves end the
            // whole call (group calls survive a single member hanging up).
            $call = Database::row('SELECT * FROM call_sessions WHERE room = ?', [$sess['room']]);
            if ($call) {
                $me = (int) $actor['id'];
                $isGuest = Auth::isGuest($actor);
                if (!$isGuest && (int) ($call['caller_user_id'] ?? 0) === $me) {
                    Database::query("UPDATE call_sessions SET status = 'ended' WHERE id = ?", [(int) $call['id']]);
                } elseif ($isGuest && (int) ($call['caller_guest_id'] ?? 0) === $me) {
                    Database::query("UPDATE call_sessions SET status = 'ended' WHERE id = ?", [(int) $call['id']]);
                } else {
                    Database::query(
                        'UPDATE call_participants SET status = "left" WHERE call_id = ? AND '
                            . ($isGuest ? 'guest_id = ? AND user_id IS NULL' : 'user_id = ? AND guest_id IS NULL'),
                        [(int) $call['id'], $me]
                    );
                    // Last man out ends the call.
                    $remaining = (int) Database::scalar(
                        "SELECT COUNT(*) FROM call_participants WHERE call_id = ? AND status IN ('invited', 'joined')",
                        [(int) $call['id']]
                    );
                    if ($remaining === 0 && $call['status'] !== 'ended') {
                        Database::query("UPDATE call_sessions SET status = 'ended' WHERE id = ?", [(int) $call['id']]);
                    }
                }
            }
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

    /** A call is a group call once anyone beyond the original 1:1 pair was invited. */
    private static function callIsGroup(array $call): bool
    {
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM call_participants WHERE call_id = ? AND status IN ('invited', 'joined', 'declined')",
            [(int) $call['id']]
        );
        return $n > 2;
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

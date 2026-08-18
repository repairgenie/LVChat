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
 * ModerationController — host controls for voice rooms (Slack/Teams-style).
 *
 * Routes (registered by routes.php):
 *   POST /api/webrtc/moderate {room, action, identity?, value?}
 *
 * Actions:
 *   kick {identity}          — remove a participant (LiveKit + session row)
 *   mute / unmute {identity} — host-mute one participant's mic (track mute)
 *   mute_all / unmute_all    — mute every publisher in the room
 *   lock / unlock            — block new joins at the app gate (room flag)
 *   admit {identity}         — let a waiting-room occupant into the meeting
 *   deny {identity}          — reject a waiting occupant (removes session)
 *   waiting_room {value}     — ops+/founder toggles the lobby for the room
 *
 * Authority is room-shaped: channel voice → ops+ (ChannelService::canManageChannel,
 * same gate as channel settings); event rooms → the event founder; call rooms →
 * the caller (the host). Guests holding no elevated role can never moderate.
 *
 * The heavy lifting (kick, track mute, permission flips) goes through
 * LiveKitService's admin API (Twirp) — see LiveKitService.php.
 */
final class ModerationController
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

    /** The room's kind + anchor row, for authority decisions. */
    private static function roomKind(string $room): array
    {
        if (str_starts_with($room, 'chan:')) {
            $slug = substr($room, 5);
            $channel = Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', [$slug]);
            if (!$channel) {
                return ['kind' => 'none', 'channel' => null, 'event' => null, 'call' => null];
            }
            $event = null;
            if ((int) ($channel['event_id'] ?? 0)) {
                $event = Database::row('SELECT * FROM events WHERE id = ?', [(int) $channel['event_id']]);
            }
            return ['kind' => 'channel', 'channel' => $channel, 'event' => $event, 'call' => null];
        }
        if (str_starts_with($room, 'call_')) {
            $call = Database::row('SELECT * FROM call_sessions WHERE room = ?', [$room]);
            return ['kind' => 'call', 'channel' => null, 'event' => null, 'call' => $call];
        }
        return ['kind' => 'none', 'channel' => null, 'event' => null, 'call' => null];
    }

    /** Can this actor moderate the given room? */
    public static function canModerate(array $actor, string $room): bool
    {
        $info = self::roomKind($room);
        if ($info['kind'] === 'channel') {
            // Event rooms are moderated by the founder; other channels by ops+.
            if ($info['event']) {
                return !Auth::isGuest($actor)
                    && (int) $info['event']['founder_id'] === (int) $actor['id'];
            }
            return ChannelService::canManageChannel($info['channel'], $actor);
        }
        if ($info['kind'] === 'call' && $info['call']) {
            // The caller is the host of the call (owns ring/end flow).
            $call = $info['call'];
            if (Auth::isGuest($actor)) {
                return (int) ($call['caller_guest_id'] ?? 0) === (int) $actor['id'];
            }
            return (int) ($call['caller_user_id'] ?? 0) === (int) $actor['id'];
        }
        return false;
    }

    /** Resolve a participant's voice_sessions row by identity, if present. */
    private static function sessionForIdentity(string $room, string $identity): ?array
    {
        $parts = LiveKitService::parseIdentity($identity);
        if (!$parts) {
            return null;
        }
        if (isset($parts['user_id'])) {
            return Database::row(
                'SELECT * FROM voice_sessions WHERE room = ? AND user_id = ?',
                [$room, $parts['user_id']]
            ) ?: null;
        }
        return Database::row(
            'SELECT * FROM voice_sessions WHERE room = ? AND guest_id = ?',
            [$room, $parts['guest_id']]
        ) ?: null;
    }

    /** POST /api/webrtc/moderate */
    public static function moderate(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();

        $room = trim((string) ($_POST['room'] ?? ''));
        $action = trim((string) ($_POST['action'] ?? ''));
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $value = (int) ($_POST['value'] ?? 0) === 1;

        if ($room === '' || $action === '') {
            json_out(['error' => 'Missing room or action.'], 400);
        }
        if (strlen($room) > 200 || strlen($identity) > 64) {
            json_out(['error' => 'Invalid input.'], 400);
        }

        $timid = Auth::isGuest($actor) ? ('g' . (int) $actor['id']) : ('u' . (int) $actor['id']);

        // The "me" identity is twice-protected: neither kick nor mute can be
        // aimed at ourselves through this endpoint (the client mutes itself
        // locally; host actions target other participants).
        if ($identity !== '' && $identity === $timid) {
            json_out(['error' => 'You cannot moderate yourself.'], 400);
        }

        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        if (!self::canModerate($actor, $room)) {
            json_out(['error' => 'You do not have moderator access to this room.'], 403);
        }
        if (!LiveKitService::rateLimit('voice-moderate:' . $timid, 120, 60)) {
            json_out(['error' => 'Too many moderation actions. Please slow down.'], 429);
        }

        $info = self::roomKind($room);

        switch ($action) {
            case 'kick':
                self::requireIdentity($identity);
                self::kick($room, $identity);
                break;

            case 'mute':
            case 'unmute':
                self::requireIdentity($identity);
                $muted = $action === 'mute';
                if (!LiveKitService::muteParticipant($room, $identity, $muted)) {
                    json_out(['error' => 'Could not mute that participant (no audio track connected).'], 400);
                }
                log_audit('voice_' . ($muted ? 'mute' : 'unmute'), $room, $identity . ' by ' . ($actor['username'] ?? 'user'));
                break;

            case 'mute_all':
            case 'unmute_all':
                $muted = $action === 'mute_all';
                $hit = LiveKitService::muteAll($room, $muted);
                log_audit('voice_' . $action, $room, count($hit) . ' participants by ' . ($actor['username'] ?? 'user'));
                break;

            case 'lock':
            case 'unlock':
                $locked = $action === 'lock';
                LiveKitService::setRoomLocked($room, $locked);
                log_audit('voice_' . $action, $room, 'by ' . ($actor['username'] ?? 'user'));
                break;

            case 'admit':
                self::requireIdentity($identity);
                self::admit($room, $identity);
                break;

            case 'deny':
                self::requireIdentity($identity);
                self::deny($room, $identity);
                break;

            case 'waiting_room':
                self::setWaitingRoom($info, $value, $actor);
                break;

            default:
                json_out(['error' => 'Unknown action.'], 400);
        }

        json_out(['ok' => true]);
    }

    /** Kick a participant: LiveKit removal + app session row. */
    private static function kick(string $room, string $identity): void
    {
        LiveKitService::removeParticipant($room, $identity);
        $sess = self::sessionForIdentity($room, $identity);
        if ($sess) {
            Database::query('DELETE FROM voice_sessions WHERE id = ?', [(int) $sess['id']]);
            self::kickSessionCleanup($sess);
        }
        // If the room is now empty, let LiveKit reclaim it (also resets the
        // room so max_participants/flag changes apply on the next join).
        if (Database::scalar('SELECT COUNT(*) FROM voice_sessions WHERE room = ?', [$room]) === 0) {
            LiveKitService::deleteRoom($room);
        }
        log_audit('voice_kick', $room, $identity);
    }

    /** On kick/deny of a call participant: end a 1:1 call cleanly. */
    private static function kickSessionCleanup(array $sess): void
    {
        if (($sess['kind'] ?? '') !== 'call' || !str_starts_with((string) $sess['room'], 'call_')) {
            return;
        }
        $left = Database::row(
            'SELECT COUNT(*) AS n FROM voice_sessions WHERE room = ? AND id != ?',
            [(string) $sess['room'], (int) $sess['id']]
        );
        // For 1:1 calls the kicked side ends the call; group calls keep going.
        if ((int) ($left['n'] ?? 0) === 0) {
            Database::query(
                "UPDATE call_sessions SET status = 'ended' WHERE room = ? AND status NOT IN ('ended', 'cancelled')",
                [(string) $sess['room']]
            );
        }
    }

    /** Admit a waiting occupant: clear waiting, mint a fresh join token. */
    private static function admit(string $room, string $identity): void
    {
        $sess = self::sessionForIdentity($room, $identity);
        if (!$sess) {
            json_out(['error' => 'No one is waiting with that identity.'], 404);
        }
        if ((int) ($sess['waiting'] ?? 0) !== 1) {
            // Already in — nothing to admit.
            return;
        }
        if (LiveKitService::roomLocked($room)) {
            json_out(['error' => 'The room is locked — unlock it before admitting.'], 409);
        }

        // Resolve the actor for token minting (identity → user or guest).
        $parts = LiveKitService::parseIdentity($identity);
        $isGuest = isset($parts['guest_id']);
        $actorId = (int) ($isGuest ? $parts['guest_id'] : $parts['user_id']);
        $actor = $isGuest
            ? (Database::row('SELECT * FROM guests WHERE id = ?', [$actorId]) ?: ['id' => $actorId, 'guest' => 1, 'nick' => 'guest'])
            : (Database::row('SELECT * FROM users WHERE id = ?', [$actorId]) ?: ['id' => $actorId, 'guest' => 0, 'username' => 'user']);

        $token = LiveKitService::token($room, $actor, LiveKitService::maxUsers());
        Database::query(
            'UPDATE voice_sessions SET waiting = 0, token = ?, mint = ?, last_seen = datetime("now") WHERE id = ?',
            [$token, $token, (int) $sess['id']]
        );
        log_audit('voice_admit', $room, $identity . ' by ' . (Auth::user()['username'] ?? 'user'));
    }

    /** Deny a waiting occupant: remove the session so their client drops out. */
    private static function deny(string $room, string $identity): void
    {
        $sess = self::sessionForIdentity($room, $identity);
        if (!$sess) {
            return; // already gone — idempotent
        }
        Database::query('DELETE FROM voice_sessions WHERE id = ?', [(int) $sess['id']]);
        LiveKitService::removeParticipant($room, $identity);
        log_audit('voice_deny', $room, $identity . ' by ' . (Auth::user()['username'] ?? 'user'));
    }

    /** ops+/founder toggles the waiting room flag for the room's source. */
    private static function setWaitingRoom(array $info, bool $value, array $actor): void
    {
        if ($info['kind'] !== 'channel') {
            json_out(['error' => 'Waiting room only applies to channel / event rooms.'], 400);
        }
        if ($info['event']) {
            Database::query('UPDATE events SET waiting_room = ? WHERE id = ?', [$value ? 1 : 0, (int) $info['event']['id']]);
        } else {
            // Channels: voice_waiting_room requires the same ops+ gate already
            // enforced by canModerate() for this room.
            Database::query('UPDATE channels SET voice_waiting_room = ? WHERE id = ?', [$value ? 1 : 0, (int) $info['channel']['id']]);
        }
        log_audit('voice_waiting_room_' . ($value ? 'on' : 'off'), $info['channel']['slug'] ?? '', ($actor['username'] ?? 'user'));
    }

    private static function requireIdentity(string $identity): void
    {
        if ($identity === '') {
            json_out(['error' => 'Missing identity.'], 400);
        }
    }
}
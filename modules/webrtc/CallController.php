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
 * CallController — one-on-one audio call state machine for the WebRTC module.
 *
 * Routes (registered by routes.php):
 *   POST /api/webrtc/call/initiate  {user}     → caller creates a ringing call
 *   POST /api/webrtc/call/accept    {call_id}  → callee accepts (returns join payload)
 *   POST /api/webrtc/call/decline   {call_id}  → callee declines
 *   POST /api/webrtc/call/join      {call_id}  → any participant once active (token)
 *   POST /api/webrtc/call/end       {call_id}  → any participant hangs up
 *
 * Ring/accept is driven by GET /api/webrtc/voice/status (both sides poll it):
 * the caller sees `outgoing` until the callee accepts, then `active`; the
 * callee sees `incoming` while ringing. Status also expires unanswered calls
 * to 'missed' after 30 s. Tokens are minted at connect time only (accept/join)
 * so a 60 s TTL is never a problem.
 */
final class CallController
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

    private static function resolveCall(int $id): ?array
    {
        return Database::row('SELECT * FROM call_sessions WHERE id = ?', [$id]);
    }

    /** Whether the actor participates in this call. */
    private static function isParticipant(array $call, array $actor): bool
    {
        $id = (int) $actor['id'];
        if (Auth::isGuest($actor)) {
            return (int) ($call['caller_guest_id'] ?? 0) === $id || (int) ($call['callee_guest_id'] ?? 0) === $id;
        }
        return (int) ($call['caller_user_id'] ?? 0) === $id || (int) ($call['callee_user_id'] ?? 0) === $id;
    }

    /** The actor is the callee side of this call. */
    private static function isCallee(array $call, array $actor): bool
    {
        $id = (int) $actor['id'];
        if (Auth::isGuest($actor)) {
            return (int) ($call['callee_guest_id'] ?? 0) === $id;
        }
        return (int) ($call['callee_user_id'] ?? 0) === $id;
    }

    private static function peer(array $call, array $actor): string
    {
        $callerId = (int) ($call['caller_user_id'] ?? 0);
        $callerGuest = (int) ($call['caller_guest_id'] ?? 0);
        $calleeId = (int) ($call['callee_user_id'] ?? 0);
        $calleeGuest = (int) ($call['callee_guest_id'] ?? 0);
        $me = (int) $actor['id'];
        $meGuest = Auth::isGuest($actor) ? $me : 0;
        if (($callerId === $me && !$meGuest) || ($callerGuest === $me && $meGuest)) {
            return self::name($calleeId, $calleeGuest);
        }
        return self::name($callerId, $callerGuest);
    }

    private static function name(int $userId, int $guestId): string
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

    private static function joinPayload(array $call, array $actor): array
    {
        $token = LiveKitService::token($call['room'], $actor, LiveKitService::maxUsers());
        Database::query(
            'DELETE FROM voice_sessions WHERE ' . (Auth::isGuest($actor) ? 'guest_id' : 'user_id') . ' = ?',
            [(int) $actor['id']]
        );
        Database::query(
            'INSERT INTO voice_sessions (' . (Auth::isGuest($actor) ? 'guest_id' : 'user_id')
                . ', room, kind, token) VALUES (?, ?, "call", ?)',
            [(int) $actor['id'], $call['room'], $token]
        );
        return [
            'ok' => true,
            'url' => LiveKitService::url(),
            'token' => $token,
            'room' => $call['room'],
            'peer' => self::peer($call, $actor),
        ];
    }

    private static function endSessionsForCall(array $call): void
    {
        Database::query(
            'DELETE FROM voice_sessions WHERE room = ?',
            [$call['room']]
        );
    }

    /** POST /api/webrtc/call/initiate */
    public static function initiate(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        $nick = trim((string) ($_POST['user'] ?? ''));
        if ($nick === '') {
            json_out(['error' => 'Missing user.'], 400);
        }
        $target = Auth::findActor($nick);
        if (!$target) {
            json_out(['error' => 'User not found.'], 404);
        }
        if (strcasecmp((string) $target['username'], (string) $actor['username']) === 0) {
            json_out(['error' => 'You cannot call yourself.'], 400);
        }

        LiveKitService::expireCalls();
        // Busy: caller or callee already in a ringing/active call.
        $me = (int) $actor['id'];
        $busy = Database::scalar(
            'SELECT 1 FROM call_sessions WHERE status IN ("ringing", "active")
               AND (caller_user_id = ? OR caller_guest_id = ? OR callee_user_id = ? OR callee_guest_id = ?)',
            [$me, $me, $me, $me]
        );
        if ($busy) {
            json_out(['error' => 'You are already in a call.'], 409);
        }

        // Blocked either direction (friendships status='blocked'); guests skip.
        if (!Auth::isGuest($actor) && !Auth::isGuest($target)) {
            $blocked = Database::scalar(
                'SELECT 1 FROM friendships WHERE status = "blocked"
                   AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))',
                [(int) $actor['id'], (int) $target['id'], (int) $target['id'], (int) $actor['id']]
            );
            if ($blocked) {
                json_out(['error' => 'You cannot call this user.'], 403);
            }
        }

        $isCallerGuest = Auth::isGuest($actor);
        $isTargetGuest = Auth::isGuest($target);
        $room = 'call_' . bin2hex(random_bytes(6));
        Database::query(
            'INSERT INTO call_sessions (room, caller_user_id, caller_guest_id, callee_user_id, callee_guest_id, status)
             VALUES (?, ?, ?, ?, ?, "ringing")',
            [
                $room,
                $isCallerGuest ? null : (int) $actor['id'],
                $isCallerGuest ? (int) $actor['id'] : null,
                $isTargetGuest ? null : (int) $target['id'],
                $isTargetGuest ? (int) $target['id'] : null,
            ]
        );
        $callId = (int) Database::lastId();

        json_out([
            'ok' => true,
            'call_id' => $callId,
            'room' => $room,
            'peer' => (string) $target['username'],
        ]);
    }

    /** POST /api/webrtc/call/accept */
    public static function accept(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        if ($call['status'] !== 'ringing') {
            json_out(['error' => 'Call is no longer ringing.'], 409);
        }
        if (!self::isCallee($call, $actor)) {
            json_out(['error' => 'Only the callee can accept.'], 403);
        }
        Database::query(
            "UPDATE call_sessions SET status = 'active', answered_at = datetime('now') WHERE id = ?",
            [(int) $call['id']]
        );
        $call['status'] = 'active';
        json_out(self::joinPayload($call, $actor));
    }

    /** POST /api/webrtc/call/decline */
    public static function decline(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        if ($call['status'] !== 'ringing') {
            json_out(['error' => 'Call is no longer ringing.'], 409);
        }
        Database::query("UPDATE call_sessions SET status = 'declined' WHERE id = ?", [(int) $call['id']]);
        json_out(['ok' => true]);
    }

    /** POST /api/webrtc/call/join — any participant once the call is active. */
    public static function join(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        if ($call['status'] !== 'active') {
            json_out(['error' => 'Call is not active yet.'], 409);
        }
        json_out(self::joinPayload($call, $actor));
    }

    /** POST /api/webrtc/call/end — any participant hangs up. */
    public static function end(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        if ($call['status'] === 'ringing') {
            // Caller cancels while ringing → 'cancelled'; callee uses decline().
            Database::query("UPDATE call_sessions SET status = 'cancelled' WHERE id = ?", [(int) $call['id']]);
        } elseif ($call['status'] !== 'ended') {
            Database::query("UPDATE call_sessions SET status = 'ended' WHERE id = ?", [(int) $call['id']]);
        }
        self::endSessionsForCall($call);
        json_out(['ok' => true]);
    }
}

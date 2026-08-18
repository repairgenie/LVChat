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
 * CallController — call state machine for the WebRTC module.
 *
 * Routes (registered by routes.php):
 *   POST /api/webrtc/call/initiate  {user, title?}  → creates a ringing 1:1 call
 *   POST /api/webrtc/call/invite     {call_id, users} → host grows the call
 *   POST /api/webrtc/call/accept     {call_id} → any invited participant joins
 *   POST /api/webrtc/call/decline    {call_id} → any invited participant declinal
 *   POST /api/webrtc/call/join       {call_id} → re-enter an active call (token)
 *   POST /api/webrtc/call/end        {call_id} → hang up (host ends for all)
 *
 * A call is a LiveKit room named `call_<hex>`. The legacy 1:1 shape is
 * preserved on call_sessions (caller/callee columns); call_participants
 * generalizes it to group calls (Discord-style "grow the call" invites):
 * the caller is the host, invited actors appear in the callee's status as
 * `incoming`, and any invited participant can accept/decline while the call
 * is active. Ending a 1:1 call ends it for both; in a group call a non-host
 * who hangs up just leaves, while the host ends it for everyone.
 *
 * Ring/accept rides GET /api/webrtc/voice/status (both sides poll): the
 * caller sees `outgoing` until accept, then `active`; invited actors see
 * `incoming`; status also expires unanswered calls to 'missed'. Calls from
 * blocked or muted users are never surfaced. Tokens are minted at connect
 * time only (accept/join), so the 60 s TTL is never a problem.
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

    /** The participant row for an actor in a call, if any. */
    private static function participantRow(array $call, array $actor): ?array
    {
        $isGuest = Auth::isGuest($actor);
        $row = Database::row(
            'SELECT * FROM call_participants WHERE call_id = ? AND '
                . ($isGuest ? 'guest_id = ? AND user_id IS NULL' : 'user_id = ? AND guest_id IS NULL'),
            [(int) $call['id'], (int) $actor['id']]
        );
        return $row ?: null;
    }

    /** Whether the actor participates in this call (legacy columns or rows). */
    private static function isParticipant(array $call, array $actor): bool
    {
        $id = (int) $actor['id'];
        if (Auth::isGuest($actor)) {
            return (int) ($call['caller_guest_id'] ?? 0) === $id || (int) ($call['callee_guest_id'] ?? 0) === $id
                || self::participantRow($call, $actor) !== null;
        }
        return (int) ($call['caller_user_id'] ?? 0) === $id || (int) ($call['callee_user_id'] ?? 0) === $id
            || self::participantRow($call, $actor) !== null;
    }

    /** The actor is the initiating caller (host) of this call. */
    private static function isHost(array $call, array $actor): bool
    {
        $id = (int) $actor['id'];
        if (Auth::isGuest($actor)) {
            return (int) ($call['caller_guest_id'] ?? 0) === $id;
        }
        return (int) ($call['caller_user_id'] ?? 0) === $id;
    }

    /** A call is "large" when more than the original 2 were invited. */
    private static function isGroupCall(array $call): bool
    {
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM call_participants WHERE call_id = ? AND status IN ('invited', 'joined', 'declined')",
            [(int) $call['id']]
        );
        return $n > 2;
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

    /** Mark the actor's participant row as joined (no-op for legacy actors). */
    private static function markJoined(array $call, array $actor): void
    {
        $row = self::participantRow($call, $actor);
        if ($row) {
            Database::query(
                "UPDATE call_participants SET status = 'joined', joined_at = datetime('now') WHERE id = ?",
                [(int) $row['id']]
            );
        }
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
        self::markJoined($call, $actor);
        $title = (string) ($call['title'] ?? '');
        return [
            'ok' => true,
            'url' => LiveKitService::url(),
            'token' => $token,
            'room' => $call['room'],
            'peer' => self::peer($call, $actor),
            'title' => $title !== '' ? $title : (self::isGroupCall($call) ? 'Group call' : self::peer($call, $actor)),
        ];
    }

    private static function endSessionsForCall(array $call): void
    {
        Database::query(
            'DELETE FROM voice_sessions WHERE room = ?',
            [$call['room']]
        );
    }

    /** The actor already sits in a live call? (busy gate, invite gate.) */
    private static function busyInCall(array $actor, ?int $exceptCallId = null): bool
    {
        $me = (int) $actor['id'];
        $cols = $exceptCallId === null ? '' : ' AND id != ?';
        $params = $exceptCallId === null ? [$me, $me, $me, $me] : [$exceptCallId, $me, $me, $me, $me];
        $busy = Database::scalar(
            'SELECT 1 FROM call_sessions WHERE status IN ("ringing", "active")
               AND (caller_user_id = ? OR caller_guest_id = ? OR callee_user_id = ? OR callee_guest_id = ?)'
                . $cols,
            $params
        );
        if ($busy) {
            return true;
        }
        // Group invites also hold the actor busy until they decline/leave.
        return (bool) Database::scalar(
            "SELECT 1 FROM call_participants cp
             JOIN call_sessions cs ON cs.id = cp.call_id
             WHERE cs.status IN ('ringing', 'active')
               AND cp.status IN ('invited', 'joined')
               AND (cp.user_id = ? OR cp.guest_id = ?)"
                . ($exceptCallId !== null ? ' AND cp.call_id != ?' : ''),
            $exceptCallId !== null ? [$me, $me, $exceptCallId] : [$me, $me]
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
        if (class_exists('SaaSService') && !SaaSService::feature($actor, 'voice')) {
            json_out(['error' => 'Voice is not available on your plan.'], 403);
        }
        $nick = trim((string) ($_POST['user'] ?? ''));
        if ($nick === '') {
            json_out(['error' => 'Missing user.'], 400);
        }
        if (!LiveKitService::rateLimit('call-initiate:' . LiveKitService::identity($actor), 6, 60)) {
            json_out(['error' => 'Too many outgoing calls. Please wait a moment.'], 429);
        }
        $target = Auth::findActor($nick);
        if (!$target) {
            json_out(['error' => 'User not found.'], 404);
        }
        if (strcasecmp((string) $target['username'], (string) $actor['username']) === 0) {
            json_out(['error' => 'You cannot call yourself.'], 400);
        }

        LiveKitService::expireCalls();
        if (self::busyInCall($actor)) {
            json_out(['error' => 'You are already in a call.'], 409);
        }
        if (self::busyInCall($target)) {
            json_out(['error' => 'That user is already in a call.'], 409);
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
        $title = trim((string) ($_POST['title'] ?? ''));
        Database::query(
            'INSERT INTO call_sessions (room, caller_user_id, caller_guest_id, callee_user_id, callee_guest_id, title, status)
             VALUES (?, ?, ?, ?, ?, ?, "ringing")',
            [
                $room,
                $isCallerGuest ? null : (int) $actor['id'],
                $isCallerGuest ? (int) $actor['id'] : null,
                $isTargetGuest ? null : (int) $target['id'],
                $isTargetGuest ? (int) $target['id'] : null,
                $title !== '' ? $title : null,
            ]
        );
        $callId = (int) Database::lastId();

        // Seed the participant roster: host is joined, callee is invited.
        Database::query(
            'INSERT INTO call_participants (call_id, user_id, guest_id, status, role) VALUES (?, ?, ?, "joined", "host")',
            [$callId, $isCallerGuest ? null : (int) $actor['id'], $isCallerGuest ? (int) $actor['id'] : null]
        );
        Database::query(
            'INSERT INTO call_participants (call_id, user_id, guest_id, status, role) VALUES (?, ?, ?, "invited", "member")',
            [$callId, $isTargetGuest ? null : (int) $target['id'], $isTargetGuest ? (int) $target['id'] : null]
        );

        json_out([
            'ok' => true,
            'call_id' => $callId,
            'room' => $room,
            'peer' => (string) $target['username'],
            'title' => $title !== '' ? $title : null,
            'ring_seconds' => LiveKitService::ringSeconds(),
        ]);
    }

    /** POST /api/webrtc/call/invite — the host grows a call into a group call. */
    public static function invite(): void
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
        if (!self::isHost($call, $actor)) {
            json_out(['error' => 'Only the host can invite people.'], 403);
        }
        if ($call['status'] !== 'active') {
            json_out(['error' => 'The call is not active yet.'], 409);
        }
        $nicks = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($_POST['users'] ?? ''))));
        if (!$nicks) {
            json_out(['error' => 'Missing users.'], 400);
        }
        if (!LiveKitService::rateLimit('call-invite:' . LiveKitService::identity($actor), 20, 60)) {
            json_out(['error' => 'Too many call invites. Please slow down.'], 429);
        }

        $added = [];
        $unknown = [];
        $busy = [];
        foreach ($nicks as $nick) {
            $target = Auth::findActor($nick);
            if (!$target) {
                $unknown[] = $nick;
                continue;
            }
            if (strcasecmp((string) $target['username'], (string) $actor['username']) === 0) {
                $busy[] = $nick;
                continue;
            }
            if (self::isParticipant($call, $target)) {
                $busy[] = $nick; // already on the call
                continue;
            }
            if (self::busyInCall($target, (int) $call['id'])) {
                $busy[] = $nick;
                continue;
            }
            $isGuest = Auth::isGuest($target);
            Database::query(
                'INSERT INTO call_participants (call_id, user_id, guest_id, status, role) VALUES (?, ?, ?, "invited", "member")',
                [$call['id'], $isGuest ? null : (int) $target['id'], $isGuest ? (int) $target['id'] : null]
            );
            $call['title'] = $call['title'] ?: 'Group call';
            if ($call['title'] === 'Group call') {
                // Extend the auto-title as the call grows.
            }
            $added[] = (string) $target['username'];
        }

        log_audit('call_invite', (string) $call['id'], count($added) . ' added: ' . implode(', ', $added));
        json_out(['ok' => true, 'added' => $added, 'unknown' => $unknown, 'busy' => $busy]);
    }

    /** POST /api/webrtc/call/accept — any invited participant accepts. */
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
        $row = self::participantRow($call, $actor);
        if ($call['status'] === 'ringing') {
            // Only the invited (callee) participant accepts the initial ring.
            if (!$row || ($row['status'] ?? '') !== 'invited') {
                json_out(['error' => 'Call is no longer ringing.'], 409);
            }
            Database::query(
                "UPDATE call_sessions SET status = 'active', answered_at = datetime('now') WHERE id = ?",
                [(int) $call['id']]
            );
            $call['status'] = 'active';
        } else {
            // Group invite: the call is already active — joining is the accept.
            if (!$row || $row['status'] !== 'invited') {
                json_out(['error' => 'You are not invited to this call.'], 409);
            }
        }
        json_out(self::joinPayload($call, $actor));
    }

    /** POST /api/webrtc/call/decline — anyone invited may decline. */
    public static function decline(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        $row = self::participantRow($call, $actor);
        if ($call['status'] === 'ringing') {
            if ((int) ($call['callee_user_id'] ?? 0) !== (int) $actor['id']
                && (int) ($call['callee_guest_id'] ?? 0) !== (int) $actor['id']) {
                json_out(['error' => 'Only the callee can decline.'], 403);
            }
            Database::query("UPDATE call_sessions SET status = 'declined' WHERE id = ?", [(int) $call['id']]);
            LiveKitService::logCallOutcome($call, 'declined');
            json_out(['ok' => true]);
        }
        if ($row) {
            Database::query("UPDATE call_participants SET status = 'declined' WHERE id = ?", [(int) $row['id']]);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/webrtc/call/join — any joined participant re-enters. */
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

    /** POST /api/webrtc/call/end — hang up. Host ends for everyone. */
    public static function end(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        $call = self::resolveCall((int) ($_POST['call_id'] ?? 0));
        if (!$call || !self::isParticipant($call, $actor)) {
            json_out(['error' => 'Call not found.'], 404);
        }
        $durationSec = null;
        if ($call['answered_at']) {
            $durationSec = max(0, (int) (time() - strtotime((string) $call['answered_at'])));
        }
        if ($call['status'] === 'ringing') {
            // Caller cancels while ringing → 'cancelled'; callee uses decline().
            Database::query("UPDATE call_sessions SET status = 'cancelled' WHERE id = ?", [(int) $call['id']]);
            LiveKitService::logCallOutcome($call, 'cancelled');
            self::endSessionsForCall($call);
            json_out(['ok' => true]);
        }

        $isGroup = self::isGroupCall($call);
        $hostLeft = self::isHost($call, $actor);
        $row = self::participantRow($call, $actor);

        if ($hostLeft || !$isGroup) {
            // 1:1 calls end for both sides when either hangs up; the host's
            // hang-up always ends the room for everyone.
            if ($call['status'] !== 'ended') {
                Database::query("UPDATE call_sessions SET status = 'ended' WHERE id = ?", [(int) $call['id']]);
                LiveKitService::logCallOutcome($call, 'ended', $durationSec);
            }
            Database::query(
                "UPDATE call_participants SET status = 'left' WHERE call_id = ? AND status IN ('invited', 'joined')",
                [(int) $call['id']]
            );
            self::endSessionsForCall($call);
        } else {
            // Group call: a member hangs up but the call survives.
            if ($row) {
                Database::query("UPDATE call_participants SET status = 'left' WHERE id = ?", [(int) $row['id']]);
            }
            Database::query(
                'DELETE FROM voice_sessions WHERE room = ? AND ' . (Auth::isGuest($actor) ? 'guest_id' : 'user_id') . ' = ?',
                [$call['room'], (int) $actor['id']]
            );
        }
        json_out(['ok' => true]);
    }
}
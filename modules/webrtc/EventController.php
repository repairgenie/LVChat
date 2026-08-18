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
 * EventController — public and private events with optional WebRTC or link-based streams.
 *
 * Events replace the legacy #mtg meeting rooms.  They support:
 *   - Public or private visibility
 *   - WebRTC (LiveKit) interactive meetings or link-based streams (YouTube/Twitch)
 *   - Email invites with unique tokens
 *   - Invite codes for private events
 *   - Scheduled start times with cron-managed channel creation
 *   - Post-event cleanup: kick everyone, email chat log (ZIP with TXT + PDF + images)
 *
 * Routes (registered by routes.php):
 *   POST /api/events/create       → {ok, id, slug, invite_code, invite_url}
 *   POST /api/events/invite       → {ok, sent[], failed[]}
 *   POST /api/events/cancel       → {ok}
 *   GET  /api/events/list         → {events: [...]}
 *   GET  /e/{token}               → invite link landing (login bounce → join)
 *   GET  /event/{slug}            → event channel landing (login bounce → join)
 */
final class EventController
{
    private static function requireActor(): array
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not signed in.'], 401);
        }
        // Events key on registered user ids (founder_id) — guests must not act
        // on events via a colliding guest id.
        if ((int) ($u['guest'] ?? 0) === 1) {
            json_out(['error' => 'Registered users only.'], 401);
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

    /** Generate a URL-safe invite code for private events. */
    private static function generateInviteCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /** Build the invite landing URL for an event. */
    private static function eventUrl(array $event): string
    {
        if (!empty($event['invite_code'])) {
            return '/e/' . rawurlencode($event['invite_code']);
        }
        $slug = $event['channel_slug'] ?? $event['slug'] ?? '';
        return '/event/' . rawurlencode($slug);
    }

    /** POST /api/events/create */
    public static function create(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (Auth::isGuest($actor)) {
            json_out(['error' => 'Registered users only.'], 403);
        }
        if (class_exists('SaaSService') && SaaSService::enabled()) {
            if (!SaaSService::feature($actor, 'events')) {
                json_out(['error' => 'Events are not available on your plan.'], 403);
            }
            $cap = SaaSService::limit($actor, 'events_concurrent');
            if ($cap !== null && SaaSService::eventCount((int) $actor['id']) >= $cap) {
                json_out(['error' => "You have reached the concurrent-event limit ($cap)."], 409);
            }
        }
        if (!LiveKitService::rateLimit('event-create:' . (int) $actor['id'], 10, 3600)) {
            json_out(['error' => 'Too many events created. Please wait a while.'], 429);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            json_out(['error' => 'A title is required.'], 400);
        }
        if (mb_strlen($title) > 120) {
            json_out(['error' => 'Title must be 120 characters or fewer.'], 400);
        }

        $description = trim((string) ($_POST['description'] ?? ''));
        $isPublic = (int) ($_POST['is_public'] ?? 0) === 1;
        $eventType = in_array($_POST['event_type'] ?? '', ['webrtc', 'link']) ? $_POST['event_type'] : 'webrtc';
        $streamUrl = trim((string) ($_POST['stream_url'] ?? ''));
        $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
        $durationMinutes = (int) ($_POST['duration_minutes'] ?? 0);
        $waitingRoom = (int) ($_POST['waiting_room'] ?? 0) === 1;

        // Validate scheduled time.
        if ($scheduledAt !== '') {
            $ts = strtotime($scheduledAt);
            if ($ts === false || $ts < time()) {
                json_out(['error' => 'Scheduled time must be in the future.'], 400);
            }
            $scheduledAt = gmdate('Y-m-d H:i:s', $ts);
        } else {
            $scheduledAt = null;
        }

        if ($durationMinutes < 0 || $durationMinutes > 1440) {
            json_out(['error' => 'Duration must be between 0 and 1440 minutes.'], 400);
        }

        if ($eventType === 'link' && $streamUrl === '') {
            json_out(['error' => 'A stream URL is required for link-type events.'], 400);
        }

        $inviteCode = $isPublic ? null : self::generateInviteCode();

        // For immediate events (no schedule), create the channel right away.
        $channelId = null;
        $slug = null;
        $status = $scheduledAt ? 'scheduled' : 'active';

        if ($status === 'active') {
            $channelId = self::createEventChannel($actor, $title, $eventType, $streamUrl, $isPublic);
            if (is_string($channelId)) {
                json_out(['error' => $channelId], 400);
            }
            $slug = (string) Database::scalar('SELECT slug FROM channels WHERE id = ?', [$channelId]);
        }

        Database::query(
            'INSERT INTO events (channel_id, founder_id, title, description, is_public, event_type, stream_url, invite_code, scheduled_at, duration_minutes, started_at, status, waiting_room)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $channelId,
                (int) $actor['id'],
                $title,
                $description,
                $isPublic ? 1 : 0,
                $eventType,
                $streamUrl !== '' ? $streamUrl : null,
                $inviteCode,
                $scheduledAt,
                $durationMinutes > 0 ? $durationMinutes : null,
                $status === 'active' ? now() : null,
                $status,
                $waitingRoom ? 1 : 0,
            ]
        );
        $eventId = (int) Database::lastId();

        // Link the channel back to the event.
        if ($channelId) {
            Database::query('UPDATE channels SET event_id = ? WHERE id = ?', [$eventId, $channelId]);
        }

        log_audit('event_create', $title, $eventType . ($isPublic ? ' public' : ' private'));

        json_out([
            'ok' => true,
            'id' => $eventId,
            'slug' => $slug,
            'title' => $title,
            'invite_code' => $inviteCode,
            'status' => $status,
            'waiting_room' => $waitingRoom,
            'invite_url' => self::eventUrl([
                'invite_code' => $inviteCode,
                'channel_slug' => $slug,
            ]),
        ]);
    }

    /** POST /api/events/invite — send email invites {event_id, emails} */
    public static function inviteEmails(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (Auth::isGuest($actor)) {
            json_out(['error' => 'Registered users only.'], 403);
        }
        if (!LiveKitService::rateLimit('event-invite:' . (int) $actor['id'], 30, 3600)) {
            json_out(['error' => 'Too many invites sent. Please wait a while.'], 429);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $event = Database::row('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            json_out(['error' => 'Event not found.'], 404);
        }
        if ((int) $event['founder_id'] !== (int) $actor['id']) {
            json_out(['error' => 'Only the event founder can send invites.'], 403);
        }

        $raw = (string) ($_POST['emails'] ?? '');
        $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw)));
        $sent = [];
        $failed = [];

        $siteName = config_get('site_name', 'LVChat');
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = $email;
                continue;
            }
            // Check for duplicate invite.
            $existing = Database::row(
                'SELECT id FROM event_invites WHERE event_id = ? AND email = ?',
                [$eventId, $email]
            );
            if ($existing) {
                $failed[] = $email;
                continue;
            }

            $token = bin2hex(random_bytes(24));
            Database::query(
                'INSERT INTO event_invites (event_id, email, token, invited_by) VALUES (?, ?, ?, ?)',
                [$eventId, $email, $token, (int) $actor['id']]
            );

            $inviteUrl = base_url() . '/e/' . rawurlencode($token);
            $scheduledInfo = '';
            if ($event['scheduled_at']) {
                $scheduledInfo = "\nScheduled: " . $event['scheduled_at'] . ' UTC';
            }

            $text = "You've been invited to an event: {$event['title']}\n"
                . "By: {$actor['username']}\n"
                . $scheduledInfo . "\n\n"
                . "Join here: {$inviteUrl}\n";

            $html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">'
                . '<h2 style="color:#5865F2">Event Invitation</h2>'
                . '<p><strong>' . h($event['title']) . '</strong></p>'
                . '<p>Invited by: <strong>' . h($actor['username']) . '</strong></p>'
                . ($scheduledInfo ? '<p>Scheduled: ' . h($event['scheduled_at']) . ' UTC</p>' : '')
                . '<p><a href="' . h($inviteUrl) . '" style="display:inline-block;padding:12px 24px;background:#5865F2;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold">Join Event</a></p>'
                . '</div>';

            $result = Mailer::send($email, "Invitation: {$event['title']}", $text, $html);
            if ($result['ok']) {
                $sent[] = $email;
            } else {
                $failed[] = $email;
            }
        }

        log_audit('event_invite', $event['title'], count($sent) . ' sent, ' . count($failed) . ' failed');

        json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
    }

    /** POST /api/events/cancel — cancel a scheduled event */
    public static function cancel(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $event = Database::row('SELECT * FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            json_out(['error' => 'Event not found.'], 404);
        }
        if ((int) $event['founder_id'] !== (int) $actor['id']) {
            json_out(['error' => 'Only the event founder can cancel.'], 403);
        }
        if ($event['status'] === 'ended' || $event['status'] === 'cancelled') {
            json_out(['error' => 'Event already ' . $event['status'] . '.'], 400);
        }

        Database::query('UPDATE events SET status = \'cancelled\' WHERE id = ?', [$eventId]);

        // If a channel was created, kick everyone and delete it.
        if ($event['channel_id']) {
            self::cleanupEventChannel((int) $event['channel_id']);
        }

        log_audit('event_cancel', $event['title']);
        json_out(['ok' => true]);
    }

    /** GET /api/events/list — list the current user's events */
    public static function listEvents(): void
    {
        $actor = self::requireActor();
        if (Auth::isGuest($actor)) {
            json_out(['events' => []]);
        }

        $events = Database::all(
            'SELECT e.*, c.slug AS channel_slug
             FROM events e
             LEFT JOIN channels c ON c.id = e.channel_id
             WHERE e.founder_id = ? AND e.status IN (\'scheduled\', \'active\')
             ORDER BY COALESCE(e.scheduled_at, e.created_at) DESC',
            [(int) $actor['id']]
        );

        json_out(['events' => $events]);
    }

    /** GET /e/{token} — email invite link landing */
    public static function inviteLanding(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = Database::row(
            'SELECT ei.*, e.title, e.status, e.channel_id, c.slug AS channel_slug
             FROM event_invites ei
             JOIN events e ON e.id = ei.event_id
             LEFT JOIN channels c ON c.id = e.channel_id
             WHERE ei.token = ?',
            [$token]
        );
        if (!$invite) {
            render_view('errors/notfound', [], null);
        }

        $user = Auth::user();
        if (!$user) {
            redirect('/login?next=' . rawurlencode('/e/' . rawurlencode($token)));
        }

        if ($invite['accepted_at']) {
            // Already accepted — redirect to channel if active.
            if ($invite['channel_slug'] && $invite['status'] === 'active') {
                redirect('/app?channel=' . rawurlencode($invite['channel_slug']));
            }
            render_view('errors/notfound', ['message' => 'This invite has already been used.'], null);
        }

        if ($invite['status'] === 'ended' || $invite['status'] === 'cancelled') {
            render_view('errors/notfound', ['message' => 'This event has ended.'], null);
        }

        // Mark invite as accepted.
        Database::query(
            'UPDATE event_invites SET accepted_at = datetime(\'now\') WHERE id = ?',
            [$invite['id']]
        );

        // If the event has a channel, join it.
        if ($invite['channel_id'] && $invite['channel_slug']) {
            $channel = Database::row('SELECT * FROM channels WHERE id = ?', [$invite['channel_id']]);
            if ($channel) {
                $status = ChannelService::joinStatus($channel, $user);
                if ($status['ok'] || $status['reason'] === 'already_member') {
                    if ($status['reason'] !== 'already_member') {
                        ChannelService::join($channel, $user);
                    }
                    redirect('/app?channel=' . rawurlencode($invite['channel_slug']));
                }
            }
        }

        // Event is scheduled but no channel yet — show a "waiting" page.
        render_view('errors/notfound', ['message' => 'The event has not started yet. You will be able to join once it begins.'], null);
    }

    /** GET /event/{slug} — event channel landing */
    public static function landing(array $params): void
    {
        // Rate limit landing requests per IP: the slug is the access credential
        // for private events, so brute-force enumeration must be throttled even
        // with a 128-bit slug. Tracked in the DB keyed by IP so clearing cookies
        // can't reset the budget.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = 'unknown';
        }
        $hits = Database::row(
            'SELECT hits FROM event_land_limits WHERE ip = ? AND updated_at > datetime("now", "-60 seconds")',
            [$ip]
        );
        $hits = $hits ? (int) $hits['hits'] + 1 : 1;
        if ($hits > 20) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Too many requests. Please slow down.');
        }
        Database::query(
            'INSERT INTO event_land_limits (ip, hits, updated_at) VALUES (?, ?, datetime("now"))
             ON CONFLICT(ip) DO UPDATE SET hits = excluded.hits, updated_at = excluded.updated_at',
            [$ip, $hits]
        );

        $slug = (string) ($params['slug'] ?? '');
        $channel = ChannelService::findBySlug($slug);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }

        $user = Auth::user();
        if (!$user) {
            redirect('/login?next=' . rawurlencode('/event/' . rawurlencode($slug)));
        }

        if (AccessService::member((int) $channel['id'], $user)) {
            redirect('/app?channel=' . rawurlencode($slug));
        }

        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            render_view('chat/denied', ['channel' => $channel, 'reason' => $restriction, 'user' => $user]);
        }

        $status = ChannelService::joinStatus($channel, $user);
        // The landing link itself is the invite: an unguessable event slug
        // grants access, so invite_only must not block a logged-in visitor.
        // Moderation (bans / akicks / forbidden) still applies via joinStatus.
        if (!$status['ok'] && !in_array($status['reason'], ['already_member', 'This channel is invite-only.'], true)) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $status['reason'],
                'user' => $user,
            ]);
        }

        if ($status['reason'] !== 'already_member') {
            ChannelService::join($channel, $user);
        }
        redirect('/app?channel=' . rawurlencode($slug));
    }

    /** ── Internal helpers ──────────────────────────────────────────────── */

    /**
     * Create the IRC-style event channel when an event goes live.
     * Returns the channel ID or an error string.
     */
    public static function createEventChannel(array $actor, string $title, string $eventType, string $streamUrl, bool $isPublic): int|string
    {
        $name = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = '#evt-' . bin2hex(random_bytes(16));
            if (ChannelService::find($candidate) === null) {
                $name = $candidate;
                break;
            }
        }
        if ($name === '') {
            return 'Could not allocate an event channel. Try again.';
        }

        $opts = [
            'visibility' => $isPublic ? 'public' : 'private',
            'invite_only' => true, // Locked until 15 min before start (cron unlocks).
            'topic' => $title,
        ];

        $channel = ChannelService::create($actor, $name, $opts);
        if (is_string($channel)) {
            return $channel;
        }
        $channelId = (int) $channel['id'];

        $updates = ['voice_enabled' => 1, 'invite_only' => 1];
        if ($eventType === 'link' && $streamUrl !== '') {
            $updates['channel_url'] = $streamUrl;
        }
        $setClauses = [];
        $setParams = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "$col = ?";
            $setParams[] = $val;
        }
        $setParams[] = $channelId;
        Database::query('UPDATE channels SET ' . implode(', ', $setClauses) . ' WHERE id = ?', $setParams);

        return $channelId;
    }

    /** Kick all non-founders from an event channel, then delete it. */
    public static function cleanupEventChannel(int $channelId): void
    {
        $ch = Database::row('SELECT * FROM channels WHERE id = ?', [$channelId]);
        if (!$ch) {
            return;
        }
        $event = Database::row('SELECT * FROM events WHERE channel_id = ?', [$channelId]);
        $founderId = $event ? (int) $event['founder_id'] : null;

        // Kick all members except the founder.
        $members = Database::all(
            'SELECT cm.*, u.username FROM channel_members cm
             LEFT JOIN users u ON u.id = cm.user_id
             WHERE cm.channel_id = ?',
            [$channelId]
        );
        foreach ($members as $m) {
            if ($founderId && (int) $m['user_id'] === $founderId) {
                continue;
            }
            if ((int) ($m['level'] ?? 0) >= 5) {
                continue; // Never kick the founder level.
            }
            Database::query('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, $m['user_id']]);
            MessageService::system($channelId, 'kick', ($m['username'] ?? 'user') . ' has been removed from ' . $ch['name']);
            if ($founderId) {
                Realtime::memberRemoved(['id' => (int) $m['user_id'], 'guest' => 0], $ch['slug'], 'event_ended');
            }
        }

        // Delete the channel.
        Database::query('DELETE FROM channel_members WHERE channel_id = ?', [$channelId]);
        Database::query(
            'UPDATE channels SET name = ?, slug = ?, owner_id = NULL, key_hash = NULL, forbidden = 1, event_id = NULL WHERE id = ?',
            ['deleted-' . bin2hex(random_bytes(4)), 'deleted-' . bin2hex(random_bytes(4)), $channelId]
        );
    }
}

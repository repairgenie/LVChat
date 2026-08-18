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
 * RecordingController — LiveKit egress (room composite recordings).
 *
 * Routes (registered by routes.php):
 *   POST /api/webrtc/record {room, action: "start"|"stop"}  → host-only
 *   GET  /api/webrtc/recordings/{id}                        → download (admin/host)
 *
 * Recording is a host-level feature gated by the admin `recording_enabled`
 * flag. Starting a recording asks LiveKit's egress service (via the server's
 * Twirp proxy) to composite the room into an MP4 written under the configured
 * recording path (default data/recordings/); the app tracks the egress id.
 *
 * Egress deployment is optional: if `livekit-egress` + Redis aren't running,
 * StartRoomCompositeEgress fails and the caller sees a friendly "recording
 * not available" error instead of a crash. The app never stores the media
 * itself — it just points the egress service at a file path and records the
 * outcome. Downloads stream the file from disk with an auth check (admins
 * and the person who started the recording).
 */
final class RecordingController
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

    private static function recordingEnabled(): bool
    {
        return (string) (config_get('recording_enabled', '0') ?? '0') === '1';
    }

    /** Does this actor control the given room (host/moderator)? */
    private static function canControl(array $actor, string $room): bool
    {
        if (Auth::isAdmin($actor)) {
            return true;
        }
        return ModerationController::canModerate($actor, $room);
    }

    /** POST /api/webrtc/record */
    public static function record(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();
        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured.'], 403);
        }
        if (!self::recordingEnabled()) {
            json_out(['error' => 'Recording is not enabled by the administrator.'], 403);
        }
        $room = trim((string) ($_POST['room'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');
        if ($room === '' || !in_array($action, ['start', 'stop'], true) || strlen($room) > 200) {
            json_out(['error' => 'Invalid request.'], 400);
        }
        if (!self::canControl($actor, $room)) {
            json_out(['error' => 'You do not have host access to this room.'], 403);
        }

        if ($action === 'start') {
            // One recording per room at a time.
            $exists = Database::row(
                "SELECT id FROM recordings WHERE room = ? AND status IN ('starting', 'active')",
                [$room]
            );
            if ($exists) {
                json_out(['error' => 'This room is already being recorded.'], 409);
            }
            $dir = LiveKitService::recordingsDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                json_out(['error' => 'Recording folder is not writable.'], 500);
            }
            $stamp = gmdate('Ymd_His');
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $room);
            $rel = $safe . '_' . $stamp . '.mp4';
            $r = LiveKitService::startEgress($room, [['kind' => 'file', 'path' => $dir . '/' . $rel]]);
            if (!$r['ok']) {
                json_out(['error' => 'Recording is not available right now (egress service down or not configured).'], 503);
            }
            Database::query(
                'INSERT INTO recordings (room, kind, filename, egress_id, status, started_by_user_id)
                 VALUES (?, ?, ?, ?, "starting", ?)',
                [$room, self::roomKind($room), $rel, (string) ($r['data']['egressId'] ?? ''), (int) $actor['id']]
            );
            log_audit('recording_start', $room, 'by ' . ($actor['username'] ?? 'user'));
            json_out(['ok' => true, 'recording' => ['room' => $room, 'file' => $rel]]);
        }

        // Stop: find our active recording for the room and ask egress to stop.
        $rec = Database::row(
            "SELECT * FROM recordings WHERE room = ? AND status IN ('starting', 'active') ORDER BY id DESC LIMIT 1",
            [$room]
        );
        if (!$rec) {
            json_out(['ok' => true]); // idempotent — nothing running
        }
        $egressId = (string) ($rec['egress_id'] ?? '');
        if ($egressId !== '') {
            LiveKitService::stopEgress($egressId);
        }
        Database::query(
            "UPDATE recordings SET status = 'stopped', stopped_at = datetime('now') WHERE id = ?",
            [(int) $rec['id']]
        );
        log_audit('recording_stop', $room, 'by ' . ($actor['username'] ?? 'user'));
        json_out(['ok' => true]);
    }

    /** Kind for a recording row ('call' | 'channel' | 'event'). */
    private static function roomKind(string $room): string
    {
        if (str_starts_with($room, 'call_')) {
            return 'call';
        }
        return 'channel';
    }

    /** GET /api/webrtc/recordings/{id} — stream the file (admin or starter). */
    public static function download(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $rec = Database::row('SELECT * FROM recordings WHERE id = ?', [$id]);
        if (!$rec || $rec['filename'] === '') {
            http_response_code(404);
            render_view('errors/notfound', [], null);
        }
        $user = Auth::user();
        if (!$user) {
            redirect('/login?next=' . rawurlencode('/api/webrtc/recordings/' . $id));
        }
        $isAdmin = Auth::isAdmin($user);
        $isStarter = !Auth::isGuest($user) && (int) $rec['started_by_user_id'] === (int) $user['id'];
        if (!$isAdmin && !$isStarter) {
            http_response_code(404);
            render_view('errors/notfound', ['message' => 'You are not allowed to download this recording.'], null);
        }
        $file = LiveKitService::recordingsDir() . '/' . $rec['filename'];
        if (!is_file($file)) {
            http_response_code(404);
            render_view('errors/notfound', ['message' => 'The recording file has been removed.'], null);
        }
        header('Content-Type: video/mp4');
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $rec['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
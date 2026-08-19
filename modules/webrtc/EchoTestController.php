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
 * EchoTestController — mic + WebRTC server echo test.
 *
 * Mints a short-lived LiveKit token for a per-user echo room. The client
 * publishes its audio track and subscribes back to hear itself — verifying
 * that the microphone, the WebRTC transport, and the LiveKit SFU are all
 * working end-to-end.
 *
 * Route (registered by routes.php):
 *   POST /api/webrtc/voice/echo-test  — mint a token and return the room URL
 *
 * Auth: browser session cookie + CSRF (POSTs) or the messenger bearer token.
 */
final class EchoTestController
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

    /** POST /api/webrtc/voice/echo-test */
    public static function test(): void
    {
        $actor = self::requireActor();
        self::requireCsrf();

        if (!LiveKitService::enabled()) {
            json_out(['error' => 'Voice is not configured on this server.'], 503);
        }

        if (!LiveKitService::rateLimit('echo-test:' . LiveKitService::identity($actor), 10, 60)) {
            json_out(['error' => 'Too many echo test attempts. Please slow down.'], 429);
        }

        $room = 'echo-test:' . LiveKitService::identity($actor);
        $token = LiveKitService::token($room, $actor, 2);

        json_out([
            'ok' => true,
            'url' => LiveKitService::url(),
            'token' => $token,
            'room' => $room,
        ]);
    }
}

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

/**
 * Push notification settings: browser subscription management, the global
 * per-context toggles, and the per-user mute list. All write endpoints are
 * CSRF-protected and registered-users-only (guests have no push).
 */
final class PushController
{
    private static function requireUser(): array
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        if ((int) ($u['guest'] ?? 0) === 1) {
            json_out(['error' => 'Registered users only.'], 403);
        }
        return $u;
    }

    /** POST /api/push/subscribe — store the browser's push subscription. */
    public static function subscribe(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        $r = PushService::subscribe(
            $user,
            (string) ($_POST['endpoint'] ?? ''),
            (string) ($_POST['p256dh'] ?? ''),
            (string) ($_POST['auth'] ?? '')
        );
        if ($r !== true) {
            json_out(['error' => $r], 400);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/push/unsubscribe — drop all of the user's subscription rows. */
    public static function unsubscribe(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        PushService::unsubscribe($user);
        json_out(['ok' => true]);
    }

    /** POST /api/push/prefs — the three global per-context push toggles. */
    public static function prefs(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        PushService::savePrefs(
            $user,
            ($_POST['channels'] ?? '1') === '1' ? 1 : 0,
            ($_POST['dms'] ?? '1') === '1' ? 1 : 0,
            ($_POST['invites'] ?? '1') === '1' ? 1 : 0
        );
        json_out(['ok' => true, 'prefs' => PushService::prefs($user)]);
    }

    /** GET /api/push/prefs — the per-context toggles, read-only (desktop/messenger clients). */
    public static function prefsGet(): void
    {
        $user = self::requireUser();
        json_out(['ok' => true, 'prefs' => PushService::prefs($user)]);
    }

    /**
     * GET /api/notify/prefs — the full per-user notification preference set
     * (per-context push toggles + master toggles, quiet hours, keywords,
     * previews). Shared by the web profile, desktop bridge and messenger.
     */
    public static function notifyPrefsGet(): void
    {
        $user = self::requireUser();
        json_out(['ok' => true, 'prefs' => [
            'push' => PushService::prefs($user),
            'notify' => NotifyPrefs::get($user),
        ]]);
    }

    /** POST /api/notify/prefs — save any subset of the preference set. */
    public static function notifyPrefs(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        $pushChanged = false;
        foreach (['channels', 'dms', 'invites'] as $k) {
            if (isset($_POST[$k])) {
                $pushChanged = true;
                break;
            }
        }
        if ($pushChanged) {
            [$ch, $dm, $inv] = self::pushBits($user, $_POST);
            PushService::savePrefs($user, $ch, $dm, $inv);
        }
        $err = NotifyPrefs::save($user, $_POST);
        if ($err !== null) {
            json_out(['error' => $err], 400);
        }
        if (isset($_POST['tz_offset_minutes'])) {
            // Remember the client's UTC offset so server-side quiet-hours
            // gating (Web Push) matches the user's local time.
            NotifyPrefs::save($user, ['tz_offset_minutes' => (int) $_POST['tz_offset_minutes']]);
        }
        json_out(['ok' => true, 'prefs' => self::notifyPrefsPayload($user)]);
    }

    private static function notifyPrefsPayload(array $user): array
    {
        return [
            'push' => PushService::prefs($user),
            'notify' => NotifyPrefs::get($user),
        ];
    }

    /** Keep the legacy push-bits save signature happy with partial posts. */
    private static function pushBits(array $user, array $in): array
    {
        $cur = PushService::prefs($user);
        return [
            isset($in['channels']) ? ($in['channels'] === '1' ? 1 : 0) : (int) $cur['channels'],
            isset($in['dms']) ? ($in['dms'] === '1' ? 1 : 0) : (int) $cur['dms'],
            isset($in['invites']) ? ($in['invites'] === '1' ? 1 : 0) : (int) $cur['invites'],
        ];
    }

    /** POST /api/push/test — send a test Web Push to the caller's browsers. */
    public static function test(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        $ok = PushService::sendTest($user);
        if (!$ok) {
            json_out(['error' => 'No push subscription on this browser — enable push first.'], 400);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/push/mute — mute a user across every notification surface. */
    public static function mute(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        $r = PushService::addMute($user, (int) ($_POST['user_id'] ?? 0));
        if ($r !== true) {
            json_out(['error' => $r], 400);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/push/unmute — lift a per-user mute. */
    public static function unmute(): void
    {
        $user = self::requireUser();
        Csrf::verify();
        PushService::removeMute($user, (int) ($_POST['user_id'] ?? 0));
        json_out(['ok' => true]);
    }
}

<?php

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

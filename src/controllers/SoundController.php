<?php

declare(strict_types=1);

/** User-facing sound-alert settings API (prefs + per-user overrides). */
final class SoundController
{
    private static function soundId(mixed $v): ?int
    {
        $id = (int) $v;
        return $id > 0 ? $id : null;
    }

    /** GET /api/sounds — everything a client needs to play alerts: the enabled
     *  sounds, the user's DM/channel choices, and per-sender overrides. */
    public static function sounds(): void
    {
        $user = Auth::require();
        json_out(['ok' => true] + SoundService::soundsForClient($user));
    }

    /** POST /api/sound/prefs — set the DM and channel sounds (0/empty = off). */
    public static function prefs(): void
    {
        $user = Auth::require();
        Csrf::verify();
        SoundService::savePrefs($user, self::soundId($_POST['dm_sound'] ?? null), self::soundId($_POST['channel_sound'] ?? null));
        json_out(['ok' => true]);
    }

    /** POST /api/sound/override — set a specific user's sound (0/empty = mute). */
    public static function setOverride(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $target = (int) ($_POST['target_user_id'] ?? 0);
        $r = SoundService::setOverride($user, $target, self::soundId($_POST['sound'] ?? null));
        if ($r !== true) {
            json_out(['error' => $r], 400);
        }
        json_out(['ok' => true]);
    }

    /** POST /api/sound/override/remove — revert a user to the default sounds. */
    public static function removeOverride(): void
    {
        $user = Auth::require();
        Csrf::verify();
        SoundService::removeOverride($user, (int) ($_POST['target_user_id'] ?? 0));
        json_out(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

/**
 * Sound alerts for channel messages and DMs.
 *
 * - Admins upload audio files (`sound_alerts`); every user can pick any of
 *   them (or mute) for channel messages and for DMs.
 * - Per-user prefs (`user_sound_prefs`): no row = default sound (on by
 *   default); a NULL sound id = that context is off.
 * - Per-user overrides (`user_sound_overrides`): a specific sound (or NULL =
 *   mute) for a particular sender, applied to both their DMs and their
 *   channel messages. No row = follow the default.
 * - The three built-in sounds are generated with a dependency-free pure-PHP
 *   WAV writer so the server never needs ffmpeg.
 */
final class SoundService
{
    private const SOUND_DIR = '/assets/sounds';
    private const AUDIO_MAX_BYTES = 2097152; // 2 MB
    private const ALLOWED_EXT = ['mp3', 'wav', 'ogg', 'oga', 'webm', 'm4a', 'mp4'];
    private const ALLOWED_MIME = [
        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm',
        'audio/mp4', 'audio/x-m4a', 'application/ogg', 'application/mp4',
    ];

    /** Absolute path to the committed runtime sound dir (created if missing). */
    public static function dir(): string
    {
        $abs = ROOT . '/public' . self::SOUND_DIR;
        if (!is_dir($abs)) {
            @mkdir($abs, 0775, true);
        }
        return $abs;
    }

    /** All sound alerts (admin list). */
    public static function listAll(): array
    {
        return Database::all(
            'SELECT s.*, u.username AS created_by_name FROM sound_alerts s
             LEFT JOIN users u ON u.id = s.created_by
             ORDER BY s.id ASC'
        );
    }

    /** Enabled sound alerts, newest last, for user-facing pickers. */
    public static function listEnabled(): array
    {
        return Database::all(
            'SELECT id, name, file FROM sound_alerts WHERE enabled = 1 ORDER BY id ASC'
        );
    }

    public static function soundExists(int $id): bool
    {
        return (bool) Database::scalar('SELECT 1 FROM sound_alerts WHERE id = ?', [$id]);
    }

    /** The default (first enabled) sound, or null when none exist. */
    public static function defaultSoundId(): ?int
    {
        $id = Database::scalar('SELECT id FROM sound_alerts WHERE enabled = 1 ORDER BY id ASC LIMIT 1');
        return $id === false ? null : (int) $id;
    }

    /**
     * The user's effective sound choices. Absence of a prefs row (or guests,
     * who have no settings page) means the defaults — channel and DM sounds on,
     * using the first enabled sound.
     *
     * @return array{dm_sound_id:?int, channel_sound_id:?int}
     */
    public static function prefsFor(array $user): array
    {
        if (!Auth::isGuest($user)) {
            $row = Database::row(
                'SELECT dm_sound_id, channel_sound_id FROM user_sound_prefs WHERE user_id = ?',
                [$user['id']]
            );
            if ($row) {
                return [
                    'dm_sound_id' => $row['dm_sound_id'] === null ? null : (int) $row['dm_sound_id'],
                    'channel_sound_id' => $row['channel_sound_id'] === null ? null : (int) $row['channel_sound_id'],
                ];
            }
        }
        $default = self::defaultSoundId();
        return ['dm_sound_id' => $default, 'channel_sound_id' => $default];
    }

    /** Save the user's per-context sound choices (NULL = that context is off). */
    public static function savePrefs(array $user, ?int $dmSoundId, ?int $channelSoundId): void
    {
        if (Auth::isGuest($user)) {
            return;
        }
        $dm = $dmSoundId !== null && self::soundExists($dmSoundId) ? $dmSoundId : null;
        $ch = $channelSoundId !== null && self::soundExists($channelSoundId) ? $channelSoundId : null;
        Database::query(
            'INSERT INTO user_sound_prefs (user_id, dm_sound_id, channel_sound_id) VALUES (?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET
               dm_sound_id = excluded.dm_sound_id,
               channel_sound_id = excluded.channel_sound_id',
            [$user['id'], $dm, $ch]
        );
    }

    /**
     * The user's per-sender overrides, keyed by target user id.
     * sound_id NULL = muted for that sender.
     *
     * @return array<int, array{sound_id:?int, username:string}>
     */
    public static function overrides(array $user): array
    {
        if (Auth::isGuest($user)) {
            return [];
        }
        $rows = Database::all(
            'SELECT o.target_user_id, o.sound_id, COALESCE(u.username, "") AS username
             FROM user_sound_overrides o
             LEFT JOIN users u ON u.id = o.target_user_id
             WHERE o.user_id = ? ORDER BY u.username COLLATE NOCASE',
            [$user['id']]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['target_user_id']] = [
                'sound_id' => $r['sound_id'] === null ? null : (int) $r['sound_id'],
                'username' => $r['username'],
            ];
        }
        return $out;
    }

    /** Add or update an override. NULL sound = mute that sender entirely. */
    public static function setOverride(array $user, int $targetUserId, ?int $soundId): bool|string
    {
        if (Auth::isGuest($user)) {
            return 'Guests cannot configure sound alerts.';
        }
        if ($targetUserId === (int) $user['id']) {
            return 'You cannot set a sound for yourself.';
        }
        if (!Database::scalar('SELECT 1 FROM users WHERE id = ?', [$targetUserId])) {
            return 'No such user.';
        }
        $sound = $soundId !== null && self::soundExists($soundId) ? $soundId : null;
        Database::query(
            'INSERT INTO user_sound_overrides (user_id, target_user_id, sound_id) VALUES (?, ?, ?)
             ON CONFLICT(user_id, target_user_id) DO UPDATE SET sound_id = excluded.sound_id',
            [$user['id'], $targetUserId, $sound]
        );
        return true;
    }

    /** Drop an override so that sender falls back to the default sounds. */
    public static function removeOverride(array $user, int $targetUserId): void
    {
        if (Auth::isGuest($user)) {
            return;
        }
        Database::query(
            'DELETE FROM user_sound_overrides WHERE user_id = ? AND target_user_id = ?',
            [$user['id'], $targetUserId]
        );
    }

    /**
     * Resolve the sound for a message from $senderUserId (only meaningful for
     * registered senders/actors). Returns:
     *   ['override' => true, 'sound_id' => ?int]  — a custom sound, or NULL = muted
     *   ['override' => false]                     — follow the context default
     */
    public static function overrideFor(array $user, ?int $senderUserId): array
    {
        if ($senderUserId === null || Auth::isGuest($user)) {
            return ['override' => false];
        }
        $soundId = Database::scalar(
            'SELECT sound_id FROM user_sound_overrides WHERE user_id = ? AND target_user_id = ?',
            [$user['id'], $senderUserId]
        );
        if ($soundId === false) {
            return ['override' => false];
        }
        return ['override' => true, 'sound_id' => $soundId === null ? null : (int) $soundId];
    }

    /**
     * Everything the chat client needs in one map (embedded into the page):
     * the enabled sounds, the user's DM/channel choices, and their overrides.
     *
     * @return array{sounds:array, dm_sound_id:?int, channel_sound_id:?int, overrides:array}
     */
    public static function soundsForClient(array $user): array
    {
        $sounds = [];
        foreach (self::listEnabled() as $s) {
            $sounds[(int) $s['id']] = ['name' => $s['name'], 'url' => $s['file']];
        }
        $overrides = [];
        foreach (self::overrides($user) as $uid => $o) {
            $overrides[$uid] = $o['sound_id'];
        }
        // A per-user mute silences that person across every surface, so their
        // sound override is forced off regardless of any custom sound choice.
        foreach (PushService::mutedList($user) as $m) {
            $overrides[(int) $m['muted_user_id']] = null;
        }
        $prefs = self::prefsFor($user);
        return [
            'sounds' => $sounds,
            'dm_sound_id' => $prefs['dm_sound_id'],
            'channel_sound_id' => $prefs['channel_sound_id'],
            'overrides' => $overrides,
        ];
    }

    /** Admin: store an uploaded audio file as a new sound alert. */
    public static function add(array $file, string $name): array
    {
        $name = mb_substr(trim($name), 0, 60);
        if ($name === '') {
            return ['ok' => false, 'error' => 'A sound name is required.'];
        }
        $v = self::validateAudio($file);
        if (!$v['ok']) {
            return $v;
        }
        $fname = bin2hex(random_bytes(8)) . '.' . $v['ext'];
        $target = self::dir() . '/' . $fname;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => 'Could not store the uploaded sound.'];
        }
        @chmod($target, 0644);
        Database::query(
            'INSERT INTO sound_alerts (name, file, created_by) VALUES (?, ?, ?)',
            [$name, self::SOUND_DIR . '/' . $fname, Auth::id()]
        );
        return ['ok' => true, 'id' => (int) Database::lastId()];
    }

    /** Admin: enable/disable a sound (disabled sounds leave the user pickers). */
    public static function toggle(int $id): bool
    {
        if (!self::soundExists($id)) {
            return false;
        }
        Database::query('UPDATE sound_alerts SET enabled = 1 - enabled WHERE id = ?', [$id]);
        return true;
    }

    /**
     * Admin: delete a sound. Prefs referencing it fall back to muted
     * (ON DELETE SET NULL); overrides referencing it are removed so the sender
     * reverts to the default rather than being muted.
     */
    public static function remove(int $id): bool
    {
        $row = Database::row('SELECT * FROM sound_alerts WHERE id = ?', [$id]);
        if (!$row) {
            return false;
        }
        UploadService::remove((string) $row['file']);
        Database::query('DELETE FROM sound_alerts WHERE id = ?', [$id]);
        return true;
    }

    /** Validate an uploaded audio file. @return array{ok:true,ext:string}|array{ok:false,error:string} */
    public static function validateAudio(array $file): array
    {
        if (!isset($file['tmp_name'], $file['name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Choose an audio file first.'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed (error ' . (int) $file['error'] . ').'];
        }
        if ((int) $file['size'] > self::AUDIO_MAX_BYTES) {
            return ['ok' => false, 'error' => 'Audio file is too large (max 2 MB).'];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['ok' => false, 'error' => 'Allowed audio formats: MP3, WAV, OGG, WebM, M4A.'];
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_file($fi, (string) $file['tmp_name']);
            finfo_close($fi);
        }
        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME, true)) {
            return ['ok' => false, 'error' => 'That file is not a supported audio file (' . $mime . ').'];
        }
        return ['ok' => true, 'ext' => $ext];
    }

    /**
     * Generate a tiny PCM WAV (16-bit mono) for the built-in sounds — no
     * ffmpeg or external dependency required. $name picks the note shape.
     */
    public static function writeDefaultWav(string $path, string $name, float $freq, float $dur): bool
    {
        $rate = 22050;
        $tones = [];
        if ($name === 'Chime') {
            // Two ascending notes with a soft overlap (a rising "chime").
            $tones = [
                ['freq' => $freq, 'start' => 0.0, 'end' => $dur * 0.6],
                ['freq' => $freq * 1.335, 'start' => $dur * 0.35, 'end' => $dur],
            ];
        } else {
            $tones = [['freq' => $freq, 'start' => 0.0, 'end' => $dur]];
        }
        $n = max(1, (int) round($dur * $rate));
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $i / $rate;
            $v = 0.0;
            foreach ($tones as $tn) {
                if ($t < $tn['start'] || $t >= $tn['end']) {
                    continue;
                }
                $local = ($t - $tn['start']) / ($tn['end'] - $tn['start']);
                $env = exp(-3.2 * $local);
                $attack = min(1.0, $local / 0.02);
                $v += sin(2 * M_PI * $tn['freq'] * $t) * $env * $attack;
            }
            $v = max(-1.0, min(1.0, $v * 0.55));
            $samples .= pack('v', ((int) round($v * 32767)) & 0xFFFF);
        }
        $dataSize = strlen($samples);
        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16)
            . 'data' . pack('V', $dataSize);
        return file_put_contents($path, $header . $samples) !== false;
    }
}

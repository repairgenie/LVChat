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

final class UploadService
{
    private const AVATAR_DIR = '/assets/avatars';
    private const UPLOAD_DIR = '/uploads';
    private const TICKET_DIR = '/uploads/tickets';
    private const THEME_DIR = '/assets/themes';
    private const AVATAR_MAX_BYTES = 1048576;   // 1 MB
    private const TICKET_MAX_BYTES = 26214400;  // 25 MB

    /** Absolute path to a public runtime dir (created if missing). */
    public static function dir(string $kind): string
    {
        $rel = match ($kind) {
            'avatar' => self::AVATAR_DIR,
            'ticket' => self::TICKET_DIR,
            'theme' => self::THEME_DIR,
            default => self::UPLOAD_DIR,
        };
        $abs = ROOT . '/public' . $rel;
        if (!is_dir($abs)) {
            @mkdir($abs, 0775, true);
        }
        return $abs;
    }

    public static function isImageUpload(array $file): bool
    {
        return isset($file['tmp_name'], $file['name'])
            && is_uploaded_file((string) $file['tmp_name']);
    }

    /** Check whether a MIME type represents an image. */
    public static function isImageMime(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /** Check whether a file extension is an image type. */
    public static function isImageExt(string $ext): bool
    {
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    /**
     * Compute the effective max upload size in bytes for a chat upload.
     *
     * Resolution order:
     *   1. Admin setting (hard ceiling): config upload_max_mb → bytes
     *   2. SaaS plan limit (if SaaS enabled and user has a plan)
     *   3. Fallback: 5 MB
     *
     * The admin setting always wins — SaaS plans cannot exceed it.
     */
    public static function chatMaxBytes(?array $actor = null): int
    {
        $adminMax = ((int) config_get('upload_max_mb', 5)) * 1024 * 1024;
        if ($adminMax <= 0) {
            $adminMax = 5 * 1024 * 1024;
        }
        $max = $adminMax;
        if ($actor !== null && class_exists('SaaSService')) {
            $planCap = SaaSService::limit($actor, 'upload_max_bytes');
            if ($planCap !== null && $planCap > 0) {
                $max = min($adminMax, $planCap);
            }
        }
        return $max;
    }

    /**
     * Validate an uploaded file for chat / general use.
     *
     * Accepts any file type. For images, runs pixel-bomb guard via getimagesize.
     * Returns ['ok' => true, 'ext' => ..., 'mime' => ...] or ['ok' => false, 'error' => ...].
     */
    public static function validate(array $file, string $kind, ?array $actor = null): array
    {
        // Determine max bytes per kind.
        $max = match ($kind) {
            'avatar' => self::AVATAR_MAX_BYTES,
            'ticket' => self::TICKET_MAX_BYTES,
            default => self::chatMaxBytes($actor),
        };

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed (error ' . (int) $file['error'] . ').'];
        }
        if ((int) $file['size'] > $max) {
            $label = $max >= 1048576 ? round($max / 1048576, 1) . ' MB' : round($max / 1024) . ' KB';
            return ['ok' => false, 'error' => 'File is too large (max ' . $label . ').'];
        }

        // MIME detection via finfo (works for all file types).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        // Map common MIME types to canonical extensions when the detected ext
        // is missing or doesn't match the MIME (e.g. .txt files detected as text/*).
        if ($ext === '' || $ext === 'bin') {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/svg+xml' => 'svg',
                'application/pdf' => 'pdf',
                'application/zip' => 'zip',
                'application/x-rar-compressed', 'application/x-rar' => 'rar',
                'application/x-7z-compressed' => '7z',
                'audio/mpeg' => 'mp3',
                'audio/wav' => 'wav',
                'audio/ogg', 'application/ogg' => 'ogg',
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                default => 'bin',
            };
        }

        // Image-specific validation: pixel-bomb guard.
        if (self::isImageMime($mime)) {
            $info = @getimagesize((string) $file['tmp_name']);
            if ($info !== false) {
                $w = (int) ($info[0] ?? 0);
                $h = (int) ($info[1] ?? 0);
                if ($w > 16384 || $h > 16384 || ($w > 0 && $h > 0 && $w * $h > 40_000_000)) {
                    return ['ok' => false, 'error' => 'Image dimensions are too large (max 16384×16384).'];
                }
                // Use the more precise MIME from getimagesize for images.
                $detectedMime = (string) ($info['mime'] ?? $mime);
                if ($detectedMime !== '') {
                    $mime = $detectedMime;
                }
            }
        }

        $originalName = (string) $file['name'];
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'original_name' => $originalName];
    }

    /**
     * Validate an uploaded file for support tickets.
     *
     * Delegates to validate() with kind 'ticket' which applies the 25 MB cap.
     * Returns the same shape plus 'original_name'.
     */
    public static function validateTicketFile(array $file): array
    {
        return self::validate($file, 'ticket');
    }

    public static function storeTicketFile(array $file): array
    {
        $v = self::validateTicketFile($file);
        if (!$v['ok']) {
            return $v;
        }
        $abs = self::dir('ticket');
        $name = bin2hex(random_bytes(16)) . '.' . $v['ext'];
        $target = $abs . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => 'Could not store the uploaded file.'];
        }
        @chmod($target, 0644);
        return ['ok' => true, 'url' => self::TICKET_DIR . '/' . $name, 'name' => $v['original_name'], 'ext' => $v['ext']];
    }

    /**
     * Store a validated upload with a random name.
     *
     * For kind 'upload' (chat files), also inserts a row into uploaded_files
     * with an expiry date so the maintenance cron can auto-delete old files.
     * Returns ['ok' => true, 'url' => ..., 'ext' => ..., 'mime' => ...] or error.
     */
    public static function store(array $file, string $kind, ?array $actor = null): array
    {
        $v = self::validate($file, $kind, $actor);
        if (!$v['ok']) {
            return $v;
        }
        $abs = self::dir($kind);
        $name = bin2hex(random_bytes(16)) . '.' . $v['ext'];
        $target = $abs . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => 'Could not store the uploaded file.'];
        }
        @chmod($target, 0644);
        $rel = match ($kind) {
            'avatar' => self::AVATAR_DIR,
            'ticket' => self::TICKET_DIR,
            'theme' => self::THEME_DIR,
            default => self::UPLOAD_DIR,
        };
        $url = $rel . '/' . $name;
        $result = ['ok' => true, 'url' => $url, 'ext' => $v['ext'], 'mime' => $v['mime'] ?? ''];

        // Track chat uploads for expiry (not avatars/themes which are managed separately).
        if ($kind === 'upload') {
            self::trackUpload($url, $v['original_name'] ?? '', $v['mime'] ?? '', (int) $file['size'], $actor, 'upload');
        }

        return $result;
    }

    /**
     * Insert a row into uploaded_files for expiry tracking.
     *
     * @param array|null $actor  The uploading user (for uploader_id).
     */
    private static function trackUpload(string $url, string $originalName, string $mime, int $size, ?array $actor, string $kind): void
    {
        $expiryDays = (int) config_get('upload_expiry_days', 60);
        $expiresAt = $expiryDays > 0 ? gmdate('Y-m-d H:i:s', time() + $expiryDays * 86400) : null;
        $uploaderId = $actor !== null ? (int) ($actor['id'] ?? 0) : null;
        Database::run(
            'INSERT INTO uploaded_files (url, original_name, mime, size, uploader_id, kind, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$url, $originalName, $mime, $size, $uploaderId, $kind, $expiresAt]
        );
    }

    /**
     * Remove an uploaded_files tracking row by URL.
     */
    public static function untrackUpload(string $url): void
    {
        Database::run('DELETE FROM uploaded_files WHERE url = ?', [$url]);
    }

    /** Re-encode to a downscaled WebP (best effort). Returns new path or false. */
    public static function downscale(string $path, string $ext, int $maxDim = 256): string|false
    {
        $src = self::loadImage($path, $ext);
        if ($src === false) {
            return false;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        // Defense-in-depth: refuse anything that decoded larger than the
        // validated header cap (a crafted file could enlarge on decode).
        if ($w > 16384 || $h > 16384 || ($w > 0 && $h > 0 && $w * $h > 40_000_000)) {
            imagedestroy($src);
            @unlink($path);
            return false;
        }
        if ($w <= $maxDim && $h <= $maxDim) {
            imagedestroy($src);
            return $path;
        }
        $scale = min($maxDim / $w, $maxDim / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $out = $dir . '/' . $base . '.webp';
        if (function_exists('imagewebp') && imagewebp($dst, $out)) {
            @unlink($path);
            imagedestroy($dst);
            return $out;
        }
        imagedestroy($dst);
        return $path;
    }

    /** Delete a stored file given its public URL path (safe path check). */
    public static function remove(?string $url): void
    {
        if (!$url) {
            return;
        }
        $abs = ROOT . '/public' . $url;
        $real = realpath($abs);
        if ($real === false || !str_starts_with($real, realpath(ROOT . '/public'))) {
            return;
        }
        @unlink($real);
    }

    /**
     * Delete a file from disk and remove its tracking row.
     */
    public static function removeTracked(?string $url): void
    {
        if (!$url) {
            return;
        }
        self::remove($url);
        self::untrackUpload($url);
    }

    private static function loadImage(string $path, string $ext): \GdImage|false
    {
        return match ($ext) {
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };
    }
}

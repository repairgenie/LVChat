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
    private const IMAGE_MAX_BYTES = 5242880;    // 5 MB
    private const TICKET_MAX_BYTES = 26214400;  // 25 MB
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const TICKET_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'pdf', 'docx', 'odt'];
    private const TICKET_ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'text/plain',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
    ];

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

    /** Validate the uploaded image, return ['ok' => true, 'ext' => 'webp'] or an error string. */
    public static function validate(array $file, string $kind, ?array $actor = null): array
    {
        $max = $kind === 'avatar' ? self::AVATAR_MAX_BYTES : self::IMAGE_MAX_BYTES;
        if ($kind === 'upload' && $actor !== null && class_exists('SaaSService')) {
            $planCap = SaaSService::limit($actor, 'upload_max_bytes');
            if ($planCap !== null && $planCap > 0) {
                $max = $planCap;
            }
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed (error ' . (int) $file['error'] . ').'];
        }
        if ((int) $file['size'] > $max) {
            return ['ok' => false, 'error' => 'Image is too large (max ' . round($max / 1024) . ' KB).'];
        }
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'That file is not a valid image.'];
        }
        // Pixel-bomb guard: a tiny file may declare enormous dimensions
        // (getimagesize only reads the header), which would force GD to allocate
        // ~bytes-per-pixel * width * height during decode — a memory-exhaustion
        // DoS vector. Reject implausible dimensions up front.
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w > 16384 || $h > 16384 || ($w > 0 && $h > 0 && $w * $h > 40_000_000)) {
            return ['ok' => false, 'error' => 'Image dimensions are too large (max 16384×16384).'];
        }
        $mime = (string) ($info['mime'] ?? '');
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($mimeToExt[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'];
        }
        return ['ok' => true, 'ext' => $mimeToExt[$mime], 'mime' => $mime];
    }

    public static function validateTicketFile(array $file): array
    {
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed (error ' . (int) $file['error'] . ').'];
        }
        if ((int) $file['size'] > self::TICKET_MAX_BYTES) {
            return ['ok' => false, 'error' => 'File is too large (max 25 MB).'];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::TICKET_ALLOWED_EXT, true)) {
            return ['ok' => false, 'error' => 'Allowed formats: JPG, PNG, WebP, GIF, TXT, PDF, DOCX, ODT.'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);
        if (!in_array($mime, self::TICKET_ALLOWED_MIME, true)) {
            if ($ext === 'txt' && str_starts_with($mime, 'text/')) {
                // accept any text/* for .txt
            } else {
                return ['ok' => false, 'error' => 'File type not allowed.'];
            }
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'original_name' => (string) $file['name']];
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

    public static function isImageExt(string $ext): bool
    {
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    /** Store a validated upload with a random name. Returns the public URL path. */
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
            return ['ok' => false, 'error' => 'Could not store the uploaded image.'];
        }
        @chmod($target, 0644);
        $rel = match ($kind) {
            'avatar' => self::AVATAR_DIR,
            'ticket' => self::TICKET_DIR,
            'theme' => self::THEME_DIR,
            default => self::UPLOAD_DIR,
        };
        return ['ok' => true, 'url' => $rel . '/' . $name, 'ext' => $v['ext']];
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

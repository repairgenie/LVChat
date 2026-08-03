<?php

declare(strict_types=1);

final class UploadService
{
    private const AVATAR_DIR = '/assets/avatars';
    private const UPLOAD_DIR = '/uploads';
    private const AVATAR_MAX_BYTES = 1048576;   // 1 MB
    private const IMAGE_MAX_BYTES = 5242880;    // 5 MB
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Absolute path to a public runtime dir (created if missing). */
    public static function dir(string $kind): string
    {
        $rel = $kind === 'avatar' ? self::AVATAR_DIR : self::UPLOAD_DIR;
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
    public static function validate(array $file, string $kind): array
    {
        $max = $kind === 'avatar' ? self::AVATAR_MAX_BYTES : self::IMAGE_MAX_BYTES;
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
        $mime = (string) ($info['mime'] ?? '');
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($mimeToExt[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'];
        }
        return ['ok' => true, 'ext' => $mimeToExt[$mime], 'mime' => $mime];
    }

    /** Store a validated upload with a random name. Returns the public URL path. */
    public static function store(array $file, string $kind): array
    {
        $v = self::validate($file, $kind);
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
        $rel = $kind === 'avatar' ? self::AVATAR_DIR : self::UPLOAD_DIR;
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

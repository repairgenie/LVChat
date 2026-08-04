<?php

declare(strict_types=1);

/**
 * Regenerate the PWA icons (public/assets/pwa/*.png).
 *
 * The icons are drawn with GD — no fonts, no external assets: a blurple
 * rounded square (matching the app's fallback "#" mark) with a white "#"
 * glyph built from four rectangles. Run locally when you want to tweak:
 *
 *   php bin/make-icons.php
 */

$blurple = [88, 101, 242];
$white = [255, 255, 255];

function make_icon(int $size, array $blurple, array $white, bool $rounded = true): void
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    $bg = imagecolorallocate($img, $blurple[0], $blurple[1], $blurple[2]);
    $fg = imagecolorallocate($img, $white[0], $white[1], $white[2]);
    imagefill($img, 0, 0, $transparent);

    $radius = $rounded ? (int) round($size * 0.22) : 0;

    // Fill the (optionally rounded) square, pixel by pixel, so transparency
    // is only outside the rounded corners.
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($radius === 0) {
                imagesetpixel($img, $x, $y, $bg);
                continue;
            }
            $nx = max($radius - $x, $x - ($size - 1 - $radius), 0);
            $ny = max($radius - $y, $y - ($size - 1 - $radius), 0);
            if ($nx * $nx + $ny * $ny <= $radius * $radius) {
                imagesetpixel($img, $x, $y, $bg);
            }
        }
    }

    // "#" glyph built from four rectangles, centered and ~55% of the canvas.
    $bar = (int) round($size * 0.072);
    $span = (int) round($size * 0.30);
    $gap = (int) round($size * 0.048);
    $top = (int) round($size * 0.27);
    $bottom = (int) round($size * 0.58);
    $left = (int) round(($size - $span * 2 - $gap) / 2);
    $right = $left + $span + $gap;

    // Vertical bars.
    imagefilledrectangle($img, $left, $top, $left + $bar, $bottom + $bar, $fg);
    imagefilledrectangle($img, $right, $top, $right + $bar, $bottom + $bar, $fg);
    // Horizontal bars.
    imagefilledrectangle($img, $left, $top, $right + $bar, $top + $bar, $fg);
    imagefilledrectangle($img, $left, $bottom, $right + $bar, $bottom + $bar, $fg);

    imagepng($img);
    imagedestroy($img);
}

$out = __DIR__ . '/../public/assets/pwa';
$sizes = [
    'icon-192.png' => 192,
    'icon-512.png' => 512,
    'apple-touch-icon.png' => 180,
];

foreach ($sizes as $file => $size) {
    $rounded = $file !== 'apple-touch-icon.png'; // Apple masks the icon itself.
    ob_start();
    make_icon($size, $blurple, $white, $rounded);
    file_put_contents($out . '/' . $file, ob_get_clean());
    echo "wrote public/assets/pwa/$file ({$size}x{$size})\n";
}

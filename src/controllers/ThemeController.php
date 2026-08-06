<?php

declare(strict_types=1);

/**
 * User-facing theme settings API.
 *
 * - GET /api/theme/css  — renders the CSS for an ad-hoc theme (live preview).
 * - POST /api/theme     — saves the viewer's personal theme.
 * - POST /api/theme/bg  — uploads the viewer's chat background image.
 * - POST /api/theme/bg/remove — clears it.
 *
 * Every endpoint honours the admin kill-switch: when theme customization is
 * disabled, personal themes can't be saved or uploaded.
 */
final class ThemeController
{
    private static function customizationAllowed(): void
    {
        if (!ThemeService::customizationEnabled()) {
            json_out(['error' => 'Theme customization has been disabled by the administrator.'], 403);
        }
    }

    /** GET /api/theme/css — text/css for live theme previews (no session needed). */
    public static function css(): void
    {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: no-store');
        echo ThemeService::cssFor($_GET);
        exit;
    }

    /** POST /api/theme — save the viewer's personal theme. */
    public static function save(): void
    {
        $user = Auth::require();
        Csrf::verify();
        self::customizationAllowed();

        // Keep the previously uploaded background image unless overridden.
        $current = ThemeService::userTheme($user);
        $preset = trim((string) ($_POST['preset'] ?? ''));
        if ($preset === '' && trim((string) ($_POST['chat_bg_image'] ?? '')) === '') {
            // Empty preset + no background = reset to the server theme.
            ThemeService::clearUser($user);
            json_out(['ok' => true]);
        }

        $image = (string) ($_POST['chat_bg_image'] ?? '');
        if (ThemeService::localPath($image) === '' && !empty($current['overrides']['chat_bg_image'])) {
            $image = (string) $current['overrides']['chat_bg_image'];
        }

        ThemeService::saveUser($user, [
            'preset' => (string) ($_POST['preset'] ?? ''),
            'mode' => (string) ($_POST['mode'] ?? ''),
            'overrides' => [
                'accent' => (string) ($_POST['accent'] ?? ''),
                'sidebar' => (string) ($_POST['sidebar'] ?? ''),
                'font' => (string) ($_POST['font'] ?? ''),
                'chat_bg_color' => (string) ($_POST['chat_bg_color'] ?? ''),
                'chat_bg_image' => $image,
                'chat_bg_fit' => (string) ($_POST['chat_bg_fit'] ?? ''),
                'chat_bg_overlay' => (int) ($_POST['chat_bg_overlay'] ?? -1),
            ],
        ]);
        json_out(['ok' => true]);
    }

    /** POST /api/theme/bg — upload the viewer's chat background image. */
    public static function uploadBg(): void
    {
        $user = Auth::require();
        Csrf::verify();
        self::customizationAllowed();

        if (!isset($_FILES['file']) || !UploadService::isImageUpload($_FILES['file'])) {
            json_out(['error' => 'Choose an image file first.'], 400);
        }
        $stored = UploadService::store($_FILES['file'], 'theme');
        if (!$stored['ok']) {
            json_out(['error' => $stored['error']], 400);
        }

        // Keep every other personal-theme field, swap the image in.
        $current = ThemeService::userTheme($user);
        if (!empty($current['overrides']['chat_bg_image'])) {
            UploadService::remove((string) $current['overrides']['chat_bg_image']);
        }
        $current['overrides']['chat_bg_image'] = $stored['url'];
        ThemeService::saveUser($user, $current);
        json_out(['ok' => true, 'url' => $stored['url']]);
    }

    /** POST /api/theme/bg/remove — clear the viewer's chat background image. */
    public static function removeBg(): void
    {
        $user = Auth::require();
        Csrf::verify();
        self::customizationAllowed();

        $current = ThemeService::userTheme($user);
        if (!empty($current['overrides']['chat_bg_image'])) {
            UploadService::remove((string) $current['overrides']['chat_bg_image']);
        }
        $current['overrides']['chat_bg_image'] = '';
        ThemeService::saveUser($user, $current);
        json_out(['ok' => true]);
    }
}

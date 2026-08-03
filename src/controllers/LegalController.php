<?php

declare(strict_types=1);

/** Public Terms of Service / Privacy Policy pages (no auth required). */
final class LegalController
{
    public static function terms(array $params): void
    {
        render_view('legal/page', [
            'title' => 'Terms of Service',
            'heading' => 'Terms of Service',
            'body' => LegalService::get('terms'),
            'user' => Auth::user(),
        ]);
    }

    public static function privacy(array $params): void
    {
        render_view('legal/page', [
            'title' => 'Privacy Policy',
            'heading' => 'Privacy Policy',
            'body' => LegalService::get('privacy'),
            'user' => Auth::user(),
        ]);
    }
}

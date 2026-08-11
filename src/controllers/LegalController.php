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

    public static function apiTerms(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['html' => LegalService::get('terms')]);
    }

    public static function apiPrivacy(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['html' => LegalService::get('privacy')]);
    }
}

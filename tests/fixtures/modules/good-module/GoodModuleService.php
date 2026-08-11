<?php

declare(strict_types=1);

// Fixture module service — proves a module can require and use its own PHP files.
final class GoodModuleService
{
    public static function hello(): string
    {
        return 'good-module says hi';
    }

    public static function assetMarker(): string
    {
        return 'good-module assets loaded';
    }
}

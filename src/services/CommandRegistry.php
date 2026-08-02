<?php

declare(strict_types=1);

final class CommandRegistry
{
    private static array $commands = [];

    public static function register(string $name, array $opts): void
    {
        self::$commands[strtolower($name)] = $opts;
    }

    public static function get(string $name): ?array
    {
        return self::$commands[strtolower($name)] ?? null;
    }

    public static function all(): array
    {
        return self::$commands;
    }

    public static function names(): array
    {
        return array_keys(self::$commands);
    }
}

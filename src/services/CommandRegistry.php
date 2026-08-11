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

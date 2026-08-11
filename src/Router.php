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

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [$method, $path, $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        // Fallback for installs without a rewrite: /index.php/login -> /login.
        if (preg_match('#^/index\.php(/.*)?$#', $path, $m)) {
            $path = $m[1] === '' ? '/' : $m[1];
        } elseif (!empty($_SERVER['PATH_INFO'])) {
            $path = $_SERVER['PATH_INFO'];
        }
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as [$m, $p, $h]) {
            if ($m !== $method) {
                continue;
            }
            $params = $this->match($p, $path);
            if ($params !== null) {
                $h($params);
                return;
            }
        }
        http_response_code(404);
        echo 'Not found';
    }

    private function match(string $pattern, string $path): ?array
    {
        // `{name...}` is a catch-all splat (matches /, . and .. in paths);
        // `{name}` is a single non-slash segment.
        $regex = preg_replace('#\{([A-Za-z_]\w*)\.\.\.\}#', '(?P<$1>.*)', $pattern);
        $regex = preg_replace('#\{([A-Za-z_]\w*)\}#', '(?P<$1>[^/]+)', $regex);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}

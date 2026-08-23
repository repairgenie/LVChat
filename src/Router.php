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
                try {
                    $h($params);
                } catch (\Throwable $e) {
                    $this->handleException($e);
                }
                return;
            }
        }
        http_response_code(404);
        $this->renderError('notfound', 'Not found', 404);
    }

    /**
     * Render a styled error page. Falls back to plain text if the template
     * or layout cannot be loaded (e.g. during early bootstrap failures).
     */
    private function renderError(string $template, string $fallbackTitle, int $code): void
    {
        http_response_code($code);
        $errorPage = ROOT . '/views/errors/' . $template . '.php';
        if (is_file($errorPage)) {
            $title = $fallbackTitle;
            $content = '';
            ob_start();
            require $errorPage;
            $content = ob_get_clean();
            $layout = ROOT . '/views/layout.php';
            if (is_file($layout)) {
                require $layout;
            } else {
                echo $content;
            }
        } else {
            echo h($fallbackTitle);
        }
    }

    /**
     * Catch unhandled exceptions and render a 500 page. Logs the full error
     * server-side; shows only a generic message to the user (no leak of paths,
     * stack traces, or DB details).
     */
    private function handleException(\Throwable $e): void
    {
        error_log('LVChat unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI === 'cli' || getenv('LVC_DEBUG') === '1') {
            http_response_code(500);
            echo "500 Internal Server Error\n\n";
            echo $e->getMessage() . "\n";
            echo $e->getFile() . ':' . $e->getLine() . "\n\n";
            echo $e->getTraceAsString();
            return;
        }
        // JSON endpoints get a JSON error; page requests get the styled 500 page.
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isApi = str_contains($accept, 'application/json')
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($isApi) {
            json_out(['error' => 'Internal server error'], 500);
        }
        $this->renderError('servererror', 'Internal Server Error', 500);
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

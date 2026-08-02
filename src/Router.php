<?php

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
        $regex = preg_replace('#\{([A-Za-z_]\w*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}

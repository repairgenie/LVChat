<?php

declare(strict_types=1);

// Fixture module routes.php — proves modules can register web routes.
return static function (Router $router): void {
    $router->get('/api/good-module/ping', static function (array $params): void {
        json_out(['ok' => true, 'module' => 'good-module']);
    });

    // Proves ModuleLoader::view() renders a module view inside the standard
    // layout (matches the fixture's manifest `admin` nav entry).
    $router->get('/admin/good-module', static function (array $params): void {
        ModuleLoader::view('good-module', 'sample', ['message' => 'from fixture view']);
    });
};

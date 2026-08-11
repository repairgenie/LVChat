<?php

declare(strict_types=1);

// Fixture module init.php — must be side-effect-free at the HTTP level.
// Guarded against re-boots in the smoke suite (a production module runs its
// init.php once per process, so a plain require is fine there).
if (!class_exists('GoodModuleService')) {
    require __DIR__ . '/GoodModuleService.php';
}

CommandRegistry::register('goodmod', [
    'group' => 'Plugins',
    'desc' => 'Test command from good-module.',
    'usage' => '/goodmod',
    'run' => function (array $args, array $user, ?array $channel) {
        return ['replies' => [GoodModuleService::hello()]];
    },
]);

<?php

declare(strict_types=1);

// Fixture paid module init.php. The module is gated on its license: it loads
// (so the loader can record its license status), but its feature only runs when
// ModuleLoader::isLicensed('paid-mod') is true.
CommandRegistry::register('paidcmd', [
    'group' => 'Plugins',
    'desc' => 'Test command from the paid module.',
    'usage' => '/paidcmd',
    'run' => function (array $args, array $user, ?array $channel) {
        if (!ModuleLoader::isLicensed('paid-mod')) {
            return ['replies' => ['This feature requires a paid-mod license key.']];
        }
        return ['replies' => ['paid-mod premium feature active']];
    },
]);

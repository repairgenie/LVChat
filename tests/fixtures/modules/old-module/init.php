<?php

declare(strict_types=1);

// Must never run: old-module fails its `requires` check at boot.
CommandRegistry::register('oldcmd', [
    'group' => 'Plugins',
    'desc' => 'Should never be registered.',
    'usage' => '/oldcmd',
    'run' => function () {
        return ['replies' => ['should not happen']];
    },
]);

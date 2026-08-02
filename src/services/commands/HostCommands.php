<?php

declare(strict_types=1);

// ─── HostServ commands ──────────────────────────────────────────────────────

CommandRegistry::register('vhost', [
    'group' => 'HostServ',
    'desc' => 'Set, activate or deactivate a virtual host (hostname).',
    'usage' => '/vhost <set <host>|on|off|status>',
    'run' => function (array $args, array $user, ?array $channel) {
        $sub = strtolower($args[0] ?? 'status');
        switch ($sub) {
            case 'set':
                $host = $args[1] ?? null;
                if (!$host || !preg_match('/^[A-Za-z0-9.\-]{3,60}$/', $host)) {
                    return ['replies' => ['Usage: /vhost set <host> (3-60 chars, letters/numbers/dot/dash)']];
                }
                Database::query('UPDATE users SET vhost = ? WHERE id = ?', [$host, $user['id']]);
                return ['replies' => ["Virtual host set to $host. Use /vhost on to activate it."]];
            case 'on':
                $cur = (string) $user['vhost'];
                $plain = str_replace('|hide', '', $cur);
                if ($plain === '') {
                    return ['replies' => ['You have no vhost set. Use /vhost set <host> first.']];
                }
                Database::query('UPDATE users SET vhost = ? WHERE id = ?', [str_replace('|hide', '', $cur), $user['id']]);
                return ['replies' => ['Virtual host activated.']];
            case 'off':
                Database::query('UPDATE users SET vhost = NULL WHERE id = ?', [$user['id']]);
                return ['replies' => ['Virtual host deactivated.']];
            case 'status':
                $cur = str_replace('|hide', '', (string) $user['vhost']);
                return ['replies' => ['Your vhost is: ' . ($cur ?: '(none)')]];
            default:
                return ['replies' => ['Usage: /vhost <set <host>|on|off|status>']];
        }
    },
]);

CommandRegistry::register('hs', [
    'group' => 'HostServ',
    'desc' => 'Alias of /vhost.',
    'usage' => '/hs <command> [args]',
    'run' => function (array $args, array $user, ?array $channel) {
        $reg = CommandRegistry::get('vhost');
        return call_user_func($reg['run'], $args, $user, $channel);
    },
]);

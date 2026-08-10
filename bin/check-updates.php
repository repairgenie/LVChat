#!/usr/bin/env php
<?php

declare(strict_types=1);

// check-updates.php — compare the installed apps against the upstream feed.
//
//   php bin/check-updates.php            # summary table (uses cached manifest)
//   php bin/check-updates.php --refresh  # force a manifest re-fetch first
//   php bin/check-updates.php --json     # machine-readable output
//
// Exit code: 0 = all current (or no feed configured), 1 = an update is
// available. Pairs with cron / uptime monitors.

require dirname(__DIR__) . '/src/bootstrap.php';

$refresh = in_array('--refresh', $argv, true);
$json = in_array('--json', $argv, true);

if ($refresh) {
    UpdaterService::fetchManifest(true);
}

$rows = UpdaterService::statusAll();
$updates = array_values(array_filter($rows, fn ($r) => $r['update_available']));

if ($json) {
    json_out([
        'enabled' => UpdaterService::enabled(),
        'updater_url' => UpdaterService::enabled() ? UpdaterService::baseUrl() : '',
        'apps' => $rows,
        'updates_available' => array_map(fn ($r) => $r['app'], $updates),
    ]);
}

$name = ['web' => 'LVChat Web', 'desktop' => 'LVChat Desktop', 'messenger' => 'LVChat Messenger'];

if (!UpdaterService::enabled()) {
    echo "Update feed is disabled (Admin → Settings → Updates). Nothing to check.\n";
    exit(0);
}

echo 'Update feed: ' . UpdaterService::baseUrl() . "\n";
echo str_repeat('-', 52) . "\n";
printf("%-18s %-10s %-10s %s\n", 'App', 'Installed', 'Latest', 'Status');
echo str_repeat('-', 52) . "\n";
foreach ($rows as $app => $r) {
    $state = $r['update_available'] ? 'UPDATE' : ($r['latest'] === '' ? 'no feed' : 'current');
    printf(
        "%-18s %-10s %-10s %s\n",
        $name[$app],
        $r['installed'] !== '' ? 'v' . $r['installed'] : '—',
        $r['latest'] !== '' ? 'v' . $r['latest'] : '—',
        $state
    );
}
echo str_repeat('-', 52) . "\n";

if ($updates === []) {
    echo "All apps are current.\n";
    exit(0);
}
foreach ($updates as $r) {
    echo "  {$name[$r['app']]}: v{$r['installed']} -> v{$r['latest']}\n";
}
exit(1);

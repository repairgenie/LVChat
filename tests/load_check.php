<?php

declare(strict_types=1);

/**
 * Load check: measures /api/poll requests per second on the current server.
 * Uses concurrent curl_multi requests against a real logged-in session.
 *
 * Usage:  php tests/load_check.php [concurrency] [rounds]
 */
$ROOT = dirname(__DIR__);
$WORK = '/tmp/opencode/loadcheck';
@mkdir($WORK, 0775, true);
$DB = "$WORK/chat.db";
foreach ([$DB, "$DB-wal", "$DB-shm"] as $f) {
    @unlink($f);
}
$PORT = 8130;
$BASE = "http://127.0.0.1:$PORT";

exec("CHAT_DB=$DB nohup php -d display_errors=0 -S 127.0.0.1:$PORT -t " . escapeshellarg("$ROOT/public") . " > $WORK/server.log 2>&1 & echo \$! > $WORK/pid");
sleep(1);
$pid = (int) trim((string) @file_get_contents("$WORK/pid"));

// Register a user and join #general so the poll has a channel.
$jar = "$WORK/cookies.txt";
$ch = curl_init($BASE . '/register');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$html = (string) curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
$t = $m[1] ?? '';
$ch = curl_init($BASE . '/register');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf' => $t, 'username' => 'loaduser', 'email' => 'load@x.com', 'password' => 'password123']), CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
curl_exec($ch);
curl_close($ch);
$ch = curl_init($BASE . '/c/general');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
curl_exec($ch);
curl_close($ch);

$pollUrl = $BASE . '/api/poll?channel=general&since=0';
$concurrency = max(1, (int) ($argv[1] ?? 10));
$rounds = max(1, (int) ($argv[2] ?? 10));

// Optional: set presence_throttle to simulate the old write-every-poll behavior
// (throttle=1) vs the optimized mostly-read behavior (default 30).
$throttle = (int) ($argv[3] ?? 0);
if ($throttle > 0) {
    $pdo = new PDO('sqlite:' . $DB);
    $pdo->exec("INSERT OR REPLACE INTO server_config (key, value) VALUES ('presence_throttle', '$throttle')");
    $pdo = null;
}

// Warm up once (session + DB page cache).
$ch = curl_init($pollUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar]);
curl_exec($ch);
curl_close($ch);

$total = 0;
$start = microtime(true);
for ($r = 0; $r < $rounds; $r++) {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $h = curl_init($pollUrl);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 10]);
        curl_multi_add_handle($mh, $h);
        $handles[] = $h;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    foreach ($handles as $h) {
        curl_multi_remove_handle($mh, $h);
    }
    curl_multi_close($mh);
    $total += $concurrency;
}
$elapsed = microtime(true) - $start;

printf("Polls: %d in %.2fs  →  %.1f req/s  (≈ %.1f ms/request)  [concurrency %d, rounds %d]\n", $total, $elapsed, $total / $elapsed, $elapsed / $total * 1000, $concurrency, $rounds);

@exec("kill $pid 2>/dev/null");

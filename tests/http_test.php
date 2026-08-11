<?php

declare(strict_types=1);

/**
 * HTTP end-to-end test. Spins up the built-in server against a scratch DB and
 * drives every route / API endpoint with real requests (cookies, CSRF, redirects).
 * Usage: php tests/http_test.php
 */

$PORT = 8098;
$DB = '/tmp/opencode/httptest.db';
$BASE = "http://127.0.0.1:$PORT";

foreach ([$DB, $DB . '-wal', $DB . '-shm'] as $f) {
    if (file_exists($f)) unlink($f);
}

$server = proc_open(
    ['php', '-S', "127.0.0.1:$PORT", '-t', __DIR__ . '/../public'],
    [0 => ['pipe', 'r'], 1 => ['file', '/tmp/opencode/http-test-server.log', 'w'], 2 => ['file', '/tmp/opencode/http-test-server.log', 'a']],
    $pipes,
    dirname(__DIR__),
    array_merge($_ENV, ['CHAT_DB' => $DB, 'LVC_EMBED_ALLOW_LOCAL' => '1'])
);
sleep(1);

// Mock web server for the /api/embed proxy tests (framing headers, redirects).
$embedDir = '/tmp/opencode/embedtest';
$embedServer = proc_open(
    ['php', '-S', '127.0.0.1:8097', '-t', $embedDir, $embedDir . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/tmp/opencode/embed-test-server.log', 'w'], 2 => ['file', '/tmp/opencode/embed-test-server.log', 'a']],
    $embedPipes,
    $embedDir
);
sleep(1);

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['pass']++;
        echo "  ok  $label\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  $label  $detail\n";
    }
}

/** @return array{0:int,1:array,2:string} [status, headers, body] */
function req(string $method, string $path, array $data = [], ?string $cookieFile = null, array $headers = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
    ];
    if ($cookieFile) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if (strtoupper($method) === 'POST') {
        // The real JS client always sends ajax=1 (JSON responses). Pass 'ajax' => '0'
        // to exercise the native no-JS form fallback (redirect responses).
        if (!array_key_exists('ajax', $data)) {
            $data['ajax'] = '1';
        }
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    if ($headers) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $head = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: 0);
    $body = substr($raw, strpos($raw, "\r\n\r\n") !== false ? strpos($raw, "\r\n\r\n") + 4 : 0);
    $headers = [];
    foreach (explode("\r\n", $head) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $headers, $body];
}

function csrf(string $html): string
{
    if (preg_match('/data-csrf="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/** Multipart POST for /api/upload (a real image file). @return array{0:int,1:string} */
function uploadReq(string $path, string $jar, array $fields, array $file): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $post = $fields;
    $post['file'] = new CURLFile($file['tmp'], $file['type'], $file['name']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = substr($raw, strpos($raw, "\r\n\r\n") !== false ? strpos($raw, "\r\n\r\n") + 4 : 0);
    return [$status, $body];
}

function jsonDecode(string $body): array
{
    $j = json_decode($body, true);
    return is_array($j) ? $j : ['_raw' => $body];
}

/** Compute a live TOTP code for a base32 secret (mirrors the server's algorithm). */
function mfaCode(string $secretB32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $map = array_flip(str_split($alphabet));
    $bin = '';
    $acc = 0;
    $bits = 0;
    foreach (str_split(strtoupper($secretB32)) as $ch) {
        $acc = ($acc << 5) | $map[$ch];
        $bits += 5;
        if ($bits >= 8) {
            $bin .= chr(($acc >> ($bits - 8)) & 0xFF);
            $bits -= 8;
        }
    }
    $hash = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30)), $bin, true);
    $off = ord($hash[19]) & 0x0F;
    $v = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Direct read access to the scratch DB for id lookups + row assertions. */
function dbq(string $sql, array $p = []): array
{
    global $DB;
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . $DB);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// ── Auth & redirects ────────────────────────────────────────────────────────
echo "== auth ==\n";
[$s, $h, $b] = req('GET', '/');
check('GET / redirects to /login', $s === 302 && ($h['location'] ?? '') === '/login', "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/login');
check('GET /login 200', $s === 200);
[$s, , $b] = req('GET', '/register');
check('GET /register 200', $s === 200);

// Honeypot: a filled trap field must be silently dropped (no account, no flash).
$cjBot = '/tmp/opencode/httptest-bot.txt';
$tBot = csrf(req('GET', '/register', [], $cjBot)[2]);
[$s, $h] = req('POST', '/register', ['csrf' => $tBot, 'website' => 'http://spam.example', 'username' => 'botty', 'email' => 'botty@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjBot);
check('honeypot registration silently redirected', $s === 302 && str_contains($h['location'] ?? '', '/register'), "$s " . ($h['location'] ?? ''));
$botty = dbq('SELECT COUNT(*) AS n FROM users WHERE username = ?', ['botty']);
check('honeypot registration created no user', (int) $botty[0]['n'] === 0, json_encode($botty));

$cjA = '/tmp/opencode/httptest-a.txt';
$page = req('GET', '/register', [], $cjA)[2];
$t = csrf($page);
[$s, $h, $b] = req('POST', '/register', ['csrf' => $t, 'username' => 'alice', 'email' => 'alice@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjA);
check('register alice 302', $s === 302, (string) $s);
check('first user redirected to next', ($h['location'] ?? '') === '/');
[$s, , $appBody] = req('GET', '/app', [], $cjA);
check('alice can open /app', $s === 200, (string) $s);
check('chat has desktop download button', str_contains($appBody, 'id="download-open-btn"'), '');
check('chat has desktop download modal', str_contains($appBody, 'id="download-modal"'), '');
check('chat download modal explains Desktop vs Messenger', str_contains($appBody, 'LVChat Messenger') && str_contains($appBody, 'streamlined'), '');

// share link: logged-in user auto-joins via /c/slug
[$s, $h] = req('GET', '/c/general', [], $cjA);
check('/c/general redirects to chat', $s === 302 && str_contains($h['location'] ?? '', '/app?channel=general'), "$s " . ($h['location'] ?? ''));

// logged-out user sees login redirect with next
[$s, $h] = req('GET', '/c/general');
check('logged-out share link -> /login?next=', $s === 302 && str_contains($h['location'] ?? '', '/login?next='), "$s " . ($h['location'] ?? ''));

// CSRF enforcement
[$s, , $b] = req('POST', '/api/send', ['channel' => 'general', 'content' => 'x'], $cjA);
check('POST without CSRF rejected (419)', $s === 419, (string) $s);

// ── TOTP / MFA ───────────────────────────────────────────────────────────────
echo "== totp mfa ==\n";
$t = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/mfa/begin', ['csrf' => $t], $cjA);
$j = jsonDecode($b);
check('mfa begin returns secret + uri', $s === 200 && !empty($j['secret']) && str_contains($j['uri'] ?? '', 'otpauth://totp/'), $b);
$mfaBare = str_replace(' ', '', (string) ($j['secret'] ?? ''));
[$s, , $b] = req('POST', '/api/mfa/enable', ['csrf' => $t, 'code' => '000000'], $cjA);
check('mfa enable rejects bad code', $s === 403, "$s $b");
[$s, , $b] = req('POST', '/api/mfa/enable', ['csrf' => $t, 'code' => mfaCode($mfaBare)], $cjA);
check('mfa enable with valid code', $s === 200 && jsonDecode($b)['ok'] === true, $b);
check('totp stored in db', !empty(dbq('SELECT totp_secret FROM users WHERE username = "alice"')[0]['totp_secret'] ?? null));
[$s] = req('POST', '/api/mfa/enable', ['csrf' => $t, 'code' => mfaCode($mfaBare)], $cjA);
check('second enable rejected', $s === 400, (string) $s);
[$s, , $b] = req('POST', '/api/mfa/disable', ['csrf' => $t, 'password' => 'wrong'], $cjA);
check('mfa disable rejects wrong password', $s === 403, "$s $b");
[$s, , $b] = req('POST', '/api/mfa/disable', ['csrf' => $t, 'password' => 'password123'], $cjA);
check('mfa disable works for self', $s === 200 && jsonDecode($b)['ok'] === true, $b);

// Re-enable, then drive the login challenge flow from a fresh cookie jar.
[$s, , $b] = req('POST', '/api/mfa/begin', ['csrf' => $t], $cjA);
$mfaBare = str_replace(' ', '', (string) (jsonDecode($b)['secret'] ?? ''));
req('POST', '/api/mfa/enable', ['csrf' => $t, 'code' => mfaCode($mfaBare)], $cjA);

$cjL = '/tmp/opencode/httptest-mfa.txt';
$page = req('GET', '/login', [], $cjL)[2];
[$s, $h] = req('POST', '/login', ['csrf' => csrf($page), 'username' => 'alice', 'password' => 'password123', 'next' => '/app'], $cjL);
check('login with mfa redirects to challenge', $s === 302 && str_contains($h['location'] ?? '', '/login/mfa'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/login/mfa', [], $cjL);
check('challenge page renders', $s === 200 && stripos($b, 'authentication code') !== false, (string) $s);
[$s, $h] = req('POST', '/login/mfa', ['csrf' => csrf($b), 'code' => '000000'], $cjL);
check('wrong mfa code bounces', $s === 302 && str_contains($h['location'] ?? '', '/login/mfa'), "$s " . ($h['location'] ?? ''));
[$s, $h] = req('POST', '/login/mfa', ['csrf' => csrf(req('GET', '/login/mfa', [], $cjL)[2]), 'code' => mfaCode($mfaBare)], $cjL);
check('correct mfa code logs in', $s === 302 && str_contains($h['location'] ?? '', '/app'), "$s " . ($h['location'] ?? ''));
[$s] = req('GET', '/app', [], $cjL);
check('mfa user can open /app', $s === 200, (string) $s);

// Forced enrollment: require MFA for admins, wipe alice's enrollment, and check
// that her next password login lands on the setup page and completes.
dbq("INSERT OR REPLACE INTO server_config (key, value) VALUES ('mfa_require_admin', '1')");
dbq("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL WHERE username = 'alice'");
$cjF = '/tmp/opencode/httptest-mfa-f.txt';
[$s, $h] = req('POST', '/login', ['csrf' => csrf(req('GET', '/login', [], $cjF)[2]), 'username' => 'alice', 'password' => 'password123', 'next' => '/app'], $cjF);
check('required-class login goes to setup', $s === 302 && str_contains($h['location'] ?? '', '/login/mfa/setup'), "$s " . ($h['location'] ?? ''));
$page = req('GET', '/login/mfa/setup', [], $cjF)[2];
check('setup page shows qr uri + secret', stripos($page, 'otpauth://totp/') !== false, '');
$forcedSecret = '';
if (preg_match('/qr\.addData\((".*?")\)/', $page, $m)) {
    $uri = json_decode((string) $m[1]);
    parse_str((string) parse_url((string) $uri, PHP_URL_QUERY), $q);
    $forcedSecret = (string) ($q['secret'] ?? '');
}
check('setup secret extracted from page', preg_match('/^[A-Z2-7]{32}$/', $forcedSecret) === 1, $forcedSecret);
[$s, $h] = req('POST', '/login/mfa/setup', ['csrf' => csrf($page), 'code' => mfaCode($forcedSecret)], $cjF);
check('setup verify completes login', $s === 302 && str_contains($h['location'] ?? '', '/app'), "$s " . ($h['location'] ?? ''));
check('totp enabled after forced setup', !empty(dbq('SELECT totp_enabled_at FROM users WHERE username = "alice"')[0]['totp_enabled_at'] ?? null));
[$s, , $b] = req('POST', '/api/mfa/disable', ['csrf' => csrf(req('GET', '/app', [], $cjF)[2]), 'password' => 'password123'], $cjF);
check('required-class cannot self-disable', $s === 403, "$s $b");
dbq("UPDATE server_config SET value = '0' WHERE key = 'mfa_require_admin'");
dbq("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL WHERE username = 'alice'");
// ── Channel + messaging API ──────────────────────────────────────────────────
echo "== channel + messaging ==\n";
$t = csrf(req('GET', '/app', [], $cjA)[2]);

// Realtime transport report: browsers tell the server which transport won.
[$s, , $b] = req('POST', '/api/rt/report', ['transport' => 'ws'], $cjA, ['X-CSRF: ' . $t]);
check('rt report ws ok', $s === 200 && jsonDecode($b)['ok'] === true, $b);
check('rt report persisted', (dbq("SELECT transport FROM rt_transports WHERE actor_id = (SELECT id FROM users WHERE username = 'alice')")[0]['transport'] ?? '') === 'ws');
[$s, , $b] = req('POST', '/api/rt/report', ['transport' => 'bogus'], $cjA, ['X-CSRF: ' . $t]);
check('rt report rejects bad transport', $s === 400, (string) $s);
[$s] = req('POST', '/api/rt/report', ['transport' => 'poll'], null);
check('rt report requires auth', $s === 401, (string) $s);

[$s, , $b] = req('POST', '/api/channels', ['csrf' => $t, 'name' => '#gaming'], $cjA);
check('create #gaming', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s] = req('GET', '/app?channel=gaming', [], $cjA);
check('/app?channel=gaming 200', $s === 200, (string) $s);

// ── Create channel options (modal) ───────────────────────────────────────────
// A bare name (no #) is auto-prefixed; topic / register / privacy are applied.
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $t, 'name' => 'secretlab', 'topic' => 'top secret tests', 'register' => '1', 'visibility' => 'secret', 'invite_only' => '1'], $cjA);
check('create channel without # prefix', $s === 200 && jsonDecode($b)['ok'] === true, $b);
$sl = dbq("SELECT name, topic, registered_at, visibility, invite_only FROM channels WHERE slug = 'secretlab'")[0] ?? null;
check('bare name auto-prefixed with #', ($sl['name'] ?? '') === '#secretlab', json_encode($sl));
check('topic persisted', ($sl['topic'] ?? '') === 'top secret tests', json_encode($sl));
check('register=1 sets registered_at', !empty($sl['registered_at']), json_encode($sl));
check('visibility=secret persisted', ($sl['visibility'] ?? '') === 'secret', json_encode($sl));
check('invite_only=1 persisted', (int) ($sl['invite_only'] ?? 0) === 1, json_encode($sl));
// The earlier #gaming was created without register → still temporary.
$gm = dbq("SELECT registered_at FROM channels WHERE slug = 'gaming'")[0] ?? null;
check('default create stays unregistered', empty($gm['registered_at']), json_encode($gm));
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $t, 'name' => '#badvis', 'visibility' => 'bogus'], $cjA);
check('invalid visibility rejected (400)', $s === 400 && strpos($b, 'public, private or secret') !== false, "$s $b");

[$s, , $b] = req('POST', '/api/send', ['csrf' => $t, 'channel' => 'gaming', 'content' => 'hello world'], $cjA);
$j = jsonDecode($b);
check('send message ok', $s === 200 && ($j['ok'] ?? false) === true && ($j['message']['content'] ?? '') === 'hello world', $b);
$msgId = $j['message']['id'] ?? 0;

[$s, , $b] = req('GET', '/api/search?q=hello', [], $cjA);
$j = jsonDecode($b);
$found = false;
foreach (($j['results']['channels'] ?? []) as $r) { if (($r['channel_slug'] ?? '') === 'gaming' && str_contains($r['content'] ?? '', 'hello')) $found = true; }
check('search finds channel message', $s === 200 && $found, $b);

[$s, , $b] = req('GET', '/api/search?q=zzzz-no-such-term', [], $cjA);
$j = jsonDecode($b);
check('search handles hyphenated term', $s === 200 && !count($j['results']['channels'] ?? []), $b);

// ── GIF messages ─────────────────────────────────────────────────────────────
echo "== gif ==\n";
$t = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/send', ['csrf' => $t, 'channel' => 'gaming', 'gif_url' => 'https://media.giphy.com/media/abc123/giphy.gif', 'gif_title' => 'dancing cat'], $cjA);
$j = jsonDecode($b);
check('gif posted to channel (kind gif)', $s === 200 && ($j['ok'] ?? false) === true && ($j['message']['kind'] ?? '') === 'gif', $b);
check('gif content stores url + title', str_starts_with((string) ($j['message']['content'] ?? ''), 'https://media.giphy.com/'), $b);
[$s, , $b] = req('POST', '/api/send', ['csrf' => $t, 'channel' => 'gaming', 'gif_url' => 'https://evil.example/x.gif', 'gif_title' => 'bad'], $cjA);
check('gif from disallowed host rejected (400)', $s === 400, $b);
// GIF search proxy without a configured key reports a clear error (no upstream call).
[$s, , $b] = req('GET', '/api/gifs?q=cat', [], $cjA);
$j = jsonDecode($b);
check('gif search not-configured error', $s === 200 && ($j['ok'] ?? true) === false && ($j['error'] ?? '') !== '', $b);
// The posted GIF is findable through chat search by its title.
[$s, , $b] = req('GET', '/api/search?q=dancing', [], $cjA);
$j = jsonDecode($b);
$found = false;
foreach (($j['results']['channels'] ?? []) as $r) { if (str_contains((string) ($r['content'] ?? ''), 'media.giphy.com')) $found = true; }
check('posted gif searchable by title', $s === 200 && $found, $b);

[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0', [], $cjA);
$j = jsonDecode($b);
check('poll returns messages', $s === 200 && count($j['messages'] ?? []) > 0, $b);
check('poll always returns dm_list (live DM sidebar)', $s === 200 && isset($j['dm_list']) && is_array($j['dm_list']), $b);

[$s, , $b] = req('POST', '/api/command', ['csrf' => $t, 'channel' => 'gaming', 'text' => '/topic #gaming cool chat'], $cjA);
check('/topic command', $s === 200 && str_contains(jsonDecode($b)['replies'][0] ?? '', 'Topic set'), $b);

[$s, , $b] = req('POST', '/api/command', ['csrf' => $t, 'text' => '/info'], $cjA);
check('/info command', $s === 200 && (jsonDecode($b)['replies'][0] ?? '') !== '', $b);

// rate limiting: 13+ quick sends -> 429
[$s] = req('POST', '/api/send', ['csrf' => $t, 'channel' => 'gaming', 'content' => 'spam'], $cjA);
$got429 = false;
for ($i = 0; $i < 15; $i++) {
    [$sc] = req('POST', '/api/send', ['csrf' => $t, 'channel' => 'gaming', 'content' => "spam $i"], $cjA);
    if ($sc === 429) {
        $got429 = true;
        break;
    }
}
check('rate limiting returns 429', $got429);
sleep(6); // let the 5s rate-limit window clear before the PM tests

// ── PMs ──────────────────────────────────────────────────────────────────────
echo "== private messages ==\n";
$cjB = '/tmp/opencode/httptest-b.txt';
$t = csrf(req('GET', '/register', [], $cjB)[2]);
[$s, , $b] = req('POST', '/register', ['csrf' => $t, 'username' => 'bob', 'email' => 'bob@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjB);
check('register bob', $s === 302, (string) $s);

$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/send', ['csrf' => $tA, 'recipient' => 'bob', 'content' => 'hi bob'], $cjA);
check('PM from alice to bob', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('GET', '/api/poll?dm=bob&since=0', [], $cjA);
$j = jsonDecode($b);
check('bob DM appears in alice poll', $s === 200 && count($j['messages'] ?? []) === 1, $b);
// A DM sent while the recipient is NOT on the DM page must still surface in
// the recipient's poll via dm_list (this was the "DMs don't land" bug).
[$s, , $b] = req('GET', '/api/poll?since=0', [], $cjB);
$j = jsonDecode($b);
$found = false;
foreach (($j['dm_list'] ?? []) as $d) {
    if ($d['username'] === 'alice' && (int) $d['unread'] >= 1) $found = true;
}
check('channel-user poll surfaces the unread DM (dm_list)', $s === 200 && $found, $b);

// ── Messenger: CORS + user-directory search + contact groups ────────────────
echo "== messenger ==\n";
// Current-user endpoint for app clients.
[$s] = req('GET', '/api/me');
check('me requires auth', $s === 401, (string) $s);
[$s, , $b] = req('GET', '/api/me', [], $cjA);
$j = jsonDecode($b);
check('me returns the session user', $s === 200 && ($j['user']['username'] ?? '') === 'alice' && isset($j['user']['id']) && array_key_exists('avatar', $j['user'] ?? []), $b);
[$s, , $b] = req('GET', '/api/csrf', [], $cjA);
$j = jsonDecode($b);
check('csrf endpoint returns the session token', $s === 200 && is_string($j['csrf'] ?? null) && strlen($j['csrf'] ?? '') === 64, $b);
// Channel read endpoint (clears a room's unread for app clients).
$t = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/channel/read', ['csrf' => $t, 'channel' => 'gaming'], $cjA);
check('mark channel read', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('POST', '/api/channel/read', ['csrf' => $t, 'channel' => 'no-such'], $cjA);
check('channel read rejects unknown channel', $s === 404, (string) $s);
// CORS: allowlisted loopback origin gets headers; unknown origins don't.
[$s, $h] = req('GET', '/api/version', [], null, ['Origin: http://127.0.0.1:48231']);
check('loopback origin gets CORS headers', ($h['access-control-allow-origin'] ?? '') === 'http://127.0.0.1:48231' && ($h['access-control-allow-credentials'] ?? '') === 'true', var_export($h, true));
[$s, $h] = req('GET', '/api/version', [], null, ['Origin: https://evil.example']);
check('unknown origin gets no CORS headers', !isset($h['access-control-allow-origin']), var_export($h, true));
[$s, $h] = req('GET', '/api/version', [], null, ['Origin: null']);
check('null origin (electron file://) allowed', ($h['access-control-allow-origin'] ?? '') === 'null', var_export($h, true));

// Directory search: auth required, then excludes self + reports status.
[$s] = req('GET', '/api/directory?q=bob');
check('directory search requires auth', $s === 401, (string) $s);
[$s, , $b] = req('GET', '/api/directory?q=bob', [], $cjA);
$j = jsonDecode($b);
$hit = null;
foreach (($j['results'] ?? []) as $r) { if (($r['username'] ?? '') === 'bob') $hit = $r; }
check('directory finds bob with status none', $s === 200 && $hit !== null && ($hit['status'] ?? '') === 'none' && isset($hit['is_online']), $b);
[$s, , $b] = req('GET', '/api/directory?q=alice', [], $cjA);
$j2 = jsonDecode($b);
check('directory excludes self', $s === 200 && count($j2['results'] ?? []) === 0, $b);
[$s, , $b] = req('GET', '/api/directory?q=', [], $cjA);
check('empty directory query returns empty', $s === 200 && count(jsonDecode($b)['results'] ?? []) === 0, $b);

// Friend flow drives the relationship status inside directory results.
[$s, , $b] = req('POST', '/api/friend/request', ['csrf' => $tA, 'username' => 'bob'], $cjA);
check('alice -> bob friend request', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('GET', '/api/directory?q=bob', [], $cjA);
$j = jsonDecode($b);
$hit = null;
foreach (($j['results'] ?? []) as $r) { if (($r['username'] ?? '') === 'bob') $hit = $r; }
check('directory status flips to outgoing', ($hit['status'] ?? '') === 'outgoing', $b);
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s] = req('POST', '/api/friend/accept', ['csrf' => $tB, 'username' => 'alice'], $cjB);
check('bob accepts', $s === 200, (string) $s);

// ── Presence statuses: online / away / dnd / invisible / custom ──────────
[$s, , $b] = req('POST', '/api/status', ['csrf' => $tB, 'status_mode' => 'dnd'], $cjB);
$j = jsonDecode($b);
check('bob sets DND', $s === 200 && ($j['status']['status_mode'] ?? '') === 'dnd' && ($j['status']['dnd'] ?? 0) === 1 && ($j['status']['is_online'] ?? 0) === 1, $b);
[$s, , $b] = req('GET', '/api/friends', [], $cjA);
$j = jsonDecode($b);
$bob = null;
foreach (($j['friends'] ?? []) as $f) { if (($f['username'] ?? '') === 'bob') $bob = $f; }
check('friend presence carries dnd status', ($bob['status_mode'] ?? '') === 'dnd' && ($bob['dnd'] ?? 0) === 1 && ($bob['is_online'] ?? 0) === 1, json_encode($bob));
[$s, , $b] = req('POST', '/api/send', ['csrf' => $tA, 'recipient' => 'bob', 'content' => 'dnd test dm'], $cjA);
$dndMsgId = (int) (jsonDecode($b)['message']['id'] ?? 0);
check('DM to a dnd user still delivers', $s === 200 && $dndMsgId > 0, $b);
$dndNotif = (int) (dbq("SELECT COUNT(*) AS c FROM notifications WHERE user_id = (SELECT id FROM users WHERE username = 'bob') AND kind = 'dm' AND message_id = ?", [$dndMsgId])[0]['c'] ?? 0);
check('DND suppresses the DM bell/push notification', $dndNotif === 0, (string) $dndNotif);

[$s, , $b] = req('POST', '/api/status', ['csrf' => $tB, 'status_mode' => 'invisible'], $cjB);
check('bob sets invisible', $s === 200 && (jsonDecode($b)['status']['status_mode'] ?? '') === 'invisible', $b);
[$s, , $b] = req('GET', '/api/friends', [], $cjA);
$j = jsonDecode($b);
$bob = null;
foreach (($j['friends'] ?? []) as $f) { if (($f['username'] ?? '') === 'bob') $bob = $f; }
check('invisible friend appears offline', ($bob['is_online'] ?? 1) === 0 && ($bob['invisible'] ?? 0) === 1, json_encode($bob));
[$s, , $b] = req('GET', '/api/online', [], $cjA);
$j = jsonDecode($b);
$invis = false;
foreach (($j['online'] ?? []) as $u) { if (($u['username'] ?? '') === 'bob') $invis = true; }
check('invisible user excluded from /api/online', !$invis, $b);

[$s, , $b] = req('POST', '/api/status', ['csrf' => $tB, 'status_mode' => 'custom', 'custom_status' => 'streaming tonight'], $cjB);
$j = jsonDecode($b);
check('bob sets a custom status', $s === 200 && ($j['status']['status_mode'] ?? '') === 'custom' && ($j['status']['custom_status'] ?? '') === 'streaming tonight', $b);
[$s, , $b] = req('GET', '/api/friends', [], $cjA);
$j = jsonDecode($b);
$bob = null;
foreach (($j['friends'] ?? []) as $f) { if (($f['username'] ?? '') === 'bob') $bob = $f; }
check('friend presence carries custom status text', ($bob['status_mode'] ?? '') === 'custom' && ($bob['custom_status'] ?? '') === 'streaming tonight', json_encode($bob));
[$s] = req('POST', '/api/status', ['csrf' => $tB, 'status_mode' => 'online'], $cjB);
check('bob back online', $s === 200, (string) $s);

// The web chat header renders the rich status UI (avatar dot + clickable line).
[$s, , $b] = req('GET', '/app?channel=gaming', [], $cjA);
check('web header renders the avatar status dot + status line', $s === 200 && strpos($b, 'me-header-avatar') !== false && strpos($b, 'avatar-status') !== false && strpos($b, 'me-status-line') !== false, (string) $s);

// Contact groups CRUD.
[$s] = req('GET', '/api/groups');
check('groups list requires auth', $s === 401, (string) $s);
[$s, , $b] = req('GET', '/api/groups', [], $cjA);
check('alice starts with no groups', $s === 200 && count(jsonDecode($b)['groups'] ?? []) === 0, $b);
[$s] = req('POST', '/api/groups', ['name' => 'Streaming Pals'], $cjA);
check('group create requires csrf', $s === 419, (string) $s);
[$s, , $b] = req('POST', '/api/groups', ['csrf' => $tA, 'name' => 'Streaming Pals'], $cjA);
$j = jsonDecode($b);
check('create group', $s === 200 && ($j['ok'] ?? false) === true && ($j['group']['name'] ?? '') === 'Streaming Pals', $b);
$gid = (int) ($j['group']['id'] ?? 0);
[$s, , $b] = req('POST', '/api/groups', ['csrf' => $tA, 'name' => 'streaming pals'], $cjA);
check('duplicate group name rejected', $s === 400, (string) $s);
[$s, , $b] = req('POST', '/api/groups', ['csrf' => $tA, 'name' => '   '], $cjA);
check('blank group name rejected', $s === 400, (string) $s);

// Membership is enforced to accepted friends only.
$bobId = (int) (dbq('SELECT id FROM users WHERE username = "bob"')[0]['id'] ?? 0);
[$s, , $b] = req('POST', '/api/groups/member/add', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => (string) $bobId], $cjA);
$j = jsonDecode($b);
check('add friend to group', $s === 200 && ($j['ok'] ?? false) === true, $b);
[$s, , $b] = req('POST', '/api/groups/member/add', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => (string) $bobId], $cjA);
check('duplicate member rejected', $s === 400, (string) $s);
$aliceId = (int) (dbq('SELECT id FROM users WHERE username = "alice"')[0]['id'] ?? 0);
[$s, , $b] = req('POST', '/api/groups/member/add', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => (string) $aliceId], $cjA);
check('cannot add self to group', $s === 400, (string) $s);
[$s, , $b] = req('POST', '/api/groups/member/add', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => '999999'], $cjA);
check('unknown member rejected', $s === 400, (string) $s);

// A non-friend (dave) can be found in the directory but not grouped.
$tD = csrf(req('GET', '/register', [], '/tmp/opencode/httptest-d.txt')[2]);
[$s] = req('POST', '/register', ['csrf' => $tD, 'username' => 'dave', 'email' => 'dave@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], '/tmp/opencode/httptest-d.txt');
check('register dave', $s === 302, (string) $s);
$daveId = (int) (dbq('SELECT id FROM users WHERE username = "dave"')[0]['id'] ?? 0);
[$s, , $b] = req('POST', '/api/groups/member/add', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => (string) $daveId], $cjA);
check('non-friend cannot be grouped', $s === 400, (string) $s);

[$s, , $b] = req('GET', '/api/groups', [], $cjA);
$j = jsonDecode($b);
$g = $j['groups'][0] ?? [];
check('group list has member bob', $s === 200 && count($j['groups'] ?? []) === 1 && count($g['members'] ?? []) === 1 && ($g['members'][0]['username'] ?? '') === 'bob', $b);
[$s, , $b] = req('POST', '/api/groups/rename', ['csrf' => $tA, 'id' => (string) $gid, 'name' => 'Gaming Pals'], $cjA);
check('rename group', $s === 200 && (jsonDecode($b)['group']['name'] ?? '') === 'Gaming Pals', $b);
[$s, , $b] = req('POST', '/api/groups/member/remove', ['csrf' => $tA, 'group_id' => (string) $gid, 'friend_id' => (string) $bobId], $cjA);
check('remove member from group', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('GET', '/api/groups', [], $cjA);
check('group is empty after member remove', count(jsonDecode($b)['groups'][0]['members'] ?? []) === 0, $b);
[$s, , $b] = req('POST', '/api/groups/delete', ['csrf' => $tA, 'id' => (string) $gid], $cjA);
check('delete group', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('GET', '/api/groups', [], $cjA);
check('no groups after delete', count(jsonDecode($b)['groups'] ?? []) === 0, $b);

// ── Admin actions ────────────────────────────────────────────────────────────
echo "== admin ==\n";
foreach (['/admin', '/admin/analytics', '/admin/users', '/admin/channels', '/admin/bans', '/admin/spamfilters', '/admin/motd', '/admin/sounds', '/admin/logs', '/admin/settings', '/admin/webhooks', '/admin/support'] as $p) {
    [$s] = req('GET', $p, [], $cjA);
    check("GET $p 200", $s === 200, (string) $s);
}
$adminSettingsPage = req('GET', '/admin/settings', [], $cjA)[2];
check('settings page has force-websocket checkbox', str_contains($adminSettingsPage, 'name="realtime_force"'), '');
check('settings page has reconnect-clients button', str_contains($adminSettingsPage, 'id="ws-btn-reconnect"'), '');
check('settings page has desktop download fields', str_contains($adminSettingsPage, 'name="download_desktop_win_url"') && str_contains($adminSettingsPage, 'name="download_desktop_win_version"') && str_contains($adminSettingsPage, 'name="download_messenger_linux_appimage_url"') && str_contains($adminSettingsPage, 'name="download_update_url"'), '');
[$s] = req('GET', '/admin', [], $cjB);
check('non-admin denied /admin', $s === 403, (string) $s);

// Gateway daemon management endpoints (Admin → Settings → WebSocket).
[$s, , $b] = req('GET', '/admin/ws/status', [], $cjA);
check('ws status 200 + JSON shape', $s === 200 && str_contains($b, '"running"'), (string) $s . ' ' . substr($b, 0, 80));
[$s] = req('GET', '/admin/ws/status', [], $cjB);
check('non-admin denied ws status', $s === 403, (string) $s);
$tWs = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/ws/control', ['csrf' => 'bogus', 'action' => 'stop'], $cjA);
check('ws control requires valid csrf', $s === 419, (string) $s);
[$s] = req('POST', '/admin/ws/control', ['csrf' => $tWs, 'action' => 'bogus'], $cjA);
check('ws control rejects unknown action', $s === 400, (string) $s);
[$s] = req('POST', '/admin/deploy/stream', ['csrf' => 'bogus'], $cjA);
check('deploy stream requires valid csrf', $s === 419, (string) $s);

$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s, , $b] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_ban', 'id' => '', 'back' => '/admin/users'], $cjA);
check('ban action with bad id handled', $s === 302, (string) $s);

[$s, , $b] = req('GET', '/admin/logs?q=hello', [], $cjA);
check('admin log search', $s === 200, (string) $s);

// Webhook: admin creates one, then an unauthenticated POST posts into a channel.
$tA = csrf(req('GET', '/admin/webhooks', [], $cjA)[2]);
[$s] = req('POST', '/admin/webhooks/action', ['csrf' => $tA, 'action' => 'webhook_create', 'channel_id' => '1', 'name' => 'Forum Bot'], $cjA);
check('admin creates webhook', $s === 302, (string) $s);
// Fetch the token from the flash URL shown on the webhooks page (next request re-renders the created box).
$hookPage = req('GET', '/admin/webhooks', [], $cjA)[2];
preg_match('#/api/webhooks/([0-9a-f]{40,})#', $hookPage, $m);
$whToken = $m[1] ?? '';
check('webhook token surfaced once', $whToken !== '', $hookPage);
[$s, , $b] = req('POST', '/api/webhooks/' . $whToken, ['content' => 'hello from webhook'], null, ['Content-Type' => 'application/x-www-form-urlencoded']);
$j = jsonDecode($b);
check('webhook POST posts a message', $s === 200 && ($j['ok'] ?? false) === true, "$s $b");
[$s] = req('POST', '/api/webhooks/' . str_repeat('0', 48), ['content' => 'x']);
check('webhook unknown token 404', $s === 404, (string) $s);

// Staff-created support tickets (user + email) and assignment.
$tA = csrf(req('GET', '/admin/support', [], $cjA)[2]);
[$s, $h, $b] = req('POST', '/admin/support/create', ['csrf' => $tA, 'user_id' => '1', 'email' => '', 'subject' => 'Staff ticket for alice', 'content' => 'body', 'assigned_to' => '1'], $cjA);
check('staff creates user ticket', $s === 302 && preg_match('#/admin/support/\d+#', $h['location'] ?? ''), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('POST', '/admin/support/create', ['csrf' => $tA, 'user_id' => '', 'email' => 'customer@ext.com', 'subject' => 'Email ticket', 'content' => 'body', 'assigned_to' => ''], $cjA);
check('staff creates email ticket', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/admin/support', [], $cjA);
check('admin support lists email ticket', $s === 200 && str_contains($b, 'customer@ext.com'), $b);
$tA = csrf(req('GET', '/admin/support/1', [], $cjA)[2]);
[$s, , $b] = req('POST', '/admin/support/1/assign', ['csrf' => $tA, 'assigned_to' => '1'], $cjA);
check('staff assigns ticket', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/admin/support?assignee=mine', [], $cjA);
check('mine filter shows assigned ticket', $s === 200 && str_contains($b, 'Staff ticket'), $b);

// Site logo: save a logo URL, verify it renders in place of the site name.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'site_name' => 'LVChat', 'logo_url' => 'https://example.com/logo.png', 'registration_enabled' => '1', 'back' => '/admin/settings'], $cjA);
check('save site logo setting', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/login');
check('logo renders on login page', $s === 200 && strpos($b, 'https://example.com/logo.png') !== false, $b);

// ── Invites + manual user creation ───────────────────────────────────────────
echo "== invites ==\n";
[$s] = req('GET', '/admin/invites', [], $cjA);
check('GET /admin/invites 200', $s === 200, (string) $s);

$tA = csrf(req('GET', '/admin/invites', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'invite_create', 'email' => 'invited@x.com', 'message' => 'join us', 'back' => '/admin/invites'], $cjA);
check('invite_create redirects', $s === 302, (string) $s);
$invPage = req('GET', '/admin/invites', [], $cjA)[2];
preg_match('#/register\?invite=([0-9a-f]{48})#', $invPage, $m);
$invToken = $m[1] ?? '';
check('invite link surfaced on invites page', $invToken !== '', $invPage);

$cjE = '/tmp/opencode/httptest-e.txt';
[$s, , $b] = req('GET', '/register?invite=' . $invToken, [], $cjE);
check('invite link opens locked register form', $s === 200 && strpos($b, 'invited@x.com') !== false && strpos($b, 'name="invite"') !== false, $b);
$t = csrf($b);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'invite' => $invToken, 'username' => 'erin', 'email' => 'invited@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjE);
check('register via invite 302', $s === 302 && ($h['location'] ?? '') === '/', "$s " . ($h['location'] ?? ''));
$pdo = new PDO('sqlite:' . $DB);
$used = (int) $pdo->query('SELECT COUNT(*) FROM registration_invites WHERE token = ' . $pdo->quote($invToken) . ' AND used_at IS NOT NULL')->fetchColumn();
check('invite marked used after registration', $used === 1, "used=$used");
[$s] = req('GET', '/register?invite=' . $invToken, [], '/tmp/opencode/httptest-e2.txt');
check('used invite link redirects away', $s === 302, (string) $s);

// Invites bypass closed registration; plain registration is blocked.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '0', 'back' => '/admin/settings'], $cjA);
$tA = csrf(req('GET', '/admin/invites', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'invite_create', 'email' => 'invited2@x.com', 'back' => '/admin/invites'], $cjA);
$invPage = req('GET', '/admin/invites', [], $cjA)[2];
preg_match('#/register\?invite=([0-9a-f]{48})#', $invPage, $m);
$invToken2 = $m[1] ?? '';
check('second invite link available', $invToken2 !== '', $invPage);
$cjF = '/tmp/opencode/httptest-f.txt';
$t = csrf(req('GET', '/register', [], $cjF)[2]);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => 'frank2', 'email' => 'frank2@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjF);
check('plain register blocked when registration closed', $s === 302 && str_contains($h['location'] ?? '', '/register'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/register?invite=' . $invToken2, [], $cjF);
$t = csrf($b);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'invite' => $invToken2, 'username' => 'frank', 'email' => 'invited2@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjF);
check('invite bypasses closed registration', $s === 302 && ($h['location'] ?? '') === '/', "$s " . ($h['location'] ?? ''));
// Restore open registration for the rest of the suite.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '1', 'back' => '/admin/settings'], $cjA);

// Per-IP registration throttle: N accounts per window, then blocked. Invites exempt.
dbq('DELETE FROM registration_attempts');
dbq("INSERT OR REPLACE INTO server_config (key, value) VALUES ('registration_rate_limit', '2')");
$cjR = '/tmp/opencode/httptest-r.txt';
foreach (['rlim1', 'rlim2'] as $rn) {
    $jar = $cjR . '-' . $rn;
    $t = csrf(req('GET', '/register', [], $jar)[2]);
    [$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => $rn, 'email' => "$rn@x.com", 'password' => 'password123', 'age18' => '1', 'next' => '/'], $jar);
    check("rate limit allows registration $rn", $s === 302 && ($h['location'] ?? '') === '/', "$s " . ($h['location'] ?? ''));
}
$jar = $cjR . '-rlim3';
$t = csrf(req('GET', '/register', [], $jar)[2]);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => 'rlim3', 'email' => 'rlim3@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $jar);
check('rate limit blocks over the cap', $s === 302 && str_contains($h['location'] ?? '', '/register'), "$s " . ($h['location'] ?? ''));
$rlim3 = dbq('SELECT COUNT(*) AS n FROM users WHERE username = ?', ['rlim3']);
check('rate-limited registration created no user', (int) $rlim3[0]['n'] === 0, json_encode($rlim3));
// Reset the throttle so the rest of the suite is unaffected.
dbq("DELETE FROM server_config WHERE key = 'registration_rate_limit'");
dbq('DELETE FROM registration_attempts');

// Manual user creation: auto-generated password shown once, then login works.
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_create', 'username' => 'manual', 'email' => 'manual@x.com', 'role' => 'user', 'back' => '/admin/users'], $cjA);
check('user_create redirects', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/admin/users', [], $cjA);
preg_match('/Password: ([0-9a-f]{16})/', $b, $pm);
$manualPw = $pm[1] ?? '';
check('created user password shown once', $manualPw !== '', $b);
$cjM = '/tmp/opencode/httptest-m.txt';
$t = csrf(req('GET', '/login', [], $cjM)[2]);
[$s, $h] = req('POST', '/login', ['csrf' => $t, 'username' => 'manual', 'password' => $manualPw, 'next' => '/'], $cjM);
check('manually created user logs in with shown password', $s === 302 && ($h['location'] ?? '') === '/', "$s " . ($h['location'] ?? ''));

// ── User deletion ────────────────────────────────────────────────────────────
echo "== user delete ==\n";
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_create', 'username' => 'doomed', 'email' => 'doomed@x.com', 'role' => 'user', 'back' => '/admin/users'], $cjA);
[$s, , $b] = req('GET', '/admin/users', [], $cjA);
preg_match('/Password: ([0-9a-f]{16})/', $b, $dm);
$doomedPw = $dm[1] ?? '';
check('doomed created with password', $doomedPw !== '', $b);
// Log in as the doomed user and have them own a temporary channel.
$cjX = '/tmp/opencode/httptest-x.txt';
$t = csrf(req('GET', '/login', [], $cjX)[2]);
[$s, $h] = req('POST', '/login', ['csrf' => $t, 'username' => 'doomed', 'password' => $doomedPw, 'next' => '/'], $cjX);
check('doomed logs in', $s === 302 && ($h['location'] ?? '') === '/', "$s " . ($h['location'] ?? ''));
$tX = csrf(req('GET', '/app', [], $cjX)[2]);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $tX, 'name' => '#doomedroom'], $cjX);
check('doomed creates a channel', $s === 200 && jsonDecode($b)['ok'] === true, $b);
// A second channel with another member: ownership should pass to the heir.
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $tX, 'name' => '#ownedroom'], $cjX);
check('doomed creates an owned channel', $s === 200 && jsonDecode($b)['ok'] === true, $b);
$tM = csrf(req('GET', '/app', [], $cjM)[2]);
[$s, , $b] = req('POST', '/api/join', ['csrf' => $tM, 'name' => '#ownedroom'], $cjM);
check('manual joins #ownedroom', $s === 200, "$s $b");
$pdo = new PDO('sqlite:' . $DB);
$doomedId = (int) $pdo->query("SELECT id FROM users WHERE username = 'doomed'")->fetchColumn();
check('doomed user id found', $doomedId > 0, "id=$doomedId");
// Delete the user as admin; their sessions + empty temp channel should disappear.
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_delete', 'id' => (string) $doomedId, 'back' => '/admin/users'], $cjA);
check('user_delete redirects', $s === 302, (string) $s);
check('deleted user row gone', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $doomedId)->fetchColumn() === 0);
check('deleted user sessions gone', (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE user_id = " . $doomedId)->fetchColumn() === 0);
check('ownerless temp channel removed', (int) $pdo->query("SELECT COUNT(*) FROM channels WHERE name = '#doomedroom'")->fetchColumn() === 0);
check('channel with a remaining member survives', (int) $pdo->query("SELECT COUNT(*) FROM channels WHERE name = '#ownedroom'")->fetchColumn() === 1);
$heir = $pdo->query("SELECT u.username FROM channels c JOIN users u ON u.id = c.owner_id WHERE c.name = '#ownedroom'")->fetchColumn();
check('ownership passed to remaining member', $heir === 'manual', (string) $heir);
check('user_delete is audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user_delete' AND target = 'doomed'")->fetchColumn() >= 1);
// Admin cannot delete themselves.
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_delete', 'id' => '1', 'back' => '/admin/users'], $cjA);
check('self-delete blocked (admin still exists)', $s === 302 && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = 1")->fetchColumn() === 1, (string) $s);

// ── DM image attachments ─────────────────────────────────────────────────────
echo "== dm upload ==\n";
// Earlier settings_save runs reset the uploads toggle; make sure it's on.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '1', 'uploads_enabled' => '1', 'back' => '/admin/settings'], $cjA);
check('uploads enabled for test', $s === 302, (string) $s);
file_put_contents('/tmp/opencode/dmtest.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$t = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, $b] = uploadReq('/api/upload', $cjA, ['csrf' => $t, 'ajax' => '1', 'dm' => 'bob'], ['tmp' => '/tmp/opencode/dmtest.png', 'type' => 'image/png', 'name' => 'dmtest.png']);
$j = jsonDecode($b);
check('DM image upload ok (kind image)', $s === 200 && ($j['ok'] ?? false) === true && ($j['message']['kind'] ?? '') === 'image' && ($j['message']['is_pm'] ?? false) === true, "$s $b");
check('DM image content is an upload url', str_starts_with((string) ($j['message']['content'] ?? ''), '/uploads/'), $b);
$imgUrl = $j['message']['content'] ?? '';
// The recipient sees the image PM in their DM poll, with kind image preserved.
[$s, , $b] = req('GET', '/api/poll?dm=alice&since=0', [], $cjB);
$j = jsonDecode($b);
$found = false;
foreach (($j['messages'] ?? []) as $m) {
    if (($m['kind'] ?? '') === 'image' && ($m['content'] ?? '') === $imgUrl && ($m['is_pm'] ?? false) === true) $found = true;
}
check('recipient DM poll surfaces the image PM', $s === 200 && $found, "$s $b");

// GIFs post into DMs with kind gif, exactly like image attachments.
$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/send', ['csrf' => $tA, 'recipient' => 'bob', 'gif_url' => 'https://media.giphy.com/media/dm/giphy.gif', 'gif_title' => 'DM GIF'], $cjA);
$j = jsonDecode($b);
check('gif posted to DM (kind gif)', $s === 200 && ($j['ok'] ?? false) === true && ($j['message']['kind'] ?? '') === 'gif' && ($j['message']['is_pm'] ?? false) === true, "$s $b");
[$s, , $b] = req('GET', '/api/poll?dm=alice&since=0', [], $cjB);
$j = jsonDecode($b);
$gifFound = false;
foreach (($j['messages'] ?? []) as $m) {
    if (($m['kind'] ?? '') === 'gif' && str_contains((string) ($m['content'] ?? ''), 'media.giphy.com') && ($m['is_pm'] ?? false) === true) $gifFound = true;
}
check('recipient DM poll surfaces the GIF PM', $s === 200 && $gifFound, "$s $b");

// SMTP settings save + graceful test failure (no SMTP server running).
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '1', 'smtp_enabled' => '1', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none', 'smtp_from_email' => 'noreply@x.com', 'smtp_from_name' => 'LVChat', 'back' => '/admin/settings'], $cjA);
check('SMTP settings saved', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/admin/settings', [], $cjA);
check('SMTP from email persists', $s === 200 && strpos($b, 'noreply@x.com') !== false, $b);
$tA = csrf($b);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'smtp_test', 'back' => '/admin/settings'], $cjA);
[$s, , $b] = req('GET', '/admin/settings', [], $cjA);
check('smtp_test reports failure gracefully', $s === 200 && strpos($b, 'SMTP test failed') !== false, $b);

// Embeddable channel page: gates on auth, offers guest/login/register.
[$s, , $b] = req('GET', '/embed/general');
check('embed page prompts sign-in (guest form)', $s === 200 && strpos($b, 'Chat as guest') !== false && strpos($b, '/guest') !== false, $b);
[$s, $h] = req('GET', '/embed/general', [], $cjA);
check('logged-in embed redirects into the channel', $s === 302 && str_contains($h['location'] ?? '', '/c/general'), "$s " . ($h['location'] ?? ''));

// Browse page shows live + peak concurrency stats.
[$s, , $b] = req('GET', '/browse', [], $cjA);
check('browse page shows online + peak stats', $s === 200 && strpos($b, '>Online<') !== false && strpos($b, '>Peak<') !== false, $b);

// Messenger room-browser API: public rooms (+ the caller's own) with shape.
[$s, , $b] = req('GET', '/api/browse', [], $cjA);
$br = jsonDecode($b);
$brPublic = array_column($br['channels'] ?? [], 'name');
$brMine = array_column($br['myChannels'] ?? [], 'name');
$brAll = array_merge($brPublic, $brMine);
$brShapeOk = (bool) ($br['channels'] ?? null) && is_array($br['channels']) && ($br['myChannels'] ?? null) && is_array($br['myChannels']) && ($br['online'] ?? null) !== null;
check('/api/browse ok with channels + myChannels + online', $s === 200 && $brShapeOk, $b);
check('/api/browse lists public rooms', $s === 200 && in_array('#gaming', $brAll, true) && in_array('#general', $brPublic, true), json_encode($brPublic));
check('/api/browse hides secret rooms from the public list', !in_array('#secretlab', $brPublic, true), json_encode($brPublic));
check('/api/browse carries join + room metadata', isset($br['channels'][0]['joined']) && isset($br['channels'][0]['members']) && isset($br['channels'][0]['online']) && isset($br['channels'][0]['visibility']), json_encode($br['channels'][0] ?? null));
[$s] = req('GET', '/api/browse');
check('/api/browse requires a session', $s === 401, (string) $s);

// ── Day log (modal text + export) ────────────────────────────────────────────
$today = gmdate('Y-m-d');
[$s, , $b] = req('GET', '/admin/logs/day?channel=%23gaming&date=' . $today, [], $cjA);
check('day log endpoint 200', $s === 200, (string) $s);
check('day log has header with topic', strpos($b, '#gaming - ' . $today) !== false, $b);
check('day log has message line', strpos($b, '- alice - hello world') !== false, $b);
check('day log has topic change line', strpos($b, '-Topic Changed to') !== false, $b);
[$s, $h, $b] = req('GET', '/admin/logs/export?channel=%23gaming&date=' . $today, [], $cjA);
check('day log export 200', $s === 200, (string) $s);
check('export sends attachment header', str_contains(strtolower($h['content-disposition'] ?? ''), 'attachment') && strpos($h['content-disposition'] ?? '', 'gaming-' . $today . '.log') !== false, $h['content-disposition'] ?? '');
[$s, , $b] = req('GET', '/admin/logs/day?channel=%23gaming&date=bad-date', [], $cjA);
check('bad date rejected', $s === 400, (string) $s);

// ── Private / keyed channels ─────────────────────────────────────────────────
echo "== private + keyed channels ==\n";
$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $tA, 'name' => '#secret'], $cjA);
check('create #secret', $s === 200, $b);
req('POST', '/api/command', ['csrf' => $tA, 'channel' => 'secret', 'text' => '/set #secret private on'], $cjA);
[$s, , $b] = req('GET', '/browse', [], $cjB);
check('#secret hidden from /browse for bob', strpos($b, '/c/secret') === false);
[$s, $h] = req('GET', '/c/secret', [], $cjB);
check('bob can join private #secret via link', $s === 302 && str_contains($h['location'] ?? '', 'channel=secret'), "$s " . ($h['location'] ?? ''));
req('POST', '/api/command', ['csrf' => $tA, 'channel' => 'secret', 'text' => '/mode #secret +k hunter2'], $cjA);
$cjD = '/tmp/opencode/httptest-d.txt';
$t = csrf(req('GET', '/register', [], $cjD)[2]);
req('POST', '/register', ['csrf' => $t, 'username' => 'dave', 'email' => 'dave@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjD);
[$s, $h] = req('GET', '/c/secret', [], $cjD);
check('keyed channel opens the join modal', $s === 302 && str_contains($h['location'] ?? '', '/app?join=secret'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/app?join=secret', [], $cjD);
check('join modal renders on the chat page', $s === 200 && strpos($b, 'join-modal') !== false && strpos($b, '#secret') !== false, "$s");
$tD = csrf($b);
[$s, , $j] = req('POST', '/api/join', ['csrf' => $tD, 'name' => '#secret', 'key' => 'wrongkey'], $cjD);
check('wrong key rejected via modal API', $s === 403 && strpos($j, 'Incorrect channel key') !== false, "$s $j");
[$s, , $j] = req('POST', '/api/join', ['csrf' => $tD, 'name' => '#secret', 'key' => 'hunter2'], $cjD);
check('correct key joins via modal API', $s === 200 && strpos($j, 'channel=secret') !== false, "$s $j");
[$s, $h] = req('GET', '/app?channel=secret', [], $cjD);
check('dave now in #secret', $s === 200, (string) $s);

// ── Staff channel ────────────────────────────────────────────────────────────
echo "== staff channel ==\n";
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s, , $b] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_staff', 'id' => '2', 'back' => '/admin/users'], $cjA);
check('promote bob to staff', $s === 302, (string) $s);
[$s, $h] = req('GET', '/c/staff', [], $cjB);
check('staff can join #staff', $s === 302 && str_contains($h['location'] ?? '', 'channel=staff'), "$s " . ($h['location'] ?? ''));
$cjC = '/tmp/opencode/httptest-c.txt';
$t = csrf(req('GET', '/register', [], $cjC)[2]);
req('POST', '/register', ['csrf' => $t, 'username' => 'carol', 'email' => 'carol@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjC);
[$s] = req('GET', '/c/staff', [], $cjC);
check('regular user denied #staff', $s === 200, (string) $s); // access-denied page (not redirect to chat)

// ── Operator action log + command list ───────────────────────────────────────
echo "== oper-log / commands ==\n";
[$s, $h] = req('GET', '/c/oper-log', [], $cjA);
check('admin can join #oper-log', $s === 302 && str_contains($h['location'] ?? '', 'channel=oper-log'), "$s " . ($h['location'] ?? ''));
[$s] = req('GET', '/c/oper-log', [], $cjC);
check('regular user denied #oper-log', $s === 200, (string) $s);
[$s, , $b] = req('GET', '/api/commands', [], $cjA);
$cmds = jsonDecode($b);
check('/api/commands returns the command list', $s === 200 && is_array($cmds['commands'] ?? null) && in_array('sanick', $cmds['commands'], true), "$s $b");
[$s] = req('GET', '/api/commands');
check('/api/commands requires a session', $s === 302, (string) $s);


// ── Misc ─────────────────────────────────────────────────────────────────────
echo "== misc ==\n";
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s] = req('POST', '/api/send', ['csrf' => $tB, 'channel' => 'gaming', 'content' => 'not a member'], $cjB);
check('non-member cannot send to #gaming', $s === 403, (string) $s);
[$s, , $b] = req('GET', '/api/notifications', [], $cjB);
check('notifications endpoint', $s === 200 && isset(jsonDecode($b)['notifications']), $b);
[$s] = req('POST', '/api/notifications/read', ['csrf' => $tB], $cjB);
check('mark notifications read', $s === 200, (string) $s);
// Founder-only channel delete (renames to -deleted####, history preserved).
$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $tA, 'name' => '#throwaway'], $cjA);
check('create #throwaway', $s === 200, $b);
[$s, , $b] = req('POST', '/api/channel/delete', ['csrf' => $tA, 'channel' => 'throwaway'], $cjA);
check('founder deletes channel via API', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('POST', '/api/channel/delete', ['csrf' => $tB, 'channel' => 'gaming'], $cjB);
check('non-founder cannot delete a channel', $s === 403, "$s $b");
[$s, , $b] = req('POST', '/api/message/edit', ['csrf' => $tA, 'id' => $msgId, 'content' => 'edited'], $cjA);
check('admin can edit a message', $s === 200 && jsonDecode($b)['ok'] === true, $b);
// Only admins may edit messages — a regular user gets 403.
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $b] = req('POST', '/api/message/edit', ['csrf' => $tB, 'id' => $msgId, 'content' => 'hacked'], $cjB);
check('non-admin cannot edit a message', $s === 403, "$s $b");
[$s, , $b] = req('GET', '/admin', [], $cjA);
check('message edit is audited in admin logs', $s === 200 && strpos($b, 'message_edit') !== false, $b);
[$s] = req('POST', '/api/message/delete', ['csrf' => $tA, 'id' => $msgId], $cjA);
check('delete own message', $s === 200, (string) $s);
[$s] = req('POST', '/api/message/delete', ['csrf' => $tA, 'id' => '999999'], $cjA);
check('delete non-existent message rejected', $s === 403, (string) $s);
[$s, , $b] = req('GET', '/u/alice', [], $cjA);
check('profile page', $s === 200, (string) $s);

// ── Sound alerts (admin upload + user settings + background channel pings) ───
echo "== sound alerts ==\n";
$pdo = new PDO('sqlite:' . $DB);
check('default sounds seeded over HTTP', (int) $pdo->query('SELECT COUNT(*) FROM sound_alerts')->fetchColumn() >= 3);
// Build a tiny valid WAV and upload it as an admin.
$wavPath = '/tmp/opencode/soundtest.wav';
$rate = 22050;
$n = (int) ($rate * 0.2);
$samples = '';
for ($i = 0; $i < $n; $i++) {
    $t = $i / $rate;
    $samples .= pack('v', ((int) round(sin(2 * M_PI * 500 * $t) * 0.4 * 32767)) & 0xFFFF);
}
file_put_contents($wavPath, 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVE'
    . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16)
    . 'data' . pack('V', strlen($samples)) . $samples);
$tA = csrf(req('GET', '/admin/sounds', [], $cjA)[2]);
[$s, $b] = uploadReq('/admin/action', $cjA, ['csrf' => $tA, 'action' => 'sound_add', 'name' => 'Test Blip', 'back' => '/admin/sounds'], ['tmp' => $wavPath, 'type' => 'audio/wav', 'name' => 'blip.wav']);
check('admin uploads a sound', $s === 302, "$s $b");
[$s, , $b] = req('GET', '/admin/sounds', [], $cjA);
check('admin sounds page lists upload', $s === 200 && strpos($b, 'Test Blip') !== false, (string) $s);
$soundId = (int) $pdo->query("SELECT id FROM sound_alerts WHERE name = 'Test Blip' ORDER BY id DESC LIMIT 1")->fetchColumn();
$soundFile = (string) $pdo->query("SELECT file FROM sound_alerts WHERE id = $soundId")->fetchColumn();
check('upload stored in sound_alerts', $soundId > 0 && str_contains($soundFile, '/assets/sounds/'), "id=$soundId file=$soundFile");

// User prefs API.
$tA = csrf(req('GET', '/u/alice', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/sound/prefs', ['csrf' => $tA, 'channel_sound' => (string) $soundId, 'dm_sound' => '0'], $cjA);
check('save sound prefs', $s === 200 && jsonDecode($b)['ok'] === true, "$s $b");
$prefRow = $pdo->query('SELECT dm_sound_id, channel_sound_id FROM user_sound_prefs WHERE user_id = 1')->fetch(PDO::FETCH_ASSOC);
check('prefs persisted (dm off, channel=upload)', (int) $prefRow['channel_sound_id'] === $soundId && $prefRow['dm_sound_id'] === null, json_encode($prefRow));
[$s, , $b] = req('GET', '/u/alice', [], $cjA);
check('profile page renders the sounds settings', $s === 200 && strpos($b, 'Notification sounds') !== false, (string) $s);

// Messenger audio alerts endpoint: the enabled sounds + the user's DM/channel
// choices + per-sender overrides in one payload.
[$s, , $b] = req('GET', '/api/sounds', [], $cjA);
$j = jsonDecode($b);
$sounds = $j['sounds'] ?? [];
check('sounds endpoint returns enabled sounds', $s === 200 && isset($sounds[$soundId]) && ($sounds[$soundId]['name'] ?? '') === 'Test Blip' && isset($sounds[$soundId]['url']), $b);
check('sounds endpoint reflects dm off / channel set', array_key_exists('dm_sound_id', $j) && $j['dm_sound_id'] === null && (int) ($j['channel_sound_id'] ?? 0) === $soundId, $b);
check('sounds endpoint returns overrides map', is_array($j['overrides'] ?? null), $b);

// Per-user override API: set a specific sound, then mute, then remove.
[$s, , $b] = req('POST', '/api/sound/override', ['csrf' => $tA, 'target_user_id' => '2', 'sound' => (string) $soundId], $cjA);
check('set per-user override sound', $s === 200 && jsonDecode($b)['ok'] === true, "$s $b");
$ov = $pdo->query('SELECT sound_id FROM user_sound_overrides WHERE user_id = 1 AND target_user_id = 2')->fetch(PDO::FETCH_ASSOC);
check('override persisted', $ov !== false && (int) $ov['sound_id'] === $soundId, json_encode($ov));
[$s] = req('POST', '/api/sound/override', ['csrf' => $tA, 'target_user_id' => '2', 'sound' => '0'], $cjA);
check('override to mute saved', $s === 200, (string) $s);
$ov = $pdo->query('SELECT sound_id FROM user_sound_overrides WHERE user_id = 1 AND target_user_id = 2')->fetch(PDO::FETCH_ASSOC);
check('override mute persisted (NULL sound)', $ov !== false && $ov['sound_id'] === null, json_encode($ov));
[$s] = req('POST', '/api/sound/override/remove', ['csrf' => $tA, 'target_user_id' => '2'], $cjA);
check('override removed', $s === 200, (string) $s);
check('override row gone', (int) $pdo->query('SELECT COUNT(*) FROM user_sound_overrides WHERE user_id = 1 AND target_user_id = 2')->fetchColumn() === 0);

// Background channel pings: bob posts in #general while alice is in #gaming.
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
req('POST', '/api/join', ['csrf' => $tB, 'name' => '#general'], $cjB);
$tB = csrf(req('GET', '/app?channel=general', [], $cjB)[2]);
[$s, , $b] = req('POST', '/api/send', ['csrf' => $tB, 'channel' => 'general', 'content' => 'bg alert message'], $cjB);
check('bob posts in #general', $s === 200 && jsonDecode($b)['ok'] === true, "$s $b");
[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0&bg_since=0', [], $cjA);
$j = jsonDecode($b);
$found = false;
foreach (($j['bg_messages'] ?? []) as $m) {
    if (($m['channel_slug'] ?? '') === 'general' && str_contains($m['content'] ?? '', 'bg alert')) $found = true;
}
check('poll bg_messages surfaces other-channel messages', $s === 200 && $found, $b);
[$s, , $b] = req('GET', '/api/poll?channel=general&since=0&bg_since=0', [], $cjA);
$j = jsonDecode($b);
$found = false;
foreach (($j['bg_messages'] ?? []) as $m) {
    if (($m['channel_slug'] ?? '') === 'general' && str_contains($m['content'] ?? '', 'bg alert')) $found = true;
}
check('open channel excluded from bg_messages', $s === 200 && !$found, $b);

// Clean up the uploaded test sound (removes row + file).
$tA = csrf(req('GET', '/admin/sounds', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'sound_del', 'id' => (string) $soundId, 'back' => '/admin/sounds'], $cjA);
check('admin deletes the uploaded sound', $s === 302 && (int) $pdo->query("SELECT COUNT(*) FROM sound_alerts WHERE id = $soundId")->fetchColumn() === 0, (string) $s);
check('deleted sound file removed from disk', !file_exists(dirname(__DIR__) . '/public' . $soundFile), $soundFile);

// ── Native (no-JS) form fallback ─────────────────────────────────────────────
echo "== native form fallback ==\n";
$tA = csrf(req('GET', '/app?channel=gaming', [], $cjA)[2]);
[$s, $h] = req('POST', '/api/send', ['csrf' => $tA, 'channel' => 'gaming', 'content' => 'native post', 'ajax' => '0'], $cjA);
check('native POST redirects back to channel', $s === 302 && str_contains($h['location'] ?? '', 'channel=gaming'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0', [], $cjA);
check('native POST delivered the message', $s === 200 && str_contains($b, 'native post'), $b);
[$s, $h] = req('POST', '/api/send', ['csrf' => $tA, 'channel' => 'gaming', 'content' => '/ping', 'ajax' => '0'], $cjA);
check('native slash command stays in the channel', $s === 302 && str_contains($h['location'] ?? '', 'channel=gaming'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/api/version', [], $cjA);
$j = jsonDecode($b);
$src = (string) file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
preg_match("/define\('LVC_VERSION', '([^']+)'\)/", $src, $m);
$expectedVersion = $m[1] ?? '';
check('/api/version matches source LVC_VERSION', $s === 200 && ($j['version'] ?? '') === $expectedVersion, $b);

// ── Moderation / reports / support / legal ───────────────────────────────────
echo "== moderation & reports ==\n";
[$s] = req('GET', '/terms');
check('/terms page 200', $s === 200, (string) $s);
[$s] = req('GET', '/privacy');
check('/privacy page 200', $s === 200, (string) $s);

// Staff guard: a regular user (carol) gets 403 from the moderation pages.
[$s] = req('GET', '/admin/moderation', [], $cjC);
check('regular user denied from moderation page', $s === 403, (string) $s);
[$s] = req('GET', '/admin/analytics', [], $cjC);
check('regular user denied from analytics page', $s === 403, (string) $s);
[$s] = req('GET', '/admin/analytics?range=90', [], $cjA);
check('admin opens analytics page with range', $s === 200, (string) $s);
[$s, , $b] = req('GET', '/admin/analytics', [], $cjA);
check('analytics page renders fully (no fatal, has content)', $s === 200 && stripos($b, 'fatal error') === false && strpos($b, 'Least active accounts') !== false, $s . ' ' . mb_substr($b, 0, 120));
[$s] = req('GET', '/admin/moderation', [], $cjA);
check('admin opens moderation page', $s === 200, (string) $s);
[$s] = req('GET', '/admin/reports', [], $cjA);
check('admin opens reports page', $s === 200, (string) $s);
[$s] = req('GET', '/admin/support', [], $cjA);
check('admin opens support page', $s === 200, (string) $s);
[$s] = req('GET', '/admin/legal', [], $cjA);
check('admin opens legal page (tiptap)', $s === 200, (string) $s);
$tA = csrf(req('GET', '/admin/legal', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'legal_save', 'terms' => '<h2>Custom terms</h2><script>alert(1)</script>', 'privacy' => '<p>Custom privacy</p>', 'back' => '/admin/legal'], $cjA);
check('legal save accepted', $s === 302, (string) $s);
[$s, , $b] = req('GET', '/terms');
check('legal save sanitizes + renders on public page', $s === 200 && strpos($b, 'Custom terms') !== false && strpos($b, 'alert(1)') === false, (string) $s);

// Bob joins #gaming so he can report a fresh message alice posts there.
$t = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $b] = req('POST', '/api/join', ['csrf' => $t, 'name' => '#gaming'], $cjB);
check('bob joins #gaming', $s === 200, "$s $b");

// ── Offline message delivery: a recipient who is offline when a DM or channel
// message is sent sees both the moment they next poll.
dbq("UPDATE users SET last_seen = datetime('now', '-1 hour') WHERE username = 'bob'");
[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf(req('GET', '/app', [], $cjA)[2]), 'recipient' => 'bob', 'content' => 'offline delivery dm'], $cjA);
check('alice sends a DM to an offline bob', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf(req('GET', '/app', [], $cjA)[2]), 'channel' => 'gaming', 'content' => 'offline delivery channel'], $cjA);
check('alice posts to a channel offline bob is in', $s === 200 && jsonDecode($b)['ok'] === true, $b);
// Bob comes online and polls — both messages arrive.
[$s, , $b] = req('GET', '/api/poll?dm=alice&since=0', [], $cjB);
$j = jsonDecode($b);
$dmGot = false;
foreach (($j['messages'] ?? []) as $m) {
    if (($m['content'] ?? '') === 'offline delivery dm') $dmGot = true;
}
check('offline DM delivered to bob on his next poll', $s === 200 && $dmGot, $b);
[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0', [], $cjB);
$j = jsonDecode($b);
$chGot = false;
foreach (($j['messages'] ?? []) as $m) {
    if (($m['content'] ?? '') === 'offline delivery channel') $chGot = true;
}
check('offline channel message delivered to bob on his next poll', $s === 200 && $chGot, $b);
dbq("UPDATE users SET last_seen = datetime('now') WHERE username = 'bob'");

[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf(req('GET', '/app', [], $cjA)[2]), 'channel' => 'gaming', 'content' => 'report me please'], $cjA);
$reportMsgId = jsonDecode($b)['message']['id'] ?? 0;
check('alice posts reportable message', $s === 200 && $reportMsgId > 0, "$s $b");
[$s, , $b] = req('POST', '/api/report', ['csrf' => $t, 'id' => (string) $reportMsgId, 'pm' => '0', 'reason' => 'Harassment / Bullying', 'other' => ''], $cjB);
check('report channel message', $s === 200 && str_contains(jsonDecode($b)['message'] ?? '', 'submitted'), "$s $b");
[$s, , $b] = req('POST', '/api/report', ['csrf' => $t, 'id' => (string) $reportMsgId, 'pm' => '0', 'reason' => 'Other', 'other' => ''], $cjB);
check('duplicate report rejected', $s === 409, "$s $b");
// A second user (carol) reports the same message with the custom "Other" reason.
$tC = csrf(req('GET', '/app', [], $cjC)[2]);
[$s] = req('POST', '/api/join', ['csrf' => $tC, 'name' => '#gaming'], $cjC);
check('carol joins #gaming to report', $s === 200, (string) $s);
[$s, , $b] = req('POST', '/api/report', ['csrf' => $tC, 'id' => (string) $reportMsgId, 'pm' => '0', 'reason' => 'Other', 'other' => 'custom detail'], $cjC);
check('report with custom reason accepted', $s === 200, "$s $b");
[$s, , $b] = req('POST', '/api/report', ['csrf' => $t, 'id' => '999999', 'pm' => '0', 'reason' => 'Spam or advertising', 'other' => ''], $cjB);
check('report of a missing message rejected', $s === 404, "$s $b");
$reports = dbq('SELECT * FROM reports ORDER BY id');
check('reports snapshotted into DB', count($reports) === 2 && ($reports[0]['content'] ?? '') === 'report me please' && ($reports[1]['reason_other'] ?? '') === 'custom detail', json_encode($reports));

// Reporting an image/GIF snapshots its kind so the admin view renders the
// media inline instead of showing the raw URL.
[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf(req('GET', '/app', [], $cjA)[2]), 'channel' => 'gaming', 'gif_url' => 'https://media.giphy.com/media/abc456/giphy.gif', 'gif_title' => 'reported gif'], $cjA);
$gifMsgId = jsonDecode($b)['message']['id'] ?? 0;
check('alice posts gif for reporting', $s === 200 && $gifMsgId > 0, "$s $b");
[$s, , $b] = req('POST', '/api/report', ['csrf' => $t, 'id' => (string) $gifMsgId, 'pm' => '0', 'reason' => 'Spam or advertising', 'other' => ''], $cjB);
check('report gif message', $s === 200, "$s $b");
$repKind = (dbq('SELECT kind FROM reports WHERE message_id = ?', [$gifMsgId])[0]['kind'] ?? '');
check('report snapshots kind gif', $repKind === 'gif', $repKind);
[$s, , $b] = req('GET', '/admin/reports', [], $cjA);
check('admin reports renders gif inline', $s === 200 && strpos($b, '<img') !== false && strpos($b, 'media.giphy.com/media/abc456/giphy.gif') !== false, (string) $s);

$tA = csrf(req('GET', '/admin/reports', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'report_status', 'id' => '1', 'status' => 'resolved', 'resolution' => 'Warned the user', 'back' => '/admin/reports'], $cjA);
check('admin resolves report', $s === 302, (string) $s);
check('report status updated + note written', (dbq('SELECT status FROM reports WHERE id = 1')[0]['status'] ?? '') === 'resolved'
    && count(dbq('SELECT 1 FROM user_notes WHERE action = "report"')) === 1);

// Age gate: guest join without the 18+ certification is rejected.
$cjMin = '/tmp/opencode/httptest-min.txt';
$t = csrf(req('GET', '/login', [], $cjMin)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'minorguest', 'next' => '/app'], $cjMin);
check('guest without age certification rejected', $s === 302 && str_contains($h['location'] ?? '', '/login'), "$s " . ($h['location'] ?? ''));

// ── Kick: removes the member AND bounces them with the reason (poll mode) ────
echo "== kick ==\n";
$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/command', ['csrf' => $tA, 'channel' => 'gaming', 'text' => '/kick bob you so stinky'], $cjA);
check('/kick with reason succeeds', $s === 200 && str_contains(jsonDecode($b)['replies'][0] ?? '', 'Kicked bob'), "$s $b");
[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0', [], $cjB);
$kj = jsonDecode($b);
check('kicked user poll returns redirect', $s === 200 && !empty($kj['redirect']), "$s $b");
check('kick reason + actor surface to the target', str_contains($kj['reason'] ?? '', 'alice kicked you from #gaming') && str_contains($kj['reason'] ?? '', 'you so stinky'), json_encode($kj));
[$s, , $b] = req('GET', '/api/poll?channel=gaming&since=0', [], $cjB);
check('removal reason is one-shot (generic fallback on next poll)', (jsonDecode($b)['reason'] ?? '') === 'You were removed from #gaming.', $b);

// ── Channel-URL embed proxy (/api/embed) ─────────────────────────────────────
echo "== embed proxy ==\n";
$emb = 'http://127.0.0.1:8097';
[$s] = req('GET', '/api/embed?url=' . rawurlencode("$emb/framed"));
check('embed requires a session', $s === 403, (string) $s);
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode('not-a-url'), [], $cjA);
check('embed rejects non-http(s) URLs', $s === 400, "$s $b");
dbq("INSERT OR REPLACE INTO banned_urls (domain, reason) VALUES ('embedbanned.test', 'test')");
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode('https://embedbanned.test/'), [], $cjA);
check('embed rejects banned domains', $s === 403, "$s $b");
dbq("DELETE FROM banned_urls WHERE domain = 'embedbanned.test'");
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode('https://10.0.0.5/x'), [], $cjA);
check('embed rejects private addresses (SSRF guard)', $s === 403, "$s $b");
// A page that refuses to be framed still embeds: no X-Frame-Options leaks
// through, and the body gains the base href + click-catcher.
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode("$emb/framed"), [], $cjA);
check('embed strips X-Frame-Options and serves framed page', $s === 200 && str_contains($b, '<h1>framed</h1>') && !isset($h['x-frame-options']), "$s");
check('embed injects base href + click catcher', str_contains($b, '<base href="http://127.0.0.1:8097/framed">') && str_contains($b, '/api/embed'), '');
// CSP frame-ancestors is dropped (kept CSP is fine, but the frame block must go).
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode("$emb/csp"), [], $cjA);
check('embed serves CSP page and strips its frame block', $s === 200 && str_contains($b, 'csp-frame-ancestors-none'), "$s");
// Relative resources keep resolving to the target site (injected <base> wins).
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode("$emb/rel"), [], $cjA);
check('embed replaces the page <base> with the target base', $s === 200 && substr_count(strtolower($b), '<base') === 1 && str_contains($b, '<base href="http://127.0.0.1:8097/rel">') && !str_contains($b, 'evil.example'), '');
// Server-side redirects are followed, so the final page is what gets embedded.
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode("$emb/redirect"), [], $cjA);
check('embed follows redirects server-side', $s === 200 && str_contains($b, '<h1>plain</h1>') && str_contains($b, '<base href="http://127.0.0.1:8097/plain">'), "$s $b");
// Non-HTML payloads pass through untouched.
[$s, $h, $b] = req('GET', '/api/embed?url=' . rawurlencode("$emb/img.png"), [], $cjA);
check('embed passes non-HTML payloads through', $s === 200 && str_starts_with($h['content-type'] ?? '', 'image/png'), ($h['content-type'] ?? ''));

// ── Support tickets (HTTP) ───────────────────────────────────────────────────
echo "== support tickets ==\n";
$t = csrf(req('GET', '/support', [], $cjB)[2]);
[$s, $h] = req('POST', '/support', ['csrf' => $t, 'subject' => 'Help with reporting', 'content' => 'I found a problem.'], $cjB);
check('support ticket created', $s === 302 && str_contains($h['location'] ?? '', '/support/'), "$s " . ($h['location'] ?? ''));
$ticketId = (int) basename($h['location'] ?? '0');
$t = csrf(req('GET', '/support/' . $ticketId, [], $cjA)[2]);
[$s, $h] = req('POST', '/support/' . $ticketId . '/reply', ['csrf' => $t, 'content' => 'We are on it.'], $cjA);
check('staff replies to ticket', $s === 302 && str_contains($h['location'] ?? '', '/support/' . $ticketId), "$s " . ($h['location'] ?? ''));
check('ticket marked answered', (dbq('SELECT status FROM support_tickets WHERE id = ?', [$ticketId])[0]['status'] ?? '') === 'answered');
[$s, , $b] = req('GET', '/admin/support', [], $cjA);
check('admin support list renders', $s === 200 && strpos($b, 'Help with reporting') !== false, (string) $s);

// ── Pending approval + suspension (HTTP) ─────────────────────────────────────
echo "== pending & suspended ==\n";
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '1', 'registration_requires_approval' => '1', 'back' => '/admin/settings'], $cjA);
check('approval toggle saved', $s === 302, (string) $s);
$cjP = '/tmp/opencode/httptest-p.txt';
$t = csrf(req('GET', '/register', [], $cjP)[2]);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => 'penny', 'email' => 'penny@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjP);
check('pending registration logs in (redirect /app)', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/app', [], $cjP);
check('pending banner shown in chat', $s === 200 && strpos($b, 'pending admin approval') !== false, (string) $s);
[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf($b), 'channel' => 'gaming', 'content' => 'hi'], $cjP);
check('pending user cannot chat (403)', $s === 403 && strpos($b, 'pending') !== false, "$s $b");
$penny = dbq('SELECT * FROM users WHERE username = "penny"')[0] ?? null;
check('penny is pending in DB', ($penny['status'] ?? '') === 'pending');
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_approve', 'id' => (string) $penny['id'], 'back' => '/admin/users'], $cjA);
check('admin approves penny', $s === 302, (string) $s);
check('approval clears status + writes note', (dbq('SELECT status FROM users WHERE id = ?', [$penny['id']])[0]['status'] ?? '') === 'active'
    && count(dbq('SELECT 1 FROM user_notes WHERE user_id = ? AND action = "approve"', [$penny['id']])) === 1);
$t = csrf(req('GET', '/app', [], $cjP)[2]);
[$s] = req('POST', '/api/join', ['csrf' => $t, 'name' => '#gaming'], $cjP);
check('approved penny can join channels', $s === 200, (string) $s);
[$s, , $b] = req('POST', '/api/send', ['csrf' => csrf(req('GET', '/app', [], $cjP)[2]), 'channel' => 'gaming', 'content' => 'now i can chat'], $cjP);
check('approved penny can chat', $s === 200, "$s $b");

// Suspend penny: her session is killed and login is blocked.
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_suspend', 'id' => (string) $penny['id'], 'reason' => 'age verification', 'back' => '/admin/users'], $cjA);
check('admin suspends penny', $s === 302, (string) $s);
[$s, $h] = req('GET', '/app', [], $cjP);
check('suspended session is dead (redirect to login)', $s === 302 && str_contains($h['location'] ?? '', '/login'), "$s " . ($h['location'] ?? ''));
$cjP2 = '/tmp/opencode/httptest-p2.txt';
$t = csrf(req('GET', '/login', [], $cjP2)[2]);
[$s, $h] = req('POST', '/login', ['csrf' => $t, 'username' => 'penny', 'password' => 'password123', 'next' => '/'], $cjP2);
check('suspended login blocked', $s === 302 && str_contains($h['location'] ?? '', '/login'), "$s " . ($h['location'] ?? ''));
check('suspended status has reason', (dbq('SELECT status, status_reason FROM users WHERE id = ?', [$penny['id']])[0]['status_reason'] ?? '') === 'age verification');
$tA = csrf(req('GET', '/admin/users', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'user_activate', 'id' => (string) $penny['id'], 'back' => '/admin/users'], $cjA);
check('admin activates penny', $s === 302, (string) $s);
// Reset the approval toggle so the rest of the suite behaves as before.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'registration_enabled' => '1', 'registration_requires_approval' => '0', 'back' => '/admin/settings'], $cjA);

// ── Anonymous guests ─────────────────────────────────────────────────────────
echo "== guests ==\n";
$cjG = '/tmp/opencode/httptest-g.txt';
$t = csrf(req('GET', '/login', [], $cjG)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'stranger', 'next' => '/app', 'age18' => '1'], $cjG);
check('guest login redirects', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));
[$s] = req('GET', '/app', [], $cjG);
check('guest can open /app', $s === 200, (string) $s);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => csrf(req('GET', '/app', [], $cjG)[2]), 'name' => '#guestroom'], $cjG);
check('guest cannot create a channel', $s === 400 && strpos($b, 'existing channels') !== false, "$s $b");
[$s, , $b] = req('POST', '/api/join', ['csrf' => csrf(req('GET', '/app', [], $cjG)[2]), 'name' => '#nonexistent'], $cjG);
check('guest cannot join-and-create via /api/join', $s === 400 && strpos($b, 'existing channels') !== false, "$s $b");
$cjG2 = '/tmp/opencode/httptest-g2.txt';
$t = csrf(req('GET', '/login', [], $cjG2)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'stranger', 'next' => '/app', 'age18' => '1'], $cjG2);
check('duplicate guest nick rejected (back to login)', $s === 302 && str_contains($h['location'] ?? '', '/login'), "$s " . ($h['location'] ?? ''));

// A guest who logs out frees the nick immediately; re-login reuses the row.
$cjG3 = '/tmp/opencode/httptest-g3.txt';
$t = csrf(req('GET', '/login', [], $cjG3)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'wanderer', 'next' => '/app', 'age18' => '1'], $cjG3);
check('guest wanderer logs in', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));
[$s] = req('POST', '/logout', ['csrf' => csrf(req('GET', '/app', [], $cjG3)[2])], $cjG3);
check('guest wanderer logs out', $s === 302, (string) $s);
$cjG4 = '/tmp/opencode/httptest-g4.txt';
$t = csrf(req('GET', '/login', [], $cjG4)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'wanderer', 'next' => '/app', 'age18' => '1'], $cjG4);
check('guest nick free after logout (re-login ok)', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));

// ── Themes (server appearance + per-user customization + channel bg) ────────
echo "== themes ==\n";
[$s, , $b] = req('GET', '/admin/theme', [], $cjA);
check('GET /admin/theme 200', $s === 200, (string) $s);
check('theme page has preset gallery + kill-switch', str_contains($b, 'preset-card') && str_contains($b, 'Allow users to customize'), 'len=' . strlen($b));

$tA = csrf($b);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'theme_save', 'preset' => 'nord', 'mode' => 'light', 'font' => 'serif', 'chat_bg_color' => '#123456', 'chat_bg_fit' => 'cover', 'chat_bg_overlay' => '80', 'chat_bg_image' => '', 'theme_user_customization' => '1', 'back' => '/admin/theme'], $cjA);
check('theme_save redirects', $s === 302, (string) $s);
$pdo = new PDO('sqlite:' . $DB);
$themeRow = json_decode((string) $pdo->query("SELECT value FROM server_config WHERE key = 'theme'")->fetchColumn(), true);
check('global theme persisted', ($themeRow['preset'] ?? '') === 'nord' && ($themeRow['mode'] ?? '') === 'light' && ($themeRow['overrides']['font'] ?? '') === 'serif' && ($themeRow['overrides']['chat_bg_color'] ?? '') === '#123456', json_encode($themeRow));
check('global theme overlay persisted', ($themeRow['overrides']['chat_bg_overlay'] ?? '') === 80, json_encode($themeRow));
check('customization flag on', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'theme_user_customization'")->fetchColumn() === '1');

// Live CSS preview endpoint.
[$s, , $b] = req('GET', '/api/theme/css?preset=nord&mode=dark&font=mono&accent=ff0000');
check('css preview 200 + theme vars', $s === 200 && str_contains($b, '--c-blurple:255 0 0') && str_contains($b, '--font-sans:') && str_contains($b, 'html.light'), $b);

// Regular user saves a personal theme that overrides the server theme.
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $j] = req('POST', '/api/theme', ['csrf' => $tB, 'preset' => 'dracula', 'mode' => 'dark', 'font' => 'mono', 'chat_bg_fit' => 'cover', 'chat_bg_image' => ''], $cjB);
check('user theme save ok', $s === 200 && !empty(jsonDecode($j)['ok']), "$s $j");
$bobId = (int) $pdo->query("SELECT id FROM users WHERE username = 'bob'")->fetchColumn();
$bobTheme = json_decode((string) $pdo->query('SELECT theme_json FROM users WHERE id = ' . $bobId)->fetchColumn(), true);
check('user theme persisted', ($bobTheme['preset'] ?? '') === 'dracula' && ($bobTheme['overrides']['font'] ?? '') === 'mono', json_encode($bobTheme));
[$s, , $b] = req('GET', '/app?channel=general', [], $cjB);
check('chat renders personal theme CSS', str_contains($b, '--c-d-800:40 42 54') && str_contains($b, 'id="theme-css"'), 'len=' . strlen($b));

// Personal chat-background upload + remove.
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, $body] = uploadReq('/api/theme/bg', $cjB, ['csrf' => $tB], ['tmp' => '/tmp/opencode/dmtest.png', 'type' => 'image/png', 'name' => 'bg.png']);
$j = jsonDecode($body);
check('theme bg upload ok', $s === 200 && !empty($j['url'] ?? ''), "$s $body");
$bobTheme = json_decode((string) $pdo->query('SELECT theme_json FROM users WHERE id = ' . $bobId)->fetchColumn(), true);
check('bg image stored in user theme', str_contains($bobTheme['overrides']['chat_bg_image'] ?? '', '/assets/themes/'), json_encode($bobTheme));
[$s] = req('POST', '/api/theme/bg/remove', ['csrf' => $tB], $cjB);
check('bg remove ok', $s === 200, (string) $s);
$bobTheme = json_decode((string) $pdo->query('SELECT theme_json FROM users WHERE id = ' . $bobId)->fetchColumn(), true);
check('bg image cleared from user theme', ($bobTheme['overrides']['chat_bg_image'] ?? '') === '', json_encode($bobTheme));

// Kill-switch: disabled customization blocks user saves and hides controls.
$tA = csrf(req('GET', '/admin/theme', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'theme_save', 'preset' => 'midnight', 'mode' => 'dark', 'theme_user_customization' => '0', 'back' => '/admin/theme'], $cjA);
check('kill-switch save redirects', $s === 302, (string) $s);
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $j] = req('POST', '/api/theme', ['csrf' => $tB, 'preset' => 'forest'], $cjB);
check('user theme blocked when disabled', $s === 403, "$s $j");
[$s, , $b] = req('GET', '/app?channel=general', [], $cjB);
check('kill-switch exposed to client', str_contains($b, 'data-theme-custom="0"'), 'len=' . strlen($b));
$tA = csrf(req('GET', '/admin/theme', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'theme_save', 'preset' => 'midnight', 'mode' => 'dark', 'theme_user_customization' => '1', 'back' => '/admin/theme'], $cjA);
check('customization re-enabled', $s === 302 && (string) $pdo->query("SELECT value FROM server_config WHERE key = 'theme_user_customization'")->fetchColumn() === '1');

// Channel background (owner only).
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $j] = req('POST', '/api/channels', ['csrf' => $tB, 'name' => '#bgtown'], $cjB);
check('bob creates bg channel', $s === 200, "$s $j");
$chanId = (int) $pdo->query("SELECT id FROM channels WHERE name = '#bgtown'")->fetchColumn();
check('bg channel exists', $chanId > 0, (string) $chanId);
check('channel bg fit defaults to contain', (string) $pdo->query("SELECT bg_fit FROM channels WHERE id = $chanId")->fetchColumn() === 'contain');
check('channel bg overlay defaults to 55', (int) $pdo->query("SELECT bg_overlay FROM channels WHERE id = $chanId")->fetchColumn() === 55);
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => '#abcdef'], $cjB);
check('owner sets channel bg colour', $s === 200, "$s $j");
check('channel bg colour persisted', (string) $pdo->query("SELECT bg_color FROM channels WHERE id = $chanId")->fetchColumn() === '#abcdef');
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => '#abcdef', 'bg_fit' => 'cover'], $cjB);
check('channel bg fit can be changed', $s === 200 && (jsonDecode($j)['bg_fit'] ?? '') === 'cover', "$s $j");
check('channel bg fit persisted', (string) $pdo->query("SELECT bg_fit FROM channels WHERE id = $chanId")->fetchColumn() === 'cover');
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => '#abcdef', 'bg_fit' => 'bogus'], $cjB);
check('invalid bg fit falls back to contain', $s === 200 && (jsonDecode($j)['bg_fit'] ?? '') === 'contain', "$s $j");
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => '#abcdef', 'bg_overlay' => '20'], $cjB);
check('channel bg overlay can be set', $s === 200 && (jsonDecode($j)['bg_overlay'] ?? '') === 20, "$s $j");
check('channel bg overlay persisted', (int) $pdo->query("SELECT bg_overlay FROM channels WHERE id = $chanId")->fetchColumn() === 20);
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => '#abcdef', 'bg_overlay' => '999'], $cjB);
check('invalid bg overlay falls back to default', $s === 200 && (jsonDecode($j)['bg_overlay'] ?? '') === 55, "$s $j");
[$s, $body] = uploadReq('/api/channel/bg', $cjB, ['csrf' => $tB, 'channel' => 'bgtown', 'bg_color' => ''], ['tmp' => '/tmp/opencode/dmtest.png', 'type' => 'image/png', 'name' => 'cbg.png']);
$j = jsonDecode($body);
check('owner uploads channel bg image', $s === 200 && str_contains($j['bg_image'] ?? '', '/assets/themes/'), "$s $body");
check('channel bg image persisted', str_contains((string) $pdo->query("SELECT bg_image FROM channels WHERE id = $chanId")->fetchColumn(), '/assets/themes/'));
$tA = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tA, 'channel' => 'bgtown', 'bg_color' => '#000000'], $cjA);
check('admin can set any channel bg', $s === 200, "$s $j");
// A non-owner, non-admin user is blocked.
$cjBG = '/tmp/opencode/httptest-bg.txt';
$t = csrf(req('GET', '/register', [], $cjBG)[2]);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => 'bguser', 'email' => 'bguser@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/app?channel=general'], $cjBG);
check('bguser registers', $s === 302, "$s " . ($h['location'] ?? ''));
$tBG = csrf(req('GET', '/app', [], $cjBG)[2]);
[$s, , $j] = req('POST', '/api/channel/bg', ['csrf' => $tBG, 'channel' => 'bgtown', 'bg_color' => '#000000'], $cjBG);
check('non-owner cannot set channel bg', $s === 403, "$s $j");
[$s, , $j] = req('POST', '/api/channel/bg/remove', ['csrf' => $tB, 'channel' => 'bgtown'], $cjB);
check('owner removes channel bg', $s === 200, "$s $j");
check('channel bg cleared', ($pdo->query("SELECT bg_color FROM channels WHERE id = $chanId")->fetchColumn() ?: '') === '' && ($pdo->query("SELECT bg_image FROM channels WHERE id = $chanId")->fetchColumn() ?: '') === '');
check('channel bg fit resets to contain on remove', (string) $pdo->query("SELECT bg_fit FROM channels WHERE id = $chanId")->fetchColumn() === 'contain');
check('channel bg overlay resets to default on remove', (int) $pdo->query("SELECT bg_overlay FROM channels WHERE id = $chanId")->fetchColumn() === 55);
// The browser's raw fetch sends the CSRF token only in the X-CSRF header (no
// POST field) — the server must accept it (that was the "save does nothing" bug).
$ch = curl_init($BASE . '/api/channel/bg');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_COOKIEFILE => $cjA,
    CURLOPT_COOKIEJAR => $cjA,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['channel' => 'bgtown', 'bg_color' => '#112233']),
    CURLOPT_HTTPHEADER => ['X-CSRF: ' . $tA],
]);
$raw = (string) curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('channel bg accepted via X-CSRF header only', $status === 200, (string) $status . ' ' . $raw);
check('header-only csrf wrote the bg', (string) $pdo->query("SELECT bg_color FROM channels WHERE id = $chanId")->fetchColumn() === '#112233');
[$s, , $j] = req('POST', '/api/channel/bg/remove', ['csrf' => $tB, 'channel' => 'bgtown'], $cjB);
check('owner removes channel bg again', $s === 200, "$s $j");

// ── Channel settings (bans / ops / topic / URL) + banned-URL list ────────────
echo "== channel settings ==\n";
$tA = csrf(req('GET', '/admin/urls', [], $cjA)[2]);
[$s, , $b] = req('GET', '/admin/urls', [], $cjA);
check('admin blocked-urls page 200', $s === 200, (string) $s);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_add', 'domain' => 'evilhttp.test', 'reason' => 'spam', 'back' => '/admin/urls'], $cjA);
check('banned_url_add redirects', $s === 302, (string) $s);
check('banned domain persisted', count(dbq("SELECT id FROM banned_urls WHERE domain = 'evilhttp.test'")) === 1);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_add', 'domain' => 'evilhttp.test', 'reason' => 'dup', 'back' => '/admin/urls'], $cjA);
check('duplicate banned domain rejected', count(dbq("SELECT id FROM banned_urls WHERE domain = 'evilhttp.test'")) === 1);
[$s, , $b] = req('GET', '/admin/urls', [], $cjA);
check('blocked-urls page lists domain', $s === 200 && str_contains($b, 'evilhttp.test'), (string) $s);
$banId = (int) $pdo->query("SELECT id FROM banned_urls WHERE domain = 'evilhttp.test'")->fetchColumn();
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_del', 'id' => $banId, 'back' => '/admin/urls'], $cjA);
check('banned_url_del removes', $s === 302 && count(dbq("SELECT id FROM banned_urls WHERE id = $banId")) === 0);

// A channel owned by bob with a normal member (bguser).
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $j] = req('POST', '/api/channels', ['csrf' => $tB, 'name' => '#sectown'], $cjB);
check('bob creates settings channel', $s === 200, "$s $j");
$tBG = csrf(req('GET', '/app', [], $cjBG)[2]);
[$s, , $j] = req('POST', '/api/join', ['csrf' => $tBG, 'name' => '#sectown'], $cjBG);
check('bguser joins settings channel', $s === 200, "$s $j");

// GET settings payload + permission surface.
$tB = csrf(req('GET', '/app', [], $cjB)[2]);
[$s, , $b] = req('GET', '/api/channel/settings?channel=sectown', [], $cjB);
$j = jsonDecode($b);
check('owner GET settings 200', $s === 200 && ($j['ok'] ?? false) === true, "$s $b");
check('settings payload shape', is_array($j['bans'] ?? null) && is_array($j['access'] ?? null) && isset($j['channel']['name']), $b);
check('owner can manage everything', ($j['can']['manage'] ?? false) === true && ($j['can']['bans'] ?? false) === true && ($j['can']['access'] ?? false) === true && ($j['can']['url'] ?? false) === true, $b);
[$s, , $b] = req('GET', '/api/channel/settings?channel=sectown', [], $cjBG);
$j = jsonDecode($b);
check('normal member GET settings 200', $s === 200, "$s $b");
check('normal member cannot manage', ($j['can']['manage'] ?? true) === false && ($j['can']['bans'] ?? true) === false && ($j['can']['access'] ?? true) === false && ($j['can']['url'] ?? true) === false, $b);
[$s] = req('GET', '/api/channel/settings?channel=no-such-channel', [], $cjB);
check('settings 404 for missing channel', $s === 404, (string) $s);
[$s] = req('GET', '/api/channel/settings?channel=general', []);
check('settings requires auth', $s === 401, (string) $s);

// URL management (ops+).
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'url_set', 'url' => 'https://good.example.org/page'], $cjB);
$j = jsonDecode($b);
check('owner sets channel url', $s === 200 && ($j['ok'] ?? false) === true && ($j['url'] ?? '') === 'https://good.example.org/page', "$s $b");
check('channel_url persisted', (string) $pdo->query("SELECT channel_url FROM channels WHERE name = '#sectown'")->fetchColumn() === 'https://good.example.org/page');
[$s, , $b] = req('GET', '/api/poll?channel=sectown&since=0', [], $cjB);
$j = jsonDecode($b);
check('poll carries channel_url', ($j['channel_url'] ?? '') === 'https://good.example.org/page', "$s $b");
// A domain banned after the fact hides the URL from clients.
$tA = csrf(req('GET', '/admin/urls', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_add', 'domain' => 'good.example.org', 'reason' => 'now banned', 'back' => '/admin/urls'], $cjA);
[$s, , $b] = req('GET', '/api/poll?channel=sectown&since=0', [], $cjB);
$j = jsonDecode($b);
check('banned domain hidden in poll', array_key_exists('channel_url', $j) && $j['channel_url'] === null && ($j['url_banned'] ?? false) === true, "$s $b");
[$s, , $b] = req('GET', '/api/channel/settings?channel=sectown', [], $cjB);
$j = jsonDecode($b);
check('settings flags banned url', array_key_exists('url', $j['channel']) && $j['channel']['url'] === null && ($j['channel']['url_banned'] ?? false) === true, $b);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_del', 'id' => (int) $pdo->query("SELECT id FROM banned_urls WHERE domain = 'good.example.org'")->fetchColumn(), 'back' => '/admin/urls'], $cjA);
// Re-ban the earlier domain so the subdomain rejection below has a ban to hit.
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'banned_url_add', 'domain' => 'evilhttp.test', 'reason' => 'spam', 'back' => '/admin/urls'], $cjA);
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'url_set', 'url' => 'https://sub.evilhttp.test/x'], $cjB);
$j = jsonDecode($b);
check('subdomain of banned domain rejected', $s === 400 && isset($j['error']), "$s $b");
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'url_set', 'url' => 'ftp://x.test'], $cjB);
check('non-http url rejected', $s === 400, "$s $b");
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'url_clear'], $cjB);
$j = jsonDecode($b);
check('owner clears channel url', $s === 200 && array_key_exists('url', $j) && $j['url'] === null, "$s $b");
check('channel_url cleared', (($pdo->query("SELECT channel_url FROM channels WHERE name = '#sectown'")->fetchColumn()) ?: '') === '');
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tBG, 'channel' => 'sectown', 'action' => 'url_set', 'url' => 'https://nope.test'], $cjBG);
check('normal member cannot set url', $s === 403, "$s $b");

// Bans (halfop+).
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'ban_add', 'mask' => 'spammer!*@*', 'reason' => 'testing', 'duration' => ''], $cjB);
$j = jsonDecode($b);
check('owner bans a mask', $s === 200 && ($j['ok'] ?? false) === true, "$s $b");
$banRow = dbq("SELECT id FROM bans WHERE kind = 'channel_ban' AND channel_id = (SELECT id FROM channels WHERE name = '#sectown') AND mask = 'spammer!*@*'");
check('ban persisted', count($banRow) === 1, json_encode($banRow));
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tBG, 'channel' => 'sectown', 'action' => 'ban_add', 'mask' => 'x!*@*'], $cjBG);
check('normal member cannot ban', $s === 403, "$s $b");
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'ban_del', 'id' => (int) $banRow[0]['id']], $cjB);
check('owner removes ban', $s === 200, "$s $b");

// Access list (ops+).
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'access_add', 'nick' => 'bguser', 'level' => 'halfop'], $cjB);
$j = jsonDecode($b);
check('owner adds access entry', $s === 200 && ($j['ok'] ?? false) === true, "$s $b");
$acc = dbq("SELECT level FROM channel_access WHERE channel_id = (SELECT id FROM channels WHERE name = '#sectown') AND user_id = (SELECT id FROM users WHERE username = 'bguser')");
check('access entry persisted', count($acc) === 1 && ($acc[0]['level'] ?? '') === 'halfop', json_encode($acc));
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tBG, 'channel' => 'sectown', 'action' => 'access_add', 'nick' => 'alice', 'level' => 'op'], $cjBG);
check('normal member cannot manage access', $s === 403, "$s $b");
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'access_del', 'nick' => 'bguser'], $cjB);
$j = jsonDecode($b);
check('owner removes access entry', $s === 200 && ($j['ok'] ?? false) === true, "$s $b");
check('access entry removed', count(dbq("SELECT id FROM channel_access WHERE channel_id = (SELECT id FROM channels WHERE name = '#sectown')")) === 0);

// Topic (respects +t).
[$s, , $b] = req('POST', '/api/channel/settings', ['csrf' => $tB, 'channel' => 'sectown', 'action' => 'topic_set', 'topic' => 'settings topic'], $cjB);
$j = jsonDecode($b);
check('owner sets topic via settings', $s === 200 && ($j['topic_set'] ?? '') === 'settings topic', "$s $b");
check('topic persisted', (string) $pdo->query("SELECT topic FROM channels WHERE name = '#sectown'")->fetchColumn() === 'settings topic');

// Push notifications API.
$tPush = csrf(req('GET', '/app', [], $cjA)[2]);
$pt = "\x04" . str_repeat("\x01", 64);
$p256 = rtrim(strtr(base64_encode($pt), '+/', '-_'), '=');
$authB64 = rtrim(strtr(base64_encode(str_repeat("\xAB", 16)), '+/', '-_'), '=');
$aliceId = (int) $pdo->query("SELECT id FROM users WHERE username = 'alice'")->fetchColumn();
$bobId = (int) $pdo->query("SELECT id FROM users WHERE username = 'bob'")->fetchColumn();
[$s, , $j] = req('POST', '/api/push/subscribe', ['csrf' => $tPush, 'endpoint' => 'https://127.0.0.1:59999/httptest', 'p256dh' => $p256, 'auth' => $authB64], $cjA);
check('push subscribe ok', $s === 200 && (jsonDecode($j)['ok'] ?? false) === true, "$s $j");
check('subscription persisted', (string) $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = $aliceId")->fetchColumn() === '1');
[$s, , $j] = req('POST', '/api/push/subscribe', ['csrf' => $tPush, 'endpoint' => 'http://127.0.0.1:59999/bad', 'p256dh' => $p256, 'auth' => $authB64], $cjA);
check('push subscribe rejects non-https', $s === 400, "$s $j");
[$s, , $j] = req('POST', '/api/push/subscribe', ['csrf' => $tPush, 'endpoint' => 'https://127.0.0.1:59999/c', 'p256dh' => 'nope', 'auth' => $authB64], $cjA);
check('push subscribe rejects bad p256dh', $s === 400, "$s $j");
[$s] = req('POST', '/api/push/subscribe', ['endpoint' => 'https://127.0.0.1:59999/d', 'p256dh' => $p256, 'auth' => $authB64], $cjA);
check('push subscribe requires CSRF', $s === 419, (string) $s);
[$s] = req('POST', '/api/push/subscribe', ['csrf' => 'x', 'endpoint' => 'https://127.0.0.1:59999/d', 'p256dh' => $p256, 'auth' => $authB64]);
check('push subscribe requires auth', $s === 401, (string) $s);
[$s, , $j] = req('POST', '/api/push/prefs', ['csrf' => $tPush, 'channels' => '0', 'dms' => '1', 'invites' => '1'], $cjA);
check('push prefs save', $s === 200 && (jsonDecode($j)['prefs']['channels'] ?? 1) === 0, "$s $j");
check('push prefs persisted', (string) $pdo->query("SELECT channels FROM user_push_prefs WHERE user_id = $aliceId")->fetchColumn() === '0');
req('POST', '/api/push/prefs', ['csrf' => $tPush, 'channels' => '1', 'dms' => '1', 'invites' => '1'], $cjA);
[$s, , $j] = req('POST', '/api/push/mute', ['csrf' => $tPush, 'user_id' => $bobId], $cjA);
check('push mute ok', $s === 200, "$s $j");
check('mute persisted', (string) $pdo->query("SELECT COUNT(*) FROM user_mutes WHERE user_id = $aliceId AND muted_user_id = $bobId")->fetchColumn() === '1');
[$s, , $j] = req('POST', '/api/push/mute', ['csrf' => $tPush, 'user_id' => 999999], $cjA);
check('push mute rejects unknown user', $s === 400, "$s $j");
[$s, , $j] = req('POST', '/api/push/mute', ['csrf' => $tPush, 'user_id' => $aliceId], $cjA);
check('push mute rejects self', $s === 400, "$s $j");
[$s, , $j] = req('POST', '/api/push/unmute', ['csrf' => $tPush, 'user_id' => $bobId], $cjA);
check('push unmute ok', $s === 200, "$s $j");
check('unmute cleared', (string) $pdo->query("SELECT COUNT(*) FROM user_mutes WHERE user_id = $aliceId AND muted_user_id = $bobId")->fetchColumn() === '0');
$page = req('GET', '/u/alice', [], $cjA)[2];
check('profile page renders push settings', str_contains($page, 'Push notifications'), '');
check('profile page embeds VAPID key', str_contains($page, 'VAPID_KEY'), '');
[$s, , $j] = req('POST', '/api/push/unsubscribe', ['csrf' => $tPush], $cjA);
check('push unsubscribe ok', $s === 200, "$s $j");
check('subscriptions cleared', (string) $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = $aliceId")->fetchColumn() === '0');

// Realtime force-WebSocket config: persist + expose to the chat page.
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'realtime' => 'ws', 'realtime_force' => '1', 'ws_port' => '8080', 'ws_ip' => '0.0.0.0', 'back' => '/admin/settings'], $cjA);
check('settings_save accepts realtime_force', $s === 302, (string) $s);
check('realtime_force persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'realtime_force'")->fetchColumn() === '1');
$page = req('GET', '/app', [], $cjA)[2];
check('chat page exposes data-rt-force', str_contains($page, 'data-rt="ws"') && str_contains($page, 'data-rt-force="1"'), '');
$forcePage = req('GET', '/admin/settings', [], $cjA)[2];
check('force checkbox reflects saved state', preg_match('/name="realtime_force"[^>]*checked/', $forcePage) === 1, '');
// WSS config: pointing the gateway at a cert+key flips the client URL to wss://.
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'realtime' => 'ws', 'realtime_force' => '0', 'ws_port' => '8080', 'ws_ip' => '0.0.0.0', 'ws_ssl_cert' => '/etc/ssl/chat.pem', 'ws_ssl_key' => '/etc/ssl/chat.key', 'back' => '/admin/settings'], $cjA);
check('settings_save accepts ws_ssl_*', $s === 302, (string) $s);
check('ws_ssl_cert persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'ws_ssl_cert'")->fetchColumn() === '/etc/ssl/chat.pem');
$page = req('GET', '/app', [], $cjA)[2];
check('chat page uses wss when TLS configured', str_contains($page, 'data-ws-url="wss://'), '');
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'realtime' => 'poll', 'realtime_force' => '0', 'ws_port' => '8080', 'ws_ip' => '0.0.0.0', 'ws_ssl_cert' => '', 'ws_ssl_key' => '', 'back' => '/admin/settings'], $cjA);
check('settings restore poll', $s === 302, (string) $s);

// Desktop app download config: persist + render into the chat's download modal.
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'registration_enabled' => '1', 'download_desktop_win_url' => 'https://example.com/LVChatSetup.exe', 'download_desktop_win_version' => '1.1.0', 'download_messenger_linux_deb_url' => 'https://example.com/messenger.deb', 'download_update_url' => 'https://example.com/downloads', 'back' => '/admin/settings'], $cjA);
check('settings_save accepts download links', $s === 302, (string) $s);
check('download win url persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'download_desktop_win_url'")->fetchColumn() === 'https://example.com/LVChatSetup.exe');
check('download win version persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'download_desktop_win_version'")->fetchColumn() === '1.1.0');
check('download update url persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'download_update_url'")->fetchColumn() === 'https://example.com/downloads');
$dlPage = req('GET', '/app', [], $cjA)[2];
check('chat download modal renders configured links', str_contains($dlPage, 'https://example.com/LVChatSetup.exe') && str_contains($dlPage, 'v1.1.0') && str_contains($dlPage, 'https://example.com/messenger.deb') && str_contains($dlPage, 'https://example.com/downloads'), '');
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'registration_enabled' => '1', 'download_desktop_win_url' => '', 'download_desktop_win_version' => '', 'download_messenger_linux_deb_url' => '', 'download_update_url' => '', 'back' => '/admin/settings'], $cjA);
check('settings clears download links', $s === 302, (string) $s);
$dlPage = req('GET', '/app', [], $cjA)[2];
check('chat download modal hides cleared links', !str_contains($dlPage, 'https://example.com/LVChatSetup.exe'), '');

// Web-messenger allowed origins (CORS): persist + normalize + emit headers.
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'app_origins' => 'https://msg.example.com/, https://msg.example.com,  https://app.example.com,ftp://bad.example.com,not-a-url', 'back' => '/admin/settings'], $cjA);
check('settings_save accepts app_origins', $s === 302, (string) $s);
check('app_origins normalized (deduped, slash-stripped, invalid dropped)', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'app_origins'")->fetchColumn() === 'https://msg.example.com, https://app.example.com', '');
[, $h] = req('GET', '/api/me', [], $cjA, ['Origin: https://msg.example.com']);
check('CORS emitted for allowlisted origin', ($h['access-control-allow-origin'] ?? '') === 'https://msg.example.com' && ($h['access-control-allow-credentials'] ?? '') === 'true', '');
[, $h] = req('GET', '/api/me', [], $cjA, ['Origin: https://evil.example.com']);
check('no CORS for non-allowlisted origin', !isset($h['access-control-allow-origin']), '');
$settingsPage = req('GET', '/admin/settings', [], $cjA)[2];
check('settings page renders allowed-origins field', str_contains($settingsPage, 'https://msg.example.com, https://app.example.com'), '');
[$s] = req('POST', '/admin/action', ['csrf' => $tPush, 'action' => 'settings_save', 'app_origins' => '', 'back' => '/admin/settings'], $cjA);
check('settings clears app_origins', $s === 302 && (string) $pdo->query("SELECT value FROM server_config WHERE key = 'app_origins'")->fetchColumn() === '', '');


// Realtime transport report accepts the forced-offline ('none') state.
[$s, , $j] = req('POST', '/api/rt/report', ['transport' => 'none'], $cjA, ['X-CSRF: ' . $tPush]);
check('rt report accepts none', $s === 200 && (jsonDecode($j)['ok'] ?? false) === true, "$s $j");
check('rt none persisted', (dbq("SELECT transport FROM rt_transports WHERE actor_id = $aliceId")[0]['transport'] ?? '') === 'none');

// Admin "reconnect all clients": trigger → next poll carries the reload flag.
[$s, , $j] = req('POST', '/admin/ws/reconnect', ['csrf' => $tPush], $cjA);
check('ws reconnect admin ok', $s === 200 && (jsonDecode($j)['reconnect'] ?? false) === true, "$s $j");
[$s] = req('POST', '/admin/ws/reconnect', ['csrf' => 'x'], $cjA);
check('ws reconnect requires valid csrf', $s === 419, (string) $s);
[$s] = req('POST', '/admin/ws/reconnect', ['csrf' => $tPush]);
check('ws reconnect requires auth', $s === 302, (string) $s);
[$s, , $j] = req('GET', '/api/poll?since=0', [], $cjA);
check('reconnect flag surfaces in poll', (jsonDecode($j)['reconnect'] ?? 0) === 1, $j);

// ── Update feed (updater) ────────────────────────────────────────────────────
echo "== updater ==\n";
// A tiny scratch manifest served by the built-in server (real static files).
$updDir = '/tmp/opencode/updtest';
@mkdir($updDir, 0777, true);
file_put_contents("$updDir/manifest.json", json_encode([
    'apps' => [
        'web' => ['version' => '9.9.9', 'url' => 'http://127.0.0.1:8099/web.zip', 'sha256' => '', 'notes' => 'https://example.com/web-notes'],
        'desktop' => ['version' => '0.5.0', 'notes' => '', 'platforms' => [
            'win' => ['url' => 'http://127.0.0.1:8099/desktop-win.exe', 'sha256' => 'aa', 'sha512' => '', 'size' => '1'],
            'mac' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_deb' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_rpm' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_appimage' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
        ]],
        'messenger' => ['version' => '0.3.0', 'notes' => '', 'platforms' => [
            'win' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'mac' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_deb' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_rpm' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
            'linux_appimage' => ['url' => '', 'sha256' => '', 'sha512' => '', 'size' => ''],
        ]],
    ],
], JSON_UNESCAPED_SLASHES));
$updSrv = proc_open(
    ['php', '-S', '127.0.0.1:8099', '-t', $updDir],
    [0 => ['pipe', 'r'], 1 => ['file', '/tmp/opencode/updtest-server.log', 'w'], 2 => ['file', '/tmp/opencode/updtest-server.log', 'a']],
    $updPipes
);
sleep(1);
$updCache = __DIR__ . '/../data/cache/updater-manifest.json';
@unlink($updCache);

[$s, , $j] = req('GET', '/api/version', [], $cjA);
check('api/version has updater_url when disabled = empty', ($s === 200) && (jsonDecode($j)['updater_url'] ?? 'x') === '', $j);

// Enable the feed via the settings form.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
[$s] = req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'updater_enabled' => '1', 'updater_url' => 'http://127.0.0.1:8099', 'back' => '/admin/settings'], $cjA);
check('settings_save stores updater config', $s === 302, (string) $s);
check('updater url persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'updater_url'")->fetchColumn() === 'http://127.0.0.1:8099', '');
check('updater enabled persisted', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'updater_enabled'")->fetchColumn() === '1', '');

[$s, , $b] = req('GET', '/api/version', [], $cjA);
check('api/version advertises updater_url', ($s === 200) && (jsonDecode($b)['updater_url'] ?? '') === 'http://127.0.0.1:8099', $b);

[$s, , $b] = req('GET', '/api/updater', [], $cjA);
$upd = jsonDecode($b);
check('api/updater resolves upstream web version', ($s === 200) && ($upd['apps']['web']['latest'] ?? '') === '9.9.9' && ($upd['apps']['web']['update_available'] ?? null) === true, $b);
check('api/updater resolves upstream desktop platform', ($upd['apps']['desktop']['latest'] ?? '') === '0.5.0' && ($upd['apps']['desktop']['platforms']['win']['url'] ?? '') === 'http://127.0.0.1:8099/desktop-win.exe', $b);
check('api/updater public (no auth)', $s === 200, (string) $s);

// Chat download modal falls back to the upstream feed when no custom URL set.
[$s, , $dlPage] = req('GET', '/app', [], $cjA);
check('chat modal shows upstream win link', str_contains($dlPage, 'http://127.0.0.1:8099/desktop-win.exe') && str_contains($dlPage, 'v0.5.0'), '');

// Admin Updates page renders status + checks feed.
[$s, , $b] = req('GET', '/admin/updates', [], $cjA);
check('GET /admin/updates 200', $s === 200, (string) $s);
check('updates page flags the web update', str_contains($b, '9.9.9') && str_contains($b, 'Update available'), '');
[$s] = req('GET', '/admin/updates', [], $cjB);
check('non-admin denied /admin/updates', $s === 403, (string) $s);

// Force-refresh endpoint requires CSRF + admin.
[$s] = req('POST', '/admin/updates/check', ['csrf' => 'bogus'], $cjA);
check('updates/check requires valid csrf', $s === 419, (string) $s);
$tA = csrf(req('GET', '/admin/updates', [], $cjA)[2]);
[$s] = req('POST', '/admin/updates/check', ['csrf' => $tA], $cjA);
check('updates/check refreshes feed', $s === 302, (string) $s);

// Pin upstream → custom fields for desktop.
$tA = csrf(req('GET', '/admin/updates', [], $cjA)[2]);
[$s] = req('POST', '/admin/updates/pin', ['csrf' => $tA, 'app' => 'desktop'], $cjA);
check('updates/pin copies upstream into custom fields', $s === 302, (string) $s);
check('pinned win url stored', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'download_desktop_win_url'")->fetchColumn() === 'http://127.0.0.1:8099/desktop-win.exe', '');
check('pinned win version stored', (string) $pdo->query("SELECT value FROM server_config WHERE key = 'download_desktop_win_version'")->fetchColumn() === '0.5.0', '');
[$s] = req('POST', '/admin/updates/pin', ['csrf' => 'bogus', 'app' => 'desktop'], $cjA);
check('updates/pin requires valid csrf', $s === 419, (string) $s);

// Web update download is admin+csrf gated (full sha256 flow exercised in CLI tests).
[$s] = req('POST', '/admin/updates/download-web', ['csrf' => 'bogus'], $cjA);
check('download-web requires valid csrf', $s === 419, (string) $s);
[$s] = req('POST', '/admin/updates/download-web', ['csrf' => $tA]);
check('download-web requires auth', $s === 302, (string) $s);

// Reset the feed so the rest of the suite sees the default behavior.
$tA = csrf(req('GET', '/admin/settings', [], $cjA)[2]);
req('POST', '/admin/action', ['csrf' => $tA, 'action' => 'settings_save', 'updater_enabled' => '0', 'updater_url' => '', 'back' => '/admin/settings'], $cjA);
@unlink($updCache);
if (is_resource($updSrv)) proc_terminate($updSrv);

// logout
[$s, $h] = req('POST', '/logout', ['csrf' => csrf(req('GET', '/app', [], $cjA)[2])], $cjA);
check('logout redirects', $s === 302 && ($h['location'] ?? '') === '/login', "$s " . ($h['location'] ?? ''));

// SSE stream (last: the stream holds the single-threaded dev server, so any
// follow-up request would hang). Bob is still logged in.
$sseBody = (string) shell_exec(
    'timeout 4 curl -s -N --max-time 4 -b ' . escapeshellarg($cjB) . ' ' . escapeshellarg($BASE . '/api/stream?since=0') . ' 2>/dev/null'
);
check('SSE stream emits data frames', str_contains($sseBody, 'data:'), $sseBody);

proc_terminate($server);
echo "\n" . $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);

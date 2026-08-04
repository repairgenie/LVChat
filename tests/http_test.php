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
    array_merge($_ENV, ['CHAT_DB' => $DB])
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

$cjA = '/tmp/opencode/httptest-a.txt';
$page = req('GET', '/register', [], $cjA)[2];
$t = csrf($page);
[$s, $h, $b] = req('POST', '/register', ['csrf' => $t, 'username' => 'alice', 'email' => 'alice@x.com', 'password' => 'password123', 'age18' => '1', 'next' => '/'], $cjA);
check('register alice 302', $s === 302, (string) $s);
check('first user redirected to next', ($h['location'] ?? '') === '/');
[$s] = req('GET', '/app', [], $cjA);
check('alice can open /app', $s === 200, (string) $s);

// share link: logged-in user auto-joins via /c/slug
[$s, $h] = req('GET', '/c/general', [], $cjA);
check('/c/general redirects to chat', $s === 302 && str_contains($h['location'] ?? '', '/app?channel=general'), "$s " . ($h['location'] ?? ''));

// logged-out user sees login redirect with next
[$s, $h] = req('GET', '/c/general');
check('logged-out share link -> /login?next=', $s === 302 && str_contains($h['location'] ?? '', '/login?next='), "$s " . ($h['location'] ?? ''));

// CSRF enforcement
[$s, , $b] = req('POST', '/api/send', ['channel' => 'general', 'content' => 'x'], $cjA);
check('POST without CSRF rejected (419)', $s === 419, (string) $s);

// ── Channel + messaging API ──────────────────────────────────────────────────
echo "== channel + messaging ==\n";
$t = csrf(req('GET', '/app', [], $cjA)[2]);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => $t, 'name' => '#gaming'], $cjA);
check('create #gaming', $s === 200 && jsonDecode($b)['ok'] === true, $b);
[$s] = req('GET', '/app?channel=gaming', [], $cjA);
check('/app?channel=gaming 200', $s === 200, (string) $s);

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

// ── Admin actions ────────────────────────────────────────────────────────────
echo "== admin ==\n";
foreach (['/admin', '/admin/analytics', '/admin/users', '/admin/channels', '/admin/bans', '/admin/spamfilters', '/admin/motd', '/admin/sounds', '/admin/logs', '/admin/settings', '/admin/webhooks', '/admin/support'] as $p) {
    [$s] = req('GET', $p, [], $cjA);
    check("GET $p 200", $s === 200, (string) $s);
}
[$s] = req('GET', '/admin', [], $cjB);
check('non-admin denied /admin', $s === 403, (string) $s);

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
check('browse page shows online + peak stats', $s === 200 && strpos($b, 'Online now') !== false && strpos($b, 'Peak users ever') !== false, $b);

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

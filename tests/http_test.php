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

function jsonDecode(string $body): array
{
    $j = json_decode($body, true);
    return is_array($j) ? $j : ['_raw' => $body];
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
[$s, $h, $b] = req('POST', '/register', ['csrf' => $t, 'username' => 'alice', 'email' => 'alice@x.com', 'password' => 'password123', 'next' => '/'], $cjA);
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
[$s, , $b] = req('POST', '/register', ['csrf' => $t, 'username' => 'bob', 'email' => 'bob@x.com', 'password' => 'password123', 'next' => '/'], $cjB);
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
foreach (['/admin', '/admin/users', '/admin/channels', '/admin/bans', '/admin/spamfilters', '/admin/motd', '/admin/logs', '/admin/settings', '/admin/webhooks'] as $p) {
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
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'invite' => $invToken, 'username' => 'erin', 'email' => 'invited@x.com', 'password' => 'password123', 'next' => '/'], $cjE);
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
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'username' => 'frank2', 'email' => 'frank2@x.com', 'password' => 'password123', 'next' => '/'], $cjF);
check('plain register blocked when registration closed', $s === 302 && str_contains($h['location'] ?? '', '/register'), "$s " . ($h['location'] ?? ''));
[$s, , $b] = req('GET', '/register?invite=' . $invToken2, [], $cjF);
$t = csrf($b);
[$s, $h] = req('POST', '/register', ['csrf' => $t, 'invite' => $invToken2, 'username' => 'frank', 'email' => 'invited2@x.com', 'password' => 'password123', 'next' => '/'], $cjF);
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
req('POST', '/register', ['csrf' => $t, 'username' => 'dave', 'email' => 'dave@x.com', 'password' => 'password123', 'next' => '/'], $cjD);
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
req('POST', '/register', ['csrf' => $t, 'username' => 'carol', 'email' => 'carol@x.com', 'password' => 'password123', 'next' => '/'], $cjC);
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

// ── Anonymous guests ─────────────────────────────────────────────────────────
echo "== guests ==\n";
$cjG = '/tmp/opencode/httptest-g.txt';
$t = csrf(req('GET', '/login', [], $cjG)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'stranger', 'next' => '/app'], $cjG);
check('guest login redirects', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));
[$s] = req('GET', '/app', [], $cjG);
check('guest can open /app', $s === 200, (string) $s);
[$s, , $b] = req('POST', '/api/channels', ['csrf' => csrf(req('GET', '/app', [], $cjG)[2]), 'name' => '#guestroom'], $cjG);
check('guest cannot create a channel', $s === 400 && strpos($b, 'existing channels') !== false, "$s $b");
[$s, , $b] = req('POST', '/api/join', ['csrf' => csrf(req('GET', '/app', [], $cjG)[2]), 'name' => '#nonexistent'], $cjG);
check('guest cannot join-and-create via /api/join', $s === 400 && strpos($b, 'existing channels') !== false, "$s $b");
$cjG2 = '/tmp/opencode/httptest-g2.txt';
$t = csrf(req('GET', '/login', [], $cjG2)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'stranger', 'next' => '/app'], $cjG2);
check('duplicate guest nick rejected (back to login)', $s === 302 && str_contains($h['location'] ?? '', '/login'), "$s " . ($h['location'] ?? ''));

// A guest who logs out frees the nick immediately; re-login reuses the row.
$cjG3 = '/tmp/opencode/httptest-g3.txt';
$t = csrf(req('GET', '/login', [], $cjG3)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'wanderer', 'next' => '/app'], $cjG3);
check('guest wanderer logs in', $s === 302 && ($h['location'] ?? '') === '/app', "$s " . ($h['location'] ?? ''));
[$s] = req('POST', '/logout', ['csrf' => csrf(req('GET', '/app', [], $cjG3)[2])], $cjG3);
check('guest wanderer logs out', $s === 302, (string) $s);
$cjG4 = '/tmp/opencode/httptest-g4.txt';
$t = csrf(req('GET', '/login', [], $cjG4)[2]);
[$s, $h] = req('POST', '/guest', ['csrf' => $t, 'nick' => 'wanderer', 'next' => '/app'], $cjG4);
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

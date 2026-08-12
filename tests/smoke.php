<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

// Smoke test: exercises the core flows against a scratch SQLite DB.
// Usage: CHAT_DB=/tmp/opencode/smoke.db php tests/smoke.php

putenv('CHAT_DB=' . (getenv('CHAT_DB') ?: '/tmp/opencode/chat-smoke.db'));
if (file_exists(getenv('CHAT_DB'))) {
    unlink(getenv('CHAT_DB'));
    @unlink(getenv('CHAT_DB') . '-wal');
    @unlink(getenv('CHAT_DB') . '-shm');
}

// Modules load from the fixture directory so the smoke suite can assert the
// loader behavior deterministically (see the "modules" section at the end).
putenv('CHAT_MODULES=' . __DIR__ . '/fixtures/modules');

// The license algorithm tests mint their own Ed25519 keypair; expose its public
// half to LicenseKeys via the env override (see docs/protocol/licensing.md). The paid-mod
// fixture boots with no key, so boot-time validation never touches the codec.
$__testSeed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
$__testKp = sodium_crypto_sign_seed_keypair($__testSeed);
putenv('LVC_LICENSE_PUBLIC_KEY=' . base64_encode(sodium_crypto_sign_publickey($__testKp)));
$GLOBALS['license_test_sk'] = sodium_crypto_sign_secretkey($__testKp);

require dirname(__DIR__) . '/src/bootstrap.php';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['passed']++;
        echo "  ok  $label\n";
    } else {
        $GLOBALS['failed']++;
        echo "FAIL  $label  $detail\n";
    }
}

// --- Registration ---
echo "== registration ==\n";
$r = Auth::register('alice', 'alice@example.com', 'password123', true);
check('register alice', $r['ok'] === true, json_encode($r));
$aliceRow = Auth::attempt('alice', 'password123');
check('first registered user becomes admin', ($aliceRow['role'] ?? '') === 'admin', json_encode($aliceRow));
$r = Auth::register('bob', 'bob@example.com', 'password123', true);
check('register bob', $r['ok'] === true, json_encode($r));
$bobRow = Auth::attempt('bob', 'password123');
check('second user stays a regular user', ($bobRow['role'] ?? '') === 'user', json_encode($bobRow));
$r = Auth::register('alice', 'alice2@example.com', 'password123', true);
check('duplicate username rejected', $r['ok'] === false, json_encode($r));

// --- Login ---
echo "== login ==\n";
$alice = Auth::attempt('alice', 'password123');
check('alice logs in', $alice !== null);
$bob = Auth::attempt('bob', 'password123');
check('bob logs in', $bob !== null);
check('wrong password rejected', Auth::attempt('alice', 'wrong') === null);
Auth::login($alice);
check('session token set', !empty($_SESSION['token']));
check('Auth::user returns alice', (Auth::user()['username'] ?? '') === 'alice');

// --- Login rate limiting ---
echo "== login throttle ==\n";
Database::query('DELETE FROM login_attempts');
check('login attempts start at 0', login_attempt_count() === 0);
for ($i = 0; $i < login_attempt_max(); $i++) {
    login_attempt_record();
}
check('attempts counted up to max', login_attempt_count() === login_attempt_max());
check('gate blocks over the limit', login_attempt_count() >= login_attempt_max());
login_attempt_clear();
check('clear resets attempts', login_attempt_count() === 0);

// --- TOTP / MFA ---
echo "== totp / mfa ==\n";
check('base32 encode', TotpService::base32Encode('abc') === 'MFRGG');
check('base32 roundtrip', TotpService::base32Decode('MFRGG') === 'abc');
$secret = TotpService::generateSecret();
check('secret is 32 base32 chars', preg_match('/^[A-Z2-7]{32}$/', $secret) === 1);
check('secret decodes to 20 bytes', strlen(TotpService::base32Decode($secret)) === 20);
// RFC 6238 Appendix B vectors (SHA-1, 6-digit).
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('rfc vector t=59', TotpService::code($rfcSecret, 59) === '287082', TotpService::code($rfcSecret, 59));
check('rfc vector t=1111111109', TotpService::code($rfcSecret, 1111111109) === '081804', TotpService::code($rfcSecret, 1111111109));
check('rfc vector t=1234567890', TotpService::code($rfcSecret, 1234567890) === '005924', TotpService::code($rfcSecret, 1234567890));
check('verify accepts current code', TotpService::verify($secret, TotpService::code($secret)) === true);
check('verify rejects garbage', TotpService::verify($secret, 'abcdef') === false);
$stale = TotpService::code($secret, time() - 3600);
check('verify rejects stale code', TotpService::verify($secret, $stale) === false);
check('otpauth uri carries secret+issuer', str_contains(TotpService::otpauthUri($secret, 'alice', 'LVChat'), 'otpauth://totp/') && str_contains(TotpService::otpauthUri($secret, 'alice', 'LVChat'), 'secret=' . $secret));
check('mfa disabled by default', TotpService::enabled($bob) === false);
TotpService::enable((int) $bob['id'], $secret);
check('mfa enabled after enroll', TotpService::enabled(Database::row('SELECT * FROM users WHERE id = ?', [(int) $bob['id']])) === true);
check('requiredFor off by default', TotpService::requiredFor(Database::row('SELECT * FROM users WHERE id = ?', [(int) $bob['id']])) === false);
Database::query('UPDATE users SET role = "staff" WHERE id = ?', [(int) $alice['id']]);
config_set('mfa_require_staff', '1');
check('requiredFor true when class requires mfa', TotpService::requiredFor(Database::row('SELECT * FROM users WHERE id = ?', [(int) $alice['id']])) === true);
config_set('mfa_require_staff', '0');
Database::query('UPDATE users SET role = "admin" WHERE id = ?', [(int) $alice['id']]);
check('requiredFor false after unset', TotpService::requiredFor(Database::row('SELECT * FROM users WHERE id = ?', [(int) $alice['id']])) === false);
check('guests never require mfa', TotpService::requiredFor(['guest' => 1, 'role' => 'user']) === false);
TotpService::disable((int) $bob['id']);
check('mfa disable clears', TotpService::enabled(Database::row('SELECT * FROM users WHERE id = ?', [(int) $bob['id']])) === false);

// --- Channel creation + join ---
echo "== channels ==\n";
$ch = ChannelService::create($alice, '#test');
check('create #test', is_array($ch), is_string($ch) ? $ch : '');
check('creator is founder', (AccessService::effectiveLevel($ch['id'], (int) $alice['id'])) === 'founder');
$bad = ChannelService::create($alice, 'no-hash');
check('bare name auto-prefixed', is_array($bad) && ($bad['name'] ?? '') === '#no-hash', is_string($bad) ? $bad : '');
$bad2 = ChannelService::create($alice, '#has space');
check('invalid name rejected', is_string($bad2), is_array($bad2) ? 'created' : '');
check('duplicate channel rejected', is_string(ChannelService::create($alice, '#test')));

$st = ChannelService::joinStatus($ch, $bob);
check('bob can join public', $st['ok'] === true, $st['reason']);
ChannelService::join($ch, $bob);
check('bob is member', AccessService::member($ch['id'], (int) $bob['id']) !== null);

// --- Messaging ---
echo "== messaging ==\n";
$msg = MessageService::send((int) $ch['id'], $alice, 'hello world');
check('message inserted', $msg['id'] > 0 && $msg['content'] === 'hello world');
$hist = MessageService::history((int) $ch['id']);
check('history has message', in_array('hello world', array_column($hist, 'content'), true));
$before = count($hist);
MessageService::system((int) $ch['id'], 'join', 'alice has joined #test');
$hist2 = MessageService::history((int) $ch['id']);
check('system message appended', count($hist2) === $before + 1 && $hist2[count($hist2) - 1]['kind'] === 'join');
// Pagination: historyBefore returns only older messages.
$last = end($hist2);
check('historyBefore excludes newest', count(MessageService::historyBefore((int) $ch['id'], (int) $last['id'], 50)) === count($hist2) - 1);
$hb = MessageService::historyBefore((int) $ch['id'], (int) $last['id'], 50);
$asc = true;
for ($i = 1, $n = count($hb); $i < $n; $i++) { if ($hb[$i]['id'] <= $hb[$i - 1]['id']) $asc = false; }
check('historyBefore returns ascending order', $asc);
// Owners may edit their own messages within 5 minutes; admins may edit anything.
check('admin can edit a message', MessageService::edit((int) $msg['id'], 'hello edited', $alice) === true);
$msg2 = MessageService::send((int) $ch['id'], $bob, 'bob message');
check('owner can edit own message', MessageService::edit((int) $msg2['id'], 'bob edited', $bob) === true);
Auth::register('mallory', 'mallory@example.com', 'password123', true);
$mallory = Auth::attempt('mallory', 'password123');
check('non-owner cannot edit a message', is_string(MessageService::edit((int) $msg2['id'], 'hacked', $mallory)));
$edited = Database::row('SELECT edited_at FROM messages WHERE id = ?', [(int) $msg2['id']]);
check('owner edit marks edited_at', !empty($edited['edited_at']));

// --- Search ---
echo "== search ==\n";
$res = MessageService::searchChannels($alice, 'hello');
check('search finds channel message', in_array('hello world', array_column($res, 'content'), true) || in_array('hello edited', array_column($res, 'content'), true));
check('search result has channel slug', !empty($res[0]['channel_slug'] ?? null));
$res2 = MessageService::searchChannels($alice, 'zzzz-no-such-term');
check('search misses unknown term', count($res2) === 0);
check('snippet truncates around match', strpos(MessageService::snippet('the quick brown fox jumps', 'fox', 60), 'fox') !== false);
$res3 = MessageService::searchDm($alice, 'hello');
check('DM search returns results or empty array', is_array($res3));

// --- Image messages ---
echo "== image messages ==\n";
$img = MessageService::send((int) $ch['id'], $alice, '/uploads/abc123.jpg' . "\n" . 'a caption', 'image');
check('image message inserted', $img['kind'] === 'image');
check('image message has avatar key', array_key_exists('avatar', $img));

// --- GIF messages + Giphy service ---
echo "== gif ==\n";
$gif = MessageService::send((int) $ch['id'], $alice, 'https://media.giphy.com/media/abc123/giphy.gif' . "\n" . 'a dancing cat', 'gif');
check('gif message inserted', $gif['kind'] === 'gif' && str_contains((string) $gif['content'], 'media.giphy.com'));
$gifHist = MessageService::history((int) $ch['id']);
$gifInHist = false;
foreach ($gifHist as $m) { if ((int) $m['id'] === (int) $gif['id'] && $m['kind'] === 'gif') $gifInHist = true; }
check('gif message appears in history', $gifInHist);
$gifSearch = MessageService::searchChannels($alice, 'dancing');
check('gif searchable by title', in_array((int) $gif['id'], array_map(fn ($x) => (int) $x['id'], $gifSearch), true));
check('gif content renders img', str_contains(chat_content_html(['kind' => 'gif', 'content' => "https://media.giphy.com/media/abc/giphy.gif\ncaption"]), '<img'));
check('gif caption escaped', str_contains(chat_content_html(['kind' => 'gif', 'content' => "https://media.giphy.com/media/abc/giphy.gif\n<script>"]), '&lt;script&gt;'));
check('validMediaUrl accepts giphy media', GifService::validMediaUrl('https://media.giphy.com/media/x/giphy.gif'));
check('validMediaUrl accepts giphy media1', GifService::validMediaUrl('https://media1.giphy.com/media/x/giphy.gif'));
check('validMediaUrl accepts i.giphy', GifService::validMediaUrl('https://i.giphy.com/x.gif'));
check('validMediaUrl rejects http', !GifService::validMediaUrl('http://media.giphy.com/x.gif'));
check('validMediaUrl rejects foreign host', !GifService::validMediaUrl('https://evil.example/x.gif'));
check('validMediaUrl rejects non-url', !GifService::validMediaUrl('not a url'));
$fixture = [
    'data' => [
        ['id' => 'abc', 'title' => 'Cat GIF', 'url' => 'https://giphy.com/gifs/abc', 'images' => [
            'preview_gif' => ['url' => 'https://media.giphy.com/media/abc/preview.gif'],
            'downsized' => ['url' => 'https://media.giphy.com/media/abc/downsized.gif'],
        ]],
        ['id' => '', 'title' => 'skip me', 'images' => []],
    ],
];
$items = GifService::itemsFrom($fixture);
check('itemsFrom normalizes giphy payload', count($items) === 1);
check('item preview/url mapped', ($items[0]['preview'] ?? '') === 'https://media.giphy.com/media/abc/preview.gif' && ($items[0]['url'] ?? '') === 'https://media.giphy.com/media/abc/downsized.gif');
check('item carries provider', ($items[0]['provider'] ?? '') === 'giphy');
check('itemsFrom empty on malformed', GifService::itemsFrom([]) === []);

// --- Reactions ---
echo "== reactions ==\n";
$r = MessageService::toggleReaction((int) $msg['id'], $alice, '👍');
check('reaction added', is_array($r) && $r['added'] === true);
check('reaction count includes mine', $r['reactions']['rows'][0]['count'] === 1);
$r2 = MessageService::toggleReaction((int) $msg['id'], $alice, '👍');
check('reaction toggled off', is_array($r2) && $r2['added'] === false && count($r2['reactions']['rows']) === 0);
MessageService::toggleReaction((int) $msg['id'], $alice, '❤️');
$hydrated = MessageService::hydrateReactions(MessageService::history((int) $ch['id']), $alice);
$withReactions = null;
foreach ($hydrated as $m) { if ((int) $m['id'] === (int) $msg['id']) $withReactions = $m; }
check('hydrateReactions attaches reactions', $withReactions !== null && count($withReactions['reactions'] ?? []) > 0);
$bad = MessageService::toggleReaction(999999, $alice, '👍');
check('reaction on missing message rejected', is_string($bad));

// --- Rich text markup ---
echo "== rich text ==\n";
check('inline bold', str_contains(chat_markup_rich('**x**'), '<strong>x</strong>'));
check('inline italic', str_contains(chat_markup_rich('*x*'), '<em>x</em>'));
check('inline strike', str_contains(chat_markup_rich('~~x~~'), '<s>x</s>'));
check('blockquote', str_contains(chat_markup_rich("> hi"), '<blockquote'));
check('code fence', str_contains(chat_markup_rich("```\ncode here\n```"), '<pre'));
check('unordered list', str_contains(chat_markup_rich("- a\n- b"), '<ul'));
check('ordered list', str_contains(chat_markup_rich("1. a\n2. b"), '<ol'));
check('markup escapes html', !str_contains(chat_markup_rich('<script>alert(1)</script>'), '<script>'));

// --- Channel unread + mute prefs ---
echo "== channel unread + mute ==\n";
ChannelService::markRead((int) $ch['id'], $bob);
check('markRead sets watermark', (int) Database::scalar('SELECT last_read_id FROM channel_members WHERE channel_id = ? AND user_id = ?', [$ch['id'], $bob['id']]) > 0);
$msgU = MessageService::send((int) $ch['id'], $alice, 'unread marker for bob');
$unread = ChannelService::joinedChannelNames($bob);
$u = null;
foreach ($unread as $c) { if ((int) $c['id'] === (int) $ch['id']) $u = $c; }
check('unread badge counts new messages', is_array($u) && (int) $u['unread'] > 0);
ChannelService::markRead((int) $ch['id'], $bob);
$unread2 = ChannelService::joinedChannelNames($bob);
foreach ($unread2 as $c) { if ((int) $c['id'] === (int) $ch['id']) $u = $c; }
check('markRead clears unread badge', (int) $u['unread'] === 0);
check('notifyMode defaults to all', ChannelService::notifyMode((int) $ch['id'], $bob) === 'all');
ChannelService::setNotifyMode((int) $ch['id'], $bob, 'muted');
check('notifyMode respects mute', ChannelService::notifyMode((int) $ch['id'], $bob) === 'muted');
$beforeNotif = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
MessageService::send((int) $ch['id'], $alice, '@bob you are muted');
$afterNotif = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
check('muted user gets no mention notification', $afterNotif === $beforeNotif);
ChannelService::setNotifyMode((int) $ch['id'], $bob, 'all');
MessageService::send((int) $ch['id'], $alice, '@bob you are unmuted');
$afterNotif2 = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
check('unmuted user gets mention notification again', $afterNotif2 > $afterNotif);

// --- Push notifications ---
echo "== push notifications ==\n";
$pk = PushService::publicKey();
check('vapid public key provisioned (65-byte point)', strlen(PushService::b64uDecode($pk)) === 65);
check('vapid keypair idempotent', PushService::publicKey() === $pk);
check('base64url roundtrip', PushService::b64uDecode(PushService::b64uEncode("abc\x00\xff123")) === "abc\x00\xff123");
check('base64url decodes unpadded', PushService::b64uDecode('YQ') === 'a');
$point = "\x04" . str_repeat("\x01", 64); // 65-byte uncompressed point shape
$auth = str_repeat("\xAB", 16);

// RFC 8291 aes128gcm: encrypt then decrypt with the client's private key.
$ua = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$uaDet = openssl_pkey_get_details($ua);
$uaRaw = (string) substr(base64_decode((string) preg_replace('/\s+/', '', (string) preg_replace('/-----[A-Z ]+-----/', '', $uaDet['key']))), -65);
$enc = PushService::encryptPayload('{"type":"dm","title":"alice"}', $uaRaw, $auth);
check('encryptPayload returns aes128gcm body', is_string($enc) && strlen($enc) > 86, strlen((string) $enc) . '');
$serverPub = substr((string) $enc, 21, 65);
$shared = openssl_pkey_derive(
    "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $serverPub), 64) . "-----END PUBLIC KEY-----\n",
    $ua
);
$prk = hash_hmac('sha256', $shared, $auth, true);
$cek = PushService::hkdfExpand($prk, 'WebPush: info' . "\x00" . $uaRaw . $serverPub, 16);
$nonce = PushService::hkdfExpand($prk, 'Content-Encoding: nonce' . "\x00", 12);
$dec = openssl_decrypt(substr((string) $enc, 86, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr((string) $enc, -16), substr((string) $enc, 0, 86));
check('RFC 8291 encrypt/decrypt roundtrip', $dec === '{"type":"dm","title":"alice"}' . "\x02", bin2hex((string) $dec));

// Pure decision helpers.
check('channelDecision all delivers', PushService::channelDecision(1, false, 'all', 'hi', 'bob') === true);
check('channelDecision muted user blocks', PushService::channelDecision(1, true, 'all', 'hi', 'bob') === false);
check('channelDecision pref off blocks', PushService::channelDecision(0, false, 'all', 'hi', 'bob') === false);
check('channelDecision channel muted blocks', PushService::channelDecision(1, false, 'muted', 'hi', 'bob') === false);
check('channelDecision mentions non-match blocks', PushService::channelDecision(1, false, 'mentions', 'hi everyone', 'bob') === false);
check('channelDecision mentions match delivers', PushService::channelDecision(1, false, 'mentions', 'hi @Bob!', 'bob') === true);
check('dmDecision', PushService::dmDecision(1, false) === true && PushService::dmDecision(1, true) === false && PushService::dmDecision(0, false) === false);
check('inviteDecision', PushService::inviteDecision(1, false) === true && PushService::inviteDecision(0, false) === false && PushService::inviteDecision(1, true) === false);
check('mentioned matches nick case-insensitively', PushService::mentioned('hi @BOB', 'bob') === true && PushService::mentioned('hi @carol', 'bob') === false);

// Global per-context prefs (default all on).
$prefs = PushService::prefs($bob);
check('push prefs default all on', $prefs === ['channels' => 1, 'dms' => 1, 'invites' => 1]);
PushService::savePrefs($bob, 0, 1, 1);
check('push prefs channels off saved', PushService::prefs($bob)['channels'] === 0 && PushService::prefs($bob)['dms'] === 1);
PushService::savePrefs($bob, 1, 1, 1);
check('push prefs restored', PushService::prefs($bob) === ['channels' => 1, 'dms' => 1, 'invites' => 1]);

// Subscriptions.
check('subscribe ok', PushService::subscribe($bob, 'https://127.0.0.1:59999/a', PushService::b64uEncode($point), PushService::b64uEncode($auth)) === true);
check('subscribe rejects non-https endpoint', is_string(PushService::subscribe($bob, 'http://127.0.0.1:59999/b', PushService::b64uEncode($point), PushService::b64uEncode($auth))));
check('subscribe rejects bad p256dh', is_string(PushService::subscribe($bob, 'https://127.0.0.1:59999/c', 'not-a-key', PushService::b64uEncode($auth))));
check('subscribe rejects bad auth', is_string(PushService::subscribe($bob, 'https://127.0.0.1:59999/d', PushService::b64uEncode($point), 'short')));
PushService::subscribe($bob, 'https://127.0.0.1:59999/e', PushService::b64uEncode($point), PushService::b64uEncode($auth));
check('second device subscribed', (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$bob['id']]) === 2);
PushService::subscribe($alice, 'https://127.0.0.1:59999/a', PushService::b64uEncode($point), PushService::b64uEncode($auth));
check('endpoint re-registered to new user', (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$bob['id']]) === 1);
PushService::unsubscribe($bob);
check('unsubscribe removes rows', (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$bob['id']]) === 0);

// Per-user mutes (all surfaces).
PushService::addMute($bob, (int) $alice['id']);
check('isMuted true after mute', PushService::isMuted((int) $bob['id'], (int) $alice['id']) === true);
check('cannot mute self', is_string(PushService::addMute($bob, (int) $bob['id'])));
check('cannot mute unknown user', is_string(PushService::addMute($bob, 999999)));
$before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
$pmMute = MessageService::insertPm($alice, $bob, 'muted dm');
MessageService::notifyDm($bob, $alice, $pmMute);
check('muted sender gets no DM bell notification', (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]) === $before);
$before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
MessageService::send((int) $ch['id'], $alice, '@bob muted mention');
check('muted sender gets no mention notification', (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]) === $before);
$sums = MessageService::dmSummaries($bob);
$muteSum = null;
foreach ($sums as $s) { if ((int) $s['user_id'] === (int) $alice['id']) $muteSum = $s; }
check('dmSummaries flags muted partner', is_array($muteSum) && (int) $muteSum['muted'] === 1, json_encode($muteSum));
$sfx = SoundService::soundsForClient($bob);
check('muted user forced off in sound overrides', array_key_exists((int) $alice['id'], $sfx['overrides']) && $sfx['overrides'][(int) $alice['id']] === null);
PushService::removeMute($bob, (int) $alice['id']);
check('isMuted false after unmute', PushService::isMuted((int) $bob['id'], (int) $alice['id']) === false);
$sums = MessageService::dmSummaries($bob);
foreach ($sums as $s) { if ((int) $s['user_id'] === (int) $alice['id']) $muteSum = $s; }
check('dmSummaries clears muted flag', is_array($muteSum) && (int) $muteSum['muted'] === 0);
$sfx = SoundService::soundsForClient($bob);
check('unmute restores sound overrides', !array_key_exists((int) $alice['id'], $sfx['overrides']));
Database::query('DELETE FROM private_messages WHERE id = ?', [$pmMute]);

// Invite notifications + mute gating.
$before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
$inv = CommandParser::run('/invite bob #test', $alice, $ch);
check('/invite creates a bell notification', (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]) > $before, $inv['replies'][0] ?? '');
PushService::addMute($bob, (int) $alice['id']);
$before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]);
$inv = CommandParser::run('/invite bob #test', $alice, $ch);
check('muted inviter gets no invite notification', (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$bob['id']]) === $before);
PushService::removeMute($bob, (int) $alice['id']);

// send() keeps working with the push hook wired in (no subscriptions = no-op).
$m = MessageService::send((int) $ch['id'], $alice, 'push hook no-op');
check('send works with push hook', is_array($m) && (int) $m['id'] > 0);

// Guest senders still reach members' pushes (regression: a NULL sender_id used
// to filter everyone out of the channelMessage query). We observe the dispatch
// firing by the subscription's last_seen being bumped after a best-effort send.
// A real on-curve point is needed so the RFC 8291 encryption succeeds.
$realKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$realPoint = (string) substr(base64_decode((string) preg_replace('/\s+/', '', (string) preg_replace('/-----[A-Z ]+-----/', '', openssl_pkey_get_details($realKey)['key']))), -65);
check('real EC point for push test', strlen($realPoint) === 65);
PushService::subscribe($bob, 'https://127.0.0.1:59999/g', PushService::b64uEncode($realPoint), PushService::b64uEncode($auth));
Database::query('UPDATE push_subscriptions SET last_seen = NULL WHERE user_id = ?', [$bob['id']]);
$guestP = Auth::loginGuest('guest_push', true);
check('guest logs in for push test', $guestP !== null);
ChannelService::join($ch, $guestP);
$gm = MessageService::send((int) $ch['id'], $guestP, 'guest message reaches members');
$seen = Database::scalar('SELECT last_seen FROM push_subscriptions WHERE user_id = ?', [$bob['id']]);
check('guest message triggers push to a subscribed member', is_array($gm) && $seen !== null && $seen !== false, json_encode($seen));
PushService::unsubscribe($bob);

// --- Slash commands ---
echo "== slash commands ==\n";
$res = CommandParser::run('/help', $alice, $ch);
check('/help returns lines', count($res['replies']) > 5);
$res = CommandParser::run('/topic #test this is a topic', $alice, $ch);
check('/topic set', $res['replies'][0] === 'Topic set to: this is a topic', $res['replies'][0] ?? '');
check('/topic returns new topic for the header', ($res['topic_set'] ?? '') === 'this is a topic' && ($res['topic_channel'] ?? '') === 'test', json_encode($res));
$res = CommandParser::run('/topic #test', $alice, $ch);
check('/topic view has no header payload', !array_key_exists('topic_set', $res), json_encode($res));
$res = CommandParser::run('/whois bob', $alice, $ch);
check('/whois', count($res['replies']) >= 1);
$res = CommandParser::run('/kick bob test reason', $alice, $ch);
check('/kick bob', $res['replies'][0] === 'Kicked bob.', $res['replies'][0] ?? '');
check('bob no longer member', AccessService::member($ch['id'], (int) $bob['id']) === null);
$removal = Database::row('SELECT reason FROM member_removals WHERE user_id = ? AND channel_id = ? ORDER BY id DESC LIMIT 1', [$bob['id'], $ch['id']]);
check('kick records a one-shot removal reason', ($removal['reason'] ?? '') === 'alice kicked you from #test (test reason)', $removal['reason'] ?? '');
$res = CommandParser::run('/ban bob 1h test', $alice, $ch);
check('/ban bob', str_starts_with($res['replies'][0] ?? '', 'Banned bob'), $res['replies'][0] ?? '');
check('bob cannot rejoin', ChannelService::joinStatus($ch, $bob)['ok'] === false);
$res = CommandParser::run('/unban bob', $alice, $ch);
check('/unban bob', $res['replies'][0] === 'Removed ban for bob.', $res['replies'][0] ?? '');
check('bob can rejoin after unban', ChannelService::joinStatus($ch, $bob)['ok'] === true);
$res = CommandParser::run('/op alice', $bob, $ch);
check('normal user cannot /op', $res['replies'][0] !== 'alice now has level: op.');
$res = CommandParser::run('/op alice', $alice, $ch);
check('founder /op self denied (equal level)', isset($res['replies'][0]), $res['replies'][0] ?? '');
$res = CommandParser::run('/kick alice', $bob, $ch);
check('normal user cannot /kick', $res['replies'][0] === 'You do not have permission to use /kick in #test.', $res['replies'][0] ?? '');
$res = CommandParser::run('/mode #test +m', $alice, $ch);
check('/mode +m', $res['replies'][0] === 'Modes updated.');
$res = CommandParser::run('/me waves', $alice, $ch);
check('/me action', $res['replies'] === []);
$res = CommandParser::run('/nosuchcommand', $alice, $ch);
check('unknown command error', str_contains($res['replies'][0] ?? '', 'Unknown command'));

// --- Private messages ---
echo "== PMs ==\n";
$pm = CommandParser::run('/msg alice hey bob here', $bob, $ch);
check('/msg returns null (silent)', $pm['replies'] === []);
$dms = MessageService::forDm($alice, $bob);
check('alice sees bob PM', count($dms) === 1 && $dms[0]['content'] === 'hey bob here');
$res = CommandParser::run('/ignore alice', $bob, $ch);
check('/ignore alice', $res['replies'][0] === 'You have blocked alice.', $res['replies'][0] ?? '');
$pm = CommandParser::run('/msg bob hi', $alice, $ch);
check('PM blocked when target blocks sender', $pm['replies'][0] === 'A block prevents messaging between you.', $pm['replies'][0] ?? '');

// DM image attachments: private messages carry a kind, so image uploads render.
$imgDm = MessageService::insertPm($alice, $bob, "/uploads/dm-image.jpg\nDM caption", 'image');
check('DM image message inserted', $imgDm > 0);
check('DM image stored with kind image', (Database::row('SELECT kind FROM private_messages WHERE id = ?', [$imgDm])['kind'] ?? '') === 'image');
$dmRows = MessageService::forDm($alice, $bob);
$kindFound = false;
foreach ($dmRows as $r) { if (($r['kind'] ?? '') === 'image' && ($r['is_pm'] ?? false) === true && str_contains((string) $r['content'], '/uploads/dm-image.jpg')) $kindFound = true; }
check('forDm returns DM image kind', $kindFound, json_encode($dmRows));
$histRows = MessageService::dmHistoryBefore($alice, $bob, $imgDm + 1);
$histKind = false;
foreach ($histRows as $r) { if (($r['kind'] ?? '') === 'image' && str_contains((string) $r['content'], '/uploads/dm-image.jpg')) $histKind = true; }
check('dmHistoryBefore returns DM image kind', $histKind, json_encode($histRows));
$plain = MessageService::insertPm($bob, $alice, 'plain dm');
check('plain DM defaults to kind message', (Database::row('SELECT kind FROM private_messages WHERE id = ?', [$plain])['kind'] ?? '') === 'message');

// GIFs post into DMs with kind gif, exactly like image attachments.
$gifDm = MessageService::insertPm($alice, $bob, "https://media.giphy.com/media/dm/giphy.gif\nDM GIF", 'gif');
check('DM gif inserted', $gifDm > 0);
check('DM gif stored with kind gif', (Database::row('SELECT kind FROM private_messages WHERE id = ?', [$gifDm])['kind'] ?? '') === 'gif');

// --- Keyed / private channel + share link flow ---
echo "== private channel / share link ==\n";
$priv = ChannelService::create($bob, '#secret');
$res = CommandParser::run('/set #secret private on', $bob, $priv);
check('set private', str_contains($res['replies'][0] ?? '', 'private'));
check('private hidden from public list', !in_array($priv['id'], array_column(ChannelService::publicChannels(), 'id'), true));
check('link grants access to private', ChannelService::joinStatus($priv, $alice)['ok'] === true);
check('slug matches url', ChannelService::findBySlug('secret')['id'] === $priv['id']);
$res = CommandParser::run('/mode #secret +k hunter2', $bob, $priv);
check('set channel key', $res['replies'][0] === 'Modes updated.');
check('keyed join requires key', ChannelService::joinStatus($priv, $alice)['reason'] === 'need_key');
check('correct key joins', ChannelService::joinStatus($priv, $alice, 'hunter2')['ok'] === true);

// --- OperServ (needs admin) ---
echo "== oper ==\n";
$res = CommandParser::run('/kline bob 1h spam', $bob, $ch);
check('non-admin cannot /kline', str_contains($res['replies'][0] ?? '', 'restricted'));
Auth::register('charlie', 'charlie@example.com', 'password123', true);
$res = CommandParser::run('/kline charlie 1h spam', $alice, $ch);
check('/kline as admin', str_contains($res['replies'][0] ?? '', 'KLINE'));
check('kline blocks charlie', Auth::globalBanFor(Auth::attempt('charlie', 'password123')) !== null);
$res = CommandParser::run('/global test announcement', $alice, $ch);
check('/global announcement', $res['replies'][0] === 'Announcement sent to all channels.');

// --- MemoServ / NickServ / HostServ ---
echo "== services ==\n";
$res = CommandParser::run('/memo send bob offline memo', $alice, $ch);
check('/memo send', $res['replies'][0] === 'Memo sent to bob.');
$res = CommandParser::run('/memo read', $bob, $ch);
check('/memo read has memo', str_contains($res['replies'][0] ?? '', 'Unread memos'));
$res = CommandParser::run('/nick bob_the_second', $bob, $ch);
check('/nick rename', $res['replies'][0] === 'You are now known as bob_the_second.', $res['replies'][0] ?? '');
$res = CommandParser::run('/vhost set myhost.example', $alice, $ch);
check('/vhost set', $res['replies'][0] === 'Virtual host set to myhost.example. Use /vhost on to activate it.');
$res = CommandParser::run('/set email new@example.com', $bob, $ch);
check('/set email', $res['replies'][0] === 'Email updated.');

// --- Default seeded channels ---
echo "== default channels ==\n";
$general = ChannelService::find('#general');
$help = ChannelService::find('#help');
$staff = ChannelService::find('#staff');
check('#general seeded public', $general !== null && $general['visibility'] === 'public');
check('#help seeded public', $help !== null && $help['visibility'] === 'public');
check('#staff seeded staff-only', $staff !== null && $staff['visibility'] === 'staff');
check('regular user denied #staff', ChannelService::joinStatus($staff, $bob)['ok'] === false);
check('admin can join #staff', ChannelService::joinStatus($staff, $alice)['ok'] === true);
check('#staff hidden from public list', !in_array($staff['id'], array_column(ChannelService::publicChannels(), 'id'), true));
Auth::register('dave', 'dave@example.com', 'password123', true);
Database::query('UPDATE users SET role = "staff" WHERE username = "dave"');
$dave = Auth::attempt('dave', 'password123');
check('staff member can join #staff', ChannelService::joinStatus($staff, $dave)['ok'] === true);
check('#staff denied via share link to non-staff', ChannelService::joinStatus($staff, $bob)['ok'] === false);

// ── Comprehensive command coverage ──────────────────────────────────────────
$alice = Auth::attempt('alice', 'password123');
$bob2 = Auth::attempt('bob_the_second', 'password123');
Auth::register('erin', 'erin@example.com', 'password123', true);
$erin = Auth::attempt('erin', 'password123');
$dave = Auth::attempt('dave', 'password123');
$ch = ChannelService::find('#test');
ChannelService::join($ch, $bob2);
ChannelService::join($ch, $erin);

echo "== core commands ==\n";
$res = CommandParser::run('/away brb', $alice, $ch);
check('/away', $res['replies'][0] === 'You are now away: brb', $res['replies'][0] ?? '');
$res = CommandParser::run('/back', $alice, $ch);
check('/back', $res['replies'][0] === 'You are back.', $res['replies'][0] ?? '');
$res = CommandParser::run('/ping', $alice, $ch);
check('/ping', $res['replies'][0] === 'Pong!');
$res = CommandParser::run('/info', $alice, $ch);
check('/info', count($res['replies']) >= 3);
$res = CommandParser::run('/whois bob_the_second', $alice, $ch);
check('/whois', count($res['replies']) >= 1);
$res = CommandParser::run('/ignore bob_the_second', $alice, $ch);
check('/ignore', $res['replies'][0] === 'You have blocked bob_the_second.', $res['replies'][0] ?? '');
$res = CommandParser::run('/unignore bob_the_second', $alice, $ch);
check('/unignore', $res['replies'][0] === 'You have unblocked bob_the_second.', $res['replies'][0] ?? '');
$pm = CommandParser::run('/msg alice note to self', $alice, $ch);
check('message yourself is allowed (IRC hallmark)', $pm['replies'] === [] && count(MessageService::forDm($alice, $alice)) === 1);
$res = CommandParser::run('/invite bob_the_second #test', $alice, $ch);
check('/invite', str_contains($res['replies'][0] ?? '', 'invited'));
$res = CommandParser::run('/knock #secret', $bob2, $ch);
check('/knock', str_contains($res['replies'][0] ?? '', 'knock'));
$res = CommandParser::run('/clear', $alice, $ch);
check('/clear action', ($res['action'] ?? '') === 'clear');
$res = CommandParser::run('/share #test', $alice, $ch);
check('/share copy link', ($res['copy'] ?? '') === canonical_channel_url('test'));
$res = CommandParser::run('/channels', $alice, $ch);
check('/channels opens browser', ($res['action'] ?? '') === 'browse');
$res = CommandParser::run('/part #test', $erin, $ch);
check('/part', str_contains($res['replies'][0] ?? '', 'left'), $res['replies'][0] ?? '');
ChannelService::join($ch, $erin);

echo "== channel op commands ==\n";
$res = CommandParser::run('/voice erin #test', $alice, $ch);
check('/voice', str_contains($res['replies'][0] ?? '', 'voice'));
$res = CommandParser::run('/halfop erin #test', $alice, $ch);
check('/halfop', str_contains($res['replies'][0] ?? '', 'halfop'));
$res = CommandParser::run('/op erin #test', $alice, $ch);
check('/op', str_contains($res['replies'][0] ?? '', 'now has level: op'));
$res = CommandParser::run('/deop erin #test', $alice, $ch);
check('/deop', str_contains($res['replies'][0] ?? '', 'normal'));
$res = CommandParser::run('/devoice erin #test', $alice, $ch);
check('/devoice', str_contains($res['replies'][0] ?? '', 'normal'));
$res = CommandParser::run('/quiet erin #test', $alice, $ch);
check('/quiet', str_contains($res['replies'][0] ?? '', 'muted'));
check('quieted user cannot post', BanService::canPost($ch, $erin, AccessService::member($ch['id'], (int) $erin['id'])) !== null);
Database::query('DELETE FROM bans WHERE kind = "quiet" AND channel_id = ?', [$ch['id']]);
$res = CommandParser::run('/kickban erin #test you are banned', $alice, $ch);
check('/kickban', str_contains($res['replies'][0] ?? '', 'Kicked'), $res['replies'][0] ?? '');
check('kickbanned user cannot rejoin', ChannelService::joinStatus($ch, $erin)['ok'] === false);
$res = CommandParser::run('/unban erin #test', $alice, $ch);
check('/unban after kickban', str_contains($res['replies'][0] ?? '', 'Removed ban'));
ChannelService::join($ch, $erin);
$res = CommandParser::run('/mode #test +i', $alice, $ch);
check('/mode +i', $res['replies'][0] === 'Modes updated.');
check('#test invite-only', (int) ChannelService::find('#test')['invite_only'] === 1);
$res = CommandParser::run('/mode #test +l 50', $alice, $ch);
check('/mode +l', $res['replies'][0] === 'Modes updated.');
check('#test limit 50', (int) ChannelService::find('#test')['member_limit'] === 50);
$res = CommandParser::run('/mode #test -lim', $alice, $ch);
check('/mode -l -i -m', $res['replies'][0] === 'Modes updated.');
$res = CommandParser::run('/topiclock #test on', $alice, $ch);
check('/topiclock on', str_contains($res['replies'][0] ?? '', 'locked'));
$res = CommandParser::run('/topiclock #test off', $alice, $ch);
check('/topiclock off', str_contains($res['replies'][0] ?? '', 'unlocked'));

echo "== chanserv ==\n";
$res = CommandParser::run('/register #test', $alice, $ch);
check('/register existing', str_contains($res['replies'][0] ?? '', 'now registered'), $res['replies'][0] ?? '');
$res = CommandParser::run('/access #test list', $alice, $ch);
check('/access list empty', str_contains($res['replies'][0] ?? '', 'empty'));
$res = CommandParser::run('/access #test add erin op', $alice, $ch);
check('/access add', str_contains($res['replies'][0] ?? '', 'Added erin'));
check('access list level applies', AccessService::effectiveLevel($ch['id'], (int) $erin['id']) === 'op');
$res = CommandParser::run('/access #test del erin', $alice, $ch);
check('/access del', str_contains($res['replies'][0] ?? '', 'Removed erin'));
$res = CommandParser::run('/akick #test add erin spamming', $alice, $ch);
check('/akick add', str_contains($res['replies'][0] ?? '', 'AKICK'));
check('akick blocks join', ChannelService::joinStatus($ch, $erin)['ok'] === false);
$res = CommandParser::run('/akick #test del erin', $alice, $ch);
check('/akick del', str_contains($res['replies'][0] ?? '', 'Removed erin'));
check('akick removed -> can join', ChannelService::joinStatus($ch, $erin)['ok'] === true, json_encode(ChannelService::joinStatus($ch, $erin)));
$res = CommandParser::run('/set #test desc a cool channel', $alice, $ch);
check('/set desc', str_contains($res['replies'][0] ?? '', 'Description updated'));
$res = CommandParser::run('/chaninfo #test', $alice, $ch);
check('/chaninfo', count($res['replies']) >= 3);
$res = CommandParser::run('/getkey #test', $alice, $ch);
check('/getkey', str_contains($res['replies'][0] ?? '', 'no key'));
$res = CommandParser::run('/senak #test hello ops', $alice, $ch);
check('/senak', str_contains($res['replies'][0] ?? '', 'sent'));
$res = CommandParser::run('/cs chaninfo #test', $alice, $ch);
check('/cs alias', count($res['replies']) >= 3);
$res = CommandParser::run('/identify #secret hunter2', $bob2, $ch);
check('/identify channel', str_contains($res['replies'][0] ?? '', 'Now in'));
$tmp = ChannelService::create($alice, '#temp');
$res = CommandParser::run('/drop #temp', $alice, $tmp);
check('/drop', str_contains($res['replies'][0] ?? '', 'dropped'), $res['replies'][0] ?? '');
check('#temp dropped', ChannelService::find('#temp') === null);
$res = CommandParser::run('/transfer #test bob_the_second', $alice, $ch);
check('/transfer', str_contains($res['replies'][0] ?? '', 'transferred'));
check('new founder is bob', Database::scalar('SELECT owner_id FROM channels WHERE id = ?', [$ch['id']]) == $bob2['id']);
$res = CommandParser::run('/transfer #test alice', $bob2, $ch);
check('/transfer back', str_contains($res['replies'][0] ?? '', 'transferred'));
$res = CommandParser::run('/forbid #test', $alice, $ch);
check('/forbid', str_contains($res['replies'][0] ?? '', 'forbidden'));
check('#test forbidden blocks join', ChannelService::joinStatus($ch, $dave)['ok'] === false);
$res = CommandParser::run('/forbid #test off', $alice, $ch);
check('/forbid off', str_contains($res['replies'][0] ?? '', 'un-forbidden'));

// --- Channel settings / URL bans ---
echo "== channel settings / URL ==\n";
check('url ban normalize wildcard', UrlBanService::normalizeHost('*.Example.COM') === 'example.com');
check('url ban normalize url', UrlBanService::normalizeHost('https://Sub.EXAMPLE.com/path') === 'sub.example.com');
check('url ban add', UrlBanService::add('banned.test', 'spam test', (int) $alice['id']) === null);
check('url ban duplicate rejected', UrlBanService::add('BANNED.test', 'dup', (int) $alice['id']) !== null);
check('url ban exact match', UrlBanService::isBanned('banned.test') !== null);
check('url ban subdomain match', UrlBanService::isBanned('sub.banned.test') !== null);
check('url ban non-match', UrlBanService::isBanned('ok.test') === null);
check('url allowed good', isset(UrlBanService::urlAllowed('https://ok.test/x')['ok']));
check('url allowed banned domain', isset(UrlBanService::urlAllowed('https://sub.banned.test/')['error']));
check('url allowed non-http rejected', isset(UrlBanService::urlAllowed('ftp://x.test')['error']));

$urlCh = ChannelService::find('#test');
check('founder can manage channel', ChannelService::canManageChannel($urlCh, $alice) === true);
check('normal member cannot manage channel', ChannelService::canManageChannel($urlCh, $erin) === false);
check('set channel url', ChannelService::setChannelUrl($urlCh['id'], 'https://good.example.org/page') === null);
$fresh = Database::row('SELECT * FROM channels WHERE id = ?', [$urlCh['id']]);
check('channel_url persisted', ($fresh['channel_url'] ?? '') === 'https://good.example.org/page');
check('channelUrl served', ChannelService::channelUrl($fresh) === 'https://good.example.org/page');
check('banned domain rejected on set', ChannelService::setChannelUrl($urlCh['id'], 'https://banned.test/evil') !== null);
check('clear channel url', ChannelService::setChannelUrl($urlCh['id'], '') === null);
check('channel_url cleared', (Database::row('SELECT channel_url FROM channels WHERE id = ?', [$urlCh['id']])['channel_url'] ?? null) === null);
ChannelService::setChannelUrl($urlCh['id'], 'https://later-banned.test');
UrlBanService::add('later-banned.test', 'added after', (int) $alice['id']);
$fresh2 = Database::row('SELECT * FROM channels WHERE id = ?', [$urlCh['id']]);
check('banned url hidden from clients', ChannelService::channelUrl($fresh2) === null);
check('banned url flagged', ChannelService::channelUrlBanned($fresh2) === true);
UrlBanService::remove((int) UrlBanService::isBanned('later-banned.test')['id']);
UrlBanService::remove((int) UrlBanService::isBanned('banned.test')['id']);
check('url ban remove', UrlBanService::isBanned('banned.test') === null);

// ── Channel delete: rename to -deleted####, archive preserved ───────────────
$doomed = ChannelService::create($alice, '#doomed');
CommandParser::run('/register #doomed', $alice, $doomed);
MessageService::send((int) $doomed['id'], $alice, 'doomed history line');
$delRes = ChannelService::delete($doomed['id'], $alice);
check('founder can delete their channel', $delRes === true, is_string($delRes) ? $delRes : '');
$delName = (string) Database::scalar('SELECT name FROM channels WHERE id = ?', [$doomed['id']]);
check('deleted channel renamed to -deleted####', ChannelService::find('#doomed') === null && preg_match('/^-deleted\d{4}$/', $delName) === 1, $delName);
check('deleted channel hidden (forbidden)', (int) Database::scalar('SELECT forbidden FROM channels WHERE id = ?', [$doomed['id']]) === 1);
check('deleted channel members cleared', (int) Database::scalar('SELECT COUNT(*) FROM channel_members WHERE channel_id = ?', [$doomed['id']]) === 0);
check('archive re-labelled under -deleted name', (int) Database::scalar('SELECT COUNT(*) FROM chat_logs WHERE channel_name = ?', [$delName]) >= 1 && (int) Database::scalar('SELECT COUNT(*) FROM chat_logs WHERE channel_name = "#doomed"') === 0);
check('non-founder cannot delete a channel', is_string(ChannelService::delete($doomed['id'], $bob)));
$owned = ChannelService::ownedChannels($alice);
check('ownedChannels lists the founder\'s channels (excludes deleted)', in_array('#test', array_column($owned, 'name'), true) && !array_filter($owned, fn ($c) => str_starts_with($c['name'], '-deleted'))) ;

// A registered-but-unowned channel (e.g. a seeded default) can be claimed by an
// admin via /register, and then appears under "My Channels".
$gen = ChannelService::find('#general');
Database::query('UPDATE channels SET owner_id = NULL WHERE id = ?', [$gen['id']]);
$res = CommandParser::run('/register #general', $alice, $gen);
check('admin can claim an unowned registered channel', str_contains($res['replies'][0] ?? '', 'You are the founder'), $res['replies'][0] ?? '');
$owned2 = ChannelService::ownedChannels($alice);
check('claimed channel appears in My Channels', in_array('#general', array_column($owned2, 'name'), true));

echo "== nickserv ==\n";
$res = CommandParser::run('/ns status alice', $alice, $ch);
check('/ns status', str_contains($res['replies'][0] ?? '', 'registered'));
$res = CommandParser::run('/status', $alice, $ch);
check('/status', str_contains($res['replies'][0] ?? '', 'registered'));
$res = CommandParser::run('/info', $alice, $ch);
check('/info nickserv', count($res['replies']) >= 3);
$res = CommandParser::run('/ghost alice', $alice, $ch);
check('/ghost', str_contains($res['replies'][0] ?? '', 'terminated'));
$res = CommandParser::run('/release alice', $alice, $ch);
check('/release', str_contains($res['replies'][0] ?? '', 'released'));
$res = CommandParser::run('/recover alice', $alice, $ch);
check('/recover', str_contains($res['replies'][0] ?? '', 'recovered'));
$res = CommandParser::run('/group', $alice, $ch);
check('/group', str_contains($res['replies'][0] ?? '', 'unified'));
$res = CommandParser::run('/identify password123', $alice, $ch);
check('/identify nickserv', str_contains($res['replies'][0] ?? '', 'verified'));

echo "== memoserv ==\n";
$res = CommandParser::run('/memo send alice test memo', $bob2, $ch);
check('/memo send', $res['replies'][0] === 'Memo sent to alice.', $res['replies'][0] ?? '');
$res = CommandParser::run('/memo read', $alice, $ch);
check('/memo read', str_contains($res['replies'][0] ?? '', 'Unread memos'));
$res = CommandParser::run('/memo summary', $alice, $ch);
check('/memo summary', str_contains($res['replies'][0] ?? '', 'unread'));
$res = CommandParser::run('/memo list', $alice, $ch);
check('/memo list', str_contains($res['replies'][0] ?? '', 'unread'));
$memoId = (int) Database::scalar('SELECT id FROM memos WHERE recipient_id = ? ORDER BY id DESC LIMIT 1', [$alice['id']]);
$res = CommandParser::run("/memo read $memoId", $alice, $ch);
check('/memo read id', str_contains($res['replies'][0] ?? '', 'Memo'));
$res = CommandParser::run("/memo del $memoId", $alice, $ch);
check('/memo del', $res['replies'][0] === 'Memo deleted.');
$res = CommandParser::run('/memo set notify', $alice, $ch);
check('/memo set', str_contains($res['replies'][0] ?? '', 'notifications are notify'));
$res = CommandParser::run('/ms send alice hi', $bob2, $ch);
check('/ms alias', str_contains($res['replies'][0] ?? '', 'sent'));

echo "== hostserv ==\n";
$res = CommandParser::run('/vhost on', $alice, $ch);
check('/vhost on', str_contains($res['replies'][0] ?? '', 'activated'));
$res = CommandParser::run('/vhost status', $alice, $ch);
check('/vhost status', str_contains($res['replies'][0] ?? '', 'myhost.example'));
$res = CommandParser::run('/vhost off', $alice, $ch);
check('/vhost off', str_contains($res['replies'][0] ?? '', 'deactivated'));
$res = CommandParser::run('/hs status', $alice, $ch);
check('/hs alias', str_contains($res['replies'][0] ?? '', 'vhost'));

echo "== operserv ==\n";
$res = CommandParser::run('/oper bob_the_second password', $bob2, $ch);
check('/oper with no o:line rejected', str_contains($res['replies'][0] ?? '', 'Incorrect oper credentials'), $res['replies'][0] ?? '');
$res = CommandParser::run('/clients', $alice, $ch);
check('/clients', count($res['replies']) >= 1);
$res = CommandParser::run('/serverstats', $alice, $ch);
check('/serverstats', count($res['replies']) >= 5);
$res = CommandParser::run('/motd', $alice, $ch);
check('/motd view', count($res['replies']) >= 1);
$res = CommandParser::run('/motd set new motd here', $alice, $ch);
check('/motd set', $res['replies'][0] === 'MOTD updated.');
// MOTD lines must be returned raw (the client escapes via linkify) — pre-escaping
// here double-escapes & < > and mangles URLs. \n sequences set a multi-line MOTD.
$res = CommandParser::run('/motd set Line one\nLine two & <b>x</b>', $alice, $ch);
check('/motd set preserves \\n + raw text', $res['replies'][0] === 'MOTD updated.');
$res = CommandParser::run('/motd', $alice, $ch);
check('/motd lines unescaped + split', $res['replies'] === ['Line one', 'Line two & <b>x</b>'], json_encode($res['replies']));
$res = CommandParser::run('/wallops hello everyone', $alice, $ch);
check('/wallops', $res['replies'][0] === 'Announcement sent to all channels.');
$res = CommandParser::run('/gline dave 1h bad', $alice, $ch);
check('/gline', str_contains(strtolower($res['replies'][0] ?? ''), 'gline'));
$res = CommandParser::run('/ungline dave', $alice, $ch);
check('/ungline', str_contains($res['replies'][0] ?? '', 'removed'));
$res = CommandParser::run('/zline 1.2.3.4 1h ip ban', $alice, $ch);
check('/zline', str_contains($res['replies'][0] ?? '', 'ZLINE'));
$res = CommandParser::run('/unzline 1.2.3.4', $alice, $ch);
check('/unzline', str_contains($res['replies'][0] ?? '', 'removed'));
$res = CommandParser::run('/shun erin 1h quiet time', $alice, $ch);
check('/shun', str_contains(strtolower($res['replies'][0] ?? ''), 'shun'));
check('shunned user blocked from sending', BanService::sendBlocked($erin, 'hi', 'c') !== null);
$res = CommandParser::run('/unshun erin', $alice, $ch);
check('/unshun', str_contains($res['replies'][0] ?? '', 'removed'));
$res = CommandParser::run('/sqline bannednick', $alice, $ch);
check('/sqline', str_contains($res['replies'][0] ?? '', 'forbidden'));
$res = CommandParser::run('/sajoin dave #test', $alice, $ch);
check('/sajoin', str_contains($res['replies'][0] ?? '', 'Forced dave'));
check('dave joined via sajoin', AccessService::member($ch['id'], (int) $dave['id']) !== null);
$res = CommandParser::run('/sapart dave #test', $alice, $ch);
check('/sapart', str_contains($res['replies'][0] ?? '', 'Forced dave'));
$res = CommandParser::run('/sanick dave davey', $alice, $ch);
check('/sanick', str_contains($res['replies'][0] ?? '', 'now known as davey'));
$res = CommandParser::run('/sasethost davey some.host', $alice, $ch);
check('/sasethost', str_contains($res['replies'][0] ?? '', 'vhost set'));
$res = CommandParser::run('/samode #test +m', $alice, $ch);
check('/samode', str_contains($res['replies'][0] ?? '', 'Modes updated'));
$res = CommandParser::run('/mode #test -m', $alice, $ch);
$res = CommandParser::run('/kill erin bad behavior', $alice, $ch);
check('/kill', str_contains($res['replies'][0] ?? '', 'killed'));
check('kill removes erin from channels', AccessService::member($ch['id'], (int) $erin['id']) === null);
ChannelService::join($ch, $erin);
$res = CommandParser::run('/notice bob_the_second hello', $alice, $ch);
check('/notice operserv', $res['replies'][0] === 'Notice sent to bob_the_second.');
$res = CommandParser::run('/spamfilter add badword', $alice, $ch);
check('/spamfilter add', $res['replies'][0] === 'Spam filter added.');
$res = CommandParser::run('/spamfilter list', $alice, $ch);
check('/spamfilter list', count($res['replies']) >= 1);
$res = CommandParser::run('/rehash', $alice, $ch);
check('/rehash', $res['replies'][0] === 'Configuration reloaded.');
$sf = (int) Database::scalar('SELECT id FROM spamfilters WHERE match = "badword" ORDER BY id DESC LIMIT 1');
$res = CommandParser::run("/spamfilter del $sf", $alice, $ch);
check('/spamfilter del', $res['replies'][0] === 'Spam filter removed.');

echo "== mentions ==\n";
$before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = "mention"', [$erin['id']]);
MessageService::send((int) $ch['id'], $alice, 'hey @erin check this');
$after = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = "mention"', [$erin['id']]);
check('mention creates notification', $after === $before + 1);

// ── Channel lifecycle: temp channels vanish, register/deregister ────────────
echo "== channel lifecycle ==\n";
Auth::register('frank', 'frank@example.com', 'password123', true);
$frank = Auth::attempt('frank', 'password123');
$tmp = ChannelService::create($frank, '#temp2');
check('temp channel starts unregistered', ChannelService::isRegistered($tmp) === false);
ChannelService::join($tmp, $alice);
ChannelService::part($tmp, $frank, null);
check('temp channel survives while someone remains', ChannelService::find('#temp2') !== null);
ChannelService::part($tmp, $alice, null);
check('temp channel deleted when last member leaves', ChannelService::find('#temp2') === null);

// Founder transfer when the owner leaves but others remain.
$tmp = ChannelService::create($frank, '#temp3');
ChannelService::join($tmp, $alice);
ChannelService::part($tmp, $frank, null);
$row = Database::row('SELECT owner_id FROM channels WHERE id = ?', [$tmp['id']]);
check('founder transfers to remaining member', (int) ($row['owner_id'] ?? 0) === (int) $alice['id']);
check('heir becomes founder level', AccessService::effectiveLevel($tmp['id'], (int) $alice['id']) === 'founder');
Database::query('DELETE FROM channels WHERE id = ?', [$tmp['id']]);

// Register keeps an empty channel alive; /unregister makes it temporary again.
$tmp = ChannelService::create($frank, '#temp4');
Database::query('UPDATE channels SET registered_at = datetime("now") WHERE id = ?', [$tmp['id']]);
ChannelService::join($tmp, $frank);
$res = CommandParser::run('/unregister #temp4', $frank, $tmp);
check('/unregister', str_contains($res['replies'][0] ?? '', 'no longer registered'), $res['replies'][0] ?? '');
check('#temp4 temporary again', ChannelService::isRegistered(ChannelService::find('#temp4')) === false);
ChannelService::part($tmp, $frank, null);
check('unregistered channel deleted when empty', ChannelService::find('#temp4') === null);

// /register on a fresh temp channel makes it persist.
$tmp = ChannelService::create($frank, '#temp5');
$res = CommandParser::run('/register #temp5', $frank, $tmp);
check('/register new temp', str_contains($res['replies'][0] ?? '', 'now registered'), $res['replies'][0] ?? '');
check('registered persists', ChannelService::isRegistered(ChannelService::find('#temp5')) === true);
Database::query('DELETE FROM channels WHERE id = ?', [$tmp['id']]);

// ── Admin IP visibility + IP/CIDR bans ──────────────────────────────────────
echo "== ip bans ==\n";
Database::query('UPDATE users SET last_ip = "203.0.113.7" WHERE username = "erin"');
$res = CommandParser::run('/whois erin', $alice, $ch);
check('admin /whois shows IP', in_array('IP: 203.0.113.7', $res['replies'], true), $res['replies'][1] ?? '');
$res = CommandParser::run('/whois erin', $erin, $ch);
check('non-admin /whois hides IP', !in_array('IP: 203.0.113.7', $res['replies'], true));
$res = CommandParser::run('/zline 203.0.113.7 1h ip test', $alice, $ch);
check('/zline by IP', str_contains(strtolower($res['replies'][0] ?? ''), '203.0.113.7'));
check('IP ban blocks erin from login', Auth::globalBanFor(Database::row('SELECT * FROM users WHERE username = "erin"')) !== null);
$res = CommandParser::run('/unzline 203.0.113.7', $alice, $ch);
check('/unzline removes IP ban', str_contains($res['replies'][0] ?? '', 'removed'));
$res = CommandParser::run('/gline 203.0.113.0/24 1h range test', $alice, $ch);
check('/gline by CIDR range', str_contains(strtolower($res['replies'][0] ?? ''), '203.0.113.0/24'));
check('CIDR ban matches erin IP', Auth::globalBanFor(Database::row('SELECT * FROM users WHERE username = "erin"')) !== null);
$res = CommandParser::run('/ungline 203.0.113.0/24', $alice, $ch);
check('/ungline removes CIDR ban', str_contains($res['replies'][0] ?? '', 'removed'));

// ── Bad-word censor + +C mode ───────────────────────────────────────────────
echo "== bad word censor ==\n";
Database::query('INSERT INTO badwords (word, action) VALUES ("floop", "censor")');
Database::query('INSERT INTO badwords (word, action) VALUES ("snork", "block")');
check('+C off by default -> no filtering', CensorService::check('hello floop world', CensorService::isChannelFiltered($ch)) === null);
$res = CommandParser::run('/mode #test +C', $alice, $ch);
check('/mode +C', $res['replies'][0] === 'Modes updated.');
check('+C enables filter', CensorService::check('hello floop world', CensorService::isChannelFiltered(ChannelService::find('#test')))['action'] === 'censor');
check('censor replaces word', CensorService::check('say floop', true)['censored'] === 'say ****');
$block = CensorService::check('snork away', true);
check('block action detected', $block !== null && $block['action'] === 'block');
check('/mode help lists flags', (count(CommandParser::run('/mode #test', $alice, $ch)['replies'] ?? []) >= 10));
$res = CommandParser::run('/mode #test -C', $alice, $ch);
check('/mode -C', $res['replies'][0] === 'Modes updated.');
check('-C disables filter', CensorService::check('floop', CensorService::isChannelFiltered(ChannelService::find('#test'))) === null);
$res = CommandParser::run('/badword add fleep block', $alice, $ch);
check('/badword add', str_contains($res['replies'][0] ?? '', 'added'));
$res = CommandParser::run('/badword list', $alice, $ch);
check('/badword list', count($res['replies']) >= 3);
$bw = (int) Database::scalar('SELECT id FROM badwords WHERE word = "fleep"');
$res = CommandParser::run("/badword del $bw", $alice, $ch);
check('/badword del', $res['replies'][0] === 'Bad word removed.');

// Wildcard bad words: trailing * = prefix match, leading * = in-word match.
Database::query('INSERT INTO badwords (word, action) VALUES ("bloop*", "censor")');
Database::query('INSERT INTO badwords (word, action) VALUES ("*splat*", "censor")');
check('trailing * catches plurals', CensorService::check('bloops', true)['action'] === 'censor');
check('trailing * censors prefix', CensorService::check('a bloopish joke', true)['censored'] === 'a ****ish joke');
check('trailing * leaves longer prefix alone when bounded', CensorService::check('sabloop', true) === null);
check('leading+trailing * catches in-word', CensorService::check('fat splatting', true)['censored'] === 'fat ****ting');
check('leading+trailing * catches suffix', CensorService::check('splatted', true)['censored'] === '****ted');
check('leading+trailing * catches leading in-word', CensorService::check('asplat', true)['censored'] === 'a****');
check('literal * only is skipped', CensorService::check('*', true) === null);
Database::query('DELETE FROM badwords WHERE word IN ("bloop*", "*splat*")');

// ── Admin red text data (role present in message payloads) ──────────────────
echo "== admin role payload ==\n";
$m = MessageService::send((int) $ch['id'], $alice, 'admin message check');
check('admin message carries role=admin', ($m['role'] ?? '') === 'admin');

// ── Comprehensive logging (survives channel deletion) ───────────────────────
echo "== chat logs archive ==\n";
$logCh = ChannelService::create($frank, '#logtest');
ChannelService::join($logCh, $frank);
ChannelService::join($logCh, $alice);
MessageService::send((int) $logCh['id'], $frank, 'logged message one');
MessageService::send((int) $logCh['id'], $alice, 'logged message two');
MessageService::system((int) $logCh['id'], 'topic', $frank['username'] . ' set the topic');
$entries = (int) Database::scalar('SELECT COUNT(*) FROM chat_logs WHERE channel_name = "#logtest"');
check('chat_logs has channel activity', $entries >= 4, "got $entries");
$participants = MessageService::channelParticipants('#logtest');
check('participants recorded', count($participants) === 2 && ($participants[0]['messages'] ?? 0) >= 1, json_encode($participants));
// Delete the channel (part the last member) and confirm the archive survives.
ChannelService::part($logCh, $frank, null);
ChannelService::part($logCh, $alice, null);
check('temp channel deleted', ChannelService::find('#logtest') === null);
check('archive survives channel deletion', (int) Database::scalar('SELECT COUNT(*) FROM chat_logs WHERE channel_name = "#logtest"') >= 4);
$channels = MessageService::loggedChannels();
check('deleted channel still listed in archive', in_array('#logtest', array_column($channels, 'channel_name'), true));
// PM logging
CommandParser::run('/msg alice private note', $bob2, $ch);
check('PM archived', (int) Database::scalar('SELECT COUNT(*) FROM chat_logs WHERE channel_name = "PM: alice"') >= 1);

// ── Custom roles + permissions ──────────────────────────────────────────────
echo "== custom roles ==\n";
Database::query('INSERT INTO roles (name, color, perms) VALUES ("Moderator", "#00ff88", ?)', [json_encode(['oper', 'manage_badwords'])]);
Database::query('UPDATE users SET role_id = ? WHERE username = "frank"', [(int) Database::lastId()]);
$frank = Auth::attempt('frank', 'password123');
check('custom role grants oper', Auth::isOper($frank) === true);
check('oper role can use /clients', !str_contains(CommandParser::run('/clients', $frank, $ch)['replies'][0] ?? '', 'restricted'));
$res = CommandParser::run('/kline erin 1h test', $frank, $ch);
check('oper role can /kline', !str_contains($res['replies'][0] ?? '', 'restricted'), $res['replies'][0] ?? '');
Database::query('DELETE FROM bans WHERE kind = "kline" AND target_user_id = ?', [$erin['id']]);
check('oper role sees IP in /whois', in_array('IP: 203.0.113.7', CommandParser::run('/whois erin', $frank, $ch)['replies'], true));
Database::query('UPDATE users SET role_id = NULL WHERE username = "frank"');
$frank = Auth::attempt('frank', 'password123');
check('removing role revokes oper', Auth::isOper($frank) === false);

// ── Helper role: green nick + always half-op ─────────────────────────────────
echo "== helper role ==\n";
Database::query('INSERT INTO roles (name, color, perms, helper) VALUES ("Helper", "#5865f2", "[]", 1)');
$helperRole = (int) Database::lastId();
Database::query('UPDATE users SET role_id = ? WHERE username = "frank"', [$helperRole]);
$frank = Auth::attempt('frank', 'password123');
check('helper role detected', Auth::isHelper($frank) === true);
ChannelService::join($ch, $frank);
check('helper effectiveLevel is halfop', AccessService::effectiveLevel($ch['id'], $frank) === 'halfop');
$frankMsg = MessageService::send((int) $ch['id'], $frank, 'hello from helper');
check('helper message level is halfop', ($frankMsg['level'] ?? '') === 'halfop');
check('helper message nick is green', ($frankMsg['role_color'] ?? '') === Auth::HELPER_COLOR);
foreach (ChannelService::members((string) $ch['id']) as $mm) {
    if ($mm['username'] === 'frank') {
        check('member list shows helper level', $mm['level'] === 'halfop');
        check('member list shows helper color', $mm['role_color'] === Auth::HELPER_COLOR);
        check('member list flags role_helper', (int) $mm['role_helper'] === 1);
    }
}
Database::query('DELETE FROM messages WHERE id = ?', [(int) $frankMsg['id']]);
Database::query('UPDATE users SET role_id = NULL WHERE username = "frank"');
Database::query('DELETE FROM roles WHERE id = ?', [$helperRole]);
$frank = Auth::attempt('frank', 'password123');
check('helper removed on role delete', Auth::isHelper($frank) === false);

// ── Chanop promotion + half-op limits (standard IRC) ────────────────────────
echo "== chanop promotion + half-op ==\n";
ChannelService::join($ch, $erin);
ChannelService::join($ch, $bob2);
$res = CommandParser::run('/op erin #test', $alice, $ch);
check('founder promotes to op', str_contains($res['replies'][0] ?? '', 'now has level: op'), $res['replies'][0] ?? '');
Auth::register('gloria', 'gloria@example.com', 'password123', true);
$gloria = Auth::attempt('gloria', 'password123');
ChannelService::join($ch, $gloria);
$res = CommandParser::run('/op gloria #test', $erin, $ch);
check('an op can promote another to op', str_contains($res['replies'][0] ?? '', 'now has level: op'), $res['replies'][0] ?? '');
$res = CommandParser::run('/halfop bob_the_second #test', $alice, $ch);
check('set half-op', str_contains($res['replies'][0] ?? '', 'now has level: halfop'), $res['replies'][0] ?? '');
$res = CommandParser::run('/mode #test +l 10', $bob2, $ch);
check('half-op cannot set +l (op-only)', str_contains($res['replies'][0] ?? '', 'operator') || str_contains($res['replies'][0] ?? '', 'Half-op') === false && str_contains($res['replies'][0] ?? '', 'Modes updated') === false, $res['replies'][0] ?? '');
$res = CommandParser::run('/mode #test -l +i', $alice, $ch);
check('op sets -l +i', $res['replies'][0] === 'Modes updated.');
$res = CommandParser::run('/mode #test -i', $bob2, $ch);
check('half-op can set -i', $res['replies'][0] === 'Modes updated.', $res['replies'][0] ?? '');

// ── Anonymous guests ─────────────────────────────────────────────────────────
echo "== guests ==\n";
$guest = Auth::loginGuest('anoncat', true);
check('guest login creates guest row', $guest !== null && (int) $guest['guest'] === 1, json_encode($guest));
check('guest nick is unique (duplicate rejected)', Auth::loginGuest('anoncat', true) === null);
check('guest cannot take a registered nick', Auth::loginGuest('alice', true) === null);

// ── Guest nick release (guests are never permanently "registered") ──────────
$g1 = Auth::loginGuest('guestnick', true);
check('guest login creates a guest row', $g1 !== null && (int) $g1['guest'] === 1, json_encode($g1));
check('guests never live in the users table', Database::scalar('SELECT COUNT(*) FROM users WHERE username = "guestnick"') === 0);
Auth::logout(); // guest logout stamps last_seen = NULL -> nick frees instantly
$g2 = Auth::loginGuest('guestnick', true);
check('guest logout frees nick (re-login reuses same row)', $g2 !== null && (int) $g2['id'] === (int) $g1['id'], json_encode($g2));
check('active guest still blocks a duplicate nick', Auth::loginGuest('guestnick', true) === null);
Database::query('UPDATE guests SET last_seen = datetime("now", "-1 hour") WHERE id = ?', [$g2['id']]);
$g3 = Auth::loginGuest('guestnick', true);
check('stale guest row reclaimed (same id keeps DM history)', $g3 !== null && (int) $g3['id'] === (int) $g1['id'], json_encode($g3));
Auth::logout();
$g4 = Auth::loginGuest('claimme', true);
Database::query('UPDATE guests SET last_seen = datetime("now", "-1 hour") WHERE id = ?', [$g4['id']]);
$rc = Auth::register('claimme', 'claimme@example.com', 'password123', true);
check('register converts a stale guest row into a real account', $rc['ok'] === true, json_encode($rc));
$claimed = Auth::attempt('claimme', 'password123');
check('converted guest is a real (non-guest) account', $claimed !== null && (int) $claimed['guest'] === 0, json_encode($claimed));
check('guest row is gone after conversion', Database::scalar('SELECT COUNT(*) FROM guests WHERE nick = "claimme"') === 0);
check('guest can join an existing channel', ChannelService::joinStatus(ChannelService::find('#test'), $guest)['ok'] === true);
ChannelService::join(ChannelService::find('#test'), $guest);
$gm = MessageService::send((int) ChannelService::find('#test')['id'], $guest, 'guest says hi');
check('guest can send messages', ($gm['username'] ?? '') === 'anoncat');
check('guest cannot create a channel', is_string(ChannelService::create($guest, '#guestchan')));
$res = CommandParser::run('/join #nobodyhere', $guest, null);
check('guest /join nonexistent denied', str_contains($res['replies'][0] ?? '', 'existing channels'), $res['replies'][0] ?? '');
Database::query('UPDATE guests SET last_seen = datetime("now", "-2 days") WHERE nick = "anoncat"');
Auth::purgeGuests();
check('inactive guest purged', Database::scalar('SELECT id FROM guests WHERE nick = "anoncat"') === false);

// ── Member counts reflect only registered users who are present ─────────────
$testCh = ChannelService::find('#test');
Database::query('UPDATE users SET last_seen = datetime("now"), away = NULL WHERE id = ?', [$alice['id']]);
Database::query('UPDATE users SET last_seen = datetime("now", "-1 hour") WHERE id = ?', [$bob['id']]);
check('member count counts present registered users', ChannelService::memberCount((int) $testCh['id']) === 1);
Database::query('UPDATE users SET last_seen = datetime("now", "-1 hour") WHERE id = ?', [$alice['id']]);
check('member count ignores offline users', ChannelService::memberCount((int) $testCh['id']) === 0);

// ── Online / peak stats ──────────────────────────────────────────────────────
Database::query('UPDATE users SET last_seen = datetime("now"), away = NULL WHERE id = ?', [$alice['id']]);
Database::query('UPDATE users SET last_seen = datetime("now"), away = NULL WHERE id = ?', [$bob['id']]);
check('online_count counts connected users', online_count() >= 2);
record_peak();
check('peak_online tracks concurrent users', (int) config_get('peak_online', '0') >= 2);

// ── O:lines / operclasses ───────────────────────────────────────────────────
echo "== o-lines ==\n";
$netadmin = (int) Database::scalar('SELECT id FROM operclasses WHERE name = "netadmin"');
check('netadmin operclass seeded', $netadmin > 0);
Database::query('INSERT INTO opers (username, password_hash, operclass_id) VALUES ("erin", ?, ?)', [password_hash('erinsecret', PASSWORD_ARGON2ID), $netadmin]);
$res = CommandParser::run('/oper erin erinsecret', $erin, $ch);
check('/oper with o:line succeeds', str_contains($res['replies'][0] ?? '', 'netadmin'), $res['replies'][0] ?? '');
check('oper session grants isOper', Auth::isOper($erin) === true);
$res = CommandParser::run('/clients', $erin, $ch);
check('oper can use /clients', !str_contains($res['replies'][0] ?? '', 'restricted'), $res['replies'][0] ?? '');
$res = CommandParser::run('/oper erin wrongpass', $erin, $ch);
check('/oper wrong password rejected', str_contains($res['replies'][0] ?? '', 'Incorrect oper credentials'));
$res = CommandParser::run('/oper alice whatever', $erin, $ch);
check('/oper requires matching nick', str_contains($res['replies'][0] ?? '', 'matches your nickname'), $res['replies'][0] ?? '');
$res = CommandParser::run('/deoper', $erin, $ch);
check('/deoper drops oper', $res['replies'][0] === 'You are no longer operating.');
check('deoper clears isOper', Auth::isOper($erin) === false);
Database::query('UPDATE opers SET enabled = 0 WHERE username = "erin"');
$res = CommandParser::run('/oper erin erinsecret', $erin, $ch);
check('disabled o:line rejected', str_contains($res['replies'][0] ?? '', 'Incorrect oper credentials'));

// ── Forbidden nicks (sqline / unsqline / sqlines) ────────────────────────────
echo "== forbidden nicks ==\n";
$res = CommandParser::run('/sqline forbiddennick keep out', $alice, $ch);
check('/sqline forbids a nick', str_contains($res['replies'][0] ?? '', 'forbidden'));
$res = CommandParser::run('/nick forbiddennick', $bob, $ch);
check('qline blocks /nick', str_contains($res['replies'][0] ?? '', 'reserved'), $res['replies'][0] ?? '');
$reg = Auth::register('forbiddennick', 'fn@example.com', 'password123', true);
check('qline blocks registration', $reg['ok'] === false, json_encode($reg));
$res = CommandParser::run('/sanick erin forbiddennick', $alice, $ch);
check('qline blocks sanick target', $res['replies'][0] === 'Requested nick is unavailable, please select another', $res['replies'][0] ?? '');
$res = CommandParser::run('/sqlines', $alice, $ch);
check('/sqlines lists forbidden nicks', str_contains(implode(' ', $res['replies']), 'forbiddennick'));
$res = CommandParser::run('/unsqline forbiddennick', $alice, $ch);
check('/unsqline removes the qline', str_contains($res['replies'][0] ?? '', 'removed'));
$res = CommandParser::run('/sqlines', $alice, $ch);
check('/sqlines no longer lists the removed nick', !str_contains(implode(' ', $res['replies']), 'forbiddennick'));

// ── Forbidden channels (cqline / uncqline / cqlines) ─────────────────────────
echo "== forbidden channels ==\n";
$res = CommandParser::run('/cqline #spamchan no spam rooms', $alice, $ch);
check('/cqline forbids a channel name', str_contains($res['replies'][0] ?? '', 'forbidden'));
check('cqline blocks creating a forbidden channel', str_contains(ChannelService::create($bob, '#spamchan'), 'forbidden'));
$mc = ChannelService::create($bob, '#matchme');
ChannelService::join($mc, $bob);
$res = CommandParser::run('/cqline #matchme', $alice, $ch);
check('/cqline forbids an existing channel name', str_contains($res['replies'][0] ?? '', 'forbidden'));
check('cqline blocks joining a forbidden channel', ChannelService::joinStatus(ChannelService::find('#matchme'), $erin)['ok'] === false);
$res = CommandParser::run('/cqlines', $alice, $ch);
check('/cqlines lists forbidden channels', str_contains(implode(' ', $res['replies']), 'matchme'));
$res = CommandParser::run('/uncqline #matchme', $alice, $ch);
check('/uncqline removes the cqline', str_contains($res['replies'][0] ?? '', 'removed'));
check('channel joinable again after uncqline', ChannelService::joinStatus(ChannelService::find('#matchme'), $erin)['ok'] === true);

// ── /sanick (admins + netadmin opers) ────────────────────────────────────────
echo "== sanick ==\n";
Database::query('UPDATE opers SET enabled = 1 WHERE username = "erin"');
$res = CommandParser::run('/oper erin erinsecret', $erin, $ch);
check('netadmin o:line works', str_contains($res['replies'][0] ?? '', 'netadmin'));
$res = CommandParser::run('/sanick nosuchuser whatever', $erin, $ch);
check('netadmin may use /sanick', !str_contains($res['replies'][0] ?? '', 'requires netadmin'));
$globalop = (int) Database::scalar('SELECT id FROM operclasses WHERE name = "globalop"');
Database::query('INSERT INTO opers (username, password_hash, operclass_id) VALUES ("frank", ?, ?)', [password_hash('franksecret', PASSWORD_ARGON2ID), $globalop]);
$res = CommandParser::run('/oper frank franksecret', $frank, $ch);
check('globalop o:line works', str_contains($res['replies'][0] ?? '', 'globalop'));
$res = CommandParser::run('/sanick erin zed', $frank, $ch);
check('sanick denied to non-netadmin oper', str_contains($res['replies'][0] ?? '', 'requires netadmin'), $res['replies'][0] ?? '');
CommandParser::run('/deoper', $frank, $ch);
$res = CommandParser::run('/sanick erin alice', $alice, $ch);
check('sanick exact error when nick is registered', $res['replies'][0] === 'Requested nick is unavailable, please select another', $res['replies'][0] ?? '');
Auth::loginGuest('guestheld', true);
$res = CommandParser::run('/sanick erin guestheld', $alice, $ch);
check('sanick exact error when nick held by a guest', $res['replies'][0] === 'Requested nick is unavailable, please select another', $res['replies'][0] ?? '');
Auth::logout();
$res = CommandParser::run('/sanick erin erina', $alice, $ch);
check('/sanick renames the user', str_contains($res['replies'][0] ?? '', 'now known as erina'), $res['replies'][0] ?? '');
check('sanick updates the registration', Database::scalar('SELECT id FROM users WHERE username = "erina"') == $erin['id']);
check('sanick notifies the renamed user (PM)', (int) Database::scalar("SELECT COUNT(*) FROM private_messages WHERE recipient_id = ? AND content LIKE '%erina%'", [$erin['id']]) > 0);
check('sanick posts a nick event in the channel', (int) Database::scalar("SELECT COUNT(*) FROM messages WHERE kind = 'nick' AND channel_id = ? AND content LIKE '%erina%'", [$ch['id']]) > 0);

// ── #oper-log (admin-only action log) ────────────────────────────────────────
echo "== oper-log ==\n";
$operLog = ChannelService::find('#oper-log');
check('#oper-log seeded as private', $operLog !== null && $operLog['visibility'] === 'private', json_encode($operLog));
check('regular user denied #oper-log', ChannelService::joinStatus($operLog, $bob)['ok'] === false);
check('admin allowed #oper-log', ChannelService::joinStatus($operLog, $alice)['ok'] === true);
$beforeLog = (int) Database::scalar('SELECT COUNT(*) FROM messages WHERE channel_id = ?', [$operLog['id']]);
Auth::login($alice);
CommandParser::run('/kline somefake 1h spam', $alice, $ch);
$afterLog = (int) Database::scalar('SELECT COUNT(*) FROM messages WHERE channel_id = ?', [$operLog['id']]);
check('admin action mirrored to #oper-log', $afterLog > $beforeLog, "$beforeLog -> $afterLog");
Auth::register('opx', 'opx@example.com', 'password123', true);
$opx = Auth::attempt('opx', 'password123');
Auth::register('kickme', 'kickme@example.com', 'password123', true);
$kickme = Auth::attempt('kickme', 'password123');
$opCh = ChannelService::create($opx, '#opxtest');
ChannelService::join($opCh, $kickme);
Auth::login($opx);
$beforeOp = (int) Database::scalar('SELECT COUNT(*) FROM messages WHERE channel_id = ?', [$operLog['id']]);
$res = CommandParser::run('/kick kickme time to go', $opx, $opCh);
check('chanop can kick in their channel', str_contains($res['replies'][0] ?? '', 'Kicked'), $res['replies'][0] ?? '');
$afterOp = (int) Database::scalar('SELECT COUNT(*) FROM messages WHERE channel_id = ?', [$operLog['id']]);
check('chanop action NOT mirrored to #oper-log', $afterOp === $beforeOp, "$beforeOp -> $afterOp");


// --- Audit / admin helpers ---
echo "== admin dashboard data ==\n";
check('audit log populated', (int) Database::scalar('SELECT COUNT(*) FROM audit_log') > 0);
check('notifications created', (int) Database::scalar('SELECT COUNT(*) FROM notifications') > 0);

// ── Client IP detection (reverse proxy aware) ────────────────────────────────
$origRem = $_SERVER['REMOTE_ADDR'] ?? null;
$origCf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
$origXr = $_SERVER['HTTP_X_REAL_IP'] ?? null;
$origXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
check('client_ip falls back to REMOTE_ADDR', client_ip() === '10.0.0.5');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.5';
check('client_ip uses X-Forwarded-For leftmost', client_ip() === '203.0.113.7');
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.3';
check('client_ip prefers X-Real-IP', client_ip() === '198.51.100.3');
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
check('client_ip prefers CF-Connecting-IP', client_ip() === '1.2.3.4');
// Invalid values are skipped, not trusted.
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.3';
check('client_ip skips invalid header values', client_ip() === '198.51.100.3');
if ($origRem !== null) { $_SERVER['REMOTE_ADDR'] = $origRem; } else { unset($_SERVER['REMOTE_ADDR']); }
foreach (['HTTP_CF_CONNECTING_IP' => $origCf, 'HTTP_X_REAL_IP' => $origXr, 'HTTP_X_FORWARDED_FOR' => $origXff] as $k => $v) {
    if ($v !== null) { $_SERVER[$k] = $v; } else { unset($_SERVER[$k]); }
}

// --- Webhooks ---
echo "== webhooks ==\n";
$wh = WebhookService::create((int) $ch['id'], $alice, 'Forum Bot');
check('webhook create returns token', $wh['ok'] === true && strlen($wh['token'] ?? '') >= 40, json_encode($wh));
$token = $wh['token'];
check('webhook found by token', WebhookService::findByToken($token) !== null);
check('webhook rejected for bad token', WebhookService::findByToken('short-token') === null);
check('webhook name validation', WebhookService::create((int) $ch['id'], $alice, '')['ok'] === false);
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_POST = ['content' => 'Hello from the forum!'];
$beforeHook = count(MessageService::history((int) $ch['id']));
$r = WebhookService::post($token);
check('webhook post succeeds', $r['ok'] === true, json_encode($r));
$histHook = MessageService::history((int) $ch['id']);
check('webhook message inserted', count($histHook) === $beforeHook + 1);
$lastHook = end($histHook);
check('webhook posts as bot', ($lastHook['bot'] ?? 0) === 1);
$rBad = WebhookService::post('nonexistenttoken00000000000000000000000000000000');
check('webhook unknown token rejected', $rBad['ok'] === false && ($rBad['status'] ?? 0) === 404);
$_POST = [];
$rEmpty = WebhookService::post($token);
check('webhook empty payload rejected', $rEmpty['ok'] === false);
unset($_POST);
Database::query('DELETE FROM webhooks WHERE id = (SELECT id FROM webhooks ORDER BY id DESC LIMIT 1)');

// ── Account invites + SMTP ───────────────────────────────────────────────────
echo "== invites & smtp ==\n";
$inv = InviteService::create('invitee@example.com', 'Welcome aboard!', (int) $alice['id']);
check('invite created with token + link', $inv['ok'] === true && strlen($inv['token'] ?? '') === 48 && str_contains($inv['link'] ?? '', '/register?invite='), json_encode($inv));
check('invite email not sent without SMTP', $inv['email_sent'] === false && $inv['error'] !== null, json_encode($inv));
$invRow = InviteService::valid($inv['token']);
check('unused invite validates', $invRow !== null && strtolower($invRow['email']) === 'invitee@example.com', json_encode($invRow));
check('duplicate invite for registered email rejected', InviteService::create('alice@example.com', '', (int) $alice['id'])['ok'] === false);
check('invite for invalid email rejected', InviteService::create('not-an-email', '', (int) $alice['id'])['ok'] === false);

$inv2 = InviteService::create('invitee2@example.com', '', (int) $alice['id']);
check('second invite ok', $inv2['ok'] === true, json_encode($inv2));
InviteService::claim((int) $inv2['id'], (int) $bob['id']);
check('used invite no longer validates', InviteService::valid($inv2['token']) === null);

$inv3 = InviteService::create('invitee3@example.com', '', (int) $alice['id']);
Database::query('UPDATE registration_invites SET expires_at = datetime("now", "-1 hour") WHERE id = ?', [(int) $inv3['id']]);
check('expired invite no longer validates', InviteService::valid($inv3['token']) === null);

$inv4 = InviteService::create('invitee4@example.com', '', (int) $alice['id']);
$oldToken = $inv4['token'];
$res = InviteService::resend((int) $inv4['id']);
check('resend rolls a new token', $res['ok'] === true && $res['token'] !== $oldToken && $res['email'] === 'invitee4@example.com', json_encode($res));
InviteService::revoke((int) $inv4['id']);
check('revoked invite removed', InviteService::row((int) $inv4['id']) === null);
check('invite list returns rows', count(InviteService::all()) >= 2);

// Admin-style manual creation with an auto-generated password.
$manualPw = bin2hex(random_bytes(8));
$rManual = Auth::register('manualuser', 'manual@example.com', $manualPw, true);
check('admin-style manual creation works', $rManual['ok'] === true && Auth::attempt('manualuser', $manualPw) !== null, json_encode($rManual));

// Mailer: graceful errors, never an uncaught exception.
check('Mailer::configured false by default', Mailer::configured() === false);
$noMail = Mailer::send('x@example.com', 't', 'body');
check('Mailer disabled returns graceful error', $noMail['ok'] === false && $noMail['error'] !== null);
config_set('smtp_enabled', '1');
config_set('smtp_host', '127.0.0.1');
config_set('smtp_port', '9'); // discard/refused port — connect fails instantly
config_set('smtp_from_email', 'test@example.com');
check('Mailer::configured true once set', Mailer::configured() === true);
$conn = Mailer::send('x@example.com', 't', 'body');
check('Mailer unreachable host returns graceful error', $conn['ok'] === false && $conn['error'] !== null, json_encode($conn));
config_set('smtp_from_email', '');
check('Mailer refuses to send without a from address', Mailer::send('x@example.com', 't', 'b')['ok'] === false);
config_set('smtp_enabled', '0');

// ── Auth tokens: password reset + magic links ───────────────────────────────
echo "== auth tokens ==\n";
$resetTok = AuthTokenService::create((int) $bob['id'], AuthTokenService::TYPE_RESET, AuthTokenService::RESET_TTL_MINUTES);
check('reset token created (64 hex chars)', strlen($resetTok) === 64 && ctype_xdigit($resetTok));
$vRow = AuthTokenService::validate($resetTok, AuthTokenService::TYPE_RESET);
check('reset token validates with user row', $vRow !== null && (int) $vRow['id'] === (int) $bob['id'] && !empty($vRow['token_id']), json_encode($vRow));
check('reset token rejected for magic type', AuthTokenService::validate($resetTok, AuthTokenService::TYPE_MAGIC) === null);
check('reset link has /reset-password/ path', str_contains(AuthTokenService::resetLink($resetTok), '/reset-password/'));
check('magic link has /magic/ path', str_contains(AuthTokenService::magicLink($resetTok), '/magic/'));
check('garbage token rejected', AuthTokenService::validate('not-a-token!!', AuthTokenService::TYPE_RESET) === null);
check('unknown hex token rejected', AuthTokenService::validate(bin2hex(random_bytes(32)), AuthTokenService::TYPE_RESET) === null);

// Claimed tokens cannot be replayed.
$tokId = (int) $vRow['token_id'];
AuthTokenService::claim($tokId);
check('claimed token no longer validates', AuthTokenService::validate($resetTok, AuthTokenService::TYPE_RESET) === null);
check('claim is idempotent (no error on re-claim)', (static function () use ($tokId): bool { AuthTokenService::claim($tokId); return true; })());

// Expired tokens are rejected.
$expTok = AuthTokenService::create((int) $bob['id'], AuthTokenService::TYPE_RESET, AuthTokenService::RESET_TTL_MINUTES);
Database::query('UPDATE auth_tokens SET expires_at = datetime("now", "-1 minute") WHERE token = ?', [$expTok]);
check('expired token rejected', AuthTokenService::validate($expTok, AuthTokenService::TYPE_RESET) === null);

// invalidateAllForUser kills outstanding unused tokens of that type only.
$mTok = AuthTokenService::create((int) $bob['id'], AuthTokenService::TYPE_MAGIC, AuthTokenService::MAGIC_TTL_MINUTES);
check('magic token validates', AuthTokenService::validate($mTok, AuthTokenService::TYPE_MAGIC) !== null);
AuthTokenService::invalidateAllForUser((int) $bob['id'], AuthTokenService::TYPE_MAGIC);
check('invalidateAllForUser kills unused magic tokens', AuthTokenService::validate($mTok, AuthTokenService::TYPE_MAGIC) === null);

// Full reset flow against the DB: hash updates + sessions die.
$rReset = Auth::register('resetme', 'resetme@example.com', 'oldpassword1', true);
check('register resetme', $rReset['ok'] === true, json_encode($rReset));
$resetme = Auth::attempt('resetme', 'oldpassword1');
$rrTok = AuthTokenService::create((int) $resetme['id'], AuthTokenService::TYPE_RESET, AuthTokenService::RESET_TTL_MINUTES);
$rrRow = AuthTokenService::validate($rrTok, AuthTokenService::TYPE_RESET);
Auth::login($resetme);
check('resetme has a live session', (int) Database::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [(int) $resetme['id']]) >= 1);
Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('newpassword1', PASSWORD_ARGON2ID), (int) $resetme['id']]);
AuthTokenService::claim((int) $rrRow['token_id']);
AuthTokenService::invalidateAllForUser((int) $resetme['id'], AuthTokenService::TYPE_RESET);
Auth::killSessions((int) $resetme['id'], true);
check('old password no longer works after reset', Auth::attempt('resetme', 'oldpassword1') === null);
check('new password works after reset', Auth::attempt('resetme', 'newpassword1') !== null);
check('reset token consumed (no replay)', AuthTokenService::validate($rrTok, AuthTokenService::TYPE_RESET) === null);
check('sessions killed after reset', (int) Database::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [(int) $resetme['id']]) === 0);
unset($_SESSION['token']);

// Password-reset + magic-link emails fail gracefully without SMTP.
$mailR = Mailer::sendPasswordReset('bob@example.com', 'bob', AuthTokenService::resetLink(bin2hex(random_bytes(32))));
check('reset email graceful without SMTP', $mailR['ok'] === false && $mailR['error'] !== null, json_encode($mailR));
$mailM = Mailer::sendMagicLink('bob@example.com', 'bob', AuthTokenService::magicLink(bin2hex(random_bytes(32))));
check('magic email graceful without SMTP', $mailM['ok'] === false && $mailM['error'] !== null, json_encode($mailM));

// ── Sound alerts (channel + DM audio) ────────────────────────────────────────
echo "== sound alerts ==\n";
$snds = SoundService::listAll();
check('default sounds seeded (>= 3)', count($snds) >= 3, json_encode($snds));
foreach ($snds as $s) {
    $abs = ROOT . '/public' . $s['file'];
    check('default sound file on disk: ' . $s['name'], file_exists($abs) && filesize($abs) > 1000, $abs);
}
$d1 = (int) $snds[0]['id'];
$d2 = (int) $snds[1]['id'];
$prefs0 = SoundService::prefsFor($alice);
check('default prefs are on (first sound)', $prefs0['dm_sound_id'] === $d1 && $prefs0['channel_sound_id'] === $d1, json_encode($prefs0));
check('guests inherit default prefs', SoundService::prefsFor(['id' => 999999, 'guest' => 1])['dm_sound_id'] === $d1);

SoundService::savePrefs($alice, null, $d2);
$prefs = SoundService::prefsFor($alice);
check('prefs saved (dm off, channel=sound 2)', $prefs['dm_sound_id'] === null && $prefs['channel_sound_id'] === $d2, json_encode($prefs));
SoundService::savePrefs($alice, $d1, null);
$prefs = SoundService::prefsFor($alice);
check('prefs update (dm back on)', $prefs['dm_sound_id'] === $d1 && $prefs['channel_sound_id'] === null, json_encode($prefs));

// Per-user overrides: mute bob, then a specific sound for mallory.
check('override set (mute bob)', SoundService::setOverride($alice, (int) $bob['id'], null) === true);
$o = SoundService::overrideFor($alice, (int) $bob['id']);
check('override mutes bob', $o['override'] === true && $o['sound_id'] === null, json_encode($o));
check('override set (specific sound for mallory)', SoundService::setOverride($alice, (int) $mallory['id'], $d1) === true);
$o = SoundService::overrideFor($alice, (int) $mallory['id']);
check('override uses specific sound', $o['override'] === true && $o['sound_id'] === $d1, json_encode($o));
$o = SoundService::overrideFor($alice, 999999);
check('no override falls back to default', $o['override'] === false, json_encode($o));
check('override for unknown user rejected', is_string(SoundService::setOverride($alice, 999999, $d1)));
check('override for self rejected', is_string(SoundService::setOverride($alice, (int) $alice['id'], $d1)));
SoundService::removeOverride($alice, (int) $bob['id']);
check('override removed reverts to default', SoundService::overrideFor($alice, (int) $bob['id'])['override'] === false);
check('override list contains mallory', isset(SoundService::overrides($alice)[(int) $mallory['id']]));

$client = SoundService::soundsForClient($alice);
check('client payload bundles sounds + prefs + overrides',
    isset($client['sounds'][$d1]) && $client['dm_sound_id'] === $d1 && $client['channel_sound_id'] === null
    && isset($client['overrides'][(int) $mallory['id']]) && $client['overrides'][(int) $mallory['id']] === $d1,
    json_encode($client));

// Toggle hides a sound from user pickers; the file stays until deletion.
$enabledBefore = count(SoundService::listEnabled());
check('sound toggle disables', SoundService::toggle($d1) === true);
check('disabled sound leaves user pickers', count(SoundService::listEnabled()) === $enabledBefore - 1);
SoundService::toggle($d1);
check('sound re-enabled', count(SoundService::listEnabled()) === $enabledBefore);
check('admin add rejects a missing file', SoundService::add(['name' => 'x.wav', 'tmp_name' => '/dev/null', 'error' => UPLOAD_ERR_NO_FILE], 'Test')['ok'] === false);

// backgroundSince drives background-channel audio: other channels surface, the
// channel being viewed is excluded, and system kinds never ping.
$ch2 = ChannelService::create($alice, '#bgtest');
ChannelService::join($ch2, $bob);
MessageService::send((int) $ch2['id'], $bob, 'bg hello');
$bg = MessageService::backgroundSince($alice, 0, (int) $ch['id']);
check('backgroundSince surfaces other-channel messages', in_array('bg hello', array_column($bg, 'content'), true), json_encode($bg));
$bgEx = MessageService::backgroundSince($alice, 0, (int) $ch2['id']);
check('backgroundSince excludes the open channel', !in_array('bg hello', array_column($bgEx, 'content'), true));
$bgKinds = MessageService::backgroundSince($alice, 0, 0);
check('backgroundSince filters system kinds', !array_intersect(array_column($bgKinds, 'kind'), MessageService::SYSTEM_KINDS));
ChannelService::drop((string) $ch2['id']);

// ── Age verification ─────────────────────────────────────────────────────────
echo "== age verification ==\n";
$under = Auth::register('minor', 'minor@example.com', 'password123', false);
check('registration without age certification rejected', $under['ok'] === false && str_contains(implode(' ', $under['errors']), '18'), json_encode($under));
check('guests cannot join without age', Auth::loginGuest('minorguest', false) === null);
check('guests can join with age', Auth::loginGuest('ageguest', true) !== null);

// ── Moderation queue (filter hits + account actions) ─────────────────────────
echo "== moderation queue ==\n";
$modCh = ChannelService::find('#test') ?: ChannelService::create($alice, '#test');
Database::query('DELETE FROM moderation_events');
Database::query('INSERT INTO spamfilters (match_type, targets, action, match) VALUES ("simple", "c", "block", "zzfilterword")');
$blocked = BanService::sendBlocked($bob, 'zzfilterword', 'c');
check('spamfilter block returns error', $blocked !== null && str_contains($blocked, 'spam filter'), (string) $blocked);
check('spamfilter hit recorded', (int) Database::scalar('SELECT COUNT(*) FROM moderation_events WHERE kind = "spamfilter" AND user_id = ?', [$bob['id']]) === 1);
Database::query('DELETE FROM spamfilters WHERE match = "zzfilterword"');

Database::query('INSERT INTO badwords (word, action) VALUES ("zzquux", "censor")');
$censor = CensorService::check('say zzquux', true);
check('badword censor detected', $censor !== null && $censor['action'] === 'censor');
ModerationService::record($bob, 'badword', $censor['action'], $censor['word'], 'say zzquux', 'c', (int) $modCh['id']);
check('badword event recorded', (int) Database::scalar('SELECT COUNT(*) FROM moderation_events WHERE kind = "badword" AND user_id = ?', [$bob['id']]) === 1);
Database::query('DELETE FROM badwords WHERE word = "zzquux"');

if (!AccessService::member($modCh['id'], (int) $bob['id'])) {
    ChannelService::join($modCh, $bob);
}
CommandParser::run('/kick bob_the_second test kick reason', $alice, $modCh);
check('/kick records moderation event', (int) Database::scalar('SELECT COUNT(*) FROM moderation_events WHERE kind = "kick" AND user_id = ?', [$bob['id']]) >= 1);
check('/kick records staff note', (int) Database::scalar('SELECT COUNT(*) FROM user_notes WHERE user_id = ? AND action = "kick"', [$bob['id']]) >= 1);
ChannelService::join($modCh, $bob);
CommandParser::run('/ban bob_the_second 1h test ban', $alice, $modCh);
check('/ban records staff note', (int) Database::scalar('SELECT COUNT(*) FROM user_notes WHERE user_id = ? AND action = "ban"', [$bob['id']]) >= 1);
CommandParser::run('/unban bob_the_second', $alice, $modCh);

// ── Pending / Suspended status ───────────────────────────────────────────────
echo "== account status ==\n";
ModerationService::setStatus((int) $mallory['id'], 'suspended', 'test suspension', $alice);
$mRow = Database::row('SELECT * FROM users WHERE id = ?', [$mallory['id']]);
check('suspend sets status + reason', ($mRow['status'] ?? '') === 'suspended' && $mRow['status_reason'] === 'test suspension');
check('suspend writes note', (int) Database::scalar('SELECT COUNT(*) FROM user_notes WHERE user_id = ? AND action = "suspend"', [$mallory['id']]) === 1);
$restr = ModerationService::restriction($mRow);
check('suspended restriction message', $restr !== null && str_contains($restr, 'suspended'), (string) $restr);
check('history lists timeline entries', count(ModerationService::history((int) $mallory['id'])) >= 1);
ModerationService::setStatus((int) $mallory['id'], 'active', null, $alice);
check('activate clears status', (Database::row('SELECT * FROM users WHERE id = ?', [$mallory['id']])['status'] ?? '') === 'active');

config_set('registration_requires_approval', '1');
$penny = Auth::register('penny', 'penny@example.com', 'password123', true);
$pennyRow = Database::row('SELECT * FROM users WHERE id = ?', [$penny['id']]);
check('approval toggle makes new accounts pending', ($pennyRow['status'] ?? '') === 'pending');
$restr = ModerationService::restriction($pennyRow);
check('pending restriction blocks chat', $restr !== null && str_contains($restr, 'pending'), (string) $restr);
check('guests are never restricted', ModerationService::restriction($guest) === null);
ModerationService::setStatus((int) $penny['id'], 'active', null, $alice);
check('approve enables chat', ModerationService::restriction(Database::row('SELECT * FROM users WHERE id = ?', [$penny['id']])) === null);
config_set('registration_requires_approval', '0');

// ── Support tickets ──────────────────────────────────────────────────────────
echo "== support tickets ==\n";
$t = SupportService::create($bob, 'Need help with channels', 'I cannot create a channel.');
check('support ticket created', $t['ok'] === true && $t['id'] > 0, json_encode($t));
$tk = SupportService::get($t['id']);
check('ticket open for owner', (string) $tk['status'] === 'open' && (int) $tk['user_id'] === (int) $bob['id']);
$rep = SupportService::reply((int) $t['id'], $alice, 'Thanks for letting us know.');
check('staff reply succeeds', $rep['ok'] === true, json_encode($rep));
$tk = SupportService::get($t['id']);
check('staff reply marks answered', (string) $tk['status'] === 'answered');
check('replies stored', count(SupportService::replies((int) $t['id'])) === 2);
check('owner cannot view someone else', SupportService::canView(SupportService::get($t['id']), $mallory) === false);
check('close ticket', SupportService::setStatus((int) $t['id'], 'closed', $alice)['ok'] === true);
check('ticket closed', (SupportService::get((int) $t['id'])['status'] ?? '') === 'closed');

// Staff-created tickets for an email address + assignment.
$te = SupportService::createStaff(null, 'customer@ext.com', (int) $alice['id'], 'Email-only ticket', 'Please help', null);
check('staff creates email ticket', $te['ok'] === true && $te['id'] > 0, json_encode($te));
$teRow = SupportService::get($te['id']);
check('email ticket stores email', $teRow['email'] === 'customer@ext.com' && $teRow['user_id'] === null);
check('email ticket emails back to address', SupportService::contactEmail($teRow) === 'customer@ext.com');
check('staff assign ticket', SupportService::assign((int) $te['id'], (int) $alice['id'])['ok'] === true);
check('assignment persisted', (int) SupportService::get($te['id'])['assigned_to'] === (int) $alice['id']);
check('staff can unassign', SupportService::assign((int) $te['id'], null)['ok'] === true);
check('unassigned persists', SupportService::get($te['id'])['assigned_to'] === null);
// Staff create for a user id (bob).
$tu = SupportService::createStaff((int) $bob['id'], '', (int) $alice['id'], 'Staff opened for bob', 'Body', null);
check('staff creates user ticket', $tu['ok'] === true && (int) SupportService::get($tu['id'])['user_id'] === (int) $bob['id']);
// Email that matches a registered user auto-links.
$bobEmail = (string) Database::scalar('SELECT email FROM users WHERE id = ?', [(int) $bob['id']]);
$tl = SupportService::createStaff(null, strtoupper($bobEmail), (int) $alice['id'], 'Auto-link', 'Body', null);
check('email matching user auto-links', (int) SupportService::get($tl['id'])['user_id'] === (int) $bob['id']);
check('invalid staff ticket rejected', SupportService::createStaff(null, '', (int) $alice['id'], 'x', 'y', null)['ok'] === false);
check('bad email rejected', SupportService::createStaff(null, 'not-an-email', (int) $alice['id'], 'x', 'y', null)['ok'] === false);
check('staff list returns admins+staff', count(SupportService::staff()) >= 1);

// ── Legal pages (ToS / Privacy) ──────────────────────────────────────────────
echo "== legal pages ==\n";
$terms = LegalService::get('terms');
check('legal boilerplate generated', str_contains($terms, 'Terms of Service') && str_contains($terms, 'Nevada'));
$clean = LegalService::sanitize('<p>Hello <script>alert(1)</script></p><p onclick="x()">ok</p><a href="javascript:evil()">x</a>');
check('legal sanitizer strips script/events/js', !str_contains($clean, 'script') && !str_contains($clean, 'onclick') && !str_contains($clean, 'javascript:'), $clean);
// Kitchen-sink: tables, task lists, images, colors, sub/sup, headings.
$ks = LegalService::sanitize(
    '<h3 style="color: red; position: fixed">Head</h3>'
    . '<table><thead><tr><th colspan="2">A</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'
    . '<ul data-type="taskList"><li data-type="taskItem" data-checked="true"><label><input type="checkbox" checked><span>Do</span></label></li></ul>'
    . '<img src="https://x.com/i.png" onerror="x()" alt="pic">'
    . '<p>a<sub>1</sub> b<sup>2</sup> <mark>hl</mark></p>'
);
check('kitchen-sink keeps table', str_contains($ks, '<table>') && str_contains($ks, '<th colspan="2">'));
check('kitchen-sink keeps task list', str_contains($ks, 'type="checkbox"') && str_contains($ks, 'data-type="taskItem"'));
check('kitchen-sink keeps image', str_contains($ks, '<img src="https://x.com/i.png"'));
check('kitchen-sink strips img onerror', !str_contains($ks, 'onerror'));
check('kitchen-sink keeps sub/sup/mark', str_contains($ks, '<sub>') && str_contains($ks, '<sup>') && str_contains($ks, '<mark>'));
check('kitchen-sink keeps safe color, strips position', str_contains($ks, 'color: red') && !str_contains($ks, 'position'));
LegalService::save('terms', '<h1>Custom terms</h1><p>Body</p>');
check('legal save/get round-trip', str_contains(LegalService::get('terms'), 'Custom terms'));

// ── Themes (presets + per-user/server precedence) ────────────────────────────
echo "== themes ==\n";
$presets = ThemeService::presets();
check('preset library has 75 combos', count($presets) === 75, (string) count($presets));
$ids = array_column($presets, 'id');
check('preset ids unique', count($ids) === count(array_unique($ids)));
check('default preset is midnight', ThemeService::presetId('') === 'midnight' && ThemeService::presetId('nord') === 'nord');
check('unknown preset falls back', ThemeService::presetId('bogus') === 'midnight');
foreach (['midnight', 'nord', 'dracula', 'cyberpunk', 'mono'] as $pid) {
    $css = ThemeService::cssFor(['preset' => $pid, 'mode' => 'dark']);
    $ok = str_contains($css, '--c-blurple:') && str_contains($css, '--c-d-950:')
        && str_contains($css, '--c-sidebar:') && str_contains($css, 'html.light')
        && str_contains($css, '--font-sans:');
    check("preset $pid emits full palette", $ok);
}
check('cssFor validates hex/urls', ThemeService::hex('ff0000') === '#ff0000' && ThemeService::hex('zzz') === '' && ThemeService::hex('#abc') === '#aabbcc');
check('localPath rejects traversal', ThemeService::localPath('/ok/x.png') === '/ok/x.png' && ThemeService::localPath('https://x/y.png') === '' && ThemeService::localPath('/../etc') === '');
check('invalid overrides dropped', empty(ThemeService::normalize(['preset' => 'nord', 'overrides' => ['accent' => 'notacolor', 'font' => 'bogus', 'sidebar' => '#112233']])['overrides']['accent']));

config_set('theme', json_encode(['preset' => 'dracula', 'mode' => 'light']));
$noUser = ThemeService::resolve(null);
check('no user -> server theme', $noUser['preset'] === 'dracula' && $noUser['mode'] === 'light');
$plain = ThemeService::resolve(['id' => 9001, 'theme' => '', 'theme_json' => null]);
check('registered w/o theme -> server theme', $plain['preset'] === 'dracula' && $plain['mode'] === 'light');
$legacy = ThemeService::resolve(['id' => 9002, 'theme' => 'dark', 'theme_json' => null]);
check('legacy theme column sets mode', $legacy['mode'] === 'dark' && $legacy['preset'] === 'dracula');
$personal = ThemeService::resolve(['id' => 9003, 'theme' => 'dark', 'theme_json' => json_encode(['preset' => 'nord', 'overrides' => ['font' => 'mono']])]);
check('personal theme wins', $personal['preset'] === 'nord' && $personal['overrides']['font'] === 'mono' && $personal['mode'] === 'dark');
$personalLight = ThemeService::resolve(['id' => 9004, 'theme' => 'dark', 'theme_json' => json_encode(['preset' => 'nord', 'mode' => 'light', 'overrides' => []])]);
check('personal mode wins over toggle', $personalLight['mode'] === 'light');
config_set('theme_user_customization', '0');
$disabled = ThemeService::resolve(['id' => 9005, 'theme' => 'light', 'theme_json' => json_encode(['preset' => 'forest', 'overrides' => ['font' => 'serif']])]);
check('kill-switch forces server theme', $disabled['preset'] === 'dracula' && $disabled['mode'] === 'light');
config_set('theme_user_customization', '1');
check('customization re-enabled', ThemeService::customizationEnabled() === true);

$bg = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight', 'mode' => 'dark', 'overrides' => ['chat_bg_color' => '#123456', 'chat_bg_image' => '/assets/themes/x.webp', 'chat_bg_fit' => 'cover']]));
check('chat bg css has color + image + overlay', str_contains($bg, '#123456') && str_contains($bg, 'theme-bg-image') && str_contains($bg, 'url(\'/assets/themes/x.webp\')'));
check('overlay defaults to 55% black in dark', str_contains($bg, 'rgba(0,0,0,0.55)'));
$bgLight = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight', 'mode' => 'light', 'overrides' => ['chat_bg_image' => '/assets/themes/x.webp']]));
check('overlay is white in light mode', str_contains($bgLight, 'rgba(255,255,255,0.55)'));
$bgNone = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight', 'overrides' => ['chat_bg_image' => '/assets/themes/x.webp', 'chat_bg_overlay' => 0]]));
check('overlay 0 disables the layer', !str_contains($bgNone, 'linear-gradient') && str_contains($bgNone, "url('/assets/themes/x.webp')"));
$bgMax = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight', 'overrides' => ['chat_bg_image' => '/assets/themes/x.webp', 'chat_bg_overlay' => 100]]));
check('overlay 100 is fully opaque', str_contains($bgMax, 'rgba(0,0,0,1.00)'));
check('overlay >100 falls back to default', (int) ThemeService::render(['preset' => 'midnight', 'overrides' => ['chat_bg_overlay' => 500]])['chat_bg_overlay'] === ThemeService::CHAT_BG_OVERLAY_DEFAULT);
$chan = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight']), ['bg_color' => '#abcdef', 'bg_image' => '/assets/themes/c.webp']);
check('channel bg overrides theme bg', str_contains($chan, '#abcdef') && str_contains($chan, 'c.webp') && !str_contains($chan, '123456'));
check('channel bg defaults to contain', str_contains($chan, 'background-size:contain;') && !str_contains($chan, 'background-size:cover;'));
check('channel bg overlay defaults to 55', str_contains($chan, 'rgba(0,0,0,0.55)'));
$chanOverlay = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight']), ['bg_color' => '#abcdef', 'bg_image' => '/assets/themes/c.webp', 'bg_overlay' => 25]);
check('channel bg_overlay honoured', str_contains($chanOverlay, 'rgba(0,0,0,0.25)') && !str_contains($chanOverlay, '0.55'));
$chanCover = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight']), ['bg_color' => '#abcdef', 'bg_image' => '/assets/themes/c.webp', 'bg_fit' => 'cover']);
check('channel bg_fit cover honoured', str_contains($chanCover, 'background-size:cover;'));
$themeFit = ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight', 'overrides' => ['chat_bg_image' => '/assets/themes/t.webp']]));
check('theme bg defaults to contain', str_contains($themeFit, 'background-size:contain;') && !str_contains($themeFit, 'background-size:cover;'));
check('no bg -> no image rules', ThemeService::chatBgCss(ThemeService::render(['preset' => 'midnight'])) === '#messages{}');
check('manifest colors derive from server theme', str_starts_with(ThemeService::manifestColors()['theme_color'], '#'));

// ── Modules system ──────────────────────────────────────────────────────────
echo "== modules ==\n";
check('good-module discovered + booted', ModuleLoader::get('good-module') !== null);
check('good-module enabled() contains it', in_array('good-module', ModuleLoader::enabled(), true));
check('good-module command registered', CommandRegistry::get('goodmod') !== null);
$res = CommandParser::run('/goodmod', ['id' => 1, 'role' => 'user'], null);
check('good-module command runs', ($res['replies'][0] ?? '') === 'good-module says hi', json_encode($res));
check('good-module settings seeded', config_get('good_setting') === 'seed_value');
check('good-module schema table exists', Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='good_module_items'") === 1);
check('good-module admin nav entry', isset(ModuleLoader::adminNav()['good-module-settings']) && ModuleLoader::adminNav()['good-module-settings']['url'] === '/admin/good-module');
check('good-module service class loads', class_exists('GoodModuleService'));
check('.disabled module not loaded', ModuleLoader::get('disabled-mod') === null);
check('missing-manifest module ignored', ModuleLoader::get('invalid-module') === null);
check('malformed manifest ignored', ModuleLoader::get('bad-json') === null);
check('requires mismatch module skipped', ModuleLoader::get('old-module') === null);
check('old-module init.php not run', CommandRegistry::get('oldcmd') === null);
check('broken-mod loaded (valid manifest) despite boot throw', ModuleLoader::get('broken-mod') !== null);
check('broken-mod throw caught with a boot warning', in_array(true, array_map(static fn (string $w): bool => str_contains($w, "Module 'broken-mod' failed to boot"), ModuleLoader::warnings()), true), implode(' | ', ModuleLoader::warnings()));
check('app keeps running after a module boot failure', ModuleLoader::get('good-module') !== null && CommandRegistry::get('goodmod') !== null);
check('warnings recorded for bad manifests + requires', count(ModuleLoader::warnings()) >= 4, implode(' | ', ModuleLoader::warnings()));
$dbRow = Database::row('SELECT enabled FROM modules WHERE id = ?', ['good-module']);
check('good-module DB row enabled', $dbRow !== null && (int) $dbRow['enabled'] === 1);
check('.disabled module keeps its DB row', Database::row('SELECT id FROM modules WHERE id = ?', ['disabled-mod']) !== null);
check('removed module pruned', Database::scalar('SELECT COUNT(*) FROM modules WHERE id = ?', ['ghost-module']) === 0);
check('isLicensed true for free module', ModuleLoader::isLicensed('good-module') === true);
$saved = config_get('good_setting', 'missing');
config_set('good_setting', 'admin_value');
ModuleLoader::reset();
ModuleLoader::boot();
check('re-boot does not overwrite admin-set settings', config_get('good_setting') === 'admin_value');
ModuleLoader::reset();
ModuleLoader::boot();
config_set('good_setting', $saved);

// Empty / missing modules directory is a silent no-op.
$emptyDir = sys_get_temp_dir() . '/opencode-empty-modules';
@mkdir($emptyDir);
file_put_contents($emptyDir . '/.gitkeep', '');
ModuleLoader::reset();
putenv('CHAT_MODULES=' . $emptyDir);
ModuleLoader::boot();
check('empty modules dir is a silent no-op', ModuleLoader::all() === [] && ModuleLoader::warnings() === [] && ModuleLoader::enabled() === []);
$missingDir = sys_get_temp_dir() . '/opencode-no-such-modules-dir';
ModuleLoader::reset();
putenv('CHAT_MODULES=' . $missingDir);
ModuleLoader::boot();
check('missing modules dir is a silent no-op', ModuleLoader::all() === [] && ModuleLoader::warnings() === []);
ModuleLoader::reset();
putenv('CHAT_MODULES=' . __DIR__ . '/fixtures/modules');
ModuleLoader::boot();
check('restore fixture modules', ModuleLoader::get('good-module') !== null);

// ── Module lifecycle: load / unload / disable (.disabled rename) ───────────
function lc_rrmdir(string $dir): void {
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) lc_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}
function lc_rcopy(string $src, string $dst): void {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0775, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $s = $src . '/' . $e;
        $d = $dst . '/' . $e;
        if (is_dir($s)) lc_rcopy($s, $d); else copy($s, $d);
    }
}

// dirExists() resolves both the active and the .disabled form of a module.
check('dirExists true for active module', ModuleLoader::dirExists('good-module') === true);
check('dirExists true for .disabled-only module', ModuleLoader::dirExists('disabled-mod') === true);
check('dirExists false for absent module', ModuleLoader::dirExists('ghost-module') === false);
// The fixture .disabled module is enabled=1 in the DB yet never loads: the hard
// .disabled rename beats the soft enabled flag.
check('hard .disabled beats soft enabled flag',
    (int) Database::scalar('SELECT enabled FROM modules WHERE id = ?', ['disabled-mod']) === 1
        && ModuleLoader::get('disabled-mod') === null);

// Full rename lifecycle on a throwaway copy of good-module.
$lc = sys_get_temp_dir() . '/opencode-module-lifecycle';
if (is_dir($lc)) lc_rrmdir($lc);
mkdir($lc, 0775, true);
lc_rcopy(__DIR__ . '/fixtures/modules/good-module', $lc . '/good-module');
ModuleLoader::reset();
putenv('CHAT_MODULES=' . $lc);
ModuleLoader::boot();
check('lifecycle module loads from temp dir', ModuleLoader::get('good-module') !== null && CommandRegistry::get('goodmod') !== null);
config_set('good_setting', 'lifecycle_admin_value');

// Unload: rename good-module -> good-module.disabled.
if (!rename($lc . '/good-module', $lc . '/good-module.disabled')) {
    check('lifecycle rename to .disabled', false, 'rename() failed');
} else {
    ModuleLoader::reset();
    ModuleLoader::boot();
    check('module unloaded after .disabled rename', ModuleLoader::get('good-module') === null && !in_array('good-module', ModuleLoader::enabled(), true));
    check('renamed module keeps its DB row', Database::row('SELECT id FROM modules WHERE id = ?', ['good-module']) !== null);
    check('renamed module keeps its enabled flag', (int) Database::scalar('SELECT enabled FROM modules WHERE id = ?', ['good-module']) === 1);
    check('dirExists still true while renamed', ModuleLoader::dirExists('good-module') === true);

    // Reload: rename back.
    if (!rename($lc . '/good-module.disabled', $lc . '/good-module')) {
        check('lifecycle rename back', false, 'rename() failed');
    } else {
        ModuleLoader::reset();
        ModuleLoader::boot();
        check('module reloads after rename back', ModuleLoader::get('good-module') !== null && CommandRegistry::get('goodmod') !== null);
        check('reload keeps admin-set settings', config_get('good_setting') === 'lifecycle_admin_value');
        check('dirExists true again after rename back', ModuleLoader::dirExists('good-module') === true);
    }
}

// Dependency gating: a module whose requires.modules dependency is soft-disabled
// is skipped; enabling the dependency lets both load.
@mkdir($lc . '/dep-child', 0775, true);
file_put_contents($lc . '/dep-child/module.json', json_encode(['id' => 'dep-child', 'name' => 'Dep Child', 'version' => '1.0.0'], JSON_PRETTY_PRINT));
@mkdir($lc . '/dep-parent', 0775, true);
file_put_contents($lc . '/dep-parent/module.json', json_encode([
    'id' => 'dep-parent', 'name' => 'Dep Parent', 'version' => '1.0.0',
    'requires' => ['modules' => ['dep-child']],
], JSON_PRETTY_PRINT));
ModuleLoader::reset();
ModuleLoader::boot();
check('dep-child loads by default', ModuleLoader::get('dep-child') !== null);
check('dep-parent loads when dependency enabled', ModuleLoader::get('dep-parent') !== null);
// Soft-disable the dependency in the DB, re-boot → the parent is skipped.
Database::query('UPDATE modules SET enabled = 0 WHERE id = ?', ['dep-child']);
ModuleLoader::reset();
ModuleLoader::boot();
check('dep-child soft-disabled', ModuleLoader::get('dep-child') === null);
check('dep-parent skipped while dependency disabled', ModuleLoader::get('dep-parent') === null);
check('unmet module dependency produces a warning',
    in_array(true, array_map(static fn (string $w): bool => str_contains($w, "requires module 'dep-child' enabled"), ModuleLoader::warnings()), true),
    implode(' | ', ModuleLoader::warnings()));

// Cleanup + restore the fixture modules dir.
lc_rrmdir($lc);
ModuleLoader::reset();
putenv('CHAT_MODULES=' . __DIR__ . '/fixtures/modules');
ModuleLoader::boot();
check('restore fixture modules after lifecycle', ModuleLoader::get('good-module') !== null);

// ── Licensing: offline Ed25519 key algorithm ─────────────────────────────────
echo "== license keys ==\n";
$lsk = $GLOBALS['license_test_sk'];
$lmk = static fn (array $c): string => LicenseKeys::generate($c, $lsk);
$future = date('Y-m-d', strtotime('+30 days'));
$key = $lmk(['mod' => 'premium-badges', 'type' => 'pro', 'holder' => 'Acme', 'exp' => $future, 'act' => 3, 'iss' => date('Y-m-d')]);
check('key format', (bool) preg_match('#^LVC-1-premium-badges-[A-Z2-7]+-[A-Z2-7]+$#', $key), $key);
$v = LicenseKeys::verify($key, 'premium-badges');
check('valid key verifies', $v['ok'] === true, $v['reason'] ?? '');
check('claims roundtrip', ($v['claims']['type'] ?? '') === 'pro' && ($v['claims']['mod'] ?? '') === 'premium-badges');
check('wrong module rejected offline', LicenseKeys::verify($key, 'other-module')['ok'] === false);
check('tampered signature rejected', LicenseKeys::verify(substr_replace($key, 'A', -3), 'premium-badges')['ok'] === false);
check('tampered payload rejected', LicenseKeys::verify(substr_replace($key, 'Q', 40, 1), 'premium-badges')['ok'] === false);
check('expired key rejected', LicenseKeys::verify($lmk(['mod' => 'premium-badges', 'exp' => date('Y-m-d', strtotime('-1 day'))]), 'premium-badges')['ok'] === false);
$fv = LicenseKeys::verify($lmk(['mod' => 'premium-badges', 'exp' => '']), 'premium-badges');
check('forever (lifetime) key verifies', $fv['ok'] === true);
check('malformed key rejected', LicenseKeys::verify('not-a-key', 'x')['ok'] === false);
check('unsupported version rejected', LicenseKeys::verify('LVC-9-m-x-x', 'm')['ok'] === false);
check('batch keys are unique', $lmk(['mod' => 'm']) !== $lmk(['mod' => 'm']));
check('base32 roundtrip', LicenseKeys::b32decode(LicenseKeys::b32encode('the quick brown fox')) === 'the quick brown fox');
$otherSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(random_bytes(32)));
check('forged signature (wrong key) rejected', LicenseKeys::verify(LicenseKeys::generate(['mod' => 'premium-badges'], $otherSk), 'premium-badges')['ok'] === false);

// ── Licensing: paid module boot integration + client policy ─────────────────
echo "== licensing client ==\n";
check('paid-mod discovered', ModuleLoader::get('paid-mod') !== null);
check('paid-mod command registered', CommandRegistry::get('paidcmd') !== null);
$row = Database::row('SELECT license_status FROM modules WHERE id = ?', ['paid-mod']);
check('paid-mod boot recorded no_key status', ($row['license_status'] ?? '') === 'no_key', json_encode($row));
check('paid-mod isLicensed false without key', ModuleLoader::isLicensed('paid-mod') === false);
$res = CommandParser::run('/paidcmd', ['id' => 1, 'role' => 'user'], null);
check('paid-mod feature gated without license', str_contains($res['replies'][0] ?? '', 'requires a paid-mod license'), json_encode($res));
check('free module isLicensed true', ModuleLoader::isLicensed('good-module') === true);

config_set('license_policy', 'offline');
config_set('license_url', '');
config_set('license_recheck_hours', '24');
config_set('license_grace_days', '7');
$r = LicensingService::validate('paid-mod', '');
check('empty key -> no_key', $r['status'] === 'no_key');
$paidKey = $lmk(['mod' => 'paid-mod', 'type' => 'pro', 'exp' => $future, 'act' => 1, 'iss' => date('Y-m-d')]);
Database::query('UPDATE modules SET license = ? WHERE id = ?', [$paidKey, 'paid-mod']);
$r = LicensingService::validate('paid-mod', $paidKey);
check('offline policy: internal check is the gate', $r['status'] === 'valid' && !empty($r['offline']));
check('isLicensed true after valid key', ModuleLoader::isLicensed('paid-mod') === true);
$res = CommandParser::run('/paidcmd', ['id' => 1, 'role' => 'user'], null);
check('paid-mod feature active when licensed', str_contains($res['replies'][0] ?? '', 'premium feature active'), json_encode($res));
check('wrong_module offline status', LicensingService::validate('paid-mod', $lmk(['mod' => 'other', 'exp' => $future]))['status'] === 'wrong_module');
check('expired offline status', LicensingService::validate('paid-mod', $lmk(['mod' => 'paid-mod', 'exp' => date('Y-m-d', strtotime('-1 day'))]))['status'] === 'expired');
check('malformed offline status', LicensingService::validate('paid-mod', 'garbage')['status'] === 'malformed');

// Grace policy against an unreachable license server (closed port).
config_set('license_policy', 'grace');
config_set('license_url', 'http://127.0.0.1:1');
$gk = $lmk(['mod' => 'grace-mod', 'exp' => $future, 'act' => 1, 'iss' => date('Y-m-d')]);
Database::query("INSERT INTO modules (id, name, version, enabled, license) VALUES ('grace-mod', 'Grace', '1.0.0', 1, ?)", [$gk]);
$r = LicensingService::validate('grace-mod', $gk);
check('grace: first unreachable check allowed', $r['status'] === 'valid' && !empty($r['grace']));
check('grace: status recorded as unvalidated', Database::scalar('SELECT license_status FROM modules WHERE id = ?', ['grace-mod']) === 'unvalidated');
$r = LicensingService::validate('grace-mod', $gk);
check('grace: no repeat dial within window', $r['status'] === 'valid' && !empty($r['grace']));
Database::query('UPDATE modules SET license_checked_at = datetime("now", "-8 days") WHERE id = ?', ['grace-mod']);
$r = LicensingService::validate('grace-mod', $gk);
check('grace: never-confirmed key stops after window', $r['status'] === 'unreachable_grace');
Database::query("UPDATE modules SET license_status = 'valid', license_checked_at = datetime('now', '-25 hours') WHERE id = ?", ['grace-mod']);
$r = LicensingService::validate('grace-mod', $gk);
check('grace: last-known-good keeps working when unreachable', $r['status'] === 'valid' && !empty($r['grace']));

// Strict policy: unreachable means refuse immediately.
config_set('license_policy', 'strict');
$stk = $lmk(['mod' => 'strict-mod', 'exp' => $future, 'iss' => date('Y-m-d')]);
Database::query("INSERT INTO modules (id, name, version, enabled, license) VALUES ('strict-mod', 'Strict', '1.0.0', 1, ?)", [$stk]);
$r = LicensingService::validate('strict-mod', $stk);
check('strict: unreachable refuses', $r['status'] === 'unreachable_strict');
config_set('license_policy', 'grace');

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
exit($GLOBALS['failed'] > 0 ? 1 : 0);

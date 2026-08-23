<?php
/**
 * Voice/video browser test setup script.
 * Registers users, configures voice, and verifies everything works.
 */
$PORT = 8098;
$DB = '/tmp/opencode/pw-test.db';
$BASE = "http://127.0.0.1:$PORT";

function req(string $method, string $path, array $data = [], ?string $cookieFile = null): array {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8];
    if ($cookieFile) { $opts[CURLOPT_COOKIEJAR] = $cookieFile; $opts[CURLOPT_COOKIEFILE] = $cookieFile; }
    if (strtoupper($method) === 'POST') {
        if (!array_key_exists('ajax', $data)) $data['ajax'] = '1';
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = substr($raw, strpos($raw, "\r\n\r\n") !== false ? strpos($raw, "\r\n\r\n") + 4 : 0);
    return [$status, $body];
}

function csrf(string $html): string {
    if (preg_match('/data-csrf="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

// First hit the server to trigger schema creation
$ck = '/tmp/opencode/pw-c.txt';
req('GET', '/register', [], $ck);

$db = new PDO("sqlite:$DB");
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Disable admin MFA so registration doesn't redirect to /login/mfa/setup
$db->exec("UPDATE server_config SET value = '0' WHERE key = 'mfa_require_admin'");
echo "MFA disabled\n";

// Register alice
[$s, $b] = req('GET', '/register', [], $ck);
$t = csrf($b);
[$s] = req('POST', '/register', ['csrf' => $t, 'username' => 'pw_alice', 'email' => 'pw_alice@test.com', 'password' => 'Test1234!', 'password_confirm' => 'Test1234!', 'age18' => '1', 'setup_token' => 'test-setup-token'], $ck);
echo "Register alice: $s\n";

// Follow redirect to /app
req('GET', '/app', [], $ck);

// Get CSRF from /app
[$s, $b] = req('GET', '/app', [], $ck);
$t = csrf($b);
echo "CSRF: " . substr($t, 0, 16) . "... (len=" . strlen($t) . ")\n";

// Configure voice
[$s] = req('POST', '/admin/webrtc/save', [
    'csrf' => $t, 'voice_enabled' => '1',
    'livekit_url' => 'ws://127.0.0.1:7880', 'livekit_api_key' => 'testkey',
    'livekit_api_secret' => 'testsecretkey1234567890123456789012',
    'voice_max_users' => '50', 'voice_talker_cap' => '8',
    'voice_quality_preset' => 'moderate', 'voice_bitrate' => '40000',
], $ck);
echo "Admin save: $s\n";

// Fresh CSRF
[$s, $b] = req('GET', '/app', [], $ck);
$t = csrf($b);

// Enable voice on #general
[$s] = req('POST', '/api/webrtc/voice/channel-voice', ['csrf' => $t, 'channel' => 'general', 'enabled' => '1'], $ck);
echo "Channel voice: $s\n";

// Fresh CSRF, then join
[$s, $b] = req('GET', '/app', [], $ck);
$t = csrf($b);
[$s] = req('POST', '/api/join', ['csrf' => $t, 'name' => '#general'], $ck);
echo "Join general: $s\n";

// Verify
$row = $db->query("SELECT value FROM server_config WHERE key='voice_enabled'")->fetch();
echo "voice_enabled: " . ($row['value'] ?? 'NULL') . "\n";

$row = $db->query("SELECT value FROM server_config WHERE key='livekit_api_key'")->fetch();
echo "livekit_api_key: " . ($row['value'] ?? 'NULL') . "\n";

echo "Setup complete\n";

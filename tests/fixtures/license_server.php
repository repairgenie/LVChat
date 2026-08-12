<?php

declare(strict_types=1);

// Minimal in-repo license server used by tests/http_test.php (see
// docs/protocol/licensing.md for the protocol it mimics). It re-verifies the key offline
// with the same codec + public key env, then behaves like a real license server:
//   - valid key            -> {"ok":true, ...}
//   - holder claim REFUSE  -> {"ok":false,"reason":"revoked"}
//   - otherwise            -> the offline reason / bad_request
//
// Spawned as a PHP built-in-server router:
//   php -S 127.0.0.1:8096 tests/fixtures/license_server.php

require dirname(__DIR__, 2) . '/src/services/LicenseKeys.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($method === 'POST' && $path === '/api/licenses/validate') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $in = is_array($in) ? $in : [];
    $module = (string) ($in['module'] ?? '');
    $key = (string) ($in['key'] ?? '');
    $out = ['ok' => false, 'reason' => 'bad_request'];
    if ($module !== '' && $key !== '') {
        $off = LicenseKeys::verify($key, $module);
        if (!$off['ok']) {
            $out = ['ok' => false, 'reason' => $off['reason']];
        } elseif (($off['claims']['holder'] ?? '') === 'REFUSE') {
            $out = ['ok' => false, 'reason' => 'revoked'];
        } else {
            $exp = (string) ($off['claims']['exp'] ?? '');
            $out = [
                'ok' => true,
                'module' => $module,
                'license_type' => (string) ($off['claims']['type'] ?? 'standard'),
                'holder' => (string) ($off['claims']['holder'] ?? ''),
                'expires_at' => $exp !== '' ? $exp : null,
                'activations_used' => 1,
                'activations_max' => 3,
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

http_response_code(404);
echo 'Not found';

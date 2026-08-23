#!/usr/bin/env bash
# Run Playwright voice/video browser tests.
# Starts the app server, configures voice, runs tests, cleans up.
set -euo pipefail
cd "$(dirname "$0")/.."

PORT=8098
DB=/tmp/opencode/pw-test.db
STAGE=/tmp/opencode/pw-test-modules
LIVEKIT_URL="ws://127.0.0.1:7880"
LIVEKIT_KEY="testkey"
LIVEKIT_SECRET="testsecretkey1234567890123456789012"

cleanup() {
    fuser -k 127.0.0.1:$PORT/tcp 2>/dev/null || true
}
trap cleanup EXIT

# Check LiveKit
if ! curl -sf http://127.0.0.1:7880 >/dev/null 2>&1; then
    echo "ERROR: LiveKit server not running on :7880"
    echo "  Start with: ~/.local/bin/livekit-server --config /tmp/opencode/livekit-test/livekit.yaml --node-ip 127.0.0.1"
    exit 1
fi

# Prepare staging directory
rm -rf "$STAGE" "$DB" "${DB}-wal" "${DB}-shm" 2>/dev/null
mkdir -p "$STAGE"
cp -r tests/fixtures/modules/* "$STAGE/" 2>/dev/null || true
rm -rf "$STAGE/webrtc" 2>/dev/null || true
ln -s "$(pwd)/modules/webrtc" "$STAGE/webrtc"

# Start server
CHAT_DB="$DB" CHAT_MODULES="$STAGE" LVC_DEBUG=1 SETUP_TOKEN=test-setup-token \
    php -S "127.0.0.1:$PORT" -t public public/router.php \
    >/tmp/opencode/pw-test-server.log 2>&1 &
SERVER_PID=$!
sleep 2

if ! kill -0 $SERVER_PID 2>/dev/null; then
    echo "ERROR: Server failed to start. Log:"
    cat /tmp/opencode/pw-test-server.log
    exit 1
fi
echo "Server started on :$PORT (PID $SERVER_PID)"

# Register alice + bob + configure voice via the test harness
CHAT_DB="$DB" php -r '
require __DIR__ . "/tests/livekit_test.php";
' 2>/dev/null || true

# Actually, use direct API calls to set up
# 1. Register alice (admin)
CSRF=$(curl -s "http://127.0.0.1:$PORT/register" -c /tmp/opencode/pw-cookies.txt | grep -oP 'data-csrf="\K[^"]+')
curl -s -X POST "http://127.0.0.1:$PORT/register" -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt \
    -d "csrf=$CSRF&username=pw_alice&email=pw_alice@test.com&password=Test1234!&password_confirm=Test1234!&age18=1&setup_token=test-setup-token&ajax=1" >/dev/null

# 2. Disable admin MFA
CSRF=$(curl -s "http://127.0.0.1:$PORT/app" -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt | grep -oP 'data-csrf="\K[^"]+')
CHAT_DB="$DB" php -r '
$db = new PDO("sqlite:" . getenv("CHAT_DB"));
$db->exec("UPDATE server_config SET value = '\''0'\'' WHERE key = '\''mfa_require_admin'\''");
'

# 3. Configure voice
curl -s -X POST "http://127.0.0.1:$PORT/admin/webrtc/save" \
    -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt \
    -d "csrf=$CSRF&voice_enabled=1&livekit_url=$LIVEKIT_URL&livekit_api_key=$LIVEKIT_KEY&livekit_api_secret=$LIVEKIT_SECRET&voice_max_users=50&voice_talker_cap=8&voice_quality_preset=moderate&voice_bitrate=40000&ajax=1" >/dev/null

# 4. Enable voice on #general
CSRF=$(curl -s "http://127.0.0.1:$PORT/app" -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt | grep -oP 'data-csrf="\K[^"]+')
curl -s -X POST "http://127.0.0.1:$PORT/api/webrtc/voice/channel-voice" \
    -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt \
    -d "csrf=$CSRF&channel=general&enabled=1&ajax=1" >/dev/null

# 5. Join #general
curl -s -X POST "http://127.0.0.1:$PORT/api/join" \
    -b /tmp/opencode/pw-cookies.txt -c /tmp/opencode/pw-cookies.txt \
    -d "csrf=$CSRF&name=%23general&ajax=1" >/dev/null

echo "Voice configured. Running Playwright tests..."

# Run Playwright
BASE_URL="http://127.0.0.1:$PORT" npx playwright test --reporter=list 2>&1
RESULT=$?

echo ""
if [ $RESULT -eq 0 ]; then
    echo "All Playwright tests passed!"
else
    echo "Some Playwright tests failed."
fi
exit $RESULT

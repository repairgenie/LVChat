#!/usr/bin/env bash
#
# test.sh — run the full automated test suite.
#
#   1. tests/smoke.php    command/service assertions (no server needed)
#   2. tests/http_test.php   route/API assertions (spins up its own server)
#   3. tests/ws_test.php   WebSocket gateway integration test (spawns the
#      realtime daemon on WS_PORT/WS_PUSH_PORT, default 9092/9093)
#
# Each suite runs to completion; any failure is reported at the end and the
# script exits non-zero. Usage:  bash bin/test.sh
set -uo pipefail
cd "$(dirname "$0")/.."

DB=/tmp/opencode/chat-test.db
rm -f "$DB" "$DB-wal" "$DB-shm"

FAIL=0

echo "== 1/3 Command / service smoke test =="
CHAT_DB="$DB" php tests/smoke.php || FAIL=1

echo ""
echo "== 2/3 HTTP end-to-end test =="
CHAT_DB="$DB" php tests/http_test.php || FAIL=1

echo ""
echo "== 3/3 WebSocket gateway test =="
WS_PORT="${WS_PORT:-9092}" WS_PUSH_PORT="${WS_PUSH_PORT:-9093}" php tests/ws_test.php || FAIL=1

echo ""
if [ "$FAIL" = "0" ]; then
    echo "All suites passed."
else
    echo "One or more suites FAILED — review the output above." >&2
fi
exit "$FAIL"

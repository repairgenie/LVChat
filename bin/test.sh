#!/usr/bin/env bash
#
# test.sh — run the full automated test suite.
#
#   1. tests/smoke.php   164 command/service assertions (no server needed)
#   2. tests/http_test.php  48 route/API assertions (spins up its own server)
#
# Usage:  bash bin/test.sh
set -euo pipefail
cd "$(dirname "$0")/.."

DB=/tmp/opencode/chat-test.db
rm -f "$DB" "$DB-wal" "$DB-shm"

echo "== 1/2 Command / service smoke test =="
CHAT_DB="$DB" php tests/smoke.php

echo ""
echo "== 2/2 HTTP end-to-end test =="
CHAT_DB="$DB" php tests/http_test.php

#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.."

LOG=$(mktemp /tmp/lvchat-messenger-test-XXXXXX.log)

setsid ./node_modules/.bin/electron tests/e2e.js --no-sandbox --disable-gpu >"$LOG" 2>&1 &
PID=$!

for _ in $(seq 1 120); do
  if grep -qE "ALL TESTS PASSED|TEST\(S\) FAILED|aborting" "$LOG" 2>/dev/null; then
    break
  fi
  sleep 1
done

kill -TERM -- "-$PID" 2>/dev/null
pkill -f "dist/electron.*tests/e2e.js" 2>/dev/null
wait "$PID" 2>/dev/null

sed -E '/Gtk-WARNING|Network service crashed|zygote|GPU process|nss_util/d' "$LOG"
RESULT=$(grep -c "ALL TESTS PASSED" "$LOG")
rm -f "$LOG"

exit "$([ "$RESULT" = "1" ] && echo 0 || echo 1)"

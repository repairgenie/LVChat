#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.."
LOG=/tmp/lvchat-dbg.log
rm -f "$LOG"
setsid ./node_modules/.bin/electron tests/e2e.js --no-sandbox --disable-gpu >"$LOG" 2>&1 &
PID=$!
sleep 90
kill -TERM -- "-$PID" 2>/dev/null
pkill -f "dist/electron.*e2e.js" 2>/dev/null
true

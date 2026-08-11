#!/usr/bin/env bash

#
# LVChat — Discord-style web chat (PHP + SQLite)
# Copyright (C) LVChat contributors
# SPDX-License-Identifier: AGPL-3.0-only
# License: GNU Affero General Public License v3 only — see the LICENSE file.
#

set -u
cd "$(dirname "$0")/.."

# tests/e2e.js: the main messenger suite (launcher-first flow).
# tests/switch-e2e.js: account switching via the Profile Manager.
# tests/multi-menu-e2e.js: two logged-in accounts — menus stay stable and the
# buddy list is not rebuilt when polls return unchanged data (flicker fix).
# tests/startup-e2e.js: launcher-bypass — once per seed mode (auto-connect on
# startup, and last-used profile with no auto-connect).
# tests/tray-diagnostic.js: tray icon non-empty + icon shipped in the package.
TESTS=(
  "tests/e2e.js"
  "tests/switch-e2e.js"
  "tests/multi-menu-e2e.js"
  "tests/startup-e2e.js"
  "tests/startup-e2e.js"
  "tests/tray-diagnostic.js"
)
EXTRA_ENV=(
  ""
  ""
  ""
  "STARTUP_SEED=auto"
  "STARTUP_SEED=lastused"
  ""
)
RESULT=0

for i in "${!TESTS[@]}"; do
  TEST="${TESTS[$i]}"
  ENVSTR="${EXTRA_ENV[$i]}"
  LOG=$(mktemp /tmp/lvchat-messenger-test-XXXXXX.log)

  if [ -n "$ENVSTR" ]; then
    setsid env "$ENVSTR" ./node_modules/.bin/electron "$TEST" --no-sandbox --disable-gpu >"$LOG" 2>&1 &
  else
    setsid ./node_modules/.bin/electron "$TEST" --no-sandbox --disable-gpu >"$LOG" 2>&1 &
  fi
  PID=$!

  for _ in $(seq 1 180); do
    if grep -qE "ALL TESTS PASSED|TEST\(S\) FAILED|aborting" "$LOG" 2>/dev/null; then
      break
    fi
    sleep 1
  done

  kill -TERM -- "-$PID" 2>/dev/null
  pkill -f "electron .*$TEST" 2>/dev/null
  wait "$PID" 2>/dev/null

  sed -E '/Gtk-WARNING|Network service crashed|zygote|GPU process|nss_util/d' "$LOG"
  grep -q "ALL TESTS PASSED" "$LOG" || RESULT=1
  rm -f "$LOG"
done

exit "$RESULT"

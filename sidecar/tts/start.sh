#!/usr/bin/env bash
# LVChat — TTS sidecar launcher
# Creates a virtualenv on first run, installs deps, and starts the server.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -d "$DIR/venv" ]; then
  echo "[tts] Creating virtualenv..."
  python3 -m venv "$DIR/venv"
  "$DIR/venv/bin/python" -m ensurepip --upgrade -q 2>/dev/null || true
  "$DIR/venv/bin/python" -m pip install --upgrade pip -q
  "$DIR/venv/bin/python" -m pip install -r "$DIR/requirements.txt" -q
  echo "[tts] Dependencies installed."
fi

# Kill any existing process on the target port
kill_port() {
  local port="$1"
  local pids
  pids=$(lsof -ti :"$port" 2>/dev/null || true)
  if [ -n "$pids" ]; then
    echo "[tts] Killing existing process(es) on port $port (PIDs: $pids)..."
    kill $pids 2>/dev/null || true
    sleep 1
    # Force kill if still alive
    pids=$(lsof -ti :"$port" 2>/dev/null || true)
    if [ -n "$pids" ]; then
      kill -9 $pids 2>/dev/null || true
      sleep 1
    fi
  fi
}

# Find a free port starting from the preferred one
find_free_port() {
  local preferred="$1"
  local port="$preferred"
  local max=$((preferred + 20))
  while [ "$port" -le "$max" ]; do
    if ! lsof -i :"$port" >/dev/null 2>&1; then
      echo "$port"
      return 0
    fi
    port=$((port + 1))
  done
  echo ""
  return 1
}

PREFERRED_PORT="${TTS_PORT:-8788}"
kill_port "$PREFERRED_PORT"
TTS_PORT=$(find_free_port "$PREFERRED_PORT")

if [ -z "$TTS_PORT" ]; then
  echo "[tts] ERROR: No free port found in range $PREFERRED_PORT-$((PREFERRED_PORT + 20))"
  exit 1
fi

if [ "$TTS_PORT" != "$PREFERRED_PORT" ]; then
  echo "[tts] Port $PREFERRED_PORT in use, using port $TTS_PORT instead"
fi

export TTS_PORT
export TTS_VOICE="${TTS_VOICE:-en_US-lessac-medium}"

echo "[tts] Starting on port $TTS_PORT (voice=$TTS_VOICE)..."
exec "$DIR/venv/bin/uvicorn" server:app --host 127.0.0.1 --port "$TTS_PORT" --log-level info

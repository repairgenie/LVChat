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

# Kill any existing process on the target port (try lsof, fall back to ss/fuser)
kill_port() {
  local port="$1"
  local pids=""
  if command -v lsof >/dev/null 2>&1; then
    pids=$(lsof -ti :"$port" 2>/dev/null || true)
  elif command -v fuser >/dev/null 2>&1; then
    pids=$(fuser "$port/tcp" 2>/dev/null | tr -s ' ' || true)
  elif command -v ss >/dev/null 2>&1; then
    pids=$(ss -tlnp "sport = :$port" 2>/dev/null | grep -oP 'pid=\K\d+' || true)
  fi
  if [ -n "$pids" ]; then
    echo "[tts] Killing existing process(es) on port $port (PIDs: $pids)..."
    echo "$pids" | xargs kill 2>/dev/null || true
    sleep 1
    # Force kill if still alive
    if command -v lsof >/dev/null 2>&1; then
      pids=$(lsof -ti :"$port" 2>/dev/null || true)
    elif command -v fuser >/dev/null 2>&1; then
      pids=$(fuser "$port/tcp" 2>/dev/null | tr -s ' ' || true)
    fi
    if [ -n "$pids" ]; then
      echo "$pids" | xargs kill -9 2>/dev/null || true
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
    local in_use=false
    if command -v lsof >/dev/null 2>&1; then
      lsof -i :"$port" >/dev/null 2>&1 && in_use=true
    elif command -v ss >/dev/null 2>&1; then
      ss -tln "sport = :$port" 2>/dev/null | grep -q ":$port" && in_use=true
    elif command -v fuser >/dev/null 2>&1; then
      fuser "$port/tcp" >/dev/null 2>&1 && in_use=true
    fi
    if [ "$in_use" = false ]; then
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

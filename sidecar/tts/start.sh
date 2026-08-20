#!/usr/bin/env bash
# LVChat — TTS sidecar launcher
# Creates a virtualenv on first run, installs deps, and starts the server.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -d "$DIR/venv" ]; then
  echo "[tts] Creating virtualenv..."
  python3 -m venv "$DIR/venv"
  "$DIR/venv/bin/pip" install --upgrade pip -q
  "$DIR/venv/bin/pip" install -r "$DIR/requirements.txt" -q
  echo "[tts] Dependencies installed."
fi

export TTS_PORT="${TTS_PORT:-8788}"
export TTS_VOICE="${TTS_VOICE:-en_US-lessac-medium}"

echo "[tts] Starting on port $TTS_PORT (voice=$TTS_VOICE)..."
exec "$DIR/venv/bin/uvicorn" server:app --host 127.0.0.1 --port "$TTS_PORT" --log-level info

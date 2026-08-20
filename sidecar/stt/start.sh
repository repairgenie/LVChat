#!/usr/bin/env bash
# LVChat — STT sidecar launcher
# Creates a virtualenv on first run, installs deps, and starts the server.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -d "$DIR/venv" ]; then
  echo "[stt] Creating virtualenv..."
  python3 -m venv "$DIR/venv"
  "$DIR/venv/bin/python" -m ensurepip --upgrade -q 2>/dev/null || true
  "$DIR/venv/bin/python" -m pip install --upgrade pip -q
  "$DIR/venv/bin/python" -m pip install -r "$DIR/requirements.txt" -q
  echo "[stt] Dependencies installed."
fi

export STT_PORT="${STT_PORT:-8787}"
export STT_MODEL="${STT_MODEL:-small}"
unset STT_THREADS

echo "[stt] Starting on port $STT_PORT (model=$STT_MODEL)..."
exec "$DIR/venv/bin/uvicorn" server:app --host 127.0.0.1 --port "$STT_PORT" --log-level info

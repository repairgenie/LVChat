#!/usr/bin/env bash
# LVChat — Start voice sidecars (STT + TTS)
# Run this alongside the main chat server.
set -euo pipefail
DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "Starting LVChat voice sidecars..."

# STT sidecar
if [ -d "$DIR/sidecar/stt/venv" ]; then
  (cd "$DIR/sidecar/stt" && source venv/bin/activate && exec uvicorn server:app --host 127.0.0.1 --port "${STT_PORT:-8787}" --log-level info) &
  echo "  STT sidecar starting on port ${STT_PORT:-8787}"
else
  echo "  STT sidecar: run sidecar/stt/start.sh first to set up the venv"
fi

# TTS sidecar
if [ -d "$DIR/sidecar/tts/venv" ]; then
  (cd "$DIR/sidecar/tts" && source venv/bin/activate && exec uvicorn server:app --host 127.0.0.1 --port "${TTS_PORT:-8788}" --log-level info) &
  echo "  TTS sidecar starting on port ${TTS_PORT:-8788}"
else
  echo "  TTS sidecar: run sidecar/tts/start.sh first to set up the venv"
fi

echo "Voice sidecars running. Press Ctrl+C to stop."
wait

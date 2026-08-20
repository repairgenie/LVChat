"""
LVChat — Text-to-Speech sidecar

Lightweight FastAPI service wrapping piper-tts for local text-to-speech.
Uses a small ONNX voice model that auto-downloads on first use.

Usage:
    pip install -r requirements.txt
    uvicorn server:app --host 127.0.0.1 --port 8788

Environment variables:
    TTS_PORT        Port to listen on (default: 8788)
    TTS_VOICE       Voice model name (default: en_US-lessac-medium)
"""

import io
import logging
import os
from contextlib import asynccontextmanager

import uvicorn
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

logger = logging.getLogger("tts-sidecar")

_voice = os.environ.get("TTS_VOICE", "en_US-lessac-medium")
_synthesizer = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _synthesizer
    logger.info("Loading TTS voice: %s", _voice)
    try:
        from piper import PiperVoice

        _synthesizer = PiperVoice.load(_voice)
        logger.info("Voice loaded successfully")
    except Exception as e:
        logger.warning("Could not load piper voice '%s': %s. TTS will return 503.", _voice, e)
        _synthesizer = None
    yield
    _synthesizer = None


app = FastAPI(title="LVChat TTS Sidecar", lifespan=lifespan)


class SynthesizeRequest(BaseModel):
    text: str
    speed: float = 1.0


@app.get("/health")
async def health():
    return {"status": "ok", "voice": _voice, "available": _synthesizer is not None}


@app.post("/synthesize")
async def synthesize(req: SynthesizeRequest):
    if _synthesizer is None:
        raise HTTPException(status_code=503, detail="TTS voice not loaded")

    text = req.text.strip()
    if not text:
        raise HTTPException(status_code=400, detail="Text is empty")

    # Truncate very long text to avoid memory issues
    if len(text) > 5000:
        text = text[:5000]

    try:
        wav_buffer = io.BytesIO()
        _synthesizer.synthesize(text, wav_buffer)
        wav_bytes = wav_buffer.getvalue()

        logger.info("Synthesized %d chars -> %d bytes", len(text), len(wav_bytes))
        return Response(content=wav_bytes, media_type="audio/wav")

    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Synthesis failed")
        raise HTTPException(status_code=500, detail=str(e))


if __name__ == "__main__":
    port = int(os.environ.get("TTS_PORT", "8788"))
    logging.basicConfig(level=logging.INFO)
    uvicorn.run(app, host="127.0.0.1", port=port, log_level="info")

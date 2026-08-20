"""
LVChat — Text-to-Speech sidecar

Lightweight FastAPI service wrapping piper-tts for local text-to-speech.
Downloads voice model from Hugging Face on first run.

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
from pathlib import Path

import uvicorn
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

logger = logging.getLogger("tts-sidecar")

_voice = os.environ.get("TTS_VOICE", "en_US-lessac-medium")
_model_dir = Path(__file__).parent / "models"
_synthesizer = None


def _download_voice(name: str) -> tuple[Path, Path]:
    """Download ONNX model + JSON config from Hugging Face if not cached."""
    _model_dir.mkdir(exist_ok=True)
    onnx_path = _model_dir / f"{name}.onnx"
    json_path = _model_dir / f"{name}.onnx.json"

    if onnx_path.exists() and json_path.exists():
        return onnx_path, json_path

    logger.info("Downloading voice model: %s", name)
    import urllib.request

    base = f"https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium"
    for filename, dest in [
        (f"{name}.onnx", onnx_path),
        (f"{name}.onnx.json", json_path),
    ]:
        url = f"{base}/{filename}"
        logger.info("  %s -> %s", url, dest)
        try:
            urllib.request.urlretrieve(url, str(dest))
        except Exception as e:
            logger.error("Failed to download %s: %s", url, e)
            raise

    return onnx_path, json_path


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _synthesizer
    logger.info("Loading TTS voice: %s", _voice)
    try:
        onnx_path, json_path = _download_voice(_voice)

        from piper import PiperVoice

        _synthesizer = PiperVoice.load(str(onnx_path), config_path=str(json_path))
        logger.info("Voice loaded successfully from %s", onnx_path)
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

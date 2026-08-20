"""
LVChat — Speech-to-Text sidecar

Lightweight FastAPI service wrapping faster-whisper for local speech-to-text.
Runs on CPU with int8 quantization for minimal resource usage.

Usage:
    pip install -r requirements.txt
    uvicorn server:app --host 127.0.0.1 --port 8787

Environment variables:
    STT_PORT        Port to listen on (default: 8787)
    STT_MODEL       Whisper model size (default: small)
    STT_THREADS     CPU threads (default: auto)
"""

import io
import logging
import os
import tempfile
from contextlib import asynccontextmanager

import uvicorn
from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse

logger = logging.getLogger("stt-sidecar")

# Global model reference — loaded once at startup.
_model = None
_model_size = os.environ.get("STT_MODEL", "small")
_threads = int(os.environ.get("STT_THREADS", "0")) or None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _model
    logger.info("Loading whisper model: %s (threads=%s)", _model_size, _threads or "auto")
    from faster_whisper import WhisperModel

    _model = WhisperModel(
        _model_size,
        device="cpu",
        compute_type="int8",
        cpu_threads=_threads,
    )
    logger.info("Model loaded successfully")
    yield
    _model = None


app = FastAPI(title="LVChat STT Sidecar", lifespan=lifespan)


@app.get("/health")
async def health():
    return {"status": "ok", "model": _model_size, "device": "cpu"}


@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)):
    if _model is None:
        raise HTTPException(status_code=503, detail="Model not loaded yet")

    # Validate content type
    allowed = {"audio/webm", "audio/wav", "audio/ogg", "audio/ogg kodec=opus",
               "audio/x-wav", "audio/mpeg", "audio/mp3", "audio/flac"}
    ct = (audio.content_type or "").split(";")[0].strip().lower()
    if ct not in allowed and not ct.startswith("audio/"):
        raise HTTPException(status_code=400, detail=f"Unsupported audio type: {ct}")

    try:
        raw = await audio.read()
        if len(raw) < 100:
            raise HTTPException(status_code=400, detail="Audio data too short")

        # Write to a temp file — faster-whisper needs a file path or file-like
        suffix = ".webm" if "webm" in ct else ".wav"
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(raw)
            tmp_path = tmp.name

        try:
            segments, info = _model.transcribe(
                tmp_path,
                beam_size=1,
                language="en",
                vad_filter=True,
                vad_parameters=dict(min_silence_duration_ms=500),
            )
            text_parts = [seg.text.strip() for seg in segments if seg.text.strip()]
            text = " ".join(text_parts)
        finally:
            os.unlink(tmp_path)

        logger.info("Transcribed %d bytes -> %d chars", len(raw), len(text))
        return JSONResponse({"text": text})

    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Transcription failed")
        raise HTTPException(status_code=500, detail=str(e))


if __name__ == "__main__":
    port = int(os.environ.get("STT_PORT", "8787"))
    logging.basicConfig(level=logging.INFO)
    uvicorn.run(app, host="127.0.0.1", port=port, log_level="info")

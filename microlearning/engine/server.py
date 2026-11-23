"""Private HTTP service used by Moodle for document import and lecture narration."""

import base64
import binascii
from contextlib import ExitStack
import json
import logging
import os
from pathlib import Path
import secrets
from tempfile import TemporaryDirectory

from fastapi import Depends, FastAPI, HTTPException, Query, Request, Response
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import FileResponse
from starlette.background import BackgroundTask

from documents import extract
from reference_audio import INPUT_FORMATS, prepare_reference
from synthesis import MAX_REFERENCE, MAX_SCRIPT, PRESET_VOICES, SpeechModel, validate_reference


MAX_DOCUMENT = 10 * 1024**2
MAX_BODY = 4 * ((MAX_DOCUMENT + 2) // 3) + 100_000
MAX_VOICE_UPLOAD = 500 * 1024**2
TOKEN = os.environ.get("MICROLEARNING_SPEECH_TOKEN", "")
if not TOKEN.isascii() or len(TOKEN) < 32:
    raise RuntimeError("Set MICROLEARNING_SPEECH_TOKEN to a shared ASCII secret of at least 32 characters")

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
speech = SpeechModel()
logger = logging.getLogger(__name__)


def authenticate(request: Request):
    expected = ("Bearer " + TOKEN).encode("ascii")
    received = request.headers.get("Authorization", "").encode("latin-1")
    if not secrets.compare_digest(expected, received):
        raise HTTPException(401, "speechauth")


async def read_payload(request: Request):
    body = bytearray()
    async for chunk in request.stream():
        if len(body) + len(chunk) > MAX_BODY:
            raise HTTPException(413, "speechrequestlimit")
        body.extend(chunk)
    try:
        payload = json.loads(body)
    except (ValueError, RecursionError):
        raise HTTPException(422, "speechrequest") from None
    if not isinstance(payload, dict):
        raise HTTPException(422, "speechrequest")
    return payload


def decode_input(encoded, limit, error):
    if not isinstance(encoded, str) or not 0 < len(encoded) <= 4 * ((limit + 2) // 3):
        raise HTTPException(422, error)
    try:
        decoded = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError):
        raise HTTPException(422, error) from None
    if not 0 < len(decoded) <= limit:
        raise HTTPException(422, error)
    return decoded


@app.get("/v1/health", dependencies=[Depends(authenticate)])
def health():
    return {"status": "ok", "model": speech.mode, "model_loaded": speech.model is not None}


@app.post("/v1/documents/extract", dependencies=[Depends(authenticate)])
def extract_document(payload=Depends(read_payload)):
    raw = decode_input(payload.get("content"), MAX_DOCUMENT, "documentlimit")
    try:
        return {"slides": extract(payload.get("kind"), raw)}
    except ValueError as error:
        known = ("documentformat", "documentempty", "documentlimit")
        raise HTTPException(422, str(error) if str(error) in known else "documentformat") from None
    except Exception:
        raise HTTPException(422, "documentformat") from None


@app.post("/v1/voices/validate", dependencies=[Depends(authenticate)])
def validate_voice(payload=Depends(read_payload)):
    raw = decode_input(payload.get("reference"), MAX_REFERENCE, "voiceformat")
    try:
        validate_reference(raw)
    except ValueError:
        raise HTTPException(422, "voiceformat") from None
    return {"valid": True}


@app.put("/v1/voices/prepare/{kind}", dependencies=[Depends(authenticate)])
async def prepare_voice(request: Request, kind: str, start: int | None = Query(default=None, ge=0, le=99_999_999)):
    if kind not in INPUT_FORMATS:
        raise HTTPException(422, "voiceformat")
    try:
        declared_size = int(request.headers.get("Content-Length", "0"))
    except ValueError:
        raise HTTPException(400, "voiceformat") from None
    if declared_size > MAX_VOICE_UPLOAD:
        raise HTTPException(413, "voiceuploadlimit")
    try:
        with TemporaryDirectory(prefix="microlearning-voice-") as folder:
            recording = Path(folder) / f"recording.{kind}"
            size = 0
            with recording.open("wb") as output:
                async for chunk in request.stream():
                    size += len(chunk)
                    if size > MAX_VOICE_UPLOAD:
                        raise HTTPException(413, "voiceuploadlimit")
                    if chunk:
                        await run_in_threadpool(output.write, chunk)
            if not size:
                raise HTTPException(422, "voiceformat")
            wav = await run_in_threadpool(prepare_reference, recording, kind, start)
    except TimeoutError:
        raise HTTPException(503, "voicepreparetimeout") from None
    except ValueError as error:
        known = ("voiceformat", "voicetooshort", "voicequiet")
        raise HTTPException(422, str(error) if str(error) in known else "voiceformat") from None
    except (OSError, RuntimeError):
        logger.exception("Voice sample preparation failed")
        raise HTTPException(503, "voicepreparefailed") from None
    return Response(wav, media_type="audio/wav")


@app.post("/v1/synthesize", dependencies=[Depends(authenticate)])
async def synthesize(payload=Depends(read_payload)):
    text = payload.get("text")
    voice = payload.get("voice", "")
    if not isinstance(text, str) or not text.strip() or len(text) > MAX_SCRIPT:
        raise HTTPException(422, "scriptlimit")
    if not isinstance(voice, str) or voice not in PRESET_VOICES:
        raise HTTPException(422, "voicenotfound")
    reference = None
    if "reference" in payload:
        reference = decode_input(payload["reference"], MAX_REFERENCE, "voiceformat")
    with ExitStack() as cleanup:
        folder = cleanup.enter_context(TemporaryDirectory(prefix="microlearning-speech-"))
        destination = Path(folder) / "narration.wav"
        try:
            await run_in_threadpool(speech.synthesize, text, voice, reference, destination)
        except TimeoutError:
            raise HTTPException(503, "speechbusy") from None
        except ValueError as error:
            if str(error) in ("voiceformat", "narrationwavlimit"):
                raise HTTPException(422, str(error)) from None
            logger.exception("Speech generation failed")
            raise HTTPException(503, "speechgenerationfailed") from None
        except Exception:
            logger.exception("Speech generation failed")
            raise HTTPException(503, "speechgenerationfailed") from None
        return FileResponse(destination, media_type="audio/wav", background=BackgroundTask(cleanup.pop_all().close))


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8010, access_log=False)

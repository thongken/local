"""Decode a recording and keep a short mono segment for the speech model."""

from collections import deque
import io
import subprocess
from threading import Event, Timer
import wave

import imageio_ffmpeg
import numpy as np

from synthesis import MIN_REFERENCE_LEVEL, MIN_REFERENCE_SECONDS, validate_reference


INPUT_FORMATS = {"wav": "wav", "mp3": "mp3", "m4a": "mov", "ogg": "ogg", "aac": "aac", "flac": "flac"}
SAMPLE_RATE = 16_000
SECOND_BYTES = SAMPLE_RATE * 2
CLIP_SECONDS = 6
DECODE_TIMEOUT = 120


def find_audible_clip(stream):
    """Prefer an early clip with little silence, using a fixed-size window."""
    window = deque(maxlen=CLIP_SECONDS)
    best = b""
    best_score = (False, 0, 0, False)
    while chunk := stream.read(SECOND_BYTES):
        samples = np.frombuffer(chunk, dtype="<i2")
        audible = np.std(samples, dtype=np.float64) >= MIN_REFERENCE_LEVEL
        window.append((chunk, audible))
        size = sum(len(block) for block, _ in window)
        active = sum(len(block) for block, heard in window if heard)
        starts_audible = window[0][1]
        if size == CLIP_SECONDS * SECOND_BYTES and active >= 5 * SECOND_BYTES and starts_audible:
            return b"".join(block for block, _ in window), True
        score = (size >= MIN_REFERENCE_SECONDS * SECOND_BYTES, active, size, starts_audible)
        if score > best_score:
            best = b"".join(block for block, _ in window)
            best_score = score
    return best, False


def prepare_reference(path, kind, start):
    command = [
        imageio_ffmpeg.get_ffmpeg_exe(), "-nostdin", "-hide_banner", "-loglevel", "error",
        "-protocol_whitelist", "file,pipe", "-threads", "1", "-filter_threads", "1", "-f", INPUT_FORMATS[kind],
    ]
    if start is not None:
        command.extend(["-ss", str(start)])
    command.extend(["-i", str(path)])
    if start is not None:
        command.extend(["-t", str(CLIP_SECONDS)])
    command.extend(["-map", "0:a:0", "-vn", "-sn", "-dn", "-ac", "1", "-ar", str(SAMPLE_RATE), "-f", "s16le", "pipe:1"])
    with subprocess.Popen(
        command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
    ) as decoder:
        timed_out = Event()

        def stop_decoder():
            timed_out.set()
            decoder.kill()

        timer = Timer(DECODE_TIMEOUT, stop_decoder)
        timer.daemon = True
        timer.start()
        try:
            if start is None:
                pcm, found = find_audible_clip(decoder.stdout)
            else:
                pcm, found = decoder.stdout.read(), False
            if not found:
                status = decoder.wait()
                if timed_out.is_set():
                    raise TimeoutError("voicepreparetimeout")
                if status:
                    raise ValueError("voiceformat")
        finally:
            timer.cancel()
            if decoder.poll() is None:
                decoder.kill()

    if len(pcm) < MIN_REFERENCE_SECONDS * SECOND_BYTES:
        raise ValueError("voicetooshort")
    output = io.BytesIO()
    with wave.open(output, "wb") as wav:
        wav.setparams((1, 2, SAMPLE_RATE, 0, "NONE", "not compressed"))
        wav.writeframes(pcm)
    reference = output.getvalue()
    try:
        validate_reference(reference)
    except ValueError:
        raise ValueError("voicequiet") from None
    return reference

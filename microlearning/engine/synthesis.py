"""Load the local VieNeu model on demand and generate WAV without retaining inputs."""

import io
import os
from pathlib import Path
from threading import Lock
import wave

import numpy as np


MAX_WAV = 256 * 1024**2
MAX_REFERENCE = 2 * 1024**2
MAX_SCRIPT = 80_000
PRESET_VOICES = ("", "Minh Quân", "Mai Anh", "Adam", "Ái Hân")
MIN_REFERENCE_SECONDS = 3
MIN_REFERENCE_LEVEL = 32


def validate_reference(raw):
    if len(raw) > MAX_REFERENCE:
        raise ValueError("voiceformat")
    try:
        with wave.open(io.BytesIO(raw), "rb") as wav:
            channels = wav.getnchannels()
            rate = wav.getframerate()
            frames = wav.getnframes()
            if wav.getsampwidth() != 2 or channels not in (1, 2) or not 8_000 <= rate <= 48_000:
                raise ValueError("voiceformat")
            if not MIN_REFERENCE_SECONDS * rate <= frames <= 8 * rate:
                raise ValueError("voiceformat")
            samples = wav.readframes(frames)
        if len(samples) != frames * channels * 2:
            raise ValueError("voiceformat")
        pcm = np.frombuffer(samples, dtype="<i2").astype(np.float32).reshape(-1, channels).mean(axis=1)
        if float(np.std(pcm)) < MIN_REFERENCE_LEVEL:
            raise ValueError("voiceformat")
    except (EOFError, wave.Error):
        raise ValueError("voiceformat") from None


class SpeechModel:
    def __init__(self):
        self.model = None
        self.lock = Lock()
        self.mode = os.environ.get("MICROLEARNING_SPEECH_MODEL", "v3nano")
        if self.mode not in ("v3nano", "v3turbo"):
            raise RuntimeError("MICROLEARNING_SPEECH_MODEL must be v3nano or v3turbo")

    def synthesize(self, text, voice, reference, destination):
        if reference:
            validate_reference(reference)
        if not self.lock.acquire(timeout=10):
            raise TimeoutError("speechbusy")
        try:
            if self.model is None:
                from vieneu import Vieneu

                options = {"backend": "onnx"} if self.mode == "v3turbo" else {}
                self.model = Vieneu(mode=self.mode, **options)
            import soundfile as sf

            options = {"text": text}
            if reference:
                sample = Path(destination).with_name("reference.wav")
                sample.write_bytes(reference)
                options["ref_audio"] = str(sample)
            elif voice:
                options["voice"] = "Thùy Dung" if voice == "Ái Hân" and self.mode == "v3turbo" else voice

            # Write each completed chunk to one WAV so long lectures do not accumulate in memory.
            with sf.SoundFile(destination, mode="w", samplerate=self.model.sample_rate,
                              channels=1, format="WAV", subtype="PCM_16") as wav:
                for chunk in self.model.infer_stream(**options):
                    if (wav.tell() + len(chunk)) * 2 + 44 > MAX_WAV:
                        raise ValueError("narrationwavlimit")
                    wav.write(chunk)
                if not wav.tell():
                    raise RuntimeError("Empty narration")
        finally:
            self.lock.release()

"""Exercise document import and the private speech API without downloading model weights."""

import base64
import io
import os
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import Mock, patch
import wave

import imageio_ffmpeg
import numpy as np
from fastapi.testclient import TestClient
from pptx import Presentation
from pypdf import PdfWriter
from pypdf.generic import DecodedStreamObject, DictionaryObject, NameObject


TEST_TOKEN = "synthetic-test-token-0000000000000"
with patch.dict(os.environ, {"MICROLEARNING_SPEECH_TOKEN": TEST_TOKEN}):
    from server import app, speech
from synthesis import MAX_SCRIPT


def make_wav(seconds, silent_seconds=0, amplitude=5000):
    rate = 16_000
    samples = (amplitude * np.sin(np.arange(seconds * rate) * 0.2)).astype("<i2")
    samples[:silent_seconds * rate] = 0
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as wav:
        wav.setparams((1, 2, rate, 0, "NONE", "not compressed"))
        wav.writeframes(samples.tobytes())
    return buffer.getvalue()


class SpeechApiTest(unittest.TestCase):
    def setUp(self):
        self.client = TestClient(app)
        self.headers = {"Authorization": f"Bearer {TEST_TOKEN}"}

    def extract(self, kind, raw):
        return self.client.post(
            "/v1/documents/extract",
            headers=self.headers,
            json={"kind": kind, "content": base64.b64encode(raw).decode()},
        )

    def test_service_requires_a_token(self):
        self.assertEqual(self.client.get("/v1/health").status_code, 401)
        self.assertEqual(self.client.get("/v1/health", headers=self.headers).json()["status"], "ok")

    def test_utf8_text_sections_and_invalid_bytes(self):
        result = self.extract("txt", "Giới thiệu\n---\nNội dung bài học".encode())
        self.assertEqual(result.status_code, 200)
        self.assertEqual([part["text"] for part in result.json()["slides"]], ["Giới thiệu", "Nội dung bài học"])
        self.assertEqual(self.extract("txt", b"\xff").json()["detail"], "documentformat")
        self.assertEqual(self.extract("txt", b" ").json()["detail"], "documentempty")

    def test_pptx_text_and_speaker_notes(self):
        pptx = Presentation()
        slide = pptx.slides.add_slide(pptx.slide_layouts[0])
        slide.shapes.title.text = "Lecture title"
        slide.notes_slide.notes_text_frame.text = "Speaker notes"
        buffer = io.BytesIO()
        pptx.save(buffer)
        result = self.extract("pptx", buffer.getvalue())
        self.assertEqual(result.status_code, 200)
        self.assertEqual(result.json()["slides"][0], {
            "title": "Lecture title", "text": "Lecture title", "notes": "Speaker notes"
        })

    def test_pdf_with_text_and_scanned_page_fallback(self):
        pdf = PdfWriter()
        page = pdf.add_blank_page(595, 842)
        page[NameObject("/Resources")] = DictionaryObject({
            NameObject("/Font"): DictionaryObject({NameObject("/F1"): DictionaryObject({
                NameObject("/Type"): NameObject("/Font"),
                NameObject("/Subtype"): NameObject("/Type1"),
                NameObject("/BaseFont"): NameObject("/Helvetica"),
            })}),
        })
        source = DecodedStreamObject()
        source.set_data(b"BT /F1 20 Tf 50 750 Td (Lecture extract) Tj ET")
        page.replace_contents(source)
        pdf.add_blank_page(595, 842)
        buffer = io.BytesIO()
        pdf.write(buffer)
        result = self.extract("pdf", buffer.getvalue())
        self.assertEqual(result.status_code, 200)
        self.assertEqual([part["text"] for part in result.json()["slides"]], ["Lecture extract", ""])

    def test_invalid_voice_and_overlength_script(self):
        for amplitude in (5000, 0):
            result = self.client.post("/v1/voices/validate", headers=self.headers,
                                      json={"reference": base64.b64encode(make_wav(4, amplitude=amplitude)).decode()})
            self.assertEqual(result.status_code, 200 if amplitude else 422)
        result = self.client.post("/v1/synthesize", headers=self.headers, json={"text": "x" * (MAX_SCRIPT + 1)})
        self.assertEqual(result.json()["detail"], "scriptlimit")

    def test_long_script_is_written_in_full_with_the_selected_voice(self):
        script = "Mo dau. " + "Noi dung bai giang. " * 1_000 + "Ket thuc."
        model = Mock(sample_rate=24_000)
        model.infer_stream.return_value = iter([np.full(240, 0.25), np.full(480, 0.5)])
        with patch.object(speech, "model", model):
            result = self.client.post("/v1/synthesize", headers=self.headers, json={"text": script, "voice": "Adam"})
        self.assertEqual(result.status_code, 200)
        model.infer_stream.assert_called_once_with(text=script, voice="Adam")
        with wave.open(io.BytesIO(result.content), "rb") as wav:
            self.assertEqual(wav.getnframes(), 720)
            samples = np.frombuffer(wav.readframes(720), dtype="<i2")
            self.assertEqual((int(samples[0]), int(samples[-1])), (8192, 16384))

    def test_prepare_voice_formats_from_a_long_recording(self):
        with TemporaryDirectory() as folder:
            source = Path(folder) / "recording.wav"
            source.write_bytes(make_wav(30, silent_seconds=12))
            for kind in ("wav", "mp3", "m4a", "ogg", "aac", "flac"):
                with self.subTest(kind=kind):
                    recording = source.with_suffix("." + kind)
                    if kind != "wav":
                        subprocess.run(
                            [imageio_ffmpeg.get_ffmpeg_exe(), "-nostdin", "-loglevel", "error", "-i", source, recording],
                            check=True, capture_output=True,
                        )
                    result = self.client.put(
                        f"/v1/voices/prepare/{kind}", headers=self.headers, content=recording.read_bytes(),
                    )
                    self.assertEqual(result.status_code, 200, result.text[:200] if result.status_code != 200 else "")
                    self.assertEqual(result.headers["content-type"], "audio/wav")
                    with wave.open(io.BytesIO(result.content), "rb") as wav:
                        self.assertEqual((wav.getnchannels(), wav.getsampwidth(), wav.getframerate()), (1, 2, 16_000))
                        self.assertEqual(wav.getnframes(), 6 * 16_000)
                        self.assertGreater(np.std(np.frombuffer(wav.readframes(16_000), dtype="<i2")), 32)

    def test_prepare_voice_offset_and_upload_limit(self):
        recording = make_wav(25, silent_seconds=15)
        url = "/v1/voices/prepare/wav"
        for start, status, reason in [(15, 200, None), (0, 422, "voicequiet"), (24, 422, "voicetooshort")]:
            with self.subTest(start=start):
                result = self.client.put(url, params={"start": start}, headers=self.headers, content=recording)
                self.assertEqual(result.status_code, status)
                if reason:
                    self.assertEqual(result.json()["detail"], reason)
        self.assertEqual(self.client.put(url, content=recording).status_code, 401)
        self.assertEqual(self.client.put(url, headers=self.headers, content=b"invalid").json()["detail"], "voiceformat")
        with patch("server.MAX_VOICE_UPLOAD", 100):
            for content in (recording, iter([recording[:50], recording[50:101]])):
                result = self.client.put(url, headers=self.headers, content=content)
                self.assertEqual((result.status_code, result.json()["detail"]), (413, "voiceuploadlimit"))

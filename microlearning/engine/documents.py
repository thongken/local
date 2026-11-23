"""Extract editable narration source from UTF-8 text, PDF pages and PPTX slides."""

import io
import re
from zipfile import ZipFile

from pptx import Presentation
from pptx.enum.shapes import MSO_SHAPE_TYPE
from pypdf import PdfReader


MAX_PARTS = 120
MAX_TEXT = 60_000


def clean(text):
    return re.sub(r"[\x00-\x08\x0b-\x1f\x7f]", "", text.replace("\r\n", "\n").replace("\r", "\n")).strip()


def shape_text(shapes):
    for shape in shapes:
        if shape.shape_type == MSO_SHAPE_TYPE.GROUP:
            yield from shape_text(shape.shapes)
        elif shape.has_table:
            for row in shape.table.rows:
                yield " | ".join(cell.text for cell in row.cells if not cell.is_spanned)
        elif shape.has_text_frame:
            yield shape.text


def pptx_parts(raw):
    # Check archive expansion before opening the presentation.
    with ZipFile(io.BytesIO(raw)) as archive:
        parts = archive.infolist()
        if len(parts) > 4_000 or sum(part.file_size for part in parts) > 80 * 1024**2:
            raise ValueError("documentlimit")
        if any(part.flag_bits & 1 for part in parts) or "ppt/presentation.xml" not in archive.namelist():
            raise ValueError("documentformat")
    ppt = Presentation(io.BytesIO(raw))
    if len(ppt.slides) > MAX_PARTS:
        raise ValueError("documentlimit")
    for slide in ppt.slides:
        title = slide.shapes.title.text if slide.shapes.title is not None else ""
        notes_frame = slide.notes_slide.notes_text_frame if slide.has_notes_slide else None
        yield title, "\n".join(shape_text(slide.shapes)), notes_frame.text if notes_frame is not None else ""


def pdf_parts(raw):
    pdf = PdfReader(io.BytesIO(raw))
    if pdf.is_encrypted:
        raise ValueError("documentformat")
    if len(pdf.pages) > MAX_PARTS:
        raise ValueError("documentlimit")
    for page in pdf.pages:
        yield "", page.extract_text() or "", ""


def text_parts(raw):
    text = raw.decode("utf-8-sig")
    if "\x00" in text:
        raise ValueError("documentformat")
    for part in re.split(r"(?m)^\s*---[ \t]*$", text):
        yield "", part, ""


def extract(kind, raw):
    extractors = {"txt": text_parts, "pdf": pdf_parts, "pptx": pptx_parts}
    if kind not in extractors or (kind == "pdf" and not raw.startswith(b"%PDF-")):
        raise ValueError("documentformat")
    slides = []
    length = 0
    for title, text, notes in extractors[kind](raw):
        slide = {"title": clean(title)[:200], "text": clean(text), "notes": clean(notes)}
        length += len(slide["text"]) + len(slide["notes"])
        slides.append(slide)
        if len(slides) > MAX_PARTS or length > MAX_TEXT:
            raise ValueError("documentlimit")
    if not any(slide["text"] or slide["notes"] for slide in slides):
        raise ValueError("documentempty")
    return slides

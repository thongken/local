"""
pdf_parser.py - Integrated from Process-PDF.
Smart PDF extraction:
1. Multi-column block reading (sort=True).
2. Coordinate filtering (trim 10% top and 10% bottom margins for headers/footers/page numbers).
3. Duplicate image hashing & small icon filtering (logo removal).
4. Selective OCR (Tesseract on scan pages with <30 chars), plus optional page_from/page_to range.
"""

from io import BytesIO
from pathlib import Path
from typing import Optional

import pymupdf as fitz

MIN_IMAGE_WIDTH = 150
MIN_IMAGE_HEIGHT = 150
MIN_IMAGE_BYTES = 5000
MAX_DUPLICATE_BYTES = 50000

OCR_ENABLED = True
OCR_MIN_TEXT_LENGTH = 30

try:
    import pytesseract
    from PIL import Image

    _tesseract_paths = [
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
    ]
    _found_binary = False
    for _path in _tesseract_paths:
        if Path(_path).exists():
            pytesseract.pytesseract.tesseract_cmd = _path
            _found_binary = True
            break
    if not _found_binary:
        import shutil
        _found_binary = shutil.which("tesseract") is not None
    HAS_TESSERACT = _found_binary
except ImportError:
    HAS_TESSERACT = False


def _run_ocr_on_image(image_bytes: bytes) -> str:
    if not HAS_TESSERACT:
        return ""
    try:
        img = Image.open(BytesIO(image_bytes))
        try:
            text = pytesseract.image_to_string(img, lang="vie+eng")
        except pytesseract.TesseractError:
            text = pytesseract.image_to_string(img, lang="eng")
        return text.strip()
    except Exception as e:
        print(f"  [OCR] Error: {e}")
        return ""


def extract_pages(
    pdf_path: str | Path,
    page_from: Optional[int] = None,
    page_to: Optional[int] = None,
    ocr_enabled: bool = OCR_ENABLED,
) -> list[dict]:
    pdf_path = Path(pdf_path)
    if not pdf_path.exists():
        raise FileNotFoundError(f"PDF not found: {pdf_path}")

    doc = fitz.open(str(pdf_path))
    total = len(doc)
    start_idx = max(0, (page_from - 1) if page_from else 0)
    end_idx = min(total, page_to if page_to else total)

    pages_data = []
    seen_image_sizes = set()

    for page_num in range(start_idx, end_idx):
        page = doc[page_num]

        # Step 1: Multi-column block reading + 10% top/bottom margin trim.
        page_height = page.rect.height
        margin_top = page_height * 0.10
        margin_bottom = page_height * 0.90

        blocks = page.get_text("blocks", sort=True)
        valid_text_blocks = []
        for b in blocks:
            x0, y0, x1, y1, block_text, block_no, block_type = b
            if block_type == 0 and y0 >= margin_top and y1 <= margin_bottom:
                valid_text_blocks.append(block_text.strip())
        text = "\n".join(valid_text_blocks)

        # Fallback if strict 10% margin trim dropped everything on a short/cover/slide page.
        if len(text.strip()) < OCR_MIN_TEXT_LENGTH:
            all_blocks = [b[4].strip() for b in blocks if b[6] == 0 and b[4].strip()]
            fallback_text = "\n".join(all_blocks)
            if len(fallback_text) > len(text):
                text = fallback_text

        # Step 2: Extract & filter non-logo images.
        images = []
        for img_info in page.get_images(full=True):
            xref = img_info[0]
            try:
                base_image = doc.extract_image(xref)
                if not base_image or not base_image.get("image"):
                    continue
                img_bytes = base_image["image"]
                img_width = base_image.get("width", 0)
                img_height = base_image.get("height", 0)
                img_size = len(img_bytes)

                if img_width < MIN_IMAGE_WIDTH or img_height < MIN_IMAGE_HEIGHT:
                    continue
                if img_size < MIN_IMAGE_BYTES:
                    continue
                if img_size < MAX_DUPLICATE_BYTES:
                    if img_size in seen_image_sizes:
                        continue
                    seen_image_sizes.add(img_size)
                images.append(img_bytes)
            except Exception:
                continue

        # Step 3: Selective OCR for scan pages.
        has_meaningful_text = len(text.strip()) > OCR_MIN_TEXT_LENGTH
        page_image = None
        ocr_applied = False

        if not has_meaningful_text:
            pix = page.get_pixmap(dpi=200)
            page_image = pix.tobytes("png")
            if ocr_enabled and HAS_TESSERACT:
                ocr_text = _run_ocr_on_image(page_image)
                if len(ocr_text) > OCR_MIN_TEXT_LENGTH:
                    text = ocr_text
                    has_meaningful_text = True
                    ocr_applied = True

        pages_data.append({
            "page_number": page_num + 1,
            "text": text,
            "images": images,
            "page_image": page_image,
            "has_text": has_meaningful_text,
            "ocr_applied": ocr_applied,
        })

    doc.close()
    return pages_data


def get_pdf_metadata(pdf_path: str | Path) -> dict:
    doc = fitz.open(str(pdf_path))
    metadata = {
        "title": doc.metadata.get("title", ""),
        "author": doc.metadata.get("author", ""),
        "total_pages": len(doc),
        "file_size_mb": round(Path(pdf_path).stat().st_size / (1024 * 1024), 2),
    }
    doc.close()
    return metadata


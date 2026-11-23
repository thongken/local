"""
Process-PDF FastAPI HTTP Service for Moodle local_pdfquiz plugin.

Wraps the Process-PDF pipeline:
1. Smart PDF extraction (PyMuPDF multi-column sort=True, 10% top/bottom margin trim,
   duplicate logo hashing, selective Tesseract OCR on scan pages).
2. Semantic page classification (TEXT_HEAVY / MIXED / VISUAL_HEAVY).
3. OpenCV image preprocessing (blur detection, denoise, CLAHE) + Vision LLM analysis.
4. LangChain RecursiveCharacterTextSplitter (3000 chars, 300 overlap) with source page tracking.
5. LiteLLM Gemini routing (language-agnostic, anti-hallucination math prompt,
   Exponential Backoff on 429/503, Quality Gate retry) producing Moodle-ready JSON.
"""

import math
import os
import sys
import tempfile
import time
from pathlib import Path
from typing import Optional

from dotenv import load_dotenv
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, Field

sys.path.insert(0, str(Path(__file__).parent))

load_dotenv(Path(__file__).parent / ".env", override=False)

from src.extractors.pdf_parser import HAS_TESSERACT, extract_pages, get_pdf_metadata
from src.core.classifier import PageType, classify_document, get_routing_summary
from src.core.text_chunking import chunk_pages_text
from src.core.vision_cv import preprocess_for_vision
from src.ai_router import (
    TEXT_MODEL,
    analyze_visual_page,
    generate_quiz,
    generate_summary,
    refine_summary_and_quiz,
)

app = FastAPI(title="Process-PDF Service for Moodle local_pdfquiz", version="1.0.0")


def _normalize_quiz_items(raw_quiz: dict) -> list[dict]:
    """Convert Process-PDF quiz output {options: {A,B,C,D}, correct_answer: 'A'} to Moodle validator format."""
    questions_out = []
    for q in raw_quiz.get("questions", []) if isinstance(raw_quiz, dict) else []:
        if not isinstance(q, dict):
            continue
        qtext = str(q.get("question", "")).strip()
        explanation = str(q.get("explanation", "")).strip() or "Xem lại nội dung tương ứng trong tài liệu PDF."
        raw_opts = q.get("options")
        raw_correct = str(q.get("correct_answer", "")).strip()

        options_list: list[str] = []
        correct_text = ""

        if isinstance(raw_opts, dict):
            ordered_keys = ["A", "B", "C", "D"]
            keys = [k for k in ordered_keys if k in raw_opts] + [k for k in raw_opts.keys() if k not in ordered_keys]
            for k in keys[:4]:
                val = str(raw_opts[k]).strip()
                if val:
                    options_list.append(val)
                    if k.upper() == raw_correct.upper() or val == raw_correct:
                        correct_text = val
        elif isinstance(raw_opts, list):
            for idx, val in enumerate(raw_opts[:4]):
                sval = str(val).strip()
                if sval:
                    options_list.append(sval)
                    letter = ["A", "B", "C", "D"][idx]
                    if raw_correct.upper() == letter or sval == raw_correct:
                        correct_text = sval

        # Ensure 4 unique non-empty options and valid correct_answer.
        deduped = []
        seen = set()
        for o in options_list:
            if o not in seen:
                seen.add(o)
                deduped.append(o)
        if not qtext or len(deduped) != 4:
            continue
        if correct_text not in seen:
            correct_text = deduped[0]

        questions_out.append({
            "question": qtext,
            "options": deduped,
            "correct_answer": correct_text,
            "explanation": explanation,
        })
    return questions_out


def _distribute_questions(total: int, num_buckets: int) -> list[int]:
    """Distribute `total` questions across `num_buckets` non-empty segments."""
    if num_buckets <= 0:
        return []
    buckets = min(total, num_buckets)
    base = total // buckets
    rem = total % buckets
    return [base + (1 if i < rem else 0) for i in range(buckets)]


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "service": "process-pdf",
        "default_model": os.getenv("PDFQUIZ_MODEL", TEXT_MODEL),
        "has_env_api_key": bool(os.getenv("GEMINI_API_KEY", "").strip()),
        "has_tesseract_ocr": bool(HAS_TESSERACT),
    }


@app.post("/api/v1/process-pdf")
async def process_pdf_endpoint(
    file: UploadFile = File(...),
    num_questions: int = Form(5),
    model: str = Form(""),
    api_key: str = Form(""),
    page_from: Optional[int] = Form(None),
    page_to: Optional[int] = Form(None),
    max_pages: int = Form(100),
    enable_vision: int = Form(1),
    enable_ocr: int = Form(1),
) -> dict:
    if num_questions < 1 or num_questions > 30:
        raise HTTPException(status_code=400, detail="num_questions must be between 1 and 30.")

    effective_key = (api_key or "").strip() or os.getenv("GEMINI_API_KEY", "").strip()
    if not effective_key:
        raise HTTPException(
            status_code=400,
            detail="Missing Gemini API key. Provide api_key in the form, Moodle Admin settings, or python_service/.env.",
        )

    effective_model = (model or "").strip() or os.getenv("PDFQUIZ_MODEL", TEXT_MODEL)
    filename = file.filename or "document.pdf"
    if not filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only .pdf files are supported.")

    raw_bytes = await file.read()
    if len(raw_bytes) < 5 or b"%PDF-" not in raw_bytes[:1024]:
        raise HTTPException(status_code=400, detail="Uploaded file is not a valid PDF.")

    start_time = time.time()
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
            tmp.write(raw_bytes)
            tmp_path = Path(tmp.name)

        metadata = get_pdf_metadata(tmp_path)
        total_doc_pages = int(metadata.get("total_pages", 0))
        if total_doc_pages < 1:
            raise HTTPException(status_code=422, detail="PDF contains no readable pages.")

        start_p = max(1, page_from) if page_from else 1
        end_p = min(total_doc_pages, page_to) if page_to else total_doc_pages
        if start_p > end_p:
            raise HTTPException(status_code=400, detail="Invalid page range (page_from > page_to).")
        if (end_p - start_p + 1) > max(1, max_pages):
            raise HTTPException(
                status_code=400,
                detail=f"Selected page range ({end_p - start_p + 1} pages) exceeds max_pages limit ({max_pages}).",
            )

        # Step 1: Smart extraction (multi-column, 10% margin trim, duplicate logo hash, selective OCR).
        pages_data = extract_pages(
            tmp_path,
            page_from=start_p,
            page_to=end_p,
            ocr_enabled=bool(enable_ocr),
        )

        # Step 2: Semantic page classification (TEXT_HEAVY / MIXED / VISUAL_HEAVY).
        classified_pages = classify_document(pages_data)
        routing_summary = get_routing_summary(classified_pages)

        # Step 3: OpenCV preprocessing + Vision LLM for MIXED / VISUAL_HEAVY pages (capped to avoid runaway latency).
        vision_results = []
        max_vision_pages = 8
        vision_count = 0
        if enable_vision:
            for cp in classified_pages:
                if cp.page_type in (PageType.VISUAL_HEAVY, PageType.MIXED) and vision_count < max_vision_pages:
                    image_to_process = cp.page_image or (cp.images[0] if cp.images else None)
                    if image_to_process:
                        cv_result = preprocess_for_vision(image_to_process)
                        vision_analysis = analyze_visual_page(
                            cv_result["processed_image"],
                            text=cp.text,
                            model=effective_model,
                            api_key=effective_key,
                        )
                        vision_results.append({
                            "page_number": cp.page_number,
                            "analysis": vision_analysis,
                            "blur_score": cv_result["blur_score"],
                        })
                        vision_count += 1

        # Step 4: LangChain chunking with source page tracking.
        text_chunks = chunk_pages_text(classified_pages)

        # Combine text chunks + vision page descriptions into unified segments with page bounds.
        segment_inputs: list[dict] = []
        for ch in text_chunks:
            pages = [p for p in ch.get("source_pages", []) if p > 0]
            segment_inputs.append({
                "text": ch["text"],
                "page_start": min(pages) if pages else start_p,
                "page_end": max(pages) if pages else end_p,
            })

        for vr in vision_results:
            analysis = vr.get("analysis")
            if isinstance(analysis, dict) and analysis.get("page_description"):
                desc = str(analysis["page_description"]).strip()
                kps = analysis.get("key_points") or []
                if isinstance(kps, list) and kps:
                    desc += "\n" + "\n".join(f"- {k}" for k in kps if isinstance(k, str))
                segment_inputs.append({
                    "text": desc,
                    "page_start": vr["page_number"],
                    "page_end": vr["page_number"],
                })

        # Fallback: if pages were MIXED/VISUAL without vision enabled, still use extracted text.
        if not segment_inputs:
            raw_combined = "\n\n".join(cp.text.strip() for cp in classified_pages if cp.text.strip())
            if raw_combined:
                segment_inputs.append({
                    "text": raw_combined,
                    "page_start": start_p,
                    "page_end": end_p,
                })

        if not segment_inputs:
            raise HTTPException(
                status_code=422,
                detail="No readable text or visual content could be extracted from this PDF.",
            )

        extracted_text = "\n\n".join(s["text"] for s in segment_inputs)

        # Group segments into at most min(num_questions, 6) balanced buckets so LLM calls stay fast & accurate.
        max_buckets = min(num_questions, 6, len(segment_inputs))
        grouped_segments: list[dict] = []
        bucket_size = math.ceil(len(segment_inputs) / max_buckets)
        for i in range(0, len(segment_inputs), bucket_size):
            grp = segment_inputs[i : i + bucket_size]
            grouped_segments.append({
                "text": "\n\n".join(g["text"] for g in grp),
                "page_start": min(g["page_start"] for g in grp),
                "page_end": max(g["page_end"] for g in grp),
            })

        # Step 5: Generate global summary.
        raw_summary = generate_summary(extracted_text, model=effective_model, api_key=effective_key)
        if isinstance(raw_summary, dict) and raw_summary.get("error"):
            raise HTTPException(status_code=502, detail=f"AI Summary failed: {raw_summary['error']}")

        summary_points = []
        if isinstance(raw_summary, dict):
            raw_pts = raw_summary.get("summary_points") or raw_summary.get("main_points") or []
            if isinstance(raw_pts, list):
                summary_points = [str(p).strip() for p in raw_pts if str(p).strip()]
            short_summary = str(raw_summary.get("short_summary") or "").strip()
            if not short_summary:
                title_part = str(raw_summary.get("title") or "").strip()
                short_summary = (
                    (f"{title_part}: " if title_part else "")
                    + ("; ".join(summary_points[:3]) if summary_points else extracted_text[:300])
                ).strip()
        else:
            short_summary = extracted_text[:300].strip()

        if not summary_points:
            summary_points = [short_summary]

        # Step 6: Generate quiz questions distributed across grouped segments.
        plan = _distribute_questions(num_questions, len(grouped_segments))
        segments_out: list[dict] = []
        for idx, (seg, qcount) in enumerate(zip(grouped_segments, plan)):
            raw_quiz = generate_quiz(
                seg["text"],
                num_questions=qcount,
                model=effective_model,
                api_key=effective_key,
            )
            if isinstance(raw_quiz, dict) and raw_quiz.get("error"):
                raise HTTPException(status_code=502, detail=f"AI Quiz generation failed: {raw_quiz['error']}")
            qs = _normalize_quiz_items(raw_quiz)
            if not qs:
                raise HTTPException(
                    status_code=502,
                    detail=f"AI returned no valid 4-option questions for segment {idx + 1}. Please retry or try another model.",
                )
            seg_sum = f"Nội dung từ trang {seg['page_start']} đến trang {seg['page_end']}."
            if idx < len(summary_points):
                seg_sum = summary_points[idx]
            segments_out.append({
                "page_start": seg["page_start"],
                "page_end": seg["page_end"],
                "segment_summary": seg_sum,
                "quiz": qs,
            })

        elapsed = round(time.time() - start_time, 2)
        return {
            "file_name": filename,
            "page_count": len(pages_data),
            "total_pdf_pages": total_doc_pages,
            "routing_summary": routing_summary,
            "ocr_pages_count": sum(1 for p in pages_data if p.get("ocr_applied")),
            "vision_pages_analyzed": len(vision_results),
            "processing_time_seconds": elapsed,
            "extracted_text": extracted_text[:450000],
            "content": {
                "summary": {
                    "short_summary": short_summary,
                    "main_points": summary_points,
                },
                "segments": segments_out,
            },
        }
    finally:
        if tmp_path and tmp_path.exists():
            try:
                tmp_path.unlink()
            except OSError:
                pass


class RefineRequest(BaseModel):
    extracted_text: str = Field(..., min_length=1)
    content: dict
    feedback: str = Field(..., min_length=1, max_length=1000)
    num_questions: int = Field(5, ge=1, le=30)
    model: str = ""
    api_key: str = ""


@app.post("/api/v1/refine-pdf")
def refine_pdf_endpoint(req: RefineRequest) -> dict:
    effective_key = (req.api_key or "").strip() or os.getenv("GEMINI_API_KEY", "").strip()
    if not effective_key:
        raise HTTPException(
            status_code=400,
            detail="Missing Gemini API key. Provide api_key in the form, Moodle Admin settings, or python_service/.env.",
        )
    effective_model = (req.model or "").strip() or os.getenv("PDFQUIZ_MODEL", TEXT_MODEL)
    start_time = time.time()

    raw = refine_summary_and_quiz(
        source_text=req.extracted_text,
        current_content=req.content,
        feedback=req.feedback,
        num_questions=req.num_questions,
        model=effective_model,
        api_key=effective_key,
    )
    if not isinstance(raw, dict) or raw.get("error") or raw.get("parse_error"):
        err_msg = raw.get("error") if isinstance(raw, dict) else "Invalid AI JSON response"
        raise HTTPException(status_code=502, detail=f"AI refinement failed: {err_msg}")

    raw_sum = raw.get("summary") if isinstance(raw.get("summary"), dict) else {}
    orig_sum = req.content.get("summary") if isinstance(req.content.get("summary"), dict) else {}
    short_summary = str(raw_sum.get("short_summary") or orig_sum.get("short_summary") or "").strip()
    raw_points = raw_sum.get("main_points") or raw_sum.get("summary_points") or orig_sum.get("main_points") or []
    main_points = [str(p).strip() for p in raw_points if str(p).strip()] if isinstance(raw_points, list) else []
    if not short_summary and main_points:
        short_summary = "; ".join(main_points[:3])
    if not main_points and short_summary:
        main_points = [short_summary]

    raw_segs = raw.get("segments") if isinstance(raw.get("segments"), list) else []
    orig_segs = req.content.get("segments") if isinstance(req.content.get("segments"), list) else []
    segments_out = []
    for idx, seg in enumerate(raw_segs):
        if not isinstance(seg, dict):
            continue
        qs = _normalize_quiz_items({"questions": seg.get("quiz", [])})
        if not qs:
            continue
        orig_seg = orig_segs[idx] if idx < len(orig_segs) and isinstance(orig_segs[idx], dict) else {}
        p_start = int(seg.get("page_start") or orig_seg.get("page_start") or 1)
        p_end = int(seg.get("page_end") or orig_seg.get("page_end") or p_start)
        seg_summary = str(seg.get("segment_summary") or orig_seg.get("segment_summary") or f"Trang {p_start}–{p_end}").strip()
        segments_out.append({
            "page_start": max(1, p_start),
            "page_end": max(1, p_end),
            "segment_summary": seg_summary,
            "quiz": qs,
        })

    if not segments_out:
        raise HTTPException(status_code=502, detail="AI refinement produced no valid 4-option questions. Please retry.")

    return {
        "processing_time_seconds": round(time.time() - start_time, 2),
        "content": {
            "summary": {
                "short_summary": short_summary,
                "main_points": main_points,
            },
            "segments": segments_out,
        },
    }


if __name__ == "__main__":
    import uvicorn

    host = os.getenv("PDFQUIZ_HOST", "127.0.0.1")
    port = int(os.getenv("PDFQUIZ_PORT", "8002"))
    uvicorn.run(app, host=host, port=port)


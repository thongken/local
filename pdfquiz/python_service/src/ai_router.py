"""
ai_router.py - Integrated from Process-PDF with per-request API key/model support,
Exponential Backoff for 429/503 Rate Limits, language-agnostic prompts & anti-hallucination.
"""
import base64
import json
import os
import re
import time
from pathlib import Path

import litellm
from dotenv import load_dotenv

litellm.drop_params = True

_env_path = Path(__file__).parent.parent / ".env"
if _env_path.exists():
    load_dotenv(_env_path, override=False)

TEXT_MODEL = os.getenv("PDFQUIZ_MODEL", "gemini/gemini-3.5-flash-lite")
VISION_MODEL = os.getenv("PDFQUIZ_MODEL", "gemini/gemini-3.5-flash-lite")
MAX_OUTPUT_TOKENS = 8192
MAX_RETRIES = 3

SUMMARY_PROMPT = """You are an educational expert. Summarize the following content into a short Micro-learning lecture (3-5 key points).
CRITICAL INSTRUCTIONS:
- You MUST answer in the EXACT SAME LANGUAGE as the source content.
- FOR MATH/PHYSICS EXERCISES WITHOUT PROVIDED SOLUTIONS: DO NOT attempt to calculate or solve the numerical problems yourself. Instead, focus on theory, formulas, or solution methods.
- If there are math formulas, describe them in words. DO NOT use backslash (\\) character in JSON.
- Output MUST be valid JSON.

Content to summarize:
{content}

Return JSON in this format:
{{
  "title": "Course Title",
  "short_summary": "Concise 2-3 sentence summary",
  "summary_points": ["Point 1", "Point 2", "Point 3"],
  "key_concepts": ["concept 1", "concept 2"]
}}"""

QUIZ_PROMPT = """You are a quiz creation expert. Based on the content, create EXACTLY {num_questions} multiple-choice questions.
CRITICAL INSTRUCTIONS:
- You MUST write the questions, options and explanations in the EXACT SAME LANGUAGE as the source content.
- Each question MUST have 4 distinct options (A, B, C, D) and ONLY 1 correct answer.
- FOR MATH/PHYSICS EXERCISES WITHOUT PROVIDED SOLUTIONS: DO NOT attempt to calculate or solve the numerical problems yourself. Instead, create questions that test theory, formulas, or the method of solving.
- If there are math formulas, describe them in words. DO NOT use backslash (\\) character in JSON.
- Output MUST be valid JSON.

Content:
{content}

Return JSON in this format:
{{
  "questions": [
    {{
      "question": "Question text?",
      "options": {{"A": "Option A", "B": "Option B", "C": "Option C", "D": "Option D"}},
      "correct_answer": "A",
      "explanation": "Short explanation"
    }}
  ]
}}"""

VISION_PROMPT = """You are an educational expert. This page contains both text and images.
Here is the extracted text from this page (if any):
---
{text}
---
Please look at the attached image, analyze its visual elements (charts, diagrams, illustrations), and COMBINE it with the extracted text above to provide a comprehensive analysis of the page's educational content.

CRITICAL INSTRUCTIONS:
- You MUST answer in the EXACT SAME LANGUAGE as the source text/image.
- Do NOT use backslash (\\) character in JSON.
Return JSON in this format:
{{
  "page_description": "Detailed description combining text and visual knowledge...",
  "key_points": ["Point 1", "Point 2"],
  "visual_elements": ["Chart 1", "Diagram 2"]
}}"""


def _parse_json_response(response_text: str) -> dict:
    text = (response_text or "").strip()
    if text.startswith("```json"):
        text = text[7:]
    elif text.startswith("```"):
        text = text[3:]
    if text.endswith("```"):
        text = text[:-3]
    try:
        return json.loads(text.strip())
    except Exception:
        return {"raw_text": response_text, "parse_error": True}


def safe_litellm_call(model: str, messages: list, max_tokens: int, api_key: str = "", retry_count: int = 0) -> dict:
    effective_key = (api_key or "").strip() or os.getenv("GEMINI_API_KEY", "").strip()
    try:
        kwargs = {
            "model": model,
            "messages": messages,
            "temperature": 0.3,
            "max_tokens": max_tokens,
            "drop_params": True,
        }
        if effective_key:
            kwargs["api_key"] = effective_key
        response = litellm.completion(**kwargs)
        return _parse_json_response(response.choices[0].message.content)
    except Exception as e:
        error_str = str(e).lower()
        if retry_count >= MAX_RETRIES:
            return {"error": f"Failed after {MAX_RETRIES} retries. Last error: {str(e)[:200]}"}

        if "429" in error_str or "quota" in error_str or "rate limit" in error_str:
            wait_time = 25
            match = re.search(r"retry in ([\d\.]+)s", error_str)
            if match:
                wait_time = int(float(match.group(1))) + 2
            print(f"  [AI Router] RATE LIMIT (429). Backoff: {wait_time}s (attempt {retry_count + 1})")
            time.sleep(wait_time)
            return safe_litellm_call(model, messages, max_tokens, api_key=effective_key, retry_count=retry_count + 1)

        if "503" in error_str or "unavailable" in error_str:
            wait_time = (2 ** retry_count) * 2
            print(f"  [AI Router] 503 Unavailable. Backoff: {wait_time}s (attempt {retry_count + 1})")
            time.sleep(wait_time)
            return safe_litellm_call(model, messages, max_tokens, api_key=effective_key, retry_count=retry_count + 1)

        if "401" in error_str or "403" in error_str or "api key" in error_str or "not found" in error_str:
            return {"error": str(e)[:250]}

        print(f"  [AI Router] Error: {str(e)[:120]}. Retrying in 2s...")
        time.sleep(2)
        return safe_litellm_call(model, messages, max_tokens, api_key=effective_key, retry_count=retry_count + 1)


def call_text_llm(prompt: str, model: str = TEXT_MODEL, max_tokens: int = MAX_OUTPUT_TOKENS, api_key: str = "") -> dict:
    return safe_litellm_call(model, [{"role": "user", "content": prompt}], max_tokens, api_key=api_key)


def call_vision_llm(
    prompt: str,
    image_bytes: bytes,
    model: str = VISION_MODEL,
    max_tokens: int = MAX_OUTPUT_TOKENS,
    api_key: str = "",
) -> dict:
    b64_image = base64.b64encode(image_bytes).decode("utf-8")
    messages = [
        {
            "role": "user",
            "content": [
                {"type": "text", "text": prompt},
                {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{b64_image}"}},
            ],
        }
    ]
    return safe_litellm_call(model, messages, max_tokens, api_key=api_key)


def generate_summary(text: str, model: str = TEXT_MODEL, api_key: str = "") -> dict:
    return call_text_llm(SUMMARY_PROMPT.format(content=text[:12000]), model=model, api_key=api_key)


def generate_quiz(text: str, num_questions: int = 5, model: str = TEXT_MODEL, api_key: str = "") -> dict:
    result = call_text_llm(
        QUIZ_PROMPT.format(content=text[:12000], num_questions=num_questions),
        model=model,
        api_key=api_key,
    )
    if result.get("parse_error"):
        fallback_count = min(3, max(1, num_questions))
        result = call_text_llm(
            QUIZ_PROMPT.format(content=text[:6000], num_questions=fallback_count),
            model=model,
            api_key=api_key,
        )
    return result


def analyze_visual_page(image_bytes: bytes, text: str = "", model: str = VISION_MODEL, api_key: str = "") -> dict:
    prompt = VISION_PROMPT.format(text=text if text else "No text extracted.")
    return call_vision_llm(prompt, image_bytes, model=model, api_key=api_key)


REFINE_PROMPT = """You are an educational expert refining a previously generated micro-learning summary and multiple-choice quiz based on the user's explicit feedback.

USER FEEDBACK / REVISION REQUEST:
{feedback}

SOURCE PDF CONTENT (ground truth — stay accurate to this content):
{source_text}

CURRENT SUMMARY & QUIZ JSON:
{current_json}

CRITICAL INSTRUCTIONS:
- Follow the USER FEEDBACK carefully (e.g., change language to Vietnamese/English, make summaries longer or shorter, make quiz questions more detailed/harder/easier, improve explanations, adjust focus, etc.).
- Keep the EXACT JSON schema below.
- Generate {num_questions} total multiple-choice questions across the segments (unless the user explicitly asked for a different number between 1 and 30).
- Every question MUST have EXACTLY 4 distinct options (A, B, C, D), 1 correct answer key ("A", "B", "C", or "D"), and a helpful explanation.
- Preserve page_start and page_end integers for each segment.
- FOR MATH/PHYSICS EXERCISES WITHOUT PROVIDED SOLUTIONS: DO NOT attempt to calculate numerical answers yourself; focus on theory, formulas, or methods.
- Describe math formulas in words. DO NOT use backslash (\\) characters inside JSON strings.
- Output MUST be valid JSON ONLY (no markdown prose outside JSON).

Return JSON in this exact format:
{{
  "summary": {{
    "short_summary": "Updated concise summary",
    "main_points": ["Point 1", "Point 2", "Point 3"]
  }},
  "segments": [
    {{
      "page_start": 1,
      "page_end": 5,
      "segment_summary": "Updated segment summary",
      "quiz": [
        {{
          "question": "Question text?",
          "options": {{"A": "Option A", "B": "Option B", "C": "Option C", "D": "Option D"}},
          "correct_answer": "A",
          "explanation": "Explanation text"
        }}
      ]
    }}
  ]
}}"""


def refine_summary_and_quiz(
    source_text: str,
    current_content: dict,
    feedback: str,
    num_questions: int = 5,
    model: str = TEXT_MODEL,
    api_key: str = "",
) -> dict:
    prompt = REFINE_PROMPT.format(
        feedback=feedback.strip()[:1000],
        source_text=source_text[:14000],
        current_json=json.dumps(current_content, ensure_ascii=False)[:14000],
        num_questions=max(1, min(30, int(num_questions))),
    )
    result = call_text_llm(prompt, model=model, api_key=api_key)
    if result.get("parse_error"):
        shorter_prompt = REFINE_PROMPT.format(
            feedback=feedback.strip()[:500],
            source_text=source_text[:8000],
            current_json=json.dumps(current_content, ensure_ascii=False)[:8000],
            num_questions=max(1, min(15, int(num_questions))),
        )
        result = call_text_llm(shorter_prompt, model=model, api_key=api_key)
    return result



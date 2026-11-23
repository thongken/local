import { DEFAULT_GEMINI_MODEL } from './config.js';
import { SYSTEM_PROMPT, buildUserPrompt } from './prompt.js';
import { validateContent } from './validator.js';
import { applyKnownTimes } from './segmenter.js';

export class GeminiError extends Error {
  constructor(message, { retryable = false, status = 0 } = {}) {
    super(message);
    this.name = 'GeminiError';
    this.retryable = retryable;
    this.status = status;
  }
}

const sleepDefault = (ms) => new Promise((r) => setTimeout(r, ms));

function stripFences(t) {
  return t.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
}

/** Một lần gọi API. Khóa chỉ nằm trong header, không bao giờ vào URL hay thông báo lỗi. */
export async function callGemini({ apiKey, model = DEFAULT_GEMINI_MODEL, system, user, signal, fetchImpl = fetch }) {
  const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`;
  let res;
  try {
    res = await fetchImpl(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-goog-api-key': apiKey },
      body: JSON.stringify({
        systemInstruction: { parts: [{ text: system }] },
        contents: [{ role: 'user', parts: [{ text: user }] }],
        generationConfig: { responseMimeType: 'application/json', temperature: 0.4 },
      }),
      signal,
    });
  } catch (e) {
    if (e && e.name === 'AbortError') throw e;
    throw new GeminiError('Không kết nối được tới Gemini. Kiểm tra mạng.', { retryable: true });
  }
  if (!res.ok) {
    const s = res.status;
    // Đọc lý do thật Google trả về (không bao giờ chứa API key) để hiện cho người dùng, thay vì câu chung chung.
    let reason = '';
    try {
      const body = await res.json();
      reason = (body && body.error && body.error.message) || '';
    } catch { /* body không phải JSON hoặc rỗng */ }
    const suffix = reason ? ` Chi tiết từ Google: ${reason}` : '';
    if (s === 429) throw new GeminiError(`Gemini đang giới hạn tốc độ hoặc hết hạn mức (429).${suffix}`, { retryable: true, status: s });
    if (s >= 500) throw new GeminiError(`Gemini đang lỗi (${s}).${suffix}`, { retryable: true, status: s });
    if (s === 400 || s === 401 || s === 403) throw new GeminiError(`API key không hợp lệ hoặc không được phép dùng model này.${suffix}`, { status: s });
    if (s === 404) throw new GeminiError(`Không tìm thấy model. Hãy kiểm tra tên model trong phần Nâng cao.${suffix}`, { status: s });
    throw new GeminiError(`Gemini trả về lỗi ${s}.${suffix}`, { status: s });
  }
  let data;
  try { data = await res.json(); } catch { throw new GeminiError('Phản hồi Gemini không đọc được.', { retryable: true }); }
  const cand = data && data.candidates && data.candidates[0];
  const text = cand && cand.content && Array.isArray(cand.content.parts)
    ? cand.content.parts.map((p) => p.text || '').join('') : '';
  if (!text) {
    const why = (data && data.promptFeedback && data.promptFeedback.blockReason) || (cand && cand.finishReason) || '';
    throw new GeminiError(`Gemini không trả về nội dung${why ? ' (' + why + ')' : ''}.`, { retryable: true });
  }
  if (cand.finishReason && cand.finishReason !== 'STOP') {
    throw new GeminiError(`Gemini dừng sớm (${cand.finishReason}).`, { retryable: true });
  }
  const u = data.usageMetadata || {};
  return { text, usage: { prompt: u.promptTokenCount || 0, output: u.candidatesTokenCount || 0, total: u.totalTokenCount || 0 } };
}

/**
 * Sinh summary + quiz. Thử tối đa `attempts` lần với lỗi mạng/5xx/429/JSON hỏng.
 * parts: kết quả groupParts(). Trả về { content, usage }.
 */
export async function generateQuiz({
  apiKey, model, parts, previous = null, feedback = '', questionCount = null, attempts = 3,
  onRetry = () => {}, signal, fetchImpl, sleep = sleepDefault,
}) {
  if (!apiKey) throw new GeminiError('Chưa nhập API key.');
  if (!parts.length) throw new GeminiError('Không có nội dung để tạo quiz.');
  const user = buildUserPrompt(parts, { previous, feedback, questionCount });
  let lastErr;
  for (let n = 1; n <= attempts; n++) {
    try {
      const { text, usage } = await callGemini({ apiKey, model, system: SYSTEM_PROMPT, user, signal, fetchImpl });
      let obj;
      try { obj = JSON.parse(stripFences(text)); }
      catch { throw new GeminiError('Gemini trả về JSON không hợp lệ.', { retryable: true }); }
      const errs = validateContent(obj, parts.length);
      if (questionCount !== null && !errs.length &&
          obj.segments.reduce((total, segment) => total + segment.quiz.length, 0) !== questionCount) {
        errs.push(`Cần đúng ${questionCount} câu hỏi.`);
      }
      if (errs.length) throw new GeminiError('Kết quả không đúng cấu trúc: ' + errs.slice(0, 3).join('; '), { retryable: true });
      return { content: applyKnownTimes(obj, parts), usage };
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      lastErr = e;
      if (!(e instanceof GeminiError) || !e.retryable || n === attempts) throw e;
      onRetry(n, e);
      await sleep(1000 * n);
    }
  }
  throw lastErr;
}

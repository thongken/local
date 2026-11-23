import test from 'node:test';
import assert from 'node:assert/strict';
import { generateQuiz, GeminiError } from '../src/gemini.js';
import { KeyStore } from '../src/keystore.js';
import { buildUserPrompt } from '../src/prompt.js';

const parts = [{ index: 1, start: 0, end: 240, text: '[00:00:00 - 00:00:10] xin chào' }];
const good = () => ({
  summary: { main_points: ['a'], short_summary: 's' },
  segments: [{ segment_index: 9, start_time: 'x', end_time: 'y', segment_summary: 'x',
    quiz: [{ question: 'q?', options: ['a', 'b', 'c', 'd'], correct_answer: 'b', explanation: 'e' }] }],
});
const ok = (obj, extra = {}) => ({ ok: true, status: 200, json: async () => ({
  candidates: [{ finishReason: 'STOP', content: { parts: [{ text: typeof obj === 'string' ? obj : JSON.stringify(obj) }] } }],
  usageMetadata: { promptTokenCount: 10, candidatesTokenCount: 5, totalTokenCount: 15 }, ...extra }) });
const fail = (status) => ({ ok: false, status, json: async () => ({}) });
const noSleep = async () => {};
const run = (seq, extra = {}) => {
  const calls = []; let i = 0;
  const fetchImpl = async (url, init) => { calls.push({ url, init }); const r = seq[Math.min(i++, seq.length - 1)]; if (r instanceof Error) throw r; return r; };
  return { calls, p: generateQuiz({ apiKey: 'SECRET-KEY', parts, fetchImpl, sleep: noSleep, ...extra }) };
};

test('thành công: ghi đè thời gian, trả usage, khóa chỉ ở header', async () => {
  const { calls, p } = run([ok(good())]);
  const r = await p;
  assert.equal(r.content.segments[0].start_time, '00:00:00');
  assert.equal(r.content.segments[0].end_time, '00:04:00');
  assert.equal(r.usage.total, 15);
  assert.ok(!calls[0].url.includes('SECRET-KEY'));
  assert.equal(calls[0].init.headers['x-goog-api-key'], 'SECRET-KEY');
  assert.ok(!calls[0].init.body.includes('SECRET-KEY'));
});
test('thử lại khi 500 rồi thành công', async () => {
  const retries = [];
  const { calls, p } = run([fail(500), ok(good())], { onRetry: (n) => retries.push(n) });
  await p; assert.equal(calls.length, 2); assert.deepEqual(retries, [1]);
});
test('thử lại khi JSON hỏng', async () => {
  const { calls, p } = run([ok('{không phải json'), ok('```json\n' + JSON.stringify(good()) + '\n```')]);
  await p; assert.equal(calls.length, 2);
});
test('thử lại khi sai cấu trúc (thiếu segment)', async () => {
  const bad = good(); bad.segments = [];
  const { calls, p } = run([ok(bad), ok(good())]);
  await p; assert.equal(calls.length, 2);
});
test('lỗi mạng được thử lại, hết 3 lần thì ném lỗi', async () => {
  const { calls, p } = run([new TypeError('net')]);
  await assert.rejects(p, (e) => e instanceof GeminiError && e.retryable);
  assert.equal(calls.length, 3);
});
test('401 không thử lại và thông báo không chứa khóa', async () => {
  const { calls, p } = run([fail(401)]);
  await assert.rejects(p, (e) => !e.retryable && !e.message.includes('SECRET-KEY'));
  assert.equal(calls.length, 1);
});
test('429 được thử lại', async () => {
  const { calls, p } = run([fail(429), ok(good())]); await p; assert.equal(calls.length, 2);
});
test('finishReason MAX_TOKENS bị coi là lỗi có thể thử lại', async () => {
  const cut = ok(good()); const orig = cut.json;
  cut.json = async () => { const d = await orig(); d.candidates[0].finishReason = 'MAX_TOKENS'; return d; };
  const { calls, p } = run([cut, ok(good())]); await p; assert.equal(calls.length, 2);
});
test('sinh lại kèm phản hồi chứa JSON cũ và feedback', async () => {
  const { calls, p } = run([ok(good())], { previous: good(), feedback: 'câu hỏi khó hơn' });
  await p;
  const body = JSON.parse(calls[0].init.body);
  const txt = body.contents[0].parts[0].text;
  assert.ok(txt.includes('câu hỏi khó hơn') && txt.includes('KẾT QUẢ TRƯỚC ĐÓ'));
});
test('thiếu key / thiếu nội dung', async () => {
  await assert.rejects(generateQuiz({ apiKey: '', parts }), GeminiError);
  await assert.rejects(generateQuiz({ apiKey: 'k', parts: [] }), GeminiError);
});
test('prompt có số phần và mốc thời gian', () => {
  const t = buildUserPrompt(parts);
  assert.ok(t.includes('Có 1 phần') && t.includes('PHẦN 1 [00:00:00 - 00:04:00]'));
});

const fake = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, v), removeItem: (k) => m.delete(k), m }; };
test('keystore: mặc định chỉ lưu bộ nhớ', () => {
  const st = fake(); const ks = new KeyStore(st);
  ks.set(' abc ', false);
  assert.equal(ks.get(), 'abc'); assert.equal(st.m.size, 0); assert.equal(ks.isRemembered(), false);
});
test('keystore: ghi nhớ và xóa', () => {
  const st = fake(); const ks = new KeyStore(st);
  ks.set('abc', true); assert.equal(st.m.size, 1); assert.ok(new KeyStore(st).get() === 'abc');
  ks.clear(); assert.equal(st.m.size, 0); assert.equal(ks.get(), '');
});
test('keystore: storage ném lỗi vẫn chạy', () => {
  const bad = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
  const ks = new KeyStore(bad); ks.set('k', true); assert.equal(ks.get(), 'k');
});
import test from 'node:test';
import assert from 'node:assert/strict';
import { Chunker } from '../src/chunker.js';
import { groupParts, fmtTime, applyKnownTimes } from '../src/segmenter.js';
import { validateContent } from '../src/validator.js';
import { FRAME } from '../src/config.js';

const fps = 16000 / FRAME;
const feed = (c, sec, p) => { for (let i = 0; i < Math.round(sec * fps); i++) c.push(p); };

test('không cắt trước 60s dù có im lặng', () => {
  const c = new Chunker(); feed(c, 20, 0.9); feed(c, 5, 0); feed(c, 20, 0.9);
  assert.equal(c.drain().length, 0);
});
test('sau 60s cắt ở khoảng lặng đầu tiên', () => {
  const c = new Chunker(); feed(c, 62, 0.9); feed(c, 1, 0); feed(c, 10, 0.9);
  const out = c.drain(); assert.equal(out.length, 1);
  const secs = out[0].end / 16000; assert.ok(secs > 62 && secs < 63.2, String(secs));
});
test('cắt cứng ở 75s khi nói liên tục', () => {
  const c = new Chunker(); feed(c, 80, 0.9);
  const out = c.drain(); assert.equal(out.length, 1);
  assert.ok(Math.abs(out[0].end / 16000 - 75) < 0.05);
});
test('bỏ chunk toàn im lặng', () => {
  const c = new Chunker(); feed(c, 80, 0.0);
  assert.equal(c.drain().length, 0); assert.equal(c.finish(80 * 16000).length, 0);
});
test('finish đẩy phần còn lại và không vượt tổng mẫu', () => {
  const c = new Chunker(); feed(c, 30, 0.9);
  const out = c.finish(30 * 16000);
  assert.equal(out.length, 1); assert.ok(out[0].end <= 30 * 16000);
});
test('fmtTime', () => assert.equal(fmtTime(3725), '01:02:05'));
test('groupParts chia ~4 phút và giữ thời gian', () => {
  const items = Array.from({ length: 12 }, (_, i) => ({ start: i * 75, end: i * 75 + 70, text: 'xin chào ' + i }));
  const parts = groupParts(items);
  assert.equal(parts.length, 4); assert.equal(parts[0].index, 1);
  assert.ok(parts.every((p) => p.text.includes('[')));
});
test('groupParts rỗng', () => assert.deepEqual(groupParts([{ start: 0, end: 1, text: '  ' }]), []));

const good = () => ({
  summary: { main_points: ['a'], short_summary: 's' },
  segments: [{ segment_index: 1, start_time: '00:00:00', end_time: '00:04:00', segment_summary: 'x',
    quiz: [{ question: 'q?', options: ['a', 'b', 'c', 'd'], correct_answer: 'b', explanation: 'e' }] }]
});
test('validator chấp nhận nội dung đúng', () => assert.deepEqual(validateContent(good(), 1), []));
test('validator bắt lỗi đáp án không khớp', () => {
  const c = good(); c.segments[0].quiz[0].correct_answer = 'z';
  assert.ok(validateContent(c).length > 0);
});
test('validator bắt sai số segments và options trùng', () => {
  const c = good(); c.segments[0].quiz[0].options = ['a', 'a', 'c', 'd'];
  assert.ok(validateContent(c, 2).length >= 2);
});
test('applyKnownTimes ghi đè thời gian', () => {
  const c = good(); applyKnownTimes(c, [{ index: 1, start: 5, end: 245 }]);
  assert.equal(c.segments[0].start_time, '00:00:05'); assert.equal(c.segments[0].end_time, '00:04:05');
});
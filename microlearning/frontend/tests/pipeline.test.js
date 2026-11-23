import test from 'node:test';
import assert from 'node:assert/strict';
import { SampleStore } from '../src/samplestore.js';
import { Pipeline } from '../src/pipeline.js';

test('SampleStore: cắt qua nhiều khối và release', () => {
  const s = new SampleStore();
  s.append(Float32Array.from([1, 2, 3])); s.append(Float32Array.from([4, 5, 6])); s.append(Float32Array.from([7, 8]));
  assert.deepEqual([...s.slice(2, 7)], [3, 4, 5, 6, 7]);
  assert.equal(s.total, 8);
  s.release(6); assert.equal(s.blocks.length, 1);
  assert.deepEqual([...s.slice(6, 8)], [7, 8]);
  assert.deepEqual([...s.slice(6, 100)], [7, 8]);
});

function fakeWorkers({ sttFail = false } = {}) {
  const w = {};
  w.vad = { sent: [], onmessage: null, postMessage(m) {
    this.sent.push(m);
    if (m.type === 'init') queueMicrotask(() => this.onmessage({ data: { type: 'ready' } }));
    if (m.type === 'push' && this.sent.filter((x) => x.type === 'push').length === 2)
      queueMicrotask(() => this.onmessage({ data: { type: 'chunks', chunks: [{ start: 0, end: 4 }, { start: 4, end: 8 }] } }));
    if (m.type === 'finish') queueMicrotask(() => this.onmessage({ data: { type: 'done' } }));
  }, terminate() { this.dead = true; } };
  w.stt = { sent: [], onmessage: null, postMessage(m) {
    this.sent.push(m);
    if (m.type === 'init') queueMicrotask(() => this.onmessage({ data: { type: 'ready', device: 'wasm' } }));
    if (m.type === 'transcribe') {
      if (sttFail) return queueMicrotask(() => this.onmessage({ data: { type: 'error', id: m.id, message: 'boom' } }));
      // trả kết quả đảo thứ tự để kiểm tra việc gán theo id
      setTimeout(() => this.onmessage({ data: { type: 'result', id: m.id, text: 'đoạn ' + m.id } }), m.id === 0 ? 5 : 1);
    }
  }, terminate() { this.dead = true; } };
  return w;
}

test('Pipeline: cắt mẫu theo chunk, STT theo id, finish chờ đủ kết quả', async () => {
  const w = fakeWorkers(); const events = [];
  const p = new Pipeline({ createWorker: (n) => w[n], distUrl: '/d/', model: 'm', device: 'wasm', onEvent: (e) => events.push(e.type) });
  const r = await p.start(); assert.equal(r.device, 'wasm');
  p.feed(Float32Array.from([1, 2, 3, 4])); p.feed(Float32Array.from([5, 6, 7, 8]));
  const out = await p.finish();
  assert.deepEqual(out.map((i) => i.text), ['đoạn 0', 'đoạn 1']);
  assert.deepEqual([...w.stt.sent.filter((m) => m.type === 'transcribe')[1].samples], [5, 6, 7, 8]);
  assert.ok(w.vad.dead && w.stt.dead);
  assert.ok(events.includes('item'));
});
test('Pipeline: giới hạn thời lượng', async () => {
  const w = fakeWorkers();
  const p = new Pipeline({ createWorker: (n) => w[n], distUrl: '/d/', model: 'm', device: 'wasm', maxSamples: 6 });
  await p.start();
  assert.equal(p.feed(new Float32Array(4)), true);
  assert.equal(p.feed(new Float32Array(4)), false);
  assert.equal(p.fed, 6); assert.equal(p.feed(new Float32Array(4)), false);
  p.cancel();
});
test('Pipeline: lỗi STT làm finish bị từ chối', async () => {
  const w = fakeWorkers({ sttFail: true });
  const p = new Pipeline({ createWorker: (n) => w[n], distUrl: '/d/', model: 'm', device: 'wasm' });
  await p.start();
  p.feed(Float32Array.from([1, 2, 3, 4])); p.feed(Float32Array.from([5, 6, 7, 8]));
  await assert.rejects(p.finish(), /STT: boom/);
});
test('Pipeline: cancel ném AbortError', async () => {
  const w = fakeWorkers();
  const p = new Pipeline({ createWorker: (n) => w[n], distUrl: '/d/', model: 'm', device: 'wasm' });
  await p.start();
  const f = p.finish(); p.cancel();
  await assert.rejects(f, (e) => e.name === 'AbortError');
});
import * as ort from 'onnxruntime-web';
import { Chunker } from './chunker.js';
import { FRAME } from './config.js';

const CTX = 64; // Silero v5 @16k cần 64 mẫu ngữ cảnh ở đầu mỗi frame
let session = null, state, ctx, sr, chunker, carry = new Float32Array(0), total = 0;
let queue = Promise.resolve();

const post = (m) => self.postMessage(m);
self.onmessage = (e) => {
  queue = queue.then(() => handle(e.data)).catch((err) => post({ type: 'error', message: String((err && err.message) || err) }));
};

async function prob(frame) {
  const x = new Float32Array(CTX + FRAME);
  x.set(ctx); x.set(frame, CTX);
  ctx = x.slice(-CTX);
  const out = await session.run({
    input: new ort.Tensor('float32', x, [1, x.length]),
    state: new ort.Tensor('float32', state, [2, 1, 128]),
    sr,
  });
  state = Float32Array.from(out.stateN.data);
  return out.output.data[0];
}

async function handle(m) {
  if (m.type === 'init') {
    ort.env.wasm.wasmPaths = m.ortBase;
    ort.env.wasm.numThreads = 1;
    session = await ort.InferenceSession.create(m.modelUrl, { executionProviders: ['wasm'] });
    state = new Float32Array(256); ctx = new Float32Array(CTX);
    sr = new ort.Tensor('int64', BigInt64Array.from([16000n]), []);
    chunker = new Chunker();
    post({ type: 'ready' });
  } else if (m.type === 'push') {
    const merged = new Float32Array(carry.length + m.samples.length);
    merged.set(carry); merged.set(m.samples, carry.length);
    total += m.samples.length;
    let i = 0;
    for (; i + FRAME <= merged.length; i += FRAME) chunker.push(await prob(merged.subarray(i, i + FRAME)));
    carry = merged.slice(i);
    const c = chunker.drain();
    if (c.length) post({ type: 'chunks', chunks: c });
  } else if (m.type === 'finish') {
    const c = chunker.finish(total);
    if (c.length) post({ type: 'chunks', chunks: c });
    post({ type: 'done' });
  }
}
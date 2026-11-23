import { pipeline, env } from '@huggingface/transformers';

let asr = null;
let queue = Promise.resolve();
const files = new Map();
const post = (m) => self.postMessage(m);

self.onmessage = (e) => {
  queue = queue.then(() => handle(e.data)).catch((err) =>
    post({ type: 'error', id: e.data && e.data.id, message: String((err && err.message) || err) }));
};

function onProgress(p) {
  if (p.status === 'progress' && p.total) {
    files.set(p.file, { loaded: p.loaded, total: p.total });
    let loaded = 0, total = 0;
    for (const f of files.values()) { loaded += f.loaded; total += f.total; }
    post({ type: 'download', loaded, total });
  }
}

/**
 * models: { webgpu: '...whisper-small', wasm: '...whisper-base' }.
 * Luôn thử WebGPU với model nặng hơn trước; nếu không chạy được thì dùng
 * model NHẸ HƠN trên WASM một luồng, chứ không ép model nặng chạy CPU.
 */
async function load({ models, ortBase, wantWebgpu }) {
  env.allowLocalModels = false;
  env.useBrowserCache = true;
  env.backends.onnx.wasm.wasmPaths = ortBase;
  env.backends.onnx.wasm.numThreads = 1;
  const make = (model, dev, dtype) => pipeline('automatic-speech-recognition', model, { device: dev, dtype, progress_callback: onProgress });
  if (wantWebgpu) {
    try {
      asr = await make(models.webgpu, 'webgpu', { encoder_model: 'fp32', decoder_model_merged: 'q4' });
      return { device: 'webgpu', model: models.webgpu };
    } catch { asr = null; files.clear(); }
  }
  asr = await make(models.wasm, 'wasm', 'q8');
  return { device: 'wasm', model: models.wasm };
}

async function handle(m) {
  if (m.type === 'init') {
    const r = await load(m);
    post({ type: 'ready', device: r.device, model: r.model });
  } else if (m.type === 'transcribe') {
    const out = await asr(m.samples, { language: 'vietnamese', task: 'transcribe', is_multilingual: true, chunk_length_s: 30, stride_length_s: 5 });
    post({ type: 'result', id: m.id, text: String((out && out.text) || '').trim() });
  }
}
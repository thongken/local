import { SAMPLE_RATE, MAX_AUDIO_SEC } from './config.js';
import { SampleStore } from './samplestore.js';

/**
 * Nối: mẫu âm thanh -> VAD worker (cắt chunk) -> STT worker (Whisper) -> danh sách đoạn.
 * createWorker(name) trả về Worker ('vad' | 'stt'); được tiêm vào để dễ kiểm thử.
 */
export class Pipeline {
  constructor({ createWorker, distUrl, models, wantWebgpu, onEvent = () => {}, maxSamples = MAX_AUDIO_SEC * SAMPLE_RATE }) {
    this.createWorker = createWorker; this.distUrl = distUrl; this.models = models; this.wantWebgpu = wantWebgpu;
    this.onEvent = onEvent; this.maxSamples = maxSamples;
    this.store = new SampleStore();
    this.items = [];
    this.pending = 0; this.vadDone = false; this.limitReached = false;
    this.cancelled = false; this.fed = 0;
  }

  _defer() { let resolve, reject; const p = new Promise((a, b) => { resolve = a; reject = b; }); p.catch(() => {}); return { p, resolve, reject }; }

  async start() {
    this.vad = this.createWorker('vad');
    this.stt = this.createWorker('stt');
    const vadReady = this._defer(), sttReady = this._defer();
    this.readyDeferreds = [vadReady, sttReady];
    this.finishD = this._defer();
    this.vad.onmessage = (e) => {
      const m = e.data;
      if (m.type === 'ready') vadReady.resolve();
      else if (m.type === 'chunks') this._onChunks(m.chunks);
      else if (m.type === 'done') { this.vadDone = true; this._check(); }
      else if (m.type === 'error') this._fail(new Error('VAD: ' + m.message), [vadReady, sttReady]);
    };
    this.stt.onmessage = (e) => {
      const m = e.data;
      if (m.type === 'ready') sttReady.resolve({ device: m.device, model: m.model });
      else if (m.type === 'download') this.onEvent({ type: 'download', loaded: m.loaded, total: m.total });
      else if (m.type === 'result') this._onResult(m);
      else if (m.type === 'error') this._fail(new Error('STT: ' + m.message), [vadReady, sttReady]);
    };
    const onErr = (who) => (e) => this._fail(new Error(`${who} worker lỗi: ${(e && e.message) || 'không rõ'}`), [vadReady, sttReady]);
    this.vad.onerror = onErr('VAD'); this.stt.onerror = onErr('STT');
    this.vad.postMessage({ type: 'init', ortBase: this.distUrl + 'ort/', modelUrl: this.distUrl + 'silero_vad_v5.onnx' });
    this.stt.postMessage({ type: 'init', models: this.models, ortBase: this.distUrl + 'ort/', wantWebgpu: this.wantWebgpu });
    const [, sttInfo] = await Promise.all([vadReady.p, sttReady.p]);
    return sttInfo;
  }

  /** Đưa thêm mẫu 16 kHz. Trả về false khi đã chạm giới hạn thời lượng. */
  feed(samples) {
    if (this.cancelled || this.limitReached) return false;
    const room = this.maxSamples - this.fed;
    if (samples.length >= room) { samples = samples.subarray(0, Math.max(0, room)); this.limitReached = true; }
    if (!samples.length) return false;
    this.fed += samples.length;
    this.store.append(samples);
    const copy = samples.slice();
    this.vad.postMessage({ type: 'push', samples: copy }, [copy.buffer]);
    return !this.limitReached;
  }

  _onChunks(chunks) {
    for (const c of chunks) {
      const id = this.items.length;
      this.items.push({ start: c.start / SAMPLE_RATE, end: c.end / SAMPLE_RATE, text: '' });
      const s = this.store.slice(c.start, c.end);
      this.store.release(c.end);
      this.pending++;
      this.stt.postMessage({ type: 'transcribe', id, samples: s }, [s.buffer]);
      this.onEvent({ type: 'chunk', index: id, total: this.items.length });
    }
  }
  _onResult(m) {
    this.items[m.id].text = m.text;
    this.pending--;
    this.onEvent({ type: 'item', index: m.id, item: this.items[m.id], done: this.items.length - this.pending, total: this.items.length });
    this._check();
  }
  _check() {
    if (this.vadDone && this.pending === 0 && this.finishD) this.finishD.resolve(this.items.filter((i) => i.text));
  }
  _fail(err, extra = []) {
    if (this.cancelled) return;
    this.onEvent({ type: 'error', error: err });
    if (this.finishD) this.finishD.reject(err);
    extra.forEach((d) => d.reject(err));
  }

  /** Báo hết âm thanh, chờ STT xong, trả về [{start,end,text}] (giây). */
  async finish() {
    this.vad.postMessage({ type: 'finish' });
    try { return await this.finishD.p; } finally { this.terminate(); }
  }

  cancel() {
    this.cancelled = true;
    const e = new Error('Đã hủy'); e.name = 'AbortError';
    if (this.finishD) this.finishD.reject(e);
    (this.readyDeferreds || []).forEach((d) => d.reject(e));
    this.terminate();
  }
  terminate() { try { this.vad && this.vad.terminate(); } catch {} try { this.stt && this.stt.terminate(); } catch {} }
}

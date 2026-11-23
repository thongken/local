import { el, mount, clear } from './dom.js';
import { S } from './strings.js';
import { KeyStore } from './keystore.js';
import { decodeFile, startMic, UserError } from './audio.js';
import { Pipeline } from './pipeline.js';
import { fullTranscript, groupParts } from './segmenter.js';
import { generateQuiz, GeminiError } from './gemini.js';
import { validateContent } from './validator.js';
import { confirmDownload, onReadyEvent } from './modelmanager.js';
import { ProgressView } from './progress.js';
import { DEFAULT_GEMINI_MODEL, MAX_AUDIO_SEC } from './config.js';
import { callAjax } from './ajax.js';

// Ưu tiên tốc độ: luôn dùng whisper-base (dù chạy GPU hay CPU), không dùng whisper-small.
const STT_MODELS = { webgpu: 'onnx-community/whisper-base', wasm: 'onnx-community/whisper-base' };

function errorText(e) {
  if (e instanceof UserError || e instanceof GeminiError) return e.message;
  return 'Có lỗi xảy ra: ' + ((e && e.message) || String(e));
}

class App {
  constructor(root, opts) {
    this.root = root; this.kind = opts.kind; this.distUrl = opts.distUrl;
    this.maxSeconds = opts.maxSeconds || MAX_AUDIO_SEC;
    this.rev = opts.rev || '';
    this.keystore = new KeyStore();
    this.wantWebgpu = typeof navigator !== 'undefined' && !!navigator.gpu;
    this.geminiModel = DEFAULT_GEMINI_MODEL;
    this.state = { parts: [], content: null, usage: null, durationSec: 0, file: null, micStop: null };
    this.render();
  }

  render() {
    clear(this.root);
    const persistKey = () => this.keystore.set(this.apiKeyInput.value, this.rememberBox.checked);
    this.apiKeyInput = el('input', { type: 'password', class: 'form-control', id: 'ml-key',
      placeholder: S.apiKeyPlaceholder, value: this.keystore.get(), oninput: persistKey });
    this.rememberBox = el('input', { type: 'checkbox', class: 'form-check-input', id: 'ml-remember',
      checked: this.keystore.isRemembered(), onchange: persistKey });
    this.titleInput = el('input', { type: 'text', class: 'form-control', placeholder: S.titlePlaceholder });
    this.modelSelect = el('select', { class: 'form-select' },
      ['gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.1-flash-lite'].map((m) =>
        el('option', { value: m, selected: m === this.geminiModel ? '' : null }, m)));
    this.progressHost = el('div', { class: 'ml-progress-host' });
    this.progress = new ProgressView(this.progressHost);
    this.resultHost = el('div', { class: 'ml-result-host' });
    this.errorHost = el('div', { class: 'ml-error-host' });

    const keyCard = el('div', { class: 'card mb-3' }, el('div', { class: 'card-body' }, [
      el('label', { class: 'form-label fw-bold', for: 'ml-key' }, S.apiKeyLabel),
      this.apiKeyInput,
      el('div', { class: 'form-text' }, S.apiKeyHelp),
      el('div', { class: 'form-check mt-2' }, [this.rememberBox,
        el('label', { class: 'form-check-label', for: 'ml-remember' }, S.rememberKey)]),
    ]));

    const sourceCard = this.kind === 'audio' ? this.renderFileSource() : this.renderRecordSource();

    const titleCard = el('div', { class: 'card mb-3' }, el('div', { class: 'card-body' }, [
      el('label', { class: 'form-label fw-bold' }, S.titleLabel), this.titleInput,
    ]));

    const advanced = el('details', { class: 'mb-3' }, [
      el('summary', {}, S.advanced),
      el('div', { class: 'mt-2' }, [el('label', { class: 'form-label' }, S.modelLabel), this.modelSelect]),
    ]);

    this.processBtn = el('button', { class: 'btn btn-primary', onclick: () => this.onProcess() }, S.processBtn);
    this.cancelBtn = el('button', { class: 'btn btn-outline-secondary ms-2 d-none', onclick: () => this.onCancel() }, S.cancelBtn);

    mount(this.root, [keyCard, sourceCard, titleCard, advanced,
      el('div', {}, [this.processBtn, this.cancelBtn]), this.errorHost, this.progressHost, this.resultHost]);
  }

  renderFileSource() {
    this.fileInput = el('input', { type: 'file', accept: 'audio/*', class: 'form-control',
      onchange: (e) => { this.state.file = e.target.files[0] || null; } });
    return el('div', { class: 'card mb-3' }, el('div', { class: 'card-body' }, [
      el('label', { class: 'form-label fw-bold' }, S.chooseFile), this.fileInput,
    ]));
  }

  renderRecordSource() {
    this.recordBtn = el('button', { class: 'btn btn-danger', onclick: () => this.toggleRecord() }, S.startRecord);
    this.recordStatus = el('div', { class: 'form-text' }, S.autoStopAt20);
    return el('div', { class: 'card mb-3' }, el('div', { class: 'card-body' }, [this.recordBtn, this.recordStatus]));
  }

  async toggleRecord() {
    if (this.state.micStop) { this.stopRecording(); return; }
    this.showError(null);
    this.state.samples = [];
    this.state.recStart = Date.now();
    try {
      const h = await startMic({ distUrl: this.distUrl, rev: this.rev, onSamples: (s) => {
        this.state.samples.push(s);
        if ((Date.now() - this.state.recStart) / 1000 >= this.maxSeconds) this.stopRecording();
      } });
      this.state.micStop = h.stop;
      this.recordBtn.textContent = S.stopRecord;
      this.recordBtn.classList.replace('btn-danger', 'btn-outline-danger');
    } catch (e) { this.showError(errorText(e)); }
  }
  stopRecording() {
    if (!this.state.micStop) return;
    this.state.micStop(); this.state.micStop = null;
    this.recordBtn.textContent = S.startRecord;
    this.recordBtn.classList.replace('btn-outline-danger', 'btn-danger');
  }

  showError(msg) {
    clear(this.errorHost);
    if (msg) mount(this.errorHost, el('div', { class: 'alert alert-danger' }, msg));
  }

  async onProcess() {
    this.showError(null);
    const apiKey = this.apiKeyInput.value.trim();
    const title = this.titleInput.value.trim();
    this.geminiModel = this.modelSelect.value;
    if (!apiKey) return this.showError(S.errNoKey);
    if (this.kind === 'audio' && !this.state.file) return this.showError(S.errNoFile);
    if (!title) return this.showError(S.errNoTitle);
    this.keystore.set(apiKey, this.rememberBox.checked);

    const likelyModel = this.wantWebgpu ? STT_MODELS.webgpu : STT_MODELS.wasm;
    const ok = await confirmDownload(likelyModel);
    if (!ok) return this.showError(S.consentDeclined);

    this.controller = new AbortController();
    this.processBtn.disabled = true;
    this.cancelBtn.classList.remove('d-none');
    clear(this.resultHost);
    try {
      let samples, durationSec;
      if (this.kind === 'audio') {
        this.progress.setStep(S.stepDecode);
        samples = await decodeFile(this.state.file, this.maxSeconds);
        durationSec = Math.round(samples.length / 16000);
      } else {
        if (!this.state.samples || !this.state.samples.length) return this.showError(S.errNoFile);
        this.stopRecording();
        const total = this.state.samples.reduce((n, s) => n + s.length, 0);
        samples = new Float32Array(total);
        let o = 0; for (const s of this.state.samples) { samples.set(s, o); o += s.length; }
        durationSec = Math.round(samples.length / 16000);
      }
      this.state.durationSec = durationSec;

      this.progress.setStep(S.stepDownload);
      const pipeline = new Pipeline({
        distUrl: this.distUrl, models: STT_MODELS, wantWebgpu: this.wantWebgpu,
        createWorker: (n) => new Worker(this.distUrl + n + '.worker.js?v=' + this.rev, { type: 'module' }),
        onEvent: (e) => {
          if (e.type === 'download' && e.total) this.progress.setStep(S.stepDownload, e.loaded / e.total);
          else if (e.type === 'item') this.progress.setStep(this.transcribeLabel, e.done / Math.max(1, e.total));
        },
      });
      const { device, model } = await pipeline.start();
      const sttT0 = performance.now(); // bấm giờ SAU khi model đã sẵn sàng, không tính thời gian tải model
      onReadyEvent(model);
      this.state.device = device;
      this.transcribeLabel = S.stepTranscribe + (device === 'wasm' ? S.cpuNote : S.gpuNote);
      this.progress.setStep(this.transcribeLabel, 0);
      const chunkMs = 4000, step = 16000 * (chunkMs / 1000);
      for (let i = 0; i < samples.length; i += step) {
        if (this.controller.signal.aborted) { pipeline.cancel(); throw this._aborted(); }
        pipeline.feed(samples.subarray(i, i + step));
        await new Promise((r) => setTimeout(r, 0));
      }
      const items = await pipeline.finish();
      this.state.sttElapsedSec = (performance.now() - sttT0) / 1000;
      if (!items.length) throw new UserError(S.errNoSpeech);
      this.state.transcript = fullTranscript(items);
      this.state.parts = groupParts(items);

      await this.runGemini(apiKey);
    } catch (e) {
      if (!(e && e.name === 'AbortError')) this.showError(errorText(e));
    } finally {
      this.processBtn.disabled = false;
      this.cancelBtn.classList.add('d-none');
      this.progress.setStep('', null);
    }
  }

  _aborted() { const e = new Error('Đã hủy'); e.name = 'AbortError'; return e; }
  onCancel() { if (this.controller) this.controller.abort(); if (this.state.pipeline) this.state.pipeline.cancel(); }

  async runGemini(apiKey, { previous = null, feedback = '' } = {}) {
    this.progress.setStep(S.stepGemini);
    const { content, usage } = await generateQuiz({
      apiKey, model: this.geminiModel, parts: this.state.parts, previous, feedback,
      signal: this.controller && this.controller.signal,
      onRetry: (n) => this.progress.setStep(`${S.stepGemini} (thử lại lần ${n})`),
    });
    this.state.content = content; this.state.usage = usage;
    this.renderResult();
  }

  /** Vô hiệu hóa nút + đổi chữ sang trạng thái đang chạy trong lúc chờ promise, rồi khôi phục. */
  async withLoading(btn, loadingText, fn) {
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = loadingText;
    try { await fn(); }
    finally { btn.disabled = false; btn.textContent = original; }
  }

  renderResult() {
    const c = this.state.content;
    const retryBtn = el('button', { class: 'btn btn-outline-primary',
      onclick: () => this.withLoading(retryBtn, S.retryingBtn, () => this.retry()) }, S.retryBtn);
    const feedback = el('textarea', { class: 'form-control mb-2', rows: 2, placeholder: S.feedbackPlaceholder });
    const regenBtn = el('button', { class: 'btn btn-outline-primary',
      onclick: () => this.withLoading(regenBtn, S.regeneratingBtn, () => this.regenerate(feedback.value)) }, S.regenBtn);
    const saveBtn = el('button', { class: 'btn btn-success',
      onclick: () => this.withLoading(saveBtn, S.savingBtn, () => this.save()) }, S.saveBtn);
    mount(this.resultHost, [
      el('h3', {}, S.done),
      el('p', { class: 'text-muted' }, this.sttTimingLine()),
      el('p', {}, c.summary.short_summary),
      el('ul', {}, c.summary.main_points.map((p) => el('li', {}, p))),
      ...c.segments.map((seg, i) => el('div', { class: 'card mb-2' }, el('div', { class: 'card-body' }, [
        el('h5', {}, `Phần ${i + 1} (${seg.start_time} - ${seg.end_time})`),
        el('p', {}, seg.segment_summary),
        ...seg.quiz.map((q, j) => el('div', { class: 'mb-2' }, [
          el('strong', {}, `${j + 1}. ${q.question}`),
          el('ul', {}, q.options.map((o) => el('li', {}, o))),
        ])),
      ]))),
      el('div', { class: 'mb-2' }, [retryBtn]),
      el('div', { class: 'mb-2' }, [feedback, regenBtn]),
      saveBtn,
    ]);
  }

  sttTimingLine() {
    const sec = this.state.sttElapsedSec, dur = this.state.durationSec;
    if (!sec || !dur) return '';
    const ratio = dur / sec;
    return `Nhận dạng giọng nói (${this.state.device === 'wasm' ? 'CPU' : 'GPU'}): ${sec.toFixed(1)}s cho ${dur}s audio `
      + `(nhanh gấp ${ratio.toFixed(2)}x so với thời lượng thật${ratio < 1 ? ', tức CHẬM HƠN thời gian thực' : ''}).`;
  }

  async retry() {
    try { await this.runGemini(this.keystore.get()); } catch (e) { this.showError(errorText(e)); }
  }
  async regenerate(feedback) {
    try { await this.runGemini(this.keystore.get(), { previous: this.state.content, feedback }); }
    catch (e) { this.showError(errorText(e)); }
  }

  async save() {
    this.showError(null);
    const errs = validateContent(this.state.content, this.state.parts.length);
    if (errs.length) return this.showError('Nội dung không hợp lệ: ' + errs.slice(0, 3).join('; '));
    try {
      this.progress.setStep(S.stepSaving);
      const res = await callAjax('local_microlearning_save_item', {
        kind: this.kind, title: this.titleInput.value.trim(),
        transcript: this.state.transcript, content: JSON.stringify(this.state.content),
        durationsec: this.state.durationSec,
      });
      this.progress.setStep('', null);
      const url = this.distUrl.replace(/\/dist\/$/, '/') + 'view.php?id=' + res.id;
      mount(this.errorHost, el('div', { class: 'alert alert-success' }, [S.savedOk + ' ',
        el('a', { href: url }, S.viewResultBtn)]));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) { this.showError(errorText(e)); }
  }
}

export function init(root, opts) { new App(root, opts); }
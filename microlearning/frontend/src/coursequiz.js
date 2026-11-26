import { el, mount, clear } from './dom.js';
import { KeyStore } from './keystore.js';
import { decodeFile, startMic, validateAudioFile, wavBlob } from './audio.js';
import { Pipeline } from './pipeline.js';
import { fullTranscript, groupParts } from './segmenter.js';
import { planQuizParts } from './quizplan.js';
import { generateQuiz } from './gemini.js';
import { callAjax } from './ajax.js';
import { confirmDownload, onReadyEvent } from './modelmanager.js';
import { ProgressView } from './progress.js';
import { DEFAULT_GEMINI_MODEL, SAMPLE_RATE } from './config.js';
import { S } from './strings.js';

const MODEL = 'onnx-community/whisper-base';
const button = (label, fn, style = 'primary') => el('button', {type: 'button', class: `btn btn-${style}`, onclick: fn}, label);
const field = (label, input) => el('div', {class: 'mb-3'}, [
  el('label', {class: 'form-label fw-semibold', for: input.id}, label), input,
]);
const card = (title, children) => el('section', {class: 'card mb-3'}, el('div', {class: 'card-body'}, [
  el('h3', {class: 'h5 card-title'}, title), ...children,
]));

class CourseQuiz {
  constructor(root, opts) {
    this.root = root; this.opts = opts; this.key = new KeyStore();
    this.samples = []; this.sampleCount = 0; this.busy = false;
    this.created = null; this.quizDraft = null; this.parts = null; this.transcript = null;
    this.onUnload = event => {
      if (this.busy || this.recording || this.micStarting) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', this.onUnload);
    window.addEventListener('pagehide', () => { this.stopRecording(); this.mic?.stop(); this.mic = null; this.pipeline?.cancel(); });
    this.render();
    if (this.opts.kind === 'record') queueMicrotask(() => this.startMicMonitor());
  }

  render() {
    this.title = el('input', {id: 'ml-quiz-title', class: 'form-control', type: 'text', maxlength: 255,
      placeholder: 'Ví dụ: Trắc nghiệm — Bài 1'});
    this.count = el('input', {id: 'ml-quiz-count', class: 'form-control', type: 'number', min: 1, max: 30, value: 10});
    this.apiKey = el('input', {id: 'ml-quiz-key', class: 'form-control', type: 'password', autocomplete: 'off', value: this.key.get()});
    this.remember = el('input', {id: 'ml-quiz-remember', type: 'checkbox', class: 'form-check-input', checked: this.key.isRemembered()});
    this.note = el('textarea', {id: 'ml-quiz-note', class: 'form-control', rows: 3, placeholder: S.feedbackPlaceholder});
    const persist = () => this.key.set(this.apiKey.value, this.remember.checked);
    this.apiKey.addEventListener('input', persist); this.remember.addEventListener('change', persist);
    this.progressHost = el('div', {class: 'mb-3', role: 'status', 'aria-live': 'polite'});
    this.progress = new ProgressView(this.progressHost);
    this.error = el('div', {role: 'alert', tabindex: '-1'});
    this.result = el('div', {'aria-live': 'polite'});
    this.process = button('Sinh câu hỏi', () => this.run());
    this.cancel = button('Hủy xử lý', () => this.controller?.abort(), 'outline-secondary');
    this.cancel.classList.add('d-none');
    const sourceCard = this.opts.kind === 'audio' ? this.audioCard() : this.recordCard();
    this.form = el('form', {}, [
      sourceCard,
      card('2. Thiết lập bài trắc nghiệm', [field('Tên bài trắc nghiệm', this.title), field('Số câu hỏi (1–30)', this.count)]),
      card('3. Ghi chú cho AI (tuỳ chọn)', [field('Yêu cầu thêm (ví dụ: câu hỏi khó hơn, tập trung vào phần X)', this.note)]),
      card('4. Kết nối AI', [field(S.apiKeyLabel, this.apiKey), el('p', {class: 'form-text'}, S.apiKeyHelp),
        el('div', {class: 'form-check mb-2'}, [this.remember,
          el('label', {class: 'form-check-label', for: this.remember.id}, S.rememberKey)])]),
      el('p', {class: 'text-muted'}, 'Sau khi tạo, bạn xem trước nội dung ngay tại đây rồi mới chọn lưu vào Moodle.'),
      el('div', {class: 'd-flex gap-2 mb-3'}, [this.process, this.cancel]),
    ]);
    this.form.addEventListener('submit', event => { event.preventDefault(); this.run(); });
    mount(this.root, [this.form, this.error, this.progressHost, this.result]);
  }

  audioCard() {
    this.source = el('select', {id: 'ml-quiz-source', class: 'form-select'}, [
      el('option', {value: ''}, 'Tải một tệp audio mới'),
      ...(this.opts.sources || []).map(item => el('option', {value: item.id}, item.label)),
    ]);
    this.file = el('input', {id: 'ml-quiz-file', type: 'file', class: 'form-control', accept: '.mp3,.wav,.m4a,.ogg'});
    this.clearFile = button('Xóa file đã chọn', () => {
      this.file.value = ''; this.requestSignature = null; this.showError('');
    }, 'outline-secondary');
    const fileField = field('File audio', el('div', {class: 'd-flex gap-2 flex-wrap'}, [this.file, this.clearFile]));
    this.source.addEventListener('change', () => {
      this.requestSignature = null;
      fileField.classList.toggle('d-none', !!this.source.value);
    });
    this.file.addEventListener('change', () => {
      this.requestSignature = null;
      this.showError('');
      const file = this.file.files[0];
      if (!file) return;
      try {
        validateAudioFile(file, this.opts.maxFileBytes);
        if (!this.title.value.trim()) this.title.value = 'Trắc nghiệm — ' + file.name.replace(/\.[^.]+$/, '');
      } catch (error) { this.showError(error.message); }
    });
    return card('1. Chọn nguồn tài liệu', [field('Nguồn tạo câu hỏi', this.source), fileField,
      el('p', {class: 'form-text'}, `Hỗ trợ MP3, WAV, M4A, OGG; tối đa ${Math.floor(this.opts.maxFileBytes / 1024 / 1024)} MB và 30 phút. PDF/MP4 chưa được hỗ trợ.`),
      el('p', {class: 'form-text'}, 'Có thể chọn bất kỳ audio hợp lệ trong các tài nguyên File của khóa học, kể cả tệp đính kèm không phải file chính.'),
    ]);
  }

  recordCard() {
    this.record = button('Bắt đầu ghi âm', () => this.toggleMic(), 'danger');
    this.micLabel = el('span', {class: 'ml-mic-label'}, 'Đang yêu cầu quyền truy cập micro…');
    this.micMeter = el('div', {class: 'ml-mic-meter', role: 'meter', 'aria-label': 'Mức âm thanh micro',
      'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': 0},
      el('div', {class: 'ml-mic-meter-fill'}));
    this.micMeterFill = this.micMeter.firstChild;
    this.micIndicator = el('div', {class: 'ml-mic-indicator', role: 'status', 'aria-live': 'polite'}, [
      el('span', {class: 'ml-mic-dot', 'aria-hidden': 'true'}), this.micLabel,
    ]);
    this.recordStatus = el('p', {class: 'form-text mb-0'}, 'Đang kết nối micro…');
    this.process.disabled = true;
    return card('1. Ghi âm nội dung bài học', [this.micIndicator, this.micMeter,
      this.recordStatus, this.record,
      el('p', {class: 'form-text mt-2'}, 'Ghi âm xong, điền thông tin bên dưới rồi bấm "Sinh câu hỏi".')]);
  }

  setMicState(state, label) {
    this.micIndicator?.classList.toggle('is-recording', state === 'recording');
    this.micIndicator?.classList.toggle('is-error', state === 'error');
    if (this.micLabel) this.micLabel.textContent = label;
    if (state !== 'recording' && this.micMeterFill) {
      this.micMeterFill.style.width = '0%';
      this.micMeter?.setAttribute('aria-valuenow', '0');
    }
  }

  updateMicMeter(samples) {
    let sum = 0;
    for (let i = 0; i < samples.length; i++) sum += samples[i] * samples[i];
    const level = Math.min(100, Math.round(Math.sqrt(sum / Math.max(1, samples.length)) * 1200));
    this.micMeterFill.style.width = level + '%';
    this.micMeter.setAttribute('aria-valuenow', String(level));
  }

  async startMicMonitor() {
    if (this.mic || this.micStarting) return;
    this.micStarting = true;
    this.record.disabled = true;
    this.setMicState('idle', 'Đang yêu cầu quyền truy cập micro…');
    try {
      this.mic = await startMic({distUrl: this.opts.distUrl, rev: this.opts.rev,
        onSamples: samples => this.handleMicSamples(samples)});
      this.setMicState('recording', 'Micro đang hoạt động');
      this.recordStatus.textContent = 'Micro sẵn sàng. Nhấn “Bắt đầu ghi âm” khi bạn muốn ghi.';
    } catch (error) {
      this.setMicState('error', 'Không truy cập được micro');
      this.showError(error.message);
    } finally {
      this.micStarting = false;
      this.record.disabled = false;
    }
  }

  handleMicSamples(samples) {
    this.updateMicMeter(samples);
    if (!this.recording) return;
    const room = this.opts.maxSeconds * SAMPLE_RATE - this.sampleCount;
    const part = samples.subarray(0, Math.max(0, room));
    if (part.length) {
      this.samples.push(part.slice()); this.sampleCount += part.length;
      if (this.pipelineQueue) this.pipelineQueue.push(part.slice());
      else this.pipeline?.feed(part);
    }
    this.recordStatus.textContent = `Đang ghi ${Math.floor(this.sampleCount / SAMPLE_RATE)} giây / ${this.opts.maxSeconds} giây.`;
        if (this.sampleCount >= this.opts.maxSeconds * SAMPLE_RATE) this.stopRecording();
  }

  async toggleMic() {
    if (this.busy) return;
    if (this.recording) { this.stopRecording(); return; }
    if (this.micStarting) return;
    if (!this.mic) {
      this.showError('Micro chưa sẵn sàng. Hãy cấp quyền truy cập micro cho trang này.');
      return;
    }
    this.showError(''); this.samples = []; this.sampleCount = 0; this.requestSignature = null;
    this.pipeline?.cancel();
    this.pipeline = null; this.pipelineReady = null; this.pipelineFinish = null;
    this.pipelineQueue = []; this.recording = true; this.micStarting = true;
    this.process.disabled = true;
    this.record.textContent = 'Dừng ghi âm';
    this.recordStatus.textContent = 'Đang ghi 0 giây…';
    this.setMicState('recording', 'Micro đang hoạt động · ĐANG GHI');
    try {
      if (!await confirmDownload(MODEL)) throw new Error(S.consentDeclined);
      this.pipeline = new Pipeline({
        createWorker: name => new Worker(this.opts.distUrl + name + '.worker.js?v=' + this.opts.rev, {type: 'module'}),
        distUrl: this.opts.distUrl, models: {webgpu: MODEL, wasm: MODEL}, wantWebgpu: !!navigator.gpu,
        onEvent: event => {
          if (event.type === 'download') this.progress.setStep(S.stepDownload, event.total ? event.loaded / event.total : null);
          if (event.type === 'item') this.progress.setStep(S.stepTranscribe, event.done / Math.max(1, event.total));
        },
      });
      this.pipelineReady = this.pipeline.start().then(info => {
        onReadyEvent(info.model);
        this.pipelineQueue.splice(0).forEach(samples => this.pipeline.feed(samples));
        this.pipelineQueue = null;
        return info;
      });
      await this.pipelineReady;
    } catch (error) {
      if (this.recording) this.stopRecording(false);
      this.pipeline?.cancel(); this.pipeline = null;
      this.setMicState(this.mic ? 'recording' : 'error', this.mic ? 'Micro đang hoạt động' : 'Không truy cập được micro');
      this.showError(error.message);
    } finally {
      this.micStarting = false;
      this.record.disabled = false;
    }
  }

  stopRecording() {
    if (this.recording) this.recording = false;
    if (this.pipeline && !this.pipelineFinish) {
      const pipeline = this.pipeline, ready = this.pipelineReady;
      this.pipelineFinish = ready.then(() => pipeline.finish());
      this.pipelineFinish.catch(() => {});
    }
    if (this.record) this.record.textContent = this.sampleCount ? 'Ghi lại từ đầu' : 'Bắt đầu ghi âm';
    if (this.recordStatus) this.recordStatus.textContent = `Đã ghi ${Math.floor(this.sampleCount / SAMPLE_RATE)} giây. Micro vẫn đang hoạt động. Điền thông tin bên dưới rồi bấm "Sinh câu hỏi".`;
    if (this.mic) this.setMicState('recording', 'Micro đang hoạt động');
    if (this.process) this.process.disabled = !this.sampleCount;
  }

  showError(message) {
    mount(this.error, message ? el('div', {class: 'alert alert-danger'}, message) : []);
    if (message) this.error.focus();
  }

  lock(value) {
    const locked = value || !!this.quizDraft || !!this.created;
    this.form.querySelectorAll('input,select,textarea,button').forEach(input => { input.disabled = locked; });
    this.cancel.disabled = false;
    this.cancel.classList.toggle('d-none', !value);
  }

  async sourceFile(signal) {
    if (!this.source?.value) {
      const file = this.file.files[0];
      if (!file) throw new Error('Hãy chọn một file audio.');
      validateAudioFile(file, this.opts.maxFileBytes);
      return file;
    }
    const item = this.opts.sources.find(item => String(item.id) === this.source.value);
    const url = new URL(this.opts.sourceUrl);
    Object.entries({courseid: this.opts.courseid, fileid: item.id, sesskey: this.opts.sesskey})
      .forEach(([key, value]) => url.searchParams.set(key, value));
    this.progress.setStep('Đang đọc audio từ khóa học');
    const response = await fetch(url, {credentials: 'same-origin', signal, cache: 'no-store'});
    if (!response.ok || response.headers.get('content-type')?.includes('text/html')) {
      throw new Error('Không đọc được file nguồn. Hãy tải lại trang để kiểm tra quyền và danh sách file.');
    }
    const file = new File([await response.blob()], item.filename, {type: item.mimetype, lastModified: 0});
    validateAudioFile(file, this.opts.maxFileBytes);
    return file;
  }

  async prepare(file, duration, title, count, signal) {
    const signature = JSON.stringify([this.source?.value || '', file.name, file.size, file.lastModified, duration, title, count]);
    if (this.requestSignature !== signature) {
      this.requestSignature = signature;
      this.requestKey = [...crypto.getRandomValues(new Uint8Array(16))].map(byte => byte.toString(16).padStart(2, '0')).join('');
    }
    const data = new FormData();
    Object.entries({courseid: this.opts.courseid, section: this.opts.section, beforemod: this.opts.beforemod,
      kind: this.opts.kind, title, questioncount: count, durationsec: duration, requestkey: this.requestKey,
      sourcefileid: this.source?.value || 0, sesskey: this.opts.sesskey}).forEach(([key, value]) => data.append(key, value));
    if (!this.source?.value) data.append('source', file);
    this.progress.setStep('Đang kiểm tra nguồn audio với Moodle');
    const response = await fetch(this.opts.prepareUrl, {method: 'POST', body: data, credentials: 'same-origin', signal});
    let result;
    try { result = await response.json(); }
    catch { throw new Error('Moodle không nhận được file. Kiểm tra giới hạn upload của PHP hoặc đăng nhập lại.'); }
    if (!response.ok || !result.requestid) {
      throw new Error(result.message || result.error?.message || (typeof result.error === 'string' ? result.error : '') || 'Không chuẩn bị được bài trắc nghiệm.');
    }
    return result;
  }

  async run() {
    if (this.busy || this.created || this.quizDraft || this.micStarting) return;
    if (this.opts.kind === 'record' && this.recording) {
      this.showError('Hãy bấm "Dừng ghi âm" trước khi sinh câu hỏi.');
      return;
    }
    this.showError('');
    const title = this.title.value.trim(), count = Number(this.count.value), apiKey = this.apiKey.value.trim();
    if (!title) return this.showError('Hãy nhập tên bài trắc nghiệm.');
    if (!Number.isInteger(count) || count < 1 || count > 30) return this.showError('Số câu hỏi phải từ 1 đến 30.');
    if (!apiKey) return this.showError(S.errNoKey);
    this.key.set(apiKey, this.remember.checked);
    this.busy = true; this.lock(true); this.stopRecording(); clear(this.result);
    this.controller = new AbortController();
    const signal = this.controller.signal;
    try {
      let samples, file, duration;
      if (this.opts.kind === 'audio') {
        file = await this.sourceFile(signal);
        this.progress.setStep('Đang giải mã audio');
        samples = await decodeFile(file, this.opts.maxSeconds);
        duration = Math.max(1, Math.ceil(samples.length / SAMPLE_RATE));
      } else {
        if (!this.sampleCount) throw new Error('Hãy ghi âm nội dung trước khi tạo trắc nghiệm.');
        duration = Math.max(1, Math.ceil(this.sampleCount / SAMPLE_RATE));
        samples = null;
      }
      signal.throwIfAborted();
      if (this.opts.kind === 'audio' && samples.length > this.opts.maxSeconds * SAMPLE_RATE) throw new Error('Audio vượt giới hạn 30 phút.');
      if (this.opts.kind === 'audio') {
        if (!await confirmDownload(MODEL)) throw new Error(S.consentDeclined);
        this.pipeline = new Pipeline({
          createWorker: name => new Worker(this.opts.distUrl + name + '.worker.js?v=' + this.opts.rev, {type: 'module'}),
          distUrl: this.opts.distUrl, models: {webgpu: MODEL, wasm: MODEL}, wantWebgpu: !!navigator.gpu,
          onEvent: event => {
            if (event.type === 'download') this.progress.setStep(S.stepDownload, event.total ? event.loaded / event.total : null);
            if (event.type === 'item') this.progress.setStep(S.stepTranscribe, event.done / Math.max(1, event.total));
          },
        });
        this.pipelineReady = this.pipeline.start().then(info => { onReadyEvent(info.model); return info; });
        await this.pipelineReady;
        for (let offset = 0; offset < samples.length; offset += SAMPLE_RATE * 4) {
          signal.throwIfAborted();
          this.pipeline.feed(samples.subarray(offset, offset + SAMPLE_RATE * 4));
        }
        this.pipelineFinish = this.pipeline.finish();
      }
      if (!this.pipelineFinish) throw new Error('Không tìm thấy pipeline phiên âm của bản ghi. Hãy ghi âm lại.');
      const abort = () => this.pipeline?.cancel();
      signal.addEventListener('abort', abort, {once: true});
      let items;
      try { items = await this.pipelineFinish; }
      finally { signal.removeEventListener('abort', abort); }
      signal.throwIfAborted();
      if (!items.length) throw new Error(S.errNoSpeech);
      
      this.parts = planQuizParts(groupParts(items), count);
      this.transcript = fullTranscript(items);
      this.questionCount = count;
      this.pendingQuiz = {file, duration, title, count};
      this.progress.setStep(S.stepGemini);
      const generated = await generateQuiz({apiKey, model: DEFAULT_GEMINI_MODEL,
        parts: this.parts, questionCount: this.questionCount, feedback: this.note.value.trim(), signal,
        onRetry: attempt => this.progress.setStep(`Đang tạo câu hỏi (thử lại lần ${attempt})`)}); 
      
      signal.throwIfAborted();
      this.quizDraft = generated.content;
      this.renderDraft();
    } catch (error) {
      this.showError(error.name === 'AbortError' ? 'Đã hủy xử lý. Chưa tạo bài trắc nghiệm.' : error.message || String(error));
    } finally {
      this.pipeline?.terminate(); this.pipeline = null;
      this.busy = false; this.lock(false); this.process.disabled = !!this.created || !!this.quizDraft;
      this.progress.setStep('', null);
    }
  }

  renderDraft() {
    this.process.classList.add('d-none');
    this.cancel.classList.add('d-none');
    this.note.disabled = false;
    const draft = this.quizDraft;
    const summary = card('Tóm tắt (bản nháp, chưa lưu vào Moodle)', [
      el('p', {}, draft.summary.short_summary),
      el('ul', {}, draft.summary.main_points.map(p => el('li', {}, p))),
    ]);
    const segments = draft.segments.map(seg => card(`Phần ${seg.segment_index} (${seg.start_time}–${seg.end_time})`, [
      el('p', {}, seg.segment_summary),
      el('ol', {class: 'ps-3'}, seg.quiz.map(q => el('li', {class: 'mb-3'}, [
        el('p', {class: 'fw-semibold mb-1'}, q.question),
        el('ul', {class: 'list-unstyled mb-1'}, q.options.map(opt => el('li', {
          class: opt === q.correct_answer ? 'text-success fw-semibold' : '',
        }, (opt === q.correct_answer ? '✓ ' : '') + opt))),
        el('p', {class: 'text-muted small mb-0'}, 'Giải thích: ' + q.explanation),
      ]))),
    ]));

    this.saveBtn2 = button(S.saveBtn, () => this.saveToMoodle());
    this.retryBtn2 = button(S.retryBtn, () => this.generateFromParts(null, ''), 'outline-secondary');
    this.regenBtn2 = button(S.regenBtn, () => this.generateFromParts(draft, this.note.value.trim()), 'outline-primary');
    this.startOverBtn = button('Làm lại từ đầu', () => this.resetAll(), 'outline-danger');
    const actions = el('div', {class: 'd-flex flex-wrap gap-2 mb-3'}, [this.saveBtn2, this.retryBtn2, this.regenBtn2, this.startOverBtn]);

    mount(this.result, [summary, ...segments, actions]);
    this.result.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  }

  setDraftBusy(value, activeBtn, busyLabel) {
    [this.saveBtn2, this.retryBtn2, this.regenBtn2, this.startOverBtn].forEach(b => { b.disabled = value; });
    if (value && activeBtn) activeBtn.textContent = busyLabel;
  }

  async generateFromParts(previous, feedback) {
    if (this.busy) return;
    this.busy = true; this.showError('');
    const activeBtn = previous ? this.regenBtn2 : this.retryBtn2;
    const busyLabel = previous ? S.regeneratingBtn : S.retryingBtn;
    const normalLabel = previous ? S.regenBtn : S.retryBtn;
    this.setDraftBusy(true, activeBtn, busyLabel);
    this.controller = new AbortController();
    const signal = this.controller.signal;
    try {
      this.progress.setStep(S.stepGemini);
      const generated = await generateQuiz({apiKey: this.apiKey.value.trim(), model: DEFAULT_GEMINI_MODEL,
        parts: this.parts, questionCount: this.questionCount, previous, feedback, signal,
        onRetry: attempt => this.progress.setStep(`Đang tạo câu hỏi (thử lại lần ${attempt})`)});
      signal.throwIfAborted();
      this.quizDraft = generated.content;
      this.renderDraft();
    } catch (error) {
      this.showError(error.name === 'AbortError' ? 'Đã hủy xử lý.' : error.message || String(error));
      this.setDraftBusy(false);
      activeBtn.textContent = normalLabel;
    } finally {
      this.busy = false; this.progress.setStep('', null);
    }
  }

  async saveToMoodle() {
    if (this.busy) return;
    this.busy = true; this.showError('');
    this.setDraftBusy(true, this.saveBtn2, S.savingBtn);
    this.generated = {transcript: this.transcript, content: JSON.stringify(this.quizDraft)};
    try {
      if (!this.requestid) {
        const {duration, title, count} = this.pendingQuiz;
        let {file} = this.pendingQuiz;
        if (!file) {
          const samples = new Float32Array(this.sampleCount);
          let offset = 0; this.samples.forEach(part => { samples.set(part, offset); offset += part.length; });
          file = new File([wavBlob(samples)], 'recording.wav', {type: 'audio/wav', lastModified: 0});
          validateAudioFile(file, this.opts.maxFileBytes);
        }
        const prepared = await this.prepare(file, duration, title, count, new AbortController().signal);
        if (prepared.quiz) { this.showCreated(prepared.quiz); return; }
        this.requestid = prepared.requestid;
      }
      await this.save();
    } catch (error) {
      this.showError(error.message);
      this.setDraftBusy(false);
      this.saveBtn2.textContent = S.saveBtn;
    } finally {
      this.busy = false;
    }
  }

  async save() {
    this.progress.setStep('Đang tạo bài trắc nghiệm và lưu câu hỏi vào Moodle');
    try {
      const result = await callAjax('local_microlearning_create_quiz', {requestid: this.requestid, ...this.generated});
      this.showCreated(result);
    } catch (error) {
      mount(this.result, el('div', {class: 'alert alert-warning'}, [
        el('p', {}, 'Đã tạo xong câu hỏi AI nhưng chưa xác nhận được kết quả lưu. Thử lưu lại sẽ dùng cùng yêu cầu để tránh tạo bài trùng.'),
        button('Thử lưu bài trắc nghiệm', async () => {
          if (this.busy) return;
          this.busy = true; this.showError('');
          try { await this.save(); } catch (error) { this.showError(error.message); }
          finally { this.busy = false; }
        }, 'warning'),
      ]));
      throw error;
    }
  }

  resetAll() {
    this.quizDraft = null; this.parts = null; this.transcript = null; this.created = null;
    this.generated = null; this.requestid = null; this.requestSignature = null;
    this.pendingQuiz = null;
    this.samples = []; this.sampleCount = 0; this.busy = false;
    this.pipeline = null; this.pipelineReady = null; this.pipelineFinish = null; this.pipelineQueue = null;
    clear(this.root);
    this.render();
  }

  showCreated(result) {
    this.created = result; this.showError('');
    mount(this.result, el('div', {class: 'alert alert-success'}, [
      el('h3', {class: 'h5'}, `Đã tạo bài trắc nghiệm với ${result.questioncount} câu hỏi`),
      el('p', {}, 'Bài đang ẩn với sinh viên. Bạn có thể xem lại câu hỏi và vào cài đặt để bật hiển thị.'),
      el('div', {class: 'd-flex flex-wrap gap-2'}, [
        el('a', {href: result.viewurl, class: 'btn btn-success'}, 'Xem bài trắc nghiệm'),
        el('a', {href: result.editurl, class: 'btn btn-outline-success'}, 'Xem / sửa câu hỏi'),
        el('a', {href: result.settingsurl, class: 'btn btn-outline-success'}, 'Cài đặt và hiển thị'),
        el('a', {href: result.courseurl, class: 'btn btn-link'}, 'Về khóa học'),
        button('Tạo bài trắc nghiệm khác', () => this.resetAll(), 'outline-secondary'),
      ]),
    ]));
    this.result.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  }
}

export function init(root, opts) { return new CourseQuiz(root, opts); }


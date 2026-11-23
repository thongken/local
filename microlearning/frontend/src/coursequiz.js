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
    this.samples = []; this.sampleCount = 0; this.busy = false; this.created = null;
    this.onUnload = event => {
      if (this.busy || this.mic || this.micStarting) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', this.onUnload);
    window.addEventListener('pagehide', () => { this.stopMic(); this.pipeline?.cancel(); });
    this.render();
  }

  render() {
    this.title = el('input', {id: 'ml-quiz-title', class: 'form-control', type: 'text', maxlength: 255,
      placeholder: 'Ví dụ: Trắc nghiệm — Bài 1'});
    this.count = el('input', {id: 'ml-quiz-count', class: 'form-control', type: 'number', min: 1, max: 30, value: 10});
    this.apiKey = el('input', {id: 'ml-quiz-key', class: 'form-control', type: 'password', autocomplete: 'off', value: this.key.get()});
    this.remember = el('input', {id: 'ml-quiz-remember', type: 'checkbox', class: 'form-check-input', checked: this.key.isRemembered()});
    this.model = el('input', {id: 'ml-quiz-model', class: 'form-control', type: 'text', value: DEFAULT_GEMINI_MODEL});
    const persist = () => this.key.set(this.apiKey.value, this.remember.checked);
    this.apiKey.addEventListener('input', persist); this.remember.addEventListener('change', persist);
    this.progressHost = el('div', {class: 'mb-3', role: 'status', 'aria-live': 'polite'});
    this.progress = new ProgressView(this.progressHost);
    this.error = el('div', {role: 'alert', tabindex: '-1'});
    this.result = el('div', {'aria-live': 'polite'});
    this.process = button('Tạo trắc nghiệm', () => this.run());
    this.cancel = button('Hủy xử lý', () => this.controller?.abort(), 'outline-secondary');
    this.cancel.classList.add('d-none');
    this.form = el('form', {}, [
      card('1. Thiết lập bài trắc nghiệm', [field('Tên bài trắc nghiệm', this.title), field('Số câu hỏi (1–30)', this.count)]),
      this.opts.kind === 'audio' ? this.audioCard() : this.recordCard(),
      card('3. Kết nối AI', [field(S.apiKeyLabel, this.apiKey), el('p', {class: 'form-text'}, S.apiKeyHelp),
        el('div', {class: 'form-check mb-2'}, [this.remember,
          el('label', {class: 'form-check-label', for: this.remember.id}, S.rememberKey)]),
        el('details', {}, [el('summary', {}, 'Nâng cao'), field('Model Gemini', this.model)])]),
      el('p', {class: 'text-muted'}, 'Quiz được tạo tự động và ẩn với sinh viên. Giữ trang mở đến khi hoàn tất.'),
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
    const fileField = field('File audio', this.file);
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
    return card('2. Chọn nguồn tài liệu', [field('Nguồn tạo câu hỏi', this.source), fileField,
      el('p', {class: 'form-text'}, `Hỗ trợ MP3, WAV, M4A, OGG; tối đa ${Math.floor(this.opts.maxFileBytes / 1024 / 1024)} MB và 30 phút. PDF/MP4 chưa được hỗ trợ.`),
      el('p', {class: 'form-text'}, 'Có thể chọn bất kỳ audio hợp lệ trong các tài nguyên File của khóa học, kể cả tệp đính kèm không phải file chính.'),
    ]);
  }

  recordCard() {
    this.record = button('Bắt đầu ghi âm', () => this.toggleMic(), 'danger');
    this.recordStatus = el('p', {class: 'form-text', role: 'status'}, 'Chưa có bản ghi. Ghi âm tự dừng ở phút 30.');
    return card('2. Ghi âm nội dung bài học', [this.record, this.recordStatus]);
  }

  async toggleMic() {
    if (this.busy || this.micStarting) return;
    if (this.mic) { this.stopMic(); return; }
    this.showError(''); this.samples = []; this.sampleCount = 0; this.requestSignature = null;
    this.micStarting = true; this.record.disabled = true;
    try {
      this.mic = await startMic({distUrl: this.opts.distUrl, rev: this.opts.rev, onSamples: samples => {
        const room = this.opts.maxSeconds * SAMPLE_RATE - this.sampleCount;
        const part = samples.subarray(0, Math.max(0, room));
        if (part.length) { this.samples.push(part); this.sampleCount += part.length; }
        this.recordStatus.textContent = `Đã ghi ${Math.floor(this.sampleCount / SAMPLE_RATE)} giây / ${this.opts.maxSeconds} giây.`;
        if (this.sampleCount >= this.opts.maxSeconds * SAMPLE_RATE) this.stopMic();
      }});
      this.record.textContent = 'Dừng ghi âm';
    } catch (error) { this.showError(error.message); }
    finally { this.micStarting = false; this.record.disabled = false; }
  }

  stopMic() {
    this.mic?.stop(); this.mic = null;
    if (this.record) this.record.textContent = this.sampleCount ? 'Ghi lại từ đầu' : 'Bắt đầu ghi âm';
  }

  showError(message) {
    mount(this.error, message ? el('div', {class: 'alert alert-danger'}, message) : []);
    if (message) this.error.focus();
  }

  lock(value) {
    const locked = value || !!this.generated || !!this.created;
    this.form.querySelectorAll('input,select,button').forEach(input => { input.disabled = locked; });
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
    if (this.busy || this.created || this.generated || this.micStarting) return;
    this.showError('');
    const title = this.title.value.trim(), count = Number(this.count.value), apiKey = this.apiKey.value.trim();
    if (!title) return this.showError('Hãy nhập tên bài trắc nghiệm.');
    if (!Number.isInteger(count) || count < 1 || count > 30) return this.showError('Số câu hỏi phải từ 1 đến 30.');
    if (!apiKey) return this.showError(S.errNoKey);
    this.key.set(apiKey, this.remember.checked);
    this.busy = true; this.lock(true); this.stopMic(); clear(this.result);
    this.controller = new AbortController();
    const signal = this.controller.signal;
    try {
      let samples, file;
      if (this.opts.kind === 'audio') {
        file = await this.sourceFile(signal);
        this.progress.setStep('Đang giải mã audio');
        samples = await decodeFile(file, this.opts.maxSeconds);
      } else {
        if (!this.sampleCount) throw new Error('Hãy ghi âm nội dung trước khi tạo trắc nghiệm.');
        samples = new Float32Array(this.sampleCount);
        let offset = 0; this.samples.forEach(part => { samples.set(part, offset); offset += part.length; });
        file = new File([wavBlob(samples)], 'recording.wav', {type: 'audio/wav', lastModified: 0});
        validateAudioFile(file, this.opts.maxFileBytes);
      }
      signal.throwIfAborted();
      if (samples.length > this.opts.maxSeconds * SAMPLE_RATE) throw new Error('Audio vượt giới hạn 30 phút.');
      const duration = Math.max(1, Math.ceil(samples.length / SAMPLE_RATE));
      const prepared = await this.prepare(file, duration, title, count, signal);
      if (prepared.quiz) { this.showCreated(prepared.quiz); return; }
      this.requestid = prepared.requestid;
      if (!await confirmDownload(MODEL)) throw new Error(S.consentDeclined);
      signal.throwIfAborted();
      this.pipeline = new Pipeline({
        createWorker: name => new Worker(this.opts.distUrl + name + '.worker.js?v=' + this.opts.rev, {type: 'module'}),
        distUrl: this.opts.distUrl, models: {webgpu: MODEL, wasm: MODEL}, wantWebgpu: !!navigator.gpu,
        onEvent: event => {
          if (event.type === 'download') this.progress.setStep(S.stepDownload, event.total ? event.loaded / event.total : null);
          if (event.type === 'item') this.progress.setStep(S.stepTranscribe, event.done / Math.max(1, event.total));
        },
      });
      const abort = () => this.pipeline?.cancel();
      signal.addEventListener('abort', abort, {once: true});
      this.progress.setStep(S.stepDownload);
      const info = await this.pipeline.start(); onReadyEvent(info.model);
      signal.throwIfAborted();
      this.progress.setStep(S.stepTranscribe);
      for (let offset = 0; offset < samples.length; offset += SAMPLE_RATE * 4) {
        signal.throwIfAborted();
        this.pipeline.feed(samples.subarray(offset, offset + SAMPLE_RATE * 4));
        await new Promise(resolve => setTimeout(resolve, 0));
      }
      const items = await this.pipeline.finish();
      signal.removeEventListener('abort', abort);
      if (!items.length) throw new Error(S.errNoSpeech);
      const parts = planQuizParts(groupParts(items), count);
      this.progress.setStep(S.stepGemini);
      const generated = await generateQuiz({apiKey, model: this.model.value.trim() || DEFAULT_GEMINI_MODEL,
        parts, questionCount: count, signal,
        onRetry: attempt => this.progress.setStep(`Đang tạo câu hỏi (thử lại lần ${attempt})`)});
      signal.throwIfAborted();
      this.generated = {transcript: fullTranscript(items), content: JSON.stringify(generated.content)};
      await this.save();
    } catch (error) {
      this.showError(error.name === 'AbortError' ? 'Đã hủy xử lý. Chưa tạo bài trắc nghiệm.' : error.message || String(error));
    } finally {
      this.pipeline?.terminate(); this.pipeline = null;
      this.busy = false; this.lock(false); this.process.disabled = !!this.created || !!this.generated;
      this.progress.setStep('', null);
    }
  }

  async save() {
    this.cancel.classList.add('d-none');
    this.progress.setStep('Đang tạo bài trắc nghiệm và lưu câu hỏi vào Moodle');
    try {
      const result = await callAjax('local_microlearning_create_quiz', {requestid: this.requestid, ...this.generated});
      this.showCreated(result);
    } catch (error) {
      mount(this.result, el('div', {class: 'alert alert-warning'}, [
        el('p', {}, 'Đã tạo xong câu hỏi AI nhưng chưa xác nhận được kết quả lưu. Thử lưu lại sẽ dùng cùng yêu cầu để tránh tạo bài trùng.'),
        button('Thử lưu bài trắc nghiệm', async () => {
          if (this.busy) return;
          this.busy = true; this.lock(true); this.showError('');
          try { await this.save(); } catch (error) { this.showError(error.message); }
          finally { this.busy = false; this.lock(false); this.process.disabled = true; this.progress.setStep('', null); }
        }, 'warning'),
      ]));
      throw error;
    }
  }

  showCreated(result) {
    this.created = result; this.process.disabled = true; this.showError('');
    mount(this.result, el('div', {class: 'alert alert-success'}, [
      el('h3', {class: 'h5'}, `Đã tạo bài trắc nghiệm với ${result.questioncount} câu hỏi`),
      el('p', {}, 'Bài đang ẩn với sinh viên. Bạn có thể xem lại câu hỏi và vào cài đặt để bật hiển thị.'),
      el('div', {class: 'd-flex flex-wrap gap-2'}, [
        el('a', {href: result.viewurl, class: 'btn btn-success'}, 'Xem bài trắc nghiệm'),
        el('a', {href: result.editurl, class: 'btn btn-outline-success'}, 'Xem / sửa câu hỏi'),
        el('a', {href: result.settingsurl, class: 'btn btn-outline-success'}, 'Cài đặt và hiển thị'),
        el('a', {href: result.courseurl, class: 'btn btn-link'}, 'Về khóa học'),
      ]),
    ]));
    this.result.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  }
}

export function init(root, opts) { return new CourseQuiz(root, opts); }

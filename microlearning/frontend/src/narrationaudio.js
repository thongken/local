import {decodeFile, UserError} from './audio.js';
import {MAX_AUDIO_SEC, SAMPLE_RATE} from './config.js';
import {confirmDownload, onReadyEvent} from './modelmanager.js';
import {Pipeline} from './pipeline.js';

const MODEL = 'onnx-community/whisper-base';

export function init(root, opts) {
  const fileInput = root.querySelector('input[type="file"]');
  const transcribe = root.querySelector('[data-transcribe]');
  const cancel = root.querySelector('[data-cancel]');
  const status = root.querySelector('[role="status"]');
  const script = document.getElementById('id_script');
  const title = document.getElementById('id_title');
  const s = opts.strings;
  let active = null;

  cancel.addEventListener('click', () => active?.abort());
  transcribe.addEventListener('click', async () => {
    if (active) return;
    const file = fileInput.files[0];
    if (!file) {
      status.textContent = s.choosefile;
      fileInput.focus();
      return;
    }
    if (!opts.formats.includes(file.name.split('.').pop().toLowerCase())) {
      status.textContent = s.format;
      return;
    }
    if (!file.size || file.size > opts.maxbytes) {
      status.textContent = s.size;
      return;
    }

    active = new AbortController();
    const {signal} = active;
    let pipeline;
    fileInput.disabled = true;
    transcribe.disabled = true;
    cancel.hidden = false;
    try {
      status.textContent = s.decoding;
      const samples = await decodeFile(file, MAX_AUDIO_SEC, opts.maxbytes);
      signal.throwIfAborted();
      if (samples.length > MAX_AUDIO_SEC * SAMPLE_RATE) throw new UserError(s.duration);
      if (!await confirmDownload(MODEL)) {
        status.textContent = s.downloaddeclined;
        return;
      }
      const wantWebgpu = Boolean(await navigator.gpu?.requestAdapter().catch(() => null));
      signal.throwIfAborted();
      signal.addEventListener('abort', () => pipeline?.cancel(), {once: true});
      const recognise = async useWebgpu => {
        pipeline = new Pipeline({
          createWorker: name => new Worker(opts.distUrl + name + '.worker.js?v=' + opts.rev, {type: 'module'}),
          distUrl: opts.distUrl,
          models: {webgpu: MODEL, wasm: MODEL},
          wantWebgpu: useWebgpu,
          onEvent: event => {
            if (event.type === 'download' && event.total) {
              status.textContent = s.loading + ' ' + Math.round(100 * event.loaded / event.total) + '%';
            } else if (event.type === 'item') {
              const done = Math.min(100, Math.round(100 * event.item.end * SAMPLE_RATE / samples.length));
              status.textContent = s.transcribing + ' ' + done + '%';
            }
          },
        });
        status.textContent = s.loading;
        const info = await pipeline.start();
        onReadyEvent(info.model);
        signal.throwIfAborted();
        status.textContent = s.transcribing;
        for (let offset = 0; offset < samples.length; offset += 4 * SAMPLE_RATE) {
          signal.throwIfAborted();
          pipeline.feed(samples.subarray(offset, offset + 4 * SAMPLE_RATE));
          await new Promise(resolve => setTimeout(resolve, 0));
        }
        return pipeline.finish();
      };
      let items;
      try {
        items = await recognise(wantWebgpu);
      } catch (error) {
        if (!wantWebgpu || signal.aborted) throw error;
        // A failed WebGPU session can leave the worker's ONNX runtime unusable.
        pipeline.terminate();
        items = await recognise(false);
      }
      signal.throwIfAborted();
      const transcript = items.map(item => item.text.trim()).filter(Boolean).join('\n\n');
      if (!transcript) throw new UserError(s.nospeech);
      if (script.value.trim() && script.value !== transcript && !window.confirm(s.replace)) {
        status.textContent = s.cancelled;
        return;
      }
      if (!title.value.trim()) {
        title.value = file.name.replace(/\.[^.]+$/, '').slice(0, title.maxLength);
      }
      script.value = transcript;
      script.dispatchEvent(new Event('input', {bubbles: true}));
      status.textContent = s.ready;
      script.focus();
    } catch (error) {
      status.textContent = signal.aborted ? s.cancelled
        : error instanceof UserError ? error.message
        : s.failed;
    } finally {
      pipeline?.terminate();
      active = null;
      fileInput.disabled = false;
      transcribe.disabled = false;
      cancel.hidden = true;
    }
  });
}

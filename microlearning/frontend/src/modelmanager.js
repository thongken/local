import { el, mount } from './dom.js';
import { S } from './strings.js';

export const MODEL_SIZES_MB = { 'onnx-community/whisper-base': 80, 'onnx-community/whisper-small': 275 };
const READY_KEY = (model) => `ml_model_ready_${model}`;

export function isModelReady(model) {
  try { return localStorage.getItem(READY_KEY(model)) === '1'; } catch { return false; }
}
function markReady(model) { try { localStorage.setItem(READY_KEY(model), '1'); } catch { /* ignore */ } }

/** Hiện popup xin phép tải model nếu chưa từng tải; trả về true nếu được phép tiếp tục. */
export function confirmDownload(model) {
  if (isModelReady(model)) return Promise.resolve(true);
  return new Promise((resolve) => {
    const mb = MODEL_SIZES_MB[model] || 150;
    const overlay = el('div', { class: 'ml-overlay' });
    const close = (ok) => { overlay.remove(); resolve(ok); };
    mount(overlay, el('div', { class: 'ml-modal', role: 'dialog', 'aria-modal': 'true' }, [
      el('h3', {}, S.consentTitle),
      el('p', {}, S.consentBody(mb)),
      el('div', { class: 'ml-modal-actions' }, [
        el('button', { class: 'btn btn-secondary', onclick: () => close(false) }, S.consentDecline),
        el('button', { class: 'btn btn-primary', onclick: () => close(true) }, S.consentAgree),
      ]),
    ]));
    document.body.appendChild(overlay);
  });
}

/** Gắn cờ "đã sẵn sàng" khi worker báo tải xong lần đầu. */
export function onReadyEvent(model) { markReady(model); }
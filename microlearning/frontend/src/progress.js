import { el, mount, clear } from './dom.js';

/** Thanh tiến trình đơn giản gắn vào một node. */
export class ProgressView {
  constructor(node) {
    this.node = node;
    this.label = el('div', { class: 'ml-step-label' });
    this.bar = el('div', { class: 'ml-progress-bar' });
    this.fill = el('div', { class: 'ml-progress-fill' });
    mount(this.bar, this.fill);
    mount(this.node, [this.label, this.bar]);
  }
  setStep(text, ratio = null) {
    this.label.textContent = text;
    this.fill.style.width = (ratio == null ? 0 : Math.round(ratio * 100)) + '%';
    this.bar.style.visibility = ratio == null ? 'hidden' : 'visible';
  }
  clear() { clear(this.node); }
}
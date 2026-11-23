const KEY = 'ml_gemini_key';

/** Khóa API chỉ ở trong trình duyệt. Mặc định chỉ lưu trong bộ nhớ; "ghi nhớ" mới ghi localStorage. */
export class KeyStore {
  constructor(storage = null) {
    this.mem = '';
    try { this.storage = storage || (typeof localStorage !== 'undefined' ? localStorage : null); }
    catch { this.storage = null; }
  }
  _read() { try { return this.storage ? this.storage.getItem(KEY) || '' : ''; } catch { return ''; } }
  get() { return this.mem || this._read(); }
  isRemembered() { return this._read() !== ''; }
  set(key, remember = false) {
    this.mem = String(key || '').trim();
    try {
      if (!this.storage) return;
      if (remember && this.mem) this.storage.setItem(KEY, this.mem); else this.storage.removeItem(KEY);
    } catch { /* bỏ qua: chế độ riêng tư hoặc bị chặn */ }
  }
  clear() { this.set('', false); }
}
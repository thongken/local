// Chạy trong AudioWorklet: gom mẫu mic thành khối 4096 rồi gửi về luồng chính.
class MicCapture extends AudioWorkletProcessor {
  constructor() { super(); this.buf = new Float32Array(4096); this.n = 0; }
  process(inputs) {
    const ch = inputs[0] && inputs[0][0];
    if (ch) {
      this.buf.set(ch, this.n);
      this.n += ch.length;
      if (this.n >= this.buf.length) {
        this.port.postMessage(this.buf, [this.buf.buffer]);
        this.buf = new Float32Array(4096);
        this.n = 0;
      }
    }
    return true;
  }
}
registerProcessor('mic-capture', MicCapture);
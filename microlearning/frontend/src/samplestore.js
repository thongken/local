/** Lưu mẫu âm thanh theo khối, cắt đoạn theo chỉ số tuyệt đối và giải phóng phần đã xử lý. */
export class SampleStore {
  constructor() { this.blocks = []; this.total = 0; }
  append(data) {
    if (!data || !data.length) return;
    this.blocks.push({ start: this.total, data });
    this.total += data.length;
  }
  slice(start, end) {
    end = Math.min(end, this.total);
    const out = new Float32Array(Math.max(0, end - start));
    for (const b of this.blocks) {
      const bs = b.start, be = bs + b.data.length;
      if (be <= start) continue;
      if (bs >= end) break;
      const from = Math.max(start, bs), to = Math.min(end, be);
      out.set(b.data.subarray(from - bs, to - bs), from - start);
    }
    return out;
  }
  release(before) {
    while (this.blocks.length && this.blocks[0].start + this.blocks[0].data.length <= before) this.blocks.shift();
  }
}
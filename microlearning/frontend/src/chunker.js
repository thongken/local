import { SAMPLE_RATE, FRAME, CHUNK } from './config.js';

export class Chunker {
  constructor(opts = {}) {
    this.o = { ...CHUNK, ...opts };
    this.frame = 0; this.chunkStart = 0;
    this.speaking = false; this.silentRun = 0; this.speechFrames = 0;
    this.out = [];
  }
  get fps() { return SAMPLE_RATE / FRAME; }
  push(prob) {
    const o = this.o;
    if (this.speaking) { if (prob < o.offThreshold) this.speaking = false; }
    else if (prob >= o.onThreshold) this.speaking = true;
    if (this.speaking) { this.silentRun = 0; this.speechFrames++; } else this.silentRun++;
    this.frame++;
    const len = (this.frame - this.chunkStart) / this.fps;
    const pauseFrames = Math.ceil(o.pauseSec * this.fps);
    if (len >= o.hardSec || (len >= o.minSec && this.silentRun >= pauseFrames)) this._cut(this.frame);
  }
  _cut(endFrame) {
    if (this.speechFrames > 0 && endFrame > this.chunkStart) {
      this.out.push({ start: this.chunkStart * FRAME, end: endFrame * FRAME });
    }
    this.chunkStart = endFrame; this.speechFrames = 0; this.silentRun = 0;
  }
  drain() { const r = this.out; this.out = []; return r; }
  finish(totalSamples) {
    const endFrame = Math.max(this.frame, Math.ceil(totalSamples / FRAME));
    this._cut(endFrame);
    const last = this.out[this.out.length - 1];
    if (last && last.end > totalSamples) last.end = totalSamples;
    return this.drain();
  }
}
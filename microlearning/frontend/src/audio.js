import { SAMPLE_RATE, MAX_AUDIO_SEC } from './config.js';

export const MAX_FILE_BYTES = 350 * 1024 * 1024;

export class UserError extends Error {}

export const AUDIO_TYPES = {
  mp3: ['audio/mpeg', 'audio/mp3', 'audio/x-mp3'],
  wav: ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
  m4a: ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'application/mp4'],
  ogg: ['audio/ogg', 'application/ogg', 'audio/vorbis'],
};

export function validateAudioFile(file, maxBytes = MAX_FILE_BYTES) {
  const extension = String(file.name || '').split('.').pop().toLowerCase();
  const mime = String(file.type || '').toLowerCase().split(';')[0].trim();
  if (!Object.hasOwn(AUDIO_TYPES, extension)) {
    throw new UserError('Hiện chỉ hỗ trợ audio MP3, WAV, M4A, OGG. PDF và MP4 chưa được hỗ trợ tạo trắc nghiệm.');
  }
  // An empty/generic browser MIME is inconclusive; decoding and PHP inspect the actual bytes next.
  if (mime && mime !== 'application/octet-stream' && !AUDIO_TYPES[extension].includes(mime)) {
    throw new UserError('Loại nội dung không khớp định dạng audio. Hãy kiểm tra lại file.');
  }
  if (!file.size || file.size > maxBytes) {
    throw new UserError(`File phải có dữ liệu và không vượt quá ${Math.floor(maxBytes / 1024 / 1024)} MB.`);
  }
}

/** WAV PCM16, used only to validate a microphone recording with Moodle before creating its Quiz. */
export function wavBlob(samples) {
  const data = new ArrayBuffer(44 + samples.length * 2), view = new DataView(data);
  const label = (offset, value) => [...value].forEach((char, index) => view.setUint8(offset + index, char.charCodeAt(0)));
  label(0, 'RIFF'); view.setUint32(4, 36 + samples.length * 2, true); label(8, 'WAVE'); label(12, 'fmt ');
  view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
  view.setUint32(24, SAMPLE_RATE, true); view.setUint32(28, SAMPLE_RATE * 2, true);
  view.setUint16(32, 2, true); view.setUint16(34, 16, true); label(36, 'data');
  view.setUint32(40, samples.length * 2, true);
  samples.forEach((sample, index) => {
    const value = Math.max(-1, Math.min(1, sample));
    view.setInt16(44 + index * 2, Math.round(value * (value < 0 ? 32768 : 32767)), true);
  });
  return new Blob([data], { type: 'audio/wav' });
}

/** Giải mã file audio thành Float32 mono 16 kHz. */
export async function decodeFile(file, maxSec = MAX_AUDIO_SEC, maxBytes = MAX_FILE_BYTES) {
  if (file.size > maxBytes) throw new UserError(`File quá lớn (tối đa ${Math.floor(maxBytes / 1024 / 1024)} MB).`);
  const ctx = new AudioContext({ sampleRate: SAMPLE_RATE });
  try {
    let buf;
    try { buf = await ctx.decodeAudioData(await file.arrayBuffer()); }
    catch { throw new UserError('Không đọc được file audio này. Hãy chọn một bản ghi có thể phát được.'); }
    if (buf.duration > maxSec + 1) {
      throw new UserError(`Audio dài ${Math.ceil(buf.duration / 60)} phút, vượt giới hạn ${Math.floor(maxSec / 60)} phút.`);
    }
    const n = buf.length, chs = buf.numberOfChannels;
    const out = new Float32Array(n);
    for (let c = 0; c < chs; c++) {
      const d = buf.getChannelData(c);
      for (let i = 0; i < n; i++) out[i] += d[i] / chs;
    }
    return out;
  } finally {
    ctx.close().catch(() => {});
  }
}

/** Bật mic; onSamples nhận Float32Array 16 kHz. Trả về { stop } để dừng. */
export async function startMic({ distUrl, rev = '', onSamples }) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    throw new UserError('Trình duyệt không hỗ trợ ghi âm (cần HTTPS hoặc localhost).');
  }
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true },
    });
  } catch {
    throw new UserError('Không truy cập được micro. Hãy cho phép quyền micro cho trang này.');
  }
  const ctx = new AudioContext({ sampleRate: SAMPLE_RATE });
  try {
    await ctx.audioWorklet.addModule(distUrl + 'mic-worklet.js' + (rev ? '?v=' + rev : ''));
  } catch (e) {
    stream.getTracks().forEach((t) => t.stop());
    ctx.close().catch(() => {});
    throw new Error('Không nạp được mic-worklet.js: ' + (e && e.message));
  }
  const src = ctx.createMediaStreamSource(stream);
  const node = new AudioWorkletNode(ctx, 'mic-capture');
  node.port.onmessage = (e) => onSamples(e.data);
  const mute = ctx.createGain();
  mute.gain.value = 0;
  src.connect(node);
  node.connect(mute);
  mute.connect(ctx.destination);
  let stopped = false;
  return {
    stop() {
      if (stopped) return;
      stopped = true;
      node.port.onmessage = null;
      stream.getTracks().forEach((t) => t.stop());
      src.disconnect();
      ctx.close().catch(() => {});
    },
  };
}

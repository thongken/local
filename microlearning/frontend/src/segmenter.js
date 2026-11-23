import { SEGMENT_TARGET_SEC } from './config.js';

export function fmtTime(sec) {
  sec = Math.max(0, Math.floor(sec));
  const p = (n) => String(n).padStart(2, '0');
  return `${p(Math.floor(sec / 3600))}:${p(Math.floor(sec % 3600 / 60))}:${p(sec % 60)}`;
}

export function groupParts(items, targetSec = SEGMENT_TARGET_SEC) {
  const clean = items.filter((i) => i.text && i.text.trim());
  if (!clean.length) return [];
  const total = clean[clean.length - 1].end - clean[0].start;
  const n = Math.max(1, Math.round(total / targetSec));
  const per = total / n;
  const base = clean[0].start;
  const parts = Array.from({ length: n }, () => ({ start: null, end: 0, lines: [] }));
  for (const it of clean) {
    const mid = (it.start + it.end) / 2 - base;
    const idx = Math.min(n - 1, Math.max(0, Math.floor(mid / per)));
    const p = parts[idx];
    if (p.start === null) p.start = it.start;
    p.end = it.end;
    p.lines.push(`[${fmtTime(it.start)} - ${fmtTime(it.end)}] ${it.text.trim()}`);
  }
  return parts.filter((p) => p.lines.length)
    .map((p, i) => ({ index: i + 1, start: p.start, end: p.end, text: p.lines.join('\n') }));
}

export function fullTranscript(items) {
  return items.filter((i) => i.text && i.text.trim())
    .map((i) => `[${fmtTime(i.start)} - ${fmtTime(i.end)}] ${i.text.trim()}`).join('\n');
}

export function applyKnownTimes(content, parts) {
  content.segments.forEach((s, i) => {
    const p = parts[i]; if (!p) return;
    s.segment_index = p.index; s.start_time = fmtTime(p.start); s.end_time = fmtTime(p.end);
  });
  return content;
}
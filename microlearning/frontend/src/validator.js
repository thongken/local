import { LIMITS } from './config.js';

export function validateContent(c, expectedSegments = null) {
  const e = [];
  const isStr = (v) => typeof v === 'string' && v.trim() !== '';
  if (!c || typeof c !== 'object') return ['Nội dung không phải object'];
  const s = c.summary;
  if (!s || typeof s !== 'object') e.push('Thiếu summary');
  else {
    if (!Array.isArray(s.main_points) || !s.main_points.every(isStr)) e.push('summary.main_points không hợp lệ');
    if (!isStr(s.short_summary)) e.push('summary.short_summary trống');
  }
  if (!Array.isArray(c.segments) || !c.segments.length) { e.push('Thiếu segments'); return e; }
  if (c.segments.length > LIMITS.maxSegments) e.push('Quá nhiều segments');
  if (expectedSegments && c.segments.length !== expectedSegments) e.push(`Cần ${expectedSegments} segments, nhận ${c.segments.length}`);
  c.segments.forEach((g, i) => {
    const t = `segments[${i}]`;
    if (!g || typeof g !== 'object') return e.push(`${t} không hợp lệ`);
    if (!isStr(g.segment_summary)) e.push(`${t}.segment_summary trống`);
    if (!Array.isArray(g.quiz) || !g.quiz.length) return e.push(`${t}.quiz trống`);
    if (g.quiz.length > LIMITS.maxQuestions) e.push(`${t} quá nhiều câu hỏi`);
    g.quiz.forEach((q, j) => {
      const u = `${t}.quiz[${j}]`;
      if (!q || !isStr(q.question)) return e.push(`${u}.question trống`);
      if (!Array.isArray(q.options) || q.options.length !== 4 || !q.options.every(isStr)) return e.push(`${u}.options phải có đúng 4 lựa chọn`);
      if (new Set(q.options.map((x) => x.trim())).size !== 4) e.push(`${u}.options bị trùng`);
      if (!isStr(q.correct_answer) || !q.options.some((o) => o.trim() === q.correct_answer.trim())) e.push(`${u}.correct_answer không khớp lựa chọn nào`);
      if (!isStr(q.explanation)) e.push(`${u}.explanation trống`);
    });
  });
  return e;
}
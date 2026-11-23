/** Merge adjacent transcript parts when fewer questions than parts are requested. */
export function planQuizParts(parts, questionCount) {
  if (!Number.isInteger(questionCount) || questionCount < 1 || questionCount > 30) {
    throw new Error('Số câu hỏi phải từ 1 đến 30.');
  }
  if (!parts.length) throw new Error('Không có nội dung để tạo câu hỏi.');
  const size = Math.min(parts.length, questionCount);
  const groups = Array.from({ length: size }, () => []);
  parts.forEach((part, index) => groups[Math.min(size - 1, Math.floor(index * size / parts.length))].push(part));
  return groups.map((group, index) => ({ index: index + 1, start: group[0].start,
    end: group[group.length - 1].end, text: group.map(part => part.text).join('\n') }));
}

export function questionAllocation(parts, count) {
  return parts.map((_, index) => Math.floor(count / parts.length) + (index < count % parts.length ? 1 : 0));
}

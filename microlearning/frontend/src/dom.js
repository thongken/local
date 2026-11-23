/** DOM helper an toàn XSS: luôn dùng textContent, không bao giờ innerHTML với dữ liệu người dùng. */
export function el(tag, attrs = {}, children = []) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
    else if (k === 'html') continue; // chặn cố ý: không hỗ trợ html thô
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const c of [].concat(children)) {
    if (c == null || c === false) continue;
    e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
  }
  return e;
}
export const text = (s) => document.createTextNode(String(s));
export function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
export function mount(node, children) { clear(node); for (const c of [].concat(children)) if (c) node.appendChild(c); return node; }
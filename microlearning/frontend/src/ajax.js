/**
 * Gọi core/ajax của Moodle. Nạp tool/javascript module 'core/ajax' qua
 * import động require() của AMD (được loader.js cấp sẵn ở window).
 */
export async function callAjax(methodname, args) {
  const coreAjax = window.__mlCoreAjax;
  if (!coreAjax) throw new Error('Thiếu core/ajax (chưa khởi tạo qua loader.js)');
  const [promise] = coreAjax.call([{ methodname, args }]);
  return promise;
}
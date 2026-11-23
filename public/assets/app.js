// نسخ رابط الموعد، وتأكيد الإلغاء والحذف، وتعطيل أوقات اليوم المغلق في جدول الدوام
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]');
  if (!b || !navigator.clipboard) return;
  navigator.clipboard.writeText(b.dataset.copy).then(function () {
    var t = b.textContent; b.textContent = 'نُسخ ✓'; setTimeout(function () { b.textContent = t; }, 1500);
  });
});
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (f.dataset.confirm && !confirm(f.dataset.confirm)) e.preventDefault();
});
function syncDay(cb) {
  cb.closest('tr').querySelectorAll('input[type=time]').forEach(function (i) { i.disabled = !cb.checked; });
}
document.querySelectorAll('input[data-day]').forEach(function (cb) {
  syncDay(cb);
  cb.addEventListener('change', function () { syncDay(cb); });
});
// زر الحجز يُعطّل بعد الضغط حتى لا يُرسل النموذج مرتين
document.querySelectorAll('form[data-once]').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button'); if (b) { b.disabled = true; b.textContent = 'جارٍ الحجز...'; } });
});

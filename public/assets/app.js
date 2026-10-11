// "select all" checkbox: <input type="checkbox" data-check-all="bc_ids[]">
document.querySelectorAll('[data-check-all]').forEach(function (master) {
  master.addEventListener('change', function () {
    document.querySelectorAll('input[name="' + master.dataset.checkAll + '"]').forEach(function (cb) {
      cb.checked = master.checked;
    });
  });
});

// copy text to clipboard: <button data-copy="...">
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var t = btn.dataset.copy;
    var done = function () { var o = btn.innerHTML; btn.innerHTML = '✅ Copied'; setTimeout(function () { btn.innerHTML = o; }, 1500); };
    if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done); return; }
    var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove(); done();
  });
});

// "select all" checkbox: <input type="checkbox" data-check-all="bc_ids[]">
document.querySelectorAll('[data-check-all]').forEach(function (master) {
  master.addEventListener('change', function () {
    document.querySelectorAll('input[name="' + master.dataset.checkAll + '"]').forEach(function (cb) {
      cb.checked = master.checked;
    });
  });
});

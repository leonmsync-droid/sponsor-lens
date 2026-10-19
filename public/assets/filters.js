// Rankings: apply the filters as soon as one changes.
document.querySelectorAll('form.filters select, form.filters input').forEach(function (el) {
  el.addEventListener('change', function () { el.form.submit(); });
});

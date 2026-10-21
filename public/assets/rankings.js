// Rankings page: click a row to open a short team summary under it; the team name still goes to the team page.
(function () {
  'use strict';

  var EASE = 'cubic-bezier(.32, .72, 0, 1)';
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('animate' in Element.prototype);
  var cache = {};

  var load = function (id) {
    cache[id] = cache[id] || fetch('team-preview.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .catch(function (e) { delete cache[id]; throw e; });
    return cache[id];
  };

  // Height + fade between two sizes; resolves when done (or straight away with reduced motion).
  var slide = function (box, from, to, opening) {
    if (still) return Promise.resolve();
    box.style.overflow = 'hidden';
    box.firstElementChild && box.firstElementChild.animate(
      { opacity: opening ? [0, 1] : [1, 0], transform: opening ? ['translateY(-6px)', 'none'] : ['none', 'translateY(-6px)'] },
      { duration: opening ? 520 : 260, easing: EASE, fill: 'both' });
    var anim = box.animate({ height: [from + 'px', to + 'px'] }, { duration: opening ? 520 : 400, easing: EASE });
    box._anim = anim;
    return anim.finished.then(function () {
      if (box._anim === anim) box.style.overflow = '';
    });
  };

  var open = function (row, button) {
    var id = row.dataset.id;
    var detail = document.createElement('tr');
    detail.className = 'preview-row';
    detail.innerHTML = '<td colspan="6"><div class="preview" id="preview-' + id + '"><div class="preview-body preview-loading">Loading…</div></div></td>';
    row.after(detail);
    row.classList.add('open');
    button.setAttribute('aria-expanded', 'true');
    button.setAttribute('aria-controls', 'preview-' + id);
    var box = detail.querySelector('.preview');

    slide(box, 0, box.scrollHeight, true);
    load(id).then(function (html) {
      if (!detail.isConnected) return;
      var from = box.getBoundingClientRect().height;
      box.innerHTML = html;
      slide(box, from, box.scrollHeight, true);
    }, function () {
      box.firstElementChild.textContent = 'Couldn’t load this team. Open the team page instead.';
    });
  };

  var close = function (row, button) {
    var detail = row.nextElementSibling;
    row.classList.remove('open');
    button.setAttribute('aria-expanded', 'false');
    if (!detail || !detail.classList.contains('preview-row') || detail.classList.contains('closing')) return;
    detail.classList.add('closing');
    var box = detail.querySelector('.preview');
    slide(box, box.getBoundingClientRect().height, 0, false).then(function () { detail.remove(); });
  };

  var table = document.querySelector('table.rankings');
  if (!table) return;

  table.addEventListener('click', function (event) {
    var row = event.target.closest('tr.team-row');
    if (!row) return;
    // Links (team name, column headers) keep their normal behaviour; so does selecting text.
    if (event.target.closest('a') || String(window.getSelection())) return;
    var button = row.querySelector('.row-toggle');
    var detail = row.nextElementSibling;
    var isOpen = row.classList.contains('open');
    if (!isOpen && detail && detail.classList.contains('preview-row')) detail.remove(); // still closing: replace it
    (isOpen ? close : open)(row, button);
  });
})();

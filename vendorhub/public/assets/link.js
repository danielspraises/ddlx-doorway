/* Admin: runs the product / order import one small batch at a time, so no request takes too long. */
(function () {
  var box = document.querySelector('[data-link-box]');
  if (!box) return;
  var seller = box.getAttribute('data-seller');
  var csrf = document.body.getAttribute('data-csrf') || '';
  var status = box.querySelector('[data-status]');
  var notes = box.querySelector('[data-notes]');
  var buttons = box.querySelectorAll('[data-run]');

  function post(kind, after) {
    var fd = new FormData();
    fd.append('seller', seller); fd.append('kind', kind); fd.append('after', String(after)); fd.append('_csrf', csrf);
    return fetch('/admin/link/run', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { return { error: 'The server sent something unexpected. Try again.' }; }); });
  }
  function addNote(t) { var li = document.createElement('li'); li.textContent = t; notes.appendChild(li); notes.classList.remove('hidden'); }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var kind = b.getAttribute('data-run');
      var done = 0, skipped = 0;
      buttons.forEach(function (x) { x.disabled = true; });
      notes.innerHTML = ''; notes.classList.add('hidden');
      status.textContent = 'Starting…';
      (function step(after) {
        post(kind, after).then(function (d) {
          if (d.error) { status.textContent = d.error; (d.notes || []).forEach(addNote); buttons.forEach(function (x) { x.disabled = false; }); return; }
          done += d.created || d.imported || 0; skipped += d.skipped || 0;
          (d.notes || []).forEach(addNote);
          var c = d.counts || {};
          var cp = box.querySelector('[data-count="products"]'), co = box.querySelector('[data-count="orders"]');
          if (cp && c.products !== undefined) cp.textContent = c.products + ' imported so far';
          if (co && c.orders !== undefined) co.textContent = c.orders + ' orders imported so far';
          if (d.next) { status.textContent = 'Working… ' + done + ' done' + (d.remaining ? ', about ' + d.remaining + ' to go' : ''); step(d.next); return; }
          status.textContent = 'Finished. ' + done + ' ' + kind + ' imported' + (skipped ? ', ' + skipped + ' skipped' : '') + '.';
          buttons.forEach(function (x) { x.disabled = false; });
        }).catch(function () { status.textContent = 'Connection problem. Press the button again to continue; nothing is duplicated.'; buttons.forEach(function (x) { x.disabled = false; }); });
      })(0);
    });
  });
})();

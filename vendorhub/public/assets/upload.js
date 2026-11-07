// Starts uploading as soon as a file is chosen and shows progress.
(function () {
  'use strict';
  document.querySelectorAll('form[data-autoupload]').forEach(function (form) {
    var input = form.querySelector('input[type=file]');
    var bar = form.querySelector('[data-upbar]');
    var fill = bar && bar.querySelector('i');
    var stat = form.querySelector('[data-upstat]');
    if (!input || !bar || !stat) return;
    input.removeAttribute('required');
    function say(text, cls) { stat.textContent = text; stat.className = 'upstat' + (cls ? ' ' + cls : ''); }
    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) return;
      var file = input.files[0];
      if (file.size > 10 * 1024 * 1024) { say('That file is over 10 MB. Choose a smaller one.', 'is-err'); input.value = ''; return; }
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      var fd = new FormData(form);
      input.disabled = true;
      bar.classList.remove('hidden'); fill.style.width = '0';
      say('Uploading… 0%');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var pct = Math.round(e.loaded / e.total * 100);
        fill.style.width = pct + '%';
        say('Uploading… ' + pct + '%');
      };
      xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (x) {}
        if (xhr.status === 200 && res.ok) {
          fill.style.width = '100%';
          say('Uploaded ✓', 'is-ok');
          setTimeout(function () { window.location.reload(); }, 700);
        } else {
          input.disabled = false; input.value = '';
          say(res.error || 'Upload failed. Please try again.', 'is-err');
        }
      };
      xhr.onerror = function () { input.disabled = false; input.value = ''; say('Upload failed. Check your connection and try again.', 'is-err'); };
      xhr.send(fd);
    });
  });
})();

// Store images: upload as soon as a picture is chosen.
(function () {
  document.querySelectorAll('form[data-autosubmit]').forEach(function (form) {
    var input = form.querySelector('input[type=file]');
    var note = form.querySelector('[data-upnote]');
    if (!input) return;
    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) return;
      if (input.files[0].size > 10 * 1024 * 1024) { if (note) note.textContent = 'That file is over 10 MB. Choose a smaller one.'; input.value = ''; return; }
      if (note) note.textContent = 'Uploading…';
      form.querySelectorAll('button,label.btn').forEach(function (b) { b.setAttribute('aria-disabled', 'true'); b.style.pointerEvents = 'none'; b.style.opacity = '.6'; });
      var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'action'; hidden.value = 'upload'; form.appendChild(hidden);
      form.submit();
    });
  });
})();

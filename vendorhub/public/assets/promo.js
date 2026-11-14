// Promo kit: copy link, download the flyer as a PNG, and show/hide the audience pickers.
(function () {
  'use strict';

  document.addEventListener('click', function (ev) {
    var c = ev.target.closest && ev.target.closest('[data-copy]');
    if (c) {
      var inp = document.querySelector(c.getAttribute('data-copy'));
      if (!inp) return;
      inp.select();
      var done = function () { var t = c.textContent; c.textContent = 'Copied'; setTimeout(function () { c.textContent = t; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(inp.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} });
      else { try { document.execCommand('copy'); done(); } catch (e) {} }
      return;
    }
    var b = ev.target.closest && ev.target.closest('[data-flyer-png]');
    if (b) {
      var label = b.textContent; b.disabled = true; b.textContent = 'Preparing…';
      var restore = function () { b.disabled = false; b.textContent = label; };
      fetch(b.getAttribute('data-flyer-png'), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (svg) {
        var url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
        var img = new Image();
        img.onload = function () {
          var cv = document.createElement('canvas'); cv.width = 1080; cv.height = 1350;
          cv.getContext('2d').drawImage(img, 0, 0, 1080, 1350);
          URL.revokeObjectURL(url);
          cv.toBlob(function (blob) {
            if (!blob) { restore(); return; }
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = b.getAttribute('data-name') || 'ddlx-flyer.png';
            document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000); restore();
          }, 'image/png');
        };
        img.onerror = function () { restore(); window.location.href = b.getAttribute('data-flyer-png') + '?download=1'; };
        img.src = url;
      }).catch(restore);
    }
  });

  function syncAudience(box) {
    var mode = (box.querySelector('input[name=mode]:checked') || {}).value || 'all';
    box.querySelectorAll('[data-aud-for]').forEach(function (el) { el.style.display = el.getAttribute('data-aud-for') === mode ? '' : 'none'; });
  }
  document.querySelectorAll('[data-aud]').forEach(function (box) {
    syncAudience(box);
    box.addEventListener('change', function () { syncAudience(box); });
  });
})();

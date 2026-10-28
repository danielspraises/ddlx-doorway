(function () {
  'use strict';
  var csrf = document.body.getAttribute('data-csrf') || '';
  window.VH = { csrf: csrf };

  function toast(msg) {
    var box = document.getElementById('toasts');
    if (!box || !msg) return;
    var t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 6000);
  }
  window.VH.toast = toast;

  // Mobile menu
  var toggle = document.querySelector('[data-menu]');
  var nav = document.querySelector('[data-nav]');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Switching the product type reloads the editor so the variations section appears or disappears.
  var typeSel = document.getElementById('f-product_type');
  if (typeSel) {
    typeSel.addEventListener('change', function () {
      var form = typeSel.form;
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'action'; h.value = 'rebuild';
      form.appendChild(h);
      form.submit();
    });
  }

  // Show / hide password
  document.querySelectorAll('[data-pw]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-pw'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
    });
  });

  // Confirm dialogs and double-submit protection
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    var msg = (ev.submitter && ev.submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    var btn = ev.submitter;
    if (btn && !form.hasAttribute('data-keep')) {
      setTimeout(function () { btn.disabled = true; }, 0);
    }
  });

  // Document preview (private files open in a dialog)
  var dlg = document.getElementById('preview-dialog');
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('[data-preview]');
    if (!a) return;
    ev.preventDefault();
    if (!dlg) { window.open(a.getAttribute('data-preview'), '_blank'); return; }
    var url = a.getAttribute('data-preview');
    var title = a.getAttribute('data-title') || 'Document';
    var body = dlg.querySelector('.dlg-body');
    dlg.querySelector('[data-dlg-title]').textContent = title;
    body.innerHTML = '';
    var isPdf = a.getAttribute('data-type') === 'pdf';
    var el = document.createElement(isPdf ? 'iframe' : 'img');
    el.src = url;
    if (!isPdf) el.alt = title;
    body.appendChild(el);
    dlg.showModal();
  });
  if (dlg) {
    dlg.querySelector('[data-dlg-close]').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('close', function () { dlg.querySelector('.dlg-body').innerHTML = ''; });
  }

  // Live updates: poll for new sales, reviews and payouts (shared hosting cannot hold a live connection open).
  if (document.body.hasAttribute('data-live')) {
    var since = null;
    var busy = false;
    var tick = function () {
      if (busy || document.hidden) return;
      busy = true;
      fetch('/api/pulse' + (since === null ? '' : '?since=' + since), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          var first = since === null;
          since = d.last;
          if (first) return;
          var changed = false;
          (d.events || []).forEach(function (ev) { changed = true; if (ev.message) toast(ev.message); });
          if (d.counts) {
            document.querySelectorAll('[data-badge]').forEach(function (el) {
              var key = el.getAttribute('data-badge');
              if (d.counts[key] !== undefined) { el.textContent = d.counts[key]; el.classList.toggle('hidden', !(d.counts[key] > 0)); }
            });
          }
          if (changed && document.body.hasAttribute('data-refresh')) softRefresh();
        })
        .catch(function () {})
        .then(function () { busy = false; });
    };
    var softRefresh = function () {
      var a = document.activeElement;
      if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
      fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('main');
        var cur = document.getElementById('main');
        if (fresh && cur) cur.innerHTML = fresh.innerHTML;
      }).catch(function () {});
    };
    tick();
    setInterval(tick, 12000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  }

  // Landing page: the five application steps
  var cards = document.querySelectorAll('[data-step]');
  if (cards.length) {
    cards.forEach(function (c) {
      c.addEventListener('click', function () {
        var n = c.getAttribute('data-step');
        cards.forEach(function (o) { o.setAttribute('aria-pressed', o === c ? 'true' : 'false'); });
        document.querySelectorAll('[data-detail]').forEach(function (d) { d.classList.toggle('on', d.getAttribute('data-detail') === n); });
      });
    });
  }

  // The tote bag leans gently towards the pointer
  var tote = document.querySelector('[data-parallax]');
  if (tote && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    window.addEventListener('pointermove', function (e) {
      var x = e.clientX / window.innerWidth - 0.5, y = e.clientY / window.innerHeight - 0.5;
      tote.style.transform = 'translate(' + (x * 18).toFixed(1) + 'px,' + (y * 10).toFixed(1) + 'px) rotateY(' + (x * 14).toFixed(1) + 'deg)';
    }, { passive: true });
  }

  // Close the account menu when clicking elsewhere
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.account[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
})();

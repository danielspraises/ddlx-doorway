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

  // Message composer: show the seller list only for "chosen sellers", and filter it as you type.
  document.querySelectorAll('[data-audience]').forEach(function (r) {
    r.addEventListener('change', function () {
      var box = document.getElementById('seller-pick');
      var sel = document.querySelector('[data-audience]:checked');
      if (box && sel) box.hidden = sel.value !== 'selected';
    });
  });
  document.querySelectorAll('[data-filter-list]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      document.querySelectorAll('#' + inp.getAttribute('data-filter-list') + ' [data-filter-item]').forEach(function (el) {
        el.hidden = q !== '' && el.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });

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

  // Return cases open in a dialog
  document.addEventListener('click', function (ev) {
    var o = ev.target.closest && ev.target.closest('[data-case-open]');
    if (o) { var d = document.getElementById(o.getAttribute('data-case-open')); if (d && d.showModal) d.showModal(); return; }
    var x = ev.target.closest && ev.target.closest('[data-case-close]');
    if (x) { var dd = x.closest('dialog'); if (dd) dd.close(); return; }
    if (ev.target.classList && ev.target.classList.contains('case-dlg')) ev.target.close();
  });
  if (location.hash.indexOf('#case-') === 0) { var hd = document.getElementById(location.hash.slice(1)); if (hd && hd.showModal) hd.showModal(); }

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

  // Questions that only appear for a certain answer: <div data-if="owns_brand=yes">
  var conds = document.querySelectorAll('[data-if]');
  if (conds.length) {
    var refresh = function () {
      conds.forEach(function (el) {
        var p = el.getAttribute('data-if').split('=');
        var ctl = document.querySelector('[name="' + p[0] + '"]');
        var on = ctl && ctl.value === p.slice(1).join('=');
        if (on) el.removeAttribute('hidden'); else el.setAttribute('hidden', '');
      });
    };
    document.addEventListener('change', function (e) { if (e.target && e.target.name) refresh(); });
    refresh();
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

// Scroll reveal for the landing page (respects reduced motion via CSS).
(function () {
  var els = document.querySelectorAll('[data-reveal]');
  if (!els.length) return;
  document.documentElement.classList.add('js');
  if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('in'); }); return; }
  var io = new IntersectionObserver(function (en) { en.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }); }, { threshold: 0.12 });
  els.forEach(function (e) { io.observe(e); });
})();

// Gentle mouse parallax on the landing hero.
(function () {
  var el = document.querySelector('[data-parallax]');
  if (!el || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
  var hero = el.closest('.hero') || document.body;
  hero.addEventListener('mousemove', function (e) {
    var r = hero.getBoundingClientRect();
    el.style.setProperty('--px', ((e.clientX - r.left) / r.width - 0.5) * -26 + 'px');
    el.style.setProperty('--py', ((e.clientY - r.top) / r.height - 0.5) * -20 + 'px');
  });
})();

// Live search and filters: a bar [data-fbar=x] controls the items inside [data-fbox=x].
(function () {
  document.querySelectorAll('[data-fbar]').forEach(function (bar) {
    var box = document.querySelector('[data-fbox="' + bar.getAttribute('data-fbar') + '"]');
    if (!box) return;
    var q = bar.querySelector('[data-fq]'), sel = bar.querySelector('[data-fs]'), count = bar.querySelector('[data-fcount]');
    var chips = bar.querySelectorAll('[data-fchip]');
    var chip = bar.getAttribute('data-fdefault') || (chips.length ? 'all' : '');
    function apply() {
      var text = q ? q.value.trim().toLowerCase() : '';
      var tag = sel ? sel.value : (chip && chip !== 'all' ? chip : '');
      var shown = 0, total = 0;
      box.querySelectorAll('[data-fitem]').forEach(function (it) {
        total++;
        var ok = (!tag || (' ' + it.getAttribute('data-tags') + ' ').indexOf(' ' + tag + ' ') !== -1) && (!text || (it.getAttribute('data-text') || it.textContent.toLowerCase()).indexOf(text) !== -1);
        it.hidden = !ok; if (ok) shown++;
      });
      var empty = box.querySelector('[data-fempty]'); if (empty) empty.hidden = shown !== 0;
      if (count) count.textContent = total ? (shown === total ? total + ' total' : shown + ' of ' + total) : '';
      chips.forEach(function (c) { c.classList.toggle('btn-outline', c.getAttribute('data-fchip') !== chip); });
    }
    if (q) q.addEventListener('input', apply);
    if (sel) sel.addEventListener('change', apply);
    chips.forEach(function (c) { c.addEventListener('click', function () { chip = c.getAttribute('data-fchip'); apply(); }); });
    apply();
  });
})();

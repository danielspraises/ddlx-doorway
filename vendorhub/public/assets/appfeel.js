/* App feel for the seller app: bottom sheets, loading placeholders between pages, pull to refresh and the app lock.
   The lock is a convenience for this phone only (it hides the app from someone holding it). It never replaces the password. */
(function () {
  var body = document.body;
  if (!body.classList.contains('app')) return;
  var uid = body.getAttribute('data-uid') || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---------- product details open in a sheet ----------
  document.addEventListener('click', function (e) {
    var r = e.target.closest ? e.target.closest('[data-pd]') : null;
    if (!r) return;
    var src = document.querySelector('[data-pd-src="' + r.getAttribute('data-pd') + '"]'), sh = document.getElementById('prod-sheet');
    if (!src || !sh) return;
    sh.querySelector('[data-pd-title]').textContent = r.getAttribute('data-pd-title') || '';
    sh.querySelector('[data-pd-body]').innerHTML = src.innerHTML;
    openSheet('prod-sheet');
  });

  // ---------- messages from the server show as a toast ----------
  function flash() {
    var f = $('#flash-src');
    if (!f) return;
    var m = f.getAttribute('data-m'); f.remove();
    if (m && window.VH.toast) window.VH.toast(m);
  }
  flash();

  // ---------- bottom sheets ----------
  function openSheet(id) {
    var d = document.getElementById(id);
    if (!d || !d.showModal) return;
    document.querySelectorAll('dialog.sheet[open]').forEach(function (o) { if (o !== d) o.close(); });
    d.showModal();
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-sheet]') : null;
    if (t) { e.preventDefault(); openSheet(t.getAttribute('data-sheet')); return; }
    var c = e.target.closest ? e.target.closest('[data-sheet-close]') : null;
    if (c) { var d = c.closest('dialog'); if (d) d.close(); return; }
    if (e.target.classList && e.target.classList.contains('sheet')) e.target.close();
  });

  // ---------- switches that save by themselves ----------
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-autosubmit') && t.form) { if (t.form.requestSubmit) t.form.requestSubmit(); else t.form.submit(); }
  });

  // ---------- hide balances ----------
  var HK = 'vh_hide_bal';
  var hidden = false;
  try { hidden = localStorage.getItem(HK) === '1'; } catch (x) {}
  function paintBal() {
    body.classList.toggle('bal-off', hidden);
    $$('[data-hide-bal]').forEach(function (b) { b.setAttribute('aria-pressed', hidden ? 'true' : 'false'); b.setAttribute('aria-label', hidden ? 'Show balances' : 'Hide balances'); });
    $$('[data-hide-toggle]').forEach(function (c) { c.checked = hidden; });
  }
  function setHidden(v) { hidden = v; try { localStorage.setItem(HK, v ? '1' : '0'); } catch (x) {} paintBal(); }
  paintBal();
  window.VH.paint = function () { paintBal(); if (typeof paintState === 'function') paintState(); flash(); };
  document.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-hide-bal]') : null; if (b) setHidden(!hidden); });
  document.addEventListener('change', function (e) { if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-hide-toggle')) setHidden(e.target.checked); });

  // ---------- loading placeholders between pages ----------
  var skelTimer = null;
  function skeleton() {
    var main = document.getElementById('main');
    if (!main || main.querySelector('.skel')) return;
    var s = document.createElement('div');
    s.className = 'skel';
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = '<i class="skel-h"></i><i class="skel-p"></i><i class="skel-c"></i><i class="skel-c"></i><i class="skel-c short"></i>';
    main.classList.add('loading');
    main.appendChild(s);
  }
  function clearSkeleton() {
    clearTimeout(skelTimer);
    var main = document.getElementById('main');
    if (main) { main.classList.remove('loading'); var s = main.querySelector('.skel'); if (s) s.remove(); }
  }

  // ---------- instant navigation: pages swap in place, like screens in an app ----------
  var BASE = ['app.js', 'pwa.js', 'help.js', 'native.js', 'appfeel.js'];
  var cache = {}, inflight = {}, seq = 0;
  var MAXAGE = 5 * 60 * 1000;
  var keyOf = function (u) { var x = new URL(u, location.href); return x.pathname + x.search; };
  function eligible(a) {
    if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-back') || a.hasAttribute('data-nonav')) return null;
    var u;
    try { u = new URL(a.href, location.href); } catch (x) { return null; }
    if (u.origin !== location.origin) return null;
    if (/^\/(files|logout|admin|api|install|health)/.test(u.pathname) || /\/(export|download)/.test(u.pathname) || /[?&]export=/.test(u.search)) return null;
    if (u.pathname === location.pathname && u.search === location.search && u.hash) return null;
    return u;
  }
  function fetchPage(url, force) {
    var k = keyOf(url);
    if (!force && inflight[k]) return inflight[k];
    var pr = fetch(url, { credentials: 'same-origin', headers: { 'X-VH-Nav': '1' } }).then(function (r) {
      if (!r.ok) throw new Error('status ' + r.status);
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('text/html') === -1) throw new Error('not a page');
      return r.text().then(function (html) { return { html: html, url: r.url, t: Date.now() }; });
    }).then(function (res) {
      cache[k] = res; cache[keyOf(res.url)] = res; delete inflight[k]; return res;
    }, function (err) { delete inflight[k]; throw err; });
    inflight[k] = pr;
    return pr;
  }
  window.VH.bust = function () { cache = {}; };
  document.addEventListener('submit', function () { cache = {}; }, true);

  function hard(url) { location.href = url; }
  // Puts a fetched page into the current screen. Returns false when this page must load the normal way.
  function swap(res, opts) {
    opts = opts || {};
    var doc = new DOMParser().parseFromString(res.html, 'text/html');
    if (!doc.body || !doc.body.classList.contains('app')) return false;
    var extra = Array.prototype.some.call(doc.querySelectorAll('script[src]'), function (sc) { return BASE.indexOf((sc.getAttribute('src') || '').split('?')[0].split('/').pop()) === -1; });
    if (extra) return false;
    var col = $('.app-col'), ncol = doc.querySelector('.app-col');
    if (!col || !ncol) return false;
    if (opts.quiet) {
      var m = $('#main'), nm = doc.getElementById('main');
      if (!m || !nm || m.innerHTML === nm.innerHTML) return true;
      var a = document.activeElement;
      if ((a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) || document.querySelector('dialog[open]')) return true;
      m.innerHTML = nm.innerHTML; if (window.VH.init) window.VH.init(m);
      return true;
    }
    document.title = doc.title;
    var keep = ['locked', 'bal-off'].filter(function (c) { return body.classList.contains(c); });
    body.className = doc.body.className + (keep.length ? ' ' + keep.join(' ') : '');
    if (doc.body.hasAttribute('data-refresh')) body.setAttribute('data-refresh', '1'); else body.removeAttribute('data-refresh');
    col.innerHTML = ncol.innerHTML;
    var tabs = $('.app-tabs'), ntabs = doc.querySelector('.app-tabs');
    if (tabs && ntabs) tabs.innerHTML = ntabs.innerHTML;
    var sn = $('.app-nav'), nsn = doc.querySelector('.app-nav');
    if (sn && nsn) sn.innerHTML = nsn.innerHTML;
    var tc = $('meta[name=theme-color]'), ntc = doc.querySelector('meta[name=theme-color]');
    if (tc && ntc) tc.setAttribute('content', ntc.getAttribute('content'));
    var mm = $('#main');
    if (window.VH.init) window.VH.init(mm || document);
    if (mm) { mm.classList.remove('swap'); void mm.offsetWidth; mm.classList.add('swap'); }
    if (window.VH.paint) window.VH.paint();
    window.scrollTo(0, opts.y || 0);
    return true;
  }
  function go(url, opts) {
    opts = opts || {};
    var k = keyOf(url), my = ++seq;
    var done = function (res) {
      if (my !== seq) return;
      clearSkeleton();
      if (!opts.pop && !opts.replace) { try { history.replaceState({ vh: 1, y: window.scrollY }, ''); } catch (x) {} }
      if (!swap(res, { y: opts.y })) return hard(url);
      var final = new URL(res.url, location.href);
      var path = final.pathname + final.search;
      if (opts.pop) return;
      try { history[opts.replace ? 'replaceState' : 'pushState']({ vh: 1, y: 0 }, '', path + final.hash); } catch (x) { hard(url); }
    };
    var hit = cache[k];
    if (hit && !opts.force && Date.now() - hit.t < MAXAGE) {
      done(hit);
      // Show what we have now, then quietly bring it up to date.
      fetchPage(url, true).then(function (fresh) { if (my === seq && keyOf(location.href) === keyOf(fresh.url)) swap(fresh, { quiet: true }); }).catch(function () {});
      return;
    }
    clearTimeout(skelTimer);
    skelTimer = setTimeout(skeleton, 120);
    fetchPage(url, true).then(done).catch(function () { if (my === seq) hard(url); });
  }
  window.VH.go = go;

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    var u = eligible(a);
    if (!u) return;
    e.preventDefault();
    if (u.pathname === location.pathname && u.search === location.search) { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    go(u.href);
  });
  window.addEventListener('popstate', function (e) {
    var st = e.state;
    if (!st || !st.vh) return;
    go(location.href, { pop: true, y: st.y || 0 });
  });
  try { history.replaceState({ vh: 1, y: 0 }, ''); } catch (x) {}

  // Start loading a page the moment a finger or pointer lands on its link.
  var warm = function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    var u = eligible(a);
    if (!u) return;
    var k = keyOf(u.href), hit = cache[k];
    if (hit && Date.now() - hit.t < 20000) return;
    fetchPage(u.href).catch(function () {});
  };
  document.addEventListener('touchstart', warm, { passive: true });
  document.addEventListener('mouseover', warm, { passive: true });
  // After the first screen is up, quietly load the other tabs so switching is instant.
  var saver = navigator.connection && navigator.connection.saveData;
  if (!saver) setTimeout(function () {
    var list = $$('.app-tabs a').map(function (a) { return a.href; }).filter(function (h) { return keyOf(h) !== keyOf(location.href); });
    (function next() {
      var h = list.shift();
      if (!h || document.hidden) return;
      fetchPage(h).catch(function () {}).then(function () { setTimeout(next, 500); });
    })();
  }, 1500);
  window.addEventListener('pageshow', clearSkeleton);

  // ---------- pull to refresh ----------
  if ('ontouchstart' in window) {
    var ind = document.createElement('div');
    ind.className = 'ptr';
    ind.innerHTML = '<span></span>';
    body.appendChild(ind);
    var y0 = null, pull = 0, busy = false;
    var TH = 74;
    var reset = function () { ind.style.transform = ''; ind.classList.remove('go', 'spin'); pull = 0; y0 = null; };
    document.addEventListener('touchstart', function (e) {
      if (busy || !body.classList.contains('app-root') || window.scrollY > 0 || e.touches.length !== 1 || document.querySelector('dialog[open]')) { y0 = null; return; }
      y0 = e.touches[0].clientY;
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
      if (y0 === null || busy) return;
      var dy = e.touches[0].clientY - y0;
      if (dy <= 0 || window.scrollY > 0) { if (pull) reset(); return; }
      pull = Math.min(dy * 0.5, 110);
      ind.style.transform = 'translate(-50%,' + (pull - 44) + 'px) rotate(' + (pull * 4) + 'deg)';
      ind.classList.toggle('go', pull >= TH * 0.5);
    }, { passive: true });
    document.addEventListener('touchend', function () {
      if (y0 === null) return;
      if (pull >= TH * 0.5) {
        busy = true;
        ind.classList.add('spin');
        ind.style.transform = 'translate(-50%,16px)';
        if (navigator.vibrate) try { navigator.vibrate(8); } catch (x) {}
        if (window.VH.go) { cache = {}; window.VH.go(location.href, { force: true, replace: true }); setTimeout(function () { busy = false; reset(); }, 600); } else location.reload();
      } else reset();
    });
  }

  // ---------- app lock ----------
  var KEY = 'vh_lock_' + uid;
  var lockEl = $('#lock');
  var cfg = null;
  try { cfg = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (x) {}
  var enc = new TextEncoder();
  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function hashPin(pin, salt) { return crypto.subtle.digest('SHA-256', enc.encode(salt + ':' + pin)).then(hex); }
  function b64(buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))); }
  function unb64(s) { return Uint8Array.from(atob(s), function (c) { return c.charCodeAt(0); }); }
  function cookie(name, val) {
    document.cookie = name + '=' + (val === null ? '; Max-Age=0' : val + (name === 'vh_lock' ? '; Max-Age=31536000' : '')) + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  var hasBio = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

  function paintState() {
    $$('[data-lock-state]').forEach(function (el) { el.textContent = cfg ? 'On' : 'Off'; });
  }
  paintState();

  function unlock() {
    cookie('vh_open', '1');
    if (lockEl) { lockEl.classList.remove('on'); lockEl.hidden = true; }
    body.classList.remove('locked');
  }
  function showLock() {
    if (!cfg || !lockEl) return;
    cookie('vh_open', null);
    lockEl.hidden = false; lockEl.classList.add('on'); body.classList.add('locked');
    entry = '';
    paintDots();
    var bioBtn = $('[data-lock-bio]', lockEl);
    if (bioBtn) bioBtn.classList.toggle('hidden', !(cfg.bio && hasBio));
    if (cfg.bio && hasBio) bioUnlock(true);
  }

  var entry = '';
  function paintDots() { $$('[data-lock-dots] i', lockEl).forEach(function (d, i) { d.classList.toggle('f', i < entry.length); }); }
  function wrong() {
    var err = $('[data-lock-err]', lockEl); if (err) err.textContent = 'Wrong PIN, try again';
    var dots = $('[data-lock-dots]', lockEl); if (dots) { dots.classList.remove('shake'); void dots.offsetWidth; dots.classList.add('shake'); }
    if (navigator.vibrate) try { navigator.vibrate(60); } catch (x) {}
    entry = ''; paintDots();
  }
  function press(k) {
    var err = $('[data-lock-err]', lockEl); if (err) err.textContent = '';
    if (k === 'del') entry = entry.slice(0, -1);
    else if (entry.length < 4) entry += k;
    paintDots();
    if (entry.length === 4 && cfg) {
      var tried = entry;
      hashPin(tried, cfg.salt).then(function (h) { if (h === cfg.hash) unlock(); else wrong(); });
    }
  }
  function bioUnlock(auto) {
    if (!cfg || !cfg.bio || !hasBio) return;
    navigator.credentials.get({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), rpId: location.hostname, allowCredentials: [{ type: 'public-key', id: unb64(cfg.bio) }], userVerification: 'required', timeout: 60000 } })
      .then(function () { unlock(); })
      .catch(function () { if (!auto) { var err = $('[data-lock-err]', lockEl); if (err) err.textContent = 'Could not verify, use your PIN'; } });
  }
  if (lockEl) {
    var pad = $('[data-lock-pad]', lockEl);
    if (pad) {
      ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'].forEach(function (k) {
        var b = document.createElement('button');
        b.type = 'button';
        if (k === '') { b.className = 'blank'; b.disabled = true; } else { b.textContent = k === 'del' ? '⌫' : k; b.setAttribute('aria-label', k === 'del' ? 'Delete' : k); b.addEventListener('click', function () { press(k); }); }
        pad.appendChild(b);
      });
    }
    var bb = $('[data-lock-bio]', lockEl); if (bb) bb.addEventListener('click', function () { bioUnlock(false); });
    document.addEventListener('keydown', function (e) {
      if (lockEl.hidden) return;
      if (/^[0-9]$/.test(e.key)) press(e.key); else if (e.key === 'Backspace') press('del');
    });
  }

  if (cfg && !lockEl.hidden) { body.classList.add('locked'); var bb2 = $('[data-lock-bio]', lockEl); if (bb2) bb2.classList.toggle('hidden', !(cfg.bio && hasBio)); if (cfg.bio && hasBio) setTimeout(function () { bioUnlock(true); }, 250); }
  // Stale cookie (lock turned off on this phone): drop it so the flag cannot lock out a phone with no PIN.
  if (!cfg && document.cookie.indexOf('vh_lock=1') !== -1) { cookie('vh_lock', null); if (lockEl) { lockEl.hidden = true; lockEl.classList.remove('on'); } }
  if (cfg && document.cookie.indexOf('vh_lock=1') === -1) cookie('vh_lock', '1');

  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    if (cfg && hiddenAt && Date.now() - hiddenAt > 60000) showLock();
    hiddenAt = 0;
  });

  // ---------- lock setup ----------
  var sheet = $('#lock-sheet');
  if (sheet) {
    var pin = $('[data-lk-pin]', sheet), pin2 = $('[data-lk-pin2]', sheet), bio = $('[data-lk-bio]', sheet), bioRow = $('[data-lk-biorow]', sheet);
    var err = $('[data-lk-err]', sheet), save = $('[data-lk-save]', sheet), off = $('[data-lk-off]', sheet);
    var say = function (m) { err.textContent = m || ''; err.classList.toggle('hidden', !m); };
    var fill = function () {
      pin.value = ''; pin2.value = ''; say('');
      bioRow.classList.toggle('hidden', !hasBio);
      bio.checked = !!(cfg && cfg.bio);
      off.classList.toggle('hidden', !cfg);
      save.textContent = cfg ? 'Change PIN' : 'Turn on';
    };
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-lock-setup]') : null;
      if (!t) return;
      e.preventDefault();
      if (!window.crypto || !crypto.subtle) { alert('The app lock needs a secure (https) connection.'); return; }
      fill(); openSheet('lock-sheet');
    });
    $$('input', sheet).forEach(function (i) { i.addEventListener('input', function () { i.value = i.value.replace(/\D/g, '').slice(0, 4); }); });
    save.addEventListener('click', function () {
      if (!/^\d{4}$/.test(pin.value)) return say('Use exactly 4 digits.');
      if (pin.value !== pin2.value) return say('The two PINs do not match.');
      var salt = hex(crypto.getRandomValues(new Uint8Array(12)));
      var next = { salt: salt, hash: '', bio: (cfg && cfg.bio && bio.checked) ? cfg.bio : null };
      var done = function () {
        hashPin(pin.value, salt).then(function (h) {
          next.hash = h;
          try { localStorage.setItem(KEY, JSON.stringify(next)); } catch (x) { return say('This browser cannot save the lock.'); }
          cfg = next; cookie('vh_lock', '1'); cookie('vh_open', '1'); paintState(); sheet.close();
        });
      };
      if (bio.checked && !next.bio && hasBio) {
        navigator.credentials.create({ publicKey: {
          challenge: crypto.getRandomValues(new Uint8Array(32)), rp: { name: 'Vendor Hub', id: location.hostname },
          user: { id: enc.encode(uid || 'seller'), name: body.getAttribute('data-who') || 'seller', displayName: body.getAttribute('data-who') || 'seller' },
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
          authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' }, timeout: 60000
        } }).then(function (c) { next.bio = b64(c.rawId); done(); }).catch(function () { next.bio = null; say('Biometrics were not set up. The PIN will still work.'); done(); });
      } else done();
    });
    off.addEventListener('click', function () {
      try { localStorage.removeItem(KEY); } catch (x) {}
      cfg = null; cookie('vh_lock', null); cookie('vh_open', null); paintState(); sheet.close();
    });
  }
})();

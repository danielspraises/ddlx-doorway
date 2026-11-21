/* App feel for the seller app: bottom sheets, loading placeholders between pages, pull to refresh and the app lock.
   The lock is a convenience for this phone only (it hides the app from someone holding it). It never replaces the password. */
(function () {
  var body = document.body;
  if (!body.classList.contains('app')) return;
  var uid = body.getAttribute('data-uid') || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

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
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-back')) return;
    var u;
    try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search)) return;
    if (/^\/(files|logout|export)/.test(u.pathname) || /[?&]export=/.test(u.search)) return;
    clearTimeout(skelTimer);
    skelTimer = setTimeout(skeleton, 140);
  });
  window.addEventListener('pageshow', clearSkeleton);

  // ---------- pull to refresh ----------
  if ('ontouchstart' in window && body.classList.contains('app-root')) {
    var ind = document.createElement('div');
    ind.className = 'ptr';
    ind.innerHTML = '<span></span>';
    body.appendChild(ind);
    var y0 = null, pull = 0, busy = false;
    var TH = 74;
    var reset = function () { ind.style.transform = ''; ind.classList.remove('go', 'spin'); pull = 0; y0 = null; };
    document.addEventListener('touchstart', function (e) {
      if (busy || window.scrollY > 0 || e.touches.length !== 1 || document.querySelector('dialog[open]')) { y0 = null; return; }
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
        location.reload();
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
    $$('[data-lock-state]').forEach(function (el) { el.textContent = cfg ? 'On' + (cfg.bio ? ' · PIN and biometrics' : ' · PIN') : 'Ask for a PIN when the app opens'; });
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

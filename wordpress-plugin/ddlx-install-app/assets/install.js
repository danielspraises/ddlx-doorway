(function () {
  'use strict';
  var C = window.DDLX_INSTALL || {};
  var KEY = 'ddlx_install_snooze';
  var deferred = null;
  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var inApp = /FBAN|FBAV|Instagram|WhatsApp|Line\/|Twitter|TikTok|Snapchat|MicroMessenger/i.test(ua);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var sheet = null;

  function store(get, v) { try { return get ? localStorage.getItem(KEY) : localStorage.setItem(KEY, v); } catch (e) { return null; } }
  function snoozed() { var t = parseInt(store(true), 10); return t && Date.now() < t; }
  function snooze() { store(false, String(Date.now() + (C.snooze || 7) * 86400000)); }
  function installed() { return standalone; }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }

  function build(mode) { // mode: 'prompt' | 'ios' | 'manual' | 'inapp'
    close(true);
    sheet = el('div', 'ddlx-i-sheet');
    var card = el('div', 'ddlx-i-card');
    var top = el('div', 'ddlx-i-top');
    var icon = el('div', 'ddlx-i-icon'); if (C.icon) icon.style.backgroundImage = 'url("' + C.icon.replace(/"/g, '') + '")';
    var box = el('div');
    box.appendChild(el('h3', '', C.title || 'Get the app'));
    box.appendChild(el('p', '', mode === 'inapp'
      ? 'To install, open this page in Safari (iPhone) or Chrome (Android) using the menu in the corner.'
      : (C.text || '')));
    top.appendChild(icon); top.appendChild(box); card.appendChild(top);
    if (mode === 'ios' || mode === 'manual') {
      var ol = el('ol', 'ddlx-i-steps');
      var steps = mode === 'ios' ? (C.steps || []) : ['Open your browser menu (the three dots).', 'Tap \u201CInstall app\u201D or \u201CAdd to Home screen\u201D.', 'Confirm to finish.'];
      steps.forEach(function (s) { ol.appendChild(el('li', '', s)); });
      card.appendChild(ol);
      if (mode === 'ios') card.appendChild(el('div', 'ddlx-i-arrow', '\u2B07'));
    }
    var row = el('div', 'ddlx-i-row');
    if (mode === 'prompt') {
      var go = el('button', 'ddlx-i-go', C.button || 'Install app'); go.type = 'button';
      go.addEventListener('click', function () { doInstall(); });
      row.appendChild(go);
    }
    var no = el('button', 'ddlx-i-no', mode === 'prompt' ? (C.later || 'Not now') : 'Got it'); no.type = 'button';
    no.addEventListener('click', function () { snooze(); close(); });
    row.appendChild(no); card.appendChild(row);
    sheet.appendChild(card); document.body.appendChild(sheet);
    requestAnimationFrame(function () { requestAnimationFrame(function () { sheet.classList.add('open'); }); });
  }
  function close(now) {
    if (!sheet) return;
    var s = sheet; sheet = null; s.classList.remove('open');
    if (now) s.remove(); else setTimeout(function () { s.remove(); }, 400);
  }

  // The one function every button and the pop-up call.
  function doInstall() {
    if (installed()) return;
    if (deferred) {
      var d = deferred; deferred = null;
      d.prompt();
      d.userChoice.then(function (r) { if (r && r.outcome === 'accepted') { close(); hideButtons(); } else { snooze(); close(); } });
      return;
    }
    build(inApp ? 'inapp' : (isIOS ? 'ios' : 'manual'));
  }
  function hideButtons() {
    document.querySelectorAll('[data-ddlx-install],.ddlx-install,a[href="#install-app"],a[href="#ddlx-install"]').forEach(function (b) { b.classList.add('ddlx-i-hide'); });
  }

  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; schedule(); });
  window.addEventListener('appinstalled', function () { deferred = null; close(); hideButtons(); snooze(); });

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-ddlx-install],.ddlx-install,a[href="#install-app"],a[href="#ddlx-install"]');
    if (!t) return;
    e.preventDefault(); doInstall();
  });

  var scheduled = false;
  function schedule() {
    if (scheduled || !C.popup || installed() || snoozed()) return;
    scheduled = true;
    setTimeout(function () {
      if (installed() || snoozed() || sheet) { scheduled = false; return; }
      if (deferred) build('prompt');
      else if (isIOS && !inApp) build('ios');
    }, Math.max(0, (C.delay || 0)) * 1000);
  }

  if (installed()) { document.addEventListener('DOMContentLoaded', hideButtons); return; }
  // iPhone never fires beforeinstallprompt, so start the timer for it directly.
  if (isIOS && !inApp) schedule();
})();

/* Installable app: registers the service worker and powers the "Install app" menu button. */
(function () {
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
  }
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var btns = document.querySelectorAll('[data-install]');
  if (standalone || !btns.length) return;
  var deferred = null;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  function show() { btns.forEach(function (b) { b.classList.remove('hidden'); }); }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(); });
  window.addEventListener('appinstalled', function () { deferred = null; btns.forEach(function (b) { b.classList.add('hidden'); }); });
  if (ios) show();
  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      if (deferred) { deferred.prompt(); deferred.userChoice.finally(function () { deferred = null; btns.forEach(function (x) { x.classList.add('hidden'); }); }); return; }
      if (ios) { b.textContent = 'Tap Share, then Add to Home Screen'; }
    });
  });
})();

/* Inside the Android app only: asks for notification permission, tells Vendor Hub which phone to notify,
   opens the right page when a notification is tapped, and forgets the phone on sign out. */
(function () {
  var C = window.Capacitor;
  if (!C || !C.isNativePlatform || !C.isNativePlatform()) return;
  document.documentElement.classList.add('in-app');
  var P = C.Plugins && C.Plugins.PushNotifications;
  var csrf = document.body.getAttribute('data-csrf') || '';
  var signedIn = document.body.hasAttribute('data-live');
  if (!P || !signedIn) return;
  var KEY = 'vh_push_token';

  function send(path, token) {
    var body = new URLSearchParams({ token: token, platform: C.getPlatform ? C.getPlatform() : 'android' });
    return fetch(path, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true, headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } }).catch(function () {});
  }

  P.addListener('registration', function (t) {
    try { localStorage.setItem(KEY, t.value); } catch (e) {}
    send('/api/push/register', t.value);
  });
  P.addListener('registrationError', function () {});
  P.addListener('pushNotificationActionPerformed', function (a) {
    var link = a && a.notification && a.notification.data && a.notification.data.link;
    if (typeof link === 'string' && link.charAt(0) === '/' && link.charAt(1) !== '/') location.href = link;
  });

  if (P.createChannel) P.createChannel({ id: 'default', name: 'Vendor Hub alerts', description: 'Orders, messages and payouts', importance: 4, sound: 'default', visibility: 1 }).catch(function () {});
  P.checkPermissions().then(function (s) {
    return s.receive === 'granted' ? s : P.requestPermissions();
  }).then(function (s) {
    if (s.receive === 'granted') P.register();
  }).catch(function () {});

  // Signing out: stop sending this person's alerts to this phone.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.getAttribute || f.getAttribute('action') !== '/logout') return;
    var tok = ''; try { tok = localStorage.getItem(KEY) || ''; } catch (x) {}
    if (!tok) return;
    e.preventDefault();
    try { localStorage.removeItem(KEY); } catch (x) {}
    Promise.race([send('/api/push/unregister', tok), new Promise(function (r) { setTimeout(r, 2500); })]).then(function () { f.submit(); });
  }, true);
})();

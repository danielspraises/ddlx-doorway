// Fetches the account holder's name from the bank as soon as the bank and the 10 digits are filled in.
(function () {
  var form = document.querySelector('form[data-bank-form]');
  if (!form) return;
  var verify = form.getAttribute('data-verify') === '1';
  var bank = form.querySelector('[name=bank_name]');
  var num = form.querySelector('[name=account_number]');
  var name = form.querySelector('[name=account_name]');
  var stat = form.querySelector('[data-bkstatus]');
  var save = form.querySelector('[data-bksave]');
  var seq = 0;
  function say(t, cls) { if (stat) { stat.textContent = t; stat.className = 'small bk-status' + (cls ? ' ' + cls : ''); } }
  function check() {
    num.value = num.value.replace(/\D/g, '').slice(0, 10);
    if (!verify) return;
    name.value = ''; if (save) save.disabled = true;
    if (!bank.value || num.value.length !== 10) { say(''); return; }
    var mine = ++seq;
    say('Checking with the bank…', 'is-busy');
    fetch('/payouts/resolve?bank=' + encodeURIComponent(bank.value) + '&number=' + encodeURIComponent(num.value), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (mine !== seq) return;
        if (d.name) { name.value = d.name; say('Verified: this account belongs to ' + d.name + '.', 'is-ok'); if (save) save.disabled = false; }
        else say(d.error || 'We could not verify this account.', 'is-err');
      })
      .catch(function () { if (mine === seq) say('Could not reach the bank check. Try again.', 'is-err'); });
  }
  bank.addEventListener('change', check);
  num.addEventListener('input', check);
})();

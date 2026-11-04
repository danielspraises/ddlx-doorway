// Ask DDLX: send a question and show the answer without reloading the page.
(function () {
  'use strict';
  var form = document.querySelector('form[data-assistant]');
  if (!form) return;
  var chat = document.querySelector('.chat');
  var box = form.querySelector('textarea');
  var btn = form.querySelector('button[type=submit]');
  var err = form.parentNode.querySelector('[data-aerr]') || document.querySelector('[data-aerr]');
  if (!chat || !box || !btn) return;

  function bubble(role, text) {
    var b = document.createElement('div');
    b.className = 'bubble ' + (role === 'user' ? 'mine' : 'theirs');
    var w = document.createElement('div'); w.className = 'who'; w.textContent = role === 'user' ? 'You' : 'Ask DDLX';
    var t = document.createElement('div'); t.className = 'txt'; t.style.whiteSpace = 'pre-line'; t.textContent = text;
    b.appendChild(w); b.appendChild(t); chat.appendChild(b);
    chat.scrollTop = chat.scrollHeight;
    return b;
  }
  function showErr(m) { if (!err) return; err.textContent = m || ''; err.classList.toggle('hidden', !m); }

  // Show the newest message when the page opens.
  chat.scrollTop = chat.scrollHeight;

  function send() {
    var q = box.value.trim();
    if (!q || btn.disabled) return;
    showErr('');
    // The "try one of these" hint goes away once a conversation starts.
    chat.querySelectorAll('.chip, p.muted, p.row').forEach(function (n) { n.remove(); });
    bubble('user', q);
    var wait = bubble('assistant', 'Thinking…'); wait.style.opacity = '.7';
    var fd = new FormData(form); fd.set('question', q);
    box.value = ''; btn.disabled = true; box.disabled = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 90000;
    function done() { btn.disabled = false; box.disabled = false; box.focus(); }
    xhr.onload = function () {
      var r = {}; try { r = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status === 200 && r.ok) {
        wait.querySelector('.txt').textContent = r.reply; wait.style.opacity = '';
        chat.scrollTop = chat.scrollHeight;
      } else {
        wait.remove(); chat.lastElementChild && chat.lastElementChild.classList.contains('mine') && chat.lastElementChild.remove();
        box.value = q; showErr(r.error || 'Something went wrong. Please try again.');
      }
      done();
    };
    xhr.onerror = xhr.ontimeout = function () {
      wait.remove(); chat.lastElementChild && chat.lastElementChild.classList.contains('mine') && chat.lastElementChild.remove();
      box.value = q; showErr('Could not reach the server. Check your connection and try again.'); done();
    };
    xhr.send(fd);
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
  box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
})();

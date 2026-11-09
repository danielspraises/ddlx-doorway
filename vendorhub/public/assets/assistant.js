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

  // The sidebar gets the new conversation straight away, at the top.
  var list = document.querySelector('[data-chats]');
  function addChat(id, title) {
    if (!list) return;
    var empty = list.querySelector('[data-empty]'); if (empty) empty.remove();
    list.querySelectorAll('li.on').forEach(function (n) { n.classList.remove('on'); });
    var li = document.createElement('li'); li.className = 'on'; li.setAttribute('data-id', id);
    var a = document.createElement('a'); a.href = '/assistant?c=' + encodeURIComponent(id);
    var sp = document.createElement('span'); sp.textContent = title || 'New chat';
    var sm = document.createElement('small'); sm.textContent = 'Just now';
    a.appendChild(sp); a.appendChild(sm); li.appendChild(a);
    var tok = form.querySelector('input[name=_csrf]');
    if (tok) {
      var f = document.createElement('form'); f.method = 'post'; f.action = '/assistant/delete'; f.setAttribute('data-keep', ''); f.setAttribute('data-confirm', 'Delete this conversation?');
      [['_csrf', tok.value], ['chat', id]].forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
      var d = document.createElement('button'); d.type = 'submit'; d.className = 'chat-del'; d.setAttribute('aria-label', 'Delete conversation'); d.textContent = '\u00d7'; f.appendChild(d); li.appendChild(f);
    }
    list.insertBefore(li, list.firstChild);
  }
  function bump(id) {
    if (!list) return;
    var li = list.querySelector('li.on'); if (li && li !== list.firstChild) list.insertBefore(li, list.firstChild);
    var sm = li && li.querySelector('small'); if (sm) sm.textContent = 'Just now';
  }
  // On a phone the list starts folded away.
  var side = document.querySelector('details.chat-list');
  if (side && window.matchMedia && window.matchMedia('(max-width: 800px)').matches) side.removeAttribute('open');

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
        if (r.chat) {
          var hid = form.querySelector('input[name=chat]');
          if (hid && !hid.value) { hid.value = r.chat; addChat(r.chat, r.title); try { history.replaceState(null, '', '/assistant?c=' + r.chat); } catch (e) {} }
          else if (hid) bump(r.chat);
        }
        var tx = wait.querySelector('.txt'); tx.style.whiteSpace = ''; tx.classList.add('rich'); if (r.html) tx.innerHTML = r.html; else tx.textContent = r.reply; wait.style.opacity = '';
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

// "?" help icons: show on hover or keyboard focus (desktop) and on tap (phones). One shared pop-up.
(function () {
  'use strict';
  var pop = null, cur = null;
  function ensure() {
    if (pop) return pop;
    pop = document.createElement('div'); pop.className = 'help-pop'; pop.setAttribute('role', 'tooltip'); pop.id = 'help-pop';
    document.body.appendChild(pop); return pop;
  }
  function place(btn) {
    var r = btn.getBoundingClientRect(), p = ensure();
    var w = p.offsetWidth, h = p.offsetHeight, vw = document.documentElement.clientWidth;
    var left = Math.max(12, Math.min(r.left + r.width / 2 - w / 2, vw - w - 12));
    var top = r.bottom + 8;
    if (top + h > window.innerHeight - 8 && r.top - h - 8 > 8) top = r.top - h - 8;
    p.style.left = left + 'px'; p.style.top = top + 'px';
  }
  function show(btn) {
    var p = ensure();
    if (cur && cur !== btn) cur.classList.remove('on');
    cur = btn; p.textContent = btn.getAttribute('data-help') || '';
    p.classList.remove('on'); place(btn);
    btn.classList.add('on'); btn.setAttribute('aria-describedby', 'help-pop');
    requestAnimationFrame(function () { p.classList.add('on'); });
  }
  function hide() {
    if (cur) { cur.classList.remove('on'); cur.removeAttribute('aria-describedby'); cur = null; }
    if (pop) pop.classList.remove('on');
  }
  var canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
  document.addEventListener('mouseover', function (e) { var b = canHover && e.target.closest && e.target.closest('.help'); if (b) show(b); });
  document.addEventListener('mouseout', function (e) { var b = e.target.closest && e.target.closest('.help'); if (b && cur === b && !(document.activeElement === b)) hide(); });
  document.addEventListener('focusin', function (e) { var b = e.target.closest && e.target.closest('.help'); if (b) show(b); });
  document.addEventListener('focusout', function (e) { if (e.target.closest && e.target.closest('.help')) hide(); });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.help');
    if (b) { e.preventDefault(); e.stopPropagation(); if (cur === b && pop && pop.classList.contains('on') && !canHover) hide(); else show(b); return; }
    hide();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
})();

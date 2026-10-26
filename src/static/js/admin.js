(function () {
  'use strict';
  var toggle = document.getElementById('sideToggle');
  var side = document.getElementById('adminSide');
  var mask = document.getElementById('adminMask');
  function closeSide() {
    if (side) { side.classList.remove('open'); }
    if (mask) { mask.classList.remove('show'); }
  }
  if (toggle && side) {
    toggle.addEventListener('click', function () {
      side.classList.toggle('open');
      if (mask) { mask.classList.toggle('show', side.classList.contains('open')); }
    });
  }
  if (mask) { mask.addEventListener('click', closeSide); }

  // 二次确认：<button data-confirm="确定？">
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  });
  // 刷新验证码
  document.querySelectorAll('[data-captcha]').forEach(function (el) {
    el.addEventListener('click', function () {
      el.src = el.src.split('?')[0] + '?t=' + Date.now();
    });
  });
  // 复制
  document.querySelectorAll('[data-copy]').forEach(function (el) {
    el.addEventListener('click', function () {
      var x = document.querySelector(el.getAttribute('data-copy'));
      if (x && navigator.clipboard) {
        navigator.clipboard.writeText(x.value || x.textContent);
        el.textContent = '已复制';
      }
    });
  });
  // 条件显示：<div data-show-when="#refund_mode=partial|full">
  function refreshShowWhen() {
    document.querySelectorAll('[data-show-when]').forEach(function (el) {
      var rule = el.getAttribute('data-show-when').split('=');
      var src = document.querySelector(rule[0]);
      if (!src) { return; }
      var val = src.type === 'checkbox' ? (src.checked ? '1' : '0') : src.value;
      el.style.display = rule[1].split('|').indexOf(val) >= 0 ? '' : 'none';
    });
  }
  document.addEventListener('change', refreshShowWhen);
  refreshShowWhen();
})();
/* 复制按钮上携带的文本（恢复码、密钥等） */
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-copy-text]') : null;
  if (!b) { return; }
  var t = b.getAttribute('data-copy-text'), old = b.innerHTML;
  var done = function () { b.innerHTML = '已复制'; setTimeout(function () { b.innerHTML = old; }, 1400); };
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
  var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} document.body.removeChild(ta);
});

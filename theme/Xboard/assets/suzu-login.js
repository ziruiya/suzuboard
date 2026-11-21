/* Suzu VPN login page redesign v1.0 — DOM enhancer (theme layer only).
   On the #/login route it: tags <html> with .suzu-login (styles live in
   suzu-login.css), moves the existing logo block above the card, and inserts
   plain foreign nodes (brand texts, form head, field labels, register line)
   around the stock Vue form. It never touches Vue-managed nodes' behaviour:
   all links are plain hash anchors cloned from the stock routes, inputs and
   the submit button stay 100% stock. On any other route it removes its
   nodes and moves the logo block back into the card. Idempotent; a
   MutationObserver re-applies after SPA re-renders. */
(function () {
  if (window.__suzuLoginRedesign) return;
  window.__suzuLoginRedesign = true;

  function isLogin() { return /^#\/login(\?.*)?$/.test(location.hash || ""); }

  function visibleWrap(content, type) {
    var wraps = content.querySelectorAll(":scope > div.mt-5");
    for (var i = 0; i < wraps.length; i++) {
      var input = wraps[i].querySelector('input[type="' + type + '"]');
      if (input && getComputedStyle(wraps[i]).display !== "none") return wraps[i];
    }
    return null;
  }

  function mk(tag, cls, html) {
    var e = document.createElement(tag);
    e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function enhance() {
    var card = document.querySelector(".n-card");
    if (!card) return;
    var content = card.querySelector(".n-card__content .p-6");
    if (!content) return;
    var outer = card.parentElement;
    if (!outer) return;

    /* brand: move the stock logo block above the card, add brand texts.
       The logo block is the DIV.text-center that contains the <img>; the
       muted <h5> also carries a text-center class and must never match. */
    var logoWrap = content.querySelector("div.text-center");
    if (logoWrap && !logoWrap.querySelector("img")) logoWrap = null;
    if (logoWrap && outer) {
      logoWrap.classList.add("suzu-logo-wrap");
      outer.insertBefore(logoWrap, card);
      if (!outer.querySelector(".suzu-brand")) {
        var brand = mk("div", "suzu-brand suzu-ins",
          '<div class="suzu-brand-title">Suzu VPN</div><div class="suzu-brand-sub">安全连接 · 轻松管理</div>');
        outer.insertBefore(brand, card);
      }
    }

    /* form head */
    var emailWrap = visibleWrap(content, "email");
    if (emailWrap && !content.querySelector(".suzu-form-head")) {
      var head = mk("div", "suzu-form-head suzu-ins",
        '<div class="suzu-form-title">欢迎回来</div><p class="suzu-form-desc">登录您的账号，管理订阅与连接服务。</p>');
      emailWrap.parentElement.insertBefore(head, emailWrap);
    }

    /* email label */
    if (emailWrap && !emailWrap.querySelector(".suzu-field-label")) {
      emailWrap.insertBefore(mk("div", "suzu-field-label suzu-ins", "邮箱"), emailWrap.firstChild);
    }

    /* password label row with forgot-password link */
    var pwWrap = visibleWrap(content, "password");
    if (pwWrap && !pwWrap.querySelector(".suzu-field-label-row")) {
      var row = mk("div", "suzu-field-label-row suzu-ins",
        '<span class="suzu-field-label">密码</span><a href="#/forgetpassword">忘记密码？</a>');
      pwWrap.insertBefore(row, pwWrap.firstChild);
    }

    /* register line under the submit button */
    var btn = content.querySelector("button.n-button--primary-type.h-9");
    var btnWrap = btn ? btn.closest("div.mt-5") : null;
    if (btnWrap && !content.querySelector(".suzu-register-line")) {
      var rl = mk("div", "suzu-register-line suzu-ins",
        '还没有账号？<a href="#/register">立即注册</a>');
      btnWrap.parentElement.insertBefore(rl, btnWrap.nextSibling);
    }
  }

  function cleanup() {
    var nodes = document.querySelectorAll(".suzu-ins");
    for (var i = 0; i < nodes.length; i++) nodes[i].remove();
    var logoWrap = document.querySelector(".suzu-logo-wrap");
    if (logoWrap) {
      var content = document.querySelector(".n-card__content .p-6");
      if (content && logoWrap.parentElement !== content) {
        content.insertBefore(logoWrap, content.firstChild);
      }
      logoWrap.classList.remove("suzu-logo-wrap");
    }
  }

  var lastState = null;
  function apply() {
    var on = isLogin();
    document.documentElement.classList.toggle("suzu-login", on);
    if (on) enhance(); else if (lastState !== false) cleanup();
    lastState = on;
  }

  window.addEventListener("hashchange", function () { setTimeout(apply, 80); });
  var timer = null;
  var mo = new MutationObserver(function () {
    clearTimeout(timer);
    timer = setTimeout(apply, 150);
  });
  function boot() {
    apply();
    if (document.body) mo.observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();

/* Enterprise lines and business fulfillment for the Xboard member center. */
(function () {
  if (window.__suzuBusiness || !window.fetch) return;
  window.__suzuBusiness = true;

  var origFetch = window.fetch.bind(window);
  var tokenKey = 'VUE_NAIVE_ACCESS_TOKEN';
  var authHeader = null;
  var route = null;
  var request = null;
  var linesData = null;
  var statusData = null;
  var catalogData = null;
  var selectedRegion = null;
  var message = '';
  var submitting = false;

  function sessionToken() {
    try {
      var session = JSON.parse(localStorage.getItem(tokenKey));
      return session && (!session.expire || session.expire > Date.now()) ? session.value : null;
    } catch (e) {
      return null;
    }
  }

  function isCheckout(path) {
    return /^\/plan(?:\/|$)/.test(path);
  }

  function checkoutBody(url, body) {
    try {
      var target = new URL(url, location.href);
      if (target.origin !== location.origin || target.pathname !== '/api/v1/user/order/save'
          || sessionToken() !== authHeader || !selectedRegion || !catalogData || typeof body !== 'string') return body;
      var order = JSON.parse(body);
      var machine = (catalogData.plans || []).some(function (plan) { return Number(plan.id) === Number(order.plan_id); });
      if (machine && !order.machine_region) {
        order.machine_region = selectedRegion;
        return JSON.stringify(order);
      }
    } catch (e) { /* Preserve the original checkout request. */ }
    return body;
  }

  window.fetch = function (input, init) {
    if (init) {
      var body = checkoutBody(input.url || input, init.body);
      if (body !== init.body) init = Object.assign({}, init, { body: body });
    }
    return origFetch(input, init);
  };
  if (window.XMLHttpRequest) {
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;
    var requestUrls = new WeakMap();
    XMLHttpRequest.prototype.open = function (method, url) {
      requestUrls.set(this, url);
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
      return send.call(this, checkoutBody(requestUrls.get(this), body));
    };
  }

  function api(path, context, data) {
    var opts = { signal: context.signal, headers: { Authorization: authHeader } };
    if (data) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
    return origFetch('/api/v1' + path, opts).then(function (response) { return response.json(); });
  }

  function fmtBytes(n) {
    if (n == null) return '-';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0, v = Number(n);
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return v.toFixed(v >= 100 ? 0 : 1) + ' ' + units[i];
  }
  function fmtTime(ts) {
    if (!ts) return '-';
    var d = new Date(Number(ts) * 1000);
    return isNaN(d.getTime()) ? '-' : d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function renderLines() {
    if (!linesData || !linesData.has_enterprise) return;
    if (document.getElementById('suzu-ent-card')) return;
    var app = document.getElementById('app');
    if (!app) return;
    var d = linesData;
    var nodeLine = function (label, n) {
      if (!n) return '';
      return '<div class="suzu-ent-row"><span class="suzu-ent-label">' + label + '</span><span>' + esc(n.name || '') + '</span><span class="suzu-ent-addr">' + esc(n.entry_address || '') + ':' + esc(n.entry_port || '') + '</span></div>';
    };
    var card = document.createElement('div');
    card.id = 'suzu-ent-card';
    card.className = 'suzu-ent-card';
    card.innerHTML =
      '<div class="suzu-ent-head"><span class="suzu-ent-title">我的企业专属线路</span><span class="suzu-ent-plan">' + esc(d.plan_name || '') + '</span></div>' +
      '<div class="suzu-ent-row"><span class="suzu-ent-label">地区</span><span>' + esc(d.region_name || '') + '</span></div>' +
      '<div class="suzu-ent-row"><span class="suzu-ent-label">用途</span><span>' + esc(d.usage_name || d.purpose_name || '') + '</span></div>' +
      nodeLine('主线', d.primary) +
      nodeLine('备线', d.backup) +
      '<div class="suzu-ent-row"><span class="suzu-ent-label">到期时间</span><span>' + esc(fmtTime(d.expired_at)) + '</span></div>' +
      '<div class="suzu-ent-row"><span class="suzu-ent-label">流量</span><span>剩余 ' + esc(fmtBytes(d.transfer_remaining)) + ' / 共 ' + esc(fmtBytes(d.transfer_enable)) + '</span></div>' +
      '<div class="suzu-ent-row"><span class="suzu-ent-label">设备数</span><span>' + (d.device_limit == null ? '不限' : esc(d.device_limit)) + '</span></div>';
    app.insertBefore(card, app.firstChild);
  }

  function renderBusiness() {
    var app = document.getElementById('app');
    if (!app) return;

    // Region picker: only while a machine plan is actually on sale.
    if (isCheckout(route) && catalogData && !document.getElementById('suzu-biz-region')) {
      var onSale = (catalogData.plans || []).filter(function (p) { return p.on_sale; });
      if (onSale.length) {
        var picker = document.createElement('div');
        picker.id = 'suzu-biz-region';
        picker.className = 'suzu-ent-card';
        var options = (catalogData.regions || []).map(function (r) {
          return '<option value="' + esc(r.code) + '">' + esc(r.name) + '</option>';
        }).join('');
        picker.innerHTML =
          '<div class="suzu-ent-head"><span class="suzu-ent-title">独享整机 · 选择地区</span></div>' +
          '<div class="suzu-ent-row"><span class="suzu-ent-label">地区</span>' +
          '<select id="suzu-biz-region-select">' + options + '</select>' +
          '<span class="suzu-ent-plan">购买独享整机时将按此地区采购部署，整台机器归你一人独享</span></div>';
        app.insertBefore(picker, app.firstChild);
        var sel = document.getElementById('suzu-biz-region-select');
        sel.value = selectedRegion || sel.value;
        selectedRegion = sel.value;
        sel.addEventListener('change', function () { selectedRegion = sel.value; });
      }
    }

    if (route !== '/dashboard' || !statusData || (!statusData.has_business && !statusData.machine)) return;
    if (document.getElementById('suzu-biz-card')) return;
    var d = statusData;
    var card = document.createElement('div');
    card.id = 'suzu-biz-card';
    card.className = 'suzu-ent-card';
    var html =
      '<div class="suzu-ent-head"><span class="suzu-ent-title">我的业务套餐</span>' +
      '<span class="suzu-ent-plan">' + (d.tier === 'machine' || (!d.has_business && d.machine) ? '独享整机' : '独享端口') + '</span></div>';
    if (d.machine) {
      html += '<div class="suzu-ent-row"><span class="suzu-ent-label">整机</span><span>' +
        esc(d.machine.region_name || '') + ' · ' + esc(d.machine.status || '') + '</span></div>';
    }
    if (d.has_business && d.can_rotate) {
      html += '<div class="suzu-ent-row"><button type="button" class="suzu-biz-btn" id="suzu-biz-rotate">更换端口</button>' +
        '<span class="suzu-ent-plan">更换入口与落地端口，凭据与订阅地址不变</span></div>';
    } else if (d.has_business && d.cooldown_seconds > 0) {
      html += '<div class="suzu-ent-row"><button type="button" class="suzu-biz-btn" disabled>更换端口</button>' +
        '<span class="suzu-ent-plan">冷却中，约 ' + Math.ceil(d.cooldown_seconds / 60) + ' 分钟后可再次更换（每 2 小时一次）</span></div>';
    }
    html += '<div class="suzu-ent-row suzu-biz-msg" id="suzu-biz-msg"></div>';
    if (d.machine && d.machine.awaiting_choice) {
      var regions = (catalogData && catalogData.regions || []).map(function (r) {
        return '<option value="' + esc(r.code) + '">' + esc(r.name) + '</option>';
      }).join('');
      html += '<div class="suzu-ent-row"><span class="suzu-ent-label">部署超时</span><span>已超过 3 小时交付目标，请选择：</span></div>' +
        '<div class="suzu-ent-row"><button type="button" class="suzu-biz-btn" id="suzu-biz-wait">继续等待原地区（最长再等 24 小时）</button></div>' +
        '<div class="suzu-ent-row"><select id="suzu-biz-switch-region">' + regions + '</select>' +
        '<button type="button" class="suzu-biz-btn" id="suzu-biz-switch"' + (regions ? '' : ' disabled') + '>免费改选该地区</button></div>';
    }
    card.innerHTML = html;
    app.insertBefore(card, app.firstChild);

    card.querySelector('#suzu-biz-msg').textContent = message;
    var rotate = card.querySelector('#suzu-biz-rotate');
    if (rotate) rotate.addEventListener('click', function () {
      submit('/user/business/rotate-port', {}, '端口已更换，订阅地址不变，客户端更新订阅即可。');
    });
    var wait = card.querySelector('#suzu-biz-wait');
    if (wait) wait.addEventListener('click', function () {
      submit('/user/business/choice', { fulfillment_id: d.machine.fulfillment_id, choice: 'WAIT_ORIGINAL' }, '已记录：继续等待原地区部署。');
    });
    var changeRegion = card.querySelector('#suzu-biz-switch');
    if (changeRegion) changeRegion.addEventListener('click', function () {
      var region = card.querySelector('#suzu-biz-switch-region').value;
      submit('/user/business/choice', { fulfillment_id: d.machine.fulfillment_id, choice: 'SWITCH_REGION', region: region }, '已改选地区，重新进入部署流程。');
    });
  }

  function removeCards() {
    ['suzu-ent-card', 'suzu-biz-card', 'suzu-biz-region'].forEach(function (id) {
      var card = document.getElementById(id);
      if (card) card.remove();
    });
  }

  function current(context) {
    return request === context && authHeader === sessionToken() && route === currentRoute();
  }

  function load() {
    if (request) request.abort();
    var context = new AbortController();
    request = context;
    var catalog = api('/user/business/machine-regions', context).catch(function () { return null; });
    var lines = route === '/dashboard' ? api('/user/enterprise/lines', context).catch(function () { return null; }) : null;
    var status = route === '/dashboard' ? api('/user/business/status', context).catch(function () { return null; }) : null;
    Promise.all([catalog, lines, status]).then(function (results) {
      if (!current(context)) return;
      catalogData = results[0] && results[0].status === 'success' ? results[0].data : null;
      linesData = results[1] && results[1].status === 'success' ? results[1].data : null;
      statusData = results[2] && results[2].status === 'success' ? results[2].data : null;
      renderLines();
      renderBusiness();
    });
  }

  function submit(path, data, successMessage) {
    var context = request;
    if (!context || !current(context) || submitting) return;
    submitting = true;
    var card = document.getElementById('suzu-biz-card');
    var feedback = card.querySelector('#suzu-biz-msg');
    feedback.textContent = '正在提交…';
    api(path, context, data).then(function (result) {
      if (!current(context)) return;
      if (result && result.status === 'success') {
        message = successMessage;
        linesData = statusData = null;
        removeCards();
        submitting = false;
        load();
      } else {
        feedback.textContent = (result && result.message) || '提交失败';
      }
    }).catch(function () {
      if (current(context)) feedback.textContent = '提交失败，请稍后再试';
    }).finally(function () {
      if (context === request) submitting = false;
    });
  }

  function currentRoute() {
    return (location.hash.slice(1) || location.pathname).split('?')[0];
  }

  function sync() {
    var nextRoute = currentRoute();
    var nextAuth = sessionToken();
    if (route !== nextRoute || authHeader !== nextAuth) {
      if (request) request.abort();
      request = null;
      if (nextAuth !== authHeader || !nextAuth || !isCheckout(nextRoute)) selectedRegion = null;
      route = nextRoute;
      authHeader = nextAuth;
      linesData = statusData = catalogData = null;
      submitting = false;
      message = '';
      removeCards();
      if (authHeader && (route === '/dashboard' || isCheckout(route))) load();
    }
    renderLines();
    renderBusiness();
  }

  function start() {
    new MutationObserver(sync).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('hashchange', sync);
    window.addEventListener('popstate', sync);
    window.addEventListener('storage', sync);
    sync();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

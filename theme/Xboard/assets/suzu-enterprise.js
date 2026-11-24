/* SB-ENT-INV-01 member center bolt-on: shows the customer's own enterprise
   dedicated lines ("我的企业专属线路") on the dashboard. Theme-layer only:
   it reuses the Authorization header the stock SPA itself sends (captured
   by wrapping fetch/XHR), calls GET /api/v1/user/enterprise/lines and
   injects a plain card above the app content when the account owns a kit.
   No secrets are rendered — the API returns entry address/port, expiry,
   traffic and device allowance only. Idempotent; a MutationObserver
   re-injects after SPA re-renders. */
(function () {
  if (window.__suzuEnterpriseLines) return;
  window.__suzuEnterpriseLines = true;

  var authHeader = null;
  var linesData = null;
  var fetching = false;

  function captureAuth(headers) {
    try {
      if (!headers) return;
      if (typeof headers.get === 'function') {
        var v = headers.get('Authorization') || headers.get('authorization');
        if (v) authHeader = v;
      } else if (Array.isArray(headers)) {
        headers.forEach(function (h) { if (h && String(h[0]).toLowerCase() === 'authorization') authHeader = h[1]; });
      } else if (typeof headers === 'object') {
        Object.keys(headers).forEach(function (k) { if (k.toLowerCase() === 'authorization') authHeader = headers[k]; });
      }
    } catch (e) { /* ignore */ }
  }

  var origFetch = window.fetch;
  if (origFetch) {
    window.fetch = function (input, init) {
      try {
        if (init && init.headers) captureAuth(init.headers);
        if (input && typeof input === 'object' && input.headers) captureAuth(input.headers);
      } catch (e) { /* ignore */ }
      return origFetch.apply(this, arguments);
    };
  }
  var origSetHeader = window.XMLHttpRequest && XMLHttpRequest.prototype.setRequestHeader;
  if (origSetHeader) {
    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
      if (String(name).toLowerCase() === 'authorization') authHeader = value;
      return origSetHeader.apply(this, arguments);
    };
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

  function render() {
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

  function load() {
    if (!authHeader || fetching || linesData) return;
    fetching = true;
    origFetch('/api/v1/user/enterprise/lines', { headers: { 'Authorization': authHeader } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.status === 'success') { linesData = j.data; render(); }
      })
      .catch(function () { /* stay silent; card simply does not appear */ })
      .finally(function () { fetching = false; });
  }

  var mo = new MutationObserver(function () { load(); render(); });
  function start() {
    if (document.body) mo.observe(document.body, { childList: true, subtree: true });
    load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

/* Business plans batch 3 bolt-on: 「更换端口」 for both business tiers,
   the SLA-overdue choice for dedicated-VPS customers, and the region
   picker for the dedicated-VPS checkout. Same theme-layer pattern as the
   enterprise lines card above: reuses the SPA's Authorization header,
   renders no secrets, stays silent for non-business accounts. */
(function () {
  if (window.__suzuBusiness) return;
  window.__suzuBusiness = true;

  var authHeader = null;
  var statusData = null;
  var catalogData = null;
  var selectedRegion = null;
  var fetching = false;

  function captureAuth(headers) {
    try {
      if (!headers) return;
      if (typeof headers.get === 'function') {
        var v = headers.get('Authorization') || headers.get('authorization');
        if (v) authHeader = v;
      } else if (Array.isArray(headers)) {
        headers.forEach(function (h) { if (h && String(h[0]).toLowerCase() === 'authorization') authHeader = h[1]; });
      } else if (typeof headers === 'object') {
        Object.keys(headers).forEach(function (k) { if (k.toLowerCase() === 'authorization') authHeader = headers[k]; });
      }
    } catch (e) { /* ignore */ }
  }

  // Wrap fetch: capture auth + attach the chosen region to machine-plan
  // order saves that do not carry one yet (backend enforces regardless).
  var origFetch = window.fetch;
  if (origFetch) {
    window.fetch = function (input, init) {
      try {
        if (init && init.headers) captureAuth(init.headers);
        if (input && typeof input === 'object' && input.headers) captureAuth(input.headers);
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        if (selectedRegion && catalogData && /\/user\/order\/save/.test(url) && init && typeof init.body === 'string') {
          var body = JSON.parse(init.body);
          var isMachinePlan = (catalogData.plans || []).some(function (p) { return Number(p.id) === Number(body.plan_id); });
          if (isMachinePlan && !body.machine_region) {
            body.machine_region = selectedRegion;
            init = Object.assign({}, init, { body: JSON.stringify(body) });
            arguments[1] = init;
          }
        }
      } catch (e) { /* ignore */ }
      return origFetch.apply(this, arguments);
    };
  }
  var origSetHeader = window.XMLHttpRequest && XMLHttpRequest.prototype.setRequestHeader;
  if (origSetHeader) {
    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
      if (String(name).toLowerCase() === 'authorization') authHeader = value;
      return origSetHeader.apply(this, arguments);
    };
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'Authorization': authHeader, 'Content-Type': 'application/json' }, opts.headers || {});
    return origFetch('/api/v1' + path, opts).then(function (r) { return r.json(); });
  }

  function render() {
    var app = document.getElementById('app');
    if (!app) return;

    // Region picker: only while a machine plan is actually on sale.
    if (catalogData && !document.getElementById('suzu-biz-region')) {
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
        selectedRegion = sel.value;
        sel.addEventListener('change', function () { selectedRegion = sel.value; });
      }
    }

    if (!statusData || !statusData.has_business) return;
    if (document.getElementById('suzu-biz-card')) return;
    var d = statusData;
    var card = document.createElement('div');
    card.id = 'suzu-biz-card';
    card.className = 'suzu-ent-card';
    var html =
      '<div class="suzu-ent-head"><span class="suzu-ent-title">我的业务套餐</span>' +
      '<span class="suzu-ent-plan">' + (d.tier === 'machine' ? '独享整机' : '独享端口') + '</span></div>';
    if (d.machine) {
      html += '<div class="suzu-ent-row"><span class="suzu-ent-label">整机</span><span>' +
        esc(d.machine.region_name || '') + ' · ' + esc(d.machine.status || '') + '</span></div>';
    }
    if (d.can_rotate) {
      html += '<div class="suzu-ent-row"><button type="button" class="suzu-biz-btn" id="suzu-biz-rotate">更换端口</button>' +
        '<span class="suzu-ent-plan">更换入口与落地端口，凭据与订阅地址不变</span></div>';
    } else if (d.cooldown_seconds > 0) {
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
        '<button type="button" class="suzu-biz-btn" id="suzu-biz-switch">免费改选该地区</button></div>';
    }
    card.innerHTML = html;
    app.insertBefore(card, app.firstChild);

    var msg = function (t) { var el = document.getElementById('suzu-biz-msg'); if (el) el.textContent = t; };
    var rotateBtn = document.getElementById('suzu-biz-rotate');
    if (rotateBtn) rotateBtn.addEventListener('click', function () {
      rotateBtn.disabled = true;
      msg('正在更换端口…');
      api('/user/business/rotate-port', { method: 'POST', body: '{}' })
        .then(function (j) {
          if (j && j.status === 'success') { msg('端口已更换，订阅地址不变，客户端更新订阅即可。'); statusData = null; load(); }
          else { msg((j && j.message) || '更换失败'); rotateBtn.disabled = false; }
        })
        .catch(function () { msg('更换失败，请稍后再试'); rotateBtn.disabled = false; });
    });
    var waitBtn = document.getElementById('suzu-biz-wait');
    if (waitBtn) waitBtn.addEventListener('click', function () {
      api('/user/business/choice', { method: 'POST', body: JSON.stringify({ fulfillment_id: d.machine.fulfillment_id, choice: 'WAIT_ORIGINAL' }) })
        .then(function (j) { msg(j && j.status === 'success' ? '已记录：继续等待原地区部署。' : ((j && j.message) || '提交失败')); statusData = null; load(); })
        .catch(function () { msg('提交失败，请稍后再试'); });
    });
    var switchBtn = document.getElementById('suzu-biz-switch');
    if (switchBtn) switchBtn.addEventListener('click', function () {
      var region = document.getElementById('suzu-biz-switch-region').value;
      api('/user/business/choice', { method: 'POST', body: JSON.stringify({ fulfillment_id: d.machine.fulfillment_id, choice: 'SWITCH_REGION', region: region }) })
        .then(function (j) { msg(j && j.status === 'success' ? '已改选地区，重新进入部署流程。' : ((j && j.message) || '提交失败')); statusData = null; load(); })
        .catch(function () { msg('提交失败，请稍后再试'); });
    });
  }

  function load() {
    if (!authHeader || fetching) return;
    fetching = true;
    Promise.all([
      api('/user/business/status').catch(function () { return null; }),
      api('/user/business/machine-regions').catch(function () { return null; })
    ]).then(function (res) {
      if (res[0] && res[0].status === 'success') statusData = res[0].data;
      if (res[1] && res[1].status === 'success') catalogData = res[1].data;
      render();
    }).finally(function () { fetching = false; });
  }

  var mo = new MutationObserver(function () { load(); render(); });
  function start() {
    if (document.body) mo.observe(document.body, { childList: true, subtree: true });
    load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

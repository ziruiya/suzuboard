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

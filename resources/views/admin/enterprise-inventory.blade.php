{{-- SB-ENT-INV-01 admin page: enterprise inventory kits / rules / alerts.
     Standalone Blade page (same pattern as the admin shell): talks to the
     V2 admin API under /{secure_path}/suzu/enterprise with the admin token
     from localStorage. --}}
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>企业库存 · {{ $title ?? 'XBoard' }}</title>
<style>
  body { font-family: -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; margin: 24px; color: #1f2430; background: #f6f7f9; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
  .bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center; }
  select, input, button { font-size: 13px; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; }
  button { cursor: pointer; }
  button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 24px; }
  th, td { text-align: left; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
  th { background: #f3f4f6; font-weight: 600; }
  .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; background: #e5e7eb; }
  .badge.ready { background: #dcfce7; } .badge.assigned, .badge.assigned_legacy { background: #dbeafe; }
  .badge.quarantined { background: #fee2e2; } .badge.held, .badge.reserved, .badge.grace_held { background: #fef9c3; }
  .muted { color: #6b7280; } .err { color: #b91c1c; }
  section h2 { font-size: 16px; margin: 20px 0 8px; }
</style>
</head>
<body>
<h1>企业专属线路库存</h1>
<div class="sub">SB-ENT-INV-01 · 每 SKU 目标库存 {{ 3 }} 套（主线+备线）· 分配后自动补库 · 库存节点对普通用户不可见</div>

<div class="bar">
  <label>SKU
    <select id="f-purpose"><option value="">全部</option></select>
  </label>
  <label>状态
    <select id="f-status">
      <option value="">全部</option>
      <option>BUILDING</option><option>VERIFYING</option><option>READY</option>
      <option>HELD</option><option>RESERVED</option><option>ASSIGNED</option>
      <option>ASSIGNED_LEGACY</option><option>GRACE_HELD</option><option>QUARANTINED</option>
    </select>
  </label>
  <button id="btn-refresh" class="primary">刷新</button>
  <button id="btn-build">手动补一套</button>
  <span id="msg" class="muted"></span>
</div>

<table>
  <thead><tr>
    <th>SKU</th><th>套件</th><th>状态</th><th>主线 / 备线</th><th>DNS 别名</th>
    <th>当前用户 / 订单</th><th>最近探测</th><th>失败次数</th><th>创建时间</th><th>操作</th>
  </tr></thead>
  <tbody id="kits-body"><tr><td colspan="10" class="muted">加载中…</td></tr></tbody>
</table>

<section>
  <h2>待处理告警</h2>
  <table>
    <thead><tr><th>时间</th><th>类型</th><th>SKU</th><th>套件</th><th>内容</th><th>状态</th><th>操作</th></tr></thead>
    <tbody id="alerts-body"><tr><td colspan="7" class="muted">加载中…</td></tr></tbody>
  </table>
</section>

<section>
  <h2>等待库存的已付订单</h2>
  <table>
    <thead><tr><th>时间</th><th>用户</th><th>订单</th><th>SKU</th><th>等待开始</th></tr></thead>
    <tbody id="intents-body"><tr><td colspan="5" class="muted">加载中…</td></tr></tbody>
  </table>
</section>

<script>
(function () {
  var base = '/' + @json($secure_path) + '/suzu/enterprise';
  var token = localStorage.getItem('access_token') || '';
  var purposes = [];

  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'Authorization': token, 'Content-Type': 'application/json' }, opts.headers || {});
    return fetch(base + path, opts).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.status === 'success') return j.data;
      throw new Error((j && j.message) || 'request failed');
    });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function msg(t, isErr) { var el = document.getElementById('msg'); el.textContent = t || ''; el.className = isErr ? 'err' : 'muted'; }
  function nodeText(n) { return n ? (esc(n.name || ('#' + n.server_id)) + ' · ' + esc(n.port)) : '-'; }

  function loadPurposes() {
    return api('/purposes').then(function (list) {
      purposes = list || [];
      var sel = document.getElementById('f-purpose');
      purposes.forEach(function (p) {
        var o = document.createElement('option');
        o.value = p.id; o.textContent = p.name + '（库存 ' + p.current_level + '/' + p.target_level + ' · 可用规则 ' + p.ready_rules + '）';
        sel.appendChild(o);
      });
    });
  }

  function loadKits() {
    var q = [];
    var pid = document.getElementById('f-purpose').value;
    var st = document.getElementById('f-status').value;
    if (pid) q.push('purpose_id=' + encodeURIComponent(pid));
    if (st) q.push('status=' + encodeURIComponent(st));
    return api('/kits' + (q.length ? '?' + q.join('&') : '')).then(function (list) {
      var tb = document.getElementById('kits-body');
      if (!list || !list.length) { tb.innerHTML = '<tr><td colspan="10" class="muted">暂无套件</td></tr>'; return; }
      tb.innerHTML = list.map(function (k) {
        var byRole = {}; (k.nodes || []).forEach(function (n) { byRole[n.role] = n; });
        var acts = '';
        if (k.status === 'QUARANTINED') acts += '<button data-act="rebuild" data-id="' + k.id + '">重建</button> ';
        if (k.status === 'ASSIGNED' || k.status === 'ASSIGNED_LEGACY') acts += '<button data-act="revoke" data-id="' + k.id + '">撤销分配</button> ';
        acts += '<button data-act="verify" data-id="' + k.id + '">全量验收</button>';
        return '<tr><td>' + esc(k.purpose_name) + '</td><td>#' + k.id + '<br><span class="muted">' + esc(k.group_name || '') + '</span></td>' +
          '<td><span class="badge ' + esc(String(k.status).toLowerCase()) + '">' + esc(k.status) + '</span></td>' +
          '<td>主：' + nodeText(byRole.primary) + '<br>备：' + nodeText(byRole.backup) + '</td>' +
          '<td>' + esc(k.dns_alias || '-') + '</td>' +
          '<td>' + (k.current_user_id ? ('用户 ' + k.current_user_id + '<br>订单 ' + (k.current_order_id || '-')) : '<span class="muted">-</span>') + '</td>' +
          '<td>' + esc(k.last_probe_at || '-') + '</td><td>' + k.health_fail_count + '</td><td>' + esc(k.created_at || '-') + '</td>' +
          '<td>' + acts + '</td></tr>';
      }).join('');
    });
  }

  function loadAlerts() {
    return api('/alerts?status=open').then(function (list) {
      var tb = document.getElementById('alerts-body');
      if (!list || !list.length) { tb.innerHTML = '<tr><td colspan="7" class="muted">无待处理告警</td></tr>'; return; }
      tb.innerHTML = list.map(function (a) {
        return '<tr><td>' + esc(a.created_at) + '</td><td>' + esc(a.alert_type) + '</td><td>' + esc(a.purpose_id || '-') + '</td><td>' + esc(a.kit_id || '-') + '</td><td>' + esc(a.message) + '</td><td>' + esc(a.status) + '</td>' +
          '<td><button data-act="ack" data-id="' + a.id + '">确认</button> <button data-act="resolve" data-id="' + a.id + '">已处理</button></td></tr>';
      }).join('');
    });
  }

  function loadIntents() {
    return api('/intents?status=PAID_WAITING_INVENTORY').then(function (list) {
      var tb = document.getElementById('intents-body');
      if (!list || !list.length) { tb.innerHTML = '<tr><td colspan="5" class="muted">无等待订单</td></tr>'; return; }
      tb.innerHTML = list.map(function (i) {
        return '<tr><td>' + esc(i.created_at) + '</td><td>' + esc(i.user ? i.user.email : i.user_id) + '</td><td>' + esc(i.order_id || '-') + '</td><td>' + esc(i.purpose ? i.purpose.name : i.purpose_id) + '</td><td>' + esc(i.waiting_since || '-') + '</td></tr>';
      }).join('');
    });
  }

  function refresh() {
    msg('加载中…');
    Promise.all([loadKits(), loadAlerts(), loadIntents()]).then(function () { msg(''); }).catch(function (e) { msg(e.message, true); });
  }

  document.getElementById('btn-refresh').addEventListener('click', refresh);
  document.getElementById('f-purpose').addEventListener('change', loadKits);
  document.getElementById('f-status').addEventListener('change', loadKits);
  document.getElementById('btn-build').addEventListener('click', function () {
    var pid = document.getElementById('f-purpose').value;
    if (!pid) { msg('请先选择 SKU', true); return; }
    if (!confirm('为该 SKU 手动补建一套库存？')) return;
    api('/kits/build', { method: 'POST', body: JSON.stringify({ purpose_id: Number(pid), confirm: true }) })
      .then(function () { msg('已触发补库'); refresh(); }).catch(function (e) { msg(e.message, true); });
  });
  document.body.addEventListener('click', function (ev) {
    var btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.getAttribute('data-act'), id = Number(btn.getAttribute('data-id'));
    if (act === 'ack' || act === 'resolve') {
      api('/alerts/ack', { method: 'POST', body: JSON.stringify({ id: id, resolve: act === 'resolve', confirm: true }) }).then(refresh).catch(function (e) { msg(e.message, true); });
      return;
    }
    if (act === 'verify') {
      api('/kits/verify', { method: 'POST', body: JSON.stringify({ kit_id: id, mode: 'full', confirm: true }) }).then(function (r) { msg(r && r.ok ? '验收通过' : '验收未通过', !(r && r.ok)); refresh(); }).catch(function (e) { msg(e.message, true); });
      return;
    }
    if (act === 'rebuild' && confirm('重建该套件？旧节点将停用、规则作废，重新领取一对规则。')) {
      api('/kits/rebuild', { method: 'POST', body: JSON.stringify({ kit_id: id, confirm: true }) }).then(refresh).catch(function (e) { msg(e.message, true); });
      return;
    }
    if (act === 'revoke' && confirm('撤销该套件的客户分配？客户将立即失去专属线路与 VIP 组访问。')) {
      api('/kits/revoke', { method: 'POST', body: JSON.stringify({ kit_id: id, confirm: true }) }).then(refresh).catch(function (e) { msg(e.message, true); });
    }
  });

  loadPurposes().then(refresh).catch(function (e) { msg(e.message, true); });
})();
</script>
</body>
</html>

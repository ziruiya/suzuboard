{{-- SB-RELAY-01 admin page: 中转管理 — relay machines, rules, config
     versions, audit. Standalone Blade page (same pattern as the enterprise
     inventory page): talks to the V2 admin API under
     /{secure_path}/suzu/relay with the admin token from localStorage. --}}
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>中转管理 · {{ $title ?? 'XBoard' }}</title>
<style>
  body { font-family: -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; margin: 24px; color: #1f2430; background: #f6f7f9; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
  .bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center; }
  select, input, button { font-size: 13px; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; }
  button { cursor: pointer; }
  button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
  button.danger { background: #b91c1c; color: #fff; border-color: #b91c1c; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 24px; }
  th, td { text-align: left; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
  th { background: #f3f4f6; font-weight: 600; }
  .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; background: #e5e7eb; }
  .badge.applied, .badge.enabled { background: #dcfce7; }
  .badge.broken, .badge.down, .badge.conflict { background: #fee2e2; }
  .badge.applying, .badge.draining, .badge.suspect, .badge.unknown { background: #fef9c3; }
  .muted { color: #6b7280; } .err { color: #b91c1c; }
  section h2 { font-size: 16px; margin: 20px 0 8px; }
  .form { background: #fff; border-radius: 8px; padding: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 24px; }
  .form label { display: inline-flex; flex-direction: column; gap: 4px; font-size: 12px; color: #374151; margin: 0 10px 10px 0; }
</style>
</head>
<body>
<h1>中转管理</h1>
<div class="sub">SB-RELAY-01 · 站内原生中转（gost 融合）· 端口池 20000–28999 · 配置通道 relay.config.v1（Outbox + 全量拉取 + 应用回执）</div>

<div class="bar">
  <button id="btn-refresh" class="primary">刷新</button>
  <span id="msg" class="muted"></span>
</div>

<section>
  <h2>机器</h2>
  <table>
    <thead><tr>
      <th>机器</th><th>在线</th><th>中转状态</th><th>档位 / 上限</th><th>规则数</th>
      <th>配置版本（已发布 / 已应用）</th><th>今日流量</th><th>本月流量</th><th>最近错误</th><th>操作</th>
    </tr></thead>
    <tbody id="machines-body"><tr><td colspan="10" class="muted">加载中…</td></tr></tbody>
  </table>
</section>

<section>
  <h2>中转线路 <span id="rules-machine-name" class="muted"></span></h2>
  <table>
    <thead><tr>
      <th>编号</th><th>名称</th><th>协议</th><th>监听</th><th>目标</th><th>状态</th>
      <th>配置版本</th><th>今日 / 本月</th><th>最近错误</th><th>操作</th>
    </tr></thead>
    <tbody id="rules-body"><tr><td colspan="10" class="muted">先在上方选择一台机器</td></tr></tbody>
  </table>
</section>

<section>
  <h2>添加中转</h2>
  <div class="form">
    <label>名称 <input id="f-name" type="text" placeholder="如 VIP-香港入口"></label>
    <label>协议
      <select id="f-transport"><option value="both">TCP+UDP</option><option value="tcp">TCP</option><option value="udp">UDP</option></select>
    </label>
    <label>监听 IP <input id="f-listen-ip" type="text" value="0.0.0.0"></label>
    <label>监听端口（20000–28999）<input id="f-listen-port" type="number" min="20000" max="28999"></label>
    <label>目标方式
      <select id="f-target-type"><option value="NODE">选择节点（自动跟随）</option><option value="MANUAL">手动地址</option></select>
    </label>
    <label id="wrap-node">目标节点 <select id="f-node"></select></label>
    <label id="wrap-manual-host" style="display:none">目标主机 <input id="f-target-host" type="text" placeholder="IP 或域名"></label>
    <label id="wrap-manual-port" style="display:none">目标端口 <input id="f-target-port" type="number" min="1" max="65535"></label>
    <label id="wrap-allowlist" style="display:none">来源白名单 CIDR（逗号分隔，手动目标必填）
      <input id="f-allowlist" type="text" placeholder="0.0.0.0/0">
    </label>
    <label id="wrap-allowlist-ok" style="display:none">白名单已审批
      <input id="f-allowlist-approved" type="checkbox">
    </label>
    <div>
      <button id="btn-add" class="primary">添加中转</button>
      <span class="muted">端口合法性在提交时由服务端复检；手动目标走公网监听必须配白名单并审批。</span>
    </div>
  </div>
</section>

<section>
  <h2>配置版本（Outbox）</h2>
  <table>
    <thead><tr><th>版本</th><th>哈希</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
    <tbody id="outbox-body"><tr><td colspan="5" class="muted">—</td></tr></tbody>
  </table>
</section>

<section>
  <h2>审计日志</h2>
  <table>
    <thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>详情</th></tr></thead>
    <tbody id="audit-body"><tr><td colspan="4" class="muted">—</td></tr></tbody>
  </table>
</section>

<script>
(function () {
  var base = '/' + @json($secure_path) + '/suzu/relay';
  var token = localStorage.getItem('access_token') || '';
  var currentMachine = null;
  var machines = [];

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
  function fmtBytes(n) {
    n = Number(n || 0);
    if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }
  function badge(text) { return '<span class="badge ' + esc(String(text).toLowerCase()) + '">' + esc(text) + '</span>'; }

  function loadMachines() {
    return api('/machines').then(function (list) {
      machines = list || [];
      var body = document.getElementById('machines-body');
      if (!machines.length) { body.innerHTML = '<tr><td colspan="10" class="muted">暂无机器</td></tr>'; return; }
      body.innerHTML = machines.map(function (m) {
        var op = m.relay_status === 'DRAINING'
          ? '<span class="muted">排空…</span>'
          : (m.relay_enabled
              ? '<button data-act="disable" data-id="' + m.id + '" class="danger">取消中转机</button>'
              : '<button data-act="enable" data-id="' + m.id + '">设为中转机</button>');
        return '<tr data-mid="' + m.id + '" style="cursor:pointer">'
          + '<td>' + esc(m.name) + ' <span class="muted">#' + m.id + '</span></td>'
          + '<td>' + (m.last_seen_at ? new Date(m.last_seen_at * 1000).toLocaleString() : '<span class="muted">未见</span>') + '</td>'
          + '<td>' + badge(m.relay_status) + '</td>'
          + '<td>' + esc(m.relay_tier) + ' · 规则≤' + m.limits.rules + ' TCP≤' + m.limits.tcp + ' UDP≤' + m.limits.udp + '</td>'
          + '<td>' + m.rule_count + '</td>'
          + '<td>v' + m.config_version + ' / v' + m.applied_version + '</td>'
          + '<td>' + fmtBytes((m.traffic_today.u || 0) + (m.traffic_today.d || 0)) + '</td>'
          + '<td>' + fmtBytes((m.traffic_month.u || 0) + (m.traffic_month.d || 0)) + '</td>'
          + '<td class="err">' + esc(m.last_error || '') + '</td>'
          + '<td>' + op + ' <button data-act="rules" data-id="' + m.id + '">线路</button></td></tr>';
      }).join('');
      body.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function (ev) {
          ev.stopPropagation();
          var id = Number(b.getAttribute('data-id'));
          if (b.getAttribute('data-act') === 'rules') { selectMachine(id); return; }
          if (!confirm('确认' + (b.getAttribute('data-act') === 'enable' ? '设为' : '取消') + '中转机？')) return;
          api('/machines/toggle', { method: 'POST', body: JSON.stringify({ machine_id: id, action: b.getAttribute('data-act'), confirm: true }) })
            .then(function () { msg('已提交'); refresh(); }).catch(function (e) { msg(e.message, true); });
        });
      });
      body.querySelectorAll('tr').forEach(function (tr) {
        tr.addEventListener('click', function () { selectMachine(Number(tr.getAttribute('data-mid'))); });
      });
    });
  }

  function selectMachine(id) {
    currentMachine = id;
    var m = machines.filter(function (x) { return x.id === id; })[0];
    document.getElementById('rules-machine-name').textContent = m ? ('· ' + m.name) : '';
    return Promise.all([loadRules(), loadOutbox(), loadAudit(), loadNodes()]);
  }

  function loadRules() {
    if (!currentMachine) return Promise.resolve();
    return api('/rules?machine_id=' + currentMachine).then(function (list) {
      var body = document.getElementById('rules-body');
      if (!list.length) { body.innerHTML = '<tr><td colspan="10" class="muted">暂无线路</td></tr>'; return; }
      body.innerHTML = list.map(function (r) {
        var target = r.target_type === 'NODE'
          ? esc(r.target_node_name || ('节点 #' + r.target_node_id)) + ' <span class="muted">(' + esc(r.snapshot_host || r.target_host) + ':' + (r.snapshot_port || r.target_port) + ')</span>'
          : esc(r.target_host) + ':' + r.target_port;
        return '<tr><td>' + esc(r.rule_code) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.transport) + '</td>'
          + '<td>' + esc(r.listen_ip) + ':' + r.listen_port + '</td><td>' + target + '</td>'
          + '<td>' + badge(r.status) + (r.enabled ? '' : ' <span class="muted">已停用</span>') + '</td>'
          + '<td>v' + r.config_version + '</td>'
          + '<td>' + fmtBytes(r.traffic_today.u + r.traffic_today.d) + ' / ' + fmtBytes(r.traffic_month.u + r.traffic_month.d) + '</td>'
          + '<td class="err">' + esc(r.last_error || '') + '</td>'
          + '<td><button data-act="' + (r.enabled ? 'off' : 'on') + '" data-id="' + r.id + '">' + (r.enabled ? '停用' : '启用') + '</button> '
          + '<button data-act="drop" data-id="' + r.id + '" class="danger">删除</button></td></tr>';
      }).join('');
      body.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = Number(b.getAttribute('data-id')), act = b.getAttribute('data-act');
          if (act === 'drop') {
            if (!confirm('确认删除这条中转线路？')) return;
            api('/rules/drop', { method: 'POST', body: JSON.stringify({ id: id, confirm: true }) }).then(refresh).catch(function (e) { msg(e.message, true); });
          } else {
            api('/rules/toggle', { method: 'POST', body: JSON.stringify({ id: id, enabled: act === 'on', confirm: true }) }).then(refresh).catch(function (e) { msg(e.message, true); });
          }
        });
      });
    });
  }

  function loadOutbox() {
    if (!currentMachine) return Promise.resolve();
    return api('/outbox?machine_id=' + currentMachine).then(function (list) {
      var body = document.getElementById('outbox-body');
      if (!list.length) { body.innerHTML = '<tr><td colspan="5" class="muted">暂无发布</td></tr>'; return; }
      body.innerHTML = list.map(function (o) {
        return '<tr><td>v' + o.version + '</td><td class="muted">' + esc(String(o.config_hash).slice(0, 12)) + '…</td>'
          + '<td>' + badge(o.status) + '</td><td>' + esc(o.created_at || '') + '</td>'
          + '<td><button data-ver="' + o.version + '">回滚到此版内容</button></td></tr>';
      }).join('');
      body.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('把 v' + b.getAttribute('data-ver') + ' 的内容以新版本重新发布（回滚）？')) return;
          api('/rollback', { method: 'POST', body: JSON.stringify({ machine_id: currentMachine, to_version: Number(b.getAttribute('data-ver')), confirm: true }) })
            .then(refresh).catch(function (e) { msg(e.message, true); });
        });
      });
    });
  }

  function loadAudit() {
    if (!currentMachine) return Promise.resolve();
    return api('/audit?machine_id=' + currentMachine).then(function (list) {
      var body = document.getElementById('audit-body');
      if (!list.length) { body.innerHTML = '<tr><td colspan="4" class="muted">暂无记录</td></tr>'; return; }
      body.innerHTML = list.map(function (l) {
        return '<tr><td>' + esc(l.created_at || '') + '</td><td>' + esc(l.actor_type) + (l.actor_id ? ' #' + l.actor_id : '') + '</td>'
          + '<td>' + esc(l.action) + '</td><td class="muted">' + esc(l.detail || '') + '</td></tr>';
      }).join('');
    });
  }

  function loadNodes() {
    return api('/nodes').then(function (list) {
      var sel = document.getElementById('f-node');
      sel.innerHTML = (list || []).map(function (n) {
        return '<option value="' + n.id + '">' + esc(n.name) + ' (' + esc(n.host) + ':' + n.server_port + ')</option>';
      }).join('');
    });
  }

  function refresh() { return loadMachines().then(function () { if (currentMachine) return selectMachine(currentMachine); }); }

  document.getElementById('btn-refresh').addEventListener('click', refresh);
  document.getElementById('f-target-type').addEventListener('change', function () {
    var manual = this.value === 'MANUAL';
    document.getElementById('wrap-node').style.display = manual ? 'none' : '';
    document.getElementById('wrap-manual-host').style.display = manual ? '' : 'none';
    document.getElementById('wrap-manual-port').style.display = manual ? '' : 'none';
    document.getElementById('wrap-allowlist').style.display = manual ? '' : 'none';
    document.getElementById('wrap-allowlist-ok').style.display = manual ? '' : 'none';
  });
  document.getElementById('btn-add').addEventListener('click', function () {
    if (!currentMachine) { msg('先选择一台机器', true); return; }
    var type = document.getElementById('f-target-type').value;
    var payload = {
      machine_id: currentMachine, confirm: true,
      name: document.getElementById('f-name').value,
      transport: document.getElementById('f-transport').value,
      listen_ip: document.getElementById('f-listen-ip').value,
      listen_port: Number(document.getElementById('f-listen-port').value),
      target_type: type
    };
    if (type === 'NODE') {
      payload.target_node_id = Number(document.getElementById('f-node').value);
    } else {
      payload.target_host = document.getElementById('f-target-host').value;
      payload.target_port = Number(document.getElementById('f-target-port').value);
      payload.allowlist_cidrs = document.getElementById('f-allowlist').value;
      payload.allowlist_approved = document.getElementById('f-allowlist-approved').checked;
    }
    api('/rules', { method: 'POST', body: JSON.stringify(payload) })
      .then(function () { msg('已添加'); refresh(); })
      .catch(function (e) { msg(e.message, true); });
  });

  refresh().then(loadNodes);
})();
</script>
</body>
</html>

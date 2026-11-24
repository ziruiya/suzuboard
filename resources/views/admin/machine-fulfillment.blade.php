{{-- Business plans batch 3 admin page: 待处理订单 — dedicated-VPS
     fulfilment queue. Standalone Blade page (same pattern as the relay /
     enterprise pages): talks to the V2 admin API under
     /{secure_path}/suzu/machine-fulfillment with the admin token from
     localStorage. --}}
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>待处理订单 · {{ $title ?? 'XBoard' }}</title>
<style>
  body { font-family: -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; margin: 24px; color: #1f2430; background: #f6f7f9; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
  .bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center; }
  select, input, button, textarea { font-size: 13px; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; }
  button { cursor: pointer; }
  button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
  button.danger { background: #b91c1c; color: #fff; border-color: #b91c1c; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 24px; }
  th, td { text-align: left; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
  th { background: #f3f4f6; font-weight: 600; }
  .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; background: #e5e7eb; }
  .badge.delivered { background: #dcfce7; }
  .badge.sla_overdue, .badge.purchase_blocked, .badge.verify_failed { background: #fee2e2; }
  .badge.waiting_install, .badge.installing, .badge.verifying, .badge.purchasing { background: #fef9c3; }
  .muted { color: #6b7280; } .err { color: #b91c1c; }
  section h2 { font-size: 16px; margin: 20px 0 8px; }
  .form { background: #fff; border-radius: 8px; padding: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 24px; }
  .form label { display: inline-flex; flex-direction: column; gap: 4px; font-size: 12px; color: #374151; margin: 0 10px 10px 0; }
  .hint { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; }
  code.cmd { display: block; background: #111827; color: #d1fae5; padding: 10px 12px; border-radius: 6px; font-size: 12px; white-space: pre-wrap; word-break: break-all; margin: 6px 0; }
  .hidden { display: none; }
</style>
</head>
<body>
<h1>待处理订单（独享整机）</h1>
<div class="sub">业务套餐-独享整机履约队列 · 付款后 3 小时交付目标 · 超时只给用户两个选择（继续等原地区 / 免费改派其他地区），本站不退款</div>

<div class="hint" id="hints"></div>

<div class="bar">
  <button id="btn-refresh" class="primary">刷新</button>
  <span id="msg" class="muted"></span>
</div>

<section>
  <h2>履约中订单</h2>
  <table>
    <thead><tr>
      <th>#</th><th>订单 / 用户</th><th>地区</th><th>状态</th><th>付款时间</th>
      <th>交付截止 / 剩余</th><th>公网 IP / 机器</th><th>租期到期</th><th>标记</th><th>操作</th>
    </tr></thead>
    <tbody id="queue-body"><tr><td colspan="10" class="muted">加载中…</td></tr></tbody>
  </table>
</section>

<section id="detail-section" class="hidden">
  <h2>履约详情 <span id="detail-title" class="muted"></span></h2>
  <div class="form">
    <div id="detail-summary" style="margin-bottom:10px"></div>
    <div id="install-wrap" class="hidden">
      <div class="muted">节点安装命令（钉死版本 v1.13，粘贴到 VPS 执行）：</div>
      <code class="cmd" id="install-command"></code>
    </div>
    <fieldset style="border:1px solid #e5e7eb;border-radius:8px;margin:10px 0">
      <legend>采购回填</legend>
      <label>公网 IP <input id="f-ip" type="text" placeholder="如 203.0.113.10"></label>
      <label>供应商 <input id="f-vendor" type="text"></label>
      <label>实例 ID <input id="f-instance" type="text"></label>
      <label>资源到期日 <input id="f-expires" type="text" placeholder="2026-12-25 00:00:00"></label>
      <label>购买记录 <input id="f-note" type="text" placeholder="订单号 / 金额"></label>
      <div>
        <button id="btn-backfill" class="primary">回填 IP 并预注册机器</button>
        <span class="muted">回填后系统自动建机器、专属组与 3 条专线，并生成上方安装命令。</span>
      </div>
    </fieldset>
    <div class="bar">
      <button id="btn-purchase">开始采购</button>
      <button id="btn-blocked">标记采购受阻</button>
      <button id="btn-verify">重新验收</button>
      <button id="btn-suspend">暂停权益</button>
      <button id="btn-resume">恢复权益</button>
    </div>
    <div id="reclaim-wrap" class="hidden">
      <h3 style="font-size:14px">回收清单</h3>
      <div id="reclaim-steps"></div>
    </div>
    <h3 style="font-size:14px">履约日志</h3>
    <table>
      <thead><tr><th>时间</th><th>操作者</th><th>事件</th><th>详情</th></tr></thead>
      <tbody id="logs-body"><tr><td colspan="4" class="muted">—</td></tr></tbody>
    </table>
  </div>
</section>

<script>
(function () {
  var base = '/' + @json($secure_path) + '/suzu/machine-fulfillment';
  var token = localStorage.getItem('access_token') || '';
  var current = null;

  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'Authorization': token, 'Content-Type': 'application/json' }, opts.headers || {});
    return fetch(base + path, opts).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.status === 'success') return j.data;
      throw new Error((j && j.message) || 'request failed');
    });
  }
  function post(path, body) {
    body = Object.assign({ confirm: true }, body || {});
    return api(path, { method: 'POST', body: JSON.stringify(body) });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function msg(t, isErr) { var el = document.getElementById('msg'); el.textContent = t || ''; el.className = isErr ? 'err' : 'muted'; }
  function badge(text) { return '<span class="badge ' + esc(String(text).toLowerCase()) + '">' + esc(text) + '</span>'; }
  function fmtRemain(sec) {
    if (sec == null) return '—';
    var neg = sec < 0; sec = Math.abs(sec);
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
    return (neg ? '已超时 ' : '剩 ') + h + ' 小时 ' + m + ' 分';
  }

  function loadQueue() {
    return api('/queue').then(function (data) {
      var hints = data.purchase_hints || {};
      document.getElementById('hints').innerHTML =
        '<b>采购提示：</b>' + esc(hints.min_spec || '') + '<br>' + esc(hints.cost_cap || '') + '<br>' + esc(hints.flow || '');
      var items = data.items || [];
      var body = document.getElementById('queue-body');
      if (!items.length) { body.innerHTML = '<tr><td colspan="10" class="muted">暂无履约中订单</td></tr>'; return; }
      body.innerHTML = items.map(function (f) {
        var flags = [];
        if (f.sla_overdue) flags.push('<span class="badge sla_overdue">SLA 超时</span>');
        if (f.termination_review) flags.push('<span class="badge sla_overdue">终止审查</span>');
        if (f.lease_expiring_soon) flags.push('<span class="badge waiting_install">租期将到期</span>');
        if (f.user_choice) flags.push(esc(f.user_choice));
        return '<tr>' +
          '<td>' + f.id + '</td>' +
          '<td>' + esc(f.trade_no || ('订单 ' + f.order_id)) + '<br><span class="muted">' + esc(f.user_email || '') + '</span></td>' +
          '<td>' + esc(f.region_name || '') + '</td>' +
          '<td>' + badge(f.status) + '</td>' +
          '<td>' + esc(f.paid_at || '—') + '</td>' +
          '<td>' + esc(f.delivery_deadline_at || '—') + '<br><span class="muted">' + fmtRemain(f.sla_remaining_seconds) + '</span></td>' +
          '<td>' + esc(f.public_ip || '—') + '<br><span class="muted">' + esc(f.machine_name || '') + '</span></td>' +
          '<td>' + esc(f.resource_expires_at || '—') + '</td>' +
          '<td>' + flags.join(' ') + '</td>' +
          '<td><button data-open="' + f.id + '">查看</button></td>' +
          '</tr>';
      }).join('');
      body.querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { openDetail(Number(b.getAttribute('data-open'))); });
      });
    }).catch(function (e) { msg(e.message, true); });
  }

  function openDetail(id) {
    return api('/' + id).then(function (d) {
      current = d;
      document.getElementById('detail-section').classList.remove('hidden');
      document.getElementById('detail-title').textContent = '# ' + d.id + ' · ' + (d.user_email || '') + ' · ' + (d.region_name || '');
      document.getElementById('detail-summary').innerHTML =
        '状态 ' + badge(d.status) + ' · 付款 ' + esc(d.paid_at || '—') + ' · 截止 ' + esc(d.delivery_deadline_at || '—') +
        (d.verify_report ? '<br>验收报告：' + esc(JSON.stringify(d.verify_report)) : '');
      var wrap = document.getElementById('install-wrap');
      if (d.install_command) {
        wrap.classList.remove('hidden');
        document.getElementById('install-command').textContent = d.install_command;
      } else { wrap.classList.add('hidden'); }
      document.getElementById('f-ip').value = d.public_ip || '';
      document.getElementById('f-vendor').value = d.vendor_name || '';
      document.getElementById('f-instance').value = d.vendor_instance_id || '';
      document.getElementById('f-expires').value = d.resource_expires_at || '';
      document.getElementById('f-note').value = d.purchase_note || '';
      var logs = document.getElementById('logs-body');
      logs.innerHTML = (d.logs || []).map(function (l) {
        return '<tr><td>' + esc(l.created_at || '') + '</td><td>' + esc(l.actor_type || '') + '</td><td>' + esc(l.event || '') + '</td><td>' + esc(l.detail || '') + '</td></tr>';
      }).join('') || '<tr><td colspan="4" class="muted">—</td></tr>';
      var rw = document.getElementById('reclaim-wrap');
      if (d.status === 'RECLAIM_PENDING' || d.status === 'GRACE_HELD') {
        rw.classList.remove('hidden');
        var cl = d.reclaim_checklist || {};
        document.getElementById('reclaim-steps').innerHTML = (d.reclaim_steps || []).map(function (s) {
          var done = cl[s] && cl[s].done;
          return '<div>' + (done ? '☑' : '☐') + ' ' + esc(s) +
            (done ? '' : ' <button data-step="' + esc(s) + '">标记完成</button>') + '</div>';
        }).join('');
        rw.querySelectorAll('[data-step]').forEach(function (b) {
          b.addEventListener('click', function () {
            post('/' + d.id + '/reclaim-step', { step: b.getAttribute('data-step') })
              .then(function () { msg('回收步骤已记录'); openDetail(d.id); loadQueue(); })
              .catch(function (e) { msg(e.message, true); });
          });
        });
      } else { rw.classList.add('hidden'); }
    }).catch(function (e) { msg(e.message, true); });
  }

  function act(path, body) {
    if (!current) return;
    post('/' + current.id + path, body || {})
      .then(function () { msg('已更新'); openDetail(current.id); loadQueue(); })
      .catch(function (e) { msg(e.message, true); });
  }

  document.getElementById('btn-refresh').addEventListener('click', loadQueue);
  document.getElementById('btn-backfill').addEventListener('click', function () {
    act('/backfill-ip', {
      public_ip: document.getElementById('f-ip').value,
      vendor_name: document.getElementById('f-vendor').value,
      vendor_instance_id: document.getElementById('f-instance').value,
      resource_expires_at: document.getElementById('f-expires').value || null,
      purchase_note: document.getElementById('f-note').value
    });
  });
  document.getElementById('btn-purchase').addEventListener('click', function () { act('/start-purchase'); });
  document.getElementById('btn-blocked').addEventListener('click', function () {
    var note = prompt('受阻原因（可空）') || '';
    act('/mark-blocked', { note: note });
  });
  document.getElementById('btn-verify').addEventListener('click', function () { act('/retry-verify'); });
  document.getElementById('btn-suspend').addEventListener('click', function () { act('/suspend'); });
  document.getElementById('btn-resume').addEventListener('click', function () { act('/resume'); });

  loadQueue();
})();
</script>
</body>
</html>

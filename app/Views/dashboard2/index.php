<?php use App\Security\Csrf; use App\Helpers; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Dashboard 2</h1>
  <div>
    <form method="post" action="/action/fetch-now" class="d-inline js-loading-form"><!-- Fetch Now -->
      <?php echo Csrf::input(); ?>
      <input type="hidden" name="return" value="/dashboard2">
      <button class="btn btn-sm btn-outline-primary js-loading-btn" data-loading-text="Fetching...">Fetch Now</button>
    </form>
    <form method="post" action="/action/run-filter" class="d-inline ms-2 js-loading-form"><!-- Run Filter -->
      <?php echo Csrf::input(); ?>
      <input type="hidden" name="return" value="/dashboard2">
      <button class="btn btn-sm btn-primary js-loading-btn" data-loading-text="Filtering...">Run Filter</button>
    </form>
    <form id="processAllFormD2" method="post" action="/action/run-filter-all" class="d-inline ms-2 js-loading-form"><!-- Process All -->
      <?php echo Csrf::input(); ?>
      <input type="hidden" name="return" value="/dashboard2">
      <input type="hidden" name="all" value="1">
      <input type="hidden" name="batch" value="500">
      <input type="hidden" name="cap" value="5000">
      <button class="btn btn-sm btn-warning js-loading-btn" data-loading-text="Processing...">Process All</button>
    </form>
    <?php
      $exportQs = ['status'=>'genuine','range'=>$range];
      if ($range==='custom') { $exportQs['start']=$start; $exportQs['end']=$end; }
    ?>
    <a class="btn btn-sm btn-success ms-2" href="<?php echo '/leads/export?' . http_build_query($exportQs); ?>">Export CSV</a>
  </div>
</div>

<div class="dashboard2">
<div class="card p-2 mb-3">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php
      $rangeBtn = function($label,$val,$active){
        $cls = 'btn btn-sm ' . ($active ? 'btn-warning' : 'btn-outline-secondary');
        $url = '/dashboard2?range=' . urlencode($val);
        return '<a class="'.$cls.'" href="'.$url.'">'.$label.'</a>';
      };
      echo $rangeBtn('Last Week','last_week', $range==='last_week');
      echo $rangeBtn('Last 7 Days','last_7', $range==='last_7');
      echo $rangeBtn('Last Month','last_month', $range==='last_month');
      echo $rangeBtn('Last 30 Days','last_30', $range==='last_30');
      echo $rangeBtn('All Time','all', $range==='all');
    ?>
    <form method="get" action="/dashboard2" class="d-flex align-items-center gap-2 ms-auto">
      <input type="hidden" name="range" value="custom">
      <div class="input-group input-group-sm" style="width: 210px;">
        <span class="input-group-text">Start</span>
        <input class="form-control" type="date" name="start" value="<?php echo Helpers::e(substr($start,0,10)); ?>">
      </div>
      <div class="input-group input-group-sm" style="width: 210px;">
        <span class="input-group-text">End</span>
        <input class="form-control" type="date" name="end" value="<?php echo Helpers::e(substr($end,0,10)); ?>">
      </div>
      <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card shadow-sm stat-card stat-blue"><div class="card-body"><div class="small text-muted">New Emails</div><div class="display-6 fw-semibold"><?php echo (int)$newEmails; ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm stat-card stat-purple"><div class="card-body"><div class="small text-muted">Processed Leads</div><div class="display-6 fw-semibold"><?php echo (int)$processed; ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm stat-card stat-green"><div class="card-body"><div class="small text-muted">Genuine Leads</div><div class="display-6 fw-semibold"><?php echo (int)$genuine; ?></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm stat-card stat-red"><div class="card-body"><div class="small text-muted">Spam Filtered</div><div class="display-6 fw-semibold"><?php echo (int)$spam; ?></div></div></div></div>
  <div class="col-md-6"><div class="card shadow-sm stat-card stat-amber"><div class="card-body"><div class="small text-muted">Genuine Rate</div><div class="display-6 fw-semibold"><?php echo number_format((float)$genuineRate,1); ?>%</div></div></div></div>
  <div class="col-md-6"><div class="card shadow-sm stat-card stat-amber"><div class="card-body"><div class="small text-muted">Spam Rate</div><div class="display-6 fw-semibold"><?php echo number_format((float)$spamRate,1); ?>%</div></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><h6 class="text-muted">Leads Over Time</h6><div class="chart-box"><canvas id="chartLines"></canvas></div></div></div></div>
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-body"><h6 class="text-muted">Status Split</h6><div class="chart-box chart-box--pie"><canvas id="chartPie"></canvas></div></div></div></div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h6 class="text-muted mb-3">Top Sender Domains</h6>
    <div class="chart-box chart-box--bar"><canvas id="chartDomains"></canvas></div>
  </div>
 </div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h6 class="text-muted mb-2">Recent Leads</h6>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="small text-muted"><tr><th>Status & Score</th><th>From</th><th>Subject</th><th>Received</th><th>Mode</th><th></th></tr></thead>
        <tbody>
          <?php foreach (($recent ?? []) as $r): ?>
          <tr>
            <td>
              <?php if ($r['status']==='genuine'): ?><span class="badge bg-success">genuine</span>
              <?php elseif ($r['status']==='spam'): ?><span class="badge bg-danger">spam</span>
              <?php else: ?><span class="badge bg-secondary">unknown</span><?php endif; ?>
              <span class="badge bg-light text-dark ms-1"><?php echo (int)$r['score']; ?></span>
            </td>
            <td><?php echo Helpers::e($r['from_email']); ?></td>
            <td class="text-truncate" style="max-width: 420px;" title="<?php echo Helpers::e($r['subject']); ?>"><?php echo Helpers::e($r['subject']); ?></td>
            <td><?php echo Helpers::e($r['received_at']); ?></td>
            <td><span class="badge bg-light text-dark"><?php echo Helpers::e($r['mode'] ?? ''); ?></span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="#" data-id="<?php echo (int)$r['id']; ?>" onclick="return viewLead(<?php echo (int)$r['id']; ?>);">View</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h6 class="text-muted mb-2">System Health</h6>
    <div class="row g-3">
      <div class="col-md-3"><div class="h6 mb-0">Unprocessed Emails</div><div class="display-6"><?php echo (int)$queue; ?></div></div>
      <div class="col-md-3"><div class="h6 mb-0">Last Fetch</div><div class="small text-muted"><?php echo Helpers::e($lastFetchAt ?: '—'); ?></div></div>
      <div class="col-md-3"><div class="h6 mb-0">Last Process</div><div class="small text-muted"><?php echo Helpers::e($lastProcessAt ?: '—'); ?></div></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const chartData = <?php echo json_encode($chart ?? [], JSON_UNESCAPED_SLASHES); ?>;
const statusSplit = <?php echo json_encode($statusSplit ?? [], JSON_UNESCAPED_SLASHES); ?>;
const domains = <?php echo json_encode($domains ?? [], JSON_UNESCAPED_SLASHES); ?>;

function viewLead(id){
  // Reuse modal from leads page if present
  let modalEl = document.getElementById('leadModal');
  if (!modalEl) {
    modalEl = document.createElement('div');
    modalEl.className = 'modal fade modal-zoom';
    modalEl.id = 'leadModal'; modalEl.tabIndex = -1; modalEl.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Lead</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="leadModalBody"><div class="text-muted">Loading...</div></div></div></div>';
    document.body.appendChild(modalEl);
  }
  const modalBody = modalEl.querySelector('#leadModalBody');
  const modal = new bootstrap.Modal(modalEl);
  modalBody.innerHTML = '<div class="text-muted">Loading...</div>';
  fetch('/lead/view?id=' + encodeURIComponent(id) + '&partial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
    .then(r=>r.text()).then(html=>{ modalBody.innerHTML = html; modal.show(); });
  return false;
}

document.addEventListener('DOMContentLoaded', function(){
  // KPI buttons spinner
  document.querySelectorAll('form.js-loading-form').forEach(function (form) {
    const btn = form.querySelector('.js-loading-btn');
    if (!btn) return;
    form.addEventListener('submit', function () {
      btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (btn.getAttribute('data-loading-text')||'Working...');
    });
  });

  // Progress polling for Process All
  const formAll = document.getElementById('processAllFormD2');
  if (formAll) {
    formAll.addEventListener('submit', function () {
      let info = document.getElementById('progressInfoD2');
      if (!info) {
        info = document.createElement('div');
        info.className = 'small text-muted ms-2 d-inline-block';
        info.id = 'progressInfoD2';
        formAll.parentElement.appendChild(info);
      }
      const tick = async () => {
        try {
          const res = await fetch('/action/filter-progress', { headers: { 'Accept':'application/json' } });
          if (!res.ok) return;
          const j = await res.json();
          if (j && (typeof j.processed !== 'undefined')) {
            info.textContent = j.done ? `Done. Processed ${j.processed} of ${j.total}. Remaining: ${j.remaining ?? 0}.` : `Processing ${j.processed} of ${j.total}...`;
            if (!j.done) { setTimeout(tick, 1000); }
          }
        } catch (e) {}
      };
      setTimeout(tick, 800);
    });
  }

  if (window.Chart && chartData && chartData.labels) {
    // Avoid multiple initializations if this script runs more than once
    window._dash2Charts = window._dash2Charts || {};
    if (window._dash2Charts.lines) { window._dash2Charts.lines.destroy(); }
    if (window._dash2Charts.pie) { window._dash2Charts.pie.destroy(); }
    if (window._dash2Charts.domains) { window._dash2Charts.domains.destroy(); }

    window._dash2Charts.lines = new Chart(document.getElementById('chartLines'), {
      type: 'line',
      data: {
        labels: chartData.labels,
        datasets: [
          { label: 'genuine', data: chartData.genuine, borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,.15)', tension:.25, fill:true },
          { label: 'spam', data: chartData.spam, borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.15)', tension:.25, fill:true },
          { label: 'unknown', data: chartData.unknown, borderColor: '#6c757d', backgroundColor: 'rgba(108,117,125,.15)', tension:.25, fill:true }
        ]
      }, options: { responsive:true, resizeDelay:120, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } }
    });

    window._dash2Charts.pie = new Chart(document.getElementById('chartPie'), {
      type: 'pie', data: { labels:['genuine','spam','unknown'], datasets:[{ data:[statusSplit.genuine||0,statusSplit.spam||0,statusSplit.unknown||0], backgroundColor:['#28a745','#dc3545','#6c757d'] }] }, options:{ responsive:true, resizeDelay:120, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });

    window._dash2Charts.domains = new Chart(document.getElementById('chartDomains'), {
      type: 'bar', data: { labels: (domains||[]).map(d=>d.dom), datasets:[{ label:'emails', data:(domains||[]).map(d=>+d.c), backgroundColor:'#3b82f6' }] }, options:{ responsive:true, resizeDelay:120, maintainAspectRatio:false, indexAxis:'x', scales:{ y:{ beginAtZero:true } } }
    });
  }
});
</script>

<style>
.dashboard2 .row.g-3 { --bs-gutter-x: .75rem; --bs-gutter-y: .75rem; }
.dashboard2 .card .card-body { padding: .75rem .9rem; }
.dashboard2 .stat-card .card-body { padding: .6rem .8rem; }
.dashboard2 .stat-card .display-6 { font-size: 1.5rem; }
.dashboard2 .stat-card .small { font-size: .75rem; }
.dashboard2 .table { font-size: .9rem; }
.dashboard2 .btn, .dashboard2 .input-group-text, .dashboard2 .form-control { padding: .25rem .5rem; font-size: .85rem; }
.dashboard2 h6 { font-size: .95rem; margin-bottom: .4rem; }
.stat-blue   { background: linear-gradient(180deg,#e8f0ff, #f6f9ff); }
.stat-purple { background: linear-gradient(180deg,#f0e8ff, #faf6ff); }
.stat-green  { background: linear-gradient(180deg,#e8fff0, #f6fffa); }
.stat-red    { background: linear-gradient(180deg,#ffe8e8, #fff6f6); }
.stat-amber  { background: linear-gradient(180deg,#fff6e5, #fffaf0); }
/* Fix chart growth by constraining canvas area */
.dashboard2 .chart-box { height: 150px; position: relative; }
.dashboard2 .chart-box--pie { height: 130px; }
.dashboard2 .chart-box--bar { height: 140px; }
.dashboard2 .chart-box canvas { width: 100% !important; height: 100% !important; display: block; }
</style>

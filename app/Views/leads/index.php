<?php use App\Helpers; use App\Security\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Leads</h1>
  <div class="d-flex align-items-center gap-2">
  <form class="d-flex" method="get" action="/leads">
    <input type="hidden" name="range" value="<?php echo Helpers::e($range); ?>">
    <?php if (!empty($activeClient)): ?>
      <input type="hidden" name="client" value="<?php echo Helpers::e($activeClient); ?>">
    <?php endif; ?>
    <?php if (!empty($status)): ?>
      <input type="hidden" name="status" value="<?php echo Helpers::e($status); ?>">
    <?php endif; ?>
    <input class="form-control form-control-sm me-2" type="search" placeholder="Search" name="q" value="<?php echo Helpers::e($q ?? ''); ?>">
    <select class="form-select form-select-sm me-2" name="sort">
      <option value="desc" <?php echo ($sort==='desc'?'selected':''); ?>>Newest</option>
      <option value="asc" <?php echo ($sort==='asc'?'selected':''); ?>>Oldest</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary">Apply</button>
  </form>
  <form method="post" action="/action/fetch-now" class="js-loading-form">
    <?php echo App\Security\Csrf::input(); ?>
    <input type="hidden" name="return" value="<?php echo Helpers::e($_SERVER['REQUEST_URI'] ?? '/leads'); ?>">
    <button class="btn btn-sm btn-outline-primary js-loading-btn" data-loading-text="Updating...">
      Update Emails
    </button>
  </form>
  <form method="post" action="/action/run-filter" class="js-loading-form ms-1">
    <?php echo App\Security\Csrf::input(); ?>
    <input type="hidden" name="return" value="<?php echo Helpers::e($_SERVER['REQUEST_URI'] ?? '/leads'); ?>">
    <input type="hidden" name="batch" value="500">
    <input type="hidden" name="all" value="1">
    <input type="hidden" name="cap" value="5000">
    <button class="btn btn-sm btn-primary js-loading-btn" data-loading-text="Filtering...">Run Filter</button>
  </form>
  </div>
</div>

<?php if (!empty($clients)): ?>
<div class="mb-2">
  <div class="btn-group btn-group-sm" role="group">
    <?php
      $clientBase = [ 'range'=>$range, 'q'=>$q ?? '', 'sort'=>$sort ];
      if (!empty($status)) { $clientBase['status'] = $status; }
    ?>
    <a class="btn btn-outline-secondary <?php echo empty($activeClient)?'active':''; ?>" href="<?php echo '/leads?' . http_build_query($clientBase); ?>">All Clients</a>
    <?php foreach ($clients as $c): ?>
      <?php $cb = $clientBase; $cb['client'] = $c['shortcode']; ?>
      <a class="btn btn-outline-secondary <?php echo ($activeClient===$c['shortcode']?'active':''); ?>" href="<?php echo '/leads?' . http_build_query($cb); ?>"><?php echo Helpers::e($c['shortcode']); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
  $rangeBase = [ 'q'=>$q ?? '', 'sort'=>$sort ];
  if (!empty($activeClient)) { $rangeBase['client'] = $activeClient; }
  if (!empty($status)) { $rangeBase['status'] = $status; }
  $rangeLink = function(string $label, string $val) use ($rangeBase, $range) {
    $qs = $rangeBase; $qs['range'] = $val;
    $active = ($range === $val) ? ' active' : '';
    return '<a class="btn btn-outline-secondary btn-sm' . $active . '" href="/leads?' . http_build_query($qs) . '">' . $label . '</a>';
  };
?>
<div class="btn-group mb-3" role="group">
  <?php echo $rangeLink('Last week','last_week'); ?>
  <?php echo $rangeLink('Last 7 days','last_7'); ?>
  <?php echo $rangeLink('Last month','last_month'); ?>
  <?php echo $rangeLink('Last 30 days','last_30'); ?>
  <?php echo $rangeLink('All time','all'); ?>
  </div>

<div class="d-flex justify-content-between mb-2">
  <div>
    <a class="btn btn-sm btn-outline-success" href="/leads/export?status=genuine&range=<?php echo urlencode($range); ?>&q=<?php echo urlencode($q ?? ''); ?>&sort=<?php echo urlencode($sort); ?><?php if(!empty($activeClient)) echo '&client='.urlencode($activeClient); ?>">Export CSV (Genuine)</a>
  </div>
  <form method="post" action="/leads/bulk" class="d-flex align-items-center flex-nowrap gap-2 bulk-actions">
    <?php echo App\Security\Csrf::input(); ?>
    <select name="action" class="form-select form-select-sm w-auto">
      <option value="mark_genuine">Mark Genuine</option>
      <option value="mark_spam">Mark Spam</option>
      <option value="reprocess">Re-process</option>
    </select>
    <button class="btn btn-sm btn-primary text-nowrap">Apply to selected</button>
    <button type="button" class="btn btn-sm btn-outline-success text-nowrap" onclick="document.querySelectorAll('tr[data-status=genuine] input.rowcb').forEach(cb=>cb.checked=true)">Select all Genuine</button>
    <?php
      $baseQuery = [ 'range'=>$range, 'q'=>$q ?? '', 'sort'=>$sort ];
      if (!empty($activeClient)) { $baseQuery['client'] = $activeClient; }
      // Genuine toggle
      if (!empty($status) && $status === 'genuine') {
          $genUrl = '/leads?' . http_build_query($baseQuery);
          $genLabel = 'Show All';
      } else {
          $genQuery = $baseQuery; $genQuery['status'] = 'genuine';
          $genUrl = '/leads?' . http_build_query($genQuery);
          $genLabel = 'Show Genuine only';
      }
      // Spam toggle
      if (!empty($status) && $status === 'spam') {
          $spamUrl = '/leads?' . http_build_query($baseQuery);
          $spamLabel = 'Show All';
      } else {
          $spamQuery = $baseQuery; $spamQuery['status'] = 'spam';
          $spamUrl = '/leads?' . http_build_query($spamQuery);
          $spamLabel = 'Show Spam only';
      }
    ?>
    <a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?php echo $genUrl; ?>"><?php echo $genLabel; ?></a>
    <a class="btn btn-sm btn-outline-danger text-nowrap" href="<?php echo $spamUrl; ?>"><?php echo $spamLabel; ?></a>
  </form>
</div>

<div class="table-responsive">
  <form method="post" action="/leads/bulk" id="bulkForm">
    <?php echo App\Security\Csrf::input(); ?>
  <table class="table table-sm align-middle" id="leadsTable">
    <thead>
      <tr>
        <th style="width:24px;"><input type="checkbox" onclick="document.querySelectorAll('.rowcb').forEach(cb=>cb.checked=this.checked)"></th>
        <th>Sender</th>
        <th>Subject</th>
        <th>Snippet</th>
        <th>Received</th>
        <th>Score</th>
        <th>Status</th>
        <th>Mode</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php include __DIR__ . '/_rows.php'; ?>
    </tbody>
  </table>
  </form>
</div>

<?php
  $pages = max(1, (int)ceil(($total ?? 0) / ($limit ?? 25)));
  $current = (int)($page ?? 1);
?>
<div class="d-flex justify-content-between align-items-center mt-2">
  <div class="text-muted small">Page <?php echo $current; ?> of <?php echo $pages; ?> • Total: <?php echo (int)($total ?? 0); ?></div>
  <button id="loadMoreLeads" class="btn btn-sm btn-outline-secondary" <?php echo ($current >= $pages) ? 'disabled' : ''; ?>>Load more</button>
</div>

<!-- Lead modal -->
<div class="modal fade modal-zoom" id="leadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Lead</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="leadModalBody">
        <div class="text-muted">Loading…</div>
      </div>
    </div>
  </div>
 </div>

<script>
// View modal loader with a subtle zoom animation
document.addEventListener('DOMContentLoaded', function () {
  const modalEl = document.getElementById('leadModal');
  const modalBody = document.getElementById('leadModalBody');
  const modal = new bootstrap.Modal(modalEl);

  document.querySelectorAll('.btn-view').forEach(function (btn) {
    btn.addEventListener('click', async function (e) {
      e.preventDefault();
      const id = this.getAttribute('data-id');
      modalBody.innerHTML = '<div class="text-muted">Loading…</div>';
      try {
        const res = await fetch('/lead/view?id=' + encodeURIComponent(id) + '&partial=1', {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const html = await res.text();
        modalBody.innerHTML = html;
      } catch (err) {
        modalBody.innerHTML = '<div class="text-danger">Failed to load lead.</div>';
      }
      modal.show();
    });
  });

  // Handle mark/reprocess inside modal via AJAX and close with a quick fade
  modalEl.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    const action = form.getAttribute('action') || '';
    if (!/\/lead\/(mark|reprocess)$/.test(action)) return;
    e.preventDefault();
    try {
      const fd = new FormData(form);
      const res = await fetch(action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (!json || !json.ok) throw new Error('Bad response');
      const id = json.lead?.id;
      const status = json.lead?.status || 'unknown';
      const score = json.lead?.score ?? '';
      const mode = json.lead?.mode || '';
      if (id) {
        const rowCb = document.querySelector('#leadsTable input.rowcb[value="' + id + '"]');
        const row = rowCb ? rowCb.closest('tr') : null;
        if (row) {
          row.setAttribute('data-status', status);
          const cells = row.querySelectorAll('td');
          if (cells[5]) cells[5].textContent = String(score);
          if (cells[6]) {
            if (status === 'genuine') cells[6].innerHTML = '<span class="badge bg-success">Genuine</span>';
            else if (status === 'spam') cells[6].innerHTML = '<span class="badge bg-danger">Spam</span>';
            else cells[6].innerHTML = '<span class="badge bg-secondary">Unknown</span>';
          }
          if (cells[7]) cells[7].innerHTML = '<span class="badge bg-light text-dark">' + mode.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
        }
      }
      // Quick fade-out before closing
      const content = modalEl.querySelector('.modal-content');
      if (content) { content.style.transition = 'opacity .15s ease, transform .15s ease'; content.style.opacity = '0'; content.style.transform = 'scale(0.98)'; }
      setTimeout(() => { modal.hide(); if (content) { content.style.opacity=''; content.style.transform=''; } }, 150);
    } catch (err) {
      modalBody.innerHTML = '<div class="text-danger">Action failed. Please try again.</div>';
    }
  }, true);
  // Load more
  const loadBtn = document.getElementById('loadMoreLeads');
  if (loadBtn) {
    let currentPage = <?php echo (int)$page; ?>;
    const total = <?php echo (int)$total; ?>;
    const limit = <?php echo (int)$limit; ?>;
    loadBtn.addEventListener('click', async function () {
      currentPage += 1;
      const url = new URL(window.location.href);
      url.searchParams.set('page', currentPage);
      url.searchParams.set('partial', '1');
      const res = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const html = await res.text();
      const tbody = document.querySelector('#leadsTable tbody');
      const temp = document.createElement('tbody');
      temp.innerHTML = html;
      while (temp.firstChild) { tbody.appendChild(temp.firstChild); }
      // Update counters
      const shown = Math.min(currentPage * limit, total);
      const info = document.querySelector('.text-muted.small');
      if (info) info.textContent = `Page ${currentPage} of ${Math.max(1, Math.ceil(total/limit))} • Total: ${total}`;
      if (shown >= total) { loadBtn.disabled = true; }
      // Re-bind view buttons for newly added rows
      document.querySelectorAll('.btn-view').forEach(function (btn) {
        if (!btn._bound) {
          btn.addEventListener('click', async function (e) {
            e.preventDefault();
            const id = this.getAttribute('data-id');
            modalBody.innerHTML = '<div class="text-muted">Loading…</div>';
            const res2 = await fetch('/lead/view?id=' + encodeURIComponent(id) + '&partial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            modalBody.innerHTML = await res2.text();
            modal.show();
          });
          btn._bound = true;
        }
      });
    });
  }
});
</script>

<style>
/* Keep action buttons in one line and add a pleasant zoom/fade for modal */
.modal-zoom .modal-dialog { transform: scale(0.98); transition: transform .18s ease-out; }
.modal-zoom.show .modal-dialog { transform: scale(1); }
.bulk-actions { white-space: nowrap; }
.bulk-actions .form-select { min-width: 12rem; }
</style>

<script>
// Lightweight submit spinner for "Update Emails" button
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form.js-loading-form').forEach(function (form) {
    const btn = form.querySelector('.js-loading-btn');
    if (!btn) return;
    form.addEventListener('submit', function () {
      btn.disabled = true;
      const text = btn.getAttribute('data-loading-text') || 'Working...';
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + text;
    });
  });
});
</script>

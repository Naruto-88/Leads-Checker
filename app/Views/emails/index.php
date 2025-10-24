<?php use App\Helpers; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Emails</h1>
  <div class="d-flex align-items-center gap-2">
  <form class="d-flex" method="get" action="/emails">
    <input type="hidden" name="range" value="<?php echo Helpers::e($range); ?>">
    <input class="form-control form-control-sm me-2" type="search" placeholder="Search" name="q" value="<?php echo Helpers::e($q ?? ''); ?>">
    <button class="btn btn-sm btn-outline-secondary">Apply</button>
  </form>
  <form method="post" action="/action/fetch-now" class="js-loading-form">
    <?php echo App\Security\Csrf::input(); ?>
    <input type="hidden" name="return" value="<?php echo Helpers::e($_SERVER['REQUEST_URI'] ?? '/emails'); ?>">
    <button class="btn btn-sm btn-outline-primary js-loading-btn" data-loading-text="Updating...">Update Emails</button>
  </form>
  <form method="post" action="/action/run-filter" class="js-loading-form ms-1">
    <?php echo App\Security\Csrf::input(); ?>
    <input type="hidden" name="return" value="<?php echo Helpers::e($_SERVER['REQUEST_URI'] ?? '/emails'); ?>">
    <input type="hidden" name="batch" value="500">
    <button class="btn btn-sm btn-primary js-loading-btn" data-loading-text="Filtering...">Run Filter</button>
  </form>
  </div>
</div>

<?php if (!empty($clients)): ?>
<div class="mb-2">
  <div class="btn-group btn-group-sm" role="group">
    <a class="btn btn-outline-secondary <?php echo empty($activeClient)?'active':''; ?>" href="/emails?range=<?php echo urlencode($range); ?>">All Clients</a>
    <?php foreach ($clients as $c): ?>
      <a class="btn btn-outline-secondary <?php echo ($activeClient===$c['shortcode']?'active':''); ?>" href="/emails?range=<?php echo urlencode($range); ?>&client=<?php echo urlencode($c['shortcode']); ?>"><?php echo App\Helpers::e($c['shortcode']); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="btn-group mb-3" role="group">
  <a class="btn btn-outline-secondary btn-sm" href="/emails?range=last_week">Last week</a>
  <a class="btn btn-outline-secondary btn-sm" href="/emails?range=last_7">Last 7 days</a>
  <a class="btn btn-outline-secondary btn-sm" href="/emails?range=last_month">Last month</a>
  <a class="btn btn-outline-secondary btn-sm" href="/emails?range=last_30">Last 30 days</a>
  <a class="btn btn-outline-secondary btn-sm" href="/emails?range=all">All time</a>
  </div>

<div class="table-responsive">
  <table class="table table-sm align-middle" id="emailsTable">
    <thead>
      <tr>
        <th>Sender</th>
        <th>Subject</th>
        <th>Snippet</th>
        <th>Received</th>
      </tr>
    </thead>
    <tbody>
    <?php include __DIR__ . '/_rows.php'; ?>
    </tbody>
  </table>
</div>

<?php
  $pages = max(1, (int)ceil(($total ?? 0) / ($limit ?? 25)));
  $current = (int)($page ?? 1);
?>
<div class="d-flex justify-content-between align-items-center mt-2">
  <div class="text-muted small">Page <?php echo $current; ?> of <?php echo $pages; ?> • Total: <?php echo (int)($total ?? 0); ?></div>
  <button id="loadMoreEmails" class="btn btn-sm btn-outline-secondary" <?php echo ($current >= $pages) ? 'disabled' : ''; ?>>Load more</button>
</div>

<script>
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
  const loadBtn = document.getElementById('loadMoreEmails');
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
      const tbody = document.querySelector('#emailsTable tbody');
      const temp = document.createElement('tbody');
      temp.innerHTML = html;
      while (temp.firstChild) { tbody.appendChild(temp.firstChild); }
      const info = document.querySelector('.text-muted.small');
      if (info) info.textContent = `Page ${currentPage} of ${Math.max(1, Math.ceil(total/limit))} • Total: ${total}`;
      const shown = Math.min(currentPage * limit, total);
      if (shown >= total) { loadBtn.disabled = true; }
    });
  }
});
</script>

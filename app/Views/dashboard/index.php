<?php use App\Security\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3">Dashboard</h1>
  <div>
    <form method="post" action="/action/fetch-now" class="d-inline js-loading-form">
      <?php echo Csrf::input(); ?>
      <button class="btn btn-outline-primary btn-sm js-loading-btn" data-loading-text="Fetching...">Fetch Now</button>
    </form>
    <form method="post" action="/action/run-filter" class="d-inline ms-2 js-loading-form">
      <?php echo Csrf::input(); ?>
      <button class="btn btn-primary btn-sm js-loading-btn" data-loading-text="Filtering...">Run Filter</button>
    </form>
    <form method="post" action="/action/run-filter" class="d-inline ms-2 js-loading-form">
      <?php echo Csrf::input(); ?>
      <input type="hidden" name="all" value="1">
      <input type="hidden" name="batch" value="500">
      <input type="hidden" name="cap" value="5000">
      <button class="btn btn-primary btn-sm js-loading-btn" data-loading-text="Processing...">Process All</button>
    </form>
  </div>
  </div>

<div class="row g-3">
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">New emails (7d)</div><div class="h4"><?php echo (int)$newEmails; ?></div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Genuine (7d)</div><div class="h4"><?php echo (int)$genuine7; ?></div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Genuine (30d)</div><div class="h4"><?php echo (int)$genuine30; ?></div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Spam filtered</div><div class="h4"><?php echo (int)$spam; ?></div></div></div></div>
</div>

<div class="row mt-3">
  <div class="col-md-6"><div class="alert alert-secondary mb-0">Processing queue: <strong><?php echo (int)$queue; ?></strong></div></div>
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
});
</script>


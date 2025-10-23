<?php use App\Security\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3">Dashboard</h1>
  <div>
    <form method="post" action="/action/fetch-now" class="d-inline">
      <?php echo Csrf::input(); ?>
      <button class="btn btn-outline-primary btn-sm">Fetch Now</button>
    </form>
    <form method="post" action="/action/run-filter" class="d-inline ms-2">
      <?php echo Csrf::input(); ?>
      <button class="btn btn-primary btn-sm">Run Filter</button>
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


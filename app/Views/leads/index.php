<?php use App\Helpers; use App\Security\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Leads</h1>
  <form class="d-flex" method="get" action="/leads">
    <input type="hidden" name="range" value="<?php echo Helpers::e($range); ?>">
    <?php if (!empty($activeClient)): ?>
      <input type="hidden" name="client" value="<?php echo Helpers::e($activeClient); ?>">
    <?php endif; ?>
    <input class="form-control form-control-sm me-2" type="search" placeholder="Search" name="q" value="<?php echo Helpers::e($q ?? ''); ?>">
    <select class="form-select form-select-sm me-2" name="sort">
      <option value="desc" <?php echo ($sort==='desc'?'selected':''); ?>>Newest</option>
      <option value="asc" <?php echo ($sort==='asc'?'selected':''); ?>>Oldest</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary">Apply</button>
  </form>
</div>

<?php if (!empty($clients)): ?>
<div class="mb-2">
  <div class="btn-group btn-group-sm" role="group">
    <a class="btn btn-outline-secondary <?php echo empty($activeClient)?'active':''; ?>" href="/leads?range=<?php echo urlencode($range); ?>">All Clients</a>
    <?php foreach ($clients as $c): ?>
      <a class="btn btn-outline-secondary <?php echo ($activeClient===$c['shortcode']?'active':''); ?>" href="/leads?range=<?php echo urlencode($range); ?>&client=<?php echo urlencode($c['shortcode']); ?>"><?php echo Helpers::e($c['shortcode']); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="btn-group mb-3" role="group">
  <a class="btn btn-outline-secondary btn-sm" href="/leads?range=last_week">Last week</a>
  <a class="btn btn-outline-secondary btn-sm" href="/leads?range=last_7">Last 7 days</a>
  <a class="btn btn-outline-secondary btn-sm" href="/leads?range=last_month">Last month</a>
  <a class="btn btn-outline-secondary btn-sm" href="/leads?range=last_30">Last 30 days</a>
  <a class="btn btn-outline-secondary btn-sm" href="/leads?range=all">All time</a>
  </div>

<div class="d-flex justify-content-between mb-2">
  <div>
    <a class="btn btn-sm btn-outline-success" href="/leads/export?status=genuine&range=<?php echo urlencode($range); ?>&q=<?php echo urlencode($q ?? ''); ?>&sort=<?php echo urlencode($sort); ?><?php if(!empty($activeClient)) echo '&client='.urlencode($activeClient); ?>">Export CSV (Genuine)</a>
  </div>
  <form method="post" action="/leads/bulk" class="d-flex align-items-center">
    <?php echo App\Security\Csrf::input(); ?>
    <select name="action" class="form-select form-select-sm me-2">
      <option value="mark_genuine">Mark Genuine</option>
      <option value="mark_spam">Mark Spam</option>
      <option value="reprocess">Re-process</option>
    </select>
    <button class="btn btn-sm btn-primary">Apply to selected</button>
    <button type="button" class="btn btn-sm btn-outline-success ms-2" onclick="document.querySelectorAll('tr[data-status=genuine] input.rowcb').forEach(cb=>cb.checked=true)">Select all Genuine</button>
  </form>
</div>

<div class="table-responsive">
  <form method="post" action="/leads/bulk" id="bulkForm">
    <?php echo App\Security\Csrf::input(); ?>
  <table class="table table-sm align-middle">
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
    <?php foreach ($leads as $l): ?>
      <tr data-status="<?php echo Helpers::e($l['status']); ?>">
        <td><input class="rowcb" type="checkbox" name="ids[]" value="<?php echo (int)$l['id']; ?>"></td>
        <td><?php echo Helpers::e($l['from_email']); ?></td>
        <td><?php echo Helpers::e($l['subject']); ?></td>
        <td><?php echo Helpers::e(substr($l['body_plain'] ?? '', 0, 60)); ?></td>
        <td><?php echo Helpers::e($l['received_at']); ?></td>
        <td><?php echo (int)$l['score']; ?></td>
        <td>
          <?php if ($l['status']==='genuine'): ?><span class="badge bg-success">Genuine</span>
          <?php elseif ($l['status']==='spam'): ?><span class="badge bg-danger">Spam</span>
          <?php else: ?><span class="badge bg-secondary">Unknown</span><?php endif; ?>
        </td>
        <td><span class="badge bg-light text-dark"><?php echo Helpers::e($l['mode'] ?? ''); ?></span></td>
        <td>
          <a href="/lead/view?id=<?php echo (int)$l['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
          <form method="post" action="/leads/delete" class="d-inline" onsubmit="return confirm('Delete lead?');">
            <?php echo Csrf::input(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
            <button class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </form>
</div>

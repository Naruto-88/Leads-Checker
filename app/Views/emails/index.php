<?php use App\Helpers; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Emails</h1>
  <form class="d-flex" method="get" action="/emails">
    <input type="hidden" name="range" value="<?php echo Helpers::e($range); ?>">
    <input class="form-control form-control-sm me-2" type="search" placeholder="Search" name="q" value="<?php echo Helpers::e($q ?? ''); ?>">
    <button class="btn btn-sm btn-outline-secondary">Apply</button>
  </form>
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
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th>Sender</th>
        <th>Subject</th>
        <th>Snippet</th>
        <th>Received</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($emails as $e): ?>
      <tr>
        <td><?php echo Helpers::e($e['from_email']); ?></td>
        <td><?php echo Helpers::e($e['subject']); ?></td>
        <td><?php echo Helpers::e(substr($e['body_plain'] ?? '', 0, 60)); ?></td>
        <td><?php echo Helpers::e($e['received_at']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

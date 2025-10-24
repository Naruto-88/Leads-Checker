<?php use App\Helpers; use App\Security\Csrf; ?>
<h1 class="h4 mb-3">Lead</h1>

<div class="mb-3">
  <div><strong>From:</strong> <?php echo Helpers::e(($lead['from_name'] ? $lead['from_name'].' ' : '') . '<' . $lead['from_email'] . '>'); ?></div>
  <div><strong>Subject:</strong> <?php echo Helpers::e($lead['subject']); ?></div>
  <div><strong>Received:</strong> <?php echo Helpers::e($lead['received_at']); ?></div>
  <div><strong>Status:</strong>
    <?php if ($lead['status']==='genuine'): ?><span class="badge bg-success">Genuine</span>
    <?php elseif ($lead['status']==='spam'): ?><span class="badge bg-danger">Spam</span>
    <?php else: ?><span class="badge bg-secondary">Unknown</span><?php endif; ?>
    <span class="badge bg-light text-dark ms-1">Score: <?php echo (int)$lead['score']; ?></span>
    <span class="badge bg-light text-dark ms-1">Mode: <?php echo Helpers::e($lead['mode'] ?? ''); ?></span>
  </div>
</div>

<div class="mb-3">
  <ul class="nav nav-tabs" id="myTab" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" id="plain-tab" data-bs-toggle="tab" data-bs-target="#plain" type="button" role="tab">Plain</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="html-tab" data-bs-toggle="tab" data-bs-target="#html" type="button" role="tab">HTML</button></li>
  </ul>
  <div class="tab-content border p-3" id="myTabContent">
    <div class="tab-pane fade show active" id="plain" role="tabpanel"><pre class="mb-0" style="white-space: pre-wrap;"><?php echo Helpers::e($lead['body_plain']); ?></pre></div>
    <div class="tab-pane fade" id="html" role="tabpanel"><div class="bg-light p-2" style="max-height: 400px; overflow:auto;"><?php echo strip_tags($lead['body_html'] ?? '', '<p><br><b><strong><i><em><ul><ol><li><a>'); ?></div></div>
  </div>
</div>

<div class="mb-3">
  <form method="post" action="/lead/reprocess" class="d-inline">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <button class="btn btn-primary btn-sm">Re-process</button>
  </form>
  <form method="post" action="/lead/mark" class="d-inline ms-2">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <input type="hidden" name="status" value="genuine">
    <button class="btn btn-success btn-sm">Mark Genuine</button>
  </form>
  <form method="post" action="/lead/mark" class="d-inline ms-2">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <input type="hidden" name="status" value="spam">
    <button class="btn btn-danger btn-sm">Mark Spam</button>
  </form>
</div>

<h2 class="h6">Classification History</h2>
<div class="table-responsive">
  <table class="table table-sm">
    <thead><tr><th>Timestamp</th><th>Mode</th><th>Score</th><th>Reason</th></tr></thead>
    <tbody>
      <?php foreach ($checks as $c): ?>
      <tr>
        <td><?php echo Helpers::e($c['created_at']); ?></td>
        <td><span class="badge bg-light text-dark"><?php echo Helpers::e($c['mode']); ?></span></td>
        <td><?php echo (int)$c['score']; ?></td>
        <td><?php echo Helpers::e($c['reason']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>


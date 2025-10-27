<?php use App\Helpers; use App\Security\Csrf; $hasHtml = !empty($lead['body_html']); ?>
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
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $hasHtml ? '' : 'active'; ?>" id="plain-tab" data-bs-toggle="tab" data-bs-target="#plain" type="button" role="tab">Plain</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $hasHtml ? 'active' : ''; ?>" id="html-tab" data-bs-toggle="tab" data-bs-target="#html" type="button" role="tab">HTML</button></li>
  </ul>
  <div class="tab-content border p-3" id="myTabContent">
    <div class="tab-pane fade <?php echo $hasHtml ? '' : 'show active'; ?>" id="plain" role="tabpanel"><pre class="mb-0" style="white-space: pre-wrap;">&lt;?php echo Helpers::e($lead['body_plain'] ?: strip_tags($lead['body_html'] ?? '')); ?&gt;</pre></div>
    <div class="tab-pane fade <?php echo $hasHtml ? 'show active' : ''; ?>" id="html" role="tabpanel"><div class="bg-light p-2" style="max-height: 400px; overflow:auto;">&lt;?php echo strip_tags($lead['body_html'] ?? '', '&lt;p&gt;&lt;br&gt;&lt;b&gt;&lt;strong&gt;&lt;i&gt;&lt;em&gt;&lt;ul&gt;&lt;ol&gt;&lt;li&gt;&lt;a&gt;&lt;div&gt;&lt;span&gt;&lt;table&gt;&lt;thead&gt;&lt;tbody&gt;&lt;tr&gt;&lt;th&gt;&lt;td&gt;'); ?&gt;</div></div>
  </div>
</div>

<div class="mb-3">
  <form method="post" action="/lead/reprocess" class="d-inline" title="Re-run the filter on this lead">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <button class="btn btn-primary btn-sm" data-bs-toggle="tooltip" title="Re-run the filter on this lead">Re-process</button>
  </form>
  <form method="post" action="/lead/mark" class="d-inline ms-2" title="Mark this lead as Genuine">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <input type="hidden" name="status" value="genuine">
    <button class="btn btn-success btn-sm" data-bs-toggle="tooltip" title="Mark this lead as Genuine">Mark Genuine</button>
  </form>
  <form method="post" action="/lead/mark" class="d-inline ms-2" title="Mark this lead as Spam">
    <?php echo Csrf::input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($lead['lead_id'] ?? $lead['id']); ?>">
    <input type="hidden" name="status" value="spam">
    <button class="btn btn-danger btn-sm" data-bs-toggle="tooltip" title="Mark this lead as Spam">Mark Spam</button>
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


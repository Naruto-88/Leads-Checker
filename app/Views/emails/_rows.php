<?php use App\Helpers; ?>
<?php foreach (($emails ?? []) as $e): ?>
  <tr>
    <td><?php echo Helpers::e($e['from_email']); ?></td>
    <td><?php echo Helpers::e($e['subject']); ?>
      <?php if (!empty($seenAtPrevEmails) && !empty($e['received_at']) && $e['received_at'] > $seenAtPrevEmails): ?>
        <span class="badge bg-info text-dark ms-1" title="New since last visit">New</span>
      <?php endif; ?>
    </td>
    <td><?php $snip = $e['body_plain'] ?? ''; if ($snip==='') { $snip = strip_tags($e['body_html'] ?? ''); } echo Helpers::e(substr($snip, 0, 60)); ?></td>
    <td><?php echo Helpers::e($e['received_at']); ?></td>
  </tr>
<?php endforeach; ?>

<?php use App\Helpers; ?>
<?php foreach (($emails ?? []) as $e): ?>
  <tr>
    <td><?php echo Helpers::e($e['from_email']); ?></td>
    <td><?php echo Helpers::e($e['subject']); ?></td>
    <td><?php echo Helpers::e(substr($e['body_plain'] ?? '', 0, 60)); ?></td>
    <td><?php echo Helpers::e($e['received_at']); ?></td>
  </tr>
<?php endforeach; ?>


<?php use App\Helpers; use App\Security\Csrf; ?>
<?php foreach (($leads ?? []) as $l): ?>
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
    <td class="text-nowrap">
      <div class="d-flex align-items-center gap-2">
        <a href="#" data-id="<?php echo (int)$l['id']; ?>" class="btn btn-sm btn-outline-primary btn-view" data-bs-toggle="tooltip" title="Open lead details">View</a>
        <form method="post" action="/leads/delete" class="d-inline" onsubmit="return confirm('Delete lead?');">
          <?php echo Csrf::input(); ?>
          <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
          <button class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Delete this lead">Delete</button>
        </form>
      </div>
    </td>
  </tr>
<?php endforeach; ?>

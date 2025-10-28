<?php use App\Helpers; use App\Security\Csrf; ?>
<?php foreach (($leads ?? []) as $l): ?>
  <tr data-status="<?php echo Helpers::e($l['status']); ?>">
    <td><input class="rowcb" type="checkbox" name="ids[]" value="<?php echo (int)$l['id']; ?>"></td>
    <td><?php echo Helpers::e($l['from_email']); ?></td>
    <td><?php echo Helpers::e($l['subject']); ?>
      <?php if (!empty($seenAtPrev) && !empty($l['created_at']) && $l['created_at'] > $seenAtPrev): ?>
        <span class="badge bg-info text-dark ms-1" title="New since last visit">New</span>
      <?php endif; ?>
    </td>
    <td><?php
      $plain = (string)($l['body_plain'] ?? '');
      $html  = (string)($l['body_html'] ?? '');
      $looksHtmlPlain = ($plain !== '' && preg_match('/<[^>]+>/', $plain));
      $src = $plain !== '' ? $plain : $html;
      if ($looksHtmlPlain || ($plain === '' && $html !== '')) {
        $t = $src;
        $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
        $t = preg_replace('/<\/(p|div|li|tr|h[1-6])\s*>/i', "\n", $t);
        $t = preg_replace('/<\/(ul|ol|table|thead|tbody|tfoot)\s*>/i', "\n\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        $t = preg_replace('/[\t\x{00A0}]+/u', ' ', $t);
        // Convert newlines to separators for one-line cell
        $t = preg_replace('/\s*\n+\s*/', ' · ', trim($t));
        $snip = $t;
      } else {
        $snip = trim($src);
      }
      echo Helpers::e(mb_substr($snip, 0, 90));
    ?></td>
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

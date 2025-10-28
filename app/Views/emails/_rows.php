<?php use App\Helpers; ?>
<?php foreach (($emails ?? []) as $e): ?>
  <tr>
    <td><?php echo Helpers::e($e['from_email']); ?></td>
    <td><?php echo Helpers::e($e['subject']); ?>
      <?php if (!empty($seenAtPrevEmails) && !empty($e['received_at']) && $e['received_at'] > $seenAtPrevEmails): ?>
        <span class="badge bg-info text-dark ms-1" title="New since last visit">New</span>
      <?php endif; ?>
    </td>
    <td><?php
      $plain = (string)($e['body_plain'] ?? '');
      $html  = (string)($e['body_html'] ?? '');
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
        $t = preg_replace('/\s*\n+\s*/', ' · ', trim($t));
        $snip = $t;
      } else {
        $snip = trim($src);
      }
      echo Helpers::e(mb_substr($snip, 0, 90));
    ?></td>
    <td><?php echo Helpers::e($e['received_at']); ?></td>
  </tr>
<?php endforeach; ?>

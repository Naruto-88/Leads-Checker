<?php use App\Helpers; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Send Client Message</h1>
  <div>
    <a class="btn btn-sm btn-outline-secondary" href="/leads<?php echo !empty($activeClient)?('?client='.urlencode($activeClient)) : ''; ?>">Back to Leads</a>
  </div>
  </div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" action="/client-message" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Client</label>
        <select name="client" class="form-select">
          <option value="">All Clients</option>
          <?php foreach (($clients ?? []) as $c): ?>
            <option value="<?php echo Helpers::e($c['shortcode']); ?>" <?php echo ($activeClient===$c['shortcode']?'selected':''); ?>><?php echo Helpers::e(($c['shortcode'] ?: '') . ' - ' . $c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Range</label>
        <select name="range" class="form-select">
          <option value="today" <?php echo ($range==='today'?'selected':''); ?>>Today</option>
          <option value="yesterday" <?php echo ($range==='yesterday'?'selected':''); ?>>Yesterday</option>
          <option value="this_week" <?php echo ($range==='this_week'?'selected':''); ?>>This week</option>
          <option value="last_24" <?php echo ($range==='last_24'?'selected':''); ?>>Last 24 hours</option>
          <option value="last_7" <?php echo ($range==='last_7'?'selected':''); ?>>Last 7 days</option>
          <option value="this_month" <?php echo ($range==='this_month'?'selected':''); ?>>This month</option>
          <option value="last_30" <?php echo ($range==='last_30'?'selected':''); ?>>Last 30 days</option>
          <option value="all" <?php echo ($range==='all'?'selected':''); ?>>All time</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">CSV</label>
        <?php $qs = ['status'=>'genuine','range'=>$range]; if (!empty($activeClient)) $qs['client']=$activeClient; ?>
        <div>
          <a class="btn btn-sm btn-success" href="<?php echo '/leads/export?' . http_build_query($qs); ?>">Download Genuine Leads CSV</a>
          <div class="form-text">Attaches genuine leads for selected period.</div>
        </div>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-body">
        <input type="hidden" id="msgTo" value="<?php echo Helpers::e($to ?? ''); ?>">
        <div class="mb-2"><strong>Subject</strong>
          <input class="form-control" id="msgSubject" value="<?php echo Helpers::e($subject); ?>">
        </div>
        <div class="mb-2"><strong>Message</strong>
          <textarea class="form-control" id="msgBody" rows="10"><?php echo Helpers::e($body); ?></textarea>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-primary" id="btnMailto" href="#">Open in Email Client</a>
          <button class="btn btn-outline-secondary" type="button" id="btnCopy">Copy Message</button>
          <span class="text-muted ms-auto">Genuine leads: <strong><?php echo (int)$genuineCount; ?></strong></span>
        </div>
        <div class="form-text mt-2">Attachments are not supported via mailto links. Please attach the downloaded CSV manually.</div>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card">
      <div class="card-body">
        <div class="mb-2"><strong>Tips</strong>
          <ul class="mb-0">
            <li>Edit the subject/body before sending.</li>
            <li>Use Settings → Clients to store default recipient and sender emails.</li>
            <li>CSV includes genuine leads for the selected period.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const subj = document.getElementById('msgSubject');
  const body = document.getElementById('msgBody');
  const mailto = <?php echo json_encode($mailto); ?>;
  const toRaw = <?php echo json_encode($to ?? ''); ?>;
  const btn = document.getElementById('btnMailto');
  const btnCopy = document.getElementById('btnCopy');
  function buildMailto() {
    const subject = encodeURIComponent(subj.value || '');
    const bodyTxt = (body.value || '') + "\n\n(Please attach the CSV you downloaded from the app.)";
    const bodyEnc = encodeURIComponent(bodyTxt);
    const to = toRaw || '';
    return 'mailto:' + to + '?subject=' + subject + '&body=' + bodyEnc;
  }
  if (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      window.location.href = buildMailto();
    });
  }
  if (btnCopy) {
    btnCopy.addEventListener('click', async function () {
      try { await navigator.clipboard.writeText(body.value); btnCopy.textContent = 'Copied'; setTimeout(()=>btnCopy.textContent='Copy Message', 1200); } catch(e) {}
    });
  }
});
</script>

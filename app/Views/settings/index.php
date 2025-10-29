<?php use App\Security\Csrf; use App\Helpers; $tab = $_GET['tab'] ?? 'filter'; if(!in_array($tab, ['filter','imap','clients','general'])) { $tab = 'filter'; } ?>
<h1 class="h4 mb-3">Settings</h1>

<ul class="nav nav-tabs" id="settingsTab" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link <?php echo $tab==='filter'?'active':''; ?>" id="filter-tab" data-bs-toggle="tab" data-bs-target="#filter" type="button" role="tab">Filtering</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link <?php echo $tab==='imap'?'active':''; ?>" id="imap-tab" data-bs-toggle="tab" data-bs-target="#imap" type="button" role="tab">IMAP Accounts</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link <?php echo $tab==='clients'?'active':''; ?>" id="clients-tab" data-bs-toggle="tab" data-bs-target="#clients" type="button" role="tab">Clients</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link <?php echo $tab==='general'?'active':''; ?>" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">General</button></li>
</ul>

<div class="tab-content border p-3" id="settingsTabContent">
  <div class="tab-pane fade <?php echo $tab==='filter'?'show active':''; ?>" id="filter" role="tabpanel">
    <form method="post" action="/settings/save-filter" class="row g-3">
      <?php echo Csrf::input(); ?>
      <div class="col-md-4">
        <label class="form-label">Filtering Mode</label>
        <select class="form-select" name="filter_mode">
          <option value="algorithmic" <?php echo ($settings['filter_mode']==='algorithmic'?'selected':''); ?>>Algorithmic</option>
          <option value="gpt" <?php echo ($settings['filter_mode']==='gpt'?'selected':''); ?>>GPT (OpenAI)</option>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label">OpenAI API Key</label>
        <input type="password" name="openai_api_key" class="form-control" placeholder="sk-... (only used if mode=GPT)">
      </div>
      <div class="col-md-4 align-self-end">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="strict_gpt" id="strictGpt" <?php echo !empty($settings['strict_gpt'])?'checked':''; ?>>
          <label class="form-check-label" for="strictGpt">Strict GPT mode (no fallback; unknown on failure)</label>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Genuine threshold</label>
        <input type="number" class="form-control" name="threshold_genuine" value="<?php echo (int)($settings['filter_threshold_genuine'] ?? 70); ?>" min="0" max="100">
      </div>
      <div class="col-md-3">
        <label class="form-label">Spam threshold</label>
        <input type="number" class="form-control" name="threshold_spam" value="<?php echo (int)($settings['filter_threshold_spam'] ?? 40); ?>" min="0" max="100">
      </div>
      <div class="col-md-6">
        <label class="form-label">Positive keywords (comma or new line)</label>
        <textarea class="form-control" name="pos_keywords" rows="3" placeholder="quote, pricing, appointment, ..."><?php echo htmlspecialchars($settings['filter_pos_keywords'] ?? ''); ?></textarea>
      </div>
      <div class="col-md-12">
        <label class="form-label">Negative keywords (comma or new line)</label>
        <textarea class="form-control" name="neg_keywords" rows="3" placeholder="casino, crypto, guest post, ..."><?php echo htmlspecialchars($settings['filter_neg_keywords'] ?? ''); ?></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>

  <div class="tab-pane fade <?php echo $tab==='imap'?'show active':''; ?>" id="imap" role="tabpanel">
    <div class="row">
      <div class="col-md-7">
        <h6>Existing Accounts</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Label</th><th>Client</th><th>Server</th><th>User</th><th>Folder</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($accounts as $a): ?>
              <tr>
                <td><?php echo Helpers::e($a['label']); ?></td>
                <td><?php 
                  $clientName = ''; 
                  foreach (($clients ?? []) as $c) { if ($c['id'] == ($a['client_id'] ?? null)) { $clientName = $c['shortcode'].' - '.$c['name']; break; } }
                  echo Helpers::e($clientName);
                ?></td>
                <td><?php echo Helpers::e($a['imap_host'] . ':' . $a['imap_port'] . ' (' . $a['encryption'] . ')'); ?></td>
                <td><?php echo Helpers::e($a['username']); ?></td>
                <td><?php echo Helpers::e($a['folder']); ?></td>
                <td class="text-nowrap">
                  <div class="d-flex align-items-center gap-2">
                  <button type="button"
                          class="btn btn-sm btn-outline-primary me-2"
                          data-bs-toggle="modal"
                          data-bs-target="#editImapModal"
                          data-id="<?php echo (int)$a['id']; ?>"
                          data-label="<?php echo Helpers::e($a['label']); ?>"
                          data-client_id="<?php echo (int)($a['client_id'] ?? 0); ?>"
                          data-host="<?php echo Helpers::e($a['imap_host']); ?>"
                          data-port="<?php echo (int)$a['imap_port']; ?>"
                          data-encryption="<?php echo Helpers::e($a['encryption']); ?>"
                          data-username="<?php echo Helpers::e($a['username']); ?>"
                          data-folder="<?php echo Helpers::e($a['folder']); ?>">
                    Edit
                  </button>
                  <form method="post" action="/settings/delete-imap" class="d-inline" onsubmit="return confirm('Delete this account?');">
                    <?php echo Csrf::input(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-md-5">
        <h6>Add IMAP Account</h6>
        <form method="post" action="/settings/save-imap" class="row g-2">
          <?php echo Csrf::input(); ?>
          <div class="col-12"><label class="form-label">Label</label><input name="label" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Client</label>
            <select name="client_id" class="form-select">
              <option value="">(None)</option>
              <?php foreach (($clients ?? []) as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"><?php echo Helpers::e($c['shortcode'].' - '.$c['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-8"><label class="form-label">Host</label><input name="imap_host" class="form-control" required></div>
          <div class="col-4"><label class="form-label">Port</label><input name="imap_port" type="number" value="993" class="form-control"></div>
          <div class="col-6"><label class="form-label">Encryption</label>
            <select name="encryption" class="form-select"><option value="ssl">ssl</option><option value="tls">tls</option><option value="none">none</option></select>
          </div>
          <div class="col-6"><label class="form-label">Folder</label><input name="folder" value="INBOX" class="form-control"></div>
          <div class="col-12"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Password/App Password</label><input name="password" type="password" class="form-control" required></div>
          <div class="col-12"><button class="btn btn-primary">Add Account</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="tab-pane fade <?php echo $tab==='clients'?'show active':''; ?>" id="clients" role="tabpanel">
    <div class="row">
      <div class="col-md-7">
        <h6>Existing Clients</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Shortcode</th><th>Name</th><th>Website</th><th>Email Addresses</th><th></th></tr></thead>
            <tbody>
              <?php foreach (($clients ?? []) as $c): ?>
              <tr>
                <td><span class="badge bg-light text-dark"><?php echo Helpers::e($c['shortcode']); ?></span></td>
                <td><?php echo Helpers::e($c['name']); ?></td>
                <td><?php echo Helpers::e($c['website']); ?></td>
                <td class="small text-muted" style="max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo Helpers::e($c['contact_emails'] ?? ''); ?>"><?php echo Helpers::e($c['contact_emails'] ?? ''); ?></td>
                <td>
                  <button type="button" class="btn btn-sm btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#editClientModal" data-id="<?php echo (int)$c['id']; ?>" data-name="<?php echo Helpers::e($c['name']); ?>" data-website="<?php echo Helpers::e($c['website']); ?>" data-shortcode="<?php echo Helpers::e($c['shortcode']); ?>" data-emails="<?php echo Helpers::e($c['contact_emails'] ?? ''); ?>">Edit</button>
                  <form method="post" action="/settings/delete-client" onsubmit="return confirm('Delete client?');" class="d-inline">
                    <?php echo Csrf::input(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-md-5">
        <h6>Add Client</h6>
        <form method="post" action="/settings/save-client" class="row g-2">
          <?php echo Csrf::input(); ?>
          <div class="col-12"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Website</label><input name="website" class="form-control" placeholder="https://example.com"></div>
          <div class="col-6"><label class="form-label">Shortcode</label><input name="shortcode" class="form-control" placeholder="ABC" required></div>
          <div class="col-12"><label class="form-label">Client Email Addresses <small class="text-muted">(comma or new line)</small></label><textarea name="contact_emails" class="form-control" rows="2" placeholder="sales@example.com, info@example.com"></textarea></div>
          <div class="col-12"><button class="btn btn-primary">Add Client</button></div>
        </form>

        <h6 class="mt-4">Import Clients (CSV)</h6>
        <form method="post" action="/settings/import-clients" enctype="multipart/form-data" class="row g-2">
          <?php echo Csrf::input(); ?>
          <div class="col-12">
            <input type="file" name="clients_csv" accept=".csv" class="form-control" required>
            <div class="form-text">CSV columns: name, website, shortcode. A header row is optional.</div>
          </div>
          <div class="col-12"><button class="btn btn-outline-secondary">Upload CSV</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="tab-pane fade <?php echo $tab==='general'?'show active':''; ?>" id="general" role="tabpanel">
    <form method="post" action="/settings/save-general" class="row g-3">
      <?php echo Csrf::input(); ?>
      <div class="col-md-6">
        <label class="form-label">Timezone</label>
        <input type="text" class="form-control" name="timezone" value="<?php echo Helpers::e($settings['timezone']); ?>" placeholder="UTC or Continent/City">
      </div>
      <div class="col-md-6">
        <label class="form-label">Page Size</label>
        <input type="number" class="form-control" name="page_size" value="<?php echo (int)$settings['page_size']; ?>" min="5" max="200">
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/settings/update-client">
        <div class="modal-body">
          <?php echo Csrf::input(); ?>
          <input type="hidden" name="id" id="editClientId">
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" id="editClientName" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Website</label>
            <input type="text" class="form-control" name="website" id="editClientWebsite" placeholder="https://example.com">
          </div>
          <div class="mb-2">
            <label class="form-label">Shortcode</label>
            <input type="text" class="form-control" name="shortcode" id="editClientShortcode" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Client Email Addresses <small class="text-muted">(comma or new line)</small></label>
            <textarea class="form-control" name="contact_emails" id="editClientEmails" rows="2" placeholder="sales@example.com, info@example.com"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var editModal = document.getElementById('editClientModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      var id = button.getAttribute('data-id');
      var name = button.getAttribute('data-name');
      var website = button.getAttribute('data-website') || '';
      var shortcode = button.getAttribute('data-shortcode');
      var emails = button.getAttribute('data-emails') || '';
      document.getElementById('editClientId').value = id;
      document.getElementById('editClientName').value = name;
      document.getElementById('editClientWebsite').value = website;
      document.getElementById('editClientShortcode').value = shortcode;
      var em = document.getElementById('editClientEmails'); if (em) em.value = emails;
    });
  }

  var editImapModal = document.getElementById('editImapModal');
  if (editImapModal) {
    editImapModal.addEventListener('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      document.getElementById('editImapId').value = button.getAttribute('data-id');
      document.getElementById('editImapLabel').value = button.getAttribute('data-label');
      document.getElementById('editImapHost').value = button.getAttribute('data-host');
      document.getElementById('editImapPort').value = button.getAttribute('data-port');
      document.getElementById('editImapEncryption').value = button.getAttribute('data-encryption');
      document.getElementById('editImapUsername').value = button.getAttribute('data-username');
      document.getElementById('editImapFolder').value = button.getAttribute('data-folder');
      var clientId = button.getAttribute('data-client_id');
      var sel = document.getElementById('editImapClient');
      if (sel) { sel.value = clientId && clientId !== '0' ? clientId : ''; }
      // Clear password field each open
      var pwd = document.getElementById('editImapPassword');
      if (pwd) { pwd.value = ''; }
    });
  }
});
</script>

<!-- Edit IMAP Account Modal -->
<div class="modal fade" id="editImapModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Email Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/settings/update-imap" class="row g-2 mx-0">
        <div class="modal-body">
          <?php echo Csrf::input(); ?>
          <input type="hidden" name="id" id="editImapId">
          <div class="row g-2">
            <div class="col-12"><label class="form-label">Label</label><input name="label" id="editImapLabel" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Client</label>
              <select name="client_id" id="editImapClient" class="form-select">
                <option value="">(None)</option>
                <?php foreach (($clients ?? []) as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>"><?php echo Helpers::e($c['shortcode'].' - '.$c['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-8"><label class="form-label">Host</label><input name="imap_host" id="editImapHost" class="form-control" required></div>
            <div class="col-4"><label class="form-label">Port</label><input name="imap_port" id="editImapPort" type="number" value="993" class="form-control"></div>
            <div class="col-6"><label class="form-label">Encryption</label>
              <select name="encryption" id="editImapEncryption" class="form-select"><option value="ssl">ssl</option><option value="tls">tls</option><option value="none">none</option></select>
            </div>
            <div class="col-6"><label class="form-label">Folder</label><input name="folder" id="editImapFolder" value="INBOX" class="form-control"></div>
            <div class="col-12"><label class="form-label">Username</label><input name="username" id="editImapUsername" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Password/App Password <small class="text-muted">(leave blank to keep existing)</small></label><input name="password" id="editImapPassword" type="password" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

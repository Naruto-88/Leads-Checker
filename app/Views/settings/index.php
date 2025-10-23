<?php use App\Security\Csrf; use App\Helpers; ?>
<h1 class="h4 mb-3">Settings</h1>

<ul class="nav nav-tabs" id="settingsTab" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" id="filter-tab" data-bs-toggle="tab" data-bs-target="#filter" type="button" role="tab">Filtering</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="imap-tab" data-bs-toggle="tab" data-bs-target="#imap" type="button" role="tab">IMAP Accounts</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="clients-tab" data-bs-toggle="tab" data-bs-target="#clients" type="button" role="tab">Clients</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">General</button></li>
</ul>

<div class="tab-content border p-3" id="settingsTabContent">
  <div class="tab-pane fade show active" id="filter" role="tabpanel">
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
      <div class="col-12">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>

  <div class="tab-pane fade" id="imap" role="tabpanel">
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
                <td>
                  <form method="post" action="/settings/delete-imap" onsubmit="return confirm('Delete this account?');">
                    <?php echo Csrf::input(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
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

  <div class="tab-pane fade" id="clients" role="tabpanel">
    <div class="row">
      <div class="col-md-7">
        <h6>Existing Clients</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Shortcode</th><th>Name</th><th>Website</th><th></th></tr></thead>
            <tbody>
              <?php foreach (($clients ?? []) as $c): ?>
              <tr>
                <td><span class="badge bg-light text-dark"><?php echo Helpers::e($c['shortcode']); ?></span></td>
                <td><?php echo Helpers::e($c['name']); ?></td>
                <td><?php echo Helpers::e($c['website']); ?></td>
                <td>
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
          <div class="col-12"><button class="btn btn-primary">Add Client</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="general" role="tabpanel">
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

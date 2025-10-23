<?php use App\Helpers; use App\Security\Csrf; ?>
<h1 class="h4 mb-3">Users</h1>
<div class="card mb-3">
  <div class="card-body">
    <h6 class="card-title">Add User</h6>
    <form method="post" action="/admin/users/create" class="row g-2">
      <?php echo App\Security\Csrf::input(); ?>
      <div class="col-md-5"><input type="email" name="email" class="form-control" placeholder="Email" required></div>
      <div class="col-md-5"><input type="password" name="password" class="form-control" placeholder="Password (min 8)" required></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Create</button></div>
    </form>
  </div>
</div>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr><th>Email</th><th>Role</th><th>Created</th><th>Last Login</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?php echo Helpers::e($u['email']); ?></td>
        <td><span class="badge bg-light text-dark"><?php echo Helpers::e($u['role']); ?></span></td>
        <td><?php echo Helpers::e($u['created_at']); ?></td>
        <td><?php echo Helpers::e($u['last_login_at']); ?></td>
        <td>
          <form method="post" action="/admin/users/reset-pass" class="d-inline"> <?php echo Csrf::input(); ?><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-outline-secondary">Reset Password</button></form>
          <?php if ($u['role']==='user'): ?>
            <form method="post" action="/admin/users/promote" class="d-inline ms-1"> <?php echo Csrf::input(); ?><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-outline-primary">Promote</button></form>
          <?php else: ?>
            <form method="post" action="/admin/users/demote" class="d-inline ms-1"> <?php echo Csrf::input(); ?><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-outline-warning">Demote</button></form>
          <?php endif; ?>
          <form method="post" action="/admin/users/delete" class="d-inline ms-1" onsubmit="return confirm('Delete user?');"> <?php echo Csrf::input(); ?><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-info mt-3"><?php echo Helpers::e($_SESSION['flash']); unset($_SESSION['flash']); ?></div><?php endif; ?>

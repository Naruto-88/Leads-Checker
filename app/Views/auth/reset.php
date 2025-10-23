<?php use App\Security\Csrf; use App\Helpers; ?>
<div class="row justify-content-center">
  <div class="col-md-4">
    <h1 class="h4 mb-3">Reset Password</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo Helpers::e($error); ?></div><?php endif; ?>
    <form method="post">
      <?php echo Csrf::input(); ?>
      <input type="hidden" name="token" value="<?php echo Helpers::e($token ?? ''); ?>">
      <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="password" class="form-control" required></div>
      <button class="btn btn-primary">Reset</button>
    </form>
  </div>
</div>


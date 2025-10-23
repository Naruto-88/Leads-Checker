<?php use App\Security\Csrf; use App\Helpers; ?>
<div class="row justify-content-center">
  <div class="col-md-4">
    <h1 class="h4 mb-3">Login</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo Helpers::e($error); ?></div><?php endif; ?>
    <form method="post">
      <?php echo Csrf::input(); ?>
      <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
      <div class="mb-3 d-flex justify-content-between align-items-center">
        <button class="btn btn-primary">Login</button>
        <a href="/auth/forgot" class="small">Forgot password?</a>
      </div>
    </form>
  </div>
</div>


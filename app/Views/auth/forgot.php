<?php use App\Security\Csrf; use App\Helpers; ?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <h1 class="h4 mb-3">Forgot Password</h1>
    <?php if (!empty($info)): ?><div class="alert alert-info"><?php echo Helpers::e($info); ?></div><?php endif; ?>
    <form method="post">
      <?php echo Csrf::input(); ?>
      <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
      <button class="btn btn-primary">Generate Reset Link</button>
    </form>
  </div>
</div>


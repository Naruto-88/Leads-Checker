<?php use App\Security\Auth; use App\Security\Csrf; use App\Helpers; $user = Auth::user(); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Real Leads Checker</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">Real Leads Checker</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarsExample" aria-controls="navbarsExample" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarsExample">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($user): ?>
          <li class="nav-item"><a class="nav-link" href="/">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="/leads">Leads</a></li>
          <li class="nav-item"><a class="nav-link" href="/emails">Emails</a></li>
          <li class="nav-item"><a class="nav-link" href="/settings">Settings</a></li>
          <?php if ($user['role']==='admin'): ?>
          <li class="nav-item"><a class="nav-link" href="/admin/users">Admin</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php if ($user): ?>
          <li class="nav-item"><span class="navbar-text me-2"><?php echo Helpers::e($user['email']); ?></span></li>
          <li class="nav-item">
            <form method="post" action="/auth/logout" class="d-inline">
              <?php echo Csrf::input(); ?>
              <button class="btn btn-outline-light btn-sm">Logout</button>
            </form>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="/auth/login">Login</a></li>
          <li class="nav-item"><a class="nav-link" href="/auth/register">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  </nav>

<main class="container my-4">
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-info"><?php echo Helpers::e($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
<?php endif; ?>


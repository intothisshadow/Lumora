<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Login
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

// Already logged in → go to dashboard.
if (lumora_is_admin()) {
    lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
}

$error    = '';
$redirect = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
$csrf     = lumora_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $user = lumora_login(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? ''
    );
    if ($user && $user['role'] === 'admin') {
        $dest = ($redirect && str_starts_with($redirect, '/')) ? $redirect : lumora_base_url() . 'admin/dashboard.php';
        lumora_redirect($dest);
    } else {
        // If user exists but is not admin, still show generic error.
        $error = 'Invalid username or password.';
    }
}

$base_url = h(lumora_base_url());
$err_html = $error ? '<div class="alert alert-danger py-2">' . h($error) . '</div>' : '';
$csrf_h   = h($csrf);
$ver      = LUMORA_VERSION;
$gal_name = h(lumora_config('gallery_name', 'Lumora Gallery'));
$redir_h  = h($redirect);

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — {$gal_name}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$base_url}admin/admin.css">
  <style>
    body { background:#f0f2f5; }
    .login-card { max-width: 400px; margin: 5rem auto; }
    .login-header { background:#1a1a2e; color:#fff; padding:1.5rem; border-radius:.5rem .5rem 0 0; }
    .login-header h1 { font-size:1.3rem; margin:0; }
  </style>
</head>
<body>
<div class="login-card card shadow-sm">
  <div class="login-header">
    <h1>⚡ Lumora Admin</h1>
    <small class="opacity-75">{$gal_name}</small>
  </div>
  <div class="card-body p-4">
    {$err_html}
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="{$csrf_h}">
      <input type="hidden" name="redirect"   value="{$redir_h}">
      <div class="mb-3">
        <label class="form-label fw-semibold">Username</label>
        <input type="text" name="username" class="form-control" autofocus autocomplete="username" required>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Password</label>
        <input type="password" name="password" class="form-control" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Log In</button>
    </form>
  </div>
  <div class="card-footer text-center text-muted small py-2">
    <a href="{$base_url}">← Back to gallery</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;

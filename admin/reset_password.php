<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Reset Password
 *
 * Validates the time-limited split token supplied in the reset URL and allows
 * the admin to set a new password. The token is consumed (deleted) immediately
 * after a successful password change so it cannot be reused.
 *
 * Entry point: admin/forgot_password.php generates the URL
 *   admin/reset_password.php?token=<selector>:<validator>
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

// Already logged in → go to dashboard.
if (lumora_is_admin()) {
    lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
}

$base_url  = lumora_base_url();
$login_url = $base_url . 'admin/login.php';
$gal_name  = lumora_config('gallery_name', 'Lumora Gallery');

// ── Parse token from URL ──────────────────────────────────────────────────────
$raw_token = trim(filter_var($_GET['token'] ?? '', FILTER_DEFAULT));
$selector  = '';
$validator = '';

if ($raw_token !== '') {
    $parts = explode(':', $raw_token, 2);
    if (count($parts) === 2) {
        [$selector, $validator] = $parts;
    }
}

// ── Verify token ──────────────────────────────────────────────────────────────
$user_id = lumora_verify_reset_token($selector, $validator);

$token_invalid = ($user_id === null);

// ── POST: change password ─────────────────────────────────────────────────────
$pw_error   = '';
$pw_success = false;

if (!$token_invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if (strlen($new_pw) < 8) {
        $pw_error = 'Password must be at least 8 characters long.';
    } elseif ($new_pw !== $confirm_pw) {
        $pw_error = 'Passwords do not match.';
    } else {
        if (lumora_change_password($user_id, $new_pw)) {
            // Consume the token so it cannot be reused.
            lumora_consume_reset_token($selector);
            // Also clean up any remember-me tokens to force fresh login.
            lumora_clear_remember_tokens($user_id);
            // Delete the recovery file now that the reset is complete.
            $recovery_path = LUMORA_ROOT . 'lumora_recovery.txt';
            if (is_file($recovery_path)) {
                unlink($recovery_path);
            }
            $pw_success = true;
        } else {
            $pw_error = 'Password change failed. Please try again.';
        }
    }
}

// ── Build page HTML ───────────────────────────────────────────────────────────
$base_url_h  = h($base_url);
$login_url_h = h($login_url);
$gal_name_h  = h($gal_name);
$csrf_h      = h(lumora_csrf_token());
$token_h     = h($raw_token);

if ($token_invalid) {
    $body_html = <<<HTML
<div class="alert alert-danger py-2">
  <strong>This reset link is invalid or has expired.</strong><br>
  Reset links are valid for 1 hour and can only be used once.
</div>
<a href="{$base_url_h}admin/forgot_password.php" class="btn btn-primary w-100 mt-1">
  Request a New Reset Link
</a>
HTML;
} elseif ($pw_success) {
    $body_html = <<<HTML
<div class="alert alert-success py-2">
  <strong>Password changed successfully.</strong><br>
  You can now log in with your new password.
</div>
<a href="{$login_url_h}" class="btn btn-primary w-100 mt-1">← Log In</a>
HTML;
} else {
    $err_html = $pw_error !== ''
        ? '<div class="alert alert-danger py-2">' . h($pw_error) . '</div>'
        : '';

    $body_html = <<<HTML
<p class="text-muted small mb-3">Enter and confirm your new password.</p>
{$err_html}
<form method="post" action="" autocomplete="off">
  <input type="hidden" name="csrf_token" value="{$csrf_h}">
  <input type="hidden" name="token"      value="{$token_h}">
  <div class="mb-3">
    <label class="form-label fw-semibold" for="lum-new-pw">New Password</label>
    <input type="password" id="lum-new-pw" name="new_password"
           class="form-control" autocomplete="new-password"
           required minlength="8" autofocus>
    <div class="form-text">Minimum 8 characters.</div>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold" for="lum-confirm-pw">Confirm New Password</label>
    <input type="password" id="lum-confirm-pw" name="confirm_password"
           class="form-control" autocomplete="new-password"
           required minlength="8">
    <div id="lum-pw-match-msg" class="form-text"></div>
  </div>
  <button type="submit" class="btn btn-primary w-100">Set New Password</button>
</form>
<script>
(function () {
  'use strict';
  var np  = document.getElementById('lum-new-pw');
  var cp  = document.getElementById('lum-confirm-pw');
  var msg = document.getElementById('lum-pw-match-msg');
  function checkMatch() {
    if (!cp.value) { msg.textContent = ''; cp.setCustomValidity(''); return; }
    if (np.value === cp.value) {
      msg.textContent = '✓ Passwords match';
      msg.className   = 'form-text text-success';
      cp.setCustomValidity('');
    } else {
      msg.textContent = '✗ Passwords do not match';
      msg.className   = 'form-text text-danger';
      cp.setCustomValidity('Passwords do not match');
    }
  }
  np.addEventListener('input', checkMatch);
  cp.addEventListener('input', checkMatch);
}());
</script>
HTML;
}

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password — {$gal_name_h}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$base_url_h}admin/admin.css">
  <style>
    body { background:#f0f2f5; }
    .login-card { max-width: 420px; margin: 5rem auto; }
    .login-header { background:#1a1a2e; color:#fff; padding:1.5rem; border-radius:.5rem .5rem 0 0; }
    .login-header h1 { font-size:1.3rem; margin:0; }
  </style>
</head>
<body>
<div class="login-card card shadow-sm">
  <div class="login-header">
    <h1>⚡ Lumora Gallery Admin</h1>
    <small class="opacity-75">{$gal_name_h}</small>
  </div>
  <div class="card-body p-4">
    <h2 class="h5 mb-3">Reset Password</h2>
    {$body_html}
  </div>
  <div class="card-footer text-center text-muted small py-2">
    <a href="{$login_url_h}">← Back to Login</a>
    &nbsp;·&nbsp;
    <a href="{$base_url_h}">View Gallery</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;

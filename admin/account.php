<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Account Management
 *
 * Allows the logged-in admin to:
 *   - Update their username and email address.
 *   - Change their password (requires current password verification).
 *
 * Security note: a 500 ms constant-time delay is enforced on any failed
 * current-password verification to make automated brute-forcing slower.
 *
 * V1 is single-user, but the page reads/writes the `users` table so it will
 * continue to work correctly when multi-user support is added in future.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Resolve current user from DB ──────────────────────────────────────────────
$session_data = lumora_current_user();
$user_id      = (int) ($session_data['user_id'] ?? 0);

$user = LumoraDB::fetchOne(
    'SELECT id, username, email, role, last_login, created_at FROM `{PREFIX}users` WHERE id = ?',
    [$user_id]
);

if (!$user) {
    // Session refers to a deleted user — force log out.
    lumora_logout();
    lumora_redirect(lumora_base_url() . 'admin/login.php');
}

$base   = lumora_base_url() . 'admin/account.php';
$base_h = h($base);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $action = trim($_POST['action'] ?? '');

    // ── Update profile (username + email) ─────────────────────────────────────
    if ($action === 'profile') {
        $new_username = trim($_POST['username'] ?? '');
        $new_email    = trim($_POST['email']    ?? '');
        $errors       = [];

        if ($new_username === '') {
            $errors[] = 'Username cannot be empty.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.\\-]{2,50}$/', $new_username)) {
            $errors[] = 'Username may only contain letters, digits, underscores, hyphens, '
                      . 'and dots, and must be 2–50 characters.';
        }

        if ($new_email !== '' && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address format.';
        }

        // Username uniqueness check (exclude the current user's own row).
        if (empty($errors) && $new_username !== $user['username']) {
            $taken = LumoraDB::fetchOne(
                'SELECT id FROM `{PREFIX}users` WHERE username = ? AND id != ?',
                [$new_username, $user_id]
            );
            if ($taken) {
                $errors[] = 'That username is already taken.';
            }
        }

        if (empty($errors)) {
            LumoraDB::update(
                'users',
                ['username' => $new_username, 'email' => $new_email],
                'id = ?',
                [$user_id]
            );
            // Keep session username in sync.
            $_SESSION[LUMORA_SESSION_KEY]['username'] = $new_username;
            lum_flash('Account details updated successfully.');
        } else {
            foreach ($errors as $err) {
                lum_flash($err, 'danger');
            }
        }

        lumora_redirect($base);
    }

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'password') {
        $current_pw = $_POST['current_password']  ?? '';
        $new_pw     = $_POST['new_password']       ?? '';
        $confirm_pw = $_POST['confirm_password']   ?? '';
        $errors     = [];

        // Fetch password hash fresh from DB — do not trust session.
        $row = LumoraDB::fetchOne(
            'SELECT password_hash FROM `{PREFIX}users` WHERE id = ?',
            [$user_id]
        );

        if (!$row || !password_verify($current_pw, $row['password_hash'])) {
            // 500 ms constant-time delay on verify failure to slow brute force
            // against the current-password field.
            usleep(500_000);
            $errors[] = 'Current password is incorrect.';
        }

        if (strlen($new_pw) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }

        if ($new_pw !== $confirm_pw) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            lumora_change_password($user_id, $new_pw);
            lum_flash('Password changed successfully.');
        } else {
            foreach ($errors as $err) {
                lum_flash($err, 'danger');
            }
        }

        lumora_redirect($base);
    }
}

// ── Build page ────────────────────────────────────────────────────────────────
// Re-read from DB so the form always shows the current persisted values,
// not possibly stale POST data.
$user = LumoraDB::fetchOne(
    'SELECT id, username, email, role, last_login, created_at FROM `{PREFIX}users` WHERE id = ?',
    [$user_id]
) ?? $user;

$csrf          = h(lumora_csrf_token());
$username_h    = h($user['username']);
$email_h       = h($user['email'] ?? '');
$role_h        = h(ucfirst((string) ($user['role'] ?? 'admin')));
$last_login_h  = ($user['last_login'] ?? '') !== '' ? h($user['last_login']) : '<em class="text-muted">never</em>';
$created_at_h  = h($user['created_at']);

$content = <<<HTML
<div class="row g-4">

  <!-- ── Profile ──────────────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="lum-adm-card h-100">
      <h5 class="mb-1">Profile Details</h5>
      <p class="text-muted small mb-3">Update your username or email address.</p>

      <form method="post" action="{$base_h}">
        <input type="hidden" name="action"     value="profile">
        <input type="hidden" name="csrf_token" value="{$csrf}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-username">Username</label>
          <input type="text" id="lum-username" name="username"
                 value="{$username_h}" class="form-control"
                 required pattern="[a-zA-Z0-9_.\-]{2,50}"
                 title="Letters, digits, underscores, hyphens, and dots (2–50 characters)"
                 autocomplete="username">
          <div class="form-text">
            Letters, digits, <code>_</code> <code>-</code> <code>.</code> — 2 to 50 characters.
          </div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold" for="lum-email">
            Email Address <span class="text-muted fw-normal">(optional)</span>
          </label>
          <input type="email" id="lum-email" name="email"
                 value="{$email_h}" class="form-control"
                 autocomplete="email">
        </div>

        <button type="submit" class="btn btn-primary">Save Profile</button>
      </form>

      <hr class="my-4">

      <dl class="row mb-0 small">
        <dt class="col-sm-5 text-muted">Role</dt>
        <dd class="col-sm-7">{$role_h}</dd>
        <dt class="col-sm-5 text-muted">Last Login</dt>
        <dd class="col-sm-7">{$last_login_h}</dd>
        <dt class="col-sm-5 text-muted">Account Created</dt>
        <dd class="col-sm-7">{$created_at_h}</dd>
      </dl>
    </div>
  </div>

  <!-- ── Change Password ──────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="lum-adm-card h-100">
      <h5 class="mb-1">Change Password</h5>
      <p class="text-muted small mb-3">Enter your current password to authorise the change.</p>

      <form method="post" action="{$base_h}" autocomplete="off">
        <input type="hidden" name="action"     value="password">
        <input type="hidden" name="csrf_token" value="{$csrf}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-cur-pw">Current Password</label>
          <input type="password" id="lum-cur-pw" name="current_password"
                 class="form-control" autocomplete="current-password" required>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-new-pw">New Password</label>
          <input type="password" id="lum-new-pw" name="new_password"
                 class="form-control" autocomplete="new-password"
                 required minlength="8">
          <div class="form-text">Minimum 8 characters.</div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold" for="lum-confirm-pw">Confirm New Password</label>
          <input type="password" id="lum-confirm-pw" name="confirm_password"
                 class="form-control" autocomplete="new-password"
                 required minlength="8">
          <div id="lum-pw-match-msg" class="form-text"></div>
        </div>

        <button type="submit" class="btn btn-warning">Change Password</button>
      </form>
    </div>
  </div>

</div>

<script>
(function () {
  'use strict';
  var np  = document.getElementById('lum-new-pw');
  var cp  = document.getElementById('lum-confirm-pw');
  var msg = document.getElementById('lum-pw-match-msg');

  function checkMatch() {
    if (!cp.value) {
      msg.textContent = '';
      cp.setCustomValidity('');
      return;
    }
    if (np.value === cp.value) {
      msg.textContent  = '✓ Passwords match';
      msg.className    = 'form-text text-success';
      cp.setCustomValidity('');
    } else {
      msg.textContent  = '✗ Passwords do not match';
      msg.className    = 'form-text text-danger';
      cp.setCustomValidity('Passwords do not match');
    }
  }

  np.addEventListener('input', checkMatch);
  cp.addEventListener('input', checkMatch);
}());
</script>
HTML;

lum_admin_page('Account', $content, 'account');

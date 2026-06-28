<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: User Management
 *
 * Allows administrators to:
 *   - List all staff accounts (paginated, 10/25/50 per page)
 *   - Create new accounts (admin, moderator, contributor)
 *   - Edit username, email, and role
 *   - Reset any account's password (no current-password check required)
 *   - Enable or disable accounts
 *   - Delete accounts
 *
 * Security:
 *   - All POST actions require a valid CSRF token.
 *   - Self-deletion and self-deactivation are blocked server-side (UserService).
 *   - The last active administrator account cannot be deleted or deactivated.
 *   - Requires Migration0003 (DB version 9) for the is_active column and
 *     updated role ENUM; a warning with a migration link is shown if pending.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$current_user    = lumora_current_user();
$current_user_id = (int) ($current_user['user_id'] ?? 0);
$base            = lumora_base_url() . 'admin/users.php';
$base_h          = h($base);
$csrf_h          = h(lumora_csrf_token());

// ── POST: handle all write actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $action = trim($_POST['action'] ?? '');

    switch ($action) {

        case 'create':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';
            $email    = trim($_POST['email'] ?? '');
            $role     = trim($_POST['role'] ?? '');

            if ($password !== $confirm) {
                lum_flash('Passwords do not match.', 'danger');
                lumora_redirect($base . '?action=new');
            }

            $result = UserService::createUser($username, $password, $email, $role);
            if (is_int($result)) {
                lum_flash('User "' . h($username) . '" created successfully.');
                lumora_redirect($base);
            }
            lum_flash((string) $result, 'danger');
            lumora_redirect($base . '?action=new');
            break;

        case 'update':
            $uid      = lumora_int($_POST['user_id'] ?? 0, 0, 1);
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = trim($_POST['role'] ?? '');

            if ($uid <= 0) {
                lum_flash('Invalid user.', 'danger');
                lumora_redirect($base);
            }

            // Prevent the current admin from changing their own role via this form.
            $data = ['username' => $username, 'email' => $email];
            if ($uid !== $current_user_id) {
                $data['role'] = $role;
            }

            $result = UserService::updateUser($uid, $data);
            if ($result === true) {
                // Keep the session username in sync when editing own account.
                if ($uid === $current_user_id) {
                    $_SESSION[LUMORA_SESSION_KEY]['username'] = $username;
                }
                lum_flash('User updated successfully.');
            } else {
                lum_flash((string) $result, 'danger');
            }
            lumora_redirect($base . '?action=edit&id=' . $uid);
            break;

        case 'reset_password':
            $uid      = lumora_int($_POST['user_id'] ?? 0, 0, 1);
            $password = $_POST['new_password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            if ($uid <= 0) {
                lum_flash('Invalid user.', 'danger');
                lumora_redirect($base);
            }
            if ($password !== $confirm) {
                lum_flash('Passwords do not match.', 'danger');
                lumora_redirect($base . '?action=edit&id=' . $uid);
            }

            $result = UserService::resetPassword($uid, $password);
            if ($result === true) {
                lum_flash('Password reset successfully.');
            } else {
                lum_flash((string) $result, 'danger');
            }
            lumora_redirect($base . '?action=edit&id=' . $uid);
            break;

        case 'toggle_active':
            $uid       = lumora_int($_POST['user_id'] ?? 0, 0, 1);
            $new_state = (bool) lumora_int($_POST['is_active'] ?? 1, 1, 0, 1);

            if ($uid <= 0) {
                lum_flash('Invalid user.', 'danger');
                lumora_redirect($base);
            }

            $result = UserService::setActive($uid, $new_state, $current_user_id);
            if ($result === true) {
                lum_flash('Account ' . ($new_state ? 'enabled' : 'disabled') . ' successfully.');
            } else {
                lum_flash((string) $result, 'danger');
            }
            lumora_redirect($base);
            break;

        case 'delete':
            $uid = lumora_int($_POST['user_id'] ?? 0, 0, 1);

            if ($uid <= 0) {
                lum_flash('Invalid user.', 'danger');
                lumora_redirect($base);
            }

            $result = UserService::deleteUser($uid, $current_user_id);
            if ($result === true) {
                lum_flash('User account deleted.');
            } else {
                lum_flash((string) $result, 'danger');
            }
            lumora_redirect($base);
            break;

        default:
            lumora_redirect($base);
    }
}

// ── GET: determine which view to render ──────────────────────────────────────
$view_action = trim($_GET['action'] ?? '');
$edit_id     = lumora_int($_GET['id'] ?? 0, 0, 1);

// ── Migration guard ───────────────────────────────────────────────────────────
// Migration0003 adds is_active and updates the role ENUM; must be applied
// before the Users page can function. Show a friendly prompt if it hasn't run.
if (in_array('Migration0003_UpdateUsersTableForRoles', SchemaService::getPendingMigrations(), true)) {
    $upd_h   = h(lumora_base_url() . 'admin/update.php');
    $content = '<div class="alert alert-warning">'
             . '<strong>⚠ Database update required</strong><br>'
             . 'The User Management feature requires a schema update (Migration 0003) '
             . 'that has not yet been applied. Please run pending migrations first.'
             . '<div class="mt-2">'
             . '<a href="' . $upd_h . '" class="btn btn-warning btn-sm">🗄 Run Database Update</a>'
             . '</div></div>';
    lum_admin_page('Users', $content, 'users');
}

// ── Pagination preferences ────────────────────────────────────────────────────
$per_page_opts = [10, 25, 50];
if (isset($_GET['per_page'])) {
    $pp = lumora_int($_GET['per_page'], 10, 1, 100);
    if (in_array($pp, $per_page_opts, true)) {
        $_SESSION['lum_adm_per_page_users'] = $pp;
    }
}
$per_page = (int) ($_SESSION['lum_adm_per_page_users'] ?? 10);
if (!in_array($per_page, $per_page_opts, true)) {
    $per_page = 10;
}
$page = lumora_int($_GET['page'] ?? 1, 1, 1);

// ════════════════════════════════════════════════════════════════════════════
// VIEW: Create User
// ════════════════════════════════════════════════════════════════════════════
if ($view_action === 'new') {

    $role_opts = UserService::roleOptions('contributor');

    $content = <<<HTML
<div class="mb-3">
  <a href="{$base_h}" class="btn btn-sm btn-outline-secondary">← Back to Users</a>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="lum-adm-card">
      <h5 class="mb-1">Create New Staff Account</h5>
      <p class="text-muted small mb-3">Staff accounts can assist with gallery administration based on their assigned role.</p>

      <form method="post" action="{$base_h}" autocomplete="off">
        <input type="hidden" name="action"     value="create">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-un">Username</label>
          <input type="text" id="lum-un" name="username" class="form-control"
                 required pattern="[a-zA-Z0-9_.\-]{2,50}"
                 title="Letters, digits, underscores, hyphens, dots (2–50 characters)"
                 autocomplete="off">
          <div class="form-text">Letters, digits, <code>_</code> <code>-</code> <code>.</code> — 2–50 characters.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-em">
            Email <span class="text-muted fw-normal">(optional)</span>
          </label>
          <input type="email" id="lum-em" name="email" class="form-control" autocomplete="off">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-role">Role</label>
          <select id="lum-role" name="role" class="form-select">
            {$role_opts}
          </select>
          <div class="form-text" id="lum-role-desc"></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-pw">Password</label>
          <input type="password" id="lum-pw" name="password" class="form-control"
                 required minlength="8" autocomplete="new-password">
          <div class="form-text">Minimum 8 characters.</div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold" for="lum-cpw">Confirm Password</label>
          <input type="password" id="lum-cpw" name="confirm_password" class="form-control"
                 required minlength="8" autocomplete="new-password">
          <div id="lum-pw-msg" class="form-text"></div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">Create Account</button>
          <a href="{$base_h}" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="lum-adm-card">
      <h5 class="mb-3">Role Reference</h5>
      <dl class="mb-0">
        <dt><span class="badge bg-danger">Administrator</span></dt>
        <dd class="small text-muted mt-1 mb-3">Full access — site configuration, user management, all gallery and admin functions.</dd>
        <dt><span class="badge bg-warning text-dark">Moderator</span></dt>
        <dd class="small text-muted mt-1 mb-3">Manage albums, images, and moderate comments. Approved maintenance tools. No site configuration or user management.</dd>
        <dt><span class="badge bg-secondary">Contributor</span></dt>
        <dd class="small text-muted mt-1 mb-0">Upload images and manage own uploads and assigned albums. No administrative access.</dd>
      </dl>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var pw   = document.getElementById('lum-pw');
  var cpw  = document.getElementById('lum-cpw');
  var msg  = document.getElementById('lum-pw-msg');
  function checkPw() {
    if (!cpw.value) { msg.textContent = ''; cpw.setCustomValidity(''); return; }
    if (pw.value === cpw.value) {
      msg.textContent = '\u2713 Passwords match'; msg.className = 'form-text text-success';
      cpw.setCustomValidity('');
    } else {
      msg.textContent = '\u2717 Passwords do not match'; msg.className = 'form-text text-danger';
      cpw.setCustomValidity('Passwords do not match');
    }
  }
  pw.addEventListener('input', checkPw);
  cpw.addEventListener('input', checkPw);

  var ROLE_DESC = {
    'admin':       'Full access to all gallery functions including site configuration and user management.',
    'moderator':   'Can manage albums and images, moderate comments, and run approved maintenance tools.',
    'contributor': 'Can upload images and manage own uploads and assigned albums. No admin access.'
  };
  var sel  = document.getElementById('lum-role');
  var desc = document.getElementById('lum-role-desc');
  function updateDesc() { desc.textContent = ROLE_DESC[sel.value] || ''; }
  sel.addEventListener('change', updateDesc);
  updateDesc();
}());
</script>
HTML;

    lum_admin_page('Create User', $content, 'users');
}

// ════════════════════════════════════════════════════════════════════════════
// VIEW: Edit User
// ════════════════════════════════════════════════════════════════════════════
if ($view_action === 'edit' && $edit_id > 0) {

    $u = UserService::getUser($edit_id);
    if (!$u) {
        lum_flash('User not found.', 'danger');
        lumora_redirect($base);
    }

    $is_self   = ($edit_id === $current_user_id);
    $u_id      = (int) $u['id'];
    $u_name_h  = h($u['username']);
    $u_email_h = h($u['email'] ?? '');
    $u_active  = (int) $u['is_active'];
    $u_login_h = ($u['last_login'] ?? '') !== ''
                 ? h($u['last_login']) : '<em class="text-muted">Never</em>';
    $u_since_h = h($u['created_at']);
    $role_opts = UserService::roleOptions($u['role']);
    $role_b    = UserService::roleBadge($u['role']);
    $status_b  = $u_active
                 ? '<span class="badge bg-success">Active</span>'
                 : '<span class="badge bg-secondary">Disabled</span>';

    $role_attr = $is_self ? ' disabled' : '';
    $self_note = $is_self
        ? '<div class="alert alert-info py-2 small mt-2">You are editing your own account.'
          . ' Use <a href="account.php">Account Management</a> to change your own password.</div>'
        : '';

    if ($u_active) {
        $tog_lbl = 'Disable Account';
        $tog_cls = 'btn-outline-warning';
        $tog_val = 0;
    } else {
        $tog_lbl = 'Enable Account';
        $tog_cls = 'btn-outline-success';
        $tog_val = 1;
    }
    $act_attr = $is_self ? ' disabled' : '';

    $content = <<<HTML
<div class="mb-3">
  <a href="{$base_h}" class="btn btn-sm btn-outline-secondary">← Back to Users</a>
</div>

<div class="row g-4">

  <!-- ── Profile ──────────────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="lum-adm-card h-100">
      <h5 class="mb-1">Edit Account</h5>
      <p class="text-muted small mb-3">Update username, email address, and role.</p>

      <form method="post" action="{$base_h}">
        <input type="hidden" name="action"     value="update">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="hidden" name="user_id"    value="{$u_id}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-edit-un">Username</label>
          <input type="text" id="lum-edit-un" name="username"
                 value="{$u_name_h}" class="form-control"
                 required pattern="[a-zA-Z0-9_.\-]{2,50}" autocomplete="off">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-edit-em">
            Email <span class="text-muted fw-normal">(optional)</span>
          </label>
          <input type="email" id="lum-edit-em" name="email"
                 value="{$u_email_h}" class="form-control" autocomplete="off">
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold" for="lum-edit-role">Role</label>
          <select id="lum-edit-role" name="role" class="form-select"{$role_attr}>
            {$role_opts}
          </select>
          {$self_note}
        </div>

        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>

      <hr class="my-4">

      <dl class="row mb-0 small">
        <dt class="col-sm-5 text-muted">Status</dt>
        <dd class="col-sm-7">{$status_b}</dd>
        <dt class="col-sm-5 text-muted">Role</dt>
        <dd class="col-sm-7">{$role_b}</dd>
        <dt class="col-sm-5 text-muted">Last Login</dt>
        <dd class="col-sm-7">{$u_login_h}</dd>
        <dt class="col-sm-5 text-muted">Member Since</dt>
        <dd class="col-sm-7">{$u_since_h}</dd>
      </dl>
    </div>
  </div>

  <!-- ── Password + Actions ────────────────────────────────────────── -->
  <div class="col-lg-6">

    <div class="lum-adm-card mb-4">
      <h5 class="mb-1">Reset Password</h5>
      <p class="text-muted small mb-3">Set a new password for this account without requiring the current one.</p>

      <form method="post" action="{$base_h}" autocomplete="off">
        <input type="hidden" name="action"     value="reset_password">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="hidden" name="user_id"    value="{$u_id}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-np">New Password</label>
          <input type="password" id="lum-np" name="new_password"
                 class="form-control" required minlength="8"
                 autocomplete="new-password">
          <div class="form-text">Minimum 8 characters.</div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold" for="lum-cp">Confirm Password</label>
          <input type="password" id="lum-cp" name="confirm_password"
                 class="form-control" required minlength="8"
                 autocomplete="new-password">
          <div id="lum-rp-msg" class="form-text"></div>
        </div>

        <button type="submit" class="btn btn-warning">Reset Password</button>
      </form>
    </div>

    <div class="lum-adm-card">
      <h5 class="mb-3">Account Actions</h5>

      <form method="post" action="{$base_h}" class="mb-3">
        <input type="hidden" name="action"     value="toggle_active">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="hidden" name="user_id"    value="{$u_id}">
        <input type="hidden" name="is_active"  value="{$tog_val}">
        <button type="submit" class="btn btn-sm {$tog_cls}"{$act_attr}>{$tog_lbl}</button>
        <span class="text-muted small ms-2">Disabled accounts cannot log in.</span>
      </form>

      <form method="post" action="{$base_h}"
            data-confirm="Permanently delete this account? This cannot be undone.">
        <input type="hidden" name="action"     value="delete">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="hidden" name="user_id"    value="{$u_id}">
        <button type="submit" class="btn btn-sm btn-outline-danger"{$act_attr}>🗑 Delete Account</button>
      </form>
    </div>

  </div>
</div>

<script>
(function () {
  'use strict';
  var np  = document.getElementById('lum-np');
  var cp  = document.getElementById('lum-cp');
  var msg = document.getElementById('lum-rp-msg');
  function checkPw() {
    if (!cp.value) { msg.textContent = ''; cp.setCustomValidity(''); return; }
    if (np.value === cp.value) {
      msg.textContent = '\u2713 Passwords match'; msg.className = 'form-text text-success';
      cp.setCustomValidity('');
    } else {
      msg.textContent = '\u2717 Passwords do not match'; msg.className = 'form-text text-danger';
      cp.setCustomValidity('Passwords do not match');
    }
  }
  np.addEventListener('input', checkPw);
  cp.addEventListener('input', checkPw);

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
}());
</script>
HTML;

    lum_admin_page('Edit User: ' . $u_name_h, $content, 'users');
}

// ════════════════════════════════════════════════════════════════════════════
// VIEW: User List (default)
// ════════════════════════════════════════════════════════════════════════════
$total = UserService::countUsers();
$pag   = lumora_pagination(
    $total,
    $per_page,
    $page,
    $base . '?page=%d&per_page=' . $per_page
);
$users = UserService::getPaginatedUsers($pag['current_page'], $per_page);

$summary      = 'Showing ' . number_format($pag['start_item']) . '–'
              . number_format($pag['end_item']) . ' of '
              . number_format($total) . ' '
              . ($total === 1 ? 'user' : 'users');
$new_btn      = '<a href="' . $base_h . '?action=new" class="btn btn-primary btn-sm">+ New User</a>';
$per_page_sel = lum_per_page_selector($base, [], $per_page, $per_page_opts);
$pag_ctrl     = lum_admin_pagination($pag);

// ── Build table rows ──────────────────────────────────────────────────────────
$rows = '';
foreach ($users as $u) {
    $uid      = (int) $u['id'];
    $uname_h  = h($u['username']);
    $email_h  = ($u['email'] ?? '') !== '' ? h($u['email']) : '<span class="text-muted">—</span>';
    $role_b   = UserService::roleBadge($u['role']);
    $is_act   = (int) $u['is_active'];
    $is_self  = ($uid === $current_user_id);
    $edit_url = h($base . '?action=edit&id=' . $uid);

    $status_b = $is_act
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Disabled</span>';

    $login_h  = ($u['last_login'] ?? '') !== ''
        ? h($u['last_login']) : '<span class="text-muted small">Never</span>';

    $self_tag = $is_self
        ? ' <span class="badge bg-info text-dark" style="font-size:.6rem">You</span>'
        : '';

    $tog_val = $is_act ? 0 : 1;
    $tog_lbl = $is_act ? 'Disable' : 'Enable';
    $tog_cls = $is_act ? 'btn-outline-warning' : 'btn-outline-success';
    $dis     = $is_self ? ' disabled' : '';

    // Inline toggle form.
    $tog = '<form method="post" action="' . $base_h . '" class="d-inline">'
         . '<input type="hidden" name="action"     value="toggle_active">'
         . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
         . '<input type="hidden" name="user_id"    value="' . $uid . '">'
         . '<input type="hidden" name="is_active"  value="' . $tog_val . '">'
         . '<button type="submit" class="btn btn-sm ' . $tog_cls . '"' . $dis . '>'
         . $tog_lbl . '</button></form>';

    // Inline delete form — confirm text HTML-escaped via h().
    $confirm_h = h('Delete user "' . $u['username'] . '"? This cannot be undone.');
    $del = '<form method="post" action="' . $base_h . '" class="d-inline"'
         . ' data-confirm="' . $confirm_h . '">'
         . '<input type="hidden" name="action"     value="delete">'
         . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
         . '<input type="hidden" name="user_id"    value="' . $uid . '">'
         . '<button type="submit" class="btn btn-sm btn-outline-danger"' . $dis . '>'
         . 'Delete</button></form>';

    $rows .= '<tr>'
           . '<td class="text-muted small align-middle">' . $uid . '</td>'
           . '<td class="align-middle">'
           .   '<a href="' . $edit_url . '" class="fw-semibold text-decoration-none">'
           .   $uname_h . '</a>' . $self_tag
           . '</td>'
           . '<td class="align-middle">' . $role_b . '</td>'
           . '<td class="align-middle small">' . $email_h . '</td>'
           . '<td class="align-middle">' . $status_b . '</td>'
           . '<td class="align-middle small">' . $login_h . '</td>'
           . '<td class="align-middle">'
           .   '<div class="d-flex gap-1 flex-wrap">'
           .   '<a href="' . $edit_url . '" class="btn btn-sm btn-outline-primary">Edit</a>'
           .   $tog . $del
           .   '</div>'
           . '</td>'
           . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="7" class="text-center text-muted py-4">'
          . 'No user accounts found. '
          . '<a href="' . $base_h . '?action=new">Create the first staff account.</a>'
          . '</td></tr>';
}

$content = <<<HTML
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="text-muted small">{$summary}</div>
  <div class="d-flex align-items-center gap-2">
    {$per_page_sel}
    {$new_btn}
  </div>
</div>

{$pag_ctrl}

<div class="lum-adm-card p-0 mt-2 mb-3">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th class="text-muted fw-normal" style="width:50px">ID</th>
          <th>Username</th>
          <th>Role</th>
          <th>Email</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {$rows}
      </tbody>
    </table>
  </div>
</div>

{$pag_ctrl}

<script>
(function () {
  'use strict';
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
}());
</script>
HTML;

lum_admin_page('Users', $content, 'users');

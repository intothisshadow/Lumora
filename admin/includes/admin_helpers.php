<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Helpers
 *
 * Shared utilities for the admin panel:
 *   lum_admin_page()      — render a full admin page
 *   lum_admin_alert()     — flash messages via session
 *   lum_admin_nav_item()  — sidebar nav item helper
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Flash messages ────────────────────────────────────────────────────────────

/**
 * Queue a flash message to display on the next page load.
 *
 * @param string $msg     The message text (will be HTML-escaped on display).
 * @param string $type    Bootstrap alert type: 'success' | 'danger' | 'warning' | 'info'
 */
function lum_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['lum_flash'][] = ['msg' => $msg, 'type' => $type];
}

/**
 * Return HTML for any queued flash messages and clear them.
 */
function lum_flash_html(): string
{
    if (empty($_SESSION['lum_flash'])) return '';
    $html = '';
    foreach ($_SESSION['lum_flash'] as $f) {
        $html .= '<div class="alert alert-' . h($f['type']) . ' alert-dismissible fade show py-2" role="alert">'
            . h($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }
    unset($_SESSION['lum_flash']);
    return $html;
}

// ── Page renderer ─────────────────────────────────────────────────────────────

/**
 * Render a full admin panel page.
 *
 * @param string $title   Page title (shown in <title> and <h1>).
 * @param string $content Main content HTML.
 * @param string $active  Active sidebar item key (matches $nav entries).
 */
function lum_admin_page(string $title, string $content, string $active = ''): never
{
    $base_url     = h(lumora_base_url());
    $admin_url    = h(lumora_base_url() . 'admin/');
    $gallery_url  = h(lumora_base_url());
    $user         = lumora_current_user();
    $username     = h($user['username'] ?? 'Admin');
    $csrf         = h(lumora_csrf_token());
    $ver          = LUMORA_VERSION;
    $flash        = lum_flash_html();
    $title_h      = h($title);
    $gallery_name = h(lumora_config('gallery_name', 'Lumora Gallery'));

    // Persistent security warning: shown on every admin page until install/ is gone.
    // The directory should be auto-deleted by the installer on success, but may
    // survive on restrictive filesystems or after a forced reinstall.
    $install_warn = is_dir(LUMORA_ROOT . 'install')
        ? '<div class="alert alert-danger alert-dismissible fade show py-2 mb-3" role="alert">'
          . '<strong>Security warning:</strong> The <code>install/</code> directory still exists. '
          . 'Delete it immediately via FTP or your hosting control panel to prevent unauthorised reinstallation.'
          . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
          . '</div>'
        : '';

    $nav_items = [
        'dashboard'   => ['icon' => '📊', 'label' => 'Dashboard',     'url' => 'dashboard.php'],
        'batch'       => ['icon' => '⬆️', 'label' => 'Batch Add',     'url' => 'batch.php'],
        'categories'  => ['icon' => '📁', 'label' => 'Categories',    'url' => 'categories.php'],
        'albums'      => ['icon' => '🖼️', 'label' => 'Albums',        'url' => 'albums.php'],
        'images'      => ['icon' => '📸', 'label' => 'Images',        'url' => 'images.php'],
        'config'      => ['icon' => '⚙️', 'label' => 'Configuration', 'url' => 'config.php'],
        'maintenance' => ['icon' => '🔧', 'label' => 'Maintenance',   'url' => 'maintenance.php'],
        'account'     => ['icon' => '👤', 'label' => 'Account',       'url' => 'account.php'],
    ];

    $nav_html = '';
    foreach ($nav_items as $key => $item) {
        $cls = ($key === $active) ? ' class="active"' : '';
        $nav_html .= '<li' . $cls . '>'
            . '<a href="' . $admin_url . h($item['url']) . '">'
            . $item['icon'] . ' ' . $item['label']
            . '</a></li>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title_h} — Lumora Gallery Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$admin_url}admin.css">
</head>
<body class="lum-admin">

<nav class="navbar navbar-expand-lg navbar-dark lum-admin-topbar px-3">
  <a class="navbar-brand fw-bold" href="{$admin_url}">⚡ Lumora Gallery Admin <span class="opacity-50 fw-normal small">v{$ver}</span></a>
  <button class="navbar-toggler ms-auto me-2" type="button" data-bs-toggle="collapse"
          data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="adminNav">
    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
      <li class="nav-item">
        <a class="nav-link" href="{$gallery_url}" target="_blank" rel="noopener">↗ View Gallery</a>
      </li>
      <li class="nav-item">
        <a class="nav-link text-white-50 small" href="{$admin_url}account.php">👤 {$username}</a>
      </li>
      <li class="nav-item">
        <form method="post" action="{$admin_url}logout.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="{$csrf}">
          <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
        </form>
      </li>
    </ul>
  </div>
</nav>

<div class="lum-admin-layout">
  <!-- Sidebar -->
  <aside class="lum-admin-sidebar">
    <div class="lum-admin-gallery-name small text-truncate px-3 py-2 text-white-50">{$gallery_name}</div>
    <ul class="lum-admin-nav list-unstyled m-0 p-0">
      {$nav_html}
    </ul>
  </aside>

  <!-- Main -->
  <main class="lum-admin-main p-3 p-md-4">
    <h1 class="h4 mb-3">{$title_h}</h1>
    {$install_warn}
    {$flash}
    {$content}
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
    exit;
}

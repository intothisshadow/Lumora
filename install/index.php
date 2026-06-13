<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Installer
 *
 * Steps:
 *   1  Requirements check + database credentials form
 *   2  Database setup + admin account form
 *   3  Done
 *
 * Usage: upload the Lumora folder to your server, then visit /install/ in a browser.
 */

define('LUMORA_ENTRY',     true);
define('LUMORA_INSTALLER', true);

// Bootstrap gives us path constants and version; stops before loading config.
require_once dirname(__DIR__) . '/include/bootstrap.php';

// Manually load the DB class (bootstrap stopped early).
require_once LUMORA_INCLUDE . 'db.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function ins_h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ins_page(string $title, string $body, int $step = 1): never
{
    $ver = LUMORA_VERSION;
    // Pre-compute step indicator classes.
    // PHP's {$...} heredoc interpolation only supports simple variable
    // references, not arbitrary expressions like ternary operators.
    $s1 = $step === 1 ? 'active' : ($step > 1 ? 'done' : '');
    $s2 = $step === 2 ? 'active' : ($step > 2 ? 'done' : '');
    $s3 = $step === 3 ? 'active' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} — Lumora Installer</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background:#f4f6f8; }
    .ins-card { max-width:640px; margin:3rem auto; }
    .ins-header { background:#1a1a2e; color:#fff; padding:1.5rem; border-radius:.5rem .5rem 0 0; }
    .ins-header h1 { font-size:1.4rem; margin:0; }
    .ins-header small { opacity:.7; }
    .ins-steps { display:flex; gap:0; border-bottom:1px solid #dee2e6; background:#fff; padding:.75rem 1.5rem 0; }
    .ins-step { padding:.5rem 1rem .65rem; font-size:.85rem; color:#6c757d; border-bottom:2px solid transparent; }
    .ins-step.active { color:#0d6efd; border-color:#0d6efd; font-weight:600; }
    .ins-step.done { color:#198754; }
    .req-ok { color:#198754; }
    .req-fail { color:#dc3545; }
    .req-warn { color:#fd7e14; }
  </style>
</head>
<body>
<div class="ins-card card shadow-sm">
  <div class="ins-header">
    <h1>Lumora Gallery Installer</h1>
    <small>Version {$ver}</small>
  </div>
  <div class="ins-steps">
    <div class="ins-step {$s1}">1. Requirements</div>
    <div class="ins-step {$s2}">2. Database &amp; Admin</div>
    <div class="ins-step {$s3}">3. Complete</div>
  </div>
  <div class="card-body p-4">
    {$body}
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
    exit;
}

/**
 * Run the schema SQL file against the connected database.
 *
 * Strategy: strip all SQL line-comment lines (-- ...) and blank lines FIRST,
 * then split on semicolons. The previous approach of splitting first and then
 * filtering segments that start with "--" incorrectly discarded every CREATE
 * TABLE statement, because each one was preceded by a block of dashes that
 * caused the whole segment to be filtered out — leaving no tables created.
 *
 * Returns null on success, or an error string on failure.
 */
function ins_run_schema(string $schema_file, string $db_prefix): ?string
{
    if (!file_exists($schema_file)) {
        return 'schema.sql not found in install/. Please re-upload.';
    }

    $sql_raw = file_get_contents($schema_file);
    if ($sql_raw === false) {
        return 'Could not read schema.sql.';
    }

    // Replace the prefix placeholder.
    $sql_raw = str_replace('{PREFIX}', $db_prefix, $sql_raw);

    // Strip line-comment lines (-- ...) and blank lines before splitting.
    // This avoids the segment-level filter incorrectly discarding CREATE TABLE
    // blocks that were preceded by comment headers.
    $lines         = explode("\n", $sql_raw);
    $cleaned_lines = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleaned_lines[] = $line;
    }
    $sql_clean = implode("\n", $cleaned_lines);

    // Split on semicolons (each statement is terminated by ;).
    $statements = array_filter(
        array_map('trim', explode(';', $sql_clean)),
        fn(string $s): bool => $s !== ''
    );

    try {
        LumoraDB::pdo()->exec('SET NAMES utf8mb4');
        foreach ($statements as $stmt) {
            LumoraDB::pdo()->exec($stmt);
        }
    } catch (PDOException $e) {
        return 'Schema setup failed: ' . $e->getMessage();
    }

    return null; // success
}

function ins_check_requirements(): array
{
    $checks = [];

    // PHP version
    $ok = PHP_VERSION_ID >= 80200;
    $checks[] = ['label' => 'PHP ' . LUMORA_MIN_PHP . '+', 'ok' => $ok ? 'ok' : 'fail',
        'note' => $ok ? 'PHP ' . PHP_VERSION : 'Running PHP ' . PHP_VERSION . '; upgrade required'];

    // PDO MySQL
    $ok = extension_loaded('pdo') && extension_loaded('pdo_mysql');
    $checks[] = ['label' => 'PDO MySQL', 'ok' => $ok ? 'ok' : 'fail',
        'note' => $ok ? 'Available' : 'pdo_mysql extension required'];

    // Imagick PHP extension or GD
    $imagick = extension_loaded('imagick');
    $gd      = extension_loaded('gd');
    $ok      = $imagick || $gd;
    $note    = $imagick
        ? 'Imagick PHP extension active (preferred)'
        : ($gd ? 'GD library active (install php-imagick for better quality and format support)' : 'Neither found — thumbnail generation will not work');
    $checks[] = ['label' => 'Imagick or GD', 'ok' => $ok ? 'ok' : 'fail', 'note' => $note];

    // Writable root
    $ok = is_writable(LUMORA_ROOT);
    $checks[] = ['label' => 'Lumora root writable', 'ok' => $ok ? 'ok' : 'fail',
        'note' => $ok ? 'config.php can be written' : 'Make the Lumora directory writable by the web server'];

    // albums/ directory
    $dir = LUMORA_ALBUMS_PATH;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ok = is_dir($dir) && is_writable($dir);
    $checks[] = ['label' => 'albums/ directory writable', 'ok' => $ok ? 'ok' : 'fail',
        'note' => $ok ? 'Ready' : 'Create albums/ and make it writable (chmod 755)'];

    // config.php already exists?
    if (file_exists(LUMORA_ROOT . 'config.php')) {
        $checks[] = ['label' => 'Existing config.php', 'ok' => 'warn',
            'note' => 'config.php already exists — proceeding will overwrite it'];
    }

    return $checks;
}

function ins_requirements_passed(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['ok'] === 'fail') return false;
    }
    return true;
}

/**
 * Attempt to delete the installer directory and all its contents.
 * Called automatically at the end of a successful installation.
 *
 * Deletes all files inside first, then removes the (now empty) directory.
 * On Unix/Linux, deleting the currently-running PHP file from within that same
 * script is safe because the process already holds an open file descriptor.
 *
 * @return bool True if the directory was fully removed; false otherwise.
 */
function ins_delete_installer(): bool
{
    $dir = LUMORA_ROOT . 'install';
    if (!is_dir($dir)) {
        return true; // already absent
    }

    // Delete every file inside (install/ contains no subdirectories).
    $files = glob($dir . DIRECTORY_SEPARATOR . '*');
    if ($files === false) {
        return false;
    }
    foreach ($files as $file) {
        if (is_file($file) && !unlink($file)) {
            return false;
        }
    }

    // Remove the now-empty directory.
    return rmdir($dir);
}

// ── Session ───────────────────────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Redirect away if already installed (unless forcing reinstall).
if (!isset($_GET['force']) && file_exists(LUMORA_ROOT . 'config.php')) {
    $config_content = file_get_contents(LUMORA_ROOT . 'config.php');
    if ($config_content !== false && str_contains($config_content, "define('LUMORA_INSTALLED', true)")) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path  = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
        header('Location: ' . $proto . '://' . $host . $path . '/');
        exit;
    }
}

// ── CSRF token for installer ──────────────────────────────────────────────────
if (empty($_SESSION['ins_csrf'])) {
    $_SESSION['ins_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ins_csrf'];

// ── Step 1 POST: Validate DB, run schema, show admin form ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $checks = ins_check_requirements();
    if (!ins_requirements_passed($checks)) {
        $_SESSION['ins_errors'] = ['Please fix the listed requirements before continuing.'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Sanitise DB credentials.
    $db_host   = trim($_POST['db_host']   ?? 'localhost');
    $db_port   = (int) ($_POST['db_port'] ?? 3306);
    $db_name   = trim($_POST['db_name']   ?? '');
    $db_user   = trim($_POST['db_user']   ?? '');
    $db_pass   = $_POST['db_pass']        ?? '';
    $db_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['db_prefix'] ?? 'lum_'));

    if ($db_name === '' || $db_user === '' || $db_prefix === '') {
        $_SESSION['ins_errors'] = ['Database name, user, and table prefix are required.'];
        $_SESSION['ins_db'] = compact('db_host', 'db_port', 'db_name', 'db_user', 'db_prefix');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $db_host_full = ($db_port !== 3306) ? $db_host . ':' . $db_port : $db_host;

    try {
        LumoraDB::connect($db_host_full, $db_name, $db_user, $db_pass, $db_prefix);
    } catch (RuntimeException $e) {
        $_SESSION['ins_errors'] = ['Database connection failed: ' . $e->getMessage()];
        $_SESSION['ins_db'] = compact('db_host', 'db_port', 'db_name', 'db_user', 'db_prefix');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $schema_error = ins_run_schema(__DIR__ . '/schema.sql', $db_prefix);
    if ($schema_error !== null) {
        $_SESSION['ins_errors'] = [$schema_error];
        $_SESSION['ins_db'] = compact('db_host', 'db_port', 'db_name', 'db_user', 'db_prefix');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Store credentials in session for step 2 (include password for reconnect).
    $_SESSION['ins_db'] = compact('db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix');

    // Detect base URL.
    $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script   = $_SERVER['SCRIPT_NAME'] ?? '/install/index.php';
    $base     = rtrim(dirname(dirname($script)), '/\\');
    $base_url = $proto . '://' . $host . $base . '/';

    $_SESSION['ins_base_url'] = $base_url;

    $b_url = ins_h($base_url);
    $body = <<<HTML
<div class="alert alert-success py-2 mb-4">Database connected and tables created successfully.</div>
<h5 class="mb-3">Gallery Settings</h5>
<form method="post" action="">
  <input type="hidden" name="step"       value="2">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <div class="mb-3">
    <label class="form-label fw-semibold">Gallery Name</label>
    <input type="text" name="gallery_name" value="My Gallery" class="form-control" required>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Base URL <small class="text-muted">(trailing slash required)</small></label>
    <input type="url" name="base_url" value="{$b_url}" class="form-control" required>
    <div class="form-text">The public URL to the root of your Lumora installation.</div>
  </div>
  <h5 class="mb-3">Admin Account</h5>
  <div class="mb-3">
    <label class="form-label fw-semibold">Username</label>
    <input type="text" name="admin_user" value="admin" class="form-control" required minlength="3">
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Password</label>
    <input type="password" name="admin_pass" class="form-control" required minlength="8">
    <div class="form-text">Minimum 8 characters.</div>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Confirm Password</label>
    <input type="password" name="admin_pass2" class="form-control" required minlength="8">
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Admin Email <small class="text-muted">(optional)</small></label>
    <input type="email" name="admin_email" value="" class="form-control">
  </div>
  <button type="submit" class="btn btn-primary">Finish Installation →</button>
</form>
HTML;

    ins_page('Step 2: Gallery &amp; Admin', $body, 2);
}

// ── Step 2 POST: Create admin, write config, done ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $db = $_SESSION['ins_db'] ?? null;
    if (!$db) {
        // Session lost — restart.
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $admin_user   = trim($_POST['admin_user']   ?? '');
    $admin_pass   = $_POST['admin_pass']         ?? '';
    $admin_pass2  = $_POST['admin_pass2']        ?? '';
    $admin_email  = trim($_POST['admin_email']   ?? '');
    $gallery_name = trim($_POST['gallery_name']  ?? 'My Gallery');
    $base_url     = rtrim(trim($_POST['base_url'] ?? ''), '/') . '/';

    $errors = [];
    if (strlen($admin_user) < 3)      $errors[] = 'Username must be at least 3 characters.';
    if (strlen($admin_pass) < 8)      $errors[] = 'Password must be at least 8 characters.';
    if ($admin_pass !== $admin_pass2) $errors[] = 'Passwords do not match.';
    if ($gallery_name === '')         $errors[] = 'Gallery name is required.';

    if (!empty($errors)) {
        $err_html = '';
        foreach ($errors as $e) {
            $err_html .= '<div class="alert alert-danger py-2">' . ins_h($e) . '</div>';
        }
        $b_url   = ins_h($base_url);
        $g_name  = ins_h($gallery_name);
        $a_user  = ins_h($admin_user);
        $a_email = ins_h($admin_email);
        $body = $err_html . <<<HTML
<h5 class="mb-3">Gallery Settings</h5>
<form method="post" action="">
  <input type="hidden" name="step"       value="2">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <div class="mb-3">
    <label class="form-label fw-semibold">Gallery Name</label>
    <input type="text" name="gallery_name" value="{$g_name}" class="form-control" required>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Base URL</label>
    <input type="url" name="base_url" value="{$b_url}" class="form-control" required>
  </div>
  <h5 class="mb-3">Admin Account</h5>
  <div class="mb-3">
    <label class="form-label fw-semibold">Username</label>
    <input type="text" name="admin_user" value="{$a_user}" class="form-control" required minlength="3">
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Password</label>
    <input type="password" name="admin_pass" class="form-control" required minlength="8">
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Confirm Password</label>
    <input type="password" name="admin_pass2" class="form-control" required minlength="8">
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Admin Email</label>
    <input type="email" name="admin_email" value="{$a_email}" class="form-control">
  </div>
  <button type="submit" class="btn btn-primary">Finish Installation →</button>
</form>
HTML;
        ins_page('Step 2: Gallery &amp; Admin', $body, 2);
    }

    // Re-connect to DB (each request is a new PHP process).
    $db_host_full = ((int)($db['db_port'] ?? 3306) !== 3306)
        ? $db['db_host'] . ':' . $db['db_port']
        : (string) $db['db_host'];

    try {
        LumoraDB::connect(
            $db_host_full,
            (string) $db['db_name'],
            (string) $db['db_user'],
            (string) $db['db_pass'],
            (string) $db['db_prefix']
        );
    } catch (RuntimeException $e) {
        $msg = ins_h('Lost database connection: ' . $e->getMessage() . ' Please start over.');
        $href = ins_h($_SERVER['PHP_SELF'] . '?force=1');
        $body = '<div class="alert alert-danger">' . $msg . '</div>'
              . '<a href="' . $href . '" class="btn btn-secondary mt-2">Start Over</a>';
        ins_page('Database Error', $body, 2);
    }

    $prefix = (string) $db['db_prefix'];
    $pdo    = LumoraDB::pdo();

    // Insert gallery config defaults.
    $config_defaults = [
        // ── Core gallery settings ──────────────────────────────────────────
        'gallery_name'        => $gallery_name,
        'gallery_description' => '',
        'base_url'            => $base_url,
        'theme'               => 'default',
        'thumb_width'         => '250',
        'thumb_height'        => '250',
        'per_page'            => '48',
        'allowed_extensions'  => 'jpg,jpeg,png,gif,webp',
        'custom_header_path'  => '',
        'custom_footer_path'  => '',
        // ── Behaviour & logging (DB version 2) ────────────────────────────
        'timezone'            => 'UTC',
        'thumb_quality'       => '85',
        'max_upload_size_mb'  => '0',
        'max_image_width'     => '0',
        'max_image_height'    => '0',
        'count_album_views'    => '1',
        'log_mode'             => 'off',
        'gallery_offline'      => '0',
        'latest_albums_count'      => '5',
        'who_is_online_duration'   => '5',
        'show_powered_by'          => '1',
        'category_layout'          => 'grid',
    ];

    try {
        foreach ($config_defaults as $cfgName => $cfgValue) {
            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}config` (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            );
            $stmt->execute([$cfgName, $cfgValue]);
        }
    } catch (PDOException $e) {
        $msg  = ins_h('Failed to write gallery config: ' . $e->getMessage());
        $href = ins_h($_SERVER['PHP_SELF'] . '?force=1');
        $body = '<div class="alert alert-danger">' . $msg . '</div>'
              . '<a href="' . $href . '" class="btn btn-secondary mt-2">Start Over</a>';
        ins_page('Database Error', $body, 2);
    }

    // Create admin user.
    $hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO `{$prefix}users` (username, password_hash, email, role)
             VALUES (?, ?, ?, 'admin')
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email = VALUES(email), role = 'admin'"
        );
        $stmt->execute([$admin_user, $hash, $admin_email]);
    } catch (PDOException $e) {
        $msg  = ins_h('Failed to create admin user: ' . $e->getMessage());
        $href = ins_h($_SERVER['PHP_SELF'] . '?force=1');
        $body = '<div class="alert alert-danger">' . $msg . '</div>'
              . '<a href="' . $href . '" class="btn btn-secondary mt-2">Start Over</a>';
        ins_page('Database Error', $body, 2);
    }

    // Write config.php.
    // Build via string concatenation to avoid heredoc delimiter ambiguity.
    $ts            = (int) ($_SERVER['REQUEST_TIME'] ?? time());
    $db_host_esc   = addslashes((string) $db['db_host']);
    $db_name_esc   = addslashes((string) $db['db_name']);
    $db_user_esc   = addslashes((string) $db['db_user']);
    $db_pass_esc   = addslashes((string) $db['db_pass']);
    $db_prefix_esc = addslashes($prefix);

    $config_php  = "<?php\n";
    $config_php .= "declare(strict_types=1);\n";
    $config_php .= "/**\n";
    $config_php .= " * Lumora Gallery — Configuration\n";
    $config_php .= " * Generated by installer on " . date('Y-m-d H:i:s', $ts) . ".\n";
    $config_php .= " * DO NOT expose this file publicly.\n";
    $config_php .= " */\n";
    $config_php .= "define('DB_HOST',    '{$db_host_esc}');\n";
    $config_php .= "define('DB_NAME',    '{$db_name_esc}');\n";
    $config_php .= "define('DB_USER',    '{$db_user_esc}');\n";
    $config_php .= "define('DB_PASS',    '{$db_pass_esc}');\n";
    $config_php .= "define('DB_PREFIX',  '{$db_prefix_esc}');\n";
    $config_php .= "define('DB_CHARSET', 'utf8mb4');\n";
    $config_php .= "define('LUMORA_INSTALLED', true);\n";

    if (file_put_contents(LUMORA_ROOT . 'config.php', $config_php) === false) {
        $body = '<div class="alert alert-danger">Could not write config.php to disk. '
              . 'Check that the Lumora root directory is writable, then paste the content below into '
              . '<code>config.php</code> manually.</div>'
              . '<pre class="bg-light p-3 rounded small">' . ins_h($config_php) . '</pre>';
        ins_page('Manual Setup Required', $body, 3);
    }

    // Ensure albums/ directory exists.
    if (!is_dir(LUMORA_ALBUMS_PATH)) {
        mkdir(LUMORA_ALBUMS_PATH, 0755, true);
    }

    // Clean up installer session data.
    unset($_SESSION['ins_db'], $_SESSION['ins_base_url'], $_SESSION['ins_csrf']);

    // Attempt to auto-delete the installer directory.
    // On most Unix/Linux hosts this succeeds; on Windows or with restrictive
    // permissions it may fail — a persistent warning will appear in the admin
    // panel until the directory is gone (see admin_helpers.php).
    $install_deleted = ins_delete_installer();

    // Done!
    $admin_url   = ins_h(rtrim($base_url, '/') . '/admin/');
    $gallery_url = ins_h($base_url);
    $a_user_h    = ins_h($admin_user);

    // Computed before the heredoc so PHP can interpolate {$install_status_html}.
    $install_status_html = $install_deleted
        ? '<div class="alert alert-success py-2 small mt-2">&#10003; The <code>install/</code> directory was automatically removed.</div>'
        : '<div class="alert alert-warning py-2 small mt-2">&#9888; The <code>install/</code> directory could not be removed automatically. '
          . 'Please delete it manually via FTP or your hosting control panel.</div>';

    $body = <<<HTML
<div class="alert alert-success">
  <strong>&#x1F389; Lumora Gallery has been installed successfully!</strong>
</div>
<p>Your gallery is ready. Here are your next steps:</p>
<ol class="mb-4">
  <li>Log in to the <a href="{$admin_url}">Admin Panel</a> with username <strong>{$a_user_h}</strong></li>
  <li>Go to <strong>Admin → Configuration</strong> to verify your settings</li>
  <li>Go to <strong>Admin → Categories</strong> to create your first category</li>
  <li>Go to <strong>Admin → Albums</strong> to create albums</li>
  <li>Upload images via FTP to <code>albums/{folder}/</code> and use <strong>Batch Add</strong></li>
</ol>
{$install_status_html}
<div class="d-flex gap-2">
  <a href="{$admin_url}" class="btn btn-primary">Go to Admin Panel</a>
  <a href="{$gallery_url}" class="btn btn-outline-secondary">View Gallery</a>
</div>
HTML;

    ins_page('Installation Complete', $body, 3);
}

// ── GET: show step 1 (requirements + DB form) ─────────────────────────────────
$checks  = ins_check_requirements();
$can_go  = ins_requirements_passed($checks);
$errors  = $_SESSION['ins_errors'] ?? [];
$old     = $_SESSION['ins_db']     ?? [];
unset($_SESSION['ins_errors'], $_SESSION['ins_db']);

$rows = '';
foreach ($checks as $c) {
    $icon  = match($c['ok']) { 'ok' => '✓', 'warn' => '⚠', default => '✗' };
    $cls   = match($c['ok']) { 'ok' => 'req-ok', 'warn' => 'req-warn', default => 'req-fail' };
    $rows .= '<tr><td class="' . $cls . '">' . $icon . '</td>'
           . '<td><strong>' . ins_h($c['label']) . '</strong>'
           . '<br><small class="text-muted">' . ins_h($c['note']) . '</small></td></tr>';
}

$err_html = '';
foreach ($errors as $e) {
    $err_html .= '<div class="alert alert-danger py-2">' . ins_h($e) . '</div>';
}

$disabled = $can_go ? '' : ' disabled';
// Pre-compute the "fix requirements" notice — can't use expressions inside heredoc {$...}.
$p_fix    = $can_go
    ? ''
    : '<p class="text-danger mt-2 small">Fix the requirements above before continuing.</p>';

$v_host   = ins_h((string) ($old['db_host']   ?? 'localhost'));
$v_name   = ins_h((string) ($old['db_name']   ?? ''));
$v_user   = ins_h((string) ($old['db_user']   ?? ''));
$v_prefix = ins_h((string) ($old['db_prefix'] ?? 'lum_'));
$v_port   = ins_h((string) ($old['db_port']   ?? '3306'));

$body = <<<HTML
<h5 class="mb-3">System Requirements</h5>
<table class="table table-sm mb-4"><tbody>{$rows}</tbody></table>
{$err_html}
<h5 class="mb-3">Database Configuration</h5>
<form method="post" action="">
  <input type="hidden" name="step"       value="1">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <div class="mb-3 row g-2">
    <div class="col-sm-8">
      <label class="form-label fw-semibold">Database Host</label>
      <input type="text" name="db_host" value="{$v_host}" class="form-control" required>
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">Port</label>
      <input type="number" name="db_port" value="{$v_port}" class="form-control">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Database Name</label>
    <input type="text" name="db_name" value="{$v_name}" class="form-control" required>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Database User</label>
    <input type="text" name="db_user" value="{$v_user}" class="form-control" required>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Database Password</label>
    <input type="password" name="db_pass" value="" class="form-control">
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Table Prefix <small class="text-muted">(default: lum_)</small></label>
    <input type="text" name="db_prefix" value="{$v_prefix}" class="form-control" required pattern="[a-zA-Z0-9_]+">
    <div class="form-text">Only letters, numbers, and underscores.</div>
  </div>
  <button type="submit" class="btn btn-primary"{$disabled}>Next: Set Up Database →</button>
  {$p_fix}
</form>
HTML;

ins_page('Step 1: Requirements', $body, 1);

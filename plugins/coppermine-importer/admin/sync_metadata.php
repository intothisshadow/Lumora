<?php

declare(strict_types=1);
/**
 * Coppermine Importer — Metadata Sync Tool
 *
 * Standalone companion to the main import wizard (index.php). Syncs category
 * and album cover-thumbnail selections from an existing Coppermine
 * installation into an already-imported Lumora gallery, without requiring a
 * full re-import.
 *
 * The credentials form includes an auto-detect panel that reads the
 * Coppermine include/config.inc.php file from a supplied filesystem path,
 * the same as the main import wizard.
 *
 * Matching strategy (the original importer does not persist Coppermine
 * record IDs into Lumora):
 *   - Albums:     matched by `folder`, resolved the same way importAlbums()
 *                 resolves it (cpg_pictures.filepath, falling back to keyword).
 *   - Categories: matched by full name-path from the root, since categories
 *                 have no folder equivalent.
 *
 * Steps:
 *   Step 1 — Credentials form (GET / POST action=connect)
 *   Step 2 — Preview (GET ?step=2), Apply (POST action=apply)
 *   Step 3 — Report (GET ?step=done)
 *
 * Session key: lumora_cpg_thumb_sync (separate from the import wizard's
 * lumora_cpg_import, so the two tools never share or collide on state).
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);

// Bootstrap path: this file is at plugins/coppermine-importer/admin/sync_metadata.php
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/CoppermineImporter.php';
require_once dirname(__DIR__) . '/CoppermineConfigDetector.php';

lumora_require_admin();

// ── Page-level variables ──────────────────────────────────────────────────────

$sess_key   = 'lumora_cpg_thumb_sync';
$report_key = 'lumora_cpg_thumb_sync_report';
$base_url   = lumora_base_url();
$plugin_url = $base_url . 'plugins/coppermine-importer/admin/';
$self_url   = $plugin_url . 'sync_metadata.php';
$admin_url  = $base_url . 'admin/';
$csrf       = lumora_csrf_token();

$step_raw = $_GET['step'] ?? '1';
$step     = ($step_raw === 'done') ? 'done' : (int) $step_raw;

$sess = &$_SESSION[$sess_key];
if (!is_array($sess)) {
    $sess = [];
}

// ── POST handlers ─────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

if ($action === 'connect') {
    lumora_csrf_validate();

    $host   = trim((string) ($_POST['db_host']   ?? ''));
    $name   = trim((string) ($_POST['db_name']   ?? ''));
    $user   = trim((string) ($_POST['db_user']   ?? ''));
    $pass   = (string) ($_POST['db_pass']         ?? '');
    $prefix = trim((string) ($_POST['db_prefix'] ?? ''));

    $importer = new CoppermineImporter($host, $name, $user, $pass, $prefix);
    $result   = $importer->validate();

    if (!$result['ok']) {
        lum_flash('Connection failed: ' . h($result['error'] ?? 'Unknown error'), 'danger');
        lumora_redirect($self_url);
    }

    $sess = [
        'db_host'   => $host,
        'db_name'   => $name,
        'db_user'   => $user,
        'db_pass'   => $pass,
        'db_prefix' => $prefix,
    ];

    lumora_redirect($self_url . '?step=2');
}

if ($action === 'apply') {
    lumora_csrf_validate();

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
        lumora_redirect($self_url);
    }
    if (!isset($_POST['confirm_backup'])) {
        lum_flash('You must confirm you have a database backup before applying changes.', 'danger');
        lumora_redirect($self_url . '?step=2');
    }

    $overwrite = isset($_POST['overwrite']);

    $importer = new CoppermineImporter(
        $sess['db_host'],
        $sess['db_name'],
        $sess['db_user'],
        $sess['db_pass'],
        $sess['db_prefix']
    );

    try {
        $importer->connect();
        $result  = $importer->applyThumbnailSync($overwrite);
        $preview = $importer->previewThumbnailSync();
    } catch (\Throwable $e) {
        lum_flash('Sync failed: ' . h($e->getMessage()), 'danger');
        lumora_redirect($self_url . '?step=2');
    }

    $matched = count(array_filter($preview['categories'], static fn($r) => $r['lumora_id'] !== null))
        + count(array_filter($preview['albums'],     static fn($r) => $r['lumora_id'] !== null));

    // ── Timestamped audit-trail file ───────────────────────────────────────
    $log_lines = [
        '[' . date('Y-m-d H:i:s') . '] Coppermine metadata sync run',
        'Overwrite existing thumbnails: ' . ($overwrite ? 'yes' : 'no'),
        'Records matched:               ' . $matched,
        'Updated:                       ' . $result['updated'],
        'Skipped:                       ' . $result['skipped'],
    ];
    foreach ($result['errors'] as $err) {
        $log_lines[] = 'ERROR: ' . $err;
    }

    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = null;
    if (is_dir($log_dir) && is_writable($log_dir)) {
        $log_file = $log_dir . '/thumb_sync_' . date('Ymd_His') . '.log';
        file_put_contents($log_file, implode("\n", $log_lines) . "\n");
    }

    MigrationService::logEvent(
        LUMORA_CPG_IMPORTER_SYNC_SOURCE,
        $result['errors'] ? MigrationService::LOG_WARNING : MigrationService::LOG_INFO,
        sprintf(
            'Thumbnail sync: %d matched, %d updated, %d skipped, %d error(s) (overwrite=%s)',
            $matched,
            $result['updated'],
            $result['skipped'],
            count($result['errors']),
            $overwrite ? 'yes' : 'no'
        )
    );

    $_SESSION[$report_key] = [
        'matched'   => $matched,
        'updated'   => $result['updated'],
        'skipped'   => $result['skipped'],
        'errors'    => $result['errors'],
        'overwrite' => $overwrite,
        'log_file'  => $log_file,
    ];

    unset($_SESSION[$sess_key]);
    lumora_redirect($self_url . '?step=done');
}

if ($action === 'cancel') {
    lumora_csrf_validate();
    unset($_SESSION[$sess_key]);
    lumora_redirect($admin_url . 'migrate.php');
}

// ── Page rendering ────────────────────────────────────────────────────────────

ob_start();

// ── Step 1: Credentials ───────────────────────────────────────────────────────
if ($step === 1) {

    $csrf_h = h($csrf);

    // Detection panel variables (pre-computed for safe JS embedding)
    $detect_url_js    = json_encode($plugin_url . 'ajax_detect_config.php');
    $csrf_js          = json_encode($csrf);
    $last_detect_path = h((string) ($_SESSION['lumora_cpg_last_detect_path'] ?? ''));

    echo '<p class="text-muted">Sync category and album cover-thumbnail selections from your '
        . 'Coppermine database into this already-imported Lumora gallery. This does <strong>not</strong> '
        . 'import categories, albums, or images &mdash; use the '
        . '<a href="' . h($plugin_url . 'index.php') . '">main Coppermine Importer</a> for that. '
        . 'Run this any time after an import to fill in cover images that were not carried over.</p>';

    // ── Auto-detect panel ─────────────────────────────────────────────────────
    echo '<div class="card mb-3" style="max-width:600px;">';
    echo '<div class="card-header d-flex align-items-center gap-2">';
    echo '&#128270; Auto-Detect from Coppermine Installation';
    echo '<span class="badge bg-secondary ms-1 small fw-normal">Optional</span>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<p class="text-muted small mb-2">'
        . 'Enter the filesystem path to your Coppermine installation to automatically '
        . 'populate the database credentials below. The <code>include/config.inc.php</code> '
        . 'file is read as text &mdash; no Coppermine PHP code is executed.'
        . '</p>';
    echo '<div class="input-group mb-2">';
    echo '<input type="text" id="cpg-detect-path" class="form-control font-monospace"'
        . ' placeholder="/var/www/html/gallery"'
        . ' value="' . $last_detect_path . '"'
        . ' autocomplete="off" spellcheck="false">';
    echo '<button type="button" id="cpg-detect-btn" class="btn btn-outline-primary">Detect Settings</button>';
    echo '</div>';
    echo '<div id="cpg-detect-status" class="small text-muted">Enter a path above and click Detect to auto-fill the form below.</div>';
    echo '<div id="cpg-detect-multi" style="display:none;" class="mt-2"></div>';
    echo '</div></div>';

    // ── Credentials form ──────────────────────────────────────────────────────
    echo '<div class="card" style="max-width:600px;">';
    echo '<div class="card-header">Coppermine Database Credentials</div>';
    echo '<div class="card-body">';
    echo '<p class="text-muted small">Enter the connection details for the <strong>Coppermine database</strong> '
        . '(not the Lumora database). A separate read-only connection is opened; no Coppermine data is modified.</p>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action"     value="connect">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">';
    echo '<div class="mb-3">'
        . '<label for="db_host" class="form-label">Database Host</label>'
        . '<input type="text" id="db_host" name="db_host" class="form-control" value="localhost" required autocomplete="off">'
        . '</div>';
    echo '<div class="mb-3">'
        . '<label for="db_name" class="form-label">Database Name</label>'
        . '<input type="text" id="db_name" name="db_name" class="form-control" required autocomplete="off">'
        . '</div>';
    echo '<div class="mb-3">'
        . '<label for="db_user" class="form-label">Database User</label>'
        . '<input type="text" id="db_user" name="db_user" class="form-control" required autocomplete="off">'
        . '</div>';
    echo '<div class="mb-3">'
        . '<label for="db_pass" class="form-label">Database Password</label>'
        . '<input type="password" id="db_pass" name="db_pass" class="form-control" autocomplete="off">'
        . '</div>';
    echo '<div class="mb-3">'
        . '<label for="db_prefix" class="form-label">Table Prefix</label>'
        . '<input type="text" id="db_prefix" name="db_prefix" class="form-control" value="cpg_" placeholder="cpg_" autocomplete="off">'
        . '<div class="form-text">Default is <code>cpg_</code>. Older installations may use a different prefix.</div>'
        . '</div>';
    echo '<div class="d-flex gap-2">'
        . '<button type="submit" class="btn btn-primary">Test Connection &amp; Preview</button>'
        . '<a href="' . h($admin_url . 'migrate.php') . '" class="btn btn-outline-secondary">Cancel</a>'
        . '</div>';
    echo '</form>';
    echo '</div></div>';

    // ── Detection JS ──────────────────────────────────────────────────────────
    echo '<script>' . "\n";
    echo 'var CPG_DETECT_URL = ' . $detect_url_js . ';' . "\n";
    echo 'var CPG_CSRF       = ' . $csrf_js . ';' . "\n";
    echo <<<'DETECTJS'
(function () {
  var pathInput  = document.getElementById('cpg-detect-path');
  var detectBtn  = document.getElementById('cpg-detect-btn');
  var statusEl   = document.getElementById('cpg-detect-status');
  var multiEl    = document.getElementById('cpg-detect-multi');

  var fHost   = document.getElementById('db_host');
  var fName   = document.getElementById('db_name');
  var fUser   = document.getElementById('db_user');
  var fPass   = document.getElementById('db_pass');
  var fPrefix = document.getElementById('db_prefix');

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setStatus(msg, type) {
    statusEl.innerHTML = msg;
    statusEl.className = 'small text-' + (type || 'muted');
  }

  function populateForm(config) {
    fHost.value   = config.dbserver    || '';
    fName.value   = config.dbname      || '';
    fUser.value   = config.dbuser      || '';
    fPass.value   = config.dbpass      || '';
    fPrefix.value = config.TABLE_PREFIX || '';
    multiEl.style.display = 'none';
    setStatus(
      '\u2713 Settings detected and populated. Review the fields below, then click \u201cTest Connection\u201d.',
      'success'
    );
  }

  function showMultiple(installations) {
    var html = '<p class="fw-semibold small mb-2 text-warning">'
             + '\u26a0 Multiple Coppermine installations found. Select one to use:</p>'
             + '<div class="list-group mb-2">';
    installations.forEach(function (inst) {
      var disabled = (inst.dbname === '(could not parse)') ? ' disabled' : '';
      html += '<label class="list-group-item list-group-item-action small py-2'
            + (disabled ? ' text-muted' : '') + '">'
            + '<input type="radio" name="cpg-inst-sel" value="' + escHtml(String(inst.index)) + '"'
            + ' class="form-check-input me-2"' + disabled + '>'
            + '<code class="me-1">' + escHtml(inst.rel_path) + '</code>'
            + '<span class="text-muted">'
            + escHtml(inst.dbname) + (inst.dbserver ? ' @ ' + escHtml(inst.dbserver) : '')
            + '</span>'
            + '</label>';
    });
    html += '</div>'
          + '<button type="button" id="cpg-use-sel-btn" class="btn btn-sm btn-outline-primary">'
          + 'Use Selected Installation</button>';

    multiEl.innerHTML     = html;
    multiEl.style.display = '';

    document.getElementById('cpg-use-sel-btn').addEventListener('click', function () {
      var sel = document.querySelector('input[name="cpg-inst-sel"]:checked');
      if (!sel) {
        setStatus('Please select an installation from the list.', 'warning');
        return;
      }
      doSelect(parseInt(sel.value, 10));
    });
  }

  function doAjax(body, onSuccess) {
    detectBtn.disabled = true;
    var xhr  = new XMLHttpRequest();
    xhr.open('POST', CPG_DETECT_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout   = 30000;
    xhr.ontimeout = function () {
      setStatus('Detection request timed out. Check that the server can access the supplied path.', 'danger');
      detectBtn.disabled = false;
    };
    xhr.onerror = function () {
      setStatus('Detection request failed. Check server connectivity.', 'danger');
      detectBtn.disabled = false;
    };
    xhr.onload = function () {
      detectBtn.disabled = false;
      var data;
      try { data = JSON.parse(xhr.responseText); }
      catch (e) { setStatus('Detection failed (HTTP ' + xhr.status + ').', 'danger'); return; }
      if (!data.ok) {
        setStatus('\u2717 ' + escHtml(data.error || 'Detection failed.'), 'danger');
        return;
      }
      onSuccess(data);
    };
    xhr.send(body);
  }

  function doDetect() {
    var path = pathInput.value.trim();
    if (!path) { setStatus('Please enter a filesystem path.', 'warning'); return; }
    setStatus('Searching\u2026', 'muted');
    multiEl.style.display = 'none';
    var body = 'action=find'
             + '&csrf_token=' + encodeURIComponent(CPG_CSRF)
             + '&cpg_path='   + encodeURIComponent(path);
    doAjax(body, function (data) {
      if (data.multiple) {
        showMultiple(data.installations);
        setStatus('Select an installation from the list below.', 'muted');
      } else {
        populateForm(data.config);
      }
    });
  }

  function doSelect(idx) {
    setStatus('Loading\u2026', 'muted');
    var body = 'action=select'
             + '&csrf_token='   + encodeURIComponent(CPG_CSRF)
             + '&select_index=' + encodeURIComponent(idx);
    doAjax(body, function (data) {
      populateForm(data.config);
    });
  }

  detectBtn.addEventListener('click', doDetect);
  pathInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doDetect(); }
  });
})();
DETECTJS;
    echo '</script>' . "\n";

    // ── Step 2: Preview & Apply ───────────────────────────────────────────────────
} elseif ($step === 2) {

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
        lumora_redirect($self_url);
    }

    $importer = new CoppermineImporter(
        $sess['db_host'],
        $sess['db_name'],
        $sess['db_user'],
        $sess['db_pass'],
        $sess['db_prefix']
    );

    try {
        $importer->connect();
        $preview = $importer->previewThumbnailSync();
    } catch (\Throwable $e) {
        lum_flash('Could not load preview: ' . h($e->getMessage()), 'danger');
        lumora_redirect($self_url);
    }

    $csrf_h = h($csrf);

    // Tally counts per status across both record types into one summary table.
    $tally = ['ready' => 0, 'already_set' => 0, 'unmatched' => 0, 'image_unresolved' => 0, 'ambiguous' => 0];
    foreach (['categories', 'albums'] as $type) {
        foreach ($preview[$type] as $row) {
            $tally[$row['status']] = ($tally[$row['status']] ?? 0) + 1;
        }
    }

    echo '<div class="card mb-3" style="max-width:900px;">';
    echo '<div class="card-header">Preview</div>';
    echo '<div class="card-body">';
    echo '<table class="table table-sm table-bordered mb-3" style="max-width:560px;">'
        . '<tr><th>Ready to set</th><td class="text-end">' . $tally['ready'] . '</td></tr>'
        . '<tr><th>Already has a cover (only changes if Overwrite is checked)</th><td class="text-end">' . $tally['already_set'] . '</td></tr>'
        . '<tr><th>Could not be matched automatically</th><td class="text-end">' . $tally['unmatched'] . '</td></tr>'
        . '<tr><th>Matched but cover image could not be resolved</th><td class="text-end">' . $tally['image_unresolved'] . '</td></tr>'
        . '<tr><th>Ambiguous category name (matched more than one)</th><td class="text-end">' . $tally['ambiguous'] . '</td></tr>'
        . '</table>';

    if (empty($preview['categories']) && empty($preview['albums'])) {
        echo '<div class="alert alert-info">No categories or albums in this Coppermine database have a custom '
            . 'cover thumbnail selected &mdash; nothing to sync.</div>';
    }

    // Detailed rows so the admin can see exactly what will happen before applying.
    $row_html = '';
    foreach (['categories' => 'Category', 'albums' => 'Album'] as $type => $label) {
        foreach ($preview[$type] as $row) {
            $name  = h($row[$type === 'categories' ? 'name' : 'title']);
            $badge = match ($row['status']) {
                'ready'            => '<span class="badge bg-success">Ready</span>',
                'already_set'      => '<span class="badge bg-secondary">Has cover</span>',
                'unmatched'        => '<span class="badge bg-warning text-dark">Unmatched</span>',
                'image_unresolved' => '<span class="badge bg-warning text-dark">Image not found</span>',
                'ambiguous'        => '<span class="badge bg-danger">Ambiguous</span>',
                default            => '<span class="badge bg-light text-dark">' . h($row['status']) . '</span>',
            };
            $row_html .= '<tr><td>' . h($label) . '</td><td>' . $name . '</td><td>' . $badge . '</td></tr>';
        }
    }
    if ($row_html !== '') {
        echo '<div class="table-responsive mb-3" style="max-height:320px;overflow-y:auto;">'
            . '<table class="table table-sm table-hover align-middle">'
            . '<thead class="table-light"><tr><th>Type</th><th>Name</th><th>Status</th></tr></thead>'
            . '<tbody>' . $row_html . '</tbody></table></div>';
    }

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action"     value="apply">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">';
    echo '<div class="form-check mb-2">'
        . '<input type="checkbox" id="overwrite" name="overwrite" class="form-check-input">'
        . '<label for="overwrite" class="form-check-label">Overwrite covers that are already set in Lumora</label>'
        . '</div>';
    echo '<div class="alert alert-warning small">'
        . '<strong>Back up your Lumora database before continuing.</strong> Changes are applied inside a '
        . 'single transaction and are logged, but a backup is the only true rollback path.'
        . '<div class="form-check mt-2">'
        . '<input type="checkbox" id="confirm_backup" name="confirm_backup" class="form-check-input" required>'
        . '<label for="confirm_backup" class="form-check-label fw-semibold">I have a backup of the Lumora database</label>'
        . '</div></div>';
    echo '<div class="d-flex gap-2">'
        . '<button type="submit" class="btn btn-success">Apply Changes</button>'
        . '<a href="' . h($self_url) . '" class="btn btn-outline-secondary">&larr; Back</a>'
        . '</div>';
    echo '</form>';
    echo '</div></div>';

    // ── Step done: Report ─────────────────────────────────────────────────────────
} elseif ($step === 'done') {

    $report = $_SESSION[$report_key] ?? null;
    unset($_SESSION[$report_key]);

    if ($report === null) {
        lum_flash('No sync report found. Please run the sync tool again.', 'warning');
        lumora_redirect($self_url);
    }

    echo '<div class="card" style="max-width:600px;">';
    echo '<div class="card-header text-bg-success">Sync Complete</div>';
    echo '<div class="card-body">';
    echo '<table class="table table-sm table-bordered mb-3">'
        . '<tr><th>Records matched</th><td class="text-end">' . (int) $report['matched'] . '</td></tr>'
        . '<tr><th>Updated</th><td class="text-end">' . (int) $report['updated'] . '</td></tr>'
        . '<tr><th>Skipped</th><td class="text-end">' . (int) $report['skipped'] . '</td></tr>'
        . '<tr><th>Overwrite mode</th><td>' . ($report['overwrite'] ? 'On' : 'Off') . '</td></tr>'
        . '</table>';

    if (!empty($report['errors'])) {
        echo '<div class="alert alert-warning"><strong>' . count($report['errors']) . ' error(s):</strong>'
            . '<ul class="mb-0 mt-1 small">';
        foreach (array_slice($report['errors'], 0, 20) as $e) {
            echo '<li>' . h($e) . '</li>';
        }
        if (count($report['errors']) > 20) {
            echo '<li><em>&hellip;and ' . (count($report['errors']) - 20) . ' more (see log file)</em></li>';
        }
        echo '</ul></div>';
    }

    if (!empty($report['log_file'])) {
        echo '<p class="small text-muted">A detailed log was written to <code>' . h($report['log_file']) . '</code>. '
            . 'Restrict web access to <code>plugins/coppermine-importer/logs/</code> or delete old logs periodically.</p>';
    }

    echo '<div class="d-flex gap-2 mt-3">'
        . '<a href="' . h($self_url) . '" class="btn btn-outline-primary btn-sm">Run Again</a>'
        . '<a href="' . h($admin_url) . '" class="btn btn-outline-secondary btn-sm">Admin Dashboard</a>'
        . '</div>';
    echo '</div></div>';
} else {
    lumora_redirect($self_url);
}

$content = ob_get_clean();
$plg_ver = LUMORA_CPG_IMPORTER_VERSION;
lum_admin_page('Coppermine Metadata Sync v' . $plg_ver, $content, 'migrate');

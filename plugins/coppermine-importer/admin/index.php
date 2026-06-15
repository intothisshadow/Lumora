<?php
declare(strict_types=1);
/**
 * Coppermine Importer — Admin Wizard
 *
 * Multi-step import wizard:
 *   Step 1 — Credentials form (GET / POST action=connect)
 *   Step 2 — Preview & options (GET ?step=2 / POST action=start_import)
 *   Step 3 — Import progress (GET ?step=3, AJAX-driven)
 *   Step 4 — Results (GET ?step=done)
 *
 * Session key: lumora_cpg_import
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);

// Bootstrap path: this file is at plugins/coppermine-importer/admin/index.php
// LUMORA_ROOT = dirname(dirname(dirname(__DIR__))) . '/'
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/CoppermineImporter.php';

lumora_require_admin();

// ── Helpers ───────────────────────────────────────────────────────────────────

$sess_key   = 'lumora_cpg_import';
$base_url   = lumora_base_url();
$plugin_url = $base_url . 'plugins/coppermine-importer/admin/';
$ajax_url   = $plugin_url . 'ajax_import.php';
$admin_url  = $base_url . 'admin/';
$step       = (int) ($_GET['step'] ?? 1);
$csrf       = lumora_csrf_token();

// Determine the current wizard step from session or query string
$sess = &$_SESSION[$sess_key];
if (!is_array($sess)) {
    $sess = [];
}

// ── POST handlers ─────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

if ($action === 'connect') {
    // ── Step 1 POST: test connection, store credentials in session ─────────
    lumora_csrf_validate();

    $host   = trim((string) ($_POST['db_host']   ?? ''));
    $name   = trim((string) ($_POST['db_name']   ?? ''));
    $user   = trim((string) ($_POST['db_user']   ?? ''));
    $pass   = (string) ($_POST['db_pass']   ?? '');
    $prefix = trim((string) ($_POST['db_prefix'] ?? ''));
    $confirmed = isset($_POST['confirmed']);

    $importer = new CoppermineImporter($host, $name, $user, $pass, $prefix);
    $result   = $importer->validate();

    if (!$result['ok']) {
        lum_flash('Connection failed: ' . h($result['error'] ?? 'Unknown error'), 'danger');
        lumora_redirect($plugin_url . 'index.php');
    }

    // Store credentials and counts in session
    $sess = [
        'db_host'    => $host,
        'db_name'    => $name,
        'db_user'    => $user,
        'db_pass'    => $pass,
        'db_prefix'  => $prefix,
        'confirmed'  => $confirmed,
        'counts'     => [
            'categories' => $result['categories'],
            'albums'     => $result['albums'],
            'images'     => $result['images'],
        ],
        'imported'      => ['categories' => 0, 'albums' => 0, 'images' => 0],
        'cat_id_map'    => [],
        'album_id_map'  => [],
        'cat_last_id'   => 0,
        'album_last_id' => 0,
        'img_last_id'   => 0,
        'started_at'    => 0,
    ];

    lumora_redirect($plugin_url . 'index.php?step=2');
}

if ($action === 'start_import') {
    // ── Step 2 POST: begin import ──────────────────────────────────────────
    lumora_csrf_validate();

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
        lumora_redirect($plugin_url . 'index.php');
    }

    // Check re-import confirmation
    if (MigrationService::isImported(LUMORA_CPG_IMPORTER_SOURCE) && empty($sess['confirmed'])) {
        if (!isset($_POST['confirm_reimport'])) {
            lum_flash('You must check the re-import confirmation box before proceeding.', 'danger');
            lumora_redirect($plugin_url . 'index.php?step=2');
        }
    }

    $sess['started_at'] = time();
    // Reset progress
    $sess['imported']      = ['categories' => 0, 'albums' => 0, 'images' => 0];
    $sess['cat_id_map']    = [];
    $sess['album_id_map']  = [];
    $sess['cat_last_id']   = 0;
    $sess['album_last_id'] = 0;
    $sess['img_last_id']   = 0;

    lumora_redirect($plugin_url . 'index.php?step=3');
}

if ($action === 'cancel') {
    // Clear session and return to migration hub
    unset($_SESSION[$sess_key]);
    lumora_redirect($admin_url . 'migrate.php');
}

// ── Page rendering ────────────────────────────────────────────────────────────

ob_start();

switch ($step) {

    // ── Step 1: Credentials ───────────────────────────────────────────────────
    case 1:
    default:

        // Re-import warning
        $reimport_warning = '';
        $status = MigrationService::getMigrationStatus(LUMORA_CPG_IMPORTER_SOURCE);
        if ($status !== null) {
            $prev_date = h($status['imported_at']    ?? '');
            $prev_cat  = number_format((int) ($status['categories'] ?? 0));
            $prev_alb  = number_format((int) ($status['albums']     ?? 0));
            $prev_img  = number_format((int) ($status['images']     ?? 0));
            $reimport_warning = <<<HTML
<div class="alert alert-warning">
  <strong>⚠ A Coppermine import has already been performed.</strong><br>
  <strong>Date:</strong> {$prev_date} &nbsp;·&nbsp;
  <strong>Categories:</strong> {$prev_cat} &nbsp;·&nbsp;
  <strong>Albums:</strong> {$prev_alb} &nbsp;·&nbsp;
  <strong>Images:</strong> {$prev_img}<br><br>
  Running the importer again will create <strong>duplicate categories, albums, and images</strong>
  unless you manually clear the existing Lumora content first.
  Only proceed if you know what you are doing.
</div>
HTML;
        }

        $csrf_h = h($csrf);
        echo <<<HTML
{$reimport_warning}

<div class="card" style="max-width:600px;">
  <div class="card-header">Coppermine Database Credentials</div>
  <div class="card-body">
    <p class="text-muted small">
      Enter the connection details for the <strong>Coppermine database</strong>
      (not the Lumora database). The importer opens a separate read-only connection
      to Coppermine to read your categories, albums, and images.
    </p>
    <form method="post" action="">
      <input type="hidden" name="action"     value="connect">
      <input type="hidden" name="csrf_token" value="{$csrf_h}">

      <div class="mb-3">
        <label for="db_host" class="form-label">Database Host</label>
        <input type="text" id="db_host" name="db_host" class="form-control"
               value="localhost" required autocomplete="off">
      </div>
      <div class="mb-3">
        <label for="db_name" class="form-label">Database Name</label>
        <input type="text" id="db_name" name="db_name" class="form-control"
               required autocomplete="off">
      </div>
      <div class="mb-3">
        <label for="db_user" class="form-label">Database User</label>
        <input type="text" id="db_user" name="db_user" class="form-control"
               required autocomplete="off">
      </div>
      <div class="mb-3">
        <label for="db_pass" class="form-label">Database Password</label>
        <input type="password" id="db_pass" name="db_pass" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label for="db_prefix" class="form-label">Table Prefix</label>
        <input type="text" id="db_prefix" name="db_prefix" class="form-control"
               value="cpg_" placeholder="cpg_" autocomplete="off">
        <div class="form-text">Default is <code>cpg_</code>. Older installations may use a different prefix.</div>
      </div>

HTML;

        if ($status !== null) {
            echo <<<HTML
      <div class="mb-3 form-check">
        <input type="checkbox" id="confirmed" name="confirmed" class="form-check-input">
        <label for="confirmed" class="form-check-label text-danger fw-semibold">
          I understand this is a re-import and may create duplicates
        </label>
      </div>
HTML;
        }

        echo <<<HTML
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Test Connection &amp; Continue</button>
        <a href="{$admin_url}migrate.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
HTML;
        break;

    // ── Step 2: Preview & Options ─────────────────────────────────────────────
    case 2:

        if (empty($sess['db_host'])) {
            lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
            lumora_redirect($plugin_url . 'index.php');
        }

        $counts   = $sess['counts'] ?? ['categories' => 0, 'albums' => 0, 'images' => 0];
        $n_cat    = number_format((int) ($counts['categories'] ?? 0));
        $n_alb    = number_format((int) ($counts['albums']     ?? 0));
        $n_img    = number_format((int) ($counts['images']     ?? 0));
        $csrf_h   = h($csrf);

        $reimport_check = '';
        if (MigrationService::isImported(LUMORA_CPG_IMPORTER_SOURCE)) {
            $reimport_check = <<<HTML
<div class="alert alert-warning mb-3">
  <strong>⚠ Re-import warning:</strong>
  A Coppermine import has already been recorded for this gallery.
  <div class="form-check mt-2">
    <input type="checkbox" id="confirm_reimport" name="confirm_reimport" class="form-check-input" required>
    <label for="confirm_reimport" class="form-check-label fw-semibold text-danger">
      I understand that re-importing will create duplicate content unless I have cleared the gallery first.
    </label>
  </div>
</div>
HTML;
        }

        echo <<<HTML
<div class="card mb-3" style="max-width:600px;">
  <div class="card-header">Import Preview</div>
  <div class="card-body">
    <p>Connection successful. The following records will be imported:</p>
    <table class="table table-sm table-bordered mb-3">
      <tr><th>Categories</th><td class="text-end">{$n_cat}</td></tr>
      <tr><th>Albums</th>    <td class="text-end">{$n_alb}</td></tr>
      <tr><th>Images</th>    <td class="text-end">{$n_img}</td></tr>
    </table>
    <div class="alert alert-info small mb-3">
      <strong>Before you continue:</strong>
      Copy or symlink your Coppermine <code>albums/</code> directory into Lumora's
      <code>albums/</code> directory. Image files are not moved by the importer.
      Folder names and filenames are preserved as-is.
    </div>
    {$reimport_check}
    <form method="post" action="">
      <input type="hidden" name="action"     value="start_import">
      <input type="hidden" name="csrf_token" value="{$csrf_h}">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success">Start Import</button>
        <a href="{$plugin_url}index.php" class="btn btn-outline-secondary">← Back</a>
        <form method="post" action="" style="margin:0;">
          <input type="hidden" name="action"     value="cancel">
          <input type="hidden" name="csrf_token" value="{$csrf_h}">
          <button type="submit" class="btn btn-outline-danger">Cancel</button>
        </form>
      </div>
    </form>
  </div>
</div>
HTML;
        break;

    // ── Step 3: Import Progress ───────────────────────────────────────────────
    case 3:

        if (empty($sess['db_host'])) {
            lum_flash('Session expired. Please restart the import.', 'warning');
            lumora_redirect($plugin_url . 'index.php');
        }

        $n_cat  = number_format((int) (($sess['counts'] ?? [])['categories'] ?? 0));
        $n_alb  = number_format((int) (($sess['counts'] ?? [])['albums']     ?? 0));
        $n_img  = number_format((int) (($sess['counts'] ?? [])['images']     ?? 0));
        $plugin_ver = LUMORA_CPG_IMPORTER_VERSION;

        $ajax_url_js = json_encode($ajax_url);
        $csrf_js     = json_encode($csrf);
        $done_url_js = json_encode($plugin_url . 'index.php?step=done');

        echo <<<HTML
<div style="max-width:700px;">
  <div class="card mb-3">
    <div class="card-header">Import Progress</div>
    <div class="card-body">

      <div class="mb-3">
        <div class="d-flex justify-content-between small text-muted mb-1">
          <span>Categories</span>
          <span id="cat-status">Waiting…</span>
        </div>
        <div class="progress mb-3" style="height:20px;">
          <div id="cat-bar" class="progress-bar" role="progressbar" style="width:0%"></div>
        </div>

        <div class="d-flex justify-content-between small text-muted mb-1">
          <span>Albums</span>
          <span id="alb-status">Waiting…</span>
        </div>
        <div class="progress mb-3" style="height:20px;">
          <div id="alb-bar" class="progress-bar" role="progressbar" style="width:0%"></div>
        </div>

        <div class="d-flex justify-content-between small text-muted mb-1">
          <span>Images</span>
          <span id="img-status">Waiting…</span>
        </div>
        <div class="progress mb-3" style="height:20px;">
          <div id="img-bar" class="progress-bar" role="progressbar" style="width:0%"></div>
        </div>
      </div>

      <div id="log" class="small font-monospace bg-light p-2 border rounded"
           style="max-height:220px;overflow-y:auto;">
        Starting import…
      </div>

      <div id="result" class="mt-3" style="display:none;"></div>
    </div>
  </div>
</div>

<script>
(function() {
  var AJAX    = {$ajax_url_js};
  var CSRF    = {$csrf_js};
  var DONE_URL = {$done_url_js};
  var TOTAL   = {categories:{$n_cat.replace(',','')},albums:{$n_alb.replace(',','')},images:{$n_img.replace(',','')}};
  var TOTAL_CAT = parseInt(TOTAL.categories) || 1;
  var TOTAL_ALB = parseInt(TOTAL.albums)     || 1;
  var TOTAL_IMG = parseInt(TOTAL.images)     || 1;

  var imported = {categories:0, albums:0, images:0};
  var phase    = 'categories'; // categories → albums → images → finish

  var catBar    = document.getElementById('cat-bar');
  var albBar    = document.getElementById('alb-bar');
  var imgBar    = document.getElementById('img-bar');
  var catStatus = document.getElementById('cat-status');
  var albStatus = document.getElementById('alb-status');
  var imgStatus = document.getElementById('img-status');
  var log       = document.getElementById('log');
  var result    = document.getElementById('result');

  function setBar(bar, pct) {
    bar.style.width = Math.min(100, pct) + '%';
  }

  function addLog(msg) {
    log.innerHTML += '<div>' + msg + '</div>';
    log.scrollTop  = log.scrollHeight;
  }

  function doPost(action, extra, callback) {
    var xhr  = new XMLHttpRequest();
    var body = 'action=' + encodeURIComponent(action)
             + '&csrf_token=' + encodeURIComponent(CSRF);
    for (var k in extra) {
      if (Object.prototype.hasOwnProperty.call(extra, k)) {
        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
      }
    }
    xhr.open('POST', AJAX, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 300000; // 5 minutes per chunk
    xhr.ontimeout = function() { showError('Request timed out. Refresh and check import status.'); };
    xhr.onerror   = function() { showError('Network error. Check server connectivity.'); };
    xhr.onload    = function() {
      if (xhr.status !== 200) {
        showError('HTTP ' + xhr.status + ': ' + xhr.responseText.substring(0, 200));
        return;
      }
      try {
        callback(JSON.parse(xhr.responseText));
      } catch(e) {
        showError('Invalid JSON response: ' + xhr.responseText.substring(0, 200));
      }
    };
    xhr.send(body);
  }

  function showError(msg) {
    result.style.display = '';
    result.innerHTML     = '<div class="alert alert-danger"><strong>Error:</strong> ' + msg + '</div>';
  }

  function showDone(data) {
    var nCat = data.imported.categories;
    var nAlb = data.imported.albums;
    var nImg = data.imported.images;
    window.location.href = DONE_URL;
  }

  function runChunk() {
    if (phase === 'categories') {
      doPost('import_categories', {}, function(r) {
        imported.categories += r.imported || 0;
        setBar(catBar, (imported.categories / TOTAL_CAT) * 100);
        catStatus.textContent = imported.categories + ' imported';
        (r.errors || []).forEach(function(e) { addLog('[cat] ' + e); });
        if (r.done) {
          catBar.classList.add('bg-success');
          catStatus.textContent = imported.categories + ' imported ✓';
          phase = 'albums';
          albStatus.textContent = 'Running…';
        }
        setTimeout(runChunk, 50);
      });
    } else if (phase === 'albums') {
      doPost('import_albums', {}, function(r) {
        imported.albums += r.imported || 0;
        setBar(albBar, (imported.albums / TOTAL_ALB) * 100);
        albStatus.textContent = imported.albums + ' imported';
        (r.errors || []).forEach(function(e) { addLog('[alb] ' + e); });
        if (r.done) {
          albBar.classList.add('bg-success');
          albStatus.textContent = imported.albums + ' imported ✓';
          phase = 'images';
          imgStatus.textContent = 'Running…';
        }
        setTimeout(runChunk, 50);
      });
    } else if (phase === 'images') {
      doPost('import_images', {}, function(r) {
        imported.images += r.imported || 0;
        setBar(imgBar, (imported.images / TOTAL_IMG) * 100);
        imgStatus.textContent = imported.images + ' imported';
        (r.missing_files > 0) && addLog('[img] ' + r.missing_files + ' missing files in this chunk');
        (r.errors || []).forEach(function(e) { addLog('[img] ' + e); });
        if (r.done) {
          imgBar.classList.add('bg-success');
          imgStatus.textContent = imported.images + ' imported ✓';
          phase = 'finish';
          setTimeout(runChunk, 50);
        } else {
          setTimeout(runChunk, 50);
        }
      });
    } else if (phase === 'finish') {
      doPost('finish', {}, function(r) {
        if (r.ok) {
          showDone(r);
        } else {
          showError(r.error || 'Finish step failed.');
        }
      });
    }
  }

  // Start after a short delay so the page renders first
  setTimeout(runChunk, 300);
})();
</script>
HTML;
        break;

    // ── Step 4: Done ──────────────────────────────────────────────────────────
    case 4: // step=done
    case 0:
        // Check query string
        if (isset($_GET['step']) && $_GET['step'] === 'done') {
            $status = MigrationService::getMigrationStatus(LUMORA_CPG_IMPORTER_SOURCE);
            if ($status === null) {
                lum_flash('Import status not found. The import may not have completed successfully.', 'warning');
                lumora_redirect($plugin_url . 'index.php');
            }
            $n_cat    = number_format((int) ($status['categories'] ?? 0));
            $n_alb    = number_format((int) ($status['albums']     ?? 0));
            $n_img    = number_format((int) ($status['images']     ?? 0));
            $imp_date = h($status['imported_at']    ?? '');
            $imp_ver  = h($status['plugin_version'] ?? '');
            $tools_url = h($admin_url . 'tools.php');

            // Fetch any warnings/errors from the log
            $log_entries = MigrationService::getLogs(LUMORA_CPG_IMPORTER_SOURCE, 50);
            $warnings    = array_filter($log_entries, fn($e) => in_array($e['level'], ['warning', 'error'], true));

            $warn_html = '';
            if (!empty($warnings)) {
                $warn_html .= '<div class="alert alert-warning mt-3"><strong>' . count($warnings)
                    . ' warning(s)/error(s) recorded:</strong><ul class="mb-0 mt-1 small">';
                foreach (array_slice($warnings, 0, 20) as $w) {
                    $warn_html .= '<li>[' . h($w['level']) . '] ' . h($w['message']) . '</li>';
                }
                if (count($warnings) > 20) {
                    $warn_html .= '<li><em>…and ' . (count($warnings) - 20) . ' more</em></li>';
                }
                $warn_html .= '</ul></div>';
            }

            echo <<<HTML
<div class="card" style="max-width:600px;">
  <div class="card-header text-bg-success">Import Complete</div>
  <div class="card-body">
    <p class="fw-semibold">Coppermine data has been imported into Lumora.</p>
    <table class="table table-sm table-bordered mb-3">
      <tr><th>Imported at</th>   <td>{$imp_date}</td></tr>
      <tr><th>Plugin version</th><td>{$imp_ver}</td></tr>
      <tr><th>Categories</th>    <td class="text-end">{$n_cat}</td></tr>
      <tr><th>Albums</th>        <td class="text-end">{$n_alb}</td></tr>
      <tr><th>Images</th>        <td class="text-end">{$n_img}</td></tr>
    </table>
    <p class="small text-muted">
      Run <strong>Tools → File Integrity Check</strong> to verify all image files
      are present in the correct locations.
    </p>
    {$warn_html}
    <div class="d-flex gap-2 mt-3">
      <a href="{$tools_url}" class="btn btn-outline-primary btn-sm">Go to Tools</a>
      <a href="{$admin_url}" class="btn btn-outline-secondary btn-sm">Admin Dashboard</a>
    </div>
  </div>
</div>
HTML;
            break;
        }
        // Fall through to step 1 if ?step= is absent or unrecognised
        lumora_redirect($plugin_url . 'index.php');
        break;
}

$content = ob_get_clean();
$plg_ver = LUMORA_CPG_IMPORTER_VERSION;
lum_admin_page("Coppermine Importer v{$plg_ver}", $content, 'migrate');

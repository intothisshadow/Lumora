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

// ── Page-level variables ──────────────────────────────────────────────────────

$sess_key   = 'lumora_cpg_import';
$base_url   = lumora_base_url();
$plugin_url = $base_url . 'plugins/coppermine-importer/admin/';
$ajax_url   = $plugin_url . 'ajax_import.php';
$admin_url  = $base_url . 'admin/';
$csrf       = lumora_csrf_token();

// ?step=done is a string; cast after handling the 'done' string specially
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

    $host      = trim((string) ($_POST['db_host']   ?? ''));
    $name      = trim((string) ($_POST['db_name']   ?? ''));
    $user      = trim((string) ($_POST['db_user']   ?? ''));
    $pass      = (string) ($_POST['db_pass']         ?? '');
    $prefix    = trim((string) ($_POST['db_prefix'] ?? ''));
    $confirmed = isset($_POST['confirmed']);

    $importer = new CoppermineImporter($host, $name, $user, $pass, $prefix);
    $result   = $importer->validate();

    if (!$result['ok']) {
        lum_flash('Connection failed: ' . h($result['error'] ?? 'Unknown error'), 'danger');
        lumora_redirect($plugin_url . 'index.php');
    }

    $sess = [
        'db_host'       => $host,
        'db_name'       => $name,
        'db_user'       => $user,
        'db_pass'       => $pass,
        'db_prefix'     => $prefix,
        'confirmed'     => $confirmed,
        'counts'        => [
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
    lumora_csrf_validate();

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
        lumora_redirect($plugin_url . 'index.php');
    }

    if (MigrationService::isImported(LUMORA_CPG_IMPORTER_SOURCE) && empty($sess['confirmed'])) {
        if (!isset($_POST['confirm_reimport'])) {
            lum_flash('You must check the re-import confirmation box before proceeding.', 'danger');
            lumora_redirect($plugin_url . 'index.php?step=2');
        }
    }

    $sess['started_at']    = time();
    $sess['imported']      = ['categories' => 0, 'albums' => 0, 'images' => 0];
    $sess['cat_id_map']    = [];
    $sess['album_id_map']  = [];
    $sess['cat_last_id']   = 0;
    $sess['album_last_id'] = 0;
    $sess['img_last_id']   = 0;

    lumora_redirect($plugin_url . 'index.php?step=3');
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

    $status = MigrationService::getMigrationStatus(LUMORA_CPG_IMPORTER_SOURCE);
    $csrf_h = h($csrf);

    $reimport_html = '';
    if ($status !== null) {
        $prev_date = h($status['imported_at']    ?? '');
        $prev_cat  = number_format((int) ($status['categories'] ?? 0));
        $prev_alb  = number_format((int) ($status['albums']     ?? 0));
        $prev_img  = number_format((int) ($status['images']     ?? 0));
        $reimport_html = '<div class="alert alert-warning">'
            . '<strong>&#9888; A Coppermine import has already been performed.</strong><br>'
            . '<strong>Date:</strong> ' . $prev_date . ' &nbsp;&middot;&nbsp;'
            . '<strong>Categories:</strong> ' . $prev_cat . ' &nbsp;&middot;&nbsp;'
            . '<strong>Albums:</strong> ' . $prev_alb . ' &nbsp;&middot;&nbsp;'
            . '<strong>Images:</strong> ' . $prev_img . '<br><br>'
            . 'Running the importer again will create <strong>duplicate categories, albums, and images</strong> '
            . 'unless you manually clear the existing Lumora content first. '
            . 'Only proceed if you know what you are doing.'
            . '</div>';
    }

    $confirm_field = '';
    if ($status !== null) {
        $confirm_field = '<div class="mb-3 form-check">'
            . '<input type="checkbox" id="confirmed" name="confirmed" class="form-check-input">'
            . '<label for="confirmed" class="form-check-label text-danger fw-semibold">'
            . 'I understand this is a re-import and may create duplicates'
            . '</label></div>';
    }

    echo $reimport_html;

    if ($status !== null) {
        echo '<div class="alert alert-info small mb-3" style="max-width:600px;">'
            . 'Only here to fill in missing cover thumbnails on a gallery you already imported? '
            . 'Use the <a href="' . h($plugin_url . 'sync_metadata.php') . '">Metadata Sync tool</a> instead '
            . '&mdash; it does not touch categories, albums, or images.'
            . '</div>';
    }

    echo '<div class="card" style="max-width:600px;">';
    echo '<div class="card-header">Coppermine Database Credentials</div>';
    echo '<div class="card-body">';
    echo '<p class="text-muted small">Enter the connection details for the <strong>Coppermine database</strong> '
        . '(not the Lumora database). The importer opens a separate read-only connection to Coppermine.</p>';
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
    echo $confirm_field;
    echo '<div class="d-flex gap-2">'
        . '<button type="submit" class="btn btn-primary">Test Connection &amp; Continue</button>'
        . '<a href="' . h($admin_url . 'migrate.php') . '" class="btn btn-outline-secondary">Cancel</a>'
        . '</div>';
    echo '</form>';
    echo '</div></div>';

    // ── Step 2: Preview & Options ─────────────────────────────────────────────────
} elseif ($step === 2) {

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please re-enter your Coppermine credentials.', 'warning');
        lumora_redirect($plugin_url . 'index.php');
    }

    $counts    = $sess['counts'] ?? ['categories' => 0, 'albums' => 0, 'images' => 0];
    $n_cat     = number_format((int) ($counts['categories'] ?? 0));
    $n_alb     = number_format((int) ($counts['albums']     ?? 0));
    $n_img     = number_format((int) ($counts['images']     ?? 0));
    $csrf_h    = h($csrf);

    // Re-import confirmation checkbox (rendered INSIDE the start_import form)
    $reimport_check = '';
    if (MigrationService::isImported(LUMORA_CPG_IMPORTER_SOURCE)) {
        $reimport_check = '<div class="alert alert-warning mb-3">'
            . '<strong>&#9888; Re-import warning:</strong> '
            . 'A Coppermine import has already been recorded for this gallery.'
            . '<div class="form-check mt-2">'
            . '<input type="checkbox" id="confirm_reimport" name="confirm_reimport" class="form-check-input" required>'
            . '<label for="confirm_reimport" class="form-check-label fw-semibold text-danger">'
            . 'I understand that re-importing will create duplicate content unless I have cleared the gallery first.'
            . '</label></div></div>';
    }

    echo '<div class="card mb-3" style="max-width:600px;">';
    echo '<div class="card-header">Import Preview</div>';
    echo '<div class="card-body">';
    echo '<p>Connection successful. The following records will be imported:</p>';
    echo '<table class="table table-sm table-bordered mb-3">'
        . '<tr><th>Categories</th><td class="text-end">' . $n_cat . '</td></tr>'
        . '<tr><th>Albums</th>    <td class="text-end">' . $n_alb . '</td></tr>'
        . '<tr><th>Images</th>    <td class="text-end">' . $n_img . '</td></tr>'
        . '</table>';
    echo '<div class="alert alert-info small mb-3">'
        . '<strong>Before you continue:</strong> Copy or symlink your Coppermine <code>albums/</code> '
        . 'directory into Lumora\'s <code>albums/</code> directory. Image files are not moved by the importer. '
        . 'Folder names and filenames are preserved as-is.'
        . '</div>';

    // Start Import form — reimport_check is INSIDE this form, not nested
    echo '<form method="post" action="" id="cpg-start-form">';
    echo '<input type="hidden" name="action"     value="start_import">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">';
    echo $reimport_check;
    echo '<div class="d-flex gap-2">';
    echo '<button type="submit" class="btn btn-success">Start Import</button>';
    echo '<a href="' . h($plugin_url . 'index.php') . '" class="btn btn-outline-secondary">&larr; Back</a>';
    // Cancel button uses a sibling form via the HTML5 form= attribute — no nesting
    echo '<button type="submit" form="cpg-cancel-form" class="btn btn-outline-danger">Cancel</button>';
    echo '</div>';
    echo '</form>';

    // Sibling cancel form — empty body, no layout impact
    echo '<form method="post" action="" id="cpg-cancel-form" style="display:none;">';
    echo '<input type="hidden" name="action"     value="cancel">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">';
    echo '</form>';

    echo '</div></div>';

    // ── Step 3: Import Progress ───────────────────────────────────────────────────
} elseif ($step === 3) {

    if (empty($sess['db_host'])) {
        lum_flash('Session expired. Please restart the import.', 'warning');
        lumora_redirect($plugin_url . 'index.php');
    }

    // Pre-compute integer counts for safe JS embedding — never use string methods inside nowdoc
    $n_cat_int = (int) (($sess['counts'] ?? [])['categories'] ?? 0);
    $n_alb_int = (int) (($sess['counts'] ?? [])['albums']     ?? 0);
    $n_img_int = (int) (($sess['counts'] ?? [])['images']     ?? 0);

    $ajax_url_js = json_encode($ajax_url);
    $csrf_js     = json_encode($csrf);
    $done_url_js = json_encode($plugin_url . 'index.php?step=done');

    echo '<div style="max-width:700px;">';
    echo '<div class="card mb-3">';
    echo '<div class="card-header">Import Progress</div>';
    echo '<div class="card-body">';
    echo '<div class="mb-3">';

    echo '<div class="d-flex justify-content-between small text-muted mb-1">'
        . '<span>Categories</span><span id="cat-status">Waiting&hellip;</span></div>';
    echo '<div class="progress mb-3" style="height:20px;">'
        . '<div id="cat-bar" class="progress-bar" role="progressbar" style="width:0%"></div></div>';

    echo '<div class="d-flex justify-content-between small text-muted mb-1">'
        . '<span>Albums</span><span id="alb-status">Waiting&hellip;</span></div>';
    echo '<div class="progress mb-3" style="height:20px;">'
        . '<div id="alb-bar" class="progress-bar" role="progressbar" style="width:0%"></div></div>';

    echo '<div class="d-flex justify-content-between small text-muted mb-1">'
        . '<span>Images</span><span id="img-status">Waiting&hellip;</span></div>';
    echo '<div class="progress mb-3" style="height:20px;">'
        . '<div id="img-bar" class="progress-bar" role="progressbar" style="width:0%"></div></div>';

    echo '<div class="d-flex justify-content-between small text-muted mb-1">'
        . '<span>Cover images</span><span id="cov-status">Waiting&hellip;</span></div>';

    echo '</div>'; // .mb-3

    echo '<div id="log" class="small font-monospace bg-light p-2 border rounded mb-2" '
        . 'style="max-height:220px;overflow-y:auto;">Starting import&hellip;</div>';

    echo '<div id="cpg-stop-wrap" class="mb-2">'
        . '<button id="cpg-stop-btn" type="button" class="btn btn-sm btn-outline-warning">'
        . '&#9209; Stop Import</button></div>';

    echo '<div id="result" class="mt-2" style="display:none;"></div>';
    echo '</div></div></div>'; // .card-body .card .outer

    // JS: integer literals injected directly — no PHP string-method calls inside JS template
    echo '<script>' . "\n";
    echo '(function() {' . "\n";
    echo '  var AJAX     = ' . $ajax_url_js . ';' . "\n";
    echo '  var CSRF     = ' . $csrf_js . ';' . "\n";
    echo '  var DONE_URL = ' . $done_url_js . ';' . "\n";
    echo '  var TOTAL_CAT = ' . $n_cat_int . ' || 1;' . "\n";
    echo '  var TOTAL_ALB = ' . $n_alb_int . ' || 1;' . "\n";
    echo '  var TOTAL_IMG = ' . $n_img_int . ' || 1;' . "\n";
    echo <<<'JSEOF'

  var imported = {categories: 0, albums: 0, images: 0};
  var phase    = 'categories';
  var stopped  = false;

  var catBar    = document.getElementById('cat-bar');
  var albBar    = document.getElementById('alb-bar');
  var imgBar    = document.getElementById('img-bar');
  var catStatus = document.getElementById('cat-status');
  var albStatus = document.getElementById('alb-status');
  var imgStatus = document.getElementById('img-status');
  var covStatus = document.getElementById('cov-status');
  var log       = document.getElementById('log');
  var result    = document.getElementById('result');
  var stopBtn   = document.getElementById('cpg-stop-btn');
  var stopWrap  = document.getElementById('cpg-stop-wrap');

  stopBtn.addEventListener('click', function() {
    stopped = true;
    stopBtn.disabled    = true;
    stopBtn.textContent = 'Stopping after current batch\u2026';
  });

  function setBar(bar, n, total) {
    bar.style.width = Math.min(100, Math.round((n / total) * 100)) + '%';
  }

  function addLog(msg) {
    log.innerHTML += '<div>' + msg + '</div>';
    log.scrollTop  = log.scrollHeight;
  }

  function hideStop() {
    stopWrap.style.display = 'none';
  }

  function showResult(html) {
    hideStop();
    result.style.display = '';
    result.innerHTML     = html;
  }

  function showError(msg) {
    showResult('<div class="alert alert-danger"><strong>Error:</strong> ' + msg + '</div>');
  }

  function doPost(action, callback) {
    var xhr  = new XMLHttpRequest();
    var body = 'action=' + encodeURIComponent(action)
             + '&csrf_token=' + encodeURIComponent(CSRF);
    xhr.open('POST', AJAX, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout   = 300000;
    xhr.ontimeout = function() { showError('Request timed out. Refresh and check import status.'); };
    xhr.onerror   = function() { showError('Network error. Check server connectivity.'); };
    xhr.onload    = function() {
      if (xhr.status !== 200) {
        showError('HTTP ' + xhr.status + ': ' + xhr.responseText.substring(0, 200));
        return;
      }
      try {
        callback(JSON.parse(xhr.responseText));
      } catch (e) {
        showError('Invalid JSON response: ' + xhr.responseText.substring(0, 200));
      }
    };
    xhr.send(body);
  }

  function runChunk() {
    if (phase === 'categories') {
      doPost('import_categories', function(r) {
        imported.categories += r.imported || 0;
        setBar(catBar, imported.categories, TOTAL_CAT);
        catStatus.textContent = imported.categories + ' imported';
        (r.errors || []).forEach(function(e) { addLog('[cat] ' + e); });
        if (r.done) {
          catBar.classList.add('bg-success');
          catStatus.textContent = imported.categories + ' imported \u2713';
          phase = 'albums';
          albStatus.textContent = 'Running\u2026';
        }
        if (stopped && r.done) { showStopped(); return; }
        setTimeout(runChunk, 50);
      });
    } else if (phase === 'albums') {
      doPost('import_albums', function(r) {
        imported.albums += r.imported || 0;
        setBar(albBar, imported.albums, TOTAL_ALB);
        albStatus.textContent = imported.albums + ' imported';
        (r.errors || []).forEach(function(e) { addLog('[alb] ' + e); });
        if (r.done) {
          albBar.classList.add('bg-success');
          albStatus.textContent = imported.albums + ' imported \u2713';
          phase = 'images';
          imgStatus.textContent = 'Running\u2026';
        }
        if (stopped && r.done) { showStopped(); return; }
        setTimeout(runChunk, 50);
      });
    } else if (phase === 'images') {
      doPost('import_images', function(r) {
        imported.images += r.imported || 0;
        setBar(imgBar, imported.images, TOTAL_IMG);
        imgStatus.textContent = imported.images + ' imported';
        if (r.missing_files > 0) addLog('[img] ' + r.missing_files + ' missing files in this chunk');
        (r.errors || []).forEach(function(e) { addLog('[img] ' + e); });
        if (r.done) {
          imgBar.classList.add('bg-success');
          imgStatus.textContent = imported.images + ' imported \u2713';
          // All images done — proceed to cover assignment even if stopped.
          // Covers are a single fast call, not a loop, so "stop" only prevents
          // more image chunks from starting; it doesn't skip this step.
          phase = 'covers';
          covStatus.textContent = 'Assigning\u2026';
        }
        // Stop mid-import (images not yet done): halt after this chunk.
        if (stopped && !r.done) { showStopped(); return; }
        setTimeout(runChunk, 50);
      });
    } else if (phase === 'covers') {
      // Cover assignment is non-critical and single-call.
      // Proceed to finish even on network error or server failure.
      var cxhr  = new XMLHttpRequest();
      var cbody = 'action=apply_covers&csrf_token=' + encodeURIComponent(CSRF);
      cxhr.open('POST', AJAX, true);
      cxhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      cxhr.timeout = 60000;
      cxhr.onload = function() {
        try {
          var cr = JSON.parse(cxhr.responseText);
          covStatus.textContent = (cr.updated || 0) + ' assigned \u2713';
          (cr.warnings || []).forEach(function(e) { addLog('[cover] ' + e); });
        } catch(e) {
          addLog('[cover] Could not apply covers \u2014 automatic cover selection will be used');
          covStatus.textContent = 'skipped';
        }
        phase = 'finish';
        setTimeout(runChunk, 50);
      };
      cxhr.ontimeout = cxhr.onerror = function() {
        addLog('[cover] Cover assignment timed out \u2014 automatic cover selection will be used');
        covStatus.textContent = 'skipped (timeout)';
        phase = 'finish';
        setTimeout(runChunk, 50);
      };
      cxhr.send(cbody);
    } else if (phase === 'finish') {
      doPost('finish', function(r) {
        hideStop();
        if (r.ok) { window.location.href = DONE_URL; }
        else       { showError(r.error || 'Finish step failed.'); }
      });
    }
  }

  function showStopped() {
    showResult('<div class="alert alert-warning">'
      + '<strong>\u26a0 Import stopped.</strong> '
      + 'Partial data has been written to the database. '
      + 'You can restart the import from the beginning, or check '
      + '<a href="' + DONE_URL.replace('step=done', '') + 'index.php">Admin &rarr; Import</a> '
      + 'to review what was imported.'
      + '</div>');
  }

  setTimeout(runChunk, 300);
})();
JSEOF;
    echo '</script>' . "\n";

    // ── Step done: Results ────────────────────────────────────────────────────────
} elseif ($step === 'done') {

    $status = MigrationService::getMigrationStatus(LUMORA_CPG_IMPORTER_SOURCE);
    if ($status === null) {
        lum_flash('Import status not found. The import may not have completed successfully.', 'warning');
        lumora_redirect($plugin_url . 'index.php');
    }

    $n_cat    = number_format((int) ($status['categories']    ?? 0));
    $n_alb    = number_format((int) ($status['albums']        ?? 0));
    $n_img    = number_format((int) ($status['images']        ?? 0));
    $imp_date = h($status['imported_at']    ?? '');
    $imp_ver  = h($status['plugin_version'] ?? '');

    $log_entries = MigrationService::getLogs(LUMORA_CPG_IMPORTER_SOURCE, 50);
    $warnings    = array_filter($log_entries, static fn($e) => in_array($e['level'], ['warning', 'error'], true));

    $warn_html = '';
    if (!empty($warnings)) {
        $warn_html  = '<div class="alert alert-warning mt-3"><strong>' . count($warnings)
            . ' warning(s)/error(s) recorded:</strong><ul class="mb-0 mt-1 small">';
        foreach (array_slice(array_values($warnings), 0, 20) as $w) {
            $warn_html .= '<li>[' . h($w['level']) . '] ' . h($w['message']) . '</li>';
        }
        if (count($warnings) > 20) {
            $warn_html .= '<li><em>&hellip;and ' . (count($warnings) - 20) . ' more</em></li>';
        }
        $warn_html .= '</ul></div>';
    }

    echo '<div class="card" style="max-width:600px;">';
    echo '<div class="card-header text-bg-success">Import Complete</div>';
    echo '<div class="card-body">';
    echo '<p class="fw-semibold">Coppermine data has been imported into Lumora.</p>';
    echo '<table class="table table-sm table-bordered mb-3">'
        . '<tr><th>Imported at</th>   <td>' . $imp_date . '</td></tr>'
        . '<tr><th>Plugin version</th><td>' . $imp_ver  . '</td></tr>'
        . '<tr><th>Categories</th>    <td class="text-end">' . $n_cat . '</td></tr>'
        . '<tr><th>Albums</th>        <td class="text-end">' . $n_alb . '</td></tr>'
        . '<tr><th>Images</th>        <td class="text-end">' . $n_img . '</td></tr>'
        . '</table>';
    echo '<p class="small text-muted">Run <strong>Tools &rarr; File Integrity Check</strong> '
        . 'to verify all image files are present in the correct locations.</p>';
    echo '<p class="small text-muted">Album or category covers not set as expected? Use the '
        . '<a href="' . h($plugin_url . 'sync_metadata.php') . '">Metadata Sync tool</a> '
        . 'to re-run cover assignment or fix any that were skipped.</p>';
    echo $warn_html;
    echo '<div class="d-flex gap-2 mt-3">'
        . '<a href="' . h($admin_url . 'tools.php')  . '" class="btn btn-outline-primary btn-sm">Go to Tools</a>'
        . '<a href="' . h($admin_url)                . '" class="btn btn-outline-secondary btn-sm">Admin Dashboard</a>'
        . '</div>';
    echo '</div></div>';
} else {
    // Unknown step — redirect to step 1
    lumora_redirect($plugin_url . 'index.php');
}

$content = ob_get_clean();
$plg_ver = LUMORA_CPG_IMPORTER_VERSION;
lum_admin_page('Coppermine Importer v' . $plg_ver, $content, 'migrate');

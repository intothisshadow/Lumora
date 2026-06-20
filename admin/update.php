<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Updates
 *
 * Displays the current update status and lets administrators manually
 * trigger a check against the Lumora release endpoint.
 *
 * Also surfaces schema migration status and provides a one-click
 * "Run Database Update" action (calls SchemaService via AJAX).
 *
 * On page load only the cached status is shown — no network call is made
 * by PHP.  If the cache is expired the page auto-triggers an AJAX check
 * after the DOM loads so there is never a server-side fetch delay.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Application update status (cache-only, no HTTP call at render time) ───────
$upd           = UpdateService::getCachedStatus();
$cache_expired = UpdateService::isCacheExpired();

$csrf_js       = json_encode(lumora_csrf_token());
$ajax_base_js  = json_encode(lumora_base_url() . 'admin/');
$auto_check_js = $cache_expired ? 'true' : 'false';
$endpoint_h    = h(UpdateService::getEndpointUrl());

// ── Schema migration status ───────────────────────────────────────────────────
$mig_status   = SchemaService::getMigrationStatus();
$mig_pending  = count($mig_status['pending']);
$mig_applied  = count($mig_status['applied']);

// Build the DB updates card content.
if ($mig_pending === 0) {
    $db_updates_block = '<table class="table table-sm mb-0" style="max-width:400px">'
        . '<tr><th class="text-muted fw-normal" style="width:160px">Schema status</th>'
        . '<td><span class="badge bg-success">✓ Up to date</span></td></tr>'
        . '<tr><th class="text-muted fw-normal">Applied</th>'
        . '<td class="text-muted small">' . $mig_applied . ' migration(s)</td></tr>'
        . '</table>';
} else {
    $pending_items_html = '';
    foreach ($mig_status['pending'] as $m) {
        $pending_items_html .= '<li class="small font-monospace">' . h($m) . '</li>';
    }
    $db_updates_block = '<table class="table table-sm mb-2" style="max-width:400px">'
        . '<tr><th class="text-muted fw-normal" style="width:160px">Schema status</th>'
        . '<td><span class="badge bg-warning text-dark">⚠ ' . $mig_pending . ' pending</span></td></tr>'
        . '<tr><th class="text-muted fw-normal">Applied</th>'
        . '<td class="text-muted small">' . $mig_applied . ' migration(s)</td></tr>'
        . '</table>'
        . '<p class="small text-muted mb-1">Pending migrations:</p>'
        . '<ul class="mb-3">' . $pending_items_html . '</ul>'
        . '<div>'
        . '<button id="lum-migrate-btn" class="btn btn-warning">🗄 Run Database Update</button>'
        . '<span id="lum-migrate-spinner" class="text-muted small ms-2 d-none">Running…</span>'
        . '</div>'
        . '<div id="lum-migrate-result" class="mt-3"></div>';
}

// ── Build initial application status block (replaced by JS after AJAX check) ──
$installed_h = h($upd['installed']);

$status_badge = match($upd['status']) {
    'up_to_date'       => '<span class="badge bg-success fs-6">✓ Up to date</span>',
    'update_available' => '<span class="badge bg-warning text-dark fs-6">🔔 Update available</span>',
    'error'            => '<span class="badge bg-danger fs-6">⚠ Error</span>',
    default            => '<span class="badge bg-secondary fs-6">— Not checked yet</span>',
};

$checked_str = $upd['checked_at'] !== null
    ? h(date('Y-m-d H:i', $upd['checked_at'])) . ' UTC'
    : 'Never';

$error_block = $upd['error'] !== null
    ? '<div class="alert alert-warning py-2 mt-3 small">' . h($upd['error']) . '</div>'
    : '';

$update_block = '';
if ($upd['status'] === 'update_available' && $upd['latest'] !== null) {
    $latest_h  = h($upd['latest']);
    $dl_url_h  = $upd['download_url']  !== null ? h($upd['download_url'])  : null;
    $cl_url_h  = $upd['changelog_url'] !== null ? h($upd['changelog_url']) : null;
    $min_php_h = $upd['minimum_php']   !== null ? h($upd['minimum_php'])   : null;

    $release_row = $upd['release_date'] !== null
        ? '<tr><th class="text-muted fw-normal">Released</th><td>' . h($upd['release_date']) . '</td></tr>'
        : '';

    $dl_btn = $dl_url_h
        ? '<a href="' . $dl_url_h . '" target="_blank" rel="noopener" class="btn btn-primary btn-sm me-2">⬇ Download ' . $latest_h . '</a>'
        : '';
    $cl_btn = $cl_url_h
        ? '<a href="' . $cl_url_h . '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">📋 View Changelog</a>'
        : '';

    $php_warn = ($upd['minimum_php'] !== null && version_compare(PHP_VERSION, $upd['minimum_php'], '<'))
        ? '<div class="alert alert-warning py-2 mt-3 small">⚠ Lumora '
          . $latest_h . ' requires PHP ' . $min_php_h
          . '. Your server is running PHP ' . h(PHP_VERSION)
          . '. Please upgrade PHP before installing this update.</div>'
        : '';

    $update_block = <<<HTML
<div class="lum-adm-card mt-3">
  <table class="table table-sm mb-3" style="max-width:400px">
    <tr><th class="text-muted fw-normal" style="width:150px">New version</th><td class="fw-semibold">{$latest_h}</td></tr>
    {$release_row}
  </table>
  {$dl_btn}{$cl_btn}{$php_warn}
</div>
HTML;
}

$status_block = <<<HTML
<table class="table table-sm mb-0" style="max-width:400px">
  <tr><th class="text-muted fw-normal" style="width:150px">Installed</th><td class="fw-semibold">{$installed_h}</td></tr>
  <tr><th class="text-muted fw-normal">Status</th><td>{$status_badge}</td></tr>
  <tr><th class="text-muted fw-normal">Last checked</th><td class="text-muted small">{$checked_str}</td></tr>
</table>
{$error_block}{$update_block}
HTML;

$content = <<<HTML
<!-- ── Current application version status ────────────────────────────────── -->
<div id="lum-update-status" class="lum-adm-card mb-4">
  {$status_block}
</div>

<!-- ── Database schema updates ───────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">🗄 Database Updates</h5>
  {$db_updates_block}
</div>

<!-- ── Check for application updates ─────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">🔄 Check for Updates</h5>
  <p class="text-muted small mb-3">
    Checks the official Lumora update endpoint for a new release.
    Results are cached for 24 hours — use this button to force an immediate refresh.
    No gallery data is transmitted; only a plain GET request is made.
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-check-btn" class="btn btn-primary">🔄 Check for Updates Now</button>
    <span id="lum-check-spinner" class="text-muted small d-none">Checking…</span>
  </div>
  <div id="lum-check-result" class="mt-3"></div>
</div>

<!-- ── Info ──────────────────────────────────────────────────────────────── -->
<div class="lum-adm-card">
  <h5 class="mb-2">ℹ About Update Checks</h5>
  <ul class="small text-muted mb-0">
    <li>Update results are cached locally for 24 hours.</li>
    <li>No gallery content, images, or user data is ever transmitted.</li>
    <li>Update endpoint: <code>{$endpoint_h}</code></li>
    <li>Downloading and installing application updates is a manual process in this version.</li>
    <li>Database schema migrations can be applied with one click using the <strong>Run Database Update</strong> button above.</li>
  </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const CSRF       = {$csrf_js};
  const AJAX_BASE  = {$ajax_base_js};
  const AUTO_CHECK = {$auto_check_js};

  // ── Application update check ───────────────────────────────────────────────

  const \$btn      = document.getElementById('lum-check-btn');
  const \$spinner  = document.getElementById('lum-check-spinner');
  const \$result   = document.getElementById('lum-check-result');
  const \$statusEl = document.getElementById('lum-update-status');

  if (\$btn) {
    \$btn.addEventListener('click', function () { runCheck(); });
  }

  // Auto-trigger an AJAX check when the cache is stale so the page stays
  // up-to-date without blocking PHP rendering.
  if (AUTO_CHECK) {
    runCheck();
  }

  async function runCheck() {
    if (\$btn)     { \$btn.disabled = true; \$btn.textContent = 'Checking…'; }
    if (\$spinner)   \$spinner.classList.remove('d-none');
    if (\$result)    \$result.innerHTML = '';

    try {
      const resp = await fetch(AJAX_BASE + 'ajax_update_check.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : 'csrf_token=' + encodeURIComponent(CSRF),
      });
      if (!resp.ok) throw new Error('Server returned ' + resp.status);
      const data = await resp.json();
      if (data.error && !data.latest) throw new Error(data.error);

      if (\$statusEl) \$statusEl.innerHTML = renderStatus(data);

      // Show non-fatal errors (e.g. stale-cache fallback notice) below button.
      if (data.error && \$result) {
        \$result.innerHTML =
          '<div class="alert alert-warning py-2 small">⚠ ' + esc(data.error) + '</div>';
      }
    } catch (err) {
      if (\$result) {
        \$result.innerHTML =
          '<div class="alert alert-danger py-2 small">Error: ' + esc(err.message) + '</div>';
      }
    } finally {
      if (\$btn)    { \$btn.disabled = false; \$btn.textContent = '🔄 Check for Updates Now'; }
      if (\$spinner)  \$spinner.classList.add('d-none');
    }
  }

  /** Render the application status block HTML from a JSON response object. */
  function renderStatus(d) {
    const installed   = esc(d.installed || '');
    const latest      = d.latest ? esc(d.latest) : null;
    const releaseDate = d.release_date ? esc(d.release_date) : null;
    const dlUrl       = d.download_url  || '';
    const clUrl       = d.changelog_url || '';
    const checkedAt   = d.checked_at
      ? new Date(d.checked_at * 1000).toISOString().replace('T', ' ').slice(0, 16) + ' UTC'
      : 'Never';

    const badge = (() => {
      switch (d.status) {
        case 'up_to_date':       return '<span class="badge bg-success fs-6">&#x2713; Up to date</span>';
        case 'update_available': return '<span class="badge bg-warning text-dark fs-6">&#x1F514; Update available</span>';
        case 'error':            return '<span class="badge bg-danger fs-6">&#x26A0; Error</span>';
        default:                 return '<span class="badge bg-secondary fs-6">&#x2014; Not checked yet</span>';
      }
    })();

    let html =
        '<table class="table table-sm mb-0" style="max-width:400px">'
      + '<tr><th class="text-muted fw-normal" style="width:150px">Installed</th><td class="fw-semibold">' + installed + '</td></tr>'
      + '<tr><th class="text-muted fw-normal">Status</th><td>' + badge + '</td></tr>'
      + '<tr><th class="text-muted fw-normal">Last checked</th><td class="text-muted small">' + esc(checkedAt) + '</td></tr>'
      + '</table>';

    if (d.status === 'update_available' && latest) {
      const dlBtn = dlUrl
        ? '<a href="' + escAttr(dlUrl) + '" target="_blank" rel="noopener" class="btn btn-primary btn-sm me-2">&#x2B07; Download ' + latest + '</a>'
        : '';
      const clBtn = clUrl
        ? '<a href="' + escAttr(clUrl) + '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">&#x1F4CB; View Changelog</a>'
        : '';
      const releaseRow = releaseDate
        ? '<tr><th class="text-muted fw-normal">Released</th><td>' + releaseDate + '</td></tr>'
        : '';

      html +=
          '<div class="lum-adm-card mt-3">'
        + '<table class="table table-sm mb-3" style="max-width:400px">'
        + '<tr><th class="text-muted fw-normal" style="width:150px">New version</th><td class="fw-semibold">' + latest + '</td></tr>'
        + releaseRow
        + '</table>'
        + dlBtn + clBtn
        + '</div>';
    }

    return html;
  }

  // ── Database migration runner ──────────────────────────────────────────────

  const \$migrateBtn = document.getElementById('lum-migrate-btn');
  if (\$migrateBtn) {
    \$migrateBtn.addEventListener('click', async function () {
      const \$migSpinner = document.getElementById('lum-migrate-spinner');
      const \$migResult  = document.getElementById('lum-migrate-result');

      \$migrateBtn.disabled    = true;
      \$migrateBtn.textContent = 'Running…';
      if (\$migSpinner) \$migSpinner.classList.remove('d-none');
      if (\$migResult)  \$migResult.innerHTML = '';

      try {
        const resp = await fetch(AJAX_BASE + 'ajax_run_migrations.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body   : 'csrf_token=' + encodeURIComponent(CSRF),
        });
        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();

        if (data.success) {
          if (\$migResult) {
            \$migResult.innerHTML =
              '<div class="alert alert-success py-2">✓ ' + esc(data.message) + '</div>';
          }
          // Reload the page after a short pause so the updated status is shown.
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          let html = '<div class="alert alert-danger py-2">✗ ' + esc(data.message);
          if (data.errors && data.errors.length) {
            html += '<ul class="mb-0 mt-1">'
              + data.errors.map(function (e) {
                  return '<li class="small font-monospace">' + esc(e) + '</li>';
                }).join('')
              + '</ul>';
          }
          html += '</div>';
          if (\$migResult) \$migResult.innerHTML = html;
          \$migrateBtn.disabled    = false;
          \$migrateBtn.textContent = '🗄 Run Database Update';
        }
      } catch (err) {
        if (\$migResult) {
          \$migResult.innerHTML =
            '<div class="alert alert-danger py-2">Error: ' + esc(err.message) + '</div>';
        }
        \$migrateBtn.disabled    = false;
        \$migrateBtn.textContent = '🗄 Run Database Update';
      } finally {
        if (\$migSpinner) \$migSpinner.classList.add('d-none');
      }
    });
  }

  // ── Shared helpers ─────────────────────────────────────────────────────────

  function esc(val) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(val)));
    return d.innerHTML;
  }

  function escAttr(val) {
    return String(val)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }
});
</script>
HTML;

lum_admin_page('Updates', $content, 'updates');

<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Updates
 *
 * Displays current version status and allows administrators to apply the
 * latest available Lumora release entirely from within the dashboard:
 *
 *   1. Update check — fetches metadata from the configured release provider
 *      (GitHub Releases API by default) and caches the result for 24 hours.
 *
 *   2. One-click updater — multi-step workflow:
 *         Pre-flight → Download → Verify → Backup → Maintenance →
 *         Extract → Validate → Replace files → Migrate DB → Cleanup
 *      Each step is a separate AJAX call so the browser can report granular
 *      progress.  Failed stages offer a Rollback option that restores the
 *      database and config.php from the automatic backup.
 *
 *   3. Database migrations — independent of the file updater; applies any
 *      pending SchemaService migrations via ajax_run_migrations.php.
 *
 *   4. Update history — last 10 update attempts stored in the config table.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Application update status (cache-only on page load) ───────────────────────
$upd           = UpdateService::getCachedStatus();
$cache_expired = UpdateService::isCacheExpired();
$upd_available = $upd['status'] === 'update_available' && $upd['latest'] !== null;

// ── Updater state ─────────────────────────────────────────────────────────────
$updater_running = UpdaterService::isUpdateRunning();
$updater_lock    = $updater_running ? UpdaterService::getLockInfo() : null;
$update_history  = UpdaterService::getUpdateHistory();

// ── Schema migration status ───────────────────────────────────────────────────
$mig_status  = SchemaService::getMigrationStatus();
$mig_pending = count($mig_status['pending']);
$mig_applied = count($mig_status['applied']);

// ── JS variables ──────────────────────────────────────────────────────────────
$csrf_js      = json_encode(lumora_csrf_token());
$ajax_base_js = json_encode(lumora_base_url() . 'admin/');
$auto_check_js = $cache_expired ? 'true' : 'false';
$endpoint_h    = h(UpdateService::getEndpointUrl());

// ── Latest available version info (for "Update Now" target) ──────────────────
$latest_h   = $upd['latest'] !== null ? h($upd['latest']) : '';
$latest_js  = json_encode($upd['latest'] ?? '');
$rel_date_h = $upd['release_date'] !== null ? h($upd['release_date']) : 'N/A';
$dl_url_h   = $upd['download_url']  !== null ? h($upd['download_url'])  : null;
$cl_url_h   = $upd['changelog_url'] !== null ? h($upd['changelog_url']) : null;
$min_php_h  = $upd['minimum_php']   !== null ? h($upd['minimum_php'])   : null;

// PHP version compatibility warning.
$php_compat_warn = ($upd['minimum_php'] !== null && version_compare(PHP_VERSION, $upd['minimum_php'], '<'))
    ? '<div class="alert alert-danger py-2 mt-2 small">⚠ Lumora '
      . $latest_h . ' requires PHP ' . $min_php_h
      . '. Your server is running PHP ' . h(PHP_VERSION)
      . '. <strong>Do not update</strong> until you have upgraded PHP.</div>'
    : '';
$php_ok = $php_compat_warn === '';

// ── Build DB updates card ──────────────────────────────────────────────────────
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

// ── Build application version status block ────────────────────────────────────
$installed_h  = h($upd['installed']);
$status_badge = match ($upd['status']) {
    'up_to_date'       => '<span class="badge bg-success fs-6">✓ Up to date</span>',
    'update_available' => '<span class="badge bg-warning text-dark fs-6">🔔 Update available</span>',
    'error'            => '<span class="badge bg-danger fs-6">⚠ Error</span>',
    default            => '<span class="badge bg-secondary fs-6">— Not checked yet</span>',
};
$checked_str  = $upd['checked_at'] !== null
    ? h(date('Y-m-d H:i', $upd['checked_at'])) . ' UTC'
    : 'Never';
$error_block  = $upd['error'] !== null
    ? '<div class="alert alert-warning py-2 mt-3 small">' . h($upd['error']) . '</div>'
    : '';

$update_avail_block = '';
if ($upd_available) {
    $release_row = $upd['release_date'] !== null
        ? '<tr><th class="text-muted fw-normal">Released</th><td>' . $rel_date_h . '</td></tr>'
        : '';
    $cl_btn = $cl_url_h
        ? '<a href="' . $cl_url_h . '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">📋 View Changelog</a>'
        : '';

    $update_avail_block = <<<HTML
<div class="lum-adm-card mt-3">
  <table class="table table-sm mb-3" style="max-width:400px">
    <tr><th class="text-muted fw-normal" style="width:150px">New version</th><td class="fw-semibold">{$latest_h}</td></tr>
    {$release_row}
  </table>
  {$cl_btn}
  {$php_compat_warn}
</div>
HTML;
}

$status_block = <<<HTML
<table class="table table-sm mb-0" style="max-width:400px">
  <tr><th class="text-muted fw-normal" style="width:150px">Installed</th><td class="fw-semibold">{$installed_h}</td></tr>
  <tr><th class="text-muted fw-normal">Status</th><td>{$status_badge}</td></tr>
  <tr><th class="text-muted fw-normal">Last checked</th><td class="text-muted small">{$checked_str}</td></tr>
</table>
{$error_block}{$update_avail_block}
HTML;

// ── Build "Update Now" card (shown only when update is available) ──────────────
$update_now_card = '';
if ($upd_available) {
    // Stage list HTML — rendered in PHP so CSS works without JS running first.
    $stage_rows = '';
    foreach (UpdaterService::STAGE_SEQUENCE as $s) {
        $label        = UpdaterService::STAGE_LABELS[$s] ?? $s;
        $stage_rows  .= '<div id="lum-stage-' . h($s) . '" class="d-flex align-items-start gap-2 mb-2 lum-upd-stage">'
            . '<span class="lum-upd-stage-icon mt-1" style="min-width:1.1rem;text-align:center">⊙</span>'
            . '<div>'
            . '<span class="lum-upd-stage-label small">' . h($label) . '</span>'
            . '<div class="lum-upd-stage-details small text-muted mt-1" style="display:none"></div>'
            . '</div>'
            . '</div>';
    }

    // Stuck-session notice (lock held but no update running from this browser).
    $stuck_notice = '';
    if ($updater_running && $updater_lock !== null) {
        $stuck_ver  = h($updater_lock['version'] ?? 'unknown');
        $stuck_strt = $updater_lock['started_at'] ?? 0;
        $stuck_age  = $stuck_strt > 0 ? h(human_time_diff((int) $stuck_strt)) . ' ago' : 'unknown time';
        $stuck_notice = '<div class="alert alert-warning py-2 mb-3 small">'
            . '⚠ An update session for v' . $stuck_ver . ' was started ' . $stuck_age . ' and may be stuck. '
            . '<button id="lum-upd-abort-stuck" class="btn btn-sm btn-outline-danger ms-2">Abort stuck session</button>'
            . '</div>';
    }

    $btn_disabled   = $updater_running ? ' disabled' : '';
    $php_warn_note  = !$php_ok
        ? '<div class="alert alert-danger py-2 mb-3 small">⚠ PHP version mismatch — see above. Do not update.</div>'
        : '';
    $btn_classes    = !$php_ok ? 'btn btn-danger disabled' : 'btn btn-success';

    $update_now_card = <<<HTML
<!-- ── "Update Now" card ───────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">⬆ Install Update</h5>
  {$stuck_notice}
  {$php_warn_note}

  <!-- Confirmation checkbox -->
  <div id="lum-upd-confirm-area" class="mb-3">
    <p class="small text-muted mb-2">
      The updater will download the release archive, verify its integrity, create an automatic
      database and configuration backup, replace application files, and run any pending database
      migrations — all without SSH access.  <strong>Custom themes and plugins are preserved by
      default.</strong>
    </p>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" id="lum-upd-confirm-chk">
      <label class="form-check-label small" for="lum-upd-confirm-chk">
        I understand that this will replace application files. I have verified my server
        has a backup or am relying on the automatic backup created during the update.
      </label>
    </div>
    <button id="lum-upd-start-btn" class="{$btn_classes}" disabled{$btn_disabled}>
      ⬆ Update to {$latest_h} Now
    </button>
    <span class="text-muted small ms-2">Provider: <span id="lum-upd-provider-label">GitHub Releases</span></span>
  </div>

  <!-- Progress area (hidden until update starts) -->
  <div id="lum-upd-progress" class="d-none">
    <h6 class="mb-3">Update progress</h6>

    <!-- Stage indicators -->
    <div id="lum-upd-stages" class="mb-3 ps-1">
      {$stage_rows}
    </div>

    <!-- Detail log -->
    <div id="lum-upd-log" class="small font-monospace"
         style="max-height:180px;overflow-y:auto;background:var(--bs-gray-100,#f8f9fa);
                border:1px solid var(--bs-border-color,#dee2e6);padding:.5rem .75rem;
                border-radius:.35rem;white-space:pre-wrap;word-break:break-word"></div>

    <!-- Controls -->
    <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
      <button id="lum-upd-rollback-btn" class="btn btn-danger btn-sm d-none">↩ Rollback</button>
      <button id="lum-upd-abort-btn"   class="btn btn-outline-secondary btn-sm d-none">⏹ Abort</button>
      <span   id="lum-upd-status-msg"  class="text-muted small"></span>
    </div>
  </div>
</div>

HTML;
}

// ── Build update history card ─────────────────────────────────────────────────
$history_card = '';
if (!empty($update_history)) {
    $rows = '';
    foreach ($update_history as $entry) {
        $icon   = $entry['success'] ? '✓' : '✗';
        $cls    = $entry['success'] ? 'text-success' : 'text-danger';
        $ver_h  = h($entry['version']);
        $msg_h  = h($entry['message']);
        $date_h = h($entry['updated_at']);
        $rows  .= "<tr><td class=\"{$cls}\">{$icon} v{$ver_h}</td><td class=\"small\">{$date_h}</td><td class=\"small text-muted\">{$msg_h}</td></tr>";
    }
    $history_card = <<<HTML
<!-- ── Update history card ─────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">📋 Update History</h5>
  <table class="table table-sm mb-0">
    <thead><tr><th>Version</th><th>Date (UTC)</th><th>Result</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>

HTML;
}

// ── Page content ──────────────────────────────────────────────────────────────
$content = <<<HTML
<!-- ── Application version status ───────────────────────────────────────────── -->
<div id="lum-update-status" class="lum-adm-card mb-4">
  {$status_block}
</div>

{$update_now_card}

<!-- ── Database schema updates ──────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">🗄 Database Updates</h5>
  {$db_updates_block}
</div>

<!-- ── Check for updates ─────────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">🔄 Check for Updates</h5>
  <p class="text-muted small mb-3">
    Checks the configured release source for a new Lumora release.  Results are cached for 24 hours.
    No gallery content, user data, or identifying information is ever transmitted — only a plain GET
    request is made to the release API.
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-check-btn" class="btn btn-primary">🔄 Check for Updates Now</button>
    <span id="lum-check-spinner" class="text-muted small d-none">Checking…</span>
  </div>
  <div id="lum-check-result" class="mt-3"></div>
</div>

{$history_card}

<!-- ── Info ──────────────────────────────────────────────────────────────────── -->
<div class="lum-adm-card">
  <h5 class="mb-2">ℹ️ About Updates</h5>
  <ul class="small text-muted mb-0">
    <li>Update check results are cached locally for 24 hours; use the button above to force a refresh.</li>
    <li>No gallery content, images, or user data is ever transmitted during an update check.</li>
    <li>Update endpoint: <code>{$endpoint_h}</code></li>
    <li>Custom themes and plugins are preserved by default during an update.
        Set <code>update_preserve_themes</code> or <code>update_preserve_plugins</code>
        to <code>0</code> in the config table to overwrite them.</li>
    <li>An automatic database backup and <code>config.php</code> backup are created before any file replacement.
        Backups are stored in <code>cache/.updates/backup/</code>.</li>
    <li>If the <code>install/</code> directory is present when an update completes, it is automatically
        removed during the cleanup step.</li>
    <li>Cryptographic signature verification is a planned future security enhancement;
        SHA-256 checksum verification is used when the release source provides a checksum.</li>
  </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const CSRF      = {$csrf_js};
  const AJAX_BASE = {$ajax_base_js};
  const AUTO_CHECK = {$auto_check_js};
  const TARGET_VER = {$latest_js};

  // ── Utility ────────────────────────────────────────────────────────────────

  function esc(val) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(val)));
    return d.innerHTML;
  }

  function escAttr(val) {
    return String(val)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  async function post(endpoint, body) {
    const params = new URLSearchParams(Object.assign({ csrf_token: CSRF }, body));
    const resp   = await fetch(AJAX_BASE + endpoint, {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : params.toString(),
    });
    if (!resp.ok) throw new Error('Server returned HTTP ' + resp.status);
    return resp.json();
  }

  // ── Application update check ───────────────────────────────────────────────

  const \$checkBtn  = document.getElementById('lum-check-btn');
  const \$checkSpin = document.getElementById('lum-check-spinner');
  const \$checkRes  = document.getElementById('lum-check-result');
  const \$statusEl  = document.getElementById('lum-update-status');

  if (\$checkBtn) {
    \$checkBtn.addEventListener('click', function () { runCheck(); });
  }
  if (AUTO_CHECK) runCheck();

  async function runCheck() {
    if (\$checkBtn)  { \$checkBtn.disabled = true; \$checkBtn.textContent = 'Checking…'; }
    if (\$checkSpin)   \$checkSpin.classList.remove('d-none');
    if (\$checkRes)    \$checkRes.innerHTML = '';
    try {
      const d = await post('ajax_update_check.php', {});
      if (d.error && !d.latest) throw new Error(d.error);
      if (\$statusEl) \$statusEl.innerHTML = renderStatus(d);
      if (d.error && \$checkRes) {
        \$checkRes.innerHTML = '<div class="alert alert-warning py-2 small">⚠ ' + esc(d.error) + '</div>';
      }
      // If an update is now available and the Update Now card was not present, reload.
      if (d.status === 'update_available' && !document.getElementById('lum-upd-start-btn')) {
        location.reload();
      }
    } catch (err) {
      if (\$checkRes) {
        \$checkRes.innerHTML = '<div class="alert alert-danger py-2 small">Error: ' + esc(err.message) + '</div>';
      }
    } finally {
      if (\$checkBtn)  { \$checkBtn.disabled = false; \$checkBtn.textContent = '🔄 Check for Updates Now'; }
      if (\$checkSpin)   \$checkSpin.classList.add('d-none');
    }
  }

  function renderStatus(d) {
    const inst  = esc(d.installed  || '');
    const lat   = d.latest ? esc(d.latest) : null;
    const rDate = d.release_date ? esc(d.release_date) : null;
    const clUrl = d.changelog_url || '';
    const chkAt = d.checked_at
      ? new Date(d.checked_at * 1000).toISOString().replace('T',' ').slice(0,16) + ' UTC'
      : 'Never';
    const badge = ({
      up_to_date:       '<span class="badge bg-success fs-6">&#x2713; Up to date</span>',
      update_available: '<span class="badge bg-warning text-dark fs-6">&#x1F514; Update available</span>',
      error:            '<span class="badge bg-danger fs-6">&#x26A0; Error</span>',
    })[d.status] || '<span class="badge bg-secondary fs-6">&#x2014; Not checked yet</span>';

    let html = '<table class="table table-sm mb-0" style="max-width:400px">'
      + '<tr><th class="text-muted fw-normal" style="width:150px">Installed</th><td class="fw-semibold">' + inst + '</td></tr>'
      + '<tr><th class="text-muted fw-normal">Status</th><td>' + badge + '</td></tr>'
      + '<tr><th class="text-muted fw-normal">Last checked</th><td class="text-muted small">' + esc(chkAt) + '</td></tr>'
      + '</table>';

    if (d.status === 'update_available' && lat) {
      const rrRow = rDate ? '<tr><th class="text-muted fw-normal">Released</th><td>' + rDate + '</td></tr>' : '';
      const clBtn = clUrl
        ? '<a href="' + escAttr(clUrl) + '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">&#x1F4CB; View Changelog</a>'
        : '';
      html += '<div class="lum-adm-card mt-3"><table class="table table-sm mb-3" style="max-width:400px">'
        + '<tr><th class="text-muted fw-normal" style="width:150px">New version</th><td class="fw-semibold">' + lat + '</td></tr>'
        + rrRow + '</table>' + clBtn + '</div>';
    }
    return html;
  }

  // ── Database migration runner ──────────────────────────────────────────────

  const \$migrateBtn = document.getElementById('lum-migrate-btn');
  if (\$migrateBtn) {
    \$migrateBtn.addEventListener('click', async function () {
      const \$sp  = document.getElementById('lum-migrate-spinner');
      const \$res = document.getElementById('lum-migrate-result');
      \$migrateBtn.disabled = true; \$migrateBtn.textContent = 'Running…';
      if (\$sp)  \$sp.classList.remove('d-none');
      if (\$res) \$res.innerHTML = '';
      try {
        const d = await post('ajax_run_migrations.php', {});
        if (d.success) {
          if (\$res) \$res.innerHTML = '<div class="alert alert-success py-2">✓ ' + esc(d.message) + '</div>';
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          let html = '<div class="alert alert-danger py-2">✗ ' + esc(d.message);
          if (d.errors && d.errors.length) {
            html += '<ul class="mb-0 mt-1">'
              + d.errors.map(function (e) { return '<li class="small font-monospace">' + esc(e) + '</li>'; }).join('')
              + '</ul>';
          }
          html += '</div>';
          if (\$res) \$res.innerHTML = html;
          \$migrateBtn.disabled = false; \$migrateBtn.textContent = '🗄 Run Database Update';
        }
      } catch (err) {
        if (\$res) \$res.innerHTML = '<div class="alert alert-danger py-2">Error: ' + esc(err.message) + '</div>';
        \$migrateBtn.disabled = false; \$migrateBtn.textContent = '🗄 Run Database Update';
      } finally {
        if (\$sp) \$sp.classList.add('d-none');
      }
    });
  }

  // ── "Update Now" workflow ──────────────────────────────────────────────────

  const \$confirmChk   = document.getElementById('lum-upd-confirm-chk');
  const \$startBtn     = document.getElementById('lum-upd-start-btn');
  const \$confirmArea  = document.getElementById('lum-upd-confirm-area');
  const \$progressArea = document.getElementById('lum-upd-progress');
  const \$logEl        = document.getElementById('lum-upd-log');
  const \$statusMsg    = document.getElementById('lum-upd-status-msg');
  const \$rollbackBtn  = document.getElementById('lum-upd-rollback-btn');
  const \$abortBtn     = document.getElementById('lum-upd-abort-btn');
  const \$abortStuck   = document.getElementById('lum-upd-abort-stuck');

  // Enable start button only when checkbox is ticked.
  if (\$confirmChk && \$startBtn) {
    \$confirmChk.addEventListener('change', function () {
      \$startBtn.disabled = !this.checked;
    });
  }

  // Abort stuck session button.
  if (\$abortStuck) {
    \$abortStuck.addEventListener('click', async function () {
      \$abortStuck.disabled = true; \$abortStuck.textContent = 'Aborting…';
      try { await post('ajax_update_perform.php', { action: 'abort' }); } catch (e) {}
      location.reload();
    });
  }

  // Start update.
  if (\$startBtn) {
    \$startBtn.addEventListener('click', async function () {
      if (!TARGET_VER) { alert('No target version known.'); return; }

      // Transition UI to progress mode.
      if (\$confirmArea)  \$confirmArea.classList.add('d-none');
      if (\$progressArea) \$progressArea.classList.remove('d-none');

      await runUpdateStage('preflight', TARGET_VER);
    });
  }

  // Rollback button.
  if (\$rollbackBtn) {
    \$rollbackBtn.addEventListener('click', async function () {
      \$rollbackBtn.disabled = true; \$rollbackBtn.textContent = 'Rolling back…';
      if (\$abortBtn) \$abortBtn.classList.add('d-none');
      setStatusMsg('Restoring backup…', 'warning');
      try {
        const d = await post('ajax_update_perform.php', { action: 'rollback' });
        appendLog(d.details || []);
        if (d.success) {
          setStatusMsg('✓ ' + esc(d.message), 'success');
        } else {
          setStatusMsg('✗ ' + esc(d.message) + ' — check the update log.', 'danger');
        }
        setTimeout(function () { location.reload(); }, 2000);
      } catch (err) {
        setStatusMsg('Rollback request failed: ' + esc(err.message), 'danger');
        \$rollbackBtn.disabled = false; \$rollbackBtn.textContent = '↩ Rollback';
      }
    });
  }

  // Manual abort (force-reset without restore).
  if (\$abortBtn) {
    \$abortBtn.addEventListener('click', async function () {
      if (!confirm('Force-abort the update session without restoring? Only use this if no files have been replaced yet.')) return;
      \$abortBtn.disabled = true;
      try { await post('ajax_update_perform.php', { action: 'abort' }); } catch (e) {}
      location.reload();
    });
  }

  // ── Stage runner ───────────────────────────────────────────────────────────

  // Ordered stage list — matches UpdaterService::STAGE_SEQUENCE.
  const STAGES = [
    'preflight','download','verify','backup','maintenance',
    'extract','validate','replace','migrate','cleanup'
  ];
  // Stages that are "destructive" — once entered, offer rollback on failure.
  const DESTRUCTIVE = new Set(['maintenance','replace','migrate','cleanup']);

  async function runUpdateStage(stage, version) {
    markStageActive(stage);

    const body = { action: 'run_stage', stage: stage };
    if (version) body.version = version;

    let data;
    try {
      data = await post('ajax_update_perform.php', body);
    } catch (err) {
      markStageFailed(stage);
      setStatusMsg('Network error at stage ' + stage + ': ' + esc(err.message), 'danger');
      showAbortBtn();
      return;
    }

    // Append detail lines to the log.
    if (data.details && data.details.length) {
      appendLog(data.details);
    }

    if (!data.success) {
      markStageFailed(stage);
      setStatusMsg('✗ ' + esc(data.message), 'danger');
      // Offer rollback for destructive stages; abort-only for earlier ones.
      if (DESTRUCTIVE.has(stage)) {
        showRollbackBtn();
      } else {
        showAbortBtn();
      }
      return;
    }

    markStageDone(stage);
    setStatusMsg('⟳ ' + esc(data.message), 'muted');

    if (data.next) {
      // Small pause between stages so the UI updates are visible.
      await sleep(300);
      await runUpdateStage(data.next, '');
    } else {
      // Workflow complete.
      setStatusMsg('🎉 ' + esc(data.message), 'success');
      if (\$rollbackBtn) \$rollbackBtn.classList.add('d-none');
      if (\$abortBtn)    \$abortBtn.classList.add('d-none');
      setTimeout(function () { location.reload(); }, 3000);
    }
  }

  // ── Stage DOM helpers ──────────────────────────────────────────────────────

  function stageEl(stage) { return document.getElementById('lum-stage-' + stage); }
  function iconEl(stage)  { const el = stageEl(stage); return el ? el.querySelector('.lum-upd-stage-icon') : null; }
  function detailEl(stage){ const el = stageEl(stage); return el ? el.querySelector('.lum-upd-stage-details') : null; }

  function markStageActive(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '⟳'; ic.style.color = ''; }
  }

  function markStageDone(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '✓'; ic.style.color = 'var(--bs-success,#198754)'; }
  }

  function markStageFailed(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '✗'; ic.style.color = 'var(--bs-danger,#dc3545)'; }
  }

  function appendLog(lines) {
    if (!Array.isArray(lines) || !lines.length || !\$logEl) return;
    lines.forEach(function (line) {
      const div = document.createElement('div');
      div.textContent = line;
      \$logEl.appendChild(div);
    });
    \$logEl.scrollTop = \$logEl.scrollHeight;
  }

  function setStatusMsg(html, type) {
    if (!\$statusMsg) return;
    \$statusMsg.innerHTML = html;
    \$statusMsg.className = 'small align-self-center'
      + (type === 'muted'   ? ' text-muted'    : '')
      + (type === 'success' ? ' text-success'  : '')
      + (type === 'danger'  ? ' text-danger'   : '')
      + (type === 'warning' ? ' text-warning'  : '');
  }

  function showRollbackBtn() {
    if (\$rollbackBtn) \$rollbackBtn.classList.remove('d-none');
  }

  function showAbortBtn() {
    if (\$abortBtn) \$abortBtn.classList.remove('d-none');
  }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

});
</script>
HTML;

// Persistent security notice when install/ still exists on disk after update.
$install_dir_notice = is_dir(LUMORA_ROOT . 'install')
    ? '<div class="alert alert-warning mb-4">&#x26A0;&#xFE0F; <strong>Security notice:</strong>'
      . ' The <code>install/</code> directory still exists on this server.'
      . ' Automatic removal after the last update did not complete — check file permissions.'
      . ' <strong>Delete it manually via FTP or your hosting file manager</strong> to prevent'
      . ' unauthorised access to the installation wizard.</div>'
    : '';

lum_admin_page('Updates', $install_dir_notice . $content, 'updates');

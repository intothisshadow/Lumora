<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Maintenance
 *
 * Three AJAX-driven tools, all sharing an optional album scope selector:
 *
 *   1. File Integrity Check  — verify every DB image record has files on disk;
 *                              select and delete orphaned DB entries.
 *   2. Reload Dimensions     — re-read width/height/filesize from disk and
 *                              update the images table.
 *   3. Regenerate Thumbnails — rebuild all thumbnails from originals using
 *                              current thumbnail settings.
 *
 * All tools run in AJAX chunks — safe for galleries with 500 000+ images.
 * No original image files are ever modified by any of these tools.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Album scope selector ──────────────────────────────────────────────────────
$album_id = lumora_int($_GET['album_id'] ?? 0, 0, 0);

$all_albums = LumoraDB::fetchAll(
    'SELECT a.id, a.title, a.folder, c.name AS cat_name
     FROM `{PREFIX}albums` a
     LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
     ORDER BY c.name ASC, a.title ASC'
);

$sel_opts          = '<option value="0"' . ($album_id === 0 ? ' selected' : '') . '>All Albums</option>';
$cur_cat           = null;
$scope_album_title = '';

foreach ($all_albums as $a) {
    if ($a['cat_name'] !== $cur_cat) {
        if ($cur_cat !== null) $sel_opts .= '</optgroup>';
        $sel_opts .= '<optgroup label="' . h($a['cat_name'] ?? 'Uncategorised') . '">';
        $cur_cat = $a['cat_name'];
    }
    $is_sel    = ((int) $a['id'] === $album_id);
    $sel_opts .= '<option value="' . (int) $a['id'] . '"' . ($is_sel ? ' selected' : '') . '>'
        . h($a['title']) . ' — ' . h($a['folder'])
        . '</option>';
    if ($is_sel) {
        $scope_album_title = $a['title'];
    }
}
if ($cur_cat !== null) $sel_opts .= '</optgroup>';

// ── Image count for the current scope ────────────────────────────────────────
if ($album_id > 0) {
    $total_images = (int) LumoraDB::fetchValue(
        'SELECT COUNT(*) FROM `{PREFIX}images` WHERE album_id = ?',
        [$album_id]
    );
    $scope_label = h('Album: ' . $scope_album_title)
        . ' <span class="text-muted">(' . number_format($total_images) . ' images)</span>';
} else {
    $total_images = (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}images`');
    $scope_label  = 'All Albums <span class="text-muted">(' . number_format($total_images) . ' images)</span>';
}
$total_fmt = number_format($total_images);

// ── Current thumbnail settings (shown in tool description) ───────────────────
$thumb_w = (int) lumora_config('thumb_width',  250);
$thumb_h = (int) lumora_config('thumb_height', 250);
$thumb_q = (int) lumora_config('thumb_quality', 85);

// ── Image processor availability (required for thumbnail regeneration) ────────
$has_processor   = extension_loaded('imagick') || extension_loaded('gd');
$thumb_btn_attr  = $has_processor ? '' : ' disabled';
$thumb_warn_html = $has_processor ? '' :
    '<div class="alert alert-warning py-2 mb-3 small">⚠ No image processor available — '
    . 'neither the Imagick PHP extension nor GD is loaded. Install <code>php-imagick</code> '
    . '(preferred) or <code>php-gd</code> to enable thumbnail regeneration.</div>';

// ── Chunk sizes (used in both PHP descriptions and JS constants) ──────────────
$int_chunk = 500;
$dim_chunk = 100;
$thn_chunk = 20;

// ── JS-safe values ────────────────────────────────────────────────────────────
// json_encode produces a properly quoted, escaped JS string literal for CSRF.
$csrf_js      = json_encode(lumora_csrf_token());
// Use the configured base_url (from DB) rather than reconstructing from $_SERVER
// to avoid HTTP_HOST injection and keep the URL consistent with the rest of the app.
$ajax_base_js = json_encode(lumora_base_url() . 'admin/');
$maint_url_h  = h(lumora_base_url() . 'admin/maintenance.php');

$content = <<<HTML
<!-- ── Album Scope Selector ─────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">🎯 Scope</h5>
  <p class="text-muted small mb-2">
    Restrict all tools below to a specific album, or run them across every image in the gallery.
  </p>
  <form method="get" action="{$maint_url_h}" class="d-flex gap-2 align-items-center flex-wrap">
    <select name="album_id" class="form-select" style="max-width:400px">
      {$sel_opts}
    </select>
    <button type="submit" class="btn btn-outline-secondary btn-sm">Apply →</button>
  </form>
  <p class="small text-muted mt-2 mb-0">Current scope: {$scope_label}</p>
</div>

<!-- ── 1. File Integrity Check ─────────────────────────────────────────────── -->
<div class="lum-adm-card mb-3">
  <h5 class="mb-2">🔍 File Integrity Check</h5>
  <p class="text-muted small mb-3">
    Scans all <strong>{$total_fmt}</strong> image records in the current scope and verifies
    that each original file and its thumbnail exist on disk.
    Runs in chunks of {$int_chunk} — safe for galleries with hundreds of thousands of images.
    <strong>Only database records are removed; no files on disk are ever touched.</strong>
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-scan-start" class="btn btn-primary">🔍 Start Integrity Scan</button>
    <button id="lum-scan-cancel" class="btn btn-outline-secondary d-none">⏹ Cancel</button>
  </div>
</div>

<!-- Integrity: progress -->
<div id="lum-scan-progress" class="lum-adm-card mb-3 d-none">
  <div class="d-flex justify-content-between mb-1">
    <span id="lum-scan-status" class="small text-muted">Starting…</span>
    <span id="lum-scan-count"  class="small text-muted">0 / {$total_fmt}</span>
  </div>
  <div class="progress mb-2" style="height:20px">
    <div id="lum-scan-bar"
         class="progress-bar progress-bar-striped progress-bar-animated"
         role="progressbar" style="width:0%;font-size:.75rem">0%</div>
  </div>
  <div id="lum-scan-summary" class="small"></div>
</div>

<!-- Integrity: missing files table (hidden until scan finds something) -->
<div id="lum-scan-results" class="lum-adm-card mb-3 d-none">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
      Missing Files
      <span id="lum-missing-badge" class="badge bg-danger ms-1">0</span>
    </h5>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" id="lum-select-all">
        <label class="form-check-label small" for="lum-select-all">Select all</label>
      </div>
      <button id="lum-delete-selected" class="btn btn-sm btn-danger" disabled>
        🗑 Delete Selected Records
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm lum-adm-table align-middle mb-0">
      <thead>
        <tr>
          <th style="width:2.5rem"></th>
          <th style="width:5rem">ID</th>
          <th>Album</th>
          <th>Filename</th>
          <th style="width:8rem">Original</th>
          <th style="width:8rem">Thumbnail</th>
        </tr>
      </thead>
      <tbody id="lum-missing-tbody"></tbody>
    </table>
  </div>
</div>
<div id="lum-delete-feedback" class="mb-4"></div>

<!-- ── 2. Reload Dimensions & File Size ────────────────────────────────────── -->
<div class="lum-adm-card mb-3">
  <h5 class="mb-2">📐 Reload Dimensions &amp; File Size</h5>
  <p class="text-muted small mb-3">
    Re-reads the pixel dimensions and file size of all <strong>{$total_fmt}</strong>
    images in the current scope directly from disk and updates the database records.
    Use this after manually replacing image files, running an external tool, or
    migrating from another gallery system where the stored metadata may be stale.
    Runs in chunks of {$dim_chunk}.  <strong>Original files are never modified.</strong>
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-dim-start" class="btn btn-primary">📐 Start Reload</button>
    <button id="lum-dim-cancel" class="btn btn-outline-secondary d-none">⏹ Cancel</button>
  </div>
</div>

<!-- Dimensions: progress -->
<div id="lum-dim-progress" class="lum-adm-card mb-4 d-none">
  <div class="d-flex justify-content-between mb-1">
    <span id="lum-dim-status" class="small text-muted">Starting…</span>
    <span id="lum-dim-count"  class="small text-muted">0 / {$total_fmt}</span>
  </div>
  <div class="progress mb-2" style="height:20px">
    <div id="lum-dim-bar"
         class="progress-bar progress-bar-striped progress-bar-animated bg-info"
         role="progressbar" style="width:0%;font-size:.75rem">0%</div>
  </div>
  <div id="lum-dim-summary" class="small"></div>
</div>

<!-- ── 3. Regenerate Thumbnails ─────────────────────────────────────────────── -->
<div class="lum-adm-card mb-3">
  <h5 class="mb-2">🖼 Regenerate Thumbnails</h5>
  {$thumb_warn_html}
  <p class="text-muted small mb-3">
    Regenerates the thumbnail for every image in the current scope
    (<strong>{$total_fmt}</strong> images) using the current thumbnail settings
    (size: <strong>{$thumb_w}×{$thumb_h}&nbsp;px</strong>,
    quality: <strong>{$thumb_q}</strong>).
    Overwrites existing thumbnails and creates any that are missing.
    Runs in chunks of {$thn_chunk} — thumbnail generation is CPU-intensive.
    <strong>Original files are never modified.</strong>
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-thn-start" class="btn btn-primary"{$thumb_btn_attr}>🖼 Start Regeneration</button>
    <button id="lum-thn-cancel" class="btn btn-outline-secondary d-none">⏹ Cancel</button>
  </div>
</div>

<!-- Thumbnails: progress -->
<div id="lum-thn-progress" class="lum-adm-card mb-3 d-none">
  <div class="d-flex justify-content-between mb-1">
    <span id="lum-thn-status" class="small text-muted">Starting…</span>
    <span id="lum-thn-count"  class="small text-muted">0 / {$total_fmt}</span>
  </div>
  <div class="progress mb-2" style="height:20px">
    <div id="lum-thn-bar"
         class="progress-bar progress-bar-striped progress-bar-animated bg-success"
         role="progressbar" style="width:0%;font-size:.75rem">0%</div>
  </div>
  <div id="lum-thn-summary" class="small"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  // ── Shared constants ───────────────────────────────────────────────────────
  const TOTAL     = {$total_images};
  const ALBUM_ID  = {$album_id};
  const CSRF      = {$csrf_js};
  const AJAX_BASE = {$ajax_base_js};

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

  // ── Tool 1: File Integrity Check ───────────────────────────────────────────
  (function () {
    const CHUNK_SIZE = {$int_chunk};

    let lastId       = 0;
    let totalChecked = 0;
    let missingCount = 0;
    let cancelled    = false;
    let scanning     = false;

    const \$start    = document.getElementById('lum-scan-start');
    const \$cancel   = document.getElementById('lum-scan-cancel');
    const \$progress = document.getElementById('lum-scan-progress');
    const \$bar      = document.getElementById('lum-scan-bar');
    const \$status   = document.getElementById('lum-scan-status');
    const \$count    = document.getElementById('lum-scan-count');
    const \$summary  = document.getElementById('lum-scan-summary');
    const \$results  = document.getElementById('lum-scan-results');
    const \$tbody    = document.getElementById('lum-missing-tbody');
    const \$badge    = document.getElementById('lum-missing-badge');
    const \$selAll   = document.getElementById('lum-select-all');
    const \$delBtn   = document.getElementById('lum-delete-selected');
    const \$feedback = document.getElementById('lum-delete-feedback');

    if (!\$start || !\$cancel) return;

    \$start.addEventListener('click', startScan);

    \$cancel.addEventListener('click', function () {
      cancelled         = true;
      \$cancel.disabled  = true;
      if (\$status) \$status.textContent = 'Cancelling…';
    });

    async function startScan() {
      if (scanning) return;
      scanning     = true;
      cancelled    = false;
      lastId       = 0;
      totalChecked = 0;
      missingCount = 0;
      let fetchFailed = false;

      \$start.classList.add('d-none');
      \$cancel.classList.remove('d-none');
      \$cancel.disabled       = false;
      \$progress.classList.remove('d-none');
      \$tbody.innerHTML       = '';
      \$results.classList.add('d-none');
      \$badge.textContent     = '0';
      \$feedback.innerHTML    = '';
      \$summary.textContent   = '';
      \$start.textContent     = '🔍 Start Integrity Scan';
      resetBar(\$bar);

      while (!cancelled) {
        const data = await fetchChunk();
        if (!data) { fetchFailed = true; break; }

        totalChecked += data.checked;
        lastId        = data.last_id;
        updateBar(\$bar, \$status, \$count, totalChecked);

        if (data.missing && data.missing.length > 0) {
          appendMissing(data.missing);
        }

        if (data.done) break;
      }

      if (!fetchFailed) finishScan();
      scanning = false;
    }

    async function fetchChunk() {
      try {
        const resp = await fetch(AJAX_BASE + 'ajax_integrity.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body   : 'last_id='    + lastId
                 + '&limit='     + CHUNK_SIZE
                 + '&album_id='  + ALBUM_ID
                 + '&csrf_token=' + encodeURIComponent(CSRF),
        });
        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        return data;
      } catch (err) {
        if (\$status) \$status.textContent = 'Error: ' + err.message;
        if (\$bar)    \$bar.classList.remove('progress-bar-animated');
        \$start.classList.remove('d-none');
        \$cancel.classList.add('d-none');
        scanning = false;
        return null;
      }
    }

    function finishScan() {
      \$bar.classList.remove('progress-bar-animated');
      \$bar.style.width  = '100%';
      \$bar.textContent  = '100%';
      \$start.classList.remove('d-none');
      \$start.textContent = '↺ Scan Again';
      \$cancel.classList.add('d-none');

      if (cancelled) {
        if (\$status) \$status.textContent =
          'Scan cancelled after checking ' + totalChecked.toLocaleString() + ' images.';
        return;
      }

      if (\$status) \$status.textContent = 'Scan complete.';

      if (missingCount === 0) {
        \$summary.innerHTML =
          '<span class="text-success fw-semibold">'
          + '✓ All ' + totalChecked.toLocaleString() + ' image records have files on disk.'
          + '</span>';
      } else {
        \$summary.innerHTML =
          '<span class="text-danger fw-semibold">'
          + '⚠ ' + missingCount.toLocaleString()
          + ' missing file' + (missingCount !== 1 ? 's' : '')
          + ' found — select records below to remove them from the database.'
          + '</span>';
      }
    }

    function appendMissing(items) {
      \$results.classList.remove('d-none');
      items.forEach(function (item) {
        missingCount++;
        \$badge.textContent = missingCount;

        const origCell  = item.orig_missing
          ? '<span class="text-danger small">✗ Missing</span>'
          : '<span class="text-success small">✓ OK</span>';
        const thumbCell = item.thumb_missing
          ? '<span class="text-danger small">✗ Missing</span>'
          : '<span class="text-success small">✓ OK</span>';

        const tr = document.createElement('tr');
        tr.dataset.id = item.id;
        tr.innerHTML =
            '<td><input type="checkbox" class="form-check-input lum-miss-chk"'
          +      ' value="' + escAttr(String(item.id)) + '"></td>'
          + '<td class="text-muted small">' + esc(item.id) + '</td>'
          + '<td class="small">' + esc(item.album_title) + '</td>'
          + '<td class="small font-monospace text-break">' + esc(item.filename) + '</td>'
          + '<td>' + origCell + '</td>'
          + '<td>' + thumbCell + '</td>';

        \$tbody.appendChild(tr);
      });
      updateDelBtn();
    }

    if (\$selAll) {
      \$selAll.addEventListener('change', function () {
        document.querySelectorAll('.lum-miss-chk').forEach(function (c) {
          c.checked = \$selAll.checked;
        });
        updateDelBtn();
      });
    }

    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('lum-miss-chk')) {
        updateDelBtn();
      }
    });

    function updateDelBtn() {
      const n        = document.querySelectorAll('.lum-miss-chk:checked').length;
      \$delBtn.disabled    = (n === 0);
      \$delBtn.textContent = n > 0
        ? '🗑 Delete ' + n + ' DB Record' + (n !== 1 ? 's' : '')
        : '🗑 Delete Selected Records';
    }

    if (\$delBtn) {
      \$delBtn.addEventListener('click', async function () {
        const checked = Array.from(document.querySelectorAll('.lum-miss-chk:checked'));
        if (!checked.length) return;

        const ids = checked.map(function (c) { return c.value; });
        const n   = ids.length;

        if (!confirm(
          'Permanently remove ' + n + ' record' + (n !== 1 ? 's' : '') + ' from the database?\\n\\n'
          + 'This only removes the database entries — no files on disk are touched.'
        )) return;

        \$delBtn.disabled    = true;
        \$delBtn.textContent = 'Deleting…';

        try {
          const body = ids.map(function (id) {
            return 'ids[]=' + encodeURIComponent(id);
          }).join('&') + '&csrf_token=' + encodeURIComponent(CSRF);

          const resp = await fetch(AJAX_BASE + 'ajax_integrity_delete.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : body,
          });
          if (!resp.ok) throw new Error('Server returned ' + resp.status);
          const data = await resp.json();
          if (data.error) throw new Error(data.error);

          ids.forEach(function (id) {
            const row = \$tbody.querySelector('tr[data-id="' + id + '"]');
            if (row) row.remove();
          });

          missingCount       = \$tbody.querySelectorAll('tr').length;
          \$badge.textContent = missingCount;
          if (\$selAll) \$selAll.checked = false;

          \$feedback.innerHTML =
              '<div class="alert alert-success alert-dismissible fade show py-2">'
            + '✓ Deleted ' + data.deleted + ' DB record' + (data.deleted !== 1 ? 's' : '') + '.'
            + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            + '</div>';

          if (data.errors && data.errors.length) {
            const li = data.errors.map(function (e) {
              return '<li class="small">' + esc(e) + '</li>';
            }).join('');
            \$feedback.innerHTML +=
                '<div class="alert alert-warning py-2">'
              + '<ul class="mb-0">' + li + '</ul>'
              + '</div>';
          }

          if (missingCount === 0) {
            \$results.classList.add('d-none');
            if (\$summary) {
              \$summary.innerHTML =
                '<span class="text-success fw-semibold">✓ All missing records have been removed.</span>';
            }
          }

          updateDelBtn();
        } catch (err) {
          \$feedback.innerHTML =
              '<div class="alert alert-danger py-2">Error: ' + esc(err.message) + '</div>';
          updateDelBtn();
        }
      });
    }

  }()); // end Tool 1


  // ── Tool 2: Reload Dimensions & File Size ──────────────────────────────────
  (function () {
    const CHUNK_SIZE = {$dim_chunk};

    let lastId       = 0;
    let totalChecked = 0;
    let cancelled    = false;
    let running      = false;

    const \$start    = document.getElementById('lum-dim-start');
    const \$cancel   = document.getElementById('lum-dim-cancel');
    const \$progress = document.getElementById('lum-dim-progress');
    const \$bar      = document.getElementById('lum-dim-bar');
    const \$status   = document.getElementById('lum-dim-status');
    const \$count    = document.getElementById('lum-dim-count');
    const \$summary  = document.getElementById('lum-dim-summary');

    if (!\$start || !\$cancel) return;

    \$start.addEventListener('click', startTool);

    \$cancel.addEventListener('click', function () {
      cancelled        = true;
      \$cancel.disabled = true;
      \$status.textContent = 'Cancelling…';
    });

    async function startTool() {
      if (running) return;
      running      = true;
      cancelled    = false;
      lastId       = 0;
      totalChecked = 0;

      \$start.classList.add('d-none');
      \$cancel.classList.remove('d-none');
      \$cancel.disabled    = false;
      \$progress.classList.remove('d-none');
      \$summary.textContent = '';
      resetBar(\$bar);

      let totalUpdated = 0;
      let totalSkipped = 0;
      let allErrors    = [];
      let fetchFailed  = false;

      while (!cancelled) {
        const data = await fetchChunk();
        if (!data) { fetchFailed = true; break; }

        totalChecked += data.checked;
        totalUpdated += data.updated;
        totalSkipped += data.skipped;
        lastId        = data.last_id;
        allErrors     = allErrors.concat(data.errors || []);
        updateBar(\$bar, \$status, \$count, totalChecked);

        if (data.done) break;
      }

      if (!fetchFailed) finishTool(totalUpdated, totalSkipped, allErrors);
      running = false;
    }

    async function fetchChunk() {
      try {
        const resp = await fetch(AJAX_BASE + 'ajax_dimensions.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body   : 'last_id='    + lastId
                 + '&limit='     + CHUNK_SIZE
                 + '&album_id='  + ALBUM_ID
                 + '&csrf_token=' + encodeURIComponent(CSRF),
        });
        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        return data;
      } catch (err) {
        \$status.textContent = 'Error: ' + err.message;
        \$bar.classList.remove('progress-bar-animated');
        \$start.classList.remove('d-none');
        \$cancel.classList.add('d-none');
        running = false;
        return null;
      }
    }

    function finishTool(updated, skipped, errors) {
      \$bar.classList.remove('progress-bar-animated');
      \$bar.style.width = '100%';
      \$bar.textContent = '100%';
      \$start.classList.remove('d-none');
      \$start.textContent = '↺ Run Again';
      \$cancel.classList.add('d-none');

      if (cancelled) {
        \$status.textContent = 'Cancelled after ' + totalChecked.toLocaleString() + ' images.';
        return;
      }

      \$status.textContent = 'Complete.';

      let html = '<span class="text-success fw-semibold">✓ Done. '
        + updated.toLocaleString() + ' record' + (updated !== 1 ? 's' : '') + ' updated'
        + (skipped > 0 ? ', ' + skipped.toLocaleString() + ' skipped (file not found)' : '')
        + '.</span>';

      if (errors.length > 0) {
        const shown = errors.slice(0, 50);
        html += '<details class="mt-2"><summary class="text-warning small">'
          + errors.length + ' warning' + (errors.length !== 1 ? 's' : '') + '</summary>'
          + '<ul class="mb-0 mt-1 small text-warning">'
          + shown.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('')
          + (errors.length > 50 ? '<li>…and ' + (errors.length - 50) + ' more</li>' : '')
          + '</ul></details>';
      }

      \$summary.innerHTML = html;
    }

  }()); // end Tool 2


  // ── Tool 3: Regenerate Thumbnails ──────────────────────────────────────────
  (function () {
    const CHUNK_SIZE = {$thn_chunk};

    let lastId       = 0;
    let totalChecked = 0;
    let cancelled    = false;
    let running      = false;

    const \$start    = document.getElementById('lum-thn-start');
    const \$cancel   = document.getElementById('lum-thn-cancel');
    const \$progress = document.getElementById('lum-thn-progress');
    const \$bar      = document.getElementById('lum-thn-bar');
    const \$status   = document.getElementById('lum-thn-status');
    const \$count    = document.getElementById('lum-thn-count');
    const \$summary  = document.getElementById('lum-thn-summary');

    if (!\$start || !\$cancel || \$start.disabled) return;

    \$start.addEventListener('click', startTool);

    \$cancel.addEventListener('click', function () {
      cancelled        = true;
      \$cancel.disabled = true;
      \$status.textContent = 'Cancelling…';
    });

    async function startTool() {
      if (running) return;
      running      = true;
      cancelled    = false;
      lastId       = 0;
      totalChecked = 0;

      \$start.classList.add('d-none');
      \$cancel.classList.remove('d-none');
      \$cancel.disabled    = false;
      \$progress.classList.remove('d-none');
      \$summary.textContent = '';
      resetBar(\$bar);

      let totalUpdated = 0;
      let totalSkipped = 0;
      let allErrors    = [];
      let fetchFailed  = false;

      while (!cancelled) {
        const data = await fetchChunk();
        if (!data) { fetchFailed = true; break; }

        totalChecked += data.checked;
        totalUpdated += data.updated;
        totalSkipped += data.skipped;
        lastId        = data.last_id;
        allErrors     = allErrors.concat(data.errors || []);
        updateBar(\$bar, \$status, \$count, totalChecked);

        if (data.done) break;
      }

      if (!fetchFailed) finishTool(totalUpdated, totalSkipped, allErrors);
      running = false;
    }

    async function fetchChunk() {
      try {
        const resp = await fetch(AJAX_BASE + 'ajax_thumbs.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body   : 'last_id='    + lastId
                 + '&limit='     + CHUNK_SIZE
                 + '&album_id='  + ALBUM_ID
                 + '&csrf_token=' + encodeURIComponent(CSRF),
        });
        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        return data;
      } catch (err) {
        \$status.textContent = 'Error: ' + err.message;
        \$bar.classList.remove('progress-bar-animated');
        \$start.classList.remove('d-none');
        \$cancel.classList.add('d-none');
        running = false;
        return null;
      }
    }

    function finishTool(updated, skipped, errors) {
      \$bar.classList.remove('progress-bar-animated');
      \$bar.style.width = '100%';
      \$bar.textContent = '100%';
      \$start.classList.remove('d-none');
      \$start.textContent = '↺ Run Again';
      \$cancel.classList.add('d-none');

      if (cancelled) {
        \$status.textContent = 'Cancelled after ' + totalChecked.toLocaleString() + ' images.';
        return;
      }

      \$status.textContent = 'Complete.';

      let html = '<span class="text-success fw-semibold">✓ Done. '
        + updated.toLocaleString() + ' thumbnail' + (updated !== 1 ? 's' : '') + ' regenerated'
        + (skipped > 0 ? ', ' + skipped.toLocaleString() + ' skipped (original not found)' : '')
        + '.</span>';

      if (errors.length > 0) {
        const shown = errors.slice(0, 50);
        html += '<details class="mt-2"><summary class="text-danger small">'
          + errors.length + ' failure' + (errors.length !== 1 ? 's' : '') + '</summary>'
          + '<ul class="mb-0 mt-1 small text-danger">'
          + shown.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('')
          + (errors.length > 50 ? '<li>…and ' + (errors.length - 50) + ' more</li>' : '')
          + '</ul></details>';
      }

      \$summary.innerHTML = html;
    }

  }()); // end Tool 3


  // ── Shared progress bar helpers ────────────────────────────────────────────
  function updateBar(bar, statusEl, countEl, checked) {
    const pct = TOTAL > 0 ? Math.min(100, Math.round(checked / TOTAL * 100)) : 100;
    bar.style.width        = pct + '%';
    bar.textContent        = pct + '%';
    countEl.textContent    = checked.toLocaleString() + ' / ' + TOTAL.toLocaleString();
    statusEl.textContent   = 'Processing…';
  }

  function resetBar(bar) {
    bar.style.width = '0%';
    bar.textContent = '0%';
    bar.classList.add('progress-bar-striped', 'progress-bar-animated');
  }

}); // end DOMContentLoaded
</script>
HTML;

lum_admin_page('Maintenance', $content, 'maintenance');

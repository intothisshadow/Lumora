<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Maintenance
 *
 * Maintenance → File Integrity Check
 *
 * Scans every image record in the database and verifies that both the original
 * file and its thumbnail exist on disk.  Runs in AJAX chunks so it is safe for
 * galleries with 500 000+ images without hitting PHP's time limit.
 * Orphaned DB records can be selectively deleted from the results table.
 * No files on disk are ever touched.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$total_images = (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}images`');
$total_fmt    = number_format($total_images);
$chunk_size   = 500;

// json_encode produces a properly quoted, escaped JS string literal.
$csrf_js = json_encode(lumora_csrf_token());

$content = <<<HTML
<div class="lum-adm-card mb-3">
  <h5 class="mb-2">🔍 File Integrity Check</h5>
  <p class="text-muted small mb-3">
    Scans all <strong>{$total_fmt}</strong> image records in the database and verifies
    that each original file and its thumbnail exist on disk.
    Runs in chunks of {$chunk_size} — safe for galleries with hundreds of thousands of images.
    <strong>Only database records are removed; no files on disk are ever touched.</strong>
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-scan-start" class="btn btn-primary">🔍 Start Integrity Scan</button>
    <button id="lum-scan-cancel" class="btn btn-outline-secondary d-none">⏹ Cancel</button>
  </div>
</div>

<!-- Progress -->
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

<!-- Missing files table (hidden until scan finds something) -->
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

<!-- Delete feedback -->
<div id="lum-delete-feedback" class="mb-3"></div>

<script>
(function () {
  'use strict';

  const TOTAL_DB   = {$total_images};
  const CHUNK_SIZE = {$chunk_size};
  const CSRF       = {$csrf_js};

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

  if (!\$start) return;

  // ── Start / Cancel ────────────────────────────────────────────────────────
  \$start.addEventListener('click', startScan);

  \$cancel.addEventListener('click', function () {
    cancelled         = true;
    \$cancel.disabled  = true;
    if (\$status) \$status.textContent = 'Cancelling…';
  });

  // ── Scan loop ─────────────────────────────────────────────────────────────
  async function startScan() {
    if (scanning) return;
    scanning     = true;
    cancelled    = false;
    lastId       = 0;
    totalChecked = 0;
    missingCount = 0;

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
    resetBar();

    while (!cancelled) {
      const data = await fetchChunk();
      if (!data) break; // error — fetchChunk already updated UI

      totalChecked += data.checked;
      lastId        = data.last_id;
      updateBar();

      if (data.missing && data.missing.length > 0) {
        appendMissing(data.missing);
      }

      if (data.done) break;
    }

    finishScan();
    scanning = false;
  }

  async function fetchChunk() {
    try {
      const resp = await fetch('ajax_integrity.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : 'last_id=' + lastId
               + '&limit='  + CHUNK_SIZE
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

  function updateBar() {
    const pct = TOTAL_DB > 0 ? Math.min(100, Math.round(totalChecked / TOTAL_DB * 100)) : 100;
    \$bar.style.width   = pct + '%';
    \$bar.textContent   = pct + '%';
    \$count.textContent = totalChecked.toLocaleString() + ' / ' + TOTAL_DB.toLocaleString();
    if (!cancelled && \$status) \$status.textContent = 'Scanning…';
  }

  function resetBar() {
    \$bar.style.width = '0%';
    \$bar.textContent = '0%';
    \$bar.classList.add('progress-bar-striped', 'progress-bar-animated');
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

  // ── Append missing rows ───────────────────────────────────────────────────
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

  // ── Select all ────────────────────────────────────────────────────────────
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
    const n       = document.querySelectorAll('.lum-miss-chk:checked').length;
    \$delBtn.disabled    = (n === 0);
    \$delBtn.textContent = n > 0
      ? '🗑 Delete ' + n + ' DB Record' + (n !== 1 ? 's' : '')
      : '🗑 Delete Selected Records';
  }

  // ── Delete selected ───────────────────────────────────────────────────────
  if (\$delBtn) {
    \$delBtn.addEventListener('click', async function () {
      const checked = Array.from(document.querySelectorAll('.lum-miss-chk:checked'));
      if (!checked.length) return;

      const ids = checked.map(function (c) { return c.value; });
      const n   = ids.length;

      if (!confirm(
        'Permanently remove ' + n + ' record' + (n !== 1 ? 's' : '') + ' from the database?\n\n'
        + 'This only removes the database entries — no files on disk are touched.'
      )) return;

      \$delBtn.disabled    = true;
      \$delBtn.textContent = 'Deleting…';

      try {
        const body = ids.map(function (id) {
          return 'ids[]=' + encodeURIComponent(id);
        }).join('&') + '&csrf_token=' + encodeURIComponent(CSRF);

        const resp = await fetch('ajax_integrity_delete.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body   : body,
        });
        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        // Remove deleted rows from the table.
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

  // ── Helpers ───────────────────────────────────────────────────────────────
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

}());
</script>
HTML;

lum_admin_page('Maintenance', $content, 'maintenance');

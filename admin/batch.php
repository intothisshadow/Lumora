<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Batch Add
 *
 * Scans an album's folder for images not yet in the database,
 * generates thumbnails, and registers them.
 *
 * For large albums (thousands of images) the page uses chunked AJAX calls to
 * process in groups and report progress.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Build album list ──────────────────────────────────────────────────────────
$all_albums = LumoraDB::fetchAll(
    'SELECT a.id, a.title, a.folder, c.name AS cat_name,
            (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id) AS img_count
     FROM `{PREFIX}albums` a
     LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
     ORDER BY c.name ASC, a.title ASC'
);

$album_id = lumora_int($_GET['album'] ?? 0, 0, 1);
$selected = null;
if ($album_id > 0) {
    foreach ($all_albums as $a) {
        if ((int) $a['id'] === $album_id) { $selected = $a; break; }
    }
}

$base = h(lumora_base_url() . 'admin/');
$csrf = h(lumora_csrf_token());

// ── Album selector ────────────────────────────────────────────────────────────
$sel_opts = '<option value="">— Select an album —</option>';
$cur_cat  = null;
foreach ($all_albums as $a) {
    if ($a['cat_name'] !== $cur_cat) {
        if ($cur_cat !== null) $sel_opts .= '</optgroup>';
        $sel_opts .= '<optgroup label="' . h($a['cat_name'] ?? 'Uncategorised') . '">';
        $cur_cat = $a['cat_name'];
    }
    $sel = ($album_id === (int)$a['id']) ? ' selected' : '';
    $sel_opts .= '<option value="' . (int)$a['id'] . '"' . $sel . '>'
        . h($a['title']) . ' (' . number_format((int)$a['img_count']) . ' in DB, folder: ' . h($a['folder']) . ')'
        . '</option>';
}
if ($cur_cat !== null) $sel_opts .= '</optgroup>';

// ── Scan info ─────────────────────────────────────────────────────────────────
// Initialise $new_count before the conditional so the JS heredoc below never
// interpolates an undefined variable (which would produce a PHP E_WARNING and
// emit `const total = ;` — a JavaScript SyntaxError that silently prevents the
// entire IIFE from running, making the Process button unresponsive).
$new_count = 0;
$scan_html = '';
if ($selected) {
    $new_files = lumora_scan_new_images($selected['folder'], (int)$selected['id']);
    $new_count = count($new_files);

    if ($new_count === 0) {
        $scan_html = '<div class="alert alert-success py-2">No new images found in <code>' . h($selected['folder']) . '</code>. The album is up to date.</div>';
    } else {
        $scan_html = '<div class="alert alert-info py-2">'
            . '<strong>' . number_format($new_count) . ' new image' . ($new_count !== 1 ? 's' : '') . '</strong>'
            . ' found in <code>' . h($selected['folder']) . '/</code> ready to add.'
            . '</div>'
            . '<div id="lum-batch-progress" class="mb-3">'
            .   '<div class="d-flex justify-content-between mb-1">'
            .     '<span id="lum-batch-status" class="small text-muted">Ready</span>'
            .     '<span id="lum-batch-count" class="small text-muted">0 / ' . $new_count . '</span>'
            .   '</div>'
            .   '<div class="progress">'
            .     '<div id="lum-batch-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div>'
            .   '</div>'
            . '</div>'
            . '<div id="lum-batch-errors" class="mb-2"></div>'
            . '<button id="lum-batch-start" class="btn btn-primary">'
            .   '▶ Process ' . number_format($new_count) . ' Image' . ($new_count !== 1 ? 's' : '')
            . '</button>'
            . '<button id="lum-batch-done" class="btn btn-success d-none ms-2" onclick="location.reload()">↺ Scan Again</button>';
    }
}

$content = <<<HTML
<div class="lum-adm-card mb-3">
  <h5 class="mb-3">Select Album</h5>
  <form method="get" action="" class="d-flex gap-2 flex-wrap">
    <select name="album" class="form-select" style="max-width:420px">
      {$sel_opts}
    </select>
    <button type="submit" class="btn btn-outline-secondary">Scan →</button>
  </form>
  <p class="text-muted small mt-2 mb-0">
    Upload images via FTP to <code>albums/{folder}/</code>, then scan and process them here.
    Thumbnails are generated automatically.
  </p>
</div>

{$scan_html}

<script>
(function () {
  'use strict';

  var albumId = {$album_id};
  var csrf    = '{$csrf}';
  var total   = {$new_count};
  var chunkSz = 50;

  var btnStart = document.getElementById('lum-batch-start');
  var btnDone  = document.getElementById('lum-batch-done');
  var bar      = document.getElementById('lum-batch-bar');
  var statusEl = document.getElementById('lum-batch-status');
  var countEl  = document.getElementById('lum-batch-count');
  var errsEl   = document.getElementById('lum-batch-errors');
  var progEl   = document.getElementById('lum-batch-progress');

  if (!btnStart) return; // no new images — nothing to do

  btnStart.addEventListener('click', function () {
    btnStart.disabled = true;
    if (progEl) progEl.style.display = 'block';
    processChunk(0);
  });

  function processChunk(processed) {
    if (statusEl) statusEl.textContent = 'Processing\u2026';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'ajax_batch.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 180000; // 3 minutes per chunk

    xhr.onload = function () {
      if (xhr.status !== 200) {
        showError('Server error ' + xhr.status);
        return;
      }
      var data;
      try {
        data = JSON.parse(xhr.responseText);
      } catch (e) {
        showError('Bad response from server');
        return;
      }

      if (data.error) {
        showError(data.error);
        return;
      }

      var doneCount = processed + (data.processed || 0);
      var pct = total > 0 ? Math.round(doneCount / total * 100) : 100;
      if (bar)     { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
      if (countEl) countEl.textContent = doneCount + ' / ' + total;

      if (data.errors && data.errors.length) {
        var ul = document.createElement('ul');
        ul.className = 'list-unstyled text-danger small mb-1';
        data.errors.forEach(function (msg) {
          var li = document.createElement('li');
          li.textContent = msg;
          ul.appendChild(li);
        });
        if (errsEl) errsEl.appendChild(ul);
      }

      if (data.done) {
        if (statusEl) statusEl.textContent = 'Done! ' + doneCount + ' image' + (doneCount !== 1 ? 's' : '') + ' processed.';
        if (bar) { bar.classList.remove('progress-bar-animated'); bar.style.width = '100%'; bar.textContent = '100%'; }
        if (btnDone) btnDone.classList.remove('d-none');
      } else {
        processChunk(doneCount);
      }
    };

    xhr.onerror = xhr.ontimeout = function () {
      showError('Request failed or timed out. Check server logs.');
    };

    xhr.send(
      'album=' + encodeURIComponent(albumId) +
      '&limit=' + encodeURIComponent(chunkSz) +
      '&csrf_token=' + encodeURIComponent(csrf)
    );
  }

  function showError(msg) {
    if (statusEl) statusEl.textContent = 'Error: ' + msg;
    if (errsEl) {
      var p = document.createElement('p');
      p.className = 'text-danger small mb-1';
      p.textContent = msg;
      errsEl.appendChild(p);
    }
    btnStart.disabled = false;
  }
}());
</script>
HTML;

lum_admin_page('Batch Add Images', $content, 'batch');

<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Regenerate Missing Thumbnails AJAX Handler
 *
 * Scans images in scope (entire gallery or a single album) and regenerates
 * thumbnails ONLY when the expected thumbnail file is missing or empty.
 * Images that already have a valid, non-empty thumbnail file are skipped
 * without any disk I/O or image-processing work.
 *
 * This complements ajax_thumbs.php (which unconditionally overwrites every
 * thumbnail). Use this handler for routine maintenance — it is significantly
 * faster when only a small fraction of thumbnails are absent.
 *
 * A thumbnail is considered valid if:
 *   - The file exists on disk (is_file() === true), AND
 *   - The file is non-empty (filesize() > 0).
 * Files that exist but are 0 bytes are treated as broken and regenerated.
 *
 * Uses keyset pagination (WHERE id > last_id) identical to the other
 * maintenance handlers.  Chunk sizes are kept small (max 25) because
 * thumbnail generation is CPU-intensive.
 *
 * POST params:
 *   last_id    int     Highest image ID already processed (0 for first call)
 *   limit      int     Records to process this call (max 25, default 20)
 *   album_id   int     Restrict to a specific album; 0 = all albums
 *   csrf_token string
 *
 * JSON response:
 *   {
 *     "checked":     N,   // rows fetched this chunk
 *     "regenerated": N,   // thumbnails successfully written (were missing/empty)
 *     "skipped":     N,   // thumbnails already present — not touched
 *     "no_orig":     N,   // original file not found — cannot regenerate
 *     "last_id":     N,
 *     "errors":      [],  // per-file error strings (generation failures)
 *     "done":        bool
 *   }
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!lumora_is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorised']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (
    !isset($_POST['csrf_token']) ||
    !hash_equals(lumora_csrf_token(), $_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$last_id  = lumora_int($_POST['last_id']  ?? 0, 0, 0);
$limit    = lumora_int($_POST['limit']    ?? 20, 20, 1, 25);
$album_id = lumora_int($_POST['album_id'] ?? 0, 0, 0);

// Allow extra time — regeneration is CPU-intensive even in small batches.
set_time_limit(300);

// ── Query chunk via keyset pagination ─────────────────────────────────────────
try {
    if ($album_id > 0) {
        $rows = LumoraDB::fetchAll(
            'SELECT i.id, i.filename, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id > ? AND i.album_id = ?
             ORDER BY i.id ASC
             LIMIT ?',
            [$last_id, $album_id, $limit]
        );
    } else {
        $rows = LumoraDB::fetchAll(
            'SELECT i.id, i.filename, a.folder
             FROM `{PREFIX}images` i
             LEFT JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id > ?
             ORDER BY i.id ASC
             LIMIT ?',
            [$last_id, $limit]
        );
    }
} catch (PDOException $e) {
    error_log('Lumora missing-thumbs regen query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during scan']);
    exit;
}

$checked     = count($rows);
$done        = ($checked < $limit);
$new_last    = $last_id;
$regenerated = 0;
$skipped     = 0;
$no_orig     = 0;
$errors      = [];

// Read thumb settings once to avoid repeated config lookups inside the loop.
$thumb_w = max(1, (int) lumora_config('thumb_width',  250));
$thumb_h = max(1, (int) lumora_config('thumb_height', 250));

foreach ($rows as $row) {
    $new_last = (int) $row['id'];

    // Image whose album record was deleted — cannot locate the original.
    if ($row['folder'] === null) {
        $no_orig++;
        continue;
    }

    $orig_path  = lumora_album_path($row['folder']) . $row['filename'];
    $thumb_path = lumora_album_path($row['folder']) . LUMORA_THUMB_PREFIX . $row['filename'];

    // Original does not exist on disk — nothing to derive a thumbnail from.
    if (!is_file($orig_path)) {
        $no_orig++;
        continue;
    }

    // Thumbnail is already present and non-empty — skip without any processing.
    if (is_file($thumb_path) && filesize($thumb_path) > 0) {
        $skipped++;
        continue;
    }

    // Thumbnail is missing or empty — generate it now.
    $ok = lumora_generate_thumb($orig_path, $thumb_path, $thumb_w, $thumb_h);

    if ($ok) {
        $regenerated++;
    } else {
        $errors[] = 'Failed: ' . $row['filename'];
        error_log('Lumora: missing-thumbnail regeneration failed for image ID ' . (int) $row['id']
            . ' (' . $orig_path . ')');
    }
}

echo json_encode([
    'checked'     => $checked,
    'regenerated' => $regenerated,
    'skipped'     => $skipped,
    'no_orig'     => $no_orig,
    'last_id'     => $new_last,
    'errors'      => $errors,
    'done'        => $done,
]);

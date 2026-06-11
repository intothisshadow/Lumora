<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Regenerate Thumbnails AJAX Handler
 *
 * Regenerates the thumbnail for every image in scope using the current
 * thumb_width, thumb_height, and thumb_quality config values.  Overwrites
 * existing thumbnail files; creates missing ones.  Original image files are
 * never modified.
 *
 * Thumbnail generation is CPU-intensive, so chunk sizes are kept small
 * (max 25 per call).  The caller should set a generous XHR timeout — the
 * default chunk of 20 images may take several seconds on shared hosting.
 *
 * Uses keyset pagination (WHERE id > last_id) identical to the other
 * maintenance handlers.
 *
 * POST params:
 *   last_id    int     Highest image ID already processed (0 for first call)
 *   limit      int     Records to process this call (max 25, default 20)
 *   album_id   int     Restrict to a specific album; 0 = all albums
 *   csrf_token string
 *
 * JSON response:
 *   {
 *     "checked": N,   // rows fetched this chunk
 *     "updated": N,   // thumbnails successfully written
 *     "skipped": N,   // original not found — thumbnail cannot be regenerated
 *     "last_id": N,
 *     "errors":  [],  // per-file error strings (generation failures)
 *     "done":    bool
 *   }
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

// Thumbnail generation is CPU-intensive; allow extra time per chunk.
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
    error_log('Lumora thumbs regen query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during scan']);
    exit;
}

$checked  = count($rows);
$done     = ($checked < $limit);
$new_last = $last_id;
$updated  = 0;
$skipped  = 0;
$errors   = [];

// Read thumb settings once; avoid repeated config lookups inside the loop.
$thumb_w = max(1, (int) lumora_config('thumb_width',  250));
$thumb_h = max(1, (int) lumora_config('thumb_height', 250));

foreach ($rows as $row) {
    $new_last = (int) $row['id'];

    // Image whose album record was deleted — cannot locate the original.
    if ($row['folder'] === null) {
        $skipped++;
        continue;
    }

    $orig_path  = lumora_album_path($row['folder']) . $row['filename'];
    $thumb_path = lumora_album_path($row['folder']) . LUMORA_THUMB_PREFIX . $row['filename'];

    if (!file_exists($orig_path)) {
        $skipped++;
        continue;
    }

    $ok = lumora_generate_thumb($orig_path, $thumb_path, $thumb_w, $thumb_h);

    if ($ok) {
        $updated++;
    } else {
        $errors[] = 'Failed: ' . $row['filename'];
        error_log('Lumora: thumbnail regeneration failed for image ID ' . (int) $row['id']
            . ' (' . $orig_path . ')');
    }
}

echo json_encode([
    'checked' => $checked,
    'updated' => $updated,
    'skipped' => $skipped,
    'last_id' => $new_last,
    'errors'  => $errors,
    'done'    => $done,
]);

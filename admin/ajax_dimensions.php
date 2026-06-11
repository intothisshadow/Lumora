<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Reload Dimensions & File Size AJAX Handler
 *
 * Re-reads the pixel dimensions and file size of each image from disk and
 * writes the updated values back to the images table.  Useful after manually
 * replacing image files, running an external resize tool, or migrating from
 * another gallery system where the stored metadata may be wrong or missing.
 *
 * Uses keyset pagination (WHERE id > last_id) identical to the integrity
 * scanner so query time stays constant regardless of gallery size.
 * Original files are never modified.
 *
 * POST params:
 *   last_id    int     Highest image ID already processed (0 for first call)
 *   limit      int     Records to process this call (max 200, default 100)
 *   album_id   int     Restrict to a specific album; 0 = all albums
 *   csrf_token string
 *
 * JSON response:
 *   {
 *     "checked": N,   // rows fetched this chunk
 *     "updated": N,   // DB rows successfully updated
 *     "skipped": N,   // files not found or unreadable (non-fatal)
 *     "last_id": N,   // highest id seen this chunk (keyset cursor)
 *     "errors":  [],  // per-file error strings (unreadable images)
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
$limit    = lumora_int($_POST['limit']    ?? 100, 100, 1, 200);
$album_id = lumora_int($_POST['album_id'] ?? 0, 0, 0);

// getimagesize() reads only image headers; 200 calls per chunk is fast.
set_time_limit(120);

// ── Query chunk via keyset pagination ─────────────────────────────────────────
// LEFT JOIN (when album_id = 0): catches records whose album was deleted.
// INNER JOIN (when album_id > 0): only images belonging to the named album.
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
    error_log('Lumora dimensions scan query error: ' . $e->getMessage());
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

foreach ($rows as $row) {
    $new_last = (int) $row['id'];

    // Image whose album record was deleted — cannot locate the file.
    if ($row['folder'] === null) {
        $skipped++;
        continue;
    }

    $path = lumora_album_path($row['folder']) . $row['filename'];

    if (!file_exists($path)) {
        $skipped++;
        continue;
    }

    [$width, $height] = lumora_get_image_dimensions($path);
    $filesize          = lumora_get_filesize($path);

    if ($width === 0 || $height === 0) {
        // File exists but getimagesize() could not read it (corrupt / unsupported format).
        $errors[] = 'Unreadable: ' . $row['filename'];
        $skipped++;
        continue;
    }

    try {
        LumoraDB::update(
            'images',
            ['width' => $width, 'height' => $height, 'filesize' => $filesize],
            'id = ?',
            [(int) $row['id']]
        );
        $updated++;
    } catch (PDOException $e) {
        $errors[] = 'DB error for ID ' . (int) $row['id'];
        error_log('Lumora dimensions update error (id=' . (int) $row['id'] . '): ' . $e->getMessage());
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

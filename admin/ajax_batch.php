<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Batch Add AJAX Handler
 *
 * Processes a chunk of new images for a given album.
 * Called repeatedly by the batch.php frontend until done=true.
 *
 * How the stateless chunking works
 * ---------------------------------
 * lumora_scan_new_images() returns only images NOT yet in the database.
 * We always slice from offset 0 and process the first $limit files.
 * After a successful chunk those files are in the DB, so the next call
 * receives a shorter list — naturally advancing through all images without
 * needing an explicit offset that would go stale as the list shrinks.
 *
 * POST params:
 *   album      int    Album ID
 *   limit      int    How many to process this call (max 100, default 50)
 *   csrf_token string
 *
 * JSON response:
 *   {
 *     "processed": 10,   // images successfully added this call
 *     "errors":    [],   // array of per-file error strings
 *     "done":      false // true when no more unprocessed images remain
 *   }
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!lumora_is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorised']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals(lumora_csrf_token(), $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$album_id = lumora_int($_POST['album'] ?? 0, 0, 1);
$limit    = min(100, max(1, lumora_int($_POST['limit'] ?? 50, 50, 1, 100)));

if ($album_id === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing album']);
    exit;
}

// ── Validate album ────────────────────────────────────────────────────────────
$album = LumoraDB::fetchOne('SELECT id, folder FROM `{PREFIX}albums` WHERE id = ?', [$album_id]);
if (!$album) {
    http_response_code(404);
    echo json_encode(['error' => 'Album not found']);
    exit;
}

// ── Scan for new images ───────────────────────────────────────────────────────
// lumora_scan_new_images() filters out files already in the DB, so the list
// shrinks naturally as each chunk is processed.  We always slice from index 0.
$all_new = lumora_scan_new_images($album['folder'], $album_id);
$chunk   = array_slice($all_new, 0, $limit);
$done    = count($all_new) <= $limit; // true when this is the last (or only) chunk

// ── Process chunk ─────────────────────────────────────────────────────────────
$processed = 0;
$errors    = [];

// Give shared-hosts time for large thumbnails (most allow at least 60 s).
set_time_limit(180);

foreach ($chunk as $filename) {
    $result = lumora_batch_add_image($filename, $album['folder'], $album_id);
    if ($result !== false) {
        $processed++;
    } else {
        $errors[] = 'Failed: ' . $filename;
    }
}

// Guard against an infinite loop: if we had files to process but none succeeded,
// stop immediately rather than asking the client to retry the same broken files.
if (!$done && $processed === 0 && !empty($chunk)) {
    $done = true;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'processed' => $processed,
    'errors'    => $errors,
    'done'      => $done,
]);

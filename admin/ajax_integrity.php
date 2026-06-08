<?php
declare(strict_types=1);
/**
 * Lumora Gallery — File Integrity Check AJAX Handler
 *
 * Processes one chunk of image records and returns which files are missing.
 * Uses keyset pagination (WHERE id > last_id) so performance stays constant
 * even on galleries with 500 000+ images — plain OFFSET becomes progressively
 * slower beyond ~100 000 rows and is not used here.
 *
 * POST params:
 *   last_id    int     Highest image ID already checked (0 for first call)
 *   limit      int     Records to check this call (max 1000, default 500)
 *   csrf_token string
 *
 * JSON response:
 *   {
 *     "checked":  500,
 *     "last_id":  12345,
 *     "missing":  [
 *       {
 *         "id":            42,
 *         "filename":      "photo.jpg",
 *         "album_title":   "Season 1",
 *         "folder":        "Xena/Season1",
 *         "orig_missing":  true,
 *         "thumb_missing": false
 *       }, …
 *     ],
 *     "done": false
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
$last_id = lumora_int($_POST['last_id'] ?? 0, 0, 0);
$limit   = lumora_int($_POST['limit']   ?? 500, 500, 1, 1000);

// Give ourselves enough time for large chunks.
@set_time_limit(120);

// ── Query chunk via keyset pagination ─────────────────────────────────────────
// LEFT JOIN: catches image records whose album row has been deleted (folder = null).
try {
    $rows = LumoraDB::fetchAll(
        'SELECT i.id, i.filename, i.album_id,
                a.folder, a.title AS album_title
         FROM `{PREFIX}images` i
         LEFT JOIN `{PREFIX}albums` a ON a.id = i.album_id
         WHERE i.id > ?
         ORDER BY i.id ASC
         LIMIT ?',
        [$last_id, $limit]
    );
} catch (PDOException $e) {
    error_log('Lumora integrity scan query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during scan']);
    exit;
}

$checked  = count($rows);
$done     = ($checked < $limit); // fewer rows than requested → end of table
$new_last = $last_id;
$missing  = [];

foreach ($rows as $row) {
    $new_last = (int) $row['id'];

    // Image whose album record no longer exists — both files are unreachable.
    if ($row['folder'] === null) {
        $missing[] = [
            'id'           => (int) $row['id'],
            'filename'     => $row['filename'],
            'album_title'  => '[Album deleted]',
            'folder'       => '',
            'orig_missing'  => true,
            'thumb_missing' => true,
        ];
        continue;
    }

    $orig_path  = lumora_album_path($row['folder']) . $row['filename'];
    $thumb_path = lumora_album_path($row['folder']) . LUMORA_THUMB_PREFIX . $row['filename'];

    $orig_missing  = !file_exists($orig_path);
    $thumb_missing = !file_exists($thumb_path);

    if ($orig_missing || $thumb_missing) {
        $missing[] = [
            'id'           => (int) $row['id'],
            'filename'     => $row['filename'],
            'album_title'  => $row['album_title'] ?? '',
            'folder'       => $row['folder'],
            'orig_missing'  => $orig_missing,
            'thumb_missing' => $thumb_missing,
        ];
    }
}

echo json_encode([
    'checked' => $checked,
    'last_id' => $new_last,
    'missing' => $missing,
    'done'    => $done,
]);

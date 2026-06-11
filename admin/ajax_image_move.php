<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Move Images to Another Album
 *
 * Moves up to 500 images per call. For each image:
 *   1. Checks there is no filename collision in the target folder.
 *   2. Moves the original file (rename; falls back to copy+unlink).
 *   3. Moves the thumbnail if it exists (non-fatal if absent or move fails).
 *   4. Updates album_id in the database.
 *   5. Resets the source album's thumb_image_id if it pointed at this image.
 *
 * Images already in the target album are silently skipped (not counted as errors).
 *
 * POST params: ids[] (int, up to 500), target_album_id (int), csrf_token (string)
 * Response:    JSON { moved: int, errors: string[] }
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

lumora_csrf_validate();

set_time_limit(120);

$target_id = lumora_int($_POST['target_album_id'] ?? 0, 0, 1);
$ids_raw   = $_POST['ids'] ?? [];

if ($target_id === 0) {
    echo json_encode(['moved' => 0, 'errors' => ['No target album selected.']]);
    exit;
}

if (!is_array($ids_raw) || count($ids_raw) === 0) {
    echo json_encode(['moved' => 0, 'errors' => ['No image IDs provided.']]);
    exit;
}

// Verify target album exists.
$target_album = LumoraDB::fetchOne(
    'SELECT id, folder FROM `{PREFIX}albums` WHERE id = ?',
    [$target_id]
);
if (!$target_album) {
    echo json_encode(['moved' => 0, 'errors' => ['Target album not found.']]);
    exit;
}

$target_folder = $target_album['folder'];

// Cast to positive integers and deduplicate; cap at 500 per call.
$ids = array_values(array_unique(array_filter(
    array_map(static fn(mixed $v): int => (int) $v, $ids_raw),
    static fn(int $v): bool => $v > 0
)));
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

$moved  = 0;
$errors = [];

foreach ($ids as $id) {
    $image = LumoraDB::fetchOne(
        'SELECT i.id, i.filename, i.album_id, a.folder
         FROM `{PREFIX}images` i
         JOIN `{PREFIX}albums` a ON a.id = i.album_id
         WHERE i.id = ?',
        [$id]
    );

    if (!$image) {
        $errors[] = "Image #$id: not found.";
        continue;
    }

    // Already in the target album — skip silently.
    if ((int) $image['album_id'] === $target_id) {
        continue;
    }

    $src_folder = $image['folder'];
    $filename   = $image['filename'];
    $thumb_name = LUMORA_THUMB_PREFIX . $filename;

    $orig_src  = lumora_album_path($src_folder) . $filename;
    $thumb_src = lumora_album_path($src_folder) . $thumb_name;
    $orig_dst  = lumora_album_path($target_folder) . $filename;
    $thumb_dst = lumora_album_path($target_folder) . $thumb_name;

    // Refuse to overwrite an existing file in the target folder.
    if (is_file($orig_dst)) {
        $errors[] = $filename . ': a file with this name already exists in the target album.';
        continue;
    }

    // Move original: try rename first (fast); fall back to copy+unlink for cross-fs moves.
    if (is_file($orig_src)) {
        $ok_orig = rename($orig_src, $orig_dst);
        if (!$ok_orig) {
            if (copy($orig_src, $orig_dst)) {
                unlink($orig_src);
            } else {
                $errors[] = $filename . ': could not move original file.';
                continue;
            }
        }
    }

    // Move thumbnail (non-fatal: proceed even if move fails).
    if (is_file($thumb_src)) {
        $ok_thumb = rename($thumb_src, $thumb_dst);
        if (!$ok_thumb && copy($thumb_src, $thumb_dst)) {
            unlink($thumb_src);
        }
    }

    // Update the image's album association.
    LumoraDB::update('images', ['album_id' => $target_id], 'id = ?', [$id]);

    // Reset the source album cover if it pointed to this image.
    LumoraDB::query(
        'UPDATE `{PREFIX}albums` SET thumb_image_id = 0
         WHERE id = ? AND thumb_image_id = ?',
        [(int) $image['album_id'], $id]
    );

    $moved++;
}

echo json_encode(['moved' => $moved, 'errors' => $errors]);
exit;

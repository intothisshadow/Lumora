<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Delete Images
 *
 * Deletes up to 500 images per call. For each image:
 *   1. Removes the original file and thumbnail from disk.
 *   2. Deletes the database record.
 *   3. Resets any album or category cover that referenced the deleted image
 *      (sets thumb_image_id = 0 so the auto-pick fallback kicks in).
 *
 * POST params: ids[] (int, up to 500), csrf_token (string)
 * Response:    JSON { deleted: int, errors: string[] }
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

$ids_raw = $_POST['ids'] ?? [];
if (!is_array($ids_raw) || count($ids_raw) === 0) {
    echo json_encode(['deleted' => 0, 'errors' => ['No image IDs provided.']]);
    exit;
}

// Cast to positive integers and deduplicate; cap at 500 per call.
$ids = array_values(array_unique(array_filter(
    array_map(static fn(mixed $v): int => (int) $v, $ids_raw),
    static fn(int $v): bool => $v > 0
)));
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

$deleted = 0;
$errors  = [];

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

    $orig_path  = lumora_album_path($image['folder']) . $image['filename'];
    $thumb_path = lumora_album_path($image['folder']) . LUMORA_THUMB_PREFIX . $image['filename'];

    // Delete original (non-fatal if already absent).
    if (is_file($orig_path) && !unlink($orig_path)) {
        $errors[] = 'Image #' . $id . ' (' . $image['filename'] . '): could not delete original file.';
    }

    // Delete thumbnail (non-fatal if absent).
    if (is_file($thumb_path)) {
        unlink($thumb_path);
    }

    // Remove DB record.
    LumoraDB::delete('images', 'id = ?', [$id]);

    // Reset album and category cover references so auto-pick takes over.
    LumoraDB::query(
        'UPDATE `{PREFIX}albums` SET thumb_image_id = 0 WHERE thumb_image_id = ?',
        [$id]
    );
    LumoraDB::query(
        'UPDATE `{PREFIX}categories` SET thumb_image_id = 0 WHERE thumb_image_id = ?',
        [$id]
    );

    $deleted++;
}

echo json_encode(['deleted' => $deleted, 'errors' => $errors]);
exit;

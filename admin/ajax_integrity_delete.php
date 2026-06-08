<?php
declare(strict_types=1);
/**
 * Lumora Gallery — File Integrity Check: Delete Orphaned Records AJAX Handler
 *
 * Removes image records from the database whose files have been confirmed
 * missing on disk by the integrity scan.  Only the database rows are deleted;
 * no files on disk are ever touched.
 *
 * POST params:
 *   ids[]      int[]  Image IDs to delete (max 5 000 per request)
 *   csrf_token string
 *
 * JSON response:
 *   { "deleted": N, "errors": [] }
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
$raw_ids = $_POST['ids'] ?? [];

if (!is_array($raw_ids) || empty($raw_ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'No IDs provided']);
    exit;
}

// Cast to positive integers and deduplicate.
$ids = array_values(array_unique(
    array_filter(
        array_map('intval', $raw_ids),
        static fn(int $id): bool => $id > 0
    )
));

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid IDs provided']);
    exit;
}

if (count($ids) > 5000) {
    http_response_code(400);
    echo json_encode(['error' => 'Too many IDs in one request (max 5 000)']);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
$deleted = 0;
$errors  = [];

try {
    LumoraDB::beginTransaction();

    foreach ($ids as $id) {
        try {
            // rowCount() = 0 if the record was already gone between scan and
            // this request — treat as success (nothing left to delete).
            $deleted += LumoraDB::delete('images', 'id = ?', [$id]);
        } catch (PDOException $e) {
            $errors[] = 'Failed to delete ID ' . $id;
            error_log('Lumora integrity delete error (id=' . $id . '): ' . $e->getMessage());
        }
    }

    LumoraDB::commit();
} catch (PDOException $e) {
    try {
        LumoraDB::rollBack();
    } catch (PDOException) {
        // Ignore rollback failure — connection may already be in a clean state.
    }
    error_log('Lumora integrity delete transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during deletion']);
    exit;
}

echo json_encode([
    'deleted' => $deleted,
    'errors'  => $errors,
]);

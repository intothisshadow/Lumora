<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Run Database Migrations
 *
 * Discovers and applies all pending schema migrations via SchemaService.
 * Called from the Admin → Updates page when the administrator clicks
 * "Run Database Update".
 *
 * Method: POST
 * Required: csrf_token
 * Auth:    admin session
 *
 * Response JSON shape:
 *   { success: bool, applied: string[], errors: string[], message: string }
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!lumora_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'applied' => [], 'errors' => [], 'message' => 'Forbidden']);
    exit;
}

if (!hash_equals(lumora_csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'applied' => [], 'errors' => [], 'message' => 'Invalid CSRF token']);
    exit;
}

$result  = SchemaService::runPendingMigrations();
$success = empty($result['errors']);
$count   = count($result['applied']);

if ($success && $count === 0) {
    $message = 'No pending migrations — the database is already up to date.';
} elseif ($success) {
    $message = $count === 1
        ? '1 migration applied successfully.'
        : "{$count} migrations applied successfully.";
} else {
    $applied_note = $count > 0 ? " ({$count} applied before failure)" : '';
    $message      = "Migration failed{$applied_note}. See errors below.";
}

echo json_encode([
    'success' => $success,
    'applied' => $result['applied'],
    'errors'  => $result['errors'],
    'message' => $message,
]);
exit;

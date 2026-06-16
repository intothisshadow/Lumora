<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Force update check
 *
 * Fetches the remote update endpoint, refreshes the local cache, and
 * returns the current update status as JSON.  Called from the Updates
 * admin page when the administrator clicks "Check for Updates Now".
 *
 * Required POST param: csrf_token
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
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (!hash_equals(lumora_csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$result = UpdateService::check(force: true);

echo json_encode($result);
exit;

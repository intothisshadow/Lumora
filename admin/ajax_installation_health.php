<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Installation Health Check
 *
 * Runs InstallationService::runHealthCheck() and returns the results as JSON.
 * Called by the Installation Settings admin page via fetch().
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
    echo json_encode(['error' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals(lumora_csrf_token(), (string) $_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed.']);
    exit;
}

try {
    $checks = InstallationService::runHealthCheck();
    $all_ok = array_reduce(
        $checks,
        static fn(bool $carry, array $c): bool => $carry && $c['ok'],
        true
    );

    echo json_encode([
        'checks' => $checks,
        'all_ok' => $all_ok,
    ]);
} catch (\Throwable $e) {
    lumora_log('error', 'ajax_installation_health: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Health check failed unexpectedly.']);
}

exit;

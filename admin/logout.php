<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Logout
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    lumora_logout();
}

lumora_redirect(lumora_base_url() . 'admin/login.php');

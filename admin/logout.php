<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Logout
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    lumora_logout(true); // clear_remember: revoke persistent tokens on explicit logout
}

lumora_redirect(lumora_base_url() . 'admin/login.php');

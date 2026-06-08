<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin index
 * Redirects to dashboard if logged in, login if not.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

if (lumora_is_admin()) {
    lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
} else {
    lumora_redirect(lumora_base_url() . 'admin/login.php');
}

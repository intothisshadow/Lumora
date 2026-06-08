<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Core Bootstrap
 *
 * Every entry point (public pages, admin pages, AJAX handlers) must define
 * LUMORA_ENTRY before requiring this file. The installer defines LUMORA_INSTALLER
 * additionally so that the "redirect to /install/" guard is skipped.
 *
 * Load order:
 *   1. PHP version check
 *   2. Path constants
 *   3. version.php
 *   4. config.php existence check / redirect to installer
 *   5. config.php  (DB credentials + LUMORA_INSTALLED)
 *   6. db.php      (connects immediately)
 *   7. functions.php
 *   8. auth.php
 *   9. thumb.php
 *  10. template.php
 *  11. PHP session start
 *  12. Gallery config loaded from DB into $LUMORA_CONFIG
 */

if (!defined('LUMORA_ENTRY')) {
    exit('Direct access denied.');
}

// ── 1. PHP version ──────────────────────────────────────────────────────────
if (PHP_VERSION_ID < 80200) {
    exit('Lumora requires PHP 8.2 or higher. You are running PHP ' . PHP_VERSION . '.');
}

// ── 2. Path constants ────────────────────────────────────────────────────────
// __DIR__ is always include/, so dirname(__DIR__) is the Lumora root.
define('LUMORA_ROOT',        dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('LUMORA_INCLUDE',     __DIR__          . DIRECTORY_SEPARATOR);
define('LUMORA_ALBUMS_PATH', LUMORA_ROOT . 'albums'  . DIRECTORY_SEPARATOR);
define('LUMORA_THEMES_PATH', LUMORA_ROOT . 'themes'  . DIRECTORY_SEPARATOR);
define('LUMORA_ADMIN_PATH',  LUMORA_ROOT . 'admin'   . DIRECTORY_SEPARATOR);

/** Coppermine-compatible thumbnail prefix. */
define('LUMORA_THUMB_PREFIX', 'thumb_');

// ── 3. Version ───────────────────────────────────────────────────────────────
require_once LUMORA_ROOT . 'version.php';

// ── 4. Config existence check ────────────────────────────────────────────────
$_lumora_config_file = LUMORA_ROOT . 'config.php';

if (!file_exists($_lumora_config_file)) {
    if (!defined('LUMORA_INSTALLER')) {
        // Detect the correct path to /install/ relative to this request.
        $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script    = $_SERVER['SCRIPT_NAME'] ?? '/';
        // Walk up until we find a segment that is not admin/, album.php, etc.
        $base_path = rtrim(dirname($script), '/\\');
        // If we're in admin/, go one level up.
        if (str_ends_with($base_path, '/admin')) {
            $base_path = dirname($base_path);
        }
        $install_url = $proto . '://' . $host . $base_path . '/install/';
        header('Location: ' . $install_url);
        exit;
    }
    return; // Inside installer, stop here — installer handles its own DB setup.
}

// ── 5. Load config.php ───────────────────────────────────────────────────────
require_once $_lumora_config_file;

// ── 6. Database ──────────────────────────────────────────────────────────────
require_once LUMORA_INCLUDE . 'db.php';

try {
    LumoraDB::connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PREFIX, DB_CHARSET);
} catch (RuntimeException $e) {
    exit('Database connection failed. Please check your config.php settings.<br>' . htmlspecialchars($e->getMessage()));
}

// ── 7–10. Includes ───────────────────────────────────────────────────────────
require_once LUMORA_INCLUDE . 'functions.php';
require_once LUMORA_INCLUDE . 'auth.php';
require_once LUMORA_INCLUDE . 'thumb.php';
require_once LUMORA_INCLUDE . 'template.php';

// ── 11. Session ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie settings.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── 12. Gallery config ───────────────────────────────────────────────────────
lumora_load_config();

unset($_lumora_config_file);

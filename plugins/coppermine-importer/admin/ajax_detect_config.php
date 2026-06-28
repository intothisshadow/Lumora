<?php

declare(strict_types=1);
/**
 * Coppermine Importer — Config Detection AJAX Handler
 *
 * Accepts POST requests from the auto-detect panel on the credentials form.
 * Two actions are supported:
 *
 *   find   — Search a user-supplied filesystem path for Coppermine
 *             installations. Returns either a single detected config
 *             (ok, multiple=false, config={...}) or a list for user
 *             selection (ok, multiple=true, installations=[...]).
 *             Detected passwords are included in the single-install response
 *             for form pre-fill but are NOT included in the multi-install list.
 *             Detected paths are stored in session for the select action.
 *
 *   select — Retrieve the parsed config for one installation chosen from the
 *             list returned by a preceding find call. The candidate list is
 *             read from session (never from client input) to prevent path
 *             manipulation.
 *
 * SECURITY NOTES:
 *   - Passwords never appear in error messages or log entries.
 *   - The select action resolves paths from the server-side session list
 *     only — the client sends an integer index, not a path.
 *   - Admin authentication and CSRF are enforced on every request.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);

// Bootstrap path: this file is at plugins/coppermine-importer/admin/ajax_detect_config.php
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/CoppermineConfigDetector.php';

lumora_require_admin();

// ── JSON output helpers ────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

function cpg_detect_ok(array $data): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function cpg_detect_error(string $message, int $http = 400): never
{
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Request validation ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpg_detect_error('POST required.', 405);
}

if (!isset($_POST['csrf_token']) || !hash_equals(lumora_csrf_token(), (string) $_POST['csrf_token'])) {
    cpg_detect_error('CSRF token invalid.', 403);
}

$action = trim((string) ($_POST['action'] ?? ''));

// ── Session key for multi-install candidate list ───────────────────────────────

/** Session key used to store candidate config paths between find and select. */
const CPG_DETECT_SESSION_KEY = 'lumora_cpg_detect_candidates';

// ── Actions ────────────────────────────────────────────────────────────────────

try {

    switch ($action) {

    // ── find ──────────────────────────────────────────────────────────────────
    case 'find':

        $cpg_path = trim((string) ($_POST['cpg_path'] ?? ''));
        if ($cpg_path === '') {
            cpg_detect_error('No path provided. Please enter the filesystem path to your Coppermine installation.');
        }

        $configs = CoppermineConfigDetector::findInstallations($cpg_path);

        if (empty($configs)) {
            cpg_detect_error(
                'No Coppermine configuration file found under "' . $cpg_path . '". '
                . 'Make sure the path points to your Coppermine root directory '
                . '(the folder that contains an include/ subdirectory with config.inc.php).'
            );
        }

        // Store all found paths in session so `select` can look them up by index.
        // This prevents the client from supplying arbitrary filesystem paths.
        $_SESSION[CPG_DETECT_SESSION_KEY] = $configs;

        // Single installation — parse and return the full config.
        if (count($configs) === 1) {
            try {
                $config = CoppermineConfigDetector::parseConfig($configs[0]);
            } catch (\RuntimeException $e) {
                cpg_detect_error($e->getMessage());
            }

            // Remember the last successful path for next-session convenience.
            // Store the CPG root (parent of include/), not the config file path.
            $_SESSION['lumora_cpg_last_detect_path'] = dirname(dirname($configs[0]));

            cpg_detect_ok([
                'multiple'     => false,
                'config'       => $config,
                'install_path' => dirname(dirname($configs[0])),
            ]);
        }

        // Multiple installations — return metadata only (no passwords in this response).
        $installations = [];
        $root_real     = (string) (realpath($cpg_path) ?: $cpg_path);

        foreach ($configs as $idx => $cfg_path) {
            // Compute a display path relative to the searched root.
            $install_dir = dirname(dirname($cfg_path));
            $rel_path    = ltrim(str_replace($root_real, '', $install_dir), '/\\');
            if ($rel_path === '') {
                $rel_path = $install_dir;
            }

            // Parse to get dbname and dbserver for the selection list.
            // On parse failure we still include the installation so the user
            // can see it, but mark it as unparseable.
            $dbname   = '(could not parse)';
            $dbserver = '';
            try {
                $parsed   = CoppermineConfigDetector::parseConfig($cfg_path);
                $dbname   = $parsed['dbname'];
                $dbserver = $parsed['dbserver'];
            } catch (\RuntimeException) {
                // Swallow — unparseable entries are shown but greyed out in UI.
            }

            $installations[] = [
                'index'    => $idx,
                'rel_path' => $rel_path,
                'dbname'   => $dbname,
                'dbserver' => $dbserver,
            ];
        }

        cpg_detect_ok([
            'multiple'      => true,
            'installations' => $installations,
        ]);

    // ── select ────────────────────────────────────────────────────────────────
    case 'select':

        $idx        = (int) ($_POST['select_index'] ?? -1);
        $candidates = $_SESSION[CPG_DETECT_SESSION_KEY] ?? [];

        if (!is_array($candidates) || !isset($candidates[$idx])) {
            cpg_detect_error(
                'Invalid selection. Please use the Detect button again to refresh the installation list.',
                400
            );
        }

        $cfg_path = (string) $candidates[$idx];

        try {
            $config = CoppermineConfigDetector::parseConfig($cfg_path);
        } catch (\RuntimeException $e) {
            cpg_detect_error($e->getMessage());
        }

        // Persist last path and clear the candidate list from session.
        $_SESSION['lumora_cpg_last_detect_path'] = dirname(dirname($cfg_path));
        unset($_SESSION[CPG_DETECT_SESSION_KEY]);

        cpg_detect_ok([
            'config'       => $config,
            'install_path' => dirname(dirname($cfg_path)),
        ]);

    default:
        cpg_detect_error('Unknown action: ' . $action, 400);

    }

} catch (\Throwable $e) {
    cpg_detect_error(
        get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
        500
    );
}

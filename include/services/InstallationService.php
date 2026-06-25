<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Installation Service
 *
 * Handles environment detection, configuration validation, cache clearing, and
 * post-migration health checks for the Installation Settings admin tool
 * (admin/installation.php).
 *
 * Key responsibilities:
 *   - Detect the current server environment (URL, paths, PHP version, extensions).
 *   - Compare the live environment against the values stored in {PREFIX}config.
 *   - Validate and apply updated configuration values with full audit logging.
 *   - Clear application caches after a configuration change.
 *   - Run a full health check of all critical installation components.
 *   - Persist every change to {PREFIX}config_changes for an auditable record.
 *   - Export the current installation state as a JSON snapshot.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class InstallationService
{
    // ── Environment detection ─────────────────────────────────────────────────

    /**
     * Detect the current server environment from live PHP superglobals.
     *
     * The detected_url is derived from the current HTTP request — the protocol,
     * host, and the path two levels above the current script (i.e. the Lumora
     * web root, not the admin/ sub-directory). This value can be compared against
     * the stored base_url to surface domain or subdirectory mismatches.
     *
     * @return array{
     *   detected_url: string,
     *   root_path:    string,
     *   albums_path:  string,
     *   cache_path:   string,
     *   php_version:  string,
     *   web_server:   string,
     *   https:        bool,
     * }
     */
    public static function detectEnvironment(): array
    {
        // Honour common reverse-proxy headers.
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        $proto  = $https ? 'https' : 'http';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/admin/installation.php';

        // Walk up two directory levels: installation.php → admin/ → Lumora root.
        $admin_path = dirname($script);   // e.g. /gallery/admin
        $base_path  = dirname($admin_path); // e.g. /gallery

        // dirname('/admin') returns '/' and dirname('/') returns '/'.
        // Normalise so we don't double-slash the root.
        if ($base_path === DIRECTORY_SEPARATOR || $base_path === '.') {
            $base_path = '';
        }

        $detected_url = $proto . '://' . $host . $base_path . '/';

        return [
            'detected_url' => $detected_url,
            'root_path'    => LUMORA_ROOT,
            'albums_path'  => LUMORA_ALBUMS_PATH,
            'cache_path'   => LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR,
            'php_version'  => PHP_VERSION,
            'web_server'   => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
            'https'        => $https,
        ];
    }

    /**
     * Return the installation-relevant values from the stored configuration.
     *
     * Database credentials (DB_PASS, etc.) are never returned — they are
     * available to the caller only as PHP constants defined in config.php.
     *
     * @return array{
     *   base_url:     string,
     *   gallery_name: string,
     *   db_host:      string,
     *   db_name:      string,
     *   db_prefix:    string,
     * }
     */
    public static function getStoredConfig(): array
    {
        return [
            'base_url'     => (string) lumora_config('base_url', ''),
            'gallery_name' => (string) lumora_config('gallery_name', 'Lumora Gallery'),
            'db_host'      => defined('DB_HOST') ? (string) DB_HOST : '(unknown)',
            'db_name'      => defined('DB_NAME') ? (string) DB_NAME : '(unknown)',
            'db_prefix'    => LumoraDB::prefix(),
        ];
    }

    // ── Change detection ──────────────────────────────────────────────────────

    /**
     * Compare the current server environment against the stored configuration
     * and return a list of detected differences.
     *
     * Returns an empty list when everything matches. Each entry describes a
     * single mismatch: the field name, a human-readable label, the stored
     * value, and the detected value.
     *
     * @return list<array{field: string, label: string, stored: string, detected: string}>
     */
    public static function detectChanges(): array
    {
        $env    = self::detectEnvironment();
        $stored = self::getStoredConfig();
        $diffs  = [];

        $stored_url   = rtrim($stored['base_url'], '/') . '/';
        $detected_url = $env['detected_url'];

        if ($stored_url !== $detected_url) {
            $diffs[] = [
                'field'    => 'base_url',
                'label'    => 'Site URL',
                'stored'   => $stored_url,
                'detected' => $detected_url,
            ];
        }

        // Suggest HTTP → HTTPS upgrade when the server reports HTTPS but the
        // stored URL still uses the http:// scheme.
        if (
            str_starts_with($stored_url, 'http://')
            && $env['https']
            && !in_array(['field' => 'base_url', 'label' => 'Site URL', 'stored' => $stored_url, 'detected' => $detected_url], $diffs, true)
        ) {
            $diffs[] = [
                'field'    => 'https_hint',
                'label'    => 'HTTPS available',
                'stored'   => 'URL uses http:// (no encryption)',
                'detected' => 'HTTPS is active on this connection — consider switching to https://',
            ];
        }

        return $diffs;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Validate a URL string.
     *
     * @return array{valid: bool, error: string}
     */
    public static function validateUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            return ['valid' => false, 'error' => 'URL cannot be empty.'];
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return ['valid' => false, 'error' => 'URL must start with http:// or https://.'];
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['valid' => false, 'error' => 'URL is not a valid URL format.'];
        }

        return ['valid' => true, 'error' => ''];
    }

    // ── Apply settings ────────────────────────────────────────────────────────

    /**
     * Validate and apply a set of updated installation settings.
     *
     * Only the keys listed in the internal allowlist are accepted. Each value
     * is validated before being written. All applied changes are logged to the
     * config_changes audit table. Application caches are cleared after any
     * successful write.
     *
     * @param array<string, string> $settings    Key → new value pairs to apply.
     * @param int    $user_id   ID of the administrator making the change.
     * @param string $username  Username of the administrator.
     * @param string $ip        Request IP address.
     * @return array{success: bool, applied: list<string>, errors: list<string>}
     */
    public static function applySettings(
        array  $settings,
        int    $user_id,
        string $username,
        string $ip
    ): array {
        $applied = [];
        $errors  = [];

        // Allowlist of config keys this tool is permitted to modify.
        $allowed = ['base_url'];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $new_value = trim($settings[$key]);

            // Key-specific normalisation and validation.
            if ($key === 'base_url') {
                $new_value  = rtrim($new_value, '/') . '/';
                $validation = self::validateUrl($new_value);
                if (!$validation['valid']) {
                    $errors[] = 'Site URL: ' . $validation['error'];
                    continue;
                }
            }

            $old_value = (string) lumora_config($key, '');

            if ($old_value === $new_value) {
                continue; // Already up to date — skip silently.
            }

            LumoraConfig::set($key, $new_value);
            self::logConfigChange($user_id, $username, $ip, $key, $old_value, $new_value);
            $applied[] = $key;

            lumora_log(
                'info',
                sprintf(
                    'InstallationService: %s changed config[%s] (IP: %s)',
                    $username, $key, $ip
                )
            );
        }

        if (!empty($applied)) {
            self::clearCaches();
        }

        return [
            'success' => empty($errors),
            'applied' => $applied,
            'errors'  => $errors,
        ];
    }

    // ── Cache management ──────────────────────────────────────────────────────

    /**
     * Clear application caches.
     *
     * Deletes non-hidden files in the cache/ root directory (hidden files such
     * as .htaccess and .maintenance_active are intentionally preserved).
     * Sub-directories (e.g. cache/.updates/) are not touched.
     * Calls opcache_reset() when OPcache is available.
     * Reloads the LumoraConfig in-memory cache from the database so the caller
     * immediately sees the updated values.
     */
    public static function clearCaches(): bool
    {
        $cache_dir = LUMORA_ROOT . 'cache';
        $success   = true;

        if (is_dir($cache_dir)) {
            try {
                foreach (new DirectoryIterator($cache_dir) as $item) {
                    if ($item->isDot() || $item->isDir()) {
                        continue;
                    }
                    if (str_starts_with($item->getFilename(), '.')) {
                        continue; // Preserve hidden files
                    }
                    if (!unlink($item->getPathname())) {
                        $success = false;
                        lumora_log('error', 'InstallationService: failed to delete cache file: ' . $item->getPathname());
                    }
                }
            } catch (\Throwable $e) {
                lumora_log('error', 'InstallationService: cache clear error: ' . $e->getMessage());
                $success = false;
            }
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Reload in-memory config so callers see the fresh values immediately.
        LumoraConfig::load();

        return $success;
    }

    // ── Health check ──────────────────────────────────────────────────────────

    /**
     * Run a full health check of the Lumora installation.
     *
     * Each check returns an array describing its outcome. The `ok` flag is
     * true for fully passing checks, false for failures, and for warnings the
     * check is still considered OK (not blocking) but worth drawing attention to.
     *
     * Checks performed:
     *   1. Database connectivity
     *   2. Upload (albums) directory accessible and writable
     *   3. Cache directory writable (if it exists)
     *   4. Configuration file present
     *   5. Site URL stored and valid
     *   6. PHP version meets minimum requirement
     *   7. Image processor (Imagick or GD) loaded
     *   8. PDO MySQL extension loaded
     *   9. ZipArchive extension loaded (required for automatic updates)
     *
     * @return list<array{name: string, status: 'OK'|'WARNING'|'FAIL', detail: string, ok: bool}>
     */
    public static function runHealthCheck(): array
    {
        $checks = [];

        // ── 1. Database connectivity ──────────────────────────────────────────
        try {
            LumoraDB::fetchValue('SELECT 1');
            $db_name = defined('DB_NAME') ? (string) DB_NAME : 'database';
            $checks[] = [
                'name'   => 'Database connectivity',
                'status' => 'OK',
                'detail' => 'Successfully connected to ' . $db_name . '.',
                'ok'     => true,
            ];
        } catch (\Throwable) {
            $checks[] = [
                'name'   => 'Database connectivity',
                'status' => 'FAIL',
                'detail' => 'Cannot connect to the database. Verify credentials in config.php.',
                'ok'     => false,
            ];
        }

        // ── 2. Upload (albums) directory ──────────────────────────────────────
        $albums   = LUMORA_ALBUMS_PATH;
        $alb_ok   = is_dir($albums) && is_writable($albums);
        $checks[] = [
            'name'   => 'Upload directory (albums/)',
            'status' => $alb_ok ? 'OK' : (is_dir($albums) ? 'WARNING' : 'FAIL'),
            'detail' => is_dir($albums)
                ? ($alb_ok
                    ? 'Accessible and writable: ' . $albums
                    : 'Directory exists but is not writable: ' . $albums . ' — set permissions to 755 or 775.')
                : 'Directory missing: ' . $albums . ' — create it and set permissions to 755.',
            'ok' => $alb_ok,
        ];

        // ── 3. Cache directory ────────────────────────────────────────────────
        $cache      = LUMORA_ROOT . 'cache';
        $cache_ok   = !is_dir($cache) || is_writable($cache);
        $checks[]   = [
            'name'   => 'Cache directory (cache/)',
            'status' => $cache_ok ? 'OK' : 'WARNING',
            'detail' => !is_dir($cache)
                ? 'Directory does not yet exist — it will be created automatically on first use.'
                : ($cache_ok
                    ? 'Accessible and writable: ' . $cache
                    : 'Directory exists but is not writable: ' . $cache . ' — set permissions to 755 or 775.'),
            'ok' => $cache_ok,
        ];

        // ── 4. Configuration file ─────────────────────────────────────────────
        $config_file = LUMORA_ROOT . 'config.php';
        $cfg_ok      = file_exists($config_file);
        $checks[]    = [
            'name'   => 'Configuration file (config.php)',
            'status' => $cfg_ok ? 'OK' : 'FAIL',
            'detail' => $cfg_ok
                ? 'Found at: ' . $config_file
                : 'Missing: ' . $config_file . ' — re-run the installer or restore from backup.',
            'ok' => $cfg_ok,
        ];

        // ── 5. Site URL ───────────────────────────────────────────────────────
        $base_url  = (string) lumora_config('base_url', '');
        $url_valid = ($base_url !== '' && filter_var($base_url, FILTER_VALIDATE_URL) !== false);
        $checks[]  = [
            'name'   => 'Site URL configured',
            'status' => $url_valid ? 'OK' : 'WARNING',
            'detail' => $url_valid
                ? 'Stored site URL: ' . $base_url
                : 'Site URL is ' . ($base_url === '' ? 'empty' : 'invalid') . ' — update it using the form below.',
            'ok' => $url_valid,
        ];

        // ── 6. PHP version ────────────────────────────────────────────────────
        $php_ok  = PHP_VERSION_ID >= 80200;
        $checks[] = [
            'name'   => 'PHP version',
            'status' => $php_ok ? 'OK' : 'FAIL',
            'detail' => 'PHP ' . PHP_VERSION . ($php_ok ? ' — meets minimum requirement (8.2).' : ' — Lumora requires PHP 8.2 or higher.'),
            'ok'     => $php_ok,
        ];

        // ── 7. Image processor ────────────────────────────────────────────────
        $has_imagick = extension_loaded('imagick');
        $has_gd      = extension_loaded('gd');
        $img_ok      = $has_imagick || $has_gd;
        $checks[]    = [
            'name'   => 'Image processor (Imagick / GD)',
            'status' => $has_imagick ? 'OK' : ($has_gd ? 'WARNING' : 'FAIL'),
            'detail' => $has_imagick
                ? 'Imagick PHP extension loaded (preferred).'
                : ($has_gd
                    ? 'GD loaded as fallback — install php-imagick for better thumbnail quality.'
                    : 'Neither Imagick nor GD is loaded — thumbnail generation is disabled.'),
            'ok' => $img_ok,
        ];

        // ── 8. PDO MySQL ──────────────────────────────────────────────────────
        $pdo_ok  = extension_loaded('pdo_mysql');
        $checks[] = [
            'name'   => 'PDO MySQL extension',
            'status' => $pdo_ok ? 'OK' : 'FAIL',
            'detail' => $pdo_ok
                ? 'pdo_mysql extension loaded.'
                : 'pdo_mysql extension is missing — required for all database access.',
            'ok' => $pdo_ok,
        ];

        // ── 9. ZipArchive ─────────────────────────────────────────────────────
        $zip_ok  = extension_loaded('zip');
        $checks[] = [
            'name'   => 'ZipArchive extension',
            'status' => $zip_ok ? 'OK' : 'WARNING',
            'detail' => $zip_ok
                ? 'zip extension loaded (required for automatic updates).'
                : 'zip extension is missing — automatic in-dashboard updates will not be available.',
            'ok' => $zip_ok,
        ];

        return $checks;
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    /**
     * Write one entry to the {PREFIX}config_changes audit table.
     * Fails silently on pre-v8 installs where the table does not yet exist.
     */
    public static function logConfigChange(
        int    $user_id,
        string $username,
        string $ip,
        string $key,
        string $old_value,
        string $new_value
    ): void {
        try {
            LumoraDB::insert('config_changes', [
                'user_id'   => $user_id,
                'username'  => substr($username, 0, 50),
                'ip'        => substr($ip, 0, 45),
                'key'       => substr($key, 0, 64),
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]);
        } catch (\Throwable) {
            // {PREFIX}config_changes absent on pre-v8 installs; fail silently.
        }
    }

    /**
     * Return the most recent entries from the config_changes audit log,
     * newest first. Returns an empty list if the table does not exist.
     *
     * @return list<array{id: string, user_id: string, username: string, ip: string, key: string, old_value: string, new_value: string, changed_at: string}>
     */
    public static function getRecentChanges(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            return LumoraDB::fetchAll(
                'SELECT * FROM `{PREFIX}config_changes` ORDER BY changed_at DESC LIMIT ' . $limit
            );
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Export ────────────────────────────────────────────────────────────────

    /**
     * Export the current installation state as a JSON string.
     *
     * Sensitive values (DB password, full DB_USER) are never included.
     * The export is intended as a quick-reference snapshot; it is not a
     * full backup and cannot be used to restore the installation.
     *
     * @return string JSON-encoded export.
     */
    public static function exportSettings(): string
    {
        $env    = self::detectEnvironment();
        $stored = self::getStoredConfig();

        $export = [
            'exported_at'    => date('c'),
            'lumora_version' => LUMORA_VERSION,
            'db_version'     => LUMORA_DB_VERSION,
            'stored_config'  => [
                'base_url'     => $stored['base_url'],
                'gallery_name' => $stored['gallery_name'],
                'db_host'      => $stored['db_host'],
                'db_name'      => $stored['db_name'],
                'db_prefix'    => $stored['db_prefix'],
                'db_password'  => '*** not exported ***',
            ],
            'environment' => [
                'detected_url' => $env['detected_url'],
                'root_path'    => $env['root_path'],
                'albums_path'  => $env['albums_path'],
                'cache_path'   => $env['cache_path'],
                'php_version'  => $env['php_version'],
                'web_server'   => $env['web_server'],
                'https'        => $env['https'],
                'albums_writable' => is_writable(LUMORA_ALBUMS_PATH),
                'cache_writable'  => is_dir(LUMORA_ROOT . 'cache') && is_writable(LUMORA_ROOT . 'cache'),
            ],
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }
}

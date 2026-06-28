<?php

declare(strict_types=1);
/**
 * Coppermine Config Detector
 *
 * Locates and parses Coppermine's `include/config.inc.php` file(s) from a
 * user-supplied filesystem path. Credentials are extracted by reading the
 * file as plain text with regex — the config file is never `include`d or
 * `eval`'d, so no Coppermine code runs inside Lumora's process.
 *
 * Security requirements enforced throughout:
 *   - Passwords are never passed through exception messages, log entries,
 *     or any debug output. Exception messages carry only path or format
 *     information.
 *   - `realpath()` is used to resolve the caller-supplied path before any
 *     filesystem access, eliminating path-traversal sequences.
 *   - Files larger than MAX_FILE_SIZE are rejected before reading.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
final class CoppermineConfigDetector
{
    /** Relative path from a Coppermine root to its config file. */
    public const CONFIG_REL_PATH = 'include/config.inc.php';

    /**
     * Maximum directory depth scanned when searching for multiple
     * Coppermine installations under a root directory.
     */
    private const SCAN_MAX_DEPTH = 4;

    /**
     * Maximum file size (bytes) accepted for parsing.
     * Protects against accidental reads of very large files.
     */
    private const MAX_FILE_SIZE = 1_048_576; // 1 MiB

    /**
     * Config keys we require to consider a file a valid Coppermine config.
     * `dbpass` may legitimately be an empty string, so it is checked for
     * presence (array_key_exists) rather than non-empty value.
     */
    private const REQUIRED_KEYS = ['dbserver', 'dbname', 'dbuser', 'dbpass', 'TABLE_PREFIX'];

    /**
     * Directories skipped when recursively scanning for Coppermine roots.
     * These names could never contain a separate CPG installation.
     */
    private const SKIP_DIRS = ['albums', 'themes', 'js', 'css', 'images', 'lang', '.git', 'node_modules', '.svn'];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Find all Coppermine `include/config.inc.php` files at or under $root.
     *
     * Search order:
     *   1. If $root is already a readable `config.inc.php` file, return it.
     *   2. If $root is a directory that directly contains `include/config.inc.php`,
     *      return that path (the root IS the CPG installation).
     *   3. Otherwise scan subdirectories up to SCAN_MAX_DEPTH levels deep.
     *
     * Returns an empty list when the path does not exist, is not accessible,
     * or contains no recognisable Coppermine installation.
     *
     * @param  string       $root  Absolute filesystem path to search from.
     * @return list<string>        Absolute paths to every config.inc.php found.
     */
    public static function findInstallations(string $root): array
    {
        $root = self::normalizePath($root);
        if ($root === '') {
            return [];
        }

        // Case 1: caller supplied the config file path directly.
        if (is_file($root) && is_readable($root) && basename($root) === 'config.inc.php') {
            return [$root];
        }

        if (!is_dir($root)) {
            return [];
        }

        // Case 2: supplied path IS the Coppermine root.
        $exact = $root . '/' . self::CONFIG_REL_PATH;
        if (is_file($exact) && is_readable($exact)) {
            return [$exact];
        }

        // Case 3: scan subdirectories for installations.
        return self::scanForConfigs($root, 0);
    }

    /**
     * Parse a Coppermine `include/config.inc.php` and return the five
     * database connection settings.
     *
     * The file is read as plain text; PHP is never executed. Single-line
     * and block comments are stripped before extraction so commented-out
     * values are ignored. Both single-quoted and double-quoted string
     * assignments are understood, with basic backslash-escape handling.
     *
     * @param  string $config_path  Absolute path to the config.inc.php file.
     * @return array{dbserver: string, dbname: string, dbuser: string,
     *               dbpass: string, TABLE_PREFIX: string}
     * @throws \RuntimeException  File not found, unreadable, too large, or
     *                            missing required keys. The message never
     *                            contains credential values.
     */
    public static function parseConfig(string $config_path): array
    {
        $config_path = self::normalizePath($config_path);

        if (!is_file($config_path)) {
            throw new \RuntimeException(
                'Configuration file not found: ' . $config_path
            );
        }

        if (!is_readable($config_path)) {
            throw new \RuntimeException(
                'Configuration file is not readable (check file permissions): ' . $config_path
            );
        }

        $size = filesize($config_path);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(
                'Configuration file is unexpectedly large and was not parsed: ' . $config_path
            );
        }

        $content = file_get_contents($config_path);
        if ($content === false) {
            throw new \RuntimeException(
                'Could not read configuration file: ' . $config_path
            );
        }

        $parsed  = self::extractConfigValues($content);
        $missing = self::getMissingKeys($parsed);

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Configuration file is missing expected Coppermine settings: '
                . implode(', ', $missing)
                . '. The file at "' . $config_path . '" may not be a valid Coppermine config.inc.php.'
            );
        }

        return [
            'dbserver'     => $parsed['dbserver'],
            'dbname'       => $parsed['dbname'],
            'dbuser'       => $parsed['dbuser'],
            'dbpass'       => $parsed['dbpass'],
            'TABLE_PREFIX' => $parsed['TABLE_PREFIX'],
        ];
    }

    /**
     * Quick existence check: does a directory contain the expected
     * `include/config.inc.php` file?
     *
     * Non-destructive — does not read or parse the file.
     *
     * @param  string $root  Absolute path to the candidate Coppermine root.
     */
    public static function hasConfigFile(string $root): bool
    {
        $root = self::normalizePath($root);
        return $root !== '' && is_file($root . '/' . self::CONFIG_REL_PATH);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Recursively scan $dir for `include/config.inc.php` up to $depth levels.
     *
     * Symbolic links are not followed to prevent infinite loops. Directories
     * listed in SKIP_DIRS are not descended into.
     *
     * @return list<string>
     */
    private static function scanForConfigs(string $dir, int $depth): array
    {
        if ($depth >= self::SCAN_MAX_DEPTH) {
            return [];
        }

        $entries = @scandir($dir, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            return [];
        }

        $found = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if (!is_dir($full) || is_link($full)) {
                continue;
            }

            if (in_array($entry, self::SKIP_DIRS, true)) {
                continue;
            }

            // Is this subdirectory a CPG root?
            $candidate = $full . '/' . self::CONFIG_REL_PATH;
            if (is_file($candidate) && is_readable($candidate)) {
                $found[] = $candidate;
            }

            // Recurse into subdirectory (even if it contained a config, there
            // could be nested installations — though uncommon, it's supported).
            foreach (self::scanForConfigs($full, $depth + 1) as $sub) {
                $found[] = $sub;
            }
        }

        return $found;
    }

    /**
     * Extract $CONFIG['key'] = 'value'; assignments from PHP file content.
     *
     * Pre-processing:
     *   1. Strip C-style block comments (slash-star ... star-slash).
     *   2. Strip single-line comments (double-slash ...).
     *
     * Both single-quoted and double-quoted string values are handled.
     * Basic backslash escape sequences are unescaped via stripslashes().
     *
     * @return array<string, string>  key => value
     */
    private static function extractConfigValues(string $content): array
    {
        // Strip block comments
        $content = (string) preg_replace('!/\*.*?\*/!s', '', $content);
        // Strip single-line comments (preserving the newline)
        $content = (string) preg_replace('/^\s*\/\/[^\n]*/m', '', $content);

        // Match: $CONFIG['key'] = 'value'; or $CONFIG['key'] = "value";
        // Group 2: single-quoted value   Group 3: double-quoted value
        $pattern = '/\$CONFIG\[\'([^\']+)\'\]\s*=\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*;/m';

        $result = [];
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return $result;
        }

        foreach ($matches as $m) {
            $key = $m[1];
            // Group 2 = single-quoted value; group 3 = double-quoted value.
            // PHP 8+: non-participating groups return null, not ''. Use ??
            // so we fall through to the group that actually matched.
            $value = $m[3] ?? $m[2] ?? '';
            // Unescape basic PHP backslash sequences (e.g. \' \\ \n)
            $result[$key] = stripslashes($value);
        }

        return $result;
    }

    /**
     * Return the list of required keys absent from $parsed.
     *
     * Note: `dbpass` only needs to be *present* (value may be empty string).
     * All other required keys must be non-empty.
     *
     * @param  array<string, string> $parsed
     * @return list<string>
     */
    private static function getMissingKeys(array $parsed): array
    {
        $missing = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if ($key === 'dbpass') {
                if (!array_key_exists($key, $parsed)) {
                    $missing[] = $key;
                }
            } elseif (!isset($parsed[$key]) || $parsed[$key] === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * Normalize a filesystem path using `realpath()` where the path exists,
     * or by cleaning separators where it does not.
     *
     * Returns '' if $path is empty after trimming.
     */
    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        // Path does not exist yet — normalize separators and strip trailing slash.
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

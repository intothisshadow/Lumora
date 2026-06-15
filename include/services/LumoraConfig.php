<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Configuration Service
 *
 * Static cache for gallery configuration stored in {PREFIX}config.
 * Replaces the module-level $LUMORA_CONFIG global previously declared in
 * include/functions.php.  All access goes through LumoraConfig::get() /
 * LumoraConfig::set(); the legacy lumora_config() / lumora_set_config()
 * free functions in functions.php now delegate here for backward
 * compatibility.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class LumoraConfig
{
    /** @var array<string, string> Runtime config cache. */
    private static array $cache = [];

    /**
     * Load all rows from {PREFIX}config into the in-memory cache.
     * Called once per request by bootstrap.php after the DB connection.
     */
    public static function load(): void
    {
        $rows = LumoraDB::fetchAll('SELECT name, value FROM `{PREFIX}config`');
        foreach ($rows as $row) {
            self::$cache[$row['name']] = $row['value'];
        }
    }

    /**
     * Get a config value from the in-memory cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    /**
     * Persist a config value to the DB and update the in-memory cache.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$cache[$key] = (string) $value;
        LumoraDB::query(
            'INSERT INTO `{PREFIX}config` (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, (string) $value]
        );
    }
}

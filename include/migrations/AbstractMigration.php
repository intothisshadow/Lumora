<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Abstract Migration Base Class
 *
 * All database schema migration classes must extend this class and implement
 * both up() and down(). Subclasses live in include/migrations/ and follow the
 * naming convention Migration{NNNN}_{Description}.php.
 *
 * Helper methods (tableExists, columnExists, indexExists) query
 * INFORMATION_SCHEMA so migration classes can write safe conditional DDL
 * without risk of "table already exists" errors on re-run.
 *
 * AbstractMigration is loaded on demand by SchemaService::runOne() — it is
 * not loaded at bootstrap time.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

abstract class AbstractMigration
{
    /**
     * Apply this migration (schema upgrade).
     * Called by SchemaService when the migration is pending.
     */
    abstract public function up(): void;

    /**
     * Reverse this migration (schema downgrade).
     * Called by SchemaService::rollback() on explicit rollback request.
     * Implement as a no-op and throw \LogicException when the migration
     * cannot safely be reversed.
     */
    abstract public function down(): void;

    // ── Schema inspection helpers ─────────────────────────────────────────────

    /**
     * Return true when the given (un-prefixed) table exists in the current DB.
     * Uses INFORMATION_SCHEMA.TABLES for a reliable cross-engine check.
     *
     * @param string $table Un-prefixed table name, e.g. 'albums'.
     */
    protected function tableExists(string $table): bool
    {
        $full  = LumoraDB::table($table);
        $count = LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$full]
        );
        return ((int) $count) > 0;
    }

    /**
     * Return true when the given column exists on the given (un-prefixed) table.
     *
     * @param string $table  Un-prefixed table name.
     * @param string $column Column name.
     */
    protected function columnExists(string $table, string $column): bool
    {
        $full  = LumoraDB::table($table);
        $count = LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = ?
                AND COLUMN_NAME  = ?',
            [$full, $column]
        );
        return ((int) $count) > 0;
    }

    /**
     * Return true when the given index exists on the given (un-prefixed) table.
     * The PRIMARY KEY has the index name 'PRIMARY'.
     *
     * @param string $table      Un-prefixed table name.
     * @param string $index_name Index name as stored in INFORMATION_SCHEMA.
     */
    protected function indexExists(string $table, string $index_name): bool
    {
        $full  = LumoraDB::table($table);
        $count = LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = ?
                AND INDEX_NAME   = ?',
            [$full, $index_name]
        );
        return ((int) $count) > 0;
    }
}

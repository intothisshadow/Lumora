<?php
declare(strict_types=1);
/**
 * Migration 0001 — Create {PREFIX}migrations tracking table
 *
 * This is the self-bootstrapping first migration. Its up() method creates the
 * table that SchemaService uses to track which migrations have been applied.
 *
 * After up() executes, SchemaService::runOne() inserts this migration's class
 * name into the newly-created table, completing the bootstrap loop cleanly.
 *
 * Rolling back this migration drops the tracking table. SchemaService handles
 * the resulting DELETE failure silently (the table is already gone).
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0001_CreateMigrationsTable extends AbstractMigration
{
    /**
     * Create the {PREFIX}migrations tracking table.
     *
     * Uses CREATE TABLE IF NOT EXISTS so that re-running this migration (e.g.
     * after manually deleting its tracking record) is idempotent and safe.
     */
    public function up(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}migrations` (
                `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration`  varchar(255)    NOT NULL,
                `applied_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY  `migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Drop the {PREFIX}migrations tracking table.
     *
     * After this runs, SchemaService has no record of any applied migrations,
     * so all migrations (including this one) will appear pending on the next
     * status check. Uses DROP TABLE IF EXISTS for idempotency.
     */
    public function down(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query("DROP TABLE IF EXISTS `{$prefix}migrations`");
    }
}

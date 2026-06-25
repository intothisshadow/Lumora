<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0002
 *
 * Creates the {PREFIX}config_changes audit table used by InstallationService
 * to log every configuration change made through the Installation Settings page.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

class Migration0002_CreateConfigChangesTable extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}config_changes` (
              `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`    int UNSIGNED    NOT NULL DEFAULT 0,
              `username`   varchar(50)     NOT NULL DEFAULT '',
              `ip`         varchar(45)     NOT NULL DEFAULT '',
              `key`        varchar(64)     NOT NULL DEFAULT '',
              `old_value`  text            NOT NULL,
              `new_value`  text            NOT NULL,
              `changed_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `key_changed`  (`key`, `changed_at`),
              KEY `user_changed` (`user_id`, `changed_at`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Configuration change audit log (DB version 8)'"
        );
    }

    public function down(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query("DROP TABLE IF EXISTS `{$prefix}config_changes`");
    }
}

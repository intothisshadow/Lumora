-- Lumora Gallery — Database Schema
-- Version: 8
-- Requires: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- {PREFIX} is replaced by the installer with the configured table prefix (default: lum_).
--
-- Tables:
--   {PREFIX}config                  — gallery-wide key/value settings
--   {PREFIX}users                   — admin account (V1 single-user, expandable)
--   {PREFIX}categories              — nested category tree (parent_id = 0 for root)
--   {PREFIX}albums                  — albums; each maps to a sub-folder of albums/
--   {PREFIX}images                  — individual images with dimensions and view counter
--   {PREFIX}log                     — activity log (used when log_mode = 'all'; DB version 2)
--   {PREFIX}remember_tokens         — persistent remember-me split tokens (DB version 3)
--   {PREFIX}online                  — active visitor tracking for Who Is Online (DB version 5)
--   {PREFIX}migration_status        — completed import records per source platform (DB version 6)
--   {PREFIX}migration_log           — import event log written by importer plugins (DB version 6)
--   {PREFIX}password_reset_tokens   — single-use admin password-reset tokens (DB version 7)
--   {PREFIX}config_changes          — configuration change audit log (DB version 8)
--
-- Migration from DB version 7:
--   Run the CREATE TABLE statement for {PREFIX}config_changes below
--   (with your actual prefix):
--
--     CREATE TABLE IF NOT EXISTS `lum_config_changes` (
--       `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
--       `user_id`    int UNSIGNED    NOT NULL DEFAULT 0,
--       `username`   varchar(50)     NOT NULL DEFAULT '',
--       `ip`         varchar(45)     NOT NULL DEFAULT '',
--       `key`        varchar(64)     NOT NULL DEFAULT '',
--       `old_value`  text            NOT NULL,
--       `new_value`  text            NOT NULL,
--       `changed_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
--       PRIMARY KEY (`id`),
--       KEY `key_changed`  (`key`, `changed_at`),
--       KEY `user_changed` (`user_id`, `changed_at`)
--     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
--   Run the CREATE TABLE statement for {PREFIX}password_reset_tokens below
--   (with your actual prefix):
--
--     CREATE TABLE IF NOT EXISTS `lum_password_reset_tokens` (
--       `id`               bigint UNSIGNED NOT NULL AUTO_INCREMENT,
--       `user_id`          int UNSIGNED    NOT NULL,
--       `selector`         varchar(32)     NOT NULL,
--       `hashed_validator` varchar(64)     NOT NULL,
--       `expires_at`       datetime        NOT NULL,
--       `created_at`       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
--       PRIMARY KEY (`id`),
--       UNIQUE KEY `selector`  (`selector`),
--       KEY `user_id`          (`user_id`),
--       KEY `expires_at`       (`expires_at`)
--     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- Migration from DB version 5:
--   Run the CREATE TABLE statements for {PREFIX}migration_status and
--   {PREFIX}migration_log below (with your actual prefix). Also add the
--   filename/title indexes to {PREFIX}images to improve admin image search
--   performance (optional but recommended for galleries with 50 000+ images):
--
--     ALTER TABLE `lum_images`
--       ADD KEY `filename` (`filename`(191)),
--       ADD KEY `title`    (`title`(191));
--
-- Migration from DB version 4:
--   Run the CREATE TABLE statement for {PREFIX}online below (with your actual prefix).
--
-- Migration from DB version 3:
--   ALTER TABLE `{PREFIX}categories`
--     ADD COLUMN `thumb_image_id` int UNSIGNED NOT NULL DEFAULT 0
--       COMMENT 'FK to images.id, 0 = auto-pick first album image';
--
-- Migration from DB version 2:
--   Run the CREATE TABLE statement for {PREFIX}remember_tokens below (with your actual prefix).
--
-- Migration from DB version 1:
--   Run the CREATE TABLE statements for {PREFIX}log and {PREFIX}remember_tokens below.

SET NAMES utf8mb4;

-- ──────────────────────────────────────────────────────────────────────────────
-- config
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}config` (
  `name`  varchar(64)  NOT NULL,
  `value` text         NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Gallery configuration key/value store';

-- ──────────────────────────────────────────────────────────────────────────────
-- users
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}users` (
  `id`            int UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`      varchar(50)      NOT NULL,
  `password_hash` varchar(255)     NOT NULL,
  `email`         varchar(255)     NOT NULL DEFAULT '',
  `role`          enum('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
  `last_login`    datetime         DEFAULT NULL,
  `created_at`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='User accounts';

-- ──────────────────────────────────────────────────────────────────────────────
-- categories
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}categories` (
  `id`             int UNSIGNED  NOT NULL AUTO_INCREMENT,
  `parent_id`      int UNSIGNED  NOT NULL DEFAULT 0,
  `name`           varchar(255)  NOT NULL,
  `description`    text          NOT NULL,
  `pos`            int           NOT NULL DEFAULT 0,
  `thumb_image_id` int UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'FK to images.id, 0 = auto-pick first album image',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `pos`       (`pos`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Category tree';

-- ──────────────────────────────────────────────────────────────────────────────
-- albums
-- ──────────────────────────────────────────────────────────────────────────────
-- folder: path relative to albums/ directory, e.g. "00001" or "xena/season1"
-- thumb_image_id: FK to images.id, used as the album cover (0 = auto-pick first image)
-- visibility: 0 = public, 1 = private/hidden
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}albums` (
  `id`             int UNSIGNED     NOT NULL AUTO_INCREMENT,
  `category_id`    int UNSIGNED     NOT NULL DEFAULT 0,
  `folder`         varchar(100)     NOT NULL,
  `title`          varchar(255)     NOT NULL DEFAULT '',
  `description`    text             NOT NULL,
  `visibility`     tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=public 1=private',
  `pos`            int              NOT NULL DEFAULT 0,
  `hits`           int UNSIGNED     NOT NULL DEFAULT 0,
  `thumb_image_id` int UNSIGNED     NOT NULL DEFAULT 0,
  `created_at`     datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folder`      (`folder`),
  KEY `category_id`        (`category_id`),
  KEY `visibility`         (`visibility`),
  KEY `pos`                (`pos`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Albums';

-- ──────────────────────────────────────────────────────────────────────────────
-- images
-- ──────────────────────────────────────────────────────────────────────────────
-- filename: bare filename, e.g. "photo.jpg"
-- Thumbnail is always LUMORA_THUMB_PREFIX + filename in the same folder.
-- approved: 1 = visible, 0 = hidden/pending
-- filename / title indexes: B-tree prefix indexes supporting admin image search.
--   They speed up album-scoped LIKE queries in combination with the album_approved
--   index. Cross-album LIKE '%term%' searches are still a full table scan; for
--   galleries with 500 000+ images consider adding a FULLTEXT index instead:
--     ALTER TABLE `{PREFIX}images` ADD FULLTEXT KEY `search_text` (`filename`, `title`);
--   and switching GalleryService::searchImages() to MATCH … AGAINST boolean mode.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}images` (
  `id`       int UNSIGNED      NOT NULL AUTO_INCREMENT,
  `album_id` int UNSIGNED      NOT NULL,
  `filename` varchar(255)      NOT NULL,
  `title`    varchar(255)      NOT NULL DEFAULT '',
  `filesize` int UNSIGNED      NOT NULL DEFAULT 0,
  `width`    smallint UNSIGNED NOT NULL DEFAULT 0,
  `height`   smallint UNSIGNED NOT NULL DEFAULT 0,
  `hits`     int UNSIGNED      NOT NULL DEFAULT 0,
  `approved` tinyint UNSIGNED  NOT NULL DEFAULT 1,
  `pos`      int               NOT NULL DEFAULT 0,
  `added_at` datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `album_approved` (`album_id`, `approved`),
  KEY `filename`       (`filename`(191)),
  KEY `title`          (`title`(191)),
  KEY `hits`           (`hits`),
  KEY `added_at`       (`added_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Images';

-- ──────────────────────────────────────────────────────────────────────────────
-- log  (DB version 2)
-- ──────────────────────────────────────────────────────────────────────────────
-- Only used when log_mode = 'all' in gallery configuration.
-- type: 'visit' | 'error' | 'info'
-- ip:   IPv4 or IPv6 address of the client (up to 45 chars for IPv6).
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}log` (
  `id`         bigint UNSIGNED  NOT NULL AUTO_INCREMENT,
  `type`       varchar(16)      NOT NULL COMMENT 'visit, error, info',
  `message`    text             NOT NULL,
  `ip`         varchar(45)      NOT NULL DEFAULT '',
  `created_at` datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `type_created` (`type`, `created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Activity log (used when log_mode = all)';

-- ──────────────────────────────────────────────────────────────────────────────
-- remember_tokens  (DB version 3)
-- ──────────────────────────────────────────────────────────────────────────────
-- Split-token persistent "Remember Me" scheme.
-- selector:         32-char hex (16 random bytes); stored plain; used for lookup.
-- hashed_validator: 64-char hex; SHA-256 of the 64-char validator in the cookie.
--                   Never stored in plain form — only the browser cookie holds it.
-- expires_at:       When the token (and the browser cookie) expire.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}remember_tokens` (
  `id`               bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          int UNSIGNED    NOT NULL,
  `selector`         varchar(32)     NOT NULL,
  `hashed_validator` varchar(64)     NOT NULL,
  `expires_at`       datetime        NOT NULL,
  `created_at`       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector`  (`selector`),
  KEY `user_id`          (`user_id`),
  KEY `expires_at`       (`expires_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Persistent remember-me tokens (DB version 3)';

-- ──────────────────────────────────────────────────────────────────────────────
-- online  (DB version 5)
-- ──────────────────────────────────────────────────────────────────────────────
-- Tracks active visitors for the "Who Is Online" feature.
-- ip:          IPv4 or IPv6 address (up to 45 chars for IPv6).
-- last_action: Updated on every public page load; stale rows are purged by
--             lumora_track_visitor() after `who_is_online_duration` minutes.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}online` (
  `ip`          varchar(45)  NOT NULL,
  `last_action` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Active visitor tracking for Who Is Online (DB version 5)';

-- ──────────────────────────────────────────────────────────────────────────────
-- migration_status  (DB version 6)
-- ──────────────────────────────────────────────────────────────────────────────
-- One row per source platform; records the outcome of a completed import.
-- source:         Short identifier for the originating platform ('coppermine', …).
-- imported_at:    Timestamp of the most recent completed import.
-- categories/albums/images: Record counts imported.
-- plugin_version: Version of the importer plugin that performed the import.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}migration_status` (
  `source`         varchar(64)  NOT NULL,
  `imported_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `categories`     int UNSIGNED NOT NULL DEFAULT 0,
  `albums`         int UNSIGNED NOT NULL DEFAULT 0,
  `images`         int UNSIGNED NOT NULL DEFAULT 0,
  `plugin_version` varchar(32)  NOT NULL DEFAULT '',
  PRIMARY KEY (`source`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Records completed gallery migration status per source platform (DB version 6)';

-- ──────────────────────────────────────────────────────────────────────────────
-- migration_log  (DB version 6)
-- ──────────────────────────────────────────────────────────────────────────────
-- Append-only event log written by importer plugins during and after import.
-- level: 'info' | 'warning' | 'error'
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}migration_log` (
  `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`     varchar(64)     NOT NULL,
  `level`      varchar(16)     NOT NULL DEFAULT 'info',
  `message`    text            NOT NULL,
  `created_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `source_level`   (`source`, `level`),
  KEY `source_created` (`source`, `created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Migration event log written by importer plugins (DB version 6)';

-- ──────────────────────────────────────────────────────────────────────────────
-- password_reset_tokens  (DB version 7)
-- ──────────────────────────────────────────────────────────────────────────────
-- Single-use admin password-reset tokens (split-token scheme, same as
-- remember_tokens). Used by admin/forgot_password.php and
-- admin/reset_password.php.
-- selector:         32-char hex (16 random bytes); stored plain; used for lookup.
-- hashed_validator: 64-char hex; SHA-256 of the 64-char validator in the URL.
--                   Never stored in plain form.
-- expires_at:       1 hour from creation time.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}password_reset_tokens` (
  `id`               bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          int UNSIGNED    NOT NULL,
  `selector`         varchar(32)     NOT NULL,
  `hashed_validator` varchar(64)     NOT NULL,
  `expires_at`       datetime        NOT NULL,
  `created_at`       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector`  (`selector`),
  KEY `user_id`          (`user_id`),
  KEY `expires_at`       (`expires_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use admin password-reset split tokens (DB version 7)';

-- ──────────────────────────────────────────────────────────────────────────────
-- config_changes  (DB version 8)
-- ──────────────────────────────────────────────────────────────────────────────
-- Audit log for configuration changes applied via the Installation Settings tool
-- (admin/installation.php). One row per individual setting change.
-- user_id / username: the administrator who made the change.
-- ip:                 the request IP address at the time of the change.
-- key:                config key name (e.g. 'base_url').
-- old_value / new_value: the previous and new values of that key.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{PREFIX}config_changes` (
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
  COMMENT='Configuration change audit log (DB version 8)';


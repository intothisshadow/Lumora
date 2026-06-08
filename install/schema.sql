-- Lumora Gallery — Database Schema
-- Version: 1
-- Requires: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- {PREFIX} is replaced by the installer with the configured table prefix (default: lum_).
--
-- Tables:
--   {PREFIX}config      — gallery-wide key/value settings
--   {PREFIX}users       — admin account (V1 single-user, expandable)
--   {PREFIX}categories  — nested category tree (parent_id = 0 for root)
--   {PREFIX}albums      — albums; each maps to a sub-folder of albums/
--   {PREFIX}images      — individual images with dimensions and view counter

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
  `id`          int UNSIGNED  NOT NULL AUTO_INCREMENT,
  `parent_id`   int UNSIGNED  NOT NULL DEFAULT 0,
  `name`        varchar(255)  NOT NULL,
  `description` text          NOT NULL,
  `pos`         int           NOT NULL DEFAULT 0,
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
  KEY `hits`           (`hits`),
  KEY `added_at`       (`added_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Images';

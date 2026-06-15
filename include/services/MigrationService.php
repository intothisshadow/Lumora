<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration Service
 *
 * Core framework for gallery migration and import plugins.
 * Provides import status tracking, migration logging, and plugin discovery.
 *
 * This service is intentionally minimal: it tracks what was imported and
 * provides hooks for importer plugins. All source-specific logic belongs in
 * the individual importer plugin, not here.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

final class MigrationService
{
    // Standard source identifiers
    public const SOURCE_COPPERMINE = 'coppermine';
    public const SOURCE_PIWIGO     = 'piwigo';
    public const SOURCE_ZENPHOTO   = 'zenphoto';
    public const SOURCE_GALLERY3   = 'gallery3';

    // Log levels
    public const LOG_INFO    = 'info';
    public const LOG_WARNING = 'warning';
    public const LOG_ERROR   = 'error';

    // ── Status tracking ───────────────────────────────────────────────────────

    /**
     * Get the recorded migration status for a source platform.
     *
     * @param  string $source  Source platform identifier (e.g. 'coppermine')
     * @return array{source: string, imported_at: string, categories: int, albums: int,
     *               images: int, plugin_version: string}|null
     */
    public static function getMigrationStatus(string $source): array|null
    {
        try {
            $pdo  = LumoraDB::pdo();
            $pre  = LumoraDB::prefix();
            $stmt = $pdo->prepare(
                "SELECT `source`, `imported_at`, `categories`, `albums`, `images`, `plugin_version`
                   FROM `{$pre}migration_status`
                  WHERE `source` = ?
                  LIMIT 1"
            );
            $stmt->execute([$source]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Save (or update) migration status after a completed import.
     *
     * @param string $source         Source platform identifier
     * @param array{categories: int, albums: int, images: int} $counts
     * @param string $plugin_version Version of the importer plugin used
     */
    public static function saveMigrationStatus(
        string $source,
        array  $counts,
        string $plugin_version
    ): void {
        try {
            $pdo = LumoraDB::pdo();
            $pre = LumoraDB::prefix();
            $pdo->prepare(
                "INSERT INTO `{$pre}migration_status`
                     (`source`, `imported_at`, `categories`, `albums`, `images`, `plugin_version`)
                 VALUES (?, NOW(), ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     `imported_at`    = NOW(),
                     `categories`     = VALUES(`categories`),
                     `albums`         = VALUES(`albums`),
                     `images`         = VALUES(`images`),
                     `plugin_version` = VALUES(`plugin_version`)"
            )->execute([
                $source,
                (int) ($counts['categories'] ?? 0),
                (int) ($counts['albums']     ?? 0),
                (int) ($counts['images']     ?? 0),
                $plugin_version,
            ]);
        } catch (\Throwable) {
            // Silently fail on pre-v6 installs that have not run the migration
        }
    }

    /**
     * Delete a migration status record.
     * Use only to prepare for a deliberate re-import.
     */
    public static function clearMigrationStatus(string $source): void
    {
        try {
            $pdo = LumoraDB::pdo();
            $pre = LumoraDB::prefix();
            $pdo->prepare(
                "DELETE FROM `{$pre}migration_status` WHERE `source` = ?"
            )->execute([$source]);
        } catch (\Throwable) {
        }
    }

    /**
     * Return true if a source has a recorded migration status.
     */
    public static function isImported(string $source): bool
    {
        return self::getMigrationStatus($source) !== null;
    }

    /**
     * Return all recorded migration statuses.
     *
     * @return list<array{source: string, imported_at: string, categories: int,
     *                     albums: int, images: int, plugin_version: string}>
     */
    public static function getAllStatuses(): array
    {
        try {
            $pdo  = LumoraDB::pdo();
            $pre  = LumoraDB::prefix();
            $stmt = $pdo->query(
                "SELECT `source`, `imported_at`, `categories`, `albums`, `images`, `plugin_version`
                   FROM `{$pre}migration_status`
                  ORDER BY `imported_at` DESC"
            );
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Migration logging ─────────────────────────────────────────────────────

    /**
     * Write a migration log entry.
     *
     * @param string $source  Source platform identifier
     * @param string $level   MigrationService::LOG_* constant
     * @param string $message Log message
     */
    public static function logEvent(string $source, string $level, string $message): void
    {
        try {
            $pdo = LumoraDB::pdo();
            $pre = LumoraDB::prefix();
            $pdo->prepare(
                "INSERT INTO `{$pre}migration_log` (`source`, `level`, `message`)
                 VALUES (?, ?, ?)"
            )->execute([$source, $level, $message]);
        } catch (\Throwable) {
        }
    }

    /**
     * Retrieve recent log entries for a source (newest first).
     *
     * @param  string $source  Source platform identifier
     * @param  int    $limit   Maximum rows to return
     * @return list<array{id: int, source: string, level: string, message: string, created_at: string}>
     */
    public static function getLogs(string $source, int $limit = 200): array
    {
        try {
            $pdo  = LumoraDB::pdo();
            $pre  = LumoraDB::prefix();
            $stmt = $pdo->prepare(
                "SELECT `id`, `source`, `level`, `message`, `created_at`
                   FROM `{$pre}migration_log`
                  WHERE `source` = ?
                  ORDER BY `id` DESC
                  LIMIT ?"
            );
            $stmt->execute([$source, $limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Delete all log entries for a source.
     */
    public static function clearLogs(string $source): void
    {
        try {
            $pdo = LumoraDB::pdo();
            $pre = LumoraDB::prefix();
            $pdo->prepare(
                "DELETE FROM `{$pre}migration_log` WHERE `source` = ?"
            )->execute([$source]);
        } catch (\Throwable) {
        }
    }

    // ── Plugin discovery ──────────────────────────────────────────────────────

    /**
     * Scan LUMORA_PLUGINS_PATH for installed importer plugins.
     *
     * Each plugin must contain a `plugin.json` manifest with at minimum:
     *   { "id": "...", "type": "importer", "name": "...", "version": "...",
     *     "description": "...", "admin_url": "...", "source": "..." }
     *
     * @return list<array{id: string, name: string, type: string, version: string,
     *                     min_lumora: string, description: string, author: string,
     *                     admin_url: string, source: string, manifest_path: string}>
     */
    public static function discoverImporters(): array
    {
        if (!defined('LUMORA_PLUGINS_PATH') || !is_dir(LUMORA_PLUGINS_PATH)) {
            return [];
        }

        $importers = [];

        foreach (glob(LUMORA_PLUGINS_PATH . '*/plugin.json') ?: [] as $manifest_path) {
            try {
                $json = file_get_contents($manifest_path);
                if ($json === false) {
                    continue;
                }
                $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
                if (!is_array($data) || ($data['type'] ?? '') !== 'importer') {
                    continue;
                }
                // Normalise optional fields
                $data += [
                    'min_lumora'    => '1.0.0',
                    'author'        => '',
                    'admin_url'     => '',
                    'source'        => $data['id'] ?? '',
                ];
                $data['manifest_path'] = $manifest_path;
                $importers[]           = $data;
            } catch (\Throwable) {
                // Skip malformed or unreadable manifests
            }
        }

        return $importers;
    }

    /**
     * Compare two semantic version strings.
     * Returns -1, 0, or 1 (same semantics as spaceship operator).
     */
    public static function compareVersions(string $a, string $b): int
    {
        return version_compare($a, $b);
    }

    /**
     * Return true when $plugin_min_lumora ≤ LUMORA_VERSION.
     */
    public static function isCompatible(string $plugin_min_lumora): bool
    {
        return version_compare(LUMORA_VERSION, $plugin_min_lumora, '>=');
    }
}

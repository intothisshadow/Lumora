<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Schema Service
 *
 * Database schema migration engine. Discovers, orders, and executes versioned
 * PHP migration classes stored in include/migrations/.
 *
 * This class is distinct from MigrationService, which tracks gallery data
 * imports from external platforms (Coppermine, Piwigo, etc.). SchemaService
 * manages Lumora's own schema changes between application versions.
 *
 * Migration file convention:
 *   include/migrations/Migration{NNNN}_{Description}.php
 *   e.g. Migration0001_CreateMigrationsTable.php
 *
 * Each file must contain a class of the same name that extends AbstractMigration
 * (include/migrations/AbstractMigration.php) and implements up() and down().
 *
 * Item 12 (Dashboard Update System) calls SchemaService::runPendingMigrations()
 * as one step of its orchestration — this is the stable public contract.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class SchemaService
{
    /** Per-request cache for pending migrations list (reset after a run). */
    private static ?array $pending_cache = null;

    // ── Path ─────────────────────────────────────────────────────────────────

    /** Absolute path to the migrations directory, with trailing separator. */
    private static function migrationsDir(): string
    {
        return LUMORA_INCLUDE . 'migrations' . DIRECTORY_SEPARATOR;
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    /**
     * Discover all migration class files in include/migrations/, sorted so
     * that the numeric prefix (0001, 0002, …) determines execution order.
     *
     * Files matching AbstractMigration.php or anything without the expected
     * Migration{NNNN}_{Word} pattern are silently skipped.
     *
     * @return list<string> Class names (filename without .php extension).
     */
    public static function discoverMigrations(): array
    {
        $dir = self::migrationsDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . 'Migration[0-9][0-9][0-9][0-9]_*.php');
        if ($files === false || empty($files)) {
            return [];
        }

        $classes = [];
        foreach ($files as $path) {
            $filename = basename($path, '.php');
            if (preg_match('/^Migration\d{4}_[A-Za-z0-9_]+$/', $filename)) {
                $classes[] = $filename;
            }
        }

        sort($classes); // Alphabetical sort preserves numeric-prefix order.
        return $classes;
    }

    /**
     * Return applied migration class names from the {PREFIX}migrations tracking
     * table. Returns an empty array when the table does not yet exist
     * (pre-Migration0001 state).
     *
     * @return list<string>
     */
    public static function getAppliedMigrations(): array
    {
        try {
            $rows = LumoraDB::fetchAll(
                'SELECT `migration` FROM `{PREFIX}migrations` ORDER BY `id` ASC'
            );
            return array_column($rows, 'migration');
        } catch (\Throwable) {
            // {PREFIX}migrations absent before Migration0001 has run.
            return [];
        }
    }

    /**
     * Return pending migration class names (discovered but not applied), in the
     * order they should be executed. Result is cached per request.
     *
     * @return list<string>
     */
    public static function getPendingMigrations(): array
    {
        if (self::$pending_cache === null) {
            $all                 = self::discoverMigrations();
            $applied             = self::getAppliedMigrations();
            self::$pending_cache = array_values(array_diff($all, $applied));
        }
        return self::$pending_cache;
    }

    /**
     * Return true when at least one migration is pending.
     * Used by the admin nav badge and dashboard notice (no extra DB call once
     * the pending list is cached).
     */
    public static function hasPendingMigrations(): bool
    {
        return !empty(self::getPendingMigrations());
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Run all pending migrations in ascending numeric order.
     *
     * Execution stops at the first failure to avoid leaving the schema in a
     * partially-updated state. Remaining pending migrations are untouched and
     * can be retried after the failure is resolved.
     *
     * Note on transactions: MariaDB/InnoDB DDL statements (CREATE TABLE, ALTER
     * TABLE, etc.) issue an implicit commit. Each migration's up() method is
     * responsible for any explicit transaction management it requires for DML
     * operations. This method does not wrap individual migrations in its own
     * outer transaction.
     *
     * This is the method Item 12 (Dashboard Update System) will call as one of
     * its orchestration steps.
     *
     * @return array{applied: list<string>, errors: list<string>}
     */
    public static function runPendingMigrations(): array
    {
        // Clear request cache so callers get a fresh pending list after the run.
        self::$pending_cache = null;
        $pending             = self::getPendingMigrations();
        self::$pending_cache = null; // Clear again; getPending() re-populated it.

        $applied = [];
        $errors  = [];

        foreach ($pending as $class_name) {
            $error = self::runOne($class_name);
            if ($error === null) {
                $applied[] = $class_name;
                lumora_log('info', "SchemaService: applied {$class_name}");
            } else {
                $errors[] = "{$class_name}: {$error}";
                lumora_log('error', "SchemaService: {$class_name} failed — {$error}");
                break; // Stop on first failure.
            }
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    /**
     * Load and execute a single migration's up() method, then record it in
     * {PREFIX}migrations.
     *
     * For Migration0001 the INSERT succeeds because up() has just created the
     * tracking table — that is the self-bootstrapping design.
     *
     * Returns null on success, or an error message string on failure.
     */
    private static function runOne(string $class_name): ?string
    {
        // Validate class name before using it in a filesystem path.
        if (!preg_match('/^Migration\d{4}_[A-Za-z0-9_]+$/', $class_name)) {
            return "Invalid migration class name: {$class_name}";
        }

        $dir  = self::migrationsDir();
        $path = $dir . $class_name . '.php';

        if (!is_file($path)) {
            return "Migration file not found: {$class_name}.php";
        }

        // AbstractMigration must be loaded before the migration subclass.
        require_once $dir . 'AbstractMigration.php';
        require_once $path;

        if (!class_exists($class_name)) {
            return "Class {$class_name} not found in migration file.";
        }

        $migration = new $class_name();

        if (!($migration instanceof AbstractMigration)) {
            return "Class {$class_name} does not extend AbstractMigration.";
        }

        try {
            $migration->up();

            // Record as applied. For Migration0001 this succeeds because up()
            // just created {PREFIX}migrations.
            LumoraDB::query(
                'INSERT IGNORE INTO `{PREFIX}migrations` (`migration`, `applied_at`)
                 VALUES (?, NOW())',
                [$class_name]
            );

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Roll back a single migration by class name.
     *
     * Calls the migration's down() method and removes its record from the
     * tracking table. Returns true on success, false on any failure.
     *
     * Rolling back Migration0001 drops the {PREFIX}migrations table, so the
     * subsequent DELETE of the tracking record will fail — this is expected
     * and handled silently.
     */
    public static function rollback(string $class_name): bool
    {
        if (!preg_match('/^Migration\d{4}_[A-Za-z0-9_]+$/', $class_name)) {
            return false;
        }

        $dir  = self::migrationsDir();
        $path = $dir . $class_name . '.php';

        if (!is_file($path)) {
            lumora_log('error', "SchemaService: rollback failed — file not found: {$class_name}.php");
            return false;
        }

        require_once $dir . 'AbstractMigration.php';
        require_once $path;

        if (!class_exists($class_name)) {
            return false;
        }

        $migration = new $class_name();
        if (!($migration instanceof AbstractMigration)) {
            return false;
        }

        try {
            $migration->down();

            // Remove the tracking record. Silently ignored if down() dropped
            // {PREFIX}migrations (the Migration0001 rollback edge case).
            try {
                LumoraDB::query(
                    'DELETE FROM `{PREFIX}migrations` WHERE `migration` = ?',
                    [$class_name]
                );
            } catch (\Throwable) {}

            self::$pending_cache = null;
            lumora_log('info', "SchemaService: rolled back {$class_name}");
            return true;
        } catch (\Throwable $e) {
            lumora_log('error', "SchemaService: rollback of {$class_name} failed — " . $e->getMessage());
            return false;
        }
    }

    // ── Status ────────────────────────────────────────────────────────────────

    /**
     * Return the full migration status for display in the admin UI.
     *
     * @return array{applied: list<string>, pending: list<string>}
     */
    public static function getMigrationStatus(): array
    {
        return [
            'applied' => self::getAppliedMigrations(),
            'pending' => self::getPendingMigrations(),
        ];
    }
}

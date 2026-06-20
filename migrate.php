<?php
declare(strict_types=1);
/**
 * Lumora Gallery — CLI Migration Runner
 *
 * Command-line entry point for running database schema migrations.
 * Must be executed via PHP CLI only; web requests are rejected with HTTP 403.
 *
 * Usage:
 *   php migrate.php                        — apply all pending migrations
 *   php migrate.php --dry-run              — list pending migrations, no changes
 *   php migrate.php --status               — show applied and pending migrations
 *   php migrate.php --rollback <ClassName> — roll back one migration by class name
 *
 * Example:
 *   php migrate.php --rollback Migration0001_CreateMigrationsTable
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.' . PHP_EOL);
}

define('LUMORA_ENTRY', true);
require_once __DIR__ . '/include/bootstrap.php';

// ── Argument parsing ──────────────────────────────────────────────────────────

$args    = array_slice($argv ?? [], 1);
$dry_run = in_array('--dry-run', $args, true);
$status  = in_array('--status',  $args, true);

$rollback     = null;
$rollback_idx = array_search('--rollback', $args, true);
if ($rollback_idx !== false && isset($args[$rollback_idx + 1])) {
    $rollback = $args[$rollback_idx + 1];
}

// ── Status ────────────────────────────────────────────────────────────────────

if ($status) {
    $mig  = SchemaService::getMigrationStatus();
    $line = str_repeat('─', 54);
    echo PHP_EOL . "Lumora Schema Migrations" . PHP_EOL . $line . PHP_EOL;
    echo 'Applied (' . count($mig['applied']) . '):' . PHP_EOL;
    if (empty($mig['applied'])) {
        echo '  (none)' . PHP_EOL;
    } else {
        foreach ($mig['applied'] as $m) {
            echo "  [x] {$m}" . PHP_EOL;
        }
    }
    echo 'Pending (' . count($mig['pending']) . '):' . PHP_EOL;
    if (empty($mig['pending'])) {
        echo '  (none)' . PHP_EOL;
    } else {
        foreach ($mig['pending'] as $m) {
            echo "  [ ] {$m}" . PHP_EOL;
        }
    }
    echo PHP_EOL;
    exit(0);
}

// ── Rollback ──────────────────────────────────────────────────────────────────

if ($rollback !== null) {
    echo PHP_EOL . "Rolling back: {$rollback}" . PHP_EOL;
    $ok = SchemaService::rollback($rollback);
    if ($ok) {
        echo '✓ Rolled back successfully.' . PHP_EOL . PHP_EOL;
        exit(0);
    } else {
        echo '✗ Rollback failed. Check the application log for details.' . PHP_EOL . PHP_EOL;
        exit(1);
    }
}

// ── Dry run ───────────────────────────────────────────────────────────────────

$pending = SchemaService::getPendingMigrations();

if ($dry_run) {
    echo PHP_EOL . 'Pending migrations (' . count($pending) . '):' . PHP_EOL;
    if (empty($pending)) {
        echo '  (none — database is up to date)' . PHP_EOL;
    } else {
        foreach ($pending as $m) {
            echo "  [ ] {$m}" . PHP_EOL;
        }
    }
    echo PHP_EOL;
    exit(0);
}

// ── Apply all pending migrations ──────────────────────────────────────────────

if (empty($pending)) {
    echo PHP_EOL . '✓ Database is up to date — no migrations to apply.' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo PHP_EOL . 'Applying ' . count($pending) . ' pending migration(s)…' . PHP_EOL;

$result = SchemaService::runPendingMigrations();

foreach ($result['applied'] as $m) {
    echo "  [✓] {$m}" . PHP_EOL;
}

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $err) {
        echo "  [✗] {$err}" . PHP_EOL;
    }
    echo PHP_EOL . '✗ Migration failed. '
        . count($result['applied'])
        . ' applied before failure.' . PHP_EOL . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '✓ ' . count($result['applied'])
    . ' migration(s) applied successfully.' . PHP_EOL . PHP_EOL;
exit(0);

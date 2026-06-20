<?php
declare(strict_types=1);
/**
 * Coppermine Importer — AJAX Import Handler
 *
 * Processes one chunk per call. All state (credentials, ID maps, progress
 * counters) is stored in $_SESSION['lumora_cpg_import'].
 *
 * Expected POST fields:
 *   action      — 'import_categories' | 'import_albums' | 'import_images' | 'apply_covers' | 'finish'
 *   csrf_token  — Standard Lumora CSRF token
 *
 * Returns JSON.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);

$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/CoppermineImporter.php';

lumora_require_admin();

// ── JSON output helpers ───────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

function cpg_json_ok(array $data): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function cpg_json_error(string $message, int $http = 400): never
{
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSRF + session ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpg_json_error('POST required', 405);
}

// lumora_csrf_validate() is void — calling it inside !() always evaluates true.
// Use an inline boolean check so CSRF failures return JSON, not plain text.
if (!isset($_POST['csrf_token']) || !hash_equals(lumora_csrf_token(), $_POST['csrf_token'])) {
    cpg_json_error('CSRF token invalid', 403);
}

$sess_key = 'lumora_cpg_import';
$sess     = &$_SESSION[$sess_key];

if (!is_array($sess) || empty($sess['db_host'])) {
    cpg_json_error('Session expired. Please restart the import.', 403);
}

// Session timeout guard: abandon sessions older than 2 hours
if (!empty($sess['started_at']) && (time() - (int) $sess['started_at']) > 7200) {
    unset($_SESSION[$sess_key]);
    cpg_json_error('Import session timed out (2 h). Please restart.', 403);
}

$action = trim((string) ($_POST['action'] ?? ''));

// ── Build importer ────────────────────────────────────────────────────────────

$importer = new CoppermineImporter(
    $sess['db_host'],
    $sess['db_name'],
    $sess['db_user'],
    $sess['db_pass'],
    $sess['db_prefix']
);

try {
    $importer->connect();
} catch (\Throwable $e) {
    cpg_json_error('Could not connect to Coppermine database: ' . $e->getMessage(), 500);
}

set_time_limit(300);

// ── Actions ───────────────────────────────────────────────────────────────────

// Wrap the entire switch in try/catch so any uncaught exception from an importer
// method surfaces as a readable JSON error rather than a blank HTTP 500.
try {
    switch ($action) {

        // ── Import categories ─────────────────────────────────────────────────
        case 'import_categories':

            $result = $importer->importCategories(
                (int) ($sess['cat_last_id'] ?? 0),
                LUMORA_CPG_IMPORTER_CAT_CHUNK,
                $sess['cat_id_map'] ?? []
            );

            // Use + operator, not array_merge() — array_merge() re-indexes integer
            // keys, corrupting every CPG cid → Lumora id lookup on subsequent chunks.
            $sess['cat_id_map']             = ($sess['cat_id_map'] ?? []) + ($result['id_map'] ?? []);
            $sess['cat_last_id']            = $result['last_id'];
            $sess['imported']['categories'] = ($sess['imported']['categories'] ?? 0) + $result['imported'];

            foreach (array_slice($result['errors'], 0, 20) as $err) {
                MigrationService::logEvent(LUMORA_CPG_IMPORTER_SOURCE, MigrationService::LOG_WARNING, $err);
            }

            cpg_json_ok([
                'imported'       => $result['imported'],
                'skipped'        => $result['skipped'],
                'errors'         => $result['errors'],
                'done'           => $result['done'],
                'last_id'        => $result['last_id'],
                'total_imported' => $sess['imported']['categories'],
            ]);

        // ── Import albums ─────────────────────────────────────────────────────
        case 'import_albums':

            $result = $importer->importAlbums(
                (int) ($sess['album_last_id'] ?? 0),
                LUMORA_CPG_IMPORTER_ALB_CHUNK,
                $sess['cat_id_map'] ?? []
            );

            $sess['album_id_map']        = ($sess['album_id_map'] ?? []) + ($result['id_map'] ?? []);
            $sess['album_last_id']       = $result['last_id'];
            $sess['imported']['albums']  = ($sess['imported']['albums'] ?? 0) + $result['imported'];

            foreach (array_slice($result['errors'], 0, 20) as $err) {
                MigrationService::logEvent(LUMORA_CPG_IMPORTER_SOURCE, MigrationService::LOG_WARNING, $err);
            }

            cpg_json_ok([
                'imported'       => $result['imported'],
                'skipped'        => $result['skipped'],
                'errors'         => $result['errors'],
                'done'           => $result['done'],
                'last_id'        => $result['last_id'],
                'total_imported' => $sess['imported']['albums'],
            ]);

        // ── Apply cover images ──────────────────────────────────────────────────
        case 'apply_covers':

            try {
                $result = $importer->importCovers(
                    $sess['cat_id_map']   ?? [],
                    $sess['album_id_map'] ?? []
                );
            } catch (\Throwable $e) {
                // Cover failure must not abort the import — log and return gracefully.
                MigrationService::logEvent(
                    LUMORA_CPG_IMPORTER_SOURCE,
                    MigrationService::LOG_WARNING,
                    'Cover image assignment failed: ' . $e->getMessage()
                );
                cpg_json_ok([
                    'updated'  => 0,
                    'skipped'  => 0,
                    'warnings' => ['Cover assignment error: ' . $e->getMessage()],
                ]);
            }

            foreach (array_slice($result['warnings'], 0, 30) as $w) {
                MigrationService::logEvent(
                    LUMORA_CPG_IMPORTER_SOURCE,
                    MigrationService::LOG_WARNING,
                    $w
                );
            }

            if ($result['updated'] > 0 || $result['skipped'] > 0 || !empty($result['warnings'])) {
                MigrationService::logEvent(
                    LUMORA_CPG_IMPORTER_SOURCE,
                    MigrationService::LOG_INFO,
                    sprintf(
                        'Cover images: %d assigned, %d skipped, %d warning(s)',
                        $result['updated'],
                        $result['skipped'],
                        count($result['warnings'])
                    )
                );
            }

            cpg_json_ok([
                'updated'  => $result['updated'],
                'skipped'  => $result['skipped'],
                'warnings' => $result['warnings'],
            ]);

        // ── Import images ─────────────────────────────────────────────────────
        case 'import_images':

            $result = $importer->importImages(
                (int) ($sess['img_last_id'] ?? 0),
                LUMORA_CPG_IMPORTER_IMG_CHUNK,
                $sess['album_id_map'] ?? []
            );

            $sess['img_last_id']         = $result['last_id'];
            $sess['imported']['images']  = ($sess['imported']['images'] ?? 0) + $result['imported'];

            foreach (array_slice($result['errors'], 0, 20) as $err) {
                $level = str_contains($err, 'not found')
                    ? MigrationService::LOG_WARNING
                    : MigrationService::LOG_ERROR;
                MigrationService::logEvent(LUMORA_CPG_IMPORTER_SOURCE, $level, $err);
            }

            cpg_json_ok([
                'imported'       => $result['imported'],
                'skipped'        => $result['skipped'],
                'missing_files'  => $result['missing_files'],
                'errors'         => $result['errors'],
                'done'           => $result['done'],
                'last_id'        => $result['last_id'],
                'total_imported' => $sess['imported']['images'],
            ]);

        // ── Finish ────────────────────────────────────────────────────────────
        case 'finish':

            $imported = $sess['imported'] ?? ['categories' => 0, 'albums' => 0, 'images' => 0];

            MigrationService::saveMigrationStatus(
                LUMORA_CPG_IMPORTER_SOURCE,
                $imported,
                LUMORA_CPG_IMPORTER_VERSION
            );

            MigrationService::logEvent(
                LUMORA_CPG_IMPORTER_SOURCE,
                MigrationService::LOG_INFO,
                sprintf(
                    'Import completed: %d categories, %d albums, %d images (plugin v%s)',
                    $imported['categories'],
                    $imported['albums'],
                    $imported['images'],
                    LUMORA_CPG_IMPORTER_VERSION
                )
            );

            unset($_SESSION[$sess_key]);

            cpg_json_ok(['imported' => $imported]);

        default:
            cpg_json_error('Unknown action: ' . $action, 400);
    }
} catch (\Throwable $e) {
    cpg_json_error('Import error: ' . $e->getMessage(), 500);
}

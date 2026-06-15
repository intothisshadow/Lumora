<?php
declare(strict_types=1);
/**
 * Coppermine Importer — Core Importer Class
 *
 * Reads from a Coppermine Gallery database (CPG 1.4–1.6) and writes to Lumora
 * using prepared statements against a separate PDO connection.
 *
 * This class contains only Coppermine-specific mapping logic.
 * All Lumora writes use the Lumora PDO connection via LumoraDB::pdo().
 *
 * File migration philosophy:
 *   Images are NOT moved. The caller is expected to copy or symlink the
 *   Coppermine albums/ directory into Lumora's albums/ directory before
 *   running the import. The importer validates file presence and reports
 *   any missing originals or thumbnails.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

final class CoppermineImporter
{
    private \PDO   $cpg;         // Coppermine PDO connection
    private string $cpg_prefix;  // Coppermine table prefix (e.g. 'cpg78_')

    public function __construct(
        private readonly string $host,
        private readonly string $db_name,
        private readonly string $db_user,
        private readonly string $db_pass,
        string                  $prefix
    ) {
        $this->cpg_prefix = rtrim($prefix, '_') . '_';
    }

    // ── Connection ────────────────────────────────────────────────────────────

    /**
     * Open the Coppermine database connection.
     *
     * @throws \RuntimeException on connection failure
     */
    public function connect(): void
    {
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->db_name . ';charset=utf8mb4';
        $this->cpg = new \PDO($dsn, $this->db_user, $this->db_pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function disconnect(): void
    {
        unset($this->cpg);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Test the connection and confirm the expected tables exist.
     *
     * @return array{ok: bool, categories: int, albums: int, images: int,
     *               cpg_version: string, error?: string}
     */
    public function validate(): array
    {
        try {
            $this->connect();
            $counts = $this->getCounts();

            // Try to detect Coppermine version from config table
            $cpg_version = '?';
            try {
                $stmt = $this->cpg->prepare(
                    "SELECT `value` FROM `{$this->cpg_prefix}config` WHERE `name` = 'last_updates_check' LIMIT 1"
                );
                $stmt->execute();
                // Use gallery_name as a proxy; detect by presence of known config keys
                $stmt = $this->cpg->prepare(
                    "SELECT COUNT(*) FROM `{$this->cpg_prefix}config`"
                );
                $stmt->execute();
                $cfg_count = (int) $stmt->fetchColumn();
                if ($cfg_count > 0) {
                    $cpg_version = 'detected';
                }
            } catch (\Throwable) {
            }

            return array_merge($counts, ['ok' => true, 'cpg_version' => $cpg_version]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'categories' => 0, 'albums' => 0, 'images' => 0,
                    'cpg_version' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve total record counts from Coppermine.
     *
     * @return array{categories: int, albums: int, images: int}
     */
    public function getCounts(): array
    {
        $cat_count = (int) $this->cpg->query(
            "SELECT COUNT(*) FROM `{$this->cpg_prefix}categories` WHERE `parent` != -1"
        )->fetchColumn();

        $alb_count = (int) $this->cpg->query(
            "SELECT COUNT(*) FROM `{$this->cpg_prefix}albums` WHERE `category` > 0"
        )->fetchColumn();

        $img_count = (int) $this->cpg->query(
            "SELECT COUNT(*) FROM `{$this->cpg_prefix}pictures`"
        )->fetchColumn();

        return ['categories' => $cat_count, 'albums' => $alb_count, 'images' => $img_count];
    }

    // ── Category import ───────────────────────────────────────────────────────

    /**
     * Import a chunk of categories using keyset pagination.
     *
     * Returns a partial id_map for this chunk. The caller must merge it into
     * the full cat_id_map stored in the PHP session.
     *
     * @param  int              $last_id   Last Coppermine cid processed (0 = start)
     * @param  int              $limit     Chunk size
     * @param  array<int, int>  $cat_id_map  Accumulated CPG cid → Lumora cat_id (for parent resolution)
     * @return array{imported: int, skipped: int, errors: list<string>,
     *               done: bool, last_id: int, id_map: array<int, int>}
     */
    public function importCategories(int $last_id, int $limit, array $cat_id_map): array
    {
        $stmt = $this->cpg->prepare(
            "SELECT `cid`, `name`, `description`, `pos`, `parent`
               FROM `{$this->cpg_prefix}categories`
              WHERE `cid` > ? AND `parent` != -1
              ORDER BY `cid` ASC
              LIMIT ?"
        );
        $stmt->execute([$last_id, $limit]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => [], 'done' => true,
                    'last_id' => $last_id, 'id_map' => []];
        }

        $lumora_pdo = LumoraDB::pdo();
        $pre        = LumoraDB::prefix();

        $insert = $lumora_pdo->prepare(
            "INSERT INTO `{$pre}categories` (`parent_id`, `name`, `description`, `pos`, `thumb_image_id`)
             VALUES (?, ?, ?, ?, 0)"
        );

        $imported  = 0;
        $skipped   = 0;
        $errors    = [];
        $id_map    = [];
        $new_last  = $last_id;

        foreach ($rows as $row) {
            $cpg_cid   = (int) $row['cid'];
            $new_last  = max($new_last, $cpg_cid);

            $name        = $this->decodeCpgText((string) ($row['name']        ?? ''));
            $description = $this->decodeCpgText((string) ($row['description'] ?? ''));
            $pos         = (int) ($row['pos'] ?? 0);
            $cpg_parent  = (int) ($row['parent'] ?? 0);

            // Map CPG parent cid to Lumora parent_id; root categories (parent=0) stay at 0
            $lumora_parent = 0;
            if ($cpg_parent > 0) {
                $lumora_parent = $cat_id_map[$cpg_parent] ?? ($id_map[$cpg_parent] ?? 0);
            }

            if ($name === '') {
                $skipped++;
                $errors[] = "Category cid={$cpg_cid}: empty name, skipped.";
                continue;
            }

            try {
                $insert->execute([$lumora_parent, $name, $description, $pos]);
                $lumora_cid    = (int) $lumora_pdo->lastInsertId();
                $id_map[$cpg_cid] = $lumora_cid;
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Category cid={$cpg_cid} '{$name}': " . $e->getMessage();
            }
        }

        $done = count($rows) < $limit;

        return compact('imported', 'skipped', 'errors', 'done', 'id_map') + ['last_id' => $new_last];
    }

    // ── Album import ──────────────────────────────────────────────────────────

    /**
     * Import a chunk of albums using keyset pagination.
     *
     * Albums are skipped if their CPG category has no mapping in $cat_id_map,
     * meaning the category was excluded or the category chunk hasn't run yet.
     * Run all category chunks before starting albums.
     *
     * @param  int              $last_id      Last CPG aid processed (0 = start)
     * @param  int              $limit        Chunk size
     * @param  array<int, int>  $cat_id_map   Full CPG cid → Lumora cat_id map
     * @return array{imported: int, skipped: int, errors: list<string>,
     *               done: bool, last_id: int, id_map: array<int, int>}
     */
    public function importAlbums(int $last_id, int $limit, array $cat_id_map): array
    {
        $stmt = $this->cpg->prepare(
            "SELECT `aid`, `title`, `description`, `visibility`, `pos`, `category`, `keyword`, `alb_hits`
               FROM `{$this->cpg_prefix}albums`
              WHERE `aid` > ? AND `category` > 0
              ORDER BY `aid` ASC
              LIMIT ?"
        );
        $stmt->execute([$last_id, $limit]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => [], 'done' => true,
                    'last_id' => $last_id, 'id_map' => []];
        }

        $lumora_pdo = LumoraDB::pdo();
        $pre        = LumoraDB::prefix();

        $insert = $lumora_pdo->prepare(
            "INSERT INTO `{$pre}albums`
                 (`category_id`, `folder`, `title`, `description`, `visibility`, `pos`, `hits`, `thumb_image_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)"
        );

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $id_map   = [];
        $new_last = $last_id;

        foreach ($rows as $row) {
            $cpg_aid  = (int) $row['aid'];
            $new_last = max($new_last, $cpg_aid);

            $cpg_cat    = (int) ($row['category'] ?? 0);
            $lumora_cat = $cat_id_map[$cpg_cat] ?? 0;

            if ($lumora_cat === 0) {
                $skipped++;
                $errors[] = "Album aid={$cpg_aid}: CPG category {$cpg_cat} not in category map, skipped.";
                continue;
            }

            $title       = $this->decodeCpgText((string) ($row['title']       ?? ''));
            $description = $this->decodeCpgText((string) ($row['description'] ?? ''));
            $pos         = (int) ($row['pos']         ?? 0);
            $hits        = (int) ($row['alb_hits']    ?? 0);
            $visibility  = (int) ($row['visibility']  ?? 0) > 0 ? 1 : 0;
            $folder      = $this->resolveCpgAlbumFolder($cpg_aid, (string) ($row['keyword'] ?? ''));

            if ($title === '') {
                $title = $folder; // fallback: use folder name as title
            }

            // Ensure folder name is unique in Lumora (append _cpg{aid} if collision)
            $folder = $this->ensureUniqueFolder($lumora_pdo, $pre, $folder, $cpg_aid);

            try {
                $insert->execute([$lumora_cat, $folder, $title, $description, $visibility, $pos, $hits]);
                $lumora_aid    = (int) $lumora_pdo->lastInsertId();
                $id_map[$cpg_aid] = $lumora_aid;
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Album aid={$cpg_aid} '{$title}': " . $e->getMessage();
            }
        }

        $done = count($rows) < $limit;

        return compact('imported', 'skipped', 'errors', 'done', 'id_map') + ['last_id' => $new_last];
    }

    // ── Image import ──────────────────────────────────────────────────────────

    /**
     * Import a chunk of image records using keyset pagination.
     *
     * Files are expected to already exist in Lumora's albums/ directory at the
     * same relative path as in Coppermine. The importer records missing files
     * in the error list but still creates the DB record so the import can
     * later be reconciled using the Tools → File Integrity Check.
     *
     * @param  int              $last_id      Last CPG pid processed (0 = start)
     * @param  int              $limit        Chunk size
     * @param  array<int, int>  $album_id_map Full CPG aid → Lumora album_id map
     * @return array{imported: int, skipped: int, missing_files: int, errors: list<string>,
     *               done: bool, last_id: int}
     */
    public function importImages(int $last_id, int $limit, array $album_id_map): array
    {
        $stmt = $this->cpg->prepare(
            "SELECT `pid`, `aid`, `filepath`, `filename`, `filesize`, `width`, `height`,
                    `hits`, `approved`, `pos`, `title`, `caption`, `added`
               FROM `{$this->cpg_prefix}pictures`
              WHERE `pid` > ?
              ORDER BY `pid` ASC
              LIMIT ?"
        );
        $stmt->execute([$last_id, $limit]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return ['imported' => 0, 'skipped' => 0, 'missing_files' => 0,
                    'errors' => [], 'done' => true, 'last_id' => $last_id];
        }

        $lumora_pdo = LumoraDB::pdo();
        $pre        = LumoraDB::prefix();

        // Prefetch Lumora album folders for all aids in this chunk to minimise queries
        $cpg_aids    = array_unique(array_column($rows, 'aid'));
        $folder_map  = $this->fetchLumoraFolders($lumora_pdo, $pre, $cpg_aids, $album_id_map);

        $insert = $lumora_pdo->prepare(
            "INSERT INTO `{$pre}images`
                 (`album_id`, `filename`, `title`, `filesize`, `width`, `height`, `hits`, `approved`, `pos`, `added_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $imported      = 0;
        $skipped       = 0;
        $missing_files = 0;
        $errors        = [];
        $new_last      = $last_id;

        foreach ($rows as $row) {
            $cpg_pid  = (int) $row['pid'];
            $new_last = max($new_last, $cpg_pid);
            $cpg_aid  = (int) $row['aid'];

            $lumora_album_id = $album_id_map[$cpg_aid] ?? 0;
            if ($lumora_album_id === 0) {
                $skipped++;
                $errors[] = "Image pid={$cpg_pid}: CPG album {$cpg_aid} not in album map, skipped.";
                continue;
            }

            $filename = basename((string) ($row['filename'] ?? ''));
            if ($filename === '' || $filename === '.' || $filename === '..') {
                $skipped++;
                $errors[] = "Image pid={$cpg_pid}: invalid filename, skipped.";
                continue;
            }

            $folder   = $folder_map[$lumora_album_id] ?? null;
            if ($folder !== null) {
                $file_path  = LUMORA_ALBUMS_PATH . $folder . DIRECTORY_SEPARATOR . $filename;
                $thumb_path = LUMORA_ALBUMS_PATH . $folder . DIRECTORY_SEPARATOR . LUMORA_THUMB_PREFIX . $filename;
                if (!is_file($file_path)) {
                    $missing_files++;
                    $errors[] = "Image pid={$cpg_pid}: original not found at albums/{$folder}/{$filename}";
                } elseif (!is_file($thumb_path)) {
                    $missing_files++;
                    $errors[] = "Image pid={$cpg_pid}: thumbnail not found at albums/{$folder}/" . LUMORA_THUMB_PREFIX . "{$filename}";
                }
                // Still import the DB record even if files are missing;
                // admin can reconcile with File Integrity Check.
            }

            $title    = $this->decodeCpgText((string) ($row['title']    ?? ''));
            $filesize = (int) ($row['filesize'] ?? 0);
            $width    = (int) ($row['width']    ?? 0);
            $height   = (int) ($row['height']   ?? 0);
            $hits     = (int) ($row['hits']     ?? 0);
            $approved = $this->normalizeApproved($row['approved'] ?? 'YES');
            $pos      = (int) ($row['pos']      ?? 0);
            $added_at = $this->normalizeDate($row['added'] ?? null);

            // Prefer title; fall back to caption if title is blank
            if ($title === '') {
                $title = $this->decodeCpgText((string) ($row['caption'] ?? ''));
            }

            try {
                $insert->execute([
                    $lumora_album_id, $filename, $title, $filesize,
                    $width, $height, $hits, $approved, $pos, $added_at,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Image pid={$cpg_pid} '{$filename}': " . $e->getMessage();
            }
        }

        $done = count($rows) < $limit;

        return compact('imported', 'skipped', 'missing_files', 'errors', 'done') + ['last_id' => $new_last];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the Lumora album folder name from a Coppermine album record.
     *
     * Coppermine logic:
     *   - Albums with a non-empty keyword use the keyword as the folder path.
     *     e.g. keyword='xena/season1' → folder='xena/season1'
     *   - Albums with an empty/null keyword use a 5-digit zero-padded aid.
     *     e.g. aid=1 → folder='00001'
     */
    private function resolveCpgAlbumFolder(int $aid, string $keyword): string
    {
        $kw = trim($keyword, " \t\n\r/\\");
        if ($kw !== '') {
            // Sanitise lightly: strip any remaining leading dots or absolute-path markers
            $kw = ltrim($kw, './\\');
            return $kw !== '' ? $kw : sprintf('%05d', $aid);
        }
        return sprintf('%05d', $aid);
    }

    /**
     * Ensure a folder name is unique in Lumora albums table.
     * If a collision exists, appends _cpg{$aid}.
     */
    private function ensureUniqueFolder(\PDO $pdo, string $pre, string $folder, int $cpg_aid): string
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$pre}albums` WHERE `folder` = ?");
        $stmt->execute([$folder]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $folder;
        }
        return $folder . '_cpg' . $cpg_aid;
    }

    /**
     * Fetch Lumora album folders for a set of Lumora album IDs.
     *
     * @param  list<int>        $cpg_aids
     * @param  array<int, int>  $album_id_map   CPG aid → Lumora album_id
     * @return array<int, string>  Lumora album_id → folder
     */
    private function fetchLumoraFolders(\PDO $pdo, string $pre, array $cpg_aids, array $album_id_map): array
    {
        $lumora_ids = [];
        foreach ($cpg_aids as $aid) {
            $lid = $album_id_map[(int) $aid] ?? 0;
            if ($lid > 0) {
                $lumora_ids[] = $lid;
            }
        }
        if (empty($lumora_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($lumora_ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT `id`, `folder` FROM `{$pre}albums` WHERE `id` IN ({$placeholders})"
        );
        $stmt->execute($lumora_ids);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = (string) $row['folder'];
        }
        return $result;
    }

    /**
     * Decode Coppermine HTML-entity-encoded text to plain UTF-8.
     * Coppermine stores titles/descriptions with HTML entities (e.g. &#039;).
     */
    private function decodeCpgText(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Normalise Coppermine's `approved` field to an integer 0 or 1.
     *
     * CPG 1.4.x stores ENUM('YES','NO'); some versions use tinyint 0/1.
     */
    private function normalizeApproved(mixed $value): int
    {
        if (is_string($value)) {
            return strtoupper($value) === 'YES' ? 1 : 0;
        }
        return (int) $value === 0 ? 0 : 1;
    }

    /**
     * Normalise Coppermine's `added` field to a MySQL datetime string.
     *
     * CPG 1.5+ stores datetime; older versions may store a Unix timestamp int.
     */
    private function normalizeDate(mixed $value): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return date('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }
        // Already a datetime string — pass through
        return (string) $value;
    }
}

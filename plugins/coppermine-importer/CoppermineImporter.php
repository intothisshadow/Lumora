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
 * Schema compatibility:
 *   CPG installations upgraded in-place over many years often have column
 *   names that differ from the canonical schema. importImages() uses
 *   getPictureColumns() + buildPictureSelect() to query INFORMATION_SCHEMA
 *   once per request and build a SELECT that aliases any renamed columns
 *   (pwidth/pheight → width/height, ctime → added) so the foreach always
 *   reads the same keys regardless of the actual schema.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

final class CoppermineImporter
{
    private \PDO    $cpg;                        // Coppermine PDO connection
    private string  $cpg_prefix;                 // e.g. 'cpg78_'
    private ?array  $picture_columns = null;     // cached INFORMATION_SCHEMA result

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
            $counts      = $this->getCounts();
            $cpg_version = 'unknown';
            try {
                $stmt = $this->cpg->prepare(
                    "SELECT COUNT(*) FROM `{$this->cpg_prefix}config`"
                );
                $stmt->execute();
                if ((int) $stmt->fetchColumn() > 0) {
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
     * @param  int              $last_id     Last CPG cid processed (0 = start)
     * @param  int              $limit       Chunk size
     * @param  array<int, int>  $cat_id_map  Accumulated CPG cid → Lumora cat_id
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

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $id_map   = [];
        $new_last = $last_id;

        foreach ($rows as $row) {
            $cpg_cid  = (int) $row['cid'];
            $new_last = max($new_last, $cpg_cid);

            $name        = $this->decodeCpgText((string) ($row['name']        ?? ''));
            $description = $this->decodeCpgText((string) ($row['description'] ?? ''));
            $pos         = (int) ($row['pos']    ?? 0);
            $cpg_parent  = (int) ($row['parent'] ?? 0);

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
                $lumora_cid       = (int) $lumora_pdo->lastInsertId();
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
     * Folder name resolution priority:
     *   1. The actual filepath recorded in cpg_pictures for that album (most
     *      accurate — reflects the real on-disk directory even when the keyword
     *      column is empty, wrong, or was changed after upload).
     *   2. resolveCpgAlbumFolder() from the keyword column (fallback for albums
     *      that have no images yet).
     *
     * @param  int              $last_id     Last CPG aid processed (0 = start)
     * @param  int              $limit       Chunk size
     * @param  array<int, int>  $cat_id_map  Full CPG cid → Lumora cat_id map
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

        // Fetch actual on-disk paths from cpg_pictures for all aids in this chunk.
        // This is more reliable than the keyword column, which may be empty or stale.
        $chunk_aids    = array_map(static fn($r) => (int) $r['aid'], $rows);
        $filepath_map  = $this->fetchCpgAlbumFilepaths($chunk_aids);

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
            $pos         = (int) ($row['pos']      ?? 0);
            $hits        = (int) ($row['alb_hits'] ?? 0);
            $visibility  = (int) ($row['visibility'] ?? 0) > 0 ? 1 : 0;

            // Primary: use filepath from pictures table (reflects actual on-disk path).
            // Fallback: derive from keyword column for albums with no images yet.
            $folder = $filepath_map[$cpg_aid]
                ?? $this->resolveCpgAlbumFolder($cpg_aid, (string) ($row['keyword'] ?? ''));

            if ($title === '') {
                $title = $folder;
            }

            $folder = $this->ensureUniqueFolder($lumora_pdo, $pre, $folder, $cpg_aid);

            try {
                $insert->execute([$lumora_cat, $folder, $title, $description, $visibility, $pos, $hits]);
                $lumora_aid       = (int) $lumora_pdo->lastInsertId();
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
     * The SELECT is built dynamically via buildPictureSelect() to handle CPG
     * installations where column names differ from the canonical schema
     * (e.g. pwidth/pheight instead of width/height, ctime instead of added).
     * filepath is intentionally excluded — the album folder is resolved via
     * the album_id_map + Lumora albums table, not from the pictures row.
     *
     * @param  int              $last_id      Last CPG pid processed (0 = start)
     * @param  int              $limit        Chunk size
     * @param  array<int, int>  $album_id_map Full CPG aid → Lumora album_id map
     * @return array{imported: int, skipped: int, missing_files: int, errors: list<string>,
     *               done: bool, last_id: int}
     */
    public function importImages(int $last_id, int $limit, array $album_id_map): array
    {
        $select = $this->buildPictureSelect();

        $stmt = $this->cpg->prepare(
            "SELECT {$select}
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

        $cpg_aids   = array_unique(array_column($rows, 'aid'));
        $folder_map = $this->fetchLumoraFolders($lumora_pdo, $pre, $cpg_aids, $album_id_map);

        $insert = $lumora_pdo->prepare(
            "INSERT INTO `{$pre}images`
                 (`album_id`, `filename`, `title`, `filesize`, `width`, `height`,
                  `hits`, `approved`, `pos`, `added_at`)
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

            $folder = $folder_map[$lumora_album_id] ?? null;
            if ($folder !== null) {
                $file_path  = LUMORA_ALBUMS_PATH . $folder . DIRECTORY_SEPARATOR . $filename;
                $thumb_path = LUMORA_ALBUMS_PATH . $folder . DIRECTORY_SEPARATOR . LUMORA_THUMB_PREFIX . $filename;
                if (!is_file($file_path)) {
                    $missing_files++;
                    $errors[] = "Image pid={$cpg_pid}: original not found at albums/{$folder}/{$filename}";
                } elseif (!is_file($thumb_path)) {
                    $missing_files++;
                    $errors[] = "Image pid={$cpg_pid}: thumbnail not found at albums/{$folder}/"
                        . LUMORA_THUMB_PREFIX . $filename;
                }
            }

            $title    = $this->decodeCpgText((string) ($row['title']    ?? ''));
            $filesize = (int) ($row['filesize'] ?? 0);
            $width    = (int) ($row['width']    ?? 0);
            $height   = (int) ($row['height']   ?? 0);
            $hits     = (int) ($row['hits']     ?? 0);
            $approved = $this->normalizeApproved($row['approved'] ?? 'YES');
            $pos      = (int) ($row['pos']      ?? 0);
            $added_at = $this->normalizeDate($row['added']       ?? null);

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

    // ── Cover image import ────────────────────────────────────────────────────
    //
    // Integrated into the main import wizard. Called from ajax_import.php action
    // 'apply_covers' after all images have been imported and both ID maps are
    // fully populated in session. Uses the exact CPG→Lumora ID maps built during
    // the import, which is more reliable than the folder/name-path matching the
    // standalone Metadata Sync tool uses — no filesystem probing required.

    /**
     * Assign album and category cover images from Coppermine to Lumora.
     *
     * Reads cpg_albums.thumb and cpg_categories.thumb (CPG picture IDs) and
     * resolves each to a Lumora image_id via:
     *   pid → (aid, filename) → Lumora album_id → Lumora image row.
     *
     * All Lumora writes are wrapped in a single transaction; individual row
     * failures are caught per-row so one bad reference never aborts the batch.
     * Missing covers fall through to Lumora's automatic cover selection (the
     * existing thumb_image_id = 0 auto-pick behaviour).
     *
     * @param array<int, int> $cat_id_map   CPG cid → Lumora category_id
     * @param array<int, int> $album_id_map CPG aid → Lumora album_id
     * @return array{updated: int, skipped: int, warnings: list<string>}
     */
    public function importCovers(array $cat_id_map, array $album_id_map): array
    {
        $updated  = 0;
        $skipped  = 0;
        $warnings = [];

        // ── Fetch CPG album cover pids ─────────────────────────────────────
        try {
            $alb_rows = $this->cpg->query(
                "SELECT `aid`, `thumb`
                   FROM `{$this->cpg_prefix}albums`
                  WHERE `category` > 0 AND `thumb` > 0"
            )->fetchAll();
        } catch (\Throwable $e) {
            $warnings[] = 'Could not read album covers from Coppermine: ' . $e->getMessage();
            return ['updated' => 0, 'skipped' => 0, 'warnings' => $warnings];
        }

        // ── Fetch CPG category cover pids ──────────────────────────────────
        try {
            $cat_rows = $this->cpg->query(
                "SELECT `cid`, `thumb`
                   FROM `{$this->cpg_prefix}categories`
                  WHERE `parent` != -1 AND `thumb` > 0"
            )->fetchAll();
        } catch (\Throwable $e) {
            $warnings[] = 'Could not read category covers from Coppermine: ' . $e->getMessage();
            return ['updated' => 0, 'skipped' => 0, 'warnings' => $warnings];
        }

        if (empty($alb_rows) && empty($cat_rows)) {
            return ['updated' => 0, 'skipped' => 0, 'warnings' => []];
        }

        // ── Batch-resolve all referenced thumb pids to (aid, filename) ─────
        $pids = [];
        foreach ($alb_rows as $r) { $pids[] = (int) $r['thumb']; }
        foreach ($cat_rows  as $r) { $pids[] = (int) $r['thumb']; }

        $picture_info = $this->fetchCpgPictureInfo($pids);

        // ── Pid → Lumora image_id resolution closure ───────────────────────
        // Maps one CPG thumb pid to its Lumora image_id using the import maps.
        // Returns null and appends a warning string on any lookup failure.
        $resolve_pid = function (int $pid, string $ctx) use (
            $picture_info, $album_id_map, &$warnings
        ): ?int {
            $info = $picture_info[$pid] ?? null;
            if ($info === null) {
                $warnings[] = "{$ctx}: cover pid={$pid} not found in CPG pictures, skipped.";
                return null;
            }

            $lumora_aid = $album_id_map[$info['aid']] ?? 0;
            if ($lumora_aid === 0) {
                $warnings[] = "{$ctx}: cover pid={$pid} belongs to CPG aid={$info['aid']}"
                    . ' which was not imported, skipped.';
                return null;
            }

            $img_id = LumoraDB::fetchValue(
                'SELECT `id` FROM `{PREFIX}images`'
                . ' WHERE `album_id` = ? AND `filename` = ? LIMIT 1',
                [$lumora_aid, $info['filename']]
            );

            if ($img_id === null) {
                $warnings[] = "{$ctx}: cover filename '{$info['filename']}'"
                    . " not found in Lumora album id={$lumora_aid}, skipped.";
                return null;
            }

            return (int) $img_id;
        };

        // ── Apply all updates inside one transaction ───────────────────────
        LumoraDB::beginTransaction();
        try {
            foreach ($alb_rows as $row) {
                $cpg_aid    = (int) $row['aid'];
                $pid        = (int) $row['thumb'];
                $lumora_aid = $album_id_map[$cpg_aid] ?? 0;

                if ($lumora_aid === 0) {
                    $skipped++;
                    $warnings[] = "Album aid={$cpg_aid}: not in import map, cover skipped.";
                    continue;
                }

                $image_id = $resolve_pid($pid, "Album aid={$cpg_aid}");
                if ($image_id === null) {
                    $skipped++;
                    continue;
                }

                try {
                    LumoraDB::update(
                        'albums',
                        ['thumb_image_id' => $image_id],
                        'id = ?',
                        [$lumora_aid]
                    );
                    $updated++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $warnings[] = "Album aid={$cpg_aid}: DB update failed — " . $e->getMessage();
                }
            }

            foreach ($cat_rows as $row) {
                $cpg_cid    = (int) $row['cid'];
                $pid        = (int) $row['thumb'];
                $lumora_cid = $cat_id_map[$cpg_cid] ?? 0;

                if ($lumora_cid === 0) {
                    $skipped++;
                    $warnings[] = "Category cid={$cpg_cid}: not in import map, cover skipped.";
                    continue;
                }

                $image_id = $resolve_pid($pid, "Category cid={$cpg_cid}");
                if ($image_id === null) {
                    $skipped++;
                    continue;
                }

                try {
                    LumoraDB::update(
                        'categories',
                        ['thumb_image_id' => $image_id],
                        'id = ?',
                        [$lumora_cid]
                    );
                    $updated++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $warnings[] = "Category cid={$cpg_cid}: DB update failed — " . $e->getMessage();
                }
            }

            LumoraDB::commit();
        } catch (\Throwable $e) {
            LumoraDB::rollBack();
            throw $e;
        }

        return compact('updated', 'skipped', 'warnings');
    }

    // ── Metadata sync (category/album cover thumbnails) ────────────────────────
    //
    // Companion to the import methods above, used by the standalone metadata
    // sync tool (admin/sync_metadata.php) to fill in category/album cover
    // images on an *already-imported* gallery, without a full re-import.
    //
    // The import methods above build their CPG-id -> Lumora-id maps only in
    // memory for the lifetime of one import run; nothing is persisted. So
    // matching here is done via durable, already-on-disk identifiers instead:
    //   - Albums:     matched by `folder`, resolved the same way importAlbums()
    //                 resolves it (cpg_pictures.filepath, falling back to the
    //                 keyword column).
    //   - Categories: matched by full name-path from the root, since categories
    //                 have no folder equivalent to match on.

    /**
     * Build a preview of category and album cover-thumbnail sync actions.
     * Read-only — makes no changes to either database.
     *
     * @return array{
     *     categories: list<array{cpg_cid: int, name: string, cpg_thumb_pid: int,
     *         lumora_id: int|null, current_thumb_image_id: int,
     *         resolved_image_id: int|null, status: string}>,
     *     albums: list<array{cpg_aid: int, title: string, folder: string,
     *         cpg_thumb_pid: int, lumora_id: int|null, current_thumb_image_id: int,
     *         resolved_image_id: int|null, status: string}>
     * }
     */
    public function previewThumbnailSync(): array
    {
        return [
            'categories' => $this->matchCategoryThumbnails(),
            'albums'     => $this->matchAlbumThumbnails(),
        ];
    }

    /**
     * Apply the thumbnail sync actions computed by previewThumbnailSync().
     *
     * Re-runs the same matching logic fresh rather than trusting any
     * client-supplied preview state, inside a single Lumora-side transaction.
     * By default only categories/albums with thumb_image_id = 0 are touched;
     * pass $overwrite = true to replace existing cover selections as well.
     *
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function applyThumbnailSync(bool $overwrite): array
    {
        $preview = $this->previewThumbnailSync();
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        LumoraDB::beginTransaction();
        try {
            foreach ($preview['categories'] as $row) {
                if ($row['lumora_id'] === null || $row['resolved_image_id'] === null) {
                    $skipped++;
                    continue;
                }
                if ($row['current_thumb_image_id'] > 0 && !$overwrite) {
                    $skipped++;
                    continue;
                }
                try {
                    LumoraDB::update(
                        'categories',
                        ['thumb_image_id' => $row['resolved_image_id']],
                        'id = ?',
                        [$row['lumora_id']]
                    );
                    $updated++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Category '{$row['name']}': " . $e->getMessage();
                }
            }
            foreach ($preview['albums'] as $row) {
                if ($row['lumora_id'] === null || $row['resolved_image_id'] === null) {
                    $skipped++;
                    continue;
                }
                if ($row['current_thumb_image_id'] > 0 && !$overwrite) {
                    $skipped++;
                    continue;
                }
                try {
                    LumoraDB::update(
                        'albums',
                        ['thumb_image_id' => $row['resolved_image_id']],
                        'id = ?',
                        [$row['lumora_id']]
                    );
                    $updated++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Album '{$row['title']}': " . $e->getMessage();
                }
            }
            LumoraDB::commit();
        } catch (\Throwable $e) {
            LumoraDB::rollBack();
            throw $e;
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Fetch the actual on-disk folder path for a set of CPG album IDs by reading
     * the filepath column from cpg_pictures.
     *
     * cpg_pictures.filepath is the ground truth for what directory CPG wrote
     * each image into (e.g. 'albums/Season1/Screencaps/1x01-TheHedgeKnight/').
     * This is more reliable than cpg_albums.keyword, which may be empty, stale,
     * or differ from the filesystem after manual moves.
     *
     * The 'albums/' prefix (CPG's fullpath config, always 'albums/') and trailing
     * slash are stripped so the result matches Lumora's folder format.
     *
     * Returns [] silently if the filepath column does not exist on this CPG install.
     *
     * @param  list<int>        $aids  CPG album IDs to look up
     * @return array<int, string>  CPG aid → folder string
     */
    private function fetchCpgAlbumFilepaths(array $aids): array
    {
        if (empty($aids)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($aids), '?'));
            $stmt = $this->cpg->prepare(
                "SELECT `aid`, MIN(`filepath`) AS `filepath`
                   FROM `{$this->cpg_prefix}pictures`
                  WHERE `aid` IN ({$placeholders})
                  GROUP BY `aid`"
            );
            $stmt->execute($aids);
            $result = [];
            foreach ($stmt->fetchAll() as $row) {
                $fp = trim((string) ($row['filepath'] ?? ''), " \t\n\r/\\");
                // Strip leading 'albums/' prefix that CPG prepends to all filepaths
                $fp = (string) preg_replace('#^albums/#i', '', $fp);
                $fp = trim($fp, '/');
                if ($fp !== '') {
                    $result[(int) $row['aid']] = $fp;
                }
            }
            return $result;
        } catch (\Throwable) {
            // filepath column absent on some CPG variants — degrade to keyword fallback
            return [];
        }
    }

    /**
     * Query INFORMATION_SCHEMA to get all column names for the pictures table.
     * Result is cached on the instance for the lifetime of a single AJAX request.
     *
     * @return list<string>
     */
    private function getPictureColumns(): array
    {
        if ($this->picture_columns !== null) {
            return $this->picture_columns;
        }
        try {
            $stmt = $this->cpg->prepare(
                "SELECT `COLUMN_NAME`
                   FROM `INFORMATION_SCHEMA`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE()
                    AND `TABLE_NAME`   = ?
                  ORDER BY `ORDINAL_POSITION`"
            );
            $stmt->execute([$this->cpg_prefix . 'pictures']);
            $this->picture_columns = array_column($stmt->fetchAll(), 'COLUMN_NAME');
        } catch (\Throwable) {
            $this->picture_columns = [];
        }
        return $this->picture_columns;
    }

    /**
     * Build a SELECT clause for cpg_pictures that works across CPG schema variants.
     *
     * Known column name variations handled:
     *   - width / height   → may be pwidth / pheight in CPG 1.6.29+
     *   - added            → may be ctime in some older forks
     *   - pos, caption     → may be absent entirely after an incomplete upgrade
     *
     * All selected columns are aliased to their canonical names so the foreach
     * in importImages() always reads $row['width'], $row['added'], etc.
     *
     * filepath is intentionally omitted — album folder is resolved via
     * the album_id_map + Lumora albums table.
     */
    private function buildPictureSelect(): string
    {
        $cols = $this->getPictureColumns();
        $has  = static fn(string $c): bool => in_array($c, $cols, true);

        $parts = ['`pid`', '`aid`', '`filename`'];

        $parts[] = $has('filesize') ? '`filesize`'        : '0 AS `filesize`';

        // Dimensions
        if ($has('width'))        { $parts[] = '`width`'; }
        elseif ($has('pwidth'))   { $parts[] = '`pwidth`  AS `width`'; }
        else                      { $parts[] = '0 AS `width`'; }

        if ($has('height'))       { $parts[] = '`height`'; }
        elseif ($has('pheight'))  { $parts[] = '`pheight` AS `height`'; }
        else                      { $parts[] = '0 AS `height`'; }

        $parts[] = $has('hits')     ? '`hits`'              : '0 AS `hits`';
        $parts[] = $has('approved') ? '`approved`'          : "'YES' AS `approved`";
        $parts[] = $has('pos')      ? '`pos`'               : '0 AS `pos`';
        $parts[] = $has('title')    ? '`title`'             : "'' AS `title`";
        $parts[] = $has('caption')  ? '`caption`'           : "'' AS `caption`";

        // Timestamp
        if ($has('added'))        { $parts[] = '`added`'; }
        elseif ($has('ctime'))    { $parts[] = '`ctime` AS `added`'; }
        else                      { $parts[] = 'NULL AS `added`'; }

        return implode(', ', $parts);
    }

    /**
     * Resolve the Lumora album folder name from a Coppermine album record.
     * Used as fallback when no images exist yet for the album in cpg_pictures.
     *
     * CPG logic:
     *   - Non-empty keyword → use as folder path (e.g. 'xena/season1')
     *   - Empty keyword     → zero-padded aid (e.g. aid=1 → '00001')
     */
    private function resolveCpgAlbumFolder(int $aid, string $keyword): string
    {
        $kw = ltrim(trim($keyword, " \t\n\r/\\"), './\\');
        return $kw !== '' ? $kw : sprintf('%05d', $aid);
    }

    /**
     * Return $folder unchanged if it is unique in Lumora's albums table,
     * otherwise append _cpg{aid} to avoid a collision.
     */
    private function ensureUniqueFolder(\PDO $pdo, string $pre, string $folder, int $cpg_aid): string
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$pre}albums` WHERE `folder` = ?");
        $stmt->execute([$folder]);
        return (int) $stmt->fetchColumn() === 0 ? $folder : $folder . '_cpg' . $cpg_aid;
    }

    /**
     * Fetch Lumora album folder strings for a set of CPG album IDs.
     *
     * @param  list<int>        $cpg_aids
     * @param  array<int, int>  $album_id_map  CPG aid → Lumora album_id
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
     * CPG stores titles and descriptions with HTML entities (e.g. &#039;).
     */
    private function decodeCpgText(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Normalise CPG's `approved` field to integer 0 or 1.
     * CPG 1.4.x uses ENUM('YES','NO'); later versions use tinyint.
     */
    private function normalizeApproved(mixed $value): int
    {
        if (is_string($value)) {
            return strtoupper($value) === 'YES' ? 1 : 0;
        }
        return (int) $value === 0 ? 0 : 1;
    }

    /**
     * Normalise CPG's `added` / `ctime` field to a MySQL datetime string.
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
        return (string) $value;
    }

    // ── Metadata sync helpers ────────────────────────────────────────────

    /**
     * Match CPG albums that have a custom thumbnail set (cpg_albums.thumb > 0)
     * to their Lumora counterpart by folder, and resolve the thumbnail's
     * picture ID to the corresponding Lumora image.
     *
     * @return list<array{cpg_aid: int, title: string, folder: string,
     *     cpg_thumb_pid: int, lumora_id: int|null, current_thumb_image_id: int,
     *     resolved_image_id: int|null, status: string}>
     */
    private function matchAlbumThumbnails(): array
    {
        $cpg_rows = $this->cpg->query(
            "SELECT `aid`, `title`, `keyword`, `thumb`
               FROM `{$this->cpg_prefix}albums`
              WHERE `category` > 0"
        )->fetchAll();

        $folder_by_aid          = $this->fetchAllCpgAlbumFolders();
        $lumora_album_by_folder = $this->fetchLumoraAlbumsByFolder();

        $thumb_pids = [];
        foreach ($cpg_rows as $r) {
            $t = (int) ($r['thumb'] ?? 0);
            if ($t > 0) $thumb_pids[] = $t;
        }
        $picture_info = $this->fetchCpgPictureInfo($thumb_pids);

        $result = [];
        foreach ($cpg_rows as $r) {
            $cpg_aid = (int) $r['aid'];
            $thumb   = (int) ($r['thumb'] ?? 0);
            if ($thumb <= 0) {
                continue; // no custom thumbnail set in Coppermine — nothing to sync
            }

            $title  = $this->decodeCpgText((string) ($r['title'] ?? ''));
            $folder = $folder_by_aid[$cpg_aid]
                ?? $this->resolveCpgAlbumFolder($cpg_aid, (string) ($r['keyword'] ?? ''));

            $lumora    = $lumora_album_by_folder[$folder] ?? null;
            $lumora_id = $lumora['id'] ?? null;
            $current   = $lumora['thumb_image_id'] ?? 0;

            $resolved = ($lumora_id !== null)
                ? $this->resolvePidToLumoraImage($thumb, $picture_info, $folder_by_aid, $lumora_album_by_folder)
                : null;

            $status = match (true) {
                $lumora_id === null => 'unmatched',
                $resolved === null  => 'image_unresolved',
                $current > 0        => 'already_set',
                default             => 'ready',
            };

            $result[] = [
                'cpg_aid'                => $cpg_aid,
                'title'                  => $title,
                'folder'                 => $folder,
                'cpg_thumb_pid'          => $thumb,
                'lumora_id'              => $lumora_id,
                'current_thumb_image_id' => $current,
                'resolved_image_id'      => $resolved,
                'status'                 => $status,
            ];
        }

        return $result;
    }

    /**
     * Match CPG categories that have a custom thumbnail set
     * (cpg_categories.thumb > 0) to their Lumora counterpart by full
     * name-path from the root, and resolve the thumbnail's picture ID to
     * the corresponding Lumora image.
     *
     * @return list<array{cpg_cid: int, name: string, cpg_thumb_pid: int,
     *     lumora_id: int|null, current_thumb_image_id: int,
     *     resolved_image_id: int|null, status: string}>
     */
    private function matchCategoryThumbnails(): array
    {
        $cpg_rows = $this->cpg->query(
            "SELECT `cid`, `name`, `parent`, `thumb`
               FROM `{$this->cpg_prefix}categories`
              WHERE `parent` != -1"
        )->fetchAll();

        $cpg_by_id = [];
        foreach ($cpg_rows as $r) {
            $cpg_by_id[(int) $r['cid']] = [
                'name'   => $this->decodeCpgText((string) $r['name']),
                'parent' => (int) $r['parent'],
                'thumb'  => (int) $r['thumb'],
            ];
        }

        $cpg_paths = [];
        foreach (array_keys($cpg_by_id) as $cid) {
            $cpg_paths[$cid] = $this->buildCpgCategoryPath($cid, $cpg_by_id);
        }

        $lum_rows  = LumoraDB::fetchAll('SELECT `id`, `parent_id`, `name`, `thumb_image_id` FROM `{PREFIX}categories`');
        $lum_by_id = [];
        foreach ($lum_rows as $r) {
            $lum_by_id[(int) $r['id']] = [
                'parent_id'      => (int) $r['parent_id'],
                'name'           => (string) $r['name'],
                'thumb_image_id' => (int) $r['thumb_image_id'],
            ];
        }

        $path_to_lumora_ids = [];
        foreach (array_keys($lum_by_id) as $id) {
            $path = $this->buildLumoraCategoryPath($id, $lum_by_id);
            $path_to_lumora_ids[$path][] = $id;
        }

        $thumb_pids = [];
        foreach ($cpg_by_id as $row) {
            if ($row['thumb'] > 0) $thumb_pids[] = $row['thumb'];
        }
        $picture_info           = $this->fetchCpgPictureInfo($thumb_pids);
        $folder_by_aid          = $this->fetchAllCpgAlbumFolders();
        $lumora_album_by_folder = $this->fetchLumoraAlbumsByFolder();

        $result = [];
        foreach ($cpg_by_id as $cid => $row) {
            if ($row['thumb'] <= 0) {
                continue; // no custom thumbnail set in Coppermine — nothing to sync
            }

            $path      = $cpg_paths[$cid];
            $matches   = $path_to_lumora_ids[$path] ?? [];
            $lumora_id = (count($matches) === 1) ? $matches[0] : null;
            $current   = $lumora_id !== null ? $lum_by_id[$lumora_id]['thumb_image_id'] : 0;

            $resolved = ($lumora_id !== null)
                ? $this->resolvePidToLumoraImage($row['thumb'], $picture_info, $folder_by_aid, $lumora_album_by_folder)
                : null;

            $status = match (true) {
                count($matches) > 1 => 'ambiguous',
                $lumora_id === null => 'unmatched',
                $resolved === null  => 'image_unresolved',
                $current > 0        => 'already_set',
                default             => 'ready',
            };

            $result[] = [
                'cpg_cid'                => $cid,
                'name'                   => $row['name'],
                'cpg_thumb_pid'          => $row['thumb'],
                'lumora_id'              => $lumora_id,
                'current_thumb_image_id' => $current,
                'resolved_image_id'      => $resolved,
                'status'                 => $status,
            ];
        }

        return $result;
    }

    /**
     * Build a normalised path string for a CPG category, walking the parent
     * chain to the root. Uses ASCII 0x1F (unit separator) to join segments —
     * a character that cannot appear in a CPG category name — so names
     * containing slashes or other punctuation can never collide across
     * genuinely different hierarchies.
     *
     * @param array<int, array{name: string, parent: int, thumb: int}> $cpg_by_id
     */
    private function buildCpgCategoryPath(int $cid, array $cpg_by_id): string
    {
        $parts = [];
        $seen  = [];
        while ($cid > 0 && isset($cpg_by_id[$cid]) && !isset($seen[$cid])) {
            $seen[$cid] = true;
            array_unshift($parts, trim($cpg_by_id[$cid]['name']));
            $cid = $cpg_by_id[$cid]['parent'];
        }
        return implode("\x1f", $parts);
    }

    /**
     * Lumora-side equivalent of buildCpgCategoryPath(), walking parent_id
     * instead of the CPG `parent` column. Must build paths the same way so
     * the two sides can be compared by exact string equality.
     *
     * @param array<int, array{parent_id: int, name: string, thumb_image_id: int}> $lum_by_id
     */
    private function buildLumoraCategoryPath(int $id, array $lum_by_id): string
    {
        $parts = [];
        $seen  = [];
        while ($id > 0 && isset($lum_by_id[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($parts, trim($lum_by_id[$id]['name']));
            $id = $lum_by_id[$id]['parent_id'];
        }
        return implode("\x1f", $parts);
    }

    /**
     * Resolve a single CPG picture ID to its corresponding Lumora image ID,
     * via the picture's (aid, filename) -> CPG album folder -> Lumora album
     * -> filename match.
     *
     * @param array<int, array{aid: int, filename: string}> $picture_info
     * @param array<int, string> $folder_by_aid
     * @param array<string, array{id: int, thumb_image_id: int}> $lumora_album_by_folder
     */
    private function resolvePidToLumoraImage(
        int   $pid,
        array $picture_info,
        array $folder_by_aid,
        array $lumora_album_by_folder
    ): ?int {
        $info = $picture_info[$pid] ?? null;
        if ($info === null) {
            return null;
        }

        $folder = $folder_by_aid[$info['aid']] ?? null;
        if ($folder === null) {
            return null;
        }

        $lumora_album = $lumora_album_by_folder[$folder] ?? null;
        if ($lumora_album === null) {
            return null;
        }

        $id = LumoraDB::fetchValue(
            'SELECT id FROM `{PREFIX}images` WHERE album_id = ? AND filename = ? LIMIT 1',
            [$lumora_album['id'], $info['filename']]
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Batch-resolve a set of CPG picture IDs to their owning album ID and
     * filename in one query.
     *
     * @param  list<int> $pids
     * @return array<int, array{aid: int, filename: string}>
     */
    private function fetchCpgPictureInfo(array $pids): array
    {
        $pids = array_values(array_unique(array_filter($pids, static fn($p) => $p > 0)));
        if (empty($pids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $stmt = $this->cpg->prepare(
            "SELECT `pid`, `aid`, `filename` FROM `{$this->cpg_prefix}pictures` WHERE `pid` IN ({$placeholders})"
        );
        $stmt->execute($pids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['pid']] = [
                'aid'      => (int) $row['aid'],
                'filename' => basename((string) $row['filename']),
            ];
        }
        return $result;
    }

    /**
     * Resolve every CPG album's on-disk folder, covering the entire
     * installation. Unlike fetchCpgAlbumFilepaths(), this is not limited to
     * a keyset-paginated chunk of album IDs — the metadata sync tool needs
     * the full picture in one pass since categories/albums are processed as
     * a whole, not in chunks.
     *
     * @return array<int, string> CPG aid => folder
     */
    private function fetchAllCpgAlbumFolders(): array
    {
        // Primary: actual on-disk path recorded against each album's pictures.
        $folder_by_aid = [];
        try {
            $stmt = $this->cpg->query(
                "SELECT `aid`, MIN(`filepath`) AS `filepath` FROM `{$this->cpg_prefix}pictures` GROUP BY `aid`"
            );
            foreach ($stmt->fetchAll() as $row) {
                $fp = trim((string) ($row['filepath'] ?? ''), " \t\n\r/\\");
                $fp = (string) preg_replace('#^albums/#i', '', $fp);
                $fp = trim($fp, '/');
                if ($fp !== '') {
                    $folder_by_aid[(int) $row['aid']] = $fp;
                }
            }
        } catch (\Throwable) {
            // filepath column absent on some CPG variants — every album falls
            // through to the keyword-based fallback below.
        }

        // Fallback: albums with no pictures yet (or filepath unavailable) —
        // derive from the keyword column the same way resolveCpgAlbumFolder() does.
        $stmt = $this->cpg->query(
            "SELECT `aid`, `keyword` FROM `{$this->cpg_prefix}albums` WHERE `category` > 0"
        );
        foreach ($stmt->fetchAll() as $row) {
            $aid = (int) $row['aid'];
            if (!isset($folder_by_aid[$aid])) {
                $folder_by_aid[$aid] = $this->resolveCpgAlbumFolder($aid, (string) ($row['keyword'] ?? ''));
            }
        }

        return $folder_by_aid;
    }

    /**
     * @return array<string, array{id: int, thumb_image_id: int}> Lumora folder => album row
     */
    private function fetchLumoraAlbumsByFolder(): array
    {
        $rows = LumoraDB::fetchAll('SELECT `id`, `folder`, `thumb_image_id` FROM `{PREFIX}albums`');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['folder']] = [
                'id'             => (int) $row['id'],
                'thumb_image_id' => (int) $row['thumb_image_id'],
            ];
        }
        return $result;
    }
}

<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Gallery Service
 *
 * All category, album, image, statistics, and visitor-tracking queries.
 * Callers on public pages and in the admin panel use the legacy free-function
 * wrappers in include/functions.php; direct use of GalleryService:: is
 * preferred for new V2 code.
 *
 * All SQL uses {PREFIX} which LumoraDB::query() replaces at runtime.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class GalleryService
{
    // ── Categories ────────────────────────────────────────────────────────────

    /**
     * Get direct children of a parent category (parent_id = 0 for root).
     * Each row includes album_count, subcategory_count, and image_count.
     * image_count covers only images in albums directly belonging to this
     * category (not images in sub-category albums).
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string,
     *                    thumb_image_id: int, album_count: int, subcategory_count: int, image_count: int}>
     */
    public static function getCategories(int $parent_id = 0): array
    {
        return LumoraDB::fetchAll(
            'SELECT c.*,
                (SELECT COUNT(*) FROM `{PREFIX}albums`     a  WHERE a.category_id = c.id) AS album_count,
                (SELECT COUNT(*) FROM `{PREFIX}categories` sc WHERE sc.parent_id  = c.id) AS subcategory_count,
                (SELECT COUNT(*) FROM `{PREFIX}images`     i
                 JOIN `{PREFIX}albums` ia ON ia.id = i.album_id
                 WHERE ia.category_id = c.id AND i.approved = 1)                          AS image_count
             FROM `{PREFIX}categories` c
             WHERE c.parent_id = ?
             ORDER BY c.pos ASC, c.name ASC',
            [$parent_id]
        );
    }

    /** Get a single category row, or null. */
    public static function getCategory(int $id): ?array
    {
        return LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}categories` WHERE id = ?',
            [$id]
        );
    }

    /**
     * Get a flat list of all categories for admin dropdowns.
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string}>
     */
    public static function getAllCategoriesFlat(): array
    {
        return LumoraDB::fetchAll(
            'SELECT * FROM `{PREFIX}categories` ORDER BY parent_id ASC, pos ASC, name ASC'
        );
    }

    /**
     * Build the breadcrumb trail for a category, from root to $cat_id.
     * Returns array of ['id', 'name'] sorted root-first.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function getCategoryBreadcrumb(int $cat_id): array
    {
        $trail = [];
        $id    = $cat_id;
        $limit = 10; // guard against cycles
        while ($id > 0 && $limit-- > 0) {
            $cat = self::getCategory($id);
            if (!$cat) break;
            array_unshift($trail, ['id' => (int) $cat['id'], 'name' => $cat['name']]);
            $id = (int) $cat['parent_id'];
        }
        return $trail;
    }

    /**
     * Return combined album and image counts for a set of categories, including
     * all descendant subcategories at any depth.
     *
     * Uses three queries total regardless of tree depth or category count:
     *   1. Load the full category tree (id + parent_id only).
     *   2. Batch album counts by category_id for all descendant IDs.
     *   3. Batch image counts by category_id for all descendant IDs.
     *
     * @param list<int> $cat_ids  Root category IDs to aggregate.
     * @return array<int, array{album_count: int, image_count: int}>
     *         Keyed by each input cat_id; every input ID is present in the result.
     */
    public static function getCategorySubtreeCounts(array $cat_ids): array
    {
        if (empty($cat_ids)) return [];

        // 1. Load id + parent_id for the whole tree (two integer columns only).
        $all_rows    = LumoraDB::fetchAll('SELECT id, parent_id FROM `{PREFIX}categories`');
        $children_of = []; // parent_id => [child_id, ...]
        foreach ($all_rows as $row) {
            $children_of[(int) $row['parent_id']][] = (int) $row['id'];
        }

        // 2. BFS from each requested root to collect all descendant IDs (inclusive).
        $subtrees = []; // root_id => [id, id, ...]
        foreach ($cat_ids as $root_id) {
            $root_id = (int) $root_id;
            $ids     = [];
            $queue   = [$root_id];
            while (!empty($queue)) {
                $id    = array_shift($queue);
                $ids[] = $id;
                foreach ($children_of[$id] ?? [] as $child_id) {
                    $queue[] = $child_id;
                }
            }
            $subtrees[$root_id] = $ids;
        }

        // 3. Flatten all descendant IDs to a unique set for the batch queries.
        $all_ids = array_values(array_unique(array_merge(...array_values($subtrees))));
        if (empty($all_ids)) {
            return array_fill_keys(
                array_map('intval', $cat_ids),
                ['album_count' => 0, 'image_count' => 0]
            );
        }
        $ph = implode(',', array_fill(0, count($all_ids), '?'));

        // 4. Album count per leaf category_id.
        $album_per_cat = [];
        $rows = LumoraDB::fetchAll(
            "SELECT category_id, COUNT(*) AS cnt FROM `{PREFIX}albums`
             WHERE category_id IN ({$ph}) GROUP BY category_id",
            $all_ids
        );
        foreach ($rows as $row) {
            $album_per_cat[(int) $row['category_id']] = (int) $row['cnt'];
        }

        // 5. Image count per leaf category_id (via album join).
        $image_per_cat = [];
        $rows = LumoraDB::fetchAll(
            "SELECT a.category_id, COUNT(*) AS cnt
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE a.category_id IN ({$ph}) AND i.approved = 1
             GROUP BY a.category_id",
            $all_ids
        );
        foreach ($rows as $row) {
            $image_per_cat[(int) $row['category_id']] = (int) $row['cnt'];
        }

        // 6. Aggregate per-leaf counts back to each input root.
        $result = [];
        foreach ($cat_ids as $root_id) {
            $root_id = (int) $root_id;
            $albums  = 0;
            $images  = 0;
            foreach ($subtrees[$root_id] as $id) {
                $albums += $album_per_cat[$id] ?? 0;
                $images += $image_per_cat[$id] ?? 0;
            }
            $result[$root_id] = ['album_count' => $albums, 'image_count' => $images];
        }
        return $result;
    }

    // ── Admin Category Queries ────────────────────────────────────────────────

    /**
     * Count all categories.
     *
     * @return int Total category count.
     */
    public static function countAllCategories(): int
    {
        return (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}categories`');
    }

    /**
     * Get a paginated flat list of categories for the admin UI.
     *
     * Ordered identically to getAllCategoriesFlat() so the paginated list is
     * consistent with the full list used in dropdowns and parent lookups.
     *
     * @param int $page     1-based page number.
     * @param int $per_page Categories per page.
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string}>
     */
    public static function getPaginatedCategoriesFlat(int $page, int $per_page): array
    {
        $offset = max(0, ($page - 1) * $per_page);
        return LumoraDB::fetchAll(
            'SELECT * FROM `{PREFIX}categories` ORDER BY parent_id ASC, pos ASC, name ASC
             LIMIT ? OFFSET ?',
            [$per_page, $offset]
        );
    }

    // ── Albums ────────────────────────────────────────────────────────────────

    /**
     * Get albums in a category, with image_count and latest_added_at.
     * $sort: 'pos' | 'title' | 'newest' | 'hits'
     *
     * latest_added_at is the MAX(added_at) of this album's approved images
     * (null when the album has no approved images yet). Themes use it instead
     * of created_at to show when an album was last actually updated with new
     * content, rather than when the album row itself was created/imported.
     *
     * @return list<array{id: int, category_id: int, folder: string, title: string,
     *                    description: string, visibility: int, pos: int, hits: int,
     *                    thumb_image_id: int, created_at: string, image_count: int,
     *                    latest_added_at: string|null}>
     */
    public static function getAlbums(int $category_id, string $sort = 'pos'): array
    {
        $order = match($sort) {
            'title'  => 'a.title ASC',
            'newest' => 'a.created_at DESC',
            'hits'   => 'a.hits DESC',
            default  => 'a.pos ASC, a.title ASC',
        };
        return LumoraDB::fetchAll(
            "SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count,
                 (SELECT MAX(i2.added_at) FROM `{PREFIX}images` i2 WHERE i2.album_id = a.id AND i2.approved = 1) AS latest_added_at
             FROM `{PREFIX}albums` a
             WHERE a.category_id = ? AND a.visibility = 0
             ORDER BY {$order}",
            [$category_id]
        );
    }

    /**
     * Get a single album row (with image_count), or null.
     */
    public static function getAlbum(int $id): ?array
    {
        return LumoraDB::fetchOne(
            'SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
             FROM `{PREFIX}albums` a
             WHERE a.id = ?',
            [$id]
        );
    }

    /** Increment album hit counter. */
    public static function incrementAlbumHits(int $album_id): void
    {
        LumoraDB::query('UPDATE `{PREFIX}albums` SET hits = hits + 1 WHERE id = ?', [$album_id]);
    }

    // ── Admin Album Queries ───────────────────────────────────────────────────

    /**
     * Count albums for the admin list, with optional category filter.
     *
     * @param int $cat_id Filter by category; 0 = all categories.
     * @return int Total album count.
     */
    public static function countAdminAlbums(int $cat_id = 0): int
    {
        if ($cat_id > 0) {
            return (int) LumoraDB::fetchValue(
                'SELECT COUNT(*) FROM `{PREFIX}albums` WHERE category_id = ?',
                [$cat_id]
            );
        }
        return (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}albums`');
    }

    /**
     * Get a paginated list of albums for the admin UI.
     *
     * Includes cat_name (categories join) and image_count (approved images only).
     * Ordered by category name, album position, then title.
     *
     * @param int $cat_id   Filter by category; 0 = all categories.
     * @param int $page     1-based page number.
     * @param int $per_page Albums per page.
     * @return list<array{id: int, category_id: int, folder: string, title: string,
     *                    description: string, visibility: int, pos: int, hits: int,
     *                    thumb_image_id: int, created_at: string,
     *                    cat_name: string|null, image_count: int}>
     */
    public static function getAdminAlbums(int $cat_id, int $page, int $per_page): array
    {
        $offset = max(0, ($page - 1) * $per_page);
        if ($cat_id > 0) {
            return LumoraDB::fetchAll(
                'SELECT a.*, c.name AS cat_name,
                        (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
                 FROM `{PREFIX}albums` a
                 LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
                 WHERE a.category_id = ?
                 ORDER BY c.name ASC, a.pos ASC, a.title ASC
                 LIMIT ? OFFSET ?',
                [$cat_id, $per_page, $offset]
            );
        }
        return LumoraDB::fetchAll(
            'SELECT a.*, c.name AS cat_name,
                    (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
             FROM `{PREFIX}albums` a
             LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
             ORDER BY c.name ASC, a.pos ASC, a.title ASC
             LIMIT ? OFFSET ?',
            [$per_page, $offset]
        );
    }

    // ── Images ────────────────────────────────────────────────────────────────

    /**
     * Get a paginated set of approved images for an album.
     * $sort: 'pos' | 'newest' | 'oldest' | 'most_viewed' | 'filename'
     */
    public static function getAlbumImages(
        int    $album_id,
        int    $page     = 1,
        int    $per_page = 48,
        string $sort     = 'pos'
    ): array {
        $order = match($sort) {
            'newest'      => 'i.added_at DESC',
            'oldest'      => 'i.added_at ASC',
            'most_viewed' => 'i.hits DESC',
            'filename'    => 'i.filename ASC',
            default       => 'i.pos ASC, i.id ASC',
        };
        $offset = max(0, ($page - 1)) * $per_page;
        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.album_id = ? AND i.approved = 1
             ORDER BY {$order}
             LIMIT ? OFFSET ?",
            [$album_id, $per_page, $offset]
        );
    }

    /** Count approved images in an album. */
    public static function countAlbumImages(int $album_id): int
    {
        return (int) LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM `{PREFIX}images` WHERE album_id = ? AND approved = 1',
            [$album_id]
        );
    }

    /**
     * Get a single approved image with its album folder and category_id.
     */
    public static function getImage(int $id): ?array
    {
        return LumoraDB::fetchOne(
            'SELECT i.*, a.folder, a.title AS album_title, a.category_id
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id = ? AND i.approved = 1',
            [$id]
        );
    }

    /** Increment image hit counter. */
    public static function incrementImageHits(int $image_id): void
    {
        LumoraDB::query('UPDATE `{PREFIX}images` SET hits = hits + 1 WHERE id = ?', [$image_id]);
    }

    /**
     * Get the previous and next image IDs in an album relative to a given image.
     *
     * Loads all IDs in order once; efficient for typical album sizes.
     *
     * @return array{prev: int|null, next: int|null}
     */
    public static function getImageNeighbours(int $image_id, int $album_id, string $sort = 'pos'): array
    {
        $order = match($sort) {
            'newest'      => 'i.added_at DESC',
            'oldest'      => 'i.added_at ASC',
            'most_viewed' => 'i.hits DESC',
            'filename'    => 'i.filename ASC',
            default       => 'i.pos ASC, i.id ASC',
        };

        $ids = array_column(
            LumoraDB::fetchAll(
                "SELECT id FROM `{PREFIX}images` WHERE album_id = ? AND approved = 1 ORDER BY {$order}",
                [$album_id]
            ),
            'id'
        );

        $pos = array_search((string) $image_id, array_map('strval', $ids), true);
        if ($pos === false) return ['prev' => null, 'next' => null];

        return [
            'prev' => $pos > 0                 ? (int) $ids[$pos - 1] : null,
            'next' => $pos < (count($ids) - 1) ? (int) $ids[$pos + 1] : null,
        ];
    }

    // ── Admin Image Search ────────────────────────────────────────────────────

    /**
     * Search images by filename or title (admin use; any approval status).
     *
     * Case-insensitive partial match against both `filename` and `title`
     * columns. Results include album title and category name so search results
     * across multiple albums can be displayed with full context.
     *
     * When $album_id > 0 the search is scoped to that album; when 0 it covers
     * all albums. For single-album searches the existing `album_approved` index
     * limits the scan to that album's rows, keeping performance acceptable even
     * at 500 K total images. Cross-album searches perform a full table scan on
     * the images table; see the migration note in CHANGELOG.md for an optional
     * FULLTEXT index that can be added on very large galleries.
     *
     * @param string $query    Search term; partial / multi-word; case-insensitive.
     * @param int    $album_id Restrict to this album; 0 = all albums.
     * @param int    $page     1-based page number.
     * @param int    $per_page Rows per page.
     * @return list<array{id: int, album_id: int, filename: string, title: string,
     *                    filesize: int, width: int, height: int, hits: int,
     *                    approved: int, pos: int, added_at: string,
     *                    folder: string, album_title: string, cat_name: string}>
     */
    public static function searchImages(
        string $query,
        int    $album_id  = 0,
        int    $page      = 1,
        int    $per_page  = 24
    ): array {
        $like   = '%' . $query . '%';
        $offset = max(0, ($page - 1) * $per_page);
        $params = [$like, $like];

        $where = '(i.filename LIKE ? OR i.title LIKE ?)';
        if ($album_id > 0) {
            $where   .= ' AND i.album_id = ?';
            $params[] = $album_id;
        }
        $params[] = $per_page;
        $params[] = $offset;

        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder, a.title AS album_title,
                    COALESCE(c.name, '') AS cat_name
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
             WHERE {$where}
             ORDER BY a.title ASC, i.pos ASC, i.id ASC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Count images matching a search query (admin use; any approval status).
     *
     * @param string $query    Search term; partial / multi-word; case-insensitive.
     * @param int    $album_id Restrict to this album; 0 = all albums.
     * @return int             Total matching image count.
     */
    public static function countSearchImages(string $query, int $album_id = 0): int
    {
        $like   = '%' . $query . '%';
        $params = [$like, $like];

        $where = '(i.filename LIKE ? OR i.title LIKE ?)';
        if ($album_id > 0) {
            $where   .= ' AND i.album_id = ?';
            $params[] = $album_id;
        }

        return (int) LumoraDB::fetchValue(
            "SELECT COUNT(*)
             FROM `{PREFIX}images` i
             WHERE {$where}",
            $params
        );
    }

    // ── Gallery-wide image queries ────────────────────────────────────────────

    /**
     * Get albums with the most recently added images (public albums only).
     * Used on the home page when latest_albums_count > 0.
     *
     * @return array[] Each row is an album row plus image_count and latest_added_at.
     */
    public static function getLatestUpdatedAlbums(int $limit = 5): array
    {
        if ($limit <= 0) return [];
        return LumoraDB::fetchAll(
            'SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count,
                 (SELECT MAX(i2.added_at) FROM `{PREFIX}images` i2 WHERE i2.album_id = a.id AND i2.approved = 1) AS latest_added_at
             FROM `{PREFIX}albums` a
             WHERE a.visibility = 0
             HAVING latest_added_at IS NOT NULL
             ORDER BY latest_added_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /** Most-viewed approved images (public albums only). */
    public static function getMostViewedImages(int $limit = 48): array
    {
        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0
             ORDER BY i.hits DESC
             LIMIT ?',
            [$limit]
        );
    }

    /** Most recently added images (public albums only). */
    public static function getLatestImages(int $limit = 48): array
    {
        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0
             ORDER BY i.added_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /** Random images from public albums. */
    public static function getRandomImages(int $limit = 48): array
    {
        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0
             ORDER BY RAND()
             LIMIT ?',
            [$limit]
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Return basic gallery stats: categories, albums, images, total hits.
     *
     * @return array{categories: int, albums: int, images: int, total_hits: int}
     */
    public static function getGalleryStats(): array
    {
        return [
            'categories' => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}categories`'),
            'albums'     => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}albums`'),
            'images'     => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}images` WHERE approved = 1'),
            'total_hits' => (int) LumoraDB::fetchValue('SELECT COALESCE(SUM(hits),0) FROM `{PREFIX}images`'),
        ];
    }

    // ── Who Is Online ─────────────────────────────────────────────────────────

    /**
     * Record (or refresh) the current visitor's IP in the online-tracking table.
     *
     * On each call:
     *   1. Deletes rows whose last_action is older than `who_is_online_duration` minutes.
     *   2. Upserts the current IP with last_action = NOW().
     *
     * Fails silently when the {PREFIX}online table is absent (pre-v5 installs).
     */
    public static function trackVisitor(): void
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        if ($ip === '') return;

        $duration = max(1, (int) LumoraConfig::get('who_is_online_duration', '5'));

        try {
            LumoraDB::query(
                'DELETE FROM `{PREFIX}online` WHERE last_action < NOW() - INTERVAL ? MINUTE',
                [$duration]
            );
            LumoraDB::query(
                'INSERT INTO `{PREFIX}online` (ip, last_action) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_action = NOW()',
                [$ip]
            );
        } catch (\Throwable) {
            // {PREFIX}online absent on pre-v5 installs; fail silently.
        }
    }

    /**
     * Return the current online visitor count and the all-time record.
     *
     * Also updates `online_record_count` / `online_record_date` config keys
     * when the current count exceeds the stored record.
     *
     * Returns ['online' => 0, ...] on pre-v5 installs where the table is absent.
     *
     * @return array{online: int, record_count: int, record_date: string}
     */
    public static function getOnlineStats(): array
    {
        try {
            $count = (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}online`');
        } catch (\Throwable) {
            return ['online' => 0, 'record_count' => 0, 'record_date' => ''];
        }

        $record      = max(0, (int) LumoraConfig::get('online_record_count', '0'));
        $record_date = (string) LumoraConfig::get('online_record_date', '');

        if ($count > $record) {
            $record      = $count;
            $record_date = date('Y-m-d H:i:s');
            LumoraConfig::set('online_record_count', (string) $record);
            LumoraConfig::set('online_record_date',  $record_date);
        }

        return [
            'online'       => $count,
            'record_count' => $record,
            'record_date'  => $record_date,
        ];
    }
}

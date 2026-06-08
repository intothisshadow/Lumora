<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Core Functions
 *
 * Covers: config cache, gallery utility helpers, categories, albums, images,
 * stats, pagination, and path/URL helpers.
 *
 * All SQL uses {PREFIX} which LumoraDB::query() replaces at runtime.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Global config cache ───────────────────────────────────────────────────────

/** @var array<string,string> Runtime config cache (populated by lumora_load_config). */
$LUMORA_CONFIG = [];

/**
 * Load all rows from lum_config into the in-memory cache.
 * Called once per request by bootstrap.php after DB connection.
 */
function lumora_load_config(): void
{
    global $LUMORA_CONFIG;
    $rows = LumoraDB::fetchAll('SELECT name, value FROM `{PREFIX}config`');
    foreach ($rows as $row) {
        $LUMORA_CONFIG[$row['name']] = $row['value'];
    }
}

/**
 * Get a config value from the in-memory cache.
 */
function lumora_config(string $key, mixed $default = null): mixed
{
    global $LUMORA_CONFIG;
    return array_key_exists($key, $LUMORA_CONFIG) ? $LUMORA_CONFIG[$key] : $default;
}

/**
 * Persist a config value to DB and update the in-memory cache.
 */
function lumora_set_config(string $key, mixed $value): void
{
    global $LUMORA_CONFIG;
    $LUMORA_CONFIG[$key] = (string) $value;
    LumoraDB::query(
        'INSERT INTO `{PREFIX}config` (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$key, (string) $value]
    );
}

// ── Output helpers ────────────────────────────────────────────────────────────

/**
 * HTML-escape a value for safe output.
 */
function h(mixed $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Redirect and exit.
 */
function lumora_redirect(string $url, int $code = 302): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

// ── Integer input validation ──────────────────────────────────────────────────

/**
 * Cast and clamp an untrusted value to int. Returns $default if out of range.
 */
function lumora_int(mixed $value, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $v = (int) $value;
    return ($v >= $min && $v <= $max) ? $v : $default;
}

// ── Path & URL helpers ────────────────────────────────────────────────────────

/**
 * Gallery base URL with trailing slash. Safe to call once config is loaded.
 */
function lumora_base_url(): string
{
    return rtrim((string) lumora_config('base_url', ''), '/') . '/';
}

/**
 * Absolute filesystem path to an album folder, with trailing separator.
 */
function lumora_album_path(string $folder): string
{
    return LUMORA_ALBUMS_PATH . $folder . DIRECTORY_SEPARATOR;
}

/**
 * Public URL to an album folder, with trailing slash.
 * Each path segment is percent-encoded individually; forward slashes are
 * preserved as path separators so nested folders resolve correctly.
 * e.g. "Xena/Season1/1x01-SinsOfThePast" → "albums/Xena/Season1/1x01-SinsOfThePast/"
 */
function lumora_album_url(string $folder): string
{
    $encoded = implode('/', array_map('rawurlencode', explode('/', $folder)));
    return lumora_base_url() . 'albums/' . $encoded . '/';
}

/**
 * Active theme name (falls back to 'default').
 */
function lumora_active_theme(): string
{
    return lumora_config('theme', 'default') ?: 'default';
}

/**
 * URL to a theme's directory, with trailing slash.
 */
function lumora_theme_url(string $theme = ''): string
{
    if ($theme === '') $theme = lumora_active_theme();
    return lumora_base_url() . 'themes/' . rawurlencode($theme) . '/';
}

/**
 * Filesystem path to a theme's directory, with trailing separator.
 */
function lumora_theme_path(string $theme = ''): string
{
    if ($theme === '') $theme = lumora_active_theme();
    return LUMORA_THEMES_PATH . $theme . DIRECTORY_SEPARATOR;
}

/**
 * List installed theme names (directories containing a template.html).
 * Returns a sorted array of theme name strings.
 */
function lumora_list_themes(): array
{
    if (!is_dir(LUMORA_THEMES_PATH)) return [];
    $themes = [];
    foreach (new DirectoryIterator(LUMORA_THEMES_PATH) as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $name = $item->getFilename();
            if (file_exists($item->getPathname() . DIRECTORY_SEPARATOR . 'template.html')) {
                $themes[] = $name;
            }
        }
    }
    sort($themes);
    return $themes;
}

// ── Formatting ────────────────────────────────────────────────────────────────

/**
 * Format bytes to a human-readable string (B / KB / MB / GB).
 */
function lumora_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)   return number_format($bytes / 1048576,   1) . ' MB';
    if ($bytes >= 1024)      return number_format($bytes / 1024,      1) . ' KB';
    return $bytes . ' B';
}

/**
 * Generate a zero-padded album folder name from an album ID, e.g. "00042".
 * Used as an automatic fallback when the admin does not supply a custom folder path.
 */
function lumora_generate_folder(int $id): string
{
    return sprintf('%05d', $id);
}

/**
 * Sanitize a user-supplied album folder path.
 *
 * Allowed per-segment characters: letters, digits, hyphens, underscores, dots.
 * Segments are joined with forward slashes to form a relative path.
 * Strips path traversal (. and ..), hidden-directory segments (leading dot),
 * and any characters outside the allowed set.
 *
 * Examples:
 *   "Xena/Season1/1x01-SinsOfThePast" → "Xena/Season1/1x01-SinsOfThePast"
 *   "../../etc/passwd"                → ""  (traversal stripped)
 *   "  /Xena//Season1/ "              → "Xena/Season1"
 *
 * @return string Clean relative path, or '' if nothing safe remains.
 */
function lumora_sanitize_folder(string $raw): string
{
    // Strip characters not allowed in path segments.
    $clean = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $raw);
    // Collapse multiple consecutive slashes.
    $clean = (string) preg_replace('#/+#', '/', (string) $clean);
    // Trim leading/trailing slashes.
    $clean = trim((string) $clean, '/');
    // Remove segments that are empty, '.', '..', or start with '.' (hidden dirs).
    $segments = array_filter(
        explode('/', $clean),
        static fn(string $s): bool => $s !== '' && $s !== '.' && $s !== '..' && !str_starts_with($s, '.')
    );
    return implode('/', $segments);
}

// ── Image URL helpers (require 'folder' column from album join) ────────────────

/** Public URL to the original image. */
function image_original_url(array $image): string
{
    return lumora_album_url($image['folder']) . rawurlencode($image['filename']);
}

/** Public URL to the thumbnail. */
function image_thumb_url(array $image): string
{
    return lumora_album_url($image['folder']) . rawurlencode(LUMORA_THUMB_PREFIX . $image['filename']);
}

/** Filesystem path to the original image. */
function image_original_path(array $image): string
{
    return lumora_album_path($image['folder']) . $image['filename'];
}

/** Filesystem path to the thumbnail. */
function image_thumb_path(array $image): string
{
    return lumora_album_path($image['folder']) . LUMORA_THUMB_PREFIX . $image['filename'];
}

// ── Categories ────────────────────────────────────────────────────────────────

/**
 * Get direct children of a parent category (parent_id = 0 for root).
 * Each row includes album_count and subcategory_count.
 */
function get_categories(int $parent_id = 0): array
{
    return LumoraDB::fetchAll(
        'SELECT c.*,
            (SELECT COUNT(*) FROM `{PREFIX}albums`     a  WHERE a.category_id = c.id) AS album_count,
            (SELECT COUNT(*) FROM `{PREFIX}categories` sc WHERE sc.parent_id  = c.id) AS subcategory_count
         FROM `{PREFIX}categories` c
         WHERE c.parent_id = ?
         ORDER BY c.pos ASC, c.name ASC',
        [$parent_id]
    );
}

/** Get a single category row, or null. */
function get_category(int $id): ?array
{
    return LumoraDB::fetchOne(
        'SELECT * FROM `{PREFIX}categories` WHERE id = ?',
        [$id]
    );
}

/**
 * Get a flat list of all categories for admin dropdowns.
 */
function get_all_categories_flat(): array
{
    return LumoraDB::fetchAll(
        'SELECT * FROM `{PREFIX}categories` ORDER BY parent_id ASC, pos ASC, name ASC'
    );
}

/**
 * Build the breadcrumb trail for a category, from root to $cat_id.
 * Returns array of ['id', 'name'] sorted root-first.
 */
function get_category_breadcrumb(int $cat_id): array
{
    $trail = [];
    $id    = $cat_id;
    $limit = 10; // guard against cycles
    while ($id > 0 && $limit-- > 0) {
        $cat = get_category($id);
        if (!$cat) break;
        array_unshift($trail, ['id' => (int) $cat['id'], 'name' => $cat['name']]);
        $id = (int) $cat['parent_id'];
    }
    return $trail;
}

// ── Albums ────────────────────────────────────────────────────────────────────

/**
 * Get albums in a category, with image_count.
 * $sort: 'pos' | 'title' | 'newest' | 'hits'
 */
function get_albums(int $category_id, string $sort = 'pos'): array
{
    $order = match($sort) {
        'title'  => 'a.title ASC',
        'newest' => 'a.created_at DESC',
        'hits'   => 'a.hits DESC',
        default  => 'a.pos ASC, a.title ASC',
    };
    return LumoraDB::fetchAll(
        "SELECT a.*,
             (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
         FROM `{PREFIX}albums` a
         WHERE a.category_id = ? AND a.visibility = 0
         ORDER BY {$order}",
        [$category_id]
    );
}

/**
 * Get a single album row (with image_count), or null.
 */
function get_album(int $id): ?array
{
    return LumoraDB::fetchOne(
        'SELECT a.*,
             (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
         FROM `{PREFIX}albums` a
         WHERE a.id = ?',
        [$id]
    );
}

/** Increment album hit counter (do throttle with session on caller side). */
function increment_album_hits(int $album_id): void
{
    LumoraDB::query('UPDATE `{PREFIX}albums` SET hits = hits + 1 WHERE id = ?', [$album_id]);
}

// ── Images ────────────────────────────────────────────────────────────────────

/**
 * Get a paginated set of approved images for an album.
 * $sort: 'pos' | 'newest' | 'oldest' | 'most_viewed' | 'filename'
 */
function get_album_images(int $album_id, int $page = 1, int $per_page = 48, string $sort = 'pos'): array
{
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
function count_album_images(int $album_id): int
{
    return (int) LumoraDB::fetchValue(
        'SELECT COUNT(*) FROM `{PREFIX}images` WHERE album_id = ? AND approved = 1',
        [$album_id]
    );
}

/**
 * Get a single approved image with its album folder and category_id.
 */
function get_image(int $id): ?array
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
function increment_image_hits(int $image_id): void
{
    LumoraDB::query('UPDATE `{PREFIX}images` SET hits = hits + 1 WHERE id = ?', [$image_id]);
}

/**
 * Get the previous and next image IDs in an album relative to a given image.
 * Returns ['prev' => ?int, 'next' => ?int].
 *
 * Loads all IDs in order once; efficient for typical album sizes.
 * For 9000-image albums this returns ~9000 ints (~72 KB), which is acceptable.
 */
function get_image_neighbours(int $image_id, int $album_id, string $sort = 'pos'): array
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
        'prev' => $pos > 0                    ? (int) $ids[$pos - 1] : null,
        'next' => $pos < (count($ids) - 1)    ? (int) $ids[$pos + 1] : null,
    ];
}

// ── Gallery-wide image queries ────────────────────────────────────────────────

/** Most-viewed approved images (public albums only). */
function get_most_viewed_images(int $limit = 48): array
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
function get_latest_images(int $limit = 48): array
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
function get_random_images(int $limit = 48): array
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

// ── Stats ─────────────────────────────────────────────────────────────────────

/**
 * Return basic gallery stats: categories, albums, images, total hits.
 */
function get_gallery_stats(): array
{
    return [
        'categories'  => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}categories`'),
        'albums'      => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}albums`'),
        'images'      => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}images` WHERE approved = 1'),
        'total_hits'  => (int) LumoraDB::fetchValue('SELECT COALESCE(SUM(hits),0) FROM `{PREFIX}images`'),
    ];
}

// ── Pagination ────────────────────────────────────────────────────────────────

/**
 * Build a pagination descriptor array.
 *
 * @param string $url_pattern A printf pattern with one %d placeholder for the page number.
 *                            Example: 'album.php?album=5&page=%d'
 */
function lumora_pagination(int $total, int $per_page, int $current_page, string $url_pattern): array
{
    $total_pages = max(1, (int) ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));

    return [
        'total'        => $total,
        'per_page'     => $per_page,
        'current_page' => $current_page,
        'total_pages'  => $total_pages,
        'has_prev'     => $current_page > 1,
        'has_next'     => $current_page < $total_pages,
        'prev_url'     => $current_page > 1                ? sprintf($url_pattern, $current_page - 1) : null,
        'next_url'     => $current_page < $total_pages     ? sprintf($url_pattern, $current_page + 1) : null,
        'url_pattern'  => $url_pattern,
        'start_item'   => ($current_page - 1) * $per_page + 1,
        'end_item'     => min($current_page * $per_page, $total),
    ];
}

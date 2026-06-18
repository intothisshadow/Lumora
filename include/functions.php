<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Core Functions
 *
 * Contains two categories of functions:
 *
 *   1. Utility / helper free functions that have no natural class home:
 *      output escaping, redirects, input coercion, path/URL builders,
 *      formatters, activity logging, and pagination.
 *      These are kept as free functions because they are called from every
 *      context (public pages, admin, AJAX, installer) and carry no state.
 *
 *   2. Legacy forwarding wrappers for every function that has been migrated
 *      to a service class. Each wrapper is a one-liner that delegates to the
 *      appropriate service method, preserving full backward compatibility for
 *      all existing callers. New V2 code should call the service classes
 *      directly: LumoraConfig::, GalleryService::.
 *
 * Service classes (loaded by bootstrap.php before this file):
 *   LumoraConfig   — include/services/LumoraConfig.php
 *   GalleryService — include/services/GalleryService.php
 *
 * All SQL uses {PREFIX} which LumoraDB::query() replaces at runtime.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Config wrappers (delegate to LumoraConfig) ────────────────────────────────

/**
 * Load all rows from {PREFIX}config into the in-memory cache.
 * Called once per request by bootstrap.php after DB connection.
 */
function lumora_load_config(): void
{
    LumoraConfig::load();
}

/**
 * Get a config value from the in-memory cache.
 */
function lumora_config(string $key, mixed $default = null): mixed
{
    return LumoraConfig::get($key, $default);
}

/**
 * Persist a config value to DB and update the in-memory cache.
 */
function lumora_set_config(string $key, mixed $value): void
{
    LumoraConfig::set($key, $value);
}

// ── Activity logging ──────────────────────────────────────────────────────────

/**
 * Log an event according to the configured log_mode.
 *
 * 'off'    — nothing is written.
 * 'errors' — only events of $type === 'error' are written to the PHP error log.
 * 'all'    — all events are written to the PHP error log AND inserted into
 *            {PREFIX}log (requires the table added in DB version 2).
 *
 * @param string $type    Short event category: 'visit', 'error', 'info'.
 * @param string $message Human-readable description of the event.
 */
function lumora_log(string $type, string $message): void
{
    $mode = lumora_config('log_mode', 'off');
    if ($mode === 'off') return;

    if ($mode === 'errors' && $type !== 'error') return;

    error_log('Lumora [' . $type . ']: ' . $message);

    if ($mode === 'all') {
        try {
            LumoraDB::query(
                'INSERT INTO `{PREFIX}log` (type, message, ip) VALUES (?, ?, ?)',
                [
                    substr($type, 0, 16),
                    substr($message, 0, 65535),
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ]
            );
        } catch (\Throwable) {
            // {PREFIX}log table absent on pre-v2 installs; fail silently.
        }
    }
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
 * Gallery base URL with trailing slash.
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

/**
 * Locate a theme's primary stylesheet: the first theme-relative ({THEME_URL})
 * stylesheet <link> found in its template.html, in document order.
 *
 * This is the file CSS header metadata (Theme Name / Author / Design URI) is
 * read from. For the bundled themes this resolves to lumora.css (default) and
 * fansite.css (classic-fansite and its derivatives) — the base stylesheet
 * linked before any optional custom.css override.
 *
 * @return string|null Absolute filesystem path, or null if none could be found.
 */
function lumora_theme_primary_stylesheet(string $theme): ?string
{
    $tpl_path = lumora_theme_path($theme) . 'template.html';
    if (!is_readable($tpl_path)) return null;

    $html = file_get_contents($tpl_path, false, null, 0, 16384);
    if ($html === false) return null;

    if (!preg_match('/\{THEME_URL\}([A-Za-z0-9_\-.]+\.css)/', $html, $m)) {
        return null;
    }

    $css_path = lumora_theme_path($theme) . $m[1];
    return is_readable($css_path) ? $css_path : null;
}

/**
 * Read theme metadata from the CSS header comment of a theme's primary
 * stylesheet, WordPress-style (Theme Name / Author / Design URI on their own
 * lines inside the first CSS comment block — see e.g. themes/default/lumora.css
 * for a working example). Only the first comment block in the file is
 * inspected; unrecognised fields are ignored. Falls back to the directory name
 * for `name` when no metadata is present, so every theme always has a usable
 * display name.
 *
 * @return array{name: string, author: string, design_uri: string}
 */
function lumora_get_theme_meta(string $theme): array
{
    $meta = [
        'name'       => $theme,
        'author'     => '',
        'design_uri' => '',
    ];

    $css_path = lumora_theme_primary_stylesheet($theme);
    if ($css_path === null) return $meta;

    $head = file_get_contents($css_path, false, null, 0, 8192);
    if ($head === false) return $meta;

    if (!preg_match('#/\*(.*?)\*/#s', $head, $m)) return $meta;
    $comment = $m[1];

    $fields = ['name' => 'Theme Name', 'author' => 'Author', 'design_uri' => 'Design URI'];
    foreach ($fields as $key => $label) {
        if (preg_match('/^[ \t*]*' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi', $comment, $mm)) {
            $value = trim($mm[1]);
            if ($value !== '') $meta[$key] = $value;
        }
    }

    return $meta;
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
 * @return string Clean relative path, or '' if nothing safe remains.
 */
function lumora_sanitize_folder(string $raw): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $raw);
    $clean = (string) preg_replace('#/+#', '/', (string) $clean);
    $clean = trim((string) $clean, '/');
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

// ── Pagination ────────────────────────────────────────────────────────────────

/**
 * Build a pagination descriptor array.
 *
 * @param string $url_pattern A printf pattern with one %d placeholder for the page number.
 *                            Example: 'album.php?album=5&page=%d'
 */
function lumora_pagination(int $total, int $per_page, int $current_page, string $url_pattern): array
{
    $total_pages  = max(1, (int) ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));

    return [
        'total'        => $total,
        'per_page'     => $per_page,
        'current_page' => $current_page,
        'total_pages'  => $total_pages,
        'has_prev'     => $current_page > 1,
        'has_next'     => $current_page < $total_pages,
        'prev_url'     => $current_page > 1             ? sprintf($url_pattern, $current_page - 1) : null,
        'next_url'     => $current_page < $total_pages  ? sprintf($url_pattern, $current_page + 1) : null,
        'url_pattern'  => $url_pattern,
        'start_item'   => ($current_page - 1) * $per_page + 1,
        'end_item'     => min($current_page * $per_page, $total),
    ];
}

// ── Category wrappers (delegate to GalleryService) ────────────────────────────

/** @return list<array{id: int, name: string, parent_id: int, pos: int, description: string,
 *                     thumb_image_id: int, album_count: int, subcategory_count: int, image_count: int}> */
function get_categories(int $parent_id = 0): array          { return GalleryService::getCategories($parent_id); }

/** Get a single category row, or null. */
function get_category(int $id): ?array                       { return GalleryService::getCategory($id); }

/** Get a flat list of all categories for admin dropdowns. */
function get_all_categories_flat(): array                    { return GalleryService::getAllCategoriesFlat(); }

/** Build the breadcrumb trail for a category, from root to $cat_id. */
function get_category_breadcrumb(int $cat_id): array         { return GalleryService::getCategoryBreadcrumb($cat_id); }

/**
 * Return combined album and image counts for a set of categories, including
 * all descendant subcategories at any depth.
 *
 * @param list<int> $cat_ids
 * @return array<int, array{album_count: int, image_count: int}>
 */
function get_category_subtree_counts(array $cat_ids): array  { return GalleryService::getCategorySubtreeCounts($cat_ids); }

// ── Album wrappers ────────────────────────────────────────────────────────────

function get_albums(int $category_id, string $sort = 'pos'): array { return GalleryService::getAlbums($category_id, $sort); }
function get_album(int $id): ?array                                 { return GalleryService::getAlbum($id); }
function increment_album_hits(int $album_id): void                  { GalleryService::incrementAlbumHits($album_id); }

// ── Image wrappers ────────────────────────────────────────────────────────────

function get_album_images(int $album_id, int $page = 1, int $per_page = 48, string $sort = 'pos'): array
{
    return GalleryService::getAlbumImages($album_id, $page, $per_page, $sort);
}

function count_album_images(int $album_id): int              { return GalleryService::countAlbumImages($album_id); }
function get_image(int $id): ?array                          { return GalleryService::getImage($id); }
function increment_image_hits(int $image_id): void           { GalleryService::incrementImageHits($image_id); }

/** @return array{prev: int|null, next: int|null} */
function get_image_neighbours(int $image_id, int $album_id, string $sort = 'pos'): array
{
    return GalleryService::getImageNeighbours($image_id, $album_id, $sort);
}

// ── Gallery-wide image query wrappers ─────────────────────────────────────────

function get_latest_updated_albums(int $limit = 5): array    { return GalleryService::getLatestUpdatedAlbums($limit); }
function get_most_viewed_images(int $limit = 48): array      { return GalleryService::getMostViewedImages($limit); }
function get_latest_images(int $limit = 48): array           { return GalleryService::getLatestImages($limit); }
function get_random_images(int $limit = 48): array           { return GalleryService::getRandomImages($limit); }

// ── Stats wrappers ────────────────────────────────────────────────────────────

/** @return array{categories: int, albums: int, images: int, total_hits: int} */
function get_gallery_stats(): array                          { return GalleryService::getGalleryStats(); }

// ── Visitor-tracking wrappers ─────────────────────────────────────────────────

function lumora_track_visitor(): void                        { GalleryService::trackVisitor(); }

/** @return array{online: int, record_count: int, record_date: string} */
function get_online_stats(): array                           { return GalleryService::getOnlineStats(); }

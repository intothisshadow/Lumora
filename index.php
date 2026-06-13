<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Homepage / Category browser
 *
 * Routes:
 *   /              → gallery home: recently updated albums + categories + latest additions + stats + who is online
 *   /?cat=N        → browse a category (sub-categories + albums)
 *   /?view=latest      → most recently added images
 *   /?view=most_viewed → all-time most viewed images
 *   /?view=random      → random selection
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);
require_once __DIR__ . '/include/bootstrap.php';

// ── Input sanitisation ────────────────────────────────────────────────────────
$cat_id  = lumora_int($_GET['cat']  ?? 0, 0, 0);
$view    = in_array($_GET['view'] ?? '', ['latest', 'most_viewed', 'random'], true)
    ? $_GET['view']
    : '';
$per_page = max(12, (int) lumora_config('per_page', 48));
$page    = lumora_int($_GET['page'] ?? 1, 1, 1);

// ── Track visitor for "Who Is Online" ─────────────────────────────────────────
lumora_track_visitor();

// ── Route ─────────────────────────────────────────────────────────────────────
$content    = '';
$page_title = '';

if ($view !== '') {
    // ── Special gallery-wide views ──────────────────────────────────────────
    $view_titles = [
        'latest'      => 'Latest Images',
        'most_viewed' => 'Most Viewed',
        'random'      => 'Random Images',
    ];
    $page_title = $view_titles[$view] . ' — ';

    $images = match ($view) {
        'latest'      => get_latest_images($per_page),
        'most_viewed' => get_most_viewed_images($per_page),
        default       => get_random_images($per_page),
    };

    $content = '<h2 class="lum-section-title">' . h($view_titles[$view]) . '</h2>'
        . lumora_render_thumbgrid($images)
        . lumora_render_lightbox_js(lumora_base_url());

} elseif ($cat_id > 0) {
    // ── Category page ─────────────────────────────────────────────────────
    $cat = get_category($cat_id);
    if (!$cat) {
        http_response_code(404);
        $content = '<div class="alert alert-warning">Category not found.</div>';
    } else {
        $page_title = h($cat['name']) . ' — ';
        $breadcrumb = lumora_render_breadcrumb(get_category_breadcrumb($cat_id));

        // Sub-categories
        $subcats = get_categories($cat_id);
        $albums  = get_albums($cat_id);

        $content = $breadcrumb;

        if (!empty($cat['description'])) {
            $content .= '<p class="text-muted mb-3">' . nl2br(h($cat['description'])) . '</p>';
        }

        if (!empty($subcats)) {
            $content .= '<h2 class="lum-section-title">Sub-categories</h2>'
                . lumora_render_categories($subcats);
        }

        if (!empty($albums)) {
            $content .= '<h2 class="lum-section-title mt-4">Albums</h2>'
                . lumora_render_catgrid($albums, 'album');
        }

        if (empty($subcats) && empty($albums)) {
            $content .= '<div class="alert alert-secondary">This category is empty.</div>';
        }
    }
} else {
    // ── Home page ─────────────────────────────────────────────────────────
    $stats               = get_gallery_stats();
    $root_cats           = get_categories(0);
    $latest              = get_latest_images(8);
    $latest_albums_count = max(0, (int) lumora_config('latest_albums_count', '5'));
    $latest_albums       = $latest_albums_count > 0 ? get_latest_updated_albums($latest_albums_count) : [];

    // 1. Recently Updated Albums (above categories)
    if (!empty($latest_albums)) {
        $content .= '<h2 class="lum-section-title">Recently Updated</h2>'
            . lumora_render_catgrid($latest_albums, 'album');
    }

    // 2. Root categories
    if (!empty($root_cats)) {
        $mt = !empty($latest_albums) ? ' mt-4' : '';
        $content .= '<h2 class="lum-section-title' . $mt . '">Categories</h2>'
            . lumora_render_categories($root_cats);
    }

    // 3. Latest Additions (thumbnail grid)
    if (!empty($latest)) {
        $base     = h(lumora_base_url());
        $content .= '<div class="d-flex justify-content-between align-items-center mt-4 mb-2">'
            . '<h2 class="lum-section-title mb-0">Latest Additions</h2>'
            . '<a href="' . $base . '?view=latest" class="btn btn-sm btn-outline-primary">View all</a>'
            . '</div>'
            . lumora_render_thumbgrid($latest)
            . lumora_render_lightbox_js(lumora_base_url());
    }

    // Empty gallery notice
    if (empty($root_cats) && empty($latest)) {
        $content .= '<div class="alert alert-info">The gallery is empty. '
            . '<a href="' . h(lumora_base_url() . 'admin/') . '">Add some content</a> to get started.</div>';
    }

    // 4. Stats (moved to bottom)
    $content .= '<hr class="mt-4">'
        . lumora_render_stats($stats);

    // 5. Who Is Online
    $content .= lumora_render_who_is_online();
}

lumora_render_page($content, ['{PAGE_TITLE}' => $page_title]);

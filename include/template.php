<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Template Engine
 *
 * lumora_render_page() loads the active theme's template.html, replaces
 * {PLACEHOLDER} tokens, and prints the result.
 *
 * All HTML-generation helper functions live here:
 *   lumora_render_thumbgrid(), lumora_render_catgrid(), lumora_render_breadcrumb(),
 *   lumora_render_pagination(), lumora_render_sort_controls(),
 *   lumora_render_stats(), lumora_render_who_is_online(), lumora_render_lightbox_js(),
 *   lumora_custom_header(), lumora_custom_footer(), lumora_render_nav()
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Page renderer ─────────────────────────────────────────────────────────────

/**
 * Render a complete HTML page using the active theme's template.html.
 *
 * Template tokens:
 *   {PAGE_TITLE}          - page-specific prefix, e.g. "Season 1 — "
 *   {GALLERY_NAME}        - from config
 *   {GALLERY_DESCRIPTION} - from config
 *   {THEME_URL}           - URL to active theme directory
 *   {BASE_URL}            - gallery base URL
 *   {LUMORA_VERSION}      - software version string
 *   {NAVIGATION}          - site navigation HTML
 *   {ADMIN_LINK}          - admin panel link (if admin is logged in)
 *   {CUSTOM_HEADER}       - optional custom header HTML
 *   {CUSTOM_FOOTER}       - optional custom footer HTML
 *   {POWERED_BY}          - Powered By credit HTML (empty when show_powered_by = 0)
 *   {CONTENT}             - main page content
 *   {CHARSET}             - always "utf-8"
 *
 * @param string $content   The main page HTML.
 * @param array  $extra     Additional token => value pairs to replace.
 */
function lumora_render_page(string $content, array $extra = []): void
{
    // ── Gallery offline mode ──────────────────────────────────────────────────
    // Non-admin visitors see a maintenance page. Admins always see the real
    // content so they can verify the gallery before bringing it back online.
    if (lumora_config('gallery_offline', '0') === '1' && !lumora_is_admin()) {
        http_response_code(503);
        header('Retry-After: 3600');
        $content = '<div class="alert alert-warning text-center my-5">'
            . '<h2>Gallery Offline</h2>'
            . '<p class="mb-0">This gallery is temporarily offline for maintenance. Please check back later.</p>'
            . '</div>';
        $extra = array_merge($extra, ['{PAGE_TITLE}' => 'Gallery Offline — ']);
    }

    $theme        = lumora_active_theme();
    $theme_path   = lumora_theme_path($theme);
    $tpl_file     = $theme_path . 'template.html';

    // Graceful fallback to default theme.
    if (!file_exists($tpl_file)) {
        $theme      = 'default';
        $theme_path = lumora_theme_path('default');
        $tpl_file   = $theme_path . 'template.html';
    }

    // Allow theme to override rendering functions via theme.php.
    $theme_php = $theme_path . 'theme.php';
    if (file_exists($theme_php)) {
        require_once $theme_php;
    }

    $template = file_get_contents($tpl_file);
    $base_url = lumora_base_url();

    $tokens = [
        '{GALLERY_NAME}'        => h(lumora_config('gallery_name',        'Lumora Gallery')),
        '{GALLERY_DESCRIPTION}' => h(lumora_config('gallery_description', '')),
        '{THEME_URL}'           => h(lumora_theme_url($theme)),
        '{BASE_URL}'            => h($base_url),
        '{LUMORA_VERSION}'      => LUMORA_VERSION,
        '{CHARSET}'             => 'utf-8',
        '{NAVIGATION}'          => lumora_render_nav(),
        '{ADMIN_LINK}'          => lumora_is_admin()
            ? '<a href="' . h($base_url . 'admin/') . '" class="lum-admin-link">&#9881; Admin</a>'
            : '',
        '{CUSTOM_HEADER}'       => lumora_custom_header(),
        '{CUSTOM_FOOTER}'       => lumora_custom_footer(),
        '{POWERED_BY}'          => lumora_render_powered_by(),
        '{CONTENT}'             => $content,
        '{PAGE_TITLE}'          => '',  // caller override expected
    ];

    // Caller-supplied tokens override defaults.
    $tokens = array_merge($tokens, $extra);

    echo str_replace(array_keys($tokens), array_values($tokens), $template);
}

// ── Navigation ────────────────────────────────────────────────────────────────

function lumora_render_nav(): string
{
    $u   = h(lumora_base_url());
    $nav = <<<HTML
<ul class="navbar-nav me-auto mb-2 mb-lg-0">
  <li class="nav-item"><a class="nav-link" href="{$u}">Home</a></li>
  <li class="nav-item"><a class="nav-link" href="{$u}?view=latest">Latest</a></li>
  <li class="nav-item"><a class="nav-link" href="{$u}?view=most_viewed">Most Viewed</a></li>
  <li class="nav-item"><a class="nav-link" href="{$u}?view=random">Random</a></li>
</ul>
HTML;
    return $nav;
}

// ── Custom header / footer ────────────────────────────────────────────────────

function lumora_custom_header(): string
{
    return lumora_load_custom_file((string) lumora_config('custom_header_path', ''));
}

function lumora_custom_footer(): string
{
    return lumora_load_custom_file((string) lumora_config('custom_footer_path', ''));
}

function lumora_render_powered_by(): string
{
    if (lumora_config('show_powered_by', '1') !== '1') {
        return '';
    }
    return '<small>Powered by '
        . '<a href="https://code.unloved-heart.net/lumora" rel="noopener">Lumora Gallery</a>'
        . '</small>';
}

/**
 * Load a custom HTML file from a config-supplied relative path.
 *
 * Uses realpath() to verify the resolved path is strictly within the gallery
 * root, preventing directory traversal attacks (e.g. "../../etc/passwd").
 *
 * @param string $path Relative path from LUMORA_ROOT (admin-supplied via config).
 */
function lumora_load_custom_file(string $path): string
{
    if ($path === '') return '';

    $root     = realpath(LUMORA_ROOT);
    $resolved = realpath(LUMORA_ROOT . ltrim($path, '/\\'));

    if ($root === false || $resolved === false) return '';

    // The resolved path must be strictly inside the gallery root directory.
    if (!str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) return '';

    $content = file_get_contents($resolved);
    return $content !== false ? $content : '';
}

// ── Breadcrumb ────────────────────────────────────────────────────────────────

/**
 * Render a Bootstrap breadcrumb trail.
 *
 * @param array       $cat_trail  Output of get_category_breadcrumb().
 * @param array|null  $album      Optional ['id'=>int,'title'=>string,'type'=>'album']
 * @param array|null  $image      Optional ['id'=>int,'title'=>string,'type'=>'image']
 */
function lumora_render_breadcrumb(array $cat_trail, ?array $album = null, ?array $image = null): string
{
    $base = lumora_base_url();
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    $html .= '<li class="breadcrumb-item"><a href="' . h($base) . '">Home</a></li>';

    foreach ($cat_trail as $crumb) {
        $html .= '<li class="breadcrumb-item"><a href="'
            . h($base . '?cat=' . (int) $crumb['id']) . '">'
            . h($crumb['name']) . '</a></li>';
    }

    if ($album && !$image) {
        $html .= '<li class="breadcrumb-item active" aria-current="page">' . h($album['title']) . '</li>';
    } elseif ($album) {
        $html .= '<li class="breadcrumb-item"><a href="'
            . h($base . 'album.php?album=' . (int) $album['id']) . '">'
            . h($album['title']) . '</a></li>';
    }

    if ($image) {
        $html .= '<li class="breadcrumb-item active" aria-current="page">' . h($image['title']) . '</li>';
    }

    $html .= '</ol></nav>';
    return $html;
}

// ── Gallery stats ─────────────────────────────────────────────────────────────

function lumora_render_stats(array $stats): string
{
    $c = number_format($stats['categories']);
    $a = number_format($stats['albums']);
    $i = number_format($stats['images']);
    $v = number_format($stats['total_hits']);
    return <<<HTML
<div class="lum-stats row text-center g-2 mb-4">
  <div class="col-6 col-md-3">
    <div class="lum-stat-box">
      <div class="lum-stat-num">{$c}</div>
      <div class="lum-stat-lbl">Categories</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lum-stat-box">
      <div class="lum-stat-num">{$a}</div>
      <div class="lum-stat-lbl">Albums</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lum-stat-box">
      <div class="lum-stat-num">{$i}</div>
      <div class="lum-stat-lbl">Images</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lum-stat-box">
      <div class="lum-stat-num">{$v}</div>
      <div class="lum-stat-lbl">Total Views</div>
    </div>
  </div>
</div>
HTML;
}

/**
 * Render the "Who Is Online" strip for the home page bottom.
 *
 * Calls get_online_stats() to fetch the live count and all-time record, then
 * renders a compact single-row strip below the stats boxes.
 * Returns an empty string when the {PREFIX}online table is absent (pre-v5
 * installs) so the home page degrades gracefully without errors.
 */
function lumora_render_who_is_online(): string
{
    $stats    = get_online_stats();
    $count    = (int) $stats['online'];
    $record   = (int) $stats['record_count'];
    $rec_date = (string) $stats['record_date'];
    $duration = max(1, (int) lumora_config('who_is_online_duration', '5'));

    $label = $count === 1 ? 'visitor' : 'visitors';
    $c     = number_format($count);
    $r     = number_format($record);
    $d_str = $duration === 1 ? '1 min' : $duration . ' min';

    $rec_html = '';
    if ($record > 0 && $rec_date !== '') {
        $rec_html = ' &mdash; record: <strong>' . $r . '</strong> on '
            . h(date('j M Y', (int) strtotime($rec_date)));
    } elseif ($record > 0) {
        $rec_html = ' &mdash; record: <strong>' . $r . '</strong>';
    }

    return <<<HTML
<div class="lum-who-is-online text-center text-muted small mb-4">
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="me-1" style="vertical-align:-1px" aria-hidden="true">
    <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
  </svg>
  <strong>{$c}</strong> {$label} online (last {$d_str}{$rec_html})
</div>
HTML;
}

// ── Thumbnail grid ────────────────────────────────────────────────────────────

/**
 * Render a responsive CSS-Grid thumbnail gallery with PhotoSwipe data attributes.
 *
 * Each image links directly to the full-size original; PhotoSwipe intercepts
 * the click and opens the lightbox without an intermediate page.
 *
 * Each anchor carries:
 *   href              — original image URL (PhotoSwipe src + fallback nav)
 *   data-pswp-width   — original image width in px
 *   data-pswp-height  — original image height in px
 *   data-download-url — same as href; used by the lightbox download button
 *   data-image-id     — database image ID; used by ajax_hit.php for view counting
 *
 * @param array $images     Rows from get_album_images() or similar (must include 'folder').
 * @param array $pagination Optional output of lumora_pagination().
 */
function lumora_render_thumbgrid(array $images, array $pagination = []): string
{
    if (empty($images)) {
        return '<div class="lum-empty alert alert-secondary">No images to display.</div>';
    }

    $html = '<div class="lum-thumbgrid" id="lum-gallery">';

    foreach ($images as $img) {
        $orig_url  = image_original_url($img);
        $thumb_url = image_thumb_url($img);
        $title     = ($img['title'] !== '') ? $img['title'] : pathinfo($img['filename'], PATHINFO_FILENAME);
        $res       = ((int) $img['width'] > 0 && (int) $img['height'] > 0)
            ? (int) $img['width'] . '×' . (int) $img['height']
            : '';
        $views     = number_format((int) $img['hits']);
        $w         = (int) $img['width'];
        $h         = (int) $img['height'];
        $img_id    = (int) $img['id'];

        $html .= '<figure class="lum-thumb-item">';
        $html .= '<a href="' . h($orig_url) . '"'
            . ' data-pswp-width="' . $w . '"'
            . ' data-pswp-height="' . $h . '"'
            . ' data-download-url="' . h($orig_url) . '"'
            . ' data-image-id="' . $img_id . '"'
            . ' target="_blank">';
        $html .= '<img src="' . h($thumb_url) . '" alt="' . h($title) . '" loading="lazy">';
        $html .= '</a>';
        $html .= '<figcaption class="lum-thumb-caption">';
        if ($res) $html .= '<span class="lum-resolution">' . $res . '</span>';
        $html .= '<span class="lum-views">' . $views . ' views</span>';
        $html .= '</figcaption>';
        $html .= '</figure>';
    }

    $html .= '</div>'; // .lum-thumbgrid

    if (!empty($pagination) && $pagination['total_pages'] > 1) {
        $html .= lumora_render_pagination($pagination);
    }

    return $html;
}

// ── Pagination ────────────────────────────────────────────────────────────────

function lumora_render_pagination(array $p): string
{
    if ($p['total_pages'] <= 1) return '';

    $html  = '<nav class="lum-pagination" aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center flex-wrap">';

    if ($p['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($p['prev_url']) . '">&laquo;</a></li>';
    }

    $start = max(1, $p['current_page'] - 4);
    $end   = min($p['total_pages'], $p['current_page'] + 5);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h(sprintf($p['url_pattern'], 1)) . '">1</a></li>';
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $p['current_page']) ? ' active" aria-current="page' : '';
        $html .= '<li class="page-item' . $active . '">'
            . '<a class="page-link" href="' . h(sprintf($p['url_pattern'], $i)) . '">' . $i . '</a></li>';
    }

    if ($end < $p['total_pages']) {
        if ($end < $p['total_pages'] - 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . h(sprintf($p['url_pattern'], $p['total_pages'])) . '">' . $p['total_pages'] . '</a></li>';
    }

    if ($p['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($p['next_url']) . '">&raquo;</a></li>';
    }

    $html .= '</ul>';
    $html .= '<p class="text-center text-muted small mt-1">Showing '
        . number_format($p['start_item']) . '–' . number_format($p['end_item'])
        . ' of ' . number_format($p['total']) . ' images</p>';
    $html .= '</nav>';
    return $html;
}

// ── Category / album card grid ────────────────────────────────────────────────

/**
 * Render a Bootstrap card grid for categories or albums.
 *
 * @param array  $items  Rows from get_categories() or get_albums().
 * @param string $type   'category' or 'album'.
 */
function lumora_render_catgrid(array $items, string $type = 'category'): string
{
    if (empty($items)) {
        return '<div class="lum-empty alert alert-secondary">No ' . h($type) . 's found.</div>';
    }

    $base = lumora_base_url();
    $html = '<div class="lum-catgrid row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">';

    foreach ($items as $item) {
        if ($type === 'album') {
            $url   = h($base . 'album.php?album=' . (int) $item['id']);
            $title = h($item['title']);
            $count = isset($item['image_count'])
                ? number_format((int) $item['image_count']) . ' image' . ((int) $item['image_count'] !== 1 ? 's' : '')
                : '';
        } else {
            $url   = h($base . '?cat=' . (int) $item['id']);
            $title = h($item['name']);
            $parts = [];
            if (!empty($item['subcategory_count']) && (int) $item['subcategory_count'] > 0) {
                $parts[] = (int) $item['subcategory_count'] . ' sub-' . ((int)$item['subcategory_count'] !== 1 ? 'categories' : 'category');
            }
            if (!empty($item['album_count'])) {
                $parts[] = number_format((int) $item['album_count']) . ' album' . ((int) $item['album_count'] !== 1 ? 's' : '');
            }
            $count = implode(', ', $parts);
        }

        $thumb_html = lumora_render_item_thumb($item, $type, $url);
        $desc_html  = !empty($item['description'])
            ? '<p class="lum-card-desc text-muted small mb-0">' . h($item['description']) . '</p>'
            : '';

        $html .= <<<HTML
<div class="col">
  <div class="card h-100 lum-catcard">
    {$thumb_html}
    <div class="card-body p-2">
      <h6 class="card-title mb-0"><a href="{$url}">{$title}</a></h6>
      <small class="text-muted">{$count}</small>
      {$desc_html}
    </div>
  </div>
</div>
HTML;
    }

    $html .= '</div>';
    return $html;
}

/**
 * Render a row-based category list (Coppermine-style list layout).
 *
 * Each category is displayed as one row with four columns:
 *   1. Thumbnail
 *   2. Category name + description
 *   3. Album count (recursive — includes all descendant subcategories)
 *   4. Image count (recursive — includes all descendant subcategories)
 *
 * Counts are fetched via get_category_subtree_counts() which resolves the
 * full subtree for each category in three queries total, regardless of
 * tree depth or category count.
 *
 * Only makes sense for categories; use lumora_render_catgrid() for albums.
 *
 * @param array $items  Rows from get_categories().
 */
function lumora_render_catlist(array $items): string
{
    if (empty($items)) {
        return '<div class="lum-empty alert alert-secondary">No categories found.</div>';
    }

    // Fetch tree-wide counts (all descendant subcategories included).
    $cat_ids     = array_map(fn(array $item): int => (int) $item['id'], $items);
    $tree_counts = get_category_subtree_counts($cat_ids);

    $base = lumora_base_url();
    $html = '<div class="lum-catlist">';

    // Header row
    $html .= '<div class="lum-catlist-header">';
    $html .= '<div class="lum-catlist-header-cell lum-catlist-header-cell--thumb"></div>';
    $html .= '<div class="lum-catlist-header-cell lum-catlist-header-cell--name">Category</div>';
    $html .= '<div class="lum-catlist-header-cell lum-catlist-header-cell--albums">Albums</div>';
    $html .= '<div class="lum-catlist-header-cell lum-catlist-header-cell--images">Images</div>';
    $html .= '</div>';

    foreach ($items as $item) {
        $cat_id = (int) $item['id'];
        $url    = h($base . '?cat=' . $cat_id);
        $title  = h($item['name']);
        $desc   = !empty($item['description'])
            ? '<div class="lum-catlist-desc">' . nl2br(h($item['description'])) . '</div>'
            : '';

        $tc     = $tree_counts[$cat_id] ?? ['album_count' => 0, 'image_count' => 0];
        $albums = number_format($tc['album_count']);
        $images = number_format($tc['image_count']);

        $thumb_html = lumora_render_item_thumb($item, 'category', $url);

        $html .= '<div class="lum-catlist-row">';
        $html .= '<div class="lum-catlist-col-thumb">' . $thumb_html . '</div>';
        $html .= '<div class="lum-catlist-col-name"><a href="' . $url . '">' . $title . '</a>' . $desc . '</div>';
        $html .= '<div class="lum-catlist-col-albums">' . $albums . '</div>';
        $html .= '<div class="lum-catlist-col-images">' . $images . '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Render categories using the layout configured in `category_layout` config key.
 *
 * 'grid' (default) → lumora_render_catgrid() (Bootstrap card grid)
 * 'list'           → lumora_render_catlist() (Coppermine-style row table)
 *
 * Always use this function for rendering categories on public pages rather than
 * calling lumora_render_catgrid() directly, so the admin's layout preference is
 * honoured everywhere.
 *
 * @param array $items  Rows from get_categories().
 */
function lumora_render_categories(array $items): string
{
    if (lumora_config('category_layout', 'grid') === 'list') {
        return lumora_render_catlist($items);
    }
    return lumora_render_catgrid($items, 'category');
}

/**
 * Return the cover thumbnail HTML for a category or album card.
 * Tries the configured thumb_image_id, then the first image in the album.
 */
function lumora_render_item_thumb(array $item, string $type, string $url): string
{
    $thumb_url = null;

    if ($type === 'album') {
        if (!empty($item['thumb_image_id'])) {
            $row = LumoraDB::fetchOne(
                'SELECT i.filename, a.folder FROM `{PREFIX}images` i
                 JOIN `{PREFIX}albums` a ON a.id = i.album_id
                 WHERE i.id = ? AND i.approved = 1',
                [(int) $item['thumb_image_id']]
            );
            if ($row) $thumb_url = image_thumb_url($row);
        }

        if (!$thumb_url) {
            $row = LumoraDB::fetchOne(
                'SELECT i.filename, a.folder FROM `{PREFIX}images` i
                 JOIN `{PREFIX}albums` a ON a.id = i.album_id
                 WHERE i.album_id = ? AND i.approved = 1
                 ORDER BY i.pos ASC, i.id ASC LIMIT 1',
                [(int) $item['id']]
            );
            if ($row) $thumb_url = image_thumb_url($row);
        }
    } elseif ($type === 'category') {
        // Use an explicitly configured cover image if set.
        if (!empty($item['thumb_image_id'])) {
            $row = LumoraDB::fetchOne(
                'SELECT i.filename, a.folder FROM `{PREFIX}images` i
                 JOIN `{PREFIX}albums` a ON a.id = i.album_id
                 WHERE i.id = ? AND i.approved = 1',
                [(int) $item['thumb_image_id']]
            );
            if ($row) $thumb_url = image_thumb_url($row);
        }

        // Fall back to the first image in any public album in this category.
        if (!$thumb_url) {
            $row = LumoraDB::fetchOne(
                'SELECT i.filename, a.folder FROM `{PREFIX}images` i
                 JOIN `{PREFIX}albums` a ON a.id = i.album_id
                 WHERE a.category_id = ? AND a.visibility = 0 AND i.approved = 1
                 ORDER BY i.pos ASC, i.id ASC LIMIT 1',
                [(int) $item['id']]
            );
            if ($row) $thumb_url = image_thumb_url($row);
        }
    }

    if ($thumb_url) {
        return '<a href="' . $url . '">'
            . '<img src="' . h($thumb_url) . '" class="card-img-top lum-catcard-img" alt="" loading="lazy">'
            . '</a>';
    }

    // SVG placeholder.
    return '<a href="' . $url . '" class="lum-catcard-placeholder d-flex align-items-center justify-content-center">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" viewBox="0 0 16 16">'
        . '<path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>'
        . '<path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1z"/>'
        . '</svg></a>';
}

// ── Sort controls ─────────────────────────────────────────────────────────────

/**
 * Render a "Sort by" button group for album views.
 *
 * @param string $current   Current sort key.
 * @param string $base_url  URL prefix to append the sort value to (e.g. 'album.php?album=3&sort=').
 */
function lumora_render_sort_controls(string $current, string $base_url): string
{
    $sorts = [
        'pos'         => 'Default',
        'newest'      => 'Newest',
        'oldest'      => 'Oldest',
        'most_viewed' => 'Most Viewed',
        'filename'    => 'Filename',
    ];

    $html = '<div class="lum-sort-bar d-flex align-items-center gap-2 mb-3 flex-wrap">';
    $html .= '<span class="text-muted small">Sort:</span>';
    $html .= '<div class="btn-group btn-group-sm">';

    foreach ($sorts as $key => $label) {
        $active = ($key === $current) ? ' active' : '';
        $html .= '<a href="' . h($base_url . $key) . '" class="btn btn-outline-secondary' . $active . '">' . $label . '</a>';
    }

    $html .= '</div></div>';
    return $html;
}

// ── PhotoSwipe lightbox init ──────────────────────────────────────────────────

/**
 * Return the inline <script> that initialises PhotoSwipe 5 on #lum-gallery.
 *
 * Requires the PhotoSwipe CSS to be loaded in the template (the default
 * template.html loads it from jsDelivr CDN). The JS is loaded as an ESM import
 * directly from the module script below — no separate <script src> tag needed.
 *
 * Each gallery <a> must carry:
 *   href              original image URL
 *   data-pswp-width   original width in px
 *   data-pswp-height  original height in px
 *   data-image-id     DB image ID (used by ajax_hit.php for view counting)
 *
 * When each image is displayed in the lightbox a fire-and-forget POST is sent
 * to ajax_hit.php. The server throttles this to one count per image per session
 * so rapidly opening the same image does not inflate its view counter.
 *
 * @param string $base_url  Gallery base URL with trailing slash (from lumora_base_url()).
 *                          Used to build the absolute URL for ajax_hit.php so the
 *                          endpoint resolves correctly regardless of subdirectory depth.
 */
function lumora_render_lightbox_js(string $base_url = ''): string
{
    // Encode the hit endpoint URL for safe embedding in a JS string literal.
    // json_encode adds surrounding quotes and escapes all special characters.
    $hit_url_js = json_encode($base_url . 'ajax_hit.php');

    // A tiny non-module <script> block that writes the endpoint URL into
    // window.__lumHitUrl before the ESM module below runs.  The ESM module
    // reads this global rather than needing a PHP variable inside the module
    // body (which would require switching from nowdoc to heredoc and risk
    // accidentally interpolating any JS identifier that starts with $).
    $setup = '<script>window.__lumHitUrl = ' . $hit_url_js . ';</script>' . "\n";

    return $setup . <<<'LIGHTBOX'
<script type="module">
(async function () {
  'use strict';

  // PhotoSwipe 5 — loaded as ESM; no global required, no CDN script tag needed.
  const { default: PhotoSwipe } = await import(
    'https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.esm.min.js'
  );

  const gallery = document.getElementById('lum-gallery');
  if (!gallery) return;

  const links = Array.from(gallery.querySelectorAll('a[data-pswp-width]'));
  if (!links.length) return;

  gallery.addEventListener('click', function (e) {
    const link = e.target.closest('a[data-pswp-width]');
    if (!link) return;
    e.preventDefault();

    const items = links.map(function (a) {
      return {
        src:         a.href,
        width:       parseInt(a.dataset.pswpWidth,  10) || 800,
        height:      parseInt(a.dataset.pswpHeight, 10) || 600,
        alt:         a.querySelector('img') ? a.querySelector('img').alt : '',
        downloadUrl: a.dataset.downloadUrl || a.href,
        imageId:     parseInt(a.dataset.imageId, 10) || 0,
      };
    });

    const pswp = new PhotoSwipe({
      dataSource:            items,
      index:                 links.indexOf(link),
      showHideAnimationType: 'zoom',
      bgOpacity:             0.9,
    });

    // ── Download button ───────────────────────────────────────────────────
    pswp.on('uiRegister', function () {
      pswp.ui.registerElement({
        name:     'download-button',
        title:    'Download original',
        order:    8,
        isButton: true,
        tagName:  'a',
        html:     '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="32" height="32"><path d="M12 16l-5-5 1.4-1.4 2.6 2.6V4h2v8.2l2.6-2.6L17 11zm-8 4h16v2H4z" fill="currentColor"/></svg>',
        onInit: function (el, pswp) {
          el.setAttribute('download', '');
          el.setAttribute('rel', 'noopener');
          pswp.on('change', function () {
            el.href = pswp.currSlide.data.downloadUrl || pswp.currSlide.data.src;
          });
        },
      });
    });

    // ── View counter ──────────────────────────────────────────────────────
    // Fires on every slide change (including the initial slide when the
    // lightbox opens). Sends a fire-and-forget POST to ajax_hit.php; the
    // server increments the counter once per image per session.
    pswp.on('change', function () {
      var imgId = pswp.currSlide && pswp.currSlide.data
        ? pswp.currSlide.data.imageId
        : 0;
      if (imgId && window.__lumHitUrl) {
        var xh = new XMLHttpRequest();
        xh.open('POST', window.__lumHitUrl, true);
        xh.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xh.send('image_id=' + encodeURIComponent(imgId));
        // Response is intentionally ignored — this is fire-and-forget.
      }
    });

    pswp.init();
  });
}());
</script>
LIGHTBOX;
}

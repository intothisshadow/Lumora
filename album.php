<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Album view
 *
 * URL: album.php?album=N[&sort=pos|newest|oldest|most_viewed|filename][&page=N]
 *
 * Displays paginated thumbnail grid for an album.
 * Increments album hit counter when count_album_views is enabled
 * (session-throttled to one hit per album per visit).
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);
require_once __DIR__ . '/include/bootstrap.php';

// ── Input ─────────────────────────────────────────────────────────────────────
$album_id = lumora_int($_GET['album'] ?? 0, 0, 1);
$sort     = in_array($_GET['sort'] ?? '', ['pos', 'newest', 'oldest', 'most_viewed', 'filename'], true)
    ? $_GET['sort']
    : 'pos';
$page     = lumora_int($_GET['page'] ?? 1, 1, 1);
$per_page = max(12, (int) lumora_config('per_page', 48));

// ── Load album ────────────────────────────────────────────────────────────────
if ($album_id === 0) {
    lumora_redirect(lumora_base_url());
}

$album = get_album($album_id);
if (!$album) {
    http_response_code(404);
    lumora_render_page('<div class="alert alert-warning">Album not found.</div>');
    exit;
}

// ── Album hit counter (session-throttled, honoring count_album_views config) ─
if (lumora_config('count_album_views', '1') === '1') {
    $hit_key = 'alb_hit_' . $album_id;
    if (empty($_SESSION[$hit_key])) {
        increment_album_hits($album_id);
        $_SESSION[$hit_key] = true;
    }
}

// ── Track visitor for "Who Is Online" ─────────────────────────────────
lumora_track_visitor();

// ── Logging ───────────────────────────────────────────────────────────────────
lumora_log('visit', 'album ' . $album_id . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));

// ── Category breadcrumb ───────────────────────────────────────────────────────
$cat_trail  = get_category_breadcrumb((int) $album['category_id']);
$breadcrumb = lumora_render_breadcrumb(
    $cat_trail,
    ['id' => $album_id, 'title' => $album['title']]
);

// ── Images ────────────────────────────────────────────────────────────────────
$total  = count_album_images($album_id);
$images = get_album_images($album_id, $page, $per_page, $sort);

$base_url = lumora_base_url();
$url_pat  = $base_url . 'album.php?album=' . $album_id . '&sort=' . $sort . '&page=%d';
$pag      = lumora_pagination($total, $per_page, $page, $url_pat);

$sort_base = $base_url . 'album.php?album=' . $album_id . '&sort=';

// ── Build page ────────────────────────────────────────────────────────────────
$desc_html = !empty($album['description'])
    ? '<p class="lum-album-desc">' . nl2br(h($album['description'])) . '</p>'
    : '';

$meta = '<small class="text-muted">'
    . number_format($total) . ' image' . ($total !== 1 ? 's' : '')
    . ' &mdash; ' . number_format((int) $album['hits']) . ' album views'
    . '</small>';

$content = $breadcrumb
    . '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">'
    .   '<div><h1 class="h4 mb-0">' . h($album['title']) . '</h1>' . $meta . '</div>'
    . '</div>'
    . $desc_html
    . lumora_render_sort_controls($sort, $sort_base)
    . lumora_render_thumbgrid($images, $pag)
    . lumora_render_lightbox_js($base_url);

lumora_render_page($content, [
    '{PAGE_TITLE}' => h($album['title']) . ' — ',
]);

<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Template Engine (legacy forwarding wrappers)
 *
 * All rendering logic now lives in ThemeRenderer (include/services/ThemeRenderer.php).
 * This file exists solely to provide backward-compatible free-function names so that
 * existing callers (public pages, admin pages, themes) require no changes.
 *
 * New V2 code should call ThemeRenderer:: directly.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Page renderer ─────────────────────────────────────────────────────────────

function lumora_render_page(string $content, array $extra = []): void
{
    ThemeRenderer::renderPage($content, $extra);
}

// ── Navigation ────────────────────────────────────────────────────────────────

function lumora_render_nav(): string               { return ThemeRenderer::renderNav(); }

// ── Custom header / footer ────────────────────────────────────────────────────

function lumora_custom_header(): string            { return ThemeRenderer::customHeader(); }
function lumora_custom_footer(): string            { return ThemeRenderer::customFooter(); }
function lumora_render_powered_by(): string        { return ThemeRenderer::renderPoweredBy(); }

// ── Breadcrumb ────────────────────────────────────────────────────────────────

function lumora_render_breadcrumb(array $cat_trail, ?array $album = null, ?array $image = null): string
{
    return ThemeRenderer::renderBreadcrumb($cat_trail, $album, $image);
}

// ── Gallery stats ─────────────────────────────────────────────────────────────

function lumora_render_stats(array $stats): string                    { return ThemeRenderer::renderStats($stats); }
function lumora_render_who_is_online(): string                        { return ThemeRenderer::renderWhoIsOnline(); }

// ── Thumbnail grid ────────────────────────────────────────────────────────────

function lumora_render_thumbgrid(array $images, array $pagination = []): string
{
    return ThemeRenderer::renderThumbgrid($images, $pagination);
}

// ── Pagination ────────────────────────────────────────────────────────────────

function lumora_render_pagination(array $p): string                   { return ThemeRenderer::renderPagination($p); }

// ── Category / album card grid ────────────────────────────────────────────────

function lumora_render_catgrid(array $items, string $type = 'category'): string
{
    return ThemeRenderer::renderCatgrid($items, $type);
}

function lumora_render_catlist(array $items): string                  { return ThemeRenderer::renderCatlist($items); }
function lumora_render_categories(array $items): string               { return ThemeRenderer::renderCategories($items); }

function lumora_render_item_thumb(array $item, string $type, string $url): string
{
    return ThemeRenderer::renderItemThumb($item, $type, $url);
}

// ── Sort controls ─────────────────────────────────────────────────────────────

function lumora_render_sort_controls(string $current, string $base_url): string
{
    return ThemeRenderer::renderSortControls($current, $base_url);
}

// ── PhotoSwipe lightbox init ──────────────────────────────────────────────────

function lumora_render_lightbox_js(string $base_url = ''): string
{
    return ThemeRenderer::renderLightboxJs($base_url);
}

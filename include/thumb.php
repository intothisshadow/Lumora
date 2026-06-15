<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Thumbnail Generation (legacy forwarding wrappers)
 *
 * All thumbnail, image-processing, and batch-add logic now lives in
 * ThumbnailService (include/services/ThumbnailService.php).
 * This file exists solely to provide backward-compatible free-function names
 * so that existing callers (admin pages, AJAX handlers) require no changes.
 *
 * New V2 code should call ThumbnailService:: directly.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Thumbnail generation ──────────────────────────────────────────────────────

function lumora_generate_thumb(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    bool   $strip   = true,
    int    $quality = 0
): bool {
    return ThumbnailService::generateThumb($source, $dest, $max_w, $max_h, $strip, $quality);
}

// ── Original image resizing ───────────────────────────────────────────────────

function lumora_resize_original(string $path, int $max_w, int $max_h): bool
{
    return ThumbnailService::resizeOriginal($path, $max_w, $max_h);
}

// ── Image metadata ────────────────────────────────────────────────────────────

/** @return array{int, int} */
function lumora_get_image_dimensions(string $filepath): array { return ThumbnailService::getImageDimensions($filepath); }
function lumora_get_filesize(string $filepath): int           { return ThumbnailService::getFilesize($filepath); }

// ── Extension validation ──────────────────────────────────────────────────────

/** @return string[] */
function lumora_allowed_extensions(): array                   { return ThumbnailService::getAllowedExtensions(); }
function lumora_is_allowed_image(string $filename): bool      { return ThumbnailService::isAllowedImage($filename); }

// ── Batch add helpers ─────────────────────────────────────────────────────────

/** @return string[] */
function lumora_scan_new_images(string $folder, int $album_id): array
{
    return ThumbnailService::scanNewImages($folder, $album_id);
}

function lumora_batch_add_image(string $filename, string $folder, int $album_id): int|false
{
    return ThumbnailService::batchAddImage($filename, $folder, $album_id);
}

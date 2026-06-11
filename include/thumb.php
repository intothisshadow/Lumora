<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Thumbnail Generation
 *
 * Tries the Imagick PHP extension first (preferred), falls back to GD.
 * Also provides helpers for reading image dimensions, scanning album folders,
 * and the per-image batch-add processing step.
 *
 * Configuration keys used by this file:
 *   thumb_quality       — JPEG/WebP quality for generated thumbnails (1–100, default 85)
 *   thumb_width         — max thumbnail width in px (default 250)
 *   thumb_height        — max thumbnail height in px (default 250)
 *   max_upload_size_mb  — maximum original file size in MB; 0 = unlimited (default 0)
 *   max_image_width     — maximum width for stored originals in px; 0 = unlimited (default 0)
 *   max_image_height    — maximum height for stored originals in px; 0 = unlimited (default 0)
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Thumbnail generation ──────────────────────────────────────────────────────

/**
 * Generate a thumbnail for $source, writing it to $dest.
 * Tries the Imagick PHP extension first; falls back to GD if unavailable.
 *
 * @param string $source  Absolute path to the source image.
 * @param string $dest    Absolute path where the thumbnail should be written.
 * @param int    $max_w   Maximum thumbnail width in pixels.
 * @param int    $max_h   Maximum thumbnail height in pixels.
 * @param bool   $strip   Strip metadata (EXIF, ICC profile) from the thumbnail.
 * @param int    $quality JPEG/WebP quality 1–100. 0 = read from config (thumb_quality).
 * @return bool True on success.
 */
function lumora_generate_thumb(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    bool   $strip = true,
    int    $quality = 0
): bool {
    // quality 0 means "read from config" — allows callers to override
    // (e.g. lumora_resize_original() uses a higher quality for originals).
    if ($quality <= 0) {
        $quality = max(1, min(100, (int) lumora_config('thumb_quality', 85)));
    }

    if (extension_loaded('imagick')) {
        return lumora_thumb_imagick($source, $dest, $max_w, $max_h, $strip, $quality);
    }

    if (extension_loaded('gd')) {
        return lumora_thumb_gd($source, $dest, $max_w, $max_h, $quality);
    }

    return false;
}

/**
 * Generate a thumbnail using the Imagick PHP extension (preferred).
 *
 * - autoOrient()      corrects EXIF rotation before resizing.
 * - thumbnailImage()  high-quality Lanczos resize.
 * - [0] frame         selects the first frame of animated GIFs.
 * - Does NOT upscale: images already within bounds are only stripped/recompressed.
 *
 * @param int $quality JPEG/WebP quality 1–100.
 */
function lumora_thumb_imagick(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    bool   $strip = true,
    int    $quality = 85
): bool {
    try {
        // [0] selects the first frame of animated GIFs / multi-page documents.
        $img = new \Imagick($source . '[0]');
        $img->autoOrient();

        $orig_w = $img->getImageWidth();
        $orig_h = $img->getImageHeight();

        // Only resize when the image actually exceeds the max dimensions.
        // thumbnailImage with $fit=true fits within the box preserving aspect ratio.
        if ($orig_w > $max_w || $orig_h > $max_h) {
            $img->thumbnailImage($max_w, $max_h, true);
        }

        if ($strip) {
            $img->stripImage();
        }

        $img->setImageCompressionQuality($quality);
        $img->writeImage($dest);
        $img->destroy();

        return file_exists($dest);
    } catch (\ImagickException $e) {
        error_log('Lumora: Imagick thumb failed for ' . $source . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate a thumbnail using the GD library (fallback).
 * Maintains aspect ratio; does not upscale.
 *
 * @param int $quality JPEG/WebP quality 1–100. PNG always uses compression level 7.
 */
function lumora_thumb_gd(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    int    $quality = 85
): bool {
    $info = getimagesize($source);
    if (!$info) return false;

    [$src_w, $src_h, $type] = $info;

    $src = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => imagecreatefrompng($source),
        IMAGETYPE_GIF  => imagecreatefromgif($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($source) : false,
        default        => false,
    };
    if (!$src) return false;

    $ratio  = min(1.0, $max_w / $src_w, $max_h / $src_h); // no upscaling
    $dst_w  = max(1, (int) round($src_w * $ratio));
    $dst_h  = max(1, (int) round($src_h * $ratio));

    $dst = imagecreatetruecolor($dst_w, $dst_h);

    // Preserve transparency for PNG / GIF / WebP.
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $dst_w, $dst_h, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $dest, $quality),
        IMAGETYPE_PNG  => imagepng($dst, $dest, 7),  // PNG is lossless; 0-9 compression level
        IMAGETYPE_GIF  => imagegif($dst, $dest),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $dest, $quality) : imagejpeg($dst, $dest, $quality),
        default        => false,
    };

    imagedestroy($src);
    imagedestroy($dst);

    return $ok && file_exists($dest);
}

// ── Original image resizing ───────────────────────────────────────────────────

/**
 * Downscale an original image file in-place to fit within the given maximum
 * dimensions. Used by lumora_batch_add_image() when max_image_width or
 * max_image_height are configured.
 *
 * - Never upscales; returns true immediately if the image is already within limits.
 * - Uses a randomly-named temp file in sys_get_temp_dir(), then renames/copies
 *   it over the original so the operation is as close to atomic as possible.
 * - Uses quality 92 (high quality for originals, independent of thumb_quality).
 * - strip = false to preserve EXIF data in the stored original.
 *
 * @param string $path  Absolute filesystem path to the image file.
 * @param int    $max_w Maximum width in px. 0 = no constraint on this axis.
 * @param int    $max_h Maximum height in px. 0 = no constraint on this axis.
 * @return bool True on success or when no resize was needed; false on failure.
 */
function lumora_resize_original(string $path, int $max_w, int $max_h): bool
{
    // Both limits disabled — nothing to do.
    if ($max_w <= 0 && $max_h <= 0) return true;

    [$orig_w, $orig_h] = lumora_get_image_dimensions($path);
    if ($orig_w === 0 || $orig_h === 0) return false;

    // Treat 0 on a single axis as "no constraint": use a very large value so
    // the image is only constrained by the axis that has a real limit.
    $limit_w = $max_w > 0 ? $max_w : 65535;
    $limit_h = $max_h > 0 ? $max_h : 65535;

    // Already within limits — nothing to do.
    if ($orig_w <= $limit_w && $orig_h <= $limit_h) return true;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
         . 'lum_resize_' . bin2hex(random_bytes(6)) . '.' . $ext;

    // Quality 92 for high-quality originals; strip=false to preserve EXIF.
    $ok = lumora_generate_thumb($path, $tmp, $limit_w, $limit_h, false, 92);

    if (!$ok || !file_exists($tmp)) {
        if (file_exists($tmp)) unlink($tmp);
        error_log('Lumora: lumora_resize_original failed to generate resized version of ' . $path);
        return false;
    }

    // Try atomic rename first (fast; works when src and dest are on the same fs).
    // Fall back to copy+unlink for cross-filesystem moves (e.g. /tmp on tmpfs).
    if (!rename($tmp, $path)) {
        if (!copy($tmp, $path)) {
            unlink($tmp);
            error_log('Lumora: Could not move resized original over ' . $path);
            return false;
        }
        unlink($tmp);
    }

    return true;
}

// ── Image metadata ────────────────────────────────────────────────────────────

/**
 * Read image dimensions without loading the full image.
 * Returns [width, height] or [0, 0] on failure.
 *
 * @return array{int, int}
 */
function lumora_get_image_dimensions(string $filepath): array
{
    if (!is_file($filepath)) return [0, 0];
    $info = getimagesize($filepath);
    return $info !== false ? [(int) $info[0], (int) $info[1]] : [0, 0];
}

/**
 * Return the filesize in bytes, or 0 on failure.
 */
function lumora_get_filesize(string $filepath): int
{
    if (!is_file($filepath)) return 0;
    $size = filesize($filepath);
    return $size !== false ? (int) $size : 0;
}

// ── Extension validation ──────────────────────────────────────────────────────

/**
 * Return the list of allowed image extensions from config.
 * @return string[]
 */
function lumora_allowed_extensions(): array
{
    $raw = lumora_config('allowed_extensions', 'jpg,jpeg,png,gif,webp');
    return array_map('strtolower', array_map('trim', explode(',', $raw)));
}

/**
 * Return true if the filename has an allowed image extension.
 */
function lumora_is_allowed_image(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, lumora_allowed_extensions(), true);
}

// ── Batch add helpers ─────────────────────────────────────────────────────────

/**
 * Scan an album's folder for image files that are not yet in the database.
 * Skips files whose names start with any known prefix (thumb_, normal_, etc.).
 *
 * Returns a naturally-sorted array of bare filenames.
 *
 * @return string[]
 */
function lumora_scan_new_images(string $folder, int $album_id): array
{
    $dir = lumora_album_path($folder);
    if (!is_dir($dir)) return [];

    // Fetch filenames already in DB for this album (one indexed query).
    $existing = array_flip(array_column(
        LumoraDB::fetchAll('SELECT filename FROM `{PREFIX}images` WHERE album_id = ?', [$album_id]),
        'filename'
    ));

    $skip_prefixes = [LUMORA_THUMB_PREFIX, 'normal_', 'mid_'];
    $allowed       = lumora_allowed_extensions();
    $new           = [];

    foreach (new DirectoryIterator($dir) as $file) {
        if (!$file->isFile()) continue;

        $name = $file->getFilename();
        $ext  = strtolower($file->getExtension());

        if (!in_array($ext, $allowed, true)) continue;

        // Skip generated variants.
        foreach ($skip_prefixes as $pfx) {
            if (str_starts_with($name, $pfx)) continue 2;
        }

        if (isset($existing[$name])) continue; // already in DB

        $new[] = $name;
    }

    natsort($new);
    return array_values($new);
}

/**
 * Process a single image for batch-add:
 *   0. Check file size against max_upload_size_mb (skip if exceeded)
 *   1. Downscale original in-place if it exceeds max_image_width / max_image_height
 *   2. Generate thumbnail
 *   3. Read dimensions and filesize (after any resize)
 *   4. Insert into DB
 *
 * Returns the new image ID on success, or false on failure.
 */
function lumora_batch_add_image(string $filename, string $folder, int $album_id): int|false
{
    $original_path = lumora_album_path($folder) . $filename;
    if (!file_exists($original_path)) return false;

    // ── 0. Max upload size check ─────────────────────────────────────────────
    $max_mb = (int) lumora_config('max_upload_size_mb', 0);
    if ($max_mb > 0) {
        $file_bytes = lumora_get_filesize($original_path);
        if ($file_bytes > $max_mb * 1024 * 1024) {
            error_log(sprintf(
                'Lumora: Skipping "%s" — file size %s exceeds limit of %d MB.',
                $filename,
                lumora_format_bytes($file_bytes),
                $max_mb
            ));
            return false;
        }
    }

    // ── 1. Downscale original if needed ──────────────────────────────────────
    $max_img_w = (int) lumora_config('max_image_width',  0);
    $max_img_h = (int) lumora_config('max_image_height', 0);
    if ($max_img_w > 0 || $max_img_h > 0) {
        lumora_resize_original($original_path, $max_img_w, $max_img_h);
        // Failure is non-fatal: we store the original dimensions on error.
    }

    // ── 2. Generate thumbnail ────────────────────────────────────────────────
    $thumb_w = (int) lumora_config('thumb_width',  250);
    $thumb_h = (int) lumora_config('thumb_height', 250);
    $thumb_p = lumora_album_path($folder) . LUMORA_THUMB_PREFIX . $filename;

    // Thumbnail generation is non-fatal; dimensions are still recorded.
    lumora_generate_thumb($original_path, $thumb_p, $thumb_w, $thumb_h);

    // ── 3. Read metadata (after any in-place resize) ─────────────────────────
    [$width, $height] = lumora_get_image_dimensions($original_path);
    $filesize = lumora_get_filesize($original_path);

    // ── 4. Insert DB record ──────────────────────────────────────────────────
    $max_pos = (int) LumoraDB::fetchValue(
        'SELECT COALESCE(MAX(pos), 0) FROM `{PREFIX}images` WHERE album_id = ?',
        [$album_id]
    );

    return (int) LumoraDB::insert('images', [
        'album_id' => $album_id,
        'filename' => $filename,
        'title'    => '',
        'filesize' => $filesize,
        'width'    => $width,
        'height'   => $height,
        'approved' => 1,
        'pos'      => $max_pos + 1,
        'added_at' => date('Y-m-d H:i:s'),
    ]);
}

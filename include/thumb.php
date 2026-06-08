<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Thumbnail Generation
 *
 * Tries the Imagick PHP extension first (preferred), falls back to GD.
 * Also provides helpers for reading image dimensions, scanning album folders,
 * and the per-image batch-add processing step.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Thumbnail generation ──────────────────────────────────────────────────────

/**
 * Generate a thumbnail for $source, writing it to $dest.
 * Tries the Imagick PHP extension first; falls back to GD if unavailable.
 *
 * @return bool True on success.
 */
function lumora_generate_thumb(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    bool   $strip = true
): bool {
    if (extension_loaded('imagick')) {
        return lumora_thumb_imagick($source, $dest, $max_w, $max_h, $strip);
    }

    if (extension_loaded('gd')) {
        return lumora_thumb_gd($source, $dest, $max_w, $max_h);
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
 */
function lumora_thumb_imagick(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h,
    bool   $strip = true
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

        $img->setImageCompressionQuality(90);
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
 */
function lumora_thumb_gd(
    string $source,
    string $dest,
    int    $max_w,
    int    $max_h
): bool {
    $info = @getimagesize($source);
    if (!$info) return false;

    [$src_w, $src_h, $type] = $info;

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_GIF  => @imagecreatefromgif($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
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
        IMAGETYPE_JPEG => imagejpeg($dst, $dest, 90),
        IMAGETYPE_PNG  => imagepng($dst, $dest, 7),
        IMAGETYPE_GIF  => imagegif($dst, $dest),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $dest, 90) : imagejpeg($dst, $dest, 90),
        default        => false,
    };

    imagedestroy($src);
    imagedestroy($dst);

    return $ok && file_exists($dest);
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
    $info = @getimagesize($filepath);
    return $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
}

/**
 * Return the filesize in bytes, or 0 on failure.
 */
function lumora_get_filesize(string $filepath): int
{
    $size = @filesize($filepath);
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
 *   1. Generate thumbnail
 *   2. Read dimensions and filesize
 *   3. Insert into DB
 *
 * Returns the new image ID on success, or false on failure.
 */
function lumora_batch_add_image(string $filename, string $folder, int $album_id): int|false
{
    $original_path = lumora_album_path($folder) . $filename;
    if (!file_exists($original_path)) return false;

    $thumb_w = (int) lumora_config('thumb_width',  250);
    $thumb_h = (int) lumora_config('thumb_height', 250);
    $thumb_p = lumora_album_path($folder) . LUMORA_THUMB_PREFIX . $filename;

    // Thumbnail generation; non-fatal if it fails.
    lumora_generate_thumb($original_path, $thumb_p, $thumb_w, $thumb_h);

    [$width, $height] = lumora_get_image_dimensions($original_path);
    $filesize = lumora_get_filesize($original_path);

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

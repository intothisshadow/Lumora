<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Thumbnail Service
 *
 * Thumbnail generation, original-image resizing, image metadata helpers,
 * extension validation, folder scanning, and the per-image batch-add step.
 *
 * Tries the Imagick PHP extension first (preferred), falls back to GD.
 * No CLI ImageMagick dependency.
 *
 * Configuration keys used by this service:
 *   thumb_quality       — JPEG/WebP quality for generated thumbnails (1–100, default 85)
 *   thumb_width         — max thumbnail width in px (default 250)
 *   thumb_height        — max thumbnail height in px (default 250)
 *   max_upload_size_mb  — maximum original file size in MB; 0 = unlimited (default 0)
 *   max_image_width     — maximum width for stored originals in px; 0 = unlimited (default 0)
 *   max_image_height    — maximum height for stored originals in px; 0 = unlimited (default 0)
 *   allowed_extensions  — comma-separated list (default 'jpg,jpeg,png,gif,webp')
 *
 * Legacy callers use the free-function wrappers in include/thumb.php.
 * New V2 code should call ThumbnailService:: directly.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class ThumbnailService
{
    // ── Thumbnail generation ──────────────────────────────────────────────────

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
    public static function generateThumb(
        string $source,
        string $dest,
        int    $max_w,
        int    $max_h,
        bool   $strip   = true,
        int    $quality = 0
    ): bool {
        // quality 0 means "read from config" — allows callers to override.
        if ($quality <= 0) {
            $quality = max(1, min(100, (int) LumoraConfig::get('thumb_quality', 85)));
        }

        if (extension_loaded('imagick')) {
            return self::thumbImagick($source, $dest, $max_w, $max_h, $strip, $quality);
        }

        if (extension_loaded('gd')) {
            return self::thumbGd($source, $dest, $max_w, $max_h, $quality);
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
    private static function thumbImagick(
        string $source,
        string $dest,
        int    $max_w,
        int    $max_h,
        bool   $strip   = true,
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
    private static function thumbGd(
        string $source,
        string $dest,
        int    $max_w,
        int    $max_h,
        int    $quality = 85
    ): bool {
        $info = getimagesize($source);
        if (!$info) return false;

        [$src_w, $src_h, $type] = $info;

        // Reject images with excessive declared dimensions before allocating a GD
        // resource.  A crafted file can declare e.g. 50 000×50 000 px in its header
        // while containing only a few hundred bytes of payload; GD would try to
        // allocate ~10 GB of RAM before discovering the fraud.
        // Cap: 50 MP total pixels OR either axis > 15 000 px.
        $max_pixels = 50_000_000;
        if ((int) $src_w * (int) $src_h > $max_pixels || $src_w > 15_000 || $src_h > 15_000) {
            error_log(sprintf(
                'Lumora: ThumbnailService::thumbGd rejected oversized source image: %s (%dx%d)',
                $source,
                $src_w,
                $src_h
            ));
            return false;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => imagecreatefrompng($source),
            IMAGETYPE_GIF  => imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($source) : false,
            default        => false,
        };
        if (!$src) return false;

        $ratio = min(1.0, $max_w / $src_w, $max_h / $src_h); // no upscaling
        $dst_w = max(1, (int) round($src_w * $ratio));
        $dst_h = max(1, (int) round($src_h * $ratio));

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

    // ── Original image resizing ───────────────────────────────────────────────

    /**
     * Downscale an original image file in-place to fit within the given maximum
     * dimensions. Used by batchAddImage() when max_image_width or max_image_height
     * are configured.
     *
     * - Never upscales; returns true immediately if the image is already within limits.
     * - Uses a randomly-named temp file, then renames/copies it over the original
     *   so the operation is as close to atomic as possible.
     * - Uses quality 92 (high quality for originals, independent of thumb_quality).
     * - strip = false to preserve EXIF data in the stored original.
     *
     * @param string $path  Absolute filesystem path to the image file.
     * @param int    $max_w Maximum width in px. 0 = no constraint on this axis.
     * @param int    $max_h Maximum height in px. 0 = no constraint on this axis.
     * @return bool True on success or when no resize was needed; false on failure.
     */
    public static function resizeOriginal(string $path, int $max_w, int $max_h): bool
    {
        // Both limits disabled — nothing to do.
        if ($max_w <= 0 && $max_h <= 0) return true;

        [$orig_w, $orig_h] = self::getImageDimensions($path);
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
        $ok = self::generateThumb($path, $tmp, $limit_w, $limit_h, false, 92);

        if (!$ok || !file_exists($tmp)) {
            if (file_exists($tmp)) unlink($tmp);
            error_log('Lumora: ThumbnailService::resizeOriginal failed to generate resized version of ' . $path);
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

    // ── Image metadata ────────────────────────────────────────────────────────

    /**
     * Read image dimensions without loading the full image.
     * Returns [width, height] or [0, 0] on failure.
     *
     * @return array{int, int}
     */
    public static function getImageDimensions(string $filepath): array
    {
        if (!is_file($filepath)) return [0, 0];
        $info = getimagesize($filepath);
        return $info !== false ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    /**
     * Return the filesize in bytes, or 0 on failure.
     */
    public static function getFilesize(string $filepath): int
    {
        if (!is_file($filepath)) return 0;
        $size = filesize($filepath);
        return $size !== false ? (int) $size : 0;
    }

    // ── Extension validation ──────────────────────────────────────────────────

    /**
     * Return the list of allowed image extensions from config.
     *
     * @return string[]
     */
    public static function getAllowedExtensions(): array
    {
        $raw = LumoraConfig::get('allowed_extensions', 'jpg,jpeg,png,gif,webp');
        return array_map('strtolower', array_map('trim', explode(',', $raw)));
    }

    /**
     * Return true if $filename has an allowed image extension AND does not
     * embed a dangerous server-side extension anywhere in its name.
     *
     * Checking only the last extension is insufficient: a file named
     * 'shell.php.jpg' would pass a naive extension test but could be
     * executed by a misconfigured web server.  This method rejects any
     * filename where any dot-separated segment matches a known
     * server-side-executable extension list.
     */
    public static function isAllowedImage(string $filename): bool
    {
        $basename = basename($filename);

        // Reject filenames that contain any server-executable extension in any
        // segment (e.g. 'shell.php.jpg', 'evil.phar.png', 'back.php3.gif').
        $dangerous = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'shtml'];
        $segments  = array_map('strtolower', explode('.', $basename));
        foreach ($segments as $segment) {
            if (in_array($segment, $dangerous, true)) {
                return false;
            }
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::getAllowedExtensions(), true);
    }

    // ── Batch add helpers ─────────────────────────────────────────────────────

    /**
     * Scan an album's folder for image files that are not yet in the database.
     * Skips files whose names start with any known generated prefix.
     *
     * Returns a naturally-sorted array of bare filenames.
     *
     * @return string[]
     */
    public static function scanNewImages(string $folder, int $album_id): array
    {
        $dir = lumora_album_path($folder);
        if (!is_dir($dir)) return [];

        // Fetch filenames already in DB for this album (one indexed query).
        $existing = array_flip(array_column(
            LumoraDB::fetchAll(
                'SELECT filename FROM `{PREFIX}images` WHERE album_id = ?',
                [$album_id]
            ),
            'filename'
        ));

        $skip_prefixes = [LUMORA_THUMB_PREFIX, 'normal_', 'mid_'];
        $new           = [];

        foreach (new DirectoryIterator($dir) as $file) {
            if (!$file->isFile()) continue;

            $name = $file->getFilename();

            // Use isAllowedImage() so the dangerous-extension check is enforced
            // consistently; this also covers double-extension filenames.
            if (!self::isAllowedImage($name)) continue;

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
    public static function batchAddImage(string $filename, string $folder, int $album_id): int|false
    {
        $original_path = lumora_album_path($folder) . $filename;
        if (!file_exists($original_path)) return false;

        // ── 0. Max upload size check ─────────────────────────────────────────
        $max_mb = (int) LumoraConfig::get('max_upload_size_mb', 0);
        if ($max_mb > 0) {
            $file_bytes = self::getFilesize($original_path);
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

        // ── 1. Downscale original if needed ──────────────────────────────────
        $max_img_w = (int) LumoraConfig::get('max_image_width',  0);
        $max_img_h = (int) LumoraConfig::get('max_image_height', 0);
        if ($max_img_w > 0 || $max_img_h > 0) {
            self::resizeOriginal($original_path, $max_img_w, $max_img_h);
            // Failure is non-fatal: we store the original dimensions on error.
        }

        // ── 2. Generate thumbnail ────────────────────────────────────────────
        $thumb_w = (int) LumoraConfig::get('thumb_width',  250);
        $thumb_h = (int) LumoraConfig::get('thumb_height', 250);
        $thumb_p = lumora_album_path($folder) . LUMORA_THUMB_PREFIX . $filename;

        // Thumbnail generation is non-fatal; dimensions are still recorded.
        self::generateThumb($original_path, $thumb_p, $thumb_w, $thumb_h);

        // ── 3. Read metadata (after any in-place resize) ─────────────────────
        [$width, $height] = self::getImageDimensions($original_path);
        $filesize = self::getFilesize($original_path);

        // ── 4. Insert DB record ──────────────────────────────────────────────
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
}

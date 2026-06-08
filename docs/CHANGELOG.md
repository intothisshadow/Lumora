# Changelog — Lumora Gallery

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — 2026-06-08

### Changed
- `README.md` — added **Development** section crediting developer Ariane with
  repository link (<https://code.unloved-hert.net/lumora/>).

### 

### Added
- **LICENSE** — project is now released under the GNU General Public License v3.0.
  `LICENSE` file added to repository root.
- **Developer credit** — `README.md` Development section lists developer Ariane with
  repository link (<https://code.unloved-hert.net/lumora/>).

- **Image view counter** — view counts are now actually recorded when visitors use
  the lightbox. Previously `increment_image_hits()` existed in the codebase but was
  never called, so the `hits` column stayed at 0 for every image.
  - `ajax_hit.php` (new file) — lightweight public AJAX endpoint that accepts a
    `POST image_id` and increments the image's hit counter. Throttled to one
    increment per image per PHP session so rapidly navigating through a lightbox
    or refreshing the page does not inflate counts. No CSRF token required (a
    public view counter is not a sensitive or destructive action).
  - `lumora_render_thumbgrid()` in `include/template.php` — each thumbnail anchor
    now carries a `data-image-id` attribute containing the database image ID.
  - `lumora_render_lightbox_js()` in `include/template.php` — now accepts an
    optional `string $base_url = ''` parameter used to build the absolute URL for
    `ajax_hit.php`. A tiny non-module `<script>` block writes
    `window.__lumHitUrl` before the ESM module runs so the module can reach the
    endpoint without PHP variable interpolation inside the nowdoc. A `change`
    listener on the PhotoSwipe instance fires a fire-and-forget `XMLHttpRequest`
    POST every time a new image is displayed (including the first image when the
    lightbox opens). The response is intentionally ignored.
  - `index.php` and `album.php` — both calls to `lumora_render_lightbox_js()` now
    pass `lumora_base_url()` so the hit endpoint resolves correctly regardless of
    subdirectory installation depth.

### Fixed
- **Batch Add — Process button did nothing** (`admin/batch.php`, `admin/ajax_batch.php`).
  Two bugs combined to make the button completely unresponsive:
  1. `$new_count` was never initialised before the `if ($selected)` block. When the
     page loads without an album selected PHP 8 emits `E_WARNING: Undefined variable
     $new_count` during heredoc interpolation, producing `const total = ;` in the
     rendered JavaScript — a **SyntaxError** that prevents the entire IIFE from
     running. Because `addEventListener` was never called, clicking the button had no
     effect on any subsequent page load in the same browser session.
  2. `ajax_batch.php` re-ran `lumora_scan_new_images()` on every AJAX call, returning
     a shorter list each time (processed images are now in the DB). The JS kept
     incrementing an offset against the original full count, so by the second chunk
     the offset already landed in the wrong position; by ~chunk 7 it exceeded
     `count($all_new)` entirely — `array_slice` returned `[]`, the server replied
     `done=true` with 0 processed, and the rest of the album was silently skipped.

  **Fixes applied:**
  - `$new_count = 0` initialised before `if ($selected)` so the heredoc always has
    a valid integer, eliminating the JS SyntaxError.
  - Switched from `async/await` + `fetch` to a plain `XMLHttpRequest` loop, removing
    one layer of implicit Promise rejection that could swallow errors silently.
    Also added a 3-minute `xhr.timeout` and explicit `onerror`/`ontimeout` handlers.
  - `ajax_batch.php`: removed the `$offset` parameter entirely. The handler now always
    calls `array_slice($all_new, 0, $limit)` — "process the first N still-unprocessed
    files". Because `lumora_scan_new_images()` filters out DB entries, each subsequent
    call naturally advances to the next unprocessed batch without any offset
    arithmetic.
  - Added an infinite-loop guard in `ajax_batch.php`: if a chunk is non-empty but
    every file in it fails (processed=0, errors=all), `done` is forced to `true` so
    the client stops retrying the same broken files forever.
  - `done` condition corrected to `count($all_new) <= $limit` (was
    `($offset + $limit) >= count($all_new)`, which was wrong once offset was removed).

### Added (earlier in this session)
- `admin/maintenance.php` — new **Maintenance** admin page with a **File Integrity
  Check** tool. Scans every image record in the database and verifies that both the
  original file and its thumbnail exist on disk. Runs in AJAX chunks of 500 so it
  handles galleries with 500 000+ images without hitting PHP's time limit. Includes
  a live progress bar, a cancel button, and a results table showing each missing
  original / thumbnail with a per-row checkbox. A "Select all / Delete Selected
  Records" control lets the admin bulk-remove orphaned DB entries in one click.
  **Only database records are removed — no files on disk are ever touched.**
- `admin/ajax_integrity.php` — AJAX endpoint for the integrity scan. Uses
  **keyset pagination** (`WHERE id > last_id`) so query time stays constant
  regardless of gallery size; plain `OFFSET` would become progressively slower
  beyond ~100 000 rows. Returns `checked`, `last_id`, `missing[]`, and `done` per
  chunk. `LEFT JOIN` on albums catches image records whose album row has been
  deleted (reported as `[Album deleted]` with both files flagged missing).
- `admin/ajax_integrity_delete.php` — AJAX endpoint that deletes a set of image
  records by ID. Accepts `ids[]` (max 5 000 per call), validates CSRF, casts all
  values to positive integers, runs deletes inside a single transaction, and returns
  `deleted` count plus any per-row `errors[]`. No files on disk are touched.
- `admin/account.php` — Account Management page. Allows the logged-in admin to update
  their username and email address (with uniqueness check), and change their password
  (requires current-password verification, minimum 8 characters, confirm field with
  live client-side match indicator). Session username is kept in sync after a
  successful profile update.
- `admin/includes/admin_helpers.php` — **Account** entry (👤) added to the sidebar
  navigation after Configuration. The username displayed in the top bar is now a
  clickable link to `account.php`.
- `lumora_sanitize_folder()` in `include/functions.php`: centralised album folder-path
  sanitisation. Allows letters, digits, hyphens, underscores, and dots per segment;
  forward slashes for subdirectory nesting (e.g. `Xena/Season1/1x01-SinsOfThePast`).
  Strips path traversal (`..`), hidden-directory segments (leading dot), and any
  characters outside the allowed set.

### Fixed (earlier in this session)
- `lumora_album_url()` in `include/functions.php`: was calling `rawurlencode()` on the
  entire folder string, encoding `/` separators to `%2F` and breaking nested paths.
  Now encodes each path segment individually while preserving slashes.
- Album folder sanitisation in `admin/albums.php` now uses `lumora_sanitize_folder()`
  (the previous inline `preg_replace` also stripped dots, breaking names like
  `1.01-EpisodeTitle` or `Season.1`).
- `install/index.php` — blank page on first visit: PHP's `{$...}` heredoc
  interpolation does not support expressions like ternary operators; pre-computed
  step indicator classes into plain variables instead.
- `install/index.php` — schema created no tables: `preg_split('/;\s*\n/', ...)` split
  the SQL into segments that each began with `-- comment` header lines; the
  `str_starts_with($s, '--')` filter then silently discarded every segment containing
  a `CREATE TABLE` statement. Fixed by stripping comment/blank lines from the SQL
  *before* splitting on semicolons, extracted into `ins_run_schema()`.
- `install/index.php` — blank page on "Finish Installation": the config-defaults loop
  and user INSERT/UPDATE had no error handling; an uncaught `PDOException` (caused by
  the missing tables above) produced a blank page. All DB writes in step 2 are now
  wrapped in `try/catch` blocks that render a clear error page with a "Start Over"
  link instead.
- `install/index.php` — replaced the `INSERT … exec(quote())` pattern for config and
  user writes with proper PDO prepared statements (`prepare` / `execute`), and
  replaced the two-query INSERT+UPDATE fallback for users with a single
  `INSERT … ON DUPLICATE KEY UPDATE`.

### Changed
- `admin/includes/admin_helpers.php` — **Maintenance** (🔧) entry added to the
  sidebar navigation between Configuration and Account.
- Album **Folder Path** field in Admin → Albums now explicitly supports nested paths
  (`ShowName/Season2/EpisodeSlug`). Updated placeholder, hint text, and removed the
  `pattern` attribute that only allowed a flat name.
- `README.md` — Image & Thumbnail Storage section rewritten to show the nested
  directory layout with a real-world example; Coppermine Migration section updated
  with step-by-step folder path instructions.
- `install/index.php` — `config.php` is now generated via string concatenation
  instead of a heredoc, eliminating delimiter-collision risk. Generated file now
  includes `declare(strict_types=1);`.
- `include/thumb.php` — image processor changed from CLI `exec('convert ...')` to the
  **Imagick PHP extension** (`ext-imagick`) as the primary engine. New
  `lumora_thumb_imagick()` uses `autoOrient()` for EXIF correction,
  `thumbnailImage($w, $h, true)` for aspect-ratio-preserving resize, and
  `stripImage()` for metadata removal. Upscaling is explicitly prevented by checking
  dimensions before resizing. **GD** remains the fallback when `ext-imagick` is not
  loaded. The old CLI-based `lumora_thumb_imagemagick()` function has been removed.
- `admin/config.php` — removed the **ImageMagick Binary Directory** config field
  (`im_path`). Replaced with a read-only **Image Processor** status line that shows
  which engine is active at runtime (`✓ Imagick PHP extension` / `⚠ GD library` /
  `✗ None found`). `im_path` removed from the save whitelist and import safe-key list.
- `install/index.php` — requirements check updated: the **GD or ImageMagick** row now
  checks `extension_loaded('imagick')` and `extension_loaded('gd')` instead of
  probing CLI binary paths. `im_path` removed from the config defaults seeded to the
  database on installation.
- `README.md` — Requirements table updated (`Imagick (preferred) or GD`); Thumbnail
  generation section rewritten to describe the extension-based approach; `im_path`
  removed from the Configuration table; note added that the image processor is
  auto-detected with no path configuration needed.

## [Unreleased] — 2026-06-06

### Changed
- Added `declare(strict_types=1);` to all 21 PHP files (`include/`, `admin/`, `admin/includes/`, `install/`, public entry points, `version.php`, `config.sample.php`).

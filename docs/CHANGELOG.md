# Changelog — Lumora Gallery

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added

- **Coppermine Importer plugin** (`plugins/coppermine-importer/`):
  Official migration plugin for importing Coppermine Gallery (CPG 1.4–1.6)
  categories, albums, and image metadata into Lumora. Image files are not moved;
  the importer is metadata-first and preserves the existing Coppermine
  `albums/` folder structure in place.

  - **`plugins/coppermine-importer/version.php`** — single source of truth for
    the plugin version (`LUMORA_CPG_IMPORTER_VERSION = '1.0.0'`).
    All other files reference this constant; updating the version requires
    changing only `version.php` and `plugin.json`.

  - **`plugins/coppermine-importer/plugin.json`** — plugin manifest consumed by
    the Lumora migration hub (`admin/migrate.php`) for discovery, display, and
    compatibility checking against `LUMORA_VERSION`.

  - **`plugins/coppermine-importer/CoppermineImporter.php`** — core importer
    class. Opens a separate PDO connection to the Coppermine database and exposes
    three chunked import methods:
    - `importCategories(int $last_id, int $limit, array $cat_id_map): array` —
      keyset-paginated category import; builds a CPG `cid` → Lumora `cat_id` map
      used to resolve parent/child relationships.
    - `importAlbums(int $last_id, int $limit, array $cat_id_map): array` —
      keyset-paginated album import; resolves Coppermine folder paths
      (`keyword` field or zero-padded `aid`) to Lumora `folder` values;
      deduplicates folder names automatically.
    - `importImages(int $last_id, int $limit, array $album_id_map): array` —
      keyset-paginated image import; verifies file and thumbnail presence at
      `LUMORA_ALBUMS_PATH/{folder}/{filename}` and reports missing files without
      blocking the DB record from being created (reconcile later with File
      Integrity Check).
    - `validate(): array` — tests the Coppermine DB connection and returns
      record counts before the import begins.
    - HTML-entity decoding via `html_entity_decode()` for CPG-encoded title and
      description fields; `approved` normalised from ENUM('YES'/'NO') or
      tinyint; `added` normalised from datetime or Unix timestamp int.

  - **`plugins/coppermine-importer/admin/index.php`** — four-step admin wizard
    (Credentials → Preview → Import → Done). Integrates with Lumora's admin
    panel via `lum_admin_page()` and `lumora_require_admin()`. Stores CPG
    credentials and accumulated ID maps in `$_SESSION['lumora_cpg_import']`;
    session is cleared on completion or timeout (2 h). Re-import warning with
    mandatory confirmation checkbox displayed when a prior migration record exists.

  - **`plugins/coppermine-importer/admin/ajax_import.php`** — AJAX chunk
    processor. Three actions (`import_categories`, `import_albums`,
    `import_images`) process one keyset-paginated chunk per call; a `finish`
    action writes the final `migration_status` record and clears the session.
    Each call validates CSRF and admin authentication; session timeout is enforced
    server-side (2 h). Returns JSON with per-chunk counts, errors, and a
    `done` boolean.

  - **`plugins/coppermine-importer/README.md`** — documentation covering what
    is and is not imported, file-migration workflow, folder-name mapping table,
    re-import protection behaviour, DB migration SQL for v5 → v6, and
    instructions for creating future importers.

- **Migration framework** (Lumora core):

  - **`include/services/MigrationService.php`** — new static service class.
    Provides import status tracking (`getMigrationStatus`, `saveMigrationStatus`,
    `clearMigrationStatus`, `isImported`, `getAllStatuses`), migration event
    logging (`logEvent`, `getLogs`, `clearLogs`), plugin discovery
    (`discoverImporters` — scans `LUMORA_PLUGINS_PATH/*/plugin.json` for
    `"type": "importer"` entries), and semantic version compatibility checking
    (`isCompatible`). All DB calls degrade silently on pre-v6 installs.

  - **`admin/migrate.php`** — new admin hub page. Discovers installed importer
    plugins, shows each as a card with name, description, version, compatibility
    badge, previous migration status (if any), and a **Run Importer** button.
    Displays a migration history table when any sources have been imported.
    Active nav key `'migrate'` highlights the **Import** sidebar item.

  - **`admin/includes/admin_helpers.php`** — **Import** (📥) nav entry added
    between Tools and Account, linking to `admin/migrate.php`.

  - **`include/bootstrap.php`** — `LUMORA_PLUGINS_PATH` constant defined (step 2);
    `MigrationService.php` loaded (step 7).

  - **`install/schema.sql`** (DB version 6) — two new tables:
    - `{PREFIX}migration_status` — one row per source platform; records
      `source`, `imported_at`, `categories`, `albums`, `images`,
      `plugin_version`. `PRIMARY KEY (source)` with `ON DUPLICATE KEY UPDATE`
      for idempotent upserts.
    - `{PREFIX}migration_log` — append-only event log written during import;
      `level` is `'info' | 'warning' | 'error'`; keyed on `(source, level)`
      and `(source, created_at)` for efficient filtering.

  - **`version.php`** — `LUMORA_DB_VERSION` bumped from 5 to 6.

### Database migration (DB v5 → v6)

Run the following SQL on existing installations (replace `lum_` with your
actual table prefix):

```sql
CREATE TABLE IF NOT EXISTS `lum_migration_status` (
  `source`         varchar(64)  NOT NULL,
  `imported_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `categories`     int UNSIGNED NOT NULL DEFAULT 0,
  `albums`         int UNSIGNED NOT NULL DEFAULT 0,
  `images`         int UNSIGNED NOT NULL DEFAULT 0,
  `plugin_version` varchar(32)  NOT NULL DEFAULT '',
  PRIMARY KEY (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lum_migration_log` (
  `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`     varchar(64)     NOT NULL,
  `level`      varchar(16)     NOT NULL DEFAULT 'info',
  `message`    text            NOT NULL,
  `created_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `source_level`   (`source`, `level`),
  KEY `source_created` (`source`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Fresh installations from `install/schema.sql` receive both tables automatically.

---

## [1.5.0] — 2026-06-15

### Security

- **Replace GET-based CSRF token on config export** (`admin/config.php`):
  The export download previously embedded the CSRF token in the URL as a query
  parameter (`?export=1&csrf_token=...`), which risks leaking the token via the
  `Referer` header and exposes it in browser history and server logs. Replaced the
  anchor link with a minimal POST form (`action="export"`); the token travels in the
  request body only and validation is handled by the existing `lumora_csrf_validate()`
  call at the top of the POST block.

### Changed

- **Migrate business logic from **free functions to service classes****
  (`include/services/LumoraConfig.php`, `include/services/GalleryService.php`,
  `include/services/ThumbnailService.php`, `include/services/ThemeRenderer.php`,
  `include/bootstrap.php`, `include/functions.php`, `include/template.php`,
  `include/thumb.php`):
  Introduces four focused static service classes in `include/services/`, resolving
  the V1 technical-debt item that flagged free functions and a global config variable
  as architectural weaknesses.

  - **`LumoraConfig`** — replaces the module-level `$LUMORA_CONFIG` global with a
    static private cache. `load()`, `get()`, and `set()` are the sole access points.
    Eliminates the `global $LUMORA_CONFIG` pattern from every call site inside the
    service layer.

  - **`GalleryService`** — collects all category, album, image, stats, and
    visitor-tracking queries that were previously scattered as free functions in
    `include/functions.php`. Method naming follows camelCase convention:
    `getCategories()`, `getAlbum()`, `getAlbumImages()`, `getGalleryStats()`,
    `trackVisitor()`, `getOnlineStats()`, etc.

  - **`ThumbnailService`** — collects all thumbnail generation, original-image
    resizing, metadata reading, extension validation, folder scanning, and
    batch-add logic from `include/thumb.php`. The Imagick and GD engines become
    `private static` methods; only `generateThumb()` is part of the public API.

  - **`ThemeRenderer`** — collects all HTML-generation functions from
    `include/template.php`, including `renderPage()`, `renderThumbgrid()`,
    `renderCatgrid()`, `renderCatlist()`, `renderBreadcrumb()`, `renderStats()`,
    `renderWhoIsOnline()`, `renderLightboxJs()`, and related helpers.
    `loadCustomFile()` becomes `private static` since it is an internal detail of
    the header/footer loading path.

  **Transition strategy — full backward compatibility preserved:** The original
  `include/functions.php`, `include/template.php`, and `include/thumb.php` are
  retained as thin forwarding-wrapper files. Every existing free function is kept
  as a one-liner that delegates to the corresponding service method. No caller
  (public pages, admin pages, AJAX handlers, or the installer) required any change.
  New V2 code can call the service classes directly.

  **Bootstrap load order updated** (`include/bootstrap.php`): the four service
  class files are now required immediately after `db.php` (step 7), before the
  legacy include files (steps 8–11). PHP class definitions are parsed at require
  time; no service method is invoked before all includes are loaded, so
  forward-references to free functions defined later are safe.

  **Utility free functions retained as-is** in `include/functions.php`: `h()`,
  `lumora_redirect()`, `lumora_int()`, `lumora_base_url()`, `lumora_album_path()`,
  `lumora_album_url()`, `lumora_active_theme()`, `lumora_theme_url()`,
  `lumora_theme_path()`, `lumora_list_themes()`, `lumora_format_bytes()`,
  `lumora_generate_folder()`, `lumora_sanitize_folder()`, `image_original_url()`,
  `image_thumb_url()`, `image_original_path()`, `image_thumb_path()`,
  `lumora_pagination()`, and `lumora_log()` have no class-level benefit and remain
  as global utility functions.

---

## [1.0.0] — 2026-06-13

### Fixed

- **"Powered by" credit invisible on dark-footer themes** (`include/template.php`,
  `themes/default/lumora.css`): `lumora_render_powered_by()` was wrapping the credit
  in `<small class="text-muted">`. Bootstrap 5's `.text-muted` applies
  `color: #6c757d !important`, overriding any inherited footer colour. In the
  classic-fansite theme the footer background is dark purple (`#2a1040`), making
  the gray text invisible. Fixed by removing the `text-muted` class from the
  generated HTML so the credit inherits its colour from the theme's footer rule.
  Added an explicit `color: #6c757d` to `.lum-footer` in `lumora.css` so the
  default theme's visual appearance is unchanged.

### Added

- **Category list layout** (`include/template.php`, `include/functions.php`, `index.php`,
  `admin/config.php`, `install/index.php`, `themes/default/lumora.css`,
  `themes/classic-fansite/fansite.css`):
  A new Coppermine-inspired row-based layout for the category browser, selectable via
  Admin → Configuration → Appearance → **Category Layout**. Album and Image counts
  shown in the list view are **recursive** — they aggregate totals across all descendant
  subcategories at any depth, matching the behaviour of Coppermine's category list.
  - **`get_category_subtree_counts(array $cat_ids): array`** in `include/functions.php`:
    Accepts a list of root category IDs. Loads the full category tree once (id +
    parent_id only), resolves each root's subtree in PHP via BFS, then runs two batch
    queries (album counts, image counts) with a single `IN (...)` clause covering all
    descendant IDs. Total: three queries regardless of tree depth or category count.
    Returns `array<int, array{album_count: int, image_count: int}>` keyed by each input
    category ID.
  - **`category_layout` config key** (`'grid'` default | `'list'`): stored in
    `{PREFIX}config`; seeded as `'grid'` by the installer so fresh installs use the
    existing card grid and existing installs are completely unaffected until an admin
    opts in.
  - **`lumora_render_catlist(array $items): string`** in `include/template.php`:
    Renders each category as one row with four columns: thumbnail, category name +
    description, album count, image count. Header row labels the columns. Uses
    `lumora_render_item_thumb()` so existing cover-image configuration is honoured.
    Empty-state message matches the pattern of other render functions.
  - **`lumora_render_categories(array $items): string`** in `include/template.php`:
    Dispatcher that reads `category_layout` from config and calls either
    `lumora_render_catlist()` (list) or `lumora_render_catgrid($items, 'category')`
    (grid). All public category rendering in `index.php` now goes through this
    function; album rendering continues to call `lumora_render_catgrid()` directly.
  - **`get_categories()` extended** in `include/functions.php`: a fourth subquery
    (`image_count`) is now returned alongside the existing `album_count` and
    `subcategory_count`. Counts approved images in albums that belong directly to
    the category (not recursive). Docblock updated with `@return` array shape.
  - **Admin form field** (`admin/config.php`): a `<select>` under Admin →
    Configuration → Appearance lets the admin switch layouts. `category_layout`
    added to the POST save whitelist, `match` sanitisation branch, `$cfg` array,
    import `$safe_keys`, and pre-computed `$sel_cat_grid` / `$sel_cat_list` select
    states.
  - **CSS** (`themes/default/lumora.css`, `themes/classic-fansite/fansite.css`):
    Complete `.lum-catlist`, `.lum-catlist-header`, `.lum-catlist-row`,
    `.lum-catlist-col-thumb`, `.lum-catlist-col-name`, `.lum-catlist-col-albums`,
    `.lum-catlist-col-images`, `.lum-catlist-desc` rule sets added to both theme
    stylesheets. The default theme uses its existing `--lum-accent` and neutral
    palette; the classic-fansite theme uses `--fs-accent`, `--fs-panel-bg`,
    `--fs-panel-border`, and `--fs-radius` for full visual consistency. Both
    include a responsive `@media (max-width: 575px)` breakpoint that shrinks the
    thumbnail column (140 → 80 px / 120 × 150 → 72 × 90 px) and compacts the
    count columns.

- **Classic Fansite starter theme** (`themes/classic-fansite/`):
  A traditional fansite-style theme inspired by the gallery sites of the 2000s–2010s
  fandom era. Fully responsive; preserves the classic fixed-width centred-panel
  aesthetic on desktop.
  - `template.html` — page structure: full-bleed banner, sticky nav bar, content
    area, footer. Does not use the `{NAVIGATION}` token; instead builds its own
    nav directly with `{BASE_URL}` links for a completely custom HTML structure.
    `{CUSTOM_HEADER}` is placed inside `.fs-banner-bg` (absolute-positioned) so a
    bare `<img>` tag in the custom header file automatically becomes a full-bleed
    banner image behind the gallery title overlay.
  - `fansite.css` — all styles defined via CSS custom properties in `:root` for
    easy one-file customisation. Covers all `lum-*` component classes produced by
    `include/template.php` (thumbgrid, catgrid, stats, sort bar, pagination,
    breadcrumb, who-is-online), Bootstrap colour overrides for `.page-link`,
    `.page-item.active`, and `.btn-outline-primary`, and full responsive rules
    (mobile-first, breakpoints at 575 px and 992 px). Sticky nav scrolls
    horizontally on narrow viewports rather than wrapping.
  - `README.md` — comprehensive customisation guide: full table of CSS variables,
    five ready-to-use fandom colour presets (dark red/fantasy, ocean blue/sci-fi,
    forest green/nature, rose gold/pop, midnight gold/historical), instructions for
    adding a banner image via custom header path, and a step-by-step guide for
    creating a new derived theme.

---

### Changed

- **Credit footer** (`include/template.php`): Removed the version number from the
  public-facing "Powered by Lumora Gallery" footer credit. The link and credit text
  are retained; only the appended version string has been dropped.

### Added

- **Image ID column in image list** (`admin/images.php`):
  The image grid now displays each image's database ID as a dedicated **ID** column
  between the row checkbox and the thumbnail. The column uses muted styling and a fixed
  50 px width so it stays compact while remaining clearly readable. Useful for cross-
  referencing images when setting album/category cover IDs in the admin panel.

- **Album thumbnail support** (`admin/albums.php`):
  The album New/Edit form now includes a **Cover Image** field (image ID).
  Admins can specify any approved image ID as the album cover thumbnail;
  entering `0` (or leaving blank) reverts to auto-picking the first image in
  the album. The ID is validated against `{PREFIX}images` on save; invalid or
  unapproved IDs are cleared with a warning flash. The `thumb_image_id` column
  was already present in the schema and already consumed by
  `lumora_render_item_thumb()` in `include/template.php`, so no DB migration
  is required and the front-end display works immediately.

- **Image Management** (`admin/images.php`, `admin/ajax_image_delete.php`,
  `admin/ajax_image_move.php`, `admin/ajax_image_rethumb.php`):
  New dedicated admin page for managing images within an album.
  - **`admin/images.php`** — paginated image grid (24/page) with per-image
    actions and bulk operations. Album selector dropdown. Edit form supports
    updating title, sort position, and visibility (approved flag), plus optional
    file replacement via multipart upload (validates type, size, and image
    integrity; regenerates thumbnail and updates dimensions/filesize in DB).
    Single-image delete removes original + thumbnail files and DB record, and
    resets any album/category cover references (`thumb_image_id` → 0 auto-pick).
  - **`admin/ajax_image_delete.php`** — AJAX bulk delete (up to 500 images per
    call). Cleans up files on disk and resets album/category cover references.
  - **`admin/ajax_image_move.php`** — AJAX bulk move to another album (up to
    500 images per call). Moves original and thumbnail files (rename with
    copy+unlink cross-filesystem fallback); refuses to overwrite existing
    filenames in the target folder; resets source album cover reference when
    the moved image was the cover.
  - **`admin/ajax_image_rethumb.php`** — AJAX single-image thumbnail
    regeneration using current `thumb_width`/`thumb_height`/`thumb_quality`
    config values.
  - **`admin/includes/admin_helpers.php`** — 📸 **Images** nav item added
    between Albums and Configuration.
  - **`admin/albums.php`** — 📸 **Manage Images** button added to each album
    row in the Albums list, linking to `images.php?album=ID`.

- **Front Page — Who Is Online** (`index.php`, `include/functions.php`,
  `include/template.php`, `install/schema.sql`):
  Active visitor tracking inspired by Coppermine's online-stats module.
  - `{PREFIX}online` table (DB version 5) — one row per distinct IP address;
    `last_action` column is updated on every public page load; stale rows are
    purged automatically after `who_is_online_duration` minutes.
  - `lumora_track_visitor()` in `include/functions.php` — records/refreshes
    the current visitor's IP. Called from `index.php` and `album.php` on every
    request. Wraps all DB work in `catch(\Throwable)` so pre-v5 installs without
    the table are completely unaffected.
  - `get_online_stats()` in `include/functions.php` — returns current online
    count and the all-time record (`online_record_count` / `online_record_date`
    config keys). Automatically updates the record when the current count exceeds
    it. Degrades gracefully to `['online' => 0, …]` when the table is absent.
  - `lumora_render_who_is_online()` in `include/template.php` — renders a
    compact strip at the bottom of the home page: visitor count, configurable
    window, and the all-time record with date.
  - `who_is_online_duration` config key (default `5`, range 1–60 minutes) —
    added to Admin → Configuration (Gallery Behavior section), save whitelist,
    `match` sanitisation branch, and the config import `$safe_keys` list.
    Also added to the installer's `$config_defaults` so fresh installs receive
    the key automatically.

- **Front Page — Statistics boxes moved to bottom** (`index.php`):
  The four stat boxes (Categories, Albums, Images, Total Views) now render
  below Latest Additions rather than above content sections. A `<hr>` separator
  visually divides the stats from the thumbnail grid.

- **Front Page — Recently Updated Albums above Categories** (`index.php`,
  `include/functions.php`):
  The home page now shows a "Recently Updated" card grid as the first section,
  above the root category grid. Albums are ordered by the newest approved image's
  `added_at` timestamp; albums with no approved images are excluded.
  `get_latest_updated_albums(int $limit)` in `include/functions.php` handles
  the query. The section is hidden when `latest_albums_count = 0`.

- **Category thumbnail support** (`include/template.php`, `admin/categories.php`,
  `install/schema.sql`): Categories now display cover thumbnails on the public gallery,
  matching the existing behaviour for albums.
  - `{PREFIX}categories` gains a `thumb_image_id` column (DB version 4). When set to a
    non-zero image ID the specified image is used as the category cover. When 0 (default)
    the system auto-picks the first approved image from any public album in that category,
    so categories that contain images get a meaningful cover without any admin action.
  - `lumora_render_item_thumb()` in `include/template.php` gains an `elseif` branch for
    `$type === 'category'`: checks `thumb_image_id` first, then falls back to the
    auto-pick SQL query. Pre-migration installs (column absent) degrade gracefully —
    `!empty($item['thumb_image_id'])` evaluates to false when the key is missing, so
    the auto-pick branch still runs and categories show a thumbnail wherever one is
    available. The old TODO comment `"a placeholder is fine for V1"` is removed.
  - `admin/categories.php` edit form gains a **Cover Image** number field (Image ID,
    0 = auto). Submitted values are validated against the `{PREFIX}images` table
    (approved = 1); an invalid ID is rejected with a warning and silently reset to 0.
    `thumb_image_id` is included in both the `INSERT` and `UPDATE` DB calls.

- **Authentication — "Stay logged in" / "Remember me" feature**:
  Admins can now opt into a 30-day persistent session by ticking the **Stay logged
  in for 30 days** checkbox on the login form. The feature uses a secure split-token
  scheme (Charles Miller / Barry Jaspan pattern):
  - A `selector` (32-char hex) is stored plain in the DB and sent in the cookie for
    fast lookup.
  - A `validator` (64-char hex) travels in the cookie only; the DB stores
    `SHA-256(validator)` so a DB compromise alone cannot forge a login.
  - Tokens are rotated on every successful auto-login to limit the exposure window.
  - If the selector matches but the validator does not, all tokens for the affected
    user are revoked immediately (theft-detection response).
  - Explicit logout (Admin → Log Out) clears all persistent tokens for the user and
    expires the cookie. Session-expiry during active browsing does **not** clear the
    cookie; `bootstrap.php` transparently re-establishes the session on the next
    request.
  - New constants in `include/auth.php`: `LUMORA_REMEMBER_COOKIE` (`lumora_remember`)
    and `LUMORA_REMEMBER_DAYS` (`30`).
  - New functions: `lumora_create_remember_token()`, `lumora_check_remember_cookie()`,
    `lumora_clear_remember_cookie()`, `lumora_clear_remember_tokens()`.
  - `lumora_login()` gains an optional `bool $remember = false` parameter.
  - `lumora_logout()` gains an optional `bool $clear_remember = false` parameter;
    all existing call sites without the argument are unaffected.
  - `include/bootstrap.php` — step 11a added: calls `lumora_check_remember_cookie()`
    after session start when no active session is found.
  - `admin/login.php` — "Stay logged in for 30 days" checkbox added below the
    password field.
  - `admin/logout.php` — passes `true` to `lumora_logout()` so tokens are revoked
    on explicit logout.
  - All DB operations on `{PREFIX}remember_tokens` are wrapped in
    `catch(\Throwable)` so installations that have not yet run the migration are
    fully unaffected (cookie silently not set; no auto-login attempted).

### Changed

- **Renamed "Maintenance" to "Tools"** in all admin UI surfaces
  (`admin/includes/admin_helpers.php`, `admin/maintenance.php`):
  - Sidebar nav label updated from `Maintenance` to `Tools`.
  - Page `<h1>` and `<title>` updated from `Maintenance` to `Tools`.
  - File docblock updated to reflect the new name.
  The underlying filename (`maintenance.php`) has since been renamed to `tools.php`
  and the nav key updated from `maintenance` to `tools` (see below).

- **Renamed `maintenance.php` to `tools.php`** (`admin/maintenance.php` → `admin/tools.php`,
  `admin/includes/admin_helpers.php`):
  - File physically renamed on disk.
  - Nav array key updated from `'maintenance'` to `'tools'`.
  - Nav `url` updated from `'maintenance.php'` to `'tools.php'`.
  - `$maint_url_h` variable updated to point to `admin/tools.php`.
  - `lum_admin_page()` active-key argument updated from `'maintenance'` to `'tools'`.
  - `README.md` directory listing and Features section updated accordingly.

- **Powered By credit moved from themes to core template system**
  (`themes/default/template.html`, `include/template.php`, `admin/config.php`,
  `install/index.php`):
  The Powered By credit is now rendered by the core and injected via the
  `{POWERED_BY}` template token, so future themes receive it automatically
  without duplicating any markup.
  - `themes/default/template.html` — hardcoded `<small>Powered by …</small>`
    footer markup replaced with the `{POWERED_BY}` token. The footer element
    itself remains in the theme so designers retain full control over placement.
  - `lumora_render_powered_by()` added to `include/template.php` — returns the
    credit HTML (or an empty string when disabled); reads
    `lumora_config('show_powered_by', '1')`. The token is populated in
    `lumora_render_page()` alongside all other standard tokens.
  - `show_powered_by` config key (default `1`) added to Admin → Configuration
    under the **Appearance** section as a toggle switch labelled **Show Powered
    By Credit**. Included in the save whitelist, `$bool_keys`, `$cfg` read,
    and config import `$safe_keys`.
  - `show_powered_by` seeded as `'1'` in the installer's `$config_defaults` for
    new installations.

- **Album delete — empty folder removal** (`admin/albums.php`): Deleting an album now
  attempts to remove its physical directory when it is empty.
  - The folder path is fetched before the DB rows are deleted.
  - After the DB deletion, `scandir()` is used to check whether the directory
    contains only `.` and `..`. If empty, `rmdir()` is called.
  - The flash message reflects the outcome: folder removed, folder non-empty and kept,
    folder not found on disk, or removal failed (with a prompt to use FTP).
  - The delete-confirm dialog wording is updated to describe the new behaviour.
  - Non-empty folders (containing images) are never touched.

### Security

- **Path traversal protection for custom header/footer files** (`include/template.php`):
  `lumora_custom_header()` and `lumora_custom_footer()` now use `realpath()` to verify
  that the configured file path resolves strictly within `LUMORA_ROOT` before reading.
  A path like `../../etc/passwd` in the config is rejected outright. Extracted into a
  new shared helper `lumora_load_custom_file()`.

- **Safer AJAX base URL in maintenance page** (`admin/maintenance.php`):
  The JavaScript `AJAX_BASE` constant now uses `lumora_base_url()` (from DB config)
  instead of reconstructing the URL from `$_SERVER['HTTP_HOST']`, eliminating a
  theoretical HTTP Host header injection vector on certain reverse-proxy setups.

### Fixed

- **`admin/maintenance.php` — Bug #6 (continued): `SyntaxError` killed entire script**:
  A PHP heredoc interprets `\n` as a real newline byte (identical to double-quoted
  strings). The `confirm()` dialog string contained `'...database?\n\n'`, which caused
  PHP to emit two literal newline characters inside the JS single-quoted string literal,
  producing an `Uncaught SyntaxError: '' string literal contains an unescaped line break`
  at column 94 on the rendered page. Because a `SyntaxError` aborts the entire script
  block before any code runs, all three maintenance tools appeared completely dead (no
  network requests, no DOM changes on button click). Fixed by escaping the newlines as
  `\\n\\n` in the heredoc, which PHP outputs as `\n\n` — the correct JS escape
  sequences.

- **Removed all `@` error-suppression operators** in compliance with PHP development
  standards (`CLAUDE.md` §Error Handling — "Never suppress errors with `@` operators"):
  - `admin/ajax_batch.php`, `admin/ajax_dimensions.php`, `admin/ajax_integrity.php`,
    `admin/ajax_thumbs.php`: `@set_time_limit()` → `set_time_limit()`.
  - `admin/albums.php`: `@mkdir()` → `mkdir()`.
  - `install/index.php`: two `@mkdir()` → `mkdir()` (requirements check and
    albums-directory creation).
  - `include/thumb.php`: `@getimagesize()`, `@imagecreatefromjpeg/png/gif/webp()`,
    `@unlink()`, `@rename()`, `@filesize()` operators removed. `is_file()` pre-checks
    added to `lumora_get_image_dimensions()` and `lumora_get_filesize()` so the common
    "file not found" case is handled without emitting a warning. Warnings for corrupt
    or unreadable image files are now correctly forwarded to the PHP error log rather
    than silently swallowed.

- **HTML/JS injection in delete-confirm dialogs** (`admin/categories.php`,
  `admin/albums.php`): Replaced `addslashes()` + inline `onsubmit="return confirm('...')"` 
  with a `data-confirm` HTML attribute populated via `h()` and read by
  `this.dataset.confirm` in the event handler. A category or album name containing
  `"`, `>`, or a newline could previously break out of the HTML attribute or terminate
  the JS string literal.

### Database migrations (DB v2 → v5)

All three migrations must be applied **in order** on existing installations.
Replace `lum_` with your actual table prefix throughout. Fresh installations from
`install/schema.sql` receive all tables and columns automatically.

**DB v2 → v3** — persistent login tokens:

```sql
CREATE TABLE IF NOT EXISTS `lum_remember_tokens` (
  `id`               bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          int UNSIGNED    NOT NULL,
  `selector`         varchar(32)     NOT NULL,
  `hashed_validator` varchar(64)     NOT NULL,
  `expires_at`       datetime        NOT NULL,
  `created_at`       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector`  (`selector`),
  KEY `user_id`          (`user_id`),
  KEY `expires_at`       (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**DB v3 → v4** — category cover thumbnails:

```sql
ALTER TABLE `lum_categories`
  ADD COLUMN `thumb_image_id` int UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'FK to images.id; 0 = auto-pick first album image';
```

**DB v4 → v5** — who-is-online tracking:

```sql
CREATE TABLE IF NOT EXISTS `lum_online` (
  `ip`          varchar(45)  NOT NULL,
  `last_action` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Installs that skip any migration degrade gracefully — all related calls fail silently
without other errors.

---

## [0.1.2] — 2026-06-10

### Added

- **`install/index.php` — auto-delete installer on success**: after writing `config.php`
  and creating the `albums/` directory, the installer now calls `ins_delete_installer()`
  which removes all files inside `install/` then removes the directory itself. On
  Unix/Linux this succeeds even while `index.php` is the running script (the process
  holds the fd in memory). The completion page shows a green success notice if
  deletion worked.

- **`install/index.php` — installer delete failure warning**: if `ins_delete_installer()`
  returns `false` (restrictive filesystem permissions, Windows), the completion page
  shows an amber warning asking the admin to remove the directory manually.

- **`admin/includes/admin_helpers.php` — persistent `install/` security warning**:
  every admin panel page now checks at render time whether `install/` exists on disk.
  If it does, a red dismissible alert is shown above the flash messages on every page
  until the directory is gone. Covers both the auto-delete failure case and existing
  installations where the installer directory was never cleaned up.

- **`latest_albums_count` config key** (default `5`) — controls how many recently
  updated albums are displayed on the home page. Set to `0` to hide the section
  entirely. Accepted range: 0–50.
  - Added to the installer's `$config_defaults` so all fresh installs receive the key.
  - Admin UI control added to **Admin → Configuration → Gallery Behavior** under the
    existing Count Album Views / Gallery Offline row.
  - Included in the save whitelist, `match` sanitisation branch
    (`max(0, min(50, ...))`) and the config import `$safe_keys` list.

- **`get_latest_updated_albums(int $limit): array`** in `include/functions.php` —
  returns public albums ordered by their newest approved image's `added_at` timestamp.
  Excludes albums with no approved images. Used by the home page "Recently Updated
  Albums" section.

### Changed

- **`admin/includes/admin_helpers.php` — admin panel branding**: navbar brand updated
  from `⚡ Lumora Admin` to `⚡ Lumora Gallery Admin` with the version string
  (`v{ver}`) appended inline as a small, muted `<span>`. Sidebar version badge
  removed (version is now shown in the topbar only). Page `<title>` updated from
  `— Lumora Admin` to `— Lumora Gallery Admin` throughout.

- **`admin/login.php`**: login card `<h1>` updated from `⚡ Lumora Admin` to
  `⚡ Lumora Gallery Admin` to match.

- **`admin/dashboard.php`**, **`admin/account.php`**, **`admin/config.php`**: added
  missing `@copyright`/`@license` GPL v3 headers to bring all three files in line
  with the rest of the codebase (every other file already had them).

- **`TODO.md`**: marked all four Maintenance items complete (`[x]`): Reload
  Dimensions, Update Thumbnails, File Integrity Check, and Album Scope Selector were
  all fully implemented in a previous session but not ticked off. Legal items (GPL v3
  licence, developer credits) and the "Make the number of last updated Albums
  selectable in config" item also marked complete.

### Fixed

- **`admin/maintenance.php` — Bug #6: maintenance actions non-functional**:
  Three compounding issues prevented all three maintenance tools (Integrity Scan,
  Reload Dimensions, Regenerate Thumbnails) from working:
  1. **Null guard missing on `$cancel`**: each IIFE guarded `$start` but called
     `$cancel.addEventListener()` unconditionally. A null `$cancel` would throw a
     `TypeError` that silently aborted the entire `DOMContentLoaded` callback, leaving
     all three tools without click listeners. Fixed by adding `!$cancel` to each guard.
  2. **Relative AJAX URLs**: `fetch('ajax_integrity.php', …)` etc. resolved against
     `window.location`, which breaks on sub-path installs or under URL rewriting.
     Fixed by injecting an absolute `AJAX_BASE` constant. The constant was already
     declared but never used — all four `fetch()` calls now use it.
  3. **AJAX_BASE derived from `base_url` config**: if `base_url` is empty or wrong,
     `AJAX_BASE` would also be wrong. Fixed by constructing the URL directly from
     `$_SERVER['HTTPS']`, `$_SERVER['HTTP_HOST']`, and `$_SERVER['SCRIPT_NAME']` —
     always accurate regardless of config state.
  4. **Silent error masking**: when `fetchChunk()` fails (403, 404, 500, or network
     error) it correctly shows "Error: …" in the status element, but the surrounding
     `startScan()`/`startTool()` loop then calls `finishScan()`/`finishTool()` which
     immediately overwrites the status with "Scan complete." and snaps the progress bar
     to 100% — making every AJAX failure look like an instant silent success. Fixed by
     adding a `fetchFailed` flag: when set, `finishScan()`/`finishTool()` is skipped
     so the error message remains visible.

- **`admin/categories.php` — Bug #1** `cat_parent_options()`: malformed `<option>` HTML
  (`<option value="0"— Root...` was missing the closing `>` after the attribute value),
  causing the "Root (no parent)" option to render as broken markup in every category
  parent dropdown.

- **`admin/categories.php` — Bug #7** Dead heredoc referencing `$s_total` before it was
  defined generated a PHP `E_WARNING: Undefined variable` on every page load.
  Removed the dead heredoc and redundant `str_replace()` call; the list is now built
  directly via string concatenation with `$s_total` correctly in scope.

- **`admin/config.php` — Config export always returned HTTP 403**: the export URL
  placed the CSRF token in `$_GET`, but the code called `lumora_csrf_validate()` which
  checks `$_POST['csrf_token']` only. Replaced with an inline `$_GET['csrf_token']`
  check so the export link works as intended.

- **`include/bootstrap.php` — DB error leaked connection details**: the `RuntimeException`
  message from a failed PDO connection (which may include host, dbname, or username)
  was passed directly to `htmlspecialchars()` and output to the browser. Now logs the
  full message via `error_log()` and shows only a generic message to visitors.

- **`include/bootstrap.php` — `@` error suppression on timezone set**: replaced
  `@date_default_timezone_set()` (which violates the "Never suppress errors with @"
  standard) with an explicit `in_array(..., \DateTimeZone::listIdentifiers())` check
  before calling `date_default_timezone_set()`. Invalid identifiers fall back to UTC
  with no suppressor needed.

- **`admin/albums.php` — SQL concatenation in list query**: the optional category
  filter was applied by appending `' WHERE a.category_id = ' . $filter_cat` to the
  SQL string, violating "use prepared statements exclusively". Replaced with two
  dedicated queries, each using `?` parameter binding.

- **`admin/batch.php` — CSRF token injected into JS with HTML-escaping**: used
  `'{$csrf}'` (HTML-escaped) instead of `{$csrf_js}` (json-encoded). While safe in
  practice for hex tokens, the pattern was inconsistent with `maintenance.php`'s
  correct `json_encode()` approach. Fixed to use `$csrf_js = json_encode(...)` and
  `var csrf = {$csrf_js};`.

### Identified (deferred — no code change)

The following issues were found during the audit but deferred because they require
an architectural or policy decision rather than a straightforward fix:

- **`@` suppression on filesystem operations** (`@getimagesize`, `@imagecreatefromjpeg`,
  `@rename`, `@unlink`, `@mkdir`) across `include/thumb.php`, `include/functions.php`,
  `admin/albums.php`, and `install/index.php`. These suppress E_WARNING from the PHP
  runtime. Eliminating them requires a consistent policy (e.g. a filesystem wrapper
  that converts warnings to exceptions) and is a larger refactor.
- **Global `$LUMORA_CONFIG`** in `include/functions.php` — violates "avoid global
  state"; migrating to a static class property or registry is an architectural change.
- **Duplicated `<optgroup>` album-selector loop** in `admin/batch.php` and
  `admin/maintenance.php` — should be extracted to `admin/includes/admin_helpers.php`.

### Audited (no change needed — already compliant)

The following TODO items and suspected issues were confirmed **already fixed** in the
current codebase (all verified by reading each file in full):

- Bug #2: `$new_count` initialised to `0` before conditional in `batch.php`.
- Bug #3: Installer Step 1 POST handler is reachable and functions correctly.
- Bug #4: All `config.php` output values are pre-escaped with `h()` into `$v_*`
  variables before heredoc interpolation; no `str_replace()` escaping attempt exists.
- Bug #5: `declare(strict_types=1)` present in all 26 PHP source files.
- Bug #6: All three maintenance tools (integrity scan, reload dimensions,
  regenerate thumbnails) are fully implemented with proper `fetch()` AJAX handlers.
- Bug #8: Installer uses `date('Y-m-d H:i:s', $ts)` for human-readable timestamps.

---

## [0.1.1] — 2026-06-08

### Added

- **8 new configuration options** — all accessible in Admin → Configuration under the
  new **Gallery Behavior** and **Upload & Image Limits** sections:
  - `timezone` (default `UTC`) — PHP timezone string (e.g. `Europe/Helsinki`) applied
    at bootstrap via `date_default_timezone_set()`; validated against
    `DateTimeZone::listIdentifiers()`.
  - `thumb_quality` (default `85`) — JPEG/WebP thumbnail quality 1–100; replaces the
    hardcoded value in both the Imagick and GD thumbnail engines.
  - `max_upload_size_mb` (default `0` = unlimited) — maximum file size accepted during
    Batch Add; files exceeding the limit are skipped with an `error_log()` entry.
  - `max_image_width` / `max_image_height` (default `0` = no limit) — resize originals
    before storing if they exceed the configured dimensions; applied atomically via a
    temp file + rename. Quality is hardcoded at 92 for originals, independent of
    `thumb_quality`.
  - `count_album_views` (default `1`) — toggle the album hit counter; `0` disables
    counting without removing existing counts.
  - `log_mode` (`off` / `errors` / `all`) — controls `lumora_log()`: `off` = no-op;
    `errors` = PHP `error_log()` for error-type events only; `all` = PHP error log +
    DB insert into `{PREFIX}log` (requires DB version 2 — see migration below).
  - `gallery_offline` (default `0`) — maintenance mode; non-admin visitors receive
    HTTP 503 + `Retry-After: 3600`; admins always see the real gallery.

- **`{PREFIX}log` table** — new table added in DB version 2, used when
  `log_mode = all`. Columns: `id`, `type` (varchar 16), `message` (text), `ip`
  (varchar 45), `created_at` (datetime). Keyed on `(type, created_at)`.
  See *Database migration* below for the SQL.

- **`lumora_log(string $type, string $message)`** in `include/functions.php` —
  central logging helper; behaviour controlled entirely by `log_mode`. Writes to
  `{PREFIX}log` are wrapped in `catch(\Throwable)` so pre-v2 installs without the
  table are unaffected at any `log_mode` setting.

- **`lumora_resize_original(string $path, int $max_w, int $max_h): bool`** in
  `include/thumb.php` — resizes an original image in-place when it exceeds the
  configured dimension limits. Uses a temp file + atomic rename to avoid partial
  writes; falls back to copy+unlink on cross-filesystem moves.

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

- `include/bootstrap.php` — step 13 added: reads `timezone` config and calls
  `date_default_timezone_set()`; falls back to UTC silently on invalid identifier.

- `include/template.php` → `lumora_render_page()` — gallery offline check: returns
  HTTP 503 with `Retry-After: 3600` and a maintenance message to non-admins;
  admins always see real content.

- `include/thumb.php` → `lumora_generate_thumb()` — `int $quality = 0` parameter
  added; `0` reads from `thumb_quality` config. Both Imagick and GD engines accept
  and apply the quality value.

- `include/thumb.php` → `lumora_batch_add_image()` — now applies (1) size limit
  check, (2) optional original resize, then (3) thumbnail generation in that order.

- `album.php` — album hit counter now gated by `count_album_views` config; album
  visits logged via `lumora_log()`.

- `ajax_hit.php` — if `gallery_offline = 1`, returns `{"ok":true}` without
  incrementing (prevents JS console errors for offline visitors).

- `install/index.php` — `$config_defaults` now seeds all 8 new config keys on fresh
  installs.

- `admin/config.php` — new **Gallery Behavior** section (Timezone, Logging Mode,
  Count Album Views, Gallery Offline Mode) and **Upload & Image Limits** section
  (Thumbnail Quality, Max File Size, Max Original Width/Height) added to the
  settings form.

- `admin/includes/admin_helpers.php` — **Maintenance** (🔧) entry added to the
  sidebar navigation between Configuration and Account.

- Album **Folder Path** field in Admin → Albums now explicitly supports nested paths
  (`ShowName/Season2/EpisodeSlug`). Updated placeholder, hint text, and removed the
  `pattern` attribute that only allowed a flat name.

- `README.md` — Image & Thumbnail Storage section rewritten to show the nested
  directory layout with a real-world example; Coppermine Migration section updated
  with step-by-step folder path instructions; Configuration table expanded with all
  8 new config keys.

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
  probing CLI binary paths. `im_path` removed from the config defaults seeded on
  installation.

- `README.md` — Requirements table updated (`Imagick (preferred) or GD`); Thumbnail
  generation section rewritten to describe the extension-based approach; `im_path`
  removed from the Configuration table.

### Database migration (DB v1 → v2)

Run the following SQL once on existing installations (replace `lum_` with your actual
table prefix if different):

```sql
CREATE TABLE IF NOT EXISTS `lum_log` (
  `id`         bigint UNSIGNED  NOT NULL AUTO_INCREMENT,
  `type`       varchar(16)      NOT NULL,
  `message`    text             NOT NULL,
  `ip`         varchar(45)      NOT NULL DEFAULT '',
  `created_at` datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The table is only written to when `log_mode = all`. If the table is absent,
`lumora_log()` catches the exception silently — no breakage occurs at any
`log_mode` setting on pre-v2 installs.

Fresh installations from `install/schema.sql` receive the table automatically.

---

## [0.1.0] — 2026-06-06

### Changed

- Added `declare(strict_types=1);` to all 21 PHP files (`include/`, `admin/`,
  `admin/includes/`, `install/`, public entry points, `version.php`,
  `config.sample.php`).

# History — Lumora Gallery

Long-term archive of completed work, migrated from TODO.md on release.

---

## v1.7.1 — Released 2026-06-19

### Bug Fixes

- [x] Albums missing added/updated date in album info display — regression from a prior fix lost on file overwrite; re-implemented in `ThemeRenderer::renderCatgrid()` and both core theme stylesheets (`.lum-card-date`).
- [x] Thumbnails missing added/updated date in thumbnail info display — regression from a prior fix lost on file overwrite; re-implemented in `ThemeRenderer::renderThumbgrid()` and both core theme stylesheets (`.lum-thumb-date`).
- [x] Album cards showed the Lumora import date (`created_at`) as the "Updated" date instead of when content was actually last added; `GalleryService::getAlbums()` now selects `MAX(images.added_at)` as `latest_added_at`; `ThemeRenderer::renderCatgrid()` prefers this field over `created_at` and relabels the span from "Added" to "Updated".
- [x] Sort bar overflowed past the viewport edge on narrow phones — fixed with `flex-wrap: wrap` in the `@media (max-width: 575px)` block of both core theme stylesheets (`default/lumora.css`, `classic-fansite/fansite.css`).
- [x] Category list header labels overflowed past the viewport edge on narrow phones — fixed by shrinking header cell font-size and padding to match the data cells at the same breakpoint in both core themes.
- [x] Corrected the official Lumora Gallery website URL in `ThemeRenderer.php`.

### Added

- [x] **Admin Password Recovery** (`admin/forgot_password.php`, `admin/reset_password.php`, `include/auth.php`, `admin/login.php`): Admins who have lost their password can generate a secure reset link without SMTP. Link is written to `lumora_recovery.txt` in the gallery root; best-effort `mail()` send is also attempted when an email is configured. Same split-token scheme as Remember Me. New DB table `{PREFIX}password_reset_tokens`; `LUMORA_DB_VERSION` bumped to 7.
- [x] **Regenerate Missing Thumbnails** (`admin/tools.php`, `admin/ajax_missing_thumbs.php`): Tool 4 on Admin → Tools. Scans all images in scope (entire gallery or a selected album) and regenerates thumbnails only where the thumbnail file is missing or empty, skipping images with valid thumbnails. Keyset-paginated AJAX handler. JSON response: `{ checked, regenerated, skipped, no_orig, last_id, errors[], done }`.
- [x] **Admin Image Search** (`admin/images.php`, `include/services/GalleryService.php`, `install/schema.sql`): Administrators can search images by filename or title from Admin → Images, scoped to a selected album or across all albums. Cross-album results include the category › album path. `GalleryService::searchImages()` and `GalleryService::countSearchImages()` added. Pagination, bulk delete, bulk move, and single-image actions all preserve the active search term. Optional B-tree prefix indexes for `filename(191)` and `title(191)` documented.
- [x] **Theme Metadata from CSS Headers** (`include/functions.php`, `admin/config.php`, `themes/default/lumora.css`, `themes/classic-fansite/fansite.css`): Theme display names, author, and design URI can be declared in a WordPress-style CSS header comment at the top of the primary stylesheet. `lumora_theme_primary_stylesheet()` and `lumora_get_theme_meta()` added to `include/functions.php`. Admin → Configuration → Appearance shows `Theme Name` in the Active Theme dropdown and a reference table for all installed themes. Both core themes updated with standardised metadata headers.
- [x] **Coppermine Importer — Metadata Sync tool** (`plugins/coppermine-importer/admin/sync_metadata.php`, `plugins/coppermine-importer/CoppermineImporter.php`, `plugins/coppermine-importer/admin/index.php`, `plugins/coppermine-importer/version.php`, `plugins/coppermine-importer/README.md`): Standalone companion to the main import wizard. Syncs category and album cover-thumbnail selections from an existing Coppermine database to an already-imported Lumora gallery, without a full re-import. Albums matched by folder path; categories matched by full name-path from root. Three-step page: Credentials → Preview → Report. Preview mode shows per-record status badges; apply step runs inside a single transaction with rollback on failure and writes a timestamped audit log to `plugins/coppermine-importer/logs/`.

### Changed

- [x] Album and category card metadata restructured from a single inline string into individually styled rows (`ThemeRenderer::renderCatgrid()`, `.lum-card-meta`, `.lum-card-images`, `.lum-card-views`, `.lum-card-subcats`, `.lum-card-albums`). All core themes updated with matching CSS.

---

## v1.7.0 — Released 2026-06-16

### Update Checker (Phase 1)

- [x] `UpdateService` static service class — fetches remote update endpoint, caches result
  in config table for 24 hours, exposes `check()`, `getCachedStatus()`, `hasCachedUpdate()`,
  and `isCacheExpired()`. Uses `version_compare()` for semantic comparison. Falls back to
  stale cache on network failure. No gallery data is ever transmitted.
- [x] `admin/update.php` — Updates admin page showing installed version, status badge,
  last-checked timestamp, changelog/download links when an update is available, and
  PHP-version compatibility warning. Renders from cache only at PHP time; JS auto-triggers
  AJAX check when cache is expired to avoid server-side blocking.
- [x] `admin/ajax_update_check.php` — AJAX endpoint for forced update check; returns full
  status array as JSON; validates CSRF and admin authentication.
- [x] `admin/includes/admin_helpers.php` — Updates (🔔) nav item added between Import and
  Account. Red `!` badge shown whenever cached status indicates an update (no HTTP call).
- [x] `admin/dashboard.php` — Dismissible info-bar shown when cached status indicates an
  update is available; includes changelog/download links; no HTTP call at render time.
- [x] `include/bootstrap.php` — `UpdateService.php` loaded in step 7 alongside other
  service classes.

### Bug Fixes

- [x] Albums missing added/updated date in album info display.
- [x] Thumbnails missing added/updated date in thumbnail info display.
- [x] Album and thumbnail info stats (views, resolution, image counts) restructured
  from a single inline string into individually styled rows.

---

## v1.6.0 — Released 2026-06-15

### Coppermine Importer Plugin (`plugins/coppermine-importer/`)

- [x] Official migration plugin for importing Coppermine Gallery (CPG 1.4–1.6) categories,
  albums, and image metadata into Lumora. Metadata-first; image files are not moved.
- [x] `version.php` — single source of truth for plugin version (`LUMORA_CPG_IMPORTER_VERSION`).
- [x] `plugin.json` — plugin manifest for discovery by the migration hub.
- [x] `CoppermineImporter` class — separate PDO connection to CPG database; keyset-paginated
  `importCategories()`, `importAlbums()`, `importImages()`, and `validate()` methods;
  schema-adaptive SELECT handles CPG column name variations across versions.
- [x] `admin/index.php` — four-step admin wizard: Credentials → Preview → Import → Done.
  Stores state and ID maps in `$_SESSION`; re-import warning with confirmation checkbox.
- [x] `admin/ajax_import.php` — AJAX chunk processor for three import actions plus `finish`;
  CSRF and admin auth validated on every call; integer-key-preserving session maps.
- [x] Stop Import button — halts after current in-flight batch without data loss.
- [x] Import status tracking — records source, date, counts, and plugin version in
  `{PREFIX}migration_status` after successful import.
- [x] Re-import protection — detects prior migration and requires confirmation before
  re-running; warns that duplicates may result.
- [x] Preserve existing Coppermine `albums/` folder structure — no file renaming or moves
  required; album paths derived from `cpg_pictures.filepath` with `keyword` fallback.

### Migration Framework (Core)

- [x] `MigrationService` static service class — import status tracking, event logging,
  plugin discovery (scans `LUMORA_PLUGINS_PATH/*/plugin.json`), and version compatibility
  checking.
- [x] `admin/migrate.php` — migration hub: discovers importer plugins, shows each as a card
  with name, description, version, compatibility badge, and previous migration status.
- [x] `admin/includes/admin_helpers.php` — Import (📥) nav entry added.
- [x] `include/bootstrap.php` — `LUMORA_PLUGINS_PATH` constant and `MigrationService.php`
  loaded in step 7.
- [x] `install/schema.sql` DB v6 — `{PREFIX}migration_status` and `{PREFIX}migration_log`
  tables.

### Bug Fixes

- [x] Album cards missing view count — `renderCatgrid()` displayed image count but omitted
  `hits`; fixed by rendering both values with spans for per-theme styling.

---

## v1.5.0 — Released 2026-06-15

### Technical Debt (V1 → V2 Prerequisites)

- [x] **0.1 Migrate business logic from free functions to service classes** — Introduced
  `GalleryService`, `ThemeRenderer`, `ThumbnailService`, and `LumoraConfig` in
  `include/services/`. Legacy free functions retained as thin forwarding wrappers;
  no callers required changes. Bootstrap load order updated to require service classes
  before the legacy include files.

- [x] **0.2 Replace global `$LUMORA_CONFIG` with a config service class** — `LumoraConfig`
  static class (private `$cache` array, `load()`, `get()`, `set()`) replaces the
  module-level global. `lumora_config()` and `lumora_set_config()` forwarding wrappers
  preserved for backward compatibility.

- [x] **0.3 Replace GET-based CSRF token on config export** — Config export anchor link
  (`?export=1&csrf_token=...`) replaced with a POST form (`action="export"`). CSRF
  token now travels in the request body only; validation delegated to the existing
  `lumora_csrf_validate()` call at the top of the POST block. Token no longer appears
  in browser history, server logs, or `Referer` headers.



## v1.0.0 — Released 2026-06-13

### Maintenance

- [x] Reload file dimensions and size information
- [x] Update thumbnails
- [x] File integrity check
- [x] Add album selector (all albums / specific album) for maintenance tools

---

### Bug Fixes

#### 1. Fix broken root category option in `admin/categories.php`

- [x] Correct the HTML markup.
- [x] Verify the "Root (no parent)" option appears correctly in the parent category dropdown.
- [x] Confirm category creation and editing work as expected after the fix.

#### 2. Fix undefined `$new_count` in `admin/batch.php`

- [x] Ensure `$new_count` is always defined before being used.
- [x] Prevent PHP undefined-variable notices on initial page load.
- [x] Ensure generated JavaScript remains valid when no album is selected.
- [x] Verify the batch page loads correctly both before and after album selection.

#### 3. Fix unreachable Step 1 form processing in `install/index.php`

- [x] Review installer control flow.
- [x] Ensure Step 1 form submissions are processed correctly.
- [x] Verify database credentials can be submitted and validated.
- [x] Confirm the installer progresses normally to the next step.

#### 4. Fix ineffective output escaping in `admin/config.php`

- [x] Review all configuration values rendered in the page.
- [x] Apply escaping before output is generated.
- [x] Remove or replace the ineffective `str_replace()` logic.
- [x] Ensure configuration values are safely displayed in form fields and page content.

#### 5. Add `declare(strict_types=1)` to all PHP files

- [x] Add `declare(strict_types=1);` immediately after the opening PHP tag in all applicable files.
- [x] Review for any type-related issues introduced by strict mode.
- [x] Resolve any compatibility problems discovered during testing.
- [x] Verify the application continues to function correctly after the update.

#### 6. Fix non-functional maintenance actions in `maintenance.php`

- [x] Investigate frontend and backend execution flow.
- [x] Determine why requests are not being processed.
- [x] Restore functionality for all three maintenance operations.
- [x] Add error handling and user feedback where appropriate.
- [x] Verify each action completes successfully and reports progress/results to the user.

#### 7. Clean up undefined `$s_total` usage in `admin/categories.php`

- [x] Remove the undefined variable usage.
- [x] Refactor content generation to avoid temporary invalid state.
- [x] Ensure functionality remains unchanged.

#### 8. Improve installer timestamp output in `install/index.php`

- [x] Replace the timestamp with a human-readable date/time format.
- [x] Use a consistent format such as `date('Y-m-d H:i:s')`.
- [x] Verify generated configuration files contain readable creation timestamps.

#### 9. "Powered by" invisible in some themes

- [x] Remove the Bootstrap-specific `text-muted` class from the `<small>` element in `lumora_render_powered_by()`.
- [x] Add an explicit `color` to `.lum-footer` in the default theme's `lumora.css` so its visual appearance is unchanged.
- [x] Verify the classic-fansite theme inherits `color: var(--fs-footer-text)` from `.fs-footer` (already set) — no change needed there.
- [x] Confirm the credit is legible in both themes.

---

### Code Audit Against PHP Development Standards

- [x] Deprecated patterns
- [x] Legacy coding styles
- [x] Naming convention violations
- [x] Missing type declarations
- [x] Missing return types
- [x] Inconsistent error handling
- [x] Direct database access patterns that violate current architecture
- [x] Security concerns
- [x] Input validation issues
- [x] Output escaping issues
- [x] Documentation deficiencies
- [x] Any code that conflicts with current project guidelines
- [x] Produce report grouped by: Critical issues, Recommended fixes, Style/compliance issues, Technical debt items
- [x] Apply straightforward low-risk fixes automatically.
- [x] Document deferred architectural issues with recommended remediation options.
- [x] Provide summary of files reviewed, files modified, remaining compliance issues, recommended future cleanup tasks.

---

### Dashboard

- [x] Change text "Lumora Admin" to "Lumora Gallery Admin"
- [x] Add current version after "Lumora Gallery Admin" text

---

### Albums

- [x] Delete physical folder if it is empty when album is deleted
- [x] Category thumbnail support
- [x] Album thumbnail support

---

### Authentication

- [x] Stay logged in feature
- [x] Remember me checkbox

---

### Installation

- [x] Automatically delete `/install` after successful installation
- [x] If deletion fails, display Admin warning

---

### Front Page

- [x] Move statistics boxes to bottom
- [x] Add "Who is online" feature based on `coppermine_onlinestats`
- [x] Show last updated Albums above Categories
- [x] Make the number of last updated Albums selectable in config

---

### Legal

- [x] Add GPLv3 license
- [x] Add developer credits

---

### Themes

- [x] Create a "Classic Fansite" starter theme inspired by the classic fansite layout reference image.
- [x] Create a reusable fansite theme framework that can be easily customized for different fandoms, celebrities, TV shows, movies, games, and communities.
- [x] Implement a traditional fansite-style homepage layout:
  - [x] Header/banner image area
  - [x] Main navigation menu
  - [x] Latest Updated Albums section
  - [x] Categories section
  - [x] Latest Additions (images) section
  - [x] Ensure the theme is fully responsive while preserving the classic fansite appearance on desktop.
  - [x] Document how to create and customize new themes based on the Classic Fansite starter theme.

---

### Planned Configuration Options

- [x] Timezone difference relative to GMT
- [x] Quality for JPG thumbnails
- [x] Selectable max size for uploaded files
- [x] Selectable max width or height for uploaded pictures
- [x] Count Album Views
- [x] Coppermine-style logging mode
- [x] Gallery offline mode

---

### Add "Move to Another Album" Functionality

- [x] Add a "Move to Album" action within image/file management.
- [x] Support moving a single image/file to another album.
- [x] Support bulk-moving multiple selected images/files.
- [x] Provide an album selection interface.
- [x] Preserve image metadata, comments, views, favorites, and other related data during the move.
- [x] Update album counts automatically after the operation.
- [x] Display confirmation and success/error messages.
- [x] Verify moved items appear correctly in the destination album and are removed from the source album.

---

### Enhance Image Management Feature

- [x] Edit image details
- [x] Move images between albums
- [x] Delete images
- [x] Bulk actions on selected images
- [x] Replace image
- [x] Thumbnail regeneration (where applicable)

---

### Move Powered By Credit from Themes to Gallery Configuration

- [x] Remove Powered By credit handling from theme files.
- [x] Move credit rendering to the core gallery/template system.
- [x] Ensure the credit is displayed consistently across all themes.
- [x] Allow future configuration of the credit from gallery settings if desired.
- [x] Verify existing themes continue to function correctly after the change.
- [x] Eliminate duplicated Powered By code across theme templates.

---

### Miscellaneous

- [x] Rename Maintenance to Tools
- [x] Display image id on images.php
- [x] Rename maintenance.php to tools.php
- [x] Remove version number from credit footer

---

### Category Structure

- [x] Current layout (as-is) — existing category display preserved.
- [x] Table/row layout — one category per row with thumbnail, name/description, album count, image count.
- [x] Add user-selectable option (setting) to switch between current category layout and category-per-row table layout.
- [x] Preserve existing functionality and sorting behavior.
- [x] Ensure responsive behavior on mobile, tablet, and desktop screens.
- [x] Reuse existing category metadata (thumbnail, title, description, album count, file/image count).
- [x] Make the new layout match the overall structure shown in the reference screenshot.
- [x] All CSS and template changes implemented across all existing themes (default + classic-fansite).
- [x] Ensure visual consistency within each theme using existing theme variables, colors, spacing, typography, borders, and component styles.
- [x] Verify that switching themes does not break the new layout.
- [x] The layout option is available and functions identically regardless of the active theme.

---


# Lumora Gallery

A modern PHP image gallery designed as a clean, fast replacement for Coppermine Gallery. Built for fansites with large collections — tested against scenarios with 9,000+ images per album and 500,000+ total images.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| PHP extensions | PDO, PDO_MySQL, Imagick (preferred) or GD |
| Web server | Apache 2.4+ or Nginx |

No Composer required. Upload and go.

---

## Installation

1. **Upload** the `Lumora/` folder to your web server (e.g. `public_html/gallery/`).
2. **Make `albums/` writable** by the web server (`chmod 755 albums/` or as needed by your host).
3. **Visit `/install/`** in your browser and follow the three-step wizard:
   - Step 1: Requirements check + database credentials
   - Step 2: Database setup + admin account creation
   - Step 3: `config.php` written — installation complete
4. **Delete or protect the `install/` directory** after installation. (The installer attempts this automatically; verify it is gone.)
5. Log in at `admin/` with the credentials you created.

### Manual install (advanced)

Copy `config.sample.php` to `config.php` and fill in your database details, then import `install/schema.sql` into your database.

---

## Directory Structure

```
Lumora/
├── admin/                      Admin panel
│   ├── includes/               Admin-only helpers (flash messages, per-page selector, pagination controls, page renderer)
│   ├── index.php               Admin entry point — redirects unauthenticated requests to login
│   ├── account.php             Account management (username, email, password)
│   ├── users.php               User management (create/edit/delete staff accounts, roles, enable/disable, password reset)
│   ├── albums.php              Album management
│   ├── batch.php               Batch-add images from FTP
│   ├── ajax_batch.php          AJAX endpoint for chunked batch processing
│   ├── ajax_image_delete.php    AJAX endpoint for bulk image deletion
│   ├── ajax_image_move.php      AJAX endpoint for bulk image move between albums
│   ├── ajax_image_rethumb.php   AJAX endpoint for single-image thumbnail regeneration
│   ├── ajax_missing_thumbs.php  AJAX endpoint for missing-thumbnail regeneration (Tool 4)
│   ├── ajax_integrity.php      AJAX endpoint for integrity scan chunks
│   ├── ajax_integrity_delete.php  AJAX endpoint for deleting orphaned records
│   ├── ajax_dimensions.php     AJAX endpoint for reload-dimensions chunks
│   ├── ajax_thumbs.php         AJAX endpoint for thumbnail regeneration chunks
│   ├── ajax_update_check.php   AJAX endpoint for forced update check
│   ├── ajax_update_perform.php AJAX endpoint for multi-stage in-dashboard update (run_stage / rollback / abort)
│   ├── ajax_run_migrations.php AJAX endpoint for running schema migrations
│   ├── ajax_installation_health.php  AJAX endpoint for installation health check (9 system checks)
│   ├── categories.php          Category management
│   ├── config.php              Gallery settings, export/import
│   ├── dashboard.php           Stats overview
│   ├── images.php              Image management (edit, delete, move, bulk actions)
│   ├── installation.php        Installation Settings — update base URL after domain/server migration; nine-item health check; configuration change log
│   ├── migrate.php             Migration hub — discovers and launches importer plugins
│   ├── tools.php               Admin tools (File Integrity Check, Reload Dimensions, Regenerate Thumbnails, Regenerate Missing Thumbnails)
│   ├── update.php              Updates page — version status, in-dashboard updater (10-stage AJAX workflow with rollback), schema migrations, update history
│   ├── forgot_password.php  Password recovery — generates a reset link to lumora_recovery.txt
│   ├── reset_password.php   Password reset — validates token, sets new password
│   ├── login.php / logout.php
│   └── admin.css
├── albums/                     Image storage — original + thumb_* thumbnails
├── docs/                       CHANGELOG.md, HISTORY.md
├── include/                    Core PHP includes
│   ├── services/               Static service classes (business logic layer)
│   │   ├── LumoraConfig.php    Config cache — load(), get(), set()
│   │   ├── GalleryService.php  Category, album, image, stats, visitor-tracking queries
│   │   ├── ThumbnailService.php Thumbnail generation, resizing, metadata, batch-add
│   │   ├── ThemeRenderer.php   All HTML output: pages, grids, breadcrumbs, lightbox
│   │   ├── MigrationService.php Import status tracking, plugin discovery, event logging
│   │   ├── UpdateService.php   Remote update check, version comparison, 24-hour cache
│   │   ├── SchemaService.php   Schema migration engine — discover, run, rollback PHP class migrations
│   │   ├── AbstractUpdateProvider.php  Provider interface — fetchMetadata(), buildArchiveUrl(), factory
│   │   ├── GitHubUpdateProvider.php    GitHub Releases API provider — metadata, SHA-256, archive URL
│   │   ├── UpdaterService.php  Update orchestrator — 10-stage workflow, lock file, backup, rollback
│   │   ├── InstallationService.php  Installation settings detection, migration helpers, health checks, audit logging
│   │   └── UserService.php     User CRUD, role constants, permission framework (ROLE_PERMISSIONS, roleHasPermission, currentUserHasPermission)
│   ├── migrations/             Versioned PHP schema migration classes
│   │   ├── AbstractMigration.php              Base class — up(), down(), tableExists(), columnExists(), indexExists()
│   │   ├── Migration0001_CreateMigrationsTable.php  Self-bootstrapping first migration — creates {PREFIX}migrations table
│   │   ├── Migration0002_CreateConfigChangesTable.php  Creates {PREFIX}config_changes audit table for installation setting changes
│   │   └── Migration0003_UpdateUsersTableForRoles.php  Adds is_active column; updates role ENUM to admin/moderator/contributor
│   ├── bootstrap.php           Load order, constants
│   ├── db.php                  PDO singleton (LumoraDB)
│   ├── functions.php           Utility helpers and legacy forwarding wrappers
│   ├── auth.php                Login, CSRF, session, password management
│   ├── thumb.php               Legacy forwarding wrapper → ThumbnailService
│   └── template.php            Legacy forwarding wrapper → ThemeRenderer
├── install/                    Web-based installer (delete after use)
│   ├── index.php
│   └── schema.sql
├── plugins/                    Optional plugins
│   └── coppermine-importer/    Official Coppermine → Lumora migration plugin
│       ├── CoppermineImporter.php  Core importer class (categories, albums, images, cover sync)
│       ├── plugin.json         Plugin manifest (consumed by admin/migrate.php)
│       ├── version.php         Single source of truth for plugin version
│       ├── README.md           Plugin documentation and Metadata Sync tool reference
│       └── admin/              Plugin admin pages
│           ├── index.php       Four-step import wizard
│           ├── ajax_import.php AJAX chunk processor for import steps
│           └── sync_metadata.php Post-import cover-thumbnail sync tool
├── themes/                     Theme folders
│   ├── default/
│   │   ├── template.html       Bootstrap 5 base template
│   │   └── style.css           Gallery styles
│   └── classic-fansite/
│       ├── template.html       Classic fansite layout (banner, sticky nav, centred panel)
│       ├── style.css           Fully variable-driven styles with fandom colour presets
│       ├── custom.css          Optional per-site CSS overrides (loaded after style.css)
│       └── README.md           Customisation guide + theme creation walkthrough
├── ajax_hit.php                Public image view counter endpoint (fire-and-forget POST)
├── album.php                   Public album view (pagination, sort, lightbox)
├── index.php                   Public home, category browse, special views
├── migrate.php                 CLI-only schema migration runner (--dry-run, --status, --rollback)
├── config.sample.php           Template for manual config.php
└── version.php                 Version constants
```

---

## Image & Thumbnail Storage

Images and their thumbnails are stored together in the same album folder. Album folders
use **human-readable nested paths** that you define when creating the album, so your
`albums/` directory mirrors your category tree and stays navigable over FTP:

```
albums/
  Xena/
    Season1/
      1x01-SinsOfThePast/
          extant_XWP_1x01_01808.jpg       ← original
          thumb_extant_XWP_1x01_01808.jpg ← thumbnail (thumb_ prefix)
          extant_XWP_1x01_01809.jpg
          thumb_extant_XWP_1x01_01809.jpg
    Season2/
      2x01-RevelationsOfTheBirthOfANew/
          ...
  00042/       ← numeric fallback when no folder path was supplied
      photo.jpg
      thumb_photo.jpg
```

Folder path rules: letters, digits, hyphens, underscores, dots; `/` for subfolders;
no path traversal (`..`). Set once at album creation — cannot be renamed afterwards
without moving files on disk.

This layout is compatible with Coppermine's `albums/` directory structure, making
migration straightforward — point Lumora at the same `albums/` directory and run
**Batch Add** to index everything.

---

## Features

### Public gallery
- Home page: recently updated albums, root category grid, gallery stats, and a Who Is Online strip
- Category and album browsing with selectable layout (card grid or Coppermine-style list with recursive album and image counts)
- Album view with sortable thumbnails (position, newest, oldest, most viewed, filename)
- Pagination (configurable images per page)
- Full-image lightbox via [PhotoSwipe 5](https://photoswipe.com/) (ESM, no global namespace)
- Image resolution displayed under each thumbnail
- Hit counter for albums and images (session-throttled; image counts recorded via lightbox `change` event → `ajax_hit.php`)
- Special views: Most Viewed, Latest, Random

### Admin panel
- **Dashboard** — stats cards + latest images
- **Users** — paginated staff account list (10/25/50 per page); create accounts with role selector; edit username, email, and role; reset any account's password (no current-password check); enable/disable accounts; delete accounts; guards prevent self-deletion, self-deactivation, and removal of the last active administrator; migration guard redirects to the Updates page if schema migration hasn't run yet
- **Categories** — paginated list (25/50/100 per page, session-persisted; item count summary; controls above and below the table); create, edit, delete; nested (parent/child); re-parents children on delete; optional cover image (ID-based, falls back to first image in category's albums)
- **Albums** — paginated list (25/50/100 per page, session-persisted; item count summary; controls above and below the table; category filter preserved across pages); create, edit, delete; auto-generated folder names or custom; filesystem directory creation; empty folder removed automatically on album delete
- **Images** — per-album paginated image grid (24/page); search images by filename or title (scoped to an album or across all albums; cross-album results include the category › album path); edit title, sort position, and visibility; optional file replacement via multipart upload (validates type, size, image integrity; regenerates thumbnail and updates dimensions/filesize); single-image delete cleans up disk files and resets album/category cover references; bulk delete and bulk move to another album (up to 500 images per AJAX call); per-image thumbnail regeneration
- **Batch Add** — scan `albums/{folder}/` for new images, process in 50-image AJAX chunks (handles 9000+ without timeout)
- **Configuration** — all settings in one form; theme selector; live image processor status; gallery behavior and upload limit controls
- **Config export/import** — JSON backup; import excludes `base_url` to protect other installs
- **Tools** — four maintenance operations, each scoped to all albums or a single album:
  - **File Integrity Check** — verifies both the original file and thumbnail exist on disk for every image record; runs in 500-image AJAX chunks (handles 500 000+ images); missing files listed in a results table with checkboxes; bulk-delete orphaned DB records in one click (disk files are never touched)
  - **Reload Dimensions** — re-reads pixel dimensions and file sizes from disk and updates the database; runs in 100-image AJAX chunks; useful after manual file operations or migrations
  - **Regenerate Thumbnails** — regenerates thumbnails via `lumora_generate_thumb()` for every image; runs in 20-image AJAX chunks; respects Imagick/GD availability
  - **Regenerate Missing Thumbnails** — regenerates thumbnails only for images where the thumbnail file is missing or empty, leaving existing valid thumbnails untouched; runs in 500-image AJAX chunks; significantly faster than a full regeneration when only a small fraction of thumbnails are absent (e.g. after manual file additions or a partial batch-add failure)
- **Account** — update username and email address; change password with current-password verification; **Forgot password** link on the login page generates a secure reset link written to `lumora_recovery.txt` in the gallery root (1-hour single-use token, email attempted if address is set)
- **Installation Settings** — update the base URL and other installation-specific settings after moving to a new domain, subdirectory, or server; nine-item health check (database connectivity, albums and cache directories, config.php, site URL, PHP version, image processor, PDO MySQL, ZipArchive) runnable on demand via AJAX; configuration change log with full audit trail (last 15 entries from `{PREFIX}config_changes`); JSON environment snapshot export; CSRF and password re-authentication required for all setting changes; Migration Helpers accordion with guided steps for domain changes, subdirectory changes, HTTPS enablement, and server migrations
- **Updates** — in-dashboard update installer: when a new release is available the Updates page shows an **⬆ Install Update** card with a 10-stage progress UI (`preflight → download → verify → backup → maintenance → extract → validate → replace → migrate → cleanup`); each stage is a separate AJAX call so progress is reported in real time; automatic database and `config.php` backup before any file replacement; one-click Rollback restores the backup on failure; Abort option for stuck sessions; update history table shows the last 10 attempts; custom themes and plugins are preserved by default (`update_preserve_themes` / `update_preserve_plugins` config keys)

### Themes
Themes live in `themes/{name}/` and require only `template.html`. The active theme is selected in Admin → Configuration. Multiple themes can be installed simultaneously.

Two themes are included:

- **`default`** — Bootstrap 5 responsive layout with a dark navbar. Clean and neutral; good starting point for any site.
- **`classic-fansite`** — Traditional fixed-width fansite layout (2000s–2010s fandom era). Features a full-bleed banner image area, sticky navigation bar, and a centred content panel against a dark outer background. Every design decision is exposed as a CSS custom property, with five ready-made fandom colour presets (dark red/fantasy, ocean blue/sci-fi, forest green/nature, rose gold/pop, midnight gold/historical) documented in `themes/classic-fansite/README.md`. The same file covers how to create a new derived theme in four steps.

A theme can optionally declare itself via a CSS header comment at the top of its primary stylesheet (the first `{THEME_URL}*.css` link found in `template.html`), in the same spirit as WordPress theme headers:

```css
/*
 * Theme Name: My Theme
 * Author: Your Name
 * Design URI: https://example.com
 */
```

Recognized fields are `Theme Name`, `Author`, and `Design URI`. When present, they're shown as the theme's display name in the Active Theme dropdown and in a reference table in Admin → Configuration → Appearance. The header is entirely optional — themes without one still work normally, falling back to the folder name.

### Thumbnail generation
- **Imagick PHP extension** preferred — auto-detected, no path configuration needed. Uses IM7 Q16-HDRI for high-quality Lanczos resizing, EXIF auto-orientation, and metadata stripping.
- **GD library** fallback if the Imagick extension is not loaded.
- Configurable max width and height (aspect ratio preserved, never upscaled).
- Configurable JPEG/WebP quality (`thumb_quality`).
- Thumbnails generated on Batch Add; never regenerated if `thumb_*` already exists.

---

## Configuration

All settings are managed in **Admin → Configuration**. Key options:

| Setting | Default | Description |
|---|---|---|
| `gallery_name` | Lumora Gallery | Displayed in page titles and nav |
| `base_url` | Auto-detected | Public URL with trailing slash |
| `theme` | default | Active theme folder name |
| `thumb_width` / `thumb_height` | 250 | Max thumbnail dimensions (px) |
| `per_page` | 48 | Thumbnails per page |
| `category_layout` | grid | Category browser layout: `grid` (card grid) or `list` (row-based with recursive album and image counts) |
| `allowed_extensions` | jpg,jpeg,png,gif,webp | Accepted image types for Batch Add |
| `custom_header_path` | — | Path to a custom HTML header file (relative to Lumora root) |
| `custom_footer_path` | — | Path to a custom HTML footer file |
| `timezone` | UTC | PHP timezone identifier (e.g. `Europe/Helsinki`); applied at bootstrap |
| `thumb_quality` | 85 | JPEG/WebP thumbnail quality 1–100 |
| `max_upload_size_mb` | 0 | Max file size in MB for Batch Add; 0 = unlimited |
| `max_image_width` | 0 | Max width for stored originals in px; 0 = no limit |
| `max_image_height` | 0 | Max height for stored originals in px; 0 = no limit |
| `count_album_views` | 1 | Toggle album hit counter (`0` = off, `1` = on) |
| `log_mode` | off | Logging: `off`, `errors` (PHP error log), or `all` (error log + DB) |
| `gallery_offline` | 0 | Maintenance mode — shows HTTP 503 to non-admins when `1` |
| `latest_albums_count` | 5 | Number of recently updated albums shown on the home page; `0` = hide section |
| `who_is_online_duration` | 5 | Visitor window in minutes for the Who Is Online strip (1–60); `0` = disable tracking |
| `show_powered_by` | 1 | Show a "Powered by Lumora Gallery" credit in the footer (`0` = hidden); uses `{POWERED_BY}` theme token |

Settings are stored in the `{PREFIX}config` database table and cached by the `LumoraConfig` static class per request.

The image processor (Imagick or GD) is detected automatically at runtime and shown as a read-only status in Admin → Configuration. No path or binary configuration is required.

---

## Coppermine Migration

Because Lumora uses the same `albums/{folder}/thumb_*` structure as Coppermine,
migration is a scan-and-index operation — no file conversion needed:

1. Copy (or symlink) your existing Coppermine `albums/` directory into Lumora's root.
2. Create matching categories and albums in Lumora Admin, setting each album's **Folder Path** to the same relative path Coppermine uses
   (e.g. `Xena/Season1/1x01-SinsOfThePast`).
3. Run **Batch Add** on each album — Lumora indexes the images without touching the files.

The **Coppermine Importer** plugin (`plugins/coppermine-importer/`) automates this entirely — it connects to the Coppermine database directly and imports categories, albums, and image metadata in keyset-paginated AJAX chunks without touching any files. Navigate to **Admin → Import** to run it.

- **Plugin v1.2.0+** — the credentials form includes an **Auto-Detect** panel: supply the filesystem path to your Coppermine installation and the importer reads `include/config.inc.php` to fill in all five database fields automatically. If multiple Coppermine installations are found under the supplied path, a selection list is shown.
- **Plugin v1.1.0+** — album and category cover-thumbnail selections are preserved automatically as part of the import wizard itself.
- The **Metadata Sync** tool (`Admin → Import → Metadata Sync`) remains available as a fallback for re-applying cover assignments after a stopped import or for galleries imported before v1.1.0.

---

## Security Notes

- `config.php` contains database credentials — ensure your web server does not serve it as plain text. Adding an `.htaccess` rule to deny direct access is recommended.
- **Unique table prefix** — the installer auto-generates a random `lum_XXXXXXXX_` prefix for every new installation, making database table names harder to guess in shared-database environments. Advanced users can override the prefix during installation. Existing installations using `lum_` or any other prefix are entirely unaffected.
- The `install/` directory is automatically removed by the installer after a successful fresh install, and by the built-in updater after a successful upgrade. Verify it is gone after either operation; if not, delete it manually via FTP or your hosting control panel.
- All POST actions use CSRF tokens. Admin routes require an authenticated session.
- **Login rate limiting** — the admin login page tracks failed attempts per IP address in `cache/.login_ratelimit.json`. After 5 failures within a 15-minute window the form is locked, a 2-second server-side delay is enforced, and a lockout message is shown. Individual failures each add a 1-second delay. The IP record is cleared after a successful login.
- Passwords are hashed with `password_hash()` / `PASSWORD_DEFAULT`.
- The **Remember Me** cookie uses a split-token scheme: the validator is stored as
  `SHA-256(validator)` in the database only; the plain value travels only in the
  browser cookie. Tokens are rotated on every use and all tokens for a user are
  revoked on explicit logout.
- **Password recovery** uses the same split-token scheme. The reset URL is written
  to `lumora_recovery.txt` in the gallery root — protect or delete this file after
  use. The token expires after 1 hour and is single-use.

---

## Development

| | |
|---|---|
| Developer | Ariane |
| Repository | <https://coding.unloved-heart.net/scripts/lumora> |

---

## Changelog

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md).

---

## License

Lumora Gallery is released under the [GNU General Public License v3.0](LICENSE).  
You are free to use, modify, and distribute it under the terms of that license.

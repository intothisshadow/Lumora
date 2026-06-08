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
4. **Delete or protect the `install/` directory** after installation.
5. Log in at `admin/` with the credentials you created.

### Manual install (advanced)

Copy `config.sample.php` to `config.php` and fill in your database details, then import `install/schema.sql` into your database.

---

## Directory Structure

```
Lumora/
├── admin/                      Admin panel
│   ├── includes/               Admin-only helpers (flash messages, page renderer)
│   ├── account.php             Account management (username, email, password)
│   ├── albums.php              Album management
│   ├── batch.php               Batch-add images from FTP
│   ├── ajax_batch.php          AJAX endpoint for chunked batch processing
│   ├── ajax_integrity.php      AJAX endpoint for integrity scan chunks
│   ├── ajax_integrity_delete.php  AJAX endpoint for deleting orphaned records
│   ├── categories.php          Category management
│   ├── config.php              Gallery settings, export/import
│   ├── dashboard.php           Stats overview
│   ├── maintenance.php         Maintenance tools (File Integrity Check)
│   ├── login.php / logout.php
│   └── admin.css
├── albums/                     Image storage — original + thumb_* thumbnails
├── docs/                       CHANGELOG.md, TROUBLESHOOTING.md
├── include/                    Core PHP includes
│   ├── bootstrap.php           Load order, constants
│   ├── db.php                  PDO singleton (LumoraDB)
│   ├── functions.php           Config cache, CRUD helpers, pagination
│   ├── auth.php                Login, CSRF, session, password management
│   ├── thumb.php               Thumbnail generation, batch-add logic
│   └── template.php            Page rendering, thumbgrid, lightbox
├── install/                    Web-based installer (delete after use)
│   ├── index.php
│   └── schema.sql
├── themes/                     Theme folders
│   └── default/
│       ├── template.html       Bootstrap 5 base template
│       └── lumora.css          Gallery styles
├── ajax_hit.php                Public image view counter endpoint (fire-and-forget POST)
├── album.php                   Public album view (pagination, sort, lightbox)
├── index.php                   Public home, category browse, special views
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
- Home page with gallery stats, root categories, and latest images
- Category and album browsing
- Album view with sortable thumbnails (position, newest, oldest, most viewed, filename)
- Pagination (configurable images per page)
- Full-image lightbox via [PhotoSwipe 5](https://photoswipe.com/) (ESM, no global namespace)
- Image resolution displayed under each thumbnail
- Hit counter for albums and images (session-throttled; image counts recorded via lightbox `change` event → `ajax_hit.php`)
- Special views: Most Viewed, Latest, Random

### Admin panel
- **Dashboard** — stats cards + latest images
- **Categories** — create, edit, delete; nested (parent/child); re-parents children on delete
- **Albums** — create, edit, delete; auto-generated folder names or custom; filesystem directory creation
- **Batch Add** — scan `albums/{folder}/` for new images, process in 50-image AJAX chunks (handles 9000+ without timeout)
- **Configuration** — all settings in one form; theme selector; live image processor status
- **Config export/import** — JSON backup; import excludes `base_url` to protect other installs
- **Maintenance → File Integrity Check** — scans all image records and verifies both the original file and its thumbnail exist on disk; runs in 500-image AJAX chunks (handles 500 000+ images); missing files shown in a results table with checkboxes; bulk-delete orphaned DB records in one click; only DB rows are removed, no files on disk touched
- **Account** — update username and email address; change password with current-password verification

### Themes
Themes live in `themes/{name}/` and require only `template.html`. Copy `themes/default/` as a starting point. The active theme is selected in Admin → Configuration. Multiple themes can be installed simultaneously.

### Thumbnail generation
- **Imagick PHP extension** preferred — auto-detected, no path configuration needed. Uses IM7 Q16-HDRI for high-quality Lanczos resizing, EXIF auto-orientation, and metadata stripping.
- **GD library** fallback if the Imagick extension is not loaded.
- Configurable max width and height (aspect ratio preserved, never upscaled).
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
| `allowed_extensions` | jpg,jpeg,png,gif,webp | Accepted image types for Batch Add |
| `custom_header_path` | — | Path to a custom HTML header file (relative to Lumora root) |
| `custom_footer_path` | — | Path to a custom HTML footer file |

Settings are stored in the `{PREFIX}config` database table and cached in `$LUMORA_CONFIG` per request.

The image processor (Imagick or GD) is detected automatically at runtime and shown as a read-only status in Admin → Configuration. No path or binary configuration is required.

---

## Coppermine Migration

Because Lumora uses the same `albums/{folder}/thumb_*` structure as Coppermine,
migration is a scan-and-index operation — no file conversion needed:

1. Copy (or symlink) your existing Coppermine `albums/` directory into Lumora's root.
2. Create matching categories and albums in Lumora Admin, setting each album's **Folder Path** to the same relative path Coppermine uses
   (e.g. `Xena/Season1/1x01-SinsOfThePast`).
3. Run **Batch Add** on each album — Lumora indexes the images without touching the files.

A dedicated Coppermine → Lumora import tool (auto-creates categories, albums, and runs Batch Add in one pass) is planned for a future release.

---

## Security Notes

- `config.php` contains database credentials — ensure your web server does not serve it as plain text. Adding an `.htaccess` rule to deny direct access is recommended.
- The `install/` directory should be **deleted or access-restricted** after installation.
- All POST actions use CSRF tokens. Admin routes require an authenticated session.
- Passwords are hashed with `password_hash()` / `PASSWORD_DEFAULT`.

---

## Changelog

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md).

---

## License

To be decided.

# Coppermine Importer

An official Lumora Gallery plugin that migrates categories, albums, and image
metadata from Coppermine Gallery (CPG 1.4–1.6) to Lumora.

**Plugin version:** 1.0.0  
**Requires Lumora:** 1.5.0+  
**License:** GPL-3.0-or-later

---

## What it imports

| Data             | Imported | Notes                                              |
|------------------|----------|----------------------------------------------------|
| Categories       | ✅       | Hierarchy (parent/child) fully preserved           |
| Albums           | ✅       | Title, description, position, visibility, hit count|
| Image metadata   | ✅       | Filename, title, dimensions, filesize, hits, date  |
| Image files      | ❌       | Files are not moved (see File Migration below)     |
| Thumbnails       | ❌       | Not regenerated — existing `thumb_` files are used |
| Comments         | ❌       | Not supported in this version                      |
| User accounts    | ❌       | Not supported (Lumora is single-admin)             |

---

## File migration

The importer is **metadata-first**. It does not move, copy, or rename image files.

Your Coppermine `albums/` directory structure is preserved exactly. Lumora
references images in the same folder layout Coppermine used.

### Recommended workflow

1. **Run the importer** (Admin → Import → Coppermine Importer).
2. **Copy or symlink** your Coppermine `albums/` directory into Lumora's `albums/`
   directory so folder names and filenames are identical.
3. **Verify** using Admin → Tools → File Integrity Check to confirm all image
   files and thumbnails are found.

### Folder name mapping

| Coppermine album            | Lumora album folder |
|-----------------------------|---------------------|
| keyword = `xena/season1`   | `xena/season1`      |
| keyword = `` (empty)       | `00001` (zero-padded album ID) |
| keyword = `photos`         | `photos`            |

No files need to be renamed or restructured.

### Example

**Coppermine directory (before migration):**

```
albums/
├── 00001/
│   ├── thumb_ep101.jpg
│   └── ep101.jpg
└── xena/season1/
    ├── thumb_scene01.jpg
    └── scene01.jpg
```

**Lumora directory (after copying):**

```
albums/
├── 00001/
│   ├── thumb_ep101.jpg   ← referenced by Lumora as thumbnail
│   └── ep101.jpg         ← referenced by Lumora as original
└── xena/season1/
    ├── thumb_scene01.jpg
    └── scene01.jpg
```

No renaming required.

---

## Re-import protection

The importer records the date, record counts, and plugin version after each
successful import. If you navigate to the importer after a previous run, you
will see a warning and must explicitly confirm before proceeding.

Re-running the importer **will create duplicate content** unless you manually
clear the existing Lumora categories, albums, and images first.

---

## Import status display

After a successful import, the migration status is visible in Admin → Import
(the Lumora migration hub). It shows:

```
Source:      coppermine
Imported at: 2026-06-15 14:30:00
Categories:  14
Albums:      103
Images:      8,432
```

---

## Plugin versioning

The single source of truth for the plugin version is `version.php`:

```php
define('LUMORA_CPG_IMPORTER_VERSION', '1.0.0');
```

This constant is used throughout the codebase for:

- The `plugin.json` manifest (must be updated manually to match)
- Migration status records in the database
- Asset cache-busting query strings
- Compatibility checks against `LUMORA_CPG_IMPORTER_MIN_LUMORA`

When releasing a new plugin version, update only `version.php` and `plugin.json`.

---

## Database migration

The migration framework tables (`{prefix}migration_status` and
`{prefix}migration_log`) are part of Lumora core and created by the Lumora
installer or DB v5→v6 migration script.

**DB v5 → v6** — migration framework tables:

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

Replace `lum_` with your actual table prefix.

---

## Future importers

The migration framework is designed to accept additional importer plugins
without modifying Lumora core. To create a new importer:

1. Create `plugins/{your-importer}/plugin.json` with `"type": "importer"`.
2. Set `"admin_url"` to your plugin's entry-point PHP file path.
3. Set `"source"` to a unique identifier string.
4. Implement your import logic; use `MigrationService::saveMigrationStatus()`
   and `MigrationService::logEvent()` to record results.

The Lumora migration hub (`Admin → Import`) discovers and lists your plugin
automatically.

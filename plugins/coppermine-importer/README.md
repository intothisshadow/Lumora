# Coppermine Importer

An official Lumora Gallery plugin that migrates categories, albums, and image
metadata from Coppermine Gallery (CPG 1.4–1.6) to Lumora.

**Plugin version:** 1.1.0  
**Requires Lumora:** 1.5.0+  
**License:** GPL-3.0-or-later

---

## What it imports

| Data                         | Imported | Notes                                                        |
|------------------------------|----------|--------------------------------------------------------------|
| Categories                   | ✅       | Hierarchy (parent/child) fully preserved                     |
| Albums                       | ✅       | Title, description, position, visibility, hit count          |
| Image metadata               | ✅       | Filename, title, dimensions, filesize, hits, date            |
| Album cover images           | ✅       | Assigned during import via `cpg_albums.thumb`                |
| Category cover images        | ✅       | Assigned during import via `cpg_categories.thumb`            |
| Image files                  | ❌       | Files are not moved (see File Migration below)               |
| Thumbnails                   | ❌       | Not regenerated — existing `thumb_` files are used           |
| Comments                     | ❌       | Not supported in this version                                |
| User accounts                | ❌       | Not supported (Lumora is single-admin)                       |

---

## Cover image import

Album and category cover images (the `thumb` field in `cpg_albums` and
`cpg_categories`) are assigned automatically at the end of every import run,
as part of the main wizard.

### How it works

After all images are imported, the wizard sends one `apply_covers` call that:

1. Reads every CPG album and category that has a non-zero `thumb` (cover picture ID).
2. Resolves each CPG picture ID to its Lumora counterpart via the exact
   CPG-ID → Lumora-ID maps built during the same import session —
   no folder matching or filesystem probing required.
3. Updates `thumb_image_id` on the Lumora album or category row.
4. Wraps all writes in a single database transaction; individual row failures
   are caught per-row so one bad reference never aborts the batch.

Missing covers (image not imported, album not imported, or CPG thumb field 0)
fall through to Lumora's automatic cover selection (`thumb_image_id = 0` →
auto-pick first approved image), so nothing breaks if a cover can't be resolved.

Cover assignment warnings are written to the migration log and appear in the
warnings section on the import results page.

### In-wizard vs. post-import Metadata Sync

| Approach | When to use |
|---|---|
| **Import wizard** (`apply_covers`) | Preferred — runs automatically, uses exact ID maps from the current import session. |
| **Metadata Sync tool** | Post-import fallback — use when covers were not set during import (e.g. the import was stopped early), or to re-run cover assignment after making manual changes. |

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
define('LUMORA_CPG_IMPORTER_VERSION', '1.1.0');
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

## Metadata Sync tool

The plugin ships a second admin page at `admin/sync_metadata.php` — the
**Metadata Sync tool** — which syncs category and album cover-thumbnail
selections from Coppermine into an *already-imported* Lumora gallery.

Access it at:
`Admin → Import → Coppermine Importer → Metadata Sync` (linked from the
importer wizard's credentials page and results page), or navigate directly to
`plugins/coppermine-importer/admin/sync_metadata.php`.

Use the Metadata Sync tool when:
- The main import was stopped before covers were assigned.
- You want to re-run cover assignment after making manual changes.
- You added new images to Coppermine and re-imported only the images.

### What it syncs

| Data                             | Synced | Notes                                    |
|----------------------------------|--------|------------------------------------------|
| Category cover-thumbnail         | ✅     | From `cpg_categories.thumb`              |
| Album cover-thumbnail            | ✅     | From `cpg_albums.thumb`                  |
| Categories, albums, image records| ❌     | Use the main importer for those          |

The sync tool matches by durable on-disk identifiers rather than import-session
ID maps (which are not persisted after the wizard completes):

- **Albums** — matched by `folder` (resolved from `cpg_pictures.filepath`,
  falling back to `cpg_albums.keyword`).
- **Categories** — matched by full name-path from root using ASCII 0x1F as the
  separator so names with slashes cannot collide across different hierarchies.

### Status values in the preview table

| Status          | Meaning                                                              |
|-----------------|----------------------------------------------------------------------|
| Ready           | Will be set on Apply                                                 |
| Has cover       | Already set in Lumora; only changes if Overwrite is checked          |
| Unmatched       | No Lumora counterpart found by folder / name-path                    |
| Image not found | Matched, but the cover image is not in Lumora's `images` table       |
| Ambiguous       | Category name-path matched more than one Lumora category             |

### Safety

- All writes are wrapped in a single Lumora-side transaction; a PHP exception
  anywhere rolls back every change for that run.
- The tool defaults to filling only records where `thumb_image_id = 0`.
  Check **Overwrite** to replace existing cover selections.
- A required **backup confirmation** checkbox must be ticked before Apply.
- A timestamped plain-text log is written to
  `plugins/coppermine-importer/logs/thumb_sync_YYYYMMDD_HHMMSS.log`.
  Restrict web access to that directory or delete old logs periodically.
- The tool never calls `saveMigrationStatus()`, so sync runs never overwrite
  the import record in `migration_status`. Log entries are written under the
  source key `coppermine_thumb_sync` (constant `LUMORA_CPG_IMPORTER_SYNC_SOURCE`)
  to keep them separate from the main import's log.
- The tool is safe to re-run any number of times.

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

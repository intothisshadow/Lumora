# Changelog — Lumora Gallery

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---



## [1.9.0] — 2026-06-25

### Security

- **Login rate limiting** (`admin/login.php`): added IP-based brute-force
  protection. Failed attempts are tracked in `cache/.login_ratelimit.json`
  using a 15-minute sliding window. After 5 failures the form is disabled
  client-side, a 2-second server-side delay is enforced, and a lockout message
  is shown. Every individual failure also adds a 1-second `usleep()` delay.
  IP record is cleared on successful authentication.

- **Password-change timing hardening** (`admin/account.php`): added
  `usleep(500_000)` on `password_verify()` failure in the password-change
  handler to slow brute-force attempts against the current-password field.

- **ZipArchive path traversal protection** (`include/services/UpdaterService.php`):
  `stageExtract()` now performs a two-layer path-traversal check: (1) enhanced
  pre-extraction string validation including null-byte rejection; (2) new
  post-extraction `realpath()` scan verifying every extracted path resolves
  within the canonical extraction directory — cleans up and aborts on any escape.

- **File upload double-extension bypass fix** (`include/services/ThumbnailService.php`):
  `isAllowedImage()` now rejects any filename containing a server-executable
  extension (`php`, `php3`, `php4`, `php5`, `php7`, `phtml`, `phar`, `shtml`) in
  any dot-separated segment, not just the last extension. `scanNewImages()` updated
  to use `isAllowedImage()` consistently.

- **GD image dimension bomb protection** (`include/services/ThumbnailService.php`):
  `thumbGd()` now validates source image dimensions from `getimagesize()` before
  calling any `imagecreatefrom*()` function. Rejects images exceeding 50 MP total
  pixels or 15 000 px on either axis, preventing memory exhaustion from crafted
  image headers.

- **Backup SQL identifier escaping** (`include/services/UpdaterService.php`):
  `dumpDatabase()` now applies `str_replace('`', '``', $table)` before
  interpolating table names into `SHOW CREATE TABLE` and `SELECT *` queries,
  ensuring correct escaping of any table name that contains a backtick character.

- **Security audit Phase A false-positive documentation**: full code review of
  all 57 files flagged by the 2026-06-25 PHP Security Scanner confirmed that
  the overwhelming majority of "Critical" SQL injection and "High" CSRF findings
  were scanner false positives (scanner fired on `require_once`, `echo
  json_encode()`, `lumora_int()`-guarded reads, and the CSRF-check lines
  themselves). All genuine issues are addressed in this release.

### Added

- **Admin Tool: Installation Settings** (`include/services/InstallationService.php`,
  `admin/installation.php`,
  `admin/ajax_installation_health.php`,
  `include/migrations/Migration0002_CreateConfigChangesTable.php`,
  `admin/includes/admin_helpers.php`,
  `include/bootstrap.php`,
  `install/schema.sql`,
  `version.php`):
  Completes TODO item 2. Administrators can now update Lumora's installation
  configuration after moving to a new domain, subdirectory, or server — without
  manually editing config.php or running raw SQL. Accessible via the new
  **Administration → Installation** sidebar item.

  **`InstallationService`** (`include/services/InstallationService.php`) — new
  static service class loaded by bootstrap.php. Responsibilities:
  - `detectEnvironment()` — reads live PHP superglobals to determine the current
    protocol, host, and Lumora web-root path; returns `detected_url`, `root_path`,
    `albums_path`, `cache_path`, `php_version`, `web_server`, and `https` flag.
    Respects common reverse-proxy headers (`HTTP_X_FORWARDED_PROTO`, `SERVER_PORT`).
  - `getStoredConfig()` — returns the installation-relevant subset of the stored
    configuration: `base_url`, `gallery_name`, `db_host`, `db_name`, `db_prefix`.
    DB credentials are never returned.
  - `detectChanges()` — compares detected vs. stored values and returns a list of
    mismatch descriptors (field, label, stored, detected). Also surfaces an HTTPS
    upgrade hint when the stored URL still uses `http://` but the current connection
    is served over TLS.
  - `validateUrl(string $url)` — validates scheme, format, and non-emptiness.
  - `applySettings(array $settings, int $user_id, string $username, string $ip)` —
    validates and persists each allowed config key (`base_url` in this version),
    calls `logConfigChange()` per key, clears application caches, and reloads the
    in-memory config. Returns `{success, applied[], errors[]}`.
  - `clearCaches()` — deletes non-hidden files in `cache/`, calls `opcache_reset()`
    if available, and calls `LumoraConfig::load()` to refresh the in-memory cache.
  - `runHealthCheck()` — runs nine checks and returns a list of result descriptors
    (`name`, `status`, `detail`, `ok`). Checks: database connectivity, albums
    directory accessible and writable, cache directory writable, config.php present,
    site URL stored and valid, PHP version ≥ 8.2, image processor (Imagick / GD),
    PDO MySQL extension, ZipArchive extension.
  - `logConfigChange(...)` — inserts one row into `{PREFIX}config_changes`. Fails
    silently on pre-v8 installs where the table does not yet exist.
  - `getRecentChanges(int $limit)` — queries `{PREFIX}config_changes` newest-first;
    returns empty array gracefully on pre-v8 installs.
  - `exportSettings()` — returns a JSON snapshot of current stored config and live
    environment (DB password excluded; labelled `*** not exported ***`).

  **`admin/installation.php`** — new admin page with six sections:
  - **Current Installation Information** — a read-only table showing stored vs.
    detected site URL (with HTTPS badge), application root, albums and cache
    directory paths with writable indicators, DB host/name/prefix (read-only),
    PHP version, and web server string. Includes an “Export Snapshot (JSON)”
    button that POSTs to the same page and triggers a JSON download.
  - **Auto-Detected Changes** — shown only when `InstallationService::detectChanges()`
    finds a mismatch. Renders a comparison table (stored vs. detected) and a
    “Copy detected URL into the form” button that pre-fills the update form.
  - **Migration Helpers** — Bootstrap 5 accordion with four scenario cards:
    *Domain Change* (replaces the hostname while preserving scheme, port, and path),
    *Subdirectory Change* (replaces the path component), *HTTPS Enablement*
    (replaces `http://` with `https://`), and *Server Migration* (accepts a
    complete new URL). Each card has a helper input and an “Apply to form” button
    that populates the Site URL field below without submitting.
  - **Update Installation Settings** — a form with the Site URL field (pre-filled
    with the stored value), a live change-preview notice (JS-driven, shows old →
    new before submit), collapsible rollback instructions, and a
    *Current Password* field (required). Submitting without the correct password
    is rejected server-side and no changes are applied.
  - **Health Check** — an AJAX-driven panel. Clicking “Run Health Check” POSTs to
    `ajax_installation_health.php` and renders the nine-row results table with
    per-check OK/WARNING/FAIL badges. A summary banner (all clear vs. attention
    needed) appears above the table.
  - **Configuration Change Log** — a table of the last 15 entries from
    `{PREFIX}config_changes`, showing timestamp, admin, IP, setting key, previous
    value (struck through in red), and new value (green). Empty-state message
    shown on first visit.

  **`admin/ajax_installation_health.php`** — POST-only AJAX endpoint. Validates
  admin authentication and CSRF token, then calls
  `InstallationService::runHealthCheck()` and returns
  `{checks: [...], all_ok: bool}` JSON. Returns HTTP 403 on auth or CSRF failure,
  HTTP 405 on non-POST requests, HTTP 500 on unexpected errors.

  **`Migration0002_CreateConfigChangesTable`**
  (`include/migrations/Migration0002_CreateConfigChangesTable.php`) — second
  schema migration. `up()` creates `{PREFIX}config_changes` with `CREATE TABLE IF
  NOT EXISTS`; `down()` drops it. Picked up automatically by `SchemaService` and
  shown as pending in the admin Updates page until applied.

  **`admin/includes/admin_helpers.php`** (extended) — **Installation** (🖥️) nav
  item added between Tools and Import in the sidebar.

  **`include/bootstrap.php`** (extended) — `InstallationService.php` added to the
  step 7 service class load sequence; header comment updated.

  **`install/schema.sql`** (DB version 8) — `{PREFIX}config_changes` table added.
  Migration comment for v7 → v8 added at the top of the file with the manual
  `CREATE TABLE` statement for existing installations.

  **`version.php`** — `LUMORA_DB_VERSION` bumped from 7 to 8.

  **New DB table** `{PREFIX}config_changes` (DB version 8):
  ```sql
  CREATE TABLE IF NOT EXISTS `lum_config_changes` (
    `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    int UNSIGNED    NOT NULL DEFAULT 0,
    `username`   varchar(50)     NOT NULL DEFAULT '',
    `ip`         varchar(45)     NOT NULL DEFAULT '',
    `key`        varchar(64)     NOT NULL DEFAULT '',
    `old_value`  text            NOT NULL,
    `new_value`  text            NOT NULL,
    `changed_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `key_changed`  (`key`, `changed_at`),
    KEY `user_changed` (`user_id`, `changed_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
  Replace `lum_` with your actual table prefix. Existing installations not yet
  running Migration0002 degrade gracefully — `logConfigChange()` and
  `getRecentChanges()` both catch `\Throwable` and fail silently.

- **Dashboard Update System — Phase 2** (`include/services/AbstractUpdateProvider.php`,
  `include/services/GitHubUpdateProvider.php`,
  `include/services/UpdaterService.php`,
  `admin/ajax_update_perform.php`,
  `admin/update.php`,
  `include/bootstrap.php`,
  `include/functions.php`):
  Completes TODO item 12. Administrators can now install a new Lumora release entirely
  from within the admin dashboard — no SSH, no manual file extraction. The workflow
  runs as a sequence of discrete AJAX stages, each reported in real time, with
  automatic backup and one-click rollback on failure.

  **Stage flow:**
  `preflight → download → verify → backup → maintenance → extract → validate → replace → migrate → cleanup`

  Each stage is one POST to `admin/ajax_update_perform.php`, returning
  `{success, stage, message, next, details[]}`. The browser drives the sequence
  recursively; a 300 ms pause between stages keeps the progress UI visible.

  **`AbstractUpdateProvider`** (`include/services/AbstractUpdateProvider.php`) —
  abstract base class defining the provider interface: `fetchMetadata()`,
  `buildArchiveUrl()`, `getName()`. The static factory `createFromConfig()` reads
  the `update_provider_type` config key (`'github'` by default) and instantiates
  the appropriate concrete class. New release sources (self-hosted servers,
  alternative repositories, private enterprise feeds) can be added by implementing
  this class and registering a match arm in the factory — no changes to the core
  update workflow are required. Includes a shared `httpGet()` helper that uses
  a stream context with `set_error_handler` / `restore_error_handler` instead of
  the `@` operator for clean E_WARNING suppression on TCP failures.

  **`GitHubUpdateProvider`** (`include/services/GitHubUpdateProvider.php`) —
  concrete provider for the GitHub Releases API
  (`https://api.github.com/repos/{owner}/{repo}/releases/latest`). Maps
  `tag_name → latest_version`, `published_at → release_date` (date only),
  `body → release_notes` (truncated to 2 000 chars), `html_url → changelog_url`.
  Extracts `minimum_php` and `minimum_db` via regex from the release body
  (e.g. `Minimum PHP: 8.2`). Searches release assets for a `.sha256` /
  `sha256sums.txt` / `checksums.txt` file and fetches its content to supply the
  `sha256` checksum. Repository configurable via `update_github_repo` config key
  (default: `intothisshadow/Lumora`).

  **`UpdaterService`** (`include/services/UpdaterService.php`) — static service
  class orchestrating the full 10-stage update workflow. Key design points:
  - A JSON lock file at `cache/.updates/lock.json` persists state (target version,
    download URL, SHA-256, paths, maintenance flag) across AJAX calls so no state
    travels as POST parameters.
  - `runStage(string $stage, string $version = ''): array` dispatches to one of
    ten private stage methods; `set_time_limit(180)` applied per stage.
  - **Pre-flight** (`stagePreflight`): checks `ext-zip`, PHP version compatibility
    against cached update metadata, disk space (≥ 80 MB), and write permission;
    fetches download URL + SHA-256 from the configured provider; acquires lock.
  - **Download** (`stageDownload`): streams archive via `file_get_contents` with
    a 120 s timeout and up to 5 redirects; resumes if archive already exists.
  - **Verify** (`stageVerify`): validates SHA-256 checksum when available (logged
    as a warning when absent — checksums protect against corruption; cryptographic
    signatures are a planned future enhancement); confirms ZIP structure via
    `ZipArchive::count()`.
  - **Backup** (`stageBackup`): copies `config.php`; dumps all prefixed tables to
    `cache/.updates/backup/database.sql` via PDO (100-row chunks; string-literal-
    aware SQL splitter for restore).
  - **Maintenance** (`stageMaintenance`): writes a flag file
    (`cache/.updates/.maintenance_active`) and calls
    `LumoraConfig::set('gallery_offline', '1')`.
  - **Extract** (`stageExtract`): validates all ZIP entry names for path-traversal
    patterns before extracting a single byte; cleans any prior extract dir first.
  - **Validate** (`stageValidate`): locates the Lumora app root inside the archive
    (searches up to 3 directory levels for `version.php`); confirms required paths
    exist; verifies the declared version string; stores resolved path in lock file.
  - **Replace** (`stageReplace`): copies files from the extracted root to
    `LUMORA_ROOT`, skipping always-preserved paths (`config.php`, `albums/`,
    `cache/`) and optionally-preserved paths (`themes/`, `plugins/` — controlled
    by `update_preserve_themes` / `update_preserve_plugins` config keys).
  - **Migrate** (`stageMigrate`): calls `SchemaService::runPendingMigrations()`
    and surfaces individual migration names and any errors.
  - **Cleanup** (`stageCleanup`): calls `opcache_reset()`, clears cache files
    (non-hidden files in `cache/` root only), disables maintenance mode, releases
    lock. Also called (with `$success = false`) during rollback.
  - `rollback()`: restores `config.php` and database from backup, then calls
    `stageCleanup(false)`. File-level rollback is noted as a future enhancement;
    administrators are advised to maintain server-level file backups.
  - `forceAbort()`: disables maintenance mode and releases lock without restoration
    — for stuck sessions where no file replacement has occurred.
  - `logUpdate()` / `getUpdateLog()`: append-only log at `cache/.updates/update.log`.
  - `recordUpdateHistory()` / `getUpdateHistory()`: last 10 update attempts stored
    as JSON in the `update_history` config key.

  **`admin/ajax_update_perform.php`** — AJAX endpoint. Actions: `run_stage`,
  `rollback`, `abort`. Validates CSRF token and admin session. Version input
  sanitised with `/^v?[0-9]+(?:\.[0-9]+)*$/`. Unknown stages or actions return
  HTTP 400 with a JSON error.

  **`admin/update.php`** (extended) — the existing Updates page gains two new cards:
  - **⬆ Install Update** (shown only when an update is available): confirmation
    checkbox that must be ticked before the **Update Now** button enables; PHP
    version compatibility warning when the new release requires a higher PHP
    version; a 10-row stage progress list with pending/active/done/failed icons
    (⊙/⟳/✓/✗); a scrollable detail log panel; Rollback and Abort buttons that
    appear on failure. A stuck-session detection notice with an abort option is
    shown when the lock file is held but the current browser did not initiate it.
  - **📋 Update History**: table of the last 10 update attempts (version, date,
    success/failure) from the `update_history` config key.
  - JS: `runUpdateStage()` recursive async loop; `markStageActive/Done/Failed()`;
    `appendLog()`; rollback and abort handlers. Destructive stages (`maintenance`,
    `replace`, `migrate`, `cleanup`) offer Rollback on failure; earlier stages
    offer Abort only.

  **`include/bootstrap.php`** (extended) — three new `require_once` lines for
  `AbstractUpdateProvider`, `GitHubUpdateProvider`, and `UpdaterService` added
  after the existing step 7 service class loads. Header comment updated.

  **`include/functions.php`** (extended) — `human_time_diff(int $timestamp): string`
  added under the Formatting section. Produces strings suitable for appending
  " ago" at the call site (e.g. "3 minutes", "2 hours", "4 days",
  "less than a minute"). Used by the stuck-session notice in `admin/update.php`.

  **New config keys** (stored in `{PREFIX}config`, no migration required):

  | Key | Default | Description |
  |-----|---------|-------------|
  | `update_provider_type` | `github` | Active release provider class |
  | `update_github_repo` | `intothisshadow/Lumora` | GitHub `owner/repo` for the GitHub provider |
  | `update_preserve_themes` | `1` | Skip `themes/` during file replacement |
  | `update_preserve_plugins` | `1` | Skip `plugins/` during file replacement |
  | `update_history` | JSON array | Last 10 update attempts (newest first) |

  **Working directory layout** (all created on first use; `.htaccess` denies web
  access on Apache hosts):
  ```
  cache/.updates/
    lock.json              — active update state
    lumora-v{ver}.zip     — downloaded archive
    extract/               — extracted archive contents
    backup/
      config.php           — config.php snapshot
      database.sql         — full SQL dump of all prefixed tables
    update.log             — append-only event log
    .maintenance_active    — flag file for maintenance mode
    .htaccess              — Apache deny-all
  ```

### Changed

- **Updated Lumora Gallery website URL** (`include/services/ThemeRenderer.php`,
  `include/services/UpdateService.php`, `README.md`): Standardised all
  references to the official Lumora Gallery website to the new URL
  (`https://coding.unloved-heart.net/scripts/Lumora`). The "Powered by"
  credit link in `ThemeRenderer::renderPoweredBy()`, the repository link
  in the README Development section, and the update-check endpoint constant
  in `UpdateService` have all been updated. Completes TODO item 1.

### Fixed

- **`admin/installation.php` — Health Check button (and all JS-driven buttons on the page) did nothing** (TODO item 3): The `<script>` block contained `const STORED = {$v_stored_url};` and `urlField.value = {$v_detected_url};`, where `$v_stored_url` and `$v_detected_url` were produced by `h()` (HTML-escaping only), not `json_encode()`. Interpolating a bare URL like `https://example.com/Lumora/` without quotes into a JS expression caused the engine to parse `https:` as a statement label and `//example.com/Lumora/` as a line comment, producing a `SyntaxError` that aborted the entire `<script>` block before `DOMContentLoaded` was registered. All click-driven features on the page were completely dead: **Run Health Check**, the live URL preview, the **Copy detected URL** button, and all four **Migration Helper** "Apply to form" buttons. Fixed by adding `$stored_url_js = json_encode($stored['base_url'])` and `$detected_url_js = json_encode($env['detected_url'])` and using those safely quoted literals in the JS (`const STORED = {$stored_url_js};`, `const DETECTED_URL = {$detected_url_js};`). The two `h()`-escaped `$v_*` variables are retained for use in HTML attributes only, where they remain correct.

---



## [1.8.0] — 2026-06-20

### Added

- **Coppermine Importer — In-wizard cover image assignment** (`plugins/coppermine-importer/CoppermineImporter.php`,
  `plugins/coppermine-importer/admin/ajax_import.php`,
  `plugins/coppermine-importer/admin/index.php`,
  `plugins/coppermine-importer/version.php`,
  `plugins/coppermine-importer/plugin.json`,
  `plugins/coppermine-importer/README.md`):
  Album and category cover images (`cpg_albums.thumb`, `cpg_categories.thumb`) are
  now preserved automatically as part of the main import wizard, completing TODO
  item 5. Cover assignment runs as a dedicated **Cover images** step at the end of
  the wizard — after all images have been imported and both CPG→Lumora ID maps are
  fully populated in session — then the wizard proceeds to the existing Finish step.

  **How it works:**
  - The wizard JS gains a `'covers'` phase between `'images'` and `'finish'`. A
    single `apply_covers` AJAX call is made; it is non-chunked and non-critical:
    a network error or server failure logs a warning in the import log and sets
    `cov-status` to *skipped* without blocking Finish or the results page.
  - `CoppermineImporter::importCovers(array $cat_id_map, array $album_id_map): array`
    — new public method. Reads every CPG album and category with `thumb > 0`,
    batch-fetches `(aid, filename)` for all referenced picture IDs via the existing
    `fetchCpgPictureInfo()` helper, then resolves each to a Lumora image_id via:
    `pid → (aid, filename) → album_id_map[aid] → (Lumora album_id, filename) → Lumora
    image_id`. All writes are wrapped in a single `LumoraDB` transaction; individual
    row failures are caught per-row so one bad reference never aborts the batch.
    Missing covers fall through silently to Lumora’s automatic cover selection
    (`thumb_image_id = 0`). Returns `{updated, skipped, warnings}`.
  - `case 'apply_covers':` added to `ajax_import.php`. Calls `importCovers()` with
    the full ID maps from session; logs all warnings and a summary event via
    `MigrationService::logEvent()`; catches any `\Throwable` and returns a graceful
    JSON response so the wizard can always proceed to Finish.
  - Step 3 progress UI gains a **Cover images** status row (no progress bar—single
    call). When complete it shows e.g. *42 assigned ✓*; on error it shows *skipped*
    with a log entry.
  - The `'images'` phase JS is updated: when images are done (`r.done = true`) it
    now transitions to `phase = 'covers'` instead of directly to `phase = 'finish'`.
    The stopped-import logic is updated so the Stop button still halts the import
    mid-images (`stopped && !r.done`), but once all images are complete cover
    assignment always runs regardless of the stopped flag (it is a single fast call).
  - Plugin bumped to **v1.1.0** (`version.php`, `plugin.json`).
  - **Relationship to Metadata Sync tool:** The in-wizard `importCovers()` uses the
    exact CPG→Lumora ID maps built in the current session, which is more reliable
    than the folder/name-path matching the standalone Metadata Sync tool uses. The
    sync tool remains the recommended fallback for re-running cover assignment after
    a stopped import or for galleries imported before v1.1.0.

- **Admin UI Pagination — Albums and Categories** (`admin/albums.php`,
  `admin/categories.php`, `admin/includes/admin_helpers.php`,
  `include/services/GalleryService.php`):
  Both the Albums and Categories admin list pages now paginate at the database
  level so only the current page's rows are fetched, keeping large galleries
  responsive regardless of how many albums or categories exist.

  - **Page size selector** — three options (25 / 50 / 100 items per page),
    rendered as an auto-submitting `<select>` above the table. The selected
    value is persisted in `$_SESSION['lum_adm_per_page_albums']` and
    `$_SESSION['lum_adm_per_page_categories']` so it survives page navigation.
    Defaults to 25 on first visit.

  - **Item count summary** — "Showing 26–50 of 847 albums" displayed to the
    left of the per-page selector on every list page.

  - **Pagination controls** — Bootstrap 5 `<nav>` rendered above and below the
    table. Shows Previous / page-number window (±2 around the current page plus
    first and last) / Next. Ellipsis indicators are inserted for gaps.
    Pages with only one page of results show no pagination controls.

  - **State preservation** — pagination links include the current `per_page`
    value and, on the Albums page, the active `cat` category-filter parameter,
    so filter context is never lost while navigating between pages.

  - **Database-level queries** — `LIMIT / OFFSET` is applied at the SQL layer.
    The list views no longer fetch every row into PHP.

  - **`GalleryService::countAdminAlbums(int $cat_id = 0): int`** — count query
    for the admin album list, with optional category filter.

  - **`GalleryService::getAdminAlbums(int $cat_id, int $page, int $per_page): array`** —
    paginated album fetch with `cat_name` join and `image_count` subquery.

  - **`GalleryService::countAllCategories(): int`** — count query for the admin
    category list.

  - **`GalleryService::getPaginatedCategoriesFlat(int $page, int $per_page): array`** —
    paginated category fetch ordered identically to `getAllCategoriesFlat()`. The
    full flat list is still fetched once for the parent-name lookup map and for
    new/edit form dropdowns.

  - **`lum_per_page_selector(string $action, array $preserve, int $current, array $options): string`**
    in `admin_helpers.php` — renders the per-page `<form>` with optional hidden
    inputs to preserve active filter params. Submitting resets to page 1.

  - **`lum_admin_pagination(array $pag): string`** in `admin_helpers.php` —
    renders the Bootstrap 5 pagination `<nav>` from a `lumora_pagination()`
    descriptor. Returns `''` when total pages ≤ 1.

  - **`albums.php`** — `$all_cats` fetch moved inside the new/edit branch;
    it is no longer queried on list page loads.

  - Page number validation: `lumora_int()` clamps `?page=` to ≥ 1; the existing
    `lumora_pagination()` further clamps to `[1, total_pages]` so out-of-range
    page numbers never produce empty results silently.

- **Automated Database Migrations — Phase 1** (`include/services/SchemaService.php`,
  `include/migrations/AbstractMigration.php`,
  `include/migrations/Migration0001_CreateMigrationsTable.php`,
  `admin/update.php`, `admin/ajax_run_migrations.php`, `admin/dashboard.php`,
  `admin/includes/admin_helpers.php`, `include/bootstrap.php`, `Lumora/migrate.php`):
  Implements the schema migration engine that automates database changes between
  Lumora releases, completing Phase 1 of the two-phase update system.
  (Phase 2, Item 12, will build the full file-download/replacement workflow on top
  of this foundation.)

  **Architecture decisions (locked in to constrain Phase 2):**
  - PHP class migrations with `up()` and `down()` methods — not raw SQL files.
  - Migration classes live in `include/migrations/` as `Migration{NNNN}_{Description}.php`.
  - Applied migrations are tracked in a dedicated `{PREFIX}migrations` table —
    not in the config table, so tracking survives config resets.
  - `SchemaService` exposes a clean library API (`runPendingMigrations()`,
    `getPendingMigrations()`) with no UI coupling so Phase 2 can call it directly.

  **`SchemaService`** (`include/services/SchemaService.php`) — new static service class
  (named `SchemaService` to avoid collision with the existing `MigrationService` class
  which tracks gallery imports from Coppermine and similar platforms):
  - `discoverMigrations()` — scans `include/migrations/` for `Migration*.php` files,
    validates names against the expected pattern, returns sorted class name list.
  - `getAppliedMigrations()` — queries `{PREFIX}migrations`; returns empty array
    gracefully when the table does not yet exist.
  - `getPendingMigrations()` — set difference of discovered vs applied; result is
    cached per request to avoid repeated DB hits (badge + dashboard both call it).
  - `hasPendingMigrations()` — convenience bool; used by nav badge and dashboard.
  - `runPendingMigrations(): array{applied: list<string>, errors: list<string>}` —
    runs all pending migrations in numeric order; stops on first failure; logs every
    result via `lumora_log()`; resets request cache after the run.
  - `rollback(string $migration): bool` — calls `down()` on a single named
    migration and removes its tracking record.
  - `getMigrationStatus(): array{applied: list<string>, pending: list<string>}` —
    returns both lists for the admin UI.
  - Class name validation before any filesystem path use prevents directory traversal.

  **`AbstractMigration`** (`include/migrations/AbstractMigration.php`) — abstract
  base class all migration classes must extend:
  - `abstract up(): void` and `abstract down(): void`.
  - `tableExists(string $table): bool`, `columnExists(string $table, string $col): bool`,
    `indexExists(string $table, string $index): bool` — INFORMATION_SCHEMA helpers
    so migrations can write safe conditional DDL without "table already exists" errors.

  **`Migration0001_CreateMigrationsTable`**
  (`include/migrations/Migration0001_CreateMigrationsTable.php`) — self-bootstrapping
  first migration. `up()` creates `{PREFIX}migrations` using `CREATE TABLE IF NOT
  EXISTS`. After `up()` executes, `SchemaService::runOne()` inserts this migration's
  record into the newly-created table, completing the bootstrap loop. `down()` drops
  the table with `DROP TABLE IF EXISTS`.

  **`admin/ajax_run_migrations.php`** — AJAX endpoint called from the Updates page.
  Validates CSRF and admin session, calls `SchemaService::runPendingMigrations()`,
  and returns `{success, applied[], errors[], message}` JSON.

  **`admin/update.php`** (extended) — **Database Updates** section added between the
  version status card and the Check for Updates card:
  - When schema is current: green ✓ badge + applied count.
  - When migrations are pending: amber ⚠ badge, list of pending migration class names,
    and a **🗄 Run Database Update** button. Clicking POSTs to `ajax_run_migrations.php`,
    shows the result, then reloads the page on success.
  - The existing application update check and AJAX infrastructure is unchanged.

  **`admin/dashboard.php`** (extended) — amber dismissible warning banner shown when
  `SchemaService::hasPendingMigrations()` is true; links to `admin/update.php`.

  **`admin/includes/admin_helpers.php`** (extended) — the `!` badge on the **Updates**
  nav item now appears when *either* a new application version is available *or* schema
  migrations are pending (`UpdateService::hasCachedUpdate() || SchemaService::hasPendingMigrations()`).

  **`include/bootstrap.php`** (extended) — `SchemaService.php` added to the step 7
  service class load sequence.

  **`Lumora/migrate.php`** — CLI entry point (PHP CLI only; returns HTTP 403 if
  accessed via web). Supports `--dry-run`, `--status`, and
  `--rollback <ClassName>` flags; exits 0 on success, 1 on failure.

- **Unique Table Prefix Generation During Installation** (`install/index.php`, `config.sample.php`):
  The installer now generates a unique, cryptographically random table prefix for every new
  Lumora installation instead of always defaulting to `lum_`. This makes table names harder
  to guess in shared-database environments, adding a layer of defence against automated
  attacks and SQL injection attempts that rely on known table names.

  - **`ins_generate_prefix()`** — new helper function. Generates a prefix in the format
    `lum_XXXXXXXX_` where `XXXXXXXX` is 8 lowercase hexadecimal characters derived from
    `random_bytes(5)`. Example output: `lum_3f9a12b4_`. Uses only letters, digits, and
    underscores, satisfying all MariaDB/MySQL identifier rules. The fixed `lum_` lead keeps
    the prefix immediately recognisable as a Lumora installation.

  - **Session persistence** — the generated prefix is stored in `$_SESSION['ins_suggested_prefix']`
    on the first GET request and reused for the lifetime of the install session. Page refreshes
    and failed submissions always show the same generated value, preventing confusing prefix
    changes mid-flow. A forced reinstall (`?force=1`) regenerates a fresh prefix.

  - **Advanced-user override** — the prefix field remains a free-text input so advanced users
    can specify any prefix that matches `[a-zA-Z0-9_]+`. The field carries an `auto-generated`
    badge and updated help text explaining the security purpose. Browser-level pattern validation
    (`pattern="[a-zA-Z0-9_]+"`) prevents invalid characters.

  - **Step 2 confirmation card** — after successful database setup, step 2 now shows a green
    **Database Configuration Confirmed** summary card displaying the database name and the
    confirmed prefix in `<code>` with a note to record the value. The card is also shown when
    step 2 is re-rendered after a validation error (e.g. password mismatch), so the prefix is
    always visible until the install completes.

  - **Session cleanup** — `$_SESSION['ins_suggested_prefix']` is cleared alongside all other
    installer session keys when installation completes successfully.

  - **Existing installations unaffected** — all existing galleries running on `lum_` (or any
    other custom prefix) continue to work without any change. The prefix is read from
    `config.php` at runtime via `DB_PREFIX`; no application code hard-codes `lum_`. The
    full `{PREFIX}` substitution path through `LumoraDB::query()`, `schema.sql`, and all
    service classes was already in place.

  - **`config.sample.php`** — `DB_PREFIX` comment updated to document the new
    `lum_XXXXXXXX_` format and note that existing `lum_` installs are unaffected.

---

## [1.7.1] — 2026-06-19

### Added

- **Theme Metadata from CSS Headers** (`include/functions.php`, `admin/config.php`,
  `themes/default/lumora.css`, `themes/classic-fansite/fansite.css`):
  Theme display names, author, and design URI can now be declared in a
  WordPress-style CSS header comment at the top of a theme's primary stylesheet,
  instead of relying on the folder name alone.

  - **`lumora_theme_primary_stylesheet(string $theme): ?string`** — locates a
    theme's primary stylesheet by finding the first theme-relative
    (`{THEME_URL}`) stylesheet `<link>` in its `template.html`, in document
    order. For the bundled themes this resolves to `lumora.css` (default) and
    `fansite.css` (classic-fansite and its derivatives) — the base stylesheet
    linked before any optional `custom.css` override.

  - **`lumora_get_theme_meta(string $theme): array`** — reads `Theme Name`,
    `Author`, and `Design URI` from the first CSS comment block in the primary
    stylesheet. Unrecognised fields are ignored; any missing field is returned
    empty, and `name` falls back to the directory name when no header is
    present at all, so every theme always has a usable display name.

  - **`admin/config.php`** — the **Active Theme** dropdown on **Configuration
    → Appearance** now shows each theme's `Theme Name` instead of
    `ucfirst($folder)`, while the submitted `<option>` value remains the
    folder name so existing `theme` config values keep working unchanged. A
    small reference table (Theme / Folder / Author / Design URI) is rendered
    beneath the selector for every installed theme; Design URI links open in
    a new tab.

  - **Core themes updated** with a standardised metadata header
    (`Theme Name`, `Author`) at the very top of their primary stylesheet:
    `themes/default/lumora.css` ("Default") and
    `themes/classic-fansite/fansite.css` ("Classic Fansite"). Both existing
    decorative file-header comments are preserved unchanged immediately below
    the new metadata block.

- **Admin Password Recovery** (`admin/forgot_password.php`, `admin/reset_password.php`,
  `include/auth.php`, `admin/login.php`, `install/schema.sql`, `version.php`):
  Admins who have lost their password can now generate a secure reset link without
  needing SMTP configured. The feature targets self-hosted installations where email
  delivery is not guaranteed.

  **Flow:**
  1. Admin clicks **Forgot password?** on the login page.
  2. `admin/forgot_password.php` generates a single-use, 1-hour split-token reset URL
     and writes it to `lumora_recovery.txt` in the gallery root — recoverable at any
     time via FTP or a hosting file manager.
  3. If an email address is set on the admin account, a best-effort send via PHP's
     `mail()` function is attempted in addition to the file.
  4. Admin opens the URL from the file, sets a new password on
     `admin/reset_password.php`, and the token is immediately consumed.
  5. `lumora_recovery.txt` is deleted automatically after a successful reset.

  **Security design:**
  - Split-token scheme identical to the remember-me implementation: `selector`
    (32-char hex) stored plain for lookup; `SHA-256(validator)` stored in DB;
    full validator travels only in the reset URL.
  - Tokens expire after 1 hour; only one active reset token per user at a time
    (existing tokens are revoked before a new one is issued).
  - Tokens are single-use — consumed and deleted immediately on successful
    password change.
  - All remember-me tokens for the user are also revoked after a successful reset,
    forcing a fresh login.
  - `forgot_password.php` shows an identical success message whether or not an
    admin account was found, to avoid disclosing account existence.
  - `mail()` warnings are captured via a temporary error handler and routed to
    the PHP error log only — never exposed to the browser.

  **New DB table** `{PREFIX}password_reset_tokens` (DB version 7):

  ```sql
  CREATE TABLE IF NOT EXISTS `lum_password_reset_tokens` (
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
  Replace `lum_` with your actual table prefix. Fresh installations from
  `install/schema.sql` receive the table automatically.

  **New auth functions** in `include/auth.php`:
  - `lumora_create_reset_token(int $user_id): array` — generates and stores a
    split-token, returns `['selector', 'validator', 'expires_at']`; throws on DB
    error (table absent) so the caller can show a migration hint.
  - `lumora_verify_reset_token(string $selector, string $validator): ?int` —
    validates format, expiry, and hashed validator; returns the user_id on success
    or null on any failure; prunes expired tokens in place.
  - `lumora_consume_reset_token(string $selector): void` — deletes one token by
    selector after successful password change.
  - `lumora_clear_reset_tokens(int $user_id): void` — deletes all reset tokens for
    a user; fails silently on pre-v7 installs.

  **`admin/login.php`** — **Forgot password?** link added below the login form.

  **`version.php`** — `LUMORA_DB_VERSION` bumped from 6 to 7.

- **Regenerate Missing Thumbnails** (`admin/ajax_missing_thumbs.php`, `admin/tools.php`):
  New Tool 4 on **Admin → Tools** that scans all images in scope (entire gallery
  or a selected album) and regenerates thumbnails **only** for images where the
  thumbnail file is missing or empty, leaving existing valid thumbnails untouched.

  This complements the existing “Regenerate All Thumbnails” tool (Tool 3), which
  unconditionally overwrites every thumbnail. Tool 4 is significantly faster when
  only a small fraction of thumbnails are absent (e.g. after adding images manually
  or after a partial batch-add failure).

  - **`admin/ajax_missing_thumbs.php`** — new AJAX chunk handler. Uses the same
    keyset-paginated architecture (`WHERE id > last_id`) and the same
    `lumora_generate_thumb()` / `LUMORA_THUMB_PREFIX` pipeline as the existing
    handlers. Before generating, it checks `is_file($thumb_path)` and
    `filesize($thumb_path) > 0`; images whose thumbnail already passes both
    checks are counted as `skipped` without any disk I/O or CPU work. Images
    whose original file is absent are counted as `no_orig`. JSON response shape:
    `{ checked, regenerated, skipped, no_orig, last_id, errors[], done }`.

  - **`admin/tools.php`** — Tool 4 card and progress UI added. The button is
    styled `btn-outline-success` (distinct from Tool 3’s `btn-primary`) and
    carries the same `disabled` attribute as Tool 3 when no image processor is
    available. Progress bar uses `bg-warning`. Completion summary shows either
    “All N thumbnails are already present” or “X missing thumbnails regenerated,
    Y already existed”. The existing three tools are unchanged.

- **Admin Image Search** (`admin/images.php`, `include/services/GalleryService.php`,
  `install/schema.sql`):
  Administrators can now search images by filename or title directly from
  **Admin → Images**, either within a selected album or across the entire gallery.

  - **Search bar** integrated into the album selector card. A single `?search=term`
    GET parameter controls the active query; the album scope (`?album=N`) is
    preserved when submitting a search and vice versa. A **✕ Clear** button
    removes the active query and returns to the current album view.

  - **Scoped vs. cross-album search:** when an album is selected and a search
    term is entered the query is limited to that album's rows (uses the existing
    `album_approved` index, fast even at 500 K images). When no album is selected
    the search runs across all albums — results show the category › album path
    below each filename so images are identifiable without opening their album.

  - **Result header** shows `Results for "term" in Album Title (N images)` or
    `Results for "term" across all albums (N images)`. The column header reads
    `Title / Filename / Album` in cross-album mode.

  - **Pagination, bulk delete, bulk move, single-image edit/delete** all
    preserve the active search term. After a save or delete the admin is
    returned to the same search results page. `location.reload()` in the bulk
    AJAX handlers preserves the search via the URL automatically.

  - **Empty-state message** when no images match, with a **Clear search** link.

  - **`GalleryService::searchImages(string $query, int $album_id, int $page,
    int $per_page): array`** — paginated image search using prepared `LIKE`
    statements against `filename` and `title`. Joins `albums` and `categories`
    so results include `album_title` and `cat_name`.

  - **`GalleryService::countSearchImages(string $query, int $album_id): int`** —
    companion count method for pagination.

  - **`install/schema.sql`** — two B-tree prefix indexes added to `{PREFIX}images`
    (`filename(191)`, `title(191)`). These improve album-scoped search performance
    in combination with the existing `album_approved` index. For very large
    galleries (500 K+) a `FULLTEXT KEY search_text (filename, title)` can be
    added manually; see the inline comment in `schema.sql` for the ALTER TABLE
    statement and the corresponding switch needed in `GalleryService::searchImages`.

  **Migration for existing installations** (optional, performance only — no
  functional change):
  ```sql
  ALTER TABLE `lum_images`
    ADD KEY `filename` (`filename`(191)),
    ADD KEY `title`    (`title`(191));
  ```
  Replace `lum_` with your actual table prefix.
  
  
  
- **Coppermine Importer — Metadata Sync tool** (`plugins/coppermine-importer/CoppermineImporter.php`,
  `plugins/coppermine-importer/admin/sync_metadata.php`,
  `plugins/coppermine-importer/admin/index.php`,
  `plugins/coppermine-importer/version.php`,
  `plugins/coppermine-importer/README.md`):
  Standalone companion to the main import wizard that syncs category and album
  cover-thumbnail selections from an existing Coppermine installation into an
  already-imported Lumora gallery, without requiring a full re-import.

  The main wizard does not carry over cover selections because it processes records
  in small keyset-paginated chunks and does not persist the CPG-ID → Lumora-ID
  map between requests. The sync tool re-derives matches from durable on-disk
  identifiers: albums by folder path (from `cpg_pictures.filepath`, falling back
  to `cpg_albums.keyword`), categories by full name-path from root using ASCII
  0x1F as a separator so names containing slashes cannot collide.

  - **`CoppermineImporter::previewThumbnailSync(): array`** — read-only method;
    returns a `{categories: [...], albums: [...]}` structure with per-record status
    strings (`ready`, `already_set`, `unmatched`, `image_unresolved`, `ambiguous`).

  - **`CoppermineImporter::applyThumbnailSync(bool $overwrite): array`** —
    re-runs the same matching logic fresh (no client state trusted), applies writes
    inside a single `LumoraDB` transaction with rollback on any `\Throwable`, and
    returns `{updated, skipped, errors}`.

  - **Private helpers added to `CoppermineImporter`:** `matchAlbumThumbnails()`,
    `matchCategoryThumbnails()`, `buildCpgCategoryPath()`,
    `buildLumoraCategoryPath()`, `resolvePidToLumoraImage()`,
    `fetchCpgPictureInfo()`, `fetchAllCpgAlbumFolders()`,
    `fetchLumoraAlbumsByFolder()`.

  - **`admin/sync_metadata.php`** — new three-step admin page (Credentials →
    Preview → Report). Separate session key (`lumora_cpg_thumb_sync`) prevents
    collision with the main wizard. Preview step shows a tally table and a
    scrollable per-record detail table with status badges. Apply step requires a
    backup-confirmation checkbox; writes a timestamped audit log to
    `plugins/coppermine-importer/logs/`. Report step shows matched/updated/skipped
    counts, errors (first 20 listed), and the log file path.

  - **`admin/index.php` (wizard)** — two contextual links to `sync_metadata.php`
    added: a blue info notice on the credentials page (shown only after a previous
    import), and a small-text paragraph on the results page.

  - **`version.php`** — new constant `LUMORA_CPG_IMPORTER_SYNC_SOURCE`
    (`'coppermine_thumb_sync'`): the source key used in `migration_log` for sync
    runs, kept separate from `LUMORA_CPG_IMPORTER_SOURCE` so sync events never
    mix with or overwrite the main import's `migration_status` row.

  - **`README.md`** — new § *Metadata Sync tool* section documenting the sync
    scope table, matching strategy, preview status values, and safety guarantees.

### Fixed

- **Albums and thumbnails missing their added/updated date — regression from a prior fix lost on a file overwrite** (`include/services/ThemeRenderer.php`, `themes/default/lumora.css`, `themes/classic-fansite/fansite.css`):
  `TODO.md` flagged that both the album info display and the thumbnail info display
  were missing their added/updated date, noting the date display had "already once
  been fixed" (recorded as completed in `docs/HISTORY.md` under the v1.7.0 Bug Fixes
  section). The date-rendering code was absent from the current `ThemeRenderer.php`
  and no `.lum-card-date` / `.lum-thumb-date` CSS existed in either core theme,
  confirming the fix was lost when the file was overwritten with a pre-fix version —
  the same class of regression already documented for `install/index.php` in the
  1.7.0 changelog entry. Re-implemented:
  - `ThemeRenderer::renderCatgrid()` (album branch) now appends a `.lum-card-date`
    span reading "Added {j M Y}", derived from the album's existing `created_at`
    column, alongside the existing image-count and view-count spans.
  - `ThemeRenderer::renderThumbgrid()` now appends a `.lum-thumb-date` span to each
    thumbnail's `<figcaption>`, derived from the image's existing `added_at` column.
    The span is full-width (`flex: 1 0 100%`) so it wraps onto its own centred row
    below the resolution/views row rather than competing for horizontal space.
  - Both bundled core themes (`default`, `classic-fansite`) receive matching
    `.lum-card-date` (tinted row, consistent with the existing `.lum-card-images` /
    `.lum-card-views` pattern) and `.lum-thumb-date` (centred caption row with a
    📅 icon, consistent with the existing `.lum-views` 👁 icon) rules. No database
    migration or service-layer change was required — `created_at` and `added_at`
    were already selected by `GalleryService::getAlbums()`/`getAlbum()` and every
    image-fetching method respectively.

- **Sort bar overflowed past the viewport edge on narrow phones** (`themes/default/lumora.css`,
  `themes/classic-fansite/fansite.css`):
  `ThemeRenderer::renderSortControls()` renders the five sort options (Default,
  Newest, Oldest, Most Viewed, Filename) inside a single Bootstrap `.btn-group`,
  which by design is a non-wrapping flex item with joined-button negative margins.
  On phones narrower than ~575px the group has no room to fit all five buttons and
  overflows past the right edge of the viewport instead of wrapping, confirmed on
  the `aknightofthesevenkindoms` theme (a `classic-fansite` derivative) and present
  identically in both bundled core themes. Fixed by adding a `@media (max-width:
  575px)` rule to each theme's existing mobile breakpoint block that forces
  `.lum-sort-bar .btn-group` to `display: flex; flex-wrap: wrap`, sizes each `.btn`
  with `flex: 1 1 auto`, and resets the joined-row-only negative `margin-left` and
  squared-off corners (`border-radius` restored per-button) so wrapped rows render
  cleanly. `default/lumora.css` uses `var(--lum-card-radius)`; `classic-fansite/fansite.css`
  uses `var(--fs-radius)`, consistent with each theme's existing variable scheme.

- **Category list header labels overflowed past the viewport edge on narrow phones**
  (`themes/default/lumora.css`, `themes/classic-fansite/fansite.css`):
  The existing `@media (max-width: 575px)` rule shrinks the `.lum-catlist-col-albums`
  and `.lum-catlist-col-images` *data* cells to a 56px column width, but never touched
  the matching `.lum-catlist-header-cell--albums` / `--images` *header* labels
  ("ALBUMS" / "IMAGES"), which kept the full `.75rem` font-size, `.75rem` padding, and
  `.05em` letter-spacing — far too wide for a 56px column, so "IMAGES" ran past the
  card and viewport edge (confirmed on the `aknightofthesevenkindoms` theme, a
  `classic-fansite` derivative, and present identically in both core themes). Fixed by
  shrinking the header cells to `.6rem` font-size, `.15rem` horizontal padding, and
  zero letter-spacing within the same mobile breakpoint, matching the row cells.

- corrected the official Lumora Gallery website URL in `ThemeRenderer.php`

- **Album cards showed the Lumora import date instead of when content was actually last added** (`include/services/GalleryService.php`, `include/services/ThemeRenderer.php`):
  Follow-up to the date-display fix above. The album card's date span (added in that
  fix) read `albums.created_at` — the timestamp the album row was inserted/imported,
  set once and never updated again — so albums that received new images long after
  import (e.g. via the Coppermine importer) kept showing the original import date
  under the label "Added", even though `images.added_at` was correct for every
  individual image. `GalleryService::getLatestUpdatedAlbums()` already computed the
  correct value (`MAX(images.added_at)` as `latest_added_at`) for the home page's
  "Recently Updated" section, but `ThemeRenderer::renderCatgrid()` ignored that field
  and used `created_at` regardless of which query supplied the row. Fixed:
  - `GalleryService::getAlbums()` now also selects `latest_added_at` via the same
    `MAX(i2.added_at)` subquery already used by `getLatestUpdatedAlbums()`, so
    category-page album listings carry the same field.
  - `ThemeRenderer::renderCatgrid()` (album branch) now prefers `latest_added_at`
    over `created_at` for the date span, falling back to `created_at` only for
    albums with no approved images yet, and relabels the span from "Added" to
    "Updated" to reflect what the date now represents. No CSS or schema change
    required — `.lum-card-date` styling and the `created_at` column are unchanged.

---

## [1.7.0] — 2026-06-16

### Added

- **Update Checker — Phase 1** (`include/services/UpdateService.php`, `admin/update.php`,
  `admin/ajax_update_check.php`, `admin/includes/admin_helpers.php`, `admin/dashboard.php`,
  `include/bootstrap.php`):
  Lumora can now check for newer releases against a JSON endpoint hosted on the Lumora
  website (`https://coding.unloved-heart.net/lumora/update.json`). No gallery content, user
  data, or identifying information is transmitted — only a plain GET request is made.

  - **`UpdateService`** (`include/services/UpdateService.php`) — new static service class.
    Fetches the remote endpoint, caches the result in the config table for 24 hours, and
    exposes `check(bool $force)` for a full status check, `getCachedStatus()` for a
    cache-only read (used in the nav and dashboard to avoid any I/O on every page load),
    `hasCachedUpdate(): bool` for the nav badge, and `isCacheExpired(): bool` so the
    Updates page can auto-trigger an AJAX refresh when the cache is stale.
    `version_compare()` is used for semantic version comparison.
    Network failures fall back to the stale cache so admins always see the last known
    state rather than a blank error. A temporary `set_error_handler` / `restore_error_handler`
    pair suppresses the E_WARNING that `file_get_contents()` emits on TCP failure without
    using the `@` operator.

  - **`admin/update.php`** — new admin page showing installed version, current status
    (up to date / update available / error / not checked), last-checked timestamp,
    changelog and download links when an update is available, a PHP-version compatibility
    warning when the new release requires a higher PHP version, and a **Check for Updates
    Now** button. The page renders from cache only (no PHP-level HTTP call); if the cache
    is expired, JS auto-triggers an AJAX check after DOM load to avoid server-side
    blocking.

  - **`admin/ajax_update_check.php`** — AJAX endpoint that calls
    `UpdateService::check(force: true)` and returns the full status array as JSON.
    Validates CSRF and admin authentication.

  - **`admin/includes/admin_helpers.php`** — **Updates** (🔔) nav item added between
    Import and Account. A red `!` badge appears next to the label whenever the cached
    status shows an update is available (no HTTP call — reads config cache only).

  - **`admin/dashboard.php`** — dismissible info-bar shown at the top of the dashboard
    when the cached status indicates an update is available. Includes inline changelog
    and download links plus a **Details** link to `update.php`. No HTTP call is made
    at dashboard render time.

  - **`include/bootstrap.php`** — `UpdateService.php` loaded in step 7 alongside the
    other service classes.

  **Update endpoint format** — the JSON file hosted at the Lumora website must follow
  this shape (all fields optional except `latest_version`):
  ```json
  {
    "latest_version": "1.6.0",
    "minimum_php":    "8.2",
    "release_date":   "2026-06-15",
    "download_url":   "https://github.com/{owner}/lumora/releases/download/v1.6.0/lumora-v1.6.0.zip",
    "changelog_url":  "https://coding.unloved-heart.net/lumora/changelog"
  }
  ```
  Additional fields may be added in future without breaking existing installations.

### Fixed

- **`install/schema.sql` — semicolon in `COMMENT` string made the schema permanently fragile**:
  The `thumb_image_id` column on `{PREFIX}categories` carried
  `COMMENT 'FK to images.id; 0 = auto-pick first album image'`. The semicolon
  inside the string literal is invisible to any string-literal-aware SQL splitter
  but is a latent footgun: if the `ins_split_sql()` guard is ever lost (e.g. the
  file is replaced with a pre-fix version), the naive `explode(';', ...)` path
  silently re-emerges and the installer breaks again. Fixed by changing the
  semicolon to a comma: `'FK to images.id, 0 = auto-pick first album image'`.
  The migration comment at the top of the file is updated to match.

- **`install/index.php` — `ins_split_sql()` lost when file was overwritten**:
  The string-literal-aware SQL splitter added to fix the `COMMENT` semicolon bug
  was absent from the file on disk — the file had been replaced with a pre-fix
  version. `ins_run_schema()` had reverted to the naive `explode(';', $sql_clean)`
  path, causing the same SQLSTATE[42000] error 1064 on fresh installs.
  `ins_split_sql()` re-added and `ins_run_schema()` updated to call it.

### Changed

- **`renderCatgrid()` — album / category card info restructured as individual rows**
  (`include/services/ThemeRenderer.php`, `themes/*/fansite.css`,
  `themes/default/lumora.css`):
  Album and category cards previously showed a single `<small class="text-muted">`
  string joining all info with ` — ` (e.g. "2,704 images — 527 views"). Each piece
  of info is now its own `<span>` inside a `.lum-card-meta` wrapper div, enabling
  themes to colour, center, and space each stat independently.
  - Albums emit `.lum-card-images` and `.lum-card-views` spans.
  - Categories emit `.lum-card-subcats` and/or `.lum-card-albums` spans.
  - All three bundled themes receive `.lum-card-meta` CSS: the default theme uses a
    light blue / neutral-gray pair; `classic-fansite` uses a light purple-tint /
    body-background pair; the GoT `aknightofthesevenkindoms` theme uses the
    Coppermine-matched teal / warm-beige pair shared with the thumbnail caption rows.

---

## [1.6.0] — 2026-06-15

### Added

- **Coppermine Importer — Stop Import button** (`plugins/coppermine-importer/admin/index.php`):
  Step 3 (Import Progress) now shows a ⏹ **Stop Import** button below the log
  panel. Clicking it sets a client-side `stopped` flag; the current in-flight
  AJAX batch is allowed to complete normally, then the loop halts instead of
  scheduling the next chunk. The button disables itself with a “Stopping after
  current batch…” label so the user knows the stop is pending. When the loop
  actually halts, the result panel shows a warning explaining that partial data
  was written and linking to the relevant admin pages for cleanup. The stop
  controls are hidden automatically on both clean completion and on error.

### Fixed

- **Album cards missing view count** (`include/services/ThemeRenderer.php`):
  Album cards rendered by `renderCatgrid()` displayed the image count but omitted
  the album view count. The `hits` column was already present in every album row
  (from `SELECT a.*` in `GalleryService::getAlbums()` and
  `getLatestUpdatedAlbums()`), so the fix is purely in the renderer: after
  computing the image-count string, two lines now derive `$views_str` from
  `$item['hits']` and append it with an em-dash separator, e.g.
  "42 images — 1,204 views". This affects all surfaces that call
  `renderCatgrid()` with `'album'`: the home-page "Recently Updated" strip,
  category album listings, and any future callers.

- **Coppermine Importer — album folder names used `cpg_albums.keyword` instead of actual on-disk path** (`CoppermineImporter.php`):
  `importAlbums()` derived each Lumora album folder name from the `keyword`
  column of `cpg_albums`, which may be empty or differ from the physical
  directory layout — especially on CPG installations where albums were created
  without an explicit keyword or were moved on disk. The correct source of
  truth is `cpg_pictures.filepath`, which CPG writes to every image row and
  which always reflects the actual folder path used on disk (e.g.
  `Season1/Screencaps/1x01-TheHedgeKnight`), preserving arbitrarily deep
  sub-directory trees. Fixed by adding `fetchCpgAlbumFilepaths()`, which runs
  one `SELECT aid, MIN(filepath) … GROUP BY aid` against `cpg_pictures` for
  every album chunk. The result is used as the primary folder source; albums
  with no images yet fall back to the previous `keyword`-based logic via
  `resolveCpgAlbumFolder()`. The method wraps its query in try-catch so
  installations without a `filepath` column degrade gracefully.

- **`plugins/coppermine-importer/CoppermineImporter.php` — image import failed with "Unknown column" on CPG installations with non-standard or incomplete schemas**:
  CPG databases upgraded in-place over many years often differ from the canonical
  schema, even when the application version is recent (confirmed on CPG 1.6.29).
  Two classes of column name variation were found and handled:
  - **`pwidth`/`pheight` instead of `width`/`height`**: this CPG 1.6.29 install
    stores image dimensions under `pwidth` and `pheight`.
  - **`ctime` instead of `added`**: creation timestamp stored as `ctime` rather
    than the standard `added` column name.
  - **`width`/`height`/`pos`/`caption` entirely absent**: columns added in later
    CPG versions that may simply not exist after an incomplete upgrade.
    `importImages()` previously built a fixed SELECT; any missing or renamed column
    caused `PDO::prepare()` to throw `PDOException[42S22]` immediately. Fixed by
    adding `getPictureColumns()` (queries `INFORMATION_SCHEMA.COLUMNS` once per
    request, cached on the instance) and building the SELECT dynamically. Renamed
    columns are aliased with SQL `AS` so the foreach always reads `$row['width']`,
    `$row['height']`, and `$row['added']` regardless of the actual column name;
    entirely absent columns fall back to `0` / `''` via the existing `?? 0` / `??''`
    expressions already in the foreach.

- **`plugins/coppermine-importer/admin/ajax_import.php` + `CoppermineImporter.php` — albums skipped, images HTTP 500 during first production import**:
  Three bugs manifested together:
  1. **`array_merge()` destroys integer keys** (`ajax_import.php`): The session
     `cat_id_map` and `album_id_map` were merged using `array_merge()`, which
     re-indexes integer keys. `array_merge([1=>X, 2=>Y], [3=>Z])` produces
     `[0=>X, 1=>Y, 2=>Z]` instead of `[1=>X, 2=>Y, 3=>Z]`. As a result, album
     lookups by CPG category cid all hit the wrong Lumora category (off by one),
     and any lookup for the highest cid returned null (skipped). Fixed by using
     the `+` operator, which preserves integer keys.
  2. **`filepath` column selected but never used** (`CoppermineImporter.php`):
     `importImages()` included `filepath` in its `SELECT` from `cpg_pictures`,
     but `$row['filepath']` is never referenced anywhere in the foreach loop —
     the album folder is resolved through `$folder_map` instead. If the CPG
     installation does not have a `filepath` column on the pictures table (some
     versions differ), `PDO::prepare()` throws an uncaught `PDOException`,
     producing a blank HTTP 500 with no body. Fixed by removing `filepath` from
     the SELECT.
  3. **No try-catch around importer calls** (`ajax_import.php`): Any uncaught
     exception from an importer method produced a raw HTTP 500 with an empty
     body, showing only "HTTP 500:" in the progress UI with no diagnostic
     message. Fixed by wrapping the entire action switch in `try { … }
     catch (\Throwable $e) { cpg_json_error(…) }` so errors are always
     surfaced as readable JSON.

- **`install/index.php` — schema setup failed on first installation**:
  `ins_run_schema()` split the schema SQL on bare semicolons using `explode(';', ...)`,
  which broke when encountering the semicolon inside the column comment
  `COMMENT 'FK to images.id; 0 = auto-pick first album image'` in the `categories`
  table. MariaDB received a truncated, syntactically invalid statement and returned
  error 1064. Fixed by replacing the naive splitter with a new `ins_split_sql()`
  helper that walks the SQL character-by-character as a state machine, tracking
  single-quoted strings, double-quoted strings, and backtick-quoted identifiers so
  that semicolons inside string literals are never treated as statement delimiters.

- **`plugins/coppermine-importer/admin/index.php` — "Start Import" redirected to migration hub instead of starting the import**:
  The step-2 Cancel button was rendered as a `<form>` nested inside the Start Import
  `<form>`. Browsers discard nested `<form>` tags but keep their child elements,
  so both `<input type="hidden" name="action">` fields (values `start_import` and
  `cancel`) ended up in the same outer form. PHP's `$_POST` retains the last
  occurrence of a duplicate key, so every click of "Start Import" actually posted
  `action=cancel`, clearing the session and redirecting to `admin/migrate.php`.
  The `{$reimport_check}` block (re-import confirmation checkbox) was also placed
  outside the form, meaning it would never be submitted. Fixed by moving the
  checkbox inside the Start Import form and separating the Cancel action into a
  sibling `<form id="cpg-cancel-form">` whose empty body does not affect layout;
  the Cancel button uses the HTML5 `form="cpg-cancel-form"` attribute to submit
  that form instead.

- **`plugins/coppermine-importer/admin/index.php` — blank page on first visit**:
  Two bugs caused a blank page when loading the plugin for the first time.
  1. **PHP heredoc parse error** (step-3 block): `{$n_cat.replace(',','')}` in a
     heredoc is invalid PHP variable interpolation — `.replace()` is a JavaScript
     method, not a PHP operator. PHP parses the entire file before executing any
     code, so the syntax error in the (unreachable on step 1) case-3 block killed
     the page for all steps. Fixed by pre-computing raw integer variables
     (`$n_cat_int`, `$n_alb_int`, `$n_img_int`) in PHP and interpolating those
     directly into the `var TOTAL = {...}` JavaScript literal.
  2. **Undefined function `lumora_csrf_check()`** (`ajax_import.php`): The CSRF
     helper in `include/auth.php` is `lumora_csrf_validate()` (which exits with
     plain text on failure), not `lumora_csrf_check()`. The AJAX handler needs to
     return JSON on CSRF failure, so the call is replaced with an inline boolean
     check using `hash_equals(lumora_csrf_token(), $_POST['csrf_token'])` followed
     by `cpg_json_error(..., 403)`.

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
  repository link (<https://coding.unloved-heart.net/lumora/>).

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

<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Updater Service
 *
 * Orchestrates the multi-step in-dashboard update process.  Each public stage
 * method performs one discrete unit of work and returns a standardised result
 * array so the AJAX controller can report granular progress.
 *
 * ── Stage flow ────────────────────────────────────────────────────────────────
 *
 *   preflight → download → verify → backup → maintenance
 *             → extract → validate → replace → migrate → cleanup
 *
 * The stages preflight through backup are non-destructive and purely prepare
 * artefacts in the working directory.  From maintenance onward, live application
 * state is modified; any failure in those stages triggers rollback().
 *
 * ── State persistence ─────────────────────────────────────────────────────────
 *
 * A JSON lock file at {LUMORA_ROOT}cache/.updates/lock.json is held for the
 * duration of the update.  It stores the target version, download URL, SHA-256
 * checksum, paths to the archive/extract/backup directories, the currently active
 * stage, and whether maintenance mode has been enabled.  Subsequent AJAX calls
 * read this file so no state needs to travel as POST parameters.
 *
 * ── Rollback ──────────────────────────────────────────────────────────────────
 *
 * rollback() restores config.php and the database from backup, then calls
 * stageCleanup(false) to disable maintenance mode and release the lock.
 * File rollback (restoring source files from a file backup) is not currently
 * implemented; the database and configuration are the critical artefacts.
 * Administrators should ensure they have a server-level file backup before
 * upgrading as recommended in the admin UI.
 *
 * ── Hosting compatibility ──────────────────────────────────────────────────────
 *
 * The entire update workflow uses only standard PHP filesystem functions.
 * No SSH, shell_exec, or CLI tools are required.  FTP credentials support
 * is a planned future enhancement.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class UpdaterService
{
    // ── Stage constants ───────────────────────────────────────────────────────

    public const STAGE_PREFLIGHT   = 'preflight';
    public const STAGE_DOWNLOAD    = 'download';
    public const STAGE_VERIFY      = 'verify';
    public const STAGE_BACKUP      = 'backup';
    public const STAGE_MAINTENANCE = 'maintenance';
    public const STAGE_EXTRACT     = 'extract';
    public const STAGE_VALIDATE    = 'validate';
    public const STAGE_REPLACE     = 'replace';
    public const STAGE_MIGRATE     = 'migrate';
    public const STAGE_CLEANUP     = 'cleanup';

    /**
     * Ordered stage sequence used by the progress UI.
     *
     * @var list<string>
     */
    public const STAGE_SEQUENCE = [
        self::STAGE_PREFLIGHT,
        self::STAGE_DOWNLOAD,
        self::STAGE_VERIFY,
        self::STAGE_BACKUP,
        self::STAGE_MAINTENANCE,
        self::STAGE_EXTRACT,
        self::STAGE_VALIDATE,
        self::STAGE_REPLACE,
        self::STAGE_MIGRATE,
        self::STAGE_CLEANUP,
    ];

    /**
     * Human-readable labels for each stage, keyed by STAGE_* constant.
     *
     * @var array<string, string>
     */
    public const STAGE_LABELS = [
        self::STAGE_PREFLIGHT   => 'Pre-flight checks',
        self::STAGE_DOWNLOAD    => 'Download archive',
        self::STAGE_VERIFY      => 'Verify integrity',
        self::STAGE_BACKUP      => 'Create backup',
        self::STAGE_MAINTENANCE => 'Maintenance mode',
        self::STAGE_EXTRACT     => 'Extract archive',
        self::STAGE_VALIDATE    => 'Validate files',
        self::STAGE_REPLACE     => 'Replace application files',
        self::STAGE_MIGRATE     => 'Run database migrations',
        self::STAGE_CLEANUP     => 'Cleanup & finish',
    ];

    // ── Path helpers ──────────────────────────────────────────────────────────

    /** Working directory for all update artefacts. */
    private static function updatesDir(): string
    {
        return LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR . '.updates' . DIRECTORY_SEPARATOR;
    }

    /** JSON lock file. */
    private static function lockFile(): string
    {
        return self::updatesDir() . 'lock.json';
    }

    /** Downloaded archive path for a given version. */
    private static function archivePath(string $version): string
    {
        return self::updatesDir() . 'lumora-v' . ltrim($version, 'v') . '.zip';
    }

    /** Directory where the archive is extracted. */
    private static function extractDir(): string
    {
        return self::updatesDir() . 'extract' . DIRECTORY_SEPARATOR;
    }

    /** Directory where the backup is written. */
    private static function backupDir(): string
    {
        return self::updatesDir() . 'backup' . DIRECTORY_SEPARATOR;
    }

    /** Append-only update log file. */
    private static function logFile(): string
    {
        return self::updatesDir() . 'update.log';
    }

    /** Presence of this file indicates maintenance mode is active. */
    private static function maintenanceFlagFile(): string
    {
        return self::updatesDir() . '.maintenance_active';
    }

    // ── Lock file management ──────────────────────────────────────────────────

    /**
     * Return true when an update lock is currently held.
     */
    public static function isUpdateRunning(): bool
    {
        return file_exists(self::lockFile());
    }

    /**
     * Acquire the update lock for the given version.
     *
     * Creates the updates working directory on first use.
     * Returns false when a lock is already held.
     *
     * @param string               $version Target version string (e.g. '1.9.0').
     * @param array<string, mixed> $extra   Additional fields to merge into the lock.
     */
    public static function acquireLock(string $version, array $extra = []): bool
    {
        if (self::isUpdateRunning()) {
            return false;
        }

        self::ensureUpdatesDir();

        $lock = array_merge([
            'version'            => $version,
            'stage'              => self::STAGE_PREFLIGHT,
            'started_at'         => time(),
            'archive_path'       => self::archivePath($version),
            'extract_dir'        => self::extractDir(),
            'backup_dir'         => self::backupDir(),
            'extracted_app_root' => null,
            'download_url'       => null,
            'sha256'             => null,
            'provider'           => '',
            'maintenance'        => false,
        ], $extra);

        return file_put_contents(
            self::lockFile(),
            json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    /**
     * Delete the update lock file.
     */
    public static function releaseLock(): void
    {
        $path = self::lockFile();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Return the current lock data as an array, or null when no lock is held.
     *
     * @return array<string, mixed>|null
     */
    public static function getLockInfo(): ?array
    {
        $path = self::lockFile();
        if (!file_exists($path)) return null;

        $raw = file_get_contents($path);
        if ($raw === false) return null;

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Merge updated fields into the existing lock file.
     *
     * @param array<string, mixed> $updates
     */
    private static function updateLock(array $updates): void
    {
        $lock = self::getLockInfo() ?? [];
        $lock = array_merge($lock, $updates);
        file_put_contents(
            self::lockFile(),
            json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // ── Stage dispatcher ──────────────────────────────────────────────────────

    /**
     * Run the named update stage and return a result array.
     *
     * The $version parameter is required only for STAGE_PREFLIGHT; all
     * subsequent stages read state from the lock file.
     *
     * @param string $stage   One of the STAGE_* constants.
     * @param string $version Target version (required for STAGE_PREFLIGHT).
     *
     * @return array{
     *   success: bool,
     *   stage:   string,
     *   message: string,
     *   next:    string|null,
     *   details: list<string>
     * }
     */
    public static function runStage(string $stage, string $version = ''): array
    {
        // Allow long-running stages without hitting PHP's default 30-second limit.
        set_time_limit(180);

        try {
            return match ($stage) {
                self::STAGE_PREFLIGHT   => self::stagePreflight($version),
                self::STAGE_DOWNLOAD    => self::stageDownload(),
                self::STAGE_VERIFY      => self::stageVerify(),
                self::STAGE_BACKUP      => self::stageBackup(),
                self::STAGE_MAINTENANCE => self::stageMaintenance(),
                self::STAGE_EXTRACT     => self::stageExtract(),
                self::STAGE_VALIDATE    => self::stageValidate(),
                self::STAGE_REPLACE     => self::stageReplace(),
                self::STAGE_MIGRATE     => self::stageMigrate(),
                self::STAGE_CLEANUP     => self::stageCleanup(true),
                default => self::fail($stage, "Unknown update stage: {$stage}"),
            };
        } catch (\Throwable $e) {
            self::logUpdate('error', "Stage {$stage} threw unexpectedly: " . $e->getMessage());
            return self::fail($stage, 'Unexpected error: ' . $e->getMessage());
        }
    }

    // ── Stage implementations ─────────────────────────────────────────────────

    /**
     * Stage 1 — Pre-flight checks.
     *
     * Verifies prerequisites, acquires the update lock, and fetches release
     * metadata (download URL + optional SHA-256) from the configured provider.
     */
    private static function stagePreflight(string $version): array
    {
        if ($version === '') {
            return self::fail(self::STAGE_PREFLIGHT, 'Target version not specified.');
        }

        // Resume: lock already held for this version means preflight passed.
        if (self::isUpdateRunning()) {
            $lock = self::getLockInfo();
            if ($lock !== null && $lock['version'] === $version) {
                return self::ok(
                    self::STAGE_PREFLIGHT,
                    'Pre-flight checks already completed.',
                    self::STAGE_DOWNLOAD,
                    ['Resuming existing update session for v' . $version . '.']
                );
            }
            return self::fail(
                self::STAGE_PREFLIGHT,
                'Another update is already in progress. ' .
                'If no update is running, the previous session may have been interrupted. ' .
                'Use the Abort button to reset.'
            );
        }

        $details = [];
        $errors  = [];

        // PHP ZipArchive extension.
        if (!class_exists('ZipArchive')) {
            $errors[] = 'PHP ZipArchive extension is required. Enable ext-zip on your server.';
        } else {
            $details[] = '✓ PHP ZipArchive available';
        }

        // PHP version compatibility (from update check cache).
        $cached = UpdateService::getCachedStatus();
        $minPhp = $cached['minimum_php'];
        if ($minPhp !== null && version_compare(PHP_VERSION, $minPhp, '<')) {
            $errors[] = 'This release requires PHP ' . $minPhp
                . '. Your server is running PHP ' . PHP_VERSION
                . '. Please upgrade PHP before installing this update.';
        } else {
            $details[] = '✓ PHP ' . PHP_VERSION . ' is compatible';
        }

        // Disk space (require at least 80 MB free for download + extract + backup).
        $cacheDir   = LUMORA_ROOT . 'cache';
        $freeBytes  = disk_free_space(is_dir($cacheDir) ? $cacheDir : LUMORA_ROOT);
        if ($freeBytes === false) {
            $details[] = '⚠ Could not determine available disk space; proceeding with caution';
        } elseif ($freeBytes < 83886080) {
            $errors[] = 'Insufficient disk space: '
                . lumora_format_bytes((int) $freeBytes) . ' available; '
                . 'at least 80 MB is recommended for the update process.';
        } else {
            $details[] = '✓ ' . lumora_format_bytes((int) $freeBytes) . ' disk space available';
        }

        // Write permission.
        if (!is_writable(LUMORA_ROOT)) {
            $errors[] = 'The Lumora root directory is not writable by the web server. '
                . 'Please adjust file permissions and try again.';
        } else {
            $details[] = '✓ File system is writable';
        }

        if (!empty($errors)) {
            return self::fail(self::STAGE_PREFLIGHT, 'Pre-flight checks failed.', $errors);
        }

        // Fetch provider metadata for download URL + optional checksum.
        $provider = AbstractUpdateProvider::createFromConfig();
        $meta     = null;
        $sha256   = null;
        $dlUrl    = null;

        try {
            $meta = $provider->fetchMetadata();
        } catch (\Throwable) {
            // Non-fatal: fall back to cached URL below.
        }

        if ($meta !== null) {
            $sha256 = $meta['sha256'] ?? null;
            $dlUrl  = $meta['download_url'] ?? null;
        }

        // Fall back to the URL already in the update check cache.
        if ($dlUrl === null) {
            $dlUrl = $cached['download_url'];
        }

        // Last resort: construct URL from the provider's build method.
        if ($dlUrl === null) {
            $dlUrl = $provider->buildArchiveUrl($version);
        }

        // Acquire the lock.
        $acquired = self::acquireLock($version, [
            'download_url' => $dlUrl,
            'sha256'       => $sha256,
            'provider'     => $provider->getName(),
        ]);

        if (!$acquired) {
            return self::fail(
                self::STAGE_PREFLIGHT,
                'Could not create the update working directory. '
                . 'Ensure the cache/ directory is writable.'
            );
        }

        $details[] = '✓ Update lock acquired';
        $details[] = '✓ Provider: ' . $provider->getName();
        $details[] = $sha256 !== null
            ? '✓ SHA-256 checksum available for integrity verification'
            : '⚠ No SHA-256 checksum found — integrity verification will be skipped';

        self::logUpdate('info', "Preflight passed for v{$version} via {$provider->getName()}");

        return self::ok(
            self::STAGE_PREFLIGHT,
            'Pre-flight checks passed.',
            self::STAGE_DOWNLOAD,
            $details
        );
    }

    /**
     * Stage 2 — Download the release archive.
     */
    private static function stageDownload(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_DOWNLOAD, 'No active update lock. Please restart from Pre-flight.');
        }

        $version     = (string) $lock['version'];
        $dlUrl       = $lock['download_url'];
        $archivePath = self::archivePath($version);

        if ($dlUrl === null) {
            return self::fail(self::STAGE_DOWNLOAD, 'No download URL in update lock. Please restart from Pre-flight.');
        }

        // Resume: if a non-empty archive already exists, skip re-download.
        if (file_exists($archivePath) && filesize($archivePath) > 0) {
            self::logUpdate('info', "Archive already present at {$archivePath}; skipping download");
            return self::ok(
                self::STAGE_DOWNLOAD,
                'Archive already downloaded.',
                self::STAGE_VERIFY,
                ['Previously downloaded archive found; proceeding to verification.']
            );
        }

        self::logUpdate('info', "Downloading v{$version} from {$dlUrl}");

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 120,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => 'Lumora Gallery/' . LUMORA_VERSION,
                'ignore_errors'   => false,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static fn(): bool => true);
        try {
            $data = file_get_contents($dlUrl, false, $ctx);
        } finally {
            restore_error_handler();
        }

        if ($data === false || strlen($data) === 0) {
            self::logUpdate('error', "Download failed from {$dlUrl}");
            return self::fail(
                self::STAGE_DOWNLOAD,
                'Download failed. Check server connectivity and try again. '
                . 'Ensure the server can make outbound HTTPS requests.'
            );
        }

        if (file_put_contents($archivePath, $data) === false) {
            self::logUpdate('error', "Could not write archive to {$archivePath}");
            return self::fail(self::STAGE_DOWNLOAD, 'Could not write downloaded archive to disk. Check permissions.');
        }

        $size = strlen($data);
        self::logUpdate('info', "Downloaded " . lumora_format_bytes($size) . " to {$archivePath}");

        return self::ok(
            self::STAGE_DOWNLOAD,
            'Archive downloaded (' . lumora_format_bytes($size) . ').',
            self::STAGE_VERIFY,
            ['Saved as: ' . basename($archivePath)]
        );
    }

    /**
     * Stage 3 — Verify archive integrity.
     *
     * Validates SHA-256 checksum (when available) and confirms the ZIP structure.
     * Checksums protect against corruption; cryptographic signatures (a future
     * requirement) will protect against tampering.
     */
    private static function stageVerify(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_VERIFY, 'No active update lock.');
        }

        $version      = (string) $lock['version'];
        $archivePath  = self::archivePath($version);
        $expectedHash = $lock['sha256'];
        $details      = [];

        if (!file_exists($archivePath)) {
            return self::fail(self::STAGE_VERIFY, 'Archive not found; please re-run the Download stage.');
        }

        // SHA-256 checksum.
        if ($expectedHash !== null) {
            $actualHash = hash_file('sha256', $archivePath);
            if ($actualHash === false) {
                return self::fail(self::STAGE_VERIFY, 'Could not compute SHA-256 of the downloaded archive.');
            }
            if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
                self::logUpdate('error', "SHA-256 mismatch — expected {$expectedHash}, got {$actualHash}");
                return self::fail(
                    self::STAGE_VERIFY,
                    'Archive integrity check failed: SHA-256 mismatch. '
                    . 'The download may be corrupt or the archive may have been tampered with. '
                    . 'Delete the cached archive and try again.'
                );
            }
            $details[] = '✓ SHA-256 verified (' . substr($actualHash, 0, 16) . '…)';
            self::logUpdate('info', "SHA-256 verified for v{$version}");
        } else {
            $details[] = '⚠ No expected checksum available — skipping SHA-256 verification';
            self::logUpdate('warning', "No SHA-256 available for v{$version}; integrity check skipped");
        }

        // ZIP structure sanity check.
        if (!class_exists('ZipArchive')) {
            return self::fail(self::STAGE_VERIFY, 'PHP ZipArchive extension is not available.');
        }

        $zip = new \ZipArchive();
        $res = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($res !== true) {
            return self::fail(
                self::STAGE_VERIFY,
                'The downloaded file is not a valid ZIP archive (ZipArchive error code: ' . $res . ').'
            );
        }

        $numFiles = $zip->count();
        $zip->close();

        if ($numFiles === 0) {
            return self::fail(self::STAGE_VERIFY, 'The archive is empty.');
        }

        $details[] = '✓ ZIP structure valid (' . $numFiles . ' entries)';

        return self::ok(self::STAGE_VERIFY, 'Archive integrity verified.', self::STAGE_BACKUP, $details);
    }

    /**
     * Stage 4 — Create database and configuration backup.
     *
     * A SQL dump of all prefixed tables is created via PDO, along with a copy
     * of config.php.  Both artefacts are stored in the backup directory and
     * used by rollback() if a later stage fails.
     */
    private static function stageBackup(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_BACKUP, 'No active update lock.');
        }

        $backupDir = self::backupDir();

        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true)) {
            return self::fail(self::STAGE_BACKUP, 'Could not create backup directory.');
        }

        $details = [];
        $errors  = [];

        // Config file backup.
        $configSrc  = LUMORA_ROOT . 'config.php';
        $configDest = $backupDir . 'config.php';
        if (file_exists($configSrc)) {
            if (copy($configSrc, $configDest)) {
                $details[] = '✓ config.php backed up';
                self::logUpdate('info', 'Backed up config.php');
            } else {
                $errors[] = 'Could not back up config.php — check permissions on the backup directory.';
            }
        } else {
            $details[] = '⚠ config.php not found; skipped';
        }

        // Database backup.
        $dbSql    = $backupDir . 'database.sql';
        $dbResult = self::dumpDatabase($dbSql);
        if ($dbResult['success']) {
            $details[] = '✓ Database backed up ('
                . $dbResult['tables'] . ' tables, '
                . lumora_format_bytes($dbResult['bytes']) . ')';
            self::logUpdate('info', "Database backup: {$dbResult['tables']} tables → {$dbSql}");
        } else {
            $errors[] = 'Database backup failed: ' . $dbResult['error'];
        }

        if (!empty($errors)) {
            return self::fail(
                self::STAGE_BACKUP,
                'Backup could not be completed. The update will not proceed without a valid backup.',
                $errors
            );
        }

        self::updateLock(['backup_dir' => $backupDir]);

        return self::ok(self::STAGE_BACKUP, 'Backup created.', self::STAGE_MAINTENANCE, $details);
    }

    /**
     * Stage 5 — Enable maintenance mode.
     *
     * Sets the gallery_offline config key and writes a maintenance flag file.
     * The flag file is the primary indicator used by rollback/cleanup to decide
     * whether maintenance mode needs to be disabled.
     */
    private static function stageMaintenance(): array
    {
        $flagFile = self::maintenanceFlagFile();

        if (!file_exists($flagFile)) {
            file_put_contents($flagFile, (string) time());
        }

        self::updateLock(['maintenance' => true]);
        self::logUpdate('info', 'Maintenance mode enabled');

        try {
            LumoraConfig::set('gallery_offline', '1');
        } catch (\Throwable) {
            // Non-fatal: flag file is the primary indicator.
        }

        return self::ok(
            self::STAGE_MAINTENANCE,
            'Maintenance mode enabled. The gallery is offline to visitors.',
            self::STAGE_EXTRACT
        );
    }

    /**
     * Stage 6 — Extract the release archive to the working directory.
     *
     * All archive entry names are validated against path-traversal patterns
     * before extraction begins.  Any unsafe entry aborts the operation.
     */
    private static function stageExtract(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_EXTRACT, 'No active update lock.');
        }

        $version     = (string) $lock['version'];
        $archivePath = self::archivePath($version);
        $extractDir  = self::extractDir();

        if (!file_exists($archivePath)) {
            return self::fail(self::STAGE_EXTRACT, 'Archive not found. Please re-run from the Download stage.');
        }

        // Clean any existing extract directory for a fresh start.
        if (is_dir($extractDir)) {
            self::removeDirectory($extractDir);
        }

        if (!mkdir($extractDir, 0755, true)) {
            return self::fail(self::STAGE_EXTRACT, 'Could not create extraction directory.');
        }

        if (!class_exists('ZipArchive')) {
            return self::fail(self::STAGE_EXTRACT, 'PHP ZipArchive extension is not available.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return self::fail(self::STAGE_EXTRACT, 'Could not open archive for extraction.');
        }

        // Security: pre-extraction pass — reject unsafe entry names.
        // Checks for path-traversal sequences, absolute paths, Windows-style
        // backslashes, and null bytes before a single byte is written to disk.
        $numEntries = $zip->count();
        for ($i = 0; $i < $numEntries; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;
            if (
                str_contains($name, '..')   ||
                str_starts_with($name, '/') ||
                str_contains($name, '\\') ||
                str_contains($name, "\0")
            ) {
                $zip->close();
                return self::fail(
                    self::STAGE_EXTRACT,
                    'Archive contains an unsafe path entry: ' . $name . '. Aborting for security.'
                );
            }
        }

        $ok = $zip->extractTo($extractDir);
        $zip->close();

        if (!$ok) {
            return self::fail(self::STAGE_EXTRACT, 'Archive extraction failed.');
        }

        // Security: post-extraction pass — verify every extracted path resolves
        // within the intended extraction directory via realpath().  This guards
        // against edge cases (OS-specific normalisation, symlinks) that the
        // string-based pre-check alone cannot catch.
        $canonExtractDir = rtrim((string) (realpath($extractDir) ?: $extractDir), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;

        $postIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($postIterator as $extractedItem) {
            $resolvedPath = realpath($extractedItem->getPathname());
            if (
                $resolvedPath !== false &&
                !str_starts_with($resolvedPath . DIRECTORY_SEPARATOR, $canonExtractDir)
            ) {
                // A path escaped the extraction directory — clean up and abort.
                self::removeDirectory($extractDir);
                self::logUpdate('error', 'Post-extraction realpath check: path escaped extraction directory — aborted');
                return self::fail(
                    self::STAGE_EXTRACT,
                    'Archive extraction produced a path outside the extraction directory. '
                    . 'The archive may have been tampered with. Aborting for security.'
                );
            }
        }

        self::logUpdate('info', "Extracted {$numEntries} entries to {$extractDir}");

        return self::ok(
            self::STAGE_EXTRACT,
            "Archive extracted ({$numEntries} entries).",
            self::STAGE_VALIDATE
        );
    }

    /**
     * Stage 7 — Validate the extracted files.
     *
     * Locates the Lumora application root inside the extracted archive
     * (the directory that directly contains version.php) and confirms that
     * key structural files are present.  The resolved app root path is stored
     * in the lock file for the replace stage.
     */
    private static function stageValidate(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_VALIDATE, 'No active update lock.');
        }

        $version    = (string) $lock['version'];
        $extractDir = self::extractDir();

        $appRoot = self::findAppRoot($extractDir);
        if ($appRoot === null) {
            return self::fail(
                self::STAGE_VALIDATE,
                'Could not locate version.php inside the extracted archive. '
                . 'The archive may be corrupt or have an unexpected directory structure.'
            );
        }

        $details = ['App root in archive: …' . str_replace($extractDir, '', $appRoot)];

        // Verify the declared version matches what we expect.
        $versionFile    = $appRoot . 'version.php';
        $versionContent = file_get_contents($versionFile);
        if ($versionContent === false) {
            return self::fail(self::STAGE_VALIDATE, 'Could not read version.php from the extracted archive.');
        }

        $vStr = "'{$version}'";
        if (!str_contains($versionContent, $vStr) && !str_contains($versionContent, '"' . $version . '"')) {
            $details[] = '⚠ version.php does not contain the expected version string ' . $version;
            self::logUpdate('warning', "version.php does not contain expected version {$version}");
        } else {
            $details[] = '✓ version.php contains v' . $version;
        }

        // Required structural entries.
        $required = ['include/bootstrap.php', 'include/services', 'admin'];
        $missing  = [];
        foreach ($required as $rel) {
            if (!file_exists($appRoot . $rel)) {
                $missing[] = $rel;
            }
        }

        if (!empty($missing)) {
            return self::fail(
                self::STAGE_VALIDATE,
                'Required paths are missing from the extracted archive: ' . implode(', ', $missing)
            );
        }

        $details[] = '✓ Required files and directories present';

        // Persist the resolved path for the replace stage.
        self::updateLock(['extracted_app_root' => $appRoot]);

        return self::ok(self::STAGE_VALIDATE, 'Extracted files validated.', self::STAGE_REPLACE, $details);
    }

    /**
     * Stage 8 — Replace application files.
     *
     * Copies files from the extracted archive root to LUMORA_ROOT, skipping
     * the following preserved paths:
     *
     *   Always preserved:
     *     config.php   — environment-specific credentials
     *     albums/      — user-uploaded image files
     *     cache/       — runtime cache + update working directory
     *
     *   Preserved by default (configurable):
     *     themes/      — set update_preserve_themes = 0 to overwrite
     *     plugins/     — set update_preserve_plugins = 0 to overwrite
     */
    private static function stageReplace(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return self::fail(self::STAGE_REPLACE, 'No active update lock.');
        }

        $srcRoot = $lock['extracted_app_root'] ?? null;

        if ($srcRoot === null || !is_dir($srcRoot)) {
            return self::fail(
                self::STAGE_REPLACE,
                'Extracted app root not set or missing. Please re-run the Validate stage.'
            );
        }

        // Paths (relative to LUMORA_ROOT) that must never be overwritten.
        $preserve = ['config.php', 'albums', 'cache'];

        $preserveThemes  = ((string) LumoraConfig::get('update_preserve_themes',  '1')) !== '0';
        $preservePlugins = ((string) LumoraConfig::get('update_preserve_plugins', '1')) !== '0';

        if ($preserveThemes)  $preserve[] = 'themes';
        if ($preservePlugins) $preserve[] = 'plugins';

        $stats = ['copied' => 0, 'skipped' => 0, 'errors' => []];

        try {
            self::copyDirectory($srcRoot, LUMORA_ROOT, $preserve, $stats);
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Copy operation failed: ' . $e->getMessage();
        }

        if (!empty($stats['errors'])) {
            self::logUpdate('error', 'Replace stage errors: ' . implode('; ', $stats['errors']));
            return self::fail(
                self::STAGE_REPLACE,
                'File replacement failed.',
                $stats['errors']
            );
        }

        self::logUpdate('info', "Replace: {$stats['copied']} files updated, {$stats['skipped']} preserved");

        $details = [
            '✓ ' . $stats['copied'] . ' files updated',
            '✓ ' . $stats['skipped'] . ' preserved paths left untouched',
            $preserveThemes  ? '✓ themes/ preserved' : '⚠ themes/ overwritten (update_preserve_themes=0)',
            $preservePlugins ? '✓ plugins/ preserved' : '⚠ plugins/ overwritten (update_preserve_plugins=0)',
        ];

        return self::ok(self::STAGE_REPLACE, 'Application files updated.', self::STAGE_MIGRATE, $details);
    }

    /**
     * Stage 9 — Run pending database migrations.
     *
     * Delegates entirely to SchemaService::runPendingMigrations(), which runs
     * each migration in ascending numeric order and stops on the first failure.
     */
    private static function stageMigrate(): array
    {
        $result  = SchemaService::runPendingMigrations();
        $applied = count($result['applied']);
        $errors  = $result['errors'];

        if (!empty($errors)) {
            self::logUpdate('error', 'Migration failed: ' . implode('; ', $errors));
            return self::fail(self::STAGE_MIGRATE, 'Database migration failed.', $errors);
        }

        $details = $applied === 0
            ? ['No pending migrations — database schema is already up to date.']
            : array_map(fn(string $m): string => '✓ Applied: ' . $m, $result['applied']);

        self::logUpdate('info', "Migrations: {$applied} applied successfully");

        $message = $applied === 0
            ? 'Database schema is up to date.'
            : "{$applied} migration(s) applied.";

        return self::ok(self::STAGE_MIGRATE, $message, self::STAGE_CLEANUP, $details);
    }

    /**
     * Stage 10 — Cleanup: clear caches, disable maintenance mode, release lock.
     *
     * Called with $success = false during rollback to indicate that the update
     * did not complete successfully.
     */
    private static function stageCleanup(bool $success): array
    {
        $details = [];
        $lock    = self::getLockInfo();
        $version = $lock['version'] ?? 'unknown';

        // OPcache clear — important after file replacement so PHP loads the new code.
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $details[] = '✓ OPcache cleared';
        }

        // Clear Lumora cache files (not subdirectories like .updates/).
        $cacheRoot = LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR;
        $cleared   = 0;
        if (is_dir($cacheRoot)) {
            foreach (new \DirectoryIterator($cacheRoot) as $f) {
                if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) continue;
                unlink($f->getPathname());
                $cleared++;
            }
        }
        if ($cleared > 0) {
            $details[] = "✓ Cleared {$cleared} cache file(s)";
        }

        // Disable maintenance mode.
        $flagFile = self::maintenanceFlagFile();
        if (file_exists($flagFile)) {
            unlink($flagFile);
        }
        try {
            LumoraConfig::set('gallery_offline', '0');
        } catch (\Throwable) {}
        $details[] = '✓ Maintenance mode disabled';

        // Log completion.
        if ($success) {
            self::logUpdate('info', "Update to v{$version} completed successfully");
            self::recordUpdateHistory($version, true, 'Updated to v' . $version . ' successfully.');
        } else {
            self::logUpdate('info', "Cleanup after failed update to v{$version}");
        }

        // Auto-remove the install/ directory on a successful upgrade.
        // The installer already attempts this after a fresh install; doing it here
        // also catches cases where that step was skipped, or a reinstall left the
        // directory behind.
        if ($success) {
            $installDir = LUMORA_ROOT . 'install';
            if (is_dir($installDir)) {
                if (!is_writable($installDir)) {
                    $details[] = '⚠ install/ is not writable by the web server — delete it manually via FTP';
                    self::logUpdate('warning', 'install/ directory is not writable; automatic removal skipped (check permissions)');
                } else {
                    try {
                        self::removeDirectory($installDir);
                        if (!is_dir($installDir)) {
                            $details[] = '✓ install/ directory removed';
                            self::logUpdate('info', 'install/ directory removed automatically after upgrade');
                        } else {
                            $details[] = '⚠ install/ could not be fully removed (files may be locked or permissions are insufficient) — delete it manually via FTP';
                            self::logUpdate('warning', 'install/ directory still present after removal attempt — check file permissions');
                        }
                    } catch (\Throwable $e) {
                        $details[] = '⚠ install/ removal failed: ' . $e->getMessage() . ' — delete it manually via FTP';
                        self::logUpdate('warning', 'install/ directory removal failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // Release the update lock.
        self::releaseLock();
        $details[] = '✓ Update lock released';

        $message = $success
            ? 'Update complete! Lumora has been updated to v' . $version . '.'
            : 'Rollback complete. The previous version has been restored.';

        return self::ok(self::STAGE_CLEANUP, $message, null, $details);
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    /**
     * Attempt to roll back a failed update.
     *
     * Restores config.php and the database from the backup created in Stage 4,
     * then calls stageCleanup(false) to disable maintenance mode and release
     * the lock.
     *
     * Note: source-file rollback (restoring replaced PHP files from a file backup)
     * is not yet implemented.  Administrators should maintain server-level file
     * backups as an additional safety net.
     *
     * @return array{success: bool, message: string, details: list<string>}
     */
    public static function rollback(): array
    {
        $lock = self::getLockInfo();
        if ($lock === null) {
            return ['success' => false, 'message' => 'No active update lock found.', 'details' => []];
        }

        $backupDir = self::backupDir();
        $details   = [];
        $errors    = [];

        self::logUpdate('info', 'Rollback initiated for v' . ($lock['version'] ?? 'unknown'));

        // Restore config.php.
        $configBackup = $backupDir . 'config.php';
        if (file_exists($configBackup)) {
            if (copy($configBackup, LUMORA_ROOT . 'config.php')) {
                $details[] = '✓ config.php restored';
                self::logUpdate('info', 'config.php restored from backup');
            } else {
                $errors[] = 'Could not restore config.php from backup.';
                self::logUpdate('error', 'config.php restore failed');
            }
        } else {
            $details[] = '⚠ No config.php backup found; skipped';
        }

        // Restore database.
        $dbBackup = $backupDir . 'database.sql';
        if (file_exists($dbBackup)) {
            $r = self::restoreDatabase($dbBackup);
            if ($r['success']) {
                $details[] = '✓ Database restored (' . $r['statements'] . ' statements)';
                self::logUpdate('info', "Database restored: {$r['statements']} statements");
            } else {
                $errors[] = 'Database restore failed: ' . $r['error'];
                self::logUpdate('error', 'Database restore failed: ' . $r['error']);
            }
        } else {
            $errors[] = 'No database backup found — manual database restore may be required.';
            self::logUpdate('error', 'No database backup found for rollback');
        }

        if (!empty($errors)) {
            self::logUpdate('error', 'Rollback encountered errors: ' . implode('; ', $errors));
        }

        $version = (string) ($lock['version'] ?? 'unknown');
        self::recordUpdateHistory($version, false, 'Update to v' . $version . ' failed; rollback attempted.');

        // Always run cleanup to disable maintenance mode and release lock.
        self::stageCleanup(false);

        return [
            'success' => empty($errors),
            'message' => empty($errors)
                ? 'Rollback completed. The previous version has been restored.'
                : 'Rollback completed with errors. Manual intervention may be required. Check the update log.',
            'details' => array_merge($details, array_map(fn(string $e): string => '✗ ' . $e, $errors)),
        ];
    }

    /**
     * Force-reset a stuck update session.
     *
     * Disables maintenance mode and deletes the lock file without any
     * restoration.  Use when an update session is stuck and no actual file
     * replacement has occurred.
     */
    public static function forceAbort(): void
    {
        // Disable maintenance mode.
        $flagFile = self::maintenanceFlagFile();
        if (file_exists($flagFile)) {
            unlink($flagFile);
        }
        try {
            LumoraConfig::set('gallery_offline', '0');
        } catch (\Throwable) {}

        self::logUpdate('warning', 'Update session force-aborted by administrator');
        self::releaseLock();
    }

    // ── Database backup / restore ─────────────────────────────────────────────

    /**
     * Dump all Lumora tables (those matching the configured DB prefix) to a SQL file.
     *
     * Uses PDO to generate DROP TABLE IF EXISTS + CREATE TABLE + INSERT statements.
     * Row data is emitted in batches of 100 to limit memory usage for large tables.
     *
     * @return array{success: bool, tables: int, bytes: int, error: string}
     */
    private static function dumpDatabase(string $outputPath): array
    {
        try {
            $prefix = LumoraDB::prefix();
            $pdo    = LumoraDB::pdo();

            $stmt   = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . '%'));
            $tables = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

            if (empty($tables)) {
                return [
                    'success' => false,
                    'tables'  => 0,
                    'bytes'   => 0,
                    'error'   => 'No tables found with prefix \'' . $prefix . '\'.',
                ];
            }

            $lines   = [];
            $lines[] = '-- Lumora Gallery database backup';
            $lines[] = '-- Generated: ' . date('Y-m-d H:i:s') . ' UTC';
            $lines[] = '-- Version: ' . LUMORA_VERSION;
            $lines[] = '-- Tables: ' . count($tables);
            $lines[] = '';
            $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
            $lines[] = '';

            foreach ($tables as $table) {
                // Escape backticks inside the table name (MySQL identifier quoting).
                // Table names come from SHOW TABLES (DB-controlled, not user input),
                // but we escape defensively in case an unusual name ever occurs.
                $safe_table = str_replace('`', '``', $table);

                // CREATE TABLE statement.
                $create = $pdo->query("SHOW CREATE TABLE `{$safe_table}`")->fetch(\PDO::FETCH_ASSOC);
                $lines[] = "DROP TABLE IF EXISTS `{$safe_table}`;";
                $lines[] = $create['Create Table'] . ';';
                $lines[] = '';

                // Data rows in chunks of 100.
                $rows = $pdo->query("SELECT * FROM `{$safe_table}`")->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols   = '`' . implode('`, `', array_keys($rows[0])) . '`';
                    $chunks = array_chunk($rows, 100);
                    foreach ($chunks as $chunk) {
                        $values = [];
                        foreach ($chunk as $row) {
                            $escaped = array_map(
                                fn(mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                                array_values($row)
                            );
                            $values[] = '(' . implode(', ', $escaped) . ')';
                        }
                        $lines[] = "INSERT INTO `{$safe_table}` ({$cols}) VALUES";
                        $lines[] = implode(",\n", $values) . ';';
                    }
                    $lines[] = '';
                }
            }

            $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

            $sql = implode("\n", $lines);

            if (file_put_contents($outputPath, $sql) === false) {
                return ['success' => false, 'tables' => 0, 'bytes' => 0, 'error' => 'Could not write backup file.'];
            }

            return ['success' => true, 'tables' => count($tables), 'bytes' => strlen($sql), 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'tables' => 0, 'bytes' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Restore a database from a SQL dump file created by dumpDatabase().
     *
     * Uses a string-literal-aware statement splitter to avoid truncating
     * INSERT values that contain semicolons.  All statements are executed
     * inside a single transaction; if any statement fails the transaction
     * is rolled back.
     *
     * @return array{success: bool, statements: int, error: string}
     */
    private static function restoreDatabase(string $sqlPath): array
    {
        try {
            $sql = file_get_contents($sqlPath);
            if ($sql === false) {
                return ['success' => false, 'statements' => 0, 'error' => 'Could not read backup file.'];
            }

            $pdo   = LumoraDB::pdo();
            $stmts = self::splitSql($sql);
            $count = 0;

            $pdo->beginTransaction();
            try {
                foreach ($stmts as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                    $pdo->exec($stmt);
                    $count++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return ['success' => true, 'statements' => $count, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'statements' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Split a SQL dump string into individual statements.
     *
     * State-machine walker that respects single-quoted strings, double-quoted
     * strings, and backtick-quoted identifiers so that semicolons inside
     * string literals are never treated as statement delimiters.
     *
     * @return list<string>
     */
    private static function splitSql(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inSingle   = false;
        $inDouble   = false;
        $inBacktick = false;
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $c    = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($inSingle) {
                $current .= $c;
                if ($c === "'" && $prev !== '\\') $inSingle = false;
            } elseif ($inDouble) {
                $current .= $c;
                if ($c === '"' && $prev !== '\\') $inDouble = false;
            } elseif ($inBacktick) {
                $current .= $c;
                if ($c === '`') $inBacktick = false;
            } elseif ($c === "'") {
                $inSingle = true;
                $current .= $c;
            } elseif ($c === '"') {
                $inDouble = true;
                $current .= $c;
            } elseif ($c === '`') {
                $inBacktick = true;
                $current .= $c;
            } elseif ($c === ';') {
                $statements[] = $current;
                $current      = '';
            } else {
                $current .= $c;
            }
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    // ── File utilities ────────────────────────────────────────────────────────

    /**
     * Recursively copy $src into $dst, skipping top-level entries listed in $preserve.
     *
     * $preserve entries are matched against the immediate children of $src root only.
     * All deeper paths are always copied.
     *
     * @param string               $src      Source directory (with trailing separator).
     * @param string               $dst      Destination directory (with trailing separator).
     * @param list<string>         $preserve Top-level entry names to skip entirely.
     * @param array{copied: int, skipped: int, errors: list<string>} $stats  By-reference stat counter.
     */
    private static function copyDirectory(
        string $src,
        string $dst,
        array  $preserve,
        array  &$stats,
        bool   $isRoot = true
    ): void {
        if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
            $stats['errors'][] = "Could not create directory: {$dst}";
            return;
        }

        foreach (new \DirectoryIterator($src) as $item) {
            if ($item->isDot()) continue;

            $name    = $item->getFilename();
            $srcPath = $item->getPathname();
            $dstPath = $dst . $name;

            // Only apply $preserve filtering at the top level of the copy.
            if ($isRoot && in_array($name, $preserve, true)) {
                $stats['skipped']++;
                continue;
            }

            if ($item->isDir()) {
                self::copyDirectory(
                    $srcPath . DIRECTORY_SEPARATOR,
                    $dstPath . DIRECTORY_SEPARATOR,
                    $preserve,
                    $stats,
                    false   // deeper levels are never filtered
                );
            } elseif ($item->isFile()) {
                if (!copy($srcPath, $dstPath)) {
                    $stats['errors'][] = "Could not copy: {$name}";
                } else {
                    $stats['copied']++;
                }
            }
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }

    /**
     * Locate the Lumora application root inside the extracted archive.
     *
     * Searches for version.php at up to three directory levels:
     *   1. Directly in $extractDir               (archive IS the app root)
     *   2. One level deep: extractDir/SomeDir/    (GitHub archive with top-level folder)
     *   3. Two levels deep: extractDir/Repo/App/  (repo with app in a subdirectory)
     *
     * Returns the path with a trailing separator, or null if not found.
     */
    private static function findAppRoot(string $extractDir): ?string
    {
        $d = rtrim($extractDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (file_exists($d . 'version.php')) return $d;

        foreach (new \DirectoryIterator($d) as $l1) {
            if (!$l1->isDir() || $l1->isDot()) continue;
            $p1 = $l1->getPathname() . DIRECTORY_SEPARATOR;
            if (file_exists($p1 . 'version.php')) return $p1;

            foreach (new \DirectoryIterator($p1) as $l2) {
                if (!$l2->isDir() || $l2->isDot()) continue;
                $p2 = $l2->getPathname() . DIRECTORY_SEPARATOR;
                if (file_exists($p2 . 'version.php')) return $p2;
            }
        }

        return null;
    }

    // ── Logging ───────────────────────────────────────────────────────────────

    /**
     * Append a timestamped line to the update log file.
     *
     * @param 'info'|'warning'|'error' $level
     */
    public static function logUpdate(string $level, string $message): void
    {
        self::ensureUpdatesDir();
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . "\n";
        file_put_contents(self::logFile(), $line, FILE_APPEND | LOCK_EX);
        lumora_log($level, 'UpdaterService: ' . $message);
    }

    /**
     * Return the update log as an array of non-empty lines, most-recent-first.
     *
     * @return list<string>
     */
    public static function getUpdateLog(): array
    {
        $path = self::logFile();
        if (!file_exists($path)) return [];
        $content = file_get_contents($path);
        if ($content === false) return [];
        $lines = array_filter(explode("\n", $content));
        return array_reverse(array_values($lines));
    }

    // ── Update history ────────────────────────────────────────────────────────

    /**
     * Prepend a record to the update history stored in the config table.
     * Keeps only the 10 most recent entries.
     *
     * @param string $version   The version that was installed (or attempted).
     * @param bool   $success   Whether the update completed successfully.
     * @param string $message   Summary message for display.
     */
    public static function recordUpdateHistory(string $version, bool $success, string $message): void
    {
        $history = self::getUpdateHistory();
        array_unshift($history, [
            'version'    => $version,
            'success'    => $success,
            'message'    => $message,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $history = array_slice($history, 0, 10);
        try {
            LumoraConfig::set('update_history', json_encode($history, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {}
    }

    /**
     * Return the update history array, newest first.
     *
     * @return list<array{version: string, success: bool, message: string, updated_at: string}>
     */
    public static function getUpdateHistory(): array
    {
        $raw = (string) LumoraConfig::get('update_history', '');
        if ($raw === '') return [];
        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Create the updates working directory if it does not exist.
     * Also writes a .htaccess file to block direct web access on Apache hosts.
     */
    private static function ensureUpdatesDir(): void
    {
        $cacheDir   = LUMORA_ROOT . 'cache';
        $updatesDir = self::updatesDir();

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (!is_dir($updatesDir)) {
            mkdir($updatesDir, 0750, true);
            // Deny direct web access on Apache; non-Apache servers should
            // configure equivalent protection.
            file_put_contents($updatesDir . '.htaccess', "Deny from all\nOptions -Indexes\n");
            file_put_contents($updatesDir . 'index.html', '');
        }
    }

    /**
     * Build a success result array.
     *
     * @param list<string> $details
     * @return array{success: bool, stage: string, message: string, next: string|null, details: list<string>}
     */
    private static function ok(
        string  $stage,
        string  $message,
        ?string $next    = null,
        array   $details = []
    ): array {
        return ['success' => true,  'stage' => $stage, 'message' => $message, 'next' => $next, 'details' => $details];
    }

    /**
     * Build a failure result array.
     *
     * @param list<string> $errors
     * @return array{success: bool, stage: string, message: string, next: string|null, details: list<string>}
     */
    private static function fail(
        string $stage,
        string $message,
        array  $errors = []
    ): array {
        return ['success' => false, 'stage' => $stage, 'message' => $message, 'next' => null, 'details' => $errors];
    }
}

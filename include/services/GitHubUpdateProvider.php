<?php
declare(strict_types=1);
/**
 * Lumora Gallery — GitHub Update Provider
 *
 * Fetches release metadata from the GitHub Releases API and constructs archive
 * download URLs using the standard GitHub archive format.
 *
 * API endpoint:  https://api.github.com/repos/{owner}/{repo}/releases/latest
 * Archive URL:   https://github.com/{owner}/{repo}/archive/refs/tags/v{ver}.zip
 *
 * The repository is configurable via the `update_github_repo` config key
 * (default: intothisshadow/Lumora) so forks and self-hosted mirrors can point
 * to their own release source without code changes.
 *
 * SHA-256 checksum: if the release contains an asset whose name ends with
 * `.sha256` or equals `sha256sums.txt`, its content is fetched and parsed to
 * supply `sha256` in the returned metadata.  When absent, `sha256` is null and
 * `UpdaterService` skips the checksum verification step (logged as a warning).
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class GitHubUpdateProvider extends AbstractUpdateProvider
{
    /** Default repository identifier (owner/repo). */
    private const DEFAULT_REPO = 'intothisshadow/Lumora';

    /** GitHub REST API v3 base URL. */
    private const API_BASE = 'https://api.github.com';

    /** GitHub base URL for source archive downloads. */
    private const ARCHIVE_BASE = 'https://github.com';

    // ── AbstractUpdateProvider interface ──────────────────────────────────────

    public function getName(): string
    {
        return 'GitHub Releases';
    }

    /**
     * Fetch the latest release metadata from the GitHub Releases API.
     *
     * GitHub API response fields mapped to the canonical metadata shape:
     *   tag_name     → latest_version  (leading 'v' stripped)
     *   published_at → release_date    (date portion only, YYYY-MM-DD)
     *   body         → release_notes   (Markdown; trimmed to 2 000 chars)
     *   html_url     → changelog_url   (GitHub release page)
     *
     * `minimum_php` and `minimum_db` are extracted via simple regex from the
     * release body when present (e.g. "Minimum PHP: 8.2" or "minimum_db: 8").
     *
     * @return array{
     *   latest_version: string,
     *   release_date:   string|null,
     *   release_notes:  string|null,
     *   download_url:   string|null,
     *   changelog_url:  string|null,
     *   minimum_php:    string|null,
     *   minimum_db:     int|null,
     *   sha256:         string|null
     * }|null
     */
    public function fetchMetadata(): ?array
    {
        $repo = $this->repo();
        $url  = self::API_BASE . '/repos/' . $repo . '/releases/latest';

        // GitHub requires a meaningful User-Agent to avoid 403 responses.
        $raw = $this->httpGet($url, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]);

        if ($raw === null) return null;

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || empty($data['tag_name'])) return null;

        $tag         = trim((string) $data['tag_name']);
        $version     = ltrim($tag, 'v');
        $releaseDate = null;

        if (!empty($data['published_at'])) {
            // ISO 8601 "2026-07-01T12:00:00Z" — keep only the date part.
            $releaseDate = substr((string) $data['published_at'], 0, 10);
        }

        $notes = isset($data['body']) ? trim((string) $data['body']) : null;
        if ($notes === '') $notes = null;
        // Truncate very long release notes for storage in the config table.
        if ($notes !== null && strlen($notes) > 2000) {
            $notes = substr($notes, 0, 1997) . '…';
        }

        $changelogUrl = isset($data['html_url']) ? trim((string) $data['html_url']) : null;
        $downloadUrl  = $this->buildArchiveUrl($version);

        // Extract minimum PHP / DB from release notes.
        $minPhp = null;
        $minDb  = null;
        if ($notes !== null) {
            if (preg_match('/minimum[_ ]php[:\s]+([0-9]+\.[0-9]+)/i', $notes, $m)) {
                $minPhp = $m[1];
            }
            if (preg_match('/minimum[_ ]db[:\s]+([0-9]+)/i', $notes, $m)) {
                $minDb = (int) $m[1];
            }
        }

        // Search release assets for a SHA-256 checksum file.
        $sha256 = null;
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                $assetName = strtolower((string) ($asset['name'] ?? ''));
                if (str_ends_with($assetName, '.sha256')
                    || $assetName === 'sha256sums.txt'
                    || $assetName === 'checksums.txt') {
                    $assetUrl = $asset['browser_download_url'] ?? null;
                    if ($assetUrl !== null) {
                        $sha256 = $this->parseChecksumAsset((string) $assetUrl, $version);
                    }
                    break;
                }
            }
        }

        return [
            'latest_version' => $version,
            'release_date'   => $releaseDate,
            'release_notes'  => $notes,
            'download_url'   => $downloadUrl,
            'changelog_url'  => $changelogUrl,
            'minimum_php'    => $minPhp,
            'minimum_db'     => $minDb,
            'sha256'         => $sha256,
        ];
    }

    /**
     * Build the GitHub source archive download URL for a specific version.
     *
     * Format: https://github.com/{repo}/archive/refs/tags/v{version}.zip
     *
     * The `v` prefix is always added; any leading `v` in $version is stripped first
     * so both '1.9.0' and 'v1.9.0' produce the same URL.
     */
    public function buildArchiveUrl(string $version): string
    {
        $repo = $this->repo();
        $ver  = ltrim($version, 'v');
        return self::ARCHIVE_BASE . '/' . $repo . '/archive/refs/tags/v' . $ver . '.zip';
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** Return the configured repository identifier (owner/repo). */
    private function repo(): string
    {
        return (string) LumoraConfig::get('update_github_repo', self::DEFAULT_REPO);
    }

    /**
     * Fetch a checksum asset file and extract the SHA-256 hash for the release archive.
     *
     * Handles two formats:
     *   Single-hash:  A 64-character lowercase hex string (possibly with a newline).
     *   Multi-entry:  sha256sum output — "{hash}  {filename}" one entry per line;
     *                 the line containing the archive filename is matched.
     *
     * Returns the lowercase hex hash string, or null when it cannot be found.
     */
    private function parseChecksumAsset(string $url, string $version): ?string
    {
        $raw = $this->httpGet($url);
        if ($raw === null) return null;

        $raw = trim($raw);

        // Single-hash file: exactly a 64-char hex string.
        if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            return strtolower($raw);
        }

        // Multi-entry file: match the line containing the archive name.
        $archiveFragment = 'lumora-v' . ltrim($version, 'v');
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (str_contains(strtolower($line), strtolower($archiveFragment))
                && preg_match('/^([a-f0-9]{64})\s/i', $line, $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }
}

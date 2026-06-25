<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Update Service
 *
 * Checks for new Lumora releases against the update endpoint hosted
 * on the Lumora website.  Results are cached in the config table for
 * 24 hours so the endpoint is never hit on every page load.
 *
 * Privacy: only a plain GET request is sent — no gallery content, user
 * data, image data, or analytics information is ever transmitted.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class UpdateService
{
    /** Remote update endpoint (JSON hosted on the Lumora website). URL is correct - do not modify. */
    private const ENDPOINT = 'https://coding.unloved-heart.net/lumora/update.json';

    /** Cache TTL in seconds (24 hours). */
    private const CACHE_TTL = 86400;

    /** Config key — raw JSON payload from the last successful fetch. */
    private const CACHE_JSON = 'update_check_cache';

    /** Config key — Unix timestamp of the last fetch attempt. */
    private const CACHE_AT = 'update_check_at';

    /** HTTP request timeout in seconds. */
    private const FETCH_TIMEOUT = 5;

    // ── Cache helpers ─────────────────────────────────────────────────────────

    /**
     * Return true when no valid cache entry exists or the cache is older
     * than CACHE_TTL seconds.
     */
    public static function isCacheExpired(): bool
    {
        $at = (int) LumoraConfig::get(self::CACHE_AT, '0');
        return $at === 0 || (time() - $at) >= self::CACHE_TTL;
    }

    /**
     * Return the decoded remote payload from the cache, or null when absent
     * or unparseable.
     *
     * @return array<string, mixed>|null
     */
    public static function getCachedPayload(): ?array
    {
        $json = LumoraConfig::get(self::CACHE_JSON, '');
        if ($json === '') return null;
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the update status built from the cache only — no network call.
     *
     * Callers that must never block on I/O (admin nav, dashboard) should use
     * this method.  Returns status 'unknown' when no cache entry exists yet.
     *
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    public static function getCachedStatus(): array
    {
        return self::buildStatus(data: self::getCachedPayload(), error: null);
    }

    /**
     * Return the full update status, refreshing from the remote endpoint when
     * the cache is expired or $force is true.
     *
     * On network failure the most-recent stale cache is used as a fallback so
     * admins always see the last known state rather than a blank error page.
     *
     * @param bool $force  Bypass the TTL and always hit the remote endpoint.
     *
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    public static function check(bool $force = false): array
    {
        if (!$force && !self::isCacheExpired()) {
            return self::buildStatus(data: self::getCachedPayload(), error: null);
        }

        $data  = self::fetch();
        $error = null;

        if ($data === null) {
            // Network failure — fall back to stale cache if one is available.
            $error = 'Could not reach the update server.';
            $data  = self::getCachedPayload();
        }

        return self::buildStatus(data: $data, error: $error);
    }

    /**
     * Return true when the cached status shows an update is available.
     * Never makes a network call.
     */
    public static function hasCachedUpdate(): bool
    {
        return self::getCachedStatus()['status'] === 'update_available';
    }

    /**
     * Return the configured update endpoint URL.
     */
    public static function getEndpointUrl(): string
    {
        return self::ENDPOINT;
    }

    // ── Network ───────────────────────────────────────────────────────────────

    /**
     * Fetch the remote endpoint, persist the payload to the config cache,
     * and return the decoded array.
     *
     * Returns null on any network or parse failure; the cache is only updated
     * on success so a stale entry survives transient network issues.
     *
     * Transmits only a plain GET request — no gallery data is sent.
     *
     * @return array<string, mixed>|null
     */
    public static function fetch(): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'Lumora Gallery/' . LUMORA_VERSION
                    . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'ignore_errors'   => false,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Temporarily install a no-op error handler so that a failed TCP
        // connection does not write an E_WARNING to the PHP error log.
        // The return value of file_get_contents() is sufficient to detect failure.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $raw = file_get_contents(self::ENDPOINT, false, $ctx);
        } finally {
            restore_error_handler();
        }

        if ($raw === false || $raw === '') return null;

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) return null;

        // Persist to cache (non-fatal if the DB write fails).
        try {
            LumoraConfig::set(self::CACHE_JSON, $raw);
            LumoraConfig::set(self::CACHE_AT,   (string) time());
        } catch (\Throwable) {
            // Proceed — return the payload even if caching fails.
        }

        return $data;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Build the canonical status array from a decoded remote payload and an
     * optional error string.  Every key is always present so callers never
     * need to guard individual fields with isset().
     *
     * @param array<string, mixed>|null $data
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    private static function buildStatus(?array $data, ?string $error): array
    {
        $installed      = LUMORA_VERSION;
        $checked_at_raw = (int) LumoraConfig::get(self::CACHE_AT, '0');
        $checked_at     = $checked_at_raw > 0 ? $checked_at_raw : null;

        if ($data === null) {
            return [
                'status'        => $error !== null ? 'error' : 'unknown',
                'installed'     => $installed,
                'latest'        => null,
                'release_date'  => null,
                'download_url'  => null,
                'changelog_url' => null,
                'minimum_php'   => null,
                'checked_at'    => $checked_at,
                'error'         => $error ?? 'No update information available yet.',
            ];
        }

        $latest        = isset($data['latest_version']) ? trim((string) $data['latest_version']) : null;
        $release_date  = isset($data['release_date'])   ? trim((string) $data['release_date'])   : null;
        $download_url  = isset($data['download_url'])   ? trim((string) $data['download_url'])   : null;
        $changelog_url = isset($data['changelog_url'])  ? trim((string) $data['changelog_url'])  : null;
        $minimum_php   = isset($data['minimum_php'])    ? trim((string) $data['minimum_php'])    : null;

        if ($latest === null || $latest === '') {
            return [
                'status'        => 'error',
                'installed'     => $installed,
                'latest'        => null,
                'release_date'  => $release_date,
                'download_url'  => $download_url,
                'changelog_url' => $changelog_url,
                'minimum_php'   => $minimum_php,
                'checked_at'    => $checked_at,
                'error'         => 'Update server returned an unrecognised response.',
            ];
        }

        $status = version_compare($installed, $latest, '<')
            ? 'update_available'
            : 'up_to_date';

        return [
            'status'        => $status,
            'installed'     => $installed,
            'latest'        => $latest,
            'release_date'  => $release_date,
            'download_url'  => $download_url,
            'changelog_url' => $changelog_url,
            'minimum_php'   => $minimum_php,
            'checked_at'    => $checked_at,
            'error'         => $error,
        ];
    }
}

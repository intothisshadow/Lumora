<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Abstract Update Provider
 *
 * Defines the interface all update providers must implement.
 * Separates release discovery, metadata retrieval, and archive URL construction
 * from the core update workflow so alternative release sources can be added
 * in future (self-hosted servers, alternative Git repositories, private feeds).
 *
 * The `update_provider_type` config key selects the active implementation.
 * `createFromConfig()` is the sole factory entry-point; every new provider
 * must be registered in its match arm.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

abstract class AbstractUpdateProvider
{
    /** HTTP request timeout in seconds for all provider network calls. */
    protected const FETCH_TIMEOUT = 10;

    // ── Abstract interface ────────────────────────────────────────────────────

    /**
     * Fetch metadata for the latest release.
     *
     * Returns null on any network or parse failure.  All returned keys are
     * optional except `latest_version`.
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
    abstract public function fetchMetadata(): ?array;

    /**
     * Build the archive download URL for a specific version string.
     *
     * @param string $version Semver string with or without a leading 'v'
     *                        (e.g. '1.9.0' or 'v1.9.0').
     */
    abstract public function buildArchiveUrl(string $version): string;

    /**
     * Return a human-readable provider name for display in the admin UI.
     */
    abstract public function getName(): string;

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Instantiate the provider configured in {PREFIX}config.
     *
     * Falls back to GitHubUpdateProvider when the stored type is unrecognised.
     * Adding a new provider requires:
     *   1. A concrete class extending AbstractUpdateProvider in include/services/.
     *   2. A new match arm below.
     *   3. A new 'update_provider_type' config option in Admin → Configuration.
     */
    public static function createFromConfig(): static
    {
        $type = (string) LumoraConfig::get('update_provider_type', 'github');
        return match ($type) {
            'github' => new GitHubUpdateProvider(),
            default  => new GitHubUpdateProvider(),
        };
    }

    // ── Shared HTTP helper ────────────────────────────────────────────────────

    /**
     * Perform an HTTP GET request and return the response body, or null on failure.
     *
     * Suppresses the E_WARNING that file_get_contents() emits on TCP failure
     * without using the @ operator.
     *
     * @param string            $url        URL to fetch.
     * @param array<string,string> $extraHeaders Additional request headers.
     */
    protected function httpGet(string $url, array $extraHeaders = []): ?string
    {
        $userAgent = 'Lumora Gallery/' . LUMORA_VERSION
            . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $headers = array_merge(['Accept: application/json'], $extraHeaders);

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => static::FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => $userAgent,
                'ignore_errors'   => false,
                'header'          => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static fn(): bool => true);
        try {
            $raw = file_get_contents($url, false, $ctx);
        } finally {
            restore_error_handler();
        }

        return ($raw === false || $raw === '') ? null : $raw;
    }
}

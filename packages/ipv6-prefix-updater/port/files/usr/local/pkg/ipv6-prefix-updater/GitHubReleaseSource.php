<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use JsonException;
use RuntimeException;

require_once(__DIR__ . '/CurlHttpClient.php');
require_once(__DIR__ . '/HttpClient.php');
require_once(__DIR__ . '/ReleasePinUnavailableException.php');
require_once(__DIR__ . '/ReleaseSource.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Reads package releases from the GitHub REST API.
 *
 * This is the only class that knows GitHub's URLs and release JSON. It turns each
 * response into the project-owned release array that update checks consume.
 *
 * @since 2.1.5
 */
final class GitHubReleaseSource implements ReleaseSource
{
    /** GitHub latest-release API endpoint. @since 2.1.5 */
    public const RELEASES_API = 'https://api.github.com/repos/mrjackyliang/pfsense-ipv6-prefix-updater/releases/latest';

    /**
     * GitHub release-by-tag API prefix used for installed-version recovery pins.
     *
     * Appending the normalized installed tag resolves immutable release metadata
     * without consulting GitHub's moving latest-release endpoint.
     *
     * @since 2.1.5
     */
    public const RELEASE_TAG_API_PREFIX = 'https://api.github.com/repos/mrjackyliang/pfsense-ipv6-prefix-updater/releases/tags/';

    /** Allowed prefix for release asset URLs returned by GitHub. @since 2.1.5 */
    public const DOWNLOAD_PREFIX = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/download/';

    /** GitHub repository release page prefix accepted for display. @since 2.1.5 */
    public const RELEASE_PAGE_PREFIX = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/';

    /** User agent sent with every GitHub request. @since 2.1.5 */
    private const USER_AGENT = 'pfSense-pkg-Ipv6PrefixUpdater';

    /**
     * Creates a GitHub release source with production or test-only CLI endpoints.
     *
     * The WebGUI never constructs endpoint overrides. Only the CLI reads the
     * documented test environment variables and passes their values here.
     *
     * @param string     $releasesApi         - Latest-release API endpoint.
     * @param string     $downloadPrefix      - Allowed release asset URL prefix.
     * @param string     $releaseTagApiPrefix - Release-by-tag API prefix.
     * @param HttpClient $httpClient          - HTTP transport.
     *
     * @since 2.1.5
     */
    public function __construct(
        private readonly string $releasesApi = self::RELEASES_API,
        private readonly string $downloadPrefix = self::DOWNLOAD_PREFIX,
        private readonly string $releaseTagApiPrefix = self::RELEASE_TAG_API_PREFIX,
        private readonly HttpClient $httpClient = new CurlHttpClient(self::USER_AGENT),
    ) {
    }

    /**
     * Returns the URL prefix every trusted release asset starts with.
     *
     * @return string
     *
     * @since 2.1.5
     */
    public function assetUrlPrefix(): string
    {
        return $this->downloadPrefix;
    }

    /**
     * Fetches and parses the latest GitHub release.
     *
     * @return array
     *
     * @throws RuntimeException When GitHub rejects or rate-limits the request.
     *
     * @since 2.1.5
     */
    public function fetchLatestRelease(): array
    {
        return $this->requestRelease($this->releasesApi);
    }

    /**
     * Fetches and parses the GitHub release tagged with one normalized version.
     *
     * @param string $version - Normalized release version.
     *
     * @return array
     *
     * @throws ReleasePinUnavailableException When the requested release is absent.
     * @throws RuntimeException               When GitHub rejects the request transiently.
     *
     * @since 2.1.5
     */
    public function fetchRelease(string $version): array
    {
        $releaseTag = 'v' . $version;

        return $this->requestRelease($this->releaseTagApiPrefix . rawurlencode($releaseTag), true);
    }

    /**
     * Downloads one release asset with the transport's proxy and TLS settings.
     *
     * @param string $url                      - Validated asset URL.
     * @param string $path                     - Private destination path.
     * @param int    $byteLimit                - Maximum accepted download size.
     * @param bool   $notFoundMeansUnavailable - Whether HTTP 404 is definitive.
     *
     * @return void
     *
     * @throws ReleasePinUnavailableException When a required asset is absent.
     * @throws RuntimeException               When transport or HTTP validation fails.
     *
     * @since 2.1.5
     */
    public function downloadAsset(
        string $url,
        string $path,
        int $byteLimit,
        bool $notFoundMeansUnavailable = false,
    ): void {
        $fileHandle = fopen($path, 'wb');

        if ($fileHandle === false || chmod($path, 0600) === false) {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }

            throw new RuntimeException(Translator::translate('update_download_failed'));
        }

        try {
            $response = $this->httpClient->download(
                $url,
                $fileHandle,
                [
                    'Accept: application/octet-stream',
                ],
                $byteLimit,
            );
        } finally {
            fclose($fileHandle);
        }

        if ($response['initialized'] === false) {
            throw new RuntimeException(Translator::translate('update_download_failed'));
        }

        $statusCode = $response['status'];

        if ($response['too_large']) {
            @unlink($path);

            throw new RuntimeException(Translator::translate('update_download_too_large'));
        }

        if ($statusCode === 404 && $notFoundMeansUnavailable) {
            @unlink($path);

            throw new ReleasePinUnavailableException(
                Translator::translate('update_download_failed_detail', [(string) $statusCode]),
            );
        }

        if ($response['completed'] === false || $statusCode < 200 || $statusCode >= 300) {
            @unlink($path);
            $detail = $response['error'] !== '' ? $response['error'] : (string) $statusCode;

            throw new RuntimeException(Translator::translate('update_download_failed_detail', [$detail]));
        }
    }

    /**
     * Validates a release page before the About tab renders it as a link.
     *
     * Only this repository's HTTPS release pages are accepted from persistent state.
     *
     * @param string $url - Release page URL.
     *
     * @return bool
     *
     * @since 2.1.5
     */
    public static function isAllowedReleaseUrl(string $url): bool
    {
        return str_starts_with($url, self::RELEASE_PAGE_PREFIX);
    }

    /**
     * Parses and validates one GitHub release response.
     *
     * The normalized result contains only fields needed by checks and installation;
     * malformed external data fails closed before it reaches persistent state.
     *
     * @param string $json - Release JSON.
     *
     * @return array
     *
     * @throws RuntimeException When required release fields are missing or invalid.
     *
     * @since 2.1.5
     */
    public static function parseReleaseJson(string $json): array
    {
        try {
            $releaseData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(Translator::translate('update_release_json_invalid'), 0, $jsonException);
        }

        if (!is_array($releaseData)) {
            throw new RuntimeException(Translator::translate('update_release_json_invalid'));
        }

        $tag = $releaseData['tag_name'] ?? null;
        $releaseUrl = $releaseData['html_url'] ?? null;
        $publishedAt = $releaseData['published_at'] ?? null;
        $releaseAssets = $releaseData['assets'] ?? null;

        if (
            !is_string($tag)
            || $tag === ''
            || !is_string($releaseUrl)
            || self::isAllowedReleaseUrl($releaseUrl) === false
            || !is_string($publishedAt)
            || $publishedAt === ''
            || !is_array($releaseAssets)
        ) {
            throw new RuntimeException(Translator::translate('update_release_json_invalid'));
        }

        $version = self::normalizeVersion($tag);

        if (preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException(Translator::translate('update_release_version_invalid'));
        }

        $assets = [];

        foreach ($releaseAssets as $releaseAsset) {
            if (!is_array($releaseAsset)) {
                continue;
            }

            $releaseAssetName = $releaseAsset['name'] ?? null;
            $releaseAssetUrl = $releaseAsset['browser_download_url'] ?? null;
            $releaseAssetDigest = $releaseAsset['digest'] ?? null;

            if (!is_string($releaseAssetName) || !is_string($releaseAssetUrl)) {
                continue;
            }

            $assets[] = [
                'name' => $releaseAssetName,
                'url' => $releaseAssetUrl,
                'digest' => is_string($releaseAssetDigest) ? $releaseAssetDigest : null,
            ];
        }

        return [
            'tag' => $tag,
            'version' => $version,
            'release_url' => $releaseUrl,
            'published_at' => $publishedAt,
            'assets' => $assets,
        ];
    }

    /**
     * Fetches and parses one GitHub release API endpoint.
     *
     * Latest-version checks and installed-version recovery pins share identical
     * transport, rate-limit, status, and response validation.
     *
     * @param string $releaseApi               - GitHub release API endpoint.
     * @param bool   $notFoundMeansUnavailable - Whether HTTP 404 is definitive.
     *
     * @return array
     *
     * @throws ReleasePinUnavailableException When the requested release is absent.
     * @throws RuntimeException               When GitHub rejects the request transiently.
     *
     * @since 2.1.5
     * @private
     */
    private function requestRelease(
        string $releaseApi,
        bool $notFoundMeansUnavailable = false,
    ): array {
        $response = $this->httpClient->get($releaseApi, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]);

        if ($response['initialized'] === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_transport'));
        }

        $statusCode = $response['status'];

        if ($statusCode === 404 && $notFoundMeansUnavailable) {
            throw new ReleasePinUnavailableException(
                Translator::translate('update_http_failed', [$statusCode]),
            );
        }

        if ($response['body'] === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_detail', [$response['error']]));
        }

        if ($statusCode === 403 || $statusCode === 429) {
            throw new RuntimeException(Translator::translate('update_rate_limited'));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(Translator::translate('update_http_failed', [$statusCode]));
        }

        return self::parseReleaseJson($response['body']);
    }

    /**
     * Normalizes a Git tag for version comparison.
     *
     * @param string $version - Package version or tag.
     *
     * @return string
     *
     * @since 2.1.5
     * @private
     */
    private static function normalizeVersion(string $version): string
    {
        return preg_replace('/^[vV]/', '', trim($version), 1) ?? trim($version);
    }
}

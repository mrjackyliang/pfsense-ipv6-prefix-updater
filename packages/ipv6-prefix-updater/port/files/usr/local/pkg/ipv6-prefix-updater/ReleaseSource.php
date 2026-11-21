<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * Defines where package releases come from.
 *
 * Update checks, self-updates, and restore recovery pins read releases only through
 * this interface. Each release is a project-owned array, so the release host's API
 * shape stays inside its adapter.
 *
 * Release arrays hold "tag", "version", "release_url", "published_at", and
 * "assets", a list of name, url, and digest entries.
 *
 * @since 2.1.5
 */
interface ReleaseSource
{
    /**
     * Returns the URL prefix every trusted release asset starts with.
     *
     * Asset selection rejects any package, checksum, or signature outside it.
     *
     * @return string
     *
     * @since 2.1.5
     */
    public function assetUrlPrefix(): string;

    /**
     * Returns the newest published release.
     *
     * @return array{tag: string, version: string, release_url: string, published_at: string, assets: list<array{name: string, url: string, digest: string|null}>}
     *
     * @throws \RuntimeException When the source rejects or rate-limits the request.
     *
     * @since 2.1.5
     */
    public function fetchLatestRelease(): array;

    /**
     * Returns one published release by its normalized version, such as "2.1.0".
     *
     * A release that doesn't exist throws ReleasePinUnavailableException, which
     * restore recovery treats as definitive.
     *
     * @param string $version - Normalized release version.
     *
     * @return array{tag: string, version: string, release_url: string, published_at: string, assets: list<array{name: string, url: string, digest: string|null}>}
     *
     * @throws ReleasePinUnavailableException When the release does not exist.
     * @throws \RuntimeException              When the source fails transiently.
     *
     * @since 2.1.5
     */
    public function fetchRelease(string $version): array;

    /**
     * Downloads one release asset into a private file.
     *
     * The file is removed again whenever the download fails.
     *
     * @param string $url                      - Validated asset URL.
     * @param string $path                     - Private destination path.
     * @param int    $byteLimit                - Largest accepted download in bytes.
     * @param bool   $notFoundMeansUnavailable - Whether a missing asset is definitive.
     *
     * @return void
     *
     * @throws ReleasePinUnavailableException When a required asset is absent.
     * @throws \RuntimeException              When the transfer fails.
     *
     * @since 2.1.5
     */
    public function downloadAsset(
        string $url,
        string $path,
        int $byteLimit,
        bool $notFoundMeansUnavailable = false,
    ): void;
}

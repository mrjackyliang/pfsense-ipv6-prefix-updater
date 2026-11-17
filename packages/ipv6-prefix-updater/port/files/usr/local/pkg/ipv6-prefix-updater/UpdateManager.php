<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use JsonException;
use RuntimeException;
use Throwable;

require_once(__DIR__ . '/PackagePaths.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Checks GitHub releases and installs checksum-verified package updates.
 *
 * Scheduled checks, notices, and the About tab share this boundary so release
 * parsing and every verification step remain independently testable.
 *
 * @since 2.1.0
 */
final class UpdateManager
{
    /** GitHub latest-release API endpoint. @since 2.1.0 */
    public const RELEASES_API = 'https://api.github.com/repos/mrjackyliang/pfsense-ipv6-prefix-updater/releases/latest';

    /** Allowed prefix for release asset URLs returned by GitHub. @since 2.1.0 */
    public const DOWNLOAD_PREFIX = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/download/';

    /** Stable pfSense notice identifier for available releases. @since 2.1.0 */
    public const NOTICE_ID = 'ipv6-prefix-updater-update-available';

    /** Package name recorded in the FreeBSD package manifest. @since 2.1.0 */
    public const PACKAGE_NAME = 'pfSense-pkg-Ipv6PrefixUpdater';

    /** Package data directory containing the fallback version metadata. @since 2.1.0 */
    public const PACKAGE_DATA_DIRECTORY = '/usr/local/share/pfSense-pkg-Ipv6PrefixUpdater';

    /** Installed OpenSSH public keys trusted for release updates. @since 2.1.0 */
    public const SIGNING_KEYS_FILE = self::PACKAGE_DATA_DIRECTORY . '/release-signing.pub';

    /** Fixed OpenSSH tool used for release signature verification. @since 2.1.0 */
    public const SSH_KEYGEN_COMMAND = '/usr/bin/ssh-keygen';

    /** About page linked by the pfSense bell notice. @since 2.1.0 */
    public const ABOUT_PAGE = '/ipv6-prefix-updater-about.php';

    /** GitHub repository release page prefix accepted for display. @since 2.1.0 */
    public const RELEASE_PAGE_PREFIX = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/';

    /** User agent sent with every GitHub request. @since 2.1.0 */
    private const USER_AGENT = 'pfSense-pkg-Ipv6PrefixUpdater';

    /** Largest accepted package release asset in bytes. @since 2.1.0 */
    private const PACKAGE_DOWNLOAD_LIMIT = 64 * 1024 * 1024;

    /** Largest accepted checksum or signature release asset in bytes. @since 2.1.0 */
    private const AUXILIARY_DOWNLOAD_LIMIT = 64 * 1024;

    /** Fixed package executable used for metadata checks and installation. @since 2.1.0 */
    private const PKG_COMMAND = '/usr/local/sbin/pkg';

    /**
     * Creates an update coordinator with production or test-only CLI endpoints.
     *
     * The WebGUI never constructs endpoint overrides. Only the CLI reads the
     * documented test environment variables and passes their values here.
     *
     * @param string $installedVersion - Installed package version.
     * @param string $releasesApi      - Latest-release API endpoint.
     * @param string $downloadPrefix   - Allowed release asset URL prefix.
     * @param string $updatesFile      - Persistent update metadata path.
     * @param string $statusFile       - Background update status path.
     * @param string $signingKeysFile  - Test-only release signing key path.
     * @param string $sshKeygenCommand - Test-only OpenSSH executable path.
     *
     * @since 2.1.0
     */
    public function __construct(
        private readonly string $installedVersion,
        private readonly string $releasesApi = self::RELEASES_API,
        private readonly string $downloadPrefix = self::DOWNLOAD_PREFIX,
        private readonly string $updatesFile = PackagePaths::UPDATES_FILE,
        private readonly string $statusFile = PackagePaths::UPDATE_STATUS_FILE,
        private readonly string $signingKeysFile = self::SIGNING_KEYS_FILE,
        private readonly string $sshKeygenCommand = self::SSH_KEYGEN_COMMAND,
    ) {
    }

    /**
     * Confirms the pfSense APIs needed by update operations are available.
     *
     * Fixed system includes provide configuration, proxy, notice, and logging
     * functions without loading the prefix-mutation adapter.
     *
     * @return void
     *
     * @throws RuntimeException When the runtime is not a supported pfSense host.
     *
     * @since 2.1.0
     */
    public static function assertEnvironment(): void
    {
        if (PHP_OS_FAMILY !== 'BSD' || file_exists('/etc/version') === false) {
            throw new RuntimeException(Translator::translate('environment_unsupported'));
        }

        $requiredFiles = [
            '/etc/inc/config.inc',
            '/etc/inc/notices.inc',
            '/etc/inc/pfsense-utils.inc',
        ];

        foreach ($requiredFiles as $requiredFile) {
            if (is_readable($requiredFile) === false) {
                throw new RuntimeException(Translator::translate('dependency_unavailable', [$requiredFile]));
            }

            require_once($requiredFile);
        }

        $requiredFunctions = [
            'close_notice',
            'config_get_path',
            'file_notice',
            'log_error',
            'set_curlproxy',
        ];

        foreach ($requiredFunctions as $requiredFunction) {
            if (function_exists($requiredFunction) === false) {
                throw new RuntimeException(Translator::translate('dependency_unavailable', [$requiredFunction]));
            }
        }

        if (function_exists('curl_init') === false) {
            throw new RuntimeException(Translator::translate('update_curl_unavailable'));
        }
    }

    /**
     * Checks the latest release, saves metadata, and synchronizes the notice.
     *
     * A failed request preserves the last valid release fields while recording a
     * localized error and attempt timestamp for the About tab.
     *
     * @param bool $remoteNotifications - Whether pfSense may notify remote channels.
     *
     * @return array
     *
     * @throws Throwable When GitHub or local state cannot be read or written.
     *
     * @since 2.1.0
     */
    public function check(bool $remoteNotifications): array
    {
        $this->clearStatus();

        $state = self::readUpdatesState($this->updatesFile);
        $checkedAt = gmdate(DATE_ATOM);

        try {
            $release = $this->fetchLatestRelease();
            $state = [
                'checked_at' => $checkedAt,
                'latest_version' => $release['version'],
                'tag' => $release['tag'],
                'release_url' => $release['release_url'],
                'published_at' => $release['published_at'],
                'notified_version' => $state['notified_version'],
                'last_error' => null,
            ];
            $state = self::synchronizeNotice(
                $state,
                $this->installedVersion,
                $release['version'],
                $remoteNotifications,
            );

            self::writeJsonAtomically($this->updatesFile, $state);

            return $state;
        } catch (Throwable $throwable) {
            $state['checked_at'] = $checkedAt;
            $state['last_error'] = $throwable->getMessage();

            self::writeJsonAtomically($this->updatesFile, $state);
            $this->logFailure($throwable);

            throw $throwable;
        }
    }

    /**
     * Maps one update frequency to fixed cron fields.
     *
     * The random components are generated once and reused whenever an administrator
     * changes between daily, weekly, and monthly checks.
     *
     * @param string $frequency - Daily, weekly, monthly, or never.
     * @param array  $schedule  - Stable random schedule values.
     *
     * @return array{string, string, string, string, string}|null
     *
     * @since 2.1.0
     */
    public static function cronFields(string $frequency, array $schedule): ?array
    {
        $minute = (string) $schedule['minute'];
        $hour = (string) $schedule['hour'];

        return match ($frequency) {
            'daily' => [$minute, $hour, '*', '*', '*'],
            'weekly' => [$minute, $hour, '*', '*', (string) $schedule['weekday']],
            'monthly' => [$minute, $hour, (string) $schedule['monthday'], '*', '*'],
            'never' => null,
            default => [$minute, $hour, '*', '*', (string) $schedule['weekday']],
        };
    }

    /**
     * Returns the installed version using the About tab's two metadata sources.
     *
     * The pfSense installed-package record is authoritative, with the shipped
     * "info.xml" used while that record is unavailable during package lifecycle work.
     *
     * @return string|null
     *
     * @since 2.1.0
     */
    public static function installedVersion(): ?string
    {
        if (function_exists('config_get_path')) {
            $installedPackages = config_get_path('installedpackages/package', []);

            if (is_array($installedPackages)) {
                foreach ($installedPackages as $installedPackage) {
                    if (!is_array($installedPackage) || ($installedPackage['name'] ?? null) !== 'Ipv6PrefixUpdater') {
                        continue;
                    }

                    $installedPackageVersion = $installedPackage['version'] ?? null;

                    if (is_string($installedPackageVersion) && $installedPackageVersion !== '') {
                        return $installedPackageVersion;
                    }
                }
            }
        }

        $packageInfoPath = self::PACKAGE_DATA_DIRECTORY . '/info.xml';

        if (is_readable($packageInfoPath) === false) {
            return null;
        }

        if (function_exists('parse_xml_config_pkg') === false) {
            $xmlParserPath = '/etc/inc/xmlparse.inc';

            if (is_readable($xmlParserPath) === false) {
                return null;
            }

            require_once($xmlParserPath);
        }

        $packageInfo = parse_xml_config_pkg($packageInfoPath, 'pfsensepkgs');
        $packageInfoPackage = is_array($packageInfo) ? ($packageInfo['package'] ?? null) : null;

        if (!is_array($packageInfoPackage)) {
            return null;
        }

        $packageInfoVersion = $packageInfoPackage['version'] ?? null;

        if (!is_string($packageInfoVersion)) {
            $packageInfoPackageFirst = $packageInfoPackage[0] ?? null;
            $packageInfoVersion = is_array($packageInfoPackageFirst)
                ? ($packageInfoPackageFirst['version'] ?? null)
                : null;
        }

        return (is_string($packageInfoVersion) && $packageInfoVersion !== '')
            ? $packageInfoVersion
            : null;
    }

    /**
     * Validates a release asset URL against the configured repository prefix.
     *
     * Tests may pass a VM endpoint prefix, while production accepts only the fixed
     * GitHub repository download path.
     *
     * @param string $url            - Asset URL.
     * @param string $downloadPrefix - Allowed prefix.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function isAllowedAssetUrl(string $url, string $downloadPrefix = self::DOWNLOAD_PREFIX): bool
    {
        return $downloadPrefix !== '' && str_starts_with($url, $downloadPrefix);
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
     * @since 2.1.0
     */
    public static function isAllowedReleaseUrl(string $url): bool
    {
        return str_starts_with($url, self::RELEASE_PAGE_PREFIX);
    }

    /**
     * Compares installed and remote versions after removing one leading "v".
     *
     * GitHub tags use a leading letter while the package manifest stores the semantic
     * version alone, so both inputs are normalized before comparison.
     *
     * @param string $installedVersion - Installed version.
     * @param string $latestVersion    - Latest release version or tag.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function isNewerVersion(string $installedVersion, string $latestVersion): bool
    {
        $normalizedInstalledVersion = self::normalizeVersion($installedVersion);
        $normalizedLatestVersion = self::normalizeVersion($latestVersion);

        return version_compare($normalizedLatestVersion, $normalizedInstalledVersion, '>');
    }

    /**
     * Parses and validates GitHub's latest-release response.
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
     * @since 2.1.0
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
     * Writes a queued state before the WebGUI starts the detached process.
     *
     * This closes the small launch race so the About tab begins polling immediately,
     * even before the background CLI has loaded its runtime classes.
     *
     * @param string|null $version - Version expected from the last check.
     * @param string      $path    - Status file path.
     *
     * @return void
     *
     * @since 2.1.0
     */
    public static function queueSelfUpdate(
        ?string $version,
        string $path = PackagePaths::UPDATE_STATUS_FILE,
    ): void {
        $timestamp = gmdate(DATE_ATOM);

        self::writeJsonAtomically($path, [
            'state' => 'running',
            'message' => Translator::translate('update_status_queued'),
            'version' => $version,
            'started_at' => $timestamp,
            'updated_at' => $timestamp,
            'finished_at' => null,
        ]);
    }

    /**
     * Replaces a queued state when the detached CLI cannot begin the update.
     *
     * Environment and lock failures happen before selfUpdate() can write its normal
     * progress states, so the WebGUI needs this terminal state to stop polling.
     *
     * @param string $message - Localized full-sentence failure.
     * @param string $path    - Status file path.
     *
     * @return void
     *
     * @since 2.1.0
     */
    public static function failQueuedSelfUpdate(
        string $message,
        string $path = PackagePaths::UPDATE_STATUS_FILE,
    ): void {
        $timestamp = gmdate(DATE_ATOM);
        $queuedState = null;

        try {
            $queuedState = self::readStatusState($path);
        } catch (Throwable) {
            // A fresh failure state safely replaces malformed queued state.
        }

        self::writeJsonAtomically($path, [
            'state' => 'failed',
            'message' => $message,
            'version' => is_array($queuedState) ? ($queuedState['version'] ?? null) : null,
            'started_at' => is_array($queuedState)
                ? ($queuedState['started_at'] ?? $timestamp)
                : $timestamp,
            'updated_at' => $timestamp,
            'finished_at' => $timestamp,
        ]);
    }

    /**
     * Reads background update status for the About tab and polling request.
     *
     * @param string $path - Status file path.
     *
     * @return array|null
     *
     * @throws RuntimeException When the status file is malformed.
     *
     * @since 2.1.0
     */
    public static function readStatusState(string $path = PackagePaths::UPDATE_STATUS_FILE): ?array
    {
        if (is_readable($path) === false) {
            return null;
        }

        return self::readJsonObject($path, Translator::translate('update_status_unreadable'));
    }

    /**
     * Reads normalized release-check state for CLI and WebGUI callers.
     *
     * @param string $path - Updates file path.
     *
     * @return array
     *
     * @throws RuntimeException When the updates file is malformed.
     *
     * @since 2.1.0
     */
    public static function readUpdatesState(string $path = PackagePaths::UPDATES_FILE): array
    {
        $state = [
            'checked_at' => null,
            'latest_version' => null,
            'tag' => null,
            'release_url' => null,
            'published_at' => null,
            'notified_version' => null,
            'last_error' => null,
        ];

        if (is_readable($path) === false) {
            return $state;
        }

        $storedState = self::readJsonObject($path, Translator::translate('update_state_unreadable'));

        foreach (array_keys($state) as $key) {
            $storedValue = $storedState[$key] ?? null;
            $state[$key] = is_string($storedValue) && $storedValue !== '' ? $storedValue : null;
        }

        return $state;
    }

    /**
     * Reads the installed OpenSSH Ed25519 release signing keys.
     *
     * A missing or comment-only file keeps unsigned updates compatible. Any
     * malformed content in an existing file fails closed before an asset download.
     *
     * @param string $path - Release signing key file path.
     *
     * @return list<string>
     *
     * @throws RuntimeException When an existing key file is unreadable or malformed.
     *
     * @since 2.1.0
     */
    public static function readSigningKeys(string $path): array
    {
        if (file_exists($path) === false) {
            return [];
        }

        if (is_readable($path) === false) {
            throw new RuntimeException(Translator::translate('update_signing_keys_invalid'));
        }

        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException(Translator::translate('update_signing_keys_invalid'));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim(str_replace("\r", '', $line));

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (
                preg_match(
                    '/^ssh-ed25519\s+(AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+\/]{43})(?:\s.*)?$/',
                    $line,
                    $matches,
                ) !== 1
            ) {
                throw new RuntimeException(Translator::translate('update_signing_keys_invalid'));
            }

            $key = $matches[1];
            $decodedKey = base64_decode($key, true);

            if (!is_string($decodedKey) || strlen($decodedKey) !== 51) {
                throw new RuntimeException(Translator::translate('update_signing_keys_invalid'));
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Builds the OpenSSH allowed-signers file for trusted release keys.
     *
     * Every key is restricted to the package identity and signature namespace so
     * signatures created for another product or purpose cannot authorize an update.
     *
     * @param list<string> $keys - Base64 Ed25519 public key data.
     *
     * @return string
     *
     * @since 2.1.0
     */
    public static function allowedSignersContent(array $keys): string
    {
        $content = '';

        foreach ($keys as $key) {
            $content .= self::PACKAGE_NAME
                . ' namespaces="'
                . self::PACKAGE_NAME
                . '" ssh-ed25519 '
                . $key
                . "\n";
        }

        return $content;
    }

    /**
     * Selects the versioned package, signature, and trusted SHA-256 source.
     *
     * GitHub's API digest is preferred. A same-release checksum asset is required as
     * the fallback, and every selected URL must remain under the configured prefix.
     *
     * @param array  $release        - Parsed release.
     * @param string $downloadPrefix - Allowed asset URL prefix.
     *
     * @return array{package: array, digest: string|null, checksum: array|null, signature: array|null}
     *
     * @throws RuntimeException When required assets or their URLs are invalid.
     *
     * @since 2.1.0
     */
    public static function selectUpdateAssets(
        array $release,
        string $downloadPrefix = self::DOWNLOAD_PREFIX,
    ): array {
        $version = (string) ($release['version'] ?? '');
        $packageAssetName = self::PACKAGE_NAME . '-' . $version . '.pkg';
        $checksumAssetName = $packageAssetName . '.sha256';
        $signatureAssetName = $packageAssetName . '.sig';
        $packageAsset = null;
        $checksumAsset = null;
        $signatureAsset = null;

        foreach ((is_array($release['assets'] ?? null) ? $release['assets'] : []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $assetName = $asset['name'] ?? null;

            if ($assetName === $packageAssetName) {
                $packageAsset = $asset;
            } elseif ($assetName === $checksumAssetName) {
                $checksumAsset = $asset;
            } elseif ($assetName === $signatureAssetName) {
                $signatureAsset = $asset;
            }
        }

        if (!is_array($packageAsset)) {
            throw new RuntimeException(Translator::translate('update_package_asset_missing', [$packageAssetName]));
        }

        $packageAssetUrl = $packageAsset['url'] ?? null;

        if (
            !is_string($packageAssetUrl)
            || self::isAllowedAssetUrl($packageAssetUrl, $downloadPrefix) === false
        ) {
            throw new RuntimeException(Translator::translate('update_asset_url_invalid'));
        }

        if (is_array($signatureAsset)) {
            $signatureAssetUrl = $signatureAsset['url'] ?? null;

            if (
                !is_string($signatureAssetUrl)
                || self::isAllowedAssetUrl($signatureAssetUrl, $downloadPrefix) === false
            ) {
                throw new RuntimeException(Translator::translate('update_asset_url_invalid'));
            }
        }

        $packageDigest = $packageAsset['digest'] ?? null;

        if (is_string($packageDigest) && preg_match('/^sha256:[a-fA-F0-9]{64}$/', $packageDigest) === 1) {
            return [
                'package' => $packageAsset,
                'digest' => $packageDigest,
                'checksum' => null,
                'signature' => $signatureAsset,
            ];
        }

        if (!is_array($checksumAsset)) {
            throw new RuntimeException(Translator::translate('update_checksum_missing', [$checksumAssetName]));
        }

        $checksumAssetUrl = $checksumAsset['url'] ?? null;

        if (
            !is_string($checksumAssetUrl)
            || self::isAllowedAssetUrl($checksumAssetUrl, $downloadPrefix) === false
        ) {
            throw new RuntimeException(Translator::translate('update_asset_url_invalid'));
        }

        return [
            'package' => $packageAsset,
            'digest' => null,
            'checksum' => $checksumAsset,
            'signature' => $signatureAsset,
        ];
    }

    /**
     * Verifies one downloaded package against its detached OpenSSH signature.
     *
     * Tests inject a private stub executable, while production always uses the
     * fixed system ssh-keygen path and package identity for both signer and namespace.
     *
     * @param string       $packagePath   - Downloaded package path.
     * @param string       $signaturePath - Downloaded detached signature path.
     * @param list<string> $keys          - Trusted base64 Ed25519 key data.
     * @param string       $directory     - Private directory for allowed signers.
     *
     * @return void
     *
     * @throws RuntimeException When the tool is unavailable or verification fails.
     *
     * @since 2.1.0
     */
    public function verifyReleaseSignature(
        string $packagePath,
        string $signaturePath,
        array $keys,
        string $directory,
    ): void {
        if (
            is_file($this->sshKeygenCommand) === false
            || is_executable($this->sshKeygenCommand) === false
        ) {
            throw new RuntimeException(Translator::translate('update_signature_tool_unavailable'));
        }

        $allowedSignersPath = $directory . '/allowed_signers';
        $allowedSignersContent = self::allowedSignersContent($keys);
        $allowedSignersBytes = @file_put_contents(
            $allowedSignersPath,
            $allowedSignersContent,
            LOCK_EX,
        );

        if (
            $allowedSignersBytes !== strlen($allowedSignersContent)
            || @chmod($allowedSignersPath, 0600) === false
        ) {
            throw new RuntimeException(Translator::translate('update_process_failed'));
        }

        $verificationResult = $this->runCommand([
            $this->sshKeygenCommand,
            '-Y',
            'verify',
            '-f',
            $allowedSignersPath,
            '-I',
            self::PACKAGE_NAME,
            '-n',
            self::PACKAGE_NAME,
            '-s',
            $signaturePath,
        ], $packagePath);

        if ($verificationResult['exit_code'] !== 0) {
            throw new RuntimeException(Translator::translate('update_signature_invalid'));
        }
    }

    /**
     * Installs the latest release after checksum, signature, and metadata checks pass.
     *
     * The caller owns the package FileLock. This method records progress, invokes
     * "pkg add -f" only after verification, and cleans its private directory.
     *
     * @param bool $remoteNotifications - Whether later notices may reach remote channels.
     *
     * @return array
     *
     * @throws Throwable When any download, verification, metadata, or install step fails.
     *
     * @since 2.1.0
     */
    public function selfUpdate(bool $remoteNotifications): array
    {
        $temporaryDirectory = null;
        $startedAt = gmdate(DATE_ATOM);

        $this->writeStatus(
            'running',
            Translator::translate('update_status_checking'),
            null,
            $startedAt,
            null,
        );

        try {
            $signingKeys = self::readSigningKeys($this->signingKeysFile);
            $release = $this->fetchLatestRelease();
            $version = $release['version'];

            if (self::isNewerVersion($this->installedVersion, $version) === false) {
                $state = $this->saveReleaseState($release, $version, $remoteNotifications);

                $this->writeStatus(
                    'succeeded',
                    Translator::translate('update_status_current', [$version]),
                    $version,
                    $startedAt,
                    gmdate(DATE_ATOM),
                );

                return $state;
            }

            $assets = self::selectUpdateAssets($release, $this->downloadPrefix);

            if (count($signingKeys) > 0 && !is_array($assets['signature'])) {
                $signatureAssetName = $assets['package']['name'] . '.sig';

                throw new RuntimeException(Translator::translate(
                    'update_signature_missing',
                    [$signatureAssetName],
                ));
            }

            $temporaryDirectory = $this->createTemporaryDirectory();
            $packagePath = $temporaryDirectory . '/' . $assets['package']['name'];

            $this->writeStatus(
                'running',
                Translator::translate('update_status_downloading', [$version]),
                $version,
                $startedAt,
                null,
            );

            $this->downloadFile(
                $assets['package']['url'],
                $packagePath,
                self::PACKAGE_DOWNLOAD_LIMIT,
            );

            $expectedDigest = $assets['digest'];

            if (is_array($assets['checksum'])) {
                $checksumPath = $temporaryDirectory . '/' . $assets['checksum']['name'];

                $this->downloadFile(
                    $assets['checksum']['url'],
                    $checksumPath,
                    self::AUXILIARY_DOWNLOAD_LIMIT,
                );

                $checksumContents = @file_get_contents($checksumPath);
                $expectedDigest = is_string($checksumContents)
                    ? self::checksumDigest($checksumContents)
                    : null;
            }

            $this->writeStatus(
                'running',
                Translator::translate('update_status_verifying', [$version]),
                $version,
                $startedAt,
                null,
            );

            if (
                !is_string($expectedDigest)
                || self::verifyDigest($packagePath, $expectedDigest) === false
            ) {
                throw new RuntimeException(Translator::translate('update_digest_mismatch'));
            }

            if (count($signingKeys) > 0 && is_array($assets['signature'])) {
                $signaturePath = $temporaryDirectory . '/' . $assets['signature']['name'];

                $this->downloadFile(
                    $assets['signature']['url'],
                    $signaturePath,
                    self::AUXILIARY_DOWNLOAD_LIMIT,
                );

                $this->verifyReleaseSignature(
                    $packagePath,
                    $signaturePath,
                    $signingKeys,
                    $temporaryDirectory,
                );
            }

            $this->verifyPackageMetadata($packagePath, $version);

            $this->writeStatus(
                'running',
                Translator::translate('update_status_installing', [$version]),
                $version,
                $startedAt,
                null,
            );

            $installResult = $this->runCommand([
                self::PKG_COMMAND,
                'add',
                '-f',
                $packagePath,
            ]);

            if ($installResult['exit_code'] !== 0) {
                throw new RuntimeException(Translator::translate('update_install_failed', [$installResult['exit_code']]));
            }

            $state = $this->saveReleaseState($release, $version, $remoteNotifications);

            $this->writeStatus(
                'succeeded',
                Translator::translate('update_status_succeeded', [$version]),
                $version,
                $startedAt,
                gmdate(DATE_ATOM),
            );

            return $state;
        } catch (Throwable $throwable) {
            $this->writeStatus(
                'failed',
                $throwable->getMessage(),
                isset($version) && is_string($version) ? $version : null,
                $startedAt,
                gmdate(DATE_ATOM),
            );
            $this->logFailure($throwable);

            throw $throwable;
        } finally {
            if (is_string($temporaryDirectory)) {
                $this->removeTemporaryDirectory($temporaryDirectory);
            }
        }
    }

    /**
     * Returns a complete stable randomized cron schedule.
     *
     * Existing valid values win without invoking the random source. Tests inject a
     * deterministic callback; production uses cryptographically secure random_int().
     *
     * @param array         $storedSchedule - Previously stored schedule.
     * @param callable|null $randomInteger  - Optional integer generator.
     *
     * @return array{minute: int, hour: int, weekday: int, monthday: int}
     *
     * @since 2.1.0
     */
    public static function stableCronSchedule(array $storedSchedule, ?callable $randomInteger = null): array
    {
        $ranges = [
            'minute' => [0, 59],
            'hour' => [0, 23],
            'weekday' => [0, 6],
            'monthday' => [1, 28],
        ];
        $schedule = [];
        $randomInteger ??= static fn (int $minimum, int $maximum): int => random_int($minimum, $maximum);

        foreach ($ranges as $key => $range) {
            $storedValue = $storedSchedule[$key] ?? null;
            $minimum = $range[0];
            $maximum = $range[1];

            if (
                (is_int($storedValue) || (is_string($storedValue) && ctype_digit($storedValue)))
                && (int) $storedValue >= $minimum
                && (int) $storedValue <= $maximum
            ) {
                $schedule[$key] = (int) $storedValue;
                continue;
            }

            $schedule[$key] = $randomInteger($minimum, $maximum);
        }

        return $schedule;
    }

    /**
     * Creates or closes the single update notice and records de-duplication state.
     *
     * A release can create only one bell notice. Remote notification delivery follows
     * the explicit checkbox by inverting it for pfSense's local_only argument.
     *
     * @param array  $state               - Update state.
     * @param string $installedVersion    - Installed version.
     * @param string $latestVersion       - Latest version.
     * @param bool   $remoteNotifications - Whether remote delivery is enabled.
     *
     * @return array
     *
     * @since 2.1.0
     */
    public static function synchronizeNotice(
        array $state,
        string $installedVersion,
        string $latestVersion,
        bool $remoteNotifications,
    ): array {
        if (self::isNewerVersion($installedVersion, $latestVersion) === false) {
            if (function_exists('close_notice')) {
                \close_notice(self::NOTICE_ID);
            }

            return $state;
        }

        if (($state['notified_version'] ?? null) === $latestVersion) {
            return $state;
        }

        $message = Translator::translate('update_available_notice', [
            Translator::translate('brand'),
            $latestVersion,
        ]);

        \file_notice(
            self::NOTICE_ID,
            $message,
            Translator::translate('brand'),
            self::ABOUT_PAGE,
            1,
            $remoteNotifications === false,
        );

        $state['notified_version'] = $latestVersion;

        return $state;
    }

    /**
     * Converts one checksum-file value into GitHub's digest format.
     *
     * Release checksum assets contain only the hexadecimal hash. Whitespace around
     * that value is accepted, while filenames and other formats fail closed.
     *
     * @param string $checksum - Downloaded checksum-file contents.
     *
     * @return string|null
     *
     * @since 2.1.0
     */
    public static function checksumDigest(string $checksum): ?string
    {
        $normalizedChecksum = trim($checksum);

        if (preg_match('/^[a-fA-F0-9]{64}$/', $normalizedChecksum) !== 1) {
            return null;
        }

        return 'sha256:' . strtolower($normalizedChecksum);
    }

    /**
     * Checks a package file against an expected SHA-256 digest.
     *
     * @param string $packagePath - Downloaded package path.
     * @param string $digest      - Expected digest with sha256 prefix.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function verifyDigest(string $packagePath, string $digest): bool
    {
        if (preg_match('/^sha256:([a-fA-F0-9]{64})$/', $digest, $matches) !== 1) {
            return false;
        }

        $actualDigest = hash_file('sha256', $packagePath);

        return is_string($actualDigest) && hash_equals(strtolower($matches[1]), strtolower($actualDigest));
    }

    /**
     * Removes a completed background status before a standalone check.
     *
     * @return void
     *
     * @since 2.1.0
     * @private
     */
    private function clearStatus(): void
    {
        if (file_exists($this->statusFile)) {
            @unlink($this->statusFile);
        }
    }

    /**
     * Creates a private temporary directory for release assets.
     *
     * @return string
     *
     * @throws RuntimeException When a private directory cannot be created.
     *
     * @since 2.1.0
     * @private
     */
    private function createTemporaryDirectory(): string
    {
        try {
            $suffix = bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            throw new RuntimeException(Translator::translate('update_temporary_directory_failed'), 0, $throwable);
        }

        $temporaryDirectory = sys_get_temp_dir() . '/ipv6-prefix-updater-' . $suffix;

        if (mkdir($temporaryDirectory, 0700) === false) {
            throw new RuntimeException(Translator::translate('update_temporary_directory_failed'));
        }

        return $temporaryDirectory;
    }

    /**
     * Downloads one release asset with pfSense proxy and TLS settings.
     *
     * @param string $url       - Validated asset URL.
     * @param string $path      - Private destination path.
     * @param int    $byteLimit - Maximum accepted download size.
     *
     * @return void
     *
     * @throws RuntimeException When transport or HTTP validation fails.
     *
     * @since 2.1.0
     * @private
     */
    private function downloadFile(string $url, string $path, int $byteLimit): void
    {
        $fileHandle = fopen($path, 'wb');

        if ($fileHandle === false || chmod($path, 0600) === false) {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }

            throw new RuntimeException(Translator::translate('update_download_failed'));
        }

        $curlHandle = curl_init($url);

        if ($curlHandle === false) {
            fclose($fileHandle);

            throw new RuntimeException(Translator::translate('update_download_failed'));
        }

        $downloadTooLarge = false;
        $progressCallback = static function (
            mixed $unusedHandle,
            float $downloadTotal,
            float $downloadedCount,
            float $uploadTotal,
            float $uploadedCount,
        ) use (&$downloadTooLarge, $byteLimit): int {
            if ($downloadedCount > $byteLimit) {
                $downloadTooLarge = true;

                return 1;
            }

            return 0;
        };

        $this->configureCurl($curlHandle, $url, [
            CURLOPT_FILE => $fileHandle,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
            ],
            CURLOPT_MAXFILESIZE_LARGE => $byteLimit,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => $progressCallback,
        ]);

        $result = curl_exec($curlHandle);
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlErrorCode = curl_errno($curlHandle);
        $curlError = curl_error($curlHandle);

        curl_close($curlHandle);
        fclose($fileHandle);

        if ($downloadTooLarge || $curlErrorCode === CURLE_FILESIZE_EXCEEDED) {
            @unlink($path);

            throw new RuntimeException(Translator::translate('update_download_too_large'));
        }

        if ($result !== true || $statusCode < 200 || $statusCode >= 300) {
            @unlink($path);
            $detail = $curlError !== '' ? $curlError : (string) $statusCode;

            throw new RuntimeException(Translator::translate('update_download_failed_detail', [$detail]));
        }
    }

    /**
     * Fetches and parses the latest GitHub release.
     *
     * @return array
     *
     * @throws RuntimeException When GitHub rejects or rate-limits the request.
     *
     * @since 2.1.0
     * @private
     */
    private function fetchLatestRelease(): array
    {
        $responseHeaders = [];
        $curlHandle = curl_init($this->releasesApi);

        if ($curlHandle === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_transport'));
        }

        $headerCallback = static function (mixed $unusedHandle, string $headerLine) use (&$responseHeaders): int {
            $separatorPosition = strpos($headerLine, ':');

            if ($separatorPosition !== false) {
                $headerName = strtolower(trim(substr($headerLine, 0, $separatorPosition)));
                $headerValue = trim(substr($headerLine, $separatorPosition + 1));
                $responseHeaders[$headerName] = $headerValue;
            }

            return strlen($headerLine);
        };

        $this->configureCurl($curlHandle, $this->releasesApi, [
            CURLOPT_HEADERFUNCTION => $headerCallback,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($curlHandle);
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curlHandle);

        curl_close($curlHandle);

        if ($response === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_detail', [$curlError]));
        }

        if ($statusCode === 403 || $statusCode === 429) {
            throw new RuntimeException(Translator::translate('update_rate_limited'));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(Translator::translate('update_http_failed', [$statusCode]));
        }

        if (!is_string($response)) {
            throw new RuntimeException(Translator::translate('update_release_json_invalid'));
        }

        return self::parseReleaseJson($response);
    }

    /**
     * Applies shared cURL safety, timeout, redirect, and proxy options.
     *
     * @param mixed  $curlHandle - cURL handle.
     * @param string $url        - Initial request URL.
     * @param array  $options    - Operation-specific cURL options.
     *
     * @return void
     *
     * @since 2.1.0
     * @private
     */
    private function configureCurl(mixed $curlHandle, string $url, array $options): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $allowedProtocols = $scheme === 'http'
            ? CURLPROTO_HTTP | CURLPROTO_HTTPS
            : CURLPROTO_HTTPS;
        $sharedOptions = [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => $allowedProtocols,
            CURLOPT_REDIR_PROTOCOLS => $allowedProtocols,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ];

        if (curl_setopt_array($curlHandle, $options + $sharedOptions) === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_transport'));
        }

        \set_curlproxy($curlHandle);
    }

    /**
     * Logs one safe update failure through pfSense.
     *
     * @param Throwable $throwable - Failure to log.
     *
     * @return void
     *
     * @since 2.1.0
     * @private
     */
    private function logFailure(Throwable $throwable): void
    {
        if (function_exists('log_error')) {
            \log_error('IPv6 Prefix Updater: ' . $throwable->getMessage());
        }
    }

    /**
     * Normalizes a package version or Git tag for version_compare().
     *
     * @param string $version - Package version or tag.
     *
     * @return string
     *
     * @since 2.1.0
     * @private
     */
    private static function normalizeVersion(string $version): string
    {
        return preg_replace('/^[vV]/', '', trim($version), 1) ?? trim($version);
    }

    /**
     * Reads one JSON object from a package-owned file.
     *
     * @param string $path         - JSON file path.
     * @param string $errorMessage - Localized malformed-file message.
     *
     * @return array
     *
     * @throws RuntimeException When the file cannot be decoded as an object.
     *
     * @since 2.1.0
     * @private
     */
    private static function readJsonObject(string $path, string $errorMessage): array
    {
        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException($errorMessage);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException($errorMessage, 0, $jsonException);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($errorMessage);
        }

        return $decoded;
    }

    /**
     * Removes files from the private update directory and then the directory.
     *
     * @param string $temporaryDirectory - Exact generated temporary directory.
     *
     * @return void
     *
     * @since 2.1.0
     * @private
     */
    private function removeTemporaryDirectory(string $temporaryDirectory): void
    {
        $entries = @scandir($temporaryDirectory);

        foreach ((is_array($entries) ? $entries : []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $temporaryDirectory . '/' . $entry;

            if (is_file($entryPath) || is_link($entryPath)) {
                @unlink($entryPath);
            }
        }

        @rmdir($temporaryDirectory);
    }

    /**
     * Runs one fixed executable with separated arguments and captured output.
     *
     * @param list<string> $command   - Executable and arguments.
     * @param string|null  $stdinPath - Optional file opened as standard input.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     *
     * @throws RuntimeException When the process cannot be started.
     *
     * @since 2.1.0
     * @private
     */
    private function runCommand(array $command, ?string $stdinPath = null): array
    {
        $descriptorSpecification = [
            0 => is_string($stdinPath) ? ['file', $stdinPath, 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptorSpecification, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(Translator::translate('update_process_failed'));
        }

        if ($stdinPath === null) {
            fclose($pipes[0]);
        }

        $standardOutput = stream_get_contents($pipes[1]);
        $standardError = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($standardOutput) ? $standardOutput : '',
            'stderr' => is_string($standardError) ? $standardError : '',
        ];
    }

    /**
     * Saves freshly fetched release metadata after a successful install or no-op.
     *
     * @param array  $release             - Parsed release.
     * @param string $effectiveVersion    - Version treated as installed.
     * @param bool   $remoteNotifications - Whether remote notices are enabled.
     *
     * @return array
     *
     * @since 2.1.0
     * @private
     */
    private function saveReleaseState(
        array $release,
        string $effectiveVersion,
        bool $remoteNotifications,
    ): array {
        $previousState = self::readUpdatesState($this->updatesFile);
        $state = [
            'checked_at' => gmdate(DATE_ATOM),
            'latest_version' => $release['version'],
            'tag' => $release['tag'],
            'release_url' => $release['release_url'],
            'published_at' => $release['published_at'],
            'notified_version' => $previousState['notified_version'],
            'last_error' => null,
        ];
        $state = self::synchronizeNotice(
            $state,
            $effectiveVersion,
            $release['version'],
            $remoteNotifications,
        );

        self::writeJsonAtomically($this->updatesFile, $state);

        return $state;
    }

    /**
     * Verifies the downloaded archive's package name and version.
     *
     * @param string $packagePath - Downloaded package path.
     * @param string $version     - Expected release version.
     *
     * @return void
     *
     * @throws RuntimeException When pkg reports unexpected metadata.
     *
     * @since 2.1.0
     * @private
     */
    private function verifyPackageMetadata(string $packagePath, string $version): void
    {
        $queryResult = $this->runCommand([
            self::PKG_COMMAND,
            'query',
            '-F',
            $packagePath,
            '%n %v',
        ]);
        $expectedMetadata = self::PACKAGE_NAME . ' ' . $version;
        $actualMetadata = trim($queryResult['stdout']);

        if ($queryResult['exit_code'] !== 0 || $actualMetadata !== $expectedMetadata) {
            throw new RuntimeException(Translator::translate('update_package_metadata_invalid'));
        }
    }

    /**
     * Writes one progress snapshot for the polling About tab.
     *
     * @param string      $state      - Running, succeeded, or failed.
     * @param string      $message    - Localized full-sentence status.
     * @param string|null $version    - Release version when known.
     * @param string      $startedAt  - Job start timestamp.
     * @param string|null $finishedAt - Job finish timestamp.
     *
     * @return void
     *
     * @since 2.1.0
     * @private
     */
    private function writeStatus(
        string $state,
        string $message,
        ?string $version,
        string $startedAt,
        ?string $finishedAt,
    ): void {
        self::writeJsonAtomically($this->statusFile, [
            'state' => $state,
            'message' => $message,
            'version' => $version,
            'started_at' => $startedAt,
            'updated_at' => gmdate(DATE_ATOM),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * Replaces one JSON file atomically with private permissions.
     *
     * @param string $path - Destination path.
     * @param array  $data - JSON object.
     *
     * @return void
     *
     * @throws RuntimeException When the directory or file cannot be written.
     *
     * @since 2.1.0
     * @private
     */
    private static function writeJsonAtomically(string $path, array $data): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && mkdir($directory, 0700, true) === false) {
            throw new RuntimeException(Translator::translate('update_state_write_failed'));
        }

        @chmod($directory, 0700);

        $temporaryPath = tempnam($directory, '.update-');

        if ($temporaryPath === false || chmod($temporaryPath, 0600) === false) {
            throw new RuntimeException(Translator::translate('update_state_write_failed'));
        }

        try {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            @unlink($temporaryPath);

            throw new RuntimeException(Translator::translate('update_state_write_failed'), 0, $jsonException);
        }

        if (file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporaryPath);

            throw new RuntimeException(Translator::translate('update_state_write_failed'));
        }

        if (rename($temporaryPath, $path) === false) {
            @unlink($temporaryPath);

            throw new RuntimeException(Translator::translate('update_state_write_failed'));
        }

        @chmod($path, 0600);
    }
}

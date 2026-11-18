<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;
use Throwable;

require_once(__DIR__ . '/ReleasePinUnavailableException.php');

/**
 * Reconciles configuration-restore notices and the recovery cron entry.
 *
 * Package lifecycle hooks use this boundary to keep notice matching, tagged cron
 * ownership, and the file-independent recovery command independently testable.
 *
 * @since 2.1.2
 */
final class RestoreManager
{
    /**
     * Fixed prefix that identifies only this package's recovery cron entry.
     *
     * The shell no-op keeps the marker executable while allowing pfSense and the
     * package lifecycle hooks to find the entry without parsing its pinned values.
     *
     * @since 2.1.2
     */
    public const CRON_MARKER = ': ipv6-prefix-updater-restore-heal;';

    /**
     * Stable identifier for the reassurance notice.
     *
     * A fixed identifier lets pfSense keep at most one reassurance notice when a
     * lifecycle sync is repeated after the same configuration restore.
     *
     * @since 2.1.2
     */
    public const REASSURANCE_NOTICE_ID = 'ipv6-prefix-updater-restore-reassurance';

    /**
     * Package name stored in the FreeBSD package database.
     *
     * The detached recovery command checks this exact package before downloading
     * anything, which makes its normal already-installed path inexpensive.
     *
     * @since 2.1.2
     */
    public const PACKAGE_NAME = 'pfSense-pkg-Ipv6PrefixUpdater';

    /**
     * Safe upper bound for the generated command stored in the system crontab.
     *
     * FreeBSD cron uses a 1,000-byte command buffer. Leaving room below that limit
     * prevents a future longer version from producing a truncated recovery entry.
     *
     * @since 2.1.2
     */
    public const CRON_COMMAND_MAX_LENGTH = 950;

    /**
     * Package name used by pfSense package registration.
     *
     * The restored installed-package record can expose this value as either its
     * name or internal name, depending on how pfSense registered the package.
     *
     * @since 2.1.2
     */
    private const INTERNAL_NAME = 'Ipv6PrefixUpdater';

    /**
     * Package configuration file stored in the pfSense package record.
     *
     * This provides a stable fallback identity when a display name differs from
     * the internal package name.
     *
     * @since 2.1.2
     */
    private const CONFIGURATION_FILE = 'ipv6-prefix-updater.xml';

    /**
     * Trusted prefix for versioned release package URLs.
     *
     * Recovery pins may reference only this repository and never a moving latest
     * download URL.
     *
     * @since 2.1.2
     */
    private const DOWNLOAD_PREFIX = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/download/';

    /**
     * Persistent directory restored with the firewall configuration.
     *
     * The stable package filename keeps at most one checksum-verified offline copy
     * while the cron command pins its expected version, URL, and digest.
     *
     * @since 2.1.2
     */
    private const HEAL_DIRECTORY = '/conf/ipv6-prefix-updater/restore-heal';

    /**
     * Stable local package cache restored with configuration data.
     *
     * A stale cached version fails the new entry's digest and is replaced before
     * installation, so one path can safely follow package upgrades.
     *
     * @since 2.1.2
     */
    private const CACHE_FILE = self::HEAL_DIRECTORY . '/package.pkg';

    /**
     * Temporary download path beside the persistent package cache.
     *
     * A download reaches the cache path only after its SHA-256 digest matches the
     * value written by the installed release.
     *
     * @since 2.1.2
     */
    private const DOWNLOAD_FILE = self::CACHE_FILE . '.tmp';

    /**
     * pfSense flag indicating that stock package restoration is still active.
     *
     * The recovery command waits for pfSense to remove this flag before it checks
     * or installs the independently distributed package.
     *
     * @since 2.1.2
     */
    private const PACKAGE_SYNC_FLAG = '/conf/needs_package_sync';

    /**
     * Closes false removal notices and files one localized reassurance.
     *
     * Callers provide pfSense side effects so matching and idempotency can be tested
     * without a firewall. The lifecycle wrapper catches and logs every failure.
     *
     * @param array    $installedPackages   - Registered pfSense packages.
     * @param array    $notices             - pfSense notice queue entries.
     * @param string   $productLabel        - Active pfSense product label.
     * @param string   $falseNoticeTemplate - Localized pfSense removal template.
     * @param string   $reassuranceNotice   - Localized package reassurance.
     * @param string   $category            - Package notice category.
     * @param string   $aboutPage           - Package About page.
     * @param callable $closeNotice         - Notice removal callback.
     * @param callable $fileNotice          - Notice creation callback.
     *
     * @return bool
     *
     * @since 2.1.2
     */
    public static function reconcileFalseRemovalNotices(
        array $installedPackages,
        array $notices,
        string $productLabel,
        string $falseNoticeTemplate,
        string $reassuranceNotice,
        string $category,
        string $aboutPage,
        callable $closeNotice,
        callable $fileNotice,
    ): bool {
        $registeredPackage = self::registeredPackage($installedPackages);

        if ($registeredPackage === null) {
            return false;
        }

        $falseNoticeTexts = array_map(
            static fn (string $falseNoticeText): string => htmlentities($falseNoticeText),
            self::falseRemovalNoticeTexts(
                $registeredPackage,
                $productLabel,
                $falseNoticeTemplate,
            ),
        );
        $storedReassuranceNoticeIdentifier = htmlentities(self::REASSURANCE_NOTICE_ID);
        $hasReassuranceNotice = array_key_exists(self::REASSURANCE_NOTICE_ID, $notices);
        $matchingNoticeIdentifiers = [];

        foreach ($notices as $noticeIdentifier => $notice) {
            if (!is_array($notice)) {
                continue;
            }

            if (($notice['id'] ?? null) === $storedReassuranceNoticeIdentifier) {
                $hasReassuranceNotice = true;
            }

            $noticeText = $notice['notice'] ?? null;

            if (
                is_string($noticeText)
                && in_array($noticeText, $falseNoticeTexts, true)
            ) {
                $matchingNoticeIdentifiers[] = (string) $noticeIdentifier;
            }
        }

        if (count($matchingNoticeIdentifiers) === 0) {
            return false;
        }

        foreach ($matchingNoticeIdentifiers as $matchingNoticeIdentifier) {
            $closeNotice($matchingNoticeIdentifier);
        }

        if ($hasReassuranceNotice === false) {
            $fileNotice(
                self::REASSURANCE_NOTICE_ID,
                $reassuranceNotice,
                $category,
                $aboutPage,
                1,
                true,
            );
        }

        return true;
    }

    /**
     * Refreshes the recovery entry only when its installed release pin changed.
     *
     * Exact current commands avoid lookup and writes. Transient failures preserve
     * every item, while a definitive unavailable pin removes only tagged entries.
     *
     * @param array       $cronItems        - Existing pfSense cron items.
     * @param string|null $installedVersion - Installed package version.
     * @param callable    $pinLookup        - Installed release pin lookup.
     * @param callable    $writeCronItems   - Changed cron item writer.
     * @param callable    $logFailure       - Lookup failure logger.
     *
     * @return void
     *
     * @since 2.1.2
     */
    public static function refreshCronItems(
        array $cronItems,
        ?string $installedVersion,
        callable $pinLookup,
        callable $writeCronItems,
        callable $logFailure,
    ): void {
        if ($installedVersion === null || trim($installedVersion) === '') {
            $versionFailure = new RuntimeException('The installed release version is unavailable.');

            $logFailure($versionFailure);

            return;
        }

        if (self::currentCronPin($cronItems, $installedVersion) !== null) {
            return;
        }

        try {
            $pin = $pinLookup($installedVersion);

            if (is_array($pin) === false || self::buildCronCommand($pin) === null) {
                throw new RuntimeException('The release lookup returned an invalid recovery pin.');
            }
        } catch (ReleasePinUnavailableException $releasePinUnavailableException) {
            $logFailure($releasePinUnavailableException);

            $synchronizedCronItems = self::synchronizeCronItems($cronItems, null);

            $writeCronItems($synchronizedCronItems);

            return;
        } catch (Throwable $throwable) {
            $logFailure($throwable);

            return;
        }

        $synchronizedCronItems = self::synchronizeCronItems($cronItems, $pin);

        $writeCronItems($synchronizedCronItems);
    }

    /**
     * Replaces every tagged recovery item with the current verified pin.
     *
     * Unrelated cron entries are preserved byte-for-byte. Callers pass null only for
     * deliberate removal or a definitive answer that no verifiable pin exists.
     *
     * @param array      $cronItems - Existing pfSense cron items.
     * @param array|null $pin       - Installed release pin, or null.
     *
     * @return array
     *
     * @since 2.1.2
     */
    public static function synchronizeCronItems(array $cronItems, ?array $pin): array
    {
        $synchronizedCronItems = [];

        foreach ($cronItems as $cronItem) {
            if (is_array($cronItem) && self::isRestoreCronItem($cronItem)) {
                continue;
            }

            $synchronizedCronItems[] = $cronItem;
        }

        $restoreCronItem = self::cronItem($pin);

        if ($restoreCronItem !== null) {
            $synchronizedCronItems[] = $restoreCronItem;
        }

        return $synchronizedCronItems;
    }

    /**
     * Removes the recovery cron item only for a deliberate package removal.
     *
     * Restore-driven package work and a package's optional Keep Settings choice
     * preserve the self-heal entry that a restored configuration still needs.
     *
     * @param array $cronItems         - Existing pfSense cron items.
     * @param bool  $restoreInProgress - Whether pfSense is restoring packages.
     * @param bool  $keepSettings      - Whether package settings must be retained.
     *
     * @return array
     *
     * @since 2.1.2
     */
    public static function removeCronItems(
        array $cronItems,
        bool $restoreInProgress,
        bool $keepSettings = false,
    ): array {
        if ($restoreInProgress || $keepSettings) {
            return $cronItems;
        }

        return self::synchronizeCronItems($cronItems, null);
    }

    /**
     * Builds the detached, package-file-independent recovery command.
     *
     * The command uses only base-system tools, verifies either the restored cache or
     * a pinned versioned download, and installs only the checksum-matching package.
     *
     * @param array $pin - Installed release pin.
     *
     * @return string|null
     *
     * @since 2.1.2
     */
    public static function buildCronCommand(array $pin): ?string
    {
        $normalizedPin = self::normalizedPin($pin);

        if ($normalizedPin === null) {
            return null;
        }

        $shellScript = self::shellScript($normalizedPin);

        $command = self::CRON_MARKER
            . ' /usr/sbin/daemon -f /bin/sh -c '
            . escapeshellarg($shellScript);

        return strlen($command) <= self::CRON_COMMAND_MAX_LENGTH
            ? $command
            : null;
    }

    /**
     * Reports whether a cron item belongs to configuration-restore recovery.
     *
     * Prefix ownership prevents package removal or refresh from changing entries
     * created by an administrator or another package.
     *
     * @param array $cronItem - One pfSense cron item.
     *
     * @return bool
     *
     * @since 2.1.2
     */
    public static function isRestoreCronItem(array $cronItem): bool
    {
        $command = $cronItem['command'] ?? null;

        return is_string($command) && str_starts_with($command, self::CRON_MARKER);
    }

    /**
     * Finds an exact current recovery pin in the stored cron items.
     *
     * Version, URL, and SHA-256 values are parsed from each tagged command and then
     * rebuilt, so only the command builder's byte-identical output skips lookup.
     *
     * @param array  $cronItems        - Existing pfSense cron items.
     * @param string $installedVersion - Installed package version.
     *
     * @return array{version: string, url: string, sha256: string}|null
     *
     * @since 2.1.2
     * @private
     */
    private static function currentCronPin(array $cronItems, string $installedVersion): ?array
    {
        $normalizedInstalledVersion = trim($installedVersion);
        $normalizedInstalledVersion = preg_replace(
            '/^[vV]/',
            '',
            $normalizedInstalledVersion,
            1,
        ) ?? $normalizedInstalledVersion;

        foreach ($cronItems as $cronItem) {
            if (!is_array($cronItem)) {
                continue;
            }

            $pin = self::cronPin($cronItem);

            if ($pin !== null && $pin['version'] === $normalizedInstalledVersion) {
                return $pin;
            }
        }

        return null;
    }

    /**
     * Parses and verifies the pin embedded in one tagged recovery command.
     *
     * Rebuilding from the parsed values rejects edited, truncated, or legacy command
     * shapes even when they retain this package's ownership marker.
     *
     * @param array $cronItem - One pfSense cron item.
     *
     * @return array{version: string, url: string, sha256: string}|null
     *
     * @since 2.1.2
     * @private
     */
    private static function cronPin(array $cronItem): ?array
    {
        if (self::isRestoreCronItem($cronItem) === false) {
            return null;
        }

        $command = $cronItem['command'];
        $versionMatched = preg_match('/;v=([^;]+);d=/', $command, $versionMatches) === 1;
        $urlMatched = preg_match('/;u=([^;]+);h=/', $command, $urlMatches) === 1;
        $sha256Matched = preg_match(
            '/;h=([a-fA-F0-9]{64});l\(\)/',
            $command,
            $sha256Matches,
        ) === 1;

        if (
            $versionMatched === false
            || $urlMatched === false
            || $sha256Matched === false
        ) {
            return null;
        }

        $pin = self::normalizedPin([
            'version' => $versionMatches[1],
            'url' => $urlMatches[1],
            'sha256' => $sha256Matches[1],
        ]);

        if ($pin === null || self::buildCronCommand($pin) !== $command) {
            return null;
        }

        return $pin;
    }

    /**
     * Finds this package's restored registration record.
     *
     * Name, internal name, and configuration file mirror pfSense's own package lookup
     * rules while remaining specific to IPv6 Prefix Updater.
     *
     * @param array $installedPackages - Registered pfSense packages.
     *
     * @return array|null
     *
     * @since 2.1.2
     * @private
     */
    private static function registeredPackage(array $installedPackages): ?array
    {
        foreach ($installedPackages as $installedPackage) {
            if (!is_array($installedPackage)) {
                continue;
            }

            $name = $installedPackage['name'] ?? null;
            $internalName = $installedPackage['internal_name'] ?? null;
            $configurationFile = $installedPackage['configurationfile'] ?? null;

            if (
                $name === self::INTERNAL_NAME
                || $internalName === self::INTERNAL_NAME
                || $configurationFile === self::CONFIGURATION_FILE
            ) {
                return $installedPackage;
            }
        }

        return null;
    }

    /**
     * Builds both pfSense false-notice name forms for one package record.
     *
     * The plain name and name-with-internal-name variants use pfSense's already
     * localized template so exact matching follows the active WebGUI language.
     *
     * @param array  $registeredPackage  - This package's registration record.
     * @param string $productLabel       - Active pfSense product label.
     * @param string $localizedTemplate  - Localized pfSense removal template.
     *
     * @return list<string>
     *
     * @since 2.1.2
     * @private
     */
    private static function falseRemovalNoticeTexts(
        array $registeredPackage,
        string $productLabel,
        string $localizedTemplate,
    ): array {
        $name = $registeredPackage['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return [];
        }

        $displayNames = [$name];
        $internalName = $registeredPackage['internal_name'] ?? null;

        if (is_string($internalName) && $internalName !== '') {
            $displayNames[] = sprintf('%s (%s)', $name, $internalName);
        }

        $falseNoticeTexts = [];

        foreach (array_values(array_unique($displayNames)) as $displayName) {
            $falseNoticeTexts[] = sprintf(
                $localizedTemplate,
                $displayName,
                $productLabel,
            );
        }

        return $falseNoticeTexts;
    }

    /**
     * Builds one root cron item from a verified release pin.
     *
     * Every valid item runs at fifteen-minute intervals and carries all information
     * required to recover when none of the package's installed files exist.
     *
     * @param array|null $pin - Installed release pin, or null.
     *
     * @return array|null
     *
     * @since 2.1.2
     * @private
     */
    private static function cronItem(?array $pin): ?array
    {
        if ($pin === null) {
            return null;
        }

        $command = self::buildCronCommand($pin);

        if ($command === null) {
            return null;
        }

        return [
            'minute' => '*/15',
            'hour' => '*',
            'mday' => '*',
            'month' => '*',
            'wday' => '*',
            'who' => 'root',
            'command' => $command,
        ];
    }

    /**
     * Validates and normalizes release values before shell generation.
     *
     * Rejecting moving URLs, unexpected filenames, and malformed digests keeps
     * external release metadata from becoming executable cron configuration.
     *
     * @param array $pin - Candidate installed release pin.
     *
     * @return array{version: string, url: string, sha256: string}|null
     *
     * @since 2.1.2
     * @private
     */
    private static function normalizedPin(array $pin): ?array
    {
        $version = $pin['version'] ?? null;
        $url = $pin['url'] ?? null;
        $sha256 = $pin['sha256'] ?? null;

        if (
            !is_string($version)
            || preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1
            || !is_string($url)
            || str_starts_with($url, self::DOWNLOAD_PREFIX) === false
            || str_contains($url, '/latest/')
            || !is_string($sha256)
            || preg_match('/^[a-fA-F0-9]{64}$/', $sha256) !== 1
        ) {
            return null;
        }

        $urlPath = parse_url($url, PHP_URL_PATH);

        if (!is_string($urlPath)) {
            return null;
        }

        $assetName = basename($urlPath);
        $expectedAssetName = self::PACKAGE_NAME . '-' . $version . '.pkg';

        $expectedUrl = self::DOWNLOAD_PREFIX
            . 'v'
            . $version
            . '/'
            . $expectedAssetName;

        if ($assetName !== $expectedAssetName || $url !== $expectedUrl) {
            return null;
        }

        return [
            'version' => $version,
            'url' => $url,
            'sha256' => strtolower($sha256),
        ];
    }

    /**
     * Creates the self-contained shell program run by the detached cron entry.
     *
     * A restored cache is trusted only after digest verification. Any absent or stale
     * cache is replaced by the exact versioned asset before pkg is invoked.
     *
     * @param array{version: string, url: string, sha256: string} $pin - Normalized pin.
     *
     * @return string
     *
     * @since 2.1.2
     * @private
     */
    private static function shellScript(array $pin): string
    {
        $segments = [
            '[ -e ' . self::PACKAGE_SYNC_FLAG . ' ]&&exit',
            '/usr/local/sbin/pkg info -e ' . self::PACKAGE_NAME . ' >/dev/null 2>&1&&exit',
            'v=' . $pin['version'],
            'd=' . self::HEAL_DIRECTORY,
            'f=$d/' . basename(self::CACHE_FILE),
            't=$f' . substr(self::DOWNLOAD_FILE, strlen(self::CACHE_FILE)),
            'u=' . $pin['url'],
            'h=' . $pin['sha256'],
            'l(){ logger -t ipv6-prefix-updater "restore heal: $*";}',
            'q(){ l "$1 failed";exit 1;}',
            'k(){ [ "$(sha256 -q "$1" 2>/dev/null)" = "$h" ];}',
            'mkdir -p "$d"||q mkdir',
            'if [ -f "$f" ]&&k "$f";then l "cache $v";else [ -f "$f" ]&&l "discard cache";rm -f "$f" "$t";l "fetch $v";fetch -q -o "$t" "$u"||q fetch;k "$t"||{ rm -f "$t";q checksum;};mv -f "$t" "$f"||q cache;fi',
            'l "install $v"',
            '/usr/local/sbin/pkg add -f "$f" >/dev/null 2>&1&&l "installed $v"||q install',
        ];

        return implode(';', $segments);
    }
}

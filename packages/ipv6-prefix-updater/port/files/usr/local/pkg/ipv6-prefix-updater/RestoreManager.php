<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;
use Throwable;

require_once(__DIR__ . '/GitHubReleaseSource.php');
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
     * Upper bound for the generated command stored in the system crontab.
     *
     * FreeBSD cron reads each command into a 1,000-byte buffer that also holds the
     * terminating NUL, so a longer command would be truncated.
     *
     * @since 2.1.2
     */
    public const CRON_COMMAND_MAX_LENGTH = 999;

    /**
     * Short package name shared by the log tag, cache folder, and pidfile.
     *
     * The recovery script derives every package-specific path from this one value,
     * which keeps the cron command short and identical across packages.
     *
     * @since 2.1.3
     */
    public const SHORT_NAME = 'ipv6-prefix-updater';

    /**
     * GitHub repository that publishes the pinned release packages.
     *
     * @since 2.1.3
     */
    public const REPOSITORY = 'mrjackyliang/pfsense-ipv6-prefix-updater';

    /**
     * Persistent directory restored with the firewall configuration.
     *
     * The recovery job keeps at most one checksum-verified package here, named after
     * its version, so a restore onto the same firewall can reinstall offline.
     *
     * @since 2.1.2
     */
    public const HEAL_DIRECTORY = '/conf/' . self::SHORT_NAME . '/restore-heal';

    /**
     * Pidfile that daemon(8) holds locked while one recovery run is working.
     *
     * A second run started by the next quarter-hour exits at once instead of
     * downloading or installing alongside the first.
     *
     * @since 2.1.3
     */
    public const PID_FILE = '/var/run/' . self::SHORT_NAME . '-restore-heal.pid';

    /**
     * Recovery program shared byte for byte by every package built from Nova.
     *
     * Arguments: package name, repository, SHA-256, version, short name. It defers
     * while pfSense restores packages, exits when the package is installed, reuses a
     * verified cached copy, otherwise downloads and verifies the pinned release into
     * the cache, then installs it. Every step logs under "<short name>-restore-heal".
     *
     * @since 2.1.3
     */
    public const SHELL_SCRIPT = 'p=$1;r=$2;h=$3;v=$4;g=$5-restore-heal;d=/conf/$5/restore-heal;n=$p-$v.pkg;c=$d/$n;t=$c.tmp;L(){ /usr/bin/logger -t $g "$1 $v";};[ -e /conf/needs_package_sync ]&&{ L deferred;exit 0;};/usr/local/sbin/pkg info -e $p&&exit 0;k(){ [ "$(/sbin/sha256 -q $1 2>/dev/null)" = $h ];};if k $c;then L cache;else /bin/rm -f $t;L fetch;/bin/mkdir -p $d&&/usr/bin/fetch -q -T 30 -o $t https://github.com/$r/releases/download/v$v/$n||{ /bin/rm -f $t;L fetch-failed;exit 1;};k $t||{ /bin/rm -f $t;L checksum-failed;exit 1;};/bin/rm -f $d/*.pkg;/bin/mv -f $t $c||{ L cache-failed;exit 1;};fi;L install;/usr/local/sbin/pkg add -f $c >/dev/null 2>&1||{ L install-failed;exit 1;};L installed';

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
     * download URL. The release source owns the URL itself.
     *
     * @since 2.1.2
     */
    private const DOWNLOAD_PREFIX = GitHubReleaseSource::DOWNLOAD_PREFIX;

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
     * Removes the recovery cron item for every deliberate package removal.
     *
     * Only restore-driven package work keeps the self-heal entry that a restored
     * configuration still needs; any other removal leaves nothing to reinstall it.
     *
     * @param array $cronItems         - Existing pfSense cron items.
     * @param bool  $restoreInProgress - Whether pfSense is restoring packages.
     *
     * @return array
     *
     * @since 2.1.2
     */
    public static function removeCronItems(
        array $cronItems,
        bool $restoreInProgress,
    ): array {
        if ($restoreInProgress) {
            return $cronItems;
        }

        return self::synchronizeCronItems($cronItems, null);
    }

    /**
     * Builds the detached, package-file-independent recovery command.
     *
     * The shared recovery program receives validated, shell-quoted arguments, and
     * daemon(8) holds a pidfile so overlapping runs exit at once.
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

        $arguments = array_map(
            static fn (string $argument): string => escapeshellarg($argument),
            [
                self::PACKAGE_NAME,
                self::REPOSITORY,
                $normalizedPin['sha256'],
                $normalizedPin['version'],
                self::SHORT_NAME,
            ],
        );
        $command = self::CRON_MARKER
            . ' /usr/sbin/daemon -f -p '
            . self::PID_FILE
            . ' /bin/sh -c '
            . escapeshellarg(self::SHELL_SCRIPT)
            . ' rh '
            . implode(' ', $arguments);

        return strlen($command) <= self::CRON_COMMAND_MAX_LENGTH
            ? $command
            : null;
    }

    /**
     * Returns the cached package file name for one release version.
     *
     * @param string $version - Release version.
     *
     * @return string
     *
     * @since 2.1.3
     */
    public static function cacheFileName(string $version): string
    {
        return self::PACKAGE_NAME . '-' . $version . '.pkg';
    }

    /**
     * Deletes cached files that do not belong to the current release pin.
     *
     * Older versions, interrupted downloads, and the earlier "package.pkg" cache
     * name are removed so the folder holds at most the current verified package.
     *
     * @param string $version   - Installed release version.
     * @param string $directory - Cache folder.
     *
     * @return void
     *
     * @since 2.1.3
     */
    public static function pruneCache(string $version, string $directory = self::HEAL_DIRECTORY): void
    {
        $keep = self::cacheFileName($version);

        foreach (self::cacheEntries($directory) as $entry) {
            if ($entry !== $keep) {
                @unlink($directory . '/' . $entry);
            }
        }
    }

    /**
     * Deletes the cache folder after a deliberate package removal.
     *
     * @param string $directory - Cache folder.
     *
     * @return void
     *
     * @since 2.1.3
     */
    public static function removeCache(string $directory = self::HEAL_DIRECTORY): void
    {
        foreach (self::cacheEntries($directory) as $entry) {
            @unlink($directory . '/' . $entry);
        }

        if (is_dir($directory) && !is_link($directory)) {
            @rmdir($directory);
        }
    }

    /**
     * Copies a verified, installed release package into the recovery cache.
     *
     * Callers pass only a package that already passed the release checksum and
     * signature checks. The copy is written beside its final name and renamed into
     * place, then older cached files are pruned.
     *
     * @param string $packagePath - Verified package file.
     * @param string $version     - Its release version.
     * @param string $directory   - Cache folder.
     *
     * @return void
     *
     * @throws RuntimeException When the cache cannot be written.
     *
     * @since 2.1.3
     */
    public static function storeVerifiedPackage(
        string $packagePath,
        string $version,
        string $directory = self::HEAL_DIRECTORY,
    ): void {
        if (preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException('The cached release version is invalid.');
        }

        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('The restore-heal cache folder is invalid.');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the restore-heal cache folder.');
        }

        @chmod($directory, 0700);

        $target = $directory . '/' . self::cacheFileName($version);
        $temporary = $target . '.tmp';

        @unlink($temporary);

        if (!@copy($packagePath, $temporary) || !@chmod($temporary, 0600) || !@rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to write the restore-heal package cache.');
        }

        self::pruneCache($version, $directory);
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
        $matched = preg_match(
            "/ rh '[^']+' '[^']+' '([a-f0-9]{64})' '([^']+)' '[^']+'\\z/",
            $command,
            $matches,
        ) === 1;

        if ($matched === false) {
            return null;
        }

        $pin = self::normalizedPin([
            'version' => $matches[2],
            'url' => self::DOWNLOAD_PREFIX
                . 'v'
                . $matches[2]
                . '/'
                . self::cacheFileName($matches[2]),
            'sha256' => $matches[1],
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
     * Lists the regular files in the recovery cache folder.
     *
     * @param string $directory - Cache folder.
     *
     * @return list<string>
     *
     * @since 2.1.3
     * @private
     */
    private static function cacheEntries(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) {
            return [];
        }

        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_file($directory . '/' . $entry),
        ));
    }
}

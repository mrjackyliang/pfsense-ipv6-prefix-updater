<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Runtime\ReleasePinUnavailableException;
use PfSense\Ipv6PrefixUpdater\Runtime\RestoreManager;
use PfSense\Ipv6PrefixUpdater\Runtime\UpdateManager;

/** @var list<array<string, string>> Simulated installed pfSense packages. */
$restoreTestInstalledPackages = [
    [
        'name' => 'Ipv6PrefixUpdater',
        'configurationfile' => 'ipv6-prefix-updater.xml',
    ],
];

/** @var list<string> Captured pfSense error log messages. */
$restoreTestLoggedErrors = [];

if (function_exists('config_get_path') === false) {
    /**
     * Returns configuration values used by restore-notice lifecycle tests.
     *
     * Only installed packages and the active locale are needed when the package
     * lifecycle wrapper reconciles the simulated pfSense notice queue.
     *
     * @param string $path    - pfSense configuration path.
     * @param mixed  $default - Default value.
     *
     * @return mixed
     *
     * @since 2.1.2
     */
    function config_get_path(string $path, mixed $default = null): mixed
    {
        global $restoreTestInstalledPackages;

        return match ($path) {
            'installedpackages/package' => $restoreTestInstalledPackages,
            'system/language' => 'en_US',
            default => $default,
        };
    }
}

if (function_exists('g_get') === false) {
    /**
     * Returns the simulated pfSense product label.
     *
     * Restore notice text includes this label exactly as the live package sync does.
     *
     * @param string $key - Global value key.
     *
     * @return mixed
     *
     * @since 2.1.2
     */
    function g_get(string $key): mixed
    {
        return ($key === 'product_label') ? 'pfSense' : null;
    }
}

if (function_exists('gettext') === false) {
    /**
     * Returns untranslated pfSense source text when gettext is unavailable.
     *
     * The local test runtime may omit the extension that pfSense always provides.
     *
     * @param string $message - Source message.
     *
     * @return string
     *
     * @since 2.1.2
     */
    function gettext(string $message): string
    {
        return $message;
    }
}

if (function_exists('log_error') === false) {
    /**
     * Captures one pfSense error log message.
     *
     * Empty notice queues must leave this list unchanged during package sync.
     *
     * @param string $message - Error log message.
     *
     * @return void
     *
     * @since 2.1.2
     */
    function log_error(string $message): void
    {
        global $restoreTestLoggedErrors;

        $restoreTestLoggedErrors[] = $message;
    }
}

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . get_include_path());
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater.inc');

/**
 * Asserts strict equality for configuration-restore regression checks.
 *
 * Notice and cron behavior use exact values because even a small difference can
 * leave a false notice visible or make the persistent entry impossible to find.
 *
 * @param mixed  $expected - Expected value.
 * @param mixed  $actual   - Actual value.
 * @param string $message  - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When values differ.
 *
 * @since 2.1.2
 */
function assertRestoreSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual:   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

/**
 * Returns only recovery cron entries from one complete cron list.
 *
 * Tests use the production ownership predicate to prove refresh and removal never
 * duplicate the tagged entry or select an administrator's unrelated command.
 *
 * @param array $cronItems - Complete cron item list.
 *
 * @return list<array>
 *
 * @since 2.1.2
 */
function restoreCronItems(array $cronItems): array
{
    return array_values(array_filter(
        $cronItems,
        static fn (mixed $cronItem): bool => is_array($cronItem)
            && RestoreManager::isRestoreCronItem($cronItem),
    ));
}

/**
 * Returns queued notices with one stored pfSense notice identifier.
 *
 * Filtering the stored identifier rather than the time-based array key mirrors the
 * lookup package code must use on a real firewall.
 *
 * @param array|false $notices - Simulated pfSense notice queue.
 * @param string      $id      - Raw notice identifier.
 *
 * @return list<array<string, string>>
 *
 * @since 2.1.2
 */
function restoreNoticesByIdentifier(array|false $notices, string $id): array
{
    if ($notices === false) {
        return [];
    }

    $storedIdentifier = htmlentities($id);

    return array_values(array_filter(
        $notices,
        static fn (mixed $notice): bool => is_array($notice)
            && ($notice['id'] ?? null) === $storedIdentifier,
    ));
}

$englishTemplate = 'Package %s does not exist in current %s version and it has been removed.';
$englishFalseNotice = sprintf($englishTemplate, 'Ipv6PrefixUpdater', 'pfSense');
$englishReassuranceNotice = pfsensePackageIpv6PrefixUpdaterTranslate('restore_reassurance_notice');
$englishNoticeCategory = pfsensePackageIpv6PrefixUpdaterTranslate('brand');

$noticeTestTime = 1_700_000_100;
$noticeTestNotices = [];
$noticeTestFileCalls = [];
$noticeTestCloseCalls = [];
$restoreTestLoggedErrors = [];

pfsensePackageIpv6PrefixUpdaterReconcileRestoreNotice();

assertRestoreSame(false, get_notices(), 'An empty pfSense queue must be returned as false.');
assertRestoreSame([], $noticeTestFileCalls, 'An empty queue must not file a reassurance notice.');
assertRestoreSame([], $noticeTestCloseCalls, 'An empty queue must not close any notice.');
assertRestoreSame([], $restoreTestLoggedErrors, 'An empty queue must not log an error.');

file_notice('duplicate-notice', 'First duplicate', 'Test', '', 1, true);
file_notice('duplicate-notice', 'Second duplicate', 'Test', '', 1, true);
close_notice('duplicate-notice');

assertRestoreSame(
    1,
    count(restoreNoticesByIdentifier(get_notices(), 'duplicate-notice')),
    'One close_notice() call must remove only one matching stored identifier.',
);

close_notice('all');

assertRestoreSame(false, get_notices(), 'Closing all notices must empty the simulated queue.');

$noticeTestFileCalls = [];
$noticeTestCloseCalls = [];

file_notice(
    'package-reinstall-false-one',
    $englishFalseNotice,
    'Package reinstall',
    '',
    1,
    true,
);
pfsensePackageIpv6PrefixUpdaterReconcileRestoreNotice();

file_notice(
    'package-reinstall-false-two',
    $englishFalseNotice,
    'Package reinstall',
    '',
    1,
    true,
);
pfsensePackageIpv6PrefixUpdaterReconcileRestoreNotice();

$englishNotices = get_notices();
$englishReassuranceNotices = restoreNoticesByIdentifier(
    $englishNotices,
    RestoreManager::REASSURANCE_NOTICE_ID,
);
$englishReassuranceFileCalls = array_values(array_filter(
    $noticeTestFileCalls,
    static fn (array $noticeCall): bool => $noticeCall['id'] === RestoreManager::REASSURANCE_NOTICE_ID,
));

assertRestoreSame(1, count($englishReassuranceNotices), 'Two restores must leave exactly one reassurance.');
assertRestoreSame(1, count($englishReassuranceFileCalls), 'The second restore must not file another reassurance.');
assertRestoreSame(
    0,
    count(restoreNoticesByIdentifier($englishNotices, 'package-reinstall-false-one')),
    'The first false removal notice must be closed.',
);
assertRestoreSame(
    0,
    count(restoreNoticesByIdentifier($englishNotices, 'package-reinstall-false-two')),
    'The second false removal notice must be closed.',
);
assertRestoreSame(
    [(string) $noticeTestTime, (string) ($noticeTestTime + 1)],
    $noticeTestCloseCalls,
    'False removal notices must be closed by their time-based queue keys.',
);
assertRestoreSame([$noticeTestTime], array_keys($englishNotices), 'The remaining notice must keep its time-based key.');
assertRestoreSame(
    [
        'id' => htmlentities(RestoreManager::REASSURANCE_NOTICE_ID),
        'notice' => htmlentities($englishReassuranceNotice),
        'url' => htmlentities(UpdateManager::ABOUT_PAGE),
        'category' => htmlentities($englishNoticeCategory),
        'priority' => htmlentities('1'),
    ],
    $englishReassuranceNotices[0],
    'The reassurance queue entry must match pfSense storage exactly.',
);
assertRestoreSame([], $restoreTestLoggedErrors, 'Successful restore reconciliation must not log an error.');

$spanishTemplate = 'El paquete %s no existe en la versión actual de %s y se ha eliminado.';
$spanishPackageName = 'Actualizador de prefijos IPv6';
$spanishInternalName = 'Ipv6PrefixUpdater';
$spanishDisplayName = sprintf('%s (%s)', $spanishPackageName, $spanishInternalName);
$spanishFalseNotice = sprintf($spanishTemplate, $spanishDisplayName, 'pfSense');
$noticeTestTime = 1_700_000_200;
$noticeTestNotices = [];
$noticeTestFileCalls = [];
$noticeTestCloseCalls = [];

file_notice(
    'aviso-restauracion',
    $spanishFalseNotice,
    'Reinstalación de paquetes',
    '',
    1,
    true,
);

$spanishStoredNotices = get_notices();

assertRestoreSame(true, is_array($spanishStoredNotices), 'The localized fixture must create one queued notice.');
assertRestoreSame(
    htmlentities($spanishFalseNotice),
    $spanishStoredNotices[$noticeTestTime]['notice'],
    'pfSense storage must entity-encode localized notice text.',
);

$spanishMatched = RestoreManager::reconcileFalseRemovalNotices(
    [
        [
            'name' => $spanishPackageName,
            'internal_name' => $spanishInternalName,
        ],
    ],
    $spanishStoredNotices,
    'pfSense',
    $spanishTemplate,
    'IPv6 Prefix Updater sigue instalado.',
    'IPv6 Prefix Updater',
    '/ipv6-prefix-updater-about.php',
    static function (string $noticeIdentifier): void {
        close_notice($noticeIdentifier);
    },
    static function (
        string $noticeIdentifier,
        string $notice,
        string $category,
        string $url,
        int $priority,
        bool $localOnly,
    ): void {
        file_notice(
            $noticeIdentifier,
            $notice,
            $category,
            $url,
            $priority,
            $localOnly,
        );
    },
);

assertRestoreSame(true, $spanishMatched, 'The localized name-with-internal-name notice must match.');
assertRestoreSame(
    0,
    count(restoreNoticesByIdentifier(get_notices(), 'aviso-restauracion')),
    'The entity-encoded localized false notice must be closed.',
);
assertRestoreSame(
    1,
    count(restoreNoticesByIdentifier(get_notices(), RestoreManager::REASSURANCE_NOTICE_ID)),
    'The localized match must file one reassurance.',
);

$noticeTestTime = 1_700_000_300;
$noticeTestNotices = [];
$noticeTestFileCalls = [];
$noticeTestCloseCalls = [];

file_notice(
    'different-package',
    sprintf($englishTemplate, 'AnotherPackage', 'pfSense'),
    'Package reinstall',
    '',
    1,
    true,
);

$unrelatedNotices = get_notices();
$unrelatedMatched = RestoreManager::reconcileFalseRemovalNotices(
    [
        [
            'name' => 'Ipv6PrefixUpdater',
        ],
    ],
    $unrelatedNotices,
    'pfSense',
    $englishTemplate,
    'IPv6 Prefix Updater is still installed.',
    'IPv6 Prefix Updater',
    '/ipv6-prefix-updater-about.php',
    static function (string $noticeIdentifier): void {
        close_notice($noticeIdentifier);
    },
    static function (
        string $noticeIdentifier,
        string $notice,
        string $category,
        string $url,
        int $priority,
        bool $localOnly,
    ): void {
        file_notice(
            $noticeIdentifier,
            $notice,
            $category,
            $url,
            $priority,
            $localOnly,
        );
    },
);

assertRestoreSame(false, $unrelatedMatched, 'An unrelated false notice must be left alone.');
assertRestoreSame([], $noticeTestCloseCalls, 'No unrelated notice may be closed.');
assertRestoreSame(1, count($noticeTestFileCalls), 'No reassurance is needed without this package false notice.');

$noticeTestTime = 1_700_000_400;
$noticeTestNotices = [];
$noticeTestFileCalls = [];
$noticeTestCloseCalls = [];

file_notice(UpdateManager::NOTICE_ID, 'Stale update one', 'IPv6 Prefix Updater', '', 1, true);
file_notice(UpdateManager::NOTICE_ID, 'Stale update two', 'IPv6 Prefix Updater', '', 1, true);
file_notice(RestoreManager::REASSURANCE_NOTICE_ID, 'Stale reassurance one', 'IPv6 Prefix Updater', '', 1, true);
file_notice(RestoreManager::REASSURANCE_NOTICE_ID, 'Stale reassurance two', 'IPv6 Prefix Updater', '', 1, true);
file_notice('another-package-notice', 'Unrelated notice', 'Another package', '', 1, true);

pfsensePackageIpv6PrefixUpdaterClosePackageNotices();

$uninstallNotices = get_notices();

assertRestoreSame(
    0,
    count(restoreNoticesByIdentifier($uninstallNotices, UpdateManager::NOTICE_ID)),
    'Uninstall must close every stale update notice.',
);
assertRestoreSame(
    0,
    count(restoreNoticesByIdentifier($uninstallNotices, RestoreManager::REASSURANCE_NOTICE_ID)),
    'Uninstall must close both stale reassurance notices.',
);
assertRestoreSame(
    1,
    count(restoreNoticesByIdentifier($uninstallNotices, 'another-package-notice')),
    'Uninstall must preserve unrelated notices.',
);
assertRestoreSame(
    [
        $noticeTestTime,
        $noticeTestTime + 1,
        $noticeTestTime + 2,
        $noticeTestTime + 3,
    ],
    $noticeTestCloseCalls,
    'Uninstall must close each package notice by its queue key.',
);

$otherCronItem = [
    'minute' => '0',
    'hour' => '3',
    'mday' => '*',
    'month' => '*',
    'wday' => '*',
    'who' => 'root',
    'command' => '/usr/local/sbin/another-package',
];
$firstPin = [
    'version' => '2.1.1',
    'url' => 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/download/v2.1.1/pfSense-pkg-Ipv6PrefixUpdater-2.1.1.pkg',
    'sha256' => str_repeat('a', 64),
];
$firstCronItems = RestoreManager::synchronizeCronItems([$otherCronItem], $firstPin);
$firstRestoreCronItems = restoreCronItems($firstCronItems);

assertRestoreSame(2, count($firstCronItems), 'Writing a pin must preserve unrelated cron entries.');
assertRestoreSame(1, count($firstRestoreCronItems), 'Writing a pin must create one tagged entry.');
assertRestoreSame('*/15', $firstRestoreCronItems[0]['minute'], 'Restore recovery must run every fifteen minutes.');
assertRestoreSame('root', $firstRestoreCronItems[0]['who'], 'Restore recovery must run as root.');

$secondPin = [
    'version' => '2.1.2',
    'url' => 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/download/v2.1.2/pfSense-pkg-Ipv6PrefixUpdater-2.1.2.pkg',
    'sha256' => str_repeat('b', 64),
];

$currentLookupCalls = 0;
$currentWriteCalls = 0;
$currentFailures = [];

RestoreManager::refreshCronItems(
    $firstCronItems,
    '2.1.1',
    static function (string $installedVersion) use (&$currentLookupCalls): array {
        $currentLookupCalls++;

        throw new RuntimeException('A current entry must not perform release lookup.');
    },
    static function (array $cronItems) use (&$currentWriteCalls): void {
        $currentWriteCalls++;
    },
    static function (Throwable $throwable) use (&$currentFailures): void {
        $currentFailures[] = $throwable->getMessage();
    },
);

assertRestoreSame(0, $currentLookupCalls, 'A current recovery entry must skip release lookup.');
assertRestoreSame(0, $currentWriteCalls, 'A current recovery entry must skip configuration writes.');
assertRestoreSame([], $currentFailures, 'A current recovery entry must not log a failure.');

$transientStoredCronItems = $firstCronItems;
$transientLookupCalls = 0;
$transientWriteCalls = 0;
$transientFailures = [];

RestoreManager::refreshCronItems(
    $transientStoredCronItems,
    '2.1.2',
    static function (string $installedVersion) use (&$transientLookupCalls): array {
        $transientLookupCalls++;

        throw new RuntimeException('Temporary DNS failure.');
    },
    static function (array $cronItems) use (&$transientStoredCronItems, &$transientWriteCalls): void {
        $transientStoredCronItems = $cronItems;
        $transientWriteCalls++;
    },
    static function (Throwable $throwable) use (&$transientFailures): void {
        $transientFailures[] = $throwable->getMessage();
    },
);

assertRestoreSame(1, $transientLookupCalls, 'A stale entry must attempt one release lookup.');
assertRestoreSame(0, $transientWriteCalls, 'A transient lookup failure must not write configuration.');
assertRestoreSame(
    $firstCronItems,
    $transientStoredCronItems,
    'A transient failure must preserve every cron item exactly.',
);
assertRestoreSame(['Temporary DNS failure.'], $transientFailures, 'A transient failure must log exactly once.');

$notFoundStoredCronItems = $firstCronItems;
$notFoundWriteCalls = 0;
$notFoundFailures = [];

RestoreManager::refreshCronItems(
    $notFoundStoredCronItems,
    '2.1.2',
    static function (string $installedVersion): array {
        throw new ReleasePinUnavailableException('The installed release returned HTTP 404.');
    },
    static function (array $cronItems) use (&$notFoundStoredCronItems, &$notFoundWriteCalls): void {
        $notFoundStoredCronItems = $cronItems;
        $notFoundWriteCalls++;
    },
    static function (Throwable $throwable) use (&$notFoundFailures): void {
        $notFoundFailures[] = $throwable->getMessage();
    },
);

assertRestoreSame(1, $notFoundWriteCalls, 'A definitive HTTP 404 must write the removal.');
assertRestoreSame(0, count(restoreCronItems($notFoundStoredCronItems)), 'A definitive HTTP 404 must remove recovery.');
assertRestoreSame([$otherCronItem], $notFoundStoredCronItems, 'A definitive HTTP 404 must preserve unrelated entries.');
assertRestoreSame(
    ['The installed release returned HTTP 404.'],
    $notFoundFailures,
    'A definitive HTTP 404 must log exactly once.',
);

$successfulStoredCronItems = $firstCronItems;
$successfulLookupCalls = 0;
$successfulWriteCalls = 0;
$successfulFailures = [];

RestoreManager::refreshCronItems(
    $successfulStoredCronItems,
    '2.1.2',
    static function (string $installedVersion) use (&$secondPin, &$successfulLookupCalls): array {
        $successfulLookupCalls++;

        return $secondPin;
    },
    static function (array $cronItems) use (&$successfulStoredCronItems, &$successfulWriteCalls): void {
        $successfulStoredCronItems = $cronItems;
        $successfulWriteCalls++;
    },
    static function (Throwable $throwable) use (&$successfulFailures): void {
        $successfulFailures[] = $throwable->getMessage();
    },
);

$successfulRestoreCronItems = restoreCronItems($successfulStoredCronItems);

assertRestoreSame(1, $successfulLookupCalls, 'A new installed version must perform one release lookup.');
assertRestoreSame(1, $successfulWriteCalls, 'A successful new-version lookup must write once.');
assertRestoreSame([], $successfulFailures, 'A successful new-version lookup must not log a failure.');
assertRestoreSame(1, count($successfulRestoreCronItems), 'A successful lookup must keep one tagged entry.');
assertRestoreSame(
    RestoreManager::buildCronCommand($secondPin),
    $successfulRestoreCronItems[0]['command'],
    'A successful lookup must replace the old entry with the new pin.',
);

$refreshedCronItems = RestoreManager::synchronizeCronItems($firstCronItems, $secondPin);
$refreshedRestoreCronItems = restoreCronItems($refreshedCronItems);
$refreshedCommand = $refreshedRestoreCronItems[0]['command'];

assertRestoreSame(1, count($refreshedRestoreCronItems), 'Refreshing a pin must replace instead of duplicate.');
assertRestoreSame(true, str_contains($refreshedCommand, '2.1.2'), 'The refreshed command must pin the new version.');
assertRestoreSame(true, str_contains($refreshedCommand, str_repeat('b', 64)), 'The refreshed command must pin the new SHA-256.');
assertRestoreSame(false, str_contains($refreshedCommand, '/latest/'), 'The recovery command must never use a latest URL.');

$deliberateRemovalCronItems = RestoreManager::removeCronItems($refreshedCronItems, false, false);

assertRestoreSame(0, count(restoreCronItems($deliberateRemovalCronItems)), 'A deliberate uninstall must remove recovery.');
assertRestoreSame([$otherCronItem], $deliberateRemovalCronItems, 'A deliberate uninstall must preserve other cron entries.');
assertRestoreSame(
    $refreshedCronItems,
    RestoreManager::removeCronItems($refreshedCronItems, true, false),
    'Restore-driven removal must keep recovery.',
);
assertRestoreSame(
    $refreshedCronItems,
    RestoreManager::removeCronItems($refreshedCronItems, false, true),
    'Keep Settings must keep recovery.',
);

$missingHashPin = $secondPin;
$missingHashPin['sha256'] = '';
$missingHashCronItems = RestoreManager::synchronizeCronItems($refreshedCronItems, $missingHashPin);

assertRestoreSame(0, count(restoreCronItems($missingHashCronItems)), 'An unverifiable release must leave no recovery entry.');
assertRestoreSame(
    null,
    RestoreManager::buildCronCommand($missingHashPin),
    'An unverifiable release must not produce a shell command.',
);

$syntaxOutput = [];
$syntaxExitCode = 0;

exec('/bin/sh -n -c ' . escapeshellarg($refreshedCommand) . ' 2>&1', $syntaxOutput, $syntaxExitCode);

assertRestoreSame(0, $syntaxExitCode, 'The generated recovery command must pass sh -n.');
assertRestoreSame(true, str_contains($refreshedCommand, '/conf/needs_package_sync'), 'The command must wait for stock package sync.');
assertRestoreSame(true, str_contains($refreshedCommand, 'pkg info -e'), 'The command must exit when the package is installed.');
assertRestoreSame(true, str_contains($refreshedCommand, $secondPin['url']), 'The command must contain the pinned release URL.');
assertRestoreSame(true, str_contains($refreshedCommand, $secondPin['sha256']), 'The command must contain the checksum.');
assertRestoreSame(true, str_contains($refreshedCommand, 'sha256 -q'), 'The command must verify SHA-256 before installation.');
assertRestoreSame(true, str_contains($refreshedCommand, 'pkg add -f'), 'The command must force-install the verified package.');
assertRestoreSame(true, str_contains($refreshedCommand, 'daemon -f'), 'The command must detach from cron.');
assertRestoreSame(
    true,
    strlen($refreshedCommand) <= RestoreManager::CRON_COMMAND_MAX_LENGTH,
    'The command must fit safely below the FreeBSD cron command limit.',
);

fwrite(STDOUT, "Configuration restore notices and recovery cron behavior passed.\n");

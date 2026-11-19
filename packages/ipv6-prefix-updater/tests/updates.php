<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Runtime\FileLock;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;
use PfSense\Ipv6PrefixUpdater\Runtime\UpdateManager;

require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/FileLock.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/Translator.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/UpdateManager.php');
require_once(__DIR__ . '/stubs/notices.inc');

/**
 * Asserts strict equality for update regression checks.
 *
 * @param mixed  $expected - Expected value.
 * @param mixed  $actual   - Actual value.
 * @param string $message  - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When values differ.
 *
 * @since 2.1.0
 */
function assertUpdateSame(mixed $expected, mixed $actual, string $message): void
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
 * Requires one callback to throw a RuntimeException with an exact message.
 *
 * @param callable $callback - Operation expected to fail.
 * @param string   $message  - Expected exception message.
 *
 * @return void
 *
 * @throws RuntimeException When the callback does not fail as expected.
 *
 * @since 2.1.0
 */
function assertUpdateFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $runtimeException) {
        assertUpdateSame($message, $runtimeException->getMessage(), 'The update failure message must be actionable.');

        return;
    }

    throw new RuntimeException('The update operation was expected to fail.');
}

/**
 * Creates one private directory for update signing tests.
 *
 * The generated directory contains only test-owned files and can be removed
 * without affecting package state or another concurrent test process.
 *
 * @return string
 *
 * @throws RuntimeException When the directory cannot be created.
 *
 * @since 2.1.0
 */
function makeUpdateTestDirectory(): string
{
    $directory = sys_get_temp_dir() . '/ipv6-prefix-updater-signing-' . bin2hex(random_bytes(16));

    if (mkdir($directory, 0700) === false) {
        throw new RuntimeException('Could not create the update signing test directory.');
    }

    return $directory;
}

/**
 * Removes files from one update signing test directory.
 *
 * Test fixtures remain in the repository while generated key files, stubs,
 * recordings, and tampered package copies are deleted after every test run.
 *
 * @param string $directory - Test-owned directory.
 *
 * @return void
 *
 * @since 2.1.0
 */
function removeUpdateTestDirectory(string $directory): void
{
    $entries = @scandir($directory);

    foreach ((is_array($entries) ? $entries : []) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $directory . '/' . $entry;

        if (is_file($entryPath) || is_link($entryPath)) {
            @unlink($entryPath);
        }
    }

    @rmdir($directory);
}

/**
 * Runs the package CLI with arguments that finish before pfSense integration.
 *
 * Parser regressions can inspect exact exit codes and translated errors without a
 * firewall because invalid combinations stop before environment checks begin.
 *
 * @param list<string> $arguments - CLI arguments after the executable name.
 *
 * @return array{exit_code: int, stdout: string, stderr: string}
 *
 * @throws RuntimeException When the PHP process cannot be started.
 *
 * @since 2.1.3
 */
function runUpdateCli(array $arguments): array
{
    $command = [
        PHP_BINARY,
        __DIR__ . '/../port/files/usr/local/sbin/ipv6-prefix-updater',
        ...$arguments,
    ];
    $descriptorSpecification = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($command, $descriptorSpecification, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the update CLI test process.');
    }

    fclose($pipes[0]);

    $standardOutput = stream_get_contents($pipes[1]);
    $standardError = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => is_string($standardOutput) ? $standardOutput : '',
        'stderr' => is_string($standardError) ? $standardError : '',
    ];
}

/**
 * Writes one ssh-keygen stub that records arguments and standard input.
 *
 * The executable simulates both successful and failed verification without
 * requiring OpenSSH for the process-boundary regression test.
 *
 * @param string $directory - Test-owned directory.
 * @param string $name      - Stub and recording file prefix.
 * @param int    $exitCode  - Stub exit code.
 *
 * @return array{command: string, arguments: string, stdin: string}
 *
 * @throws RuntimeException When the stub cannot be written or made executable.
 *
 * @since 2.1.0
 */
function writeUpdateSshKeygenStub(string $directory, string $name, int $exitCode): array
{
    $commandPath = $directory . '/' . $name;
    $argumentsPath = $directory . '/' . $name . '.arguments';
    $standardInputPath = $directory . '/' . $name . '.stdin';
    $stubLines = [
        '#!/bin/sh',
        'printf \'%s\\n\' "$@" > ' . escapeshellarg($argumentsPath),
        'cat > ' . escapeshellarg($standardInputPath),
        'exit ' . $exitCode,
        '',
    ];
    $stubContents = implode("\n", $stubLines);

    if (
        file_put_contents($commandPath, $stubContents) === false
        || chmod($commandPath, 0700) === false
    ) {
        throw new RuntimeException('Could not create the ssh-keygen test stub.');
    }

    return [
        'command' => $commandPath,
        'arguments' => $argumentsPath,
        'stdin' => $standardInputPath,
    ];
}

/**
 * Finds a real ssh-keygen executable for fixture verification.
 *
 * The fixed pfSense path is preferred, with PATH lookup allowing the same tests
 * to run on contributor workstations and continuous-integration hosts.
 *
 * @return string|null
 *
 * @since 2.1.0
 */
function findUpdateSshKeygen(): ?string
{
    if (is_file('/usr/bin/ssh-keygen') && is_executable('/usr/bin/ssh-keygen')) {
        return '/usr/bin/ssh-keygen';
    }

    $path = getenv('PATH');

    foreach ((is_string($path) ? explode(PATH_SEPARATOR, $path) : []) as $directory) {
        if ($directory === '') {
            continue;
        }

        $candidate = $directory . '/ssh-keygen';

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

assertUpdateSame(true, UpdateManager::isNewerVersion('2.0.1', 'v2.1.0'), 'A leading v must be ignored.');
assertUpdateSame(false, UpdateManager::isNewerVersion('v2.1.0', '2.1.0'), 'Equal normalized versions are current.');
assertUpdateSame(false, UpdateManager::isNewerVersion('2.2.0', 'v2.1.9'), 'An older release is not an update.');
assertUpdateSame(
    '/var/run/ipv6-prefix-updater-release.lock',
    PackagePaths::RELEASE_LOCK_FILE,
    'Release operations must use the package-named release lock path.',
);
assertUpdateSame(
    PackagePaths::UPDATE_CHECK_COMMAND . ' --no-notice',
    PackagePaths::MANUAL_UPDATE_CHECK_COMMAND,
    'The About tab command must add only the manual notice-suppression flag.',
);

$invalidNoNoticeResult = runUpdateCli(['--no-notice']);

assertUpdateSame(64, $invalidNoNoticeResult['exit_code'], '--no-notice without --check-updates must be a usage error.');
assertUpdateSame(
    Translator::translate('update_option_conflict'),
    trim($invalidNoNoticeResult['stderr']),
    'The invalid --no-notice combination must use the existing update usage error.',
);

$releaseJson = json_encode([
    'tag_name' => 'v2.1.0',
    'html_url' => 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/tag/v2.1.0',
    'published_at' => '2026-11-06T12:00:00Z',
    'assets' => [
        [
            'name' => 'pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg',
            'browser_download_url' => UpdateManager::DOWNLOAD_PREFIX . 'v2.1.0/pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg',
            'digest' => 'sha256:' . str_repeat('a', 64),
        ],
        [
            'name' => 'pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg.sig',
            'browser_download_url' => UpdateManager::DOWNLOAD_PREFIX . 'v2.1.0/pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg.sig',
            'digest' => null,
        ],
    ],
], JSON_THROW_ON_ERROR);
$release = UpdateManager::parseReleaseJson($releaseJson);

assertUpdateSame('2.1.0', $release['version'], 'Release parsing must normalize the Git tag.');
assertUpdateSame(2, count($release['assets']), 'Release parsing must retain valid assets.');
assertUpdateSame(
    true,
    UpdateManager::isAllowedAssetUrl($release['assets'][0]['url']),
    'Repository release assets must be accepted.',
);
assertUpdateSame(
    false,
    UpdateManager::isAllowedAssetUrl('https://example.com/pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg'),
    'Assets outside the configured repository must be rejected.',
);
assertUpdateSame(
    true,
    UpdateManager::isAllowedAssetUrl('http://vm.test/releases/package.pkg', 'http://vm.test/releases/'),
    'The explicit CLI test prefix must support a local VM endpoint.',
);
$apiDigestAssets = UpdateManager::selectUpdateAssets($release);

assertUpdateSame(
    'sha256:' . str_repeat('a', 64),
    $apiDigestAssets['digest'],
    'The GitHub API digest must be the preferred SHA-256 source.',
);
assertUpdateSame(null, $apiDigestAssets['checksum'], 'An API digest must not require a checksum asset.');
assertUpdateSame(
    'pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg.sig',
    $apiDigestAssets['signature']['name'],
    'The matching detached signature asset must be selected.',
);

$unsignedRelease = $release;
$unsignedRelease['assets'] = [$release['assets'][0]];
$unsignedAssets = UpdateManager::selectUpdateAssets($unsignedRelease);

assertUpdateSame(null, $unsignedAssets['signature'], 'A release without a signature must report no signature asset.');

$outsidePrefixRelease = $release;
$outsidePrefixRelease['assets'][1]['url'] = 'https://example.com/pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg.sig';

assertUpdateFailure(
    static fn (): array => UpdateManager::selectUpdateAssets($outsidePrefixRelease),
    'The release contains an asset URL outside the trusted repository path.',
);

$signedInstallableManager = new UpdateManager(
    installedVersion: '2.0.1',
    signingKeysFile: __DIR__ . '/fixtures/release-signing/release-signing-a.pub',
);
$unsignedInstallableManager = new UpdateManager(
    installedVersion: '2.0.1',
    signingKeysFile: __DIR__ . '/fixtures/release-signing/missing.pub',
);
$bareRelease = $release;
$bareRelease['assets'] = [];
$unpinnedRelease = $unsignedRelease;
$unpinnedRelease['assets'][0]['digest'] = null;

assertUpdateSame(
    true,
    $signedInstallableManager->releaseInstallable($release),
    'A release with its package, digest, and signature is installable.',
);
assertUpdateSame(
    false,
    $signedInstallableManager->releaseInstallable($bareRelease),
    'A release whose build hasn\'t attached files yet must not be offered.',
);
assertUpdateSame(
    false,
    $signedInstallableManager->releaseInstallable($unsignedRelease),
    'With signing keys installed, a release without its signature must not be offered.',
);
assertUpdateSame(
    true,
    $unsignedInstallableManager->releaseInstallable($unsignedRelease),
    'Without signing keys installed, a release needs no signature to be offered.',
);
assertUpdateSame(
    false,
    $unsignedInstallableManager->releaseInstallable($unpinnedRelease),
    'A release without a digest or checksum asset must not be offered.',
);

$randomValues = [17, 4, 2, 23];
$randomCalls = 0;
$randomInteger = static function (int $minimum, int $maximum) use (&$randomValues, &$randomCalls): int {
    $value = $randomValues[$randomCalls] ?? $minimum;
    $randomCalls++;

    if ($value < $minimum || $value > $maximum) {
        throw new RuntimeException('The deterministic random value is outside the requested range.');
    }

    return $value;
};
$schedule = UpdateManager::stableCronSchedule([], $randomInteger);

assertUpdateSame(
    ['minute' => 17, 'hour' => 4, 'weekday' => 2, 'monthday' => 23],
    $schedule,
    'A complete random schedule must be generated once.',
);
assertUpdateSame(4, $randomCalls, 'Every stable schedule component must be initialized.');

$reusedSchedule = UpdateManager::stableCronSchedule(
    array_map(static fn (int $value): string => (string) $value, $schedule),
    static function (): never {
        throw new RuntimeException('A valid stored schedule must not be randomized again.');
    },
);

assertUpdateSame($schedule, $reusedSchedule, 'A valid stored schedule must stay stable.');
assertUpdateSame(['17', '4', '*', '*', '*'], UpdateManager::cronFields('daily', $schedule), 'Daily mapping must use minute and hour.');
assertUpdateSame(['17', '4', '*', '*', '2'], UpdateManager::cronFields('weekly', $schedule), 'Weekly mapping must use the weekday.');
assertUpdateSame(['17', '4', '23', '*', '*'], UpdateManager::cronFields('monthly', $schedule), 'Monthly mapping must use the month day.');
assertUpdateSame(null, UpdateManager::cronFields('never', $schedule), 'Never must remove the cron schedule.');

$noticeState = [
    'notified_version' => null,
];
$manualNoticeState = UpdateManager::synchronizeNotice($noticeState, '2.0.1', '2.1.0', true, false);

assertUpdateSame('2.1.0', $manualNoticeState['notified_version'], 'A manual check must record the version as seen.');
assertUpdateSame(0, count($noticeTestFileCalls), 'A manual check must not file a local or remote notice.');

$manualNoticeState = UpdateManager::synchronizeNotice($manualNoticeState, '2.0.1', '2.1.0', true, true);

assertUpdateSame(0, count($noticeTestFileCalls), 'A later scheduled check must not announce the manually seen version.');

$noticeState = UpdateManager::synchronizeNotice($noticeState, '2.0.1', '2.1.0', false, true);

assertUpdateSame('2.1.0', $noticeState['notified_version'], 'The noticed version must be persisted.');
assertUpdateSame(1, count($noticeTestFileCalls), 'A new version must create one notice.');
assertUpdateSame(
    'IPv6 Prefix Updater 2.1.0 is available.',
    $noticeTestFileCalls[0]['notice'],
    'The bell notice must use plain localized package and version text.',
);
assertUpdateSame('IPv6 Prefix Updater', $noticeTestFileCalls[0]['category'], 'The notice category must be the package name.');
assertUpdateSame(true, $noticeTestFileCalls[0]['localOnly'], 'Remote notifications must be off by default.');
assertUpdateSame(UpdateManager::ABOUT_PAGE, $noticeTestFileCalls[0]['url'], 'The notice must link to the About tab.');

$noticeState = UpdateManager::synchronizeNotice($noticeState, '2.0.1', '2.1.0', true, true);
assertUpdateSame(1, count($noticeTestFileCalls), 'The same version must not create a duplicate notice.');

$noticeState = UpdateManager::synchronizeNotice($noticeState, '2.0.1', '2.2.0', true, true);
assertUpdateSame(2, count($noticeTestFileCalls), 'A later version must create one new notice.');
assertUpdateSame(false, $noticeTestFileCalls[1]['localOnly'], 'The opt-in must allow pfSense remote notifications.');

UpdateManager::synchronizeNotice($noticeState, '2.2.0', '2.2.0', true, true);
assertUpdateSame(
    UpdateManager::NOTICE_ID,
    $noticeTestCloseCalls[array_key_last($noticeTestCloseCalls)],
    'The notice must close when the installed version is current.',
);

$digestPath = tempnam(sys_get_temp_dir(), 'ipv6-prefix-updater-digest-');

if ($digestPath === false) {
    throw new RuntimeException('Could not create the digest test file.');
}

file_put_contents($digestPath, 'package payload');
$digest = 'sha256:' . hash_file('sha256', $digestPath);

assertUpdateSame(true, UpdateManager::verifyDigest($digestPath, $digest), 'A matching SHA-256 digest must pass.');
assertUpdateSame(
    false,
    UpdateManager::verifyDigest($digestPath, 'sha256:' . str_repeat('0', 64)),
    'A package with a mismatched SHA-256 digest must be refused.',
);
$checksumAssetName = 'pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg.sha256';
$checksumRelease = [
    'version' => '2.1.0',
    'assets' => [
        [
            'name' => 'pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg',
            'url' => UpdateManager::DOWNLOAD_PREFIX . 'v2.1.0/pfSense-pkg-Ipv6PrefixUpdater-2.1.0.pkg',
            'digest' => null,
        ],
        [
            'name' => $checksumAssetName,
            'url' => UpdateManager::DOWNLOAD_PREFIX . 'v2.1.0/' . $checksumAssetName,
            'digest' => null,
        ],
    ],
];
$checksumAssets = UpdateManager::selectUpdateAssets($checksumRelease);
$checksumHash = hash_file('sha256', $digestPath);

assertUpdateSame(null, $checksumAssets['digest'], 'A release without an API digest must use the checksum fallback.');
assertUpdateSame(
    $checksumAssetName,
    $checksumAssets['checksum']['name'],
    'The fallback must select the same-release package checksum asset.',
);
assertUpdateSame(
    'sha256:' . $checksumHash,
    UpdateManager::checksumDigest($checksumHash . PHP_EOL),
    'A hash-only .sha256 file must produce the expected digest.',
);
assertUpdateSame(
    true,
    UpdateManager::verifyDigest($digestPath, (string) UpdateManager::checksumDigest($checksumHash . PHP_EOL)),
    'The .sha256 fallback digest must verify the package.',
);
assertUpdateSame(
    null,
    UpdateManager::checksumDigest($checksumHash . '  package.pkg'),
    'A checksum file with extra fields must fail closed.',
);

$signingFixturesDirectory = __DIR__ . '/fixtures/release-signing';
$packageFixturePath = $signingFixturesDirectory . '/pfSense-pkg-Ipv6PrefixUpdater-9.9.9.pkg';
$signatureFixturePath = $packageFixturePath . '.sig';
$keyBSignatureFixturePath = $packageFixturePath . '.key-b.sig';
$wrongNamespaceSignatureFixturePath = $packageFixturePath . '.wrong-namespace.sig';
$keyAFixturePath = $signingFixturesDirectory . '/release-signing-a.pub';
$keyBFixturePath = $signingFixturesDirectory . '/release-signing-b.pub';
$keyA = UpdateManager::readSigningKeys($keyAFixturePath)[0];
$keyB = UpdateManager::readSigningKeys($keyBFixturePath)[0];
$signingTestDirectory = makeUpdateTestDirectory();

try {
    assertUpdateSame(
        [],
        UpdateManager::readSigningKeys($signingTestDirectory . '/missing.pub'),
        'A missing release signing key file must keep unsigned updates compatible.',
    );

    $commentOnlyPath = $signingTestDirectory . '/comments.pub';

    file_put_contents($commentOnlyPath, "# Comment\n\n  # Indented comment\n");

    assertUpdateSame(
        [],
        UpdateManager::readSigningKeys($commentOnlyPath),
        'A comment-only release signing key file must contain no trusted keys.',
    );
    assertUpdateSame(
        [$keyA],
        UpdateManager::readSigningKeys($keyAFixturePath),
        'One valid release signing key must return its base64 key data.',
    );

    $twoKeysPath = $signingTestDirectory . '/two-keys.pub';
    $twoKeysContents = "# First key\r\n\r\n"
        . "ssh-ed25519 $keyA first key\r\n"
        . "  # Between keys\r\n"
        . "ssh-ed25519 $keyB second key\r\n";

    file_put_contents($twoKeysPath, $twoKeysContents);

    assertUpdateSame(
        [$keyA, $keyB],
        UpdateManager::readSigningKeys($twoKeysPath),
        'CRLF, comments, and two valid release signing keys must be accepted.',
    );

    $malformedKeyPath = $signingTestDirectory . '/malformed.pub';

    file_put_contents($malformedKeyPath, 'ssh-rsa invalid' . PHP_EOL);

    assertUpdateFailure(
        static fn (): array => UpdateManager::readSigningKeys($malformedKeyPath),
        'The installed release signing key file is unreadable or malformed, so the update wasn\'t installed.',
    );

    $shortKeyPath = $signingTestDirectory . '/short-key.pub';

    file_put_contents($shortKeyPath, 'ssh-ed25519 ' . substr($keyA, 0, -1) . PHP_EOL);

    assertUpdateFailure(
        static fn (): array => UpdateManager::readSigningKeys($shortKeyPath),
        'The installed release signing key file is unreadable or malformed, so the update wasn\'t installed.',
    );

    $expectedAllowedSigners = UpdateManager::PACKAGE_NAME
        . ' namespaces="'
        . UpdateManager::PACKAGE_NAME
        . '" ssh-ed25519 '
        . $keyA
        . "\n"
        . UpdateManager::PACKAGE_NAME
        . ' namespaces="'
        . UpdateManager::PACKAGE_NAME
        . '" ssh-ed25519 '
        . $keyB
        . "\n";

    assertUpdateSame(
        $expectedAllowedSigners,
        UpdateManager::allowedSignersContent([$keyA, $keyB]),
        'Allowed signers content must restrict every key to the package namespace.',
    );

    $successfulStub = writeUpdateSshKeygenStub($signingTestDirectory, 'ssh-keygen-success', 0);
    $stubManager = new UpdateManager(
        installedVersion: '9.9.8',
        signingKeysFile: $keyAFixturePath,
        sshKeygenCommand: $successfulStub['command'],
    );

    $stubManager->verifyReleaseSignature(
        $packageFixturePath,
        $signatureFixturePath,
        [$keyA],
        $signingTestDirectory,
    );

    $expectedStubArguments = implode(PHP_EOL, [
        '-Y',
        'verify',
        '-f',
        $signingTestDirectory . '/allowed_signers',
        '-I',
        UpdateManager::PACKAGE_NAME,
        '-n',
        UpdateManager::PACKAGE_NAME,
        '-s',
        $signatureFixturePath,
    ]) . PHP_EOL;

    assertUpdateSame(
        $expectedStubArguments,
        file_get_contents($successfulStub['arguments']),
        'Signature verification must pass the exact ssh-keygen argument vector.',
    );
    assertUpdateSame(
        file_get_contents($packageFixturePath),
        file_get_contents($successfulStub['stdin']),
        'Signature verification must stream the downloaded package bytes to ssh-keygen.',
    );
    assertUpdateSame(
        0600,
        fileperms($signingTestDirectory . '/allowed_signers') & 0777,
        'The generated allowed-signers file must use private permissions.',
    );

    $failingStub = writeUpdateSshKeygenStub($signingTestDirectory, 'ssh-keygen-failure', 1);
    $failingStubManager = new UpdateManager(
        installedVersion: '9.9.8',
        signingKeysFile: $keyAFixturePath,
        sshKeygenCommand: $failingStub['command'],
    );

    assertUpdateFailure(
        static function () use (
            $failingStubManager,
            $packageFixturePath,
            $signatureFixturePath,
            $keyA,
            $signingTestDirectory,
        ): void {
            $failingStubManager->verifyReleaseSignature(
                $packageFixturePath,
                $signatureFixturePath,
                [$keyA],
                $signingTestDirectory,
            );
        },
        'The release signature doesn\'t match this package\'s signing key, so the update wasn\'t installed.',
    );
    assertUpdateSame(
        $expectedStubArguments,
        file_get_contents($failingStub['arguments']),
        'A failed signature check must use the exact ssh-keygen argument vector.',
    );
    assertUpdateSame(
        file_get_contents($packageFixturePath),
        file_get_contents($failingStub['stdin']),
        'A failed signature check must still stream the package bytes to ssh-keygen.',
    );

    $missingToolManager = new UpdateManager(
        installedVersion: '9.9.8',
        signingKeysFile: $keyAFixturePath,
        sshKeygenCommand: $signingTestDirectory . '/missing-ssh-keygen',
    );

    assertUpdateFailure(
        static function () use (
            $missingToolManager,
            $packageFixturePath,
            $signatureFixturePath,
            $keyA,
            $signingTestDirectory,
        ): void {
            $missingToolManager->verifyReleaseSignature(
                $packageFixturePath,
                $signatureFixturePath,
                [$keyA],
                $signingTestDirectory,
            );
        },
        'The required ssh-keygen signature verification tool is unavailable, so the update wasn\'t installed.',
    );

    $realSshKeygen = findUpdateSshKeygen();

    if ($realSshKeygen === null) {
        fwrite(STDOUT, "SKIP: ssh-keygen is unavailable; real release signature fixtures were not verified.\n");
    } else {
        $realSignatureManager = new UpdateManager(
            installedVersion: '9.9.8',
            signingKeysFile: $keyAFixturePath,
            sshKeygenCommand: $realSshKeygen,
        );

        $realSignatureManager->verifyReleaseSignature(
            $packageFixturePath,
            $signatureFixturePath,
            [$keyA],
            $signingTestDirectory,
        );

        assertUpdateFailure(
            static function () use (
                $realSignatureManager,
                $packageFixturePath,
                $keyBSignatureFixturePath,
                $keyA,
                $signingTestDirectory,
            ): void {
                $realSignatureManager->verifyReleaseSignature(
                    $packageFixturePath,
                    $keyBSignatureFixturePath,
                    [$keyA],
                    $signingTestDirectory,
                );
            },
            'The release signature doesn\'t match this package\'s signing key, so the update wasn\'t installed.',
        );
        assertUpdateFailure(
            static function () use (
                $realSignatureManager,
                $packageFixturePath,
                $wrongNamespaceSignatureFixturePath,
                $keyA,
                $signingTestDirectory,
            ): void {
                $realSignatureManager->verifyReleaseSignature(
                    $packageFixturePath,
                    $wrongNamespaceSignatureFixturePath,
                    [$keyA],
                    $signingTestDirectory,
                );
            },
            'The release signature doesn\'t match this package\'s signing key, so the update wasn\'t installed.',
        );

        $tamperedPackagePath = $signingTestDirectory . '/tampered.pkg';
        $packageFixtureContents = file_get_contents($packageFixturePath);

        file_put_contents($tamperedPackagePath, $packageFixtureContents . 'tampered');

        assertUpdateFailure(
            static function () use (
                $realSignatureManager,
                $tamperedPackagePath,
                $signatureFixturePath,
                $keyA,
                $signingTestDirectory,
            ): void {
                $realSignatureManager->verifyReleaseSignature(
                    $tamperedPackagePath,
                    $signatureFixturePath,
                    [$keyA],
                    $signingTestDirectory,
                );
            },
            'The release signature doesn\'t match this package\'s signing key, so the update wasn\'t installed.',
        );
    }
} finally {
    removeUpdateTestDirectory($signingTestDirectory);
}

$missingChecksumRelease = $checksumRelease;
$missingChecksumRelease['assets'] = [$checksumRelease['assets'][0]];

assertUpdateFailure(
    static fn (): array => UpdateManager::selectUpdateAssets($missingChecksumRelease),
    'The release provides neither a GitHub SHA-256 digest nor the required checksum asset '
        . $checksumAssetName
        . '. The installed version was kept.',
);

@unlink($digestPath);

$lockTestDirectory = makeUpdateTestDirectory();
$packageLockPath = $lockTestDirectory . '/ipv6-prefix-updater.lock';
$releaseLockPath = $lockTestDirectory . '/ipv6-prefix-updater-release.lock';
$lockStatusPath = $lockTestDirectory . '/update-status.json';
$packageLockOwner = new FileLock($packageLockPath);
$checkReleaseLock = new FileLock($releaseLockPath);
$releaseLockOwner = new FileLock($releaseLockPath);
$releaseLockContender = new FileLock($releaseLockPath);
$selfUpdateReleaseLock = new FileLock($releaseLockPath);
$selfUpdatePackageLock = new FileLock($packageLockPath);

try {
    assertUpdateSame(true, $packageLockOwner->acquire(), 'The prefix task must own the package lock for concurrency tests.');
    assertUpdateSame(
        true,
        $checkReleaseLock->acquire(0),
        'A release check must acquire its separate lock while the package lock is held.',
    );

    $checkReleaseLock->release();

    assertUpdateSame(true, $releaseLockOwner->acquire(), 'The first release operation must acquire the release lock.');
    assertUpdateSame(
        false,
        $releaseLockContender->acquire(0),
        'A second release operation must stop after its injected zero-second wait.',
    );
    assertUpdateSame(
        'An update check or install is already running.',
        Translator::translate('release_operation_running'),
        'A busy release lock must report the release-operation message.',
    );

    $releaseLockOwner->release();

    UpdateManager::queueSelfUpdate('2.2.0', $lockStatusPath);

    assertUpdateSame(true, $selfUpdateReleaseLock->acquire(), 'A self-update must acquire the release lock first.');
    assertUpdateSame(
        false,
        $selfUpdatePackageLock->acquire(0),
        'A self-update must wait on a package lock held by the prefix task.',
    );

    UpdateManager::markQueuedSelfUpdateWaiting($lockStatusPath);

    $waitingStatus = UpdateManager::readStatusState($lockStatusPath, '2.1.0');

    assertUpdateSame('running', $waitingStatus['state'] ?? null, 'The queued self-update must remain running while it waits.');
    assertUpdateSame(
        'Waiting for the running prefix update to finish.',
        $waitingStatus['message'] ?? null,
        'The About tab must show that the self-update is waiting for the prefix task.',
    );
    assertUpdateSame(
        false,
        $selfUpdatePackageLock->acquire(0),
        'The injected zero-second package-lock wait must expire while the prefix task still owns the lock.',
    );

    $taskStillRunningMessage = Translator::translate('update_task_still_running');

    UpdateManager::failQueuedSelfUpdate($taskStillRunningMessage, $lockStatusPath);

    $failedWaitingStatus = UpdateManager::readStatusState($lockStatusPath, '2.1.0');

    assertUpdateSame('failed', $failedWaitingStatus['state'] ?? null, 'A timed-out self-update wait must fail the queued job.');
    assertUpdateSame(
        'A prefix update is still running. Try again when it finishes.',
        $failedWaitingStatus['message'] ?? null,
        'The timed-out self-update wait must report the running prefix task.',
    );
} finally {
    $selfUpdatePackageLock->release();
    $selfUpdateReleaseLock->release();
    $releaseLockContender->release();
    $releaseLockOwner->release();
    $checkReleaseLock->release();
    $packageLockOwner->release();

    removeUpdateTestDirectory($lockTestDirectory);
}

$statusTestDirectory = makeUpdateTestDirectory();
$statusTestPath = $statusTestDirectory . '/update-status.json';

try {
    UpdateManager::queueSelfUpdate('2.2.0', $statusTestPath);

    $runningStatus = UpdateManager::readStatusState($statusTestPath, '2.1.0');

    assertUpdateSame('running', $runningStatus['state'] ?? null, 'An install must stay running until pfSense reports the new version.');

    $settledStatus = UpdateManager::readStatusState($statusTestPath, 'v2.2.0');

    assertUpdateSame('succeeded', $settledStatus['state'] ?? null, 'A running install must settle once pfSense reports its version.');
    assertUpdateSame('2.2.0', $settledStatus['version'] ?? null, 'The settled status must keep the installed version.');
    assertUpdateSame(true, is_string($settledStatus['finished_at'] ?? null), 'The settled status must record when it finished.');

    $savedStatus = UpdateManager::readStatusState($statusTestPath, '2.1.0');

    assertUpdateSame('succeeded', $savedStatus['state'] ?? null, 'The settled status must be saved for later reads.');
} finally {
    removeUpdateTestDirectory($statusTestDirectory);
}

fwrite(STDOUT, "Update checks, notices, schedules, checksums, and signatures passed.\n");

exit(0);

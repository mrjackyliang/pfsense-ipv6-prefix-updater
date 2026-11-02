<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationChangePlanner;
use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationTargetScanner;
use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;
use PfSense\Ipv6PrefixUpdater\Domain\Ipv6Prefix;
use PfSense\Ipv6PrefixUpdater\Domain\LegacyInstallation;
use PfSense\Ipv6PrefixUpdater\Domain\MonitoredInterfaces;
use Throwable;

require_once(__DIR__ . '/BackupStore.php');
require_once(__DIR__ . '/ConfigurationChangePlanner.php');
require_once(__DIR__ . '/ConfigurationTargetScanner.php');
require_once(__DIR__ . '/FieldSelection.php');
require_once(__DIR__ . '/Ipv6Prefix.php');
require_once(__DIR__ . '/LegacyInstallation.php');
require_once(__DIR__ . '/MonitoredInterfaces.php');
require_once(__DIR__ . '/PackagePaths.php');
require_once(__DIR__ . '/PlatformAdapter.php');
require_once(__DIR__ . '/StateStore.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Moves a firewall from the version 1.x script to the package without user steps.
 *
 * The migrator reads the old Cron entries, saved prefixes, and backups, then turns
 * them into package settings, baselines, and package backups. Every change follows a
 * configuration backup, and old files are removed only after what replaces them is in
 * place, so an interruption at any point leaves either the script or the package in
 * charge, never neither.
 *
 * @since 2.0.0
 */
final class LegacyMigrator
{
    /**
     * Settings record written by the package WebGUI.
     *
     * @since 2.0.0
     */
    private const SETTINGS_PATH = 'installedpackages/ipv6prefixupdater/config';

    /**
     * Creates a migrator.
     *
     * @param PlatformAdapter $platformAdapter   - Platform adapter.
     * @param StateStore      $stateStore        - Package state store.
     * @param BackupStore     $backupStore       - Package backup store.
     * @param string          $legacyRoot        - Directory that held the 1.x files.
     * @param int             $activeLockSeconds - Age below which the 1.x lock counts as a running script.
     *
     * @since 2.0.0
     */
    public function __construct(
        private readonly PlatformAdapter $platformAdapter,
        private readonly StateStore $stateStore,
        private readonly BackupStore $backupStore,
        private readonly string $legacyRoot = PackagePaths::LEGACY_ROOT,
        private readonly int $activeLockSeconds = 600,
    ) {
    }

    /**
     * Lists every version 1.x leftover without changing anything.
     *
     * @return array{
     *     found: bool,
     *     downloads: list<string>,
     *     cron_entries: list<array>,
     *     cache_files: list<array>,
     *     backup_files: list<array>,
     *     other_backup_files: int,
     *     script_directory: array{path: string, present: bool, retired_stub: bool},
     *     cache_directory: array{path: string, present: bool},
     *     backup_directory: array{path: string, present: bool},
     *     lock: array{present: bool, age_seconds: int|null, active: bool}
     * }
     *
     * @since 2.0.0
     */
    public function inspect(): array
    {
        $cronEntries = [];
        $cronItems = $this->platformAdapter->getConfigurationValue('cron/item');

        foreach ((is_array($cronItems) ? $cronItems : []) as $index => $item) {
            $command = is_array($item) ? (string) ($item['command'] ?? '') : '';

            if (LegacyInstallation::isLegacyCommand($command) === false) {
                continue;
            }

            $arguments = LegacyInstallation::parseCommand($command);
            $cronEntries[] = [
                'index' => $index,
                'command' => $command,
                'device' => $arguments['device'] ?? null,
                'prefix_length' => $arguments['prefix_length'] ?? null,
                'interface' => ($arguments !== null)
                    ? $this->platformAdapter->findInterfaceByDevice($arguments['device'])
                    : null,
                'schedule' => LegacyInstallation::scheduleFor(
                    (string) ($item['minute'] ?? '*'),
                    (string) ($item['hour'] ?? '*'),
                    (string) ($item['mday'] ?? '*'),
                    (string) ($item['month'] ?? '*'),
                    (string) ($item['wday'] ?? '*'),
                ),
            ];
        }

        $cacheFiles = [];

        foreach ($this->listFiles($this->cacheDirectory(), '.cache') as $path) {
            $device = basename($path, '.cache');
            $contents = @file_get_contents($path);
            $cache = is_string($contents) ? LegacyInstallation::parseCache($contents) : null;
            $modifiedAt = @filemtime($path);
            $cacheFiles[] = [
                'path' => $path,
                'device' => $device,
                'prefix' => $cache['prefix'] ?? null,
                'prefix_length' => $cache['prefix_length'] ?? null,
                'interface' => $this->platformAdapter->findInterfaceByDevice($device),
                'modified_at' => ($modifiedAt === false) ? null : $modifiedAt,
            ];
        }

        $backupFiles = [];
        $otherBackupFiles = 0;

        foreach ($this->listFiles($this->backupDirectory(), '') as $path) {
            $name = basename($path);
            $head = @file_get_contents($path, false, null, 0, 4096);

            // Only pfSense configurations are imported; anything else the operator kept
            // there stays put and never triggers another migration.
            if (str_ends_with($name, '.xml') === false || !is_string($head) || stripos($head, '<pfsense') === false) {
                $otherBackupFiles++;
                continue;
            }

            $modifiedAt = @filemtime($path);
            $createdAt = ($modifiedAt === false) ? time() : $modifiedAt;
            $device = null;

            if (preg_match('/^([0-9A-Za-z._]+)-([0-9]{9,11})\.xml$/', $name, $matches) === 1) {
                $device = $matches[1];
                $createdAt = (int) $matches[2];
            }

            $backupFiles[] = [
                'path' => $path,
                'name' => $name,
                'device' => $device,
                'created_at' => $createdAt,
                'size' => (int) @filesize($path),
            ];
        }

        $lockPath = $this->scriptDirectory() . '/update.lock';
        $lockModifiedAt = file_exists($lockPath) ? @filemtime($lockPath) : false;
        $lockAge = ($lockModifiedAt === false) ? null : max(0, time() - $lockModifiedAt);
        $scriptPresent = is_dir($this->scriptDirectory()) || is_link($this->scriptDirectory());
        $entryPoint = @file_get_contents($this->scriptDirectory() . '/update.php', false, null, 0, 8192);
        $cacheDirectoryPresent = is_dir($this->cacheDirectory());
        $backupDirectoryPresent = is_dir($this->backupDirectory());
        $downloads = array_values(array_filter(
            $this->downloadPaths(),
            static fn (string $path): bool => is_file($path) || is_link($path),
        ));

        return [
            'found' => count($cronEntries) > 0
                || $scriptPresent
                || $cacheDirectoryPresent
                || count($backupFiles) > 0
                || count($downloads) > 0,
            'downloads' => $downloads,
            'cron_entries' => $cronEntries,
            'cache_files' => $cacheFiles,
            'backup_files' => $backupFiles,
            'other_backup_files' => $otherBackupFiles,
            'script_directory' => [
                'path' => $this->scriptDirectory(),
                'present' => $scriptPresent,
                'retired_stub' => is_string($entryPoint) && str_contains($entryPoint, 'ipv6-prefix-updater-legacy-retired'),
            ],
            'cache_directory' => [
                'path' => $this->cacheDirectory(),
                'present' => $cacheDirectoryPresent,
            ],
            'backup_directory' => [
                'path' => $this->backupDirectory(),
                'present' => $backupDirectoryPresent,
            ],
            'lock' => [
                'present' => $lockAge !== null,
                'age_seconds' => $lockAge,
                'active' => $lockAge !== null && $lockAge < $this->activeLockSeconds,
            ],
        ];
    }

    /**
     * Moves every version 1.x leftover into the package.
     *
     * The order is fixed: back up, record baselines, write settings and remove the old
     * Cron entries in one configuration write, then import backups and delete old
     * files. A script that is running right now defers the move to the next check.
     *
     * @return array{status: string, steps: list<array>, warnings: list<array>}
     *
     * @throws Throwable When a backup, state write, or configuration write fails.
     *
     * @since 2.0.0
     */
    public function migrate(): array
    {
        $findings = $this->inspect();

        if ($findings['found'] === false) {
            return [
                'status' => 'none',
                'steps' => [],
                'warnings' => [],
            ];
        }

        if ($findings['lock']['active']) {
            $report = [
                'status' => 'deferred',
                'steps' => [],
                'warnings' => [
                    self::message('legacy_deferred', [(int) $findings['lock']['age_seconds']]),
                ],
            ];

            $this->record($report);
            $this->platformAdapter->log(Translator::translate('legacy_deferred', [(int) $findings['lock']['age_seconds']]));

            return $report;
        }

        $steps = [];
        $warnings = [];
        $candidates = $this->collectCandidates($findings, $warnings);
        $savedSettings = $this->platformAdapter->getSettings();
        $settingsSteps = [];
        $settings = $this->mergeSettings($savedSettings, count($savedSettings) > 0, $candidates, $findings, $settingsSteps);
        $remainingCronItems = [];
        $removedCronItems = 0;
        $cronItems = $this->platformAdapter->getConfigurationValue('cron/item');

        foreach ((is_array($cronItems) ? $cronItems : []) as $item) {
            $command = is_array($item) ? (string) ($item['command'] ?? '') : '';

            if (LegacyInstallation::isLegacyCommand($command)) {
                $removedCronItems++;
                continue;
            }

            $remainingCronItems[] = $item;
        }

        $configurationChanged = $removedCronItems > 0 || $settings != $savedSettings;

        if ($configurationChanged) {
            $this->platformAdapter->backupConfiguration();
            $this->backupStore->create(
                $this->platformAdapter->getConfigurationFilePath(),
                'migration',
                array_keys($candidates),
                Translator::translate('legacy_backup_summary'),
            );
            $steps[] = self::message('legacy_step_backup');
        }

        $this->seedBaselines($settings, $candidates, $steps);

        if ($configurationChanged) {
            $this->platformAdapter->setConfigurationValue(self::SETTINGS_PATH, [$settings]);

            if ($removedCronItems > 0) {
                $this->platformAdapter->setConfigurationValue('cron/item', $remainingCronItems);
            }

            $this->platformAdapter->writeConfiguration(Translator::translate('legacy_revision_description'));

            array_push($steps, ...$settingsSteps);
        }

        if ($removedCronItems > 0) {
            $this->platformAdapter->reloadCron();
            $steps[] = self::message('legacy_step_cron_removed', [$removedCronItems]);
        }

        $this->importBackups($findings, $candidates, $steps, $warnings);
        $this->removeCache($findings, $steps, $warnings);

        if ($findings['script_directory']['present']) {
            if ($this->removeTree($this->scriptDirectory())) {
                $steps[] = self::message('legacy_step_script_removed', [$this->scriptDirectory()]);
            } else {
                $warnings[] = self::message('legacy_remove_failed', [$this->scriptDirectory()]);
            }
        }

        $removedDownloads = 0;

        foreach ($findings['downloads'] as $download) {
            if (@unlink($download)) {
                $removedDownloads++;
            } else {
                $warnings[] = self::message('legacy_remove_failed', [$download]);
            }
        }

        if ($removedDownloads > 0) {
            $steps[] = self::message('legacy_step_downloads_removed', [$removedDownloads]);
        }

        $report = [
            'status' => (count($warnings) > 0) ? 'completed_with_warnings' : 'completed',
            'steps' => $steps,
            'warnings' => $warnings,
        ];

        $this->record($report);

        $notice = Translator::translate('legacy_notice', [count($steps), count($warnings)]);

        $this->platformAdapter->log($notice);

        // A pass that changed nothing (for example, a folder that cannot be deleted) is
        // shown on the Cleanup tab only, so scheduled checks do not repeat the notice.
        if (count($steps) > 0) {
            try {
                $this->platformAdapter->notice('legacy-migration', $notice, 1, true, '/ipv6-prefix-updater-cleanup.php');
            } catch (Throwable) {
                $this->platformAdapter->log(Translator::translate('notice_failed'));
            }
        }

        return $report;
    }

    /**
     * Builds one monitored interface per device the 1.x script watched.
     *
     * Cron entries come first because they hold the configured prefix length. Cache
     * files fill in interfaces whose Cron entry was already deleted. Devices that no
     * longer belong to an interface are reported instead of guessed.
     *
     * @param array $findings - Inspection result.
     * @param array $warnings - Warnings, updated in place.
     *
     * @return array<string, array{interface: string, device: string, prefix_length: int, cache: array|null}>
     *
     * @since 2.0.0
     * @private
     */
    private function collectCandidates(array $findings, array &$warnings): array
    {
        $candidates = [];
        $unmappedDevices = [];

        foreach ($findings['cron_entries'] as $cronEntry) {
            if ($cronEntry['device'] === null) {
                $warnings[] = self::message('legacy_cron_unreadable', [$cronEntry['command']]);
                continue;
            }

            if ($cronEntry['interface'] === null) {
                $unmappedDevices[$cronEntry['device']] = true;
                continue;
            }

            $candidates[$cronEntry['interface']] ??= [
                'interface' => $cronEntry['interface'],
                'device' => $cronEntry['device'],
                'prefix_length' => $cronEntry['prefix_length'],
                'cache' => null,
            ];
        }

        foreach ($findings['cache_files'] as $cacheFile) {
            if ($cacheFile['prefix'] === null) {
                $warnings[] = self::message('legacy_cache_unreadable', [$cacheFile['device']]);
                continue;
            }

            if ($cacheFile['interface'] === null) {
                $unmappedDevices[$cacheFile['device']] = true;
                continue;
            }

            $interface = $cacheFile['interface'];
            $candidates[$interface] ??= [
                'interface' => $interface,
                'device' => $cacheFile['device'],
                'prefix_length' => $cacheFile['prefix_length'],
                'cache' => null,
            ];

            if ($candidates[$interface]['device'] === $cacheFile['device']) {
                $candidates[$interface]['cache'] = $cacheFile;
            }
        }

        foreach (array_keys($unmappedDevices) as $device) {
            $warnings[] = self::message('legacy_device_unmapped', [$device]);
        }

        return $candidates;
    }

    /**
     * Adds the 1.x interfaces to the package settings.
     *
     * Interfaces the operator already configured keep their settings. A firewall with
     * no package settings gets the defaults plus the 1.x schedule, and scheduling is
     * turned on whenever the script was still scheduled, so checks never stop.
     *
     * @param array $settings        - Saved settings.
     * @param bool  $settingsExisted - Whether settings were saved before.
     * @param array $candidates      - Interfaces from the 1.x script.
     * @param array $findings        - Inspection result.
     * @param array $steps           - Steps, updated in place.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private function mergeSettings(
        array $settings,
        bool $settingsExisted,
        array $candidates,
        array $findings,
        array &$steps,
    ): array {
        $rows = [];

        foreach (MonitoredInterfaces::fromSettings($settings) as $row) {
            if (is_string($row['interface']) && $row['interface'] !== '') {
                $rows[$row['interface']] = [
                    'interface' => $row['interface'],
                    'prefix_length' => (string) $row['prefix_length'],
                ];
            }
        }

        foreach ($candidates as $interface => $candidate) {
            if (isset($rows[$interface])) {
                continue;
            }

            $rows[$interface] = [
                'interface' => $interface,
                'prefix_length' => (string) $candidate['prefix_length'],
            ];
            $steps[] = self::message('legacy_step_monitor', [$interface, $candidate['prefix_length'], $candidate['device']]);
        }

        // New settings get no field choices, which the Fields tab and runs read as
        // Auto-select: every Recommended group on and Other Settings off.
        if ($settingsExisted === false) {
            $settings = [
                'enabled' => '',
                'update_frequency' => 'hourly',
                'notifications' => 'on',
            ];
        }

        $schedules = array_column($findings['cron_entries'], 'schedule');

        if ($settingsExisted === false && count($schedules) > 0) {
            $scheduleLabels = [
                'every_fifteen_minutes' => 'every_15_minutes',
                'hourly' => 'hourly',
                'every_six_hours' => 'every_6_hours',
                'daily' => 'daily_midnight',
            ];
            $order = array_keys($scheduleLabels);
            $settings['update_frequency'] = $order[min(array_map(
                static fn (string $schedule): int => (int) array_search($schedule, $order, true),
                $schedules,
            ))];
            $steps[] = self::message('legacy_step_schedule', [$scheduleLabels[$settings['update_frequency']]]);
        }

        if (count($findings['cron_entries']) > 0 && count($rows) > 0 && ($settings['enabled'] ?? '') !== 'on') {
            $settings['enabled'] = 'on';
            $steps[] = self::message('legacy_step_enabled');
        }

        $settings['row'] = array_values($rows);

        return $settings;
    }

    /**
     * Records each saved 1.x prefix as the package baseline for its interface.
     *
     * When the ISP changed the prefix after the script last ran, the saved prefix is
     * kept so the first package check updates the stale fields. A saved prefix that no
     * approved field still uses is replaced by the current one, which avoids a warning
     * on every check. Interfaces the package already tracks are left alone.
     *
     * @param array $settings   - Merged settings.
     * @param array $candidates - Interfaces from the 1.x script.
     * @param array $steps      - Steps, updated in place.
     *
     * @return void
     *
     * @throws Throwable When state cannot be read or written.
     *
     * @since 2.0.0
     * @private
     */
    private function seedBaselines(array $settings, array $candidates, array &$steps): void
    {
        $state = $this->stateStore->read();
        $selection = FieldSelection::fromStorage($this->platformAdapter->getFieldSelection());
        $scannedTargets = null;
        $prefixLengths = [];
        $seeded = false;

        foreach ($settings['row'] as $row) {
            $prefixLengths[$row['interface']] = (int) $row['prefix_length'];
        }

        foreach ($candidates as $interface => $candidate) {
            $cache = $candidate['cache'];

            if ($cache === null || isset($state['targets'][$interface])) {
                continue;
            }

            $prefix = $cache['prefix'];
            $prefixLength = $cache['prefix_length'];
            $plan = null;

            try {
                $sourceAddress = Ipv6Prefix::canonicalize($this->platformAdapter->getCurrentIpv6Address($interface));
                $scannedTargets ??= ConfigurationTargetScanner::scan(
                    $this->platformAdapter->getConfiguration(),
                    $selection,
                );
                $plan = ConfigurationChangePlanner::plan(
                    $prefix,
                    $prefixLength,
                    $sourceAddress,
                    $prefixLengths[$interface] ?? $prefixLength,
                    $scannedTargets,
                );
            } catch (Throwable) {
                $plan = null;
            }

            if ($plan !== null && $plan['prefix_changed'] && count($plan['changes']) === 0) {
                $steps[] = self::message('legacy_step_baseline_current', [$interface, $prefix, $prefixLength]);
                $prefix = $plan['current_prefix'];
                $prefixLength = $plan['current_prefix_length'];
            } elseif ($plan !== null && $plan['prefix_changed']) {
                $steps[] = self::message('legacy_step_baseline_stale', [$interface, $prefix, $prefixLength, count($plan['changes'])]);
            } else {
                $steps[] = self::message('legacy_step_baseline', [$interface, $prefix, $prefixLength]);
            }

            $state['targets'][$interface] = [
                'interface' => $interface,
                'source_address' => null,
                'prefix' => $prefix,
                'prefix_length' => $prefixLength,
                'updated_at' => gmdate('c', $cache['modified_at'] ?? time()),
                'source' => 'legacy_cache',
            ];
            $seeded = true;
        }

        if ($seeded) {
            $this->stateStore->write($state);
        }
    }

    /**
     * Copies the 1.x backups into the package and removes the originals.
     *
     * Each original is deleted only after its copy is verified. Files that are not
     * pfSense configurations stay where they are and are reported.
     *
     * @param array $findings   - Inspection result.
     * @param array $candidates - Interfaces from the 1.x script.
     * @param array $steps      - Steps, updated in place.
     * @param array $warnings   - Warnings, updated in place.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private function importBackups(array $findings, array $candidates, array &$steps, array &$warnings): void
    {
        $interfacesByDevice = [];

        foreach ($candidates as $interface => $candidate) {
            $interfacesByDevice[$candidate['device']] = $interface;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($findings['backup_files'] as $backupFile) {
            $device = $backupFile['device'];
            $interfaces = ($device === null) ? [] : [$interfacesByDevice[$device] ?? $device];

            try {
                $backup = $this->backupStore->import($backupFile['path'], 'legacy', $interfaces, $backupFile['created_at']);
            } catch (Throwable) {
                $backup = null;
            }

            if ($backup === null || @unlink($backupFile['path']) === false) {
                $skipped++;
                continue;
            }

            $imported++;
        }

        if ($imported > 0) {
            $steps[] = self::message('legacy_step_backups_imported', [$imported]);
        }

        $skipped += $findings['other_backup_files'];

        if ($skipped > 0) {
            $warnings[] = self::message('legacy_backups_skipped', [$skipped, $this->backupDirectory()]);
        }

        if ($findings['backup_directory']['present'] && $skipped === 0) {
            @rmdir($this->backupDirectory());
        }
    }

    /**
     * Deletes the 1.x saved-prefix files once their baselines are recorded.
     *
     * @param array $findings - Inspection result.
     * @param array $steps    - Steps, updated in place.
     * @param array $warnings - Warnings, updated in place.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private function removeCache(array $findings, array &$steps, array &$warnings): void
    {
        if ($findings['cache_directory']['present'] === false) {
            return;
        }

        if ($this->removeTree($this->cacheDirectory())) {
            $steps[] = self::message('legacy_step_cache_removed', [$this->cacheDirectory()]);
        } else {
            $warnings[] = self::message('legacy_remove_failed', [$this->cacheDirectory()]);
        }
    }

    /**
     * Saves the migration report in package state for the Cleanup tab.
     *
     * Once a move has completed, later passes add their steps to it, so the Cleanup tab
     * keeps the full history, and a later wait for the old script never replaces it.
     *
     * @param array $report - Migration report.
     *
     * @return void
     *
     * @throws Throwable When state cannot be read or written.
     *
     * @since 2.0.0
     * @private
     */
    private function record(array $report): void
    {
        $state = $this->stateStore->read();
        $previous = $state['legacy_migration'] ?? null;

        if (is_array($previous) && in_array($previous['status'] ?? null, ['completed', 'completed_with_warnings'], true)) {
            if ($report['status'] === 'deferred') {
                return;
            }

            $report['steps'] = array_merge(
                is_array($previous['steps'] ?? null) ? $previous['steps'] : [],
                $report['steps'],
            );
        }

        $state['legacy_migration'] = $report + ['recorded_at' => gmdate('c')];

        $this->stateStore->write($state);
    }

    /**
     * Lists regular files in one 1.x directory.
     *
     * @param string $directory - Directory.
     * @param string $suffix    - Required file-name suffix, or empty for any.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private function listFiles(string $directory, string $suffix): array
    {
        if (is_dir($directory) === false || is_link($directory)) {
            return [];
        }

        $entries = scandir($directory);
        $files = [];

        foreach (($entries === false) ? [] : $entries as $entry) {
            $path = $directory . '/' . $entry;

            if (is_file($path) && is_link($path) === false && ($suffix === '' || str_ends_with($entry, $suffix))) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Deletes a fixed 1.x path without following symbolic links.
     *
     * @param string $path - Path inside the 1.x root.
     *
     * @return bool
     *
     * @since 2.0.0
     * @private
     */
    private function removeTree(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        if (is_dir($path) === false) {
            return true;
        }

        $entries = scandir($path);

        foreach (($entries === false) ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($this->removeTree($path . '/' . $entry) === false) {
                return false;
            }
        }

        return @rmdir($path);
    }

    /**
     * Returns the files the 1.x install command left in the 1.x root.
     *
     * The README saved the installer as "pfsense-ipv6-prefix-updater-install.sh", and
     * the installer downloaded the repository archive without deleting it afterwards.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private function downloadPaths(): array
    {
        return [
            $this->legacyRoot . '/pfsense-ipv6-prefix-updater-install.sh',
            $this->legacyRoot . '/pfsense-ipv6-prefix-updater.zip',
        ];
    }

    /**
     * Returns the directory that held the 1.x script.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private function scriptDirectory(): string
    {
        return $this->legacyRoot . '/pfsense-ipv6-prefix-updater';
    }

    /**
     * Returns the directory that held the 1.x saved prefixes.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private function cacheDirectory(): string
    {
        return $this->legacyRoot . '/pfsense-ipv6-prefix-updater-cache';
    }

    /**
     * Returns the directory that held the 1.x backups.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private function backupDirectory(): string
    {
        return $this->legacyRoot . '/pfsense-ipv6-prefix-updater-backups';
    }

    /**
     * Creates a translatable report message.
     *
     * Reports store keys and arguments so the Cleanup tab shows them in the viewer's
     * language, not the language active when the migration ran.
     *
     * @param string       $key       - Translation key.
     * @param list<scalar> $arguments - Translation arguments.
     *
     * @return array{key: string, arguments: list<scalar>}
     *
     * @since 2.0.0
     * @private
     */
    private static function message(string $key, array $arguments = []): array
    {
        return [
            'key' => $key,
            'arguments' => $arguments,
        ];
    }
}

<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;
use PfSense\Ipv6PrefixUpdater\Domain\MonitoredInterfaces;
use PfSense\Ipv6PrefixUpdater\Runtime\BackupStore;
use PfSense\Ipv6PrefixUpdater\Runtime\FileLock;
use PfSense\Ipv6PrefixUpdater\Runtime\LegacyMigrator;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\PfSenseAdapter;
use PfSense\Ipv6PrefixUpdater\Runtime\StateStore;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once('config.inc');
require_once('interfaces.inc');
require_once('pfsense-utils.inc');
require_once('services.inc');
require_once(__DIR__ . '/ipv6-prefix-updater/BackupStore.php');
require_once(__DIR__ . '/ipv6-prefix-updater/FieldSelection.php');
require_once(__DIR__ . '/ipv6-prefix-updater/FileLock.php');
require_once(__DIR__ . '/ipv6-prefix-updater/LegacyMigrator.php');
require_once(__DIR__ . '/ipv6-prefix-updater/MonitoredInterfaces.php');
require_once(__DIR__ . '/ipv6-prefix-updater/PackagePaths.php');
require_once(__DIR__ . '/ipv6-prefix-updater/PfSenseAdapter.php');
require_once(__DIR__ . '/ipv6-prefix-updater/StateStore.php');
require_once(__DIR__ . '/ipv6-prefix-updater/Translator.php');

/**
 * Translates one package-owned message for the active pfSense language.
 *
 * @param string              $key       - Stable catalog key.
 * @param array<int, scalar> $arguments - Positional message arguments.
 *
 * @return string
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterTranslate(string $key, array $arguments = []): string
{
    return Translator::translate($key, $arguments);
}

/**
 * Localizes the generic package editor schema before pfSense builds the form.
 *
 * The package editor renders XML labels literally. This hook translates the parsed
 * in-memory schema, including the post-save confirmation, without altering persisted
 * package settings or pfSense's global gettext domain.
 *
 * @param array $package - Parsed package GUI definition.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterLocalizePackageGui(array &$package): void
{
    $package['title'] = pfsensePackageIpv6PrefixUpdaterTranslate('page_title');
    $package['aftersaveredirect'] = sprintf(
        '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0&savemsg=%s',
        rawurlencode(pfsensePackageIpv6PrefixUpdaterTranslate('settings_saved')),
    );

    $sourceKeys = [
        'Settings' => 'settings',
        'Status' => 'status',
        'Fields' => 'fields',
        'Backups' => 'backups',
        'Cleanup' => 'cleanup',
        'Monitored Interfaces' => 'monitored_interfaces',
        'Interface' => 'interface_column',
        'Delegated prefix length' => 'prefix_length_column',
        'Add one row per interface whose primary active global IPv6 address carries a delegated prefix. For prefix-only DHCPv6 on WAN, select a downstream tracked interface. Host bits after the prefix length are preserved exactly, and the address pfSense picks for each interface appears on the Status tab.' => 'monitored_interfaces_help',
        'Prefix Monitoring' => 'prefix_monitoring',
        'Enable Scheduled Updates' => 'enable_scheduled_updates',
        'Check for prefix changes automatically. The first successful check records a baseline and never modifies configuration.' => 'enable_scheduled_updates_help',
        'Monitored Interface' => 'monitored_interface',
        'Delegated Prefix Length' => 'delegated_prefix_length',
        'Enter the ISP-delegated prefix boundary. Host bits after this boundary are preserved exactly.' => 'delegated_prefix_length_help',
        'Schedule' => 'schedule',
        'Every 15 minutes' => 'every_15_minutes',
        'Hourly' => 'hourly',
        'Every 6 hours' => 'every_6_hours',
        'Daily at midnight' => 'daily_midnight',
        'Choose how often pfSense checks the active prefix. Unchanged checks do not scan or write approved fields.' => 'schedule_help',
        'Choose which configuration fields change with the prefix on the Fields tab.' => 'fields_settings_help',
        'Notifications' => 'notifications',
        'Send Change Notifications' => 'send_change_notifications',
        'Use configured pfSense notification channels after a verified successful prefix update. No-op checks never notify.' => 'send_change_notifications_help',
    ];

    if (isset($package['tabs']['tab']) && is_array($package['tabs']['tab'])) {
        foreach ($package['tabs']['tab'] as &$tab) {
            if (is_array($tab) && isset($tab['text'], $sourceKeys[$tab['text']])) {
                $tab['text'] = pfsensePackageIpv6PrefixUpdaterTranslate($sourceKeys[$tab['text']]);
            }
        }
        unset($tab);
    }

    if (!isset($package['fields']['field']) || !is_array($package['fields']['field'])) {
        return;
    }

    foreach ($package['fields']['field'] as &$field) {
        if (!is_array($field)) {
            continue;
        }

        foreach (['name', 'fielddescr', 'description'] as $property) {
            if (isset($field[$property], $sourceKeys[$field[$property]])) {
                $field[$property] = pfsensePackageIpv6PrefixUpdaterTranslate($sourceKeys[$field[$property]]);
            }
        }

        if (isset($field['rowhelper']['rowhelperfield']) && is_array($field['rowhelper']['rowhelperfield'])) {
            foreach ($field['rowhelper']['rowhelperfield'] as &$rowField) {
                if (!is_array($rowField)) {
                    continue;
                }

                if (isset($rowField['fielddescr'], $sourceKeys[$rowField['fielddescr']])) {
                    $rowField['fielddescr'] = pfsensePackageIpv6PrefixUpdaterTranslate($sourceKeys[$rowField['fielddescr']]);
                }

                // pfSense's own interface picker for rows prints a stray <select>, so the
                // row uses a plain select filled with the configured interfaces instead.
                if (($rowField['fieldname'] ?? '') === 'interface') {
                    $interfaceOptions = [];
                    $interfaces = get_configured_interface_with_descr(true);

                    foreach ((is_array($interfaces) ? $interfaces : []) as $interfaceName => $interfaceDescription) {
                        $interfaceOptions[] = [
                            'name' => (string) $interfaceDescription,
                            'value' => (string) $interfaceName,
                        ];
                    }

                    $rowField['options'] = ['option' => $interfaceOptions];
                }
            }
            unset($rowField);
        }

        if (!isset($field['options']['option']) || !is_array($field['options']['option'])) {
            continue;
        }

        foreach ($field['options']['option'] as &$option) {
            if (is_array($option) && isset($option['name'], $sourceKeys[$option['name']])) {
                $option['name'] = pfsensePackageIpv6PrefixUpdaterTranslate($sourceKeys[$option['name']]);
            }
        }
        unset($option);
    }
    unset($field);
}

/**
 * Returns the package tabs for the custom Status, Fields, Backups, and Cleanup pages.
 *
 * The generic settings editor builds its tabs from the package XML; the custom pages
 * share this list so all five pages show the same tabs in the same order.
 *
 * @param string $active - Active tab: settings, status, fields, backups, or cleanup.
 *
 * @return list<array{0: string, 1: bool, 2: string}>
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterTabs(string $active): array
{
    $tabs = [
        'settings' => '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
        'status' => '/ipv6-prefix-updater-status.php',
        'fields' => '/ipv6-prefix-updater-fields.php',
        'backups' => '/ipv6-prefix-updater-backups.php',
        'cleanup' => '/ipv6-prefix-updater-cleanup.php',
    ];
    $tabArray = [];

    foreach ($tabs as $key => $url) {
        $tabArray[] = [
            pfsensePackageIpv6PrefixUpdaterTranslate($key),
            $key === $active,
            $url,
        ];
    }

    return $tabArray;
}

/**
 * Returns an interface label such as "LAN (lan)" for package pages.
 *
 * @param string $interface - Friendly interface name.
 *
 * @return string
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterInterfaceLabel(string $interface): string
{
    $description = function_exists('convert_friendly_interface_to_friendly_descr')
        ? (string) convert_friendly_interface_to_friendly_descr($interface)
        : '';

    return ($description === '' || $description === $interface)
        ? $interface
        : sprintf('%s (%s)', $description, $interface);
}

/**
 * Returns the saved package settings.
 *
 * The WebGUI and cron synchronization share one normalized read path so missing package
 * configuration consistently becomes an empty settings record.
 *
 * @return array
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterGetSettings(): array
{
    $settings = config_get_path('installedpackages/ipv6prefixupdater/config/0', []);

    return is_array($settings) ? $settings : [];
}

/**
 * Returns the field selection saved from the Fields tab.
 *
 * Before the Fields tab is first saved, every Recommended group starts on.
 *
 * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterGetFieldSelection(): array
{
    return FieldSelection::fromStorage(config_get_path(PackagePaths::FIELD_SELECTION_PATH));
}

/**
 * Saves a field selection in one configuration revision.
 *
 * It is stored beside the settings record, which the generic Settings editor replaces
 * on every save.
 *
 * @param array $selection - Field selection.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterSaveFieldSelection(array $selection): void
{
    config_set_path(PackagePaths::FIELD_SELECTION_PATH, FieldSelection::toStorage($selection));
    write_config(pfsensePackageIpv6PrefixUpdaterTranslate('fields_revision'));
}

/**
 * Returns the installed package that owns a settings section, or null.
 *
 * @param string $section - Settings section under "installedpackages".
 *
 * @return string|null
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterPackageOwner(string $section): ?string
{
    static $platformAdapter = null;

    $platformAdapter ??= new PfSenseAdapter();

    try {
        return $platformAdapter->findPackageOwner($section);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Maps a WebGUI schedule to pfSense cron fields.
 *
 * Every supported schedule uses fixed values and an unknown value falls back to hourly,
 * matching the form default without accepting arbitrary cron fragments.
 *
 * @param string $schedule - Selected schedule.
 *
 * @return array{string, string, string, string, string}
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterGetSchedule(string $schedule): array
{
    return match ($schedule) {
        'every_fifteen_minutes' => ['*/15', '*', '*', '*', '*'],
        'every_six_hours' => ['0', '*/6', '*', '*', '*'],
        'daily' => ['0', '0', '*', '*', '*'],
        'hourly' => ['0', '*', '*', '*', '*'],
        default => ['0', '*', '*', '*', '*'],
    };
}

/**
 * Removes the package cron job and lock.
 *
 * Baselines and backups in the package data directory are kept. pfSense removes and
 * reinstalls packages during upgrades, and deleting the baseline there would let the
 * next check accept a changed prefix without updating the fields that used the old one.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterRemove(): void
{
    install_cron_job(PackagePaths::COMMAND, false);

    if (file_exists(PackagePaths::LOCK_FILE)) {
        @unlink(PackagePaths::LOCK_FILE);
    }
}

/**
 * Moves a version 1.x installation into the package during install or save.
 *
 * The package lock keeps this from overlapping a running check, which performs the
 * same move itself. Errors never stop the package install; they are logged, shown as
 * a notice, and retried by the next scheduled check.
 *
 * @return bool
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterMigrateLegacy(): bool
{
    $fileLock = new FileLock(PackagePaths::LOCK_FILE);

    if ($fileLock->acquire() === false) {
        return false;
    }

    try {
        $platformAdapter = new PfSenseAdapter();
        $stateStore = new StateStore(PackagePaths::STATE_FILE);

        $report = (new LegacyMigrator(
            $platformAdapter,
            $stateStore,
            new BackupStore(PackagePaths::BACKUP_DIRECTORY),
        ))->migrate();

        return $report['status'] === 'deferred';
    } catch (Throwable $throwable) {
        $message = pfsensePackageIpv6PrefixUpdaterTranslate('legacy_failed', [$throwable->getMessage()]);

        log_error('IPv6 Prefix Updater: ' . $message);
        file_notice(
            'ipv6-prefix-updater-legacy-migration',
            $message,
            'IPv6 Prefix Updater',
            '/ipv6-prefix-updater-cleanup.php',
            2,
            true,
        );

        return true;
    } finally {
        $fileLock->release();
    }
}

/**
 * Synchronizes the 1.x migration and the cron job.
 *
 * Runs on install, upgrade, and every settings save, so each step is idempotent.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterSync(): void
{
    pfsensePackageIpv6PrefixUpdaterInstallCron(pfsensePackageIpv6PrefixUpdaterMigrateLegacy());
}

/**
 * Synchronizes the package cron job with saved WebGUI settings.
 *
 * Existing entries are removed first so repeated saves remain idempotent. A disabled
 * package keeps no scheduled command, except a fifteen-minute retry while a 1.x
 * migration is still waiting for the old script to finish.
 *
 * @param bool $retryMigration - Whether a deferred 1.x migration needs a retry.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterInstallCron(bool $retryMigration = false): void
{
    $command = PackagePaths::COMMAND;

    install_cron_job($command, false);

    $settings = pfsensePackageIpv6PrefixUpdaterGetSettings();

    if (($settings['enabled'] ?? '') !== 'on') {
        if ($retryMigration) {
            install_cron_job($command, true, '*/15', '*', '*', '*', '*', 'root');
        }

        return;
    }

    $schedule = (string) ($settings['update_frequency'] ?? 'hourly');
    [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = pfsensePackageIpv6PrefixUpdaterGetSchedule($schedule);

    install_cron_job(
        $command,
        true,
        $minute,
        $hour,
        $dayOfMonth,
        $month,
        $dayOfWeek,
        'root',
    );
}

/**
 * Validates values submitted by the package WebGUI.
 *
 * Execution repeats these checks because persisted values are not trusted. Form-time
 * validation exists to give the administrator immediate and actionable feedback.
 *
 * @param array    $post        - Submitted values.
 * @param string[] $inputErrors - Validation errors.
 *
 * @return void
 *
 * @since 2.0.0
 */
function pfsensePackageIpv6PrefixUpdaterValidate(array $post, array &$inputErrors): void
{
    $rows = MonitoredInterfaces::fromPost($post);
    $interfaces = get_configured_interface_with_descr(true);
    $seenInterfaces = [];
    $interfaceError = count($rows) === 0;
    $prefixLengthError = false;

    foreach ($rows as $row) {
        $interface = trim((string) ($row['interface'] ?? ''));
        $prefixLength = (string) ($row['prefix_length'] ?? '');

        if ($interface === '' || !is_array($interfaces) || array_key_exists($interface, $interfaces) === false) {
            $interfaceError = true;
        } elseif (isset($seenInterfaces[$interface])) {
            $inputErrors[] = pfsensePackageIpv6PrefixUpdaterTranslate('duplicate_interface_error', [
                (string) $interfaces[$interface],
            ]);
        }

        $seenInterfaces[$interface] = true;

        if (
            ctype_digit($prefixLength) === false
            || (int) $prefixLength < 48
            || (int) $prefixLength > 64
        ) {
            $prefixLengthError = true;
        }
    }

    if ($interfaceError) {
        $inputErrors[] = pfsensePackageIpv6PrefixUpdaterTranslate('select_interface_error');
    }

    if ($prefixLengthError) {
        $inputErrors[] = pfsensePackageIpv6PrefixUpdaterTranslate('prefix_length_error');
    }

    $validSchedules = [
        'every_fifteen_minutes',
        'hourly',
        'every_six_hours',
        'daily',
    ];
    $schedule = (string) ($post['update_frequency'] ?? '');

    if (in_array($schedule, $validSchedules, true) === false) {
        $inputErrors[] = pfsensePackageIpv6PrefixUpdaterTranslate('schedule_error');
    }
}

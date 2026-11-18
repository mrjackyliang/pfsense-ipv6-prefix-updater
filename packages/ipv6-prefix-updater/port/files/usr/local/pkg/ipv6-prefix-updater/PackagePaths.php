<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * Package-owned filesystem and configuration locations.
 *
 * Persistent data lives beside the pfSense configuration in "/cf/conf" so state and
 * backups survive reboots when "/var" is a RAM disk. Only the lock stays transient.
 *
 * @since 2.0.0
 */
final class PackagePaths
{
    /**
     * Persistent package data directory.
     *
     * @since 2.0.0
     */
    public const DATA_DIRECTORY = '/cf/conf/ipv6-prefix-updater';

    /**
     * Last-successful prefix state file.
     *
     * @since 2.0.0
     */
    public const STATE_FILE = self::DATA_DIRECTORY . '/state.json';

    /**
     * Latest-release metadata from GitHub.
     *
     * @since 2.1.0
     */
    public const UPDATES_FILE = self::DATA_DIRECTORY . '/updates.json';

    /**
     * Background self-update progress shown on the About tab.
     *
     * @since 2.1.0
     */
    public const UPDATE_STATUS_FILE = self::DATA_DIRECTORY . '/update-status.json';

    /**
     * pfSense package-reinstall flag created during a full configuration restore.
     *
     * Deliberate removal may delete the recovery cron entry, while restore-driven
     * lifecycle work must preserve it for a firewall that lacks the package files.
     *
     * @since 2.1.2
     */
    public const PACKAGE_SYNC_FLAG = '/conf/needs_package_sync';

    /**
     * Configuration backups kept by the package.
     *
     * @since 2.0.0
     */
    public const BACKUP_DIRECTORY = self::DATA_DIRECTORY . '/backups';

    /**
     * Single-run lock shared by scheduled and WebGUI runs.
     *
     * @since 2.0.0
     */
    public const LOCK_FILE = '/var/run/ipv6-prefix-updater.lock';

    /**
     * Release-check and self-update lock.
     *
     * Release metadata work stays independent of a running prefix update, while one
     * release operation at a time may read or replace the saved update state.
     *
     * @since 2.1.3
     */
    public const RELEASE_LOCK_FILE = '/var/run/ipv6-prefix-updater-release.lock';

    /**
     * Package command run by cron and the WebGUI.
     *
     * @since 2.0.0
     */
    public const COMMAND = '/usr/local/sbin/ipv6-prefix-updater';

    /**
     * Package command used by the independent update-check cron entry.
     *
     * @since 2.1.0
     */
    public const UPDATE_CHECK_COMMAND = self::COMMAND . ' --check-updates';

    /**
     * Manual release-check command used by the About tab.
     *
     * The explicit flag records the release as seen without filing a redundant pfSense
     * notice while the administrator is already viewing the result.
     *
     * @since 2.1.3
     */
    public const MANUAL_UPDATE_CHECK_COMMAND = self::UPDATE_CHECK_COMMAND . ' --no-notice';

    /**
     * Package command launched in the background by the About tab.
     *
     * @since 2.1.0
     */
    public const SELF_UPDATE_COMMAND = self::COMMAND . ' --self-update';

    /**
     * Stable randomized update-check schedule in the pfSense configuration.
     *
     * @since 2.1.0
     */
    public const UPDATE_SCHEDULE_PATH = 'installedpackages/ipv6prefixupdater/update_schedule';

    /**
     * Home directory that held the version 1.x script, cache, and backups.
     *
     * @since 2.0.0
     */
    public const LEGACY_ROOT = '/root';

    /**
     * Configuration path of the field selection saved from the Fields tab.
     *
     * It sits beside the settings record, which the generic Settings editor replaces
     * on every save, so the selection survives Settings saves and package reinstalls.
     *
     * @since 2.0.0
     */
    public const FIELD_SELECTION_PATH = 'installedpackages/ipv6prefixupdater/fields';
}

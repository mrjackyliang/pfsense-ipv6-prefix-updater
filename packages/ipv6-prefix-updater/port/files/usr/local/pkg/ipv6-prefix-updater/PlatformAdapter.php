<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * Defines the pfSense operations required by the prefix update coordinator.
 *
 * Keeping platform calls behind this interface lets tests exercise state transitions,
 * planning, failure handling, and write verification without a live firewall.
 *
 * @since 2.0.0
 */
interface PlatformAdapter
{
    /**
     * Confirms required pfSense APIs are available.
     *
     * Implementations fail closed before configuration state is read when the runtime
     * does not match the package's supported pfSense environment.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function assertEnvironment(): void;

    /**
     * Confirms every planned service can be reloaded.
     *
     * This preflight runs before configuration mutation so a missing optional package
     * integration cannot leave a committed change without its required reload.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function assertReloadCapabilities(array $services): void;

    /**
     * Returns a complete configuration snapshot for pure scanning.
     *
     * The coordinator reads this only after detecting a prefix transition, preserving
     * the strict no-op path when the active prefix is unchanged.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public function getConfiguration(): array;

    /**
     * Returns one current value through the platform configuration API.
     *
     * Exact reads guard against stale plans immediately before mutation and verify each
     * committed result after pfSense writes and reloads the configuration.
     *
     * @param string $path - Configuration path.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getConfigurationValue(string $path): mixed;

    /**
     * Returns the active IPv6 address for a friendly interface name.
     *
     * The coordinator validates global scope and derives the configured prefix from
     * this source without negotiating or changing the ISP delegation.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public function getCurrentIpv6Address(string $interface): string;

    /**
     * Returns the persisted package settings.
     *
     * Runtime validation treats WebGUI persistence as untrusted input and checks every
     * required option again before the package reads mutation targets.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public function getSettings(): array;

    /**
     * Returns the field selection saved from the Fields tab, unvalidated.
     *
     * It lives beside the settings record because the generic Settings editor replaces
     * that record on every save. Null means no selection was saved yet.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getFieldSelection(): mixed;

    /**
     * Re-applies the packages that own changed package settings sections.
     *
     * Runs after the verified write, the way each package's Save button does. A failure
     * is reported for its section and never undoes the saved values.
     *
     * @param list<string> $sections - Package settings sections that changed.
     *
     * @return list<array{section: string, package: string|null, status: string, error: string|null}>
     *
     * @since 2.0.0
     */
    public function reapplyPackages(array $sections): array;

    /**
     * Reports whether a friendly pfSense interface exists.
     *
     * This check prevents a renamed or removed interface from silently becoming a
     * different monitored target while retaining the old successful prefix state.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public function interfaceExists(string $interface): bool;

    /**
     * Writes an operational message to the pfSense system log.
     *
     * Messages contain transition summaries and counts but never the full firewall
     * configuration, secrets, certificates, or remote peer data.
     *
     * @param string $message - Safe log message.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function log(string $message): void;

    /**
     * Creates a native pfSense notice and optional remote notification.
     *
     * Local-only notices expose warnings and failures in the GUI without generating a
     * change notification. Successful transitions may use configured remote channels.
     *
     * @param string $identifier - Notice identifier.
     * @param string $message    - Safe notice message.
     * @param int    $priority   - pfSense notice priority.
     * @param bool   $localOnly  - Whether remote delivery is disabled.
     * @param string $page       - Package page the notice links to.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function notice(
        string $identifier,
        string $message,
        int $priority,
        bool $localOnly,
        string $page = '/ipv6-prefix-updater-status.php',
    ): void;

    /**
     * Returns the friendly interface that owns an operating-system device.
     *
     * Version 1.x stored device names such as "igb1.10". Null means no configured
     * interface uses the device, so the caller must not guess a replacement.
     *
     * @param string $device - Operating-system device name.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public function findInterfaceByDevice(string $device): ?string;

    /**
     * Regenerates the system crontab from the saved configuration.
     *
     * Called after Cron entries are removed so the old script stops running at once
     * instead of at the next unrelated cron synchronization.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function reloadCron(): void;

    /**
     * Returns the path of the active configuration file.
     *
     * Package backups copy this file byte for byte so a downloaded backup restores
     * through the native Backup & Restore page.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public function getConfigurationFilePath(): string;

    /**
     * Removes the kernel routes of static route networks that a committed plan replaced.
     *
     * pfSense's routing reload adds and changes routes but never deletes a network that
     * left the configuration, so the Static Routes page deletes it first, and so does a run.
     *
     * @param list<string> $networks - Previous static route networks.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function deleteRoutes(array $networks): void;

    /**
     * Reloads exactly the subsystems referenced by a committed plan.
     *
     * Implementations deduplicate service names and call reviewed native pfSense or
     * package synchronization functions in a deterministic order.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function reloadServices(array $services): void;

    /**
     * Creates a pfSense configuration-history backup before planned mutation.
     *
     * Implementations must fail closed when the backup cannot be confirmed so the
     * coordinator never changes the in-memory configuration without a recovery point.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function backupConfiguration(): void;

    /**
     * Sets one planned value through the platform configuration API.
     *
     * The coordinator calls this only after every change has passed stale-plan checks
     * and all required reload capabilities have completed preflight validation.
     *
     * @param string $path  - Configuration path.
     * @param mixed  $value - Planned value.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function setConfigurationValue(string $path, mixed $value): void;

    /**
     * Writes the complete planned configuration once.
     *
     * The coordinator creates the recovery point separately before its first mutation.
     * Implementations must throw whenever the resulting write state is uncertain.
     *
     * @param string $description - Configuration revision description.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function writeConfiguration(string $description): void;
}

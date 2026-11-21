<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * Defines the pfSense configuration, notice, cron, and system operations the package uses.
 *
 * The coordinator, lifecycle hooks, update checks, and package pages reach those APIs
 * only through this interface, so tests run without a live firewall and a pfSense
 * API change touches only the adapter.
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

    /**
     * Confirms the pfSense APIs needed by release checks and self-updates are available.
     *
     * Update work loads only the configuration, proxy, notice, and logging includes,
     * never the interface APIs that prefix mutation needs.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function assertUpdateEnvironment(): void;

    /**
     * Returns the installed package that owns one settings section, or null.
     *
     * The Fields tab names each package group after its owner, and package re-applies
     * use the same lookup.
     *
     * @param string $section - Settings section under "installedpackages".
     *
     * @return string|null
     *
     * @since 2.1.5
     */
    public function findPackageOwner(string $section): ?string;

    /**
     * Returns pfSense's installed-package records, or an empty list.
     *
     * Callers that run without a loaded configuration API, such as package lifecycle
     * edges, get an empty list instead of an error.
     *
     * @return array
     *
     * @since 2.1.5
     */
    public function getInstalledPackages(): array;

    /**
     * Returns the language selected in pfSense, or null when it can't be read.
     *
     * Tests and non-pfSense tooling have no configuration API, so the translator falls
     * back to English.
     *
     * @return string|null
     *
     * @since 2.1.5
     */
    public function getConfiguredLanguage(): ?string;

    /**
     * Returns every configured interface keyed by friendly name, with its description.
     *
     * Unassigned interfaces are included, matching the interface picker on the
     * Settings tab.
     *
     * @return array<string, string>
     *
     * @since 2.1.5
     */
    public function getInterfaceDescriptions(): array;

    /**
     * Returns the description of one friendly interface, or an empty string.
     *
     * An empty string means pfSense can't describe the interface, so callers show
     * the friendly name alone.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return string
     *
     * @since 2.1.5
     */
    public function getInterfaceDescription(string $interface): string;

    /**
     * Returns pfSense's queued notices keyed by queue key, or null.
     *
     * Null means the notice API is unavailable or the queue is empty, so callers
     * skip notice upkeep instead of failing.
     *
     * @return array<int|string, mixed>|null
     *
     * @since 2.1.5
     */
    public function getNoticeQueue(): ?array;

    /**
     * Closes one queued notice by queue key or stored identifier.
     *
     * Nothing happens when the notice API is unavailable, so lifecycle and update
     * code can close notices from any context.
     *
     * @param int|string $identifier - Queue key or stored notice identifier.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function closeNotice(int|string $identifier): void;

    /**
     * Files one pfSense notice with every field given exactly.
     *
     * Unlike notice(), nothing is prefixed, so restore reassurance and update notices
     * keep the identifiers and categories that other code looks up.
     *
     * @param string $identifier - Notice identifier.
     * @param string $message    - Notice text.
     * @param string $category   - Notice category.
     * @param string $url        - Notice link.
     * @param int    $priority   - pfSense notice priority.
     * @param bool   $localOnly  - Whether remote delivery is disabled.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function fileNotice(
        string $identifier,
        string $message,
        string $category,
        string $url,
        int $priority,
        bool $localOnly,
    ): void;

    /**
     * Returns the product label pfSense shows, such as "pfSense".
     *
     * Restore notice matching needs the label pfSense put into its own notice text.
     *
     * @return string
     *
     * @since 2.1.5
     */
    public function getProductLabel(): string;

    /**
     * Translates one pfSense source string through pfSense's own catalog.
     *
     * Restore notice matching needs pfSense's exact localized wording, which lives
     * in pfSense's catalog rather than the package's.
     *
     * @param string $text - pfSense source text.
     *
     * @return string
     *
     * @since 2.1.5
     */
    public function translatePlatformText(string $text): string;

    /**
     * Removes one configuration path through the platform configuration API.
     *
     * Lifecycle hooks use it when the last cron item leaves the configuration.
     *
     * @param string $path - Configuration path.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function deleteConfigurationValue(string $path): void;

    /**
     * Saves the configuration with pfSense's default backup and synchronization.
     *
     * Settings saves made by package pages and lifecycle hooks use this, unlike the
     * write-only commit that writeConfiguration() makes after a planned run.
     *
     * @param string $description - Configuration revision description.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function saveConfiguration(string $description): void;

    /**
     * Adds, replaces, or removes one cron job by its command.
     *
     * Defaults match pfSense's own, so a removal passes only the command and false.
     *
     * @param string $command  - Command the job runs.
     * @param bool   $active   - Whether the job is installed or removed.
     * @param string $minute   - Minute field.
     * @param string $hour     - Hour field.
     * @param string $monthDay - Day-of-month field.
     * @param string $month    - Month field.
     * @param string $weekDay  - Day-of-week field.
     * @param string $who      - User the job runs as.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function installCronJob(
        string $command,
        bool $active,
        string $minute = '0',
        string $hour = '*',
        string $monthDay = '*',
        string $month = '*',
        string $weekDay = '*',
        string $who = 'root',
    ): void;

    /**
     * Regenerates the system crontab from configuration already loaded by the caller.
     *
     * Unlike reloadCron(), it loads no include, because package lifecycle hooks run
     * with pfSense's services already loaded.
     *
     * @return void
     *
     * @since 2.1.5
     */
    public function regenerateCrontab(): void;

    /**
     * Parses one pfSense package XML file, or returns null when no parser is available.
     *
     * The About tab reads the shipped "info.xml" this way when pfSense's installed
     * package record is unavailable.
     *
     * @param string $path        - XML file path.
     * @param string $rootElement - Expected root element.
     *
     * @return mixed
     *
     * @since 2.1.5
     */
    public function parsePackageXml(string $path, string $rootElement): mixed;
}

<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;
use Throwable;

require_once(__DIR__ . '/PackagePaths.php');
require_once(__DIR__ . '/PlatformAdapter.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Adapts the pure prefix coordinator to reviewed pfSense APIs.
 *
 * Configuration writes use pfSense's backup-aware writer and service reloads call only
 * subsystems referenced by the completed plan.
 *
 * @since 2.0.0
 */
final class PfSenseAdapter implements PlatformAdapter
{
    /**
     * Installed package that owns each settings section.
     *
     * Every installed package's XML is parsed once per adapter, so listing several
     * package groups doesn't parse the same files again. Null means not read yet.
     *
     * @var array<string, string>|null
     *
     * @since 2.0.0
     * @private
     */
    private ?array $packageOwners = null;

    /**
     * Confirms the process is running with required pfSense configuration APIs.
     *
     * Platform files are loaded only from fixed system paths. A missing file, function,
     * or non-FreeBSD runtime stops execution before package settings are read.
     *
     * @return void
     *
     * @throws RuntimeException When the environment is unsupported.
     *
     * @since 2.0.0
     */
    public function assertEnvironment(): void
    {
        if (PHP_OS_FAMILY !== 'BSD' || file_exists('/etc/version') === false) {
            throw new RuntimeException(Translator::translate('environment_unsupported'));
        }

        $requiredFiles = [
            '/etc/inc/config.inc',
            '/etc/inc/interfaces.inc',
            '/etc/inc/notices.inc',
        ];

        foreach ($requiredFiles as $requiredFile) {
            if (is_readable($requiredFile) === false) {
                throw new RuntimeException(Translator::translate('dependency_unavailable', [$requiredFile]));
            }

            require_once($requiredFile);
        }

        $requiredFunctions = [
            'backup_config',
            'config_get_path',
            'config_set_path',
            'convert_real_interface_to_friendly_interface_name',
            'file_notice',
            'get_configured_interface_with_descr',
            'get_interface_ipv6',
            'log_error',
            'write_config',
        ];

        foreach ($requiredFunctions as $requiredFunction) {
            if (function_exists($requiredFunction) === false) {
                throw new RuntimeException(Translator::translate('dependency_unavailable', [$requiredFunction]));
            }
        }
    }

    /**
     * Confirms native and optional-package reload functions are available.
     *
     * Every include path is fixed and reviewed. WireGuard changes fail preflight when
     * the package configuration remains but its runtime integration is not installed.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @throws RuntimeException When a reload integration is unavailable.
     *
     * @since 2.0.0
     */
    public function assertReloadCapabilities(array $services): void
    {
        $uniqueServices = array_values(array_unique($services));

        foreach ($uniqueServices as $service) {
            switch ($service) {
                case 'filter': {
                    $this->requireFunction('/etc/inc/filter.inc', 'filter_configure');
                    break;
                }

                case 'interfaces': {
                    $this->requireFunction('/etc/inc/interfaces.inc', 'interfaces_configure');
                    $this->requireFunction('/etc/inc/filter.inc', 'filter_configure');
                    break;
                }

                case 'routing': {
                    $this->requireFunction('/etc/inc/util.inc', 'mwexec');
                    $this->requireFunction('/etc/inc/system.inc', 'system_routing_configure');
                    $this->requireFunction('/etc/inc/gwlb.inc', 'setup_gateways_monitor');
                    $this->requireFunction('/etc/inc/filter.inc', 'filter_configure');
                    break;
                }

                case 'ipsec': {
                    $this->requireFunction('/etc/inc/ipsec.inc', 'ipsec_configure');
                    break;
                }

                case 'openvpn': {
                    $this->requireFunction('/etc/inc/openvpn.inc', 'openvpn_resync_all');
                    break;
                }

                case 'wireguard': {
                    $this->requireFunction('/usr/local/pkg/wireguard/includes/wg.inc', 'wg_resync');
                    break;
                }

                case 'dhcpv6': {
                    $this->requireFunction('/etc/inc/services.inc', 'services_dhcpd_configure');
                    $this->requireFunction('/etc/inc/services.inc', 'services_dnsmasq_configure');
                    $this->requireFunction('/etc/inc/services.inc', 'services_unbound_configure');
                    $this->requireFunction('/etc/inc/config.inc', 'config_path_enabled');
                    break;
                }

                case 'router_advertisements': {
                    $this->requireFunction('/etc/inc/services.inc', 'services_radvd_configure');
                    break;
                }

                case 'dns': {
                    $this->requireFunction('/etc/inc/system.inc', 'system_resolvconf_generate');
                    $this->requireFunction('/etc/inc/services.inc', 'services_unbound_configure');
                    $this->requireFunction('/etc/inc/services.inc', 'services_dnsmasq_configure');
                    $this->requireFunction('/etc/inc/config.inc', 'config_path_enabled');
                    break;
                }

                case 'dns_resolver': {
                    $this->requireFunction('/etc/inc/services.inc', 'services_unbound_configure');
                    break;
                }

                case 'dns_forwarder': {
                    $this->requireFunction('/etc/inc/services.inc', 'services_dnsmasq_configure');
                    break;
                }

                case 'none': {
                    break;
                }

                default: {
                    if (str_starts_with($service, 'package:')) {
                        $this->requireFunction('/etc/inc/pkg-utils.inc', 'sync_package');
                        $this->requireFunction('/etc/inc/xmlparse.inc', 'parse_xml_config_pkg');
                        $this->requireFunction('/etc/inc/util.inc', 'xml_safe_fieldname');
                        break;
                    }

                    throw new RuntimeException(Translator::translate('reload_service_unsupported', [$service]));
                }
            }
        }
    }

    /**
     * Returns the installed package that owns one settings section.
     *
     * Each installed package's XML "<name>" becomes its settings key the same way
     * pkg_edit.php derives it. Null means no installed package owns the section.
     *
     * @param string $section - Settings section under "installedpackages".
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public function findPackageOwner(string $section): ?string
    {
        if ($this->packageOwners === null) {
            $this->requireFunction('/etc/inc/xmlparse.inc', 'parse_xml_config_pkg');
            $this->requireFunction('/etc/inc/util.inc', 'xml_safe_fieldname');

            $packages = \config_get_path('installedpackages/package', []);
            $packageOwners = [];

            foreach ((is_array($packages) ? $packages : []) as $package) {
                $packageName = $package['name'] ?? null;
                $configurationFile = $package['configurationfile'] ?? null;

                if (!is_string($packageName) || !is_string($configurationFile) || basename($configurationFile) !== $configurationFile) {
                    continue;
                }

                $packageXmlPath = '/usr/local/pkg/' . $configurationFile;

                if (is_readable($packageXmlPath) === false) {
                    continue;
                }

                $packageXml = \parse_xml_config_pkg($packageXmlPath, 'packagegui');
                $packageXmlName = is_array($packageXml) ? ($packageXml['name'] ?? null) : null;

                if (is_string($packageXmlName)) {
                    $packageOwners[\xml_safe_fieldname($packageXmlName)] ??= $packageName;
                }
            }

            $this->packageOwners = $packageOwners;
        }

        return $this->packageOwners[$section] ?? null;
    }

    /**
     * Returns the complete pfSense configuration through its path API.
     *
     * The adapter never reads or parses "/cf/conf/config.xml" directly, preserving
     * pfSense's in-memory representation and compatibility behavior.
     *
     * @return array
     *
     * @throws RuntimeException When the root configuration is unavailable.
     *
     * @since 2.0.0
     */
    public function getConfiguration(): array
    {
        $configuration = \config_get_path('', []);

        if (!is_array($configuration)) {
            throw new RuntimeException(Translator::translate('root_configuration_invalid'));
        }

        return $configuration;
    }

    /**
     * Returns one value through the pfSense configuration path API.
     *
     * Exact path reads support stale-plan detection and post-write verification without
     * exposing unrelated configuration content to logs or notices.
     *
     * @param string $path - Configuration path.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getConfigurationValue(string $path): mixed
    {
        return \config_get_path($path);
    }

    /**
     * Returns the active global IPv6 source for one friendly interface.
     *
     * The forced refresh avoids stale cached interface data. Scope and prefix bounds
     * remain the coordinator's responsibility so every adapter follows one policy.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return string
     *
     * @throws RuntimeException When no address is available.
     *
     * @since 2.0.0
     */
    public function getCurrentIpv6Address(string $interface): string
    {
        $address = \get_interface_ipv6($interface, true, false);

        if (!is_string($address) || trim($address) === '') {
            throw new RuntimeException(Translator::translate('interface_address_unavailable', [$interface]));
        }

        return trim($address);
    }

    /**
     * Returns saved package settings through the pfSense configuration API.
     *
     * Missing settings become an empty array and are rejected by runtime validation
     * unless the package is intentionally disabled.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public function getSettings(): array
    {
        $settings = \config_get_path('installedpackages/ipv6prefixupdater/config/0', []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * Returns the field selection saved from the Fields tab, unvalidated.
     *
     * The coordinator validates it, and starts every Recommended group on when nothing
     * was saved yet.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getFieldSelection(): mixed
    {
        return \config_get_path(PackagePaths::FIELD_SELECTION_PATH);
    }

    /**
     * Re-applies the packages that own changed package settings sections.
     *
     * pfSense's sync_package() runs a package's resync command, as its Save button and
     * boot do. Output the package prints is discarded so it can't corrupt the results.
     *
     * @param list<string> $sections - Package settings sections that changed.
     *
     * @return list<array{section: string, package: string|null, status: string, error: string|null}>
     *
     * @since 2.0.0
     */
    public function reapplyPackages(array $sections): array
    {
        $outcomes = [];

        foreach (array_values(array_unique($sections)) as $section) {
            $owner = null;

            try {
                $owner = $this->findPackageOwner($section);

                if ($owner === null) {
                    $outcomes[] = [
                        'section' => $section,
                        'package' => null,
                        'status' => 'unknown_owner',
                        'error' => null,
                    ];

                    continue;
                }

                $this->requireFunction('/etc/inc/pkg-utils.inc', 'sync_package');

                ob_start();

                try {
                    \sync_package($owner);
                } finally {
                    ob_end_clean();
                }

                $outcomes[] = [
                    'section' => $section,
                    'package' => $owner,
                    'status' => 'reapplied',
                    'error' => null,
                ];
            } catch (Throwable $throwable) {
                $outcomes[] = [
                    'section' => $section,
                    'package' => $owner,
                    'status' => 'failed',
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        return $outcomes;
    }

    /**
     * Reports whether the friendly interface exists in pfSense configuration.
     *
     * The display-name map keys are stable friendly identifiers such as "wan" and
     * "lan", matching values persisted by the package interface selector.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public function interfaceExists(string $interface): bool
    {
        $interfaces = \get_configured_interface_with_descr(true);

        return is_array($interfaces) && array_key_exists($interface, $interfaces);
    }

    /**
     * Writes a package-prefixed operational message to the system log.
     *
     * The fixed prefix makes scheduled and manual activity discoverable through the
     * native pfSense logs without managing a separate package log file.
     *
     * @param string $message - Safe log message.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function log(string $message): void
    {
        \log_error('IPv6 Prefix Updater: ' . $message);
    }

    /**
     * Creates a native package notice with optional remote delivery.
     *
     * pfSense escapes notice fields before persistence. The coordinator still supplies
     * only safe transition summaries and never full configuration values.
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
    ): void {
        \file_notice(
            'ipv6-prefix-updater-' . $identifier,
            $message,
            'IPv6 Prefix Updater',
            $page,
            $priority,
            $localOnly,
        );
    }

    /**
     * Returns the friendly interface that owns an operating-system device.
     *
     * pfSense's own lookup resolves VLAN, LAGG, and bridge devices the same way the
     * Interfaces pages do. Unknown devices return null.
     *
     * @param string $device - Operating-system device name.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public function findInterfaceByDevice(string $device): ?string
    {
        $interface = \convert_real_interface_to_friendly_interface_name($device);

        return (is_string($interface) && $interface !== '') ? $interface : null;
    }

    /**
     * Regenerates the system crontab from the saved configuration.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function reloadCron(): void
    {
        $this->requireFunction('/etc/inc/services.inc', 'configure_cron');

        \configure_cron();
    }

    /**
     * Returns the path of the active configuration file.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public function getConfigurationFilePath(): string
    {
        global $g;

        return rtrim((string) ($g['conf_path'] ?? '/conf'), '/') . '/config.xml';
    }

    /**
     * Removes the kernel routes of static route networks that a committed plan replaced.
     *
     * Only values shaped like a network are passed to "route delete"; an alias name in a
     * route's network field is left to the routing reload.
     *
     * @param list<string> $networks - Previous static route networks.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function deleteRoutes(array $networks): void
    {
        foreach (array_values(array_unique($networks)) as $network) {
            if (preg_match('#^[0-9A-Fa-f:.]+/[0-9]{1,3}$#', $network) !== 1) {
                continue;
            }

            \mwexec('/sbin/route delete ' . (str_contains($network, ':') ? '-inet6 ' : '-inet ') . escapeshellarg($network));
        }
    }

    /**
     * Reloads only subsystems referenced by the committed change plan.
     *
     * An interface reload is followed by one filter reload, and in pfSense 2.7.2 it
     * also restarts DHCPv6, radvd, and both DNS services, which are then skipped. A
     * routing reload applies gateways and static routes the way their pages do.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @throws RuntimeException When a reload reports failure.
     *
     * @since 2.0.0
     */
    public function reloadServices(array $services): void
    {
        $uniqueServices = array_values(array_unique($services));
        $serviceOrder = [
            'interfaces',
            'routing',
            'filter',
            'dhcpv6',
            'router_advertisements',
            'dns',
            'dns_resolver',
            'dns_forwarder',
            'ipsec',
            'openvpn',
            'wireguard',
        ];
        $reloadedServices = [];

        foreach ($serviceOrder as $service) {
            if (in_array($service, $uniqueServices, true) === false || isset($reloadedServices[$service])) {
                continue;
            }

            switch ($service) {
                case 'interfaces': {
                    \interfaces_configure();
                    $this->assertReloadResult('filter', \filter_configure());
                    $reloadedServices += array_fill_keys(
                        [
                            'filter',
                            'dhcpv6',
                            'router_advertisements',
                            'dns_resolver',
                            'dns_forwarder',
                        ],
                        true,
                    );
                    break;
                }

                case 'routing': {
                    // Mirrors Apply on the Gateways and Static Routes pages.
                    \system_routing_configure();
                    \setup_gateways_monitor();
                    $this->assertReloadResult('filter', \filter_configure());
                    $reloadedServices['filter'] = true;
                    break;
                }

                case 'filter': {
                    $this->assertReloadResult('filter', \filter_configure());
                    break;
                }

                case 'dhcpv6': {
                    // Mirrors the DHCPv6 Server page's Apply, which reloads the DNS service
                    // that registers static mappings, and that reload restarts DHCP itself.
                    if (\config_path_enabled('dnsmasq') && \config_path_enabled('dnsmasq', 'regdhcpstatic')) {
                        $this->assertReloadResult('dns_forwarder', \services_dnsmasq_configure());
                        $reloadedServices['dns_forwarder'] = true;
                    } elseif (\config_path_enabled('unbound') && \config_path_enabled('unbound', 'regdhcpstatic')) {
                        $this->assertReloadResult('dns_resolver', \services_unbound_configure());
                        $reloadedServices['dns_resolver'] = true;
                    } else {
                        \services_dhcpd_configure('inet6');
                    }

                    $reloadedServices['router_advertisements'] = true;
                    break;
                }

                case 'router_advertisements': {
                    \services_radvd_configure();
                    break;
                }

                case 'dns': {
                    // Mirrors Save on System > General Setup for its DNS servers.
                    \system_resolvconf_generate();

                    if (\config_path_enabled('unbound')) {
                        $this->assertReloadResult('dns_resolver', \services_unbound_configure(false));
                    }

                    if (\config_path_enabled('dnsmasq')) {
                        $this->assertReloadResult('dns_forwarder', \services_dnsmasq_configure(false));
                    }

                    $reloadedServices['dns_resolver'] = true;
                    $reloadedServices['dns_forwarder'] = true;
                    break;
                }

                case 'dns_resolver': {
                    $this->assertReloadResult('dns_resolver', \services_unbound_configure(false));
                    break;
                }

                case 'dns_forwarder': {
                    $this->assertReloadResult('dns_forwarder', \services_dnsmasq_configure(false));
                    break;
                }

                case 'ipsec': {
                    \ipsec_configure();
                    break;
                }

                case 'openvpn': {
                    \openvpn_resync_all();
                    break;
                }

                case 'wireguard': {
                    \wg_resync();
                    break;
                }

                default: {
                    throw new RuntimeException(Translator::translate('reload_service_unsupported', [$service]));
                }
            }
        }
    }

    /**
     * Creates a pfSense configuration-history backup before planned mutation.
     *
     * The native backup API copies the current on-disk "config.xml" revision. Failure
     * stops the coordinator before it changes the in-memory configuration.
     *
     * @return void
     *
     * @throws RuntimeException When pfSense cannot confirm the backup.
     *
     * @since 2.0.0
     */
    public function backupConfiguration(): void
    {
        $backupResult = \backup_config();

        if ($backupResult !== true) {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }
    }

    /**
     * Sets one exact configuration path through pfSense's supported API.
     *
     * No raw XML or broad textual replacement is used, and the coordinator supplies
     * only paths emitted by the approved scanner.
     *
     * @param string $path  - Configuration path.
     * @param mixed  $value - Planned value.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function setConfigurationValue(string $path, mixed $value): void
    {
        \config_set_path($path, $value);
    }

    /**
     * Writes the complete plan once through pfSense's configuration API.
     *
     * The write-only option skips a duplicate backup and unrelated synchronization
     * because the coordinator already created a backup and reloads explicit subsystems.
     *
     * @param string $description - Configuration revision description.
     *
     * @return void
     *
     * @throws RuntimeException When the write does not return configuration.
     *
     * @since 2.0.0
     */
    public function writeConfiguration(string $description): void
    {
        $result = \write_config($description, false, true);

        if (!is_array($result)) {
            throw new RuntimeException(Translator::translate('configuration_write_failed'));
        }
    }

    /**
     * Interprets common pfSense reload return conventions.
     *
     * Filter routines report integer zero on success. Explicit false and nonzero integer
     * results are failures; other service routines use exceptions or void returns.
     *
     * @param string $service - Display service name.
     * @param mixed  $result  - Native reload result.
     *
     * @return void
     *
     * @throws RuntimeException When the result explicitly reports failure.
     *
     * @since 2.0.0
     * @private
     */
    private function assertReloadResult(string $service, mixed $result): void
    {
        if ($result === false || (is_int($result) && $result !== 0)) {
            throw new RuntimeException(Translator::translate('reload_failed', [$service]));
        }
    }

    /**
     * Loads one fixed integration file and confirms its required function.
     *
     * This helper is used only during preflight before the coordinator mutates any
     * configuration path, preventing missing integrations from causing partial runs.
     *
     * @param string $path     - Fixed include path.
     * @param string $function - Required global function.
     *
     * @return void
     *
     * @throws RuntimeException When the integration is unavailable.
     *
     * @since 2.0.0
     * @private
     */
    private function requireFunction(string $path, string $function): void
    {
        if (is_readable($path) === false) {
            throw new RuntimeException(Translator::translate('dependency_unavailable', [$path]));
        }

        require_once($path);

        if (function_exists($function) === false) {
            throw new RuntimeException(Translator::translate('dependency_unavailable', [$function]));
        }
    }
}

<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Runtime\PfSenseAdapter;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

if (PHP_OS_FAMILY === 'BSD') {
    fwrite(STDOUT, "SKIP pfSense adapter stubs are not loaded on BSD.\n");

    exit(0);
}

/** @var list<string> $adapterTestEvents Ordered native API calls. */
$adapterTestEvents = [];

/** @var list<array> $adapterTestAddressRequests Captured address API arguments. */
$adapterTestAddressRequests = [];

/** @var string|null $adapterTestAddress Simulated active interface address. */
$adapterTestAddress = " 2001:0DB8:ABCD:1200::42\n";

/** @var bool $adapterTestBackupResult Simulated native backup result. */
$adapterTestBackupResult = true;

/** @var array|bool $adapterTestWriteResult Simulated native write result. */
$adapterTestWriteResult = [
    'revision' => 'test',
];

if (function_exists('get_interface_ipv6') === false) {
    /**
     * Returns a simulated address while recording pfSense selection arguments.
     *
     * The production adapter must request a forced refresh and disable link-local
     * fallback for the operator-selected friendly interface.
     *
     * @param string $interface         - Friendly interface name.
     * @param bool   $flush             - Whether cached interface data is flushed.
     * @param bool   $linklocalFallback - Whether link-local fallback is allowed.
     * @param bool   $gatewaysStatus    - Whether gateway state affects selection.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    function get_interface_ipv6(
        string $interface = 'wan',
        bool $flush = false,
        bool $linklocalFallback = false,
        bool $gatewaysStatus = false,
    ): ?string {
        global $adapterTestAddress;
        global $adapterTestAddressRequests;

        $adapterTestAddressRequests[] = [
            'interface' => $interface,
            'flush' => $flush,
            'linklocal_fallback' => $linklocalFallback,
            'gateways_status' => $gatewaysStatus,
        ];

        return $adapterTestAddress;
    }
}

if (function_exists('backup_config') === false) {
    /**
     * Returns a simulated pfSense configuration-history backup result.
     *
     * The ordered event log lets the test prove the native backup boundary is invoked
     * separately from the write boundary.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    function backup_config(): bool
    {
        global $adapterTestBackupResult;
        global $adapterTestEvents;

        $adapterTestEvents[] = 'backup';

        return $adapterTestBackupResult;
    }
}

if (function_exists('write_config') === false) {
    /**
     * Returns a simulated pfSense configuration write result.
     *
     * Captured flags prove the adapter suppresses pfSense's duplicate automatic backup
     * after the coordinator has already created and confirmed the recovery point.
     *
     * @param string $description     - Configuration revision description.
     * @param bool   $backup          - Whether pfSense should create another backup.
     * @param bool   $writeConfigOnly - Whether unrelated synchronization is skipped.
     *
     * @return array|bool
     *
     * @since 2.0.0
     */
    function write_config(
        string $description = 'Unknown',
        bool $backup = true,
        bool $writeConfigOnly = false,
    ): array|bool {
        global $adapterTestEvents;
        global $adapterTestWriteResult;

        $adapterTestEvents[] = sprintf(
            'write:%s:%s:%s',
            $description,
            ($backup) ? 'backup' : 'no-backup',
            ($writeConfigOnly) ? 'write-only' : 'synchronize',
        );

        return $adapterTestWriteResult;
    }
}

/** @var array<string, bool> $adapterTestEnabledPaths Simulated "enable" flags by path and key. */
$adapterTestEnabledPaths = [];

/** @var array<string, mixed> $adapterTestConfiguration Simulated configuration values by path. */
$adapterTestConfiguration = [];

if (function_exists('config_get_path') === false) {
    /**
     * Returns a simulated configuration value or the caller's default.
     *
     * @param string $path    - Configuration path.
     * @param mixed  $default - Missing-path default.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    function config_get_path(string $path, mixed $default = null): mixed
    {
        global $adapterTestConfiguration;

        return $adapterTestConfiguration[$path] ?? $default;
    }
}

if (function_exists('config_path_enabled') === false) {
    /**
     * Reports a simulated pfSense "enable" flag.
     *
     * @param string $path      - Configuration path.
     * @param string $enableKey - Flag name.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    function config_path_enabled(string $path, string $enableKey = 'enable'): bool
    {
        global $adapterTestEnabledPaths;

        return $adapterTestEnabledPaths[$path . '/' . $enableKey] ?? false;
    }
}

if (function_exists('interfaces_configure') === false) {
    /**
     * Records a simulated interface reconfiguration.
     *
     * @return int
     *
     * @since 2.0.0
     */
    function interfaces_configure(): int
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'interfaces_configure';

        return 0;
    }
}

if (function_exists('filter_configure') === false) {
    /**
     * Records a simulated filter reload that succeeds.
     *
     * @return int
     *
     * @since 2.0.0
     */
    function filter_configure(): int
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'filter_configure';

        return 0;
    }
}

if (function_exists('services_dhcpd_configure') === false) {
    /**
     * Records a simulated DHCP server reload and its address family.
     *
     * @param string $family - Address family.
     *
     * @return void
     *
     * @since 2.0.0
     */
    function services_dhcpd_configure(string $family = 'all'): void
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'services_dhcpd_configure:' . $family;
    }
}

if (function_exists('services_radvd_configure') === false) {
    /**
     * Records a simulated router advertisement daemon reload.
     *
     * @return void
     *
     * @since 2.0.0
     */
    function services_radvd_configure(): void
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'services_radvd_configure';
    }
}

if (function_exists('services_unbound_configure') === false) {
    /**
     * Records a simulated DNS Resolver reload and whether DHCP restarts too.
     *
     * @param bool $restartDhcp - Whether DHCP restarts afterwards.
     *
     * @return int
     *
     * @since 2.0.0
     */
    function services_unbound_configure(bool $restartDhcp = true): int
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'services_unbound_configure:' . (($restartDhcp) ? 'dhcp' : 'no-dhcp');

        return 0;
    }
}

if (function_exists('services_dnsmasq_configure') === false) {
    /**
     * Records a simulated DNS Forwarder reload and whether DHCP restarts too.
     *
     * @param bool $restartDhcp - Whether DHCP restarts afterwards.
     *
     * @return int
     *
     * @since 2.0.0
     */
    function services_dnsmasq_configure(bool $restartDhcp = true): int
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'services_dnsmasq_configure:' . (($restartDhcp) ? 'dhcp' : 'no-dhcp');

        return 0;
    }
}

if (function_exists('system_routing_configure') === false) {
    /**
     * Records a simulated static route and gateway reconfiguration.
     *
     * @return void
     *
     * @since 2.0.0
     */
    function system_routing_configure(): void
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'system_routing_configure';
    }
}

if (function_exists('setup_gateways_monitor') === false) {
    /**
     * Records a simulated gateway monitor restart.
     *
     * @return void
     *
     * @since 2.0.0
     */
    function setup_gateways_monitor(): void
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'setup_gateways_monitor';
    }
}

if (function_exists('system_resolvconf_generate') === false) {
    /**
     * Records a simulated resolver configuration rewrite.
     *
     * @return void
     *
     * @since 2.0.0
     */
    function system_resolvconf_generate(): void
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'system_resolvconf_generate';
    }
}

if (function_exists('mwexec') === false) {
    /**
     * Records a simulated shell command.
     *
     * @param string $command - Shell command.
     *
     * @return int
     *
     * @since 2.0.0
     */
    function mwexec(string $command): int
    {
        global $adapterTestEvents;

        $adapterTestEvents[] = 'mwexec:' . $command;

        return 0;
    }
}

require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/PfSenseAdapter.php');

/**
 * Asserts that two adapter-test values are strictly identical.
 *
 * Exact comparisons protect boolean pfSense API flags and ordered transaction events
 * from type coercion that could hide a regression.
 *
 * @param mixed  $expected - Expected value.
 * @param mixed  $actual   - Actual value.
 * @param string $message  - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When values differ.
 *
 * @since 2.0.0
 */
function assertAdapterSame(mixed $expected, mixed $actual, string $message): void
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
 * Asserts that an adapter callback throws a runtime exception.
 *
 * Failure-boundary checks use this helper before inspecting whether a later native API
 * call was incorrectly reached.
 *
 * @param callable $callback - Callback under test.
 * @param string   $message  - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When the callback does not throw.
 *
 * @since 2.0.0
 */
function assertAdapterThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

$platformAdapter = new PfSenseAdapter();
$observedAddress = $platformAdapter->getCurrentIpv6Address('lan');

assertAdapterSame(
    '2001:0DB8:ABCD:1200::42',
    $observedAddress,
    'The adapter should trim the selected active address.',
);
assertAdapterSame(
    [
        [
            'interface' => 'lan',
            'flush' => true,
            'linklocal_fallback' => false,
            'gateways_status' => false,
        ],
    ],
    $adapterTestAddressRequests,
    'The adapter should query the configured interface with a forced refresh and no link-local fallback.',
);

$platformAdapter->backupConfiguration();
$platformAdapter->writeConfiguration('Adapter transaction test');

assertAdapterSame(
    [
        'backup',
        'write:Adapter transaction test:no-backup:write-only',
    ],
    $adapterTestEvents,
    'The native backup call should precede the single write-only configuration commit.',
);

$adapterTestEvents = [];
$adapterTestBackupResult = false;

assertAdapterThrows(
    static function () use ($platformAdapter): void {
        $platformAdapter->backupConfiguration();
    },
    'A failed native backup must throw before the write boundary.',
);
assertAdapterSame(
    ['backup'],
    $adapterTestEvents,
    'A failed backup must not invoke the configuration writer.',
);

$adapterTestEvents = [];

$platformAdapter->reloadServices(['dns_forwarder', 'filter', 'router_advertisements', 'dhcpv6', 'dns_resolver']);

assertAdapterSame(
    [
        'filter_configure',
        'services_dhcpd_configure:inet6',
        'services_unbound_configure:no-dhcp',
        'services_dnsmasq_configure:no-dhcp',
    ],
    $adapterTestEvents,
    'New services must reload once in a fixed order, and a DHCPv6 reload must cover router advertisements.',
);

$adapterTestEvents = [];
$adapterTestEnabledPaths = [
    'unbound/enable' => true,
    'unbound/regdhcpstatic' => true,
];

$platformAdapter->reloadServices(['dns_resolver', 'dhcpv6']);

assertAdapterSame(
    ['services_unbound_configure:dhcp'],
    $adapterTestEvents,
    'With static mappings registered in the DNS Resolver, a DHCPv6 reload must use the DNS Resolver reload once, as the DHCPv6 page does.',
);

$adapterTestEvents = [];

$platformAdapter->reloadServices(['dhcpv6', 'dns_forwarder', 'router_advertisements', 'filter', 'interfaces']);

assertAdapterSame(
    ['interfaces_configure', 'filter_configure'],
    $adapterTestEvents,
    'An interface reload already restarts DHCPv6, radvd, and both DNS services, so they must not reload again.',
);

$adapterTestEvents = [];
$adapterTestEnabledPaths = [
    'unbound/enable' => true,
];

$platformAdapter->reloadServices(['none', 'dns', 'dns_resolver', 'filter', 'routing']);

assertAdapterSame(
    [
        'system_routing_configure',
        'setup_gateways_monitor',
        'filter_configure',
        'system_resolvconf_generate',
        'services_unbound_configure:no-dhcp',
    ],
    $adapterTestEvents,
    'A routing reload must apply routes and gateways, then the filter once, and a DNS server reload must rewrite resolv.conf and reload only the enabled DNS service.',
);

$adapterTestEvents = [];

$platformAdapter->deleteRoutes(['2001:db8:1111:22e0::/60', 'LAN_NETWORKS', '2001:db8:1111:22e0::/60']);

assertAdapterSame(
    ["mwexec:/sbin/route delete -inet6 '2001:db8:1111:22e0::/60'"],
    $adapterTestEvents,
    'A replaced static route network must be deleted once, and an alias name in the network field must be left alone.',
);

$adapterTestConfiguration = [
    'installedpackages/ipv6prefixupdater/fields' => [
        'schema' => '1',
        'include' => ['item' => 'firewall-rule:1700000001:source'],
    ],
];

assertAdapterSame(
    ['schema' => '1', 'include' => ['item' => 'firewall-rule:1700000001:source']],
    $platformAdapter->getFieldSelection(),
    'The saved field selection must be returned exactly as stored, for the coordinator to validate.',
);

$adapterTestEvents = [];

assertAdapterSame(
    [
        [
            'section' => 'sample',
            'package' => null,
            'status' => 'failed',
            'error' => Translator::translate('dependency_unavailable', ['/etc/inc/xmlparse.inc']),
        ],
    ],
    $platformAdapter->reapplyPackages(['sample', 'sample']),
    'A package that cannot be re-applied must be reported once, without throwing.',
);
assertAdapterSame([], $adapterTestEvents, 'A failed package lookup must not reload anything.');

fwrite(STDOUT, "9 pfSense adapter regression checks passed.\n");

exit(0);

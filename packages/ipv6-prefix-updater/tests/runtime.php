<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationChangePlanner;
use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationFieldDetector;
use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationTargetScanner;
use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;
use PfSense\Ipv6PrefixUpdater\Domain\LegacyInstallation;
use PfSense\Ipv6PrefixUpdater\Runtime\BackupStore;
use PfSense\Ipv6PrefixUpdater\Runtime\FileLock;
use PfSense\Ipv6PrefixUpdater\Runtime\LegacyMigrator;
use PfSense\Ipv6PrefixUpdater\Runtime\PlatformAdapter;
use PfSense\Ipv6PrefixUpdater\Runtime\PrefixUpdater;
use PfSense\Ipv6PrefixUpdater\Runtime\StateStore;

require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/BackupStore.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationChangePlanner.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationFieldDetector.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationTargetScanner.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/FieldSelection.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/FileLock.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/LegacyInstallation.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/LegacyMigrator.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/PlatformAdapter.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/PrefixUpdater.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/StateStore.php');

/**
 * Simulates the pfSense API boundary for coordinator tests.
 *
 * Mutable controls model platform failure points while counters prove no-op and dry-run
 * behavior never reaches configuration writes or service reloads.
 *
 * @since 2.0.0
 */
final class FakePlatformAdapter implements PlatformAdapter
{
    /** @var int Number of configuration backup attempts. */
    public int $backupCount = 0;

    /** @var bool Whether configuration backup should fail. */
    public bool $backupShouldFail = false;

    /** @var array Fake pfSense configuration. */
    public array $configuration = [];

    /** @var int Number of root configuration reads. */
    public int $configurationReadCount = 0;

    /** @var string Active interface IPv6 address. */
    public string $currentAddress = '2001:db8:1111:2200::1';

    /** @var array<string, string|null> Per-interface addresses; null simulates no address. */
    public array $addresses = [];

    /** @var array<string, string> Operating-system devices mapped to friendly interfaces. */
    public array $devices = [];

    /** @var int Number of crontab regenerations. */
    public int $cronReloadCount = 0;

    /** @var string Simulated active configuration file. */
    public string $configurationFile = '';

    /** @var bool Whether environment validation should fail. */
    public bool $environmentShouldFail = false;

    /** @var array Configured friendly interfaces. */
    public array $interfaces = ['wan'];

    /** @var list<string> Safe log messages. */
    public array $logs = [];

    /** @var bool Whether notification delivery should fail. */
    public bool $notificationShouldFail = false;

    /** @var list<array> Recorded notices. */
    public array $notices = [];

    /** @var bool Whether service reload should fail. */
    public bool $reloadShouldFail = false;

    /** @var list<string> Reloaded service names. */
    public array $reloadedServices = [];

    /** @var list<string> Static route networks deleted before the reloads. */
    public array $deletedRoutes = [];

    /** @var list<string> Friendly interfaces requested for source-address discovery. */
    public array $requestedInterfaces = [];

    /** @var bool Whether reload preflight should fail. */
    public bool $reloadValidationShouldFail = false;

    /** @var array Persisted package settings. */
    public array $settings = [];

    /** @var mixed Field selection saved from the Fields tab, exactly as stored. */
    public mixed $fieldSelection = null;

    /** @var array<string, string> Installed package that owns each settings section. */
    public array $packageOwners = [];

    /** @var list<string> Packages whose settings fail to apply again. */
    public array $failingPackages = [];

    /** @var list<string> Package settings sections applied again, in order. */
    public array $reappliedSections = [];

    /** @var list<string> Ordered backup, mutation, and write events. */
    public array $transactionEvents = [];

    /** @var int Number of configuration write attempts. */
    public int $writeCount = 0;

    /** @var bool Whether the backup-aware write should fail. */
    public bool $writeShouldFail = false;

    /**
     * Confirms or rejects the simulated environment.
     *
     * Tests use this switch to exercise the adapter contract without loading any
     * pfSense includes inside the PHP container.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function assertEnvironment(): void
    {
        if ($this->environmentShouldFail) {
            throw new RuntimeException('Unsupported environment.');
        }
    }

    /**
     * Confirms or rejects service reload preflight.
     *
     * The coordinator must complete this stage before setting the first planned value.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function assertReloadCapabilities(array $services): void
    {
        if ($this->reloadValidationShouldFail) {
            throw new RuntimeException('Reload integration unavailable.');
        }
    }

    /**
     * Returns the simulated configuration snapshot.
     *
     * The read counter proves unchanged prefixes exit before this potentially expensive
     * and sensitive configuration operation.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public function getConfiguration(): array
    {
        $this->configurationReadCount += 1;

        return $this->configuration;
    }

    /**
     * Returns one simulated value through slash-path traversal.
     *
     * Exact path semantics mirror the production adapter sufficiently for stale-plan
     * and post-write verification tests.
     *
     * @param string $path - Configuration path.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getConfigurationValue(string $path): mixed
    {
        $segments = explode('/', $path);
        $value = $this->configuration;

        foreach ($segments as $segment) {
            if (!is_array($value) || array_key_exists($segment, $value) === false) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Returns the simulated active source address.
     *
     * The friendly interface is validated separately, matching production coordinator
     * ordering while keeping this fake intentionally small.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public function getCurrentIpv6Address(string $interface): string
    {
        $this->requestedInterfaces[] = $interface;

        if (array_key_exists($interface, $this->addresses)) {
            if ($this->addresses[$interface] === null) {
                throw new RuntimeException('No address on ' . $interface . '.');
            }

            return $this->addresses[$interface];
        }

        return $this->currentAddress;
    }

    /**
     * Returns simulated package settings.
     *
     * Individual tests replace this record to exercise runtime validation and package
     * enablement behavior.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Returns the simulated field selection exactly as stored.
     *
     * Tests store malformed entries here to prove the coordinator validates them.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    public function getFieldSelection(): mixed
    {
        return $this->fieldSelection;
    }

    /**
     * Reports whether a simulated friendly interface exists.
     *
     * Strict membership matches the production adapter's configuration-key check.
     *
     * @param string $interface - Friendly interface name.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public function interfaceExists(string $interface): bool
    {
        return in_array($interface, $this->interfaces, true);
    }

    /**
     * Records one safe operational log message.
     *
     * Tests inspect only counts and selected summaries, never full configuration data.
     *
     * @param string $message - Safe log message.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function log(string $message): void
    {
        $this->logs[] = $message;
    }

    /**
     * Records or rejects a simulated native notice.
     *
     * Notification failure occurs after a successful configuration transaction and must
     * not roll back last-successful prefix state.
     *
     * @param string $identifier - Notice identifier.
     * @param string $message    - Safe notice message.
     * @param int    $priority   - Notice priority.
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
        if ($this->notificationShouldFail) {
            throw new RuntimeException('Notification failure.');
        }

        $this->notices[] = [
            'identifier' => $identifier,
            'message' => $message,
            'priority' => $priority,
            'local_only' => $localOnly,
            'page' => $page,
        ];
    }

    /**
     * Returns the friendly interface for a simulated device.
     *
     * @param string $device - Operating-system device name.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public function findInterfaceByDevice(string $device): ?string
    {
        return $this->devices[$device] ?? null;
    }

    /**
     * Records a simulated crontab regeneration.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function reloadCron(): void
    {
        $this->cronReloadCount += 1;
        $this->transactionEvents[] = 'cron';
    }

    /**
     * Returns a simulated configuration file, creating it on first use.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public function getConfigurationFilePath(): string
    {
        if ($this->configurationFile === '') {
            $this->configurationFile = makeTemporaryDirectory() . '/config.xml';

            file_put_contents($this->configurationFile, "<?xml version=\"1.0\"?>\n<pfsense><version>23.3</version></pfsense>\n");
        }

        return $this->configurationFile;
    }

    /**
     * Records the static route networks a run deleted before its reloads.
     *
     * @param list<string> $networks - Previous static route networks.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function deleteRoutes(array $networks): void
    {
        $this->deletedRoutes = $networks;
    }

    /**
     * Records or rejects simulated service reloads.
     *
     * A failure models the post-write critical path where configuration is committed but
     * last-successful state must remain at the previous prefix.
     *
     * @param list<string> $services - Required service names.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function reloadServices(array $services): void
    {
        $this->reloadedServices = $services;

        if ($this->reloadShouldFail) {
            throw new RuntimeException('Reload failure.');
        }
    }

    /**
     * Records simulated package re-applies and reports each outcome.
     *
     * A section without an owner or a failing package reports its outcome the way the
     * production adapter does, without throwing.
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

        foreach ($sections as $section) {
            $this->reappliedSections[] = $section;
            $package = $this->packageOwners[$section] ?? null;

            if ($package === null) {
                $outcomes[] = ['section' => $section, 'package' => null, 'status' => 'unknown_owner', 'error' => null];
            } elseif (in_array($package, $this->failingPackages, true)) {
                $outcomes[] = ['section' => $section, 'package' => $package, 'status' => 'failed', 'error' => 'Resync failure.'];
            } else {
                $outcomes[] = ['section' => $section, 'package' => $package, 'status' => 'reapplied', 'error' => null];
            }
        }

        return $outcomes;
    }

    /**
     * Records or rejects a simulated configuration backup.
     *
     * A dedicated event proves the recovery point precedes every planned in-memory
     * mutation and the single configuration write.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function backupConfiguration(): void
    {
        $this->backupCount += 1;
        $this->transactionEvents[] = 'backup';

        if ($this->backupShouldFail) {
            throw new RuntimeException('Backup failure.');
        }
    }

    /**
     * Sets one simulated configuration path.
     *
     * References walk the nested array so the same exact paths emitted by the scanner
     * can be verified before and after write attempts.
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
        $this->transactionEvents[] = 'set:' . $path;
        $segments = explode('/', $path);
        $target = &$this->configuration;

        foreach ($segments as $segment) {
            if (!is_array($target)) {
                throw new RuntimeException('Invalid fake configuration path.');
            }

            if (array_key_exists($segment, $target) === false) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    /**
     * Records or rejects the simulated configuration write.
     *
     * Planned in-memory values already exist at this stage, allowing tests to verify
     * restoration after a write failure and exact backup-before-write ordering.
     *
     * @param string $description - Configuration revision description.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function writeConfiguration(string $description): void
    {
        $this->writeCount += 1;
        $this->transactionEvents[] = 'write';

        if ($this->writeShouldFail) {
            throw new RuntimeException('Write failure.');
        }
    }
}

/**
 * Asserts that two runtime-test values are strictly identical.
 *
 * A single diagnostic format keeps custom PHP tests dependency-free while preserving
 * exact type checks for addresses, state records, counters, and service lists.
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
function assertRuntimeSame(mixed $expected, mixed $actual, string $message): void
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
 * Asserts that a callback throws the requested runtime exception type.
 *
 * Failure-path tests use this helper while separately inspecting state and simulated
 * configuration after the coordinator unwinds.
 *
 * @param callable                $callback      - Callback under test.
 * @param class-string<Throwable> $expectedClass - Expected throwable type.
 * @param string                  $message       - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When the callback does not throw the requested type.
 *
 * @since 2.0.0
 */
function assertRuntimeThrows(callable $callback, string $expectedClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s Expected %s, received %s.',
            $message,
            $expectedClass,
            $throwable::class,
        ));
    }

    throw new RuntimeException(sprintf('%s Expected %s to be thrown.', $message, $expectedClass));
}

/**
 * Builds one complete sanitized configuration fixture.
 *
 * Every approved subsystem includes a matching local IPv6 field and an excluded remote
 * or dynamic field so scanner boundaries are tested together with prefix planning.
 *
 * @return array
 *
 * @since 2.0.0
 */
function makeConfigurationFixture(): array
{
    return [
        'aliases' => [
            'alias' => [
                [
                    'name' => 'SERVICES_V6',
                    'type' => 'host',
                    'address' => '2001:db8:1111:22aa::10 192.0.2.1 OTHER_ALIAS 2001:db8:9999::1',
                ],
                [
                    'name' => 'PORTS',
                    'type' => 'port',
                    'address' => '443 2001:db8:1111:22aa::20',
                ],
            ],
        ],
        'interfaces' => [
            'wan' => [
                'ipaddrv6' => 'dhcp6',
            ],
            'lan' => [
                'ipaddrv6' => '2001:db8:1111:22bb::1',
            ],
        ],
        'virtualip' => [
            'vip' => [
                [
                    'uniqid' => 'vip-service',
                    'mode' => 'ipalias',
                    'interface' => 'lan',
                    'subnet' => '2001:db8:1111:22cc::5',
                    'subnet_bits' => '128',
                ],
                [
                    'uniqid' => 'proxyarp',
                    'mode' => 'proxyarp',
                    'subnet' => '2001:db8:1111:22cc::6',
                ],
            ],
        ],
        'ipsec' => [
            'phase2' => [
                [
                    'uniqid' => 'phase-two-v6',
                    'mode' => 'tunnel6',
                    'localid' => [
                        'type' => 'network',
                        'address' => '2001:db8:1111:22dd::/64',
                    ],
                    'natlocalid' => [
                        'type' => 'address',
                        'address' => '2001:db8:1111:22dd::42',
                    ],
                    'remoteid' => [
                        'type' => 'network',
                        'address' => '2001:db8:1111:22ee::/64',
                    ],
                ],
            ],
        ],
        'openvpn' => [
            'openvpn-server' => [
                [
                    'vpnid' => '1',
                    'tunnel_networkv6_type' => 'network',
                    'tunnel_networkv6' => '2001:db8:1111:22e0::/64',
                    'local_networkv6' => '2001:db8:1111:22e1::/64, 2001:db8:9999::/64',
                    'remote_networkv6' => '2001:db8:1111:22e2::/64',
                ],
                [
                    'vpnid' => '2',
                    'tunnel_networkv6_type' => 'track6',
                    'tunnel_networkv6' => '2001:db8:1111:22e3::/64',
                ],
            ],
            'openvpn-client' => [
                [
                    'vpnid' => '3',
                    'tunnel_networkv6' => '2001:db8:1111:22e4::/64',
                    'remote_networkv6' => '2001:db8:1111:22e5::/64',
                ],
            ],
            'openvpn-csc' => [
                [
                    'common_name' => 'road-warrior',
                    'tunnel_networkv6' => '2001:db8:1111:22e6::10/128',
                    'local_networkv6' => '2001:db8:1111:22e7::/64',
                    'remote_networkv6' => '2001:db8:1111:22e8::/64',
                ],
            ],
        ],
        'installedpackages' => [
            'wireguard' => [
                'tunnels' => [
                    'item' => [
                        [
                            'name' => 'tun_wg0',
                            'addresses' => [
                                'row' => [
                                    [
                                        'address' => '2001:db8:1111:22f0::1',
                                        'mask' => '64',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'peers' => [
                    'item' => [
                        [
                            'allowedips' => [
                                'row' => [
                                    [
                                        'address' => '2001:db8:1111:22f1::',
                                        'mask' => '64',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Builds a sanitized fixture that covers every field group.
 *
 * Every field group holds a value inside 2001:db8:1111:2200::/56, beside remote, secret,
 * Base64, and other values that belong to Other Settings. Two other packages hold
 * settings too: "sample" is installed, and nothing owns "orphan".
 *
 * @return array
 *
 * @since 2.0.0
 */
function makeLocalFieldsFixture(): array
{
    return [
        'interfaces' => [
            'lan' => [
                'descr' => 'Office',
                'ipaddrv6' => 'track6',
            ],
        ],
        'aliases' => [
            'alias' => [
                [
                    'name' => 'NOTES',
                    'type' => 'host',
                    'address' => '2001:db8:1111:22aa::77',
                    'detail' => 'was 2001:db8:1111:22aa::78',
                ],
            ],
        ],
        'dhcpdv6' => [
            'lan' => [
                'range' => [
                    'from' => '2001:db8:1111:22aa::1000',
                    'to' => '2001:db8:1111:22aa::2000',
                ],
                'prefixrange' => [
                    'from' => '2001:db8:1111:22f0::',
                    'to' => '2001:db8:1111:22fe::',
                    'prefixlength' => '64',
                ],
                'dnsserver' => [
                    '2001:db8:1111:22aa::1',
                    '2001:4860:4860::8888',
                ],
                'ntpserver' => [
                    '2001:db8:1111:22aa::123',
                ],
                'pool' => [
                    [
                        'descr' => 'Lab pool',
                        'range' => [
                            'from' => '2001:db8:1111:22aa::3000',
                            'to' => '2001:db8:1111:22aa::3fff',
                        ],
                    ],
                ],
                'staticmap' => [
                    [
                        'duid' => '00:01:00:01:2a:3b:4c:5d:00:11:22:33:44:55',
                        'ipaddrv6' => '2001:db8:1111:22aa::50',
                        'hostname' => 'printer',
                    ],
                    [
                        'duid' => '00:01:00:01:2a:3b:4c:5d:00:11:22:33:44:66',
                        'ipaddrv6' => '::60',
                        'hostname' => 'suffix-only',
                    ],
                ],
                'radnsserver' => [
                    '2001:db8:1111:22aa::1',
                ],
                'subnets' => [
                    'item' => [
                        '2001:db8:1111:22ab::/64',
                    ],
                ],
            ],
            'opt1' => [
                'range' => [
                    'from' => '::1000',
                    'to' => '::2000',
                ],
            ],
        ],
        'unbound' => [
            'hosts' => [
                [
                    'host' => 'nas',
                    'domain' => 'home.arpa',
                    'ip' => '192.168.1.10, 2001:db8:1111:22aa::10',
                ],
            ],
            'domainoverrides' => [
                [
                    'domain' => 'corp.example',
                    'ip' => '2001:db8:1111:22aa::53@5353',
                ],
            ],
            'custom_options' => base64_encode("server:\nlocal-data: \"printer.home.arpa AAAA 2001:db8:1111:22aa::50\"\n"),
        ],
        'dnsmasq' => [
            'hosts' => [
                [
                    'host' => 'nas',
                    'domain' => 'home.arpa',
                    'ip' => '192.168.1.10',
                ],
                [
                    'host' => 'nas',
                    'domain' => 'home.arpa',
                    'ip' => '2001:db8:1111:22aa::10',
                ],
            ],
        ],
        'filter' => [
            'rule' => [
                [
                    'tracker' => '1700000001',
                    'descr' => 'Allow web',
                    'source' => [
                        'any' => '',
                    ],
                    'destination' => [
                        'address' => '2001:db8:1111:22aa::10',
                    ],
                ],
                [
                    'tracker' => '1700000002',
                    'descr' => 'Allow LAN to web',
                    'source' => [
                        'network' => 'lan',
                    ],
                    'destination' => [
                        'address' => 'WEB_SERVERS',
                    ],
                ],
                [
                    'descr' => 'Imported without tracker',
                    'source' => [
                        'address' => '2001:db8:1111:22bb::/64',
                    ],
                    'destination' => [
                        'any' => '',
                    ],
                ],
            ],
        ],
        'nat' => [
            'rule' => [
                [
                    'created' => [
                        'time' => 1700000100,
                        'username' => 'admin',
                    ],
                    'descr' => 'Web forward',
                    'destination' => [
                        'address' => '2001:db8:1111:22aa::1',
                    ],
                    'target' => '2001:db8:1111:22aa::10',
                ],
            ],
            'outbound' => [
                'rule' => [
                    [
                        'created' => [
                            'time' => 1700000200,
                            'username' => 'admin',
                        ],
                        'source' => [
                            'network' => '2001:db8:1111:22cc::/64',
                        ],
                        'destination' => [
                            'any' => '',
                        ],
                        'target' => '2001:db8:1111:22ff::1',
                    ],
                ],
            ],
            'onetoone' => [
                [
                    'descr' => 'Camera',
                    'external' => '2001:db8:1111:22ff::20',
                    'source' => [
                        'address' => 'fd00::20',
                    ],
                    'destination' => [
                        'any' => '',
                    ],
                ],
            ],
            'npt' => [
                [
                    'descr' => 'ULA',
                    'source' => [
                        'address' => 'fd00:1::/64',
                    ],
                    'destination' => [
                        'address' => '2001:db8:1111:22d0::/64',
                    ],
                ],
                [
                    'descr' => 'Tracked',
                    'source' => [
                        'address' => 'fd00:2::/64',
                    ],
                    'destination' => [
                        'network' => 'lan',
                    ],
                ],
            ],
        ],
        'gateways' => [
            'gateway_item' => [
                [
                    'name' => 'LAN_ROUTER',
                    'gateway' => '2001:db8:1111:22aa::fe',
                    'monitor' => '2001:db8:1111:22aa::fe',
                ],
            ],
        ],
        'staticroutes' => [
            'route' => [
                [
                    'network' => '2001:db8:1111:22e0::/60',
                    'gateway' => 'LAN_ROUTER',
                    'descr' => 'Downstream router',
                ],
            ],
        ],
        'ipsec' => [
            'phase1' => [
                [
                    'ikeid' => '1',
                    'remote-gateway' => '2001:db8:1111:22aa::99',
                ],
            ],
            'phase2' => [
                [
                    'uniqid' => 'phase-two-v6',
                    'mode' => 'tunnel6',
                    'localid' => [
                        'type' => 'network',
                        'address' => '2001:db8:1111:22dd::/64',
                    ],
                    'remoteid' => [
                        'type' => 'network',
                        'address' => '2001:db8:1111:22ee::/64',
                    ],
                ],
            ],
            'mobilekey' => [
                [
                    'ident' => 'road-warrior',
                    'pre-shared-key' => '2001:db8:1111:22aa::abc',
                ],
            ],
        ],
        'openvpn' => [
            'openvpn-server' => [
                [
                    'vpnid' => '1',
                    'tunnel_networkv6_type' => 'network',
                    'remote_networkv6' => '2001:db8:1111:22e2::/64',
                ],
            ],
        ],
        'installedpackages' => [
            'wireguard' => [
                'peers' => [
                    'item' => [
                        [
                            'allowedips' => [
                                'row' => [
                                    [
                                        'address' => '2001:db8:1111:22f1::',
                                        'mask' => '64',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'sample' => [
                'config' => [
                    [
                        'listen_address' => '2001:db8:1111:22aa::80',
                        'admin_password' => '2001:db8:1111:22aa::81',
                    ],
                ],
                'servers' => [
                    'item' => [
                        [
                            'name' => 'web',
                            'address' => '2001:db8:1111:22aa::82',
                        ],
                        [
                            'address' => '2001:db8:1111:22aa::83',
                        ],
                    ],
                ],
            ],
            'orphan' => [
                'config' => [
                    [
                        'upstream' => '2001:db8:1111:22aa::84',
                    ],
                ],
            ],
        ],
        'cert' => [
            [
                'refid' => 'webgui',
                'descr' => 'WebGUI certificate',
                'crt' => base64_encode("subjectAltName = IP:2001:db8:1111:22aa::1\n"),
            ],
        ],
        'system' => [
            'dnsserver' => [
                '2001:db8:1111:22aa::53',
            ],
        ],
    ];
}

/**
 * Returns a field selection with only the given groups on.
 *
 * @param list<string> $groups - Groups to turn on; every other Recommended group is off.
 *
 * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
 *
 * @since 2.0.0
 */
function makeGroupSelection(array $groups): array
{
    $selection = FieldSelection::clearAll();

    foreach ($groups as $group) {
        $selection['groups'][$group] = true;
    }

    return $selection;
}

/**
 * Returns monitoring settings with no saved field selection.
 *
 * Without a saved selection every Recommended group is on, as after a fresh install.
 * Individual coordinator tests clone and modify this record only where behavior under
 * test requires a different notification or enablement value.
 *
 * @return array
 *
 * @since 2.0.0
 */
function makeSettingsFixture(): array
{
    return [
        'enabled' => 'on',
        'row' => [
            [
                'interface' => 'wan',
                'prefix_length' => '56',
            ],
        ],
        'notifications' => 'on',
    ];
}

/**
 * Creates a backup store in a new temporary directory.
 *
 * @return BackupStore
 *
 * @since 2.0.0
 */
function makeBackupStore(): BackupStore
{
    return new BackupStore(makeTemporaryDirectory() . '/backups');
}

/**
 * Creates a unique temporary directory for one filesystem test.
 *
 * A random suffix prevents parallel test processes from sharing state or locks.
 *
 * @return string
 *
 * @since 2.0.0
 */
function makeTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir() . '/ipv6-prefix-updater-' . bin2hex(random_bytes(8));

    if (mkdir($directory, 0700, true) === false) {
        throw new RuntimeException('Unable to create a runtime test directory.');
    }

    return $directory;
}

/**
 * Creates a simulated version 1.x installation under a temporary root.
 *
 * @param array<string, string> $caches  - Cache contents keyed by device.
 * @param array<string, string> $backups - Backup contents keyed by file name.
 * @param bool                  $script  - Whether the script folder exists.
 *
 * @return string
 *
 * @since 2.0.0
 */
function makeLegacyRoot(array $caches, array $backups, bool $script = true): string
{
    $root = makeTemporaryDirectory();

    if ($script) {
        mkdir($root . '/pfsense-ipv6-prefix-updater/lib', 0755, true);
        file_put_contents($root . '/pfsense-ipv6-prefix-updater/update.php', "<?php\n// Version 1.x entry point.\n");
        file_put_contents($root . '/pfsense-ipv6-prefix-updater/lib/ip.php', "<?php\n");
    }

    if (count($caches) > 0) {
        mkdir($root . '/pfsense-ipv6-prefix-updater-cache', 0755);

        foreach ($caches as $device => $contents) {
            file_put_contents($root . '/pfsense-ipv6-prefix-updater-cache/' . $device . '.cache', $contents);
        }
    }

    if (count($backups) > 0) {
        mkdir($root . '/pfsense-ipv6-prefix-updater-backups', 0755);

        foreach ($backups as $name => $contents) {
            file_put_contents($root . '/pfsense-ipv6-prefix-updater-backups/' . $name, $contents);
        }
    }

    return $root;
}

/**
 * Returns one Cron item in pfSense's configuration shape.
 *
 * @param string $command - Command.
 * @param string $minute  - Minute field.
 * @param string $hour    - Hour field.
 *
 * @return array<string, string>
 *
 * @since 2.0.0
 */
function makeCronItem(string $command, string $minute = '*', string $hour = '*'): array
{
    return [
        'minute' => $minute,
        'hour' => $hour,
        'mday' => '*',
        'month' => '*',
        'wday' => '*',
        'who' => 'root',
        'command' => $command,
    ];
}

/**
 * Returns settings that monitor several interfaces with every Recommended group on.
 *
 * @param array<string, string> $rows - Prefix lengths keyed by interface.
 *
 * @return array
 *
 * @since 2.0.0
 */
function makeMultiInterfaceSettings(array $rows): array
{
    $settings = makeSettingsFixture();
    $settings['row'] = [];

    foreach ($rows as $interface => $prefixLength) {
        $settings['row'][] = [
            'interface' => $interface,
            'prefix_length' => $prefixLength,
        ];
    }

    return $settings;
}

/**
 * Removes a temporary test directory tree.
 *
 * @param string $path - Directory or file.
 *
 * @return void
 *
 * @since 2.0.0
 */
function removeTestTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (is_dir($path) === false) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        removeTestTree($path . '/' . $entry);
    }

    rmdir($path);
}

/** @var array<string, Closure(): void> $tests Runtime test cases. */
$tests = [
    'scans and plans the selected field groups' => static function (): void {
        $configuration = makeConfigurationFixture();
        $selection = makeGroupSelection(['aliases', 'interfaces', 'virtual_ips', 'ipsec', 'openvpn', 'wireguard']);
        $targets = ConfigurationTargetScanner::scan($configuration, $selection);
        $plan = ConfigurationChangePlanner::plan(
            '2001:db8:1111:2200::1',
            56,
            '2001:db8:aaaa:bb00::1',
            56,
            $targets,
        );
        $changes = $plan['changes'];
        $changedPaths = array_column($changes, 'path');

        assertRuntimeSame(11, count($changes), 'Every approved local field should be planned exactly once.');
        assertRuntimeSame(
            false,
            in_array('ipsec/phase2/0/remoteid/address', $changedPaths, true),
            'Remote IPsec identities must stay unplanned while Other Settings is off.',
        );
        assertRuntimeSame(
            false,
            in_array('openvpn/openvpn-server/0/remote_networkv6', $changedPaths, true),
            'Remote OpenVPN networks must stay unplanned while Other Settings is off.',
        );
        assertRuntimeSame(
            false,
            in_array('installedpackages/wireguard/peers/item/0/allowedips/row/0/address', $changedPaths, true),
            'WireGuard peer allowed IPs must stay unplanned while Other Settings is off.',
        );
        assertRuntimeSame(
            false,
            in_array('openvpn/openvpn-server/1/tunnel_networkv6', $changedPaths, true),
            'Tracked OpenVPN tunnel networks must stay unplanned while Other Settings is off.',
        );

        $aliasChange = $changes[array_search('aliases/alias/0/address', $changedPaths, true)];

        assertRuntimeSame(
            '2001:db8:aaaa:bbaa::10 192.0.2.1 OTHER_ALIAS 2001:db8:9999::1',
            $aliasChange['after'],
            'Mixed alias lists should preserve separators and unrelated tokens.',
        );

        $openVpnChange = $changes[array_search('openvpn/openvpn-server/0/local_networkv6', $changedPaths, true)];

        assertRuntimeSame(
            '2001:db8:aaaa:bbe1::/64, 2001:db8:9999::/64',
            $openVpnChange['after'],
            'Comma-list planning should preserve CIDR suffixes and spacing.',
        );
    },
    'persists state atomically with restrictive permissions' => static function (): void {
        $directory = makeTemporaryDirectory();
        $path = $directory . '/state/state.json';
        $stateStore = new StateStore($path);
        $state = $stateStore->read();

        assertRuntimeSame(1, $state['schema_version'], 'A missing file should return the current schema.');

        $state['last_run'] = [
            'status' => 'test',
        ];

        $stateStore->write($state);

        assertRuntimeSame($state, $stateStore->read(), 'Written state should round-trip exactly.');
        assertRuntimeSame(0600, fileperms($path) & 0777, 'The state file should be owner-readable and owner-writable only.');

        file_put_contents($path, '{broken');

        assertRuntimeThrows(
            static function () use ($stateStore): void {
                $stateStore->read();
            },
            RuntimeException::class,
            'Malformed persisted state must fail closed.',
        );

        unlink($path);
        rmdir(dirname($path));
        rmdir($directory);
    },
    'prevents concurrent runs with a reusable file lock' => static function (): void {
        $directory = makeTemporaryDirectory();
        $path = $directory . '/updater.lock';
        $firstLock = new FileLock($path);
        $secondLock = new FileLock($path);

        assertRuntimeSame(true, $firstLock->acquire(), 'The first process should acquire the lock.');
        assertRuntimeSame(false, $secondLock->acquire(), 'A concurrent process should not acquire the lock.');

        $firstLock->release();

        assertRuntimeSame(true, $secondLock->acquire(), 'The lock file should be reusable after release.');
        assertRuntimeSame(0600, fileperms($path) & 0777, 'The lock file should use restrictive permissions.');

        $secondLock->release();
        unlink($path);
        rmdir($directory);
    },
    'fails before settings or state when the platform is unsupported' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->environmentShouldFail = true;

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(false, false);
            },
            RuntimeException::class,
            'An unsupported platform must stop before package state is initialized.',
        );

        assertRuntimeSame(false, file_exists($statePath), 'Environment failure must not create package state.');
        assertRuntimeSame(0, $platformAdapter->configurationReadCount, 'Environment failure must not read configuration.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'Environment failure must not write configuration.');

        rmdir($directory);
    },
    'keeps disabled scheduled checks free of state and configuration reads' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->settings['enabled'] = 'off';

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());
        $result = $prefixUpdater->run(false, false);

        assertRuntimeSame('disabled', $result['status'], 'A disabled scheduled check should exit successfully.');
        assertRuntimeSame(false, file_exists($statePath), 'A disabled scheduled check must not create state.');
        assertRuntimeSame(0, $platformAdapter->configurationReadCount, 'A disabled check must not read configuration.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A disabled check must not write configuration.');

        rmdir($directory);
    },
    'initializes, previews, commits, and no-ops in transaction order' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->currentAddress = '2001:0DB8:1111:2200:0000:0000:0000:0001';

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());
        $initialResult = $prefixUpdater->run(false, false);

        assertRuntimeSame('initialized', $initialResult['status'], 'A first run should establish a safe baseline.');
        assertRuntimeSame(
            '2001:db8:1111:2200::1',
            $initialResult['observed_address'],
            'The exact source address should be canonicalized and exposed for operator verification.',
        );
        assertRuntimeSame(
            ['wan'],
            $platformAdapter->requestedInterfaces,
            'Address discovery must use the configured friendly interface.',
        );
        assertRuntimeSame(0, $platformAdapter->configurationReadCount, 'Initialization should not scan configuration.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'Initialization should not write configuration.');

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->transactionEvents = [];

        $dryRunResult = $prefixUpdater->run(true, true);
        $stateAfterDryRun = $stateStore->read();

        assertRuntimeSame('dry_run', $dryRunResult['status'], 'A changed prefix should produce a dry-run plan.');
        assertRuntimeSame(
            '2001:db8:aaaa:bb00::1',
            $dryRunResult['observed_address'],
            'Dry-run output should identify the exact source address used for the plan.',
        );
        assertRuntimeSame(11, $dryRunResult['change_count'], 'The dry run should report every planned field.');
        assertRuntimeSame(false, $dryRunResult['backup_created'], 'A dry run must not create a configuration backup.');
        assertRuntimeSame([], $platformAdapter->transactionEvents, 'A dry run must not enter the mutation transaction.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A dry run must not write configuration.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $stateAfterDryRun['targets']['wan']['prefix'],
            'A dry run must not advance last-successful prefix state.',
        );

        $platformAdapter->transactionEvents = [];
        $successResult = $prefixUpdater->run(true, false);
        $stateAfterSuccess = $stateStore->read();

        assertRuntimeSame('success', $successResult['status'], 'A normal changed-prefix run should succeed.');
        assertRuntimeSame(true, $successResult['backup_created'], 'A committed run should report its recovery backup.');
        assertRuntimeSame(1, $platformAdapter->backupCount, 'A committed run should create exactly one backup.');
        assertRuntimeSame(1, $platformAdapter->writeCount, 'The complete plan should use exactly one configuration write.');
        assertRuntimeSame(
            'backup',
            $platformAdapter->transactionEvents[0] ?? null,
            'The first mutation-transaction event must create the configuration backup.',
        );
        assertRuntimeSame(
            'write',
            $platformAdapter->transactionEvents[count($platformAdapter->transactionEvents) - 1] ?? null,
            'The configuration write must follow every planned in-memory mutation.',
        );
        assertRuntimeSame(
            '2001:db8:aaaa:bb00::',
            $stateAfterSuccess['targets']['wan']['prefix'],
            'Verified success should advance last-successful prefix state.',
        );
        assertRuntimeSame(
            ['interfaces', 'filter', 'ipsec', 'openvpn', 'wireguard'],
            $platformAdapter->reloadedServices,
            'The coordinator should reload only services referenced by the plan, in scan order.',
        );
        assertRuntimeSame(1, count($platformAdapter->notices), 'Successful change notification should be sent once.');

        unset($stateAfterSuccess['targets']['wan']['source_address']);
        $stateStore->write($stateAfterSuccess);

        $configurationReadCount = $platformAdapter->configurationReadCount;
        $backupCount = $platformAdapter->backupCount;
        $writeCount = $platformAdapter->writeCount;
        $noticeCount = count($platformAdapter->notices);
        $noChangeResult = $prefixUpdater->run(false, false);
        $stateAfterNoChange = $stateStore->read();

        assertRuntimeSame('no_change', $noChangeResult['status'], 'Equivalent prefixes should produce a no-op result.');
        assertRuntimeSame(
            $configurationReadCount,
            $platformAdapter->configurationReadCount,
            'A no-op must exit before reading the root configuration.',
        );
        assertRuntimeSame(
            '2001:db8:aaaa:bb00::1',
            $stateAfterNoChange['targets']['wan']['source_address'],
            'A no-op should backfill source-address visibility for state from older package versions.',
        );
        assertRuntimeSame($backupCount, $platformAdapter->backupCount, 'A no-op must not create a backup.');
        assertRuntimeSame($writeCount, $platformAdapter->writeCount, 'A no-op must not write configuration.');
        assertRuntimeSame($noticeCount, count($platformAdapter->notices), 'A no-op must not create a notification.');

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'restores in-memory values and preserves state after write failure' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $before = $platformAdapter->configuration;

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->writeShouldFail = true;

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            RuntimeException::class,
            'A backup-aware write failure must stop the run.',
        );

        $state = $stateStore->read();

        assertRuntimeSame($before, $platformAdapter->configuration, 'Failed writes should restore planned in-memory values.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $state['targets']['wan']['prefix'],
            'Write failure must preserve the last-successful prefix.',
        );
        assertRuntimeSame('failure', $state['last_run']['status'], 'Write failure should remain visible in status.');
        assertRuntimeSame([], $platformAdapter->reloadedServices, 'Services must not reload after a failed write.');

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'stops before write when configuration backup fails' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $before = $platformAdapter->configuration;

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->backupShouldFail = true;

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            RuntimeException::class,
            'A backup failure must stop before configuration commit.',
        );

        $state = $stateStore->read();

        assertRuntimeSame(1, $platformAdapter->backupCount, 'The backup should be attempted exactly once.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'Configuration must not be written after backup failure.');
        assertRuntimeSame(
            ['backup'],
            $platformAdapter->transactionEvents,
            'Backup failure must happen before the first planned in-memory mutation.',
        );
        assertRuntimeSame($before, $platformAdapter->configuration, 'Backup failure should restore in-memory planned values.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $state['targets']['wan']['prefix'],
            'Backup failure must preserve the last-successful prefix.',
        );

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'stops before mutation when a required reload integration is unavailable' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $before = $platformAdapter->configuration;

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->reloadValidationShouldFail = true;

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            RuntimeException::class,
            'A missing reload integration must stop before the first planned mutation.',
        );

        $state = $stateStore->read();

        assertRuntimeSame($before, $platformAdapter->configuration, 'Reload preflight failure must not mutate values.');
        assertRuntimeSame(0, $platformAdapter->backupCount, 'Reload preflight failure must not create a backup.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'Reload preflight failure must not write configuration.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $state['targets']['wan']['prefix'],
            'Reload preflight failure must preserve the last-successful prefix.',
        );

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'preserves old success state after a required reload failure' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->reloadShouldFail = true;

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            RuntimeException::class,
            'A required reload failure must stop successful state advancement.',
        );

        $state = $stateStore->read();

        assertRuntimeSame(1, $platformAdapter->writeCount, 'Configuration should have been committed before reload failed.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $state['targets']['wan']['prefix'],
            'Reload failure must preserve the previous last-successful prefix.',
        );
        assertRuntimeSame('failure', $state['last_run']['status'], 'Reload failure should remain visible in status.');

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'accepts the saved prefix after a post-write reload failure' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->reloadShouldFail = true;

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            RuntimeException::class,
            'A reload failure must preserve the old baseline until the operator accepts recovery.',
        );

        $platformAdapter->reloadShouldFail = false;
        $retryResult = $prefixUpdater->run(true, false);

        assertRuntimeSame(
            'warning',
            $retryResult['status'],
            'Saved new-prefix values should expose the recovery warning on the next run.',
        );

        $configurationBeforeAccept = $platformAdapter->configuration;
        $configurationReadsBeforeAccept = $platformAdapter->configurationReadCount;
        $backupCountBeforeAccept = $platformAdapter->backupCount;
        $writeCountBeforeAccept = $platformAdapter->writeCount;
        $reloadsBeforeAccept = $platformAdapter->reloadedServices;
        $transactionEventsBeforeAccept = $platformAdapter->transactionEvents;

        $acceptedResult = $prefixUpdater->acceptCurrentPrefix();
        $acceptedState = $stateStore->read();

        assertRuntimeSame('accepted', $acceptedResult['status'], 'Recovery should have a distinct accepted outcome.');
        assertRuntimeSame(
            '2001:db8:aaaa:bb00::',
            $acceptedState['targets']['wan']['prefix'],
            'Recovery should advance the baseline to the active prefix.',
        );
        assertRuntimeSame(
            'accepted',
            $acceptedState['last_run']['status'],
            'Recovery should remain visible as the latest run outcome.',
        );
        assertRuntimeSame(
            $configurationBeforeAccept,
            $platformAdapter->configuration,
            'Accepting the current prefix must not change configuration.',
        );
        assertRuntimeSame(
            $configurationReadsBeforeAccept,
            $platformAdapter->configurationReadCount,
            'Accepting the current prefix must not read the root configuration.',
        );
        assertRuntimeSame(
            $backupCountBeforeAccept,
            $platformAdapter->backupCount,
            'Accepting the current prefix must not create a configuration backup.',
        );
        assertRuntimeSame(
            $writeCountBeforeAccept,
            $platformAdapter->writeCount,
            'Accepting the current prefix must not write configuration.',
        );
        assertRuntimeSame(
            $reloadsBeforeAccept,
            $platformAdapter->reloadedServices,
            'Accepting the current prefix must not reload services.',
        );
        assertRuntimeSame(
            $transactionEventsBeforeAccept,
            $platformAdapter->transactionEvents,
            'Accepting the current prefix must not enter the configuration transaction.',
        );

        $nextResult = $prefixUpdater->run(true, false);

        assertRuntimeSame('no_change', $nextResult['status'], 'The accepted baseline should end the warning loop.');
        assertRuntimeSame(
            $configurationReadsBeforeAccept,
            $platformAdapter->configurationReadCount,
            'The first check after acceptance should exit before reading configuration.',
        );

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'retains successful state when remote notification delivery fails' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = makeConfigurationFixture();

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $platformAdapter->notificationShouldFail = true;

        $result = $prefixUpdater->run(true, false);
        $state = $stateStore->read();

        assertRuntimeSame('success', $result['status'], 'Notification failure must not undo a verified commit.');
        assertRuntimeSame('failed', $result['notification'], 'Notification failure should be visible in the result.');
        assertRuntimeSame(
            '2001:db8:aaaa:bb00::',
            $state['targets']['wan']['prefix'],
            'Notification failure should retain the verified successful prefix.',
        );

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'accepts a zero-change prefix-length widening without writing configuration' => static function (): void {
        $directory = makeTemporaryDirectory();
        $statePath = $directory . '/state/state.json';
        $stateStore = new StateStore($statePath);
        $platformAdapter = new FakePlatformAdapter();

        $platformAdapter->settings = makeSettingsFixture();
        $platformAdapter->configuration = [
            'aliases' => [
                'alias' => [],
            ],
        ];

        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $prefixUpdater->run(false, false);

        $platformAdapter->settings['row'][0]['prefix_length'] = '48';

        $result = $prefixUpdater->run(true, false);
        $state = $stateStore->read();

        assertRuntimeSame('warning', $result['status'], 'A zero-change transition should be a visible warning.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A zero-change transition must not write configuration.');
        assertRuntimeSame(
            '2001:db8:1111:2200::',
            $state['targets']['wan']['prefix'],
            'A zero-change transition must not advance last-successful state.',
        );
        assertRuntimeSame(true, $platformAdapter->notices[0]['local_only'], 'The warning notice should remain local-only.');

        $configurationBeforeAccept = $platformAdapter->configuration;
        $acceptedResult = $prefixUpdater->acceptCurrentPrefix();
        $acceptedState = $stateStore->read();

        assertRuntimeSame('accepted', $acceptedResult['status'], 'The operator should be able to accept a widening.');
        assertRuntimeSame(
            '2001:db8:1111::',
            $acceptedState['targets']['wan']['prefix'],
            'Accepting a widening should store the active widened prefix.',
        );
        assertRuntimeSame(
            48,
            $acceptedState['targets']['wan']['prefix_length'],
            'Accepting a widening should store the active prefix length.',
        );
        assertRuntimeSame(
            $configurationBeforeAccept,
            $platformAdapter->configuration,
            'Accepting a widening must leave configuration unchanged.',
        );
        assertRuntimeSame(0, $platformAdapter->backupCount, 'Accepting a widening must not create a backup.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'Accepting a widening must not write configuration.');

        $nextResult = $prefixUpdater->run(true, false);

        assertRuntimeSame('no_change', $nextResult['status'], 'The accepted widening should end the warning loop.');

        unlink($statePath);
        rmdir(dirname($statePath));
        rmdir($directory);
    },
    'parses version 1.x Cron commands in the forms the old README produced' => static function (): void {
        $cases = [
            'php /root/pfsense-ipv6-prefix-updater/update.php em1 56' => ['device' => 'em1', 'prefix_length' => 56],
            '/usr/local/bin/php -f /root/pfsense-ipv6-prefix-updater/update.php igb1.10 60 > /dev/null 2>&1' => ['device' => 'igb1.10', 'prefix_length' => 60],
            'cd /root && php pfsense-ipv6-prefix-updater/update.php "lagg0.20" "48"' => ['device' => 'lagg0.20', 'prefix_length' => 48],
            'php /root/pfsense-ipv6-prefix-updater/update.php em1 72' => null,
            'php /root/pfsense-ipv6-prefix-updater/update.php' => null,
            '/usr/bin/nice -n20 newsyslog' => null,
        ];

        foreach ($cases as $command => $expected) {
            assertRuntimeSame($expected, LegacyInstallation::parseCommand($command), 'Unexpected parse for: ' . $command);
        }

        assertRuntimeSame(true, LegacyInstallation::isLegacyCommand('php /root/pfsense-ipv6-prefix-updater/update.php'), 'An unreadable 1.x command is still a 1.x command.');
    },
    'converts version 1.x saved prefixes into canonical networks' => static function (): void {
        $cases = [
            '2001:db8:abcd:78||56' => ['prefix' => '2001:db8:abcd:7800::', 'prefix_length' => 56],
            "2001:db8:abcd:78aa||64\n" => ['prefix' => '2001:db8:abcd:78aa::', 'prefix_length' => 64],
            '2001:db8:abcd||48' => ['prefix' => '2001:db8:abcd::', 'prefix_length' => 48],
            '2001:db8:abcd:7||52' => ['prefix' => '2001:db8:abcd:7000::', 'prefix_length' => 52],
            '2001:db8:abcd:1ff||56' => null,
            '2001:db8:abcd||56' => null,
            'fe80:0:0:12||56' => null,
            'garbage' => null,
        ];

        foreach ($cases as $contents => $expected) {
            assertRuntimeSame($expected, LegacyInstallation::parseCache((string) $contents), 'Unexpected cache parse for: ' . $contents);
        }
    },
    'never schedules checks less often than the 1.x Cron entry did' => static function (): void {
        assertRuntimeSame('every_fifteen_minutes', LegacyInstallation::scheduleFor('*', '*', '*', '*', '*'), 'Every minute maps to fifteen minutes.');
        assertRuntimeSame('every_fifteen_minutes', LegacyInstallation::scheduleFor('*/30', '*', '*', '*', '*'), 'Every half hour maps to fifteen minutes.');
        assertRuntimeSame('hourly', LegacyInstallation::scheduleFor('0', '*', '*', '*', '*'), 'Hourly stays hourly.');
        assertRuntimeSame('hourly', LegacyInstallation::scheduleFor('0', '*/2', '*', '*', '*'), 'Every two hours maps to hourly.');
        assertRuntimeSame('every_six_hours', LegacyInstallation::scheduleFor('0', '*/6', '*', '*', '*'), 'Every six hours stays every six hours.');
        assertRuntimeSame('daily', LegacyInstallation::scheduleFor('15', '3', '*', '*', '*'), 'Daily stays daily.');
    },
    'moves a 1.x install with a stale saved prefix and fixes the stale fields on the first check' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->configuration['cron']['item'] = [
            makeCronItem('/usr/bin/nice -n20 newsyslog'),
            makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em1 56'),
        ];
        $platformAdapter->interfaces = ['wan', 'lan'];
        $platformAdapter->devices = ['em1' => 'lan'];
        $platformAdapter->currentAddress = '2001:db8:aaaa:bb00::1';
        $legacyRoot = makeLegacyRoot(
            ['em1' => '2001:db8:1111:22||56'],
            [
                'em1-1700000000.xml' => "<?xml version=\"1.0\"?>\n<pfsense><version>23.3</version></pfsense>\n",
                'notes.txt' => 'Kept by the operator.',
            ],
        );
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $backupStore = makeBackupStore();
        $migrator = new LegacyMigrator($platformAdapter, $stateStore, $backupStore, $legacyRoot);

        file_put_contents($legacyRoot . '/pfsense-ipv6-prefix-updater.zip', 'archive');
        file_put_contents($legacyRoot . '/pfsense-ipv6-prefix-updater-install.sh', "#!/bin/bash\n");

        $report = $migrator->migrate();

        assertRuntimeSame('completed_with_warnings', $report['status'], 'A kept non-configuration file is a warning, not a failure.');
        assertRuntimeSame(false, file_exists($legacyRoot . '/pfsense-ipv6-prefix-updater.zip'), 'The downloaded 1.x archive must be deleted.');
        assertRuntimeSame(false, file_exists($legacyRoot . '/pfsense-ipv6-prefix-updater-install.sh'), 'The saved 1.x installer must be deleted.');
        assertRuntimeSame(
            ['key' => 'legacy_step_downloads_removed', 'arguments' => [2]],
            $report['steps'][count($report['steps']) - 1],
            'The report must list the deleted installer downloads.',
        );
        assertRuntimeSame(
            ['/usr/bin/nice -n20 newsyslog'],
            array_column($platformAdapter->configuration['cron']['item'], 'command'),
            'Only the 1.x Cron entry must be removed.',
        );
        assertRuntimeSame(1, $platformAdapter->cronReloadCount, 'The crontab must be regenerated after removing the entry.');
        assertRuntimeSame(['backup', 'set:installedpackages/ipv6prefixupdater/config', 'set:cron/item', 'write', 'cron'], $platformAdapter->transactionEvents, 'The backup must precede the single settings and Cron write.');

        $settings = $platformAdapter->configuration['installedpackages']['ipv6prefixupdater']['config'][0];

        assertRuntimeSame([['interface' => 'lan', 'prefix_length' => '56']], $settings['row'], 'The 1.x device must become a monitored interface row.');
        assertRuntimeSame('on', $settings['enabled'], 'Scheduling must continue after the move.');
        assertRuntimeSame('every_fifteen_minutes', $settings['update_frequency'], 'An every-minute 1.x entry maps to fifteen minutes.');

        $selection = FieldSelection::fromStorage($platformAdapter->getFieldSelection());

        foreach (FieldSelection::RECOMMENDED_GROUPS as $group) {
            assertRuntimeSame(true, FieldSelection::groupEnabled($selection, $group), sprintf('A moved 1.x install must start with Auto-select, so %s is on.', $group));
        }

        $state = $stateStore->read();

        assertRuntimeSame('2001:db8:1111:2200::', $state['targets']['lan']['prefix'], 'The stale saved prefix must stay the baseline.');
        assertRuntimeSame('legacy_cache', $state['targets']['lan']['source'], 'The baseline must record where it came from.');
        assertRuntimeSame('completed_with_warnings', $state['legacy_migration']['status'], 'The Cleanup tab needs the report.');
        assertRuntimeSame(false, is_dir($legacyRoot . '/pfsense-ipv6-prefix-updater'), 'The 1.x script must be deleted.');
        assertRuntimeSame(false, is_dir($legacyRoot . '/pfsense-ipv6-prefix-updater-cache'), 'The 1.x saved prefixes must be deleted.');
        assertRuntimeSame(false, file_exists($legacyRoot . '/pfsense-ipv6-prefix-updater-backups/em1-1700000000.xml'), 'The imported backup original must be deleted.');
        assertRuntimeSame(true, is_file($legacyRoot . '/pfsense-ipv6-prefix-updater-backups/notes.txt'), 'Files that are not configurations must stay.');

        $backups = $backupStore->list();
        $legacyBackups = array_values(array_filter($backups, static fn (array $backup): bool => $backup['source'] === 'legacy'));

        assertRuntimeSame(2, count($backups), 'The store must hold the pre-move backup and the imported 1.x backup.');
        assertRuntimeSame('em1-1700000000.xml', $legacyBackups[0]['original_name'], 'The imported backup keeps its original name.');
        assertRuntimeSame(gmdate('c', 1700000000), $legacyBackups[0]['created_at'], 'The imported backup keeps the time in its name.');
        assertRuntimeSame(['lan'], $legacyBackups[0]['interfaces'], 'The imported backup maps its device to the interface.');
        assertRuntimeSame('/ipv6-prefix-updater-cleanup.php', $platformAdapter->notices[0]['page'] ?? null, 'The notice must link to the Cleanup tab.');

        $noticeCount = count($platformAdapter->notices);

        assertRuntimeSame('none', $migrator->migrate()['status'], 'A second pass must find nothing actionable.');
        assertRuntimeSame($noticeCount, count($platformAdapter->notices), 'A second pass must not repeat the notice.');

        $platformAdapter->settings = $settings;
        $result = (new PrefixUpdater($platformAdapter, $stateStore, $backupStore))->run(false, false);

        assertRuntimeSame('success', $result['status'], 'The first check must update the fields left on the old prefix.');
        assertRuntimeSame(11, $result['change_count'], 'Every approved field on the old prefix must move.');
        assertRuntimeSame(
            '2001:db8:aaaa:bbaa::10 192.0.2.1 OTHER_ALIAS 2001:db8:9999::1',
            $platformAdapter->configuration['aliases']['alias'][0]['address'],
            'Aliases must move to the current prefix with host bits kept.',
        );

        removeTestTree($legacyRoot);
        removeTestTree($stateDirectory);
    },
    'starts from the current prefix when no approved field uses the saved one' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->configuration['cron']['item'] = [makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em1 56', '0', '*/6')];
        $platformAdapter->interfaces = ['wan', 'lan'];
        $platformAdapter->devices = ['em1' => 'lan'];
        $legacyRoot = makeLegacyRoot(['em1' => '2001:db8:5555:55||56'], []);
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $migrator = new LegacyMigrator($platformAdapter, $stateStore, makeBackupStore(), $legacyRoot);
        $report = $migrator->migrate();

        assertRuntimeSame('completed', $report['status'], 'A clean move has no warnings.');
        assertRuntimeSame(
            true,
            in_array('legacy_step_baseline_current', array_column($report['steps'], 'key'), true),
            'The report must say the current prefix became the baseline.',
        );
        assertRuntimeSame('2001:db8:1111:2200::', $stateStore->read()['targets']['lan']['prefix'], 'An unused saved prefix must not become the baseline.');
        assertRuntimeSame(
            'every_six_hours',
            $platformAdapter->configuration['installedpackages']['ipv6prefixupdater']['config'][0]['update_frequency'],
            'The 1.x schedule must carry over.',
        );

        $platformAdapter->settings = $platformAdapter->configuration['installedpackages']['ipv6prefixupdater']['config'][0];
        $result = (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(false, false);

        assertRuntimeSame('no_change', $result['status'], 'The first check must not warn about the unused saved prefix.');

        $firstSteps = $stateStore->read()['legacy_migration']['steps'];

        file_put_contents($legacyRoot . '/pfsense-ipv6-prefix-updater.zip', 'archive');

        assertRuntimeSame('completed', $migrator->migrate()['status'], 'A leftover found later must be cleaned up.');
        assertRuntimeSame(
            array_merge($firstSteps, [['key' => 'legacy_step_downloads_removed', 'arguments' => [1]]]),
            $stateStore->read()['legacy_migration']['steps'],
            'A later pass must add to the completed report instead of replacing it.',
        );

        removeTestTree($legacyRoot);
        removeTestTree($stateDirectory);
    },
    'waits for a running 1.x script and treats an old lock as left behind' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->configuration['cron']['item'] = [makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em1 56')];
        $platformAdapter->interfaces = ['wan', 'lan'];
        $platformAdapter->devices = ['em1' => 'lan'];
        $legacyRoot = makeLegacyRoot(['em1' => '2001:db8:1111:22||56'], []);
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $migrator = new LegacyMigrator($platformAdapter, $stateStore, makeBackupStore(), $legacyRoot);

        touch($legacyRoot . '/pfsense-ipv6-prefix-updater/update.lock');

        assertRuntimeSame('deferred', $migrator->migrate()['status'], 'A fresh 1.x lock must defer the move.');
        assertRuntimeSame(1, count($platformAdapter->configuration['cron']['item']), 'A deferred move must leave the Cron entry in place.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A deferred move must not write configuration.');

        touch($legacyRoot . '/pfsense-ipv6-prefix-updater/update.lock', time() - 3600);

        assertRuntimeSame('completed', $migrator->migrate()['status'], 'A lock left by a stopped run must not block the move.');
        assertRuntimeSame([], $platformAdapter->configuration['cron']['item'], 'The 1.x Cron entry must be removed.');

        removeTestTree($legacyRoot);
        removeTestTree($stateDirectory);
    },
    'monitors every 1.x interface and reports devices that are not assigned' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->configuration['cron']['item'] = [
            makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em1 56', '5'),
            makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em1.10 56', '35'),
            makeCronItem('php /root/pfsense-ipv6-prefix-updater/update.php em9 56'),
        ];
        $platformAdapter->interfaces = ['wan', 'lan', 'opt1'];
        $platformAdapter->devices = ['em1' => 'lan', 'em1.10' => 'opt1'];
        $legacyRoot = makeLegacyRoot([], []);
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $report = (new LegacyMigrator($platformAdapter, $stateStore, makeBackupStore(), $legacyRoot))->migrate();
        $settings = $platformAdapter->configuration['installedpackages']['ipv6prefixupdater']['config'][0];

        assertRuntimeSame(
            [['interface' => 'lan', 'prefix_length' => '56'], ['interface' => 'opt1', 'prefix_length' => '56']],
            $settings['row'],
            'Each mapped 1.x Cron entry must become its own row.',
        );
        assertRuntimeSame(
            [['key' => 'legacy_device_unmapped', 'arguments' => ['em9']]],
            $report['warnings'],
            'An unassigned device must be reported, not guessed.',
        );
        assertRuntimeSame([], $platformAdapter->configuration['cron']['item'], 'Every 1.x Cron entry must be removed, including broken ones.');

        removeTestTree($legacyRoot);
        removeTestTree($stateDirectory);
    },
    'commits identical changes once when monitored interfaces share a delegation' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->interfaces = ['wan', 'lan', 'opt1'];
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56', 'opt1' => '56']);
        $platformAdapter->addresses = ['lan' => '2001:db8:aaaa:bb00::1', 'opt1' => '2001:db8:aaaa:bb01::1'];
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');

        $stateStore->write([
            'schema_version' => 1,
            'targets' => [
                'lan' => ['interface' => 'lan', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
                'opt1' => ['interface' => 'opt1', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
            ],
            'last_run' => null,
        ]);

        $result = (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(false, false);

        assertRuntimeSame('success', $result['status'], 'Both interfaces must succeed together.');
        assertRuntimeSame(11, $result['change_count'], 'Shared fields must be counted once.');
        assertRuntimeSame(1, $platformAdapter->writeCount, 'All interfaces must commit in one write.');
        assertRuntimeSame(1, $platformAdapter->backupCount, 'All interfaces must share one backup.');
        assertRuntimeSame(['success', 'success'], array_column($result['targets'], 'status'), 'Each interface must report its own result.');
        assertRuntimeSame('2001:db8:aaaa:bb00::', $stateStore->read()['targets']['opt1']['prefix'], 'Each interface must record its new baseline.');

        removeTestTree($stateDirectory);
    },
    'stops before writing when monitored interfaces disagree about a field' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $configurationBefore = $platformAdapter->configuration;
        $platformAdapter->interfaces = ['wan', 'lan', 'opt1'];
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56', 'opt1' => '56']);
        $platformAdapter->addresses = ['lan' => '2001:db8:aaaa:bb00::1', 'opt1' => '2001:db8:cccc:dd00::1'];
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');

        $stateStore->write([
            'schema_version' => 1,
            'targets' => [
                'lan' => ['interface' => 'lan', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
                'opt1' => ['interface' => 'opt1', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
            ],
            'last_run' => null,
        ]);

        assertRuntimeThrows(
            static function () use ($platformAdapter, $stateStore): void {
                (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(false, false);
            },
            RuntimeException::class,
            'Conflicting plans must stop the run.',
        );
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A conflict must not write configuration.');
        assertRuntimeSame($configurationBefore, $platformAdapter->configuration, 'A conflict must leave configuration untouched.');

        removeTestTree($stateDirectory);
    },
    'keeps checking other interfaces when one has no address' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeConfigurationFixture();
        $platformAdapter->interfaces = ['wan', 'lan', 'opt1'];
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56', 'opt1' => '60']);
        $platformAdapter->addresses = ['lan' => '2001:db8:1111:2200::1', 'opt1' => null];
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $result = (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(false, false);

        assertRuntimeSame('warning', $result['status'], 'One interface without an address is a warning for the run.');
        assertRuntimeSame(['initialized', 'failure'], array_column($result['targets'], 'status'), 'The working interface must still record its baseline.');
        assertRuntimeSame(true, isset($stateStore->read()['targets']['lan']), 'The working interface baseline must be saved.');
        assertRuntimeSame('failure', $platformAdapter->notices[0]['identifier'] ?? null, 'The missing address must raise a local notice.');

        $platformAdapter->addresses = ['lan' => null, 'opt1' => null];

        assertRuntimeThrows(
            static function () use ($platformAdapter, $stateStore): void {
                (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(false, false);
            },
            RuntimeException::class,
            'A run with no readable interface must fail loudly.',
        );

        removeTestTree($stateDirectory);
    },
    'keeps package backups owner-only, prunes package copies, and never prunes 1.x imports' => static function (): void {
        $directory = makeTemporaryDirectory();
        $configurationFile = $directory . '/config.xml';

        file_put_contents($configurationFile, "<?xml version=\"1.0\"?>\n<pfsense></pfsense>\n");

        $backupStore = new BackupStore($directory . '/backups');
        $legacyBackup = $backupStore->import($configurationFile, 'legacy', ['lan'], 1600000000);

        for ($index = 0; $index < 3; $index++) {
            $backupStore->create($configurationFile, 'package', ['lan'], 'lan: test');
        }

        assertRuntimeSame(0600, fileperms($backupStore->path($legacyBackup['id'])) & 0777, 'Backups must be readable by root only.');
        assertRuntimeSame(0700, fileperms($directory . '/backups') & 0777, 'The backup folder must be private.');
        assertRuntimeSame(2, $backupStore->prune(1), 'Only package copies beyond the limit may be pruned.');
        assertRuntimeSame(['package', 'legacy'], array_column($backupStore->list(), 'source'), 'The newest package copy and the 1.x import must remain.');
        assertRuntimeSame(null, $backupStore->import(__FILE__, 'legacy', [], time()), 'Files that are not configurations must be refused.');
        assertRuntimeThrows(
            static function () use ($backupStore): void {
                $backupStore->path('../../etc/passwd');
            },
            RuntimeException::class,
            'Backup identifiers must not address other files.',
        );

        removeTestTree($directory);
    },
    'scans DHCPv6, router advertisement, DNS, firewall rule, and NAT fields' => static function (): void {
        $targets = ConfigurationTargetScanner::scan(makeLocalFieldsFixture(), FieldSelection::autoSelect());
        $plan = ConfigurationChangePlanner::plan(
            '2001:db8:1111:2200::1',
            56,
            '2001:db8:aaaa:bb00::1',
            56,
            $targets,
        );
        $changes = array_column($plan['changes'], 'after', 'path');
        $services = array_values(array_unique(array_column($plan['changes'], 'service')));

        assertRuntimeSame(
            [
                'aliases/alias/0/address' => '2001:db8:aaaa:bbaa::77',
                'filter/rule/0/destination/address' => '2001:db8:aaaa:bbaa::10',
                'filter/rule/2/source/address' => '2001:db8:aaaa:bbbb::/64',
                'nat/rule/0/destination/address' => '2001:db8:aaaa:bbaa::1',
                'nat/rule/0/target' => '2001:db8:aaaa:bbaa::10',
                'nat/outbound/rule/0/source/network' => '2001:db8:aaaa:bbcc::/64',
                'nat/outbound/rule/0/target' => '2001:db8:aaaa:bbff::1',
                'nat/onetoone/0/external' => '2001:db8:aaaa:bbff::20',
                'nat/npt/0/destination/address' => '2001:db8:aaaa:bbd0::/64',
                'dhcpdv6/lan/range/from' => '2001:db8:aaaa:bbaa::1000',
                'dhcpdv6/lan/range/to' => '2001:db8:aaaa:bbaa::2000',
                'dhcpdv6/lan/prefixrange/from' => '2001:db8:aaaa:bbf0::',
                'dhcpdv6/lan/prefixrange/to' => '2001:db8:aaaa:bbfe::',
                'dhcpdv6/lan/dnsserver/0' => '2001:db8:aaaa:bbaa::1',
                'dhcpdv6/lan/ntpserver/0' => '2001:db8:aaaa:bbaa::123',
                'dhcpdv6/lan/pool/0/range/from' => '2001:db8:aaaa:bbaa::3000',
                'dhcpdv6/lan/pool/0/range/to' => '2001:db8:aaaa:bbaa::3fff',
                'dhcpdv6/lan/staticmap/0/ipaddrv6' => '2001:db8:aaaa:bbaa::50',
                'dhcpdv6/lan/radnsserver/0' => '2001:db8:aaaa:bbaa::1',
                'dhcpdv6/lan/subnets/item/0' => '2001:db8:aaaa:bbab::/64',
                'unbound/hosts/0/ip' => '192.168.1.10, 2001:db8:aaaa:bbaa::10',
                'dnsmasq/hosts/1/ip' => '2001:db8:aaaa:bbaa::10',
                'ipsec/phase2/0/localid/address' => '2001:db8:aaaa:bbdd::/64',
            ],
            $changes,
            'Every Recommended field must be planned once, keeping separators, CIDR suffixes, and unrelated tokens, and no other package\'s field may be picked automatically.',
        );
        assertRuntimeSame(
            ['filter', 'dhcpv6', 'router_advertisements', 'dns_resolver', 'dns_forwarder', 'ipsec'],
            $services,
            'Each field must name the service pfSense reloads for it.',
        );
    },
    'reads a saved field selection and starts Recommended groups on without one' => static function (): void {
        $selection = FieldSelection::fromStorage(null);

        assertRuntimeSame(true, FieldSelection::groupEnabled($selection, 'dhcpv6'), 'A Recommended group must start on before the Fields tab is saved.');
        assertRuntimeSame(false, FieldSelection::groupEnabled($selection, 'package:sample'), 'Another package\'s group must start off.');

        $stored = FieldSelection::toStorage([
            'groups' => ['aliases' => true, 'nat' => false, 'package:sample' => true],
            'include' => ['firewall-rule:1700000001:destination'],
            'exclude' => ['alias:WEB'],
        ]);

        assertRuntimeSame(
            [
                'groups' => ['aliases' => true, 'package:sample' => true, 'nat' => false],
                'include' => ['firewall-rule:1700000001:destination'],
                'exclude' => ['alias:WEB'],
            ],
            FieldSelection::fromStorage($stored),
            'A saved selection must read back with its switches and exceptions.',
        );
        assertRuntimeSame(
            ['groups' => ['aliases' => true], 'include' => ['alias:WEB'], 'exclude' => []],
            FieldSelection::fromStorage(
                ['schema' => '1', 'enabled_groups' => ['item' => 'aliases'], 'include' => ['item' => 'alias:WEB']],
            ),
            'A lone saved entry must be read as a one-item list.',
        );
        assertRuntimeSame(
            false,
            FieldSelection::hasSelection(FieldSelection::fromStorage(FieldSelection::toStorage(FieldSelection::clearAll()))),
            'Clear All must survive a save, so a later read does not turn Recommended groups back on.',
        );
    },
    'applies Fields tab choices as group switches and exceptions' => static function (): void {
        $selection = FieldSelection::applyChoices(
            [
                'groups' => ['firewall_rules' => false],
                'include' => ['firewall-rule:1700000009:source', 'alias:HIDDEN'],
                'exclude' => ['dhcpv6:lan:dns-servers'],
            ],
            ['aliases' => true, 'firewall_rules' => false, 'dhcpv6' => true, 'package:sample' => false, 'not a group' => true],
            [
                'alias:WEB' => 'aliases',
                'alias:DB' => 'aliases',
                'firewall-rule:1700000001:destination' => 'firewall_rules',
                'firewall-rule:1700000009:source' => 'firewall_rules',
                'dhcpv6:lan:dns-servers' => 'dhcpv6',
                'package:sample:config/0/listen_address' => 'package:sample',
            ],
            [
                'alias:WEB',
                'firewall-rule:1700000001:destination',
                'dhcpv6:lan:dns-servers',
                'package:sample:config/0/listen_address',
                'firewall-rule:#2:source',
            ],
        );

        assertRuntimeSame(
            [
                'groups' => ['firewall_rules' => false, 'aliases' => true, 'dhcpv6' => true],
                'include' => ['alias:HIDDEN', 'firewall-rule:1700000001:destination', 'package:sample:config/0/listen_address'],
                'exclude' => ['alias:DB'],
            ],
            $selection,
            'An unticked field of an on group must be excluded, a ticked field of an off group included, and choices for fields not shown kept.',
        );
        assertRuntimeSame(
            [
                'groups' => [
                    'firewall_rules' => true,
                    'aliases' => true,
                    'dhcpv6' => true,
                    'interfaces' => true,
                    'virtual_ips' => true,
                    'nat' => true,
                    'router_advertisements' => true,
                    'dns_host_overrides' => true,
                    'ipsec' => true,
                    'openvpn' => true,
                    'wireguard' => true,
                ],
                'include' => ['package:sample:config/0/listen_address'],
                'exclude' => [],
            ],
            FieldSelection::autoSelect($selection),
            'Auto-select must turn on every Recommended group, drop their exceptions, and keep choices for other packages.',
        );
        assertRuntimeSame(false, FieldSelection::hasSelection(FieldSelection::clearAll()), 'Clear All must leave nothing selected.');
        assertRuntimeSame(
            true,
            FieldSelection::hasSelection(['groups' => FieldSelection::clearAll()['groups'], 'include' => ['alias:WEB'], 'exclude' => []]),
            'One ticked field must count as a selection.',
        );
    },
    'gives no field identity to records that share or lack one' => static function (): void {
        $targets = ConfigurationTargetScanner::scanAll([
            'filter' => [
                'rule' => [
                    [
                        'tracker' => '5',
                        'source' => ['address' => '2001:db8:1111:22aa::1'],
                    ],
                    [
                        'tracker' => '5',
                        'source' => ['address' => '2001:db8:1111:22aa::2'],
                    ],
                    [
                        'source' => ['address' => '2001:db8:1111:22aa::3'],
                    ],
                    [
                        'tracker' => '6',
                        'source' => ['address' => '2001:db8:1111:22aa::4'],
                    ],
                ],
            ],
            'dnsmasq' => makeLocalFieldsFixture()['dnsmasq'],
            'installedpackages' => [
                'sample' => makeLocalFieldsFixture()['installedpackages']['sample'],
            ],
        ]);

        assertRuntimeSame(
            [
                'firewall-rule:#0:source' => null,
                'firewall-rule:#1:source' => null,
                'firewall-rule:#2:source' => null,
                'firewall-rule:6:source' => 'firewall-rule:6:source',
                'dns-forwarder-host:nas:home.arpa:inet' => 'dns-forwarder-host:nas:home.arpa:inet',
                'dns-forwarder-host:nas:home.arpa:inet6' => 'dns-forwarder-host:nas:home.arpa:inet6',
                'package:sample:config/0/listen_address' => 'package:sample:config/0/listen_address',
                'package:sample:servers/item/name=web/address' => 'package:sample:servers/item/name=web/address',
                'package:sample:#installedpackages/sample/servers/item/1/address' => null,
                'other:#installedpackages/sample/config/0/admin_password' => 'other:installedpackages/sample/config/0/admin_password',
            ],
            array_column($targets, 'field_id', 'target_id'),
            'Shared or missing identities must fall back to unique positional targets that can never be stored.',
        );
        assertRuntimeSame(
            ['alias:WEB', 'dns-resolver-host:nas:home.arpa:inet46'],
            FieldSelection::normalizeFieldIds([
                'firewall-rule:#2:source',
                'package:sample:#installedpackages/sample/servers/item/1/address',
                'alias:WEB',
                'alias:WEB',
                42,
                'not an identity',
                'gateway:LAN_ROUTER',
                'dns-resolver-host:nas:home.arpa:inet46',
            ]),
            'Stored field identities must drop positional, malformed, unknown, and repeated entries.',
        );
        assertRuntimeSame(
            ['firewall-rule:1700000001:source'],
            FieldSelection::normalizeFieldIds('firewall-rule:1700000001:source'),
            'A lone stored identity must be read as a list.',
        );
    },
    'lists each field by group with its selection, highlight, and Other Settings kind' => static function (): void {
        $detected = ConfigurationFieldDetector::detect(
            makeLocalFieldsFixture(),
            [
                ['interface' => 'lan', 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
                ['interface' => 'opt1', 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
                ['interface' => 'opt2', 'prefix' => 'not-a-prefix', 'prefix_length' => 56],
            ],
            [
                'groups' => ['firewall_rules' => false, 'nat' => true],
                'include' => ['firewall-rule:1700000001:destination', 'alias:GONE'],
                'exclude' => ['port-forward:1700000100:destination'],
            ],
        );
        $rows = [];
        $otherRows = [];

        foreach ($detected['groups'] as $group => $detectedGroup) {
            foreach ($detectedGroup['rows'] as $row) {
                $rows[$row['paths'][0]] = $row;

                if ($group === FieldSelection::OTHER_GROUP) {
                    $otherRows[$row['paths'][0]] = $row;
                }
            }
        }

        assertRuntimeSame(
            [['prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56, 'interfaces' => ['lan', 'opt1']]],
            $detected['prefixes'],
            'Interfaces that share a delegation must be checked once, and unusable baselines skipped.',
        );
        assertRuntimeSame(
            [...FieldSelection::RECOMMENDED_GROUPS, 'package:sample', 'package:orphan', FieldSelection::OTHER_GROUP],
            array_keys($detected['groups']),
            'Every Recommended group must be listed even when empty, then other packages\' groups, then Other Settings.',
        );
        assertRuntimeSame(
            ['recommended', 'needs_review', 'needs_review', 'other', false],
            [
                $detected['groups']['aliases']['kind'],
                $detected['groups']['package:sample']['kind'],
                $detected['groups']['package:orphan']['kind'],
                $detected['groups'][FieldSelection::OTHER_GROUP]['kind'],
                $detected['groups'][FieldSelection::OTHER_GROUP]['enabled'],
            ],
            'Each group must carry its kind, and Other Settings must start off.',
        );
        assertRuntimeSame(
            [false, 1, 2],
            [
                $detected['groups']['firewall_rules']['enabled'],
                $detected['groups']['firewall_rules']['selected'],
                $detected['groups']['firewall_rules']['total'],
            ],
            'An off group must count only its ticked fields.',
        );
        assertRuntimeSame(
            [true, false, false, true, false],
            [
                $rows['filter/rule/0/destination/address']['selected'],
                $rows['filter/rule/2/source/address']['selected'],
                $rows['nat/rule/0/destination/address']['selected'],
                $rows['nat/rule/0/target']['selected'],
                $rows['installedpackages/sample/config/0/listen_address']['selected'],
            ],
            'A ticked field of an off group, an unticked field of an on group, and an untouched package field must each show their own state.',
        );
        assertRuntimeSame(
            [null, 'field_destination', 'listen_address'],
            [
                $rows['filter/rule/2/source/address']['field_id'],
                $rows['filter/rule/0/destination/address']['label'],
                $rows['installedpackages/sample/config/0/listen_address']['label_text'],
            ],
            'A field without a stable identity must have no checkbox, and every row must carry a label.',
        );
        assertRuntimeSame(
            [
                ['text' => '192.168.1.10, ', 'prefix' => false],
                ['text' => '2001:db8:1111:22', 'prefix' => true],
                ['text' => 'aa::10', 'prefix' => false],
            ],
            $rows['unbound/hosts/0/ip']['segments'],
            'Only the prefix bits of each address must be highlighted.',
        );
        assertRuntimeSame(['alias:GONE'], $detected['missing'], 'A ticked field that no longer exists must be listed as missing.');
        $expectedKinds = [
            'aliases/alias/0/detail' => 'reason_description',
            'unbound/domainoverrides/0/ip' => null,
            'unbound/custom_options' => 'reason_base64',
            'gateways/gateway_item/0/gateway' => 'reason_gateway',
            'gateways/gateway_item/0/monitor' => 'reason_gateway',
            'staticroutes/route/0/network' => 'reason_static_route',
            'ipsec/phase1/0/remote-gateway' => 'reason_remote_vpn',
            'ipsec/phase2/0/remoteid/address' => 'reason_remote_vpn',
            'ipsec/mobilekey/0/pre-shared-key' => 'reason_secret',
            'openvpn/openvpn-server/0/remote_networkv6' => 'reason_remote_vpn',
            'installedpackages/wireguard/peers/item/0/allowedips/row/0/address' => 'reason_wireguard_peer',
            'installedpackages/sample/config/0/admin_password' => 'reason_secret',
            'cert/0/crt' => 'reason_certificate',
            'system/dnsserver/0' => null,
        ];
        $otherKinds = array_map(static fn (array $otherRow): ?string => $otherRow['label'], $otherRows);

        ksort($expectedKinds);
        ksort($otherKinds);
        assertRuntimeSame(
            $expectedKinds,
            $otherKinds,
            'Every value no other group covers must be listed under Other Settings with its kind.',
        );
        assertRuntimeSame(
            [
                'other:aliases/alias/name=NOTES/detail',
                'other:gateways/gateway_item/name=LAN_ROUTER/gateway',
                'other:staticroutes/route/descr=Downstream%20router/network',
                'other:ipsec/phase1/ikeid=1/remote-gateway',
                'other:installedpackages/sample/config/0/admin_password',
                'other:system/dnsserver',
                null,
            ],
            [
                $otherRows['aliases/alias/0/detail']['field_id'],
                $otherRows['gateways/gateway_item/0/gateway']['field_id'],
                $otherRows['staticroutes/route/0/network']['field_id'],
                $otherRows['ipsec/phase1/0/remote-gateway']['field_id'],
                $otherRows['installedpackages/sample/config/0/admin_password']['field_id'],
                $otherRows['system/dnsserver/0']['field_id'],
                $otherRows['installedpackages/wireguard/peers/item/0/allowedips/row/0/address']['field_id'],
            ],
            'An Other Settings field must be named by a unique field of its entry that holds no address, a plain list by its path, and an entry without one must have no checkbox.',
        );
        assertRuntimeSame(
            [false, 'dnsserver', 'ip'],
            [
                $otherRows['gateways/gateway_item/0/gateway']['selected'],
                $otherRows['system/dnsserver/0']['label_text'],
                $otherRows['unbound/domainoverrides/0/ip']['label_text'],
            ],
            'An Other Settings field must start unticked, and one without a kind must carry its setting name.',
        );
        assertRuntimeSame(
            [[], true, [], true],
            [
                $otherRows['cert/0/crt']['segments'],
                $otherRows['cert/0/crt']['hidden'],
                $otherRows['installedpackages/sample/config/0/admin_password']['segments'],
                $otherRows['installedpackages/sample/config/0/admin_password']['hidden'],
            ],
            'Certificate and secret values must be listed without their contents.',
        );
        assertRuntimeSame(
            ['text' => '2001:db8:1111:22', 'prefix' => true],
            $otherRows['unbound/custom_options']['segments'][1],
            'A Base64 value must be shown and matched as its decoded text.',
        );
    },
    'commits selected exceptions, re-applies their packages, and skips missing fields' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeLocalFieldsFixture();
        $platformAdapter->interfaces = ['lan'];
        $platformAdapter->currentAddress = '2001:db8:aaaa:bbaa::1';
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56']);
        $platformAdapter->fieldSelection = FieldSelection::toStorage(FieldSelection::clearAll());
        $platformAdapter->packageOwners = ['sample' => 'Sample'];
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $stateStore->write([
            'schema_version' => 1,
            'targets' => [
                'lan' => ['interface' => 'lan', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
            ],
            'last_run' => null,
        ]);

        assertRuntimeThrows(
            static function () use ($prefixUpdater): void {
                $prefixUpdater->run(true, false);
            },
            InvalidArgumentException::class,
            'A run with nothing selected must stop.',
        );

        $platformAdapter->fieldSelection['include'] = [
            'item' => [
                'dns-forwarder-host:nas:home.arpa:inet6',
                'npt:ULA:destination',
                'package:sample:config/0/listen_address',
                'package:orphan:config/0/upstream',
                'alias:GONE',
                'firewall-rule:#2:source',
                ['not', 'an', 'identity'],
            ],
        ];

        $preview = $prefixUpdater->run(true, true);

        assertRuntimeSame('dry_run', $preview['status'], 'A dry run must report the planned changes.');
        assertRuntimeSame(0, $platformAdapter->writeCount, 'A dry run must not write the configuration.');
        assertRuntimeSame([], $platformAdapter->reappliedSections, 'A dry run must not re-apply packages.');
        assertRuntimeSame(
            [
                ['path' => 'nat/npt/0/destination/address', 'replacements' => [['2001:db8:1111:22d0::/64', '2001:db8:aaaa:bbd0::/64']]],
                ['path' => 'dnsmasq/hosts/1/ip', 'replacements' => [['2001:db8:1111:22aa::10', '2001:db8:aaaa:bbaa::10']]],
                ['path' => 'installedpackages/sample/config/0/listen_address', 'replacements' => [['2001:db8:1111:22aa::80', '2001:db8:aaaa:bbaa::80']]],
                ['path' => 'installedpackages/orphan/config/0/upstream', 'replacements' => [['2001:db8:1111:22aa::84', '2001:db8:aaaa:bbaa::84']]],
            ],
            $preview['changes'],
            'A dry run must show each field with its exact before and after addresses.',
        );

        $result = $prefixUpdater->run(true, false);

        assertRuntimeSame('success', $result['status'], 'Ticked fields must be updated while their groups are off.');
        assertRuntimeSame(4, $result['change_count'], 'Only the four ticked fields that still exist may change.');
        assertRuntimeSame(
            ['2001:db8:aaaa:bbaa::10', '2001:db8:aaaa:bbd0::/64', '2001:db8:aaaa:bbaa::80', '2001:db8:aaaa:bbaa::84'],
            [
                $platformAdapter->getConfigurationValue('dnsmasq/hosts/1/ip'),
                $platformAdapter->getConfigurationValue('nat/npt/0/destination/address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/config/0/listen_address'),
                $platformAdapter->getConfigurationValue('installedpackages/orphan/config/0/upstream'),
            ],
            'Every ticked field must change.',
        );
        assertRuntimeSame(
            ['192.168.1.10, 2001:db8:1111:22aa::10', '2001:db8:1111:22bb::/64', '2001:db8:1111:22aa::82', '2001:db8:1111:22aa::81'],
            [
                $platformAdapter->getConfigurationValue('unbound/hosts/0/ip'),
                $platformAdapter->getConfigurationValue('filter/rule/2/source/address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/servers/item/0/address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/config/0/admin_password'),
            ],
            'Unticked fields, positional identities, and secrets must stay unchanged.',
        );
        assertRuntimeSame(['filter', 'dns_forwarder'], $platformAdapter->reloadedServices, 'Only the services of the changed fields may reload.');
        assertRuntimeSame(['sample', 'orphan'], $platformAdapter->reappliedSections, 'Each changed package must be re-applied after the write.');
        assertRuntimeSame(
            [
                ['section' => 'sample', 'package' => 'Sample', 'status' => 'reapplied', 'error' => null],
                ['section' => 'orphan', 'package' => null, 'status' => 'unknown_owner', 'error' => null],
            ],
            $result['package_reapply'],
            'Each package re-apply must be reported, including a section no installed package owns.',
        );
        assertRuntimeSame(['alias:GONE'], $result['skipped_fields'], 'A ticked field that no longer exists must be skipped and listed.');
        assertRuntimeSame(
            ['package-orphan'],
            array_values(array_filter(
                array_column($platformAdapter->notices, 'identifier'),
                static fn (string $identifier): bool => str_starts_with($identifier, 'package-'),
            )),
            'A package nobody owns must raise a notice to restart its service by hand.',
        );

        removeTestTree($stateDirectory);
    },
    'keeps the new values when a package fails to apply them again' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeLocalFieldsFixture();
        $platformAdapter->interfaces = ['lan'];
        $platformAdapter->currentAddress = '2001:db8:aaaa:bbaa::1';
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56']);
        $platformAdapter->fieldSelection = FieldSelection::toStorage([
            'groups' => FieldSelection::clearAll()['groups'] + ['package:sample' => true],
            'include' => [],
            'exclude' => [],
        ]);
        $platformAdapter->packageOwners = ['sample' => 'Sample'];
        $platformAdapter->failingPackages = ['Sample'];
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');
        $prefixUpdater = new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore());

        $stateStore->write([
            'schema_version' => 1,
            'targets' => [
                'lan' => ['interface' => 'lan', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
            ],
            'last_run' => null,
        ]);

        $result = $prefixUpdater->run(true, false);

        assertRuntimeSame('success', $result['status'], 'A failed package re-apply must not fail the verified write.');
        assertRuntimeSame(
            ['2001:db8:aaaa:bbaa::80', '2001:db8:aaaa:bbaa::82', '2001:db8:aaaa:bbaa::83', '2001:db8:1111:22aa::81'],
            [
                $platformAdapter->getConfigurationValue('installedpackages/sample/config/0/listen_address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/servers/item/0/address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/servers/item/1/address'),
                $platformAdapter->getConfigurationValue('installedpackages/sample/config/0/admin_password'),
            ],
            'An on package group must cover every field, even one without an identity, but never a secret, and a failed re-apply must keep the new values.',
        );
        assertRuntimeSame(
            [['section' => 'sample', 'package' => 'Sample', 'status' => 'failed', 'error' => 'Resync failure.']],
            $result['package_reapply'],
            'A failed re-apply must be reported for its package.',
        );
        assertRuntimeSame(
            true,
            in_array('package-sample', array_column($platformAdapter->notices, 'identifier'), true),
            'A failed re-apply must raise a notice.',
        );
        assertRuntimeSame([], $platformAdapter->reloadedServices, 'Package fields must not reload pfSense services.');

        removeTestTree($stateDirectory);
    },
    'plans Other Settings values only when selected, each with the reload its subsystem needs' => static function (): void {
        $configuration = makeLocalFieldsFixture();
        $selection = FieldSelection::autoSelect();
        $planChanges = static fn (array $planSelection): array => array_column(
            ConfigurationChangePlanner::plan(
                '2001:db8:1111:2200::1',
                56,
                '2001:db8:aaaa:bb00::1',
                56,
                ConfigurationTargetScanner::scan($configuration, $planSelection),
            )['changes'],
            null,
            'path',
        );
        $expectedServices = [
            'aliases/alias/0/detail' => 'none',
            'cert/0/crt' => 'none',
            'gateways/gateway_item/0/gateway' => 'routing',
            'gateways/gateway_item/0/monitor' => 'routing',
            'installedpackages/sample/config/0/admin_password' => 'package:sample',
            'installedpackages/wireguard/peers/item/0/allowedips/row/0/address' => 'wireguard',
            'ipsec/mobilekey/0/pre-shared-key' => 'ipsec',
            'ipsec/phase1/0/remote-gateway' => 'ipsec',
            'ipsec/phase2/0/remoteid/address' => 'ipsec',
            'openvpn/openvpn-server/0/remote_networkv6' => 'openvpn',
            'staticroutes/route/0/network' => 'routing',
            'system/dnsserver/0' => 'dns',
            'unbound/custom_options' => 'dns_resolver',
            'unbound/domainoverrides/0/ip' => 'dns_resolver',
        ];

        foreach (ConfigurationTargetScanner::scanAll($configuration) as $target) {
            if ($target['group'] !== FieldSelection::OTHER_GROUP) {
                $selection['groups'][$target['group']] = true;
            }
        }

        $changes = $planChanges($selection);

        assertRuntimeSame(
            [],
            array_keys(array_intersect_key($changes, $expectedServices)),
            'Other Settings must stay unchanged while its switch is off and nothing in it is ticked.',
        );
        assertRuntimeSame(
            true,
            isset($changes['installedpackages/sample/config/0/listen_address']),
            'Turning a package group on must plan its values.',
        );

        $selection['groups'][FieldSelection::OTHER_GROUP] = true;
        $changes = $planChanges($selection);
        $plannedServices = array_map(
            static fn (array $change): string => $change['service'],
            array_intersect_key($changes, $expectedServices),
        );

        ksort($plannedServices);
        assertRuntimeSame(
            $expectedServices,
            $plannedServices,
            'With Other Settings on, every value in it must be planned with the reload its subsystem needs.',
        );
        assertRuntimeSame(
            [
                "server:\nlocal-data: \"printer.home.arpa AAAA 2001:db8:aaaa:bbaa::50\"\n",
                '2001:db8:aaaa:bbe0::/60',
                'was 2001:db8:aaaa:bbaa::78',
            ],
            [
                base64_decode($changes['unbound/custom_options']['after'], true),
                $changes['staticroutes/route/0/network']['after'],
                $changes['aliases/alias/0/detail']['after'],
            ],
            'Base64 text must be decoded, rewritten, and encoded again, and free text must keep everything but the address.',
        );
        assertRuntimeSame(
            [false, false],
            [isset($changes['dhcpdv6/lan/staticmap/1/ipaddrv6']), isset($changes['dhcpdv6/opt1/range/from'])],
            'Values outside the prefix must stay as they are.',
        );

        $tickedSelection = FieldSelection::autoSelect();
        $tickedSelection['include'] = ['other:gateways/gateway_item/name=LAN_ROUTER/gateway', 'other:system/dnsserver'];

        assertRuntimeSame(
            ['gateways/gateway_item/0/gateway', 'system/dnsserver/0'],
            array_keys(array_intersect_key($planChanges($tickedSelection), $expectedServices)),
            'A ticked field must be planned on its own while Other Settings is off.',
        );
    },
    'deletes the old network of a ticked static route before reloading routing' => static function (): void {
        $platformAdapter = new FakePlatformAdapter();
        $platformAdapter->configuration = makeLocalFieldsFixture();
        $platformAdapter->interfaces = ['lan'];
        $platformAdapter->currentAddress = '2001:db8:aaaa:bbaa::1';
        $platformAdapter->settings = makeMultiInterfaceSettings(['lan' => '56']);
        $platformAdapter->fieldSelection = FieldSelection::toStorage([
            'groups' => FieldSelection::clearAll()['groups'],
            'include' => [
                'other:staticroutes/route/descr=Downstream%20router/network',
                'other:gateways/gateway_item/name=LAN_ROUTER/gateway',
            ],
            'exclude' => [],
        ]);
        $stateDirectory = makeTemporaryDirectory();
        $stateStore = new StateStore($stateDirectory . '/state.json');

        $stateStore->write([
            'schema_version' => 1,
            'targets' => [
                'lan' => ['interface' => 'lan', 'source_address' => null, 'prefix' => '2001:db8:1111:2200::', 'prefix_length' => 56],
            ],
            'last_run' => null,
        ]);

        $result = (new PrefixUpdater($platformAdapter, $stateStore, makeBackupStore()))->run(true, false);

        assertRuntimeSame('success', $result['status'], 'Ticked Other Settings fields must be updated.');
        assertRuntimeSame(
            ['2001:db8:aaaa:bbe0::/60', '2001:db8:aaaa:bbaa::fe'],
            [
                $platformAdapter->getConfigurationValue('staticroutes/route/0/network'),
                $platformAdapter->getConfigurationValue('gateways/gateway_item/0/gateway'),
            ],
            'The route and its gateway must move to the new prefix.',
        );
        assertRuntimeSame(
            [['2001:db8:1111:22e0::/60'], ['routing']],
            [$platformAdapter->deletedRoutes, $platformAdapter->reloadedServices],
            'The old route network must be deleted, because the routing reload never removes it, and only routing may reload.',
        );

        removeTestTree($stateDirectory);
    },
];

$failureCount = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, sprintf("PASS %s\n", $name));
    } catch (Throwable $throwable) {
        $failureCount += 1;
        fwrite(STDERR, sprintf("FAIL %s\n%s\n", $name, $throwable->getMessage()));
    }
}

if ($failureCount !== 0) {
    fwrite(STDERR, sprintf("%d runtime test(s) failed.\n", $failureCount));

    exit(1);
}

fwrite(STDOUT, sprintf("%d runtime tests passed.\n", count($tests)));

exit(0);

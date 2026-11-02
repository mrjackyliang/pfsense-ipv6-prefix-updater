<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

require_once(__DIR__ . '/ConfigurationChangePlanner.php');
require_once(__DIR__ . '/FieldSelection.php');

/**
 * Selects approved IPv6-bearing fields from a pfSense configuration snapshot.
 *
 * Each scanner branch encodes reviewed schema knowledge for one pfSense subsystem.
 * Every other value that holds an address belongs to the Other Settings group.
 *
 * @since 2.0.0
 */
final class ConfigurationTargetScanner
{
    /**
     * Package sections that are package metadata or reviewed elsewhere.
     *
     * WireGuard tunnel addresses have their own group, and its peers belong to Other
     * Settings.
     *
     * @since 2.0.0
     */
    private const SKIPPED_PACKAGE_SECTIONS = [
        'ipv6prefixupdater',
        'menu',
        'package',
        'service',
        'wireguard',
    ];

    /**
     * Fields that may identify one record in another package's list.
     *
     * The first field whose value is unique among the record's siblings names it, so
     * a choice follows the record when the list is reordered.
     *
     * @since 2.0.0
     */
    private const PACKAGE_IDENTITY_FIELDS = [
        'tracker',
        'uniqid',
        'uuid',
        'refid',
        'id',
        'ident',
        'name',
        'host',
        'hostname',
        'domain',
        'descr',
        'description',
    ];

    /**
     * Remote-side VPN fields, written with "*" in place of list positions.
     *
     * @since 2.0.0
     */
    private const REMOTE_VPN_PATHS = [
        'ipsec/phase1/*/remote-gateway',
        'ipsec/phase1/*/peerid_data',
        'openvpn/openvpn-server/*/remote_network',
        'openvpn/openvpn-server/*/remote_networkv6',
        'openvpn/openvpn-client/*/server_addr',
        'openvpn/openvpn-client/*/proxy_addr',
        'openvpn/openvpn-client/*/remote_network',
        'openvpn/openvpn-client/*/remote_networkv6',
        'openvpn/openvpn-csc/*/remote_network',
        'openvpn/openvpn-csc/*/remote_networkv6',
    ];

    /**
     * Fields that may identify one record of a list outside the reviewed groups.
     *
     * The first field whose value is unique among the record's siblings and holds no
     * address names it, so a choice survives both reordering and a prefix change.
     *
     * @since 2.0.0
     */
    private const OTHER_IDENTITY_FIELDS = [
        'tracker',
        'uniqid',
        'uuid',
        'refid',
        'ikeid',
        'vpnid',
        'id',
        'ident',
        'name',
        'common_name',
        'publickey',
        'host',
        'hostname',
        'domain',
        'descr',
        'description',
    ];

    /**
     * Configuration branches that hold no firewall settings and are never scanned.
     *
     * @since 2.0.0
     */
    private const OTHER_SKIPPED_PATHS = [
        'revision',
        'rrddata',
        'installedpackages/ipv6prefixupdater',
        'installedpackages/menu',
        'installedpackages/package',
        'installedpackages/service',
    ];

    /**
     * Returns every selected configuration target.
     *
     * The supplied configuration is never mutated. A field is selected when its group
     * is on and it isn't excluded, or when it is included while its group is off.
     *
     * @param array $configuration - Complete pfSense configuration snapshot.
     * @param array $selection     - Field selection from FieldSelection.
     *
     * @return list<array{
     *     target_id: string,
     *     path: string,
     *     value: string,
     *     format: string,
     *     service: string,
     *     interface: string|null,
     *     group: string,
     *     field_id: string|null,
     *     record: string|null,
     *     label: string|null,
     *     label_text: string|null
     * }>
     *
     * @since 2.0.0
     */
    public static function scan(array $configuration, array $selection): array
    {
        return array_values(array_filter(
            self::scanAll($configuration),
            static fn (array $target): bool => FieldSelection::isSelected(
                $selection,
                $target['group'],
                $target['field_id'],
            ),
        ));
    }

    /**
     * Returns every configuration target, selected or not.
     *
     * The Fields tab uses the full set to list each field with its selection, and a
     * run uses it to find selected fields that no longer exist.
     *
     * @param array $configuration - Complete pfSense configuration snapshot.
     *
     * @return list<array>
     *
     * @since 2.0.0
     */
    public static function scanAll(array $configuration): array
    {
        $targets = [];

        self::scanInterfaces($configuration, $targets);
        self::scanVirtualIps($configuration, $targets);
        self::scanAliases($configuration, $targets);
        self::scanFirewallRules($configuration, $targets);
        self::scanNat($configuration, $targets);
        self::scanDhcpv6($configuration, $targets);
        self::scanRouterAdvertisements($configuration, $targets);
        self::scanDnsHostOverrides($configuration, $targets);
        self::scanIpsec($configuration, $targets);
        self::scanOpenVpn($configuration, $targets);
        self::scanWireGuard($configuration, $targets);
        self::scanPackages($configuration, $targets);
        self::scanOtherSettings($configuration, $targets);

        return $targets;
    }

    /**
     * Names the kind of value at a path that no reviewed group covers, such as a gateway.
     *
     * Other packages' fields of these kinds belong to Other Settings instead of their
     * package's group. Null means the path has no special kind.
     *
     * @param list<string> $segments - Configuration path segments.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public static function protectedReason(array $segments): ?string
    {
        $root = $segments[0] ?? '';
        $leaf = strtolower((string) end($segments));
        $wildcardSegments = array_map(
            static fn (string $segment): string => (ctype_digit($segment)) ? '*' : $segment,
            $segments,
        );
        $wildcardPath = implode('/', $wildcardSegments);

        if (
            in_array($root, ['ca', 'cert', 'crl'], true)
            || preg_match('/(crt|csr|cert)/', $leaf) === 1
        ) {
            return 'certificate';
        }

        if (preg_match('/(pass|secret|psk|hash|prv|tls|token|authorizedkeys|key$)/', $leaf) === 1) {
            return 'secret';
        }

        if (str_starts_with($wildcardPath, 'installedpackages/wireguard/peers/')) {
            return 'wireguard_peer';
        }

        if (
            in_array($wildcardPath, self::REMOTE_VPN_PATHS, true)
            || preg_match('#^ipsec/phase2/\*/remoteid/#', $wildcardPath) === 1
        ) {
            return 'remote_vpn';
        }

        if ($root === 'gateways') {
            return 'gateway';
        }

        if ($root === 'staticroutes') {
            return 'static_route';
        }

        if (in_array($leaf, ['descr', 'description', 'detail', 'comment', 'comments', 'notes'], true)) {
            return 'description';
        }

        return null;
    }

    /**
     * Adds one non-empty string field to the target list.
     *
     * Central target construction keeps the exact record shape consistent across each
     * schema-specific scanner without interpreting the field's contents.
     *
     * @param array $targets - Accumulated targets.
     * @param array $target  - Target fields; a missing optional field becomes null.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function addTarget(array &$targets, array $target): void
    {
        $value = $target['value'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $targets[] = [
            'target_id' => $target['target_id'],
            'path' => $target['path'],
            'value' => $value,
            'format' => $target['format'],
            'service' => $target['service'],
            'interface' => $target['interface'] ?? null,
            'group' => $target['group'],
            'field_id' => $target['field_id'] ?? null,
            'record' => $target['record'] ?? null,
            'label' => $target['label'] ?? null,
            'label_text' => $target['label_text'] ?? null,
        ];
    }

    /**
     * Reports the address families listed in a host override value.
     *
     * A host and domain may carry one IPv4 and one IPv6 override, so the family keeps
     * their identities apart without depending on list position.
     *
     * @param mixed $value - Host override address value.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private static function addressFamily(mixed $value): string
    {
        $tokens = is_string($value) ? preg_split('/[,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) : [];
        $hasIpv4 = false;
        $hasIpv6 = false;

        foreach (is_array($tokens) ? $tokens : [] as $token) {
            if (str_contains($token, ':')) {
                $hasIpv6 = true;
            } elseif (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $token) === 1) {
                $hasIpv4 = true;
            }
        }

        if ($hasIpv4 && $hasIpv6) {
            return 'inet46';
        }

        if ($hasIpv6) {
            return 'inet6';
        }

        return ($hasIpv4) ? 'inet' : 'none';
    }

    /**
     * Reads a nested configuration array through a slash-delimited path.
     *
     * The helper mirrors pfSense path semantics for pure fixture-based tests. A missing
     * or non-array segment safely returns the caller's default value.
     *
     * @param array  $configuration - Configuration snapshot.
     * @param string $path          - Slash-delimited path.
     * @param mixed  $default       - Missing-path default.
     *
     * @return mixed
     *
     * @since 2.0.0
     * @private
     */
    private static function getPath(array $configuration, string $path, mixed $default = null): mixed
    {
        $segments = explode('/', $path);
        $value = $configuration;

        foreach ($segments as $segment) {
            if (!is_array($value) || array_key_exists($segment, $value) === false) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Returns the name pfSense shows for an interface, such as "LAN" or a description.
     *
     * Fields tab rows name interface, DHCPv6, and router advertisement settings by
     * interface, the way pfSense's own tabs label them.
     *
     * @param array  $configuration - Configuration snapshot.
     * @param string $interface     - Friendly interface name.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private static function interfaceName(array $configuration, string $interface): string
    {
        $description = self::getPath($configuration, 'interfaces/' . $interface . '/descr');

        return (is_string($description) && trim($description) !== '') ? trim($description) : strtoupper($interface);
    }

    /**
     * Names one record of a list outside the reviewed groups by a unique field.
     *
     * Fields that hold an address are skipped, because a prefix change would rename the
     * record, and a package's single settings record keeps position 0. Null means no
     * field names the record uniquely.
     *
     * @param array      $records - Sibling records.
     * @param int|string $index   - Position of the record.
     * @param string     $listKey - Configuration key that holds the list.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function otherRecordSelector(array $records, int|string $index, string $listKey): ?string
    {
        if ($listKey === 'config' && count($records) === 1 && $index === 0) {
            return '0';
        }

        $record = $records[$index] ?? null;

        if (!is_array($record)) {
            return null;
        }

        foreach (self::OTHER_IDENTITY_FIELDS as $fieldName) {
            $value = $record[$fieldName] ?? null;

            if (
                !is_int($value)
                && (!is_string($value) || trim($value) === '' || ConfigurationChangePlanner::containsAddressToken($value))
            ) {
                continue;
            }

            $matchCount = 0;

            foreach ($records as $sibling) {
                if (is_array($sibling) && (string) ($sibling[$fieldName] ?? '') === (string) $value) {
                    $matchCount++;
                }
            }

            if ($matchCount === 1) {
                return $fieldName . '=' . rawurlencode(trim((string) $value));
            }
        }

        return null;
    }

    /**
     * Returns the service a run reloads after changing a value in Other Settings.
     *
     * Descriptions and certificates need no reload, and a value whose subsystem has no
     * known reload is only written.
     *
     * @param list<string> $pathSegments - Configuration path segments.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private static function otherService(array $pathSegments): string
    {
        $root = $pathSegments[0] ?? '';

        if (in_array(self::protectedReason($pathSegments), ['description', 'certificate'], true)) {
            return 'none';
        }

        if ($root === 'installedpackages') {
            $section = $pathSegments[1] ?? '';

            if ($section === 'wireguard') {
                return 'wireguard';
            }

            return (preg_match('/^[a-z0-9_-]+$/', $section) === 1) ? 'package:' . $section : 'none';
        }

        return match ($root) {
            'gateways', 'staticroutes' => 'routing',
            'interfaces', 'virtualip' => 'interfaces',
            'filter', 'aliases', 'nat' => 'filter',
            'dhcpdv6' => 'dhcpv6',
            'unbound' => 'dns_resolver',
            'dnsmasq' => 'dns_forwarder',
            'ipsec' => 'ipsec',
            'openvpn' => 'openvpn',
            'system' => (($pathSegments[1] ?? '') === 'dnsserver') ? 'dns' : 'none',
            default => 'none',
        };
    }

    /**
     * Names one record of another package's list by a unique identity field.
     *
     * The list key "config" with a single record is pfSense's singleton settings
     * record, which keeps position 0. Null means no field names the record uniquely.
     *
     * @param array      $records - Sibling records.
     * @param int|string $index   - Position of the record.
     * @param string     $listKey - Configuration key that holds the list.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function packageRecordSelector(array $records, int|string $index, string $listKey): ?string
    {
        if ($listKey === 'config' && count($records) === 1 && $index === 0) {
            return '0';
        }

        $record = $records[$index] ?? null;

        if (!is_array($record)) {
            return null;
        }

        foreach (self::PACKAGE_IDENTITY_FIELDS as $fieldName) {
            $value = $record[$fieldName] ?? null;

            if ((!is_string($value) || trim($value) === '') && !is_int($value)) {
                continue;
            }

            $matchCount = 0;

            foreach ($records as $sibling) {
                if (is_array($sibling) && (string) ($sibling[$fieldName] ?? '') === (string) $value) {
                    $matchCount++;
                }
            }

            if ($matchCount === 1) {
                return $fieldName . '=' . rawurlencode(trim((string) $value));
            }
        }

        return null;
    }

    /**
     * Returns the first non-empty display name among reviewed record fields.
     *
     * The name only labels a Fields tab row. Identity and planning never depend on it,
     * so an edited description can't redirect a change.
     *
     * @param array|null   $record     - Configuration record.
     * @param list<string> $fieldNames - Candidate name fields.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function recordName(?array $record, array $fieldNames): ?string
    {
        foreach ($fieldNames as $fieldName) {
            $value = self::getPath($record ?? [], $fieldName);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Selects host and network alias address lists.
     *
     * Port, URL, table, and external aliases are excluded because their text does not
     * represent package-owned literal IPv6 configuration.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanAliases(array $configuration, array &$targets): void
    {
        $aliases = self::getPath($configuration, 'aliases/alias', []);

        if (!is_array($aliases)) {
            return;
        }

        $identities = self::uniqueIdentities(
            $aliases,
            static fn (array $alias): ?string => self::stableIdentity($alias, ['name']),
        );

        foreach ($aliases as $index => $alias) {
            if (!is_array($alias)) {
                continue;
            }

            $aliasType = $alias['type'] ?? null;

            if ($aliasType !== 'host' && $aliasType !== 'network') {
                continue;
            }

            $aliasIdentity = $identities[$index];
            $targetId = 'alias:' . ($aliasIdentity ?? '#' . $index);

            self::addTarget($targets, [
                'group' => 'aliases',
                'target_id' => $targetId,
                'field_id' => ($aliasIdentity === null) ? null : $targetId,
                'path' => sprintf('aliases/alias/%s/address', $index),
                'value' => $alias['address'] ?? null,
                'format' => 'space_list',
                'service' => 'filter',
                'record' => self::recordName($alias, ['name']),
                'label' => 'field_addresses',
            ]);
        }
    }

    /**
     * Selects DHCPv6 server ranges, pools, static mappings, and handed-out servers.
     *
     * Interfaces that track a delegated prefix store host suffixes such as "::1000"
     * instead of addresses, so those values never match a prefix and stay unchanged.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanDhcpv6(array $configuration, array &$targets): void
    {
        $servers = self::getPath($configuration, 'dhcpdv6', []);

        if (!is_array($servers)) {
            return;
        }

        foreach ($servers as $interface => $server) {
            if (!is_string($interface) || !is_array($server)) {
                continue;
            }

            $interfaceName = self::interfaceName($configuration, $interface);

            self::scanDhcpv6Settings(
                $targets,
                $server,
                'dhcpdv6/' . $interface,
                'dhcpv6:' . $interface,
                true,
                $interface,
                $interfaceName,
            );

            $pools = $server['pool'] ?? [];

            if (is_array($pools)) {
                $poolIdentities = self::uniqueIdentities(
                    $pools,
                    static fn (array $pool): ?string => self::stableIdentity($pool, ['descr']),
                );

                foreach ($pools as $poolIndex => $pool) {
                    if (!is_array($pool)) {
                        continue;
                    }

                    $poolIdentity = $poolIdentities[$poolIndex];

                    self::scanDhcpv6Settings(
                        $targets,
                        $pool,
                        sprintf('dhcpdv6/%s/pool/%s', $interface, $poolIndex),
                        sprintf('dhcpv6-pool:%s:%s', $interface, $poolIdentity ?? '#' . $poolIndex),
                        $poolIdentity !== null,
                        $interface,
                        $interfaceName . ': ' . (self::recordName($pool, ['descr']) ?? '#' . $poolIndex),
                    );
                }
            }

            $mappings = $server['staticmap'] ?? [];

            if (!is_array($mappings)) {
                continue;
            }

            $mappingIdentities = self::uniqueIdentities(
                $mappings,
                static fn (array $mapping): ?string => self::stableIdentity($mapping, ['duid']),
            );

            foreach ($mappings as $mappingIndex => $mapping) {
                if (!is_array($mapping)) {
                    continue;
                }

                $mappingIdentity = $mappingIdentities[$mappingIndex];
                $targetId = sprintf(
                    'dhcpv6-static-mapping:%s:%s',
                    $interface,
                    $mappingIdentity ?? '#' . $mappingIndex,
                );

                self::addTarget($targets, [
                    'group' => 'dhcpv6',
                    'target_id' => $targetId,
                    'field_id' => ($mappingIdentity === null) ? null : $targetId,
                    'path' => sprintf('dhcpdv6/%s/staticmap/%s/ipaddrv6', $interface, $mappingIndex),
                    'value' => $mapping['ipaddrv6'] ?? null,
                    'format' => 'single',
                    'service' => 'dhcpv6',
                    'interface' => $interface,
                    'record' => $interfaceName . ': ' . (self::recordName($mapping, ['hostname', 'duid']) ?? '#' . $mappingIndex),
                    'label' => 'field_static_mapping',
                ]);
            }
        }
    }

    /**
     * Selects the ranges and handed-out servers of one DHCPv6 server or pool.
     *
     * Each DNS or NTP server slot is its own target, while one identity covers the
     * whole list because pfSense renumbers the slots when an earlier one is cleared.
     *
     * @param array  $targets    - Accumulated targets.
     * @param array  $settings   - DHCPv6 server or pool settings.
     * @param string $basePath   - Configuration path of the settings.
     * @param string $baseId     - Target identifier prefix.
     * @param bool   $identified - Whether the settings have a stable identity.
     * @param string $interface  - Friendly interface name.
     * @param string $record     - Display name of the settings.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanDhcpv6Settings(
        array &$targets,
        array $settings,
        string $basePath,
        string $baseId,
        bool $identified,
        string $interface,
        string $record,
    ): void {
        $ranges = [
            'range' => ['range', 'field_range_start', 'field_range_end'],
            'prefixrange' => ['prefix-range', 'field_prefix_range_start', 'field_prefix_range_end'],
        ];
        $serverLists = [
            'dnsserver' => ['dns-server', 'field_dns_server'],
            'ntpserver' => ['ntp-server', 'field_ntp_server'],
        ];

        foreach ($ranges as $rangeName => [$rangeId, $startLabel, $endLabel]) {
            foreach (['from' => $startLabel, 'to' => $endLabel] as $boundary => $label) {
                $targetId = sprintf('%s:%s-%s', $baseId, $rangeId, $boundary);

                self::addTarget($targets, [
                    'group' => 'dhcpv6',
                    'target_id' => $targetId,
                    'field_id' => ($identified) ? $targetId : null,
                    'path' => sprintf('%s/%s/%s', $basePath, $rangeName, $boundary),
                    'value' => self::getPath($settings, $rangeName . '/' . $boundary),
                    'format' => 'single',
                    'service' => 'dhcpv6',
                    'interface' => $interface,
                    'record' => $record,
                    'label' => $label,
                ]);
            }
        }

        foreach ($serverLists as $listName => [$listId, $label]) {
            $servers = $settings[$listName] ?? [];

            if (!is_array($servers)) {
                continue;
            }

            foreach ($servers as $serverIndex => $server) {
                self::addTarget($targets, [
                    'group' => 'dhcpv6',
                    'target_id' => sprintf('%s:%s-%s', $baseId, $listId, $serverIndex),
                    'field_id' => ($identified) ? sprintf('%s:%ss', $baseId, $listId) : null,
                    'path' => sprintf('%s/%s/%s', $basePath, $listName, $serverIndex),
                    'value' => $server,
                    'format' => 'single',
                    'service' => 'dhcpv6',
                    'interface' => $interface,
                    'record' => $record,
                    'label' => $label,
                ]);
            }
        }
    }

    /**
     * Selects DNS Resolver and DNS Forwarder host override addresses.
     *
     * Domain overrides name upstream servers, often with an "@port" suffix, so they are
     * never scanned. Host overrides are identified by host, domain, and address family.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanDnsHostOverrides(array $configuration, array &$targets): void
    {
        $resolvers = [
            [
                'path' => 'unbound/hosts',
                'kind' => 'dns-resolver-host',
                'format' => 'comma_list',
                'service' => 'dns_resolver',
                'label' => 'field_addresses',
            ],
            [
                'path' => 'dnsmasq/hosts',
                'kind' => 'dns-forwarder-host',
                'format' => 'single',
                'service' => 'dns_forwarder',
                'label' => 'field_address',
            ],
        ];

        foreach ($resolvers as $resolver) {
            $hosts = self::getPath($configuration, $resolver['path'], []);

            if (!is_array($hosts)) {
                continue;
            }

            $identities = self::uniqueIdentities(
                $hosts,
                static function (array $host): ?string {
                    $hostName = $host['host'] ?? '';
                    $domain = $host['domain'] ?? '';

                    if (!is_string($hostName) || !is_string($domain) || trim($domain) === '') {
                        return null;
                    }

                    return sprintf(
                        '%s:%s:%s',
                        rawurlencode(trim($hostName)),
                        rawurlencode(trim($domain)),
                        self::addressFamily($host['ip'] ?? null),
                    );
                },
            );

            foreach ($hosts as $index => $host) {
                if (!is_array($host)) {
                    continue;
                }

                $hostIdentity = $identities[$index];
                $targetId = $resolver['kind'] . ':' . ($hostIdentity ?? '#' . $index);
                $hostName = self::recordName($host, ['host']);
                $domain = self::recordName($host, ['domain']);
                $fullName = ($hostName !== null && $domain !== null) ? $hostName . '.' . $domain : $hostName ?? $domain;

                self::addTarget($targets, [
                    'group' => 'dns_host_overrides',
                    'target_id' => $targetId,
                    'field_id' => ($hostIdentity === null) ? null : $targetId,
                    'path' => sprintf('%s/%s/ip', $resolver['path'], $index),
                    'value' => $host['ip'] ?? null,
                    'format' => $resolver['format'],
                    'service' => $resolver['service'],
                    'record' => $fullName,
                    'label' => $resolver['label'],
                ]);
            }
        }
    }

    /**
     * Selects literal source and destination addresses of firewall rules.
     *
     * Only the "address" form is read. Interface networks, "(self)", and "any" are
     * stored in other keys, and alias names inside "address" pass through the planner.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanFirewallRules(array $configuration, array &$targets): void
    {
        $rules = self::getPath($configuration, 'filter/rule', []);

        if (!is_array($rules)) {
            return;
        }

        $identities = self::uniqueIdentities(
            $rules,
            static fn (array $rule): ?string => self::stableIdentity($rule, ['tracker']),
        );

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $ruleIdentity = $identities[$index];

            foreach (['source' => 'field_source', 'destination' => 'field_destination'] as $side => $label) {
                $targetId = sprintf('firewall-rule:%s:%s', $ruleIdentity ?? '#' . $index, $side);

                self::addTarget($targets, [
                    'group' => 'firewall_rules',
                    'target_id' => $targetId,
                    'field_id' => ($ruleIdentity === null) ? null : $targetId,
                    'path' => sprintf('filter/rule/%s/%s/address', $index, $side),
                    'value' => self::getPath($rule, $side . '/address'),
                    'format' => 'single',
                    'service' => 'filter',
                    'record' => self::recordName($rule, ['descr', 'tracker']),
                    'label' => $label,
                ]);
            }
        }
    }

    /**
     * Selects literal static IPv6 interface addresses.
     *
     * Dynamic modes such as DHCPv6, SLAAC, and tracked prefixes do not contain a colon
     * and are excluded before planning so the runtime never overwrites their mode.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanInterfaces(array $configuration, array &$targets): void
    {
        $interfaces = self::getPath($configuration, 'interfaces', []);

        if (!is_array($interfaces)) {
            return;
        }

        foreach ($interfaces as $interfaceName => $interface) {
            if (!is_string($interfaceName) || !is_array($interface)) {
                continue;
            }

            $address = $interface['ipaddrv6'] ?? null;

            if (!is_string($address) || str_contains($address, ':') === false) {
                continue;
            }

            self::addTarget($targets, [
                'group' => 'interfaces',
                'target_id' => 'interface:' . $interfaceName,
                'field_id' => 'interface:' . $interfaceName,
                'path' => sprintf('interfaces/%s/ipaddrv6', $interfaceName),
                'value' => $address,
                'format' => 'single',
                'service' => 'interfaces',
                'interface' => $interfaceName,
                'record' => self::interfaceName($configuration, $interfaceName),
                'label' => 'field_address',
            ]);
        }
    }

    /**
     * Selects local IPsec Phase 2 address and network identities.
     *
     * Remote identities are intentionally excluded. NAT-local identities remain local
     * pfSense-owned values and are approved alongside the primary local identity.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanIpsec(array $configuration, array &$targets): void
    {
        $phaseTwos = self::getPath($configuration, 'ipsec/phase2', []);

        if (!is_array($phaseTwos)) {
            return;
        }

        $identities = self::uniqueIdentities(
            $phaseTwos,
            static fn (array $phaseTwo): ?string => self::stableIdentity(
                $phaseTwo,
                [
                    'uniqid',
                    'reqid',
                    'ikeid',
                ],
            ),
        );

        foreach ($phaseTwos as $index => $phaseTwo) {
            if (!is_array($phaseTwo)) {
                continue;
            }

            $phaseTwoMode = $phaseTwo['mode'] ?? null;

            if ($phaseTwoMode !== 'tunnel6' && $phaseTwoMode !== 'vti') {
                continue;
            }

            $phaseTwoIdentity = $identities[$index];

            foreach (['localid' => 'field_local_network', 'natlocalid' => 'field_nat_translation'] as $identityName => $label) {
                $identity = $phaseTwo[$identityName] ?? null;

                if (!is_array($identity)) {
                    continue;
                }

                $identityType = $identity['type'] ?? null;

                if ($identityType !== 'address' && $identityType !== 'network') {
                    continue;
                }

                $targetId = sprintf('ipsec:%s:%s', $phaseTwoIdentity ?? '#' . $index, $identityName);

                self::addTarget($targets, [
                    'group' => 'ipsec',
                    'target_id' => $targetId,
                    'field_id' => ($phaseTwoIdentity === null) ? null : $targetId,
                    'path' => sprintf('ipsec/phase2/%s/%s/address', $index, $identityName),
                    'value' => $identity['address'] ?? null,
                    'format' => 'single',
                    'service' => 'ipsec',
                    'record' => self::recordName($phaseTwo, ['descr', 'uniqid']),
                    'label' => $label,
                ]);
            }
        }
    }

    /**
     * Selects literal addresses of port forwards, outbound NAT, 1:1 NAT, and NPt.
     *
     * NPt destinations that track an interface live in "network" and are skipped, as
     * pfSense derives them. Rules lacking a stable identity can't be chosen singly.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanNat(array $configuration, array &$targets): void
    {
        $natLists = [
            [
                'path' => 'nat/rule',
                'kind' => 'port-forward',
                'identity' => ['created/time'],
                'fields' => [
                    'source' => ['source/address', 'field_source'],
                    'destination' => ['destination/address', 'field_destination'],
                    'target' => ['target', 'field_redirect_target'],
                ],
            ],
            [
                'path' => 'nat/outbound/rule',
                'kind' => 'outbound-nat',
                'identity' => ['created/time'],
                'fields' => [
                    'source' => ['source/network', 'field_source'],
                    'destination' => ['destination/network', 'field_destination'],
                    'target' => ['target', 'field_translation'],
                ],
            ],
            [
                'path' => 'nat/onetoone',
                'kind' => 'one-to-one-nat',
                'identity' => ['descr'],
                'fields' => [
                    'external' => ['external', 'field_external_address'],
                    'source' => ['source/address', 'field_internal_address'],
                    'destination' => ['destination/address', 'field_destination'],
                ],
            ],
            [
                'path' => 'nat/npt',
                'kind' => 'npt',
                'identity' => ['descr'],
                'fields' => [
                    'source' => ['source/address', 'field_internal_prefix'],
                    'destination' => ['destination/address', 'field_external_prefix'],
                ],
            ],
        ];

        foreach ($natLists as $natList) {
            $rules = self::getPath($configuration, $natList['path'], []);

            if (!is_array($rules)) {
                continue;
            }

            $identities = self::uniqueIdentities(
                $rules,
                static fn (array $rule): ?string => self::stableIdentity($rule, $natList['identity']),
            );

            foreach ($rules as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $ruleIdentity = $identities[$index];

                foreach ($natList['fields'] as $fieldId => [$fieldPath, $label]) {
                    $targetId = sprintf('%s:%s:%s', $natList['kind'], $ruleIdentity ?? '#' . $index, $fieldId);

                    self::addTarget($targets, [
                        'group' => 'nat',
                        'target_id' => $targetId,
                        'field_id' => ($ruleIdentity === null) ? null : $targetId,
                        'path' => sprintf('%s/%s/%s', $natList['path'], $index, $fieldPath),
                        'value' => self::getPath($rule, $fieldPath),
                        'format' => 'single',
                        'service' => 'filter',
                        'record' => self::recordName($rule, ['descr']),
                        'label' => $label,
                    ]);
                }
            }
        }
    }

    /**
     * Selects local OpenVPN server, client tunnel, and override fields.
     *
     * Remote-network fields are excluded. Track-interface server tunnels are also
     * excluded because pfSense derives those values from live interface state.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanOpenVpn(array $configuration, array &$targets): void
    {
        $instanceLists = [
            [
                'path' => 'openvpn/openvpn-server',
                'kind' => 'openvpn-server',
                'identity' => ['vpnid', 'description'],
                'name' => ['description', 'vpnid'],
                'fields' => [
                    'tunnel' => ['tunnel_networkv6', 'single', 'field_tunnel_network'],
                    'local' => ['local_networkv6', 'comma_list', 'field_local_network'],
                ],
            ],
            [
                'path' => 'openvpn/openvpn-client',
                'kind' => 'openvpn-client',
                'identity' => ['vpnid', 'description'],
                'name' => ['description', 'vpnid'],
                'fields' => [
                    'tunnel' => ['tunnel_networkv6', 'single', 'field_tunnel_network'],
                ],
            ],
            [
                'path' => 'openvpn/openvpn-csc',
                'kind' => 'openvpn-override',
                'identity' => ['common_name', 'description'],
                'name' => ['common_name', 'description'],
                'fields' => [
                    'tunnel' => ['tunnel_networkv6', 'single', 'field_tunnel_network'],
                    'local' => ['local_networkv6', 'comma_list', 'field_local_network'],
                ],
            ],
        ];

        foreach ($instanceLists as $instanceList) {
            $instances = self::getPath($configuration, $instanceList['path'], []);

            if (!is_array($instances)) {
                continue;
            }

            $identities = self::uniqueIdentities(
                $instances,
                static fn (array $instance): ?string => self::stableIdentity($instance, $instanceList['identity']),
            );

            foreach ($instances as $index => $instance) {
                if (!is_array($instance)) {
                    continue;
                }

                $instanceIdentity = $identities[$index];
                $targetIdPrefix = $instanceList['kind'] . ':' . ($instanceIdentity ?? '#' . $index);

                foreach ($instanceList['fields'] as $fieldId => [$fieldName, $format, $label]) {
                    if (
                        $instanceList['kind'] === 'openvpn-server'
                        && $fieldId === 'tunnel'
                        && ($instance['tunnel_networkv6_type'] ?? null) === 'track6'
                    ) {
                        continue;
                    }

                    self::addTarget($targets, [
                        'group' => 'openvpn',
                        'target_id' => $targetIdPrefix . ':' . $fieldId,
                        'field_id' => ($instanceIdentity === null) ? null : $targetIdPrefix . ':' . $fieldId,
                        'path' => sprintf('%s/%s/%s', $instanceList['path'], $index, $fieldName),
                        'value' => $instance[$fieldName] ?? null,
                        'format' => $format,
                        'service' => 'openvpn',
                        'record' => self::recordName($instance, $instanceList['name']),
                        'label' => $label,
                    ]);
                }
            }
        }
    }

    /**
     * Walks one configuration branch for values that no other group covers.
     *
     * Each list position is replaced by a unique field of its record, and a list of
     * plain values shares one identity. A value without an identity still follows the
     * group switch, but no exception can name it.
     *
     * @param array             $targets          - Accumulated targets.
     * @param mixed             $node             - Current configuration node.
     * @param list<string>      $pathSegments     - Configuration path segments.
     * @param list<string>|null $identitySegments - Identity path, or null without one.
     * @param array|null        $record           - Nearest list record above the node.
     * @param array<string, true> $coveredPaths   - Paths another group already lists.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanOtherNode(
        array &$targets,
        mixed $node,
        array $pathSegments,
        ?array $identitySegments,
        ?array $record,
        array $coveredPaths,
    ): void {
        if (is_array($node)) {
            if (in_array(implode('/', $pathSegments), self::OTHER_SKIPPED_PATHS, true)) {
                return;
            }

            $plainList = array_is_list($node) && count(array_filter($node, 'is_array')) === 0;
            $listKey = (string) end($pathSegments);

            foreach ($node as $key => $child) {
                $childIdentitySegments = $identitySegments;

                if (is_int($key) && $childIdentitySegments !== null && $plainList === false) {
                    $selector = self::otherRecordSelector($node, $key, $listKey);
                    $childIdentitySegments = ($selector === null) ? null : [...$childIdentitySegments, $selector];
                } elseif (is_string($key) && $childIdentitySegments !== null) {
                    $childIdentitySegments[] = rawurlencode($key);
                }

                self::scanOtherNode(
                    $targets,
                    $child,
                    [...$pathSegments, (string) $key],
                    $childIdentitySegments,
                    (is_int($key) && is_array($child)) ? $child : $record,
                    $coveredPaths,
                );
            }

            return;
        }

        $path = implode('/', $pathSegments);

        if (!is_string($node) || trim($node) === '' || isset($coveredPaths[$path])) {
            return;
        }

        $format = 'text';

        if (ConfigurationChangePlanner::containsAddressToken($node) === false) {
            $decodedValue = ConfigurationChangePlanner::decodeBase64($node);

            if ($decodedValue === null || ConfigurationChangePlanner::containsAddressToken($decodedValue) === false) {
                return;
            }

            $format = 'base64';
        }

        $reason = self::protectedReason($pathSegments) ?? (($format === 'base64') ? 'base64' : null);
        $labelText = (string) end($pathSegments);

        // An entry of a plain list is labeled by the list, such as "dnsserver".
        if (ctype_digit($labelText) && count($pathSegments) > 1) {
            $labelText = $pathSegments[count($pathSegments) - 2];
        }

        self::addTarget($targets, [
            'group' => FieldSelection::OTHER_GROUP,
            'target_id' => 'other:#' . $path,
            'field_id' => ($identitySegments === null) ? null : 'other:' . implode('/', $identitySegments),
            'path' => $path,
            'value' => $node,
            'format' => $format,
            'service' => self::otherService($pathSegments),
            'record' => self::recordName($record, ['name', 'descr', 'description', 'host', 'hostname', 'common_name', 'domain']),
            'label' => ($reason === null) ? null : 'reason_' . $reason,
            'label_text' => $labelText,
        ]);
    }

    /**
     * Selects every value with an address that no other group covers.
     *
     * Together they form the Other Settings group, which starts off, so nothing in it
     * changes until its switch is turned on or one of its fields is ticked.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanOtherSettings(array $configuration, array &$targets): void
    {
        self::scanOtherNode(
            $targets,
            $configuration,
            [],
            [],
            null,
            array_fill_keys(array_column($targets, 'path'), true),
        );
    }

    /**
     * Walks one branch of another package's settings for IPv6 values.
     *
     * Each list position is replaced by an identity field of its record. A value under
     * a record without one can still follow its group switch, but no exception names it.
     *
     * @param array             $targets          - Accumulated targets.
     * @param string            $section          - Package settings section.
     * @param mixed             $node             - Current settings node.
     * @param list<string>      $pathSegments     - Configuration path segments.
     * @param list<string>|null $identitySegments - Identity path, or null without one.
     * @param array|null        $record           - Nearest list record above the node.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanPackageNode(
        array &$targets,
        string $section,
        mixed $node,
        array $pathSegments,
        ?array $identitySegments,
        ?array $record,
    ): void {
        if (is_array($node)) {
            $listKey = (string) end($pathSegments);

            foreach ($node as $key => $child) {
                $childIdentitySegments = $identitySegments;

                if (is_int($key) && $childIdentitySegments !== null) {
                    $selector = self::packageRecordSelector($node, $key, $listKey);
                    $childIdentitySegments = ($selector === null) ? null : [...$childIdentitySegments, $selector];
                } elseif ($childIdentitySegments !== null) {
                    $childIdentitySegments[] = (string) $key;
                }

                self::scanPackageNode(
                    $targets,
                    $section,
                    $child,
                    [...$pathSegments, (string) $key],
                    $childIdentitySegments,
                    (is_int($key) && is_array($child)) ? $child : $record,
                );
            }

            return;
        }

        if (
            !is_string($node)
            || self::protectedReason($pathSegments) !== null
            || ConfigurationChangePlanner::containsAddressToken($node) === false
        ) {
            return;
        }

        $path = implode('/', $pathSegments);
        $fieldId = ($identitySegments === null)
            ? null
            : sprintf('package:%s:%s', $section, implode('/', $identitySegments));

        self::addTarget($targets, [
            'group' => 'package:' . $section,
            'target_id' => $fieldId ?? 'package:' . $section . ':#' . $path,
            'field_id' => $fieldId,
            'path' => $path,
            'value' => $node,
            'format' => 'text',
            'service' => 'package:' . $section,
            'record' => self::recordName($record, ['name', 'descr', 'description', 'host', 'hostname']) ?? $section,
            'label_text' => (string) end($pathSegments),
        ]);
    }

    /**
     * Selects IPv6 values in the settings of other installed packages.
     *
     * These fields need review: the package can't know what they mean, so they are
     * never selected automatically, and a run re-applies the owning package after.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanPackages(array $configuration, array &$targets): void
    {
        $sections = self::getPath($configuration, 'installedpackages', []);

        if (!is_array($sections)) {
            return;
        }

        foreach ($sections as $section => $sectionSettings) {
            if (
                !is_string($section)
                || !is_array($sectionSettings)
                || in_array($section, self::SKIPPED_PACKAGE_SECTIONS, true)
                || preg_match('/^[a-z0-9_-]+$/', $section) !== 1
            ) {
                continue;
            }

            self::scanPackageNode(
                $targets,
                $section,
                $sectionSettings,
                ['installedpackages', $section],
                [],
                null,
            );
        }
    }

    /**
     * Selects advertised DNS servers and RA subnets of each interface.
     *
     * Both lists are renumbered when pfSense saves the form, so every entry is its own
     * target while one identity per interface covers the whole list.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanRouterAdvertisements(array $configuration, array &$targets): void
    {
        $servers = self::getPath($configuration, 'dhcpdv6', []);

        if (!is_array($servers)) {
            return;
        }

        $lists = [
            'radnsserver' => ['dns-server', 'field_dns_server'],
            'subnets/item' => ['subnet', 'field_ra_subnet'],
        ];

        foreach ($servers as $interface => $server) {
            if (!is_string($interface) || !is_array($server)) {
                continue;
            }

            $interfaceName = self::interfaceName($configuration, $interface);

            foreach ($lists as $listPath => [$listId, $label]) {
                $entries = self::getPath($server, $listPath, []);

                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entryIndex => $entry) {
                    self::addTarget($targets, [
                        'group' => 'router_advertisements',
                        'target_id' => sprintf('router-advertisements:%s:%s-%s', $interface, $listId, $entryIndex),
                        'field_id' => sprintf('router-advertisements:%s:%ss', $interface, $listId),
                        'path' => sprintf('dhcpdv6/%s/%s/%s', $interface, $listPath, $entryIndex),
                        'value' => $entry,
                        'format' => 'single',
                        'service' => 'router_advertisements',
                        'interface' => $interface,
                        'record' => $interfaceName,
                        'label' => $label,
                    ]);
                }
            }
        }
    }

    /**
     * Selects CARP and IP Alias virtual IPv6 addresses.
     *
     * Proxy ARP and other virtual address modes are excluded because their schema and
     * runtime semantics are not approved for automatic IPv6 prefix changes.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanVirtualIps(array $configuration, array &$targets): void
    {
        $virtualIps = self::getPath($configuration, 'virtualip/vip', []);

        if (!is_array($virtualIps)) {
            return;
        }

        $identities = self::uniqueIdentities(
            $virtualIps,
            static fn (array $virtualIp): ?string => self::stableIdentity(
                $virtualIp,
                [
                    'uniqid',
                    'descr',
                ],
            ),
        );

        foreach ($virtualIps as $index => $virtualIp) {
            if (!is_array($virtualIp)) {
                continue;
            }

            $virtualIpMode = $virtualIp['mode'] ?? null;

            if ($virtualIpMode !== 'carp' && $virtualIpMode !== 'ipalias') {
                continue;
            }

            $virtualIpIdentity = $identities[$index];
            $virtualIpInterface = $virtualIp['interface'] ?? null;
            $targetId = 'virtual-ip:' . ($virtualIpIdentity ?? '#' . $index);

            self::addTarget($targets, [
                'group' => 'virtual_ips',
                'target_id' => $targetId,
                'field_id' => ($virtualIpIdentity === null) ? null : $targetId,
                'path' => sprintf('virtualip/vip/%s/subnet', $index),
                'value' => $virtualIp['subnet'] ?? null,
                'format' => 'single',
                'service' => 'interfaces',
                'interface' => is_string($virtualIpInterface) ? $virtualIpInterface : null,
                'record' => self::recordName($virtualIp, ['descr', 'uniqid']),
                'label' => 'field_address',
            ]);
        }
    }

    /**
     * Selects pfSense-owned WireGuard tunnel interface addresses.
     *
     * Peer allowed-IP and endpoint values are remote routing data and are never scanned.
     * Address masks live in sibling fields and remain unchanged by prefix replacement.
     *
     * @param array $configuration - Configuration snapshot.
     * @param array $targets       - Accumulated targets.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private static function scanWireGuard(array $configuration, array &$targets): void
    {
        $tunnels = self::getPath($configuration, 'installedpackages/wireguard/tunnels/item', []);

        if (!is_array($tunnels)) {
            return;
        }

        $identities = self::uniqueIdentities(
            $tunnels,
            static fn (array $tunnel): ?string => self::stableIdentity($tunnel, ['name', 'descr']),
        );

        foreach ($tunnels as $tunnelIndex => $tunnel) {
            if (!is_array($tunnel)) {
                continue;
            }

            $tunnelIdentity = $identities[$tunnelIndex];
            $tunnelTargetId = 'wireguard:' . ($tunnelIdentity ?? '#' . $tunnelIndex);
            $addresses = self::getPath($tunnel, 'addresses/row', []);

            if (!is_array($addresses)) {
                continue;
            }

            foreach ($addresses as $addressIndex => $address) {
                if (!is_array($address)) {
                    continue;
                }

                self::addTarget($targets, [
                    'group' => 'wireguard',
                    'target_id' => sprintf('%s:address-%s', $tunnelTargetId, $addressIndex),
                    'field_id' => ($tunnelIdentity === null) ? null : $tunnelTargetId . ':addresses',
                    'path' => sprintf(
                        'installedpackages/wireguard/tunnels/item/%s/addresses/row/%s/address',
                        $tunnelIndex,
                        $addressIndex,
                    ),
                    'value' => $address['address'] ?? null,
                    'format' => 'single',
                    'service' => 'wireguard',
                    'record' => self::recordName($tunnel, ['name', 'descr']),
                    'label' => 'field_tunnel_address',
                ]);
            }
        }
    }

    /**
     * Derives a stable record identity from reviewed schema fields.
     *
     * The first non-empty scalar identifier wins, and a field name may be a nested path
     * such as "created/time". Null means the record has no usable identifier.
     *
     * @param array        $record     - Configuration record.
     * @param list<string> $fieldNames - Candidate identity fields.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function stableIdentity(array $record, array $fieldNames): ?string
    {
        foreach ($fieldNames as $fieldName) {
            $value = self::getPath($record, $fieldName);

            if (is_string($value) && trim($value) !== '') {
                return rawurlencode(trim($value));
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Derives an identity for every record in one list and drops shared ones.
     *
     * A record whose identity is missing or shared with another record gets null, so it
     * is still planned by position but no exception can name it.
     *
     * @param array    $records    - Configuration records keyed by list position.
     * @param callable $identityOf - Returns one record's identity, or null.
     *
     * @return array<int|string, string|null>
     *
     * @since 2.0.0
     * @private
     */
    private static function uniqueIdentities(array $records, callable $identityOf): array
    {
        $identities = [];
        $identityCounts = [];

        foreach ($records as $index => $record) {
            $identity = is_array($record) ? $identityOf($record) : null;
            $identities[$index] = $identity;

            if ($identity !== null) {
                $identityCounts[$identity] = ($identityCounts[$identity] ?? 0) + 1;
            }
        }

        foreach ($identities as $index => $identity) {
            if ($identity !== null && $identityCounts[$identity] > 1) {
                $identities[$index] = null;
            }
        }

        return $identities;
    }
}

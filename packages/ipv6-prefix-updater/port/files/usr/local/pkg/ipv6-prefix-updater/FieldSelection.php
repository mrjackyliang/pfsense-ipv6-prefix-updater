<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

/**
 * Decides which configuration fields a run may update.
 *
 * Each field group has a switch, and single fields are exceptions to it: an excluded
 * field of an enabled group stays as it is, and an included field of a disabled group
 * is updated. Fields are named by stable identities, never by list position.
 *
 * @since 2.0.0
 */
final class FieldSelection
{
    /**
     * Recommended field groups in Fields tab order.
     *
     * @since 2.0.0
     */
    public const RECOMMENDED_GROUPS = [
        'interfaces',
        'virtual_ips',
        'aliases',
        'firewall_rules',
        'nat',
        'dhcpv6',
        'router_advertisements',
        'dns_host_overrides',
        'ipsec',
        'openvpn',
        'wireguard',
    ];

    /**
     * Group of every value that no other group covers, such as gateways and static
     * routes. Like another package's group, it starts off.
     *
     * @since 2.0.0
     */
    public const OTHER_GROUP = 'other';

    /**
     * Largest number of included or excluded fields kept.
     *
     * @since 2.0.0
     */
    public const MAX_FIELDS = 1000;

    /**
     * Group of each field identity kind, the text before its first colon.
     *
     * @since 2.0.0
     */
    private const FIELD_KINDS = [
        'alias' => 'aliases',
        'interface' => 'interfaces',
        'virtual-ip' => 'virtual_ips',
        'ipsec' => 'ipsec',
        'openvpn-server' => 'openvpn',
        'openvpn-client' => 'openvpn',
        'openvpn-override' => 'openvpn',
        'wireguard' => 'wireguard',
        'dhcpv6' => 'dhcpv6',
        'dhcpv6-pool' => 'dhcpv6',
        'dhcpv6-static-mapping' => 'dhcpv6',
        'router-advertisements' => 'router_advertisements',
        'dns-resolver-host' => 'dns_host_overrides',
        'dns-forwarder-host' => 'dns_host_overrides',
        'firewall-rule' => 'firewall_rules',
        'port-forward' => 'nat',
        'outbound-nat' => 'nat',
        'one-to-one-nat' => 'nat',
        'npt' => 'nat',
        'other' => self::OTHER_GROUP,
    ];

    /**
     * Syntax of a field identity: a kind followed by encoded identity parts.
     *
     * Parts are produced by rawurlencode, so a positional identity, which starts with
     * "#", can never pass and be stored.
     *
     * @since 2.0.0
     */
    private const FIELD_ID_PATTERN = '/^[a-z][a-z0-9-]*(?::[A-Za-z0-9%._~\/=-]*)+$/';

    /**
     * Syntax of a group that holds another package's settings.
     *
     * @since 2.0.0
     */
    private const PACKAGE_GROUP_PATTERN = '/^package:[a-z0-9_-]+$/';

    /**
     * Longest field identity accepted from package configuration.
     *
     * @since 2.0.0
     */
    private const MAX_FIELD_ID_LENGTH = 512;

    /**
     * Turns on every Recommended group and drops the exceptions inside them.
     *
     * Other Settings and other packages' groups keep their choices, as they do when
     * Auto-select is clicked on the Fields tab.
     *
     * @param array|null $selection - Current selection, or null for a new one.
     *
     * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     *
     * @since 2.0.0
     */
    public static function autoSelect(?array $selection = null): array
    {
        $selection ??= self::empty();

        foreach (self::RECOMMENDED_GROUPS as $group) {
            $selection['groups'][$group] = true;
        }

        $selection['include'] = self::withoutRecommended($selection['include']);
        $selection['exclude'] = self::withoutRecommended($selection['exclude']);

        return $selection;
    }

    /**
     * Applies the Fields tab form to a selection.
     *
     * Only groups and fields shown on the form change. A shown field that differs from
     * its group switch becomes an exception, and choices for fields not shown are kept.
     *
     * @param array               $selection     - Current selection.
     * @param array<string, bool> $groupSwitches - Group switches shown on the form.
     * @param array<string, string> $offeredFields - Shown field identities and their groups.
     * @param array               $checkedFields - Field identities posted as ticked.
     *
     * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     *
     * @since 2.0.0
     */
    public static function applyChoices(
        array $selection,
        array $groupSwitches,
        array $offeredFields,
        array $checkedFields,
    ): array {
        foreach ($groupSwitches as $group => $enabled) {
            if (self::isGroup($group) === false) {
                continue;
            }

            if ($enabled === false && in_array($group, self::RECOMMENDED_GROUPS, true) === false) {
                unset($selection['groups'][$group]);

                continue;
            }

            $selection['groups'][$group] = $enabled;
        }

        $checkedLookup = array_fill_keys(self::normalizeFieldIds($checkedFields), true);
        $include = array_values(array_filter(
            $selection['include'],
            static fn (string $includedField): bool => isset($offeredFields[$includedField]) === false,
        ));
        $exclude = array_values(array_filter(
            $selection['exclude'],
            static fn (string $excludedField): bool => isset($offeredFields[$excludedField]) === false,
        ));

        foreach ($offeredFields as $fieldId => $group) {
            $groupEnabled = self::groupEnabled($selection, $group);
            $checked = isset($checkedLookup[$fieldId]);

            if ($groupEnabled && $checked === false) {
                $exclude[] = $fieldId;
            } elseif ($groupEnabled === false && $checked) {
                $include[] = $fieldId;
            }
        }

        $selection['include'] = self::normalizeFieldIds($include);
        $selection['exclude'] = self::normalizeFieldIds($exclude);

        return $selection;
    }

    /**
     * Turns every group off and forgets every exception.
     *
     * Recommended groups are stored as off, so a later version can't treat them as
     * never chosen and select them again.
     *
     * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     *
     * @since 2.0.0
     */
    public static function clearAll(): array
    {
        $selection = self::empty();

        foreach (self::RECOMMENDED_GROUPS as $group) {
            $selection['groups'][$group] = false;
        }

        return $selection;
    }

    /**
     * Reads a selection saved by the Fields tab.
     *
     * Without a saved selection every group keeps its default, which is on for
     * Recommended groups.
     *
     * @param mixed $stored - Saved selection from package configuration.
     *
     * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     *
     * @since 2.0.0
     */
    public static function fromStorage(mixed $stored): array
    {
        $selection = self::empty();

        if (!is_array($stored)) {
            return $selection;
        }

        foreach (self::storedList($stored, 'enabled_groups') as $group) {
            if (self::isGroup($group)) {
                $selection['groups'][$group] = true;
            }
        }

        foreach (self::storedList($stored, 'disabled_groups') as $group) {
            if (self::isGroup($group)) {
                $selection['groups'][$group] = false;
            }
        }

        $selection['include'] = self::normalizeFieldIds(self::storedList($stored, 'include'));
        $selection['exclude'] = self::normalizeFieldIds(self::storedList($stored, 'exclude'));

        return $selection;
    }

    /**
     * Reports whether a group's switch is on.
     *
     * A Recommended group without a saved switch is on, and Other Settings and a group
     * holding another package's settings are off until the operator turns them on.
     *
     * @param array  $selection - Field selection.
     * @param string $group     - Field group.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public static function groupEnabled(array $selection, string $group): bool
    {
        return $selection['groups'][$group] ?? in_array($group, self::RECOMMENDED_GROUPS, true);
    }

    /**
     * Returns the group a field identity belongs to, or null for an unknown kind.
     *
     * @param string $fieldId - Field identity.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public static function groupOf(string $fieldId): ?string
    {
        $parts = explode(':', $fieldId, 3);

        if ($parts[0] === 'package') {
            $group = 'package:' . ($parts[1] ?? '');

            return (preg_match(self::PACKAGE_GROUP_PATTERN, $group) === 1) ? $group : null;
        }

        return self::FIELD_KINDS[$parts[0]] ?? null;
    }

    /**
     * Reports whether a run has anything to update.
     *
     * @param array $selection - Field selection.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public static function hasSelection(array $selection): bool
    {
        if (count($selection['include']) > 0) {
            return true;
        }

        foreach (self::RECOMMENDED_GROUPS as $group) {
            if (self::groupEnabled($selection, $group)) {
                return true;
            }
        }

        return in_array(true, $selection['groups'], true);
    }

    /**
     * Reports whether one field is updated by a run.
     *
     * A field without a stable identity follows its group switch only, because no
     * exception can name it.
     *
     * @param array       $selection - Field selection.
     * @param string      $group     - Field group.
     * @param string|null $fieldId   - Field identity, or null.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public static function isSelected(array $selection, string $group, ?string $fieldId): bool
    {
        if (self::groupEnabled($selection, $group)) {
            return $fieldId === null || in_array($fieldId, $selection['exclude'], true) === false;
        }

        return $fieldId !== null && in_array($fieldId, $selection['include'], true);
    }

    /**
     * Returns the included fields that no longer exist in the configuration.
     *
     * @param array        $selection - Field selection.
     * @param list<string> $fieldIds  - Identities of every field that exists.
     *
     * @return list<string>
     *
     * @since 2.0.0
     */
    public static function missingFields(array $selection, array $fieldIds): array
    {
        $existingLookup = array_fill_keys($fieldIds, true);

        return array_values(array_filter(
            $selection['include'],
            static fn (string $includedField): bool => isset($existingLookup[$includedField]) === false,
        ));
    }

    /**
     * Validates field identities read from configuration or a form.
     *
     * Identities are matched exactly against scanner output, so anything that isn't a
     * well-formed identity string is dropped instead of being interpreted.
     *
     * @param mixed $fieldIds - Candidate field identities.
     *
     * @return list<string>
     *
     * @since 2.0.0
     */
    public static function normalizeFieldIds(mixed $fieldIds): array
    {
        if (is_string($fieldIds)) {
            $fieldIds = [$fieldIds];
        }

        if (!is_array($fieldIds)) {
            return [];
        }

        $normalizedFieldIds = [];

        foreach ($fieldIds as $fieldId) {
            if (
                !is_string($fieldId)
                || strlen($fieldId) > self::MAX_FIELD_ID_LENGTH
                || preg_match(self::FIELD_ID_PATTERN, $fieldId) !== 1
                || self::groupOf($fieldId) === null
            ) {
                continue;
            }

            $normalizedFieldIds[$fieldId] = true;

            if (count($normalizedFieldIds) >= self::MAX_FIELDS) {
                break;
            }
        }

        return array_keys($normalizedFieldIds);
    }

    /**
     * Converts a selection to the shape saved in package configuration.
     *
     * The "schema" entry marks the selection as saved by the Fields tab, even an empty
     * one.
     *
     * @param array $selection - Field selection.
     *
     * @return array
     *
     * @since 2.0.0
     */
    public static function toStorage(array $selection): array
    {
        $stored = ['schema' => '1'];
        $enabledGroups = array_keys(array_filter(
            $selection['groups'],
            static fn (bool $enabled): bool => $enabled,
        ));
        $disabledGroups = array_keys(array_filter(
            $selection['groups'],
            static fn (bool $enabled): bool => $enabled === false,
        ));
        $lists = [
            'enabled_groups' => $enabledGroups,
            'disabled_groups' => $disabledGroups,
            'include' => $selection['include'],
            'exclude' => $selection['exclude'],
        ];

        foreach ($lists as $listName => $listItems) {
            if (count($listItems) > 0) {
                $stored[$listName] = ['item' => array_values($listItems)];
            }
        }

        return $stored;
    }

    /**
     * Returns a selection with no saved switches or exceptions.
     *
     * @return array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     *
     * @since 2.0.0
     * @private
     */
    private static function empty(): array
    {
        return [
            'groups' => [],
            'include' => [],
            'exclude' => [],
        ];
    }

    /**
     * Reports whether a group key names a Recommended group, Other Settings, or a
     * package section.
     *
     * @param mixed $group - Candidate group key.
     *
     * @return bool
     *
     * @since 2.0.0
     * @private
     */
    private static function isGroup(mixed $group): bool
    {
        return is_string($group)
            && (
                in_array($group, self::RECOMMENDED_GROUPS, true)
                || $group === self::OTHER_GROUP
                || preg_match(self::PACKAGE_GROUP_PATTERN, $group) === 1
            );
    }

    /**
     * Reads one saved list, which pfSense stores as "item" entries.
     *
     * @param array  $stored   - Saved selection.
     * @param string $listName - List name.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private static function storedList(array $stored, string $listName): array
    {
        $items = $stored[$listName]['item'] ?? [];

        if (is_string($items)) {
            return [$items];
        }

        return is_array($items) ? $items : [];
    }

    /**
     * Drops field identities that belong to Recommended groups.
     *
     * @param list<string> $fieldIds - Field identities.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private static function withoutRecommended(array $fieldIds): array
    {
        return array_values(array_filter(
            $fieldIds,
            static fn (string $fieldId): bool => in_array(self::groupOf($fieldId), self::RECOMMENDED_GROUPS, true) === false,
        ));
    }
}

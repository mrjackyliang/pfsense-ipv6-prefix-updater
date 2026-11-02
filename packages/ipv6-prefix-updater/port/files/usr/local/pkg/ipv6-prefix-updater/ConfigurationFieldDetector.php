<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

use InvalidArgumentException;

require_once(__DIR__ . '/ConfigurationChangePlanner.php');
require_once(__DIR__ . '/ConfigurationTargetScanner.php');
require_once(__DIR__ . '/FieldSelection.php');
require_once(__DIR__ . '/Ipv6Prefix.php');

/**
 * Lists every configuration value inside a monitored prefix for the Fields tab.
 *
 * Reviewed fields are grouped with their selection, other packages' fields form groups
 * of their own, and every other value is listed under Other Settings with its kind.
 *
 * @since 2.0.0
 */
final class ConfigurationFieldDetector
{
    /**
     * Longest run of plain text shown in full around highlighted addresses.
     *
     * @since 2.0.0
     */
    private const MAX_PLAIN_TEXT_LENGTH = 80;

    /**
     * Lists the fields inside each monitored prefix, grouped for the Fields tab.
     *
     * Every Recommended group is returned even when empty, so its switch can still be
     * set, and Other Settings comes last. Values outside the monitored prefixes are
     * never listed or changed.
     *
     * @param array       $configuration - Complete pfSense configuration snapshot.
     * @param list<array> $baselines     - Baselines with interface, prefix, and length.
     * @param array       $selection     - Field selection from FieldSelection.
     *
     * @return array{
     *     prefixes: list<array{prefix: string, prefix_length: int, interfaces: list<string>}>,
     *     groups: array<string, array>,
     *     missing: list<string>
     * }
     *
     * @since 2.0.0
     */
    public static function detect(array $configuration, array $baselines, array $selection): array
    {
        $prefixes = self::uniquePrefixes($baselines);
        $targets = ConfigurationTargetScanner::scanAll($configuration);
        $fieldIds = array_values(array_filter(
            array_column($targets, 'field_id'),
            static fn (?string $fieldId): bool => $fieldId !== null,
        ));
        $detected = [
            'prefixes' => $prefixes,
            'groups' => [],
            'missing' => FieldSelection::missingFields($selection, $fieldIds),
        ];

        foreach (FieldSelection::RECOMMENDED_GROUPS as $group) {
            $detected['groups'][$group] = self::emptyGroup($group, $selection);
        }

        foreach (array_keys($selection['groups']) as $group) {
            if (str_starts_with($group, 'package:') || $group === FieldSelection::OTHER_GROUP) {
                $detected['groups'][$group] ??= self::emptyGroup($group, $selection);
            }
        }

        $rows = [];

        foreach ((count($prefixes) > 0) ? $targets : [] as $target) {
            // Encoded values are matched and shown as their decoded text.
            $value = ($target['format'] === 'base64')
                ? (ConfigurationChangePlanner::decodeBase64($target['value']) ?? '')
                : $target['value'];
            $segments = self::valueSegments($value, ($target['format'] === 'base64') ? 'text' : $target['format'], $prefixes);

            if ($segments === null) {
                continue;
            }

            $group = $target['group'];
            $rowKey = $target['field_id'] ?? '#' . $target['path'];
            $reason = ($group === FieldSelection::OTHER_GROUP)
                ? ConfigurationTargetScanner::protectedReason(explode('/', $target['path']))
                : null;
            $hidden = in_array($reason, ['certificate', 'secret'], true);

            $detected['groups'][$group] ??= self::emptyGroup($group, $selection);

            if (isset($rows[$group][$rowKey])) {
                $rows[$group][$rowKey]['paths'][] = $target['path'];

                if ($rows[$group][$rowKey]['hidden'] === false) {
                    $rows[$group][$rowKey]['segments'] = [
                        ...$rows[$group][$rowKey]['segments'],
                        [
                            'text' => ', ',
                            'prefix' => false,
                        ],
                        ...$segments,
                    ];
                }

                continue;
            }

            $rows[$group][$rowKey] = [
                'field_id' => $target['field_id'],
                'group' => $group,
                'paths' => [$target['path']],
                'record' => $target['record'],
                'label' => $target['label'],
                'label_text' => $target['label_text'],
                'segments' => ($hidden) ? [] : $segments,
                'selected' => FieldSelection::isSelected($selection, $group, $target['field_id']),
                'kind' => $detected['groups'][$group]['kind'],
                'reason' => $reason,
                'hidden' => $hidden,
            ];
        }

        foreach ($rows as $group => $groupRows) {
            $detected['groups'][$group]['rows'] = array_values($groupRows);
            $detected['groups'][$group]['total'] = count($groupRows);
            $detected['groups'][$group]['selected'] = count(array_filter(
                $groupRows,
                static fn (array $groupRow): bool => $groupRow['selected'],
            ));
        }

        if (isset($detected['groups'][FieldSelection::OTHER_GROUP])) {
            $otherGroup = $detected['groups'][FieldSelection::OTHER_GROUP];

            unset($detected['groups'][FieldSelection::OTHER_GROUP]);
            $detected['groups'][FieldSelection::OTHER_GROUP] = $otherGroup;
        }

        return $detected;
    }

    /**
     * Splits one address into the bits covered by the prefix and the host bits.
     *
     * The two parts are written so that, joined, they spell the same address, and a
     * prefix length that ends inside a hexadecimal digit highlights that whole digit.
     *
     * @param string $token        - Address with an optional CIDR suffix.
     * @param int    $prefixLength - Prefix length.
     *
     * @return list<array{text: string, prefix: bool}>
     *
     * @since 2.0.0
     */
    public static function addressSegments(string $token, int $prefixLength): array
    {
        $slashPosition = strrpos($token, '/');
        $address = ($slashPosition === false) ? $token : substr($token, 0, $slashPosition);
        $suffix = ($slashPosition === false) ? '' : substr($token, $slashPosition);
        $binary = @inet_pton($address);

        if (!is_string($binary) || strlen($binary) !== 16) {
            return [
                [
                    'text' => $token,
                    'prefix' => false,
                ],
            ];
        }

        $hextets = str_split(bin2hex($binary), 4);
        $fullHextets = intdiv($prefixLength, 16);
        $partialDigits = intdiv(($prefixLength % 16) + 3, 4);
        $trimHextet = static fn (string $hextet): string => (ltrim($hextet, '0') === '') ? '0' : ltrim($hextet, '0');
        $prefixText = implode(':', array_map($trimHextet, array_slice($hextets, 0, $fullHextets)));
        $hostText = '';
        $tail = array_map($trimHextet, array_slice($hextets, $fullHextets + (($partialDigits > 0) ? 1 : 0)));

        if ($partialDigits > 0) {
            $prefixText .= ':' . substr($hextets[$fullHextets], 0, $partialDigits);
            $hostText = substr($hextets[$fullHextets], $partialDigits);
        }

        return [
            [
                'text' => $prefixText,
                'prefix' => true,
            ],
            [
                'text' => $hostText . self::compressTail($tail) . $suffix,
                'prefix' => false,
            ],
        ];
    }

    /**
     * Writes the hextets after the prefix, compressing their longest run of zeros.
     *
     * The text starts with the colon that separates it from the prefix part.
     *
     * @param list<string> $tail - Trimmed hextets after the prefix.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private static function compressTail(array $tail): string
    {
        $bestStart = -1;
        $bestLength = 0;
        $runStart = -1;

        foreach ([...$tail, 'end'] as $index => $hextet) {
            if ($hextet === '0') {
                $runStart = ($runStart === -1) ? $index : $runStart;

                continue;
            }

            if ($runStart !== -1 && $index - $runStart > $bestLength) {
                $bestStart = $runStart;
                $bestLength = $index - $runStart;
            }

            $runStart = -1;
        }

        if ($bestLength < 2) {
            return (count($tail) === 0) ? '' : ':' . implode(':', $tail);
        }

        $left = array_slice($tail, 0, $bestStart);
        $right = array_slice($tail, $bestStart + $bestLength);

        return ((count($left) > 0) ? ':' . implode(':', $left) : '') . '::' . implode(':', $right);
    }

    /**
     * Returns an empty Fields tab group with its switch state.
     *
     * Its kind is "recommended", "other" for Other Settings, or "needs_review" for
     * another package's settings.
     *
     * @param string $group     - Field group.
     * @param array  $selection - Field selection.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private static function emptyGroup(string $group, array $selection): array
    {
        $kind = 'needs_review';

        if (in_array($group, FieldSelection::RECOMMENDED_GROUPS, true)) {
            $kind = 'recommended';
        } elseif ($group === FieldSelection::OTHER_GROUP) {
            $kind = 'other';
        }

        return [
            'key' => $group,
            'kind' => $kind,
            'enabled' => FieldSelection::groupEnabled($selection, $group),
            'rows' => [],
            'selected' => 0,
            'total' => 0,
        ];
    }

    /**
     * Shortens long plain text between highlighted addresses.
     *
     * @param list<array{text: string, prefix: bool}> $segments - Value segments.
     *
     * @return list<array{text: string, prefix: bool}>
     *
     * @since 2.0.0
     * @private
     */
    private static function shortenSegments(array $segments): array
    {
        return array_map(
            static function (array $segment): array {
                if ($segment['prefix'] || strlen($segment['text']) <= self::MAX_PLAIN_TEXT_LENGTH) {
                    return $segment;
                }

                $segment['text'] = substr($segment['text'], 0, 30) . ' … ' . substr($segment['text'], -30);

                return $segment;
            },
            $segments,
        );
    }

    /**
     * Collapses baselines that share one prefix and drops unusable ones.
     *
     * Interfaces of one delegation share a prefix, so each prefix is checked once and
     * remembers every interface it belongs to.
     *
     * @param list<array> $baselines - Baselines with interface, prefix, and length.
     *
     * @return list<array{prefix: string, prefix_length: int, interfaces: list<string>}>
     *
     * @since 2.0.0
     * @private
     */
    private static function uniquePrefixes(array $baselines): array
    {
        $prefixes = [];

        foreach ($baselines as $baseline) {
            $interface = $baseline['interface'] ?? null;
            $prefix = $baseline['prefix'] ?? null;
            $prefixLength = $baseline['prefix_length'] ?? null;

            if (!is_string($interface) || !is_string($prefix) || !is_int($prefixLength)) {
                continue;
            }

            try {
                $network = Ipv6Prefix::networkAddress($prefix, $prefixLength);
            } catch (InvalidArgumentException) {
                continue;
            }

            $prefixKey = $network . '/' . $prefixLength;

            $prefixes[$prefixKey] ??= [
                'prefix' => $network,
                'prefix_length' => $prefixLength,
                'interfaces' => [],
            ];
            $prefixes[$prefixKey]['interfaces'][] = $interface;
        }

        return array_values($prefixes);
    }

    /**
     * Splits a value into plain text and highlighted addresses inside the prefixes.
     *
     * Matching uses the planner's own rules for the value's format, so a highlighted
     * address is exactly one a prefix change would rewrite. Null means none matched.
     *
     * @param string $value    - Field value.
     * @param string $format   - Field format.
     * @param array  $prefixes - Unique monitored prefixes.
     *
     * @return list<array{text: string, prefix: bool}>|null
     *
     * @since 2.0.0
     * @private
     */
    private static function valueSegments(string $value, string $format, array $prefixes): ?array
    {
        $tokenLengths = [];

        foreach ($prefixes as $prefix) {
            $matchingTokens = ConfigurationChangePlanner::matchingTokens(
                $value,
                $format,
                $prefix['prefix'],
                $prefix['prefix_length'],
            );

            foreach ($matchingTokens as $matchingToken) {
                $tokenLengths[$matchingToken] ??= $prefix['prefix_length'];
            }
        }

        if (count($tokenLengths) === 0) {
            return null;
        }

        $tokens = array_keys($tokenLengths);

        usort($tokens, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $pattern = '/(?<![0-9A-Fa-f])(' . implode('|', array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            $tokens,
        )) . ')(?![0-9A-Fa-f])/';
        $pieces = preg_split($pattern, $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = [];

        foreach (is_array($pieces) ? $pieces : [$value] as $piece) {
            if ($piece === '') {
                continue;
            }

            if (isset($tokenLengths[$piece])) {
                array_push($segments, ...self::addressSegments($piece, $tokenLengths[$piece]));

                continue;
            }

            $segments[] = [
                'text' => $piece,
                'prefix' => false,
            ];
        }

        return self::shortenSegments($segments);
    }
}

<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

use InvalidArgumentException;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once(__DIR__ . '/Ipv6Prefix.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Builds an immutable plan for approved IPv6 configuration targets.
 *
 * Adapters remain responsible for selecting approved pfSense fields. This planner
 * validates those target records, ignores unrelated global prefixes, and never mutates
 * the supplied records or firewall configuration.
 *
 * @since 2.0.0
 */
final class PrefixChangePlanner
{
    /**
     * Plans address changes between the last successful and active prefixes.
     *
     * A prefix-length difference is a transition even when both canonical network
     * strings match. No-op transitions return before inspecting targets, allowing the
     * executor to avoid reading configuration when the active state is unchanged.
     *
     * @param string                                                        $previousSourceAddress Last successful source address.
     * @param int                                                           $previousPrefixLength  Last successful prefix length.
     * @param string                                                        $currentSourceAddress  Active source address.
     * @param int                                                           $currentPrefixLength   Active prefix length.
     * @param list<array{target_id: string, path: string, address: string}> $targets               Approved target records.
     *
     * @return array{
     *     previous_prefix: string,
     *     previous_prefix_length: int,
     *     current_prefix: string,
     *     current_prefix_length: int,
     *     prefix_changed: bool,
     *     changes: list<array{target_id: string, path: string, before: string, after: string}>
     * } Complete, non-mutating change plan.
     *
     * @throws InvalidArgumentException When state or target input is malformed or unsuitable.
     *
     * @since 2.0.0
     */
    public static function plan(
        string $previousSourceAddress,
        int $previousPrefixLength,
        string $currentSourceAddress,
        int $currentPrefixLength,
        array $targets,
    ): array
    {
        $previousPrefix = Ipv6Prefix::networkAddress($previousSourceAddress, $previousPrefixLength);
        $currentPrefix = Ipv6Prefix::networkAddress($currentSourceAddress, $currentPrefixLength);
        $prefixChanged = $previousPrefix !== $currentPrefix
            || $previousPrefixLength !== $currentPrefixLength;

        $plan = [
            'previous_prefix' => $previousPrefix,
            'previous_prefix_length' => $previousPrefixLength,
            'current_prefix' => $currentPrefix,
            'current_prefix_length' => $currentPrefixLength,
            'prefix_changed' => $prefixChanged,
            'changes' => [],
        ];

        if ($prefixChanged === false) {
            return $plan;
        }

        $seenTargetIds = [];
        $seenPaths = [];

        foreach ($targets as $index => $target) {
            $normalizedTarget = self::normalizeTarget($target, $index);
            $targetId = $normalizedTarget['target_id'];
            $path = $normalizedTarget['path'];

            if (isset($seenTargetIds[$targetId])) {
                throw new InvalidArgumentException(Translator::translate('target_id_duplicated', [$targetId]));
            }

            if (isset($seenPaths[$path])) {
                throw new InvalidArgumentException(Translator::translate('target_path_duplicated', [$path]));
            }

            $seenTargetIds[$targetId] = true;
            $seenPaths[$path] = true;

            if (Ipv6Prefix::contains(
                $previousPrefix,
                $normalizedTarget['address'],
                $previousPrefixLength,
            ) === false) {
                continue;
            }

            $before = Ipv6Prefix::canonicalize($normalizedTarget['address']);
            $after = Ipv6Prefix::replacePrefix(
                $currentSourceAddress,
                $normalizedTarget['address'],
                $currentPrefixLength,
            );

            if ($before === $after) {
                continue;
            }

            $plan['changes'][] = [
                'target_id' => $targetId,
                'path' => $path,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $plan;
    }

    /**
     * Validates and normalizes one adapter-provided target record.
     *
     * Stable identifiers and explicit configuration paths are mandatory so a later
     * mutation adapter can apply every planned value without relying on list position.
     *
     * @param mixed $target Adapter-provided target value.
     * @param int   $index  Position used only for validation diagnostics.
     *
     * @return array{target_id: string, path: string, address: string} Normalized target.
     *
     * @throws InvalidArgumentException When the target record is incomplete or malformed.
     *
     * @since 2.0.0
     */
    private static function normalizeTarget(mixed $target, int $index): array
    {
        if (!is_array($target)) {
            throw new InvalidArgumentException(Translator::translate('target_array_required', [$index]));
        }

        $targetId = $target['target_id'] ?? null;
        $path = $target['path'] ?? null;
        $address = $target['address'] ?? null;

        if (!is_string($targetId) || trim($targetId) === '') {
            throw new InvalidArgumentException(Translator::translate('target_id_required', [$index]));
        }

        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(Translator::translate('target_path_required', [$index]));
        }

        if (!is_string($address) || trim($address) === '') {
            throw new InvalidArgumentException(Translator::translate('target_ipv6_required', [$index]));
        }

        return [
            'target_id' => trim($targetId),
            'path' => trim($path),
            'address' => trim($address),
        ];
    }
}

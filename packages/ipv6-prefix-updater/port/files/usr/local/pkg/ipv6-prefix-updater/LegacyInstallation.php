<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

use InvalidArgumentException;

require_once(__DIR__ . '/Ipv6Prefix.php');

/**
 * Reads the traces left by the version 1.x script without touching the firewall.
 *
 * Version 1.x ran "update.php <device> <length>" from a Cron entry and saved the last
 * applied prefix as truncated hexadecimal groups. These pure helpers turn both into
 * package values so migration decisions stay testable away from pfSense.
 *
 * @since 2.0.0
 */
final class LegacyInstallation
{
    /**
     * Path fragment present in every version 1.x Cron command.
     *
     * @since 2.0.0
     */
    public const SCRIPT_MARKER = 'pfsense-ipv6-prefix-updater/update.php';

    /**
     * Reports whether a Cron command runs the version 1.x script.
     *
     * @param string $command - Command.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public static function isLegacyCommand(string $command): bool
    {
        return str_contains($command, self::SCRIPT_MARKER);
    }

    /**
     * Extracts the device and prefix length passed to the version 1.x script.
     *
     * The two arguments follow "update.php" in any quoting the shell accepts. Values the
     * script itself would have rejected return null, so the caller only removes them.
     *
     * @param string $command - Command.
     *
     * @return array{device: string, prefix_length: int}|null
     *
     * @since 2.0.0
     */
    public static function parseCommand(string $command): ?array
    {
        if (self::isLegacyCommand($command) === false) {
            return null;
        }

        $pattern = '#' . preg_quote(self::SCRIPT_MARKER, '#') . '["\']?\s+["\']?([0-9A-Za-z._]+)["\']?\s+["\']?([0-9]{2})["\']?(?=\s|;|&|\||>|$)#';

        if (preg_match($pattern, $command, $matches) !== 1) {
            return null;
        }

        $prefixLength = (int) $matches[2];

        if ($prefixLength < Ipv6Prefix::MIN_PREFIX_LENGTH || $prefixLength > Ipv6Prefix::MAX_PREFIX_LENGTH) {
            return null;
        }

        return [
            'device' => strtolower($matches[1]),
            'prefix_length' => $prefixLength,
        ];
    }

    /**
     * Converts a version 1.x cache file into a canonical network prefix.
     *
     * The script stored "<groups>||<length>", where the groups are the leading
     * hexadecimal blocks and a partial block holds only the remaining prefix bits.
     *
     * @param string $contents - Contents.
     *
     * @return array{prefix: string, prefix_length: int}|null
     *
     * @since 2.0.0
     */
    public static function parseCache(string $contents): ?array
    {
        if (preg_match('/^\s*([0-9a-fA-F:]+)\|\|([0-9]{2})\s*$/', $contents, $matches) !== 1) {
            return null;
        }

        $prefixLength = (int) $matches[2];

        if ($prefixLength < Ipv6Prefix::MIN_PREFIX_LENGTH || $prefixLength > Ipv6Prefix::MAX_PREFIX_LENGTH) {
            return null;
        }

        $segments = explode(':', strtolower($matches[1]));
        $fullBlocks = intdiv($prefixLength, 16);
        $remainingBits = $prefixLength % 16;
        $expectedSegments = $fullBlocks + (($remainingBits > 0) ? 1 : 0);

        if (count($segments) !== $expectedSegments) {
            return null;
        }

        $groups = array_fill(0, 8, 0);

        foreach ($segments as $index => $segment) {
            if (preg_match('/^[0-9a-f]{1,4}$/', $segment) !== 1) {
                return null;
            }

            $value = (int) hexdec($segment);

            if ($index === $fullBlocks) {
                if ($value >= (1 << $remainingBits)) {
                    return null;
                }

                $value <<= (16 - $remainingBits);
            }

            $groups[$index] = $value;
        }

        $address = implode(':', array_map(
            static fn (int $group): string => dechex($group),
            $groups,
        ));

        try {
            return [
                'prefix' => Ipv6Prefix::networkAddress($address, $prefixLength),
                'prefix_length' => $prefixLength,
            ];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Picks the package schedule that checks at least as often as a Cron entry did.
     *
     * Runs per day are estimated from the five Cron fields, so a legacy entry that ran
     * every minute maps to the package's most frequent choice of fifteen minutes.
     *
     * @param string $minute     - Minute.
     * @param string $hour       - Hour.
     * @param string $dayOfMonth - Day of month.
     * @param string $month      - Month.
     * @param string $dayOfWeek  - Day of week.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public static function scheduleFor(
        string $minute,
        string $hour,
        string $dayOfMonth,
        string $month,
        string $dayOfWeek,
    ): string {
        $runsPerDay = self::countValues($minute, 0, 59) * self::countValues($hour, 0, 23);
        $dayFields = [
            [$dayOfMonth, 1, 31],
            [$month, 1, 12],
            [$dayOfWeek, 0, 6],
        ];

        foreach ($dayFields as [$field, $minimum, $maximum]) {
            if (trim($field) !== '*') {
                $runsPerDay *= self::countValues($field, $minimum, $maximum) / ($maximum - $minimum + 1);
            }
        }

        return match (true) {
            $runsPerDay > 24 => 'every_fifteen_minutes',
            $runsPerDay > 4 => 'hourly',
            $runsPerDay > 1 => 'every_six_hours',
            default => 'daily',
        };
    }

    /**
     * Counts the values one Cron field selects.
     *
     * Lists, ranges, steps, and wildcards are supported. Names and malformed parts count
     * as one value, which only makes the chosen schedule more frequent.
     *
     * @param string $field   - Field.
     * @param int    $minimum - Minimum.
     * @param int    $maximum - Maximum.
     *
     * @return int
     *
     * @since 2.0.0
     * @private
     */
    private static function countValues(string $field, int $minimum, int $maximum): int
    {
        $selected = [];

        foreach (explode(',', trim($field)) as $part) {
            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
            }

            if ($part === '*' || $part === '') {
                $start = $minimum;
                $end = $maximum;
            } elseif (preg_match('/^([0-9]+)-([0-9]+)$/', $part, $matches) === 1) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];
            } elseif (ctype_digit($part)) {
                $start = (int) $part;
                $end = ($step > 1) ? $maximum : $start;
            } else {
                $selected[] = $part;
                continue;
            }

            for ($value = max($minimum, $start); $value <= min($maximum, $end); $value += $step) {
                $selected[] = $value;
            }
        }

        return max(1, count(array_unique($selected)));
    }
}

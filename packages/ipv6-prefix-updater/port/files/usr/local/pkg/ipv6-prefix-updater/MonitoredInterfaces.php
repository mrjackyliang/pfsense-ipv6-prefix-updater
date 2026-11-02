<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

/**
 * Reads the monitored-interface rows from saved settings or a submitted form.
 *
 * Saved settings hold a "row" list written by pfSense's repeatable-row field. Both
 * sources return the same unvalidated list so callers apply one set of checks.
 *
 * @since 2.0.0
 */
final class MonitoredInterfaces
{
    /**
     * Returns monitored-interface rows from saved package settings.
     *
     * @param array $settings - Saved package settings.
     *
     * @return list<array{interface: mixed, prefix_length: mixed}>
     *
     * @since 2.0.0
     */
    public static function fromSettings(array $settings): array
    {
        $rows = $settings['row'] ?? null;

        if (is_array($rows) && array_key_exists('interface', $rows)) {
            $rows = [$rows];
        }

        if (is_array($rows) && count($rows) > 0) {
            $result = [];

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $result[] = [
                        'interface' => $row['interface'] ?? null,
                        'prefix_length' => $row['prefix_length'] ?? null,
                    ];
                }
            }

            return $result;
        }

        return [];
    }

    /**
     * Returns monitored-interface rows from the package settings form.
     *
     * pfSense posts each repeatable row as "interface<N>" and "prefix_length<N>".
     * Rows keep their on-screen order even when earlier rows were deleted.
     *
     * @param array $post - Submitted form values.
     *
     * @return list<array{interface: mixed, prefix_length: mixed}>
     *
     * @since 2.0.0
     */
    public static function fromPost(array $post): array
    {
        $rows = [];

        foreach ($post as $key => $value) {
            if (preg_match('/^(interface|prefix_length)([0-9]+)$/', (string) $key, $matches) === 1) {
                $rows[(int) $matches[2]][$matches[1]] = $value;
            }
        }

        ksort($rows);

        return array_values(array_map(
            static fn (array $row): array => [
                'interface' => $row['interface'] ?? null,
                'prefix_length' => $row['prefix_length'] ?? null,
            ],
            $rows,
        ));
    }
}

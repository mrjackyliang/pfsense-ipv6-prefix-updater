<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * pfSense releases covered by the package's release verification.
 *
 * @since 2.1.0
 */
final class TestedReleases
{
    /**
     * Tested releases in newest-first order.
     *
     * @var list<array{edition: 'CE'|'Plus', version: string, freebsd: string}>
     *
     * @since 2.1.0
     */
    public const RELEASES = [
        [
            'edition' => 'CE',
            'version' => '2.9.1',
            'freebsd' => '16.0',
        ],
        [
            'edition' => 'CE',
            'version' => '2.9.0',
            'freebsd' => '16.0',
        ],
        [
            'edition' => 'CE',
            'version' => '2.8.1',
            'freebsd' => '15.0',
        ],
        [
            'edition' => 'Plus',
            'version' => '26.07.1',
            'freebsd' => '16.0',
        ],
        [
            'edition' => 'Plus',
            'version' => '26.07',
            'freebsd' => '16.0',
        ],
        [
            'edition' => 'Plus',
            'version' => '26.03.1',
            'freebsd' => '16.0',
        ],
    ];
}

<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Domain\Ipv6Prefix;
use PfSense\Ipv6PrefixUpdater\Domain\PrefixChangePlanner;

require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/Ipv6Prefix.php');
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/PrefixChangePlanner.php');

/**
 * Asserts that two values are strictly identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure context.
 *
 * @throws RuntimeException When the values are not strictly identical.
 *
 * @since 2.0.0
 */
function assertSameValue(mixed $expected, mixed $actual, string $message): void
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
 * Asserts that a callback throws the expected exception type.
 *
 * @param callable                $callback      Callback under test.
 * @param class-string<Throwable> $expectedClass Expected exception class.
 * @param string                  $message       Failure context.
 *
 * @throws RuntimeException When no exception or an unexpected exception is thrown.
 *
 * @since 2.0.0
 */
function assertThrows(callable $callback, string $expectedClass, string $message): void
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

/** @var array<string, Closure(): void> $tests Domain test cases. */
$tests = [
    'canonicalizes equivalent IPv6 text' => static function (): void {
        assertSameValue(
            '2001:db8::1',
            Ipv6Prefix::canonicalize('2001:0DB8:0000:0000:0000:0000:0000:0001'),
            'Expanded uppercase IPv6 text should use canonical lowercase compression.',
        );

        assertThrows(
            static function (): void {
                Ipv6Prefix::canonicalize('not-an-address');
            },
            InvalidArgumentException::class,
            'Malformed input must be rejected.',
        );
    },
    'enforces prefix-length boundaries' => static function (): void {
        assertSameValue(
            '2001:db8:abcd::',
            Ipv6Prefix::networkAddress('2001:db8:abcd:ffff::1', 48),
            'A /48 should clear every bit after the third hextet.',
        );
        assertSameValue(
            '2001:db8:abcd:1234::',
            Ipv6Prefix::networkAddress('2001:db8:abcd:1234:ffff::1', 64),
            'A /64 should clear every bit after the fourth hextet.',
        );

        foreach ([47, 65] as $invalidPrefixLength) {
            assertThrows(
                static function () use ($invalidPrefixLength): void {
                    Ipv6Prefix::networkAddress('2001:db8::1', $invalidPrefixLength);
                },
                InvalidArgumentException::class,
                sprintf('Prefix length %d must be rejected.', $invalidPrefixLength),
            );
        }
    },
    'derives the documented lab prefix from the exact source address' => static function (): void {
        assertSameValue(
            '2001:db8:abcd:7800::',
            Ipv6Prefix::networkAddress('2001:db8:abcd:78aa::1', 56),
            'The lab source address must be masked at the configured /56 bit boundary.',
        );
        assertSameValue(
            '2001:db8:abcd:78aa::1',
            Ipv6Prefix::canonicalize('2001:0DB8:ABCD:78AA:0000:0000:0000:0001'),
            'The displayed source address must be the canonical form of the selected interface address.',
        );
    },
    'preserves host bits at a non-byte boundary' => static function (): void {
        assertSameValue(
            '2001:db8:1234:afff:89ab:cdef:123:4567',
            Ipv6Prefix::replacePrefix(
                '2001:db8:1234:abcd::1',
                '2001:db8:ffff:ffff:89ab:cdef:0123:4567',
                53,
            ),
            'A /53 replacement must retain the configured lower 11 bits of the boundary hextet.',
        );
    },
    'rejects invalid and unsuitable global addresses' => static function (): void {
        $unsuitableAddresses = [
            'fe80::1',
            '::1',
            '::',
            'ff02::1',
            'fd00::1',
            '::ffff:192.0.2.1',
            '192.0.2.1',
        ];

        foreach ($unsuitableAddresses as $unsuitableAddress) {
            assertThrows(
                static function () use ($unsuitableAddress): void {
                    Ipv6Prefix::networkAddress($unsuitableAddress, 56);
                },
                InvalidArgumentException::class,
                sprintf('Unsuitable source address %s must be rejected.', $unsuitableAddress),
            );
        }

        assertThrows(
            static function (): void {
                Ipv6Prefix::replacePrefix('2001:db8:aaaa::1', 'fe80::1', 56);
            },
            InvalidArgumentException::class,
            'A non-global configured target must be rejected.',
        );
    },
    'returns an early no-op for an equivalent prefix state' => static function (): void {
        $plan = PrefixChangePlanner::plan(
            '2001:0DB8:1111:2200:0000:0000:0000:0001',
            56,
            '2001:db8:1111:22ff::beef',
            56,
            [],
        );

        assertSameValue(false, $plan['prefix_changed'], 'Equivalent canonical prefixes should be a no-op.');
        assertSameValue([], $plan['changes'], 'A no-op prefix state must not contain address changes.');
    },
    'plans only targets belonging to the previous prefix' => static function (): void {
        $plan = PrefixChangePlanner::plan(
            '2001:db8:1111:2200::1',
            56,
            '2001:db8:aaaa:bb00::9',
            56,
            [
                [
                    'target_id' => 'alias:web-v6',
                    'path' => 'aliases.alias.0.address',
                    'address' => '2001:0DB8:1111:22CC:1234:5678:9ABC:DEF0',
                ],
                [
                    'target_id' => 'alias:unrelated-v6',
                    'path' => 'aliases.alias.1.address',
                    'address' => '2001:db8:9999:22cc::1',
                ],
            ],
        );

        assertSameValue(true, $plan['prefix_changed'], 'Different canonical prefixes should create a transition.');
        assertSameValue(
            [
                [
                    'target_id' => 'alias:web-v6',
                    'path' => 'aliases.alias.0.address',
                    'before' => '2001:db8:1111:22cc:1234:5678:9abc:def0',
                    'after' => '2001:db8:aaaa:bbcc:1234:5678:9abc:def0',
                ],
            ],
            $plan['changes'],
            'The plan should preserve host bits and skip unrelated global addresses.',
        );
    },
    'treats a prefix-length difference as a state transition' => static function (): void {
        $plan = PrefixChangePlanner::plan(
            '2001:db8:1234:ab00::1',
            56,
            '2001:db8:1234:ab00::1',
            64,
            [
                [
                    'target_id' => 'virtual-ip:service',
                    'path' => 'virtualip.vip.0.subnet',
                    'address' => '2001:db8:1234:abcc::42',
                ],
            ],
        );

        assertSameValue(true, $plan['prefix_changed'], 'A prefix-length change must never be treated as a no-op.');
        assertSameValue(
            '2001:db8:1234:ab00::42',
            $plan['changes'][0]['after'] ?? null,
            'The current prefix length should define the new host boundary.',
        );
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
    fwrite(STDERR, sprintf("%d domain test(s) failed.\n", $failureCount));

    exit(1);
}

fwrite(STDOUT, sprintf("%d domain tests passed.\n", count($tests)));

exit(0);

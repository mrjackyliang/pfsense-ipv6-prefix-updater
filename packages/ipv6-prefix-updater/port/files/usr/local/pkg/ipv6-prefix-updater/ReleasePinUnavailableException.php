<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;

/**
 * ReleasePinUnavailableException.
 *
 * Identifies the definitive absence of a checksum-verifiable release pin.
 * Restore synchronization removes obsolete entries only for this result.
 *
 * @since 2.1.2
 */
final class ReleasePinUnavailableException extends RuntimeException
{
}

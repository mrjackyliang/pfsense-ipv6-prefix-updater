<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

use InvalidArgumentException;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;
use RuntimeException;

require_once(__DIR__ . '/Translator.php');

/**
 * Performs binary IPv6 prefix operations.
 *
 * Address parsing and prefix replacement stay independent from pfSense so callers can
 * validate a complete configuration change plan before using any firewall APIs.
 *
 * @since 2.0.0
 */
final class Ipv6Prefix
{
    /**
     * Lowest delegated prefix length accepted by the package.
     *
     * @since 2.0.0
     */
    public const MIN_PREFIX_LENGTH = 48;

    /**
     * Highest delegated prefix length accepted by the package.
     *
     * @since 2.0.0
     */
    public const MAX_PREFIX_LENGTH = 64;

    /**
     * Returns the canonical text representation of an IPv6 address.
     *
     * The conversion round-trips through the 16-byte binary form so comparisons do not
     * depend on capitalization, zero padding, or abbreviation choices.
     *
     * @param string $address IPv6 address to canonicalize.
     *
     * @throws InvalidArgumentException When the value is not an IPv6 address.
     *
     * @since 2.0.0
     */
    public static function canonicalize(string $address): string
    {
        return self::format(self::parse($address));
    }

    /**
     * Returns the canonical network address for a suitable global IPv6 source.
     *
     * Host bits are cleared with a binary mask after the package prefix-length bounds
     * and the source-address scope have been validated.
     *
     * @param string $sourceAddress Global IPv6 address that carries the active prefix.
     * @param int    $prefixLength  Delegated prefix length from 48 through 64.
     *
     * @throws InvalidArgumentException When the address or prefix length is unsuitable.
     *
     * @since 2.0.0
     */
    public static function networkAddress(string $sourceAddress, int $prefixLength): string
    {
        self::validatePrefixLength($prefixLength);

        $sourceBinary = self::parseGlobalUnicast($sourceAddress);
        $zeroHost = str_repeat("\0", 16);

        return self::format(self::combine($sourceBinary, $zeroHost, $prefixLength));
    }

    /**
     * Replaces an address prefix while preserving every host bit exactly.
     *
     * Prefix bits come from the active global source and all remaining bits come from
     * the configured global address, including at non-byte-aligned prefix lengths.
     *
     * @param string $sourceAddress     Global IPv6 address that carries the new prefix.
     * @param string $configuredAddress Approved configured global IPv6 address.
     * @param int    $prefixLength      Delegated prefix length from 48 through 64.
     *
     * @throws InvalidArgumentException When an address or prefix length is unsuitable.
     *
     * @since 2.0.0
     */
    public static function replacePrefix(
        string $sourceAddress,
        string $configuredAddress,
        int $prefixLength,
    ): string
    {
        self::validatePrefixLength($prefixLength);

        $sourceBinary = self::parseGlobalUnicast($sourceAddress);
        $configuredBinary = self::parseGlobalUnicast($configuredAddress);

        return self::format(self::combine($sourceBinary, $configuredBinary, $prefixLength));
    }

    /**
     * Reports whether a global IPv6 address belongs to a supplied network prefix.
     *
     * Both values are masked in binary form so equivalent textual representations and
     * non-byte-aligned prefix lengths produce the same result.
     *
     * @param string $prefixAddress    Global IPv6 address that identifies the network.
     * @param string $candidateAddress Global IPv6 address to test.
     * @param int    $prefixLength     Delegated prefix length from 48 through 64.
     *
     * @throws InvalidArgumentException When an address or prefix length is unsuitable.
     *
     * @since 2.0.0
     */
    public static function contains(
        string $prefixAddress,
        string $candidateAddress,
        int $prefixLength,
    ): bool
    {
        self::validatePrefixLength($prefixLength);

        $prefixBinary = self::parseGlobalUnicast($prefixAddress);
        $candidateBinary = self::parseGlobalUnicast($candidateAddress);
        $zeroHost = str_repeat("\0", 16);

        return self::combine($prefixBinary, $zeroHost, $prefixLength)
            === self::combine($candidateBinary, $zeroHost, $prefixLength);
    }

    /**
     * Combines prefix bytes with configured host bytes.
     *
     * The boundary byte is assembled with complementary masks so neither the source
     * host bits nor the configured prefix bits can leak into the result.
     *
     * @param string $sourceBinary     Parsed source address.
     * @param string $configuredBinary Parsed configured address or zero-filled host.
     * @param int    $prefixLength     Validated prefix length.
     *
     * @since 2.0.0
     */
    private static function combine(string $sourceBinary, string $configuredBinary, int $prefixLength): string
    {
        $combined = '';

        for ($index = 0; $index < 16; $index += 1) {
            $prefixBits = $prefixLength - ($index * 8);

            if ($prefixBits >= 8) {
                $combined .= $sourceBinary[$index];

                continue;
            }

            if ($prefixBits <= 0) {
                $combined .= $configuredBinary[$index];

                continue;
            }

            $prefixMask = (0xff << (8 - $prefixBits)) & 0xff;
            $hostMask = ~$prefixMask & 0xff;
            $combined .= chr(
                (ord($sourceBinary[$index]) & $prefixMask)
                | (ord($configuredBinary[$index]) & $hostMask),
            );
        }

        return $combined;
    }

    /**
     * Formats a parsed IPv6 address in canonical lowercase form.
     *
     * A formatting failure indicates an unexpected runtime problem because callers only
     * pass a validated 16-byte address into this method.
     *
     * @param string $binary Parsed 16-byte IPv6 address.
     *
     * @throws RuntimeException When the runtime cannot format the binary address.
     *
     * @since 2.0.0
     */
    private static function format(string $binary): string
    {
        $formatted = inet_ntop($binary);

        if ($formatted === false) {
            throw new RuntimeException(Translator::translate('ipv6_format_failed'));
        }

        return strtolower($formatted);
    }

    /**
     * Parses one IPv6 address into its 16-byte binary form.
     *
     * Zone-qualified and IPv4 values are intentionally rejected because package state
     * and approved configuration targets must contain unambiguous IPv6 addresses.
     *
     * @param string $address Address to parse.
     *
     * @throws InvalidArgumentException When the value is not an unqualified IPv6 address.
     *
     * @since 2.0.0
     */
    private static function parse(string $address): string
    {
        $candidate = trim($address);

        if ($candidate === '' || str_contains($candidate, '%')) {
            throw new InvalidArgumentException(Translator::translate('ipv6_address_invalid'));
        }

        $binary = inet_pton($candidate);

        if ($binary === false || strlen($binary) !== 16) {
            throw new InvalidArgumentException(Translator::translate('ipv6_address_invalid'));
        }

        return $binary;
    }

    /**
     * Parses a source or configured address that is suitable for global prefix updates.
     *
     * The 2000::/3 check excludes unspecified, loopback, multicast, link-local,
     * unique-local, site-local, and IPv4-mapped addresses from mutation planning.
     *
     * @param string $address Address to parse and validate.
     *
     * @throws InvalidArgumentException When the address is not global unicast.
     *
     * @since 2.0.0
     */
    private static function parseGlobalUnicast(string $address): string
    {
        $binary = self::parse($address);

        if ((ord($binary[0]) & 0xe0) !== 0x20) {
            throw new InvalidArgumentException(Translator::translate('ipv6_global_required'));
        }

        return $binary;
    }

    /**
     * Validates the package's delegated prefix-length boundary.
     *
     * Prefix lengths outside 48 through 64 are rejected before any address calculation
     * so adapters cannot accidentally broaden the configuration mutation scope.
     *
     * @param int $prefixLength Prefix length to validate.
     *
     * @throws InvalidArgumentException When the prefix length is outside the supported range.
     *
     * @since 2.0.0
     */
    private static function validatePrefixLength(int $prefixLength): void
    {
        if ($prefixLength < self::MIN_PREFIX_LENGTH || $prefixLength > self::MAX_PREFIX_LENGTH) {
            throw new InvalidArgumentException(Translator::translate('prefix_length_error'));
        }
    }
}

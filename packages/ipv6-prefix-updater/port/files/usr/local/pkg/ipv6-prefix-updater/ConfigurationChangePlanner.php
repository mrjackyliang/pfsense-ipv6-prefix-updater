<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Domain;

use InvalidArgumentException;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once(__DIR__ . '/Ipv6Prefix.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Builds field-level changes for approved pfSense configuration values.
 *
 * The planner understands only explicit IPv6 value formats selected by the scanner.
 * It preserves separators and CIDR lengths while replacing matching address prefixes.
 *
 * @since 2.0.0
 */
final class ConfigurationChangePlanner
{
    /**
     * Largest Base64 value decoded to look for addresses.
     *
     * @since 2.0.0
     */
    private const MAX_DECODED_LENGTH = 1048576;

    /**
     * Reload services a field may name.
     *
     * Fields from other packages name "package:<section>" instead, which the run turns
     * into a re-apply of the package that owns that settings section. "none" marks a
     * value that is only written, such as a description.
     *
     * @since 2.0.0
     */
    private const SERVICES = [
        'filter',
        'interfaces',
        'routing',
        'ipsec',
        'openvpn',
        'wireguard',
        'dhcpv6',
        'router_advertisements',
        'dns',
        'dns_resolver',
        'dns_forwarder',
        'none',
    ];

    /**
     * Builds a complete immutable plan for a prefix transition.
     *
     * Duplicate paths and identifiers are rejected before any result is returned. The
     * caller can therefore validate the whole plan before mutating configuration.
     *
     * @param string $previousSourceAddress - Previous source address.
     * @param int    $previousPrefixLength  - Previous prefix length.
     * @param string $currentSourceAddress  - Current source address.
     * @param int    $currentPrefixLength   - Current prefix length.
     * @param array  $targets               - Approved configuration targets.
     *
     * @return array{
     *     previous_prefix: string,
     *     previous_prefix_length: int,
     *     current_prefix: string,
     *     current_prefix_length: int,
     *     prefix_changed: bool,
     *     changes: list<array{
     *         target_id: string,
     *         path: string,
     *         before: string,
     *         after: string,
     *         replacements: list<array{0: string, 1: string}>,
     *         service: string,
     *         interface: string|null
     *     }>
     * }
     *
     * @throws InvalidArgumentException When state or target input is invalid.
     *
     * @since 2.0.0
     */
    public static function plan(
        string $previousSourceAddress,
        int $previousPrefixLength,
        string $currentSourceAddress,
        int $currentPrefixLength,
        array $targets,
    ): array {
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

            $replacements = [];
            $after = self::transformValue(
                $normalizedTarget['value'],
                $normalizedTarget['format'],
                $previousPrefix,
                $previousPrefixLength,
                $currentSourceAddress,
                $currentPrefixLength,
                $replacements,
            );

            if ($after === $normalizedTarget['value']) {
                continue;
            }

            $plan['changes'][] = [
                'target_id' => $targetId,
                'path' => $path,
                'before' => $normalizedTarget['value'],
                'after' => $after,
                'replacements' => $replacements,
                'service' => $normalizedTarget['service'],
                'interface' => $normalizedTarget['interface'],
            ];
        }

        return $plan;
    }

    /**
     * Returns the tokens of one approved field value that a prefix owns.
     *
     * The Fields tab uses this so it reports exactly the addresses a prefix transition
     * would rewrite, with the same list splitting and CIDR rules as the planner.
     *
     * @param string $value        - Field value.
     * @param string $format       - Approved field format.
     * @param string $prefix       - Canonical network prefix.
     * @param int    $prefixLength - Prefix length.
     *
     * @return list<string>
     *
     * @since 2.0.0
     */
    public static function matchingTokens(string $value, string $format, string $prefix, int $prefixLength): array
    {
        $matchingTokens = [];

        foreach (self::tokens($value, $format) as $token) {
            $address = self::tokenAddress($token);

            if ($address === null) {
                continue;
            }

            try {
                if (Ipv6Prefix::contains($prefix, $address, $prefixLength)) {
                    $matchingTokens[] = $token;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $matchingTokens;
    }

    /**
     * Reports whether a value holds an IPv6-looking token in free text.
     *
     * Other packages' settings are scanned only when they hold such a token, so values
     * without any IPv6 address never become targets.
     *
     * @param string $value - Configuration value.
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public static function containsAddressToken(string $value): bool
    {
        return count(self::tokens($value, 'text')) > 0;
    }

    /**
     * Decodes a value stored as Base64 text, such as the DNS Resolver's custom options.
     *
     * Only strict, re-encodable Base64 that decodes to printable UTF-8 counts, so binary
     * data and ordinary words are never treated as encoded text. Null means not Base64.
     *
     * @param string $value - Configuration value.
     *
     * @return string|null
     *
     * @since 2.0.0
     */
    public static function decodeBase64(string $value): ?string
    {
        $compactValue = preg_replace('/\s+/', '', $value);

        if (
            !is_string($compactValue)
            || strlen($compactValue) < 16
            || strlen($compactValue) > self::MAX_DECODED_LENGTH
            || strlen($compactValue) % 4 !== 0
            || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compactValue) !== 1
        ) {
            return null;
        }

        $decodedValue = base64_decode($compactValue, true);

        if (
            !is_string($decodedValue)
            || base64_encode($decodedValue) !== $compactValue
            || preg_match('//u', $decodedValue) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decodedValue) === 1
        ) {
            return null;
        }

        return $decodedValue;
    }

    /**
     * Validates and normalizes one scanner-provided target.
     *
     * Every target names its exact path, syntax, and required service so the runtime
     * never infers mutation or reload behavior from arbitrary configuration content.
     *
     * @param mixed $target - Scanner-provided target.
     * @param int   $index  - Diagnostic target index.
     *
     * @return array{
     *     target_id: string,
     *     path: string,
     *     value: string,
     *     format: string,
     *     service: string,
     *     interface: string|null
     * }
     *
     * @throws InvalidArgumentException When the target is malformed.
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
        $value = $target['value'] ?? null;
        $format = $target['format'] ?? null;
        $service = $target['service'] ?? null;
        $interface = $target['interface'] ?? null;
        $formats = [
            'single',
            'space_list',
            'comma_list',
            'text',
            'base64',
        ];

        if (!is_string($targetId) || trim($targetId) === '') {
            throw new InvalidArgumentException(Translator::translate('target_id_required', [$index]));
        }

        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(Translator::translate('target_path_required', [$index]));
        }

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(Translator::translate('target_value_required', [$index]));
        }

        if (!is_string($format) || in_array($format, $formats, true) === false) {
            throw new InvalidArgumentException(Translator::translate('target_format_unsupported', [$index]));
        }

        if (
            !is_string($service)
            || (
                in_array($service, self::SERVICES, true) === false
                && preg_match('/^package:[a-z0-9_-]+$/', $service) !== 1
            )
        ) {
            throw new InvalidArgumentException(Translator::translate('target_service_unsupported', [$index]));
        }

        if ($interface !== null && (!is_string($interface) || trim($interface) === '')) {
            throw new InvalidArgumentException(Translator::translate('target_interface_invalid', [$index]));
        }

        return [
            'target_id' => trim($targetId),
            'path' => trim($path),
            'value' => $value,
            'format' => $format,
            'service' => $service,
            'interface' => is_string($interface) ? trim($interface) : null,
        ];
    }

    /**
     * Returns the separator pattern of a list format, or null for a single value.
     *
     * Planning and Fields tab matching share this so both split approved list values
     * the same way.
     *
     * @param string $format - Approved field format.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function separatorPattern(string $format): ?string
    {
        return match ($format) {
            'space_list' => '/(\s+)/',
            'comma_list' => '/([,\s]+)/',
            default => null,
        };
    }

    /**
     * Splits one run of address characters from free text into its parts.
     *
     * A lone leading colon, as in "IP:2001:db8::1", and trailing periods or a lone
     * trailing colon belong to the surrounding text and are kept apart from the token.
     *
     * @param string $run - Run of hexadecimal digits, colons, periods, and slashes.
     *
     * @return array{0: string, 1: string, 2: string}
     *
     * @since 2.0.0
     * @private
     */
    private static function splitTextRun(string $run): array
    {
        $leading = '';
        $trailing = '';

        if (str_starts_with($run, ':') && str_starts_with($run, '::') === false) {
            $leading = ':';
            $run = substr($run, 1);
        }

        while (str_ends_with($run, '.')) {
            $trailing = '.' . $trailing;
            $run = substr($run, 0, -1);
        }

        if (str_ends_with($run, ':') && str_ends_with($run, '::') === false) {
            $trailing = ':' . $trailing;
            $run = substr($run, 0, -1);
        }

        return [$leading, $run, $trailing];
    }

    /**
     * Returns the address part of a token with an optional CIDR suffix.
     *
     * A suffix must be "/" followed by 0 through 128. Null means the suffix is malformed
     * and the token must stay exactly as written.
     *
     * @param string $token - Candidate value token.
     *
     * @return string|null
     *
     * @since 2.0.0
     * @private
     */
    private static function tokenAddress(string $token): ?string
    {
        $slashPosition = strrpos($token, '/');

        if ($slashPosition === false) {
            return $token;
        }

        $cidrLength = substr($token, $slashPosition + 1);

        if ($cidrLength === '' || ctype_digit($cidrLength) === false || (int) $cidrLength > 128) {
            return null;
        }

        return substr($token, 0, $slashPosition);
    }

    /**
     * Returns the candidate address tokens of one value in a given format.
     *
     * Free text yields only runs with at least two colons, so times, MAC-style values,
     * and ports never reach address parsing.
     *
     * @param string $value  - Field value.
     * @param string $format - Approved field format.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private static function tokens(string $value, string $format): array
    {
        if ($format === 'base64') {
            $decodedValue = self::decodeBase64($value);

            return ($decodedValue === null) ? [] : self::tokens($decodedValue, 'text');
        }

        if ($format === 'text') {
            $tokens = [];

            if (str_contains($value, ':') === false) {
                return $tokens;
            }

            preg_match_all('/[0-9A-Fa-f:.\/]+/', $value, $matches);

            foreach ($matches[0] as $run) {
                $token = self::splitTextRun($run)[1];

                if (substr_count($token, ':') >= 2) {
                    $tokens[] = $token;
                }
            }

            return $tokens;
        }

        $separatorPattern = self::separatorPattern($format);

        if ($separatorPattern === null) {
            return [$value];
        }

        $tokens = preg_split($separatorPattern, $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Replaces one optional-CIDR IPv6 token when it belongs to the old prefix.
     *
     * Invalid and unsuitable values are intentionally ignored because approved list
     * fields may also contain alias names, IPv4 networks, or other literal values.
     *
     * @param string $token                - Candidate value token.
     * @param string $previousPrefix       - Previous canonical network prefix.
     * @param int    $previousPrefixLength - Previous prefix length.
     * @param string $currentSourceAddress - Current source address.
     * @param int    $currentPrefixLength  - Current prefix length.
     * @param array  $replacements         - Replaced token pairs, updated in place.
     *
     * @return string
     *
     * @since 2.0.0
     */
    private static function transformToken(
        string $token,
        string $previousPrefix,
        int $previousPrefixLength,
        string $currentSourceAddress,
        int $currentPrefixLength,
        array &$replacements,
    ): string {
        $address = self::tokenAddress($token);

        if ($address === null) {
            return $token;
        }

        $suffix = substr($token, strlen($address));

        try {
            if (Ipv6Prefix::contains($previousPrefix, $address, $previousPrefixLength) === false) {
                return $token;
            }

            $replacedAddress = Ipv6Prefix::replacePrefix(
                $currentSourceAddress,
                $address,
                $currentPrefixLength,
            );
        } catch (InvalidArgumentException) {
            return $token;
        }

        $replacedToken = $replacedAddress . $suffix;

        if ($replacedToken !== $token) {
            $replacements[] = [$token, $replacedToken];
        }

        return $replacedToken;
    }

    /**
     * Replaces matching address tokens inside one approved field value.
     *
     * Separators remain byte-for-byte identical. Non-IPv6 tokens such as alias names,
     * IPv4 addresses, comments, and hostnames pass through without modification.
     *
     * @param string $value                - Original field value.
     * @param string $format               - Approved field format.
     * @param string $previousPrefix       - Previous canonical network prefix.
     * @param int    $previousPrefixLength - Previous prefix length.
     * @param string $currentSourceAddress - Current source address.
     * @param int    $currentPrefixLength  - Current prefix length.
     * @param array  $replacements         - Replaced token pairs, updated in place.
     *
     * @return string
     *
     * @since 2.0.0
     */
    private static function transformValue(
        string $value,
        string $format,
        string $previousPrefix,
        int $previousPrefixLength,
        string $currentSourceAddress,
        int $currentPrefixLength,
        array &$replacements,
    ): string {
        // Encoded text is rewritten as plain text and encoded again only when it changed.
        if ($format === 'base64') {
            $decodedValue = self::decodeBase64($value);

            if ($decodedValue === null) {
                return $value;
            }

            $transformedValue = self::transformValue(
                $decodedValue,
                'text',
                $previousPrefix,
                $previousPrefixLength,
                $currentSourceAddress,
                $currentPrefixLength,
                $replacements,
            );

            return ($transformedValue === $decodedValue) ? $value : base64_encode($transformedValue);
        }

        if ($format === 'text') {
            $transformedValue = preg_replace_callback(
                '/[0-9A-Fa-f:.\/]+/',
                static function (array $matches) use (
                    $previousPrefix,
                    $previousPrefixLength,
                    $currentSourceAddress,
                    $currentPrefixLength,
                    &$replacements,
                ): string {
                    [$leading, $token, $trailing] = self::splitTextRun($matches[0]);

                    if (substr_count($token, ':') < 2) {
                        return $matches[0];
                    }

                    $replacedToken = self::transformToken(
                        $token,
                        $previousPrefix,
                        $previousPrefixLength,
                        $currentSourceAddress,
                        $currentPrefixLength,
                        $replacements,
                    );

                    return $leading . $replacedToken . $trailing;
                },
                $value,
            );

            if (!is_string($transformedValue)) {
                throw new InvalidArgumentException(Translator::translate('target_parse_failed'));
            }

            return $transformedValue;
        }

        $separatorPattern = self::separatorPattern($format);

        if ($separatorPattern === null) {
            return self::transformToken(
                $value,
                $previousPrefix,
                $previousPrefixLength,
                $currentSourceAddress,
                $currentPrefixLength,
                $replacements,
            );
        }

        $tokens = preg_split($separatorPattern, $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($tokens === false) {
            throw new InvalidArgumentException(Translator::translate('target_parse_failed'));
        }

        foreach ($tokens as $index => $token) {
            if ($token === '' || preg_match($separatorPattern, $token) === 1) {
                continue;
            }

            $tokens[$index] = self::transformToken(
                $token,
                $previousPrefix,
                $previousPrefixLength,
                $currentSourceAddress,
                $currentPrefixLength,
                $replacements,
            );
        }

        return implode('', $tokens);
    }
}

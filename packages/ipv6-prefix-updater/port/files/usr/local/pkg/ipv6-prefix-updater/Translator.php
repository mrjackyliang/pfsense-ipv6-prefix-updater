<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;

/**
 * Translates package-owned text independently of pfSense's core gettext domain.
 *
 * pfSense package XML is rendered by the generic package editor and is not passed
 * through gettext. A private catalog also lets the package honor pfSense language
 * identifiers such as ko_FR without depending on operating-system locale aliases.
 *
 * @since 2.0.0
 */
final class Translator
{
    /** Default language and catalog fallback. @since 2.0.0 */
    public const DEFAULT_LOCALE = 'en_US';

    /** @var array<string, array<string, string>>|null Loaded translation catalogs. @since 2.0.0 */
    private static ?array $catalogs = null;

    /**
     * Returns every pfSense language identifier supported by this package.
     *
     * @return list<string>
     *
     * @since 2.0.0
     */
    public static function supportedLocales(): array
    {
        return array_keys(self::catalogs());
    }

    /**
     * Returns the language selected in the active pfSense configuration.
     *
     * Tests and non-pfSense tooling use English because config_get_path is absent.
     * Unknown identifiers also fall back to English instead of exposing raw keys.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public static function currentLocale(): string
    {
        $locale = self::DEFAULT_LOCALE;

        if (function_exists('config_get_path')) {
            $configuredLocale = config_get_path('system/language', self::DEFAULT_LOCALE);

            if (is_string($configuredLocale) && $configuredLocale !== '') {
                $locale = $configuredLocale;
            }
        }

        return array_key_exists($locale, self::catalogs()) ? $locale : self::DEFAULT_LOCALE;
    }

    /**
     * Translates one package message for the active pfSense language.
     *
     * @param string              $key       - Stable catalog key.
     * @param array<int, scalar> $arguments - Positional sprintf arguments.
     *
     * @return string
     *
     * @since 2.0.0
     */
    public static function translate(string $key, array $arguments = []): string
    {
        return self::translateForLocale(self::currentLocale(), $key, $arguments);
    }

    /**
     * Translates one package message for an explicit pfSense language identifier.
     *
     * Explicit selection supports regression tests without changing the firewall's
     * global language. Missing keys are programmer errors and fail closed in tests.
     *
     * @param string              $locale    - pfSense language identifier.
     * @param string              $key       - Stable catalog key.
     * @param array<int, scalar> $arguments - Positional sprintf arguments.
     *
     * @return string
     *
     * @throws RuntimeException When the English source key is missing.
     *
     * @since 2.0.0
     */
    public static function translateForLocale(string $locale, string $key, array $arguments = []): string
    {
        $catalogs = self::catalogs();
        $selectedLocale = array_key_exists($locale, $catalogs) ? $locale : self::DEFAULT_LOCALE;

        if (!array_key_exists($key, $catalogs[self::DEFAULT_LOCALE])) {
            throw new RuntimeException(sprintf('Unknown IPv6 Prefix Updater translation key "%s".', $key));
        }

        $templateKey = $key;

        if ($key === 'updated_message' && is_int($arguments[0] ?? null)) {
            $templateKey = self::pluralizedUpdatedMessageKey($selectedLocale, $arguments[0]);
        }

        $template = $catalogs[$selectedLocale][$templateKey]
            ?? $catalogs[self::DEFAULT_LOCALE][$templateKey];

        if ($arguments === []) {
            return $template;
        }

        return vsprintf($template, $arguments);
    }

    /**
     * Selects the count-specific updated-message key for one locale.
     *
     * Bosnian and Russian use one/few/many rules based on the final two
     * digits. Polish shares the few rule but treats values ending in one,
     * other than exactly one, as many. Other catalogs use singular/plural.
     *
     * @param string $locale - Supported pfSense language identifier.
     * @param int    $count  - Number of updated configuration fields.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private static function pluralizedUpdatedMessageKey(string $locale, int $count): string
    {
        $count = max(0, $count);
        $moduloTen = $count % 10;
        $moduloHundred = $count % 100;

        if ($locale === 'bs' || $locale === 'ru_RU') {
            if ($moduloTen === 1 && $moduloHundred !== 11) {
                return 'updated_message_one';
            }

            if ($moduloTen >= 2 && $moduloTen <= 4 && ($moduloHundred < 12 || $moduloHundred > 14)) {
                return 'updated_message_few';
            }

            return 'updated_message_many';
        }

        if ($locale === 'pl_PL') {
            if ($count === 1) {
                return 'updated_message_one';
            }

            if ($moduloTen >= 2 && $moduloTen <= 4 && ($moduloHundred < 12 || $moduloHundred > 14)) {
                return 'updated_message_few';
            }

            return 'updated_message_many';
        }

        return $count === 1 ? 'updated_message_one' : 'updated_message_many';
    }

    /**
     * Returns an explicit locale catalog for completeness and placeholder tests.
     *
     * @param string $locale - pfSense language identifier.
     *
     * @return array<string, string>
     *
     * @since 2.0.0
     */
    public static function catalogForLocale(string $locale): array
    {
        $catalogs = self::catalogs();

        return $catalogs[$locale] ?? $catalogs[self::DEFAULT_LOCALE];
    }

    /**
     * Loads and validates the immutable package translation catalogs once.
     *
     * @return array<string, array<string, string>>
     *
     * @throws RuntimeException When the catalog source is malformed.
     *
     * @since 2.0.0
     * @private
     */
    private static function catalogs(): array
    {
        if (is_array(self::$catalogs)) {
            return self::$catalogs;
        }

        $catalogs = require(__DIR__ . '/Translations.php');

        if (!is_array($catalogs) || !isset($catalogs[self::DEFAULT_LOCALE])) {
            throw new RuntimeException('IPv6 Prefix Updater translation catalogs are invalid.');
        }

        self::$catalogs = $catalogs;

        return self::$catalogs;
    }
}

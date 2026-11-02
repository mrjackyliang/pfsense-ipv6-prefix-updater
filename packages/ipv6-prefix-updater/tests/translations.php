<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater/Translator.php');

/**
 * Asserts strict equality for translation regression checks.
 *
 * @param mixed  $expected - Expected value.
 * @param mixed  $actual   - Actual value.
 * @param string $message  - Failure context.
 *
 * @return void
 *
 * @throws RuntimeException When values differ.
 *
 * @since 2.0.0
 */
function assertTranslationSame(mixed $expected, mixed $actual, string $message): void
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
 * Returns ordered printf placeholders from one translated template.
 *
 * Placeholder parity protects address, prefix-length, count, interface, and error
 * values from being dropped or assigned the wrong type in a translated message.
 *
 * @param string $template - Translation template.
 *
 * @return list<string>
 *
 * @since 2.0.0
 */
function translationPlaceholders(string $template): array
{
    preg_match_all('/%(?:[1-9][0-9]*\$)?[bcdeEfFgGosuxX]/', $template, $matches);
    sort($matches[0]);

    return $matches[0];
}

$expectedLocales = [
    'bs',
    'zh_CN',
    'zh_Hans_CN',
    'zh_Hans_HK',
    'zh_Hant_TW',
    'nl_NL',
    'en_US',
    'fr_FR',
    'de_DE',
    'it_IT',
    'ko_FR',
    'ko_KR',
    'nb_NO',
    'pl_PL',
    'pt_PT',
    'pt_BR',
    'ru_RU',
    'es_ES',
    'es_AR',
];

assertTranslationSame(
    $expectedLocales,
    Translator::supportedLocales(),
    'The package must match every language identifier exposed by pfSense.',
);

$english = Translator::catalogForLocale('en_US');
$englishKeys = array_keys($english);

foreach ($expectedLocales as $locale) {
    $catalog = Translator::catalogForLocale($locale);

    assertTranslationSame(
        $englishKeys,
        array_keys($catalog),
        sprintf('Locale %s must contain every package message key in canonical order.', $locale),
    );

    foreach ($english as $key => $englishTemplate) {
        $translatedTemplate = $catalog[$key] ?? '';

        if (trim($translatedTemplate) === '') {
            throw new RuntimeException(sprintf('Locale %s has an empty translation for %s.', $locale, $key));
        }

        assertTranslationSame(
            translationPlaceholders($englishTemplate),
            translationPlaceholders($translatedTemplate),
            sprintf('Locale %s must preserve placeholders for %s.', $locale, $key),
        );
    }

    if ($locale !== 'en_US' && $catalog === $english) {
        throw new RuntimeException(sprintf('Locale %s must not be an English-only fallback catalog.', $locale));
    }
}

$runtimeErrorKeys = [
    'ipv6_format_failed',
    'ipv6_address_invalid',
    'ipv6_global_required',
    'lock_unavailable',
    'state_read_failed',
    'state_invalid',
    'state_write_failed',
    'target_id_duplicated',
    'target_path_duplicated',
    'target_array_required',
    'target_id_required',
    'target_path_required',
    'target_value_required',
    'target_format_unsupported',
    'target_service_unsupported',
    'target_interface_invalid',
    'target_parse_failed',
    'target_ipv6_required',
    'environment_unsupported',
    'dependency_unavailable',
    'reload_service_unsupported',
    'root_configuration_invalid',
    'interface_address_unavailable',
    'backup_failed',
    'configuration_write_failed',
    'reload_failed',
];

foreach ($expectedLocales as $locale) {
    if ($locale === 'en_US') {
        continue;
    }

    $catalog = Translator::catalogForLocale($locale);

    foreach ($runtimeErrorKeys as $key) {
        if ($catalog[$key] === $english[$key]) {
            throw new RuntimeException(sprintf(
                'Locale %s must translate operator-visible runtime error %s.',
                $locale,
                $key,
            ));
        }
    }
}

$baselineRecoveryKeys = [
    'baseline_recovery',
    'accept_current_prefix',
    'accept_current_prefix_help',
    'accept_current_prefix_confirm',
    'accept_current_prefix_completed',
    'accept_current_prefix_warning',
    'status_accepted',
    'accept_current_prefix_message',
    'accept_current_prefix_conflict',
    'usage',
];

foreach ($expectedLocales as $locale) {
    if ($locale === 'en_US') {
        continue;
    }

    $catalog = Translator::catalogForLocale($locale);

    foreach ($baselineRecoveryKeys as $key) {
        if ($catalog[$key] === $english[$key]) {
            throw new RuntimeException(sprintf(
                'Locale %s must translate baseline recovery message %s.',
                $locale,
                $key,
            ));
        }
    }
}

$fieldKeys = [
    'dhcpv6_server_help',
    'router_advertisements_help',
    'dns_host_overrides_help',
    'firewall_rules_help',
    'nat_npt_help',
    'fields',
    'fields_settings_help',
    'fields_intro',
    'fields_no_baseline',
    'fields_failed',
    'fields_nothing_selected',
    'fields_auto_select',
    'fields_auto_select_help',
    'fields_group_empty',
    'fields_package_help',
    'fields_package_owner_unknown',
    'other_settings',
    'other_settings_help',
    'fields_missing_help',
    'fields_locked',
    'fields_revision',
    'fields_runtime_error',
    'last_run_skipped_fields_help',
    'package_reapplied',
    'package_owner_unknown',
    'package_reapply_failed',
    'skipped_fields_message',
    'reason_remote_vpn',
    'reason_secret',
    'reason_base64',
];

foreach ($expectedLocales as $locale) {
    if ($locale === 'en_US') {
        continue;
    }

    $catalog = Translator::catalogForLocale($locale);

    foreach ($fieldKeys as $key) {
        if ($catalog[$key] === $english[$key]) {
            throw new RuntimeException(sprintf(
                'Locale %s must translate Fields tab message %s.',
                $locale,
                $key,
            ));
        }
    }
}

assertTranslationSame(
    'Hay una comprobación del prefijo en curso. Guardá de nuevo cuando termine.',
    Translator::translateForLocale('es_AR', 'fields_locked'),
    'Argentine Spanish Fields tab instructions must use voseo.',
);
assertTranslationSame(
    'O Atualizador de prefixo IPv6 salvou a seleção de campos da guia Campos',
    Translator::translateForLocale('pt_BR', 'fields_revision'),
    'Brazilian Portuguese must use its own word for a tab.',
);
assertTranslationSame(
    '1002개 중 1001개 선택됨',
    Translator::translateForLocale('ko_KR', 'fields_selected_count', [1001, 1002]),
    'The Fields tab count template must keep both numbers when a locale reorders them.',
);

assertTranslationSame(
    '설정',
    Translator::translateForLocale('ko_FR', 'settings'),
    'The pfSense ko_FR identifier must resolve to Korean package text.',
);
assertTranslationSame(
    '설정',
    Translator::translateForLocale('ko_KR', 'settings'),
    'The pfSense ko_KR identifier must resolve to Korean package text.',
);
assertTranslationSame(
    'Settings',
    Translator::translateForLocale('unsupported_LOCALE', 'settings'),
    'Unknown locale identifiers must fall back safely to English.',
);
assertTranslationSame(
    'pfSense 無法備份目前的設定。',
    Translator::translateForLocale('zh_Hant_TW', 'backup_failed'),
    'A backup failure shown in the WebGUI must use the active pfSense language.',
);
assertTranslationSame(
    'Prefix changed from 2001:db8:1::/56 to 2001:db8:2::/56, but no approved fields matched the previous prefix.',
    Translator::translateForLocale(
        'en_US',
        'prefix_changed_no_matches',
        ['2001:db8:1::', 56, '2001:db8:2::', 56],
    ),
    'Formatted translations must preserve ordered values and types.',
);

$portugueseBrazilCatalog = Translator::catalogForLocale('pt_BR');

assertTranslationSame(
    'Aceito',
    $portugueseBrazilCatalog['status_accepted'],
    'Brazilian Portuguese must use its accepted-status form after the regional recovery merge.',
);
assertTranslationSame(
    'Uso: ipv6-prefix-updater [--manual] [--dry-run] [--accept-current-prefix [--interface=NAME]] [--migrate-legacy]',
    $portugueseBrazilCatalog['usage'],
    'Brazilian Portuguese usage text must not be overwritten by the European Portuguese recovery catalog.',
);
assertTranslationSame(
    'O estado do Atualizador de prefixo IPv6 é inválido ou não é compatível.',
    $portugueseBrazilCatalog['state_invalid'],
    'Brazilian Portuguese runtime errors must preserve package-brand capitalization.',
);

$portugueseBrazilText = implode("\n", $portugueseBrazilCatalog);
$portugueseBrazilForbiddenFragments = [
    'Monitorização',
    'monitorizada',
    'planead',
    'âmbito',
    'Selecione uma agenda',
    'Utilização:',
    '«',
    '»',
];

foreach ($portugueseBrazilForbiddenFragments as $fragment) {
    assertTranslationSame(
        false,
        str_contains($portugueseBrazilText, $fragment),
        sprintf('Brazilian Portuguese must not inherit the European Portuguese fragment %s.', $fragment),
    );
}

$spanishArgentinaCatalog = Translator::catalogForLocale('es_AR');
$spanishArgentinaText = implode("\n", $spanishArgentinaCatalog);

foreach (['Seleccione', 'Introduzca', 'Elija', 'Active', 'Revise', 'Ejecute', 'Acepte', 'Use esta acción', 'Apruebe', 'Actualícelos', 'Guarde'] as $fragment) {
    assertTranslationSame(
        false,
        str_contains($spanishArgentinaText, $fragment),
        sprintf('Argentine Spanish must use voseo instead of the formal fragment %s.', $fragment),
    );
}

assertTranslationSame(
    'El destino en el índice 4 debe ser un arreglo.',
    Translator::translateForLocale('es_AR', 'target_array_required', [4]),
    'Argentine Spanish runtime errors must use the regional catalog.',
);
assertTranslationSame(
    'Aceptá el prefijo activo como nueva referencia sin cambiar la configuración. Usá esta acción solo después de confirmar que las direcciones guardadas ya son correctas.',
    $spanishArgentinaCatalog['accept_current_prefix_help'],
    'Argentine Spanish baseline recovery instructions must use voseo.',
);

$obsoleteBaselineTerms = [
    'de_DE' => 'Ausgangszustand',
    'nl_NL' => 'uitgangspunt',
    'it_IT' => 'riferimento',
    'nb_NO' => 'utgangspunkt',
    'ko_KR' => '기준만',
    'ru_RU' => 'исходное состояние',
    'pl_PL' => 'punkt odniesienia',
];

foreach ($obsoleteBaselineTerms as $locale => $obsoleteTerm) {
    assertTranslationSame(
        false,
        str_contains(implode("\n", Translator::catalogForLocale($locale)), $obsoleteTerm),
        sprintf('Locale %s must use one term consistently for the saved prefix baseline.', $locale),
    );
}

assertTranslationSame(
    'Dernière exécution',
    Translator::translateForLocale('fr_FR', 'last_run'),
    'French must retain the label for the complete last-run section.',
);
assertTranslationSame(
    'Exécution la plus récente',
    Translator::translateForLocale('fr_FR', 'latest_run'),
    'French must distinguish the latest command output from the complete last-run section.',
);
assertTranslationSame(
    'Nadzirano sučelje',
    Translator::translateForLocale('bs', 'monitored_interface'),
    'Bosnian must distinguish the monitored interface from pfSense tracked interfaces.',
);
assertTranslationSame(
    'Контролируемый интерфейс',
    Translator::translateForLocale('ru_RU', 'monitored_interface'),
    'Russian must distinguish the monitored interface from pfSense tracked interfaces.',
);
assertTranslationSame(
    false,
    str_contains(implode("\n", Translator::catalogForLocale('bs')), '"%1$s"'),
    'Bosnian messages must use one quotation-mark style.',
);

$updatedMessageArguments = static fn (int $count): array => [
    $count,
    '2001:db8:1::',
    56,
    '2001:db8:2::',
    56,
    'wan',
];
$pluralCases = [
    ['bs', 1, 'Ažurirano je 1 odobreno polje sa 2001:db8:1::/56 na 2001:db8:2::/56 na sučelju wan.'],
    ['bs', 2, 'Ažurirana su 2 odobrena polja sa 2001:db8:1::/56 na 2001:db8:2::/56 na sučelju wan.'],
    ['bs', 5, 'Ažurirano je 5 odobrenih polja sa 2001:db8:1::/56 na 2001:db8:2::/56 na sučelju wan.'],
    ['bs', 21, 'Ažurirano je 21 odobreno polje sa 2001:db8:1::/56 na 2001:db8:2::/56 na sučelju wan.'],
    ['pl_PL', 1, 'Zaktualizowano 1 zatwierdzone pole z 2001:db8:1::/56 do 2001:db8:2::/56 na interfejsie wan.'],
    ['pl_PL', 2, 'Zaktualizowano 2 zatwierdzone pola z 2001:db8:1::/56 do 2001:db8:2::/56 na interfejsie wan.'],
    ['pl_PL', 5, 'Zaktualizowano 5 zatwierdzonych pól z 2001:db8:1::/56 do 2001:db8:2::/56 na interfejsie wan.'],
    ['pl_PL', 21, 'Zaktualizowano 21 zatwierdzonych pól z 2001:db8:1::/56 do 2001:db8:2::/56 na interfejsie wan.'],
    ['ru_RU', 1, 'Обновлено 1 разрешённое поле с 2001:db8:1::/56 на 2001:db8:2::/56 на интерфейсе wan.'],
    ['ru_RU', 2, 'Обновлено 2 разрешённых поля с 2001:db8:1::/56 на 2001:db8:2::/56 на интерфейсе wan.'],
    ['ru_RU', 5, 'Обновлено 5 разрешённых полей с 2001:db8:1::/56 на 2001:db8:2::/56 на интерфейсе wan.'],
    ['ru_RU', 21, 'Обновлено 21 разрешённое поле с 2001:db8:1::/56 на 2001:db8:2::/56 на интерфейсе wan.'],
];

foreach ($pluralCases as [$locale, $count, $expected]) {
    assertTranslationSame(
        $expected,
        Translator::translateForLocale($locale, 'updated_message', $updatedMessageArguments($count)),
        sprintf('Locale %s must select the correct updated-field form for %d.', $locale, $count),
    );
}

fwrite(STDOUT, sprintf(
    "%d locale catalogs with %d messages passed translation checks.\n",
    count($expectedLocales),
    count($english),
));

exit(0);

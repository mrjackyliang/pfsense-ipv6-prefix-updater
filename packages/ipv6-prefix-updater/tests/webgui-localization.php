<?php

declare(strict_types=1);

use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;

/** @var string Simulated pfSense language identifier. */
$webGuiTestLocale = 'zh_Hant_TW';

/** @var array|null Simulated package settings record. */
$webGuiTestSettings = null;

/** @var array|null Simulated field selection saved from the Fields tab. */
$webGuiTestFieldSelection = null;

if (function_exists('config_get_path') === false) {
    /**
     * Returns the simulated language, settings, field selection, or a default.
     *
     * @param string $path    - pfSense configuration path.
     * @param mixed  $default - Default value.
     *
     * @return mixed
     *
     * @since 2.0.0
     */
    function config_get_path(string $path, mixed $default = null): mixed
    {
        global $webGuiTestFieldSelection;
        global $webGuiTestLocale;
        global $webGuiTestSettings;

        return match ($path) {
            'system/language' => $webGuiTestLocale,
            'installedpackages/ipv6prefixupdater/config/0' => $webGuiTestSettings ?? $default,
            'installedpackages/ipv6prefixupdater/fields' => $webGuiTestFieldSelection ?? $default,
            default => $default,
        };
    }
}

if (function_exists('get_configured_interface_with_descr') === false) {
    /**
     * Returns one simulated configured interface.
     *
     * @param bool $includeUnassigned - Whether unassigned interfaces are requested.
     *
     * @return array<string, string>
     *
     * @since 2.0.0
     */
    function get_configured_interface_with_descr(bool $includeUnassigned = false): array
    {
        return ['lan' => 'LAN'];
    }
}

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . get_include_path());
require_once(__DIR__ . '/../port/files/usr/local/pkg/ipv6-prefix-updater.inc');

/**
 * Asserts strict equality for WebGUI localization checks.
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
function assertWebGuiLocalizationSame(mixed $expected, mixed $actual, string $message): void
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

$package = [
    'title' => 'Services: IPv6 Prefix Updater',
    'aftersaveredirect' => '/old',
    'tabs' => [
        'tab' => [
            ['text' => 'Settings'],
            ['text' => 'Status'],
            ['text' => 'Fields'],
            ['text' => 'Backups'],
            ['text' => 'Cleanup'],
            ['text' => 'About'],
        ],
    ],
    'fields' => [
        'field' => [
            [
                'type' => 'listtopic',
                'name' => 'Prefix Monitoring',
            ],
            [
                'fielddescr' => 'Enable Scheduled Updates',
                'description' => 'Check for prefix changes automatically. The first successful check records a baseline and never modifies configuration.',
            ],
            [
                'fielddescr' => 'Schedule',
                'options' => [
                    'option' => [
                        ['name' => 'Every 15 minutes'],
                        ['name' => 'Hourly'],
                    ],
                ],
            ],
            [
                'fielddescr' => 'Monitored Interfaces',
                'type' => 'rowhelper',
                'rowhelper' => [
                    'rowhelperfield' => [
                        [
                            'fielddescr' => 'Interface',
                            'fieldname' => 'interface',
                            'type' => 'select',
                        ],
                        [
                            'fielddescr' => 'Delegated prefix length',
                            'fieldname' => 'prefix_length',
                            'type' => 'select',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'info',
                'description' => 'Choose which configuration fields change with the prefix on the Fields tab.',
            ],
            [
                'type' => 'listtopic',
                'name' => 'Package Updates',
            ],
            [
                'fielddescr' => 'Update Checks',
                'description' => 'Choose how often pfSense checks GitHub for a new package release. The check runs at a stable randomized time.',
                'options' => [
                    'option' => [
                        ['name' => 'Daily'],
                        ['name' => 'Weekly'],
                        ['name' => 'Monthly'],
                        ['name' => 'Never'],
                    ],
                ],
            ],
            [
                'fielddescr' => 'Remote Notifications',
                'description' => 'Also send each new-version notice through configured pfSense email, Telegram, Pushover, or other remote notification channels.',
            ],
        ],
    ],
];

pfsensePackageIpv6PrefixUpdaterLocalizePackageGui($package);

assertWebGuiLocalizationSame(
    '服務：IPv6 前綴更新器',
    $package['title'],
    'The generic package editor title must use the configured pfSense language.',
);
assertWebGuiLocalizationSame(
    '設定',
    $package['tabs']['tab'][0]['text'],
    'Package tabs must be localized before form rendering.',
);
assertWebGuiLocalizationSame(
    '啟用排程更新',
    $package['fields']['field'][1]['fielddescr'],
    'Package XML field labels must be localized.',
);
assertWebGuiLocalizationSame(
    '每 15 分鐘',
    $package['fields']['field'][2]['options']['option'][0]['name'],
    'Package XML option names must be localized.',
);
assertWebGuiLocalizationSame(
    true,
    str_contains($package['aftersaveredirect'], rawurlencode('設定已儲存。')),
    'The post-save redirect must carry a localized success notice.',
);
assertWebGuiLocalizationSame(
    ['欄位', '備份', '清理', '關於'],
    [$package['tabs']['tab'][2]['text'], $package['tabs']['tab'][3]['text'], $package['tabs']['tab'][4]['text'], $package['tabs']['tab'][5]['text']],
    'The Fields, Backups, Cleanup, and About tabs must be localized.',
);
assertWebGuiLocalizationSame(
    ['設定', '狀態', '欄位', '備份', '清理', '關於'],
    array_column(pfsensePackageIpv6PrefixUpdaterTabs('fields'), 0),
    'The custom pages must show the same six tabs in the same order.',
);
assertWebGuiLocalizationSame(
    [false, false, true, false, false, false],
    array_column(pfsensePackageIpv6PrefixUpdaterTabs('fields'), 1),
    'The Fields page must mark only its own tab active.',
);
assertWebGuiLocalizationSame(
    ['監控介面', '介面', '委派前綴長度'],
    [
        $package['fields']['field'][3]['fielddescr'],
        $package['fields']['field'][3]['rowhelper']['rowhelperfield'][0]['fielddescr'],
        $package['fields']['field'][3]['rowhelper']['rowhelperfield'][1]['fielddescr'],
    ],
    'Monitored-interface row labels must be localized.',
);
assertWebGuiLocalizationSame(
    ['option' => [['name' => 'LAN', 'value' => 'lan']]],
    $package['fields']['field'][3]['rowhelper']['rowhelperfield'][0]['options'],
    'The interface column must list the configured interfaces.',
);
assertWebGuiLocalizationSame(
    '在「欄位」分頁中選擇哪些設定欄位隨前綴變更。',
    $package['fields']['field'][4]['description'],
    'The Settings tab pointer to the Fields tab must be localized.',
);
assertWebGuiLocalizationSame(
    [
        '套件更新',
        '更新檢查',
        ['每天', '每週', '每月', '永不'],
        '遠端通知',
    ],
    [
        $package['fields']['field'][5]['name'],
        $package['fields']['field'][6]['fielddescr'],
        array_column($package['fields']['field'][6]['options']['option'], 'name'),
        $package['fields']['field'][7]['fielddescr'],
    ],
    'Update settings and every frequency choice must use the active pfSense language.',
);
assertWebGuiLocalizationSame(
    '已測試的 pfSense 版本',
    \PfSense\Ipv6PrefixUpdater\Runtime\Translator::translate('tested_pfsense_releases'),
    'The About tab release matrix heading must use the active pfSense language.',
);
assertWebGuiLocalizationSame(
    '每個已測試版本均通過安裝、升級、前綴變更及移除測試。',
    \PfSense\Ipv6PrefixUpdater\Runtime\Translator::translate('tested_releases_footnote'),
    'The About tab release test scope must use the active pfSense language.',
);

$inputErrors = [];
pfsensePackageIpv6PrefixUpdaterValidate(
    [
        'interface0' => 'missing',
        'prefix_length0' => '72',
        'update_frequency' => 'sometimes',
        'update_check_frequency' => 'sometimes',
    ],
    $inputErrors,
);

assertWebGuiLocalizationSame(
    [
        '請選擇要監控的已設定 pfSense 介面。',
        '委派前綴長度必須介於 48 到 64。',
        '請選擇支援的套件排程。',
        '請為更新檢查選擇每天、每週、每月或永不。',
    ],
    $inputErrors,
    'WebGUI validation errors must use the active package catalog.',
);

$inputErrors = [];
pfsensePackageIpv6PrefixUpdaterValidate(
    [
        'interface0' => 'lan',
        'prefix_length0' => '56',
        'interface1' => 'lan',
        'prefix_length1' => '60',
        'update_frequency' => 'hourly',
    ],
    $inputErrors,
);

assertWebGuiLocalizationSame(
    ['每個介面只能列出一次。請移除 LAN 的多餘列。'],
    $inputErrors,
    'A monitored interface listed twice must be rejected with its description.',
);

$inputErrors = [];
pfsensePackageIpv6PrefixUpdaterValidate(
    [
        'update_frequency' => 'hourly',
    ],
    $inputErrors,
);

assertWebGuiLocalizationSame(
    ['請選擇要監控的已設定 pfSense 介面。'],
    $inputErrors,
    'Saving without any monitored interface row must be rejected.',
);

$inputErrors = [];
pfsensePackageIpv6PrefixUpdaterValidate(
    [
        'interface0' => 'lan',
        'prefix_length0' => '56',
        'update_frequency' => 'hourly',
    ],
    $inputErrors,
);

assertWebGuiLocalizationSame(
    [],
    $inputErrors,
    'Settings must save without any field group switch, which now lives on the Fields tab.',
);

assertWebGuiLocalizationSame(
    true,
    FieldSelection::hasSelection(pfsensePackageIpv6PrefixUpdaterGetFieldSelection()),
    'Before the Fields tab is saved, every Recommended group must be on.',
);

$webGuiTestFieldSelection = FieldSelection::toStorage(FieldSelection::clearAll());

assertWebGuiLocalizationSame(
    false,
    FieldSelection::hasSelection(pfsensePackageIpv6PrefixUpdaterGetFieldSelection()),
    'A saved Clear All must turn every group off.',
);

fwrite(STDOUT, "WebGUI schema and validation localization checks passed.\n");

exit(0);

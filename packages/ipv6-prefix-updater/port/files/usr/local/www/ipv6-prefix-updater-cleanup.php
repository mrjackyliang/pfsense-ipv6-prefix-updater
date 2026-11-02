<?php

declare(strict_types=1);

/*
 * IPv6 Prefix Updater version 1.x cleanup page.
 * SPDX-License-Identifier: MIT
 */

##|+PRIV
##|*IDENT=page-services-ipv6-prefix-updater-cleanup
##|*NAME=Services: IPv6 Prefix Updater: Cleanup
##|*DESCR=Allow access to the IPv6 Prefix Updater cleanup of version 1.x files and Cron entries.
##|*MATCH=ipv6-prefix-updater-cleanup.php*
##|-PRIV

use PfSense\Ipv6PrefixUpdater\Runtime\BackupStore;
use PfSense\Ipv6PrefixUpdater\Runtime\LegacyMigrator;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\PfSenseAdapter;
use PfSense\Ipv6PrefixUpdater\Runtime\StateStore;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once('guiconfig.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater/BackupStore.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/LegacyMigrator.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/PfSenseAdapter.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/StateStore.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/Translator.php');

/**
 * Translates one stored migration message.
 *
 * @param array $message - Message key and arguments.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterCleanupMessage(array $message): string
{
    $arguments = is_array($message['arguments'] ?? null) ? $message['arguments'] : [];

    if (($message['key'] ?? '') === 'legacy_step_schedule' && is_string($arguments[0] ?? null)) {
        $arguments[0] = Translator::translate($arguments[0]);
    }

    $interfaceKeys = [
        'legacy_step_monitor',
        'legacy_step_baseline',
        'legacy_step_baseline_stale',
        'legacy_step_baseline_current',
    ];

    if (in_array($message['key'] ?? '', $interfaceKeys, true) && is_string($arguments[0] ?? null)) {
        $arguments[0] = pfsensePackageIpv6PrefixUpdaterInterfaceLabel($arguments[0]);
    }

    try {
        return Translator::translate((string) ($message['key'] ?? ''), $arguments);
    } catch (Throwable) {
        return Translator::translate('unknown');
    }
}

$actionMessage = null;
$actionClass = 'info';
$actionOutput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean_up_now'])) {
    $actionOutputLines = [];

    exec(PackagePaths::COMMAND . ' --migrate-legacy 2>&1', $actionOutputLines, $returnCode);

    $actionOutput = count($actionOutputLines) > 0
        ? implode(PHP_EOL, $actionOutputLines)
        : Translator::translate('command_no_output');
    $actionMessage = ($returnCode === 0)
        ? Translator::translate('clean_up_completed')
        : Translator::translate('clean_up_warning');
    $actionClass = ($returnCode === 0) ? 'success' : 'warning';
}

$stateStore = new StateStore(PackagePaths::STATE_FILE);
$findings = null;
$report = null;
$pageError = null;

try {
    $findings = (new LegacyMigrator(
        new PfSenseAdapter(),
        $stateStore,
        new BackupStore(PackagePaths::BACKUP_DIRECTORY),
    ))->inspect();
    $report = $stateStore->read()['legacy_migration'] ?? null;
} catch (Throwable $throwable) {
    $pageError = $throwable->getMessage();
}

$pgtitle = [
    gettext('Services'),
    Translator::translate('brand'),
    Translator::translate('cleanup'),
];
$pglinks = [
    '',
    '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
    '@self',
];

include('head.inc');

display_top_tabs(pfsensePackageIpv6PrefixUpdaterTabs('cleanup'));

if (is_string($actionMessage)) {
    print_info_box(htmlspecialchars($actionMessage), $actionClass, false);
}

if (is_string($pageError)) {
    print_info_box(htmlspecialchars($pageError), 'danger', false);
}

if (is_array($findings)) {
    print_info_box(htmlspecialchars(Translator::translate(
        ($findings['found']) ? 'cleanup_intro' : 'cleanup_nothing_found',
    )), ($findings['found']) ? 'warning' : 'success', false);

    $notPresent = htmlspecialchars(Translator::translate('cleanup_not_found'));
    $rows = [];

    $cronDetails = [];

    foreach ($findings['cron_entries'] as $cronEntry) {
        $cronDetails[] = '<code>' . htmlspecialchars($cronEntry['command']) . '</code>';
    }

    $rows[] = [
        Translator::translate('cleanup_cron_entries'),
        htmlspecialchars('Services > Cron'),
        (count($cronDetails) > 0)
            ? htmlspecialchars(Translator::translate('cleanup_found_count', [count($cronDetails)])) . '<br />' . implode('<br />', $cronDetails)
            : $notPresent,
    ];

    $scriptState = $notPresent;

    if ($findings['script_directory']['present']) {
        $scriptState = htmlspecialchars(Translator::translate('cleanup_found'));

        if ($findings['script_directory']['retired_stub']) {
            $scriptState .= '<br />' . htmlspecialchars(Translator::translate('cleanup_retired_stub'));
        }
    }

    $rows[] = [
        Translator::translate('cleanup_script'),
        '<code>' . htmlspecialchars($findings['script_directory']['path']) . '</code>',
        $scriptState,
    ];

    $cacheDetails = [];

    foreach ($findings['cache_files'] as $cacheFile) {
        $cacheDetails[] = htmlspecialchars(sprintf(
            '%s → %s: %s',
            $cacheFile['device'],
            ($cacheFile['interface'] === null)
                ? Translator::translate('cleanup_unassigned')
                : pfsensePackageIpv6PrefixUpdaterInterfaceLabel($cacheFile['interface']),
            ($cacheFile['prefix'] === null)
                ? Translator::translate('cleanup_unreadable')
                : $cacheFile['prefix'] . '/' . $cacheFile['prefix_length'],
        ));
    }

    $rows[] = [
        Translator::translate('cleanup_cache'),
        '<code>' . htmlspecialchars($findings['cache_directory']['path']) . '</code>',
        ($findings['cache_directory']['present'])
            ? htmlspecialchars(Translator::translate('cleanup_found_count', [count($cacheDetails)])) . ((count($cacheDetails) > 0) ? '<br />' . implode('<br />', $cacheDetails) : '')
            : $notPresent,
    ];
    $rows[] = [
        Translator::translate('cleanup_backups'),
        '<code>' . htmlspecialchars($findings['backup_directory']['path']) . '</code>',
        ($findings['backup_directory']['present'])
            ? htmlspecialchars(Translator::translate('cleanup_found_count', [count($findings['backup_files'])]))
            : $notPresent,
    ];

    $rows[] = [
        Translator::translate('cleanup_downloads'),
        '<code>/root/pfsense-ipv6-prefix-updater-install.sh</code><br /><code>/root/pfsense-ipv6-prefix-updater.zip</code>',
        (count($findings['downloads']) > 0)
            ? htmlspecialchars(Translator::translate('cleanup_found_count', [count($findings['downloads'])]))
            : $notPresent,
    ];

    $lockState = $notPresent;

    if ($findings['lock']['present']) {
        $lockState = htmlspecialchars(Translator::translate(
            ($findings['lock']['active']) ? 'cleanup_lock_active' : 'cleanup_lock_stale',
            [(int) $findings['lock']['age_seconds']],
        ));
    }

    $rows[] = [
        Translator::translate('cleanup_lock'),
        '<code>' . htmlspecialchars($findings['script_directory']['path'] . '/update.lock') . '</code>',
        $lockState,
    ];
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('cleanup_leftovers'))?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th><?=htmlspecialchars(Translator::translate('cleanup_item'))?></th>
						<th><?=htmlspecialchars(Translator::translate('cleanup_location'))?></th>
						<th><?=htmlspecialchars(Translator::translate('cleanup_state'))?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as [$item, $location, $itemState]): ?>
					<tr>
						<td><?=htmlspecialchars($item)?></td>
						<td><?=$location?></td>
						<td><?=$itemState?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
}

$form = new Form(false);

if (is_array($report)) {
    $reportSection = new Form_Section(Translator::translate('cleanup_last_report'));
    $steps = array_map('ipv6PrefixUpdaterCleanupMessage', is_array($report['steps'] ?? null) ? $report['steps'] : []);
    $warnings = array_map('ipv6PrefixUpdaterCleanupMessage', is_array($report['warnings'] ?? null) ? $report['warnings'] : []);

    try {
        $reportStatus = Translator::translate('legacy_status_' . (string) ($report['status'] ?? 'none'));
    } catch (Throwable) {
        $reportStatus = Translator::translate('unknown');
    }

    $reportSection->addInput(new Form_StaticText(
        Translator::translate('status'),
        htmlspecialchars($reportStatus),
    ));
    $reportSection->addInput(new Form_StaticText(
        Translator::translate('cleanup_recorded_at'),
        htmlspecialchars((string) ($report['recorded_at'] ?? Translator::translate('unknown'))),
    ));

    if (count($steps) > 0) {
        $reportSection->addInput(new Form_StaticText(
            Translator::translate('cleanup_steps'),
            implode('<br />', array_map('htmlspecialchars', $steps)),
        ));
    }

    if (count($warnings) > 0) {
        $reportSection->addInput(new Form_StaticText(
            Translator::translate('cleanup_warnings'),
            implode('<br />', array_map('htmlspecialchars', $warnings)),
        ));
    }

    $form->add($reportSection);
}

$actionSection = new Form_Section(Translator::translate('actions'));
$cleanUpButton = new Form_Button(
    'clean_up_now',
    Translator::translate('clean_up_now'),
    null,
    'fa-solid fa-eraser',
);
$cleanUpConfirmation = json_encode(
    Translator::translate('clean_up_confirm'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);

if (!is_string($cleanUpConfirmation)) {
    $cleanUpConfirmation = '""';
}

$cleanUpButton->removeClass('btn-primary')->addClass('btn-warning');
$cleanUpButton->setAttribute('onclick', 'return confirm(' . $cleanUpConfirmation . ');');

if (!is_array($findings) || $findings['found'] === false) {
    $cleanUpButton->setAttribute('disabled', true);
}

$cleanUpGroup = new Form_Group(Translator::translate('cleanup'));

$cleanUpGroup->add($cleanUpButton);
$cleanUpGroup->setHelp(Translator::translate('clean_up_help'));
$actionSection->add($cleanUpGroup);
$form->add($actionSection);

if (is_string($actionOutput)) {
    $outputSection = new Form_Section(Translator::translate('command_output'));
    $outputTextarea = new Form_Textarea(
        'action_output',
        Translator::translate('latest_run'),
        $actionOutput,
    );

    $outputTextarea->setWidth(9);
    $outputTextarea->setRows(12);
    $outputTextarea->setNoWrap();
    $outputTextarea->setAttribute('readonly', true);
    $outputSection->addInput($outputTextarea);
    $form->add($outputSection);
}

print($form);

include('foot.inc');

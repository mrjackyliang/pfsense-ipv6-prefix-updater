<?php

declare(strict_types=1);

/*
 * IPv6 Prefix Updater status and manual-run page.
 * SPDX-License-Identifier: MIT
 */

##|+PRIV
##|*IDENT=page-services-ipv6-prefix-updater-status
##|*NAME=Services: IPv6 Prefix Updater: Status
##|*DESCR=Allow access to the IPv6 Prefix Updater status and manual run page.
##|*MATCH=ipv6-prefix-updater-status.php*
##|-PRIV

use PfSense\Ipv6PrefixUpdater\Domain\MonitoredInterfaces;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\StateStore;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once('guiconfig.inc');
require_once('pfsense-utils.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater/MonitoredInterfaces.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/StateStore.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/Translator.php');

/**
 * Translates a stored result summary, keeping the saved text for unknown keys.
 *
 * @param array $result - Stored run result.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterStatusSummary(array $result): string
{
    $summary = (string) ($result['message'] ?? Translator::translate('no_summary'));
    $messageKey = $result['message_key'] ?? null;
    $messageArguments = $result['message_arguments'] ?? [];

    if (is_string($messageKey) && is_array($messageArguments)) {
        try {
            $summary = Translator::translate($messageKey, $messageArguments);
        } catch (Throwable) {
            // Retain the stored safe summary when an older or newer key is unavailable.
        }
    }

    return $summary;
}

/**
 * Translates a stored result status.
 *
 * @param array $result - Stored run result.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterStatusOutcome(array $result): string
{
    try {
        return Translator::translate('status_' . (string) ($result['status'] ?? 'unknown'));
    } catch (Throwable) {
        return Translator::translate('unknown');
    }
}

/**
 * Describes one package re-apply recorded with a run.
 *
 * @param array $outcome - Package re-apply outcome.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterStatusPackageOutcome(array $outcome): string
{
    $section = (string) ($outcome['section'] ?? '');
    $package = (string) ($outcome['package'] ?? $section);

    return match ($outcome['status'] ?? null) {
        'reapplied' => Translator::translate('package_reapplied', [$package]),
        'unknown_owner' => Translator::translate('package_owner_unknown', [$section]),
        default => Translator::translate('package_reapply_failed', [$package, (string) ($outcome['error'] ?? '')]),
    };
}

$actionMessage = null;
$actionClass = 'info';
$actionOutput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['run_now'])) {
        $actionOutputLines = [];

        exec('/usr/local/sbin/ipv6-prefix-updater --manual 2>&1', $actionOutputLines, $returnCode);

        $actionOutput = count($actionOutputLines) > 0
            ? implode(PHP_EOL, $actionOutputLines)
            : Translator::translate('command_no_output');
        $actionMessage = ($returnCode === 0)
            ? Translator::translate('manual_completed')
            : Translator::translate('manual_warning');
        $actionClass = ($returnCode === 0) ? 'success' : 'warning';
    } elseif (isset($_POST['dry_run'])) {
        $actionOutputLines = [];

        exec('/usr/local/sbin/ipv6-prefix-updater --dry-run 2>&1', $actionOutputLines, $returnCode);

        $actionOutput = count($actionOutputLines) > 0
            ? implode(PHP_EOL, $actionOutputLines)
            : Translator::translate('command_no_output');
        $actionMessage = ($returnCode === 0)
            ? Translator::translate('dry_run_completed')
            : Translator::translate('dry_run_warning');
        $actionClass = ($returnCode === 0) ? 'success' : 'warning';
    } elseif (isset($_POST['accept_current_prefix'])) {
        $actionOutputLines = [];

        exec('/usr/local/sbin/ipv6-prefix-updater --accept-current-prefix 2>&1', $actionOutputLines, $returnCode);

        $actionOutput = count($actionOutputLines) > 0
            ? implode(PHP_EOL, $actionOutputLines)
            : Translator::translate('command_no_output');
        $actionMessage = ($returnCode === 0)
            ? Translator::translate('accept_current_prefix_completed')
            : Translator::translate('accept_current_prefix_warning');
        $actionClass = ($returnCode === 0) ? 'success' : 'warning';
    }
}

$stateStore = new StateStore(PackagePaths::STATE_FILE);
$state = null;
$stateError = null;

try {
    $state = $stateStore->read();
} catch (Throwable $throwable) {
    $stateError = $throwable->getMessage();
}

$targetStates = is_array($state) ? $state['targets'] : [];
$monitoredInterfaces = [];

foreach (MonitoredInterfaces::fromSettings(pfsensePackageIpv6PrefixUpdaterGetSettings()) as $row) {
    if (is_string($row['interface']) && $row['interface'] !== '') {
        $monitoredInterfaces[] = $row['interface'];
    }
}

$lastRun = is_array($state) ? ($state['last_run'] ?? null) : null;

$pgtitle = [
    gettext('Services'),
    Translator::translate('brand'),
    Translator::translate('status'),
];
$pglinks = [
    '',
    '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
    '@self',
];

include('head.inc');

display_top_tabs(pfsensePackageIpv6PrefixUpdaterTabs('status'));

if (is_string($actionMessage)) {
    print_info_box(htmlspecialchars($actionMessage), $actionClass, false);
}

if (is_string($stateError)) {
    print_info_box(htmlspecialchars($stateError), 'danger', false);
}
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('current_state'))?></h2></div>
	<div class="panel-body">
<?php if (count($monitoredInterfaces) === 0): ?>
		<p class="text-muted" style="padding: 10px;"><?=htmlspecialchars(Translator::translate('no_monitored_interfaces'))?></p>
<?php else: ?>
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th><?=htmlspecialchars(Translator::translate('monitored_interface'))?></th>
						<th><?=htmlspecialchars(Translator::translate('last_successful_prefix'))?></th>
						<th><?=htmlspecialchars(Translator::translate('last_successful_source_address'))?></th>
						<th><?=htmlspecialchars(Translator::translate('last_successful_update'))?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($monitoredInterfaces as $monitoredInterface): ?>
<?php
    $targetState = $targetStates[$monitoredInterface] ?? null;
    $prefixDisplay = Translator::translate('not_initialized');

    if (is_array($targetState) && is_string($targetState['prefix'] ?? null)) {
        $prefixDisplay = $targetState['prefix'] . (is_int($targetState['prefix_length'] ?? null) ? '/' . $targetState['prefix_length'] : '');
    }
?>
					<tr>
						<td><?=htmlspecialchars(pfsensePackageIpv6PrefixUpdaterInterfaceLabel($monitoredInterface))?></td>
						<td><?=htmlspecialchars($prefixDisplay)?></td>
						<td><?=htmlspecialchars(is_array($targetState) && is_string($targetState['source_address'] ?? null) ? $targetState['source_address'] : Translator::translate('not_recorded'))?></td>
						<td><?=htmlspecialchars(is_array($targetState) ? (string) ($targetState['updated_at'] ?? Translator::translate('unknown')) : '')?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>
</div>
<?php

$form = new Form(false);
$runSection = new Form_Section(Translator::translate('last_run'));

if (is_array($lastRun)) {
    $observedAddress = (string) ($lastRun['observed_address'] ?? Translator::translate('not_recorded'));
    $currentPrefix = (string) ($lastRun['current_prefix'] ?? Translator::translate('unknown'));
    $currentPrefixLength = (string) ($lastRun['current_prefix_length'] ?? '');
    $currentPrefixDisplay = $currentPrefixLength === ''
        ? $currentPrefix
        : $currentPrefix . '/' . $currentPrefixLength;
    $services = $lastRun['services'] ?? [];
    $serviceDisplay = is_array($services) && count($services) > 0
        ? implode(', ', array_map(
            static function (mixed $service): string {
                $serviceKey = 'service_' . (string) $service;

                try {
                    return Translator::translate($serviceKey);
                } catch (Throwable) {
                    return (string) $service;
                }
            },
            $services,
        ))
        : Translator::translate('none');
    $backupCreated = $lastRun['backup_created'] ?? null;
    $backupDisplay = Translator::translate('not_recorded');

    if (is_bool($backupCreated)) {
        $backupDisplay = ($backupCreated)
            ? Translator::translate('yes')
            : Translator::translate('no_no_write');
    }

    $runSection->addInput(new Form_StaticText(
        Translator::translate('outcome'),
        htmlspecialchars(ipv6PrefixUpdaterStatusOutcome($lastRun)),
    ));
    $runSection->addInput(new Form_StaticText(
        Translator::translate('timestamp_utc'),
        htmlspecialchars((string) ($lastRun['timestamp'] ?? Translator::translate('unknown'))),
    ));

    if (!is_array($lastRun['targets'] ?? null)) {
        $runSection->addInput(new Form_StaticText(
            Translator::translate('observed_source_address'),
            htmlspecialchars($observedAddress),
        ));
        $runSection->addInput(new Form_StaticText(
            Translator::translate('observed_prefix'),
            htmlspecialchars($currentPrefixDisplay),
        ));
    }

    $runSection->addInput(new Form_StaticText(
        Translator::translate('changed_fields'),
        htmlspecialchars((string) ($lastRun['change_count'] ?? 0)),
    ));
    $runSection->addInput(new Form_StaticText(
        Translator::translate('configuration_backup_created'),
        htmlspecialchars($backupDisplay),
    ));
    $runSection->addInput(new Form_StaticText(
        Translator::translate('reloaded_services'),
        htmlspecialchars($serviceDisplay),
    ));
    $runSection->addInput(new Form_StaticText(
        Translator::translate('summary'),
        htmlspecialchars(ipv6PrefixUpdaterStatusSummary($lastRun)),
    ));

    $packageOutcomes = is_array($lastRun['package_reapply'] ?? null) ? $lastRun['package_reapply'] : [];
    $skippedFields = is_array($lastRun['skipped_fields'] ?? null) ? $lastRun['skipped_fields'] : [];

    if (count($packageOutcomes) > 0) {
        $packageLines = array_map(
            static fn (mixed $packageOutcome): string => htmlspecialchars(
                is_array($packageOutcome) ? ipv6PrefixUpdaterStatusPackageOutcome($packageOutcome) : '',
            ),
            $packageOutcomes,
        );

        $runSection->addInput(new Form_StaticText(
            Translator::translate('last_run_packages'),
            implode('<br>', $packageLines),
        ));
    }

    if (count($skippedFields) > 0) {
        $skippedLines = array_map(
            static fn (mixed $skippedField): string => '<code>' . htmlspecialchars((string) $skippedField) . '</code>',
            $skippedFields,
        );

        $runSection->addInput(new Form_StaticText(
            Translator::translate('last_run_skipped_fields'),
            htmlspecialchars(Translator::translate('last_run_skipped_fields_help')) . '<br>' . implode('<br>', $skippedLines),
        ));
    }
} else {
    $runSection->addInput(new Form_StaticText(
        Translator::translate('outcome'),
        Translator::translate('no_run'),
    ));
}

$form->add($runSection);

print($form);

if (is_array($lastRun) && is_array($lastRun['targets'] ?? null)):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('last_run_by_interface'))?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th><?=htmlspecialchars(Translator::translate('monitored_interface'))?></th>
						<th><?=htmlspecialchars(Translator::translate('outcome'))?></th>
						<th><?=htmlspecialchars(Translator::translate('observed_prefix'))?></th>
						<th><?=htmlspecialchars(Translator::translate('changed_fields'))?></th>
						<th><?=htmlspecialchars(Translator::translate('summary'))?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($lastRun['targets'] as $targetResult): ?>
<?php
    if (!is_array($targetResult)) {
        continue;
    }

    $targetPrefix = (string) ($targetResult['current_prefix'] ?? '');
    $targetPrefixLength = $targetResult['current_prefix_length'] ?? null;
?>
					<tr>
						<td><?=htmlspecialchars(pfsensePackageIpv6PrefixUpdaterInterfaceLabel((string) ($targetResult['interface'] ?? '')))?></td>
						<td><?=htmlspecialchars(ipv6PrefixUpdaterStatusOutcome($targetResult))?></td>
						<td><?=htmlspecialchars($targetPrefix === '' ? Translator::translate('unknown') : $targetPrefix . (is_int($targetPrefixLength) ? '/' . $targetPrefixLength : ''))?></td>
						<td><?=htmlspecialchars((string) ($targetResult['change_count'] ?? 0))?></td>
						<td><?=htmlspecialchars(ipv6PrefixUpdaterStatusSummary($targetResult))?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
endif;

$form = new Form(false);
$actionSection = new Form_Section(Translator::translate('actions'));
$actionGroup = new Form_Group(Translator::translate('run_prefix_check'));
$runButton = new Form_Button(
    'run_now',
    Translator::translate('run_now'),
    null,
    'fa-solid fa-play',
);
$dryRunButton = new Form_Button(
    'dry_run',
    Translator::translate('dry_run'),
    null,
    'fa-solid fa-search',
);

$runButton->addClass('btn-success');
$dryRunButton->removeClass('btn-primary')->addClass('btn-info');
$actionGroup->add($runButton);
$actionGroup->add($dryRunButton);
$actionGroup->setHelp(Translator::translate('run_help'));
$actionSection->add($actionGroup);

$recoveryGroup = new Form_Group(Translator::translate('baseline_recovery'));
$acceptButton = new Form_Button(
    'accept_current_prefix',
    Translator::translate('accept_current_prefix'),
    null,
    'fa-solid fa-check-circle',
);
$acceptConfirmation = json_encode(
    Translator::translate('accept_current_prefix_confirm'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);

if (!is_string($acceptConfirmation)) {
    $acceptConfirmation = '""';
}

$acceptButton->removeClass('btn-primary')->addClass('btn-warning');
$acceptButton->setAttribute('onclick', 'return confirm(' . $acceptConfirmation . ');');
$recoveryGroup->add($acceptButton);
$recoveryGroup->setHelp(Translator::translate('accept_current_prefix_help'));
$actionSection->add($recoveryGroup);
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

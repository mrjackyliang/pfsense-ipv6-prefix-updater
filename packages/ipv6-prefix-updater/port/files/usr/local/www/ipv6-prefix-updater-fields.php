<?php

declare(strict_types=1);

/*
 * IPv6 Prefix Updater field selection page.
 * SPDX-License-Identifier: MIT
 */

##|+PRIV
##|*IDENT=page-services-ipv6-prefix-updater-fields
##|*NAME=Services: IPv6 Prefix Updater: Fields
##|*DESCR=Allow access to the IPv6 Prefix Updater field selection.
##|*MATCH=ipv6-prefix-updater-fields.php*
##|-PRIV

use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationFieldDetector;
use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;
use PfSense\Ipv6PrefixUpdater\Domain\MonitoredInterfaces;
use PfSense\Ipv6PrefixUpdater\Runtime\FileLock;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\StateStore;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once('guiconfig.inc');
require_once('pfsense-utils.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater/ConfigurationFieldDetector.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/FieldSelection.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/FileLock.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/MonitoredInterfaces.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/StateStore.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/Translator.php');

/**
 * Returns the saved baseline of each monitored interface.
 *
 * Only monitored interfaces count, so a baseline left behind by a removed row can't
 * pull unrelated addresses into the list.
 *
 * @return list<array{interface: string, prefix: mixed, prefix_length: mixed}>
 *
 * @throws Throwable When package state can't be read.
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterFieldsBaselines(): array
{
    $state = (new StateStore(PackagePaths::STATE_FILE))->read();
    $targetStates = is_array($state['targets'] ?? null) ? $state['targets'] : [];
    $baselines = [];

    foreach (MonitoredInterfaces::fromSettings(pfsensePackageIpv6PrefixUpdaterGetSettings()) as $row) {
        $interface = $row['interface'];
        $targetState = is_string($interface) ? ($targetStates[$interface] ?? null) : null;

        if (!is_array($targetState)) {
            continue;
        }

        $baselines[] = [
            'interface' => $interface,
            'prefix' => $targetState['prefix'] ?? null,
            'prefix_length' => $targetState['prefix_length'] ?? null,
        ];
    }

    return $baselines;
}

/**
 * Lists the configuration fields for the current selection.
 *
 * @param array $selection - Field selection.
 *
 * @return array
 *
 * @throws Throwable When package state can't be read.
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterFieldsDetect(array $selection): array
{
    $configuration = config_get_path('', []);

    return ConfigurationFieldDetector::detect(
        is_array($configuration) ? $configuration : [],
        ipv6PrefixUpdaterFieldsBaselines(),
        $selection,
    );
}

/**
 * Returns the title and description of a field group.
 *
 * A package group is named after the installed package that owns its settings, and
 * says so when no installed package does. Other Settings has a fixed name.
 *
 * @param string $group - Field group.
 *
 * @return array{title: string, help: string}
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterFieldsGroupText(string $group): array
{
    $groupKeys = [
        'interfaces' => ['static_interfaces', 'static_interfaces_help'],
        'virtual_ips' => ['virtual_ip_addresses', 'virtual_ip_addresses_help'],
        'aliases' => ['aliases', 'aliases_help'],
        'firewall_rules' => ['firewall_rules', 'firewall_rules_help'],
        'nat' => ['nat_npt', 'nat_npt_help'],
        'dhcpv6' => ['dhcpv6_server', 'dhcpv6_server_help'],
        'router_advertisements' => ['router_advertisements', 'router_advertisements_help'],
        'dns_host_overrides' => ['dns_host_overrides', 'dns_host_overrides_help'],
        'ipsec' => ['ipsec', 'ipsec_help'],
        'openvpn' => ['openvpn', 'openvpn_help'],
        'wireguard' => ['wireguard', 'wireguard_help'],
    ];

    if (isset($groupKeys[$group])) {
        return [
            'title' => Translator::translate($groupKeys[$group][0]),
            'help' => Translator::translate($groupKeys[$group][1]),
        ];
    }

    if ($group === FieldSelection::OTHER_GROUP) {
        return [
            'title' => Translator::translate('other_settings'),
            'help' => Translator::translate('other_settings_help'),
        ];
    }

    $section = substr($group, strlen('package:'));
    $owner = pfsensePackageIpv6PrefixUpdaterPackageOwner($section);

    return [
        'title' => Translator::translate('fields_package_group', [$owner ?? $section]),
        'help' => ($owner === null)
            ? Translator::translate('fields_package_owner_unknown')
            : Translator::translate('fields_package_help', [$owner]),
    ];
}

/**
 * Returns a row's value as HTML, with the prefix bits of each address marked.
 *
 * @param array $row - Fields tab row.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterFieldsValueHtml(array $row): string
{
    if ($row['hidden']) {
        return '<em class="text-muted">' . htmlspecialchars(Translator::translate('fields_hidden')) . '</em>';
    }

    $html = '';

    foreach ($row['segments'] as $segment) {
        $text = htmlspecialchars($segment['text']);
        $html .= ($segment['prefix']) ? '<mark>' . $text . '</mark>' : $text;
    }

    return '<code>' . $html . '</code>';
}

/**
 * Returns the label of a row's field, such as "Destination".
 *
 * @param array $row - Fields tab row.
 *
 * @return string
 *
 * @since 2.0.0
 */
function ipv6PrefixUpdaterFieldsLabel(array $row): string
{
    if (is_string($row['label'])) {
        try {
            return Translator::translate($row['label']);
        } catch (Throwable) {
            return $row['label'];
        }
    }

    return (string) ($row['label_text'] ?? '');
}

$selection = pfsensePackageIpv6PrefixUpdaterGetFieldSelection();
$actionMessage = null;
$actionClass = 'info';
$detected = null;
$detectError = null;

try {
    $detected = ipv6PrefixUpdaterFieldsDetect($selection);
} catch (Throwable $throwable) {
    $detectError = $throwable->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($detected)) {
    $updatedSelection = null;

    if (isset($_POST['fields_forget_missing'])) {
        $missingLookup = array_fill_keys($detected['missing'], true);
        $updatedSelection = $selection;
        $updatedSelection['include'] = array_values(array_filter(
            $selection['include'],
            static fn (string $includedField): bool => isset($missingLookup[$includedField]) === false,
        ));
    } elseif (isset($_POST['fields_save'])) {
        $postedGroups = is_array($_POST['group'] ?? null) ? $_POST['group'] : [];
        $groupSwitches = [];
        $offeredFields = [];

        // Only groups and fields shown on this page change. Choices for fields whose
        // value has left the monitored prefixes are kept.
        foreach ($detected['groups'] as $group => $detectedGroup) {
            $groupSwitches[$group] = isset($postedGroups[$group]);

            foreach ($detectedGroup['rows'] as $row) {
                if (is_string($row['field_id'])) {
                    $offeredFields[$row['field_id']] = $group;
                }
            }
        }

        $updatedSelection = FieldSelection::applyChoices(
            $selection,
            $groupSwitches,
            $offeredFields,
            is_array($_POST['field'] ?? null) ? $_POST['field'] : [],
        );
    }

    if ($updatedSelection !== null) {
        $fileLock = new FileLock(PackagePaths::LOCK_FILE);

        try {
            if ($fileLock->acquire() === false) {
                $actionMessage = Translator::translate('fields_locked');
                $actionClass = 'warning';
            } else {
                try {
                    pfsensePackageIpv6PrefixUpdaterSaveFieldSelection($updatedSelection);
                } finally {
                    $fileLock->release();
                }

                $selection = $updatedSelection;
                $actionMessage = Translator::translate('fields_saved');
                $actionClass = 'success';
                $detected = ipv6PrefixUpdaterFieldsDetect($selection);
            }
        } catch (Throwable $throwable) {
            $actionMessage = $throwable->getMessage();
            $actionClass = 'danger';
        }
    }
}

$rowCount = 0;
$prefixLabels = [];

foreach (is_array($detected) ? $detected['groups'] : [] as $detectedGroup) {
    $rowCount += count($detectedGroup['rows']);
}

foreach (is_array($detected) ? $detected['prefixes'] : [] as $detectedPrefix) {
    $interfaceLabels = array_map(
        static fn (string $interface): string => pfsensePackageIpv6PrefixUpdaterInterfaceLabel($interface),
        $detectedPrefix['interfaces'],
    );
    $prefixLabels[] = sprintf(
        '%s/%d (%s)',
        $detectedPrefix['prefix'],
        $detectedPrefix['prefix_length'],
        implode(', ', $interfaceLabels),
    );
}

$collapseGroups = $rowCount > 40;
$countTemplate = json_encode(
    Translator::translate('fields_selected_count', [1001, 1002]),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);

$pgtitle = [
    gettext('Services'),
    Translator::translate('brand'),
    Translator::translate('fields'),
];
$pglinks = [
    '',
    '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
    '@self',
];

include('head.inc');

display_top_tabs(pfsensePackageIpv6PrefixUpdaterTabs('fields'));

if (is_string($actionMessage)) {
    print_info_box(htmlspecialchars($actionMessage), $actionClass, false);
}

if (is_string($detectError)) {
    print_info_box(htmlspecialchars(Translator::translate('fields_failed', [$detectError])), 'danger', false);
}

if (FieldSelection::hasSelection($selection) === false) {
    print_info_box(htmlspecialchars(Translator::translate('fields_nothing_selected')), 'warning', false);
}

if (is_array($detected)):
?>
<form method="post" id="ipu-fields">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('fields'))?></h2></div>
		<div class="panel-body" style="padding: 10px;">
			<p><?=htmlspecialchars(Translator::translate('fields_intro'))?></p>
<?php if (count($prefixLabels) > 0): ?>
			<p class="text-muted"><?=htmlspecialchars(Translator::translate('fields_baselines', [implode('; ', $prefixLabels)]))?></p>
<?php else: ?>
			<p class="text-muted"><?=htmlspecialchars(Translator::translate('fields_no_baseline'))?></p>
<?php endif; ?>
			<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px;">
				<div>
					<button type="button" class="btn btn-success btn-sm" id="ipu-auto-select">
						<i class="fa fa-solid fa-magic icon-embed-btn"></i>
						<?=htmlspecialchars(Translator::translate('fields_auto_select'))?>
					</button>
					<button type="button" class="btn btn-danger btn-sm" id="ipu-clear-all">
						<i class="fa fa-solid fa-times icon-embed-btn"></i>
						<?=htmlspecialchars(Translator::translate('fields_clear_all'))?>
					</button>
				</div>
				<div class="form-inline">
					<input type="search" class="form-control input-sm" id="ipu-search" style="height: 30px;" placeholder="<?=htmlspecialchars(Translator::translate('fields_search'))?>" aria-label="<?=htmlspecialchars(Translator::translate('fields_search'))?>" />
					<select class="form-control input-sm" id="ipu-filter" aria-label="<?=htmlspecialchars(Translator::translate('fields_filter_all'))?>">
						<option value="all"><?=htmlspecialchars(Translator::translate('fields_filter_all'))?></option>
						<option value="selected"><?=htmlspecialchars(Translator::translate('fields_filter_selected'))?></option>
						<option value="needs_review"><?=htmlspecialchars(Translator::translate('fields_filter_packages'))?></option>
					</select>
				</div>
			</div>
			<p class="help-block"><?=htmlspecialchars(Translator::translate('fields_auto_select_help'))?></p>
		</div>
	</div>
<?php if (count($detected['missing']) > 0): ?>
	<div class="panel panel-warning">
		<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('fields_missing'))?> <span class="badge"><?=count($detected['missing'])?></span></h2></div>
		<div class="panel-body" style="padding: 10px;">
			<p><?=htmlspecialchars(Translator::translate('fields_missing_help'))?></p>
			<ul>
<?php foreach ($detected['missing'] as $missingField): ?>
				<li><code><?=htmlspecialchars($missingField)?></code></li>
<?php endforeach; ?>
			</ul>
			<button type="submit" class="btn btn-warning btn-sm" name="fields_forget_missing" value="1">
				<i class="fa fa-solid fa-trash icon-embed-btn"></i>
				<?=htmlspecialchars(Translator::translate('fields_forget_missing'))?>
			</button>
		</div>
	</div>
<?php endif; ?>
<?php
$groupNumber = 0;

foreach ($detected['groups'] as $group => $detectedGroup):
    $groupNumber++;
    $groupText = ipv6PrefixUpdaterFieldsGroupText($group);
    $groupExpanded = count($detectedGroup['rows']) > 0 && $collapseGroups === false;
?>
	<div class="panel panel-default ipu-group" data-group="<?=htmlspecialchars($group)?>">
		<div class="panel-heading">
			<h2 class="panel-title">
				<input type="checkbox" class="ipu-group-switch" name="group[<?=htmlspecialchars($group)?>]" value="on" data-group="<?=htmlspecialchars($group)?>" data-kind="<?=htmlspecialchars($detectedGroup['kind'])?>"<?=($detectedGroup['enabled']) ? ' checked' : ''?> aria-label="<?=htmlspecialchars($groupText['title'])?>" />
				<a data-toggle="collapse" href="#ipu-group-<?=$groupNumber?>" style="color: inherit;"><?=htmlspecialchars($groupText['title'])?></a>
				<span class="pull-right ipu-count" data-group="<?=htmlspecialchars($group)?>"><?=htmlspecialchars(Translator::translate('fields_selected_count', [$detectedGroup['selected'], $detectedGroup['total']]))?></span>
			</h2>
		</div>
		<div class="panel-body collapse<?=($groupExpanded) ? ' in' : ''?>" id="ipu-group-<?=$groupNumber?>">
			<p class="text-muted" style="padding: 10px 10px 0;"><?=htmlspecialchars($groupText['help'])?></p>
<?php if (count($detectedGroup['rows']) === 0): ?>
			<p class="text-muted" style="padding: 0 10px 10px;"><?=htmlspecialchars(Translator::translate('fields_group_empty'))?></p>
<?php else: ?>
			<div class="table-responsive">
				<table class="table table-striped table-hover table-condensed">
					<thead>
						<tr>
							<th style="width: 4%;"></th>
							<th style="width: 50%;"><?=htmlspecialchars(Translator::translate('fields_column_field'))?></th>
							<th style="width: 46%;"><?=htmlspecialchars(Translator::translate('fields_column_value'))?></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($detectedGroup['rows'] as $row): ?>
<?php
    $rowLabel = ipv6PrefixUpdaterFieldsLabel($row);
    $rowValue = implode('', array_column($row['segments'], 'text'));
    $rowSearch = implode(' ', [$groupText['title'], (string) $row['record'], $rowLabel, $rowValue, ...$row['paths']]);
?>
						<tr class="ipu-row" data-group="<?=htmlspecialchars($group)?>" data-kind="<?=htmlspecialchars($row['kind'])?>" data-selected="<?=($row['selected']) ? '1' : '0'?>" data-search="<?=htmlspecialchars($rowSearch)?>">
							<td>
<?php if (is_string($row['field_id'])): ?>
								<input type="checkbox" class="ipu-field" name="field[]" value="<?=htmlspecialchars($row['field_id'])?>" data-group="<?=htmlspecialchars($group)?>"<?=($row['selected']) ? ' checked' : ''?> aria-label="<?=htmlspecialchars(trim((string) $row['record'] . ' ' . $rowLabel))?>" />
<?php else: ?>
								<i class="fa fa-solid fa-link text-muted" title="<?=htmlspecialchars(Translator::translate('fields_no_identity'))?>"></i>
<?php endif; ?>
							</td>
							<td>
								<strong><?=htmlspecialchars((string) $row['record'])?></strong> <?=htmlspecialchars($rowLabel)?>
								<br><small class="text-muted"><?=htmlspecialchars(implode(', ', $row['paths']))?></small>
<?php if (is_string($row['field_id']) === false): ?>
								<br><small class="text-muted"><?=htmlspecialchars(Translator::translate('fields_no_identity'))?></small>
<?php endif; ?>
							</td>
							<td><?=ipv6PrefixUpdaterFieldsValueHtml($row)?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>
	<nav class="action-buttons">
		<button type="submit" class="btn btn-primary btn-sm" name="fields_save" value="1">
			<i class="fa fa-solid fa-save icon-embed-btn"></i>
			<?=htmlspecialchars(Translator::translate('fields_save'))?>
		</button>
	</nav>
</form>
<script type="text/javascript">
//<![CDATA[
(function () {
	var form = document.getElementById('ipu-fields');
	var countTemplate = <?=$countTemplate?>;

	function groupRows(group) {
		return form.querySelectorAll('tr.ipu-row[data-group="' + group + '"]');
	}

	function refreshGroup(group) {
		var groupSwitch = form.querySelector('input.ipu-group-switch[data-group="' + group + '"]');
		var count = form.querySelector('.ipu-count[data-group="' + group + '"]');
		var rows = groupRows(group);
		var selected = 0;
		var differs = false;

		if (groupSwitch === null) {
			return;
		}

		rows.forEach(function (row) {
			var field = row.querySelector('input.ipu-field');
			var rowSelected = (field === null) ? groupSwitch.checked : field.checked;

			if (field !== null && field.checked !== groupSwitch.checked) {
				differs = true;
			}

			row.setAttribute('data-selected', (rowSelected) ? '1' : '0');
			selected += (rowSelected) ? 1 : 0;
		});

		groupSwitch.indeterminate = differs;

		if (count !== null) {
			count.textContent = countTemplate.replace('1001', String(selected)).replace('1002', String(rows.length));
		}
	}

	function applyFilters() {
		var term = document.getElementById('ipu-search').value.trim().toLowerCase();
		var filter = document.getElementById('ipu-filter').value;
		var filtering = term !== '' || filter !== 'all';

		form.querySelectorAll('.ipu-group').forEach(function (panel) {
			var visibleRows = 0;
			var body = panel.querySelector('.panel-body');

			panel.querySelectorAll('tr.ipu-row').forEach(function (row) {
				var matchesTerm = term === '' || row.getAttribute('data-search').toLowerCase().indexOf(term) !== -1;
				var matchesFilter = filter === 'all'
					|| (filter === 'selected' && row.getAttribute('data-selected') === '1')
					|| (filter === row.getAttribute('data-kind'));
				var visible = matchesTerm && matchesFilter;

				row.style.display = (visible) ? '' : 'none';
				visibleRows += (visible) ? 1 : 0;
			});

			panel.style.display = (filtering && visibleRows === 0) ? 'none' : '';

			if (filtering && visibleRows > 0 && body !== null) {
				body.classList.add('in');
			}
		});
	}

	form.querySelectorAll('input.ipu-group-switch').forEach(function (groupSwitch) {
		var group = groupSwitch.getAttribute('data-group');

		groupSwitch.addEventListener('change', function () {
			groupRows(group).forEach(function (row) {
				var field = row.querySelector('input.ipu-field');

				if (field !== null) {
					field.checked = groupSwitch.checked;
				}
			});

			refreshGroup(group);
			applyFilters();
		});

		refreshGroup(group);
	});

	form.querySelectorAll('input.ipu-field').forEach(function (field) {
		field.addEventListener('change', function () {
			refreshGroup(field.getAttribute('data-group'));
			applyFilters();
		});
	});

	// Both buttons only set the switches and ticks on this page, so Save keeps them.
	function setGroups(selector, checked) {
		form.querySelectorAll(selector).forEach(function (groupSwitch) {
			groupSwitch.checked = checked;
			groupSwitch.dispatchEvent(new Event('change'));
		});
	}

	document.getElementById('ipu-auto-select').addEventListener('click', function () {
		setGroups('input.ipu-group-switch[data-kind="recommended"]', true);
	});
	document.getElementById('ipu-clear-all').addEventListener('click', function () {
		setGroups('input.ipu-group-switch', false);
	});
	document.getElementById('ipu-search').addEventListener('input', applyFilters);
	document.getElementById('ipu-filter').addEventListener('change', applyFilters);
})();
//]]>
</script>
<?php
endif;

include('foot.inc');

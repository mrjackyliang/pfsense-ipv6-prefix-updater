<?php

declare(strict_types=1);

/*
 * IPv6 Prefix Updater configuration backups page.
 * SPDX-License-Identifier: MIT
 */

##|+PRIV
##|*IDENT=page-services-ipv6-prefix-updater-backups
##|*NAME=Services: IPv6 Prefix Updater: Backups
##|*DESCR=Allow access to the IPv6 Prefix Updater configuration backups, including downloads.
##|*MATCH=ipv6-prefix-updater-backups.php*
##|-PRIV

use PfSense\Ipv6PrefixUpdater\Runtime\BackupStore;
use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;

require_once('guiconfig.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater/BackupStore.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/Translator.php');

$backupStore = new BackupStore(PackagePaths::BACKUP_DIRECTORY);
$actionMessage = null;
$actionClass = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backupId = (string) ($_POST['backup_id'] ?? '');

    try {
        if (isset($_POST['download'])) {
            send_user_download(
                'file',
                $backupStore->path($backupId),
                'config-ipv6-prefix-updater-' . $backupId . '.xml',
                'application/xml',
            );

            exit;
        }

        if (isset($_POST['delete'])) {
            $backupStore->delete($backupId);

            $actionMessage = Translator::translate('backup_deleted');
            $actionClass = 'success';
        }
    } catch (Throwable $throwable) {
        $actionMessage = $throwable->getMessage();
        $actionClass = 'danger';
    }
}

$backups = $backupStore->list();
$totalSize = array_sum(array_column($backups, 'size'));
$deleteConfirmation = json_encode(
    Translator::translate('backup_delete_confirm'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);

if (!is_string($deleteConfirmation)) {
    $deleteConfirmation = '""';
}

$pgtitle = [
    gettext('Services'),
    Translator::translate('brand'),
    Translator::translate('backups'),
];
$pglinks = [
    '',
    '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
    '@self',
];

include('head.inc');

display_top_tabs(pfsensePackageIpv6PrefixUpdaterTabs('backups'));

if (is_string($actionMessage)) {
    print_info_box(htmlspecialchars($actionMessage), $actionClass, false);
}

print_info_box(htmlspecialchars(Translator::translate('backups_help', [BackupStore::KEEP_PACKAGE_BACKUPS])), 'info', false);
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(Translator::translate('backup_list'))?></h2></div>
	<div class="panel-body">
<?php if (count($backups) === 0): ?>
		<p class="text-muted" style="padding: 10px;"><?=htmlspecialchars(Translator::translate('no_backups'))?></p>
<?php else: ?>
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th><?=htmlspecialchars(Translator::translate('backup_created_at'))?></th>
						<th><?=htmlspecialchars(Translator::translate('backup_source'))?></th>
						<th><?=htmlspecialchars(Translator::translate('monitored_interfaces'))?></th>
						<th><?=htmlspecialchars(Translator::translate('backup_details'))?></th>
						<th><?=htmlspecialchars(Translator::translate('backup_size'))?></th>
						<th><?=htmlspecialchars(Translator::translate('actions'))?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($backups as $backup): ?>
<?php
    $createdAt = strtotime($backup['created_at']);
    $details = $backup['summary'] ?? '';

    // Migration backups all store the same message, so it follows the current language.
    if ($backup['source'] === 'migration') {
        $details = Translator::translate('legacy_backup_summary');
    }

    if ($backup['original_name'] !== null) {
        $details = Translator::translate('backup_imported_from', [$backup['original_name']]);
    }

    $interfaceLabels = array_map(
        static fn (string $interface): string => pfsensePackageIpv6PrefixUpdaterInterfaceLabel($interface),
        $backup['interfaces'],
    );
?>
					<tr>
						<td><?=htmlspecialchars(($createdAt === false) ? $backup['created_at'] : date('Y-m-d H:i:s T', $createdAt))?></td>
						<td><?=htmlspecialchars(Translator::translate('backup_source_' . $backup['source']))?></td>
						<td><?=htmlspecialchars(implode(', ', $interfaceLabels))?></td>
						<td><?=htmlspecialchars($details)?></td>
						<td class="text-nowrap"><?=htmlspecialchars(format_bytes($backup['size']))?></td>
						<td class="text-nowrap">
							<?php // Icon links match Config History; "usepost" makes pfSense submit them as POST with the CSRF token. ?>
							<a class="fa fa-download" title="<?=htmlspecialchars(Translator::translate('download'))?>" href="ipv6-prefix-updater-backups.php?download=1&amp;backup_id=<?=urlencode($backup['id'])?>" usepost></a>
							<?php // "no-confirm" skips pfSense's English-only prompt; the translated prompt below stops the POST on cancel. ?>
							<a class="fa fa-trash no-confirm" title="<?=htmlspecialchars(Translator::translate('delete'))?>" href="ipv6-prefix-updater-backups.php?delete=1&amp;backup_id=<?=urlencode($backup['id'])?>" usepost onclick="if (!confirm(<?=htmlspecialchars($deleteConfirmation)?>)) { event.stopImmediatePropagation(); return false; }"></a>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>
	<div class="panel-footer">
		<?=htmlspecialchars(Translator::translate('backup_storage', [PackagePaths::BACKUP_DIRECTORY, format_bytes($totalSize)]))?>
	</div>
</div>
<?php

include('foot.inc');

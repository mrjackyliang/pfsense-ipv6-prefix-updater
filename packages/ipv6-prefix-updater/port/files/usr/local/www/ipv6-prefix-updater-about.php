<?php

declare(strict_types=1);

/*
 * IPv6 Prefix Updater package information.
 * SPDX-License-Identifier: MIT
 */

##|+PRIV
##|*IDENT=page-services-ipv6-prefix-updater-about
##|*NAME=Services: IPv6 Prefix Updater: About
##|*DESCR=Allow access to the IPv6 Prefix Updater package information page.
##|*MATCH=ipv6-prefix-updater-about.php*
##|-PRIV

use PfSense\Ipv6PrefixUpdater\Runtime\PackagePaths;
use PfSense\Ipv6PrefixUpdater\Runtime\TestedReleases;
use PfSense\Ipv6PrefixUpdater\Runtime\Translator;
use PfSense\Ipv6PrefixUpdater\Runtime\UpdateManager;

require_once('guiconfig.inc');
require_once('pfsense-utils.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater.inc');
require_once('/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/TestedReleases.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/Translator.php');
require_once('/usr/local/pkg/ipv6-prefix-updater/UpdateManager.php');

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$packageDataPath = '/usr/local/share/pfSense-pkg-Ipv6PrefixUpdater';
$packageLogoPath = $packageDataPath . '/logo.svg';
$installedPackageVersion = UpdateManager::installedVersion();
$packageVersion = $installedPackageVersion ?? Translator::translate('unknown');

if (($_GET['update_status'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    try {
        $statusResponse = UpdateManager::readStatusState() ?? ['state' => 'idle'];
    } catch (Throwable $throwable) {
        $statusResponse = [
            'state' => 'failed',
            'message' => $throwable->getMessage(),
        ];
    }

    echo json_encode(
        $statusResponse,
        JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    exit;
}

$updateActionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_updates'])) {
        $actionOutputLines = [];

        exec(PackagePaths::MANUAL_UPDATE_CHECK_COMMAND . ' 2>&1', $actionOutputLines, $returnCode);
        $actionOutput = implode(PHP_EOL, $actionOutputLines);
        $operationRunningMessages = [
            Translator::translate('lock_owned'),
            Translator::translate('release_operation_running'),
        ];

        if ($returnCode !== 0 || in_array(trim($actionOutput), $operationRunningMessages, true)) {
            $updateActionError = $actionOutput !== ''
                ? $actionOutput
                : Translator::translate('update_check_failed_transport');
        }
    } elseif (isset($_POST['self_update'])) {
        try {
            $queuedState = UpdateManager::readUpdatesState();
            $queuedVersion = $queuedState['latest_version'] ?? null;

            if (!is_string($queuedVersion) || $queuedVersion === '') {
                throw new RuntimeException(Translator::translate('update_not_checked'));
            }

            $currentJob = UpdateManager::readStatusState();

            if (is_array($currentJob) && ($currentJob['state'] ?? null) === 'running') {
                throw new RuntimeException((string) ($currentJob['message'] ?? Translator::translate('update_status_queued')));
            }

            UpdateManager::queueSelfUpdate($queuedVersion);

            try {
                mwexec_bg(PackagePaths::SELF_UPDATE_COMMAND);
            } catch (Throwable $throwable) {
                UpdateManager::failQueuedSelfUpdate($throwable->getMessage());

                throw $throwable;
            }
        } catch (Throwable $throwable) {
            $updateActionError = $throwable->getMessage();
        }
    }
}

$updateState = null;
$updateStatus = null;

try {
    $updateState = UpdateManager::readUpdatesState();
} catch (Throwable $throwable) {
    $updateActionError ??= $throwable->getMessage();
}

try {
    $updateStatus = UpdateManager::readStatusState();
} catch (Throwable $throwable) {
    $updateActionError ??= $throwable->getMessage();
}

$updateState ??= [
    'checked_at' => null,
    'latest_version' => null,
    'release_url' => null,
    'last_error' => null,
];
$settings = pfsensePackageIpv6PrefixUpdaterGetSettings();
$updateCheckFrequency = (string) ($settings['update_check_frequency'] ?? 'weekly');
$latestVersion = $updateState['latest_version'] ?? null;
$checkedAt = $updateState['checked_at'] ?? null;
$checkedAtDisplay = null;

if (is_string($checkedAt) && $checkedAt !== '') {
    $checkedTimestamp = strtotime($checkedAt);
    $checkedAtDisplay = $checkedTimestamp === false
        ? $checkedAt
        : date('Y-m-d H:i:s T', $checkedTimestamp);
}

$releaseUrl = $updateState['release_url'] ?? null;
$releaseUrl = is_string($releaseUrl) && UpdateManager::isAllowedReleaseUrl($releaseUrl)
    ? $releaseUrl
    : null;
$updateAvailable = is_string($installedPackageVersion)
    && is_string($latestVersion)
    && UpdateManager::isNewerVersion($installedPackageVersion, $latestVersion);
$updateError = $updateActionError ?? ($updateState['last_error'] ?? null);
$updateJobState = is_array($updateStatus) ? ($updateStatus['state'] ?? null) : null;
$updateJobMessage = is_array($updateStatus) ? ($updateStatus['message'] ?? null) : null;
$updateJobRunning = $updateJobState === 'running';
$updateBarTone = match (true) {
    $updateJobState === 'failed', is_string($updateError) && $updateError !== '' => 'is-error',
    $updateJobState === 'succeeded', $updateAvailable => 'is-success',
    $updateJobRunning => 'is-running',
    default => 'is-muted',
};

$packageLogoData = null;

if (is_readable($packageLogoPath)) {
    $packageLogo = file_get_contents($packageLogoPath);

    if (is_string($packageLogo)) {
        $packageLogoData = 'data:image/svg+xml;base64,' . base64_encode($packageLogo);
    }
}

$productLabel = g_get('product_label');
$productVersion = g_get('product_version');
$currentEdition = is_string($productLabel) && stripos($productLabel, 'Plus') !== false ? 'Plus' : 'CE';
$currentVersion = is_string($productVersion) ? trim($productVersion) : '';
$normalizedVersion = preg_replace('/-RELEASE(?:-p[0-9]+)?$/i', '', $currentVersion);

if (is_string($normalizedVersion)) {
    $currentVersion = $normalizedVersion;
}

$normalizedVersion = preg_replace('/-p[0-9]+$/i', '', $currentVersion);

if (is_string($normalizedVersion)) {
    $currentVersion = $normalizedVersion;
}

if ($currentVersion === '') {
    $currentVersion = Translator::translate('unknown');
}

$freeBsdRelease = php_uname('r');
$currentFreeBsd = Translator::translate('unknown');

if (preg_match('/^([0-9]+\.[0-9]+)/', $freeBsdRelease, $freeBsdReleaseMatches) === 1) {
    $currentFreeBsd = $freeBsdReleaseMatches[1];
}

$testedReleases = TestedReleases::RELEASES;
$currentReleaseTested = false;

foreach ($testedReleases as $testedRelease) {
    if (
        $testedRelease['edition'] === $currentEdition
        && $testedRelease['version'] === $currentVersion
    ) {
        $currentReleaseTested = true;
        break;
    }
}

$licenseUrl = 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/blob/HEAD/LICENSE';
$licenseLabel = Translator::translate('mit_license');
$licenseLinkHtml = sprintf(
    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
    $escape($licenseUrl),
    $escape($licenseLabel),
);
$packageDisplayName = Translator::translate('brand');
$packageDisplayNameEscaped = $escape($packageDisplayName);
$supportMessageTemplate = Translator::translate('support_message');
$supportMessageTemplateEscaped = $escape($supportMessageTemplate);
$supportMessage = vsprintf(
    $supportMessageTemplateEscaped,
    [
        $packageDisplayNameEscaped,
        $licenseLinkHtml,
    ],
);

$pgtitle = [
    gettext('Services'),
    Translator::translate('brand'),
    Translator::translate('about'),
];
$pglinks = [
    '',
    '/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0',
    '@self',
];

include('head.inc');

display_top_tabs(pfsensePackageIpv6PrefixUpdaterTabs('about'));
?>
<style>
.pkg-about .panel-body.pkg-about-pad { padding: 15px; }
.pkg-about-hero { display: flex; align-items: center; gap: 24px; padding: 24px; }
.pkg-about-logo { flex: none; width: 96px; height: 96px; border-radius: 22px; box-shadow: 0 2px 6px rgba(0, 0, 0, .18); }
.pkg-about-hero-text { flex: 1; min-width: 0; }
.pkg-about-name { margin: 0; font-size: 26px; font-weight: 500; line-height: 1.25; }
.pkg-about-name .label { position: relative; top: -3px; margin-left: 8px; font-size: 12px; font-weight: 500; letter-spacing: .3px; vertical-align: middle; }
.pkg-about-tagline { margin: 6px 0 16px; font-size: 15px; opacity: .8; }
.pkg-about-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pkg-about .btn i { font-size: 14px; margin-right: 6px; }
.pkg-about-update { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 16px; padding: 10px 12px; border: 1px solid rgba(127, 127, 127, .3); border-radius: 4px; background: rgba(127, 127, 127, .07); }
.pkg-about-update.is-success { border-color: rgba(46, 125, 50, .38); background: rgba(46, 125, 50, .09); }
.pkg-about-update.is-error { border-color: rgba(198, 40, 40, .38); background: rgba(198, 40, 40, .08); }
.pkg-about-update.is-running { border-color: rgba(21, 101, 192, .38); background: rgba(21, 101, 192, .08); }
.pkg-about-update-text { min-width: 0; }
.pkg-about-update-text i { margin-right: 8px; font-size: 16px; }
.pkg-about-update.is-success .pkg-about-update-text i { color: #2e7d32; }
.pkg-about-update.is-error .pkg-about-update-text i { color: #c62828; }
.pkg-about-update.is-running .pkg-about-update-text i { color: #1565c0; }
.pkg-about-update-detail { margin-left: 4px; opacity: .75; }
.pkg-about-update-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pkg-about-update-actions form { display: inline; margin: 0; }
.pkg-about-table > tbody > tr > td, .pkg-about-table > thead > tr > th { padding: 9px 15px !important; vertical-align: middle; }
.pkg-about-table i { font-size: 15px; margin-right: 8px; }
.pkg-about-table .label { margin-left: 8px; font-weight: 500; }
.pkg-about-muted { opacity: .7; }
.pkg-about-support p { margin: 0 0 14px; }
.pkg-about-footnote { margin: 0; padding: 9px 15px; font-size: 12px; opacity: .75; border-top: 1px solid rgba(127, 127, 127, .25); }
@media (max-width: 600px) {
  .pkg-about-hero { flex-direction: column; align-items: flex-start; gap: 16px; padding: 18px; }
  .pkg-about-logo { width: 72px; height: 72px; border-radius: 16px; }
}
.pkg-about-untested td { opacity: .75; }
.pkg-about-untested i { opacity: .6; }
.pkg-about-note { margin-left: 8px; font-size: 12px; font-style: italic; }
</style>
<div class="pkg-about">
	<div class="panel panel-default">
		<div class="panel-body">
			<div class="pkg-about-hero">
<?php if ($packageLogoData !== null): ?>
				<img class="pkg-about-logo" src="<?=$escape($packageLogoData)?>" width="96" height="96" alt="">
<?php endif; ?>
				<div class="pkg-about-hero-text">
					<h2 class="pkg-about-name"><?=$escape(Translator::translate('brand'))?><span class="label label-primary"><?=$escape($packageVersion)?></span></h2>
					<p class="pkg-about-tagline"><?=$escape(Translator::translate('tagline'))?></p>
					<div class="pkg-about-actions">
						<a class="btn btn-sm btn-primary" href="https://mrjackyliang.github.io/pfsense-ipv6-prefix-updater/" target="_blank" rel="noopener noreferrer"><i class="fa fa-book"></i><?=$escape(Translator::translate('documentation'))?></a>
						<a class="btn btn-sm btn-default" href="https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater" target="_blank" rel="noopener noreferrer"><i class="fab fa-github"></i><?=$escape(Translator::translate('source_code'))?></a>
						<a class="btn btn-sm btn-default" href="https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/issues/new/choose" target="_blank" rel="noopener noreferrer"><i class="fa fa-bug"></i><?=$escape(Translator::translate('report_an_issue'))?></a>
						<a class="btn btn-sm btn-default" href="https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases" target="_blank" rel="noopener noreferrer"><i class="fa fa-tag"></i><?=$escape(Translator::translate('release_notes'))?></a>
					</div>
					<div class="pkg-about-update <?=$escape($updateBarTone)?>">
						<span class="pkg-about-update-text" id="pkg-about-update-status">
<?php if (is_string($updateJobMessage) && $updateJobMessage !== ''): ?>
							<i class="fa fa-solid <?=$escape($updateJobRunning ? 'fa-spinner fa-spin' : ($updateJobState === 'succeeded' ? 'fa-check-circle' : 'fa-exclamation-circle'))?>"></i><strong><?=$escape($updateJobMessage)?></strong>
<?php elseif (is_string($updateError) && $updateError !== ''): ?>
							<i class="fa fa-solid fa-exclamation-circle"></i><span><?=$escape(Translator::translate('update_check_failed', [$updateError]))?></span>
<?php elseif ($updateAvailable && is_string($latestVersion)): ?>
							<i class="fa fa-solid fa-arrow-circle-up"></i><strong><?=$escape(Translator::translate('update_available', [$latestVersion]))?></strong><?php if (is_string($checkedAtDisplay)): ?><span class="pkg-about-update-detail"><?=$escape(Translator::translate('update_checked', [$checkedAtDisplay]))?></span><?php endif; ?>
<?php elseif ($updateCheckFrequency === 'never'): ?>
							<i class="fa fa-solid fa-pause-circle"></i><span><?=$escape(Translator::translate('update_checks_off'))?></span>
<?php elseif (is_string($checkedAtDisplay)): ?>
							<i class="fa fa-solid fa-check-circle"></i><span><?=$escape(Translator::translate('update_up_to_date', [$checkedAtDisplay]))?></span>
<?php else: ?>
							<i class="fa fa-solid fa-info-circle"></i><span><?=$escape(Translator::translate('update_not_checked'))?></span>
<?php endif; ?>
						</span>
						<div class="pkg-about-update-actions">
<?php if ($updateAvailable && is_string($latestVersion) && !$updateJobRunning && $updateJobState !== 'succeeded'): ?>
							<form method="post"><button class="btn btn-sm btn-success" type="submit" name="self_update" value="1"><i class="fa fa-solid fa-download"></i><?=$escape(Translator::translate('update_to_version', [$latestVersion]))?></button></form>
<?php if (is_string($releaseUrl)): ?>
							<a class="btn btn-sm btn-default" href="<?=$escape($releaseUrl)?>" target="_blank" rel="noopener noreferrer"><i class="fa fa-solid fa-tag"></i><?=$escape(Translator::translate('whats_new'))?></a>
<?php endif; ?>
<?php elseif (!$updateJobRunning): ?>
							<form method="post"><button class="btn btn-xs btn-default" type="submit" name="check_updates" value="1"><i class="fa fa-solid fa-refresh"></i><?=$escape(Translator::translate('check_now'))?></button></form>
<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
<?php if ($testedReleases !== []): ?>
		<div class="col-md-7">
			<div class="panel panel-default">
				<div class="panel-heading"><h2 class="panel-title"><?=$escape(Translator::translate('tested_pfsense_releases'))?></h2></div>
				<div class="panel-body">
					<div class="table-responsive">
						<table class="table table-striped table-hover pkg-about-table">
							<thead><tr><th><?=$escape(Translator::translate('release'))?></th><th>FreeBSD</th></tr></thead>
							<tbody>
<?php foreach ($testedReleases as $testedRelease): ?>
								<tr><td><i class="fa fa-check-circle text-success"></i><?=$escape(sprintf('pfSense %s %s', $testedRelease['edition'], $testedRelease['version']))?><?php if ($testedRelease['edition'] === $currentEdition && $testedRelease['version'] === $currentVersion): ?><span class="label label-info"><?=$escape(Translator::translate('this_firewall'))?></span><?php endif; ?></td><td><?=$escape($testedRelease['freebsd'])?></td></tr>
<?php endforeach; ?>
<?php if ($currentReleaseTested === false): ?>
								<tr class="pkg-about-untested"><td><i class="far fa-circle"></i><?=$escape(sprintf('pfSense %s %s', $currentEdition, $currentVersion))?><span class="label label-info"><?=$escape(Translator::translate('this_firewall'))?></span><span class="pkg-about-note"><?=$escape(Translator::translate('not_tested'))?></span></td><td><?=$escape($currentFreeBsd)?></td></tr>
<?php endif; ?>
							</tbody>
						</table>
					</div>
					<p class="pkg-about-footnote"><?=$escape(Translator::translate('tested_releases_footnote'))?></p>
				</div>
			</div>
		</div>
<?php endif; ?>
		<div class="<?=$escape($testedReleases === [] ? 'col-md-12' : 'col-md-5')?>">
			<div class="panel panel-default">
				<div class="panel-heading"><h2 class="panel-title"><?=$escape(Translator::translate('support_project'))?></h2></div>
				<div class="panel-body pkg-about-pad pkg-about-support">
					<p><?=$supportMessage?></p>
					<div class="pkg-about-actions">
						<a class="btn btn-sm btn-success" href="https://github.com/sponsors/mrjackyliang" target="_blank" rel="noopener noreferrer"><i class="fa fa-heart"></i>GitHub Sponsors</a>
						<a class="btn btn-sm btn-default" href="https://liang.nyc/paypal" target="_blank" rel="noopener noreferrer"><i class="fab fa-paypal"></i>PayPal</a>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php if ($updateJobRunning): ?>
<script>
(function () {
	'use strict';

	var statusLine = document.getElementById('pkg-about-update-status');
	var messageNode = statusLine ? statusLine.querySelector('strong') : null;

	window.setInterval(function () {
		window.fetch('ipv6-prefix-updater-about.php?update_status=1', {
			credentials: 'same-origin',
			cache: 'no-store'
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('status');
			}

			return response.json();
		}).then(function (status) {
			if (status.state !== 'running') {
				window.location.reload();

				return;
			}

			if (messageNode && typeof status.message === 'string') {
				messageNode.textContent = status.message;
			}
		}).catch(function () {
			// A later poll can recover from a transient WebGUI or network failure.
		});
	}, 3000);
}());
</script>
<?php endif; ?>
<?php

include('foot.inc');

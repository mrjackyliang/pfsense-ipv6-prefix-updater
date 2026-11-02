<?php

declare(strict_types=1);

/**
 * Retired legacy entry point.
 *
 * A version 1.x installer that still downloads the main branch places this file where
 * the Cron entry expects "update.php". It never changes configuration and only raises
 * one pfSense notice that points the administrator to the package.
 *
 * @since 2.0.0
 */
$noticeId = 'ipv6-prefix-updater-legacy-retired';
$message = 'pfSense IPv6 Prefix Updater is now a pfSense package and the /root script no longer updates your configuration. Install the package with: pkg add https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/latest/download/pfSense-pkg-Ipv6PrefixUpdater.pkg';

fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);

if (!file_exists('/etc/inc/notices.inc')) {
    exit(1);
}

require_once('/etc/inc/config.inc');
require_once('/etc/inc/globals.inc');
require_once('/etc/inc/notices.inc');
require_once('/etc/inc/util.inc');

if (!function_exists('file_notice')) {
    exit(1);
}

// Cron may run this every minute, so an existing unread notice is not repeated.
$notices = function_exists('get_notices') ? get_notices() : false;

if (is_array($notices)) {
    foreach ($notices as $notice) {
        if (is_array($notice) && ($notice['id'] ?? '') === $noticeId) {
            exit(1);
        }
    }
}

file_notice($noticeId, $message, 'IPv6 Prefix Updater', 'https://mrjackyliang.github.io/pfsense-ipv6-prefix-updater/docs/migrate-from-legacy');

exit(1);

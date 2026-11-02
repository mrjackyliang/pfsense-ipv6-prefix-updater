# pfsense-pkg-ipv6-prefix-updater

## 2.0.1 - 2026-11-02

### FIXED
- Fixed missing WebGUI icons on current pfSense releases while retaining pfSense 2.7.2 compatibility.

## 2.0.0 - 2026-11-02

### ADDED
- Added a native installable pfSense IPv6 prefix updater package with binary host-bit preservation, updates for aliases, static interfaces, virtual IPs, IPsec, OpenVPN, and WireGuard, backup-aware writes, locking, state verification, targeted service reloads, notifications, dry runs, a settings and status WebGUI, and executable failure-path tests.
- Added package-owned WebGUI, command, validation, status, notification, and runtime translations for all 18 pfSense language choices.
- Added automatic migration from the version 1.x script on install, which turns its Cron entries into monitored interfaces, keeps its saved prefixes as baselines, imports its backups, and deletes its files, plus a Cleanup tab that lists every leftover with a Clean Up Now action.
- Added several monitored interfaces in one settings form, checked together in one run with a single backed-up configuration write and a per-interface Last Run table on the Status tab.
- Added an Accept Current Prefix action on the Status tab that, after confirmation, records the active prefix as the new baseline without scanning or changing approved fields.
- Added a Backups tab that keeps the package's own root-only copies of config.xml from before each prefix update, with download and delete actions. The copies and the package state live in /cf/conf/ipv6-prefix-updater, so RAM-disk reboots and package removal keep them.
- Added updates for DHCPv6 server ranges, static mappings, and DNS and NTP servers, router advertisement DNS servers and subnets, DNS Resolver and DNS Forwarder host overrides, firewall rule addresses, and port forward, outbound NAT, 1:1 NAT, and NPt addresses, each reloading only the service it belongs to.
- Added a Fields tab that lists every address inside the monitored prefixes with a checkbox per field group, single-field exceptions that follow their entry instead of its list position, search and filters, Auto-select and Clear All actions, and an Other Settings group for gateways, static routes, remote VPN values, descriptions, and every other address, which stays off until a field in it is ticked.
- Added opt-in updates for other packages' settings, which apply the owning package's settings again after the verified write and report a section without an installed owner or a failed re-apply without undoing the change.
- Added the exact old and new address of each field to dry runs and command output, and Packages and Skipped Fields rows to the Status tab's Last Run.

## 1.1.1 - 2026-04-21

### UPDATED
- Added a compatibility layer to `update.php` so the script runs on pfSense Plus 26.03-RELEASE and later, which use `config_read_file()`, and on pfSense CE and earlier pfSense Plus releases, which use `parse_config()`. The dependency check accepts either function, and the script calls whichever one exists.
- Added wblew to `SPONSOR_EXEMPT`, crediting the pull request #1 fix with an exemption from sponsor-gated support.

## 1.1.0 - 2026-01-18

### UPDATED
- Made prefix handling case-insensitive from end to end (scan, cache, search, replace, and base64), and normalized prefixes to lowercase when they are computed or read.
- Made base64-encoded values such as `custom_options` update too, with case-insensitive replacements and support for base64 blocks wrapped with whitespace.

### FIXED
- Fixed the interface scan picking up temporary or deprecated global IPv6 addresses. It now skips them and keeps the first stable global address on each interface.

## 1.0.3 - 2026-01-17

### UPDATED
- Skipped excluded keys such as `revision` during updates, with a notice showing how many locations were skipped.

## 1.0.2 - 2026-01-17

### UPDATED
- Moved the backups and cache out of the script folder, to `/root/pfsense-ipv6-prefix-updater-backups` and `/root/pfsense-ipv6-prefix-updater-cache`, so updating the script keeps them.
- Changed the license from Apache 2.0 to MIT.

### FIXED
- Fixed unique local addresses (ULAs) being detected as global addresses. The interface scan now only accepts addresses in `2000::/3`.

## 1.0.1 - 2024-05-10

### REMOVED
- Removed ANSI terminal coloring from the script's log output.

## 1.0.0 - 2024-01-17

### ADDED
- Added `update.php`, a script that runs from a Cron package entry with an interface name and prefix length, compares the interface's IPv6 prefix with the cached one, and replaces the old prefix throughout the pfSense configuration, including firewall aliases, static IPs, and IPsec, OpenVPN, and WireGuard addresses.
- Added a backup of `config.xml` in `/root/pfsense-ipv6-prefix-updater/backups` before each change, a pfSense notification when the prefix changes, and a lock that lets only one copy of the script run at a time.
- Added an install script that downloads the updater into `/root/pfsense-ipv6-prefix-updater`.

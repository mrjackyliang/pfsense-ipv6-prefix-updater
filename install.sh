#!/bin/sh
set -eu

# ============================
# Retired Installer
# ============================

# The version 1.x setup command fetches this file from jsDelivr and runs it with the
# FreeBSD "sh", so it stays POSIX and only explains how to install the package.
main() {
  echo "ERROR: pfSense IPv6 Prefix Updater is now a pfSense package." >&2
  echo "The /root script is no longer supported. Install the package with:" >&2
  echo "" >&2
  echo "  pkg add https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases/latest/download/pfSense-pkg-Ipv6PrefixUpdater.pkg" >&2
  echo "" >&2
  echo "Your Cron entry, saved prefix, and backups are moved over automatically." >&2
  echo "Documentation: https://mrjackyliang.github.io/pfsense-ipv6-prefix-updater/docs/migrate-from-legacy" >&2

  exit 1
}

main "$@"

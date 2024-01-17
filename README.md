pfSense® Dynamic IPv6 Prefix Updater
=====================================

[![GitHub Releases](https://img.shields.io/github/v/release/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=github&logoColor=%23ffffff&color=%23b25da6)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases)
[![GitHub Top Languages](https://img.shields.io/github/languages/top/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=php&logoColor=%23ffffff&color=%236688c3)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater)
[![GitHub License](https://img.shields.io/github/license/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=googledocs&logoColor=%23ffffff&color=%2348a56a)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/blob/main/LICENSE)
[![Become a GitHub Sponsor](https://img.shields.io/badge/github-sponsor-gray?style=flat-square&logo=githubsponsors&logoColor=%23ffffff&color=%23eaaf41)](https://github.com/sponsors/mrjackyliang)
[![Donate via PayPal](https://img.shields.io/badge/paypal-donate-gray?style=flat-square&logo=paypal&logoColor=%23ffffff&color=%23ce4a4a)](https://liang.nyc/paypal)

This is a script designed to be run on pfSense® environments to help automatically update the configuration when your Internet Service Provider (ISP) changes your dynamic IPv6 prefix.

Assists with updating global IPv6 addresses configured on firewall aliases, static IP assignments, and virtual addresses for IPsec / OpenVPN / WireGuard.

To use this updater, here are three simple steps you need to follow:
1. Download the **Cron** package from **Package Manager**.
2. Choose an interface, and configure your system using the assigned IPv6 address.
3. Download the updater by following the [setup instructions](#setup-instructions).

## Setup Instructions
To download this updater, login to **pfSense®** > **Diagnostics** > **Command Prompt**. Then copy the command below and click the **Execute** button.
```shell
cd /root && fetch https://raw.githubusercontent.com/mrjackyliang/pfsense-ipv6-prefix-updater/main/install.sh | sh
```
the setup only downloads the script. you must manually set up the cron scheduling yourself.

it is not recommended for you to have more than 1 script due to race conditions. you also should not setup cron so that the system runs multiple instances of the script.

## WireGuard Peer Configurations
the script can update wireguard configurations on pfSense, but all peer related files must be manually updated. please setup notifications to be notified when ipv6 prefixes change.

## Root User Requirement
root user is required because `write_config()` will fail if not running under root.

## Credits and Appreciation
If you find value in the ongoing development of this updater and wish to express your appreciation, you have the option to become my supporter on [GitHub Sponsors](https://github.com/sponsors/mrjackyliang)!

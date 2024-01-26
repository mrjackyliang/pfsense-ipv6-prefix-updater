pfSense® Dynamic IPv6 Prefix Updater
=====================================

[![GitHub Releases](https://img.shields.io/github/v/release/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=github&logoColor=%23ffffff&color=%23b25da6)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases)
[![GitHub Top Languages](https://img.shields.io/github/languages/top/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=php&logoColor=%23ffffff&color=%236688c3)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater)
[![GitHub License](https://img.shields.io/github/license/mrjackyliang/pfsense-ipv6-prefix-updater?style=flat-square&logo=googledocs&logoColor=%23ffffff&color=%2348a56a)](https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/blob/main/LICENSE)
[![Become a GitHub Sponsor](https://img.shields.io/badge/github-sponsor-gray?style=flat-square&logo=githubsponsors&logoColor=%23ffffff&color=%23eaaf41)](https://github.com/sponsors/mrjackyliang)
[![Donate via PayPal](https://img.shields.io/badge/paypal-donate-gray?style=flat-square&logo=paypal&logoColor=%23ffffff&color=%23ce4a4a)](https://liang.nyc/paypal)

This script is specifically crafted to run on pfSense® environments to help automatically update the configuration when your Internet Service Provider (ISP) changes your dynamic IPv6 prefix.

It assists in automatically updating global IPv6 addresses saved in firewall aliases, static IPs, and virtual addresses used in IPsec, OpenVPN, and WireGuard configurations.

To use this updater, here are three simple steps you need to follow:
1. Download the **Cron** package from **Package Manager**.
2. Choose an interface, and configure your system with the given global IPv6 address.
3. Download the updater by following the [setup instructions](#setup-instructions).

## Setup Instructions
To download this updater, first login to **pfSense®** > **Diagnostics** > **Command Prompt**. Copy the provided command and then click the **Execute** button.

```shell
cd /root && fetch -o pfsense-ipv6-prefix-updater-install.sh https://raw.githubusercontent.com/mrjackyliang/pfsense-ipv6-prefix-updater/main/install.sh && sh pfsense-ipv6-prefix-updater-install.sh
```

__NOTE:__ The script runs as `root` and will reside in the `/root` folder.

## Setup Cron Scheduling
After downloading the script, go to **Services** > **Cron** > **Add** tab. Fill in the properties shown below (after determining the cron schedule, interface name, and prefix length) then click the **Save** button.

- __Minute:__ `*`
- __Hour:__ `*`
- __Day of the Month:__ `*`
- __Month of the Year:__ `*`
- __Day of the Week:__ `*`
- __User:__ `root`
- __Command:__ `php /root/pfsense-ipv6-prefix-updater/update.php [INTERFACE NAME] [PREFIX LENGTH]`

__NOTE:__ DO NOT use `wan` for the `INTERFACE NAME` since that 100% a /128 address. The `PREFIX LENGTH` will be defined by your Internet Service Provider (ISP) and should be between 48 and 64.

__NOTE 2:__ To calculate the cron schedule expression, visit the [crontab guru](https://crontab.guru) website.

__NOTE 3:__ When monitoring multiple interfaces, ensure that you run them at distinct time intervals, as only one instance is permitted to run simultaneously.

## WireGuard Peer Configurations
Even though the script can streamline the process of updating configurations on your pfSense® system, manual updates to peer configuration files are required due to the design of WireGuard. This is necessary to maintain the proper functioning of IPv6.

If you wish to be notified everytime the prefix is changed, please set up notifications in **System** > **Advanced** > **Notifications**.

## Root User Requirement
Typically, it is recommended to avoid running scripts under the `root` user. However, because of the `root:wheel` ownership of the configuration file (`/conf/config.xml`), the script requires execution under this user.

Rest assured, before applying any changes to the configuration file, the script will generate a backup of the `config.xml` file and store it in the `/root/pfsense-ipv6-prefix-updater/backups` folder.

## Credits and Appreciation
If you find value in the ongoing development of this updater and wish to express your appreciation, you have the option to become my supporter on [GitHub Sponsors](https://github.com/sponsors/mrjackyliang)!

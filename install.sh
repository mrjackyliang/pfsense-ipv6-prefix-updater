#!/bin/bash

echo "#####################################################################"
echo "###      pfSense® Dynamic IPv6 Prefix Updater (Installation)      ###"
echo "###  https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater  ###"
echo "###                                                               ###"
echo "###      Copyright (c) 2024 Jacky Liang. All Rights Reserved      ###"
echo "#####################################################################"

# Installation can only be executed on a pfSense® environment.
if ! (uname -a | grep -q "FreeBSD" && uname -a | grep -q "pfSense"); then
    echo "ERROR: Installation can only be executed on a pfSense® environment."
    exit 1
fi

# Installation must be run under the root user.
if [ "$(whoami)" != "root" ]; then
    echo "ERROR: Installation must be run under the root user."
    exit 1
fi

# The files will be stored in the /root directory.
if [ ! -d /root ]; then
    echo "ERROR: The /root directory does not exist."
    exit 1
fi

# Move to the /root directory first.
echo "Moving to the /root directory ..."
cd /root || exit

# Prevent existing installation from causing errors.
echo "Removing existing installation files ..."
rm -rf pfsense-ipv6-prefix-updater

# Fetch and rename the downloaded zip file.
echo "Fetching new files ..."
fetch -q -o pfsense-ipv6-prefix-updater.zip https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/archive/refs/heads/main.zip

# Unzip the files.
echo "Unzipping the fetched files ..."
unzip -q pfsense-ipv6-prefix-updater.zip

# Rename the folder.
echo "Renaming the folder ..."
mv pfsense-ipv6-prefix-updater-main pfsense-ipv6-prefix-updater

echo "Installation successful!"
exit 0

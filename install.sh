#!/bin/bash

cd /root

rm -rf pfsense-ipv6-prefix-updater

fetch -o pfsense-ipv6-prefix-updater.zip https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/archive/refs/heads/main.zip

unzip pfsense-ipv6-prefix-updater.zip

mv pfsense-ipv6-prefix-updater-main pfsense-ipv6-prefix-updater

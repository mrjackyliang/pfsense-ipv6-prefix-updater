<?php
	/**
	 * pfSense® Dynamic IPv6 Prefix Updater.
	 *
	 * Automatically update your pfSense® configuration when your
	 * Internet Service Provider (ISP) changes your dynamic IPv6 prefix.
	 *
	 * @link https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater
	 * @tags pfsense, pfsense-firewall, ipv6, dynamic-ip, ipv6-prefix
	 *
	 * @since 1.0.0
	 */
	$version = "1.0.2";
	$root_path = dirname(__FILE__);

	require_once($root_path . "/lib/ip.php");
	require_once($root_path . "/lib/utility.php");

	// Checks if this script is executed in a pfSense® environment.
	if (!is_pfsense_os("/etc", "/etc/inc")) {
		die(generate_log_string("error", "This script can only be executed in pfSense® environments"));
	}

	require_once("/etc/inc/config.inc");
	require_once("/etc/inc/globals.inc");
	require_once("/etc/inc/notices.inc");
	require_once("/etc/inc/util.inc");

	// Check if the required pfSense® functions exist.
	if (
		!function_exists("parse_config")
		|| !function_exists("write_config")
		|| !function_exists("notify_all_remote")
	) {
		die(generate_log_string("error", "This script requires the usage of \"parse_config()\", \"write_config()\", \"notify_all_remote()\" functions"));
	}

	global $g;

	// Print script header.
	print_script_header($version);

	echo PHP_EOL;

	// Print system configuration.
	print_system_config($g);

	echo PHP_EOL;

	// Print system versions.
	print_system_versions($g);

	echo PHP_EOL;

	// Print available interfaces.
	print_available_interfaces();

	echo PHP_EOL;

	// Check if the script is running under a root user.
	if (exec("whoami") !== "root") {
		die(generate_log_string("error", "This script can only be executed under the \"root\" user"));
	}

	// Check if the lock file exists.
	if (lockfile_exists($root_path)) {
		die(generate_log_string("error", "Only one instance of this script can be executed at the same time"));
	}

	// Create the lock file.
	create_lock_file($root_path);

	// Check if this script is executed properly.
	if ($argc !== 3) {
		delete_lock_file($root_path);

		die(generate_log_string("error", "Incorrect usage. Interface name (e.g. wan) and prefix length (e.g. 56) is required"));
	}

	$defined_interface_name = $argv[1];
	$defined_prefix_length = intval($argv[2]);
	$network_interfaces = get_network_interfaces();

	// Check if the interface has a global IPv6 address assigned.
	if (!array_key_exists($argv[1], $network_interfaces)) {
		delete_lock_file($root_path);

		die(generate_log_string("error", "The interface name specified (" . $defined_interface_name . ") has no global IPv6 address assigned"));
	}

	// Check if the prefix length is invalid.
	if ($defined_prefix_length < 48 || $defined_prefix_length > 64) {
		delete_lock_file($root_path);

		die(generate_log_string("error", "The prefix length specified (" . $defined_interface_name . ") must be between 48 and 64"));
	}

	$prefixed_address = get_ipv6_prefix($network_interfaces[$defined_interface_name]["address"], $defined_prefix_length);

	echo "Defined interface: " . $defined_interface_name . PHP_EOL;
	echo "Defined prefix length: " . $defined_prefix_length . PHP_EOL;
	echo "Prefixed address: " . $prefixed_address . PHP_EOL;

	echo PHP_EOL;

	// Cache name and path properties.
	$cache_name = $defined_interface_name . ".cache";
	$cache_path = $root_path . "/../pfsense-ipv6-prefix-updater-cache";
	$cache_location = $cache_path . "/" . $cache_name;
	$cache_contents = $prefixed_address . "||" . $defined_prefix_length;

	// Create the cache file if it does not exist.
	create_cache_file($cache_name, $cache_path, $cache_contents);

	// Pull data from the cache and save it to memory.
	$cache_contents = file_get_contents($cache_location);

	// Validate the data from cache.
	if ($cache_contents === false || !preg_match("/^([0-9a-f:]+)\|\|([0-9]{2})$/", $cache_contents)) {
		delete_lock_file($root_path);

		die(generate_log_string("error", "Failed to read from cache file. Please manually delete the cache file and try running the script again"));
	}

	$cached = explode("||", $cache_contents);
	$cached_address = $cached[0];
	$cached_prefix = $cached[1];

	echo "Cached address: " . $cached_address . PHP_EOL;
	echo "Cached prefix: " . $cached_prefix . PHP_EOL;

	echo PHP_EOL;

	// If the cached address and the interface address are equivalent.
	if ($cached_address === $prefixed_address) {
		delete_lock_file($root_path);

		exit(generate_log_string("notice", "The IPv6 prefix for this interface is already up-to-date"));
	}

	/**
	 * Parse pfSense® configuration.
	 *
	 * @var array $config - Config.
	 *
	 * @since 1.0.0
	 */
	parse_config(true);

	// Loop through the entire configuration and find properties that we need to update.
	$found_keys = get_keys($config, $cached_address);

	// Print locations to replace.
	print_locations_to_replace($found_keys);

	echo PHP_EOL;

	// Backup name and path properties.
	$backup_name = $defined_interface_name . "-" . time() . ".xml";
	$backup_path = $root_path . "/../pfsense-ipv6-prefix-updater-backups";

	// Backup the configuration file.
	backup_config_file($backup_name, $g["conf_path"], $backup_path);

	// Make the changes to the configuration.
	$changes_count = make_changes($config, $found_keys, $cached_address, $prefixed_address);

	$message = generate_success_message($defined_interface_name, $defined_prefix_length, $cached_address, $prefixed_address);

	// Only log and reload system if changes were made.
	if ($changes_count !== 0) {
		$cache_contents = $prefixed_address . "||" . $defined_prefix_length;

		// Update the cache file using the newest address.
		update_cache_file($cache_name, $cache_path, $cache_contents);

		// Trigger a notification via pfSense.
		notify_all_remote($message);

		// Write the configuration to the system. Must be root.
		write_config($message);

		echo PHP_EOL;

		echo generate_log_string("success", "Configuration has successfully updated!");
	}

	// Delete the lock file.
	delete_lock_file($root_path);

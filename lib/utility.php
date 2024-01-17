<?php
	use JetBrains\PhpStorm\ExpectedValues;

	/**
	 * Backup config file.
	 *
	 * @param string $backup_name - Backup name.
	 * @param string $conf_path - Conf path.
	 * @param string $backup_path - Backup path.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function backup_config_file(string $backup_name, string $conf_path, string $backup_path): void {
		// If the cache directory does not exist, create it.
		if (!is_dir($backup_path)) {
			mkdir($backup_path, 0755, true);
		}

		echo generate_log_string("progress", "Backing up configuration file before making changes. Copying \"config.xml\" to \"" . $backup_name . "\"");

		echo shell_exec("cp " . $conf_path . "/config.xml " . $backup_path . "/" . $backup_name);
	}

	/**
	 * Color text.
	 *
	 * @param string $color_code - Color code.
	 * @param string $message - Message.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	function color_text(string $color_code, string $message): string {
		return "\033[" . $color_code . "m" . $message . "\033[0m";
	}

	/**
	 * Create cache file.
	 *
	 * @param string $cache_name - Cache name.
	 * @param string $cache_path - Cache path.
	 * @param string $cache_contents - Cache contents.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function create_cache_file(string $cache_name, string $cache_path, string $cache_contents): void {
		// Create the cache file if it does not exist.
		if (!file_exists($cache_path . "/" . $cache_name)) {
			echo generate_log_string("progress", "The cache file does not exist. Creating \"" . $cache_name . "\"");

			echo PHP_EOL;

			// If the cache directory does not exist, create it.
			if (!is_dir($cache_path)) {
				mkdir($cache_path, 0755, true);
			}

			$cache_file = fopen($cache_path . "/" . $cache_name, "w");
			fwrite($cache_file, $cache_contents);
			fclose($cache_file);
		}
	}

	/**
	 * Create lock file.
	 *
	 * @param string $root_path - Root path.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function create_lock_file(string $root_path): void {
		$cache_file = fopen($root_path . "/update.lock", "w");
		fclose($cache_file);
	}

	/**
	 * Delete lock file.
	 *
	 * @param string $root_path - Root path.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function delete_lock_file(string $root_path): void {
		unlink($root_path . "/update.lock");
	}

	/**
	 * Generate log string.
	 *
	 * @param string $type - Type.
	 * @param string $message - Message.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	function generate_log_string(#[ExpectedValues(["success", "progress", "notice", "error"])] string $type, string $message): string {
		return match ($type) {
			"success" => color_text("92;1", "SUCCESS") . ": " . $message . "." . PHP_EOL,
			"progress" => color_text("93;1", "IN PROGRESS") . ": " . $message . " ..." . PHP_EOL,
			"notice" => color_text("94;1", "NOTICE") . ": " . $message . "." . PHP_EOL,
			"error" => color_text("91;1", "ERROR") . ": " . $message . "." . PHP_EOL,
			default => $message . " ..." . PHP_EOL,
		};
	}

	/**
	 * Generate success message.
	 *
	 * @param string $interface_name - Interface name.
	 * @param string $prefix_length - Prefix length.
	 * @param string $old_address - Old address.
	 * @param string $new_address - New address.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	function generate_success_message(string $interface_name, string $prefix_length, string $old_address, string $new_address): string {
		return "pfSense® Dynamic IPv6 Prefix Updater updated the configuration using the \"" . $interface_name . "\" interface with a \"" . $prefix_length . "\" prefix length from \"" . $old_address . "\" to \"" . $new_address . "\".";
	}

	/**
	 * Get keys.
	 *
	 * @param array $array - Array.
	 * @param string $search_text - Search text.
	 * @param array $current_path - Current path.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	function get_keys(array $array, string $search_text, array $current_path = []): array {
		$keys = array();

		foreach ($array as $key => $value) {
			// Build the current path
			$current_key_path = array_merge($current_path, array($key));

			if (is_array($value)) {
				// Recursively search nested arrays.
				$keys = array_merge($keys, get_keys($value, $search_text, $current_key_path));
			} elseif (is_string($value) && str_contains($value, $search_text)) {
				// Found the property, add the entire path to the array.
				$keys[] = $current_key_path;
			}
		}

		return $keys;
	}

	/**
	 * Is pfsense os.
	 *
	 * @param string $etc_path - Etc path.
	 * @param string $inc_path - Inc path.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	function is_pfsense_os(string $etc_path, string $inc_path): bool {
		return (
			preg_match("/(.*?)FreeBSD(.*?)pfSense(.*?)/", shell_exec("uname -a 2>&1"))
			&& file_exists($etc_path . "/rc.reload_all")
			&& file_exists($etc_path . "/version")
			&& file_exists($inc_path . "/config.inc")
			&& file_exists($inc_path . "/globals.inc")
			&& file_exists($inc_path . "/notices.inc")
			&& file_exists($inc_path . "/util.inc")
		);
	}

	/**
	 * Lockfile exists.
	 *
	 * @param string $root_path - Root path.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	function lockfile_exists(string $root_path): bool {
		return file_exists($root_path . "/update.lock");
	}

	/**
	 * Make changes.
	 *
	 * @param array $config - Config.
	 * @param array $found_keys - Found keys.
	 * @param string $old_address - Old address.
	 * @param string $new_address - New address.
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 */
	function make_changes(array &$config, array $found_keys, string $old_address, string $new_address): int {
		$changes_count = 0;

		foreach ($found_keys as $found_key) {
			$path = "\$config";
			$old_value = &$config;

			foreach ($found_key as $property) {
				$path .= (gettype($property) === "string") ? "[\"" . $property . "\"]" : "[" . $property . "]";
				$old_value = &$old_value[$property];
			}

			$new_value = str_replace($old_address, $new_address, $old_value);

			// Only replace the old value with the new value if they are different.
			if ($old_value !== $new_value) {
				echo generate_log_string("progress", "Updating the \"" . color_text("95", $path) . "\" key from " . color_text("92", $old_value) . " to " . color_text("92", $new_value));

				// Increment the changes count.
				$changes_count += 1;

				// Replacing the old value with the new value.
				$old_value = $new_value;
			}
		}

		// If no changes were made, notify the user.
		if ($changes_count === 0) {
			echo generate_log_string("notice", "No changes were made because all the configuration values are identical");
		}

		return $changes_count;
	}

	/**
	 * Print available interfaces.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function print_available_interfaces(): void {
		echo color_text("1;4", "Available interfaces") . ": " . PHP_EOL;
		foreach (get_network_interfaces() as $interface => $prefix) {
			echo $interface . " => " . $prefix["address"] . " / " . $prefix["prefixlen"] . PHP_EOL;
		}
	}

	/**
	 * Print locations to replace.
	 *
	 * @param $found_keys - Found keys.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function print_locations_to_replace($found_keys): void {
		echo color_text("1;4", "Locations to replace") . ": " . PHP_EOL;
		foreach ($found_keys as $found_key) {
			$config_path = "\$config";

			foreach ($found_key as $value) {
				if (gettype($value) === "string") {
					$config_path .= "[\"" . $value . "\"]";
				} else {
					$config_path .= "[" . $value . "]";
				}
			}

			echo $config_path . PHP_EOL;
		}
	}

	/**
	 * Print script header.
	 *
	 * @param string $version - Version.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function print_script_header(string $version): void {
		echo color_text("96", "#####################################################################") . PHP_EOL;
		echo color_text("96", "###          pfSense® Dynamic IPv6 Prefix Updater v" . $version . "          ###") . PHP_EOL;
		echo color_text("96", "###  https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater  ###") . PHP_EOL;
		echo color_text("96", "###                                                               ###") . PHP_EOL;
		echo color_text("96", "###      Copyright (c) 2024 Jacky Liang. All Rights Reserved      ###") . PHP_EOL;
		echo color_text("96", "#####################################################################") . PHP_EOL;
	}

	/**
	 * Print system config.
	 *
	 * @param array $g - G.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function print_system_config(array $g): void {
		echo color_text("1;4", "System") . ": " . $g["product_label"] . " " . $g["product_version"] . PHP_EOL;
		echo color_text("1;4", "Debug mode") . ": " . (($g["debug"] === true) ? "enabled" : "disabled") . PHP_EOL;
	}

	/**
	 * Print system versions.
	 *
	 * @param array $g - G.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function print_system_versions($g): void {
		echo color_text("1;4", "FreeBSD version") . ": " . shell_exec("uname -r");
		echo color_text("1;4", "Configuration version") . ": " . $g["latest_config"] . PHP_EOL;
		echo color_text("1;4", "PHP version") . ": " . shell_exec("php -v | head -n 1 | awk '{print $2}'");
	}

	/**
	 * Update cache file.
	 *
	 * @param string $cache_name - Cache name.
	 * @param string $cache_path - Cache path.
	 * @param string $cache_contents - Cache contents.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	function update_cache_file(string $cache_name, string $cache_path, string $cache_contents): void {
		if (!file_exists($cache_path . "/" . $cache_name)) {
			die(generate_log_string("error", "The cache file (" . $cache_name . ") does not exist. Please run the create_cache_file() function first."));
		}

		echo generate_log_string("progress", "Updating the \"" . color_text("95", $cache_name) . "\" to reflect the latest changes");

		$cache_file = fopen($cache_path . "/" . $cache_name, "w");
		fwrite($cache_file, $cache_contents);
		fclose($cache_file);
	}

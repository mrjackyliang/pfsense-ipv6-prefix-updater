<?php
/**
 * pfSense® IPv6 Firewall Alias Updater
 *
 * This script is designed to automatically update your firewall aliases in case your ISP
 * decides to change your IPv6 prefix, leaving firewall rules outdated/broken.
 *
 * @author Jacky Liang
 *
 * @since 1.0.0
 */
$version = "1.0.0";
$current_dir = dirname(__FILE__) . "/";
$inc_dir = "/etc/inc/";

echo "Initializing pfSense® IPv6 Firewall Alias Updater v" . $version . " ...\n";
echo "Copyright (c) 2023 Jacky Liang. All Rights Reserved.\n";

// Checks if script is executed in a pfSense® environment.
if (
  !preg_match("/(.*?)FreeBSD(.*?)pfSense(.*?)/", shell_exec("uname -a 2>&1"))
  || !file_exists($inc_dir . "config.inc")
  || !file_exists($inc_dir . "util.inc")
) {
  die("ERROR: pfSense® IPv6 Firewall Alias Updater can only be executed in pfSense® environments.");
}

// Checks if there is a configuration file.
if (!file_exists($current_dir . "config.php")) {
  // If the sample configuration exists.
  if (file_exists($current_dir . "config-sample.php")) {
    die("ERROR: The configuration file has not been initialized.");
  } else {
    die("ERROR: The configuration files are missing.");
  }
}

// Script requires.
require_once($inc_dir . "config.inc");
require_once($inc_dir . "util.inc");
require_once($current_dir . "config.php");
require_once($current_dir . "utility.php");

// From the script config file.
global $user_config;

// Start configuration.
foreach ($user_config as $user_config_name => $user_config_options) {
  $user_config_options_interface = $user_config_options["interface"];
  $user_config_options_alias = $user_config_options["alias"];

  if (
    !is_string($user_config_options_interface)
    || !is_string($user_config_options_alias)
    || empty($user_config_options_interface)
    || empty($user_config_options_alias)
  ) {
    echo "ERROR: The configuration options for \"" . $user_config_name . "\" are invalid.";
    continue;
  }

  echo "===========================================\n";
  echo "Current task: " . $user_config_name . "\n";
  echo "===========================================\n";

  /**
   * This shell command should return something like:
   *
   * inet6 0000:0000:0000:0000:0000:0000:0000:0000 prefixlen 64
   *
   * @since 1.0.0
   */
  $inet6 = trim(shell_exec("ifconfig " . $user_config_options_interface . " 2>&1 | grep -E -o \"inet6 [^fF][^eE].*\" 2>&1"));
  $alias = trim(shell_exec("pfctl -t " . $user_config_options_alias . " -T show 2>&1"));

  if (empty($inet6)) {
    echo "ERROR: Failed to retrieve the interface address for \"" . $user_config_options_interface . "\".\n";
    continue;
  }

  if (str_contains($alias, "pfctl: Unknown error")) {
    echo "ERROR: Failed to retrieve the alias result for \"" . $user_config_options_alias . "\".\n";
    continue;
  }

  $ipv6_address = explode(" ", $inet6)[1];
  $ipv6_prefix_length = intval(explode(" ", $inet6)[3]);

  $inet6_prefix = get_ipv6_prefix_postfix("prefix", $ipv6_address, $ipv6_prefix_length);
  $alias_prefix = get_ipv6_prefix_postfix("prefix", $alias, $ipv6_prefix_length);

  echo "Interface Address: $ipv6_address/$ipv6_prefix_length\n";
  echo "Alias Address:     $alias\n";

  echo "\n";

  if ($inet6_prefix === $alias_prefix) {
    echo "Alias already up to date.\n";
    continue;
  }

  echo "Alias is outdated. Updating now ...\n";

  echo "\n";

  /**
   * This will populate the "$config" variable.
   *
   * @var array $config - pfSense system configuration.
   *
   * @since 1.0.0
   */
  parse_config(true);

  $alias_location = get_alias_index($config["aliases"]["alias"], $user_config_options_alias);

  if ($alias_location === -1) {
    echo "ERROR: The alias \"" . $user_config_options_alias . "\" was not found in the system configuration.\n";
    continue;
  }

  $new_alias = $inet6_prefix . ":" . get_ipv6_prefix_postfix("postfix", $alias, $ipv6_prefix_length);

  echo "Old Address: $alias\n";
  echo "New Address: $new_alias\n";

  echo "\n";

  /**
   * Making sure that the new address is a valid IPv6 address.
   *
   * @since 1.0.0
   */
  if (filter_var($new_alias, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
    echo "ERROR: The new address is not a valid IPv6 address. There may be an issue in this script!\n";
    continue;
  }

  /**
   * This is where the alias would be replaced.
   *
   * @since 1.0.0
   */
  $config["aliases"]["alias"][$alias_location] = array_replace(
    $config["aliases"]["alias"][$alias_location],
    array(
      "address" => $new_alias,
      "detail" => "DO NOT EDIT THIS HOST",
    ),
  );

  echo "Updating system configuration ...\n";
  write_config("pfSense® IPv6 Firewall Alias Updater updated the \"" . $user_config_options_alias . "\" alias using the \"" . $user_config_options_interface . "\" interface.");

  echo "Reloading filters ...\n";
  shell_exec("/etc/rc.filter_configure 2>&1");

  echo "\n";

  echo "Update Success!\n";
}

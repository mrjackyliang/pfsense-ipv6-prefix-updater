<?php
/**
 * Get alias index.
 *
 * @param array $aliases - System config aliases.
 * @param string $alias - Alias wanted.
 *
 * @return int
 *
 * @since 1.0.0
 */
function get_alias_index(array $aliases, string $alias): int {
  foreach ($aliases as $alias_index => $alias_options) {
    $alias_options_name = $alias_options['name'];

    // If the current index matches the alias wanted.
    if ($alias_options_name === $alias) {
      return $alias_index;
    }
  }

  return -1;
}

/**
 * Get IPv6 prefix postfix.
 *
 * @param string $type - Accepts "prefix" or "postfix".
 * @param string $address - IPv6 address.
 * @param int $prefix_length - IPv6 prefix length.
 *
 * @return string
 *
 * @since 1.0.0
 */
function get_ipv6_prefix_postfix(string $type, string $address, int $prefix_length): string {
  $address = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

  if (
    (
      $type !== "prefix"
      && $type !== "postfix"
    )
    || $address === false
    || $prefix_length < 1
    || $prefix_length > 128
  ) {
    return '';
  }

  $all_segments = explode(":", $address);
  $prefix_segments = array_slice($all_segments, 0, ceil($prefix_length / 16));
  $postfix_segments = array_slice($all_segments, ceil($prefix_length / 16));

  return implode(':', ($type === "prefix") ? $prefix_segments : $postfix_segments);
}

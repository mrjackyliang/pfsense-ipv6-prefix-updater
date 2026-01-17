<?php
	/**
	 * Get IPv6 prefix.
	 *
	 * @param string $address - IPv6 address.
	 * @param int $prefix_length - IPv6 prefix length.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	function get_ipv6_prefix(string $address, int $prefix_length): string {
		$address = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

		if (
			$address === false
			|| $prefix_length < 48
			|| $prefix_length > 64
		) {
			die(generate_log_string("error", "Invalid IPv6 address (" . $address . ") or prefix length (" . $prefix_length . ")"));
		}

		// Calculate the number of full 16-bit blocks.
		$full_blocks = floor($prefix_length / 16);

		// Calculate the number of remaining bits.
		$remaining_bits = $prefix_length % 16;

		// Split the address into 16-bit blocks.
		$all_segments = explode(":", $address);

		// Take full blocks.
		$prefix_segments = array_slice($all_segments, 0, $full_blocks);

		// If there are remaining bits, adjust the last block.
		if ($remaining_bits > 0) {
			$last_block = hexdec($all_segments[$full_blocks]);
			$last_block >>= (16 - $remaining_bits);

			// Append the adjusted last block to the prefix_segments array.
			$prefix_segments[] = sprintf("%x", $last_block);
		}

		return implode(":", $prefix_segments);
	}

	/**
	 * Get network interfaces.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	function get_network_interfaces(): array {
		$currentInterface = "";
		$interfaces = [];

		exec("ifconfig -a", $lines);

		foreach ($lines as $line) {
			if (preg_match("/^([0-9a-z._]+):/", $line, $matches)) {
				$currentInterface = $matches[1];
			} elseif ($currentInterface !== "" && preg_match("/inet6 ([23][0-9a-f:]+) prefixlen ([0-9]{1,3})/i", $line, $matches)) {
				$interfaces[$currentInterface]["address"] = $matches[1];
				$interfaces[$currentInterface]["prefixlen"] = $matches[2];
			}
		}

		return $interfaces;
	}

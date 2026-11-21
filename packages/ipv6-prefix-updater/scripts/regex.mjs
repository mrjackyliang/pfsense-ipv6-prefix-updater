/**
 * Regex - Curl Call Pattern.
 *
 * Matches one PHP cURL function call such as "curl_exec(". Function names in strings
 * and translation keys like "update_curl_unavailable" do not match.
 *
 * @since 2.1.5
 */
export const curlCallPattern = /\bcurl_[a-z_]+\s*\(/;

/**
 * Regex - Release Signing Key Pattern.
 *
 * Matches one OpenSSH Ed25519 public key accepted by release verification.
 * The package checker applies canonical base64 and decoded-length checks.
 *
 * @since 2.1.0
 */
export const releaseSigningKeyPattern = /^ssh-ed25519\s+(AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+\/]{43})(?:\s.*)?$/;

/**
 * Regex - Semantic Version Pattern.
 *
 * Matches the release versions supported by the generated pfSense package.
 * Keeping the pattern shared prevents validation logic from mixing with I/O.
 *
 * @since 0.0.0
 */
export const semanticVersionPattern = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;

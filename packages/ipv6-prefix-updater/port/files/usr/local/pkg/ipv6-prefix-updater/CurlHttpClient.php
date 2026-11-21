<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;

require_once(__DIR__ . '/HttpClient.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Sends HTTP requests with cURL and pfSense's proxy settings.
 *
 * This is the only class that calls cURL. Every request verifies TLS, follows a few
 * redirects on the same protocols, and honors the proxy set in pfSense.
 *
 * @since 2.1.5
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * Creates a client that sends one fixed user agent.
     *
     * @param string $userAgent - User agent sent with every request.
     *
     * @since 2.1.5
     */
    public function __construct(
        private readonly string $userAgent,
    ) {
    }

    /**
     * Sends one GET request and returns the whole response body.
     *
     * @param string       $url     - Request URL.
     * @param list<string> $headers - Request header lines.
     *
     * @return array{initialized: bool, status: int, body: string|false, error: string}
     *
     * @throws RuntimeException When cURL rejects the request options.
     *
     * @since 2.1.5
     */
    public function get(string $url, array $headers): array
    {
        $curlHandle = curl_init($url);

        if ($curlHandle === false) {
            return [
                'initialized' => false,
                'status' => 0,
                'body' => false,
                'error' => '',
            ];
        }

        $this->configureCurl($curlHandle, $url, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($curlHandle);
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curlHandle);

        curl_close($curlHandle);

        return [
            'initialized' => true,
            'status' => $statusCode,
            'body' => is_string($response) ? $response : false,
            'error' => $curlError,
        ];
    }

    /**
     * Streams one GET response into an open file, stopping past a byte limit.
     *
     * @param string       $url        - Request URL.
     * @param resource     $fileHandle - Open, writable destination file.
     * @param list<string> $headers    - Request header lines.
     * @param int          $byteLimit  - Largest accepted response in bytes.
     *
     * @return array{initialized: bool, completed: bool, status: int, error: string, too_large: bool}
     *
     * @throws RuntimeException When cURL rejects the request options.
     *
     * @since 2.1.5
     */
    public function download(string $url, mixed $fileHandle, array $headers, int $byteLimit): array
    {
        $curlHandle = curl_init($url);

        if ($curlHandle === false) {
            return [
                'initialized' => false,
                'completed' => false,
                'status' => 0,
                'error' => '',
                'too_large' => false,
            ];
        }

        $downloadTooLarge = false;
        $progressCallback = static function (
            mixed $unusedHandle,
            float $downloadTotal,
            float $downloadedCount,
            float $uploadTotal,
            float $uploadedCount,
        ) use (&$downloadTooLarge, $byteLimit): int {
            if ($downloadedCount > $byteLimit) {
                $downloadTooLarge = true;

                return 1;
            }

            return 0;
        };

        $this->configureCurl($curlHandle, $url, [
            CURLOPT_FILE => $fileHandle,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_MAXFILESIZE_LARGE => $byteLimit,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => $progressCallback,
        ]);

        $result = curl_exec($curlHandle);
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlErrorCode = curl_errno($curlHandle);
        $curlError = curl_error($curlHandle);

        curl_close($curlHandle);

        return [
            'initialized' => true,
            'completed' => $result === true,
            'status' => $statusCode,
            'error' => $curlError,
            'too_large' => $downloadTooLarge || $curlErrorCode === CURLE_FILESIZE_EXCEEDED,
        ];
    }

    /**
     * Applies shared cURL safety, timeout, redirect, and proxy options.
     *
     * @param mixed  $curlHandle - cURL handle.
     * @param string $url        - Initial request URL.
     * @param array  $options    - Operation-specific cURL options.
     *
     * @return void
     *
     * @throws RuntimeException When cURL rejects the options.
     *
     * @since 2.1.5
     * @private
     */
    private function configureCurl(mixed $curlHandle, string $url, array $options): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $allowedProtocols = $scheme === 'http'
            ? CURLPROTO_HTTP | CURLPROTO_HTTPS
            : CURLPROTO_HTTPS;
        $sharedOptions = [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => $allowedProtocols,
            CURLOPT_REDIR_PROTOCOLS => $allowedProtocols,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $this->userAgent,
        ];

        if (curl_setopt_array($curlHandle, $options + $sharedOptions) === false) {
            throw new RuntimeException(Translator::translate('update_check_failed_transport'));
        }

        \set_curlproxy($curlHandle);
    }
}

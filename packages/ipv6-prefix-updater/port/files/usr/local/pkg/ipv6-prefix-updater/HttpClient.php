<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

/**
 * Defines the HTTP transport that release sources use.
 *
 * Release sources build URLs and read responses, while the transport owns the
 * connection, TLS, proxy, and size limits, so tests can swap in a fake.
 *
 * @since 2.1.5
 */
interface HttpClient
{
    /**
     * Sends one GET request and returns the whole response body.
     *
     * A false body means the transfer itself failed, and the error then describes
     * the transport failure. Initialized is false when no request could be started.
     *
     * @param string       $url     - Request URL.
     * @param list<string> $headers - Request header lines.
     *
     * @return array{initialized: bool, status: int, body: string|false, error: string}
     *
     * @since 2.1.5
     */
    public function get(string $url, array $headers): array;

    /**
     * Streams one GET response into an open file, stopping past a byte limit.
     *
     * Too large is true when the response passed the limit. Completed is true only
     * when the transfer finished, whatever its HTTP status.
     *
     * @param string       $url        - Request URL.
     * @param resource     $fileHandle - Open, writable destination file.
     * @param list<string> $headers    - Request header lines.
     * @param int          $byteLimit  - Largest accepted response in bytes.
     *
     * @return array{initialized: bool, completed: bool, status: int, error: string, too_large: bool}
     *
     * @since 2.1.5
     */
    public function download(string $url, mixed $fileHandle, array $headers, int $byteLimit): array;
}

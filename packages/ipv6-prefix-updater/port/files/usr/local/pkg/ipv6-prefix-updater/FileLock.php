<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use RuntimeException;

require_once(__DIR__ . '/Translator.php');

/**
 * Coordinates package operations with a filesystem lock.
 *
 * The open descriptor owns the lock for the process lifetime. Callers may try once or
 * wait for a bounded interval when the operation is safe to queue.
 *
 * @since 2.0.0
 */
final class FileLock
{
    /**
     * Open lock-file handle while this instance owns the lock.
     *
     * PHP does not expose a resource property type, so the handle remains mixed and is
     * checked explicitly before release.
     *
     * @var mixed
     *
     * @since 2.0.0
     * @private
     */
    private mixed $handle = null;

    /**
     * Creates a lock coordinator for one package-owned path.
     *
     * Production places this file under "/var/run" while tests use a disposable path
     * to verify concurrent execution behavior without pfSense.
     *
     * @param string $path - Lock file path.
     *
     * @since 2.0.0
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Releases an owned lock when the instance leaves scope.
     *
     * Explicit release in the CLI remains preferred, while the destructor protects
     * exception paths and early returns from leaking the descriptor.
     *
     * @since 2.0.0
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Attempts to acquire the lock within a bounded interval.
     *
     * A zero timeout makes one non-blocking attempt. A false result after the timeout
     * means another valid process still owns the lock.
     *
     * @param int $timeoutSeconds - Maximum seconds to wait.
     *
     * @return bool
     *
     * @throws RuntimeException When the lock file cannot be opened or protected.
     *
     * @since 2.1.3
     */
    public function acquire(int $timeoutSeconds = 0): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }

        $handle = fopen($this->path, 'c');

        if ($handle === false) {
            throw new RuntimeException(Translator::translate('lock_unavailable'));
        }

        if (chmod($this->path, 0600) === false) {
            fclose($handle);

            throw new RuntimeException(Translator::translate('lock_unavailable'));
        }

        $timeoutSeconds = max(0, $timeoutSeconds);
        $deadline = microtime(true) + $timeoutSeconds;

        while (flock($handle, LOCK_EX | LOCK_NB) === false) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                return false;
            }

            usleep(100_000);
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid() . PHP_EOL);
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    /**
     * Releases the current lock and closes its descriptor.
     *
     * The lock file remains in place for reuse, avoiding unsafe deletion races between
     * an exiting process and a newly scheduled process.
     *
     * @return void
     *
     * @since 2.0.0
     */
    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}

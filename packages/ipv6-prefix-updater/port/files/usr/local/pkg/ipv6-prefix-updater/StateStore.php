<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use JsonException;
use RuntimeException;

require_once(__DIR__ . '/Translator.php');

/**
 * Persists last-successful prefix state outside the pfSense configuration.
 *
 * Atomic replacement and restrictive permissions keep interrupted runs from claiming
 * success or exposing operational state to unprivileged local users.
 *
 * @since 2.0.0
 */
final class StateStore
{
    /**
     * Creates a state store for one package-owned JSON file.
     *
     * Tests provide a temporary path while production uses the pfSense variable-data
     * directory, keeping filesystem behavior testable without platform dependencies.
     *
     * @param string $path - State file path.
     *
     * @since 2.0.0
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Returns persisted state or a clean schema when no file exists.
     *
     * Malformed or unsupported state fails closed so a corrupt cache can never be
     * interpreted as a last successfully applied prefix.
     *
     * @return array{schema_version: int, targets: array, last_run: array|null}
     *
     * @throws RuntimeException When state cannot be read or validated.
     *
     * @since 2.0.0
     */
    public function read(): array
    {
        if (file_exists($this->path) === false) {
            return self::emptyState();
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException(Translator::translate('state_read_failed'));
        }

        try {
            $state = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(Translator::translate('state_invalid'), 0, $exception);
        }

        if (!is_array($state) || ($state['schema_version'] ?? null) !== 1) {
            throw new RuntimeException(Translator::translate('state_invalid'));
        }

        if (!is_array($state['targets'] ?? null)) {
            throw new RuntimeException(Translator::translate('state_invalid'));
        }

        $lastRun = $state['last_run'] ?? null;

        if ($lastRun !== null && !is_array($lastRun)) {
            throw new RuntimeException(Translator::translate('state_invalid'));
        }

        $result = [
            'schema_version' => 1,
            'targets' => $state['targets'],
            'last_run' => $lastRun,
        ];

        if (is_array($state['legacy_migration'] ?? null)) {
            $result['legacy_migration'] = $state['legacy_migration'];
        }

        return $result;
    }

    /**
     * Atomically replaces persisted package state.
     *
     * A sibling temporary file is flushed and renamed only after complete JSON output
     * succeeds, preventing partial writes during power loss or interrupted execution.
     *
     * @param array $state - Valid package state.
     *
     * @return void
     *
     * @throws RuntimeException When state cannot be encoded or written atomically.
     *
     * @since 2.0.0
     */
    public function write(array $state): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
            throw new RuntimeException(Translator::translate('state_write_failed'));
        }

        if (chmod($directory, 0700) === false) {
            throw new RuntimeException(Translator::translate('state_write_failed'));
        }

        try {
            $encoded = json_encode(
                $state,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(Translator::translate('state_write_failed'), 0, $exception);
        }

        $temporaryPath = tempnam($directory, '.state-');

        if ($temporaryPath === false) {
            throw new RuntimeException(Translator::translate('state_write_failed'));
        }

        try {
            if (chmod($temporaryPath, 0600) === false) {
                throw new RuntimeException(Translator::translate('state_write_failed'));
            }

            $bytesWritten = file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX);

            if ($bytesWritten === false) {
                throw new RuntimeException(Translator::translate('state_write_failed'));
            }

            if (rename($temporaryPath, $this->path) === false) {
                throw new RuntimeException(Translator::translate('state_write_failed'));
            }

            if (chmod($this->path, 0600) === false) {
                throw new RuntimeException(Translator::translate('state_write_failed'));
            }
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * Returns the package's initial state schema.
     *
     * A separate target map allows later releases to add monitored interfaces without
     * changing the meaning of the current stable primary target identifier.
     *
     * @return array{schema_version: int, targets: array, last_run: null}
     *
     * @since 2.0.0
     * @private
     */
    private static function emptyState(): array
    {
        return [
            'schema_version' => 1,
            'targets' => [],
            'last_run' => null,
        ];
    }
}

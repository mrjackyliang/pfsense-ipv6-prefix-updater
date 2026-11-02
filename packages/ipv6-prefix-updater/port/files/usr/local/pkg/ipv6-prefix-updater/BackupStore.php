<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use JsonException;
use RuntimeException;

require_once(__DIR__ . '/Translator.php');

/**
 * Keeps package-owned copies of "config.xml" with a small metadata file each.
 *
 * pfSense's Config History rotates entries away as other changes are saved, so the
 * package keeps its own copies of the configuration taken before each prefix update,
 * plus the backups the version 1.x script made. Every file is readable by root only.
 *
 * @since 2.0.0
 */
final class BackupStore
{
    /**
     * Number of package-made backups kept before the oldest ones are removed.
     *
     * Imported version 1.x backups are never removed automatically.
     *
     * @since 2.0.0
     */
    public const KEEP_PACKAGE_BACKUPS = 10;

    /**
     * Backup sources the store accepts.
     *
     * @since 2.0.0
     */
    public const SOURCES = [
        'package',
        'migration',
        'legacy',
    ];

    /**
     * Creates a backup store for one directory.
     *
     * @param string $directory - Backup directory.
     *
     * @since 2.0.0
     */
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Lists every readable backup, newest first.
     *
     * A metadata file without its configuration copy, or one that cannot be parsed, is
     * skipped so a single damaged entry never hides the rest of the list.
     *
     * @return list<array{
     *     id: string,
     *     created_at: string,
     *     source: string,
     *     interfaces: list<string>,
     *     summary: string|null,
     *     original_name: string|null,
     *     sha256: string,
     *     size: int
     * }>
     *
     * @since 2.0.0
     */
    public function list(): array
    {
        if (is_dir($this->directory) === false) {
            return [];
        }

        $metadataPaths = glob($this->directory . '/*.json');
        $backups = [];

        foreach (($metadataPaths === false) ? [] : $metadataPaths as $metadataPath) {
            $backup = $this->readMetadata($metadataPath);

            if ($backup !== null) {
                $backups[] = $backup;
            }
        }

        usort(
            $backups,
            static fn (array $left, array $right): int => [$right['created_at'], $right['id']] <=> [$left['created_at'], $left['id']],
        );

        return $backups;
    }

    /**
     * Copies the active configuration into a new backup.
     *
     * The copy is compared with the source by SHA-256 before its metadata is written,
     * so an entry only appears in the list once its file is complete.
     *
     * @param string       $configurationPath - Configuration file path.
     * @param string       $source            - Backup source.
     * @param list<string> $interfaces        - Interfaces the backup relates to.
     * @param string|null  $summary           - Short description.
     *
     * @return array
     *
     * @throws RuntimeException When the copy cannot be written or verified.
     *
     * @since 2.0.0
     */
    public function create(
        string $configurationPath,
        string $source,
        array $interfaces,
        ?string $summary,
    ): array {
        return $this->store($configurationPath, $source, $interfaces, $summary, null, time());
    }

    /**
     * Imports a configuration copy made outside the package.
     *
     * Only files that look like a pfSense configuration are accepted. The original
     * file is left in place; the caller removes it after the import succeeds.
     *
     * @param string       $path       - File to import.
     * @param string       $source     - Backup source.
     * @param list<string> $interfaces - Interfaces the backup relates to.
     * @param int          $createdAt  - Unix time the original backup was made.
     *
     * @return array|null
     *
     * @throws RuntimeException When an accepted file cannot be copied or verified.
     *
     * @since 2.0.0
     */
    public function import(string $path, string $source, array $interfaces, int $createdAt): ?array
    {
        $head = @file_get_contents($path, false, null, 0, 4096);

        if (!is_string($head) || stripos($head, '<pfsense') === false) {
            return null;
        }

        return $this->store($path, $source, $interfaces, null, basename($path), $createdAt);
    }

    /**
     * Deletes one backup and its metadata.
     *
     * @param string $id - Backup identifier.
     *
     * @return void
     *
     * @throws RuntimeException When the identifier is invalid or the files remain.
     *
     * @since 2.0.0
     */
    public function delete(string $id): void
    {
        $this->assertId($id);

        foreach (['.xml', '.json'] as $extension) {
            $path = $this->directory . '/' . $id . $extension;

            if (file_exists($path) && unlink($path) === false) {
                throw new RuntimeException(Translator::translate('backup_delete_failed'));
            }
        }
    }

    /**
     * Returns the configuration file of one backup.
     *
     * @param string $id - Backup identifier.
     *
     * @return string
     *
     * @throws RuntimeException When the identifier is invalid or the backup is missing.
     *
     * @since 2.0.0
     */
    public function path(string $id): string
    {
        $this->assertId($id);

        $path = $this->directory . '/' . $id . '.xml';

        if (is_file($path) === false) {
            throw new RuntimeException(Translator::translate('backup_missing'));
        }

        return $path;
    }

    /**
     * Removes the oldest package-made backups beyond the retention limit.
     *
     * @param int $keep - Number of package-made backups to keep.
     *
     * @return int
     *
     * @since 2.0.0
     */
    public function prune(int $keep = self::KEEP_PACKAGE_BACKUPS): int
    {
        $removed = 0;
        $kept = 0;

        foreach ($this->list() as $backup) {
            if ($backup['source'] === 'legacy') {
                continue;
            }

            $kept++;

            if ($kept > $keep) {
                $this->delete($backup['id']);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Writes one verified copy and its metadata.
     *
     * @param string       $sourcePath   - File to copy.
     * @param string       $source       - Backup source.
     * @param list<string> $interfaces   - Interfaces the backup relates to.
     * @param string|null  $summary      - Short description.
     * @param string|null  $originalName - Original file name for imports.
     * @param int          $createdAt    - Unix creation time.
     *
     * @return array
     *
     * @throws RuntimeException When the copy cannot be written or verified.
     *
     * @since 2.0.0
     * @private
     */
    private function store(
        string $sourcePath,
        string $source,
        array $interfaces,
        ?string $summary,
        ?string $originalName,
        int $createdAt,
    ): array {
        if (in_array($source, self::SOURCES, true) === false) {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }

        $contents = @file_get_contents($sourcePath);

        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }

        $this->ensureDirectory();

        $id = gmdate('Ymd\THis\Z', $createdAt) . '-' . bin2hex(random_bytes(3));
        $sha256 = hash('sha256', $contents);
        $configurationCopy = $this->directory . '/' . $id . '.xml';

        $this->writeFile($configurationCopy, $contents);

        $copied = @file_get_contents($configurationCopy);

        if (!is_string($copied) || hash('sha256', $copied) !== $sha256) {
            @unlink($configurationCopy);

            throw new RuntimeException(Translator::translate('backup_failed'));
        }

        $metadata = [
            'schema_version' => 1,
            'id' => $id,
            'created_at' => gmdate('c', $createdAt),
            'source' => $source,
            'interfaces' => array_values(array_map('strval', $interfaces)),
            'summary' => $summary,
            'original_name' => $originalName,
            'sha256' => $sha256,
        ];

        try {
            $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            @unlink($configurationCopy);

            throw new RuntimeException(Translator::translate('backup_failed'), 0, $exception);
        }

        $this->writeFile($this->directory . '/' . $id . '.json', $encoded . PHP_EOL);

        return $metadata + ['size' => strlen($contents)];
    }

    /**
     * Reads one metadata file and checks it against its configuration copy.
     *
     * @param string $metadataPath - Metadata file path.
     *
     * @return array|null
     *
     * @since 2.0.0
     * @private
     */
    private function readMetadata(string $metadataPath): ?array
    {
        $contents = @file_get_contents($metadataPath);

        if (!is_string($contents)) {
            return null;
        }

        try {
            $metadata = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = $metadata['id'] ?? null;

        if (
            !is_array($metadata)
            || !is_string($id)
            || $this->isValidId($id) === false
            || basename($metadataPath) !== $id . '.json'
            || !is_string($metadata['created_at'] ?? null)
            || in_array($metadata['source'] ?? null, self::SOURCES, true) === false
        ) {
            return null;
        }

        $configurationCopy = $this->directory . '/' . $id . '.xml';
        $size = @filesize($configurationCopy);

        if ($size === false) {
            return null;
        }

        $interfaces = $metadata['interfaces'] ?? [];

        return [
            'id' => $id,
            'created_at' => $metadata['created_at'],
            'source' => $metadata['source'],
            'interfaces' => is_array($interfaces) ? array_values(array_map('strval', $interfaces)) : [],
            'summary' => is_string($metadata['summary'] ?? null) ? $metadata['summary'] : null,
            'original_name' => is_string($metadata['original_name'] ?? null) ? $metadata['original_name'] : null,
            'sha256' => is_string($metadata['sha256'] ?? null) ? $metadata['sha256'] : '',
            'size' => $size,
        ];
    }

    /**
     * Creates the backup directory with owner-only permissions.
     *
     * @return void
     *
     * @throws RuntimeException When the directory cannot be prepared.
     *
     * @since 2.0.0
     * @private
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->directory) === false && @mkdir($this->directory, 0700, true) === false) {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }

        if (@chmod($this->directory, 0700) === false) {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }
    }

    /**
     * Atomically writes one owner-only file.
     *
     * @param string $path     - Destination path.
     * @param string $contents - File contents.
     *
     * @return void
     *
     * @throws RuntimeException When the file cannot be written.
     *
     * @since 2.0.0
     * @private
     */
    private function writeFile(string $path, string $contents): void
    {
        $temporaryPath = tempnam($this->directory, '.backup-');

        if ($temporaryPath === false) {
            throw new RuntimeException(Translator::translate('backup_failed'));
        }

        try {
            if (
                chmod($temporaryPath, 0600) === false
                || file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)
                || rename($temporaryPath, $path) === false
            ) {
                throw new RuntimeException(Translator::translate('backup_failed'));
            }
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * Rejects identifiers that could address files outside the store.
     *
     * @param string $id - Backup identifier.
     *
     * @return void
     *
     * @throws RuntimeException When the identifier is invalid.
     *
     * @since 2.0.0
     * @private
     */
    private function assertId(string $id): void
    {
        if ($this->isValidId($id) === false) {
            throw new RuntimeException(Translator::translate('backup_missing'));
        }
    }

    /**
     * Reports whether an identifier has the store's fixed shape.
     *
     * @param string $id - Backup identifier.
     *
     * @return bool
     *
     * @since 2.0.0
     * @private
     */
    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{6}$/', $id) === 1;
    }
}

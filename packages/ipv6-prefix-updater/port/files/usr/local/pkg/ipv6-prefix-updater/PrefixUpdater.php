<?php

declare(strict_types=1);

namespace PfSense\Ipv6PrefixUpdater\Runtime;

use InvalidArgumentException;
use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationChangePlanner;
use PfSense\Ipv6PrefixUpdater\Domain\ConfigurationTargetScanner;
use PfSense\Ipv6PrefixUpdater\Domain\FieldSelection;
use PfSense\Ipv6PrefixUpdater\Domain\Ipv6Prefix;
use PfSense\Ipv6PrefixUpdater\Domain\MonitoredInterfaces;
use RuntimeException;
use Throwable;

require_once(__DIR__ . '/BackupStore.php');
require_once(__DIR__ . '/ConfigurationChangePlanner.php');
require_once(__DIR__ . '/ConfigurationTargetScanner.php');
require_once(__DIR__ . '/FieldSelection.php');
require_once(__DIR__ . '/Ipv6Prefix.php');
require_once(__DIR__ . '/MonitoredInterfaces.php');
require_once(__DIR__ . '/PlatformAdapter.php');
require_once(__DIR__ . '/StateStore.php');
require_once(__DIR__ . '/Translator.php');

/**
 * Coordinates safe detection, planning, commit, reload, and state updates.
 *
 * The coordinator owns transaction ordering while platform calls remain behind an
 * adapter and all configuration discovery and address transformation stay pure.
 * Every monitored interface is checked in one run, and all of their changes are
 * committed in a single backed-up configuration write.
 *
 * @since 2.0.0
 */
final class PrefixUpdater
{
    /**
     * Severity order used to pick one overall outcome for a multi-interface run.
     *
     * @since 2.0.0
     */
    private const STATUS_SEVERITY = [
        'no_change' => 0,
        'initialization_preview' => 1,
        'initialized' => 2,
        'accepted' => 3,
        'dry_run' => 4,
        'success' => 5,
        'warning' => 6,
        'failure' => 7,
    ];

    /**
     * Largest number of changed fields described in one interface's result.
     *
     * @since 2.0.0
     */
    private const MAX_DESCRIBED_CHANGES = 200;

    /**
     * Creates a prefix update coordinator.
     *
     * All dependencies are explicit so unit tests can simulate write, reload, backup,
     * and notification failures without accessing a real pfSense installation.
     *
     * @param PlatformAdapter $platformAdapter - Platform adapter.
     * @param StateStore      $stateStore      - State store.
     * @param BackupStore     $backupStore     - Package backup store.
     *
     * @since 2.0.0
     */
    public function __construct(
        private readonly PlatformAdapter $platformAdapter,
        private readonly StateStore $stateStore,
        private readonly BackupStore $backupStore,
    ) {
    }

    /**
     * Runs one scheduled, manual, or dry-run prefix check.
     *
     * A scheduled disabled package exits before state or interface reads. Every enabled
     * path revalidates settings and records an operator-safe outcome when possible. An
     * interface without an address is reported on its own while the others proceed.
     *
     * @param bool $manual - Whether a user explicitly requested this run.
     * @param bool $dryRun - Whether configuration mutation is disabled.
     *
     * @return array
     *
     * @throws Throwable When validation or a required transaction stage fails.
     *
     * @since 2.0.0
     */
    public function run(bool $manual, bool $dryRun): array
    {
        $state = null;
        $interfaceLabel = null;
        $backupCreated = false;

        try {
            $this->platformAdapter->assertEnvironment();
            $settings = $this->platformAdapter->getSettings();

            if (($settings['enabled'] ?? '') !== 'on' && $manual === false) {
                return [
                    'status' => 'disabled',
                    'message' => Translator::translate('scheduled_disabled'),
                    'message_key' => 'scheduled_disabled',
                    'message_arguments' => [],
                ];
            }

            $validatedSettings = $this->validateSettings($settings);
            $interfaceLabel = implode(', ', array_column($validatedSettings['targets'], 'interface'));
            $observations = $this->observeTargets($validatedSettings['targets']);
            $state = $this->stateStore->read();
            $results = [];
            $pending = [];

            foreach ($observations['failures'] as $interface => $failure) {
                $results[$interface] = $this->makeResult(
                    'failure',
                    $interface,
                    null,
                    null,
                    null,
                    $failure['prefix_length'],
                    0,
                    false,
                    [],
                    Translator::translate('run_failed', [$failure['error']]),
                    'run_failed',
                    [$failure['error']],
                );
            }

            foreach ($observations['observed'] as $interface => $observation) {
                $targetState = $state['targets'][$interface] ?? null;

                if (!is_array($targetState) || ($targetState['interface'] ?? $interface) !== $interface) {
                    $results[$interface] = $this->initializeTarget($state, $observation, $dryRun);
                    continue;
                }

                $previousPrefix = $targetState['prefix'] ?? null;
                $previousPrefixLength = $targetState['prefix_length'] ?? null;

                if (!is_string($previousPrefix) || !is_int($previousPrefixLength)) {
                    throw new RuntimeException(Translator::translate('state_invalid'));
                }

                $transition = ConfigurationChangePlanner::plan(
                    $previousPrefix,
                    $previousPrefixLength,
                    $observation['source_address'],
                    $observation['prefix_length'],
                    [],
                );

                if ($transition['prefix_changed'] === false) {
                    $results[$interface] = $this->makeResult(
                        'no_change',
                        $interface,
                        $observation['source_address'],
                        $previousPrefix,
                        $observation['prefix'],
                        $observation['prefix_length'],
                        0,
                        false,
                        [],
                        Translator::translate('no_change_message'),
                        'no_change_message',
                    );
                    $state['targets'][$interface]['source_address'] = $observation['source_address'];

                    continue;
                }

                $pending[$interface] = $observation + [
                    'previous_prefix' => $previousPrefix,
                    'previous_prefix_length' => $previousPrefixLength,
                ];
            }

            if (count($pending) > 0) {
                $configuration = $this->platformAdapter->getConfiguration();
                $allTargets = ConfigurationTargetScanner::scanAll($configuration);
                $selection = $validatedSettings['selection'];
                $scannedTargets = array_values(array_filter(
                    $allTargets,
                    static fn (array $target): bool => FieldSelection::isSelected(
                        $selection,
                        $target['group'],
                        $target['field_id'],
                    ),
                ));
                $skippedFields = FieldSelection::missingFields(
                    $selection,
                    array_values(array_filter(
                        array_column($allTargets, 'field_id'),
                        static fn (?string $fieldId): bool => $fieldId !== null,
                    )),
                );
                $plans = [];

                foreach ($pending as $interface => $transition) {
                    $plan = ConfigurationChangePlanner::plan(
                        $transition['previous_prefix'],
                        $transition['previous_prefix_length'],
                        $transition['source_address'],
                        $transition['prefix_length'],
                        $scannedTargets,
                    );

                    if (count($plan['changes']) === 0) {
                        $messageArguments = [
                            $plan['previous_prefix'],
                            $plan['previous_prefix_length'],
                            $plan['current_prefix'],
                            $plan['current_prefix_length'],
                        ];
                        $results[$interface] = $this->makeResult(
                            'warning',
                            $interface,
                            $transition['source_address'],
                            $plan['previous_prefix'],
                            $plan['current_prefix'],
                            $transition['prefix_length'],
                            0,
                            false,
                            [],
                            Translator::translate('prefix_changed_no_matches', $messageArguments),
                            'prefix_changed_no_matches',
                            $messageArguments,
                        );

                        continue;
                    }

                    $plans[$interface] = $plan;
                }

                $changes = $this->mergeChanges($plans);

                if (count($changes) > 0 && $dryRun) {
                    foreach ($plans as $interface => $plan) {
                        $changeCount = count($plan['changes']);
                        $messageArguments = [
                            $changeCount,
                            $plan['current_prefix'],
                            $plan['current_prefix_length'],
                        ];
                        $results[$interface] = $this->makeResult(
                            'dry_run',
                            $interface,
                            $pending[$interface]['source_address'],
                            $plan['previous_prefix'],
                            $plan['current_prefix'],
                            $plan['current_prefix_length'],
                            $changeCount,
                            false,
                            $this->collectServices($plan['changes'], false),
                            Translator::translate('dry_run_changes', $messageArguments),
                            'dry_run_changes',
                            $messageArguments,
                        );
                        $results[$interface]['changes'] = $this->describeChanges($plan['changes']);
                    }
                }

                if (count($changes) > 0 && $dryRun === false) {
                    $services = $this->collectServices($changes, true);

                    $this->platformAdapter->assertReloadCapabilities($services);
                    $this->verifyValues($changes, 'before');
                    $this->platformAdapter->backupConfiguration();
                    $this->backupStore->create(
                        $this->platformAdapter->getConfigurationFilePath(),
                        'package',
                        array_keys($plans),
                        $this->describeTransitions($plans),
                    );
                    $backupCreated = true;

                    try {
                        foreach ($changes as $change) {
                            $this->platformAdapter->setConfigurationValue($change['path'], $change['after']);
                        }

                        $description = Translator::translate(
                            'revision_description',
                            [count($changes), implode(', ', array_keys($plans))],
                        );

                        $this->platformAdapter->writeConfiguration($description);
                    } catch (Throwable $throwable) {
                        foreach ($changes as $change) {
                            $this->platformAdapter->setConfigurationValue($change['path'], $change['before']);
                        }

                        throw $throwable;
                    }

                    $this->verifyValues($changes, 'after');
                    $this->platformAdapter->deleteRoutes($this->replacedRouteNetworks($changes));
                    $this->platformAdapter->reloadServices($this->collectServices($changes, false));
                    $this->verifyValues($changes, 'after');

                    $packageSections = array_values(array_map(
                        static fn (string $service): string => substr($service, strlen('package:')),
                        array_filter(
                            $services,
                            static fn (string $service): bool => str_starts_with($service, 'package:'),
                        ),
                    ));
                    $packageOutcomes = $this->reapplyPackages($packageSections);

                    foreach ($plans as $interface => $plan) {
                        $changeCount = count($plan['changes']);
                        $messageArguments = [
                            $changeCount,
                            $plan['previous_prefix'],
                            $plan['previous_prefix_length'],
                            $plan['current_prefix'],
                            $plan['current_prefix_length'],
                            $interface,
                        ];
                        $results[$interface] = $this->makeResult(
                            'success',
                            $interface,
                            $pending[$interface]['source_address'],
                            $plan['previous_prefix'],
                            $plan['current_prefix'],
                            $plan['current_prefix_length'],
                            $changeCount,
                            true,
                            $this->collectServices($plan['changes'], false),
                            Translator::translate('updated_message', $messageArguments),
                            'updated_message',
                            $messageArguments,
                        );
                        $results[$interface]['changes'] = $this->describeChanges($plan['changes']);
                        $results[$interface]['notification'] = ($validatedSettings['notifications']) ? 'pending' : 'disabled';
                        $state['targets'][$interface] = [
                            'interface' => $interface,
                            'source_address' => $pending[$interface]['source_address'],
                            'prefix' => $plan['current_prefix'],
                            'prefix_length' => $plan['current_prefix_length'],
                            'updated_at' => $results[$interface]['timestamp'],
                        ];
                    }

                    try {
                        $this->backupStore->prune();
                    } catch (Throwable) {
                        $this->platformAdapter->log(Translator::translate('backup_prune_failed'));
                    }
                }
            }

            $orderedResults = $this->orderResults($results, $validatedSettings['targets']);
            $result = $this->combineResults($orderedResults, $dryRun, $backupCreated, count($changes ?? []));
            $runDetails = $this->runDetails($skippedFields ?? [], $packageOutcomes ?? []);
            $result += $runDetails;
            $state['last_run'] = $result;

            $this->stateStore->write($state);

            foreach ($orderedResults as $targetResult) {
                $this->platformAdapter->log($targetResult['message']);

                if ($targetResult['status'] === 'warning') {
                    $this->safeNotice('no-matches', $targetResult['message'], 1, true);
                }

                if ($targetResult['status'] === 'failure') {
                    $this->safeNotice('failure', $targetResult['message'], 2, true);
                }
            }

            $this->reportRunDetails($runDetails);

            if ($backupCreated && $validatedSettings['notifications']) {
                foreach ($orderedResults as $interface => $targetResult) {
                    if ($targetResult['status'] !== 'success') {
                        continue;
                    }

                    try {
                        $this->platformAdapter->notice('success', $targetResult['message'], 0, false);
                        $orderedResults[$interface]['notification'] = 'sent';
                    } catch (Throwable) {
                        $orderedResults[$interface]['notification'] = 'failed';
                        $this->platformAdapter->log(Translator::translate('notification_failed'));
                    }
                }

                $result = $this->combineResults($orderedResults, $dryRun, $backupCreated, count($changes ?? []));
                $result += $runDetails;
                $state['last_run'] = $result;

                try {
                    $this->stateStore->write($state);
                } catch (Throwable) {
                    $this->platformAdapter->log(Translator::translate('notification_state_failed'));
                }
            }

            return $result;
        } catch (Throwable $throwable) {
            $message = Translator::translate('run_failed', [$throwable->getMessage()]);

            $this->platformAdapter->log($message);
            $this->safeNotice('failure', $message, 2, true);

            if (is_array($state)) {
                $failureResult = $this->makeResult(
                    'failure',
                    $interfaceLabel,
                    null,
                    null,
                    null,
                    null,
                    0,
                    $backupCreated,
                    [],
                    $message,
                    'run_failed',
                    [$throwable->getMessage()],
                );
                $state['last_run'] = $failureResult;

                try {
                    $this->stateStore->write($state);
                } catch (Throwable) {
                    $this->platformAdapter->log(Translator::translate('failure_state_failed'));
                }
            }

            throw $throwable;
        }
    }

    /**
     * Accepts the active prefix as the operator-confirmed baseline.
     *
     * This recovery path changes package state only. It reads package settings and the
     * active addresses but never scans approved fields, backs up, writes, or reloads.
     * Without an interface, every monitored interface that has an address is accepted.
     *
     * @param string|null $onlyInterface - Interface to accept, or null for all.
     *
     * @return array
     *
     * @throws Throwable When validation, address discovery, or state persistence fails.
     *
     * @since 2.0.0
     */
    public function acceptCurrentPrefix(?string $onlyInterface = null): array
    {
        $this->platformAdapter->assertEnvironment();
        $settings = $this->platformAdapter->getSettings();
        $validatedSettings = $this->validateSettings($settings);
        $targets = $validatedSettings['targets'];

        if ($onlyInterface !== null) {
            $targets = array_values(array_filter(
                $targets,
                static fn (array $target): bool => $target['interface'] === $onlyInterface,
            ));

            if (count($targets) === 0) {
                throw new InvalidArgumentException(Translator::translate('interface_missing', [$onlyInterface]));
            }
        }

        $observations = $this->observeTargets($targets);
        $state = $this->stateStore->read();
        $results = [];

        foreach ($observations['failures'] as $interface => $failure) {
            $results[$interface] = $this->makeResult(
                'failure',
                $interface,
                null,
                null,
                null,
                $failure['prefix_length'],
                0,
                false,
                [],
                Translator::translate('run_failed', [$failure['error']]),
                'run_failed',
                [$failure['error']],
            );
        }

        foreach ($observations['observed'] as $interface => $observation) {
            $targetState = $state['targets'][$interface] ?? null;
            $previousPrefix = is_array($targetState) && is_string($targetState['prefix'] ?? null)
                ? $targetState['prefix']
                : null;
            $messageArguments = [$observation['prefix'], $observation['prefix_length'], $interface];
            $results[$interface] = $this->makeResult(
                'accepted',
                $interface,
                $observation['source_address'],
                $previousPrefix,
                $observation['prefix'],
                $observation['prefix_length'],
                0,
                false,
                [],
                Translator::translate('accept_current_prefix_message', $messageArguments),
                'accept_current_prefix_message',
                $messageArguments,
            );
            $state['targets'][$interface] = [
                'interface' => $interface,
                'source_address' => $observation['source_address'],
                'prefix' => $observation['prefix'],
                'prefix_length' => $observation['prefix_length'],
                'updated_at' => $results[$interface]['timestamp'],
            ];
        }

        $orderedResults = $this->orderResults($results, $targets);

        if (count($orderedResults) === 1) {
            $result = reset($orderedResults);
        } else {
            $acceptedCount = count($observations['observed']);
            $messageArguments = [$acceptedCount, count($orderedResults)];
            $result = $this->makeResult(
                ($acceptedCount === count($orderedResults)) ? 'accepted' : 'warning',
                implode(', ', array_keys($orderedResults)),
                null,
                null,
                null,
                null,
                0,
                false,
                [],
                Translator::translate('accepted_summary', $messageArguments),
                'accepted_summary',
                $messageArguments,
            );
            $result['targets'] = array_values($orderedResults);
        }

        $state['last_run'] = $result;

        $this->stateStore->write($state);

        foreach ($orderedResults as $targetResult) {
            $this->platformAdapter->log($targetResult['message']);
        }

        return $result;
    }

    /**
     * Returns required services in first-seen order without duplicates.
     *
     * Each service originates from the scanner rather than path inference. Package
     * re-applies are listed only on request, because they run after the reloads, and
     * "none" marks values that are only written.
     *
     * @param array $changes          - Planned field changes.
     * @param bool  $includePackages  - Whether "package:<section>" entries are kept.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private function collectServices(array $changes, bool $includePackages): array
    {
        $services = [];

        foreach ($changes as $change) {
            $service = $change['service'];

            if ($service === 'none' || ($includePackages === false && str_starts_with($service, 'package:'))) {
                continue;
            }

            if (in_array($service, $services, true) === false) {
                $services[] = $service;
            }
        }

        return $services;
    }

    /**
     * Returns the previous networks of changed static routes.
     *
     * @param array $changes - Committed field changes.
     *
     * @return list<string>
     *
     * @since 2.0.0
     * @private
     */
    private function replacedRouteNetworks(array $changes): array
    {
        $networks = [];

        foreach ($changes as $change) {
            if (preg_match('#^staticroutes/route/[0-9]+/network$#', $change['path']) === 1) {
                $networks[] = $change['before'];
            }
        }

        return $networks;
    }

    /**
     * Lists each changed field with the exact address replacements in it.
     *
     * Dry runs show these before anything is written, and successful runs keep them for
     * the Status tab. Only the replaced addresses are recorded, not whole values.
     *
     * @param array $changes - Planned field changes.
     *
     * @return list<array{path: string, replacements: list<array{0: string, 1: string}>}>
     *
     * @since 2.0.0
     * @private
     */
    private function describeChanges(array $changes): array
    {
        return array_map(
            static fn (array $change): array => [
                'path' => $change['path'],
                'replacements' => $change['replacements'] ?? [],
            ],
            array_slice($changes, 0, self::MAX_DESCRIBED_CHANGES),
        );
    }

    /**
     * Re-applies the packages whose settings changed, without ever failing the run.
     *
     * The write is already verified, so an adapter error is reported for every
     * section instead of being thrown.
     *
     * @param list<string> $sections - Package settings sections that changed.
     *
     * @return list<array{section: string, package: string|null, status: string, error: string|null}>
     *
     * @since 2.0.0
     * @private
     */
    private function reapplyPackages(array $sections): array
    {
        if (count($sections) === 0) {
            return [];
        }

        try {
            return $this->platformAdapter->reapplyPackages($sections);
        } catch (Throwable $throwable) {
            return array_map(
                static fn (string $section): array => [
                    'section' => $section,
                    'package' => null,
                    'status' => 'failed',
                    'error' => $throwable->getMessage(),
                ],
                $sections,
            );
        }
    }

    /**
     * Logs skipped fields and package re-applies, with a notice for each problem.
     *
     * A package that couldn't be re-applied keeps its new values, so its notice asks
     * the operator to finish the job by hand.
     *
     * @param array $runDetails - Run details from runDetails().
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private function reportRunDetails(array $runDetails): void
    {
        $skippedFields = $runDetails['skipped_fields'] ?? [];

        if (count($skippedFields) > 0) {
            $this->platformAdapter->log(Translator::translate('skipped_fields_message', [implode(', ', $skippedFields)]));
        }

        foreach ($runDetails['package_reapply'] ?? [] as $outcome) {
            $message = match ($outcome['status']) {
                'reapplied' => Translator::translate('package_reapplied', [$outcome['package']]),
                'unknown_owner' => Translator::translate('package_owner_unknown', [$outcome['section']]),
                default => Translator::translate(
                    'package_reapply_failed',
                    [$outcome['package'] ?? $outcome['section'], (string) $outcome['error']],
                ),
            };

            $this->platformAdapter->log($message);

            if ($outcome['status'] !== 'reapplied') {
                $this->safeNotice('package-' . $outcome['section'], $message, 1, true);
            }
        }
    }

    /**
     * Returns the run details worth keeping with the result.
     *
     * Empty lists are left out so results without them keep their earlier shape.
     *
     * @param list<string> $skippedFields   - Included fields that no longer exist.
     * @param list<array>  $packageOutcomes - Package re-apply outcomes.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private function runDetails(array $skippedFields, array $packageOutcomes): array
    {
        $runDetails = [];

        if (count($skippedFields) > 0) {
            $runDetails['skipped_fields'] = $skippedFields;
        }

        if (count($packageOutcomes) > 0) {
            $runDetails['package_reapply'] = $packageOutcomes;
        }

        return $runDetails;
    }

    /**
     * Picks one overall result for the interfaces checked in a run.
     *
     * A single interface returns its own result unchanged. Several interfaces return a
     * summary with the most severe outcome and the per-interface results under
     * "targets", so the Status page and command output can show each one.
     *
     * @param array<string, array> $results          - Results keyed by interface.
     * @param bool                 $dryRun           - Whether this was a dry run.
     * @param bool                 $backupCreated    - Whether this run created a backup.
     * @param int                  $totalChangeCount - Changed fields after merging.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private function combineResults(array $results, bool $dryRun, bool $backupCreated, int $totalChangeCount): array
    {
        if (count($results) === 1) {
            return reset($results);
        }

        $status = 'no_change';
        $updated = 0;
        $unchanged = 0;
        $attention = 0;
        $services = [];

        foreach ($results as $targetResult) {
            $targetStatus = $targetResult['status'];

            if (self::STATUS_SEVERITY[$targetStatus] > self::STATUS_SEVERITY[$status]) {
                $status = $targetStatus;
            }

            match ($targetStatus) {
                'success', 'dry_run' => $updated++,
                'warning', 'failure' => $attention++,
                default => $unchanged++,
            };

            foreach ($targetResult['services'] as $service) {
                if (in_array($service, $services, true) === false) {
                    $services[] = $service;
                }
            }
        }

        if ($status === 'failure' && $attention < count($results)) {
            $status = 'warning';
        }

        $messageKey = ($dryRun) ? 'dry_run_summary' : 'run_summary';
        $messageArguments = [count($results), $updated, $unchanged, $attention];
        $result = $this->makeResult(
            $status,
            implode(', ', array_keys($results)),
            null,
            null,
            null,
            null,
            $totalChangeCount,
            $backupCreated,
            $services,
            Translator::translate($messageKey, $messageArguments),
            $messageKey,
            $messageArguments,
        );
        $result['targets'] = array_values($results);

        return $result;
    }

    /**
     * Describes the prefix transitions saved with a package backup.
     *
     * @param array<string, array> $plans - Plans keyed by interface.
     *
     * @return string
     *
     * @since 2.0.0
     * @private
     */
    private function describeTransitions(array $plans): string
    {
        $descriptions = [];

        foreach ($plans as $interface => $plan) {
            $descriptions[] = sprintf(
                '%s: %s/%d → %s/%d',
                $interface,
                $plan['previous_prefix'],
                $plan['previous_prefix_length'],
                $plan['current_prefix'],
                $plan['current_prefix_length'],
            );
        }

        return implode('; ', $descriptions);
    }

    /**
     * Records a baseline without changing firewall configuration.
     *
     * A first run has no trustworthy old prefix, and a newly monitored interface is a
     * new target. Both cases establish a baseline instead of guessing replacements.
     *
     * @param array $state       - Current package state, updated in place.
     * @param array $observation - Active address and prefix for one interface.
     * @param bool  $dryRun      - Whether state initialization is preview-only.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private function initializeTarget(array &$state, array $observation, bool $dryRun): array
    {
        $interface = $observation['interface'];
        $status = ($dryRun) ? 'initialization_preview' : 'initialized';
        $messageKey = ($dryRun) ? 'initialize_dry' : 'initialize';
        $messageArguments = [$interface, $observation['prefix'], $observation['prefix_length']];
        $result = $this->makeResult(
            $status,
            $interface,
            $observation['source_address'],
            null,
            $observation['prefix'],
            $observation['prefix_length'],
            0,
            false,
            [],
            Translator::translate($messageKey, $messageArguments),
            $messageKey,
            $messageArguments,
        );

        if ($dryRun === false) {
            $state['targets'][$interface] = [
                'interface' => $interface,
                'source_address' => $observation['source_address'],
                'prefix' => $observation['prefix'],
                'prefix_length' => $observation['prefix_length'],
                'updated_at' => $result['timestamp'],
            ];
        }

        return $result;
    }

    /**
     * Creates one consistent operator-visible result record.
     *
     * Results contain the selected source address, network prefixes, counts, services,
     * and safe messages. Unrelated firewall configuration never enters package state.
     *
     * @param string      $status              - Outcome status.
     * @param string|null $interface           - Monitored interface.
     * @param string|null $observedAddress      - Active canonical source address.
     * @param string|null $previousPrefix      - Previous prefix.
     * @param string|null $currentPrefix       - Current prefix.
     * @param int|null    $currentPrefixLength - Current prefix length.
     * @param int         $changeCount         - Planned or committed change count.
     * @param bool        $backupCreated       - Whether this run created a backup.
     * @param list<string> $services           - Referenced services.
     * @param string       $message             - Operator-safe summary.
     * @param string|null  $messageKey          - Stable translation key.
     * @param list<scalar> $messageArguments    - Translation arguments.
     *
     * @return array
     *
     * @since 2.0.0
     * @private
     */
    private function makeResult(
        string $status,
        ?string $interface,
        ?string $observedAddress,
        ?string $previousPrefix,
        ?string $currentPrefix,
        ?int $currentPrefixLength,
        int $changeCount,
        bool $backupCreated,
        array $services,
        string $message,
        ?string $messageKey = null,
        array $messageArguments = [],
    ): array {
        return [
            'status' => $status,
            'timestamp' => gmdate('c'),
            'interface' => $interface,
            'observed_address' => $observedAddress,
            'previous_prefix' => $previousPrefix,
            'current_prefix' => $currentPrefix,
            'current_prefix_length' => $currentPrefixLength,
            'change_count' => $changeCount,
            'backup_created' => $backupCreated,
            'services' => $services,
            'message' => $message,
            'message_key' => $messageKey,
            'message_arguments' => $messageArguments,
        ];
    }

    /**
     * Combines per-interface plans into one list of field changes.
     *
     * Interfaces that share a delegation plan the same field the same way, so identical
     * changes are kept once. Two plans that disagree about one field stop the run before
     * anything is written, because only an operator can decide which prefix is right.
     *
     * @param array<string, array> $plans - Plans keyed by interface.
     *
     * @return list<array>
     *
     * @throws RuntimeException When two plans set one field to different values.
     *
     * @since 2.0.0
     * @private
     */
    private function mergeChanges(array $plans): array
    {
        $changesByPath = [];

        foreach ($plans as $plan) {
            foreach ($plan['changes'] as $change) {
                $path = $change['path'];

                if (isset($changesByPath[$path])) {
                    if ($changesByPath[$path]['after'] !== $change['after']) {
                        throw new RuntimeException(Translator::translate('targets_conflict', [$path]));
                    }

                    continue;
                }

                $changesByPath[$path] = $change;
            }
        }

        return array_values($changesByPath);
    }

    /**
     * Reads the active address and prefix of each monitored interface.
     *
     * One interface without an address is reported as a failure for that interface
     * only. When every interface fails, the first error is thrown so a single-interface
     * setup keeps failing loudly.
     *
     * @param list<array{interface: string, prefix_length: int}> $targets - Validated targets.
     *
     * @return array{
     *     observed: array<string, array{interface: string, source_address: string, prefix: string, prefix_length: int}>,
     *     failures: array<string, array{prefix_length: int, error: string}>
     * }
     *
     * @throws Throwable When no monitored interface can be read.
     *
     * @since 2.0.0
     * @private
     */
    private function observeTargets(array $targets): array
    {
        $observed = [];
        $failures = [];
        $firstThrowable = null;

        foreach ($targets as $target) {
            $interface = $target['interface'];

            try {
                $sourceAddress = Ipv6Prefix::canonicalize(
                    $this->platformAdapter->getCurrentIpv6Address($interface),
                );
                $observed[$interface] = [
                    'interface' => $interface,
                    'source_address' => $sourceAddress,
                    'prefix' => Ipv6Prefix::networkAddress($sourceAddress, $target['prefix_length']),
                    'prefix_length' => $target['prefix_length'],
                ];
            } catch (Throwable $throwable) {
                $firstThrowable ??= $throwable;
                $failures[$interface] = [
                    'prefix_length' => $target['prefix_length'],
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        if (count($observed) === 0 && $firstThrowable !== null) {
            throw $firstThrowable;
        }

        return [
            'observed' => $observed,
            'failures' => $failures,
        ];
    }

    /**
     * Orders results the way the interfaces appear in settings.
     *
     * @param array<string, array>                                 $results - Results keyed by interface.
     * @param list<array{interface: string, prefix_length: int}> $targets - Validated targets.
     *
     * @return array<string, array>
     *
     * @since 2.0.0
     * @private
     */
    private function orderResults(array $results, array $targets): array
    {
        $orderedResults = [];

        foreach ($targets as $target) {
            if (isset($results[$target['interface']])) {
                $orderedResults[$target['interface']] = $results[$target['interface']];
            }
        }

        return $orderedResults;
    }

    /**
     * Creates a notice without allowing notification problems to mask the run result.
     *
     * Warning and failure notices are local-only operational visibility. Any notice
     * subsystem failure is logged and the original transaction outcome remains intact.
     *
     * @param string $identifier - Notice identifier.
     * @param string $message    - Notice message.
     * @param int    $priority   - Notice priority.
     * @param bool   $localOnly  - Whether remote delivery is disabled.
     *
     * @return void
     *
     * @since 2.0.0
     * @private
     */
    private function safeNotice(string $identifier, string $message, int $priority, bool $localOnly): void
    {
        try {
            $this->platformAdapter->notice($identifier, $message, $priority, $localOnly);
        } catch (Throwable) {
            $this->platformAdapter->log(Translator::translate('notice_failed'));
        }
    }

    /**
     * Validates every persisted runtime setting.
     *
     * WebGUI validation improves feedback but does not establish trust. The executor
     * independently checks each interface, prefix bounds, duplicates, scopes, and
     * approved fields.
     *
     * @param array $settings - Persisted package settings.
     *
     * @return array{
     *     targets: list<array{interface: string, prefix_length: int}>,
     *     notifications: bool,
     *     selection: array{groups: array<string, bool>, include: list<string>, exclude: list<string>}
     * }
     *
     * @throws InvalidArgumentException When settings are incomplete or invalid.
     *
     * @since 2.0.0
     * @private
     */
    private function validateSettings(array $settings): array
    {
        $rows = MonitoredInterfaces::fromSettings($settings);
        $targets = [];

        if (count($rows) === 0) {
            throw new InvalidArgumentException(Translator::translate('interface_required'));
        }

        foreach ($rows as $row) {
            $interface = $row['interface'];
            $prefixLengthValue = $row['prefix_length'];

            if (!is_string($interface) || trim($interface) === '') {
                throw new InvalidArgumentException(Translator::translate('interface_required'));
            }

            $interface = trim($interface);

            if ($this->platformAdapter->interfaceExists($interface) === false) {
                throw new InvalidArgumentException(Translator::translate('interface_missing', [$interface]));
            }

            if (isset($targets[$interface])) {
                throw new InvalidArgumentException(Translator::translate('interface_duplicated', [$interface]));
            }

            if (
                (!is_string($prefixLengthValue) && !is_int($prefixLengthValue))
                || ctype_digit((string) $prefixLengthValue) === false
            ) {
                throw new InvalidArgumentException(Translator::translate('prefix_integer_error'));
            }

            $prefixLength = (int) $prefixLengthValue;

            if ($prefixLength < Ipv6Prefix::MIN_PREFIX_LENGTH || $prefixLength > Ipv6Prefix::MAX_PREFIX_LENGTH) {
                throw new InvalidArgumentException(Translator::translate('prefix_length_error'));
            }

            $targets[$interface] = [
                'interface' => $interface,
                'prefix_length' => $prefixLength,
            ];
        }

        $selection = FieldSelection::fromStorage($this->platformAdapter->getFieldSelection());

        if (FieldSelection::hasSelection($selection) === false) {
            throw new InvalidArgumentException(Translator::translate('fields_runtime_error'));
        }

        return [
            'targets' => array_values($targets),
            'notifications' => ($settings['notifications'] ?? '') === 'on',
            'selection' => $selection,
        ];
    }

    /**
     * Verifies planned values immediately before or after the configuration commit.
     *
     * Strict comparison detects concurrent edits, schema conversions, and uncertain
     * write results instead of silently accepting a partially applied transaction.
     *
     * @param array  $changes - Planned field changes.
     * @param string $stage   - Expected value stage.
     *
     * @return void
     *
     * @throws RuntimeException When any exact path differs from the plan.
     *
     * @since 2.0.0
     * @private
     */
    private function verifyValues(array $changes, string $stage): void
    {
        if ($stage !== 'before' && $stage !== 'after') {
            throw new RuntimeException(Translator::translate('verification_stage_invalid'));
        }

        foreach ($changes as $change) {
            $actualValue = $this->platformAdapter->getConfigurationValue($change['path']);
            $expectedValue = $change[$stage];

            if ($actualValue !== $expectedValue) {
                throw new RuntimeException(Translator::translate(
                    'configuration_path_changed',
                    [$change['path']],
                ));
            }
        }
    }
}

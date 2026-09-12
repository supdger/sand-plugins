<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use support\Log;

/**
 * The scheduler owns only process-local cadence and retry state. The existing
 * SyncConnectorService remains the authority for cursors, conflicts, disable
 * propagation, SyncRun records and audit writes.
 */
final class DirectorySyncScheduler
{
    /** @var array<int,int> Unix timestamps keyed by connector id. */
    private array $retryAt = [];

    /** @var array<int,int> Consecutive transient failures keyed by connector id. */
    private array $attempts = [];

    /** @var array<int,int> Last selected connector per organization. */
    private array $lastConnectorByOrganization = [];

    /**
     * Non-transient service failures are deliberately retried on the next tick
     * without a backoff. Keep the complete safe scheduling tuple only in this
     * process; it is validated and discarded with the other retry state.
     *
     * @var array<int,array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}>
     */
    private array $immediateRetry = [];

    private ?int $lastOrganizationId = null;

    // Keyset position persists for the life of this worker. It is advanced by
    // the connector actually claimed, never by a fixed low-id query prefix.
    private ?int $scanAfterId = null;

    private bool $stopping = false;

    /** @param null|callable():int $clock */
    public function __construct(
        private readonly DirectorySyncScheduleRepository $connectors = new EloquentDirectorySyncScheduleRepository(),
        private readonly DirectorySyncRunExecutor $runs = new ServiceDirectorySyncRunExecutor(),
        private readonly ?\Closure $clock = null,
        private readonly ?int $retryBaseSeconds = null,
        private readonly ?int $retryMaxSeconds = null,
    ) {}

    /**
     * @return array{stopped:bool,eligible:int,skipped:int,deferred:int,retry_state_pruned:int,succeeded:int,failed:int}
     */
    public function tick(int $batchLimit): array
    {
        $result = ['stopped' => $this->stopping, 'eligible' => 0, 'skipped' => 0, 'deferred' => 0, 'retry_state_pruned' => 0, 'succeeded' => 0, 'failed' => 0];
        if ($this->stopping) {
            return $result;
        }

        $batchLimit = max(1, min(100, $batchLimit));
        $now = $this->now();
        $result['retry_state_pruned'] = $this->pruneRetryState();
        $scanLimit = min(500, max(8, $batchLimit * 8));
        $candidates = $this->connectors->dueAfter($this->scanAfterId, $scanLimit);
        if ($candidates === [] && $this->scanAfterId !== null) {
            // At most one bounded wrap query per tick. Do not widen scans until
            // all ids above the persistent keyset cursor have been considered.
            $candidates = $this->connectors->dueAfter(null, $scanLimit);
        }
        $byOrganization = [];

        foreach ($candidates as $candidate) {
            if ($this->stopping) {
                break;
            }
            if (!$this->eligible($candidate)) {
                $result['skipped']++;
                continue;
            }

            $connectorId = (int) $candidate['id'];
            $organizationId = (int) $candidate['organization_id'];
            if (($this->retryAt[$connectorId] ?? 0) > $now) {
                $result['deferred']++;
                continue;
            }
            $byOrganization[$organizationId][] = $candidate;
        }

        foreach ($byOrganization as $organizationCandidates) {
            $result['deferred'] += max(0, count($organizationCandidates) - 1);
        }

        // An immediately retryable business failure is not a priority lane:
        // it becomes eligible again and competes with normal due connectors in
        // the same organization and connection rotation. This keeps a durable
        // conflict from consuming a batch slot or starving another tenant.
        foreach ($this->immediateRetry as $connectorId => $candidate) {
            $organizationId = (int) $candidate['organization_id'];
            $alreadyPresent = false;
            foreach ($byOrganization[$organizationId] ?? [] as $normalCandidate) {
                if ((int) $normalCandidate['id'] === (int) $connectorId) {
                    $alreadyPresent = true;
                    break;
                }
            }
            if (!$alreadyPresent) {
                $byOrganization[$organizationId][] = $candidate;
            }
        }

        $selected = [];
        $selectedOrganizations = [];
        foreach ($this->organizationsInRotation($byOrganization) as $organizationId) {
            if ($this->stopping || count($selected) >= $batchLimit) {
                break;
            }
            if (isset($selectedOrganizations[$organizationId])) {
                continue;
            }
            $selected[] = $this->nextConnector($organizationId, $byOrganization[$organizationId]);
            $selectedOrganizations[$organizationId] = true;
        }

        foreach ($selected as $candidate) {
            if ($this->stopping) {
                break;
            }
            $connectorId = (int) $candidate['id'];
            $organizationId = (int) $candidate['organization_id'];

            // One connector per organization per tick prevents a noisy tenant
            // from consuming the bounded batch. Rotation preserves fairness
            // across ticks rather than repeatedly selecting the lowest id.
            // The process is single threaded; SyncConnectorService also
            // enforces one running run per connector in PostgreSQL.
            $this->lastConnectorByOrganization[$organizationId] = $connectorId;
            $this->lastOrganizationId = $organizationId;
            $this->scanAfterId = $connectorId;
            $result['eligible']++;
            $attempt = ($this->attempts[$connectorId] ?? 0) + 1;
            $requestId = sprintf('sync-worker.%d.%d.%d', $connectorId, $attempt, $now);
            unset($this->immediateRetry[$connectorId]);

            try {
                $this->runs->run($connectorId, (int) $candidate['application_id'], 'system:directory-sync-worker', $requestId);
                unset($this->attempts[$connectorId], $this->retryAt[$connectorId]);
                $result['succeeded']++;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $errorCode = $this->errorCode($exception);
                $retryAt = null;
                if ($this->retryable($errorCode)) {
                    $this->attempts[$connectorId] = $attempt;
                    $retryAt = $now + $this->backoffSeconds($attempt);
                    $this->retryAt[$connectorId] = $retryAt;
                } else {
                    unset($this->attempts[$connectorId], $this->retryAt[$connectorId]);
                    $this->immediateRetry[$connectorId] = $candidate;
                }

                // Never include exception messages: drivers can include remote
                // request fragments and configured credentials in their text.
                Log::warning('SandIAM directory sync worker run failed', [
                    'connector_id' => $connectorId,
                    'organization_id' => $organizationId,
                    'application_id' => (int) $candidate['application_id'],
                    'error_code' => $errorCode,
                    'retry_at' => $retryAt,
                ]);
            }
        }

        if ($result['eligible'] === 0 && $candidates !== []) {
            // Every scanned connector is temporarily deferred or invalid. Move
            // the keyset window forward to avoid re-reading that same bounded
            // page until a retry becomes due or the cursor wraps.
            $this->scanAfterId = (int) $candidates[array_key_last($candidates)]['id'];
        }

        $result['stopped'] = $this->stopping;
        return $result;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** @param array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool} $candidate */
    private function eligible(array $candidate): bool
    {
        return (int) $candidate['id'] > 0
            && (int) $candidate['organization_id'] > 0
            && (int) $candidate['application_id'] > 0
            && (int) $candidate['status'] === 1
            && $candidate['config_configured'] === true;
    }

    private function retryable(string $errorCode): bool
    {
        return str_starts_with($errorCode, 'SAND_IAM_SYNC_REMOTE_')
            || in_array($errorCode, ['SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID', 'SAND_IAM_SYNC_OUTBOUND_FAILED', 'SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED', 'SAND_IAM_SYNC_OUTBOUND_PAYLOAD_INVALID', 'SAND_IAM_SYNC_RUN_FAILED'], true);
    }

    /** @param array<int,list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}>> $byOrganization @return list<int> */
    private function organizationsInRotation(array $byOrganization): array
    {
        $organizationIds = array_keys($byOrganization);
        sort($organizationIds, SORT_NUMERIC);
        if ($this->lastOrganizationId === null || $organizationIds === []) {
            return $organizationIds;
        }

        $after = array_values(array_filter($organizationIds, fn (int $id): bool => $id > $this->lastOrganizationId));
        $before = array_values(array_filter($organizationIds, fn (int $id): bool => $id <= $this->lastOrganizationId));
        return [...$after, ...$before];
    }

    /** @param list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}> $candidates @return array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool} */
    private function nextConnector(int $organizationId, array $candidates): array
    {
        usort($candidates, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        $last = $this->lastConnectorByOrganization[$organizationId] ?? null;
        if ($last !== null) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate['id'] > $last) {
                    return $candidate;
                }
            }
        }
        return $candidates[0];
    }

    private function pruneRetryState(): int
    {
        $tracked = array_values(array_unique([
            ...array_keys($this->retryAt),
            ...array_keys($this->attempts),
            ...array_keys($this->immediateRetry),
        ]));
        if ($tracked === []) {
            return 0;
        }

        $active = array_fill_keys($this->connectors->retryableIds(array_map('intval', $tracked)), true);
        $pruned = 0;
        foreach ($tracked as $connectorId) {
            if (!isset($active[$connectorId])) {
                unset($this->retryAt[$connectorId], $this->attempts[$connectorId], $this->immediateRetry[$connectorId]);
                $pruned++;
            }
        }
        return $pruned;
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = $this->retryBaseSeconds ?? max(1, (int) config('plugin.sand-iam.app.directory_sync_worker_retry_base_seconds', 5));
        $max = $this->retryMaxSeconds ?? max($base, (int) config('plugin.sand-iam.app.directory_sync_worker_retry_max_seconds', 300));
        return min($max, $base * (2 ** min(8, max(0, $attempt - 1))));
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function errorCode(\Throwable $exception): string
    {
        if (preg_match('/(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $matches) === 1) {
            return $matches[1];
        }
        return 'SAND_IAM_SYNC_RUN_FAILED';
    }
}

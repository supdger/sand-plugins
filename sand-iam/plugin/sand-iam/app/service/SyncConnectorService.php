<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\SyncConnector;
use plugin\SandIam\app\model\SyncOutbox;
use plugin\SandIam\app\model\SyncResource;
use plugin\SandIam\app\model\SyncRun;
use plugin\SandIam\app\sync\SyncDriverInterface;
use plugin\SandIam\app\sync\PostgresIdentitySyncDriver;
use plugin\SandIam\app\sync\MicrosoftGraphDirectorySyncDriver;
use plugin\SandIam\app\sync\GoogleWorkspaceDirectorySyncDriver;
use plugin\SandIam\app\sync\KeycloakDirectorySyncDriver;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;
use support\Log;

final class SyncConnectorService
{
    public function __construct(private readonly SyncSecretCipher $cipher = new SyncSecretCipher(), private readonly AuditWriter $audit = new AuditWriter()) {}

    /** @param array<string,mixed> $config @param array<string,string> $authorityMap */
    public function configure(SyncConnector $connector, array $config, array $authorityMap, string $actor, string $requestId): void
    {
        $this->enabled(); if ($config === [] || count($config) > 64) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        $driver = $this->driver((string) $connector->driver_code); if (method_exists($driver, 'validateConfig')) $driver::validateConfig($config); $this->assertDirection($driver, (string) $connector->direction);
        foreach ($authorityMap as $field => $authority) if (!in_array($field, ['display_name', 'email', 'phone', 'group'], true) || !in_array($authority, ['source', 'local'], true)) throw new ApiException('SAND_IAM_SYNC_AUTHORITY_MAP_INVALID', 400);
        if ((string) $connector->direction === 'bidirectional' && !isset($authorityMap['display_name'])) throw new ApiException('SAND_IAM_SYNC_AUTHORITY_MAP_REQUIRED', 400);
        $current = null;
        Db::startTrans();
        try {
            $current = SyncConnector::where('id', (int) $connector->id)->lock(true)->find();
            if ($current === null) throw new ApiException('SAND_IAM_SYNC_CONNECTOR_NOT_FOUND', 404);
            if ((int) $current->application_id !== (int) $connector->application_id
                || (int) $current->organization_id !== (int) $connector->organization_id) {
                throw new ApiException('SAND_IAM_SYNC_CONNECTOR_SCOPE_CHANGED: 同步连接范围已改变，请刷新后重试', 409);
            }
            $connector->save(['encrypted_config' => $this->cipher->encryptArray($config), 'authority_map' => $authorityMap, 'config_version' => (int) $current->config_version + 1]);
            $this->writeAudit($connector, 'sync_connector.configure', (int) $connector->id, $actor, $requestId, 'succeeded', ['config_version' => (int) $connector->config_version]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($current !== null) $connector->refresh();
            throw $exception;
        }
    }

    public function test(SyncConnector $connector, string $actor, string $requestId): void
    {
        $this->enabled(); if ((string) ($connector->encrypted_config ?? '') === '') throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_UNAVAILABLE', 503);
        $driver = $this->driver((string) $connector->driver_code); $this->assertDirection($driver, (string) $connector->direction); $driver::test($this->cipher->decryptArray((string) $connector->encrypted_config));
        $this->writeAudit($connector, 'sync_connector.test', (int) $connector->id, $actor, $requestId, 'succeeded', []);
    }

    /** @return array{id:int,state:string,pulled:int,pushed:int,created:int,updated:int,missing:int,disabled:int,conflict:int} */
    public function run(int $connectorId, int $applicationId, string $actor, string $requestId): array
    {
        $this->enabled();
        $ownership = SyncRunOwnership::acquire($connectorId, $applicationId);
        try {
            return $this->runOwned($ownership, $connectorId, $applicationId, $actor, $requestId);
        } finally {
            $ownership->release();
        }
    }

    private function runOwned(SyncRunOwnership $ownership, int $connectorId, int $applicationId, string $actor, string $requestId): array
    {
        [$connector, $run, $driver, $config] = $ownership->transaction(null, function (SyncConnector $connector) use ($connectorId, $applicationId, $actor, $requestId): array {
            if ((int) $connector->status !== 1) throw new ApiException('SAND_IAM_SYNC_CONNECTOR_NOT_FOUND', 404);
            $this->application($applicationId);
            $driver = $this->driver((string) $connector->driver_code);
            $this->assertDirection($driver, (string) $connector->direction);
            $config = $this->cipher->decryptArray((string) $connector->encrypted_config);
            $abandoned = SyncRun::where('sync_connector_id', $connectorId)->where('state', 'running')->lock(true)->select();
            foreach ($abandoned as $previous) {
                if (!(bool) config('plugin.sand-iam.app.sync_recover_abandoned_runs', false)) {
                    throw new ApiException('SAND_IAM_SYNC_ALREADY_RUNNING', 409);
                }
                if ((int) $previous->application_id !== $applicationId) throw new ApiException('SAND_IAM_SYNC_RUN_OWNERSHIP_LOST', 409);
                if ($previous->save(['state' => 'failed', 'error_code' => 'SAND_IAM_SYNC_RUN_ABANDONED', 'finish_time' => $this->now(), 'status' => 2]) === false) {
                    throw new ApiException('SAND_IAM_SYNC_RUN_FAILED', 503);
                }
                $this->writeAudit($connector, 'sync.run_recover', (int) $previous->id, $actor, $requestId, 'succeeded', ['error_code' => 'SAND_IAM_SYNC_RUN_ABANDONED']);
            }
            $run = SyncRun::create(['sync_connector_id' => $connectorId, 'application_id' => $applicationId, 'connector_config_version' => (int) $connector->config_version, 'state' => 'running', 'cursor_before_hash' => $this->cursorHash($connector->encrypted_cursor), 'start_time' => $this->now(), 'status' => 1]);
            return [$connector, $run, $driver, $config];
        });
        $counts = ['pulled' => 0, 'pushed' => 0, 'created' => 0, 'updated' => 0, 'missing' => 0, 'disabled' => 0, 'conflict' => 0];
        try {
            $direction = (string) $connector->direction;
            if (in_array($direction, ['inbound', 'bidirectional'], true)) $this->pull($connector, $run, $driver, $config, $counts, $ownership);
            if (in_array($direction, ['outbound', 'bidirectional'], true)) $this->push($connector, $run, $driver, $config, $counts, $ownership);
            $counts['disabled'] += $this->finalizeMissing($connector, $run, $actor, $requestId, $ownership);
            $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $counts, $actor, $requestId): void {
                $this->assertRunConfiguration($connector, $current, $currentRun);
                if ($currentRun->save($counts + ['state' => 'succeeded', 'cursor_after_hash' => $this->cursorHash($current->encrypted_cursor), 'finish_time' => $this->now(), 'status' => 2]) === false) {
                    throw new ApiException('SAND_IAM_SYNC_RUN_FAILED', 503);
                }
                $this->writeAudit($current, 'sync.run', (int) $currentRun->id, $actor, $requestId, 'succeeded', $counts);
            });
        } catch (\Throwable $exception) {
            $errorCode = $this->errorCode($exception, 'SAND_IAM_SYNC_RUN_FAILED');
            // Failure remains durable if audit storage is unavailable, but an old
            // runner must never record it using a replacement database session.
            $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($counts, $errorCode): void {
                if ($currentRun->save($counts + ['state' => 'failed', 'error_code' => $errorCode, 'finish_time' => $this->now(), 'status' => 2]) === false) {
                    throw new ApiException('SAND_IAM_SYNC_RUN_FAILED', 503);
                }
            });
            try {
                $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($actor, $requestId, $errorCode, $counts): void {
                    $this->writeAudit($current, 'sync.run', (int) $currentRun->id, $actor, $requestId, 'failed', ['error_code' => $errorCode] + $counts);
                }, ['failed']);
            } catch (\Throwable $failureException) {
                try {
                    Log::error('SandIAM sync failure audit unavailable', ['run_id' => (int) $run->id, 'exception_type' => $failureException::class]);
                } catch (\Throwable) {
                    // Keep the original sync error.
                }
            }
            throw $exception;
        }
        return ['id' => (int) $run->id, 'state' => 'succeeded'] + $counts;
    }

    /** @param class-string<SyncDriverInterface> $driver @param array<string,mixed> $config @param array<string,int> $counts */
    private function pull(SyncConnector $connector, SyncRun $run, string $driver, array $config, array &$counts, SyncRunOwnership $ownership): void
    {
        $cursor = $connector->encrypted_cursor ? $this->cipher->decrypt((string) $connector->encrypted_cursor) : null; $pages = 0; $fullSnapshot = false;
        do {
            if (++$pages > 100) throw new ApiException('SAND_IAM_SYNC_PAGE_LIMIT_EXCEEDED', 409);
            $ownership->check();
            $page = $driver::pullPage($config, $cursor, 500); $this->assertPage($page); if ($pages === 1) $fullSnapshot = ($page['full_snapshot'] ?? false) === true;
            $nextCursor = $page['next_cursor'];
            $encryptedCursor = $nextCursor === null || $nextCursor === '' ? null : $this->cipher->encrypt($nextCursor);
            $nextCounts = $counts;
            $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $config, $page, $encryptedCursor, &$nextCounts): void {
                $this->assertRunConfiguration($connector, $current, $currentRun);
                $pageCounts = $this->applyPage($current, $currentRun, $config, $page['records']);
                foreach ($pageCounts as $key => $value) $nextCounts[$key] += $value;
                $nextCounts['pulled'] += count($page['records']);
                if ($current->save(['encrypted_cursor' => $encryptedCursor, 'last_sync_time' => $this->now()]) === false
                    || $currentRun->save($nextCounts + ['cursor_after_hash' => $this->cursorHash($encryptedCursor)]) === false) {
                    throw new ApiException('SAND_IAM_SYNC_RUN_FAILED', 503);
                }
            });
            $cursor = $nextCursor;
            $counts = $nextCounts;
        } while ($page['has_more'] === true);
        if ($fullSnapshot) {
            $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector): void {
                $this->assertRunConfiguration($connector, $current, $currentRun);
                SyncResource::where('sync_connector_id', (int) $current->id)->where('application_id', (int) $current->application_id)->where('status', 1)->where('source_state', 'active')->where(function ($query) use ($currentRun): void { $query->whereNull('last_seen_run_id')->whereOr('last_seen_run_id', '<>', (int) $currentRun->id); })->update(['source_state' => 'missing', 'missing_since' => $this->now()]);
            });
        }
    }

    /** @param array<string,mixed> $config @param list<array<string,mixed>> $records @return array{created:int,updated:int,missing:int,conflict:int} */
    private function applyPage(SyncConnector $connector, SyncRun $run, array $config, array $records): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'missing' => 0, 'conflict' => 0];
        try {
            foreach ($records as $record) {
                $record = $this->record($record); $keyHash = $this->referenceHash((int) $connector->id, $record['source_id']); $snapshot = $this->cipher->encryptArray($record); $snapshotHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                $resource = SyncResource::where('sync_connector_id', (int) $connector->id)->where('source_key_hash', $keyHash)->lock(true)->find();
                if ($record['deleted']) { if ($resource !== null && (string) $resource->source_state === 'active') { $resource->save(['source_state' => 'missing', 'missing_since' => $resource->missing_since ?: $this->now(), 'source_version' => $record['version'], 'encrypted_snapshot' => $snapshot, 'snapshot_hash' => $snapshotHash, 'last_seen_run_id' => (int) $run->id]); $counts['missing']++; } continue; }
                if ($resource === null) {
                    $identity = Identity::create(['application_id' => (int) $connector->application_id, 'code' => 'sync_' . substr($keyHash, 0, 24), 'display_name' => $record['display_name'], 'lifecycle_state' => 'pending', 'status' => 2]);
                    SyncResource::create(['sync_connector_id' => (int) $connector->id, 'application_id' => (int) $connector->application_id, 'source_key_hash' => $keyHash, 'source_version' => $record['version'], 'identity_id' => (int) $identity->id, 'encrypted_snapshot' => $snapshot, 'snapshot_hash' => $snapshotHash, 'source_state' => 'active', 'last_seen_run_id' => (int) $run->id, 'status' => 1]);
                    $this->applyGroups($connector, $identity, $record, $config);
                    $counts['created']++;
                    continue;
                }
                if ((string) $resource->source_version === $record['version'] && (string) $resource->snapshot_hash === $snapshotHash) { $resource->save(['last_seen_run_id' => (int) $run->id, 'source_state' => 'active', 'missing_since' => null]); continue; }
                $identity = Identity::where('id', (int) $resource->identity_id)->where('application_id', (int) $connector->application_id)->lock(true)->find(); if ($identity === null || (string) ($identity->lifecycle_state ?? '') === 'deleted') { $resource->save(['source_state' => 'conflict', 'last_seen_run_id' => (int) $run->id]); $counts['conflict']++; continue; }
                $authority = is_array($connector->authority_map ?? null) ? $connector->authority_map : [];
                if (($authority['display_name'] ?? 'source') === 'source') {
                    $previous = $this->cipher->decryptArray((string) $resource->encrypted_snapshot);
                    $localChanged = (string) $identity->display_name !== (string) ($previous['display_name'] ?? '');
                    if ($localChanged && (string) $connector->conflict_policy === 'reject') throw new ApiException('SAND_IAM_SYNC_FIELD_CONFLICT', 409);
                    if ($localChanged && (string) $connector->conflict_policy === 'manual') { $resource->save(['source_state' => 'conflict', 'last_seen_run_id' => (int) $run->id]); $counts['conflict']++; continue; }
                    if (!$localChanged || (string) $connector->conflict_policy !== 'local_wins') $identity->save(['display_name' => $record['display_name']]);
                }
                $this->applyGroups($connector, $identity, $record, $config);
                $resource->save(['source_version' => $record['version'], 'encrypted_snapshot' => $snapshot, 'snapshot_hash' => $snapshotHash, 'source_state' => 'active', 'missing_since' => null, 'last_seen_run_id' => (int) $run->id]); $counts['updated']++;
            }
        } catch (\Throwable $exception) { if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_SYNC_RESOURCE_CONFLICT', 409); throw $exception; }
        return $counts;
    }

    /** @param class-string<SyncDriverInterface> $driver @param array<string,mixed> $config @param array<string,int> $counts */
    private function push(SyncConnector $connector, SyncRun $run, string $driver, array $config, array &$counts, SyncRunOwnership $ownership): void
    {
        $attemptPolicy = new SyncOutboxAttemptPolicy();
        for ($batch = 0; $batch < 100; $batch++) {
            [$events, $dispatchRows, $payloadFailure, $rowCount] = $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $attemptPolicy): array {
                $this->assertRunConfiguration($connector, $current, $currentRun);
                $rows = SyncOutbox::where('sync_connector_id', (int) $current->id)->where('application_id', (int) $current->application_id)->where('state', 'pending')->order('id', 'asc')->limit(100)->lock(true)->select();
                $events = []; $dispatchRows = []; $payloadFailure = false;
                foreach ($rows as $row) {
                    try {
                        $payload = $this->cipher->decryptArray((string) $row->encrypted_payload);
                    } catch (\Throwable) {
                        $row->save($attemptPolicy->failure((int) $row->attempt_count, 'SAND_IAM_SYNC_OUTBOUND_PAYLOAD_INVALID'));
                        $payloadFailure = true;
                        continue;
                    }
                    $events[] = $payload + ['event_id' => (string) $row->event_id];
                    $dispatchRows[] = ['id' => (int) $row->id, 'event_id' => (string) $row->event_id];
                }
                return [$events, $dispatchRows, $payloadFailure, count($rows)];
            });
            if ($rowCount === 0) return;
            if ($events === []) throw new ApiException('SAND_IAM_SYNC_OUTBOUND_PAYLOAD_INVALID', 503);
            $ownership->check();
            try {
                $accepted = $driver::pushBatch($config, $events); if (!is_array($accepted) || array_filter($accepted, static fn (mixed $id): bool => !is_string($id)) !== []) throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID', 503);
                $sentIds = array_column($dispatchRows, 'event_id'); $accepted = array_values(array_unique($accepted)); if (array_diff($accepted, $sentIds) !== []) throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID', 503);
            } catch (\Throwable $exception) {
                $errorCode = $this->errorCode($exception, 'SAND_IAM_SYNC_OUTBOUND_FAILED');
                $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $dispatchRows, $attemptPolicy, $errorCode): void {
                    $this->assertRunConfiguration($connector, $current, $currentRun);
                    foreach ($dispatchRows as $dispatch) {
                        $row = $this->pendingOutbox($current, $dispatch);
                        $row->save($attemptPolicy->failure((int) $row->attempt_count, $errorCode));
                    }
                });
                throw $exception;
            }
            $nextCounts = $counts;
            $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $dispatchRows, $accepted, $attemptPolicy, &$nextCounts): void {
                $this->assertRunConfiguration($connector, $current, $currentRun);
                foreach ($dispatchRows as $dispatch) {
                    $row = $this->pendingOutbox($current, $dispatch);
                    if (in_array((string) $row->event_id, $accepted, true)) {
                        $row->save(['state' => 'succeeded', 'error_code' => null, 'delivered_time' => $this->now(), 'status' => 2]);
                        $nextCounts['pushed']++;
                    } else {
                        $row->save($attemptPolicy->failure((int) $row->attempt_count, 'SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED'));
                    }
                }
                $currentRun->save($nextCounts);
            });
            $counts = $nextCounts;
            if ($payloadFailure) throw new ApiException('SAND_IAM_SYNC_OUTBOUND_PAYLOAD_INVALID', 503);
            if (count($accepted) < $rowCount) throw new ApiException('SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED', 503);
        }
        throw new ApiException('SAND_IAM_SYNC_OUTBOUND_BATCH_LIMIT_EXCEEDED', 409);
    }

    public function retryOutbound(int $connectorId, int $applicationId, int $outboxId, string $actor, string $requestId): void
    {
        $this->enabled();
        $requestId = RequestId::normalize($requestId);
        $fingerprint = IdempotencyService::fingerprint(['connector_id' => $connectorId, 'application_id' => $applicationId, 'outbox_id' => $outboxId]);
        Db::startTrans();
        try {
            (new IdempotencyService())->execute(
                'sync_outbox',
                (string) $outboxId,
                'sync.outbox_retry',
                $requestId,
                $fingerprint,
                'sync_outbox',
                function () use ($connectorId, $applicationId, $outboxId, $actor, $requestId): array {
                    $connector = SyncConnector::where('id', $connectorId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
                    if ($connector === null) throw new ApiException('SAND_IAM_SYNC_CONNECTOR_NOT_FOUND', 404);
                    if (!in_array((string) $connector->direction, ['outbound', 'bidirectional'], true)) throw new ApiException('SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE', 409);
                    if (SyncRun::where('sync_connector_id', $connectorId)->where('state', 'running')->find() !== null) throw new ApiException('SAND_IAM_SYNC_ALREADY_RUNNING', 409);
                    $outbox = SyncOutbox::where('id', $outboxId)->where('sync_connector_id', $connectorId)->where('application_id', $applicationId)->where('state', 'failed')->where('status', 2)->lock(true)->find();
                    if ($outbox === null) throw new ApiException('SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE', 409);
                    $outbox->save((new SyncOutboxAttemptPolicy())->retry());
                    $this->audit->write('admin', $actor, (int) $connector->organization_id, $applicationId, 'sync.outbox_retry', 'sync_outbox', $outboxId, 'succeeded', $requestId, ['event_id_sha256' => hash('sha256', (string) $outbox->event_id)]);
                    return ['resource_id' => $outboxId, 'result' => []];
                },
                0,
                false,
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    private function finalizeMissing(SyncConnector $connector, SyncRun $run, string $actor, string $requestId, SyncRunOwnership $ownership): int
    {
        if (!in_array((string) $connector->direction, ['inbound', 'bidirectional'], true)) return 0;
        return $ownership->transaction((int) $run->id, function (SyncConnector $current, SyncRun $currentRun) use ($connector, $actor, $requestId): int {
            $this->assertRunConfiguration($connector, $current, $currentRun);
            $cutoff = date('Y-m-d H:i:s', time() - (int) $current->missing_protection_hours * 3600);
            $candidates = SyncResource::where('sync_connector_id', (int) $current->id)->where('application_id', (int) $current->application_id)->where('source_state', 'missing')->where('missing_since', '<=', $cutoff)->where('status', 1)->lock(true)->select();
            if (count($candidates) === 0) return 0;
            $active = SyncResource::where('sync_connector_id', (int) $current->id)->where('application_id', (int) $current->application_id)->where('status', 1)->count();
            if ((count($candidates) * 100 / max(1, $active)) > (int) $current->disable_threshold_percent) throw new ApiException('SAND_IAM_SYNC_DISABLE_THRESHOLD_EXCEEDED', 409);
            foreach ($candidates as $resource) {
                $identity = Identity::where('id', (int) $resource->identity_id)->where('application_id', (int) $current->application_id)->lock(true)->find();
                if ($identity !== null && (string) ($identity->lifecycle_state ?? '') !== 'deleted') {
                    (new IdentityLifecycleService())->disable((int) $identity->id, (int) $current->application_id, $actor, $requestId, false, false);
                }
                $resource->save(['source_state' => 'disabled']);
            }
            return count($candidates);
        });
    }

    private function assertRunConfiguration(SyncConnector $original, SyncConnector $current, SyncRun $run): void
    {
        if ((int) $current->status !== 1 || (int) $current->organization_id !== (int) $original->organization_id
            || (int) $current->config_version !== (int) $run->connector_config_version) {
            throw new ApiException('SAND_IAM_SYNC_CONNECTOR_CHANGED', 409);
        }
        $this->application((int) $current->application_id);
    }

    /** @param array{id:int,event_id:string} $dispatch */
    private function pendingOutbox(SyncConnector $connector, array $dispatch): SyncOutbox
    {
        $row = SyncOutbox::where('id', $dispatch['id'])->where('sync_connector_id', (int) $connector->id)
            ->where('application_id', (int) $connector->application_id)->where('event_id', $dispatch['event_id'])
            ->where('state', 'pending')->lock(true)->find();
        if ($row === null) throw new ApiException('SAND_IAM_SYNC_RUN_OWNERSHIP_LOST', 409);
        return $row;
    }

    /** @param array{attributes:array<string,mixed>} $record @param array<string,mixed> $config */
    private function applyGroups(SyncConnector $connector, Identity $identity, array $record, array $config): void
    {
        $authority = is_array($connector->authority_map ?? null) ? $connector->authority_map : [];
        if (($authority['group'] ?? 'local') !== 'source') return;

        $externalCodes = $record['attributes']['group_codes'] ?? [];
        $mapping = $config['group_map'] ?? [];
        if (!is_array($externalCodes) || count($externalCodes) > 100 || !is_array($mapping)) throw new ApiException('SAND_IAM_SYNC_GROUP_MAPPING_INVALID', 400);

        $localCodes = [];
        foreach ($externalCodes as $externalCode) {
            if (!is_string($externalCode) || $externalCode === '' || !is_string($mapping[$externalCode] ?? null) || $mapping[$externalCode] === '') throw new ApiException('SAND_IAM_SYNC_GROUP_MAPPING_REQUIRED', 409);
            $localCodes[] = $mapping[$externalCode];
        }
        $localCodes = array_values(array_unique($localCodes));
        $groupIds = $localCodes === [] ? [] : IdentityGroup::whereIn('code', $localCodes)->where('application_id', (int) $connector->application_id)->where('status', 1)->column('id');
        if (count($groupIds) !== count($localCodes)) throw new ApiException('SAND_IAM_SYNC_GROUP_MAPPING_TARGET_NOT_FOUND', 409);
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));

        foreach (IdentityGroupMember::where('identity_id', (int) $identity->id)->where('application_id', (int) $connector->application_id)->lock(true)->select() as $member) {
            if ((int) $member->status === 1 && !in_array((int) $member->identity_group_id, $groupIds, true)) $member->save(['status' => 2]);
        }
        foreach ($groupIds as $groupId) {
            $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', (int) $identity->id)->lock(true)->find();
            if ($member === null) IdentityGroupMember::create(['identity_group_id' => $groupId, 'application_id' => (int) $connector->application_id, 'identity_id' => (int) $identity->id, 'status' => 1]);
            elseif ((int) $member->status !== 1) $member->save(['status' => 1]);
        }
    }

    /** @param array<string,mixed> $payload */ public function enqueueOutbound(int $connectorId, int $applicationId, int $identityId, string $operation, array $payload, string $eventId): void { $this->enabled(); if(!in_array($operation,['create','update','disable','delete'],true)||!preg_match('/^[A-Za-z0-9._-]{8,128}$/',$eventId))throw new ApiException('SAND_IAM_SYNC_OUTBOUND_EVENT_INVALID',400); SyncOutbox::create(['sync_connector_id'=>$connectorId,'application_id'=>$applicationId,'identity_id'=>$identityId,'event_id'=>$eventId,'operation'=>$operation,'encrypted_payload'=>$this->cipher->encryptArray($payload),'state'=>'pending','attempt_count'=>0,'status'=>1]); }
    /** @param array<string,mixed> $page */ private function assertPage(array $page): void { if(!is_array($page['records']??null)||count($page['records'])>500||!is_bool($page['has_more']??null)||(!(is_string($page['next_cursor']??null))&&($page['next_cursor']??null)!==null))throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID',503); if(($page['has_more']??false)===true&&($page['next_cursor']??null)==='')throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID',503); }
    /** @param array<string,mixed> $record @return array{source_id:string,version:string,display_name:string,deleted:bool,attributes:array<string,mixed>} */ private function record(array $record): array { $id=trim((string)($record['source_id']??''));$version=trim((string)($record['version']??''));$display=trim((string)($record['display_name']??'')); if($id===''||strlen($id)>256||$version===''||strlen($version)>128||($display===''&&($record['deleted']??false)!==true)||mb_strlen($display)>128)throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID',400);$attributes=is_array($record['attributes']??null)?$record['attributes']:[];return ['source_id'=>$id,'version'=>$version,'display_name'=>$display,'deleted'=>($record['deleted']??false)===true,'attributes'=>$attributes]; }
    /** @return class-string<SyncDriverInterface> */ private function driver(string $code): string { $registry=config('plugin.sand-iam.app.sync_drivers',[]);if(is_string($registry))$registry=json_decode($registry,true);$registry=array_replace(['postgresql'=>PostgresIdentitySyncDriver::class,'microsoft_graph'=>MicrosoftGraphDirectorySyncDriver::class,'google_workspace'=>GoogleWorkspaceDirectorySyncDriver::class,'keycloak'=>KeycloakDirectorySyncDriver::class],is_array($registry)?$registry:[]);$class=is_string($registry[$code]??null)?$registry[$code]:'';if($class===''||!class_exists($class)||!is_subclass_of($class,SyncDriverInterface::class))throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE',503);return $class; }
    /** @param class-string<SyncDriverInterface> $driver */ private function assertDirection(string $driver,string $direction):void{$capabilities=$driver::capabilities();if(!is_bool($capabilities['inbound']??null)||!is_bool($capabilities['outbound']??null))throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID',503);if(in_array($direction,['inbound','bidirectional'],true)&&$capabilities['inbound']!==true)throw new ApiException('SAND_IAM_SYNC_DIRECTION_UNSUPPORTED',409);if(in_array($direction,['outbound','bidirectional'],true)&&$capabilities['outbound']!==true)throw new ApiException('SAND_IAM_SYNC_DIRECTION_UNSUPPORTED',409);}
    private function referenceHash(int $connectorId,string $value):string{$pepper=(string)config('plugin.sand-iam.app.sync_reference_pepper','');if(strlen($pepper)<32)throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_UNAVAILABLE',503);return hash_hmac('sha256',$connectorId.'|'.$value,$pepper);}
    private function cursorHash(mixed $value):?string{return $value===null||$value===''?null:hash('sha256',(string)$value);}
    private function application(int $id):Application{$app=Application::where('id',$id)->where('status',1)->find();if($app===null)throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用',404);return $app;}
    private function enabled():void{if((int)config('plugin.sand-iam.app.identity_lifecycle_enabled',0)!==1)throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE',503);}
    private function now():string{return date('Y-m-d H:i:s');}
    private function errorCode(\Throwable $exception,string $fallback):string{return preg_match('/^(SAND_IAM_[A-Z0-9_]+)/',$exception->getMessage(),$matches)===1?$matches[1]:$fallback;}
    /** @param array<string,mixed> $context */ private function writeAudit(SyncConnector $connector,string $action,int $id,string $actor,string $requestId,string $outcome,array $context):void{$app=$this->application((int)$connector->application_id);$this->audit->write('admin',$actor,(int)$app->organization_id,(int)$app->id,$action,'sync_run',$id,$outcome,$requestId!==''?substr($requestId,0,96):bin2hex(random_bytes(16)),$context);}
}

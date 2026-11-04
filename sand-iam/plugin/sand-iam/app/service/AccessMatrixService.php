<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\admin\support\AccessMatrixInput;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Aggregates the "applications × services" home grid and orchestrates the
 * one-click grant flow. Thinner than OnboardingService: same advisory-lock,
 * serializable transaction and idempotent-replay shape without manifests.
 */
final class AccessMatrixService
{
    /**
     * Read-only aggregate scoped to the admin's management range. Never
     * returns credential secrets or plaintext credentials.
     *
     * @return array{organizations:list<array>,services:list<array>,applications:list<array>}
     */
    public function matrix(AdminOrganizationAccess $access): array
    {
        $organizationQuery = Organization::where('status', 1)->field('id,code,name')->order('id');
        if (!$access->isSuperAdmin()) {
            $organizationIds = $access->organizationIds();
            if ($organizationIds === []) {
                $organizationQuery->whereRaw('1 = 0');
            } else {
                $organizationQuery->whereIn('id', $organizationIds);
            }
        }
        $organizations = $organizationQuery->select()->toArray();
        $organizationIds = array_map(static fn (array $row): int => (int) $row['id'], $organizations);

        $applicationQuery = Application::field('id,organization_id,code,name,status')->order('id');
        if ($organizationIds !== []) {
            $applicationQuery->whereIn('organization_id', $organizationIds);
        } elseif (!$access->isSuperAdmin()) {
            $applicationIds = $access->applicationIds();
            if ($applicationIds === []) {
                $applicationQuery->whereRaw('1 = 0');
            } else {
                $applicationQuery->whereIn('id', $applicationIds);
            }
        }
        $applications = $applicationQuery->select()->toArray();
        $applicationIds = array_map(static fn (array $row): int => (int) $row['id'], $applications);

        $environments = $applicationIds === [] ? [] : Environment::whereIn('application_id', $applicationIds)->field('id,application_id,code,name,status')->order('id')->select()->toArray();
        $environmentIds = array_map(static fn (array $row): int => (int) $row['id'], $environments);

        $clients = $environmentIds === [] ? [] : WorkloadClient::whereIn('environment_id', $environmentIds)->field('id,environment_id,code,audience,status')->order('id')->select()->toArray();
        $clientIds = array_map(static fn (array $row): int => (int) $row['id'], $clients);

        $grants = $clientIds === [] ? [] : ServiceGrant::whereIn('workload_client_id', $clientIds)->field('id,workload_client_id,service_action_id,audience,expire_time,status')->order('id')->select()->toArray();
        $actionIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['service_action_id'], $grants)));
        $actionsById = $actionIds === [] ? [] : array_column(
            ServiceAction::whereIn('id', $actionIds)->field('id,code,service_id')->select()->toArray(),
            null,
            'id',
        );
        $serviceIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['service_id'], $actionsById)));
        $serviceCodesById = $serviceIds === [] ? [] : array_column(
            Service::whereIn('id', $serviceIds)->field('id,code')->select()->toArray(),
            'code',
            'id',
        );

        $credentials = $clientIds === [] ? [] : Credential::whereIn('workload_client_id', $clientIds)
            ->field('id,workload_client_id,name,key_prefix,expire_time,status')
            ->order('id')
            ->select()
            ->toArray();

        $services = Service::where('status', 1)->field('id,code,name')->order('id')->select()->toArray();
        $activeServiceIds = array_map(static fn (array $row): int => (int) $row['id'], $services);
        $serviceActions = $activeServiceIds === [] ? [] : ServiceAction::whereIn('service_id', $activeServiceIds)->where('status', 1)->field('id,service_id,code,name')->order('id')->select()->toArray();
        $actionsByService = [];
        foreach ($serviceActions as $action) {
            $actionsByService[(int) $action['service_id']][] = [
                'id' => (int) $action['id'],
                'code' => (string) $action['code'],
                'name' => (string) $action['name'],
            ];
        }

        $clientsByEnvironment = [];
        foreach ($clients as $client) {
            $clientId = (int) $client['id'];
            $clientsByEnvironment[(int) $client['environment_id']][] = [
                'id' => $clientId,
                'code' => (string) $client['code'],
                'audience' => (string) $client['audience'],
                'status' => (int) $client['status'],
                'grants' => array_values(array_map(
                    static function (array $grant) use ($actionsById, $serviceCodesById): array {
                        $action = $actionsById[(int) $grant['service_action_id']] ?? null;
                        return [
                            'service_action_id' => (int) $grant['service_action_id'],
                            'action_code' => $action === null ? null : (string) $action['code'],
                            'service_code' => $action === null ? null : ($serviceCodesById[(int) $action['service_id']] ?? null),
                            'audience' => (string) $grant['audience'],
                            'status' => (int) $grant['status'],
                            'expire_time' => $grant['expire_time'],
                        ];
                    },
                    array_filter($grants, static fn (array $grant): bool => (int) $grant['workload_client_id'] === $clientId),
                )),
                'credentials' => array_values(array_map(
                    static fn (array $credential): array => [
                        'id' => (int) $credential['id'],
                        'name' => (string) $credential['name'],
                        'key_prefix' => (string) $credential['key_prefix'],
                        'expire_time' => $credential['expire_time'],
                        'status' => (int) $credential['status'],
                    ],
                    array_filter($credentials, static fn (array $credential): bool => (int) $credential['workload_client_id'] === $clientId),
                )),
            ];
        }

        $environmentsByApplication = [];
        foreach ($environments as $environment) {
            $environmentsByApplication[(int) $environment['application_id']][] = [
                'id' => (int) $environment['id'],
                'code' => (string) $environment['code'],
                'name' => (string) $environment['name'],
                'status' => (int) $environment['status'],
                'clients' => $clientsByEnvironment[(int) $environment['id']] ?? [],
            ];
        }

        return [
            'organizations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
            ], $organizations),
            'services' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'actions' => $actionsByService[(int) $row['id']] ?? [],
            ], $services),
            'applications' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'organization_id' => (int) $row['organization_id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'status' => (int) $row['status'],
                'environments' => $environmentsByApplication[(int) $row['id']] ?? [],
            ], $applications),
        ];
    }

    /**
     * Idempotent one-click grant: upserts organization/application/
     * environment/client, grants each whitelisted action, optionally issues a
     * credential. Whole orchestration is atomic.
     *
     * @param array<string,mixed> $input
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function grant(array $input, int $adminId, string $requestId, AdminOrganizationAccess $access): array
    {
        $normalized = AccessMatrixInput::normalizeGrant($input);
        if ($normalized['organization_id'] !== null) {
            $access->assertOrganization($normalized['organization_id']);
        }
        if ($normalized['application']['id'] !== null) {
            $access->assertApplication($normalized['application']['id']);
        } elseif ($normalized['organization_id'] === null && !$access->isSuperAdmin()) {
            // Creating a brand-new organization is platform-level only.
            $access->assertSuperAdmin();
        }

        $fingerprint = IdempotencyService::fingerprint($normalized);
        $operations = new IdempotencyService();
        $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'access_matrix.grant', $requestId, $fingerprint);
        if ($completedReplay !== null) return $completedReplay;

        [$lockLeft, $lockRight] = self::advisoryKeyFor($normalized);
        $previousIsolation = $this->beginSerializableTransaction();
        try {
            Db::query('SELECT pg_advisory_xact_lock(?, ?)', [$lockLeft, $lockRight]);
            $result = $operations->execute('admin', (string) $adminId, 'access_matrix.grant', $requestId, $fingerprint, 'access_matrix', function () use ($normalized, $adminId, $requestId, $access): array {
                Db::startTrans();
                try {
                    $actor = (string) $adminId;
                    $writer = new AuditWriter();

                    [$organization, $organizationCreated] = $this->resolveOrganization($normalized);
                    if ($organizationCreated) {
                        $writer->write('admin', $actor, (int) $organization->id, null, 'organization.create', 'organization', (int) $organization->id, 'succeeded', $requestId);
                    }

                    [$application, $applicationCreated] = $this->resolveApplication($normalized, (int) $organization->id);
                    // Re-check scope for applications upserted by code inside the lock.
                    $access->assertApplication((int) $application->id);
                    if ($normalized['organization_id'] === null
                        && (int) $application->organization_id !== (int) $organization->id) {
                        // Callers may name an existing application by id alone;
                        // audits and issued credentials must then attribute to
                        // the application's own organization, not the default.
                        $organization = Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
                        if ($organization === null) {
                            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 客户主体不存在或已停用', 400);
                        }
                    }
                    if ($applicationCreated) {
                        $writer->write('admin', $actor, (int) $organization->id, (int) $application->id, 'application.create', 'application', (int) $application->id, 'succeeded', $requestId);
                    }

                    [$environment, $environmentCreated] = $this->resolveEnvironment($normalized, (int) $application->id);
                    if ($environmentCreated) {
                        $writer->write('admin', $actor, (int) $organization->id, (int) $application->id, 'environment.create', 'environment', (int) $environment->id, 'succeeded', $requestId);
                    }

                    [$client, $clientCreated] = $this->resolveClient((int) $environment->id);
                    if ($clientCreated) {
                        $writer->write('admin', $actor, (int) $organization->id, (int) $application->id, 'workload_client.create', 'workload_client', (int) $client->id, 'succeeded', $requestId);
                    }
                    if ((string) $client->audience !== AccessMatrixInput::DEFAULT_AUDIENCE) {
                        throw new ApiException('SAND_IAM_ACCESS_MATRIX_AUDIENCE_MISMATCH: 服务调用身份的受众与目标服务不一致', 409);
                    }

                    // Empty action_codes is the credential-only form accepted
                    // by normalizeGrant for existing applications: the whole
                    // grant orchestration is skipped so no revoked grant can
                    // be silently reactivated here.
                    $granted = [];
                    $skippedActionCodes = [];
                    if ($normalized['action_codes'] !== []) {
                        $service = Service::where('code', $normalized['service_code'])->where('status', 1)->find();
                        if ($service === null) {
                            throw new ApiException('SAND_IAM_ACCESS_MATRIX_SERVICE_MISSING: 目标服务未登记或已停用', 400);
                        }
                        $activeActions = ServiceAction::where('service_id', (int) $service->id)->where('status', 1)->field('id,code')->select()->toArray();
                        $activeCodes = array_map(static fn (array $row): string => (string) $row['code'], $activeActions);
                        $clip = AccessMatrixInput::clipActionCodes($normalized['action_codes'], $activeCodes);
                        if ($clip['granted'] === []) {
                            throw new ApiException('SAND_IAM_ACCESS_MATRIX_ACTION_MISSING: 所选能力未登记或已停用，请先完成服务目录登记', 400);
                        }
                        $actionsByCode = array_column($activeActions, null, 'code');

                        foreach ($clip['granted'] as $actionCode) {
                            $action = $actionsByCode[$actionCode];
                            [$grant, $grantState] = $this->upsertGrant((int) $client->id, (int) $action['id']);
                            if ($grantState === 'created') {
                                $writer->write('admin', $actor, (int) $organization->id, (int) $application->id, 'service_grant.create', 'service_grant', (int) $grant->id, 'succeeded', $requestId, ['action_code' => $actionCode]);
                            } elseif ($grantState === 'reactivated') {
                                $writer->write('admin', $actor, (int) $organization->id, (int) $application->id, 'service_grant.reactivate', 'service_grant', (int) $grant->id, 'succeeded', $requestId, ['action_code' => $actionCode]);
                            }
                            $granted[] = [
                                'service_action_id' => (int) $action['id'],
                                'action_code' => $actionCode,
                                'service_code' => (string) $service->code,
                                'state' => $grantState,
                            ];
                        }
                        $skippedActionCodes = $clip['skipped'];
                    }

                    $result = [
                        'application_id' => (int) $application->id,
                        'environment_id' => (int) $environment->id,
                        'workload_client_id' => (int) $client->id,
                        'organization_id' => (int) $organization->id,
                        'granted' => $granted,
                    ];
                    if ($skippedActionCodes !== []) {
                        $result['skipped_action_codes'] = $skippedActionCodes;
                    }
                    if ($normalized['issue_credential']) {
                        $issued = (new CredentialIssuanceService())->issue(
                            (int) $client->id,
                            $normalized['credential_name'],
                            null,
                            $this->childRequestId($requestId, 'credential.issue'),
                            $actor,
                            (int) $organization->id,
                            (int) $application->id,
                        );
                        $result['credential'] = [
                            'id' => $issued['id'],
                            'key_prefix' => $issued['key_prefix'],
                            'credential' => $issued['credential'],
                            'expire_time' => $issued['expire_time'],
                        ];
                    }

                    Db::commit();
                    return ['resource_id' => (int) $client->id, 'result' => $result];
                } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
            }, 0, false);
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'access_matrix.grant', $requestId, $fingerprint);
            if ($completedReplay !== null) return $completedReplay;
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                return $operations->replay('admin', (string) $adminId, 'access_matrix.grant', $requestId, $fingerprint);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'access_matrix.grant', $requestId, $fingerprint);
            if ($completedReplay !== null) return $completedReplay;
            if ($this->isSerializationFailure($exception)) {
                throw new ApiException('SAND_IAM_ACCESS_MATRIX_CONFLICT: 并发授权冲突，请重试', 409);
            }
            throw $exception;
        } finally {
            $this->restoreTransactionIsolation($previousIsolation);
        }
        return $result;
    }

    /** @return array{0:object,1:bool} */
    private function resolveOrganization(array $normalized): array
    {
        if ($normalized['organization_id'] !== null) {
            $organization = Organization::where('id', $normalized['organization_id'])->where('status', 1)->lock(true)->find();
            if ($organization === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 客户主体不存在或已停用', 400);
            }
            return [$organization, false];
        }
        $organization = Organization::where('code', AccessMatrixInput::DEFAULT_ORGANIZATION_CODE)->lock(true)->find();
        if ($organization !== null) {
            if ((int) $organization->status !== 1) $organization->save(['status' => 1]);
            return [$organization, false];
        }
        return [Organization::create([
            'code' => AccessMatrixInput::DEFAULT_ORGANIZATION_CODE,
            'name' => AccessMatrixInput::DEFAULT_ORGANIZATION_NAME,
            'status' => 1,
        ]), true];
    }

    /** @return array{0:object,1:bool} */
    private function resolveApplication(array $normalized, int $organizationId): array
    {
        $application = $normalized['application'];
        if ($application['id'] !== null) {
            $model = Application::where('id', $application['id'])->where('status', 1)->lock(true)->find();
            if ($model === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 接入应用不存在或已停用', 400);
            }
            if ($normalized['organization_id'] !== null && (int) $model->organization_id !== $organizationId) {
                throw new ApiException('SAND_IAM_ACCESS_MATRIX_ORGANIZATION_MISMATCH: 接入应用不属于所选客户主体', 400);
            }
            return [$model, false];
        }
        $model = Application::where('organization_id', $organizationId)->where('code', $application['code'])->lock(true)->find();
        if ($model !== null) {
            if ((int) $model->status !== 1) $model->save(['status' => 1]);
            return [$model, false];
        }
        return [Application::create([
            'organization_id' => $organizationId,
            'code' => $application['code'],
            'name' => $application['name'],
            'status' => 1,
        ]), true];
    }

    /** @return array{0:object,1:bool} */
    private function resolveEnvironment(array $normalized, int $applicationId): array
    {
        $environment = Environment::where('application_id', $applicationId)->where('code', $normalized['environment_code'])->lock(true)->find();
        if ($environment !== null) {
            if ((int) $environment->status !== 1) $environment->save(['status' => 1]);
            return [$environment, false];
        }
        return [Environment::create([
            'application_id' => $applicationId,
            'code' => $normalized['environment_code'],
            'name' => $normalized['environment_code'] === AccessMatrixInput::DEFAULT_ENVIRONMENT_CODE ? AccessMatrixInput::DEFAULT_ENVIRONMENT_NAME : $normalized['environment_code'],
            'status' => 1,
        ]), true];
    }

    /** @return array{0:object,1:bool} */
    private function resolveClient(int $environmentId): array
    {
        $client = WorkloadClient::where('environment_id', $environmentId)->where('code', AccessMatrixInput::DEFAULT_CLIENT_CODE)->lock(true)->find();
        if ($client !== null) {
            if ((int) $client->status !== 1) $client->save(['status' => 1]);
            return [$client, false];
        }
        return [WorkloadClient::create([
            'environment_id' => $environmentId,
            'code' => AccessMatrixInput::DEFAULT_CLIENT_CODE,
            'name' => AccessMatrixInput::DEFAULT_CLIENT_NAME,
            'audience' => AccessMatrixInput::DEFAULT_AUDIENCE,
            'status' => 1,
        ]), true];
    }

    /** @return array{0:object,1:string} grant + 'created'|'reactivated'|'active' */
    private function upsertGrant(int $clientId, int $actionId): array
    {
        $grant = ServiceGrant::where('workload_client_id', $clientId)
            ->where('service_action_id', $actionId)
            ->where('audience', AccessMatrixInput::DEFAULT_AUDIENCE)
            ->lock(true)
            ->find();
        if ($grant === null) {
            return [ServiceGrant::create([
                'workload_client_id' => $clientId,
                'service_action_id' => $actionId,
                'audience' => AccessMatrixInput::DEFAULT_AUDIENCE,
                'status' => 1,
            ]), 'created'];
        }
        if ((int) $grant->status !== 1) {
            $grant->save(['status' => 1, 'revoked_time' => null]);
            return [$grant, 'reactivated'];
        }
        return [$grant, 'active'];
    }

    /** @return array{0:int,1:int} two PostgreSQL int4 lock keys */
    private static function advisoryKeyFor(array $normalized): array
    {
        $scope = 'access-matrix:'
            . ($normalized['organization_id'] ?? 'new-org')
            . '|'
            . ($normalized['application']['id'] ?? $normalized['application']['code'] ?? 'new-app');
        $parts = unpack('Nleft/Nright', substr(hash('sha256', $scope, true), 0, 8));
        $signed = static fn (int $value): int => $value > 0x7fffffff ? $value - 0x100000000 : $value;
        return [$signed((int) $parts['left']), $signed((int) $parts['right'])];
    }

    private function childRequestId(string $requestId, string $operation): string
    {
        return 'amx_' . substr(hash('sha256', $requestId . "\0" . $operation), 0, 48);
    }

    private function beginSerializableTransaction(): string
    {
        $rows = Db::query('SHOW default_transaction_isolation');
        $previous = strtolower(trim((string) ($rows[0]['default_transaction_isolation'] ?? '')));
        if (!in_array($previous, ['read uncommitted', 'read committed', 'repeatable read', 'serializable'], true)) {
            throw new ApiException('SAND_IAM_ACCESS_MATRIX_TRANSACTION_CONFIGURATION_INVALID', 503);
        }
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        try {
            Db::startTrans();
        } catch (\Throwable $exception) {
            $this->restoreTransactionIsolation($previous);
            throw $exception;
        }
        return $previous;
    }

    private function restoreTransactionIsolation(string $level): void
    {
        $keyword = match ($level) {
            'read uncommitted' => 'READ UNCOMMITTED',
            'read committed' => 'READ COMMITTED',
            'repeatable read' => 'REPEATABLE READ',
            'serializable' => 'SERIALIZABLE',
            default => throw new ApiException('SAND_IAM_ACCESS_MATRIX_TRANSACTION_CONFIGURATION_INVALID', 503),
        };
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ' . $keyword);
    }

    private function isSerializationFailure(\Throwable $exception): bool
    {
        return (string) $exception->getCode() === '40001'
            || str_contains($exception->getMessage(), '40001')
            || str_contains(strtolower($exception->getMessage()), 'serialization_failure');
    }
}

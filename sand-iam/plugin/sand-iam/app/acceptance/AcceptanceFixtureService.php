<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

use Closure;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;

final class AcceptanceFixtureService
{
    public const PREFIX = 'sand_iam_acceptance_';
    public const PREFIX_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_$/';
    public const REQUEST_ID_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_[A-Za-z0-9][A-Za-z0-9_.:-]{0,58}$/';
    public const CONFIRMATION = 'I_CONFIRM_DELETE_ONLY_THIS_ACCEPTANCE_FIXTURE';
    private const WEBHOOK_FIXTURE_EVENT_TYPE = 'acceptance.fixture.event';

    /** @var array<string,array{types:list<string>,purge_order:list<string>,revoke_order?:list<string>,actions:array<string,string>,scope_types?:list<string>,partial_types?:bool}> */
    private const CHAINS = [
        'organization-application-environment' => [
            'types' => ['organization', 'application', 'environment'],
            'purge_order' => ['environment', 'application', 'organization'],
            'actions' => [
                'organization' => 'organization.create',
                'application' => 'application.create',
                'environment' => 'environment.create',
            ],
        ],
        'delegation-scope' => [
            'types' => ['admin_application_grant'],
            'purge_order' => ['admin_application_grant'],
            'actions' => ['admin_application_grant' => 'admin_application_grant.create'],
        ],
        'identity-group-role-policy' => [
            // Roles, resources and policies are approved prerequisites. This
            // cleanup owns only the identity, group and captured relation rows.
            'types' => ['identity', 'identity_group', 'identity_group_member', 'identity_group_role'],
            'purge_order' => ['identity_group_role', 'identity_group_member', 'identity_group', 'identity'],
            'revoke_order' => ['identity_group_role', 'identity_group_member', 'identity_group', 'identity'],
            'actions' => [
                'identity' => 'identity.create',
                'identity_group' => 'identity_group.create',
                'identity_group_member' => 'identity_group.member_add',
                'identity_group_role' => 'identity_group_role.grant',
            ],
            'partial_types' => true,
        ],
        'human-auth-session-mfa' => [
            // The acceptance account is created through the normal application
            // registration API. Its authentication rows are derived from the
            // captured, prefixed identity so a caller cannot select only a
            // convenient subset of sessions, refresh tokens or MFA material.
            'types' => ['identity', 'auth_session', 'mfa_factor'],
            'purge_order' => ['mfa_recovery_code', 'mfa_factor', 'auth_refresh_token', 'auth_session', 'identity_auth', 'identity'],
            'revoke_order' => ['identity'],
            'actions' => [
                'identity' => 'identity.register',
                'mfa_factor' => 'identity.totp_start',
            ],
        ],
        'workload-credential-invocation' => [
            'types' => ['credential', 'service_grant'],
            'purge_order' => ['service_quota_bucket', 'service_invocation_operation', 'credential', 'service_grant'],
            'revoke_order' => ['credential', 'service_grant'],
            'actions' => [
                'credential' => 'credential.issue',
                'service_grant' => 'service_grant.create',
            ],
            // These records are inputs to the call chain. They must exist and
            // match exactly, but are never acceptance-fixture deletion targets.
            'scope_types' => ['environment', 'workload_client', 'service', 'service_action'],
            'partial_types' => true,
        ],
        'event-webhook-delivery' => [
            // An endpoint may legitimately receive no event before an
            // interrupted acceptance run. A delivery, when present, must be
            // the complete same-run endpoint set, never a caller-selected
            // subset.
            'types' => ['webhook_endpoint', 'webhook_delivery'],
            'purge_order' => ['webhook_delivery', 'webhook_endpoint'],
            'revoke_order' => ['webhook_endpoint'],
            'actions' => [
                'webhook_endpoint' => 'webhook.create',
                'webhook_delivery' => 'webhook.delivery_enqueue',
            ],
            'partial_types' => true,
        ],
        'oauth-cas-api-governance' => [
            // Application users, business resources, declared actions and the
            // environment are preflight inputs. This fixture owns only the
            // OAuth/CAS and API-governance configuration created in this run.
            'types' => ['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy'],
            'purge_order' => [
                'oauth_token', 'oauth_grant', 'authorization_code', 'oauth_authorization_request', 'oauth_consent', 'oauth_client',
                'cas_ticket', 'cas_login_request', 'cas_service',
                'api_route_binding', 'api_resource', 'policy_version', 'policy',
            ],
            'revoke_order' => ['oauth_client', 'cas_service', 'api_route_binding', 'api_resource', 'policy'],
            'actions' => [
                'oauth_client' => 'oauth_client.create',
                'cas_service' => 'cas_service.create',
                'api_resource' => 'api_resource.create',
                'api_route_binding' => 'api_route_binding.create',
                'policy' => 'policy.create',
            ],
            'scope_types' => ['environment', 'resource', 'identity'],
        ],
    ];

    private readonly AcceptanceFixtureStore $store;
    private readonly Closure $idempotentExecute;
    private readonly Closure $audit;

    public function __construct(
        ?AcceptanceFixtureStore $store = null,
        ?Closure $idempotentExecute = null,
        ?Closure $audit = null,
    ) {
        $this->store = $store ?? new DatabaseAcceptanceFixtureStore();
        $this->idempotentExecute = $idempotentExecute ?? static function (
            string $actorRef,
            string $requestId,
            string $fingerprint,
            callable $operation,
        ): array {
            return (new IdempotencyService())->execute(
                'admin',
                $actorRef,
                'acceptance_fixture.cleanup',
                $requestId,
                $fingerprint,
                'acceptance_fixture',
                $operation,
                0,
                false,
            );
        };
        $this->audit = $audit ?? static function (
            string $actorRef,
            string $requestId,
            string $action,
            string $outcome,
            array $context,
        ): void {
            (new AuditWriter())->write(
                'admin',
                $actorRef,
                null,
                null,
                $action,
                'acceptance_fixture',
                null,
                $outcome,
                $requestId,
                $context,
            );
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function cleanup(array $payload, int $adminId, string $headerRequestId): array
    {
        $request = $this->validateRequest($payload, $headerRequestId);
        $fingerprint = IdempotencyService::fingerprint($request);
        $failureContext = [];
        try {
            return $this->store->transaction(function () use ($request, $adminId, $fingerprint, &$failureContext): array {
                $execution = ($this->idempotentExecute)(
                    (string) $adminId,
                    $request['request_id'],
                    $fingerprint,
                    function () use ($request, $adminId, &$failureContext): array {
                        $records = $this->verifiedRecords($request, true, true);
                        if ($request['chain_id'] === 'human-auth-session-mfa') {
                            $beforeDrain = $this->humanAuthDrainSnapshot($records);
                            try {
                                $this->assertHumanAuthDrained($records);
                            } catch (ApiException $exception) {
                                $afterDrain = $this->humanAuthDrainSnapshot($this->verifiedRecords($request, true, true));
                                $failureContext['drain_snapshot'] = ['before' => $beforeDrain, 'after' => $afterDrain, 'unchanged' => $beforeDrain === $afterDrain];
                                if ($beforeDrain !== $afterDrain) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_SNAPSHOT_CHANGED: DRAIN_REQUIRED 前后夹具状态不一致，事务已回滚', 409);
                                throw $exception;
                            }
                        }
                        $revoked = [];
                        $purged = [];
                        $recordIds = $this->recordIds($request, $records);
                        foreach ($request['spec']['revoke_order'] ?? $request['spec']['purge_order'] as $type) {
                            $ids = $recordIds[$type] ?? [];
                            if ($ids === []) continue;
                            $revoked[$type] = $this->store->updateStatus($type, $ids, 2, $request['prefix']);
                        }
                        foreach ($request['spec']['purge_order'] as $type) {
                            $ids = $recordIds[$type] ?? [];
                            if ($ids === []) { $purged[$type] = 0; continue; }
                            if ($type === 'policy_version') {
                                $this->store->detachPolicyVersions($ids, $request['object_ids']['policy'] ?? [], $request['application_id']);
                            }
                            $purged[$type] = $this->store->purge($type, $ids, $request['prefix']);
                        }
                        $residual = $this->residual($request, $records);
                        if (array_sum($residual) !== 0) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_RESIDUAL: 清理后仍发现本轮验收数据，事务已回滚', 400);
                        $result = [
                            'chain_id' => $request['chain_id'],
                            'matched' => array_map('count', $records),
                            'revoked' => $revoked,
                            'purged' => $purged,
                            'residual' => $residual,
                        ];
                        ($this->audit)((string) $adminId, $request['request_id'], 'acceptance_fixture.cleanup', 'succeeded', $this->auditContext($request, $result));
                        return ['result' => $result];
                    },
                );
                return array_merge($execution['result'], ['replayed' => (bool) $execution['replayed']]);
            });
        } catch (\Throwable $exception) {
            try {
                $context = $this->auditContext($request, [
                    'matched' => [], 'revoked' => [], 'purged' => [], 'residual' => [],
                ]) + $failureContext + ['failure_code' => $this->failureCode($exception)];
                $this->assertAuditContextSafe($context, $request['prefix']);
                ($this->audit)((string) $adminId, $request['request_id'], 'acceptance_fixture.cleanup_failed', 'failed', $context);
            } catch (\Throwable) {
                // The original failure remains authoritative when failure-audit storage is unavailable.
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function status(array $payload, string $headerRequestId): array
    {
        $request = $this->validateRequest($payload, $headerRequestId);
        $records = $this->verifiedRecords($request, false, false);
        return [
            'chain_id' => $request['chain_id'],
            'request_id' => $request['request_id'],
            'residual' => $this->residual($request, $records),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validateRequest(array $payload, string $headerRequestId): array
    {
        $chainId = trim((string) ($payload['chain_id'] ?? ''));
        $spec = self::CHAINS[$chainId] ?? null;
        if ($spec === null) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_CHAIN_UNSUPPORTED: 当前业务链尚未提供受控自动清理', 400);
        $prefix = trim((string) ($payload['prefix'] ?? ''));
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_INVALID: 验收前缀必须使用 sand_iam_acceptance_ 加 16 位小写十六进制标识和结尾下划线', 400);
        if (($payload['confirmation'] ?? null) !== self::CONFIRMATION) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_CONFIRMATION_REQUIRED: 请明确确认只清理本轮验收数据', 400);
        $requestId = $this->requestId($payload['request_id'] ?? null, '清理请求');
        $this->assertRequestPrefix($requestId, $prefix, '清理请求');
        if ($requestId !== $this->requestId($headerRequestId, '请求头')) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_MISMATCH: 请求头与请求内容中的请求编号必须一致', 400);
        $organizationId = $this->positiveId($payload['organization_id'] ?? null, '客户主体');
        $applicationId = $this->positiveId($payload['application_id'] ?? null, '接入应用');
        $scopeIds = [];
        $expectedRoleId = null;
        foreach ($spec['scope_types'] ?? [] as $type) {
            $scopeIds[$type] = $this->positiveId($payload[$type . '_id'] ?? null, $this->scopeLabel($type));
        }
        $inputIds = $payload['object_ids'] ?? null;
        $submittedTypes = is_array($inputIds) ? array_keys($inputIds) : [];
        $expectedTypes = $spec['types'];
        sort($submittedTypes);
        sort($expectedTypes);
        $partialTypes = (bool) ($spec['partial_types'] ?? false);
        if (!is_array($inputIds)
            || (!$partialTypes && $submittedTypes !== $expectedTypes)
            || ($partialTypes && ($submittedTypes === [] || array_diff($submittedTypes, $expectedTypes) !== []))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 必须按固定对象类型逐项提交精确编号', 400);
        }
        $inputRequestIds = $payload['object_request_ids'] ?? null;
        $submittedRequestTypes = is_array($inputRequestIds) ? array_keys($inputRequestIds) : [];
        sort($submittedRequestTypes);
        if (!is_array($inputRequestIds) || $submittedRequestTypes !== $submittedTypes) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 必须逐项提交每个对象创建时的请求编号', 400);
        $objectIds = [];
        $objectRequestIds = [];
        $allObjectRequestIds = [];
        foreach ($submittedTypes as $type) {
            $values = $inputIds[$type] ?? null;
            if (!is_array($values) || !array_is_list($values) || $values === []) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 每种对象都必须提交非空编号列表', 400);
            $ids = array_map(fn (mixed $id): int => $this->positiveId($id, $type), $values);
            if (count(array_unique($ids)) !== count($ids)) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 对象编号不得重复', 400);
            $requestIds = $inputRequestIds[$type] ?? null;
            if (!is_array($requestIds) || !array_is_list($requestIds) || count($requestIds) !== count($ids)) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 每个对象必须对应一个创建请求编号', 400);
            $pairs = [];
            foreach ($ids as $offset => $id) {
                $objectRequestId = $this->requestId($requestIds[$offset] ?? null, $type);
                $this->assertRequestPrefix($objectRequestId, $prefix, $type);
                $allObjectRequestIds[] = $objectRequestId;
                $pairs[] = ['id' => $id, 'request_id' => $objectRequestId];
            }
            usort($pairs, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            $objectIds[$type] = array_column($pairs, 'id');
            $objectRequestIds[$type] = array_column($pairs, 'request_id');
        }
        $allowedChainThreeRegistrationPair = $chainId === 'human-auth-session-mfa'
            && ($objectRequestIds['identity'][0] ?? null) === ($objectRequestIds['auth_session'][0] ?? null)
            && count($allObjectRequestIds) === count(array_unique($allObjectRequestIds)) + 1;
        if ((!$allowedChainThreeRegistrationPair && count(array_unique($allObjectRequestIds)) !== count($allObjectRequestIds)) || in_array($requestId, $allObjectRequestIds, true)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 每次创建和清理都必须使用各自唯一的请求编号', 400);
        }
        $invocationRequestIds = [];
        $deliveryRetryRequestIds = [];
        $humanAuthActionRequestIds = [];
        $humanAuthSessionActions = [];
        if ($chainId === 'workload-credential-invocation') {
            $hasCredential = isset($objectIds['credential']);
            $hasGrant = isset($objectIds['service_grant']);
            if ($hasCredential && !$hasGrant) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 清理调用凭证时必须同时提交本轮服务授权', 400);
            $rawInvocationRequestIds = $payload['invocation_request_ids'] ?? [];
            if (!is_array($rawInvocationRequestIds) || !array_is_list($rawInvocationRequestIds)) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 调用操作必须提交本轮请求编号列表', 400);
            foreach ($rawInvocationRequestIds as $value) {
                $invocationRequestId = $this->requestId($value, '真实调用');
                $this->assertRequestPrefix($invocationRequestId, $prefix, '真实调用');
                $invocationRequestIds[] = $invocationRequestId;
            }
            if (count(array_unique($invocationRequestIds)) !== count($invocationRequestIds)
                || array_intersect($invocationRequestIds, $allObjectRequestIds) !== []
                || in_array($requestId, $invocationRequestIds, true)
                || (($hasCredential && $invocationRequestIds === []) || (!$hasCredential && $invocationRequestIds !== []))) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 真实调用、创建和清理必须使用各自唯一且完整的本轮请求编号', 400);
            }
        }
        if ($chainId === 'event-webhook-delivery') {
            $hasEndpoint = isset($objectIds['webhook_endpoint']);
            $hasDelivery = isset($objectIds['webhook_delivery']);
            if (!$hasEndpoint) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 清理 Webhook 投递时必须同时提交本轮端点', 400);
            }
            $rawRetryRequestIds = $payload['delivery_retry_request_ids'] ?? [];
            if ($hasDelivery) {
                if (!is_array($rawRetryRequestIds) || !array_is_list($rawRetryRequestIds) || count($rawRetryRequestIds) !== count($inputIds['webhook_delivery'] ?? [])) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 每条 Webhook 投递必须绑定本轮人工重试请求编号', 400);
                }
                $retryByDeliveryId = [];
                foreach ($inputIds['webhook_delivery'] as $offset => $deliveryId) {
                    $id = $this->positiveId($deliveryId, 'Webhook 投递');
                    $retryRequestId = $this->requestId($rawRetryRequestIds[$offset] ?? null, 'Webhook 重试');
                    $this->assertRequestPrefix($retryRequestId, $prefix, 'Webhook 重试');
                    if (isset($retryByDeliveryId[$id]) || in_array($retryRequestId, $deliveryRetryRequestIds, true)
                        || in_array($retryRequestId, $allObjectRequestIds, true) || $retryRequestId === $requestId) {
                        throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: Webhook 创建、投递、重试和清理必须使用各自唯一的请求编号', 400);
                    }
                    $retryByDeliveryId[$id] = $retryRequestId;
                    $deliveryRetryRequestIds[] = $retryRequestId;
                }
                $deliveryRetryRequestIds = array_map(static fn (int $id): string => $retryByDeliveryId[$id], $objectIds['webhook_delivery']);
            } elseif ($rawRetryRequestIds !== []) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 未产生投递时不得提交 Webhook 重试请求编号', 400);
            }
        }
        if ($chainId === 'identity-group-role-policy') {
            $hasIdentity = isset($objectIds['identity']);
            $hasGroup = isset($objectIds['identity_group']);
            $expectedRoleId = $this->positiveId($payload['role_id'] ?? null, '预置角色');
            if (isset($objectIds['identity_group_member']) && (!$hasIdentity || !$hasGroup)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 清理用户组成员关系时必须同时提交本轮身份和用户组', 400);
            }
            if (isset($objectIds['identity_group_role']) && !$hasGroup) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 清理用户组角色关系时必须同时提交本轮用户组', 400);
            }
        }
        if ($chainId === 'human-auth-session-mfa') {
            if (count($objectIds['identity'] ?? []) !== 1 || count($objectIds['mfa_factor'] ?? []) !== 1 || count($objectIds['auth_session'] ?? []) !== 3) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 登录验收每轮必须清理一个受控身份、三条会话和一个 MFA 因子', 400);
            }
            $rawActionIds = $payload['human_auth_action_request_ids'] ?? null;
            $actionNames = is_array($rawActionIds) ? array_keys($rawActionIds) : [];
            sort($actionNames);
            if (!is_array($rawActionIds) || $actionNames !== ['mfa_confirm', 'mfa_login_verify', 'mfa_revoke', 'session_revoke']) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 登录验收必须提交 MFA 绑定、MFA 撤销和会话撤销的本轮请求编号', 400);
            }
            foreach ($rawActionIds as $name => $value) {
                $values = $name === 'session_revoke' ? $value : [$value];
                if (!is_array($values) || !array_is_list($values) || ($name === 'session_revoke' && count($values) !== count($objectIds['auth_session']))) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 每个受控会话都必须有独立的会话撤销请求编号', 400);
                }
                $normalized = [];
                foreach ($values as $item) {
                    $actionRequestId = $this->requestId($item, '登录验收 ' . $name);
                    $this->assertRequestPrefix($actionRequestId, $prefix, '登录验收 ' . $name);
                    $mfaSessionAtomicRequest = $name === 'mfa_login_verify' && in_array($actionRequestId, $objectRequestIds['auth_session'] ?? [], true);
                    if ((!$mfaSessionAtomicRequest && in_array($actionRequestId, $allObjectRequestIds, true))
                        || $actionRequestId === $requestId
                        || in_array($actionRequestId, $humanAuthActionRequestIds, true)
                        || in_array($actionRequestId, $normalized, true)) {
                        throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 登录、MFA、撤销和清理必须使用各自唯一的本轮请求编号', 400);
                    }
                    $normalized[] = $actionRequestId;
                }
                $humanAuthActionRequestIds[$name] = $name === 'session_revoke' ? $normalized : $normalized[0];
            }
            $rawSessionActions = $payload['human_auth_session_actions'] ?? null;
            if (!is_array($rawSessionActions) || !array_is_list($rawSessionActions) || count($rawSessionActions) !== count($objectIds['auth_session'])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 每个受控会话必须声明实际签发动作', 400);
            }
            $actionMultiset = $rawSessionActions;
            sort($actionMultiset);
            if ($actionMultiset !== ['identity.login', 'identity.mfa_login', 'identity.register']) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 登录验收必须恰好声明注册、普通登录和 MFA 验证各一条会话', 400);
            }
            foreach ($rawSessionActions as $offset => $action) {
                if (!is_string($action) || !in_array($action, ['identity.register', 'identity.login', 'identity.mfa_login'], true)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 会话签发动作不属于冻结认证契约', 400);
                }
                if ($action === 'identity.mfa_login' && ($objectRequestIds['auth_session'][$offset] ?? null) !== $humanAuthActionRequestIds['mfa_login_verify']) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: MFA 会话必须绑定 MFA challenge 验证请求编号', 400);
                }
                $humanAuthSessionActions[] = $action;
            }
        }
        if ($chainId === 'oauth-cas-api-governance') {
            foreach (['environment', 'resource', 'identity'] as $type) {
                if (!isset($scopeIds[$type])) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: OAuth/CAS 接口治理验收缺少冻结的应用范围对象', 400);
                }
            }
        }
        return [
            'chain_id' => $chainId,
            'spec' => $spec,
            'request_id' => $requestId,
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'scope_ids' => $scopeIds,
            'object_ids' => $objectIds,
            'object_request_ids' => $objectRequestIds,
            'invocation_request_ids' => $invocationRequestIds,
            'delivery_retry_request_ids' => $deliveryRetryRequestIds,
            'human_auth_action_request_ids' => $humanAuthActionRequestIds,
            'human_auth_session_actions' => $humanAuthSessionActions,
            'expected_role_id' => $expectedRoleId,
            'prefix' => $prefix,
        ];
    }

    /** @param array<string,mixed> $request @return array<string,list<array<string,mixed>>> */
    private function verifiedRecords(array $request, bool $lock, bool $requirePresent): array
    {
        $records = [];
        if ($request['chain_id'] === 'human-auth-session-mfa') {
            return $this->verifiedHumanAuthArtifacts($request, $lock, $requirePresent);
        }
        if ($request['chain_id'] === 'delegation-scope') {
            $applications = $this->store->records('application', [$request['application_id']], $lock, $request['prefix']);
            if (count($applications) !== 1 || (int) ($applications[0]['organization_id'] ?? 0) !== $request['organization_id']) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 指定接入应用不属于客户主体边界', 400);
            }
        }
        if ($request['chain_id'] === 'identity-group-role-policy') {
            $applications = $this->store->records('application', [$request['application_id']], $lock, '');
            if (count($applications) !== 1 || (int) ($applications[0]['organization_id'] ?? 0) !== $request['organization_id']) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 指定接入应用不属于客户主体边界', 400);
            }
        }
        if ($request['chain_id'] === 'workload-credential-invocation') {
            $this->verifiedWorkloadInvocationScope($request, $lock);
        }
        if ($request['chain_id'] === 'event-webhook-delivery') {
            $this->verifiedWebhookScope($request, $lock);
        }
        if ($request['chain_id'] === 'oauth-cas-api-governance') {
            $this->verifiedOAuthCasApiGovernanceScope($request, $lock);
        }
        foreach (array_keys($request['object_ids']) as $type) {
            $ids = $request['object_ids'][$type];
            $rows = $this->store->records($type, $ids, $lock, $request['prefix']);
            if ($requirePresent && count($rows) !== count($ids)) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_NOT_FOUND: 本轮验收对象不存在或已被清理', 400);
            foreach ($rows as $row) $this->assertRecordBoundary($request, $type, $row);
            $records[$type] = $rows;
        }
        if ($request['chain_id'] === 'identity-group-role-policy') $this->verifiedIdentityGroupRoleScope($request, $lock, $requirePresent, $records);
        if ($request['chain_id'] === 'identity-group-role-policy') $this->assertIdentityGroupRoleRelationships($request, $records, $requirePresent);
        foreach (array_keys($request['object_ids']) as $type) {
            foreach ($request['object_ids'][$type] as $offset => $id) {
                $recordExists = array_filter($records[$type] ?? [], static fn (array $row): bool => (int) ($row['id'] ?? 0) === $id) !== [];
                if (!$requirePresent && !$recordExists) continue;
                $this->assertCreationAudit($request, $type, $id, $request['object_request_ids'][$type][$offset], $records[$type] ?? []);
            }
        }
        if ($request['chain_id'] === 'workload-credential-invocation') {
            $records += $this->verifiedInvocationArtifacts($request, $lock, $requirePresent);
        }
        if ($request['chain_id'] === 'event-webhook-delivery') {
            $records += $this->verifiedWebhookDeliveries($request, $lock, $requirePresent, $records);
        }
        if ($request['chain_id'] === 'oauth-cas-api-governance') {
            $records += $this->verifiedOAuthCasApiGovernanceArtifacts($request, $lock, $requirePresent, $records);
        }
        return $records;
    }

    /** @param array<string,mixed> $request @return array<string,list<array<string,mixed>>> */
    private function verifiedHumanAuthArtifacts(array $request, bool $lock, bool $requirePresent): array
    {
        $applications = $this->store->records('application', [$request['application_id']], $lock, '');
        if (count($applications) !== 1 || (int) ($applications[0]['organization_id'] ?? 0) !== $request['organization_id']) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 指定接入应用不属于客户主体边界', 400);
        }

        $identityIds = $request['object_ids']['identity'];
        $identityRows = $this->store->records('identity', $identityIds, $lock, $request['prefix']);
        if ($requirePresent && count($identityRows) !== count($identityIds)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_NOT_FOUND: 本轮受控应用用户不存在或已被清理', 400);
        }
        foreach ($identityRows as $row) $this->assertRecordBoundary($request, 'identity', $row);

        $artifacts = $this->store->humanAuthArtifacts($identityIds, $request['application_id'], $lock);
        foreach (['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) {
            if (!is_array($artifacts[$type] ?? null)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 登录验收对象查询未返回完整关系集合', 400);
            }
        }
        $expectedSessions = $request['object_ids']['auth_session'];
        $actualSessions = $this->sortedIds($artifacts['auth_session']);
        $expectedFactors = $request['object_ids']['mfa_factor'];
        $actualFactors = $this->sortedIds($artifacts['mfa_factor']);
        if ($requirePresent && ($actualSessions !== $expectedSessions || $actualFactors !== $expectedFactors)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 登录会话或 MFA 因子的完整本轮集合与提交编号不一致', 400);
        }
        foreach ($artifacts['identity_auth'] as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id'] || !in_array((int) ($row['identity_id'] ?? 0), $identityIds, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 认证凭据不属于本轮应用用户', 400);
            }
        }
        if ($requirePresent && count($artifacts['identity_auth']) !== 1) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 本轮应用用户的认证凭据集合不完整或包含额外记录', 400);
        }
        foreach ($artifacts['auth_session'] as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id'] || !in_array((int) ($row['identity_id'] ?? 0), $identityIds, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 会话不属于本轮应用用户', 400);
            }
        }
        foreach ($artifacts['mfa_factor'] as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id'] || !in_array((int) ($row['identity_id'] ?? 0), $identityIds, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: MFA 因子不属于本轮应用用户', 400);
            }
        }
        foreach ($artifacts['auth_refresh_token'] as $row) {
            if (!in_array((int) ($row['session_id'] ?? 0), $actualSessions, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 刷新令牌不属于本轮完整会话集合', 400);
            }
        }
        foreach ($artifacts['mfa_recovery_code'] as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id'] || !in_array((int) ($row['factor_id'] ?? 0), $actualFactors, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 恢复码不属于本轮完整 MFA 因子集合', 400);
            }
        }
        if ($requirePresent) {
            $identityRequestId = $request['object_request_ids']['identity'][0];
            $this->assertAuditIds('identity.register', 'identity', $identityRequestId, $identityIds, $request['prefix'], '应用用户注册');
            foreach ($request['object_ids']['auth_session'] as $offset => $sessionId) {
                $sessionCreateRequestId = $request['object_request_ids']['auth_session'][$offset];
                $sessionAction = $request['human_auth_session_actions'][$offset] ?? null;
                if ($sessionAction === 'identity.register') {
                    if ($sessionCreateRequestId !== $identityRequestId) {
                        throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 注册初始会话必须绑定身份注册请求编号', 400);
                    }
                    $this->assertAuditIds('identity.register', 'identity', $sessionCreateRequestId, $identityIds, $request['prefix'], '注册初始会话');
                } elseif ($sessionAction === 'identity.mfa_login') {
                    $this->assertAuditIds('identity.mfa_login', 'identity', $sessionCreateRequestId, $identityIds, $request['prefix'], 'MFA 会话签发');
                    if (!$this->store->mfaLoginChallengeAuditExists($request['application_id'], (int) $identityIds[0], $sessionCreateRequestId, $request['prefix'])) {
                        throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: MFA 会话缺少本轮身份、应用和 challenge 归属的验证审计', 400);
                    }
                } elseif ($sessionAction === 'identity.login' && $sessionCreateRequestId !== $identityRequestId) {
                    $this->assertAuditIds('identity.login', 'identity', $sessionCreateRequestId, $identityIds, $request['prefix'], '应用用户登录');
                } else {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 会话签发动作与注册请求不匹配', 400);
                }
                $sessionRevokeRequestId = $request['human_auth_action_request_ids']['session_revoke'][$offset] ?? null;
                if (!is_string($sessionRevokeRequestId)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 受控会话缺少对应撤销请求编号', 400);
                }
                $this->assertAuditIds('identity.session_revoke', 'auth_session', $sessionRevokeRequestId, [(int) $sessionId], $request['prefix'], '会话撤销');
            }
            $this->assertAuditIds('identity.totp_start', 'mfa_factor', $request['object_request_ids']['mfa_factor'][0], $expectedFactors, $request['prefix'], 'MFA 绑定开始');
            $this->assertAuditIds('identity.totp_confirm', 'mfa_factor', $request['human_auth_action_request_ids']['mfa_confirm'], $expectedFactors, $request['prefix'], 'MFA 绑定确认');
            $this->assertAuditIds('identity.mfa_revoke', 'mfa_factor', $request['human_auth_action_request_ids']['mfa_revoke'], $expectedFactors, $request['prefix'], 'MFA 撤销');
        }
        return ['identity' => $identityRows] + $artifacts;
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private function sortedIds(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    /** @param list<int> $ids */
    private function assertAuditIds(string $action, string $resourceType, string $requestId, array $ids, string $prefix, string $label): void
    {
        $actual = $this->store->creationAuditIds($action, $resourceType, $requestId, $ids, $prefix);
        $expected = $ids;
        sort($expected);
        if ($actual !== $expected) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: ' . $label . '缺少本轮完整成功审计', 400);
        }
    }

    /** @param array<string,list<array<string,mixed>>> $records */
    private function assertHumanAuthDrained(array $records): void
    {
        foreach ($records['auth_session'] ?? [] as $session) {
            if ((int) ($session['status'] ?? 0) !== 2 || empty($session['revoked_time'])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED: 受控会话仍在使用，必须先通过会话撤销接口完成失效', 409);
            }
        }
        foreach ($records['mfa_factor'] ?? [] as $factor) {
            if ((int) ($factor['status'] ?? 0) !== 2 || empty($factor['revoked_time'])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED: 受控 MFA 因子仍在绑定或启用，必须先通过 MFA 撤销接口完成失效', 409);
            }
        }
    }

    /** @param array<string,list<array<string,mixed>>> $records @return array<string,array<int,array<string,int|string|null>>> */
    private function humanAuthDrainSnapshot(array $records): array
    {
        $snapshot = [];
        foreach (['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) {
            $rows = $records[$type] ?? [];
            $byId = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) throw new \LogicException('登录验收 DRAIN 快照缺少稳定对象编号');
                $byId[$id] = [
                    'id' => $id,
                    'identity_id' => isset($row['identity_id']) ? (int) $row['identity_id'] : null,
                    'application_id' => isset($row['application_id']) ? (int) $row['application_id'] : null,
                    'parent_id' => isset($row['session_id']) ? (int) $row['session_id'] : (isset($row['factor_id']) ? (int) $row['factor_id'] : null),
                    'status' => isset($row['status']) ? (int) $row['status'] : null,
                    'revoked_time' => isset($row['revoked_time']) && $row['revoked_time'] !== '' ? (string) $row['revoked_time'] : null,
                ];
            }
            ksort($byId, SORT_NUMERIC);
            $snapshot[$type] = $byId;
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $request @return array<string,list<array<string,mixed>>> */
    private function verifiedInvocationArtifacts(array $request, bool $lock, bool $requirePresent): array
    {
        $grantIds = $request['object_ids']['service_grant'] ?? [];
        $credentialIds = $request['object_ids']['credential'] ?? [];
        $quotaIds = $grantIds === [] ? [] : $this->store->serviceQuotaBucketIds($grantIds, $lock);
        $quotaRows = $quotaIds === [] ? [] : $this->store->records('service_quota_bucket', $quotaIds, $lock, '');
        if ($credentialIds === []) return ['service_quota_bucket' => $quotaRows, 'service_invocation_operation' => []];
        $operations = $this->store->invocationOperations(
            $request['prefix'],
            $credentialIds,
            $grantIds,
            [
                'organization' => $request['organization_id'],
                'application' => $request['application_id'],
                'environment' => $request['scope_ids']['environment'],
                'workload_client' => $request['scope_ids']['workload_client'],
            ],
            $lock,
        );
        $actualRequestIds = [];
        foreach ($operations as $operation) {
            $operationRequestId = (string) ($operation['request_id'] ?? '');
            $operationId = (int) ($operation['id'] ?? 0);
            if ($operationId <= 0 || !str_starts_with($operationRequestId, $request['prefix']) || isset($actualRequestIds[$operationRequestId])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 调用操作不属于本轮精确请求范围或同一请求重复记录', 400);
            }
            $audited = $this->store->creationAuditIds('service.invoke.authorize', 'service_invocation_operation', $operationRequestId, [$operationId], $request['prefix']);
            if ($audited !== [$operationId]) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 调用操作缺少本轮成功调用审计，拒绝清理历史记录', 400);
            $actualRequestIds[$operationRequestId] = true;
        }
        $expectedRequestIds = $request['invocation_request_ids'];
        $actualRequestIds = array_keys($actualRequestIds);
        sort($expectedRequestIds);
        sort($actualRequestIds);
        if ($requirePresent && $actualRequestIds !== $expectedRequestIds) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_NOT_FOUND: 本轮真实调用操作与提交请求集合不完全一致', 400);
        }
        return ['service_quota_bucket' => $quotaRows, 'service_invocation_operation' => $operations];
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records @return array<string,list<array<string,mixed>>> */
    private function verifiedWebhookDeliveries(array $request, bool $lock, bool $requirePresent, array $records): array
    {
        $endpointIds = $request['object_ids']['webhook_endpoint'] ?? [];
        if ($endpointIds === []) return ['webhook_delivery' => []];
        $actual = $this->store->webhookDeliveries($endpointIds, $request['application_id'], $lock);
        foreach ($actual as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !in_array((int) ($row['webhook_endpoint_id'] ?? 0), $endpointIds, true)
                || !str_starts_with((string) ($row['event_id'] ?? ''), $request['prefix'])
                || (string) ($row['event_type'] ?? '') !== self::WEBHOOK_FIXTURE_EVENT_TYPE) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 投递不属于本轮端点、应用或固定前缀', 400);
            }
            $lockedUntil = (string) ($row['locked_until'] ?? '');
            if ((int) ($row['status'] ?? 0) === 2 || ($lockedUntil !== '' && $lockedUntil > date('Y-m-d H:i:s'))) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED: 存在由 worker 领取或持有未过期锁的 Webhook 投递；必须等待投递完成，或由 worker 回收过期锁为待投递后再清理，避免外部接收端副作用竞态', 409);
            }
        }
        $submitted = $request['object_ids']['webhook_delivery'] ?? [];
        $actualIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $actual)));
        sort($submitted);
        sort($actualIds);
        if ($requirePresent && $submitted !== $actualIds) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 投递全集与提交编号不完全一致，拒绝遗漏、额外或并发新增记录', 400);
        }
        if (!$requirePresent && $submitted !== [] && $actualIds !== [] && $submitted !== $actualIds) {
            // status must expose an unexpected residual instead of claiming
            // zero. The returned actual set below drives the residual count.
            return ['webhook_delivery' => $actual];
        }
        foreach ($actual as $row) {
            $id = (int) ($row['id'] ?? 0);
            $offset = array_search($id, $request['object_ids']['webhook_delivery'] ?? [], true);
            if ($requirePresent && (!is_int($offset) || !isset($request['object_request_ids']['webhook_delivery'][$offset]))) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: Webhook 投递缺少本轮事件请求绑定', 400);
            }
            if ($requirePresent) $this->assertCreationAudit($request, 'webhook_delivery', $id, $request['object_request_ids']['webhook_delivery'][$offset], $actual);
        }
        if ($requirePresent && $actualIds !== []) {
            $workerAudits = $this->store->webhookDeliveryAuditIds($actualIds);
            if ($workerAudits !== $actualIds) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: Webhook 投递缺少持久化投递审计，拒绝以未可追溯状态收口', 400);
            }
            foreach ($actualIds as $offset => $id) {
                $retryRequestId = $request['delivery_retry_request_ids'][$offset] ?? null;
                if (!is_string($retryRequestId)
                    || $this->store->creationAuditIds('webhook.delivery_retry', 'webhook_delivery', $retryRequestId, [$id], $request['prefix']) !== [$id]) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: Webhook 投递缺少本轮精确重试审计，拒绝清理', 400);
                }
            }
        }
        return ['webhook_delivery' => $actual];
    }

    /** @param array<string,mixed> $request @param list<array<string,mixed>> $rows */
    private function assertCreationAudit(array $request, string $type, int $id, string $objectRequestId, array $rows): void
    {
        if ($type === 'identity_group_member') {
            $row = array_values(array_filter($rows, static fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $id))[0] ?? null;
            $groupId = (int) ($row['identity_group_id'] ?? 0);
            $identityId = (int) ($row['identity_id'] ?? 0);
            $audited = $this->store->membershipCreationAuditIds($objectRequestId, $groupId, $identityId, $request['prefix']);
            if ($audited !== [$groupId]) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 用户组成员关系并非由本轮精确请求创建，拒绝清理历史关系', 400);
            return;
        }
        $audited = $this->store->creationAuditIds(
            $request['spec']['actions'][$type],
            $type,
            $objectRequestId,
            [$id],
            $request['prefix'],
        );
        if ($audited !== [$id]) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: 对象并非由本轮验收请求创建，拒绝清理预置或历史数据', 400);
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records */
    private function assertIdentityGroupRoleRelationships(array $request, array $records, bool $strictSubmission): void
    {
        $identityIds = $request['object_ids']['identity'] ?? [];
        $groupIds = $request['object_ids']['identity_group'] ?? [];
        $memberPairs = [];
        foreach ($records['identity_group_member'] ?? [] as $row) {
            $groupId = (int) ($row['identity_group_id'] ?? 0);
            $identityId = (int) ($row['identity_id'] ?? 0);
            $pair = $groupId . ':' . $identityId;
            if (($strictSubmission && (!in_array($groupId, $groupIds, true)
                || !in_array($identityId, $identityIds, true)))
                || isset($memberPairs[$pair])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 用户组成员关系未同时归属本轮捕获的身份和用户组', 400);
            }
            $memberPairs[$pair] = true;
        }
        $groupRolePairs = [];
        foreach ($records['identity_group_role'] ?? [] as $row) {
            $groupId = (int) ($row['identity_group_id'] ?? 0);
            $roleId = (int) ($row['role_id'] ?? 0);
            $pair = $groupId . ':' . $roleId;
            if (($strictSubmission && !in_array($groupId, $groupIds, true)) || $roleId !== $request['expected_role_id'] || isset($groupRolePairs[$pair])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 用户组角色关系未归属本轮捕获的用户组', 400);
            }
            $groupRolePairs[$pair] = true;
        }
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records */
    private function verifiedIdentityGroupRoleScope(array $request, bool $lock, bool $requirePresent, array &$records): void
    {
        $roles = $this->store->records('role', [$request['expected_role_id']], $lock, '');
        $role = $roles[0] ?? null;
        if (count($roles) !== 1
            || (int) ($role['application_id'] ?? 0) !== $request['application_id']
            || (int) ($role['status'] ?? 0) !== 1
            || str_starts_with((string) ($role['code'] ?? ''), $request['prefix'])) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 预置角色不存在、未启用或不属于当前应用边界', 400);
        }
        $actualMembers = $this->store->identityGroupMembers($request['prefix'], $request['application_id'], $lock);
        $actualRoles = $this->store->identityGroupRoles($request['prefix'], $request['application_id'], $lock);
        if ($requirePresent) {
            foreach (['identity_group_member' => $actualMembers, 'identity_group_role' => $actualRoles] as $type => $rows) {
                $submitted = $request['object_ids'][$type] ?? [];
                $actual = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
                sort($submitted);
                sort($actual);
                if ($submitted !== $actual) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 本轮用户组关系全集与提交对象不完全一致，拒绝清理遗漏、额外或历史关系', 400);
                }
            }
        }
        $records['identity_group_member'] = $actualMembers;
        $records['identity_group_role'] = $actualRoles;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $row */
    private function assertRecordBoundary(array $request, string $type, array $row): void
    {
        if (in_array($type, ['organization', 'application', 'environment', 'identity', 'identity_group'], true)) {
            $code = (string) ($row['code'] ?? '');
            if (!str_starts_with($code, $request['prefix'])) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_INVALID: 对象代码不属于本轮验收前缀', 400);
        }
        if ($type === 'organization' && (int) ($row['id'] ?? 0) !== $request['organization_id']) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 客户主体边界不匹配', 400);
        if ($type === 'application' && ((int) ($row['id'] ?? 0) !== $request['application_id'] || (int) ($row['organization_id'] ?? 0) !== $request['organization_id'])) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 接入应用边界不匹配', 400);
        if ($type === 'environment' && (int) ($row['application_id'] ?? 0) !== $request['application_id']) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 应用环境边界不匹配', 400);
        if ($type === 'admin_application_grant' && (int) ($row['application_id'] ?? 0) !== $request['application_id']) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 管理委派不属于指定接入应用', 400);
        if (in_array($type, ['identity', 'identity_group', 'identity_group_member', 'identity_group_role'], true)
            && (int) ($row['application_id'] ?? 0) !== $request['application_id']) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 身份、用户组或关系不属于指定接入应用', 400);
        }
        if ($type === 'credential') {
            if ((int) ($row['workload_client_id'] ?? 0) !== ($request['scope_ids']['workload_client'] ?? 0)
                || !str_starts_with((string) ($row['name'] ?? ''), $request['prefix'])) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 调用凭证不属于本轮指定调用身份或验收前缀', 400);
            }
        }
        if ($type === 'service_grant'
            && ((int) ($row['workload_client_id'] ?? 0) !== ($request['scope_ids']['workload_client'] ?? 0)
                || (int) ($row['service_action_id'] ?? 0) !== ($request['scope_ids']['service_action'] ?? 0))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 服务授权不属于本轮指定调用身份或服务动作', 400);
        }
        if ($type === 'webhook_endpoint'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !str_starts_with((string) ($row['code'] ?? ''), $request['prefix']))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 端点不属于指定应用或本轮验收前缀', 400);
        }
        if ($type === 'webhook_delivery'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !str_starts_with((string) ($row['event_id'] ?? ''), $request['prefix']))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 投递不属于指定应用或本轮事件前缀', 400);
        }
        if ($type === 'oauth_client'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !str_starts_with((string) ($row['code'] ?? ''), $request['prefix']))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: OAuth 客户端不属于指定应用或本轮验收前缀', 400);
        }
        if ($type === 'cas_service'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !str_starts_with((string) ($row['name'] ?? ''), $request['prefix']))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: CAS 服务不属于指定应用或本轮验收前缀', 400);
        }
        if ($type === 'api_resource'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || (int) ($row['resource_id'] ?? 0) !== ($request['scope_ids']['resource'] ?? 0)
                || !str_starts_with((string) ($row['code'] ?? ''), $request['prefix']))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 接口目录不属于指定应用、资源或本轮验收前缀', 400);
        }
        if ($type === 'api_route_binding'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !in_array((int) ($row['api_resource_id'] ?? 0), $request['object_ids']['api_resource'] ?? [], true))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 路由绑定不属于本轮接口目录', 400);
        }
        if ($type === 'policy'
            && ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || (int) ($row['resource_id'] ?? 0) !== ($request['scope_ids']['resource'] ?? 0)
                || (int) ($row['identity_id'] ?? 0) !== ($request['scope_ids']['identity'] ?? 0))) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 策略不属于本轮应用身份和业务资源边界', 400);
        }
    }

    /** @param array<string,mixed> $request */
    private function verifiedWorkloadInvocationScope(array $request, bool $lock): void
    {
        // Scope objects are operator-approved prerequisites, not this-run
        // fixture records; never require their code/name to carry the prefix.
        $organization = $this->exactScopeRecord('organization', $request['organization_id'], $lock);
        $application = $this->exactScopeRecord('application', $request['application_id'], $lock);
        $environment = $this->exactScopeRecord('environment', $request['scope_ids']['environment'], $lock);
        $client = $this->exactScopeRecord('workload_client', $request['scope_ids']['workload_client'], $lock);
        $service = $this->exactScopeRecord('service', $request['scope_ids']['service'], $lock);
        $action = $this->exactScopeRecord('service_action', $request['scope_ids']['service_action'], $lock);

        if ((int) ($organization['id'] ?? 0) !== $request['organization_id']
            || (int) ($application['organization_id'] ?? 0) !== $request['organization_id']
            || (int) ($environment['application_id'] ?? 0) !== $request['application_id']
            || (int) ($client['environment_id'] ?? 0) !== $request['scope_ids']['environment']
            || (int) ($action['service_id'] ?? 0) !== $request['scope_ids']['service']) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 客户主体、应用、环境、调用身份、服务或服务动作归属不匹配', 400);
        }
    }

    /** @param array<string,mixed> $request */
    private function verifiedWebhookScope(array $request, bool $lock): void
    {
        $application = $this->exactScopeRecord('application', $request['application_id'], $lock);
        if ((int) ($application['organization_id'] ?? 0) !== $request['organization_id']) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 接入应用不属于指定客户主体', 400);
        }
    }

    /** @param array<string,mixed> $request */
    private function verifiedOAuthCasApiGovernanceScope(array $request, bool $lock): void
    {
        $organization = $this->exactScopeRecord('organization', $request['organization_id'], $lock);
        $application = $this->exactScopeRecord('application', $request['application_id'], $lock);
        $environment = $this->exactScopeRecord('environment', $request['scope_ids']['environment'], $lock);
        $resource = $this->exactScopeRecord('resource', $request['scope_ids']['resource'], $lock);
        $identity = $this->exactScopeRecord('identity', $request['scope_ids']['identity'], $lock);
        if ((int) ($application['organization_id'] ?? 0) !== (int) ($organization['id'] ?? 0)
            || (int) ($application['status'] ?? 0) !== 1
            || (int) ($environment['application_id'] ?? 0) !== $request['application_id']
            || (int) ($environment['status'] ?? 0) !== 1
            || (int) ($resource['application_id'] ?? 0) !== $request['application_id']
            || (int) ($resource['status'] ?? 0) !== 1
            || (int) ($identity['application_id'] ?? 0) !== $request['application_id']) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: OAuth/CAS 接口治理的客户主体、启用应用、可清理环境、资源或身份归属不匹配', 400);
        }
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records @return array<string,list<array<string,mixed>>> */
    private function verifiedOAuthCasApiGovernanceArtifacts(array $request, bool $lock, bool $requirePresent, array $records): array
    {
        $universe = $this->store->oauthCasApiGovernanceUniverse(
            $request['prefix'], $request['application_id'], $request['scope_ids']['resource'], $request['scope_ids']['identity'], $lock,
        );
        // Policy rows have no acceptance prefix/code column in the immutable
        // schema. Their exact creation audit and derived-version scope below
        // remain the ownership authority; the other roots are discoverable.
        foreach (['oauth_client', 'cas_service', 'api_resource', 'api_route_binding'] as $type) {
            $actualIds = $this->sortedIds($universe[$type] ?? []);
            $submittedIds = $this->sortedIds(array_map(static fn (int $id): array => ['id' => $id], $request['object_ids'][$type] ?? []));
            if ($requirePresent && $actualIds !== $submittedIds) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SET_DENIED: OAuth/CAS 接口治理同前缀对象全集与提交集合不一致（' . $type . '），拒绝写入', 400);
            }
            if (!$requirePresent) $records[$type] = $universe[$type] ?? [];
        }
        foreach ([
            'oauth_client' => ['action' => 'oauth_client.create', 'resource_type' => 'oauth_client'],
            'cas_service' => ['action' => 'cas_service.create', 'resource_type' => 'cas_service'],
            'api_resource' => ['action' => 'api_resource.create', 'resource_type' => 'api_resource'],
            'api_route_binding' => ['action' => 'api_route_binding.create', 'resource_type' => 'api_route_binding'],
            'policy' => ['action' => 'policy.create', 'resource_type' => 'policy'],
        ] as $type => $audit) {
            $requestIds = $request['object_request_ids'][$type] ?? [];
            // status is deliberately allowed after successful cleanup: roots
            // are gone, but their creation audit remains the immutable proof
            // that this exact run once owned them. A recreated root is still
            // exposed by residual(), and an extra audit is rejected here.
            $submittedIds = $requirePresent
                ? $this->sortedIds($records[$type] ?? [])
                : $this->sortedIds(array_map(static fn (int $id): array => ['id' => $id], $request['object_ids'][$type] ?? []));
            foreach ($requestIds as $requestId) {
                $actualIds = $this->store->allCreationAuditIds($audit['action'], $audit['resource_type'], $requestId, $request['prefix']);
                if ($actualIds !== $submittedIds) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: OAuth/CAS 接口治理创建审计与提交对象集不一致', 400);
                }
            }
        }
        $artifacts = $universe;
        foreach (['oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'] as $type) {
            if (!is_array($artifacts[$type] ?? null)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: OAuth/CAS 派生对象查询未返回完整关系集合', 400);
            }
        }
        $oauthClientIds = $request['object_ids']['oauth_client'] ?? [];
        foreach (['oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token'] as $type) {
            foreach ($artifacts[$type] as $row) {
                if ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                    || !in_array((int) ($row['client_id'] ?? 0), $oauthClientIds, true)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: OAuth 派生记录不属于本轮客户端或应用', 400);
                }
            }
        }
        $casServiceIds = $request['object_ids']['cas_service'] ?? [];
        foreach (['cas_login_request', 'cas_ticket'] as $type) {
            foreach ($artifacts[$type] as $row) {
                if ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                    || !in_array((int) ($row['cas_service_id'] ?? 0), $casServiceIds, true)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: CAS 派生记录不属于本轮服务或应用', 400);
                }
            }
        }
        $policyIds = $request['object_ids']['policy'] ?? [];
        foreach ($artifacts['policy_version'] as $row) {
            if ((int) ($row['application_id'] ?? 0) !== $request['application_id']
                || !in_array((int) ($row['policy_id'] ?? 0), $policyIds, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 策略版本不属于本轮策略或应用', 400);
            }
        }
        return $artifacts;
    }

    /** @return array<string,mixed> */
    private function exactScopeRecord(string $type, int $id, bool $lock): array
    {
        $rows = $this->store->records($type, [$id], $lock, '');
        if (count($rows) !== 1) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 指定的验收调用范围对象不存在', 400);
        return $rows[0];
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records @return array<string,int> */
    private function residual(array $request, array $records): array
    {
        $residual = [];
        foreach (array_keys($request['object_ids']) as $type) $residual[$type] = count($this->store->records($type, $request['object_ids'][$type], false, $request['prefix']));
        if ($request['chain_id'] === 'human-auth-session-mfa') {
            $artifacts = $this->store->humanAuthArtifacts($request['object_ids']['identity'], $request['application_id'], false);
            foreach (['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) {
                $residual[$type] = count($artifacts[$type] ?? []);
            }
        }
        if ($request['chain_id'] === 'identity-group-role-policy') {
            $residual['identity_group_member'] = count($this->store->identityGroupMembers($request['prefix'], $request['application_id'], false));
            $residual['identity_group_role'] = count($this->store->identityGroupRoles($request['prefix'], $request['application_id'], false));
        }
        if ($request['chain_id'] === 'event-webhook-delivery') {
            $endpointIds = $request['object_ids']['webhook_endpoint'] ?? [];
            $actualDeliveries = $endpointIds === [] ? [] : $this->store->webhookDeliveries($endpointIds, $request['application_id'], false);
            $residual['webhook_delivery'] = count($actualDeliveries);
            $residual['webhook_endpoint'] = count($this->store->records('webhook_endpoint', $endpointIds, false, $request['prefix']));
        }
        if ($request['chain_id'] === 'oauth-cas-api-governance') {
            $universe = $this->store->oauthCasApiGovernanceUniverse(
                $request['prefix'], $request['application_id'], $request['scope_ids']['resource'], $request['scope_ids']['identity'], false,
            );
            foreach (['oauth_client', 'cas_service', 'api_resource', 'api_route_binding'] as $type) {
                $residual[$type] = count($universe[$type] ?? []);
            }
            foreach (['oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'] as $type) {
                $residual[$type] = count($records[$type] ?? []) === 0 ? 0 : count($this->store->records($type, $this->sortedIds($records[$type]), false, ''));
            }
        }
        foreach (['service_quota_bucket', 'service_invocation_operation'] as $type) {
            $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $records[$type] ?? [])));
            $residual[$type] = $ids === [] ? 0 : count($this->store->records($type, $ids, false, ''));
        }
        return $residual;
    }

    /** @param array<string,mixed> $request @param array<string,list<array<string,mixed>>> $records @return array<string,list<int>> */
    private function recordIds(array $request, array $records): array
    {
        $ids = $request['object_ids'];
        if ($request['chain_id'] === 'human-auth-session-mfa') {
            foreach (['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) {
                $ids[$type] = $this->sortedIds($records[$type] ?? []);
            }
        }
        foreach (['service_quota_bucket', 'service_invocation_operation'] as $type) {
            $ids[$type] = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $records[$type] ?? [])));
        }
        if ($request['chain_id'] === 'oauth-cas-api-governance') {
            foreach (['oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'] as $type) {
                $ids[$type] = $this->sortedIds($records[$type] ?? []);
            }
        }
        return $ids;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $result @return array<string,mixed> */
    private function auditContext(array $request, array $result): array
    {
        $context = [
            'chain_id' => $request['chain_id'],
            'prefix_sha256' => hash('sha256', $request['prefix']),
            'organization_id' => $request['organization_id'],
            'application_id' => $request['application_id'],
            'object_counts' => array_map('count', $request['object_ids']),
            'matched' => $result['matched'],
            'revoked' => $result['revoked'],
            'purged' => $result['purged'],
            'residual' => $result['residual'],
        ];
        $this->assertAuditContextSafe($context, $request['prefix']);
        return $context;
    }

    /** @param array<string,mixed> $context */
    private function assertAuditContextSafe(array $context, string $prefix): void
    {
        $walk = function (mixed $value, string $path = '') use (&$walk, $prefix): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $name = strtolower((string) $key);
                    // These are table/category labels in the deliberately
                    // count-only audit context, never protocol field names.
                    // Keep the exception at the concrete child level: their
                    // descendants remain recursively inspected.
                    $countOnlyTypes = ['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket'];
                    $artifactCollection = is_array($item) && in_array($name, $countOnlyTypes, true);
                    $artifactCount = is_int($item) && $item >= 0 && in_array($name, $countOnlyTypes, true);
                    if (!$artifactCollection && !$artifactCount && preg_match('/(?:password|client[_-]?secret|(?:code[_-]?)?verifier|code[_-]?challenge|pkce|cas[_-]?ticket|authorization[_-]?code|csrf(?:[_-]?token)?|nonce|access[_-]?token|refresh[_-]?token|challenge[_-]?token|totp(?:[_-]?(?:secret|code))?|recovery[_-]?codes?|confirmation|request[_-]?id)/', $name) === 1) {
                        throw new \LogicException('验收审计上下文不得包含敏感字段：' . $path . '/' . $key);
                    }
                    $walk($item, $path . '/' . $key);
                }
                return;
            }
            $key = strtolower((string) substr($path, strrpos($path, '/') + 1));
            if (is_int($value) && $value >= 0) return;
            if (is_bool($value) && $key === 'unchanged') return;
            if ($value === null && in_array($key, ['identity_id', 'application_id', 'parent_id', 'status', 'revoked_time'], true)) return;
            if (is_string($value) && $key === 'prefix_sha256' && preg_match('/^[a-f0-9]{64}$/', $value) === 1) return;
            if (is_string($value) && $key === 'chain_id' && isset(self::CHAINS[$value])) return;
            if (is_string($value) && $key === 'failure_code' && preg_match('/^SAND_IAM_[A-Z0-9_]+$/', $value) === 1) return;
            if (is_string($value) && $key === 'revoked_time' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) return;
            $text = is_scalar($value) ? (string) $value : get_debug_type($value);
            if ($text === self::CONFIRMATION || str_contains($text, $prefix) || preg_match('/(?:siam_(?:ac|at|rt)_|otpauth:|challenge-|recovery[_-]?code|(?:^|[^A-Za-z0-9])ST-[A-Za-z0-9._-]+)/i', $text) === 1) {
                throw new \LogicException('验收审计上下文不得包含敏感原值：' . $path);
            }
            throw new \LogicException('验收审计上下文包含非白名单叶子值：' . $path);
        };
        $walk($context);
    }

    private function positiveId(mixed $value, string $label): int
    {
        $text = is_int($value) ? (string) $value : trim((string) $value);
        $maximum = (string) PHP_INT_MAX;
        if (preg_match('/^[1-9]\d*$/', $text) !== 1 || strlen($text) > strlen($maximum) || (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
            throw new ApiException("SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: {$label}编号必须是正整数", 400);
        }
        return (int) $text;
    }

    private function failureCode(\Throwable $exception): string
    {
        if ($exception instanceof ApiException && preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $match) === 1) {
            return $match[1];
        }
        return 'SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_FAILED';
    }

    private function requestId(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match(self::REQUEST_ID_PATTERN, $text) !== 1) {
            throw new ApiException("SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: {$label}请求编号必须以完整本轮验收前缀开头且使用安全后缀", 400);
        }
        return RequestId::normalize($text);
    }

    private function assertRequestPrefix(string $requestId, string $prefix, string $label): void
    {
        if (!str_starts_with($requestId, $prefix)) {
            throw new ApiException("SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: {$label}请求编号必须属于本轮固定验收前缀", 400);
        }
    }

    private function scopeLabel(string $type): string
    {
        return match ($type) {
            'environment' => '应用环境',
            'workload_client' => '服务调用身份',
            'service' => '平台服务',
            'service_action' => '服务动作',
            default => $type,
        };
    }
}

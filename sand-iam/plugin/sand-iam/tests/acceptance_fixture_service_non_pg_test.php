<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    require_once dirname(__DIR__) . '/app/service/RequestId.php';
    require_once dirname(__DIR__) . '/app/service/IdempotencyService.php';
    require_once dirname(__DIR__) . '/app/acceptance/AcceptanceFixtureStore.php';
    require_once dirname(__DIR__) . '/app/acceptance/AcceptanceFixtureService.php';

    use plugin\SandIam\app\acceptance\AcceptanceFixtureService;
    use plugin\SandIam\app\acceptance\AcceptanceFixtureStore;
    use plugin\sandadmin\exception\ApiException;

    const ACCEPTANCE_FIXTURE_PREFIX = 'sand_iam_acceptance_0123456789abcdef_';

    final class AcceptanceFixtureMemoryStore implements AcceptanceFixtureStore
    {
        /** @var array<string,array<int,array<string,mixed>>> */
        public array $rows;
        /** @var array<string,list<int>> */
        public array $audits;
        /** @var list<string> */
        public array $operations = [];
        public ?string $failPurgeType = null;
        /** @var null|\Closure(self,int):void */
        public ?\Closure $humanAuthArtifactsHook = null;
        public int $humanAuthArtifactsCalls = 0;

        /** @param array<string,array<int,array<string,mixed>>> $rows @param array<string,list<int>> $audits */
        public function __construct(array $rows, array $audits)
        {
            $this->rows = $rows;
            $this->audits = $audits;
        }

        public function transaction(callable $operation): mixed
        {
            $snapshot = $this->rows;
            $this->operations[] = 'transaction:start';
            try {
                $result = $operation();
                $this->operations[] = 'transaction:commit';
                return $result;
            } catch (\Throwable $exception) {
                $this->rows = $snapshot;
                $this->operations[] = 'transaction:rollback';
                throw $exception;
            }
        }

        public function records(string $type, array $ids, bool $lock, string $prefix): array
        {
            $this->operations[] = 'records:' . $type . ':' . ($lock ? 'lock' : 'read');
            $result = [];
            foreach ($ids as $id) {
                if (isset($this->rows[$type][$id])) $result[] = $this->rows[$type][$id];
            }
            return $result;
        }

        public function updateStatus(string $type, array $ids, int $status, string $prefix): int
        {
            $this->operations[] = 'revoke:' . $type;
            $count = 0;
            foreach ($ids as $id) {
                if (!isset($this->rows[$type][$id])) continue;
                $this->rows[$type][$id]['status'] = $status;
                if (in_array($type, ['credential', 'service_grant'], true) && $status === 2) $this->rows[$type][$id]['revoked_time'] = '2026-08-30 00:00:00';
                if ($type === 'identity' && $status === 2) $this->rows[$type][$id] += ['deleted_time' => '2026-08-30 00:00:00', 'purge_after' => '2026-08-30 00:00:00'];
                if ($type === 'identity' && $status === 2) $this->rows[$type][$id]['lifecycle_state'] = 'deleted';
                $count++;
            }
            return $count;
        }

        public function purge(string $type, array $ids, string $prefix): int
        {
            $this->operations[] = 'purge:' . $type;
            if ($this->failPurgeType === $type) throw new \RuntimeException('forced purge failure');
            $count = 0;
            foreach ($ids as $id) {
                if (!isset($this->rows[$type][$id])) continue;
                unset($this->rows[$type][$id]);
                $count++;
            }
            return $count;
        }

        public function creationAuditIds(string $action, string $resourceType, string $requestId, array $ids, string $prefix): array
        {
            $key = $requestId . '|' . $action . '|' . $resourceType;
            $found = array_values(array_intersect($ids, $this->audits[$key] ?? []));
            sort($found);
            return $found;
        }

        /** @var list<array{application_id:int,identity_id:int,request_id:string,actor_ref:string,challenge_id:int,challenge_application_id:int,challenge_identity_id:int,purpose:string,outcome:string}> */
        public array $mfaLoginChallengeAudits = [];

        public function mfaLoginChallengeAuditExists(int $applicationId, int $identityId, string $requestId, string $prefix): bool
        {
            foreach ($this->mfaLoginChallengeAudits as $audit) {
                if ($audit['application_id'] === $applicationId
                    && $audit['identity_id'] === $identityId
                    && $audit['request_id'] === $requestId
                    && str_starts_with($audit['request_id'], $prefix)
                    && $audit['actor_ref'] === (string) $identityId
                    && $audit['challenge_id'] > 0
                    && $audit['challenge_application_id'] === $applicationId
                    && $audit['challenge_identity_id'] === $identityId
                    && $audit['purpose'] === 'mfa_login'
                    && $audit['outcome'] === 'succeeded') return true;
            }
            return $this->mfaLoginChallengeAudits === []
                && ($this->audits[$requestId . '|identity.mfa_login_verify|mfa_challenge'] ?? []) === [901];
        }

        public function membershipCreationAuditIds(string $requestId, int $identityGroupId, int $identityId, string $prefix): array
        {
            $key = $requestId . '|identity_group.member_add|identity_group|' . $identityId;
            $this->operations[] = 'membership-audit:' . $key;
            $found = array_values(array_intersect([$identityGroupId], $this->audits[$key] ?? []));
            sort($found);
            return $found;
        }

        public function identityGroupMembers(string $prefix, int $applicationId, bool $lock): array
        {
            $this->operations[] = 'records:identity_group_member:all:' . ($lock ? 'lock' : 'read');
            return array_values(array_filter($this->rows['identity_group_member'] ?? [], function (array $row) use ($prefix, $applicationId): bool {
                $group = $this->rows['identity_group'][(int) ($row['identity_group_id'] ?? 0)] ?? [];
                $identity = $this->rows['identity'][(int) ($row['identity_id'] ?? 0)] ?? [];
                return (int) ($row['application_id'] ?? 0) === $applicationId
                    && (int) ($group['application_id'] ?? 0) === $applicationId
                    && (int) ($identity['application_id'] ?? 0) === $applicationId
                    && (str_starts_with((string) ($group['code'] ?? ''), $prefix)
                        || str_starts_with((string) ($identity['code'] ?? ''), $prefix));
            }));
        }

        public function identityGroupRoles(string $prefix, int $applicationId, bool $lock): array
        {
            $this->operations[] = 'records:identity_group_role:all:' . ($lock ? 'lock' : 'read');
            return array_values(array_filter($this->rows['identity_group_role'] ?? [], function (array $row) use ($prefix, $applicationId): bool {
                $group = $this->rows['identity_group'][(int) ($row['identity_group_id'] ?? 0)] ?? [];
                return (int) ($row['application_id'] ?? 0) === $applicationId
                    && (int) ($group['application_id'] ?? 0) === $applicationId
                    && str_starts_with((string) ($group['code'] ?? ''), $prefix);
            }));
        }

        public function invocationOperations(string $prefix, array $credentialIds, array $grantIds, array $scopeIds, bool $lock): array
        {
            $this->operations[] = 'records:service_invocation_operation:' . ($lock ? 'lock' : 'read');
            return array_values(array_filter($this->rows['service_invocation_operation'] ?? [], static function (array $row) use ($prefix, $credentialIds, $grantIds, $scopeIds): bool {
                return str_starts_with((string) ($row['request_id'] ?? ''), $prefix)
                    && in_array((int) ($row['credential_id'] ?? 0), $credentialIds, true)
                    && in_array((int) ($row['grant_id'] ?? 0), $grantIds, true)
                    && (int) ($row['organization_id'] ?? 0) === $scopeIds['organization']
                    && (int) ($row['application_id'] ?? 0) === $scopeIds['application']
                    && (int) ($row['environment_id'] ?? 0) === $scopeIds['environment']
                    && (int) ($row['workload_client_id'] ?? 0) === $scopeIds['workload_client'];
            }));
        }

        public function serviceQuotaBucketIds(array $grantIds, bool $lock): array
        {
            $this->operations[] = 'records:service_quota_bucket:' . ($lock ? 'lock' : 'read');
            $ids = array_keys(array_filter($this->rows['service_quota_bucket'] ?? [], static fn (array $row): bool => in_array((int) ($row['grant_id'] ?? 0), $grantIds, true)));
            sort($ids);
            return array_map('intval', $ids);
        }

        public function webhookDeliveries(array $endpointIds, int $applicationId, bool $lock): array
        {
            $this->operations[] = 'records:webhook_delivery:all:' . ($lock ? 'lock' : 'read');
            return array_values(array_filter($this->rows['webhook_delivery'] ?? [], static function (array $row) use ($endpointIds, $applicationId): bool {
                return in_array((int) ($row['webhook_endpoint_id'] ?? 0), $endpointIds, true)
                    && (int) ($row['application_id'] ?? 0) === $applicationId;
            }));
        }

        public function webhookDeliveryAuditIds(array $deliveryIds): array
        {
            $result = [];
            foreach ($this->audits as $key => $ids) {
                if (!str_contains($key, '|webhook.delivery|webhook_delivery')) continue;
                foreach ($ids as $id) if (in_array($id, $deliveryIds, true)) $result[] = $id;
            }
            $result = array_values(array_unique($result));
            sort($result);
            return $result;
        }

        public function allCreationAuditIds(string $action, string $resourceType, string $requestId, string $prefix): array
        {
            $result = [];
            foreach ($this->audits as $key => $ids) {
                [$auditPrefix, $auditAction, $auditType] = array_pad(explode('|', $key, 3), 3, '');
                if ($auditPrefix === $requestId && str_starts_with($auditPrefix, $prefix) && $auditAction === $action && $auditType === $resourceType) {
                    foreach ($ids as $id) $result[] = $id;
                }
            }
            $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $result)));
            sort($result);
            return $result;
        }

        /** @var array<string,int> */
        public array $creationAuditActors = [];

        public function creationAuditCreatedBy(string $action, string $resourceType, string $requestId, int $resourceId, string $prefix, int $adminId): bool
        {
            $key = $requestId . '|' . $action . '|' . $resourceType . '|' . $resourceId;
            return ($this->creationAuditActors[$key] ?? 1) === $adminId;
        }

        public function organizationApplicationEnvironmentUniverse(string $prefix, bool $lock): array
        {
            $this->operations[] = 'records:organization_application_environment_universe:' . ($lock ? 'lock' : 'read');
            $roots = ['organization' => array_values(array_filter($this->rows['organization'] ?? [], static fn (array $row): bool => str_starts_with((string) ($row['code'] ?? ''), $prefix)))];
            $organizationIds = array_map(static fn (array $row): int => (int) $row['id'], $roots['organization']);
            $roots['application'] = array_values(array_filter($this->rows['application'] ?? [], static fn (array $row): bool => str_starts_with((string) ($row['code'] ?? ''), $prefix) || in_array((int) ($row['organization_id'] ?? 0), $organizationIds, true)));
            $applicationIds = array_map(static fn (array $row): int => (int) $row['id'], $roots['application']);
            $roots['environment'] = array_values(array_filter($this->rows['environment'] ?? [], static fn (array $row): bool => str_starts_with((string) ($row['code'] ?? ''), $prefix) || in_array((int) ($row['application_id'] ?? 0), $applicationIds, true)));
            $roots['admin_application_grant'] = array_values(array_filter($this->rows['admin_application_grant'] ?? [], static fn (array $row): bool => in_array((int) ($row['application_id'] ?? 0), $applicationIds, true)));
            return $roots;
        }

        public function humanAuthArtifacts(array $identityIds, int $applicationId, bool $lock): array
        {
            $this->operations[] = 'records:human_auth_artifacts:' . ($lock ? 'lock' : 'read');
            $this->humanAuthArtifactsCalls++;
            if ($this->humanAuthArtifactsHook !== null) ($this->humanAuthArtifactsHook)($this, $this->humanAuthArtifactsCalls);
            $byIdentity = static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId
                && in_array((int) ($row['identity_id'] ?? 0), $identityIds, true);
            $identityAuth = array_values(array_filter($this->rows['identity_auth'] ?? [], $byIdentity));
            $sessions = array_values(array_filter($this->rows['auth_session'] ?? [], $byIdentity));
            $factors = array_values(array_filter($this->rows['mfa_factor'] ?? [], $byIdentity));
            $sessionIds = array_map(static fn (array $row): int => (int) $row['id'], $sessions);
            $factorIds = array_map(static fn (array $row): int => (int) $row['id'], $factors);
            return [
                'identity_auth' => $identityAuth,
                'auth_session' => $sessions,
                'auth_refresh_token' => array_values(array_filter($this->rows['auth_refresh_token'] ?? [], static fn (array $row): bool => in_array((int) ($row['session_id'] ?? 0), $sessionIds, true))),
                'mfa_factor' => $factors,
                'mfa_recovery_code' => array_values(array_filter($this->rows['mfa_recovery_code'] ?? [], static fn (array $row): bool => in_array((int) ($row['factor_id'] ?? 0), $factorIds, true))),
            ];
        }

        public function oauthCasApiGovernanceArtifacts(array $oauthClientIds, array $casServiceIds, array $policyIds, int $applicationId, bool $lock): array
        {
            $this->operations[] = 'records:oauth_cas_api_governance:' . ($lock ? 'lock' : 'read');
            $byClient = static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId
                && in_array((int) ($row['client_id'] ?? 0), $oauthClientIds, true);
            $byService = static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId
                && in_array((int) ($row['cas_service_id'] ?? 0), $casServiceIds, true);
            $publishedVersionIds = array_values(array_filter(array_map(fn (int $id): int => (int) (($this->rows['policy'][$id]['published_version_id'] ?? 0)), $policyIds)));
            $byPolicy = static fn (array $row): bool => in_array((int) ($row['policy_id'] ?? 0), $policyIds, true)
                || in_array((int) ($row['id'] ?? 0), $publishedVersionIds, true);
            return [
                'oauth_authorization_request' => array_values(array_filter($this->rows['oauth_authorization_request'] ?? [], $byClient)),
                'authorization_code' => array_values(array_filter($this->rows['authorization_code'] ?? [], $byClient)),
                'oauth_consent' => array_values(array_filter($this->rows['oauth_consent'] ?? [], $byClient)),
                'oauth_grant' => array_values(array_filter($this->rows['oauth_grant'] ?? [], $byClient)),
                'oauth_token' => array_values(array_filter($this->rows['oauth_token'] ?? [], $byClient)),
                'cas_login_request' => array_values(array_filter($this->rows['cas_login_request'] ?? [], $byService)),
                'cas_ticket' => array_values(array_filter($this->rows['cas_ticket'] ?? [], $byService)),
                'policy_version' => array_values(array_filter($this->rows['policy_version'] ?? [], $byPolicy)),
            ];
        }

        public function oauthCasApiGovernanceUniverse(string $prefix, int $applicationId, int $resourceId, int $identityId, bool $lock): array
        {
            $this->operations[] = 'records:oauth_cas_api_governance_universe:' . ($lock ? 'lock' : 'read');
            $roots = [
                'oauth_client' => array_values(array_filter($this->rows['oauth_client'] ?? [], static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId && str_starts_with((string) ($row['code'] ?? ''), $prefix))),
                'cas_service' => array_values(array_filter($this->rows['cas_service'] ?? [], static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId && str_starts_with((string) ($row['name'] ?? ''), $prefix))),
                'api_resource' => array_values(array_filter($this->rows['api_resource'] ?? [], static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId && (int) ($row['resource_id'] ?? 0) === $resourceId && str_starts_with((string) ($row['code'] ?? ''), $prefix))),
                'policy' => array_values(array_filter($this->rows['policy'] ?? [], static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId && (int) ($row['resource_id'] ?? 0) === $resourceId && (int) ($row['identity_id'] ?? 0) === $identityId)),
            ];
            $apiIds = array_map(static fn (array $row): int => (int) $row['id'], $roots['api_resource']);
            $roots['api_route_binding'] = array_values(array_filter($this->rows['api_route_binding'] ?? [], static fn (array $row): bool => (int) ($row['application_id'] ?? 0) === $applicationId && in_array((int) ($row['api_resource_id'] ?? 0), $apiIds, true)));
            $artifact = $this->oauthCasApiGovernanceArtifacts(array_map(static fn (array $row): int => (int) $row['id'], $roots['oauth_client']), array_map(static fn (array $row): int => (int) $row['id'], $roots['cas_service']), array_map(static fn (array $row): int => (int) $row['id'], $roots['policy']), $applicationId, $lock);
            return $roots + $artifact;
        }

        public function detachPolicyVersions(array $policyVersionIds, array $policyIds, int $applicationId): void
        {
            $this->operations[] = 'detach:policy_version';
            foreach ($policyIds as $policyId) {
                $policy = $this->rows['policy'][$policyId] ?? null;
                $publishedVersionId = (int) ($policy['published_version_id'] ?? 0);
                $version = $publishedVersionId > 0 ? ($this->rows['policy_version'][$publishedVersionId] ?? null) : null;
                if ($policy === null || (int) ($policy['application_id'] ?? 0) !== $applicationId
                    || ($publishedVersionId > 0 && (!is_array($version)
                        || (int) ($version['policy_id'] ?? 0) !== $policyId
                        || (int) ($version['application_id'] ?? 0) !== $applicationId))) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: published policy version is outside the submitted policy/application scope', 400);
                }
            }
            foreach ($policyVersionIds as $id) {
                $version = $this->rows['policy_version'][$id] ?? null;
                if ($version === null
                    || (int) ($version['application_id'] ?? 0) !== $applicationId
                    || !in_array((int) ($version['policy_id'] ?? 0), $policyIds, true)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: policy version is outside the submitted application/policy scope', 400);
                }
            }
            foreach ($this->rows['policy'] ?? [] as $id => $policy) {
                if (in_array((int) ($policy['published_version_id'] ?? 0), $policyVersionIds, true)) {
                    if ((int) ($policy['application_id'] ?? 0) !== $applicationId || !in_array((int) $id, $policyIds, true)) {
                        throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: submitted policy version is referenced outside the submitted policy scope', 400);
                    }
                }
            }
            foreach ($this->rows['policy'] ?? [] as $id => $policy) {
                if ((int) ($policy['application_id'] ?? 0) === $applicationId
                    && in_array((int) $id, $policyIds, true)
                    && in_array((int) ($policy['published_version_id'] ?? 0), $policyVersionIds, true)) {
                    $this->rows['policy'][$id]['published_version_id'] = null;
                }
            }
            foreach ($policyVersionIds as $id) {
                if (isset($this->rows['policy_version'][$id])
                    && (int) ($this->rows['policy_version'][$id]['application_id'] ?? 0) === $applicationId
                    && in_array((int) ($this->rows['policy_version'][$id]['policy_id'] ?? 0), $policyIds, true)) {
                    $this->rows['policy_version'][$id]['rollback_of_version_id'] = null;
                }
            }
        }
    }

    function acceptanceFixtureAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    /** @param callable():mixed $operation */
    function acceptanceFixtureExpect(callable $operation, string $code): void
    {
        try {
            $operation();
        } catch (ApiException $exception) {
            acceptanceFixtureAssert(str_contains($exception->getMessage(), $code), "unexpected acceptance error: {$exception->getMessage()}");
            return;
        }
        acceptanceFixtureAssert(false, "expected {$code}");
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    function acceptanceFixtureRows(string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'organization' => [11 => ['id' => 11, 'code' => $prefix . 'org_11', 'status' => 1]],
            'application' => [22 => ['id' => 22, 'organization_id' => 11, 'code' => $prefix . 'app_22', 'status' => 1]],
            'environment' => [33 => ['id' => 33, 'application_id' => 22, 'code' => $prefix . 'env_33', 'status' => 1]],
            'identity' => [
                34 => ['id' => 34, 'application_id' => 22, 'code' => $prefix . 'identity_34', 'status' => 1, 'lifecycle_state' => 'active'],
                134 => ['id' => 134, 'application_id' => 22, 'code' => $prefix . 'login_134', 'status' => 1, 'lifecycle_state' => 'active'],
            ],
            'identity_auth' => [135 => ['id' => 135, 'application_id' => 22, 'identity_id' => 134, 'username' => $prefix . 'login_134', 'status' => 1]],
            'auth_session' => [
                136 => ['id' => 136, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
                140 => ['id' => 140, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
                141 => ['id' => 141, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
            ],
            'auth_refresh_token' => [
                137 => ['id' => 137, 'session_id' => 136, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
                142 => ['id' => 142, 'session_id' => 140, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
                143 => ['id' => 143, 'session_id' => 141, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'],
            ],
            'mfa_factor' => [138 => ['id' => 138, 'application_id' => 22, 'identity_id' => 134, 'name' => $prefix . 'factor_138', 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00']],
            'mfa_recovery_code' => [139 => ['id' => 139, 'application_id' => 22, 'identity_id' => 134, 'factor_id' => 138, 'status' => 2]],
            'identity_group' => [35 => ['id' => 35, 'application_id' => 22, 'code' => $prefix . 'group_35', 'status' => 1]],
            'identity_group_member' => [36 => ['id' => 36, 'identity_group_id' => 35, 'application_id' => 22, 'identity_id' => 34, 'status' => 1]],
            'identity_group_role' => [37 => ['id' => 37, 'identity_group_id' => 35, 'application_id' => 22, 'role_id' => 38, 'status' => 1]],
            'role' => [38 => ['id' => 38, 'application_id' => 22, 'code' => 'acceptance.role', 'status' => 1]],
            'resource' => [49 => ['id' => 49, 'application_id' => 22, 'code' => 'acceptance.resource', 'status' => 1]],
            'admin_application_grant' => [44 => ['id' => 44, 'application_id' => 22, 'status' => 1]],
            'workload_client' => [55 => ['id' => 55, 'environment_id' => 33, 'audience' => 'acceptance-audience', 'status' => 1]],
            'service' => [66 => ['id' => 66, 'code' => 'acceptance.service', 'status' => 1]],
            'service_action' => [77 => ['id' => 77, 'service_id' => 66, 'code' => 'acceptance.action', 'status' => 1]],
            'credential' => [88 => ['id' => 88, 'workload_client_id' => 55, 'name' => $prefix . 'credential', 'status' => 1]],
            'service_grant' => [99 => ['id' => 99, 'workload_client_id' => 55, 'service_action_id' => 77, 'status' => 1]],
            'service_invocation_operation' => [111 => ['id' => 111, 'request_id' => $prefix . 'chain4-allow', 'credential_id' => 88, 'grant_id' => 99, 'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 55]],
            'service_quota_bucket' => [112 => ['id' => 112, 'grant_id' => 99]],
            'webhook_endpoint' => [121 => ['id' => 121, 'application_id' => 22, 'code' => $prefix . 'webhook_121', 'status' => 1]],
            'webhook_delivery' => [122 => ['id' => 122, 'application_id' => 22, 'webhook_endpoint_id' => 121, 'event_id' => $prefix . 'chain7-fixture-event', 'event_type' => 'acceptance.fixture.event', 'status' => 4, 'attempt_count' => 2, 'locked_until' => null]],
            'oauth_client' => [151 => ['id' => 151, 'application_id' => 22, 'code' => $prefix . 'oauth-client', 'status' => 1]],
            'cas_service' => [152 => ['id' => 152, 'application_id' => 22, 'name' => $prefix . 'cas-service', 'status' => 1]],
            'api_resource' => [153 => ['id' => 153, 'application_id' => 22, 'resource_id' => 49, 'code' => $prefix . 'api-resource', 'status' => 1]],
            'api_route_binding' => [154 => ['id' => 154, 'application_id' => 22, 'api_resource_id' => 153, 'status' => 1]],
            'policy' => [
                50 => ['id' => 50, 'application_id' => 22, 'code' => 'acceptance.policy', 'status' => 1],
                155 => ['id' => 155, 'application_id' => 22, 'resource_id' => 49, 'identity_id' => 34, 'published_version_id' => 176, 'status' => 1],
            ],
            'oauth_authorization_request' => [161 => ['id' => 161, 'application_id' => 22, 'client_id' => 151]],
            'authorization_code' => [162 => ['id' => 162, 'application_id' => 22, 'client_id' => 151]],
            'oauth_consent' => [163 => ['id' => 163, 'application_id' => 22, 'client_id' => 151]],
            'oauth_grant' => [164 => ['id' => 164, 'application_id' => 22, 'client_id' => 151]],
            'oauth_token' => [165 => ['id' => 165, 'application_id' => 22, 'client_id' => 151]],
            'cas_login_request' => [166 => ['id' => 166, 'application_id' => 22, 'cas_service_id' => 152]],
            'cas_ticket' => [167 => ['id' => 167, 'application_id' => 22, 'cas_service_id' => 152]],
            'policy_version' => [176 => ['id' => 176, 'application_id' => 22, 'policy_id' => 155, 'rollback_of_version_id' => null]],
        ];
    }

    /** @return array<string,list<int>> */
    function acceptanceFixtureAudits(string $requestId): array
    {
        preg_match('/^(sand_iam_acceptance_[a-f0-9]{16}_)/', $requestId, $prefixMatch);
        $prefix = $prefixMatch[1] ?? ACCEPTANCE_FIXTURE_PREFIX;
        return [
            $requestId . '-org|organization.create|organization' => [11],
            $requestId . '-app|application.create|application' => [22],
            $requestId . '-env|environment.create|environment' => [33],
            $requestId . '-chain2-identity|identity.create|identity' => [34],
            $requestId . '-chain2-group|identity_group.create|identity_group' => [35],
            $requestId . '-chain2-member|identity_group.member_add|identity_group|34' => [35],
            $requestId . '-chain2-role|identity_group_role.grant|identity_group_role' => [37],
            $requestId . '-chain3-register|identity.register|identity' => [134],
            $requestId . '-chain3-login|identity.login|identity' => [134],
            $requestId . '-chain3-mfa-verify|identity.mfa_login|identity' => [134],
            $requestId . '-chain3-mfa-verify|identity.mfa_login_verify|mfa_challenge' => [901],
            $requestId . '-chain3-totp-start|identity.totp_start|mfa_factor' => [138],
            $requestId . '-chain3-totp-confirm|identity.totp_confirm|mfa_factor' => [138],
            $requestId . '-chain3-mfa-revoke|identity.mfa_revoke|mfa_factor' => [138],
            $requestId . '-chain3-session-revoke-136|identity.session_revoke|auth_session' => [136],
            $requestId . '-chain3-session-revoke-140|identity.session_revoke|auth_session' => [140],
            $requestId . '-chain3-session-revoke-141|identity.session_revoke|auth_session' => [141],
            $requestId . '-grant|admin_application_grant.create|admin_application_grant' => [44],
            $requestId . '-chain4-grant|service_grant.create|service_grant' => [99],
            $requestId . '-chain4-credential|credential.issue|credential' => [88],
            $prefix . 'chain4-allow|service.invoke.authorize|service_invocation_operation' => [111],
            $requestId . '-chain7-endpoint|webhook.create|webhook_endpoint' => [121],
            $requestId . '-chain7-delivery|webhook.delivery_enqueue|webhook_delivery' => [122],
            $requestId . '-chain7-retry|webhook.delivery_retry|webhook_delivery' => [122],
            $requestId . '-chain5-oauth-client|oauth_client.create|oauth_client' => [151],
            $requestId . '-chain5-cas-service|cas_service.create|cas_service' => [152],
            $requestId . '-chain5-api-resource|api_resource.create|api_resource' => [153],
            $requestId . '-chain5-route-binding|api_route_binding.create|api_route_binding' => [154],
            $requestId . '-chain5-policy|policy.create|policy' => [155],
            'worker-delivery-' . $requestId . '|webhook.delivery|webhook_delivery' => [122],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixturePayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'organization-application-environment',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'object_ids' => ['organization' => [11], 'application' => [22], 'environment' => [33]],
            'object_request_ids' => [
                'organization' => [$requestId . '-org'],
                'application' => [$requestId . '-app'],
                'environment' => [$requestId . '-env'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixtureChainFourPayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'workload-credential-invocation',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'environment_id' => 33,
            'workload_client_id' => 55,
            'service_id' => 66,
            'service_action_id' => 77,
            'object_ids' => ['credential' => [88], 'service_grant' => [99]],
            'object_request_ids' => [
                'credential' => [$requestId . '-chain4-credential'],
                'service_grant' => [$requestId . '-chain4-grant'],
            ],
            'invocation_request_ids' => [$prefix . 'chain4-allow'],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixtureChainTwoPayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'identity-group-role-policy',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'role_id' => 38,
            'object_ids' => [
                'identity' => [34],
                'identity_group' => [35],
                'identity_group_member' => [36],
                'identity_group_role' => [37],
            ],
            'object_request_ids' => [
                'identity' => [$requestId . '-chain2-identity'],
                'identity_group' => [$requestId . '-chain2-group'],
                'identity_group_member' => [$requestId . '-chain2-member'],
                'identity_group_role' => [$requestId . '-chain2-role'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixtureChainThreePayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'human-auth-session-mfa',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'object_ids' => ['identity' => [134], 'auth_session' => [136, 140, 141], 'mfa_factor' => [138]],
            'object_request_ids' => [
                'identity' => [$requestId . '-chain3-register'],
                'auth_session' => [$requestId . '-chain3-register', $requestId . '-chain3-login', $requestId . '-chain3-mfa-verify'],
                'mfa_factor' => [$requestId . '-chain3-totp-start'],
            ],
            'human_auth_action_request_ids' => [
                'mfa_confirm' => $requestId . '-chain3-totp-confirm',
                'mfa_login_verify' => $requestId . '-chain3-mfa-verify',
                'mfa_revoke' => $requestId . '-chain3-mfa-revoke',
                'session_revoke' => [$requestId . '-chain3-session-revoke-136', $requestId . '-chain3-session-revoke-140', $requestId . '-chain3-session-revoke-141'],
            ],
            'human_auth_session_actions' => ['identity.register', 'identity.login', 'identity.mfa_login'],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixtureChainSevenPayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'event-webhook-delivery',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'object_ids' => ['webhook_endpoint' => [121], 'webhook_delivery' => [122]],
            'object_request_ids' => [
                'webhook_endpoint' => [$requestId . '-chain7-endpoint'],
                'webhook_delivery' => [$requestId . '-chain7-delivery'],
            ],
            'delivery_retry_request_ids' => [$requestId . '-chain7-retry'],
        ];
    }

    /** @return array<string,mixed> */
    function acceptanceFixtureChainFivePayload(string $requestId, string $prefix = ACCEPTANCE_FIXTURE_PREFIX): array
    {
        return [
            'chain_id' => 'oauth-cas-api-governance',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => AcceptanceFixtureService::CONFIRMATION,
            'organization_id' => 11,
            'application_id' => 22,
            'environment_id' => 33,
            'resource_id' => 49,
            'identity_id' => 34,
            'object_ids' => [
                'oauth_client' => [151],
                'cas_service' => [152],
                'api_resource' => [153],
                'api_route_binding' => [154],
                'policy' => [155],
            ],
            'object_request_ids' => [
                'oauth_client' => [$requestId . '-chain5-oauth-client'],
                'cas_service' => [$requestId . '-chain5-cas-service'],
                'api_resource' => [$requestId . '-chain5-api-resource'],
                'api_route_binding' => [$requestId . '-chain5-route-binding'],
                'policy' => [$requestId . '-chain5-policy'],
            ],
        ];
    }

    /** @return array{0:\Closure,1:\Closure,2:\ArrayObject<int,array<string,mixed>>} */
    function acceptanceFixtureDoubles(): array
    {
        $cache = [];
        $events = new \ArrayObject();
        $idempotent = static function (string $actor, string $requestId, string $fingerprint, callable $operation) use (&$cache): array {
            $key = $actor . '|' . $requestId;
            if (isset($cache[$key])) {
                if ($cache[$key]['fingerprint'] !== $fingerprint) throw new ApiException('SAND_IAM_IDEMPOTENCY_CONFLICT', 409);
                return ['result' => $cache[$key]['result'], 'replayed' => true];
            }
            $value = $operation();
            $cache[$key] = ['fingerprint' => $fingerprint, 'result' => $value['result']];
            return ['result' => $value['result'], 'replayed' => false];
        };
        $audit = static function (string $actor, string $requestId, string $action, string $outcome, array $context) use (&$events): void {
            $events[] = compact('actor', 'requestId', 'action', 'outcome', 'context');
        };
        return [$idempotent, $audit, $events];
    }

    $requestId = ACCEPTANCE_FIXTURE_PREFIX . 'chain1-cleanup';
    $store = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($requestId));
    [$idempotent, $audit, $events] = acceptanceFixtureDoubles();
    $service = new AcceptanceFixtureService($store, $idempotent, $audit);
    $payload = acceptanceFixturePayload($requestId);
    $first = $service->cleanup($payload, 1, $requestId);
    acceptanceFixtureAssert($first['replayed'] === false && array_sum($first['residual']) === 0, 'chain 1 cleanup did not reach zero residual');
    $chainOneStatus = $service->status($payload, $requestId);
    acceptanceFixtureAssert(array_sum($chainOneStatus['residual']) === 0 && $store->audits !== [], 'chain 1 cleanup removed audit evidence or status lost the exact creation binding');
    acceptanceFixtureAssert(
        array_values(array_filter($store->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'purge:'))) === [
            'revoke:environment', 'revoke:application', 'revoke:organization',
            'purge:environment', 'purge:application', 'purge:organization',
        ],
        'chain 1 cleanup did not revoke first and purge child before parent',
    );
    $emptyRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain1-empty';
    $emptyRows = acceptanceFixtureRows();
    foreach (['organization', 'application', 'environment', 'admin_application_grant'] as $type) $emptyRows[$type] = [];
    $emptyPayload = acceptanceFixturePayload($emptyRequest);
    $emptyPayload['contract_version'] = 2;
    $emptyPayload['creator_admin_id'] = 1;
    unset($emptyPayload['organization_id'], $emptyPayload['application_id']);
    $emptyPayload['object_ids'] = [];
    $emptyPayload['object_request_ids'] = [];
    $emptyStore = new AcceptanceFixtureMemoryStore($emptyRows, acceptanceFixtureAudits($emptyRequest));
    [$emptyIdempotent, $emptyAudit] = acceptanceFixtureDoubles();
    $emptyService = new AcceptanceFixtureService($emptyStore, $emptyIdempotent, $emptyAudit);
    $empty = $emptyService->cleanup($emptyPayload, 1, $emptyRequest);
    acceptanceFixtureAssert(array_sum($empty['residual']) === 0 && !in_array('revoke:organization', $emptyStore->operations, true), 'C01 empty closed stage was not a no-op after an empty universe check');
    $partialRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain1-org-only';
    $partialRows = acceptanceFixtureRows();
    unset($partialRows['application'][22], $partialRows['environment'][33], $partialRows['admin_application_grant'][44]);
    $partialPayload = acceptanceFixturePayload($partialRequest);
    $partialPayload['contract_version'] = 2;
    $partialPayload['creator_admin_id'] = 1;
    unset($partialPayload['application_id'], $partialPayload['object_ids']['application'], $partialPayload['object_ids']['environment'], $partialPayload['object_request_ids']['application'], $partialPayload['object_request_ids']['environment']);
    $partialStore = new AcceptanceFixtureMemoryStore($partialRows, acceptanceFixtureAudits($partialRequest));
    [$partialIdempotent, $partialAudit] = acceptanceFixtureDoubles();
    $partialService = new AcceptanceFixtureService($partialStore, $partialIdempotent, $partialAudit);
    $partial = $partialService->cleanup($partialPayload, 1, $partialRequest);
    acceptanceFixtureAssert(($partial['purged']['organization'] ?? 0) === 0
        && (($partial['retained']['organization'] ?? null) === [['id' => 11, 'status' => 2]])
        && array_sum($partial['residual']) === 0, 'C01 organization-only interrupted create did not retain its disabled audit anchor');
    $fullGrantRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain1-full-grant';
    $fullGrantRows = acceptanceFixtureRows();
    $fullGrantRows['admin_application_grant'][44]['admin_user_id'] = 77;
    $fullGrantAudits = acceptanceFixtureAudits($fullGrantRequest);
    $fullGrantAudits[$fullGrantRequest . '-chain1-grant-create|admin_application_grant.create|admin_application_grant'] = [44];
    $fullGrantPayload = acceptanceFixturePayload($fullGrantRequest);
    $fullGrantPayload['contract_version'] = 2;
    $fullGrantPayload['creator_admin_id'] = 1;
    $fullGrantPayload['scoped_admin_id'] = 77;
    $fullGrantPayload['object_ids']['admin_application_grant'] = [44];
    $fullGrantPayload['object_request_ids']['admin_application_grant'] = [$fullGrantRequest . '-chain1-grant-create'];
    $fullGrantStore = new AcceptanceFixtureMemoryStore($fullGrantRows, $fullGrantAudits);
    [$fullGrantIdempotent, $fullGrantAudit] = acceptanceFixtureDoubles();
    $fullGrantService = new AcceptanceFixtureService($fullGrantStore, $fullGrantIdempotent, $fullGrantAudit);
    $fullGrant = $fullGrantService->cleanup($fullGrantPayload, 1, $fullGrantRequest);
    acceptanceFixtureAssert(array_sum($fullGrant['residual']) === 0
        && (($fullGrant['retained'] ?? null) === ['organization' => [['id' => 11, 'status' => 2]], 'application' => [['id' => 22, 'status' => 2]]])
        && array_values(array_filter($fullGrantStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'purge:'))) === ['purge:admin_application_grant', 'purge:environment'], 'C01 full plus grant did not retain disabled audit anchors after removing grant and environment');

    $fullOmitGrant = $fullGrantPayload;
    unset($fullOmitGrant['scoped_admin_id'], $fullOmitGrant['object_ids']['admin_application_grant'], $fullOmitGrant['object_request_ids']['admin_application_grant']);
    $fullOmitGrantStore = new AcceptanceFixtureMemoryStore($fullGrantRows, $fullGrantAudits);
    [$fullOmitGrantIdempotent, $fullOmitGrantAudit] = acceptanceFixtureDoubles();
    $fullOmitGrantService = new AcceptanceFixtureService($fullOmitGrantStore, $fullOmitGrantIdempotent, $fullOmitGrantAudit);
    acceptanceFixtureExpect(static fn () => $fullOmitGrantService->cleanup($fullOmitGrant, 1, $fullGrantRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($fullOmitGrantStore->rows['environment'][33], $fullOmitGrantStore->rows['organization'][11]), 'C01 full stage omitted an actual grant and reached cleanup mutation');
    $wrongGrantActor = $fullGrantPayload;
    $wrongGrantActor['scoped_admin_id'] = 78;
    $wrongGrantStore = new AcceptanceFixtureMemoryStore($fullGrantRows, $fullGrantAudits);
    [$wrongGrantIdempotent, $wrongGrantAudit] = acceptanceFixtureDoubles();
    $wrongGrantService = new AcceptanceFixtureService($wrongGrantStore, $wrongGrantIdempotent, $wrongGrantAudit);
    acceptanceFixtureExpect(static fn () => $wrongGrantService->cleanup($wrongGrantActor, 1, $fullGrantRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($wrongGrantStore->rows['organization'][11]), 'C01 scoped-grant actor mismatch reached cleanup mutation');

    $wrongCreator = $fullGrantPayload;
    $wrongCreator['creator_admin_id'] = 2;
    $wrongCreatorStore = new AcceptanceFixtureMemoryStore($fullGrantRows, $fullGrantAudits);
    [$wrongCreatorIdempotent, $wrongCreatorAudit] = acceptanceFixtureDoubles();
    $wrongCreatorService = new AcceptanceFixtureService($wrongCreatorStore, $wrongCreatorIdempotent, $wrongCreatorAudit);
    acceptanceFixtureExpect(static fn () => $wrongCreatorService->cleanup($wrongCreator, 1, $fullGrantRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($wrongCreatorStore->rows['organization'][11]), 'C01 creator mismatch reached cleanup mutation');

    $wrongCreationAuditStore = new AcceptanceFixtureMemoryStore($fullGrantRows, $fullGrantAudits);
    $wrongCreationAuditStore->creationAuditActors[$fullGrantRequest . '-org|organization.create|organization|11'] = 2;
    [$wrongCreationAuditIdempotent, $wrongCreationAudit] = acceptanceFixtureDoubles();
    $wrongCreationAuditService = new AcceptanceFixtureService($wrongCreationAuditStore, $wrongCreationAuditIdempotent, $wrongCreationAudit);
    acceptanceFixtureExpect(static fn () => $wrongCreationAuditService->cleanup($fullGrantPayload, 1, $fullGrantRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($wrongCreationAuditStore->rows['organization'][11]), 'C01 creation-audit actor mismatch reached cleanup mutation');

    $extraDependentRows = $fullGrantRows;
    $extraDependentRows['environment'][45] = ['id' => 45, 'application_id' => 22, 'code' => 'ordinary_environment', 'status' => 1];
    $extraDependentStore = new AcceptanceFixtureMemoryStore($extraDependentRows, $fullGrantAudits);
    [$extraDependentIdempotent, $extraDependentAudit] = acceptanceFixtureDoubles();
    $extraDependentService = new AcceptanceFixtureService($extraDependentStore, $extraDependentIdempotent, $extraDependentAudit);
    acceptanceFixtureExpect(static fn () => $extraDependentService->cleanup($fullGrantPayload, 1, $fullGrantRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($extraDependentStore->rows['environment'][45], $extraDependentStore->rows['organization'][11]), 'C01 non-prefixed dependency reached cleanup mutation');

    $ordinaryChildRows = acceptanceFixtureRows();
    $ordinaryChildRows['application'][46] = ['id' => 46, 'organization_id' => 11, 'code' => 'ordinary_application', 'status' => 1];
    $ordinaryChildStore = new AcceptanceFixtureMemoryStore($ordinaryChildRows, acceptanceFixtureAudits($partialRequest));
    [$ordinaryChildIdempotent, $ordinaryChildAudit] = acceptanceFixtureDoubles();
    $ordinaryChildService = new AcceptanceFixtureService($ordinaryChildStore, $ordinaryChildIdempotent, $ordinaryChildAudit);
    acceptanceFixtureExpect(static fn () => $ordinaryChildService->cleanup($partialPayload, 1, $partialRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($ordinaryChildStore->rows['application'][46], $ordinaryChildStore->rows['organization'][11]), 'C01 ordinary-code child application reached cleanup mutation');
    $operationCount = count($store->operations);
    $replayed = $service->cleanup($payload, 1, $requestId);
    acceptanceFixtureAssert($replayed['replayed'] === true && count($store->operations) === $operationCount + 2, 'successful cleanup was not idempotently replayed without another purge');
    $fingerprintConflict = $payload;
    $fingerprintConflict['organization_id'] = 12;
    acceptanceFixtureExpect(static fn () => $service->cleanup($fingerprintConflict, 1, $requestId), 'SAND_IAM_IDEMPOTENCY_CONFLICT');
    $succeededEvents = array_values(array_filter($events->getArrayCopy(), static fn (array $event): bool => $event['outcome'] === 'succeeded'));
    acceptanceFixtureAssert(count($succeededEvents) === 1, 'successful cleanup audit was not recorded exactly once');
    $encodedContext = json_encode($events[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    acceptanceFixtureAssert(!str_contains($encodedContext, AcceptanceFixtureService::PREFIX) && !str_contains($encodedContext, AcceptanceFixtureService::CONFIRMATION), 'cleanup audit context leaked raw prefix or confirmation');

    foreach ([
        AcceptanceFixtureService::PREFIX,
        'sand_iam_acceptance_' . str_repeat('a', 15) . '_',
        'sand_iam_acceptance_' . str_repeat('a', 17) . '_',
        'sand_iam_acceptance_0123456789abcdeg_',
        'sand_iam_acceptance_0123456789ABCDEF_',
        'sand_iam_acceptance_0123456789abcdef_nested_',
    ] as $invalidPrefix) {
        $badPrefix = acceptanceFixturePayload(ACCEPTANCE_FIXTURE_PREFIX . 'prefix-invalid');
        $badPrefix['prefix'] = $invalidPrefix;
        $prefixStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits(ACCEPTANCE_FIXTURE_PREFIX . 'prefix-invalid'));
        [$prefixIdempotent, $prefixAudit] = acceptanceFixtureDoubles();
        $prefixService = new AcceptanceFixtureService($prefixStore, $prefixIdempotent, $prefixAudit);
        acceptanceFixtureExpect(static fn () => $prefixService->cleanup($badPrefix, 1, ACCEPTANCE_FIXTURE_PREFIX . 'prefix-invalid'), 'SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_INVALID');
        acceptanceFixtureAssert($prefixStore->operations === [], 'invalid prefix reached the transaction boundary');
    }

    $runAPrefix = 'sand_iam_acceptance_1111111111111111_';
    $runBPrefix = 'sand_iam_acceptance_2222222222222222_';
    $runARequest = $runAPrefix . 'cleanup';
    $runBRequest = $runBPrefix . 'cleanup';
    $runAStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows($runAPrefix), acceptanceFixtureAudits($runARequest));
    $runBStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows($runBPrefix), acceptanceFixtureAudits($runBRequest));
    [$isolatedIdempotent, $isolatedAudit] = acceptanceFixtureDoubles();
    $runAService = new AcceptanceFixtureService($runAStore, $isolatedIdempotent, $isolatedAudit);
    $runBService = new AcceptanceFixtureService($runBStore, $isolatedIdempotent, $isolatedAudit);
    $runA = $runAService->cleanup(acceptanceFixturePayload($runARequest, $runAPrefix), 1, $runARequest);
    $runB = $runBService->cleanup(acceptanceFixturePayload($runBRequest, $runBPrefix), 1, $runBRequest);
    acceptanceFixtureAssert($runA['replayed'] === false && $runB['replayed'] === false && array_sum($runA['residual']) === 0 && array_sum($runB['residual']) === 0, 'two unique fixture prefixes shared one idempotency key or cleanup scope');

    $foreignPrefixStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows($runAPrefix), acceptanceFixtureAudits($runBRequest));
    [$foreignPrefixIdempotent, $foreignPrefixAudit] = acceptanceFixtureDoubles();
    $foreignPrefixService = new AcceptanceFixtureService($foreignPrefixStore, $foreignPrefixIdempotent, $foreignPrefixAudit);
    acceptanceFixtureExpect(static fn () => $foreignPrefixService->cleanup(acceptanceFixturePayload($runBRequest, $runBPrefix), 1, $runBRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_INVALID');
    acceptanceFixtureAssert(isset($foreignPrefixStore->rows['organization'][11]), 'a different 16-character fixture token reached another token scope');

    $badRequestId = ACCEPTANCE_FIXTURE_PREFIX . 'request-prefix';
    $badRequestPrefix = acceptanceFixturePayload($badRequestId);
    $badRequestPrefix['object_request_ids']['organization'][0] = 'ordinary-request-20260828';
    $requestPrefixStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($badRequestId));
    [$requestPrefixIdempotent, $requestPrefixAudit] = acceptanceFixtureDoubles();
    $requestPrefixService = new AcceptanceFixtureService($requestPrefixStore, $requestPrefixIdempotent, $requestPrefixAudit);
    acceptanceFixtureExpect(static fn () => $requestPrefixService->cleanup($badRequestPrefix, 1, $badRequestId), 'SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID');
    acceptanceFixtureAssert($requestPrefixStore->operations === [], 'object request without the fixed prefix reached the transaction boundary');

    $badStatusRequest = acceptanceFixturePayload(ACCEPTANCE_FIXTURE_PREFIX . 'status');
    $badStatusRequest['request_id'] = 'ordinary-status-20260830';
    $statusPrefixStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits(ACCEPTANCE_FIXTURE_PREFIX . 'status'));
    [$statusPrefixIdempotent, $statusPrefixAudit] = acceptanceFixtureDoubles();
    $statusPrefixService = new AcceptanceFixtureService($statusPrefixStore, $statusPrefixIdempotent, $statusPrefixAudit);
    acceptanceFixtureExpect(static fn () => $statusPrefixService->status($badStatusRequest, 'ordinary-status-20260830'), 'SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID');
    acceptanceFixtureAssert($statusPrefixStore->operations === [], 'status request without the complete prefix reached the transaction boundary');

    $presetRequest = ACCEPTANCE_FIXTURE_PREFIX . 'preset';
    $presetStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), []);
    [$presetIdempotent, $presetAudit] = acceptanceFixtureDoubles();
    $presetService = new AcceptanceFixtureService($presetStore, $presetIdempotent, $presetAudit);
    acceptanceFixtureExpect(static fn () => $presetService->cleanup(acceptanceFixturePayload($presetRequest), 1, $presetRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($presetStore->rows['organization'][11]), 'preset object was changed despite missing current-run audit');

    $scopeRequest = ACCEPTANCE_FIXTURE_PREFIX . 'scope';
    $scopeRows = acceptanceFixtureRows();
    $scopeRows['application'][22]['organization_id'] = 999;
    $scopeStore = new AcceptanceFixtureMemoryStore($scopeRows, acceptanceFixtureAudits($scopeRequest));
    [$scopeIdempotent, $scopeAudit] = acceptanceFixtureDoubles();
    $scopeService = new AcceptanceFixtureService($scopeStore, $scopeIdempotent, $scopeAudit);
    acceptanceFixtureExpect(static fn () => $scopeService->cleanup(acceptanceFixturePayload($scopeRequest), 1, $scopeRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($scopeStore->rows['environment'][33]), 'ownership mismatch changed fixture rows');

    $failureRequest = ACCEPTANCE_FIXTURE_PREFIX . 'failure';
    $failureStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($failureRequest));
    $failureStore->failPurgeType = 'application';
    [$failureIdempotent, $failureAudit, $failureEvents] = acceptanceFixtureDoubles();
    $failureService = new AcceptanceFixtureService($failureStore, $failureIdempotent, $failureAudit);
    try {
        $failureService->cleanup(acceptanceFixturePayload($failureRequest), 1, $failureRequest);
        acceptanceFixtureAssert(false, 'forced transaction failure was accepted');
    } catch (\RuntimeException $exception) {
        acceptanceFixtureAssert($exception->getMessage() === 'forced purge failure', 'transaction failure changed the authoritative exception');
    }
    acceptanceFixtureAssert(isset($failureStore->rows['organization'][11], $failureStore->rows['application'][22], $failureStore->rows['environment'][33]), 'transaction failure did not restore all fixture rows');
    acceptanceFixtureAssert(end($failureStore->operations) === 'transaction:rollback', 'transaction failure did not roll back');
    acceptanceFixtureAssert(count($failureEvents) === 1 && $failureEvents[0]['outcome'] === 'failed', 'failed cleanup audit was not attempted after rollback');

    $delegationRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain6-cleanup';
    $delegationPayload = [
        'chain_id' => 'delegation-scope',
        'request_id' => $delegationRequest,
        'prefix' => ACCEPTANCE_FIXTURE_PREFIX,
        'confirmation' => AcceptanceFixtureService::CONFIRMATION,
        'organization_id' => 11,
        'application_id' => 22,
        'object_ids' => ['admin_application_grant' => [44]],
        'object_request_ids' => ['admin_application_grant' => [$delegationRequest . '-grant']],
    ];
    $delegationStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($delegationRequest));
    [$delegationIdempotent, $delegationAudit] = acceptanceFixtureDoubles();
    $delegationService = new AcceptanceFixtureService($delegationStore, $delegationIdempotent, $delegationAudit);
    $delegation = $delegationService->cleanup($delegationPayload, 1, $delegationRequest);
    acceptanceFixtureAssert($delegation['revoked']['admin_application_grant'] === 1 && $delegation['purged']['admin_application_grant'] === 1, 'chain 6 did not disable before purge');
    $status = $delegationService->status($delegationPayload, $delegationRequest);
    acceptanceFixtureAssert($status['residual']['admin_application_grant'] === 0, 'status did not prove exact delegation fixture zero residual');

    $chainTwoRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-cleanup';
    $chainTwoStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainTwoRequest));
    [$chainTwoIdempotent, $chainTwoAudit, $chainTwoEvents] = acceptanceFixtureDoubles();
    $chainTwoService = new AcceptanceFixtureService($chainTwoStore, $chainTwoIdempotent, $chainTwoAudit);
    $chainTwoPayload = acceptanceFixtureChainTwoPayload($chainTwoRequest);
    $chainTwo = $chainTwoService->cleanup($chainTwoPayload, 1, $chainTwoRequest);
    acceptanceFixtureAssert(
        $chainTwo['replayed'] === false
        && $chainTwo['revoked'] === ['identity_group_role' => 1, 'identity_group_member' => 1, 'identity_group' => 1, 'identity' => 1]
        && $chainTwo['purged'] === ['identity_group_role' => 1, 'identity_group_member' => 1, 'identity_group' => 1, 'identity' => 1]
        && array_sum($chainTwo['residual']) === 0,
        'chain 2 did not retire and physically remove only its captured relationship children before parents',
    );
    acceptanceFixtureAssert(
        array_values(array_filter($chainTwoStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'purge:'))) === [
            'revoke:identity_group_role', 'revoke:identity_group_member', 'revoke:identity_group', 'revoke:identity',
            'purge:identity_group_role', 'purge:identity_group_member', 'purge:identity_group', 'purge:identity',
        ],
        'chain 2 did not keep relation-child before group and identity physical cleanup order',
    );
    acceptanceFixtureAssert(isset($chainTwoStore->rows['organization'][11], $chainTwoStore->rows['application'][22], $chainTwoStore->rows['role'][38], $chainTwoStore->rows['resource'][49], $chainTwoStore->rows['policy'][50], $chainTwoStore->rows['service'][66], $chainTwoStore->rows['service_action'][77]), 'chain 2 touched pre-existing organization, application, role/resource/policy prerequisites or service records');
    $chainTwoAuditJson = json_encode($chainTwoEvents[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    acceptanceFixtureAssert(!str_contains($chainTwoAuditJson, ACCEPTANCE_FIXTURE_PREFIX) && !str_contains($chainTwoAuditJson, AcceptanceFixtureService::CONFIRMATION), 'chain 2 cleanup audit leaked its prefix or confirmation');
    $chainTwoStatus = $chainTwoService->status($chainTwoPayload, $chainTwoRequest);
    acceptanceFixtureAssert(array_sum($chainTwoStatus['residual']) === 0, 'chain 2 complete cleanup status did not accept already-purged member relationships as zero residual');
    $chainTwoReplayOperations = count($chainTwoStore->operations);
    $chainTwoReplay = $chainTwoService->cleanup($chainTwoPayload, 1, $chainTwoRequest);
    acceptanceFixtureAssert($chainTwoReplay['replayed'] === true && count($chainTwoStore->operations) === $chainTwoReplayOperations + 2, 'chain 2 cleanup replay did not remain idempotent');

    foreach (['extra member' => static function (array &$rows): void {
        $rows['identity'][40] = ['id' => 40, 'application_id' => 22, 'code' => ACCEPTANCE_FIXTURE_PREFIX . 'identity_40', 'status' => 1, 'lifecycle_state' => 'active'];
        $rows['identity_group_member'][41] = ['id' => 41, 'identity_group_id' => 35, 'application_id' => 22, 'identity_id' => 40, 'status' => 1];
    }, 'extra group role' => static function (array &$rows): void {
        $rows['identity_group_role'][42] = ['id' => 42, 'identity_group_id' => 35, 'application_id' => 22, 'role_id' => 38, 'status' => 1];
    }] as $label => $mutate) {
        $request = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-actual-' . str_replace(' ', '-', $label);
        $rows = acceptanceFixtureRows();
        $mutate($rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($request));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainTwoPayload($request), 1, $request), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert(isset($store->rows['identity_group_member'][36], $store->rows['identity_group_role'][37]), "chain 2 {$label} actual relationship scope reached the mutation boundary");
    }

    foreach ([
        'this-run group historical identity' => static function (array &$rows): void {
            $rows['identity'][40] = ['id' => 40, 'application_id' => 22, 'code' => 'historical-identity-40', 'status' => 1, 'lifecycle_state' => 'active'];
            $rows['identity_group_member'][41] = ['id' => 41, 'identity_group_id' => 35, 'application_id' => 22, 'identity_id' => 40, 'status' => 1];
        },
        'historical group this-run identity' => static function (array &$rows): void {
            $rows['identity_group'][40] = ['id' => 40, 'application_id' => 22, 'code' => 'historical-group-40', 'status' => 1];
            $rows['identity_group_member'][41] = ['id' => 41, 'identity_group_id' => 40, 'application_id' => 22, 'identity_id' => 34, 'status' => 1];
        },
    ] as $label => $mutate) {
        $request = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-' . str_replace(' ', '-', $label);
        $rows = acceptanceFixtureRows();
        $mutate($rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($request));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainTwoPayload($request), 1, $request), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert(isset($store->rows['identity_group_member'][36], $store->rows['identity_group_member'][41]) && end($store->operations) === 'transaction:rollback', "chain 2 {$label} was not rejected before mutation");
    }

    $chainTwoStatusExtraRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-status-extra-member';
    $chainTwoStatusExtraRows = acceptanceFixtureRows();
    $chainTwoStatusExtraRows['identity'][40] = ['id' => 40, 'application_id' => 22, 'code' => ACCEPTANCE_FIXTURE_PREFIX . 'identity_40', 'status' => 1, 'lifecycle_state' => 'active'];
    $chainTwoStatusExtraRows['identity_group_member'][41] = ['id' => 41, 'identity_group_id' => 35, 'application_id' => 22, 'identity_id' => 40, 'status' => 1];
    $chainTwoStatusExtraStore = new AcceptanceFixtureMemoryStore($chainTwoStatusExtraRows, acceptanceFixtureAudits($chainTwoStatusExtraRequest));
    [$chainTwoStatusExtraIdempotent, $chainTwoStatusExtraAudit] = acceptanceFixtureDoubles();
    $chainTwoStatusExtraService = new AcceptanceFixtureService($chainTwoStatusExtraStore, $chainTwoStatusExtraIdempotent, $chainTwoStatusExtraAudit);
    $chainTwoStatusExtra = $chainTwoStatusExtraService->status(acceptanceFixtureChainTwoPayload($chainTwoStatusExtraRequest), $chainTwoStatusExtraRequest);
    acceptanceFixtureAssert($chainTwoStatusExtra['residual']['identity_group_member'] === 2, 'chain 2 status incorrectly narrowed relationship residual to submitted member IDs');

    foreach (['missing role' => static function (array &$payload, array &$rows): void { $payload['role_id'] = 999; }, 'cross application role' => static function (array &$payload, array &$rows): void { $rows['role'][38]['application_id'] = 222; }] as $label => $mutate) {
        $request = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-' . str_replace(' ', '-', $label);
        $rows = acceptanceFixtureRows();
        $payload = acceptanceFixtureChainTwoPayload($request);
        $mutate($payload, $rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($request));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        acceptanceFixtureExpect(static fn () => $service->cleanup($payload, 1, $request), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert(isset($store->rows['role'][38], $store->rows['identity_group_role'][37]), "chain 2 {$label} touched the pre-existing role or relation");
    }

    $chainTwoPartialRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-partial-identity';
    $chainTwoPartialRows = acceptanceFixtureRows();
    unset($chainTwoPartialRows['identity_group'][35], $chainTwoPartialRows['identity_group_member'][36], $chainTwoPartialRows['identity_group_role'][37]);
    $chainTwoPartialStore = new AcceptanceFixtureMemoryStore($chainTwoPartialRows, acceptanceFixtureAudits($chainTwoPartialRequest));
    [$chainTwoPartialIdempotent, $chainTwoPartialAudit] = acceptanceFixtureDoubles();
    $chainTwoPartialService = new AcceptanceFixtureService($chainTwoPartialStore, $chainTwoPartialIdempotent, $chainTwoPartialAudit);
    $chainTwoPartialPayload = acceptanceFixtureChainTwoPayload($chainTwoPartialRequest);
    foreach (['identity_group', 'identity_group_member', 'identity_group_role'] as $type) {
        unset($chainTwoPartialPayload['object_ids'][$type], $chainTwoPartialPayload['object_request_ids'][$type]);
    }
    $chainTwoPartial = $chainTwoPartialService->cleanup($chainTwoPartialPayload, 1, $chainTwoPartialRequest);
    acceptanceFixtureAssert($chainTwoPartial['purged']['identity'] === 1 && array_sum($chainTwoPartial['residual']) === 0, 'chain 2 did not recover an identity-only partial creation safely');

    $chainTwoMemberPartialRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-partial-member';
    $chainTwoMemberPartialRows = acceptanceFixtureRows();
    unset($chainTwoMemberPartialRows['identity_group_role'][37]);
    $chainTwoMemberPartialStore = new AcceptanceFixtureMemoryStore($chainTwoMemberPartialRows, acceptanceFixtureAudits($chainTwoMemberPartialRequest));
    [$chainTwoMemberPartialIdempotent, $chainTwoMemberPartialAudit] = acceptanceFixtureDoubles();
    $chainTwoMemberPartialService = new AcceptanceFixtureService($chainTwoMemberPartialStore, $chainTwoMemberPartialIdempotent, $chainTwoMemberPartialAudit);
    $chainTwoMemberPartialPayload = acceptanceFixtureChainTwoPayload($chainTwoMemberPartialRequest);
    unset($chainTwoMemberPartialPayload['object_ids']['identity_group_role'], $chainTwoMemberPartialPayload['object_request_ids']['identity_group_role']);
    $chainTwoMemberPartial = $chainTwoMemberPartialService->cleanup($chainTwoMemberPartialPayload, 1, $chainTwoMemberPartialRequest);
    $chainTwoMemberPartialStatus = $chainTwoMemberPartialService->status($chainTwoMemberPartialPayload, $chainTwoMemberPartialRequest);
    acceptanceFixtureAssert($chainTwoMemberPartial['purged']['identity_group_member'] === 1 && array_sum($chainTwoMemberPartialStatus['residual']) === 0, 'chain 2 member partial cleanup status did not prove zero residual after relationship removal');

    $chainTwoGroupPartialRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-partial-group';
    $chainTwoGroupPartialRows = acceptanceFixtureRows();
    unset($chainTwoGroupPartialRows['identity_group_member'][36], $chainTwoGroupPartialRows['identity_group_role'][37]);
    $chainTwoGroupPartialStore = new AcceptanceFixtureMemoryStore($chainTwoGroupPartialRows, acceptanceFixtureAudits($chainTwoGroupPartialRequest));
    [$chainTwoGroupPartialIdempotent, $chainTwoGroupPartialAudit] = acceptanceFixtureDoubles();
    $chainTwoGroupPartialService = new AcceptanceFixtureService($chainTwoGroupPartialStore, $chainTwoGroupPartialIdempotent, $chainTwoGroupPartialAudit);
    $chainTwoGroupPartialPayload = acceptanceFixtureChainTwoPayload($chainTwoGroupPartialRequest);
    unset($chainTwoGroupPartialPayload['object_ids']['identity_group_member'], $chainTwoGroupPartialPayload['object_request_ids']['identity_group_member'], $chainTwoGroupPartialPayload['object_ids']['identity_group_role'], $chainTwoGroupPartialPayload['object_request_ids']['identity_group_role']);
    $chainTwoGroupPartial = $chainTwoGroupPartialService->cleanup($chainTwoGroupPartialPayload, 1, $chainTwoGroupPartialRequest);
    $chainTwoGroupPartialStatus = $chainTwoGroupPartialService->status($chainTwoGroupPartialPayload, $chainTwoGroupPartialRequest);
    acceptanceFixtureAssert($chainTwoGroupPartial['purged']['identity_group'] === 1 && array_sum($chainTwoGroupPartialStatus['residual']) === 0, 'chain 2 group partial cleanup status did not prove zero residual');

    $chainTwoExtraRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-extra-object';
    $chainTwoExtraRows = acceptanceFixtureRows();
    $chainTwoExtraRows['identity'][39] = ['id' => 39, 'application_id' => 22, 'code' => ACCEPTANCE_FIXTURE_PREFIX . 'identity-extra', 'status' => 1, 'lifecycle_state' => 'active'];
    $chainTwoExtraPayload = acceptanceFixtureChainTwoPayload($chainTwoExtraRequest);
    $chainTwoExtraPayload['object_ids']['identity'][] = 39;
    $chainTwoExtraPayload['object_request_ids']['identity'][] = $chainTwoExtraRequest . '-chain2-identity-extra';
    $chainTwoExtraStore = new AcceptanceFixtureMemoryStore($chainTwoExtraRows, acceptanceFixtureAudits($chainTwoExtraRequest));
    [$chainTwoExtraIdempotent, $chainTwoExtraAudit] = acceptanceFixtureDoubles();
    $chainTwoExtraService = new AcceptanceFixtureService($chainTwoExtraStore, $chainTwoExtraIdempotent, $chainTwoExtraAudit);
    acceptanceFixtureExpect(static fn () => $chainTwoExtraService->cleanup($chainTwoExtraPayload, 1, $chainTwoExtraRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainTwoExtraStore->rows['identity'][34], $chainTwoExtraStore->rows['identity'][39]), 'chain 2 extra identity without its exact creation audit reached the mutation boundary');

    $chainTwoDuplicateRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-duplicate-relation';
    $chainTwoDuplicateRows = acceptanceFixtureRows();
    $chainTwoDuplicateRows['identity_group_member'][38] = ['id' => 38, 'application_id' => 22, 'identity_group_id' => 35, 'identity_id' => 34, 'status' => 1];
    $chainTwoDuplicatePayload = acceptanceFixtureChainTwoPayload($chainTwoDuplicateRequest);
    $chainTwoDuplicatePayload['object_ids']['identity_group_member'][] = 38;
    $chainTwoDuplicatePayload['object_request_ids']['identity_group_member'][] = $chainTwoDuplicateRequest . '-chain2-member-duplicate';
    $chainTwoDuplicateStore = new AcceptanceFixtureMemoryStore($chainTwoDuplicateRows, acceptanceFixtureAudits($chainTwoDuplicateRequest));
    [$chainTwoDuplicateIdempotent, $chainTwoDuplicateAudit] = acceptanceFixtureDoubles();
    $chainTwoDuplicateService = new AcceptanceFixtureService($chainTwoDuplicateStore, $chainTwoDuplicateIdempotent, $chainTwoDuplicateAudit);
    acceptanceFixtureExpect(static fn () => $chainTwoDuplicateService->cleanup($chainTwoDuplicatePayload, 1, $chainTwoDuplicateRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($chainTwoDuplicateStore->rows['identity_group_member'][36], $chainTwoDuplicateStore->rows['identity_group_member'][38]), 'chain 2 duplicate group membership reached the mutation boundary');

    foreach ([
        'cross application member' => static function (array &$rows): void { $rows['identity_group_member'][36]['application_id'] = 222; },
        'wrong member identity' => static function (array &$rows): void { $rows['identity_group_member'][36]['identity_id'] = 999; },
        'wrong member group' => static function (array &$rows): void { $rows['identity_group_member'][36]['identity_group_id'] = 999; },
        'wrong role group' => static function (array &$rows): void { $rows['identity_group_role'][37]['identity_group_id'] = 999; },
    ] as $label => $mutate) {
        $request = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-' . str_replace(' ', '-', $label);
        $rows = acceptanceFixtureRows();
        $mutate($rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($request));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainTwoPayload($request), 1, $request), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert(isset($store->rows['identity'][34], $store->rows['identity_group'][35]), "chain 2 {$label} reached the mutation boundary");
    }

    $chainTwoHistoricalRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-historical-member';
    $chainTwoHistoricalStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), array_filter(acceptanceFixtureAudits($chainTwoHistoricalRequest), static fn (string $key): bool => !str_contains($key, 'identity_group.member_add'), ARRAY_FILTER_USE_KEY));
    [$chainTwoHistoricalIdempotent, $chainTwoHistoricalAudit] = acceptanceFixtureDoubles();
    $chainTwoHistoricalService = new AcceptanceFixtureService($chainTwoHistoricalStore, $chainTwoHistoricalIdempotent, $chainTwoHistoricalAudit);
    acceptanceFixtureExpect(static fn () => $chainTwoHistoricalService->cleanup(acceptanceFixtureChainTwoPayload($chainTwoHistoricalRequest), 1, $chainTwoHistoricalRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainTwoHistoricalStore->rows['identity_group_member'][36]), 'chain 2 historical relationship was changed without its exact member-add audit');

    $chainTwoFailureRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain2-failure';
    $chainTwoFailureStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainTwoFailureRequest));
    $chainTwoFailureStore->failPurgeType = 'identity_group';
    [$chainTwoFailureIdempotent, $chainTwoFailureAudit, $chainTwoFailureEvents] = acceptanceFixtureDoubles();
    $chainTwoFailureService = new AcceptanceFixtureService($chainTwoFailureStore, $chainTwoFailureIdempotent, $chainTwoFailureAudit);
    try {
        $chainTwoFailureService->cleanup(acceptanceFixtureChainTwoPayload($chainTwoFailureRequest), 1, $chainTwoFailureRequest);
        acceptanceFixtureAssert(false, 'chain 2 forced rollback was accepted');
    } catch (\RuntimeException $exception) {
        acceptanceFixtureAssert($exception->getMessage() === 'forced purge failure', 'chain 2 rollback changed the authoritative failure');
    }
    acceptanceFixtureAssert(isset($chainTwoFailureStore->rows['identity'][34], $chainTwoFailureStore->rows['identity_group'][35], $chainTwoFailureStore->rows['identity_group_member'][36], $chainTwoFailureStore->rows['identity_group_role'][37]) && $chainTwoFailureStore->rows['identity'][34]['status'] === 1, 'chain 2 failure did not roll back lifecycle and relation cleanup together');
    $chainTwoFailureStore->failPurgeType = null;
    $chainTwoRetry = $chainTwoFailureService->cleanup(acceptanceFixtureChainTwoPayload($chainTwoFailureRequest), 1, $chainTwoFailureRequest);
    acceptanceFixtureAssert($chainTwoRetry['replayed'] === false && array_sum($chainTwoRetry['residual']) === 0 && count($chainTwoFailureEvents) === 2, 'chain 2 failed cleanup could not retry with the same idempotency key');

    $chainFourRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-cleanup';
    $chainFourStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainFourRequest));
    [$chainFourIdempotent, $chainFourAudit, $chainFourEvents] = acceptanceFixtureDoubles();
    $chainFourService = new AcceptanceFixtureService($chainFourStore, $chainFourIdempotent, $chainFourAudit);
    $chainFourPayload = acceptanceFixtureChainFourPayload($chainFourRequest);
    $chainFour = $chainFourService->cleanup($chainFourPayload, 1, $chainFourRequest);
    acceptanceFixtureAssert(
        $chainFour['replayed'] === false
        && $chainFour['revoked']['credential'] === 1
        && $chainFour['revoked']['service_grant'] === 1
        && $chainFour['purged']['service_quota_bucket'] === 1
        && $chainFour['purged']['service_invocation_operation'] === 1
        && $chainFour['purged']['credential'] === 1
        && $chainFour['purged']['service_grant'] === 1
        && array_sum($chainFour['residual']) === 0,
        'chain 4 did not revoke then physically clean the exact credential and service grant',
    );
    acceptanceFixtureAssert(
        array_values(array_filter($chainFourStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'purge:'))) === [
            'revoke:credential', 'revoke:service_grant', 'purge:service_quota_bucket', 'purge:service_invocation_operation', 'purge:credential', 'purge:service_grant',
        ],
        'chain 4 did not preserve credential-before-grant child-first cleanup ordering',
    );
    acceptanceFixtureAssert(
        isset($chainFourStore->rows['organization'][11], $chainFourStore->rows['application'][22], $chainFourStore->rows['environment'][33], $chainFourStore->rows['workload_client'][55], $chainFourStore->rows['service'][66], $chainFourStore->rows['service_action'][77]),
        'chain 4 cleanup touched a pre-existing organization, application, environment, client, service or action',
    );
    $chainFourStatus = $chainFourService->status($chainFourPayload, $chainFourRequest);
    acceptanceFixtureAssert($chainFourStatus['residual'] === ['credential' => 0, 'service_grant' => 0, 'service_quota_bucket' => 0, 'service_invocation_operation' => 0], 'chain 4 status did not prove invocation, credential and grant cleanup all reached zero residual');
    $chainFourReplayOperations = count($chainFourStore->operations);
    $chainFourReplay = $chainFourService->cleanup($chainFourPayload, 1, $chainFourRequest);
    acceptanceFixtureAssert($chainFourReplay['replayed'] === true && count($chainFourStore->operations) === $chainFourReplayOperations + 2, 'chain 4 cleanup replay did not remain idempotent');
    $chainFourAuditJson = json_encode($chainFourEvents[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach ([AcceptanceFixtureService::PREFIX, AcceptanceFixtureService::CONFIRMATION, 'secret', 'secret_hash', 'token', 'key_prefix'] as $sensitive) {
        acceptanceFixtureAssert(!str_contains(strtolower($chainFourAuditJson), strtolower($sensitive)), 'chain 4 cleanup audit leaked a fixture prefix, confirmation or credential material');
    }

    $chainFourPresetRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-preset';
    $chainFourPresetStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), []);
    [$chainFourPresetIdempotent, $chainFourPresetAudit] = acceptanceFixtureDoubles();
    $chainFourPresetService = new AcceptanceFixtureService($chainFourPresetStore, $chainFourPresetIdempotent, $chainFourPresetAudit);
    acceptanceFixtureExpect(static fn () => $chainFourPresetService->cleanup(acceptanceFixtureChainFourPayload($chainFourPresetRequest), 1, $chainFourPresetRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainFourPresetStore->rows['credential'][88], $chainFourPresetStore->rows['service_grant'][99]), 'chain 4 pre-existing objects were changed without current-run creation audit');

    foreach ([
        'cross application' => static function (array &$rows): void { $rows['environment'][33]['application_id'] = 222; },
        'cross environment' => static function (array &$rows): void { $rows['workload_client'][55]['environment_id'] = 333; },
        'wrong client credential' => static function (array &$rows): void { $rows['credential'][88]['workload_client_id'] = 555; },
        'wrong client grant' => static function (array &$rows): void { $rows['service_grant'][99]['workload_client_id'] = 555; },
        'wrong service action' => static function (array &$rows): void { $rows['service_action'][77]['service_id'] = 666; },
        'wrong grant action' => static function (array &$rows): void { $rows['service_grant'][99]['service_action_id'] = 777; },
    ] as $label => $mutate) {
        $request = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-' . str_replace(' ', '-', $label);
        $rows = acceptanceFixtureRows();
        $mutate($rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($request));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainFourPayload($request), 1, $request), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert(isset($store->rows['credential'][88], $store->rows['service_grant'][99]), "chain 4 {$label} changed a fixture before scope validation");
    }

    $chainFourMissingRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-missing';
    $chainFourMissingStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainFourMissingRequest));
    [$chainFourMissingIdempotent, $chainFourMissingAudit] = acceptanceFixtureDoubles();
    $chainFourMissingService = new AcceptanceFixtureService($chainFourMissingStore, $chainFourMissingIdempotent, $chainFourMissingAudit);
    $chainFourMissingPayload = acceptanceFixtureChainFourPayload($chainFourMissingRequest);
    unset($chainFourMissingPayload['object_ids']['service_grant'], $chainFourMissingPayload['object_request_ids']['service_grant']);
    acceptanceFixtureExpect(static fn () => $chainFourMissingService->cleanup($chainFourMissingPayload, 1, $chainFourMissingRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID');
    acceptanceFixtureAssert($chainFourMissingStore->operations === [], 'chain 4 partial cleanup target reached the transaction boundary');

    $chainFourHistoricalRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-historical-operation';
    $chainFourHistoricalRows = acceptanceFixtureRows();
    $chainFourHistoricalRows['service_invocation_operation'][111]['request_id'] = ACCEPTANCE_FIXTURE_PREFIX . 'historical-allow';
    $chainFourHistoricalStore = new AcceptanceFixtureMemoryStore($chainFourHistoricalRows, acceptanceFixtureAudits($chainFourHistoricalRequest));
    [$chainFourHistoricalIdempotent, $chainFourHistoricalAudit] = acceptanceFixtureDoubles();
    $chainFourHistoricalService = new AcceptanceFixtureService($chainFourHistoricalStore, $chainFourHistoricalIdempotent, $chainFourHistoricalAudit);
    acceptanceFixtureExpect(static fn () => $chainFourHistoricalService->cleanup(acceptanceFixtureChainFourPayload($chainFourHistoricalRequest), 1, $chainFourHistoricalRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainFourHistoricalStore->rows['service_invocation_operation'][111], $chainFourHistoricalStore->rows['credential'][88], $chainFourHistoricalStore->rows['service_grant'][99]), 'historical invocation operation reached the cleanup mutation boundary');

    $chainFourExtraRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra-operation';
    $chainFourExtraRows = acceptanceFixtureRows();
    $chainFourExtraRows['service_invocation_operation'][113] = array_merge($chainFourExtraRows['service_invocation_operation'][111], ['id' => 113, 'request_id' => ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra']);
    $chainFourExtraAudits = acceptanceFixtureAudits($chainFourExtraRequest);
    $chainFourExtraAudits[ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra|service.invoke.authorize|service_invocation_operation'] = [113];
    $chainFourExtraStore = new AcceptanceFixtureMemoryStore($chainFourExtraRows, $chainFourExtraAudits);
    [$chainFourExtraIdempotent, $chainFourExtraAudit] = acceptanceFixtureDoubles();
    $chainFourExtraService = new AcceptanceFixtureService($chainFourExtraStore, $chainFourExtraIdempotent, $chainFourExtraAudit);
    acceptanceFixtureExpect(static fn () => $chainFourExtraService->cleanup(acceptanceFixtureChainFourPayload($chainFourExtraRequest), 1, $chainFourExtraRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_NOT_FOUND');
    acceptanceFixtureAssert(isset($chainFourExtraStore->rows['service_invocation_operation'][111], $chainFourExtraStore->rows['service_invocation_operation'][113], $chainFourExtraStore->rows['credential'][88], $chainFourExtraStore->rows['service_grant'][99]), 'an unsubmitted second invocation operation reached the cleanup mutation boundary');

    $chainFourDuplicateRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-duplicate-operation';
    $chainFourDuplicateRows = acceptanceFixtureRows();
    $chainFourDuplicateRows['service_invocation_operation'][113] = array_merge($chainFourDuplicateRows['service_invocation_operation'][111], ['id' => 113]);
    $chainFourDuplicateAudits = acceptanceFixtureAudits($chainFourDuplicateRequest);
    $chainFourDuplicateAudits[ACCEPTANCE_FIXTURE_PREFIX . 'chain4-allow|service.invoke.authorize|service_invocation_operation'] = [111, 113];
    $chainFourDuplicateStore = new AcceptanceFixtureMemoryStore($chainFourDuplicateRows, $chainFourDuplicateAudits);
    [$chainFourDuplicateIdempotent, $chainFourDuplicateAudit] = acceptanceFixtureDoubles();
    $chainFourDuplicateService = new AcceptanceFixtureService($chainFourDuplicateStore, $chainFourDuplicateIdempotent, $chainFourDuplicateAudit);
    acceptanceFixtureExpect(static fn () => $chainFourDuplicateService->cleanup(acceptanceFixtureChainFourPayload($chainFourDuplicateRequest), 1, $chainFourDuplicateRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainFourDuplicateStore->rows['service_invocation_operation'][111], $chainFourDuplicateStore->rows['service_invocation_operation'][113], $chainFourDuplicateStore->rows['credential'][88], $chainFourDuplicateStore->rows['service_grant'][99]), 'duplicate invocation request reached the cleanup mutation boundary');

    $chainFourUnorderedRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-unordered-operations';
    $chainFourUnorderedRows = acceptanceFixtureRows();
    $chainFourUnorderedRows['service_invocation_operation'][113] = array_merge($chainFourUnorderedRows['service_invocation_operation'][111], ['id' => 113, 'request_id' => ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra']);
    $chainFourUnorderedAudits = acceptanceFixtureAudits($chainFourUnorderedRequest);
    $chainFourUnorderedAudits[ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra|service.invoke.authorize|service_invocation_operation'] = [113];
    $chainFourUnorderedStore = new AcceptanceFixtureMemoryStore($chainFourUnorderedRows, $chainFourUnorderedAudits);
    [$chainFourUnorderedIdempotent, $chainFourUnorderedAudit] = acceptanceFixtureDoubles();
    $chainFourUnorderedService = new AcceptanceFixtureService($chainFourUnorderedStore, $chainFourUnorderedIdempotent, $chainFourUnorderedAudit);
    $chainFourUnorderedPayload = acceptanceFixtureChainFourPayload($chainFourUnorderedRequest);
    $chainFourUnorderedPayload['invocation_request_ids'] = [ACCEPTANCE_FIXTURE_PREFIX . 'chain4-extra', ACCEPTANCE_FIXTURE_PREFIX . 'chain4-allow'];
    $chainFourUnordered = $chainFourUnorderedService->cleanup($chainFourUnorderedPayload, 1, $chainFourUnorderedRequest);
    acceptanceFixtureAssert($chainFourUnordered['purged']['service_invocation_operation'] === 2 && array_sum($chainFourUnordered['residual']) === 0, 'operation collection equality incorrectly depended on request-id order');

    $chainFourGrantOnlyRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-grant-only';
    $chainFourGrantOnlyRows = acceptanceFixtureRows();
    unset($chainFourGrantOnlyRows['credential'][88], $chainFourGrantOnlyRows['service_invocation_operation'][111], $chainFourGrantOnlyRows['service_quota_bucket'][112]);
    $chainFourGrantOnlyStore = new AcceptanceFixtureMemoryStore($chainFourGrantOnlyRows, acceptanceFixtureAudits($chainFourGrantOnlyRequest));
    [$chainFourGrantOnlyIdempotent, $chainFourGrantOnlyAudit] = acceptanceFixtureDoubles();
    $chainFourGrantOnlyService = new AcceptanceFixtureService($chainFourGrantOnlyStore, $chainFourGrantOnlyIdempotent, $chainFourGrantOnlyAudit);
    $chainFourGrantOnlyPayload = acceptanceFixtureChainFourPayload($chainFourGrantOnlyRequest);
    unset($chainFourGrantOnlyPayload['object_ids']['credential'], $chainFourGrantOnlyPayload['object_request_ids']['credential']);
    $chainFourGrantOnlyPayload['invocation_request_ids'] = [];
    $chainFourGrantOnly = $chainFourGrantOnlyService->cleanup($chainFourGrantOnlyPayload, 1, $chainFourGrantOnlyRequest);
    acceptanceFixtureAssert($chainFourGrantOnly['purged']['service_grant'] === 1 && array_sum($chainFourGrantOnly['residual']) === 0, 'grant-only fallback did not recover the successfully created grant after credential issuance failed');

    $chainFourFailureRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain4-failure';
    $chainFourFailureStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainFourFailureRequest));
    $chainFourFailureStore->failPurgeType = 'service_grant';
    [$chainFourFailureIdempotent, $chainFourFailureAudit, $chainFourFailureEvents] = acceptanceFixtureDoubles();
    $chainFourFailureService = new AcceptanceFixtureService($chainFourFailureStore, $chainFourFailureIdempotent, $chainFourFailureAudit);
    try {
        $chainFourFailureService->cleanup(acceptanceFixtureChainFourPayload($chainFourFailureRequest), 1, $chainFourFailureRequest);
        acceptanceFixtureAssert(false, 'chain 4 forced rollback was accepted');
    } catch (\RuntimeException $exception) {
        acceptanceFixtureAssert($exception->getMessage() === 'forced purge failure', 'chain 4 rollback changed the authoritative failure');
    }
    acceptanceFixtureAssert(isset($chainFourFailureStore->rows['credential'][88], $chainFourFailureStore->rows['service_grant'][99], $chainFourFailureStore->rows['service_invocation_operation'][111], $chainFourFailureStore->rows['service_quota_bucket'][112]) && $chainFourFailureStore->rows['credential'][88]['status'] === 1 && $chainFourFailureStore->rows['service_grant'][99]['status'] === 1, 'chain 4 failure did not roll back invocation, revocation and physical cleanup together');
    acceptanceFixtureAssert(count($chainFourFailureEvents) === 1 && $chainFourFailureEvents[0]['action'] === 'acceptance_fixture.cleanup_failed' && $chainFourFailureEvents[0]['outcome'] === 'failed', 'chain 4 failed cleanup did not retain an independently keyed failure audit without credential material');
    $chainFourFailureStore->failPurgeType = null;
    $chainFourRetry = $chainFourFailureService->cleanup(acceptanceFixtureChainFourPayload($chainFourFailureRequest), 1, $chainFourFailureRequest);
    acceptanceFixtureAssert($chainFourRetry['replayed'] === false && array_sum($chainFourRetry['residual']) === 0 && count($chainFourFailureEvents) === 2 && $chainFourFailureEvents[1]['action'] === 'acceptance_fixture.cleanup', 'failed cleanup request could not retry successfully with the same idempotency key');

    $chainSevenRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain7-cleanup';
    $chainSevenStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainSevenRequest));
    [$chainSevenIdempotent, $chainSevenAudit, $chainSevenEvents] = acceptanceFixtureDoubles();
    $chainSevenService = new AcceptanceFixtureService($chainSevenStore, $chainSevenIdempotent, $chainSevenAudit);
    $chainSeven = $chainSevenService->cleanup(acceptanceFixtureChainSevenPayload($chainSevenRequest), 1, $chainSevenRequest);
    acceptanceFixtureAssert($chainSeven['purged']['webhook_delivery'] === 1 && $chainSeven['purged']['webhook_endpoint'] === 1 && array_sum($chainSeven['residual']) === 0, 'chain 7 did not physically clean delivery before endpoint');
    acceptanceFixtureAssert(
        array_values(array_filter($chainSevenStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'purge:'))) === ['revoke:webhook_endpoint', 'purge:webhook_delivery', 'purge:webhook_endpoint'],
        'chain 7 did not disable the endpoint before delivery and endpoint cleanup',
    );
    $chainSevenAuditJson = json_encode($chainSevenEvents[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    acceptanceFixtureAssert(!str_contains($chainSevenAuditJson, ACCEPTANCE_FIXTURE_PREFIX) && !str_contains($chainSevenAuditJson, AcceptanceFixtureService::CONFIRMATION), 'chain 7 cleanup audit leaked prefix or confirmation');

    $chainSevenExtraRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain7-extra-delivery';
    $chainSevenExtraRows = acceptanceFixtureRows();
    $chainSevenExtraRows['webhook_delivery'][123] = array_merge($chainSevenExtraRows['webhook_delivery'][122], ['id' => 123, 'event_id' => ACCEPTANCE_FIXTURE_PREFIX . 'chain7-extra']);
    $chainSevenExtraAudits = acceptanceFixtureAudits($chainSevenExtraRequest);
    $chainSevenExtraAudits[$chainSevenExtraRequest . '-chain7-extra-delivery|webhook.delivery_enqueue|webhook_delivery'] = [123];
    $chainSevenExtraStore = new AcceptanceFixtureMemoryStore($chainSevenExtraRows, $chainSevenExtraAudits);
    [$chainSevenExtraIdempotent, $chainSevenExtraAudit] = acceptanceFixtureDoubles();
    $chainSevenExtraService = new AcceptanceFixtureService($chainSevenExtraStore, $chainSevenExtraIdempotent, $chainSevenExtraAudit);
    acceptanceFixtureExpect(static fn () => $chainSevenExtraService->cleanup(acceptanceFixtureChainSevenPayload($chainSevenExtraRequest), 1, $chainSevenExtraRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($chainSevenExtraStore->rows['webhook_delivery'][122], $chainSevenExtraStore->rows['webhook_delivery'][123], $chainSevenExtraStore->rows['webhook_endpoint'][121]), 'chain 7 extra delivery reached cleanup mutation');

    $chainSevenHistoricalRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain7-historical-delivery';
    $chainSevenHistoricalRows = acceptanceFixtureRows();
    $chainSevenHistoricalRows['webhook_delivery'][123] = array_merge($chainSevenHistoricalRows['webhook_delivery'][122], ['id' => 123, 'event_id' => 'evt_historical_delivery_123']);
    $chainSevenHistoricalStore = new AcceptanceFixtureMemoryStore($chainSevenHistoricalRows, acceptanceFixtureAudits($chainSevenHistoricalRequest));
    [$chainSevenHistoricalIdempotent, $chainSevenHistoricalAudit] = acceptanceFixtureDoubles();
    $chainSevenHistoricalService = new AcceptanceFixtureService($chainSevenHistoricalStore, $chainSevenHistoricalIdempotent, $chainSevenHistoricalAudit);
    acceptanceFixtureExpect(static fn () => $chainSevenHistoricalService->cleanup(acceptanceFixtureChainSevenPayload($chainSevenHistoricalRequest), 1, $chainSevenHistoricalRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($chainSevenHistoricalStore->rows['webhook_delivery'][122], $chainSevenHistoricalStore->rows['webhook_delivery'][123], $chainSevenHistoricalStore->rows['webhook_endpoint'][121]), 'chain 7 historical delivery reached cleanup mutation');

    $chainSevenDrainRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain7-drain-required';
    $chainSevenDrainRows = acceptanceFixtureRows();
    $chainSevenDrainRows['webhook_delivery'][122]['status'] = 2;
    $chainSevenDrainRows['webhook_delivery'][122]['locked_until'] = '2099-01-01 00:00:00';
    $chainSevenDrainStore = new AcceptanceFixtureMemoryStore($chainSevenDrainRows, acceptanceFixtureAudits($chainSevenDrainRequest));
    [$chainSevenDrainIdempotent, $chainSevenDrainAudit, $chainSevenDrainEvents] = acceptanceFixtureDoubles();
    $chainSevenDrainService = new AcceptanceFixtureService($chainSevenDrainStore, $chainSevenDrainIdempotent, $chainSevenDrainAudit);
    acceptanceFixtureExpect(static fn () => $chainSevenDrainService->cleanup(acceptanceFixtureChainSevenPayload($chainSevenDrainRequest), 1, $chainSevenDrainRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED');
    acceptanceFixtureAssert($chainSevenDrainStore->rows['webhook_endpoint'][121]['status'] === 1 && isset($chainSevenDrainStore->rows['webhook_delivery'][122]), 'chain 7 drain rejection changed a potentially in-flight delivery');
    acceptanceFixtureAssert(count($chainSevenDrainEvents) === 1 && $chainSevenDrainEvents[0]['context']['failure_code'] === 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED', 'chain 7 drain rejection did not retain an accurate failure audit');

    $chainSevenEndpointOnlyRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain7-endpoint-only';
    $chainSevenEndpointOnlyRows = acceptanceFixtureRows();
    unset($chainSevenEndpointOnlyRows['webhook_delivery'][122]);
    $chainSevenEndpointOnlyStore = new AcceptanceFixtureMemoryStore($chainSevenEndpointOnlyRows, acceptanceFixtureAudits($chainSevenEndpointOnlyRequest));
    [$chainSevenEndpointOnlyIdempotent, $chainSevenEndpointOnlyAudit] = acceptanceFixtureDoubles();
    $chainSevenEndpointOnlyService = new AcceptanceFixtureService($chainSevenEndpointOnlyStore, $chainSevenEndpointOnlyIdempotent, $chainSevenEndpointOnlyAudit);
    $chainSevenEndpointOnlyPayload = acceptanceFixtureChainSevenPayload($chainSevenEndpointOnlyRequest);
    unset($chainSevenEndpointOnlyPayload['object_ids']['webhook_delivery'], $chainSevenEndpointOnlyPayload['object_request_ids']['webhook_delivery'], $chainSevenEndpointOnlyPayload['delivery_retry_request_ids']);
    $chainSevenEndpointOnly = $chainSevenEndpointOnlyService->cleanup($chainSevenEndpointOnlyPayload, 1, $chainSevenEndpointOnlyRequest);
    acceptanceFixtureAssert($chainSevenEndpointOnly['purged']['webhook_endpoint'] === 1 && array_sum($chainSevenEndpointOnly['residual']) === 0, 'chain 7 endpoint-only interrupted setup did not clean safely');

    $chainThreeRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-cleanup';
    $chainThreeStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainThreeRequest));
    [$chainThreeIdempotent, $chainThreeAudit, $chainThreeEvents] = acceptanceFixtureDoubles();
    $chainThreeService = new AcceptanceFixtureService($chainThreeStore, $chainThreeIdempotent, $chainThreeAudit);
    $chainThree = $chainThreeService->cleanup(acceptanceFixtureChainThreePayload($chainThreeRequest), 1, $chainThreeRequest);
    acceptanceFixtureAssert(
        ($chainThree['purged']['mfa_recovery_code'] ?? 0) === 1
        && ($chainThree['purged']['mfa_factor'] ?? 0) === 1
        && ($chainThree['purged']['auth_refresh_token'] ?? 0) === 3
        && ($chainThree['purged']['auth_session'] ?? 0) === 3
        && ($chainThree['purged']['identity_auth'] ?? 0) === 1
        && ($chainThree['purged']['identity'] ?? 0) === 1
        && array_sum($chainThree['residual']) === 0,
        'chain 3 did not clean every derived authentication artifact in foreign-key order',
    );
    acceptanceFixtureAssert(
        array_values(array_filter($chainThreeStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'purge:'))) === [
            'revoke:identity', 'purge:mfa_recovery_code', 'purge:mfa_factor', 'purge:auth_refresh_token', 'purge:auth_session', 'purge:identity_auth', 'purge:identity',
        ],
        'chain 3 cleanup did not revoke the identity and purge child authentication artifacts before the identity',
    );
    $chainThreeAuditJson = json_encode($chainThreeEvents[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['siam_at_', 'siam_rt_', 'otpauth://', 'password-value', 'secret-value'] as $sensitiveKey) {
        acceptanceFixtureAssert(!str_contains($chainThreeAuditJson, $sensitiveKey), 'chain 3 cleanup audit leaked authentication material');
    }

    $chainThreeMfaRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-mfa-three-session';
    $chainThreeMfaRows = acceptanceFixtureRows();
    $chainThreeMfaRows['auth_session'][140] = ['id' => 140, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'];
    $chainThreeMfaRows['auth_session'][141] = ['id' => 141, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'];
    $chainThreeMfaRows['auth_refresh_token'][142] = ['id' => 142, 'session_id' => 140, 'status' => 2];
    $chainThreeMfaRows['auth_refresh_token'][143] = ['id' => 143, 'session_id' => 141, 'status' => 2];
    $chainThreeMfaAudits = acceptanceFixtureAudits($chainThreeMfaRequest);
    $chainThreeMfaAudits[$chainThreeMfaRequest . '-chain3-register|identity.register|identity'] = [134];
    $chainThreeMfaAudits[$chainThreeMfaRequest . '-chain3-login|identity.login|identity'] = [134];
    $chainThreeMfaAudits[$chainThreeMfaRequest . '-chain3-mfa-verify|identity.mfa_login|identity'] = [134];
    $chainThreeMfaAudits[$chainThreeMfaRequest . '-chain3-mfa-verify|identity.mfa_login_verify|mfa_challenge'] = [901];
    foreach ([136, 140, 141] as $id) $chainThreeMfaAudits[$chainThreeMfaRequest . '-chain3-session-revoke-' . $id . '|identity.session_revoke|auth_session'] = [$id];
    $chainThreeMfaStore = new AcceptanceFixtureMemoryStore($chainThreeMfaRows, $chainThreeMfaAudits);
    $chainThreeMfaStore->mfaLoginChallengeAudits[] = [
        'application_id' => 22,
        'identity_id' => 134,
        'request_id' => $chainThreeMfaRequest . '-chain3-mfa-verify',
        'actor_ref' => '134',
        'challenge_id' => 901,
        'challenge_application_id' => 22,
        'challenge_identity_id' => 134,
        'purpose' => 'mfa_login',
        'outcome' => 'succeeded',
    ];
    [$chainThreeMfaIdempotent, $chainThreeMfaAudit] = acceptanceFixtureDoubles();
    $chainThreeMfaService = new AcceptanceFixtureService($chainThreeMfaStore, $chainThreeMfaIdempotent, $chainThreeMfaAudit);
    $chainThreeMfaPayload = acceptanceFixtureChainThreePayload($chainThreeMfaRequest);
    $chainThreeMfaPayload['object_ids']['auth_session'] = [136, 140, 141];
    $chainThreeMfaPayload['object_request_ids']['auth_session'] = [$chainThreeMfaRequest . '-chain3-register', $chainThreeMfaRequest . '-chain3-login', $chainThreeMfaRequest . '-chain3-mfa-verify'];
    $chainThreeMfaPayload['human_auth_session_actions'] = ['identity.register', 'identity.login', 'identity.mfa_login'];
    $chainThreeMfaPayload['human_auth_action_request_ids']['mfa_login_verify'] = $chainThreeMfaRequest . '-chain3-mfa-verify';
    $chainThreeMfaPayload['human_auth_action_request_ids']['session_revoke'] = [$chainThreeMfaRequest . '-chain3-session-revoke-136', $chainThreeMfaRequest . '-chain3-session-revoke-140', $chainThreeMfaRequest . '-chain3-session-revoke-141'];
    $chainThreeMfa = $chainThreeMfaService->cleanup($chainThreeMfaPayload, 1, $chainThreeMfaRequest);
    acceptanceFixtureAssert(($chainThreeMfa['purged']['auth_session'] ?? 0) === 3 && ($chainThreeMfa['purged']['auth_refresh_token'] ?? 0) === 3 && array_sum($chainThreeMfa['residual']) === 0, 'three-session MFA cleanup did not reach complete zero residual');
    $normalActionReuseStore = new AcceptanceFixtureMemoryStore($chainThreeMfaRows, $chainThreeMfaAudits);
    [$normalActionReuseIdempotent, $normalActionReuseAudit] = acceptanceFixtureDoubles();
    $normalActionReuseService = new AcceptanceFixtureService($normalActionReuseStore, $normalActionReuseIdempotent, $normalActionReuseAudit);
    $normalActionReusePayload = $chainThreeMfaPayload;
    $normalActionReusePayload['human_auth_action_request_ids']['mfa_confirm'] = $chainThreeMfaRequest . '-chain3-totp-start';
    acceptanceFixtureExpect(static fn () => $normalActionReuseService->cleanup($normalActionReusePayload, 1, $chainThreeMfaRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID');
    $wrongMfaSessionRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-mfa-session-reuse';
    $wrongMfaSessionRows = $chainThreeMfaRows;
    $wrongMfaAudits = acceptanceFixtureAudits($wrongMfaSessionRequest);
    $wrongMfaStore = new AcceptanceFixtureMemoryStore($wrongMfaSessionRows, $wrongMfaAudits);
    [$wrongMfaIdempotent, $wrongMfaAudit] = acceptanceFixtureDoubles();
    $wrongMfaService = new AcceptanceFixtureService($wrongMfaStore, $wrongMfaIdempotent, $wrongMfaAudit);
    $wrongMfaPayload = $chainThreeMfaPayload;
    $wrongMfaPayload['request_id'] = $wrongMfaSessionRequest;
    $wrongMfaPayload['object_request_ids']['auth_session'][2] = $wrongMfaSessionRequest . '-chain3-login';
    $wrongMfaPayload['human_auth_action_request_ids']['mfa_login_verify'] = $wrongMfaSessionRequest . '-chain3-mfa-verify';
    acceptanceFixtureExpect(static fn () => $wrongMfaService->cleanup($wrongMfaPayload, 1, $wrongMfaSessionRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID');

    $duplicateRegisterActionsStore = new AcceptanceFixtureMemoryStore($chainThreeMfaRows, $chainThreeMfaAudits);
    [$duplicateRegisterActionsIdempotent, $duplicateRegisterActionsAudit] = acceptanceFixtureDoubles();
    $duplicateRegisterActionsService = new AcceptanceFixtureService($duplicateRegisterActionsStore, $duplicateRegisterActionsIdempotent, $duplicateRegisterActionsAudit);
    $duplicateRegisterActionsPayload = $chainThreeMfaPayload;
    $duplicateRegisterActionsPayload['human_auth_session_actions'] = ['identity.register', 'identity.register', 'identity.mfa_login'];
    acceptanceFixtureExpect(static fn () => $duplicateRegisterActionsService->cleanup($duplicateRegisterActionsPayload, 1, $chainThreeMfaRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID');
    $fourSessionActionsStore = new AcceptanceFixtureMemoryStore($chainThreeMfaRows, $chainThreeMfaAudits);
    [$fourSessionActionsIdempotent, $fourSessionActionsAudit] = acceptanceFixtureDoubles();
    $fourSessionActionsService = new AcceptanceFixtureService($fourSessionActionsStore, $fourSessionActionsIdempotent, $fourSessionActionsAudit);
    $fourSessionActionsPayload = $chainThreeMfaPayload;
    $fourSessionActionsPayload['object_ids']['auth_session'][] = 144;
    $fourSessionActionsPayload['object_request_ids']['auth_session'][] = $chainThreeMfaRequest . '-chain3-login-extra';
    $fourSessionActionsPayload['human_auth_action_request_ids']['session_revoke'][] = $chainThreeMfaRequest . '-chain3-session-revoke-144';
    $fourSessionActionsPayload['human_auth_session_actions'][] = 'identity.login';
    acceptanceFixtureExpect(static fn () => $fourSessionActionsService->cleanup($fourSessionActionsPayload, 1, $chainThreeMfaRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID');

    foreach (['actor_ref', 'application_id', 'challenge_identity_id'] as $brokenField) {
        $brokenStore = new AcceptanceFixtureMemoryStore($chainThreeMfaRows, $chainThreeMfaAudits);
        $brokenAudit = $chainThreeMfaStore->mfaLoginChallengeAudits[0];
        $brokenAudit[$brokenField] = $brokenField === 'actor_ref' ? '999' : 999;
        $brokenStore->mfaLoginChallengeAudits[] = $brokenAudit;
        [$brokenIdempotent, $brokenCleanupAudit] = acceptanceFixtureDoubles();
        $brokenService = new AcceptanceFixtureService($brokenStore, $brokenIdempotent, $brokenCleanupAudit);
        acceptanceFixtureExpect(static fn () => $brokenService->cleanup($chainThreeMfaPayload, 1, $chainThreeMfaRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    }

    $chainThreeDrainRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-drain-required';
    $chainThreeDrainRows = acceptanceFixtureRows();
    $chainThreeDrainRows['auth_session'][136]['status'] = 1;
    $chainThreeDrainRows['auth_session'][136]['revoked_time'] = null;
    $chainThreeDrainStore = new AcceptanceFixtureMemoryStore($chainThreeDrainRows, acceptanceFixtureAudits($chainThreeDrainRequest));
    [$chainThreeDrainIdempotent, $chainThreeDrainAudit, $chainThreeDrainEvents] = acceptanceFixtureDoubles();
    $chainThreeDrainService = new AcceptanceFixtureService($chainThreeDrainStore, $chainThreeDrainIdempotent, $chainThreeDrainAudit);
    acceptanceFixtureExpect(static fn () => $chainThreeDrainService->cleanup(acceptanceFixtureChainThreePayload($chainThreeDrainRequest), 1, $chainThreeDrainRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED');
    acceptanceFixtureAssert(
        $chainThreeDrainStore->rows['identity'][134]['status'] === 1
        && $chainThreeDrainStore->rows['auth_session'][136]['status'] === 1
        && isset($chainThreeDrainStore->rows['mfa_factor'][138]),
        'chain 3 drain rejection changed an active session or another fixture',
    );
    $chainThreeDrainContext = $chainThreeDrainEvents[0]['context'] ?? [];
    acceptanceFixtureAssert(
        count($chainThreeDrainEvents) === 1
        && ($chainThreeDrainContext['failure_code'] ?? null) === 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_REQUIRED'
        && (($chainThreeDrainContext['drain_snapshot']['unchanged'] ?? null) === true)
        && (($chainThreeDrainContext['drain_snapshot']['before'] ?? null) === ($chainThreeDrainContext['drain_snapshot']['after'] ?? null)),
        'chain 3 drain rejection did not retain before/after unchanged snapshots in its failure audit',
    );
    foreach ($chainThreeDrainEvents as $event) {
        $serialized = strtolower(json_encode($event['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach ([ACCEPTANCE_FIXTURE_PREFIX, AcceptanceFixtureService::CONFIRMATION, 'siam_at_', 'siam_rt_', 'challenge-', 'otpauth:', 'password-value', 'secret-value', 'recovery-code'] as $forbidden) {
            acceptanceFixtureAssert(!str_contains($serialized, strtolower($forbidden)), 'chain 3 failure audit leaked a raw fixture or authentication secret');
        }
    }

    $chainThreeDrainChangedStore = new AcceptanceFixtureMemoryStore($chainThreeDrainRows, acceptanceFixtureAudits($chainThreeDrainRequest));
    $chainThreeDrainChangedStore->humanAuthArtifactsHook = static function (AcceptanceFixtureMemoryStore $store, int $call): void {
        if ($call !== 2) return;
        $store->rows['auth_session'][136]['status'] = 2;
        $store->rows['auth_session'][136]['revoked_time'] = '2026-08-30 00:00:00';
    };
    [$chainThreeDrainChangedIdempotent, $chainThreeDrainChangedAudit, $chainThreeDrainChangedEvents] = acceptanceFixtureDoubles();
    $chainThreeDrainChangedService = new AcceptanceFixtureService($chainThreeDrainChangedStore, $chainThreeDrainChangedIdempotent, $chainThreeDrainChangedAudit);
    acceptanceFixtureExpect(static fn () => $chainThreeDrainChangedService->cleanup(acceptanceFixtureChainThreePayload($chainThreeDrainRequest), 1, $chainThreeDrainRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_SNAPSHOT_CHANGED');
    acceptanceFixtureAssert((($chainThreeDrainChangedEvents[0]['context']['drain_snapshot']['unchanged'] ?? null) === false), 'chain 3 DRAIN difference injection did not prove before/after comparison is effective');

    $chainThreeDrainSwapRows = $chainThreeMfaRows;
    $chainThreeDrainSwapRows['auth_session'][136]['status'] = 1;
    $chainThreeDrainSwapRows['auth_session'][136]['revoked_time'] = null;
    $chainThreeDrainSwapRows['auth_session'][140]['status'] = 2;
    $chainThreeDrainSwapRows['auth_session'][140]['revoked_time'] = '2026-08-30 00:00:00';
    $chainThreeDrainSwapStore = new AcceptanceFixtureMemoryStore($chainThreeDrainSwapRows, $chainThreeMfaAudits);
    $chainThreeDrainSwapStore->mfaLoginChallengeAudits = $chainThreeMfaStore->mfaLoginChallengeAudits;
    $chainThreeDrainSwapStore->humanAuthArtifactsHook = static function (AcceptanceFixtureMemoryStore $store, int $call): void {
        if ($call !== 2) return;
        $store->rows['auth_session'][136]['status'] = 2;
        $store->rows['auth_session'][136]['revoked_time'] = '2026-08-30 00:01:00';
        $store->rows['auth_session'][140]['status'] = 1;
        $store->rows['auth_session'][140]['revoked_time'] = null;
    };
    [$chainThreeDrainSwapIdempotent, $chainThreeDrainSwapAudit, $chainThreeDrainSwapEvents] = acceptanceFixtureDoubles();
    $chainThreeDrainSwapService = new AcceptanceFixtureService($chainThreeDrainSwapStore, $chainThreeDrainSwapIdempotent, $chainThreeDrainSwapAudit);
    acceptanceFixtureExpect(static fn () => $chainThreeDrainSwapService->cleanup($chainThreeMfaPayload, 1, $chainThreeMfaRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_DRAIN_SNAPSHOT_CHANGED');
    acceptanceFixtureAssert((($chainThreeDrainSwapEvents[0]['context']['drain_snapshot']['unchanged'] ?? null) === false), 'chain 3 DRAIN per-ID snapshot did not detect exchanged session status and revocation fields');

    $auditSafety = new \ReflectionMethod(AcceptanceFixtureService::class, 'assertAuditContextSafe');
    $auditSafety->setAccessible(true);
    try {
        $auditSafety->invoke($chainThreeService, ['context' => ['details' => ['access_token' => 'siam_at_nested-leak']]], ACCEPTANCE_FIXTURE_PREFIX);
        acceptanceFixtureAssert(false, 'nested access-token leakage must be rejected from the full audit context tree');
    } catch (\ReflectionException $exception) {
        throw $exception;
    } catch (\Throwable $exception) {
        acceptanceFixtureAssert(str_contains($exception->getMessage(), '不得包含敏感'), 'nested access-token leakage was rejected for an unexpected reason');
    }
    try {
        $auditSafety->invoke($chainThreeService, ['drain_snapshot' => ['auth_session' => [136 => ['nested' => ['password' => 'password-value']]]]], ACCEPTANCE_FIXTURE_PREFIX);
        acceptanceFixtureAssert(false, 'nested password leakage inside drain snapshot must be rejected');
    } catch (\ReflectionException $exception) {
        throw $exception;
    } catch (\Throwable $exception) {
        acceptanceFixtureAssert(str_contains($exception->getMessage(), '不得包含敏感'), 'nested drain snapshot password was rejected for an unexpected reason');
    }
    try {
        $auditSafety->invoke($chainThreeService, ['matched' => ['auth_session' => ['nested' => ['password' => 'password-value']]]], ACCEPTANCE_FIXTURE_PREFIX);
        acceptanceFixtureAssert(false, 'nested password leakage inside matched must be rejected');
    } catch (\ReflectionException $exception) {
        throw $exception;
    } catch (\Throwable $exception) {
        acceptanceFixtureAssert(str_contains($exception->getMessage(), '不得包含敏感'), 'nested matched password was rejected for an unexpected reason');
    }
    foreach (['client_secret' => 'client-secret-value', 'pkce_verifier' => 'pkce-verifier', 'cas_ticket' => 'ST-secret-ticket', 'authorization_code' => 'authorization-code', 'csrf' => 'csrf-value', 'nonce' => 'nonce-value', 'access_token' => 'siam_at_nested-leak', 'refresh_token' => 'siam_rt_nested-leak'] as $field => $value) {
        try {
            $auditSafety->invoke($chainThreeService, ['matched' => ['nested' => [$field => $value]]], ACCEPTANCE_FIXTURE_PREFIX);
            acceptanceFixtureAssert(false, "nested {$field} leakage must be rejected as sensitive");
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            acceptanceFixtureAssert(str_contains($exception->getMessage(), '不得包含敏感'), "nested {$field} leakage was rejected for an unexpected reason");
        }
    }
    $auditSafety->invoke($chainThreeService, ['matched' => ['policy' => 1], 'residual' => ['policy_version' => 0]], ACCEPTANCE_FIXTURE_PREFIX);

    $chainThreeExtraRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-extra-session';
    $chainThreeExtraRows = acceptanceFixtureRows();
    $chainThreeExtraRows['auth_session'][144] = ['id' => 144, 'application_id' => 22, 'identity_id' => 134, 'status' => 2, 'revoked_time' => '2026-08-30 00:00:00'];
    $chainThreeExtraStore = new AcceptanceFixtureMemoryStore($chainThreeExtraRows, acceptanceFixtureAudits($chainThreeExtraRequest));
    [$chainThreeExtraIdempotent, $chainThreeExtraAudit] = acceptanceFixtureDoubles();
    $chainThreeExtraService = new AcceptanceFixtureService($chainThreeExtraStore, $chainThreeExtraIdempotent, $chainThreeExtraAudit);
    acceptanceFixtureExpect(static fn () => $chainThreeExtraService->cleanup(acceptanceFixtureChainThreePayload($chainThreeExtraRequest), 1, $chainThreeExtraRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainThreeExtraStore->rows['auth_session'][136], $chainThreeExtraStore->rows['auth_session'][144], $chainThreeExtraStore->rows['identity'][134]), 'chain 3 extra session reached cleanup mutation');

    $chainThreeCrossApplicationRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-cross-application';
    $chainThreeCrossApplicationRows = acceptanceFixtureRows();
    $chainThreeCrossApplicationRows['mfa_factor'][138]['application_id'] = 23;
    $chainThreeCrossApplicationStore = new AcceptanceFixtureMemoryStore($chainThreeCrossApplicationRows, acceptanceFixtureAudits($chainThreeCrossApplicationRequest));
    [$chainThreeCrossApplicationIdempotent, $chainThreeCrossApplicationAudit] = acceptanceFixtureDoubles();
    $chainThreeCrossApplicationService = new AcceptanceFixtureService($chainThreeCrossApplicationStore, $chainThreeCrossApplicationIdempotent, $chainThreeCrossApplicationAudit);
    acceptanceFixtureExpect(static fn () => $chainThreeCrossApplicationService->cleanup(acceptanceFixtureChainThreePayload($chainThreeCrossApplicationRequest), 1, $chainThreeCrossApplicationRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED');
    acceptanceFixtureAssert(isset($chainThreeCrossApplicationStore->rows['identity'][134], $chainThreeCrossApplicationStore->rows['mfa_factor'][138]), 'chain 3 cross-application factor reached cleanup mutation');

    $chainThreeFailureRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain3-partial-failure';
    $chainThreeFailureStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainThreeFailureRequest));
    $chainThreeFailureStore->failPurgeType = 'identity_auth';
    [$chainThreeFailureIdempotent, $chainThreeFailureAudit, $chainThreeFailureEvents] = acceptanceFixtureDoubles();
    $chainThreeFailureService = new AcceptanceFixtureService($chainThreeFailureStore, $chainThreeFailureIdempotent, $chainThreeFailureAudit);
    try {
        $chainThreeFailureService->cleanup(acceptanceFixtureChainThreePayload($chainThreeFailureRequest), 1, $chainThreeFailureRequest);
        acceptanceFixtureAssert(false, 'chain 3 partial cleanup failure was accepted');
    } catch (\RuntimeException $exception) {
        acceptanceFixtureAssert($exception->getMessage() === 'forced purge failure', 'chain 3 rollback changed the authoritative failure');
    }
    acceptanceFixtureAssert(isset($chainThreeFailureStore->rows['identity'][134], $chainThreeFailureStore->rows['identity_auth'][135], $chainThreeFailureStore->rows['auth_session'][136], $chainThreeFailureStore->rows['mfa_factor'][138]), 'chain 3 failure did not roll back every mutation');
    $chainThreeFailureStore->failPurgeType = null;
    $chainThreeRetry = $chainThreeFailureService->cleanup(acceptanceFixtureChainThreePayload($chainThreeFailureRequest), 1, $chainThreeFailureRequest);
    acceptanceFixtureAssert($chainThreeRetry['replayed'] === false && array_sum($chainThreeRetry['residual']) === 0, 'chain 3 failed cleanup could not retry with the same request id');

    $chainFiveRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-cleanup';
    $chainFiveStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainFiveRequest));
    [$chainFiveIdempotent, $chainFiveAudit, $chainFiveEvents] = acceptanceFixtureDoubles();
    $chainFiveService = new AcceptanceFixtureService($chainFiveStore, $chainFiveIdempotent, $chainFiveAudit);
    $chainFive = $chainFiveService->cleanup(acceptanceFixtureChainFivePayload($chainFiveRequest), 1, $chainFiveRequest);
    foreach (['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'] as $type) {
        acceptanceFixtureAssert(($chainFive['residual'][$type] ?? -1) === 0, "chain 5 did not reach zero residual for {$type}");
    }
    acceptanceFixtureAssert(
        array_values(array_filter($chainFiveStore->operations, static fn (string $operation): bool => str_starts_with($operation, 'revoke:') || str_starts_with($operation, 'detach:') || str_starts_with($operation, 'purge:'))) === [
            'revoke:oauth_client', 'revoke:cas_service', 'revoke:api_route_binding', 'revoke:api_resource', 'revoke:policy',
            'purge:oauth_token', 'purge:oauth_grant', 'purge:authorization_code', 'purge:oauth_authorization_request', 'purge:oauth_consent', 'purge:oauth_client',
            'purge:cas_ticket', 'purge:cas_login_request', 'purge:cas_service', 'purge:api_route_binding', 'purge:api_resource',
            'detach:policy_version', 'purge:policy_version', 'purge:policy',
        ],
        'chain 5 cleanup did not revoke and purge OAuth/CAS/API-governance fixtures in foreign-key order',
    );
    $chainFiveAuditJson = json_encode($chainFiveEvents[0]['context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['client_secret', 'siam_ac_', 'siam_at_', 'siam_rt_', 'csrf_token', 'nonce', 'ST-'] as $sensitive) {
        acceptanceFixtureAssert(!str_contains($chainFiveAuditJson, $sensitive), 'chain 5 cleanup audit leaked protocol material');
    }
    $chainFiveStatus = $chainFiveService->status(acceptanceFixtureChainFivePayload($chainFiveRequest), $chainFiveRequest);
    acceptanceFixtureAssert(array_sum($chainFiveStatus['residual']) === 0, 'chain 5 cleanup status did not preserve historical creation audit while reporting the empty fixture set');

    // These are operator-approved Chain5 prerequisites, not cleanup fixtures:
    // a failed validation must leave every one of the thirteen submitted and
    // derived OAuth/CAS/API-governance rows untouched.
    foreach ([
        'missing-application' => static function (array &$rows): void { unset($rows['application'][22]); },
        'disabled-application' => static function (array &$rows): void { $rows['application'][22]['status'] = 2; },
        'missing-environment' => static function (array &$rows): void { unset($rows['environment'][33]); },
        'wrong-application-environment' => static function (array &$rows): void { $rows['environment'][33]['application_id'] = 23; },
        'missing-resource' => static function (array &$rows): void { unset($rows['resource'][49]); },
        'wrong-application-resource' => static function (array &$rows): void { $rows['resource'][49]['application_id'] = 23; },
    ] as $label => $breakScope) {
        $requestId = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-' . $label;
        $rows = acceptanceFixtureRows();
        $breakScope($rows);
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($requestId));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        $before = $store->rows;
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainFivePayload($requestId), 1, $requestId), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert($store->rows === $before, "chain 5 {$label} prerequisite rejection changed OAuth/CAS/API fixture rows");
    }

    foreach ([
        'pv-cross' => ['application_id' => 23, 'policy_id' => 155],
        'pv-policy' => ['application_id' => 22, 'policy_id' => 999],
    ] as $label => $override) {
        $requestId = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-' . $label;
        $rows = acceptanceFixtureRows();
        $rows['policy_version'][176] = $override + $rows['policy_version'][176];
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($requestId));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        $before = $store->rows;
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainFivePayload($requestId), 1, $requestId), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
        acceptanceFixtureAssert($store->rows === $before, "chain 5 {$label} changed rows before policy-version scope rejection");
    }

    foreach ([
        'unsubmitted-policy' => ['id' => 156, 'application_id' => 22],
        'cross-application-policy' => ['id' => 157, 'application_id' => 23],
    ] as $label => $foreignPolicy) {
        $requestId = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-' . $label;
        $rows = acceptanceFixtureRows();
        $rows['policy'][$foreignPolicy['id']] = $foreignPolicy + ['resource_id' => 49, 'identity_id' => 34, 'published_version_id' => 176, 'status' => 1];
        $store = new AcceptanceFixtureMemoryStore($rows, acceptanceFixtureAudits($requestId));
        [$idempotent, $audit] = acceptanceFixtureDoubles();
        $service = new AcceptanceFixtureService($store, $idempotent, $audit);
        $before = $store->rows;
        acceptanceFixtureExpect(static fn () => $service->cleanup(acceptanceFixtureChainFivePayload($requestId), 1, $requestId), 'SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED');
        acceptanceFixtureAssert($store->rows === $before, "chain 5 {$label} policy-version reference changed fixture rows before rejection");
    }

    $chainFiveCrossApplicationRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-cross-application';
    $chainFiveCrossApplicationRows = acceptanceFixtureRows();
    $chainFiveCrossApplicationRows['oauth_client'][151]['application_id'] = 23;
    $chainFiveCrossApplicationStore = new AcceptanceFixtureMemoryStore($chainFiveCrossApplicationRows, acceptanceFixtureAudits($chainFiveCrossApplicationRequest));
    [$chainFiveCrossApplicationIdempotent, $chainFiveCrossApplicationAudit] = acceptanceFixtureDoubles();
    $chainFiveCrossApplicationService = new AcceptanceFixtureService($chainFiveCrossApplicationStore, $chainFiveCrossApplicationIdempotent, $chainFiveCrossApplicationAudit);
    acceptanceFixtureExpect(static fn () => $chainFiveCrossApplicationService->cleanup(acceptanceFixtureChainFivePayload($chainFiveCrossApplicationRequest), 1, $chainFiveCrossApplicationRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED');
    acceptanceFixtureAssert(isset($chainFiveCrossApplicationStore->rows['oauth_client'][151], $chainFiveCrossApplicationStore->rows['oauth_token'][165]), 'chain 5 cross-application fixture reached cleanup mutation');

    $chainFiveSamePrefixRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-same-prefix-extra';
    $chainFiveSamePrefixRows = acceptanceFixtureRows();
    $chainFiveSamePrefixRows['oauth_client'][158] = ['id' => 158, 'application_id' => 22, 'code' => ACCEPTANCE_FIXTURE_PREFIX . 'oauth-extra', 'status' => 1];
    $chainFiveSamePrefixStore = new AcceptanceFixtureMemoryStore($chainFiveSamePrefixRows, acceptanceFixtureAudits($chainFiveSamePrefixRequest));
    [$chainFiveSamePrefixIdempotent, $chainFiveSamePrefixAudit] = acceptanceFixtureDoubles();
    $chainFiveSamePrefixService = new AcceptanceFixtureService($chainFiveSamePrefixStore, $chainFiveSamePrefixIdempotent, $chainFiveSamePrefixAudit);
    $chainFiveSamePrefixBefore = $chainFiveSamePrefixStore->rows;
    acceptanceFixtureExpect(static fn () => $chainFiveSamePrefixService->cleanup(acceptanceFixtureChainFivePayload($chainFiveSamePrefixRequest), 1, $chainFiveSamePrefixRequest), 'SAND_IAM_ACCEPTANCE_FIXTURE_SET_DENIED');
    acceptanceFixtureAssert($chainFiveSamePrefixStore->rows === $chainFiveSamePrefixBefore, 'chain 5 same-prefix extra root changed rows before set rejection');
    $chainFiveSamePrefixStatus = $chainFiveSamePrefixService->status(acceptanceFixtureChainFivePayload($chainFiveSamePrefixRequest), $chainFiveSamePrefixRequest);
    acceptanceFixtureAssert(($chainFiveSamePrefixStatus['residual']['oauth_client'] ?? 0) === 2, 'chain 5 status did not report same-prefix extra OAuth client residual');

    $chainFiveExtraPolicyVersionRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-extra-policy-version';
    $chainFiveExtraPolicyVersionRows = acceptanceFixtureRows();
    $chainFiveExtraPolicyVersionRows['policy_version'][177] = ['id' => 177, 'application_id' => 22, 'policy_id' => 155, 'rollback_of_version_id' => 176];
    $chainFiveExtraPolicyVersionStore = new AcceptanceFixtureMemoryStore($chainFiveExtraPolicyVersionRows, acceptanceFixtureAudits($chainFiveExtraPolicyVersionRequest));
    [$chainFiveExtraPolicyVersionIdempotent, $chainFiveExtraPolicyVersionAudit] = acceptanceFixtureDoubles();
    $chainFiveExtraPolicyVersionService = new AcceptanceFixtureService($chainFiveExtraPolicyVersionStore, $chainFiveExtraPolicyVersionIdempotent, $chainFiveExtraPolicyVersionAudit);
    $chainFiveExtraPolicyVersion = $chainFiveExtraPolicyVersionService->cleanup(acceptanceFixtureChainFivePayload($chainFiveExtraPolicyVersionRequest), 1, $chainFiveExtraPolicyVersionRequest);
    acceptanceFixtureAssert(($chainFiveExtraPolicyVersion['purged']['policy_version'] ?? 0) === 2 && array_sum($chainFiveExtraPolicyVersion['residual']) === 0, 'chain 5 did not collect and clear the complete policy-version relation set');

    $chainFiveFailureRequest = ACCEPTANCE_FIXTURE_PREFIX . 'chain5-partial-failure';
    $chainFiveFailureStore = new AcceptanceFixtureMemoryStore(acceptanceFixtureRows(), acceptanceFixtureAudits($chainFiveFailureRequest));
    $chainFiveFailureStore->failPurgeType = 'oauth_client';
    [$chainFiveFailureIdempotent, $chainFiveFailureAudit, $chainFiveFailureEvents] = acceptanceFixtureDoubles();
    $chainFiveFailureService = new AcceptanceFixtureService($chainFiveFailureStore, $chainFiveFailureIdempotent, $chainFiveFailureAudit);
    try {
        $chainFiveFailureService->cleanup(acceptanceFixtureChainFivePayload($chainFiveFailureRequest), 1, $chainFiveFailureRequest);
        acceptanceFixtureAssert(false, 'chain 5 partial cleanup failure was accepted');
    } catch (\RuntimeException $exception) {
        acceptanceFixtureAssert($exception->getMessage() === 'forced purge failure', 'chain 5 rollback changed the authoritative failure');
    }
    acceptanceFixtureAssert(isset($chainFiveFailureStore->rows['oauth_client'][151], $chainFiveFailureStore->rows['oauth_token'][165], $chainFiveFailureStore->rows['cas_service'][152], $chainFiveFailureStore->rows['policy'][155], $chainFiveFailureStore->rows['policy_version'][176]), 'chain 5 partial failure did not roll back every fixture mutation');
    acceptanceFixtureAssert(($chainFiveFailureEvents[0]['outcome'] ?? null) === 'failed', 'chain 5 partial failure did not write the failed cleanup audit');

    echo 'acceptance fixture service non-PG checks passed' . PHP_EOL;
}

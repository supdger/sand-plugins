<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class DatabaseAcceptanceFixtureStore implements AcceptanceFixtureStore
{
    private const TABLES = [
        'organization' => 'sand_iam_organization',
        'application' => 'sand_iam_application',
        'application_experience' => 'sand_iam_application_experience',
        'environment' => 'sand_iam_environment',
        'resource' => 'sand_iam_resource',
        'application_business_action' => 'sand_iam_application_business_action',
        'identity' => 'sand_iam_identity',
        'auth_policy' => 'sand_iam_auth_policy',
        'identity_group' => 'sand_iam_identity_group',
        'identity_group_member' => 'sand_iam_identity_group_member',
        'identity_group_role' => 'sand_iam_identity_group_role',
        'sync_connector' => 'sand_iam_sync_connector',
        'sync_run' => 'sand_iam_sync_run',
        'sync_resource' => 'sand_iam_sync_resource',
        'directory_identity' => 'sand_iam_identity',
        'identity_invitation' => 'sand_iam_identity_invitation',
        'identity_import_job' => 'sand_iam_identity_import_job',
        'identity_import_row' => 'sand_iam_identity_import_row',
        'import_invitation' => 'sand_iam_identity_invitation',
        'identity_provider' => 'sand_iam_identity_provider',
        'identity_provider_application' => 'sand_iam_identity_provider_application',
        'scim_token' => 'sand_iam_scim_token',
        'scim_resource' => 'sand_iam_scim_resource',
        'scim_group' => 'sand_iam_scim_group',
        'scim_group_member' => 'sand_iam_scim_group_member',
        'identity_binding' => 'sand_iam_identity_binding',
        'provisioning_event' => 'sand_iam_provisioning_event',
        'scim_identity' => 'sand_iam_identity',
        'identity_auth' => 'sand_iam_identity_auth',
        'auth_verification' => 'sand_iam_auth_verification',
        'auth_challenge' => 'sand_iam_auth_challenge',
        'auth_session' => 'sand_iam_auth_session',
        'auth_refresh_token' => 'sand_iam_auth_refresh_token',
        'mfa_factor' => 'sand_iam_mfa_factor',
        'mfa_recovery_code' => 'sand_iam_mfa_recovery_code',
        'webauthn_credential' => 'sand_iam_webauthn_credential',
        'role' => 'sand_iam_role',
        'admin_organization_grant' => 'sand_iam_admin_organization_grant',
        'admin_application_grant' => 'sand_iam_admin_application_grant',
        'workload_client' => 'sand_iam_workload_client',
        'credential' => 'sand_iam_credential',
        'service' => 'sand_iam_service',
        'service_action' => 'sand_iam_service_action',
        'service_grant' => 'sand_iam_service_grant',
        'service_invocation_operation' => 'sand_iam_service_invocation_operation',
        'service_quota_bucket' => 'sand_iam_service_quota_bucket',
        'webhook_endpoint' => 'sand_iam_webhook_endpoint',
        'webhook_delivery' => 'sand_iam_webhook_delivery',
        'oauth_client' => 'sand_iam_oauth_client',
        'oauth_authorization_request' => 'sand_iam_oauth_authorization_request',
        'authorization_code' => 'sand_iam_authorization_code',
        'oauth_consent' => 'sand_iam_oauth_consent',
        'oauth_grant' => 'sand_iam_oauth_grant',
        'oauth_token' => 'sand_iam_oauth_token',
        'cas_service' => 'sand_iam_cas_service',
        'cas_login_request' => 'sand_iam_cas_login_request',
        'cas_ticket' => 'sand_iam_cas_ticket',
        'api_resource' => 'sand_iam_api_resource',
        'api_route_binding' => 'sand_iam_api_route_binding',
        'policy' => 'sand_iam_policy',
        'policy_version' => 'sand_iam_policy_version',
    ];

    public function transaction(callable $operation): mixed
    {
        Db::startTrans();
        try {
            $result = $operation();
            Db::commit();
            return $result;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public function records(string $type, array $ids, bool $lock, string $prefix): array
    {
        $query = $this->fixtureRows($type, $ids, $prefix);
        if ($lock) $query->lock(true);
        $rows = $query->select();
        $values = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : $rows;
        return is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
    }

    public function updateStatus(string $type, array $ids, int $status, string $prefix): int
    {
        $values = [
            'status' => $status,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if (in_array($type, ['credential', 'service_grant'], true) && $status === 2) {
            $values['revoked_time'] = date('Y-m-d H:i:s');
        }
        if ($type === 'identity' && $status === 2) {
            // The live plan deletes identities through their normal lifecycle.
            // This narrow fallback prevents an interrupted creation flow from
            // leaving a captured identity active before physical cleanup.
            $values += [
                'lifecycle_state' => 'deleted',
                'deleted_time' => date('Y-m-d H:i:s'),
                'purge_after' => date('Y-m-d H:i:s'),
            ];
        }
        return $this->fixtureRows($type, $ids, $prefix)->update($values);
    }

    public function purge(string $type, array $ids, string $prefix): int
    {
        return $this->fixtureRows($type, $ids, $prefix)->delete();
    }

    public function creationAuditIds(string $action, string $resourceType, string $requestId, array $ids, string $prefix): array
    {
        $values = Db::table('sand_iam_audit_log')
            ->where('action', $action)
            ->where('resource_type', $resourceType)
            ->where('request_id', $requestId)
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->where('outcome', 'succeeded')
            ->whereIn('resource_id', $ids)
            ->column('resource_id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($values) ? $values : [])));
        sort($result);
        return $result;
    }

    public function allCreationAuditIds(string $action, string $resourceType, string $requestId, string $prefix): array
    {
        $values = Db::table('sand_iam_audit_log')
            ->where('action', $action)
            ->where('resource_type', $resourceType)
            ->where('request_id', $requestId)
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->where('outcome', 'succeeded')
            ->column('resource_id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($values) ? $values : [])));
        sort($result);
        return $result;
    }

    public function creationAuditCreatedBy(string $action, string $resourceType, string $requestId, int $resourceId, string $prefix, int $adminId): bool
    {
        return Db::table('sand_iam_audit_log')
            ->where('action', $action)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('request_id', $requestId)
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->where('actor_type', 'admin')
            ->where('actor_ref', (string) $adminId)
            ->where('outcome', 'succeeded')
            ->count() > 0;
    }

    public function organizationApplicationEnvironmentUniverse(string $prefix, bool $lock): array
    {
        $organizationQuery = Db::table($this->table('organization'))
            ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($lock) $organizationQuery->lock(true);
        $roots = ['organization' => $this->queryRows($organizationQuery)];

        $organizationIds = $this->sortedIds($roots['organization']);
        $applicationQuery = Db::table($this->table('application'))
            ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($organizationIds !== []) $applicationQuery->whereOr('organization_id', 'in', $organizationIds);
        if ($lock) $applicationQuery->lock(true);
        $roots['application'] = $this->queryRows($applicationQuery);
        $applicationIds = $this->sortedIds($roots['application']);
        $experienceRows = [];
        if ($applicationIds !== []) {
            $query = Db::table($this->table('application_experience'))
                ->whereIn('application_id', $applicationIds);
            if ($lock) $query->lock(true);
            $experienceRows = $this->queryRows($query);
        }
        $environmentQuery = Db::table($this->table('environment'))
            ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($applicationIds !== []) $environmentQuery->whereOr('application_id', 'in', $applicationIds);
        if ($lock) $environmentQuery->lock(true);
        $roots['environment'] = $this->queryRows($environmentQuery);
        $roots['application_experience'] = $experienceRows;
        $organizationGrants = [];
        if ($organizationIds !== []) {
            $query = Db::table($this->table('admin_organization_grant'))
                ->whereIn('organization_id', $organizationIds);
            if ($lock) $query->lock(true);
            $organizationGrants = $this->queryRows($query);
        }
        $grants = [];
        if ($applicationIds !== []) {
            $query = Db::table($this->table('admin_application_grant'))
                ->whereIn('application_id', $applicationIds);
            if ($lock) $query->lock(true);
            $grants = $this->queryRows($query);
        }

        return $roots + [
            'admin_organization_grant' => $organizationGrants,
            'admin_application_grant' => $grants,
        ];
    }

    public function mfaLoginChallengeAuditExists(int $applicationId, int $identityId, string $requestId, string $prefix): bool
    {
        return Db::table('sand_iam_audit_log')->alias('audit')
            ->join('sand_iam_auth_challenge challenge', 'challenge.id = audit.resource_id')
            ->where('audit.actor_type', 'application_user')
            ->where('audit.actor_ref', (string) $identityId)
            ->where('audit.application_id', $applicationId)
            ->where('audit.resource_type', 'mfa_challenge')
            ->where('audit.request_id', $requestId)
            ->whereRaw('left(audit.request_id, ?) = ?', [strlen($prefix), $prefix])
            ->where('audit.outcome', 'succeeded')
            ->where('challenge.application_id', $applicationId)
            ->where('challenge.identity_id', $identityId)
            ->where('challenge.status', 2)
            ->where(static function ($query): void {
                $query
                    ->where(static function ($passwordMfa): void {
                        $passwordMfa
                            ->where('audit.action', 'identity.mfa_login_verify')
                            ->where('challenge.purpose', 'mfa_login');
                    })
                    ->whereOr(static function ($passkey): void {
                        $passkey
                            ->where('audit.action', 'identity.passkey_auth_finish')
                            ->where('challenge.purpose', 'webauthn_auth');
                    });
            })
            ->count() > 0;
    }

    public function membershipCreationAuditIds(string $requestId, int $identityGroupId, int $identityId, string $prefix): array
    {
        $values = Db::table('sand_iam_audit_log')
            ->where('action', 'identity_group.member_add')
            ->where('resource_type', 'identity_group')
            ->where('resource_id', $identityGroupId)
            ->where('request_id', $requestId)
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->whereRaw("context->>'identity_id' = ?", [(string) $identityId])
            ->where('outcome', 'succeeded')
            ->column('resource_id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($values) ? $values : [])));
        sort($result);
        return $result;
    }

    public function identityGroupMembers(string $prefix, int $applicationId, bool $lock): array
    {
        $query = Db::table('sand_iam_identity_group_member')->alias('member')
            ->join('sand_iam_identity_group iam_group', 'iam_group.id = member.identity_group_id')
            ->join('sand_iam_identity iam_identity', 'iam_identity.id = member.identity_id')
            ->where('member.application_id', $applicationId)
            ->where('iam_group.application_id', $applicationId)
            ->where('iam_identity.application_id', $applicationId)
            ->whereRaw('(left(iam_group.code, ?) = ? OR left(iam_identity.code, ?) = ?)', [strlen($prefix), $prefix, strlen($prefix), $prefix])
            ->field('member.id, member.application_id, member.identity_group_id, member.identity_id, member.status');
        if ($lock) $query->lock(true);
        return $this->queryRows($query);
    }

    public function identityGroupRoles(string $prefix, int $applicationId, bool $lock): array
    {
        $query = Db::table('sand_iam_identity_group_role')->alias('group_role')
            ->join('sand_iam_identity_group iam_group', 'iam_group.id = group_role.identity_group_id')
            ->where('group_role.application_id', $applicationId)
            ->where('iam_group.application_id', $applicationId)
            ->whereRaw('left(iam_group.code, ?) = ?', [strlen($prefix), $prefix])
            ->field('group_role.id, group_role.application_id, group_role.identity_group_id, group_role.role_id, group_role.status');
        if ($lock) $query->lock(true);
        return $this->queryRows($query);
    }

    public function directorySyncArtifacts(array $connectorIds, int $applicationId, bool $lock): array
    {
        if ($connectorIds === []) {
            return ['sync_run' => [], 'sync_resource' => [], 'directory_identity' => []];
        }
        $runs = Db::table('sand_iam_sync_run')
            ->where('application_id', $applicationId)
            ->whereIn('sync_connector_id', $connectorIds);
        $resources = Db::table('sand_iam_sync_resource')
            ->where('application_id', $applicationId)
            ->whereIn('sync_connector_id', $connectorIds);
        if ($lock) {
            $runs->lock(true);
            $resources->lock(true);
        }
        $runRows = $this->queryRows($runs);
        $resourceRows = $this->queryRows($resources);
        $identityIds = $this->sortedIds(array_map(
            static fn (array $row): array => ['id' => (int) ($row['identity_id'] ?? 0)],
            $resourceRows,
        ));
        $identityRows = [];
        if ($identityIds !== []) {
            $identities = Db::table('sand_iam_identity')
                ->where('application_id', $applicationId)
                ->whereIn('id', $identityIds);
            if ($lock) $identities->lock(true);
            $identityRows = $this->queryRows($identities);
        }
        return [
            'sync_run' => $runRows,
            'sync_resource' => $resourceRows,
            'directory_identity' => $identityRows,
        ];
    }

    public function identityLifecycleArtifacts(array $importJobIds, int $applicationId, bool $lock): array
    {
        if ($importJobIds === []) return ['identity_import_row' => [], 'import_invitation' => []];
        $rows = Db::table('sand_iam_identity_import_row')
            ->where('application_id', $applicationId)
            ->whereIn('import_job_id', $importJobIds);
        if ($lock) $rows->lock(true);
        $rowRecords = $this->queryRows($rows);
        $invitationIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['result_invitation_id'] ?? 0),
            $rowRecords,
        ), static fn (int $id): bool => $id > 0)));
        sort($invitationIds);
        $invitations = [];
        if ($invitationIds !== []) {
            $query = Db::table('sand_iam_identity_invitation')
                ->where('application_id', $applicationId)
                ->whereIn('id', $invitationIds);
            if ($lock) $query->lock(true);
            $invitations = $this->queryRows($query);
        }
        return ['identity_import_row' => $rowRecords, 'import_invitation' => $invitations];
    }

    public function scimArtifacts(array $providerIds, int $applicationId, bool $lock): array
    {
        $empty = [
            'identity_provider_application' => [], 'scim_token' => [], 'scim_resource' => [],
            'scim_group' => [], 'scim_group_member' => [],
            'identity_binding' => [], 'provisioning_event' => [], 'scim_identity' => [],
        ];
        if ($providerIds === []) return $empty;
        $read = function (string $table) use ($providerIds, $applicationId, $lock): array {
            $query = Db::table($table)
                ->where('application_id', $applicationId)
                ->whereIn('identity_provider_id', $providerIds);
            if ($lock) $query->lock(true);
            return $this->queryRows($query);
        };
        $resources = $read('sand_iam_scim_resource');
        $bindings = $read('sand_iam_identity_binding');
        $groups = $read('sand_iam_scim_group');
        $groupIds = $this->sortedIds($groups);
        $groupMembers = [];
        if ($groupIds !== []) {
            $query = Db::table('sand_iam_scim_group_member')
                ->where('application_id', $applicationId)
                ->whereIn('group_id', $groupIds);
            if ($lock) $query->lock(true);
            $groupMembers = $this->queryRows($query);
        }
        $identityIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['identity_id'] ?? 0),
            $resources,
        ), static fn (int $id): bool => $id > 0)));
        $ldapProviderIds = array_map(
            'intval',
            Db::table('sand_iam_identity_provider')
                ->whereIn('id', $providerIds)
                ->where('provider_type', 'ldap')
                ->column('id'),
        );
        foreach ($bindings as $binding) {
            if (in_array((int) ($binding['identity_provider_id'] ?? 0), $ldapProviderIds, true)) {
                $identityIds[] = (int) ($binding['identity_id'] ?? 0);
            }
        }
        $identityIds = array_values(array_unique(array_filter($identityIds, static fn (int $id): bool => $id > 0)));
        sort($identityIds);
        $identities = [];
        if ($identityIds !== []) {
            $query = Db::table('sand_iam_identity')
                ->where('application_id', $applicationId)
                ->whereIn('id', $identityIds);
            if ($lock) $query->lock(true);
            $identities = $this->queryRows($query);
        }
        return [
            'identity_provider_application' => $read('sand_iam_identity_provider_application'),
            'scim_token' => $read('sand_iam_scim_token'),
            'scim_resource' => $resources,
            'scim_group' => $groups,
            'scim_group_member' => $groupMembers,
            'identity_binding' => $bindings,
            'provisioning_event' => $read('sand_iam_provisioning_event'),
            'scim_identity' => $identities,
        ];
    }

    public function invocationOperations(string $prefix, array $credentialIds, array $grantIds, array $scopeIds, bool $lock): array
    {
        $query = Db::table('sand_iam_service_invocation_operation')
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->whereIn('credential_id', $credentialIds)
            ->whereIn('grant_id', $grantIds)
            ->where('organization_id', $scopeIds['organization'])
            ->where('application_id', $scopeIds['application'])
            ->where('environment_id', $scopeIds['environment'])
            ->where('workload_client_id', $scopeIds['workload_client']);
        if ($lock) $query->lock(true);
        $rows = $query->select();
        $values = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : $rows;
        return is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
    }

    public function serviceQuotaBucketIds(array $grantIds, bool $lock): array
    {
        $query = Db::table('sand_iam_service_quota_bucket')->whereIn('grant_id', $grantIds);
        if ($lock) $query->lock(true);
        $values = $query->column('id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($values) ? $values : [])));
        sort($result);
        return $result;
    }

    public function webhookDeliveries(array $endpointIds, int $applicationId, bool $lock): array
    {
        $query = Db::table('sand_iam_webhook_delivery')
            ->whereIn('webhook_endpoint_id', $endpointIds)
            ->where('application_id', $applicationId);
        if ($lock) $query->lock(true);
        return $this->queryRows($query);
    }

    public function webhookDeliveryAuditIds(array $deliveryIds): array
    {
        $values = Db::table('sand_iam_audit_log')
            ->where('action', 'webhook.delivery')
            ->where('resource_type', 'webhook_delivery')
            ->whereIn('resource_id', $deliveryIds)
            ->column('resource_id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($values) ? $values : [])));
        sort($result);
        return $result;
    }

    public function humanAuthArtifacts(array $identityIds, int $applicationId, bool $lock): array
    {
        $identityAuth = Db::table('sand_iam_identity_auth')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $identityAuth->lock(true);
        $identityAuthRows = $this->queryRows($identityAuth);

        $sessions = Db::table('sand_iam_auth_session')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $sessions->lock(true);
        $sessionRows = $this->queryRows($sessions);
        $sessionIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $sessionRows), static fn (int $id): bool => $id > 0));

        $challenges = Db::table('sand_iam_auth_challenge')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $challenges->lock(true);
        $challengeRows = $this->queryRows($challenges);

        $verifications = Db::table('sand_iam_auth_verification')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $verifications->lock(true);
        $verificationRows = $this->queryRows($verifications);

        $factors = Db::table('sand_iam_mfa_factor')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $factors->lock(true);
        $factorRows = $this->queryRows($factors);
        $factorIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $factorRows), static fn (int $id): bool => $id > 0));

        $refreshRows = [];
        if ($sessionIds !== []) {
            $refreshTokens = Db::table('sand_iam_auth_refresh_token')->whereIn('session_id', $sessionIds);
            if ($lock) $refreshTokens->lock(true);
            $refreshRows = $this->queryRows($refreshTokens);
        }
        $recoveryRows = [];
        if ($factorIds !== []) {
            $recoveryCodes = Db::table('sand_iam_mfa_recovery_code')
                ->where('application_id', $applicationId)
                ->whereIn('factor_id', $factorIds);
            if ($lock) $recoveryCodes->lock(true);
            $recoveryRows = $this->queryRows($recoveryCodes);
        }
        $passkeys = Db::table('sand_iam_webauthn_credential')
            ->where('application_id', $applicationId)
            ->whereIn('identity_id', $identityIds);
        if ($lock) $passkeys->lock(true);
        $passkeyRows = $this->queryRows($passkeys);

        return [
            'identity_auth' => $identityAuthRows,
            'auth_verification' => $verificationRows,
            'auth_challenge' => $challengeRows,
            'auth_session' => $sessionRows,
            'auth_refresh_token' => $refreshRows,
            'mfa_factor' => $factorRows,
            'mfa_recovery_code' => $recoveryRows,
            'webauthn_credential' => $passkeyRows,
        ];
    }

    public function oauthCasApiGovernanceArtifacts(array $oauthClientIds, array $casServiceIds, array $policyIds, int $applicationId, bool $lock): array
    {
        $rowsForClient = function (string $table) use ($oauthClientIds, $applicationId, $lock): array {
            if ($oauthClientIds === []) return [];
            $query = Db::table($table)->where('application_id', $applicationId)->whereIn('client_id', $oauthClientIds);
            if ($lock) $query->lock(true);
            return $this->queryRows($query);
        };
        $rowsForService = function (string $table) use ($casServiceIds, $applicationId, $lock): array {
            if ($casServiceIds === []) return [];
            $query = Db::table($table)->where('application_id', $applicationId)->whereIn('cas_service_id', $casServiceIds);
            if ($lock) $query->lock(true);
            return $this->queryRows($query);
        };
        $policyVersions = [];
        if ($policyIds !== []) {
            // Do not filter by application before the caller validates it:
            // a corrupted cross-application version must be visible and stop
            // cleanup before an FK cascade can touch any root.
            $query = Db::table('sand_iam_policy_version')->whereIn('policy_id', $policyIds);
            if ($lock) $query->lock(true);
            $policyVersions = $this->queryRows($query);
            $policies = $this->queryRows(Db::table('sand_iam_policy')->whereIn('id', $policyIds)->lock(true));
            $publishedVersionIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['published_version_id'] ?? 0), $policies)));
            if ($publishedVersionIds !== []) {
                $publishedQuery = Db::table('sand_iam_policy_version')->whereIn('id', $publishedVersionIds);
                if ($lock) $publishedQuery->lock(true);
                foreach ($this->queryRows($publishedQuery) as $row) $policyVersions[(int) ($row['id'] ?? 0)] = $row;
                $policyVersions = array_values($policyVersions);
            }
        }
        return [
            'oauth_authorization_request' => $rowsForClient('sand_iam_oauth_authorization_request'),
            'authorization_code' => $rowsForClient('sand_iam_authorization_code'),
            'oauth_consent' => $rowsForClient('sand_iam_oauth_consent'),
            'oauth_grant' => $rowsForClient('sand_iam_oauth_grant'),
            'oauth_token' => $rowsForClient('sand_iam_oauth_token'),
            'cas_login_request' => $rowsForService('sand_iam_cas_login_request'),
            'cas_ticket' => $rowsForService('sand_iam_cas_ticket'),
            'policy_version' => $policyVersions,
        ];
    }

    public function oauthCasApiGovernanceUniverse(string $prefix, int $applicationId, int $resourceId, int $identityId, bool $lock): array
    {
        $roots = [];
        foreach ([
            'oauth_client' => ['table' => 'sand_iam_oauth_client', 'field' => 'code'],
            'cas_service' => ['table' => 'sand_iam_cas_service', 'field' => 'name'],
            'api_resource' => ['table' => 'sand_iam_api_resource', 'field' => 'code'],
        ] as $type => $definition) {
            $query = Db::table($definition['table'])->where('application_id', $applicationId)->whereRaw('left(' . $definition['field'] . ', ?) = ?', [strlen($prefix), $prefix]);
            if ($type === 'api_resource') $query->where('resource_id', $resourceId);
            if ($lock) $query->lock(true);
            $roots[$type] = $this->queryRows($query);
        }
        $policyQuery = Db::table('sand_iam_policy')->where('application_id', $applicationId)->where('resource_id', $resourceId)->where('identity_id', $identityId);
        if ($lock) $policyQuery->lock(true);
        $roots['policy'] = $this->queryRows($policyQuery);
        $apiIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $roots['api_resource']);
        $routeQuery = Db::table('sand_iam_api_route_binding')->where('application_id', $applicationId);
        if ($apiIds === []) $roots['api_route_binding'] = []; else { $routeQuery->whereIn('api_resource_id', $apiIds); if ($lock) $routeQuery->lock(true); $roots['api_route_binding'] = $this->queryRows($routeQuery); }
        $artifact = $this->oauthCasApiGovernanceArtifacts(
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $roots['oauth_client']),
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $roots['cas_service']),
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $roots['policy']),
            $applicationId, $lock,
        );
        return $roots + $artifact;
    }

    public function nonAiBusinessConsumerUniverse(string $prefix, int $applicationId, int $identityId, bool $lock): array
    {
        $actions = Db::table('sand_iam_application_business_action')
            ->where('application_id', $applicationId)
            ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        $resources = Db::table('sand_iam_resource')
            ->where('application_id', $applicationId)
            ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($lock) {
            $actions->lock(true);
            $resources->lock(true);
        }
        $roots = [
            'application_business_action' => $this->queryRows($actions),
            'resource' => $this->queryRows($resources),
        ];
        $resourceIds = $this->sortedIds($roots['resource']);
        $apiResources = [];
        if ($resourceIds !== []) {
            $query = Db::table('sand_iam_api_resource')
                ->where('application_id', $applicationId)
                ->whereIn('resource_id', $resourceIds)
                ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
            if ($lock) $query->lock(true);
            $apiResources = $this->queryRows($query);
        }
        $roots['api_resource'] = $apiResources;
        $apiResourceIds = $this->sortedIds($apiResources);
        $routeBindings = [];
        if ($apiResourceIds !== []) {
            $query = Db::table('sand_iam_api_route_binding')
                ->where('application_id', $applicationId)
                ->whereIn('api_resource_id', $apiResourceIds);
            if ($lock) $query->lock(true);
            $routeBindings = $this->queryRows($query);
        }
        $roots['api_route_binding'] = $routeBindings;
        $policies = [];
        if ($resourceIds !== []) {
            $query = Db::table('sand_iam_policy')
                ->where('application_id', $applicationId)
                ->where('identity_id', $identityId)
                ->whereIn('resource_id', $resourceIds);
            if ($lock) $query->lock(true);
            $policies = $this->queryRows($query);
        }
        $roots['policy'] = $policies;
        $artifact = $this->oauthCasApiGovernanceArtifacts(
            [],
            [],
            $this->sortedIds($policies),
            $applicationId,
            $lock,
        );
        return $roots + ['policy_version' => $artifact['policy_version']];
    }

    public function detachPolicyVersions(array $policyVersionIds, array $policyIds, int $applicationId): void
    {
        if ($policyVersionIds === []) return;
        sort($policyVersionIds, SORT_NUMERIC);
        $policyVersionIds = array_values(array_unique($policyVersionIds));
        sort($policyIds, SORT_NUMERIC);
        $policyIds = array_values(array_unique($policyIds));
        if ($policyIds === []) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: 策略版本未绑定本轮提交策略，拒绝清理', 400);
        }
        $policies = $this->queryRows(Db::table('sand_iam_policy')->where('application_id', $applicationId)->whereIn('id', $policyIds)->lock(true));
        if (count($policies) !== count($policyIds)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 本轮策略不属于指定应用，拒绝清理', 400);
        }
        foreach ($policies as $policy) {
            $publishedVersionId = (int) ($policy['published_version_id'] ?? 0);
            if ($publishedVersionId === 0) continue;
            $published = $this->queryRows(Db::table('sand_iam_policy_version')->where('id', $publishedVersionId)->lock(true));
            $version = $published[0] ?? null;
            if (!is_array($version)
                || (int) ($version['policy_id'] ?? 0) !== (int) ($policy['id'] ?? 0)
                || (int) ($version['application_id'] ?? 0) !== (int) ($policy['application_id'] ?? 0)
                || (int) ($version['application_id'] ?? 0) !== $applicationId) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 策略 published_version 不属于本轮策略或应用，拒绝清理', 400);
            }
        }
        $versions = $this->queryRows(
            Db::table('sand_iam_policy_version')->whereIn('id', $policyVersionIds)->lock(true),
        );
        if (count($versions) !== count($policyVersionIds)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: 本轮策略版本不完整，拒绝清理', 400);
        }
        foreach ($versions as $version) {
            if ((int) ($version['application_id'] ?? 0) !== $applicationId
                || !in_array((int) ($version['policy_id'] ?? 0), $policyIds, true)) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: 策略版本不属于本轮应用或提交策略，拒绝清理', 400);
            }
        }
        $foreignPublication = $this->queryRows(
            Db::table('sand_iam_policy')
                ->field('id')
                ->whereIn('published_version_id', $policyVersionIds)
                ->whereNotIn('id', $policyIds)
                ->limit(1)
                ->lock(true),
        ) !== [];
        if ($foreignPublication) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED: 本轮策略版本仍被未提交或跨应用策略引用，拒绝清理', 400);
        }
        Db::table('sand_iam_policy')
            ->where('application_id', $applicationId)
            ->whereIn('id', $policyIds)
            ->whereIn('published_version_id', $policyVersionIds)
            ->update(['published_version_id' => null, 'update_time' => date('Y-m-d H:i:s')]);
        Db::table('sand_iam_policy_version')
            ->where('application_id', $applicationId)
            ->whereIn('policy_id', $policyIds)
            ->whereIn('id', $policyVersionIds)
            ->update(['rollback_of_version_id' => null]);
    }

    private function table(string $type): string
    {
        if (!isset(self::TABLES[$type])) throw new \LogicException('Unsupported acceptance fixture type');
        return self::TABLES[$type];
    }

    private function fixtureRows(string $type, array $ids, string $prefix): mixed
    {
        $query = Db::table($this->table($type))->whereIn('id', $ids);
        if ($prefix !== '' && in_array($type, ['organization', 'application', 'environment', 'identity', 'identity_group', 'resource', 'application_business_action'], true)) {
            $query->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        }
        if ($prefix !== '' && $type === 'webhook_endpoint') $query->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        // Production webhook event ids deliberately use the `evt_` namespace.
        // Delivery ownership is proved later from the exact endpoint,
        // application and credential event envelope, so a fixture-prefix
        // predicate here would hide the real row and make safe cleanup fail.
        if ($prefix !== '' && $type === 'credential') $query->whereRaw('left(name, ?) = ?', [strlen($prefix), $prefix]);
        if ($prefix !== '' && in_array($type, ['oauth_client', 'api_resource'], true)) $query->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($prefix !== '' && $type === 'sync_connector') $query->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($prefix !== '' && $type === 'identity_provider') $query->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix]);
        if ($prefix !== '' && $type === 'cas_service') $query->whereRaw('left(name, ?) = ?', [strlen($prefix), $prefix]);
        return $query;
    }

    /** @return list<array<string,mixed>> */
    private function queryRows(mixed $query): array
    {
        $rows = $query->select();
        $values = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : $rows;
        return is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private function sortedIds(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }
}

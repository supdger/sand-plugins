<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

interface AcceptanceFixtureStore
{
    /** @param callable():mixed $operation */
    public function transaction(callable $operation): mixed;

    /** @param list<int> $ids @return list<array<string,mixed>> */
    public function records(string $type, array $ids, bool $lock, string $prefix): array;

    /** @param list<int> $ids */
    public function updateStatus(string $type, array $ids, int $status, string $prefix): int;

    /** @param list<int> $ids */
    public function purge(string $type, array $ids, string $prefix): int;

    /** @param list<int> $ids @return list<int> */
    public function creationAuditIds(string $action, string $resourceType, string $requestId, array $ids, string $prefix): array;

    /**
     * Finds every successful creation audit for one declared fixture request.
     * Unlike creationAuditIds(), this deliberately does not accept submitted
     * ids: a duplicate root created under the same idempotency namespace must
     * stop cleanup rather than be silently left behind.
     *
     * @return list<int>
     */
    public function allCreationAuditIds(string $action, string $resourceType, string $requestId, string $prefix): array;

    /**
     * The MFA verification audit is only valid when its actor, application
     * and challenge resource all belong to the submitted acceptance identity.
     */
    public function mfaLoginChallengeAuditExists(int $applicationId, int $identityId, string $requestId, string $prefix): bool;

    /**
     * Membership audits use the group as their resource and carry the identity
     * in JSON context, so they need a relationship-specific ownership check.
     *
     * @return list<int> matching identity_group_id values
     */
    public function membershipCreationAuditIds(string $requestId, int $identityGroupId, int $identityId, string $prefix): array;

    /** @return list<array<string,mixed>> */
    public function identityGroupMembers(string $prefix, int $applicationId, bool $lock): array;

    /** @return list<array<string,mixed>> */
    public function identityGroupRoles(string $prefix, int $applicationId, bool $lock): array;

    /**
     * @param list<int> $credentialIds
     * @param list<int> $grantIds
     * @param array<string,int> $scopeIds
     * @return list<array<string,mixed>>
     */
    public function invocationOperations(string $prefix, array $credentialIds, array $grantIds, array $scopeIds, bool $lock): array;

    /** @param list<int> $grantIds @return list<int> */
    public function serviceQuotaBucketIds(array $grantIds, bool $lock): array;

    /**
     * Returns the complete same-run delivery set for the captured endpoints.
     * It deliberately does not accept submitted delivery ids: cleanup must
     * refuse an omitted or concurrently-created same-run delivery.
     *
     * @param list<int> $endpointIds
     * @return list<array<string,mixed>>
     */
    public function webhookDeliveries(array $endpointIds, int $applicationId, bool $lock): array;

    /** @param list<int> $deliveryIds @return list<int> */
    public function webhookDeliveryAuditIds(array $deliveryIds): array;

    /**
     * Returns every authentication artifact owned by the submitted, prefixed
     * identity in the supplied application. The caller intentionally supplies
     * identity ids, rather than session or factor ids, so an interrupted run
     * cannot hide an extra session, token or factor from cleanup.
     *
     * @param list<int> $identityIds
     * @return array<string,list<array<string,mixed>>>
     */
    public function humanAuthArtifacts(array $identityIds, int $applicationId, bool $lock): array;

    /**
     * Returns all protocol and immutable-policy artifacts owned by the
     * submitted OAuth clients, CAS services and policies. Callers intentionally
     * do not submit derived ids: an incomplete set must fail closed before a
     * cleanup can reach its mutation boundary.
     *
     * @param list<int> $oauthClientIds
     * @param list<int> $casServiceIds
     * @param list<int> $policyIds
     * @return array<string,list<array<string,mixed>>>
     */
    public function oauthCasApiGovernanceArtifacts(array $oauthClientIds, array $casServiceIds, array $policyIds, int $applicationId, bool $lock): array;

    /**
     * Returns the full same-application acceptance universe. Root discovery is
     * by controlled prefix and scope, never by the caller's creation request.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function oauthCasApiGovernanceUniverse(string $prefix, int $applicationId, int $resourceId, int $identityId, bool $lock): array;

    /**
     * Detaches only versions derived from this submitted policy set. The store
     * must reject a version referenced by another policy or application before
     * it writes anything, so cleanup cannot alter an unrelated publication.
     *
     * @param list<int> $policyVersionIds
     * @param list<int> $policyIds
     */
    public function detachPolicyVersions(array $policyVersionIds, array $policyIds, int $applicationId): void;
}

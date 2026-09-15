<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\SandIam\app\security\NetworkPolicy;
use plugin\SandIam\app\security\ServiceGrantConstraintNormalizer;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityContextProvider
{
    private const CONTEXT_TTL_SECONDS = 300;

    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter())
    {
    }

    /**
     * @param list<string> $requestedActions
     * @param array<string, mixed>|null $subjectScope
     * @return array{context:string,context_id:string,expire_time:string}
     */
    public function issue(
        string $workloadCredential,
        string $audience,
        array $requestedActions,
        ?array $subjectScope,
        string $requestId,
        string $sourceIp = '',
        string $serviceCode = '',
    ): array {
        $requestId = $this->requestId($requestId);
        $this->assertSigningKey();
        if ($audience === '' || $requestedActions === []) {
            $this->deny('credential', 'unknown', null, null, 'context.issue', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }

        $credential = $this->credential($workloadCredential, $requestId);
        $client = WorkloadClient::where('id', $credential->workload_client_id)->where('status', 1)->find();
        if ($client === null || (int) $credential->status !== 1 || $credential->revoked_time !== null || $this->expired($credential->expire_time)) {
            $this->deny('credential', (string) $credential->id, null, null, 'context.issue', $requestId, 'SAND_IAM_CREDENTIAL_REVOKED');
        }

        $reference = $this->environmentReference((int) $client->environment_id, 'workload_client', (string) $client->id, 'context.issue', $requestId);
        if (!hash_equals((string) $client->audience, $audience)) {
            $this->deny('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', $requestId, 'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH');
        }
        $actions = array_values(array_unique(array_filter($requestedActions, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        try {
            [$resolvedServiceCode, $grants] = $this->resolveServiceGrants((int) $client->id, $audience, $actions, $sourceIp, trim($serviceCode));
        } catch (ApiException $exception) {
            if ($exception->getMessage() !== 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN') throw $exception;
            $this->deny('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', $requestId, 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN');
        }
        try {
            $this->normalizeGrantConstraints($grants);
        } catch (ApiException) {
            $this->deny('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', $requestId, 'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID');
        }
        if ($actions === [] || count($grants) !== count($actions)) {
            $this->deny('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }

        $contextId = bin2hex(random_bytes(16));
        $expiresAt = time() + self::CONTEXT_TTL_SECONDS;
        $payload = [
            'context_id' => $contextId,
            'credential_id' => (int) $credential->id,
            'organization_id' => $reference['organization_id'],
            'application_id' => $reference['application_id'],
            'environment_id' => $reference['environment_id'],
            'workload_client_id' => (int) $client->id,
            'service_code' => $resolvedServiceCode,
            'audience' => $audience,
            'actions' => $actions,
            'grant_ids' => array_values(array_map(static fn (array $grant): int => (int) $grant['id'], $grants)),
            'subject_scope' => $subjectScope,
            'subject_scope_trust' => 'caller_asserted',
            'iat' => time(),
            'exp' => $expiresAt,
        ];
        $context = $this->sign($payload);
        $expireTime = date('Y-m-d H:i:s', $expiresAt);
        $this->auditWriter->write('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', 'identity_context', null, 'succeeded', $requestId, ['context_id' => $contextId, 'service_code' => $resolvedServiceCode, 'actions' => $actions, 'audience' => $audience]);
        return ['context' => $context, 'context_id' => $contextId, 'expire_time' => $expireTime];
    }

    /** @return array<string, mixed> */
    public function verify(string $context, string $expectedAudience, string $requiredAction, string $requestId = '', string $sourceIp = ''): array
    {
        return $this->verifyContext($context, '', $expectedAudience, $requiredAction, $sourceIp === '' ? null : $sourceIp, $requestId);
    }

    /** @return array<string, mixed> */
    public function verifyForService(string $context, string $expectedServiceCode, string $expectedAudience, string $requiredAction, string $trustedSourceIp, string $requestId = ''): array
    {
        if (!$this->validServiceCode($expectedServiceCode)) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: expected service is invalid', 403);
        if (inet_pton($trustedSourceIp) === false) throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        return $this->verifyContext($context, $expectedServiceCode, $expectedAudience, $requiredAction, $trustedSourceIp, $requestId);
    }

    /** @return array<string, mixed> */
    private function verifyContext(string $context, string $expectedServiceCode, string $expectedAudience, string $requiredAction, ?string $sourceIp, string $requestId): array
    {
        $requestId = RequestId::normalize($requestId);
        try {
            $payload = $this->verifySignature($context);
        } catch (ApiException $exception) {
            if ($exception->getCode() === 401) {
                $this->auditWriter->write('context', 'unknown', null, null, 'context.verify', 'identity_context', null, 'denied', $requestId, ['code' => 'SAND_IAM_AUTHENTICATION_FAILED']);
            }
            throw $exception;
        }
        if ((int) ($payload['exp'] ?? 0) <= time()) {
            $this->deny('context', (string) ($payload['context_id'] ?? 'unknown'), null, null, 'context.verify', $requestId, 'SAND_IAM_CONTEXT_EXPIRED');
        }
        if (($payload['audience'] ?? '') !== $expectedAudience) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH');
        }
        $payloadServiceCode = is_string($payload['service_code'] ?? null) ? (string) $payload['service_code'] : '';
        if (!$this->validServiceCode($payloadServiceCode) || ($expectedServiceCode !== '' && !hash_equals($expectedServiceCode, $payloadServiceCode))) {
            $this->deny('context', (string) ($payload['context_id'] ?? 'unknown'), null, null, 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $actions = $payload['actions'] ?? [];
        if (!is_array($actions) || !array_is_list($actions) || $actions === []) {
            $this->deny('context', (string) ($payload['context_id'] ?? 'unknown'), null, null, 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        foreach ($actions as $action) {
            if (!is_string($action) || $action === '') {
                $this->deny('context', (string) ($payload['context_id'] ?? 'unknown'), null, null, 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            }
        }
        if (count($actions) !== count(array_unique($actions)) || !in_array($requiredAction, $actions, true)) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $credential = Credential::where('id', (int) ($payload['credential_id'] ?? 0))->where('status', 1)->find();
        if ($credential === null || $credential->revoked_time !== null || $this->expired($credential->expire_time)) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CREDENTIAL_REVOKED');
        }
        $client = WorkloadClient::where('id', (int) ($payload['workload_client_id'] ?? 0))->where('status', 1)->find();
        if ($client === null || (int) $credential->workload_client_id !== (int) $client->id || !hash_equals((string) $client->audience, $expectedAudience)) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CREDENTIAL_REVOKED');
        }
        $reference = $this->environmentReference((int) $client->environment_id, 'context', (string) $payload['context_id'], 'context.verify', $requestId);
        if (
            (int) ($payload['organization_id'] ?? 0) !== $reference['organization_id']
            || (int) ($payload['application_id'] ?? 0) !== $reference['application_id']
            || (int) ($payload['environment_id'] ?? 0) !== $reference['environment_id']
        ) {
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        try {
            [, $grants] = is_array($actions) ? $this->resolveServiceGrants((int) $client->id, $expectedAudience, $actions, $sourceIp, $payloadServiceCode) : ['', []];
        } catch (ApiException $exception) {
            if ($exception->getMessage() !== 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN') throw $exception;
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN');
        }
        try {
            $actionGrants = $this->normalizeGrantConstraints($grants);
        } catch (ApiException) {
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID');
        }
        if (!is_array($actions) || count($grants) !== count($actions) || !$this->sameGrantIds($payload['grant_ids'] ?? null, $grants)) {
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $this->auditWriter->write('context', (string) $payload['context_id'], (int) $payload['organization_id'], (int) $payload['application_id'], 'context.verify', 'identity_context', null, 'allowed', $requestId, ['required_action' => $requiredAction, 'service_code' => $payloadServiceCode, 'audience' => $expectedAudience]);
        return array_replace($payload, ['action_grants' => $actionGrants]);
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,status:int} */
    private function environmentReference(int $environmentId, string $actorType, string $actorRef, string $action, string $requestId): array
    {
        try {
            return (new EnvironmentReferenceVerifier())->verifyEnvironmentReferenceForClient($environmentId);
        } catch (ApiException $exception) {
            $this->auditWriter->write($actorType, $actorRef, null, null, $action, 'identity_context', null, 'denied', $requestId, ['code' => 'SAND_IAM_SERVICE_ACTION_FORBIDDEN']);
            throw $exception;
        }
    }

    private function credential(string $value, string $requestId): Credential
    {
        $prefix = substr($value, 0, 16);
        $credential = Credential::where('key_prefix', $prefix)->find();
        if ($credential === null || !password_verify($value, (string) $credential->secret_hash)) {
            $this->deny('credential', $prefix === '' ? 'unknown' : $prefix, null, null, 'context.issue', $requestId, 'SAND_IAM_AUTHENTICATION_FAILED');
        }
        return $credential;
    }

    /** @param list<string> $actions @return array{0:string,1:list<array<string,mixed>>} */
    private function resolveServiceGrants(int $clientId, string $audience, array $actions, ?string $sourceIp, string $serviceCode): array
    {
        $query = Db::table('sand_iam_service_grant')->alias('service_grant')
            ->join('sand_iam_service_action action', 'action.id = service_grant.service_action_id')
            ->where('service_grant.workload_client_id', $clientId)
            ->where('service_grant.audience', $audience)
            ->where('service_grant.status', 1)
            ->whereNull('service_grant.revoked_time')
            ->where(static function ($query): void { $query->whereNull('service_grant.expire_time')->whereOr('service_grant.expire_time', '>', date('Y-m-d H:i:s')); })
            ->join('sand_iam_service service', 'service.id = action.service_id')
            ->where('service.status', 1)
            ->where('action.status', 1)
            ->whereIn('action.code', $actions);
        if ($serviceCode !== '') {
            if (!$this->validServiceCode($serviceCode)) return ['', []];
            $query->where('service.code', $serviceCode);
        }
        $rows = $query->field('service_grant.id,service_grant.network_policy,service_grant.quota_policy,service_grant.data_class,action.code,service.code AS service_code')->select()->toArray();
        $allByService = [];
        $allowedByService = [];
        foreach ($rows as $row) {
            $rowService = (string) ($row['service_code'] ?? '');
            $action = (string) ($row['code'] ?? '');
            if (!$this->validServiceCode($rowService) || $action === '' || isset($allByService[$rowService][$action])) return ['', []];
            $allByService[$rowService][$action] = $row;
            if ($sourceIp === null || $this->networkAllows($row['network_policy'] ?? null, $sourceIp)) $allowedByService[$rowService][$action] = $row;
        }
        $candidates = [];
        foreach ($allByService as $candidateService => $candidateGrants) {
            if (count($candidateGrants) === count($actions)) $candidates[$candidateService] = array_values($candidateGrants);
        }
        if ($serviceCode !== '') {
            if (!isset($candidates[$serviceCode])) return ['', []];
            if (count($allowedByService[$serviceCode] ?? []) !== count($actions)) throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
            return [$serviceCode, array_values($allowedByService[$serviceCode])];
        }
        if (count($candidates) !== 1) return ['', []];
        $resolvedService = (string) array_key_first($candidates);
        if (count($allowedByService[$resolvedService] ?? []) !== count($actions)) throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        return [$resolvedService, array_values($allowedByService[$resolvedService])];
    }

    private function networkAllows(mixed $policy, string $sourceIp): bool
    {
        if (inet_pton($sourceIp) === false) throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        try {
            return NetworkPolicy::allows($policy, $sourceIp);
        } catch (ApiException) {
            throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        }
    }

    /** @param list<array<string,mixed>> $grants @return array<string,array{grant_id:int,service_code:string,data_class:?string,quota_policy:array}> */
    private function normalizeGrantConstraints(array $grants): array
    {
        $result = [];
        foreach ($grants as $grant) {
            $action = (string) ($grant['code'] ?? '');
            if ($action === '' || isset($result[$action])) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: ambiguous service action grant', 403);
            $result[$action] = [
                'grant_id' => (int) ($grant['id'] ?? 0),
                'service_code' => (string) ($grant['service_code'] ?? ''),
                'data_class' => ServiceGrantConstraintNormalizer::dataClass($grant['data_class'] ?? null, true),
                'quota_policy' => ServiceGrantConstraintNormalizer::quota($grant['quota_policy'] ?? null, true),
            ];
        }
        return $result;
    }

    private function validServiceCode(string $value): bool { return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value) === 1; }

    /** @param mixed $payloadGrantIds @param list<array<string,mixed>> $grants */
    private function sameGrantIds(mixed $payloadGrantIds, array $grants): bool
    {
        if (!is_array($payloadGrantIds) || $payloadGrantIds === []) {
            return false;
        }
        $expected = [];
        foreach ($payloadGrantIds as $grantId) {
            if (!is_int($grantId) && !(is_string($grantId) && ctype_digit($grantId))) {
                return false;
            }
            $grantId = (int) $grantId;
            if ($grantId <= 0 || isset($expected[$grantId])) {
                return false;
            }
            $expected[$grantId] = true;
        }
        $actual = [];
        foreach ($grants as $grant) {
            $grantId = (int) ($grant['id'] ?? 0);
            if ($grantId <= 0 || isset($actual[$grantId])) {
                return false;
            }
            $actual[$grantId] = true;
        }
        $expectedIds = array_keys($expected);
        $actualIds = array_keys($actual);
        sort($expectedIds, SORT_NUMERIC);
        sort($actualIds, SORT_NUMERIC);
        return $expectedIds === $actualIds;
    }

    private function sign(array $payload): string
    {
        $encoded = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        return $encoded . '.' . $this->base64Url(hash_hmac('sha256', $encoded, $this->signingKey(), true));
    }

    /** @return array<string, mixed> */
    private function verifySignature(string $context): array
    {
        $this->assertSigningKey();
        [$encoded, $signature] = array_pad(explode('.', $context, 2), 2, '');
        $expected = $this->base64Url(hash_hmac('sha256', $encoded, $this->signingKey(), true));
        if ($encoded === '' || $signature === '' || !hash_equals($expected, $signature)) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED: invalid identity context', 401);
        }
        try {
            $payload = json_decode($this->base64UrlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED: malformed identity context', 401);
        }
        return is_array($payload) ? $payload : throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED: malformed identity context', 401);
    }

    private function assertSigningKey(): void
    {
        if ($this->signingKey() === '') {
            throw new ApiException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE: context signer is unavailable', 503);
        }
    }

    private function signingKey(): string { return (string) config('plugin.sand-iam.app.context_signing_key', ''); }
    private function expired(mixed $value): bool { return $value !== null && $value !== '' && strtotime((string) $value) <= time(); }
    private function requestId(string $requestId): string { return RequestId::normalize($requestId); }
    private function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function base64UrlDecode(string $value): string { return base64_decode(strtr($value, '-_', '+/'), true) ?: ''; }

    private function deny(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $requestId, string $code): never
    {
        $this->auditWriter->write($actorType, $actorRef, $organizationId, $applicationId, $action, 'identity_context', null, 'denied', $requestId, ['code' => $code]);
        throw new ApiException($code, str_contains($code, 'AUTHENTICATION') || str_contains($code, 'CREDENTIAL') || str_contains($code, 'CONTEXT_EXPIRED') ? 401 : 403);
    }
}

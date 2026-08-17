<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\service\AuditWriter;
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

        $reference = (new EnvironmentReferenceVerifier())->verifyEnvironmentReferenceForClient((int) $client->environment_id);
        $actions = array_values(array_unique(array_filter($requestedActions, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        $grants = $this->activeGrants((int) $client->id, $audience, $actions);
        if (count($grants) !== count($actions)) {
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
            'audience' => $audience,
            'actions' => $actions,
            'grant_ids' => array_values(array_map(static fn (array $grant): int => (int) $grant['id'], $grants)),
            'subject_scope' => $subjectScope,
            'iat' => time(),
            'exp' => $expiresAt,
        ];
        $context = $this->sign($payload);
        $expireTime = date('Y-m-d H:i:s', $expiresAt);
        $this->auditWriter->write('workload_client', (string) $client->id, $reference['organization_id'], $reference['application_id'], 'context.issue', 'identity_context', null, 'succeeded', $requestId, ['context_id' => $contextId, 'actions' => $actions, 'audience' => $audience]);
        return ['context' => $context, 'context_id' => $contextId, 'expire_time' => $expireTime];
    }

    /** @return array<string, mixed> */
    public function verify(string $context, string $expectedAudience, string $requiredAction): array
    {
        $payload = $this->verifySignature($context);
        $requestId = 'verify-' . substr((string) ($payload['context_id'] ?? 'unknown'), 0, 48) . '-' . bin2hex(random_bytes(8));
        if ((int) ($payload['exp'] ?? 0) < time()) {
            $this->deny('context', (string) ($payload['context_id'] ?? 'unknown'), null, null, 'context.verify', $requestId, 'SAND_IAM_CONTEXT_EXPIRED');
        }
        if (($payload['audience'] ?? '') !== $expectedAudience) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH');
        }
        if (!in_array($requiredAction, $payload['actions'] ?? [], true)) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $credential = Credential::where('id', (int) ($payload['credential_id'] ?? 0))->where('status', 1)->find();
        if ($credential === null || $credential->revoked_time !== null || $this->expired($credential->expire_time)) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CREDENTIAL_REVOKED');
        }
        $client = WorkloadClient::where('id', (int) ($payload['workload_client_id'] ?? 0))->where('status', 1)->find();
        if ($client === null || (int) $credential->workload_client_id !== (int) $client->id) {
            $this->deny('context', (string) $payload['context_id'], null, null, 'context.verify', $requestId, 'SAND_IAM_CREDENTIAL_REVOKED');
        }
        $reference = (new EnvironmentReferenceVerifier())->verifyEnvironmentReferenceForClient((int) $client->environment_id);
        if (
            (int) ($payload['organization_id'] ?? 0) !== $reference['organization_id']
            || (int) ($payload['application_id'] ?? 0) !== $reference['application_id']
            || (int) ($payload['environment_id'] ?? 0) !== $reference['environment_id']
        ) {
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $actions = $payload['actions'] ?? [];
        if (!is_array($actions) || count($this->activeGrants((int) $client->id, $expectedAudience, $actions)) !== count($actions)) {
            $this->deny('context', (string) $payload['context_id'], $reference['organization_id'], $reference['application_id'], 'context.verify', $requestId, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        }
        $this->auditWriter->write('context', (string) $payload['context_id'], (int) $payload['organization_id'], (int) $payload['application_id'], 'context.verify', 'identity_context', null, 'allowed', $requestId, ['required_action' => $requiredAction, 'audience' => $expectedAudience]);
        return $payload;
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

    /** @param list<string> $actions @return list<array<string, mixed>> */
    private function activeGrants(int $clientId, string $audience, array $actions): array
    {
        return Db::table('sand_iam_service_grant')->alias('service_grant')
            ->join('sand_iam_service_action action', 'action.id = service_grant.service_action_id')
            ->where('service_grant.workload_client_id', $clientId)
            ->where('service_grant.audience', $audience)
            ->where('service_grant.status', 1)
            ->whereNull('service_grant.revoked_time')
            ->where(static function ($query): void { $query->whereNull('service_grant.expire_time')->whereOr('service_grant.expire_time', '>', date('Y-m-d H:i:s')); })
            ->where('action.status', 1)
            ->whereIn('action.code', $actions)
            ->field('service_grant.id,action.code')
            ->select()
            ->toArray();
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
    private function requestId(string $requestId): string { return $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)); }
    private function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function base64UrlDecode(string $value): string { return base64_decode(strtr($value, '-_', '+/'), true) ?: ''; }

    private function deny(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $requestId, string $code): never
    {
        $this->auditWriter->write($actorType, $actorRef, $organizationId, $applicationId, $action, 'identity_context', null, 'denied', $requestId, ['code' => $code]);
        throw new ApiException($code, str_contains($code, 'AUTHENTICATION') || str_contains($code, 'CREDENTIAL') || str_contains($code, 'CONTEXT_EXPIRED') ? 401 : 403);
    }
}

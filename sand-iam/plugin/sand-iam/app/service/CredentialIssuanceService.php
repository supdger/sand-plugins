<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Credential;

/** One-time workload credential issuer shared by admin and onboarding flows. */
final class CredentialIssuanceService
{
    /** @return array{id:int,key_prefix:string,credential:string,expire_time:mixed} */
    public function issue(int $workloadClientId, string $name, mixed $expireTime, string $requestId, string $actor, ?int $organizationId = null, ?int $applicationId = null): array
    {
        $plain = 'siam_' . bin2hex(random_bytes(24));
        $credential = Credential::create(['workload_client_id' => $workloadClientId, 'name' => $name, 'key_prefix' => substr($plain, 0, 16), 'secret_hash' => password_hash($plain, PASSWORD_DEFAULT), 'expire_time' => $expireTime ?: null, 'status' => 1]);
        (new AuditWriter())->write('admin', $actor, $organizationId, $applicationId, 'credential.issue', 'credential', (int) $credential->id, 'succeeded', $requestId, ['workload_client_id' => $workloadClientId, 'key_prefix' => (string) $credential->key_prefix]);
        return ['id' => (int) $credential->id, 'key_prefix' => (string) $credential->key_prefix, 'credential' => $plain, 'expire_time' => $credential->expire_time];
    }
}
